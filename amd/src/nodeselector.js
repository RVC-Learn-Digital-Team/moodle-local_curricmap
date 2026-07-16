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
 * Autocomplete backend for the mappings page node picker.
 *
 * Unlike the presenter's scope picker this searches ALL roles - mapping
 * content to individual outcomes is the point. With an empty query the year
 * nodes AND their strands are offered as starting points (strand-shaped
 * courses like Alimentary map to a strand directly).
 *
 * @module     local_curricmap/nodeselector
 * @copyright  2026 The Royal Veterinary College
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

const ROLELABELS = {
    year: 'Year',
    strand: 'Strand',
    strandoutcome: 'Strand outcome',
    session: 'Session',
    sessionoutcome: 'Session outcome',
    assessment: 'Assessment',
    programmeoutcome: 'Programme outcome',
    group: 'Group',
    other: '',
};

/**
 * Fetch year nodes and nodes of any role matching the query within the
 * selected programme year.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {String} query The typed search text.
 * @param {Function} callback Success callback.
 * @param {Function} failure Failure callback.
 */
export const transport = (selector, query, callback, failure) => {
    const element = document.querySelector(selector);
    const courseid = parseInt(element?.dataset.courseid || '1', 10);
    const programmeid = parseInt(document.querySelector('#id_programmeid')?.value || '0', 10);
    if (!programmeid) {
        callback({years: [], results: []});
        return;
    }
    const calls = Ajax.call([
        {
            methodname: 'local_curricmap_get_children',
            args: {courseid: courseid, programmeid: programmeid, parentuuid: '', withstrands: true},
        },
        {
            methodname: 'local_curricmap_search',
            args: {courseid: courseid, programmeid: programmeid, query: query, roles: []},
        },
    ]);
    Promise.all(calls)
        .then(([years, results]) => callback({years: years, results: results}))
        .catch(failure);
};

/**
 * Map the transport payload to autocomplete options: years and their strands
 * first as starting points, then all matches (starting-point duplicates
 * removed) with a role suffix.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {Object} payload Transport payload {years, results}.
 * @returns {Array}
 */
export const processResults = (selector, payload) => {
    const label = (node) => {
        const role = ROLELABELS[node.role] ?? node.role;
        return (node.title || '') + (node.code ? ' (' + node.code + ')' : '')
            + (role ? ' — ' + role : '');
    };
    const seen = new Set();
    const options = (payload.years || []).map((node) => {
        seen.add(node.uuid);
        return {value: node.uuid, label: label(node)};
    });
    (payload.results || []).forEach((node) => {
        if (!seen.has(node.uuid)) {
            options.push({value: node.uuid, label: label(node)});
        }
    });
    return options;
};
