(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var root = document.getElementById('instrument-booking-app');
        if (!root) {
            return;
        }

        document.body.classList.add('instrument-booking-page');
        root.setAttribute('data-theme', 'midnight-lab');

        loadFullCalendar(root).then(function () {
            boot(root);
        }).catch(function () {
            showFatal(root, 'Unable to load the local FullCalendar file. Check the plugin vendor/fullcalendar directory.');
        });
    });

    function loadFullCalendar(root) {
        if (window.FullCalendar) {
            return Promise.resolve();
        }
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = root.getAttribute('data-fullcalendar-js');
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    function boot(root) {
        var state = {
            ajaxUrl: root.getAttribute('data-ajax-url'),
            sectok: root.getAttribute('data-sectok'),
            timezone: 'UTC',
            isManager: false,
            instruments: [],
            selectedInstrument: null,
            calendar: null,
            saving: false,
            statusLocked: false,
            root: root
        };

        root.textContent = '';
        root.appendChild(buildShell(state));
        fetchInstruments(state).then(function (data) {
            state.timezone = data.timezone;
            state.isManager = !!data.isManager;
            state.instruments = data.instruments || [];
            if (state.instruments.length === 0) {
                showStatus(root, 'No instruments are available. Confirm that you are logged in and belong to the required DokuWiki group.', true);
                return;
            }
            state.selectedInstrument = state.instruments[0].code;
            refreshInstrumentSelect(root, state);
            initCalendar(root, state);
            showStatus(root, '', false);
        }).catch(function (err) {
            showStatus(root, err.message || 'Failed to load instruments.', true);
        });
    }

    function buildShell(state) {
        var shell = el('div', 'ib-shell');

        var appBar = el('div', 'ib-app-bar');
        var identity = el('div', 'ib-app-identity');
        var appTitle = el('h1', 'ib-app-title');
        appTitle.textContent = 'TRSys';
        var subtitle = el('p', 'ib-app-subtitle');
        subtitle.textContent = 'Tool Reservation System';
        identity.appendChild(appTitle);
        identity.appendChild(subtitle);
        appBar.appendChild(identity);

        var appNav = el('nav', 'ib-app-nav');
        appNav.setAttribute('aria-label', 'Application navigation');
        var wikiLink = el('a', 'ib-app-link');
        wikiLink.href = wikiUrl(state.ajaxUrl);
        wikiLink.textContent = 'Return to Lab Wiki';
        appNav.appendChild(wikiLink);
        appBar.appendChild(appNav);
        shell.appendChild(appBar);

        var toolbar = el('div', 'ib-toolbar');
        var controls = el('div', 'ib-toolbar-controls');
        var selectWrap = el('div', 'ib-field-inline');
        var pickerLabel = el('span', 'ib-instrument-label');
        pickerLabel.textContent = 'Instrument';
        selectWrap.appendChild(pickerLabel);

        var picker = el('div', 'ib-instrument-picker');
        var trigger = button('button', '');
        trigger.className = 'ib-instrument-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', 'ib-instrument-menu');
        trigger.setAttribute('aria-labelledby', 'ib-instrument-label ib-instrument-current');
        var current = el('span', 'ib-instrument-current');
        current.id = 'ib-instrument-current';
        var chevron = el('span', 'ib-instrument-chevron');
        chevron.setAttribute('aria-hidden', 'true');
        trigger.appendChild(current);
        trigger.appendChild(chevron);
        picker.appendChild(trigger);

        var menu = el('div', 'ib-instrument-menu');
        menu.id = 'ib-instrument-menu';
        menu.setAttribute('role', 'listbox');
        menu.setAttribute('aria-labelledby', 'ib-instrument-label');
        menu.hidden = true;
        picker.appendChild(menu);
        pickerLabel.id = 'ib-instrument-label';
        selectWrap.appendChild(picker);
        controls.appendChild(selectWrap);

        trigger.addEventListener('click', function () {
            setInstrumentPickerOpen(root, !isInstrumentPickerOpen(root), true);
        });
        trigger.addEventListener('keydown', function (event) {
            handleInstrumentTriggerKeydown(event, root);
        });
        document.addEventListener('click', function (event) {
            if (isInstrumentPickerOpen(root) && !picker.contains(event.target)) {
                setInstrumentPickerOpen(root, false, false);
            }
        });

        toolbar.appendChild(controls);
        shell.appendChild(toolbar);

        var status = el('div', 'ib-status');
        status.setAttribute('role', 'status');
        shell.appendChild(status);

        var calendar = el('div', 'ib-calendar');
        shell.appendChild(calendar);

        shell.appendChild(buildDialog(state));
        return shell;
    }

    function refreshInstrumentSelect(root, state) {
        var menu = root.querySelector('.ib-instrument-menu');
        var current = root.querySelector('.ib-instrument-current');
        menu.textContent = '';
        state.instruments.forEach(function (instrument, index) {
            var option = button('button', instrumentLabel(instrument));
            option.className = 'ib-instrument-option';
            option.id = 'ib-instrument-option-' + index;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', instrument.code === state.selectedInstrument ? 'true' : 'false');
            option.setAttribute('data-instrument-code', instrument.code);
            option.addEventListener('click', function () {
                selectInstrument(root, state, instrument.code);
            });
            option.addEventListener('keydown', function (event) {
                handleInstrumentOptionKeydown(event, root, state);
            });
            menu.appendChild(option);
        });

        var selected = findInstrument(state, state.selectedInstrument);
        current.textContent = selected ? instrumentLabel(selected) : '';
    }

    function instrumentLabel(instrument) {
        return instrument.name + (instrument.enabled ? '' : ' (disabled)');
    }

    function selectInstrument(root, state, instrumentCode) {
        state.selectedInstrument = instrumentCode;
        refreshInstrumentSelect(root, state);
        setInstrumentPickerOpen(root, false, true);
        if (state.calendar) {
            state.calendar.refetchEvents();
        }
    }

    function isInstrumentPickerOpen(root) {
        return root.querySelector('.ib-instrument-trigger').getAttribute('aria-expanded') === 'true';
    }

    function setInstrumentPickerOpen(root, open, returnFocus) {
        var trigger = root.querySelector('.ib-instrument-trigger');
        var menu = root.querySelector('.ib-instrument-menu');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        menu.hidden = !open;

        if (open) {
            var selected = menu.querySelector('[aria-selected="true"]');
            var first = menu.querySelector('.ib-instrument-option');
            window.requestAnimationFrame(function () {
                (selected || first || trigger).focus();
            });
        } else if (returnFocus) {
            trigger.focus();
        }
    }

    function handleInstrumentTriggerKeydown(event, root) {
        if (!['Enter', ' ', 'ArrowDown', 'ArrowUp', 'Home', 'End', 'Escape'].includes(event.key)) {
            return;
        }
        event.preventDefault();

        if (event.key === 'Escape') {
            setInstrumentPickerOpen(root, false, true);
            return;
        }

        setInstrumentPickerOpen(root, true, false);
        var menu = root.querySelector('.ib-instrument-menu');
        var options = instrumentOptions(menu);
        var target = menu.querySelector('[aria-selected="true"]') || options[0];
        if (event.key === 'ArrowUp' || event.key === 'End') {
            target = options[options.length - 1];
        } else if (event.key === 'Home' || event.key === 'ArrowDown') {
            target = options[0];
        }
        if (target) {
            window.requestAnimationFrame(function () {
                target.focus();
            });
        }
    }

    function handleInstrumentOptionKeydown(event, root, state) {
        var option = event.currentTarget;
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            selectInstrument(root, state, option.getAttribute('data-instrument-code'));
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            setInstrumentPickerOpen(root, false, true);
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        var options = instrumentOptions(option.parentNode);
        var index = options.indexOf(option);
        if (event.key === 'Home') {
            index = 0;
        } else if (event.key === 'End') {
            index = options.length - 1;
        } else if (event.key === 'ArrowDown') {
            index = (index + 1) % options.length;
        } else {
            index = (index - 1 + options.length) % options.length;
        }
        options[index].focus();
    }

    function instrumentOptions(menu) {
        return Array.prototype.slice.call(menu.querySelectorAll('.ib-instrument-option'));
    }

    function initCalendar(root, state) {
        var calendarEl = root.querySelector('.ib-calendar');
        state.calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            timeZone: state.timezone,
            selectable: true,
            editable: false,
            nowIndicator: true,
            allDaySlot: false,
            height: 'auto',
            slotMinTime: '00:00:00',
            slotMaxTime: '24:00:00',
            slotLabelFormat: {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            },
            eventTimeFormat: {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            },
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'timeGridWeek,timeGridDay'
            },
            buttonText: {
                today: 'Today',
                week: 'Week',
                day: 'Day'
            },
            select: function (selection) {
                openDialog(root, state, {
                    mode: 'create',
                    instrumentCode: state.selectedInstrument,
                    start: ensureExplicitOffset(selection.startStr, state.timezone),
                    end: ensureExplicitOffset(selection.endStr, state.timezone),
                    title: '',
                    note: '',
                    eventType: 'booking',
                    canEdit: true,
                    canCancel: false
                });
            },
            eventClick: function (info) {
                var event = info.event.extendedProps.bookingEvent;
                openDialog(root, state, Object.assign({ mode: 'view' }, event));
            },
            events: function (info, success, failure) {
                api(state, 'GET', 'events', {
                    instrumentCode: state.selectedInstrument,
                    start: ensureExplicitOffset(info.startStr, state.timezone),
                    end: ensureExplicitOffset(info.endStr, state.timezone)
                }).then(function (data) {
                    var instrument = findInstrument(state, state.selectedInstrument);
                    var color = instrument ? instrument.color : '#64748b';
                    success((data.events || []).map(function (event) {
                        var eventColor = event.eventType === 'block' ? '#b45309' : color;
                        return {
                            id: String(event.id),
                            title: event.title,
                            start: event.start,
                            end: event.end,
                            backgroundColor: hexToRgba(eventColor, 0.38),
                            borderColor: eventColor,
                            textColor: '#ffffff',
                            extendedProps: {
                                bookingEvent: event
                            }
                        };
                    }));
                }).catch(function (err) {
                    state.statusLocked = true;
                    showStatus(root, err.message || 'Failed to load bookings.', true);
                    failure(err);
                });
            },
            loading: function (isLoading) {
                if (isLoading) {
                    state.statusLocked = false;
                    showStatus(root, 'Loading bookings...', false);
                } else if (!state.statusLocked) {
                    showStatus(root, '', false);
                }
            }
        });
        state.calendar.render();
    }

    function buildDialog(state) {
        var overlay = el('div', 'ib-dialog-overlay');
        overlay.hidden = true;

        var dialog = el('div', 'ib-dialog');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');

        var heading = el('h3', 'ib-dialog-title');
        dialog.appendChild(heading);

        var form = el('form', 'ib-form');
        form.appendChild(field('title', 'Title', 'text', 120));
        form.appendChild(field('start', 'Start time', 'datetime-local', 64));
        form.appendChild(field('end', 'End time', 'datetime-local', 64));

        var typeWrap = el('label', 'ib-field ib-event-type-field');
        typeWrap.appendChild(text('Type'));
        var typeSelect = el('select', 'ib-input');
        typeSelect.name = 'eventType';
        [['booking', 'Booking'], ['block', 'Maintenance/outage']].forEach(function (item) {
            var option = document.createElement('option');
            option.value = item[0];
            option.textContent = item[1];
            typeSelect.appendChild(option);
        });
        typeWrap.appendChild(typeSelect);
        form.appendChild(typeWrap);

        var noteWrap = el('label', 'ib-field');
        noteWrap.appendChild(text('Note'));
        var note = document.createElement('textarea');
        note.className = 'ib-input';
        note.name = 'note';
        note.maxLength = 1000;
        note.rows = 4;
        noteWrap.appendChild(note);
        form.appendChild(noteWrap);

        var message = el('p', 'ib-dialog-message');
        form.appendChild(message);

        var buttons = el('div', 'ib-dialog-buttons');
        var closeButton = button('button', '×');
        closeButton.className += ' ib-close';
        closeButton.setAttribute('aria-label', 'Close');
        closeButton.addEventListener('click', function () {
            overlay.hidden = true;
        });
        var cancelButton = button('button', 'Cancel booking');
        cancelButton.className += ' ib-danger';
        var submitButton = button('submit', 'Save');
        buttons.appendChild(cancelButton);
        buttons.appendChild(closeButton);
        buttons.appendChild(submitButton);
        form.appendChild(buttons);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitDialog(state, overlay);
        });
        cancelButton.addEventListener('click', function () {
            cancelEvent(state, overlay);
        });

        dialog.appendChild(form);
        overlay.appendChild(dialog);
        return overlay;
    }

    function openDialog(root, state, eventData) {
        var overlay = root.querySelector('.ib-dialog-overlay');
        overlay._eventData = eventData;
        var isCreate = eventData.mode === 'create';
        var canEdit = isCreate || eventData.canEdit;
        var title = overlay.querySelector('.ib-dialog-title');
        title.textContent = isCreate ? 'Create booking' : (canEdit ? 'Edit booking' : 'View reservation');

        setValue(overlay, 'title', eventData.title || '');
        setValue(overlay, 'start', toDatetimeInput(eventData.start, state.timezone));
        setValue(overlay, 'end', toDatetimeInput(eventData.end, state.timezone));
        setValue(overlay, 'eventType', eventData.eventType || 'booking');
        setValue(overlay, 'note', eventData.note || '');

        overlay.querySelector('.ib-event-type-field').hidden = !state.isManager;
        overlay.querySelector('[name="eventType"]').disabled = !state.isManager || !canEdit;
        overlay.querySelector('[name="title"]').disabled = !canEdit;
        overlay.querySelector('[name="start"]').disabled = !canEdit;
        overlay.querySelector('[name="end"]').disabled = !canEdit;
        overlay.querySelector('[name="note"]').disabled = !canEdit;
        overlay.querySelector('[type="submit"]').hidden = !canEdit;
        overlay.querySelector('.ib-danger').hidden = !(eventData.canCancel && !isCreate);
        overlay.querySelector('.ib-dialog-message').textContent = canEdit
            ? 'Times are entered in the lab timezone. Conflicts and permissions are checked again before saving.'
            : 'Bookings owned by other users only show reserved time.';
        overlay.hidden = false;
    }

    function submitDialog(state, overlay) {
        if (state.saving) {
            return;
        }
        var eventData = overlay._eventData;
        var isCreate = eventData.mode === 'create';
        var payload = {
            instrumentCode: state.selectedInstrument,
            title: getValue(overlay, 'title'),
            start: fromDatetimeInput(getValue(overlay, 'start'), state.timezone),
            end: fromDatetimeInput(getValue(overlay, 'end'), state.timezone),
            eventType: state.isManager ? getValue(overlay, 'eventType') : 'booking',
            note: getValue(overlay, 'note')
        };
        if (isCreate) {
            payload.requestId = uuid();
        } else {
            payload.eventId = eventData.id;
        }

        setSaving(state, overlay, true);
        state.statusLocked = false;
        showStatus(state.root, 'Saving booking...', false);
        api(state, 'POST', isCreate ? 'create' : 'update', payload).then(function () {
            overlay.hidden = true;
            state.calendar.refetchEvents();
            showTransientStatus(state, 'Booking saved.');
        }).catch(function (err) {
            overlay.querySelector('.ib-dialog-message').textContent = err.message || 'Save failed.';
            state.statusLocked = true;
            showStatus(state.root, err.message || 'Save failed.', true);
        }).finally(function () {
            setSaving(state, overlay, false);
        });
    }

    function cancelEvent(state, overlay) {
        if (state.saving || !overlay._eventData || !overlay._eventData.id) {
            return;
        }
        if (!window.confirm('Cancel this booking?')) {
            return;
        }
        setSaving(state, overlay, true);
        state.statusLocked = false;
        showStatus(state.root, 'Cancelling booking...', false);
        api(state, 'POST', 'cancel', { eventId: overlay._eventData.id }).then(function () {
            overlay.hidden = true;
            state.calendar.refetchEvents();
            showTransientStatus(state, 'Booking cancelled.');
        }).catch(function (err) {
            overlay.querySelector('.ib-dialog-message').textContent = err.message || 'Cancel failed.';
            state.statusLocked = true;
            showStatus(state.root, err.message || 'Cancel failed.', true);
        }).finally(function () {
            setSaving(state, overlay, false);
        });
    }

    function api(state, method, operation, data) {
        var url = new URL(state.ajaxUrl, window.location.href);
        url.searchParams.set('operation', operation);
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {}
        };

        if (method === 'GET') {
            Object.keys(data || {}).forEach(function (key) {
                url.searchParams.set(key, data[key]);
            });
        } else {
            url.searchParams.set('sectok', state.sectok);
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data || {});
        }

        return fetch(url.toString(), options).then(function (response) {
            return response.json().catch(function () {
                throw new Error('The server returned an invalid response.');
            }).then(function (payload) {
                if (!response.ok || !payload.ok) {
                    var error = payload && payload.error ? payload.error : {};
                    throw new Error(error.message || 'Request failed.');
                }
                return payload.data || {};
            });
        });
    }

    function fetchInstruments(state) {
        return api(state, 'GET', 'instruments', {});
    }

    function wikiUrl(ajaxUrl) {
        var url = new URL(ajaxUrl, window.location.href);
        url.pathname = url.pathname.replace(/lib\/exe\/ajax\.php$/, 'doku.php');
        url.search = '';
        url.hash = '';
        return url.toString();
    }

    function setSaving(state, overlay, saving) {
        state.saving = saving;
        overlay.querySelectorAll('button').forEach(function (btn) {
            btn.disabled = saving;
        });
        overlay.querySelector('[type="submit"]').textContent = saving ? 'Saving...' : 'Save';
    }

    function findInstrument(state, code) {
        for (var i = 0; i < state.instruments.length; i += 1) {
            if (state.instruments[i].code === code) {
                return state.instruments[i];
            }
        }
        return null;
    }

    function hexToRgba(color, alpha) {
        var value = String(color || '').trim();
        var match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value);
        if (!match) {
            return 'rgba(100, 116, 139, ' + alpha + ')';
        }
        var hex = match[1];
        if (hex.length === 3) {
            hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }
        return 'rgba('
            + parseInt(hex.slice(0, 2), 16) + ', '
            + parseInt(hex.slice(2, 4), 16) + ', '
            + parseInt(hex.slice(4, 6), 16) + ', '
            + alpha + ')';
    }

    function ensureExplicitOffset(value, timezone) {
        if (/(Z|[+-]\d{2}:\d{2})$/i.test(value)) {
            return value;
        }

        var match = /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(?::\d{2}(?:\.\d+)?)?$/.exec(value);
        if (!match) {
            return value;
        }

        return fromDatetimeInput(match[1], timezone);
    }

    function toDatetimeInput(iso, timezone) {
        var parts = zonedParts(new Date(iso), timezone);
        return parts.year + '-' + parts.month + '-' + parts.day + 'T' + parts.hour + ':' + parts.minute;
    }

    function fromDatetimeInput(value, timezone) {
        var match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
        if (!match) {
            return value;
        }
        var y = Number(match[1]);
        var m = Number(match[2]);
        var d = Number(match[3]);
        var h = Number(match[4]);
        var min = Number(match[5]);
        var guess = Date.UTC(y, m - 1, d, h, min, 0);
        var offset = offsetMinutes(timezone, new Date(guess));
        var utc = guess - offset * 60000;
        var refinedOffset = offsetMinutes(timezone, new Date(utc));
        if (refinedOffset !== offset) {
            utc = guess - refinedOffset * 60000;
            offset = refinedOffset;
        }
        var sign = offset >= 0 ? '+' : '-';
        var abs = Math.abs(offset);
        return match[1] + '-' + match[2] + '-' + match[3] + 'T' + match[4] + ':' + match[5] + ':00'
            + sign + pad(Math.floor(abs / 60)) + ':' + pad(abs % 60);
    }

    function offsetMinutes(timezone, date) {
        var parts = zonedParts(date, timezone);
        var asUtc = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour), Number(parts.minute), Number(parts.second));
        return Math.round((asUtc - date.getTime()) / 60000);
    }

    function zonedParts(date, timezone) {
        var formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            hour12: false,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        var result = {};
        formatter.formatToParts(date).forEach(function (part) {
            if (part.type !== 'literal') {
                result[part.type] = part.value;
            }
        });
        if (result.hour === '24') {
            result.hour = '00';
        }
        return result;
    }

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, function (c) {
            return (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16);
        });
    }

    function field(name, label, type, maxLength) {
        var wrap = el('label', 'ib-field');
        wrap.appendChild(text(label));
        var input = document.createElement('input');
        input.className = 'ib-input';
        input.name = name;
        input.type = type;
        input.maxLength = maxLength;
        input.required = true;
        wrap.appendChild(input);
        return wrap;
    }

    function setValue(root, name, value) {
        root.querySelector('[name="' + name + '"]').value = value;
    }

    function getValue(root, name) {
        return root.querySelector('[name="' + name + '"]').value;
    }

    function showStatus(root, message, isError) {
        var status = root.querySelector('.ib-status');
        if (!status) {
            return;
        }
        status.textContent = message;
        status.classList.toggle('ib-status-error', !!isError);
    }

    function showTransientStatus(state, message) {
        state.statusLocked = true;
        showStatus(state.root, message, false);
        window.setTimeout(function () {
            state.statusLocked = false;
            showStatus(state.root, '', false);
        }, 3000);
    }

    function showFatal(root, message) {
        root.textContent = '';
        var node = el('div', 'ib-status ib-status-error');
        node.textContent = message;
        root.appendChild(node);
    }

    function el(tag, className) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        return node;
    }

    function text(value) {
        return document.createTextNode(value);
    }

    function button(type, label) {
        var btn = document.createElement('button');
        btn.type = type;
        btn.textContent = label;
        return btn;
    }

    function pad(value) {
        return String(value).padStart(2, '0');
    }
})();
