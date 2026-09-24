# Extracting the Sofia curriculum map from Moodle

How a developer gets the Sofia curriculum graph **out of a Moodle site running
`local_curricmap`** — the sync status, the programme slug-years, the node
counts, and a full structural copy (nodes + edges) of any slug-year — using
the plugin's web services, with the gaps spelled out: what Sofia holds that
the Moodle mirror stores but does not expose over the web service (reachable
by SQL), and what the mirror never stores at all.

The companion document [SOFIA_API.md](SOFIA_API.md) describes the other
direction: how the plugin pulls the map from Sofia's own API and keeps it
fresh. Read that if you want to build a mirror of your own; read this if you
want to consume the one Moodle already has.

**Why go through Moodle rather than Sofia directly:** Sofia's API is
rate-limited to 60 requests/hour per site. The plugin pays that budget once,
hourly, and its web service is unthrottled, already carries the derived roles
(`year`, `strand`, `session`, `strandoutcome`…) and the year-pinned composed
node keys that every Moodle mapping table uses. The data platform's
`curriculum_staging.sofia_*` tables are loaded exactly this way.

All examples use:

```bash
BASE="https://learn-uat.rvc.ac.uk/webservice/rest/server.php"
ARGS="wstoken=$TOKEN&moodlewsrestformat=json"
```

Version: written against `local_curricmap` v0.34.0 (`db/services.php`,
`classes/external/get_nodes.php`, `classes/api/curriculum.php`). Programme
ids, uuids and hashes in the example responses are illustrative — the shapes
are exact, the values are not.

---

## 1. Transport and authorisation

- REST only: `POST $BASE` with form fields `wstoken`, `wsfunction`,
  `moodlewsrestformat=json` plus the function's own parameters. GET works
  too but keep tokens out of URLs.
- The base URL must be **https** (the RVC test hosts 303 http and drop the path).
- The token must be issued for the **Curriculum mapping API** service
  (shortname `curricmap_mapping`, restricted users). Add the ws user to the
  service's authorised users under *Site administration → Server → Web
  services → External services*.
- The read functions in this document all require the capability
  `local/curricmap:viewstaffmeta`. `get_nodes` and `get_edges` check it in the
  **system** context; `get_programmes`, `get_children` and `search` check it
  in the context of the `courseid` you pass (site course `1` is fine for a
  system-wide role). No course enrolments are needed.
- Errors come back as `200 OK` with a JSON object carrying an `exception`
  key — always test for it:

```bash
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_programmes -d courseid=1 \
  | jq 'if type=="object" and has("exception") then error(.message) else . end'
```

Service function list (curriculum reads in bold):

| Function | Purpose |
|---|---|
| **`local_curricmap_get_programmes`** | Enumerate enabled slug-years |
| **`local_curricmap_get_nodes`** | Full node rows of one slug-year (pageable, optional subtree) |
| **`local_curricmap_get_edges`** | Directed edges of one slug-year |
| **`local_curricmap_get_children`** | Browse one level at a time (picker-shaped) |
| **`local_curricmap_search`** | Ranked title/code search |
| `local_curricmap_bind` / `unbind` / `list_bindings` / `resolve` | Moodle↔node bindings — see [BINDING_API.md](BINDING_API.md) |
| `local_curricmap_*_resource*` | Study resources attached to nodes — see [BINDING_API.md](BINDING_API.md) |
| `core_course_get_categories` / `get_courses` / `get_contents` | Core lookups bundled so one token can address Moodle locations |

---

## 2. Current status: is the mirror fresh, and how much Sofia budget is left?

There is **no status web-service function**. Status lives in three places.

### 2.1 The admin status page (human)

`https://<moodle>/local/curricmap/status.php` (site admin, *Site
administration → Plugins → Local plugins → Curriculum map → Status*). It shows:

- **Connection** — Sofia base URL, configured yes/no, the last rate budget seen
  (`count/limit` from Sofia's `X-Sofia-Request-Count` / `X-Sofia-Request-Limit`
  headers and how long ago), a warning with the expected return time if Sofia
  is currently rate-limiting, and a rolling-window forecast from the plugin's
  own request log ("N requests sent in the last hour — next slot frees ~…,
  full budget back ~…").
- **Test connection** button — costs one Sofia request
  (`compare/LATEST/LATEST` on the first configured slug) and reports the
  current `LATEST` revision hash, the round-trip time and the remaining budget.
- **Programmes** table — one row per slug-year: revision hash, last sync
  status (`ok` / `noop` / `error` / `never`), last synced time, **active node
  count**; with *Sync now*, *Force sync*, *Purge* and *Discover versions*
  buttons.
- **Recent syncs** — the sync log, and a CSV export
  (`status.php?action=csv&sesskey=…`) with columns
  `id, programme, version, type, start, end, status, fromhash, tohash,
  fetched, inserted, updated, deleted, edges, tags, requests, remaining,
  message`.

### 2.2 Per slug-year status over the web service

`get_nodes` with `limitnum=1` returns the programme wrapper (including the
revision hash the mirror is at) and the total node count without pulling the
graph:

```bash
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_nodes \
  -d programmeid=7 -d limitnum=1 | jq '{programme, total}'
```

```json
{
  "programme": {
    "id": 7,
    "slug": "vet-nur",
    "versionlabel": "2025",
    "displayname": "vet-nur",
    "revisionhash": "3f1c0a9e5d2b7c4e8a6f1d0b9c8e7a6f5d4c3b2a"
  },
  "total": 2312
}
```

The web service does not expose *when* that hash was synced. Compare
`revisionhash` against Sofia's `compare/<year>/<year>` `meta.compare.to` if
you need to know whether the mirror is behind (that costs one Sofia request).

### 2.3 Status by SQL (if you have database access)

Table prefix is `mdl_` on the RVC hosts (`m_` on the moodle-docker
playground).

```sql
-- One row per slug-year: freshness and last outcome
SELECT id, slug, versionlabel, enabled, lastsyncstatus,
       FROM_UNIXTIME(timelastsynced)  AS last_synced,
       FROM_UNIXTIME(timelastchanged) AS last_changed,
       revisionhash, rootuuid
FROM mdl_local_curricmap_programme
ORDER BY slug, versionlabel;

-- Last ten sync runs with their request spend
SELECT l.id, p.slug, p.versionlabel, l.status,
       FROM_UNIXTIME(l.timestart) AS started, l.timeend - l.timestart AS secs,
       l.nodesfetched, l.nodesinserted, l.nodesupdated, l.nodesdeleted,
       l.edgeschanged, l.tagschanged, l.requestcount, l.ratelimitremaining,
       LEFT(l.message, 120) AS message
FROM mdl_local_curricmap_synclog l
JOIN mdl_local_curricmap_programme p ON p.id = l.programmeid
ORDER BY l.id DESC LIMIT 10;

-- Sofia budget as last observed (plugin config)
SELECT name, value FROM mdl_config_plugins
WHERE plugin = 'local_curricmap'
  AND name IN ('lastratecount','lastratelimit','lastrateseen','ratebudgetback','lastdiscovery');
```

(`FROM_UNIXTIME` is MySQL/MariaDB; on Postgres use `to_timestamp(...)`.)

---

## 3. Enumerating slugs and years

```bash
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_programmes -d courseid=1 | jq .
```

```json
[
  {"id": 3, "slug": "bio-sc",  "versionlabel": "2025"},
  {"id": 4, "slug": "bio-sc",  "versionlabel": "2026"},
  {"id": 1, "slug": "vet-med", "versionlabel": "2025"},
  {"id": 2, "slug": "vet-med", "versionlabel": "2026"},
  {"id": 7, "slug": "vet-nur", "versionlabel": "2025"},
  {"id": 8, "slug": "vet-nur", "versionlabel": "2026"}
]
```

What you are looking at:

- One row per **slug-year** = one Sofia programme (`slug`) × one Sofia version
  (`versionlabel`, a four-digit academic year: `2025` means 2025/26). The
  plugin discovers years itself by probing Sofia; you never see `LATEST` /
  `UPCOMING` labels here because the mirror pins to concrete years.
- Only **enabled** programmes are listed (slugs present in the
  `programmeslugs` admin setting). Disabled programmes keep their data but
  disappear from this call; their `programmeid` still works in `get_nodes`.
- `id` is the Moodle-side programme id you pass to every other call. It is
  stable for the life of the row (a purge-and-rediscover produces a new id).
- Order is `slug ASC, versionlabel ASC`.

The **composed node key** prefix for a slug-year is derived from these two
fields and is what every node `uuid` in the web service starts with:

```
prefix  = slug + "_" + YYYY + "_" + (YY+1)        e.g. vet-nur_2025_26
nodekey = prefix + "_" + <raw Sofia uuid>          e.g. vet-nur_2025_26_ec917dc5-8b3a-4f2e-9c1d-0a7b6e5f4d3c
```

(Non-numeric labels, if any legacy rows exist, are lowercased:
`vet-med_latest_<uuid>`.) The raw Sofia uuid is always the last 36 characters.

---

## 4. Counting objects in a slug-year

### 4.1 Totals over the web service

`total` from `get_nodes` is the count of rows matching the call's filters,
ignoring paging:

```bash
# active (not soft-deleted) nodes
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_nodes -d programmeid=7 -d limitnum=1 | jq .total
# including soft-deleted rows (nodes Sofia has since removed)
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_nodes -d programmeid=7 -d limitnum=1 -d includedeleted=1 | jq .total
# a subtree only (a year node, a strand ...) — the ancestor itself is included
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_nodes -d programmeid=7 -d limitnum=1 \
  -d ancestoruuid=vet-nur_2025_26_ec917dc5-8b3a-4f2e-9c1d-0a7b6e5f4d3c | jq .total
```

Edge count: `get_edges` has no paging or total, so count the array:

```bash
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_edges -d programmeid=7 | jq length
```

### 4.2 Counts per role / type / subtype

No web-service call groups; pull the slug-year once (§5) and count locally:

```bash
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_nodes -d programmeid=7 -d limitnum=0 \
  > vet-nur_2025_nodes.json

jq '.nodes | group_by(.role) | map({role: .[0].role, n: length})' vet-nur_2025_nodes.json
jq '.nodes | group_by(.type) | map({type: .[0].type, n: length})' vet-nur_2025_nodes.json
jq '.nodes | map(select(.role=="session")) | group_by(.subtype) | map({subtype: .[0].subtype, n: length})' vet-nur_2025_nodes.json
jq '.nodes | map(select(.role=="year")) | map({uuid, code, title})' vet-nur_2025_nodes.json
```

The role vocabulary (derived by the plugin at sync time from Sofia type,
subtype and parent — see [SOFIA_API.md §5](SOFIA_API.md)):

| role | meaning | typical Sofia origin |
|---|---|---|
| `year` | a course year | `Y`, or `U` with subtype Year/Course |
| `strand` | module-like division of a year | `U` with subtype Strand/Module, or `U` directly under a year |
| `unit` | container between strand and sessions (only where Sofia models it as a node) | untyped `U` under a strand |
| `strandoutcome` | outcome owned above session level (strand, unit, year) | `O` |
| `session` | taught event (lecture, practical, DLI…) | `E` |
| `sessionoutcome` | outcome owned by a session or assessment | `O` |
| `assessment` | assessment item | `Z` |
| `group` | generic container | `G` |
| `programmeoutcome` | outcome at the top level of the tree | top-level `O` |
| `other` | unrecognised — visible to admins, hidden from consumers | anything else |

### 4.3 Counts by SQL

```sql
SELECT p.slug, p.versionlabel, n.role, n.type, n.subtype, COUNT(*) AS n
FROM mdl_local_curricmap_node n
JOIN mdl_local_curricmap_programme p ON p.id = n.programmeid
WHERE n.deleted = 0
GROUP BY p.slug, p.versionlabel, n.role, n.type, n.subtype
ORDER BY p.slug, p.versionlabel, n.role, n.type, n.subtype;

SELECT p.slug, p.versionlabel, e.connectiontype, COUNT(*) AS n
FROM mdl_local_curricmap_edge e
JOIN mdl_local_curricmap_programme p ON p.id = e.programmeid
GROUP BY p.slug, p.versionlabel, e.connectiontype;
```

---

## 5. Extracting a slug-year: nodes

### 5.1 The call

```bash
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_nodes \
  -d programmeid=7 -d includedeleted=1 -d limitfrom=0 -d limitnum=0 > vet-nur_2025_nodes.json
```

Parameters:

| name | default | meaning |
|---|---|---|
| `programmeid` | required | from `get_programmes` |
| `ancestoruuid` | `''` | restrict to the subtree below this composed key (itself included). Unknown key → `total: 0`. |
| `includedeleted` | `0` | include soft-deleted rows, flagged `deleted: true` |
| `limitfrom` | `0` | paging offset |
| `limitnum` | `0` | page size; **0 = everything in one response** (verified at production scale: vet-med 2026 = 10,394 nodes in one call) |

Response shape:

```json
{
  "programme": {"id": 7, "slug": "vet-nur", "versionlabel": "2025", "displayname": "vet-nur", "revisionhash": "3f1c…"},
  "total": 2312,
  "nodes": [
    {
      "uuid": "vet-nur_2025_26_ec917dc5-8b3a-4f2e-9c1d-0a7b6e5f4d3c",
      "role": "year",
      "type": "Y",
      "code": "VN1",
      "title": "Year 1",
      "sortorder": 0,
      "depth": 0,
      "source": "sofia",
      "sourceversion": "3f1c0a9e5d2b7c4e8a6f1d0b9c8e7a6f5d4c3b2a",
      "sofiaurl": "https://<sofia-site>/map/vet-nur/2025/ec917dc5-8b3a-4f2e-9c1d-0a7b6e5f4d3c",
      "deleted": false,
      "timemodified": 1756112345
    },
    {
      "uuid": "vet-nur_2025_26_0a7b6e5f-4d3c-4b2a-9c1d-ec917dc58b3a",
      "parentuuid": "vet-nur_2025_26_ec917dc5-8b3a-4f2e-9c1d-0a7b6e5f4d3c",
      "role": "strand",
      "type": "U",
      "subtype": "Strand",
      "code": "VN1-AAU",
      "title": "Anaesthesia and Analgesia",
      "description": "…",
      "sortorder": 3,
      "depth": 1,
      "source": "sofia",
      "deleted": false,
      "timemodified": 1756112345
    }
  ]
}
```

Node fields (optional fields are **absent**, never null, when empty):

| field | always | meaning |
|---|---|---|
| `uuid` | yes | composed key `slug_YYYY_YY_<sofia-uuid>` — the identity to persist |
| `parentuuid` | top-level nodes omit it | composed key of the parent; with `ancestoruuid` the ancestor's own parent is still reported even though that node is outside the response |
| `role` | yes | derived role (§4.2) |
| `type` | Sofia rows | raw Sofia type letter (`Y U E O Z G …`); absent on csv/manual rows |
| `subtype` | if set | Sofia `doc.typeName` (Lecture, Practical, Strand…) |
| `code` | if set | Sofia code (`VN1-AAU-LE4-LO1`) |
| `title` | yes | Sofia's coalesced title (long outcome text stored in `text` is already promoted to title) |
| `description` | if set | Sofia's coalesced description |
| `grouplabel` | if set | `doc["sofia:grouping:group"]` — the free-text "Unit n: …" / "THEME: …" display grouping of a session |
| `sortorder` | yes | index among siblings, in Sofia's presentation order |
| `depth` | yes | 0 = top level under the (unstored) Sofia root |
| `sofiaurl` | if set | deep link into the Sofia app (staff-only surface — students have no Sofia access) |
| `pebblepadurl`, `pebblepadlabel` | if set | manual/CSV rows only |
| `source` | yes | `sofia` (immutable mirror), `csv` or `manual` (authored in Moodle) |
| `sourceversion` | if set | Sofia revision hash in which this row last changed |
| `deleted` | yes | `true` only with `includedeleted=1`: Sofia no longer has the node (or it became unreachable from the root) |
| `timemodified` | yes | unix time the Moodle row last changed (not a Sofia timestamp) |

### 5.2 Rebuilding the tree

Rows come back in **row-id order** (`id ASC`), which is roughly insertion
order — *not* tree order. Rebuild the tree from `parentuuid` + `sortorder`:

```python
import json
data = json.load(open("vet-nur_2025_nodes.json"))
nodes = {n["uuid"]: n for n in data["nodes"] if not n["deleted"]}
children = {}
for n in nodes.values():
    children.setdefault(n.get("parentuuid"), []).append(n)
for siblings in children.values():
    siblings.sort(key=lambda n: n["sortorder"])

def walk(parent=None, indent=0):
    for n in children.get(parent, []):
        print("  " * indent + f'{n["role"]:14} {n.get("code","")!s:22} {n["title"][:70]}')
        walk(n["uuid"], indent + 1)

walk()
```

Invariants you can rely on (and should assert in a loader):

- `len(nodes) == total` when `limitnum=0` — a mismatch means a paging bug.
- Every non-empty `parentuuid` resolves to a node in the same slug-year
  (when not using `ancestoruuid`). Deleted rows may point at deleted parents.
- `depth` equals the number of `parentuuid` hops to a top-level node.
- The Sofia **root node is not present** (the plugin never stores it). Top-level
  rows are the root's children: normally the year nodes, but also whatever
  else an editor has put at the root (test folders, top-level programme
  outcomes). **Select the year(s) you want; never assume one top-level node.**

### 5.3 Paging, if you must

`limitnum` > 0 pages in `id ASC` order, which is stable across pages as long
as no sync lands mid-extract. Loop until `limitfrom >= total`, then verify
the row count. Prefer a single `limitnum=0` call — the payloads are a few MB
at most.

### 5.4 Subtrees

`ancestoruuid` uses the stored materialised path, so it is exact and cheap:

```bash
# everything under one strand, including the strand row itself
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_nodes -d programmeid=7 \
  -d ancestoruuid=vet-nur_2025_26_0a7b6e5f-4d3c-4b2a-9c1d-ec917dc58b3a | jq '.nodes | length'
```

---

## 6. Extracting a slug-year: edges

```bash
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_edges -d programmeid=7 > vet-nur_2025_edges.json
# or one type only
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_edges -d programmeid=7 -d connectiontype=implements
```

```json
[
  {
    "sourceuuid": "vet-nur_2025_26_5d4c3b2a-…",
    "targetuuid": "vet-nur_2025_26_9c8e7a6f-…",
    "connectiontype": "implements",
    "sortorder": 0
  }
]
```

- Edges are **directed**, stored on the Sofia source node, exactly as Sofia's
  Nodes API `connections` object presents them. `implements` runs session
  outcome → strand outcome; `event-outcome` runs event → outcome elsewhere in
  the tree; and so on (`unit-outcome`, `assessment-outcome`,
  `outcome-outcome`, `follows`, `prerequisites`, `outcome-atom`, `atom-atom`,
  plus institution-defined connection types whose key is a 40-hex-char hash).
- `connectiontype` is Sofia's raw key truncated to 40 characters.
- `sortorder` is the position within the source node's connection list, in
  Sofia's presentation order (the plugin requests `?connection-sort`).
- Both ends are composed keys in the same slug-year and always resolve to a
  row from `get_nodes` (edges are rebuilt from scratch every sync, only
  between nodes present in that snapshot; 0 dangling edges of 3,791 measured
  on the live mirror). Edges to soft-deleted nodes do not survive a sync.
- No paging; the whole edge set comes in one response (vet-med Y1 alone has
  3,786 `implements` edges — fine).

Reverse index for "which sessions teach strand outcome X":

```bash
jq 'map(select(.connectiontype=="implements")) | group_by(.targetuuid) | map({target: .[0].targetuuid, sources: map(.sourceuuid)})' vet-nur_2025_edges.json
```

---

## 7. Browsing and searching (picker-shaped helpers)

These exist for the Moodle UI pickers; they are convenient for spot checks
but not for extraction (shallow, filtered, ranked).

```bash
# one level: top-level nodes of a slug-year (years, plus strands when withstrands=1)
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_children -d courseid=1 -d programmeid=7 -d withstrands=1
# children of a node
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_get_children -d courseid=1 -d programmeid=7 \
  -d parentuuid=vet-nur_2025_26_0a7b6e5f-4d3c-4b2a-9c1d-ec917dc58b3a

# ranked search by title/code within a slug-year (programmeid=0 searches every enabled mirror);
# ancestoruuid optionally locks the search to one subtree
curl -s "$BASE" -d "$ARGS" -d wsfunction=local_curricmap_search -d courseid=1 -d programmeid=7 -d query="anaesth"
```

Search matches title and code (synonym-expanded; exact code match ranks
first) and returns at most 50. An optional `roles[]` parameter restricts the
roles offered (`-d "roles[0]=strand" -d "roles[1]=strandoutcome"`); with none
given every role is searched, `other` included.

---

## 8. A full copy of a slug-year, repeated for every slug-year

```bash
#!/usr/bin/env bash
# dump_sofia_mirror.sh — one nodes + one edges file per slug-year
set -euo pipefail
BASE="https://learn-uat.rvc.ac.uk/webservice/rest/server.php"
ARGS="wstoken=$TOKEN&moodlewsrestformat=json"
OUT="${1:-sofia_mirror}"; mkdir -p "$OUT"

call() { curl -sf "$BASE" -d "$ARGS" -d "wsfunction=$1" "${@:2}" \
         | jq -e 'if type=="object" and has("exception") then error(.message) else . end'; }

call local_curricmap_get_programmes -d courseid=1 > "$OUT/programmes.json"

jq -c '.[]' "$OUT/programmes.json" | while read -r p; do
  id=$(jq -r .id <<<"$p"); slug=$(jq -r .slug <<<"$p"); year=$(jq -r .versionlabel <<<"$p")
  call local_curricmap_get_nodes -d programmeid="$id" -d includedeleted=1 -d limitnum=0 > "$OUT/${slug}_${year}_nodes.json"
  call local_curricmap_get_edges -d programmeid="$id"                                   > "$OUT/${slug}_${year}_edges.json"
  total=$(jq .total "$OUT/${slug}_${year}_nodes.json"); got=$(jq '.nodes|length' "$OUT/${slug}_${year}_nodes.json")
  [ "$total" = "$got" ] || { echo "$slug $year: total $total but received $got" >&2; exit 1; }
  echo "$slug $year: $got nodes, $(jq length "$OUT/${slug}_${year}_edges.json") edges, revision $(jq -r .programme.revisionhash "$OUT/${slug}_${year}_nodes.json" | cut -c1-12)"
done
```

Because composed keys carry the slug-year prefix, the per-programme files can
be concatenated into one node table and one edge table without collisions —
the same node in 2025 and 2026 has two distinct keys, which is deliberate:
bindings and mappings are year-pinned.

Freshness: the mirror re-checks Sofia hourly for the two most recent years
of each slug (daily for older years). Re-run the dump and compare
`programme.revisionhash`; if it has not moved, nothing under it has changed.

---

## 9. What Sofia has that this route does not give you

Three layers, from "reachable with SQL" to "gone".

### 9.1 Stored in the Moodle mirror but NOT exposed by the web service

| data | where it lives | how to get it |
|---|---|---|
| **Tags** — accreditation/competency mappings (RCVS, EAEVE, AVMA, MODALITY, THEME, LOTYPE…) applied to nodes | `mdl_local_curricmap_nodetag` → `tagoption` → `tagfield` | SQL below. The full schema (fields + options, incl. display names) is also stored, per programme. |
| **Raw Sofia `doc` object** (`typeName`, `sofia:grouping:group`, `meta:duration`, `meta:occurrences`, any future exchange-format keys) | `node.metadata` (JSON text) | SQL: `JSON_EXTRACT(metadata, '$."meta:duration"')` |
| **`subtitle`** — lead educator(s) on taught nodes, free text | `node.subtitle` | SQL |
| **`position`** — Sofia's raw drag/drop ordering key | `node.positionraw` | SQL (the ws gives the resolved `sortorder`) |
| Materialised path (`/12/345/6789/`) of Moodle row ids | `node.path` | SQL; or rebuild from `parentuuid` |
| Programme `rootuuid` (the raw uuid of Sofia's root node for the last synced revision) | `programme.rootuuid` | SQL |
| Sync history, request spend, change reports (the first 15 Compare changes of each applied revision) | `synclog` | SQL or the status page CSV |
| Sofia API request log (errors always; successes only with debug logging on; purged nightly after `apilogretention` days) | `apilog` | SQL |
| Which rows are Sofia-immutable vs Moodle-authored (`source`), and the audit trail of csv/manual edits | `node.source`, `audit` | `source` is in the ws; the audit trail is SQL only |

Tags by SQL:

```sql
-- Tag schema of a slug-year
SELECT f.fieldkey, f.name AS field, o.optionkey, o.name AS option_name
FROM mdl_local_curricmap_tagfield f
JOIN mdl_local_curricmap_tagoption o ON o.tagfieldid = f.id
WHERE f.programmeid = 7
ORDER BY f.sortorder, o.sortorder;

-- Tags applied to nodes, with node identity
SELECT n.uuid, n.role, n.code, f.fieldkey, o.optionkey, o.name AS option_name
FROM mdl_local_curricmap_nodetag nt
JOIN mdl_local_curricmap_node n      ON n.id = nt.nodeid
JOIN mdl_local_curricmap_tagoption o ON o.id = nt.tagoptionid
JOIN mdl_local_curricmap_tagfield f  ON f.id = o.tagfieldid
WHERE n.programmeid = 7 AND n.deleted = 0
ORDER BY n.id, f.sortorder, nt.sortorder;

-- The raw doc and subtitle
SELECT uuid, role, code, subtitle, positionraw, metadata
FROM mdl_local_curricmap_node
WHERE programmeid = 7 AND deleted = 0;
```

Note the tag keys are canonical as spelled in Sofia, typos included
(`EAEVE_DAY_ONE_COMPETENCIE`, `AVBC_DAY_ONCE_COMPETENCIE`) — never "fix" them.

### 9.2 In Sofia's API but NOT stored by the mirror

The sync fetches `nodes/<year>?coalesce&connection-sort&url` and
`metadata/<year>` and keeps what the tables above hold. Dropped on the floor:

| Sofia data | why absent | how to get it |
|---|---|---|
| The **root node** (`type r`; its title is the revision commit message) | never stored; the programme keeps only `rootuuid` and `revisionhash` | Sofia `nodes/<year>` |
| **`created_at`** per node | not mapped to a column | Sofia `nodes/<year>` |
| **Raw `title` / `text` split** | the mirror stores the coalesced `title`/`description` | Sofia `nodes/<year>` *without* `?coalesce` |
| **Resources** (`?resources=links` — link resources attached to nodes in Sofia) | not requested | Sofia `nodes/<year>?resources=links` (only for version labels / their hashes) |
| **Nodes unreachable from the root** | derivation walks from the root; orphans are omitted (and soft-deleted if previously stored) | Sofia `nodes/<year>` and diff the key sets |
| **Connections whose target is not in the snapshot** | edge insert skips them | Sofia `nodes/<year>` raw `connections` |
| **Connection keys longer than 40 chars** | truncated | Sofia (institution-defined keys are exactly 40 hex chars, so this is theoretical) |
| **Tags whose option is not in the Metadata schema** (expired/legacy) | nodetag insert skips unknown options | Sofia `nodes/<year>?tag-format=object` |
| **Legacy clinical option extras** (`system`, `systems`, `related_conditions`, `expired`, category `type`) | tag options store key + name only | Sofia `metadata/<year>` |
| **Full change history** between revisions | only the last applied revision's first 15 changes, as text in `synclog.message` | Sofia `compare/<hash1>/<hash2>` |
| **Version labels** `LATEST` / `UPCOMING` / `SENTINEL`, and any revision **hash other than the latest** of each year | discovery creates rows for four-digit years only; each row tracks the head of that year | Sofia `nodes/<label-or-hash>` |
| **Subtree at an arbitrary revision** | mirror holds one revision per year | Sofia `nodes/<hash>/<uuid>` |
| Programmes whose slug is not in `programmeslugs` | never discovered | ask an admin to add the slug; or Sofia directly |

### 9.3 In Sofia but NOT in Sofia's API at all

For completeness, so nobody goes looking (details in
[SOFIA_API.md §2](SOFIA_API.md)):

- A **list of programmes/slugs** on a site, and a **list of versions** of a
  slug — there are no such endpoints. The plugin probes
  `compare/<YYYY>/<YYYY>` for each candidate year (404 = absent). Slugs must
  be known in advance.
- Per-node **`hash`** and **`owner`** — omitted from Nodes/Tree output (they
  surface only inside Compare field changes).
- **Upload resources** (files) — only link resources are exposed.
- The **revision kind** (aesthetic / minor / major) of a commit.
- **Webhook registration** — done by Isotoma on request, not via API.
- Empty values are omitted everywhere: an absent field and a blank field are
  indistinguishable.
