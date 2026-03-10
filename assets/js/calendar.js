/**
 * Kalenderansicht – Vanilla JavaScript
 * Monats- und Wochenansicht mit Kalender-Auswahl.
 */
(function () {
    'use strict';

    const API = window.CAL_CONFIG.apiBase;
    const TIMEZONE = window.CAL_CONFIG.timezone;

    // ==================== State ====================
    const state = {
        view: 'week', // 'month' | 'week' | 'day' | '3day'
        currentDate: new Date(),
        miniDate: null, // Tracks mini-calendar independently if needed
        sources: [],     // [{id, type, connected, calendars: [{id, name, color}]}]
        selectedCalendars: new Map(), // Map<"sourceId:calendarId", {sourceId, calendarId, name, color, checked}>
        events: [],      // Cached events for current view
        loading: false,
    };

    // ==================== Init ====================
    document.addEventListener('DOMContentLoaded', init);

    function isMobile() { return window.innerWidth <= 768; }

    function init() {
        state.miniDate = new Date(state.currentDate);
        // Auf Mobile standardmäßig 3-Tage-Ansicht
        if (isMobile()) {
            state.view = '3day';
        }
        bindMobilePanel(); // muss vor anderen Bindings laufen (guard im Funktionskopf)
        bindToolbar();
        bindPopup();
        // Buttons korrekt initialisieren
        document.querySelectorAll('.btn-view').forEach(function (b) {
            b.classList.toggle('active', b.dataset.view === state.view);
        });
        var isTimeGrid = (state.view !== 'month');
        document.getElementById('monthView').classList.toggle('hidden', state.view !== 'month');
        document.getElementById('weekView').classList.toggle('hidden', !isTimeGrid);
        loadSources();
    }

    // ==================== Data Loading ====================

    async function loadSources() {
        try {
            const resp = await fetch(API + '?action=sources');
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            state.sources = data.sources || [];

            // Alle Kalender standardmässig aktivieren
            state.selectedCalendars.clear();
            const defaultColors = ['#2563eb', '#16a34a', '#f97316', '#8b5cf6', '#ec4899', '#14b8a6', '#eab308', '#ef4444'];
            let colorIdx = 0;
            state.sources.forEach(function (src) {
                if (!src.connected) return;
                src.calendars.forEach(function (cal) {
                    var key = src.id + ':' + cal.id;
                    state.selectedCalendars.set(key, {
                        sourceId: src.id,
                        calendarId: cal.id,
                        name: cal.name,
                        color: cal.color || defaultColors[colorIdx % defaultColors.length],
                        checked: true,
                    });
                    colorIdx++;
                });
            });

            restoreCalendarSelection();
            renderSourcesSidebar();
            renderMiniCalendar();
            if (isMobile()) {
                renderMobileSourcesList();
                renderMobileMiniCalendar();
            }
            loadEvents();
        } catch (e) {
            document.getElementById('calSourcesList').innerHTML =
                '<div class="cal-loading">Fehler beim Laden der Kalender.</div>';
        }
    }

    async function loadEvents() {
        var range = getViewDateRange();
        var start = range.start;
        var end = range.end;

        state.events = [];
        state.loading = true;
        renderView();

        var promises = [];
        state.selectedCalendars.forEach(function (cal, key) {
            if (!cal.checked) return;
            promises.push(
                fetch(API + '?action=events&source_id=' + encodeURIComponent(cal.sourceId) +
                    '&calendar_id=' + encodeURIComponent(cal.calendarId) +
                    '&start=' + encodeURIComponent(start) +
                    '&end=' + encodeURIComponent(end))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        return (data.events || []).map(function (ev) {
                            ev._color = cal.color;
                            ev._calendarName = cal.name;
                            ev._sourceId = cal.sourceId;
                            ev._calendarId = cal.calendarId;
                            return ev;
                        });
                    })
                    .catch(function () { return []; })
            );
        });

        var results = await Promise.all(promises);
        state.events = [];
        results.forEach(function (evts) {
            state.events = state.events.concat(evts);
        });

        state.loading = false;
        renderView();
    }

    // ==================== Date Helpers ====================

    function getViewDateRange() {
        if (state.view === 'month') {
            var y = state.currentDate.getFullYear();
            var m = state.currentDate.getMonth();
            var first = new Date(y, m, 1);
            var startDay = (first.getDay() + 6) % 7; // Mo=0
            var viewStart = new Date(y, m, 1 - startDay);
            var last = new Date(y, m + 1, 0);
            var endDay = (last.getDay() + 6) % 7;
            var viewEnd = new Date(y, m + 1, 0 + (6 - endDay));
            viewEnd.setHours(23, 59, 59);
            return {
                start: formatDateTimeISO(viewStart),
                end: formatDateTimeISO(viewEnd),
            };
        } else if (state.view === 'day') {
            var d = new Date(state.currentDate);
            d.setHours(0, 0, 0, 0);
            var end = new Date(d);
            end.setHours(23, 59, 59);
            return {
                start: formatDateTimeISO(d),
                end: formatDateTimeISO(end),
            };
        } else if (state.view === '3day') {
            var d = new Date(state.currentDate);
            d.setHours(0, 0, 0, 0);
            var end = new Date(d);
            end.setDate(d.getDate() + 2);
            end.setHours(23, 59, 59);
            return {
                start: formatDateTimeISO(d),
                end: formatDateTimeISO(end),
            };
        } else {
            // Woche: Mo - So
            var d = new Date(state.currentDate);
            var day = (d.getDay() + 6) % 7;
            var monday = new Date(d);
            monday.setDate(d.getDate() - day);
            monday.setHours(0, 0, 0, 0);
            var sunday = new Date(monday);
            sunday.setDate(monday.getDate() + 6);
            sunday.setHours(23, 59, 59);
            return {
                start: formatDateTimeISO(monday),
                end: formatDateTimeISO(sunday),
            };
        }
    }

    function formatDateTimeISO(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
            'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function formatTime(dateStr) {
        var d = new Date(dateStr);
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function isSameDay(d1, d2) {
        return d1.getFullYear() === d2.getFullYear() &&
            d1.getMonth() === d2.getMonth() &&
            d1.getDate() === d2.getDate();
    }

    function isToday(d) { return isSameDay(d, new Date()); }

    var MONTH_NAMES = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    var SHORT_MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun',
        'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    var DAY_NAMES = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    var FULL_DAY_NAMES = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    function getISOWeekNumber(date) {
        var d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
        d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
        var yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
        return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
    }

    // ==================== Toolbar ====================

    function bindToolbar() {
        document.getElementById('btnToday').addEventListener('click', function () {
            state.currentDate = new Date();
            state.miniDate = new Date();
            renderMiniCalendar();
            loadEvents();
        });

        document.getElementById('btnPrev').addEventListener('click', function () {
            navigate(-1);
        });

        document.getElementById('btnNext').addEventListener('click', function () {
            navigate(1);
        });

        document.querySelectorAll('.btn-view').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setView(this.dataset.view);
            });
        });
    }

    function navigate(dir) {
        if (state.view === 'month') {
            state.currentDate.setMonth(state.currentDate.getMonth() + dir);
        } else if (state.view === 'day') {
            state.currentDate.setDate(state.currentDate.getDate() + dir);
        } else if (state.view === '3day') {
            state.currentDate.setDate(state.currentDate.getDate() + (dir * 3));
        } else {
            state.currentDate.setDate(state.currentDate.getDate() + (dir * 7));
        }
        state.miniDate = new Date(state.currentDate);
        renderMiniCalendar();
        loadEvents();
    }

    function setView(view) {
        state.view = view;
        document.querySelectorAll('.btn-view').forEach(function (b) {
            b.classList.toggle('active', b.dataset.view === view);
        });
        var isTimeGrid = (view === 'week' || view === 'day' || view === '3day');
        document.getElementById('monthView').classList.toggle('hidden', view !== 'month');
        document.getElementById('weekView').classList.toggle('hidden', !isTimeGrid);
        loadEvents();
    }

    // ==================== Render Sidebar ====================

    function renderSourcesSidebar() {
        var container = document.getElementById('calSourcesList');
        container.innerHTML = '';

        if (state.sources.length === 0) {
            container.innerHTML = '<div class="cal-loading">Keine Kalender konfiguriert.</div>';
            return;
        }

        state.sources.forEach(function (src) {
            if (!src.connected || src.calendars.length === 0) return;

            var group = document.createElement('div');
            group.className = 'cal-source-group';

            var label = document.createElement('div');
            label.className = 'cal-source-group-label';
            var icon = src.type === 'microsoft' ? '&#xf871;' : '&#x1F310;';
            label.innerHTML = '<span class="cal-source-type-icon">' +
                (src.type === 'microsoft' ? 'M365' : 'Google') + '</span>';
            group.appendChild(label);

            src.calendars.forEach(function (cal) {
                var key = src.id + ':' + cal.id;
                var calData = state.selectedCalendars.get(key);
                if (!calData) return;

                var item = document.createElement('label');
                item.className = 'cal-source-item';

                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'cal-source-checkbox';
                checkbox.checked = calData.checked;
                checkbox.addEventListener('change', function () {
                    calData.checked = this.checked;
                    saveCalendarSelection();
                    loadEvents();
                });

                var colorDot = document.createElement('span');
                colorDot.className = 'cal-source-color';
                colorDot.style.backgroundColor = calData.color;

                var name = document.createElement('span');
                name.className = 'cal-source-name';
                name.textContent = cal.name;

                item.appendChild(checkbox);
                item.appendChild(colorDot);
                item.appendChild(name);
                group.appendChild(item);
            });

            container.appendChild(group);
        });
    }

    // ==================== Render Mini Calendar ====================

    function renderMiniCalendar() {
        var container = document.getElementById('miniCalendar');
        var d = state.miniDate || state.currentDate;
        var year = d.getFullYear();
        var month = d.getMonth();

        var html = '<div class="mini-cal-nav">' +
            '<button id="miniPrev">&#9664;</button>' +
            '<span class="mini-cal-title">' + SHORT_MONTHS[month] + ' ' + year + '</span>' +
            '<button id="miniNext">&#9654;</button>' +
            '</div>';

        html += '<div class="mini-cal-grid">';
        DAY_NAMES.forEach(function (dn) {
            html += '<div class="mini-cal-weekday">' + dn + '</div>';
        });

        var first = new Date(year, month, 1);
        var startDay = (first.getDay() + 6) % 7;
        var last = new Date(year, month + 1, 0);

        // Previous month fill
        for (var i = startDay - 1; i >= 0; i--) {
            var pd = new Date(year, month, -i);
            html += '<div class="mini-cal-day other-month" data-date="' + formatDateOnly(pd) + '">' + pd.getDate() + '</div>';
        }

        // Current month
        for (var day = 1; day <= last.getDate(); day++) {
            var cd = new Date(year, month, day);
            var classes = 'mini-cal-day';
            if (isToday(cd)) classes += ' today';
            if (isSameDay(cd, state.currentDate)) classes += ' selected';
            html += '<div class="' + classes + '" data-date="' + formatDateOnly(cd) + '">' + day + '</div>';
        }

        // Next month fill
        var endDay = (last.getDay() + 6) % 7;
        for (var j = 1; j <= 6 - endDay; j++) {
            var nd = new Date(year, month + 1, j);
            html += '<div class="mini-cal-day other-month" data-date="' + formatDateOnly(nd) + '">' + j + '</div>';
        }

        html += '</div>';
        container.innerHTML = html;

        // Bind mini-cal nav
        document.getElementById('miniPrev').addEventListener('click', function () {
            state.miniDate.setMonth(state.miniDate.getMonth() - 1);
            renderMiniCalendar();
        });

        document.getElementById('miniNext').addEventListener('click', function () {
            state.miniDate.setMonth(state.miniDate.getMonth() + 1);
            renderMiniCalendar();
        });

        // Bind day clicks
        container.querySelectorAll('.mini-cal-day').forEach(function (el) {
            el.addEventListener('click', function () {
                var dateStr = this.dataset.date;
                if (!dateStr) return;
                var parts = dateStr.split('-');
                state.currentDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
                state.miniDate = new Date(state.currentDate);
                renderMiniCalendar();
                if (isMobile()) renderMobileMiniCalendar();
                loadEvents();
            });
        });
    }

    function formatDateOnly(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    // ==================== Render Views ====================

    function renderView() {
        updateTitle();
        if (state.view === 'month') {
            renderMonthView();
        } else if (state.view === 'day') {
            renderDayGridView(1);
        } else if (state.view === '3day') {
            renderDayGridView(3);
        } else {
            renderWeekView();
        }
    }

    function updateTitle() {
        var title = document.getElementById('calTitle');
        if (state.view === 'month') {
            title.textContent = MONTH_NAMES[state.currentDate.getMonth()] + ' ' + state.currentDate.getFullYear();
        } else if (state.view === 'day') {
            var d = state.currentDate;
            var dow = (d.getDay() + 6) % 7;
            title.textContent = FULL_DAY_NAMES[dow] + ', ' + d.getDate() + '. ' +
                MONTH_NAMES[d.getMonth()] + ' ' + d.getFullYear();
        } else if (state.view === '3day') {
            var startD = new Date(state.currentDate);
            var endD = new Date(state.currentDate);
            endD.setDate(endD.getDate() + 2);
            if (startD.getMonth() === endD.getMonth()) {
                title.textContent = startD.getDate() + '. – ' + endD.getDate() + '. ' +
                    MONTH_NAMES[startD.getMonth()] + ' ' + startD.getFullYear();
            } else {
                title.textContent = startD.getDate() + '. ' + SHORT_MONTHS[startD.getMonth()] + ' – ' +
                    endD.getDate() + '. ' + SHORT_MONTHS[endD.getMonth()] + ' ' + endD.getFullYear();
            }
        } else {
            var range = getWeekRange();
            var kw = getISOWeekNumber(range.start);
            var kwPrefix = 'KW ' + kw + ' · ';
            if (range.start.getMonth() === range.end.getMonth()) {
                title.textContent = kwPrefix + range.start.getDate() + '. – ' + range.end.getDate() + '. ' +
                    MONTH_NAMES[range.start.getMonth()] + ' ' + range.start.getFullYear();
            } else {
                title.textContent = kwPrefix + range.start.getDate() + '. ' + SHORT_MONTHS[range.start.getMonth()] + ' – ' +
                    range.end.getDate() + '. ' + SHORT_MONTHS[range.end.getMonth()] + ' ' + range.end.getFullYear();
            }
        }
    }

    function getWeekRange() {
        var d = new Date(state.currentDate);
        var day = (d.getDay() + 6) % 7;
        var monday = new Date(d);
        monday.setDate(d.getDate() - day);
        var sunday = new Date(monday);
        sunday.setDate(monday.getDate() + 6);
        return { start: monday, end: sunday };
    }

    // ==================== Month View ====================

    function renderMonthView() {
        var grid = document.getElementById('monthGrid');
        var year = state.currentDate.getFullYear();
        var month = state.currentDate.getMonth();

        var first = new Date(year, month, 1);
        var startDay = (first.getDay() + 6) % 7;
        var last = new Date(year, month + 1, 0);

        var viewStart = new Date(year, month, 1 - startDay);

        // Berechne Anzahl Wochen
        var totalDays = startDay + last.getDate();
        var weeks = Math.ceil(totalDays / 7);

        grid.innerHTML = '';
        grid.style.gridTemplateRows = 'repeat(' + weeks + ', 1fr)';

        // Gruppiere Events nach Datum
        var eventsByDate = {};
        state.events.forEach(function (ev) {
            var evStart = new Date(ev.start);
            var evEnd = new Date(ev.end);

            if (ev.isAllDay) {
                // Ganztägige Events können mehrtägig sein
                var d = new Date(evStart);
                while (d < evEnd) {
                    var key = formatDateOnly(d);
                    if (!eventsByDate[key]) eventsByDate[key] = [];
                    eventsByDate[key].push(ev);
                    d.setDate(d.getDate() + 1);
                }
            } else {
                var key = formatDateOnly(evStart);
                if (!eventsByDate[key]) eventsByDate[key] = [];
                eventsByDate[key].push(ev);
            }
        });

        for (var i = 0; i < weeks * 7; i++) {
            var cellDate = new Date(viewStart);
            cellDate.setDate(viewStart.getDate() + i);

            var cell = document.createElement('div');
            cell.className = 'cal-month-cell';

            if (cellDate.getMonth() !== month) cell.classList.add('other-month');
            if (isToday(cellDate)) cell.classList.add('today');
            var dow = (cellDate.getDay() + 6) % 7;
            if (dow >= 5) cell.classList.add('weekend');

            var dayNum = document.createElement('div');
            dayNum.className = 'cal-month-day-number';
            dayNum.textContent = cellDate.getDate();
            cell.appendChild(dayNum);

            var eventsContainer = document.createElement('div');
            eventsContainer.className = 'cal-month-events';

            var dateKey = formatDateOnly(cellDate);
            var dayEvents = eventsByDate[dateKey] || [];

            // Sortiere: Ganztägig zuerst, dann nach Uhrzeit
            dayEvents.sort(function (a, b) {
                if (a.isAllDay && !b.isAllDay) return -1;
                if (!a.isAllDay && b.isAllDay) return 1;
                return new Date(a.start) - new Date(b.start);
            });

            var maxVisible = weeks <= 5 ? 3 : 2;
            var visibleEvents = dayEvents.slice(0, maxVisible);
            var overflowCount = dayEvents.length - maxVisible;

            visibleEvents.forEach(function (ev) {
                var evEl = document.createElement('div');
                evEl.className = 'cal-month-event';
                if (ev.isAllDay) evEl.classList.add('allday');
                evEl.style.backgroundColor = ev._color || 'var(--primary)';

                if (ev.isAllDay) {
                    evEl.textContent = ev.subject;
                } else {
                    evEl.innerHTML = '<span class="event-time">' + formatTime(ev.start) + '</span>' +
                        escapeHtml(ev.subject);
                }

                evEl.addEventListener('click', function (e) {
                    e.stopPropagation();
                    showEventPopup(ev);
                });

                eventsContainer.appendChild(evEl);
            });

            if (overflowCount > 0) {
                var more = document.createElement('div');
                more.className = 'cal-month-more';
                more.textContent = '+' + overflowCount + ' weitere';
                eventsContainer.appendChild(more);
            }

            cell.appendChild(eventsContainer);
            grid.appendChild(cell);
        }
    }

    // ==================== Week View ====================

    function renderWeekView() {
        var weekView = document.getElementById('weekView');
        weekView.setAttribute('data-cols', '7');

        var range = getWeekRange();
        var monday = range.start;

        // Header
        var header = document.getElementById('weekHeader');
        header.innerHTML = '<div class="cal-week-header-spacer"></div>';
        for (var i = 0; i < 7; i++) {
            var d = new Date(monday);
            d.setDate(monday.getDate() + i);
            var dayEl = document.createElement('div');
            dayEl.className = 'cal-week-header-day';
            if (isToday(d)) dayEl.classList.add('today');
            dayEl.innerHTML = '<div class="cal-week-day-name">' + DAY_NAMES[i] + '</div>' +
                '<div class="cal-week-day-number">' + d.getDate() + '</div>';
            header.appendChild(dayEl);
        }

        // Times
        var times = document.getElementById('weekTimes');
        times.innerHTML = '';
        for (var h = 0; h < 24; h++) {
            var timeEl = document.createElement('div');
            timeEl.className = 'cal-week-time';
            timeEl.textContent = pad(h) + ':00';
            times.appendChild(timeEl);
        }

        // Columns
        var columns = document.getElementById('weekColumns');
        columns.innerHTML = '';

        for (var i = 0; i < 7; i++) {
            var col = document.createElement('div');
            col.className = 'cal-week-column';

            // Hour lines
            for (var h = 0; h < 24; h++) {
                var line = document.createElement('div');
                line.className = 'cal-week-hour-line';
                col.appendChild(line);
            }

            // Events for this day
            var dayDate = new Date(monday);
            dayDate.setDate(monday.getDate() + i);

            var dayEvents = state.events.filter(function (ev) {
                if (ev.isAllDay) return false;
                var evDate = new Date(ev.start);
                return isSameDay(evDate, dayDate);
            });

            dayEvents.forEach(function (ev) {
                var evStart = new Date(ev.start);
                var evEnd = new Date(ev.end);

                var startMinutes = evStart.getHours() * 60 + evStart.getMinutes();
                var endMinutes = evEnd.getHours() * 60 + evEnd.getMinutes();
                if (endMinutes <= startMinutes) endMinutes = startMinutes + 30;

                var top = (startMinutes / 60) * 48;
                var height = ((endMinutes - startMinutes) / 60) * 48;
                if (height < 18) height = 18;

                var evEl = document.createElement('div');
                evEl.className = 'cal-week-event';
                evEl.style.top = top + 'px';
                evEl.style.height = height + 'px';
                evEl.style.backgroundColor = ev._color || 'var(--primary)';

                evEl.innerHTML = '<div class="cal-week-event-title">' + escapeHtml(ev.subject) + '</div>' +
                    '<div class="cal-week-event-time">' + formatTime(ev.start) + ' – ' + formatTime(ev.end) + '</div>';

                evEl.addEventListener('click', function (e) {
                    e.stopPropagation();
                    showEventPopup(ev);
                });

                col.appendChild(evEl);
            });

            // Now line
            if (isToday(dayDate)) {
                var now = new Date();
                var nowMinutes = now.getHours() * 60 + now.getMinutes();
                var nowTop = (nowMinutes / 60) * 48;
                var nowLine = document.createElement('div');
                nowLine.className = 'cal-now-line';
                nowLine.style.top = nowTop + 'px';
                col.appendChild(nowLine);
            }

            columns.appendChild(col);
        }

        // Scroll to 8:00
        var body = document.querySelector('.cal-week-body');
        if (body) {
            body.scrollTop = 8 * 48;
        }

        // Mobile: synchronisiere Header-Scroll mit Body-Scroll
        if (window.innerWidth <= 768) {
            var weekHeader = document.getElementById('weekHeader');
            body.addEventListener('scroll', function () {
                weekHeader.scrollLeft = body.scrollLeft;
            });

            // Scrolle zum heutigen Tag
            var todayIdx = -1;
            for (var ti = 0; ti < 7; ti++) {
                var td = new Date(monday);
                td.setDate(monday.getDate() + ti);
                if (isToday(td)) { todayIdx = ti; break; }
            }
            if (todayIdx > 0) {
                var scrollTarget = todayIdx * 120;
                body.scrollLeft = scrollTarget;
                weekHeader.scrollLeft = scrollTarget;
            }
        }
    }

    // ==================== Day/3-Day Grid View (Mobile) ====================

    function renderDayGridView(numDays) {
        var weekView = document.getElementById('weekView');
        weekView.setAttribute('data-cols', numDays);

        var startDate = new Date(state.currentDate);

        // Header
        var header = document.getElementById('weekHeader');
        header.innerHTML = '<div class="cal-week-header-spacer"></div>';
        for (var i = 0; i < numDays; i++) {
            var d = new Date(startDate);
            d.setDate(startDate.getDate() + i);
            var dow = (d.getDay() + 6) % 7;
            var dayEl = document.createElement('div');
            dayEl.className = 'cal-week-header-day';
            if (isToday(d)) dayEl.classList.add('today');
            dayEl.innerHTML = '<div class="cal-week-day-name">' + DAY_NAMES[dow] + '</div>' +
                '<div class="cal-week-day-number">' + d.getDate() + '</div>';
            header.appendChild(dayEl);
        }

        // Times
        var times = document.getElementById('weekTimes');
        times.innerHTML = '';
        for (var h = 0; h < 24; h++) {
            var timeEl = document.createElement('div');
            timeEl.className = 'cal-week-time';
            timeEl.textContent = pad(h) + ':00';
            times.appendChild(timeEl);
        }

        // Columns
        var columns = document.getElementById('weekColumns');
        columns.innerHTML = '';

        for (var i = 0; i < numDays; i++) {
            var col = document.createElement('div');
            col.className = 'cal-week-column';

            for (var h = 0; h < 24; h++) {
                var line = document.createElement('div');
                line.className = 'cal-week-hour-line';
                col.appendChild(line);
            }

            var dayDate = new Date(startDate);
            dayDate.setDate(startDate.getDate() + i);

            var dayEvents = state.events.filter(function (ev) {
                if (ev.isAllDay) return false;
                var evDate = new Date(ev.start);
                return isSameDay(evDate, dayDate);
            });

            dayEvents.forEach(function (ev) {
                var evStart = new Date(ev.start);
                var evEnd = new Date(ev.end);

                var startMinutes = evStart.getHours() * 60 + evStart.getMinutes();
                var endMinutes = evEnd.getHours() * 60 + evEnd.getMinutes();
                if (endMinutes <= startMinutes) endMinutes = startMinutes + 30;

                var top = (startMinutes / 60) * 48;
                var height = ((endMinutes - startMinutes) / 60) * 48;
                if (height < 18) height = 18;

                var evEl = document.createElement('div');
                evEl.className = 'cal-week-event';
                evEl.style.top = top + 'px';
                evEl.style.height = height + 'px';
                evEl.style.backgroundColor = ev._color || 'var(--primary)';

                evEl.innerHTML = '<div class="cal-week-event-title">' + escapeHtml(ev.subject) + '</div>' +
                    '<div class="cal-week-event-time">' + formatTime(ev.start) + ' – ' + formatTime(ev.end) + '</div>';

                evEl.addEventListener('click', function (e) {
                    e.stopPropagation();
                    showEventPopup(ev);
                });

                col.appendChild(evEl);
            });

            // Now line
            if (isToday(dayDate)) {
                var now = new Date();
                var nowMinutes = now.getHours() * 60 + now.getMinutes();
                var nowTop = (nowMinutes / 60) * 48;
                var nowLine = document.createElement('div');
                nowLine.className = 'cal-now-line';
                nowLine.style.top = nowTop + 'px';
                col.appendChild(nowLine);
            }

            columns.appendChild(col);
        }

        // Scroll to 8:00
        var body = document.querySelector('.cal-week-body');
        if (body) {
            body.scrollTop = 8 * 48;
        }
    }

    // ==================== Mobile Panel ====================

    function bindMobilePanel() {
        var toggle = document.getElementById('mobileBottomToggle');
        var panel = document.getElementById('mobilePanel');
        var overlay = document.getElementById('mobilePanelOverlay');
        var bar = document.getElementById('mobileBottomBar');
        if (!toggle || !panel || !overlay || !bar) return;

        var isOpen = false;

        function openPanel() {
            isOpen = true;
            panel.classList.add('open');
            overlay.classList.remove('hidden');
            overlay.classList.add('visible');
            bar.classList.add('open');
        }

        function closePanel() {
            isOpen = false;
            panel.classList.remove('open');
            overlay.classList.remove('visible');
            bar.classList.remove('open');
            setTimeout(function () {
                if (!panel.classList.contains('open')) {
                    overlay.classList.add('hidden');
                }
            }, 300);
        }

        function togglePanel() {
            if (isOpen) { closePanel(); } else { openPanel(); }
        }

        // Tap auf Toggle-Button
        toggle.addEventListener('click', togglePanel);

        // Tap auf Overlay schließt Panel
        overlay.addEventListener('click', closePanel);

        // Swipe-up auf Bottom-Bar öffnet, Swipe-down auf Panel schließt
        var touchStartY = 0;
        bar.addEventListener('touchstart', function (e) {
            touchStartY = e.touches[0].clientY;
        }, { passive: true });
        bar.addEventListener('touchend', function (e) {
            var diff = touchStartY - e.changedTouches[0].clientY;
            if (diff > 30 && !isOpen) openPanel();
        });

        panel.addEventListener('touchstart', function (e) {
            touchStartY = e.touches[0].clientY;
        }, { passive: true });
        panel.addEventListener('touchend', function (e) {
            var diff = e.changedTouches[0].clientY - touchStartY;
            if (diff > 50 && isOpen) closePanel();
        });
    }

    function renderMobileMiniCalendar() {
        var container = document.getElementById('miniCalendarMobile');
        if (!container) return;
        var d = state.miniDate || state.currentDate;
        var year = d.getFullYear();
        var month = d.getMonth();

        var html = '<div class="mini-cal-nav">' +
            '<button id="miniPrevMobile">&#9664;</button>' +
            '<span class="mini-cal-title">' + SHORT_MONTHS[month] + ' ' + year + '</span>' +
            '<button id="miniNextMobile">&#9654;</button>' +
            '</div>';

        html += '<div class="mini-cal-grid">';
        DAY_NAMES.forEach(function (dn) {
            html += '<div class="mini-cal-weekday">' + dn + '</div>';
        });

        var first = new Date(year, month, 1);
        var startDay = (first.getDay() + 6) % 7;
        var last = new Date(year, month + 1, 0);

        for (var i = startDay - 1; i >= 0; i--) {
            var pd = new Date(year, month, -i);
            html += '<div class="mini-cal-day other-month" data-date="' + formatDateOnly(pd) + '">' + pd.getDate() + '</div>';
        }

        for (var day = 1; day <= last.getDate(); day++) {
            var cd = new Date(year, month, day);
            var classes = 'mini-cal-day';
            if (isToday(cd)) classes += ' today';
            if (isSameDay(cd, state.currentDate)) classes += ' selected';
            html += '<div class="' + classes + '" data-date="' + formatDateOnly(cd) + '">' + day + '</div>';
        }

        var endDay = (last.getDay() + 6) % 7;
        for (var j = 1; j <= 6 - endDay; j++) {
            var nd = new Date(year, month + 1, j);
            html += '<div class="mini-cal-day other-month" data-date="' + formatDateOnly(nd) + '">' + j + '</div>';
        }

        html += '</div>';
        container.innerHTML = html;

        document.getElementById('miniPrevMobile').addEventListener('click', function () {
            state.miniDate.setMonth(state.miniDate.getMonth() - 1);
            renderMobileMiniCalendar();
        });

        document.getElementById('miniNextMobile').addEventListener('click', function () {
            state.miniDate.setMonth(state.miniDate.getMonth() + 1);
            renderMobileMiniCalendar();
        });

        container.querySelectorAll('.mini-cal-day').forEach(function (el) {
            el.addEventListener('click', function () {
                var dateStr = this.dataset.date;
                if (!dateStr) return;
                var parts = dateStr.split('-');
                state.currentDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
                state.miniDate = new Date(state.currentDate);
                renderMiniCalendar();
                renderMobileMiniCalendar();
                loadEvents();
            });
        });
    }

    function renderMobileSourcesList() {
        var container = document.getElementById('calSourcesListMobile');
        if (!container) return;
        container.innerHTML = '';

        if (state.sources.length === 0) {
            container.innerHTML = '<div class="cal-loading">Keine Kalender konfiguriert.</div>';
            return;
        }

        state.sources.forEach(function (src) {
            if (!src.connected || src.calendars.length === 0) return;

            var group = document.createElement('div');
            group.className = 'cal-source-group';

            var label = document.createElement('div');
            label.className = 'cal-source-group-label';
            label.innerHTML = '<span class="cal-source-type-icon">' +
                (src.type === 'microsoft' ? 'M365' : 'Google') + '</span>';
            group.appendChild(label);

            src.calendars.forEach(function (cal) {
                var key = src.id + ':' + cal.id;
                var calData = state.selectedCalendars.get(key);
                if (!calData) return;

                var item = document.createElement('label');
                item.className = 'cal-source-item';

                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'cal-source-checkbox';
                checkbox.checked = calData.checked;
                checkbox.addEventListener('change', function () {
                    calData.checked = this.checked;
                    saveCalendarSelection();
                    loadEvents();
                });

                var colorDot = document.createElement('span');
                colorDot.className = 'cal-source-color';
                colorDot.style.backgroundColor = calData.color;

                var name = document.createElement('span');
                name.className = 'cal-source-name';
                name.textContent = cal.name;

                item.appendChild(checkbox);
                item.appendChild(colorDot);
                item.appendChild(name);
                group.appendChild(item);
            });

            container.appendChild(group);
        });
    }

    // ==================== Event Popup ====================

    function bindPopup() {
        document.getElementById('eventPopupOverlay').addEventListener('click', function (e) {
            if (e.target === this) closePopup();
        });
        document.getElementById('popupClose').addEventListener('click', closePopup);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closePopup();
        });
    }

    function showEventPopup(ev) {
        var title = window.CAL_CONFIG.showEventTitle ? (ev.subject || '(Kein Titel)') : 'Belegt';
        document.getElementById('popupTitle').textContent = title;

        var timeStr = '';
        if (ev.isAllDay) {
            timeStr = '<span class="popup-icon">&#128197;</span> Ganztägig';
        } else {
            var startD = new Date(ev.start);
            var endD = new Date(ev.end);
            var dateStr = startD.getDate() + '. ' + MONTH_NAMES[startD.getMonth()] + ' ' + startD.getFullYear();
            timeStr = '<span class="popup-icon">&#128339;</span> ' + dateStr +
                ', ' + formatTime(ev.start) + ' – ' + formatTime(ev.end);
        }
        document.getElementById('popupTime').innerHTML = timeStr;

        var locEl = document.getElementById('popupLocation');
        if (ev.location) {
            locEl.innerHTML = '<span class="popup-icon">&#128205;</span> ' + escapeHtml(ev.location);
        } else {
            locEl.innerHTML = '';
        }

        var statusEl = document.getElementById('popupStatus');
        var statusMap = {
            'busy': 'Gebucht',
            'tentative': 'Unter Vorbehalt',
            'free': 'Frei',
            'oof': 'Abwesend',
        };
        if (ev.showAs) {
            statusEl.innerHTML = '<span class="popup-icon">&#128310;</span> ' + (statusMap[ev.showAs] || ev.showAs);
        } else {
            statusEl.innerHTML = '';
        }

        var calEl = document.getElementById('popupCalendar');
        if (ev._calendarName) {
            calEl.innerHTML = '<span class="popup-icon" style="color:' + escapeHtml(ev._color || '#2563eb') + '">&#9632;</span> ' +
                escapeHtml(ev._calendarName);
        } else {
            calEl.innerHTML = '';
        }

        document.getElementById('eventPopupOverlay').classList.remove('hidden');
    }

    function closePopup() {
        document.getElementById('eventPopupOverlay').classList.add('hidden');
    }

    // ==================== Calendar Selection Persistence ====================

    var STORAGE_KEY = 'cal_selected_calendars';

    function saveCalendarSelection() {
        var selection = {};
        state.selectedCalendars.forEach(function (cal, key) {
            selection[key] = cal.checked;
        });
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(selection));
        } catch (e) { /* ignore */ }
    }

    function restoreCalendarSelection() {
        try {
            var saved = localStorage.getItem(STORAGE_KEY);
            if (!saved) return;
            var selection = JSON.parse(saved);
            state.selectedCalendars.forEach(function (cal, key) {
                if (key in selection) {
                    cal.checked = selection[key];
                }
            });
        } catch (e) { /* ignore */ }
    }

    // ==================== Utility ====================

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})();
