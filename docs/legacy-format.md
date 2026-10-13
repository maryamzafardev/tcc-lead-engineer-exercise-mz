# Legacy content format

Glow Daily's legacy CMS exports one article per line as NDJSON. Two exports are provided:

| File | What it represents |
| --- | --- |
| `fixtures/articles.ndjson` | The first export |
| `fixtures/articles-v2.ndjson` | A later export of the same site, taken after editors kept working |

## Article record

```json
{
  "id": 10231,
  "docType": "ArticlePage",
  "name": "The 10-minute spring skincare reset",
  "urlName": "spring-skincare-reset",
  "path": "/skincare/spring-skincare-reset/",
  "createDate": "2021-09-03T08:15:00+10:00",
  "updateDate": "2025-02-11T14:02:33+11:00",
  "published": true,
  "category": "Skincare",
  "author": "Priya Nand",
  "properties": {
    "title": "The 10-minute spring skincare reset",
    "excerpt": "Swap heavy winter layers for a lighter routine in three easy steps.",
    "articleBody": "{\"name\":\"1 column layout\",\"sections\":[ ... ]}"
  }
}
```

| Field | Notes |
| --- | --- |
| `id` | The legacy node ID. Unique and stable across exports. |
| `path` | The article's URL on the legacy site, relative to `https://www.glowdaily.example`. |
| `createDate` | When the article was first created. Treat this as the original publish date. |
| `updateDate` | When the article was last changed in the legacy CMS. |
| `published` | `false` means the article is a draft on the legacy site. |
| `author` | The byline as plain text. There is no separate author record. |
| `properties.articleBody` | The article body as **grid JSON, stored as a string**. You'll need to decode it. |

## Grid JSON

The body is made up of sections, rows, areas and controls:

```json
{
  "name": "1 column layout",
  "sections": [
    {
      "grid": 12,
      "rows": [
        {
          "name": "Product & Text",
          "areas": [
            { "grid": 4, "controls": [ { "editor": { "alias": "media" }, "value": { } } ] },
            { "grid": 8, "controls": [ { "editor": { "alias": "rte" }, "value": "<p>...</p>" } ] }
          ]
        }
      ]
    }
  ]
}
```

- **Rows** contain one or more **areas**. Area widths (`grid`) are out of 12 and add up to 12 for each row.
- Row names used on the site are `Full width` (12), `Text & Content` (6 + 6) and `Product & Text` (4 + 8).
- **Controls** sit inside areas, in display order. The control type is in `editor.alias`.

## Control types

| Alias | `value` | Notes |
| --- | --- | --- |
| `rte` | HTML string | Output of the legacy rich text editor. Can contain `<p>`, `<h2>`, `<h3>`, `<ul>`, `<li>`, `<strong>`, `<em>`, `<a>` and the occasional stray `<span>` or `&nbsp;`. |
| `quote` | `{ "text": "...", "citation": "..." }` | A pull quote. |
| `media` | `{ "id": 5501, "image": "/media/5501/file.jpg", "altText": "...", "caption": "..." }` | An image. `image` is relative to `https://www.glowdaily.example`. `altText` and `caption` may be empty. |

Other aliases appear in the export from older features that are no longer supported. They aren't documented.

## Links inside `rte` content

The legacy editor stores links to other articles as `{localLink:NODE_ID}`, for example:

```html
<a href="{localLink:10233}">drugstore sunscreen guide</a>
```

Some editors also pasted absolute links to the legacy domain, for example `https://www.glowdaily.example/skincare/best-vitamin-c-serums/`.

## Media

Media files will be migrated separately (see Jordan's pull request in `review/`). For Part 1, you can reference images at their legacy URL.
