<?php
/**
 * Settings → Spacefast Comments admin screen.
 *
 * Uses the Roots & Fruit admin design system from Agent Role / Context Authority Toolkit:
 * - Warm cream canvas (`--rf-cream`), Domine serif headings + Nunito Sans body
 * - Slanted-shoulder folder tabs (`Server`, `Settings`, `Activity`)
 * - Accessible toggle switches (`Enabled` / `Disabled` text + track)
 * - Copyable CDS code blocks + sticky Roots & Fruit footer with "About" popover
 * - Positioned directly beneath `Settings → Spacefast` (`options-general.php`)
 *
 * @package spacefast-static-comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SF_STATIC_COMMENTS_ADMIN_SLUG', 'spacefast-static-comments' );

/**
 * Register the Settings → Spacefast Comments screen and form handlers.
 */
function sf_static_comments_admin_register(): void {
	add_action( 'admin_menu', 'sf_static_comments_admin_menu', 11 );
	add_action( 'admin_menu', 'sf_static_comments_order_settings_submenu', 999 );
	add_action( 'admin_enqueue_scripts', 'sf_static_comments_admin_assets' );
	add_action( 'admin_post_sf_static_comments_provision', 'sf_static_comments_handle_provision' );
	add_action( 'admin_post_sf_static_comments_save_settings', 'sf_static_comments_handle_save_settings' );
	add_action( 'admin_post_sf_static_comments_rotate_salt', 'sf_static_comments_handle_rotate_salt' );
}
add_action( 'init', 'sf_static_comments_admin_register' );

/**
 * Add "Spacefast Comments" under Settings (`options-general.php`).
 */
function sf_static_comments_admin_menu(): void {
	add_options_page(
		__( 'Spacefast Comments', 'spacefast-static-comments' ),
		__( 'Spacefast Comments', 'spacefast-static-comments' ),
		'manage_options',
		SF_STATIC_COMMENTS_ADMIN_SLUG,
		'sf_static_comments_render_admin_page'
	);
}

/**
 * Position "Spacefast Comments" immediately after "Spacefast" in the Settings submenu.
 */
function sf_static_comments_order_settings_submenu(): void {
	global $submenu;
	if ( empty( $submenu['options-general.php'] ) || ! is_array( $submenu['options-general.php'] ) ) {
		return;
	}

	$items   = array_values( $submenu['options-general.php'] );
	$our_idx = null;
	foreach ( $items as $idx => $item ) {
		if ( isset( $item[2] ) && SF_STATIC_COMMENTS_ADMIN_SLUG === $item[2] ) {
			$our_idx = $idx;
			break;
		}
	}
	if ( null === $our_idx ) {
		return;
	}

	$our_item = $items[ $our_idx ];
	array_splice( $items, $our_idx, 1 );

	$sf_idx = null;
	foreach ( $items as $idx => $item ) {
		if ( isset( $item[2] ) && 'spacefast-wordpress' === $item[2] ) {
			$sf_idx = $idx;
			break;
		}
	}

	if ( null !== $sf_idx ) {
		array_splice( $items, $sf_idx + 1, 0, array( $our_item ) );
	} else {
		$items[] = $our_item;
	}

	$submenu['options-general.php'] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}

/**
 * Enqueue the R&F admin stylesheet and script on Settings → Spacefast Comments only.
 *
 * @param string $hook Current admin page hook.
 */
function sf_static_comments_admin_assets( string $hook ): void {
	if ( 'settings_page_' . SF_STATIC_COMMENTS_ADMIN_SLUG !== $hook ) {
		return;
	}

	$plugin_file = dirname( __DIR__ ) . '/spacefast-static-comments.php';
	$style_path  = dirname( __DIR__ ) . '/admin/css/ssc-admin.css';
	$script_path = dirname( __DIR__ ) . '/admin/js/ssc-admin.js';
	$version     = SF_STATIC_COMMENTS_VERSION . '.' . (string) max(
		file_exists( $style_path ) ? (int) filemtime( $style_path ) : 0,
		file_exists( $script_path ) ? (int) filemtime( $script_path ) : 0
	);

	wp_enqueue_style(
		'sf-static-comments-admin',
		plugins_url( 'admin/css/ssc-admin.css', $plugin_file ),
		array(),
		$version
	);
	wp_enqueue_script(
		'sf-static-comments-admin',
		plugins_url( 'admin/js/ssc-admin.js', $plugin_file ),
		array(),
		$version,
		true
	);
	wp_localize_script(
		'sf-static-comments-admin',
		'sscAdmin',
		array(
			'copied' => __( 'Copied', 'spacefast-static-comments' ),
		)
	);
}

/**
 * Require `manage_options` capability.
 */
function sf_static_comments_require_manage_options(): void {
	if ( current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_die(
		esc_html__( 'You do not have permission to manage Spacefast Comments.', 'spacefast-static-comments' ),
		'',
		array( 'response' => 403 )
	);
}

/**
 * Store a one-time notice for the current administrator.
 *
 * @param string $type    `success`, `warning`, or `error`.
 * @param string $message Notice text.
 */
function sf_static_comments_set_admin_notice( string $type, string $message ): void {
	set_transient(
		'sf_static_comments_notice_' . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
		),
		60
	);
}

/**
 * Handle the "Provision / Update Zero Comments Server" button.
 */
function sf_static_comments_handle_provision(): void {
	sf_static_comments_require_manage_options();
	check_admin_referer( 'sf_static_comments_provision', 'sf_static_comments_nonce' );

	$result = sf_static_comments_provision_zero_server();
	sf_static_comments_set_admin_notice(
		$result['ok'] ? 'success' : 'error',
		$result['message']
	);

	wp_safe_redirect(
		add_query_arg(
			array(
				'page' => SF_STATIC_COMMENTS_ADMIN_SLUG,
				'tab'  => 'server',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Handle saving Settings tab controls.
 */
function sf_static_comments_handle_save_settings(): void {
	sf_static_comments_require_manage_options();
	check_admin_referer( 'sf_static_comments_save_settings', 'sf_static_comments_settings_nonce' );

	$enable_prod  = ! empty( $_POST['sf_static_comments_enable_production'] ) ? 1 : 0;
	$require_auth = ! empty( $_POST['sf_static_comments_require_auth_opt'] ) ? 1 : 0;
	$min_fill_ms  = isset( $_POST['sf_static_comments_min_fill_ms_opt'] ) ? max( 500, min( 30000, absint( wp_unslash( $_POST['sf_static_comments_min_fill_ms_opt'] ) ) ) ) : 2000;
	$rate_limit   = isset( $_POST['sf_static_comments_rate_limit_opt'] ) ? max( 1, min( 100, absint( wp_unslash( $_POST['sf_static_comments_rate_limit_opt'] ) ) ) ) : 5;
	$rate_window  = isset( $_POST['sf_static_comments_rate_window_opt'] ) ? max( 60, min( 86400, absint( wp_unslash( $_POST['sf_static_comments_rate_window_opt'] ) ) ) ) : 600;
	$max_per_post = isset( $_POST['sf_static_comments_max_per_post_opt'] ) ? max( 10, min( 5000, absint( wp_unslash( $_POST['sf_static_comments_max_per_post_opt'] ) ) ) ) : 200;
	$max_posts    = isset( $_POST['sf_static_comments_max_posts_opt'] ) ? max( 5, min( 5000, absint( wp_unslash( $_POST['sf_static_comments_max_posts_opt'] ) ) ) ) : 100;
	$raw_dev_url  = isset( $_POST['sf_static_comments_sf_dev_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['sf_static_comments_sf_dev_url'] ) ) ) : '';

	if ( '' !== $raw_dev_url ) {
		$host   = strtolower( trim( (string) wp_parse_url( $raw_dev_url, PHP_URL_HOST ), '[]' ) );
		$scheme = strtolower( (string) wp_parse_url( $raw_dev_url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) ) {
			sf_static_comments_set_admin_notice(
				'error',
				__( 'Local sf dev proxy URL must be a loopback http(s) URL (127.0.0.1, localhost, or ::1).', 'spacefast-static-comments' )
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => SF_STATIC_COMMENTS_ADMIN_SLUG,
						'tab'  => 'settings',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}
	}

	update_option( 'sf_static_comments_enable_production', $enable_prod, false );
	update_option( 'sf_static_comments_require_auth_opt', $require_auth, false );
	update_option( 'sf_static_comments_min_fill_ms_opt', $min_fill_ms, false );
	update_option( 'sf_static_comments_rate_limit_opt', $rate_limit, false );
	update_option( 'sf_static_comments_rate_window_opt', $rate_window, false );
	update_option( 'sf_static_comments_max_per_post_opt', $max_per_post, false );
	update_option( 'sf_static_comments_max_posts_opt', $max_posts, false );
	if ( '' === $raw_dev_url ) {
		delete_option( 'sf_static_comments_sf_dev_url' );
	} else {
		update_option( 'sf_static_comments_sf_dev_url', rtrim( $raw_dev_url, '/' ), false );
	}

	sf_static_comments_log_event(
		'settings',
		sprintf(
			/* translators: 1: rate limit count, 2: rate window seconds, 3: max per post. */
			__( 'Updated comment security settings (%1$d/%2$ds rate limit, %3$d max per post).', 'spacefast-static-comments' ),
			$rate_limit,
			$rate_window,
			$max_per_post
		),
		'production=' . ( $enable_prod ? 'on' : 'off' ),
		'changed'
	);

	sf_static_comments_set_admin_notice(
		'success',
		__( 'Spacefast Comments settings saved.', 'spacefast-static-comments' )
	);

	wp_safe_redirect(
		add_query_arg(
			array(
				'page' => SF_STATIC_COMMENTS_ADMIN_SLUG,
				'tab'  => 'settings',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Handle rotating the manifest salt used to hash private `user_login` values.
 */
function sf_static_comments_handle_rotate_salt(): void {
	sf_static_comments_require_manage_options();
	check_admin_referer( 'sf_static_comments_rotate_salt', 'sf_static_comments_salt_nonce' );

	$new_salt = bin2hex( random_bytes( 16 ) );
	update_option( SF_STATIC_COMMENTS_SALT_OPTION, $new_salt, false );
	sf_static_comments_log_event(
		'settings',
		__( 'Rotated reserved-login SHA-256 manifest salt.', 'spacefast-static-comments' ),
		substr( $new_salt, 0, 8 ) . '…',
		'changed'
	);

	sf_static_comments_set_admin_notice(
		'success',
		__( 'Manifest login salt rotated. Provision or publish to update the live Zero capsule.', 'spacefast-static-comments' )
	);

	wp_safe_redirect(
		add_query_arg(
			array(
				'page' => SF_STATIC_COMMENTS_ADMIN_SLUG,
				'tab'  => 'server',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Apply saved admin option values to the security filters when no code filter overrides them.
 */
function sf_static_comments_register_option_backed_filters(): void {
	add_filter(
		'sf_static_comments_require_auth',
		static function ( bool $default ): bool {
			return $default || (bool) get_option( 'sf_static_comments_require_auth_opt', false );
		},
		5
	);
	add_filter(
		'sf_static_comments_min_fill_ms',
		static function ( int $default ): int {
			$opt = (int) get_option( 'sf_static_comments_min_fill_ms_opt', 0 );
			return $opt > 0 ? $opt : $default;
		},
		5
	);
	add_filter(
		'sf_static_comments_rate_limit',
		static function ( int $default ): int {
			$opt = (int) get_option( 'sf_static_comments_rate_limit_opt', 0 );
			return $opt > 0 ? $opt : $default;
		},
		5
	);
	add_filter(
		'sf_static_comments_rate_window',
		static function ( int $default ): int {
			$opt = (int) get_option( 'sf_static_comments_rate_window_opt', 0 );
			return $opt > 0 ? $opt : $default;
		},
		5
	);
	add_filter(
		'sf_static_comments_max_per_post',
		static function ( int $default ): int {
			$opt = (int) get_option( 'sf_static_comments_max_per_post_opt', 0 );
			return $opt > 0 ? $opt : $default;
		},
		5
	);
	add_filter(
		'sf_static_comments_max_posts',
		static function ( int $default ): int {
			$opt = (int) get_option( 'sf_static_comments_max_posts_opt', 0 );
			return $opt > 0 ? $opt : $default;
		},
		5
	);
}
sf_static_comments_register_option_backed_filters();

/**
 * Current tab slug (`server`, `settings`, or `activity`).
 */
function sf_static_comments_current_tab(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'server';
	if ( in_array( $tab, array( 'server', 'settings', 'activity' ), true ) ) {
		return $tab;
	}
	return 'server';
}

/**
 * Render the Settings → Spacefast Comments screen.
 */
function sf_static_comments_render_admin_page(): void {
	sf_static_comments_require_manage_options();

	$plugin_file = dirname( __DIR__ ) . '/spacefast-static-comments.php';
	$notice      = get_transient( 'sf_static_comments_notice_' . get_current_user_id() );
	if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
		delete_transient( 'sf_static_comments_notice_' . get_current_user_id() );
	} else {
		$notice = null;
	}

	$tab = sf_static_comments_current_tab();

	echo '<div class="wrap ar-rf-settings">';
	echo '<header class="ar-rf-settings__header">';
	echo '<h1 class="ar-rf-settings__title">';
	echo '<img class="ar-rf-settings__mark" src="' . esc_url( plugins_url( 'admin/images/comments-mark.svg', $plugin_file ) ) . '" alt="" width="56" height="56" />';
	echo esc_html__( 'Spacefast Comments', 'spacefast-static-comments' );
	echo '</h1>';
	echo '<p class="ar-rf-settings__lede">' . esc_html__( 'Static WordPress comment archives plus a hardened Spacefast Zero comment server on your Space.', 'spacefast-static-comments' ) . '</p>';
	echo '</header>';
	echo '<hr class="wp-header-end" />';
	echo '<div class="ar-rf-settings__body">';

	if ( is_array( $notice ) ) {
		$class = 'error' === ( $notice['type'] ?? '' ) ? 'notice-error' : ( 'warning' === ( $notice['type'] ?? '' ) ? 'notice-warning' : 'notice-success' );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	echo '<div class="rf-tabs">';
	sf_static_comments_render_tabs( $tab );
	echo '<div class="rf-tabs__panel">';
	if ( 'settings' === $tab ) {
		sf_static_comments_render_settings_tab();
	} elseif ( 'activity' === $tab ) {
		sf_static_comments_render_activity_tab();
	} else {
		sf_static_comments_render_server_tab();
	}
	echo '</div></div>';
	echo '</div>';
	sf_static_comments_render_footer( $plugin_file );
	echo '</div>';
}

/**
 * Folder tabs navigation.
 *
 * @param string $current Active tab slug.
 */
function sf_static_comments_render_tabs( string $current ): void {
	echo '<nav class="rf-tabs__list" aria-label="' . esc_attr__( 'Spacefast Comments', 'spacefast-static-comments' ) . '">';
	sf_static_comments_render_tab_link( 'server', __( 'Zero Server', 'spacefast-static-comments' ), $current );
	sf_static_comments_render_tab_link( 'settings', __( 'Settings & Security', 'spacefast-static-comments' ), $current );
	sf_static_comments_render_tab_link( 'activity', __( 'Activity', 'spacefast-static-comments' ), $current );
	echo '</nav>';
}

/**
 * Render one folder tab with the slanted SVG shoulder.
 *
 * @param string $slug    Tab slug.
 * @param string $label   Tab label.
 * @param string $current Current tab.
 */
function sf_static_comments_render_tab_link( string $slug, string $label, string $current ): void {
	$active = $slug === $current;
	$url    = add_query_arg(
		array(
			'page' => SF_STATIC_COMMENTS_ADMIN_SLUG,
			'tab'  => $slug,
		),
		admin_url( 'options-general.php' )
	);

	echo '<a class="rf-tabs__tab' . ( $active ? ' is-current' : '' ) . '" href="' . esc_url( $url ) . '"';
	if ( $active ) {
		echo ' aria-current="page"';
	}
	echo '>';
	echo '<span class="rf-tabs__dot" aria-hidden="true"></span>';
	echo esc_html( $label );
	echo sf_static_comments_kses_svg( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'<svg class="rf-tabs__shoulder" viewBox="0 0 48 40" width="48" height="40" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path class="rf-tabs__shoulder-face" d="M0 0H20Q26 0 30 8L46 40H0Z" /><path class="rf-tabs__shoulder-edge" d="M0 .5H20Q26 .5 30 8.5L46 40" /></svg>'
	);
	echo '</a>';
}

/**
 * Render the "Zero Server" tab: visual connection status, provisioning button,
 * live security posture summary, and inspectable `export-manifest.ts`.
 */
function sf_static_comments_render_server_tab(): void {
	$conn    = sf_static_comments_spacefast_connection();
	$state   = sf_static_comments_get_provision_state();
	$package = null;
	$pkg_err = '';
	try {
		$package = sf_static_comments_compile_zero_package();
	} catch ( Throwable $e ) {
		$pkg_err = $e->getMessage();
	}

	$current_digest = is_array( $package ) ? (string) $package['deployDigest'] : '';
	$is_provisioned = 'provisioned' === $state['status'] && '' !== $state['space_id'] && $state['space_id'] === $conn['space_id'];
	$in_sync        = $is_provisioned && '' !== $current_digest && $state['deploy_digest'] === $current_digest;

	if ( ! $conn['selected'] ) {
		$badge_class = 'ar-rf-outcome--denied';
		$badge_label = __( 'Space Not Connected', 'spacefast-static-comments' );
	} elseif ( 'error' === $state['status'] ) {
		$badge_class = 'ar-rf-outcome--error';
		$badge_label = __( 'Needs Attention', 'spacefast-static-comments' );
	} elseif ( $in_sync ) {
		$badge_class = 'ar-rf-outcome--success';
		$badge_label = __( 'Provisioned & In Sync', 'spacefast-static-comments' );
	} elseif ( $is_provisioned ) {
		$badge_class = 'ar-rf-outcome--changed';
		$badge_label = __( 'Provisioned · Update Ready', 'spacefast-static-comments' );
	} else {
		$badge_class = 'ar-rf-outcome--denied';
		$badge_label = __( 'Ready to Provision', 'spacefast-static-comments' );
	}

	echo '<div class="ar-rf-panel">';
	echo '<div class="ar-rf-toolbar">';
	echo '<div>';
	echo '<h2>' . esc_html__( 'Spacefast Zero Comment Server', 'spacefast-static-comments' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'Lives on the same Space as your static blog so /api/comments shares your site origin and passes every strict Origin, honeypot, and rate-limit check.', 'spacefast-static-comments' ) . '</p>';
	echo '</div>';
	echo '<span class="ar-rf-outcome ' . esc_attr( $badge_class ) . '">' . esc_html( $badge_label ) . '</span>';
	echo '</div>';

	if ( ! $conn['selected'] ) {
		$sf_url = admin_url( 'options-general.php?page=spacefast-wordpress' );
		echo '<div class="ar-rf-mcp-notice" role="note">';
		echo '<span>' . esc_html__( 'Connect your site to a Space in Settings → Spacefast first. Once connected, return here to provision the Zero comment server on that Space.', 'spacefast-static-comments' ) . ' ';
		echo '<a href="' . esc_url( $sf_url ) . '"><strong>' . esc_html__( 'Open Settings → Spacefast', 'spacefast-static-comments' ) . '</strong></a></span>';
		echo '</div>';
	} else {
		echo '<div class="ssc-status-grid">';
		sf_static_comments_render_copy_row(
			__( 'Connected Space', 'spacefast-static-comments' ),
			( '' !== $conn['space_name'] ? $conn['space_name'] . ' (' . $conn['space_id'] . ')' : $conn['space_id'] )
		);
		if ( '' !== $conn['live_url'] ) {
			sf_static_comments_render_copy_row(
				__( 'Live Comment Endpoint', 'spacefast-static-comments' ),
				untrailingslashit( $conn['live_url'] ) . '/api/comments'
			);
		}
		if ( '' !== $current_digest ) {
			sf_static_comments_render_copy_row(
				__( 'Compiled Capsule Digest', 'spacefast-static-comments' ),
				$current_digest
			);
		}
		if ( $is_provisioned && $state['provisioned_at'] > 0 ) {
			$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['provisioned_at'], wp_timezone() );
			sf_static_comments_render_copy_row(
				__( 'Last Provisioned', 'spacefast-static-comments' ),
				$when . ( '' !== $state['version_id'] ? ' · ' . $state['version_id'] : '' )
			);
		}
		echo '</div>';

		if ( '' !== $state['last_error'] ) {
			echo '<div class="ar-rf-mcp-notice ssc-notice--error" role="alert">';
			echo '<span><strong>' . esc_html__( 'Last provisioning error:', 'spacefast-static-comments' ) . '</strong> ' . esc_html( $state['last_error'] ) . '</span>';
			echo '</div>';
		}

		echo '<div class="ssc-actions-bar">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ar-rf-inline-form">';
		wp_nonce_field( 'sf_static_comments_provision', 'sf_static_comments_nonce' );
		echo '<input type="hidden" name="action" value="sf_static_comments_provision" />';
		$btn_label = $is_provisioned
			? ( $in_sync ? __( 'Re-verify & Push Zero Server', 'spacefast-static-comments' ) : __( 'Update Zero Comment Server Now', 'spacefast-static-comments' ) )
			: __( 'Provision Zero Comment Server on This Space', 'spacefast-static-comments' );
		echo '<button type="submit" class="button button-primary">' . esc_html( $btn_label ) . '</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ar-rf-inline-form">';
		wp_nonce_field( 'sf_static_comments_rotate_salt', 'sf_static_comments_salt_nonce' );
		echo '<input type="hidden" name="action" value="sf_static_comments_rotate_salt" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Rotate Login Hash Salt', 'spacefast-static-comments' ) . '</button>';
		echo '</form>';
		echo '</div>';
		echo '<p class="description ssc-auto-note">' . esc_html__( 'Automatic build hook active: every Simply Static → Spacefast publish automatically stages this compiled Zero capsule and updates the post allow-list and reserved author hashes.', 'spacefast-static-comments' ) . '</p>';
	}

	if ( '' !== $pkg_err ) {
		echo '<p class="description">' . esc_html( $pkg_err ) . '</p>';
		echo '</div>';
		return;
	}

	$manifest = $package['manifest'];
	echo '<hr class="ssc-divider" />';
	echo '<h3>' . esc_html__( 'Hardened Security Manifest Snapshot', 'spacefast-static-comments' ) . '</h3>';
	echo '<p class="description">' . esc_html__( 'Baked directly into the compiled Zero QuickJS bundle. Private WordPress user_login values are never exported in plain text—only salted SHA-256 digests are included.', 'spacefast-static-comments' ) . '</p>';

	echo '<table class="widefat ar-rf-table ssc-summary-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Security Guard', 'spacefast-static-comments' ) . '</th>';
	echo '<th>' . esc_html__( 'Current Value', 'spacefast-static-comments' ) . '</th>';
	echo '<th>' . esc_html__( 'Enforcement', 'spacefast-static-comments' ) . '</th>';
	echo '</tr></thead><tbody>';

	$rows = array(
		array(
			'guard'  => __( 'Allowed Post Slugs (published + comments open)', 'spacefast-static-comments' ),
			'value'  => sprintf(
				/* translators: %d: count of open posts. */
				_n( '%d open post', '%d open posts', count( $manifest['allowedPostSlugs'] ), 'spacefast-static-comments' ),
				count( $manifest['allowedPostSlugs'] )
			),
			'status' => __( '404 post_not_found on unknown/closed slugs', 'spacefast-static-comments' ),
		),
		array(
			'guard'  => __( 'Reserved Editor Display Names & Salted Login Digests', 'spacefast-static-comments' ),
			'value'  => sprintf(
				/* translators: 1: display name count, 2: login hash count. */
				__( '%1$d display names · %2$d SHA-256 login hashes', 'spacefast-static-comments' ),
				count( $manifest['reservedNames'] ),
				count( $manifest['reservedNameHashes'] )
			),
			'status' => __( '422 name_reserved + server-bound SSO names', 'spacefast-static-comments' ),
		),
		array(
			'guard'  => __( 'Origin, Content-Type & Bot Traps (hp + t)', 'spacefast-static-comments' ),
			'value'  => sprintf(
				/* translators: %d: minimum fill time in milliseconds. */
				__( 'application/json + same-host Origin + min fill %d ms', 'spacefast-static-comments' ),
				(int) $manifest['minFillMs']
			),
			'status' => __( '415 / 403 / 422 too_fast', 'spacefast-static-comments' ),
		),
		array(
			'guard'  => __( 'Caller Rate Limit & Storage Caps', 'spacefast-static-comments' ),
			'value'  => sprintf(
				/* translators: 1: rate limit, 2: rate window seconds, 3: max per post, 4: max posts. */
				__( '%1$d writes / %2$ds · %3$d per post · %4$d posts max', 'spacefast-static-comments' ),
				(int) $manifest['rateLimit'],
				(int) $manifest['rateWindowSeconds'],
				(int) $manifest['maxPerPost'],
				(int) $manifest['maxPosts']
			),
			'status' => __( '429 rate_limited / 422 store_full', 'spacefast-static-comments' ),
		),
	);

	foreach ( $rows as $row ) {
		echo '<tr>';
		echo '<td><strong>' . esc_html( $row['guard'] ) . '</strong></td>';
		echo '<td>' . esc_html( $row['value'] ) . '</td>';
		echo '<td><span class="ar-rf-outcome ar-rf-outcome--success">' . esc_html( $row['status'] ) . '</span></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';

	sf_static_comments_render_code_block(
		__( 'Compiled shared/export-manifest.ts (baked into Zero bundle)', 'spacefast-static-comments' ),
		$package['manifestTs'],
		'TS',
		__( 'Copy export-manifest.ts', 'spacefast-static-comments' )
	);

	echo '</div>';
}

/**
 * Render the "Settings & Security" tab using Agent Role's accessible toggle switches
 * and numeric field layout.
 */
function sf_static_comments_render_settings_tab(): void {
	$enable_prod  = (bool) get_option( 'sf_static_comments_enable_production', false );
	$require_auth = (bool) apply_filters( 'sf_static_comments_require_auth', (bool) get_option( 'sf_static_comments_require_auth_opt', false ) );
	$min_fill_ms  = (int) apply_filters( 'sf_static_comments_min_fill_ms', 2000 );
	$rate_limit   = (int) apply_filters( 'sf_static_comments_rate_limit', 5 );
	$rate_window  = (int) apply_filters( 'sf_static_comments_rate_window', 600 );
	$max_per_post = (int) apply_filters( 'sf_static_comments_max_per_post', 200 );
	$max_posts    = (int) apply_filters( 'sf_static_comments_max_posts', 100 );
	$dev_url      = (string) get_option( 'sf_static_comments_sf_dev_url', '' );

	echo '<div class="ar-rf-panel">';
	echo '<h2>' . esc_html__( 'Comment Island & Security Rules', 'spacefast-static-comments' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'These settings drive both the local WordPress Studio /api/comments bridge and the compiled Spacefast Zero capsule.', 'spacefast-static-comments' ) . '</p>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ar-rf-log-settings ssc-settings-form">';
	wp_nonce_field( 'sf_static_comments_save_settings', 'sf_static_comments_settings_nonce' );
	echo '<input type="hidden" name="action" value="sf_static_comments_save_settings" />';

	echo '<div class="ssc-toggle-group">';
	sf_static_comments_render_toggle(
		array(
			'name'       => 'sf_static_comments_enable_production',
			'value'      => '1',
			'checked'    => $enable_prod,
			'labelledby' => 'ssc-enable-prod-label',
			'title'      => __( 'Mount live comment island on production exports', 'spacefast-static-comments' ),
			'note'       => __( 'When enabled, singular posts with open comments mount the live Spacefast Zero comment form in production exports as well as local/staging.', 'spacefast-static-comments' ),
		)
	);

	sf_static_comments_render_toggle(
		array(
			'name'       => 'sf_static_comments_require_auth_opt',
			'value'      => '1',
			'checked'    => $require_auth,
			'labelledby' => 'ssc-require-auth-label',
			'title'      => __( 'Require signed-in session to post comments', 'spacefast-static-comments' ),
			'note'       => __( 'When disabled, guests can post with a display name (marked with a guest badge) so long as it does not collide with an editor or author name.', 'spacefast-static-comments' ),
		)
	);
	echo '</div>';

	echo '<h3>' . esc_html__( 'Abuse & Storage Thresholds', 'spacefast-static-comments' ) . '</h3>';

	echo '<p class="ar-rf-log-settings__field"><label for="sf_static_comments_min_fill_ms_opt">' . esc_html__( 'Minimum form fill time', 'spacefast-static-comments' ) . '</label> ';
	echo '<input type="number" class="small-text" id="sf_static_comments_min_fill_ms_opt" name="sf_static_comments_min_fill_ms_opt" min="500" max="30000" step="100" value="' . esc_attr( (string) $min_fill_ms ) . '" required /> ';
	echo '<span>' . esc_html__( 'ms (rejects instant bot submissions with 422 too_fast)', 'spacefast-static-comments' ) . '</span></p>';

	echo '<p class="ar-rf-log-settings__field"><label for="sf_static_comments_rate_limit_opt">' . esc_html__( 'Rate limit per caller', 'spacefast-static-comments' ) . '</label> ';
	echo '<input type="number" class="small-text" id="sf_static_comments_rate_limit_opt" name="sf_static_comments_rate_limit_opt" min="1" max="100" value="' . esc_attr( (string) $rate_limit ) . '" required /> ';
	echo '<span>' . esc_html__( 'accepted comments per window', 'spacefast-static-comments' ) . '</span></p>';

	echo '<p class="ar-rf-log-settings__field"><label for="sf_static_comments_rate_window_opt">' . esc_html__( 'Rate-limit window', 'spacefast-static-comments' ) . '</label> ';
	echo '<input type="number" class="small-text" id="sf_static_comments_rate_window_opt" name="sf_static_comments_rate_window_opt" min="60" max="86400" step="60" value="' . esc_attr( (string) $rate_window ) . '" required /> ';
	echo '<span>' . esc_html__( 'seconds (600 = 10 minutes)', 'spacefast-static-comments' ) . '</span></p>';

	echo '<p class="ar-rf-log-settings__field"><label for="sf_static_comments_max_per_post_opt">' . esc_html__( 'Max comments per post', 'spacefast-static-comments' ) . '</label> ';
	echo '<input type="number" class="small-text" id="sf_static_comments_max_per_post_opt" name="sf_static_comments_max_per_post_opt" min="10" max="5000" value="' . esc_attr( (string) $max_per_post ) . '" required /></p>';

	echo '<p class="ar-rf-log-settings__field"><label for="sf_static_comments_max_posts_opt">' . esc_html__( 'Max distinct post slugs', 'spacefast-static-comments' ) . '</label> ';
	echo '<input type="number" class="small-text" id="sf_static_comments_max_posts_opt" name="sf_static_comments_max_posts_opt" min="5" max="5000" value="' . esc_attr( (string) $max_posts ) . '" required /></p>';

	echo '<h3>' . esc_html__( 'Local Studio Developer Proxy (Optional)', 'spacefast-static-comments' ) . '</h3>';
	echo '<p class="ar-rf-log-settings__field"><label for="sf_static_comments_sf_dev_url">' . esc_html__( 'Local sf dev URL', 'spacefast-static-comments' ) . '</label> ';
	echo '<input type="text" class="regular-text" id="sf_static_comments_sf_dev_url" name="sf_static_comments_sf_dev_url" placeholder="http://127.0.0.1:4173" value="' . esc_attr( $dev_url ) . '" /></p>';
	echo '<p class="description">' . esc_html__( 'Leave empty in normal Studio use. When set to a loopback URL (127.0.0.1, localhost, or ::1), Studio validates requests first and relays /api/comments to a running sf dev process.', 'spacefast-static-comments' ) . '</p>';

	submit_button( __( 'Save Comment Settings', 'spacefast-static-comments' ) );
	echo '</form></div>';
}

/**
 * Render the "Activity" tab showing recent provisioning, export staging, and settings events.
 */
function sf_static_comments_render_activity_tab(): void {
	$rows = get_option( SF_STATIC_COMMENTS_LOG_OPTION, array() );
	$rows = is_array( $rows ) ? $rows : array();

	$labels = array(
		'provision' => __( 'Zero Provision', 'spacefast-static-comments' ),
		'export'    => __( 'Static Export', 'spacefast-static-comments' ),
		'settings'  => __( 'Security Settings', 'spacefast-static-comments' ),
	);
	$outcomes = array(
		'success' => __( 'Success', 'spacefast-static-comments' ),
		'changed' => __( 'Updated', 'spacefast-static-comments' ),
		'error'   => __( 'Error', 'spacefast-static-comments' ),
	);

	echo '<div class="ar-rf-panel">';
	echo '<div class="ar-rf-toolbar ar-rf-activity__head">';
	echo '<div>';
	echo '<h2>' . esc_html__( 'Activity', 'spacefast-static-comments' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'Recent Zero comment server provisioning, export manifest staging, and security configuration changes.', 'spacefast-static-comments' ) . '</p>';
	echo '</div>';
	echo '</div>';

	if ( empty( $rows ) ) {
		echo '<p>' . esc_html__( 'No provisioning or export events recorded yet.', 'spacefast-static-comments' ) . '</p>';
		echo '</div>';
		return;
	}

	echo '<table class="widefat ar-rf-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Time', 'spacefast-static-comments' ) . '</th>';
	echo '<th>' . esc_html__( 'Event type', 'spacefast-static-comments' ) . '</th>';
	echo '<th>' . esc_html__( 'Details', 'spacefast-static-comments' ) . '</th>';
	echo '<th>' . esc_html__( 'Outcome', 'spacefast-static-comments' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$ts      = isset( $row['created_at'] ) ? strtotime( (string) $row['created_at'] . ' UTC' ) : false;
		$when    = $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts, wp_timezone() ) : (string) ( $row['created_at'] ?? '' );
		$type    = (string) ( $row['event_type'] ?? 'provision' );
		$label   = $labels[ $type ] ?? $type;
		$outcome = (string) ( $row['outcome'] ?? 'success' );
		$out_lbl = $outcomes[ $outcome ] ?? $outcome;
		$detail  = (string) ( $row['detail'] ?? '' );
		$id_str  = (string) ( $row['identifier'] ?? '' );

		echo '<tr>';
		echo '<td>' . esc_html( $when ) . '</td>';
		echo '<td>' . esc_html( $label ) . '</td>';
		echo '<td><span class="ar-rf-activity__detail">' . esc_html( $detail ) . '</span>';
		if ( '' !== $id_str ) {
			echo '<span class="ar-rf-activity__id">' . esc_html( $id_str ) . '</span>';
		}
		echo '</td>';
		echo '<td><span class="ar-rf-outcome ar-rf-outcome--' . esc_attr( $outcome ) . '">' . esc_html( $out_lbl ) . '</span></td>';
		echo '</tr>';
	}

	echo '</tbody></table></div>';
}

/**
 * Render an Agent Role style accessible switch row.
 *
 * @param array<string, mixed> $args Switch arguments.
 */
function sf_static_comments_render_toggle( array $args ): void {
	$name       = (string) $args['name'];
	$value      = (string) $args['value'];
	$checked    = ! empty( $args['checked'] );
	$labelledby = (string) $args['labelledby'];
	$title      = (string) ( $args['title'] ?? '' );
	$note       = (string) ( $args['note'] ?? '' );
	$state_id   = $labelledby . '-state';
	$note_id    = $labelledby . '-note';
	$enabled    = _x( 'Enabled', 'toggle state', 'spacefast-static-comments' );
	$disabled   = _x( 'Disabled', 'toggle state', 'spacefast-static-comments' );

	echo '<div class="ssc-toggle-row">';
	echo '<div class="ssc-toggle-row__copy">';
	echo '<strong id="' . esc_attr( $labelledby ) . '">' . esc_html( $title ) . '</strong>';
	if ( '' !== $note ) {
		echo '<p class="description" id="' . esc_attr( $note_id ) . '">' . esc_html( $note ) . '</p>';
	}
	echo '</div>';
	echo '<label class="ar-rf-toggle">';
	echo '<input class="ar-rf-toggle__input" type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"';
	echo ' aria-labelledby="' . esc_attr( $labelledby . ' ' . $state_id ) . '"';
	if ( '' !== $note ) {
		echo ' aria-describedby="' . esc_attr( $note_id ) . '"';
	}
	echo ' ' . checked( $checked, true, false ) . ' />';
	echo '<span class="ar-rf-toggle__track" aria-hidden="true"></span>';
	echo '<span class="ar-rf-toggle__state" id="' . esc_attr( $state_id ) . '" data-enabled="' . esc_attr( $enabled ) . '" data-disabled="' . esc_attr( $disabled ) . '">';
	echo esc_html( $checked ? $enabled : $disabled );
	echo '</span>';
	echo '</label>';
	echo '</div>';
}

/**
 * Labeled value with a Copy button (matching Agent Role's `.ar-rf-copy`).
 *
 * @param string $label Label text.
 * @param string $value Value to copy.
 */
function sf_static_comments_render_copy_row( string $label, string $value ): void {
	echo '<div class="ar-rf-copy">';
	echo '<span class="ar-rf-copy__label">' . esc_html( $label ) . '</span>';
	echo '<code class="ar-rf-copy__value">' . esc_html( $value ) . '</code>';
	echo '<button type="button" class="button ar-rf-copy__button">' . esc_html__( 'Copy', 'spacefast-static-comments' ) . '</button>';
	echo '</div>';
}

/**
 * CDS macOS-dots code block with copy button (matching Agent Role's `render_code_block`).
 *
 * @param string $label      Heading above the block.
 * @param string $code       Code content.
 * @param string $lang       Language badge.
 * @param string $copy_label Accessible copy button label.
 */
function sf_static_comments_render_code_block( string $label, string $code, string $lang = 'TS', string $copy_label = '' ): void {
	if ( '' === $copy_label ) {
		$copy_label = __( 'Copy code', 'spacefast-static-comments' );
	}
	echo '<p class="ar-rf-code-label">' . esc_html( $label ) . '</p>';
	echo '<div class="rf-code">';
	echo '<div class="rf-code__chrome">';
	echo '<div class="rf-code__dots" aria-hidden="true"><span></span><span></span><span></span></div>';
	echo '<button type="button" class="rf-code__copy" aria-label="' . esc_attr( $copy_label ) . '">';
	echo sf_static_comments_kses_svg( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 8.25V6A2.25 2.25 0 0014.25 3.75H6A2.25 2.25 0 003.75 6v8.25A2.25 2.25 0 006 16.5h2.25m8.25-8.25H18A2.25 2.25 0 0120.25 10.5V18A2.25 2.25 0 0118 20.25h-7.5A2.25 2.25 0 018.25 18v-1.5m8.25-8.25h-6A2.25 2.25 0 008.25 10.5v6"/></svg>'
	);
	echo '</button></div>';
	echo '<pre class="rf-code__body"><code>' . esc_html( $code ) . '</code></pre>';
	echo '<span class="rf-code__lang">' . esc_html( $lang ) . '</span>';
	echo '</div>';
}

/**
 * Sticky Roots & Fruit footer + "About" popover card matching Agent Role.
 *
 * @param string $plugin_file Main plugin file path.
 */
function sf_static_comments_render_footer( string $plugin_file ): void {
	echo '<footer class="ar-rf-footer">';
	echo '<a class="ar-rf-footer__rate" href="#">' . esc_html__( 'Like Spacefast Comments? Give us a 5-★ Rating Here', 'spacefast-static-comments' ) . '</a>';
	echo '<nav class="ar-rf-footer__links" aria-label="' . esc_attr__( 'Plugin links', 'spacefast-static-comments' ) . '">';
	echo '<a href="' . esc_url( admin_url( 'options-general.php?page=spacefast-wordpress' ) ) . '">' . esc_html__( 'Spacefast Settings', 'spacefast-static-comments' ) . '</a>';
	echo '<a href="https://spacefast.com/docs" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Docs', 'spacefast-static-comments' ) . '</a>';
	echo '</nav>';
	echo '<button type="button" class="ar-rf-footer__brand" aria-expanded="false" aria-controls="ar-rf-about">';
	echo '<img src="' . esc_url( plugins_url( 'admin/images/rf-logo.svg', $plugin_file ) ) . '" alt="' . esc_attr__( 'Roots and Fruit', 'spacefast-static-comments' ) . '" width="120" height="53" />';
	echo '</button>';
	echo '</footer>';

	echo '<aside id="ar-rf-about" class="ar-rf-about" hidden>';
	echo '<p class="ar-rf-about__hello"><strong>' . esc_html__( 'Hi, I’m Matt!', 'spacefast-static-comments' ) . '</strong>';
	echo '<span>' . esc_html__( 'But most folks call me Cromwell.', 'spacefast-static-comments' ) . '</span></p>';
	echo '<p>' . esc_html__( 'Roots & Fruit is my digital product practice for solopreneurs and product teams: sustainable growth focused on CX and Marketing driven ROI.', 'spacefast-static-comments' ) . '</p>';
	echo '<h2 class="ar-rf-about__heading">' . esc_html__( 'About Spacefast Static Comments', 'spacefast-static-comments' ) . '</h2>';
	echo '<p>' . esc_html__( 'Spacefast Static Comments bakes approved WordPress comment threads into Simply Static exports and provisions a hardened Spacefast Zero comment server on your Space:', 'spacefast-static-comments' ) . '</p>';
	echo '<ol class="ar-rf-about__list">';
	echo '<li>' . esc_html__( 'Same-origin security: /api/comments runs on your Space with strict Origin, Content-Type, honeypot, and fill-time checks.', 'spacefast-static-comments' ) . '</li>';
	echo '<li>' . esc_html__( 'Impersonation protection: authenticated names are bound on the server and editor logins ship only as salted SHA-256 hashes.', 'spacefast-static-comments' ) . '</li>';
	echo '<li>' . esc_html__( 'Zero-CLI publishing: every Simply Static → Spacefast export automatically updates your live comment server.', 'spacefast-static-comments' ) . '</li>';
	echo '</ol>';
	echo '<div class="ar-rf-about__person">';
	echo '<img class="ar-rf-about__photo" src="' . esc_url( plugins_url( 'admin/images/matt-cromwell.jpg', $plugin_file ) ) . '" alt="" width="64" height="64" />';
	echo '<p class="ar-rf-about__id"><strong>' . esc_html__( 'Matt Cromwell', 'spacefast-static-comments' ) . '</strong>';
	echo '<span>' . esc_html__( 'Founder and CGO at Roots and Fruit', 'spacefast-static-comments' ) . '</span></p>';
	echo '</div></aside>';
}

/**
 * Sanitize inline SVG markup for tabs and copy buttons.
 *
 * @param string $svg SVG string.
 */
function sf_static_comments_kses_svg( string $svg ): string {
	return wp_kses(
		$svg,
		array(
			'svg'  => array(
				'xmlns'               => true,
				'width'               => true,
				'height'              => true,
				'viewbox'             => true,
				'preserveaspectratio' => true,
				'fill'                => true,
				'stroke'              => true,
				'stroke-width'        => true,
				'stroke-linecap'      => true,
				'stroke-linejoin'     => true,
				'class'               => true,
				'aria-hidden'         => true,
				'focusable'           => true,
			),
			'path' => array(
				'class'           => true,
				'd'               => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
		)
	);
}
