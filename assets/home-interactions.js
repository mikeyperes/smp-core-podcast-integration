(function (window, document) {
    'use strict';

    var apiKey = '__mppHomeInteractions23128';
    var version = '3.2.0';
    var existing = window[apiKey];
    if (existing && existing.version === version && typeof existing.refresh === 'function') {
        existing.refresh();
        return;
    }
    if (existing && typeof existing.destroy === 'function') {
        existing.destroy();
    }

    var rootSelector = '[data-elementor-id="23095"]';
    var state = { topic: 'all', query: '' };
    var topicMap = {
        '158': ['education', 'entrepreneurship', 'technology'],
        '157': ['cybersecurity', 'entrepreneurship', 'financial-technology'],
        '156': ['astrophysics', 'education', 'science'],
        '155': ['cybersecurity', 'entrepreneurship', 'technology'],
        '154': ['entrepreneurship', 'financial-technology'],
        '153': ['science', 'technology']
    };
    var observer = null;
    var observedRoot = null;
    var timer = 0;
    var cueFrame = 0;
    var api = null;

    function pageRoot() {
        return document.querySelector(rootSelector);
    }

    function cards(root) {
        if (!root) return [];
        return Array.prototype.slice.call(root.querySelectorAll('[data-id="c04f006"] .e-loop-item'));
    }

    function episodeNumber(card) {
        var label = card.querySelector('.mpp-episode-number');
        var match = label ? label.textContent.match(/(\d{1,4})/) : null;
        return match ? match[1] : '';
    }

    function hydrateCard(card) {
        var number = episodeNumber(card);
        if (!number) return;
        card.id = 'ep-' + number;
        card.dataset.episodeNumber = number;
        card.dataset.topics = (topicMap[number] || []).join(' ');
        card.querySelectorAll('.mpp-episode-guest a').forEach(function (link) {
            var name = link.querySelector('.mpp-guest-n');
            if (name) link.setAttribute('aria-label', 'View the verified profile for ' + name.textContent.trim());
        });
        card.querySelectorAll('.elementor-widget-button a[target="_blank"]').forEach(function (link) {
            link.setAttribute('rel', 'noopener noreferrer');
        });
    }

    function searchInput(root) {
        var form = root && root.querySelector('.mpp-episode-search form');
        if (!form) return null;
        return form.querySelector('input.elementor-field:not([type="hidden"]), input[type="search"]:not([type="hidden"]), input[type="text"]:not([type="hidden"])');
    }

    function setStatus(root, visible, total) {
        var status = root && root.querySelector('.mpp-episode-status .elementor-heading-title');
        if (!status) return;
        var next = !state.query && state.topic === 'all' ? '' : visible + ' of ' + total + ' latest conversations shown.';
        var widget = status.closest('.mpp-episode-status');
        if (widget) widget.classList.toggle('is-populated', next !== '');
        if (status.textContent !== next) status.textContent = next;
    }

    function applyFilters(root) {
        if (!root || root !== pageRoot()) return;
        var items = cards(root);
        var query = state.query.toLowerCase().trim();
        var visible = 0;
        items.forEach(function (card) {
            hydrateCard(card);
            var topics = (card.dataset.topics || '').split(/\s+/).filter(Boolean);
            var topicMatch = state.topic === 'all' || topics.indexOf(state.topic) !== -1;
            var haystack = (card.textContent + ' ' + topics.join(' ')).toLowerCase();
            var queryMatch = !query || haystack.indexOf(query) !== -1;
            var show = topicMatch && queryMatch;
            card.hidden = !show;
            card.setAttribute('aria-hidden', show ? 'false' : 'true');
            if (show) visible += 1;
        });
        setStatus(root, visible, items.length);
    }

    function prepareControls(root) {
        root.querySelectorAll('.mpp-topic-chip .elementor-button').forEach(function (chip) {
            var topic = chip.getAttribute('data-topic') || 'all';
            chip.setAttribute('role', 'button');
            chip.setAttribute('aria-pressed', topic === state.topic ? 'true' : 'false');
        });
        var input = searchInput(root);
        if (input) {
            input.type = 'search';
            input.setAttribute('autocomplete', 'off');
        }
    }

    function repairHeroRuler(root) {
        root.querySelectorAll('a[href="#ep-153"],a[href="#ep-154"],a[href="#ep-155"],a[href="#ep-156"],a[href="#ep-157"],a[href="#ep-158"]').forEach(function (link) {
            var number = (link.getAttribute('href') || '').replace('#ep-', '');
            link.setAttribute('aria-label', 'Episode ' + number + ' — jump to conversation');
            link.removeAttribute('a');
        });
    }

    function scrollCue(root) {
        return root && root.querySelector('.mpp-scroll-cue, [data-id="b01f027"]');
    }

    function updateScrollCue() {
        var root = pageRoot();
        var cue = scrollCue(root);
        if (!cue) return;
        var threshold = Math.max(80, Math.min(220, window.innerHeight * 0.18));
        var hidden = window.scrollY > threshold || document.body.classList.contains('smp-podcast-player-visible');
        cue.classList.toggle('is-hidden', hidden);
        cue.setAttribute('aria-hidden', hidden ? 'true' : 'false');
        var link = cue.querySelector('a[href="#listen"]');
        if (link) {
            if (hidden) link.setAttribute('tabindex', '-1');
            else link.removeAttribute('tabindex');
        }
    }

    function queueScrollCue() {
        if (cueFrame) return;
        cueFrame = window.requestAnimationFrame(function () {
            cueFrame = 0;
            updateScrollCue();
        });
    }

    function scan(root) {
        if (!root || root !== pageRoot()) return;
        cards(root).forEach(hydrateCard);
        prepareControls(root);
        repairHeroRuler(root);
        applyFilters(root);
        updateScrollCue();
    }

    function disconnectObserver() {
        window.clearTimeout(timer);
        timer = 0;
        if (observer) observer.disconnect();
        observer = null;
        observedRoot = null;
    }

    function refresh() {
        var nextRoot = pageRoot();
        if (nextRoot === observedRoot) {
            scan(nextRoot);
            return;
        }

        disconnectObserver();
        state.topic = 'all';
        state.query = '';
        observedRoot = nextRoot;
        if (!observedRoot) return;

        var input = searchInput(observedRoot);
        if (input) state.query = input.value || '';
        scan(observedRoot);

        if (!window.MutationObserver) return;
        observer = new MutationObserver(function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                if (observedRoot !== pageRoot()) refresh();
                else scan(observedRoot);
            }, 40);
        });
        observer.observe(observedRoot, { childList: true, subtree: true });
    }

    function eventElement(event) {
        return event.target instanceof Element ? event.target : null;
    }

    function activateTopic(root, chip) {
        state.topic = chip.getAttribute('data-topic') || 'all';
        root.querySelectorAll('.mpp-topic-chip .elementor-button').forEach(function (other) {
            other.setAttribute('aria-pressed', (other.getAttribute('data-topic') || 'all') === state.topic ? 'true' : 'false');
        });
        applyFilters(root);
    }

    function handleClick(event) {
        var target = eventElement(event);
        if (!target) return;

        var backToTop = target.closest('[data-elementor-type="footer"] .mpp-back-to-top a');
        if (backToTop) {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            return;
        }

        var root = pageRoot();
        if (!root || !root.contains(target)) return;
        var chip = target.closest('.mpp-topic-chip .elementor-button');
        if (!chip || !root.contains(chip)) return;
        event.preventDefault();
        activateTopic(root, chip);
    }

    function handleKeydown(event) {
        if (event.key !== ' ' && event.key !== 'Enter') return;
        var target = eventElement(event);
        var root = pageRoot();
        var chip = target && target.closest('.mpp-topic-chip .elementor-button');
        if (!root || !chip || !root.contains(chip)) return;
        event.preventDefault();
        activateTopic(root, chip);
    }

    function handleInput(event) {
        var target = eventElement(event);
        var root = pageRoot();
        var input = searchInput(root);
        if (!root || !target || target !== input) return;
        state.query = input.value;
        applyFilters(root);
    }

    function handleSubmit(event) {
        var target = eventElement(event);
        var root = pageRoot();
        if (!root || !target || !target.matches('.mpp-episode-search form') || !root.contains(target)) return;
        event.preventDefault();
    }

    function destroy() {
        disconnectObserver();
        if (cueFrame) window.cancelAnimationFrame(cueFrame);
        cueFrame = 0;
        window.removeEventListener('scroll', queueScrollCue);
        window.removeEventListener('resize', queueScrollCue);
        document.removeEventListener('click', handleClick);
        document.removeEventListener('keydown', handleKeydown);
        document.removeEventListener('input', handleInput);
        document.removeEventListener('submit', handleSubmit, true);
        document.removeEventListener('smp:content-ready', refresh);
        document.removeEventListener('smp:podcast-track-selected', updateScrollCue);
        document.removeEventListener('smp:podcast-player-closed', updateScrollCue);
        document.removeEventListener('DOMContentLoaded', refresh);
        if (window[apiKey] === api) delete window[apiKey];
    }

    api = {
        version: version,
        refresh: refresh,
        destroy: destroy,
        isActive: function () { return !!observedRoot && observedRoot === pageRoot(); }
    };
    window[apiKey] = api;

    window.addEventListener('scroll', queueScrollCue, { passive: true });
    window.addEventListener('resize', queueScrollCue, { passive: true });
    document.addEventListener('click', handleClick);
    document.addEventListener('keydown', handleKeydown);
    document.addEventListener('input', handleInput);
    document.addEventListener('submit', handleSubmit, true);
    document.addEventListener('smp:content-ready', refresh);
    document.addEventListener('smp:podcast-track-selected', updateScrollCue);
    document.addEventListener('smp:podcast-player-closed', updateScrollCue);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', refresh, { once: true });
    else refresh();
}(window, document));
