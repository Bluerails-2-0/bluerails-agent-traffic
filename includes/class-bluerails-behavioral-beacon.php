<?php
/**
 * BLUE-1474 — client-side behavioral signal for rendered-browser agent traffic (the
 * browser-extension agentic-session class — CORRECTED 2026-08-28: ChatGPT Atlas the standalone
 * app was retired 2026-08-09; its successor is a Chrome extension + Work desktop app on the
 * same architecture, and Anthropic's Claude for Chrome is a directly comparable live product).
 * Two halves:
 *
 * 1. Enqueues `assets/js/bluerails-behavioral-beacon.js` in the footer of every front-end page,
 *    but ONLY when the site owner has explicitly turned the feature on (the "ship behind a flag"
 *    ticket AC — a NEW, separate opt-in from the existing endpoint/API-key configuration, default
 *    OFF) and the endpoint/API key are both configured (mirrors Bot_Detector::report_hit's own
 *    "nothing to send yet" skip). The beacon's OWN first action, once loaded, is a visitor-consent
 *    check (Complianz) — this class does not gate on visitor consent itself, because that state
 *    lives client-side (see the JS file's header for why).
 * 2. Registers a same-origin REST proxy the beacon POSTs its feature summary to. A proxy, not a
 *    direct POST to the Bluerails ingest endpoint from the browser, because this is the first
 *    signal this plugin observes CLIENT-SIDE — the existing bot-UA/referer paths run entirely in
 *    PHP and can attach the API key server-side. Putting the API key in a browser-visible request
 *    instead would leak it to every visitor via the network tab. This route re-derives the
 *    User-Agent and Referer from `$_SERVER` (never trusts a client-supplied value for those, same
 *    discipline as the rest of this plugin) and forwards server-side with `wp_remote_post`,
 *    exactly like `report_hit()`.
 *
 * @package Bluerails_Agent_Traffic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bluerails_Behavioral_Beacon {

	const OPT_ENABLED  = 'bluerails_agent_traffic_behavioral_enabled';
	const REST_NAMESPACE = 'bluerails-agent-traffic/v1';
	const REST_ROUTE      = '/behavioral';
	const HANDLE          = 'bluerails-behavioral-beacon';

	// Mirrors visibility-web's BEHAVIORAL_BOUNDS (api.agent-traffic-ingest.ts) — this proxy
	// forwards whatever the beacon sends, but still bounds-checks before spending a
	// wp_remote_post on an obviously malformed body.
	const BOUNDS = array(
		'move_count'         => array( 0, 100000 ),
		'duration_ms'        => array( 0, 1800000 ),
		'avg_interval_ms'    => array( 0, 1800000 ),
		'interval_stddev_ms' => array( 0, 1800000 ),
		'quantized_ratio'    => array( 0, 1 ),
	);

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_beacon' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * True only when the site owner has explicitly opted in AND the plugin is otherwise
	 * configured. Three independent conditions, all required — mirrors report_hit()'s own
	 * "nothing to send yet" check for the endpoint/API-key half.
	 */
	private function is_enabled() {
		if ( '1' !== get_option( self::OPT_ENABLED, '' ) ) {
			return false;
		}
		$endpoint_url = get_option( 'bluerails_agent_traffic_endpoint_url', '' );
		$api_key      = get_option( 'bluerails_agent_traffic_api_key', '' );
		return ! empty( $endpoint_url ) && ! empty( $api_key );
	}

	public function maybe_enqueue_beacon() {
		// Front-end only — wp_enqueue_scripts does not fire on admin/cron/AJAX requests, but this
		// guard is kept explicit for the same reason Bot_Detector keeps its own (defense in depth,
		// and a reader checking this file alone can see the intent without cross-referencing).
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}
		if ( ! $this->is_enabled() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/bluerails-behavioral-beacon.js', BLUERAILS_AGENT_TRAFFIC_FILE ),
			array(),
			BLUERAILS_AGENT_TRAFFIC_VERSION,
			true // in the footer — the ticket's own enqueue-location requirement.
		);
		wp_localize_script(
			self::HANDLE,
			'bluerailsBehavioralBeacon',
			array(
				// This site's OWN REST route, not the Bluerails ingest endpoint — see file header
				// for why the API key must never reach the browser.
				'restUrl' => rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
			)
		);
	}

	public function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest_request' ),
				// No WP identity to nonce against (anonymous visitor browser), but BLUE-1474
				// review found this accepted a POST from anywhere with no page load at all —
				// is_same_origin_request() below closes that bare-curl gap (weak vs a spoofed
				// header, non-zero vs no check at all).
				'permission_callback' => array( $this, 'is_same_origin_request' ),
			)
		);
	}

	/**
	 * BLUE-1474 fix — permission_callback for the /behavioral route. Requires the request's
	 * Origin header (falling back to Referer when Origin is absent, since some browser
	 * contexts omit Origin on same-origin requests) to resolve to the same host as this
	 * site's own home_url(). Hostname-only, exact match — deliberately not a subdomain match
	 * like match_ai_referer(), since Origin/Referer here should always be THIS site, not a
	 * related domain. A request with neither header, or a header naming a different host, is
	 * rejected (WP core turns a false return into a 401/403 rest_forbidden response).
	 */
	public function is_same_origin_request( $request ) {
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( empty( $home_host ) ) {
			return false;
		}

		$origin = $request->get_header( 'origin' );
		$source = ! empty( $origin ) ? $origin : $request->get_header( 'referer' );
		if ( empty( $source ) ) {
			return false;
		}

		$source_host = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		if ( empty( $source_host ) ) {
			return false;
		}

		return $source_host === $home_host;
	}

	/**
	 * Validates a single bounded numeric field. Returns the value cast to float on success, or
	 * null on any type/range violation — mirrors visibility-web's isBoundedFiniteNumber.
	 */
	private function bounded_number( $value, $bounds ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$num = (float) $value;
		if ( ! is_finite( $num ) || $num < $bounds[0] || $num > $bounds[1] ) {
			return null;
		}
		return $num;
	}

	/**
	 * Re-checks is_enabled() again at request time (not just at enqueue time) — a request could
	 * arrive after the site owner disables the feature mid-session, or (since this route has no
	 * origin check) from any client at all, not only this site's own beacon. Returns a
	 * WP_REST_Response either way; never a fatal.
	 */
	public function handle_rest_request( $request ) {
		if ( ! $this->is_enabled() ) {
			return new WP_REST_Response( array( 'ok' => false ), 404 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['behavioral'] ) || ! is_array( $body['behavioral'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_payload' ), 400 );
		}
		$b = $body['behavioral'];

		if ( ! isset( $b['pointer_capable'] ) || ! is_bool( $b['pointer_capable'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_payload' ), 400 );
		}
		$move_count         = $this->bounded_number( isset( $b['move_count'] ) ? $b['move_count'] : null, self::BOUNDS['move_count'] );
		$duration_ms        = $this->bounded_number( isset( $b['duration_ms'] ) ? $b['duration_ms'] : null, self::BOUNDS['duration_ms'] );
		$avg_interval_ms    = $this->bounded_number( isset( $b['avg_interval_ms'] ) ? $b['avg_interval_ms'] : null, self::BOUNDS['avg_interval_ms'] );
		$interval_stddev_ms = $this->bounded_number( isset( $b['interval_stddev_ms'] ) ? $b['interval_stddev_ms'] : null, self::BOUNDS['interval_stddev_ms'] );
		$quantized_ratio    = $this->bounded_number( isset( $b['quantized_ratio'] ) ? $b['quantized_ratio'] : null, self::BOUNDS['quantized_ratio'] );
		if ( null === $move_count || null === $duration_ms || null === $avg_interval_ms || null === $interval_stddev_ms || null === $quantized_ratio ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_payload' ), 400 );
		}

		$page_path = isset( $body['page_path'] ) && is_string( $body['page_path'] ) ? sanitize_text_field( $body['page_path'] ) : '/';
		if ( '' === $page_path || '/' !== $page_path[0] ) {
			$page_path = '/';
		}

		$endpoint_url = get_option( 'bluerails_agent_traffic_endpoint_url', '' );
		$api_key      = get_option( 'bluerails_agent_traffic_api_key', '' );

		// Server truth, never the client's own claim — same discipline as Bot_Detector::maybe_capture.
		$user_agent = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';

		$payload = array(
			'matched_ua_string' => $user_agent,
			'page_path'         => $page_path,
			'timestamp'         => gmdate( 'c' ),
			'site_url'          => home_url(),
			'behavioral'        => array(
				'pointer_capable'    => $b['pointer_capable'],
				'move_count'         => $move_count,
				'duration_ms'        => $duration_ms,
				'avg_interval_ms'    => $avg_interval_ms,
				'interval_stddev_ms' => $interval_stddev_ms,
				'quantized_ratio'    => $quantized_ratio,
			),
		);

		// Fire-and-forget from the VISITOR's perspective (the REST response below returns
		// immediately regardless), but this call itself is a normal blocking wp_remote_post — the
		// visitor's browser already got its response the instant this PHP request completes, same
		// as any other REST callback; there is no separate "visitor page load" being blocked here.
		wp_remote_post(
			$endpoint_url,
			array(
				'timeout'   => 3,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'      => wp_json_encode( $payload ),
			)
		);

		return new WP_REST_Response( array( 'ok' => true ), 202 );
	}
}
