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
use local_curricmap\api\resources;

/**
 * Shared building blocks for the course content mapping page and its lazily
 * loaded fragments: binding buckets, per-section counts, and the row/cell
 * renderers. The page shows every section up front with its counts; the
 * activity mapping rows (candidate pools, pickers) are the expensive part
 * and load per section via the fragment API when the user opens them.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contentmap {
    /** @var string[] Node roles offered as activity/chapter targets. */
    const TARGET_ROLES = ['session', 'strandoutcome', 'sessionoutcome', 'assessment'];

    /** @var int Pools larger than this offer hints only. */
    const POOL_CAP = 300;

    /**
     * A node label: title, role, academic year from the composed key.
     *
     * @param \stdClass $node Node-ish record (title, role, uuid).
     * @return string
     */
    public static function label(\stdClass $node): string {
        $year = preg_match('/_(20\d\d)_\d\d_/', $node->uuid, $matches) ? ' - ' . $matches[1] : '';
        return $node->title . ' [' . $node->role . ']' . $year;
    }

    /**
     * The course's central bindings bucketed by grain.
     *
     * @param int $courseid Course id.
     * @return array [bysection, bycm, bychapter] — bychapter keyed cmid then subitemid.
     */
    public static function buckets(int $courseid): array {
        global $DB;
        $sql = "SELECT b.id, b.sectionid, b.cmid, b.component, b.subitemid, b.nodeuuid, n.title, n.role
                  FROM {local_curricmap_binding} b
             LEFT JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
                 WHERE b.courseid = :courseid AND b.scope = :scope AND b.status = :status
              ORDER BY b.sortorder ASC, b.id ASC";
        $params = ['courseid' => $courseid, 'scope' => 'central', 'status' => 'active'];
        $bysection = [];
        $bycm = [];
        $bychapter = [];
        foreach ($DB->get_records_sql($sql, $params) as $binding) {
            if ($binding->subitemid) {
                $bychapter[(int) $binding->cmid][(int) $binding->subitemid][] = $binding;
            } else if ($binding->cmid) {
                $bycm[(int) $binding->cmid][] = $binding;
            } else if ($binding->sectionid) {
                $bysection[(int) $binding->sectionid][] = $binding;
            }
        }
        return [$bysection, $bycm, $bychapter];
    }

    /**
     * Per-section counts: mappable activities, mapped activities, book
     * chapters and mapped chapters — everything the section header shows
     * without the user opening anything.
     *
     * @param \stdClass $course Course record.
     * @param string[] $mappabletypes Activity types offered for mapping.
     * @param array $bycm Activity-grain bucket from buckets().
     * @param array $bychapter Chapter-grain bucket from buckets().
     * @return array sectionid => {activities, mapped, chapters, chaptersmapped}
     */
    public static function section_counts(
        \stdClass $course,
        array $mappabletypes,
        array $bycm,
        array $bychapter
    ): array {
        global $DB;
        $modinfo = get_fast_modinfo($course);

        // Chapter totals per book instance, one query.
        $bookinstances = [];
        foreach ($modinfo->cms as $cm) {
            if ($cm->modname === 'book') {
                $bookinstances[(int) $cm->instance] = (int) $cm->id;
            }
        }
        $chaptersperbook = [];
        if ($bookinstances) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($bookinstances), SQL_PARAMS_NAMED);
            $chaptersql = "SELECT bookid, COUNT(*) AS chapters FROM {book_chapters}
                            WHERE bookid $insql GROUP BY bookid";
            foreach ($DB->get_records_sql($chaptersql, $inparams) as $row) {
                $chaptersperbook[(int) $row->bookid] = (int) $row->chapters;
            }
        }

        $counts = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $tally = (object) ['activities' => 0, 'mapped' => 0, 'chapters' => 0, 'chaptersmapped' => 0];
            foreach ($modinfo->sections[(int) $section->section] ?? [] as $cmid) {
                $cm = $modinfo->cms[$cmid];
                if (!in_array($cm->modname, $mappabletypes)) {
                    continue;
                }
                $tally->activities++;
                if (!empty($bycm[(int) $cm->id])) {
                    $tally->mapped++;
                }
                if ($cm->modname === 'book') {
                    $tally->chapters += $chaptersperbook[(int) $cm->instance] ?? 0;
                    $tally->chaptersmapped += count($bychapter[(int) $cm->id] ?? []);
                }
            }
            $counts[(int) $section->id] = $tally;
        }
        return $counts;
    }

    /**
     * Filter a candidate pool by node type: a value matches a node's role
     * ("session", "strandoutcome"...) or, for sessions, its Sofia subtype
     * ("Lecture", "Directed Learning", "Digital Learning Interaction"...).
     * An empty filter keeps everything.
     *
     * @param array $pool Candidates from content_candidates().
     * @param string[] $nodetypes Selected values, lowercased comparison.
     * @return array
     */
    public static function filter_pool(array $pool, array $nodetypes): array {
        if (!$nodetypes) {
            return $pool;
        }
        $wanted = array_map('strtolower', $nodetypes);
        return array_values(array_filter($pool, function ($candidate) use ($wanted) {
            $role = strtolower((string) $candidate->node->role);
            $subtype = strtolower((string) ($candidate->node->subtype ?? ''));
            return in_array($role, $wanted) || ($subtype !== '' && in_array($subtype, $wanted));
        }));
    }

    /**
     * The current-matches cell: node titles with year, remove icon, and a
     * study-resources cross-link.
     *
     * @param array $rowbindings Binding records for this row.
     * @param string $returnurl Url (string) the remove action returns to.
     * @param array $rescounts Resource counts keyed by node uuid.
     * @return string HTML.
     */
    public static function current_cell(array $rowbindings, string $returnurl, array $rescounts = []): string {
        global $OUTPUT;
        $entries = [];
        foreach ($rowbindings as $binding) {
            $removeurl = new \moodle_url($returnurl, ['unbind' => $binding->id, 'sesskey' => sesskey()]);
            $removeicon = $OUTPUT->pix_icon('t/delete', get_string('coursemapping_removematch', 'local_curricmap'));
            $year = preg_match('/_(20\d\d)_\d\d_/', $binding->nodeuuid, $matches) ? ' - ' . $matches[1] : '';
            $label = s(($binding->title ?? $binding->nodeuuid) . $year);
            $resurl = new \moodle_url('/local/curricmap/study_resources.php', ['node' => $binding->nodeuuid]);
            $count = $rescounts[$binding->nodeuuid] ?? 0;
            $reslabel = get_string('studyresources_count', 'local_curricmap', $count);
            $entries[] = $label . ' ' . \html_writer::link($removeurl, $removeicon)
                . ' ' . \html_writer::link($resurl, $reslabel, ['class' => 'small']);
        }
        return implode(\html_writer::empty_tag('br'), $entries);
    }

    /**
     * A multi-select proposal picker: hints first (scored), then the pool.
     *
     * @param string $key Row key (s/c/h prefix + id).
     * @param array $hints Scored hints from matcher::match_title().
     * @param array $pool Candidate pool.
     * @param bool $narrowed Whether the pool is already below a match.
     * @return string HTML.
     */
    public static function proposal_cell(string $key, array $hints, array $pool, bool $narrowed): string {
        $options = [];
        foreach ($hints as $hint) {
            $percent = (int) round($hint->score * 100);
            $options[$hint->candidate->node->uuid] = self::label($hint->candidate->node) . ' [' . $percent . '%]';
        }
        $capped = count($pool) > self::POOL_CAP;
        if (!$capped) {
            foreach ($pool as $candidate) {
                if (!isset($options[$candidate->node->uuid])) {
                    $options[$candidate->node->uuid] = self::label($candidate->node);
                }
            }
        }
        $cell = '';
        if ($options) {
            $attrs = ['data-curricmap-row' => $key, 'id' => 'curricmap-bind-' . $key,
                'multiple' => 'multiple', 'data-curricmap-search' => 1];
            $cell = \html_writer::select($options, "bind{$key}[]", '', false, $attrs);
        }
        if ($capped) {
            $notekey = $narrowed ? 'contentmapping_toolarge' : 'contentmapping_narrowfirst';
            $note = get_string($notekey, 'local_curricmap');
            $cell .= ' ' . \html_writer::tag('span', $note, ['class' => 'small text-muted']);
        } else if ($cell === '') {
            $nopool = get_string('contentmapping_nopool', 'local_curricmap');
            $cell = \html_writer::tag('span', $nopool, ['class' => 'small text-muted']);
        }
        return $cell;
    }

    /**
     * A row's apply checkbox.
     *
     * @param string $key Row key.
     * @param string $name Accessible name.
     * @return string HTML.
     */
    public static function tick(string $key, string $name): string {
        $attrs = ['type' => 'checkbox', 'name' => "apply[$key]", 'value' => 1,
            'data-action' => 'toggle', 'data-toggle' => 'slave', 'data-togglegroup' => 'contentmatch',
            'aria-label' => get_string('coursemapping_selectcourse', 'local_curricmap', $name)];
        return \html_writer::empty_tag('input', $attrs);
    }

    /**
     * The lazily loaded activity mapping rows for one section.
     *
     * @param \stdClass $course Course record.
     * @param int $sectionid Section id.
     * @param string[] $modtypes Module-type filter (empty = all mappable).
     * @param string $returnurl Url string for remove/resource links.
     * @return string HTML rows (divs, form inputs join the page's form).
     */
    public static function activity_rows(
        \stdClass $course,
        int $sectionid,
        array $modtypes,
        string $returnurl,
        array $nodetypes = [],
        array $pendingroots = []
    ): string {
        $mappabletypes = array_filter(explode(',', (string) get_config('local_curricmap', 'mappablemodtypes')));
        $rules = matcher::rules();
        [$bysection, $bycm, $bychapter] = self::buckets((int) $course->id);
        $anchors = bindings::anchors((int) $course->id);
        $rootuuids = array_map(fn($node) => $node->uuid, $anchors);

        $modinfo = get_fast_modinfo($course);
        $target = null;
        foreach ($modinfo->get_section_info_all() as $section) {
            if ((int) $section->id === $sectionid) {
                $target = $section;
            }
        }
        if (!$target) {
            return \html_writer::tag('p', get_string('contentmapping_norows', 'local_curricmap'));
        }

        // The section's saved matches plus any not-yet-saved picks from its
        // proposal select: an unsaved strand selection narrows the activity
        // pools immediately, so section + activities can be matched in one
        // apply instead of save-reload-drill.
        $sectionroots = array_map(fn($b) => $b->nodeuuid, $bysection[$sectionid] ?? []);
        $roots = array_values(array_unique(array_merge($sectionroots, $pendingroots)));
        $narrowed = !empty($roots);
        $modulepool = matcher::content_candidates($roots ?: $rootuuids, self::TARGET_ROLES);
        $modulepool = self::filter_pool($modulepool, $nodetypes);

        // Resource counts for the bound nodes shown in these rows.
        $bounduuids = [];
        foreach ($modinfo->sections[(int) $target->section] ?? [] as $cmid) {
            foreach ($bycm[(int) $cmid] ?? [] as $binding) {
                $bounduuids[$binding->nodeuuid] = true;
            }
        }
        $rescounts = [];
        if ($bounduuids) {
            foreach (resources::for_nodes(array_keys($bounduuids), null, true) as $resource) {
                $rescounts[$resource->nodeuuid] = ($rescounts[$resource->nodeuuid] ?? 0) + 1;
            }
        }

        $out = '';
        foreach ($modinfo->sections[(int) $target->section] ?? [] as $cmid) {
            $cm = $modinfo->cms[$cmid];
            if (!in_array($cm->modname, $mappabletypes)) {
                continue;
            }
            if ($modtypes && !in_array($cm->modname, $modtypes)) {
                continue;
            }
            $cmname = $cm->get_formatted_name();
            $rowpool = $modulepool;
            $ownroots = array_map(fn($b) => $b->nodeuuid, $bycm[(int) $cm->id] ?? []);
            if ($ownroots) {
                $extra = matcher::content_candidates($ownroots, ['sessionoutcome']);
                $rowpool = array_merge($rowpool, self::filter_pool($extra, $nodetypes));
            }
            $hints = matcher::match_title($cmname, $rowpool, $rules);
            $key = 'c' . (int) $cm->id;

            $namebits = s($cmname) . ' '
                . \html_writer::tag('span', s($cm->modname), ['class' => 'small text-muted']);
            if ($cm->modname === 'book') {
                $chapters = count($bychapter[(int) $cm->id] ?? []);
                $bookparams = ['bookcm' => (int) $cm->id];
                if ($pendingroots) {
                    // Unsaved section picks follow into the chapter view.
                    $bookparams['pending'] = implode(',', $pendingroots);
                }
                $bookurl = new \moodle_url($returnurl, $bookparams);
                $booklabel = get_string('contentmapping_mapchapters', 'local_curricmap', $chapters);
                $namebits .= \html_writer::div(\html_writer::link($bookurl, $booklabel, ['class' => 'small']));
            }
            $currentcell = self::current_cell($bycm[(int) $cm->id] ?? [], $returnurl, $rescounts);
            $proposalcell = self::proposal_cell($key, $hints, $rowpool, $narrowed || !empty($ownroots));
            $cells = \html_writer::div(self::tick($key, $cmname), 'curricmap-cell-tick')
                . \html_writer::div($namebits, 'curricmap-cell-name')
                . \html_writer::div($currentcell, 'curricmap-cell-current')
                . \html_writer::div($proposalcell, 'curricmap-cell-proposal');
            $out .= \html_writer::div($cells, 'curricmap-row curricmap-activity-row');
        }
        if ($out === '') {
            $norows = get_string('contentmapping_norows', 'local_curricmap');
            $out = \html_writer::tag('p', $norows, ['class' => 'text-muted small']);
        }
        return $out;
    }
}
