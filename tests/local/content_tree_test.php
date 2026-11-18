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
use local_curricmap\external\bind;
use local_curricmap\external\unbind;

/**
 * Shared mapper hierarchy, text, repeated mappings and permission regressions.
 *
 * @package local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_curricmap\local\content_tree
 * @covers \local_curricmap\local\contentmap
 * @covers \local_curricmap\api\bindings
 * @covers \local_curricmap\external\bind
 * @covers \local_curricmap\external\unbind
 */
final class content_tree_test extends \advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;
    /** @var \stdClass Editing teacher. */
    private $teacher;
    /** @var string Synthetic curriculum node. */
    private $node = 'test_2025_26_learning';

    /** Prepare an isolated course and curriculum. */
    protected function setUp(): void {
        global $DB, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['format' => 'topics', 'numsections' => 2]);
        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $PAGE->set_url('/local/curricmap/section_module_mapping.php', ['courseid' => $this->course->id]);
        $PAGE->set_context(\context_course::instance($this->course->id));
        $programme = $DB->insert_record('local_curricmap_programme', (object) [
            'slug' => 'test', 'versionlabel' => '2025', 'enabled' => 1,
        ]);
        $id = $DB->insert_record('local_curricmap_node', (object) [
            'programmeid' => $programme, 'uuid' => $this->node, 'role' => 'session',
            'title' => 'Reflect on learning strategies', 'source' => 'manual',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->set_field('local_curricmap_node', 'path', '/' . $id . '/', ['id' => $id]);
        bindings::bind(['courseid' => $this->course->id], $this->node, 'anchor', 'central');
        set_config('mappablemodtypes', 'label,page,book,resource', 'local_curricmap');
        $pluginmanager = \core_plugin_manager::resolve_plugininfo_class('mod');
        $pluginmanager::enable_plugin('subsection', 1);
    }

    /**
     * Create a delegated section within section one.
     *
     * @param string $name Section name.
     * @return \section_info
     */
    private function subsection(string $name): \section_info {
        $module = $this->getDataGenerator()->create_module('subsection', [
            'course' => $this->course->id, 'section' => 1, 'name' => $name,
        ]);
        return \mod_subsection\manager::create_from_instance($module)->get_delegated_section_info();
    }

    /** A week counts descendants and renders their teaching text in Moodle order. */
    public function test_subsections_text_counts_and_parent_scope(): void {
        global $DB;
        $friday = $this->subsection('Friday');
        $resources = $this->subsection('Weekly downloadable resources');
        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $this->course->id, 'section' => $friday->section, 'name' => 'Weekly overview',
            'intro' => '<p><strong>Reflect on learning strategies</strong></p><script>alert(1)</script>',
            'introformat' => FORMAT_HTML,
        ]);
        for ($i = 0; $i < 4; $i++) {
            $this->getDataGenerator()->create_module('resource', [
                'course' => $this->course->id, 'section' => $resources->section, 'name' => 'Study resource ' . $i,
            ]);
        }
        $root = get_fast_modinfo($this->course)->get_section_info(1);
        bindings::bind(['courseid' => $this->course->id, 'sectionid' => $root->id], $this->node, 'anchor', 'central');
        $this->setUser($this->teacher);
        $tree = new content_tree($this->course, '/local/curricmap/section_module_mapping.php');
        $heading = $tree->section($root);
        $this->assertStringContainsString('2 subsections · 5 activities', $heading);
        $children = $tree->children((int) $root->id);
        $this->assertLessThan(strpos($children, 'Weekly downloadable resources'), strpos($children, 'Friday'));
        $html = $tree->children((int) $friday->id);
        $this->assertStringContainsString('<strong>Reflect on learning strategies</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('bindc' . $label->cmid, $html);
        $path = bindings::section_path((int) $this->course->id, (int) $friday->id);
        $this->assertSame([(int) $friday->id, (int) $root->id], $path);
        $resolved = bindings::resolve(['courseid' => $this->course->id, 'cmid' => $label->cmid], 'anchor');
        $this->assertEquals($root->id, $resolved[0]->sectionid);
    }

    /** Both scopes display; a teacher replaces central content and maps it twice. */
    public function test_shared_content_permissions_and_repeated_node(): void {
        $one = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => 1]);
        $two = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => 2]);
        $central = bindings::bind(['courseid' => $this->course->id, 'cmid' => $one->cmid], $this->node, 'anchor', 'central');
        $this->setUser($this->teacher);
        $this->assertTrue(unbind::execute($central)['deleted']);
        $first = bind::execute($this->node, 0, (int) $this->course->id, 0, (int) $one->cmid, '', 0, 'anchor');
        $second = bind::execute($this->node, 0, (int) $this->course->id, 0, (int) $two->cmid, '', 0, 'anchor', 'central');
        $this->assertNotSame($first['id'], $second['id']);
        $again = bind::execute($this->node, 0, (int) $this->course->id, 0, (int) $one->cmid, '', 0, 'anchor');
        $this->assertSame($first['id'], $again['id']);
        [, $bycm] = contentmap::buckets((int) $this->course->id);
        $this->assertCount(1, $bycm[$one->cmid]);
        $this->assertCount(1, $bycm[$two->cmid]);
        $tree = new content_tree($this->course, '/local/curricmap/section_module_mapping.php');
        $html = $tree->children((int) get_fast_modinfo($this->course)->get_section_info(2)->id);
        $this->assertStringContainsString('Reflect on learning strategies', $html);
        $this->expectException(\required_capability_exception::class);
        bind::execute($this->node, 0, (int) $this->course->id, 0, 0, '', 0, 'anchor', 'course');
    }

    /** Teachers cannot delete the course affiliation, even through the API. */
    public function test_affiliation_deletion_is_site_only(): void {
        $anchor = bindings::for_course((int) $this->course->id)[0];
        $this->setUser($this->teacher);
        $this->expectException(\required_capability_exception::class);
        unbind::execute((int) $anchor->id);
    }

    /** A forged foreign-course module cannot be bound through an allowed course. */
    public function test_foreign_course_location_is_rejected(): void {
        $other = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);
        $this->setUser($this->teacher);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorbindaddress', 'local_curricmap'));
        bind::execute($this->node, 0, (int) $this->course->id, 0, (int) $page->cmid);
    }

    /** A teacher cannot remove mappings belonging to another course. */
    public function test_foreign_course_deletion_is_rejected(): void {
        $other = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);
        $id = bindings::bind(['courseid' => $other->id, 'cmid' => $page->cmid], $this->node, 'anchor', 'central');
        $this->setUser($this->teacher);
        $this->expectException(\moodle_exception::class);
        unbind::execute($id);
    }

    /** Students cannot create content mappings. */
    public function test_student_content_write_is_rejected(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        bind::execute($this->node, 0, (int) $this->course->id, 0, (int) $page->cmid);
    }

    /** Book descriptions, chapter and subchapter bodies are available together. */
    public function test_book_text_and_chapter_addresses(): void {
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $this->course->id, 'section' => 1,
            'intro' => '<p>Book description evidence</p>', 'introformat' => FORMAT_HTML,
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter = $generator->create_chapter(['bookid' => $book->id, 'title' => 'First chapter',
            'content' => '<p>Chapter learning text</p>', 'contentformat' => FORMAT_HTML, 'pagenum' => 1]);
        $subchapter = $generator->create_chapter(['bookid' => $book->id, 'title' => 'Subchapter',
            'content' => '<p>Subchapter learning text</p>', 'contentformat' => FORMAT_HTML,
            'pagenum' => 2, 'subchapter' => 1]);
        $this->setUser($this->teacher);
        $tree = new content_tree($this->course, '/local/curricmap/section_module_mapping.php');
        $html = $tree->children((int) get_fast_modinfo($this->course)->get_section_info(1)->id);
        foreach (['Book description evidence', 'Chapter learning text', 'Subchapter learning text'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $address = content_tree::address_for_key((int) $this->course->id, 'h' . $subchapter->id);
        $this->assertSame((int) $book->cmid, $address['cmid']);
        $this->assertSame((int) $subchapter->id, $address['subitemid']);
        $this->assertSame('mod_book', $address['component']);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $query = '//*[@id="curricmap-h' . $chapter->id . '"]//*[@id="curricmap-h' . $subchapter->id . '"]';
        $this->assertEquals(1, $xpath->query($query)->length);
    }
}
