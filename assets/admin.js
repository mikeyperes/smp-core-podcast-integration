(function () {
    'use strict';

    function request(data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (key) {
            body.set(key, data[key]);
        });
        return fetch(window.smpPodcastAdmin.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload || !payload.success) {
                    var message = payload && payload.data && payload.data.message ? payload.data.message : 'Request failed.';
                    throw new Error(message);
                }
                return payload.data || {};
            });
        });
    }

    function buttonStart(button, label) {
        button.disabled = true;
        if (window.HexaWpCoreDynamicButton) {
            window.HexaWpCoreDynamicButton.start(button, label);
        }
    }

    function buttonDone(button, message, passed) {
        button.disabled = false;
        if (!window.HexaWpCoreDynamicButton) return;
        if (passed) window.HexaWpCoreDynamicButton.success(button, message);
        else window.HexaWpCoreDynamicButton.error(button, message, false);
    }

    function text(value) {
        if (value === null || typeof value === 'undefined') return '';
        if (typeof value === 'object') return JSON.stringify(value);
        return String(value);
    }

    function appendRow(log, report) {
        var row = document.createElement('div');
        row.className = 'smp-podcast-operation-row ' + (report.changed ? 'is-changed' : 'is-skipped');

        var icon = document.createElement('span');
        icon.className = 'dashicons ' + (report.changed ? 'dashicons-yes-alt' : 'dashicons-minus');
        icon.setAttribute('aria-hidden', 'true');

        var main = document.createElement('div');
        var title = document.createElement('strong');
        title.textContent = report.title || ('Post #' + report.post_id);
        var message = document.createElement('span');
        message.textContent = report.message || '';
        var comparison = document.createElement('code');
        comparison.textContent = 'Before: ' + text(report.before) + ' | After: ' + text(report.after);
        main.appendChild(title);
        main.appendChild(message);
        main.appendChild(comparison);

        var link = document.createElement('a');
        link.className = 'hpc-button secondary hpc-external';
        link.href = report.edit_url || '#';
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = 'Edit';

        row.appendChild(icon);
        row.appendChild(main);
        row.appendChild(link);
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
    }

    function runOperation(button) {
        var root = button.closest('[data-smp-operation]');
        if (!root) return;
        var operation = root.getAttribute('data-smp-operation') || '';
        var status = root.querySelector('[data-smp-operation-status]');
        var progressRoot = root.querySelector('.smp-podcast-operation-progress');
        var progress = progressRoot.querySelector('progress');
        var progressLabel = progressRoot.querySelector('span');
        var log = root.querySelector('.smp-podcast-operation-log');

        log.innerHTML = '';
        log.hidden = false;
        progressRoot.hidden = false;
        status.textContent = 'Scanning podcast content...';
        buttonStart(button, 'Scanning...');

        request({ action: 'smp_podcast_operation_index', nonce: window.smpPodcastAdmin.nonce, operation: operation })
            .then(function (index) {
                var ids = Array.isArray(index.ids) ? index.ids.slice() : [];
                var total = ids.length;
                var completed = 0;
                progress.max = Math.max(total, 1);
                progress.value = 0;
                progressLabel.textContent = '0 / ' + total;
                status.textContent = index.message || (total + ' item(s) queued.');

                function next() {
                    if (!ids.length) {
                        status.textContent = 'Completed ' + completed + ' item(s).';
                        buttonDone(button, 'Completed', true);
                        return;
                    }
                    var id = ids.shift();
                    request({ action: 'smp_podcast_operation_item', nonce: window.smpPodcastAdmin.nonce, operation: operation, post_id: id })
                        .then(function (report) {
                            appendRow(log, report);
                        })
                        .catch(function (error) {
                            appendRow(log, { post_id: id, title: 'Post #' + id, changed: false, message: error.message, before: '', after: '' });
                        })
                        .finally(function () {
                            completed += 1;
                            progress.value = completed;
                            progressLabel.textContent = completed + ' / ' + total;
                            status.textContent = 'Processing ' + completed + ' of ' + total + '...';
                            next();
                        });
                }
                next();
            })
            .catch(function (error) {
                status.textContent = error.message;
                buttonDone(button, 'Failed', false);
            });
    }

    function saveModel(button) {
        var select = document.querySelector('[data-smp-content-model]');
        var status = document.querySelector('[data-smp-model-status]');
        if (!select || !status) return;
        buttonStart(button, 'Saving...');
        status.textContent = 'Saving content model...';
        request({ action: 'smp_podcast_save_content_model', nonce: window.smpPodcastAdmin.nonce, model: select.value })
            .then(function (report) {
                status.textContent = report.message || 'Saved.';
                buttonDone(button, 'Saved', true);
                if (report.changed && report.reload_url) window.location.assign(report.reload_url);
            })
            .catch(function (error) {
                status.textContent = error.message;
                buttonDone(button, 'Failed', false);
            });
    }

    function savePlayback(button) {
        var form = button.closest('[data-smp-playback-form]');
        if (!form) return;
        var status = form.querySelector('[data-smp-playback-status]');
        var data = {
            action: 'smp_podcast_save_playback_settings',
            nonce: window.smpPodcastAdmin.nonce
        };
        var checkboxNames = [
            'enabled', 'ajax_navigation', 'show_cover', 'show_skip',
            'show_rate', 'show_volume', 'show_download', 'show_close', 'media_session',
            'remember_preferences'
        ];
        checkboxNames.forEach(function (name) {
            var field = form.querySelector('[name="' + name + '"]');
            data[name] = field && field.checked ? '1' : '0';
        });
        ['content_selector', 'excluded_paths', 'timeout_ms', 'transition_ms', 'skip_back', 'skip_forward'].forEach(function (name) {
            var field = form.querySelector('[name="' + name + '"]');
            data[name] = field ? field.value : '';
        });

        buttonStart(button, 'Saving...');
        if (status) status.textContent = 'Saving player settings...';
        request(data)
            .then(function (report) {
                if (status) status.textContent = report.message || 'Saved.';
                buttonDone(button, report.changed ? 'Saved' : 'Unchanged', true);
            })
            .catch(function (error) {
                if (status) status.textContent = error.message;
                buttonDone(button, 'Failed', false);
            });
    }

    document.addEventListener('click', function (event) {
        var operation = event.target.closest('.smp-podcast-operation-run');
        if (operation) {
            event.preventDefault();
            runOperation(operation);
            return;
        }
        var model = event.target.closest('.smp-podcast-save-model');
        if (model) {
            event.preventDefault();
            saveModel(model);
            return;
        }
        var playback = event.target.closest('.smp-podcast-save-playback');
        if (playback) {
            event.preventDefault();
            savePlayback(playback);
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-smp-playback-form]');
        if (!form) return;
        event.preventDefault();
        var button = form.querySelector('.smp-podcast-save-playback');
        if (button && !button.disabled) savePlayback(button);
    });

    document.addEventListener('hexa-core-host-tab-loaded', function (event) {
        var panel = event.detail && event.detail.panel ? event.detail.panel : null;
        if (panel && window.acf && typeof window.acf.doAction === 'function') {
            window.acf.doAction('append', panel);
        }
    });
})();
