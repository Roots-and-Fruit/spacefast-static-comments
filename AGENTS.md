# AGENTS.md — Spacefast Static Comments

## Purpose

`spacefast-static-comments` is the canonical Roots & Fruit package for adding reader comments to a Simply Static WordPress site hosted on Spacefast.

## Hard invariants

1. **Never create `capsule/client/index.tsx`.**
   `capsule/sf.jsonc` declares `"client": "client/index.tsx"` because the `sf.jsonc` schema requires `runtime.client` whenever `runtime.kind` is `"zero"`. Keeping `client/index.tsx` absent on disk forces Spacefast Zero into **server-only** mode so it does not generate an app shell that shadows `index.html`.
2. **Keep `capsule/shared/` pure TypeScript.**
   No DOM globals (`window`, `document`), no Node built-ins, and no external package imports. Both `capsule/server/index.ts` and any client code can import it safely.
3. **Never edit `server/index.ts` or `sf.jsonc` inside `public_static/` by hand.**
   Edit `capsule/` in this package (or the site's tracked override) and let `scripts/stage-capsule.mjs` stage them into the export directory before `sf publish`.
4. **Post identity is always the post slug (`post_name`).**
   Never key comments by WordPress numeric `post_ID`, so URLs and comment threads survive migrations and re-imports.
5. **Guest-first, SSO-ready.**
   When `ctx.env.COMMENTS_REQUIRE_AUTH` is `"1"` or `"true"`, `addComment` and `POST /api/comments` reject unauthenticated callers (`!ctx.auth.isAuthenticated`) with `401 auth_required`, and `GET /api/comments` reports `requireAuth: true` plus `viewer.isAuthenticated` so the island can prompt the reader to sign in.
6. **Follow the canonical security contract (`SECURITY.md`).**
   Before changing `capsule/server/index.ts`, `capsule/shared/comments.ts`, `capsule/shared/export-manifest.ts`, or the plugin bridge/island, read `plugin/spacefast-static-comments/SECURITY.md` (mirrored in Studio at `wp-content/plugins/spacefast-static-comments/SECURITY.md`). Keep the 12-step POST validation order, server-bound author rules, rate/cap limits, and error codes identical across both servers.

## Commands

### Test the capsule locally

```powershell
cd "C:\Users\reach\OneDrive\RootsAndFruit\company-docs\08-Products\spacefast-static-comments"
sf build capsule
```

### Stage into a static export directory

```powershell
node "C:\Users\reach\OneDrive\RootsAndFruit\company-docs\08-Products\spacefast-static-comments\scripts\stage-capsule.mjs" `
  --target "C:\Users\reach\Studio\mccom\public_static" `
  --name "mattcromwell.com"
```
