# Test fixtures — provenance

Recorded Sofia API payloads from the RVC test instance (`rvc-vetmed-test.sofiasrv.net`,
programme `vet-med`, BVetMed Year 1), captured July 2026 during the change-control
exercise documented in the `sofia_api_explorer` umbrella repo
(`CHANGE_CONTROL_TESTING.md`). This repo is private; the fixtures contain real curriculum
text and staff names and must not be republished.

Three consecutive revisions with human-verified deltas between them:

| Revision | Hash | Files |
|---|---|---|
| A | `05f4c82da705350f369e8782cf8a4db55adc87f0` | `vetmed_a_nodes.json`, `vetmed_a_metadata.json` |
| B | `10fed6689f116b04977dfc8baa64e5f06aed67f3` | `vetmed_b_nodes.json`, `vetmed_b_metadata.json` |
| C | `12ac3c30e3489c886a5c0d12606757b8770d3893` | `vetmed_c_nodes.json`, `vetmed_c_metadata.json` |

Compare API payloads: `compare_a_to_b.json` (8 node changes: adds, removes incl.
remove-with-children, tag/doc/text edits, schema-field add), `compare_b_to_c.json`
(6 node changes: move, remove, group add, schema-field removal). Both are verified
consistent with independent snapshot diffs — they are the golden masters for the sync
engine's change-control tests (M4/M5).

Nodes payloads were fetched with `?coalesce&connection-sort&tag-format=object&url` —
the same options the sync engine uses except `tag-format` (sync uses key-only; the
object format here includes display names, a superset).

Reference facts for assertions (revision A):

- 1,497 nodes incl. the root; 1,496 stored (root skipped)
- roles: year 1, strand 14, strandoutcome 79, session 331, sessionoutcome 1,066,
  assessment 2, group 1, other 2 (Test Unit `U1`, Test Outcome `O1` under an assessment)
- 3,786 `implements` connection values; 5 `event-outcome`
- Locomotor strand (`UG1-LOCO`, uuid `15629971-00d5-428a-944e-e94142c86088`):
  27 children — 3 strand outcomes, 23 events, 1 assessment; every event has an empty
  grouping label

`natural_sort_vectors.json` is a copy of `test_vectors/natural_sort.json` from the
umbrella repo — update both together.
