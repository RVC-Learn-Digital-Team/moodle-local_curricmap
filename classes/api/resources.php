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

namespace local_curricmap\api;

/**
 * Node resources: related learning content (Panopto, PebblePad, ebooks, image
 * libraries, links) attached to curriculum nodes.
 *
 * A resource with a null courseid is institutional and appears wherever the
 * node is displayed; with a courseid set it is a course-scoped addition,
 * visible only in that course's renders. Types are free strings; the
 * resourcetypes setting seeds the suggestion vocabulary but nothing is
 * enforced in code.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resources {
    /**
     * The suggested type vocabulary (seeded by the resourcetypes setting).
     *
     * @return string[]
     */
    public static function suggested_types(): array {
        $setting = (string) get_config('local_curricmap', 'resourcetypes');
        $types = array_values(array_filter(array_map('trim', explode(',', $setting))));
        return $types ?: ['panopto', 'pebblepad', 'ebook', 'images', 'link'];
    }

    /**
     * Add a resource to a node (idempotent on node+url+course scope).
     *
     * @param string $nodeuuid Composed node key.
     * @param string $type Free-string type.
     * @param string $label Display label.
     * @param string $url Resource URL.
     * @param int|null $courseid Null = institutional, set = course-scoped.
     * @param int $sortorder Display order within the node's resources.
     * @return int Resource id.
     */
    public static function add(
        string $nodeuuid,
        string $type,
        string $label,
        string $url,
        ?int $courseid = null,
        int $sortorder = 0
    ): int {
        global $DB, $USER;

        $node = curriculum::node($nodeuuid);
        if (!$node) {
            throw new \moodle_exception('errorbindnode', 'local_curricmap', '', s($nodeuuid));
        }
        $conditions = ['nodeuuid' => $nodeuuid, 'url' => $url, 'courseid' => $courseid];
        $existing = $DB->get_record_select(
            'local_curricmap_resource',
            'nodeuuid = :nodeuuid AND ' . $DB->sql_compare_text('url') . ' = :url AND '
            . ($courseid === null ? 'courseid IS NULL' : 'courseid = :courseid'),
            $courseid === null ? ['nodeuuid' => $nodeuuid, 'url' => $url] : $conditions
        );
        if ($existing) {
            return (int) $existing->id;
        }

        $record = new \stdClass();
        $record->nodeuuid = $nodeuuid;
        $record->courseid = $courseid;
        $record->type = clean_param($type, PARAM_TEXT) ?: 'link';
        $record->label = $label;
        $record->url = $url;
        $record->sortorder = $sortorder;
        $record->usermodified = $USER->id ?? null;
        $record->timecreated = time();
        $record->timemodified = time();
        return $DB->insert_record('local_curricmap_resource', $record);
    }

    /**
     * Delete a resource.
     *
     * @param int $id Resource id.
     */
    public static function delete(int $id): void {
        global $DB;
        $DB->delete_records('local_curricmap_resource', ['id' => $id]);
    }

    /**
     * A node's resources: institutional rows plus, when a course context is
     * given, that course's additions.
     *
     * @param string $nodeuuid Composed node key.
     * @param int|null $courseid Course context, null for institutional only.
     * @return \stdClass[]
     */
    public static function for_node(string $nodeuuid, ?int $courseid = null): array {
        global $DB;
        $where = 'nodeuuid = :nodeuuid AND (courseid IS NULL';
        $params = ['nodeuuid' => $nodeuuid];
        if ($courseid !== null) {
            $where .= ' OR courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $where .= ')';
        return array_values($DB->get_records_select(
            'local_curricmap_resource',
            $where,
            $params,
            'sortorder ASC, id ASC'
        ));
    }

    /**
     * Flexible listing: filter by node, by type (case-insensitive), or both.
     *
     * Course scoping matches for_node(): institutional rows always, plus the
     * given course's own additions.
     *
     * @param string|null $nodeuuid Composed node key, null for any node.
     * @param string|null $type Resource type, null for any type.
     * @param int|null $courseid Course context, null for institutional only.
     * @return \stdClass[]
     */
    public static function query(?string $nodeuuid = null, ?string $type = null, ?int $courseid = null): array {
        global $DB;
        $where = [];
        $params = [];
        if ($nodeuuid !== null && $nodeuuid !== '') {
            $where[] = 'nodeuuid = :nodeuuid';
            $params['nodeuuid'] = $nodeuuid;
        }
        if ($type !== null && $type !== '') {
            $where[] = 'LOWER(type) = LOWER(:type)';
            $params['type'] = $type;
        }
        if ($courseid !== null) {
            $where[] = '(courseid IS NULL OR courseid = :courseid)';
            $params['courseid'] = $courseid;
        } else {
            $where[] = 'courseid IS NULL';
        }
        return array_values($DB->get_records_select(
            'local_curricmap_resource',
            implode(' AND ', $where),
            $params,
            'nodeuuid ASC, sortorder ASC, id ASC'
        ));
    }

    /**
     * Bulk fetch resources for many nodes (one query, for renderers).
     *
     * @param string[] $nodeuuids Composed node keys.
     * @param int|null $courseid Course context, null for institutional only.
     * @return array Map nodeuuid => resource records.
     */
    public static function for_nodes(array $nodeuuids, ?int $courseid = null): array {
        global $DB;
        if (!$nodeuuids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($nodeuuids, SQL_PARAMS_NAMED);
        $where = "nodeuuid $insql AND (courseid IS NULL";
        if ($courseid !== null) {
            $where .= ' OR courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $where .= ')';
        $map = [];
        foreach ($DB->get_records_select('local_curricmap_resource', $where, $params, 'sortorder ASC, id ASC') as $row) {
            $map[$row->nodeuuid][] = $row;
        }
        return $map;
    }
}
