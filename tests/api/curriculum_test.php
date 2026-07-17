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

require_once(__DIR__ . '/../fixtures/fake_sofia_client.php');

use local_curricmap\local\fake_sofia_client;
use local_curricmap\local\sync;

/**
 * Tests for the curriculum query service and its external functions, against
 * the synced revision-A fixture corpus.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_curricmap\api\curriculum
 * @covers    \local_curricmap\external\get_children
 * @covers    \local_curricmap\external\get_programmes
 * @covers    \local_curricmap\external\search
 */
final class curriculum_test extends \advanced_testcase {
    /** @var string Year 1 uuid. */
    const YEAR_UUID = 'e1e70820-6c4b-4ddf-a478-7c1b2db0cabe';

    /** @var string Locomotor strand uuid. */
    const LOCO_UUID = '15629971-00d5-428a-944e-e94142c86088';

    /** @var string A session outcome implementing two strand outcomes. */
    const URINARY_LO4_UUID = 'ec917dc5-4dc3-4d58-b619-b2921eef1976';

    /** @var string First implements target of URINARY_LO4 (strand outcome LO58). */
    const URINARY_LO58_UUID = '091a0a73-331e-45b0-9f50-9e3503217602';

    /** @var string Second implements target of URINARY_LO4 (strand outcome LO59). */
    const URINARY_LO59_UUID = 'd7541e7f-04a8-4e65-8bfa-25138e2c107f';

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
     * The whole query surface against revision A.
     */
    public function test_query_surface(): void {
        $programme = $this->sync_fixture('a', 'aaaa1111');

        // Years and strands, in sibling order.
        $years = curriculum::years($programme->id);
        $this->assertCount(1, $years);
        $this->assertSame($this->key(self::YEAR_UUID), $years[0]->uuid);

        $strands = curriculum::strands($this->key(self::YEAR_UUID));
        $this->assertCount(14, $strands);
        $sortorders = array_map(fn($s) => (int) $s->sortorder, $strands);
        $sorted = $sortorders;
        sort($sorted);
        $this->assertSame($sorted, $sortorders, 'Strands come back in sibling order.');

        // Locomotor: outcomes, sessions, subtype filter, no unit labels.
        $outcomes = curriculum::strand_outcomes($this->key(self::LOCO_UUID));
        $this->assertCount(3, $outcomes);

        $sessions = curriculum::sessions($this->key(self::LOCO_UUID));
        $this->assertCount(23, $sessions);
        $lectures = curriculum::sessions($this->key(self::LOCO_UUID), null, 'Lecture');
        $this->assertCount(11, $lectures);
        $this->assertSame([], curriculum::units($this->key(self::LOCO_UUID)), 'Locomotor has no unit labels.');

        // Animal Husbandry: unit labels in first-appearance order.
        $ah = null;
        foreach ($strands as $strand) {
            if ($strand->code === 'UG1-AH') {
                $ah = $strand;
            }
        }
        $this->assertNotNull($ah);
        $units = curriculum::units($ah->uuid);
        $this->assertNotEmpty($units);
        $this->assertSame('Unit 1: Animal Management', $units[0]['grouplabel']);
        $unit1 = curriculum::sessions($ah->uuid, 'Unit 1: Animal Management');
        $this->assertCount($units[0]['sessioncount'], $unit1);

        // Session outcomes and traceability, in connection order.
        $lo4targets = curriculum::implements_targets($this->key(self::URINARY_LO4_UUID));
        $expectedtargets = [$this->key(self::URINARY_LO58_UUID), $this->key(self::URINARY_LO59_UUID)];
        $this->assertSame($expectedtargets, array_map(fn($n) => $n->uuid, $lo4targets));

        $implementers = curriculum::implemented_by($this->key(self::URINARY_LO58_UUID));
        $this->assertNotEmpty($implementers);
        $this->assertContains($this->key(self::URINARY_LO4_UUID), array_map(fn($n) => $n->uuid, $implementers));

        // Tags with display names from the synced schema.
        $lo58tags = curriculum::tags($this->key(self::URINARY_LO58_UUID));
        $fieldkeys = array_column($lo58tags, 'fieldkey');
        $this->assertContains('RCVS_DAY_ONE_COMPETENCIES', $fieldkeys);

        $schema = curriculum::tag_schema($programme->id);
        $this->assertCount(10, $schema);

        // Subtree via the materialised path: Locomotor strand + 101 descendants.
        $subtree = curriculum::subtree($this->key(self::LOCO_UUID));
        $this->assertSame($this->key(self::LOCO_UUID), $subtree[0]->uuid);
        $this->assertCount(102, $subtree);
        $this->assertCount(28, curriculum::subtree($this->key(self::LOCO_UUID), 2), 'Depth-limited subtree.');

        // Search is ranked: a whole-code query puts the exact code first
        // (partial code matches now follow instead of being excluded), and
        // the tightest title match tops a plain word query.
        $found = curriculum::search($programme->id, 'locomotor');
        $this->assertContains($this->key(self::LOCO_UUID), array_map(fn($n) => $n->uuid, $found));
        $this->assertSame($this->key(self::LOCO_UUID), $found[0]->uuid, 'The strand titled Locomotor ranks first.');
        $found = curriculum::search($programme->id, 'ug1-loco-lo32');
        $this->assertNotEmpty($found);
        $this->assertSame(0, strcasecmp('ug1-loco-lo32', (string) $found[0]->code));
        $this->assertSame([], curriculum::search($programme->id, '  '));

        // A code's final segment alone finds it (LO codes are quotable).
        $found = curriculum::search($programme->id, 'lo32');
        $this->assertNotEmpty($found);
        $this->assertSame(0, strcasecmp('ug1-loco-lo32', (string) $found[0]->code));

        // Synonyms reach typed search: "locomotion" ranks the Locomotor
        // strand first even though no title contains the typed word.
        $found = curriculum::search($programme->id, 'locomotion');
        $this->assertNotEmpty($found);
        $this->assertSame($this->key(self::LOCO_UUID), $found[0]->uuid);

        // Subtree-restricted search (the picker strict lock): the Locomotor
        // code hits inside its own subtree and under the whole year, but not
        // under a sibling strand; the ancestor itself is included.
        $limit = curriculum::SEARCH_LIMIT;
        $found = curriculum::search($programme->id, 'ug1-loco-lo32', null, $limit, $this->key(self::LOCO_UUID));
        $this->assertNotEmpty($found);
        $this->assertSame(0, strcasecmp('ug1-loco-lo32', (string) $found[0]->code));
        $found = curriculum::search($programme->id, 'ug1-loco-lo32', null, $limit, $this->key(self::YEAR_UUID));
        $this->assertNotEmpty($found);
        $this->assertSame(0, strcasecmp('ug1-loco-lo32', (string) $found[0]->code));
        $found = curriculum::search($programme->id, 'ug1-loco-lo32', null, $limit, $this->key(self::TESTFOLDER_UUID));
        $this->assertSame([], $found);
        $found = curriculum::search($programme->id, 'locomotor', null, $limit, $this->key(self::LOCO_UUID));
        $this->assertContains($this->key(self::LOCO_UUID), array_map(fn($n) => $n->uuid, $found));
        $this->assertSame([], curriculum::search($programme->id, 'locomotor', null, $limit, 'nope_missing'));
    }

    /**
     * Soft-deleted nodes vanish from lists but stay resolvable via node(), and
     * cached results do not survive a revision change.
     */
    public function test_soft_delete_and_cache_invalidation(): void {
        $programme = $this->sync_fixture('a', 'aaaa1111');

        $topfolders = curriculum::node($this->key(self::TESTFOLDER_UUID));
        $this->assertSame(0, (int) $topfolders->deleted);
        $countbefore = count(curriculum::children($this->key(self::YEAR_UUID)));

        // Prime the cache with revision-A results, then move to revision B.
        $this->assertCount(14, curriculum::strands($this->key(self::YEAR_UUID)));
        $this->sync_fixture('b', 'bbbb2222', $programme);

        $folder = curriculum::node($this->key(self::TESTFOLDER_UUID));
        $this->assertSame(1, (int) $folder->deleted, 'node() resolves soft-deleted rows, flagged.');
        $this->assertSame('Test Folder', $folder->title);

        // The strand list is re-read (new revision stamp), not served stale.
        $this->assertCount(14, curriculum::strands($this->key(self::YEAR_UUID)));
        $this->assertSame($countbefore, count(curriculum::children($this->key(self::YEAR_UUID))));

        // Unknown uuid resolves to null and empty lists.
        $this->assertNull(curriculum::node('00000000-0000-0000-0000-000000000000'));
        $this->assertSame([], curriculum::children('00000000-0000-0000-0000-000000000000'));
    }

    /**
     * Programmes list alphabetically: slug, then academic-year version.
     */
    public function test_programmes_ordering(): void {
        global $DB;
        $rows = [['vet-med', '2027'], ['vet-med', '2026'], ['bio-sc', '2024'], ['bio-sc', '2022']];
        foreach ($rows as [$slug, $year]) {
            $record = (object) [
                'slug' => $slug,
                'versionlabel' => $year,
                'enabled' => 1,
                'lastsyncstatus' => 'never',
            ];
            $DB->insert_record('local_curricmap_programme', $record);
        }
        $ordered = array_map(fn($p) => "{$p->slug} {$p->versionlabel}", array_values(curriculum::programmes()));
        $this->assertSame(['bio-sc 2022', 'bio-sc 2024', 'vet-med 2026', 'vet-med 2027'], $ordered);
    }

    /**
     * External functions: capability enforcement in course context and the
     * exported node shape.
     */
    public function test_external_functions(): void {
        $programme = $this->sync_fixture('a', 'aaaa1111');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        $this->setUser($teacher);
        $programmes = \local_curricmap\external\get_programmes::execute($course->id);
        $this->assertCount(1, $programmes);
        $this->assertSame('vet-med', $programmes[0]['slug']);

        $years = \local_curricmap\external\get_children::execute($course->id, $programme->id, '');
        $this->assertCount(1, $years);
        $this->assertSame('year', $years[0]['role']);
        $this->assertTrue($years[0]['haschildren']);

        // With strands interleaved: each year followed by its strands (the
        // picker's starting points — strand-shaped courses map to a strand).
        $withstrands = \local_curricmap\external\get_children::execute($course->id, $programme->id, '', true);
        $this->assertCount(15, $withstrands);
        $this->assertSame('year', $withstrands[0]['role']);
        $this->assertSame('strand', $withstrands[1]['role']);
        $strandtitles = array_column(array_filter($withstrands, fn($n) => $n['role'] === 'strand'), 'title');
        $this->assertContains('Locomotor', $strandtitles);

        $children = \local_curricmap\external\get_children::execute($course->id, $programme->id, $this->key(self::LOCO_UUID));
        $this->assertCount(27, $children);

        $found = \local_curricmap\external\search::execute($course->id, $programme->id, 'Locomotor');
        $this->assertNotEmpty($found);
        $this->assertSame('strand', $found[0]['role']);

        // Students hold no viewstaffmeta capability: refused.
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        \local_curricmap\external\get_programmes::execute($course->id);
    }

    /**
     * Graph extraction: full node dump with reconstructable tree, paging,
     * subtree restriction, deleted flags and the edge list.
     */
    public function test_graph_export(): void {
        global $DB;
        $programme = $this->sync_fixture('a', 'aaaa1111');
        $this->setAdminUser();

        $export = \local_curricmap\external\get_nodes::execute($programme->id);
        $export = \core_external\external_api::clean_returnvalue(
            \local_curricmap\external\get_nodes::execute_returns(),
            $export
        );
        $activecount = $DB->count_records('local_curricmap_node', ['programmeid' => $programme->id, 'deleted' => 0]);
        $this->assertSame($activecount, $export['total']);
        $this->assertCount($activecount, $export['nodes']);
        $this->assertSame('vet-med', $export['programme']['slug']);
        $this->assertSame('aaaa1111', $export['programme']['revisionhash']);

        // The tree is reconstructable offline: every parent link resolves
        // within the full export; top-level rows carry none.
        $byuuid = array_column($export['nodes'], null, 'uuid');
        foreach ($export['nodes'] as $node) {
            if (isset($node['parentuuid'])) {
                $this->assertArrayHasKey($node['parentuuid'], $byuuid);
            }
        }
        $this->assertArrayNotHasKey('parentuuid', $byuuid[$this->key(self::YEAR_UUID)]);
        $this->assertSame($this->key(self::YEAR_UUID), $byuuid[$this->key(self::LOCO_UUID)]['parentuuid']);

        // Paging slices without losing the total; parents outside the page
        // still resolve to keys.
        $page = \local_curricmap\external\get_nodes::execute($programme->id, '', false, 5, 10);
        $this->assertCount(10, $page['nodes']);
        $this->assertSame($activecount, $page['total']);

        // Subtree restriction: Locomotor and below only.
        $subtree = \local_curricmap\external\get_nodes::execute($programme->id, $this->key(self::LOCO_UUID));
        $this->assertLessThan($activecount, $subtree['total']);
        $subuuids = array_column($subtree['nodes'], 'uuid');
        $this->assertContains($this->key(self::LOCO_UUID), $subuuids);
        $this->assertNotContains($this->key(self::YEAR_UUID), $subuuids);

        // Edges, with the known implements pair, and the type filter.
        $edges = \local_curricmap\external\get_edges::execute($programme->id);
        $edges = \core_external\external_api::clean_returnvalue(
            \local_curricmap\external\get_edges::execute_returns(),
            $edges
        );
        $this->assertNotEmpty($edges);
        $pairs = array_map(fn($e) => "{$e['sourceuuid']}>{$e['targetuuid']}:{$e['connectiontype']}", $edges);
        $expected = $this->key(self::URINARY_LO4_UUID) . '>' . $this->key(self::URINARY_LO58_UUID) . ':implements';
        $this->assertContains($expected, $pairs);
        $implements = \local_curricmap\external\get_edges::execute($programme->id, 'implements');
        $this->assertNotEmpty($implements);
        $this->assertLessThanOrEqual(count($edges), count($implements));

        // Soft-deleted rows appear only on request, flagged.
        $this->sync_fixture('b', 'bbbb2222', $programme);
        $withoutdeleted = \local_curricmap\external\get_nodes::execute($programme->id);
        $this->assertNotContains($this->key(self::TESTFOLDER_UUID), array_column($withoutdeleted['nodes'], 'uuid'));
        $withdeleted = \local_curricmap\external\get_nodes::execute($programme->id, '', true);
        $deletedbyuuid = array_column($withdeleted['nodes'], null, 'uuid');
        $this->assertArrayHasKey($this->key(self::TESTFOLDER_UUID), $deletedbyuuid);
        $this->assertNotEmpty($deletedbyuuid[$this->key(self::TESTFOLDER_UUID)]['deleted']);
    }

    /**
     * Graph extraction is system-level staff/integration territory: a
     * course-level teacher is refused.
     */
    public function test_graph_export_permissions(): void {
        $programme = $this->sync_fixture('a', 'aaaa1111');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        \local_curricmap\external\get_nodes::execute($programme->id);
    }
}
