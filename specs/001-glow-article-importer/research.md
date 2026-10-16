# Research: Glow Daily Article Importer

**Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md) | **Date**: 2026-10-14

The Technical Context had no open "NEEDS CLARIFICATION" items, because the brief and `.wp-env.json` fix the platform. This file records the decisions that carry real risk, mostly how to produce block markup the editor accepts and how to make reruns safe.

---

## R1. How to generate block markup that passes editor validation

**Decision**: Build each block's inner HTML by hand so it matches exactly what that core block's `save()` function outputs. Wrap it with WordPress's own `get_comment_delimited_block_content()` / `serialize_block_attributes()` so the comment delimiters and attribute JSON are encoded correctly. Use only blocks with simple, stable `save()` output: `core/paragraph`, `core/heading`, `core/list` + `core/list-item`, `core/pullquote`, `core/image`, `core/columns` + `core/column`, and `core/html` as the fallback.

**Rationale**: The editor checks a block by re-running `save()` on the attributes it parsed and comparing the result with the stored HTML. Differences in whitespace and attribute order are tolerated; a different tag, class or style is not. The safest markup is therefore exactly what the editor would have saved itself. Reference markup, current core:

| Block | Saved markup |
| --- | --- |
| Paragraph | `<!-- wp:paragraph -->\n<p>…</p>\n<!-- /wp:paragraph -->` |
| Heading h2 | `<!-- wp:heading -->\n<h2 class="wp-block-heading">…</h2>\n<!-- /wp:heading -->` |
| Heading h3 | `<!-- wp:heading {"level":3} -->\n<h3 class="wp-block-heading">…</h3>\n<!-- /wp:heading -->` |
| List | `<!-- wp:list -->\n<ul class="wp-block-list"><!-- wp:list-item -->\n<li>…</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->` (ordered: `{"ordered":true}` + `<ol class="wp-block-list">`) |
| Pullquote | `<!-- wp:pullquote -->\n<figure class="wp-block-pullquote"><blockquote><p>…</p><cite>…</cite></blockquote></figure>\n<!-- /wp:pullquote -->` (no `<cite>` when the citation is empty) |
| Image | `<!-- wp:image -->\n<figure class="wp-block-image"><img src="…" alt="…"/><figcaption class="wp-element-caption">…</figcaption></figure>\n<!-- /wp:image -->` (no `<figcaption>` when the caption is empty) |
| Columns | `<!-- wp:columns -->\n<div class="wp-block-columns">…</div>\n<!-- /wp:columns -->` |
| Column | `<!-- wp:column {"width":"33.33%"} -->\n<div class="wp-block-column" style="flex-basis:33.33%">…</div>\n<!-- /wp:column -->` |

**Alternatives considered**:
- *Classic (freeform) content*: No validation errors, but it isn't "native blocks", and editors would have to convert every post by hand. Rejected.
- *Putting everything in `core/html` blocks*: Always valid, but editors can't edit the content visually. Rejected except as the fallback for unexpected block-level HTML.
- *Server-side rendering through `@wordpress/blocks` in Node*: Matches `save()` exactly, but adds a JS toolchain and a PHP→Node handoff to a 3-hour exercise. Rejected. We'll verify in the real editor instead (R7).
- *`core/quote` for legacy quotes*: The legacy control is described as a **pull quote**, and `core/pullquote` has a simpler `save()` (a single RichText value plus a citation). Chose pullquote.

---

## R2. Parsing and cleaning `rte` HTML

**Decision**: Parse with PHP's `DOMDocument`, loading a UTF-8 charset wrapper, `LIBXML_NOERROR`, and the fragment wrapped in a known container element. Walk the top-level nodes:

- `p` → paragraph. Skip it if its text, after trimming whitespace and `&nbsp;` (U+00A0), is empty (FR-015).
- `h1`–`h6` → heading. Keep the level, except `h1` becomes `h2`, because the post title is already the h1.
- `ul` / `ol` → list, with one list-item per `li`. A list nested inside an `li` becomes a nested `core/list` inside that list item.
- `div` and other wrapper elements → unwrap, then process their children.
- Text and inline nodes at the top level (not wrapped in a block element) → collected into a single paragraph.
- Any other block-level element (`table`, `blockquote`, `iframe`, …) → `core/html` with the original markup, plus a warning in the report.

Inline content is re-serialised through an allow-list: `strong` (and `b` → `strong`), `em` (and `i` → `em`), `a` (keeping only `href`, `target` and `rel`), `br`, `sup`, `sub` and `code`. Any other element, including `<span style>`, is unwrapped so its text survives. Text nodes are escaped with `htmlspecialchars( …, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' )`.

**Rationale**: `DOMDocument` is available in the wp-env PHP image and works on PHP 8.1+, the plugin header's minimum. An allow-list produces clean RichText content and gets rid of the stray `<span>` from article 10234. Re-serialising the HTML ourselves, rather than using `saveHTML`, lets us control escaping and avoids libxml adding `<html>` / `<body>` wrappers.

**Alternatives considered**:
- *`Dom\HTMLDocument` (PHP 8.4 HTML5 parser)*: Better at parsing, but it breaks the plugin's declared PHP 8.1 minimum. Noted as a future upgrade.
- *`WP_HTML_Processor`*: Native to WordPress, but its tree-building support for arbitrary fragments is still partial and it takes more code for nested lists. Rejected for now.
- *Regex / `wp_kses` only*: Can't reliably split HTML into blocks. Rejected.

---

## R3. Matching posts to legacy articles and detecting changes

**Decision**:
- Store the legacy ID in post meta `_glow_legacy_id`. Look posts up with `get_posts()` filtered on that meta key, across every post status including `trash`, returning IDs only. If there is more than one match, use the lowest ID and warn.
- Store `_glow_import_hash`: a SHA-256 hash of the JSON encoding of `[ CONVERTER_VERSION, title, excerpt, urlName, published, createDate, updateDate, articleBody ]`.
- Compare hashes. Same hash → **unchanged**, no write (FR-019). Different hash → **updated** (FR-020). No match → **created** (FR-021).

**Rationale**: The legacy ID is documented as unique and stable across exports. The hash includes `updateDate` as well as the content, so *any* difference in either counts as a change, which is exactly FR-020's "different `updateDate` or changed content". FR-019 and FR-020 therefore cover every case between them. Including `CONVERTER_VERSION` means that improving the converter and bumping the constant automatically re-converts every post on the next run. Trashed posts are included in the lookup so a trashed article is never imported a second time.

**Alternatives considered**:
- *Matching on slug or GUID*: Slugs can be edited or changed by uniqueness rules. GUIDs aren't designed to be lookup keys. Rejected.
- *Comparing `updateDate` only*: Misses converter changes and silent content changes. Rejected.
- *A custom tracking table*: More moving parts than post meta for this scale. Rejected.

---

## R4. Saving posts with the correct dates and status

**Decision**:
- Parse `createDate` with `new DateTimeImmutable( $createDate )`, which keeps the offset. Set `post_date_gmt` = its UTC value and `post_date` = the same instant in `wp_timezone()`, both formatted `Y-m-d H:i:s` (FR-023).
- `published: true` → `post_status = 'publish'`. Anything else → `'draft'` (FR-025).
- Always pass `post_date_gmt` explicitly, and on updates pass `edit_date => true`. Without these, WordPress leaves a draft's GMT date as `0000-00-00` and can reset draft dates on update (FR-024).
- Pass the post array through `wp_slash()`, because `wp_insert_post` unslashes its input.
- Call `kses_remove_filters()` for the duration of the run. WP-CLI runs with no user, so KSES filters are active by default and can strip attributes from our generated markup (for example the column `style`). The content comes from our own converter, which already applies the allow-list in R2. Pass `--user=admin` in the docs anyway, so revisions are attributed to a real user.

**Rationale**: These are the known pitfalls of `wp_insert_post` for migrations: slashing, draft GMT dates, `edit_date`, and KSES under the CLI. Handling them explicitly is what makes FR-023/FR-024 hold.

**Alternatives considered**: Writing to `$wpdb` directly would skip all these pitfalls, but it also skips caches, hooks and revisions, and it's harder to review. Rejected.

---

## R5. Unrecognised controls

**Decision**: Drop the control from the output. Record a warning in the run report (alias, legacy ID, count), and store the dropped aliases on the post as `_glow_dropped_controls`, so a later report or clean-up can find the affected posts without re-reading the export.

**Rationale**: This meets FR-016/FR-017 and SC-006: nothing odd is shown to readers or editors, and nothing is lost track of.

**Alternatives considered**:
- *Keeping the control as an HTML comment or in a hidden `core/html` block*: Gets lost the first time someone saves the post in the editor, and could leak into feeds or excerpts. Rejected.
- *Leaving a placeholder block for editors*: Shows "something odd" if the post is published as-is. Rejected.

---

## R6. Reading the file, reporting and exit codes

**Decision**: Read the file one line at a time with `SplFileObject`, skipping blank lines. Each record is processed in its own `try` / `catch`, so a failure is recorded against its line number and ID and the run continues (FR-002). At the end, print a summary table with `WP_CLI\Utils\format_items()`, using exactly the four outcomes from the spec: created / updated / unchanged / failed (FR-003). Then print the warnings, and exit non-zero if anything failed. `--dry-run` runs the full decode, convert and compare path but skips every write (FR-004, US2 scenario 5).

**Rationale**: Covers FR-002 through FR-004. A non-zero exit code makes failures visible in scripts and CI.

---

## R7. Keeping out-of-scope data out (FR-026, SC-010)

**Decision**: The importer never reads `author` or `category`, and never calls `wp_insert_user`, `wp_insert_term`, `wp_set_post_terms`, `media_sideload_image` or `wp_insert_attachment`. Posts get the default author (the `--user` running the command) and the default category, which WordPress assigns automatically. The `media` control's `id` is ignored, and the image block has no `id` attribute, so it isn't tied to any attachment.

**Rationale**: This makes SC-010 (zero new users, categories, tags or media items) true by construction. The quickstart checks it by comparing counts before and after a run.

---

## R8. How to test

**Decision**: Two layers.
1. **Converter snapshot tests**: a small self-contained runner (`tests/run.php`), executed with `wp eval-file` inside wp-env. It converts each fixture body and compares the result with checked-in expected markup. It also asserts `serialize_blocks( parse_blocks( $html ) ) === $html`, which catches badly formed delimiters and attributes.
2. **Editor validation check**: open each imported post in the editor and run a console snippet (in [quickstart.md](./quickstart.md)) that walks every block, including nested ones, and lists any with `isValid === false`. This is the only real check against the editor's `save()` output (FR-014, SC-002).

**Rationale**: `.wp-env.json` sets `testsEnvironment: false`, and setting up the WordPress PHPUnit scaffolding would use a large share of the 3-hour budget. A test runner that needs no framework, plus a real editor check, gives the most confidence for the time.

**Alternatives considered**:
- *Full PHPUnit with `wp-phpunit`*: The proper long-term approach and listed as a next step in the README. Too expensive for now.
- *Playwright end-to-end tests*: Out of budget.
