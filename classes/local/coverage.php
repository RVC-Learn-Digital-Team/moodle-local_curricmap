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

namespace local_curricmap\local;

use local_curricmap\api\bindings;
use local_curricmap\api\curriculum;

/**
 * Coverage aggregates: how much of the estate is matched, how much of the
 * curriculum is taught somewhere, and the hygiene counters.
 *
 * Definitions (agreed 2026-07-16/18):
 * - A curriculum node is COVERED only by content-grain bindings — section,
 *   activity or book chapter. Course-level anchors are affiliations, not
 *   coverage, or every node would count the moment a course matched.
 * - A course is MATCHED when it carries an active central course-level
 *   anchor (the matching page's definition).
 * - The in-scope course denominator reuses the matching rules' skip
 *   patterns, so this report and course_mapping.php never disagree.
 *
 * All reads; nothing cached (an admin report wants live truth).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coverage {
    /** @var string[] Roles counted as "teachable" curriculum nodes. */
    const TEACHABLE_ROLES = ['strand', 'session', 'strandoutcome', 'sessionoutcome', 'assessment'];

    /**
     * SQL fragment: active bindings at content grain (section/activity/chapter).
     *
     * @return string
     */
    private static function content_grain_where(): string {
        return "b.status = 'active' AND (b.sectionid IS NOT NULL OR b.cmid IS NOT NULL)";
    }

    /**
     * Composed keys of nodes covered by content-grain bindings, per programme.
     *
     * @param int $programmeid Programme id.
     * @return array uuid => true.
     */
    private static function covered_uuids(int $programmeid): array {
        global $DB;
        $where = self::content_grain_where();
        $sql = "SELECT DISTINCT n.uuid
                  FROM {local_curricmap_node} n
                  JOIN {local_curricmap_binding} b ON b.nodeuuid = n.uuid
                 WHERE n.programmeid = :programmeid AND n.deleted = 0 AND $where";
        return array_fill_keys($DB->get_fieldset_sql($sql, ['programmeid' => $programmeid]), true);
    }

    /**
     * One summary row per synced programme-year node.
     *
     * @return array[] Rows: slug, yearlabel, yeartitle, yearuuid, strands,
     *         sessions/sessionscovered, outcomes/outcomescovered,
     *         matchedcourses, contentbindings.
     */
    public static function programme_year_rows(): array {
        global $DB;
        $rows = [];
        foreach (curriculum::programmes() as $programme) {
            $covered = self::covered_uuids((int) $programme->id);
            foreach (curriculum::years((int) $programme->id) as $yearnode) {
                $nodes = self::year_nodes((int) $programme->id, $yearnode);
                $counts = self::bucket_counts($nodes, $covered);
                $rows[] = [
                    'slug' => $programme->slug,
                    'yearlabel' => $programme->versionlabel,
                    'yeartitle' => $yearnode->title,
                    'yearuuid' => $yearnode->uuid,
                    'strands' => $counts['strands'],
                    'sessions' => $counts['sessions'],
                    'sessionscovered' => $counts['sessionscovered'],
                    'outcomes' => $counts['outcomes'],
                    'outcomescovered' => $counts['outcomescovered'],
                    'matchedcourses' => count(self::matched_courseids($yearnode)),
                    'contentbindings' => self::content_binding_count($yearnode),
                ];
            }
        }
        return $rows;
    }

    /**
     * Strand-by-strand coverage for one programme-year.
     *
     * @param string $yearuuid Year node composed key.
     * @return array[] Rows: title, code, sessions/sessionscovered,
     *         outcomes/outcomescovered, covered flag for the strand itself.
     */
    public static function strand_rows(string $yearuuid): array {
        $yearnode = curriculum::node($yearuuid);
        if (!$yearnode) {
            return [];
        }
        $covered = self::covered_uuids((int) $yearnode->programmeid);
        $rows = [];
        foreach (curriculum::strands($yearuuid) as $strand) {
            $subtree = self::subtree_nodes((int) $yearnode->programmeid, $strand);
            $counts = self::bucket_counts($subtree, $covered);
            $rows[] = [
                'title' => $strand->title,
                'code' => (string) $strand->code,
                'stranduuid' => $strand->uuid,
                'strandcovered' => isset($covered[$strand->uuid]),
                'sessions' => $counts['sessions'],
                'sessionscovered' => $counts['sessionscovered'],
                'outcomes' => $counts['outcomes'],
                'outcomescovered' => $counts['outcomescovered'],
            ];
        }
        return $rows;
    }

    /**
     * The matched courses of one programme-year, with mapping depth counts.
     *
     * @param string $yearuuid Year node composed key.
     * @return array[] Rows: courseid, fullname, shortname, idnumber,
     *         sections, activities, chapters (bound counts).
     */
    public static function course_rows(string $yearuuid): array {
        global $DB;
        $yearnode = curriculum::node($yearuuid);
        if (!$yearnode) {
            return [];
        }
        $courseids = self::matched_courseids($yearnode);
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $courses = $DB->get_records_select('course', "id $insql", $params, 'fullname ASC');

        $rows = [];
        foreach ($courses as $course) {
            $depth = self::course_depth((int) $course->id);
            $rows[] = [
                'courseid' => (int) $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'idnumber' => (string) $course->idnumber,
                'sections' => $depth['sections'],
                'activities' => $depth['activities'],
                'chapters' => $depth['chapters'],
            ];
        }
        return $rows;
    }

    /**
     * Estate-wide course summary: total, skipped by rules, matched, in-scope
     * unmatched.
     *
     * @return array total, skipped, matched, unmatched.
     */
    public static function course_summary(): array {
        global $DB;
        $rules = matcher::rules();
        $courses = $DB->get_records_select('course', 'id <> :siteid', ['siteid' => SITEID], '', 'id, idnumber');

        $matchedids = [];
        $sql = "SELECT DISTINCT b.courseid
                  FROM {local_curricmap_binding} b
                 WHERE b.relation = :relation AND b.scope = 'central' AND b.status = 'active'
                       AND b.courseid IS NOT NULL AND b.sectionid IS NULL AND b.cmid IS NULL";
        foreach ($DB->get_fieldset_sql($sql, ['relation' => bindings::RELATION_ANCHOR]) as $courseid) {
            $matchedids[(int) $courseid] = true;
        }

        $total = 0;
        $skipped = 0;
        $matched = 0;
        foreach ($courses as $course) {
            $total++;
            if (isset($matchedids[(int) $course->id])) {
                $matched++;
                continue;
            }
            $isskipped = false;
            foreach ($rules['skip'] as $pattern) {
                if (@preg_match('/' . $pattern . '/i', (string) $course->idnumber) === 1) {
                    $isskipped = true;
                    break;
                }
            }
            if ($isskipped) {
                $skipped++;
            }
        }
        return [
            'total' => $total,
            'matched' => $matched,
            'skipped' => $skipped,
            'unmatched' => $total - $matched - $skipped,
        ];
    }

    /**
     * Hygiene counters: orphaned bindings, courses anchored to a non-latest
     * year of their programme slug, hidden and total resources.
     *
     * @return array orphaned, staleanchorcourses, hiddenresources, resources.
     */
    public static function hygiene(): array {
        global $DB;

        // The two most recent versionlabels per slug are "live" (the same
        // current + upcoming pair the sync tiering treats as hourly); an
        // anchor into anything older is a rollover straggler.
        $byslug = [];
        foreach (curriculum::programmes() as $programme) {
            $byslug[$programme->slug][] = $programme->versionlabel;
        }
        $live = [];
        foreach ($byslug as $slug => $labels) {
            rsort($labels, SORT_STRING);
            $live[$slug] = array_slice($labels, 0, 2);
        }

        // Central course anchors joined to their node's programme.
        $stale = [];
        $sql = "SELECT b.id, b.courseid, p.slug, p.versionlabel
                  FROM {local_curricmap_binding} b
                  JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
                  JOIN {local_curricmap_programme} p ON p.id = n.programmeid
                 WHERE b.relation = :relation AND b.scope = 'central' AND b.status = 'active'
                       AND b.courseid IS NOT NULL AND b.sectionid IS NULL AND b.cmid IS NULL";
        foreach ($DB->get_records_sql($sql, ['relation' => bindings::RELATION_ANCHOR]) as $row) {
            if (isset($live[$row->slug]) && !in_array($row->versionlabel, $live[$row->slug], true)) {
                $stale[(int) $row->courseid] = true;
            }
        }

        return [
            'orphaned' => $DB->count_records('local_curricmap_binding', ['status' => 'orphaned']),
            'staleanchorcourses' => count($stale),
            'hiddenresources' => $DB->count_records('local_curricmap_resource', ['visible' => 0]),
            'resources' => $DB->count_records('local_curricmap_resource'),
        ];
    }

    /**
     * Minimal node rows (uuid, role, path) for one programme-year subtree.
     *
     * @param int $programmeid Programme id.
     * @param \stdClass $yearnode Year node record.
     * @return \stdClass[]
     */
    private static function year_nodes(int $programmeid, \stdClass $yearnode): array {
        return self::subtree_nodes($programmeid, $yearnode);
    }

    /**
     * Minimal node rows below (and including) one node.
     *
     * @param int $programmeid Programme id.
     * @param \stdClass $ancestor Ancestor node record.
     * @return \stdClass[]
     */
    private static function subtree_nodes(int $programmeid, \stdClass $ancestor): array {
        global $DB;
        $pathlike = $DB->sql_like('path', ':subpath');
        $select = "programmeid = :programmeid AND deleted = 0 AND $pathlike";
        $params = [
            'programmeid' => $programmeid,
            'subpath' => $DB->sql_like_escape((string) $ancestor->path) . '%',
        ];
        return $DB->get_records_select('local_curricmap_node', $select, $params, '', 'id, uuid, role');
    }

    /**
     * Count teachable nodes and covered nodes by bucket.
     *
     * @param \stdClass[] $nodes Node rows (uuid, role).
     * @param array $covered uuid => true map of covered nodes.
     * @return array strands, sessions, sessionscovered, outcomes, outcomescovered.
     */
    private static function bucket_counts(array $nodes, array $covered): array {
        $counts = [
            'strands' => 0,
            'sessions' => 0,
            'sessionscovered' => 0,
            'outcomes' => 0,
            'outcomescovered' => 0,
        ];
        foreach ($nodes as $node) {
            if ($node->role === 'strand') {
                $counts['strands']++;
            } else if ($node->role === 'session') {
                $counts['sessions']++;
                if (isset($covered[$node->uuid])) {
                    $counts['sessionscovered']++;
                }
            } else if ($node->role === 'strandoutcome' || $node->role === 'sessionoutcome') {
                $counts['outcomes']++;
                if (isset($covered[$node->uuid])) {
                    $counts['outcomescovered']++;
                }
            }
        }
        return $counts;
    }

    /**
     * Course ids centrally anchored into one programme-year's subtree.
     *
     * @param \stdClass $yearnode Year node record.
     * @return int[]
     */
    private static function matched_courseids(\stdClass $yearnode): array {
        global $DB;
        $pathlike = $DB->sql_like('n.path', ':subpath');
        $sql = "SELECT DISTINCT b.courseid
                  FROM {local_curricmap_binding} b
                  JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
                 WHERE b.relation = :relation AND b.scope = 'central' AND b.status = 'active'
                       AND b.courseid IS NOT NULL AND b.sectionid IS NULL AND b.cmid IS NULL
                       AND n.deleted = 0 AND $pathlike";
        $params = [
            'relation' => bindings::RELATION_ANCHOR,
            'subpath' => $DB->sql_like_escape((string) $yearnode->path) . '%',
        ];
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * Content-grain binding count into one programme-year's subtree.
     *
     * @param \stdClass $yearnode Year node record.
     * @return int
     */
    private static function content_binding_count(\stdClass $yearnode): int {
        global $DB;
        $where = self::content_grain_where();
        $pathlike = $DB->sql_like('n.path', ':subpath');
        $sql = "SELECT COUNT(b.id)
                  FROM {local_curricmap_binding} b
                  JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
                 WHERE $where AND n.deleted = 0 AND $pathlike";
        return (int) $DB->count_records_sql($sql, ['subpath' => $DB->sql_like_escape((string) $yearnode->path) . '%']);
    }

    /**
     * A course's binding depth: bound sections, activities and chapters.
     *
     * @param int $courseid Course id.
     * @return array sections, activities, chapters.
     */
    private static function course_depth(int $courseid): array {
        global $DB;
        $conditions = ['courseid' => $courseid, 'status' => 'active'];
        $rows = $DB->get_records('local_curricmap_binding', $conditions, '', 'id, sectionid, cmid, subitemid');
        $sections = [];
        $activities = [];
        $chapters = 0;
        foreach ($rows as $row) {
            if (!empty($row->subitemid)) {
                $chapters++;
            } else if (!empty($row->cmid)) {
                $activities[(int) $row->cmid] = true;
            } else if (!empty($row->sectionid)) {
                $sections[(int) $row->sectionid] = true;
            }
        }
        return [
            'sections' => count($sections),
            'activities' => count($activities),
            'chapters' => $chapters,
        ];
    }
}
