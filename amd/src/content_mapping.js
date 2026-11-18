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
 * Shared course-content tree. Loaded rows stay mounted so draft picks survive.
 *
 * @module local_curricmap/content_mapping
 * @copyright 2026 The Royal Veterinary College
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Fragment from 'core/fragment';
import Templates from 'core/templates';
import Autocomplete from 'core/form-autocomplete';
import Notification from 'core/notification';
import {getStrings} from 'core/str';

/**
 * Initialise the course editor.
 *
 * @param {number} contextid Course context for fragment permissions.
 * @param {string} placeholder Search placeholder.
 * @param {number} focuscm Optional activity to reveal.
 */
export const init = async(contextid, placeholder, focuscm = 0) => {
    const tree = document.querySelector('[data-curricmap-tree]');
    if (!tree) {
        return;
    }
    const [expand, collapse, loading, failed] = await getStrings(
        ['expandall', 'collapseall', 'loading', 'loadfailed'].map((name) =>
            ({key: 'contentmapping_' + name, component: 'local_curricmap'}))
    );
    const requests = new Map();
    const controls = document.querySelectorAll('[data-curricmap-control]');
    const expandbutton = document.querySelector('[data-curricmap-control="expandall"]');
    const status = document.querySelector('[data-curricmap-status]');
    let previewsOpen = true;
    let dirty = false;
    let bulk = false;
    const branches = () => Array.from(tree.querySelectorAll('[data-curricmap-branch]'));
    const previews = () => Array.from(tree.querySelectorAll('[data-curricmap-preview]'));
    const fullyExpanded = () => branches().every((branch) => branch.open &&
        (!branch.dataset.sectionid || branch.dataset.loaded === '1')) && previews().every((preview) => preview.open);
    const update = () => {
        expandbutton.textContent = fullyExpanded() ? collapse : expand;
    };
    const initrows = (root) => {
        root.querySelectorAll('select[data-curricmap-row]').forEach((select) => {
            if (!select.dataset.initialised) {
                select.dataset.initialised = '1';
                Autocomplete.enhance('#' + select.id, false, '', placeholder);
            }
        });
    };
    const load = (branch) => {
        if (!branch.dataset.sectionid || branch.dataset.loaded === '1') {
            return Promise.resolve();
        }
        if (requests.has(branch)) {
            return requests.get(branch);
        }
        const container = branch.querySelector('[data-curricmap-children]');
        const key = branch.dataset.curricmapBranch;
        const select = branch.querySelector('select[data-curricmap-row="' + key + '"]');
        const pending = select ? Array.from(select.selectedOptions).map((option) => option.value) : [];
        branch.querySelectorAll('[data-curricmap-picks="' + key + '"] input').forEach((input) => pending.push(input.value));
        container.textContent = loading;
        const params = {courseid: tree.dataset.courseid, sectionid: branch.dataset.sectionid,
            modtypes: tree.dataset.modtypes, nodetypes: tree.dataset.ntypes,
            pending: pending.join(','), returnurl: tree.dataset.returnurl};
        // Preserve both values from Moodle's jQuery promise before using native Promise methods.
        const fragment = Fragment.loadFragment('local_curricmap', 'activities', contextid, params)
            .then((html, js) => ({html, js}));
        const request = Promise.resolve(fragment)
            .then(async({html, js}) => {
                await Templates.replaceNodeContents(container, html, js);
                branch.dataset.loaded = '1';
                initrows(container);
                container.querySelectorAll('[data-curricmap-preview]').forEach((preview) => {
                    preview.open = previewsOpen;
                });
                update();
            }).catch((error) => {
                container.textContent = failed;
                throw error;
            }).finally(() => requests.delete(branch));
        requests.set(branch, request);
        return request;
    };
    const showAll = async(openText) => {
        previewsOpen = openText;
        // Loading a section inserts further branches. Visit each new level too.
        let remaining = branches().filter((branch) => !branch.open ||
            (branch.dataset.sectionid && branch.dataset.loaded !== '1'));
        while (remaining.length) {
            for (const branch of remaining) {
                branch.open = true;
                await load(branch);
            }
            remaining = branches().filter((branch) => !branch.open ||
                (branch.dataset.sectionid && branch.dataset.loaded !== '1'));
        }
        previews().forEach((preview) => { preview.open = openText; });
        update();
    };
    tree.addEventListener('toggle', (event) => {
        const target = event.target;
        if (target.matches('[data-curricmap-branch]') && target.open) {
            load(target).catch(Notification.exception);
        }
        update();
    }, true);
    tree.addEventListener('change', (event) => {
        dirty = true;
        const select = event.target.closest('select[data-curricmap-row]');
        if (select) {
            const tick = tree.querySelector('input[name="apply[' + select.dataset.curricmapRow + ']"]');
            if (tick) {
                tick.checked = select.selectedOptions.length > 0;
            }
        }
    });
    tree.addEventListener('click', (event) => {
        if (event.target.closest('[data-curricmap-pick], [data-curricmap-unpick]')) {
            dirty = true;
        }
    });
    window.addEventListener('beforeunload', (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    tree.addEventListener('submit', () => { dirty = false; });
    controls.forEach((button) => button.addEventListener('click', async() => {
        if (bulk) {
            return;
        }
        bulk = true;
        controls.forEach((control) => { control.disabled = true; });
        status.textContent = loading;
        try {
            switch (button.dataset.curricmapControl) {
                case 'topsections':
                    branches().forEach((branch) => { branch.open = false; });
                    break;
                case 'showall':
                    await showAll(false);
                    break;
                case 'expandall':
                    if (fullyExpanded()) {
                        previewsOpen = false;
                        previews().forEach((preview) => { preview.open = false; });
                    } else {
                        await showAll(true);
                    }
                    break;
            }
            status.textContent = '';
        } catch (error) {
            status.textContent = failed;
            Notification.exception(error);
        } finally {
            bulk = false;
            controls.forEach((control) => { control.disabled = false; });
            update();
        }
    }));
    initrows(tree);
    for (const sectionid of tree.dataset.focuspath.split(',').filter(Boolean)) {
        const branch = document.getElementById('curricmap-s' + sectionid);
        if (branch) {
            branch.open = true;
            try {
                await load(branch);
            } catch (error) {
                Notification.exception(error);
                break;
            }
        }
    }
    if (focuscm) {
        const activity = document.getElementById('curricmap-c' + focuscm);
        if (activity) {
            activity.scrollIntoView({block: 'center'});
        }
    }
    update();
};
