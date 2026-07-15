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
 * Toolbar and row behaviour for the central matching pages.
 *
 * Single-value filter controls auto-submit the toolbar form (multi-select
 * filters wait for the Go button). The Sofia node select resubmits only on
 * a real selection. Picking in a row's proposal select ticks that row's
 * apply checkbox; long flat selects get the core autocomplete (substring
 * search). Sections' activity mapping rows load lazily via the fragment
 * API when opened, then get the same row behaviours.
 *
 * @module     local_curricmap/course_mapping
 * @copyright  2026 The Royal Veterinary College
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Autocomplete from 'core/form-autocomplete';
import Fragment from 'core/fragment';
import Templates from 'core/templates';

let searchplaceholder = '';
let fragmentcontextid = 0;

const loadsection = (button) => {
    const container = document.getElementById(button.dataset.curricmapExpand);
    if (!container) {
        return;
    }
    // Not-yet-saved picks in the section's own select act as its match for
    // pool purposes, so activities can be mapped in the same apply.
    let pending = '';
    const sectionselect = document.querySelector(
        'select[data-curricmap-row="s' + button.dataset.curricmapSection + '"]');
    if (sectionselect) {
        pending = Array.from(sectionselect.selectedOptions).map((option) => option.value)
            .filter((value) => value !== '').join(',');
    }
    container.dataset.loaded = 1;
    container.classList.remove('d-none');
    container.textContent = '…';
    const params = {
        courseid: button.dataset.curricmapCourse,
        sectionid: button.dataset.curricmapSection,
        modtypes: button.dataset.curricmapModtypes || '',
        nodetypes: button.dataset.curricmapNtypes || '',
        pending: pending,
        returnurl: button.dataset.curricmapReturn || '',
    };
    Fragment.loadFragment('local_curricmap', 'activities', fragmentcontextid, params)
        .then((html, js) => {
            Templates.replaceNodeContents(container, html, js);
            initrows(container);
            return null;
        })
        .catch(() => {
            container.textContent = '!';
        });
};

const initrows = (root) => {
    root.querySelectorAll('select[data-curricmap-row]').forEach((select) => {
        select.addEventListener('change', () => {
            const key = select.dataset.curricmapRow;
            const tick = document.querySelector('input[name="apply[' + key + ']"]');
            if (tick) {
                tick.checked = select.value !== '';
            }
            // A section's picks changed: refresh its open activity list so
            // the pools reflect the pending selection.
            if (key.charAt(0) === 's') {
                const button = document.querySelector(
                    '[data-curricmap-expand="curricmap-sec-' + key.slice(1) + '"]');
                const container = button && document.getElementById(button.dataset.curricmapExpand);
                if (container && container.dataset.loaded && !container.classList.contains('d-none')) {
                    loadsection(button);
                }
            }
        });
        if (select.dataset.curricmapSearch) {
            Autocomplete.enhance('#' + select.id, false, '', searchplaceholder);
        }
    });
};

export const init = (placeholder, contextid = 0) => {
    searchplaceholder = placeholder;
    fragmentcontextid = contextid;

    const form = document.querySelector('.local-curricmap-filterform');
    if (form) {
        form.querySelectorAll('select:not([data-curricmap-node]):not([multiple]), input[type=checkbox]')
            .forEach((control) => {
                control.addEventListener('change', () => form.submit());
            });
        const nodeselect = form.querySelector('select[data-curricmap-node]');
        if (nodeselect) {
            nodeselect.addEventListener('change', () => {
                if (nodeselect.value !== '') {
                    form.submit();
                }
            });
            Autocomplete.enhance('#' + nodeselect.id, false, '', placeholder);
        }
        form.querySelectorAll('select[multiple]').forEach((select) => {
            Autocomplete.enhance('#' + select.id, false, '', placeholder);
        });
    }

    initrows(document);

    document.querySelectorAll('[data-curricmap-expand]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const container = document.getElementById(button.dataset.curricmapExpand);
            if (!container) {
                return;
            }
            if (container.dataset.loaded) {
                container.classList.toggle('d-none');
                return;
            }
            loadsection(button);
        });
    });
};
