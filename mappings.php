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
 * Per-course curriculum mappings: what this course (and its sections and
 * activities) is bound to, grouped by location, with scope badges. Central
 * and category-level rows are locked for course staff.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_curricmap\api\bindings;
use local_curricmap\api\curriculum;
use local_curricmap\external\helper;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/curricmap:viewstaffmeta', $context);

$canmanage = has_capability('local/curricmap:managebindings', $context);
$cancentral = has_capability('local/curricmap:managebindings', context_system::instance());

$pageurl = new moodle_url('/local/curricmap/mappings.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('mappings', 'local_curricmap'));
$PAGE->set_heading($course->fullname);

// Delete a binding. Central rows need the system capability, course-scope
// rows the course one.
$unbind = optional_param('unbind', 0, PARAM_INT);
if ($unbind && confirm_sesskey()) {
    $binding = $DB->get_record('local_curricmap_binding', ['id' => $unbind], '*', MUST_EXIST);
    $iscourserow = (int) ($binding->courseid ?? 0) === $courseid && $binding->scope !== 'central';
    if (($iscourserow && $canmanage) || $cancentral) {
        bindings::unbind((int) $binding->id);
        redirect($pageurl, get_string('mappings_deleted', 'local_curricmap'));
    }
    throw new required_capability_exception($context, 'local/curricmap:managebindings', 'nopermissions', '');
}

// Add a binding.
$form = null;
if ($canmanage) {
    $form = new \local_curricmap\form\binding_form(
        $pageurl,
        ['course' => $course, 'cancentral' => $cancentral]
    );
    if ($data = $form->get_data()) {
        $address = ['courseid' => $courseid];
        if (strpos($data->location, 'section:') === 0) {
            $address['sectionid'] = (int) substr($data->location, 8);
        } else if (strpos($data->location, 'cm:') === 0) {
            $address['cmid'] = (int) substr($data->location, 3);
        }
        $scope = ($cancentral && $data->scope === 'central') ? 'central' : 'course';
        bindings::bind($address, $data->nodeuuid, $data->relation, $scope);
        redirect($pageurl, get_string('mappings_added', 'local_curricmap'));
    }
}

// Assemble the display model: rows grouped by location, deepest last.
$modinfo = get_fast_modinfo($course);
$sectionnames = [];
foreach ($modinfo->get_section_info_all() as $section) {
    $sectionnames[(int) $section->id] = get_section_name($course, $section);
}
$cmnames = [];
foreach ($modinfo->cms as $cm) {
    $cmnames[(int) $cm->id] = $cm->get_formatted_name();
}

/**
 * Export one binding row for the template.
 *
 * @param stdClass $binding Binding record.
 * @param bool $candelete Whether to offer a delete action.
 * @param moodle_url $pageurl The page url for the delete action.
 * @return array
 */
function local_curricmap_mappings_row(stdClass $binding, bool $candelete, moodle_url $pageurl): array {
    $node = curriculum::node($binding->nodeuuid);
    $exported = $node ? helper::export_nodes([$node])[0] : null;
    return [
        'id' => (int) $binding->id,
        'nodetitle' => $exported['title'] ?? $binding->nodeuuid,
        'nodecode' => $exported['code'] ?? '',
        'noderole' => $exported['role'] ?? '',
        'programmelabel' => $exported['programmelabel'] ?? '',
        'relation' => $binding->relation,
        'isanchor' => $binding->relation === bindings::RELATION_ANCHOR,
        'iscentral' => $binding->scope === 'central',
        'candelete' => $candelete,
        'deleteurl' => (new moodle_url($pageurl, ['unbind' => $binding->id, 'sesskey' => sesskey()]))->out(false),
    ];
}

$groups = [];

// Inherited category-level rows, outermost first, read-only for course staff.
$category = core_course_category::get($course->category, IGNORE_MISSING, true);
if ($category) {
    foreach (array_merge($category->get_parents(), [$category->id]) as $categoryid) {
        $rows = $DB->get_records(
            'local_curricmap_binding',
            ['categoryid' => $categoryid, 'status' => 'active'],
            'sortorder ASC, id ASC'
        );
        if (!$rows) {
            continue;
        }
        $categoryname = core_course_category::get($categoryid, IGNORE_MISSING, true);
        $groups[] = [
            'heading' => get_string(
                'mappings_inheritedfrom',
                'local_curricmap',
                $categoryname ? $categoryname->get_formatted_name() : $categoryid
            ),
            'inherited' => true,
            'rows' => array_map(fn($b) => local_curricmap_mappings_row($b, $cancentral, $pageurl), $rows),
        ];
    }
}

// This course's own rows, grouped whole-course, then per section, per activity.
$own = bindings::for_course($courseid);
$active = array_filter($own, fn($b) => $b->status === 'active');
$orphanedrows = bindings::orphaned($courseid);

$bucketed = ['course' => [], 'section' => [], 'cm' => []];
foreach ($active as $binding) {
    if (!empty($binding->cmid)) {
        $bucketed['cm'][(int) $binding->cmid][] = $binding;
    } else if (!empty($binding->sectionid)) {
        $bucketed['section'][(int) $binding->sectionid][] = $binding;
    } else {
        $bucketed['course'][0][] = $binding;
    }
}

$candeleterow = function (stdClass $binding) use ($canmanage, $cancentral): bool {
    return $binding->scope === 'central' ? $cancentral : $canmanage;
};

if (!empty($bucketed['course'][0])) {
    $groups[] = [
        'heading' => get_string('mappings_location_course', 'local_curricmap'),
        'inherited' => false,
        'rows' => array_map(
            fn($b) => local_curricmap_mappings_row($b, $candeleterow($b), $pageurl),
            $bucketed['course'][0]
        ),
    ];
}
foreach ($bucketed['section'] as $sectionid => $rows) {
    $groups[] = [
        'heading' => get_string(
            'mappings_location_section',
            'local_curricmap',
            $sectionnames[$sectionid] ?? $sectionid
        ),
        'inherited' => false,
        'rows' => array_map(fn($b) => local_curricmap_mappings_row($b, $candeleterow($b), $pageurl), $rows),
    ];
}
foreach ($bucketed['cm'] as $cmid => $rows) {
    $groups[] = [
        'heading' => get_string('mappings_location_activity', 'local_curricmap', $cmnames[$cmid] ?? $cmid),
        'inherited' => false,
        'rows' => array_map(fn($b) => local_curricmap_mappings_row($b, $candeleterow($b), $pageurl), $rows),
    ];
}

// Effective anchors (what the presenter will treat as the course's default scope).
$anchors = [];
foreach (bindings::anchors($courseid) as $node) {
    $exported = helper::export_nodes([$node])[0];
    $anchors[] = [
        'nodetitle' => $exported['title'],
        'nodecode' => $exported['code'] ?? '',
        'programmelabel' => $exported['programmelabel'] ?? '',
    ];
}

$templatecontext = [
    'anchors' => $anchors,
    'hasanchors' => !empty($anchors),
    'groups' => $groups,
    'hasgroups' => !empty($groups),
    'orphaned' => array_map(
        fn($b) => local_curricmap_mappings_row($b, $canmanage || $cancentral, $pageurl),
        $orphanedrows
    ),
    'hasorphaned' => !empty($orphanedrows),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mappings', 'local_curricmap'));
echo $OUTPUT->render_from_template('local_curricmap/mappings', $templatecontext);
if ($form) {
    echo $OUTPUT->heading(get_string('mappings_addmapping', 'local_curricmap'), 3);
    $form->display();
}
echo $OUTPUT->footer();
