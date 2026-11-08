/**
 * Build-time WordPress export manifest for the Spacefast Zero comments capsule.
 *
 * Why a `.ts` module instead of `.json`:
 * `@spacefast/zero-compile` bundles only code files (`.ts`, `.tsx`, `.js`, `.mjs`)
 * under `server/` and `shared/`. Plain `.json` files in `shared/` are ignored by
 * `isAllowedSourceManifestPath()`. Generating this module at export/stage time
 * bakes the site's post allow-list, reserved author names, and filter limits
 * directly into the compiled capsule bundle without shipping a public static file.
 *
 * Pure TypeScript only — no DOM globals, no Node built-ins, no external imports.
 */

export type CommentsExportManifest = {
  /**
   * When `true`, `allowedPostSlugs` is authoritative and any slug not in the list
   * (or in `closedPostSlugs`) is rejected with `404 post_not_found`.
   * When `false` (default un-staged template), only `closedPostSlugs` are rejected.
   */
  readonly enforceAllowedPosts: boolean;
  /** Published `post` slugs (`post_name`) that have comments open and mount the live island. */
  readonly allowedPostSlugs: readonly string[];
  /** Slugs explicitly known to have comments closed. */
  readonly closedPostSlugs: readonly string[];
  /**
   * Lowercased, whitespace-collapsed public `display_name` values of users with `edit_posts`.
   * Guests cannot post under these names (`422 name_reserved`).
   */
  readonly reservedNames: readonly string[];
  /**
   * Per-export random salt used to hash private `user_login` values so raw logins
   * never appear in the export directory.
   */
  readonly reservedNameSalt: string;
  /**
   * Lowercase hex SHA-256 digests of `${reservedNameSalt}:${normalizedLogin}` for
   * every user with `edit_posts`. Checked via `ctx.crypto.sha256()` on guest POSTs.
   */
  readonly reservedNameHashes: readonly string[];
  /** Minimum form fill time (`t`) in milliseconds (`sf_static_comments_min_fill_ms`). */
  readonly minFillMs: number;
  /** Maximum comments per post (`sf_static_comments_max_per_post`). */
  readonly maxPerPost: number;
  /** Maximum distinct post slugs in the store (`sf_static_comments_max_posts`). */
  readonly maxPosts: number;
  /** Maximum writes per caller within `rateWindowSeconds` (`sf_static_comments_rate_limit`). */
  readonly rateLimit: number;
  /** Rate-limit sliding window in seconds (`sf_static_comments_rate_window`). */
  readonly rateWindowSeconds: number;
};

export const EXPORT_MANIFEST: CommentsExportManifest = {
  enforceAllowedPosts: false,
  allowedPostSlugs: [],
  closedPostSlugs: [],
  reservedNames: [],
  reservedNameSalt: "",
  reservedNameHashes: [],
  minFillMs: 2000,
  maxPerPost: 200,
  maxPosts: 100,
  rateLimit: 5,
  rateWindowSeconds: 600,
};
