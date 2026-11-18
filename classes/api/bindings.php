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
 * The Moodle-location <-> curriculum-node binding service (the mapping API).
 *
 * A binding joins one Moodle address - deepest-non-null of category, course,
 * section, course module, sub-activity (component+subitemid) - to one node key.
 * Relations are soft-validated verbs; v1 vocabulary is "anchor" (a course's
 * default curriculum scope, machine-consumed) and "related". Scope is
 * "central" or "course". Content in either scope is shared-editable by
 * managebindings holders in the course; affiliation remains site-managed. Bindings never involve users beyond audit fields
 * and are year-pinned by the composed node key.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bindings {
    /** @var string The machine-consumed relation: a course's default curriculum scope. */
    const RELATION_ANCHOR = 'anchor';

    /** @var string The generic relation for everything else (v1). */
    const RELATION_RELATED = 'related';

    /**
     * Normalise and validate a Moodle address.
     *
     * @param array $address Any of categoryid, courseid, sectionid, cmid, component, subitemid.
     * @return array Normalised address with all six keys (null where unset).
     */
    public static function normalise_address(array $address): array {
        $out = [];
        foreach (['categoryid', 'courseid', 'sectionid', 'cmid', 'subitemid'] as $key) {
            $out[$key] = isset($address[$key]) && (int) $address[$key] > 0 ? (int) $address[$key] : null;
        }
        $out['component'] = isset($address['component']) && $address['component'] !== ''
            ? clean_param($address['component'], PARAM_COMPONENT) : null;

        if ($out['categoryid'] === null && $out['courseid'] === null) {
            throw new \moodle_exception('errorbindaddress', 'local_curricmap');
        }
        if (($out['sectionid'] || $out['cmid'] || $out['subitemid']) && $out['courseid'] === null) {
            throw new \moodle_exception('errorbindaddress', 'local_curricmap');
        }
        if (($out['subitemid'] !== null) !== ($out['component'] !== null)) {
            throw new \moodle_exception('errorbindaddress', 'local_curricmap');
        }
        return $out;
    }

    /**
     * The Moodle context a binding's permissions are checked in.
     *
     * @param array $address Normalised address.
     * @return \context
     */
    public static function address_context(array $address): \context {
        if ($address['courseid'] !== null) {
            return \context_course::instance($address['courseid']);
        }
        return \context_coursecat::instance($address['categoryid']);
    }

    /**
     * Context for editing: content is shared, affiliation remains site-managed.
     *
     * @param array $address Moodle address.
     * @param string $scope Stored scope.
     * @param string $relation Binding relation.
     * @return \context
     */
    public static function management_context(array $address, string $scope, string $relation = 'related'): \context {
        if (!empty($address['courseid']) && (!empty($address['sectionid']) || !empty($address['cmid']))) {
            return \context_course::instance($address['courseid']);
        }
        if ($scope === 'central' || $relation === self::RELATION_ANCHOR) {
            return \context_system::instance();
        }
        return self::address_context(self::normalise_address($address));
    }

    /**
     * Validate that a submitted content location belongs to its course.
     *
     * @param array $address Normalised address.
     */
    public static function validate_location(array $address): void {
        global $DB;
        if (empty($address['courseid'])) {
            return;
        }
        $modinfo = get_fast_modinfo($address['courseid']);
        if (!empty($address['sectionid']) && !$modinfo->get_section_info_by_id($address['sectionid'])) {
            throw new \moodle_exception('errorbindaddress', 'local_curricmap');
        }
        $cm = !empty($address['cmid']) ? ($modinfo->cms[$address['cmid']] ?? null) : null;
        if (!empty($address['cmid']) && (!$cm || (!empty($address['sectionid']) && $cm->section != $address['sectionid']))) {
            throw new \moodle_exception('errorbindaddress', 'local_curricmap');
        }
        if (!empty($address['subitemid'])) {
            if (!$cm || $cm->modname !== 'book' || $address['component'] !== 'mod_book'
                    || !$DB->record_exists('book_chapters', ['id' => $address['subitemid'], 'bookid' => $cm->instance])) {
                throw new \moodle_exception('errorbindaddress', 'local_curricmap');
            }
        }
    }

    /**
     * Section and subsection ancestors, nearest first.
     *
     * @param int $courseid Course id.
     * @param int $sectionid Immediate section id.
     * @return int[]
     */
    public static function section_path(int $courseid, int $sectionid): array {
        $modinfo = get_fast_modinfo($courseid);
        $owners = $modinfo->get_instances_of('subsection');
        $path = [];
        while ($sectionid && !in_array($sectionid, $path)) {
            $section = $modinfo->get_section_info_by_id($sectionid);
            if (!$section) {
                break;
            }
            $path[] = $sectionid;
            $owner = ($section->component ?? '') === 'mod_subsection' ? ($owners[$section->itemid] ?? null) : null;
            $sectionid = $owner ? (int) $owner->section : 0;
        }
        return $path;
    }

    /**
     * May the given (or current) user manage a binding at this address and scope?
     *
     * Central content bindings use the course capability; affiliation uses system context.
     *
     * @param array $address Normalised address.
     * @param string $scope central or course.
     * @param int|null $userid User id, null for the current user.
     * @param string $relation Binding relation.
     * @return bool
     */
    public static function can_manage(
        array $address,
        string $scope,
        ?int $userid = null,
        string $relation = 'related'
    ): bool {
        return has_capability('local/curricmap:managebindings', self::management_context($address, $scope, $relation), $userid);
    }

    /**
     * Create a binding (idempotent: an identical address+node+relation returns
     * the existing row id).
     *
     * Capability checks belong to the calling layer (external functions, UI);
     * this is the trusted service layer.
     *
     * @param array $address Moodle address (see normalise_address).
     * @param string $nodeuuid Composed node key.
     * @param string $relation Relation verb, default related.
     * @param string $scope central or course.
     * @param int $sortorder Order among bindings sharing the address.
     * @return int Binding id.
     */
    public static function bind(
        array $address,
        string $nodeuuid,
        string $relation = self::RELATION_RELATED,
        string $scope = 'course',
        int $sortorder = 0
    ): int {
        global $DB, $USER;

        $address = self::normalise_address($address);
        $node = curriculum::node($nodeuuid);
        if (!$node || $node->deleted) {
            throw new \moodle_exception('errorbindnode', 'local_curricmap', '', s($nodeuuid));
        }
        $scope = $scope === 'central' ? 'central' : 'course';
        $relation = clean_param($relation, PARAM_ALPHANUMEXT) ?: self::RELATION_RELATED;

        $conditions = $address + ['nodeuuid' => $nodeuuid, 'relation' => $relation];
        $existing = $DB->get_record('local_curricmap_binding', $conditions);
        if ($existing) {
            return (int) $existing->id;
        }

        $record = (object) $conditions;
        $record->nodeid = (int) $node->id;
        $record->scope = $scope;
        $record->sortorder = $sortorder;
        $record->status = 'active';
        $record->source = 'api';
        $record->usermodified = $USER->id ?? null;
        $record->timecreated = time();
        $record->timemodified = time();
        return $DB->insert_record('local_curricmap_binding', $record);
    }

    /**
     * Delete a binding.
     *
     * @param int $id Binding id.
     */
    public static function unbind(int $id): void {
        global $DB;
        $DB->delete_records('local_curricmap_binding', ['id' => $id]);
    }

    /**
     * Resolve the bindings applying to a Moodle location, deepest match first:
     * sub-activity, course module, section, course, then the course's category
     * and its ancestors (nearest first). Only the deepest level with any
     * bindings contributes, ordered by sortorder.
     *
     * @param array $location Location (courseid required unless category-only).
     * @param string|null $relation Restrict to one relation, e.g. anchor.
     * @return \stdClass[] Binding records with the node row attached as ->node.
     */
    public static function resolve(array $location, ?string $relation = null): array {
        global $DB;

        $levels = [];
        if (!empty($location['cmid']) && !empty($location['component']) && !empty($location['subitemid'])) {
            $levels[] = ['courseid' => (int) $location['courseid'], 'cmid' => (int) $location['cmid'], 'component' => $location['component'],
                'subitemid' => (int) $location['subitemid']];
        }
        if (!empty($location['cmid'])) {
            $levels[] = ['courseid' => (int) $location['courseid'], 'cmid' => (int) $location['cmid'], 'component' => null, 'subitemid' => null];
        }
        $sectionid = (int) ($location['sectionid'] ?? 0);
        if (!empty($location['courseid'])) {
            if (!empty($location['cmid'])) {
                $cm = get_fast_modinfo($location['courseid'])->cms[$location['cmid']] ?? null;
                $sectionid = $cm ? (int) $cm->section : $sectionid;
            }
            foreach (self::section_path((int) $location['courseid'], $sectionid) as $ancestor) {
                $levels[] = ['courseid' => (int) $location['courseid'], 'sectionid' => $ancestor, 'cmid' => null];
            }
        }
        if (!empty($location['courseid'])) {
            $levels[] = ['courseid' => (int) $location['courseid'], 'sectionid' => null, 'cmid' => null];
            $course = get_course((int) $location['courseid']);
            $category = \core_course_category::get($course->category, IGNORE_MISSING, true);
            if ($category) {
                foreach (array_reverse(array_merge($category->get_parents(), [$category->id])) as $categoryid) {
                    $levels[] = ['categoryid' => (int) $categoryid, 'courseid' => null];
                }
            }
        } else if (!empty($location['categoryid'])) {
            $levels[] = ['categoryid' => (int) $location['categoryid'], 'courseid' => null];
        }

        foreach ($levels as $conditions) {
            if ($relation !== null) {
                $conditions['relation'] = $relation;
            }
            $conditions['status'] = 'active';
            $found = $DB->get_records('local_curricmap_binding', $conditions, 'sortorder ASC, id ASC');
            if ($found) {
                foreach ($found as $binding) {
                    $binding->node = curriculum::node($binding->nodeuuid);
                }
                return array_values($found);
            }
        }
        return [];
    }

    /**
     * A course's anchor nodes (its default curriculum scopes), in order.
     *
     * @param int $courseid Course id.
     * @return \stdClass[] Node records.
     */
    public static function anchors(int $courseid): array {
        $nodes = [];
        foreach (self::resolve(['courseid' => $courseid], self::RELATION_ANCHOR) as $binding) {
            if ($binding->node && !$binding->node->deleted) {
                $nodes[] = $binding->node;
            }
        }
        return $nodes;
    }

    /**
     * Every binding addressing a course (course, section, cm and sub-activity rows).
     *
     * @param int $courseid Course id.
     * @return \stdClass[]
     */
    public static function for_course(int $courseid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_curricmap_binding',
            ['courseid' => $courseid],
            'sectionid ASC, cmid ASC, sortorder ASC'
        ));
    }

    /**
     * Every binding targeting a node (where is this node mapped?).
     *
     * @param string $nodeuuid Composed node key.
     * @param string|null $relation Restrict to one relation.
     * @return \stdClass[]
     */
    public static function for_node(string $nodeuuid, ?string $relation = null): array {
        global $DB;
        $conditions = ['nodeuuid' => $nodeuuid];
        if ($relation !== null) {
            $conditions['relation'] = $relation;
        }
        return array_values($DB->get_records('local_curricmap_binding', $conditions, 'id ASC'));
    }

    /**
     * Bindings whose either end no longer resolves: marked orphaned by the
     * event observers, or targeting a soft-deleted node.
     *
     * @param int|null $courseid Restrict to one course.
     * @return \stdClass[]
     */
    public static function orphaned(?int $courseid = null): array {
        global $DB;
        $where = "(b.status = 'orphaned' OR n.id IS NULL OR n.deleted = 1)";
        $params = [];
        if ($courseid !== null) {
            $where .= ' AND b.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $sql = "SELECT b.* FROM {local_curricmap_binding} b
                  LEFT JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
                 WHERE $where ORDER BY b.id ASC";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Mark bindings at a Moodle location as orphaned (observer callback path).
     *
     * @param array $conditions Column conditions identifying the affected rows.
     */
    public static function mark_orphaned(array $conditions): void {
        global $DB;
        $records = $DB->get_records('local_curricmap_binding', $conditions);
        foreach ($records as $record) {
            $DB->set_field('local_curricmap_binding', 'status', 'orphaned', ['id' => $record->id]);
        }
    }
}
