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

	/**
	 * BLUE-1473: a small, NAMED allow-list of AI-assistant referrer hostnames —
	 * mirrors the backend's `AI_REFERER_ALLOWLIST` (visibility-web/app/features/
	 * agent-traffic/ingest.ts). Deliberately not exhaustive: agentic browsers like
	 * ChatGPT Atlas typically strip the Referer header on outbound navigation, so
	 * this only ever catches the minority of AI-assistant traffic that still
	 * carries one — a cheap, additive signal, not the primary detection path.
	 */
	const AI_REFERER_DOMAINS = array(
		'chatgpt.com',
		'perplexity.ai',
		'claude.ai',
		'gemini.google.com',
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
		// BLUE-1473: read on every request, independent of whether the UA matches —
		// the referer-heuristic path below needs it even on a UA miss.
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		$match = $this->match_bot( $user_agent );
		if ( null !== $match ) {
			$this->report_hit( $match['bot_name'], $match['matched_substring'], $referer );
			return;
		}

		// Only fires when the referer itself is on AI_REFERER_DOMAINS, so an
		// ordinary human visit never reaches the endpoint; the backend
		// independently re-verifies this same allow-list server-side.
		if ( null !== $this->match_ai_referer( $referer ) ) {
			$this->report_hit( '', $user_agent, $referer );
		}
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
	 * Returns the matched domain from AI_REFERER_DOMAINS, or null. Hostname-only
	 * match (exact or subdomain) via wp_parse_url — never a substring scan of the
	 * whole referer string, so `https://evil.example/?u=chatgpt.com` cannot spoof
	 * a match. Mirrors the backend's `matchAiRefererDomain` (ingest.ts).
	 */
	private function match_ai_referer( $referer ) {
		if ( empty( $referer ) ) {
			return null;
		}
		$host = wp_parse_url( $referer, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return null;
		}
		$host = strtolower( $host );
		foreach ( self::AI_REFERER_DOMAINS as $domain ) {
			if ( $host === $domain || substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain ) {
				return $domain;
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
	private function report_hit( $bot_name, $matched_ua_string, $referer ) {
		$endpoint_url = get_option( 'bluerails_agent_traffic_endpoint_url', '' );
		$api_key      = get_option( 'bluerails_agent_traffic_api_key', '' );

		if ( empty( $endpoint_url ) || empty( $api_key ) ) {
			return; // Not configured yet — nothing to send, nothing to block on.
		}

		$page_path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		$payload = array(
			'bot_name'          => $bot_name,
			'matched_ua_string' => $matched_ua_string,
			'page_path'         => $page_path,
			'page_url'          => home_url( $page_path ),
			'timestamp'         => gmdate( 'c' ),
			'site_url'          => home_url(),
			// BLUE-1473: always sent when present (even on a UA match) — the
			// backend stores it for forensic value regardless of which path
			// accepted the row; only a UA-miss uses it to decide identity.
			'referer'           => $referer,
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
