# Glow Daily migration: article importer

A WP-CLI command that imports Glow Daily articles from the legacy CMS's NDJSON export into WordPress as posts with native, editable blocks. This is Part 1 of the [Lead WordPress Engineer Challenge](BRIEF.md). The original brief is in `BRIEF.md`.

- **Plugin:** `plugin/glow-migration/`
- **Spec, plan and tasks:** `specs/001-glow-article-importer/`

---

## How to run

You'll need Docker and Node.js 24.18 or later.

```bash
npm install
npx wp-env start
```

The site runs at http://localhost:8888 (`admin` / `password`).

**Preview a run without changing anything:**

```bash
npx wp-env run cli wp glow import wp-content/glow-fixtures/articles.ndjson --dry-run

npx wp-env run cli wp glow import wp-content/glow-fixtures/articles-v2.ndjson --dry-run
```


**Import the first export, then the later one:**

```bash
npx wp-env run cli wp glow import wp-content/glow-fixtures/articles.ndjson --user=admin

npx wp-env run cli wp glow import wp-content/glow-fixtures/articles-v2.ndjson --user=admin
```

`--user=admin` attributes the posts and their revisions to a real user.

**Run the tests** (127 assertions: block markup, converters, fixture snapshots, dates):

```bash
npx wp-env run cli wp eval-file wp-content/plugins/glow-migration/tests/run.php
```

**Example output** (`articles-v2.ndjson` after `articles.ndjson`):

```text
Unchanged post 6 for legacy 10231 "The 10-minute spring skincare reset"
Updated post 7 for legacy 10232 "Serum vs essence: what's the difference (and do you need both)?"
Warning: line 2 legacy 10232: Left out unrecognised control "wirewaxVideoId".
...
Created post 17 for legacy 10236 "How to choose a moisturiser"
+-----------+-------+
| outcome   | count |
+-----------+-------+
| created   | 1     |
| updated   | 1     |
| unchanged | 4     |
| failed    | 0     |
+-----------+-------+
Unrecognised controls (left out of content):
  wirewaxVideoId   x1  legacy IDs: 10232
  pinterestWidget  x1  legacy IDs: 10233
Success: Imported 6 records (1 created, 1 updated, 4 unchanged).
```

The command exits with code 1 if any record fails. Each failure is reported with its line number and reason, and the rest of the file still imports.

---

| Requirement from the brief | How it's met |
| --- | --- |
| **Native blocks** | `rte` becomes paragraph, heading and list blocks. `quote` becomes a pull quote. `media` becomes an image block pointing at the legacy URL. A multi-area row becomes columns sized from the grid (4 + 8 → 33.33% / 66.67%, 6 + 6 → 50% / 50%). The markup matches each core block's `save()` output byte for byte, so the editor reports no validation errors. Checked in the editor: no invalid-block warnings. |
| **Unrecognised content** | Unknown controls (`wirewaxVideoId`, `pinterestWidget`) are left out, so readers never see raw IDs or URLs. They're listed in the run summary and stored on the post as `_glow_dropped_controls`. The content around them still converts. |
| **Safe reruns** | Each post stores its legacy ID (`_glow_legacy_id`) and a fingerprint of the source data. On a rerun, the importer finds the post by legacy ID and compares fingerprints: unchanged is skipped, changed is updated in place, and new is created. Nothing is ever deleted. |
| **Dates** | `createDate` (with its offset) sets both `post_date_gmt` (UTC) and `post_date` (site time). Legacy drafts stay drafts and keep their dates. |

**Results on the fixtures:**
- Importing `articles.ndjson` gives 5 created. Rerunning it gives 5 unchanged.
- Importing `articles-v2.ndjson` gives 1 created (10236), 1 updated (10232, same post ID) and 4 unchanged. Rerunning it gives 6 unchanged.

---

## Assumptions

- **Scope:** Part 1 only. Authors, categories and media files aren't imported, as the brief says. The *`media` control* is still converted, into an image block that references the legacy URL, as `docs/legacy-format.md` allows. The legacy `author` and `category` fields are ignored, and the media `id` is unused.
- **Images point at `https://www.glowdaily.example`,** which doesn't resolve (`.example` is a reserved domain), so they show as broken locally. The separate media migration replaces these URLs.
- **The legacy CMS is the source of truth until cut-over.** If a legacy article changes, the next import overwrites the post's title, excerpt, slug, body, status and dates.
- **Change detection:** an article counts as changed when its `updateDate` *or* its content differs from the last import, or when the converter version changes.
- **`createDate` is the publish date** (per `docs/legacy-format.md`). `updateDate` is stored for reference and change detection only.
- **Legacy drafts** (`published: false`) are imported as drafts, not skipped. A missing `published` value also counts as a draft, so nothing goes public by accident.
- **Excerpts are stored as plain text.** One legacy excerpt contains `<em>`, which is stripped.
- **Links** (`{localLink:N}` and absolute legacy-domain links) are kept exactly as they appear in the source, ready for a link-rewriting pass (see below).
- **Columns are built from area widths,** not row names, so an undocumented layout still converts. It's flagged as a warning.
- **Rich text** is filtered through an allow-list: `strong`, `em`, `a` (`href`, `target` and `rel` only), `br`, `sup`, `sub` and `code`. Stray wrappers like `<span style>` are unwrapped, keeping their text. Paragraphs containing only `&nbsp;` are dropped. Any other block-level HTML is kept as a Custom HTML block, with a warning.

---

## Trade-offs and what I'd do next

**Trade-offs I made:**
- **Hand-written block markup instead of a JS serializer.** It's the riskiest part of the brief, so I isolated it in one class (`Block_Markup`), pinned every block to exact expected strings and fixture snapshots, and checked the results in the real editor. The cost: if core changes a block's `save()` output, these strings need updating.
- **A small test runner instead of PHPUnit.** `.wp-env.json` disables the tests environment, and setting up `wp-phpunit` would have used a big share of the time. The runner needs no framework and runs with `wp eval-file`.
- **`DOMDocument` for parsing rich text.** It works on the plugin's PHP 8.1 minimum. PHP 8.4's `Dom\HTMLDocument` parses HTML5 better.
- **WordPress's HTML filter (KSES) is switched off during the import.** WP-CLI runs without a user who's allowed unfiltered HTML, so KSES would strip parts of the generated markup, such as column styles. The content is safe because it comes from our converter, which applies its own allow-list.
- **The converter version is part of the fingerprint.** After changing how content is converted, bump `CONVERTER_VERSION` in `glow-migration.php`, and the next run re-converts every post in place. The articles themselves haven't changed, so their `updateDate` alone wouldn't trigger it.

**What I'd do next:**
1. **Move the tests to PHPUnit and add the editor validation check to CI,** for example with Playwright and `isValid` on every block.
2. **Protect edits made in WordPress.** Store a hash of the content as imported, so the importer can tell when an editor has changed a post and warn instead of overwriting it.
3. **Turn off pingbacks during the import.** Publishing old articles queues pingbacks (`_pingme`) to every site they link to.
4. **Set the site timezone before the first import.** `post_date` is saved in site time and isn't recalculated if the timezone changes later. Alternatively, include the timezone in the fingerprint.
5. **Report images with no alt text** (for example legacy 10235), so editors can add it.
6. **Replace the retired controls with embeds** (video, Pinterest), if the client still wants that content.
7. **Set `post_modified` from `updateDate`,** if the theme shows "Last updated" dates.
8. **Prepare for scale (about 2,500 articles):** batch the work, use `wp_defer_term_counting()` and suspend cache invalidation during the run, and support `--limit` and `--offset` for resuming.
9. **Show the legacy ID in the admin**, as a read-only column in the Posts list.
10. **Import authors, categories and media** (out of scope here).

---

## Follow-up topics

### Internal links (`{localLink:1234}`) and links to the old domain

- **Run it as a second pass after the import,** so every article already has a post to link to.
- **Build two lookup tables from post meta:**
  - legacy ID → post ID, from `_glow_legacy_id`;
  - legacy path → post ID, from `_glow_legacy_path`.
- **Rewrite the links:** for every imported post, use `WP_HTML_Tag_Processor` to visit each `<a href>`:
  - `{localLink:N}` → the permalink of the post with legacy ID `N`;
  - an absolute `https://www.glowdaily.example/…` link whose path matches an imported article → that post's permalink;
  - other legacy-domain URLs, such as category pages or media → map them where there's an equivalent, otherwise leave and report them.
- **Store each link as a relative URL or the post ID,** rather than a hard-coded domain, so it survives staging → production moves.
- **Report every link it can't resolve,** for example `{localLink:10299}` in 10231, which points at an article that isn't in the export. Those need an editor or the client to decide.
- **Make it idempotent and version it,** like the importer, so it's safe to rerun after each import.

### Redirects from legacy URLs

**Why redirects are needed.** The same article has a different address on each site:

| | Address |
| --- | --- |
| Old Glow Daily site | `https://www.glowdaily.example/skincare/spring-skincare-reset/` |
| New WordPress site (test install) | `http://localhost:8888/2021/09/02/spring-skincare-reset/` |

The end of the address (`spring-skincare-reset`) is the same, because the importer copies the old `urlName` as the slug. The part before it is different:

- The old site put the **section** in the address: `/skincare/`.
- The new WordPress site puts the **publish date** in the address: `/2021/09/02/`.

WordPress builds addresses from a pattern chosen under **Settings → Permalinks**. The fresh test site happens to use the date-based pattern.

Links to the old addresses are everywhere: Google results, other websites, social media posts, people's bookmarks. After launch, someone visiting `/skincare/spring-skincare-reset/` would get a "page not found" error, because that address doesn't exist on the new site. That loses readers and hurts search rankings.

**The fix is redirects.** A redirect is an automatic forwarding rule: anyone who visits the old address is sent straight to the new one. Visitors don't notice, and Google moves its listing to the new address. The importer already stores each article's old address on the post (`_glow_legacy_path`), so the redirect rules can be built from that. Jordan's pull request (Part 2) attempts this.

**The alternative is to make the new addresses match the old ones,** so fewer redirects are needed, by choosing a permalink pattern that includes the section, such as `/%category%/%postname%/`. That only works if the posts have the right categories (Skincare, Makeup, Fragrance). Categories are out of scope for this importer, so every post is "Uncategorized" for now, and the addresses would come out as `/uncategorized/spring-skincare-reset/`. Once categories are imported, that pattern would give `/skincare/spring-skincare-reset/`, matching the old site exactly. That's a decision to make with the client.

In short: WordPress builds new URLs, so old links would break. The importer stores each article's old path, so we can redirect old URLs to the new ones, or match the old URL structure once categories are imported.

**How I'd build the redirects:**

- **Build the redirect lookup from the data we already store** (`_glow_legacy_path` → post) rather than a separate copy that can drift.
- **For about 2,500 articles and heavy traffic, handle redirects as early as possible:**
  - **Best:** export the map as server rules (nginx or the CDN), so redirects never reach PHP.
  - **Otherwise:** a lookup on `template_redirect`, only for 404s, against an indexed table or a cached map. Not a large autoloaded option.
- **Normalise incoming paths:** trailing slash, case, query strings, URL encoding.
- **Use 301 redirects,** then `exit`.
- **Only redirect to our own URLs,** never to anything taken from the request.
- **Also redirect old category and section URLs** to their new archives, where they exist.
- **Before launch, check with a crawl:** every legacy path from the export should return a 301 to a URL that returns 200.

### Reporting what an import run did

- **Add a `--format=json` or `--report=<file>` option.** It would write the full run report: counts, per-article outcomes with post IDs, warnings, unrecognised controls, unresolved links and failures with line numbers. `Import_Report` already collects all of this, and the command only prints it.
- **Store each run's report** (for example as a log entry or a private custom post type), so the team can compare rehearsal runs over time.
- **For the team:** the full detail, plus a diff against the previous run ("10232 updated: title and body changed").
- **For the client:** a short summary in plain language:
  - "6 articles migrated, 1 draft kept as a draft";
  - "2 articles had retired video or Pinterest widgets removed";
  - "1 internal link points to an article that isn't in the export";
  - "1 image has no alt text";

  each with a link to the affected posts, so they can make decisions before launch.

---

## How I used AI tools

> **⚠️ TODO (Maryam):** check this section reflects what you did and add your own reflections, then delete this note before submitting.

I used **Claude Code** with **GitHub Spec Kit**, working in stages:

1. **Specify:** turned Part 1 of the brief into a spec (`specs/001-glow-article-importer/spec.md`) with user stories, functional requirements and measurable success criteria, then checked it against a quality checklist. I reviewed and corrected the spec myself, for example:
   - clarifying that media *files* are out of scope but the `media` *control* isn't;
   - fixing the requirement numbering;
   - closing gaps the checklist found (an undefined "skipped" outcome, and a mismatch between "newer" and "different" `updateDate`).
2. **Plan:** produced research decisions, a data model, contracts for the CLI and the exact block markup, and a validation guide.
3. **Tasks:** broke the plan into 32 tasks grouped by user story.
4. **Implement:** built one phase at a time, reviewing each phase before moving on. I checked the editor manually (no invalid-block warnings on any post) and questioned the results along the way: the image alt text, the timezone display, and how the legacy ID maps to post IDs.

The AI also reviewed its own output. One example: a fixture snapshot showed pull quotes saving `I&#039;d` while paragraphs saved a plain `'`. The editor would rewrite that on first save, so it was fixed and covered by a test.

The full trail is in `specs/001-glow-article-importer/` (spec, plan, research, tasks with verification notes).
