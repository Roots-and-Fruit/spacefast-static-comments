<?php
/**
 * Local Studio `/api/comments` bridge.
 *
 * On Spacefast, `/api/comments` is answered by the Zero capsule before static files.
 * In WordPress Studio this file answers it instead, with the same JSON contract, backed by
 * the `sf_static_comments_local_store` option, or relays to an opted-in `sf dev`. Loaded only when
 * `sf_static_comments_local_bridge_enabled()` passes, so production never includes it.
 *
 * @package spacefast-static-comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send a JSON error in the capsule's `{ error: { code, message } }` shape and stop.
 *
 * @param string $code    Machine-readable error code.
 * @param string $message Human-readable message.
 * @param int    $status  HTTP status.
 */
function sf_static_comments_bridge_error( string $code, string $message, int $status ): never {
	wp_send_json(
		array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
			),
		),
		$status
	);
}

/**
 * Send a JSON success in the capsule's `{ data: ... }` shape and stop.
 *
 * @param array<string, mixed> $data   Response data.
 * @param int                  $status HTTP status.
 */
function sf_static_comments_bridge_respond( array $data, int $status ): never {
	wp_send_json( array( 'data' => $data ), $status );
}

/**
 * Read the local comment store.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function sf_static_comments_bridge_store_get(): array {
	$store = get_option( SF_STATIC_COMMENTS_LOCAL_OPTION, array() );
	return is_array( $store ) ? $store : array();
}

/**
 * Persist the local comment store (never autoloaded).
 *
 * @param array<string, array<int, array<string, mixed>>> $store Store.
 */
function sf_static_comments_bridge_store_save( array $store ): void {
	update_option( SF_STATIC_COMMENTS_LOCAL_OPTION, $store, false );
}

/**
 * Describe the current viewer the way the capsule does.
 *
 * @return array{userId: string, displayName: string, isAuthenticated: bool}
 */
function sf_static_comments_bridge_viewer(): array {
	$user      = wp_get_current_user();
	$logged_in = $user->exists();

	return array(
		'userId'          => $logged_in ? 'wp:' . (int) $user->ID : 'guest:local-studio',
		'displayName'     => ( $logged_in && '' !== (string) $user->display_name ) ? (string) $user->display_name : 'Guest',
		'isAuthenticated' => $logged_in,
	);
}

/**
 * Shape a stored row for the API response.
 *
 * @param array<string, mixed> $row       Stored row.
 * @param string                $viewer_id Current viewer id.
 * @return array<string, mixed>
 */
function sf_static_comments_bridge_format_row( array $row, string $viewer_id ): array {
	return array(
		'id'        => isset( $row['id'] ) ? (string) $row['id'] : '',
		'author'    => isset( $row['authorName'] ) ? (string) $row['authorName'] : 'Guest',
		'body'      => isset( $row['body'] ) ? (string) $row['body'] : '',
		'mine'      => isset( $row['authorId'] ) && $viewer_id === $row['authorId'],
		'isGuest'   => isset( $row['isGuest'] ) ? (bool) $row['isGuest'] : ! str_starts_with( (string) ( $row['authorId'] ?? '' ), 'wp:' ),
		'createdAt' => isset( $row['createdAt'] ) ? (string) $row['createdAt'] : gmdate( 'c' ),
	);
}

/**
 * Whether a guest name matches (case-insensitively) the display name or login of anyone who can edit posts.
 *
 * @param string $name Sanitized, trimmed guest name.
 */
function sf_static_comments_bridge_name_reserved( string $name ): bool {
	$needle = mb_strtolower( $name );
	$users  = get_users(
		array(
			'capability' => 'edit_posts',
			'fields'     => array( 'display_name', 'user_login' ),
		)
	);
	foreach ( $users as $user ) {
		if ( mb_strtolower( (string) $user->display_name ) === $needle || mb_strtolower( (string) $user->user_login ) === $needle ) {
			return true;
		}
	}

	return false;
}

/**
 * Opted-in `sf dev` base URL, or '' when proxying is off.
 *
 * Off by default. Set the `sf_static_comments_sf_dev_url` option (or the
 * `sf_static_comments_local_zero_url` filter) to e.g. `http://127.0.0.1:4173`.
 * Only loopback hosts are accepted.
 */
function sf_static_comments_bridge_dev_url(): string {
	$url  = (string) apply_filters( 'sf_static_comments_local_zero_url', (string) get_option( 'sf_static_comments_sf_dev_url', '' ) );
	$host = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' ) );

	if ( ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
		return '';
	}

	return in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) ? rtrim( $url, '/' ) : '';
}

/**
 * Relay the request to an opted-in `sf dev` and stop, when it answers with valid JSON.
 * Anything else falls through to the local store.
 *
 * @param string $method HTTP method.
 */
function sf_static_comments_bridge_maybe_proxy( string $method ): void {
	$base = sf_static_comments_bridge_dev_url();
	if ( '' === $base ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$slug   = isset( $_GET['post'] ) ? sanitize_title( sanitize_text_field( wp_unslash( $_GET['post'] ) ) ) : '';
	$target = $base . '/api/comments' . ( '' !== $slug ? '?post=' . rawurlencode( $slug ) : '' );

	$proxied = wp_remote_request(
		$target,
		array(
			'method'  => $method,
			'timeout' => 1,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => ( 'POST' === $method ) ? (string) file_get_contents( 'php://input' ) : null,
		)
	);

	if ( is_wp_error( $proxied ) ) {
		return;
	}

	$status  = (int) wp_remote_retrieve_response_code( $proxied );
	$type    = implode( ',', (array) wp_remote_retrieve_header( $proxied, 'content-type' ) );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $proxied ), true );

	if ( $status >= 200 && $status < 500 && str_contains( $type, 'application/json' ) && is_array( $decoded ) ) {
		wp_send_json( $decoded, $status );
	}
}

/**
 * The published post behind a slug, only when the live island would mount there.
 *
 * @param string $slug Sanitized post slug.
 */
function sf_static_comments_bridge_resolve_post( string $slug ): ?WP_Post {
	$post = get_page_by_path( $slug, OBJECT, 'post' );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! comments_open( $post ) ) {
		return null;
	}

	return sf_static_comments_should_mount_live_island( $post ) ? $post : null;
}

/**
 * Host part of an Origin or Referer header value, lowercased.
 *
 * @param string $url Header value.
 */
function sf_static_comments_bridge_url_host( string $url ): string {
	return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
}

/**
 * Ordered POST checks. Stops the request with a JSON error at the first failure.
 *
 * Order matters: transport (type, origin) before parsing, then bot traps and the rate
 * limit before any database lookup, so rejected and throttled requests stay cheap.
 *
 * @return array{slug: string, body: string, author: string, viewer: array, store: array, rate_key: string, rate_hits: int}
 */
function sf_static_comments_bridge_validate_post(): array {
	$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '';
	if ( ! str_starts_with( strtolower( trim( $content_type ) ), 'application/json' ) ) {
		sf_static_comments_bridge_error( 'unsupported_media_type', 'Send the comment as application/json.', 415 );
	}

	$source = '';
	if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
		$source = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
	} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$source = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
	}
	$home_host = sf_static_comments_bridge_url_host( home_url() );
	if ( '' === $source || sf_static_comments_bridge_url_host( $source ) !== $home_host ) {
		sf_static_comments_bridge_error( 'bad_origin', 'Comments can only be posted from this site.', 403 );
	}

	$payload = json_decode( (string) file_get_contents( 'php://input' ), true );
	if ( ! is_array( $payload ) ) {
		sf_static_comments_bridge_error( 'invalid_comment', 'Send JSON with a post slug and a non-empty comment body.', 400 );
	}

	if ( ! empty( $payload['hp'] ) ) {
		sf_static_comments_bridge_error( 'comment_rejected', 'That comment looks like spam.', 422 );
	}

	$min_fill_ms = (int) apply_filters( 'sf_static_comments_min_fill_ms', 2000 );
	if ( ! isset( $payload['t'] ) || ! is_numeric( $payload['t'] ) || (int) $payload['t'] < $min_fill_ms ) {
		sf_static_comments_bridge_error( 'too_fast', 'That was quick. Give it a moment and try again.', 422 );
	}

	$viewer    = sf_static_comments_bridge_viewer();
	$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate_key  = 'sf_static_comments_rl_' . md5( $remote_ip . '|' . $viewer['userId'] );
	$rate_hits = (int) get_transient( $rate_key );
	if ( $rate_hits >= (int) apply_filters( 'sf_static_comments_rate_limit', 5 ) ) {
		sf_static_comments_bridge_error( 'rate_limited', 'You are commenting quickly. Please wait a few minutes and try again.', 429 );
	}

	$slug = isset( $payload['post'] ) ? sanitize_title( (string) $payload['post'] ) : '';
	$body = isset( $payload['body'] ) ? mb_substr( trim( sanitize_textarea_field( (string) $payload['body'] ) ), 0, 1000 ) : '';
	if ( '' === $slug || '' === $body ) {
		sf_static_comments_bridge_error( 'invalid_comment', 'Send JSON with a post slug and a non-empty comment body.', 400 );
	}

	$url_matches = preg_match_all( '/https?:\/\//i', $body );
	if ( false !== $url_matches && $url_matches > 3 ) {
		sf_static_comments_bridge_error( 'comment_rejected', 'That comment looks like spam and was not posted.', 422 );
	}

	if ( null === sf_static_comments_bridge_resolve_post( $slug ) ) {
		sf_static_comments_bridge_error( 'post_not_found', 'Comments are not open on that post.', 404 );
	}

	if ( apply_filters( 'sf_static_comments_require_auth', false ) && ! $viewer['isAuthenticated'] ) {
		sf_static_comments_bridge_error( 'auth_required', 'Sign in is required to post a comment on this site.', 401 );
	}

	$author = $viewer['displayName'];
	if ( ! $viewer['isAuthenticated'] ) {
		$raw_author = isset( $payload['author'] ) ? trim( sanitize_text_field( (string) $payload['author'] ) ) : '';
		$author     = '' !== $raw_author ? trim( mb_substr( $raw_author, 0, 40 ) ) : $author;
		if ( sf_static_comments_bridge_name_reserved( $author ) ) {
			sf_static_comments_bridge_error( 'name_reserved', 'That name belongs to someone on this site. Please use a different one.', 422 );
		}
	}

	$store     = sf_static_comments_bridge_store_get();
	$slug_rows = isset( $store[ $slug ] ) && is_array( $store[ $slug ] ) ? count( $store[ $slug ] ) : 0;
	$is_new    = ! isset( $store[ $slug ] );
	if ( $slug_rows >= (int) apply_filters( 'sf_static_comments_max_per_post', 200 )
		|| ( $is_new && count( $store ) >= (int) apply_filters( 'sf_static_comments_max_posts', 100 ) ) ) {
		sf_static_comments_bridge_error( 'store_full', 'This post is not taking new comments right now.', 422 );
	}

	return array(
		'slug'      => $slug,
		'body'      => $body,
		'author'    => $author,
		'viewer'    => $viewer,
		'store'     => $store,
		'rate_key'  => $rate_key,
		'rate_hits' => $rate_hits,
	);
}

/**
 * GET /api/comments?post=<slug>
 */
function sf_static_comments_bridge_handle_get(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$raw_post = isset( $_GET['post'] ) ? sanitize_text_field( wp_unslash( $_GET['post'] ) ) : '';
	$slug     = sanitize_title( $raw_post );
	if ( '' === $slug ) {
		sf_static_comments_bridge_error( 'post_required', 'Pass ?post=<slug>.', 400 );
	}
	if ( null === sf_static_comments_bridge_resolve_post( $slug ) ) {
		sf_static_comments_bridge_error( 'post_not_found', 'Comments are not open on that post.', 404 );
	}

	sf_static_comments_bridge_maybe_proxy( 'GET' );

	$viewer    = sf_static_comments_bridge_viewer();
	$store     = sf_static_comments_bridge_store_get();
	$rows      = isset( $store[ $slug ] ) && is_array( $store[ $slug ] ) ? array_values( $store[ $slug ] ) : array();
	$formatted = array();
	foreach ( $rows as $row ) {
		if ( is_array( $row ) ) {
			$formatted[] = sf_static_comments_bridge_format_row( $row, $viewer['userId'] );
		}
	}

	sf_static_comments_bridge_respond(
		array(
			'post'        => $slug,
			'count'       => count( $formatted ),
			'requireAuth' => (bool) apply_filters( 'sf_static_comments_require_auth', false ),
			'viewer'      => $viewer,
			'comments'    => $formatted,
		),
		200
	);
}

/**
 * POST /api/comments
 */
function sf_static_comments_bridge_handle_post(): void {
	$input = sf_static_comments_bridge_validate_post();

	sf_static_comments_bridge_maybe_proxy( 'POST' );

	$slug  = $input['slug'];
	$store = $input['store'];
	if ( ! isset( $store[ $slug ] ) || ! is_array( $store[ $slug ] ) ) {
		$store[ $slug ] = array();
	}

	$new_id           = wp_generate_uuid4();
	$created_at       = gmdate( 'c' );
	$is_guest         = ! $input['viewer']['isAuthenticated'];
	$store[ $slug ][] = array(
		'id'         => $new_id,
		'postSlug'   => $slug,
		'authorName' => $input['author'],
		'body'       => $input['body'],
		'authorId'   => $input['viewer']['userId'],
		'isGuest'    => $is_guest,
		'createdAt'  => $created_at,
	);
	sf_static_comments_bridge_store_save( $store );
	set_transient( $input['rate_key'], $input['rate_hits'] + 1, (int) apply_filters( 'sf_static_comments_rate_window', 10 * MINUTE_IN_SECONDS ) );

	sf_static_comments_bridge_respond(
		array(
			'id'        => $new_id,
			'post'      => $slug,
			'author'    => $input['author'],
			'body'      => $input['body'],
			'mine'      => true,
			'isGuest'   => $is_guest,
			'createdAt' => $created_at,
		),
		201
	);
}

/**
 * Route `/api/comments` requests. Other paths and methods fall through to WordPress.
 */
function sf_static_comments_handle_local_api(): void {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( '/api/comments' !== rtrim( $path, '/' ) ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	if ( 'GET' === $method ) {
		sf_static_comments_bridge_handle_get();
	}

	if ( 'POST' === $method ) {
		sf_static_comments_bridge_handle_post();
	}
}
