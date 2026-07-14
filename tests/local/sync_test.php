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

require_once(__DIR__ . '/../fixtures/fake_sofia_client.php');

/**
 * Golden-master tests for the sync engine against the recorded revision corpus
 * (A -> B -> C, with human-verified deltas; see tests/fixtures/README.md).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_curricmap\local\sync
 */
final class sync_test extends \advanced_testcase {
    /** @var string Revision hash of fixture A. */
    const HASH_A = '05f4c82da705350f369e8782cf8a4db55adc87f0';

    /** @var string Revision hash of fixture B. */
    const HASH_B = '10fed6689f116b04977dfc8baa64e5f06aed67f3';

    /** @var string Revision hash of fixture C. */
    const HASH_C = '12ac3c30e3489c886a5c0d12606757b8770d3893';

    /** @var string UUID of the "Test Unit" artifact. */
    const TESTUNIT_UUID = 'a783486f-5409-40ce-820e-d5b0d90e96a6';

    /** @var string UUID of the "Test Event" artifact. */
    const TESTEVENT_UUID = 'c831e424-4502-49b2-a92f-00e662a7820a';

    /** @var string UUID of the "Test Outcome" artifact (removed in B). */
    const TESTOUTCOME_UUID = '3fbfe142-fe51-4c09-9866-5e83b52401ce';

    /** @var string UUID of the original "Test Folder" (removed in B). */
    const TESTFOLDER_UUID = 'bb4e6dea-a72b-4785-9918-8093395174cd';

    /** @var string UUID of "Test Assessment" (removed in B). */
    const TESTASSESSMENT_UUID = 'a106c5fb-cf6c-47a8-9d56-229d7510c914';

    /** @var string UUID of "CC-TEST new outcome" (added in B, removed in C). */
    const CCNEW_UUID = '595ff1a2-c74d-4bf8-b965-6950f2d88e36';

    /** @var string UUID of "CC-TEST strand outcome" / "Test Outcome" (added in B, moved in C). */
    const CCSTRAND_UUID = 'bf337f58-de08-4e8b-9d52-e31a718c7e6f';

    /** @var string UUID of the recreated "Folder 1" (added in C). */
    const NEWFOLDER_UUID = 'f8d51f27-bb45-4186-8eb8-31d62a70e13b';

    /**
     * Reset the environment for every test.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Load a JSON fixture.
     *
     * @param string $name Fixture basename.
     * @return array
     */
    private static function fixture(string $name): array {
        return json_decode(file_get_contents(__DIR__ . '/../fixtures/' . $name), true);
    }

    /**
     * Create the vet-med programme row.
     *
     * @return \stdClass
     */
    private function make_programme(): \stdClass {
        global $DB;
        $programme = new \stdClass();
        $programme->slug = 'vet-med';
        $programme->versionlabel = 'LATEST';
        $programme->enabled = 1;
        $programme->lastsyncstatus = 'never';
        $programme->id = $DB->insert_record('local_curricmap_programme', $programme);
        return $programme;
    }

    /**
     * Sync a fixture revision into a programme.
     *
     * @param \stdClass $programme Programme record.
     * @param string $revision Fixture revision letter (a, b or c).
     * @param string $hash Revision hash to report.
     * @param bool $force Force apply.
     * @return \stdClass Sync log record.
     */
    private function sync_revision(\stdClass $programme, string $revision, string $hash, bool $force = false): \stdClass {
        $client = new fake_sofia_client(
            self::fixture("vetmed_{$revision}_nodes.json"),
            self::fixture("vetmed_{$revision}_metadata.json"),
            $hash
        );
        $engine = new sync($client);
        return $engine->sync_programme($programme, $force);
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
     * Fetch a node row by raw Sofia uuid (composing the stored node key).
     *
     * @param string $uuid Raw Sofia node uuid.
     * @return \stdClass
     */
    private function node(string $uuid): \stdClass {
        global $DB;
        return $DB->get_record('local_curricmap_node', ['uuid' => $this->key($uuid)], '*', MUST_EXIST);
    }

    /**
     * Full sync of revision A: reference counts from the fixture corpus.
     */
    public function test_full_sync_revision_a(): void {
        global $DB;

        $programme = $this->make_programme();
        $log = $this->sync_revision($programme, 'a', self::HASH_A);

        $this->assertSame('ok', $log->status);
        $this->assertSame('Initial full sync.', $log->message);
        $this->assertSame(1497, (int) $log->nodesfetched);
        $this->assertSame(1496, (int) $log->nodesinserted);
        $this->assertSame(0, (int) $log->nodesupdated);
        $this->assertSame(0, (int) $log->nodesdeleted);

        $this->assertSame(1496, $DB->count_records('local_curricmap_node'));
        $this->assertSame(14, $DB->count_records('local_curricmap_node', ['role' => 'strand']));
        $this->assertSame(79, $DB->count_records('local_curricmap_node', ['role' => 'strandoutcome']));
        $this->assertSame(331, $DB->count_records('local_curricmap_node', ['role' => 'session']));
        $this->assertSame(1066, $DB->count_records('local_curricmap_node', ['role' => 'sessionoutcome']));

        $this->assertSame(3786, $DB->count_records('local_curricmap_edge', ['connectiontype' => 'implements']));
        $this->assertSame(5, $DB->count_records('local_curricmap_edge', ['connectiontype' => 'event-outcome']));
        $this->assertSame(3791, $DB->count_records('local_curricmap_edge'));

        $this->assertSame(10, $DB->count_records('local_curricmap_tagfield'));
        $this->assertSame(259, $DB->count_records('local_curricmap_tagoption'));

        $programme = $DB->get_record('local_curricmap_programme', ['id' => $programme->id]);
        $this->assertSame(self::HASH_A, $programme->revisionhash);
        $this->assertSame('ok', $programme->lastsyncstatus);

        // Path materialisation: a strand outcome sits under year > strand.
        $lo32 = $DB->get_record('local_curricmap_node', ['code' => 'UG1-LOCO-LO32'], '*', MUST_EXIST);
        $strand = $DB->get_record('local_curricmap_node', ['code' => 'UG1-LOCO'], '*', MUST_EXIST);
        $this->assertSame((int) $strand->id, (int) $lo32->parentid);
        $this->assertStringStartsWith($strand->path, $lo32->path);
        $this->assertSame(4, $DB->count_records('local_curricmap_nodetag', ['nodeid' => $lo32->id]));
    }

    /**
     * Re-running against the same revision hash is a no-op with no fetches.
     */
    public function test_resync_is_noop(): void {
        global $DB;

        $programme = $this->make_programme();
        $this->sync_revision($programme, 'a', self::HASH_A);
        $programme = $DB->get_record('local_curricmap_programme', ['id' => $programme->id]);

        $before = $DB->get_field_sql('SELECT MAX(timemodified) FROM {local_curricmap_node}');
        $log = $this->sync_revision($programme, 'a', self::HASH_A);

        $this->assertSame('noop', $log->status);
        $this->assertSame(1, (int) $log->requestcount, 'Only the compare request was made.');
        $after = $DB->get_field_sql('SELECT MAX(timemodified) FROM {local_curricmap_node}');
        $this->assertSame($before, $after, 'No rows were touched.');
    }

    /**
     * A forced re-apply of the same snapshot changes nothing (idempotent apply).
     */
    public function test_forced_reapply_is_idempotent(): void {
        global $DB;

        $programme = $this->make_programme();
        $this->sync_revision($programme, 'a', self::HASH_A);
        $programme = $DB->get_record('local_curricmap_programme', ['id' => $programme->id]);

        $log = $this->sync_revision($programme, 'a', self::HASH_A, true);
        $this->assertSame('ok', $log->status);
        $this->assertSame(0, (int) $log->nodesinserted);
        $this->assertSame(0, (int) $log->nodesupdated);
        $this->assertSame(0, (int) $log->nodesdeleted);
        $this->assertSame(3791, (int) $log->edgeschanged, 'Edges are rebuilt wholesale.');
    }

    /**
     * Golden master: applying revision B over A reproduces the captured delta
     * exactly (compare_a_to_b.json: 2 adds, 3 removes, root + 2 real modifications).
     */
    public function test_sync_b_over_a_matches_captured_delta(): void {
        global $DB;

        $programme = $this->make_programme();
        $this->sync_revision($programme, 'a', self::HASH_A);
        $programme = $DB->get_record('local_curricmap_programme', ['id' => $programme->id]);

        $log = $this->sync_revision($programme, 'b', self::HASH_B);

        $this->assertSame('ok', $log->status);
        $this->assertSame(2, (int) $log->nodesinserted, 'CC-TEST new outcome and CC-TEST strand outcome.');
        $this->assertSame(3, (int) $log->nodesdeleted, 'Test Outcome, Test Assessment, Test Folder.');
        // Compare reported 3 modifications incl. the root, which we do not store.
        $this->assertSame(2, (int) $log->nodesupdated, 'Test Unit and Test Event.');
        $this->assertNotEmpty($log->message, 'A change report is stored for non-initial syncs.');
        $this->assertStringContainsString(substr(self::HASH_A, 0, 12), $log->message);

        // Soft-deleted rows keep their content.
        foreach ([self::TESTOUTCOME_UUID, self::TESTASSESSMENT_UUID, self::TESTFOLDER_UUID] as $uuid) {
            $this->assertSame(1, (int) $this->node($uuid)->deleted);
        }
        $this->assertSame('Test Folder', $this->node(self::TESTFOLDER_UUID)->title);

        // New nodes derive parent-aware roles: outcome under a session is a
        // sessionoutcome; outcome under a non-strand Unit is only "other".
        $ccnew = $this->node(self::CCNEW_UUID);
        $this->assertSame('sessionoutcome', $ccnew->role);
        $this->assertSame(0, (int) $ccnew->deleted);
        $this->assertSame('other', $this->node(self::CCSTRAND_UUID)->role);

        // Modified rows carry the new revision hash; untouched rows keep A's.
        $this->assertSame(self::HASH_B, $this->node(self::TESTEVENT_UUID)->sourceversion);
        $lo32 = $DB->get_record('local_curricmap_node', ['code' => 'UG1-LOCO-LO32'], '*', MUST_EXIST);
        $this->assertSame(self::HASH_A, $lo32->sourceversion);

        // The doc edit (duration/occurrences) landed in the metadata overflow.
        $this->assertStringContainsString('meta:duration', (string) $this->node(self::TESTEVENT_UUID)->metadata);

        // Schema change: CCTEST_CATEGORY was created in B.
        $this->assertSame(11, $DB->count_records('local_curricmap_tagfield'));

        // Totals: 1496 + 2 new rows; 3 of them soft-deleted.
        $this->assertSame(1498, $DB->count_records('local_curricmap_node'));
        $this->assertSame(1495, $DB->count_records('local_curricmap_node', ['deleted' => 0]));
        $this->assertSame(self::HASH_B, $DB->get_field('local_curricmap_programme', 'revisionhash', ['id' => $programme->id]));
    }

    /**
     * Golden master: applying revision C over B reproduces the second captured
     * delta (compare_b_to_c.json: move, remove, group add, schema-field removal),
     * including a node id surviving a move with its role re-derived.
     */
    public function test_sync_c_over_b_matches_captured_delta(): void {
        global $DB;

        $programme = $this->make_programme();
        $this->sync_revision($programme, 'a', self::HASH_A);
        $programme = $DB->get_record('local_curricmap_programme', ['id' => $programme->id]);
        $this->sync_revision($programme, 'b', self::HASH_B);
        $programme = $DB->get_record('local_curricmap_programme', ['id' => $programme->id]);

        $movedbefore = $this->node(self::CCSTRAND_UUID);
        $log = $this->sync_revision($programme, 'c', self::HASH_C);

        $this->assertSame('ok', $log->status);
        $this->assertSame(1, (int) $log->nodesinserted, 'Recreated folder.');
        $this->assertSame(1, (int) $log->nodesdeleted, 'CC-TEST new outcome.');
        // Test Event (title revert), the moved outcome, and Test Unit - the last
        // only because the recreated folder shifts its sibling sort order
        // (natural code order puts F1 before U1 at the top level).
        $this->assertSame(3, (int) $log->nodesupdated);

        // The move: same row id, new parent, new path, role re-derived from the
        // new location (outcome under a session becomes a sessionoutcome).
        $moved = $this->node(self::CCSTRAND_UUID);
        $this->assertSame((int) $movedbefore->id, (int) $moved->id, 'Node ids are stable across syncs.');
        $event = $this->node(self::TESTEVENT_UUID);
        $this->assertSame((int) $event->id, (int) $moved->parentid);
        $this->assertStringStartsWith($event->path, $moved->path);
        $this->assertSame('sessionoutcome', $moved->role);
        $this->assertSame('Test Outcome', $moved->title);

        // Test Unit's only change is the sibling shift caused by the new folder.
        $testunit = $this->node(self::TESTUNIT_UUID);
        $this->assertSame(2, (int) $testunit->sortorder);
        $this->assertSame(self::HASH_C, $testunit->sourceversion);

        // Removal and schema-field removal.
        $this->assertSame(1, (int) $this->node(self::CCNEW_UUID)->deleted);
        $this->assertSame(10, $DB->count_records('local_curricmap_tagfield'));
        $this->assertSame(0, $DB->count_records('local_curricmap_tagfield', ['fieldkey' => 'CCTEST_CATEGORY']));

        // Recreated folder is a NEW uuid (Sofia does not resurrect deletions).
        $folder = $this->node(self::NEWFOLDER_UUID);
        $this->assertSame('Folder 1', $folder->title);
        $this->assertSame('group', $folder->role);
        $this->assertSame(0, (int) $folder->deleted);
        $this->assertSame(1, (int) $this->node(self::TESTFOLDER_UUID)->deleted, 'The original stays soft-deleted.');
    }

    /**
     * A failure before any write leaves everything intact and logs an error.
     */
    public function test_failure_before_write_leaves_data_intact(): void {
        global $DB;

        $programme = $this->make_programme();
        $this->sync_revision($programme, 'a', self::HASH_A);
        $programme = $DB->get_record('local_curricmap_programme', ['id' => $programme->id]);

        // A payload with no root node fails derivation before any write.
        $client = new fake_sofia_client(['x' => ['type' => 'O']], self::fixture('vetmed_a_metadata.json'), 'deadbeef');
        $engine = new sync($client);
        $log = $engine->sync_programme($programme);

        $this->assertSame('error', $log->status);
        $this->assertSame(1496, $DB->count_records('local_curricmap_node'));
        $this->assertSame(self::HASH_A, $DB->get_field('local_curricmap_programme', 'revisionhash', ['id' => $programme->id]));
        $this->assertSame('error', $DB->get_field('local_curricmap_programme', 'lastsyncstatus', ['id' => $programme->id]));
    }

    /**
     * A failure mid-apply rolls the transaction back: the previous revision
     * survives fully intact (FR-SOF-4).
     */
    public function test_midapply_failure_rolls_back(): void {
        global $DB;

        // The engine's own transaction must be outermost for its rollback to be
        // physically observable - otherwise PHPUnit's wrapping transaction defers
        // it until after the assertions run.
        $this->preventResetByRollback();

        $programme = $this->make_programme();
        $this->sync_revision($programme, 'a', self::HASH_A);
        $programme = $DB->get_record('local_curricmap_programme', ['id' => $programme->id]);
        $edgecount = $DB->count_records('local_curricmap_edge');

        // Corrupt one deep node (a depth-3 session outcome): an over-length key
        // violates char(128) after hundreds of rows have already been written
        // inside the transaction.
        $deepuuid = 'ec917dc5-4dc3-4d58-b619-b2921eef1976';
        $payload = self::fixture('vetmed_a_nodes.json');
        $this->assertArrayHasKey($deepuuid, $payload);
        $bomb = str_repeat('z', 140);
        $payload[$bomb] = $payload[$deepuuid];
        unset($payload[$deepuuid]);
        foreach ($payload as $uuid => $candidate) {
            if (!empty($candidate['children']) && in_array($deepuuid, $candidate['children'], true)) {
                $payload[$uuid]['children'] = array_map(
                    fn($child) => $child === $deepuuid ? $bomb : $child,
                    $candidate['children']
                );
            }
        }

        $client = new fake_sofia_client($payload, self::fixture('vetmed_a_metadata.json'), 'deadbeefdeadbeef');
        $engine = new sync($client);
        $log = $engine->sync_programme($programme);

        $this->assertSame('error', $log->status);
        $this->assertSame(1496, $DB->count_records('local_curricmap_node'), 'Node rows unchanged after rollback.');
        $this->assertSame($edgecount, $DB->count_records('local_curricmap_edge'), 'Edges restored by rollback.');
        $this->assertSame(self::HASH_A, $DB->get_field('local_curricmap_programme', 'revisionhash', ['id' => $programme->id]));
    }

    /**
     * Version discovery probes only missing years, creates rows for existing
     * versions, and reconciliation enables/disables by slug. The two most
     * recent years per slug are the hourly tier.
     */
    public function test_discovery_and_tiers(): void {
        global $DB;

        set_config('programmeslugs', 'vet-med, vet-nur', 'local_curricmap');
        set_config('discoveryfloor', 2024, 'local_curricmap');

        $client = new fake_sofia_client([], [], 'aabb', ['2025', '2026']);
        $found = sync::discover_programmes($client);
        $this->assertSame(2 * 2, $found['created'], 'Two years found for each of two slugs.');
        $this->assertSame(4, $DB->count_records('local_curricmap_programme'));

        // Second run probes only the still-missing slots and creates nothing.
        $again = sync::discover_programmes($client);
        $this->assertSame(0, $again['created']);
        $this->assertLessThan($found['probed'], $again['probed'] + 1);

        // Reconciliation by slug: dropping vet-nur disables its rows, data kept.
        set_config('programmeslugs', 'vet-med', 'local_curricmap');
        $programmes = sync::ensure_programmes();
        $this->assertCount(2, $programmes);
        $conditions = ['slug' => 'vet-nur', 'versionlabel' => '2025'];
        $this->assertSame(0, (int) $DB->get_field('local_curricmap_programme', 'enabled', $conditions));

        // Tiering: with 2024 added, 2025+2026 are hourly, 2024 daily.
        $old = (object) ['slug' => 'vet-med', 'versionlabel' => '2024', 'enabled' => 1, 'lastsyncstatus' => 'never'];
        $old->id = $DB->insert_record('local_curricmap_programme', $old);
        $enabled = sync::ensure_programmes();
        foreach ($enabled as $programme) {
            $expected = in_array($programme->versionlabel, ['2025', '2026'], true);
            $this->assertSame($expected, sync::is_hourly($programme, $enabled), $programme->versionlabel);
        }
    }
}
