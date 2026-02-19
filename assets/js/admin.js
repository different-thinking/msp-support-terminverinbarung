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

    // ==================== ESC schließt Modals ====================
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSourceModal();
    });

})();
