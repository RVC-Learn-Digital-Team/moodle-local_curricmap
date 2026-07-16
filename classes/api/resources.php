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
     * Whether a user may manage resources at the given scope.
     *
     * Global (institutional) rows need managebindings at system level;
     * course-scoped rows need managecourseresources (or managebindings) in
     * the course context — the editing-teacher surface.
     *
     * @param int|null $courseid Course scope, null for institutional.
     * @param int|null $userid User id, null for the current user.
     * @return bool
     */
    public static function can_manage(?int $courseid, ?int $userid = null): bool {
        if ($courseid === null || $courseid === 0) {
            return has_capability('local/curricmap:managebindings', \context_system::instance(), $userid);
        }
        try {
            $context = \context_course::instance($courseid);
        } catch (\moodle_exception $e) {
            // The scoping course is gone: manage centrally.
            return has_capability('local/curricmap:managebindings', \context_system::instance(), $userid);
        }
        $capabilities = ['local/curricmap:managecourseresources', 'local/curricmap:managebindings'];
        return has_any_capability($capabilities, $context, $userid);
    }

    /**
     * Whether a node sits within a course's centrally mapped scope: the node
     * is one of the course's anchors, or lies below one. The strict lock for
     * teacher-facing writes — courses without anchors have no scope, so
     * nothing qualifies.
     *
     * @param string $nodeuuid Composed node key.
     * @param int $courseid Course id.
     * @return bool
     */
    public static function within_course_scope(string $nodeuuid, int $courseid): bool {
        $node = curriculum::node($nodeuuid);
        if (!$node || $node->deleted) {
            return false;
        }
        foreach (bindings::anchors($courseid) as $anchor) {
            if ((int) $anchor->id === (int) $node->id) {
                return true;
            }
            if ($node->path && strpos($node->path, '/' . $anchor->id . '/') !== false) {
                return true;
            }
        }
        return false;
    }

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
        $record->visible = 1;
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
     * Show or hide a resource (hidden rows are kept but never rendered to
     * viewers; management surfaces show them greyed).
     *
     * @param int $id Resource id.
     * @param bool $visible Whether the resource renders to viewers.
     */
    public static function set_visible(int $id, bool $visible): void {
        global $DB, $USER;
        $record = $DB->get_record('local_curricmap_resource', ['id' => $id], '*', MUST_EXIST);
        $record->visible = $visible ? 1 : 0;
        $record->usermodified = $USER->id ?? null;
        $record->timemodified = time();
        $DB->update_record('local_curricmap_resource', $record);
    }

    /**
     * A node's resources: institutional rows plus, when a course context is
     * given, that course's additions.
     *
     * @param string $nodeuuid Composed node key.
     * @param int|null $courseid Course context, null for institutional only.
     * @param bool $includehidden Include hidden rows (management surfaces only).
     * @return \stdClass[]
     */
    public static function for_node(string $nodeuuid, ?int $courseid = null, bool $includehidden = false): array {
        global $DB;
        $where = 'nodeuuid = :nodeuuid AND (courseid IS NULL';
        $params = ['nodeuuid' => $nodeuuid];
        if ($courseid !== null) {
            $where .= ' OR courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $where .= ')';
        if (!$includehidden) {
            $where .= ' AND visible = 1';
        }
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
     * @param bool $includehidden Include hidden rows (management surfaces only).
     * @return \stdClass[]
     */
    public static function query(
        ?string $nodeuuid = null,
        ?string $type = null,
        ?int $courseid = null,
        bool $includehidden = false
    ): array {
        global $DB;
        $where = [];
        $params = [];
        if (!$includehidden) {
            $where[] = 'visible = 1';
        }
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
     * @param bool $includehidden Include hidden rows (management surfaces only).
     * @return array Map nodeuuid => resource records.
     */
    public static function for_nodes(array $nodeuuids, ?int $courseid = null, bool $includehidden = false): array {
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
        if (!$includehidden) {
            $where .= ' AND visible = 1';
        }
        $map = [];
        foreach ($DB->get_records_select('local_curricmap_resource', $where, $params, 'sortorder ASC, id ASC') as $row) {
            $map[$row->nodeuuid][] = $row;
        }
        return $map;
    }
}
