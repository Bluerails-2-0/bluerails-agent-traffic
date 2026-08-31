<?php
/**
 * BLUE-1539 — CI boot smoke test.
 *
 * Loads the plugin's real bootstrap chain (bluerails-agent-traffic.php's two
 * 'plugins_loaded' callbacks, the three feature classes' constructors, and the
 * vendored Plugin Update Checker's buildUpdateChecker()+getVcsApi()+
 * enableReleaseAssets() call) OUTSIDE WordPress, against a minimal set of stub
 * WP functions/constants. A missing/renamed class or a wrong PUC namespace
 * segment (the exact 2026-08-31 outage: `v5\Vcs\Api` vs the vendored
 * `v5p7\Vcs\Api`) throws a fatal Error here, same as it would on a live site's
 * very first request — which is the whole point: this catches it in CI,
 * before a release ships it.
 *
 * WHAT THIS TEST DOES REACH (verified by reading the code, not assumed):
 *   - bluerails-agent-traffic.php: the `require_once` chain for all three
 *     includes/class-bluerails-*.php files plus the PUC library loader
 *     (load-v5p7.php, its Autoloader registration, both PucFactory files).
 *   - bluerails_agent_traffic_init(): Bluerails_Settings::instance(),
 *     Bluerails_Bot_Detector::instance(), Bluerails_Behavioral_Beacon::instance()
 *     — each class's __construct(), which only calls add_action() (registering
 *     callbacks, not invoking them).
 *   - bluerails_agent_traffic_init_update_checker(): PucFactory::buildUpdateChecker()
 *     (namespace \YahnisElsts\PluginUpdateChecker\v5\PucFactory, which
 *     delegates via the version-registry built in load-v5p7.php to the actual
 *     v5p7\Vcs\PluginUpdateChecker / v5p7\Vcs\GitHubApi constructors — this is
 *     the exact call graph the 2026-08-31 outage broke), then
 *     ->getVcsApi()->enableReleaseAssets(...) with the REQUIRE_RELEASE_ASSETS
 *     constant read off \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api — the
 *     literal line that fataled.
 *
 * WHAT THIS TEST DOES NOT REACH (by design — these all need a real WP runtime
 * or a real HTTP round-trip to exercise meaningfully, not stubs):
 *   - Any hook CALLBACK body: maybe_capture() (wp_loaded), maybe_enqueue_beacon()
 *     (wp_enqueue_scripts), register_rest_route()/handle_rest_request()
 *     (rest_api_init / REST dispatch), add_settings_page()/register_settings()
 *     (admin_menu / admin_init), maybe_show_cdn_notice() (admin_notices),
 *     register_activation_hook()/register_deactivation_hook() callbacks
 *     themselves (WP never fires these outside an actual (de)activation).
 *   - Any actual network call — wp_remote_post() is stubbed to a no-op here
 *     (the only HTTP-shaped function actually called on this boot path: the
 *     Scheduler's cron registration goes through wp_next_scheduled/
 *     wp_schedule_event, not a live request), so PUC's own update-check
 *     round trip against GitHub never happens; this only proves the checker
 *     OBJECT can be built and its API called without fataling, not that a
 *     live update check against GitHub succeeds.
 *   - assets/js/bluerails-behavioral-beacon.js — not PHP, not loaded here.
 *   - Any WordPress-version-specific behavior (this stub layer is a fixed,
 *     hand-written approximation of the WP API surface actually called on the
 *     'plugins_loaded' path, not real WP core).
 */

// ---------------------------------------------------------------------------
// Constants the plugin file / PUC library check via defined() or reference
// directly.
// ---------------------------------------------------------------------------
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_PLUGIN_DIR', __DIR__ . '/fake-wp-content/plugins' );
define( 'WPMU_PLUGIN_DIR', __DIR__ . '/fake-wp-content/mu-plugins' );
define( 'WP_CONTENT_DIR', __DIR__ . '/fake-wp-content' );
define( 'WP_DEBUG', false );
define( 'HOUR_IN_SECONDS', 3600 );

// ---------------------------------------------------------------------------
// Stub WP functions. Every one of these is called somewhere on the
// 'plugins_loaded' boot path (main plugin file, the three feature-class
// constructors, or PUC's buildUpdateChecker()/getVcsApi()/enableReleaseAssets()
// chain) — this list was built by grepping the plugin's own four files and
// the vendored Puc/v5p7/*.php tree for call sites, then iterating against real
// "Call to undefined function/constant" fatals until the whole chain ran
// clean. Kept flat (no WP behavior beyond "don't fatal, return something
// plausible") on purpose — this is a boot smoke test, not a WP shim library.
// ---------------------------------------------------------------------------

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.test/wp-content/plugins/' . ltrim( $path, '/' );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	return $value;
}

function do_action( $hook, ...$args ) {
	return null;
}

function did_action( $hook ) {
	return 0;
}

function register_activation_hook( $file, $callback ) {
	return true;
}

function register_deactivation_hook( $file, $callback ) {
	return true;
}

function wp_remote_post( $url, $args = array() ) {
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
}

function wp_next_scheduled( $hook, $args = array() ) {
	return false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	return true;
}

function esc_html( $text ) {
	return $text;
}

function wp_parse_url( $url, $component = -1 ) {
	return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

// PUC's PucFactory::isPluginFile() falls back to this (mirrors WP core's own
// header-comment parser) when the target file isn't under WP_PLUGIN_DIR /
// WPMU_PLUGIN_DIR — true here, since this repo's plugin file sits at the repo
// root, not inside a real wp-content/plugins tree.
function get_file_data( $file, $default_headers, $context = '' ) {
	$contents = file_get_contents( $file, false, null, 0, 8192 );
	$headers  = array();
	foreach ( $default_headers as $field => $label ) {
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $contents, $match ) ) {
			$headers[ $field ] = trim( $match[1] );
		} else {
			$headers[ $field ] = '';
		}
	}
	return $headers;
}

// ---------------------------------------------------------------------------
// Boot.
// ---------------------------------------------------------------------------

echo "[boot-test] requiring bluerails-agent-traffic.php ...\n";
require dirname( __DIR__ ) . '/bluerails-agent-traffic.php';

echo "[boot-test] calling bluerails_agent_traffic_init() ...\n";
bluerails_agent_traffic_init();

echo "[boot-test] calling bluerails_agent_traffic_init_update_checker() ...\n";
bluerails_agent_traffic_init_update_checker();

echo "[boot-test] OK -- plugin bootstrap chain ran with no fatal error.\n";
exit( 0 );
