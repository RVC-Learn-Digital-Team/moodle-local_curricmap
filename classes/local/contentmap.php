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
use local_curricmap\api\resources;

/**
 * Shared building blocks for the Moodle Course Mapping page and its lazily
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
    /**
     * Node roles offered as activity/chapter targets.
     *
     * `unit` belongs here: in this estate a strand's spine book is chaptered BY
     * unit ("Unit 1", "Unit 2", each with subchapters), so a unit is the natural
     * target for a chapter, not merely for a section. It is also what populates
     * the node-type filter on the content mapper, so leaving it out removed Unit
     * from the filter as well as from the pool (found on GAB Animal Husbandry,
     * 2026-07-30).
     *
     * @var string[]
     */
    const TARGET_ROLES = ['unit', 'session', 'strandoutcome', 'sessionoutcome', 'assessment'];

    /** @var int Pools larger than this offer hints only. */
    const POOL_CAP = 300;

    /** @var array Body-text fields per module type (instance-table columns). */
    const BODY_FIELDS = [
        'page' => ['intro', 'content'],
        'book' => ['intro'],
        'label' => ['intro'],
        'url' => ['intro'],
        'forum' => ['intro'],
        'resource' => ['intro'],
        'folder' => ['intro'],
        'lesson' => ['intro'],
        'quiz' => ['intro'],
        'lti' => ['intro'],
    ];

    /** @var int Body text is capped at this many characters before matching. */
    const BODY_CAP = 8000;

    /**
     * A node label: title, code, role, YEAR OF STUDY and academic year.
     *
     * The year-of-study segment is not decoration. Node titles repeat across the
     * years of a programme - vet-med 2025-26 has two strands called "Animal
     * Husbandry" (one under Year 1, one under Graduate accelerated, only the
     * latter having units) and three called "Principles of Science" (Years 1, 2
     * and 3). Without the owning year node on the option, a picker cannot be
     * used correctly, and choosing the wrong twin silently produces an empty
     * pool below it (found on GAB, 2026-07-30).
     *
     * Pass $yeartitle when labelling a list - year_titles() resolves a whole
     * pool in one query, where calling this per node would walk the tree each
     * time.
     *
     * @param \stdClass $node Node-ish record (title, role, uuid, code).
     * @param string|null $yeartitle Owning year node's title, if already known.
     * @return string
     */
    public static function label(\stdClass $node, ?string $yeartitle = null): string {
        $year = preg_match('/_(20\d\d)_\d\d_/', $node->uuid, $matches) ? ' - ' . $matches[1] : '';
        $code = !empty($node->code) ? ' (' . $node->code . ')' : '';
        $of = ($yeartitle !== null && $yeartitle !== '' && $yeartitle !== $node->title)
            ? ' - ' . $yeartitle : '';
        return $node->title . $code . ' [' . $node->role . ']' . $of . $year;
    }

    /**
     * Owning year node title for each of the given nodes, in ONE query.
     *
     * Ancestry is a materialised path of node ids (/12/47/103/), so every
     * candidate ancestor can be gathered from the paths and resolved together
     * rather than walking parents per node.
     *
     * @param \stdClass[] $nodes Node records (need id, path).
     * @return array uuid => year node title (absent where none resolves).
     */
    public static function year_titles(array $nodes): array {
        global $DB;
        $ancestorids = [];
        foreach ($nodes as $node) {
            foreach (explode('/', (string) ($node->path ?? '')) as $part) {
                if ($part !== '') {
                    $ancestorids[(int) $part] = true;
                }
            }
        }
        if (!$ancestorids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($ancestorids), SQL_PARAMS_NAMED);
        $params['role'] = 'year';
        $years = $DB->get_records_select_menu(
            'local_curricmap_node',
            "id $insql AND role = :role",
            $params,
            '',
            'id, title'
        );
        $titles = [];
        foreach ($nodes as $node) {
            foreach (explode('/', (string) ($node->path ?? '')) as $part) {
                if ($part !== '' && isset($years[(int) $part])) {
                    $titles[$node->uuid] = $years[(int) $part];
                    break;
                }
            }
        }
        return $titles;
    }

    /**
     * The course's central bindings bucketed by grain.
     *
     * @param int $courseid Course id.
     * @return array [bysection, bycm, bychapter] — bychapter keyed cmid then subitemid.
     */
    public static function buckets(int $courseid): array {
        global $DB;
        $sql = "SELECT b.id, b.sectionid, b.cmid, b.component, b.subitemid, b.nodeuuid, n.title, n.role, n.code, n.path
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
        // Compact on the page, complete on hover (Brian's ruling, 2026-07-30):
        // the visible text stays "Title - YYYY" so rows do not bloat, and the
        // full disambiguated label (code, role, owning year node) rides in the
        // title attribute for the ambiguous-twins cases.
        $nodes = [];
        foreach ($rowbindings as $binding) {
            if ($binding->title !== null) {
                $nodes[] = (object) ['uuid' => $binding->nodeuuid, 'title' => $binding->title,
                    'code' => $binding->code ?? null, 'role' => $binding->role ?? null,
                    'path' => $binding->path ?? null];
            }
        }
        $yeartitles = self::year_titles($nodes);
        $entries = [];
        foreach ($rowbindings as $binding) {
            $removeurl = new \moodle_url($returnurl, ['unbind' => $binding->id, 'sesskey' => sesskey()]);
            $removeicon = $OUTPUT->pix_icon('t/delete', get_string('coursemapping_removematch', 'local_curricmap'));
            $year = preg_match('/_(20\d\d)_\d\d_/', $binding->nodeuuid, $matches) ? ' - ' . $matches[1] : '';
            if ($binding->title !== null) {
                $node = (object) ['uuid' => $binding->nodeuuid, 'title' => $binding->title,
                    'code' => $binding->code ?? null, 'role' => $binding->role ?? null];
                $full = self::label($node, $yeartitles[$binding->nodeuuid] ?? null);
                $label = \html_writer::tag('span', s($binding->title . $year), ['title' => s($full)]);
            } else {
                $label = s($binding->nodeuuid);
            }
            $resurl = new \moodle_url('/local/curricmap/study_resources.php', ['node' => $binding->nodeuuid]);
            $count = $rescounts[$binding->nodeuuid] ?? 0;
            $reslabel = get_string('studyresources_count', 'local_curricmap', $count);
            $entries[] = $label . ' ' . \html_writer::link($removeurl, $removeicon)
                . ' ' . \html_writer::link($resurl, $reslabel, ['class' => 'small']);
        }
        return implode(\html_writer::empty_tag('br'), $entries);
    }

    /**
     * The plain text of an activity's own content (intro plus, where the
     * module has one, its content field) — the matching corpus for body
     * hints. Empty when the type carries no body or the row is missing.
     *
     * @param \cm_info $cm The course module.
     * @return string
     */
    public static function body_text(\cm_info $cm): string {
        global $DB;
        if (!isset(self::BODY_FIELDS[$cm->modname])) {
            return '';
        }
        $fields = self::BODY_FIELDS[$cm->modname];
        $record = $DB->get_record($cm->modname, ['id' => (int) $cm->instance], implode(',', array_merge(['id'], $fields)));
        if (!$record) {
            return '';
        }
        $parts = [];
        foreach ($fields as $field) {
            if (!empty($record->$field)) {
                $parts[] = content_to_text((string) $record->$field, FORMAT_HTML);
            }
        }
        return \core_text::substr(trim(implode(' ', $parts)), 0, self::BODY_CAP);
    }

    /**
     * Title hints first, then body-text hints for nodes the title missed —
     * the two-signal proposal list. Body hints keep their frombody flag so
     * the picker can mark them.
     *
     * @param string $name The location's name.
     * @param string $bodytext The location's body text ('' skips the pass).
     * @param array $pool Candidate pool.
     * @param array $rules Matching rules.
     * @return array Scored hints.
     */
    public static function merged_hints(string $name, string $bodytext, array $pool, array $rules): array {
        $hints = matcher::match_title($name, $pool, $rules);
        if ($bodytext === '') {
            return $hints;
        }
        $seen = [];
        foreach ($hints as $hint) {
            $seen[$hint->candidate->node->uuid] = true;
        }
        foreach (matcher::match_body($bodytext, $pool, $rules) as $bodyhint) {
            if (!isset($seen[$bodyhint->candidate->node->uuid])) {
                $hints[] = $bodyhint;
            }
        }
        return $hints;
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
    public static function proposal_cell(
        string $key,
        array $hints,
        array $pool,
        bool $narrowed,
        ?string $browseroot = null
    ): string {
        // One query for the whole option list: every node title in this estate
        // repeats across years, so each option has to name its owning year node.
        $labelnodes = array_map(fn($hint) => $hint->candidate->node, $hints);
        $capped = count($pool) > self::POOL_CAP;
        if (!$capped) {
            foreach ($pool as $candidate) {
                $labelnodes[] = $candidate->node;
            }
        }
        $yeartitles = self::year_titles($labelnodes);

        $options = [];
        foreach ($hints as $hint) {
            $node = $hint->candidate->node;
            $percent = (int) round($hint->score * 100);
            $tag = empty($hint->frombody) ? '' : ' ' . get_string('contentmapping_bodyhint', 'local_curricmap');
            $options[$node->uuid] = self::label($node, $yeartitles[$node->uuid] ?? null)
                . ' [' . $percent . '%' . $tag . ']';
        }
        if (!$capped) {
            foreach ($pool as $candidate) {
                $node = $candidate->node;
                if (!isset($options[$node->uuid])) {
                    $options[$node->uuid] = self::label($node, $yeartitles[$node->uuid] ?? null);
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
        // Browse: navigate the curriculum instead of searching it. This is the
        // path for content whose name shares no words with Sofia's ("Base Unit
        // Test Horse Bladders") - hints are empty and, above POOL_CAP, so is
        // the dropdown, so without browse such a row is simply unmappable.
        // Picks land as hidden bind{key}[] inputs beside the select, so Apply
        // reads them through the exact same code path.
        if ($browseroot !== null) {
            $cell .= \html_writer::div(
                \html_writer::link('#', get_string('contentmapping_browse', 'local_curricmap'), [
                    'data-curricmap-browse' => $key,
                    'data-curricmap-root' => $browseroot,
                ]),
                'small mt-1'
            );
            $cell .= \html_writer::div('', 'curricmap-picks', ['data-curricmap-picks' => $key]);
            $panelattrs = ['data-curricmap-browsepanel' => $key];
            $cell .= \html_writer::div('', 'curricmap-browsepanel border rounded p-2 mt-1 d-none', $panelattrs);
        }
        return $cell;
    }

    /**
     * One level of the curriculum tree for a row's Browse panel: breadcrumb
     * down from the slug-year node, then the root's children with counts,
     * drill links and pick buttons.
     *
     * The ceiling is the YEAR node, ruled 2026-07-30: browsing never goes
     * above slug-year - cross-year or cross-programme mapping is done
     * manually, even by admins.
     *
     * @param string $rootuuid Node to list the children of.
     * @param string $key Row key the panel belongs to.
     * @return string HTML.
     */
    public static function browse_panel(string $rootuuid, string $key): string {
        global $DB;
        $root = curriculum::node($rootuuid);
        if (!$root || !empty($root->deleted)) {
            return \html_writer::tag('em', get_string('contentmapping_nopool', 'local_curricmap'));
        }

        // Breadcrumb: ancestors from the year node down to the current root.
        $ancestorids = array_filter(array_map('intval', explode('/', (string) $root->path)));
        array_pop($ancestorids); // The root itself is not its own ancestor.
        $crumbs = [];
        if ($ancestorids) {
            [$insql, $params] = $DB->get_in_or_equal($ancestorids, SQL_PARAMS_NAMED);
            $ancestors = $DB->get_records_select('local_curricmap_node', "id $insql", $params);
            $ordered = [];
            foreach ($ancestorids as $id) {
                if (isset($ancestors[$id])) {
                    $ordered[] = $ancestors[$id];
                }
            }
            $seenyear = false;
            foreach ($ordered as $ancestor) {
                if (!$seenyear && $ancestor->role !== 'year') {
                    continue; // Programme-level and above: never browsable.
                }
                $seenyear = true;
                $crumbs[] = \html_writer::link('#', s($ancestor->title), [
                    'data-curricmap-drill' => $ancestor->uuid,
                    'data-curricmap-key' => $key,
                ]);
            }
        }
        // The CURRENT node is itself pickable - a chapter that teaches the
        // whole strand maps to the strand, not to something below it (the
        // spine-book lesson again: the pool must include the node itself).
        // Ancestors need no button: drill up and any of them becomes current.
        $rootyears = self::year_titles([$root]);
        $rootpick = \html_writer::tag('button', get_string('contentmapping_pick', 'local_curricmap'), [
            'type' => 'button',
            'class' => 'btn btn-sm btn-outline-secondary py-0',
            'data-curricmap-pick' => $root->uuid,
            'data-curricmap-key' => $key,
            'data-curricmap-picklabel' => self::label($root, $rootyears[$root->uuid] ?? null),
        ]);
        $crumbs[] = \html_writer::tag('strong', s($root->title)) . ' ' . $rootpick;
        $out = \html_writer::div(implode(' &rsaquo; ', $crumbs), 'small mb-2');

        $children = curriculum::children($rootuuid);
        if (!$children) {
            $empty = get_string('contentmapping_browseempty', 'local_curricmap');
            return $out . \html_writer::tag('em', $empty, ['class' => 'small']);
        }

        // Grandchild counts by role, one query for the whole level.
        $childids = array_map(fn($node) => (int) $node->id, $children);
        [$insql, $params] = $DB->get_in_or_equal($childids, SQL_PARAMS_NAMED);
        $countsql = "SELECT " . $DB->sql_concat('parentid', "'-'", 'role') . " AS pk,
                            parentid, role, COUNT(id) AS n
                       FROM {local_curricmap_node}
                      WHERE parentid $insql AND deleted = 0
                   GROUP BY parentid, role";
        $grandcounts = [];
        foreach ($DB->get_records_sql($countsql, $params) as $row) {
            $grandcounts[(int) $row->parentid][$row->role] = (int) $row->n;
        }

        $yeartitles = self::year_titles($children);
        $items = [];
        foreach ($children as $child) {
            $bits = [];
            $counts = $grandcounts[(int) $child->id] ?? [];
            $total = array_sum($counts);
            $roletag = \html_writer::tag('small', '[' . s($child->role) . ']', ['class' => 'text-muted']);
            $fulltitle = s(self::label($child, $yeartitles[$child->uuid] ?? null));
            $label = \html_writer::tag('span', s($child->title) . ' ' . $roletag, ['title' => $fulltitle]);
            if ($total) {
                $summary = [];
                foreach ($counts as $role => $n) {
                    $summary[] = $n . ' ' . $role;
                }
                $bits[] = \html_writer::link('#', $label, [
                    'data-curricmap-drill' => $child->uuid,
                    'data-curricmap-key' => $key,
                ]);
                $bits[] = \html_writer::tag('small', implode(' · ', $summary), ['class' => 'text-muted']);
            } else {
                $bits[] = $label;
            }
            $bits[] = \html_writer::tag('button', get_string('contentmapping_pick', 'local_curricmap'), [
                'type' => 'button',
                'class' => 'btn btn-sm btn-outline-secondary py-0',
                'data-curricmap-pick' => $child->uuid,
                'data-curricmap-key' => $key,
                'data-curricmap-picklabel' => self::label($child, $yeartitles[$child->uuid] ?? null),
            ]);
            $items[] = \html_writer::tag('li', implode(' ', $bits), ['class' => 'mb-1']);
        }
        return $out . \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-0']);
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
            $hints = self::merged_hints($cmname, self::body_text($cm), $rowpool, $rules);
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
            $browseroot = $ownroots[0] ?? ($roots[0] ?? ($rootuuids[0] ?? null));
            $proposalcell = self::proposal_cell($key, $hints, $rowpool, $narrowed || !empty($ownroots), $browseroot);
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
