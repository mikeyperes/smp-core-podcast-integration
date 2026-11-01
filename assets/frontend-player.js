(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    }

    if (window.smpPodcastPlayerBooted) return;
    window.smpPodcastPlayerBooted = true;
    ready(initializePlayer);
    document.addEventListener('hws:page-changed', function () {
        initializePlayer();
        if (window.smpPodcastPlayerRefresh) window.smpPodcastPlayerRefresh();
    });

    function initializePlayer() {
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
            legacyObserver: null,
            legacySources: new WeakMap()
        };
        var strings = config.strings || {};
        var triggerSelector = '[data-smp-player-trigger],.ep-listen[data-mp3],#ap-toggle';
        var watchTriggerSelector = '[data-smp-watch-trigger],.smp-watch-button,.mpp-card-watch a[href*="youtu"]';
        var allTriggerSelector = triggerSelector + ',' + watchTriggerSelector;

        restorePreferences();
        enforcePlayerSingleton(document);
        observeLegacyPlayers();
        bindPlayer();
        configureMediaSession();
        updatePlayerState();
        window.smpPodcastPlayerRefresh = function () {
            enforcePlayerSingleton(document);
            neutralizeLegacyAudio(document);
            document.body.classList.toggle('smp-podcast-player-visible', !player.hidden);
            document.body.classList.toggle('smp-podcast-player-video-visible', !player.hidden && state.mode === 'video');
            updatePlayerState();
        };
        function beforePageChange() {
            if (player.hasAttribute('data-turbo-permanent')) return;
            closePlayer();
            document.removeEventListener('click', handleDocumentClick, true);
            window.removeEventListener('message', handleVideoMessage);
            document.removeEventListener('hws:page-will-change', beforePageChange);
            if (state.legacyObserver) state.legacyObserver.disconnect();
            window.smpPodcastPlayerRefresh = null;
        }
        document.addEventListener('hws:page-will-change', beforePageChange);

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
            });
            audio.addEventListener('ended', function () {
                state.playbackActivated = false;
                if (state.mode !== 'audio') return;
            });
            ['loadedmetadata', 'durationchange', 'timeupdate', 'progress'].forEach(function (eventName) {
                audio.addEventListener(eventName, updateTimeline);
            });
            audio.addEventListener('error', function () {
                state.playbackActivated = false;
                if (state.mode !== 'audio') return;
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
            } else if (code === 2 || code === 5) {
                state.videoPlaying = false;
                if (state.mode === 'video') {
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
    }
})();
