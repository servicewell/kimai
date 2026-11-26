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
const { createEventInstance, DateEnv, EventApi, refineEventDef, parseEventDef, globalPlugins } = createRequire(fullCalendarPath)('@fullcalendar/common');
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

function createCalendar(now, dates, timezone = 'UTC', clipboard) {
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
        dispatchEvent() {},
    };
    const Calendar = vm.runInNewContext(calendarSource + '\nKimaiCalendar;', {
        DateTime,
        CustomEvent,
        document,
        navigator: { clipboard },
        KimaiColor: { calculateContrastColor() { return '#000'; } },
    });
    const calendar = Object.create(Calendar.prototype);
    calendar.options = { now, timezone, patterns: { title: '{duration}' } };
    calendar.kimai = { getPlugin() { return { formatSeconds: String, formatDuration: String }; }, getConfiguration() { return { get() { return '#fff'; } }; } };
    return { calendar, headers };
}

test('calendar uses the customer color ahead of the project and activity colors', () => {
    const { calendar } = createCalendar('2026-11-24T10:00:00Z', []);
    const customer = { name: 'Customer', color: '#357ecd', 'color-safe': '#663399' };
    const record = { project: { name: 'Project', color: '#228833', customer }, activity: { name: 'Activity', color: '#004488' }, end: null };

    assert.equal(calendar.convertSourceForCalendar(record).color, '#357ecd');
    customer.color = '#fff';
    assert.equal(calendar.convertSourceForCalendar(record).color, '#663399');
    delete customer['color-safe'];
    assert.equal(calendar.convertSourceForCalendar(record).color, '#fff');
});

test('calendar copies a card description with its line breaks and punctuation intact', async () => {
    const writes = [];
    const notifications = [];
    const { calendar } = createCalendar('2026-11-24T10:00:00Z', [], 'UTC', { async writeText(text) { writes.push(text); } });
    calendar.options.translations = { descriptionCopied: 'Copied', descriptionCopyFailed: 'Failed' };
    calendar.kimai.getPlugin = () => ({ success: message => notifications.push(message), warning: message => notifications.push(message) });
    const description = { textContent: 'Mobile forms & UI\nTested <draft>.' };
    const button = { closest: () => ({ querySelector: () => description }) };

    await calendar.copyDescription(button);

    assert.deepEqual(writes, [description.textContent]);
    assert.deepEqual(notifications, ['Copied']);
});

test('dragging a day card includes its description independently of the optional copy-data setting', () => {
    const { calendar } = createCalendar('2026-11-24T10:00:00Z', []);
    const card = {projectId: 3, activityId: 4, description: 'First line\nSecond line', tags: ['forms']};

    calendar.options.dragDropCopyData = false;
    const day = calendar.getCardDropData(card, 'timeGridDay');
    assert.equal(day.description, card.description);
    assert.equal(day.project, 3);
    assert.equal(day.activity, 4);
    assert.ok(!Object.hasOwn(day, 'tags'));

    calendar.options.dragDropCopyData = true;
    assert.equal(calendar.getCardDropData(card, 'timeGridDay').tags, 'forms');
    for (const type of ['timeGridWeek', 'dayGridMonth']) {
        assert.ok(!Object.hasOwn(calendar.getCardDropData(card, type), 'description'));
    }
});

function event(calendar, begin, end, duration = 0, pause = 0, {customerId = 1, description = '', tags = []} = {}) {
    const record = {
        id: 1, begin, end, duration, break: pause, description, tags,
        project: { id: 2, name: 'Project', color: '#fff', customer: { id: customerId, name: 'Customer', color: '#fff' } },
        activity: { id: 3, name: 'Activity', color: '#fff' },
    };
    const converted = calendar.convertSourceForCalendar(record);
    const { refined, extra } = refineEventDef(converted, eventContext);
    const definition = parseEventDef(refined, extra, 'kimai-user-timesheets', false, end !== null, eventContext);
    const dateEnv = new DateEnv({
        timeZone: calendar.options.timezone,
        calendarSystem: 'gregory',
        locale: { week: { dow: 1, doy: 4 }, options: {} },
    });
    const startMeta = dateEnv.createMarkerMeta(converted.start);
    const endMeta = converted.end === null ? null : dateEnv.createMarkerMeta(converted.end);
    const instance = createEventInstance(definition.defId, {
        start: startMeta.marker,
        end: endMeta?.marker ?? startMeta.marker,
    }, startMeta.forcedTzo, endMeta?.forcedTzo ?? null);
    const rendered = new EventApi({ dateEnv }, definition, instance);
    return {
        id: rendered.id,
        start: rendered.start,
        startStr: rendered.startStr,
        end: rendered.end,
        backgroundColor: rendered.backgroundColor,
        source: { id: 'kimai-user-timesheets' },
        extendedProps: rendered.extendedProps,
    };
}

test('customer summary follows the visible range even when the calendar reuses cached events', () => {
    const { calendar } = createCalendar('2026-11-26T12:00:00+01:00', [], 'Europe/Stockholm');
    const records = [
        event(calendar, '2026-11-05T09:00:00+01:00', '2026-11-05T13:00:00+01:00', 14400),
        event(calendar, '2026-11-25T09:00:00+01:00', '2026-11-25T11:00:00+01:00', 7200),
        event(calendar, '2026-11-26T00:30:00+01:00', '2026-11-26T01:30:00+01:00', 3600),
        event(calendar, '2026-11-26T11:00:00+01:00', null, 0, 600),
        event(calendar, '2026-11-28T09:00:00+01:00', '2026-11-28T10:00:00+01:00', 3600),
        event(calendar, '2026-12-01T09:00:00+01:00', '2026-12-01T13:00:00+01:00', 14400),
    ];
    records.push({ ...records[2], source: {id: 'google-holidays'} });
    calendar.options.showWeekends = false;
    const duration = (type, start, end) => calendar.getPeriodSummary(records, {
        type, activeStart: new Date(start), activeEnd: new Date(end),
    });

    for (const [type, start, end, expected] of [
        ['timeGridDay', '2026-11-26', '2026-11-27', 6600],
        ['timeGridWeek', '2026-11-23', '2026-11-30', 13800],
        ['dayGridMonth', '2026-10-26', '2026-11-30', 28200],
    ]) {
        const summary = duration(type, start, end);
        assert.equal(summary.groups[0][1].duration, expected);
        assert.equal(summary.registered, expected);
        assert.equal(summary.worked, expected);
    }
});

test('customer summary groups descriptions only in day view and distinguishes tags and customer IDs', () => {
    const { calendar } = createCalendar('2026-11-26T12:00:00Z', []);
    const records = [
        {description: 'First', tags: ['forms', 'build']},
        {description: 'First', tags: ['build', 'forms']},
        {description: 'Second', tags: ['forms', 'build']},
        {description: 'First', tags: ['review']},
        {customerId: 5, description: 'First', tags: ['forms', 'build']},
    ].map(details => event(calendar, '2026-11-26T08:00:00Z', '2026-11-26T09:00:00Z', 3600, 0, details));
    const view = {type: 'timeGridDay', activeStart: new Date('2026-11-26'), activeEnd: new Date('2026-11-27')};
    const {groups: day} = calendar.getPeriodSummary(records, view);
    assert.equal(day.length, 2);
    assert.equal(day[0][0], '1');
    assert.equal(day[1][0], '5');
    assert.deepEqual(Array.from(day[0][1].cards.values(), card => [card.description, card.duration]), [
        ['First', 7200], ['Second', 3600], ['First', 3600],
    ]);

    for (const type of ['timeGridWeek', 'dayGridMonth']) {
        const {groups} = calendar.getPeriodSummary(records, {...view, type});
        assert.deepEqual(Array.from(groups[0][1].cards.values(), card => [card.description, card.duration]), [
            ['', 10800], ['', 3600],
        ]);
    }
});

test('worked time counts overlapping clock time once across customers while registered time adds all entries', () => {
    const { calendar } = createCalendar('2026-11-26T16:00:00+01:00', [], 'Europe/Stockholm');
    const records = [
        event(calendar, '2026-11-26T12:30:00+01:00', '2026-11-26T16:00:00+01:00', 12600, 0, {customerId: 2}),
        event(calendar, '2026-11-26T08:00:00+01:00', '2026-11-26T08:30:00+01:00', 1800),
        event(calendar, '2026-11-26T12:00:00+01:00', '2026-11-26T15:30:00+01:00', 12600),
    ];
    const view = {type: 'timeGridDay', activeStart: new Date('2026-11-26'), activeEnd: new Date('2026-11-27')};
    const summary = calendar.getPeriodSummary(records, view);

    assert.equal(summary.worked, 16200);
    assert.equal(summary.registered, 27000);
    assert.deepEqual(Array.from(summary.groups, ([id, group]) => [id, group.duration]), [['1', 14400], ['2', 12600]]);
});

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

test('calendar groups running time by the visible start day across midnight and UTC date boundaries', () => {
    const { calendar, headers } = createCalendar('2026-11-24T01:00:00+01:00', ['2026-11-23', '2026-11-24'], 'Europe/Stockholm');
    calendar._renderDayAndWeekSum('timeGridWeek', [event(calendar, '2026-11-23T23:00:00+01:00', null)]);
    assert.equal(headers.get('2026-11-23').total, '7200');
    assert.equal(headers.get('2026-11-24').total, '0');

    const utc = createCalendar('2026-11-24T02:30:00+14:00', ['2026-11-23', '2026-11-24']);
    utc.calendar._renderDayAndWeekSum('timeGridWeek', [event(utc.calendar, '2026-11-24T00:30:00+14:00', null)]);
    assert.equal(utc.headers.get('2026-11-23').total, '7200');
    assert.equal(utc.headers.get('2026-11-24').total, '0');
});

test('calendar handles DST elapsed time and its day view', () => {
    const { calendar, headers } = createCalendar('2026-03-29T03:30:00+02:00', ['2026-03-29'], 'Europe/Stockholm');
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

test('calendar reloads the edited source after saving a move or resize', () => {
    const { calendar, headers } = createCalendar('2026-11-26T18:00:00Z', ['2026-11-24', '2026-11-25']);
    const original = event(calendar, '2026-11-24T08:00:00Z', '2026-11-24T12:00:00Z', 14400);
    calendar._renderDayAndWeekSum('timeGridWeek', [original]);

    const saved = event(calendar, '2026-11-25T08:00:00Z', '2026-11-25T13:00:00Z', 17400);
    const edited = { ...saved, extendedProps: original.extendedProps };
    edited.source.refetch = () => calendar._renderDayAndWeekSum('timeGridWeek', [saved]);

    let completeSave;
    const api = {
        patch(url, payload, onSuccess) {
            assert.equal(url, '/api/timesheets/1');
            assert.deepEqual(JSON.parse(payload), {
                begin: '2026-11-25T08:00:00.000Z',
                end: '2026-11-25T13:00:00.000Z',
            });
            completeSave = onSuccess;
        },
    };
    const dates = { formatSeconds: String, formatForAPI: date => date.toISOString() };
    calendar.options.url = { update: id => `/api/timesheets/${id}` };
    calendar.kimai.getPlugin = name => name === 'api' ? api : dates;
    calendar.changeHandler({ event: edited });

    assert.equal(headers.get('2026-11-24').total, '14400');
    completeSave();
    assert.equal(headers.get('2026-11-24').total, '0');
    assert.equal(headers.get('2026-11-25').total, '17400');
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
