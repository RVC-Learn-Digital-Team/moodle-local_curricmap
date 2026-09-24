# How local_curricmap mirrors Sofia — the algorithm, in pseudo-code

A language-neutral description of how the Moodle plugin talks to the Sofia
curriculum API, what it stores, and how it keeps the copy fresh — written so
that a developer (or an AI) can build an equivalent Sofia curriculum store
for a non-Moodle application without reading the PHP.

Everything here is transcribed from the shipped code (v0.34.0):
`classes/api/client.php` (HTTP client), `classes/local/sync.php` (sync
engine), `classes/local/derive.php` (role derivation),
`classes/task/sync_task.php` (scheduling), `db/install.xml` (storage).
Where the plugin's behaviour is a Moodle-ism rather than a Sofia necessity,
it says so.

Companion: [SOFIA_MOODLE_API.md](SOFIA_MOODLE_API.md) — reading the finished
mirror out of Moodle. Upstream spec: the Isotoma *Sofia APIs* document
(v3.6), plus the observed-behaviour notes in the umbrella repo's
`SOFIA_GRAPH_DOCUMENTATION.md`.

---

## 1. The design in five sentences

1. Sofia stores each programme as a Merkle tree; every save is a new
   **revision** identified by the root hash, and **versions** (four-digit
   academic years, plus the labels `LATEST`/`UPCOMING`) point at the head
   revision of a year.
2. The mirror tracks one row per **slug × year** and remembers the revision
   hash it last applied.
3. Each sync costs **one Compare request** to learn the current head hash;
   only if it moved does it spend **two more** (Nodes + Metadata) to fetch a
   full snapshot.
4. The snapshot is applied **transactionally**: nodes upserted by stable key,
   missing nodes soft-deleted, edges and tags rebuilt wholesale, hash advanced
   last — failure rolls everything back to the previous revision.
5. Sofia's budget is **60 requests/hour per site**, so everything is shaped to
   spend a handful of requests per hour across all programmes.

Deltas from Compare are used only as a human-readable change report, never
replayed — see §9 for why.

---

## 2. What the plugin uses from Sofia's API

Base URL: `https://<site>` (e.g. `https://rvc-vetmed-test.sofiasrv.net`).
All calls are `GET`, JSON, bearer-token authenticated.

| call | used for | request cost |
|---|---|---|
| `POST /o/token/` — OAuth2 client-credentials | bearer token, cached until near expiry | the plugin does not count it in its own spend; whether Sofia counts it against the 60/h budget is unverified — the token endpoint returns no rate headers |
| `GET /api/_<slug>_/compare/<A>/<B>` | head-hash discovery (`A == B`), no-op detection, year discovery (404 = year absent), change report | 1 |
| `GET /api/_<slug>_/nodes/<year>?coalesce&connection-sort&url` | the whole tree as a flat `{uuid: node}` map | 1 |
| `GET /api/_<slug>_/metadata/<year>` | tag schema (fields + options) and connection field keys | 1 |

Not used: Tree API (same data, nested — the flat map is easier to diff and
store), `?tag-format=object` (display names come from the Metadata API
instead), `?resources=links`, subtree fetches, webhooks (no receiver is
implemented; the adhoc task exists so one could be added).

Things Sofia's API cannot tell you, which shape the design:

- **No list of programmes** — slugs are configuration (`programmeslugs`).
- **No list of versions** — years are discovered by probing (§6).
- **No `modified_since`** — change detection is hash comparison.
- **Empty values are omitted**, never null.
- **`hash` and `owner`** are absent from node payloads.

Rate-limit facts (observed on the RVC test site, July 2026): every response
carries `X-Sofia-Request-Count` and `X-Sofia-Request-Limit` (60); exceeding
the limit returns **HTTP 403** with plain-text body `Rate Limited: wait <N>s`;
the count header can exceed the limit (62/60 seen) — compute remaining as
`max(0, limit − count)`.

---

## 3. Storage model

Names are the plugin's; types are indicative. A non-Moodle store needs the
first six tables; the rest are observability.

```
programme        id, slug, versionlabel ('2025'), displayname?, revisionhash?, rootuuid?,
                 enabled, lastsyncstatus ('never'|'ok'|'noop'|'error'),
                 timelastsynced?, timelastchanged?
                 UNIQUE (slug, versionlabel)

node             id, programmeid, uuid (COMPOSED KEY, unique), parentid?, path?, depth,
                 type? (Sofia letter), subtype? (doc.typeName), role (derived),
                 code?, title?, description?, subtitle?, positionraw?, sortorder,
                 grouplabel? (doc["sofia:grouping:group"]), sofiaurl?,
                 source ('sofia'|'csv'|'manual'), sourceversion? (hash of last change),
                 metadata? (raw doc as JSON), deleted (0|1),
                 timecreated, timemodified, lastsynced?
                 -- ids are STABLE across syncs: everything downstream (bindings, caches)
                 -- keys on node.id or node.uuid, never on payload position.

edge             id, programmeid, sourceid, targetid, connectiontype (≤40 chars), sortorder
                 -- rebuilt wholesale every applied sync

tagfield         id, programmeid, fieldkey, name?, plural?, sortorder      UNIQUE (programmeid, fieldkey)
tagoption        id, tagfieldid, optionkey, name?, sortorder               UNIQUE (tagfieldid, optionkey)
nodetag          id, nodeid, tagoptionid, sortorder                        -- rebuilt wholesale

synclog          id, programmeid, synctype, timestart, timeend?, status, fromhash?, tohash?,
                 nodesfetched, nodesinserted, nodesupdated, nodesdeleted, edgeschanged,
                 tagschanged, requestcount, ratelimitremaining?, message?
apilog           id, timecreated, method, url, httpcode?, elapsedms, ratecount?, ratelimit?,
                 outcome ('ok'|'error'), message?, responsepreview?

config (key/value)   sofia_baseurl, sofia_clientid, sofia_clientsecret, programmeslugs,
                     ratelimitfloor (10), discoveryfloor (2020), enabledebuglog,
                     apilogretention (days),
                     lastratecount, lastratelimit, lastrateseen, ratebudgetback, lastdiscovery
```

### 3.1 The composed node key

Sofia uuids are stable across revisions **and across versions**: the same
strand has the same uuid in 2025 and 2026. The mirror needs both years side by
side (mappings are year-pinned), so every stored key is prefixed:

```
function programme_prefix(programme):
    label = trim(programme.versionlabel)
    if label matches /^\d{4}$/:           # '2025' → '2025_26'
        label = label + '_' + two_digits((int(label) + 1) mod 100)
    else:                                  # 'LATEST' → 'latest'
        label = lowercase(label)
    return programme.slug + '_' + label

function nodekey(programme, sofia_uuid):
    return programme_prefix(programme) + '_' + sofia_uuid
    # e.g. vet-med_2025_26_ec917dc5-8b3a-4f2e-9c1d-0a7b6e5f4d3c
```

The raw Sofia uuid is recoverable as the last 36 characters.

---

## 4. The HTTP client

```
class SofiaClient:
    base_url, client_id, client_secret          # from config
    rate_floor = config.ratelimitfloor or 10    # refuse to send when remaining <= floor
    remaining  = null                           # budget last seen on a response header
    request_count = 0                           # this instance's spend (excl. token calls)

    constructor():
        # Seed the budget from the last persisted headers if they are recent (< 15 min),
        # so a fresh process refuses politely instead of collecting a 403 from Sofia.
        if config.lastrateseen and now() - config.lastrateseen < 900:
            remaining = max(0, config.lastratelimit - config.lastratecount)

    function token(force_new = false):
        key = sha1(base_url + '|' + client_id)
        if not force_new and cache[key] and cache[key].expires > now() + 60:
            return cache[key].token
        resp = POST base_url + '/o/token/'
               basic_auth(client_id, client_secret)
               form: grant_type=client_credentials          # no scope, no other fields
        if resp.status != 200 or no resp.json.access_token: raise TokenError
        cache[key] = {token: resp.json.access_token,
                      expires: now() + (resp.json.expires_in or 3600)}
        log('POST', '/o/token/', status, elapsed)          # NEVER log the body/token
        return cache[key].token

    function get_json(path, params = {}):
        if not configured(): raise NotConfigured
        if remaining is not null and remaining <= rate_floor:
            raise RateFloor(remaining, floor)              # local refusal, no request sent
        url = base_url + path + querystring(params)         # key-only options render as '?coalesce&url'
        resp = GET url  with  Authorization: Bearer token()
        if resp.status == 401:                              # token expired/revoked: re-auth once
            resp = GET url  with  Authorization: Bearer token(force_new = true)
        request_count += 1 per GET actually sent
        capture_rate(resp)
        if resp.status != 200:
            if resp.status == 403 and resp.body matches /wait\s+(\d+)/i:
                config.ratebudgetback = now() + N            # Sofia's only statement of when budget returns
            log error (status, first 2000 chars of body)
            raise HttpError(status, body[:500])
        data = json_decode(resp.body)
        if data is not an object/array: log error; raise InvalidJson
        log ok (body preview only when debug logging is enabled)
        return data

    function capture_rate(resp):
        count = header 'X-Sofia-Request-Count'; limit = header 'X-Sofia-Request-Limit'
        if both present:
            remaining = max(0, limit - count)
            config.lastratecount = count; config.lastratelimit = limit; config.lastrateseen = now()

    # Endpoint helpers
    NODE_OPTIONS = {coalesce: '', 'connection-sort': '', url: ''}     # key-only options
    metadata(slug, ref)               = get_json('/api/_'+slug+'_/metadata/'+ref)
    nodes(slug, ref, subtree = null)  = get_json('/api/_'+slug+'_/nodes/'+ref+(subtree ? '/'+subtree : ''), NODE_OPTIONS)
    tree(slug, ref, subtree = null)   = get_json('/api/_'+slug+'_/tree/'+ref+(subtree ? '/'+subtree : ''), NODE_OPTIONS)
    compare(slug, older, newer)       = get_json('/api/_'+slug+'_/compare/'+older+'/'+newer)
```

Why each option on the Nodes call:

- `coalesce` — Sofia stores outcome titles longer than 300 chars in `text`
  with `title` blank; coalesce returns a calculated `title` (title, else
  text) and `description` (text, unless it became the title). Without it
  you must replicate that rule yourself.
- `connection-sort` — connection uuid lists come in Sofia's presentation
  order instead of effectively random order; the mirror stores that order.
- `url` (key-only) — each node carries a deep link into the Sofia app for
  the fetched version.

---

## 5. Deriving rows from a Nodes payload

The Nodes payload is `{uuid: node}`. A node looks like:

```json
{
  "uuid": "…", "code": "UG1-PVP-LE4", "type": "E", "created_at": "…",
  "title": "…", "description": "…", "subtitle": "Lecturer names", "position": "4",
  "doc": {"typeName": "Lecture", "sofia:grouping:group": "Unit 4: Cattle Production"},
  "tags": {"MODALITY": ["ONSITE"]},
  "connections": {"implements": ["uuid", "uuid"]},
  "children": ["uuid", "uuid"],
  "url": "https://<site>/map/vet-med/2025/<uuid>"
}
```

Tree shape, depth, sibling order and the consumer-facing **role** are not
node properties in Sofia — the role depends on the parent — so they are
derived by walking down from the root:

```
ROLE_BY_TYPE      = {Y: 'year', E: 'session', Z: 'assessment', G: 'group'}
ROLE_BY_UNIT_SUBTYPE = {Strand: 'strand', Module: 'strand', Course: 'year', Year: 'year'}
STRANDOUTCOME_PARENTS  = {'strand', 'strandoutcome', 'year', 'unit'}
SESSIONOUTCOME_PARENTS = {'session', 'sessionoutcome', 'assessment'}

function role(type, subtype, parent_role):
    if type == 'U':
        if subtype in ROLE_BY_UNIT_SUBTYPE: return ROLE_BY_UNIT_SUBTYPE[subtype]
        if parent_role == 'year':                     return 'strand'   # untyped unit under a year
        if parent_role in {'strand', 'unit'}:         return 'unit'     # untyped container under a strand
        return 'other'
    if type == 'O':
        if parent_role in STRANDOUTCOME_PARENTS:      return 'strandoutcome'
        if parent_role in SESSIONOUTCOME_PARENTS:     return 'sessionoutcome'
        if parent_role is null:                       return 'programmeoutcome'   # top-level outcome
        return 'other'
    return ROLE_BY_TYPE.get(type, 'other')

function build_rows(payload):
    root = the one node with type == 'r'          # error if none
    rows = {}; queue = []
    for index, child_uuid in enumerate(root.children):
        queue.push((child_uuid, parent=null, parent_role=null, depth=0, sortorder=index, ancestors=[]))
    while queue:                                    # breadth-first: parents before children
        (uuid, parent, parent_role, depth, sortorder, ancestors) = queue.shift()
        if uuid not in payload or uuid in rows: continue      # dangling child ref, or cycle
        node = payload[uuid]
        subtype = node.doc.typeName or null
        r = role(node.type, subtype, parent_role)
        rows[uuid] = {uuid, parentuuid: parent, depth, sortorder, type: node.type,
                      subtype, role: r, grouplabel: node.doc["sofia:grouping:group"] or null,
                      pathuuids: ancestors + [uuid]}
        for index, child_uuid in enumerate(node.children or []):
            queue.push((child_uuid, uuid, r, depth + 1, index, rows[uuid].pathuuids))
    return rows      # insertion order == BFS order; nodes unreachable from the root are absent
```

Notes:

- `sortorder` is the index in the parent's `children` array — Sofia's
  presentation order (position, else natural sort of code). The mirror does
  not re-sort Sofia rows; it trusts the API.
- The **root is not stored**; its children are depth 0.
- The role table is data, not logic: when Sofia retires the legacy `Y` type
  in favour of `U` + subtype `Year`, only the table changes.
- Real programmes are messier than the ideal year→strand→session→outcome
  shape: the live RVC graph has untyped `U` containers between strand and
  sessions ("Unit 3: …", derived to `unit`), year-level outcomes, outcomes
  under assessments, top-level programme outcomes, and editor test folders
  at the root. The derivation absorbs all of these; consumers filter by role.

---

## 6. Discovering which years exist

Sofia has no list-versions endpoint. `compare/<YYYY>/<YYYY>` costs one
request and returns 404 for a year that does not exist, so:

```
function discover_programmes():
    floor   = config.discoveryfloor if >= 2000 else 2020
    ceiling = current_year + 1                     # the UPCOMING year gets a slot ahead of rollover
    for slug in configured_slugs():                # 'programmeslugs' setting, comma-separated
        existing = {versionlabel for rows of programme where slug}
        for year in floor..ceiling:
            if str(year) in existing: continue     # never re-probe a known year
            try:    client.compare(slug, str(year), str(year))
            except HttpError(404): continue        # year absent (this year, on this site)
            insert programme(slug, versionlabel=str(year), enabled=1, lastsyncstatus='never')
    config.lastdiscovery = now()
```

Cost: every year in `[floor, ceiling]` that has no row is probed on **every**
discovery run (at most once per 20 h), so set `discoveryfloor` to the first
real year of your oldest programme rather than leaving it at 2020, or you pay
a few 404s per slug per day forever. Steady state with a correct floor is one
probe per slug per run (next year's empty slot) until Sofia rolls over, at
which point the new year appears automatically.

`ensure_programmes()` reconciles enabled flags afterwards: rows whose slug is
still configured are enabled, others disabled — **never deleted**, their data
stays. Deletion is an explicit admin *Purge* (§10).

---

## 7. Scheduling and tiers

Moodle runs `sync_task` at minute 15 of every hour (cron). The task
self-guards so that admin rescheduling cannot push it past the agreed
cadence bounds (at least daily, at most hourly):

```
GUARD_HOURLY    = 55 min        # min interval between syncs of an hourly-tier programme
GUARD_DAILY     = 20 h          # ... of a daily-tier (older year) programme
GUARD_DISCOVERY = 20 h          # min interval between discovery runs

function is_hourly(programme, all_enabled):
    if programme.versionlabel is not a 4-digit year: return true          # LATEST etc.
    years = sorted desc {int(p.versionlabel) for p in all_enabled if p.slug == programme.slug and 4-digit}
    cutoff = years[min(1, len(years) - 1)]                                 # second-newest year
    return int(programme.versionlabel) >= cutoff
    # i.e. the two most recent years per slug (on live: current + upcoming) are hourly;
    # older years are essentially frozen but Sofia allows minor corrections, so daily.

function sync_task():
    if not client.configured(): return
    if now() - config.lastdiscovery > GUARD_DISCOVERY: discover_programmes()
    programmes = ensure_programmes()
    for programme in programmes:
        guard = GUARD_HOURLY if is_hourly(programme, programmes) else GUARD_DAILY
        if programme.timelastsynced and now() - programme.timelastsynced < guard
           and programme.lastsyncstatus != 'error':
            continue                                   # errors retry every run
        log = sync_programme(programme)
        print summary (status, +inserted ~updated -deleted, requests, remaining)
```

Budget arithmetic per hour, S slugs each with H hourly-tier years:
`S × H` Compare requests when nothing changed (12 programmes on live ≈ 12),
`+2` per programme whose head moved, `+` the discovery probes once a day.
Well inside 60, leaving headroom for admin *Test connection* clicks.

An **adhoc task** (`adhoc_sync_task`, custom data `{programmeid, force}`) runs
one programme on demand — queued by the status page's *Sync now* / *Force
sync* buttons. A webhook receiver would queue the same task; none is
implemented.

---

## 8. Syncing one programme-year

```
function sync_programme(programme, force = false):
    log = insert synclog(programmeid, synctype='full', timestart=now(), status='running',
                         fromhash=programme.revisionhash)
    try:
        # 1. Where is the head?  One Compare request.
        older = programme.revisionhash or programme.versionlabel    # first sync: compare year with itself
        diffable_from = programme.revisionhash
        try:
            cmp = client.compare(programme.slug, older, programme.versionlabel)
        except HttpError(404) when older != programme.versionlabel:
            # Sofia does not know our stored hash (e.g. mirror moved to a different Sofia site):
            # fall back to head discovery with no diff.
            cmp = client.compare(programme.slug, programme.versionlabel, programme.versionlabel)
            diffable_from = null
        head = cmp.meta.compare.to or cmp.meta.compare.from     # 'to' = second URL parameter
        if head is empty: raise NoHash
        log.tohash = head

        # 2. Nothing moved?  Done, one request spent.
        if not force and programme.revisionhash == head:
            return finish(log, programme, 'noop')

        # 3. Something moved: two more requests, then apply.
        log.message = change_report(cmp, diffable_from)
        nodes_payload    = client.nodes(programme.slug, programme.versionlabel)
        metadata_payload = client.metadata(programme.slug, programme.versionlabel)
        log.nodesfetched = len(nodes_payload)
        stats = apply(programme, nodes_payload, metadata_payload, head)
        copy stats into log
        return finish(log, programme, 'ok', applied_hash = head)
    except any error e:
        log.message = str(e)
        return finish(log, programme, 'error')        # never throws; outcome is in the log row

function finish(log, programme, status, applied_hash = null):
    log.status = status; log.timeend = now()
    log.requestcount = client.request_count; log.ratelimitremaining = client.remaining
    update synclog
    programme.lastsyncstatus = status; programme.timelastsynced = log.timeend
    if applied_hash: programme.timelastchanged = log.timeend
    update programme
    return log
```

`force` re-applies the snapshot even when the hash is unchanged — the way to
recover from a bad apply, a schema/derivation change in the plugin, or a
purge of dependent tables.

### 8.1 Applying a snapshot (one transaction)

```
function apply(programme, nodes_payload, metadata_payload, head):
    BEGIN TRANSACTION
    now = now()
    derived  = build_rows(nodes_payload)                       # §5
    rootuuid = uuid of the type 'r' node

    # Rebuild-wholesale tables are cleared first
    delete nodetag where nodeid in (nodes of programme)
    delete edge    where programmeid = programme.id

    option_ids = sync_tag_schema(programme, metadata_payload)   # "FIELD|OPTION" → tagoption.id

    existing = {row.uuid: row for row in node where programmeid = programme.id}   # keyed by composed key

    (id_by_uuid, inserted, updated) = upsert_nodes(programme, nodes_payload, derived, existing, head, now)
    deleted = soft_delete_missing(programme_prefix(programme), existing, derived, head, now)
    edges   = insert_edges(programme, nodes_payload, id_by_uuid)
    tags    = insert_nodetags(nodes_payload, id_by_uuid, option_ids)

    programme.revisionhash = head; programme.rootuuid = rootuuid
    update programme                                            # hash advances LAST
    COMMIT
    on any error: ROLLBACK and re-raise   # previous revision stays intact and consistent
    return {inserted, updated, deleted, edgeschanged: edges, tagschanged: tags}
```

### 8.2 Node upsert — stable ids, path, change detection

```
function upsert_nodes(programme, payload, derived, existing, head, now):
    prefix = programme_prefix(programme)
    id_by_uuid = {}; path_by_uuid = {}; inserted = updated = 0
    for uuid, row in derived:                                   # BFS order ⇒ parent id is known
        node = payload[uuid]
        parent_id   = id_by_uuid[row.parentuuid] if row.parentuuid else null
        parent_path = path_by_uuid[row.parentuuid] if row.parentuuid else '/'
        candidate = {
            programmeid: programme.id,
            uuid:        prefix + '_' + uuid,
            parentid:    parent_id,
            depth:       row.depth,
            type:        node.type or null,
            subtype:     row.subtype,
            role:        row.role,
            code:        node.code, title: node.title, description: node.description,
            subtitle:    node.subtitle, positionraw: node.position,
            sortorder:   row.sortorder,
            grouplabel:  row.grouplabel,
            sofiaurl:    node.url,
            source:      'sofia',
            metadata:    json(node.doc) if node.doc else null,
            deleted:     0,                                     # a returning node is resurrected
        }
        current = existing.get(candidate.uuid)
        if current is null:
            candidate.sourceversion = head
            candidate.timecreated = candidate.timemodified = candidate.lastsynced = now
            id = insert node(candidate); inserted += 1
            path = parent_path + id + '/'
            update node set path = path where id           # path needs the new id
        else:
            id = current.id
            candidate.path = parent_path + id + '/'
            if node_changed(current, candidate):            # field-by-field compare, see below
                candidate.id = id; candidate.sourceversion = head
                candidate.timemodified = candidate.lastsynced = now
                update node(candidate); updated += 1
            # unchanged rows are not touched: timemodified/sourceversion keep their history
            path = candidate.path
        id_by_uuid[uuid] = id; path_by_uuid[uuid] = path
    return (id_by_uuid, inserted, updated)

function node_changed(current, candidate):
    compare as ints:    programmeid, parentid, depth, sortorder, deleted
    compare as strings: path, type, subtype, role, code, title, description, subtitle,
                        positionraw, grouplabel, sofiaurl, source, metadata
    return true if any differs

function soft_delete_missing(prefix, existing, derived, head, now):
    count = 0
    for key, row in existing:
        raw = key[len(prefix) + 1:]                            # strip 'slug_YYYY_YY_'
        if raw not in derived and row.deleted == 0:            # gone from Sofia, or now unreachable
            update node set deleted=1, sourceversion=head, timemodified=now, lastsynced=now where id=row.id
            count += 1
    return count
```

Why soft delete: bindings, mappings and content references point at node
rows by id/key; hard-deleting would orphan them silently. Consumers filter
`deleted = 0`; admins can see what vanished and when (`sourceversion`).

### 8.3 Edges and tags — rebuilt wholesale

```
function insert_edges(programme, payload, id_by_uuid):
    batch = []; count = 0
    for uuid, node in payload:
        if not node.connections or uuid not in id_by_uuid: continue     # unreachable source: skip
        for connection_type, targets in node.connections:               # insertion order preserved
            for sortorder, target_uuid in enumerate(targets):
                if target_uuid not in id_by_uuid: continue              # target not in snapshot: skip
                batch.append({programmeid, sourceid: id_by_uuid[uuid], targetid: id_by_uuid[target_uuid],
                              connectiontype: connection_type[:40], sortorder})
                count += 1
                if len(batch) >= 500: bulk insert edge(batch); batch = []
    bulk insert remaining
    return count

function insert_nodetags(payload, id_by_uuid, option_ids):
    for uuid, node in payload:
        if not node.tags or uuid not in id_by_uuid: continue
        for field_key, value in node.tags:
            for sortorder, option_key in enumerate(tag_option_keys(value)):
                option_id = option_ids.get(field_key + '|' + option_key)
                if option_id is null: continue                          # option unknown to the schema: skip
                batch.append({nodeid: id_by_uuid[uuid], tagoptionid: option_id, sortorder})
    bulk insert in chunks of 500; return count

function tag_option_keys(value):
    # default API format:      "MODALITY": ["ONSITE"]                      → ['ONSITE']
    # tag-format=object format: "MODALITY": {name, options: {ONSITE: {…}}} → ['ONSITE']
    if value is a list: return [str(v) for v in value]
    if value is an object with 'options': return keys(value.options)
    return []
```

### 8.4 Tag schema — upsert and prune

```
function sync_tag_schema(programme, metadata_payload):
    option_ids = {}; keep_fields = []
    for index, field in enumerate(metadata_payload.fields or []):
        if 'options' not in field or not field.key: continue   # connection fields have no options: not tags
        f = upsert tagfield where (programmeid, fieldkey=field.key)
              set name=field.name, plural=field.plural, sortorder=index
        keep_fields.append(f.id); keep_options = []
        for oindex, option in enumerate(field.options):
            if not option.key: continue
            o = upsert tagoption where (tagfieldid=f.id, optionkey=option.key)
                  set name=option.name, sortorder=oindex
            keep_options.append(o.id)
            option_ids[field.key + '|' + option.key] = o.id
        delete tagoption where tagfieldid=f.id and id not in keep_options
    removed = tagfield ids where programmeid=programme.id and id not in keep_fields
    delete tagoption where tagfieldid in removed; delete tagfield where id in removed
    return option_ids
```

Schema ids are stable across syncs (upsert by key), so anything that stored a
`tagoption.id` survives. Keys are identity — spelled exactly as Sofia spells
them, typos included.

### 8.5 The change report (human-readable only)

```
function change_report(cmp, from_hash):
    if not from_hash: return 'Initial full sync.'
    lines = ['%d added, %d removed, %d modified since %s' %
             (cmp.meta.added, cmp.meta.removed, cmp.meta.modified, from_hash[:12])]
    for change in cmp.changes[:15]:
        lines.append(join(' ', [change.class, change.type, change.code, quote(change.preview)]))
    if len(cmp.changes) > 15: lines.append('... and %d more' % (len(cmp.changes) - 15))
    return join('\n', lines)          # stored in synclog.message
```

---

## 9. Why a full snapshot rather than replaying Compare deltas

Compare was verified (July 2026, two controlled edit runs) to be a complete
and trustworthy *detector* — its Add/Remove/Modifications set matched an
independent diff of before/after snapshots exactly. It is a poor *replay*
source:

- `ChildrenEdit` values are child node **hashes**, not uuids; reconstructing
  a parent's new child order needs hash→node correlation via the
  accompanying `HashEdit`s.
- The root node "modifies" on every revision (hash, title = commit message,
  children, sometimes `SchemaFieldEdit`).
- Compare reports **net** differences, not history: moved-then-deleted
  collapses to `Remove`; added-then-deleted vanishes.
- Connection `MetadataEdit`s were never captured (no in-app connection
  editing; connections arrive by spreadsheet import).

A snapshot is two requests and a few MB; applying it is idempotent and
self-healing (a bad earlier apply is corrected by the next one, or by
*Force sync*). Compare is worth its one request purely to avoid spending the
other two when nothing changed.

---

## 10. Admin operations

- **Test connection** — `compare(slug, 'LATEST', 'LATEST')` on the first
  configured slug; reports hash, latency, remaining budget. Costs 1.
- **Discover versions** — runs §6 immediately.
- **Sync now / Force sync** — queues the adhoc task for one programme-year.
- **Purge** — one transaction deleting the programme-year's nodetags, audit
  rows, edges, tag options, tag fields, nodes, sync log and the programme row.
  Bindings to its nodes are left orphaned (every consumer already treats a
  binding whose node row is missing as orphaned). A year that still exists
  upstream reappears as a fresh `never`-synced row on the next discovery; a
  ghost year (left over from a different Sofia site) stays gone because the
  probe 404s.
- **Cleanup task** (daily 03:30) — purges `apilog` rows older than
  `apilogretention` days.

---

## 11. Building this outside Moodle — what to swap

| Moodle-ism in the plugin | Replace with |
|---|---|
| `\core\http_client` (Guzzle) | any HTTP client; keep TLS verification on |
| MUC cache for the bearer token | any in-process or shared cache keyed by `sha1(base_url + client_id)` |
| `get_config` / `set_config` | your settings store; the rate-limit trio (`lastratecount/limit/seen`) must persist across processes if more than one process talks to Sofia |
| Delegated DB transaction | one transaction per applied snapshot; Postgres/MySQL both fine |
| Scheduled task (hourly cron) + adhoc task | cron/systemd timer + a job queue (or just run inline) |
| Composed key prefix | keep it, or store `(programmeid, sofia_uuid)` as a compound key — but multi-year consumers need *some* year discriminator |
| `role` derivation table | keep verbatim; it encodes hard-won knowledge of the live graph |
| Soft delete | keep, unless nothing will ever reference node rows |

Minimal viable copy for one slug-year, no freshness logic:

```
token    = POST /o/token/  (client credentials)
head     = GET /api/_<slug>_/compare/<year>/<year>  → meta.compare.to
nodes    = GET /api/_<slug>_/nodes/<year>?coalesce&connection-sort&url
schema   = GET /api/_<slug>_/metadata/<year>
rows     = build_rows(nodes)                 # §5
store programme(slug, year, head), rows, edges from nodes[*].connections,
      tag schema from schema.fields (those with 'options'), nodetags from nodes[*].tags
```

Three requests. Re-run: one request to compare `head` with `<year>`; if
`meta.compare.to` is unchanged, stop.
