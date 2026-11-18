# Agent instructions

## Assumptions and simplicity — Brian's standing rule

- Check every proposed assumption back with Brian before acting on it. State
  what is unknown and ask; do not silently choose an interpretation or treat
  silence as approval. Verified facts and Brian's explicit decisions are not
  assumptions.
- Keep solutions as simple as reasonably sensible for the agreed task. Inspect
  existing project examples and follow established patterns. Do not add
  speculative features, abstractions, validation frameworks or migration
  machinery without checking with Brian first.
- If requirements or existing patterns conflict or are unclear, pause the
  affected decision and refer it back to Brian. Continue only work that does
  not depend on that unresolved assumption.

End responses with a concise summary of what is different, followed by actions
for Brian. State explicitly when nothing changed or no action is needed.

## Release and JavaScript builds — Brian, 2026-11-18

- Always increase `version.php` for a delivered change, including JavaScript-only
  changes, so Moodle detects the upgrade and refreshes its caches.
- Generate AMD builds with Moodle's own Grunt toolchain; do not hand-edit built
  files or replace the Moodle build with a standalone Babel transform.
- Use the playground's separate `docker-compose.tools.yml` build container with
  Moodle core mounted read-only and this development checkout mounted as the
  plugin. Run `eslint:amd rollup --root=local/curricmap`; `amd` also regenerates
  Moodle-root ignore/config files. Never use `--force` to bypass build failures.
- Do not manage the playground's Git submodules or change Moodle core. Brian's
  scripts handle those checkouts. If building needs a newer installed plugin,
  prepare source changes first, wait for Brian's push/pull, then build and copy
  generated files back to this checkout for the second push.
