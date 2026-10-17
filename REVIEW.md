# Review: PR #14 (short version)

Thanks Jordan, this is easy to follow and uses the right meta keys. Not ready for staging yet, though: two things would break the site and one is a security hole. Could you also split it into three PRs (media, redirects, random articles)?

## 🔴 Must fix

- **Line 40 (media importer):** missing `;`. The file won't parse, which crashes the whole site. Maybe run the PHP linter next time or install PHPCS which will sniff automatically for code violations / errors. 
- **Lines 12–14, 114 (media importer):** wrap the includes and `add_command()` in `if ( defined( 'WP_CLI' ) && WP_CLI )`. Otherwise every page fatals.
- **Lines 64–66 (redirects):** change `?glow_test_redirect=`. Anyone can use it to send visitors to any site (an open redirect). 

## 🟠 Should fix

**Media importer**
- `find_post()` loads all ~2,500 posts per article. Query using get_posts() using meta key `_glow_legacy_id` instead.
- If no post is found, skip the article. Also, right now it attaches images to post 0.
- Check `is_wp_error()` after `download_url()` and `media_handle_sideload()`, otherwise the error will get passed on as the attachment and delete temp files on failure.
- Make it safe to rerun: skip images that are already imported (store the legacy media `id` on the attachment).
- Update the image blocks in the post content to use the new attachment. Otherwise posts still show the old URLs.

**Redirects**
- Use `wp_safe_redirect( $url, 301 ); exit;`. At the moment it's missing `exit` and sends a 302.
- Strip the query string (utm codes) from `REQUEST_URI` before the lookup.
- Line 42 needs to be moved inside the `if`, it seems it was part of the if block. 
- Admin page: Any logged-in user can see the admin page, including subscibers, needs to be defined per capability.

**Random articles**
- `orderby => rand` is slow and can't be cached. Change orderby criteria.
- Exclude the current post (`post__not_in`).
- Move this to the theme. If the migration plugin is switched off after launch, templates calling it will fatal.

## ❓ Questions

- How did the images come through, given that `www.glowdaily.example` doesn't resolve - we need to replace the URL with the actual yes? You have in your comments "I ran it on the 5 articles in the fixtures and all the images came through. "?
- Who calls `build_map()` in the redirects? The importer doesn't? Is this supposed to be a CLI command? You mentioned you tested and they redirected, how was this run, please LMK.

## 🔗 Fit with the importer

- ✅ The meta keys, path format and namespace all match.
- ⚠️ Run the media import **after** the article import, because a re-import of the articles overwrites post media content.
- ⚠️ Rewritten image blocks must match WordPress's exact block markup, otherwise we will get the unexpected or invalid content error. 


*AI (Claude Code) helped me go through the code; I reviewed the points before posting.*
