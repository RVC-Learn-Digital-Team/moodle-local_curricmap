# PLAN — local_curricmap

Build tracker for the plugin. The full design is maintained in the RVC
`sofia_api_explorer` umbrella project (`LOCAL_CURRICMAP_DESIGN.md`,
`IMPLEMENTING_SOFIA_GRAPH_IN_MOODLE.md`, `SOFIA_GRAPH_DOCUMENTATION.md`); this file tracks
*progress against it* and records the constraints a contributor needs without opening the
umbrella repo.

## Operating constraints (decided)

- Sofia API sync: **at least daily, at most hourly**; Sofia rate limit is **60
  requests/hour per site** — the sync design budgets ≈ 9/hour worst case.
- **Sofia rows are immutable in Moodle** (`source='sofia'`, written only by sync, enforced
  in the storage layer). CSV (`source='csv'`) and manual (`source='manual'`) rows are the
  mutable path. CSV import ships **disabled by default**.
- **No student access to Sofia** — Sofia deep links render only to holders of
  `local/curricmap:viewstaffmeta`.
- Sync strategy: **Compare API as change detector (1 request), full snapshot as the
  transactional apply mechanism** — validated against real captured revision pairs
  (July 2026; the fixtures live in the umbrella repo under `sofia_probe/change_control*/`).
- Binding capability is **Phase 1, API-first**: generic store (course/section/activity/
  sub-activity ↔ any node, soft-typed relations), no mapping UIs.
- Moodle 4.5 LTS baseline; no runtime dependencies beyond core (`\core\http_client` for
  HTTP so the Sofia client is mockable in PHPUnit).
- Databases: **MySQL 8.4 (RVC production)**, MariaDB 10.6.7+, PostgreSQL 13+ (dev/CI).
  CI matrix runs Postgres 14 and MySQL 8.4; anything DB-specific is a bug.

## Milestones

### M1 — Scaffold (this release, v0.1.0)

- [x] Installable skeleton: version.php, language strings, settings page stub
- [x] Capability definitions (managesync, importcsv, editmanual, viewstaffmeta,
      managebindings)
- [x] Privacy null provider
- [x] CI: moodle-plugin-ci (lint + PHPCS) green
- [ ] Repo pushed; umbrella repo submodule pinned

### M2 — Schema + derivation

Decisions (July 2026): trust the API — `?coalesce` output stored as-is, `sortorder` =
index in the parent's `children` array (no re-implementation of Sofia ordering); the
grouping-label column is named `grouplabel` (`grouping` is reserved in PostgreSQL); all
nine tables ship in `install.xml` up front (forward-only migrations); node ids are stable
across syncs (upsert by UUID), edges/nodetags rebuilt wholesale.

- [x] `db/install.xml`: all nine tables — programme, node, edge, tagfield, tagoption,
      nodetag, synclog, audit, binding (design doc §1); `db/upgrade.php` creates them on
      scaffold-era installs via `install_one_table_from_xmldb_file`
- [x] `\local_curricmap\local\derive`: role derivation, `grouplabel` extraction,
      path/depth/sortorder assembly — pure functions, table-driven; natural sort for
      csv/manual rows only. Verified against the full revision-A fixture (1,496 rows,
      role counts exact) on PHP 8.2
- [x] Golden fixture corpus copied into `tests/fixtures/` with provenance notes (repo is
      private — approved for inclusion), plus the natural-sort test vectors; PHPUnit
      suite in `tests/local/derive_test.php`; PHPUnit step added to CI
- [x] Install/uninstall clean on Moodle 4.5.3/Postgres 14 — verified 8 July 2026 on the
      docker playground: upgrade path created all nine tables (incl. `grouplabel` and
      the unique uuid / dual-direction edge indexes); CLI uninstall removed all tables,
      capabilities and config; reinstall from install.xml restored version 2026070710,
      9 tables, 5 capabilities, 9 role grants — both creation paths proven
- [ ] CI green on GitHub (PHPCS zero errors + PHPUnit) — confirm on the Actions run for
      the M2 push

### M3 — Sofia client

Decision (July 2026): API debug logging is **database-backed** (`..._apilog` table) —
production is load-balanced so local files are per-node/ephemeral, and FR-SOF-7 needs
the error log admin-visible. Errors always logged; success + response preview only when
`enabledebuglog` is on; tokens/credentials never logged; daily cleanup task with
configurable retention (default 30 days).

- [x] OAuth2 client-credentials on `\core\http_client`, token cache (MUC), single 401 retry
- [x] `metadata() / nodes() / tree() / compare()` with key-only query options
- [x] Rate-limit header tracking (persisted to config for admin visibility) +
      configurable refusal floor (`ratelimitfloor`, default 10)
- [x] API debug log: `apilog` table + `enabledebuglog`/`apilogretention` settings +
      daily `cleanup_task`
- [x] Real settings page: base URL, client id, client secret (passwordunmask), rate
      floor, debug log, retention
- [x] PHPUnit via Guzzle MockHandler: token flow + caching, 401 single-retry, second-401
      failure, 5xx (always logged), invalid JSON, rate-floor refusal, header
      persistence, debug on/off logging, token-body never stored, unconfigured refusal,
      compare path, log cleanup
- [x] Secrets in Moodle secret config; TLS verification always on (core default, never
      touched)
- [x] Verified on playground 9 July 2026: upgrade to 2026070800 created `apilog`,
      cleanup task registered, settings stored. **Live smoke test against
      `rvc-vetmed-test` passed**: OAuth token obtained, 17 metadata fields,
      `compare LATEST/LATEST` resolved hash `12ac3c30…` (= change-control revision C),
      rate headers captured (49/60 remaining) and persisted; debug log recorded
      token + 2 GETs correctly (no token body stored). First real-world error was
      also captured correctly by apilog (401 on a corrupted 146-char secret —
      re-pasting at the spec's 128 chars fixed it)
- [ ] CI green on both DBs for the M3 push — confirm on the Actions run

### M4 — Sync engine (full sync)

Note: the engine already uses `compare(label, label)` as its first request, so hash
discovery **and** no-op change detection ship with M4 (1 request when nothing changed);
M5 upgrades this to a full change report + admin UI.

- [x] Snapshot apply: upsert nodes by UUID (ids stable — verified across a captured
      move), rebuild edges/tags wholesale (two-pass), recompute
      path/sortorder/role/grouplabel, soft-delete missing (content kept), tag schema
      synced with pruning, transactional per programme
- [x] Synclog with per-run statistics, request count and rate headroom
- [x] Golden-master tests (PHPUnit + verified pre-push via a rollback harness on real
      Postgres): sync A → exact corpus counts (1,496 stored, 3,786 implements, 14
      strands, 79 strand outcomes, 10 tag fields / 259 options); resync → noop with 1
      request; forced reapply → zero row changes; **B-over-A and C-over-B reproduce the
      captured change-control deltas exactly** (incl. role re-derivation on a move, new
      uuid for the recreated folder, schema field add/remove, and the sibling
      sort-shift nuance); failure before write and mid-apply DB failure both roll back
      with the previous revision intact
- [x] Scheduled task (default hourly, 55-min self-guard per programme) + adhoc task +
      `cli/sync.php` (--programme, --force); `programmeslugs` setting +
      `ensure_programmes` (removing a slug disables, never deletes)
- [x] Verified live on playground 9 July 2026: upgrade to 2026070900, tasks
      registered; **first live sync mirrored vet-med in 4 seconds** (1,495 nodes,
      3,791 edges, 1,186 tags, 3 requests, 57/60 remaining) at revision `12ac3c30…`
      (= change-control revision C — role counts match it exactly, including the
      moved outcome as a sessionoutcome); live noop rerun = 1 request, zero rows
- [ ] CI green on both DBs for the M4 push — confirm on the Actions run

### M5 — Change detection + admin UI

- [x] Compare-API change check before apply — shipped with M4; M5 upgrades it to
      compare **from the stored hash**, so each changed sync also records a
      human-readable delta report (counts + first 15 change summaries) in the synclog
      ("Initial full sync." on first run)
- [x] Golden-master: B-over-A applies the captured delta (M4) — now also asserts the
      change report is stored
- [x] Admin **status page** (`status.php`, registered as an external admin page and
      linked from settings): connection **Test button** (1 compare request → revision
      hash, latency, remaining budget, or the error), per-programme Sync now / Force
      full sync (inline, FR-SOF-5), programme table (revision, status, active nodes),
      recent sync runs with reports, recent API errors, sync-log **CSV export**
      (FR-SOF-3/7)
- [x] Robustness fix found by the pre-push harness running against a live-synced site:
      node upsert now keys on uuid **globally**, so a programme slug rename adopts
      existing rows instead of colliding on the unique uuid index; soft-delete strictly
      scoped to the synced programme (csv/manual rows with null programmeid are
      untouchable by sync)
- [ ] Behat admin flows — deferred to M10 hardening (CI has no Behat lane yet; the
      status page logic is exercised manually and via the underlying engine's PHPUnit)
- [ ] Verify on playground after push: upgrade to 2026071000, status page renders,
      Test connection button green, Sync now buttons work, CSV downloads; CI green

### M6 — Service API + caching

- [x] Query surface (`\local_curricmap\api\curriculum`): programmes / years / strands /
      strand_outcomes / units (grouplabel roll-up with counts, first-appearance order) /
      sessions (grouplabel+subtype filters) / session_outcomes / node (resolves
      soft-deleted, flagged) / children / subtree (path-based, depth-limited) /
      implements_targets / implemented_by (reverse edge index) / tags / tag_schema /
      search (title+code, case-insensitive). Note: `implements()` from the design doc
      is named `implements_targets()` — reserved word
- [x] MUC `queries` cache keyed on a stamp of all programme revision hashes — sync
      invalidates by key change, no purging
- [x] Read-only external functions (`get_programmes`, `get_children`, `search`) for the
      picker/AJAX, capability-gated (`viewstaffmeta`) in the calling course context
- [x] PHPUnit: full query surface against revision A (incl. Locomotor no-labels
      fallback, AH unit ordering, traceability in connection order, 102-node subtree),
      soft-delete + cache invalidation across an A→B resync, external function shapes +
      student-refusal
- [ ] Query-count ceilings (perfdebug) — deferred until the presenter's render path
      exists to measure against (M10 with NFR-2)
- [x] Live-verified on playground 10 July 2026 against the real mirror: strands in
      order, AH's 14 unit labels (Unit 1 first, 7 sessions), Locomotor no-label
      fallback, implemented_by(LO58) = 14 session outcomes, accreditation tags with
      display keys, 102-node subtree at 1.2ms cold / 0.2ms warm (≫ inside NFR-2)
- [ ] CI green on both DBs for the M6 push (multi-line call style fixes included) —
      confirm on the Actions run

### M7 — Mutable sources

- [ ] Manual CRUD (course-scoped, `editmanual`), audit log; replace privacy null provider
      with full metadata provider
- [ ] CSV import/export port from `mod_curriculummapexp`: dry-run → transactional commit,
      `enablecsvimport` setting (default off) + `importcsv` capability
- [ ] Immutability tests: no write path can touch `source='sofia'` rows; CSV rows with a
      Sofia UUID rejected per-line; export carries `source`, importer refuses it back

### M8 — Binding API

Decisions (July 2026): five address levels — `categoryid` (nullable, nested categories
resolved via ancestor walk) → course → section → cm → sub-activity; `courseid` nullable
only for category bindings; `scope` column `central|course` — central = admin/API
authored (locked for course staff), course = shared-editable by managebindings holders
(editing teachers and up) with usermodified audit. Runs **after mod_curricmap's renderer
(its M2)**, before the presenter's mapping-aware defaults.

Further decisions (July 2026, pre-build discussion): no users in mappings; relations v1
= `anchor` (default course scope, multiple allowed/ordered) + `related`; binding rows
carry `sortorder`; bindings are year-pinned (rollover = bulk-create new mappings for new
courses; old courses/bindings stay intact); **node resources** in the same milestone
(`_resource`: node + optional course scope + free-string type from a seeded vocabulary +
label/url) so mapped locations inherit "related learning content"; **named node groups**
as optional phase 2; external access via declared ws + manual service account, contract-
tested from the `moodle_mapping_api_test` Python repo; bulk relationship import is
separate follow-on work; the presenter's programme/scope picker consumes `resolve()` for
defaults once this lands.

- [x] Binding table (+categoryid, +scope, +sortorder) + resource/group/groupitem tables;
      service API: bind/unbind (idempotent), for_course/for_node, orphaned,
      resolve(location) — deepest-match incl. ancestor categories — anchors(courseid);
      resources API (add/delete/for_node/for_nodes/query with case-insensitive type
      filter, suggested_types from the resourcetypes setting) (v0.8.0)
- [x] Event observers: course_module_deleted / course_section_deleted / course_deleted /
      course_category_deleted → bindings marked orphaned, never silently deleted (v0.8.0)
- [x] Tests: all five address depths, ancestor-category resolution, permission denial,
      central-scope lock, orphaning on module deletion and on Sofia soft-delete (v0.8.0)
- [x] External functions (bind, unbind, resolve, list_bindings, add_resource,
      delete_resource, list_resources — by node and/or type with optional course scope —
      list_resource_types) + declared service `curricmap_mapping` (enabled, authorised
      users) bundling the curriculum read functions and core_course_get_categories/
      _get_courses/_get_contents so one manual token drives address building; ws
      round-trip + permission tests (v0.9.0)
- [x] Per-course "Curriculum mappings" page (mappings.php via course navigation):
      grouped by location (inherited categories read-only, whole course, sections,
      activities), anchors summary, scope badges with central rows locked for course
      staff, orphaned section, add-mapping form (location + programme year + all-roles
      node autocomplete incl. outcomes + relation, central scope admins only) (v0.10.0)
- [x] Contract tests from the `moodle_mapping_api_test` Python repo against the ws API
      (13 tests green against the live playground; contracttest-tagged rows self-clean)
- [ ] Named node groups (phase 2, optional)
- [ ] Investigate binding survival across course backup/restore/duplication

### M9 — Central course matching (site admin)

Signals evidence base and rule derivation live in the `moodle_mapping_api_test`
repo (`MATCHING_SIGNALS.md`, the Python matcher prototype and the production
extract fixtures) — the prototype stays the offline rules lab. Agreed scope
(July 2026): course matches target programme-year nodes ONLY (the anchor = the
affiliation); no-idnumber courses are matchable (support courses need mappings
too) but hidden by default behind the "Only courses with idnumber" toggle;
years below the discovery floor are out of scope; fuzzy = lowercase whole-word
overlap, suggestion-only; harmonised academic year = first four digits of the
year token; rules are data (the `matchingrules` setting), so conventions
evolve without releases; UI language says "match" — "anchor" stays internal.

- [x] Matching engine (`local\matcher`): idnumber year parsing (both estate
      dialects + the range-spelling exception), name/category year fallback with
      unicode-dash normalisation, alias rule table -> slug + year-node narrowing,
      whole-word overlap fallback, statuses match/suggest/nocoverage/noyear/
      nomatch/skipped; `matchingrules` admin setting (JSON, shipped defaults);
      PHPUnit over the production conventions (v0.11.0)
- [x] `course_mapping.php` admin page (managebindings at system context), UI
      designed with Brian via mockups, two directions: match by Moodle course
      (per-row proposal dropdown: proposals + all programme years) and match by
      Sofia curriculum (pick a programme year, courses ranked by fit — already
      matched / matched / suggestions / search results); toolbar = keyword+year
      search, idnumber toggle (default off in Sofia mode), show filter
      (matched / unmatched / already matched / all incl. skipped); explicit
      per-row tick + select-all, nothing preselected for apply; confirm creates
      central-scope anchor bindings (idempotent) (v0.11.0)
- [x] Admin settings restructured into a plugin category (General settings /
      Course matching settings / status page / matching page) per the Quiz
      pattern (v0.11.0)
- [ ] `section_module_mapping.php`: per-course drill-down matching sections and
      modules against nodes at or below the course's anchored programme-year
      (section names are the signal; skip-list housekeeping sections)
- [ ] Picker locking: course staff node pickers offer only the anchored
      programme-year(s) once anchors exist (strict-lock decision)
- [ ] Rollover assist: year-swap successor proposal from existing anchors
      (dry-run report first)
- [ ] Verify on playground after push: upgrade to 2026071360, matching page
      proposes correct anchors for the seeded test estate, confirm round-trip,
      CI green on both DBs

### M10 — Hardening (pre-pilot)

- [ ] Webhook receiver (HMAC-signed, event_at dedupe) queuing adhoc sync
- [ ] Error-path testing: Sofia 5xx, partial payloads, expired credentials
- [ ] Performance: full-programme sync < 60 s on the 1,497-node corpus
- [ ] Security review: secret handling, capability enforcement on every entry point,
      Sofia text treated as untrusted on every render path

## Open questions (tracked in the umbrella repo, mirrored here)

1. Binding authority — `managebindings` for editing teachers in-course (current default)
   or admin-only?
2. Binding survival across backup/restore (cmids renumber) — local-plugin backup hooks vs
   idnumber-assisted rebinding + orphan report.
3. TimeEdit fusion key for future week/section bindings — shared identifier written into
   Sofia metadata vs fuzzy title match.
4. Tag schema versioning — keep historical tag options for soft-deleted nodes, or prune.
5. Webhook endpoint exposure — acceptable to VLE admin, or scheduled sync only?

## Definition of done (v1)

Installs and uninstalls cleanly on Moodle 4.5; nightly/hourly sync mirrors `vet-med` with
counts verified against fixtures; the service API serves the presenter within its render
budget; Sofia-row immutability holds under test; CSV import (when enabled) round-trips;
binding API passes all address-depth and permission tests; PHPCS/PHPUnit/Behat green in CI.
