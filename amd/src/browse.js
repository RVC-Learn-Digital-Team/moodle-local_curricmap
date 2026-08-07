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
 * Browse-the-curriculum panels on the content mapping page.
 *
 * Search fails when a Moodle name shares no words with Sofia's, so every
 * mapping row carries a Browse link: a breadcrumb + one-level tree walker
 * (fragment-rendered, ceiling = the slug-year node). Picking a node at any
 * level adds a chip with a hidden bind{key}[] input beside the row's select,
 * so Apply reads browse picks and dropdown picks through the same path.
 * The panel stays open after a pick (multi-pick, close explicitly).
 *
 * Course grain (course_mapping.php, ruled 2026-08-06): the browse link
 * carries data-curricmap-grain="course" (+ optional data-curricmap-year and
 * data-curricmap-pickmode="select"). The walker then starts at the programme
 * list, floors at strand, and a pick SETS the row's single select instead of
 * adding a chip - one central anchor per course, last pick wins.
 *
 * @module     local_curricmap/browse
 * @copyright  2026 The Royal Veterinary College
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Fragment from 'core/fragment';
import Templates from 'core/templates';

let fragmentcontextid = 0;

/**
 * Load one level of the tree into a row's panel.
 *
 * @param {string} key Row key.
 * @param {string} root Node uuid to list.
 */
const load = (key, root) => {
    const panel = document.querySelector('[data-curricmap-browsepanel="' + key + '"]');
    if (!panel) {
        return;
    }
    panel.classList.remove('d-none');
    panel.setAttribute('data-curricmap-loaded', root);
    const params = {root: root, key: key};
    if (panel.dataset.curricmapGrain) {
        params.grain = panel.dataset.curricmapGrain;
    }
    if (panel.dataset.curricmapYear) {
        params.year = panel.dataset.curricmapYear;
    }
    Fragment.loadFragment('local_curricmap', 'browsenode', fragmentcontextid, params)
        .then((html, js) => {
            Templates.replaceNodeContents(panel, html, js);
            return null;
        })
        .catch(() => null);
};

/**
 * Add a picked node as a chip + hidden input, and tick the row.
 *
 * @param {string} key Row key.
 * @param {string} uuid Node uuid.
 * @param {string} label Full label for the chip.
 */
const pick = (key, uuid, label) => {
    const panel = document.querySelector('[data-curricmap-browsepanel="' + key + '"]');
    if (panel && panel.dataset.curricmapPickmode === 'select') {
        const select = document.getElementById('curricmap-bind-' + key);
        if (!select) {
            return;
        }
        if (![...select.options].some((option) => option.value === uuid)) {
            select.add(new Option(label, uuid));
        }
        select.value = uuid;
        // Resync the core autocomplete when the select is enhanced, and run
        // the row's own change behaviours.
        select.dispatchEvent(new Event('change', {bubbles: true}));
        const tick = document.querySelector('input[name="apply[' + key + ']"]');
        if (tick) {
            tick.checked = true;
        }
        panel.classList.add('d-none');
        return;
    }
    const picks = document.querySelector('[data-curricmap-picks="' + key + '"]');
    if (!picks || picks.querySelector('input[value="' + uuid + '"]')) {
        return;
    }
    const chip = document.createElement('span');
    chip.className = 'badge bg-secondary text-light me-1';
    chip.appendChild(document.createTextNode(label + ' '));
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'bind' + key + '[]';
    input.value = uuid;
    chip.appendChild(input);
    const remove = document.createElement('a');
    remove.href = '#';
    remove.setAttribute('data-curricmap-unpick', '1');
    remove.textContent = '×';
    remove.className = 'text-light';
    chip.appendChild(remove);
    picks.appendChild(chip);
    const tick = document.querySelector('input[name="apply[' + key + ']"]');
    if (tick) {
        tick.checked = true;
    }
};

/**
 * Initialise the delegated handlers.
 *
 * @param {number} contextid System context id for the fragment calls.
 */
export const init = (contextid = 0) => {
    fragmentcontextid = contextid;
    document.addEventListener('click', (event) => {
        const browse = event.target.closest('[data-curricmap-browse]');
        if (browse) {
            event.preventDefault();
            const key = browse.getAttribute('data-curricmap-browse');
            const panel = document.querySelector('[data-curricmap-browsepanel="' + key + '"]');
            if (panel) {
                ['Grain', 'Year', 'Pickmode'].forEach((suffix) => {
                    const value = browse.dataset['curricmap' + suffix];
                    if (value) {
                        panel.dataset['curricmap' + suffix] = value;
                    }
                });
            }
            if (panel && !panel.classList.contains('d-none')) {
                panel.classList.add('d-none');
            } else {
                load(key, browse.getAttribute('data-curricmap-root'));
            }
            return;
        }
        const drill = event.target.closest('[data-curricmap-drill]');
        if (drill) {
            event.preventDefault();
            load(drill.getAttribute('data-curricmap-key'), drill.getAttribute('data-curricmap-drill'));
            return;
        }
        const picker = event.target.closest('[data-curricmap-pick]');
        if (picker) {
            event.preventDefault();
            pick(
                picker.getAttribute('data-curricmap-key'),
                picker.getAttribute('data-curricmap-pick'),
                picker.getAttribute('data-curricmap-picklabel')
            );
            return;
        }
        const unpick = event.target.closest('[data-curricmap-unpick]');
        if (unpick) {
            event.preventDefault();
            unpick.closest('span').remove();
        }
    });
};
