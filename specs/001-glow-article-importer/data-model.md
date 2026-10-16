# Data Model: Glow Daily Article Importer

**Feature**: [spec.md](./spec.md) | **Research**: [research.md](./research.md)

## 1. Legacy article (input, one NDJSON line)

| Field | Type | Required | Validation / use |
| --- | --- | --- | --- |
| `id` | int | yes | Must be a positive integer. The matching key (FR-018). Missing → record **failed**. |
| `name` | string | no | Not used. `properties.title` is used for the title instead. |
| `urlName` | string | yes | Post slug (FR-006), passed through `sanitize_title()`. If empty, falls back to the slugified title. |
| `path` | string | no | Stored as meta (FR-007) for future redirect work. |
| `createDate` | ISO 8601 string with offset | yes | Must parse as a date. Unparseable → record **failed**. Becomes the publish date (FR-023). |
| `updateDate` | ISO 8601 string with offset | no | Stored as meta (FR-007), and part of the change hash. |
| `published` | bool | yes | `true` → `publish`, `false` → `draft` (FR-025). A missing value counts as `false`, so it's never accidentally made public. |
| `category`, `author` | string | no | **Ignored** (FR-026). Not read and not stored. |
| `properties.title` | string | yes | Post title. Empty → record **failed**. |
| `properties.excerpt` | string | no | Post excerpt, as plain text. |
| `properties.articleBody` | string (JSON) | no | Decoded into a Grid body. Decode error → record **failed**. Empty, or no sections → empty content plus a **warning**. |

## 2. Grid body (decoded `articleBody`)

```text
GridBody
└── sections[]            (grid: int, normally 12)
    └── rows[]            (name: string, e.g. "Full width")
        └── areas[]       (grid: int 1–12)
            └── controls[] (editor.alias: string, value: mixed)
```

Rules:
- A row with **1 area** → its blocks are placed straight into the post flow (FR-013).
- A row with **2 or more areas** → `core/columns`, with one `core/column` per area. Each column's width is `round( grid / 12 * 100, 2 ) . '%'` (FR-012).
- Areas whose widths don't add up to 12, or a row with more than 2 areas → still converted, plus a **warning**.
- A row whose areas all end up with no blocks → no output at all, so there's no empty columns block.

## 3. Control (by `editor.alias`)

| Alias | `value` shape | Output | Skip / warn conditions |
| --- | --- | --- | --- |
| `rte` | HTML string | 0..n of paragraph / heading / list / html (see research R2) | Whitespace or `&nbsp;` only → no output, no warning |
| `quote` | `{ text, citation }` | `core/pullquote` | Empty `text` → skipped with a warning. Empty `citation` → no `<cite>` |
| `media` | `{ id, image, altText, caption }` | `core/image`, `src = https://www.glowdaily.example + image` | Missing or empty `image` → skipped with a warning. `id` is **ignored** (FR-026) |
| *anything else* | any | nothing | Always recorded as an unrecognised control (FR-016) |

The exact markup for each block is in [contracts/block-markup.md](./contracts/block-markup.md).

## 4. Imported post (WordPress `post`)

| Post field | Source |
| --- | --- |
| `post_type` | `post` |
| `post_title` | `properties.title` |
| `post_excerpt` | `properties.excerpt` |
| `post_name` | `urlName` (FR-006) |
| `post_content` | Converted grid body (serialized blocks) |
| `post_status` | `publish` / `draft` from `published` |
| `post_date` | `createDate` in the site's timezone |
| `post_date_gmt` | `createDate` in UTC |
| `post_author` | Default: the user running the command. Not taken from the legacy `author` (FR-026) |
| Categories / tags | None set. WordPress applies the default category (FR-026) |

| Post meta key | Value | Purpose |
| --- | --- | --- |
| `_glow_legacy_id` | int | Matching key for reruns (FR-007, FR-018) |
| `_glow_legacy_path` | string | Future redirects (FR-007) |
| `_glow_legacy_update_date` | string (as given) | Shows which legacy version was imported (FR-007) |
| `_glow_import_hash` | sha256 hex | Change detection (research R3) |
| `_glow_dropped_controls` | array of alias strings | Lets a later report find posts with dropped content (research R5) |

All keys start with `_` so they're hidden from the Custom Fields panel.

## 5. Record outcome (state per record per run)

```text
                 ┌── decode/validate fails ──────────────▶ FAILED    (no write)
read line ──────▶┤
                 ├── no post with _glow_legacy_id ───────▶ CREATED   (insert)              FR-021
                 ├── post found, hash equal ─────────────▶ UNCHANGED (no write)            FR-019
                 └── post found, hash differs ───────────▶ UPDATED   (update same ID)      FR-020
```

These four are the only outcomes (spec FR-003, US2 scenario 4). With `--dry-run`, the outcome is computed in the same way, but nothing is inserted or updated (FR-004).

## 6. Import run report (in memory, printed at the end)

| Field | Type |
| --- | --- |
| `created`, `updated`, `unchanged`, `failed` | int counts |
| `rows` | list of `{ line, legacy_id, post_id, outcome, title }` |
| `warnings` | list of `{ line, legacy_id, code, message }`, where `code` is one of: `unrecognised_control`, `empty_body`, `unusual_row_layout`, `media_missing_image`, `quote_missing_text`, `html_fallback`, `duplicate_match` |
| `unrecognised` | map of alias → `{ count, legacy_ids[] }` (SC-007) |
