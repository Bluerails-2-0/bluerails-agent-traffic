<?php
/**
 * OpenAI Ads measurement (BLUE-2725): Bluerails_OpenAI_Ads_Measurement fetches its config in the
 * background, caches it, and enqueues the Pixel loader only when Bluerails switched it on.
 *
 * Plain PHP with minimal WordPress stubs: options, transients and cron live in arrays, and
 * wp_remote_get returns whatever the test queued.
 *
 * Run:  php tests/test-openai-ads-measurement.php
 * Exits 0 on all-pass, 1 on any failure.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'BLUERAILS_AGENT_TRAFFIC_FILE', dirname( __DIR__ ) . '/bluerails-agent-traffic.php' );
define( 'BLUERAILS_AGENT_TRAFFIC_VERSION', 'test' );

$GLOBALS['opts']       = array();
$GLOBALS['transients'] = array();
$GLOBALS['scheduled']  = array();
$GLOBALS['requests']   = array();
$GLOBALS['response']   = null;
$GLOBALS['enqueued']   = array();
$GLOBALS['localized']  = array();

function add_action() {}
$GLOBALS['filters'] = array();
$GLOBALS['preview'] = false;
function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['filters'] ) ? $GLOBALS['filters'][ $hook ] : $value; }
function is_admin() { return false; }
function is_customize_preview() { return 'customize' === $GLOBALS['preview']; }
function is_preview() { return 'post' === $GLOBALS['preview']; }
function wp_doing_cron() { return false; }
function wp_doing_ajax() { return false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['opts'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['opts'][ $name ] ); return true; }
function get_transient( $name ) { return isset( $GLOBALS['transients'][ $name ] ) ? $GLOBALS['transients'][ $name ]['value'] : false; }
function set_transient( $name, $value, $ttl ) { $GLOBALS['transients'][ $name ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function delete_transient( $name ) { unset( $GLOBALS['transients'][ $name ] ); return true; }
function wp_next_scheduled( $hook ) { return in_array( $hook, $GLOBALS['scheduled'], true ) ? time() : false; }
function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['scheduled'][] = $hook; return true; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
class WP_Error {}
function wp_remote_get( $url, $args ) {
	$GLOBALS['requests'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['response'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function plugins_url( $path, $file ) { return 'https://hotel.example/wp-content/plugins/bluerails-agent-traffic/' . $path; }
function wp_enqueue_script( $handle, $src, $deps, $ver, $in_footer ) { $GLOBALS['enqueued'][ $handle ] = array( 'src' => $src, 'in_footer' => $in_footer ); }
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized'][ $name ] = $data; }

require __DIR__ . '/../includes/class-bluerails-openai-ads-measurement.php';

$failures = 0;
function check( $label, $passed ) {
	global $failures;
	if ( ! $passed ) {
		$failures++;
	}
	printf( "[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label );
}

function reset_state( $opts = null ) {
	$GLOBALS['opts']       = null === $opts ? array(
		'bluerails_agent_traffic_endpoint_url' => 'https://discovery.bluerails.com/api/agent-traffic-ingest',
		'bluerails_agent_traffic_api_key'      => 'bak_test',
	) : $opts;
	$GLOBALS['transients'] = array();
	$GLOBALS['scheduled']  = array();
	$GLOBALS['requests']   = array();
	$GLOBALS['response']   = null;
	$GLOBALS['enqueued']   = array();
	$GLOBALS['localized']  = array();
	$GLOBALS['filters']    = array();
	$GLOBALS['preview']    = false;
}

function fresh( $body, $age = 0 ) {
	return Bluerails_OpenAI_Ads_Measurement::parse_config( $body ) + array( 'fetched_at' => time() - $age );
}

function module() {
	$reflection = new ReflectionClass( 'Bluerails_OpenAI_Ads_Measurement' );
	return $reflection->newInstanceWithoutConstructor();
}

$on_body = array(
	'enabled'       => true,
	'pixel_id'      => 'WJiUXtEVpLN3BqnY3LKTx2',
	'booking_hosts' => array( 'reservation.one.dirs21.de', 'Schwitzers.com/hotel-buchen/' ),
);

// --- config URL -------------------------------------------------------------------------------
check( 'config URL sits on the ingest endpoint host', 'https://discovery.bluerails.com/api/ads-measurement-config' === Bluerails_OpenAI_Ads_Measurement::config_url( 'https://discovery.bluerails.com/api/agent-traffic-ingest' ) );
check( 'config URL keeps a non-default port', 'https://discovery.example:8443/api/ads-measurement-config' === Bluerails_OpenAI_Ads_Measurement::config_url( 'https://discovery.example:8443/api/agent-traffic-ingest' ) );
check( 'plain http is refused when WP_DEBUG is off (the API key would travel in clear)', null === Bluerails_OpenAI_Ads_Measurement::config_url( 'http://discovery.example/api/agent-traffic-ingest' ) );
check( 'plain http is allowed only with WP_DEBUG on', 'http://localhost:5173/api/ads-measurement-config' === Bluerails_OpenAI_Ads_Measurement::config_url( 'http://localhost:5173/api/agent-traffic-ingest', true ) );
check( 'no config URL for an empty endpoint', null === Bluerails_OpenAI_Ads_Measurement::config_url( '' ) );
check( 'no config URL for a non-http endpoint', null === Bluerails_OpenAI_Ads_Measurement::config_url( 'ftp://x.example/a' ) );

// --- parsing ----------------------------------------------------------------------------------
$parsed = Bluerails_OpenAI_Ads_Measurement::parse_config( $on_body );
check( 'enabled config keeps the pixel id', true === $parsed['enabled'] && 'WJiUXtEVpLN3BqnY3LKTx2' === $parsed['pixel_id'] );
check( 'booking targets are lowercased, trailing slash dropped', array( 'reservation.one.dirs21.de', 'schwitzers.com/hotel-buchen' ) === $parsed['booking_hosts'] );
check( 'enabled: "true" (string) is off', false === Bluerails_OpenAI_Ads_Measurement::parse_config( array( 'enabled' => 'true', 'pixel_id' => 'abc' ) )['enabled'] );
check( 'enabled without a pixel id is off', false === Bluerails_OpenAI_Ads_Measurement::parse_config( array( 'enabled' => true ) )['enabled'] );
check( 'a pixel id with script characters is off', false === Bluerails_OpenAI_Ads_Measurement::parse_config( array( 'enabled' => true, 'pixel_id' => 'a"};alert(1)//' ) )['enabled'] );
check( 'a non-array body is off', false === Bluerails_OpenAI_Ads_Measurement::parse_config( null )['enabled'] );
$junk = Bluerails_OpenAI_Ads_Measurement::parse_config(
	array(
		'enabled'       => true,
		'pixel_id'      => 'abc',
		'booking_hosts' => array( 'https://booking.example', 'booking.example?x=1', 42, 'localhost', 'ok.example', 'ok.example' ),
	)
);
check( 'malformed booking targets are dropped, duplicates removed', array( 'ok.example' ) === $junk['booking_hosts'] );
$many = array();
for ( $i = 0; $i < 25; $i++ ) {
	$many[] = "h$i.example";
}
check( 'at most ten booking targets', 10 === count( Bluerails_OpenAI_Ads_Measurement::parse_config( array( 'enabled' => true, 'pixel_id' => 'abc', 'booking_hosts' => $many ) )['booking_hosts'] ) );

// --- refresh ----------------------------------------------------------------------------------
reset_state();
$GLOBALS['response'] = array( 'code' => 200, 'body' => json_encode( $on_body ) );
module()->refresh_config();
$req = $GLOBALS['requests'][0];
check( 'refresh calls the config endpoint with the bearer key', 'https://discovery.bluerails.com/api/ads-measurement-config' === $req['url'] && 'Bearer bak_test' === $req['args']['headers']['Authorization'] );
check( 'refresh follows no redirect and reads at most 16 KB', 0 === $req['args']['redirection'] && 16384 === $req['args']['limit_response_size'] );
check( 'a 200 stores when it was fetched', abs( time() - $GLOBALS['transients']['bluerails_oai_ads_config']['value']['fetched_at'] ) < 5 );
check( 'a 200 is cached for an hour', 3600 === $GLOBALS['transients']['bluerails_oai_ads_config']['ttl'] && true === $GLOBALS['transients']['bluerails_oai_ads_config']['value']['enabled'] );
check( 'a 200 is kept as the last good config', true === $GLOBALS['opts']['bluerails_oai_ads_config_last']['enabled'] );

$GLOBALS['response'] = array( 'code' => 503, 'body' => '' );
module()->refresh_config();
check( 'a 5xx keeps the last good config and retries in five minutes', 300 === $GLOBALS['transients']['bluerails_oai_ads_config']['ttl'] && true === $GLOBALS['transients']['bluerails_oai_ads_config']['value']['enabled'] );

$GLOBALS['response'] = new WP_Error();
module()->refresh_config();
check( 'a network error keeps the last good config', true === $GLOBALS['transients']['bluerails_oai_ads_config']['value']['enabled'] );

$GLOBALS['response'] = array( 'code' => 401, 'body' => '{"error":"invalid_api_key"}' );
module()->refresh_config();
check( 'a 401 switches measurement off', false === $GLOBALS['transients']['bluerails_oai_ads_config']['value']['enabled'] && false === $GLOBALS['opts']['bluerails_oai_ads_config_last']['enabled'] );

reset_state( array( 'bluerails_agent_traffic_endpoint_url' => 'http://discovery.bluerails.com/api/agent-traffic-ingest', 'bluerails_agent_traffic_api_key' => 'bak_test' ) );
module()->refresh_config();
check( 'an http endpoint: no request, the key is never sent, measurement off', 0 === count( $GLOBALS['requests'] ) && false === $GLOBALS['transients']['bluerails_oai_ads_config']['value']['enabled'] );

reset_state( array( 'bluerails_agent_traffic_endpoint_url' => 'https://discovery.bluerails.com/api/agent-traffic-ingest' ) );
module()->refresh_config();
check( 'no API key: no request, measurement off', 0 === count( $GLOBALS['requests'] ) && false === $GLOBALS['transients']['bluerails_oai_ads_config']['value']['enabled'] );

// --- page view --------------------------------------------------------------------------------
reset_state();
module()->maybe_enqueue();
check( 'first page view: nothing enqueued, one refresh scheduled, no request while the visitor waits', array() === $GLOBALS['enqueued'] && array( 'bluerails_oai_ads_refresh_config' ) === $GLOBALS['scheduled'] && 0 === count( $GLOBALS['requests'] ) );
module()->maybe_enqueue();
check( 'a second page view does not schedule a second refresh', 1 === count( $GLOBALS['scheduled'] ) );

reset_state();
set_transient( 'bluerails_oai_ads_config', fresh( $on_body ), 3600 );
module()->maybe_enqueue();
check( 'enabled: loader enqueued in the head', isset( $GLOBALS['enqueued']['bluerails-openai-ads-measurement'] ) && false === $GLOBALS['enqueued']['bluerails-openai-ads-measurement']['in_footer'] );
check(
	'enabled: config handed to the browser',
	array(
		'pixelId'       => 'WJiUXtEVpLN3BqnY3LKTx2',
		'bookingHosts'  => array( 'reservation.one.dirs21.de', 'schwitzers.com/hotel-buchen' ),
		'rcbService'    => 'openai-ads-measurement',
		'assumeConsent' => false,
	) === $GLOBALS['localized']['bluerailsOaiAds']
);
check( 'enabled: no request on the page view', 0 === count( $GLOBALS['requests'] ) );

reset_state();
$GLOBALS['opts']['bluerails_oai_ads_config_last'] = fresh( $on_body );
module()->maybe_enqueue();
check( 'expired cache: last good config still loads the Pixel, refresh scheduled', isset( $GLOBALS['enqueued']['bluerails-openai-ads-measurement'] ) && 1 === count( $GLOBALS['scheduled'] ) );

reset_state();
set_transient( 'bluerails_oai_ads_config', fresh( $on_body, 7 * 86400 + 60 ), 3600 );
module()->maybe_enqueue();
check( 'a config fetched more than seven days ago is off', array() === $GLOBALS['enqueued'] );

reset_state();
set_transient( 'bluerails_oai_ads_config', fresh( $on_body, 6 * 86400 ), 3600 );
module()->maybe_enqueue();
check( 'a config fetched six days ago still loads', isset( $GLOBALS['enqueued']['bluerails-openai-ads-measurement'] ) );

reset_state();
set_transient( 'bluerails_oai_ads_config', Bluerails_OpenAI_Ads_Measurement::parse_config( $on_body ), 3600 );
module()->maybe_enqueue();
check( 'a config without fetched_at (pre-fix cache) is off', array() === $GLOBALS['enqueued'] );

reset_state();
$GLOBALS['opts']['bluerails_oai_ads_config_last'] = fresh( $on_body, 8 * 86400 );
module()->maybe_enqueue();
check( 'expired cache with a stale last good config: off, refresh still scheduled', array() === $GLOBALS['enqueued'] && 1 === count( $GLOBALS['scheduled'] ) );

foreach ( array( 'customize', 'post' ) as $mode ) {
	reset_state();
	set_transient( 'bluerails_oai_ads_config', fresh( $on_body ), 3600 );
	$GLOBALS['preview'] = $mode;
	module()->maybe_enqueue();
	check( "skipped in a $mode preview", array() === $GLOBALS['enqueued'] );
}

reset_state();
set_transient( 'bluerails_oai_ads_config', fresh( $on_body ), 3600 );
$GLOBALS['filters']['bluerails_oai_ads_assume_consent'] = true;
module()->maybe_enqueue();
check( 'bluerails_oai_ads_assume_consent filter on: assumeConsent true', true === $GLOBALS['localized']['bluerailsOaiAds']['assumeConsent'] );

reset_state();
set_transient( 'bluerails_oai_ads_config', fresh( $on_body ), 3600 );
$GLOBALS['filters']['bluerails_oai_ads_assume_consent'] = '1';
module()->maybe_enqueue();
check( 'assume-consent filter must be exactly true', false === $GLOBALS['localized']['bluerailsOaiAds']['assumeConsent'] );

reset_state();
set_transient( 'bluerails_oai_ads_config', array( 'enabled' => false ), 3600 );
module()->maybe_enqueue();
check( 'disabled: nothing enqueued', array() === $GLOBALS['enqueued'] );

reset_state( array() );
set_transient( 'bluerails_oai_ads_config', fresh( $on_body ), 3600 );
module()->maybe_enqueue();
check( 'plugin not configured: nothing enqueued even with a cached config', array() === $GLOBALS['enqueued'] );

reset_state();
set_transient( 'bluerails_oai_ads_config', array( 'enabled' => true ), 3600 );
$GLOBALS['opts']['bluerails_oai_ads_config_last'] = array( 'enabled' => true );
Bluerails_OpenAI_Ads_Measurement::clear_cache();
check( 'changing the key or endpoint clears both caches', false === get_transient( 'bluerails_oai_ads_config' ) && false === get_option( 'bluerails_oai_ads_config_last' ) );

printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
