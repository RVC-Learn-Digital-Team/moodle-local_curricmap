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
 * Compatibility route to the shared content mapper and course resource management.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_curricmap\api\bindings;
use local_curricmap\api\curriculum;
use local_curricmap\api\resources;
use local_curricmap\external\helper;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
if (!optional_param('resources', false, PARAM_BOOL)) {
    require_capability('local/curricmap:managebindings', $context);
    $params = ['courseid' => $courseid];
    if ($cmid = optional_param('cmid', 0, PARAM_INT)) {
        $params['cmid'] = $cmid;
    }
    redirect(new moodle_url('/local/curricmap/section_module_mapping.php', $params));
}
require_capability('local/curricmap:viewstaffmeta', $context);
$canresources = resources::can_manage($courseid);
$pageurl = new moodle_url('/local/curricmap/mappings.php', ['courseid' => $courseid, 'resources' => 1]);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('studyresources_forcourse', 'local_curricmap'));
$PAGE->set_heading($course->fullname);

// Course study resources: show/hide toggle. Course-scoped rows only — the
// courseid condition guarantees a teacher can never touch a global row.
$toggleres = optional_param('toggleres', 0, PARAM_INT);
if ($toggleres && $canresources && confirm_sesskey()) {
    $row = $DB->get_record(
        'local_curricmap_resource',
        ['id' => $toggleres, 'courseid' => $courseid],
        '*',
        MUST_EXIST
    );
    resources::set_visible((int) $row->id, empty($row->visible));
    redirect($pageurl, get_string('courseresources_visibilitychanged', 'local_curricmap'));
}

// Course study resources: delete, confirmed first — the resource disappears
// everywhere its node renders in this course, not just one spot.
$delres = optional_param('delres', 0, PARAM_INT);
if ($delres && $canresources) {
    $row = $DB->get_record(
        'local_curricmap_resource',
        ['id' => $delres, 'courseid' => $courseid],
        '*',
        MUST_EXIST
    );
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        resources::delete((int) $row->id);
        redirect($pageurl, get_string('courseresources_deleted', 'local_curricmap'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('courseresources_confirmdelete', 'local_curricmap', format_string($row->label)),
        new moodle_url($pageurl, ['delres' => $row->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $pageurl
    );
    echo $OUTPUT->footer();
    exit;
}

$boundnodeuuids = [];
foreach (bindings::for_course($courseid) as $binding) {
    if ($binding->status === 'active') {
        $boundnodeuuids[$binding->nodeuuid] = true;
    }
}
foreach (bindings::anchors($courseid) as $node) {
    $boundnodeuuids[$node->uuid] = true;
}
$resnodechoices = [];
foreach (array_keys($boundnodeuuids) as $uuid) {
    $node = curriculum::node($uuid);
    if ($node && !$node->deleted) {
        $exported = helper::export_nodes([$node])[0];
        $resnodechoices[$uuid] = $exported['title'] . ' — ' . ($exported['programmelabel'] ?? '');
    }
}

// Course study resources: add (course scope always — global rows are central).
if ($canresources && optional_param('addres', 0, PARAM_BOOL) && confirm_sesskey()) {
    $resnode = required_param('resnode', PARAM_ALPHANUMEXT);
    $reslabel = trim(optional_param('reslabel', '', PARAM_TEXT));
    // Trim BEFORE cleaning: PARAM_URL rejects a value with a trailing space
    // outright (copy-paste brings one for free), so trimming after the clean
    // ran was too late - 'https://google.co.uk ' arrived as ''.
    $resurl = clean_param(trim(optional_param('resurl', '', PARAM_RAW_TRIMMED)), PARAM_URL);
    $restypeother = trim(optional_param('restypeother', '', PARAM_TEXT));
    $restype = $restypeother !== '' ? $restypeother : optional_param('restype', 'link', PARAM_TEXT);
    if (!isset($resnodechoices[$resnode])) {
        throw new moodle_exception('errorbindnode', 'local_curricmap', '', s($resnode));
    }
    if ($reslabel === '' || $resurl === '') {
        redirect(
            $pageurl,
            get_string('courseresources_namerequired', 'local_curricmap'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    resources::add($resnode, $restype, $reslabel, $resurl, $courseid);
    redirect($pageurl, get_string('courseresources_added', 'local_curricmap'));
}

$courseresources = [];
if ($canresources) {
    $rows = $DB->get_records('local_curricmap_resource', ['courseid' => $courseid], 'sortorder ASC, id ASC');
    foreach ($rows as $res) {
        $resnode = curriculum::node($res->nodeuuid);
        $courseresources[] = [
            'id' => (int) $res->id,
            'nodetitle' => $resnode ? $resnode->title : $res->nodeuuid,
            'type' => $res->type,
            'label' => $res->label,
            'url' => $res->url,
            'visible' => !empty($res->visible),
            'toggleurl' => (new moodle_url(
                $pageurl,
                ['toggleres' => $res->id, 'sesskey' => sesskey()]
            ))->out(false),
            'deleteurl' => (new moodle_url($pageurl, ['delres' => $res->id]))->out(false),
        ];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('studyresources_forcourse', 'local_curricmap'));
echo html_writer::link(new moodle_url('/local/curricmap/section_module_mapping.php', ['courseid' => $courseid]),
    get_string('contentmapping', 'local_curricmap'));
if ($canresources) {
    $typeoptions = array_map(
        fn($type) => ['value' => $type, 'name' => $type],
        resources::suggested_types()
    );
    echo $OUTPUT->render_from_template('local_curricmap/course_resources', [
        'formurl' => $pageurl->out(false),
        'sesskey' => sesskey(),
        'resources' => $courseresources,
        'hasresources' => !empty($courseresources),
        'nodeoptions' => array_map(
            fn($uuid) => ['value' => $uuid, 'name' => $resnodechoices[$uuid]],
            array_keys($resnodechoices)
        ),
        'hasnodeoptions' => !empty($resnodechoices),
        'typeoptions' => $typeoptions,
    ]);
}
echo $OUTPUT->footer();
