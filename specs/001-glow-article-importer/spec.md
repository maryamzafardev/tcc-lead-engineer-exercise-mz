# Feature Specification: Glow Daily Article Importer

**Feature Branch**: `001-glow-article-importer`

**Created**: 2026-10-13

**Status**: Draft

**Input**: User description: "The feature description is in the README.md file. I want you to work on the part 1 of the Readme and not the part 2." (README Part 1: Build a basic importer. Part 2, the pull request review, is out of scope.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Import legacy articles as editable posts (Priority: P1)

A migration engineer runs a single import command against a legacy export file. Every article in the file becomes a post on the new site, with its title, excerpt and body. The body is converted from the legacy grid layout into native editor content, so an editor can open any imported post and keep working on it without the editor reporting broken or invalid content. Text, pull quotes, images and side-by-side layouts all come across looking the way they did on the legacy site.

**Why this priority**: This is the core of the migration. Without it there is nothing to rerun, update or date-correct. It is also the part most visible to the client's editors, who will live in these posts after launch.

**Independent Test**: Start from an empty site, run the import against `fixtures/articles.ndjson`, then open each of the 5 resulting posts in the editor. Each opens with no invalid-content warnings, and the rendered post shows the expected text, quotes, images and column layouts.

**Acceptance Scenarios**:

1. **Given** an empty site, **When** the engineer imports `articles.ndjson`, **Then** 5 posts exist, one per legacy article, each with the legacy title and excerpt.
2. **Given** an article whose body contains rich text with headings, lists, bold, italic and links, **When** it is imported, **Then** each piece of content appears as the matching native element (paragraph, heading, list) with inline formatting and links intact.
3. **Given** an article containing a pull quote with text and a citation, **When** it is imported, **Then** the post shows a native pull quote with the same text and citation.
4. **Given** an article containing an image with alt text and a caption, **When** it is imported, **Then** the post shows a native image pointing at the image's legacy URL, with the same alt text and caption.
5. **Given** an image with empty alt text and an empty caption, **When** it is imported, **Then** the image is shown with no caption and no placeholder text where the caption would be.
6. **Given** a row with two areas (6 + 6 or 4 + 8), **When** it is imported, **Then** the post shows side-by-side native columns whose widths match the legacy proportions (50/50 or one-third/two-thirds), each holding its area's content in order.
7. **Given** a full-width row, **When** it is imported, **Then** its content appears directly in the post flow, not wrapped in a single column.
8. **Given** any imported post, **When** it is opened in the block editor, **Then** no block shows a validation or "unexpected content" warning.
9. **Given** an article with `urlName` `spring-skincare-reset`, legacy ID 10231 and path `/skincare/spring-skincare-reset/`, **When** it is imported, **Then** the post's URL slug is `spring-skincare-reset`, and the post records legacy ID 10231, that legacy path, and the article's `updateDate`.

---

### User Story 2 - Rerun safely and pick up later exports (Priority: P1)

The migration will be rehearsed many times before launch, and editors keep working in the legacy CMS in the meantime. The engineer needs to rerun the import as often as they like without creating duplicates, and to run it against a newer export so that changed articles are updated and new articles are added.

**Why this priority**: Migrations are run repeatedly. A non-repeatable import forces a database reset before every run and makes the final cut-over risky. The brief names this as a hard requirement.

**Independent Test**: Import `articles.ndjson` twice and confirm there are still 5 posts with unchanged content. Then import `articles-v2.ndjson` and confirm there are 6 posts, that article 10232 shows its new title and extra paragraph, and that article 10236 has been added.

**Acceptance Scenarios**:

1. **Given** `articles.ndjson` has already been imported, **When** the same file is imported again, **Then** no new posts are created and existing posts are left unchanged.
2. **Given** `articles.ndjson` has already been imported, **When** `articles-v2.ndjson` is imported, **Then** article 10232 is updated in place (same post, new title "Serum vs essence: what's the difference (and do you need both)?" and new body content), article 10236 is created, and the other 4 posts are left unchanged.
3. **Given** `articles-v2.ndjson` has been imported, **When** it is imported again, **Then** the post count stays at 6 and nothing changes.
4. **Given** any rerun, **When** the import finishes, **Then** the engineer can see how many articles were created, updated, unchanged and failed.
5. **Given** `articles.ndjson` has already been imported, **When** `articles-v2.ndjson` is imported in dry-run mode, **Then** the output reports 1 created, 1 updated and 4 unchanged, but the post count stays at 5 and no post's content changes.

---

### User Story 3 - Keep original publish dates and status (Priority: P2)

Readers, search engines and the client's archive pages depend on articles keeping their original dates. Each imported post must carry the article's original publish date, not the date the import ran. Articles that were drafts on the legacy site must not become publicly visible.

**Why this priority**: Wrong dates scramble archive order and feeds, and publishing a draft leaks unfinished content to readers. Both are visible to the client, but they build on a working import (Stories 1 and 2).

**Independent Test**: After importing, compare each post's publish date with the legacy `createDate`, and check that article 10234 (`published: false`) is not visible to logged-out visitors.

**Acceptance Scenarios**:

1. **Given** an article with `createDate` `2021-09-03T08:15:00+10:00`, **When** it is imported, **Then** the post's publish date is the same instant (3 Sept 2021, 08:15 in +10:00), correctly reflected in both site-local and UTC time.
2. **Given** an article with `published: false`, **When** it is imported, **Then** the post is saved as a draft, keeps its original date for reference, and is not visible to readers.
3. **Given** an article that is updated by a later export, **When** the update is applied, **Then** its original publish date is preserved.

---

### User Story 4 - Unrecognised content never breaks a post (Priority: P2)

The legacy export contains control types from retired features (for example `wirewaxVideoId` and `pinterestWidget`) that are not documented. These must not break the post, cause editor warnings, or show raw IDs, URLs or code to readers. The engineer must still be able to find out what was dropped so the team can decide what to do about it later.

**Why this priority**: Unknown content is guaranteed in the real export, and rendering it badly is a visible quality issue. It ranks below the core import because the fix is mostly to leave it out safely.

**Independent Test**: Import articles 10232 and 10233, view them as a reader and open them in the editor. No raw video ID, Pinterest URL or empty placeholder appears, and the surrounding content is intact. The import output lists the unrecognised control types and the articles they were found in.

**Acceptance Scenarios**:

1. **Given** an area with an unrecognised control followed by a recognised one, **When** it is imported, **Then** the recognised content is imported normally and the unrecognised control produces nothing visible to readers.
2. **Given** an unrecognised control, **When** the post is opened in the editor, **Then** no invalid-block or unsupported-content warning is shown.
3. **Given** an import run that encountered unrecognised controls, **When** it finishes, **Then** the output names each unrecognised control type, the legacy article ID it came from, and how many times it appeared.

---

### Edge Cases

- **Empty or whitespace-only rich text** (e.g. `<p>&nbsp;</p>` in article 10231): produces no empty paragraph in the post.
- **Stray inline markup** (e.g. `<span style="color:#ff0000">` in article 10234): the text is kept; the stray styling wrapper must not cause editor validation warnings.
- **Special characters and emoji in titles** (e.g. `&` and ❤️ in article 10235): stored and shown correctly, with no double-encoding (no `&amp;amp;`).
- **Malformed line in the export** (invalid JSON, or an `articleBody` string that can't be decoded): that article is reported as failed with its line number or ID, and the rest of the file still imports.
- **Article with no body or no sections**: imported with an empty body and reported as a warning rather than failing.
- **Missing title**: the article is reported as failed instead of creating an untitled post.
- **Area widths that don't match a known pattern** (e.g. three areas, or widths not adding up to 12): rendered as columns using the given proportions, and reported as a warning.
- **Unknown or missing fields in a quote or media control** (missing citation, missing image path): a quote without citation shows only the text; an image without a path is skipped and reported.
- **Links in the format `{localLink:NNNN}` and absolute links to the legacy domain**: kept exactly as they appear in the source. Rewriting them is out of scope for this feature (see Assumptions).
- **Article present in an earlier export but missing from a later one**: left untouched. The importer does not delete posts.
- **Post edited on the new site after import, then reimported with a changed legacy article**: the legacy version wins and overwrites the post body, title and excerpt. Until cut-over, the legacy CMS is the source of truth.
- **Import file path missing or unreadable**: the command stops with a clear error before changing anything.

## Requirements *(mandatory)*

### Functional Requirements

**Running the import**

- **FR-001**: The system MUST provide a command-line import command that takes the path to a legacy NDJSON export file and imports every article record in it as a post.
- **FR-002**: The command MUST process the file one record at a time, so that a bad record does not stop the remaining records from importing.
- **FR-003**: At the end of a run, the command MUST print a summary with the number of articles created, updated, unchanged and failed, plus any warnings (including unrecognised control types, see FR-016).
- **FR-004**: The command MUST offer a dry-run mode that reports what would be created, updated or left unchanged without writing anything.

**Post content and metadata**

- **FR-005**: Each imported post MUST use the legacy `properties.title` as its title and `properties.excerpt` as its excerpt.
- **FR-006**: Each imported post MUST use the legacy `urlName` as its URL slug.
- **FR-007**: Each imported post MUST record its legacy article ID, legacy path, and the legacy `updateDate` it was last imported from, so it can be matched on later runs and used by future redirect and link-rewriting work.

**Body conversion**

- **FR-008**: The system MUST decode the string-encoded grid JSON in `properties.articleBody` and convert sections, rows, areas and controls into native block-editor content, in display order.
- **FR-009**: `rte` controls MUST be converted into native paragraph, heading (levels 2 and 3), and list blocks, keeping inline bold, italic and link formatting.
- **FR-010**: `quote` controls MUST be converted into a native pull-quote block with the quote text and, when present, the citation.
- **FR-011**: `media` controls MUST be converted into a native image block whose source is the legacy image path resolved against `https://www.glowdaily.example`, with the legacy alt text and caption. An empty caption MUST produce no caption element. The image file itself MUST NOT be downloaded or added to the media library; the block only references the legacy URL (see FR-026).
- **FR-012**: Rows with more than one area MUST be converted into a native columns layout with one column per area, each column's width proportional to the area's `grid` value out of 12.
- **FR-013**: Rows with a single full-width area MUST place their content directly in the post without a column wrapper.
- **FR-014**: All generated content MUST open in the block editor with no block validation errors.
- **FR-015**: Empty rich-text content (only whitespace or non-breaking spaces) MUST NOT produce empty blocks.

**Unrecognised content**

- **FR-016**: Controls with an unrecognised `editor.alias` MUST be left out of the visible post content, and MUST be recorded in the run output with the alias, the legacy article ID and the number of occurrences.
- **FR-017**: Leaving out an unrecognised control MUST NOT affect the conversion of other controls in the same area or row.

**Safe reruns**

- **FR-018**: The system MUST match each incoming article to an existing post by its legacy article ID. If a match exists it MUST update that post, never create a second one.
- **FR-019**: If the matched post was imported from an article with the same `updateDate` and the same content, the system MUST leave it unchanged and count it as "unchanged".
- **FR-020**: If the incoming article differs (different `updateDate` or changed content), the system MUST update the existing post's title, excerpt, body, slug and status, and count it as "updated".
- **FR-021**: Articles with no existing match MUST be created and counted as "created".
- **FR-022**: The system MUST NOT delete or unpublish posts for articles that are absent from the file being imported.

**Dates and status**

- **FR-023**: Each post's publish date MUST equal the legacy `createDate`, with its timezone offset converted correctly into both the site's local time and UTC.
- **FR-024**: Updates on later runs MUST NOT change a post's publish date unless the legacy `createDate` itself has changed.
- **FR-025**: Articles with `published: true` MUST be imported as published posts. Articles with `published: false` MUST be imported as drafts and MUST NOT be visible to readers.

**Out of scope (per README Part 1)**

- **FR-026**: The system MUST NOT import authors, categories or media files. The legacy `author` and `category` fields are ignored: no users or terms are created, and posts are left with the default author and category. No image files are downloaded, copied or added to the media library, so the `media` control's `id` is not used.

**Documentation**

- **FR-027**: The project README MUST explain how to run the importer, the assumptions and trade-offs made, next steps, and short approach notes (not implementations) for: rewriting `{localLink:NNNN}` and legacy-domain links, redirects from legacy URLs, and reporting import results to the team and the client. It MUST also explain how AI tools were used.

### Key Entities

- **Legacy article**: One line in the export. Has a stable legacy ID, title, excerpt, URL name, legacy path, create date, update date, published flag, and a grid-JSON body. Author and category are present but not imported here.
- **Grid body**: The article layout. Sections contain rows. Rows contain areas whose widths add up to 12. Areas contain controls in display order.
- **Control**: One piece of content within an area, typed by alias. Supported: `rte` (rich text HTML), `quote` (text + citation), `media` (image path, alt text, caption). Anything else is unrecognised.
- **Imported post**: The site post created from a legacy article. Carries a reference to its legacy ID, legacy path and source `updateDate` so reruns can match and compare it.
- **Import run result**: The outcome of one run: counts of created, updated, unchanged and failed articles, plus warnings such as unrecognised controls and malformed records.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of articles in `articles.ndjson` (5 of 5) are imported as posts on the first run.
- **SC-002**: 100% of imported posts open in the block editor with zero block validation warnings.
- **SC-003**: Running the same import file any number of times leaves the post count unchanged (5 after repeated `articles.ndjson` runs, 6 after `articles-v2.ndjson`) and reports every article as "unchanged" on reruns.
- **SC-004**: Importing `articles-v2.ndjson` after `articles.ndjson` reports exactly 1 created, 1 updated and 4 unchanged.
- **SC-005**: 100% of imported posts have a publish date that matches the legacy `createDate` to the minute.
- **SC-006**: Zero raw legacy control values (video IDs, widget URLs) appear anywhere in the reader-facing output of any imported post.
- **SC-007**: 100% of unrecognised control types in a run are listed in that run's summary.
- **SC-008**: Zero legacy drafts are visible to logged-out readers after import.
- **SC-009**: A full import of the sample file finishes in under 30 seconds on the local development environment.
- **SC-010**: After any import, the site has zero new users, categories, tags or media library items.
- **SC-011**: The README covers all 7 required topics: how to run the importer, assumptions, trade-offs and next steps, internal and legacy-domain links, legacy URL redirects, import reporting, and AI tool usage.

## Assumptions

- **Scope**: Only README Part 1 is covered. Reviewing Jordan's pull request (Part 2) is excluded. Authors, categories and media files are excluded too, as the README says (FR-026). This is different from the `media` *control*, which Part 1 item 1 explicitly requires: it becomes an image block that points at the legacy URL, as `docs/legacy-format.md` allows ("For Part 1, you can reference images at their legacy URL").
- **Platform**: The target is the provided local WordPress environment. The brief requires the import to be a WP-CLI command in `plugin/glow-migration/` and the output to be native block-editor content. These are constraints from the client, not design choices.
- **Images**: Images point at their legacy URL (`https://www.glowdaily.example` + `image`). No files are downloaded, and nothing is added to the media library. That will happen in a separate media migration.
- **Links**: `{localLink:NNNN}` and absolute legacy-domain links are kept as-is. Rewriting them, and setting up redirects, are follow-ups to describe in the README rather than build.
- **Source of truth**: Until cut-over, the legacy CMS is the source of truth. Changes made on the new site to imported fields are overwritten when the legacy article changes.
- **Change detection**: An article counts as changed when its `updateDate` or its content differs from the version last imported. Comparing content as well as dates protects against exports where content changed without a new `updateDate`.
- **Publish date**: `createDate` is the original publish date, as `docs/legacy-format.md` says. `updateDate` is stored for change detection and is not used as the post's modified date.
- **Drafts**: Legacy drafts are imported as drafts, not skipped, so editors can finish them on the new site.
- **Unrecognised controls**: These are left out of the content entirely, not kept as hidden markup. They are recorded in the run output so the team can decide later whether any need a replacement (such as a video embed).
- **Row layouts**: The documented row types are `Full width` (12), `Text & Content` (6 + 6) and `Product & Text` (4 + 8). Columns are built from area widths rather than row names, so other combinations still work.
- **Scale**: The sample files are tiny. The importer should stream records rather than load the whole file into memory, but tuning for very large exports is out of scope.
