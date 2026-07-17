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

use local_curricmap\api\curriculum;

/**
 * Proposes course -> programme-year anchor matches for the central mapping page.
 *
 * Signals and rules were derived from production course extracts (see the
 * moodle_mapping_api_test repo's MATCHING_SIGNALS.md). The idnumber carries a
 * parseable academic year in both estate dialects (legacy RVC_STEM_2025_6 and
 * SRS/SITS VN1202_A_Y_202526); the harmonised year is its first four digits.
 * Programme identity comes from an alias rule table (data, not code — the
 * matchingrules setting), with lowercase whole-word overlap as the fallback.
 * Courses without an idnumber match on name/category signals alone (support
 * courses may need mappings too — visibility is the page's concern, not
 * ours); years below the discovery floor are skipped. Nothing here writes:
 * results are proposals for an admin to confirm.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class matcher {
    /** @var string A course matched a unique node via alias rules on the idnumber. */
    const STATUS_MATCH = 'match';

    /** @var string Candidate nodes found; admin judgement needed. */
    const STATUS_SUGGEST = 'suggest';

    /** @var string A year was parsed but the mirror has no nodes for it. */
    const STATUS_NOCOVERAGE = 'nocoverage';

    /** @var string No academic year found in idnumber, names or category. */
    const STATUS_NOYEAR = 'noyear';

    /** @var string Year and coverage exist but nothing scored. */
    const STATUS_NOMATCH = 'nomatch';

    /** @var string Out of scope: no idnumber, skip pattern, or below the year floor. */
    const STATUS_SKIPPED = 'skipped';

    /** @var int Suggestions kept per course. */
    const MAX_SUGGESTIONS = 5;

    /** @var string[] Words carrying no signal in title containment scoring. */
    const STOPWORDS = ['and', 'of', 'the', 'a', 'an', 'in', 'to', 'for'];

    /**
     * The shipped rule set (the matchingrules setting default).
     *
     * @return array
     */
    public static function default_rules(): array {
        return [
            'skip' => ['^Temp_', 'shell', '^catalyst_'],
            'minscore' => 2,
            'mincontainment' => 0.6,
            // Body-text hints (secondary signal): stricter threshold because
            // long content matches more easily, and candidates need at least
            // this many significant title words to qualify at all.
            'bodymincontainment' => 0.75,
            'bodyminwords' => 2,
            // Patterns are unanchored regexes over section names — anchor
            // anything that could hide inside a real teaching title
            // ("General" must never swallow "General Pathology").
            'skipsections' => [
                'archive', 'to be classified', 'drop.?in', 'course overview', 'announcement',
                'weekly guidance', 'attendance', 'module books?', 'pebblepad', 'learn kit',
                '^general$', '^support blocks?$', '^welcome (&|and) overview$', '^learn guidance',
            ],
            // Includes the strand-code master legend from the timetable/strand
            // map (2026-07-17). Deliberately absent: 'end' (Endocrine — the
            // lowercase token false-positives on "end of ..." titles) and
            // 'nma' (Non-Modular Activity — maps to no Sofia strand).
            'synonyms' => [
                'ah' => 'animal husbandry',
                'alim' => 'alimentary',
                'cs' => 'cardiovascular respiratory',
                'cvrs' => 'cardiovascular respiratory',
                'devb' => 'developmental biology',
                'digestion' => 'alimentary',
                'digestive' => 'alimentary',
                'dops' => 'directly observed procedural skills',
                'ebm' => 'evidence based medicine',
                'iaa' => 'integrated applied anatomy',
                'loc' => 'locomotor',
                'loco' => 'locomotor',
                'locomotion' => 'locomotor',
                'lym' => 'lymphoreticular haemopoietic',
                'nervous' => 'neurology',
                'noss' => 'neurology ophthalmology special senses',
                'pmvph' => 'population medicine veterinary public health',
                'pos' => 'principles science',
                'pvp' => 'principles veterinary practice',
                'repr' => 'reproduction',
                'rs' => 'cardiovascular respiratory',
                'sebm' => 'scholarship evidence based medicine',
                'skn' => 'skin',
                'urn' => 'urinary',
                'vph' => 'veterinary public health',
            ],
            'aliases' => [
                ['pattern' => 'BVETMEDGA|GRADUATE ACCELERATED|\\bGAB\\b', 'slug' => 'vet-med',
                    'node' => 'accelerated|\\bGAB\\b'],
                ['pattern' => 'GATEWAY', 'slug' => 'vet-med', 'node' => 'gateway'],
                ['pattern' => 'BVETMED(?<n>[1-5])|UBVETMD|BVETMED', 'slug' => 'vet-med', 'node' => 'year\\s*{n}'],
                ['pattern' => 'FD_BSC_VN(?<n>[1-4])|UBVETNR_(?<n2>[1-4])', 'slug' => 'vet-nur', 'node' => 'year\\s*{n}'],
                ['pattern' => 'VN(?<n>[1-4])\\d{3}', 'slug' => 'vet-nur', 'node' => 'year\\s*{n}'],
                ['pattern' => 'BIO_SCI_HUB_Y(?<n>[1-3])', 'slug' => 'bio-sc', 'node' => 'year\\s*{n}'],
            ],
        ];
    }

    /**
     * The shipped rule set as pretty JSON (for the setting default).
     *
     * @return string
     */
    public static function default_rules_json(): string {
        return json_encode(self::default_rules(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The active rule set: the matchingrules setting, or the default when the
     * setting is empty or not valid JSON.
     *
     * @return array
     */
    public static function rules(): array {
        $raw = (string) get_config('local_curricmap', 'matchingrules');
        $decoded = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::default_rules();
        }
        return $decoded + self::default_rules();
    }

    /**
     * All match target candidates in the synced mirror: programme-year nodes,
     * plus each year's strand nodes when requested (strand-shaped courses
     * like the PVP Strand course match a strand, not a whole year).
     *
     * @param bool $includestrands Also offer strand nodes as targets.
     * @return \stdClass[] Each with programme, node, yearstart, yeartitle and tokens.
     */
    public static function candidates(bool $includestrands = false): array {
        $out = [];
        foreach (curriculum::programmes() as $programme) {
            foreach (curriculum::years((int) $programme->id) as $yearnode) {
                $pattern = '/^' . preg_quote($programme->slug, '/') . '_(20\d\d)_\d\d_/';
                if (!preg_match($pattern, $yearnode->uuid, $matches)) {
                    continue;
                }
                $yearstart = (int) $matches[1];
                $out[] = (object) [
                    'programme' => $programme,
                    'node' => $yearnode,
                    'yearstart' => $yearstart,
                    'yeartitle' => null,
                    'tokens' => self::tokens(
                        (string) ($programme->displayname ?? ''),
                        str_replace('-', ' ', $programme->slug),
                        (string) $yearnode->title
                    ),
                ];
                if (!$includestrands) {
                    continue;
                }
                foreach (curriculum::strands($yearnode->uuid) as $strand) {
                    $out[] = (object) [
                        'programme' => $programme,
                        'node' => $strand,
                        'yearstart' => $yearstart,
                        'yeartitle' => (string) $yearnode->title,
                        'tokens' => self::tokens(
                            (string) ($programme->displayname ?? ''),
                            str_replace('-', ' ', $programme->slug),
                            (string) $yearnode->title,
                            (string) $strand->title
                        ),
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Lowercase whole-word tokens of the given texts, with unicode dashes
     * normalised and year-like tokens (2025, 202526, 25) dropped — years are
     * matched via the harmonised year, never as words.
     *
     * @param string ...$texts Texts to tokenise.
     * @return string[] Unique tokens.
     */
    public static function tokens(string ...$texts): array {
        $joined = \core_text::strtolower(self::normalise(implode(' ', $texts)));
        $words = preg_split('/[^a-z0-9]+/', $joined, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($words, fn($word) => !preg_match('/^(20\d\d(\d\d)?|\d\d)$/', $word));
        return array_values(array_unique($words));
    }

    /**
     * Score candidates against a location's body text — the secondary
     * signal behind match_title(). Same containment idea (fraction of the
     * candidate title's words present in the text, synonyms expanded) but
     * with its own stricter threshold, because long prose matches more
     * easily than a short name, and a minimum candidate-title length so
     * single-word titles don't match every page that mentions the word.
     *
     * @param string $bodytext Plain text of the location's content.
     * @param array $candidates Candidates from content_candidates().
     * @param array|null $rules Rule set, null for the active one.
     * @return array Scored hints, best first, capped like match_title().
     */
    public static function match_body(string $bodytext, array $candidates, ?array $rules = null): array {
        $rules = $rules ?? self::rules();
        $threshold = (float) ($rules['bodymincontainment'] ?? 0.75);
        $minwords = (int) ($rules['bodyminwords'] ?? 2);
        $bodytokens = self::expand_tokens(self::tokens($bodytext), $rules);
        if (!$bodytokens) {
            return [];
        }

        $scored = [];
        foreach ($candidates as $candidate) {
            $words = array_diff($candidate->tokens, self::STOPWORDS);
            if (count($words) < $minwords) {
                continue;
            }
            $score = count(array_intersect($words, $bodytokens)) / count($words);
            if ($score >= $threshold) {
                $scored[] = (object) ['candidate' => $candidate, 'score' => $score, 'frombody' => true];
            }
        }
        usort($scored, fn($a, $b) => $b->score <=> $a->score);
        return array_slice($scored, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Expand tokens through the synonym table: each token that is a synonym
     * key contributes its expansion words alongside the originals — the
     * bridge between local teaching vocabulary (strand codes, abbreviations)
     * and Sofia's titles.
     *
     * @param string[] $tokens Lowercased tokens.
     * @param array|null $rules Rule set, null for the active one.
     * @return string[]
     */
    public static function expand_tokens(array $tokens, ?array $rules = null): array {
        $rules = $rules ?? self::rules();
        $expanded = $tokens;
        foreach ($tokens as $token) {
            if (isset($rules['synonyms'][$token])) {
                $expanded = array_merge($expanded, explode(' ', $rules['synonyms'][$token]));
            }
        }
        return array_values(array_unique($expanded));
    }

    /**
     * Replace unicode dashes with ASCII hyphens (production names mix en-dash
     * and hyphen in year ranges) and collapse whitespace incl. newlines.
     *
     * @param string $text Raw text.
     * @return string
     */
    public static function normalise(string $text): string {
        $text = preg_replace('/[\x{2010}-\x{2015}]/u', '-', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * The harmonised academic year (its first four digits, e.g. 2025 for
     * 2025-26) and the field it was read from: idnumber first (both dialects),
     * then shortname, fullname and category name.
     *
     * @param \stdClass $course Needs idnumber, shortname, fullname, categoryname.
     * @return array [int|null year, string|null field]
     */
    public static function harmonised_year(\stdClass $course): array {
        $idnumber = trim((string) $course->idnumber);
        // Dialect A (legacy): RVC_STEM_2025_6, incl. the RVC_..._2020_21 range spelling.
        // Dialect B (SRS/SITS): VN1202_A_Y_202526.
        foreach (['/_(20\d\d)_\d{1,2}$/', '/_(20\d\d)\d\d$/'] as $pattern) {
            if (preg_match($pattern, $idnumber, $matches)) {
                return [(int) $matches[1], 'idnumber'];
            }
        }
        foreach (['shortname', 'fullname', 'categoryname'] as $field) {
            $text = self::normalise((string) ($course->$field ?? ''));
            if (preg_match('~(20\d\d)\s*[-/]\s*(?:20)?\d\d~', $text, $matches)) {
                return [(int) $matches[1], $field];
            }
        }
        return [null, null];
    }

    /**
     * Match target candidates below the given nodes (the course's central
     * matches), for section/module mapping: subtree nodes filtered by role,
     * deduplicated across overlapping roots.
     *
     * @param string[] $rootuuids Composed keys of the course's matched nodes.
     * @param string[]|null $roles Roles to include, null for all.
     * @return \stdClass[] Each with node and tokens.
     */
    public static function content_candidates(array $rootuuids, ?array $roles = null): array {
        $out = [];
        $seen = [];
        foreach ($rootuuids as $rootuuid) {
            foreach (curriculum::subtree($rootuuid) as $node) {
                if (isset($seen[$node->uuid])) {
                    continue;
                }
                if ($roles !== null && !in_array($node->role, $roles)) {
                    continue;
                }
                $seen[$node->uuid] = true;
                $out[] = (object) [
                    'node' => $node,
                    'tokens' => self::tokens((string) $node->title),
                ];
            }
        }
        return $out;
    }

    /**
     * Score candidates against a Moodle section/module name by containment:
     * the fraction of the candidate title's words (stopwords dropped) present
     * in the name, after expanding the name's words through the synonym table
     * — local teaching vocabulary differs from Sofia's titles ("Locomotion"
     * vs "Locomotor", "CVRS" vs "Cardiovascular & Respiratory").
     *
     * @param string $name Section or module name.
     * @param \stdClass[] $candidates From content_candidates().
     * @param array|null $rules Rule set, null for the active setting.
     * @return \stdClass[] score-descending {candidate, score}, best few only.
     */
    public static function match_title(string $name, array $candidates, ?array $rules = null): array {
        $rules = $rules ?? self::rules();
        $nametokens = self::expand_tokens(self::tokens($name), $rules);

        $scored = [];
        foreach ($candidates as $candidate) {
            $words = array_diff($candidate->tokens, self::STOPWORDS);
            if (!$words) {
                continue;
            }
            $score = count(array_intersect($words, $nametokens)) / count($words);
            if ($score >= (float) $rules['mincontainment']) {
                $scored[] = (object) ['candidate' => $candidate, 'score' => $score];
            }
        }
        usort($scored, fn($a, $b) => $b->score <=> $a->score);
        return array_slice($scored, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Is this section name housekeeping (never hinted)?
     *
     * @param string $name Section name.
     * @param array|null $rules Rule set, null for the active setting.
     * @return bool
     */
    public static function is_housekeeping(string $name, ?array $rules = null): bool {
        $rules = $rules ?? self::rules();
        // Section names arrive HTML-escaped (get_section_name → format_string,
        // so "&" is "&amp;"); decode so skip patterns can be written in plain
        // human form ("welcome & overview", not "welcome &amp; overview").
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5);
        foreach ($rules['skipsections'] as $pattern) {
            if (preg_match('/' . $pattern . '/i', $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Propose anchors for one course.
     *
     * @param \stdClass $course Needs idnumber, shortname, fullname, categoryname.
     * @param \stdClass[] $candidates From candidates().
     * @param array|null $rules Rule set, null for the active setting.
     * @return \stdClass status, year, yearfield, best (candidate|null), suggestions, note.
     */
    public static function match(\stdClass $course, array $candidates, ?array $rules = null): \stdClass {
        $rules = $rules ?? self::rules();
        $result = (object) [
            'status' => self::STATUS_NOMATCH,
            'year' => null,
            'yearfield' => null,
            'best' => null,
            'suggestions' => [],
            'note' => '',
        ];

        $idnumber = trim((string) $course->idnumber);
        foreach ($rules['skip'] as $pattern) {
            if (preg_match('/' . $pattern . '/i', $idnumber)) {
                $result->status = self::STATUS_SKIPPED;
                $result->note = 'skip pattern: ' . $pattern;
                return $result;
            }
        }

        [$year, $yearfield] = self::harmonised_year($course);
        if ($year === null) {
            $result->status = self::STATUS_NOYEAR;
            return $result;
        }
        $floor = (int) (get_config('local_curricmap', 'discoveryfloor') ?: 2020);
        if ($year < $floor) {
            $result->status = self::STATUS_SKIPPED;
            $result->note = 'year below floor ' . $floor;
            return $result;
        }
        $result->year = $year;
        $result->yearfield = $yearfield;

        $pool = array_values(array_filter($candidates, fn($c) => $c->yearstart === $year));
        if (!$pool) {
            $result->status = self::STATUS_NOCOVERAGE;
            return $result;
        }

        // Alias rules first: deterministic programme (and often year-of-programme).
        $aliasfield = null;
        foreach ($rules['aliases'] as $alias) {
            foreach (['idnumber', 'shortname', 'fullname', 'categoryname'] as $field) {
                $text = self::normalise((string) ($course->$field ?? ''));
                if (!preg_match('/' . $alias['pattern'] . '/i', $text, $matches)) {
                    continue;
                }
                $slugpool = array_values(array_filter($pool, fn($c) => $c->programme->slug === $alias['slug']));
                if (!$slugpool) {
                    $result->status = self::STATUS_NOCOVERAGE;
                    $result->note = 'programme ' . $alias['slug'] . ' has no ' . $year . ' nodes';
                    return $result;
                }
                $number = '';
                foreach ($matches as $key => $value) {
                    if (is_string($key) && $value !== '') {
                        $number = $value;
                        break;
                    }
                }
                $slugpoolfull = $slugpool;
                $noderegex = $number !== '' ? str_replace('{n}', $number, $alias['node']) : $alias['node'];
                if ($noderegex !== '' && strpos($noderegex, '{n}') === false) {
                    $narrowed = array_values(array_filter(
                        $slugpool,
                        fn($c) => preg_match('/' . $noderegex . '/i', (string) $c->node->title)
                    ));
                    if (!$narrowed) {
                        $result->status = self::STATUS_NOCOVERAGE;
                        $result->note = 'no ' . $year . ' node title matches ' . $noderegex;
                        return $result;
                    }
                    $slugpool = $narrowed;
                }
                if (count($slugpool) === 1) {
                    $result->best = $slugpool[0];
                    $result->status = ($field === 'idnumber' && $yearfield === 'idnumber')
                        ? self::STATUS_MATCH : self::STATUS_SUGGEST;
                    // The alias's node regex names the YEAR, which would
                    // otherwise silence every strand/module candidate — a
                    // course named after its module (all of vet-nur/bio-sc)
                    // still deserves strand suggestions beside the year.
                    $coursetokens = self::tokens(
                        $idnumber,
                        (string) $course->shortname,
                        (string) $course->fullname
                    );
                    $strandscored = [];
                    foreach ($slugpoolfull as $candidate) {
                        if ($candidate->yeartitle === null) {
                            continue;
                        }
                        $score = count(array_intersect($coursetokens, $candidate->tokens));
                        if ($score > 0) {
                            $strandscored[] = (object) ['candidate' => $candidate, 'score' => $score];
                        }
                    }
                    usort($strandscored, fn($a, $b) => $b->score <=> $a->score);
                    $result->suggestions = array_slice($strandscored, 0, self::MAX_SUGGESTIONS);
                    return $result;
                }
                $pool = $slugpool;
                $aliasfield = $field;
                break 2;
            }
        }

        // Fallback: lowercase whole-word overlap against programme + node titles.
        $coursetokens = self::tokens($idnumber, (string) $course->shortname, (string) $course->fullname);
        $scored = [];
        foreach ($pool as $candidate) {
            $score = count(array_intersect($coursetokens, $candidate->tokens));
            if ($score >= (int) $rules['minscore'] || ($aliasfield !== null && $score > 0)) {
                $scored[] = (object) ['candidate' => $candidate, 'score' => $score];
            }
        }
        usort($scored, fn($a, $b) => $b->score <=> $a->score);
        $result->suggestions = array_slice($scored, 0, self::MAX_SUGGESTIONS);
        if ($aliasfield !== null && !$result->suggestions) {
            // Alias resolved the programme but words could not split the years' nodes.
            $result->suggestions = array_map(
                fn($c) => (object) ['candidate' => $c, 'score' => 0],
                array_slice($pool, 0, self::MAX_SUGGESTIONS)
            );
        }
        if ($result->suggestions) {
            $result->status = self::STATUS_SUGGEST;
        }
        return $result;
    }
}
