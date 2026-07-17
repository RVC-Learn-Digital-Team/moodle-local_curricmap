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
 * Generate a realistic test course from the synced Sofia mirror.
 *
 * Content derives from the curriculum itself (strand/session titles, outcome
 * text as page and chapter bodies), so the matching pages have true positives
 * to find. Decoy housekeeping sections, a red-herring page from another
 * strand, and a page of pre-authored filter placeholders are included so the
 * skip rules, false-positive behaviour and filter rendering get exercised
 * too. Port the result to other test sites with a standard course backup.
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
require_once($CFG->dirroot . '/course/modlib.php');

use local_curricmap\api\curriculum;

// Module creation checks capabilities; run as the site admin.
\core\session\manager::set_user(get_admin());

[$options, $unrecognised] = cli_get_params(
    [
        'slug' => 'vet-med',
        'year' => '',
        'programme_year' => '',
        'strand_course' => '',
        'idnumber' => '',
        'match_existing' => false,
        'strand_sections' => false,
        'maxsessions' => 8,
        'categoryid' => '',
        'list_courses' => false,
        'list_programmes' => false,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('Unrecognised options: ' . implode(', ', array_keys($unrecognised)));
}
if ($options['help']) {
    cli_writeln("Generate a test course from the synced Sofia mirror.

Options:
  --slug=vet-med          Programme slug (default vet-med).
  --year=2026             Academic-year version label (default: latest synced).
  --programme_year=XXXX   Which programme-year node (\"Year 1\", \"Veterinary
                          Gateway\", a code, or a composed uuid). Mandatory
                          when the programme has more than one; the script
                          lists them with strand counts when unsure.
  --strand_course=XXXX    Single-strand course; XXXX = strand title or code,
                          case-insensitive (\"Locomotor\" or \"UG1-LOCO\"), or a
                          composed uuid. Omit for a whole programme-year course.
  --idnumber=XXXX         Course idnumber (default RVC_TESTGEN_<CODE>_<year>_<seq>).
  --match_existing        Append content to the existing course with that
                          idnumber instead of creating a new course.
  --strand_sections       Strands become sections of pages/labels/urls instead
                          of the default books-with-chapters layout.
  --maxsessions=8         Sessions used per strand/unit (size cap).
  --categoryid=N          Course category id — MANDATORY when creating a
                          course (not needed with --match_existing).
  --list_courses[=N]      List courses (id, idnumber, fullname, shortname,
                          category id) as CSV and exit. With N: only
                          category N, including its subcategories.
  --list_programmes       List every synced slug / year / programme-year
                          combination (with strand counts) as CSV and exit.
  -h, --help              This help.

Examples:
  php generate_test_course.php --strand_course=Locomotor --categoryid=2
  php generate_test_course.php --slug=vet-med --year=2026 --strand_sections --categoryid=2
  php generate_test_course.php --strand_course=UG1-ALI --idnumber=1VETS90_A_Y_202627 --match_existing");
    exit(0);
}

// Course listing mode: print and exit (works before any curriculum sync).
if ($options['list_courses'] !== false) {
    $where = 'c.id <> :siteid';
    $params = ['siteid' => SITEID];
    if ($options['list_courses'] !== true) {
        $categoryid = (int) $options['list_courses'];
        $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id, name, path');
        if (!$category) {
            cli_writeln("--list_courses={$options['list_courses']} is not an existing course category.");
            cli_writeln('Available categories:');
            foreach ($DB->get_records('course_categories', [], 'sortorder ASC', 'id, name') as $cat) {
                cli_writeln("  {$cat->id}  {$cat->name}");
            }
            exit(1);
        }
        // The category itself plus everything below it (path prefix).
        $pathlike = $DB->sql_like('cc.path', ':subpath');
        $where .= " AND (cc.id = :categoryid OR $pathlike)";
        $params['categoryid'] = $category->id;
        $params['subpath'] = $DB->sql_like_escape($category->path) . '/%';
        cli_writeln("Courses in '{$category->name}' ({$category->id}) and subcategories:");
    }
    $sql = "SELECT c.id, c.idnumber, c.fullname, c.shortname, c.category
              FROM {course} c
              JOIN {course_categories} cc ON cc.id = c.category
             WHERE $where
          ORDER BY cc.sortorder ASC, c.fullname ASC";
    $courses = $DB->get_records_sql($sql, $params);
    // CSV: unambiguous through terminals and straight into a spreadsheet
    // (tabs render as spaces; fullnames contain commas, so quote properly).
    $out = fopen('php://stdout', 'w');
    fputcsv($out, ['id', 'idnumber', 'fullname', 'shortname', 'categoryid']);
    foreach ($courses as $course) {
        fputcsv($out, [$course->id, $course->idnumber, $course->fullname, $course->shortname, $course->category]);
    }
    fclose($out);
    cli_writeln(count($courses) . ' course(s).');
    exit(0);
}

// Programme listing mode: every slug / year / programme-year combination.
if ($options['list_programmes'] !== false) {
    $out = fopen('php://stdout', 'w');
    fputcsv($out, ['slug', 'year', 'programme_year_code', 'programme_year', 'strands']);
    $combinations = 0;
    foreach (curriculum::programmes() as $programme) {
        foreach (curriculum::years((int) $programme->id) as $yearnode) {
            fputcsv($out, [
                $programme->slug,
                $programme->versionlabel,
                (string) $yearnode->code,
                $yearnode->title,
                count(curriculum::strands($yearnode->uuid)),
            ]);
            $combinations++;
        }
    }
    fclose($out);
    cli_writeln($combinations . ' programme-year combination(s).');
    exit(0);
}

$maxsessions = max(1, (int) $options['maxsessions']);

// Resolve the programme year.
$programme = null;
foreach (curriculum::programmes() as $candidate) {
    if ($candidate->slug !== $options['slug']) {
        continue;
    }
    if ($options['year'] !== '' && $candidate->versionlabel !== (string) $options['year']) {
        continue;
    }
    if (!$programme || strcmp($candidate->versionlabel, $programme->versionlabel) > 0) {
        $programme = $candidate;
    }
}
if (!$programme) {
    cli_error("No synced programme for slug '{$options['slug']}'" .
        ($options['year'] !== '' ? " year '{$options['year']}'" : '') . '. Sync first.');
}
$years = curriculum::years((int) $programme->id);
if (!$years) {
    cli_error("Programme {$programme->slug} {$programme->versionlabel} has no year nodes.");
}

/**
 * Print the programme-year nodes with their strand counts.
 *
 * @param \stdClass[] $years Year nodes.
 */
function local_curricmap_testgen_list_years(array $years): void {
    cli_writeln('Available programme years:');
    foreach ($years as $year) {
        $strandcount = count(curriculum::strands($year->uuid));
        $code = !empty($year->code) ? "{$year->code}  " : '';
        cli_writeln("  {$code}{$year->title}  ({$strandcount} strands)");
    }
}

// Select the programme-year node: explicit option, or the only one there is.
$yearnode = null;
if ($options['programme_year'] !== '') {
    $needle = core_text::strtolower(trim((string) $options['programme_year']));
    foreach ($years as $year) {
        $title = core_text::strtolower((string) $year->title);
        $code = core_text::strtolower((string) $year->code);
        if ($needle === $title || $needle === $code || $needle === $year->uuid) {
            $yearnode = $year;
            break;
        }
        if ($yearnode === null && $title !== '' && strpos($title, $needle) !== false) {
            $yearnode = $year;
        }
    }
    if (!$yearnode) {
        cli_writeln("Programme year '{$options['programme_year']}' not found.");
        local_curricmap_testgen_list_years($years);
        exit(1);
    }
} else if (count($years) === 1) {
    $yearnode = reset($years);
} else {
    cli_writeln('This programme has more than one programme year — pass --programme_year=.');
    local_curricmap_testgen_list_years($years);
    exit(1);
}
$strands = curriculum::strands($yearnode->uuid);
if (!$strands) {
    cli_writeln("No strands under {$yearnode->title} — pick a programme year that has some.");
    local_curricmap_testgen_list_years($years);
    exit(1);
}
cli_writeln("Programme: {$programme->slug} {$programme->versionlabel} — {$yearnode->title}, " . count($strands) . ' strands');

// Resolve --strand_course to one strand (title, code or composed uuid).
$targetstrand = null;
if ($options['strand_course'] !== '') {
    $needle = core_text::strtolower(trim((string) $options['strand_course']));
    foreach ($strands as $strand) {
        $title = core_text::strtolower((string) $strand->title);
        $code = core_text::strtolower((string) $strand->code);
        if ($needle === $title || $needle === $code || $needle === $strand->uuid) {
            $targetstrand = $strand;
            break;
        }
        if ($targetstrand === null && $title !== '' && strpos($title, $needle) !== false) {
            $targetstrand = $strand;
        }
    }
    if (!$targetstrand) {
        cli_writeln('Strand not found. Available strands:');
        foreach ($strands as $strand) {
            cli_writeln("  {$strand->code}  {$strand->title}");
        }
        exit(1);
    }
    cli_writeln("Strand: {$targetstrand->title} ({$targetstrand->code})");
}

// Idnumber: given, or old-tool dialect the matcher can parse.
$yearstart = preg_match('/^(20\d\d)/', $programme->versionlabel, $m) ? (int) $m[1] : 2026;
$idnumber = trim((string) $options['idnumber']);
if ($idnumber === '') {
    $stem = $targetstrand ? preg_replace('/[^A-Z0-9]/', '', core_text::strtoupper((string) $targetstrand->code)) : 'YEAR';
    $idnumber = 'RVC_TESTGEN_' . $stem . '_' . $yearstart . '_' . ($yearstart - 2019);
}

// Find or create the course.
$existing = $DB->get_record('course', ['idnumber' => $idnumber]);
if ($existing && !$options['match_existing']) {
    cli_error("A course with idnumber '{$idnumber}' exists (id {$existing->id}). " .
        'Pass --match_existing to append content to it.');
}
if (!$existing && $options['match_existing']) {
    cli_error("--match_existing: no course with idnumber '{$idnumber}' found.");
}
if ($existing) {
    $course = $existing;
    cli_writeln("Appending to course {$course->id} '{$course->shortname}'.");
} else {
    // Creating a course: an explicit target category is mandatory so test
    // content never lands somewhere by default.
    $categoryid = (int) $options['categoryid'];
    $categoryvalid = $options['categoryid'] !== '' && $categoryid > 0
        && $DB->record_exists('course_categories', ['id' => $categoryid]);
    if (!$categoryvalid) {
        if ($options['categoryid'] === '') {
            cli_writeln('--categoryid is mandatory when creating a course.');
        } else {
            cli_writeln("--categoryid={$options['categoryid']} is not an existing course category.");
        }
        cli_writeln('Available categories:');
        foreach ($DB->get_records('course_categories', [], 'sortorder ASC', 'id, name, parent') as $cat) {
            cli_writeln("  {$cat->id}  {$cat->name}");
        }
        exit(1);
    }
    $yearlabel = $yearstart . '-' . sprintf('%02d', ($yearstart + 1) % 100);
    $base = $targetstrand ? $targetstrand->title : $yearnode->title;
    $course = create_course((object) [
        'fullname' => $base . ' (generated test course) ' . $yearlabel,
        'shortname' => 'TG-' . preg_replace('/[^A-Za-z0-9]/', '', $targetstrand->code ?? 'YEAR') . '-' . $yearstart,
        'category' => $categoryid,
        'idnumber' => $idnumber,
        'summary' => 'Generated from the Sofia mirror by local_curricmap for mapping tests.',
        'summaryformat' => FORMAT_HTML,
        'format' => 'topics',
        'visible' => 1,
    ]);
    cli_writeln("Created course {$course->id} '{$course->shortname}' idnumber {$idnumber}.");
}

/**
 * The generated body text for a session: description plus its outcomes.
 *
 * @param \stdClass $session Session node.
 * @return string HTML.
 */
function local_curricmap_testgen_body(stdClass $session): string {
    $html = '';
    if (!empty($session->description)) {
        $html .= '<p>' . s($session->description) . '</p>';
    }
    $html .= '<p>This session forms part of the taught programme. By the end students should be able to:</p><ul>';
    foreach (curriculum::session_outcomes($session->uuid) as $outcome) {
        $html .= '<li>' . s($outcome->title) . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Create one activity via the standard modlib path.
 *
 * @param \stdClass $course The course.
 * @param string $modname page|book|label|url.
 * @param int $sectionnum Section number.
 * @param string $name Activity name.
 * @param string $intro Intro HTML.
 * @param array $extra Module-specific fields.
 * @return \stdClass The created module info.
 */
function local_curricmap_testgen_module(
    stdClass $course,
    string $modname,
    int $sectionnum,
    string $name,
    string $intro,
    array $extra = []
): stdClass {
    $moduleinfo = (object) array_merge([
        'modulename' => $modname,
        'course' => $course->id,
        'section' => $sectionnum,
        'name' => $name,
        'introeditor' => ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0],
        'visible' => 1,
        'visibleoncoursepage' => 1,
        'groupmode' => 0,
        'groupingid' => 0,
        'availability' => null,
        'completion' => 0,
        'cmidnumber' => '',
    ], $extra);
    return create_module($moduleinfo);
}

/**
 * Fill one section with a strand's teaching at the requested layout.
 *
 * @param \stdClass $course The course.
 * @param int $sectionnum Section number.
 * @param \stdClass $strand Strand node.
 * @param bool $asbook Book-with-chapters (default) or loose activities.
 * @param int $maxsessions Session cap.
 * @return int Items created.
 */
function local_curricmap_testgen_strand(
    stdClass $course,
    int $sectionnum,
    stdClass $strand,
    bool $asbook,
    int $maxsessions
): int {
    global $DB;
    $sessions = array_slice(curriculum::sessions($strand->uuid), 0, $maxsessions);
    $created = 0;

    local_curricmap_testgen_module(
        $course,
        'label',
        $sectionnum,
        $strand->title . ' overview',
        '<p>Teaching for the ' . s($strand->title) . ' strand'
        . (!empty($strand->code) ? ' (' . s($strand->code) . ')' : '') . '.</p>'
    );
    $created++;

    if ($asbook) {
        $book = local_curricmap_testgen_module(
            $course,
            'book',
            $sectionnum,
            $strand->title . ' strand book',
            '<p>Session-by-session teaching material for ' . s($strand->title) . '.</p>',
            ['numbering' => 1, 'navstyle' => 1, 'customtitles' => 0]
        );
        $pagenum = 0;
        foreach ($sessions as $session) {
            $DB->insert_record('book_chapters', (object) [
                'bookid' => (int) $book->instance,
                'pagenum' => ++$pagenum,
                'subchapter' => 0,
                'title' => $session->title,
                'content' => local_curricmap_testgen_body($session),
                'contentformat' => FORMAT_HTML,
                'hidden' => 0,
                'importsrc' => '',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            $created++;
        }
        return $created;
    }

    foreach ($sessions as $index => $session) {
        if ($index % 3 === 2 && !empty($session->sofiaurl)) {
            local_curricmap_testgen_module(
                $course,
                'url',
                $sectionnum,
                $session->title,
                '<p>' . s($session->title) . '</p>',
                ['externalurl' => $session->sofiaurl, 'display' => 0]
            );
        } else {
            local_curricmap_testgen_module(
                $course,
                'page',
                $sectionnum,
                $session->title,
                '<p>' . s($session->title) . '</p>',
                ['content' => local_curricmap_testgen_body($session), 'contentformat' => FORMAT_HTML,
                'display' => 0,
                'printintro' => 0,
                'printlastmodified' => 1]
            );
        }
        $created++;
    }
    return $created;
}

// Plan the sections.
$strandlist = $targetstrand ? [$targetstrand] : $strands;
$existingsections = $DB->count_records('course_sections', ['course' => $course->id]) - 1;
$startsection = $options['match_existing'] ? $existingsections + 1 : 1;
$numsections = $startsection + count($strandlist) + 2;
course_create_sections_if_missing($course, range(0, $numsections));
$course = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);

$modinfo = get_fast_modinfo($course);
$total = 0;
$sectionnum = $startsection;

foreach ($strandlist as $strand) {
    course_update_section($course, $modinfo->get_section_info($sectionnum), ['name' => $strand->title]);
    $total += local_curricmap_testgen_strand(
        $course,
        $sectionnum,
        $strand,
        empty($options['strand_sections']),
        $maxsessions
    );
    cli_writeln("  section {$sectionnum}: {$strand->title}");
    $sectionnum++;
}

// Decoy housekeeping sections: the skip rules must ignore these.
foreach (['General', 'Support Blocks'] as $decoy) {
    course_update_section($course, $modinfo->get_section_info($sectionnum), ['name' => $decoy]);
    local_curricmap_testgen_module(
        $course,
        'label',
        $sectionnum,
        $decoy . ' notice',
        '<p>Administrative content for the ' . s($decoy) . ' section — not teaching material.</p>'
    );
    $sectionnum++;
    $total++;
}

// A red-herring page: content from a DIFFERENT strand, in section 0.
$others = array_values(array_filter($strands, fn($s) => !$targetstrand || $s->uuid !== $targetstrand->uuid));
if ($others) {
    $herringsessions = curriculum::sessions($others[0]->uuid);
    if ($herringsessions) {
        $herring = reset($herringsessions);
        local_curricmap_testgen_module(
            $course,
            'page',
            0,
            'Preparation reading',
            '<p>Background reading.</p>',
            ['content' => local_curricmap_testgen_body($herring), 'contentformat' => FORMAT_HTML,
            'display' => 0,
            'printintro' => 0,
            'printlastmodified' => 1]
        );
        $total++;
    }
}

// A page of pre-authored filter placeholders (inline + card) in section 0.
$placeholderstrand = $targetstrand ?: $strandlist[0];
$outcomes = curriculum::strand_outcomes($placeholderstrand->uuid);
if (!$outcomes) {
    $firstsessions = curriculum::sessions($placeholderstrand->uuid);
    $outcomes = $firstsessions ? curriculum::session_outcomes(reset($firstsessions)->uuid) : [];
}
if ($outcomes) {
    $first = reset($outcomes);
    $span = fn(string $display) => '<span class="curricmap" data-curricmap-node="' . s($first->uuid)
        . '" data-curricmap-display="' . $display . '">' . s($first->title) . '</span>';
    local_curricmap_testgen_module(
        $course,
        'page',
        0,
        'About this curriculum content',
        '<p>Filter test page.</p>',
        ['content' => '<p>This course teaches ' . $span('inline') . ' among other outcomes.</p>'
            . $span('card'),
        'contentformat' => FORMAT_HTML,
        'display' => 0,
        'printintro' => 0,
        'printlastmodified' => 1]
    );
    $total++;
}

rebuild_course_cache($course->id, true);
cli_writeln("Done: {$total} items across " . ($sectionnum - $startsection) . ' strand/decoy sections.');
cli_writeln('Next: match it on course_mapping.php (idnumber ' . $idnumber . '), then map content.');
