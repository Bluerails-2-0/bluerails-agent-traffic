<?php
/**
 * BLUE-2725 — "OpenAI Ads Messung": loads OpenAI's Measurement Pixel on this site when Bluerails
 * has switched measurement on for the hotel's account, so the hotel's ChatGPT ad campaigns can
 * count page views and booking starts.
 *
 * Bluerails, not the site owner, decides whether it runs: the config (enabled flag, Pixel ID,
 * booking link targets) comes from Bluerails' `/api/ads-measurement-config` endpoint, called with
 * the API key this plugin already holds for agent-traffic ingest. Nothing runs until that
 * endpoint returns `enabled: true` with a valid Pixel ID.
 *
 * The config is never fetched while a visitor waits. A page view reads the cached copy (transient,
 * one hour; the last good copy is also kept in an option so an expired transient never switches
 * measurement off). When the transient has expired, the page view schedules a single WP-cron run
 * that refreshes it in the background. Changing the endpoint URL or API key clears the cache.
 *
 * The browser half (`assets/js/bluerails-openai-ads-measurement.js`) waits for consent from
 * Borlabs Cookie (marketing group) or Real Cookie Banner, and otherwise loads the Pixel with
 * OpenAI's own consent set to false. See the JS file for the details.
 *
 * @package Bluerails_Agent_Traffic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bluerails_OpenAI_Ads_Measurement {

	const HANDLE          = 'bluerails-openai-ads-measurement';
	const TRANSIENT       = 'bluerails_oai_ads_config';
	const OPT_LAST_CONFIG = 'bluerails_oai_ads_config_last';
	const CRON_HOOK       = 'bluerails_oai_ads_refresh_config';
	const CONFIG_PATH     = '/api/ads-measurement-config';
	const FRESH_TTL       = 3600; // one hour: how long Bluerails waits at most before a switch takes effect
	const RETRY_TTL       = 300;  // after a failed refresh, try again in five minutes
	const MAX_TARGETS     = 10;

	// Real Cookie Banner has no group-level consent call; the hotel's banner needs a service with
	// this unique name (filterable per site with `bluerails_oai_ads_rcb_service`).
	const RCB_SERVICE = 'openai-ads-measurement';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 1 );
		add_action( self::CRON_HOOK, array( $this, 'refresh_config' ) );
		add_action( 'update_option_bluerails_agent_traffic_api_key', array( __CLASS__, 'clear_cache' ) );
		add_action( 'update_option_bluerails_agent_traffic_endpoint_url', array( __CLASS__, 'clear_cache' ) );
	}

	public static function clear_cache() {
		delete_transient( self::TRANSIENT );
		delete_option( self::OPT_LAST_CONFIG );
	}

	/**
	 * The config URL lives on the same Bluerails host as the configured ingest endpoint, so a site
	 * pointed at another Bluerails environment reads that environment's config. Null when the
	 * endpoint is unset or not an http(s) URL.
	 */
	public static function config_url( $endpoint_url ) {
		$parts = wp_parse_url( (string) $endpoint_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $parts['host'] . $port . self::CONFIG_PATH;
	}

	/**
	 * Validates the endpoint's JSON body. Returns the normalised config when measurement is on and
	 * every field is well formed, or `array( 'enabled' => false )` for anything else, so a
	 * malformed answer switches measurement off rather than loading a broken Pixel.
	 */
	public static function parse_config( $body ) {
		$off = array( 'enabled' => false );
		if ( ! is_array( $body ) || true !== ( isset( $body['enabled'] ) ? $body['enabled'] : null ) ) {
			return $off;
		}
		$pixel_id = isset( $body['pixel_id'] ) ? $body['pixel_id'] : null;
		if ( ! is_string( $pixel_id ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $pixel_id ) ) {
			return $off;
		}
		$targets = array();
		$raw     = isset( $body['booking_hosts'] ) && is_array( $body['booking_hosts'] ) ? $body['booking_hosts'] : array();
		foreach ( $raw as $entry ) {
			$target = self::normalise_target( $entry );
			if ( null !== $target && ! in_array( $target, $targets, true ) ) {
				$targets[] = $target;
			}
			if ( count( $targets ) >= self::MAX_TARGETS ) {
				break;
			}
		}
		return array(
			'enabled'       => true,
			'pixel_id'      => $pixel_id,
			'booking_hosts' => $targets,
		);
	}

	/**
	 * A booking target is a host ("booking.hotel.de") or a host with a path prefix
	 * ("hotel.de/hotel-buchen"), lowercase, no scheme, query or port. Null when malformed.
	 */
	public static function normalise_target( $entry ) {
		if ( ! is_string( $entry ) ) {
			return null;
		}
		$entry = strtolower( trim( $entry ) );
		if ( ! preg_match( '#^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+)(/[a-z0-9._~%/-]*)?$#', $entry, $m ) ) {
			return null;
		}
		$path = isset( $m[2] ) ? rtrim( $m[2], '/' ) : '';
		return $m[1] . $path;
	}

	/**
	 * Background refresh (WP-cron). A 200 replaces the cached config. A 401/403/404 means Bluerails
	 * does not (or no longer) recognise this site's key, so measurement switches off. A network
	 * error or a 5xx keeps the last good config and retries in five minutes.
	 */
	public function refresh_config() {
		$endpoint_url = get_option( 'bluerails_agent_traffic_endpoint_url', '' );
		$api_key      = get_option( 'bluerails_agent_traffic_api_key', '' );
		$url          = self::config_url( $endpoint_url );
		if ( empty( $api_key ) || null === $url ) {
			self::store( array( 'enabled' => false ), self::FRESH_TTL );
			return;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 5,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
			)
		);

		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			self::store( self::parse_config( $body ), self::FRESH_TTL );
			return;
		}
		if ( 401 === $status || 403 === $status || 404 === $status ) {
			self::store( array( 'enabled' => false ), self::FRESH_TTL );
			return;
		}
		$last = get_option( self::OPT_LAST_CONFIG, null );
		set_transient( self::TRANSIENT, is_array( $last ) ? $last : array( 'enabled' => false ), self::RETRY_TTL );
	}

	private static function store( $config, $ttl ) {
		set_transient( self::TRANSIENT, $config, $ttl );
		update_option( self::OPT_LAST_CONFIG, $config, false );
	}

	/**
	 * The config a page view uses, without any network call. On an expired transient this returns
	 * the last good config and schedules one background refresh.
	 */
	public function current_config() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
		$last = get_option( self::OPT_LAST_CONFIG, null );
		return is_array( $last ) ? $last : array( 'enabled' => false );
	}

	public function maybe_enqueue() {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}
		$endpoint_url = get_option( 'bluerails_agent_traffic_endpoint_url', '' );
		$api_key      = get_option( 'bluerails_agent_traffic_api_key', '' );
		if ( empty( $endpoint_url ) || empty( $api_key ) ) {
			return;
		}

		$config = $this->current_config();
		if ( empty( $config['enabled'] ) || empty( $config['pixel_id'] ) ) {
			return;
		}

		// In the <head>, as OpenAI's install guide asks, so an early booking click is not lost.
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/bluerails-openai-ads-measurement.js', BLUERAILS_AGENT_TRAFFIC_FILE ),
			array(),
			BLUERAILS_AGENT_TRAFFIC_VERSION,
			false
		);
		wp_localize_script(
			self::HANDLE,
			'bluerailsOaiAds',
			array(
				'pixelId'      => $config['pixel_id'],
				'bookingHosts' => isset( $config['booking_hosts'] ) ? array_values( $config['booking_hosts'] ) : array(),
				'rcbService'   => (string) apply_filters( 'bluerails_oai_ads_rcb_service', self::RCB_SERVICE ),
			)
		);
	}
}
