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

        var toolbar = el('div', 'ib-toolbar');
        var title = el('h1', 'ib-title');
        title.textContent = 'Instrument Booking System';
        toolbar.appendChild(title);

        var controls = el('div', 'ib-toolbar-controls');
        var selectWrap = el('label', 'ib-field-inline');
        selectWrap.appendChild(text('Instrument'));
        var select = el('select', 'ib-instrument-select');
        select.addEventListener('change', function () {
            state.selectedInstrument = select.value;
            if (state.calendar) {
                state.calendar.refetchEvents();
            }
        });
        selectWrap.appendChild(select);
        controls.appendChild(selectWrap);

        var timezone = el('span', 'ib-timezone');
        controls.appendChild(timezone);
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
        var select = root.querySelector('.ib-instrument-select');
        select.textContent = '';
        state.instruments.forEach(function (instrument) {
            var option = document.createElement('option');
            option.value = instrument.code;
            option.textContent = instrument.name + (instrument.enabled ? '' : ' (disabled)');
            select.appendChild(option);
        });
        select.value = state.selectedInstrument;
        root.querySelector('.ib-timezone').textContent = 'Lab timezone: ' + state.timezone;
    }

    function initCalendar(root, state) {
        var calendarEl = root.querySelector('.ib-calendar');
        state.calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            timeZone: state.timezone,
            selectable: true,
            editable: false,
            nowIndicator: true,
            height: 'auto',
            slotMinTime: '06:00:00',
            slotMaxTime: '22:00:00',
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
                    start: selection.startStr,
                    end: selection.endStr,
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
                    start: info.startStr,
                    end: info.endStr
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
