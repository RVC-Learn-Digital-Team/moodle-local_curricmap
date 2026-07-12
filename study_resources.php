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
 * links...) to curriculum nodes. Node-first: pick a programme year, then a
 * strand/session/outcome, see every resource on it AND below it (a strand
 * rolls up its sessions' and outcomes' material), add or remove. Resources
 * are the node's own — they surface wherever the node is displayed, in any
 * course that binds it — which is also why rollover never touches them.
 * Bulk population (outcome -> Panopto by week) belongs to the platform
 * engine via the ws API; this page is for hand curation.
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

$slugyear = optional_param('slugyear', '', PARAM_RAW_TRIMMED);
$nodeparam = optional_param('node', '', PARAM_RAW_TRIMMED);

// Programme-year filter, as on the matching pages; the node picker needs it
// to stay a manageable list. A node arriving via cross-link sets it.
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

// The node pool for the picker: everything attachable within the slug-year.
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

$urlparams = ['slugyear' => $slugyear];
if ($node) {
    $urlparams['node'] = $node->uuid;
}
$pageurl = new moodle_url('/local/curricmap/study_resources.php', $urlparams);

/**
 * A node label: title, role, academic year from the composed key.
 *
 * @param stdClass $node Node record.
 * @return string
 */
function local_curricmap_resource_label(stdClass $node): string {
    $year = preg_match('/_(20\d\d)_\d\d_/', $node->uuid, $matches) ? ' - ' . $matches[1] : '';
    return $node->title . ' [' . $node->role . ']' . $year;
}

// Delete a resource.
$delres = optional_param('delres', 0, PARAM_INT);
if ($delres && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    resources::delete($delres);
    redirect($pageurl, get_string('studyresources_deleted', 'local_curricmap'));
}

// Add a resource to the selected node.
if (optional_param('addres', 0, PARAM_BOOL) && $node && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $type = optional_param('restype', '', PARAM_TEXT);
    $typeother = trim(optional_param('restypeother', '', PARAM_TEXT));
    if ($typeother !== '') {
        $type = $typeother;
    }
    $label = trim(optional_param('reslabel', '', PARAM_TEXT));
    $url = trim(optional_param('resurl', '', PARAM_URL));
    if ($url === '') {
        $warn = \core\output\notification::NOTIFY_WARNING;
        redirect($pageurl, get_string('studyresources_nourl', 'local_curricmap'), null, $warn);
    }
    resources::add($node->uuid, $type, $label !== '' ? $label : $url, $url);
    redirect($pageurl, get_string('studyresources_added', 'local_curricmap'));
}

$typetosearch = get_string('coursemapping_typetosearch', 'local_curricmap');
$PAGE->requires->js_call_amd('local_curricmap/course_mapping', 'init', [$typetosearch]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('studyresources', 'local_curricmap'));
echo html_writer::tag('p', get_string('studyresources_intro', 'local_curricmap'));

// Toolbar: slug-year (auto-submit) + searchable node picker.
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
$rows = $uuids ? resources::for_nodes($uuids) : [];

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    get_string('studyresources_onnode', 'local_curricmap'),
    get_string('studyresources_type', 'local_curricmap'),
    get_string('studyresources_resource', 'local_curricmap'),
    '',
];
foreach ($rows as $resource) {
    $deleteurl = new moodle_url($pageurl, ['delres' => $resource->id, 'sesskey' => sesskey()]);
    $deleteicon = $OUTPUT->pix_icon('t/delete', get_string('delete'));
    $table->data[] = [
        s($titles[$resource->nodeuuid] ?? $resource->nodeuuid),
        s($resource->type),
        html_writer::link($resource->url, s($resource->label ?: $resource->url), ['target' => '_blank']),
        html_writer::link($deleteurl, $deleteicon),
    ];
}
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('studyresources_none', 'local_curricmap'), 'info');
}

// Add form: vocabulary dropdown, custom type override, label, url.
echo $OUTPUT->heading(get_string('studyresources_add', 'local_curricmap'), 3);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false),
    'class' => 'd-flex flex-wrap align-items-center', 'style' => 'gap: 8px;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'addres', 'value' => 1]);
$typeoptions = [];
foreach (resources::suggested_types() as $suggested) {
    $typeoptions[$suggested] = $suggested;
}
$typeattrs = ['aria-label' => get_string('studyresources_type', 'local_curricmap')];
echo html_writer::select($typeoptions, 'restype', '', false, $typeattrs);
$otherattrs = ['type' => 'text', 'name' => 'restypeother', 'class' => 'form-control', 'style' => 'width: 120px;',
    'placeholder' => get_string('studyresources_typeother', 'local_curricmap')];
echo html_writer::empty_tag('input', $otherattrs);
$labelattrs = ['type' => 'text', 'name' => 'reslabel', 'class' => 'form-control', 'style' => 'width: 240px;',
    'placeholder' => get_string('studyresources_label', 'local_curricmap')];
echo html_writer::empty_tag('input', $labelattrs);
$urlattrs = ['type' => 'text', 'name' => 'resurl', 'class' => 'form-control', 'style' => 'width: 300px;',
    'placeholder' => get_string('studyresources_url', 'local_curricmap')];
echo html_writer::empty_tag('input', $urlattrs);
echo html_writer::empty_tag('input', ['type' => 'submit',
    'value' => get_string('studyresources_addbutton', 'local_curricmap'), 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
