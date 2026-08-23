<?php
/**
 * Matches the request's User-Agent against a static list of known AI-bot
 * substrings and, on a match, async-reports the hit to the configured
 * Bluerails ingest endpoint.
 *
 * This list is a LOCAL COPY, not a live sync of Bluerails' canonical
 * `agent_bot_registry`. It will drift over time (new crawlers won't be
 * added here automatically, and a bot Bluerails later drops from its
 * canonical list may still match here). That is fine: the backend
 * re-verifies every submitted bot name server-side, so a stale local
 * list can under- or over-match on this plugin's side, but it can never
 * corrupt what actually lands in your dashboard.
 *
 * @package Bluerails_Agent_Traffic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bluerails_Bot_Detector {

	private static $instance = null;

	/**
	 * bot name => User-Agent substring to match (case-insensitive).
	 * Seed list of the most-cited AI/LLM crawlers in the public record
	 * as of 2026-08. Not exhaustive — see class docblock above.
	 */
	const BOT_SIGNATURES = array(
		'GPTBot'              => 'GPTBot',
		'ClaudeBot'           => 'ClaudeBot',
		'PerplexityBot'       => 'PerplexityBot',
		'Google-Extended'     => 'Google-Extended',
		'CCBot'               => 'CCBot',
		'Bytespider'          => 'Bytespider',
		'Amazonbot'           => 'Amazonbot',
		'Applebot-Extended'   => 'Applebot-Extended',
		'meta-externalagent'  => 'meta-externalagent',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bot names formatted for pasting into a cache plugin's "exclude
	 * user agent" rule (e.g. WP Rocket's "Never Cache User Agent(s)").
	 */
	public static function get_bot_signatures_for_display() {
		return array_values( self::BOT_SIGNATURES );
	}

	private function __construct() {
		// 'wp_loaded' (not 'template_redirect'): WordPress's own hook
		// reference recommends the earlier hooks for full-request
		// logging, since template_redirect is oriented around redirect
		// logic and an exit()/die() there can skip later handlers.
		add_action( 'wp_loaded', array( $this, 'maybe_capture' ) );
	}

	public function maybe_capture() {
		// Never do bot-matching work on admin/cron/AJAX requests — this
		// plugin only cares about front-end traffic, and skipping here
		// keeps wp-admin itself fast.
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}

		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return;
		}
		$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );

		$match = $this->match_bot( $user_agent );
		if ( null === $match ) {
			return;
		}

		$this->report_hit( $match['bot_name'], $match['matched_substring'] );
	}

	/**
	 * Returns the first matching bot as array( 'bot_name' => ..., 'matched_substring' => ... ),
	 * or null if the User-Agent doesn't match any known signature.
	 */
	private function match_bot( $user_agent ) {
		foreach ( self::BOT_SIGNATURES as $bot_name => $substring ) {
			if ( false !== stripos( $user_agent, $substring ) ) {
				return array(
					'bot_name'          => $bot_name,
					'matched_substring' => $substring,
				);
			}
		}
		return null;
	}

	/**
	 * Fire-and-forget POST to the configured ingest endpoint. Uses
	 * blocking => false so this never adds latency to the visitor's
	 * (bot's) page load, and returns immediately regardless of whether
	 * the endpoint or API key are configured yet.
	 */
	private function report_hit( $bot_name, $matched_substring ) {
		$endpoint_url = get_option( 'bluerails_agent_traffic_endpoint_url', '' );
		$api_key      = get_option( 'bluerails_agent_traffic_api_key', '' );

		if ( empty( $endpoint_url ) || empty( $api_key ) ) {
			return; // Not configured yet — nothing to send, nothing to block on.
		}

		$page_path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		$payload = array(
			'bot_name'          => $bot_name,
			'matched_ua_string' => $matched_substring,
			'page_path'         => $page_path,
			'page_url'          => home_url( $page_path ),
			'timestamp'         => gmdate( 'c' ),
			'site_url'          => home_url(),
		);

		wp_remote_post(
			$endpoint_url,
			array(
				'timeout'   => 3,
				'blocking'  => false,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type'  => 'application/json',
					// Auth convention: API key sent as a Bearer token.
					// Documented in README.md for the backend team.
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'      => wp_json_encode( $payload ),
			)
		);
	}
}
