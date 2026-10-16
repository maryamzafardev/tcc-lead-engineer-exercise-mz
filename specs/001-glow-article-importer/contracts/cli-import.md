# Contract: `wp glow import`

## Synopsis

```text
wp glow import <file> [--dry-run]
```

| Argument | Description |
| --- | --- |
| `<file>` | Path to a legacy NDJSON export. Inside wp-env: `wp-content/glow-fixtures/articles.ndjson`. |
| `--dry-run` | Decode, convert and compare every record, and report what would happen, without writing anything (FR-004). |

Recommended invocation, so revisions are attributed to a real user:

```bash
npx wp-env run cli wp glow import wp-content/glow-fixtures/articles.ndjson --user=admin
```

## Behaviour

1. If `<file>` is missing or unreadable → `Error: …` on stderr, exit code 1, nothing written.
2. Each non-blank line is processed on its own. The outcome per line is `created`, `updated`, `unchanged` or `failed` (see [data-model.md §5](../data-model.md)).
3. One progress line is printed per record, for example:
   `Created post 12 for legacy 10231 "The 10-minute spring skincare reset"`
4. Warnings are printed as they happen, with `WP_CLI::warning`, and each one includes the line number and legacy ID.
5. A summary is printed at the end.

## Output (summary)

```text
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

With `--dry-run`, every line is prefixed with `[dry-run]`, and the final line reads `Success: Dry run complete; no changes written.`

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | All records created, updated or unchanged. Warnings are allowed. |
| 1 | The file couldn't be read, or at least one record failed. The summary is still printed. |

## Guarantees

- **Idempotent**: running the same file again gives `unchanged` for every record that succeeded before, and creates no posts.
- **Never deletes**: posts whose legacy ID is missing from the file are left alone (FR-022).
- **Never publishes drafts**: `published: false` always results in `post_status = draft` (FR-025).
- **No side data**: never creates users, terms or media items (FR-026, SC-010).
