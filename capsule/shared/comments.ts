/**
 * Pure shared rules for static WordPress post comments on Spacefast Zero.
 *
 * Imported by `server/index.ts` (and safe to mirror in browser islands).
 * Contains pure string helpers only — no browser globals, no Node built-ins.
 */

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
    createdAt: string;
  }>;
};

function collapseWhitespace(value: string): string {
  return value.replace(/\s+/g, " ").trim();
}

export function cleanName(value: string): string {
  return collapseWhitespace(value).slice(0, MAX_NAME_LENGTH);
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

export function displayNameFor(name: string, fallback: string): string {
  return cleanName(name) || cleanName(fallback) || "Guest";
}

export function isAuthRequired(envValue: string | undefined): boolean {
  if (!envValue) return false;
  const normalized = envValue.trim().toLowerCase();
  return normalized === "1" || normalized === "true" || normalized === "yes" || normalized === "on";
}
