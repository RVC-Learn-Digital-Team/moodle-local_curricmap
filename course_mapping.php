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
 * Central course matching: proposes a programme-year anchor per course from
 * idnumber/name/category signals, for an administrator to review and confirm.
 * Confirming creates central-scope anchor bindings; nothing binds unreviewed.
 * Courses without an idnumber, or below the discovery floor year, are not
 * listed (agreed scope). Deeper section/module matching is reached through
 * each course's own mappings page.
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

$search = trim(optional_param('search', '', PARAM_RAW_TRIMMED));
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;
$pageurl = new moodle_url('/local/curricmap/course_mapping.php', ['search' => $search, 'page' => $page]);

// Apply: create central-scope anchors for the reviewed selections.
$selections = optional_param_array('bind', [], PARAM_RAW_TRIMMED);
if ($selections && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $created = 0;
    foreach ($selections as $courseid => $nodeuuid) {
        if ($nodeuuid === '') {
            continue;
        }
        bindings::bind(
            ['courseid' => (int) $courseid],
            $nodeuuid,
            bindings::RELATION_ANCHOR,
            'central'
        );
        $created++;
    }
    redirect($pageurl, get_string('coursemapping_applied', 'local_curricmap', $created));
}

// Candidate courses: an idnumber is required (agreed scope rule).
$select = "c.id <> :siteid AND " . $DB->sql_isnotempty('course', 'c.idnumber', true, false);
$params = ['siteid' => SITEID];
if ($search !== '') {
    $like = [];
    foreach (['c.fullname', 'c.shortname', 'c.idnumber'] as $index => $field) {
        $like[] = $DB->sql_like($field, ':search' . $index, false);
        $params['search' . $index] = '%' . $DB->sql_like_escape($search) . '%';
    }
    $select .= ' AND (' . implode(' OR ', $like) . ')';
}
$sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber, cc.name AS categoryname
          FROM {course} c
     LEFT JOIN {course_categories} cc ON cc.id = c.category
         WHERE $select
      ORDER BY c.fullname ASC";
$courses = $DB->get_records_sql($sql, $params);

$candidates = matcher::candidates();
$rules = matcher::rules();

// Match every candidate course; skipped rows (year floor, skip patterns) are not shown.
$rows = [];
foreach ($courses as $course) {
    $result = matcher::match($course, $candidates, $rules);
    if ($result->status === matcher::STATUS_SKIPPED) {
        continue;
    }
    $rows[] = (object) ['course' => $course, 'result' => $result];
}

$total = count($rows);
$rows = array_slice($rows, $page * $perpage, $perpage);

// Existing course-level central anchors for the rows on this page, in one query.
$anchorsbycourse = [];
if ($rows) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_map(fn($r) => (int) $r->course->id, $rows), SQL_PARAMS_NAMED);
    $bindingsql = "SELECT b.id, b.courseid, b.nodeuuid, n.title, n.uuid AS nodefound
                     FROM {local_curricmap_binding} b
                LEFT JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
                    WHERE b.courseid $insql AND b.relation = :relation AND b.scope = :scope
                          AND b.status = :status AND b.sectionid IS NULL AND b.cmid IS NULL
                 ORDER BY b.sortorder ASC, b.id ASC";
    $bindingparams = $inparams + ['relation' => bindings::RELATION_ANCHOR, 'scope' => 'central', 'status' => 'active'];
    foreach ($DB->get_records_sql($bindingsql, $bindingparams) as $binding) {
        $anchorsbycourse[(int) $binding->courseid][] = $binding;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursemapping', 'local_curricmap'));
echo html_writer::tag('p', get_string('coursemapping_intro', 'local_curricmap'));

// Search box.
$searchattrs = ['type' => 'text', 'name' => 'search', 'value' => $search,
    'placeholder' => get_string('coursemapping_search', 'local_curricmap'), 'class' => 'form-control d-inline w-auto'];
$submitattrs = ['type' => 'submit', 'value' => get_string('search'), 'class' => 'btn btn-secondary'];
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $pageurl->out_omit_querystring(), 'class' => 'mb-3']);
echo html_writer::empty_tag('input', $searchattrs);
echo ' ' . html_writer::empty_tag('input', $submitattrs);
echo html_writer::end_tag('form');

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

$table = new html_table();
$table->head = [
    get_string('coursemapping_course', 'local_curricmap'),
    get_string('idnumbercourse'),
    get_string('coursemapping_year', 'local_curricmap'),
    get_string('coursemapping_anchored', 'local_curricmap'),
    get_string('coursemapping_proposal', 'local_curricmap'),
];
$table->attributes['class'] = 'generaltable';

foreach ($rows as $row) {
    $course = $row->course;
    $result = $row->result;
    $courseid = (int) $course->id;

    $coursecell = html_writer::link(new moodle_url('/course/view.php', ['id' => $courseid]), s($course->fullname))
        . html_writer::tag('div', s($course->categoryname ?? ''), ['class' => 'small text-muted'])
        . html_writer::link(
            new moodle_url('/local/curricmap/mappings.php', ['courseid' => $courseid]),
            get_string('mappings', 'local_curricmap'),
            ['class' => 'small']
        );

    $noyearlabel = get_string('coursemapping_status_noyear', 'local_curricmap');
    $yearcell = $result->year
        ? $result->year . '-' . sprintf('%02d', ($result->year + 1) % 100)
        : html_writer::tag('span', $noyearlabel, ['class' => 'badge badge-warning']);

    $anchored = [];
    foreach ($anchorsbycourse[$courseid] ?? [] as $binding) {
        $anchored[] = s($binding->title ?? $binding->nodeuuid);
    }
    $anchorcell = $anchored ? implode(html_writer::empty_tag('br'), $anchored) : '';

    // Proposal cell: a select of candidates (empty option = do nothing);
    // deterministic matches are preselected unless already anchored to that node.
    $options = ['' => get_string('coursemapping_noaction', 'local_curricmap')];
    $selected = '';
    $badge = '';
    if ($result->best) {
        $uuid = $result->best->node->uuid;
        $options[$uuid] = local_curricmap_course_mapping_label($result->best);
        $bounduuids = array_map(fn($b) => $b->nodeuuid, $anchorsbycourse[$courseid] ?? []);
        $alreadybound = in_array($uuid, $bounduuids);
        $selected = $alreadybound ? '' : $uuid;
        $badge = html_writer::tag(
            'span',
            get_string('coursemapping_status_' . $result->status, 'local_curricmap'),
            ['class' => $result->status === matcher::STATUS_MATCH ? 'badge badge-success' : 'badge badge-info']
        );
    } else if ($result->suggestions) {
        foreach ($result->suggestions as $suggestion) {
            $label = local_curricmap_course_mapping_label($suggestion->candidate);
            if ($suggestion->score > 0) {
                $label .= ' [' . $suggestion->score . ']';
            }
            $options[$suggestion->candidate->node->uuid] = $label;
        }
        $suggestlabel = get_string('coursemapping_status_suggest', 'local_curricmap');
        $badge = html_writer::tag('span', $suggestlabel, ['class' => 'badge badge-info']);
    } else {
        $statuslabel = get_string('coursemapping_status_' . $result->status, 'local_curricmap');
        $badge = html_writer::tag('span', $statuslabel, ['class' => 'badge badge-secondary']);
        if ($result->note) {
            $badge .= ' ' . html_writer::tag('span', s($result->note), ['class' => 'small text-muted']);
        }
    }
    $proposalcell = $badge;
    if (count($options) > 1) {
        $proposalcell .= ' ' . html_writer::select($options, "bind[$courseid]", $selected, false);
    }

    $table->data[] = [$coursecell, s($course->idnumber), $yearcell, $anchorcell, $proposalcell];
}

if ($table->data) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::table($table);
    echo html_writer::empty_tag('input', ['type' => 'submit',
        'value' => get_string('coursemapping_apply', 'local_curricmap'), 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
} else {
    echo $OUTPUT->notification(get_string('coursemapping_nocourses', 'local_curricmap'), 'info');
}

echo $OUTPUT->footer();
