# Spacefast Static Comments (`spacefast-static-comments`)

Reusable Roots & Fruit package that adds live reader comments to a static WordPress site hosted on Spacefast, using a server-only Spacefast Zero capsule on the same Space.

WordPress stays the content editor. Simply Static still writes the flat HTML export. Spacefast Zero owns the `comments` database table, spam filtering, guest/SSO gate, and `/api/comments` endpoint on the same origin.

## What is in this package

| Path | Job |
|------|-----|
| `capsule/sf.jsonc` | Server-only Zero config (`runtime.server` + absent `runtime.client` so the static homepage is never shadowed) |
| `capsule/server/index.ts` | Zero capsule: `comments` table indexed by `postSlug`, `comments` + `commentConfig` queries, `addComment` mutation (`ctx.spam.check`, length limits, `COMMENTS_REQUIRE_AUTH` gate), and `GET`/`POST /api/comments` |
| `capsule/shared/comments.ts` | Shared types and input cleaners (`cleanSlug`, `cleanName`, `cleanBody`, length constants) |
| `plugin/spacefast-static-comments/` | WordPress plugin that renders the comment mount (`[data-sf-comments-slug]`) on singular posts and enqueues the self-contained browser island (`comments-island.js` + `comments-island.css`) so Simply Static exports both |
| `scripts/stage-capsule.mjs` | Stages `sf.jsonc`, `server/`, and `shared/` into a static export directory (`public_static/`) before `sf publish` |
| `AGENTS.md` | Agent instructions and invariants for maintaining or installing this package |
| `prompt.md` | Copy-paste recipe prompt to wire this package onto another static WordPress → Spacefast site |

## How it works

1. **In WordPress (Studio):** When `spacefast-static-comments` is active, singular `post` pages render a `<section class="sf-comments" data-sf-comments-slug="<post_name>">` after post content and enqueue `comments-island.js` and `comments-island.css`.
2. **During static export:** Simply Static crawls the site and copies the post HTML plus the plugin's JS and CSS assets into the export folder (for example `public_static/`).
3. **Before publish (`scripts/stage-capsule.mjs`):** Copies `sf.jsonc`, `server/`, and `shared/` into the export root. Because `client/index.tsx` is intentionally absent on disk, Spacefast compiles the capsule in server-only mode and serves your static `index.html` and post HTML untouched.
4. **Live on Spacefast:** The browser island calls `GET /api/comments?post=<slug>` and `POST /api/comments` on the same origin. New comments run through `ctx.spam.check`, save to the Space's MySQL database, and appear immediately.
5. **SSO toggle (`COMMENTS_REQUIRE_AUTH`):** By default, guests can post with a display name. Setting `sf env set COMMENTS_REQUIRE_AUTH 1` on the Space requires an authenticated Spacefast session (`ctx.auth.isAuthenticated`) before accepting writes, while keeping comment reading public.

## Local vs live workflow

- **Local WordPress (Studio):** Write posts and preview layout at your Studio URL. Because Studio is PHP/SQLite and not the Zero runtime, the comment island shows a quiet local-preview note when `/api/comments` is not present on the Studio origin.
- **Local Zero capsule development:**
  ```powershell
  sf dev --dir capsule --state-backend sqlite
  ```
  Test the capsule directly against `http://127.0.0.1:4173/api/comments?post=hello-world`.
- **Live (or staging) Space:** Run the site's `shipit` flow (Simply Static export → `stage-capsule.mjs` → `sf publish`). Open a published post in two browser windows, post a comment in one, and confirm it renders live.
