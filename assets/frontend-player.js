(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    }

    ready(function () {
        var config = window.smpPodcastPlayerConfig || {};
        var player = document.querySelector('[data-smp-player]');
        if (!config.enabled || !player || player.dataset.smpInitialized === '1') return;
        player.dataset.smpInitialized = '1';

        var audio = player.querySelector('[data-smp-audio]');
        var toggle = player.querySelector('[data-smp-toggle]');
        var playIcon = player.querySelector('[data-smp-play-icon]');
        var pauseIcon = player.querySelector('[data-smp-pause-icon]');
        var seek = player.querySelector('[data-smp-seek]');
        var elapsed = player.querySelector('[data-smp-elapsed]');
        var duration = player.querySelector('[data-smp-duration]');
        var title = player.querySelector('[data-smp-title]');
        var cover = player.querySelector('[data-smp-cover]');
        var download = player.querySelector('[data-smp-download]');
        var rate = player.querySelector('[data-smp-rate]');
        var volume = player.querySelector('[data-smp-volume]');
        var mute = player.querySelector('[data-smp-mute]');
        var status = player.querySelector('[data-smp-status]');
        var state = {
            track: null,
            trigger: null,
            controller: null,
            navigationId: 0,
            navigationSession: false,
            historyGuardInstalled: false,
            originalScrollRestoration: null,
            playbackActivated: false,
            seeking: false,
            scrollTimer: 0,
            legacyObserver: null,
            legacySources: new WeakMap()
        };
        var strings = config.strings || {};
        var triggerSelector = '[data-smp-player-trigger],.ep-listen[data-mp3],#ap-toggle';
        var runtimeClasses = ['smp-podcast-player-visible', 'smp-podcast-ajax-loading'];
        var ajaxSupported = typeof window.fetch === 'function' && typeof window.DOMParser === 'function' && typeof window.AbortController === 'function';

        document.documentElement.style.setProperty('--smp-transition-duration', number(config.transitionMs, 180) + 'ms');
        restorePreferences();
        enforcePlayerSingleton(document);
        observeLegacyPlayers();
        bindPlayer();
        configureMediaSession();
        updatePlayerState();

        function bindPlayer() {
            document.addEventListener('click', handleDocumentClick, true);

            toggle.addEventListener('click', function () {
                if (!state.track) return;
                if (audio.paused) playAudio();
                else audio.pause();
            });

            var back = player.querySelector('[data-smp-back]');
            var forward = player.querySelector('[data-smp-forward]');
            var close = player.querySelector('[data-smp-close]');
            if (back) back.addEventListener('click', function () { skipBy(-number(config.skipBack, 15)); });
            if (forward) forward.addEventListener('click', function () { skipBy(number(config.skipForward, 30)); });
            if (close) close.addEventListener('click', closePlayer);

            seek.addEventListener('input', function () {
                state.seeking = true;
                elapsed.textContent = formatTime(number(seek.value, 0));
            });
            seek.addEventListener('change', function () {
                if (Number.isFinite(audio.duration)) audio.currentTime = Math.min(number(seek.value, 0), audio.duration);
                state.seeking = false;
                updateTimeline();
            });

            if (rate) {
                rate.addEventListener('change', function () {
                    audio.playbackRate = number(rate.value, 1);
                    savePreferences();
                    updateMediaPosition();
                });
            }
            if (volume) {
                volume.addEventListener('input', function () {
                    audio.volume = Math.max(0, Math.min(1, number(volume.value, 1)));
                    if (audio.volume > 0) audio.muted = false;
                    savePreferences();
                });
            }
            if (mute) {
                mute.addEventListener('click', function () {
                    audio.muted = !audio.muted;
                    updateVolume();
                    savePreferences();
                });
            }

            ['play', 'pause', 'ended', 'waiting', 'canplay', 'volumechange', 'ratechange'].forEach(function (eventName) {
                audio.addEventListener(eventName, updatePlayerState);
            });
            audio.addEventListener('play', function () { state.playbackActivated = true; });
            audio.addEventListener('pause', function () {
                cancelPendingNavigation('playback-paused');
                if (!navigationActive()) parkNavigationSession();
            });
            audio.addEventListener('ended', function () {
                state.playbackActivated = false;
                cancelPendingNavigation('playback-ended');
                parkNavigationSession();
            });
            ['loadedmetadata', 'durationchange', 'timeupdate', 'progress'].forEach(function (eventName) {
                audio.addEventListener(eventName, updateTimeline);
            });
            audio.addEventListener('error', function () {
                state.playbackActivated = false;
                cancelPendingNavigation('playback-error');
                parkNavigationSession();
                announce('This episode could not be played.');
                updatePlayerState();
            });
        }

        function handleDocumentClick(event) {
            var target = event.target instanceof Element ? event.target : null;
            if (!target) return;

            var trigger = target.closest(triggerSelector);
            if (trigger && !trigger.closest('[data-smp-player]')) {
                var track = trackFromTrigger(trigger);
                if (!track.src) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                activateTrack(track, trigger);
                return;
            }

            var anchor = target.closest('a[href]');
            if (!anchor || !shouldIntercept(event, anchor)) return;
            event.preventDefault();
            event.stopPropagation();
            navigate(new URL(anchor.href, window.location.href), { mode: 'push', fallback: 'assign' });
        }

        function trackFromTrigger(trigger) {
            var container = trigger.closest('[data-smp-episode],article,.e-loop-item,.elementor-widget-container') || trigger.parentElement;
            var titleNode = container ? container.querySelector('[data-smp-episode-title],.ep-title,.entry-title,h1,h2,h3') : null;
            var linkNode = container ? container.querySelector('a[href]:not(.ep-listen)') : null;
            var imageNode = container ? container.querySelector('img') : null;
            var source = trigger.getAttribute('data-smp-audio-src')
                || trigger.getAttribute('data-mp3');

            return {
                src: mediaUrl(source),
                download: mediaUrl(trigger.getAttribute('data-smp-download-src') || source),
                title: cleanText(trigger.getAttribute('data-smp-title') || (titleNode ? titleNode.textContent : '') || document.title),
                url: pageUrl(trigger.getAttribute('data-smp-url') || (linkNode ? linkNode.href : window.location.href)),
                image: mediaUrl(trigger.getAttribute('data-smp-image') || (imageNode ? imageNode.currentSrc || imageNode.src : '')),
                postId: trigger.getAttribute('data-smp-post-id') || '',
                duration: trigger.getAttribute('data-smp-duration') || '',
                durationSeconds: number(trigger.getAttribute('data-smp-duration-seconds'), 0)
            };
        }

        function activateTrack(track, trigger) {
            state.trigger = trigger;
            if (state.track && comparableUrl(state.track.src) === comparableUrl(track.src)) {
                if (audio.paused) playAudio();
                else audio.pause();
                return;
            }

            audio.pause();
            state.track = track;
            state.playbackActivated = false;
            audio.src = track.src;
            audio.load();
            renderTrack();
            showPlayer();
            announce((strings.loading || 'Loading episode') + ': ' + track.title);
            document.dispatchEvent(new CustomEvent('smp:podcast-track-selected', { detail: { track: track, trigger: trigger } }));
            playAudio();
        }

        function playAudio() {
            var promise = audio.play();
            if (promise && typeof promise.catch === 'function') {
                promise.catch(function () {
                    announce('Press play to start this episode.');
                    updatePlayerState();
                });
            }
        }

        function showPlayer() {
            player.hidden = false;
            document.body.classList.add('smp-podcast-player-visible');
        }

        function closePlayer() {
            var focusTarget = state.trigger;
            audio.pause();
            cancelPendingNavigation('player-closed');
            parkNavigationSession();
            audio.removeAttribute('src');
            audio.load();
            state.track = null;
            state.playbackActivated = false;
            player.hidden = true;
            document.body.classList.remove('smp-podcast-player-visible');
            clearMediaSession();
            updatePlayerState();
            announce(strings.playerClosed || 'Player closed');
            document.dispatchEvent(new CustomEvent('smp:podcast-player-closed'));
            if (focusTarget && document.contains(focusTarget)) focusTarget.focus({ preventScroll: true });
        }

        function renderTrack() {
            if (!state.track) return;
            title.textContent = state.track.title || 'Podcast episode';
            if (state.track.url) title.href = state.track.url;
            else title.removeAttribute('href');

            if (cover && config.showCover && state.track.image) {
                cover.src = state.track.image;
                cover.hidden = false;
            } else if (cover) {
                cover.removeAttribute('src');
                cover.hidden = true;
            }

            if (download && state.track.download) download.href = state.track.download;
            if (state.track.durationSeconds > 0) {
                seek.max = String(state.track.durationSeconds);
                duration.textContent = formatTime(state.track.durationSeconds);
            }
            setMediaMetadata();
        }

        function updatePlayerState() {
            var playing = !!state.track && !audio.paused && !audio.ended;
            toggle.setAttribute('aria-pressed', playing ? 'true' : 'false');
            toggle.setAttribute('aria-label', playing ? (strings.pause || 'Pause episode') : (strings.play || 'Play episode'));
            playIcon.hidden = playing;
            pauseIcon.hidden = !playing;
            updateVolume();
            updateTriggers(playing);

            if (audio.readyState < 3 && playing) announce(strings.loading || 'Loading episode');
            else if (playing && state.track) announce('Playing ' + state.track.title);
            else if (state.track && !audio.ended) announce('Paused ' + state.track.title);
            else if (state.track && audio.ended) announce('Finished ' + state.track.title);
            updateMediaPosition();
        }

        function updateTriggers(playing) {
            document.querySelectorAll(triggerSelector).forEach(function (trigger) {
                var candidate = trackFromTrigger(trigger);
                var current = !!state.track && comparableUrl(candidate.src) === comparableUrl(state.track.src);
                trigger.setAttribute('aria-controls', 'smp-podcast-player');
                trigger.setAttribute('aria-pressed', current && playing ? 'true' : 'false');
                trigger.classList.toggle('is-smp-playing', current && playing);
                trigger.classList.toggle('is-smp-current', current);
            });
        }

        function updateTimeline() {
            var total = Number.isFinite(audio.duration) && audio.duration > 0
                ? audio.duration
                : (state.track ? number(state.track.durationSeconds, 0) : 0);
            if (total > 0) {
                seek.max = String(total);
                duration.textContent = formatTime(total);
            }
            if (!state.seeking) {
                seek.value = String(Number.isFinite(audio.currentTime) ? audio.currentTime : 0);
                elapsed.textContent = formatTime(audio.currentTime);
            }
            seek.setAttribute('aria-valuetext', formatTime(audio.currentTime) + ' of ' + formatTime(total));
            updateMediaPosition();
        }

        function updateVolume() {
            if (volume) volume.value = String(audio.muted ? 0 : audio.volume);
            if (mute) {
                mute.setAttribute('aria-pressed', audio.muted ? 'true' : 'false');
                mute.setAttribute('aria-label', audio.muted ? 'Unmute audio' : 'Mute audio');
            }
            if (rate) rate.value = String(audio.playbackRate);
        }

        function skipBy(seconds) {
            if (!state.track) return;
            var maximum = Number.isFinite(audio.duration) ? audio.duration : Number.MAX_SAFE_INTEGER;
            audio.currentTime = Math.max(0, Math.min(maximum, audio.currentTime + seconds));
            updateTimeline();
        }

        function configureMediaSession() {
            if (!config.mediaSession || !('mediaSession' in navigator)) return;
            var handlers = {
                play: function () { playAudio(); },
                pause: function () { audio.pause(); },
                seekbackward: function (details) { skipBy(-(details.seekOffset || number(config.skipBack, 15))); },
                seekforward: function (details) { skipBy(details.seekOffset || number(config.skipForward, 30)); },
                seekto: function (details) {
                    if (details.fastSeek && typeof audio.fastSeek === 'function') audio.fastSeek(details.seekTime);
                    else audio.currentTime = details.seekTime;
                },
                stop: closePlayer
            };
            Object.keys(handlers).forEach(function (action) {
                try { navigator.mediaSession.setActionHandler(action, handlers[action]); } catch (error) { /* Unsupported action. */ }
            });
        }

        function setMediaMetadata() {
            if (!config.mediaSession || !state.track || !('mediaSession' in navigator) || !('MediaMetadata' in window)) return;
            var metadata = { title: state.track.title || 'Podcast episode', artist: config.siteName || 'Scale My Podcast' };
            if (state.track.image) metadata.artwork = [{ src: state.track.image }];
            try { navigator.mediaSession.metadata = new MediaMetadata(metadata); } catch (error) { /* Browser metadata failure is non-fatal. */ }
        }

        function updateMediaPosition() {
            if (!config.mediaSession || !('mediaSession' in navigator) || typeof navigator.mediaSession.setPositionState !== 'function') return;
            if (!Number.isFinite(audio.duration) || audio.duration <= 0) return;
            try {
                navigator.mediaSession.setPositionState({
                    duration: audio.duration,
                    playbackRate: audio.playbackRate,
                    position: Math.min(audio.currentTime, audio.duration)
                });
            } catch (error) { /* Invalid transient media state is harmless. */ }
        }

        function clearMediaSession() {
            if (!('mediaSession' in navigator)) return;
            try { navigator.mediaSession.metadata = null; } catch (error) { /* Ignore. */ }
        }

        function restorePreferences() {
            if (!config.rememberPreferences) return;
            try {
                var savedRate = number(localStorage.getItem('smpPodcastPlaybackRate'), 1);
                var savedVolume = number(localStorage.getItem('smpPodcastVolume'), 1);
                audio.playbackRate = Math.max(0.5, Math.min(3, savedRate));
                audio.volume = Math.max(0, Math.min(1, savedVolume));
            } catch (error) { /* Storage can be unavailable in private contexts. */ }
        }

        function savePreferences() {
            if (!config.rememberPreferences) return;
            try {
                localStorage.setItem('smpPodcastPlaybackRate', String(audio.playbackRate));
                localStorage.setItem('smpPodcastVolume', String(audio.volume));
            } catch (error) { /* Storage can be unavailable in private contexts. */ }
        }

        function beginNavigationSession() {
            if (state.navigationSession || !config.ajaxNavigation) return;
            state.navigationSession = true;
            if (!state.historyGuardInstalled) {
                window.addEventListener('popstate', handleHistoryPop);
                state.historyGuardInstalled = true;
            }
            window.addEventListener('scroll', handleSessionScroll, { passive: true });
            if ('scrollRestoration' in history) {
                state.originalScrollRestoration = history.scrollRestoration;
                history.scrollRestoration = 'manual';
            }
            storeCurrentScroll();
        }

        function parkNavigationSession() {
            if (!state.navigationSession) return;
            state.navigationSession = false;
            window.removeEventListener('scroll', handleSessionScroll);
            window.clearTimeout(state.scrollTimer);
            state.scrollTimer = 0;
            if ('scrollRestoration' in history && state.originalScrollRestoration !== null) {
                history.scrollRestoration = state.originalScrollRestoration;
            }
            state.originalScrollRestoration = null;
        }

        function cancelPendingNavigation(reason) {
            var hadPendingNavigation = !!state.controller;
            state.navigationId += 1;
            if (state.controller) {
                state.controller.abort();
                state.controller = null;
            }
            setLoading(false);
            if (hadPendingNavigation) {
                document.dispatchEvent(new CustomEvent('smp:navigation-cancelled', { detail: { reason: reason || 'cancelled' } }));
            }
        }

        function handleHistoryPop(event) {
            if (!state.historyGuardInstalled) return;
            var marked = !!(event.state && event.state.smpPodcastAjax);
            if (!state.navigationSession || !navigationActive() || !marked) {
                hardFallback(new URL(window.location.href), 'reload', marked ? 'playback-inactive' : 'foreign-history-state');
                return;
            }
            navigate(new URL(window.location.href), { mode: 'pop', fallback: 'reload', historyState: event.state });
        }

        function handleSessionScroll() {
            if (!state.navigationSession) return;
            window.clearTimeout(state.scrollTimer);
            state.scrollTimer = window.setTimeout(recordHistoryState, 120);
        }

        function navigationActive() {
            if (!ajaxSupported || !config.ajaxNavigation || !state.track || !state.playbackActivated || !audio.currentSrc || audio.ended) return false;
            return !audio.paused;
        }

        function pendingNavigationActive(navigationId) {
            if (navigationId !== state.navigationId) return false;
            if (navigationActive()) return true;
            cancelPendingNavigation('playback-inactive');
            parkNavigationSession();
            return false;
        }

        function shouldIntercept(event, anchor) {
            if (!navigationActive() || event.defaultPrevented || event.button !== 0) return false;
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
            if (anchor.hasAttribute('download') || anchor.hasAttribute('data-smp-hard-navigation') || anchor.getAttribute('data-smp-ajax') === 'off') return false;
            if (anchor.closest('#wpadminbar,[data-smp-player],[contenteditable="true"],.no-ajax')) return false;

            var target = (anchor.getAttribute('target') || '').toLowerCase();
            if (target && target !== '_self') return false;
            var rel = (anchor.getAttribute('rel') || '').toLowerCase().split(/\s+/);
            if (rel.indexOf('external') !== -1) return false;

            var raw = (anchor.getAttribute('href') || '').trim();
            if (!raw || raw.charAt(0) === '#' || /^(mailto|tel|sms|javascript|data|blob):/i.test(raw)) return false;

            var url;
            try { url = new URL(anchor.href, window.location.href); } catch (error) { return false; }
            if (!/^https?:$/.test(url.protocol) || url.origin !== window.location.origin) return false;
            if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return false;
            return !excludedUrl(url) && !!findContentRoot(document);
        }

        function excludedUrl(url) {
            var path = url.pathname || '/';
            var candidate = path + url.search;
            if (/^\/(?:wp-admin(?:\/|$)|wp-login\.php(?:\/|$)|wp-json(?:\/|$)|xmlrpc\.php(?:\/|$)|wp-cron\.php(?:\/|$))/i.test(path)) return true;
            if (/(?:^|\/)feed(?:\/|$)/i.test(path)) return true;
            if (/(?:^|\/)(?:cart|checkout|my-account)(?:\/|$)/i.test(path)) return true;
            if (/\.(?:mp3|m4a|aac|wav|ogg|oga|flac|mp4|m4v|mov|avi|webm|pdf|zip|gz|rar|7z|docx?|xlsx?|pptx?|jpe?g|png|gif|webp|svg)(?:$|\/)/i.test(path)) return true;
            if (url.searchParams.has('add-to-cart') || url.searchParams.has('wc-ajax') || url.searchParams.has('download_file')) return true;

            return (Array.isArray(config.excludedPaths) ? config.excludedPaths : []).some(function (pattern) {
                pattern = String(pattern || '').trim();
                if (!pattern) return false;
                if (pattern.indexOf('*') === -1) return candidate.indexOf(pattern) === 0;
                var escaped = pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
                try { return new RegExp('^' + escaped, 'i').test(candidate); } catch (error) { return false; }
            });
        }

        function navigate(url, options) {
            options = options || {};
            if (!navigationActive()) {
                hardFallback(url, options.fallback, 'playback-inactive');
                return;
            }

            var currentDescriptor = findContentRoot(document);
            if (!currentDescriptor) {
                hardFallback(url, options.fallback, 'content-root-missing');
                return;
            }

            var before = new CustomEvent('smp:before-navigate', { cancelable: true, detail: { url: url.href, mode: options.mode || 'push' } });
            if (!document.dispatchEvent(before)) {
                hardFallback(url, options.fallback, 'navigation-cancelled');
                return;
            }

            if (state.controller) cancelPendingNavigation('superseded');
            var controller = new AbortController();
            var navigationId = ++state.navigationId;
            var timedOut = false;
            var stagedStyleNodes = [];
            var stylesCommitted = false;
            state.controller = controller;
            setLoading(true);
            announce(strings.navigationLoading || 'Loading page');

            var timer = window.setTimeout(function () {
                timedOut = true;
                controller.abort();
            }, number(config.timeoutMs, 10000));

            fetch(url.href, {
                method: 'GET',
                credentials: 'same-origin',
                redirect: 'follow',
                signal: controller.signal,
                headers: { 'Accept': 'text/html,application/xhtml+xml' }
            }).then(function (response) {
                var type = response.headers.get('content-type') || '';
                if (response.status !== 200 || type.toLowerCase().indexOf('text/html') === -1) throw new Error('Unexpected response');
                if (new URL(response.url).origin !== window.location.origin) throw new Error('Cross-origin redirect');
                return response.text().then(function (html) { return { html: html, responseUrl: response.url }; });
            }).then(function (result) {
                if (!pendingNavigationActive(navigationId)) return;
                var finalUrl = new URL(result.responseUrl || url.href, window.location.href);
                if (url.hash) finalUrl.hash = url.hash;
                var parsed = new DOMParser().parseFromString(result.html, 'text/html');
                if (!parsed.documentElement || !parsed.head || !parsed.body) throw unsupportedNavigation('invalid-document');
                configureParsedBase(parsed, finalUrl);
                var roots = findMatchingContentRoots(parsed, currentDescriptor);
                if (!roots) throw unsupportedNavigation('content-root-mismatch');
                var plan = inspectNavigationDocument(parsed, roots.next);

                return loadRequiredStyles(plan.styles.missing, controller.signal, stagedStyleNodes)
                    .then(function () { return loadRequiredScripts(plan.scripts, controller.signal); })
                    .then(function () {
                    if (!pendingNavigationActive(navigationId)) return;
                    var importedRoot = document.importNode(roots.next, true);
                    sanitizeImportedContent(importedRoot);
                    enforcePlayerSingleton(importedRoot);
                    if (!pendingNavigationActive(navigationId)) return;
                    beginNavigationSession();
                    if (!pendingNavigationActive(navigationId)) {
                        parkNavigationSession();
                        return;
                    }
                    storeCurrentScroll();
                    syncInlineStyles(plan.styles.inline);
                    syncHead(parsed);
                    syncBody(parsed);
                    applyLocalizedConfigs(plan.configs);
                    syncPersistentNavigation(parsed);
                    roots.current.replaceWith(importedRoot);
                    syncCompanionFragments(plan.companions);
                    syncStylesheetAssets(plan.styles.urls);
                    stylesCommitted = true;
                    updateHistory(finalUrl, options);
                    reinitializeContent(importedRoot, finalUrl, options, plan);
                });
            }).catch(function (error) {
                if (navigationId !== state.navigationId) return;
                if (error && error.name === 'AbortError' && !timedOut) return;
                announce(strings.navigationFailed || 'Opening page normally');
                hardFallback(url, options.fallback, navigationErrorReason(error, timedOut));
            }).finally(function () {
                window.clearTimeout(timer);
                if (!stylesCommitted) removeStagedStyles(stagedStyleNodes);
                if (navigationId === state.navigationId) {
                    state.controller = null;
                    setLoading(false);
                }
            });
        }

        function findContentRoot(doc) {
            var selectors = contentRootSelectors();
            for (var index = 0; index < selectors.length; index += 1) {
                try {
                    var matches = doc.querySelectorAll(selectors[index]);
                    if (matches.length === 1 && validContentRoot(matches[0], doc)) {
                        return { node: matches[0], selector: selectors[index] };
                    }
                } catch (error) { /* Invalid selectors are never eligible roots. */ }
            }
            return null;
        }

        function findMatchingContentRoots(parsed, currentDescriptor) {
            if (!currentDescriptor || !safeContentRootSelector(currentDescriptor.selector)) return null;
            try {
                var incomingDescriptor = findContentRoot(parsed);
                if (!incomingDescriptor
                    || !safeContentRootSelector(incomingDescriptor.selector)
                    || !validContentRoot(currentDescriptor.node, document)
                    || !validContentRoot(incomingDescriptor.node, parsed)
                ) return null;

                var currentMarker = currentDescriptor.node.getAttribute('data-smp-ajax-root');
                var incomingMarker = incomingDescriptor.node.getAttribute('data-smp-ajax-root');
                if (currentMarker !== null || incomingMarker !== null) {
                    if (currentMarker === null || incomingMarker === null || currentMarker !== incomingMarker) return null;
                } else if (currentDescriptor.selector !== incomingDescriptor.selector
                    && (!trustedElementorSurfaceSelector(currentDescriptor.selector)
                        || !trustedElementorSurfaceSelector(incomingDescriptor.selector))
                ) return null;

                return {
                    current: currentDescriptor.node,
                    next: incomingDescriptor.node,
                    selector: incomingDescriptor.selector
                };
            } catch (error) {
                return null;
            }
        }

        function trustedElementorSurfaceSelector(selector) {
            return [
                '[data-elementor-type="wp-page"]',
                '[data-elementor-type="wp-post"]',
                '[data-elementor-type="single-post"]',
                '[data-elementor-type="single"]',
                '[data-elementor-type="archive"]',
                '.elementor-location-single',
                '.elementor-location-archive'
            ].indexOf(String(selector || '').trim()) !== -1;
        }

        function contentRootSelectors() {
            var selectors = [];
            if (config.contentSelector) selectors.push(String(config.contentSelector));
            selectors.push('[data-smp-ajax-root]');
            (Array.isArray(config.rootFallbacks) ? config.rootFallbacks : []).forEach(function (selector) { selectors.push(String(selector || '')); });
            return selectors.filter(function (selector, index) {
                return safeContentRootSelector(selector) && selectors.indexOf(selector) === index;
            });
        }

        function safeContentRootSelector(selector) {
            selector = String(selector || '').trim();
            if (!selector || selector.length > 240 || selector.indexOf(',') !== -1 || /[{}<>\x00-\x1F]/.test(selector)) return false;
            if (/^(?:\*|:root|html|body|header|footer|nav|main|#page|\.site|#wrapper|\.wrapper)$/i.test(selector)) return false;
            return !/(^|[\s>+~])(?:html|body|header|footer|nav)(?=$|[\s.#:\[>+~])/i.test(selector)
                && !/(^|[\s>+~])(?:#page|\.site)(?=$|[\s.#:\[>+~])/i.test(selector);
        }

        function validContentRoot(root, doc) {
            if (!root || root === doc.documentElement || root === doc.body) return false;
            if (root.matches('header,footer,nav,[data-elementor-type="header"],[data-elementor-type="footer"],.elementor-location-header,.elementor-location-footer')) return false;
            if (root.closest('header,footer,nav,[data-elementor-type="header"],[data-elementor-type="footer"],.elementor-location-header,.elementor-location-footer')) return false;
            if (root.matches('[data-smp-ajax-disabled]') || root.closest('[data-smp-ajax-disabled]')) return false;
            if (root.contains(player) || root.querySelector('[data-smp-player]')) return false;
            return true;
        }

        function inspectNavigationDocument(parsed, nextRoot) {
            if (parsed.body.matches('[data-smp-ajax-disabled],.elementor-editor-active')) throw unsupportedNavigation('page-opted-out');
            rejectInlineEventHandlers(parsed);
            validateStyleElements(parsed);

            var plan = {
                configs: [],
                elementor: rootUsesElementor(nextRoot),
                companions: inspectCompanionFragments(parsed, nextRoot),
                styles: inspectRequiredStyles(parsed),
                scripts: []
            };
            inspectRequiredScripts(parsed, nextRoot, plan);

            if (plan.elementor && !elementorLifecycleReady()) throw unsupportedNavigation('elementor-not-ready');
            return plan;
        }

        function inspectCompanionFragments(parsed, nextRoot) {
            var fragments = new Map();
            parsed.querySelectorAll('[data-smp-ajax-companion]').forEach(function (source) {
                var key = (source.getAttribute('data-smp-ajax-companion') || '').trim();
                var policy = companionFragmentPolicy(key);
                if (!policy
                    || source.tagName !== 'TEMPLATE'
                    || nextRoot.contains(source)
                    || fragments.has(key)
                ) throw unsupportedNavigation('unsafe-companion-fragment');
                validateCompanionTemplate(source, policy);
                fragments.set(key, source);
            });
            return fragments;
        }

        function companionFragmentPolicy(key) {
            if (key !== 'smpi-breadcrumbs') return null;
            return {
                renderedSelector: '[data-smp-ajax-companion-rendered="smpi-breadcrumbs"],[data-smpi-breadcrumbs-injected]'
            };
        }

        function validateCompanionTemplate(source) {
            var allowedTags = ['A', 'DIV', 'EM', 'LI', 'NAV', 'OL', 'P', 'SMALL', 'SPAN', 'STRONG', 'UL'];
            Array.prototype.forEach.call(source.content.querySelectorAll('*'), function (element) {
                if (allowedTags.indexOf(element.tagName) === -1) throw unsupportedNavigation('unsafe-companion-fragment');
                Array.prototype.forEach.call(element.attributes || [], function (attribute) {
                    var name = attribute.name.toLowerCase();
                    var allowed = name === 'class'
                        || name === 'role'
                        || name === 'title'
                        || name.indexOf('aria-') === 0
                        || name.indexOf('data-smpi-') === 0;
                    if (name === 'href' && element.tagName === 'A') allowed = safeCompanionLink(attribute.value, source.ownerDocument);
                    if (!allowed) throw unsupportedNavigation('unsafe-companion-fragment');
                });
            });
        }

        function safeCompanionLink(value, ownerDocument) {
            try {
                var url = new URL(String(value || ''), ownerDocument.baseURI || window.location.href);
                return /^https?:$/.test(url.protocol) && url.origin === window.location.origin;
            } catch (error) {
                return false;
            }
        }

        function configureParsedBase(parsed, finalUrl) {
            var declared = parsed.head.querySelector('base[href]');
            if (declared) {
                try {
                    var resolved = new URL(declared.getAttribute('href'), finalUrl.href);
                    if (!/^https?:$/.test(resolved.protocol) || resolved.origin !== finalUrl.origin) throw unsupportedNavigation('unsupported-base-url');
                    declared.href = resolved.href;
                    return;
                } catch (error) {
                    if (error && error.smpNavigationReason) throw error;
                    throw unsupportedNavigation('unsupported-base-url');
                }
            }
            var resolutionBase = parsed.createElement('base');
            resolutionBase.setAttribute('href', finalUrl.href);
            resolutionBase.setAttribute('data-smp-resolution-base', '1');
            parsed.head.prepend(resolutionBase);
        }

        function inspectRequiredStyles(parsed) {
            var existing = new Set(Array.prototype.map.call(document.querySelectorAll('link[rel~="stylesheet"][href]'), function (node) { return absoluteAsset(node.href); }));
            var required = [];
            var targetUrls = new Set();
            parsed.querySelectorAll('link[rel~="stylesheet"][href]').forEach(function (source) {
                var href = absoluteAsset(source.href);
                targetUrls.add(href);
                if (existing.has(href)) return;
                if (!safeStyleAsset(href)) throw unsupportedNavigation('unsupported-stylesheet');
                existing.add(href);
                required.push(source);
            });

            var currentUnknownStyles = new Map();
            var currentManagedIds = new Set();
            document.head.querySelectorAll('style').forEach(function (source) {
                var id = (source.id || '').trim();
                if (managedInlineStyleId(id)) {
                    if (currentManagedIds.has(id)) throw unsupportedNavigation('duplicate-managed-style');
                    currentManagedIds.add(id);
                    return;
                }
                incrementSignature(currentUnknownStyles, inlineStyleSignature(source));
            });

            var targetManagedIds = new Set();
            var targetManagedStyles = [];
            parsed.head.querySelectorAll('style').forEach(function (source) {
                var id = (source.id || '').trim();
                if (managedInlineStyleId(id)) {
                    if (targetManagedIds.has(id)) throw unsupportedNavigation('duplicate-managed-style');
                    targetManagedIds.add(id);
                    targetManagedStyles.push(source);
                    return;
                }
                var signature = inlineStyleSignature(source);
                var available = currentUnknownStyles.get(signature) || 0;
                if (available < 1) throw unsupportedNavigation('unknown-inline-style');
                currentUnknownStyles.set(signature, available - 1);
            });

            var existingHints = new Set(Array.prototype.map.call(document.querySelectorAll('link[rel="modulepreload"][href],link[rel="preload"][href]'), function (node) { return absoluteAsset(node.href); }));
            parsed.querySelectorAll('link[rel="modulepreload"][href],link[rel="preload"][href]').forEach(function (source) {
                var as = (source.getAttribute('as') || '').toLowerCase();
                if (as && as !== 'script' && as !== 'style') return;
                var href = absoluteAsset(source.href);
                if (href && !existingHints.has(href) && !existing.has(href)) throw unsupportedNavigation('unsupported-preload');
            });
            return {
                missing: required,
                urls: targetUrls,
                inline: { managed: targetManagedStyles, ids: targetManagedIds }
            };
        }

        function inspectRequiredScripts(parsed, nextRoot, plan) {
            var existingExternal = new Set(Array.prototype.map.call(document.querySelectorAll('script[src]'), function (node) { return absoluteAsset(node.src); }));
            var missingExternal = new Set();
            var currentInlineSignatures = new Set();
            var wordfenceInitialized = false;
            document.querySelectorAll('script:not([src])').forEach(function (node) {
                var text = normalizedAssetText(node.textContent);
                if (!text) return;
                var signature = scriptType(node) + '\n' + text;
                currentInlineSignatures.add(signature);
                if (alreadyInitializedInlineScript(node, text)) wordfenceInitialized = true;
            });

            parsed.querySelectorAll('script').forEach(function (source) {
                var executable = executableScript(source);
                var text = normalizedAssetText(source.textContent);
                var signature = scriptType(source) + '\n' + text;
                if (!executable) {
                    if (nextRoot.contains(source)) return;
                    if (parsed.head.contains(source) && scriptType(source) === 'application/ld+json') {
                        try { JSON.parse(text); } catch (error) { throw unsupportedNavigation('invalid-json-ld'); }
                        return;
                    }
                    if (text && currentInlineSignatures.has(signature)) return;
                    if (text || source.src) throw unsupportedNavigation('unsupported-data-script');
                    return;
                }
                if (nextRoot.contains(source)) throw unsupportedNavigation('executable-script-in-content');

                if (source.src) {
                    var sourceUrl = absoluteAsset(source.src);
                    if (existingExternal.has(sourceUrl)) return;
                    if (!safeDynamicScript(source) || missingExternal.has(sourceUrl)) {
                        if (!missingExternal.has(sourceUrl)) throw unsupportedNavigation('missing-script-asset');
                        return;
                    }
                    missingExternal.add(sourceUrl);
                    plan.scripts.push(source);
                    return;
                }

                if (!text) return;
                if (currentInlineSignatures.has(signature)) return;
                if (wordfenceInitialized && alreadyInitializedInlineScript(source, text)) return;
                var localized = parseSupportedLocalizedConfig(source, text);
                if (!localized) throw unsupportedNavigation('unsupported-inline-script');
                plan.configs.push(localized);
            });
        }

        function executableScript(script) {
            var type = scriptType(script);
            if (!type || type === 'module' || type === 'importmap' || type === 'speculationrules') return true;
            return !(/^(?:application|text)\/[a-z0-9.+-]*json$/.test(type)
                || /^(?:text\/(?:plain|html|template|x-template)|application\/x-template)$/.test(type));
        }

        function scriptType(script) {
            return (script.getAttribute('type') || '').trim().toLowerCase().split(';')[0];
        }

        function parseSupportedLocalizedConfig(script, text) {
            var definitions = {
                elementorFrontendConfig: /^elementor-frontend-js-(?:before|after)$/,
                ElementorProFrontendConfig: /^elementor-pro-frontend-js-(?:before|after)$/,
                elementorProFrontendConfig: /^elementor-pro-frontend-js-(?:before|after)$/
            };
            var id = script.id || '';
            var cleaned = text
                .replace(/^\/\*\s*<!\[CDATA\[\s*\*\//, '')
                .replace(/\/\*\s*\]\]>\s*\*\/$/, '')
                .trim();

            var names = Object.keys(definitions);
            for (var index = 0; index < names.length; index += 1) {
                var name = names[index];
                if (!definitions[name].test(id)) continue;
                var escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                var assignment = new RegExp('^(?:var\\s+|window\\.)?' + escapedName + '\\s*=\\s*([\\s\\S]+);$').exec(cleaned);
                if (!assignment) return null;
                try {
                    var value = JSON.parse(assignment[1]);
                    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
                    return { name: name, value: value };
                } catch (error) {
                    return null;
                }
            }
            return null;
        }

        function loadRequiredStyles(sources, signal, stagedNodes) {
            return Promise.all(sources.map(function (source) {
                return new Promise(function (resolve, reject) {
                    var settled = false;
                    var link = copyStylesheetLink(source);
                    var finish = function (error, aborted) {
                        if (settled) return;
                        settled = true;
                        window.clearTimeout(timer);
                        if (signal) signal.removeEventListener('abort', handleAbort);
                        if (aborted) {
                            link.remove();
                            reject(abortError());
                        } else if (error) reject(unsupportedNavigation('stylesheet-load-failed'));
                        else resolve();
                    };
                    link.addEventListener('load', function () { finish(false); }, { once: true });
                    link.addEventListener('error', function () { finish(true); }, { once: true });
                    var timer = window.setTimeout(function () { finish(true); }, Math.min(number(config.timeoutMs, 10000), 6000));
                    var handleAbort = function () { finish(false, true); };
                    if (signal && signal.aborted) {
                        finish(false, true);
                        return;
                    }
                    if (signal) signal.addEventListener('abort', handleAbort, { once: true });
                    stagedNodes.push(link);
                    document.head.appendChild(link);
                });
            }));
        }

        function loadRequiredScripts(sources, signal) {
            return sources.reduce(function (chain, source) {
                return chain.then(function () {
                    return new Promise(function (resolve, reject) {
                        var settled = false;
                        var script = copyExternalScript(source);
                        var finish = function (error, aborted) {
                            if (settled) return;
                            settled = true;
                            window.clearTimeout(timer);
                            if (signal) signal.removeEventListener('abort', handleAbort);
                            if (aborted) {
                                script.remove();
                                reject(abortError());
                            } else if (error) reject(unsupportedNavigation('script-load-failed'));
                            else resolve();
                        };
                        script.addEventListener('load', function () { finish(false); }, { once: true });
                        script.addEventListener('error', function () { finish(true); }, { once: true });
                        var timer = window.setTimeout(function () { finish(true); }, Math.min(number(config.timeoutMs, 10000), 6000));
                        var handleAbort = function () { finish(false, true); };
                        if (signal && signal.aborted) {
                            finish(false, true);
                            return;
                        }
                        if (signal) signal.addEventListener('abort', handleAbort, { once: true });
                        document.head.appendChild(script);
                    });
                });
            }, Promise.resolve());
        }

        function copyStylesheetLink(source) {
            var link = document.createElement('link');
            copyAllowedAttributes(source, link, ['id', 'type', 'media', 'nonce', 'integrity', 'crossorigin', 'referrerpolicy']);
            link.setAttribute('rel', 'stylesheet');
            link.href = source.href;
            return link;
        }

        function copyExternalScript(source) {
            var script = document.createElement('script');
            copyAllowedAttributes(source, script, ['id', 'type', 'nonce', 'integrity', 'crossorigin', 'referrerpolicy']);
            script.async = false;
            if (source.hasAttribute('nomodule')) script.noModule = true;
            script.src = source.src;
            return script;
        }

        function copyStyleElement(source) {
            var style = document.createElement('style');
            copyAllowedAttributes(source, style, ['id', 'type', 'media', 'nonce']);
            style.textContent = source.textContent || '';
            return style;
        }

        function copyDataScript(source) {
            var script = document.createElement('script');
            copyAllowedAttributes(source, script, ['id', 'type', 'nonce']);
            script.textContent = source.textContent || '';
            return script;
        }

        function copyAllowedAttributes(source, target, allowed) {
            allowed.forEach(function (name) {
                if (source.hasAttribute(name)) target.setAttribute(name, source.getAttribute(name));
            });
        }

        function syncInlineStyles(plan) {
            var targetIds = plan.ids;
            document.head.querySelectorAll('style[id]').forEach(function (current) {
                if (managedInlineStyleId(current.id) && !targetIds.has(current.id)) current.remove();
            });

            plan.managed.forEach(function (source, index) {
                var current = Array.prototype.find.call(document.head.querySelectorAll('style[id]'), function (candidate) {
                    return candidate.id === source.id;
                });
                if (current) {
                    if (inlineStyleSignature(current) !== inlineStyleSignature(source)) current.replaceWith(copyStyleElement(source));
                    return;
                }

                var next = null;
                for (var cursor = index + 1; cursor < plan.managed.length; cursor += 1) {
                    var nextId = plan.managed[cursor].id;
                    next = Array.prototype.find.call(document.head.querySelectorAll('style[id]'), function (candidate) {
                        return candidate.id === nextId;
                    });
                    if (next) break;
                }
                var created = copyStyleElement(source);
                if (next) document.head.insertBefore(created, next);
                else document.head.appendChild(created);
            });
        }

        function syncStylesheetAssets(targetUrls) {
            document.querySelectorAll('link[rel~="stylesheet"][href]').forEach(function (link) {
                if (!targetUrls.has(absoluteAsset(link.href))) link.remove();
            });
        }

        function sanitizeImportedContent(root) {
            root.querySelectorAll('script').forEach(function (script) {
                if (executableScript(script)) {
                    script.remove();
                    return;
                }
                script.replaceWith(copyDataScript(script));
            });
            root.querySelectorAll('style').forEach(function (style) {
                style.replaceWith(copyStyleElement(style));
            });
            root.querySelectorAll('link[rel~="stylesheet"],link[rel="preload"],link[rel="modulepreload"]').forEach(function (link) {
                link.remove();
            });
        }

        function removeStagedStyles(nodes) {
            nodes.forEach(function (node) {
                if (node && node.parentNode) node.remove();
            });
        }

        function syncHead(parsed) {
            document.title = parsed.querySelector('title') ? parsed.title : '';
            syncDocumentAttribute('lang', parsed.documentElement.getAttribute('lang'));
            syncDocumentAttribute('dir', parsed.documentElement.getAttribute('dir'));
            syncBaseElement(parsed);
            syncHeadGroup('link[rel="canonical"],link[rel="prev"],link[rel="next"],link[rel="shortlink"],link[rel="alternate"]', parsed);
            syncHeadGroup('meta[name="description"],meta[name="robots"],meta[name="googlebot"],meta[name="bingbot"],meta[name="author"],meta[name="keywords"],meta[property^="og:"],meta[property^="article:"],meta[property^="profile:"],meta[name^="twitter:"],meta[property^="twitter:"]', parsed);
            syncHeadGroup('script[type="application/ld+json"]', parsed);
        }

        function syncDocumentAttribute(name, value) {
            if (value) document.documentElement.setAttribute(name, value);
            else document.documentElement.removeAttribute(name);
        }

        function syncBaseElement(parsed) {
            document.head.querySelectorAll('base[href]').forEach(function (node) { node.remove(); });
            var incoming = parsed.head.querySelector('base[href]:not([data-smp-resolution-base])');
            if (incoming) document.head.prepend(document.importNode(incoming, true));
        }

        function syncHeadGroup(selector, parsed) {
            document.head.querySelectorAll(selector).forEach(function (node) { node.remove(); });
            parsed.head.querySelectorAll(selector).forEach(function (node) {
                document.head.appendChild(node.tagName === 'SCRIPT' ? copyDataScript(node) : document.importNode(node, true));
            });
        }

        function syncBody(parsed) {
            var preserved = runtimeClasses.filter(function (className) { return document.body.classList.contains(className); });
            var incoming = (parsed.body.getAttribute('class') || '').split(/\s+/).filter(Boolean);
            document.body.className = Array.from(new Set(incoming.concat(preserved))).join(' ');
            if (parsed.body.id) document.body.id = parsed.body.id;
            else document.body.removeAttribute('id');
        }

        function syncCompanionFragments(fragments) {
            ['smpi-breadcrumbs'].forEach(function (key) {
                var policy = companionFragmentPolicy(key);
                document.querySelectorAll('template[data-smp-ajax-companion="' + key + '"],' + policy.renderedSelector).forEach(function (node) {
                    node.remove();
                });
                var source = fragments.get(key);
                if (!source) return;
                var template = document.createElement('template');
                template.setAttribute('data-smp-ajax-companion', key);
                template.content.appendChild(document.importNode(source.content, true));
                document.body.appendChild(template);
            });
        }

        function syncPersistentNavigation(parsed) {
            var currentHeader = document.querySelector('[data-elementor-type="header"],header');
            var incomingHeader = parsed.querySelector('[data-elementor-type="header"],header');
            if (!currentHeader || !incomingHeader) return;

            var incomingLinks = Array.prototype.slice.call(incomingHeader.querySelectorAll('a[href]'));
            currentHeader.querySelectorAll('a[href]').forEach(function (currentLink) {
                var href = comparableUrl(currentLink.href);
                var incomingLink = incomingLinks.find(function (candidate) { return comparableUrl(candidate.href) === href; });
                if (!incomingLink) return;
                syncActiveClasses(currentLink, incomingLink);
                var currentItem = currentLink.closest('li');
                var incomingItem = incomingLink.closest('li');
                if (currentItem && incomingItem) syncActiveClasses(currentItem, incomingItem);
                if (incomingLink.hasAttribute('aria-current')) currentLink.setAttribute('aria-current', incomingLink.getAttribute('aria-current'));
                else currentLink.removeAttribute('aria-current');
            });
        }

        function applyLocalizedConfigs(configs) {
            configs.forEach(function (entry) {
                var target = window[entry.name];
                if (target && typeof target === 'object') {
                    Object.keys(target).forEach(function (key) { delete target[key]; });
                    Object.assign(target, entry.value);
                } else {
                    window[entry.name] = entry.value;
                }
                if (entry.name === 'elementorFrontendConfig' && window.elementorFrontend) {
                    try { window.elementorFrontend.config = window[entry.name]; } catch (error) { /* Read-only internals are left untouched. */ }
                }
                if ((entry.name === 'ElementorProFrontendConfig' || entry.name === 'elementorProFrontendConfig') && window.elementorProFrontend) {
                    try { window.elementorProFrontend.config = window[entry.name]; } catch (error) { /* Read-only internals are left untouched. */ }
                }
            });
        }

        function syncActiveClasses(currentNode, incomingNode) {
            var isActiveClass = function (className) {
                return className.indexOf('current-') === 0 || className === 'active' || className === 'is-active' || className === 'elementor-item-active';
            };
            Array.prototype.slice.call(currentNode.classList).filter(isActiveClass).forEach(function (className) { currentNode.classList.remove(className); });
            Array.prototype.slice.call(incomingNode.classList).filter(isActiveClass).forEach(function (className) { currentNode.classList.add(className); });
        }

        function updateHistory(url, options) {
            var payload = { smpPodcastAjax: true, smpScrollX: 0, smpScrollY: 0 };
            if (options.mode === 'push') history.pushState(payload, '', url.href);
            else history.replaceState(Object.assign({}, options.historyState || {}, payload), '', url.href);
        }

        function reinitializeContent(root, url, options, plan) {
            if (plan.elementor) {
                try {
                    window.elementorFrontend.elementsHandler.runReadyTrigger(window.jQuery(root));
                } catch (error) {
                    throw unsupportedNavigation('elementor-reinitialization-failed');
                }
            }

            updateTriggers(!audio.paused && !audio.ended);
            window.dispatchEvent(new Event('resize'));
            var detail = { root: root, url: url.href, mode: options.mode || 'push' };
            document.dispatchEvent(new CustomEvent('smp:after-navigate', { detail: detail }));
            document.dispatchEvent(new CustomEvent('smp:content-ready', { detail: detail }));

            window.requestAnimationFrame(function () {
                if (options.mode === 'pop') {
                    window.scrollTo(number(options.historyState && options.historyState.smpScrollX, 0), number(options.historyState && options.historyState.smpScrollY, 0));
                } else if (url.hash) {
                    var target = document.getElementById(decodeURIComponent(url.hash.slice(1)));
                    if (target) target.scrollIntoView();
                    else window.scrollTo(0, 0);
                } else {
                    window.scrollTo(0, 0);
                }
                focusContent(root, options.mode === 'pop');
                announce('Page loaded: ' + document.title);
                recordHistoryState();
            });
        }

        function focusContent(root, preserveFocus) {
            if (preserveFocus) return;
            var heading = root.querySelector('h1') || root;
            var hadTabindex = heading.hasAttribute('tabindex');
            if (!hadTabindex) heading.setAttribute('tabindex', '-1');
            try { heading.focus({ preventScroll: true }); } catch (error) { /* Focus support is optional. */ }
            if (!hadTabindex) heading.addEventListener('blur', function () { heading.removeAttribute('tabindex'); }, { once: true });
        }

        function setLoading(loading) {
            document.body.classList.toggle('smp-podcast-ajax-loading', loading);
            var descriptor = findContentRoot(document);
            if (descriptor) {
                if (loading) descriptor.node.setAttribute('aria-busy', 'true');
                else descriptor.node.removeAttribute('aria-busy');
            }
        }

        function hardFallback(url, fallback, reason) {
            document.dispatchEvent(new CustomEvent('smp:navigation-rejected', { detail: { url: url.href, reason: reason || 'unsupported-page' } }));
            if (fallback === 'reload') window.location.reload();
            else window.location.assign(url.href);
        }

        function storeCurrentScroll() {
            if (!state.navigationSession) return;
            var current = Object.assign({}, history.state || {}, {
                smpPodcastAjax: true,
                smpScrollX: window.scrollX,
                smpScrollY: window.scrollY
            });
            history.replaceState(current, '', window.location.href);
        }

        function recordHistoryState() {
            if (!state.navigationSession) return;
            storeCurrentScroll();
        }

        function rootUsesElementor(root) {
            return root.matches('[data-elementor-type],.elementor') || !!root.querySelector('[data-elementor-type],.elementor,.elementor-element');
        }

        function elementorLifecycleReady() {
            return !!(window.jQuery
                && window.elementorFrontend
                && window.elementorFrontend.elementsHandler
                && typeof window.elementorFrontend.elementsHandler.runReadyTrigger === 'function');
        }

        function rejectInlineEventHandlers(parsed) {
            parsed.querySelectorAll('*').forEach(function (element) {
                Array.prototype.forEach.call(element.attributes || [], function (attribute) {
                    if (/^on/i.test(attribute.name)) throw unsupportedNavigation('inline-event-handler');
                });
            });
        }

        function validateStyleElements(parsed) {
            parsed.querySelectorAll('style').forEach(function (style) {
                validateCssText(normalizedAssetText(style.textContent));
            });
        }

        function validateCssText(css) {
            if (/(?:expression\s*\(|url\s*\(\s*['"]?\s*(?:javascript|vbscript)\s*:|(?:^|[;{])\s*(?:behavior|-moz-binding)\s*:)/i.test(css)) {
                throw unsupportedNavigation('unsafe-inline-style');
            }

            var remainder = css.replace(/@import\s+([^;]+);/gi, function (rule, payload) {
                var match = /^\s*(?:url\(\s*)?(?:(["'])(.*?)\1|([^\s)"']+))\s*\)?(?:\s+[^;]+)?\s*$/i.exec(payload);
                var imported = match ? (match[2] || match[3] || '') : '';
                if (!imported || !safeStyleAsset(imported)) throw unsupportedNavigation('unsafe-css-import');
                return '';
            });
            if (/@import\b/i.test(remainder)) throw unsupportedNavigation('unsafe-css-import');
        }

        function managedInlineStyleId(id) {
            return /^(?:elementor|wp|global-styles|font-awesome|hws|smpi|smp|loop)(?:-|$)/i.test(String(id || ''));
        }

        function inlineStyleSignature(style) {
            return [
                (style.id || '').trim(),
                (style.getAttribute('type') || '').trim().toLowerCase(),
                (style.getAttribute('media') || '').trim(),
                normalizedAssetText(style.textContent)
            ].join('\n');
        }

        function incrementSignature(signatures, signature) {
            signatures.set(signature, (signatures.get(signature) || 0) + 1);
        }

        function safeDynamicScript(source) {
            var id = (source.id || '').trim();
            var paths = {
                'e-sticky-js': /^\/wp-content\/plugins\/elementor-pro\/assets\/lib\/sticky\/jquery\.sticky(?:\.min)?\.js$/,
                'jet-plugins-js': /^\/wp-content\/plugins\/jet-engine\/assets\/lib\/jet-plugins\/jet-plugins\.js$/,
                'jet-engine-data-stores-js': /^\/wp-content\/plugins\/jet-engine\/assets\/js\/frontend\/modules\/data-stores\.js$/,
                'jet-engine-frontend-js': /^\/wp-content\/plugins\/jet-engine\/assets\/js\/frontend\/frontend\.js$/
            };
            if (!Object.prototype.hasOwnProperty.call(paths, id)) return false;
            try {
                var url = new URL(source.src, window.location.href);
                return url.origin === window.location.origin && /^https?:$/.test(url.protocol) && paths[id].test(url.pathname);
            } catch (error) {
                return false;
            }
        }

        function alreadyInitializedInlineScript(source, text) {
            var type = scriptType(source);
            if (source.id || (type && type !== 'text/javascript' && type !== 'application/javascript')) return false;
            return text.indexOf('WordfenceTestMonBot') !== -1
                && text.indexOf('window.wfLogHumanRan') !== -1
                && text.indexOf("document.createElement('script')") !== -1
                && /[?&]wordfence_lh=1&hid=[a-f0-9]+/i.test(text);
        }

        function unsupportedNavigation(reason) {
            var error = new Error(reason || 'unsupported-page');
            error.smpNavigationReason = reason || 'unsupported-page';
            return error;
        }

        function abortError() {
            var error = new Error('Navigation aborted');
            error.name = 'AbortError';
            return error;
        }

        function navigationErrorReason(error, timedOut) {
            if (timedOut) return 'request-timeout';
            if (error && error.smpNavigationReason) return error.smpNavigationReason;
            return 'request-failed';
        }

        function normalizedAssetText(value) {
            return String(value || '').replace(/\r\n?/g, '\n').trim();
        }

        function safeStyleAsset(value) {
            try {
                var url = new URL(value, window.location.href);
                if (!/^https?:$/.test(url.protocol)) return false;
                if (url.origin === window.location.origin) return true;
                return url.protocol === 'https:' && (url.hostname === 'fonts.googleapis.com' || url.hostname === 'use.fontawesome.com');
            } catch (error) {
                return false;
            }
        }

        function absoluteAsset(value) {
            try {
                var url = new URL(value, window.location.href);
                url.hash = '';
                return url.href;
            } catch (error) { return ''; }
        }

        function comparableUrl(value) {
            try {
                var url = new URL(value, window.location.href);
                url.hash = '';
                return url.href;
            } catch (error) { return String(value || ''); }
        }

        function mediaUrl(value) {
            try {
                var url = new URL(String(value || ''), window.location.href);
                return /^https?:$/.test(url.protocol) ? url.href : '';
            } catch (error) { return ''; }
        }

        function pageUrl(value) {
            try {
                var url = new URL(String(value || ''), window.location.href);
                return /^https?:$/.test(url.protocol) ? url.href : '';
            } catch (error) { return ''; }
        }

        function enforcePlayerSingleton(scope) {
            nodesIncludingScope(scope, '[data-smp-player]').forEach(function (candidate) {
                if (candidate !== player) candidate.remove();
            });
            neutralizeLegacyAudio(scope);
        }

        function neutralizeLegacyAudio(scope) {
            nodesIncludingScope(scope, '#ap-audio').forEach(function (legacyAudio) {
                var container = legacyAudio.closest('[data-smp-episode],article,.e-loop-item,.elementor-widget-container') || legacyAudio.parentElement || scope;
                var trigger = container && container.querySelector ? container.querySelector('#ap-toggle') : null;
                if (!trigger && scope.querySelector) trigger = scope.querySelector('#ap-toggle');
                var sourceNode = legacyAudio.querySelector ? legacyAudio.querySelector('source[src]') : null;
                var source = legacyAudio.currentSrc || legacyAudio.getAttribute('src') || (sourceNode ? sourceNode.getAttribute('src') : '');
                if (container && source) state.legacySources.set(container, source);
                if (trigger && source && !trigger.getAttribute('data-smp-audio-src') && !trigger.getAttribute('data-mp3')) {
                    trigger.setAttribute('data-smp-audio-src', source);
                }
                try { legacyAudio.pause(); } catch (error) { /* A detached legacy element may not implement media controls. */ }
                legacyAudio.removeAttribute('autoplay');
                legacyAudio.removeAttribute('src');
                if (sourceNode) sourceNode.removeAttribute('src');
                try { legacyAudio.load(); } catch (error) { /* Reset is best effort before removal. */ }
                legacyAudio.remove();
            });
            nodesIncludingScope(scope, '#ap-toggle').forEach(function (trigger) {
                if (trigger.getAttribute('data-smp-audio-src') || trigger.getAttribute('data-mp3')) return;
                var container = trigger.closest('[data-smp-episode],article,.e-loop-item,.elementor-widget-container') || trigger.parentElement || scope;
                var source = container ? state.legacySources.get(container) : '';
                if (source) trigger.setAttribute('data-smp-audio-src', source);
            });
        }

        function nodesIncludingScope(scope, selector) {
            var nodes = [];
            if (scope && scope.matches && scope.matches(selector)) nodes.push(scope);
            if (scope && scope.querySelectorAll) nodes = nodes.concat(Array.prototype.slice.call(scope.querySelectorAll(selector)));
            return nodes;
        }

        function observeLegacyPlayers() {
            if (!window.MutationObserver || !document.documentElement) return;
            state.legacyObserver = new MutationObserver(function (records) {
                records.forEach(function (record) {
                    Array.prototype.forEach.call(record.addedNodes || [], function (node) {
                        if (node.nodeType === 1) enforcePlayerSingleton(node);
                    });
                });
            });
            state.legacyObserver.observe(document.documentElement, { childList: true, subtree: true });
        }

        function formatTime(value) {
            var seconds = Math.max(0, Math.floor(number(value, 0)));
            var hours = Math.floor(seconds / 3600);
            var minutes = Math.floor((seconds % 3600) / 60);
            var remainder = seconds % 60;
            return hours > 0
                ? hours + ':' + String(minutes).padStart(2, '0') + ':' + String(remainder).padStart(2, '0')
                : minutes + ':' + String(remainder).padStart(2, '0');
        }

        function cleanText(value) {
            return String(value || '').replace(/\s+/g, ' ').trim();
        }

        function number(value, fallback) {
            var parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : fallback;
        }

        function announce(message) {
            if (status) status.textContent = message || '';
        }
    });
})();
