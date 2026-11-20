# moodle-local_curricmap

> **Status:** v0.37.0 · shared content mapping review · test release
> **Component:** `local_curricmap` (repo name follows the RVC `moodle-<component>` convention)
> **Minimum Moodle:** 4.5 LTS
> **Databases:** MySQL 8.4 (RVC production), MariaDB 10.6.7+, PostgreSQL 13+ — CI tests Postgres 14 and MySQL 8.4
> **Licence:** GPL v3 or later

The core Moodle local plugin of the RVC curriculum mapping suite. It will be the **only
component that talks to Sofia** (the institutional curriculum management system) and the
**owner of all curriculum data inside Moodle**, consumed via its PHP service API and web
services by the companion plugins:

- `mod_curricmap` — activity module presenting strand content in courses
- `tiny_curricmap` / `filter_curricmap` — TinyMCE editor plugin + display filter for
  curriculum references in text areas

## What this plugin will do (full design)

- **Sync engine** — scheduled (hourly–daily) pull from the Sofia API: Compare API as a
  cheap change detector, full snapshot as the transactional apply mechanism, revision hash
  as the change-control anchor. Rate-limit aware (Sofia allows 60 requests/hour/site).
- **Curriculum store** — a single polymorphic node table mirroring Sofia's graph (years,
  strands, sessions, outcomes), with derived columns (`role`, `grouping`, `sortorder`)
  computed at sync time; a directed edge table for connections (`implements`
  traceability); synced tag schema (RCVS/EAEVE/AVMA competencies, modality, themes).
- **Source-of-truth model** — every row carries `source` (`sofia | csv | manual`). Sofia
  rows are **immutable inside Moodle** (written only by sync, enforced at the storage
  layer); CSV and manual rows are the mutable path for courses not governed by Sofia.
- **CSV import/export** — optional, disabled by default, capability-gated; dry-run then
  transactional commit.
- **Binding API** — a generic join surface between Moodle locations (course → section →
  activity → sub-activity) and curriculum nodes, with soft-typed relations (`anchor`,
  `teaches`, …) and Moodle-context permissions. API-first: mapping UIs and coverage
  reporting come later as clients. Cookbook with per-grain web-service examples:
  [docs/BINDING_API.md](docs/BINDING_API.md).
- **Admin tooling** — sync status/trigger/log, statistics export, orphan reports.

## What v0.2.0 actually contains

The base scaffold (M1) plus schema and derivation (M2):

- `version.php`, plugin language strings, placeholder admin settings page
- Capability definitions (`managesync`, `importcsv`, `editmanual`, `viewstaffmeta`,
  `managebindings`)
- Privacy API null provider (no personal data stored yet)
- **Full database schema** (`db/install.xml` + upgrade path): nine tables — programme,
  node, edge, tag schema (3), synclog, audit, binding
- **`\local_curricmap\local\derive`**: role derivation, grouping-label extraction,
  tree assembly; natural sort for csv/manual rows
- **PHPUnit suite + recorded Sofia fixture corpus** (three revisions of the vet-med
  test programme with verified deltas)
- CI workflow (moodle-plugin-ci: lint, PHPCS, savepoints, PHPUnit on Postgres 14 and
  MySQL 8.4)

No Sofia client or sync yet — those land per [PLAN.md](PLAN.md) (M3/M4).

## The mapping model (rulings 2026-07-23)

- **Central Admin Mapping** (`course_mapping.php`, site admin) makes ONE
  central decision per course: match it to a Sofia programme year (a *year
  course*) or to a strand (a *strand course*). Once made, nothing on that
  page changes it — a decided course shows "Already matched" with no tick
  and no dropdown; delete-and-redo is the only correction path there.
- **Map course content** (`section_module_mapping.php`) is one shared editor
  for site administrators and authorised course administrators. The course menu
  and activity menu (`mappings.php` compatibility route) open this editor.
  Sections/subsections retain Moodle order; label text, activity descriptions,
  page bodies, book descriptions and chapter/subchapter text appear with mappings.
- Course administrators may remove/replace central content mappings. Course
  affiliation remains site-admin-only. The same session/outcome can be mapped
  at several locations; duplicate bindings at the same address remain idempotent.
- **Show only top sections** collapses the hierarchy but permits opening any
  branch. **Show all** reveals the hierarchy with text previews closed.
  **Expand all** opens all branches and text, then becomes **Collapse all**,
  which closes text previews. Loaded rows and unsaved selections survive these
  controls. Leaving the page with pending edits prompts before navigation.
- **Suggested matches and source text** offers a Pick button beside each session
  and outcome. Outcomes of a suggested session appear beneath it; related outcomes
  without a direct text match are labelled explicitly. Picking a session never
  implicitly picks its outcomes. Manually typed learning outcomes are valid evidence.
- Each section, activity and chapter has its own **Save changes / Cancel** controls.
  Saved mappings and pending additions are labelled separately. Saves are atomic,
  preserve other drafts and scroll position, and retain choices on failure for retry.
  Removing every choice and saving clears that item's explicit anchors; inherited
  parent mappings still apply through the existing resolver.
- **More curriculum** opens a prominent search panel beneath the item. Search checks
  the complete curriculum beneath the course's central anchors, including unloaded
  descendants; results include ancestry and independent Pick buttons. Empty search
  returns to browsing. At most 50 results are shown, with a refinement notice.
- Academic-year recognition accepts a four- or six-digit year token anywhere in
  `idnumber`, independently of prefixes/suffixes. `VN1201_A_Y_202627_1`, `2026`,
  `2026-`, `2026_`, `202627` and `202628` resolve to 2026. Longer uninterrupted
  numbers are not treated as year tokens. Existing name/category range fallbacks
  remain. The central search accepts the same standalone year spellings; a full
  course idnumber remains a keyword search.
- Inline TinyMCE editing is outside this release; saved anchors continue to use
  the existing mapping resolver consumed by the editor/filter plugins.
- Course study-resource management remains available from the editor's resource
  link (`mappings.php?resources=1`). Activity types disabled in the plugin settings
  still display their content, with an explanation beside the disabled mapping
  controls. Labels are included in new installations' defaults; existing saved
  activity-type settings are retained.


## Installation

1. Clone into your Moodle at `local/curricmap`:

   ```bash
   git clone https://github.com/RVC-Learn-Digital-Team/moodle-local_curricmap.git local/curricmap
   ```

2. Visit *Site administration* as an admin to complete the install.
3. The settings page appears under *Site administration → Plugins → Local plugins →
   Curriculum map*.

## Design documentation

The authoritative design lives in the RVC `sofia_api_explorer` umbrella project alongside
the Sofia API fixtures it is derived from:

- `LOCAL_CURRICMAP_DESIGN.md` — schema, sync engine, service API, testing strategy
- `IMPLEMENTING_SOFIA_GRAPH_IN_MOODLE.md` — narrative rationale (graph→relational mapping,
  presentation model, binding model)
- `SOFIA_GRAPH_DOCUMENTATION.md` — the Sofia graph structures and observed API behaviour,
  including change-control findings

The matching engine's tunable rules (the `matchingrules` admin setting) are documented in
this repo: **`MATCHING_RULES.md`** — structure reference for every key, how the matcher
applies them, and a runbook (with the CSV inputs required) for refreshing the patterns
from data when course names or structures change.

## Repository conventions

- Frankenstyle component: `local_curricmap`; repo named `moodle-local_curricmap` per RVC
  convention. All database tables, capabilities and web services use `local_curricmap` /
  `local/curricmap`.
- All PHP files carry the standard Moodle GPL header; PHPCS `moodle` standard enforced in CI.
- `main` is releasable; feature work in `feat/...` branches.

## License

GNU GPL v3 or later. Copyright 2026 The Royal Veterinary College.
See [LICENSE](LICENSE).


## JavaScript builds and releases

Edit `amd/src/` and generate all four AMD modules with Moodle's own ESLint and
Rollup tasks. The standalone build service in the Docker playground mounts Moodle
core read-only and this development checkout at `/moodle/local/curricmap`.
The running playground and its installed plugin checkout are not changed.

The Node 22 image and Moodle npm dependencies were provisioned on 2026-11-18 in
the separate `curricmapbuild` Compose project. Normal rebuild from this checkout:

```bash
cd /Volumes/MacMiniSSD/dev/sofia_api_explorer/moodle-local_curricmap
docker compose -p curricmapbuild \
    -f /Volumes/MacMiniSSD/dev/docker/learning_tools_ai_playground/docker-compose.tools.yml \
    run --rm --no-deps amdbuild \
    node node_modules/grunt/bin/grunt eslint:amd rollup \
    --root=local/curricmap --show-lint-warnings
node --test tests/content_mapping_test.cjs
```

These are the lint and build tasks used by `grunt amd`, omitting its `ignorefiles`
task because that writes Moodle-root configuration files. Do not use `--force`.
Stop if Grunt reports an error; do not ship stale generated files. The three
existing callback warnings in `nodeselector.js` are separate from this fragment
fix. Both `.min.js` and `.min.js.map` files must be included in the release.

If the standalone build dependency volume is removed, provision it again without
writing a lockfile or installing packages in the managed Moodle checkout:

```bash
cd /Volumes/MacMiniSSD/dev/sofia_api_explorer/moodle-local_curricmap
mkdir -p test_results/amd_build
docker compose -p curricmapbuild \
    -f /Volumes/MacMiniSSD/dev/docker/learning_tools_ai_playground/docker-compose.tools.yml \
    run --rm --no-deps \
    -e npm_config_cache=/moodle/node_modules/.npm-cache \
    -e npm_config_logs_dir=/moodle/local/curricmap/test_results/amd_build \
    amdbuild npm install --no-audit --no-fund --package-lock=false --ignore-scripts
```

Always increase `version.php`, even for JavaScript-only changes. After deploying,
complete Moodle's upgrade through Site administration so its normal upgrade cache
refresh runs. Never manage playground submodules as part of this build; Brian's
scripts handle their Git updates.
