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

use local_curricmap\api\client;

/**
 * The Sofia sync engine: Compare API as change detector, full snapshot as the
 * transactional apply mechanism.
 *
 * Each run per programme costs one Compare request (hash discovery + no-op
 * detection); only when the revision hash has moved does it fetch the Nodes and
 * Metadata payloads (two more requests) and rebuild the programme's rows inside
 * one delegated transaction: nodes upserted by UUID (ids stay stable - bindings
 * and caches depend on that), edges and node tags rebuilt wholesale, missing
 * nodes soft-deleted, revision hash advanced last. A failure rolls the whole
 * apply back, leaving the previous revision intact (FR-SOF-4).
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync {
    /** @var int Bulk-insert chunk size for edges and node tags. */
    const CHUNK = 500;

    /** @var client The Sofia API client. */
    private client $client;

    /**
     * Constructor.
     *
     * @param client|null $client Injectable client for tests.
     */
    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    /**
     * Ensure programme rows exist for the configured slugs.
     *
     * Slugs present in the programmeslugs setting are created/enabled; rows for
     * slugs no longer configured are disabled (never deleted - their data stays).
     *
     * @return \stdClass[] Enabled programme records.
     */
    public static function ensure_programmes(): array {
        global $DB;

        $setting = (string) get_config('local_curricmap', 'programmeslugs');
        $slugs = array_values(array_filter(array_map('trim', explode(',', $setting))));

        foreach ($slugs as $slug) {
            $existing = $DB->get_record('local_curricmap_programme', ['slug' => $slug]);
            if (!$existing) {
                $record = new \stdClass();
                $record->slug = $slug;
                $record->versionlabel = 'LATEST';
                $record->enabled = 1;
                $record->lastsyncstatus = 'never';
                $DB->insert_record('local_curricmap_programme', $record);
            } else if (!$existing->enabled) {
                $DB->set_field('local_curricmap_programme', 'enabled', 1, ['id' => $existing->id]);
            }
        }
        if ($slugs) {
            [$insql, $params] = $DB->get_in_or_equal($slugs, SQL_PARAMS_QM, 'param', false);
            $DB->set_field_select('local_curricmap_programme', 'enabled', 0, "slug $insql", $params);
        } else {
            $DB->set_field('local_curricmap_programme', 'enabled', 0, []);
        }

        return $DB->get_records('local_curricmap_programme', ['enabled' => 1], 'slug ASC');
    }

    /**
     * Sync one programme. Never throws: the outcome (ok, noop or error) is
     * recorded in the returned sync log row and on the programme row.
     *
     * @param \stdClass $programme Programme record.
     * @param bool $force Apply the snapshot even if the revision hash is unchanged.
     * @return \stdClass The completed sync log record.
     */
    public function sync_programme(\stdClass $programme, bool $force = false): \stdClass {
        global $DB;

        $log = new \stdClass();
        $log->programmeid = $programme->id;
        $log->synctype = 'full';
        $log->timestart = time();
        $log->status = 'running';
        $log->fromhash = $programme->revisionhash ?? null;
        $log->id = $DB->insert_record('local_curricmap_synclog', $log);

        try {
            $compare = $this->client->compare($programme->slug, $programme->versionlabel, $programme->versionlabel);
            $tohash = $compare['meta']['compare']['to'] ?? ($compare['meta']['compare']['from'] ?? null);
            if (!is_string($tohash) || $tohash === '') {
                throw new \moodle_exception('errorsyncnohash', 'local_curricmap');
            }
            $log->tohash = $tohash;

            if (!$force && ($programme->revisionhash ?? null) === $tohash) {
                return $this->finish($log, $programme, 'noop');
            }

            $nodespayload = $this->client->nodes($programme->slug, $programme->versionlabel);
            $metadatapayload = $this->client->metadata($programme->slug, $programme->versionlabel);
            $log->nodesfetched = count($nodespayload);

            $stats = $this->apply($programme, $nodespayload, $metadatapayload, $tohash);
            foreach ($stats as $key => $value) {
                $log->$key = $value;
            }
            return $this->finish($log, $programme, 'ok', $tohash);
        } catch (\Throwable $exception) {
            $log->message = $exception->getMessage();
            return $this->finish($log, $programme, 'error');
        }
    }

    /**
     * Apply a snapshot to the database inside one transaction.
     *
     * @param \stdClass $programme Programme record.
     * @param array $nodespayload Decoded Nodes API payload (uuid => node).
     * @param array $metadatapayload Decoded Metadata API payload.
     * @param string $tohash Revision hash this snapshot represents.
     * @return array Statistics: nodesinserted, nodesupdated, nodesdeleted,
     *               edgeschanged, tagschanged.
     */
    private function apply(\stdClass $programme, array $nodespayload, array $metadatapayload, string $tohash): array {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $now = time();
            $derived = derive::build_rows($nodespayload);
            $rootuuid = $this->find_root($nodespayload);

            // Wholesale teardown of rebuilt structures (node ids stay stable).
            $nodescope = 'nodeid IN (SELECT id FROM {local_curricmap_node} WHERE programmeid = :pid)';
            $DB->delete_records_select('local_curricmap_nodetag', $nodescope, ['pid' => $programme->id]);
            $DB->delete_records('local_curricmap_edge', ['programmeid' => $programme->id]);

            $optionids = $this->sync_tag_schema($programme, $metadatapayload);

            $existing = $DB->get_records('local_curricmap_node', ['programmeid' => $programme->id]);
            $byuuid = [];
            foreach ($existing as $record) {
                $byuuid[$record->uuid] = $record;
            }

            [$idbyuuid, $inserted, $updated] = $this->upsert_nodes(
                $programme,
                $nodespayload,
                $derived,
                $byuuid,
                $tohash,
                $now
            );

            $deleted = $this->soft_delete_missing($byuuid, $derived, $tohash, $now);
            $edges = $this->insert_edges($programme, $nodespayload, $idbyuuid);
            $tags = $this->insert_nodetags($nodespayload, $idbyuuid, $optionids);

            $programme->revisionhash = $tohash;
            $programme->rootuuid = $rootuuid;
            $DB->update_record('local_curricmap_programme', $programme);

            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        return [
            'nodesinserted' => $inserted,
            'nodesupdated' => $updated,
            'nodesdeleted' => $deleted,
            'edgeschanged' => $edges,
            'tagschanged' => $tags,
        ];
    }

    /**
     * Upsert all nodes in derivation (breadth-first) order, so parents receive
     * their ids before children need them.
     *
     * @param \stdClass $programme Programme record.
     * @param array $nodespayload Nodes payload.
     * @param array $derived Output of derive::build_rows().
     * @param array $byuuid Existing node records keyed by uuid.
     * @param string $tohash Revision hash being applied.
     * @param int $now Timestamp.
     * @return array{0: array, 1: int, 2: int} Map uuid => id, inserted count, updated count.
     */
    private function upsert_nodes(\stdClass $programme, array $nodespayload, array $derived,
            array $byuuid, string $tohash, int $now): array {
        global $DB;

        $idbyuuid = [];
        $pathbyuuid = [];
        $inserted = 0;
        $updated = 0;

        foreach ($derived as $uuid => $row) {
            $node = $nodespayload[$uuid];
            $parentid = $row['parentuuid'] !== null ? ($idbyuuid[$row['parentuuid']] ?? null) : null;
            $parentpath = $row['parentuuid'] !== null ? ($pathbyuuid[$row['parentuuid']] ?? '/') : '/';

            $candidate = new \stdClass();
            $candidate->programmeid = $programme->id;
            $candidate->uuid = $uuid;
            $candidate->parentid = $parentid;
            $candidate->depth = $row['depth'];
            $candidate->type = $row['type'] !== '' ? $row['type'] : null;
            $candidate->subtype = $row['subtype'];
            $candidate->role = $row['role'];
            $candidate->code = isset($node['code']) ? (string) $node['code'] : null;
            $candidate->title = isset($node['title']) ? (string) $node['title'] : null;
            $candidate->description = isset($node['description']) ? (string) $node['description'] : null;
            $candidate->subtitle = isset($node['subtitle']) ? (string) $node['subtitle'] : null;
            $candidate->positionraw = isset($node['position']) ? (string) $node['position'] : null;
            $candidate->sortorder = $row['sortorder'];
            $candidate->grouplabel = $row['grouplabel'];
            $candidate->sofiaurl = isset($node['url']) ? (string) $node['url'] : null;
            $candidate->source = 'sofia';
            $candidate->metadata = !empty($node['doc']) ? json_encode($node['doc']) : null;
            $candidate->deleted = 0;

            $current = $byuuid[$uuid] ?? null;
            if ($current === null) {
                $candidate->sourceversion = $tohash;
                $candidate->timecreated = $now;
                $candidate->timemodified = $now;
                $candidate->lastsynced = $now;
                $id = $DB->insert_record('local_curricmap_node', $candidate);
                $inserted++;
            } else {
                $id = (int) $current->id;
                $candidate->path = $parentpath . $id . '/';
                if ($this->node_changed($current, $candidate)) {
                    $candidate->id = $id;
                    $candidate->sourceversion = $tohash;
                    $candidate->timemodified = $now;
                    $candidate->lastsynced = $now;
                    $DB->update_record('local_curricmap_node', $candidate);
                    $updated++;
                }
            }

            $path = $parentpath . $id . '/';
            if ($current === null) {
                $DB->set_field('local_curricmap_node', 'path', $path, ['id' => $id]);
            }
            $idbyuuid[$uuid] = $id;
            $pathbyuuid[$uuid] = $path;
        }

        return [$idbyuuid, $inserted, $updated];
    }

    /**
     * Has a node's stored content changed relative to the candidate row?
     *
     * @param \stdClass $current Existing record.
     * @param \stdClass $candidate Candidate record (path already computed).
     * @return bool
     */
    private function node_changed(\stdClass $current, \stdClass $candidate): bool {
        $intfields = ['parentid', 'depth', 'sortorder', 'deleted'];
        foreach ($intfields as $field) {
            $currentvalue = $current->$field === null ? null : (int) $current->$field;
            $candidatevalue = $candidate->$field === null ? null : (int) $candidate->$field;
            if ($currentvalue !== $candidatevalue) {
                return true;
            }
        }
        $stringfields = ['path', 'type', 'subtype', 'role', 'code', 'title', 'description',
            'subtitle', 'positionraw', 'grouplabel', 'sofiaurl', 'source', 'metadata'];
        foreach ($stringfields as $field) {
            if (($current->$field ?? null) !== ($candidate->$field ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Soft-delete rows whose uuid is absent from the new snapshot.
     *
     * @param array $byuuid Existing records keyed by uuid.
     * @param array $derived Derived rows keyed by uuid.
     * @param string $tohash Revision hash being applied.
     * @param int $now Timestamp.
     * @return int Number of rows soft-deleted.
     */
    private function soft_delete_missing(array $byuuid, array $derived, string $tohash, int $now): int {
        global $DB;

        $count = 0;
        foreach ($byuuid as $uuid => $record) {
            if (!isset($derived[$uuid]) && !(int) $record->deleted) {
                $update = new \stdClass();
                $update->id = $record->id;
                $update->deleted = 1;
                $update->sourceversion = $tohash;
                $update->timemodified = $now;
                $update->lastsynced = $now;
                $DB->update_record('local_curricmap_node', $update);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Rebuild the edge table from payload connections.
     *
     * @param \stdClass $programme Programme record.
     * @param array $nodespayload Nodes payload.
     * @param array $idbyuuid Map uuid => node id.
     * @return int Edges inserted.
     */
    private function insert_edges(\stdClass $programme, array $nodespayload, array $idbyuuid): int {
        global $DB;

        $records = [];
        $count = 0;
        foreach ($nodespayload as $uuid => $node) {
            if (empty($node['connections']) || !isset($idbyuuid[$uuid])) {
                continue;
            }
            foreach ($node['connections'] as $connectiontype => $targets) {
                foreach (array_values((array) $targets) as $sortorder => $targetuuid) {
                    if (!isset($idbyuuid[$targetuuid])) {
                        continue;
                    }
                    $records[] = (object) [
                        'programmeid' => $programme->id,
                        'sourceid' => $idbyuuid[$uuid],
                        'targetid' => $idbyuuid[$targetuuid],
                        'connectiontype' => substr((string) $connectiontype, 0, 40),
                        'sortorder' => $sortorder,
                    ];
                    $count++;
                    if (count($records) >= self::CHUNK) {
                        $DB->insert_records('local_curricmap_edge', $records);
                        $records = [];
                    }
                }
            }
        }
        if ($records) {
            $DB->insert_records('local_curricmap_edge', $records);
        }
        return $count;
    }

    /**
     * Rebuild node tags from payload tag data.
     *
     * Accepts both API tag formats: key-only lists (the live sync request) and
     * tag-format=object maps (used by the recorded fixtures).
     *
     * @param array $nodespayload Nodes payload.
     * @param array $idbyuuid Map uuid => node id.
     * @param array $optionids Map "fieldkey|optionkey" => tag option id.
     * @return int Node tags inserted.
     */
    private function insert_nodetags(array $nodespayload, array $idbyuuid, array $optionids): int {
        global $DB;

        $records = [];
        $count = 0;
        foreach ($nodespayload as $uuid => $node) {
            if (empty($node['tags']) || !isset($idbyuuid[$uuid])) {
                continue;
            }
            foreach ($node['tags'] as $fieldkey => $value) {
                foreach (array_values(self::tag_option_keys($value)) as $sortorder => $optionkey) {
                    $optionid = $optionids[$fieldkey . '|' . $optionkey] ?? null;
                    if ($optionid === null) {
                        continue;
                    }
                    $records[] = (object) [
                        'nodeid' => $idbyuuid[$uuid],
                        'tagoptionid' => $optionid,
                        'sortorder' => $sortorder,
                    ];
                    $count++;
                    if (count($records) >= self::CHUNK) {
                        $DB->insert_records('local_curricmap_nodetag', $records);
                        $records = [];
                    }
                }
            }
        }
        if ($records) {
            $DB->insert_records('local_curricmap_nodetag', $records);
        }
        return $count;
    }

    /**
     * Normalise one node's tag value to a list of option keys.
     *
     * @param mixed $value Either a list of option keys, or a tag-format=object map.
     * @return string[] Option keys in order.
     */
    public static function tag_option_keys($value): array {
        if (!is_array($value)) {
            return [];
        }
        if (array_is_list($value)) {
            return array_map('strval', $value);
        }
        if (isset($value['options']) && is_array($value['options'])) {
            return array_map('strval', array_keys($value['options']));
        }
        return [];
    }

    /**
     * Upsert the tag schema (fields with options) and prune removed entries.
     *
     * Connection fields (no options key in the Metadata API) are not tag
     * categories and are skipped - connections live in the edge table.
     *
     * @param \stdClass $programme Programme record.
     * @param array $metadatapayload Metadata API payload.
     * @return array Map "fieldkey|optionkey" => tag option id.
     */
    private function sync_tag_schema(\stdClass $programme, array $metadatapayload): array {
        global $DB;

        $optionids = [];
        $keepfields = [];

        foreach (($metadatapayload['fields'] ?? []) as $index => $field) {
            if (!is_array($field) || !array_key_exists('options', $field) || empty($field['key'])) {
                continue;
            }
            $fieldkey = (string) $field['key'];

            $fieldrecord = $DB->get_record('local_curricmap_tagfield',
                ['programmeid' => $programme->id, 'fieldkey' => $fieldkey]);
            $values = new \stdClass();
            $values->programmeid = $programme->id;
            $values->fieldkey = $fieldkey;
            $values->name = isset($field['name']) ? (string) $field['name'] : null;
            $values->plural = isset($field['plural']) ? (string) $field['plural'] : null;
            $values->sortorder = $index;
            if ($fieldrecord) {
                $values->id = $fieldrecord->id;
                $DB->update_record('local_curricmap_tagfield', $values);
                $fieldid = (int) $fieldrecord->id;
            } else {
                $fieldid = $DB->insert_record('local_curricmap_tagfield', $values);
            }
            $keepfields[] = $fieldid;

            $keepoptions = [];
            foreach (array_values((array) $field['options']) as $optionindex => $option) {
                if (!is_array($option) || !isset($option['key'])) {
                    continue;
                }
                $optionkey = (string) $option['key'];
                $optionrecord = $DB->get_record('local_curricmap_tagoption',
                    ['tagfieldid' => $fieldid, 'optionkey' => $optionkey]);
                $optionvalues = new \stdClass();
                $optionvalues->tagfieldid = $fieldid;
                $optionvalues->optionkey = $optionkey;
                $optionvalues->name = isset($option['name']) ? (string) $option['name'] : null;
                $optionvalues->sortorder = $optionindex;
                if ($optionrecord) {
                    $optionvalues->id = $optionrecord->id;
                    $DB->update_record('local_curricmap_tagoption', $optionvalues);
                    $optionid = (int) $optionrecord->id;
                } else {
                    $optionid = $DB->insert_record('local_curricmap_tagoption', $optionvalues);
                }
                $keepoptions[] = $optionid;
                $optionids[$fieldkey . '|' . $optionkey] = $optionid;
            }

            $this->prune('local_curricmap_tagoption', 'tagfieldid = :fid', ['fid' => $fieldid], $keepoptions);
        }

        // Remove options of removed fields first, then the fields themselves.
        $fieldscope = 'programmeid = :pid';
        $params = ['pid' => $programme->id];
        $removedfields = $DB->get_fieldset_select('local_curricmap_tagfield', 'id', $fieldscope, $params);
        $removedfields = array_diff(array_map('intval', $removedfields), $keepfields);
        if ($removedfields) {
            [$insql, $inparams] = $DB->get_in_or_equal($removedfields, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_curricmap_tagoption', "tagfieldid $insql", $inparams);
            $DB->delete_records_select('local_curricmap_tagfield', "id $insql", $inparams);
        }

        return $optionids;
    }

    /**
     * Delete rows in a scope whose ids are not in the keep list.
     *
     * @param string $table Table name.
     * @param string $scopesql Scope WHERE fragment with named params.
     * @param array $scopeparams Scope parameters.
     * @param int[] $keepids Ids to keep.
     */
    private function prune(string $table, string $scopesql, array $scopeparams, array $keepids): void {
        global $DB;

        if ($keepids) {
            [$insql, $inparams] = $DB->get_in_or_equal($keepids, SQL_PARAMS_NAMED, 'keep', false);
            $DB->delete_records_select($table, "$scopesql AND id $insql", $scopeparams + $inparams);
        } else {
            $DB->delete_records_select($table, $scopesql, $scopeparams);
        }
    }

    /**
     * Find the root node uuid in a payload.
     *
     * @param array $nodespayload Nodes payload.
     * @return string|null
     */
    private function find_root(array $nodespayload): ?string {
        foreach ($nodespayload as $uuid => $node) {
            if (($node['type'] ?? '') === 'r') {
                return (string) $uuid;
            }
        }
        return null;
    }

    /**
     * Complete a sync log row and mirror the outcome onto the programme row.
     *
     * @param \stdClass $log Sync log record (already inserted).
     * @param \stdClass $programme Programme record.
     * @param string $status ok, noop or error.
     * @param string|null $appliedhash Hash applied, for timelastchanged bookkeeping.
     * @return \stdClass The completed log record.
     */
    private function finish(\stdClass $log, \stdClass $programme, string $status, ?string $appliedhash = null): \stdClass {
        global $DB;

        $log->status = $status;
        $log->timeend = time();
        $log->requestcount = $this->client->request_count();
        $log->ratelimitremaining = $this->client->remaining();
        $DB->update_record('local_curricmap_synclog', $log);

        $programme->lastsyncstatus = $status;
        $programme->timelastsynced = $log->timeend;
        if ($appliedhash !== null) {
            $programme->timelastchanged = $log->timeend;
        }
        $DB->update_record('local_curricmap_programme', $programme);
        return $log;
    }
}
