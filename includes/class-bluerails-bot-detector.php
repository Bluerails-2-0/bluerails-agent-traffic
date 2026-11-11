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
		'Meta-ExternalAgent'  => 'Meta-ExternalAgent',

		// BLUE-1527 Tier 1 -- vendor/Cloudflare-confirmed *-User (live-fetch) and
		// *-SearchBot signatures, 2026-08-31. match_bot() below is first-match-wins
		// in array order, so any pair where one token is a substring of another
		// MUST be ordered longer/more-specific first -- never rely on append order
		// alone. 'Diffbot-User' contains 'Diffbot', so it is listed first.
		'OAI-SearchBot'         => 'OAI-SearchBot',
		'ChatGPT-User'          => 'ChatGPT-User',
		'Claude-User'           => 'Claude-User',
		'Claude-SearchBot'      => 'Claude-SearchBot',
		'Perplexity-User'       => 'Perplexity-User',
		'Google-CloudVertexBot' => 'Google-CloudVertexBot',
		'Meta-ExternalFetcher'  => 'Meta-ExternalFetcher',
		'DuckAssistBot'         => 'DuckAssistBot',
		'MistralAI-User'        => 'MistralAI-User',
		'Diffbot-User'          => 'Diffbot-User', // longer substring -- must precede 'Diffbot'.
		'Diffbot'               => 'Diffbot',
		'Kagibot'               => 'Kagibot',

		// BLUE-1527 Tier 2 -- multi-aggregator-corroborated (ai-robots-txt/ai.robots.txt
		// cross-checked against knownagents.com), not a vendor page -- same evidentiary
		// bar this file already applied to Bytespider/meta-externalagent above.
		// 'omgilibot' contains 'omgili' and 'webzio-extended' contains 'webzio', so each
		// longer token is listed first, same rule as the Tier 1 Diffbot pair.
		'Bravebot'              => 'Bravebot',            // Brave
		'YouBot'                => 'YouBot',              // You.com
		'YiyanBot'              => 'YiyanBot',             // Baidu
		'YandexAdditionalBot'   => 'YandexAdditionalBot',  // Yandex
		'Doubaobot'             => 'Doubaobot',            // ByteDance Doubao
		'QwenBot'               => 'QwenBot',              // Alibaba
		'TongyiBot'             => 'TongyiBot',            // Alibaba
		'Timpibot'              => 'Timpibot',             // Timpi
		'ImagesiftBot'          => 'ImagesiftBot',         // Hive
		'Omgilibot'             => 'Omgilibot',            // Webz.io -- longer substring first.
		'omgili'                => 'omgili',               // Webz.io
		'webzio-extended'       => 'webzio-extended',      // Webz.io -- longer substring first.
		'webzio'                => 'webzio',               // Webz.io
		'Andibot'               => 'Andibot',              // Andi Search

		// AI assistants and agents named on the operator's own crawler page.
		'Google-Agent'          => 'Google-Agent',
		'GoogleOther'           => 'GoogleOther',
		'Google-GeminiNotebook' => 'Google-GeminiNotebook',
		'Amzn-User'             => 'Amzn-User',
		'Amzn-SearchBot'        => 'Amzn-SearchBot',
		'MistralAI-Index'       => 'MistralAI-Index',
		'ExaUser'               => 'ExaUser',
		'Liner-User'            => 'Liner-User',
		// Listed by Mintlify's agent-traffic dataset; no operator page found.
		'grok-agent'            => 'grok-agent',
		'kagi-fetcher'          => 'kagi-fetcher',
		'TavilyBot'             => 'TavilyBot',
		'AzureAI-SearchBot'     => 'AzureAI-SearchBot',
		'Gemini-Deep-Research'  => 'Gemini-Deep-Research',
		// Already in the Bluerails registry. Matching ignores case, so 'AI2Bot' also
		// catches 'Ai2Bot-Dolma' and reports it as AI2Bot.
		'cohere-ai'             => 'cohere-ai',
		'AI2Bot'                => 'AI2Bot',
		'Kimi-User'             => 'Kimi-User',
	);

	/** Longest Accept header forwarded; the backend rejects anything longer. */
	const MAX_ACCEPT_LEN = 1024;

	/**
	 * BLUE-1473: a small, NAMED allow-list of AI-assistant referrer hostnames —
	 * mirrors the backend's `AI_REFERER_ALLOWLIST` (visibility-web/app/features/
	 * agent-traffic/ingest.ts). Deliberately not exhaustive: browser-extension agentic
	 * sessions (ChatGPT's post-Atlas Chrome extension/Work app, Anthropic's Claude for
	 * Chrome) typically strip the Referer header on outbound navigation, so
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

		// A request signed by its agent (e.g. ChatGPT's agent, which uses a
		// browser user agent) is reported even though its user agent matches
		// nothing; the backend counts it only if the signature verifies.
		$signed = $this->signed_request_fields();
		if ( null !== $signed ) {
			// Sent too so the backend can fall back to the markdown path if the signature fails.
			$accept = $this->markdown_accept_header();
			if ( null !== $accept ) {
				$signed['accept'] = $accept;
			}
			$this->report_hit( '', $user_agent, $referer, $signed );
			return;
		}

		// Only fires when the referer itself is on AI_REFERER_DOMAINS, so an
		// ordinary human visit never reaches the endpoint; the backend
		// independently re-verifies this same allow-list server-side.
		if ( null !== $this->match_ai_referer( $referer ) ) {
			$this->report_hit( '', $user_agent, $referer );
			return;
		}

		$this->maybe_report_markdown_request( $user_agent, $referer );
	}

	/**
	 * Reports a request whose Accept header ranks markdown above HTML. The
	 * backend ranks the header again and makes the final decision.
	 */
	private function maybe_report_markdown_request( $user_agent, $referer ) {
		$accept = $this->markdown_accept_header();
		if ( null !== $accept ) {
			$this->report_hit( '', $user_agent, $referer, array( 'accept' => $accept ) );
		}
	}

	/**
	 * The raw Accept header when it fits MAX_ACCEPT_LEN and ranks markdown
	 * above text/html, else null.
	 */
	private function markdown_accept_header() {
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			return null;
		}
		$accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );
		if ( strlen( $accept ) > self::MAX_ACCEPT_LEN || ! self::prefers_markdown( $accept ) ) {
			return null;
		}
		return $accept;
	}

	/**
	 * True when text/markdown or text/x-markdown ranks strictly above text/html
	 * (or HTML is absent). Ranks by q-value, ties broken by header order, q=0
	 * dropped. Ported from Mintlify's agent-traffic src/classify.ts (MIT License,
	 * Copyright (c) 2026 Mintlify, Inc.).
	 */
	private static function prefers_markdown( $accept ) {
		$ranked = array();
		foreach ( explode( ',', $accept ) as $position => $part ) {
			$params = explode( ';', strtolower( trim( $part ) ) );
			$type   = trim( array_shift( $params ) );
			$q      = 1.0;
			foreach ( $params as $param ) {
				$param = trim( $param );
				if ( 0 === strpos( $param, 'q=' ) ) {
					$value = substr( $param, 2 );
					// Same as JavaScript's Number(): empty is 0, non-numeric is treated as 1.
					$q = '' === $value ? 0.0 : ( is_numeric( $value ) ? (float) $value : 1.0 );
					break;
				}
			}
			if ( $q > 0 ) {
				$ranked[] = array( 'type' => $type, 'q' => $q, 'position' => $position );
			}
		}
		usort(
			$ranked,
			function ( $a, $b ) {
				if ( $a['q'] !== $b['q'] ) {
					return $a['q'] < $b['q'] ? 1 : -1;
				}
				return $a['position'] - $b['position'];
			}
		);
		$markdown = null;
		$html     = null;
		foreach ( $ranked as $index => $entry ) {
			if ( null === $markdown && in_array( $entry['type'], array( 'text/markdown', 'text/x-markdown' ), true ) ) {
				$markdown = $index;
			}
			if ( null === $html && 'text/html' === $entry['type'] ) {
				$html = $index;
			}
		}
		return null !== $markdown && ( null === $html || $markdown < $html );
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
	 * Returns the Signature, Signature-Input and Signature-Agent headers plus
	 * the request parts a signature can cover (host, scheme, method, raw URI),
	 * or null unless all three signature headers are present. Values are
	 * unslashed but not sanitised: the signature covers them byte for byte.
	 */
	private function signed_request_fields() {
		$keys = array(
			'signature'       => 'HTTP_SIGNATURE',
			'signature_input' => 'HTTP_SIGNATURE_INPUT',
			'signature_agent' => 'HTTP_SIGNATURE_AGENT',
		);
		$fields = array();
		foreach ( $keys as $field => $server_key ) {
			if ( empty( $_SERVER[ $server_key ] ) ) {
				return null;
			}
			$fields[ $field ] = wp_unslash( $_SERVER[ $server_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		$fields['request_host']   = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fields['request_scheme'] = is_ssl() ? 'https' : 'http';
		$fields['request_method'] = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fields['request_uri']    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return $fields;
	}

	/**
	 * Fire-and-forget POST to the configured ingest endpoint. Uses
	 * blocking => false so this never adds latency to the visitor's
	 * (bot's) page load, and returns immediately regardless of whether
	 * the endpoint or API key are configured yet.
	 */
	private function report_hit( $bot_name, $matched_ua_string, $referer, $extra_fields = array() ) {
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
		// Path-specific fields: the markdown Accept header, or the signed-request fields.
		$payload = array_merge( $payload, $extra_fields );

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
