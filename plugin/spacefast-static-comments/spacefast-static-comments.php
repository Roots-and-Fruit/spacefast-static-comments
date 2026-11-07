<?php
/**
 * Plugin Name:       Spacefast Static Comments
 * Plugin URI:        https://rootsandfruit.com
 * Description:       Renders approved WordPress comment threads (including nested replies) into the static HTML export and mounts the Spacefast Zero live comment island below them, plus a local Studio `/api/comments` bridge.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Roots & Fruit
 * License:           GPL-2.0-or-later
 * Text Domain:       spacefast-static-comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SF_STATIC_COMMENTS_VERSION', '0.2.0' );
define( 'SF_STATIC_COMMENTS_LOCAL_OPTION', 'sf_static_comments_local_store' );
define( 'SF_STATIC_COMMENTS_TARGET_OPTION', 'sf_static_comments_export_target' );

/**
 * Determine whether the current request is a singular post that can display comments.
 *
 * @return WP_Post|null
 */
function sf_static_comments_get_singular_post(): ?WP_Post {
	if ( is_admin() || is_feed() || is_embed() ) {
		return null;
	}

	if ( ! is_singular( 'post' ) ) {
		return null;
	}

	$post = get_queried_object();
	if ( ! ( $post instanceof WP_Post ) || empty( $post->post_name ) ) {
		return null;
	}

	return $post;
}

/**
 * Determine whether the live Spacefast Zero comment island should mount on the current request.
 *
 * By default, the live form island mounts locally in Studio and during staging exports
 * (`sf_static_comments_export_target === 'staging'`), and stays off during production exports
 * until `sf_static_comments_enable_production` is enabled.
 *
 * @param WP_Post|null $post Optional post object.
 * @return bool
 */
function sf_static_comments_should_mount_live_island( ?WP_Post $post = null ): bool {
	$post = $post ?? sf_static_comments_get_singular_post();
	if ( ! ( $post instanceof WP_Post ) ) {
		return false;
	}

	$export_target = (string) get_option( SF_STATIC_COMMENTS_TARGET_OPTION, 'local' );
	$default_on    = ( 'production' !== $export_target ) || (bool) get_option( 'sf_static_comments_enable_production', false );

	/**
	 * Filter whether the live Spacefast Zero comment island mounts on the current singular post.
	 *
	 * @param bool    $enabled       Whether the live island should mount.
	 * @param WP_Post $post          Current post object.
	 * @param string  $export_target Current export target ('local', 'staging', or 'production').
	 */
	return (bool) apply_filters( 'sf_static_comments_enabled', $default_on, $post, $export_target );
}

/**
 * Query approved WordPress comments for a post in chronological order.
 *
 * Ignores Disable Comments plugin runtime filters if `show_existing_comments` was left off,
 * by reading directly through `get_comments()` for normal comment types.
 *
 * @param int $post_id Post ID.
 * @return WP_Comment[]
 */
function sf_static_comments_get_wp_comments( int $post_id ): array {
	if ( $post_id <= 0 ) {
		return array();
	}

	$comments = get_comments(
		array(
			'post_id' => $post_id,
			'status'  => 'approve',
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
			'type'    => 'comment',
		)
	);

	return is_array( $comments ) ? $comments : array();
}

/**
 * Determine whether the comments section should render on the current singular post
 * (either because the post has approved WordPress comments in the archive, or because
 * the live Spacefast Zero island is active).
 *
 * @return bool
 */
function sf_static_comments_should_render(): bool {
	$post = sf_static_comments_get_singular_post();
	if ( ! ( $post instanceof WP_Post ) ) {
		return false;
	}

	if ( sf_static_comments_should_mount_live_island( $post ) ) {
		return true;
	}

	$wp_comments = sf_static_comments_get_wp_comments( (int) $post->ID );
	return ! empty( $wp_comments );
}

/**
 * Enqueue the CSS (on singular posts) and the live island JS (when the live island is enabled).
 * Simply Static discovers these assets in the rendered HTML and includes them in the export.
 */
function sf_static_comments_enqueue_assets(): void {
	$post = sf_static_comments_get_singular_post();
	if ( ! ( $post instanceof WP_Post ) ) {
		return;
	}

	$plugin_url = plugin_dir_url( __FILE__ );

	wp_enqueue_style(
		'sf-static-comments',
		$plugin_url . 'assets/comments-island.css',
		array(),
		SF_STATIC_COMMENTS_VERSION
	);

	if ( sf_static_comments_should_mount_live_island( $post ) ) {
		wp_enqueue_script(
			'sf-static-comments',
			$plugin_url . 'assets/comments-island.js',
			array(),
			SF_STATIC_COMMENTS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'sf_static_comments_enqueue_assets' );

/**
 * Append a (`lucide:message-square`) "X comments" link to `#sf-comments` after `core/post-date`
 * in the singular post byline when the post has comments.
 *
 * Calendar icon and byline molecule spacing belong to the theme / design kit.
 *
 * @param string $block_content Rendered `core/post-date` block HTML.
 * @return string
 */
function sf_static_comments_decorate_byline_post_date( string $block_content ): string {
	static $decorated = false;

	if ( $decorated || '' === trim( $block_content ) ) {
		return $block_content;
	}

	$post = sf_static_comments_get_singular_post();
	if ( ! ( $post instanceof WP_Post ) ) {
		return $block_content;
	}

	$decorated = true;

	// Supericons verified ref: lucide:message-square
	$chat_svg = '<svg class="sf-byline-meta__icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/></svg>';

	$wp_comments = sf_static_comments_get_wp_comments( (int) $post->ID );
	$wp_count    = count( $wp_comments );
	$label       = sprintf(
		/* translators: %s: formatted number of comments */
		_n( '%s comment', '%s comments', max( 1, $wp_count ), 'spacefast-static-comments' ),
		number_format_i18n( $wp_count )
	);

	$hidden_attr = ( $wp_count > 0 ) ? '' : ' hidden';

	$comments_pill = sprintf(
		'<a class="sf-byline-comments" href="#sf-comments" data-sf-byline-comments data-base-count="%d"%s>%s<span class="sf-byline-comments__text" data-sf-byline-comments-text>%s</span></a>',
		$wp_count,
		$hidden_attr,
		$chat_svg,
		esc_html( $label )
	);

	return $block_content . "\n" . $comments_pill;
}
add_filter( 'render_block_core/post-date', 'sf_static_comments_decorate_byline_post_date', 20 );

/**
 * Normalize comment body HTML so links use HTTPS and root-slug links resolve to current permalinks.
 *
 * Old WordPress comments often contain raw URLs pasted before permalink structure changes
 * (for example `https://example.com/post-slug/` before a `/blog/` prefix was added) or `http://`
 * links. Resolving single-segment paths via `get_page_by_path()` + `get_permalink()` keeps
 * the comment archive clean and domain-agnostic on any WordPress site.
 *
 * @param string $html Rendered comment body HTML after `make_clickable()`.
 * @return string
 */
function sf_static_comments_normalize_comment_links( string $html ): string {
	return (string) preg_replace_callback(
		'/\bhref=(["\'])(https?:\/\/[^"\']+)\1/i',
		static function ( array $matches ): string {
			$quote = $matches[1];
			$url   = $matches[2];
			$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );

			if ( 0 === stripos( $url, 'http://' ) && 'web.archive.org' !== strtolower( $host ) ) {
				$url = 'https://' . substr( $url, 7 );
			}

			$trimmed_slug = trim( $path, '/' );
			if ( '' !== $trimmed_slug && false === strpos( $trimmed_slug, '/' ) ) {
				$found = get_page_by_path( $trimmed_slug, OBJECT, 'post' );
				if ( $found instanceof WP_Post && 'publish' === $found->post_status ) {
					$canonical = get_permalink( $found );
					if ( is_string( $canonical ) && '' !== $canonical ) {
						$url = $canonical;
					}
				}
			}

			return 'href=' . $quote . esc_url( $url ) . $quote;
		},
		$html
	);
}

/**
 * Check whether a WordPress comment belongs to the post's author.
 *
 * Matches by `user_id === $post_author` first, and falls back to matching the post
 * author's `user_email` or `display_name` when historical imported comments have `user_id = 0`.
 *
 * @param WP_Comment $comment     Comment object.
 * @param int        $post_author Post author user ID.
 * @return bool
 */
function sf_static_comments_is_post_author_comment( WP_Comment $comment, int $post_author ): bool {
	if ( $post_author <= 0 ) {
		return false;
	}

	if ( (int) $comment->user_id === $post_author ) {
		return true;
	}

	$author_user = get_userdata( $post_author );
	if ( ! ( $author_user instanceof WP_User ) ) {
		return false;
	}

	$comment_email = strtolower( trim( (string) $comment->comment_author_email ) );
	$user_email    = strtolower( trim( (string) $author_user->user_email ) );
	if ( '' !== $comment_email && '' !== $user_email && $comment_email === $user_email ) {
		return true;
	}

	$comment_name = strtolower( trim( (string) $comment->comment_author ) );
	$display_name = strtolower( trim( (string) $author_user->display_name ) );
	if ( '' !== $comment_name && '' !== $display_name && $comment_name === $display_name ) {
		return true;
	}

	return false;
}

/**
 * Render a single WordPress comment item and its nested child replies recursively.
 *
 * @param WP_Comment                 $comment      Comment object.
 * @param array<int, WP_Comment[]>   $by_parent    Map of parent comment_ID => child comments.
 * @param int                        $post_author  Post author user ID (for author badge & styling).
 * @param int                        $depth        Current nesting depth (1-based).
 * @return string
 */
function sf_static_comments_render_wp_comment_node( WP_Comment $comment, array $by_parent, int $post_author, int $depth = 1 ): string {
	$comment_id  = (int) $comment->comment_ID;
	$author_name = get_comment_author( $comment );
	if ( '' === trim( $author_name ) ) {
		$author_name = __( 'Anonymous', 'spacefast-static-comments' );
	}

	$iso_time     = get_comment_date( 'c', $comment );
	$display_date = get_comment_date( 'M j, Y', $comment );
	$is_author    = sf_static_comments_is_post_author_comment( $comment, $post_author );
	$item_classes = 'sf-comments__item sf-comments__item--wp' . ( $is_author ? ' sf-comments__item--author' : '' );

	$raw_text       = (string) $comment->comment_content;
	$formatted_body = sf_static_comments_normalize_comment_links( wpautop( make_clickable( esc_html( $raw_text ) ) ) );

	$badge_html = $is_author
		? sprintf( '<span class="sf-comments__badge sf-comments__badge--author">%s</span>', esc_html__( 'Author', 'spacefast-static-comments' ) )
		: '';

	$children_html = '';
	if ( ! empty( $by_parent[ $comment_id ] ) && $depth < 6 ) {
		$child_items = '';
		foreach ( $by_parent[ $comment_id ] as $child ) {
			$child_items .= sf_static_comments_render_wp_comment_node( $child, $by_parent, $post_author, $depth + 1 );
		}
		if ( '' !== $child_items ) {
			$children_html = sprintf(
				'<ol class="sf-comments__children">%s</ol>',
				$child_items
			);
		}
	}

	return sprintf(
		'<li id="comment-%d" class="%s" data-wp-comment-id="%d"><article class="sf-comments__article"><header class="sf-comments__meta"><span class="sf-comments__author">%s</span><a class="sf-comments__permalink" href="#comment-%d"><time class="sf-comments__time" datetime="%s">%s</time></a>%s</header><div class="sf-comments__body">%s</div></article>%s</li>',
		$comment_id,
		esc_attr( $item_classes ),
		$comment_id,
		esc_html( $author_name ),
		$comment_id,
		esc_attr( (string) $iso_time ),
		esc_html( (string) $display_date ),
		$badge_html,
		$formatted_body,
		$children_html
	);
}

/**
 * Render the threaded `<ol class="sf-comments__list">` for a post's approved WordPress comments.
 *
 * @param WP_Comment[] $comments    Approved comments for the post.
 * @param int          $post_author Post author user ID.
 * @return string
 */
function sf_static_comments_render_wp_thread( array $comments, int $post_author ): string {
	if ( empty( $comments ) ) {
		return '';
	}

	$by_id     = array();
	$by_parent = array();

	foreach ( $comments as $comment ) {
		if ( ! ( $comment instanceof WP_Comment ) ) {
			continue;
		}
		$by_id[ (int) $comment->comment_ID ] = $comment;
	}

	foreach ( $by_id as $comment ) {
		$parent_id = (int) $comment->comment_parent;
		if ( $parent_id > 0 && ! isset( $by_id[ $parent_id ] ) ) {
			// Orphaned reply: promote to top-level so it is never hidden.
			$parent_id = 0;
		}
		if ( ! isset( $by_parent[ $parent_id ] ) ) {
			$by_parent[ $parent_id ] = array();
		}
		$by_parent[ $parent_id ][] = $comment;
	}

	if ( empty( $by_parent[0] ) ) {
		return '';
	}

	$items_html = '';
	foreach ( $by_parent[0] as $root_comment ) {
		$items_html .= sf_static_comments_render_wp_comment_node( $root_comment, $by_parent, $post_author, 1 );
	}

	return sprintf(
		'<ol class="sf-comments__list sf-comments__list--archive">%s</ol>',
		$items_html
	);
}

/**
 * Build the HTML comment section for a given post.
 *
 * Includes any approved WordPress comments rendered directly into the static HTML,
 * plus the live Spacefast Zero mount when active.
 *
 * @param WP_Post $post Post object.
 * @return string
 */
function sf_static_comments_render_section( WP_Post $post ): string {
	$clean_slug = sanitize_title( $post->post_name );
	if ( '' === $clean_slug ) {
		return '';
	}

	$wp_comments  = sf_static_comments_get_wp_comments( (int) $post->ID );
	$wp_count     = count( $wp_comments );
	$live_enabled = sf_static_comments_should_mount_live_island( $post );

	if ( 0 === $wp_count && ! $live_enabled ) {
		return '';
	}

	$heading_text = $wp_count > 0
		? sprintf(
			/* translators: %s: formatted number of comments */
			_n( '%s Comment', '%s Comments', $wp_count, 'spacefast-static-comments' ),
			number_format_i18n( $wp_count )
		)
		: __( 'Comments', 'spacefast-static-comments' );

	$archive_html = sf_static_comments_render_wp_thread( $wp_comments, (int) $post->post_author );

	$live_mount_html = $live_enabled
		? sprintf(
			'<div class="sf-comments__root" data-sf-comments-mount data-wp-comment-count="%d"><p class="sf-comments__state">%s</p></div>',
			$wp_count,
			esc_html__( 'Loading comment form…', 'spacefast-static-comments' )
		)
		: '';

	$slug_attr = $live_enabled
		? sprintf( ' data-sf-comments-slug="%s"', esc_attr( $clean_slug ) )
		: '';

	return sprintf(
		'<section id="sf-comments" class="wp-block-group sf-comments" aria-label="%s"%s><div class="sf-comments__inner"><h2 class="sf-comments__heading" data-sf-comments-heading data-base-count="%d">%s</h2>%s%s</div></section>',
		esc_attr__( 'Reader comments', 'spacefast-static-comments' ),
		$slug_attr,
		$wp_count,
		esc_html( $heading_text ),
		$archive_html,
		$live_mount_html
	);
}

/**
 * Backwards-compatible helper when called with a slug string.
 *
 * @param string $slug Post slug (`post_name`).
 * @return string
 */
function sf_static_comments_render_mount( string $slug ): string {
	$post = sf_static_comments_get_singular_post();
	if ( $post instanceof WP_Post && $post->post_name === $slug ) {
		return sf_static_comments_render_section( $post );
	}

	$found = get_page_by_path( $slug, OBJECT, 'post' );
	if ( $found instanceof WP_Post ) {
		return sf_static_comments_render_section( $found );
	}

	return '';
}

/**
 * Insert the comment section inside the `core/post-content` wrapper div on singular posts.
 *
 * Keeping the section inside `.wp-block-post-content` (rather than as a sibling after it)
 * preserves two-column parent layouts like `.cds-article-frame` (`> :first-child` + `> :last-child`)
 * so sticky sidebars stay beside the reading column.
 *
 * @param string $block_content Rendered block HTML.
 * @return string
 */
function sf_static_comments_append_to_post_content_block( string $block_content ): string {
	static $rendered = false;

	if ( $rendered || ! sf_static_comments_should_render() ) {
		return $block_content;
	}

	$post = sf_static_comments_get_singular_post();
	if ( ! ( $post instanceof WP_Post ) ) {
		return $block_content;
	}

	$section_html = sf_static_comments_render_section( $post );
	if ( '' === $section_html ) {
		return $block_content;
	}

	$rendered        = true;
	$closing_tag_pos = strrpos( $block_content, '</div>' );
	if ( false !== $closing_tag_pos ) {
		return substr( $block_content, 0, $closing_tag_pos ) . "\n" . $section_html . "\n</div>" . substr( $block_content, $closing_tag_pos + 6 );
	}

	return $block_content . "\n" . $section_html;
}
add_filter( 'render_block_core/post-content', 'sf_static_comments_append_to_post_content_block', 20 );

/**
 * Shortcode `[spacefast_comments]` for custom templates that want explicit placement.
 *
 * @param array<string, string> $atts Shortcode attributes.
 * @return string
 */
function sf_static_comments_shortcode( $atts = array() ): string {
	$atts = shortcode_atts(
		array(
			'slug' => '',
		),
		$atts,
		'spacefast_comments'
	);

	$slug = $atts['slug'];
	if ( '' === $slug ) {
		$post = get_post();
		if ( $post instanceof WP_Post ) {
			return sf_static_comments_render_section( $post );
		}
	}

	return sf_static_comments_render_mount( (string) $slug );
}
add_shortcode( 'spacefast_comments', 'sf_static_comments_shortcode' );

/**
 * Local Studio `/api/comments` bridge.
 *
 * On Spacefast, `/api/comments` is answered by the Zero capsule before static files.
 * Locally in WordPress Studio (`.local` / `localhost`), WordPress intercepts `/api/comments`:
 * - If `sf dev` is running on `http://127.0.0.1:4173`, it proxies the request to `sf dev`.
 * - Otherwise, it answers with the exact same JSON shape backed by the local WP option
 *   (`sf_static_comments_local_store`) so you can test the full comment UI, states, and
 *   layout right inside Studio without publishing.
 */
function sf_static_comments_handle_local_api(): void {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( '/api/comments' !== rtrim( $path, '/' ) ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	// Optional proxy to local `sf dev` if running on 127.0.0.1:4173.
	$sf_dev_base = (string) apply_filters( 'sf_static_comments_local_zero_url', 'http://127.0.0.1:4173' );
	if ( '' !== $sf_dev_base ) {
		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		$target_url   = rtrim( $sf_dev_base, '/' ) . '/api/comments' . ( '' !== $query_string ? '?' . $query_string : '' );
		$raw_body     = ( 'POST' === $method ) ? (string) file_get_contents( 'php://input' ) : '';

		$proxied = wp_remote_request(
			$target_url,
			array(
				'method'  => $method,
				'timeout' => 1,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => ( 'POST' === $method ) ? $raw_body : null,
			)
		);

		if ( ! is_wp_error( $proxied ) ) {
			$status_code = (int) wp_remote_retrieve_response_code( $proxied );
			$body        = (string) wp_remote_retrieve_body( $proxied );
			if ( $status_code >= 200 && $status_code < 500 && '' !== $body ) {
				status_header( $status_code );
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'X-SF-Comments-Backend: sf-dev-proxy' );
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}
		}
	}

	// Built-in local Studio store fallback (mirrors capsule/server/index.ts contract).
	$require_auth = (bool) apply_filters( 'sf_static_comments_require_auth', false );
	$is_logged_in = is_user_logged_in();
	$current_user = wp_get_current_user();
	$viewer_id    = $is_logged_in ? 'wp:' . (int) $current_user->ID : 'guest:local-studio';
	$viewer_name  = ( $is_logged_in && ! empty( $current_user->display_name ) ) ? (string) $current_user->display_name : 'Guest';

	$store = get_option( SF_STATIC_COMMENTS_LOCAL_OPTION, array() );
	if ( ! is_array( $store ) ) {
		$store = array();
	}

	if ( 'GET' === $method ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw_post = isset( $_GET['post'] ) ? sanitize_text_field( wp_unslash( $_GET['post'] ) ) : '';
		$slug     = sanitize_title( $raw_post );
		if ( '' === $slug ) {
			wp_send_json(
				array(
					'error' => array(
						'code'    => 'post_required',
						'message' => 'Pass ?post=<slug>.',
					),
				),
				400
			);
		}

		$post_comments = isset( $store[ $slug ] ) && is_array( $store[ $slug ] ) ? array_values( $store[ $slug ] ) : array();
		$formatted     = array();
		foreach ( $post_comments as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$formatted[] = array(
				'id'        => isset( $row['id'] ) ? (string) $row['id'] : '',
				'author'    => isset( $row['authorName'] ) ? (string) $row['authorName'] : 'Guest',
				'body'      => isset( $row['body'] ) ? (string) $row['body'] : '',
				'mine'      => isset( $row['authorId'] ) && $viewer_id === $row['authorId'],
				'createdAt' => isset( $row['createdAt'] ) ? (string) $row['createdAt'] : gmdate( 'c' ),
			);
		}

		header( 'X-SF-Comments-Backend: studio-local' );
		wp_send_json(
			array(
				'data' => array(
					'post'        => $slug,
					'count'       => count( $formatted ),
					'requireAuth' => $require_auth,
					'viewer'      => array(
						'userId'          => $viewer_id,
						'displayName'     => $viewer_name,
						'isAuthenticated' => $is_logged_in,
					),
					'comments'    => $formatted,
				),
			),
			200
		);
	}

	if ( 'POST' === $method ) {
		$raw_input = (string) file_get_contents( 'php://input' );
		$payload   = json_decode( $raw_input, true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		if ( ! empty( $payload['hp'] ) ) {
			wp_send_json(
				array(
					'error' => array(
						'code'    => 'comment_rejected',
						'message' => 'That comment looks like spam.',
					),
				),
				422
			);
		}

		$slug = isset( $payload['post'] ) ? sanitize_title( (string) $payload['post'] ) : '';
		$body = isset( $payload['body'] ) ? trim( sanitize_textarea_field( (string) $payload['body'] ) ) : '';
		$body = mb_substr( $body, 0, 1000 );

		if ( '' === $slug || '' === $body ) {
			wp_send_json(
				array(
					'error' => array(
						'code'    => 'invalid_comment',
						'message' => 'Send JSON with a post slug and a non-empty comment body.',
					),
				),
				400
			);
		}

		if ( $require_auth && ! $is_logged_in ) {
			wp_send_json(
				array(
					'error' => array(
						'code'    => 'auth_required',
						'message' => 'Sign in is required to post a comment on this site.',
					),
				),
				401
			);
		}

		$raw_author = isset( $payload['author'] ) ? trim( sanitize_text_field( (string) $payload['author'] ) ) : '';
		$author     = '' !== $raw_author ? mb_substr( $raw_author, 0, 40 ) : $viewer_name;

		$url_matches = preg_match_all( '/https?:\/\//i', $body );
		if ( false !== $url_matches && $url_matches > 3 ) {
			wp_send_json(
				array(
					'error' => array(
						'code'    => 'comment_rejected',
						'message' => 'That comment looks like spam and was not posted.',
					),
				),
				422
			);
		}

		if ( ! isset( $store[ $slug ] ) || ! is_array( $store[ $slug ] ) ) {
			$store[ $slug ] = array();
		}

		$new_id     = (string) ( count( $store[ $slug ] ) + 1 );
		$created_at = gmdate( 'c' );
		$store[ $slug ][] = array(
			'id'         => $new_id,
			'postSlug'   => $slug,
			'authorName' => $author,
			'body'       => $body,
			'authorId'   => $viewer_id,
			'createdAt'  => $created_at,
		);

		update_option( SF_STATIC_COMMENTS_LOCAL_OPTION, $store, false );

		header( 'X-SF-Comments-Backend: studio-local' );
		wp_send_json(
			array(
				'data' => array(
					'id'        => $new_id,
					'post'      => $slug,
					'author'    => $author,
					'body'      => $body,
					'mine'      => true,
					'createdAt' => $created_at,
				),
			),
			201
		);
	}
}
add_action( 'parse_request', 'sf_static_comments_handle_local_api', 1 );
