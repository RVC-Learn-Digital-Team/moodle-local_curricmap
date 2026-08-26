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

namespace local_curricmap\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/fake_sofia_client.php');

use local_curricmap\api\bindings;
use local_curricmap\local\fake_sofia_client;
use local_curricmap\local\sync;

/**
 * Tests for the content_hints web service (the tiny editor's hint band).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_curricmap\external\content_hints
 */
final class content_hints_test extends \advanced_testcase {
    /** @var string Locomotor strand uuid. */
    const LOCO_UUID = '15629971-00d5-428a-944e-e94142c86088';

    /**
     * Reset per test.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Compose the stored node key for a raw uuid (programme vet-med/LATEST).
     *
     * @param string $uuid Raw Sofia node uuid.
     * @return string
     */
    private function key(string $uuid): string {
        return 'vet-med_latest_' . $uuid;
    }

    /**
     * Sync the revision-A fixture corpus.
     */
    private function sync_fixture(): void {
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
    }

    /**
     * Run the service and clean the return value.
     *
     * @param array $args Named arguments for execute().
     * @return array
     */
    private function hints(array $args): array {
        $result = content_hints::execute(
            $args['courseid'],
            $args['sectionid'] ?? 0,
            $args['cmid'] ?? 0,
            $args['component'] ?? '',
            $args['subitemid'] ?? 0,
            $args['title'] ?? '',
            $args['text'] ?? ''
        );
        return \core_external\external_api::clean_returnvalue(content_hints::execute_returns(), $result);
    }

    /**
     * Course mapped to a strand: pool grows from the strand (itself first),
     * a matching title surfaces the session as the top hint, and body text
     * contributes hints the title missed.
     */
    public function test_hints_from_course_anchor(): void {
        $this->sync_fixture();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        bindings::bind(['courseid' => (int) $course->id], $this->key(self::LOCO_UUID), 'anchor', 'central');

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->hints([
            'courseid' => (int) $course->id,
            'title' => 'Bone and the Skeleton',
            'text' => '<p>This chapter covers muscle contraction in some depth.</p>',
        ]);

        $this->assertCount(1, $result['roots']);
        $this->assertSame('Locomotor', $result['roots'][0]['title']);
        $this->assertSame('strand', $result['roots'][0]['role']);

        // The pool leads with the mapped node itself, then its subtree.
        $this->assertSame('Locomotor', $result['pool'][0]['title']);
        $this->assertGreaterThan(20, $result['pooltotal']);

        $titles = array_column($result['hints'], 'title');
        $this->assertContains('Bone and the Skeleton', $titles);
        $this->assertSame('Bone and the Skeleton', $result['hints'][0]['title']);
        $this->assertSame(100, $result['hints'][0]['score']);
        $this->assertFalse((bool) $result['hints'][0]['frombody']);

        $bodyhints = array_values(array_filter($result['hints'], fn($hint) => !empty($hint['frombody'])));
        $this->assertContains('Muscle Contraction', array_column($bodyhints, 'title'));
    }

    /**
     * The cascade takes the DEEPEST mapping: a chapter-grain binding narrows
     * the pool to that node's subtree, beating activity, section and course.
     */
    public function test_cascade_prefers_deepest_mapping(): void {
        $this->sync_fixture();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $book = $generator->create_module('book', ['course' => $course->id]);
        $sectionid = (int) get_fast_modinfo($course->id)->cms[$book->cmid]->section;

        // Find a session under Locomotor to use as the chapter's mapping.
        global $DB;
        $session = $DB->get_record('local_curricmap_node', ['title' => 'Joints', 'role' => 'session'], '*', MUST_EXIST);

        bindings::bind(['courseid' => (int) $course->id], $this->key(self::LOCO_UUID), 'anchor', 'central');
        $chapteraddress = ['courseid' => (int) $course->id, 'cmid' => (int) $book->cmid,
            'component' => 'mod_book', 'subitemid' => 42];
        bindings::bind($chapteraddress, $session->uuid, 'anchor', 'central');

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->hints([
            'courseid' => (int) $course->id,
            'sectionid' => $sectionid,
            'cmid' => (int) $book->cmid,
            'component' => 'mod_book',
            'subitemid' => 42,
        ]);
        $this->assertCount(1, $result['roots']);
        $this->assertSame('Joints', $result['roots'][0]['title']);
        // Pool = the session plus its own outcomes only, not the whole strand.
        $this->assertLessThan(10, $result['pooltotal']);
        $this->assertSame('Joints', $result['pool'][0]['title']);

        // Without the subitem, the cascade falls back to the course anchor.
        $result = $this->hints([
            'courseid' => (int) $course->id,
            'sectionid' => $sectionid,
            'cmid' => (int) $book->cmid,
        ]);
        $this->assertSame('Locomotor', $result['roots'][0]['title']);
    }

    /**
     * An unmapped course returns the empty shape, and students are refused.
     */
    public function test_unmapped_and_permissions(): void {
        $this->sync_fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->hints(['courseid' => (int) $course->id, 'title' => 'Joints']);
        $this->assertSame([], $result['roots']);
        $this->assertSame([], $result['hints']);
        $this->assertSame(0, $result['pooltotal']);

        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        $this->hints(['courseid' => (int) $course->id]);
    }
}
