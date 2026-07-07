# moodle-local_curricmap

> **Status:** v0.1.0 · base scaffold · not for production
> **Component:** `local_curricmap` (repo name follows the RVC `moodle-<component>` convention)
> **Minimum Moodle:** 4.5 LTS
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

## What v0.1.0 actually contains

The installable base scaffold only:

- `version.php`, plugin language strings
- Capability definitions (`managesync`, `importcsv`, `editmanual`, `viewstaffmeta`,
  `managebindings`)
- Placeholder admin settings page
- Privacy API null provider (no personal data stored yet)
- CI workflow (moodle-plugin-ci: lint + PHPCS)

No database tables, no Sofia client, no sync — those land per [PLAN.md](PLAN.md).

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

## Repository conventions

- Frankenstyle component: `local_curricmap`; repo named `moodle-local_curricmap` per RVC
  convention. All database tables, capabilities and web services use `local_curricmap` /
  `local/curricmap`.
- All PHP files carry the standard Moodle GPL header; PHPCS `moodle` standard enforced in CI.
- `main` is releasable; feature work in `feat/...` branches.
