/**
 * Pure shared rules for static WordPress post comments on Spacefast Zero.
 *
 * Imported by `server/index.ts` (and safe to mirror in browser islands).
 * Contains pure string helpers only — no browser globals, no Node built-ins.
 */

import { EXPORT_MANIFEST, type CommentsExportManifest } from "./export-manifest";

export const MAX_NAME_LENGTH = 40;
export const MAX_BODY_LENGTH = 1000;
export const MAX_SLUG_LENGTH = 120;
export const COMMENT_PAGE_SIZE = 200;

export type CommentItem = {
  id: string;
  postSlug: string;
  authorName: string;
  body: string;
  authorId: string;
  isGuest?: boolean;
  createdAt: string;
  updatedAt: string;
};

export type CommentThreadPayload = {
  post: string;
  count: number;
  requireAuth: boolean;
  viewer: {
    userId: string;
    displayName: string;
    isAuthenticated: boolean;
  };
  comments: Array<{
    id: string;
    author: string;
    body: string;
    mine: boolean;
    isGuest: boolean;
    createdAt: string;
  }>;
};

function collapseWhitespace(value: string): string {
  return value.replace(/\s+/g, " ").trim();
}

export function cleanName(value: string): string {
  return collapseWhitespace(value).slice(0, MAX_NAME_LENGTH);
}

/** Normalize a display name or login for case-insensitive reserved-name comparison. */
export function normalizeIdentityName(value: string): string {
  return collapseWhitespace(value).toLowerCase();
}

/** Preserves paragraph breaks while collapsing excess inline whitespace. */
export function cleanBody(value: string): string {
  return value
    .replace(/\r\n/g, "\n")
    .split(/\n{2,}/)
    .map((paragraph) => collapseWhitespace(paragraph))
    .filter((paragraph) => paragraph !== "")
    .join("\n\n")
    .slice(0, MAX_BODY_LENGTH);
}

export function cleanSlug(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/^\/+|\/+$/g, "")
    .replace(/[^a-z0-9-_]/g, "")
    .slice(0, MAX_SLUG_LENGTH);
}

/**
 * Legacy helper kept for compatibility; for authenticated users prefer
 * `resolveAuthorName()`, which always binds the server-verified display name.
 */
export function displayNameFor(name: string, fallback: string): string {
  return cleanName(name) || cleanName(fallback) || "Guest";
}

/**
 * Server-bound author resolution (SECURITY.md invariant #7):
 * - Authenticated users always post as their account `displayName` (client input ignored).
 * - Guests use their sanitized `clientName` or `"Guest"`.
 */
export function resolveAuthorName(
  clientName: string,
  viewer: { displayName: string; isAuthenticated: boolean },
): string {
  if (viewer.isAuthenticated) {
    return cleanName(viewer.displayName) || "Member";
  }
  return cleanName(clientName) || "Guest";
}

/** Whether a stored row represents an unauthenticated guest comment. */
export function isGuestCommentRow(row: { isGuest?: boolean; authorId?: string }): boolean {
  if (typeof row.isGuest === "boolean") {
    return row.isGuest;
  }
  const authorId = String(row.authorId ?? "");
  return authorId === "" || authorId === "guest" || authorId.startsWith("guest:");
}

/**
 * Check whether a target post slug is open for comments under the export manifest.
 */
export function isPostSlugOpen(
  slug: string,
  manifest: CommentsExportManifest = EXPORT_MANIFEST,
): boolean {
  if (!slug) return false;
  if (manifest.closedPostSlugs.includes(slug)) return false;
  if (manifest.enforceAllowedPosts) {
    return manifest.allowedPostSlugs.includes(slug);
  }
  return true;
}

export function isAuthRequired(envValue: string | undefined): boolean {
  if (!envValue) return false;
  const normalized = envValue.trim().toLowerCase();
  return normalized === "1" || normalized === "true" || normalized === "yes" || normalized === "on";
}
