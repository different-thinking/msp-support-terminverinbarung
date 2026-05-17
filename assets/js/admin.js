/**
 * Admin-Interface JavaScript
 * Tab-Navigation, AJAX-Speichern, Kalender-Management, Formularfelder-Editor.
 */
(function () {
    'use strict';

    const API = 'api.php';
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // ==================== Tab Navigation ====================
    document.querySelectorAll('.nav-item[data-tab]').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const tab = link.dataset.tab;

            document.querySelectorAll('.nav-item[data-tab]').forEach(l => l.classList.remove('active'));
            link.classList.add('active');

            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.getElementById('tab-' + tab).classList.add('active');

            history.replaceState(null, '', '#' + tab);
        });
    });

    // Inline nav links (inside content, e.g. guide pages)
    document.addEventListener('click', function(e) {
        const link = e.target.closest('.nav-link-inline[data-tab]');
        if (link) {
            e.preventDefault();
            const tab = link.dataset.tab;
            const navItem = document.querySelector(`.nav-item[data-tab="${tab}"]`);
            if (navItem) navItem.click();
            window.scrollTo(0, 0);
        }
    });

    // Tab aus URL-Hash laden
    const hash = location.hash.slice(1);
    if (hash) {
        const navItem = document.querySelector(`.nav-item[data-tab="${hash}"]`);
        if (navItem) navItem.click();
    }

    // ==================== Toast ====================
    function showToast(message, type) {
        type = type || 'success';
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ==================== API Helper ====================
    function apiPost(action, data) {
        return fetch(API + '?action=' + action, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify(data),
        }).then(r => r.json());
    }

    function setLoading(btn, loading) {
        if (loading) {
            btn.dataset.origText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Speichern...';
        } else {
            btn.disabled = false;
            btn.textContent = btn.dataset.origText || 'Speichern';
        }
    }

    // ==================== Form: Allgemein ====================
    document.getElementById('form-general').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        setLoading(btn, true);

        const data = {
            name: document.getElementById('app-name').value,
            url: document.getElementById('app-url').value,
            timezone: document.getElementById('app-timezone').value,
            locale: 'de',
            appointment_duration_minutes: document.getElementById('app-duration').value,
            booking_horizon_days: document.getElementById('app-horizon').value,
            min_notice_hours: document.getElementById('app-notice').value,
            slot_interval_minutes: document.getElementById('app-interval').value,
            buffer_minutes: document.getElementById('app-buffer').value,
        };

        apiPost('save-app', data).then(res => {
            setLoading(btn, false);
            if (res.success) showToast('Einstellungen gespeichert');
            else showToast(res.error || 'Fehler', 'error');
        }).catch(() => { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
    });

    // ==================== Form: Organisator ====================
    document.getElementById('form-organizer').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        setLoading(btn, true);

        apiPost('save-organizer', {
            name: document.getElementById('org-name').value,
            email: document.getElementById('org-email').value,
        }).then(res => {
            setLoading(btn, false);
            if (res.success) showToast('Organisator gespeichert');
            else showToast(res.error || 'Fehler', 'error');
        }).catch(() => { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
    });

    // ==================== Form: Arbeitszeiten ====================
    // Toggle: Zeitfelder aktivieren/deaktivieren
    document.querySelectorAll('.hours-enabled').forEach(cb => {
        cb.addEventListener('change', function () {
            const day = this.dataset.day;
            const timesDiv = this.closest('.hours-row').querySelector('.hours-times');
            const inputs = timesDiv.querySelectorAll('input');
            if (this.checked) {
                timesDiv.classList.remove('disabled');
                inputs.forEach(i => i.disabled = false);
            } else {
                timesDiv.classList.add('disabled');
                inputs.forEach(i => i.disabled = true);
            }
        });
    });

    // Toggle: Pause aktivieren/deaktivieren
    const breakCheckbox = document.getElementById('break-enabled');
    if (breakCheckbox) {
        breakCheckbox.addEventListener('change', function () {
            const timesDiv = document.getElementById('break-times');
            const inputs = timesDiv.querySelectorAll('input');
            if (this.checked) {
                timesDiv.classList.remove('disabled');
                inputs.forEach(i => i.disabled = false);
            } else {
                timesDiv.classList.add('disabled');
                inputs.forEach(i => i.disabled = true);
            }
        });
    }

    document.getElementById('form-hours').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        setLoading(btn, true);

        const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        const data = {};
        days.forEach(day => {
            const enabled = document.querySelector(`.hours-enabled[data-day="${day}"]`).checked;
            const start = document.querySelector(`.hours-start[data-day="${day}"]`).value;
            const end = document.querySelector(`.hours-end[data-day="${day}"]`).value;
            data[day] = { enabled: enabled, start: start, end: end };
        });

        // Pausenzeit mitsenden
        data.break_time = {
            enabled: document.getElementById('break-enabled').checked,
            start: document.getElementById('break-start').value,
            end: document.getElementById('break-end').value,
        };

        apiPost('save-working-hours', data).then(res => {
            setLoading(btn, false);
            if (res.success) showToast('Arbeitszeiten gespeichert');
            else showToast(res.error || 'Fehler', 'error');
        }).catch(() => { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
    });

    // ==================== Form: Teams ====================
    document.getElementById('form-teams').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        setLoading(btn, true);

        apiPost('save-teams', {
            enabled: document.getElementById('teams-enabled').checked,
            source_id: document.getElementById('teams-source').value,
        }).then(res => {
            setLoading(btn, false);
            if (res.success) showToast('Teams-Einstellungen gespeichert');
            else showToast(res.error || 'Fehler', 'error');
        }).catch(() => { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
    });

    // ==================== Form: Buchungsformular ====================

    // Feld hinzufügen
    document.getElementById('btn-add-field').addEventListener('click', function () {
        const list = document.getElementById('custom-fields-list');
        const idx = list.children.length;
        const row = document.createElement('div');
        row.className = 'custom-field-row';
        row.dataset.index = idx;
        row.innerHTML = `
            <div class="field-drag-handle" title="Ziehen zum Sortieren">&#9776;</div>
            <div class="field-config">
                <div class="form-row form-row-4">
                    <div class="form-group">
                        <label>Feldname</label>
                        <input type="text" class="cf-name" placeholder="feldname" pattern="[a-z0-9_]+">
                    </div>
                    <div class="form-group">
                        <label>Bezeichnung</label>
                        <input type="text" class="cf-label" placeholder="Anzeigename">
                    </div>
                    <div class="form-group">
                        <label>Typ</label>
                        <select class="cf-type">
                            <option value="text">Text</option>
                            <option value="email">E-Mail</option>
                            <option value="tel">Telefon</option>
                            <option value="url">URL</option>
                            <option value="number">Zahl</option>
                            <option value="textarea">Textbereich</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Platzhalter</label>
                        <input type="text" class="cf-placeholder">
                    </div>
                </div>
                <label class="checkbox-label">
                    <input type="checkbox" class="cf-required">
                    <span>Pflichtfeld</span>
                </label>
            </div>
            <button type="button" class="btn-remove-field" title="Entfernen">&times;</button>
        `;
        list.appendChild(row);
        bindRemoveFieldButtons();
    });

    function bindRemoveFieldButtons() {
        document.querySelectorAll('.btn-remove-field').forEach(btn => {
            btn.onclick = function () {
                this.closest('.custom-field-row').remove();
            };
        });
    }
    bindRemoveFieldButtons();

    // Drag & Drop für Felder
    (function initDragDrop() {
        const list = document.getElementById('custom-fields-list');
        let dragItem = null;

        list.addEventListener('dragstart', function (e) {
            dragItem = e.target.closest('.custom-field-row');
            if (dragItem) {
                dragItem.style.opacity = '0.5';
                e.dataTransfer.effectAllowed = 'move';
            }
        });

        list.addEventListener('dragend', function () {
            if (dragItem) dragItem.style.opacity = '1';
            dragItem = null;
        });

        list.addEventListener('dragover', function (e) {
            e.preventDefault();
            const target = e.target.closest('.custom-field-row');
            if (target && target !== dragItem) {
                const rect = target.getBoundingClientRect();
                const mid = rect.top + rect.height / 2;
                if (e.clientY < mid) {
                    list.insertBefore(dragItem, target);
                } else {
                    list.insertBefore(dragItem, target.nextSibling);
                }
            }
        });

        // Make rows draggable via handle
        const observer = new MutationObserver(function () {
            list.querySelectorAll('.custom-field-row').forEach(row => {
                row.draggable = true;
            });
        });
        observer.observe(list, { childList: true });
        list.querySelectorAll('.custom-field-row').forEach(row => {
            row.draggable = true;
        });
    })();

    document.getElementById('form-booking-fields').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        setLoading(btn, true);

        const fields = [];
        document.querySelectorAll('.custom-field-row').forEach(row => {
            const name = row.querySelector('.cf-name').value.trim();
            const label = row.querySelector('.cf-label').value.trim();
            if (name && label) {
                fields.push({
                    name: name,
                    label: label,
                    type: row.querySelector('.cf-type').value,
                    placeholder: row.querySelector('.cf-placeholder').value.trim(),
                    required: row.querySelector('.cf-required').checked,
                });
            }
        });

        const data = {
            additional_fields: fields,
            allow_additional_attendees: document.getElementById('allow-attendees').checked,
            max_additional_attendees: document.getElementById('max-attendees').value,
        };

        apiPost('save-booking-form', data).then(res => {
            setLoading(btn, false);
            if (res.success) showToast('Buchungsformular gespeichert');
            else showToast(res.error || 'Fehler', 'error');
        }).catch(() => { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
    });

    // ==================== Kalender-Quellen ====================

    // Modal öffnen/schließen
    function openSourceModal(source) {
        const modal = document.getElementById('modal-source');
        const title = document.getElementById('modal-source-title');
        const form = document.getElementById('form-source');

        form.reset();

        if (source) {
            title.textContent = 'Kalender-Quelle bearbeiten';
            document.getElementById('source-id').value = source.id || '';
            document.getElementById('source-type').value = source.type || 'microsoft';
            document.getElementById('source-label').value = source.label || '';
            document.getElementById('source-client-id').value = source.client_id || '';
            document.getElementById('source-client-secret').value = '';
            document.getElementById('source-tenant').value = source.tenant_id || 'common';
            document.getElementById('source-calendars').value = (source.calendars || ['primary']).join(', ');
            document.getElementById('source-redirect').value = source.redirect_uri || '';
            document.getElementById('source-booking-target').checked = !!source.is_booking_target;

            // Secret-Hinweis
            const hint = document.getElementById('secret-hint');
            if (source.client_secret_set || source.client_secret) {
                hint.style.display = 'block';
            } else {
                hint.style.display = 'none';
            }
        } else {
            title.textContent = 'Neue Kalender-Quelle';
            document.getElementById('source-id').value = '';
            document.getElementById('secret-hint').style.display = 'none';
        }

        updateTenantVisibility();
        modal.style.display = 'flex';
    }

    function closeSourceModal() {
        document.getElementById('modal-source').style.display = 'none';
    }

    // Tenant-Feld nur bei Microsoft anzeigen
    function updateTenantVisibility() {
        const type = document.getElementById('source-type').value;
        document.getElementById('ms-fields').style.display = type === 'microsoft' ? 'block' : 'none';
    }

    document.getElementById('source-type').addEventListener('change', updateTenantVisibility);

    document.getElementById('btn-add-source').addEventListener('click', () => openSourceModal(null));

    document.querySelectorAll('.modal-close, .modal-cancel, .modal-backdrop').forEach(el => {
        el.addEventListener('click', closeSourceModal);
    });

    // Edit-Buttons
    document.querySelectorAll('.btn-edit-source').forEach(btn => {
        btn.addEventListener('click', function () {
            const source = JSON.parse(this.dataset.source);
            openSourceModal(source);
        });
    });

    // Delete source
    document.querySelectorAll('.btn-delete-source').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            const label = this.dataset.label;
            if (!confirm('Kalender-Quelle "' + label + '" wirklich entfernen?')) return;

            apiPost('delete-calendar-source', { id: id }).then(res => {
                if (res.success) {
                    showToast('Kalender-Quelle entfernt');
                    document.querySelector(`.calendar-source-card[data-id="${id}"]`).remove();
                } else {
                    showToast(res.error || 'Fehler', 'error');
                }
            });
        });
    });

    // Disconnect source
    document.querySelectorAll('.btn-disconnect-source').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            if (!confirm('Verbindung wirklich trennen?')) return;

            apiPost('disconnect-calendar', { id: id }).then(res => {
                if (res.success) {
                    showToast('Verbindung getrennt');
                    location.reload();
                } else {
                    showToast(res.error || 'Fehler', 'error');
                }
            });
        });
    });

    // Save source (modal form)
    document.getElementById('form-source').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        setLoading(btn, true);

        const data = {
            id: document.getElementById('source-id').value,
            type: document.getElementById('source-type').value,
            label: document.getElementById('source-label').value,
            client_id: document.getElementById('source-client-id').value,
            client_secret: document.getElementById('source-client-secret').value,
            tenant_id: document.getElementById('source-tenant').value,
            calendars: document.getElementById('source-calendars').value,
            redirect_uri: document.getElementById('source-redirect').value,
            is_booking_target: document.getElementById('source-booking-target').checked,
        };

        apiPost('save-calendar-source', data).then(res => {
            setLoading(btn, false);
            if (res.success) {
                showToast('Kalender-Quelle gespeichert');
                closeSourceModal();
                location.reload();
            } else {
                showToast(res.error || 'Fehler', 'error');
            }
        }).catch(() => { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
    });

    // ==================== Form: Passwort ====================
    document.getElementById('form-password').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');

        const newPw = document.getElementById('new-password').value;
        const confirmPw = document.getElementById('confirm-password').value;
        const currentPwField = document.getElementById('current-password');

        if (newPw !== confirmPw) {
            showToast('Passwörter stimmen nicht überein', 'error');
            return;
        }

        if (newPw.length < 12) {
            showToast('Passwort muss mindestens 12 Zeichen haben', 'error');
            return;
        }

        setLoading(btn, true);

        apiPost('save-password', {
            current_password: currentPwField ? currentPwField.value : '',
            new_password: newPw,
        }).then(res => {
            setLoading(btn, false);
            if (res.success) {
                showToast('Passwort gespeichert');
                this.reset();
            } else {
                showToast(res.error || 'Fehler', 'error');
            }
        }).catch(() => { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
    });

    // ==================== Form: Kalenderansicht ====================

    // Kalender-Liste für die Kalenderansicht laden (echte Kalenderlisten von den Accounts)
    (function loadCalendarViewCalendars() {
        var container = document.getElementById('calview-calendars-list');
        if (!container) return;

        // Zuerst Config laden für visible_calendars, dann echte Kalenderlisten
        Promise.all([
            fetch(API + '?action=config').then(function (r) { return r.json(); }),
            fetch(API + '?action=calendar-lists').then(function (r) { return r.json(); })
        ]).then(function (results) {
            var config = results[0].config || {};
            var calView = config.calendar_view || {};
            var visibleCalendars = calView.visible_calendars || [];
            var sources = results[1].sources || [];

            if (sources.length === 0) {
                container.innerHTML = '<div style="color:var(--gray-400);font-size:14px;">Keine Kalender-Quellen konfiguriert.</div>';
                return;
            }

            container.innerHTML = '';
            var hasAnyCalendars = false;

            sources.forEach(function (src) {
                if (!src.connected || src.calendars.length === 0) return;
                hasAnyCalendars = true;

                var group = document.createElement('div');
                group.style.marginBottom = '12px';

                var label = document.createElement('div');
                label.style.cssText = 'font-weight:600;font-size:14px;margin-bottom:6px;';
                label.textContent = src.label || src.id;
                var typeTag = document.createElement('span');
                typeTag.style.cssText = 'font-weight:400;color:var(--gray-400);margin-left:6px;font-size:12px;';
                typeTag.textContent = '(' + (src.type === 'microsoft' ? 'M365' : 'Google') + ')';
                label.appendChild(typeTag);
                group.appendChild(label);

                src.calendars.forEach(function (cal) {
                    var key = src.id + ':' + cal.id;
                    var isChecked = visibleCalendars.length === 0 || visibleCalendars.indexOf(key) !== -1;

                    var item = document.createElement('label');
                    item.className = 'checkbox-label';
                    item.style.cssText = 'display:flex;align-items:center;margin-bottom:4px;padding-left:8px;';

                    var colorDot = '';
                    if (cal.color) {
                        colorDot = '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' +
                            cal.color + ';margin-right:6px;flex-shrink:0;"></span>';
                    }

                    item.innerHTML = '<input type="checkbox" class="calview-cal-checkbox" data-key="' +
                        key.replace(/"/g, '&quot;') + '" ' + (isChecked ? 'checked' : '') + '>' +
                        colorDot + '<span>' + (cal.name || cal.id) + '</span>';
                    group.appendChild(item);
                });

                container.appendChild(group);
            });

            if (!hasAnyCalendars) {
                container.innerHTML = '<div style="color:var(--gray-400);font-size:14px;">Keine verbundenen Kalender gefunden. Bitte zuerst unter "Kalender" eine Quelle verbinden.</div>';
            }
        }).catch(function () {
            container.innerHTML = '<div style="color:var(--error);font-size:14px;">Fehler beim Laden der Kalender.</div>';
        });
    })();

    // Kalenderansicht-Formular speichern
    var formCalView = document.getElementById('form-calendar-view');
    if (formCalView) {
        formCalView.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = this.querySelector('button[type="submit"]');

            var newPw = document.getElementById('calview-new-password').value;
            var confirmPw = document.getElementById('calview-confirm-password').value;
            var removePwEl = document.getElementById('calview-remove-password');

            if (newPw && newPw !== confirmPw) {
                showToast('Passwörter stimmen nicht überein', 'error');
                return;
            }
            if (newPw && newPw.length < 12) {
                showToast('Passwort muss mindestens 12 Zeichen haben', 'error');
                return;
            }

            // Sichtbare Kalender sammeln
            var visibleCalendars = [];
            var allChecked = true;
            var anyUnchecked = false;
            document.querySelectorAll('.calview-cal-checkbox').forEach(function (cb) {
                if (cb.checked) {
                    visibleCalendars.push(cb.dataset.key);
                } else {
                    anyUnchecked = true;
                }
            });
            // Wenn alle ausgewählt, leeres Array senden (= alle anzeigen)
            if (!anyUnchecked) {
                visibleCalendars = [];
            }

            var data = {
                show_event_title: document.getElementById('calview-show-title').checked,
                visible_calendars: visibleCalendars,
            };

            if (newPw) {
                data.new_password = newPw;
            }
            if (removePwEl && removePwEl.checked) {
                data.remove_password = true;
            }

            setLoading(btn, true);
            apiPost('save-calendar-view', data).then(function (res) {
                setLoading(btn, false);
                if (res.success) {
                    showToast('Kalenderansicht-Einstellungen gespeichert');
                    document.getElementById('calview-new-password').value = '';
                    document.getElementById('calview-confirm-password').value = '';
                } else {
                    showToast(res.error || 'Fehler', 'error');
                }
            }).catch(function () { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
        });
    }

    // ==================== Form: Seitendesign ====================

    // Image upload
    document.querySelectorAll('.image-upload-input').forEach(function (input) {
        input.addEventListener('change', function () {
            var file = this.files[0];
            if (!file) return;
            var field = this.dataset.field;
            var label = this.closest('.image-upload-area').querySelector('.image-upload-btn');
            var formData = new FormData();
            formData.append('image', file);
            formData.append('field', field);
            formData.append('csrf_token', CSRF_TOKEN);

            label.classList.add('uploading');
            label.querySelector('span').textContent = 'Wird hochgeladen...';

            fetch(API + '?action=upload-image', {
                method: 'POST',
                body: formData,
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    label.classList.remove('uploading');
                    label.querySelector('span').textContent = 'Bild hochladen';
                    if (res.success) {
                        showToast('Bild hochgeladen');
                        var preview = document.getElementById(field.replace('_', '-') + '-preview');
                        preview.querySelector('img').src = '../' + res.path;
                        preview.style.display = '';
                        label.style.display = 'none';
                    } else {
                        showToast(res.error || 'Upload fehlgeschlagen', 'error');
                    }
                })
                .catch(function () {
                    label.classList.remove('uploading');
                    label.querySelector('span').textContent = 'Bild hochladen';
                    showToast('Verbindungsfehler', 'error');
                });

            this.value = '';
        });
    });

    // Image delete
    document.querySelectorAll('.btn-remove-image').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = this.dataset.field;
            if (!confirm('Bild wirklich entfernen?')) return;
            apiPost('delete-image', { field: field }).then(function (res) {
                if (res.success) {
                    showToast('Bild entfernt');
                    var preview = document.getElementById(field.replace('_', '-') + '-preview');
                    preview.style.display = 'none';
                    var label = document.getElementById(field.replace('_', '-') + '-upload-label');
                    label.style.display = '';
                } else {
                    showToast(res.error || 'Fehler', 'error');
                }
            });
        });
    });

    // Text form
    var designForm = document.getElementById('form-page-design');
    if (designForm) {
        designForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = this.querySelector('button[type="submit"]');
            setLoading(btn, true);

            apiPost('save-page-design', {
                welcome_title: document.getElementById('design-welcome-title').value,
                welcome_text: document.getElementById('design-welcome-text').value,
                booking_info: document.getElementById('design-booking-info').value,
            }).then(function (res) {
                setLoading(btn, false);
                if (res.success) showToast('Texte gespeichert');
                else showToast(res.error || 'Fehler', 'error');
            }).catch(function () { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
        });
    }

    // ==================== Embed-Code Generator ====================
    const embedWidth = document.getElementById('embed-width');
    const embedHeight = document.getElementById('embed-height');
    const embedCode = document.getElementById('embed-code');

    function updateEmbedCode() {
        if (!embedCode) return;
        const w = (embedWidth ? embedWidth.value : '100%') || '100%';
        const h = (embedHeight ? embedHeight.value : '700px') || '700px';
        // URL aus dem Preview-iframe lesen oder aus dem sichtbaren Input
        const iframe = document.getElementById('embed-preview-iframe');
        const src = iframe ? iframe.src : '';
        if (!src) {
            embedCode.value = '<!-- Bitte zuerst die App-URL unter Allgemein konfigurieren -->';
            return;
        }
        embedCode.value =
            '<iframe src="' + src + '"\n' +
            '        style="width:' + w + ';min-height:' + h + ';border:none;"\n' +
            '        loading="lazy" allow="clipboard-write"></iframe>\n' +
            '<script>\n' +
            'window.addEventListener("message", function(e) {\n' +
            '    if (e.data && e.data.type === "terminbuchung-resize") {\n' +
            '        var f = document.querySelector(\'iframe[src*="embed.php"]\');\n' +
            '        if (f) f.style.height = e.data.height + "px";\n' +
            '    }\n' +
            '});\n' +
            '</' + 'script>';
    }

    if (embedWidth) embedWidth.addEventListener('input', updateEmbedCode);
    if (embedHeight) embedHeight.addEventListener('input', updateEmbedCode);
    updateEmbedCode();

    const btnCopyEmbed = document.getElementById('btn-copy-embed');
    if (btnCopyEmbed) {
        btnCopyEmbed.addEventListener('click', function () {
            if (embedCode) {
                navigator.clipboard.writeText(embedCode.value).then(function () {
                    showToast('Embed-Code kopiert');
                });
            }
        });
    }

    // ==================== Form: Webhook ====================

    // Toggle Webhook-Felder
    var webhookEnabled = document.getElementById('webhook-enabled');
    if (webhookEnabled) {
        webhookEnabled.addEventListener('change', function () {
            var fields = document.getElementById('webhook-fields');
            if (this.checked) {
                fields.style.opacity = '1';
                fields.style.pointerEvents = 'auto';
            } else {
                fields.style.opacity = '0.5';
                fields.style.pointerEvents = 'none';
            }
        });
    }

    // Header hinzufügen
    var btnAddWhHeader = document.getElementById('btn-add-wh-header');
    if (btnAddWhHeader) {
        btnAddWhHeader.addEventListener('click', function () {
            var list = document.getElementById('webhook-headers-list');
            var row = document.createElement('div');
            row.className = 'webhook-header-row';
            row.innerHTML =
                '<div class="form-row" style="flex:1;">' +
                '    <div class="form-group">' +
                '        <input type="text" class="wh-name" placeholder="Header-Name (z.B. Authorization)">' +
                '    </div>' +
                '    <div class="form-group">' +
                '        <input type="text" class="wh-value" placeholder="Wert (z.B. Bearer token123)">' +
                '    </div>' +
                '</div>' +
                '<button type="button" class="btn-remove-wh-header" title="Entfernen">&times;</button>';
            list.appendChild(row);
            bindRemoveWhHeaderButtons();
        });
    }

    function bindRemoveWhHeaderButtons() {
        document.querySelectorAll('.btn-remove-wh-header').forEach(function (btn) {
            btn.onclick = function () {
                this.closest('.webhook-header-row').remove();
            };
        });
    }
    bindRemoveWhHeaderButtons();

    // Webhook speichern
    var formWebhook = document.getElementById('form-webhook');
    if (formWebhook) {
        formWebhook.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = this.querySelector('button[type="submit"]');
            setLoading(btn, true);

            var headers = [];
            document.querySelectorAll('.webhook-header-row').forEach(function (row) {
                var name = row.querySelector('.wh-name').value.trim();
                var value = row.querySelector('.wh-value').value.trim();
                if (name) {
                    headers.push({ name: name, value: value });
                }
            });

            var data = {
                enabled: document.getElementById('webhook-enabled').checked,
                url: document.getElementById('webhook-url').value,
                secret: document.getElementById('webhook-secret').value,
                headers: headers,
            };

            apiPost('save-webhook', data).then(function (res) {
                setLoading(btn, false);
                if (res.success) {
                    showToast('Webhook-Einstellungen gespeichert');
                    // Test-Button Status aktualisieren
                    var testBtn = document.getElementById('btn-test-webhook');
                    if (testBtn) {
                        testBtn.disabled = !data.url;
                    }
                } else {
                    showToast(res.error || 'Fehler', 'error');
                }
            }).catch(function () { setLoading(btn, false); showToast('Verbindungsfehler', 'error'); });
        });
    }

    // Webhook testen
    var btnTestWebhook = document.getElementById('btn-test-webhook');
    if (btnTestWebhook) {
        btnTestWebhook.addEventListener('click', function () {
            var btn = this;
            var origText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Sende Test...';

            apiPost('test-webhook', {}).then(function (res) {
                btn.disabled = false;
                btn.textContent = origText;

                var resultBox = document.getElementById('webhook-test-result');
                var content = document.getElementById('webhook-test-content');
                resultBox.style.display = 'block';

                var statusClass = res.success ? 'success' : 'error';
                var statusText = res.success ? 'Erfolgreich' : 'Fehlgeschlagen';
                var statusCode = res.status_code ? ' (HTTP ' + res.status_code + ')' : '';

                var html = '<div class="webhook-test-status ' + statusClass + '">' + statusText + statusCode + '</div>';

                if (res.error) {
                    html += '<p style="color:var(--error);font-size:14px;margin-top:8px;">' +
                        res.error.replace(/</g, '&lt;') + '</p>';
                }

                if (res.response) {
                    html += '<div class="webhook-test-response">' +
                        res.response.replace(/</g, '&lt;') + '</div>';
                }

                content.innerHTML = html;

                if (res.success) {
                    showToast('Webhook-Test erfolgreich');
                } else {
                    showToast('Webhook-Test fehlgeschlagen', 'error');
                }
            }).catch(function () {
                btn.disabled = false;
                btn.textContent = origText;
                showToast('Verbindungsfehler', 'error');
            });
        });
    }

    // ==================== Funnels ====================

    var funnelsList = document.getElementById('funnels-list');
    var funnelsEmptyHint = document.getElementById('funnels-empty-hint');
    var funnelsLoaded = [];
    // Pro Row die Funnel-Daten – vermeidet JSON.stringify/parse via dataset und
    // haelt das Secret aus dem DOM-Inspector raus.
    var funnelByRow = new WeakMap();

    var MODE_VIEW = 'view';
    var MODE_EDIT = 'edit';

    function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
    function escText(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    function funnelUrl(slug) {
        var configured = document.querySelector('meta[name="app-base-url"]')?.content || '';
        if (configured) return configured.replace(/\/+$/, '') + '/' + (slug || '');
        // Fallback: aus dem aktuellen Admin-Pfad ableiten.
        var base = location.pathname.replace(/admin\/?(index\.php)?$/, '');
        if (!base.endsWith('/')) base += '/';
        return location.origin + base + (slug || '');
    }

    function renderFunnelRow(f, mode) {
        var div = document.createElement('div');
        div.className = 'funnel-row admin-card';
        div.style.marginBottom = '12px';
        div.dataset.mode = mode;
        div.dataset.originalSlug = f.slug || '';
        funnelByRow.set(div, f);

        if (mode === MODE_VIEW) {
            var slug = f.slug || '';
            var name = f.name || '(unbenannt)';
            var disabled = f.enabled === false;
            var url = funnelUrl(slug);
            div.innerHTML =
                '<div style="display:flex;align-items:center;gap:16px;">' +
                '  <div style="flex:1;">' +
                '    <strong>' + escText(name) + '</strong>' +
                (disabled ? ' <span style="background:var(--gray-200);color:var(--gray-700);padding:2px 8px;border-radius:4px;font-size:12px;margin-left:8px;">inaktiv</span>' : '') +
                '    <div class="text-muted" style="font-size:13px;margin-top:4px;">URL: <a href="' + escAttr(url) + '" target="_blank"><code>' + escText(url) + '</code></a></div>' +
                '  </div>' +
                '  <div style="display:flex;gap:6px;">' +
                '    <button type="button" class="btn btn-secondary f-test">Test</button>' +
                '    <button type="button" class="btn btn-secondary f-edit">Bearbeiten</button>' +
                '    <button type="button" class="btn btn-secondary f-delete" style="color:var(--error);">L&ouml;schen</button>' +
                '  </div>' +
                '</div>';
        } else {
            var slugVal = escAttr(f.slug || '');
            var nameVal = escAttr(f.name || '');
            var urlVal = escAttr(f.webhook_url || '');
            var secretVal = escAttr(f.webhook_secret || '');
            var enabled = f.enabled === false ? '' : 'checked';
            div.innerHTML =
                '<div class="form-row">' +
                '  <div class="form-group" style="flex:1;">' +
                '    <label>Slug <span class="required">*</span></label>' +
                '    <input type="text" class="f-slug" value="' + slugVal + '" placeholder="webinar-a" maxlength="50">' +
                '    <div class="form-hint">URL: <code>' + escText(funnelUrl(slugVal || '<slug>')) + '</code></div>' +
                '  </div>' +
                '  <div class="form-group" style="flex:2;">' +
                '    <label>Name <span class="required">*</span></label>' +
                '    <input type="text" class="f-name" value="' + nameVal + '" placeholder="Webinar A – Mai 2026">' +
                '  </div>' +
                '</div>' +
                '<div class="form-group">' +
                '  <label>Webhook-URL <span class="required">*</span></label>' +
                '  <input type="url" class="f-url" value="' + urlVal + '" placeholder="https://hooks.example.com/...">' +
                '</div>' +
                '<div class="form-group">' +
                '  <label>HMAC-Secret (optional)</label>' +
                '  <input type="text" class="f-secret" value="' + secretVal + '" placeholder="Geheimer Schl&uuml;ssel f&uuml;r X-Funnel-Signature">' +
                '</div>' +
                '<div class="form-row" style="align-items:center;">' +
                '  <label class="checkbox-label" style="flex:1;">' +
                '    <input type="checkbox" class="f-enabled" ' + enabled + '>' +
                '    <span>Aktiviert</span>' +
                '  </label>' +
                '  <button type="button" class="btn btn-secondary f-test">Test</button>' +
                '  <button type="button" class="btn btn-primary f-save">Speichern</button>' +
                '  <button type="button" class="btn btn-secondary f-cancel">Abbrechen</button>' +
                '</div>';
        }
        return div;
    }

    function isNewRow(row) {
        return !row.dataset.originalSlug;
    }

    function readFunnelFromRow(row) {
        if (row.dataset.mode === MODE_EDIT) {
            return {
                slug: row.querySelector('.f-slug').value.trim(),
                name: row.querySelector('.f-name').value.trim(),
                webhook_url: row.querySelector('.f-url').value.trim(),
                webhook_secret: row.querySelector('.f-secret').value,
                enabled: row.querySelector('.f-enabled').checked,
            };
        }
        return funnelByRow.get(row) || {};
    }

    function swapRow(oldRow, f, mode) {
        var newRow = renderFunnelRow(f, mode);
        oldRow.replaceWith(newRow);
        bindRowButtons(newRow);
    }

    function bindRowButtons(row) {
        var editBtn = row.querySelector('.f-edit');
        if (editBtn) editBtn.onclick = function () {
            swapRow(row, funnelByRow.get(row) || {}, MODE_EDIT);
        };

        var cancelBtn = row.querySelector('.f-cancel');
        if (cancelBtn) cancelBtn.onclick = function () {
            if (isNewRow(row)) {
                row.remove();
                if (funnelsList.children.length === 0 && funnelsEmptyHint) {
                    funnelsList.appendChild(funnelsEmptyHint);
                    funnelsEmptyHint.style.display = '';
                }
            } else {
                swapRow(row, funnelByRow.get(row) || {}, MODE_VIEW);
            }
        };

        var saveBtn = row.querySelector('.f-save');
        if (saveBtn) saveBtn.onclick = function () { saveRow(row); };

        var deleteBtn = row.querySelector('.f-delete');
        if (deleteBtn) deleteBtn.onclick = function () { deleteRow(row); };

        var testBtn = row.querySelector('.f-test');
        if (testBtn) testBtn.onclick = function () { testRow(row); };
    }

    function saveRow(row) {
        var newF = readFunnelFromRow(row);
        if (!newF.slug) { showToast('Slug erforderlich', 'error'); return; }
        if (!newF.name) { showToast('Name erforderlich', 'error'); return; }
        if (!newF.webhook_url) { showToast('Webhook-URL erforderlich', 'error'); return; }

        var originalSlug = row.dataset.originalSlug || '';
        var list = funnelsLoaded.slice();
        if (isNewRow(row)) {
            list.push(newF);
        } else {
            var idx = list.findIndex(function (x) { return x.slug === originalSlug; });
            if (idx >= 0) list[idx] = newF;
            else list.push(newF);
        }

        var btn = row.querySelector('.f-save');
        var orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Speichern...';

        apiPost('funnels-save', { funnels: list }).then(function (res) {
            btn.disabled = false;
            btn.textContent = orig;
            if (res.success) {
                showToast('Funnel gespeichert');
                rebuildFunnelsList(res.funnels || list);
            } else {
                showToast(res.error || 'Fehler beim Speichern', 'error');
            }
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = orig;
            showToast('Verbindungsfehler', 'error');
        });
    }

    function deleteRow(row) {
        if (isNewRow(row)) { row.remove(); return; }
        var f = funnelByRow.get(row) || {};
        var originalSlug = row.dataset.originalSlug || '';
        if (!confirm('Funnel "' + (f.name || originalSlug) + '" wirklich loeschen?')) return;

        var list = funnelsLoaded.filter(function (x) { return x.slug !== originalSlug; });
        apiPost('funnels-save', { funnels: list }).then(function (res) {
            if (res.success) {
                showToast('Funnel geloescht');
                rebuildFunnelsList(res.funnels || list);
            } else {
                showToast(res.error || 'Fehler', 'error');
            }
        }).catch(function () { showToast('Verbindungsfehler', 'error'); });
    }

    function testRow(row) {
        var data = readFunnelFromRow(row);
        if (!data.slug || !data.webhook_url) {
            showToast('Slug und Webhook-URL erforderlich', 'error');
            return;
        }
        var btn = row.querySelector('.f-test');
        var orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Sende...';

        apiPost('funnels-test', data).then(function (res) {
            btn.disabled = false;
            btn.textContent = orig;
            var box = document.getElementById('funnel-test-result');
            var content = document.getElementById('funnel-test-content');
            box.style.display = 'block';
            var statusClass = res.success ? 'success' : 'error';
            var statusText = res.success ? 'Erfolgreich' : 'Fehlgeschlagen';
            var statusCode = res.status_code ? ' (HTTP ' + res.status_code + ')' : '';
            var html = '<div class="webhook-test-status ' + statusClass + '">' + statusText + statusCode + '</div>';
            if (res.error) html += '<p style="color:var(--error);font-size:14px;margin-top:8px;">' + escText(res.error) + '</p>';
            if (res.response) html += '<div class="webhook-test-response">' + escText(res.response) + '</div>';
            content.innerHTML = html;
            showToast(res.success ? 'Funnel-Test erfolgreich' : 'Funnel-Test fehlgeschlagen', res.success ? 'success' : 'error');
        }).catch(function () { btn.disabled = false; btn.textContent = orig; showToast('Verbindungsfehler', 'error'); });
    }

    function rebuildFunnelsList(funnels) {
        funnelsLoaded = funnels || [];
        funnelsList.innerHTML = '';
        if (funnelsLoaded.length === 0) {
            funnelsList.appendChild(funnelsEmptyHint);
            funnelsEmptyHint.style.display = '';
        } else {
            if (funnelsEmptyHint) funnelsEmptyHint.style.display = 'none';
            funnelsLoaded.forEach(function (f) {
                var row = renderFunnelRow(f, MODE_VIEW);
                funnelsList.appendChild(row);
                bindRowButtons(row);
            });
        }
    }

    function loadFunnels() {
        if (!funnelsList) return;
        fetch('api.php?action=funnels-list')
            .then(function (r) { return r.json(); })
            .then(function (res) { rebuildFunnelsList(res.funnels || []); })
            .catch(function () { showToast('Konnte Funnels nicht laden', 'error'); });
    }

    var btnAddFunnel = document.getElementById('btn-add-funnel');
    if (btnAddFunnel) {
        btnAddFunnel.addEventListener('click', function () {
            if (funnelsEmptyHint) funnelsEmptyHint.style.display = 'none';
            var row = renderFunnelRow({ enabled: true }, MODE_EDIT);
            funnelsList.appendChild(row);
            bindRowButtons(row);
        });
    }

    // ==================== Webhook-Queue (innerhalb Funnels-Tab) ====================

    var currentBucket = 'pending';
    function loadQueue(bucket) {
        currentBucket = bucket || currentBucket;
        var listEl = document.getElementById('funnel-queue-list');
        if (!listEl) return;
        listEl.innerHTML = '<p class="text-muted">Lade&hellip;</p>';
        fetch('api.php?action=webhook-queue&bucket=' + encodeURIComponent(currentBucket))
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var counts = res.counts || { pending: 0, done: 0, failed: 0 };
                ['pending', 'done', 'failed'].forEach(function (b) {
                    var el = document.querySelector('.funnel-queue-tab [data-count="' + b + '"]');
                    if (el) el.textContent = counts[b] || 0;
                });
                var jobs = res.jobs || [];
                if (jobs.length === 0) {
                    listEl.innerHTML = '<p class="text-muted">Keine Eintr&auml;ge in diesem Bucket.</p>';
                    return;
                }
                var html = '<table class="admin-table" style="width:100%;font-size:13px;">' +
                    '<thead><tr><th>Funnel</th><th>Versuche</th><th>Letzter Status</th><th>N&auml;chster Versuch</th><th></th></tr></thead><tbody>';
                jobs.forEach(function (j) {
                    var nextRun = j.next_run_at ? new Date(j.next_run_at * 1000).toLocaleString() : '-';
                    var status = j.last_status_code ? ('HTTP ' + j.last_status_code) : '-';
                    if (j.last_error) status += ' &middot; ' + escText(j.last_error).slice(0, 80);
                    var actions = '';
                    if (currentBucket === 'failed') {
                        actions += '<button type="button" class="btn btn-secondary btn-q-retry" data-id="' + escAttr(j.id) + '">Erneut</button> ';
                    }
                    actions += '<button type="button" class="btn btn-secondary btn-q-delete" data-id="' + escAttr(j.id) + '" data-bucket="' + currentBucket + '" style="color:var(--error);">L&ouml;schen</button>';
                    html += '<tr>' +
                        '<td>' + escText(j.funnel_slug || '-') + '</td>' +
                        '<td>' + (j.attempts || 0) + '</td>' +
                        '<td>' + status + '</td>' +
                        '<td>' + escText(nextRun) + '</td>' +
                        '<td>' + actions + '</td>' +
                    '</tr>';
                });
                html += '</tbody></table>';
                listEl.innerHTML = html;

                listEl.querySelectorAll('.btn-q-retry').forEach(function (b) {
                    b.onclick = function () {
                        apiPost('webhook-queue-retry', { id: this.dataset.id }).then(function (r) {
                            if (r.success) { showToast('Job neu in Queue'); loadQueue(); }
                            else showToast('Fehler', 'error');
                        });
                    };
                });
                listEl.querySelectorAll('.btn-q-delete').forEach(function (b) {
                    b.onclick = function () {
                        if (!confirm('Job wirklich l&ouml;schen?')) return;
                        apiPost('webhook-queue-delete', { id: this.dataset.id, bucket: this.dataset.bucket }).then(function (r) {
                            if (r.success) { showToast('Gel&ouml;scht'); loadQueue(); }
                            else showToast('Fehler', 'error');
                        });
                    };
                });
            })
            .catch(function () { listEl.innerHTML = '<p class="text-muted">Fehler beim Laden.</p>'; });
    }

    document.querySelectorAll('.funnel-queue-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.funnel-queue-tab').forEach(function (t) { t.classList.remove('active'); });
            this.classList.add('active');
            loadQueue(this.dataset.bucket);
        });
    });

    var btnRunQueue = document.getElementById('btn-run-queue');
    if (btnRunQueue) {
        btnRunQueue.addEventListener('click', function () {
            var orig = this.textContent;
            this.disabled = true;
            this.textContent = 'Verarbeite...';
            var self = this;
            apiPost('webhook-queue-run', {}).then(function (res) {
                self.disabled = false;
                self.textContent = orig;
                if (res.success) {
                    var s = res.stats || {};
                    showToast('Verarbeitet: ' + (s.processed || 0) + ' (' + (s.succeeded || 0) + ' OK, ' + (s.requeued || 0) + ' requeued, ' + (s.failed || 0) + ' failed)');
                    loadQueue();
                } else {
                    showToast('Fehler', 'error');
                }
            }).catch(function () { self.disabled = false; self.textContent = orig; showToast('Verbindungsfehler', 'error'); });
        });
    }

    // Beim Wechsel auf den Funnels-Tab Daten laden
    document.querySelectorAll('.nav-item[data-tab="funnels"]').forEach(function (a) {
        a.addEventListener('click', function () {
            loadFunnels();
            loadQueue('pending');
        });
    });

    // Initial laden, wenn die Seite direkt mit #funnels geoeffnet wurde –
    // der Hash-Click oben passiert vor dem Anhaengen der Tab-Listener,
    // dadurch wuerde sonst nichts gefetcht.
    if (funnelsList && location.hash === '#funnels') {
        loadFunnels();
        loadQueue('pending');
    }

    // ==================== ESC schließt Modals ====================
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSourceModal();
    });

})();
