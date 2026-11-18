<?php
/**
 * Plugin Name:       TWTH Site Health Report
 * Plugin URI:        https://twthconsulting.com/
 * Description:       Read-only daily health, security and vulnerability report, emailed to TWTH Consulting. Changes nothing on the site. Updates itself from GitHub.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            TWTH Consulting
 * Author URI:        https://twthconsulting.com/
 * License:           GPL-2.0-or-later
 *
 * Settings (optional, in wp-config.php):
 *   define( 'TWTH_HEALTH_TO', 'reports@example.com' );   // override the report recipient
 *
 * Admin page:
 *   /wp-admin/?twth_health=preview   show the report
 *   Tools → TWTH Site Health        preview or send it now
 * WP-CLI: wp twth-health preview | wp twth-health send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

if ( ! defined( 'TWTH_HEALTH_TO' ) ) {
	define( 'TWTH_HEALTH_TO', 'leigh@twthconsulting.com' );
}

// GitHub repository this plugin updates from.
const TWTH_HEALTH_REPO   = 'https://github.com/leighsilverstein/twth-site-health/';
const TWTH_HEALTH_BRANCH = 'main';
const TWTH_HEALTH_HOOK   = 'twth_site_health_report_send';

// ---------------------------------------------------------------------------
// Self-updates from GitHub (Plugin Update Checker) + always auto-update
// ---------------------------------------------------------------------------

require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

if ( false === strpos( TWTH_HEALTH_REPO, 'REPLACE_WITH' ) ) {
	$twth_health_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		TWTH_HEALTH_REPO,
		__FILE__,
		'twth-site-health'
	);
	$twth_health_updater->setBranch( TWTH_HEALTH_BRANCH );

	// Only install versions published as a GitHub Release. Commits to the branch alone
	// (accidental or unauthorized) never reach the sites.
	$twth_health_updater->addFilter( 'vcs_update_detection_strategies', function ( $strategies ) {
		return array_intersect_key( $strategies, array( 'latest_release' => true ) );
	} );
}

// Install new versions automatically, so no one has to click "update" on 16 sites.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	if ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) {
		return true;
	}
	return $update;
}, 10, 2 );

// ---------------------------------------------------------------------------
// Schedule: daily, 6:00 am site time. The daily report doubles as a heartbeat:
// a site that stops sending it is flagged as "silent" on the dashboard.
// ---------------------------------------------------------------------------

add_action( 'init', function () {
	$event = wp_get_scheduled_event( TWTH_HEALTH_HOOK );
	if ( $event && 'daily' === $event->schedule ) {
		return;
	}
	wp_clear_scheduled_hook( TWTH_HEALTH_HOOK ); // Replaces the weekly schedule of v1.2 and earlier.
	try {
		$first = ( new DateTime( 'tomorrow 06:00', wp_timezone() ) )->getTimestamp();
	} catch ( Exception $e ) {
		$first = time() + HOUR_IN_SECONDS;
	}
	wp_schedule_event( $first, 'daily', TWTH_HEALTH_HOOK );
} );

// ---------------------------------------------------------------------------
// Mandatory on TWTH-hosted sites: a small must-use "guard" keeps this plugin
// active, hides its Deactivate/Delete links, re-installs itself if removed,
// and emails an alert if this plugin is deactivated or its files disappear.
// ---------------------------------------------------------------------------

const TWTH_HEALTH_GUARD_REQUIRED = 1;

function twth_health_guard_path() {
	return WPMU_PLUGIN_DIR . '/twth-site-health-guard.php';
}

function twth_health_guard_code() {
	$basename = plugin_basename( __FILE__ );
	$to       = TWTH_HEALTH_TO;
	$ver      = TWTH_HEALTH_GUARD_REQUIRED;
	return <<<PHP
<?php
/**
 * Plugin Name: TWTH Site Health Guard
 * Description: Required on websites hosted or maintained by TWTH Consulting. Keeps the TWTH Site Health Report plugin active and alerts TWTH if it is removed. Installed automatically by that plugin.
 * Version: {$ver}
 * Author: TWTH Consulting
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'TWTH_HEALTH_GUARD_VERSION', {$ver} );

// Keep the report plugin active, as long as its files are present: add it when the
// list is read, and refuse to save a list without it (blocks bulk "Deactivate").
\$twth_keep = function ( \$plugins ) {
	\$main = '{$basename}';
	if ( is_array( \$plugins ) && ! in_array( \$main, \$plugins, true ) && file_exists( WP_PLUGIN_DIR . '/' . \$main ) ) {
		\$plugins[] = \$main;
	}
	return \$plugins;
};
add_filter( 'option_active_plugins', \$twth_keep );
add_filter( 'pre_update_option_active_plugins', \$twth_keep );

// Hide Deactivate / Delete for the report plugin and explain why.
add_filter( 'plugin_action_links_{$basename}', function ( \$links ) {
	unset( \$links['deactivate'], \$links['delete'] );
	\$links[] = '<span style="color:#646970">Required by TWTH Consulting</span>';
	return \$links;
}, 99 );

// If the report plugin's files are gone, alert TWTH (at most once a day).
add_action( 'init', function () {
	if ( file_exists( WP_PLUGIN_DIR . '/{$basename}' ) || get_transient( 'twth_health_guard_alerted' ) ) {
		return;
	}
	set_transient( 'twth_health_guard_alerted', 1, DAY_IN_SECONDS );
	\$to   = defined( 'TWTH_HEALTH_TO' ) ? TWTH_HEALTH_TO : '{$to}';
	\$host = wp_parse_url( home_url(), PHP_URL_HOST );
	wp_mail( \$to, '[TWTH Site Health] ALERT ' . \$host . ' - monitoring plugin removed', "The TWTH Site Health Report plugin files are missing on " . home_url() . ".\\nDaily reports have stopped. Reinstall the plugin, and check who removed it." );
} );
PHP;
}

/** Install or refresh the guard. Returns true if it is in place. */
function twth_health_install_guard() {
	$path = twth_health_guard_path();
	if ( file_exists( $path ) && false !== strpos( (string) file_get_contents( $path ), "define( 'TWTH_HEALTH_GUARD_VERSION', " . TWTH_HEALTH_GUARD_REQUIRED . ' )' ) ) {
		return true;
	}
	if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
		wp_mkdir_p( WPMU_PLUGIN_DIR );
	}
	return is_writable( WPMU_PLUGIN_DIR ) && false !== file_put_contents( $path, twth_health_guard_code() );
}

register_activation_hook( __FILE__, 'twth_health_install_guard' );

// Self-heal: if someone deletes the guard file, put it back (checked at most twice a day).
add_action( 'init', function () {
	if ( get_transient( 'twth_health_guard_checked' ) ) {
		return;
	}
	set_transient( 'twth_health_guard_checked', 1, 12 * HOUR_IN_SECONDS );
	if ( ! file_exists( twth_health_guard_path() ) ) {
		update_option( 'twth_health_guard_reinstalled', gmdate( 'Y-m-d H:i:s' ), false );
	}
	twth_health_install_guard();
} );

// Deactivation (only possible once the guard is gone): alert immediately, naming who did it.
register_deactivation_hook( __FILE__, function () {
	if ( file_exists( twth_health_guard_path() ) ) {
		return; // The guard keeps the plugin active (e.g. after a bulk "Deactivate"), so nothing actually changes.
	}
	wp_clear_scheduled_hook( TWTH_HEALTH_HOOK );
	if ( ! is_email( TWTH_HEALTH_TO ) ) {
		return;
	}
	$user = wp_get_current_user();
	$who  = $user && $user->exists() ? $user->user_login . ' (' . $user->user_email . ')' : 'unknown (no logged-in user)';
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	wp_mail(
		TWTH_HEALTH_TO,
		'[TWTH Site Health] ALERT ' . $host . ' - monitoring plugin deactivated',
		'The TWTH Site Health Report plugin was deactivated on ' . home_url() . ' at ' . wp_date( 'Y-m-d H:i T' ) . ".\nDeactivated by: {$who}\nDaily reports will stop until it is reactivated."
	);
} );

add_action( TWTH_HEALTH_HOOK, 'twth_health_send' );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function twth_health_table_exists( $table ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
}

function twth_health_rel( $path ) {
	return ltrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $path ) ), '/' );
}

// ---------------------------------------------------------------------------
// Security checks (read-only)
// ---------------------------------------------------------------------------

/**
 * Compare WordPress core files against the official checksums from wordpress.org,
 * and look for extra PHP files inside wp-admin/ and wp-includes/.
 */
function twth_health_core_integrity() {
	global $wp_version, $wp_local_package;

	require_once ABSPATH . 'wp-admin/includes/update.php';

	$locale    = ! empty( $wp_local_package ) ? $wp_local_package : 'en_US';
	$checksums = get_core_checksums( $wp_version, $locale );
	if ( ! is_array( $checksums ) && 'en_US' !== $locale ) {
		$checksums = get_core_checksums( $wp_version, 'en_US' );
	}
	if ( ! is_array( $checksums ) ) {
		return array( 'checked' => false, 'note' => 'Could not fetch checksums from wordpress.org' );
	}

	$modified = array();
	$missing  = 0;
	foreach ( $checksums as $file => $md5 ) {
		if ( 0 === strpos( $file, 'wp-content/' ) ) {
			continue; // Bundled themes/plugins are often removed or updated; skip.
		}
		$path = ABSPATH . $file;
		if ( ! file_exists( $path ) ) {
			$missing++;
			continue;
		}
		if ( md5_file( $path ) !== $md5 ) {
			$modified[] = $file;
		}
	}

	$unexpected = array();
	foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
		$base = ABSPATH . $dir;
		if ( ! is_dir( $base ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && preg_match( '/\.(php\d?|phtml|phar)$/i', $f->getFilename() ) ) {
				$rel = twth_health_rel( $f->getPathname() );
				if ( ! isset( $checksums[ $rel ] ) ) {
					$unexpected[] = $rel;
				}
			}
		}
	}

	return array(
		'checked'          => true,
		'modified_count'   => count( $modified ),
		'modified'         => array_slice( $modified, 0, 15 ),
		'missing_count'    => $missing,
		'unexpected_count' => count( $unexpected ),
		'unexpected'       => array_slice( $unexpected, 0, 15 ),
	);
}

/**
 * PHP files have no business in the uploads folder; their presence is a common sign of compromise.
 * Bounded by time so a huge media library can't stall the run.
 */
function twth_health_uploads_php() {
	$uploads = wp_upload_dir( null, false );
	$base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
	if ( ! $base || ! is_dir( $base ) ) {
		return array( 'checked' => false );
	}

	$found    = array();
	$scanned  = 0;
	$deadline = microtime( true ) + 25;
	$complete = true;

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY,
		RecursiveIteratorIterator::CATCH_GET_CHILD
	);
	foreach ( $it as $f ) {
		$scanned++;
		if ( 0 === $scanned % 500 && microtime( true ) > $deadline ) {
			$complete = false;
			break;
		}
		if ( preg_match( '/\.(php\d?|phtml|phar|pht)$/i', $f->getFilename() ) ) {
			$rel = twth_health_rel( $f->getPathname() );
			// Empty index.php "silence is golden" files are harmless.
			if ( 'index.php' === strtolower( $f->getFilename() ) && $f->getSize() < 100 ) {
				continue;
			}
			$found[] = $rel;
		}
	}

	return array(
		'checked'       => true,
		'complete'      => $complete,
		'files_scanned' => $scanned,
		'php_count'     => count( $found ),
		'php_files'     => array_slice( $found, 0, 20 ),
	);
}

function twth_health_pending_updates() {
	$out     = array( 'core' => null, 'plugins' => array(), 'themes' => array() );
	$core    = get_site_transient( 'update_core' );
	if ( isset( $core->updates ) && is_array( $core->updates ) ) {
		foreach ( $core->updates as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response ) {
				$out['core'] = $u->current;
				break;
			}
		}
	}
	$plugins = get_site_transient( 'update_plugins' );
	if ( isset( $plugins->response ) && is_array( $plugins->response ) ) {
		foreach ( $plugins->response as $file => $p ) {
			$out['plugins'][] = dirname( $file ) . ' -> ' . ( isset( $p->new_version ) ? $p->new_version : '?' );
		}
	}
	$themes = get_site_transient( 'update_themes' );
	if ( isset( $themes->response ) && is_array( $themes->response ) ) {
		foreach ( $themes->response as $slug => $t ) {
			$out['themes'][] = $slug . ' -> ' . ( isset( $t['new_version'] ) ? $t['new_version'] : '?' );
		}
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Known vulnerabilities (WPVulnerability.net: free, open database that combines
// Wordfence, Patchstack, WPScan and CVE data). Read-only lookups; results are
// cached for 20 hours so each component is fetched about once a day.
// ---------------------------------------------------------------------------

const TWTH_HEALTH_VULN_API = 'https://www.wpvulnerability.net/';

function twth_health_vuln_fetch( $type, $slug ) {
	$key    = 'twth_vuln_' . md5( $type . '/' . $slug );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$res = wp_remote_get( TWTH_HEALTH_VULN_API . $type . '/' . rawurlencode( $slug ) . '/', array( 'timeout' => 8 ) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return null; // Lookup failed; reported as "not checked".
	}
	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	$list = ( is_array( $json ) && isset( $json['data']['vulnerability'] ) && is_array( $json['data']['vulnerability'] ) ) ? $json['data']['vulnerability'] : array();
	set_transient( $key, $list, 20 * HOUR_IN_SECONDS );
	return $list;
}

function twth_health_vuln_applies( $v, $version ) {
	$op = isset( $v['operator'] ) && is_array( $v['operator'] ) ? $v['operator'] : array();
	$ok = false;
	if ( ! empty( $op['min_operator'] ) && isset( $op['min_version'] ) ) {
		$ok = version_compare( $version, $op['min_version'], $op['min_operator'] );
		if ( ! $ok ) {
			return false;
		}
	}
	if ( ! empty( $op['max_operator'] ) && isset( $op['max_version'] ) ) {
		return version_compare( $version, $op['max_version'], $op['max_operator'] );
	}
	return $ok;
}

function twth_health_vuln_item( $v, $type, $slug, $name, $version, $active ) {
	$op       = isset( $v['operator'] ) && is_array( $v['operator'] ) ? $v['operator'] : array();
	$unfixed  = ! empty( $op['unfixed'] );
	$fixed_in = null;
	if ( ! $unfixed && isset( $op['max_operator'], $op['max_version'] ) && in_array( $op['max_operator'], array( 'lt', '<' ), true ) ) {
		$fixed_in = $op['max_version'];
	}
	$sev_map  = array( 'n' => 'none', 'l' => 'low', 'm' => 'medium', 'h' => 'high', 'c' => 'critical' );
	$sev_code = isset( $v['impact']['cvss']['severity'] ) ? strtolower( (string) $v['impact']['cvss']['severity'] ) : '';
	$cves     = array();
	$link     = null;
	foreach ( (array) ( isset( $v['source'] ) ? $v['source'] : array() ) as $s ) {
		if ( ! empty( $s['id'] ) && 0 === strpos( (string) $s['id'], 'CVE-' ) ) {
			$cves[] = $s['id'];
		}
		if ( ! $link && ! empty( $s['link'] ) ) {
			$link = $s['link'];
		}
	}
	return array(
		'type'     => $type,
		'slug'     => $slug,
		'name'     => $name,
		'version'  => $version,
		'active'   => $active,
		'title'    => isset( $v['name'] ) ? trim( preg_replace( '#</?[a-z][^>]*>#i', '', (string) $v['name'] ) ) : 'Unnamed vulnerability', // Keep "<= 1.2" style text.
		'severity' => isset( $sev_map[ $sev_code ] ) ? $sev_map[ $sev_code ] : 'unknown',
		'score'    => isset( $v['impact']['cvss']['score'] ) ? (float) $v['impact']['cvss']['score'] : null,
		'fixed_in' => $fixed_in,
		'unfixed'  => $unfixed,
		'cve'      => array_values( array_unique( $cves ) ),
		'link'     => $link,
	);
}

/**
 * Check WordPress core, every installed plugin (active or not) and every installed theme.
 * Bounded to ~90 seconds so a slow API can't stall the site's cron.
 */
function twth_health_vulnerabilities( $inventory ) {
	$found     = array();
	$unchecked = array();
	$deadline  = microtime( true ) + 90;

	$wp = get_bloginfo( 'version' );
	$core = twth_health_vuln_fetch( 'core', $wp );
	if ( null === $core ) {
		$unchecked[] = 'core';
	} else {
		// The core endpoint is version-specific: everything it returns applies.
		foreach ( $core as $v ) {
			$found[] = twth_health_vuln_item( $v, 'core', 'wordpress', 'WordPress', $wp, true );
		}
	}

	$items = array();
	foreach ( $inventory['plugins'] as $p ) {
		$items[] = array( 'plugin', $p['slug'], $p['name'], $p['version'], $p['active'] );
	}
	foreach ( $inventory['themes'] as $t ) {
		$items[] = array( 'theme', $t['slug'], $t['name'], $t['version'], $t['active'] );
	}

	foreach ( $items as $it ) {
		list( $type, $slug, $name, $version, $active ) = $it;
		if ( 'twth-site-health' === $slug || '' === (string) $version ) {
			continue;
		}
		if ( microtime( true ) > $deadline ) {
			$unchecked[] = $type . ':' . $slug;
			continue;
		}
		$list = twth_health_vuln_fetch( $type, $slug );
		if ( null === $list ) {
			$unchecked[] = $type . ':' . $slug;
			continue;
		}
		foreach ( $list as $v ) {
			if ( twth_health_vuln_applies( $v, $version ) ) {
				$found[] = twth_health_vuln_item( $v, $type, $slug, $name, $version, $active );
			}
		}
	}

	$rank = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'unknown' => 4, 'none' => 5 );
	usort( $found, function ( $a, $b ) use ( $rank ) {
		return $rank[ $a['severity'] ] <=> $rank[ $b['severity'] ];
	} );

	$counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0, 'none' => 0 );
	foreach ( $found as $f ) {
		$counts[ $f['severity'] ]++;
	}

	return array(
		'source'    => 'wpvulnerability.net',
		'count'     => count( $found ),
		'by_severity' => $counts,
		'items'     => $found,
		'unchecked' => $unchecked,
	);
}

// ---------------------------------------------------------------------------
// Data collection (read-only)
// ---------------------------------------------------------------------------

function twth_health_collect() {
	global $wpdb;

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore
	}

	$week_ago = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );

	$r = array(
		'report_version' => 3,
		'plugin_version' => '1.3.0',
		'site'           => home_url(),
		'generated_gmt'  => gmdate( 'Y-m-d H:i:s' ),
		'wp_version'     => get_bloginfo( 'version' ),
		'php_version'    => PHP_VERSION,
		'wc_version'     => ( function_exists( 'WC' ) && WC() ) ? WC()->version : null,
	);

	// --- Cron -------------------------------------------------------------
	$schedules = wp_get_schedules();
	$hooks     = array();
	$total     = 0;
	$overdue   = 0;
	$crons     = _get_cron_array();
	if ( is_array( $crons ) ) {
		foreach ( $crons as $ts => $cron_hooks ) {
			foreach ( (array) $cron_hooks as $hook => $events ) {
				foreach ( (array) $events as $event ) {
					$total++;
					if ( $ts < time() - HOUR_IN_SECONDS ) {
						$overdue++;
					}
					$interval = isset( $event['interval'] ) ? (int) $event['interval'] : 0;
					if ( ! $interval && ! empty( $event['schedule'] ) && isset( $schedules[ $event['schedule'] ]['interval'] ) ) {
						$interval = (int) $schedules[ $event['schedule'] ]['interval'];
					}
					if ( $interval && $interval <= 15 * MINUTE_IN_SECONDS ) {
						$hooks[ $hook ] = isset( $hooks[ $hook ] ) ? min( $hooks[ $hook ], $interval ) : $interval;
					}
				}
			}
		}
	}
	asort( $hooks );
	$frequent = array();
	foreach ( $hooks as $hook => $interval ) {
		$frequent[] = array( 'hook' => $hook, 'every_min' => round( $interval / 60, 1 ) );
	}
	$r['cron'] = array(
		'wp_cron_disabled'      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'scheduled_events'      => $total,
		'overdue_events'        => $overdue,
		'frequent_hooks_15min'  => $frequent,
	);

	// --- Action Scheduler (WooCommerce and many other plugins) -------------
	$as = $wpdb->prefix . 'actionscheduler_actions';
	if ( twth_health_table_exists( $as ) ) {
		$counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$as} GROUP BY status", ARRAY_A ) as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}
		$r['action_scheduler'] = array(
			'counts_by_status'    => $counts,
			'pending_past_due'    => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$as} WHERE status = 'pending' AND scheduled_date_gmt < %s",
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			) ),
			'failed_last_7d'      => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$as} WHERE status = 'failed' AND last_attempt_gmt >= %s",
				$week_ago
			) ),
			'top_failed_hooks_7d' => $wpdb->get_results( $wpdb->prepare(
				"SELECT hook, COUNT(*) AS n FROM {$as} WHERE status = 'failed' AND last_attempt_gmt >= %s GROUP BY hook ORDER BY n DESC LIMIT 5",
				$week_ago
			), ARRAY_A ),
			'top_run_hooks_7d'    => $wpdb->get_results( $wpdb->prepare(
				"SELECT hook, COUNT(*) AS n FROM {$as} WHERE status = 'complete' AND last_attempt_gmt >= %s GROUP BY hook ORDER BY n DESC LIMIT 8",
				$week_ago
			), ARRAY_A ),
		);
	} else {
		$r['action_scheduler'] = null;
	}

	// --- Database ---------------------------------------------------------
	$like          = $wpdb->esc_like( $wpdb->prefix ) . '%';
	$r['database'] = array(
		'total_mb'           => (float) $wpdb->get_var( $wpdb->prepare(
			'SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
			DB_NAME,
			$like
		) ),
		'largest_tables'     => $wpdb->get_results( $wpdb->prepare(
			'SELECT table_name AS name, table_rows AS approx_rows, ROUND((data_length + index_length) / 1048576, 1) AS size_mb
			   FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s
			  ORDER BY (data_length + index_length) DESC LIMIT 10',
			DB_NAME,
			$like
		), ARRAY_A ),
		'autoload_kb'        => (float) $wpdb->get_var(
			"SELECT ROUND(SUM(LENGTH(option_value)) / 1024, 1) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')"
		),
		'expired_transients' => (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			time()
		) ),
	);

	// --- WooCommerce (only when active) -----------------------------------
	$r['woocommerce'] = null;
	if ( function_exists( 'WC' ) ) {
		$sessions = $wpdb->prefix . 'woocommerce_sessions';
		$wclog    = $wpdb->prefix . 'woocommerce_log';

		$hpos_on = null;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos_on = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		$log_mb = null;
		if ( defined( 'WC_LOG_DIR' ) && is_dir( WC_LOG_DIR ) ) {
			$bytes = 0;
			foreach ( (array) glob( trailingslashit( WC_LOG_DIR ) . '*' ) as $f ) {
				if ( is_file( $f ) ) {
					$bytes += filesize( $f );
				}
			}
			$log_mb = round( $bytes / 1048576, 1 );
		}

		$products = wp_count_posts( 'product' );

		$r['woocommerce'] = array(
			'hpos_enabled'       => $hpos_on,
			'hpos_sync_enabled'  => 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ),
			'orders_last_7d'     => function_exists( 'wc_get_orders' ) ? count( wc_get_orders( array(
				'limit'        => -1,
				'return'       => 'ids',
				'date_created' => '>' . ( time() - WEEK_IN_SECONDS ),
			) ) ) : null,
			'published_products' => isset( $products->publish ) ? (int) $products->publish : null,
			'sessions'           => twth_health_table_exists( $sessions ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sessions}" ) : null,
			'log_rows_db'        => twth_health_table_exists( $wclog ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wclog}" ) : null,
			'log_files_mb'       => $log_mb,
			'tracking_enabled'   => 'yes' === get_option( 'woocommerce_allow_tracking' ),
		);
	}

	// --- Security ---------------------------------------------------------
	$users                 = count_users();
	$r['security']         = array(
		'admin_users'     => isset( $users['avail_roles']['administrator'] ) ? (int) $users['avail_roles']['administrator'] : null,
		'core_integrity'  => twth_health_core_integrity(),
		'uploads_php'     => twth_health_uploads_php(),
		'pending_updates' => twth_health_pending_updates(),
	);

	// --- Software inventory (every installed plugin and theme, with versions) ---
	// Inactive plugins are included on purpose: their files can still be attacked.
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$active  = (array) get_option( 'active_plugins', array() );
	$plugins = array();
	foreach ( get_plugins() as $file => $data ) {
		$plugins[] = array(
			'slug'    => '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file ),
			'name'    => $data['Name'],
			'version' => $data['Version'],
			'active'  => in_array( $file, $active, true ),
		);
	}
	usort( $plugins, function ( $a, $b ) {
		return strcmp( $a['slug'], $b['slug'] );
	} );

	$mu = array();
	foreach ( get_mu_plugins() as $file => $data ) {
		$mu[] = array( 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'] );
	}

	$current_theme = get_stylesheet();
	$parent_theme  = get_template();
	$themes        = array();
	foreach ( wp_get_themes() as $slug => $theme ) {
		$themes[] = array(
			'slug'    => $slug,
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'active'  => ( $slug === $current_theme || $slug === $parent_theme ),
		);
	}

	$r['inventory'] = array(
		'plugins'    => $plugins,
		'mu_plugins' => $mu,
		'themes'     => $themes,
	);

	$r['vulnerabilities'] = twth_health_vulnerabilities( $r['inventory'] );

	$r['guard'] = array(
		'installed'      => file_exists( twth_health_guard_path() ),
		'reinstalled_at' => get_option( 'twth_health_guard_reinstalled' ) ?: null,
	);

	return $r;
}

// ---------------------------------------------------------------------------
// Report formatting + sending
// ---------------------------------------------------------------------------

function twth_health_format( $r ) {
	$host = wp_parse_url( $r['site'], PHP_URL_HOST );
	$yn   = function ( $v ) {
		return null === $v ? 'n/a' : ( $v ? 'yes' : 'no' );
	};
	$na   = function ( $v ) {
		return null === $v ? 'n/a' : $v;
	};

	$l   = array();
	$l[] = "TWTH Site Health Report: {$host}";
	$l[] = "Generated (GMT): {$r['generated_gmt']} | report plugin v{$r['plugin_version']}";
	$l[] = "WP {$r['wp_version']} | WC " . ( $r['wc_version'] ?: 'n/a' ) . " | PHP {$r['php_version']}";

	// Known vulnerabilities first: they're what needs attention fastest.
	$vu  = $r['vulnerabilities'];
	$l[] = '';
	$l[] = 'KNOWN VULNERABILITIES (' . $vu['count'] . ')';
	if ( ! $vu['count'] ) {
		$l[] = '  None found in the installed versions.';
	}
	foreach ( $vu['items'] as $v ) {
		$fix = $v['unfixed'] ? 'NO FIX YET' : ( $v['fixed_in'] ? 'fixed in ' . $v['fixed_in'] : 'fix version unknown' );
		$l[] = '  [' . strtoupper( $v['severity'] ) . '] ' . $v['name'] . ' ' . $v['version'] . ( $v['active'] ? '' : ' (inactive)' ) . ': ' . $v['title'] . ' (' . $fix . ')';
	}
	if ( $vu['unchecked'] ) {
		$l[] = '  Not checked (lookup failed): ' . implode( ', ', $vu['unchecked'] );
	}
	if ( ! $r['guard']['installed'] ) {
		$l[] = '  ! Guard (mu-plugin) is missing: the report plugin can be deactivated.';
	}

	// Security checks.
	$s   = $r['security'];
	$ci  = $s['core_integrity'];
	$up  = $s['uploads_php'];
	$pu  = $s['pending_updates'];
	$l[] = '';
	$l[] = 'SECURITY';
	if ( $ci['checked'] ) {
		$l[] = "  Modified core files: {$ci['modified_count']} | missing: {$ci['missing_count']} | unexpected PHP in wp-admin/wp-includes: {$ci['unexpected_count']}";
		foreach ( array_merge( $ci['modified'], $ci['unexpected'] ) as $f ) {
			$l[] = "    ! {$f}";
		}
	} else {
		$l[] = '  Core integrity: not checked (' . ( isset( $ci['note'] ) ? $ci['note'] : 'unknown' ) . ')';
	}
	if ( $up['checked'] ) {
		$l[] = "  PHP files in uploads: {$up['php_count']}" . ( $up['complete'] ? '' : " (scan stopped early after {$up['files_scanned']} files)" );
		foreach ( $up['php_files'] as $f ) {
			$l[] = "    ! {$f}";
		}
	}
	$l[] = '  Administrator accounts: ' . $na( $s['admin_users'] );
	$l[] = '  Core update available: ' . ( $pu['core'] ? $pu['core'] : 'no' );
	$l[] = '  Plugin updates waiting (' . count( $pu['plugins'] ) . '): ' . ( $pu['plugins'] ? implode( ', ', $pu['plugins'] ) : 'none' );
	$l[] = '  Theme updates waiting (' . count( $pu['themes'] ) . '): ' . ( $pu['themes'] ? implode( ', ', $pu['themes'] ) : 'none' );

	$c   = $r['cron'];
	$l[] = '';
	$l[] = 'CRON';
	$l[] = '  WP-Cron disabled (real cron in use): ' . $yn( $c['wp_cron_disabled'] );
	$l[] = "  Scheduled events: {$c['scheduled_events']} (overdue > 1h: {$c['overdue_events']})";
	if ( $c['frequent_hooks_15min'] ) {
		$l[] = '  Hooks running every 15 min or more often:';
		foreach ( $c['frequent_hooks_15min'] as $h ) {
			$l[] = "    every {$h['every_min']} min  {$h['hook']}";
		}
	}

	if ( $r['action_scheduler'] ) {
		$as  = $r['action_scheduler'];
		$l[] = '';
		$l[] = 'ACTION SCHEDULER';
		foreach ( $as['counts_by_status'] as $status => $n ) {
			$l[] = "  {$status}: {$n}";
		}
		$l[] = "  Pending past due: {$as['pending_past_due']} | failed in last 7 days: {$as['failed_last_7d']}";
		if ( $as['top_failed_hooks_7d'] ) {
			$l[] = '  Top failing hooks (7d):';
			foreach ( $as['top_failed_hooks_7d'] as $h ) {
				$l[] = "    {$h['n']}  {$h['hook']}";
			}
		}
		if ( $as['top_run_hooks_7d'] ) {
			$l[] = '  Busiest hooks (7d):';
			foreach ( $as['top_run_hooks_7d'] as $h ) {
				$l[] = "    {$h['n']}  {$h['hook']}";
			}
		}
	}

	$db  = $r['database'];
	$l[] = '';
	$l[] = 'DATABASE';
	$l[] = "  Total: {$db['total_mb']} MB | autoloaded options: {$db['autoload_kb']} KB | expired transients: {$db['expired_transients']}";
	$l[] = '  Largest tables:';
	foreach ( (array) $db['largest_tables'] as $t ) {
		$l[] = "    {$t['size_mb']} MB  ~{$t['approx_rows']} rows  {$t['name']}";
	}

	if ( $r['woocommerce'] ) {
		$wc  = $r['woocommerce'];
		$l[] = '';
		$l[] = 'WOOCOMMERCE';
		$l[] = '  HPOS enabled: ' . $yn( $wc['hpos_enabled'] ) . ' | compatibility sync on: ' . $yn( $wc['hpos_sync_enabled'] );
		$l[] = '  Orders last 7 days: ' . $na( $wc['orders_last_7d'] ) . ' | published products: ' . $na( $wc['published_products'] );
		$l[] = '  Sessions: ' . $na( $wc['sessions'] ) . ' | log rows (DB): ' . $na( $wc['log_rows_db'] ) . ' | log files: ' . ( null === $wc['log_files_mb'] ? 'n/a' : $wc['log_files_mb'] . ' MB' );
		$l[] = '  Usage tracking on: ' . $yn( $wc['tracking_enabled'] );
	}

	$inv  = $r['inventory'];
	$on   = count( array_filter( $inv['plugins'], function ( $p ) {
		return $p['active'];
	} ) );
	$l[] = '';
	$l[] = 'PLUGINS (' . count( $inv['plugins'] ) . " installed, {$on} active)";
	foreach ( $inv['plugins'] as $p ) {
		$l[] = "  {$p['slug']} {$p['version']}" . ( $p['active'] ? '' : '  [inactive]' );
	}
	if ( $inv['mu_plugins'] ) {
		$l[] = 'MUST-USE PLUGINS';
		foreach ( $inv['mu_plugins'] as $p ) {
			$l[] = "  {$p['file']} {$p['version']}";
		}
	}
	$l[] = 'THEMES';
	foreach ( $inv['themes'] as $t ) {
		$l[] = "  {$t['slug']} {$t['version']}" . ( $t['active'] ? '  [active]' : '' );
	}

	// Machine-readable copy for automated review. Keep the markers intact.
	$l[] = '';
	$l[] = '---TWTH-JSON-BEGIN---';
	$l[] = wp_json_encode( $r );
	$l[] = '---TWTH-JSON-END---';

	return implode( "\n", $l );
}

function twth_health_send() {
	if ( ! is_email( TWTH_HEALTH_TO ) ) {
		return false; // Recipient not configured; do nothing.
	}
	$r    = twth_health_collect();
	$host = wp_parse_url( $r['site'], PHP_URL_HOST );
	$subj = sprintf( '[TWTH Site Health] %s - %s', $host, gmdate( 'Y-m-d' ) );
	$vu   = $r['vulnerabilities'];
	if ( $vu['count'] ) {
		$top  = $vu['by_severity']['critical'] ? 'critical' : ( $vu['by_severity']['high'] ? 'high' : '' );
		$subj .= sprintf( ' - AT RISK: %d known %s%s', $vu['count'], 1 === $vu['count'] ? 'vulnerability' : 'vulnerabilities', $top ? " ({$vu['by_severity'][ $top ]} {$top})" : '' );
	}
	return wp_mail( TWTH_HEALTH_TO, $subj, twth_health_format( $r ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
}

// ---------------------------------------------------------------------------
// Manual test hooks (admins only)
// ---------------------------------------------------------------------------

// Tools → TWTH Site Health: preview and send buttons.
add_action( 'admin_menu', function () {
	add_management_page( 'TWTH Site Health', 'TWTH Site Health', 'manage_options', 'twth-site-health', function () {
		$preview = admin_url( '?twth_health=preview' );
		$send    = wp_nonce_url( admin_url( '?twth_health=send' ), 'twth_health_send' );
		echo '<div class="wrap"><h1>TWTH Site Health Report</h1>';
		echo '<p>A read-only report is emailed weekly to ' . esc_html( TWTH_HEALTH_TO ) . '.';
		$next = wp_next_scheduled( TWTH_HEALTH_HOOK );
		if ( $next ) {
			echo ' Next report: ' . esc_html( wp_date( 'l, F j \a\t g:i a', $next ) ) . '.';
		}
		echo '</p><p><a class="button" href="' . esc_url( $preview ) . '">Preview report</a> ';
		echo '<a class="button button-primary" href="' . esc_url( $send ) . '">Send report now</a></p></div>';
	} );
} );

add_action( 'admin_init', function () {
	if ( empty( $_GET['twth_health'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$mode = sanitize_key( wp_unslash( $_GET['twth_health'] ) );
	if ( 'send' === $mode ) {
		check_admin_referer( 'twth_health_send' ); // Blocks other sites from triggering a send.
		$ok = twth_health_send();
		wp_die( $ok ? 'TWTH health report sent to ' . esc_html( TWTH_HEALTH_TO ) . '.' : 'Not sent. Check TWTH_HEALTH_TO and that this site can send mail.' );
	}
	if ( 'preview' === $mode ) {
		wp_die( '<pre style="white-space:pre-wrap">' . esc_html( twth_health_format( twth_health_collect() ) ) . '</pre>', 'TWTH Health Preview' );
	}
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'twth-health', function ( $args ) {
		$mode = isset( $args[0] ) ? $args[0] : 'preview';
		if ( 'send' === $mode ) {
			twth_health_send() ? WP_CLI::success( 'Sent to ' . TWTH_HEALTH_TO ) : WP_CLI::error( 'Not sent. Check TWTH_HEALTH_TO.' );
			return;
		}
		WP_CLI::line( twth_health_format( twth_health_collect() ) );
	} );
}
