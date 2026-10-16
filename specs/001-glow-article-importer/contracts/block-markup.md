# Contract: Grid → block markup

This is the exact output the converter must produce. Each fragment matches the matching core block's `save()` output (research R1). Blocks are separated by `\n\n`. `…` is escaped content.

## `rte`

| Source | Output |
| --- | --- |
| `<p>x</p>` | `<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->` |
| `<h2>x</h2>` | `<!-- wp:heading -->\n<h2 class="wp-block-heading">x</h2>\n<!-- /wp:heading -->` |
| `<h3>x</h3>` | `<!-- wp:heading {"level":3} -->\n<h3 class="wp-block-heading">x</h3>\n<!-- /wp:heading -->` |
| `<ul><li>a</li></ul>` | `<!-- wp:list -->\n<ul class="wp-block-list"><!-- wp:list-item -->\n<li>a</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->` |
| `<ol>…</ol>` | as above, with `{"ordered":true}` and `<ol class="wp-block-list">` |
| `<p>&nbsp;</p>` | *(nothing)* |
| `<span style="…">x</span>` | `x` (unwrapped) |
| loose text or inline at top level | wrapped in one paragraph |
| unknown block element | `<!-- wp:html -->\n…original…\n<!-- /wp:html -->` plus an `html_fallback` warning |

Inline allow-list: `strong`, `em`, `a[href|target|rel]`, `br`, `sup`, `sub`, `code`. `b` becomes `strong` and `i` becomes `em`. `href` values, including `{localLink:N}`, are kept as-is apart from attribute escaping.

## `quote`

```html
<!-- wp:pullquote -->
<figure class="wp-block-pullquote"><blockquote><p>TEXT</p><cite>CITATION</cite></blockquote></figure>
<!-- /wp:pullquote -->
```

`<cite>…</cite>` is left out when the citation is empty.

## `media`

```html
<!-- wp:image -->
<figure class="wp-block-image"><img src="https://www.glowdaily.example/media/5501/spring-flatlay.jpg" alt="ALT"/><figcaption class="wp-element-caption">CAPTION</figcaption></figure>
<!-- /wp:image -->
```

- `alt=""` is always present, even when empty, because a missing alt is treated as decorative.
- `<figcaption …>` is left out when the caption is empty.
- No `id` attribute and no `wp-image-N` class: the block isn't linked to any media library item (FR-026).

## Rows

- **Single area**: the area's blocks are output directly.
- **Multiple areas** (example: 4 + 8):

```html
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%">…inner blocks…</div>
<!-- /wp:column -->

<!-- wp:column {"width":"66.67%"} -->
<div class="wp-block-column" style="flex-basis:66.67%">…inner blocks…</div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
```

A 6 + 6 row uses `"width":"50%"` / `flex-basis:50%`.

## Unrecognised controls

These produce no markup.

## Invariants (checked by the tests)

1. `serialize_blocks( parse_blocks( $out ) ) === $out`
2. No block reports `isValid === false` in the editor (quickstart step 4).
3. The output contains none of: `wirewax`, `pinterest.com`, `8812345`.
