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
        var stage = player.querySelector('[data-smp-stage]');
        var cover = player.querySelector('[data-smp-cover]');
        var videoShell = player.querySelector('[data-smp-video-shell]');
        var videoFrame = player.querySelector('[data-smp-video]');
        var kind = player.querySelector('[data-smp-kind]');
        var modes = player.querySelector('[data-smp-modes]');
        var audioModeButton = player.querySelector('[data-smp-mode-button="audio"]');
        var videoModeButton = player.querySelector('[data-smp-mode-button="video"]');
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
            mode: 'audio',
            videoReady: false,
            videoPlaying: false,
            videoPlaybackActivated: false,
            videoCurrentTime: 0,
            videoDuration: 0,
            videoMuted: false,
            videoPendingPlay: false,
            videoPendingSeek: null,
            videoId: '',
            seeking: false,
            scrollTimer: 0,
            wordfenceLoggerActive: false,
            legacyObserver: null,
            legacySources: new WeakMap()
        };
        var strings = config.strings || {};
        var triggerSelector = '[data-smp-player-trigger],.ep-listen[data-mp3],#ap-toggle';
        var watchTriggerSelector = '[data-smp-watch-trigger],.smp-watch-button,.mpp-card-watch a[href*="youtu"]';
        var allTriggerSelector = triggerSelector + ',' + watchTriggerSelector;
        var runtimeClasses = ['smp-podcast-player-visible', 'smp-podcast-player-video-visible', 'smp-podcast-ajax-loading'];
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
            window.addEventListener('message', handleVideoMessage);

            toggle.addEventListener('click', function () {
                if (!state.track) return;
                if (state.mode === 'video') {
                    if (state.videoPlaying) pauseVideo();
                    else playVideo();
                    return;
                }
                if (audio.paused) playAudio();
                else audio.pause();
            });

            if (audioModeButton) audioModeButton.addEventListener('click', function () { switchMode('audio', true); });
            if (videoModeButton) videoModeButton.addEventListener('click', function () { switchMode('video', true); });

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
                var target = Math.max(0, number(seek.value, 0));
                if (state.mode === 'video') {
                    state.videoCurrentTime = Math.min(target, state.videoDuration || target);
                    postVideoCommand('seekTo', [state.videoCurrentTime, true]);
                } else if (Number.isFinite(audio.duration)) {
                    audio.currentTime = Math.min(target, audio.duration);
                }
                state.seeking = false;
                updateTimeline();
            });

            if (rate) {
                rate.addEventListener('change', function () {
                    audio.playbackRate = number(rate.value, 1);
                    if (state.mode === 'video') postVideoCommand('setPlaybackRate', [audio.playbackRate]);
                    savePreferences();
                    updateMediaPosition();
                });
            }
            if (volume) {
                volume.addEventListener('input', function () {
                    audio.volume = Math.max(0, Math.min(1, number(volume.value, 1)));
                    if (audio.volume > 0) audio.muted = false;
                    if (state.mode === 'video') {
                        state.videoMuted = false;
                        postVideoCommand('unMute');
                        postVideoCommand('setVolume', [Math.round(audio.volume * 100)]);
                    }
                    savePreferences();
                });
            }
            if (mute) {
                mute.addEventListener('click', function () {
                    if (state.mode === 'video') {
                        state.videoMuted = !state.videoMuted;
                        postVideoCommand(state.videoMuted ? 'mute' : 'unMute');
                    } else {
                        audio.muted = !audio.muted;
                    }
                    updateVolume();
                    savePreferences();
                });
            }

            ['play', 'pause', 'ended', 'waiting', 'canplay', 'volumechange', 'ratechange'].forEach(function (eventName) {
                audio.addEventListener(eventName, updatePlayerState);
            });
            audio.addEventListener('play', function () {
                if (state.mode !== 'audio') {
                    audio.pause();
                    return;
                }
                state.playbackActivated = true;
            });
            audio.addEventListener('pause', function () {
                if (state.mode !== 'audio') return;
                cancelPendingNavigation('playback-paused');
                if (!navigationActive()) parkNavigationSession();
            });
            audio.addEventListener('ended', function () {
                state.playbackActivated = false;
                if (state.mode !== 'audio') return;
                cancelPendingNavigation('playback-ended');
                parkNavigationSession();
            });
            ['loadedmetadata', 'durationchange', 'timeupdate', 'progress'].forEach(function (eventName) {
                audio.addEventListener(eventName, updateTimeline);
            });
            audio.addEventListener('error', function () {
                state.playbackActivated = false;
                if (state.mode !== 'audio') return;
                cancelPendingNavigation('playback-error');
                parkNavigationSession();
                announce('This episode could not be played.');
                updatePlayerState();
            });
        }

        function handleDocumentClick(event) {
            var target = event.target instanceof Element ? event.target : null;
            if (!target) return;

            var watchHit = target.closest(watchTriggerSelector);
            if (watchHit && !watchHit.closest('[data-smp-player]') && config.videoEnabled && unmodifiedPrimaryClick(event)) {
                var watchTrigger = interactiveTrigger(watchHit);
                var videoTrack = trackFromTrigger(watchTrigger);
                if (!videoTrack.videoId) return;
                event.preventDefault();
                event.stopPropagation();
                activateTrack(videoTrack, watchTrigger, 'video');
                return;
            }

            var trigger = target.closest(triggerSelector);
            if (trigger && !trigger.closest('[data-smp-player]')) {
                var track = trackFromTrigger(trigger);
                if (!track.src) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                activateTrack(track, trigger, 'audio');
                return;
            }

            var anchor = target.closest('a[href]');
            if (!anchor || !shouldIntercept(event, anchor)) return;
            event.preventDefault();
            event.stopPropagation();
            navigate(new URL(anchor.href, window.location.href), { mode: 'push', fallback: 'assign' });
        }

        function trackFromTrigger(trigger) {
            var container = episodeContainer(trigger);
            var audioTrigger = trigger.matches(triggerSelector) ? trigger : (container ? container.querySelector(triggerSelector) : null);
            var watchHit = trigger.matches(watchTriggerSelector) ? trigger : (container ? container.querySelector(watchTriggerSelector) : null);
            var watchTrigger = watchHit ? interactiveTrigger(watchHit) : null;
            var metadataTrigger = audioTrigger || trigger;
            var titleNode = container ? container.querySelector('[data-smp-episode-title],.ep-title,.entry-title,h1,h2,h3') : null;
            var linkNode = container ? container.querySelector('h1 a[href],h2 a[href],h3 a[href],[data-smp-episode-title] a[href],a[data-smp-url]') : null;
            var imageNode = container ? container.querySelector('img') : null;
            var source = attributeFrom([metadataTrigger, trigger], 'data-smp-audio-src')
                || attributeFrom([metadataTrigger, trigger], 'data-mp3');
            var videoValue = attributeFrom([trigger, metadataTrigger, watchTrigger], 'data-smp-video-id')
                || attributeFrom([trigger, metadataTrigger, watchTrigger], 'data-smp-video-url')
                || (watchTrigger ? watchTrigger.getAttribute('href') : '');

            return {
                src: mediaUrl(source),
                download: mediaUrl(attributeFrom([metadataTrigger, trigger], 'data-smp-download-src') || source),
                title: cleanText(attributeFrom([metadataTrigger, trigger], 'data-smp-title') || (titleNode ? titleNode.textContent : '') || document.title),
                url: pageUrl(attributeFrom([metadataTrigger, trigger], 'data-smp-url') || (linkNode ? linkNode.href : window.location.href)),
                image: mediaUrl(attributeFrom([metadataTrigger, trigger], 'data-smp-image') || (imageNode ? imageNode.currentSrc || imageNode.src : '')),
                postId: attributeFrom([metadataTrigger, trigger], 'data-smp-post-id') || '',
                duration: attributeFrom([metadataTrigger, trigger], 'data-smp-duration') || '',
                durationSeconds: number(attributeFrom([metadataTrigger, trigger], 'data-smp-duration-seconds'), 0),
                videoId: youtubeVideoId(videoValue),
                videoUrl: pageUrl(attributeFrom([trigger, metadataTrigger, watchTrigger], 'data-smp-video-url') || (watchTrigger ? watchTrigger.getAttribute('href') : ''))
            };
        }

        function activateTrack(track, trigger, requestedMode) {
            state.trigger = trigger;
            if (sameTrack(state.track, track)) {
                if (state.mode !== requestedMode) {
                    switchMode(requestedMode, true);
                } else if (requestedMode === 'video') {
                    if (state.videoPlaying) pauseVideo();
                    else playVideo();
                } else if (audio.paused) {
                    playAudio();
                } else {
                    audio.pause();
                }
                return;
            }

            audio.pause();
            destroyVideo();
            state.track = track;
            state.playbackActivated = false;
            state.videoPlaybackActivated = false;
            state.videoCurrentTime = 0;
            state.videoDuration = 0;
            if (track.src) audio.src = track.src;
            else audio.removeAttribute('src');
            audio.load();
            renderTrack();
            showPlayer();
            document.dispatchEvent(new CustomEvent('smp:podcast-track-selected', { detail: { track: track, trigger: trigger, mode: requestedMode } }));
            switchMode(requestedMode, true);
        }

        function playAudio() {
            if (!state.track || !state.track.src) return;
            if (state.mode !== 'audio') switchMode('audio', false);
            var promise = audio.play();
            if (promise && typeof promise.catch === 'function') {
                promise.catch(function () {
                    announce('Press play to start this episode.');
                    updatePlayerState();
                });
            }
        }

        function playVideo() {
            if (!state.track || !state.track.videoId || !config.videoEnabled) return;
            if (state.mode !== 'video') switchMode('video', false);
            ensureVideo();
            state.videoPendingPlay = true;
            postVideoCommand('playVideo');
            announce((strings.loadingVideo || 'Loading episode video') + ': ' + state.track.title);
        }

        function pauseVideo() {
            state.videoPendingPlay = false;
            postVideoCommand('pauseVideo');
            state.videoPlaying = false;
            if (state.mode === 'video') {
                cancelPendingNavigation('video-paused');
                parkNavigationSession();
            }
            updatePlayerState();
        }

        function switchMode(mode, shouldPlay) {
            if (!state.track) return;
            if (mode === 'video' && (!config.videoEnabled || !state.track.videoId)) {
                announce(strings.videoUnavailable || 'This episode does not have a video.');
                return;
            }
            if (mode === 'audio' && !state.track.src) return;

            var position = currentPosition();
            if (mode === 'video') {
                audio.pause();
                state.mode = 'video';
                state.videoPendingSeek = config.syncMediaPosition ? position : 0;
                player.setAttribute('data-smp-mode', 'video');
                document.body.classList.add('smp-podcast-player-video-visible');
                if (stage) stage.hidden = false;
                if (cover) cover.hidden = true;
                if (videoShell) videoShell.hidden = false;
                ensureVideo();
                if (shouldPlay) playVideo();
            } else {
                if (state.mode === 'video') pauseVideo();
                state.mode = 'audio';
                player.setAttribute('data-smp-mode', 'audio');
                document.body.classList.remove('smp-podcast-player-video-visible');
                if (videoShell) videoShell.hidden = true;
                if (cover) cover.hidden = !(config.showCover && state.track.image);
                if (stage) stage.hidden = !(config.showCover && state.track.image);
                if (config.syncMediaPosition && position > 0) setAudioPosition(position);
                if (shouldPlay) playAudio();
            }
            updateModeControls();
            updateTimeline();
            updatePlayerState();
            document.dispatchEvent(new CustomEvent('smp:podcast-mode-changed', { detail: { mode: state.mode, track: state.track } }));
        }

        function setAudioPosition(position) {
            var apply = function () {
                var maximum = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : position;
                audio.currentTime = Math.max(0, Math.min(position, maximum));
            };
            try { apply(); } catch (error) { /* Metadata may not be ready yet. */ }
            if (audio.readyState === 0) audio.addEventListener('loadedmetadata', apply, { once: true });
        }

        function ensureVideo() {
            if (!videoFrame || !state.track || !state.track.videoId) return;
            if (state.videoId === state.track.videoId && videoFrame.getAttribute('src')) {
                flushVideoIntent();
                return;
            }
            destroyVideo();
            state.videoId = state.track.videoId;
            state.videoPendingPlay = true;
            var params = new URLSearchParams({
                autoplay: '1',
                controls: '1',
                enablejsapi: '1',
                playsinline: '1',
                rel: '0',
                modestbranding: '1',
                origin: window.location.origin
            });
            videoFrame.title = 'Video: ' + (state.track.title || 'Podcast episode');
            videoFrame.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(state.videoId) + '?' + params.toString();
            videoFrame.addEventListener('load', subscribeToVideo, { once: true });
        }

        function subscribeToVideo() {
            if (!videoFrame || !videoFrame.contentWindow) return;
            postVideoMessage({ event: 'listening', id: 'smp-podcast-youtube' });
            postVideoCommand('addEventListener', ['onReady']);
            postVideoCommand('addEventListener', ['onStateChange']);
            window.setTimeout(flushVideoIntent, 80);
        }

        function handleVideoMessage(event) {
            if (!videoFrame || event.source !== videoFrame.contentWindow || !trustedYouTubeOrigin(event.origin)) return;
            var payload = event.data;
            if (typeof payload === 'string') {
                try { payload = JSON.parse(payload); } catch (error) { return; }
            }
            if (!payload || typeof payload !== 'object') return;

            if (payload.event === 'onReady') {
                state.videoReady = true;
                flushVideoIntent();
                return;
            }
            if (payload.event === 'onStateChange') updateVideoPlaybackState(number(payload.info, -1));
            if (payload.event === 'infoDelivery' && payload.info && typeof payload.info === 'object') {
                if (Number.isFinite(Number(payload.info.currentTime))) state.videoCurrentTime = Math.max(0, Number(payload.info.currentTime));
                if (Number.isFinite(Number(payload.info.duration))) state.videoDuration = Math.max(0, Number(payload.info.duration));
                if (Number.isFinite(Number(payload.info.playerState))) updateVideoPlaybackState(Number(payload.info.playerState));
                if (typeof payload.info.muted === 'boolean') state.videoMuted = payload.info.muted;
                updateTimeline();
            }
        }

        function updateVideoPlaybackState(code) {
            if (code === 1) {
                state.videoPlaying = true;
                state.videoPlaybackActivated = true;
                state.videoPendingPlay = false;
            } else if (code === 0) {
                state.videoPlaying = false;
                state.videoPlaybackActivated = false;
                cancelPendingNavigation('video-ended');
                parkNavigationSession();
            } else if (code === 2 || code === 5) {
                state.videoPlaying = false;
                if (state.mode === 'video') {
                    cancelPendingNavigation('video-paused');
                    parkNavigationSession();
                }
            }
            updatePlayerState();
        }

        function flushVideoIntent() {
            if (!videoFrame || !videoFrame.contentWindow) return;
            if (state.videoPendingSeek !== null) {
                postVideoCommand('seekTo', [Math.max(0, state.videoPendingSeek), true]);
                state.videoCurrentTime = Math.max(0, state.videoPendingSeek);
                state.videoPendingSeek = null;
            }
            postVideoCommand('setVolume', [Math.round(audio.volume * 100)]);
            postVideoCommand('setPlaybackRate', [audio.playbackRate]);
            if (state.videoPendingPlay) postVideoCommand('playVideo');
        }

        function postVideoCommand(command, args) {
            postVideoMessage({ event: 'command', func: command, args: Array.isArray(args) ? args : [], id: 'smp-podcast-youtube' });
        }

        function postVideoMessage(payload) {
            if (!videoFrame || !videoFrame.contentWindow || !videoFrame.getAttribute('src')) return;
            videoFrame.contentWindow.postMessage(JSON.stringify(payload), 'https://www.youtube-nocookie.com');
        }

        function destroyVideo() {
            if (videoFrame) videoFrame.removeAttribute('src');
            state.videoReady = false;
            state.videoPlaying = false;
            state.videoPlaybackActivated = false;
            state.videoPendingPlay = false;
            state.videoPendingSeek = null;
            state.videoId = '';
        }

        function showPlayer() {
            player.hidden = false;
            document.body.classList.add('smp-podcast-player-visible');
        }

        function closePlayer() {
            var focusTarget = state.trigger;
            audio.pause();
            pauseVideo();
            destroyVideo();
            cancelPendingNavigation('player-closed');
            parkNavigationSession();
            audio.removeAttribute('src');
            audio.load();
            state.track = null;
            state.playbackActivated = false;
            state.videoPlaybackActivated = false;
            state.mode = 'audio';
            player.setAttribute('data-smp-mode', 'audio');
            player.hidden = true;
            document.body.classList.remove('smp-podcast-player-visible', 'smp-podcast-player-video-visible');
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

            if (config.showCover && state.track.image) {
                cover = ensureCover();
                if (cover) {
                    cover.src = state.track.image;
                    cover.alt = 'Episode artwork for ' + state.track.title;
                    cover.hidden = false;
                }
            } else {
                removeCover();
            }
            if (stage) stage.hidden = !(config.showCover && state.track.image);

            if (download && state.track.download) download.href = state.track.download;
            if (state.track.durationSeconds > 0) {
                seek.max = String(state.track.durationSeconds);
                duration.textContent = formatTime(state.track.durationSeconds);
            }
            updateModeControls();
            setMediaMetadata();
        }

        function ensureCover() {
            if (cover && cover.isConnected) return cover;
            if (!stage) return null;
            cover = document.createElement('img');
            cover.setAttribute('data-smp-cover', '');
            cover.className = 'smp-podcast-player__cover';
            cover.alt = '';
            cover.hidden = true;
            stage.insertBefore(cover, videoShell || stage.firstChild);
            return cover;
        }

        function removeCover() {
            if (cover && cover.parentNode) cover.remove();
            cover = null;
        }

        function updatePlayerState() {
            var playing = mediaPlaying();
            toggle.setAttribute('aria-pressed', playing ? 'true' : 'false');
            toggle.setAttribute('aria-label', playing ? (strings.pause || 'Pause episode') : (strings.play || 'Play episode'));
            playIcon.hidden = playing;
            pauseIcon.hidden = !playing;
            updateVolume();
            updateTriggers(playing);

            if (state.mode === 'video' && state.videoPendingPlay && !state.videoPlaying) announce(strings.loadingVideo || 'Loading episode video');
            else if (state.mode === 'audio' && audio.readyState < 3 && playing) announce(strings.loading || 'Loading episode');
            else if (playing && state.track) announce('Playing ' + state.track.title);
            else if (state.track) announce('Paused ' + state.track.title);
            updateMediaPosition();
        }

        function updateTriggers(playing) {
            document.querySelectorAll(allTriggerSelector).forEach(function (hit) {
                var trigger = interactiveTrigger(hit);
                var candidate = trackFromTrigger(trigger);
                var current = sameTrack(state.track, candidate);
                var triggerMode = isWatchTrigger(hit) ? 'video' : 'audio';
                var active = current && playing && state.mode === triggerMode;
                trigger.setAttribute('aria-controls', 'smp-podcast-player');
                if (trigger.tagName === 'BUTTON') trigger.setAttribute('aria-pressed', active ? 'true' : 'false');
                trigger.classList.toggle('is-smp-playing', active);
                trigger.classList.toggle('is-smp-current', current);
                if (hit !== trigger) {
                    hit.classList.toggle('is-smp-playing', active);
                    hit.classList.toggle('is-smp-current', current);
                }
            });
        }

        function updateTimeline() {
            var position = currentPosition();
            var total = state.mode === 'video'
                ? (state.videoDuration || (state.track ? number(state.track.durationSeconds, 0) : 0))
                : (Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : (state.track ? number(state.track.durationSeconds, 0) : 0));
            if (total > 0) {
                seek.max = String(total);
                duration.textContent = formatTime(total);
            }
            if (!state.seeking) {
                seek.value = String(position);
                elapsed.textContent = formatTime(position);
            }
            seek.setAttribute('aria-valuetext', formatTime(position) + ' of ' + formatTime(total));
            updateMediaPosition();
        }

        function updateVolume() {
            var muted = state.mode === 'video' ? state.videoMuted : audio.muted;
            if (volume) volume.value = String(muted ? 0 : audio.volume);
            if (mute) {
                mute.setAttribute('aria-pressed', muted ? 'true' : 'false');
                mute.setAttribute('aria-label', muted ? 'Unmute media' : 'Mute media');
            }
            if (rate) rate.value = String(audio.playbackRate);
        }

        function skipBy(seconds) {
            if (!state.track) return;
            if (state.mode === 'video') {
                var videoMaximum = state.videoDuration || Number.MAX_SAFE_INTEGER;
                state.videoCurrentTime = Math.max(0, Math.min(videoMaximum, state.videoCurrentTime + seconds));
                postVideoCommand('seekTo', [state.videoCurrentTime, true]);
            } else {
                var maximum = Number.isFinite(audio.duration) ? audio.duration : Number.MAX_SAFE_INTEGER;
                audio.currentTime = Math.max(0, Math.min(maximum, audio.currentTime + seconds));
            }
            updateTimeline();
        }

        function updateModeControls() {
            var hasAudio = !!(state.track && state.track.src);
            var hasVideo = !!(config.videoEnabled && state.track && state.track.videoId);
            if (modes) modes.hidden = !config.showModeSwitch || !(hasAudio && hasVideo);
            if (audioModeButton) {
                audioModeButton.hidden = !hasAudio;
                audioModeButton.classList.toggle('is-active', state.mode === 'audio');
                audioModeButton.setAttribute('aria-pressed', state.mode === 'audio' ? 'true' : 'false');
            }
            if (videoModeButton) {
                videoModeButton.hidden = !hasVideo;
                videoModeButton.classList.toggle('is-active', state.mode === 'video');
                videoModeButton.setAttribute('aria-pressed', state.mode === 'video' ? 'true' : 'false');
            }
            if (kind) kind.textContent = state.mode === 'video' ? 'Video' : 'Audio';
        }

        function mediaPlaying() {
            if (!state.track) return false;
            return state.mode === 'video' ? state.videoPlaying : (!audio.paused && !audio.ended);
        }

        function currentPosition() {
            return state.mode === 'video' ? Math.max(0, state.videoCurrentTime) : (Number.isFinite(audio.currentTime) ? audio.currentTime : 0);
        }

        function configureMediaSession() {
            if (!config.mediaSession || !('mediaSession' in navigator)) return;
            var handlers = {
                play: function () { if (state.mode === 'video') playVideo(); else playAudio(); },
                pause: function () { if (state.mode === 'video') pauseVideo(); else audio.pause(); },
                seekbackward: function (details) { skipBy(-(details.seekOffset || number(config.skipBack, 15))); },
                seekforward: function (details) { skipBy(details.seekOffset || number(config.skipForward, 30)); },
                seekto: function (details) {
                    if (state.mode === 'video') {
                        state.videoCurrentTime = Math.max(0, details.seekTime);
                        postVideoCommand('seekTo', [state.videoCurrentTime, true]);
                    } else if (details.fastSeek && typeof audio.fastSeek === 'function') audio.fastSeek(details.seekTime);
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
            var mediaDuration = state.mode === 'video' ? state.videoDuration : audio.duration;
            var mediaPosition = currentPosition();
            if (!Number.isFinite(mediaDuration) || mediaDuration <= 0) return;
            try {
                navigator.mediaSession.setPositionState({
                    duration: mediaDuration,
                    playbackRate: audio.playbackRate,
                    position: Math.min(mediaPosition, mediaDuration)
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
            if (!ajaxSupported || !config.ajaxNavigation || !state.track) return false;
            if (state.mode === 'video') {
                return !!(state.track.videoId && state.videoPlaybackActivated && state.videoPlaying && videoFrame && videoFrame.getAttribute('src'));
            }
            if (!state.playbackActivated || !audio.currentSrc || audio.ended) return false;
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
                    initializeWordfenceHumanLogger(plan.wordfenceUrl);
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
                scripts: [],
                wordfenceUrl: ''
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
            var wordfenceInitialized = state.wordfenceLoggerActive || window.wfLogHumanRan;
            document.querySelectorAll('script:not([src])').forEach(function (node) {
                var text = normalizedAssetText(node.textContent);
                if (!text) return;
                var signature = scriptType(node) + '\n' + text;
                currentInlineSignatures.add(signature);
                if (parseWordfenceHumanLogger(node, text)) wordfenceInitialized = true;
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
                    if (ignorableSelfRemovingScript(source)) return;
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
                var wordfenceUrl = parseWordfenceHumanLogger(source, text);
                if (wordfenceUrl) {
                    if (wordfenceInitialized) return;
                    if (plan.wordfenceUrl) throw unsupportedNavigation('duplicate-wordfence-logger');
                    plan.wordfenceUrl = wordfenceUrl;
                    return;
                }
                var localized = parseSupportedLocalizedConfig(source, text);
                if (localized) {
                    plan.configs.push(localized);
                    return;
                }
                if (safeDynamicInlineScript(source, text)) {
                    plan.scripts.push(source);
                    return;
                }
                throw unsupportedNavigation('unsupported-inline-script');
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
                elementorProFrontendConfig: /^elementor-pro-frontend-js-(?:before|after)$/,
                JetEngineSettings: /^jet-engine-frontend-js-extra$/
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
                var escapedId = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                var candidate = cleaned.replace(new RegExp('(?:^|\\n)//# sourceURL=' + escapedId + '\\s*$'), '').trim();
                var assignment = new RegExp('^(?:var\\s+|window\\.)?' + escapedName + '\\s*=\\s*([\\s\\S]+);$').exec(candidate);
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
                        if (!source.src) {
                            if (signal && signal.aborted) {
                                reject(abortError());
                                return;
                            }
                            var inlineText = normalizedAssetText(source.textContent);
                            if (!safeDynamicInlineScript(source, inlineText)) {
                                reject(unsupportedNavigation('unsupported-inline-script'));
                                return;
                            }
                            if (signal && signal.aborted) {
                                reject(abortError());
                                return;
                            }
                            document.head.appendChild(copyExecutableInlineScript(source));
                            resolve();
                            return;
                        }
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

        function copyExecutableInlineScript(source) {
            var script = document.createElement('script');
            copyAllowedAttributes(source, script, ['id', 'type', 'nonce']);
            script.textContent = source.textContent || '';
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

        function initializeWordfenceHumanLogger(value) {
            if (!value || state.wordfenceLoggerActive || window.wfLogHumanRan) return;
            if (/(?:Chrome\/26\.0\.1410\.63 Safari\/537\.31|WordfenceTestMonBot)/.test(navigator.userAgent)) return;
            var url;
            try { url = new URL(value, window.location.href); } catch (error) { return; }
            if (url.origin !== window.location.origin || url.pathname !== '/' || url.searchParams.get('wordfence_lh') !== '1') return;

            var events = 'contextmenu dblclick drag dragend dragenter dragleave dragover dragstart drop keydown keypress keyup mousedown mousemove mouseout mouseover mouseup mousewheel scroll'.split(' ');
            var cleanup = function () {
                events.forEach(function (eventName) { document.removeEventListener(eventName, logHuman, false); });
            };
            var logHuman = function () {
                if (window.wfLogHumanRan) {
                    cleanup();
                    return;
                }
                window.wfLogHumanRan = true;
                cleanup();
                var script = document.createElement('script');
                script.id = 'smp-wordfence-human-logger';
                script.type = 'text/javascript';
                script.async = true;
                url.searchParams.set('r', String(Math.random()));
                script.src = url.href;
                document.head.appendChild(script);
            };
            state.wordfenceLoggerActive = true;
            events.forEach(function (eventName) { document.addEventListener(eventName, logHuman, false); });
        }

        function reinitializeContent(root, url, options, plan) {
            if (plan.elementor) {
                try {
                    reinitializeElementorElements(root);
                } catch (error) {
                    throw unsupportedNavigation('elementor-reinitialization-failed');
                }
            }

            updateTriggers(mediaPlaying());
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

        function reinitializeElementorElements(root) {
            nodesIncludingScope(root, '.elementor-element[data-element_type]').forEach(function (element) {
                window.elementorFrontend.elementsHandler.runReadyTrigger(element);
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
            try {
                var url = new URL(source.src, window.location.href);
                if (id === 'elementor-recaptcha_v3-api-js') {
                    return url.protocol === 'https:'
                        && (url.host === 'www.google.com' || url.host === 'www.recaptcha.net')
                        && url.pathname === '/recaptcha/api.js'
                        && url.searchParams.getAll('render').length === 1
                        && url.searchParams.get('render') === 'explicit'
                        && url.hash === '';
                }
                if (!Object.prototype.hasOwnProperty.call(paths, id)) return false;
                return url.origin === window.location.origin && /^https?:$/.test(url.protocol) && paths[id].test(url.pathname);
            } catch (error) {
                return false;
            }
        }

        function safeDynamicInlineScript(source, text) {
            var id = (source.id || '').trim();
            var type = scriptType(source);
            if ((type && type !== 'text/javascript' && type !== 'application/javascript')
                || !Object.prototype.hasOwnProperty.call(safeDynamicInlineScript.sources, id)
            ) return false;

            var escapedId = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var canonical = String(text || '')
                .replace(new RegExp('(?:^|\\n)//# sourceURL=' + escapedId + '\\s*$'), '')
                .trim();
            return canonical === safeDynamicInlineScript.sources[id];
        }

        safeDynamicInlineScript.sources = {
            'jet-engine-data-stores-js-before': "window.JetEngineStores = window.JetEngineStores || {};\n\t\t\twindow.JetEngineStores['local-storage'] = {\n\t\t\t\taddToStore: function( storeSlug, postID, maxSize, isOnViewStore ) {\n\t\t\t\t\t\n\t\tvar store = window.localStorage.getItem( 'jet_engine_store_' + storeSlug );\n\t\tisOnViewStore = isOnViewStore || false;\n\n\t\tif ( store ) {\n\t\t\tstore = store.split( ',' );\n\t\t} else {\n\t\t\tstore = [];\n\t\t}\n\n\t\tpostID = '' + postID;\n\n\t\tmaxSize = parseInt( maxSize, 10 );\n\n\t\tif ( 0 <= store.indexOf( postID ) ) {\n\t\t\treturn store.length;\n\t\t}\n\n\t\tif ( 0 < maxSize && store.length >= maxSize ) {\n\t\t\t\n\t\t\tif ( isOnViewStore ) {\n\t\t\t\tstore.splice( 0, 1 );\n\t\t\t} else {\n\t\t\t\talert( 'You can`t add more posts' );\n\t\t\t\treturn false;\n\t\t\t}\n\t\t\n\t\t}\n\n\t\tstore.push( postID );\n\n\t\twindow.localStorage.setItem( 'jet_engine_store_' + storeSlug, store.join( ',' ) );\n\n\t\treturn store.length;\n\n\t\t\n\t\t\t\t},\n\t\t\t\tremove: function( storeSlug, postID ) {\n\t\t\t\t\t\n\t\tvar store = window.localStorage.getItem( 'jet_engine_store_' + storeSlug ),\n\t\t\tindex;\n\n\t\tif ( store ) {\n\t\t\tstore = store.split( ',' );\n\t\t} else {\n\t\t\tstore = [];\n\t\t}\n\n\t\tpostID = '' + postID;\n\n\t\tindex = store.indexOf( postID );\n\n\t\tif ( 0 > index ) {\n\t\t\treturn store.length;\n\t\t} else {\n\t\t\tstore.splice( index, 1 );\n\t\t}\n\n\t\twindow.localStorage.setItem( 'jet_engine_store_' + storeSlug, store.join( ',' ) );\n\n\t\treturn store.length;\n\n\t\t\n\t\t\t\t},\n\t\t\t\tinStore: function( storeSlug, postID ) {\n\t\t\t\t\t\n\t\tvar store = window.localStorage.getItem( 'jet_engine_store_' + storeSlug ),\n\t\t\tindex;\n\n\t\tpostID = '' + postID;\n\n\t\tif ( store ) {\n\t\t\tstore = store.split( ',' );\n\t\t} else {\n\t\t\tstore = [];\n\t\t}\n\n\t\tindex = store.indexOf( postID );\n\n\t\treturn ( 0 <= index );\n\n\t\t\n\t\t\t\t},\n\t\t\t\tgetStore: function( storeSlug ) {\n\t\t\t\t\t\n\t\tvar store = window.localStorage.getItem( 'jet_engine_store_' + storeSlug ),\n\t\t\tindex;\n\n\t\tif ( store ) {\n\t\t\tstore = store.split( ',' );\n\t\t} else {\n\t\t\tstore = [];\n\t\t}\n\n\t\treturn store;\n\n\t\t\n\t\t\t\t},\n\t\t\t};",
            'jet-engine-frontend-js-before': "jQuery( window ).on( 'jet-engine/frontend/loaded', function() {\n\t\t\t\twindow.JetPlugins.hooks.addFilter(\n\t\t\t\t\t'jet-popup.show-popup.data',\n\t\t\t\t\t'JetEngine.popupData',\n\t\t\t\t\tfunction( popupData, popup, triggeredBy ) {\n\n\t\t\t\t\t\tif ( ! triggeredBy ) {\n\t\t\t\t\t\t\treturn popupData;\n\t\t\t\t\t\t}\n\n\t\t\t\t\t\tif ( ! triggeredBy.data( 'popupIsJetEngine' ) ) {\n\t\t\t\t\t\t\treturn popupData;\n\t\t\t\t\t\t}\n\n\t\t\t\t\t\tvar wrapper = triggeredBy.closest( '.jet-listing-grid__items' );\n\n\t\t\t\t\t\tif ( wrapper.length && wrapper.data( 'cctSlug' ) ) {\n\t\t\t\t\t\t\tpopupData['cctSlug'] = wrapper.data( 'cctSlug' );\n\t\t\t\t\t\t}\n\n\t\t\t\t\t\treturn popupData;\n\t\t\t\t\t}\n\t\t\t\t);\n\t\t\t} );"
        };

        function ignorableSelfRemovingScript(source) {
            if (!source || !source.src) return false;
            try {
                var url = new URL(source.src, window.location.href);
                return url.origin === window.location.origin
                    && /^\/cdn-cgi\/scripts\/[a-z0-9_-]+\/cloudflare-static\/email-decode(?:\.min)?\.js$/i.test(url.pathname);
            } catch (error) {
                return false;
            }
        }

        function parseWordfenceHumanLogger(source, text) {
            var type = scriptType(source);
            if (source.id || (type && type !== 'text/javascript' && type !== 'application/javascript')) return '';
            var match = /\}\)\('([^']+)'\);$/.exec(text);
            if (!match) return '';

            var url;
            try { url = new URL(match[1], window.location.href); } catch (error) { return ''; }
            var keys = [];
            url.searchParams.forEach(function (value, key) { keys.push(key); });
            if (!/^https?:$/.test(url.protocol)
                || url.origin !== window.location.origin
                || url.username
                || url.password
                || url.pathname !== '/'
                || url.hash
                || keys.length !== 2
                || url.searchParams.getAll('wordfence_lh').length !== 1
                || url.searchParams.get('wordfence_lh') !== '1'
                || url.searchParams.getAll('hid').length !== 1
                || !/^[a-f0-9]{32}$/i.test(url.searchParams.get('hid') || '')
            ) return '';

            var canonical = text.slice(0, match.index) + "})('__WORDFENCE_URL__');";
            return canonical === parseWordfenceHumanLogger.template ? url.href : '';
        }

        parseWordfenceHumanLogger.template = "(function(url){\n\tif(/(?:Chrome\\/26\\.0\\.1410\\.63 Safari\\/537\\.31|WordfenceTestMonBot)/.test(navigator.userAgent)){ return; }\n\tvar addEvent = function(evt, handler) {\n\t\tif (window.addEventListener) {\n\t\t\tdocument.addEventListener(evt, handler, false);\n\t\t} else if (window.attachEvent) {\n\t\t\tdocument.attachEvent('on' + evt, handler);\n\t\t}\n\t};\n\tvar removeEvent = function(evt, handler) {\n\t\tif (window.removeEventListener) {\n\t\t\tdocument.removeEventListener(evt, handler, false);\n\t\t} else if (window.detachEvent) {\n\t\t\tdocument.detachEvent('on' + evt, handler);\n\t\t}\n\t};\n\tvar evts = 'contextmenu dblclick drag dragend dragenter dragleave dragover dragstart drop keydown keypress keyup mousedown mousemove mouseout mouseover mouseup mousewheel scroll'.split(' ');\n\tvar logHuman = function() {\n\t\tif (window.wfLogHumanRan) { return; }\n\t\twindow.wfLogHumanRan = true;\n\t\tvar wfscr = document.createElement('script');\n\t\twfscr.type = 'text/javascript';\n\t\twfscr.async = true;\n\t\twfscr.src = url + '&r=' + Math.random();\n\t\t(document.getElementsByTagName('head')[0]||document.getElementsByTagName('body')[0]).appendChild(wfscr);\n\t\tfor (var i = 0; i < evts.length; i++) {\n\t\t\tremoveEvent(evts[i], logHuman);\n\t\t}\n\t};\n\tfor (var i = 0; i < evts.length; i++) {\n\t\taddEvent(evts[i], logHuman);\n\t}\n})('__WORDFENCE_URL__');";

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

        function unmodifiedPrimaryClick(event) {
            return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
        }

        function interactiveTrigger(hit) {
            if (!hit) return hit;
            if (hit.matches('a[href],button,[data-smp-player-trigger],[data-smp-watch-trigger]')) return hit;
            return hit.querySelector('a[href],button,[data-smp-player-trigger],[data-smp-watch-trigger]') || hit;
        }

        function episodeContainer(trigger) {
            return trigger.closest('.e-loop-item,[data-smp-episode],article')
                || trigger.closest('.elementor-widget-container')
                || trigger.parentElement;
        }

        function attributeFrom(nodes, name) {
            for (var index = 0; index < nodes.length; index += 1) {
                var node = nodes[index];
                if (!node || !node.getAttribute) continue;
                var value = (node.getAttribute(name) || '').trim();
                if (value) return value;
            }
            return '';
        }

        function isWatchTrigger(trigger) {
            return !!(trigger && (trigger.matches(watchTriggerSelector) || trigger.closest('.smp-watch-button,.mpp-card-watch')));
        }

        function sameTrack(left, right) {
            if (!left || !right) return false;
            if (left.postId && right.postId && String(left.postId) === String(right.postId)) return true;
            if (left.src && right.src && comparableUrl(left.src) === comparableUrl(right.src)) return true;
            return !!(left.videoId && right.videoId && left.videoId === right.videoId);
        }

        function youtubeVideoId(value) {
            value = String(value || '').trim();
            var directMatch = value.match(/^([A-Za-z0-9_-]{11})(?:[?&].*)?$/);
            if (directMatch) return directMatch[1];
            var url;
            try { url = new URL(value, window.location.href); } catch (error) { return ''; }
            var host = url.hostname.toLowerCase().replace(/^www\./, '');
            var candidate = '';
            if (host === 'youtu.be') candidate = url.pathname.split('/').filter(Boolean)[0] || '';
            else if (['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'].indexOf(host) !== -1) {
                candidate = url.searchParams.get('v') || '';
                if (!candidate) {
                    var match = url.pathname.match(/^\/(?:embed|shorts|live)\/([A-Za-z0-9_-]{11})(?:\/|$)/);
                    candidate = match ? match[1] : '';
                }
            }
            return /^[A-Za-z0-9_-]{11}$/.test(candidate) ? candidate : '';
        }

        function trustedYouTubeOrigin(origin) {
            return origin === 'https://www.youtube.com'
                || origin === 'https://www.youtube-nocookie.com'
                || origin === 'https://youtube.com'
                || origin === 'https://youtube-nocookie.com';
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
