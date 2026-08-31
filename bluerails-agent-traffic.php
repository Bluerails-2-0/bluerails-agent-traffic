<?php
/**
 * Plugin Name:       Bluerails Agent Traffic Capture
 * Plugin URI:        https://github.com/Bluerails-2-0/bluerails-wp-plugin
 * Description:       Detects AI-bot crawler traffic (GPTBot, ClaudeBot, PerplexityBot, etc.) on this
 *                     WordPress site and reports it to your Bluerails Discovery Agent Traffic dashboard.
 * Version:           1.3.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Bluerails
 * Author URI:        https://bluerails.com
 * License:            GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bluerails-agent-traffic
 * Update URI:        https://github.com/Bluerails-2-0/bluerails-agent-traffic
 *
 * @package Bluerails_Agent_Traffic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLUERAILS_AGENT_TRAFFIC_VERSION', '1.3.2' );
define( 'BLUERAILS_AGENT_TRAFFIC_FILE', __FILE__ );
define( 'BLUERAILS_AGENT_TRAFFIC_DIR', plugin_dir_path( __FILE__ ) );

require_once BLUERAILS_AGENT_TRAFFIC_DIR . 'includes/class-bluerails-settings.php';
require_once BLUERAILS_AGENT_TRAFFIC_DIR . 'includes/class-bluerails-bot-detector.php';
require_once BLUERAILS_AGENT_TRAFFIC_DIR . 'includes/class-bluerails-behavioral-beacon.php';
require_once BLUERAILS_AGENT_TRAFFIC_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

/**
 * Boot the plugin. Settings page, bot detector, and the behavioral beacon are
 * independent singletons wired up on 'plugins_loaded' so all are available on
 * every request, including the earliest front-end hooks.
 */
function bluerails_agent_traffic_init() {
	Bluerails_Settings::instance();
	Bluerails_Bot_Detector::instance();
	Bluerails_Behavioral_Beacon::instance();
}
add_action( 'plugins_loaded', 'bluerails_agent_traffic_init' );

/**
 * Point WP core's native update mechanism at this repo's GitHub Releases, so an
 * installed site sees "Update available" the same way it would for a WP.org
 * plugin instead of needing a manual zip re-upload for every release.
 */
function bluerails_agent_traffic_init_update_checker() {
	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Bluerails-2-0/bluerails-agent-traffic',
		BLUERAILS_AGENT_TRAFFIC_FILE,
		'bluerails-agent-traffic'
	);
	// The stable-named zip is the asset that always exists on every release
	// (see README.md "Cutting a release"); the versioned zip alongside it would
	// otherwise be picked instead depending on GitHub's asset ordering.
	// REQUIRE (not the PUC default PREFER) so a future release that omits this
	// asset fails closed — no silent fallback to GitHub's raw, unprocessed
	// source-code archive as the "update" (independent review finding, BLUE-1534).
	// v5p7 must match the vendored Puc/v5pX/ dir exactly — unlike PucFactory,
	// Vcs\Api has no unversioned alias, so a mismatch here fatals every request
	// (2026-08-31 outage). BLUE-1537's drift-check re-vendors the library but
	// does not update this reference — check by hand on any PUC version bump.
	$update_checker->getVcsApi()->enableReleaseAssets(
		'/^bluerails-agent-traffic\.zip$/',
		\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
	);
}
add_action( 'plugins_loaded', 'bluerails_agent_traffic_init_update_checker' );

/**
 * Activation: seed default options so the settings screen has sane
 * values before the site owner ever visits it. No DB table, no schema.
 */
function bluerails_agent_traffic_activate() {
	add_option( 'bluerails_agent_traffic_endpoint_url', 'https://discovery.bluerails.com/api/agent-traffic-ingest' );
	add_option( 'bluerails_agent_traffic_api_key', '' );
	add_option( 'bluerails_agent_traffic_has_cdn', '' );
	// BLUE-1474: OFF by default — a separate opt-in from the endpoint/API-key config above,
	// since this feature also depends on the site's own visitor-consent tooling being in place.
	add_option( 'bluerails_agent_traffic_behavioral_enabled', '' );
}
register_activation_hook( __FILE__, 'bluerails_agent_traffic_activate' );

/**
 * Deactivation: intentionally a no-op beyond WordPress's own cleanup.
 * Options are left in place so re-activating the plugin does not force
 * the site owner to re-enter their endpoint URL and API key. Uninstall
 * (delete) is a separate, explicit action WordPress does not fire here.
 */
function bluerails_agent_traffic_deactivate() {
	// Nothing to clean up: no cron jobs, no transients, no temp files.
}
register_deactivation_hook( __FILE__, 'bluerails_agent_traffic_deactivate' );
