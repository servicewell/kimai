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

const source = fs.readFileSync(path.join(__dirname, '../../assets/js/plugins/KimaiActiveRecords.js'), 'utf8')
    .replace(/^import .+;\r?\n/gm, '')
    .replace('export default class KimaiActiveRecords', 'class KimaiActiveRecords');

test('running entries in a timesheet list tick without the start and stop menu', () => {
    const record = { dataset: { since: '2026-11-24T09:00:00Z' } };
    const listeners = new Map();
    const document = {
        title: 'Timesheets',
        querySelector: () => null,
        querySelectorAll: () => [record],
        addEventListener(type, callback) { listeners.set(type, callback); },
    };
    let tick;
    const ActiveRecords = vm.runInNewContext(source + '\nKimaiActiveRecords;', {
        KimaiPlugin: class {},
        document,
        setInterval(callback, delay) {
            assert.equal(delay, 1000);
            tick = callback;
        },
    });

    const records = new ActiveRecords();
    records.getConfiguration = () => true;
    let seconds = 14;
    records.getDateUtils = () => ({
        formatDuration(since, withSeconds) {
            assert.equal(since, record.dataset.since);
            assert.equal(withSeconds, true);
            return `0:${seconds}`;
        },
    });
    records.reloadActiveRecords = () => assert.fail('The absent menu cannot be reloaded');

    records.init();
    assert.equal(record.textContent, '0:14');
    seconds = 15;
    tick();
    assert.equal(record.textContent, '0:15');
    seconds = 16;
    listeners.get('kimai.timesheetUpdate')();
    assert.equal(record.textContent, '0:16');
    assert.equal(document.title, 'Timesheets');
});
