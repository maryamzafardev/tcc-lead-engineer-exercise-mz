# Implementation Plan: Glow Daily Article Importer

**Branch**: `001-glow-article-importer` | **Date**: 2026-10-14 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/001-glow-article-importer/spec.md`

## Summary

Add a `wp glow import <file> [--dry-run]` WP-CLI command to `plugin/glow-migration/`. It reads the legacy NDJSON export one line at a time and converts each article's grid JSON into native block markup. The markup is hand-built to match each core block's `save()` output: paragraph, heading, list, pullquote, image, and columns/column. Each article is then created or updated as a post. Posts are matched by `_glow_legacy_id` meta. A source hash stored on the post decides whether the article is created, updated or unchanged, which makes reruns safe. Dates come from `createDate`, with the timezone handled correctly, and legacy drafts stay drafts. Unrecognised controls are dropped from the content and reported. Authors, categories and media files are never touched: images point at their legacy URL. Design details are in [research.md](./research.md).

## Technical Context

**Language/Version**: PHP 8.1+ (the plugin header minimum). wp-env runs PHP 8.4.

**Primary Dependencies**: WordPress core (latest, `core: null` in `.wp-env.json`), WP-CLI (bundled in the wp-env `cli` container), PHP `dom`/`libxml`. No Composer packages.

**Storage**: WordPress posts and post meta (`_glow_legacy_id`, `_glow_legacy_path`, `_glow_legacy_update_date`, `_glow_import_hash`, `_glow_dropped_controls`). No users, terms or attachments are written (FR-026).

**Testing**: A self-contained snapshot and round-trip runner, run with `wp eval-file`, plus an in-editor `isValid` check ([quickstart.md](./quickstart.md) §4). No PHPUnit, because `testsEnvironment: false` (research R8).

**Target Platform**: The `@wordpress/env` 11.17 local Docker environment.

**Project Type**: WordPress plugin exposing a CLI command.

**Performance Goals**: The sample file (5–6 records) imports in under 30 seconds (SC-009). Records are streamed, so memory stays flat as the file grows.

**Constraints**: Output must pass block-editor validation. The import must be idempotent. It must never delete posts, publish drafts, or create users, terms or media items. The whole exercise is time-boxed to 3 hours.

**Scale/Scope**: 6 sample articles. The design should work up to thousands of articles without changes, apart from batching or object-cache tuning.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

`.specify/memory/constitution.md` is still the unfilled template, so there are no project principles to check against. **Gate: PASS (vacuous).** The same constraints from the brief are applied as a stand-in, and are satisfied by this design:

| Brief constraint | Status |
| --- | --- |
| Build in `plugin/glow-migration/` as a WP-CLI command | ✅ |
| Native, valid block markup (rte, quote, media, multi-column rows) | ✅ R1, R2, [contracts/block-markup.md](./contracts/block-markup.md) |
| Unrecognised content handled safely | ✅ R5 |
| No duplicates on rerun; v2 updates and adds | ✅ R3 |
| Keep original publish date | ✅ R4 |
| Authors, categories and media files out of scope | ✅ R7 (FR-026, SC-010) |
| Stay within roughly 3 hours: favour sound decisions over completeness | ✅ no framework-heavy tooling |

**Post-design re-check**: PASS. No violations, so Complexity Tracking is empty.

## Spec Coverage

| Spec items | Where they're handled |
| --- | --- |
| FR-001–FR-004, SC-009 | R6, [contracts/cli-import.md](./contracts/cli-import.md) |
| FR-005–FR-007, US1 scenario 9 | [data-model.md §4](./data-model.md) |
| FR-008–FR-015, SC-002 | R1, R2, [contracts/block-markup.md](./contracts/block-markup.md), R8 |
| FR-016–FR-017, SC-006, SC-007 | R5 |
| FR-018–FR-022, SC-003, SC-004, US2 scenario 5 | R3, R6, [data-model.md §5](./data-model.md) |
| FR-023–FR-025, SC-005, SC-008 | R4 |
| FR-026, SC-010 | R7 |
| FR-027, SC-011 | README task (quickstart §7) |

## Project Structure

### Documentation (this feature)

```text
specs/001-glow-article-importer/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── cli-import.md
│   └── block-markup.md
├── checklists/requirements.md
└── tasks.md             # created by /speckit-tasks
```

### Source Code (repository root)

```text
plugin/glow-migration/
├── glow-migration.php                 # Bootstrap: requires includes/, registers `glow` CLI command when WP_CLI
├── includes/
│   ├── class-import-command.php       # WP-CLI glue: args, file checks, progress, summary, exit code
│   ├── class-importer.php             # Record → post: validate, hash, match by legacy ID, insert/update, dates, meta
│   ├── class-grid-converter.php       # Grid JSON → block markup: sections/rows/areas/columns, control dispatch
│   ├── class-rte-converter.php        # rte HTML → paragraph/heading/list/html blocks, inline allow-list
│   ├── class-block-markup.php         # Small helpers that emit each block's exact save() markup
│   └── class-import-report.php        # Counts, warnings, unrecognised-control tally
└── tests/
    ├── run.php                        # Framework-free runner (wp eval-file)
    └── snapshots/                     # Expected markup per fixture article / converter case

README.md                              # Updated: how to run, assumptions, trade-offs, next steps, follow-up notes, AI usage
```

**Structure Decision**: A single plugin, with one class per responsibility under the `GlowMigration` namespace, loaded with `require_once` (no Composer autoloader, to keep setup at zero). The converters don't depend on the database. They only use WordPress's serialisation helpers, so they can be tested without importing anything. The repo's existing `plugin/` and `fixtures/` layout is unchanged.

## Key Design Points

1. **Markup fidelity over cleverness**: each block helper in `class-block-markup.php` writes the exact `save()` HTML. Comment delimiters and attributes are written with `get_comment_delimited_block_content()`. (R1)
2. **Cleaning rich text**: the `rte` HTML is parsed with a DOM parser, mapped to blocks, and inline tags are filtered through an allow-list, so stray `<span>` and `&nbsp;` disappear. (R2)
3. **Idempotency**: the hash covers `CONVERTER_VERSION`, `updateDate` and the content fields, so any difference counts as a change (FR-020). Reruns either skip or update. Bumping the version forces every post to be re-converted. (R3)
4. **`wp_insert_post` pitfalls handled**: `wp_slash`, explicit `post_date_gmt`, `edit_date`, and KSES disabled during the run. (R4)
5. **Strict scope**: the importer never reads `author` or `category`, and never creates users, terms or attachments. Images are plain URL references. (R7)
6. **Run continues past bad records**: a failure in one record never stops the rest, every outcome is counted (created / updated / unchanged / failed), and the exit code is non-zero if anything failed. (R6)

## Complexity Tracking

No violations.
