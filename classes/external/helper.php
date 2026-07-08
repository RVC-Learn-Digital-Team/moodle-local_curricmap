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

use core_external\external_value;

/**
 * Shared node export shape for the plugin's external functions.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Export node records for external consumption.
     *
     * @param \stdClass[] $nodes Node records.
     * @return array[]
     */
    public static function export_nodes(array $nodes): array {
        global $DB;

        $haschildren = [];
        if ($nodes) {
            $ids = array_map(fn($node) => (int) $node->id, $nodes);
            [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $sql = "SELECT parentid, COUNT(id) AS childcount FROM {local_curricmap_node}
                     WHERE parentid $insql AND deleted = 0 GROUP BY parentid";
            $haschildren = $DB->get_records_sql_menu($sql, $params);
        }

        $out = [];
        foreach ($nodes as $node) {
            $out[] = [
                'uuid' => $node->uuid,
                'role' => $node->role,
                'subtype' => $node->subtype,
                'code' => $node->code,
                'title' => $node->title,
                'grouplabel' => $node->grouplabel,
                'haschildren' => !empty($haschildren[$node->id]),
            ];
        }
        return $out;
    }

    /**
     * The exported node structure definition.
     *
     * @return array
     */
    public static function node_structure(): array {
        return [
            'uuid' => new external_value(PARAM_ALPHANUMEXT, 'Node uuid'),
            'role' => new external_value(PARAM_ALPHA, 'Derived role'),
            'subtype' => new external_value(PARAM_TEXT, 'Subtype', VALUE_OPTIONAL),
            'code' => new external_value(PARAM_TEXT, 'Code', VALUE_OPTIONAL),
            'title' => new external_value(PARAM_RAW, 'Title'),
            'grouplabel' => new external_value(PARAM_TEXT, 'Unit grouping label', VALUE_OPTIONAL),
            'haschildren' => new external_value(PARAM_BOOL, 'Whether the node has children'),
        ];
    }
}
