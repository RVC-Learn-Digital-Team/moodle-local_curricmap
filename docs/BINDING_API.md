# Binding API cookbook

How an external tool maps Moodle locations to Sofia curriculum nodes through
the `curricmap_mapping` web service: discovering both sides of an address,
creating and removing bindings at every grain, and attaching study
resources. Everything here is REST (`/webservice/rest/server.php`) with a
token authorised for the **Curriculum mapping API** service
(`curricmap_mapping`, restricted users). All examples use:

    BASE="https://<moodle>/webservice/rest/server.php"
    ARGS="wstoken=$TOKEN&moodlewsrestformat=json"

Capabilities: curriculum reads need `local/curricmap:viewstaffmeta`; binding
and resource writes need `local/curricmap:managebindings` (system context
for central-scope writes). The ws user needs no course enrolments.

## 1. The model in one paragraph

A **binding** says "this Moodle location teaches this curriculum node". It
is an address (category / course / section / activity / sub-activity) plus a
**composed node key**, a **relation** verb and a **scope**. Bindings are
year-pinned: the composed key `slug_YYYY_YY_uuid` (e.g.
`vet-med_2025_26_<uuid>`) names one programme year's node, so academic years
never blur. Any node role can be bound — strand, unit, session, outcome,
assessment alike. Two things that are NOT bindings: grouplabels (term, week,
outcome bucket — attributes of nodes, not bindable targets) and
tiny_curricmap chips (in-content presentation references, stored in the
content HTML, not in the binding table).

## 2. Discovering the curriculum side (which node?)

| Function | Use |
|---|---|
| `local_curricmap_get_programmes` | Enumerate programme years: `[{id, slug, versionlabel}]`. |
| `local_curricmap_get_children` | Walk the graph one level at a time from any node. |
| `local_curricmap_search` | Ranked, synonym-aware title search, lockable to a subtree. |
| `local_curricmap_get_nodes` / `get_edges` | Bulk export of a programme's full graph (pageable; ~10k nodes per call). External matchers start here. |

Every function returns composed keys ready to use as `nodeuuid`.

    curl -s "$BASE" --data "$ARGS&wsfunction=local_curricmap_get_programmes&courseid=1"

## 3. Discovering the Moodle side (which address?)

The service bundles three core functions so ONE token can build addresses
without the mobile service:

| Function | Gives you |
|---|---|
| `core_course_get_categories` | `categoryid` values. |
| `core_course_get_courses` | `courseid` values (+ idnumber, names). |
| `core_course_get_contents` | A course's **sections** (`id` = sectionid) and each section's **modules** (`id` = cmid, `modname`, `instance`). |

Sub-activities (book chapters) take one extra step:
`core_course_get_contents` returns each book module with a `contents` array
whose entries carry `fileurl`s of the form
`.../mod_book/chapter/<chapterid>/index.html` — parse the **chapterid** from
that path (the technique learning_tools_content_api uses). There is no
separate chapter-listing function in the service.

    curl -s "$BASE" --data "$ARGS&wsfunction=core_course_get_contents&courseid=1234"

## 4. Creating bindings — one example per grain

Function: `local_curricmap_bind`. Idempotent: re-binding an existing
(address, node, relation) is safe. Unset address parts stay `0` / empty.

Parameters: `nodeuuid`, `categoryid`, `courseid`, `sectionid`, `cmid`,
`component`, `subitemid`, `relation` (default `related`), `scope`
(`central` or `course`), `sortorder`.

**Whole course** (the central match — what course_mapping.php creates):

    curl -s "$BASE" --data "$ARGS&wsfunction=local_curricmap_bind\
    &nodeuuid=vet-med_2025_26_<uuid>&courseid=1234&relation=anchor&scope=central"

**Section** → e.g. a strand or unit:

    curl -s "$BASE" --data "$ARGS&wsfunction=local_curricmap_bind\
    &nodeuuid=<strand-composed-key>&courseid=1234&sectionid=567\
    &relation=anchor&scope=central"

**Activity** (page, label — a "text area" is its label/page cm — quiz, ...)
→ e.g. a session or outcome:

    curl -s "$BASE" --data "$ARGS&wsfunction=local_curricmap_bind\
    &nodeuuid=<session-composed-key>&courseid=1234&cmid=8901\
    &relation=anchor&scope=central"

**Book chapter** → the finest grain there is:

    curl -s "$BASE" --data "$ARGS&wsfunction=local_curricmap_bind\
    &nodeuuid=<outcome-composed-key>&courseid=1234&cmid=8901\
    &component=mod_book&subitemid=<chapterid>&relation=anchor&scope=central"

**Category** (rare; central admins only): `categoryid` alone.

Relations in use: `anchor` = the mapping pages' "this teaches that";
`related` = additional/extra mappings (mappings.php). Scope: `central` =
site-admin-managed, locked for course staff; `course` = editable by any
managebindings holder in the course.

## 5. Reading and removing

| Function | Semantics |
|---|---|
| `local_curricmap_list_bindings` | By `courseid` (course context) or by `nodeuuid` (system context) — all grains, chapters included. |
| `local_curricmap_resolve` | "What applies HERE?" — pass an address, get its bindings deepest-first (chapter beats activity beats section beats course). |
| `local_curricmap_unbind` | Delete by binding id (from list/resolve/bind results). |

## 6. Study resources — yes, but they attach to NODES

The service carries a full resource write surface: `add_resource`,
`delete_resource`, `set_resource_visibility`, `list_resources`,
`list_resource_types`. The model is deliberately different from bindings:
a resource ("is supported by this material") attaches to a **curriculum
node**, not to a Moodle address — it then appears everywhere that node is
displayed. `courseid=0` makes it institutional; a courseid scopes it to one
course. This is the intended write path for external feeds such as lecture
recordings: bind the node to its Moodle location once, then attach the
recording to the node.

    curl -s "$BASE" --data "$ARGS&wsfunction=local_curricmap_add_resource\
    &nodeuuid=<session-composed-key>&type=Panopto&label=<label>\
    &url=https://<panopto-viewer-url>&courseid=0"

Gate: every resource function (reads included) returns nothing / refuses
while the **Support resource linking** master setting is off.

## 7. Caveats and sharp edges

- **No fallback discovery for grouplabels**: to target "everything in Term
  2" you bind the nodes in Term 2 individually (grouplabels come from
  `get_nodes` rows, so an external tool can select by them).
- **Year-pinning**: rollover to a new academic year means new composed keys
  — bindings never carry across years implicitly.
- **Contexts**: category-grain and node-mode calls validate at system
  context; course-grain calls at course context. A token whose user only
  has course-level capability cannot write central scope.
- **The pytest contract suite** (`moodle_mapping_api_test`) exercises this
  surface against a live site — run it after each deploy. It does not yet
  cover chapter-grain binding; the plugin's own PHPUnit does.
- Sofia's own API is never touched by any of this: the plugin's mirror is
  the source, so external tools spend no Sofia API quota (60 requests/hour).
