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
 * The whole toolbar is one GET form: changing any select or checkbox
 * resubmits it, so the current search text and every other filter always
 * travel together. Picking a proposed match in a row ticks that row's
 * apply checkbox (and clearing it unticks). The proposal dropdowns and the
 * Sofia programme-year select are enhanced with the core autocomplete, so
 * long node lists are searchable by typing (substring match anywhere).
 *
 * @module     local_curricmap/course_mapping
 * @copyright  2026 The Royal Veterinary College
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Autocomplete from 'core/form-autocomplete';

export const init = (placeholder) => {
    const form = document.querySelector('.local-curricmap-filterform');
    if (form) {
        form.querySelectorAll('select, input[type=checkbox]').forEach((control) => {
            control.addEventListener('change', () => form.submit());
        });
    }
    document.querySelectorAll('select[data-curricmap-row]').forEach((select) => {
        select.addEventListener('change', () => {
            const tick = document.querySelector('input[name="apply[' + select.dataset.curricmapRow + ']"]');
            if (tick) {
                tick.checked = select.value !== '';
            }
        });
    });
    document.querySelectorAll('select[data-curricmap-row], select[data-curricmap-node]').forEach((select) => {
        Autocomplete.enhance('#' + select.id, false, '', placeholder);
    });
};
