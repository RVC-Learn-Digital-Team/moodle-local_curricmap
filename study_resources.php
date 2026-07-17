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
 * Study resources: attach learning material (Panopto recordings, ebooks,
 * links...) to curriculum nodes.
 *
 * Course-first (the main workflow): pick a course, see every one of its
 * central mappings — whole course, sections, activities — each with the
 * node's existing resources and an inline add form, and work down the list.
 * Node-first (curriculum-wide curation): pick a programme year and a node,
 * see everything on it and below it.
 *
 * Resources are the node's own — they surface wherever the node is
 * displayed, in any course and year that binds it, and rollover never
 * touches them. Bulk population (outcome -> Panopto by week) belongs to the
 * platform engine via the ws API; this page is for hand curation.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_curricmap\api\curriculum;
use local_curricmap\api\resources;
use local_curricmap\local\matcher;

admin_externalpage_setup('local_curricmap_studyresources');

$courseid = optional_param('courseid', 0, PARAM_INT);
$slugyear = optional_param('slugyear', '', PARAM_RAW_TRIMMED);
$nodeparam = optional_param('node', '', PARAM_RAW_TRIMMED);
$coursesearch = trim(optional_param('coursesearch', '', PARAM_RAW_TRIMMED));

$urlparams = [];
if ($courseid) {
    $urlparams['courseid'] = $courseid;
} else {
    $urlparams['slugyear'] = $slugyear;
    if ($nodeparam !== '') {
        $urlparams['node'] = $nodeparam;
    }
}
$pageurl = new moodle_url('/local/curricmap/study_resources.php', $urlparams);

/**
 * A node label: title, role, academic year from the composed key.
 *
 * @param stdClass $node Node record (title, role, uuid).
 * @return string
 */
function local_curricmap_resource_label(stdClass $node): string {
    $year = preg_match('/_(20\d\d)_\d\d_/', $node->uuid, $matches) ? ' - ' . $matches[1] : '';
    return $node->title . ' [' . $node->role . ']' . $year;
}

/**
 * The resources cell for one node: labelled links with delete icons.
 *
 * @param array $noderesources Resource records for the node.
 * @param moodle_url $pageurl Page url for the delete action.
 * @return string HTML.
 */
function local_curricmap_resource_list(array $noderesources, moodle_url $pageurl): string {
    global $OUTPUT;
    $entries = [];
    foreach ($noderesources as $resource) {
        $deleteurl = new moodle_url($pageurl, ['delres' => $resource->id, 'sesskey' => sesskey()]);
        $deleteicon = $OUTPUT->pix_icon('t/delete', get_string('delete'));
        $text = s($resource->label ?: $resource->url) . ' ';
        $text .= html_writer::tag('span', '(' . s($resource->type) . ')', ['class' => 'small text-muted']);
        $entries[] = html_writer::link($resource->url, $text, ['target' => '_blank'])
            . ' ' . html_writer::link($deleteurl, $deleteicon);
    }
    return implode(html_writer::empty_tag('br'), $entries);
}

/**
 * An inline add-resource form for one node.
 *
 * @param string $nodeuuid Composed node key.
 * @param moodle_url $pageurl Page url to post back to.
 * @return string HTML.
 */
function local_curricmap_resource_addform(string $nodeuuid, moodle_url $pageurl): string {
    $out = html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false),
        'class' => 'd-flex flex-wrap align-items-center', 'style' => 'gap: 4px;']);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'addres', 'value' => 1]);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'resnode', 'value' => $nodeuuid]);
    $typeoptions = [];
    foreach (resources::suggested_types() as $suggested) {
        $typeoptions[$suggested] = $suggested;
    }
    $typeattrs = ['aria-label' => get_string('studyresources_type', 'local_curricmap')];
    $out .= html_writer::select($typeoptions, 'restype', '', false, $typeattrs);
    $out .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'restypeother',
        'class' => 'form-control form-control-sm', 'style' => 'width: 90px;',
        'placeholder' => get_string('studyresources_typeother', 'local_curricmap')]);
    $out .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reslabel',
        'class' => 'form-control form-control-sm', 'style' => 'width: 150px;',
        'placeholder' => get_string('studyresources_label_short', 'local_curricmap')]);
    $out .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'resurl',
        'class' => 'form-control form-control-sm', 'style' => 'width: 200px;',
        'placeholder' => get_string('studyresources_url', 'local_curricmap')]);
    $out .= html_writer::empty_tag('input', ['type' => 'submit',
        'value' => get_string('studyresources_addbutton', 'local_curricmap'), 'class' => 'btn btn-sm btn-secondary']);
    $out .= html_writer::end_tag('form');
    return $out;
}

// Delete a resource, confirmed (it disappears everywhere its node renders).
$delres = optional_param('delres', 0, PARAM_INT);
if ($delres && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    if (!optional_param('confirm', 0, PARAM_BOOL)) {
        $row = $DB->get_record('local_curricmap_resource', ['id' => $delres], '*', MUST_EXIST);
        $confirmurl = new moodle_url($pageurl, ['delres' => $delres, 'confirm' => 1, 'sesskey' => sesskey()]);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('courseresources_confirmdelete', 'local_curricmap', format_string($row->label)),
            $confirmurl,
            $pageurl
        );
        echo $OUTPUT->footer();
        exit;
    }
    resources::delete($delres);
    redirect($pageurl, get_string('studyresources_deleted', 'local_curricmap'));
}

// Add a resource: the target node arrives from the inline form (course view)
// or the node picker (node view).
if (optional_param('addres', 0, PARAM_BOOL) && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $resnode = optional_param('resnode', '', PARAM_RAW_TRIMMED);
    $targetnode = $resnode !== '' ? curriculum::node($resnode) : null;
    $type = optional_param('restype', '', PARAM_TEXT);
    $typeother = trim(optional_param('restypeother', '', PARAM_TEXT));
    if ($typeother !== '') {
        $type = $typeother;
    }
    $label = trim(optional_param('reslabel', '', PARAM_TEXT));
    $url = trim(optional_param('resurl', '', PARAM_URL));
    if (!$targetnode || $url === '') {
        $warn = \core\output\notification::NOTIFY_WARNING;
        redirect($pageurl, get_string('studyresources_nourl', 'local_curricmap'), null, $warn);
    }
    resources::add($targetnode->uuid, $type, $label !== '' ? $label : $url, $url);
    redirect($pageurl, get_string('studyresources_added', 'local_curricmap'));
}

$typetosearch = get_string('coursemapping_typetosearch', 'local_curricmap');
$PAGE->requires->js_call_amd('local_curricmap/course_mapping', 'init', [$typetosearch]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('studyresources', 'local_curricmap'));

// Course finder results (either view).
if ($coursesearch !== '') {
    $like = [];
    $params = ['siteid' => SITEID];
    foreach (['c.fullname', 'c.shortname', 'c.idnumber'] as $index => $field) {
        $like[] = $DB->sql_like($field, ':search' . $index, false);
        $params['search' . $index] = '%' . $DB->sql_like_escape($coursesearch) . '%';
    }
    $sql = "SELECT c.id, c.fullname, c.idnumber FROM {course} c
             WHERE c.id <> :siteid AND (" . implode(' OR ', $like) . ")
          ORDER BY c.fullname ASC";
    foreach ($DB->get_records_sql($sql, $params, 0, 20) as $found) {
        $url = new moodle_url('/local/curricmap/study_resources.php', ['courseid' => $found->id]);
        $label = $found->fullname . ($found->idnumber ? ' (' . $found->idnumber . ')' : '');
        echo html_writer::div(html_writer::link($url, s($label)));
    }
}

if ($courseid) {
    // Course view: every central mapping with inline resource management.
    $course = get_course($courseid);
    echo html_writer::tag('p', s($course->fullname), ['class' => 'lead']);
    echo html_writer::tag('p', get_string('studyresources_courseintro', 'local_curricmap'), ['class' => 'text-muted']);

    // Toolbar: switch course, jump back to content mapping, node-first link.
    echo html_writer::start_div('d-flex flex-wrap align-items-center mb-3', ['style' => 'gap: 8px;']);
    $formurl = new moodle_url('/local/curricmap/study_resources.php');
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out_omit_querystring(),
        'class' => 'd-flex align-items-center', 'style' => 'gap: 8px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'coursesearch', 'value' => '',
        'placeholder' => get_string('contentmapping_coursesearch', 'local_curricmap'), 'class' => 'form-control',
        'style' => 'width: 240px;']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'),
        'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    $contenturl = new moodle_url('/local/curricmap/section_module_mapping.php', ['courseid' => $courseid]);
    echo html_writer::link($contenturl, get_string('contentmapping_link', 'local_curricmap'));
    $nodeviewurl = new moodle_url('/local/curricmap/study_resources.php');
    echo html_writer::link($nodeviewurl, get_string('studyresources_bynode', 'local_curricmap'));
    echo html_writer::end_div();

    // All central mappings, bucketed course / section / activity.
    $bindingsql = "SELECT b.id, b.sectionid, b.cmid, b.nodeuuid, n.title, n.role
                     FROM {local_curricmap_binding} b
                LEFT JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
                    WHERE b.courseid = :courseid AND b.scope = :scope AND b.status = :status
                 ORDER BY b.sortorder ASC, b.id ASC";
    $bindingparams = ['courseid' => $courseid, 'scope' => 'central', 'status' => 'active'];
    $bindings = $DB->get_records_sql($bindingsql, $bindingparams);

    if (!$bindings) {
        $matchurl = new moodle_url('/local/curricmap/course_mapping.php', ['search' => $course->shortname]);
        echo $OUTPUT->notification(
            get_string('contentmapping_notmatched', 'local_curricmap', $matchurl->out(false)),
            'warning',
            false
        );
        echo $OUTPUT->footer();
        exit;
    }

    // All resources for the bound nodes, one query, grouped by node.
    $bynode = [];
    $uuids = array_values(array_unique(array_map(fn($b) => $b->nodeuuid, $bindings)));
    foreach (resources::for_nodes($uuids, null, true) as $resource) {
        $bynode[$resource->nodeuuid][] = $resource;
    }

    $modinfo = get_fast_modinfo($course);
    $sectionnames = [];
    foreach ($modinfo->get_section_info_all() as $section) {
        $sectionnames[(int) $section->id] = get_section_name($course, $section);
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [
        get_string('mappings_location', 'local_curricmap'),
        get_string('studyresources_onnode', 'local_curricmap'),
        get_string('studyresources', 'local_curricmap'),
        get_string('studyresources_add', 'local_curricmap'),
    ];
    foreach ($bindings as $binding) {
        if ($binding->cmid && isset($modinfo->cms[(int) $binding->cmid])) {
            $location = $modinfo->cms[(int) $binding->cmid]->get_formatted_name();
        } else if ($binding->sectionid) {
            $fallback = get_string('mappings_location_section', 'local_curricmap', $binding->sectionid);
            $location = $sectionnames[(int) $binding->sectionid] ?? $fallback;
        } else {
            $location = get_string('mappings_location_course', 'local_curricmap');
        }
        $nodestub = (object) ['title' => $binding->title ?? $binding->nodeuuid,
            'role' => $binding->role ?? '', 'uuid' => $binding->nodeuuid];
        $table->data[] = [
            s($location),
            s(local_curricmap_resource_label($nodestub)),
            local_curricmap_resource_list($bynode[$binding->nodeuuid] ?? [], $pageurl),
            local_curricmap_resource_addform($binding->nodeuuid, $pageurl),
        ];
    }
    echo html_writer::table($table);
    echo $OUTPUT->footer();
    exit;
}

// Node view: curriculum-wide curation with subtree roll-up.
echo html_writer::tag('p', get_string('studyresources_intro', 'local_curricmap'));

$slugyears = [];
foreach (matcher::candidates() as $candidate) {
    $key = $candidate->programme->slug . ':' . $candidate->yearstart;
    $yearlabel = $candidate->yearstart . '-' . sprintf('%02d', ($candidate->yearstart + 1) % 100);
    $slugyears[$key] = $candidate->programme->slug . ' ' . $yearlabel;
}
ksort($slugyears);
if ($nodeparam !== '' && preg_match('/^(.+)_(20\d\d)_\d\d_/', $nodeparam, $matches)) {
    $derived = $matches[1] . ':' . $matches[2];
    if (isset($slugyears[$derived])) {
        $slugyear = $derived;
    }
}
if (!isset($slugyears[$slugyear])) {
    $slugyear = array_key_first($slugyears) ?? '';
}

$pool = [];
if ($slugyear !== '') {
    $yearroots = [];
    foreach (matcher::candidates() as $candidate) {
        if ($candidate->programme->slug . ':' . $candidate->yearstart === $slugyear) {
            $yearroots[] = $candidate->node->uuid;
        }
    }
    $poolroles = ['strand', 'session', 'strandoutcome', 'sessionoutcome', 'assessment'];
    $pool = matcher::content_candidates($yearroots, $poolroles);
}
$node = null;
foreach ($pool as $candidate) {
    if ($candidate->node->uuid === $nodeparam) {
        $node = $candidate->node;
    }
}

// Toolbar: slug-year (auto-submit), searchable node picker, course finder.
$formurl = new moodle_url('/local/curricmap/study_resources.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out_omit_querystring(),
    'class' => 'local-curricmap-filterform d-flex flex-wrap align-items-center mb-3', 'style' => 'gap: 8px;']);
$syattrs = ['aria-label' => get_string('coursemapping_slugyear', 'local_curricmap')];
echo html_writer::select($slugyears, 'slugyear', $slugyear, false, $syattrs);
$nodeoptions = [];
foreach ($pool as $candidate) {
    $nodeoptions[$candidate->node->uuid] = local_curricmap_resource_label($candidate->node);
}
if ($nodeoptions) {
    $nodeattrs = ['aria-label' => get_string('studyresources_node', 'local_curricmap'),
        'id' => 'curricmap-node', 'data-curricmap-node' => 1];
    echo html_writer::select($nodeoptions, 'node', $node->uuid ?? '', false, $nodeattrs);
}
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'coursesearch', 'value' => '',
    'placeholder' => get_string('studyresources_bycourse', 'local_curricmap'), 'class' => 'form-control',
    'style' => 'width: 220px;']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('go'),
    'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if (!$node) {
    echo $OUTPUT->notification(get_string('studyresources_pick', 'local_curricmap'), 'info');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', s(local_curricmap_resource_label($node)), ['class' => 'lead']);

// Roll-up: the node's own resources plus everything below it.
$subtree = curriculum::subtree($node->uuid);
$titles = [];
$uuids = [];
foreach ($subtree as $subnode) {
    $uuids[] = $subnode->uuid;
    $titles[$subnode->uuid] = $subnode->title;
}
$byrollup = [];
foreach ($uuids ? resources::for_nodes($uuids, null, true) : [] as $resource) {
    $byrollup[$resource->nodeuuid][] = $resource;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    get_string('studyresources_onnode', 'local_curricmap'),
    get_string('studyresources', 'local_curricmap'),
];
foreach ($byrollup as $uuid => $noderesources) {
    $table->data[] = [
        s($titles[$uuid] ?? $uuid),
        local_curricmap_resource_list($noderesources, $pageurl),
    ];
}
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('studyresources_none', 'local_curricmap'), 'info');
}

echo $OUTPUT->heading(get_string('studyresources_add', 'local_curricmap'), 3);
echo local_curricmap_resource_addform($node->uuid, $pageurl);

echo $OUTPUT->footer();
