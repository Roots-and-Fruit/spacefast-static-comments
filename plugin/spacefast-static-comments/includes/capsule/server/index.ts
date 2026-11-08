import {
  boolean,
  capsule,
  endpoint,
  json,
  mutation,
  number,
  query,
  string,
  table,
  type EndpointRequest,
  type EndpointResponse,
} from "@spacefast/zero/server";

import {
  cleanBody,
  cleanSlug,
  COMMENT_PAGE_SIZE,
  isAuthRequired,
  isGuestCommentRow,
  isPostSlugOpen,
  normalizeIdentityName,
  resolveAuthorName,
  type CommentItem,
} from "../shared/comments";
import { EXPORT_MANIFEST } from "../shared/export-manifest";

type RateLimitRow = {
  id: string;
  keyHash: string;
  hits: number;
  windowStartMs: number;
  createdAt: string;
  updatedAt: string;
};

/**
 * Extract the lowercased hostname from a URL or Origin/Referer header without
 * relying on platform-specific URL globals that may differ across sandboxes.
 */
function extractUrlHost(rawUrl: string): string {
  const trimmed = rawUrl.trim();
  const match = /^https?:\/\/([^/?#]+)/i.exec(trimmed);
  if (!match || !match[1]) return "";
  const authority = match[1].toLowerCase();
  if (authority.startsWith("[")) {
    const closeBracket = authority.indexOf("]");
    return closeBracket > 1 ? authority.slice(1, closeBracket) : authority;
  }
  return authority.replace(/:\d+$/, "");
}

function requestExpectedHost(req: EndpointRequest): string {
  const fromUrl = extractUrlHost(req.url);
  if (fromUrl) return fromUrl;
  const hostHeader = (req.headers.get("x-forwarded-host") ?? req.headers.get("host") ?? "")
    .split(",")[0]
    .trim()
    .toLowerCase();
  if (hostHeader.startsWith("[")) {
    const closeBracket = hostHeader.indexOf("]");
    return closeBracket > 1 ? hostHeader.slice(1, closeBracket) : hostHeader;
  }
  return hostHeader.replace(/:\d+$/, "");
}

function hasExceedingUrlCount(text: string): boolean {
  const urlMatches = text.match(/https?:\/\//gi);
  return Boolean(urlMatches && urlMatches.length > 3);
}

/**
 * Check spam via `ctx.spam.check` when the runtime provides the connector,
 * falling back to safe local heuristics when `ctx.spam` is not bound.
 */
async function isSpamComment(
  ctx: {
    spam?: {
      check?: (input: { content: string; type: string; authorName: string }) => Promise<{ spam?: boolean }>;
    };
  },
  text: string,
  author: string,
): Promise<boolean> {
  if (hasExceedingUrlCount(text)) {
    return true;
  }
  if (ctx.spam && typeof ctx.spam.check === "function") {
    try {
      const verdict = await ctx.spam.check({ content: text, type: "comment", authorName: author });
      if (verdict && verdict.spam) return true;
    } catch {
      // Fall through when the platform spam service is unbound in local dev.
    }
  }
  return false;
}

/**
 * Check whether a guest display name collides with a site editor/author's
 * public `display_name` or salted `user_login` hash (`SECURITY.md` step 11).
 */
async function isGuestNameReserved(
  ctx: { crypto: { sha256(data: string): Promise<string> } },
  authorName: string,
): Promise<boolean> {
  const normalized = normalizeIdentityName(authorName);
  if (!normalized) return false;

  if (EXPORT_MANIFEST.reservedNames.includes(normalized)) {
    return true;
  }

  if (EXPORT_MANIFEST.reservedNameSalt && EXPORT_MANIFEST.reservedNameHashes.length > 0) {
    const digest = await ctx.crypto.sha256(`${EXPORT_MANIFEST.reservedNameSalt}:${normalized}`);
    if (EXPORT_MANIFEST.reservedNameHashes.includes(digest)) {
      return true;
    }
  }

  return false;
}

/**
 * Compute a privacy-preserving caller key for rate limiting (`SECURITY.md` step 6).
 */
async function callerRateKeyHash(
  ctx: {
    auth: { userId?: string | null; isAuthenticated?: boolean };
    crypto: { sha256(data: string): Promise<string> };
  },
  req?: EndpointRequest,
): Promise<string> {
  const viewerId = ctx.auth.isAuthenticated && ctx.auth.userId ? `user:${ctx.auth.userId}` : "guest";
  const forwardedIp = (
    req?.headers.get("cf-connecting-ip") ??
    req?.headers.get("x-real-ip") ??
    req?.headers.get("x-forwarded-for") ??
    ""
  )
    .split(",")[0]
    .trim();
  const originHint = req?.headers.get("origin") ?? req?.headers.get("referer") ?? "";
  return ctx.crypto.sha256(`rl:${forwardedIp || "local"}|${viewerId}|${extractUrlHost(originHint)}`);
}

/**
 * Check whether the caller has already exhausted their rate-limit window.
 * Does NOT increment the counter (`SECURITY.md` invariant #6).
 */
async function getActiveRateLimitRow(
  db: {
    commentRateLimits: {
      withIndex(
        index: string,
        range: (q: { eq(field: string, value: string): unknown }) => unknown,
      ): { first(): Promise<RateLimitRow | null> };
    };
  },
  keyHash: string,
  nowMs: number,
): Promise<{ blocked: boolean; row: RateLimitRow | null }> {
  const existing = await db.commentRateLimits
    .withIndex("by_key", (q) => q.eq("keyHash", keyHash))
    .first();

  const windowMs = Math.max(1, EXPORT_MANIFEST.rateWindowSeconds) * 1000;
  if (!existing) {
    return { blocked: false, row: null };
  }

  if (nowMs - existing.windowStartMs >= windowMs) {
    return { blocked: false, row: existing };
  }

  return {
    blocked: existing.hits >= EXPORT_MANIFEST.rateLimit,
    row: existing,
  };
}

/**
 * Increment the caller's rate-limit counter AFTER a comment write succeeds.
 */
async function recordSuccessfulWriteRateHit(
  db: {
    commentRateLimits: {
      insert(value: { keyHash: string; hits: number; windowStartMs: number }): Promise<unknown>;
      update(
        id: string,
        patch: { hits?: number; windowStartMs?: number },
      ): Promise<unknown>;
    };
  },
  keyHash: string,
  existing: RateLimitRow | null,
  nowMs: number,
): Promise<void> {
  const windowMs = Math.max(1, EXPORT_MANIFEST.rateWindowSeconds) * 1000;
  if (!existing) {
    await db.commentRateLimits.insert({
      keyHash,
      hits: 1,
      windowStartMs: nowMs,
    });
    return;
  }

  const expired = nowMs - existing.windowStartMs >= windowMs;
  await db.commentRateLimits.update(existing.id, {
    hits: expired ? 1 : existing.hits + 1,
    windowStartMs: nowMs,
  });
}

/**
 * Enforce per-post (`maxPerPost`) and store-wide distinct-post (`maxPosts`) caps
 * (`SECURITY.md` step 12).
 */
async function isStoreCapExceeded(
  db: {
    comments: {
      withIndex(
        index: string,
        range?: (q: { eq(field: string, value: string): unknown }) => unknown,
      ): {
        count(): Promise<number>;
        take(limit: number): Promise<CommentItem[]>;
      };
    };
  },
  slug: string,
): Promise<boolean> {
  const postCount = await db.comments
    .withIndex("by_post", (q) => q.eq("postSlug", slug))
    .count();

  if (postCount >= EXPORT_MANIFEST.maxPerPost) {
    return true;
  }

  // If this slug already has at least one comment, it does not add a new post slug.
  if (postCount > 0) {
    return false;
  }

  const sample = await db.comments.withIndex("by_post").take(1000);
  const distinctSlugs = new Set<string>();
  for (const row of sample) {
    if (row.postSlug) distinctSlugs.add(row.postSlug);
    if (distinctSlugs.size >= EXPORT_MANIFEST.maxPosts) {
      return true;
    }
  }

  return false;
}

function errorResponse(code: string, message: string, status: number): EndpointResponse {
  return json({ error: { code, message } }, { status });
}

/**
 * Server-only Spacefast Zero capsule for static WordPress post comments.
 *
 * Enforces the 12-step security contract in `SECURITY.md` in parity with the
 * WordPress Studio local bridge (`includes/local-bridge.php`).
 */
export default capsule({
  name: "StaticWPComments",
  schema: {
    comments: table({
      postSlug: string(),
      authorName: string(),
      body: string(),
      authorId: string(),
      isGuest: boolean().default(true),
    }).index("by_post", ["postSlug"]),

    commentRateLimits: table({
      keyHash: string(),
      hits: number(),
      windowStartMs: number(),
    }).index("by_key", ["keyHash"]),
  },

  queries: {
    comments: query(async (ctx, postSlug: string): Promise<CommentItem[]> => {
      const slug = cleanSlug(postSlug);
      if (!slug || !isPostSlugOpen(slug)) return [];
      const rows = await ctx.db.comments
        .withIndex("by_post", (row) => row.eq("postSlug", slug))
        .order("desc")
        .take(COMMENT_PAGE_SIZE);
      return (rows as CommentItem[]).reverse();
    }),

    commentConfig: query(async (ctx) => {
      const requireAuth = isAuthRequired(ctx.env.COMMENTS_REQUIRE_AUTH);
      return {
        requireAuth,
        viewer: {
          userId: ctx.auth.userId ?? "",
          displayName: ctx.auth.displayName ?? "",
          isAuthenticated: Boolean(ctx.auth.isAuthenticated),
        },
      };
    }),
  },

  mutations: {
    addComment: mutation(
      async (ctx, postSlug: string, authorName: string, body: string): Promise<CommentItem | null> => {
        const nowMs = Date.now();
        const rateKey = await callerRateKeyHash(ctx);
        const rateState = await getActiveRateLimitRow(ctx.db, rateKey, nowMs);
        if (rateState.blocked) {
          throw new Error("You are commenting quickly. Please wait a few minutes and try again.");
        }

        const slug = cleanSlug(postSlug);
        const text = cleanBody(body);
        if (!slug || !text) return null;
        if (!isPostSlugOpen(slug)) return null;

        const isAuthenticated = Boolean(ctx.auth.isAuthenticated);
        if (isAuthRequired(ctx.env.COMMENTS_REQUIRE_AUTH) && !isAuthenticated) {
          throw new Error("Sign in is required to leave a comment.");
        }

        const name = resolveAuthorName(authorName, {
          displayName: ctx.auth.displayName ?? "",
          isAuthenticated,
        });
        if (!isAuthenticated && (await isGuestNameReserved(ctx, name))) {
          throw new Error("That name belongs to someone on this site. Please use a different one.");
        }

        if (await isSpamComment(ctx, text, name)) return null;
        if (await isStoreCapExceeded(ctx.db, slug)) return null;

        const isGuest = !isAuthenticated;
        const row = await ctx.db.comments.insert({
          postSlug: slug,
          authorName: name,
          body: text,
          authorId: isAuthenticated && ctx.auth.userId ? ctx.auth.userId : "guest",
          isGuest,
        });
        await recordSuccessfulWriteRateHit(ctx.db, rateKey, rateState.row, nowMs);
        ctx.invalidate("comments");
        return row as CommentItem;
      },
    ),
  },

  endpoints: {
    listComments: endpoint({ method: "GET", path: "/api/comments", readOnly: true }, async (ctx, req) => {
      const slug = cleanSlug(req.query.get("post") ?? "");
      if (!slug) {
        return errorResponse("post_required", "Pass ?post=<slug>.", 400);
      }
      if (!isPostSlugOpen(slug)) {
        return errorResponse("post_not_found", "Comments are not open on that post.", 404);
      }

      const rows = (await ctx.db.comments
        .withIndex("by_post", (row) => row.eq("postSlug", slug))
        .order("desc")
        .take(COMMENT_PAGE_SIZE)) as unknown as CommentItem[];
      rows.reverse();

      const requireAuth = isAuthRequired(ctx.env.COMMENTS_REQUIRE_AUTH);
      const viewerId = ctx.auth.userId ?? "";

      return json({
        data: {
          post: slug,
          count: rows.length,
          requireAuth,
          viewer: {
            userId: viewerId,
            displayName: ctx.auth.displayName ?? "",
            isAuthenticated: Boolean(ctx.auth.isAuthenticated),
          },
          comments: rows.map((row) => ({
            id: row.id,
            author: row.authorName,
            body: row.body,
            mine: Boolean(viewerId && row.authorId === viewerId),
            isGuest: isGuestCommentRow(row),
            createdAt: row.createdAt,
          })),
        },
      });
    }),

    createComment: endpoint({ method: "POST", path: "/api/comments", readOnly: false }, async (ctx, req) => {
      // 1. Content-Type must be application/json.
      const contentType = (req.headers.get("content-type") ?? "").trim().toLowerCase();
      if (!contentType.startsWith("application/json")) {
        return errorResponse("unsupported_media_type", "Send the comment as application/json.", 415);
      }

      // 2. Same-origin Origin (or Referer fallback) required.
      const sourceHeader = (req.headers.get("origin") ?? req.headers.get("referer") ?? "").trim();
      const sourceHost = extractUrlHost(sourceHeader);
      const expectedHost = requestExpectedHost(req);
      if (!sourceHost || !expectedHost || sourceHost !== expectedHost) {
        return errorResponse("bad_origin", "Comments can only be posted from this site.", 403);
      }

      // 3. Body must parse as a JSON object.
      const payload = await req
        .json<{ post?: unknown; author?: unknown; body?: unknown; hp?: unknown; t?: unknown }>()
        .catch(() => null);
      if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
        return errorResponse(
          "invalid_comment",
          "Send JSON with a post slug and a non-empty comment body.",
          400,
        );
      }

      // 4. Honeypot must be empty.
      if (payload.hp !== undefined && payload.hp !== null && String(payload.hp) !== "") {
        return errorResponse("comment_rejected", "That comment looks like spam.", 422);
      }

      // 5. Minimum fill time `t` in milliseconds.
      const fillMs = typeof payload.t === "number" ? payload.t : Number(payload.t);
      if (
        payload.t === undefined ||
        payload.t === null ||
        payload.t === "" ||
        !Number.isFinite(fillMs) ||
        Math.trunc(fillMs) < EXPORT_MANIFEST.minFillMs
      ) {
        return errorResponse("too_fast", "That was quick. Give it a moment and try again.", 422);
      }

      // 6. Rate limit (checked before post/user/comment queries; incremented only after write).
      const nowMs = Date.now();
      const rateKey = await callerRateKeyHash(ctx, req);
      const rateState = await getActiveRateLimitRow(ctx.db, rateKey, nowMs);
      if (rateState.blocked) {
        return errorResponse(
          "rate_limited",
          "You are commenting quickly. Please wait a few minutes and try again.",
          429,
        );
      }

      // 7. Required fields (`post` slug and `body`).
      const slug = typeof payload.post === "string" ? cleanSlug(payload.post) : "";
      const text = typeof payload.body === "string" ? cleanBody(payload.body) : "";
      if (!slug || !text) {
        return errorResponse(
          "invalid_comment",
          "Send JSON with a post slug and a non-empty comment body.",
          400,
        );
      }

      // 8. Link-count heuristic (cheap check before post/auth/cap lookups).
      if (hasExceedingUrlCount(text)) {
        return errorResponse(
          "comment_rejected",
          "That comment looks like spam and was not posted.",
          422,
        );
      }

      // 9. Target post must resolve as open for comments.
      if (!isPostSlugOpen(slug)) {
        return errorResponse("post_not_found", "Comments are not open on that post.", 404);
      }

      // 10. Authentication requirement.
      const isAuthenticated = Boolean(ctx.auth.isAuthenticated);
      if (isAuthRequired(ctx.env.COMMENTS_REQUIRE_AUTH) && !isAuthenticated) {
        return errorResponse(
          "auth_required",
          "Sign in is required to post a comment on this site.",
          401,
        );
      }

      // 11. Server-bound author name + guest reserved-name protection.
      const rawAuthor = typeof payload.author === "string" ? payload.author : "";
      const author = resolveAuthorName(rawAuthor, {
        displayName: ctx.auth.displayName ?? "",
        isAuthenticated,
      });
      if (!isAuthenticated && (await isGuestNameReserved(ctx, author))) {
        return errorResponse(
          "name_reserved",
          "That name belongs to someone on this site. Please use a different one.",
          422,
        );
      }

      // Platform spam service check (runs after cheap checks and name resolution).
      if (await isSpamComment(ctx, text, author)) {
        return errorResponse(
          "comment_rejected",
          "That comment looks like spam and was not posted.",
          422,
        );
      }

      // 12. Per-post and total-post storage caps.
      if (await isStoreCapExceeded(ctx.db, slug)) {
        return errorResponse("store_full", "This post is not taking new comments right now.", 422);
      }

      const isGuest = !isAuthenticated;
      const viewerId = isAuthenticated && ctx.auth.userId ? ctx.auth.userId : "guest";
      const row = await ctx.db.comments.insert({
        postSlug: slug,
        authorName: author,
        body: text,
        authorId: viewerId,
        isGuest,
      });
      await recordSuccessfulWriteRateHit(ctx.db, rateKey, rateState.row, nowMs);
      ctx.invalidate("comments");

      return json(
        {
          data: {
            id: row.id,
            post: slug,
            author,
            body: text,
            mine: true,
            isGuest,
            createdAt: row.createdAt,
          },
        },
        { status: 201 },
      );
    }),
  },
});
