<?php
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

namespace local_curricmap\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_curricmap\api\curriculum;

/**
 * Graph extraction: a programme's full node rows in one (pageable) call.
 *
 * Built for external consumers — the data platform's sofia loader and the
 * external mapping engine — so the payload carries everything needed to
 * rebuild the tree offline (parentuuid, sortorder, depth, deleted flags),
 * unlike the picker-shaped exports. Sofia's 60/hr budget is paid once by the
 * mirror; everything downstream reads this.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_nodes extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'programmeid' => new external_value(PARAM_INT, 'Programme id (see get_programmes)'),
            'ancestoruuid' => new external_value(
                PARAM_ALPHANUMEXT,
                'Only the subtree below this node (itself included)',
                VALUE_DEFAULT,
                ''
            ),
            'includedeleted' => new external_value(
                PARAM_BOOL,
                'Include soft-deleted rows, flagged',
                VALUE_DEFAULT,
                false
            ),
            'limitfrom' => new external_value(PARAM_INT, 'Paging offset', VALUE_DEFAULT, 0),
            'limitnum' => new external_value(PARAM_INT, 'Paging size, 0 for all', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $programmeid Programme id.
     * @param string $ancestoruuid Subtree restriction; empty for the whole programme.
     * @param bool $includedeleted Include soft-deleted rows.
     * @param int $limitfrom Paging offset.
     * @param int $limitnum Paging size, 0 for all.
     * @return array
     */
    public static function execute(
        int $programmeid,
        string $ancestoruuid = '',
        bool $includedeleted = false,
        int $limitfrom = 0,
        int $limitnum = 0
    ): array {
        global $DB;
        $data = [
            'programmeid' => $programmeid,
            'ancestoruuid' => $ancestoruuid,
            'includedeleted' => $includedeleted,
            'limitfrom' => $limitfrom,
            'limitnum' => $limitnum,
        ];
        $params = self::validate_parameters(self::execute_parameters(), $data);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/curricmap:viewstaffmeta', $context);

        $programme = $DB->get_record('local_curricmap_programme', ['id' => $params['programmeid']], '*', MUST_EXIST);
        $found = curriculum::nodes(
            $params['programmeid'],
            $params['ancestoruuid'] !== '' ? $params['ancestoruuid'] : null,
            $params['includedeleted'],
            $params['limitfrom'],
            $params['limitnum']
        );

        // Resolve parent ids to composed keys: most parents sit inside the
        // result set; the rest (paging cuts, the subtree root's parent) are
        // fetched in one query.
        $uuidsbyid = [];
        foreach ($found['nodes'] as $node) {
            $uuidsbyid[(int) $node->id] = $node->uuid;
        }
        $missing = [];
        foreach ($found['nodes'] as $node) {
            if ($node->parentid && !isset($uuidsbyid[(int) $node->parentid])) {
                $missing[(int) $node->parentid] = true;
            }
        }
        if ($missing) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($missing), SQL_PARAMS_NAMED);
            $parents = $DB->get_records_select_menu('local_curricmap_node', "id $insql", $inparams, '', 'id, uuid');
            $uuidsbyid = $uuidsbyid + $parents;
        }

        $nodes = [];
        foreach ($found['nodes'] as $node) {
            $row = [
                'uuid' => $node->uuid,
                'role' => $node->role,
                'title' => (string) $node->title,
                'sortorder' => (int) $node->sortorder,
                'depth' => (int) $node->depth,
                'source' => $node->source,
                'deleted' => !empty($node->deleted),
                'timemodified' => (int) $node->timemodified,
            ];
            if ($node->parentid && isset($uuidsbyid[(int) $node->parentid])) {
                $row['parentuuid'] = $uuidsbyid[(int) $node->parentid];
            }
            $optionalfields = ['type', 'subtype', 'code', 'description', 'grouplabel', 'sofiaurl',
                'pebblepadurl', 'pebblepadlabel', 'sourceversion'];
            foreach ($optionalfields as $optional) {
                if ($node->$optional !== null && $node->$optional !== '') {
                    $row[$optional] = $node->$optional;
                }
            }
            $nodes[] = $row;
        }

        return [
            'programme' => [
                'id' => (int) $programme->id,
                'slug' => $programme->slug,
                'versionlabel' => $programme->versionlabel,
                'displayname' => $programme->displayname ?: $programme->slug,
                'revisionhash' => (string) $programme->revisionhash,
            ],
            'total' => $found['total'],
            'nodes' => $nodes,
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'programme' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Programme id'),
                'slug' => new external_value(PARAM_TEXT, 'Sofia programme slug'),
                'versionlabel' => new external_value(PARAM_TEXT, 'Academic-year version label'),
                'displayname' => new external_value(PARAM_TEXT, 'Display name'),
                'revisionhash' => new external_value(PARAM_RAW, 'Last synced Sofia revision hash'),
            ]),
            'total' => new external_value(PARAM_INT, 'Total matching nodes ignoring paging'),
            'nodes' => new external_multiple_structure(new external_single_structure([
                'uuid' => new external_value(PARAM_ALPHANUMEXT, 'Composed node key'),
                'parentuuid' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'Parent composed key; absent for top-level nodes',
                    VALUE_OPTIONAL
                ),
                'role' => new external_value(PARAM_ALPHA, 'Derived role'),
                'type' => new external_value(PARAM_TEXT, 'Raw Sofia type letter (Y/U/E/O/Z/G)', VALUE_OPTIONAL),
                'subtype' => new external_value(PARAM_TEXT, 'Sofia type name', VALUE_OPTIONAL),
                'code' => new external_value(PARAM_TEXT, 'Code', VALUE_OPTIONAL),
                'title' => new external_value(PARAM_RAW, 'Title'),
                'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
                'grouplabel' => new external_value(PARAM_TEXT, 'Unit grouping label', VALUE_OPTIONAL),
                'sortorder' => new external_value(PARAM_INT, 'Sibling order'),
                'depth' => new external_value(PARAM_INT, 'Tree depth, 0 = top level'),
                'sofiaurl' => new external_value(PARAM_URL, 'Sofia deep link (staff-only surface)', VALUE_OPTIONAL),
                'pebblepadurl' => new external_value(PARAM_RAW, 'PebblePad link', VALUE_OPTIONAL),
                'pebblepadlabel' => new external_value(PARAM_TEXT, 'PebblePad label', VALUE_OPTIONAL),
                'source' => new external_value(PARAM_ALPHA, 'sofia, csv or manual'),
                'sourceversion' => new external_value(
                    PARAM_RAW,
                    'Revision hash in which the row last changed',
                    VALUE_OPTIONAL
                ),
                'deleted' => new external_value(PARAM_BOOL, 'Soft-deleted (only with includedeleted)'),
                'timemodified' => new external_value(PARAM_INT, 'Row last modified (unix)'),
            ])),
        ]);
    }
}
