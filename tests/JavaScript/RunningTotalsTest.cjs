/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const { createRequire } = require('node:module');
const { DateTime } = require('luxon');
const fullCalendarPath = require.resolve('@fullcalendar/core');
require(fullCalendarPath);
const { refineEventDef, parseEventDef, globalPlugins } = createRequire(fullCalendarPath)('@fullcalendar/common');
const eventContext = {
    pluginHooks: {
        eventRefiners: Object.assign({}, ...globalPlugins.map(plugin => plugin.eventRefiners)),
        eventDefMemberAdders: [],
    },
};

const root = path.resolve(__dirname, '../..');
const calendarSource = fs.readFileSync(path.join(root, 'assets/js/widgets/KimaiCalendar.js'), 'utf8')
    .replace(/^import .+;\r?\n/gm, '')
    .replace('export default class KimaiCalendar', 'class KimaiCalendar');

function createCalendar(now, dates) {
    const headers = new Map(dates.map(date => [date, {
        dataset: { date },
        appendChild(element) { this.total = element.textContent; },
    }]));
    const document = {
        querySelectorAll(selector) {
            if (selector === 'th.fc-col-header-cell[data-date]') {
                return [...headers.values()];
            }
            const date = selector.match(/data-date="([^"]+)"/);
            return date && headers.has(date[1]) ? [headers.get(date[1])] : [];
        },
        querySelector() { return headers.values().next().value; },
        createElement() { return { classList: { add() {} } }; },
    };
    const Calendar = vm.runInNewContext(calendarSource + '\nKimaiCalendar;', {
        DateTime,
        document,
        KimaiColor: { calculateContrastColor() { return '#000'; } },
    });
    const calendar = Object.create(Calendar.prototype);
    calendar.options = { now, patterns: { title: '{duration}' } };
    calendar.kimai = { getPlugin() { return { formatSeconds: String, formatDuration: String }; }, getConfiguration() { return { get() { return '#fff'; } }; } };
    return { calendar, headers };
}

function event(calendar, begin, end, duration = 0, pause = 0) {
    const record = {
        id: 1, begin, end, duration, break: pause,
        project: { name: 'Project', color: '#fff', customer: { name: 'Customer', color: '#fff' } },
        activity: { name: 'Activity', color: '#fff' },
    };
    const converted = calendar.convertSourceForCalendar(record);
    const { refined, extra } = refineEventDef(converted, eventContext);
    const definition = parseEventDef(refined, extra, 'kimai-user-timesheets', false, end !== null, eventContext);
    return {
        start: DateTime.fromISO(begin).toJSDate(),
        end: end === null ? null : DateTime.fromISO(end).toJSDate(),
        source: { id: 'kimai-user-timesheets' },
        extendedProps: definition.extendedProps,
    };
}

test('calendar combines stored duration with running time minus breaks', () => {
    const { calendar, headers } = createCalendar('2026-11-24T10:00:00Z', ['2026-11-24']);
    calendar._renderDayAndWeekSum('timeGridWeek', [
        event(calendar, '2026-11-24T08:00:00Z', '2026-11-24T09:00:00Z', 2700),
        event(calendar, '2026-11-24T09:00:00Z', null, 0, 600),
        event(calendar, '2026-11-24T11:00:00Z', null),
        event(calendar, '2026-11-24T09:00:00Z', null, 0, 7200),
    ]);
    assert.equal(headers.get('2026-11-24').total, '5700');
});

test('calendar retains the start day across midnight and UTC date boundaries', () => {
    const { calendar, headers } = createCalendar('2026-11-24T01:00:00+01:00', ['2026-11-23', '2026-11-24']);
    calendar._renderDayAndWeekSum('timeGridWeek', [event(calendar, '2026-11-23T23:00:00+01:00', null)]);
    assert.equal(headers.get('2026-11-23').total, '7200');
    assert.equal(headers.get('2026-11-24').total, '0');

    const shifted = createCalendar('2026-11-24T02:30:00+14:00', ['2026-11-23', '2026-11-24']);
    shifted.calendar._renderDayAndWeekSum('timeGridWeek', [event(shifted.calendar, '2026-11-24T00:30:00+14:00', null)]);
    assert.equal(shifted.headers.get('2026-11-24').total, '7200');
});

test('calendar handles DST elapsed time and its day view', () => {
    const { calendar, headers } = createCalendar('2026-03-29T03:30:00+02:00', ['2026-03-29']);
    calendar._renderDayAndWeekSum('timeGridDay', [event(calendar, '2026-03-29T01:30:00+01:00', null)]);
    assert.equal(headers.get('2026-03-29').total, '3600');
});

test('calendar day view counts both completed records and running time after FullCalendar parsing', () => {
    const { calendar, headers } = createCalendar('2026-11-24T16:00:00+01:00', ['2026-11-24']);
    calendar._renderDayAndWeekSum('timeGridDay', [
        event(calendar, '2026-11-24T09:00:00+01:00', '2026-11-24T11:00:00+01:00', 7200),
        event(calendar, '2026-11-24T15:30:00+01:00', null),
    ]);
    assert.equal(headers.get('2026-11-24').total, '9000');
});

test('quick entry includes running time in day, week and row totals without double counting edits', () => {
    const source = fs.readFileSync(path.join(root, 'templates/quick-entry/index.html.twig'), 'utf8');
    const start = source.indexOf('const getInputDuration =');
    const end = source.indexOf('recalculateTotals();', start);
    assert.ok(start >= 0 && end > start);
    const fields = [
        { id: 'quick_entry_form_rows_0_timesheets_0_duration', value: '1:00', dataset: {} },
        { id: 'quick_entry_form_rows_1_timesheets_0_duration', value: '', dataset: { runningDuration: '3000' } },
        { id: 'quick_entry_form_rows_2_timesheets_1_duration', value: '', dataset: {} },
    ];
    const rowTotals = fields.map(() => ({}));
    const rows = fields.map((field, index) => ({
        getElementsByClassName(name) { return name === 'duration-input' ? [field] : [rowTotals[index]]; },
    }));
    const totals = {};
    const recalculate = vm.runInNewContext('(() => { ' + source.slice(start, end) + '\nreturn recalculateTotals; })()', {
        document: {
            getElementsByClassName(name) { return name === 'duration-input' ? fields : rows; },
            getElementById(id) { return totals[id] ??= {}; },
        },
        DATES: {
            getSecondsFromDurationString(value) {
                if (value === '') return 0;
                const [hours, minutes] = value.split(':').map(Number);
                return hours * 3600 + minutes * 60;
            },
            formatSeconds: String,
        },
    });
    recalculate();
    assert.equal(totals['qe-totals-day-0'].innerText, '6600');
    assert.equal(totals['qe-totals-week'].innerText, '6600');
    assert.equal(rowTotals[1].innerText, '3000');
    assert.equal(fields[1].value, '');
    fields[1].value = '0:30';
    recalculate();
    assert.equal(totals['qe-totals-week'].innerText, '5400');
    assert.equal(rowTotals[1].innerText, '1800');
    fields[1].value = '';
    recalculate();
    assert.equal(totals['qe-totals-week'].innerText, '6600');
});
