# Recipe Prompt: Add Spacefast Zero Comments to a Static WordPress Site

Paste this prompt into Cursor or Claude Code in a local WordPress Studio repository that exports static HTML with Simply Static and publishes to Spacefast:

```markdown
Wire the Roots & Fruit `spacefast-static-comments` package (`C:\Users\reach\OneDrive\RootsAndFruit\company-docs\08-Products\spacefast-static-comments`) into this static WordPress site so every published blog post has live reader comments powered by a server-only Spacefast Zero capsule on the same Space.

Before changing files, check:
1. Where Simply Static writes the static export directory (for example `public_static/` or `static-export/`).
2. Which publish script runs `sf publish` (for example `scripts/shipit.mjs`).
3. Whether `sf.jsonc` or `_headers` / `_redirects` already exist in the export root.

Then complete these steps:

1. **Install and activate the WordPress plugin:**
   - Copy `C:\Users\reach\OneDrive\RootsAndFruit\company-docs\08-Products\spacefast-static-comments\plugin\spacefast-static-comments` into `wp-content/plugins/spacefast-static-comments`.
   - Activate it with `studio wp plugin activate spacefast-static-comments`.
   - Verify on a local single post URL that `<section id="sf-comments" class="sf-comments" data-sf-comments-slug="...">` renders below the article content and that `comments-island.js` and `comments-island.css` are enqueued.

2. **Stage the server-only Zero capsule during ship/export:**
   - In the site's ship script, right after local static export checks pass (and before `sf publish`), run:
     `node "C:\Users\reach\OneDrive\RootsAndFruit\company-docs\08-Products\spacefast-static-comments\scripts\stage-capsule.mjs" --target <EXPORT_DIR> --name "<SITE_NAME>"`
   - Confirm `client/index.tsx` is NOT created in `<EXPORT_DIR>`, and `<EXPORT_DIR>/index.html` is still the WordPress static homepage.

3. **Verify the build and live comment flow:**
   - Run the site's dryrun export (`npm run shipit:dryrun`), verify `sf.jsonc`, `server/index.ts`, and `shared/comments.ts` are staged in `<EXPORT_DIR>/`, and run `sf build <EXPORT_DIR>` to prove the Zero capsule compiles cleanly.
   - Publish to Spacefast (`npm run shipit:publish`), test `GET /api/comments?post=<slug>` and `POST /api/comments` on the live Space, and verify a comment posted on a single post appears in the thread.
```
