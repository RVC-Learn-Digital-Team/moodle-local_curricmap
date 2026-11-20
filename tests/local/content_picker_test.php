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

use local_curricmap\api\bindings;
use local_curricmap\api\curriculum;
use local_curricmap\external\save_content_mappings;

/**
 * Reviewable suggestions, scoped search and atomic row saves.
 *
 * @package local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_curricmap\local\content_picker
 * @covers \local_curricmap\external\save_content_mappings
 */
final class content_picker_test extends \advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;
    /** @var \stdClass Editing teacher. */
    private $teacher;
    /** @var int Synthetic programme. */
    private $programme;
    /** @var array Synthetic curriculum. */
    private $nodes;

    /**
     * Create fictional curriculum and an authorised course administrator.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['numsections' => 2]);
        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->programme = $DB->insert_record('local_curricmap_programme', (object) [
            'slug' => 'test', 'displayname' => 'Nursing', 'versionlabel' => '2026', 'enabled' => 1,
        ]);
        $year = $this->node('year', 'Year 1');
        $strand = $this->node('strand', 'Academic and Professional Development', $year);
        $session = $this->node('session', 'Effective note making', $strand);
        $one = $this->node('sessionoutcome', 'Evaluate note-making strategies and organise notes effectively', $session);
        $two = $this->node('sessionoutcome', 'Recognise effective learning approaches for studying at university', $session);
        $related = $this->node('sessionoutcome', 'Review personal study habits', $session);
        $outside = $this->node('sessionoutcome', 'Outside course curriculum');
        $this->nodes = compact('year', 'strand', 'session', 'one', 'two', 'related', 'outside');
        bindings::bind(['courseid' => $this->course->id], $year->uuid, 'anchor', 'central');
    }

    /**
     * Insert one synthetic node with a valid materialised path.
     *
     * @param string $role Node role.
     * @param string $title Title.
     * @param \stdClass|null $parent Parent.
     * @return \stdClass Node.
     */
    private function node(string $role, string $title, ?\stdClass $parent = null): \stdClass {
        global $DB;
        $uuid = 'test_2026_27_' . strtolower(str_replace(' ', '_', $title));
        $uuid = clean_param($uuid, PARAM_ALPHANUMEXT);
        $id = $DB->insert_record('local_curricmap_node', (object) [
            'programmeid' => $this->programme, 'uuid' => $uuid, 'title' => $title,
            'role' => $role, 'parentid' => $parent->id ?? null, 'source' => 'manual',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->set_field('local_curricmap_node', 'path', ($parent->path ?? '/') . $id . '/', ['id' => $id]);
        return curriculum::node($uuid);
    }

    /**
     * Teachers can replace central content mappings, retain sessions and outcomes, and map them repeatedly.
     */
    public function test_save_replaces_only_this_items_anchors(): void {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $other = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $address = ['courseid' => $this->course->id, 'cmid' => $page->cmid];
        $section = get_fast_modinfo($this->course)->cms[$page->cmid]->section;
        bindings::bind($address + ['sectionid' => $section], $this->nodes['related']->uuid, 'anchor', 'central');
        $related = bindings::bind($address, $this->nodes['related']->uuid, 'related', 'central');
        bindings::bind($address + ['sectionid' => $section], $this->nodes['one']->uuid, 'anchor', 'central');
        $wanted = [$this->nodes['session']->uuid, $this->nodes['one']->uuid];
        $this->setUser($this->teacher);
        foreach ([$page, $other] as $module) {
            $result = save_content_mappings::execute($this->course->id, 'c' . $module->cmid, $wanted);
            $this->assertEquals($wanted, array_column($result['mappings'], 'uuid'));
        }
        $result = save_content_mappings::execute($this->course->id, 'c' . $page->cmid, $wanted);
        $this->assertCount(2, $result['mappings']);
        [, $savedbycm] = contentmap::buckets($this->course->id);
        $this->assertCount(2, $savedbycm[$page->cmid]);
        $this->assertTrue($DB->record_exists('local_curricmap_binding', ['id' => $related]));
        save_content_mappings::execute($this->course->id, 'c' . $page->cmid, []);
        [, $bycm] = contentmap::buckets($this->course->id);
        $this->assertArrayNotHasKey($page->cmid, $bycm);
        $this->assertCount(2, $bycm[$other->cmid]);
        $this->assertCount(1, bindings::anchors($this->course->id));
    }

    /**
     * An invalid target must leave every existing mapping unchanged.
     */
    public function test_invalid_selection_is_atomic(): void {
        global $DB;
        $section = get_fast_modinfo($this->course)->get_section_info(1);
        $address = ['courseid' => $this->course->id, 'sectionid' => $section->id];
        $id = bindings::bind($address, $this->nodes['one']->uuid, 'anchor', 'central');
        $this->setUser($this->teacher);
        try {
            $invalid = [$this->nodes['two']->uuid, $this->nodes['outside']->uuid];
            save_content_mappings::execute($this->course->id, 's' . $section->id, $invalid);
            $this->fail('Out-of-scope curriculum was accepted');
        } catch (\moodle_exception $exception) {
            $this->assertSame('errorbindnode', $exception->errorcode);
        }
        $this->assertTrue($DB->record_exists('local_curricmap_binding', ['id' => $id]));
        $this->assertCount(1, bindings::resolve(['courseid' => $this->course->id, 'sectionid' => $section->id], 'anchor'));
    }

    /**
     * Chapter saves are isolated from their parent activity and sibling chapters.
     */
    public function test_chapter_save(): void {
        $book = $this->getDataGenerator()->create_module('book', ['course' => $this->course->id]);
        $chapter = $this->getDataGenerator()->get_plugin_generator('mod_book')->create_chapter(['bookid' => $book->id]);
        $this->setUser($this->teacher);
        save_content_mappings::execute($this->course->id, 'h' . $chapter->id, [$this->nodes['one']->uuid]);
        [, $bycm, $bychapter] = contentmap::buckets($this->course->id);
        $this->assertEmpty($bycm);
        $this->assertSame($this->nodes['one']->uuid, $bychapter[$book->cmid][$chapter->id][0]->nodeuuid);
    }

    /**
     * A course administrator cannot forge a location from another course.
     */
    public function test_foreign_location_rejected(): void {
        $other = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);
        $this->setUser($this->teacher);
        $this->expectException(\moodle_exception::class);
        save_content_mappings::execute($this->course->id, 'c' . $page->cmid, [$this->nodes['one']->uuid]);
    }

    /**
     * Students cannot use the row-save endpoint.
     */
    public function test_student_rejected(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $section = get_fast_modinfo($this->course)->get_section_info(1);
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        save_content_mappings::execute($this->course->id, 's' . $section->id, []);
    }

    /**
     * Search includes unloaded descendants and excludes curriculum outside the course anchors.
     */
    public function test_scoped_search_and_browse(): void {
        $html = content_picker::browse($this->course->id, 's1', '', 'strategies');
        $this->assertStringContainsString($this->nodes['one']->uuid, $html);
        $this->assertStringContainsString('Effective note making', $html);
        $this->assertStringContainsString('Nursing › Year 1', $html);
        $html = content_picker::browse($this->course->id, 's1', '', 'Outside');
        $this->assertStringNotContainsString($this->nodes['outside']->uuid, $html);
        $this->expectException(\moodle_exception::class);
        content_picker::browse($this->course->id, 's1', $this->nodes['outside']->uuid, '');
    }

    /**
     * Manual outcome text yields pickable matches plus clearly identified related children.
     */
    public function test_editor_suggestions_and_evidence(): void {
        $pool = matcher::content_candidates([$this->nodes['year']->uuid], null, true);
        $name = 'Effective Note Making';
        $text = $this->nodes['one']->title . '. ' . $this->nodes['two']->title;
        $hints = contentmap::merged_hints($name, $text, $pool, matcher::rules());
        $html = content_picker::editor('s1', [], $hints, $pool, $name, $text);
        foreach (['session', 'one', 'two', 'related'] as $key) {
            $this->assertStringContainsString('data-curricmap-pick="' . $this->nodes[$key]->uuid . '"', $html);
        }
        $this->assertStringContainsString(get_string('contentmapping_relatedoutcome', 'local_curricmap'), $html);
        $this->assertStringContainsString(get_string('contentmapping_source', 'local_curricmap'), $html);
        $this->assertStringContainsString('data-curricmap-save', $html);
        $this->assertStringNotContainsString('type="submit"', $html);
        // Opt-in synthetic browser fixtures. The caller creates this ignored repository-local directory.
        $output = dirname(__DIR__, 2) . '/test_results/content_picker_preview';
        if (is_dir($output)) {
            file_put_contents($output . '/editor.html', $html);
            file_put_contents($output . '/search.html', content_picker::browse($this->course->id, 's1', '', 'strategies'));
            $strings = [];
            $keys = ['saved', 'unsaved', 'pending', 'remove', 'empty', 'selected', 'pick', 'saving', 'savefailed',
                'cancelled', 'loading', 'loadfailed'];
            foreach ($keys as $key) {
                $strings['contentmapping_' . $key] = get_string('contentmapping_' . $key, 'local_curricmap');
            }
            file_put_contents($output . '/strings.json', json_encode($strings));
        }
    }
}
