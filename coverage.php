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
 * Curriculum coverage report: the map from above. Per programme-year — how
 * many courses are matched and how much of the curriculum is taught
 * somewhere (content-grain bindings only; anchors are affiliation, not
 * coverage). Pick a programme year for the strand-by-strand and matched-
 * course breakdowns. Read-only; CSV exports for the spreadsheet people.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_curricmap\local\coverage;

admin_externalpage_setup('local_curricmap_coverage');

$yearuuid = optional_param('yearuuid', '', PARAM_ALPHANUMEXT);
$export = optional_param('export', '', PARAM_ALPHA);

$pageurl = new moodle_url('/local/curricmap/coverage.php', ['yearuuid' => $yearuuid]);
$PAGE->set_url($pageurl);

$summaryrows = coverage::programme_year_rows();

// CSV exports.
if ($export !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="curricmap_coverage_' . $export . '.csv"');
    $out = fopen('php://output', 'w');
    if ($export === 'summary') {
        fputcsv($out, ['slug', 'year', 'programme_year', 'strands', 'sessions', 'sessions_covered',
            'outcomes', 'outcomes_covered', 'matched_courses', 'content_bindings']);
        foreach ($summaryrows as $row) {
            fputcsv($out, [$row['slug'], $row['yearlabel'], $row['yeartitle'], $row['strands'],
                $row['sessions'], $row['sessionscovered'], $row['outcomes'], $row['outcomescovered'],
                $row['matchedcourses'], $row['contentbindings']]);
        }
    } else if ($export === 'strands' && $yearuuid !== '') {
        fputcsv($out, ['strand', 'code', 'strand_bound', 'sessions', 'sessions_covered',
            'outcomes', 'outcomes_covered']);
        foreach (coverage::strand_rows($yearuuid) as $row) {
            fputcsv($out, [$row['title'], $row['code'], $row['strandcovered'] ? 1 : 0,
                $row['sessions'], $row['sessionscovered'], $row['outcomes'], $row['outcomescovered']]);
        }
    } else if ($export === 'courses' && $yearuuid !== '') {
        fputcsv($out, ['courseid', 'idnumber', 'fullname', 'shortname',
            'sections_bound', 'activities_bound', 'chapters_bound']);
        foreach (coverage::course_rows($yearuuid) as $row) {
            fputcsv($out, [$row['courseid'], $row['idnumber'], $row['fullname'], $row['shortname'],
                $row['sections'], $row['activities'], $row['chapters']]);
        }
    }
    fclose($out);
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coverage', 'local_curricmap'));
echo html_writer::tag('p', get_string('coverage_intro', 'local_curricmap'), ['class' => 'text-muted']);

// Estate summary + hygiene strip.
$estate = coverage::course_summary();
$hygiene = coverage::hygiene();
echo html_writer::tag('p', get_string('coverage_estate', 'local_curricmap', (object) $estate));
$hygienebits = [
    get_string('coverage_orphaned', 'local_curricmap', $hygiene['orphaned']),
    get_string('coverage_staleanchors', 'local_curricmap', $hygiene['staleanchorcourses']),
    get_string('coverage_resources', 'local_curricmap', (object) $hygiene),
];
echo html_writer::tag('p', implode(' · ', $hygienebits), ['class' => 'small text-muted']);

// Programme-year summary table with drill-down links.
$table = new html_table();
$table->head = [
    get_string('coverage_programmeyear', 'local_curricmap'),
    get_string('coverage_strands', 'local_curricmap'),
    get_string('coverage_sessions', 'local_curricmap'),
    get_string('coverage_outcomes', 'local_curricmap'),
    get_string('coverage_matchedcourses', 'local_curricmap'),
    get_string('coverage_contentbindings', 'local_curricmap'),
];
foreach ($summaryrows as $row) {
    $label = "{$row['slug']} {$row['yearlabel']} — {$row['yeartitle']}";
    $link = html_writer::link(new moodle_url($pageurl, ['yearuuid' => $row['yearuuid']]), s($label));
    $table->data[] = [
        $row['yearuuid'] === $yearuuid ? html_writer::tag('strong', $link) : $link,
        $row['strands'],
        "{$row['sessionscovered']} / {$row['sessions']}",
        "{$row['outcomescovered']} / {$row['outcomes']}",
        $row['matchedcourses'],
        $row['contentbindings'],
    ];
}
echo html_writer::table($table);
echo html_writer::div(html_writer::link(
    new moodle_url($pageurl, ['export' => 'summary']),
    get_string('coverage_exportsummary', 'local_curricmap')
), 'small mb-4');

// Drill-down for the selected programme year.
if ($yearuuid !== '') {
    $strandrows = coverage::strand_rows($yearuuid);
    echo $OUTPUT->heading(get_string('coverage_strandheading', 'local_curricmap'), 3);
    if (!$strandrows) {
        echo html_writer::tag('p', get_string('coverage_nostrands', 'local_curricmap'), ['class' => 'text-muted']);
    } else {
        $table = new html_table();
        $table->head = [
            get_string('coverage_strand', 'local_curricmap'),
            get_string('coverage_strandbound', 'local_curricmap'),
            get_string('coverage_sessions', 'local_curricmap'),
            get_string('coverage_outcomes', 'local_curricmap'),
        ];
        foreach ($strandrows as $row) {
            $name = $row['title'] . ($row['code'] !== '' ? ' (' . $row['code'] . ')' : '');
            $table->data[] = [
                s($name),
                $row['strandcovered'] ? get_string('yes') : get_string('no'),
                "{$row['sessionscovered']} / {$row['sessions']}",
                "{$row['outcomescovered']} / {$row['outcomes']}",
            ];
        }
        echo html_writer::table($table);
        echo html_writer::div(html_writer::link(
            new moodle_url($pageurl, ['export' => 'strands']),
            get_string('coverage_exportstrands', 'local_curricmap')
        ), 'small mb-4');
    }

    $courserows = coverage::course_rows($yearuuid);
    echo $OUTPUT->heading(get_string('coverage_courseheading', 'local_curricmap'), 3);
    if (!$courserows) {
        echo html_writer::tag('p', get_string('coverage_nocourses', 'local_curricmap'), ['class' => 'text-muted']);
    } else {
        $table = new html_table();
        $table->head = [
            get_string('coursemapping_course', 'local_curricmap'),
            get_string('coverage_sectionsbound', 'local_curricmap'),
            get_string('coverage_activitiesbound', 'local_curricmap'),
            get_string('coverage_chaptersbound', 'local_curricmap'),
            '',
        ];
        foreach ($courserows as $row) {
            $name = format_string($row['fullname']) . ' '
                . html_writer::tag('span', s($row['idnumber']), ['class' => 'small text-muted']);
            $mapurl = new moodle_url('/local/curricmap/mappings.php', ['courseid' => $row['courseid']]);
            $table->data[] = [
                $name,
                $row['sections'],
                $row['activities'],
                $row['chapters'],
                html_writer::link($mapurl, get_string('mappings', 'local_curricmap'), ['class' => 'small']),
            ];
        }
        echo html_writer::table($table);
        echo html_writer::div(html_writer::link(
            new moodle_url($pageurl, ['export' => 'courses']),
            get_string('coverage_exportcourses', 'local_curricmap')
        ), 'small mb-4');
    }
}

echo $OUTPUT->footer();
