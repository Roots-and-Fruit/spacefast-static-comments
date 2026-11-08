=== Spacefast Static Comments ===
Contributors: webdevmattcrom
Requires at least: 6.5
Tested up to: 7.2
Requires PHP: 8.1
Requires Plugins: spacefast-wordpress, simply-static
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: comments, static, simply-static, spacefast

Bake approved WordPress comment threads into static HTML exports and run a hardened Spacefast Zero live comment server on your Space.

== Description ==

Static WordPress exports are fast and durable, until someone wants to leave a comment. Most sites either turn comments off or bolt on a heavy third-party embed that clashes with the theme and hands reader data to an ad network.

Spacefast Static Comments keeps WordPress as your content archive and uses your own Spacefast Space for live reader comments:

* **Approved WordPress comments bake straight into the HTML.** Existing threads (including nested replies up to 6 levels deep and author badges) are rendered into each post at export time, so search engines and readers see the conversation with zero JavaScript required.
* **A live comment form mounts right below the archive.** New comments post to `/api/comments` on the exact same origin as your static blog, inheriting your theme's typography and colors cleanly.
* **1-click Zero server provisioning in wp-admin.** Open **Settings → Spacefast Comments** (right below **Settings → Spacefast**) and click **Provision Zero Comment Server on This Space**. Every future Simply Static → Spacefast publish updates the allowed-post manifest automatically—no Node.js or `sf` CLI needed.
* **Hardened by default.** Both the local WordPress Studio bridge and the live Spacefast Zero capsule enforce the same 12-step security contract: strict `Origin` and `application/json` checks, an off-screen `website` honeypot, minimum form fill-time validation, per-caller rate limiting, open-post verification, server-bound SSO display names, reserved editor name and salted SHA-256 login protection, and per-post storage caps.

== Installation ==

1. Install and activate **Spacefast** (`spacefast-wordpress`) and **Simply Static** (`simply-static` or `simply-static-pro`).
2. Upload `spacefast-static-comments` to `/wp-content/plugins/` and activate it.
3. Connect your Space in **Settings → Spacefast**.
4. Open **Settings → Spacefast Comments** and click **Provision Zero Comment Server on This Space**.

== Changelog ==

= 0.3.0 =
Released 2026-11-08.

**Added**

* **Settings → Spacefast Comments** sits right under **Settings → Spacefast** in `wp-admin`. It has three folder tabs (**Zero Server**, **Settings & Security**, and **Activity**), a sticky Roots and Fruit footer, and an About card.
* The **Zero Server** tab reads the Space connected in `spacefast-wordpress`, shows its connection and sync status, and lets a site owner provision or update the Spacefast Zero comment server on that Space with one click. It also lists the active security manifest counts and shows the compiled `shared/export-manifest.ts` code with a copy button.
* Every Simply Static → Spacefast publish now stages the pre-compiled Zero comment capsule (`sf.jsonc`, `__spacefast/zero/deploy.json`, and platform assets) and attaches the site's compiled Zero metadata to the Spacefast version upload automatically, with no Node.js or `sf` CLI needed on the WordPress server.
* The **Settings & Security** tab adds switches to mount the live comment form on production exports and to require a signed-in session to comment, plus number fields for minimum form fill time, per-caller rate limit and window, maximum comments per post, maximum distinct post slugs, and an optional local `sf dev` proxy URL.
* The **Activity** tab records recent Zero server provisioning, static export staging, and security setting changes.
* Guest comments now carry an `isGuest` flag in `/api/comments` responses and display a `guest` badge next to the author name in the live comment thread.
* `SECURITY.md` documents the 12-step `POST /api/comments` validation order, error codes, filter names, and parity matrix between the Studio local bridge and the Spacefast Zero capsule.

**Changed**

* **Breaking:** Activating Spacefast Static Comments now requires both `spacefast-wordpress` and Simply Static (`simply-static` or `simply-static-pro`). If either plugin is deactivated later, Spacefast Static Comments deactivates itself and shows an admin notice instead of running half-configured.
* `assets/comments-island.css` now ships structural layout rules only (list resets, reply indentation, form layout, and focus outlines) so comment threads inherit typography, colors, and borders from the active theme.
* New comment IDs in the local Studio store use random UUID v4 strings instead of sequential numbers.

**Security**

* The local `/api/comments` bridge (`includes/local-bridge.php`) now loads only when `wp_get_environment_type()` is `local` and the request host is local (`localhost`, `127.0.0.1`, `::1`, `*.local`, or `*.test`). On any non-local environment, `/api/comments` returns a normal WordPress 404 without redirecting to a post slug.
* The local `sf dev` proxy is now off by default, accepts only loopback `http(s)` URLs (`127.0.0.1`, `localhost`, `::1`) when configured, runs after request validation, forwards only the sanitized `post` slug, and relays only valid JSON responses.
* Removed the `X-SF-Comments-Backend` response header so responses do not advertise which backend answered.
* Both the Studio bridge and the Spacefast Zero capsule now enforce the same 12-step `POST /api/comments` check order: `Content-Type: application/json` (`415 unsupported_media_type`), same-host `Origin` or `Referer` (`403 bad_origin`), JSON object body (`400 invalid_comment`), empty `website` honeypot (`422 comment_rejected`), minimum 2-second form fill time (`422 too_fast`), caller rate limit of 5 comments per 10 minutes incremented only after a successful write (`429 rate_limited`), non-empty `post` and `body` (`400 invalid_comment`), at most 3 links (`422 comment_rejected`), published post with open comments (`404 post_not_found`), authentication gate when enabled (`401 auth_required`), server-bound author names, and per-post (200) and total-post (100) storage caps (`422 store_full`).
* Signed-in commenters always post under their account's `display_name` (ignoring any client-supplied `author` field). Guests cannot post under the `display_name` or `user_login` of any user who can edit posts (`422 name_reserved`), and private WordPress `user_login` values are exported to the Zero manifest only as salted SHA-256 hashes.

= 0.2.0 =
Released 2026-11-07.

**Added**

* First packaged release: renders approved WordPress comment threads (up to 6 levels deep) into Simply Static HTML exports, mounts the live Spacefast Zero comment island below post content, adds a comment count link to the post byline, and provides a local Studio `/api/comments` bridge and server-only Zero capsule.

== Upgrade Notice ==

= 0.3.0 =
Adds Settings -> Spacefast Comments with 1-click Zero server provisioning, automatic Simply Static publish hooks, and the 12-step /api/comments security hardening across Studio and Spacefast Zero. Requires active spacefast-wordpress and Simply Static plugins.
