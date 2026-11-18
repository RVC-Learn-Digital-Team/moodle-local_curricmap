// Regression tests for Moodle's multi-value jQuery fragment promise.
// Run from the plugin root: node --test tests/content_mapping_test.cjs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');

// jQuery resolves multiple arguments and chains without native Promise.finally.
const jqueryPromise = (promise) => ({
    then(onFulfilled, onRejected) {
        const next = promise.then((args) => onFulfilled ? onFulfilled(...args) : args[0], onRejected);
        return jqueryPromise(next.then((value) => [value]));
    },
    catch(onRejected) {
        return this.then(undefined, onRejected);
    },
});

const setup = async({failFirst = false, failRender = false} = {}) => {
    const state = {branches: [], previews: [], loads: [], renders: [], errors: []};
    const controls = Object.fromEntries(['topsections', 'showall', 'expandall'].map((name) => [name, {
        dataset: {curricmapControl: name}, disabled: false, textContent: '',
        addEventListener(type, handler) { this[type] = handler; },
    }]));
    const status = {textContent: ''};
    const addBranch = (id) => {
        const container = {textContent: '', querySelectorAll: () => []};
        const branch = {
            dataset: {sectionid: id, curricmapBranch: 's' + id}, open: false, container,
            querySelector(selector) {
                return selector === '[data-curricmap-children]' ? container : null;
            },
            querySelectorAll: () => [],
            matches: (selector) => selector === '[data-curricmap-branch]',
        };
        state.branches.push(branch);
        return branch;
    };
    const root = addBranch('1');
    const tree = {
        dataset: {courseid: '10', returnurl: '/local/curricmap/section_module_mapping.php?courseid=10',
            modtypes: '', ntypes: '', focuspath: ''},
        querySelectorAll(selector) {
            if (selector === '[data-curricmap-branch]') { return state.branches; }
            if (selector === '[data-curricmap-preview]') { return state.previews; }
            return [];
        },
        addEventListener(type, handler) { this[type] = handler; },
    };
    const modules = {
        'core/fragment': {loadFragment(component, callback, contextid, params) {
            state.loads.push(params);
            const response = failFirst && state.loads.length === 1
                ? Promise.reject(new Error('Fragment unavailable'))
                : Promise.resolve(['<p>Section ' + params.sectionid + '</p>', 'initialise-' + params.sectionid]);
            return jqueryPromise(response);
        }},
        'core/templates': {async replaceNodeContents(container, html, js) {
            if (failRender && state.renders.length === 0) {
                state.renders.push({failed: true});
                throw new Error('Render unavailable');
            }
            state.renders.push({html, js});
            if (container === root.container && state.branches.length === 1) { addBranch('2'); }
            const preview = {open: true, draft: 'unsaved-node'};
            state.previews.push(preview);
            container.querySelectorAll = (selector) => selector === '[data-curricmap-preview]' ? [preview] : [];
        }},
        'core/form-autocomplete': {enhance() {}},
        'core/notification': {exception(error) { state.errors.push(error.message); }},
        'core/str': {getStrings: async() => ['Expand all', 'Collapse all', 'Loading', 'Load failed']},
    };
    const exported = {};
    const context = {
        document: {
            querySelector(selector) {
                if (selector === '[data-curricmap-tree]') { return tree; }
                if (selector === '[data-curricmap-status]') { return status; }
                return controls.expandall;
            },
            querySelectorAll: () => Object.values(controls),
        },
        window: {addEventListener() {}},
        define(name, dependencies, factory) {
            factory(...dependencies.map((dependency) => dependency === 'exports' ? exported : modules[dependency]));
        },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../amd/build/content_mapping.min.js'), 'utf8'), context);
    await exported.init(10, 'Search');
    return {state, controls, tree, root, status};
};

for (const action of ['showall', 'expandall']) {
    test(`${action} loads nested sections with both jQuery fragment values`, async() => {
        const {state, controls, status} = await setup();
        await controls[action].click();
        assert.deepEqual(state.errors, []);
        assert.deepEqual(state.loads.map((params) => params.sectionid), ['1', '2']);
        assert.deepEqual(state.renders.map(({html, js}) => [html, js]), [
            ['<p>Section 1</p>', 'initialise-1'], ['<p>Section 2</p>', 'initialise-2'],
        ]);
        assert.ok(state.branches.every((branch) => branch.open && branch.dataset.loaded === '1'));
        assert.ok(state.previews.every((preview) => preview.open === (action === 'expandall')));
        assert.ok(Object.values(controls).every((control) => !control.disabled));
        assert.equal(status.textContent, '');
        const savedPreview = state.previews[0];
        await controls.topsections.click();
        await controls[action].click();
        assert.equal(state.loads.length, 2, 'Collapsing and reopening must reuse loaded rows');
        assert.equal(state.previews[0], savedPreview);
        assert.equal(savedPreview.draft, 'unsaved-node');
    });
}

test('failed fragment is removed from in-flight requests and can be retried', async() => {
    const {state, controls, root, status} = await setup({failFirst: true});
    await controls.expandall.click();
    assert.deepEqual(state.errors, ['Fragment unavailable']);
    assert.equal(root.dataset.loaded, undefined);
    assert.equal(status.textContent, 'Load failed');
    assert.ok(Object.values(controls).every((control) => !control.disabled));
    await controls.expandall.click();
    assert.deepEqual(state.loads.map((params) => params.sectionid), ['1', '1', '2']);
    assert.equal(root.dataset.loaded, '1');
    assert.equal(controls.expandall.textContent, 'Collapse all');
});

test('failed rendering also clears the request and can be retried', async() => {
    const {state, controls, root} = await setup({failRender: true});
    await controls.showall.click();
    assert.deepEqual(state.errors, ['Render unavailable']);
    assert.equal(root.dataset.loaded, undefined);
    await controls.showall.click();
    assert.equal(root.dataset.loaded, '1');
    assert.deepEqual(state.loads.map((params) => params.sectionid), ['1', '1', '2']);
});

test('opening a section while Expand all is loading shares the same request', async() => {
    const {state, controls, tree, root} = await setup();
    const expanding = controls.expandall.click();
    tree.toggle({target: root});
    await expanding;
    assert.deepEqual(state.errors, []);
    assert.deepEqual(state.loads.map((params) => params.sectionid), ['1', '2']);
    await controls.expandall.click();
    assert.equal(controls.expandall.textContent, 'Expand all');
    assert.ok(state.previews.every((preview) => !preview.open));
});
