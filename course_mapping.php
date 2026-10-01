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

/**
 * Central Admin Mapping, in two directions: by Moodle course (review each
 * course's proposed programme-year match) or by Sofia curriculum (pick a
 * programme year, then gather the courses that belong to it — including
 * support courses without an idnumber). Ticked rows are confirmed explicitly;
 * confirming creates central-scope anchor bindings and nothing binds
 * unreviewed.
 *
 * ONE central decision per course, and this page never changes it once made
 * (delete-and-redo is the only correction path here): a course matched to a
 * strand IS a strand course; a course matched to a year maps its strands
 * per-section on section_module_mapping.php (Moodle Course Mapping); manual
 * extra mappings (course scope, or central for central staff) are made on
 * each course's Add Additional Mappings page (mappings.php).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_curricmap\api\bindings;
use local_curricmap\local\contentmap;
use local_curricmap\local\matcher;

admin_externalpage_setup('local_curricmap_coursemapping');

$mode = optional_param('mode', 'course', PARAM_ALPHA);
$mode = $mode === 'sofia' ? 'sofia' : 'course';
$search = trim(optional_param('search', '', PARAM_RAW_TRIMMED));
// Support courses often have no idnumber, so the Sofia-first mode includes them by default.
$requireid = optional_param('requireid', $mode === 'sofia' ? 0 : 1, PARAM_BOOL);
$show = optional_param('show', 'suggested', PARAM_ALPHA);
if (!in_array($show, ['suggested', 'matched', 'unmatched', 'skipped', 'all'])) {
    $show = 'suggested';
}
$strands = optional_param('strands', 1, PARAM_BOOL);
$pre2024 = optional_param('pre2024', 0, PARAM_BOOL);
$slugyear = optional_param('slugyear', '', PARAM_RAW_TRIMMED);
$nodeparam = optional_param('node', '', PARAM_RAW_TRIMMED);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$candidates = matcher::candidates($strands);
$rules = matcher::rules();

// The slug-year filter narrows which nodes are OFFERED (the Sofia node
// select) AND which course rows show: the academic year is enforced on every
// row, and the slug on what the course is MATCHED to, falling back to its
// proposal when nothing is matched yet. Blank = all.
$slugyears = [];
foreach ($candidates as $candidate) {
    $key = $candidate->programme->slug . ':' . $candidate->yearstart;
    $yearlabel = $candidate->yearstart . '-' . sprintf('%02d', ($candidate->yearstart + 1) % 100);
    $slugyears[$key] = $candidate->programme->slug . ' ' . $yearlabel;
}
ksort($slugyears);
if ($slugyear !== '' && !isset($slugyears[$slugyear])) {
    $slugyear = '';
}
$slugyearslug = null;
$slugyearstart = null;
if ($slugyear !== '') {
    [$slugyearslug, $slugyearstart] = explode(':', $slugyear);
    $slugyearstart = (int) $slugyearstart;
}
$offered = $candidates;
if ($slugyear !== '') {
    $offered = array_values(array_filter(
        $candidates,
        fn($c) => $c->programme->slug . ':' . $c->yearstart === $slugyear
    ));
}

// The Sofia-first target node, defaulting to the first offered programme year.
$target = null;
foreach ($offered as $candidate) {
    if ($candidate->node->uuid === $nodeparam) {
        $target = $candidate;
    }
}
if ($mode === 'sofia' && $target === null && $offered) {
    $target = $offered[0];
}

$urlparams = ['mode' => $mode, 'search' => $search, 'requireid' => $requireid, 'show' => $show,
    'strands' => $strands, 'pre2024' => $pre2024, 'slugyear' => $slugyear, 'page' => $page];
if ($target) {
    $urlparams['node'] = $target->node->uuid;
}
$pageurl = new moodle_url('/local/curricmap/course_mapping.php', $urlparams);

// Remove one central match (the delete icon in the Current matches column).
$unbind = optional_param('unbind', 0, PARAM_INT);
if ($unbind && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $binding = $DB->get_record('local_curricmap_binding', ['id' => $unbind], '*', MUST_EXIST);
    if ($binding->scope === 'central' && $binding->courseid !== null) {
        if (!optional_param('confirm', 0, PARAM_BOOL)) {
            $node = \local_curricmap\api\curriculum::node($binding->nodeuuid);
            $confirmurl = new moodle_url($pageurl, ['unbind' => $unbind, 'confirm' => 1, 'sesskey' => sesskey()]);
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(
                get_string('coursemapping_confirmremove', 'local_curricmap', s($node->title ?? $binding->nodeuuid)),
                $confirmurl,
                $pageurl
            );
            echo $OUTPUT->footer();
            exit;
        }
        bindings::unbind((int) $binding->id);
        redirect($pageurl, get_string('coursemapping_removed', 'local_curricmap'));
    }
    redirect($pageurl);
}

// Apply: create central-scope matches for the ticked rows.
$apply = optional_param_array('apply', [], PARAM_INT);
$selections = optional_param_array('bind', [], PARAM_RAW_TRIMMED);
if ($apply && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $created = 0;
    foreach ($apply as $courseid => $ticked) {
        if (!$ticked) {
            continue;
        }
        $nodeuuid = $mode === 'sofia' ? ($target->node->uuid ?? '') : ($selections[$courseid] ?? '');
        if ($nodeuuid === '') {
            continue;
        }
        bindings::bind(['courseid' => (int) $courseid], $nodeuuid, bindings::RELATION_ANCHOR, 'central');
        $created++;
    }
    redirect($pageurl, get_string('coursemapping_applied', 'local_curricmap', $created));
}

/**
 * A candidate's display label: programme, year context for strands, node
 * title, academic year.
 *
 * @param stdClass $candidate Matcher candidate.
 * @return string
 */
function local_curricmap_course_mapping_label(stdClass $candidate): string {
    $programme = $candidate->programme->displayname ?: $candidate->programme->slug;
    $year = $candidate->yearstart . '-' . sprintf('%02d', ($candidate->yearstart + 1) % 100);
    $middle = $candidate->yeartitle !== null ? $candidate->yeartitle . ' / ' : '';
    $code = !empty($candidate->node->code) ? ' (' . $candidate->node->code . ')' : '';
    return $programme . ' / ' . $middle . $candidate->node->title . $code . ' (' . $year . ')';
}

/**
 * A current match's label: what shows in the column, and the fully
 * disambiguated version for its tooltip.
 *
 * The visible form names the programme the way the proposal dropdown does,
 * so both columns read alike; the tooltip adds the slug, the node code and
 * its role for the cases where display names collide.
 *
 * @param stdClass $binding Binding row carrying the joined node columns.
 * @param stdClass|null $programme Owning programme record, null when unknown.
 * @param string|null $yeartitle Owning year-node title, null at year level.
 * @return string[] [visible label, tooltip].
 */
function local_curricmap_course_mapping_match_label(stdClass $binding, ?stdClass $programme, ?string $yeartitle): array {
    $slug = $programme ? $programme->slug : '';
    $name = $programme ? ($programme->displayname ?: $programme->slug) : '';
    $year = '';
    if (preg_match('/_(20\d\d)_(\d\d)_/', $binding->nodeuuid, $matches)) {
        $year = ' (' . $matches[1] . '-' . $matches[2] . ')';
    }
    $middle = ($yeartitle !== null && $yeartitle !== '' && $yeartitle !== $binding->title)
        ? $yeartitle . ' / ' : '';
    $code = !empty($binding->code) ? ' (' . $binding->code . ')' : '';
    $visible = ltrim($name . ' / ' . $middle . $binding->title . $year, ' /');
    $tooltip = ltrim($slug . ' / ' . $middle . $binding->title . $code . ' [' . $binding->role . ']' . $year, ' /');
    return [$visible, $tooltip];
}

/**
 * The composed-key prefix (slug_year_yy_) shared by every node of a
 * candidate's programme year.
 *
 * @param stdClass $candidate Matcher candidate.
 * @return string
 */
function local_curricmap_course_mapping_prefix(stdClass $candidate): string {
    return $candidate->programme->slug . '_' . $candidate->yearstart . '_'
        . sprintf('%02d', ($candidate->yearstart + 1) % 100) . '_';
}

/**
 * A status badge span.
 *
 * @param string $status Matcher status or 'searchresult'.
 * @return string HTML.
 */
function local_curricmap_course_mapping_badge(string $status): string {
    $classes = [
        matcher::STATUS_MATCH => 'badge badge-success',
        matcher::STATUS_SUGGEST => 'badge badge-info',
        matcher::STATUS_NOYEAR => 'badge badge-warning',
        'searchresult' => 'badge badge-info',
    ];
    $class = $classes[$status] ?? 'badge badge-secondary';
    $label = get_string('coursemapping_status_' . $status, 'local_curricmap');
    return html_writer::tag('span', $label, ['class' => $class]);
}

// Every non-site course with its category name; visibility filters run in PHP
// because matching signals (harmonised year, proposals) are parsed, not stored.
$sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber, cc.name AS categoryname, cc.path AS categorypath
          FROM {course} c
     LEFT JOIN {course_categories} cc ON cc.id = c.category
         WHERE c.id <> :siteid
      ORDER BY c.fullname ASC";
$courses = $DB->get_records_sql($sql, ['siteid' => SITEID]);

// The excludecategories rule matches the TOP of the category tree, so a
// subcategory cannot escape the area it belongs to. Resolved here because
// only this page runs the matcher over whole-estate course records.
$categorynames = $DB->get_records_menu('course_categories', null, '', 'id, name');
foreach ($courses as $course) {
    $path = array_filter(array_map('intval', explode('/', (string) $course->categorypath)));
    $topid = $path ? reset($path) : 0;
    $course->topcategoryname = $topid ? ($categorynames[$topid] ?? '') : (string) $course->categoryname;
}

// Existing course-level central matches, one query for the whole estate.
$currentmatches = [];
$matchsql = "SELECT b.id, b.courseid, b.nodeuuid, n.title, n.code, n.role, n.path, n.programmeid
               FROM {local_curricmap_binding} b
          LEFT JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
              WHERE b.relation = :relation AND b.scope = :scope AND b.status = :status
                    AND b.courseid IS NOT NULL AND b.sectionid IS NULL AND b.cmid IS NULL
           ORDER BY b.sortorder ASC, b.id ASC";
$matchparams = ['relation' => bindings::RELATION_ANCHOR, 'scope' => 'central', 'status' => 'active'];
foreach ($DB->get_records_sql($matchsql, $matchparams) as $binding) {
    $currentmatches[(int) $binding->courseid][] = $binding;
}

// Programme and owning-year context for the Current matches column: on its
// own "Year 2 - 2024" names neither the programme nor the full academic year
// (Brian, 2026-09-24). Resolved once for the whole estate.
$programmes = $DB->get_records('local_curricmap_programme');
$matchnodes = [];
foreach ($currentmatches as $bindings) {
    foreach ($bindings as $binding) {
        if ($binding->title !== null) {
            $matchnodes[$binding->nodeuuid] = (object) ['uuid' => $binding->nodeuuid,
                'title' => $binding->title, 'code' => $binding->code,
                'role' => $binding->role, 'path' => $binding->path];
        }
    }
}
$matchyeartitles = $matchnodes ? contentmap::year_titles(array_values($matchnodes)) : [];

// Search terms: a year-shaped token filters by harmonised year, the rest are
// keywords that must all appear in name, idnumber or category.
$searchyear = null;
$keywords = [];
foreach (preg_split('/\s+/', matcher::normalise($search), -1, PREG_SPLIT_NO_EMPTY) as $token) {
    if (preg_match('~^(20\d\d)(?:[-/]\d{2,4}|\d\d)?$~', $token, $matches)) {
        $searchyear = (int) $matches[1];
    } else {
        $keywords[] = core_text::strtolower($token);
    }
}

// Filter and match.
$rows = [];
foreach ($courses as $course) {
    if ($requireid && trim($course->idnumber) === '') {
        continue;
    }
    if ($keywords) {
        $haystack = core_text::strtolower(matcher::normalise(
            $course->fullname . ' ' . $course->shortname . ' ' . $course->idnumber . ' ' . ($course->categoryname ?? '')
        ));
        foreach ($keywords as $keyword) {
            if (strpos($haystack, $keyword) === false) {
                continue 2;
            }
        }
    }
    $result = matcher::match($course, $candidates, $rules);
    if ($searchyear !== null && $result->year !== $searchyear) {
        continue;
    }
    // Pre-2024 courses hidden unless asked for; year-less courses are never
    // excluded by this toggle (ruled 2026-08-06).
    if (!$pre2024 && $result->year !== null && $result->year < 2024) {
        continue;
    }
    // A selected slug-year enforces its academic year on every row, and its
    // slug on rows carrying a proposal - unmatched courses have no programme
    // yet, so the slug cannot exclude them (ruled 2026-08-06).
    if ($slugyearstart !== null) {
        if ($result->year !== $slugyearstart) {
            continue;
        }
        // What a course is MATCHED to outranks what the engine proposes: a
        // 1VETS course matched to vet-med must not surface under a bio-sc
        // filter merely because it carries no proposal (Brian, 2026-09-24).
        $bound = $currentmatches[(int) $course->id] ?? [];
        if ($bound) {
            $inslug = false;
            foreach ($bound as $binding) {
                if (strpos($binding->nodeuuid, $slugyearslug . '_') === 0) {
                    $inslug = true;
                }
            }
            if (!$inslug) {
                continue;
            }
        } else {
            $proposal = $result->best ?? ($result->suggestions[0]->candidate ?? null);
            if ($proposal && $proposal->programme->slug !== $slugyearslug) {
                continue;
            }
        }
    }
    $rows[] = (object) ['course' => $course, 'result' => $result];
}

// Slug|yearstart|yeartitle -> year-node uuid, for positioning a strand
// proposal's browse panel at its OWNING programme year (ruled 2026-08-06).
// The year title is part of the key because multi-year-node programmes
// (vet-nur's Year 1-4, vet-med's Year 1-5/Gateway/GAB) share slug|yearstart:
// without it the map kept only the last year node, so every browse panel
// opened at Year 4 whatever the proposal said.
$browseyearnodes = [];
foreach ($candidates as $candidate) {
    if ($candidate->yeartitle === null) {
        $browsekey = $candidate->programme->slug . '|' . $candidate->yearstart . '|' . $candidate->node->title;
        $browseyearnodes[$browsekey] = $candidate->node->uuid;
    }
}

$showcounts = ['suggested' => 0, 'matched' => 0, 'unmatched' => 0, 'skipped' => 0, 'all' => 0];
if ($mode === 'course') {
    // Every course sits in exactly ONE band, so the Show counts add up to
    // "all courses". MATCHED means a match the user selected (Brian,
    // 2026-09-24) - it wins over every other band, including a skip pattern,
    // because a deliberate decision must stay visible, and a proposal that
    // disagrees with it never drags the course back into the working queue.
    // suggested = the engine proposes something and nothing is matched yet;
    // unmatched = no proposal and no match; skipped = a skip rule fired.
    $band = function ($row) use ($currentmatches) {
        if (!empty($currentmatches[(int) $row->course->id])) {
            return 'matched';
        }
        if ($row->result->status === matcher::STATUS_SKIPPED) {
            return 'skipped';
        }
        $proposed = [matcher::STATUS_MATCH, matcher::STATUS_SUGGEST];
        return in_array($row->result->status, $proposed) ? 'suggested' : 'unmatched';
    };
    foreach ($rows as $row) {
        $showcounts['all']++;
        $showcounts[$band($row)]++;
    }
    // A search whose hits all sit outside the selected Show band would
    // display nothing while the results are one filter away - switch the
    // band to suit (ruled 2026-08-07). Only while searching, only when the
    // current band is empty, and to the one non-empty band when there is
    // exactly one ('all' when the hits span several bands).
    if ($search !== '' && $show !== 'all' && ($showcounts[$show] ?? 0) === 0 && $showcounts['all'] > 0) {
        $bandkeys = array_flip(['suggested', 'matched', 'unmatched', 'skipped']);
        $bandcounts = array_intersect_key($showcounts, $bandkeys);
        $nonempty = array_keys(array_filter($bandcounts));
        $show = count($nonempty) === 1 ? $nonempty[0] : 'all';
    }
    $rows = array_values(array_filter($rows, function ($row) use ($show, $band) {
        return $show === 'all' || $band($row) === $show;
    }));
} else if ($target) {
    // Rank each course's fit for the target node: already matched to it,
    // proposed for it, suggested for it (scored), or a plain search result.
    $targetprefix = local_curricmap_course_mapping_prefix($target);
    $ranked = [];
    foreach ($rows as $row) {
        $courseid = (int) $row->course->id;
        $bound = array_map(fn($b) => $b->nodeuuid, $currentmatches[$courseid] ?? []);
        // A course proposed for a strand still belongs to that strand's year,
        // so a year target counts it as proposed too.
        $best = $row->result->best;
        $bestfits = $best && ($best->node->uuid === $target->node->uuid
            || ($target->yeartitle === null && $best->yeartitle !== null
                && $best->programme->slug === $target->programme->slug
                && $best->yearstart === $target->yearstart
                && $best->yeartitle === (string) $target->node->title));
        // Any central anchor within the target's programme year means the
        // course's decision is made and this page never changes it: a
        // strand-matched course IS a strand course, a year-matched course
        // maps its strands on the Moodle Course Mapping page, and extra
        // mappings belong on the course's Add Additional Mappings page.
        $boundinyear = array_filter($bound, fn($uuid) => strpos($uuid, $targetprefix) === 0);
        if (in_array($target->node->uuid, $bound) || $boundinyear) {
            $row->fit = 'existing';
            $row->rank = 1;
        } else if ($bestfits) {
            $row->fit = matcher::STATUS_MATCH;
            $row->rank = 2;
        } else {
            $row->score = 0;
            foreach ($row->result->suggestions as $suggestion) {
                if ($suggestion->candidate->node->uuid === $target->node->uuid) {
                    $row->score = $suggestion->score;
                }
            }
            if ($row->score > 0) {
                $row->fit = matcher::STATUS_SUGGEST;
                $row->rank = 3;
            } else {
                // Blank search is open search: every course stays pickable,
                // ranked below the matches and suggestions.
                $row->fit = ($keywords || $searchyear !== null) ? 'searchresult' : 'candidate';
                $row->rank = 4;
            }
        }
        $ranked[] = $row;
    }
    usort($ranked, fn($a, $b) => [$a->rank, -($a->score ?? 0)] <=> [$b->rank, -($b->score ?? 0)]);
    $rows = $ranked;
} else {
    $rows = [];
}

$total = count($rows);
$rows = array_slice($rows, $page * $perpage, $perpage);

$PAGE->requires->js_call_amd('core/checkbox-toggleall', 'init');
$typetosearch = get_string('coursemapping_typetosearch', 'local_curricmap');
$PAGE->requires->js_call_amd('local_curricmap/course_mapping', 'init', [$typetosearch]);
$PAGE->requires->js_call_amd('local_curricmap/browse', 'init', [context_system::instance()->id]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursemapping', 'local_curricmap'));
echo html_writer::tag('p', get_string('coursemapping_intro', 'local_curricmap'));

// The whole toolbar is ONE GET form: any select or checkbox change resubmits
// it (see the course_mapping AMD module), so the current search text and every
// filter always travel together. Unchecked checkboxes fall back to the hidden
// zero inputs.
$formurl = new moodle_url('/local/curricmap/course_mapping.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out_omit_querystring(),
    'class' => 'local-curricmap-filterform d-flex flex-wrap align-items-center mb-3', 'style' => 'gap: 8px;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'requireid', 'value' => 0]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'strands', 'value' => 0]);
$searchattrs = ['type' => 'text', 'name' => 'search', 'value' => $search,
    'placeholder' => get_string('coursemapping_search', 'local_curricmap'), 'class' => 'form-control',
    'style' => 'width: 220px;'];
echo html_writer::empty_tag('input', $searchattrs);

$requireidattrs = ['type' => 'checkbox', 'name' => 'requireid', 'value' => 1, 'class' => 'mr-1'];
if ($requireid) {
    $requireidattrs['checked'] = 'checked';
}
echo html_writer::start_tag('label', ['class' => 'mb-0 d-flex align-items-center text-nowrap']);
echo html_writer::empty_tag('input', $requireidattrs);
echo get_string('coursemapping_onlyidnumber', 'local_curricmap');
echo html_writer::end_tag('label');

$strandsattrs = ['type' => 'checkbox', 'name' => 'strands', 'value' => 1, 'class' => 'mr-1'];
if ($strands) {
    $strandsattrs['checked'] = 'checked';
}
echo html_writer::start_tag('label', ['class' => 'mb-0 d-flex align-items-center text-nowrap']);
echo html_writer::empty_tag('input', $strandsattrs);
echo get_string('coursemapping_includestrands', 'local_curricmap');
echo html_writer::end_tag('label');

$pre2024attrs = ['type' => 'checkbox', 'name' => 'pre2024', 'value' => 1, 'class' => 'mr-1'];
if ($pre2024) {
    $pre2024attrs['checked'] = 'checked';
}
echo html_writer::start_tag('label', ['class' => 'mb-0 d-flex align-items-center text-nowrap']);
echo html_writer::empty_tag('input', $pre2024attrs);
echo get_string('coursemapping_pre2024', 'local_curricmap');
echo html_writer::end_tag('label');

$slugyearoptions = ['' => get_string('coursemapping_slugyear_all', 'local_curricmap')] + $slugyears;
$slugyearattrs = ['aria-label' => get_string('coursemapping_slugyear', 'local_curricmap')];
echo html_writer::select($slugyearoptions, 'slugyear', $slugyear, false, $slugyearattrs);

if ($mode === 'course') {
    $showoptions = [
        'suggested' => get_string('coursemapping_show_suggested', 'local_curricmap', $showcounts['suggested']),
        'matched' => get_string('coursemapping_show_matched', 'local_curricmap', $showcounts['matched']),
        'unmatched' => get_string('coursemapping_show_unmatched', 'local_curricmap', $showcounts['unmatched']),
        'skipped' => get_string('coursemapping_show_skipped', 'local_curricmap', $showcounts['skipped']),
        'all' => get_string('coursemapping_show_all', 'local_curricmap', $showcounts['all']),
    ];
    $showattrs = ['aria-label' => get_string('coursemapping_show', 'local_curricmap')];
    echo html_writer::select($showoptions, 'show', $show, false, $showattrs);
}

$modeoptions = [
    'course' => get_string('coursemapping_mode_course', 'local_curricmap'),
    'sofia' => get_string('coursemapping_mode_sofia', 'local_curricmap'),
];
$modeattrs = ['aria-label' => get_string('coursemapping_mode', 'local_curricmap')];
echo html_writer::select($modeoptions, 'mode', $mode, false, $modeattrs);

if ($mode === 'sofia' && $target) {
    $nodeoptions = [];
    foreach ($offered as $candidate) {
        $nodeoptions[$candidate->node->uuid] = local_curricmap_course_mapping_label($candidate);
    }
    $nodeattrs = ['aria-label' => get_string('coursemapping_sofianode', 'local_curricmap'),
        'id' => 'curricmap-node', 'data-curricmap-node' => 1];
    echo html_writer::select($nodeoptions, 'node', $target->node->uuid, false, $nodeattrs);
}

echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if ($mode === 'sofia' && !$target) {
    echo $OUTPUT->notification(get_string('coursemapping_nonodes', 'local_curricmap'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// The table, wrapped in the apply form.
$masterattrs = ['type' => 'checkbox', 'data-action' => 'toggle', 'data-toggle' => 'master',
    'data-togglegroup' => 'coursematch', 'aria-label' => get_string('coursemapping_selectall', 'local_curricmap')];

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    html_writer::empty_tag('input', $masterattrs),
    get_string('coursemapping_course', 'local_curricmap'),
    get_string('coursemapping_year', 'local_curricmap'),
    get_string('coursemapping_currentmatches', 'local_curricmap'),
    $mode === 'sofia'
        ? get_string('coursemapping_fit', 'local_curricmap')
        : get_string('coursemapping_proposal', 'local_curricmap'),
];

foreach ($rows as $row) {
    $course = $row->course;
    $result = $row->result;
    $courseid = (int) $course->id;

    $coursecell = html_writer::link(new moodle_url('/course/view.php', ['id' => $courseid]), s($course->fullname));
    $subline = trim(s($course->idnumber) . ' · ' . s($course->categoryname ?? ''), ' ·');
    $coursecell .= html_writer::tag('div', $subline, ['class' => 'small text-muted']);
    $coursecell .= html_writer::link(
        new moodle_url('/local/curricmap/section_module_mapping.php', ['courseid' => $courseid]),
        get_string('contentmapping_link', 'local_curricmap'),
        ['class' => 'small']
    );
    $coursecell .= ' · ' . html_writer::link(
        new moodle_url('/local/curricmap/mappings.php', ['courseid' => $courseid]),
        get_string('mappings', 'local_curricmap'),
        ['class' => 'small']
    );

    $yearcell = $result->year
        ? $result->year . '-' . sprintf('%02d', ($result->year + 1) % 100)
        : local_curricmap_course_mapping_badge(matcher::STATUS_NOYEAR);

    $currententries = [];
    foreach ($currentmatches[$courseid] ?? [] as $binding) {
        $removeurl = new moodle_url($pageurl, ['unbind' => $binding->id, 'sesskey' => sesskey()]);
        $removeicon = $OUTPUT->pix_icon('t/delete', get_string('coursemapping_removematch', 'local_curricmap'));
        // Programme, year of study, strand and academic year all matter:
        // titles repeat ACROSS the years of one programme (two "Animal
        // Husbandry", three "Principles of Science") and across programmes,
        // so a bare "Year 2 - 2024" identifies nothing. Compact on the page,
        // fully disambiguated (slug, code, role) on hover.
        if ($binding->title !== null) {
            $programme = $programmes[$binding->programmeid] ?? null;
            $yeartitle = $matchyeartitles[$binding->nodeuuid] ?? null;
            $labels = local_curricmap_course_mapping_match_label($binding, $programme, $yeartitle);
            $label = html_writer::tag('span', s($labels[0]), ['title' => s($labels[1])]);
        } else {
            $label = s($binding->nodeuuid);
        }
        $currententries[] = $label . ' ' . html_writer::link($removeurl, $removeicon);
    }
    $currentcell = implode(html_writer::empty_tag('br'), $currententries);

    $tickattrs = ['type' => 'checkbox', 'name' => "apply[$courseid]", 'value' => 1,
        'data-action' => 'toggle', 'data-toggle' => 'slave', 'data-togglegroup' => 'coursematch',
        'aria-label' => get_string('coursemapping_selectcourse', 'local_curricmap', s($course->shortname))];

    if ($mode === 'sofia') {
        if ($row->fit === 'existing') {
            $tick = '';
            $donelabel = get_string('coursemapping_alreadymatched', 'local_curricmap');
            $fitcell = html_writer::tag('span', $donelabel, ['class' => 'badge badge-secondary']);
        } else {
            $tick = html_writer::empty_tag('input', $tickattrs);
            if ($row->fit === 'candidate') {
                $fitcell = html_writer::tag('span', '—', ['class' => 'text-muted']);
            } else {
                $fitcell = local_curricmap_course_mapping_badge($row->fit);
                if (($row->score ?? 0) > 0) {
                    $scoretag = '[' . $row->score . ']';
                    $fitcell .= ' ' . html_writer::tag('span', $scoretag, ['class' => 'small text-muted']);
                }
            }
        }
        $table->data[] = [$tick, $coursecell, $yearcell, $currentcell, $fitcell];
        continue;
    }

    // Course mode. A course the user has already matched gets no tick and no
    // dropdown - delete-and-redo is the correction path: a strand-matched course
    // IS a strand course, a year-matched course maps its strands on the
    // Moodle Course Mapping page, and NOTHING here changes either —
    // extra mappings belong on the course's Add Additional Mappings page.
    if (!empty($currentmatches[$courseid])) {
        $donelabel = get_string('coursemapping_alreadymatched', 'local_curricmap');
        $donecell = html_writer::tag('span', $donelabel, ['class' => 'badge badge-secondary']);
        $table->data[] = ['', $coursecell, $yearcell, $currentcell, $donecell];
        continue;
    }

    // The dropdown holds the engine's proposals only; manual picks come from
    // the More curriculum browse below (the old stuff-the-dropdown role of
    // the slug-year select ended when it became a row filter, 2026-08-06).
    $bounduuids = array_map(fn($binding) => $binding->nodeuuid, $currentmatches[$courseid] ?? []);
    $proposals = [];
    $selected = '';
    if ($result->best) {
        $proposals[$result->best->node->uuid] = local_curricmap_course_mapping_label($result->best);
        if (!in_array($result->best->node->uuid, $bounduuids)) {
            $selected = $result->best->node->uuid;
        }
    }
    foreach ($result->suggestions as $suggestion) {
        $label = local_curricmap_course_mapping_label($suggestion->candidate);
        if ($suggestion->score > 0) {
            $label .= ' [' . $suggestion->score . ']';
        }
        $proposals[$suggestion->candidate->node->uuid] = $label;
    }
    $options = ['' => get_string('coursemapping_noaction', 'local_curricmap')] + $proposals;

    $proposalcell = local_curricmap_course_mapping_badge($result->status);
    if ($result->note) {
        $proposalcell .= ' ' . html_writer::tag('span', s($result->note), ['class' => 'small text-muted']);
    }
    $bindattrs = ['data-curricmap-row' => $courseid, 'id' => 'curricmap-bind-' . $courseid];
    $proposalcell .= html_writer::div(html_writer::select($options, "bind[$courseid]", $selected, false, $bindattrs));

    // More curriculum (ruled 2026-08-06): walk the graph when the proposals
    // carry mixed signals. Starts at the proposal's programme year when there
    // is one, else at the programme list (filtered to the harmonised year
    // when known - escapable up); pick floor is STRAND, and a pick sets this
    // row's select, so Apply reads it through the exact same path.
    $browseroot = '';
    if ($result->best) {
        if ($result->best->yeartitle === null) {
            // The proposal IS a year node - open the panel right on it.
            $browseroot = $result->best->node->uuid;
        } else {
            // A strand proposal opens at its owning year node.
            $browsekey = $result->best->programme->slug . '|' . $result->best->yearstart
                . '|' . $result->best->yeartitle;
            $browseroot = $browseyearnodes[$browsekey] ?? '';
        }
    }
    $browseattrs = ['data-curricmap-browse' => $courseid, 'data-curricmap-root' => $browseroot,
        'data-curricmap-grain' => 'course', 'data-curricmap-pickmode' => 'select'];
    if ($result->year) {
        $browseattrs['data-curricmap-year'] = $result->year;
    }
    $browselink = html_writer::link('#', get_string('contentmapping_browse', 'local_curricmap'), $browseattrs);
    $proposalcell .= html_writer::div($browselink, 'small mt-1');
    $proposalcell .= html_writer::div('', 'curricmap-browsepanel border rounded p-2 mt-1 d-none',
        ['data-curricmap-browsepanel' => $courseid]);

    $tick = html_writer::empty_tag('input', $tickattrs);
    $table->data[] = [$tick, $coursecell, $yearcell, $currentcell, $proposalcell];
}

if ($table->data) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::table($table);
    $applylabel = $mode === 'sofia'
        ? get_string('coursemapping_apply_sofia', 'local_curricmap')
        : get_string('coursemapping_apply', 'local_curricmap');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => $applylabel, 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
} else {
    echo $OUTPUT->notification(get_string('coursemapping_nocourses', 'local_curricmap'), 'info');
}

echo $OUTPUT->footer();
