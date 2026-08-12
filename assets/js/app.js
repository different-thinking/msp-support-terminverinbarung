/**
 * Terminbuchungs-App Frontend
 * Vanilla JS – Kalender, Zeitslots, Formular und Buchung.
 */
(function () {
    'use strict';

    const MONTHS_DE = [
        'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'
    ];
    const WEEKDAYS_DE = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    let state = {
        currentMonth: new Date().getMonth(),
        currentYear: new Date().getFullYear(),
        selectedDate: null,
        selectedTime: null,
        availableDays: {},
        availableSlots: [],
        attendees: [],
        loading: false,
        loadError: false,
        slotsError: false,
        currentStep: 1,
    };

    // Cache für bereits geladene Monate (key: "YYYY-MM")
    const daysCache = {};

    // DOM Elemente
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    function init() {
        loadAvailableDays(state.currentYear, state.currentMonth + 1);
        bindEvents();
    }

    function bindEvents() {
        $('#btn-prev-month').addEventListener('click', prevMonth);
        $('#btn-next-month').addEventListener('click', nextMonth);
        $('#btn-to-form').addEventListener('click', goToStep2);
        $('#btn-back-to-calendar').addEventListener('click', goToStep1);
        $('#btn-to-confirm').addEventListener('click', goToStep3);
        $('#btn-back-to-form').addEventListener('click', goToStep2);
        $('#btn-book').addEventListener('click', submitBooking);

        const addAttBtn = $('#btn-add-attendee');
        if (addAttBtn) {
            addAttBtn.addEventListener('click', addAttendeeField);
        }
    }

    // ========== Navigation ==========

    function goToStep1() {
        state.currentStep = 1;
        showPanel('panel-calendar');
        updateSteps();
    }

    function goToStep2() {
        if (!state.selectedDate || !state.selectedTime) {
            return;
        }
        state.currentStep = 2;
        showPanel('panel-form');
        updateSteps();
    }

    function goToStep3() {
        if (!validateForm()) return;

        state.currentStep = 3;
        buildSummary();
        showPanel('panel-confirm');
        updateSteps();
    }

    function showPanel(id) {
        $$('.booking-panel').forEach(p => p.classList.remove('active'));
        $('#' + id).classList.add('active');
    }

    function updateSteps() {
        $$('.step').forEach((step, i) => {
            const num = i + 1;
            step.classList.remove('active', 'completed');
            if (num === state.currentStep) {
                step.classList.add('active');
            } else if (num < state.currentStep) {
                step.classList.add('completed');
            }
        });
    }

    // ========== Kalender ==========

    function prevMonth() {
        state.currentMonth--;
        if (state.currentMonth < 0) {
            state.currentMonth = 11;
            state.currentYear--;
        }
        loadAvailableDays(state.currentYear, state.currentMonth + 1);
    }

    function nextMonth() {
        state.currentMonth++;
        if (state.currentMonth > 11) {
            state.currentMonth = 0;
            state.currentYear++;
        }
        loadAvailableDays(state.currentYear, state.currentMonth + 1);
    }

    function loadAvailableDays(year, month) {
        const cacheKey = `${year}-${String(month).padStart(2, '0')}`;

        // Aus Cache laden falls vorhanden
        if (daysCache[cacheKey]) {
            state.availableDays = daysCache[cacheKey];
            state.loading = false;
            state.loadError = false;
            renderCalendar();
            updatePrevButton(year, month);
            prefetchNextMonth(year, month);
            return;
        }

        state.loading = true;
        state.loadError = false;
        renderCalendar();

        fetch(`api/slots.php?action=days&year=${year}&month=${month}`)
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                const days = data.days || {};
                daysCache[cacheKey] = days;
                state.availableDays = days;
                state.loading = false;
                state.loadError = false;
                renderCalendar();
                updatePrevButton(year, month);
                prefetchNextMonth(year, month);
            })
            .catch(() => {
                state.loading = false;
                state.loadError = true;
                state.availableDays = {};
                renderCalendar();
            });
    }

    function updatePrevButton(year, month) {
        const now = new Date();
        $('#btn-prev-month').disabled =
            (year === now.getFullYear() && month <= now.getMonth() + 1);
    }

    function prefetchNextMonth(year, month) {
        let nextMonth = month + 1;
        let nextYear = year;
        if (nextMonth > 12) {
            nextMonth = 1;
            nextYear++;
        }

        const nextKey = `${nextYear}-${String(nextMonth).padStart(2, '0')}`;
        if (daysCache[nextKey]) return;

        fetch(`api/slots.php?action=days&year=${nextYear}&month=${nextMonth}`)
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                daysCache[nextKey] = data.days || {};
            })
            .catch(() => {
                // Prefetch fehlgeschlagen – Cache-Key wird nicht gesetzt,
                // sodass bei Navigation erneut geladen wird
            });
    }

    function renderCalendar() {
        // Monatsanzeige
        $('#calendar-month-year').textContent =
            MONTHS_DE[state.currentMonth] + ' ' + state.currentYear;

        const grid = $('#calendar-grid');

        if (state.loading) {
            grid.innerHTML = '<div class="calendar-loading"><div class="spinner spinner-dark"></div></div>';
            return;
        }

        if (state.loadError) {
            grid.innerHTML = '<div class="calendar-loading">'
                + '<p style="color:var(--gray-500);margin-bottom:8px;">Laden fehlgeschlagen</p>'
                + '<button type="button" class="btn btn-secondary btn-sm" id="btn-retry-load">Erneut versuchen</button>'
                + '</div>';
            const retryBtn = grid.querySelector('#btn-retry-load');
            if (retryBtn) {
                retryBtn.addEventListener('click', function() {
                    loadAvailableDays(state.currentYear, state.currentMonth + 1);
                });
            }
            return;
        }

        let html = '';

        // Wochentage
        WEEKDAYS_DE.forEach(day => {
            html += `<div class="calendar-weekday">${day}</div>`;
        });

        // Erster Tag des Monats
        const firstDay = new Date(state.currentYear, state.currentMonth, 1);
        let startOffset = firstDay.getDay() - 1; // Montag = 0
        if (startOffset < 0) startOffset = 6;

        const daysInMonth = new Date(state.currentYear, state.currentMonth + 1, 0).getDate();
        const today = new Date();

        // Leere Zellen vor dem 1.
        for (let i = 0; i < startOffset; i++) {
            html += '<div class="calendar-day other-month"></div>';
        }

        // Tage
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${state.currentYear}-${String(state.currentMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const isToday = (d === today.getDate() &&
                state.currentMonth === today.getMonth() &&
                state.currentYear === today.getFullYear());
            const isAvailable = state.availableDays[dateStr] !== undefined;
            const isSelected = state.selectedDate === dateStr;
            const slotCount = state.availableDays[dateStr] || 0;

            let classes = ['calendar-day'];
            if (isAvailable) classes.push('available');
            if (isSelected) classes.push('selected');
            if (isToday) classes.push('today');

            html += `<div class="${classes.join(' ')}" ${isAvailable ? `data-date="${dateStr}"` : ''}>`;
            html += d;
            if (isAvailable && slotCount > 0) {
                html += `<span class="slot-count">${slotCount}</span>`;
            }
            html += '</div>';
        }

        grid.innerHTML = html;

        // Click-Handler
        grid.querySelectorAll('.calendar-day.available').forEach(el => {
            el.addEventListener('click', () => selectDate(el.dataset.date));
        });
    }

    function selectDate(dateStr) {
        state.selectedDate = dateStr;
        state.selectedTime = null;
        renderCalendar();
        loadTimeSlots(dateStr);
    }

    // ========== Zeitslots ==========

    function loadTimeSlots(date) {
        const container = $('#time-slots-container');
        container.classList.remove('hidden');
        container.innerHTML = '<div class="calendar-loading"><div class="spinner spinner-dark"></div></div>';

        fetch(`api/slots.php?action=slots&date=${date}`)
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                state.availableSlots = data.slots || [];
                state.slotsError = false;
                renderTimeSlots(date);
            })
            .catch(() => {
                // Fehler nicht als "keine Termine" darstellen – sonst wirkt ein
                // Kalender-Ausfall wie ein voller Tag (oder umgekehrt).
                state.availableSlots = [];
                state.slotsError = true;
                renderTimeSlots(date);
            });
    }

    function renderTimeSlots(date) {
        const container = $('#time-slots-container');

        // Datum formatieren
        const parts = date.split('-');
        const dateFormatted = `${parts[2]}.${parts[1]}.${parts[0]}`;

        let html = `<div class="time-slots-date">${dateFormatted}</div>`;

        // Eine frühere Auswahl verwerfen, wenn sie nicht mehr belegbar ist –
        // sonst bleibt der Weiter-Button nach einem Ladefehler oder einem
        // inzwischen vergebenen Slot aktiv und die Buchung scheitert erst spät.
        if (state.selectedTime && !state.availableSlots.some(s => s.start === state.selectedTime)) {
            state.selectedTime = null;
        }

        if (state.slotsError) {
            html += '<div class="time-slots-empty">Die Verfügbarkeit konnte nicht geladen werden.</div>';
            html += '<div style="text-align:center;margin-top:8px;">'
                + '<button type="button" class="btn btn-secondary btn-sm" id="btn-retry-slots">Erneut versuchen</button>'
                + '</div>';
            container.innerHTML = html;
            const retryBtn = container.querySelector('#btn-retry-slots');
            if (retryBtn) {
                retryBtn.addEventListener('click', () => loadTimeSlots(date));
            }
            updateContinueButton();
            return;
        }

        if (state.availableSlots.length === 0) {
            html += '<div class="time-slots-empty">Keine verfügbaren Zeitslots an diesem Tag.</div>';
            container.innerHTML = html;
            updateContinueButton();
            return;
        }

        html += '<div class="time-slots-grid">';
        state.availableSlots.forEach(slot => {
            const isSelected = state.selectedTime === slot.start;
            html += `<div class="time-slot ${isSelected ? 'selected' : ''}" data-time="${slot.start}">`;
            html += slot.start;
            html += '</div>';
        });
        html += '</div>';

        container.innerHTML = html;

        // Click-Handler
        container.querySelectorAll('.time-slot').forEach(el => {
            el.addEventListener('click', () => selectTime(el.dataset.time));
        });

        updateContinueButton();
    }

    function selectTime(time) {
        state.selectedTime = time;
        renderTimeSlots(state.selectedDate);
        updateContinueButton();
    }

    function updateContinueButton() {
        const btn = $('#btn-to-form');
        btn.disabled = !(state.selectedDate && state.selectedTime);
    }

    // ========== Formular ==========

    function validateForm() {
        let valid = true;

        // Fehlermeldungen zurücksetzen
        $$('.form-error').forEach(el => el.textContent = '');
        $$('.form-group input, .form-group textarea').forEach(el => el.classList.remove('error'));

        const firstname = $('#field-firstname');
        const lastname = $('#field-lastname');
        const email = $('#field-email');

        if (!firstname.value.trim()) {
            showFieldError(firstname, 'Bitte geben Sie Ihren Vornamen ein.');
            valid = false;
        }

        if (!lastname.value.trim()) {
            showFieldError(lastname, 'Bitte geben Sie Ihren Nachnamen ein.');
            valid = false;
        }

        if (!email.value.trim() || !isValidEmail(email.value)) {
            showFieldError(email, 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
            valid = false;
        }

        // Pflichtfelder prüfen
        $$('[data-required="true"]').forEach(input => {
            if (!input.value.trim()) {
                showFieldError(input, `Bitte füllen Sie dieses Feld aus.`);
                valid = false;
            }
        });

        // Teilnehmer-E-Mails validieren
        $$('.attendee-email').forEach(input => {
            if (input.value.trim() && !isValidEmail(input.value)) {
                input.classList.add('error');
                valid = false;
            }
        });

        return valid;
    }

    function showFieldError(input, message) {
        input.classList.add('error');
        const errorEl = input.closest('.form-group').querySelector('.form-error');
        if (errorEl) errorEl.textContent = message;
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    // ========== Teilnehmer ==========

    function addAttendeeField() {
        const container = $('#attendees-list');
        const maxAttendees = parseInt($('#attendees-list').dataset.max || '5');
        const current = container.querySelectorAll('.attendee-row').length;

        if (current >= maxAttendees) return;

        const row = document.createElement('div');
        row.className = 'attendee-row';
        row.innerHTML = `
            <input type="email" class="attendee-email" placeholder="teilnehmer@example.com">
            <button type="button" class="btn-remove-attendee" title="Entfernen">&times;</button>
        `;

        row.querySelector('.btn-remove-attendee').addEventListener('click', () => {
            row.remove();
            updateAddAttendeeButton();
        });

        container.appendChild(row);
        updateAddAttendeeButton();
    }

    function updateAddAttendeeButton() {
        const container = $('#attendees-list');
        const maxAttendees = parseInt(container.dataset.max || '5');
        const current = container.querySelectorAll('.attendee-row').length;
        const btn = $('#btn-add-attendee');
        if (btn) {
            btn.style.display = current >= maxAttendees ? 'none' : 'block';
        }
    }

    // ========== Zusammenfassung ==========

    function buildSummary() {
        const parts = state.selectedDate.split('-');
        const dateFormatted = `${parts[2]}.${parts[1]}.${parts[0]}`;

        // Endzeit berechnen
        const startParts = state.selectedTime.split(':');
        const startMinutes = parseInt(startParts[0]) * 60 + parseInt(startParts[1]);
        const duration = parseInt(document.body.dataset.duration || '60');
        const endMinutes = startMinutes + duration;
        const endTime = String(Math.floor(endMinutes / 60)).padStart(2, '0') + ':' +
            String(endMinutes % 60).padStart(2, '0');

        const firstname = $('#field-firstname').value.trim();
        const lastname = $('#field-lastname').value.trim();
        const email = $('#field-email').value.trim();

        let summaryHtml = `
            <div class="summary-row">
                <span class="summary-label">Datum</span>
                <span class="summary-value">${dateFormatted}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Uhrzeit</span>
                <span class="summary-value">${state.selectedTime} – ${endTime} Uhr</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Name</span>
                <span class="summary-value">${escHtml(firstname)} ${escHtml(lastname)}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">E-Mail</span>
                <span class="summary-value">${escHtml(email)}</span>
            </div>
        `;

        // Zusatzfelder
        $$('.additional-field').forEach(input => {
            if (input.value.trim()) {
                const label = input.closest('.form-group').querySelector('label').textContent.replace(' *', '');
                summaryHtml += `
                    <div class="summary-row">
                        <span class="summary-label">${escHtml(label)}</span>
                        <span class="summary-value">${escHtml(input.value.trim())}</span>
                    </div>
                `;
            }
        });

        // Teilnehmer
        const attendees = getAttendeeEmails();
        if (attendees.length > 0) {
            summaryHtml += `
                <div class="summary-row">
                    <span class="summary-label">Weitere Teilnehmer</span>
                    <span class="summary-value">${attendees.map(escHtml).join(', ')}</span>
                </div>
            `;
        }

        $('#booking-summary-content').innerHTML = summaryHtml;
    }

    // ========== Buchung ==========

    function submitBooking() {
        const btn = $('#btn-book');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Wird gebucht...';

        // Zusatzfelder sammeln
        const fields = {};
        $$('.additional-field').forEach(input => {
            fields[input.name] = input.value.trim();
        });

        const data = {
            date: state.selectedDate,
            time: state.selectedTime,
            firstname: $('#field-firstname').value.trim(),
            lastname: $('#field-lastname').value.trim(),
            email: $('#field-email').value.trim(),
            fields: fields,
            additional_attendees: getAttendeeEmails(),
        };

        const funnelInput = $('#field-funnel');
        if (funnelInput && funnelInput.value) {
            data.funnel = funnelInput.value;
        }

        fetch('api/book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    showSuccess(result);
                } else {
                    showBookingError(result.message || 'Ein Fehler ist aufgetreten.');
                    btn.disabled = false;
                    btn.textContent = 'Termin verbindlich buchen';
                }
            })
            .catch(() => {
                showBookingError('Verbindungsfehler. Bitte versuchen Sie es erneut.');
                btn.disabled = false;
                btn.textContent = 'Termin verbindlich buchen';
            });
    }

    function showSuccess(result) {
        state.currentStep = 4;
        updateSteps();

        let detailsHtml = `
            <div class="summary-row">
                <span class="summary-label">Datum</span>
                <span class="summary-value">${escHtml(result.event.date)}</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Uhrzeit</span>
                <span class="summary-value">${escHtml(result.event.time)} Uhr</span>
            </div>
        `;

        if (result.event.teams_link) {
            detailsHtml += `
                <div style="margin-top: 12px;">
                    <a href="${escHtml(result.event.teams_link)}" target="_blank" class="teams-link">
                        &#128247; Microsoft Teams Meeting beitreten
                    </a>
                </div>
            `;
        }

        $$('.booking-panel').forEach(p => p.classList.remove('active'));
        const successPanel = $('#panel-success');
        successPanel.classList.add('active');
        successPanel.querySelector('.success-details').innerHTML = detailsHtml;
    }

    function showBookingError(message) {
        let alertEl = $('#booking-error');
        if (!alertEl) {
            alertEl = document.createElement('div');
            alertEl.id = 'booking-error';
            alertEl.className = 'alert alert-error';
            $('#panel-confirm').insertBefore(alertEl, $('#panel-confirm').firstChild);
        }
        alertEl.textContent = message;
        alertEl.style.display = 'block';
    }

    // ========== Hilfsfunktionen ==========

    function getAttendeeEmails() {
        const emails = [];
        $$('.attendee-email').forEach(input => {
            const email = input.value.trim();
            if (email && isValidEmail(email)) {
                emails.push(email);
            }
        });
        return emails;
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Start
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
