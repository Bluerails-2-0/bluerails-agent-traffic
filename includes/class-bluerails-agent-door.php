<?php
/**
 * The hotel's agent door (BLUE-2656): serves /.well-known/poppy.json and
 * /.well-known/poppy-0.4.json on this site's own host by proxying the
 * documents Bluerails generates for the hotel's listing. Same host, never a
 * redirect: Poppy requires `organization.domain` to be the host that serves
 * the document, so a redirect to Bluerails would break it.
 *
 * Off until the listing ID is set (settings screen or
 * `wp option update bluerails_agent_door_listing_id <uuid>`). Fetches of the
 * two paths are reported by Bluerails_Bot_Detector, which runs first.
 *
 * @package Bluerails_Agent_Traffic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bluerails_Agent_Door {

	const OPT_LISTING_ID = 'bluerails_agent_door_listing_id';
	const UPSTREAM_BASE  = 'https://402.bluerails.com/mcp/agent/v1/hotels/';
	const CACHE_PREFIX   = 'bluerails_agent_door_';
	/** Same as the upstream's own max-age, so a switch-off reaches agents within five minutes. */
	const CACHE_TTL = 300;
	/** An unreachable or broken upstream is retried after a minute, not on every request. */
	const ERROR_TTL = 60;
	const MAX_BYTES = 65536;
	const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// After Bluerails_Bot_Detector::maybe_capture() (priority 10), which reports the fetch.
		add_action( 'wp_loaded', array( $this, 'maybe_serve' ), 20 );
	}

	public function maybe_serve() {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}
		$document = Bluerails_Bot_Detector::requested_door_document();
		if ( null === $document ) {
			return;
		}
		$response = self::serve( self::listing_id(), $document, self::request_host(), Bluerails_Bot_Detector::request_method() );
		if ( null === $response ) {
			return; // Not set up on this site, or not GET/HEAD: WordPress answers as it did before.
		}
		self::emit( $response );
		exit;
	}

	/**
	 * The full answer to a door request, array( 'status', 'headers', 'body' ), or
	 * null when this site has no listing ID or the method is neither GET nor HEAD.
	 */
	public static function serve( $listing_id, $document, $host, $method ) {
		if ( null === $listing_id || ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return null;
		}
		$response = self::response_for( $listing_id, $document, $host );
		return array(
			'status'  => $response['status'],
			'headers' => self::headers_for( $response ),
			'body'    => 'HEAD' === $method ? '' : $response['body'],
		);
	}

	/** Sends a serve() answer. */
	public static function emit( $response ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Page-cache plugins: see headers_for().
		}
		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the upstream JSON, byte for byte.
	}

	/** The listing ID from the option, or null when unset or not a UUID. */
	public static function listing_id() {
		$value = self::sanitize_listing_id( get_option( self::OPT_LISTING_ID, '' ) );
		return '' === $value ? null : $value;
	}

	/** Lowercased UUID, or '' for anything else. Also the settings screen's sanitize callback. */
	public static function sanitize_listing_id( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return 1 === preg_match( self::UUID_PATTERN, $value ) ? $value : '';
	}

	public static function upstream_url( $listing_id, $document ) {
		return self::UPSTREAM_BASE . $listing_id . '/' . $document;
	}

	/** The host this request was made to, lowercased, without port or trailing dot. */
	public static function request_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		if ( '' === $host ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		$host = strtolower( preg_replace( '/:\d+$/', '', $host ) );
		return rtrim( $host, '.' );
	}

	/**
	 * What to answer: array( 'status' => int, 'body' => string ). 200 carries the
	 * upstream body unchanged; the upstream's 404 and 410 (switched off) become 404,
	 * and so does a document naming another host than this one.
	 */
	public static function response_for( $listing_id, $document, $host ) {
		$key      = self::CACHE_PREFIX . md5( $listing_id . '/' . $document );
		$upstream = get_transient( $key );
		if ( ! is_array( $upstream ) || ! isset( $upstream['status'] ) ) {
			$upstream = self::fetch( $listing_id, $document, $host );
			set_transient( $key, $upstream, 503 === $upstream['status'] ? self::ERROR_TTL : self::CACHE_TTL );
		}

		if ( 200 === $upstream['status'] && $upstream['domain'] === $host ) {
			return array( 'status' => 200, 'body' => $upstream['body'] );
		}
		if ( 503 === $upstream['status'] ) {
			return array( 'status' => 503, 'body' => '{"error":"door_unavailable"}' );
		}
		return array( 'status' => 404, 'body' => '{"error":"not_found"}' );
	}

	/** Fetches the upstream document and reduces it to what response_for() caches. */
	public static function fetch( $listing_id, $document, $host ) {
		$url      = self::upstream_url( $listing_id, $document );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 3,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::log( sprintf( '%s could not be fetched: %s', $url, $response->get_error_message() ) );
			return array( 'status' => 503 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code || 410 === $code ) {
			return array( 'status' => 404 );
		}
		if ( 200 !== $code ) {
			self::log( sprintf( '%s answered HTTP %d.', $url, $code ) );
			return array( 'status' => 503 );
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$domain  = is_array( $decoded ) && isset( $decoded['organization']['domain'] ) && is_string( $decoded['organization']['domain'] )
			? strtolower( $decoded['organization']['domain'] )
			: null;
		if ( null === $domain ) {
			self::log( sprintf( '%s returned no organization.domain.', $url ) );
			return array( 'status' => 503 );
		}
		if ( $domain !== $host ) {
			self::log( sprintf( '%s names %s but this request came to %s (site address %s), so it is answered with 404. Fix the listing\'s agent_door_host or the listing ID.', $url, $domain, $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		}
		return array(
			'status' => 200,
			'body'   => $body,
			'domain' => $domain,
		);
	}

	public static function headers_for( $response ) {
		$headers = array(
			'Content-Type'                => 'application/json',
			// Not cached by browsers, proxies or CDNs, so every fetch reaches WordPress and is counted.
			'Cache-Control'               => 'no-store, max-age=0',
			'Access-Control-Allow-Origin' => '*',
			'X-Content-Type-Options'      => 'nosniff',
		);
		if ( 503 === $response['status'] ) {
			$headers['Retry-After'] = (string) self::ERROR_TTL;
		}
		return $headers;
	}

	private static function log( $message ) {
		error_log( 'Bluerails agent door: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
