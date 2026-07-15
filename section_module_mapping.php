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
 * Central content matching for one course, all on one page: every section is
 * listed up front with its current matches, activity/chapter counts and (when
 * the pool is genuinely ambiguous) a strand proposal; each section's activity
 * mapping rows load lazily on demand. Books link to a chapter view (same
 * page, back button) — the only sub-module grain. Multi-select filters bound
 * what is shown; apply buttons repeat every few sections for long courses.
 * Everything created here is a central-scope anchor binding.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_curricmap\api\bindings;
use local_curricmap\api\resources;
use local_curricmap\local\contentmap;
use local_curricmap\local\matcher;

admin_externalpage_setup('local_curricmap_contentmapping');

$courseid = optional_param('courseid', 0, PARAM_INT);
$bookcm = optional_param('bookcm', 0, PARAM_INT);
$coursesearch = trim(optional_param('coursesearch', '', PARAM_RAW_TRIMMED));

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

$urlparams = ['courseid' => $courseid];
if ($sectionids) {
    $urlparams['sections'] = implode(',', $sectionids);
}
if ($modtypesfilter) {
    $urlparams['types'] = implode(',', $modtypesfilter);
}
if ($bookcm) {
    $urlparams['bookcm'] = $bookcm;
}
$pageurl = new moodle_url('/local/curricmap/section_module_mapping.php', $urlparams);

// Remove one central match.
$unbind = optional_param('unbind', 0, PARAM_INT);
if ($unbind && $courseid && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $binding = $DB->get_record('local_curricmap_binding', ['id' => $unbind], '*', MUST_EXIST);
    if ($binding->scope === 'central' && (int) $binding->courseid === $courseid) {
        bindings::unbind((int) $binding->id);
        redirect($pageurl, get_string('coursemapping_removed', 'local_curricmap'));
    }
    redirect($pageurl);
}

// Apply: keys are s<sectionid>, c<cmid>, or h<chapterid> (chapter view only,
// where bookcm supplies the owning cm). Each row's select is multiple.
$apply = optional_param_array('apply', [], PARAM_INT);
if ($apply && $courseid && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $created = 0;
    foreach ($apply as $key => $ticked) {
        if (!$ticked) {
            continue;
        }
        $address = ['courseid' => $courseid];
        if (strpos($key, 's') === 0) {
            $address['sectionid'] = (int) substr($key, 1);
        } else if (strpos($key, 'c') === 0) {
            $address['cmid'] = (int) substr($key, 1);
        } else if (strpos($key, 'h') === 0 && $bookcm) {
            $address['cmid'] = $bookcm;
            $address['component'] = 'mod_book';
            $address['subitemid'] = (int) substr($key, 1);
        } else {
            continue;
        }
        foreach (optional_param_array('bind' . $key, [], PARAM_RAW_TRIMMED) as $nodeuuid) {
            if ($nodeuuid === '') {
                continue;
            }
            bindings::bind($address, $nodeuuid, bindings::RELATION_ANCHOR, 'central');
            $created++;
        }
    }
    redirect($pageurl, get_string('coursemapping_applied', 'local_curricmap', $created));
}

$typetosearch = get_string('coursemapping_typetosearch', 'local_curricmap');
$systemcontext = context_system::instance();
$PAGE->requires->js_call_amd('core/checkbox-toggleall', 'init');
$PAGE->requires->js_call_amd('local_curricmap/course_mapping', 'init', [$typetosearch, $systemcontext->id]);

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
$rules = matcher::rules();
$mappabletypes = array_filter(explode(',', (string) get_config('local_curricmap', 'mappablemodtypes')));

$anchors = bindings::anchors($courseid);
$rootuuids = array_map(fn($node) => $node->uuid, $anchors);
if (!$rootuuids) {
    $matchurl = new moodle_url('/local/curricmap/course_mapping.php', ['search' => $course->shortname]);
    echo $OUTPUT->notification(
        get_string('contentmapping_notmatched', 'local_curricmap', $matchurl->out(false)),
        'warning',
        false
    );
    echo $OUTPUT->footer();
    exit;
}

[$bysection, $bycm, $bychapter] = contentmap::buckets($courseid);
$modinfo = get_fast_modinfo($course);
$returnurl = $pageurl->out_as_local_url(false);

// Chapter view: one book's chapters, back button, no lazy loading needed.
if ($bookcm && isset($modinfo->cms[$bookcm]) && $modinfo->cms[$bookcm]->modname === 'book') {
    $cm = $modinfo->cms[$bookcm];
    $backparams = $urlparams;
    unset($backparams['bookcm']);
    $backurl = new moodle_url('/local/curricmap/section_module_mapping.php', $backparams);

    echo html_writer::tag('p', s($course->fullname) . ' — ' . s($cm->get_formatted_name()), ['class' => 'lead']);
    echo html_writer::div(html_writer::link($backurl, get_string('contentmapping_back', 'local_curricmap')), 'mb-3');

    // Pool cascade: the book's own matches, else its section's, else the course's.
    $ownroots = array_map(fn($b) => $b->nodeuuid, $bycm[$bookcm] ?? []);
    $sectionroots = array_map(fn($b) => $b->nodeuuid, $bysection[(int) $cm->section] ?? []);
    $poolroots = $ownroots ?: ($sectionroots ?: $rootuuids);
    $chapterpool = matcher::content_candidates($poolroots, contentmap::TARGET_ROLES);
    $narrowed = !empty($ownroots) || !empty($sectionroots);

    $chapters = $DB->get_records('book_chapters', ['bookid' => (int) $cm->instance], 'pagenum ASC');
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    foreach ($chapters as $chapter) {
        $key = 'h' . (int) $chapter->id;
        $hints = matcher::match_title($chapter->title, $chapterpool, $rules);
        $chapterproposal = contentmap::proposal_cell($key, $hints, $chapterpool, $narrowed);
        $name = s($chapter->title);
        if ($chapter->subchapter) {
            $name = html_writer::tag('span', $name, ['style' => 'padding-left: 20px;']);
        }
        $cells = html_writer::div(contentmap::tick($key, $chapter->title), 'curricmap-cell-tick')
            . html_writer::div($name, 'curricmap-cell-name')
            . html_writer::div(
                contentmap::current_cell($bychapter[$bookcm][(int) $chapter->id] ?? [], $returnurl),
                'curricmap-cell-current'
            )
            . html_writer::div($chapterproposal, 'curricmap-cell-proposal');
        echo html_writer::div($cells, 'd-flex align-items-start border-top py-1', ['style' => 'gap: 8px;']);
    }
    echo html_writer::empty_tag('input', ['type' => 'submit',
        'value' => get_string('contentmapping_matchchapters', 'local_curricmap'), 'class' => 'btn btn-primary mt-2']);
    echo html_writer::end_tag('form');
    echo html_writer::div(html_writer::link($backurl, get_string('contentmapping_back', 'local_curricmap')), 'mt-2');
    echo $OUTPUT->footer();
    exit;
}

// Main view: every section up front with counts; activity rows lazy-load.
$anchorlabels = implode(', ', array_map(fn($node) => contentmap::label($node), $anchors));
$resourcesurl = new moodle_url('/local/curricmap/study_resources.php', ['courseid' => $courseid]);
$resourceslink = html_writer::link($resourcesurl, get_string('studyresources_forcourse', 'local_curricmap'));
$headline = s($course->fullname) . ' — ' . s($anchorlabels) . ' · ' . $resourceslink;
echo html_writer::tag('p', $headline, ['class' => 'lead']);
echo html_writer::tag('p', get_string('contentmapping_help', 'local_curricmap'), ['class' => 'text-muted']);

// Toolbar: multi-select section + type filters (chips; applied by Go), course switch.
$sections = $modinfo->get_section_info_all();
$formurl = new moodle_url('/local/curricmap/section_module_mapping.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out_omit_querystring(),
    'class' => 'local-curricmap-filterform d-flex flex-wrap align-items-center mb-3', 'style' => 'gap: 8px;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
$sectionoptions = [];
foreach ($sections as $section) {
    $sectionoptions[(int) $section->id] = get_section_name($course, $section);
}
$sectionattrs = ['aria-label' => get_string('contentmapping_section', 'local_curricmap'),
    'multiple' => 'multiple', 'id' => 'curricmap-filter-sections'];
echo html_writer::select($sectionoptions, 'sectionsel[]', $sectionids, false, $sectionattrs);
$presenttypes = [];
foreach ($modinfo->cms as $cm) {
    if (in_array($cm->modname, $mappabletypes)) {
        $presenttypes[$cm->modname] = $cm->modname;
    }
}
ksort($presenttypes);
$typeattrs = ['aria-label' => get_string('contentmapping_modtype', 'local_curricmap'),
    'multiple' => 'multiple', 'id' => 'curricmap-filter-types'];
echo html_writer::select($presenttypes, 'typesel[]', $modtypesfilter, false, $typeattrs);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'coursesearch', 'value' => '',
    'placeholder' => get_string('contentmapping_coursesearch', 'local_curricmap'), 'class' => 'form-control',
    'style' => 'width: 200px;']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('go'),
    'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

// Section proposal pool: shown only when there is a real choice to make.
$strandpool = matcher::content_candidates($rootuuids, ['strand']);
$counts = contentmap::section_counts($course, $mappabletypes, $bycm, $bychapter);

// Resource counts for section-level bound nodes.
$bounduuids = [];
foreach ($bysection as $rows) {
    foreach ($rows as $binding) {
        $bounduuids[$binding->nodeuuid] = true;
    }
}
$rescounts = [];
if ($bounduuids) {
    foreach (resources::for_nodes(array_keys($bounduuids)) as $resource) {
        $rescounts[$resource->nodeuuid] = ($rescounts[$resource->nodeuuid] ?? 0) + 1;
    }
}

$applybutton = html_writer::div(html_writer::empty_tag('input', ['type' => 'submit',
    'value' => get_string('coursemapping_apply', 'local_curricmap'), 'class' => 'btn btn-primary']), 'my-2');

$masterattrs = ['type' => 'checkbox', 'data-action' => 'toggle', 'data-toggle' => 'master',
    'data-togglegroup' => 'contentmatch', 'aria-label' => get_string('coursemapping_selectall', 'local_curricmap')];

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::div(html_writer::empty_tag('input', $masterattrs) . ' '
    . get_string('coursemapping_selectall', 'local_curricmap'), 'mb-2');

$shown = 0;
foreach ($sections as $section) {
    $sid = (int) $section->id;
    if ($sectionids && !in_array($sid, $sectionids)) {
        continue;
    }
    $sectionname = get_section_name($course, $section);
    $sectionroots = array_map(fn($b) => $b->nodeuuid, $bysection[$sid] ?? []);

    // Once matched, the section's own picker deepens to its node's subtree.
    $sectionpool = $strandpool;
    if ($sectionroots) {
        $sectionpool = array_merge($strandpool, matcher::content_candidates($sectionroots, contentmap::TARGET_ROLES));
    }
    $housekeeping = matcher::is_housekeeping($sectionname, $rules);
    $hints = $housekeeping ? [] : matcher::match_title($sectionname, $sectionpool, $rules);
    $key = 's' . $sid;

    $namecell = html_writer::tag('strong', s($sectionname));
    if ($housekeeping) {
        $hklabel = get_string('contentmapping_housekeeping', 'local_curricmap');
        $namecell .= ' ' . html_writer::tag('span', $hklabel, ['class' => 'badge badge-secondary']);
    }
    $tally = $counts[$sid] ?? null;
    if ($tally && $tally->activities) {
        $countbits = get_string('contentmapping_counts', 'local_curricmap', $tally);
        if ($tally->chapters) {
            $countbits .= ' · ' . get_string('contentmapping_chaptercounts', 'local_curricmap', $tally);
        }
        $expandattrs = ['type' => 'button', 'class' => 'btn btn-sm btn-link p-0',
            'data-curricmap-expand' => 'curricmap-sec-' . $sid,
            'data-curricmap-course' => $courseid,
            'data-curricmap-section' => $sid,
            'data-curricmap-modtypes' => implode(',', $modtypesfilter),
            'data-curricmap-return' => $returnurl];
        $expandlabel = get_string('contentmapping_mapactivities', 'local_curricmap');
        $namecell .= html_writer::div(
            html_writer::tag('span', $countbits, ['class' => 'small text-muted']) . ' · '
            . html_writer::tag('button', $expandlabel, $expandattrs)
        );
    }

    // Proposal only when there is genuine ambiguity (pool > 1).
    $proposalcell = '';
    if (count($sectionpool) > 1) {
        $proposalcell = contentmap::proposal_cell($key, $hints, $sectionpool, !empty($sectionroots));
    }

    $sectioncurrent = contentmap::current_cell($bysection[$sid] ?? [], $returnurl, $rescounts);
    $cells = html_writer::div(contentmap::tick($key, $sectionname), 'curricmap-cell-tick')
        . html_writer::div($namecell, 'curricmap-cell-name', ['style' => 'min-width: 280px;'])
        . html_writer::div($sectioncurrent, 'curricmap-cell-current')
        . html_writer::div($proposalcell, 'curricmap-cell-proposal');
    echo html_writer::start_div('curricmap-section-box border rounded p-2 mb-2');
    echo html_writer::div($cells, 'd-flex align-items-start', ['style' => 'gap: 8px;']);
    echo html_writer::div('', 'curricmap-activities mt-1', ['id' => 'curricmap-sec-' . $sid]);
    echo html_writer::end_div();

    $shown++;
    if ($shown % 4 === 0) {
        echo $applybutton;
    }
}
if ($shown === 0) {
    echo $OUTPUT->notification(get_string('contentmapping_norows', 'local_curricmap'), 'info');
} else if ($shown % 4 !== 0) {
    echo $applybutton;
}
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
