<?php
/**
 * Plugin Name:       Bluerails Agent Traffic Capture
 * Plugin URI:        https://github.com/Bluerails-2-0/bluerails-wp-plugin
 * Description:       Detects AI-bot crawler traffic (GPTBot, ClaudeBot, PerplexityBot, etc.) on this
 *                     WordPress site and reports it to your Bluerails Discovery Agent Traffic dashboard.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Bluerails
 * Author URI:        https://bluerails.com
 * License:            GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bluerails-agent-traffic
 *
 * @package Bluerails_Agent_Traffic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLUERAILS_AGENT_TRAFFIC_VERSION', '1.1.0' );
define( 'BLUERAILS_AGENT_TRAFFIC_FILE', __FILE__ );
define( 'BLUERAILS_AGENT_TRAFFIC_DIR', plugin_dir_path( __FILE__ ) );

require_once BLUERAILS_AGENT_TRAFFIC_DIR . 'includes/class-bluerails-settings.php';
require_once BLUERAILS_AGENT_TRAFFIC_DIR . 'includes/class-bluerails-bot-detector.php';

/**
 * Boot the plugin. Settings page and bot detector are independent
 * singletons wired up on 'plugins_loaded' so both are available on
 * every request, including the earliest front-end hooks.
 */
function bluerails_agent_traffic_init() {
	Bluerails_Settings::instance();
	Bluerails_Bot_Detector::instance();
}
add_action( 'plugins_loaded', 'bluerails_agent_traffic_init' );

/**
 * Activation: seed default options so the settings screen has sane
 * values before the site owner ever visits it. No DB table, no schema.
 */
function bluerails_agent_traffic_activate() {
	add_option( 'bluerails_agent_traffic_endpoint_url', 'https://discovery.bluerails.com/api/agent-traffic-ingest' );
	add_option( 'bluerails_agent_traffic_api_key', '' );
	add_option( 'bluerails_agent_traffic_has_cdn', '' );
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
