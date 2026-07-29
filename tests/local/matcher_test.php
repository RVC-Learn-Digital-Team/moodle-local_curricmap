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
 * Tests for the central course matcher.
 *
 * Fixture idnumbers/names mirror the production conventions documented in
 * the moodle_mapping_api_test repo's MATCHING_SIGNALS.md.
 *
 * @package   local_curricmap
 * @covers    \local_curricmap\local\matcher
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class matcher_test extends \advanced_testcase {
    /**
     * Insert a programme with one year node per given (yearstart, title).
     *
     * @param string $slug Programme slug.
     * @param string $displayname Programme display name.
     * @param array $years yearstart => title.
     */
    private function seed_programme(string $slug, string $displayname, array $years): void {
        global $DB;
        $programmeid = $DB->insert_record('local_curricmap_programme', (object) [
            'slug' => $slug,
            'displayname' => $displayname,
            'versionlabel' => 'TEST',
            'enabled' => 1,
        ]);
        $sortorder = 0;
        foreach ($years as $yearstart => $title) {
            $academicyear = $yearstart . '_' . sprintf('%02d', ($yearstart + 1) % 100);
            $DB->insert_record('local_curricmap_node', (object) [
                'programmeid' => $programmeid,
                'uuid' => $slug . '_' . $academicyear . '_' . sprintf('%08x', crc32($slug . $title . $yearstart)),
                'role' => 'year',
                'title' => $title,
                'sortorder' => $sortorder++,
                'source' => 'sofia',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * A course row shaped like the page's SQL result.
     *
     * @param string $idnumber Course idnumber.
     * @param string $shortname Course shortname.
     * @param string $fullname Course fullname.
     * @param string $categoryname Category name.
     * @return \stdClass
     */
    private function course(
        string $idnumber,
        string $shortname = '',
        string $fullname = '',
        string $categoryname = ''
    ): \stdClass {
        return (object) [
            'idnumber' => $idnumber,
            'shortname' => $shortname,
            'fullname' => $fullname,
            'categoryname' => $categoryname,
        ];
    }

    /**
     * Both idnumber dialects, name/category fallbacks and the en-dash gotcha.
     */
    public function test_harmonised_year_dialects(): void {
        // Dialect A, incl. the seq and range spellings.
        $this->assertSame([2022, 'idnumber'], matcher::harmonised_year($this->course('RVC_FD_BSC_VN3_2022_3')));
        $this->assertSame([2020, 'idnumber'], matcher::harmonised_year($this->course('RVC_BVETMED45_ROT_2020_21')));
        // Dialect B (SRS/SITS).
        $this->assertSame([2025, 'idnumber'], matcher::harmonised_year($this->course('VN1202_A_Y_202526')));
        // Name fallbacks, including the production en-dash.
        $this->assertSame(
            [2021, 'shortname'],
            matcher::harmonised_year($this->course('RVC_NOYEAR', "VN Yr 1 2021\u{2013}22"))
        );
        $this->assertSame(
            [2024, 'categoryname'],
            matcher::harmonised_year($this->course('RVC_NOYEAR', 'X', 'Y', 'BVetMed & BVSc 2024-2025'))
        );
        $this->assertSame([null, null], matcher::harmonised_year($this->course('RVC_BSC_EMPLOY_HUB', 'Hub')));
    }

    /**
     * Year-like tokens never take part in word matching.
     */
    public function test_tokens_drop_year_like_words(): void {
        $this->assertSame(
            ['bvetmed', 'yr', '4'],
            matcher::tokens('BVetMed Yr 4 2024-25', '202425')
        );
    }

    /**
     * Dialect A idnumber with embedded programme year matches deterministically.
     */
    public function test_exact_match_from_idnumber(): void {
        $this->resetAfterTest();
        $this->seed_programme('vet-nur', 'FdSc/BSc Veterinary Nursing', [2022 => 'Year 3']);
        $candidates = matcher::candidates();

        $result = matcher::match(
            $this->course('RVC_FD_BSC_VN3_2022_3', 'VN Yr 3 2022-23'),
            $candidates,
            matcher::default_rules()
        );
        $this->assertSame(matcher::STATUS_MATCH, $result->status);
        $this->assertSame('Year 3', $result->best->node->title);
        $this->assertSame(2022, $result->year);
    }

    /**
     * Dialect B (SITS module code) resolves programme and year via alias rules.
     */
    public function test_srs_dialect_matches_via_alias(): void {
        $this->resetAfterTest();
        $this->seed_programme('vet-nur', 'FdSc/BSc Veterinary Nursing', [2025 => 'Year 2']);
        $result = matcher::match(
            $this->course('VN2203_A_Y_202526', 'VN2203_A_Y_202526', 'Understanding Disease'),
            matcher::candidates(),
            matcher::default_rules()
        );
        $this->assertSame(matcher::STATUS_MATCH, $result->status);
        $this->assertSame('Year 2', $result->best->node->title);
    }

    /**
     * Skip patterns and pre-floor years are out of scope; a missing idnumber
     * is not (support courses match on name signals).
     */
    public function test_skip_rules(): void {
        $this->resetAfterTest();
        $rules = matcher::default_rules();

        $this->assertSame(matcher::STATUS_SKIPPED, matcher::match($this->course('Temp_IDnumber_1'), [], $rules)->status);
        // 201x years are below the default discovery floor (2020).
        $this->assertSame(
            matcher::STATUS_SKIPPED,
            matcher::match($this->course('RVC_BVETMED1_2019_0'), [], $rules)->status
        );
        // No idnumber and no other signals: reported, not skipped.
        $this->assertSame(matcher::STATUS_NOYEAR, matcher::match($this->course(''), [], $rules)->status);
    }

    /**
     * A support course without an idnumber still matches from its name.
     */
    public function test_no_idnumber_matches_from_names(): void {
        $this->resetAfterTest();
        $this->seed_programme('vet-med', 'Bachelor of Veterinary Medicine', [2025 => 'Year 1']);
        $result = matcher::match(
            $this->course('', 'Vet Medicine skills 2025-26', 'Veterinary Medicine clinical skills 2025-26'),
            matcher::candidates(),
            matcher::default_rules()
        );
        $this->assertSame(matcher::STATUS_SUGGEST, $result->status);
        $this->assertSame(2025, $result->year);
    }

    /**
     * Missing mirror coverage is reported, never guessed around.
     */
    public function test_no_coverage_and_ambiguity(): void {
        $this->resetAfterTest();
        $this->seed_programme('vet-med', 'Bachelor of Veterinary Medicine', [2026 => 'Year 1']);
        $candidates = matcher::candidates();
        $rules = matcher::default_rules();

        // Year synced but the aliased programme's year absent.
        $nocoverage = matcher::match($this->course('RVC_GATEWAY_2026_7'), $candidates, $rules);
        $this->assertSame(matcher::STATUS_NOCOVERAGE, $nocoverage->status);

        // Year not synced at all.
        $noyearnodes = matcher::match($this->course('RVC_BVETMED1_2024_5'), $candidates, $rules);
        $this->assertSame(matcher::STATUS_NOCOVERAGE, $noyearnodes->status);
    }

    /**
     * An alias-matched course named after its module-strand (all of
     * vet-nur/bio-sc) proposes the STRAND, not the year: the strand title
     * contained in the course name is the stronger match (ruling
     * 2026-07-23), with the year kept at the top of the suggestions.
     */
    public function test_alias_course_gets_strand_suggestions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_programme('vet-nur', 'Veterinary Nursing', [2026 => 'Year 1']);
        $yearnode = $DB->get_record('local_curricmap_node', ['role' => 'year'], '*', MUST_EXIST);
        $modules = ['Applied Animal Health & Welfare 1', 'Academic and Professional Development 1'];
        foreach ($modules as $index => $title) {
            $DB->insert_record('local_curricmap_node', (object) [
                'programmeid' => $yearnode->programmeid,
                'uuid' => 'vet-nur_2026_27_module' . $index,
                'parentid' => $yearnode->id,
                'role' => 'strand',
                'subtype' => 'Module',
                'title' => $title,
                'sortorder' => $index,
                'source' => 'sofia',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        $course = $this->course('VN1202_A_Y_202627', 'AAHW1 26/7', 'Applied Animal Health & Welfare 1 (VN1202_A_Y_202627)');
        $rules = matcher::default_rules();

        // With strands on: the course is named after its module, so the
        // module IS the proposal; the year sits first in the suggestions.
        $result = matcher::match($course, matcher::candidates(true), $rules);
        $this->assertSame(matcher::STATUS_MATCH, $result->status);
        $this->assertSame('Applied Animal Health & Welfare 1', $result->best->node->title);
        $this->assertSame('strand', $result->best->node->role);
        $this->assertNotEmpty($result->suggestions);
        $this->assertSame('Year 1', $result->suggestions[0]->candidate->node->title);

        // With strands off there is nothing to suggest — unchanged behaviour.
        $plain = matcher::match($course, matcher::candidates(false), $rules);
        $this->assertSame(matcher::STATUS_MATCH, $plain->status);
        $this->assertSame([], $plain->suggestions);
    }

    /**
     * A strand whose title the course name contains — directly or through
     * the synonym table (LOC/Locomotion -> Locomotor) — becomes the
     * proposal itself; without that evidence the year stays the proposal.
     */
    public function test_alias_course_matches_strand_when_stronger(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_programme('vet-med', 'Bachelor of Veterinary Medicine', [2025 => 'Year 2']);
        $year = $DB->get_record('local_curricmap_node', ['role' => 'year'], '*', MUST_EXIST);
        foreach (['Locomotor', 'Cardiovascular & Respiratory'] as $index => $title) {
            $DB->insert_record('local_curricmap_node', (object) [
                'programmeid' => $year->programmeid,
                'uuid' => 'vet-med_2025_26_strand' . $index,
                'parentid' => $year->id,
                'role' => 'strand',
                'title' => $title,
                'sortorder' => $index,
                'source' => 'sofia',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        $rules = matcher::default_rules();
        $candidates = matcher::candidates(true);

        $result = matcher::match(
            $this->course('RVC_BVETMED2_LOC_2025_6', 'BVetMed2 LOC 2025-26', 'BVetMed 2 Locomotion 2025-26'),
            $candidates,
            $rules
        );
        $this->assertSame(matcher::STATUS_MATCH, $result->status);
        $this->assertSame('Locomotor', $result->best->node->title);
        $this->assertSame('Year 2', $result->suggestions[0]->candidate->node->title);

        // No strand title in the name: the year stays the proposal.
        $plain = matcher::match(
            $this->course('RVC_BVETMED2_2025_6', 'BVetMed2 2025-26', 'BVetMed Year 2 2025-26'),
            $candidates,
            $rules
        );
        $this->assertSame(matcher::STATUS_MATCH, $plain->status);
        $this->assertSame('Year 2', $plain->best->node->title);
    }

    /**
     * Unknown conventions fall back to lowercase whole-word overlap.
     */
    public function test_word_overlap_suggestions(): void {
        $this->resetAfterTest();
        $this->seed_programme('vet-med', 'Bachelor of Veterinary Medicine', [2026 => 'Year 1']);
        $result = matcher::match(
            $this->course('UNKNOWN_CONVENTION_202627', 'Veterinary Medicine intro', 'Veterinary Medicine intro'),
            matcher::candidates(),
            matcher::default_rules()
        );
        $this->assertSame(matcher::STATUS_SUGGEST, $result->status);
        $this->assertNotEmpty($result->suggestions);
        $this->assertSame('Year 1', $result->suggestions[0]->candidate->node->title);
        $this->assertGreaterThanOrEqual(2, $result->suggestions[0]->score);
    }

    /**
     * Strand nodes become targets only when requested, and strand-shaped
     * courses reach them by word overlap ranking above the year node.
     */
    public function test_strand_candidates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed_programme('vet-med', 'Bachelor of Veterinary Medicine', [2025 => 'Year 1']);
        $year = $DB->get_record('local_curricmap_node', ['role' => 'year']);
        $DB->insert_record('local_curricmap_node', (object) [
            'programmeid' => $year->programmeid,
            'uuid' => 'vet-med_2025_26_' . sprintf('%08x', crc32('pvp')),
            'parentid' => $year->id,
            'role' => 'strand',
            'title' => 'Principles of Veterinary Practice (PVP) Strand',
            'source' => 'sofia',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->assertCount(1, matcher::candidates());
        $candidates = matcher::candidates(true);
        $this->assertCount(2, $candidates);

        $result = matcher::match(
            $this->course('UBVETMD_202526', 'PVP Strand 2025-26', 'Principles of Veterinary Practice (PVP) Strand'),
            $candidates,
            matcher::default_rules()
        );
        $this->assertSame(matcher::STATUS_SUGGEST, $result->status);
        $this->assertSame('strand', $result->suggestions[0]->candidate->node->role);
    }

    /**
     * Title containment with synonyms bridges local teaching vocabulary to
     * Sofia strand titles (the GAB corpus evidence: CVRS, Locomotion, IAA).
     */
    public function test_match_title_synonyms(): void {
        $rules = matcher::default_rules();
        $candidates = [];
        $strandtitles = ['Cardiovascular & Respiratory', 'Locomotor', 'Endocrine',
            'Integrated and Applied Anatomy', 'Urinary'];
        foreach ($strandtitles as $index => $title) {
            $candidates[] = (object) [
                'node' => (object) ['uuid' => 'vet-med_2026_27_' . $index, 'title' => $title, 'role' => 'strand'],
                'tokens' => matcher::tokens($title),
            ];
        }

        $cvrs = matcher::match_title('PAFF: Unit 8 (CVRS)', $candidates, $rules);
        $this->assertSame('Cardiovascular & Respiratory', $cvrs[0]->candidate->node->title);

        $loco = matcher::match_title('Animal Form and Function: Unit 1 (Locomotion)', $candidates, $rules);
        $this->assertSame('Locomotor', $loco[0]->candidate->node->title);

        $iaa = matcher::match_title('Integrated & Applied Anatomy (IAA)', $candidates, $rules);
        $this->assertSame('Integrated and Applied Anatomy', $iaa[0]->candidate->node->title);

        // Housekeeping names are recognised and never hinted.
        $this->assertTrue(matcher::is_housekeeping('To be archived', $rules));
        $this->assertTrue(matcher::is_housekeeping('Weekly Guidance', $rules));
        $this->assertFalse(matcher::is_housekeeping('Endocrine', $rules));

        // The anchored patterns skip the boilerplate names without swallowing
        // real teaching sections that contain the same word.
        $this->assertTrue(matcher::is_housekeeping('General', $rules));
        $this->assertFalse(matcher::is_housekeeping('General Pathology', $rules));
        $this->assertTrue(matcher::is_housekeeping('Support Blocks', $rules));
        $this->assertTrue(matcher::is_housekeeping('Welcome & Overview', $rules));
        // The name arrives HTML-escaped from get_section_name.
        $this->assertTrue(matcher::is_housekeeping('Welcome &amp; Overview', $rules));
        $this->assertTrue(matcher::is_housekeeping('LEARN guidance for this course', $rules));
        $this->assertFalse(matcher::is_housekeeping('Reading List', $rules));
        $this->assertFalse(matcher::is_housekeeping('Strand Overview', $rules));
    }

    /**
     * Body-text hints: the secondary signal reads a location's content, with
     * a stricter threshold and a minimum candidate-title length.
     */
    public function test_match_body(): void {
        $rules = matcher::default_rules();
        $candidates = [];
        $titles = ['Equine Distal Limb', 'Comparative locomotion 1', 'Endocrine', 'Introduction to the Cardiovascular System'];
        foreach ($titles as $index => $title) {
            $candidates[] = (object) [
                'node' => (object) ['uuid' => 'vet-med_2026_27_' . $index, 'title' => $title, 'role' => 'session'],
                'tokens' => matcher::tokens($title),
            ];
        }

        $body = 'This practical covers the anatomy of the equine distal limb, '
            . 'including the tendons and ligaments below the carpus.';
        $hints = matcher::match_body($body, $candidates, $rules);
        $this->assertNotEmpty($hints);
        $this->assertSame('Equine Distal Limb', $hints[0]->candidate->node->title);
        $this->assertNotEmpty($hints[0]->frombody);

        // Single-word candidates never qualify, however often the word occurs.
        $body = 'Endocrine endocrine endocrine glands and more endocrine content.';
        $this->assertSame([], matcher::match_body($body, $candidates, $rules));

        // Below the stricter threshold: one of four significant words is not
        // enough ("cardiovascular" alone must not hint the full session).
        $body = 'A passing mention of cardiovascular fitness in an unrelated page.';
        $this->assertSame([], matcher::match_body($body, $candidates, $rules));

        $this->assertSame([], matcher::match_body('', $candidates, $rules));
    }

    /**
     * Broken setting JSON falls back to defaults; partial JSON overlays them.
     */
    public function test_rules_setting_fallback(): void {
        $this->resetAfterTest();
        set_config('matchingrules', 'not valid json {', 'local_curricmap');
        $this->assertSame(matcher::default_rules(), matcher::rules());

        set_config('matchingrules', '{"minscore": 3}', 'local_curricmap');
        $rules = matcher::rules();
        $this->assertSame(3, $rules['minscore']);
        $this->assertNotEmpty($rules['aliases']);
    }
}
