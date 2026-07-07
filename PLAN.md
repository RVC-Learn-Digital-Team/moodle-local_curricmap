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
- [ ] Install/uninstall clean on Moodle 4.5 (verify on the docker playground after
      submodule update); PHPCS zero errors in CI

### M3 — Sofia client

- [ ] OAuth2 client-credentials on `\core\http_client`, token cache (MUC), single 401 retry
- [ ] `metadata() / nodes() / tree() / compare()` with key-only query options
- [ ] Rate-limit header tracking + configurable refusal floor
- [ ] PHPUnit via Guzzle MockHandler: token flow, 401, 5xx, truncated JSON, rate floor
- [ ] Secrets in Moodle secret config; TLS verification always on

### M4 — Sync engine (full sync)

- [ ] Snapshot apply: upsert nodes by UUID, rebuild edges/tags, recompute
      path/sortorder/role/grouping, soft-delete missing, transactional per programme
- [ ] Synclog with per-run statistics and rate headroom
- [ ] Golden-master tests against the captured fixture corpus: sync A → counts match
      (1,497 nodes, 3,786 implements edges, 14 strands, 79 strand outcomes);
      A-over-A → no-op; mid-transaction failure → previous revision intact
- [ ] Scheduled task (default hourly, self-guarded bounds) + adhoc task + CLI trigger

### M5 — Change detection + admin UI

- [ ] Compare-API change check before apply (1 request when unchanged)
- [ ] Golden-master: sync B-over-A applies exactly the known captured delta
- [ ] Admin pages: sync status, trigger-now, log view, statistics CSV export
- [ ] Behat: admin flows

### M6 — Service API + caching

- [ ] Query surface: programmes/years/strands/strand_outcomes/units/sessions/
      session_outcomes/node/subtree/implements/implemented_by/tags/tag_schema/search
- [ ] MUC caches keyed on (programmeid, revisionhash, query, args)
- [ ] Read-only external functions for AJAX + external consumers
- [ ] Query-count ceilings (perfdebug) protecting the presenter's render budget

### M7 — Mutable sources

- [ ] Manual CRUD (course-scoped, `editmanual`), audit log; replace privacy null provider
      with full metadata provider
- [ ] CSV import/export port from `mod_curriculummapexp`: dry-run → transactional commit,
      `enablecsvimport` setting (default off) + `importcsv` capability
- [ ] Immutability tests: no write path can touch `source='sofia'` rows; CSV rows with a
      Sofia UUID rejected per-line; export carries `source`, importer refuses it back

### M8 — Binding API

- [ ] Binding table + service API: bind/unbind, find_by_course/cm/node/subtree,
      orphaned_bindings, anchor(courseid)
- [ ] External functions with course-context permission checks
- [ ] Event observers: course_module_deleted / course_section_deleted / course_deleted
- [ ] Tests: all four address depths incl. sub-activity, permission denial, orphaning on
      module deletion and on Sofia soft-delete
- [ ] Investigate binding survival across course backup/restore/duplication

### M9 — Hardening (pre-pilot)

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
