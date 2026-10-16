# Quickstart & Validation: Glow Daily Article Importer

These steps prove the feature works end to end. The command is described in [contracts/cli-import.md](./contracts/cli-import.md), and the expected markup in [contracts/block-markup.md](./contracts/block-markup.md). Each step names the spec items it checks.

## Prerequisites

- Docker running, and Node.js 24.18 or later.
- From the repo root: `npm install && npx wp-env start`
- The site is at http://localhost:8888 (`admin` / `password`).

To start from an empty site (deletes **all** posts in the local environment):

```bash
npx wp-env run cli wp post delete $(npx wp-env run cli wp post list --post_type=post --format=ids --post_status=any) --force
```

Record baseline counts for SC-010:

```bash
npx wp-env run cli wp user list --format=count
npx wp-env run cli wp term list category --format=count
npx wp-env run cli wp term list post_tag --format=count
npx wp-env run cli wp post list --post_type=attachment --post_status=any --format=count
```

## 1. Converter tests (FR-008 to FR-017)

```bash
npx wp-env run cli wp eval-file wp-content/plugins/glow-migration/tests/run.php
```

**Expected**: every snapshot and round-trip assertion passes, and the run exits with code 0.

## 2. First import (US1, US3, US4)

```bash
npx wp-env run cli wp glow import wp-content/glow-fixtures/articles.ndjson --user=admin
```

**Expected**: 5 created, 0 updated, 0 unchanged, 0 failed (SC-001). Warnings list `wirewaxVideoId` (10232) and `pinterestWidget` (10233) (SC-007).

Check the dates, statuses and slugs:

```bash
npx wp-env run cli wp post list --post_type=post --post_status=any --fields=ID,post_name,post_status,post_date,post_date_gmt
```

**Expected**:
- 10231 has `post_name` = `spring-skincare-reset` and `post_date_gmt` = `2021-09-02 22:15:00` (US1 scenario 9, SC-005).
- Every `post_date` equals `createDate` in the site timezone.
- 10234 is `draft`. All the others are `publish` (FR-025).

Check the legacy references (US1 scenario 9, FR-007), using the post ID for 10231:

```bash
npx wp-env run cli wp post meta list <ID> --keys=_glow_legacy_id,_glow_legacy_path,_glow_legacy_update_date
```

**Expected**: `10231`, `/skincare/spring-skincare-reset/`, `2025-02-11T14:02:33+11:00`.

Check no side data was created (SC-010): rerun the 4 baseline count commands. **Expected**: identical counts.

## 3. Rerun safety and dry run (US2)

```bash
npx wp-env run cli wp glow import wp-content/glow-fixtures/articles.ndjson --user=admin
```

**Expected**: 0 created, 0 updated, 5 unchanged. The post count is still 5 (US2 scenario 1).

```bash
npx wp-env run cli wp glow import wp-content/glow-fixtures/articles-v2.ndjson --dry-run
npx wp-env run cli wp post list --post_type=post --post_status=any --format=count
```

**Expected**: `[dry-run]` output showing 1 created (10236), 1 updated (10232), 4 unchanged. The post count is still 5, and 10232 still has its old title (US2 scenario 5, FR-004).

```bash
npx wp-env run cli wp glow import wp-content/glow-fixtures/articles-v2.ndjson --user=admin
npx wp-env run cli wp glow import wp-content/glow-fixtures/articles-v2.ndjson --user=admin
```

**Expected**: the first run gives 1 created, 1 updated, 4 unchanged (SC-004). The second gives 6 unchanged (SC-003). The post count is 6. Post 10232 has the title "Serum vs essence: what's the difference (and do you need both)?" and keeps its original post ID and publish date (US2 scenario 2, FR-024).

## 4. Editor validation (FR-014, SC-002)

Open each imported post at `http://localhost:8888/wp-admin/post.php?post=<ID>&action=edit` and paste this into the browser console:

```js
(() => {
  const walk = (bs) => bs.flatMap((b) => [b, ...walk(b.innerBlocks)]);
  const bad = walk(wp.data.select('core/block-editor').getBlocks()).filter((b) => !b.isValid);
  console.log(bad.length ? bad.map((b) => b.name) : 'All blocks valid');
})();
```

**Expected**: `All blocks valid`, and no "This block contains unexpected or invalid content" notices.

## 5. Reader view (US1, US4, SC-006, SC-008)

- Visit 10232 and 10233 on the front end. No video ID, no Pinterest URL, and no empty gaps where those controls were.
- 10232 shows the image and text side by side (one-third / two-thirds). 10233 shows two equal columns.
- 10235's title shows `&` and ❤️ correctly. 10231 has no empty paragraph where `<p>&nbsp;</p>` was.
- Images load from `https://www.glowdaily.example/media/…`, not from the site's uploads folder (FR-011).
- Logged out, visiting 10234 (`?p=<ID>`) returns 404.

## 6. Failure handling (edge cases)

Make a copy of the fixture with one corrupt line, and run it:

```bash
(cat fixtures/articles.ndjson; echo '{not json') > fixtures/broken.ndjson
npx wp-env run cli wp glow import wp-content/glow-fixtures/broken.ndjson --user=admin; echo "exit=$?"
rm fixtures/broken.ndjson
```

**Expected**: 5 unchanged and 1 failed (the line number is reported). Exit code is 1.

## 7. README (FR-027, SC-011)

Check that `README.md` covers all 7 topics: how to run, assumptions, trade-offs and next steps, internal and legacy-domain links, legacy URL redirects, import reporting, and AI tool usage.
