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

namespace local_curricmap\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/../fixtures/fake_sofia_client.php');

use local_curricmap\api\bindings;

/**
 * Tests for the coverage aggregates against the fixture corpus.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_curricmap\local\coverage
 */
final class coverage_test extends \advanced_testcase {
    /** @var string Year 1 uuid. */
    const YEAR_UUID = 'e1e70820-6c4b-4ddf-a478-7c1b2db0cabe';

    /** @var string Locomotor strand uuid. */
    const LOCO_UUID = '15629971-00d5-428a-944e-e94142c86088';

    /** @var string A session outcome deep in the tree. */
    const URINARY_LO4_UUID = 'ec917dc5-4dc3-4d58-b619-b2921eef1976';

    /**
     * Reset per test.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Compose the stored node key for a raw uuid.
     *
     * @param string $uuid Raw Sofia node uuid.
     * @return string
     */
    private function key(string $uuid): string {
        return 'vet-med_latest_' . $uuid;
    }

    /**
     * Sync the revision-A fixture corpus.
     *
     * @return \stdClass The programme record.
     */
    private function sync_fixture(): \stdClass {
        global $DB;
        $programme = new \stdClass();
        $programme->slug = 'vet-med';
        $programme->versionlabel = 'LATEST';
        $programme->enabled = 1;
        $programme->lastsyncstatus = 'never';
        $programme->id = $DB->insert_record('local_curricmap_programme', $programme);
        $nodes = json_decode(file_get_contents(__DIR__ . '/../fixtures/vetmed_a_nodes.json'), true);
        $metadata = json_decode(file_get_contents(__DIR__ . '/../fixtures/vetmed_a_metadata.json'), true);
        $engine = new sync(new fake_sofia_client($nodes, $metadata, 'aaaa1111'));
        $engine->sync_programme($programme);
        return $programme;
    }

    /**
     * Coverage counts content-grain bindings only; anchors affiliate courses
     * without covering nodes; depth and hygiene counters add up.
     */
    public function test_coverage_aggregates(): void {
        global $DB;
        $this->sync_fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['idnumber' => 'RVC_TEST_2026_7']);
        $skipme = $generator->create_course(['idnumber' => 'Temp_shellcourse']);
        $module = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $sectionid = (int) $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1]);
        $this->assertNotEmpty($skipme);

        // Baseline: nothing matched, nothing covered.
        $rows = coverage::programme_year_rows();
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['matchedcourses']);
        $this->assertSame(0, $rows[0]['sessionscovered']);
        $this->assertSame(14, $rows[0]['strands']);

        // Central anchor: the course becomes MATCHED, but nothing is covered
        // (anchors are affiliation, not coverage).
        bindings::bind(['courseid' => $course->id], $this->key(self::YEAR_UUID), bindings::RELATION_ANCHOR, 'central');
        $rows = coverage::programme_year_rows();
        $this->assertSame(1, $rows[0]['matchedcourses']);
        $this->assertSame(0, $rows[0]['sessionscovered']);
        $this->assertSame(0, $rows[0]['outcomescovered']);

        // Content-grain bindings cover nodes: a section to a strand, an
        // activity to a session outcome, a chapter to the same outcome.
        bindings::bind(['courseid' => $course->id, 'sectionid' => $sectionid], $this->key(self::LOCO_UUID));
        $address = ['courseid' => $course->id, 'cmid' => $module->cmid];
        bindings::bind($address, $this->key(self::URINARY_LO4_UUID));
        $chapteraddress = $address + ['component' => 'mod_book', 'subitemid' => 7];
        bindings::bind($chapteraddress, $this->key(self::URINARY_LO4_UUID));

        $rows = coverage::programme_year_rows();
        $this->assertSame(1, $rows[0]['outcomescovered'], 'One outcome covered (twice-bound counts once).');
        $this->assertSame(3, $rows[0]['contentbindings']);

        // Strand rows: Locomotor itself is bound; the outcome sits under the
        // Urinary strand.
        $strands = coverage::strand_rows($this->key(self::YEAR_UUID));
        $bytitle = array_column($strands, null, 'title');
        $this->assertTrue($bytitle['Locomotor']['strandcovered']);
        $this->assertSame(14, count($strands));
        $covered = array_sum(array_column($strands, 'outcomescovered'));
        $this->assertSame(1, $covered);

        // Course rows: depth counts per grain.
        $courses = coverage::course_rows($this->key(self::YEAR_UUID));
        $this->assertCount(1, $courses);
        $this->assertSame(1, $courses[0]['sections']);
        $this->assertSame(1, $courses[0]['activities']);
        $this->assertSame(1, $courses[0]['chapters']);

        // Estate summary: matched, and the Temp_ course is skipped by rules.
        $summary = coverage::course_summary();
        $this->assertSame(1, $summary['matched']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame($summary['total'] - 2, $summary['unmatched']);

        // Hygiene: nothing orphaned yet; deleting the module orphans rows.
        $this->assertSame(0, coverage::hygiene()['orphaned']);
        course_delete_module($module->cmid);
        $this->assertSame(2, coverage::hygiene()['orphaned']);
    }
}
