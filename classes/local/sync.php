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
     * The node-key prefix for a programme: slug plus academic year, underscored.
     *
     * A numeric version label 2025 becomes 2025_26; non-numeric labels (LATEST,
     * UPCOMING) are lowercased. Example prefix: vet-med_2025_26.
     *
     * @param \stdClass $programme Programme record.
     * @return string
     */
    public static function programme_prefix(\stdClass $programme): string {
        $label = trim((string) $programme->versionlabel);
        if (preg_match('/^\d{4}$/', $label)) {
            $label = $label . '_' . sprintf('%02d', ((int) $label + 1) % 100);
        } else {
            $label = strtolower($label);
        }
        return $programme->slug . '_' . $label;
    }

    /**
     * The globally unique node key for a Sofia uuid within a programme mirror:
     * slug_academicyear_uuid, e.g. vet-med_2025_26_ec917dc5-....
     *
     * @param \stdClass $programme Programme record.
     * @param string $uuid Raw Sofia node uuid.
     * @return string
     */
    public static function nodekey(\stdClass $programme, string $uuid): string {
        return self::programme_prefix($programme) . '_' . $uuid;
    }

    /**
     * The configured slugs (the programmeslugs setting holds slugs only; academic
     * years are auto-discovered). Legacy slug:version entries are tolerated by
     * stripping the version part.
     *
     * @return string[]
     */
    public static function configured_slugs(): array {
        $setting = (string) get_config('local_curricmap', 'programmeslugs');
        $slugs = [];
        foreach (array_filter(array_map('trim', explode(',', $setting))) as $entry) {
            $slug = trim(explode(':', $entry, 2)[0]);
            if ($slug !== '') {
                $slugs[$slug] = $slug;
            }
        }
        return array_values($slugs);
    }

    /**
     * Reconcile programme rows against the configured slugs: rows for configured
     * slugs are enabled, others disabled (never deleted - their data stays).
     * Rows themselves are created by discover_programmes().
     *
     * @return \stdClass[] Enabled programme records.
     */
    public static function ensure_programmes(): array {
        global $DB;

        $slugs = self::configured_slugs();
        foreach ($DB->get_records('local_curricmap_programme') as $record) {
            $shouldenable = in_array($record->slug, $slugs, true) ? 1 : 0;
            if ((int) $record->enabled !== $shouldenable) {
                $DB->set_field('local_curricmap_programme', 'enabled', $shouldenable, ['id' => $record->id]);
            }
        }

        return $DB->get_records('local_curricmap_programme', ['enabled' => 1], 'slug ASC, versionlabel ASC');
    }

    /**
     * Discover academic-year versions for the configured slugs by probing the
     * Compare API (Sofia has no list-versions endpoint; compare/YYYY/YYYY costs
     * one request and 404s for absent years). Only years without an existing
     * row are probed, so steady-state cost is one probe per slug per run (next
     * year's slot) until Sofia rolls over - no annual settings visit needed.
     *
     * @param \local_curricmap\api\client|null $client Injectable client for tests.
     * @return array{created: int, probed: int}
     */
    public static function discover_programmes(?\local_curricmap\api\client $client = null): array {
        global $DB;

        $client = $client ?? new \local_curricmap\api\client();
        $result = ['created' => 0, 'probed' => 0];
        if (!$client->is_configured()) {
            return $result;
        }

        $floor = (int) get_config('local_curricmap', 'discoveryfloor');
        if ($floor < 2000) {
            $floor = 2020;
        }
        $ceiling = (int) date('Y') + 1;

        foreach (self::configured_slugs() as $slug) {
            $existing = $DB->get_records_menu('local_curricmap_programme', ['slug' => $slug], '', 'versionlabel, id');
            for ($year = $floor; $year <= $ceiling; $year++) {
                if (isset($existing[(string) $year])) {
                    continue;
                }
                $result['probed']++;
                try {
                    $client->compare($slug, (string) $year, (string) $year);
                } catch (\local_curricmap\api\client_exception $exception) {
                    if ($exception->httpcode === 404) {
                        continue;
                    }
                    throw $exception;
                }
                $record = new \stdClass();
                $record->slug = $slug;
                $record->versionlabel = (string) $year;
                $record->enabled = 1;
                $record->lastsyncstatus = 'never';
                $DB->insert_record('local_curricmap_programme', $record);
                $result['created']++;
            }
        }

        set_config('lastdiscovery', time(), 'local_curricmap');
        return $result;
    }

    /**
     * Is this programme in the hourly sync tier?
     *
     * The two most recent discovered years per slug (on live: latest + upcoming)
     * and any non-numeric labels sync hourly; older years are essentially frozen
     * and sync daily (Sofia allows minor corrections to past versions).
     *
     * @param \stdClass $programme Programme record.
     * @param \stdClass[] $allenabled All enabled programme records.
     * @return bool
     */
    public static function is_hourly(\stdClass $programme, array $allenabled): bool {
        if (!preg_match('/^\d{4}$/', (string) $programme->versionlabel)) {
            return true;
        }
        $years = [];
        foreach ($allenabled as $other) {
            if ($other->slug === $programme->slug && preg_match('/^\d{4}$/', (string) $other->versionlabel)) {
                $years[] = (int) $other->versionlabel;
            }
        }
        rsort($years);
        $cutoff = $years[min(1, count($years) - 1)] ?? (int) $programme->versionlabel;
        return (int) $programme->versionlabel >= $cutoff;
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
            // Comparing from the stored hash (when we have one) both detects change
            // and yields the human-readable delta report; the fallback of comparing
            // a label with itself still resolves the current hash.
            $comparefrom = $log->fromhash ?: $programme->versionlabel;
            $compare = $this->client->compare($programme->slug, $comparefrom, $programme->versionlabel);
            $tohash = $compare['meta']['compare']['to'] ?? ($compare['meta']['compare']['from'] ?? null);
            if (!is_string($tohash) || $tohash === '') {
                throw new \moodle_exception('errorsyncnohash', 'local_curricmap');
            }
            $log->tohash = $tohash;

            if (!$force && ($programme->revisionhash ?? null) === $tohash) {
                return $this->finish($log, $programme, 'noop');
            }

            $log->message = $this->change_report($compare, $log->fromhash);

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

            // Node keys are composed (slug_year_uuid), so mirrors of different
            // versions coexist and lookups stay programme-unambiguous.
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

            $deleted = $this->soft_delete_missing(self::programme_prefix($programme), $byuuid, $derived, $tohash, $now);
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
    private function upsert_nodes(
        \stdClass $programme,
        array $nodespayload,
        array $derived,
        array $byuuid,
        string $tohash,
        int $now
    ): array {
        global $DB;

        $idbyuuid = [];
        $pathbyuuid = [];
        $inserted = 0;
        $updated = 0;

        $prefix = self::programme_prefix($programme);
        foreach ($derived as $uuid => $row) {
            $node = $nodespayload[$uuid];
            $parentid = $row['parentuuid'] !== null ? ($idbyuuid[$row['parentuuid']] ?? null) : null;
            $parentpath = $row['parentuuid'] !== null ? ($pathbyuuid[$row['parentuuid']] ?? '/') : '/';

            $candidate = new \stdClass();
            $candidate->programmeid = $programme->id;
            $candidate->uuid = $prefix . '_' . $uuid;
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

            $current = $byuuid[$candidate->uuid] ?? null;
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
        $intfields = ['programmeid', 'parentid', 'depth', 'sortorder', 'deleted'];
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
     * Soft-delete this programme's rows whose node is absent from the new snapshot.
     *
     * The records are already scoped to the programme by the caller's fetch;
     * their stored keys are composed (prefix_uuid), so the prefix is stripped to
     * compare against the payload's raw uuids.
     *
     * @param string $prefix The programme's node-key prefix.
     * @param array $byuuid Existing programme records keyed by composed node key.
     * @param array $derived Derived rows keyed by raw uuid.
     * @param string $tohash Revision hash being applied.
     * @param int $now Timestamp.
     * @return int Number of rows soft-deleted.
     */
    private function soft_delete_missing(string $prefix, array $byuuid, array $derived, string $tohash, int $now): int {
        global $DB;

        $count = 0;
        foreach ($byuuid as $key => $record) {
            $raw = substr((string) $key, strlen($prefix) + 1);
            if (!isset($derived[$raw]) && !(int) $record->deleted) {
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

            $fieldparams = ['programmeid' => $programme->id, 'fieldkey' => $fieldkey];
            $fieldrecord = $DB->get_record('local_curricmap_tagfield', $fieldparams);
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
                $optionparams = ['tagfieldid' => $fieldid, 'optionkey' => $optionkey];
                $optionrecord = $DB->get_record('local_curricmap_tagoption', $optionparams);
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
     * Build a human-readable change report from a Compare API payload.
     *
     * @param array $compare Compare API payload.
     * @param string|null $fromhash The stored hash the comparison ran from, if any.
     * @return string
     */
    private function change_report(array $compare, ?string $fromhash): string {
        if (!$fromhash) {
            return 'Initial full sync.';
        }
        $meta = $compare['meta'] ?? [];
        $lines = [sprintf(
            '%d added, %d removed, %d modified since %s',
            (int) ($meta['added'] ?? 0),
            (int) ($meta['removed'] ?? 0),
            (int) ($meta['modified'] ?? 0),
            substr($fromhash, 0, 12)
        )];
        $changes = $compare['changes'] ?? [];
        foreach (array_slice($changes, 0, 15) as $change) {
            $parts = [
                $change['class'] ?? '?',
                $change['type'] ?? '',
                $change['code'] ?? '',
                isset($change['preview']) ? '"' . $change['preview'] . '"' : '',
            ];
            $lines[] = trim(implode(' ', array_filter($parts)));
        }
        if (count($changes) > 15) {
            $lines[] = '... and ' . (count($changes) - 15) . ' more';
        }
        return implode("\n", $lines);
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
