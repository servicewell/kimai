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

function loadPlugin(name, context) {
    const source = fs.readFileSync(path.join(__dirname, '../../assets/js/plugins', name + '.js'), 'utf8')
        .replace(/^import .+;\r?\n/gm, '')
        .replace('export default class', 'class');

    return vm.runInNewContext(source + '\n' + name + ';', context);
}

test('Delete requires confirmation and closes the editor only after the API succeeds', () => {
    const form = {};
    const events = [];
    let click;
    let confirm;
    let response;
    const plugins = {
        alert: {
            question(message, callback) {
                assert.equal(message, 'confirm.delete');
                confirm = callback;
            },
        },
        api: {
            delete(url, success, error) {
                assert.equal(url, '/api/timesheets/42');
                response = { success, error };
            },
            handleError(message) { events.push(message); },
        },
        modal: {
            closeForm(currentForm) {
                assert.equal(currentForm, form);
                events.push('close');
            },
        },
        event: { trigger(name) { events.push(name); } },
    };
    const APILink = loadPlugin('KimaiAPILink', {
        KimaiPlugin: class {
            getContainer() { return { getPlugin: (name) => plugins[name] }; }
        },
        document: {
            addEventListener(type, callback) { click = callback; },
            dispatchEvent() {},
        },
        CustomEvent: class {},
    });
    new APILink('api-link').init();

    const deleteButton = {
        matches: () => false,
        classList: { contains: (name) => name === 'api-link' },
        closest: () => form,
        parentNode: null,
        dataset: {
            href: '/api/timesheets/42',
            method: 'DELETE',
            question: 'confirm.delete',
            msgError: 'action.delete.error',
            event: 'kimai.timesheetDelete',
            closeModal: 'true',
        },
    };
    const clickDelete = () => click({ target: deleteButton, preventDefault() {}, stopPropagation() {} });

    clickDelete();
    confirm(false);
    assert.equal(response, undefined);
    assert.deepEqual(events, []);

    clickDelete();
    confirm(true);
    response.error(new Error('Delete failed'));
    assert.deepEqual(events, ['action.delete.error']);

    clickDelete();
    confirm(true);
    response.success();
    assert.deepEqual(events, ['action.delete.error', 'close', 'kimai.timesheetDelete']);
});

test('Closing a completed form clears its dirty flag and leaves a replacement form open', () => {
    const form = {};
    const ModalForm = loadPlugin('KimaiAjaxModalForm', {
        KimaiReducedClickHandler: class {},
        document: { querySelector: () => form },
    });
    const modalForm = new ModalForm();
    let closes = 0;
    modalForm._isDirty = true;
    modalForm._getModal = () => ({
        hide() {
            assert.equal(modalForm._isDirty, false);
            closes++;
        },
    });

    modalForm.closeForm({});
    assert.equal(modalForm._isDirty, true);
    assert.equal(closes, 0);

    modalForm.closeForm(form);
    assert.equal(closes, 1);
});
