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
 * Central course matching, in two directions: by Moodle course (review each
 * course's proposed programme-year match) or by Sofia curriculum (pick a
 * programme year, then gather the courses that belong to it — including
 * support courses without an idnumber). Ticked rows are confirmed explicitly;
 * confirming creates central-scope anchor bindings and nothing binds
 * unreviewed. Deeper section/module matching lives on each course's own
 * mappings page.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_curricmap\api\bindings;
use local_curricmap\local\matcher;

admin_externalpage_setup('local_curricmap_coursemapping');

$mode = optional_param('mode', 'course', PARAM_ALPHA);
$mode = $mode === 'sofia' ? 'sofia' : 'course';
$search = trim(optional_param('search', '', PARAM_RAW_TRIMMED));
// Support courses often have no idnumber, so the Sofia-first mode includes them by default.
$requireid = optional_param('requireid', $mode === 'sofia' ? 0 : 1, PARAM_BOOL);
$show = optional_param('show', 'matched', PARAM_ALPHA);
$nodeparam = optional_param('node', '', PARAM_RAW_TRIMMED);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$candidates = matcher::candidates();
$rules = matcher::rules();

// The Sofia-first target node, defaulting to the first synced programme year.
$target = null;
foreach ($candidates as $candidate) {
    if ($candidate->node->uuid === $nodeparam) {
        $target = $candidate;
    }
}
if ($mode === 'sofia' && $target === null && $candidates) {
    $target = $candidates[0];
}

$urlparams = ['mode' => $mode, 'search' => $search, 'requireid' => $requireid, 'show' => $show, 'page' => $page];
if ($target) {
    $urlparams['node'] = $target->node->uuid;
}
$pageurl = new moodle_url('/local/curricmap/course_mapping.php', $urlparams);

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
 * A candidate's display label: programme, node title, academic year.
 *
 * @param stdClass $candidate Matcher candidate.
 * @return string
 */
function local_curricmap_course_mapping_label(stdClass $candidate): string {
    $programme = $candidate->programme->displayname ?: $candidate->programme->slug;
    $year = $candidate->yearstart . '-' . sprintf('%02d', ($candidate->yearstart + 1) % 100);
    return $programme . ' / ' . $candidate->node->title . ' (' . $year . ')';
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
$sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber, cc.name AS categoryname
          FROM {course} c
     LEFT JOIN {course_categories} cc ON cc.id = c.category
         WHERE c.id <> :siteid
      ORDER BY c.fullname ASC";
$courses = $DB->get_records_sql($sql, ['siteid' => SITEID]);

// Existing course-level central matches, one query for the whole estate.
$currentmatches = [];
$matchsql = "SELECT b.id, b.courseid, b.nodeuuid, n.title
               FROM {local_curricmap_binding} b
          LEFT JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
              WHERE b.relation = :relation AND b.scope = :scope AND b.status = :status
                    AND b.courseid IS NOT NULL AND b.sectionid IS NULL AND b.cmid IS NULL
           ORDER BY b.sortorder ASC, b.id ASC";
$matchparams = ['relation' => bindings::RELATION_ANCHOR, 'scope' => 'central', 'status' => 'active'];
foreach ($DB->get_records_sql($matchsql, $matchparams) as $binding) {
    $currentmatches[(int) $binding->courseid][] = $binding;
}

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
    $rows[] = (object) ['course' => $course, 'result' => $result];
}

if ($mode === 'course') {
    $rows = array_values(array_filter($rows, function ($row) use ($show, $currentmatches) {
        if ($show === 'all') {
            return true;
        }
        if ($row->result->status === matcher::STATUS_SKIPPED) {
            return false;
        }
        $unmatched = [matcher::STATUS_NOCOVERAGE, matcher::STATUS_NOYEAR, matcher::STATUS_NOMATCH];
        switch ($show) {
            case 'unmatched':
                return in_array($row->result->status, $unmatched);
            case 'existing':
                return !empty($currentmatches[(int) $row->course->id]);
            default:
                return in_array($row->result->status, [matcher::STATUS_MATCH, matcher::STATUS_SUGGEST]);
        }
    }));
} else if ($target) {
    // Rank each course's fit for the target node: already matched to it,
    // proposed for it, suggested for it (scored), or a plain search result.
    $ranked = [];
    foreach ($rows as $row) {
        $courseid = (int) $row->course->id;
        $bound = array_map(fn($b) => $b->nodeuuid, $currentmatches[$courseid] ?? []);
        if (in_array($target->node->uuid, $bound)) {
            $row->fit = 'existing';
            $row->rank = 1;
        } else if ($row->result->best && $row->result->best->node->uuid === $target->node->uuid) {
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
            } else if ($keywords || $searchyear !== null) {
                $row->fit = 'searchresult';
                $row->rank = 4;
            } else {
                continue;
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

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursemapping', 'local_curricmap'));
echo html_writer::tag('p', get_string('coursemapping_intro', 'local_curricmap'));

// Toolbar: search + idnumber toggle (GET form), then auto-submitting selects.
$formurl = new moodle_url('/local/curricmap/course_mapping.php');
echo html_writer::start_div('d-flex flex-wrap align-items-center mb-3', ['style' => 'gap: 8px;']);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out_omit_querystring(),
    'class' => 'd-flex align-items-center', 'style' => 'gap: 8px;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mode', 'value' => $mode]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'show', 'value' => $show]);
if ($target) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'node', 'value' => $target->node->uuid]);
}
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'requireid', 'value' => 0]);
$searchattrs = ['type' => 'text', 'name' => 'search', 'value' => $search,
    'placeholder' => get_string('coursemapping_search', 'local_curricmap'), 'class' => 'form-control',
    'style' => 'width: 240px;'];
echo html_writer::empty_tag('input', $searchattrs);
$checkboxattrs = ['type' => 'checkbox', 'name' => 'requireid', 'value' => 1, 'class' => 'mr-1'];
if ($requireid) {
    $checkboxattrs['checked'] = 'checked';
}
echo html_writer::start_tag('label', ['class' => 'mb-0 d-flex align-items-center text-nowrap']);
echo html_writer::empty_tag('input', $checkboxattrs);
echo get_string('coursemapping_onlyidnumber', 'local_curricmap');
echo html_writer::end_tag('label');
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if ($mode === 'course') {
    $showoptions = [
        'matched' => get_string('coursemapping_show_matched', 'local_curricmap'),
        'unmatched' => get_string('coursemapping_show_unmatched', 'local_curricmap'),
        'existing' => get_string('coursemapping_show_existing', 'local_curricmap'),
        'all' => get_string('coursemapping_show_all', 'local_curricmap'),
    ];
    $showurl = new moodle_url($pageurl, ['page' => 0]);
    $showurl->remove_params(['show']);
    $showselect = new single_select($showurl, 'show', $showoptions, $show, null);
    $showselect->set_label(get_string('coursemapping_show', 'local_curricmap'), ['class' => 'sr-only']);
    echo $OUTPUT->render($showselect);
}

$modeoptions = [
    'course' => get_string('coursemapping_mode_course', 'local_curricmap'),
    'sofia' => get_string('coursemapping_mode_sofia', 'local_curricmap'),
];
$modeurl = new moodle_url($pageurl, ['page' => 0]);
$modeurl->remove_params(['mode']);
$modeselect = new single_select($modeurl, 'mode', $modeoptions, $mode, null);
$modeselect->set_label(get_string('coursemapping_mode', 'local_curricmap'), ['class' => 'sr-only']);
echo $OUTPUT->render($modeselect);
echo html_writer::end_div();

if ($mode === 'sofia') {
    if (!$target) {
        echo $OUTPUT->notification(get_string('coursemapping_nonodes', 'local_curricmap'), 'info');
        echo $OUTPUT->footer();
        exit;
    }
    $nodeoptions = [];
    foreach ($candidates as $candidate) {
        $nodeoptions[$candidate->node->uuid] = local_curricmap_course_mapping_label($candidate);
    }
    $nodeurl = new moodle_url($pageurl, ['page' => 0]);
    $nodeurl->remove_params(['node']);
    $nodeselect = new single_select($nodeurl, 'node', $nodeoptions, $target->node->uuid, null);
    $nodeselect->set_label(get_string('coursemapping_sofianode', 'local_curricmap'));
    echo html_writer::div($OUTPUT->render($nodeselect), 'mb-3');
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
        new moodle_url('/local/curricmap/mappings.php', ['courseid' => $courseid]),
        get_string('mappings', 'local_curricmap'),
        ['class' => 'small']
    );

    $yearcell = $result->year
        ? $result->year . '-' . sprintf('%02d', ($result->year + 1) % 100)
        : local_curricmap_course_mapping_badge(matcher::STATUS_NOYEAR);

    $currentcell = implode(html_writer::empty_tag('br'), array_map(
        fn($binding) => s($binding->title ?? $binding->nodeuuid),
        $currentmatches[$courseid] ?? []
    ));

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
            $fitcell = local_curricmap_course_mapping_badge($row->fit);
            if (($row->score ?? 0) > 0) {
                $fitcell .= ' ' . html_writer::tag('span', '[' . $row->score . ']', ['class' => 'small text-muted']);
            }
        }
        $table->data[] = [$tick, $coursecell, $yearcell, $currentcell, $fitcell];
        continue;
    }

    // Course mode: proposal dropdown — proposals on top, all programme years below.
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
    $allyears = [];
    foreach ($candidates as $candidate) {
        if ($searchyear !== null && $candidate->yearstart !== $searchyear) {
            continue;
        }
        if (!isset($proposals[$candidate->node->uuid])) {
            $allyears[$candidate->node->uuid] = local_curricmap_course_mapping_label($candidate);
        }
    }
    $options = ['' => get_string('coursemapping_noaction', 'local_curricmap')] + $proposals;
    if ($allyears) {
        $options[] = [get_string('coursemapping_allyears', 'local_curricmap') => $allyears];
    }

    $proposalcell = local_curricmap_course_mapping_badge($result->status);
    if ($result->note) {
        $proposalcell .= ' ' . html_writer::tag('span', s($result->note), ['class' => 'small text-muted']);
    }
    $proposalcell .= html_writer::div(html_writer::select($options, "bind[$courseid]", $selected, false));

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
