<?php
/**
 * Spacefast Zero capsule compiler, manifest builder, and publish integration.
 *
 * Keeps `spacefast-wordpress` untouched while allowing `spacefast-static-comments`
 * to:
 * 1. Build the site's hardened `EXPORT_MANIFEST` (published post slugs with open
 *    comments, reserved editor display names, salted SHA-256 login hashes, and
 *    active rate/cap filter values).
 * 2. Customize the pre-compiled Spacefast Zero capsule bundle in pure PHP (no
 *    Node.js or `sf` CLI required) with exact canonical-JSON SHA-256 digests.
 * 3. Provision or update the Zero comment server directly on the Space connected
 *    in `spacefast-wordpress`, or automatically during Simply Static exports via
 *    Simply Static's task list + WordPress's `http_request_args` filter.
 *
 * @package spacefast-static-comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SF_STATIC_COMMENTS_PROVISION_STATE_OPTION', 'sf_static_comments_provision_state' );
define( 'SF_STATIC_COMMENTS_SALT_OPTION', 'sf_static_comments_manifest_salt' );
define( 'SF_STATIC_COMMENTS_LOG_OPTION', 'sf_static_comments_provision_log' );

/**
 * Stable random salt used to hash private `user_login` values in the export manifest.
 */
function sf_static_comments_manifest_salt(): string {
	$salt = (string) get_option( SF_STATIC_COMMENTS_SALT_OPTION, '' );
	if ( ! preg_match( '/^[0-9a-f]{32,64}$/', $salt ) ) {
		$salt = bin2hex( random_bytes( 16 ) );
		update_option( SF_STATIC_COMMENTS_SALT_OPTION, $salt, false );
	}

	return $salt;
}

/**
 * Normalize a display name or login the same way `normalizeIdentityName()` in `shared/comments.ts` does.
 *
 * @param string $value Raw name.
 */
function sf_static_comments_normalize_identity_name( string $value ): string {
	$collapsed = (string) preg_replace( '/\s+/u', ' ', trim( $value ) );
	return mb_strtolower( $collapsed );
}

/**
 * Build the current site's security & post manifest for the Zero capsule.
 *
 * @return array{
 *   enforceAllowedPosts: bool,
 *   allowedPostSlugs: array<int, string>,
 *   closedPostSlugs: array<int, string>,
 *   reservedNames: array<int, string>,
 *   reservedNameSalt: string,
 *   reservedNameHashes: array<int, string>,
 *   minFillMs: int,
 *   maxPerPost: int,
 *   maxPosts: int,
 *   rateLimit: int,
 *   rateWindowSeconds: int
 * }
 */
function sf_static_comments_build_export_manifest_data(): array {
	$posts = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$allowed_slugs = array();
	$closed_slugs  = array();
	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post || '' === $post->post_name ) {
			continue;
		}
		$slug = sanitize_title( $post->post_name );
		if ( '' === $slug ) {
			continue;
		}
		if ( comments_open( $post ) ) {
			$allowed_slugs[] = $slug;
		} else {
			$closed_slugs[] = $slug;
		}
	}
	$allowed_slugs = array_values( array_unique( $allowed_slugs ) );
	sort( $allowed_slugs, SORT_STRING );
	$closed_slugs = array_values( array_unique( array_diff( $closed_slugs, $allowed_slugs ) ) );
	sort( $closed_slugs, SORT_STRING );

	$salt           = sf_static_comments_manifest_salt();
	$reserved_names = array();
	$login_hashes   = array();

	$editors = get_users(
		array(
			'capability' => 'edit_posts',
			'fields'     => array( 'display_name', 'user_login' ),
		)
	);
	foreach ( $editors as $editor ) {
		$display = sf_static_comments_normalize_identity_name( (string) ( $editor->display_name ?? '' ) );
		if ( '' !== $display ) {
			$reserved_names[] = $display;
		}
		$login = sf_static_comments_normalize_identity_name( (string) ( $editor->user_login ?? '' ) );
		if ( '' !== $login ) {
			$login_hashes[] = hash( 'sha256', $salt . ':' . $login );
		}
	}
	$reserved_names = array_values( array_unique( $reserved_names ) );
	sort( $reserved_names, SORT_STRING );
	$login_hashes = array_values( array_unique( $login_hashes ) );
	sort( $login_hashes, SORT_STRING );

	return array(
		'enforceAllowedPosts' => true,
		'allowedPostSlugs'    => $allowed_slugs,
		'closedPostSlugs'     => $closed_slugs,
		'reservedNames'       => $reserved_names,
		'reservedNameSalt'    => $salt,
		'reservedNameHashes'  => $login_hashes,
		'minFillMs'           => max( 0, (int) apply_filters( 'sf_static_comments_min_fill_ms', 2000 ) ),
		'maxPerPost'          => max( 1, (int) apply_filters( 'sf_static_comments_max_per_post', 200 ) ),
		'maxPosts'            => max( 1, (int) apply_filters( 'sf_static_comments_max_posts', 100 ) ),
		'rateLimit'           => max( 1, (int) apply_filters( 'sf_static_comments_rate_limit', 5 ) ),
		'rateWindowSeconds'   => max( 1, (int) apply_filters( 'sf_static_comments_rate_window', 10 * MINUTE_IN_SECONDS ) ),
	);
}

/**
 * Render `shared/export-manifest.ts` TypeScript source for a manifest array.
 *
 * @param array<string, mixed> $manifest Manifest from `sf_static_comments_build_export_manifest_data()`.
 */
function sf_static_comments_render_export_manifest_ts( array $manifest ): string {
	$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return "/**\n"
		. " * Auto-generated by Spacefast Static Comments v" . SF_STATIC_COMMENTS_VERSION . ".\n"
		. " * Contains only public post slugs, public display names, and salted SHA-256 login digests.\n"
		. " */\n\n"
		. "export type CommentsExportManifest = {\n"
		. "  readonly enforceAllowedPosts: boolean;\n"
		. "  readonly allowedPostSlugs: readonly string[];\n"
		. "  readonly closedPostSlugs: readonly string[];\n"
		. "  readonly reservedNames: readonly string[];\n"
		. "  readonly reservedNameSalt: string;\n"
		. "  readonly reservedNameHashes: readonly string[];\n"
		. "  readonly minFillMs: number;\n"
		. "  readonly maxPerPost: number;\n"
		. "  readonly maxPosts: number;\n"
		. "  readonly rateLimit: number;\n"
		. "  readonly rateWindowSeconds: number;\n"
		. "};\n\n"
		. "export const EXPORT_MANIFEST: CommentsExportManifest = " . $json . ";\n";
}

/**
 * Render the JS literal that replaces `var EXPORT_MANIFEST={...};` inside the compiled QuickJS bundle.
 *
 * @param array<string, mixed> $manifest Manifest array.
 */
function sf_static_comments_render_js_manifest_assignment( array $manifest ): string {
	$encoded = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return 'var EXPORT_MANIFEST=' . $encoded . ';';
}

/**
 * Canonical JSON encoder matching `@spacefast/common/utils/canonical-json.js`.
 *
 * - Object keys sorted in UTF-8 byte / Unicode code point order (`SORT_STRING`).
 * - Arrays preserve order.
 * - Unescaped slashes and Unicode, matching `JSON.stringify()`.
 *
 * @param mixed $value Value to encode.
 */
function sf_static_comments_canonical_json( $value ): string {
	$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	if ( is_array( $value ) ) {
		if ( array_is_list( $value ) ) {
			$items = array();
			foreach ( $value as $item ) {
				$items[] = sf_static_comments_canonical_json( $item );
			}
			return '[' . implode( ',', $items ) . ']';
		}

		ksort( $value, SORT_STRING );
		$pairs = array();
		foreach ( $value as $key => $entry ) {
			if ( null === $entry && ! array_key_exists( $key, $value ) ) {
				continue;
			}
			$pairs[] = wp_json_encode( (string) $key, $flags ) . ':' . sf_static_comments_canonical_json( $entry );
		}
		return '{' . implode( ',', $pairs ) . '}';
	}

	if ( is_object( $value ) ) {
		$vars = get_object_vars( $value );
		ksort( $vars, SORT_STRING );
		$pairs = array();
		foreach ( $vars as $key => $entry ) {
			if ( null === $entry && ! property_exists( $value, $key ) ) {
				continue;
			}
			$pairs[] = wp_json_encode( (string) $key, $flags ) . ':' . sf_static_comments_canonical_json( $entry );
		}
		return '{' . implode( ',', $pairs ) . '}';
	}

	$encoded = wp_json_encode( $value, $flags );
	return false === $encoded ? 'null' : $encoded;
}

/**
 * Compute `zeroDeployDigest(finalizePayload)` identically to `@spacefast/zero-compile`.
 *
 * @param array<string, mixed> $finalize Finalize payload array.
 */
function sf_static_comments_zero_deploy_digest( array $finalize ): string {
	$manifest = $finalize['artifact']['sourceManifest'] ?? array();
	$artifact = $finalize['artifact'] ?? array();
	$artifact['sourceManifest'] = array(
		'entries' => $manifest['entries'] ?? array(),
		'files'   => $manifest['files'] ?? array(),
		'env'     => $manifest['env'] ?? null,
	);

	$bundles = array();
	foreach ( (array) ( $finalize['bundles'] ?? array() ) as $bundle ) {
		$bundles[] = array(
			'path'   => $bundle['path'],
			'sha256' => $bundle['sha256'],
			'size'   => $bundle['size'],
		);
	}

	$strip_migrations = static function ( array $source ): array {
		if ( isset( $source['db'] ) && is_array( $source['db'] ) ) {
			unset( $source['db']['migrationOperations'] );
		}
		return $source;
	};

	$endpoint_sources = array_map( $strip_migrations, (array) ( $finalize['endpointSources'] ?? array() ) );
	$run_sources      = array_map( $strip_migrations, (array) ( $finalize['runSources'] ?? array() ) );

	$digest_input = array(
		'artifact'        => $artifact,
		'bundles'         => $bundles,
		'endpointSources' => $endpoint_sources,
		'runSources'      => $run_sources,
		'install'         => $finalize['install'] ?? array(),
		'variables'       => $finalize['variables'] ?? array(),
		'realtime'        => $finalize['realtime'] ?? array(),
		'auth'            => $finalize['auth'] ?? array(),
		'inspect'         => $finalize['inspect'] ?? array(),
		'schemaHash'      => $finalize['migrations']['schemaHash'] ?? null,
	);

	return 'sha256:' . hash( 'sha256', sf_static_comments_canonical_json( $digest_input ) );
}

/**
 * Load the bundled Zero finalize template and customize it with the current site's manifest.
 *
 * @param array<string, mixed>|null $manifest Optional manifest override.
 * @return array{
 *   manifest: array<string, mixed>,
 *   manifestTs: string,
 *   sfJsonc: string,
 *   deployJson: string,
 *   deployDigest: string,
 *   sourceMetadata: array<string, mixed>,
 *   staticFiles: array<string, array{contentType: string, sha256: string, size: int, bytes: string}>
 * }
 */
function sf_static_comments_compile_zero_package( ?array $manifest = null ): array {
	$manifest    = $manifest ?? sf_static_comments_build_export_manifest_data();
	$manifest_ts = sf_static_comments_render_export_manifest_ts( $manifest );
	$js_assign   = sf_static_comments_render_js_manifest_assignment( $manifest );

	$template_path = __DIR__ . '/capsule/finalize-template.json.gz';
	$compressed    = file_exists( $template_path ) ? file_get_contents( $template_path ) : false;
	$raw_json      = false !== $compressed ? gzdecode( $compressed ) : false;
	$decoded       = false !== $raw_json ? json_decode( $raw_json, true ) : null;
	if ( ! is_array( $decoded ) || ! isset( $decoded['finalize'], $decoded['staticFiles'] ) ) {
		throw new RuntimeException( 'The bundled Spacefast Zero comments template could not be read.' );
	}

	$finalize = $decoded['finalize'];
	$pattern  = '/var EXPORT_MANIFEST=\{[^;]+\};/';

	// 1. Patch the QuickJS server bundle with the site's manifest.
	foreach ( $finalize['bundles'] as $idx => $bundle ) {
		$bundle_js = base64_decode( (string) $bundle['contentBase64'], true );
		if ( false === $bundle_js ) {
			throw new RuntimeException( 'Invalid base64 server bundle in Zero template.' );
		}
		$patched_js = preg_replace( $pattern, $js_assign, $bundle_js, 1, $count );
		if ( null === $patched_js || 0 === $count ) {
			throw new RuntimeException( 'Could not inject EXPORT_MANIFEST into Zero server bundle.' );
		}
		$finalize['bundles'][ $idx ]['contentBase64'] = base64_encode( $patched_js );
		$finalize['bundles'][ $idx ]['size']          = strlen( $patched_js );
		$finalize['bundles'][ $idx ]['sha256']        = 'sha256:' . hash( 'sha256', $patched_js );
	}

	// 2. Patch every endpointSource and runSource with the same manifest.
	foreach ( array( 'endpointSources', 'runSources' ) as $group ) {
		if ( empty( $finalize[ $group ] ) || ! is_array( $finalize[ $group ] ) ) {
			continue;
		}
		foreach ( $finalize[ $group ] as $idx => $entry ) {
			$patched = preg_replace( $pattern, $js_assign, (string) ( $entry['source'] ?? '' ), 1, $count );
			if ( null !== $patched && $count > 0 ) {
				$finalize[ $group ][ $idx ]['source'] = $patched;
			}
		}
	}

	// 3. Update the sourceManifest entry for `shared/export-manifest.ts`.
	if ( isset( $finalize['artifact']['sourceManifest']['files'] ) && is_array( $finalize['artifact']['sourceManifest']['files'] ) ) {
		foreach ( $finalize['artifact']['sourceManifest']['files'] as $idx => $file_entry ) {
			if ( 'shared/export-manifest.ts' === ( $file_entry['path'] ?? '' ) ) {
				$finalize['artifact']['sourceManifest']['files'][ $idx ]['size']   = strlen( $manifest_ts );
				$finalize['artifact']['sourceManifest']['files'][ $idx ]['sha256'] = hash( 'sha256', $manifest_ts );
			}
		}
	}

	// 4. Strip `contentBase64` from `finalize.staticFiles` (wire format keeps only metadata).
	$wire_static_files = array();
	foreach ( (array) ( $finalize['staticFiles'] ?? array() ) as $sf ) {
		unset( $sf['contentBase64'] );
		$wire_static_files[] = $sf;
	}
	$finalize['staticFiles'] = $wire_static_files;

	// 5. Recompute the canonical deploy digest and `__spacefast/zero/deploy.json`.
	$deploy_digest = sf_static_comments_zero_deploy_digest( $finalize );
	$deploy_json   = wp_json_encode(
		array(
			'format' => 'spacefast.zero.deploy.v1',
			'digest' => $deploy_digest,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";

	$space_settings = sf_static_comments_spacefast_connection();
	$space_name     = '' !== $space_settings['space_slug']
		? $space_settings['space_slug']
		: ( '' !== $space_settings['space_name'] ? sanitize_title( $space_settings['space_name'] ) : 'spacefast-static-comments' );

	$sf_jsonc_raw = (string) file_get_contents( __DIR__ . '/capsule/sf.jsonc' );
	$sf_jsonc     = (string) preg_replace(
		'/"name":\s*"[^"]*"/',
		'"name": ' . wp_json_encode( $space_name ),
		$sf_jsonc_raw,
		1
	);

	$static_files = array();
	foreach ( (array) $decoded['staticFiles'] as $rel_path => $info ) {
		$bytes = base64_decode( (string) ( $info['base64'] ?? '' ), true );
		if ( false === $bytes ) {
			continue;
		}
		$static_files[ (string) $rel_path ] = array(
			'contentType' => (string) ( $info['contentType'] ?? 'application/octet-stream' ),
			'sha256'      => 'sha256:' . hash( 'sha256', $bytes ),
			'size'        => strlen( $bytes ),
			'bytes'       => $bytes,
		);
	}

	return array(
		'manifest'       => $manifest,
		'manifestTs'     => $manifest_ts,
		'sfJsonc'        => $sf_jsonc,
		'deployJson'     => $deploy_json,
		'deployDigest'   => $deploy_digest,
		'sourceMetadata' => array(
			'statticZero' => array(
				'finalize' => $finalize,
			),
		),
		'staticFiles'    => $static_files,
	);
}

/**
 * Write the compiled Zero runtime files (`sf.jsonc`, `__spacefast/zero/deploy.json`,
 * `client.js`, `zero.css`, `_spacefast/platform/...`) into an export directory, and
 * remove any raw `.ts`/`.tsx` capsule source files so they can never be uploaded publicly.
 *
 * Also writes `server/` and `shared/` into a sibling `.spacefast-comments-capsule/`
 * directory (outside the web export root) for developers who run `sf build` or `sf dev`.
 *
 * @param string $export_dir Simply Static archive directory.
 * @return array<string, mixed> Compiled Zero package.
 */
function sf_static_comments_stage_into_export_dir( string $export_dir ): array {
	$root = realpath( $export_dir );
	if ( false === $root || ! is_dir( $root ) || ! is_writable( $root ) ) {
		throw new RuntimeException( 'The static export directory is not writable.' );
	}

	$package = sf_static_comments_compile_zero_package();

	// Never leave raw TypeScript capsule sources inside the public static export root.
	foreach ( array( 'client', 'server', 'shared' ) as $forbidden_dir ) {
		$candidate = $root . DIRECTORY_SEPARATOR . $forbidden_dir;
		if ( is_dir( $candidate ) ) {
			sf_static_comments_rrmdir( $candidate );
		}
	}

	// Write `sf.jsonc` with `runtime.kind = "zero"`.
	file_put_contents( $root . DIRECTORY_SEPARATOR . 'sf.jsonc', $package['sfJsonc'], LOCK_EX );

	// Write `__spacefast/zero/deploy.json`.
	$deploy_dir = $root . DIRECTORY_SEPARATOR . '__spacefast' . DIRECTORY_SEPARATOR . 'zero';
	wp_mkdir_p( $deploy_dir );
	file_put_contents( $deploy_dir . DIRECTORY_SEPARATOR . 'deploy.json', $package['deployJson'], LOCK_EX );

	// Write the Zero platform static files (`client.js`, `zero.css`, `_spacefast/platform/*`).
	foreach ( $package['staticFiles'] as $rel_path => $info ) {
		$target = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel_path );
		wp_mkdir_p( dirname( $target ) );
		file_put_contents( $target, $info['bytes'], LOCK_EX );
	}

	return $package;
}

/**
 * Recursively remove a directory inside an export or temp folder.
 *
 * @param string $dir Directory path.
 */
function sf_static_comments_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . DIRECTORY_SEPARATOR . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			sf_static_comments_rrmdir( $path );
		} else {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

/**
 * Read the current `spacefast-wordpress` connection state without modifying that plugin.
 *
 * @return array{
 *   available: bool,
 *   authorized: bool,
 *   selected: bool,
 *   configured: bool,
 *   mode: string,
 *   api_url: string,
 *   team_id: string,
 *   team_name: string,
 *   team_slug: string,
 *   space_id: string,
 *   space_name: string,
 *   space_slug: string,
 *   live_url: string
 * }
 */
function sf_static_comments_spacefast_connection(): array {
	if ( ! class_exists( 'Spacefast_Settings' ) ) {
		return array(
			'available'  => false,
			'authorized' => false,
			'selected'   => false,
			'configured' => false,
			'mode'       => 'static',
			'api_url'    => 'https://api.spacefast.com',
			'team_id'    => '',
			'team_name'  => '',
			'team_slug'  => '',
			'space_id'   => '',
			'space_name' => '',
			'space_slug' => '',
			'live_url'   => '',
		);
	}

	$settings = Spacefast_Settings::get();
	return array(
		'available'  => true,
		'authorized' => Spacefast_Settings::authorized(),
		'selected'   => Spacefast_Settings::selected(),
		'configured' => Spacefast_Settings::configured(),
		'mode'       => Spacefast_Settings::mode(),
		'api_url'    => (string) ( $settings['api_url'] ?? 'https://api.spacefast.com' ),
		'team_id'    => (string) ( $settings['team_id'] ?? '' ),
		'team_name'  => (string) ( $settings['team_name'] ?? '' ),
		'team_slug'  => (string) ( $settings['team_slug'] ?? '' ),
		'space_id'   => (string) ( $settings['space_id'] ?? '' ),
		'space_name' => (string) ( $settings['space_name'] ?? '' ),
		'space_slug' => (string) ( $settings['space_slug'] ?? '' ),
		'live_url'   => (string) ( $settings['live_url'] ?? '' ),
	);
}

/**
 * Read the saved Zero comments provisioning state.
 *
 * @return array{
 *   status: string,
 *   space_id: string,
 *   space_name: string,
 *   live_url: string,
 *   version_id: string,
 *   deploy_digest: string,
 *   allowed_posts: int,
 *   reserved_names: int,
 *   provisioned_at: int,
 *   plugin_version: string,
 *   last_error: string
 * }
 */
function sf_static_comments_get_provision_state(): array {
	$stored = get_option( SF_STATIC_COMMENTS_PROVISION_STATE_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();

	return array_merge(
		array(
			'status'         => 'unprovisioned',
			'space_id'       => '',
			'space_name'     => '',
			'live_url'       => '',
			'version_id'     => '',
			'deploy_digest'  => '',
			'allowed_posts'  => 0,
			'reserved_names' => 0,
			'provisioned_at' => 0,
			'plugin_version' => '',
			'last_error'     => '',
		),
		$stored
	);
}

/**
 * Append a provisioning or security event to the bounded activity log (max 50 entries).
 *
 * @param string $event_type `provision`, `export`, `settings`, or `verify`.
 * @param string $detail     Human-readable summary.
 * @param string $identifier Secondary identifier (version ID, digest, or space ID).
 * @param string $outcome    `success`, `changed`, or `error`.
 */
function sf_static_comments_log_event( string $event_type, string $detail, string $identifier = '', string $outcome = 'success' ): void {
	$rows = get_option( SF_STATIC_COMMENTS_LOG_OPTION, array() );
	$rows = is_array( $rows ) ? $rows : array();

	array_unshift(
		$rows,
		array(
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'user_id'    => get_current_user_id(),
			'event_type' => $event_type,
			'detail'     => $detail,
			'identifier' => $identifier,
			'outcome'    => $outcome,
		)
	);

	if ( count( $rows ) > 50 ) {
		$rows = array_slice( $rows, 0, 50 );
	}

	update_option( SF_STATIC_COMMENTS_LOG_OPTION, $rows, false );
}

/**
 * Provision or update the Zero comment server on the connected Space via an additive
 * Spacefast version upload (`POST /v1/spaces/{space_id}/versions`).
 *
 * Uses `Spacefast_OAuth` and `Spacefast_Client` from `spacefast-wordpress` without
 * modifying that plugin.
 *
 * @return array{ok: bool, message: string, state: array<string, mixed>}
 */
function sf_static_comments_provision_zero_server(): array {
	$conn = sf_static_comments_spacefast_connection();
	if ( ! $conn['available'] || ! class_exists( 'Spacefast_OAuth' ) || ! class_exists( 'Spacefast_Client' ) ) {
		return array(
			'ok'      => false,
			'message' => __( 'The Spacefast plugin is not available.', 'spacefast-static-comments' ),
			'state'   => sf_static_comments_get_provision_state(),
		);
	}

	if ( ! $conn['selected'] || '' === $conn['space_id'] ) {
		return array(
			'ok'      => false,
			'message' => __( 'Connect a Space in Settings → Spacefast first.', 'spacefast-static-comments' ),
			'state'   => sf_static_comments_get_provision_state(),
		);
	}

	try {
		$package = sf_static_comments_compile_zero_package();
	} catch ( Throwable $error ) {
		sf_static_comments_log_event( 'provision', $error->getMessage(), $conn['space_id'], 'error' );
		return array(
			'ok'      => false,
			'message' => $error->getMessage(),
			'state'   => sf_static_comments_get_provision_state(),
		);
	}

	// Build the in-memory file map for the additive Zero runtime publish.
	$file_bytes = array(
		'sf.jsonc'                     => array(
			'bytes'       => $package['sfJsonc'],
			'contentType' => 'application/json',
		),
		'__spacefast/zero/deploy.json' => array(
			'bytes'       => $package['deployJson'],
			'contentType' => 'application/json',
		),
	);
	foreach ( $package['staticFiles'] as $path => $info ) {
		$file_bytes[ $path ] = array(
			'bytes'       => $info['bytes'],
			'contentType' => $info['contentType'],
		);
	}
	ksort( $file_bytes, SORT_STRING );

	$manifest_wire = array();
	foreach ( $file_bytes as $path => $entry ) {
		$manifest_wire[] = array(
			'path'        => $path,
			'size'        => strlen( $entry['bytes'] ),
			'sha256'      => hash( 'sha256', $entry['bytes'] ),
			'contentType' => $entry['contentType'],
		);
	}

	$token = ( new Spacefast_OAuth() )->access_token();
	if ( '' === $token ) {
		$msg = __( 'Spacefast authorization expired. Reconnect in Settings → Spacefast.', 'spacefast-static-comments' );
		sf_static_comments_log_event( 'provision', $msg, $conn['space_id'], 'error' );
		return array(
			'ok'      => false,
			'message' => $msg,
			'state'   => sf_static_comments_get_provision_state(),
		);
	}

	$event_id = (string) wp_generate_uuid4();
	$api_base = untrailingslashit( $conn['api_url'] );
	$space_id = rawurlencode( $conn['space_id'] );

	$create_response = wp_safe_remote_request(
		"{$api_base}/v1/spaces/{$space_id}/versions",
		array(
			'method'              => 'POST',
			'timeout'             => 20,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'sslverify'           => true,
			'limit_response_size' => 16 * 1024 * 1024,
			'headers'             => array(
				'Authorization'   => 'Bearer ' . $token,
				'Accept'          => 'application/json',
				'Content-Type'    => 'application/json',
				'Idempotency-Key' => $event_id,
				'User-Agent'      => 'Spacefast-Static-Comments/' . SF_STATIC_COMMENTS_VERSION,
			),
			'body'                => wp_json_encode(
				array(
					'publishMode'    => 'additive',
					'files'          => $manifest_wire,
					'finalize'       => array( 'channel' => 'live' ),
					'sourceMetadata' => $package['sourceMetadata'],
					'buildLog'       => sprintf(
						"Spacefast Static Comments v%s Zero capsule (%s)\nAllowed posts: %d | Reserved names: %d\n",
						SF_STATIC_COMMENTS_VERSION,
						$package['deployDigest'],
						count( $package['manifest']['allowedPostSlugs'] ),
						count( $package['manifest']['reservedNames'] )
					),
					'source'         => array(
						'kind'     => 'api',
						'client'   => 'spacefast-static-comments/' . SF_STATIC_COMMENTS_VERSION,
						'message'  => 'Provisioned Spacefast Zero comments capsule from WordPress.',
						'metadata' => array(
							'integration' => 'wordpress-comments',
							'siteUrl'     => untrailingslashit( home_url() ),
						),
					),
				)
			),
		)
	);

	if ( is_wp_error( $create_response ) ) {
		$msg = __( 'Could not reach Spacefast to provision the comments server.', 'spacefast-static-comments' );
		sf_static_comments_update_provision_error( $msg );
		sf_static_comments_log_event( 'provision', $msg, $conn['space_id'], 'error' );
		return array(
			'ok'      => false,
			'message' => $msg,
			'state'   => sf_static_comments_get_provision_state(),
		);
	}

	$status  = (int) wp_remote_retrieve_response_code( $create_response );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $create_response ), true );
	$data    = is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : ( is_array( $decoded ) ? $decoded : array() );

	if ( $status < 200 || $status >= 300 ) {
		$api_msg = isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] )
			? $decoded['error']['message']
			: sprintf(
				/* translators: %d: HTTP status code from Spacefast API. */
				__( 'Spacefast rejected the provisioning request (HTTP %d).', 'spacefast-static-comments' ),
				$status
			);
		sf_static_comments_update_provision_error( $api_msg );
		sf_static_comments_log_event( 'provision', $api_msg, $conn['space_id'], 'error' );
		return array(
			'ok'      => false,
			'message' => $api_msg,
			'state'   => sf_static_comments_get_provision_state(),
		);
	}

	$version_id = (string) ( $data['versionId'] ?? '' );
	$upload     = isset( $data['upload'] ) && is_array( $data['upload'] ) ? $data['upload'] : null;

	// Upload any requested files (typically only `sf.jsonc`, `deploy.json`, and any uncached platform chunks).
	if ( '' !== $version_id && is_array( $upload ) ) {
		$targets = isset( $upload['targets'] ) && is_array( $upload['targets'] ) ? array_values( $upload['targets'] ) : array();
		foreach ( $targets as $target ) {
			$rel_path = (string) ( $target['path'] ?? '' );
			$url      = (string) ( $target['url'] ?? '' );
			if ( '' === $rel_path || '' === $url || ! isset( $file_bytes[ $rel_path ] ) ) {
				continue;
			}
			$method  = strtoupper( (string) ( $target['method'] ?? 'PUT' ) );
			$headers = isset( $target['headers'] ) && is_array( $target['headers'] ) ? $target['headers'] : array();
			$up_res  = wp_safe_remote_request(
				$url,
				array(
					'method'             => $method,
					'timeout'            => 30,
					'redirection'        => 0,
					'reject_unsafe_urls' => true,
					'sslverify'          => true,
					'headers'            => $headers,
					'body'               => $file_bytes[ $rel_path ]['bytes'],
				)
			);
			$up_code = is_wp_error( $up_res ) ? 0 : (int) wp_remote_retrieve_response_code( $up_res );
			if ( $up_code < 200 || $up_code >= 300 ) {
				$msg = sprintf(
					/* translators: %s: relative file path being uploaded. */
					__( 'Failed to upload Zero runtime file: %s', 'spacefast-static-comments' ),
					$rel_path
				);
				sf_static_comments_update_provision_error( $msg );
				sf_static_comments_log_event( 'provision', $msg, $version_id, 'error' );
				return array(
					'ok'      => false,
					'message' => $msg,
					'state'   => sf_static_comments_get_provision_state(),
				);
			}
		}
	}

	// Ensure production island mounting is enabled once the user explicitly provisions the server.
	update_option( 'sf_static_comments_enable_production', 1, false );

	$next_state = array(
		'status'         => 'provisioned',
		'space_id'       => $conn['space_id'],
		'space_name'     => $conn['space_name'],
		'live_url'       => $conn['live_url'],
		'version_id'     => '' !== $version_id ? $version_id : 'unchanged',
		'deploy_digest'  => $package['deployDigest'],
		'allowed_posts'  => count( $package['manifest']['allowedPostSlugs'] ),
		'reserved_names' => count( $package['manifest']['reservedNames'] ),
		'provisioned_at' => time(),
		'plugin_version' => SF_STATIC_COMMENTS_VERSION,
		'last_error'     => '',
	);
	update_option( SF_STATIC_COMMENTS_PROVISION_STATE_OPTION, $next_state, false );

	$summary = sprintf(
		/* translators: 1: Space name or ID, 2: number of open posts. */
		__( 'Zero comments server provisioned on %1$s (%2$d open posts).', 'spacefast-static-comments' ),
		'' !== $conn['space_name'] ? $conn['space_name'] : $conn['space_id'],
		$next_state['allowed_posts']
	);
	sf_static_comments_log_event(
		'provision',
		$summary,
		'' !== $version_id ? $version_id : $package['deployDigest'],
		'success'
	);

	return array(
		'ok'      => true,
		'message' => $summary,
		'state'   => $next_state,
	);
}

/**
 * Record a provisioning error on the saved state.
 *
 * @param string $message Error message.
 */
function sf_static_comments_update_provision_error( string $message ): void {
	$state               = sf_static_comments_get_provision_state();
	$state['status']     = 'error';
	$state['last_error'] = $message;
	update_option( SF_STATIC_COMMENTS_PROVISION_STATE_OPTION, $state, false );
}

/**
 * Hook into `spacefast-wordpress` + Simply Static publish pipeline without editing `spacefast-wordpress`:
 *
 * 1. Before `Spacefast_Static_Publisher::manifest()` walks the Simply Static archive directory,
 *    a Simply Static task (`sf_static_comments_stage`) writes `sf.jsonc`, `__spacefast/zero/deploy.json`,
 *    and the Zero platform static files into the archive root.
 * 2. When `Spacefast_Client::create_static_version()` sends `POST /v1/spaces/{space_id}/versions`,
 *    our `http_request_args` filter injects `sourceMetadata.statticZero.finalize` (with the site's
 *    compiled QuickJS server bundle, `comments` + `commentRateLimits` schema, and `EXPORT_MANIFEST`)
 *    into the JSON request body.
 */
function sf_static_comments_register_publish_hooks(): void {
	add_filter( 'simplystatic.archive_creation_job.task_list', 'sf_static_comments_filter_simply_static_tasks', 19, 2 );
	add_filter( 'simply_static_class_name', 'sf_static_comments_filter_simply_static_task_class', 20, 2 );
	add_filter( 'http_request_args', 'sf_static_comments_inject_zero_version_metadata', 10, 2 );
}
add_action( 'init', 'sf_static_comments_register_publish_hooks', 25 );

/**
 * Insert the `sf_static_comments_stage` task right before `spacefast_publish` (or `wrapup`).
 *
 * @param array<int, string> $tasks           Task list.
 * @param string             $delivery_method Delivery method.
 * @return array<int, string>
 */
function sf_static_comments_filter_simply_static_tasks( array $tasks, string $delivery_method ): array {
	unset( $delivery_method );
	if ( in_array( 'sf_static_comments_stage', $tasks, true ) ) {
		return $tasks;
	}

	$anchor = array_search( 'spacefast_publish', $tasks, true );
	if ( false === $anchor ) {
		$anchor = array_search( 'wrapup', $tasks, true );
	}
	$position = false === $anchor ? count( $tasks ) : (int) $anchor;
	array_splice( $tasks, $position, 0, array( 'sf_static_comments_stage' ) );

	return $tasks;
}

/**
 * Map `sf_static_comments_stage` to its Simply Static task class.
 *
 * @param string $class_name Resolved class name.
 * @param string $task_name  Task slug.
 */
function sf_static_comments_filter_simply_static_task_class( string $class_name, string $task_name ): string {
	if ( 'sf_static_comments_stage' !== $task_name ) {
		return $class_name;
	}
	sf_static_comments_define_simply_static_task_class();
	return 'SF_Static_Comments_Stage_Task';
}

/**
 * Define `SF_Static_Comments_Stage_Task` lazily once `\Simply_Static\Task` is loaded.
 */
function sf_static_comments_define_simply_static_task_class(): void {
	if ( class_exists( 'SF_Static_Comments_Stage_Task', false ) || ! class_exists( '\\Simply_Static\\Task' ) ) {
		return;
	}

	/**
	 * Stages the Zero capsule artifacts into Simply Static's archive folder right before
	 * `spacefast_publish` computes the SHA-256 manifest.
	 */
	class SF_Static_Comments_Stage_Task extends \Simply_Static\Task {
		/** @var string */
		protected static $task_name = 'sf_static_comments_stage';

		/**
		 * Stage the Zero files and record the manifest snapshot.
		 *
		 * @return bool|WP_Error
		 */
		public function perform() {
			try {
				$options     = \Simply_Static\Options::instance();
				$archive_dir = $options->get_archive_dir();
				$package     = sf_static_comments_stage_into_export_dir( $archive_dir );

				$this->save_status_message(
					sprintf(
						/* translators: %d: number of published posts with open comments. */
						__( 'Staged Spacefast Zero comments capsule (%d open posts)', 'spacefast-static-comments' ),
						count( $package['manifest']['allowedPostSlugs'] )
					)
				);
				return true;
			} catch ( Throwable $error ) {
				sf_static_comments_log_event( 'export', $error->getMessage(), '', 'error' );
				return new WP_Error( 'sf_static_comments_stage_failed', $error->getMessage() );
			}
		}
	}
}

/**
 * Intercept `Spacefast_Client::create_static_version()` (`POST /v1/spaces/{id}/versions`)
 * and attach `sourceMetadata.statticZero.finalize` so Spacefast activates the Zero
 * comment server alongside the static WordPress export.
 *
 * @param array<string, mixed> $args HTTP request args.
 * @param string               $url  Request URL.
 * @return array<string, mixed>
 */
function sf_static_comments_inject_zero_version_metadata( array $args, string $url ): array {
	if ( 'POST' !== strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
		return $args;
	}
	if ( ! preg_match( '#/v1/spaces/[^/]+/versions$#', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
		return $args;
	}
	if ( empty( $args['body'] ) || ! is_string( $args['body'] ) ) {
		return $args;
	}

	$payload = json_decode( $args['body'], true );
	if ( ! is_array( $payload ) || empty( $payload['files'] ) || ! is_array( $payload['files'] ) ) {
		return $args;
	}

	// If `sourceMetadata.statticZero` is already present (e.g. direct provision call), leave it untouched.
	if ( isset( $payload['sourceMetadata']['statticZero'] ) ) {
		return $args;
	}

	try {
		$package = sf_static_comments_compile_zero_package();

		// Ensure `sf.jsonc` and `__spacefast/zero/deploy.json` are present in the manifest wire list
		// even if Simply Static skipped our task (for example, an external caller invoking Spacefast_Static_Publisher).
		$by_path = array();
		foreach ( $payload['files'] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['path'] ) ) {
				$by_path[ (string) $entry['path'] ] = $entry;
			}
		}

		// Strip any accidental raw `.ts`/`.tsx` capsule files from the public file list.
		foreach ( array_keys( $by_path ) as $file_path ) {
			$root_seg = explode( '/', $file_path )[0] ?? '';
			if ( in_array( $root_seg, array( 'client', 'server', 'shared' ), true ) || preg_match( '/\.(?:ts|tsx|mts|cts)$/', $file_path ) ) {
				unset( $by_path[ $file_path ] );
			}
		}

		$payload['files']          = array_values( $by_path );
		$payload['sourceMetadata'] = array_merge(
			isset( $payload['sourceMetadata'] ) && is_array( $payload['sourceMetadata'] ) ? $payload['sourceMetadata'] : array(),
			$package['sourceMetadata']
		);
		$payload['buildLog']       = sprintf(
			"Spacefast Static Comments v%s Zero capsule (%s)\nAllowed posts: %d | Reserved names: %d\n",
			SF_STATIC_COMMENTS_VERSION,
			$package['deployDigest'],
			count( $package['manifest']['allowedPostSlugs'] ),
			count( $package['manifest']['reservedNames'] )
		);

		$args['body'] = wp_json_encode( $payload );

		// Update our provisioning state record so the admin UI reflects the latest publish.
		$conn       = sf_static_comments_spacefast_connection();
		$next_state = array(
			'status'         => 'provisioned',
			'space_id'       => $conn['space_id'],
			'space_name'     => $conn['space_name'],
			'live_url'       => $conn['live_url'],
			'version_id'     => 'publishing',
			'deploy_digest'  => $package['deployDigest'],
			'allowed_posts'  => count( $package['manifest']['allowedPostSlugs'] ),
			'reserved_names' => count( $package['manifest']['reservedNames'] ),
			'provisioned_at' => time(),
			'plugin_version' => SF_STATIC_COMMENTS_VERSION,
			'last_error'     => '',
		);
		update_option( SF_STATIC_COMMENTS_PROVISION_STATE_OPTION, $next_state, false );
		sf_static_comments_log_event(
			'export',
			sprintf(
				/* translators: %d: number of open posts included in the export manifest. */
				__( 'Attached Zero comments capsule to Spacefast publish (%d open posts).', 'spacefast-static-comments' ),
				count( $package['manifest']['allowedPostSlugs'] )
			),
			$package['deployDigest'],
			'changed'
		);
	} catch ( Throwable $error ) {
		sf_static_comments_log_event( 'export', $error->getMessage(), '', 'error' );
	}

	return $args;
}
