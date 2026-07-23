# Course matching rules — reference and update runbook

The matching engine's rules are **data, not code**: they live in the
`matchingrules` admin setting (Site administration → Plugins → Curriculum map
→ Course matching settings) as a JSON object. Conventions can evolve — new
idnumber formats, renamed strands, new programmes — without a plugin release.

Shipped defaults live in `\local_curricmap\local\matcher::default_rules()`.
Invalid JSON in the setting falls back to the defaults entirely; valid JSON
overlays the defaults **per top-level key** (a JSON containing only
`{"minscore": 3}` keeps every other default).

**Before changing any rule, read `Documentation/MATCHING_KNOWLEDGEBASE.md`
in the umbrella repo** — the consolidated record of what predicts a correct
mapping (signal hierarchy, proven/disproven findings, measured estate facts).

The evidence base behind the current patterns is in the
`moodle_mapping_api_test` repo: `MATCHING_SIGNALS.md` (analysis of 78k
production module rows) and `matcher.py` + `matching_rules.json` (the offline
Python prototype — the rules lab).

---

## 1. Structure reference

```json
{
    "skip": ["^Temp_", "shell", "^catalyst_"],
    "minscore": 2,
    "mincontainment": 0.6,
    "skipsections": ["archive", "to be classified", "drop.?in", "..."],
    "synonyms": {"cvrs": "cardiovascular respiratory", "...": "..."},
    "aliases": [
        {"pattern": "BVETMED(?<n>[1-5])|UBVETMD|BVETMED",
         "slug": "vet-med", "node": "year\\s*{n}"}
    ]
}
```

### skip — course idnumber skip-list
Regexes matched **case-insensitively against the course idnumber**. A hit
takes the course out of matching entirely (status `skipped`; visible only
under "Show: all courses"). Serves shell templates, workspace slugs, junk.

### minscore — course-level fuzzy threshold
Minimum number of **shared whole words** between a course's tokens
(idnumber + shortname + fullname, lowercased, year-like tokens dropped) and a
candidate's tokens (programme display name + slug + year-node title) for a
fuzzy suggestion on the *course matching* page. Integer, default 2.

### mincontainment — content-level fuzzy threshold
For the *content mapping* page: the fraction (0–1) of a candidate node
title's words (stopwords dropped: and/of/the/a/an/in/to/for) that must appear
in the section/module name (after synonym expansion). Default 0.6 — so
"Endocrine" (1 word) needs its 1 word present; "Integrated and Applied
Anatomy" (3 significant words) needs 2 of 3.

### skipsections — housekeeping section names
Regexes matched case-insensitively against **section names**. A hit marks the
section "Housekeeping": it stays listed and manually mappable, but gets no
hints. Serves "To be archived", "Announcements", "Weekly Guidance", "Module
Books", "PebblePad", "LEARN Kit" and similar non-teaching sections.

### synonyms — local vocabulary → Sofia vocabulary
A flat map applied on the *content mapping* page before containment scoring.
Each key is a single lowercased word that appears in RVC section/module names;
its value is the space-separated Sofia words it should expand to. This bridges
the gap where local teaching vocabulary differs from Sofia strand titles:
`"cvrs": "cardiovascular respiratory"`, `"locomotion": "locomotor"`,
`"digestion": "alimentary"`. Without these, a section called "PAFF: Unit 8
(CVRS)" shares no words with the strand "Cardiovascular & Respiratory" and
scores zero. Expansion is additive — the original word stays too.

### aliases — course → programme + node
An **ordered** list; the first rule whose `pattern` matches wins. Each rule:

- **`pattern`** — a regex matched case-insensitively against the course's
  idnumber first, then shortname + fullname, then category name. May contain a
  named capture `(?<n>...)` (PHP `preg_*` syntax) whose value fills `{n}` in
  the node template — this is how one rule covers Years 1–5.
- **`slug`** — the Sofia programme slug this course belongs to. Must exist in
  the synced mirror or the course reports "no synced coverage".
- **`node`** — a regex matched against the year-node titles within the
  resolved programme + year, to pick which node. `{n}` is substituted from the
  named capture (e.g. `year\s*{n}` → `year\s*3` for a Year 3 course). An empty
  string means "the programme-year node itself, no further narrowing".

Named-capture syntax note: the **live setting and the plugin's PHP defaults
use `(?<n>...)`**; the offline Python prototype in `moodle_mapping_api_test`
uses `(?P<n>...)` (Python's spelling of the same thing). When porting a rule
between them, convert the `?<n>`/`?P<n>` spelling accordingly.

---

## 2. How the matcher applies these

**Course matching page** (`course_mapping.php`):
1. Parse an academic year from the idnumber (both dialects), then from names /
   category. No year → no proposal.
2. Walk `aliases` in order; first `pattern` hit sets the programme `slug` and,
   via `node`, the target year-node. Exact when both programme and year came
   from the idnumber; a suggestion when names/category were involved.
3. No alias hit → fall back to whole-word overlap ≥ `minscore` against the
   synced nodes for that year.

**Content mapping page** (`section_module_mapping.php`):
1. Sections score against the course's matched strands by containment; a hit
   ≥ `mincontainment` (after `synonyms` expansion) is a hint. `skipsections`
   names are skipped.
2. Modules score against the matched strand's sessions/outcomes the same way.

---

## 3. Update runbook (for an AI session, when courses or structures change)

When course naming conventions, strand names, or programme structures change,
the rules need refreshing. This is a self-contained task an AI model can do
from data — no plugin code changes, only the JSON in the setting.

### Inputs required

Provide these as CSV files (the same shapes already used in
`Documentation/` and the test-repo fixtures):

1. **Courses to match** — one row per course, columns:
   `fullname, shortname, idnumber, category`.
   (This is exactly `Documentation/2026_courses_for_mapping.csv`.) The
   idnumbers and names here are what `aliases` and the year regexes must parse.

2. **Courses + modules + sections** — one row per module, columns include:
   `crs_id, fullname, shortname, idnumber, cat_name, course_section_id,
   section_name, module_type, module_name`.
   (This is `moodle_course_and_modules_with_names_idnumbers_sections_v2.csv`.)
   The `section_name` values are what `skipsections`, `synonyms` and
   `mincontainment` are tuned against.

3. **The current Sofia node vocabulary** — the strand / year titles the rules
   must resolve to. Get this from the live mirror (the ws API
   `local_curricmap_get_programmes` / `_get_children`, or a DB read of
   `mdl_local_curricmap_node` where `role IN ('year','strand')`), or ask for a
   CSV of `slug, role, title`. Rules can only target titles that exist here.

### Method

1. **Year parsing** — confirm every course idnumber in input (1) yields the
   right academic year via the two idnumber regexes; if a new idnumber format
   appears, add/adjust a `year_patterns` regex (documented in the test repo's
   `matching_rules.json`; the plugin's equivalents are in
   `matcher::harmonised_year()`).
2. **Aliases** — for each distinct programme stem in the idnumbers, ensure an
   `aliases` rule maps it to the right `slug` and `node`. Cross-check the
   `slug` and node titles against input (3). Order matters: put specific
   patterns before general ones (e.g. `BVETMED(?<n>...)` before bare
   `BVETMED`).
3. **Synonyms** — diff the distinct `section_name` words in input (2) against
   the strand titles in input (3). Any section word that *means* a strand but
   shares no letters with its title (abbreviations, local names) needs a
   `synonyms` entry. Verify each proposed synonym raises containment on a real
   section name without creating false hits elsewhere.
4. **Skip lists** — scan input (2) for section names that are clearly
   non-teaching (archives, announcements, admin) and confirm `skipsections`
   covers them; scan idnumbers for shells/templates for `skip`.
5. **Validate before shipping** — run the changed JSON through the offline
   prototype (`moodle_mapping_api_test/matcher.py` against the same fixtures)
   or a dry read, and **surface the proposed rule changes to a human as a
   diff with the evidence** (which real course/section names each change
   affects) before pasting into the live setting. Admin-facing conventions are
   always confirmed, never shipped silently.

### Guardrails

- Rules are **suggestions only** — nothing auto-binds, so a wrong rule wastes
  clicks, it does not corrupt data. Still, prefer precision over reach.
- `slug` and node titles **must exist in the current mirror**; a rule pointing
  at a renamed/absent node silently yields "no coverage".
- Keep the plugin setting and the test-repo `matching_rules.json` in step
  (mind the `?<n>` vs `?P<n>` spelling), so the offline lab stays honest.
- Invalid JSON reverts the **whole** setting to shipped defaults — validate
  JSON before saving.