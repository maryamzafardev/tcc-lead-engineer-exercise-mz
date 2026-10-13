# PR #14: Add media import, legacy redirects and a random articles helper

**Author:** Jordan (junior engineer)
**Branch:** `feature/media-and-redirects` → `main`
**Files changed:** `includes/class-media-importer.php`, `includes/class-legacy-redirects.php`, `includes/functions-random-articles.php`

---

Hey! This adds the three pieces we talked about in stand-up:

1. **Media import.** `wp glow media <file>` goes through the export, downloads every image from the old site and attaches it to the matching article. It also sets the first image as the featured image.
2. **Legacy redirects.** It saves a map of old URLs to new posts, so anyone hitting an old URL gets redirected to the new one. There's also a small admin page under Tools so editors can see the redirects.
3. **Random articles helper.** `get_random_articles()` returns random imported articles, optionally from one section, for the "You might also like" area at the bottom of every article. The frontend team can call it from the single post template.

**Testing:** I ran it on the 5 articles in the fixtures and all the images came through. I also tried a few old URLs in the browser and they redirected, and the random articles change every time I refresh.

Can you review before I deploy to staging tomorrow? Thanks!

---

> Note: assume the article importer already exists and has stored the legacy node ID in the `_glow_legacy_id` post meta and the legacy URL path in `_glow_legacy_path` on every imported post. The live site has about 2,500 articles and a lot of traffic. You don't need to run this code. Please review it as you would a real pull request.
