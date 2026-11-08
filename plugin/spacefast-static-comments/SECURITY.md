# Security Standards (`spacefast-static-comments`)

This document is the single source of truth for security rules across both `/api/comments` implementations:

1. **Local Studio bridge:** [`includes/local-bridge.php`](includes/local-bridge.php) (WordPress Studio only)
2. **Production Zero capsule:** `company-docs/08-Products/spacefast-static-comments/capsule/` (Spacefast Zero runtime)

Any agent or developer changing [`includes/local-bridge.php`](includes/local-bridge.php), [`assets/comments-island.js`](assets/comments-island.js), or the Zero capsule **must** read this file first and keep both servers in lockstep.

---

## 1. Non-negotiable invariants

1. **The PHP bridge never loads outside a local environment.**
   `sf_static_comments_local_bridge_enabled()` requires `wp_get_environment_type() === 'local'` **and** a local host (`localhost`, `127.0.0.1`, `::1`, `*.local`, `*.test`). Everywhere else, `includes/local-bridge.php` is not `require`d and `/api/comments` is a normal WordPress 404.
2. **The `redirect_canonical` guard stays registered.**
   `sf_static_comments_skip_api_canonical_redirect()` stops WordPress's `redirect_guess_404_permalink` from 301-redirecting `/api/comments` to a post whose slug starts with `comments`.
3. **The `sf dev` proxy is opt-in, loopback-only, and JSON-only.**
   Off unless `sf_static_comments_sf_dev_url` (or `sf_static_comments_local_zero_url`) is set to an `http(s)` loopback URL (`127.0.0.1`, `localhost`, `::1`). It forwards only the sanitized `post` query parameter, runs **after** request validation, and relays only `200–499` responses whose `Content-Type` is `application/json` and whose body decodes to a JSON object/array, re-encoded through `wp_send_json()`.
4. **No backend-identifying response headers.**
   Never send `X-SF-Comments-Backend` or similar diagnostic headers from either server.
5. **POST validation runs in a fixed, cheap-first order.**
   Transport headers (`Content-Type`, `Origin`/`Referer`) → JSON parse → bot traps (`hp`, `t`) → rate limit → payload fields (`post`, `body`, link count) → post resolution → auth gate → author binding & reserved-name check → storage caps. Rejected or throttled requests must stop before expensive database or user queries wherever the order allows.
6. **Rate limit increments only after a successful write.**
   Invalid, spam, or cap-rejected requests never consume the caller's rate-limit budget.
7. **Author identity is bound on the server.**
   - When `viewer.isAuthenticated` is true, the stored author is **always** `viewer.displayName`; client `author` input is ignored.
   - When `viewer.isAuthenticated` is false, `isGuest` is `true` and the trimmed guest name must not match (case-insensitively) any editor/author's `display_name` or `user_login`.
8. **Export manifests never leak private WordPress usernames.**
   Public `display_name` values may ship normalized in `shared/export-manifest.ts`, or alongside salted SHA-256 hashes (`sha256(salt + ":" + mb_strtolower(trim(name)))`) for `user_login` values. Never write raw `user_login` values, emails, or tokens into the static export directory.
9. **Render plain text only in the browser island.**
   `assets/comments-island.js` must build DOM nodes with `textContent` (never `innerHTML` with user data) and keep the off-screen `website` honeypot (`tabindex="-1"`, `autocomplete="off"`, `aria-hidden="true"`) plus `hp` and `t` in the POST body.

---

## 2. Ordered `POST /api/comments` contract

Both servers must evaluate `POST /api/comments` in this order and return `{ error: { code, message } }`:

| Step | Check | HTTP | `error.code` | Filter / Manifest Override | Default |
|---|---|---|---|---|---|
| 1 | `Content-Type` starts with `application/json` | `415` | `unsupported_media_type` | — | required |
| 2 | `Origin` (or `Referer` fallback) host matches site host | `403` | `bad_origin` | — | same host |
| 3 | Request body parses as a JSON object | `400` | `invalid_comment` | — | required |
| 4 | Honeypot `hp` is empty | `422` | `comment_rejected` | — | empty |
| 5 | Fill time `t` is numeric and `>= minFillMs` | `422` | `too_fast` | `sf_static_comments_min_fill_ms` | `2000` ms |
| 6 | Caller is under the rate limit | `429` | `rate_limited` | `sf_static_comments_rate_limit` / `sf_static_comments_rate_window` | `5` per `600` s |
| 7 | `post` slug and `body` (1–1000 chars) are non-empty | `400` | `invalid_comment` | — | required |
| 8 | `body` has `<= 3` `http(s)://` links (plus `ctx.spam.check` on Zero) | `422` | `comment_rejected` | — | max `3` URLs |
| 9 | Target post exists, is published, and has comments open | `404` | `post_not_found` | `allowedPostSlugs` in `export-manifest.ts` | published + open |
| 10 | Authenticated session when auth is required | `401` | `auth_required` | `sf_static_comments_require_auth` / `COMMENTS_REQUIRE_AUTH` | `false` |
| 11 | Guest `author` does not match a reserved author/editor name | `422` | `name_reserved` | `reservedNames` / `reservedNameHashes` | all `edit_posts` users |
| 12 | Per-post and total-post caps not exceeded | `422` | `store_full` | `sf_static_comments_max_per_post` / `sf_static_comments_max_posts` | `200` / `100` |

`GET /api/comments?post=<slug>` returns `400 post_required` when `post` is missing/empty and `404 post_not_found` when the post does not resolve (step 9).

Every comment row in `GET` and `POST` responses includes:
- `id`: UUID v4 string (`wp_generate_uuid4()` in Studio; Zero's native UUID `row.id` in production)
- `author`: bound display name (`1–40` chars)
- `body`: sanitized plain text (`1–1000` chars)
- `mine`: boolean
- `isGuest`: boolean (`true` for unauthenticated callers; rendered with a `guest` badge by the island)
- `createdAt`: ISO-8601 UTC timestamp

---

## 3. Parity matrix (Studio bridge vs. Zero capsule)

| Check ID | Rule | Studio (`includes/local-bridge.php`) | Zero capsule (`capsule/server/index.ts`) |
|---|---|---|---|
| `C01–C05` | GET/POST contract, honeypot `hp`, archive render, clean `debug.log` | Enforced | Enforced |
| `C10` | Bridge disabled outside local env + local host (`404`) | Enforced | N/A (capsule is the production server) |
| `C20–C22` | `sf dev` proxy off by default, loopback-only, valid JSON only | Enforced | N/A |
| `C23` | No `X-SF-Comments-Backend` header | Enforced | Enforced |
| `C30` | Reject non-JSON `Content-Type` (`415 unsupported_media_type`) | Enforced | Enforced |
| `C31–C32` | Require same-host `Origin` or `Referer` (`403 bad_origin`) | Enforced | Enforced |
| `C33–C34` | Require `t >= minFillMs` (`422 too_fast`) | Enforced | Enforced |
| `C35–C37` | Reject unknown or comments-closed slug on GET/POST (`404 post_not_found`) | Enforced via `get_page_by_path()` + `comments_open()` | Enforced via `EXPORT_MANIFEST.allowedPostSlugs` (when populated) + `closedPostSlugs` |
| `C38` | Browser E2E: off-screen `website` honeypot + `hp`/`t` submission | Enforced (`assets/comments-island.js`) | Same island asset |
| `C40–C41` | Reject guest using reserved author/editor name (`422 name_reserved`) | Enforced via `get_users( capability => 'edit_posts' )` | Enforced via `EXPORT_MANIFEST.reservedNames` + salted SHA-256 `reservedNameHashes` |
| `C42` | Ignore client `author` when authenticated; set `isGuest: false` | Enforced | Enforced (`resolveAuthorName`) |
| `C43`, `C49` | Boolean `isGuest` on every GET/POST row + `guest` badge in UI | Enforced | Enforced (`isGuest` column + fallback from `authorId`) |
| `C44` | Rate limit `5` writes per `10` minutes (`429 rate_limited`) | Enforced via transient (`REMOTE_ADDR` + `viewer.userId`) | Enforced via `commentRateLimits` table |
| `C45–C47` | Per-post cap (`200`) and post-slug cap (`100`) (`422 store_full`) | Enforced | Enforced via `by_post` count + `EXPORT_MANIFEST.maxPosts` |
| `C48` | Distinct UUID v4 comment IDs | Enforced (`wp_generate_uuid4()`) | Enforced (Zero native `randomUUID()` row IDs) |

---

## 4. Verification rules for agents

- **Run the matrix** from the Studio site root after any change to the bridge, island, or capsule:
  ```powershell
  powershell -ExecutionPolicy Bypass -File tools/ssc-security-matrix.ps1 -Phase 5
  ```
- **Never edit `tools/ssc-security-expectations.json` to make a failing check pass.** If a check fails, fix the implementation. If you believe an expectation is wrong, stop and ask Matt before touching the expectations file or harness.
- **When adding a new security rule**, add the check to `tools/ssc-security-expectations.json` and `tools/ssc-security-matrix.mjs` first, update both servers, and update the tables in this file.
