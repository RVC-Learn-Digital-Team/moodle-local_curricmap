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
- [x] Click-test round 1 fixes (v0.11.1): toolbar unified into one auto-
      submitting GET form (search text + every filter travel together — the
      old split forms lost checkbox state on select change); picking a
      proposed match ticks the row's apply checkbox (AMD
      `local_curricmap/course_mapping`, hand-built artifact); Show options
      carry live row counts so empty views explain themselves; checkbox
      renamed "idnumber only"; new "include strands" toggle adds strand nodes
      as match targets (strand-shaped courses like PVP match their strand,
      word overlap ranks it above the year node — amends the year-level-only
      rule); delete icon per entry in Current matches (central course-level
      rows only) so the page covers create/read/delete — "update" = remove +
      re-match
- [x] Round-3 simplification (v0.11.3) after widget regressions: rich search
      lives in exactly ONE place — the Sofia node select (core autocomplete,
      substring anywhere; resubmits only on a real selection, never on clear).
      Course-mode row dropdowns are short native selects holding only the
      engine's proposals (form-autocomplete cannot see optgroup children, which
      had blanked them); manual map-course-to-anything is Sofia mode's job.
      Auto-submit only on controls whose change requires new data
- [x] Slug-year selector (v0.11.4, Brian's design): one programme×year filter
      in the toolbar, both modes, blank = everything. Narrows what nodes are
      OFFERED (never what the engine proposes): Sofia node select options, and
      course-mode row dropdowns — blank keeps rows proposals-only (native),
      a selected slug-year adds that year's nodes (+strands when toggled) as a
      flat searchable list (autocomplete works on flat lists). Also: unmatched
      band redefined = no proposal AND no current match (already-matched
      courses stop counting as unmatched)
Click-test findings 2026-07-11 (course mode; auto-match itself confirmed "right
first choice every time"):

- [ ] Show filter: "matched proposals" mixes already-applied and pending rows —
      add a band for pending-only (proposal exists AND no current match, the
      "unmapped matched"), so the working view empties as matches are applied
- [ ] Slug-year filter leaks: with vet-nur 2023-24 selected, vet-med 2026
      proposals (courses and strands) still appear in row dropdowns — decide:
      when a slug-year is chosen, hide off-filter proposals from the dropdown
      too (proposals were deliberately unfiltered in v0.11.4; Brian finds the
      leak confusing, so filter them — the proposal still shows via the badge/
      status, just not as a pickable option outside the filter)
- [ ] Plugin admin pages need cross-navigation: each page in the Curriculum map
      category (General settings / Course matching settings / Status / Central
      course matching) gets links or a dropdown near the top pointing to the
      others

Deeper-mapping decisions (Brian, 2026-07-11 — full write-up in the umbrella
overview §5): binding grains restricted to course / section / activity module /
**book chapters only** (no other sub-module types); sections are usually weeks
(week N = lectures + support material), so section bindings mostly mean "week N
teaches these units/outcomes"; **mapping precedes material** (week bindings
exist before the lecture is recorded — the platform's engine appends recordings
as node resources later via the ws API, a separate external build); rollover is
its own automation, not part of the matching pages.

- [x] `section_module_mapping.php` (v0.12.0) — design agreed via corpus + live
      mirror evidence (no `unit` nodes exist: units are session grouplabels,
      so targets are strand/session/outcomes/assessment): entry from
      course_mapping rows ("Map course content") or on-page course finder;
      requires a central match (pool = subtree of the matched nodes); toolbar
      = section filter + module-type filter + course switch; sections propose
      strands via synonym-aware title containment (`matcher::match_title`,
      `synonyms`/`mincontainment`/`skipsections` in the rules JSON — CVRS→
      Cardiovascular & Respiratory etc., 100% hint rate on the GAB corpus
      names vs live mirror); modules propose sessions AND outcomes with
      cascading pools (section→strand narrows its modules; module→session
      adds that session's outcomes; unnarrowed pools >300 offer hints only);
      housekeeping badge; same tick/apply/remove grammar; all bindings
      central-scope anchors. Weeks never name-match (no week concept in
      Sofia — weeks move; recordings arrive via the platform engine).
      Grouplabel/unit filtering deferred (agreed)
- [x] `study_resources.php` (v0.13.0) — the resources half of the two-edge
      model gets its UI: node-first (slug-year → searchable node picker),
      subtree roll-up table (a strand shows its sessions'/outcomes' material
      with the owning node named), add form (vocabulary dropdown + custom-type
      override + label + URL; unscoped only — course scoping stays API-only
      per agreement), delete, idempotent on node+url. Cross-links from content
      mapping: every bound node in Current matches shows "resources (N)"
      linking into the page pre-filtered (slug-year derived from the composed
      key). Bulk population remains the platform engine's job via ws
- [x] Study resources course-first rework (v0.13.1, Brian's workflow ruling:
      "select a course, find all mappings, then for each add one or more
      resource links"): courseid mode lists EVERY central mapping of the
      course (whole course / sections / activities, in binding order) with
      each node's resources inline and a per-row add form — the whole
      attach-resources pass is one page, no node hunting. Node-first view
      kept for curriculum-wide curation (subtree roll-up); course finder in
      both views; content mapping header links to the course view
- [ ] Grouplabel ("unit") filtering on the content mapping page — narrow a
      matched strand's session/outcome pool by unit label (deferred from
      v0.12.0 by agreement)
- [x] Book chapter mapping (v0.14.0) — integrated as a chapter VIEW of
      section_module_mapping.php (?bookcm=), not a separate file (Brian's
      revision of the 2026-07-14 plan): book rows in the lazy activity lists
      link "Map chapters (N mapped)" → book-name heading, chapter rows with
      hints + multi-select pickers, "Match selected chapters" + Back button.
      Address = cmid + mod_book + chapter subitemid. Books remain the ONLY
      sub-module grain in v1
- [x] Content mapper redesign (v0.14.0, click-test driven): ALL sections
      always listed (no expand to see counts) with per-section
      activities/mapped and chapters/mapped counts computed server-side;
      strand proposal shown ONLY when the pool is ambiguous (>1); activity
      mapping rows lazy-load per section via the fragment API
      (lib.php fragment callback + contentmap helper class, rows join the
      page's form); section + module-type filters are MULTI-select chips
      applied by Go (multi never auto-submits); apply button repeats every 4
      sections; no forced mapping anywhere (collapse = cancel)
- [ ] API character split (agreed 2026-07-14): course/content mapping ws is
      read-oriented — its role is letting the curriculum_mapping platform
      extract Sofia graphs + Moodle mappings WITHOUT the Sofia API; consider
      withdrawing/locking external bind/unbind for v1 (writes stay in the
      admin UI). The resource ws becomes the CRUD surface for external
      inserts (Panopto engine, library) — may slip past v1
- [x] Teacher-resources groundwork (v0.15.0/2026071400 — design in umbrella
      TINY_FILTER_CURRICMAP_DESIGN.md §7): new course-context capability
      `local/curricmap:managecourseresources` (default editingteacher);
      `visible` flag on the resource table + upgrade step (hide/show via
      resources::set_visible; viewer reads exclude hidden by default, admin
      surfaces pass includehidden — global resources always display and
      cannot be hidden); resources::can_manage() splits course-scoped CRUD
      (managecourseresources OR managebindings in course) from institutional
      (managebindings at system) and gates add/delete/set-visibility ws; NEW
      ws local_curricmap_set_resource_visibility (service updated);
      curriculum::parent() for the filter's up-one-level link; course study
      resources section on mappings.php (list + hide/show + confirmed delete
      + add form offering the course's bound nodes; name AND url required).
      All ws were already ajax=>true. Verified: phpcs clean + rolled-back
      /tmp harness against the live mirror; new PHPUnit tests
      (test_resource_visibility, test_resources_can_manage) run in CI after
      push. NOTE: list_resources ws now returns hidden rows too, with a new
      `visible` field — check the pytest contract suite for strict asserts
- [x] mappings.php picker fixes (v0.15.3/2026071403, Brian 2026-07-16):
      programme-year select sorted alphabetically (curriculum::programmes now
      orders slug ASC, versionlabel ASC — benefits every caller incl. the
      status page); node autocomplete's empty-query starting points now list
      each year FOLLOWED BY ITS STRANDS (new optional withstrands param on
      the get_children ws; strand-shaped courses like Alimentary map to a
      strand directly — typing always searched all roles, but nobody could
      see that). nodeselector.min.js regenerated via the babel transform
      (replacing the hand-built artifact); uuid-dedup between starting
      points and search results. Tests: programmes ordering + interleave
      assertions; harness-verified against the live mirror
- [x] Strict-lock hardening (v0.15.2/2026071402, Brian's click-test rulings
      2026-07-16): (1) mappings.php honours role switching — a role-switched
      admin sees exactly what a real teacher could (central rows undeletable);
      real teachers were always blocked (system-capability check). (2) NEW
      resources::within_course_scope(node, course) — anchor-or-below check —
      enforced in the add_resource ws for course-scoped adds by non-central
      users: teachers can only attach resources within the course's centrally
      mapped scope (unmatched course = nothing qualifies); central users and
      the platform engine unrestricted. New lang string errorresourcescope;
      two new ws tests (scope lock + edges); harness-verified on live mirror
- [x] Subtree-limited search (v0.15.1/2026071401, for tiny's strict-lock
      picker): curriculum::search gains an optional $ancestoruuid (path-LIKE
      restriction, ancestor included; unknown/deleted ancestor returns []);
      ws local_curricmap_search gains the matching optional ancestoruuid
      param (programmeid stays 0-for-all — the ancestor determines the
      subtree). PHPUnit assertions added to test_query_surface; verified via
      read-only harness against the live mirror
- [x] Graph-export ws (v0.16.0/2026071410, Brian go 2026-07-16 — the bulk
      extraction surface for the platform's sofia loader and the external
      mapping engine, closing the "picker-shaped exports only" gap):
      curriculum::nodes() (per programme, optional subtree, includedeleted,
      paged, uncached) + curriculum::edges() (both ends as composed keys);
      NEW ws local_curricmap_get_nodes (payload rebuilds the tree offline:
      parentuuid — with out-of-page parents resolved in one extra query —
      role/subtype/code/title/description/grouplabel/sortorder/depth/
      sofiaurl/pebblepad/source/sourceversion/deleted/timemodified, plus
      programme block with revisionhash and a paging-safe total) and
      local_curricmap_get_edges (sourceuuid/targetuuid/connectiontype/
      sortorder); both require viewstaffmeta at SYSTEM context (staff/
      integration surface — course-level teachers refused); service updated.
      Consumers: learning_tools_content_api CSVs (course_id/cmid/section_id/
      chapter_id = ready-made binding addresses + clean_text matching
      corpus) on one side, bind/unbind on the other — the extraction→match→
      bind loop is now fully closed over the ws. Tests: test_graph_export
      (tree reconstruction, paging, subtree, deleted flags, implements edge
      pair, type filter) + permission test. Contract-suite additions pending
- [x] LO-code display fix (v0.16.1/2026071411, Brian 2026-07-17): both
      course_mapping.php's proposal label and contentmap::label() (content
      mapper's dropdowns) now show the node's code — data was always 100%
      populated on outcomes (UG1-AH-LO8 format), only these two label
      builders never included it; mappings.php and tiny already did
- [x] Strand-code legend synonyms (v0.16.2/2026071412): the 21-code master
      legend from Brian's strand-map tooling folded into the shipped default
      synonyms (ah/alim/cs/devb/dops/loc/loco/lym/pmvph/pos/repr/rs/sebm/
      skn/urn/vph added; 'end' EXCLUDED — proven false-positive on "END OF
      ..." titles; 'nma' excluded — no Sofia strand). Evidence: legend codes
      appear as real tokens in 57.8k scanned titles (IAA 66, POS 31, PVP 23,
      CS 20 ...). NOTE: sites with a SAVED matchingrules setting do not
      inherit (top-level merge) — paste the new synonyms block into live
      settings by hand; fresh installs get them automatically. Full analysis
      in Documentation/SEARCH_AND_STRUCTURE_MATCHING.md
- [x] Generator listing updates (v0.19.4/2026071434, Brian's spec):
      --list_courses now leads with the course id column; NEW
      --list_programmes emits every slug/year/programme-year combination as
      CSV with strand counts — the lookup companion for --slug/--year/
      --programme_year/--strand_course. Verified live
- [x] `cli/empty_test_course.php` (v0.19.3/2026071433, Brian's ask after a
      spreadsheet error put generated content into the wrong strand
      courses): empty a course WITHOUT deleting it — all activities, then
      all sections above 0; course, settings and enrolments survive.
      --idnumber accepts CSV for several courses; DRY RUN by default, only
      --confirm deletes. Deleted modules route through the course recycle
      bin (recoverable for the retention window); binding observers mark
      module bindings orphaned; course-level central matches untouched
      (remove those on course_mapping.php if also wrong). Verified live:
      generate → dry-run → confirm → course intact with 0 activities
- [x] Cache-stamp fix (v0.19.2/2026071432, found live by Brian on vle-test —
      "vet-nur 2026 has 0 strands but 2025 works" after force-syncing the
      derive change): the MUC query stamp keyed on programme revision hashes
      ONLY, and a force full sync re-derives rows WITHOUT moving the Sofia
      revision — so cached years()/strands()/children() kept serving
      pre-rederivation results; which programmes "worked" depended on cache
      population order. The uncached ws get_nodes proved the mirror itself
      was correct throughout. Stamp now includes each programme's
      timelastchanged (bumped by every apply incl. forced; untouched by
      noop syncs, so normal caching behaviour is preserved). New test pins
      it. Interim remedy on any already-affected site: purge caches once
- [x] Sync log shows the year (v0.19.1/2026071431, Brian mid-force-sync):
      Recent sync runs table + the CSV export showed only the programme slug
      — with 12 slug×year rows you cannot see which years are done. Both now
      render slug:versionlabel (matching the notification format)
- [x] Modules derive as strands (v0.19.0/2026071430, Brian's Option A after
      the production-Sofia structure audit via the graph ws): production
      facts — bio-sc is ENTIRELY modular (51 Module nodes, 0 strands),
      vet-nur years are modular (11), and vet-med uses Modules for
      Veterinary Gateway (10) + Graduate accelerated (6); module outcome
      children were role 'other' (invisible to every role-filtered surface —
      why vet-nur/bio-sc always showed "0 strands"). Changes: (1)
      UNIT_SUBTYPE_ROLES gains 'Module' => strand — outcome children become
      strandoutcome automatically via the parent-aware rule; (2)
      STRANDOUTCOME_PARENTS gains 'year' — year-level outcomes (bio-sc MSci
      years etc.) bucket as strand outcomes; (3) get_nodes ws now exports
      the raw Sofia `type` letter (classification + platform use). Fixture
      counts unchanged (test corpus has neither shape). DEPLOY NOTE: role is
      computed at sync time and the change detector skips unchanged
      revisions — run **Force full sync** once per programme after this
      version lands, on every environment. REMAINING WAVE 2: vet-med has 42
      untyped containers under clinical years ("Anaesthesia" etc., ~574
      outcome children) — classify via the new `type` export after the next
      vle-test deploy, then decide their rule; also flag missing typeName to
      the Sofia/curriculum team as a data-quality issue. O-under-assessment
      (4 nodes estate-wide) left as 'other' deliberately
- [x] Generator --programme_year selector (v0.18.5/2026071426, from Brian's
      first rvc-vle-test run against PRODUCTION Sofia: "No strands under
      Veterinary Gateway"): programmes carry MULTIPLE year-role nodes (Year
      1..5, Gateway, GAB — even the playground has Year 1 + a strand-less
      Year 2); the script blindly took the first. Now: exactly one year node
      = auto; more than one = require --programme_year=<title|code|uuid> and
      list them WITH strand counts; strand-less pick = guidance + the same
      list. Also v0.18.4: --list_courses output switched from tabs to
      quoted CSV (terminals render tabs as spaces; fullnames contain commas)
- [x] Generator --list_courses[=N] (v0.18.3/2026071424, Brian's spec):
      tab-separated idnumber/fullname/shortname/categoryid listing; bare =
      whole site, =N restricts to category N INCLUDING subcategories (path
      prefix); unknown N refuses + prints the category list; runs before any
      sync requirement. Verified live incl. subcategory traversal
- [x] Generator --categoryid mandatory (v0.18.2/2026071423, Brian's ruling):
      creating a course without --categoryid (or with a nonexistent id)
      refuses and prints the available category list; --match_existing does
      not need it. All three paths verified live
- [x] `cli/generate_test_course.php` (v0.18.1/2026071422, Brian's spec
      2026-07-17) — test content from the mirror itself, because both
      playground AND rvc-vle-test are empty and learn-uat needs a partner
      ticket per deploy. Options: --strand_course=<title|code|uuid> (prints
      the strand list when not found), --idnumber=, --match_existing (append
      to the course with that idnumber), --strand_sections (loose pages/
      urls/labels instead of the default book-per-strand), plus --slug/
      --year/--maxsessions/--category. Generates: section per strand named
      from the strand, book with a chapter per session (chapter body =
      session description + its outcomes = real body-matching corpus),
      "General" + "Support Blocks" DECOY sections (skip rules), a
      red-herring page from a DIFFERENT strand (false-positive check), and
      a page of pre-authored inline+card filter placeholders. Idnumber
      defaults to the old-tool dialect so central matching proposes
      immediately. Sets the admin user (create_module checks capabilities).
      Verified on the playground: Locomotor course, 8 chapters with real
      body text, decoys and placeholders present; test course removed after.
      Port to other sites via standard course backup/restore (.mbz) — no
      CLI needed there
- [x] Skip-list additions + body-text matching (v0.18.0/2026071421, Brian
      go 2026-07-17): (1) skipsections gains anchored patterns ^general$,
      ^support blocks?$, ^welcome (&|and) overview$, ^learn guidance —
      ANCHORED so "General" is skipped but "General Pathology"/"General
      Anaesthesia" are kept (verified against the real corpus); is_housekeeping
      now html_entity_decodes the name (get_section_name returns &amp;-escaped
      text, so patterns stay human-readable). Reading List + Strand Overview/
      Learning Resources deliberately NOT added (Brian: keep reading list;
      strand-template sections hold real material — evidence below).
      (2) Body-text matching: matcher::match_body() (secondary signal, own
      stricter bodymincontainment 0.75 + bodyminwords 2 so long prose and
      single-word titles don't over-match); contentmap::body_text() pulls
      intro/content per type via content_to_text() (Moodle's own stripper —
      no PHP HTML-cleaning needed), capped 8000 chars; merged_hints() appends
      body hints for nodes the title missed, tagged " text" in the picker.
      Wired into activity rows AND the chapter view (chapters already had
      content in memory). Same "Course activities to map" gate — body reading
      only applies to already-mappable types, adds a signal, never a type.
      11/11 matcher+curriculum PHPUnit green; live body harness deferred
      (playground reinstalled without book content — logic covered by tests)
- [x] Search RANKING (v0.17.0/2026071420, Brian go 2026-07-17) — full design
      + findings in Documentation/SEARCH_AND_STRUCTURE_MATCHING.md.
      curriculum::search() rewritten from plain-LIKE-tree-order to OR-pool +
      coverage ranking: query tokens synonym-expanded (matcher::expand_tokens,
      extracted + shared so match_title and search agree), candidates matching
      ANY token pooled (cap SEARCH_POOL=400), ranked codematch > coverage
      (3-of-3 tokens beat 2-of-3) > title tightness > tree order; token
      matching is exact for <3-char tokens, prefix for 3+ ("cs" never matches
      inside "physics"; "locomot" finds "locomotor"). Code queries: whole code
      exact-first, single-token = code final segment (LO32 finds
      UG1-LOCO-LO32). Signature UNCHANGED so every consumer (mappings.php
      autocomplete, tiny dialog, ws, external) inherits it; ws contract shape
      unchanged (only ordering). Verified live: pos→Principles of Science
      strand 1st, cs cough→CVR strand 1st, locomotion(synonym)→Locomotor 1st,
      alim(legend)→Alimentary 1st, full+partial codes exact-first, 1-45ms.
      Legend synonyms now reach TYPED search, not just proposals — the "codes
      into search" outcome Brian wanted
- [ ] Course-structure positional matching (section number ↔ session
      sortorder, "Week N") — HYPOTHESIS, DOWNGRADED after the day-of-week
      finding (needs 3 structural dialects or VN-family-only scope); revisit
      only if body-text matching leaves gaps. See design doc §3
- [x] mappings.php UX round (v0.21.0/2026071450, Brian's click-test
      2026-07-18): (1) course-resources add form gets wrap spacing (gap +
      label margins in styles.css). (2) STRICT LOCK lands on the add-mapping
      form: anchored courses lose the free programme-year select (replaced
      by a static "Matched curriculum" line); the node picker gains
      data-ancestors (anchor years — a strand/module anchor widens to its
      parent year so sibling strands stay offerable) + data-exclude (nodes
      already mapped in the course); nodeselector.js locked mode: empty
      query lists the matched years' strands (minus mapped), typing searches
      ONLY within the matched subtrees via ancestoruuid — no other
      programmes or years, ever; unmatched courses keep the old free picker.
      New placeholder "Select additional strands below, or type to search" +
      locked help text. (3) Scope select (Course/Central) gets a help
      tooltip. (4) DELETE CONFIRMATIONS everywhere they were missing:
      mappings.php unbind, course_mapping.php remove-match,
      section_module_mapping.php unbind, study_resources.php delete — all
      now show an are-you-sure page naming the node/resource before acting
      (course-resources delete already had one)
- [x] Alias courses get strand suggestions (v0.20.1/2026071441, Brian's
      vle-test report: "include strands shows no strands for bio-sc/vet-nur").
      DIAGNOSIS BY ELIMINATION, all fact-checked: mirror data correct (ws
      probe: bio-sc 43-51, vet-nur 9-11 strands/year), cached strands path
      correct (get_children withstrands via ws), candidates()/content pools
      correct (playground harness with module-shaped rows), page wiring
      correct (authenticated fetch of course_mapping.php?strands=1 — sofia
      select DID contain the strands). ACTUAL BUG: matcher::match()'s alias
      branch — the alias node regex (year\s*{n}) names the YEAR, and when
      exactly one candidate survives it returned immediately with zero
      suggestions, so alias-matched courses (ALL VN*/BIO_SCI_HUB idnumbers)
      could never be offered their module-strand, however the course was
      named. Fix: the single-survivor branch now also scores the programme's
      strand candidates by word overlap and attaches them as suggestions
      beside the deterministic year best; strands-off behaviour unchanged.
      New matcher test (VN1202 → best Year 1 + AAHW1 module suggestion
      first) + harness green. NOTE: sofia mode + slugyear-filtered rows
      always offered strands correctly — course-mode PROPOSALS were the gap
- [x] Coverage / reporting page (v0.20.0/2026071440, Brian go 2026-07-18):
      `coverage.php` admin page (viewstaffmeta at system — read-only central
      staff) + testable `classes/local/coverage.php` calculator. Definitions
      locked: a node is COVERED only via content-grain bindings (section/
      activity/chapter — anchors are affiliation, not coverage); matched =
      active central course-level anchor; in-scope denominator reuses the
      matching rules' skip patterns. Page: estate summary line + hygiene
      strip (orphaned, rollover stragglers, resources/hidden), programme-year
      summary table (strands, sessions x/y, outcomes x/y, matched courses,
      content bindings) with drill-down per year → strand-by-strand coverage
      + matched-course depth table (sections/activities/chapters bound,
      mappings-page links). CSV exports for all three tables. Staleness
      definition fixed after live check: "live" years = the TWO most recent
      versionlabels per slug (same current+upcoming pair as sync tiering) —
      latest-only wrongly flagged every 2026 anchor because 2027 is synced
      ahead. PHPUnit coverage_test (anchor≠coverage, grain depth, skip
      rules, orphan counter); harness-verified against live playground data
      (13 matched courses, 2/330 sessions, real numbers). Later: feed the
      engine's worklist via ws when the engine wants it
- [ ] Rollover — RESHAPED by restore findings (verified against 4.5 core,
      2026-07-16). Facts: (1) restore rewrites in-content links to course
      assets via encode/decode rules (mod/xxx/view.php?id=N → token → new
      id), so copied content points at the new course's copies
      automatically; (2) LOCAL plugins CAN participate in course backup —
      add_plugin_structure('local', ...) exists at course, section AND
      module level on both backup and restore sides, and restore plugins
      get get_mappingid() (old→new section/cm ids) plus after_restore_*
      hooks. Therefore, two-part plan:
      (a) backup/restore support in local_curricmap: bindings (and
          course-scoped resources) travel INSIDE the course backup at their
          owning level and are recreated against the NEW sectionids/cmids
          via the restore mappings — the old→new location matching problem
          disappears; bind() idempotency guards repeat restores. Restored
          keys still carry the OLD year segment.
      (b) rollover_mapping.php shrinks to a post-restore pass: year-swap
          the composed keys on the new course (uuids stable), verify
          against the new year's mirror, dry-run + needs-attention report.
      OPEN QUESTION (Brian to rule): node resources are year-pinned via
      composed keys — next year's node (same uuid, new key) shows NO
      resources unless (i) lookups gain a raw-uuid cross-year fallback
      (material carries forward automatically; stale recordings leak) or
      (ii) rows stay year-pinned and the rollover pass copies them forward
      (optionally by type — carry ebooks/links, not last year's Panopto).
      The studyresources_intro string currently overpromises ("rollover
      never touches it") and must match the ruling. Once (a) ships, the
      documented backup/restore limitation in both READMEs and TEST_PLAN §9
      is lifted
- [ ] Picker locking: course staff node pickers offer only the anchored
      programme-year(s) once anchors exist (strict-lock decision)
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
