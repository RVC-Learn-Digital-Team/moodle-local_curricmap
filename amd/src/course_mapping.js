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
 * Toolbar and row behaviour for the central course matching page.
 *
 * The toolbar is one GET form: changing a filter control resubmits it, so
 * the current search text and every other filter always travel together.
 * The Sofia programme-year select is the one long list on the page, so it
 * alone gets the core autocomplete (substring search) — and it only
 * resubmits on a real selection, never on clearing, so the server can't
 * "helpfully" default it back to the first node. Picking a proposed match
 * in a row ticks that row's apply checkbox (and clearing it unticks).
 *
 * @module     local_curricmap/course_mapping
 * @copyright  2026 The Royal Veterinary College
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Autocomplete from 'core/form-autocomplete';

export const init = (placeholder) => {
    const form = document.querySelector('.local-curricmap-filterform');
    if (form) {
        form.querySelectorAll('select:not([data-curricmap-node]), input[type=checkbox]').forEach((control) => {
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
    }
    document.querySelectorAll('select[data-curricmap-row]').forEach((select) => {
        select.addEventListener('change', () => {
            const tick = document.querySelector('input[name="apply[' + select.dataset.curricmapRow + ']"]');
            if (tick) {
                tick.checked = select.value !== '';
            }
        });
    });
};
