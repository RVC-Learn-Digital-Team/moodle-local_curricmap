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

namespace local_curricmap\api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/../fixtures/fake_sofia_client.php');

use local_curricmap\local\fake_sofia_client;
use local_curricmap\local\sync;

/**
 * Tests for the binding (mapping) and node-resource services, against the
 * synced revision-A fixture corpus and generated Moodle structure.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_curricmap\api\bindings
 * @covers    \local_curricmap\api\resources
 * @covers    \local_curricmap\observer
 */
final class bindings_test extends \advanced_testcase {
    /** @var string Year 1 uuid. */
    const YEAR_UUID = 'e1e70820-6c4b-4ddf-a478-7c1b2db0cabe';

    /** @var string Locomotor strand uuid. */
    const LOCO_UUID = '15629971-00d5-428a-944e-e94142c86088';

    /** @var string A session outcome deep in the tree. */
    const URINARY_LO4_UUID = 'ec917dc5-4dc3-4d58-b619-b2921eef1976';

    /** @var string Test Folder uuid (removed in revision B). */
    const TESTFOLDER_UUID = 'bb4e6dea-a72b-4785-9918-8093395174cd';

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
     * Sync a fixture revision and return the programme record.
     *
     * @param string $revision Fixture revision letter.
     * @param string $hash Revision hash to report.
     * @param \stdClass|null $programme Existing programme to reuse.
     * @return \stdClass
     */
    private function sync_fixture(string $revision, string $hash, ?\stdClass $programme = null): \stdClass {
        global $DB;
        if ($programme === null) {
            $programme = new \stdClass();
            $programme->slug = 'vet-med';
            $programme->versionlabel = 'LATEST';
            $programme->enabled = 1;
            $programme->lastsyncstatus = 'never';
            $programme->id = $DB->insert_record('local_curricmap_programme', $programme);
        }
        $nodes = json_decode(file_get_contents(__DIR__ . "/../fixtures/vetmed_{$revision}_nodes.json"), true);
        $metadata = json_decode(file_get_contents(__DIR__ . "/../fixtures/vetmed_{$revision}_metadata.json"), true);
        $engine = new sync(new fake_sofia_client($nodes, $metadata, $hash));
        $engine->sync_programme($programme);
        return $DB->get_record('local_curricmap_programme', ['id' => $programme->id], '*', MUST_EXIST);
    }

    /**
     * A generated category > subcategory > course > section > module ladder.
     *
     * @return \stdClass Ids: parentcat, childcat, course, sectionid, cmid.
     */
    private function make_ladder(): \stdClass {
        global $DB;
        $generator = $this->getDataGenerator();
        $out = new \stdClass();
        $parentcat = $generator->create_category();
        $childcat = $generator->create_category(['parent' => $parentcat->id]);
        $course = $generator->create_course(['category' => $childcat->id, 'numsections' => 3]);
        $module = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $out->parentcat = (int) $parentcat->id;
        $out->childcat = (int) $childcat->id;
        $out->course = (int) $course->id;
        $out->sectionid = (int) $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1]);
        $out->cmid = (int) $module->cmid;
        return $out;
    }

    /**
     * Bind at every depth; resolve returns only the deepest level with rows,
     * walking up to ancestor categories when nothing deeper matches.
     */
    public function test_bind_and_resolve_deepest_wins(): void {
        $this->sync_fixture('a', 'aaaa1111');
        $l = $this->make_ladder();

        bindings::bind(['categoryid' => $l->parentcat], $this->key(self::YEAR_UUID));
        bindings::bind(['categoryid' => $l->childcat], $this->key(self::LOCO_UUID));
        bindings::bind(['courseid' => $l->course], $this->key(self::LOCO_UUID));
        bindings::bind(['courseid' => $l->course, 'sectionid' => $l->sectionid], $this->key(self::URINARY_LO4_UUID));
        bindings::bind(['courseid' => $l->course, 'cmid' => $l->cmid], $this->key(self::TESTFOLDER_UUID));
        $subaddress = ['courseid' => $l->course, 'cmid' => $l->cmid, 'component' => 'mod_book', 'subitemid' => 7];
        bindings::bind($subaddress, $this->key(self::YEAR_UUID));

        // Sub-activity beats cm.
        $location = ['courseid' => $l->course, 'cmid' => $l->cmid, 'component' => 'mod_book', 'subitemid' => 7];
        $resolved = bindings::resolve($location);
        $this->assertCount(1, $resolved);
        $this->assertSame($this->key(self::YEAR_UUID), $resolved[0]->nodeuuid);
        $this->assertSame('Year 1', $resolved[0]->node->title);

        // Cm beats section; section beats course; course beats category.
        $resolved = bindings::resolve(['courseid' => $l->course, 'cmid' => $l->cmid]);
        $this->assertSame($this->key(self::TESTFOLDER_UUID), $resolved[0]->nodeuuid);
        $resolved = bindings::resolve(['courseid' => $l->course, 'sectionid' => $l->sectionid]);
        $this->assertSame($this->key(self::URINARY_LO4_UUID), $resolved[0]->nodeuuid);
        $resolved = bindings::resolve(['courseid' => $l->course]);
        $this->assertSame($this->key(self::LOCO_UUID), $resolved[0]->nodeuuid);

        // With no course-level rows the nearest category answers, then its parent.
        foreach (bindings::resolve(['courseid' => $l->course]) as $binding) {
            bindings::unbind((int) $binding->id);
        }
        $resolved = bindings::resolve(['courseid' => $l->course]);
        $this->assertSame($this->key(self::LOCO_UUID), $resolved[0]->nodeuuid, 'Nearest category answers.');
        $this->assertNotNull($resolved[0]->categoryid);
        $this->assertEquals($l->childcat, $resolved[0]->categoryid);
        foreach (bindings::resolve(['categoryid' => $l->childcat]) as $binding) {
            bindings::unbind((int) $binding->id);
        }
        $resolved = bindings::resolve(['courseid' => $l->course]);
        $this->assertEquals($l->parentcat, $resolved[0]->categoryid, 'Ancestor category answers.');
        $this->assertSame($this->key(self::YEAR_UUID), $resolved[0]->nodeuuid);
    }

    /**
     * Anchors come back in sortorder; bind is idempotent on address+node+relation.
     */
    public function test_anchors_and_idempotency(): void {
        $this->sync_fixture('a', 'aaaa1111');
        $l = $this->make_ladder();
        $address = ['courseid' => $l->course];

        $second = bindings::bind($address, $this->key(self::LOCO_UUID), bindings::RELATION_ANCHOR, 'course', 2);
        $first = bindings::bind($address, $this->key(self::YEAR_UUID), bindings::RELATION_ANCHOR, 'course', 1);

        $anchors = bindings::anchors($l->course);
        $this->assertCount(2, $anchors);
        $this->assertSame($this->key(self::YEAR_UUID), $anchors[0]->uuid);
        $this->assertSame($this->key(self::LOCO_UUID), $anchors[1]->uuid);

        // Same address+node+relation returns the existing id, different relation is a new row.
        $again = bindings::bind($address, $this->key(self::LOCO_UUID), bindings::RELATION_ANCHOR, 'course', 9);
        $this->assertSame($second, $again);
        $related = bindings::bind($address, $this->key(self::LOCO_UUID), bindings::RELATION_RELATED);
        $this->assertNotSame($second, $related);
        $this->assertCount(3, bindings::for_course($l->course));
        $this->assertCount(2, bindings::for_node($this->key(self::LOCO_UUID)));
        $this->assertCount(1, bindings::for_node($this->key(self::LOCO_UUID), bindings::RELATION_ANCHOR));
        $this->assertGreaterThan(0, $first);
    }

    /**
     * Address validation: category or course required; section/cm need a
     * course; subitemid and component travel together; node must exist.
     */
    public function test_bind_validation(): void {
        $this->sync_fixture('a', 'aaaa1111');
        $l = $this->make_ladder();

        try {
            bindings::bind([], $this->key(self::YEAR_UUID));
            $this->fail('Empty address must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorbindaddress', $e->errorcode);
        }
        try {
            bindings::bind(['categoryid' => $l->childcat, 'sectionid' => $l->sectionid], $this->key(self::YEAR_UUID));
            $this->fail('Section without course must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorbindaddress', $e->errorcode);
        }
        try {
            bindings::bind(['courseid' => $l->course, 'cmid' => $l->cmid, 'subitemid' => 3], $this->key(self::YEAR_UUID));
            $this->fail('Subitemid without component must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorbindaddress', $e->errorcode);
        }
        try {
            bindings::bind(['courseid' => $l->course], 'vet-med_latest_no-such-node');
            $this->fail('Unknown node must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorbindnode', $e->errorcode);
        }
    }

    /**
     * Course-scope management follows the course context capability; central
     * scope requires it at system context.
     */
    public function test_can_manage(): void {
        $this->sync_fixture('a', 'aaaa1111');
        $l = $this->make_ladder();
        $generator = $this->getDataGenerator();
        $address = bindings::normalise_address(['courseid' => $l->course]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $l->course, 'editingteacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $l->course, 'student');
        $manager = $generator->create_user();
        $managerrole = $generator->create_role(['archetype' => 'manager']);
        role_assign($managerrole, $manager->id, \context_system::instance()->id);

        $this->assertTrue(bindings::can_manage($address, 'course', $teacher->id));
        $this->assertFalse(bindings::can_manage($address, 'central', $teacher->id));
        $this->assertFalse(bindings::can_manage($address, 'course', $student->id));
        $this->assertTrue(bindings::can_manage($address, 'course', $manager->id));
        $this->assertTrue(bindings::can_manage($address, 'central', $manager->id));

        $cataddress = bindings::normalise_address(['categoryid' => $l->parentcat]);
        $this->assertFalse(bindings::can_manage($cataddress, 'course', $teacher->id));
        $this->assertTrue(bindings::can_manage($cataddress, 'course', $manager->id));
    }

    /**
     * Deleting the Moodle end marks bindings orphaned via the event observers;
     * a node soft-deleted by sync surfaces through orphaned() too.
     */
    public function test_orphaning(): void {
        $programme = $this->sync_fixture('a', 'aaaa1111');
        $l = $this->make_ladder();

        $cmbinding = bindings::bind(['courseid' => $l->course, 'cmid' => $l->cmid], $this->key(self::LOCO_UUID));
        $folderbinding = bindings::bind(['courseid' => $l->course], $this->key(self::TESTFOLDER_UUID));
        $this->assertSame([], bindings::orphaned());

        // Observer path: deleting the module orphans its binding.
        course_delete_module($l->cmid);
        $orphans = bindings::orphaned($l->course);
        $this->assertCount(1, $orphans);
        $this->assertEquals($cmbinding, $orphans[0]->id);
        $this->assertSame('orphaned', $orphans[0]->status);

        // Resolve ignores orphaned rows: the course-level binding still answers.
        $resolved = bindings::resolve(['courseid' => $l->course, 'cmid' => $l->cmid]);
        $this->assertSame($this->key(self::TESTFOLDER_UUID), $resolved[0]->nodeuuid);

        // Sync revision B soft-deletes the Test Folder node; its binding is orphaned.
        $this->sync_fixture('b', 'bbbb2222', $programme);
        $orphanids = array_map(fn($o) => (int) $o->id, bindings::orphaned($l->course));
        $this->assertContains($folderbinding, $orphanids);

        // And binding a soft-deleted node is refused.
        $this->expectException(\moodle_exception::class);
        bindings::bind(['courseid' => $l->course], $this->key(self::TESTFOLDER_UUID));
    }

    /**
     * Node resources: idempotent add, course scoping, bulk fetch, vocabulary.
     */
    public function test_resources(): void {
        $this->sync_fixture('a', 'aaaa1111');
        $l = $this->make_ladder();
        $other = (int) $this->getDataGenerator()->create_course()->id;
        $loco = $this->key(self::LOCO_UUID);
        $year = $this->key(self::YEAR_UUID);

        $inst = resources::add($loco, 'panopto', 'Gait analysis lecture', 'https://rvc.cloud.panopto.eu/v/1');
        $mine = resources::add($loco, 'link', 'Course notes', 'https://example.com/notes', $l->course);
        resources::add($loco, 'ebook', 'Elsewhere', 'https://example.com/other', $other);
        resources::add($year, 'link', 'Year handbook', 'https://example.com/handbook');

        // Idempotent on node+url+scope; a different course scope is a new row.
        $this->assertSame($inst, resources::add($loco, 'panopto', 'Renamed', 'https://rvc.cloud.panopto.eu/v/1'));
        $this->assertNotSame($inst, resources::add(
            $loco,
            'panopto',
            'Same url, course copy',
            'https://rvc.cloud.panopto.eu/v/1',
            $l->course
        ));

        // Institutional only without a course; institutional + own with one.
        $institutional = resources::for_node($loco);
        $this->assertCount(1, $institutional);
        $this->assertSame('Gait analysis lecture', $institutional[0]->label);
        $incourse = resources::for_node($loco, $l->course);
        $this->assertCount(3, $incourse);
        $labels = array_map(fn($r) => $r->label, $incourse);
        $this->assertContains('Course notes', $labels);
        $this->assertNotContains('Elsewhere', $labels);

        // Bulk map for renderers.
        $map = resources::for_nodes([$loco, $year], $l->course);
        $this->assertCount(3, $map[$loco]);
        $this->assertCount(1, $map[$year]);
        $this->assertSame([], resources::for_nodes([]));

        // Vocabulary comes from the setting, falling back to the seeded list.
        $this->assertSame(['panopto', 'pebblepad', 'ebook', 'images', 'link'], resources::suggested_types());
        set_config('resourcetypes', 'panopto, sway , ,ebook', 'local_curricmap');
        $this->assertSame(['panopto', 'sway', 'ebook'], resources::suggested_types());

        resources::delete($mine);
        $this->assertCount(2, resources::for_node($loco, $l->course));
    }

    /**
     * Hidden resources are kept but excluded from viewer reads unless a
     * management surface asks for them.
     */
    public function test_resource_visibility(): void {
        $this->sync_fixture('a', 'aaaa1111');
        $l = $this->make_ladder();
        $loco = $this->key(self::LOCO_UUID);

        $mine = resources::add($loco, 'link', 'Course notes', 'https://example.com/notes', $l->course);
        resources::add($loco, 'panopto', 'Gait analysis lecture', 'https://rvc.cloud.panopto.eu/v/1');

        resources::set_visible($mine, false);

        // Viewer reads skip the hidden row on every read path.
        $labels = array_map(fn($r) => $r->label, resources::for_node($loco, $l->course));
        $this->assertSame(['Gait analysis lecture'], array_values($labels));
        $this->assertCount(1, resources::query($loco, null, $l->course));
        $map = resources::for_nodes([$loco], $l->course);
        $this->assertCount(1, $map[$loco]);

        // Management surfaces see it, flagged.
        $all = resources::for_node($loco, $l->course, true);
        $this->assertCount(2, $all);
        $hidden = array_values(array_filter($all, fn($r) => empty($r->visible)));
        $this->assertCount(1, $hidden);
        $this->assertSame('Course notes', $hidden[0]->label);
        $this->assertCount(2, resources::query($loco, null, $l->course, true));
        $this->assertCount(2, resources::for_nodes([$loco], $l->course, true)[$loco]);

        resources::set_visible($mine, true);
        $this->assertCount(2, resources::for_node($loco, $l->course));
    }

    /**
     * Course-scoped resource management is the editing-teacher surface;
     * institutional rows stay central.
     */
    public function test_resources_can_manage(): void {
        $this->sync_fixture('a', 'aaaa1111');
        $l = $this->make_ladder();
        $generator = $this->getDataGenerator();

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $l->course, 'editingteacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $l->course, 'student');
        $manager = $generator->create_user();
        $managerrole = $generator->create_role(['archetype' => 'manager']);
        role_assign($managerrole, $manager->id, \context_system::instance()->id);

        // Course scope: the editing teacher's own material.
        $this->assertTrue(resources::can_manage($l->course, $teacher->id));
        $this->assertFalse(resources::can_manage($l->course, $student->id));
        $this->assertTrue(resources::can_manage($l->course, $manager->id));

        // Institutional scope: central only.
        $this->assertFalse(resources::can_manage(null, $teacher->id));
        $this->assertTrue(resources::can_manage(null, $manager->id));
    }
}
