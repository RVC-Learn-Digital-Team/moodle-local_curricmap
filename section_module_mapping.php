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
 * Shared course-content mapper for authorised course and site administrators.
 * Sections load their ordered children on demand; descriptions and book chapters
 * stay alongside mapping controls. Course affiliation is managed separately.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_curricmap\api\bindings;
use local_curricmap\local\contentmap;
use local_curricmap\local\content_tree;
use local_curricmap\local\matcher;

$courseid = optional_param('courseid', 0, PARAM_INT);
if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('local/curricmap:managebindings', $context);
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_title(get_string('contentmapping', 'local_curricmap'));
    $PAGE->set_heading($course->fullname);
} else {
    admin_externalpage_setup('local_curricmap_contentmapping');
    $context = context_system::instance();
}
$cancentral = has_capability('local/curricmap:managebindings', context_system::instance())
    && (!$courseid || !is_role_switched($courseid));
$focuscm = optional_param('cmid', 0, PARAM_INT);
$bookcm = optional_param('bookcm', 0, PARAM_INT);
$coursesearch = $cancentral ? trim(optional_param('coursesearch', '', PARAM_RAW_TRIMMED)) : '';
$focuscm = $focuscm ?: $bookcm;

// Multi-select filters arrive as arrays from the toolbar form and travel as
// csv in links (moodle_url cannot carry array params).
$sectionids = optional_param_array('sectionsel', null, PARAM_INT);
if ($sectionids === null) {
    $sectionids = array_filter(array_map('intval', explode(',', optional_param('sections', '', PARAM_SEQUENCE))));
}
$sectionids = array_values(array_filter($sectionids));
$typesraw = optional_param_array('typesel', null, PARAM_PLUGIN);
if ($typesraw === null) {
    $typesraw = explode(',', optional_param('types', '', PARAM_RAW_TRIMMED));
}
$modtypesfilter = [];
foreach ($typesraw as $type) {
    $clean = clean_param($type, PARAM_PLUGIN);
    if ($clean !== '') {
        $modtypesfilter[] = $clean;
    }
}
$ntraw = optional_param_array('ntypesel', null, PARAM_TEXT);
if ($ntraw === null) {
    $ntraw = explode(',', optional_param('ntypes', '', PARAM_RAW_TRIMMED));
}
$nodetypesfilter = [];
foreach ($ntraw as $ntype) {
    $clean = trim(clean_param($ntype, PARAM_TEXT));
    if ($clean !== '') {
        $nodetypesfilter[] = $clean;
    }
}

$urlparams = ['courseid' => $courseid];
if ($sectionids) {
    $urlparams['sections'] = implode(',', $sectionids);
}
if ($modtypesfilter) {
    $urlparams['types'] = implode(',', $modtypesfilter);
}
if ($nodetypesfilter) {
    $urlparams['ntypes'] = implode(',', $nodetypesfilter);
}
if ($bookcm) {
    $urlparams['bookcm'] = $bookcm;
    $pendingparam = optional_param('pending', '', PARAM_RAW_TRIMMED);
    if ($pendingparam !== '') {
        $urlparams['pending'] = $pendingparam;
    }
}
$pageurl = new moodle_url('/local/curricmap/section_module_mapping.php', $urlparams);
$PAGE->set_url($pageurl);

// Remove one content match, regardless of the authoring scope.
$unbind = optional_param('unbind', 0, PARAM_INT);
if ($unbind && $courseid && confirm_sesskey()) {
    $binding = $DB->get_record('local_curricmap_binding', ['id' => $unbind, 'courseid' => $courseid], '*', MUST_EXIST);
    if (($binding->sectionid || $binding->cmid)
            && bindings::can_manage((array) $binding, $binding->scope, null, $binding->relation)) {
        if (!optional_param('confirm', 0, PARAM_BOOL)) {
            $node = \local_curricmap\api\curriculum::node($binding->nodeuuid);
            $confirmurl = new moodle_url($pageurl, ['unbind' => $unbind, 'confirm' => 1, 'sesskey' => sesskey()]);
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(
                get_string('mappings_confirmunbind', 'local_curricmap', s($node->title ?? $binding->nodeuuid)),
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

$typetosearch = get_string('coursemapping_typetosearch', 'local_curricmap');
$PAGE->requires->js_call_amd('local_curricmap/content_mapping', 'init', [$context->id, $focuscm]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('contentmapping', 'local_curricmap'));

// Course finder: shown when no course is selected, or when searching to switch.
if (!$courseid || $coursesearch !== '') {
    if (!$courseid) {
        echo html_writer::tag('p', get_string('contentmapping_intro', 'local_curricmap'));
    }
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
            $url = new moodle_url('/local/curricmap/section_module_mapping.php', ['courseid' => $found->id]);
            $label = $found->fullname . ($found->idnumber ? ' (' . $found->idnumber . ')' : '');
            echo html_writer::div(html_writer::link($url, s($label)));
        }
    }
}
if (!$courseid) {
    $findurl = new moodle_url('/local/curricmap/section_module_mapping.php');
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $findurl->out_omit_querystring(),
        'class' => 'd-flex align-items-center', 'style' => 'gap: 8px;']);
    $findattrs = ['type' => 'text', 'name' => 'coursesearch', 'value' => $coursesearch,
        'placeholder' => get_string('contentmapping_coursesearch', 'local_curricmap'), 'class' => 'form-control',
        'style' => 'width: 260px;'];
    echo html_writer::empty_tag('input', $findattrs);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'),
        'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    exit;
}

$course = get_course($courseid);

$anchors = bindings::anchors($courseid);
$rootuuids = array_map(fn($node) => $node->uuid, $anchors);
if (!$rootuuids) {
    $matchurl = new moodle_url('/local/curricmap/course_mapping.php', ['search' => $course->shortname]);
    echo $OUTPUT->notification(
        $cancentral ? get_string('contentmapping_notmatched', 'local_curricmap', $matchurl->out(false))
            : get_string('contentmapping_waitforaffiliation', 'local_curricmap'),
        'warning',
        false
    );
    echo $OUTPUT->footer();
    exit;
}

$modinfo = get_fast_modinfo($course);
$returnurl = $pageurl->out_as_local_url(false);

// Main view: every section up front with counts; activity rows lazy-load.
$anchoryears = contentmap::year_titles($anchors);
$anchorlabels = implode(', ', array_map(
    fn($node) => contentmap::label($node, $anchoryears[$node->uuid] ?? null),
    $anchors
));
$resourcesurl = new moodle_url('/local/curricmap/mappings.php', ['courseid' => $courseid, 'resources' => 1]);
$resourceslink = resources::enabled()
    ? ' · ' . html_writer::link($resourcesurl, get_string('studyresources_forcourse', 'local_curricmap'))
    : '';
$headline = s($course->fullname) . ' — ' . s($anchorlabels) . $resourceslink;
echo html_writer::tag('p', $headline, ['class' => 'lead']);
echo html_writer::tag('p', get_string('contentmapping_help', 'local_curricmap'), ['class' => 'text-muted']);

// Toolbar: current course + switch, then labelled multi-select filters
// (sections, module types, strand/node types), applied by Go.
$sections = $modinfo->get_section_info_all();

$fullpool = matcher::content_candidates($rootuuids, contentmap::TARGET_ROLES, true);
$formurl = new moodle_url('/local/curricmap/section_module_mapping.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out_omit_querystring(),
    'class' => 'local-curricmap-filterform d-flex flex-wrap mb-3', 'style' => 'gap: 12px;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);

if ($cancentral) {
    echo html_writer::start_div('curricmap-filter');
    $courselabel = get_string('contentmapping_currentcourse', 'local_curricmap', s($course->shortname));
    echo html_writer::tag('label', $courselabel, ['for' => 'curricmap-switch']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'coursesearch', 'value' => '',
        'id' => 'curricmap-switch', 'class' => 'form-control',
        'placeholder' => get_string('contentmapping_coursesearch', 'local_curricmap')]);
    echo html_writer::end_div();
}
$sectionoptions = [];
foreach ($sections as $section) {
    if (($section->component ?? '') === 'mod_subsection' || !$section->uservisible) {
        continue;
    }
    $optionname = get_section_name($course, $section);
    $sectionoptions[(int) $section->id] = $optionname;
}
echo html_writer::start_div('curricmap-filter');
$sectionslabel = get_string('contentmapping_filtersections', 'local_curricmap');
echo html_writer::tag('label', $sectionslabel, ['for' => 'curricmap-filter-sections']);
$sectionattrs = ['multiple' => 'multiple', 'id' => 'curricmap-filter-sections'];
echo html_writer::select($sectionoptions, 'sectionsel[]', $sectionids, false, $sectionattrs);
echo html_writer::end_div();

$presenttypes = [];
foreach ($modinfo->cms as $cm) {
    if ($cm->modname !== 'subsection') {
        $presenttypes[$cm->modname] = $cm->modname;
    }
}
ksort($presenttypes);
echo html_writer::start_div('curricmap-filter');
$typeslabel = get_string('contentmapping_filtertypes', 'local_curricmap');
echo html_writer::tag('label', $typeslabel, ['for' => 'curricmap-filter-types']);
$typeattrs = ['multiple' => 'multiple', 'id' => 'curricmap-filter-types'];
echo html_writer::select($presenttypes, 'typesel[]', $modtypesfilter, false, $typeattrs);
echo html_writer::end_div();

// Strand/node type options: the roles and session subtypes actually present
// below this course's matches.
$ntoptions = [];
foreach ($fullpool as $candidate) {
    $ntoptions[$candidate->node->role] = $candidate->node->role;
    if (!empty($candidate->node->subtype)) {
        $ntoptions[$candidate->node->subtype] = $candidate->node->subtype;
    }
}
ksort($ntoptions);
echo html_writer::start_div('curricmap-filter');
$ntypeslabel = get_string('contentmapping_filternodetypes', 'local_curricmap');
echo html_writer::tag('label', $ntypeslabel, ['for' => 'curricmap-filter-ntypes']);
$ntattrs = ['multiple' => 'multiple', 'id' => 'curricmap-filter-ntypes'];
echo html_writer::select($ntoptions, 'ntypesel[]', $nodetypesfilter, false, $ntattrs);
echo html_writer::end_div();

echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('go'),
    'class' => 'btn btn-secondary']), 'curricmap-filter align-self-end');
echo html_writer::end_tag('form');

// Both entry points render this same tree; each content location saves independently.
$tree = new content_tree($course, $returnurl, $modtypesfilter, $nodetypesfilter);
$focuspath = [];
if ($focuscm) {
    $focusaddress = bindings::normalise_address(['courseid' => $courseid, 'cmid' => $focuscm]);
    bindings::validate_location($focusaddress);
    $focuspath = array_reverse(bindings::section_path($courseid, (int) $modinfo->cms[$focuscm]->section));
}
echo html_writer::start_div('curricmap-toolbar mb-3');
foreach (['topsections', 'showall', 'expandall'] as $control) {
    echo html_writer::tag('button', get_string('contentmapping_' . $control, 'local_curricmap'),
        ['type' => 'button', 'class' => 'btn btn-secondary', 'data-curricmap-control' => $control]);
}
echo html_writer::div('', 'small', ['data-curricmap-status' => '', 'role' => 'status', 'aria-live' => 'polite']);
echo html_writer::end_div();
echo html_writer::start_tag('div', ['data-curricmap-tree' => '', 'data-courseid' => $courseid, 'data-returnurl' => $returnurl,
    'data-modtypes' => implode(',', $modtypesfilter), 'data-ntypes' => implode(',', $nodetypesfilter),
    'data-focuspath' => implode(',', $focuspath)]);
foreach ($sections as $section) {
    if (($section->component ?? '') === 'mod_subsection' || !$section->uservisible) {
        continue;
    }
    if ($sectionids && !in_array((int) $section->id, $sectionids)) {
        continue;
    }
    echo $tree->section($section);
}
echo html_writer::end_tag('div');
echo $OUTPUT->footer();
