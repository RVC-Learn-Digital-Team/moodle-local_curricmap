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

/**
 * Tests for the derive class, against the recorded vet-med fixture corpus.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_curricmap\local\derive
 */
final class derive_test extends \basic_testcase {
    /** @var string UUID of the Year 1 node in the vet-med fixtures. */
    const YEAR_UUID = 'e1e70820-6c4b-4ddf-a478-7c1b2db0cabe';

    /** @var string UUID of the Locomotor strand in the vet-med fixtures. */
    const LOCO_UUID = '15629971-00d5-428a-944e-e94142c86088';

    /** @var string UUID of a Lecture carrying a THEME grouping label. */
    const THEME_LECTURE_UUID = 'c8ccfbe7-5bbd-431b-b53a-457d8571224a';

    /** @var string UUID of the "Test Unit" editor artifact (U without Strand subtype). */
    const TESTUNIT_UUID = 'a783486f-5409-40ce-820e-d5b0d90e96a6';

    /** @var string UUID of the "Test Outcome" artifact (O under an assessment). */
    const TESTOUTCOME_UUID = '3fbfe142-fe51-4c09-9866-5e83b52401ce';

    /** @var string UUID of the "Test Folder" artifact (G at top level). */
    const TESTFOLDER_UUID = 'bb4e6dea-a72b-4785-9918-8093395174cd';

    /** @var array|null Cached decoded revision-A nodes payload. */
    private static ?array $payload = null;

    /**
     * Load the revision-A nodes fixture once per test run.
     *
     * @return array Decoded payload, uuid => node.
     */
    private static function payload(): array {
        if (self::$payload === null) {
            $json = file_get_contents(__DIR__ . '/../fixtures/vetmed_a_nodes.json');
            self::$payload = json_decode($json, true);
        }
        return self::$payload;
    }

    /**
     * Role derivation matrix.
     *
     * @return array[] type, subtype, parentrole, expected role.
     */
    public static function role_provider(): array {
        return [
            'year (legacy Y)' => ['Y', null, null, 'year'],
            'year as future unit subtype' => ['U', 'Year', null, 'year'],
            'course subtype is a year container' => ['U', 'Course', null, 'year'],
            'strand' => ['U', 'Strand', 'year', 'strand'],
            'unit without subtype is other' => ['U', null, null, 'other'],
            'unit with unknown subtype is other' => ['U', 'Module', 'year', 'other'],
            'session' => ['E', 'Lecture', 'strand', 'session'],
            'session without subtype' => ['E', null, 'other', 'session'],
            'strand outcome' => ['O', null, 'strand', 'strandoutcome'],
            'nested strand outcome' => ['O', null, 'strandoutcome', 'strandoutcome'],
            'session outcome' => ['O', null, 'session', 'sessionoutcome'],
            'nested session outcome' => ['O', null, 'sessionoutcome', 'sessionoutcome'],
            'outcome under assessment is other' => ['O', null, 'assessment', 'other'],
            'outcome at top level is a programme outcome' => ['O', null, null, 'programmeoutcome'],
            'assessment' => ['Z', null, 'strand', 'assessment'],
            'group' => ['G', null, null, 'group'],
            'unknown type is other' => ['A', null, null, 'other'],
        ];
    }

    /**
     * Test the role derivation matrix.
     *
     * @dataProvider role_provider
     * @param string $type Sofia type letter.
     * @param string|null $subtype Subtype.
     * @param string|null $parentrole Parent role.
     * @param string $expected Expected role.
     */
    public function test_role(string $type, ?string $subtype, ?string $parentrole, string $expected): void {
        $this->assertSame($expected, derive::role($type, $subtype, $parentrole));
    }

    /**
     * Whole-corpus role counts for revision A (see tests/fixtures/README.md).
     */
    public function test_build_rows_fixture_counts(): void {
        $rows = derive::build_rows(self::payload());

        $this->assertCount(1496, $rows, 'All nodes except the root are stored.');

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['role']] = ($counts[$row['role']] ?? 0) + 1;
        }
        ksort($counts);
        $this->assertSame([
            'assessment' => 2,
            'group' => 1,
            'other' => 2,
            'session' => 331,
            'sessionoutcome' => 1066,
            'strand' => 14,
            'strandoutcome' => 79,
            'year' => 1,
        ], $counts);
    }

    /**
     * Top-level nodes have no parent; test artifacts derive to consumer-hidden roles.
     */
    public function test_build_rows_top_level_and_artifacts(): void {
        $rows = derive::build_rows(self::payload());

        $year = $rows[self::YEAR_UUID];
        $this->assertSame('year', $year['role']);
        $this->assertNull($year['parentuuid']);
        $this->assertSame(0, $year['depth']);
        $this->assertSame([self::YEAR_UUID], $year['pathuuids']);

        $this->assertSame('other', $rows[self::TESTUNIT_UUID]['role']);
        $this->assertSame('group', $rows[self::TESTFOLDER_UUID]['role']);
        // An outcome under an assessment is not a strand or session outcome.
        $this->assertSame('other', $rows[self::TESTOUTCOME_UUID]['role']);
    }

    /**
     * Locomotor case study: order preserved from the API, roles and labels correct.
     */
    public function test_build_rows_locomotor(): void {
        $payload = self::payload();
        $rows = derive::build_rows($payload);

        $strand = $rows[self::LOCO_UUID];
        $this->assertSame('strand', $strand['role']);
        $this->assertSame('Strand', $strand['subtype']);
        $this->assertSame(1, $strand['depth']);
        $this->assertSame(self::YEAR_UUID, $strand['parentuuid']);

        $children = $payload[self::LOCO_UUID]['children'];
        $this->assertCount(27, $children);

        foreach ($children as $index => $childuuid) {
            // The sortorder is the index in the parent children array.
            $this->assertSame($index, $rows[$childuuid]['sortorder']);
            $this->assertSame(self::LOCO_UUID, $rows[$childuuid]['parentuuid']);
            $this->assertSame(2, $rows[$childuuid]['depth']);
        }

        // First child is a strand outcome carrying the "Strand outcomes" grouping label.
        $first = $rows[$children[0]];
        $this->assertSame('strandoutcome', $first['role']);
        $this->assertSame('Strand outcomes', $first['grouplabel']);

        // Locomotor events carry no grouping label at all (fallback-to-subtype case).
        foreach ($children as $childuuid) {
            if ($rows[$childuuid]['role'] === 'session') {
                $this->assertNull($rows[$childuuid]['grouplabel']);
            }
        }

        // A session outcome three levels down, with full ancestry.
        $lecture = null;
        foreach ($children as $childuuid) {
            if ($rows[$childuuid]['role'] === 'session' && !empty($payload[$childuuid]['children'])) {
                $lecture = $childuuid;
                break;
            }
        }
        $this->assertNotNull($lecture);
        $outcome = $payload[$lecture]['children'][0];
        $this->assertSame('sessionoutcome', $rows[$outcome]['role']);
        $this->assertSame([self::YEAR_UUID, self::LOCO_UUID, $lecture, $outcome], $rows[$outcome]['pathuuids']);
    }

    /**
     * Grouping-label extraction from a THEME-grouped lecture (Animal Husbandry style
     * "Unit N:" labels and POS-style "THEME:" labels use the same mechanism).
     */
    public function test_grouplabel_extraction(): void {
        $payload = self::payload();
        $node = $payload[self::THEME_LECTURE_UUID];

        $this->assertSame('THEME: GENETICS AND INHERITANCE', derive::grouplabel($node));
        $this->assertSame('Lecture', derive::subtype($node));

        $this->assertNull(derive::grouplabel(['doc' => []]));
        $this->assertNull(derive::grouplabel([]));
        $this->assertNull(derive::subtype(['doc' => ['sofia:grouping:group' => 'X']]));
    }

    /**
     * Natural-compare conformance vectors (tests/fixtures/natural_sort_vectors.json).
     */
    public function test_natural_compare_vectors(): void {
        $json = file_get_contents(__DIR__ . '/../fixtures/natural_sort_vectors.json');
        $vectors = json_decode($json, true);

        foreach ($vectors['compare_cases'] as $case) {
            $label = "natural_compare('{$case['a']}', '{$case['b']}')";
            $this->assertSame($case['expect'], derive::natural_compare($case['a'], $case['b']), $label);
            // Antisymmetry.
            $this->assertSame(-$case['expect'], derive::natural_compare($case['b'], $case['a']), $label . ' reversed');
        }

        foreach ($vectors['sorted_sequences'] as $sequence) {
            $shuffled = array_reverse($sequence);
            usort($shuffled, [derive::class, 'natural_compare']);
            $this->assertSame($sequence, $shuffled);
        }
    }

    /**
     * Sibling ordering for csv/manual rows: positioned first, then natural code order.
     */
    public function test_compare_siblings(): void {
        $records = [
            ['code' => 'B10', 'positionraw' => null],
            ['code' => 'B2', 'positionraw' => ''],
            ['code' => 'A1', 'positionraw' => '10'],
            ['code' => 'Z9', 'positionraw' => '2'],
        ];
        usort($records, [derive::class, 'compare_siblings']);
        // Positioned rows first in position order, then unpositioned in natural code order.
        $this->assertSame(['Z9', 'A1', 'B2', 'B10'], array_column($records, 'code'));
    }

    /**
     * A payload without a root node is rejected.
     */
    public function test_build_rows_requires_root(): void {
        $this->expectException(\coding_exception::class);
        derive::build_rows(['abc' => ['type' => 'O', 'children' => []]]);
    }
}
