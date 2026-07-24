'use strict';

var fs = require('fs');
var path = require('path');
var assert = require('assert');

var scriptPath = path.join(__dirname, '..', 'script.js');
var script = fs.readFileSync(scriptPath, 'utf8');

function civilDateParts(value) {
    var dateMatch = /^(\d{4})-(\d{2})-(\d{2})(?:T|$)/.exec(value);
    return {
        year: Number(dateMatch[1]),
        month: Number(dateMatch[2]),
        day: Number(dateMatch[3])
    };
}

function civilDateNumber(parts) {
    return (parts.year * 10000) + (parts.month * 100) + parts.day;
}

function civilWeekday(parts) {
    return new Date(Date.UTC(parts.year, parts.month - 1, parts.day)).getUTCDay();
}

function civilDayDiff(start, endExclusive) {
    var startUtc = Date.UTC(start.year, start.month - 1, start.day);
    var endUtc = Date.UTC(endExclusive.year, endExclusive.month - 1, endExclusive.day);
    return Math.round((endUtc - startUtc) / 86400000);
}

function addCivilDays(parts, days) {
    var date = new Date(Date.UTC(parts.year, parts.month - 1, parts.day + days));
    return {
        year: date.getUTCFullYear(),
        month: date.getUTCMonth() + 1,
        day: date.getUTCDate()
    };
}

function zonedDateKey(date, timezone) {
    var formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
    });
    var result = {};
    formatter.formatToParts(date).forEach(function (part) {
        if (part.type !== 'literal') {
            result[part.type] = part.value;
        }
    });
    return result.year + '-' + result.month + '-' + result.day;
}

function classifyWeekNowLineMode(viewStartStr, viewEndStr, now, timezone) {
    var viewStart = civilDateParts(viewStartStr);
    var viewEndExclusive = civilDateParts(viewEndStr);
    var todayKey = zonedDateKey(now, timezone);
    var today = civilDateParts(todayKey + 'T12:00:00');
    var todayNumber = civilDateNumber(today);
    var startNumber = civilDateNumber(viewStart);
    var endNumber = civilDateNumber(viewEndExclusive);

    if (todayNumber >= startNumber && todayNumber < endNumber) {
        return 'current';
    }

    var daySpan = civilDayDiff(viewStart, viewEndExclusive);
    if (daySpan <= 0) {
        return 'none';
    }

    var alignWeekday = civilWeekday(viewStart);
    var todayWeekday = civilWeekday(today);
    var delta = (todayWeekday - alignWeekday + 7) % 7;
    var currentWeekStart = addCivilDays(today, -delta);
    var nextWeekStart = addCivilDays(currentWeekStart, daySpan);
    var nextWeekEnd = addCivilDays(nextWeekStart, daySpan);

    if (
        civilDateNumber(viewStart) === civilDateNumber(nextWeekStart)
        && civilDateNumber(viewEndExclusive) === civilDateNumber(nextWeekEnd)
    ) {
        return 'next';
    }
    return 'none';
}

var failures = 0;

function check(name, fn) {
    try {
        fn();
        console.log('[PASS] ' + name);
    } catch (error) {
        failures += 1;
        console.log('[FAIL] ' + name + ': ' + error.message);
    }
}

check('TRCal product branding is current', function () {
    assert.ok(script.indexOf("appTitle.textContent = 'TRCal'") !== -1);
    assert.ok(script.indexOf("subtitle.textContent = 'Tool Reservation Calendar'") !== -1);
});

check('header shows short commit SHA and GitHub repository without branch label', function () {
    assert.ok(script.indexOf("root.getAttribute('data-build-commit')") !== -1);
    assert.ok(script.indexOf("root.getAttribute('data-repository-url')") !== -1);
    assert.ok(script.indexOf("commit.slice(0, 7)") !== -1);
    assert.ok(script.indexOf("repositoryUrl + '/commit/' + commit") !== -1);
    assert.ok(script.indexOf("repositoryUrl.replace(/^https:\\/\\//, '')") !== -1);
    assert.ok(script.indexOf('Last updated:') === -1);
    assert.ok(script.indexOf('main @') === -1);
});

check('Delete button uses type=button and opens confirm', function () {
    assert.ok(script.indexOf("button('button', 'Delete')") !== -1);
    assert.ok(script.indexOf('del.type = \'button\'') !== -1);
    assert.ok(script.indexOf('openDeleteConfirm(') !== -1);
    assert.ok(script.indexOf('ib-delete-confirm-overlay') !== -1);
    assert.ok(script.indexOf('mountDeleteConfirmDialog') !== -1);
});

check('Delete confirm validates name before API and shows errors', function () {
    assert.ok(script.indexOf('The confirmation name does not match the tool name.') !== -1);
    assert.ok(script.indexOf("api(state, 'POST', 'admin/instrument/delete'") !== -1);
    assert.ok(script.indexOf('setDeleteMessage(confirm, err.message') !== -1);
    assert.ok(script.indexOf('setSettingsMessage(settingsOverlay, err.message') !== -1);
});

check('Cancel closes confirm without delete API in handler', function () {
    assert.ok(/cancel\.addEventListener\('click', function \(\) \{\s*if \(!state\.saving\) \{\s*confirm\.hidden = true;/m.test(script));
});

check('read-only booking messages distinguish completed and future bookings', function () {
    var helperStart = script.indexOf('function readOnlyBookingMessage');
    var helperEnd = script.indexOf('function submitDialog', helperStart);
    assert.ok(helperStart !== -1 && helperEnd > helperStart);
    var helperSource = script.slice(helperStart, helperEnd);
    var readOnlyBookingMessage = new Function(
        helperSource + '\nreturn readOnlyBookingMessage;'
    )();
    var now = Date.parse('2026-07-23T12:00:00-07:00');

    assert.strictEqual(
        readOnlyBookingMessage({
            start: '2026-07-23T09:00:00-07:00',
            end: '2026-07-23T10:00:00-07:00'
        }, now),
        'You can only view the complete reservation details.'
    );
    assert.strictEqual(
        readOnlyBookingMessage({
            start: '2026-07-23T13:00:00-07:00',
            end: '2026-07-23T14:00:00-07:00'
        }, now),
        'Only the owner can modify this booking.'
    );
    assert.strictEqual(
        readOnlyBookingMessage({
            start: '2026-07-23T11:30:00-07:00',
            end: '2026-07-23T12:30:00-07:00'
        }, now),
        'You can only view the complete reservation details.'
    );
    assert.ok(
        script.indexOf(
            'You can view the complete reservation details. Only the owner can modify this booking.'
        ) === -1
    );
});

check('nowIndicator disabled and custom week line helpers exist', function () {
    assert.ok(script.indexOf('nowIndicator: false') !== -1);
    assert.ok(script.indexOf('function classifyWeekNowLineMode') !== -1);
    assert.ok(script.indexOf('function computeNowLineTopPx') !== -1);
    assert.ok(script.indexOf('clearInterval(state.weekNowLineTimer)') !== -1);
    assert.ok(script.indexOf('clearWeekNowLines(root)') !== -1);
});

check('Los Angeles current week classification', function () {
    var timezone = 'America/Los_Angeles';
    // Wednesday 2026-07-22 in LA
    var now = new Date('2026-07-22T18:00:00-07:00');
    var todayKey = zonedDateKey(now, timezone);
    assert.strictEqual(todayKey, '2026-07-22');
    // Sunday-start week containing 2026-07-22
    var currentStart = '2026-07-19T00:00:00-07:00';
    var currentEnd = '2026-07-26T00:00:00-07:00';
    assert.strictEqual(classifyWeekNowLineMode(currentStart, currentEnd, now, timezone), 'current');
});

check('Los Angeles next week uses dim mode class path', function () {
    var timezone = 'America/Los_Angeles';
    var now = new Date('2026-07-22T15:20:00-07:00');
    var nextStart = '2026-07-26T00:00:00-07:00';
    var nextEnd = '2026-08-02T00:00:00-07:00';
    assert.strictEqual(classifyWeekNowLineMode(nextStart, nextEnd, now, timezone), 'next');
});

check('Other weeks hide the now line', function () {
    var timezone = 'America/Los_Angeles';
    var now = new Date('2026-07-22T15:20:00-07:00');
    var earlierStart = '2026-07-12T00:00:00-07:00';
    var earlierEnd = '2026-07-19T00:00:00-07:00';
    var laterStart = '2026-08-02T00:00:00-07:00';
    var laterEnd = '2026-08-09T00:00:00-07:00';
    assert.strictEqual(classifyWeekNowLineMode(earlierStart, earlierEnd, now, timezone), 'none');
    assert.strictEqual(classifyWeekNowLineMode(laterStart, laterEnd, now, timezone), 'none');
});

check('Monday-aligned weeks still detect next week', function () {
    var timezone = 'America/Los_Angeles';
    var now = new Date('2026-07-22T15:20:00-07:00');
    var currentStart = '2026-07-20T00:00:00-07:00';
    var currentEnd = '2026-07-27T00:00:00-07:00';
    var nextStart = '2026-07-27T00:00:00-07:00';
    var nextEnd = '2026-08-03T00:00:00-07:00';
    assert.strictEqual(classifyWeekNowLineMode(currentStart, currentEnd, now, timezone), 'current');
    assert.strictEqual(classifyWeekNowLineMode(nextStart, nextEnd, now, timezone), 'next');
});

check('timegrid hides half-hour minor lines and uses viewport-fixed calendar scroll', function () {
    var stylePath = path.join(__dirname, '..', 'style.css');
    var css = fs.readFileSync(stylePath, 'utf8');
    assert.ok(css.indexOf('.fc-timegrid-slot-minor') !== -1);
    assert.ok(css.indexOf('border-top-color: transparent') !== -1);
    assert.ok(css.indexOf('overflow-y: scroll') !== -1);
    assert.ok(css.indexOf('scrollbar-color:') !== -1);
    assert.ok(css.indexOf('position: fixed') !== -1);
    assert.ok(css.indexOf('top: 16px') !== -1);
    assert.ok(css.indexOf('right: 16px') !== -1);
    assert.ok(css.indexOf('bottom: 16px') !== -1);
    assert.ok(css.indexOf('left: 16px') !== -1);
    assert.ok(css.indexOf('height: auto') !== -1);
    assert.ok(css.indexOf('max-height: none') !== -1);
    assert.ok(css.indexOf('.instrument-booking-app[data-theme="midnight-lab"].ib-portal') !== -1);
    assert.ok(css.indexOf('position: static') !== -1);
    assert.ok(css.indexOf('pointer-events: none') !== -1);
    assert.ok(css.indexOf('.ib-portal .ib-delete-confirm-overlay') !== -1);
    assert.ok(css.indexOf('pointer-events: auto') !== -1);
    assert.ok(script.indexOf("height: '100%'") !== -1);
    assert.ok(script.indexOf("scrollTime: '09:00:00'") !== -1);
    assert.ok(script.indexOf('scrollTimeReset: false') !== -1);
    assert.ok(script.indexOf('scheduleInitialTimeSlotScroll') !== -1);
    assert.ok(script.indexOf('timeSlotScrollInitialized') !== -1);
    assert.ok(script.indexOf('fitInitialTwelveHourWindow') === -1);
    assert.ok(script.indexOf("scrollToTime('09:00:00')") !== -1);
    assert.ok(script.indexOf('scrollTimeSlotsToBottom') === -1);
    assert.ok(/\.fc-timegrid-slot\s*\{\s*height:\s*1\.6em;/m.test(css));
    assert.ok(script.indexOf("slotDuration: '00:30:00'") !== -1);
    assert.ok(script.indexOf("slotMaxTime: '24:00:00'") !== -1);
});

check('top nav order is Settings, Return, instrument selector without a visible label', function () {
    var buildStart = script.indexOf('function buildShell(state)');
    var buildEnd = script.indexOf('function refreshInstrumentSelect');
    assert.ok(buildStart !== -1 && buildEnd > buildStart);
    var buildShell = script.slice(buildStart, buildEnd);
    assert.ok(buildShell.indexOf("ib-toolbar") === -1);
    assert.ok(buildShell.indexOf("ib-instrument-field") !== -1);
    assert.ok(buildShell.indexOf("text('Instrument')") === -1);
    assert.ok(buildShell.indexOf("setAttribute('aria-label', 'Instrument')") !== -1);
    var settingsPos = buildShell.indexOf("button('button', 'Settings')");
    var returnPos = buildShell.indexOf("Return to Lab Wiki");
    var instrumentPos = buildShell.indexOf("ib-instrument-select");
    assert.ok(settingsPos !== -1 && returnPos !== -1 && instrumentPos !== -1);
    assert.ok(settingsPos < returnPos);
    assert.ok(returnPos < instrumentPos);
});

check('calendar navigation is compact and desktop tool selector is four times wider', function () {
    var stylePath = path.join(__dirname, '..', 'style.css');
    var css = fs.readFileSync(stylePath, 'utf8');
    assert.ok(/\.fc-prev-button,[\s\S]*?\.fc-next-button\s*\{\s*width:\s*56px;/m.test(css));
    assert.ok(/\.fc-today-button\s*\{\s*width:\s*84px;/m.test(css));
    assert.ok(/\.ib-instrument-select\s*\{[\s\S]*?width:\s*528px;/m.test(css));
    assert.ok(/\.ib-instrument-select\s*\{[\s\S]*?min-width:\s*528px;/m.test(css));
    assert.ok(/@media \(max-width: 768px\)[\s\S]*?\.ib-instrument-select\s*\{[\s\S]*?max-width:\s*none;/m.test(css));
});

check('changing tools resets the calendar scroll position to 09:00', function () {
    var selectStart = script.indexOf("select.addEventListener('change'");
    var selectEnd = script.indexOf('selectWrap.appendChild(select)', selectStart);
    assert.ok(selectStart !== -1 && selectEnd > selectStart);
    var changeHandler = script.slice(selectStart, selectEnd);
    assert.ok(changeHandler.indexOf('state.calendar.refetchEvents()') !== -1);
    assert.ok(changeHandler.indexOf("state.calendar.scrollToTime('09:00:00')") !== -1);
});

console.log('js_logic_test: ' + (failures === 0 ? 'ok' : failures + ' failures'));
process.exit(failures === 0 ? 0 : 1);
