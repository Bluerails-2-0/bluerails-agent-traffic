<?php
/**
 * Agent door (BLUE-2656): Bluerails_Agent_Door proxies the hotel's poppy.json and
 * poppy-0.4.json from Bluerails, and Bluerails_Bot_Detector reports every fetch of
 * those paths whatever the user agent.
 *
 * Plain PHP with minimal WordPress stubs: options and transients live in arrays,
 * wp_remote_get returns whatever the test queued, wp_remote_post records the payload.
 *
 * Run:  php tests/test-agent-door.php
 * Exits 0 on all-pass, 1 on any failure.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['opts']       = array();
$GLOBALS['transients'] = array();
$GLOBALS['requests']   = array();
$GLOBALS['response']   = null;
$GLOBALS['posts']      = array();

function add_action() {}
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function wp_doing_ajax() { return false; }
function is_ssl() { return true; }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function esc_url_raw( $url ) { return $url; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function home_url( $path = '' ) { return 'https://schwitzers.com' . $path; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $name ] : $default; }
function get_transient( $name ) { return isset( $GLOBALS['transients'][ $name ] ) ? $GLOBALS['transients'][ $name ]['value'] : false; }
function set_transient( $name, $value, $ttl ) { $GLOBALS['transients'][ $name ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
class WP_Error {
	public function get_error_message() { return 'cURL error 28: timed out'; }
}
function wp_remote_get( $url, $args ) {
	$GLOBALS['requests'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['response'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_post( $url, $args ) { $GLOBALS['posts'][] = json_decode( $args['body'], true ); }
$GLOBALS['status_sent']     = null;
$GLOBALS['settings_errors'] = array();
function status_header( $code ) { $GLOBALS['status_sent'] = $code; }
function add_settings_error( $setting, $code, $message ) { $GLOBALS['settings_errors'][] = $code; }
function __( $text ) { return $text; }

require __DIR__ . '/../includes/class-bluerails-bot-detector.php';
require __DIR__ . '/../includes/class-bluerails-agent-door.php';
require __DIR__ . '/../includes/class-bluerails-settings.php';

$log_file = tempnam( sys_get_temp_dir(), 'door-log' );
ini_set( 'error_log', $log_file );
ini_set( 'log_errors', '1' );

$failures = 0;
function check( $label, $passed ) {
	global $failures;
	if ( ! $passed ) {
		$failures++;
	}
	printf( "[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label );
}

const LISTING = '766cb53a-6677-4963-afdb-b2c72d180c1b';
const CHROME  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

// The live document for Schwitzer's, as 402.bluerails.com served it on 2026-11-25.
$live_body = '{"protocol_version":"1.0","organization":{"name":"Schwitzer\'s Hotel am Park","domain":"schwitzers.com"},"web":{},"apis":[{"type":"mcp","url":"https://402.bluerails.com/mcp/agent/v1/hotels/766cb53a-6677-4963-afdb-b2c72d180c1b/mcp","description":"Live room prices and availability, policies, and a booking link for Schwitzer\'s Hotel am Park. Answers for this hotel only."}],"supported_versions":{"0.4":"https://schwitzers.com/.well-known/poppy-0.4.json"}}';

function reset_state() {
	$GLOBALS['opts']       = array( 'bluerails_agent_door_listing_id' => LISTING );
	$GLOBALS['transients'] = array();
	$GLOBALS['requests']   = array();
	$GLOBALS['response']   = null;
	file_put_contents( $GLOBALS['log_file'], '' );
}

function logged() { return (string) file_get_contents( $GLOBALS['log_file'] ); }

function ttl_of_only_transient() {
	$t = array_values( $GLOBALS['transients'] );
	return 1 === count( $t ) ? $t[0]['ttl'] : null;
}

// --- which requests are door requests --------------------------------------------------------
function door_for( $uri, $get = array() ) {
	$_SERVER = array( 'REQUEST_URI' => $uri );
	$_GET    = $get;
	return Bluerails_Bot_Detector::requested_door_document();
}
check( '/.well-known/poppy.json is the door', 'poppy.json' === door_for( '/.well-known/poppy.json' ) );
check( '/.well-known/poppy-0.4.json is the door', 'poppy-0.4.json' === door_for( '/.well-known/poppy-0.4.json' ) );
check( 'a query string does not hide the door', 'poppy.json' === door_for( '/.well-known/poppy.json?x=1' ) );
check( 'a server rewrite can hand the door over as a query argument', 'poppy-0.4.json' === door_for( '/index.php?bluerails_agent_door=poppy-0.4.json', array( 'bluerails_agent_door' => 'poppy-0.4.json' ) ) );
check( 'the query argument takes only the two document names', null === door_for( '/', array( 'bluerails_agent_door' => '../wp-config.php' ) ) );
check( 'the query argument ignores arrays', null === door_for( '/', array( 'bluerails_agent_door' => array( 'poppy.json' ) ) ) );
check( 'poppy.json outside /.well-known/ is not the door', null === door_for( '/blog/.well-known/poppy.json' ) );
check( 'oauth-protected-resource is not served by this plugin', null === door_for( '/.well-known/oauth-protected-resource' ) );
check( 'mcp.json is not served by this plugin', null === door_for( '/.well-known/mcp.json' ) );

// --- listing id -------------------------------------------------------------------------------
check( 'a listing ID is trimmed and lowercased', LISTING === Bluerails_Agent_Door::sanitize_listing_id( '  766CB53A-6677-4963-AFDB-B2C72D180C1B ' ) );
check( 'a listing ID that is not a UUID is dropped', '' === Bluerails_Agent_Door::sanitize_listing_id( '766cb53a/../../mcp' ) );
check( 'the upstream URL is the hotel\'s own document', 'https://402.bluerails.com/mcp/agent/v1/hotels/' . LISTING . '/poppy-0.4.json' === Bluerails_Agent_Door::upstream_url( LISTING, 'poppy-0.4.json' ) );
$GLOBALS['opts'] = array();
check( 'no listing ID set: the door is off', null === Bluerails_Agent_Door::listing_id() );
$GLOBALS['opts'] = array( 'bluerails_agent_door_listing_id' => 'not-a-uuid' );
check( 'a bad stored listing ID: the door is off', null === Bluerails_Agent_Door::listing_id() );

// --- request host -----------------------------------------------------------------------------
$_SERVER = array( 'HTTP_HOST' => 'Schwitzers.COM:443' );
check( 'the request host is lowercased without its port', 'schwitzers.com' === Bluerails_Agent_Door::request_host() );
$_SERVER = array();
check( 'without a Host header the site address is used', 'schwitzers.com' === Bluerails_Agent_Door::request_host() );

// --- proxied 200 ------------------------------------------------------------------------------
reset_state();
$GLOBALS['response'] = array( 'code' => 200, 'body' => $live_body );
$out = Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'schwitzers.com' );
check( 'proxied 200: status 200', 200 === $out['status'] );
check( 'proxied 200: body passed through byte for byte', $live_body === $out['body'] );
$req = $GLOBALS['requests'][0];
check( 'proxied 200: fetched from the hotel\'s own document', 'https://402.bluerails.com/mcp/agent/v1/hotels/' . LISTING . '/poppy.json' === $req['url'] );
check( 'proxied 200: no redirect followed, at most 64 KB read, 3 s timeout', 0 === $req['args']['redirection'] && 65536 === $req['args']['limit_response_size'] && 3 === $req['args']['timeout'] );
check( 'proxied 200: cached for 300 s', 300 === ttl_of_only_transient() );
check( 'proxied 200: nothing logged', '' === logged() );
$GLOBALS['response'] = array( 'code' => 500, 'body' => '' );
$again = Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'schwitzers.com' );
check( 'proxied 200: the next request is served from cache, no second fetch', 1 === count( $GLOBALS['requests'] ) && $live_body === $again['body'] );
Bluerails_Agent_Door::response_for( LISTING, 'poppy-0.4.json', 'schwitzers.com' );
check( 'the 0.4 sibling has its own cache entry', 2 === count( $GLOBALS['requests'] ) && 2 === count( $GLOBALS['transients'] ) );

$headers = Bluerails_Agent_Door::headers_for( $out );
check( 'headers: Content-Type application/json', 'application/json' === $headers['Content-Type'] );
check( 'headers: not stored by caches, so every fetch is counted', 'no-store, max-age=0' === $headers['Cache-Control'] );
check( 'headers: readable cross-origin, no sniffing, no Retry-After on 200', '*' === $headers['Access-Control-Allow-Origin'] && 'nosniff' === $headers['X-Content-Type-Options'] && ! isset( $headers['Retry-After'] ) );

// --- 410 (switched off) and 404 -> 404 --------------------------------------------------------
reset_state();
$GLOBALS['response'] = array( 'code' => 410, 'body' => '{"error":"agent_tools_off"}' );
$out = Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'schwitzers.com' );
check( '410 (off switch) -> 404', 404 === $out['status'] );
check( '410 -> 404 body is ours, not the upstream\'s', '{"error":"not_found"}' === $out['body'] );
check( '410 -> 404 cached for 300 s', 300 === ttl_of_only_transient() );
reset_state();
$GLOBALS['response'] = array( 'code' => 404, 'body' => '{"error":"unknown_hotel"}' );
check( 'upstream 404 -> 404', 404 === Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'schwitzers.com' )['status'] );

// --- domain mismatch -> 404 and logged --------------------------------------------------------
reset_state();
$GLOBALS['response'] = array( 'code' => 200, 'body' => $live_body );
$out = Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'www.oberwirt-wangen.de' );
check( 'domain mismatch -> 404', 404 === $out['status'] && '{"error":"not_found"}' === $out['body'] );
check( 'domain mismatch is logged with both hosts', false !== strpos( logged(), 'names schwitzers.com but this request came to www.oberwirt-wangen.de (site address schwitzers.com)' ) );
reset_state();
$GLOBALS['response'] = array( 'code' => 200, 'body' => $live_body );
check( 'www. is a different host: www.schwitzers.com -> 404', 404 === Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'www.schwitzers.com' )['status'] );
reset_state();
$GLOBALS['response'] = array( 'code' => 200, 'body' => str_replace( '"schwitzers.com"', '"Schwitzers.com"', $live_body ) );
check( 'domain compared without regard to case', 200 === Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'schwitzers.com' )['status'] );

// --- upstream trouble -> 503, retried after a minute ------------------------------------------
reset_state();
$GLOBALS['response'] = new WP_Error();
$out = Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'schwitzers.com' );
check( 'network error -> 503, cached 60 s, logged', 503 === $out['status'] && 60 === ttl_of_only_transient() && false !== strpos( logged(), 'timed out' ) );
check( '503 carries Retry-After: 60', '60' === Bluerails_Agent_Door::headers_for( $out )['Retry-After'] );
reset_state();
$GLOBALS['response'] = array( 'code' => 502, 'body' => '' );
check( 'upstream 5xx -> 503', 503 === Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'schwitzers.com' )['status'] );
reset_state();
$GLOBALS['response'] = array( 'code' => 200, 'body' => '{"protocol_version":"1.0"' );
check( 'a truncated or invalid document is not served (503)', 503 === Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'schwitzers.com' )['status'] );
reset_state();
$GLOBALS['response'] = array( 'code' => 301, 'body' => '' );
check( 'an upstream redirect is not followed (503)', 503 === Bluerails_Agent_Door::response_for( LISTING, 'poppy.json', 'schwitzers.com' )['status'] );

// --- serve() and emit(): what the site sends ----------------------------------------------------
reset_state();
check( 'serve: no listing ID set -> nothing served, no fetch', null === Bluerails_Agent_Door::serve( null, 'poppy.json', 'schwitzers.com', 'GET' ) && 0 === count( $GLOBALS['requests'] ) );
check( 'serve: POST and OPTIONS are left to WordPress', null === Bluerails_Agent_Door::serve( LISTING, 'poppy.json', 'schwitzers.com', 'POST' ) && null === Bluerails_Agent_Door::serve( LISTING, 'poppy.json', 'schwitzers.com', 'OPTIONS' ) );
$GLOBALS['response'] = array( 'code' => 200, 'body' => $live_body );
$get = Bluerails_Agent_Door::serve( LISTING, 'poppy.json', 'schwitzers.com', 'GET' );
check( 'serve GET: 200, the upstream body, JSON headers', 200 === $get['status'] && $live_body === $get['body'] && 'application/json' === $get['headers']['Content-Type'] );
$head = Bluerails_Agent_Door::serve( LISTING, 'poppy.json', 'schwitzers.com', 'HEAD' );
check( 'serve HEAD: 200 and the same headers, but no body', 200 === $head['status'] && '' === $head['body'] && $get['headers'] === $head['headers'] );
reset_state();
$GLOBALS['response'] = new WP_Error();
check( 'serve: 503 carries Retry-After', '60' === Bluerails_Agent_Door::serve( LISTING, 'poppy.json', 'schwitzers.com', 'GET' )['headers']['Retry-After'] );
check( 'DONOTCACHEPAGE is not defined before anything is sent', ! defined( 'DONOTCACHEPAGE' ) );
// The CLI already printed the results above, so header() warns "headers already sent"; keep that out of the body.
ini_set( 'display_errors', '0' );
ob_start();
Bluerails_Agent_Door::emit( $get );
$sent = ob_get_clean();
check( 'emit: defines DONOTCACHEPAGE so page caches skip the answer', defined( 'DONOTCACHEPAGE' ) && true === DONOTCACHEPAGE );
check( 'emit: sends the status and exactly the body', 200 === $GLOBALS['status_sent'] && $live_body === $sent );
ob_start();
Bluerails_Agent_Door::emit( $head );
check( 'emit HEAD: no body sent', '' === ob_get_clean() );
ini_set( 'display_errors', '1' );

// --- settings: an invalid listing ID keeps the stored one -------------------------------------
$settings = ( new ReflectionClass( 'Bluerails_Settings' ) )->newInstanceWithoutConstructor();
$GLOBALS['opts'] = array( 'bluerails_agent_door_listing_id' => LISTING );
$GLOBALS['settings_errors'] = array();
check( 'settings: a typo keeps the working listing ID and shows an error', LISTING === $settings->sanitize_door_listing_id( '766cb53a-6677-4963-afdb-b2c72d180c1' ) && array( 'invalid_listing_id' ) === $GLOBALS['settings_errors'] );
$GLOBALS['settings_errors'] = array();
check( 'settings: a valid ID is saved lowercased, no error', '00000000-0000-4000-8000-00000000abcd' === $settings->sanitize_door_listing_id( '00000000-0000-4000-8000-00000000ABCD' ) && array() === $GLOBALS['settings_errors'] );
check( 'settings: an empty field switches the door off', '' === $settings->sanitize_door_listing_id( '  ' ) && array() === $GLOBALS['settings_errors'] );

// --- every GET is reported, whatever the user agent -------------------------------------------
function capture( array $server, array $get = array(), $listing_id = LISTING ) {
	$GLOBALS['opts']  = array(
		'bluerails_agent_traffic_endpoint_url' => 'https://discovery.bluerails.com/api/agent-traffic-ingest',
		'bluerails_agent_traffic_api_key'      => 'bak_test',
		'bluerails_agent_door_listing_id'      => $listing_id,
	);
	$GLOBALS['posts'] = array();
	$_SERVER          = array_map( 'addslashes', $server );
	$_GET             = $get;
	$reflection       = new ReflectionClass( 'Bluerails_Bot_Detector' );
	$reflection->newInstanceWithoutConstructor()->maybe_capture();
	return $GLOBALS['posts'];
}

$posts = capture( array( 'HTTP_USER_AGENT' => CHROME, 'REQUEST_URI' => '/.well-known/poppy.json' ) );
check( 'desktop Chrome GET of poppy.json is reported once', 1 === count( $posts ) );
check( 'desktop Chrome: full UA, empty bot name, door_document set', CHROME === $posts[0]['matched_ua_string'] && '' === $posts[0]['bot_name'] && 'poppy.json' === $posts[0]['door_document'] );
check( 'desktop Chrome: page_path is the door path', '/.well-known/poppy.json' === $posts[0]['page_path'] );

$posts = capture( array( 'HTTP_USER_AGENT' => 'curl/8.7.1', 'REQUEST_URI' => '/.well-known/poppy-0.4.json' ) );
check( 'curl GET of poppy-0.4.json is reported', 1 === count( $posts ) && 'poppy-0.4.json' === $posts[0]['door_document'] );

$posts = capture( array( 'REQUEST_URI' => '/.well-known/poppy.json' ) );
check( 'a fetch with no User-Agent at all is reported, as "-"', 1 === count( $posts ) && '-' === $posts[0]['matched_ua_string'] );

$posts = capture( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'REQUEST_URI' => '/.well-known/poppy.json' ) );
check( 'a known bot is reported once, as that bot, with door_document', 1 === count( $posts ) && 'GPTBot' === $posts[0]['bot_name'] && 'poppy.json' === $posts[0]['door_document'] );

$posts = capture(
	array(
		'HTTP_USER_AGENT'      => CHROME,
		'REQUEST_URI'          => '/.well-known/poppy.json',
		'HTTP_SIGNATURE'       => 'sig1=:AbCd:',
		'HTTP_SIGNATURE_INPUT' => 'sig1=("@authority");tag="web-bot-auth"',
		'HTTP_SIGNATURE_AGENT' => '"https://muse.ai"',
		'HTTP_HOST'            => 'schwitzers.com',
		'REQUEST_METHOD'       => 'GET',
	)
);
check( 'a signed fetch is reported once, signature fields and door_document both sent', 1 === count( $posts ) && '"https://muse.ai"' === $posts[0]['signature_agent'] && 'poppy.json' === $posts[0]['door_document'] );

$posts = capture( array( 'HTTP_USER_AGENT' => CHROME, 'REQUEST_URI' => '/index.php?bluerails_agent_door=poppy.json' ), array( 'bluerails_agent_door' => 'poppy.json' ) );
check( 'through a server rewrite: reported under the door path, not /index.php', 1 === count( $posts ) && '/.well-known/poppy.json' === $posts[0]['page_path'] );

$posts = capture( array( 'HTTP_USER_AGENT' => CHROME, 'REQUEST_URI' => '/.well-known/poppy.json' ), array(), '' );
check( 'no listing ID: a fetch of poppy.json sends no ingest POST', 0 === count( $posts ) );
$posts = capture( array( 'HTTP_USER_AGENT' => CHROME, 'REQUEST_URI' => '/?bluerails_agent_door=poppy.json' ), array( 'bluerails_agent_door' => 'poppy.json' ), '' );
check( 'no listing ID: the query argument sends no ingest POST either', 0 === count( $posts ) );
foreach ( array( 'HEAD', 'POST', 'OPTIONS' ) as $method ) {
	$posts = capture( array( 'HTTP_USER_AGENT' => CHROME, 'REQUEST_URI' => '/.well-known/poppy.json', 'REQUEST_METHOD' => $method ) );
	check( "$method of poppy.json is not counted as a door fetch", 0 === count( $posts ) );
}
$posts = capture( array( 'HTTP_USER_AGENT' => CHROME, 'REQUEST_URI' => '/.well-known/poppy.json', 'REQUEST_METHOD' => 'GET' ) );
check( 'an explicit GET is counted', 1 === count( $posts ) );

$posts = capture( array( 'HTTP_USER_AGENT' => CHROME, 'REQUEST_URI' => '/zimmer/' ) );
check( 'an ordinary Chrome page view is still not reported', 0 === count( $posts ) );
$posts = capture( array( 'HTTP_USER_AGENT' => CHROME, 'REQUEST_URI' => '/.well-known/oauth-protected-resource' ) );
check( 'a Chrome fetch of another .well-known path is not reported', 0 === count( $posts ) );

unlink( $log_file );
printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
