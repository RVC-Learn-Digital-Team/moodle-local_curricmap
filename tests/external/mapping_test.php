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

use local_curricmap\local\fake_sofia_client;
use local_curricmap\local\sync;

/**
 * Tests for the mapping web-service functions (the contract the external
 * mapping API consumers code against).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_curricmap\external\bind
 * @covers    \local_curricmap\external\unbind
 * @covers    \local_curricmap\external\resolve
 * @covers    \local_curricmap\external\list_bindings
 * @covers    \local_curricmap\external\add_resource
 * @covers    \local_curricmap\external\delete_resource
 * @covers    \local_curricmap\external\list_resources
 * @covers    \local_curricmap\external\list_resource_types
 */
final class mapping_test extends \advanced_testcase {
    /** @var string Year 1 uuid. */
    const YEAR_UUID = 'e1e70820-6c4b-4ddf-a478-7c1b2db0cabe';

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
     * The full bind / resolve / list / unbind round trip as admin.
     */
    public function test_binding_round_trip(): void {
        $this->sync_fixture();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $bound = bind::execute($this->key(self::LOCO_UUID), 0, (int) $course->id, 0, 0, '', 0, 'anchor');
        $bound = \core_external\external_api::clean_returnvalue(bind::execute_returns(), $bound);
        $this->assertGreaterThan(0, $bound['id']);

        // Idempotent: same call returns the same id.
        $again = bind::execute($this->key(self::LOCO_UUID), 0, (int) $course->id, 0, 0, '', 0, 'anchor');
        $this->assertSame($bound['id'], $again['id']);

        $resolved = resolve::execute(0, (int) $course->id, 0, 0, '', 0, 'anchor');
        $resolved = \core_external\external_api::clean_returnvalue(resolve::execute_returns(), $resolved);
        $this->assertCount(1, $resolved);
        $this->assertSame($this->key(self::LOCO_UUID), $resolved[0]['nodeuuid']);
        $this->assertSame('Locomotor', $resolved[0]['node']['title']);

        $listed = list_bindings::execute((int) $course->id);
        $listed = \core_external\external_api::clean_returnvalue(list_bindings::execute_returns(), $listed);
        $this->assertCount(1, $listed);

        $bynode = list_bindings::execute(0, $this->key(self::LOCO_UUID));
        $bynode = \core_external\external_api::clean_returnvalue(list_bindings::execute_returns(), $bynode);
        $this->assertCount(1, $bynode);
        $this->assertSame($bound['id'], $bynode[0]['id']);

        $deleted = unbind::execute($bound['id']);
        $deleted = \core_external\external_api::clean_returnvalue(unbind::execute_returns(), $deleted);
        $this->assertTrue($deleted['deleted']);
        $this->assertSame([], list_bindings::execute((int) $course->id));
    }

    /**
     * Course staff can bind course scope; central scope needs system capability.
     */
    public function test_bind_permissions(): void {
        $this->sync_fixture();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setUser($teacher);
        $bound = bind::execute($this->key(self::LOCO_UUID), 0, (int) $course->id);
        $this->assertGreaterThan(0, $bound['id']);

        $this->expectException(\required_capability_exception::class);
        bind::execute($this->key(self::YEAR_UUID), 0, (int) $course->id, 0, 0, '', 0, 'related', 'central');
    }

    /**
     * Resources: add, list by node and by type (course-scoped and not), delete,
     * and the suggested-type vocabulary.
     */
    public function test_resources_round_trip(): void {
        $this->sync_fixture();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $loco = $this->key(self::LOCO_UUID);

        $inst = add_resource::execute($loco, 'Panopto', 'Gait lecture', 'https://rvc.cloud.panopto.eu/v/1');
        $mine = add_resource::execute($loco, 'Ebook', 'Course text', 'https://example.com/book', (int) $course->id);
        add_resource::execute($this->key(self::YEAR_UUID), 'panopto', 'Intro', 'https://rvc.cloud.panopto.eu/v/2');

        // By node, course scope pulls institutional + course rows.
        $found = list_resources::execute($loco, '', (int) $course->id);
        $found = \core_external\external_api::clean_returnvalue(list_resources::execute_returns(), $found);
        $this->assertCount(2, $found);

        // By type, case-insensitive, across nodes; institutional only without a course.
        $found = list_resources::execute('', 'panopto');
        $found = \core_external\external_api::clean_returnvalue(list_resources::execute_returns(), $found);
        $this->assertCount(2, $found);
        $labels = array_map(fn($r) => $r['label'], $found);
        $this->assertContains('Gait lecture', $labels);
        $this->assertNotContains('Course text', $labels);

        // By node AND type with course scope.
        $found = list_resources::execute($loco, 'ebook', (int) $course->id);
        $this->assertCount(1, $found);

        // A filter is required.
        try {
            list_resources::execute();
            $this->fail('Filterless listing must be rejected.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        set_config('resourcetypes', 'Panopto, PebblePad, Document, Ebook, Image, Link', 'local_curricmap');
        $types = list_resource_types::execute();
        $types = \core_external\external_api::clean_returnvalue(list_resource_types::execute_returns(), $types);
        $this->assertSame(['Panopto', 'PebblePad', 'Document', 'Ebook', 'Image', 'Link'], $types);

        $deleted = delete_resource::execute($mine['id']);
        $this->assertTrue($deleted['deleted']);
        $this->assertCount(1, list_resources::execute($loco, '', (int) $course->id));
        $this->assertGreaterThan(0, $inst['id']);
    }

    /**
     * The declared service exposes the agreed function set.
     */
    public function test_declared_service(): void {
        $functions = null;
        $services = null;
        require(__DIR__ . '/../../db/services.php');
        $this->assertArrayHasKey('Curriculum mapping API', $services);
        $service = $services['Curriculum mapping API'];
        $this->assertSame('curricmap_mapping', $service['shortname']);
        $this->assertSame(1, $service['restrictedusers']);
        $required = ['local_curricmap_bind', 'local_curricmap_resolve', 'local_curricmap_list_resources',
            'core_course_get_contents'];
        foreach ($required as $expected) {
            $this->assertContains($expected, $service['functions']);
        }
        foreach ($service['functions'] as $name) {
            if (strpos($name, 'local_curricmap_') === 0) {
                $this->assertArrayHasKey($name, $functions, "Service references undeclared function $name.");
            }
        }
    }
}
