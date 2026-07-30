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
 * Moodle Course Mapping for one course, all on one page: every section is
 * listed up front with its current matches, activity/chapter counts and (when
 * the pool is genuinely ambiguous) a strand proposal; each section's activity
 * mapping rows load lazily on demand. Books link to a chapter view (same
 * page, back button) — the only sub-module grain. Multi-select filters bound
 * what is shown; apply buttons repeat every few sections for long courses.
 * Everything created here is a central-scope anchor binding.
 *
 * This is where a year-matched course's strands are mapped: sections take
 * strands, activities take sessions and outcomes within them. (A
 * strand-matched course is a strand course — its whole-course scope was
 * decided on Central Admin Mapping and content maps within that strand.)
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

// Remove one central match.
$unbind = optional_param('unbind', 0, PARAM_INT);
if ($unbind && $courseid && confirm_sesskey()) {
    require_capability('local/curricmap:managebindings', context_system::instance());
    $binding = $DB->get_record('local_curricmap_binding', ['id' => $unbind], '*', MUST_EXIST);
    if ($binding->scope === 'central' && (int) $binding->courseid === $courseid) {
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
$PAGE->requires->js_call_amd('local_curricmap/browse', 'init', [$systemcontext->id]);

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

    // Pool cascade: the book's own matches, else its section's saved matches
    // merged with any pending (unsaved) picks carried on the link, else the
    // course's.
    $pendingraw = array_filter(explode(',', optional_param('pending', '', PARAM_RAW_TRIMMED)));
    $pendingroots = array_slice(array_map('trim', $pendingraw), 0, 20);
    $ownroots = array_map(fn($b) => $b->nodeuuid, $bycm[$bookcm] ?? []);
    $sectionroots = array_map(fn($b) => $b->nodeuuid, $bysection[(int) $cm->section] ?? []);
    $sectionroots = array_values(array_unique(array_merge($sectionroots, $pendingroots)));
    $poolroots = $ownroots ?: ($sectionroots ?: $rootuuids);
    $rawpool = matcher::content_candidates($poolroots, contentmap::TARGET_ROLES);
    $chapterpool = contentmap::filter_pool($rawpool, $nodetypesfilter);
    $narrowed = !empty($ownroots) || !empty($sectionroots);

    // The strand-type filter, visible and adjustable in the chapter view too.
    $ntoptions = [];
    foreach ($rawpool as $candidate) {
        $ntoptions[$candidate->node->role] = $candidate->node->role;
        if (!empty($candidate->node->subtype)) {
            $ntoptions[$candidate->node->subtype] = $candidate->node->subtype;
        }
    }
    ksort($ntoptions);
    $chapterformurl = new moodle_url('/local/curricmap/section_module_mapping.php');
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $chapterformurl->out_omit_querystring(),
        'class' => 'local-curricmap-filterform d-flex flex-wrap mb-3', 'style' => 'gap: 12px;']);
    foreach (['courseid' => $courseid, 'bookcm' => $bookcm] as $hiddenname => $hiddenvalue) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $hiddenname, 'value' => $hiddenvalue]);
    }
    foreach (['sections', 'types', 'pending'] as $passthrough) {
        $passvalue = optional_param($passthrough, '', PARAM_RAW_TRIMMED);
        if ($passvalue !== '') {
            $passattrs = ['type' => 'hidden', 'name' => $passthrough, 'value' => $passvalue];
            echo html_writer::empty_tag('input', $passattrs);
        }
    }
    echo html_writer::start_div('curricmap-filter');
    $ntypeslabel = get_string('contentmapping_filternodetypes', 'local_curricmap');
    echo html_writer::tag('label', $ntypeslabel, ['for' => 'curricmap-filter-ntypes']);
    $ntattrs = ['multiple' => 'multiple', 'id' => 'curricmap-filter-ntypes'];
    echo html_writer::select($ntoptions, 'ntypesel[]', $nodetypesfilter, false, $ntattrs);
    echo html_writer::end_div();
    echo html_writer::div(html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('go'),
        'class' => 'btn btn-secondary']), 'curricmap-filter align-self-end');
    echo html_writer::end_tag('form');

    $chapters = $DB->get_records('book_chapters', ['bookid' => (int) $cm->instance], 'pagenum ASC');
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    foreach ($chapters as $chapter) {
        $key = 'h' . (int) $chapter->id;
        $chapterbody = content_to_text((string) $chapter->content, (int) $chapter->contentformat);
        $hints = contentmap::merged_hints($chapter->title, $chapterbody, $chapterpool, $rules);
        $chapterbrowseroot = $poolroots[0] ?? null;
        $chapterproposal = contentmap::proposal_cell($key, $hints, $chapterpool, $narrowed, $chapterbrowseroot);
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
        echo html_writer::div($cells, 'curricmap-row curricmap-activity-row');
    }
    echo html_writer::empty_tag('input', ['type' => 'submit',
        'value' => get_string('contentmapping_matchchapters', 'local_curricmap'), 'class' => 'btn btn-primary mt-2']);
    echo html_writer::end_tag('form');
    echo html_writer::div(html_writer::link($backurl, get_string('contentmapping_back', 'local_curricmap')), 'mt-2');
    echo $OUTPUT->footer();
    exit;
}

// Main view: every section up front with counts; activity rows lazy-load.
$anchoryears = contentmap::year_titles($anchors);
$anchorlabels = implode(', ', array_map(
    fn($node) => contentmap::label($node, $anchoryears[$node->uuid] ?? null),
    $anchors
));
$resourcesurl = new moodle_url('/local/curricmap/study_resources.php', ['courseid' => $courseid]);
$resourceslink = html_writer::link($resourcesurl, get_string('studyresources_forcourse', 'local_curricmap'));
$headline = s($course->fullname) . ' — ' . s($anchorlabels) . ' · ' . $resourceslink;
echo html_writer::tag('p', $headline, ['class' => 'lead']);
echo html_writer::tag('p', get_string('contentmapping_help', 'local_curricmap'), ['class' => 'text-muted']);

// Toolbar: current course + switch, then labelled multi-select filters
// (sections, module types, strand/node types), applied by Go.
$sections = $modinfo->get_section_info_all();
$fullpool = matcher::content_candidates($rootuuids, contentmap::TARGET_ROLES);
$formurl = new moodle_url('/local/curricmap/section_module_mapping.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out_omit_querystring(),
    'class' => 'local-curricmap-filterform d-flex flex-wrap mb-3', 'style' => 'gap: 12px;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);

echo html_writer::start_div('curricmap-filter');
$courselabel = get_string('contentmapping_currentcourse', 'local_curricmap', s($course->shortname));
echo html_writer::tag('label', $courselabel, ['for' => 'curricmap-switch']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'coursesearch', 'value' => '',
    'id' => 'curricmap-switch', 'class' => 'form-control',
    'placeholder' => get_string('contentmapping_coursesearch', 'local_curricmap')]);
echo html_writer::end_div();

$sectionoptions = [];
foreach ($sections as $section) {
    $sectionoptions[(int) $section->id] = get_section_name($course, $section);
}
echo html_writer::start_div('curricmap-filter');
$sectionslabel = get_string('contentmapping_filtersections', 'local_curricmap');
echo html_writer::tag('label', $sectionslabel, ['for' => 'curricmap-filter-sections']);
$sectionattrs = ['multiple' => 'multiple', 'id' => 'curricmap-filter-sections'];
echo html_writer::select($sectionoptions, 'sectionsel[]', $sectionids, false, $sectionattrs);
echo html_writer::end_div();

$presenttypes = [];
foreach ($modinfo->cms as $cm) {
    if (in_array($cm->modname, $mappabletypes)) {
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

// Section proposal pool: shown only when there is a real choice to make.
// Units belong here beside strands - a unit-level grouping is section-shaped,
// so a Moodle section may teach one. They are deliberately NOT added to
// contentmap::TARGET_ROLES, which is the activity/chapter grain and too fine.
$strandpool = matcher::content_candidates($rootuuids, ['strand', 'unit']);
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
    foreach (resources::for_nodes(array_keys($bounduuids), null, true) as $resource) {
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
        $deepened = matcher::content_candidates($sectionroots, contentmap::TARGET_ROLES);
        $sectionpool = array_merge($strandpool, contentmap::filter_pool($deepened, $nodetypesfilter));
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
            'data-curricmap-ntypes' => implode(',', $nodetypesfilter),
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
        $sectionbrowseroot = $sectionroots[0] ?? ($rootuuids[0] ?? null);
        $proposalcell = contentmap::proposal_cell($key, $hints, $sectionpool, !empty($sectionroots), $sectionbrowseroot);
    }

    $sectioncurrent = contentmap::current_cell($bysection[$sid] ?? [], $returnurl, $rescounts);
    $cells = html_writer::div(contentmap::tick($key, $sectionname), 'curricmap-cell-tick')
        . html_writer::div($namecell, 'curricmap-cell-name', ['style' => 'min-width: 280px;'])
        . html_writer::div($sectioncurrent, 'curricmap-cell-current')
        . html_writer::div($proposalcell, 'curricmap-cell-proposal');
    echo html_writer::start_div('curricmap-section-box border rounded p-2 mb-2');
    echo html_writer::div($cells, 'curricmap-row');
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
