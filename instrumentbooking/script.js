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
            sectok: '',
            timezone: 'UTC',
            isAdmin: false,
            instruments: [],
            selectedInstrument: null,
            calendar: null,
            saving: false,
            statusLocked: false,
            weekNowLineTimer: null,
            weekNowLineFrame: null,
            weekNowLineResizeHandler: null,
            weekNowLinePageHideHandler: null,
            updatedDate: root.getAttribute('data-updated-date') || '',
            root: root
        };

        root.textContent = '';
        root.appendChild(buildShell(state));
        fetchInstruments(state).then(function (data) {
            updateSecurityToken(state, data);
            state.timezone = data.timezone;
            state.isAdmin = data.isAdmin === true;
            state.instruments = data.instruments || [];
            root.querySelector('.ib-settings-button').hidden = !state.isAdmin;
            if (data.migrationRequired) {
                showStatus(root, data.migrationMessage || 'The booking database must be migrated.', true);
                return;
            }
            if (state.instruments.length === 0) {
                showStatus(
                    root,
                    state.isAdmin
                        ? 'No instruments are configured. Open Settings to create one.'
                        : 'No instruments are available. Contact an administrator.',
                    true
                );
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
        var titleRow = el('div', 'ib-app-title-row');
        var appTitle = el('h1', 'ib-app-title');
        appTitle.textContent = 'TRSys';
        titleRow.appendChild(appTitle);
        if (/^\d{4}-\d{2}-\d{2}$/.test(state.updatedDate)) {
            var updated = el('time', 'ib-app-updated');
            updated.setAttribute('datetime', state.updatedDate);
            updated.textContent = 'Last updated: ' + state.updatedDate;
            titleRow.appendChild(updated);
        }
        var subtitle = el('p', 'ib-app-subtitle');
        subtitle.textContent = 'Tool Reservation System';
        identity.appendChild(titleRow);
        identity.appendChild(subtitle);
        appBar.appendChild(identity);

        var appNav = el('nav', 'ib-app-nav');
        appNav.setAttribute('aria-label', 'Application navigation');
        var wikiLink = el('a', 'ib-app-link');
        wikiLink.href = wikiUrl(state.ajaxUrl);
        wikiLink.textContent = 'Return to Lab Wiki';
        appNav.appendChild(wikiLink);
        var settingsButton = button('button', 'Settings');
        settingsButton.className = 'ib-app-link ib-settings-button';
        settingsButton.hidden = true;
        settingsButton.addEventListener('click', function () {
            openSettings(state);
        });
        appNav.appendChild(settingsButton);
        appBar.appendChild(appNav);
        shell.appendChild(appBar);

        var toolbar = el('div', 'ib-toolbar');
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

        toolbar.appendChild(controls);
        shell.appendChild(toolbar);

        var status = el('div', 'ib-status');
        status.setAttribute('role', 'status');
        shell.appendChild(status);

        var calendar = el('div', 'ib-calendar');
        shell.appendChild(calendar);

        shell.appendChild(buildDialog(state));
        shell.appendChild(buildSettingsDialog(state));
        return shell;
    }

    function refreshInstrumentSelect(root, state) {
        var select = root.querySelector('.ib-instrument-select');
        select.textContent = '';
        state.instruments.forEach(function (instrument) {
            var option = document.createElement('option');
            option.value = instrument.code;
            option.textContent = instrument.name;
            select.appendChild(option);
        });
        select.value = state.selectedInstrument;
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
            slotDuration: '00:30:00',
            snapDuration: '00:30:00',
            slotLabelInterval: '01:00:00',
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
                right: ''
            },
            buttonText: {
                today: 'Today'
            },
            datesSet: function (info) {
                updateWeekTitle(calendarEl, formatWeekTitle(info.startStr, info.endStr, state.timezone));
                scheduleWeekNowLines(root, state);
            },
            viewWillUnmount: function () {
                clearWeekNowLines(root);
            },
            select: function (selection) {
                var selectedDuration = selection.end.getTime() - selection.start.getTime();
                var endIso = selectedDuration <= 30 * 60 * 1000
                    ? fromDatetimeInput(
                        toDatetimeInput(
                            new Date(selection.start.getTime() + 60 * 60 * 1000).toISOString(),
                            state.timezone
                        ),
                        state.timezone
                    )
                    : ensureExplicitOffset(selection.endStr, state.timezone);
                openDialog(root, state, {
                    mode: 'create',
                    instrumentCode: state.selectedInstrument,
                    start: ensureExplicitOffset(selection.startStr, state.timezone),
                    end: endIso,
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
            eventContent: function (info) {
                return renderCalendarEvent(info);
            },
            events: function (info, success, failure) {
                api(state, 'GET', 'events', {
                    instrumentCode: state.selectedInstrument,
                    start: ensureExplicitOffset(info.startStr, state.timezone),
                    end: ensureExplicitOffset(info.endStr, state.timezone)
                }).then(function (data) {
                    success((data.events || []).map(function (event) {
                        var isOutage = event.eventType === 'block';
                        return {
                            id: String(event.id),
                            title: '',
                            start: event.start,
                            end: event.end,
                            backgroundColor: isOutage ? 'rgba(239, 68, 68, 0.26)' : 'rgba(59, 130, 246, 0.26)',
                            borderColor: isOutage ? 'rgba(248, 113, 113, 0.92)' : 'rgba(96, 165, 250, 0.92)',
                            textColor: isOutage ? '#fff7f7' : '#f7fbff',
                            classNames: [isOutage ? 'ib-event-outage' : 'ib-event-booking'],
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
        var destroyCalendar = state.calendar.destroy.bind(state.calendar);
        state.calendar.destroy = function () {
            stopWeekNowLineSync(root, state);
            destroyCalendar();
        };
        state.calendar.render();
        startWeekNowLineSync(root, state);
        scheduleWeekNowLines(root, state);
    }

    function formatWeekTitle(startStr, endStr, timezone) {
        var start = civilDateParts(startStr, timezone);
        var endExclusive = civilDateParts(endStr, timezone);
        var endDate = new Date(Date.UTC(endExclusive.year, endExclusive.month - 1, endExclusive.day) - 86400000);
        var end = {
            year: endDate.getUTCFullYear(),
            month: endDate.getUTCMonth() + 1,
            day: endDate.getUTCDate()
        };
        var startMonth = monthName(start.month);
        var endMonth = monthName(end.month);

        if (start.year === end.year && start.month === end.month) {
            return start.year + ' ' + startMonth + ' ' + start.day + '–' + end.day;
        }
        if (start.year === end.year) {
            return start.year + ' ' + startMonth + ' ' + start.day + ' – ' + endMonth + ' ' + end.day;
        }
        return start.year + ' ' + startMonth + ' ' + start.day + ' – '
            + end.year + ' ' + endMonth + ' ' + end.day;
    }

    function civilDateParts(value, timezone) {
        var dateMatch = /^(\d{4})-(\d{2})-(\d{2})(?:T|$)/.exec(value);
        if (dateMatch) {
            return {
                year: Number(dateMatch[1]),
                month: Number(dateMatch[2]),
                day: Number(dateMatch[3])
            };
        }
        var parts = zonedParts(new Date(value), timezone);
        return {
            year: Number(parts.year),
            month: Number(parts.month),
            day: Number(parts.day)
        };
    }

    function monthName(month) {
        return new Intl.DateTimeFormat('en-US', {
            month: 'long',
            timeZone: 'UTC'
        }).format(new Date(Date.UTC(2000, month - 1, 1)));
    }

    function updateWeekTitle(calendarEl, title) {
        var titleEl = calendarEl.querySelector('.fc-toolbar-title');
        if (titleEl) {
            titleEl.textContent = title;
        }
    }

    function startWeekNowLineSync(root, state) {
        if (state.weekNowLineTimer !== null) {
            return;
        }
        state.weekNowLineResizeHandler = function () {
            scheduleWeekNowLines(root, state);
        };
        state.weekNowLinePageHideHandler = function () {
            stopWeekNowLineSync(root, state);
        };
        window.addEventListener('resize', state.weekNowLineResizeHandler);
        window.addEventListener('pagehide', state.weekNowLinePageHideHandler);
        state.weekNowLineTimer = window.setInterval(function () {
            scheduleWeekNowLines(root, state);
        }, 60000);
    }

    function stopWeekNowLineSync(root, state) {
        if (state.weekNowLineTimer !== null) {
            window.clearInterval(state.weekNowLineTimer);
            state.weekNowLineTimer = null;
        }
        if (state.weekNowLineFrame !== null) {
            window.cancelAnimationFrame(state.weekNowLineFrame);
            state.weekNowLineFrame = null;
        }
        if (state.weekNowLineResizeHandler) {
            window.removeEventListener('resize', state.weekNowLineResizeHandler);
            state.weekNowLineResizeHandler = null;
        }
        if (state.weekNowLinePageHideHandler) {
            window.removeEventListener('pagehide', state.weekNowLinePageHideHandler);
            state.weekNowLinePageHideHandler = null;
        }
        clearWeekNowLines(root);
    }

    function scheduleWeekNowLines(root, state) {
        if (state.weekNowLineFrame !== null) {
            window.cancelAnimationFrame(state.weekNowLineFrame);
        }
        state.weekNowLineFrame = window.requestAnimationFrame(function () {
            state.weekNowLineFrame = window.requestAnimationFrame(function () {
                state.weekNowLineFrame = null;
                syncWeekNowLines(root, state);
            });
        });
    }

    function syncWeekNowLines(root, state) {
        clearWeekNowLines(root);
        var calendarEl = root.querySelector('.ib-calendar');
        var todayKey = zonedDateKey(new Date(), state.timezone);
        var columns = Array.prototype.slice.call(
            calendarEl.querySelectorAll('.fc-timegrid-col[data-date]')
        );
        var todayColumn = columns.find(function (column) {
            return column.getAttribute('data-date') === todayKey;
        });
        var defaultLine = calendarEl.querySelector('.fc-timegrid-now-indicator-line');
        if (!todayColumn || !defaultLine) {
            return;
        }

        var todayFrame = todayColumn.querySelector('.fc-timegrid-col-frame');
        if (!todayFrame) {
            return;
        }
        var lineTop = defaultLine.getBoundingClientRect().top - todayFrame.getBoundingClientRect().top;

        columns.forEach(function (column) {
            var frame = column.querySelector('.fc-timegrid-col-frame');
            if (!frame) {
                return;
            }
            var line = el('div', 'ib-week-now-line');
            var isToday = column === todayColumn;
            line.classList.add(isToday ? 'ib-week-now-line-today' : 'ib-week-now-line-dim');
            line.style.top = lineTop + 'px';
            line.setAttribute('aria-hidden', 'true');
            frame.appendChild(line);
        });
    }

    function clearWeekNowLines(root) {
        root.querySelectorAll('.ib-week-now-line').forEach(function (line) {
            line.remove();
        });
    }

    function zonedDateKey(date, timezone) {
        var parts = zonedParts(date, timezone);
        return parts.year + '-' + parts.month + '-' + parts.day;
    }

    function renderCalendarEvent(info) {
        var eventData = info.event.extendedProps.bookingEvent;
        var container = el('div', 'ib-event-content');
        var displayTime = info.timeText.replace(/\s*-\s*/, '–');
        var isCompact = info.event.start && info.event.end
            && info.event.end.getTime() - info.event.start.getTime() <= 1800000;

        if (isCompact) {
            container.classList.add('ib-event-content-compact');
            var compact = el('div', 'ib-event-compact');
            var compactTime = el('span', 'ib-event-time');
            compactTime.textContent = displayTime;
            compact.appendChild(compactTime);
            compact.appendChild(document.createTextNode(' · '));
            var compactOwner = el('span', 'ib-event-owner');
            compactOwner.textContent = eventData.ownerUser;
            compact.appendChild(compactOwner);
            container.appendChild(compact);
        } else {
            var time = el('div', 'ib-event-time');
            time.textContent = displayTime;
            container.appendChild(time);

            var main = el('div', 'ib-event-main');
            var owner = el('span', 'ib-event-owner');
            owner.textContent = eventData.ownerUser;
            main.appendChild(owner);
            container.appendChild(main);

            if (eventData.note) {
                var note = el('div', 'ib-event-note');
                note.textContent = eventData.note;
                container.appendChild(note);
            }
        }

        container.title = [
            eventData.eventType === 'block' ? 'Outage' : 'Booking',
            'User: ' + eventData.ownerUser,
            displayTime,
            eventData.note ? 'Note: ' + eventData.note : ''
        ].filter(Boolean).join('\n');

        return { domNodes: [container] };
    }

    function buildDialog(state) {
        var overlay = el('div', 'ib-dialog-overlay');
        overlay.hidden = true;

        var dialog = el('div', 'ib-dialog');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');

        var closeButton = button('button', '×');
        closeButton.className += ' ib-dialog-close ib-danger';
        closeButton.setAttribute('aria-label', 'Close');
        closeButton.addEventListener('click', function () {
            if (!state.saving) {
                overlay.hidden = true;
            }
        });
        dialog.appendChild(closeButton);

        var heading = el('h3', 'ib-dialog-title');
        dialog.appendChild(heading);

        var form = el('form', 'ib-form');
        form.appendChild(field('start', 'Start time', 'datetime-local', 64));
        form.appendChild(field('end', 'End time', 'datetime-local', 64));

        var typeWrap = el('label', 'ib-field ib-event-type-field');
        typeWrap.appendChild(text('Event type'));
        var typeSelect = el('select', 'ib-input');
        typeSelect.name = 'eventType';
        [['booking', 'Booking'], ['block', 'Outage']].forEach(function (item) {
            var option = document.createElement('option');
            option.value = item[0];
            option.textContent = item[1];
            typeSelect.appendChild(option);
        });
        typeWrap.appendChild(typeSelect);
        form.appendChild(typeWrap);

        var metadata = el('div', 'ib-event-metadata');
        metadata.hidden = true;
        var typeBadge = el('span', 'ib-event-type-badge');
        metadata.appendChild(typeBadge);
        var owner = el('span', 'ib-event-owner');
        var ownerLabel = el('span', 'ib-event-owner-label');
        ownerLabel.textContent = 'Username';
        var ownerValue = el('span', 'ib-event-owner-value');
        owner.appendChild(ownerLabel);
        owner.appendChild(ownerValue);
        metadata.appendChild(owner);
        form.appendChild(metadata);

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
        var cancelButton = button('button', 'Cancel booking');
        cancelButton.className += ' ib-danger';
        var submitButton = button('submit', 'Save');
        buttons.appendChild(cancelButton);
        buttons.appendChild(submitButton);
        form.appendChild(buttons);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitDialog(state, overlay);
        });
        cancelButton.addEventListener('click', function () {
            cancelEvent(state, overlay);
        });
        overlay.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !state.saving) {
                overlay.hidden = true;
            }
        });

        dialog.appendChild(form);
        overlay.appendChild(dialog);
        return overlay;
    }

    function buildSettingsDialog(state) {
        var overlay = el('div', 'ib-dialog-overlay ib-settings-overlay');
        overlay.hidden = true;

        var dialog = el('div', 'ib-dialog ib-settings-dialog');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'ib-settings-title');

        var closeButton = button('button', '×');
        closeButton.className += ' ib-dialog-close ib-danger';
        closeButton.setAttribute('aria-label', 'Close settings');
        closeButton.addEventListener('click', function () {
            if (!state.saving) {
                overlay.hidden = true;
            }
        });
        dialog.appendChild(closeButton);

        var heading = el('h3', 'ib-dialog-title');
        heading.id = 'ib-settings-title';
        heading.textContent = 'Settings';
        dialog.appendChild(heading);

        var introduction = el('p', 'ib-settings-intro');
        introduction.textContent = 'Manage tools and TRSys administrators.';
        dialog.appendChild(introduction);

        var message = el('p', 'ib-dialog-message ib-settings-message');
        dialog.appendChild(message);

        var adminsSection = el('section', 'ib-settings-section');
        var adminsTitle = el('h4', 'ib-settings-section-title');
        adminsTitle.textContent = 'Administrators';
        adminsSection.appendChild(adminsTitle);
        var adminsHelp = el('p', 'ib-settings-empty');
        adminsHelp.textContent = 'Admins can be viewed and added here. Use the server CLI to revoke access.';
        adminsSection.appendChild(adminsHelp);
        adminsSection.appendChild(el('div', 'ib-admin-list'));
        var addAdminForm = el('form', 'ib-admin-add-form');
        addAdminForm.appendChild(settingsTextField('username', 'Add administrator username', '', 255, true));
        var addAdminButton = button('submit', 'Add Admin');
        addAdminForm.appendChild(addAdminButton);
        addAdminForm.addEventListener('submit', function (event) {
            event.preventDefault();
            submitAddAdmin(state, overlay, addAdminForm);
        });
        adminsSection.appendChild(addAdminForm);
        dialog.appendChild(adminsSection);

        var toolsSection = el('section', 'ib-settings-section');
        var toolsTitle = el('h4', 'ib-settings-section-title');
        toolsTitle.textContent = 'Tools';
        toolsSection.appendChild(toolsTitle);
        var actions = el('div', 'ib-settings-actions');
        var addButton = button('button', 'Add instrument');
        addButton.addEventListener('click', function () {
            overlay.querySelector('.ib-settings-list').prepend(buildInstrumentSettingsForm(state, overlay, null));
        });
        actions.appendChild(addButton);
        toolsSection.appendChild(actions);
        toolsSection.appendChild(el('div', 'ib-settings-list'));
        dialog.appendChild(toolsSection);

        overlay.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !state.saving) {
                var confirm = overlay.querySelector('.ib-delete-confirm');
                if (confirm && !confirm.hidden) {
                    confirm.hidden = true;
                    return;
                }
                overlay.hidden = true;
            }
        });
        overlay.appendChild(dialog);
        overlay.appendChild(buildDeleteConfirmDialog(state, overlay));
        return overlay;
    }

    function buildDeleteConfirmDialog(state, settingsOverlay) {
        var confirm = el('div', 'ib-dialog-overlay ib-delete-confirm');
        confirm.hidden = true;
        var dialog = el('div', 'ib-dialog ib-delete-confirm-dialog');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        var title = el('h3', 'ib-dialog-title');
        title.textContent = 'Delete Tool';
        dialog.appendChild(title);
        dialog.appendChild(el('div', 'ib-delete-summary'));
        var warning = el('p', 'ib-delete-warning');
        warning.textContent = 'This permanently deletes the tool and all of its bookings and outages. This cannot be undone.';
        dialog.appendChild(warning);
        dialog.appendChild(settingsTextField('confirmName', 'Type the exact tool name to confirm', '', 120, true));
        var message = el('p', 'ib-dialog-message ib-delete-message');
        dialog.appendChild(message);
        var buttons = el('div', 'ib-dialog-buttons');
        var cancel = button('button', 'Cancel');
        cancel.addEventListener('click', function () {
            if (!state.saving) {
                confirm.hidden = true;
            }
        });
        var destroy = button('button', 'Delete permanently');
        destroy.className += ' ib-danger';
        destroy.disabled = true;
        destroy.addEventListener('click', function () {
            submitDeleteInstrument(state, settingsOverlay, confirm);
        });
        buttons.appendChild(cancel);
        buttons.appendChild(destroy);
        dialog.appendChild(buttons);
        confirm.appendChild(dialog);

        confirm.querySelector('[name="confirmName"]').addEventListener('input', function () {
            var expected = confirm._expectedName || '';
            destroy.disabled = getValue(confirm, 'confirmName') !== expected || state.saving;
        });
        return confirm;
    }

    function openSettings(state) {
        if (!state.isAdmin || state.saving) {
            return;
        }
        var overlay = state.root.querySelector('.ib-settings-overlay');
        overlay.hidden = false;
        setSettingsMessage(overlay, 'Loading settings...', false);
        api(state, 'GET', 'admin/instruments', {}).then(function (data) {
            renderAdminList(overlay, data.admins || []);
            renderInstrumentSettings(state, overlay, data.instruments || []);
            setSettingsMessage(overlay, '', false);
        }).catch(function (err) {
            setSettingsMessage(overlay, err.message || 'Unable to load instrument settings.', true);
        });
    }

    function renderAdminList(overlay, admins) {
        var list = overlay.querySelector('.ib-admin-list');
        list.textContent = '';
        if (!admins.length) {
            var empty = el('p', 'ib-settings-empty');
            empty.textContent = 'No TRSys administrators have been added yet.';
            list.appendChild(empty);
            return;
        }
        admins.forEach(function (admin) {
            var row = el('div', 'ib-admin-row');
            var name = el('span', 'ib-admin-username');
            name.textContent = admin.username;
            var added = el('span', 'ib-admin-added');
            added.textContent = 'Added ' + formatAdminDate(admin.addedAt);
            row.appendChild(name);
            row.appendChild(added);
            list.appendChild(row);
        });
    }

    function formatAdminDate(timestamp) {
        var date = new Date(Number(timestamp) * 1000);
        if (!Number.isFinite(date.getTime())) {
            return 'unknown date';
        }
        return date.toISOString().slice(0, 10);
    }

    function submitAddAdmin(state, overlay, form) {
        if (state.saving) {
            return;
        }
        state.saving = true;
        setSettingsMessage(overlay, 'Adding administrator...', false);
        api(state, 'POST', 'admin/users/add', {
            username: getValue(form, 'username')
        }).then(function () {
            setValue(form, 'username', '');
            return api(state, 'GET', 'admin/instruments', {});
        }).then(function (data) {
            renderAdminList(overlay, data.admins || []);
            renderInstrumentSettings(state, overlay, data.instruments || []);
            setSettingsMessage(overlay, 'Administrator added.', false);
        }).catch(function (err) {
            setSettingsMessage(overlay, err.message || 'Unable to add administrator.', true);
        }).finally(function () {
            state.saving = false;
        });
    }

    function renderInstrumentSettings(state, overlay, instruments) {
        var list = overlay.querySelector('.ib-settings-list');
        list.textContent = '';
        instruments.forEach(function (instrument) {
            list.appendChild(buildInstrumentSettingsForm(state, overlay, instrument));
        });
        if (instruments.length === 0) {
            var empty = el('p', 'ib-settings-empty');
            empty.textContent = 'No tools are currently available.';
            list.appendChild(empty);
        }
    }

    function buildInstrumentSettingsForm(state, overlay, instrument) {
        var form = el('form', 'ib-settings-card');
        var isCreate = !instrument;
        var cardTitle = el('h4', 'ib-settings-card-title');
        cardTitle.textContent = isCreate ? 'New instrument' : instrument.name;
        form.appendChild(cardTitle);
        form.appendChild(settingsTextField('name', 'Name', instrument ? instrument.name : '', 120, true));
        form.appendChild(settingsTextArea('description', 'Description', instrument ? instrument.description : '', 1000));
        form.appendChild(settingsNumberField(
            'maxBookingHours',
            'Maximum duration per booking (hours)',
            instrument ? instrument.maxBookingHours : 4,
            1
        ));
        form.appendChild(settingsNumberField(
            'weeklyQuotaHours',
            'Weekly limit per user (hours, 0 for unlimited)',
            instrument ? instrument.weeklyQuotaHours : 0,
            0
        ));

        var buttons = el('div', 'ib-dialog-buttons');
        if (isCreate) {
            var discard = button('button', 'Discard');
            discard.className += ' ib-danger';
            discard.addEventListener('click', function () {
                form.remove();
            });
            buttons.appendChild(discard);
        } else {
            var deleteButton = button('button', 'Delete Tool');
            deleteButton.className += ' ib-danger';
            deleteButton.addEventListener('click', function () {
                openDeleteConfirm(state, overlay, instrument);
            });
            buttons.appendChild(deleteButton);
        }
        var save = button('submit', isCreate ? 'Create instrument' : 'Save changes');
        buttons.appendChild(save);
        form.appendChild(buttons);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (state.saving) {
                return;
            }
            var payload = {
                name: getValue(form, 'name'),
                description: getValue(form, 'description'),
                maxBookingHours: Number(getValue(form, 'maxBookingHours')),
                weeklyQuotaHours: Number(getValue(form, 'weeklyQuotaHours'))
            };
            if (!isCreate) {
                payload.instrumentCode = instrument.code;
            }
            state.saving = true;
            overlay.querySelectorAll('button, input, textarea').forEach(function (control) {
                control.disabled = true;
            });
            setSettingsMessage(overlay, 'Saving settings...', false);
            api(
                state,
                'POST',
                isCreate ? 'admin/instrument/create' : 'admin/instrument/update',
                payload
            ).then(function () {
                return refreshInstrumentData(state);
            }).then(function () {
                return api(state, 'GET', 'admin/instruments', {});
            }).then(function (data) {
                renderAdminList(overlay, data.admins || []);
                renderInstrumentSettings(state, overlay, data.instruments || []);
                setSettingsMessage(overlay, 'Settings saved.', false);
            }).catch(function (err) {
                setSettingsMessage(overlay, err.message || 'Unable to save instrument settings.', true);
            }).finally(function () {
                state.saving = false;
                overlay.querySelectorAll('button, input, textarea').forEach(function (control) {
                    control.disabled = false;
                });
            });
        });
        return form;
    }

    function openDeleteConfirm(state, settingsOverlay, instrument) {
        var confirm = settingsOverlay.querySelector('.ib-delete-confirm');
        setDeleteMessage(confirm, 'Loading tool details...', false);
        confirm.hidden = false;
        confirm._instrumentCode = instrument.code;
        confirm._expectedName = instrument.name;
        setValue(confirm, 'confirmName', '');
        confirm.querySelector('.ib-danger').disabled = true;
        api(state, 'GET', 'admin/instrument/delete-preview', {
            instrumentCode: instrument.code
        }).then(function (data) {
            var summary = confirm.querySelector('.ib-delete-summary');
            summary.textContent = '';
            var name = el('p', 'ib-delete-name');
            name.textContent = 'Tool: ' + data.instrument.name;
            var description = el('p', 'ib-delete-description');
            description.textContent = data.instrument.description
                ? ('Description: ' + data.instrument.description)
                : 'Description: (none)';
            var totals = el('p', 'ib-delete-counts');
            totals.textContent = 'Associated events: ' + data.totalEvents
                + ' · Future events: ' + data.futureEvents;
            summary.appendChild(name);
            summary.appendChild(description);
            summary.appendChild(totals);
            confirm._expectedName = data.instrument.name;
            confirm.querySelector('[name="confirmName"]').placeholder = 'Type “' + data.instrument.name + '” to confirm';
            setDeleteMessage(confirm, '', false);
        }).catch(function (err) {
            setDeleteMessage(confirm, err.message || 'Unable to load deletion details.', true);
        });
    }

    function submitDeleteInstrument(state, settingsOverlay, confirm) {
        if (state.saving) {
            return;
        }
        state.saving = true;
        setDeleteMessage(confirm, 'Deleting tool...', false);
        api(state, 'POST', 'admin/instrument/delete', {
            instrumentCode: confirm._instrumentCode,
            confirmName: getValue(confirm, 'confirmName')
        }).then(function () {
            confirm.hidden = true;
            return refreshInstrumentData(state);
        }).then(function () {
            return api(state, 'GET', 'admin/instruments', {});
        }).then(function (data) {
            renderAdminList(settingsOverlay, data.admins || []);
            renderInstrumentSettings(state, settingsOverlay, data.instruments || []);
            setSettingsMessage(settingsOverlay, 'Tool deleted.', false);
        }).catch(function (err) {
            setDeleteMessage(confirm, err.message || 'Unable to delete tool.', true);
        }).finally(function () {
            state.saving = false;
            var expected = confirm._expectedName || '';
            confirm.querySelector('.ib-danger').disabled = getValue(confirm, 'confirmName') !== expected;
        });
    }

    function setDeleteMessage(confirm, message, isError) {
        var node = confirm.querySelector('.ib-delete-message');
        node.textContent = message;
        node.classList.toggle('is-error', !!isError);
    }

    function settingsTextField(name, label, value, maxLength, required) {
        var wrap = el('label', 'ib-field');
        wrap.appendChild(text(label));
        var input = document.createElement('input');
        input.className = 'ib-input';
        input.name = name;
        input.type = 'text';
        input.value = value;
        input.maxLength = maxLength;
        input.required = required;
        wrap.appendChild(input);
        return wrap;
    }

    function settingsTextArea(name, label, value, maxLength) {
        var wrap = el('label', 'ib-field');
        wrap.appendChild(text(label));
        var input = document.createElement('textarea');
        input.className = 'ib-input';
        input.name = name;
        input.value = value;
        input.maxLength = maxLength;
        input.rows = 3;
        wrap.appendChild(input);
        return wrap;
    }

    function settingsNumberField(name, label, value, minimum) {
        var wrap = el('label', 'ib-field');
        wrap.appendChild(text(label));
        var input = document.createElement('input');
        input.className = 'ib-input';
        input.name = name;
        input.type = 'number';
        input.value = String(value);
        input.min = String(minimum);
        input.max = '168';
        input.step = '1';
        input.required = true;
        wrap.appendChild(input);
        return wrap;
    }

    function setSettingsMessage(overlay, message, isError) {
        var node = overlay.querySelector('.ib-settings-message');
        node.textContent = message;
        node.classList.toggle('is-error', !!isError);
    }

    function refreshInstrumentData(state) {
        return fetchInstruments(state).then(function (data) {
            updateSecurityToken(state, data);
            state.instruments = data.instruments || [];
            if (!state.instruments.some(function (instrument) {
                return instrument.code === state.selectedInstrument;
            })) {
                state.selectedInstrument = state.instruments.length ? state.instruments[0].code : null;
            }
            refreshInstrumentSelect(state.root, state);
            if (!state.selectedInstrument) {
                if (state.calendar) {
                    state.calendar.removeAllEvents();
                }
                showStatus(state.root, 'No tools are currently available.', false);
                return;
            }
            if (!state.calendar) {
                initCalendar(state.root, state);
            } else {
                state.calendar.refetchEvents();
            }
        });
    }

    function openDialog(root, state, eventData) {
        var overlay = root.querySelector('.ib-dialog-overlay');
        overlay._eventData = eventData;
        var isCreate = eventData.mode === 'create';
        var canEdit = isCreate || eventData.canEdit;
        var title = overlay.querySelector('.ib-dialog-title');
        title.textContent = isCreate ? 'Create booking' : (canEdit ? 'Edit booking' : 'View reservation');

        setValue(overlay, 'start', toDatetimeInput(eventData.start, state.timezone));
        setValue(overlay, 'end', toDatetimeInput(eventData.end, state.timezone));
        setValue(overlay, 'eventType', eventData.eventType || 'booking');
        setValue(overlay, 'note', eventData.note || '');

        var showEventTypeChoice = state.isAdmin && isCreate;
        var eventTypeField = overlay.querySelector('.ib-event-type-field');
        var eventTypeSelect = overlay.querySelector('[name="eventType"]');
        eventTypeField.hidden = !showEventTypeChoice;
        eventTypeSelect.disabled = !showEventTypeChoice;

        var metadata = overlay.querySelector('.ib-event-metadata');
        var typeBadge = overlay.querySelector('.ib-event-type-badge');
        metadata.hidden = isCreate;
        typeBadge.textContent = eventData.eventType === 'block' ? 'OUTAGE' : 'BOOKING';
        typeBadge.classList.toggle('ib-event-type-outage', eventData.eventType === 'block');
        typeBadge.classList.toggle('ib-event-type-booking', eventData.eventType !== 'block');
        overlay.querySelector('.ib-event-owner-value').textContent = eventData.ownerUser || '';

        overlay.querySelector('[name="start"]').disabled = !canEdit;
        overlay.querySelector('[name="end"]').disabled = !canEdit;
        overlay.querySelector('[name="note"]').disabled = !canEdit;
        overlay.querySelector('[type="submit"]').hidden = !canEdit;
        overlay.querySelector('.ib-dialog-buttons .ib-danger').hidden = !(eventData.canCancel && !isCreate);
        if (!isCreate && eventData.eventType === 'block') {
            setDialogMessage(
                overlay,
                canEdit
                    ? 'You can modify this outage because you created it.'
                    : 'Only the administrator who created this outage can modify it.',
                false
            );
        } else if (!isCreate && !canEdit) {
            setDialogMessage(overlay, 'You can view the complete reservation details. Only the owner can modify this booking.', false);
        } else {
            setDialogMessage(overlay, '', false);
        }
        overlay.hidden = false;
    }

    function submitDialog(state, overlay) {
        if (state.saving) {
            return;
        }
        var eventData = overlay._eventData;
        var isCreate = eventData.mode === 'create';
        setDialogMessage(overlay, '', false);
        if (!validateDialogTimes(state, overlay)) {
            return;
        }
        var payload = {
            instrumentCode: state.selectedInstrument,
            start: fromDatetimeInput(getValue(overlay, 'start'), state.timezone),
            end: fromDatetimeInput(getValue(overlay, 'end'), state.timezone),
            eventType: isCreate
                ? (state.isAdmin ? getValue(overlay, 'eventType') : 'booking')
                : eventData.eventType,
            note: getValue(overlay, 'note')
        };
        if (isCreate) {
            payload.requestId = uuid();
        } else {
            payload.eventId = eventData.id;
        }

        setSaving(state, overlay, true);
        api(state, 'POST', isCreate ? 'create' : 'update', payload).then(function () {
            overlay.hidden = true;
            state.calendar.refetchEvents();
            showTransientStatus(state, 'Booking saved.');
        }).catch(function (err) {
            setDialogMessage(overlay, err.message || 'Save failed.', true);
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
        setDialogMessage(overlay, '', false);
        setSaving(state, overlay, true);
        api(state, 'POST', 'cancel', { eventId: overlay._eventData.id }).then(function () {
            overlay.hidden = true;
            state.calendar.refetchEvents();
            showTransientStatus(state, 'Booking cancelled.');
        }).catch(function (err) {
            setDialogMessage(overlay, err.message || 'Cancel failed.', true);
        }).finally(function () {
            setSaving(state, overlay, false);
        });
    }

    function api(state, method, operation, data, retried) {
        if (method === 'POST' && !state.sectok) {
            return Promise.reject(securityTokenError());
        }

        return apiRequest(state, method, operation, data).catch(function (error) {
            if (method !== 'POST' || error.code !== 'CSRF_FAILED' || retried) {
                throw error;
            }

            return refreshSecurityToken(state).then(function () {
                return apiRequest(state, method, operation, data);
            });
        });
    }

    function apiRequest(state, method, operation, data) {
        var url = new URL(state.ajaxUrl, window.location.href);
        url.searchParams.set('operation', operation);
        var options = {
            method: method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {}
        };

        if (method === 'GET') {
            Object.keys(data || {}).forEach(function (key) {
                url.searchParams.set(key, data[key]);
            });
        } else {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-DokuWiki-Sectok'] = state.sectok;
            options.body = JSON.stringify(data || {});
        }

        return fetch(url.toString(), options).then(function (response) {
            return response.json().catch(function () {
                var responseError = new Error('The server returned an invalid response.');
                responseError.code = 'INVALID_RESPONSE';
                throw responseError;
            }).then(function (payload) {
                if (!response.ok || !payload.ok) {
                    var error = payload && payload.error ? payload.error : {};
                    var requestError = new Error(error.message || 'Request failed.');
                    requestError.code = error.code || 'REQUEST_FAILED';
                    throw requestError;
                }
                return payload.data || {};
            });
        });
    }

    function fetchInstruments(state) {
        return api(state, 'GET', 'instruments', {});
    }

    function refreshSecurityToken(state) {
        return apiRequest(state, 'GET', 'instruments', {}).then(function (data) {
            updateSecurityToken(state, data);
        }).catch(function () {
            var error = new Error('Your login session may have expired. Please sign in again and retry.');
            error.code = 'CSRF_REFRESH_FAILED';
            throw error;
        });
    }

    function updateSecurityToken(state, data) {
        var token = data && typeof data.sectok === 'string' ? data.sectok : '';
        if (!token) {
            throw securityTokenError();
        }
        state.sectok = token;
    }

    function securityTokenError() {
        var error = new Error('A security token is unavailable. Your login session may have expired. Please sign in again.');
        error.code = 'CSRF_TOKEN_MISSING';
        return error;
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

    function setDialogMessage(overlay, message, isError) {
        var node = overlay.querySelector('.ib-dialog-message');
        node.textContent = message;
        node.classList.toggle('is-error', !!isError);
    }

    function validateDialogTimes(state, overlay) {
        var startValue = getValue(overlay, 'start');
        var endValue = getValue(overlay, 'end');
        var slotPattern = /^\d{4}-\d{2}-\d{2}T\d{2}:(?:00|30)$/;
        var startIso = fromDatetimeInput(startValue, state.timezone);
        var endIso = fromDatetimeInput(endValue, state.timezone);
        var startTime = Date.parse(startIso);
        var endTime = Date.parse(endIso);

        if (
            !slotPattern.test(startValue)
            || !slotPattern.test(endValue)
            || !Number.isFinite(startTime)
            || !Number.isFinite(endTime)
            || (endTime - startTime) % 1800000 !== 0
        ) {
            setDialogMessage(overlay, 'Start and end times must use 30-minute intervals.', true);
            return false;
        }

        var earliest = nextBookableSlotTime();
        if (startTime < earliest) {
            setDialogMessage(
                overlay,
                'The earliest available start time is ' + formatTimeInTimezone(earliest, state.timezone) + '.',
                true
            );
            return false;
        }

        var horizon = bookingHorizonTime(state.timezone);
        if (startTime > horizon) {
            setDialogMessage(overlay, 'The event must start within the next seven calendar days.', true);
            return false;
        }
        return true;
    }

    function nextBookableSlotTime() {
        return (Math.floor(Date.now() / 1800000) + 1) * 1800000;
    }

    function bookingHorizonTime(timezone) {
        var parts = zonedParts(new Date(), timezone);
        var targetUtcGuess = Date.UTC(
            Number(parts.year),
            Number(parts.month) - 1,
            Number(parts.day) + 7,
            Number(parts.hour),
            Number(parts.minute),
            Number(parts.second)
        );
        var offset = offsetMinutes(timezone, new Date(targetUtcGuess));
        var utc = targetUtcGuess - offset * 60000;
        var refinedOffset = offsetMinutes(timezone, new Date(utc));
        if (refinedOffset !== offset) {
            utc = targetUtcGuess - refinedOffset * 60000;
        }
        return utc;
    }

    function formatTimeInTimezone(timestamp, timezone) {
        var parts = zonedParts(new Date(timestamp), timezone);
        return parts.hour + ':' + parts.minute;
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
        if (type === 'datetime-local') {
            input.step = 1800;
        }
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
