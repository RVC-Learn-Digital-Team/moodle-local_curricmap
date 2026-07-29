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

use local_curricmap\local\derive;
use local_curricmap\local\grouping;
use local_curricmap\local\matcher;

/**
 * The curriculum query service consumed by mod_curricmap, tiny_curricmap and
 * the plugin's own web services.
 *
 * All reads, no writes. Nodes are addressed by uuid (the durable identifier);
 * results are node records in sibling order. Soft-deleted nodes are excluded
 * everywhere except node(), which returns them flagged so consumers can degrade
 * gracefully. List queries are cached in MUC keyed on the owning programme's
 * revision hash, so a sync invalidates by key change, not by purge.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class curriculum {
    /** @var int Default search result limit. */
    const SEARCH_LIMIT = 50;

    /** @var int Ranked search scores at most this many pooled candidates. */
    const SEARCH_POOL = 400;

    /**
     * Enabled programmes.
     *
     * @return \stdClass[] Programme records keyed by id, slug order.
     */
    public static function programmes(): array {
        global $DB;
        return $DB->get_records('local_curricmap_programme', ['enabled' => 1], 'slug ASC, versionlabel ASC');
    }

    /**
     * One node by uuid, including soft-deleted rows (flagged via deleted).
     *
     * @param string $uuid Node uuid.
     * @return \stdClass|null
     */
    public static function node(string $uuid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_curricmap_node', ['uuid' => $uuid]);
        return $record ?: null;
    }

    /**
     * A node's parent, or null for top-level (or missing) nodes.
     *
     * Soft-deleted parents are returned flagged, matching node().
     *
     * @param string $uuid Node uuid.
     * @return \stdClass|null
     */
    public static function parent(string $uuid): ?\stdClass {
        global $DB;
        $node = self::node($uuid);
        if (!$node || empty($node->parentid)) {
            return null;
        }
        $record = $DB->get_record('local_curricmap_node', ['id' => $node->parentid]);
        return $record ?: null;
    }

    /**
     * Year nodes of a programme.
     *
     * @param int $programmeid Programme id.
     * @return \stdClass[] In sibling order.
     */
    public static function years(int $programmeid): array {
        return self::cached('years', [$programmeid], function () use ($programmeid) {
            global $DB;
            $params = ['programmeid' => $programmeid, 'role' => 'year', 'deleted' => 0];
            return array_values($DB->get_records('local_curricmap_node', $params, 'sortorder ASC'));
        });
    }

    /**
     * Children of a node, optionally restricted to roles.
     *
     * @param string $parentuuid Parent node uuid.
     * @param string[]|null $roles Roles to include, null for all.
     * @return \stdClass[] In sibling order.
     */
    public static function children(string $parentuuid, ?array $roles = null): array {
        $parent = self::node($parentuuid);
        if (!$parent) {
            return [];
        }
        return self::cached('children', [$parentuuid, $roles], function () use ($parent, $roles) {
            global $DB;
            $select = 'parentid = :parentid AND deleted = 0';
            $params = ['parentid' => $parent->id];
            if ($roles !== null) {
                [$insql, $inparams] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'role');
                $select .= " AND role $insql";
                $params += $inparams;
            }
            return array_values($DB->get_records_select('local_curricmap_node', $select, $params, 'sortorder ASC'));
        });
    }

    /**
     * Strand nodes of a year.
     *
     * @param string $yearuuid Year node uuid.
     * @return \stdClass[]
     */
    public static function strands(string $yearuuid): array {
        return self::children($yearuuid, ['strand']);
    }

    /**
     * A strand's own outcomes.
     *
     * @param string $stranduuid Strand node uuid.
     * @return \stdClass[]
     */
    public static function strand_outcomes(string $stranduuid): array {
        return self::children($stranduuid, ['strandoutcome']);
    }

    /**
     * The strand's "unit" level: distinct grouping labels of its sessions, in
     * first-appearance order. Empty when the strand uses no labels (the
     * Locomotor case) - consumers then fall back to grouping by subtype.
     *
     * @param string $stranduuid Strand node uuid.
     * @return array[] Each: ['grouplabel' => string, 'sessioncount' => int].
     */
    public static function units(string $stranduuid): array {
        $strand = self::node($stranduuid);
        if (!$strand) {
            return [];
        }
        return self::cached('units', [$stranduuid], function () use ($strand) {
            global $DB;
            $sql = "SELECT grouplabel, COUNT(id) AS sessioncount, MIN(sortorder) AS firstorder
                      FROM {local_curricmap_node}
                     WHERE parentid = :parentid AND role = 'session' AND deleted = 0
                           AND grouplabel IS NOT NULL
                  GROUP BY grouplabel
                  ORDER BY MIN(sortorder) ASC";
            $units = [];
            foreach ($DB->get_records_sql($sql, ['parentid' => $strand->id]) as $row) {
                $units[] = ['grouplabel' => $row->grouplabel, 'sessioncount' => (int) $row->sessioncount];
            }
            return $units;
        });
    }

    /**
     * A strand's grouping level, however Sofia happens to express it.
     *
     * Sofia uses TWO structures for the level between a strand and its taught
     * sessions, and both are live (measured 2026-07-29):
     *
     * - **container nodes** - a real node between strand and sessions, with the
     *   sessions beneath it (vet-med GAB and bio-sc: 131 "Unit n: ..." nodes).
     *   Sofia sends no typeName for these, so they derive to role `unit` via the
     *   positional fallback in derive::role().
     * - **grouping labels** - sessions hang directly off the strand and carry a
     *   `grouplabel` naming their grouping (vet-med Year 1).
     *
     * Consumers must not care which. This returns one ordered list either way,
     * plus an ungrouped bucket for sessions belonging to neither, so a renderer
     * or filter has a single shape to work with. A strand using neither
     * structure returns an empty array and the caller falls back to subtype
     * grouping as before.
     *
     * Note the grouping label is free text and multiplexes several kinds of
     * thing (unit, theme, term, week, housekeeping) - classifying it is a
     * separate concern, deliberately not done here.
     *
     * @param string $stranduuid Strand node uuid.
     * @return array[] Each: ['uuid' => string|null, 'label' => string,
     *         'subtype' => string|null, 'source' => 'node'|'grouplabel'|'ungrouped',
     *         'sessions' => \stdClass[], 'sessioncount' => int].
     */
    public static function groupings(string $stranduuid): array {
        $strand = self::node($stranduuid);
        if (!$strand) {
            return [];
        }
        $groupings = [];
        foreach (self::children($stranduuid, [derive::ROLE_UNIT]) as $container) {
            $sessions = self::children($container->uuid, [derive::ROLE_SESSION]);
            // A container node's TITLE is classified the same way a grouping
            // label is - "Unit 4: Cattle Production" means the same thing
            // whichever structure Sofia used to express it.
            $classified = grouping::classify($container->title);
            $groupings[] = [
                'uuid' => $container->uuid,
                'label' => (string) $container->title,
                'canonical' => $classified['label'],
                'kind' => $classified['kind'],
                'subtype' => $container->subtype,
                'source' => 'node',
                'sessions' => $sessions,
                'sessioncount' => count($sessions),
            ];
        }
        $labelled = [];
        $ungrouped = [];
        foreach (self::children($stranduuid, [derive::ROLE_SESSION]) as $session) {
            $label = $session->grouplabel;
            if ($label === null || $label === '') {
                $ungrouped[] = $session;
                continue;
            }
            $labelled[$label] = $labelled[$label] ?? [];
            $labelled[$label][] = $session;
        }
        foreach ($labelled as $label => $sessions) {
            $classified = grouping::classify((string) $label);
            $groupings[] = [
                'uuid' => null,
                'label' => (string) $label,
                'canonical' => $classified['label'],
                'kind' => $classified['kind'],
                'subtype' => null,
                'source' => 'grouplabel',
                'sessions' => $sessions,
                'sessioncount' => count($sessions),
            ];
        }
        // Loose sessions only earn a bucket when something else grouped - with
        // no grouping at all the caller keeps its subtype behaviour.
        if ($groupings && $ungrouped) {
            $groupings[] = [
                'uuid' => null,
                'label' => '',
                'canonical' => '',
                'kind' => grouping::KIND_OTHER,
                'subtype' => null,
                'source' => 'ungrouped',
                'sessions' => $ungrouped,
                'sessioncount' => count($ungrouped),
            ];
        }
        return $groupings;
    }

    /**
     * A strand's sessions, optionally filtered by grouping label and/or subtype.
     *
     * @param string $stranduuid Strand node uuid.
     * @param string|null $grouplabel Filter to one unit label.
     * @param string|null $subtype Filter to one subtype (e.g. Lecture).
     * @return \stdClass[]
     */
    public static function sessions(string $stranduuid, ?string $grouplabel = null, ?string $subtype = null): array {
        $sessions = self::children($stranduuid, ['session']);
        if ($grouplabel !== null) {
            $sessions = array_values(array_filter($sessions, fn($s) => $s->grouplabel === $grouplabel));
        }
        if ($subtype !== null) {
            $sessions = array_values(array_filter($sessions, fn($s) => $s->subtype === $subtype));
        }
        return $sessions;
    }

    /**
     * A session's learning outcomes.
     *
     * @param string $sessionuuid Session node uuid.
     * @return \stdClass[]
     */
    public static function session_outcomes(string $sessionuuid): array {
        return self::children($sessionuuid, ['sessionoutcome']);
    }

    /**
     * All descendants of a node via the materialised path.
     *
     * @param string $uuid Node uuid.
     * @param int|null $maxdepth Absolute depth limit, null for unlimited.
     * @return \stdClass[] Depth-then-sibling order.
     */
    public static function subtree(string $uuid, ?int $maxdepth = null): array {
        $root = self::node($uuid);
        if (!$root) {
            return [];
        }
        return self::cached('subtree', [$uuid, $maxdepth], function () use ($root, $maxdepth) {
            global $DB;
            $select = $DB->sql_like('path', ':pathprefix') . ' AND deleted = 0';
            $params = ['pathprefix' => $DB->sql_like_escape($root->path) . '%'];
            if ($maxdepth !== null) {
                $select .= ' AND depth <= :maxdepth';
                $params['maxdepth'] = $maxdepth;
            }
            $sort = 'depth ASC, sortorder ASC';
            return array_values($DB->get_records_select('local_curricmap_node', $select, $params, $sort));
        });
    }

    /**
     * The higher-level outcomes an outcome implements (forward traceability).
     *
     * @param string $outcomeuuid Source outcome uuid.
     * @return \stdClass[] Target node records in connection order.
     */
    public static function implements_targets(string $outcomeuuid): array {
        return self::edge_nodes($outcomeuuid, 'implements', true);
    }

    /**
     * The nodes that implement a given outcome (reverse traceability: which
     * sessions' outcomes serve this strand outcome).
     *
     * @param string $outcomeuuid Target outcome uuid.
     * @return \stdClass[] Source node records.
     */
    public static function implemented_by(string $outcomeuuid): array {
        return self::edge_nodes($outcomeuuid, 'implements', false);
    }

    /**
     * A node's tags with their category and option display names.
     *
     * @param string $nodeuuid Node uuid.
     * @return array[] Each: fieldkey, fieldname, optionkey, optionname.
     */
    public static function tags(string $nodeuuid): array {
        $node = self::node($nodeuuid);
        if (!$node) {
            return [];
        }
        return self::cached('tags', [$nodeuuid], function () use ($node) {
            global $DB;
            $sql = "SELECT nt.id, f.fieldkey, f.name AS fieldname, o.optionkey, o.name AS optionname
                      FROM {local_curricmap_nodetag} nt
                      JOIN {local_curricmap_tagoption} o ON o.id = nt.tagoptionid
                      JOIN {local_curricmap_tagfield} f ON f.id = o.tagfieldid
                     WHERE nt.nodeid = :nodeid
                  ORDER BY f.sortorder ASC, nt.sortorder ASC";
            $tags = [];
            foreach ($DB->get_records_sql($sql, ['nodeid' => $node->id]) as $row) {
                $tags[] = [
                    'fieldkey' => $row->fieldkey,
                    'fieldname' => $row->fieldname,
                    'optionkey' => $row->optionkey,
                    'optionname' => $row->optionname,
                ];
            }
            return $tags;
        });
    }

    /**
     * The tag schema of a programme: fields with their options.
     *
     * @param int $programmeid Programme id.
     * @return \stdClass[] Field records with an options array property.
     */
    public static function tag_schema(int $programmeid): array {
        return self::cached('tagschema', [$programmeid], function () use ($programmeid) {
            global $DB;
            $fields = $DB->get_records('local_curricmap_tagfield', ['programmeid' => $programmeid], 'sortorder ASC');
            foreach ($fields as $field) {
                $field->options = array_values(
                    $DB->get_records('local_curricmap_tagoption', ['tagfieldid' => $field->id], 'sortorder ASC')
                );
            }
            return array_values($fields);
        });
    }

    /**
     * Ranked node search by title or code, within one programme or across all
     * (id 0), optionally restricted to one node's subtree (the picker strict
     * lock).
     *
     * OR-pool + coverage ranking: query tokens are synonym-expanded (strand
     * codes and local vocabulary reach Sofia titles), candidates matching ANY
     * token are pooled, then ranked best-first — exact code match beats
     * everything, then query-token coverage (three matched tokens beat two),
     * then title tightness (a node whose title IS the query beats one that
     * mentions it mid-sentence), with tree order as the stable tiebreak.
     *
     * @param int $programmeid Programme id, or 0 to search every enabled mirror.
     * @param string $query Search text.
     * @param string[]|null $roles Roles to include, null for all.
     * @param int $limit Maximum results.
     * @param string|null $ancestoruuid Only offer the subtree below this node (itself included).
     * @return \stdClass[] Best match first.
     */
    public static function search(
        int $programmeid,
        string $query,
        ?array $roles = null,
        int $limit = self::SEARCH_LIMIT,
        ?string $ancestoruuid = null
    ): array {
        global $DB;
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $rules = matcher::rules();
        $querytokens = array_values(array_diff(matcher::tokens($query), matcher::STOPWORDS));
        if (!$querytokens) {
            return [];
        }
        $searchtokens = matcher::expand_tokens($querytokens, $rules);

        $select = 'deleted = 0';
        $params = [];
        $tokenconditions = [];
        foreach (array_values($searchtokens) as $i => $token) {
            $titlelike = $DB->sql_like('title', ":title{$i}", false);
            $codelike = $DB->sql_like('code', ":code{$i}", false);
            $tokenconditions[] = "($titlelike OR $codelike)";
            $params["title{$i}"] = '%' . $DB->sql_like_escape($token) . '%';
            $params["code{$i}"] = '%' . $DB->sql_like_escape($token) . '%';
        }
        $select .= ' AND (' . implode(' OR ', $tokenconditions) . ')';
        if ($ancestoruuid !== null && $ancestoruuid !== '') {
            $ancestor = self::node($ancestoruuid);
            if (!$ancestor || $ancestor->deleted) {
                return [];
            }
            // The ancestor's own path matches the pattern too (empty tail),
            // so the subtree includes the ancestor itself.
            $pathlike = $DB->sql_like('path', ':ancestorpath');
            $select .= " AND $pathlike";
            $params['ancestorpath'] = $DB->sql_like_escape($ancestor->path) . '%';
        }
        if ($programmeid > 0) {
            $select .= ' AND programmeid = :programmeid';
            $params['programmeid'] = $programmeid;
        } else {
            $select .= ' AND programmeid IN (SELECT id FROM {local_curricmap_programme} WHERE enabled = 1)';
        }
        if ($roles !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'role');
            $select .= " AND role $insql";
            $params += $inparams;
        }
        $sort = 'depth ASC, sortorder ASC, id ASC';
        $pool = $DB->get_records_select('local_curricmap_node', $select, $params, $sort, '*', 0, self::SEARCH_POOL);

        return self::rank($pool, $query, $querytokens, $rules, $limit);
    }

    /**
     * Rank a search pool: code match, then query-token coverage, then title
     * tightness, then the pool's tree order. Nodes matching no query token at
     * the token level (substring-only noise, e.g. "cs" inside "physics") are
     * dropped.
     *
     * @param \stdClass[] $pool Candidate rows in tree order.
     * @param string $query The raw query.
     * @param string[] $querytokens Significant query tokens.
     * @param array $rules Matching rules (synonyms).
     * @param int $limit Maximum results.
     * @return \stdClass[]
     */
    private static function rank(array $pool, string $query, array $querytokens, array $rules, int $limit): array {
        $lowquery = \core_text::strtolower(matcher::normalise($query));
        $scored = [];
        $position = 0;
        foreach ($pool as $node) {
            $nodetokens = matcher::tokens((string) $node->title, (string) $node->code);
            $lowcode = \core_text::strtolower((string) $node->code);

            // Code match: the whole query is the code, or a single-token query
            // is the code's final segment (LO32 finds UG1-LOCO-LO32).
            $codematch = 0;
            if ($lowcode !== '' && $lowquery === $lowcode) {
                $codematch = 2;
            } else if ($lowcode !== '' && count($querytokens) === 1) {
                $codetokens = matcher::tokens($lowcode);
                if ($codetokens && end($codetokens) === $querytokens[0]) {
                    $codematch = 1;
                }
            }

            // Coverage: how many query tokens are present, counting a token as
            // present when it (or one of its synonym-expansion words) matches
            // a node token exactly, or as a prefix for tokens of 3+ chars.
            $matched = 0;
            foreach ($querytokens as $token) {
                $words = matcher::expand_tokens([$token], $rules);
                if (self::any_word_matches($words, $nodetokens)) {
                    $matched++;
                }
            }
            if (!$matched && !$codematch) {
                continue;
            }
            $coverage = $matched / count($querytokens);

            // Tightness: the fraction of the node's own significant tokens
            // that the query accounts for.
            $significant = array_values(array_diff($nodetokens, matcher::STOPWORDS));
            $allwords = matcher::expand_tokens($querytokens, $rules);
            $accounted = 0;
            foreach ($significant as $nodetoken) {
                if (self::any_word_matches($allwords, [$nodetoken])) {
                    $accounted++;
                }
            }
            $tightness = $significant ? $accounted / count($significant) : 0;

            $scored[] = (object) [
                'node' => $node,
                'codematch' => $codematch,
                'coverage' => $coverage,
                'tightness' => $tightness,
                'position' => $position++,
            ];
        }
        usort($scored, function ($a, $b) {
            return ($b->codematch <=> $a->codematch)
                ?: ($b->coverage <=> $a->coverage)
                ?: ($b->tightness <=> $a->tightness)
                ?: ($a->position <=> $b->position);
        });
        return array_map(fn($entry) => $entry->node, array_slice($scored, 0, $limit));
    }

    /**
     * Whether any query word matches any node token — exact for short words,
     * prefix for words of three or more characters (autocomplete semantics:
     * "locomot" finds "locomotor"; "cs" never matches inside "physics").
     *
     * @param string[] $words Query-side words (token + expansions).
     * @param string[] $nodetokens Node-side tokens.
     * @return bool
     */
    private static function any_word_matches(array $words, array $nodetokens): bool {
        foreach ($words as $word) {
            foreach ($nodetokens as $nodetoken) {
                if ($word === $nodetoken) {
                    return true;
                }
                if (strlen($word) >= 3 && strpos($nodetoken, $word) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Full node rows for one programme — the graph-extraction read, built
     * for external consumers (the data platform's sofia loader, the external
     * mapping engine) rather than pickers. Uncached: extraction is
     * infrequent and large.
     *
     * @param int $programmeid Programme id.
     * @param string|null $ancestoruuid Restrict to one node's subtree (itself included).
     * @param bool $includedeleted Include soft-deleted rows, flagged.
     * @param int $limitfrom Paging offset.
     * @param int $limitnum Paging size, 0 for all.
     * @return array ['total' => int, 'nodes' => \stdClass[]] Rows in id order.
     */
    public static function nodes(
        int $programmeid,
        ?string $ancestoruuid = null,
        bool $includedeleted = false,
        int $limitfrom = 0,
        int $limitnum = 0
    ): array {
        global $DB;
        $select = 'programmeid = :programmeid';
        $params = ['programmeid' => $programmeid];
        if (!$includedeleted) {
            $select .= ' AND deleted = 0';
        }
        if ($ancestoruuid !== null && $ancestoruuid !== '') {
            $ancestor = self::node($ancestoruuid);
            if (!$ancestor) {
                return ['total' => 0, 'nodes' => []];
            }
            $pathlike = $DB->sql_like('path', ':ancestorpath');
            $select .= " AND $pathlike";
            $params['ancestorpath'] = $DB->sql_like_escape($ancestor->path) . '%';
        }
        return [
            'total' => $DB->count_records_select('local_curricmap_node', $select, $params),
            'nodes' => array_values(
                $DB->get_records_select('local_curricmap_node', $select, $params, 'id ASC', '*', $limitfrom, $limitnum)
            ),
        ];
    }

    /**
     * A programme's edges with both ends resolved to composed keys.
     *
     * @param int $programmeid Programme id.
     * @param string|null $connectiontype Restrict to one type, e.g. implements.
     * @return \stdClass[] Rows: sourceuuid, targetuuid, connectiontype, sortorder.
     */
    public static function edges(int $programmeid, ?string $connectiontype = null): array {
        global $DB;
        $where = 'e.programmeid = :programmeid';
        $params = ['programmeid' => $programmeid];
        if ($connectiontype !== null && $connectiontype !== '') {
            $where .= ' AND e.connectiontype = :connectiontype';
            $params['connectiontype'] = $connectiontype;
        }
        $sql = "SELECT e.id, s.uuid AS sourceuuid, t.uuid AS targetuuid, e.connectiontype, e.sortorder
                  FROM {local_curricmap_edge} e
                  JOIN {local_curricmap_node} s ON s.id = e.sourceid
                  JOIN {local_curricmap_node} t ON t.id = e.targetid
                 WHERE $where
              ORDER BY e.id ASC";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Resolve edge-connected node records for one node.
     *
     * @param string $uuid Node uuid.
     * @param string $connectiontype Edge type, e.g. implements.
     * @param bool $forward True: this node is the source; false: the target.
     * @return \stdClass[]
     */
    private static function edge_nodes(string $uuid, string $connectiontype, bool $forward): array {
        $node = self::node($uuid);
        if (!$node) {
            return [];
        }
        $cachekey = $forward ? 'edgefwd' : 'edgerev';
        return self::cached($cachekey, [$uuid, $connectiontype], function () use ($node, $connectiontype, $forward) {
            global $DB;
            $fromfield = $forward ? 'sourceid' : 'targetid';
            $tofield = $forward ? 'targetid' : 'sourceid';
            $sort = $forward ? 'e.sortorder ASC' : 'n.depth ASC, n.sortorder ASC';
            $sql = "SELECT n.*
                      FROM {local_curricmap_edge} e
                      JOIN {local_curricmap_node} n ON n.id = e.$tofield
                     WHERE e.$fromfield = :nodeid AND e.connectiontype = :ctype AND n.deleted = 0
                  ORDER BY $sort";
            $params = ['nodeid' => $node->id, 'ctype' => $connectiontype];
            return array_values($DB->get_records_sql($sql, $params));
        });
    }

    /**
     * Run a producer through the MUC query cache.
     *
     * The key embeds a global stamp built from all programme revision hashes, so
     * any applied sync changes every key and stale entries simply age out.
     *
     * @param string $method Query name.
     * @param array $args Query arguments.
     * @param callable $producer Produces the value on cache miss.
     * @return mixed
     */
    private static function cached(string $method, array $args, callable $producer) {
        $cache = \cache::make('local_curricmap', 'queries');
        $key = $method . '_' . md5(self::stamp() . '|' . json_encode($args));
        $value = $cache->get($key);
        if ($value === false) {
            $value = $producer();
            $cache->set($key, $value);
        }
        return $value;
    }

    /**
     * Cache stamp covering every programme's applied revision AND when it
     * last applied one: a force full sync re-derives rows without changing
     * the Sofia revision hash, so the hash alone kept serving stale results
     * (found live: modules re-derived as strands, cached queries still empty).
     *
     * @return string
     */
    private static function stamp(): string {
        global $DB;
        $rows = $DB->get_records('local_curricmap_programme', [], 'id ASC', 'id, revisionhash, timelastchanged');
        $parts = [];
        foreach ($rows as $row) {
            $parts[$row->id] = $row->revisionhash . '@' . ($row->timelastchanged ?? 0);
        }
        return md5(json_encode($parts));
    }
}
