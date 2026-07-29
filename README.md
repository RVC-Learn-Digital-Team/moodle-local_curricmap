# moodle-local_curricmap

> **Status:** v0.2.0 · schema + derivation (M2) · not for production
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
  reporting come later as clients.
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
- **Moodle Course Mapping** (`section_module_mapping.php`, site admin) is where a
  year course's strands are mapped: sections take strands, activities take
  sessions and outcomes within them.
- **Add Additional Mappings** (`mappings.php`, per course) is where manual extra
  mappings are made — additional strands or nodes beyond the central match —
  at course scope, or central scope for central staff.

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
