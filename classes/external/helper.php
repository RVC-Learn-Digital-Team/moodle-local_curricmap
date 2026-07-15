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

use core_external\external_single_structure;
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

        $programmes = $DB->get_records('local_curricmap_programme');
        $out = [];
        foreach ($nodes as $node) {
            $programme = $programmes[$node->programmeid] ?? null;
            $label = '';
            if ($programme) {
                $year = $programme->versionlabel;
                if (preg_match('/^\d{4}$/', $year)) {
                    $year = $year . '/' . sprintf('%02d', ((int) $year + 1) % 100);
                }
                $label = ($programme->displayname ?: $programme->slug) . ' ' . $year;
            }
            $out[] = [
                'uuid' => $node->uuid,
                'role' => $node->role,
                'subtype' => $node->subtype,
                'code' => $node->code,
                'title' => $node->title,
                'grouplabel' => $node->grouplabel,
                'programmelabel' => $label,
                'haschildren' => !empty($haschildren[$node->id]),
            ];
        }
        return $out;
    }

    /**
     * Export binding records (with any attached ->node) for external consumption.
     *
     * @param \stdClass[] $bindings Binding records, optionally carrying ->node.
     * @return array[]
     */
    public static function export_bindings(array $bindings): array {
        $nodes = [];
        foreach ($bindings as $binding) {
            if (!empty($binding->node)) {
                $nodes[$binding->node->uuid] = $binding->node;
            }
        }
        $exported = [];
        foreach (self::export_nodes(array_values($nodes)) as $node) {
            $exported[$node['uuid']] = $node;
        }
        $out = [];
        foreach ($bindings as $binding) {
            $row = [
                'id' => (int) $binding->id,
                'nodeuuid' => $binding->nodeuuid,
                'relation' => $binding->relation,
                'scope' => $binding->scope,
                'sortorder' => (int) $binding->sortorder,
                'status' => $binding->status,
            ];
            foreach (['categoryid', 'courseid', 'sectionid', 'cmid', 'subitemid'] as $key) {
                if (!empty($binding->$key)) {
                    $row[$key] = (int) $binding->$key;
                }
            }
            if (!empty($binding->component)) {
                $row['component'] = $binding->component;
            }
            if (isset($exported[$binding->nodeuuid])) {
                $row['node'] = $exported[$binding->nodeuuid];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The exported binding structure definition.
     *
     * @return array
     */
    public static function binding_structure(): array {
        return [
            'id' => new external_value(PARAM_INT, 'Binding id'),
            'categoryid' => new external_value(PARAM_INT, 'Category id', VALUE_OPTIONAL),
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_OPTIONAL),
            'sectionid' => new external_value(PARAM_INT, 'Course section id', VALUE_OPTIONAL),
            'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_OPTIONAL),
            'component' => new external_value(PARAM_COMPONENT, 'Sub-activity component', VALUE_OPTIONAL),
            'subitemid' => new external_value(PARAM_INT, 'Sub-activity id', VALUE_OPTIONAL),
            'nodeuuid' => new external_value(PARAM_ALPHANUMEXT, 'Composed node key'),
            'relation' => new external_value(PARAM_ALPHANUMEXT, 'Relation verb'),
            'scope' => new external_value(PARAM_ALPHA, 'central or course'),
            'sortorder' => new external_value(PARAM_INT, 'Order among bindings sharing the address'),
            'status' => new external_value(PARAM_ALPHA, 'active or orphaned'),
            'node' => new external_single_structure(self::node_structure(), 'The bound node', VALUE_OPTIONAL),
        ];
    }

    /**
     * Export resource records for external consumption.
     *
     * @param \stdClass[] $resources Resource records.
     * @return array[]
     */
    public static function export_resources(array $resources): array {
        $out = [];
        foreach ($resources as $resource) {
            $row = [
                'id' => (int) $resource->id,
                'nodeuuid' => $resource->nodeuuid,
                'type' => $resource->type,
                'label' => $resource->label,
                'url' => $resource->url,
                'sortorder' => (int) $resource->sortorder,
                'visible' => !empty($resource->visible),
            ];
            if (!empty($resource->courseid)) {
                $row['courseid'] = (int) $resource->courseid;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The exported resource structure definition.
     *
     * @return array
     */
    public static function resource_structure(): array {
        return [
            'id' => new external_value(PARAM_INT, 'Resource id'),
            'nodeuuid' => new external_value(PARAM_ALPHANUMEXT, 'Composed node key'),
            'courseid' => new external_value(PARAM_INT, 'Course id; absent when institutional', VALUE_OPTIONAL),
            'type' => new external_value(PARAM_TEXT, 'Free-string type, e.g. Panopto'),
            'label' => new external_value(PARAM_TEXT, 'Display label'),
            'url' => new external_value(PARAM_URL, 'Resource URL'),
            'sortorder' => new external_value(PARAM_INT, 'Display order within the node'),
            'visible' => new external_value(PARAM_BOOL, 'Whether the resource renders to viewers'),
        ];
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
            'programmelabel' => new external_value(PARAM_TEXT, 'Programme and academic year label', VALUE_OPTIONAL),
            'haschildren' => new external_value(PARAM_BOOL, 'Whether the node has children'),
        ];
    }
}
