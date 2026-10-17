---

description: "Task list for the Glow Daily Article Importer"
---

# Tasks: Glow Daily Article Importer

**Input**: Design documents from `specs/001-glow-article-importer/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/cli-import.md, contracts/block-markup.md, quickstart.md

**Tests**: The plan (research R8) includes a test runner with no framework, run with `wp eval-file`. It covers snapshot and round-trip tests for the converter. These test tasks are included. The spec doesn't ask for TDD, so the tests are written alongside each converter rather than strictly first.

**Organization**: Tasks are grouped by user story. Every path below is relative to the repo root. All PHP lives in `plugin/glow-migration/` under `namespace GlowMigration;`, follows WordPress coding standards (tabs, `snake_case` methods, consistent array syntax), and starts each file with `defined( 'ABSPATH' ) || exit;`.

**Scope guard (FR-026)**: No task may read the legacy `author` or `category` fields, or call `wp_insert_user`, `wp_insert_term`, `wp_set_post_terms`, `wp_set_post_categories`, `media_sideload_image`, `media_handle_sideload` or `wp_insert_attachment`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: The user story the task belongs to (US1–US4 from spec.md)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Plugin skeleton and loading.

- [X] T001 Create the directories `plugin/glow-migration/includes/`, `plugin/glow-migration/tests/` and `plugin/glow-migration/tests/snapshots/`.
- [X] T002 Update `plugin/glow-migration/glow-migration.php`:
  - Keep the existing plugin header and the `defined( 'ABSPATH' ) || exit;` line.
  - Define `GLOW_MIGRATION_DIR` (`__DIR__`) and `GlowMigration\CONVERTER_VERSION` (`'1'`).
  - `require_once` these six files from `includes/`: `class-block-markup.php`, `class-import-report.php`, `class-rte-converter.php`, `class-grid-converter.php`, `class-importer.php`, `class-import-command.php`.
  - Inside `if ( defined( 'WP_CLI' ) && WP_CLI )`, call `\WP_CLI::add_command( 'glow', GlowMigration\Import_Command::class )`.
  - Create each of the six files with an empty class stub (`Block_Markup`, `Import_Report`, `Rte_Converter`, `Grid_Converter`, `Importer`, `Import_Command`) so the plugin loads without fatal errors.
- [X] T003 Run `npx wp-env start` and then `npx wp-env run cli wp plugin list`. Confirm `glow-migration` is active and there's no PHP error. Then record the baseline counts for SC-010 from quickstart.md "Prerequisites": users, categories, tags, attachments.
  - **Baseline recorded on 2026-10-14**: users=1, categories=1 (Uncategorized), tags=0, attachments=0. Posts: 1 ("Hello world!", ID 1), plus 1 auto-draft. Environment: WordPress 7.1.3, PHP 8.4.26, site timezone `+00:00`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The block markup helpers, the report object and the test runner, which every story uses.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T004 [P] Implement `GlowMigration\Block_Markup` in `plugin/glow-migration/includes/class-block-markup.php` as static methods that return a string for each block. Each must produce exactly the markup in `specs/001-glow-article-importer/contracts/block-markup.md`.
  - Use `get_comment_delimited_block_content( $name, $attrs, "\n" . $html . "\n" )` for the delimiters.
  - Methods:
    - `paragraph( string $inner_html )` → `<p>…</p>`.
    - `heading( string $inner_html, int $level )`:
      - Omit the `level` attribute when it's 2.
      - Always add `class="wp-block-heading"`.
    - `list_block( array $item_blocks, bool $ordered )`:
      - `<ul class="wp-block-list">` or `<ol class="wp-block-list">`, with the item blocks joined with `''`.
      - Add `{"ordered":true}` only when ordered.
    - `list_item( string $inner_html, string $nested_list = '' )` → `<li>inner + nested</li>`.
    - `pullquote( string $text, string $citation )`:
      - Escape both with `esc_html`.
      - Omit `<cite>` when the citation is empty.
    - `image( string $src, string $alt, string $caption )`:
      - Escape with `esc_url` / `esc_attr` / `esc_html`.
      - Always output `alt`, even when empty.
      - Omit `<figcaption class="wp-element-caption">` when the caption is empty.
      - **No `id` attribute and no `wp-image-N` class** (FR-026).
      - Self-close with `/>`.
    - `columns( array $column_blocks )` → the column blocks joined with `"\n\n"` inside `<div class="wp-block-columns">`.
    - `column( string $inner_blocks, string $width )` → attribute `{"width":"33.33%"}` and `<div class="wp-block-column" style="flex-basis:33.33%">`.
    - `html( string $raw )`.
    - `join( array $blocks )` → filters out empty strings and joins with `"\n\n"`.
- [X] T005 [P] Implement `GlowMigration\Import_Report` in `plugin/glow-migration/includes/class-import-report.php`:
  - Counters `created`, `updated`, `unchanged`, `failed`. These are the only outcomes (data-model.md §5); don't add "skipped".
  - `record( int $line, ?int $legacy_id, ?int $post_id, string $outcome, string $title )`.
  - `warn( int $line, ?int $legacy_id, string $code, string $message )`. `code` must be one of `unrecognised_control`, `empty_body`, `unusual_row_layout`, `media_missing_image`, `quote_missing_text`, `html_fallback`, `duplicate_match`.
  - `add_unrecognised( string $alias, int $legacy_id )`, which keeps a map of alias → `{ count, legacy_ids[] }` (data-model.md §6).
  - Getters for `counts()`, `rows()`, `warnings()` and `unrecognised()`, plus `has_failures()`.
- [X] T006 [P] Create the framework-free runner `plugin/glow-migration/tests/run.php`, executed with `wp eval-file`:
  - Small helpers `assert_same( $expected, $actual, $label )` and `assert_true( $cond, $label )` that collect passes and failures.
  - A `snapshot( $name, $actual )` helper that compares against `tests/snapshots/{$name}.html`. If `getenv( 'GLOW_UPDATE_SNAPSHOTS' ) === '1'`, it writes the file instead.
  - A `roundtrip( $html, $label )` helper asserting `serialize_blocks( parse_blocks( $html ) ) === $html`.
  - Print `PASS` or `FAIL` per assertion and a total, then `exit( $failures ? 1 : 0 )`.
  - Add a `Block_Markup` section that compares each of these with the exact strings in contracts/block-markup.md:
    - a paragraph, an h2 and an h3;
    - a pullquote, with and without a citation;
    - an image, with and without a caption (assert it doesn't contain `wp-image-`);
    - a 4 + 8 columns block.
- [X] T007 Run `npx wp-env run cli wp eval-file wp-content/plugins/glow-migration/tests/run.php` and fix `plugin/glow-migration/includes/class-block-markup.php` until every Phase 2 assertion passes.

**Checkpoint**: Block helpers are verified, and stories can now build on them.

---

## Phase 3: User Story 1 - Import legacy articles as editable posts (Priority: P1) 🎯 MVP

**Goal**: `wp glow import <file>` creates one post per article. Each post gets a native, valid block body (text, quotes, images, columns), the title, excerpt and slug, and its legacy references.

**Independent Test**: On an empty site, run `npx wp-env run cli wp glow import wp-content/glow-fixtures/articles.ndjson --user=admin`. Then check:
- There are 5 posts.
- The editor console check in quickstart.md §4 shows "All blocks valid" for every post.
- Article 10231 has the slug `spring-skincare-reset` and the meta `_glow_legacy_id` = 10231 (quickstart.md §2).

### Implementation for User Story 1

- [X] T008 [P] [US1] Implement `GlowMigration\Rte_Converter::convert( string $html ): array` in `plugin/glow-migration/includes/class-rte-converter.php`, following research R2. It returns `[ 'content' => string, 'notices' => array ]`.
  - **Loading:** `DOMDocument::loadHTML( '<?xml encoding="utf-8"?><div id="glow-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED )`. Walk the children of `#glow-root`.
  - **Top-level elements:**
    - `p` → `Block_Markup::paragraph`. Skip the paragraph when `trim( str_replace( "\u{00A0}", ' ', $text ) ) === ''` (FR-015).
    - `h1`–`h6` → `heading`, with `h1` mapped to level 2.
    - `ul` / `ol` → `list_block`, with one `list_item` per `li`. A nested `ul` or `ol` inside an `li` becomes the `$nested_list` argument.
    - `div` / `section` / `article` → recurse into the children.
    - Consecutive top-level text and inline nodes → buffered into one paragraph. Skip it if it's whitespace only.
    - Any other element → `Block_Markup::html( original outer HTML )`, plus an `html_fallback` notice.
  - **Inline serialiser** (`private function inline( \DOMNode $node ): string`):
    - Text → `htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' )`.
    - Keep `strong`, `em`, `br` (written as `<br>`), `sup`, `sub` and `code`. Convert `b` → `strong` and `i` → `em`.
    - `a` keeps only `href`, `target` and `rel`, each escaped with `esc_attr`. Keep `href` values like `{localLink:10299}` unchanged, and **don't** pass them through `esc_url`.
    - Any other element (including `span`) is unwrapped, keeping its children.
  - Return `content` as `Block_Markup::join( $blocks )`.
- [X] T009 [P] [US1] Add `Rte_Converter` cases to `plugin/glow-migration/tests/run.php`:
  - `<p>&nbsp;</p>` → content `''`.
  - `<p>a <span style="color:#ff0000">b</span></p>` → one paragraph `a b`, with no `span`.
  - `<h2>x</h2><p>y</p>` → a heading followed by a paragraph.
  - `<ul><li>a</li><li>b</li></ul>` → a list with 2 list items.
  - A link with `href="{localLink:10299}"` → the href is kept unchanged.
  - `Fresh &amp; citrusy` → stays `Fresh &amp; citrusy`, with no double encoding.
  - Run `roundtrip()` on every output.
- [X] T010 [US1] Implement `GlowMigration\Grid_Converter::convert( string $article_body_json ): array` in `plugin/glow-migration/includes/class-grid-converter.php`. It returns `[ 'content' => string, 'notices' => array of [ code, message ], 'dropped' => string[] ]`.
  - **Decoding:**
    - Use `json_decode( $json, true, 512, JSON_THROW_ON_ERROR )`. Let a `JsonException` propagate.
    - An empty string, or no `sections` → content `''` plus an `empty_body` notice.
  - **Rows:**
    - 1 area → output its blocks directly (FR-013).
    - 2 or more areas → `Block_Markup::columns`, with each area as `column( inner, width )` (FR-012). Format the width as `round( grid / 12 * 100, 2 )` with trailing zeros removed, plus `%`: 6 gives `'50%'` and 4 gives `'33.33%'`.
    - Grids that don't add up to 12, or more than 2 areas → add an `unusual_row_layout` notice.
    - Every area empty → output nothing for that row.
  - **Control dispatch** on `$control['editor']['alias'] ?? ''`:
    - `rte` → `Rte_Converter`, merging its notices.
    - `quote` → `pullquote( value.text, value.citation ?? '' )`. Empty text → skip it, with a `quote_missing_text` notice.
    - `media` → `image( 'https://www.glowdaily.example' . value.image, value.altText ?? '', value.caption ?? '' )`. Empty `image` → skip it, with a `media_missing_image` notice. Never read `value.id` (FR-026).
    - Default → skip the control. US4 adds reporting in T024.
- [X] T011 [US1] Add `Grid_Converter` snapshot tests to `plugin/glow-migration/tests/run.php`:
  - Read `ABSPATH . 'wp-content/glow-fixtures/articles.ndjson'` and `articles-v2.ndjson`.
  - For each record, call `snapshot( "article-{$id}" . ( $is_v2 ? '-v2' : '' ), $content )` and `roundtrip( $content )`.
  - Assert that 10232's content contains `"width":"33.33%"` and `"width":"66.67%"`.
  - Assert that 10233's contains `"width":"50%"` twice.
  - Assert that no content contains `wp-image-` (FR-026).
  - Generate the snapshots once with `GLOW_UPDATE_SNAPSHOTS=1`. Then **review each file `plugin/glow-migration/tests/snapshots/article-*.html` by hand against contracts/block-markup.md** before you commit it.
- [X] T012 [US1] Implement the US1 version of `GlowMigration\Importer` in `plugin/glow-migration/includes/class-importer.php`. This version only creates posts.
  - Constructor: `( Grid_Converter $converter, Import_Report $report, bool $dry_run )`.
  - `import_line( string $line, int $line_no ): void`:
    - Decode the line with `json_decode` and `JSON_THROW_ON_ERROR`.
    - **Validate** (data-model.md §1). Each failure → `failed`:
      - `id`: "Must be a positive integer… Missing → record **failed**."
      - `properties.title`: "Empty → record **failed**."
      - `createDate`: "Must parse as a date. Unparseable → record **failed**."
    - Convert `properties.articleBody` and forward each notice to `$report->warn()`.
    - **Build the post array:**
      - `post_type` `'post'`.
      - `post_title` from `properties.title`.
      - `post_excerpt` from `properties.excerpt ?? ''`.
      - `post_name`: `sanitize_title( urlName )`, falling back to `sanitize_title( title )` (FR-006).
      - `post_content`: the converted content.
      - Don't set `post_author`, `post_category` or `tags_input` (FR-026).
    - Save: `wp_insert_post( wp_slash( $postarr ), true )`. A `WP_Error` → `failed`.
    - Save meta (FR-007): `_glow_legacy_id` (int), `_glow_legacy_path` (`path`), `_glow_legacy_update_date` (`updateDate`, as given).
    - Record `created`.
  - Wrap the whole body in `try { } catch ( \Throwable $e )` and record `failed` with `$e->getMessage()` (FR-002).
- [X] T013 [US1] Implement `GlowMigration\Import_Command` in `plugin/glow-migration/includes/class-import-command.php`, following contracts/cli-import.md:
  - **Command:** `public function import( $args, $assoc_args )`, with a docblock synopsis for `<file>` and `[--dry-run]`.
  - **Start-up:**
    - If the file isn't readable → `\WP_CLI::error()` before doing anything else.
    - Call `kses_remove_filters()` (research R4).
  - **Loop:**
    - Read with `new \SplFileObject( $file )`, skip blank lines, and pass each line to `Importer::import_line`.
    - Print one progress line per record, e.g. `Created post 12 for legacy 10231 "…"`.
    - Print warnings as `\WP_CLI::warning( "line {$n} legacy {$id}: {$msg}" )`.
  - **End:**
    - Print the summary with `\WP_CLI\Utils\format_items( 'table', $rows, [ 'outcome', 'count' ] )`, with one row each for created, updated, unchanged and failed.
    - Then `\WP_CLI::success( … )`. If `$report->has_failures()`, call `\WP_CLI::halt( 1 )` after the summary instead.
- [X] T014 [US1] Run `npx wp-env run cli wp glow import wp-content/glow-fixtures/articles.ndjson --user=admin`:
  - Confirm 5 created.
  - Run the slug and meta checks in quickstart.md §2 for 10231.
  - Run the console check in quickstart.md §4 on all 5 posts.
  - Fix any invalid block in `plugin/glow-migration/includes/class-block-markup.php` or `class-rte-converter.php`, and regenerate the snapshots.
  - **Verified 2026-10-14**: posts 6–10 created, and the content of each matches its snapshot byte for byte. The user opened all five in the editor: no invalid-block warnings. Images point at the legacy URL, which doesn't resolve (`.example` is a reserved domain), so they show as broken. That's expected (FR-011, FR-026).

**Checkpoint**: The MVP works. Posts are created with valid native blocks, slugs and legacy references, but reruns still duplicate them and dates are still the import time.

---

## Phase 4: User Story 2 - Rerun safely and pick up later exports (Priority: P1)

**Goal**: Reruns never create duplicates. v2 gives 1 created, 1 updated and 4 unchanged. A dry run writes nothing.

**Independent Test**: Run quickstart.md §3:
- `articles.ndjson` twice: the second run gives 5 unchanged.
- A dry run of v2: reports 1 created, 1 updated and 4 unchanged, and the post count stays at 5.
- v2 for real: 1 created (10236), 1 updated (10232), 4 unchanged.
- v2 again: 6 unchanged.
- 10232 keeps the same post ID.

### Implementation for User Story 2

- [X] T015 [US2] Add `find_existing( int $legacy_id ): ?int` to `plugin/glow-migration/includes/class-importer.php`:
  - Call `get_posts()` with these arguments:
    ```php
    [
        'post_type'        => 'post',
        'post_status'      => array_merge( array_keys( get_post_stati() ), [ 'trash' ] ),
        'meta_key'         => '_glow_legacy_id',
        'meta_value'       => $legacy_id,
        'fields'           => 'ids',
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'posts_per_page'   => -1,
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]
    ```
  - If there's more than one result → `duplicate_match` warning, and use the first.
- [X] T016 [US2] Add `source_hash( array $record ): string` to `plugin/glow-migration/includes/class-importer.php`:
  - Return `hash( 'sha256', wp_json_encode( [ CONVERTER_VERSION, title, excerpt, urlName, published, createDate, updateDate, articleBody ] ) )` (research R3).
  - Because `updateDate` is included, *any* different `updateDate` counts as a change (FR-020).
  - Save the result as `_glow_import_hash` on every create and update.
- [X] T017 [US2] Change `import_line` in `plugin/glow-migration/includes/class-importer.php` to follow the state machine in data-model.md §5:
  - No existing post → create (FR-021).
  - Existing post where `get_post_meta( $id, '_glow_import_hash', true ) === $hash` → record `unchanged`, no write (FR-019).
  - Otherwise → `wp_update_post( wp_slash( $postarr + [ 'ID' => $id ] ), true )`, refresh all the meta, and record `updated` (FR-020).
  - Never delete or unpublish posts (FR-022).
- [X] T018 [US2] Add dry-run handling to `plugin/glow-migration/includes/class-importer.php` and `plugin/glow-migration/includes/class-import-command.php` (FR-004):
  - When `$dry_run` is true, compute the outcome as normal but skip `wp_insert_post`, `wp_update_post` and `update_post_meta`.
  - Prefix every output line with `[dry-run]`.
  - The final message is `Success: Dry run complete; no changes written.`
- [X] T019 [US2] Run quickstart.md §3 end to end, including the dry-run step (US2 scenario 5), and confirm the exact counts. Confirm the post count after each step with `npx wp-env run cli wp post list --post_type=post --post_status=any --format=count`.
  - **Verified 2026-10-14**:
    - Rerunning `articles.ndjson` gave 5 unchanged. The first rerun gave 5 updated, because posts 6–10 were created before fingerprints existed; they were matched by legacy ID and updated in place, with no duplicates.
    - A dry run of v2 reported 1 created, 1 updated and 4 unchanged, and changed nothing: post 7's title stayed the same and the count stayed at 6.
    - The real v2 import gave 1 created (post 17, for 10236), 1 updated (post 7, same ID) and 4 unchanged.
    - Running v2 again gave 6 unchanged.
    - Final count: 7 posts (6 imported plus "Hello world!"). Every imported post matches its v2 snapshot.

**Checkpoint**: Reruns are safe, v2 updates work, and the dry run is verified.

---

## Phase 5: User Story 3 - Keep original publish dates and status (Priority: P2)

**Goal**: `post_date` and `post_date_gmt` equal `createDate`. Drafts stay drafts, and updates keep the date.

**Independent Test**: Run quickstart.md §2's date check:
- 10231 has `post_date_gmt` `2021-09-02 22:15:00`.
- 10234 is a `draft` and returns 404 to logged-out visitors.

### Implementation for User Story 3

- [ ] T020 [P] [US3] Add `dates_for( string $iso ): array` to `plugin/glow-migration/includes/class-importer.php`:
  - `$dt = new \DateTimeImmutable( $iso )`.
  - Return:
    ```php
    [
        'post_date'     => $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
        'post_date_gmt' => $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
    ]
    ```
  - An invalid date throws, so the record fails.
- [ ] T021 [US3] In the post array in `plugin/glow-migration/includes/class-importer.php`:
  - Merge in `dates_for( createDate )` (FR-023).
  - Set `post_status` to `( true === ( $record['published'] ?? false ) ) ? 'publish' : 'draft'`. As data-model.md §1 says, "A missing value counts as `false`" (FR-025).
  - Add `'edit_date' => true` on updates (research R4), so draft dates aren't reset (FR-024).
- [ ] T022 [US3] Add unit asserts for `dates_for` to `plugin/glow-migration/tests/run.php`:
  - `2021-09-03T08:15:00+10:00` → GMT `2021-09-02 22:15:00`.
  - `2022-03-14T10:00:00+11:00` (10232, daylight saving) → GMT `2022-03-13 23:00:00`.
- [ ] T023 [US3] Bump `CONVERTER_VERSION` to `'2'` in `plugin/glow-migration/glow-migration.php`, so posts imported before dates were fixed get updated on the next run. Rerun the import, then run quickstart.md §2's `wp post list` date and status check and the logged-out 404 check for 10234 (quickstart.md §5).

**Checkpoint**: Dates and statuses are correct, and they survive reruns.

---

## Phase 6: User Story 4 - Unrecognised content never breaks a post (Priority: P2)

**Goal**: Unknown controls produce no output, are counted in the summary, and are stored on the post.

**Independent Test**: Import the fixtures, then check:
- The summary lists `wirewaxVideoId x1 legacy IDs: 10232` and `pinterestWidget x1 legacy IDs: 10233`.
- `npx wp-env run cli wp post list --post_type=post --s=pinterest --format=count` returns 0.
- Both posts are valid in the editor (quickstart.md §4) and look fine on the front end (quickstart.md §5).

### Implementation for User Story 4

- [ ] T024 [US4] Replace the default branch from T010 in `plugin/glow-migration/includes/class-grid-converter.php`:
  - Add the alias, or `'(missing)'` when it's empty, to `dropped[]`.
  - Add an `unrecognised_control` notice, and output nothing.
  - The other controls in the same area must still convert (FR-017).
- [ ] T025 [US4] In `plugin/glow-migration/includes/class-importer.php`:
  - For each dropped alias, call `$report->add_unrecognised( $alias, $legacy_id )` (FR-016).
  - Save `_glow_dropped_controls` (an array of unique aliases) on create and update. Delete the meta when the array is empty.
- [ ] T026 [US4] In `plugin/glow-migration/includes/class-import-command.php`, after the summary table, print the "Unrecognised controls (left out of content):" section in the format shown in contracts/cli-import.md, only when the tally isn't empty (SC-007).
- [ ] T027 [US4] Add asserts to `plugin/glow-migration/tests/run.php`:
  - The converted content of 10232 and 10233 contains none of `wirewax`, `8812345` or `pinterest.com` (SC-006).
  - `dropped` equals `['wirewaxVideoId']` for 10232 and `['pinterestWidget']` for 10233.
  - The other controls in the same areas are present: 10232's essence `rte` and 10233's `quote`.

  Then bump `CONVERTER_VERSION` to `'3'` in `plugin/glow-migration/glow-migration.php`, regenerate the snapshots, and rerun the import.

**Checkpoint**: All four stories work.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Edge cases, the scope check, documentation and the final check.

- [ ] T028 Run the corrupt-line test in quickstart.md §6. Confirm `failed 1` with the line number reported, 5 unchanged, and exit code 1. If not, fix `plugin/glow-migration/includes/class-import-command.php` or `plugin/glow-migration/includes/class-importer.php`.
- [ ] T029 Check the out-of-scope rule (FR-026, SC-010):
  - Rerun the four baseline count commands from quickstart.md "Prerequisites" and confirm they match the counts recorded in T003.
  - Run `grep -rnE "wp_insert_user|wp_insert_term|wp_set_post_terms|wp_set_post_categories|media_sideload_image|media_handle_sideload|wp_insert_attachment|\['author'\]|\['category'\]" plugin/glow-migration/` and confirm there are no matches.
- [ ] T030 [P] Rewrite the root `README.md` (FR-027, SC-011). Keep the original brief below a `---` separator, or move it to `BRIEF.md`. Add these 7 sections:
  1. **How to run**: the commands from quickstart.md, including `--dry-run` and the test runner.
  2. **Assumptions**: from spec.md's Assumptions, including that authors, categories and media files are out of scope.
  3. **Trade-offs and what I'd do next**:
     - moving from the custom runner to PHPUnit;
     - `Dom\HTMLDocument` on PHP 8.4;
     - batching and `wp_defer_term_counting` for large imports;
     - handling a post that's been edited in WordPress since import;
     - embed blocks for the retired video and Pinterest controls;
     - importing authors, categories and media.
  4. **Internal and legacy-domain links** (approach only): a second pass after import that maps legacy ID → post ID using `_glow_legacy_id`, and legacy path → permalink using `_glow_legacy_path`. It uses `WP_HTML_Tag_Processor` to rewrite `href` values and reports links it can't resolve.
  5. **Redirects from legacy URLs** (approach only): look up `_glow_legacy_path` → post on `template_redirect`, or export the mapping to the Redirection plugin or server rules. Use 301s, and normalise trailing slashes and case.
  6. **Import reporting** (approach only): JSON or CSV output of the run report, stored per run (for example as a custom post type or log file), with a client-facing summary of counts, dropped content and unresolved links.
  7. **How AI tools were used**: Spec Kit (specify → plan → tasks → implement) with Claude Code, and what was reviewed or changed by hand.
- [ ] T031 [P] Add PHPDoc to every public method in `plugin/glow-migration/includes/*.php`, and make sure no debug output is left in the code.
- [ ] T032 Run the full quickstart.md (§1–§7) on a fresh environment: `npx wp-env clean all && npx wp-env start`. Confirm every expected result, including the editor `isValid` check on all 6 posts after the v2 import and the README check in §7.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)** → **Foundational (Phase 2)** → user stories → **Polish (Phase 7)**.
- **US1 (Phase 3)** depends on Phase 2 and is the MVP.
- **US2 (Phase 4)** depends on US1's `Importer` and `Import_Command` (T012, T013).
- **US3 (Phase 5)** depends on US1's `Importer` (T012). In principle it can run in parallel with US2, but both edit `class-importer.php`, so in practice do them one after the other.
- **US4 (Phase 6)** depends on US1's `Grid_Converter` (T010) and `Import_Command` (T013). It's independent of US2 and US3.
- **Polish**: T029 needs the T003 baseline. T032 needs everything else.

### Within Each Story

- Converters come before the importer, the importer before the command, and the command before running the checks.
- Bump `CONVERTER_VERSION` whenever the output changes, so existing posts are re-converted (T023, T027).

### Parallel Opportunities

- Phase 2: T004, T005 and T006 are in different files and can run in parallel.
- US1: T008 and T009 can run in parallel with each other. T010 depends on T008.
- US3: T020 is a standalone helper and can be written while US2 is in progress.
- Polish: T030 (README) and T031 (PHPDoc) can run in parallel.

---

## Parallel Example: Foundational

```bash
Task: "T004 Implement Block_Markup in plugin/glow-migration/includes/class-block-markup.php"
Task: "T005 Implement Import_Report in plugin/glow-migration/includes/class-import-report.php"
Task: "T006 Create test runner in plugin/glow-migration/tests/run.php"
```

## Parallel Example: User Story 1

```bash
Task: "T008 Implement Rte_Converter in plugin/glow-migration/includes/class-rte-converter.php"
Task: "T009 Add Rte_Converter test cases in plugin/glow-migration/tests/run.php"
```

## Parallel Example: Polish

```bash
Task: "T030 Rewrite README.md"
Task: "T031 Add PHPDoc in plugin/glow-migration/includes/*.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phases 1 and 2: the skeleton, verified block helpers and the test runner.
2. Phase 3 (US1): import with valid blocks. **Stop and check in the editor.**
3. Commit. This is a demonstrable MVP.

### Incremental Delivery (fits the 3-hour budget)

1. Setup + Foundational (~30 min). Commit.
2. US1: converters and a basic import (~60 min). Commit.
3. US2: idempotency and dry run (~25 min). Commit.
4. US3: dates and status (~15 min). Commit.
5. US4: unrecognised controls (~15 min). Commit.
6. Polish: scope check, README and a fresh-environment check (~35 min). Commit.

If time runs out, finish US2 before US3 and US4, because safe reruns are a hard requirement. Note anything unfinished in the README.

---

## Notes

- Commit after each phase. The brief asks for unsquashed history.
- The block markup must match contracts/block-markup.md exactly. The editor `isValid` check is the final word on whether markup is valid.
- Never pass `{localLink:…}` hrefs through `esc_url()`, because it would strip them. Use `esc_attr()`.
- Always `wp_slash()` data before passing it to `wp_insert_post` or `wp_update_post`.
- Run outcomes are only created, updated, unchanged and failed.
