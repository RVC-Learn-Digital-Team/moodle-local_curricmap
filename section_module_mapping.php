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
 * Central content matching for one course: map its sections and activity
 * modules to curriculum nodes below the course's central match(es).
 * Sections propose strands (synonym-aware title containment); modules
 * propose sessions and outcomes, cascading — a section matched to a strand
 * narrows its modules' pool to that strand's subtree, and a module matched
 * to a session gains the session's outcomes as further targets. Ticked rows
 * are confirmed explicitly; everything created here is a central-scope
 * anchor binding. Weeks never match by name (Sofia has no week concept —
 * weeks move); recordings arrive later via the platform engine as node
 * resources, not here.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_curricmap\api\bindings;
use local_curricmap\api\resources;
use local_curricmap\local\matcher;

admin_externalpage_setup('local_curricmap_contentmapping');

$courseid = optional_param('courseid', 0, PARAM_INT);
$sectionid = optional_param('sectionid', 0, PARAM_INT);
$modtype = optional_param('modtype', '', PARAM_PLUGIN);
$coursesearch = trim(optional_param('coursesearch', '', PARAM_RAW_TRIMMED));

$pageurl = new moodle_url('/local/curricmap/course_mapping.php');
if ($courseid) {
    $pageparams = ['courseid' => $courseid, 'sectionid' => $sectionid, 'modtype' => $modtype];
    $pageurl = new moodle_url('/local/curricmap/section_module_mapping.php', $pageparams);
}

/**
 * A short target label: node title, role, academic year from the composed key.
 *
 * @param stdClass $node Node record.
 * @return string
 */
function local_curricmap_content_label(stdClass $node): string {
    $year = preg_match('/_(20\d\d)_\d\d_/', $node->uuid, $matches) ? ' - ' . $matches[1] : '';
    return $node->title . ' [' . $node->role . ']' . $year;
}

// Remove one central match (delete icon in the current matches column).
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

// Apply: keys are s<sectionid> or c<cmid>; each row's select is multiple, so
// its picks arrive as bind<key>[] and every pick becomes one binding.
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

$PAGE->requires->js_call_amd('core/checkbox-toggleall', 'init');
$typetosearch = get_string('coursemapping_typetosearch', 'local_curricmap');
$PAGE->requires->js_call_amd('local_curricmap/course_mapping', 'init', [$typetosearch]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('contentmapping', 'local_curricmap'));

// Course finder: shown when no course is selected, or when searching to switch.
if (!$courseid || $coursesearch !== '') {
    echo html_writer::tag('p', get_string('contentmapping_intro', 'local_curricmap'));
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
        $found = $DB->get_records_sql($sql, $params, 0, 20);
        foreach ($found as $candidate) {
            $url = new moodle_url('/local/curricmap/section_module_mapping.php', ['courseid' => $candidate->id]);
            $label = $candidate->fullname . ($candidate->idnumber ? ' (' . $candidate->idnumber . ')' : '');
            echo html_writer::div(html_writer::link($url, s($label)));
        }
        if (!$found) {
            echo $OUTPUT->notification(get_string('coursemapping_nocourses', 'local_curricmap'), 'info');
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

// The course's central matches set the candidate pool; without them no
// meaningful proposals are possible.
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

$anchorlabels = implode(', ', array_map(fn($node) => local_curricmap_content_label($node), $anchors));
echo html_writer::tag('p', s($course->fullname) . ' — ' . s($anchorlabels), ['class' => 'lead']);
echo html_writer::tag('p', get_string('contentmapping_help', 'local_curricmap'), ['class' => 'text-muted']);

// Candidate pools below the course's matches.
$strandpool = matcher::content_candidates($rootuuids, ['strand']);
$outcomeroles = ['session', 'strandoutcome', 'sessionoutcome', 'assessment'];

// Existing central section/module matches for this course, with node titles.
$bysection = [];
$bycm = [];
$bindingsql = "SELECT b.id, b.sectionid, b.cmid, b.nodeuuid, n.title, n.uuid AS nodefound, n.role
                 FROM {local_curricmap_binding} b
            LEFT JOIN {local_curricmap_node} n ON n.uuid = b.nodeuuid
                WHERE b.courseid = :courseid AND b.scope = :scope AND b.status = :status
                      AND (b.sectionid IS NOT NULL OR b.cmid IS NOT NULL)
             ORDER BY b.sortorder ASC, b.id ASC";
$bindingparams = ['courseid' => $courseid, 'scope' => 'central', 'status' => 'active'];
foreach ($DB->get_records_sql($bindingsql, $bindingparams) as $binding) {
    if ($binding->cmid) {
        $bycm[(int) $binding->cmid][] = $binding;
    } else if ($binding->sectionid) {
        $bysection[(int) $binding->sectionid][] = $binding;
    }
}

// Resource counts for every bound node on the page, one query.
$rescounts = [];
$bounduuids = [];
foreach (array_merge($bysection, $bycm) as $rowbindings) {
    foreach ($rowbindings as $binding) {
        $bounduuids[$binding->nodeuuid] = true;
    }
}
if ($bounduuids) {
    foreach (resources::for_nodes(array_keys($bounduuids)) as $resource) {
        $rescounts[$resource->nodeuuid] = ($rescounts[$resource->nodeuuid] ?? 0) + 1;
    }
}

$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();

// Only the configured activity types are offered for mapping (the
// mappablemodtypes setting on the general settings page).
$mappabletypes = array_filter(explode(',', (string) get_config('local_curricmap', 'mappablemodtypes')));

// Toolbar: section and module-type filters plus course switching, one form.
$modtypes = [];
foreach ($modinfo->cms as $cm) {
    if (in_array($cm->modname, $mappabletypes)) {
        $modtypes[$cm->modname] = $cm->modname;
    }
}
ksort($modtypes);
$formurl = new moodle_url('/local/curricmap/section_module_mapping.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out_omit_querystring(),
    'class' => 'local-curricmap-filterform d-flex flex-wrap align-items-center mb-3', 'style' => 'gap: 8px;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
$sectionoptions = [0 => get_string('contentmapping_allsections', 'local_curricmap')];
foreach ($sections as $section) {
    $sectionoptions[(int) $section->id] = get_section_name($course, $section);
}
$sectionattrs = ['aria-label' => get_string('contentmapping_section', 'local_curricmap')];
echo html_writer::select($sectionoptions, 'sectionid', $sectionid, false, $sectionattrs);
$typeoptions = ['' => get_string('contentmapping_alltypes', 'local_curricmap')] + $modtypes;
$typeattrs = ['aria-label' => get_string('contentmapping_modtype', 'local_curricmap')];
echo html_writer::select($typeoptions, 'modtype', $modtype, false, $typeattrs);
$switchattrs = ['type' => 'text', 'name' => 'coursesearch', 'value' => '',
    'placeholder' => get_string('contentmapping_coursesearch', 'local_curricmap'), 'class' => 'form-control',
    'style' => 'width: 220px;'];
echo html_writer::empty_tag('input', $switchattrs);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search'),
    'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

/**
 * The current matches cell: node titles with year, a remove icon, and a
 * study-resources cross-link showing how much material sits on the node.
 *
 * @param array $rowbindings Binding records for this row.
 * @param moodle_url $pageurl Page url for the remove action.
 * @param array $rescounts Resource counts keyed by node uuid.
 * @return string HTML.
 */
function local_curricmap_content_current(array $rowbindings, moodle_url $pageurl, array $rescounts): string {
    global $OUTPUT;
    $entries = [];
    foreach ($rowbindings as $binding) {
        $removeurl = new moodle_url($pageurl, ['unbind' => $binding->id, 'sesskey' => sesskey()]);
        $removeicon = $OUTPUT->pix_icon('t/delete', get_string('coursemapping_removematch', 'local_curricmap'));
        $year = preg_match('/_(20\d\d)_\d\d_/', $binding->nodeuuid, $matches) ? ' - ' . $matches[1] : '';
        $label = s(($binding->title ?? $binding->nodeuuid) . $year);
        $resurl = new moodle_url('/local/curricmap/study_resources.php', ['node' => $binding->nodeuuid]);
        $count = $rescounts[$binding->nodeuuid] ?? 0;
        $reslabel = get_string('studyresources_count', 'local_curricmap', $count);
        $entries[] = $label . ' ' . html_writer::link($removeurl, $removeicon)
            . ' ' . html_writer::link($resurl, $reslabel, ['class' => 'small']);
    }
    return implode(html_writer::empty_tag('br'), $entries);
}

/**
 * A proposal cell: hint-ordered searchable dropdown plus the full pool.
 *
 * @param string $key Row key (s<sectionid> or c<cmid>).
 * @param string $name The Moodle name being matched.
 * @param array $hints Scored hints from matcher::match_title().
 * @param array $pool Full candidate pool offered below the hints.
 * @param bool $narrowed Whether the pool is already below a match (changes the capped-pool message).
 * @return string HTML.
 */
function local_curricmap_content_proposal(
    string $key,
    string $name,
    array $hints,
    array $pool,
    bool $narrowed = false
): string {
    $options = [];
    foreach ($hints as $hint) {
        $percent = (int) round($hint->score * 100);
        $hintlabel = local_curricmap_content_label($hint->candidate->node);
        $options[$hint->candidate->node->uuid] = $hintlabel . ' [' . $percent . '%]';
    }
    // An unnarrowed pool (section not yet matched) can run to thousands of
    // outcomes — offer hints only and say why, rather than a monster dropdown.
    $capped = count($pool) > 300;
    if (!$capped) {
        foreach ($pool as $candidate) {
            if (!isset($options[$candidate->node->uuid])) {
                $options[$candidate->node->uuid] = local_curricmap_content_label($candidate->node);
            }
        }
    }
    $cell = '';
    if ($options) {
        // Multi-select: pick several targets in one pass, each becomes a
        // binding on apply; an empty selection is "no action".
        $attrs = ['data-curricmap-row' => $key, 'id' => 'curricmap-bind-' . $key,
            'multiple' => 'multiple', 'data-curricmap-search' => 1];
        $cell = html_writer::select($options, "bind{$key}[]", '', false, $attrs);
    }
    if ($capped) {
        $notekey = $narrowed ? 'contentmapping_toolarge' : 'contentmapping_narrowfirst';
        $narrownote = get_string($notekey, 'local_curricmap');
        $cell .= ' ' . html_writer::tag('span', $narrownote, ['class' => 'small text-muted']);
    } else if ($cell === '') {
        $poolnote = get_string('contentmapping_nopool', 'local_curricmap');
        $cell = html_writer::tag('span', $poolnote, ['class' => 'small text-muted']);
    }
    return $cell;
}

// Build the rows: sections in course order; a section's modules appear when
// that section is filtered or when a module type is chosen.
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$masterattrs = ['type' => 'checkbox', 'data-action' => 'toggle', 'data-toggle' => 'master',
    'data-togglegroup' => 'contentmatch', 'aria-label' => get_string('coursemapping_selectall', 'local_curricmap')];
$table->head = [
    html_writer::empty_tag('input', $masterattrs),
    get_string('contentmapping_item', 'local_curricmap'),
    get_string('coursemapping_currentmatches', 'local_curricmap'),
    get_string('coursemapping_proposal', 'local_curricmap'),
];

foreach ($sections as $section) {
    $sid = (int) $section->id;
    if ($sectionid && $sid !== $sectionid) {
        continue;
    }
    $sectionname = get_section_name($course, $section);
    $showmodules = ($sectionid === $sid) || $modtype !== '';
    $sectionroots = array_map(fn($b) => $b->nodeuuid, $bysection[$sid] ?? []);

    // Section row (hidden when filtering by module type only).
    if ($modtype === '' || $sectionid === $sid) {
        // Once the section is matched, its dropdown deepens: the matched
        // node's own sessions and outcomes become secondary targets, so
        // specific objectives and events can be added to the same section.
        $sectionpool = $strandpool;
        if ($sectionroots) {
            $sectionpool = array_merge($strandpool, matcher::content_candidates($sectionroots, $outcomeroles));
        }
        $housekeeping = matcher::is_housekeeping($sectionname, $rules);
        $hints = $housekeeping ? [] : matcher::match_title($sectionname, $sectionpool, $rules);
        $key = 's' . $sid;
        $tickattrs = ['type' => 'checkbox', 'name' => "apply[$key]", 'value' => 1,
            'data-action' => 'toggle', 'data-toggle' => 'slave', 'data-togglegroup' => 'contentmatch',
            'aria-label' => get_string('coursemapping_selectcourse', 'local_curricmap', $sectionname)];
        $namecell = html_writer::tag('strong', s($sectionname));
        if ($housekeeping) {
            $hklabel = get_string('contentmapping_housekeeping', 'local_curricmap');
            $namecell .= ' ' . html_writer::tag('span', $hklabel, ['class' => 'badge badge-secondary']);
        }
        $mappablecount = 0;
        foreach ($modinfo->sections[(int) $section->section] ?? [] as $cmid) {
            if (in_array($modinfo->cms[$cmid]->modname, $mappabletypes)) {
                $mappablecount++;
            }
        }
        if ($sectionid !== $sid && $mappablecount) {
            $drillurl = new moodle_url($pageurl, ['sectionid' => $sid]);
            $drilllabel = get_string('contentmapping_drill', 'local_curricmap', $mappablecount);
            $namecell .= html_writer::div(html_writer::link($drillurl, $drilllabel, ['class' => 'small']));
        }
        $table->data[] = [
            html_writer::empty_tag('input', $tickattrs),
            $namecell,
            local_curricmap_content_current($bysection[$sid] ?? [], $pageurl, $rescounts),
            local_curricmap_content_proposal($key, $sectionname, $hints, $sectionpool, !empty($sectionroots)),
        ];
    }

    if (!$showmodules) {
        continue;
    }

    // Module rows: pool cascades — a section matched to a node narrows its
    // modules to that subtree; a module matched to a session adds outcomes.
    $modulepool = matcher::content_candidates($sectionroots ?: $rootuuids, $outcomeroles);
    foreach ($modinfo->sections[(int) $section->section] ?? [] as $cmid) {
        $cm = $modinfo->cms[$cmid];
        if (!in_array($cm->modname, $mappabletypes)) {
            continue;
        }
        if ($modtype !== '' && $cm->modname !== $modtype) {
            continue;
        }
        $cmname = $cm->get_formatted_name();
        $rowpool = $modulepool;
        $ownroots = array_map(fn($b) => $b->nodeuuid, $bycm[(int) $cm->id] ?? []);
        if ($ownroots) {
            $rowpool = array_merge($rowpool, matcher::content_candidates($ownroots, ['sessionoutcome']));
        }
        $hints = matcher::match_title($cmname, $rowpool, $rules);
        $rownarrowed = !empty($sectionroots) || !empty($ownroots);
        $key = 'c' . (int) $cm->id;
        $tickattrs = ['type' => 'checkbox', 'name' => "apply[$key]", 'value' => 1,
            'data-action' => 'toggle', 'data-toggle' => 'slave', 'data-togglegroup' => 'contentmatch',
            'aria-label' => get_string('coursemapping_selectcourse', 'local_curricmap', $cmname)];
        $namecell = html_writer::tag('span', s($cmname), ['style' => 'padding-left: 24px;'])
            . ' ' . html_writer::tag('span', s($cm->modname), ['class' => 'small text-muted']);
        $table->data[] = [
            html_writer::empty_tag('input', $tickattrs),
            $namecell,
            local_curricmap_content_current($bycm[(int) $cm->id] ?? [], $pageurl, $rescounts),
            local_curricmap_content_proposal($key, $cmname, $hints, $rowpool, $rownarrowed),
        ];
    }
}

if ($table->data) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::table($table);
    echo html_writer::empty_tag('input', ['type' => 'submit',
        'value' => get_string('coursemapping_apply', 'local_curricmap'), 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
} else {
    echo $OUTPUT->notification(get_string('contentmapping_norows', 'local_curricmap'), 'info');
}

echo $OUTPUT->footer();
