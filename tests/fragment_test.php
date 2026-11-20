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

namespace local_curricmap;

/**
 * Exercise content fragments through Moodle's page and theme lifecycle.
 *
 * @package local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::local_curricmap_output_fragment_activities
 * @covers ::local_curricmap_output_fragment_browsenode
 */
final class fragment_test extends \advanced_testcase {
    /** @var \stdClass Test course. */
    private $course;
    /** @var \stdClass Editing teacher. */
    private $teacher;
    /** @var \section_info Delegated subsection. */
    private $section;

    /**
     * Create a nested label without changing the live Moodle site.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/external/externallib.php');
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['format' => 'topics', 'numsections' => 1]);
        $this->teacher = $generator->create_user();
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $pluginmanager = \core_plugin_manager::resolve_plugininfo_class('mod');
        $pluginmanager::enable_plugin('subsection', 1);
        $subsection = $generator->create_module('subsection', [
            'course' => $this->course->id, 'section' => 1, 'name' => 'Friday',
        ]);
        $this->section = \mod_subsection\manager::create_from_instance($subsection)->get_delegated_section_info();
        $generator->create_module('label', [
            'course' => $this->course->id, 'section' => $this->section->section,
            'intro' => '<p><strong>Nested teaching text</strong></p>', 'introformat' => FORMAT_HTML,
        ]);
        set_config('mappablemodtypes', 'label', 'local_curricmap');
    }

    /**
     * Send the same arguments as core/fragment, including Moodle's validated context.
     *
     * @param string $callback Fragment callback suffix.
     * @param array $overrides Request arguments to override.
     * @param \context|null $context Request context, defaulting to the test course.
     * @return array HTML and JavaScript from Moodle's fragment endpoint.
     */
    private function fragment(string $callback, array $overrides = [], ?\context $context = null): array {
        global $PAGE, $OUTPUT;
        // Each browser fragment load is a new HTTP request with a fresh page and renderer.
        $PAGE = new \moodle_page();
        $OUTPUT = new \core\output\bootstrap_renderer();
        $context = $context ?? \context_course::instance($this->course->id);
        $params = array_merge([
            'courseid' => $this->course->id, 'sectionid' => $this->section->id,
            'root' => '', 'key' => 's' . $this->section->id, 'grain' => 'content',
        ], $overrides);
        $args = [];
        foreach ($params as $name => $value) {
            $args[] = ['name' => $name, 'value' => (string) $value];
        }
        return \core_external::get_fragment('local_curricmap', $callback, $context->id, $args);
    }

    /**
     * Roles that can map course content.
     *
     * @return array Role cases.
     */
    public static function mapper_provider(): array {
        return ['site administrator' => [true], 'course administrator' => [false]];
    }

    /**
     * Expanding a section must work after Moodle initialises its theme.
     *
     * @dataProvider mapper_provider
     * @param bool $siteadmin Whether to use the site administrator.
     */
    public function test_activities_after_theme_initialisation(bool $siteadmin): void {
        if (!$siteadmin) {
            $this->setUser($this->teacher);
        }
        $root = get_fast_modinfo($this->course)->get_section_info(1);
        $result = $this->fragment('activities', ['sectionid' => $root->id]);
        $this->assertStringContainsString('Friday', $result['html']);
        $result = $this->fragment('activities');
        $this->assertStringContainsString('<strong>Nested teaching text</strong>', $result['html']);
        $this->assertIsString($result['javascript']);
    }

    /**
     * Browsing curriculum must use the same already-initialised course context.
     *
     * @dataProvider mapper_provider
     * @param bool $siteadmin Whether to use the site administrator.
     */
    public function test_browse_after_theme_initialisation(bool $siteadmin): void {
        if (!$siteadmin) {
            $this->setUser($this->teacher);
        }
        $result = $this->fragment('browsenode');
        $this->assertStringContainsString(get_string('contentmapping_noresults', 'local_curricmap'), $result['html']);
    }

    /**
     * An unsupported grain cannot fall back to the unrestricted curriculum browser.
     */
    public function test_unknown_grain_stays_course_scoped(): void {
        $this->setUser($this->teacher);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorbindnode', 'local_curricmap', 'unmappednode'));
        $this->fragment('browsenode', ['grain' => 'unsupported', 'root' => 'unmappednode']);
    }

    /**
     * Content callbacks that require matching course contexts.
     *
     * @return array Callback cases.
     */
    public static function callback_provider(): array {
        return ['activities' => ['activities'], 'curriculum browse' => ['browsenode']];
    }

    /**
     * A caller cannot substitute a different course after context validation.
     *
     * @dataProvider callback_provider
     * @param string $callback Fragment callback suffix.
     */
    public function test_mismatched_course_context_is_rejected(string $callback): void {
        $other = $this->getDataGenerator()->create_course();
        $this->expectException(\invalid_parameter_exception::class);
        $this->fragment($callback, ['courseid' => $other->id]);
    }

    /**
     * Course access alone does not grant mapping access.
     *
     * @dataProvider callback_provider
     * @param string $callback Fragment callback suffix.
     */
    public function test_student_cannot_load_mapping_fragment(string $callback): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        $this->fragment($callback);
    }

    /**
     * Site administrators can still browse course affiliations at system context.
     */
    public function test_course_affiliation_browse_uses_system_context(): void {
        $result = $this->fragment('browsenode', ['grain' => 'course'], \context_system::instance());
        $this->assertStringContainsString(get_string('coursemapping_browseprogrammes', 'local_curricmap'), $result['html']);
    }

    /**
     * Teachers cannot use the system-level affiliation browser.
     */
    public function test_teacher_cannot_browse_course_affiliations(): void {
        $this->setUser($this->teacher);
        $this->expectException(\required_capability_exception::class);
        $this->fragment('browsenode', ['grain' => 'course'], \context_system::instance());
    }
}
