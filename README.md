# Lead WordPress Engineer Challenge

Thanks for taking the time to do this. It's a chance for us to see how you approach a hands-on problem and how you review code, in a setting close to the work you'd be doing with us.

Please spend **no more than 3 hours** in total. We'd rather see sound decisions than everything finished. If you run out of time, stop and note in your README what you'd do next. You're welcome to use any tools you normally would, including AI assistants.

## Context

You're the lead engineer on a migration for a fictional client, **Glow Daily**, a beauty and lifestyle publisher moving from a legacy CMS to WordPress. The legacy CMS stores article bodies as "grid" JSON: rows of columns, each holding typed controls.

This repo contains:

| Path | What it is |
| --- | --- |
| `.wp-env.json` | A local WordPress environment with WP-CLI |
| `plugin/glow-migration/` | An empty plugin for you to build in |
| `fixtures/articles.ndjson` | Sample articles exported from the legacy CMS |
| `fixtures/articles-v2.ndjson` | A later export of the same site |
| `docs/legacy-format.md` | A description of the export format. **Start here.** |
| `review/` | A pull request from an engineer on your team |

## Getting started

You'll need Docker and Node.js 24.18 or later installed.

```bash
npm install
npx wp-env start
```

`npm install` may print audit and `allow-scripts` warnings about `wp-env`'s own dependencies. You can ignore them for this exercise.

The site runs at http://localhost:8888 (username `admin`, password `password`). The fixtures are available inside the environment at `wp-content/glow-fixtures/`, so you can run WP-CLI commands like this:

```bash
npx wp-env run cli wp glow import wp-content/glow-fixtures/articles.ndjson
```

The command name is only an example. Call it whatever you like.

## Part 1: Build a basic importer

Build a WP-CLI command in `plugin/glow-migration/` that imports the articles in `fixtures/articles.ndjson` as posts.

1. **Native blocks.** Convert each article's grid JSON into valid, native block markup that opens in the block editor without validation errors. Support the `rte`, `quote` and `media` controls, and multi-column rows.
2. **Unrecognised content.** Handle control types you don't recognise without breaking the post or showing anything odd to readers.
3. **Safe reruns.** Running the command twice must not create duplicates. Running it against `articles-v2.ndjson` should update what has changed and add what's new.
4. **Dates.** Keep each article's original publish date.

You don't need to handle authors, categories or media files for this exercise.

In your README, briefly explain how you'd approach the following if you had more time. You don't need to build them.

- Internal links in the format `{localLink:1234}`, and links to the old domain.
- Redirects from legacy URLs.
- Reporting what an import run did, for the team and the client.

## Part 2: Review a pull request

Jordan, another engineer on your team, has opened a pull request that adds media importing, legacy redirects and a random articles helper to the migration. The description is in `review/PR.md` and the code is in `review/includes/`.

Review it as you would on a real project. Either open a pull request on your own repo and leave review comments there, or write your review in `REVIEW.md`.

## What to submit

1. **A private GitHub repository** with `zhickson` invited as a collaborator. Commit this starter as your first commit, then commit as you go. Please don't squash your history.
2. **Your README**, covering:
   - how to run your importer;
   - any assumptions you made;
   - the trade-offs you made and what you'd do next;
   - your notes on the three Part 1 follow-up topics;
   - how you used AI tools, if you did.
3. **A short Loom video (up to 10 minutes)** walking us through both parts and your AI tooling workflow (if used) along the way.

## Help

If anything in this brief is unclear, email Zac. Asking questions won't count against you.
