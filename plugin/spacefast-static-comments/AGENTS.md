# Spacefast Static Comments

Plugin that bakes approved WordPress comment threads into static HTML (Simply Static /
Spacefast export) and mounts a live Spacefast Zero comment island underneath them.
Locally in Studio it also answers `/api/comments` so the island works without publishing.

Prefix: `sf_static_comments_` / `SF_STATIC_COMMENTS_`. Text domain: `spacefast-static-comments`.

**Requires** active `spacefast-wordpress` and Simply Static free (`simply-static`) or Pro
(`simply-static-pro`). Declared via `Requires Plugins` plus an activation/`admin_init` guard.

## Layout

| Path | Role |
|---|---|
| `spacefast-static-comments.php` | Bootstrap, render, byline pill, shortcode, local-bridge gate |
| `includes/admin-settings.php` | `Settings → Spacefast Comments` screen (R&F admin UI, folder tabs, toggles, activity log) |
| `includes/zero-provisioner.php` | Pure-PHP Zero capsule compiler, `EXPORT_MANIFEST` generator, direct provisioner, and Simply Static / `spacefast-wordpress` publish hooks |
| `includes/capsule/` | Bundled Zero capsule sources + pre-compiled `finalize-template.json.gz` |
| `includes/local-bridge.php` | Local `/api/comments` bridge (loaded only when the gate passes) |
| `SECURITY.md` | Canonical security invariants, ordered POST contract, and Studio/Zero parity matrix |
| `admin/` | Roots & Fruit admin stylesheet, script, Domine/Nunito Sans fonts, and brand marks |
| `assets/comments-island.js` | Front-end island: fetch/post/poll `/api/comments` |
| `assets/comments-island.css` | Comment archive + island styles |

Keep behavior in this plugin. Theme presentation (byline molecule, article frame, comment
card chrome) stays in the consuming site theme.

`assets/comments-island.css` is structural only (list reset, nest indent, form layout,
focus outlines). It does not ship brand colors or card recipes. Themes style
`.sf-comments*` / `.sf-byline-comments` or override optional `--ssc-*` spacing tokens.

## Settled behavior

- **Archive is WordPress comments.** Approved `comment` types for the singular post are
  rendered into HTML at export time. Nested replies go up to depth 6; orphans promote to
  top-level.
- **Live island is conditional.** Mounts on local / staging by default. Production export
  stays off until `sf_static_comments_enable_production` is true, or
  `sf_static_comments_enabled` filters it on.
- **Section lives inside `core/post-content`.** That keeps two-column article frames
  intact. Shortcode `[spacefast_comments]` is the escape hatch for custom templates.
- **Local API bridge.** `includes/local-bridge.php` loads only when
  `sf_static_comments_local_bridge_enabled()` passes. That requires the environment type
  to be `local` and the host to be `localhost`, `127.0.0.1`, `::1`, `*.local`, or `*.test`.
  The filter `sf_static_comments_local_bridge_enabled` can override it. Everywhere else,
  `/api/comments` is a normal WordPress 404. The bridge answers from the option store
  `sf_static_comments_local_store`, which is not autoloaded. Responses use the capsule's
  `{ data }` / `{ error: { code, message } }` shape and must stay compatible with it.
- **`sf dev` proxy is opt-in.** It's off by default. To turn it on, set the option
  `sf_static_comments_sf_dev_url` or the filter `sf_static_comments_local_zero_url`.
  Only loopback `http(s)` URLs are accepted. The proxy forwards only the `post` slug and
  relays only valid JSON with a 2xx–4xx status. A request is validated before it is
  proxied.
- **Security contract (`SECURITY.md`).** Read [`SECURITY.md`](SECURITY.md) before changing
  `includes/local-bridge.php`, `assets/comments-island.js`, or the Zero capsule. It defines
  the 12-step POST validation order, error codes, filter names, server-bound author rules,
  and the Studio/Zero parity matrix.
- **Export target option.** `sf_static_comments_export_target` is `local` | `staging` |
  `production`.
- **Admin UI & Zero Provisioning (`Settings → Spacefast Comments`).** Positioned right below
  `Settings → Spacefast`. Uses the Roots & Fruit admin UI from Agent Role (`admin/css/ssc-admin.css`).
  Reads the connected Space from `Spacefast_Settings::get()` without modifying
  `spacefast-wordpress`. Clicking **Provision Zero Comment Server** (or running any Simply Static
  → Spacefast publish) injects the site's `EXPORT_MANIFEST` (published open post slugs, reserved
  editor display names, salted SHA-256 `user_login` digests, and rate/cap thresholds) into the
  bundled `includes/capsule/finalize-template.json.gz`, stages `sf.jsonc` + `__spacefast/zero/deploy.json`
  + platform chunks, and attaches `sourceMetadata.statticZero.finalize` to `POST /v1/spaces/{id}/versions`.

## Easy to break

- Do not move the comments section outside `.wp-block-post-content` without checking
  sticky sidebar / article-frame layouts.
- Island JS expects `data-sf-comments-slug` on `#sf-comments` and
  `[data-sf-comments-mount]` inside it.
- **Don't reorder the POST checks.** Transport and bot checks run before any database
  work. The rate counter increments only after a successful write, so rejected posts
  don't use up the limit.
- **The island must keep sending `hp` and `t`.** Without them, every post fails with
  `too_fast`. The `website` field stays off-screen and outside the tab order; don't
  switch it to `display:none`.
- **`/api/comments` needs the canonical-redirect guard.** Without the `redirect_canonical`
  filter, WordPress's 404 guessing redirects `/api/comments` to a post with a similar
  slug when the bridge is off.
- **The response helpers never return.** `sf_static_comments_bridge_error()` and
  `sf_static_comments_bridge_respond()` exit the request. The handlers rely on that.
- **Run the security harness after any bridge change.**
  `powershell -ExecutionPolicy Bypass -File tools/ssc-security-matrix.ps1 -Phase 5` from
  the site root takes about 3 minutes and restores the store when it finishes.
- Sanitize on write, escape on render. Comment bodies go through `esc_html` →
  `make_clickable` → `wpautop` → link normalization.

## Local checks

```bash
studio wp plugin list --name=spacefast-static-comments --status=active
studio wp eval 'echo function_exists("sf_static_comments_should_render") ? "ok" : "missing";'
# Live API (Studio local store, or opted-in sf dev proxy):
# GET https://sfsc.wp.local/api/comments?post=<slug>
```

Use a singular post with comments open. Fixture post: slug `comments-playground`
(URL from `studio wp post list --name=comments-playground --field=url`).
