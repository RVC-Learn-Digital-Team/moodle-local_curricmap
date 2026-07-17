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
 * Empty a course's content without deleting the course: every activity goes,
 * then every section above 0. Course settings, enrolments and the course
 * itself stay. Dry-run by default — nothing is touched without --confirm.
 *
 * Companion to generate_test_course.php for when generated content lands in
 * the wrong course. Deletion observers mark any curriculum bindings on the
 * removed modules as orphaned (review them on the course's mappings page);
 * course-level central matches are NOT touched — remove those on the course
 * matching page if they are wrong too.
 *
 * Test-content tooling only — never run against a production site.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

// Module deletion checks capabilities; run as the site admin.
\core\session\manager::set_user(get_admin());

[$options, $unrecognised] = cli_get_params(
    [
        'idnumber' => '',
        'confirm' => false,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('Unrecognised options: ' . implode(', ', array_keys($unrecognised)));
}
if ($options['help'] || trim((string) $options['idnumber']) === '') {
    cli_writeln("Empty course content (activities + extra sections) WITHOUT deleting the course.

Options:
  --idnumber=X[,Y,...]    Course idnumber(s) to empty. Comma-separated for
                          several courses.
  --confirm               Actually delete. Without it: dry run — list what
                          would be removed and change nothing.
  -h, --help              This help.

Examples:
  php empty_test_course.php --idnumber=1VETS01_A_Y_202627
  php empty_test_course.php --idnumber=1VETS01_A_Y_202627,1VETS02_A_Y_202627 --confirm");
    exit(0);
}

$idnumbers = array_filter(array_map('trim', explode(',', (string) $options['idnumber'])));
$confirm = (bool) $options['confirm'];

foreach ($idnumbers as $idnumber) {
    $course = $DB->get_record('course', ['idnumber' => $idnumber]);
    if (!$course) {
        cli_writeln("SKIP: no course with idnumber '{$idnumber}'.");
        continue;
    }
    $modinfo = get_fast_modinfo($course);
    $cms = $modinfo->cms;
    $sections = array_filter(
        $modinfo->get_section_info_all(),
        fn($section) => $section->section > 0
    );

    cli_writeln("Course {$course->id} '{$course->shortname}' (idnumber {$idnumber}): "
        . count($cms) . ' activities, ' . count($sections) . ' sections above 0.');
    foreach ($cms as $cm) {
        cli_writeln("  [{$cm->modname}] " . $cm->get_formatted_name()
            . ' (section ' . $cm->sectionnum . ')');
    }
    foreach ($sections as $section) {
        $name = get_section_name($course, $section);
        cli_writeln("  [section {$section->section}] {$name}");
    }

    if (!$confirm) {
        cli_writeln('  DRY RUN — pass --confirm to delete the above.');
        continue;
    }

    foreach ($cms as $cm) {
        course_delete_module($cm->id);
    }
    // Refresh after module deletion, then drop sections from the top down.
    rebuild_course_cache($course->id, true);
    $modinfo = get_fast_modinfo($course->id);
    $remaining = array_filter(
        $modinfo->get_section_info_all(),
        fn($section) => $section->section > 0
    );
    usort($remaining, fn($a, $b) => $b->section <=> $a->section);
    foreach ($remaining as $section) {
        course_delete_section($course, $section, true);
    }
    rebuild_course_cache($course->id, true);
    cli_writeln('  EMPTIED: ' . count($cms) . ' activities and ' . count($remaining) . ' sections removed.');
}
cli_writeln('Done.');
