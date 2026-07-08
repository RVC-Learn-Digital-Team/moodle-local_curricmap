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

    /**
     * Enabled programmes.
     *
     * @return \stdClass[] Programme records keyed by id, slug order.
     */
    public static function programmes(): array {
        global $DB;
        return $DB->get_records('local_curricmap_programme', ['enabled' => 1], 'slug ASC');
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
     * Search a programme's nodes by title or code.
     *
     * @param int $programmeid Programme id.
     * @param string $query Search text.
     * @param string[]|null $roles Roles to include, null for all.
     * @param int $limit Maximum results.
     * @return \stdClass[]
     */
    public static function search(int $programmeid, string $query, ?array $roles = null, int $limit = self::SEARCH_LIMIT): array {
        global $DB;
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $titlelike = $DB->sql_like('title', ':titlequery', false);
        $codelike = $DB->sql_like('code', ':codequery', false);
        $select = "programmeid = :programmeid AND deleted = 0 AND ($titlelike OR $codelike)";
        $params = [
            'programmeid' => $programmeid,
            'titlequery' => '%' . $DB->sql_like_escape($query) . '%',
            'codequery' => '%' . $DB->sql_like_escape($query) . '%',
        ];
        if ($roles !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'role');
            $select .= " AND role $insql";
            $params += $inparams;
        }
        $sort = 'depth ASC, sortorder ASC';
        return array_values($DB->get_records_select('local_curricmap_node', $select, $params, $sort, '*', 0, $limit));
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
     * Cache stamp covering every programme's applied revision.
     *
     * @return string
     */
    private static function stamp(): string {
        global $DB;
        $hashes = $DB->get_records_menu('local_curricmap_programme', [], 'id ASC', 'id, revisionhash');
        return md5(json_encode($hashes));
    }
}
