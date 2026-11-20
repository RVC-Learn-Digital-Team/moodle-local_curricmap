// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Independent draft/save/cancel state for each section, activity and chapter.
 *
 * @module local_curricmap/content_picker
 * @copyright 2026 The Royal Veterinary College
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import Fragment from 'core/fragment';
import Templates from 'core/templates';
import {getStrings} from 'core/str';

const states = new Map();
let strings;

/** @param {object} state Editor state. @returns {boolean} Whether the set differs from the saved set. */
const changed = (state) => state.saved.size !== state.draft.size || [...state.saved.keys()].some((id) => !state.draft.has(id));

/** @param {HTMLElement} editor Editor element. Update its controls and readable selection list. */
const render = (editor) => {
    const state = states.get(editor);
    const choices = editor.querySelector('[data-curricmap-choices]');
    choices.replaceChildren();
    state.draft.forEach((label, uuid) => {
        const entry = document.createElement('div');
        entry.className = 'curricmap-choice';
        const text = document.createElement('span');
        text.textContent = label;
        const badge = document.createElement('small');
        badge.textContent = state.saved.has(uuid) ? strings.saved : strings.unsaved;
        badge.className = 'curricmap-choice-status';
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-outline-secondary btn-sm';
        remove.dataset.curricmapRemove = uuid;
        remove.textContent = strings.remove;
        remove.setAttribute('aria-label', strings.remove + ': ' + label);
        remove.disabled = state.saving;
        entry.append(text, badge, remove);
        choices.append(entry);
    });
    if (!state.draft.size) {
        choices.textContent = strings.empty;
    }
    editor.querySelectorAll('[data-curricmap-save], [data-curricmap-cancel]').forEach((button) => {
        button.disabled = state.saving || !changed(state);
    });
    editor.querySelectorAll('[data-curricmap-pick]').forEach((button) => {
        const selected = state.draft.has(button.dataset.curricmapPick);
        button.disabled = state.saving || selected;
        button.textContent = selected ? strings.selected : strings.pick;
    });
};

/** @param {HTMLElement} root Newly rendered content. */
export const initRows = (root) => {
    root.querySelectorAll('[data-curricmap-editor]').forEach((editor) => {
        if (!states.has(editor)) {
            const saved = new Map(JSON.parse(editor.dataset.saved).map(({uuid, label}) => [uuid, label]));
            states.set(editor, {saved, draft: new Map(saved), saving: false, request: 0});
            render(editor);
        }
    });
};

/**
 * Read a parent's pending choices for the existing lazy-loading hint cascade.
 * @param {HTMLElement} branch Parent section.
 * @returns {string[]} Selected UUIDs.
 */
export const selections = (branch) => {
    const editor = branch.querySelector('[data-curricmap-editor]');
    return editor && states.has(editor) ? [...states.get(editor).draft.keys()] : [];
};

/**
 * Keep loaded section summaries in step with an activity's saved mappings.
 * @param {HTMLElement} editor Saved editor.
 * @param {number} delta Change in the number of mapped activities.
 */
const updateCounts = (editor, delta) => {
    if (!editor.dataset.curricmapEditor.startsWith('c') || !delta) {
        return;
    }
    let branch = editor.closest('[data-curricmap-branch]');
    while (branch) {
        const count = branch.querySelector('[data-curricmap-counts]');
        if (count) {
            count.dataset.mapped = String(Number(count.dataset.mapped) + delta);
            count.textContent = count.dataset.countTemplate.replace('__MAPPED__', count.dataset.mapped);
        }
        branch = branch.parentElement.closest('[data-curricmap-branch]');
    }
};

/**
 * Bind the shared mapper's row-level controls.
 * @param {HTMLElement} tree Content tree.
 * @param {number} contextid Course context.
 */
export const init = async(tree, contextid) => {
    const names = ['saved', 'unsaved', 'pending', 'remove', 'empty', 'selected', 'pick', 'saving', 'savefailed',
        'cancelled', 'loading', 'loadfailed'];
    const values = await getStrings(names.map((name) => ({key: 'contentmapping_' + name, component: 'local_curricmap'})));
    strings = Object.fromEntries(names.map((name, index) => [name, values[index]]));
    initRows(tree);
    const feedback = (editor, text, error = false) => {
        const status = editor.querySelector('[data-curricmap-save-status]');
        status.textContent = text;
        status.classList.toggle('text-danger', error);
    };
    const browse = async(editor, root = '') => {
        const state = states.get(editor);
        const request = ++state.request;
        const results = editor.querySelector('[data-curricmap-results]');
        results.textContent = strings.loading;
        try {
            // Fragment is a jQuery promise with two resolution arguments; never call finally() on it.
            const {html, js} = await Fragment.loadFragment('local_curricmap', 'browsenode', contextid, {
                courseid: tree.dataset.courseid, key: editor.dataset.curricmapEditor, root,
                query: editor.querySelector('[data-curricmap-query]').value.trim()
            }).then((html, js) => ({html, js}));
            if (request === state.request) {
                await Templates.replaceNodeContents(results, html, js);
                render(editor);
            }
        } catch (error) {
            if (request === state.request) {
                results.textContent = strings.loadfailed;
            }
        }
    };
    tree.addEventListener('click', async(event) => {
        const editor = event.target.closest('[data-curricmap-editor]');
        const button = event.target.closest('button');
        if (!editor || !button) {
            return;
        }
        const state = states.get(editor);
        if (state.saving) {
            return;
        }
        if (button.matches('[data-curricmap-pick]')) {
            state.draft.set(button.dataset.curricmapPick, button.dataset.curricmapPicklabel);
            feedback(editor, changed(state) ? strings.pending : strings.saved);
        } else if (button.matches('[data-curricmap-remove]')) {
            state.draft.delete(button.dataset.curricmapRemove);
            feedback(editor, changed(state) ? strings.pending : strings.saved);
        } else if (button.matches('[data-curricmap-cancel]')) {
            state.draft = new Map(state.saved);
            feedback(editor, strings.cancelled);
        } else if (button.matches('[data-curricmap-save]')) {
            state.saving = true;
            render(editor);
            feedback(editor, strings.saving);
            try {
                const response = await Ajax.call([{
                    methodname: 'local_curricmap_save_content_mappings',
                    args: {courseid: tree.dataset.courseid, key: editor.dataset.curricmapEditor,
                        nodeuuids: [...state.draft.keys()]}
                }])[0];
                updateCounts(editor, Number(response.mappings.length > 0) - Number(state.saved.size > 0));
                state.saved = new Map(response.mappings.map(({uuid, label}) => [uuid, label]));
                state.draft = new Map(state.saved);
                feedback(editor, strings.saved);
            } catch (error) {
                feedback(editor, strings.savefailed + (error.message ? ' ' + error.message : ''), true);
            } finally {
                state.saving = false;
            }
        } else if (button.matches('[data-curricmap-open-browser]')) {
            const panel = editor.querySelector('[data-curricmap-browser]');
            panel.hidden = !panel.hidden;
            button.setAttribute('aria-expanded', String(!panel.hidden));
            if (!panel.hidden) {
                const input = editor.querySelector('[data-curricmap-query]');
                input.focus({preventScroll: true});
                panel.scrollIntoView({block: 'nearest'});
                await browse(editor);
            }
        } else if (button.matches('[data-curricmap-drill]')) {
            editor.querySelector('[data-curricmap-query]').value = '';
            clearTimeout(state.timer);
            await browse(editor, button.dataset.curricmapDrill);
        }
        render(editor);
    });
    tree.addEventListener('input', (event) => {
        if (event.target.matches('[data-curricmap-query]')) {
            const editor = event.target.closest('[data-curricmap-editor]');
            const state = states.get(editor);
            // Invalidate the previous response immediately, including during the debounce delay.
            state.request++;
            clearTimeout(state.timer);
            state.timer = setTimeout(() => browse(editor), 250);
        }
    });
    tree.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.matches('[data-curricmap-query]')) {
            event.preventDefault();
            const editor = event.target.closest('[data-curricmap-editor]');
            clearTimeout(states.get(editor).timer);
            browse(editor);
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if ([...states.values()].some((state) => state.saving || changed(state))) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
};
