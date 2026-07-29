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
 * Classification of Sofia grouping labels.
 *
 * `grouplabel` is ONE free-text column carrying at least six different kinds of
 * information. Measured on live Sofia, 2026-07-29:
 *
 * - term            "Term 1".."Term 3"                  vet-med 1,391, vet-nur 1,552
 * - week            "Week 1".."Week 11", "Term 1, Week 3"  a vet-nur habit
 * - unit            "Unit 3: Reproductive Physiology"    hundreds, all programmes
 * - theme           "THEME: INFECTION AND IMMUNITY"      vet-med ~20
 * - outcome bucket  "Strand outcomes" 1,881, "Module outcomes" 1,585
 * - cohort          "Graduate accelerated", "Gateway", "Intercalation"
 * - housekeeping    "KEEP OR DELETE", "ARCHIVE"
 *
 * A consumer that offers these as one filter list mixes "Term 1" with
 * "THEME: ONCOLOGY" and "KEEP OR DELETE", which is useless. This class splits
 * them so a filter can offer one kind at a time.
 *
 * Canonicalisation matters as much as the kind, because the same grouping
 * appears in variant spellings that would otherwise fragment and double-count:
 * "THEME: PRINCIPLES OF PHARMACOLOGY" also appears unprefixed;
 * "Unit 2: Cell Communication" also appears as "2: Cell Communication";
 * "Unit 4: Obesity" also appears as "Unit4: Obesity"; there is a genuine
 * concatenation bug, "THEME: ONCOLOGYKEEP OR DELETE"; and "Nodule outcomes" is
 * a typo of Module.
 *
 * The kind is classified PER LABEL and never from the node's role - four
 * vet-med outcome nodes carry a theme or term label rather than an outcome one.
 *
 * All functions are pure: no database, no configuration, no side effects.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grouping {
    /** @var string A numbered curriculum unit: "Unit 3: Reproductive Physiology". */
    const KIND_UNIT = 'unit';

    /** @var string A theme: "THEME: INFECTION AND IMMUNITY". */
    const KIND_THEME = 'theme';

    /** @var string A term of the academic year: "Term 1". */
    const KIND_TERM = 'term';

    /** @var string A teaching week: "Week 4", "Term 1, Week 3". */
    const KIND_WEEK = 'week';

    /** @var string An outcome container label: "Strand outcomes". */
    const KIND_OUTCOMES = 'outcomes';

    /** @var string An entry route or cohort: "Graduate accelerated", "Gateway". */
    const KIND_COHORT = 'cohort';

    /** @var string Editorial residue that must never reach a learner: "KEEP OR DELETE". */
    const KIND_HOUSEKEEPING = 'housekeeping';

    /** @var string A real label that fits none of the above. */
    const KIND_OTHER = 'other';

    /**
     * Kinds worth offering as a filter, in the order a human would expect.
     *
     * Housekeeping is deliberately absent: it is editorial residue, reported
     * for data quality but never offered as a way to browse a curriculum.
     *
     * @var string[]
     */
    const FILTERABLE_KINDS = [
        self::KIND_UNIT,
        self::KIND_THEME,
        self::KIND_TERM,
        self::KIND_WEEK,
        self::KIND_COHORT,
        self::KIND_OUTCOMES,
        self::KIND_OTHER,
    ];

    /**
     * Cohort and entry-route labels, lowercased, matched as whole strings.
     *
     * @var string[]
     */
    const COHORT_LABELS = [
        'undergraduate entry',
        'graduate accelerated',
        'gateway',
        'intercalation',
        'all programmes',
        'core',
    ];

    /**
     * Classify one raw grouping label.
     *
     * @param string|null $label Raw grouplabel as Sofia sent it.
     * @return array ['kind' => string, 'label' => string, 'term' => int|null,
     *               'week' => int|null, 'number' => int|null] where `label` is
     *               the canonical display form and `number` the unit number.
     */
    public static function classify(?string $label): array {
        $raw = trim((string) $label);
        $result = ['kind' => self::KIND_OTHER, 'label' => $raw, 'term' => null,
            'week' => null, 'number' => null];
        if ($raw === '') {
            return $result;
        }
        $text = preg_replace('/\s+/', ' ', $raw);
        $lower = \core_text::strtolower($text);

        // Housekeeping first: it also appears CONCATENATED onto a real label
        // ("THEME: ONCOLOGYKEEP OR DELETE"), so an unanchored test is deliberate.
        if (preg_match('/k+e+p\s+or\s+delete|^archive$|^to be (classified|archived)/i', $text)) {
            $result['kind'] = self::KIND_HOUSEKEEPING;
            return $result;
        }
        // Compound "Term 1, Week 3" carries both numbers; week is the finer grain.
        if (preg_match('/^term\s*(\d+)\s*,\s*week\s*(\d+)/i', $text, $m)) {
            $result['kind'] = self::KIND_WEEK;
            $result['term'] = (int) $m[1];
            $result['week'] = (int) $m[2];
            $result['label'] = 'Term ' . $m[1] . ', Week ' . $m[2];
            return $result;
        }
        if (preg_match('/^week\s*(\d+)/i', $text, $m)) {
            $result['kind'] = self::KIND_WEEK;
            $result['week'] = (int) $m[1];
            $result['label'] = 'Week ' . $m[1];
            return $result;
        }
        if (preg_match('/^term\s*(\d+)$/i', $text, $m)) {
            $result['kind'] = self::KIND_TERM;
            $result['term'] = (int) $m[1];
            $result['label'] = 'Term ' . $m[1];
            return $result;
        }
        if (preg_match('/^theme\s*[:.\-]\s*(.+)$/i', $text, $m)) {
            $result['kind'] = self::KIND_THEME;
            $result['label'] = self::titlecase($m[1]);
            return $result;
        }
        // Unit forms: "Unit 3: X", "Unit3: X", "Unit 3. X" and the prefix-less
        // "3: X" that is the same unit written differently.
        if (preg_match('/^unit\s*(\d+)\s*[:.\-]?\s*(.*)$/i', $text, $m)) {
            $result['kind'] = self::KIND_UNIT;
            $result['number'] = (int) $m[1];
            $result['label'] = self::unitlabel((int) $m[1], $m[2]);
            return $result;
        }
        if (preg_match('/^(\d+)\s*[:.]\s*(.+)$/', $text, $m)) {
            $result['kind'] = self::KIND_UNIT;
            $result['number'] = (int) $m[1];
            $result['label'] = self::unitlabel((int) $m[1], $m[2]);
            return $result;
        }
        // Outcome buckets: "Strand outcomes", "Module outcomes", "Programme
        // outcomes", and the "Nodule outcomes" typo of Module.
        if (preg_match('/\boutcomes$/i', $text)) {
            $result['kind'] = self::KIND_OUTCOMES;
            $result['label'] = self::titlecase(preg_replace('/^nodule\b/i', 'Module', $text));
            return $result;
        }
        if (in_array($lower, self::COHORT_LABELS, true) || preg_match('/^year \d+ pathways$/i', $text)) {
            $result['kind'] = self::KIND_COHORT;
            $result['label'] = self::titlecase($text);
            return $result;
        }
        $hasletters = $text !== \core_text::strtolower($text);
        if ($hasletters && $text === \core_text::strtoupper($text)) {
            $result['kind'] = self::KIND_THEME;
            $result['label'] = self::titlecase($text);
            return $result;
        }
        return $result;
    }

    /**
     * Canonical label for a numbered unit, so the spelling variants collapse.
     *
     * @param int $number Unit number.
     * @param string $rest Everything after the number.
     * @return string
     */
    private static function unitlabel(int $number, string $rest): string {
        $rest = trim($rest, " \t:.-");
        return $rest === '' ? 'Unit ' . $number : 'Unit ' . $number . ': ' . self::titlecase($rest);
    }

    /**
     * Sentence-case a SHOUTED label, leaving mixed-case text alone.
     *
     * Sofia writes themes in capitals ("THEME: INFECTION AND IMMUNITY"); a
     * filter reading in capitals is hostile, and upper-casing is not a
     * meaningful difference between two spellings of the same grouping.
     *
     * @param string $text Label text.
     * @return string
     */
    private static function titlecase(string $text): string {
        $text = trim($text);
        if ($text === '' || $text !== \core_text::strtoupper($text)) {
            return $text;
        }
        return \core_text::strtotitle(\core_text::strtolower($text));
    }
}
