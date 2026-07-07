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
 * Pure derivation functions applied to Sofia Nodes API payloads at sync time.
 *
 * Design rule (see the umbrella project's IMPLEMENTING_SOFIA_GRAPH_IN_MOODLE.md):
 * trust the API for what it already computes. The Nodes API returns children in
 * presentation order and, with ?coalesce, display-ready titles - neither is
 * re-derived here. What this class owns is the vocabulary mapping (role), the
 * grouping-label extraction, tree assembly (depth, sort order, ancestry), and a
 * natural sort used only for csv/manual rows where there is no API order to trust.
 *
 * All functions are pure: no database, no configuration, no side effects.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class derive {
    /** @var string Role: course year. */
    const ROLE_YEAR = 'year';

    /** @var string Role: strand (RVC module-like division of a year). */
    const ROLE_STRAND = 'strand';

    /** @var string Role: outcome owned directly by a strand. */
    const ROLE_STRANDOUTCOME = 'strandoutcome';

    /** @var string Role: unit level - never derived for Sofia rows, authored on csv/manual rows only. */
    const ROLE_UNIT = 'unit';

    /** @var string Role: taught session (lecture, practical, DLI, ...). */
    const ROLE_SESSION = 'session';

    /** @var string Role: learning outcome owned by a session. */
    const ROLE_SESSIONOUTCOME = 'sessionoutcome';

    /** @var string Role: assessment item. */
    const ROLE_ASSESSMENT = 'assessment';

    /** @var string Role: generic container. */
    const ROLE_GROUP = 'group';

    /** @var string Role: anything unrecognised - admin-visible, consumer-hidden. */
    const ROLE_OTHER = 'other';

    /**
     * Type letters that map to a role irrespective of subtype or parent.
     *
     * Table-driven so upstream type changes (e.g. the legacy Y retirement) are a
     * data change here, not a logic change.
     *
     * @var array<string, string>
     */
    const TYPE_ROLES = [
        'Y' => self::ROLE_YEAR,
        'E' => self::ROLE_SESSION,
        'Z' => self::ROLE_ASSESSMENT,
        'G' => self::ROLE_GROUP,
    ];

    /**
     * Unit subtypes that map to a role (Unit is otherwise role "other").
     *
     * @var array<string, string>
     */
    const UNIT_SUBTYPE_ROLES = [
        'Strand' => self::ROLE_STRAND,
        'Year' => self::ROLE_YEAR,
    ];

    /**
     * Parent roles under which an Outcome node counts as a strand outcome.
     *
     * Nested outcome containers inherit their outcome bucket.
     *
     * @var string[]
     */
    const STRANDOUTCOME_PARENTS = [self::ROLE_STRAND, self::ROLE_STRANDOUTCOME];

    /**
     * Parent roles under which an Outcome node counts as a session outcome.
     *
     * @var string[]
     */
    const SESSIONOUTCOME_PARENTS = [self::ROLE_SESSION, self::ROLE_SESSIONOUTCOME];

    /**
     * Extract the subtype (doc.typeName) from a Nodes API node.
     *
     * @param array $node Decoded node object.
     * @return string|null
     */
    public static function subtype(array $node): ?string {
        $value = $node['doc']['typeName'] ?? null;
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Extract the grouping label (doc "sofia:grouping:group") from a node.
     *
     * This is the RVC "unit" display label; free text, not a stable identifier.
     *
     * @param array $node Decoded node object.
     * @return string|null
     */
    public static function grouplabel(array $node): ?string {
        $value = $node['doc']['sofia:grouping:group'] ?? null;
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Derive the consumer-facing role for a node.
     *
     * Parent-aware: an Outcome is a strand outcome or a session outcome depending
     * on what it hangs under, so roles must be derived tree-down, never per node
     * in isolation.
     *
     * @param string $type Sofia type letter (Y, U, E, O, Z, G, ...).
     * @param string|null $subtype Subtype from doc.typeName, if any.
     * @param string|null $parentrole Derived role of the parent node, null at top level.
     * @return string One of the ROLE_* constants.
     */
    public static function role(string $type, ?string $subtype, ?string $parentrole): string {
        if ($type === 'U') {
            if ($subtype !== null && isset(self::UNIT_SUBTYPE_ROLES[$subtype])) {
                return self::UNIT_SUBTYPE_ROLES[$subtype];
            }
            return self::ROLE_OTHER;
        }
        if ($type === 'O') {
            if (in_array($parentrole, self::STRANDOUTCOME_PARENTS, true)) {
                return self::ROLE_STRANDOUTCOME;
            }
            if (in_array($parentrole, self::SESSIONOUTCOME_PARENTS, true)) {
                return self::ROLE_SESSIONOUTCOME;
            }
            return self::ROLE_OTHER;
        }
        return self::TYPE_ROLES[$type] ?? self::ROLE_OTHER;
    }

    /**
     * Assemble derived rows from a full Nodes API payload.
     *
     * Walks the tree from the root node (type "r", which is itself not stored),
     * assigning parent, depth, sort order (the index in the parent's children
     * array - API presentation order), role, subtype and grouping label.
     *
     * Nodes unreachable from the root are omitted; cycles are guarded.
     *
     * @param array $payload Decoded Nodes API response: uuid => node object.
     * @return array<string, array> uuid => derived row with keys uuid, parentuuid,
     *         depth, sortorder, type, subtype, role, grouplabel, pathuuids.
     */
    public static function build_rows(array $payload): array {
        $rootuuid = null;
        foreach ($payload as $uuid => $node) {
            if (($node['type'] ?? '') === 'r') {
                $rootuuid = (string) $uuid;
                break;
            }
        }
        if ($rootuuid === null) {
            throw new \coding_exception('Nodes payload contains no root (type r) node.');
        }

        $rows = [];
        // Queue entries: [uuid, parentuuid|null, parentrole|null, depth, sortorder, ancestor uuids].
        $queue = [];
        foreach (($payload[$rootuuid]['children'] ?? []) as $index => $childuuid) {
            $queue[] = [(string) $childuuid, null, null, 0, $index, []];
        }

        while ($queue) {
            [$uuid, $parentuuid, $parentrole, $depth, $sortorder, $ancestors] = array_shift($queue);
            if (!isset($payload[$uuid]) || isset($rows[$uuid])) {
                continue;
            }
            $node = $payload[$uuid];
            $type = (string) ($node['type'] ?? '');
            $subtype = self::subtype($node);
            $role = self::role($type, $subtype, $parentrole);
            $pathuuids = array_merge($ancestors, [$uuid]);

            $rows[$uuid] = [
                'uuid' => $uuid,
                'parentuuid' => $parentuuid,
                'depth' => $depth,
                'sortorder' => $sortorder,
                'type' => $type,
                'subtype' => $subtype,
                'role' => $role,
                'grouplabel' => self::grouplabel($node),
                'pathuuids' => $pathuuids,
            ];

            foreach (($node['children'] ?? []) as $index => $childuuid) {
                $queue[] = [(string) $childuuid, $uuid, $role, $depth + 1, $index, $pathuuids];
            }
        }

        return $rows;
    }

    /**
     * Natural comparison for csv/manual ordering (Sofia rows use API order).
     *
     * Tokenises into digit and non-digit runs. Digit runs compare numerically;
     * non-digit runs compare case-insensitively; a digit run sorts before a
     * non-digit run at the same position; a prefix sorts before its extension;
     * final tie-break is a case-sensitive full comparison for stable ordering.
     * Conformance vectors: tests/fixtures/natural_sort_vectors.json.
     *
     * @param string $a First value.
     * @param string $b Second value.
     * @return int -1, 0 or 1.
     */
    public static function natural_compare(string $a, string $b): int {
        if ($a === $b) {
            return 0;
        }
        $tokensa = self::tokenise($a);
        $tokensb = self::tokenise($b);
        $shared = min(count($tokensa), count($tokensb));
        for ($i = 0; $i < $shared; $i++) {
            [$isdigita, $valuea] = $tokensa[$i];
            [$isdigitb, $valueb] = $tokensb[$i];
            if ($isdigita !== $isdigitb) {
                return $isdigita ? -1 : 1;
            }
            if ($isdigita) {
                $compare = self::compare_digit_runs($valuea, $valueb);
            } else {
                $compare = strcasecmp($valuea, $valueb) <=> 0;
            }
            if ($compare !== 0) {
                return $compare;
            }
        }
        if (count($tokensa) !== count($tokensb)) {
            return count($tokensa) <=> count($tokensb);
        }
        return strcmp($a, $b) <=> 0;
    }

    /**
     * Order two csv/manual sibling records: positioned rows first (by natural
     * comparison of position), then unpositioned rows by natural comparison of code.
     *
     * @param array $a Record with optional keys positionraw and code.
     * @param array $b Record with optional keys positionraw and code.
     * @return int -1, 0 or 1.
     */
    public static function compare_siblings(array $a, array $b): int {
        $posa = self::nonempty($a['positionraw'] ?? null);
        $posb = self::nonempty($b['positionraw'] ?? null);
        if ($posa !== null && $posb !== null) {
            $compare = self::natural_compare($posa, $posb);
            if ($compare !== 0) {
                return $compare;
            }
        } else if ($posa !== null) {
            return -1;
        } else if ($posb !== null) {
            return 1;
        }
        return self::natural_compare((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
    }

    /**
     * Split a string into digit / non-digit runs.
     *
     * @param string $value Input string.
     * @return array<int, array{0: bool, 1: string}> List of [isdigit, run] pairs.
     */
    private static function tokenise(string $value): array {
        if ($value === '') {
            return [];
        }
        preg_match_all('/\d+|\D+/', $value, $matches);
        $tokens = [];
        foreach ($matches[0] as $run) {
            $tokens[] = [ctype_digit($run), $run];
        }
        return $tokens;
    }

    /**
     * Compare two digit runs numerically without integer overflow.
     *
     * @param string $a Digit run.
     * @param string $b Digit run.
     * @return int -1, 0 or 1.
     */
    private static function compare_digit_runs(string $a, string $b): int {
        $trimmeda = ltrim($a, '0');
        $trimmedb = ltrim($b, '0');
        if (strlen($trimmeda) !== strlen($trimmedb)) {
            return strlen($trimmeda) <=> strlen($trimmedb);
        }
        return strcmp($trimmeda, $trimmedb) <=> 0;
    }

    /**
     * Normalise a nullable string: empty string becomes null.
     *
     * @param mixed $value Raw value.
     * @return string|null
     */
    private static function nonempty($value): ?string {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
