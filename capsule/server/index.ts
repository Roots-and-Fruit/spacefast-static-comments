import { capsule, endpoint, json, mutation, query, string, table } from "@spacefast/zero/server";

import {
  cleanBody,
  cleanSlug,
  COMMENT_PAGE_SIZE,
  displayNameFor,
  isAuthRequired,
  type CommentItem,
} from "../shared/comments";

/**
 * Check spam via `ctx.spam.check` when the runtime provides the connector,
 * falling back to safe local heuristics when `ctx.spam` is not bound.
 */
async function isSpamComment(
  ctx: { spam?: { check?: (input: { content: string; type: string; authorName: string }) => Promise<{ spam?: boolean }> } },
  text: string,
  author: string,
): Promise<boolean> {
  if (ctx.spam && typeof ctx.spam.check === "function") {
    try {
      const verdict = await ctx.spam.check({ content: text, type: "comment", authorName: author });
      if (verdict && verdict.spam) return true;
    } catch {
      // Fall through to local heuristic checks if the spam service errors.
    }
  }
  const urlMatches = text.match(/https?:\/\//gi);
  if (urlMatches && urlMatches.length > 3) {
    return true;
  }
  return false;
}

/**
 * Server-only Spacefast Zero capsule for static WordPress post comments.
 *
 * WordPress and Simply Static render the article pages at build time.
 * This capsule owns the live comment thread per post slug (`post_name`).
 * Guests can comment by default; setting `COMMENTS_REQUIRE_AUTH=1` in Space
 * environment variables enforces authenticated SSO sessions (`ctx.auth.isAuthenticated`).
 */
export default capsule({
  name: "StaticWPComments",
  schema: {
    comments: table({
      postSlug: string(),
      authorName: string(),
      body: string(),
      authorId: string(),
    }).index("by_post", ["postSlug"]),
  },

  queries: {
    comments: query(async (ctx, postSlug: string): Promise<CommentItem[]> => {
      const slug = cleanSlug(postSlug);
      if (!slug) return [];
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
        const slug = cleanSlug(postSlug);
        const text = cleanBody(body);
        if (!slug || !text) return null;

        if (isAuthRequired(ctx.env.COMMENTS_REQUIRE_AUTH) && !ctx.auth.isAuthenticated) {
          throw new Error("Sign in is required to leave a comment.");
        }

        const name = displayNameFor(authorName, ctx.auth.displayName ?? "");
        if (await isSpamComment(ctx, text, name)) return null;

        const row = await ctx.db.comments.insert({
          postSlug: slug,
          authorName: name,
          body: text,
          authorId: ctx.auth.userId ?? "guest",
        });
        ctx.invalidate("comments");
        return row as CommentItem;
      },
    ),
  },

  endpoints: {
    listComments: endpoint({ method: "GET", path: "/api/comments", mode: "read" }, async (ctx, req) => {
      const slug = cleanSlug(req.query.get("post") ?? "");
      if (!slug) {
        return json({ error: { code: "post_required", message: "Pass ?post=<slug>." } }, { status: 400 });
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
            createdAt: row.createdAt,
          })),
        },
      });
    }),

    createComment: endpoint({ method: "POST", path: "/api/comments", mode: "write" }, async (ctx, req) => {
      const payload = await req
        .json<{ post?: string; author?: string; body?: string; hp?: string }>()
        .catch(() => null);
      if (payload?.hp) {
        return json(
          { error: { code: "comment_rejected", message: "That comment looks like spam." } },
          { status: 422 },
        );
      }

      const slug = cleanSlug(payload?.post ?? "");
      const text = cleanBody(payload?.body ?? "");
      if (!slug || !text) {
        return json(
          {
            error: {
              code: "invalid_comment",
              message: "Send JSON with a post slug and a non-empty comment body.",
            },
          },
          { status: 400 },
        );
      }

      if (isAuthRequired(ctx.env.COMMENTS_REQUIRE_AUTH) && !ctx.auth.isAuthenticated) {
        return json(
          {
            error: {
              code: "auth_required",
              message: "Sign in is required to post a comment on this site.",
            },
          },
          { status: 401 },
        );
      }

      const author = displayNameFor(payload?.author ?? "", ctx.auth.displayName ?? "");
      if (await isSpamComment(ctx, text, author)) {
        return json(
          {
            error: {
              code: "comment_rejected",
              message: "That comment looks like spam and was not posted.",
            },
          },
          { status: 422 },
        );
      }

      const viewerId = ctx.auth.userId ?? "guest";
      const row = await ctx.db.comments.insert({
        postSlug: slug,
        authorName: author,
        body: text,
        authorId: viewerId,
      });
      ctx.invalidate("comments");

      return json(
        {
          data: {
            id: row.id,
            post: slug,
            author,
            body: text,
            mine: true,
            createdAt: row.createdAt,
          },
        },
        { status: 201 },
      );
    }),
  },
});
