<?php
/**
 * Signed-request forwarding test for Bluerails_Bot_Detector::maybe_capture().
 *
 * A request carrying Signature, Signature-Input and Signature-Agent with an
 * ordinary Chrome user agent must produce exactly one ingest POST carrying the
 * three headers as received plus host, scheme, method and the raw request URI.
 * Runs the real maybe_capture() against stub WordPress functions.
 *
 * Run:  php tests/test-signed-request-forwarding.php
 * Exits 0 on all-pass, 1 on any failure.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['bluerails_test_posts'] = array();

function is_admin() { return false; }
function wp_doing_cron() { return false; }
function wp_doing_ajax() { return false; }
function is_ssl() { return true; }
function add_action() {}
function wp_unslash( $value ) { return stripslashes( $value ); }
function esc_url_raw( $url ) { return $url; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function home_url( $path = '' ) { return 'https://www.hotel.example' . $path; }
function sanitize_text_field( $value ) {
	// Same lossy behaviour as WordPress for this test: tags and %XX octets are removed.
	return trim( preg_replace( '/%[a-f0-9]{2}/i', '', strip_tags( $value ) ) );
}
function get_option( $name, $default = '' ) {
	$options = array(
		'bluerails_agent_traffic_endpoint_url' => 'https://discovery.example/api/agent-traffic-ingest',
		'bluerails_agent_traffic_api_key'      => 'bak_test',
	);
	return isset( $options[ $name ] ) ? $options[ $name ] : $default;
}
function wp_remote_post( $url, $args ) {
	$GLOBALS['bluerails_test_posts'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
}

require __DIR__ . '/../includes/class-bluerails-bot-detector.php';

const CHROME_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const SIGNATURE = 'sig1=:AbCd+/ef==:';
const SIGNATURE_INPUT = 'sig1=("@authority" "signature-agent");created=1794414509;keyid="k1";alg="ed25519";expires=1794414569;nonce="n+/=";tag="web-bot-auth"';
const SIGNATURE_AGENT = '"https://chatgpt.com"';
const RAW_URI = '/zimmer/%C3%BCbersicht?lang=de&x=%2F';

/** Runs maybe_capture() with the given request headers (slashed the way WordPress slashes $_SERVER). */
function bluerails_capture( array $server ) {
	$GLOBALS['bluerails_test_posts'] = array();
	$_SERVER = array_map( 'addslashes', $server );
	$reflection = new ReflectionClass( 'Bluerails_Bot_Detector' );
	$reflection->newInstanceWithoutConstructor()->maybe_capture();
	return $GLOBALS['bluerails_test_posts'];
}

$signed_request = array(
	'HTTP_USER_AGENT'      => CHROME_UA,
	'HTTP_SIGNATURE'       => SIGNATURE,
	'HTTP_SIGNATURE_INPUT' => SIGNATURE_INPUT,
	'HTTP_SIGNATURE_AGENT' => SIGNATURE_AGENT,
	'HTTP_HOST'            => 'www.hotel.example',
	'REQUEST_METHOD'       => 'HEAD',
	'REQUEST_URI'          => RAW_URI,
);

$failures = 0;
function bluerails_check( $label, $passed ) {
	global $failures;
	if ( ! $passed ) {
		$failures++;
	}
	printf( "[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label );
}

$posts = bluerails_capture( $signed_request );
bluerails_check( 'signed request with a Chrome user agent produces exactly one POST', 1 === count( $posts ) );
$body = isset( $posts[0] ) ? $posts[0]['body'] : array();
bluerails_check( 'POST carries the Signature header as received', ( $body['signature'] ?? null ) === SIGNATURE );
bluerails_check( 'POST carries the Signature-Input header as received', ( $body['signature_input'] ?? null ) === SIGNATURE_INPUT );
bluerails_check( 'POST carries the quoted Signature-Agent header as received', ( $body['signature_agent'] ?? null ) === SIGNATURE_AGENT );
bluerails_check( 'POST carries the requested host', ( $body['request_host'] ?? null ) === 'www.hotel.example' );
bluerails_check( 'POST carries the scheme', ( $body['request_scheme'] ?? null ) === 'https' );
bluerails_check( 'POST carries the HTTP method', ( $body['request_method'] ?? null ) === 'HEAD' );
bluerails_check( 'POST carries the raw request URI, %XX intact', ( $body['request_uri'] ?? null ) === RAW_URI );
bluerails_check( 'matched_ua_string is the full browser user agent', ( $body['matched_ua_string'] ?? null ) === CHROME_UA );

$unsigned = $signed_request;
unset( $unsigned['HTTP_SIGNATURE_AGENT'] );
bluerails_check( 'a Chrome request missing one signature header sends nothing', 0 === count( bluerails_capture( $unsigned ) ) );

$plain = array( 'HTTP_USER_AGENT' => CHROME_UA, 'REQUEST_URI' => '/' );
bluerails_check( 'a plain Chrome request sends nothing', 0 === count( bluerails_capture( $plain ) ) );

$bot = array( 'HTTP_USER_AGENT' => 'GPTBot/1.0', 'REQUEST_URI' => '/rooms' );
$posts = bluerails_capture( $bot );
bluerails_check( 'a known bot user agent still sends one POST without signed fields', 1 === count( $posts ) && ! isset( $posts[0]['body']['signature'] ) );

$signed_markdown                = $signed_request;
$signed_markdown['HTTP_ACCEPT'] = 'text/markdown, text/html;q=0.9';
$posts                          = bluerails_capture( $signed_markdown );
bluerails_check(
	'a signed request that also prefers markdown sends one POST with both the signature and the Accept header',
	1 === count( $posts ) && isset( $posts[0]['body']['signature'] ) && 'text/markdown, text/html;q=0.9' === ( $posts[0]['body']['accept'] ?? null )
);
bluerails_check( 'a signed request without a markdown Accept header sends no accept field', ! isset( bluerails_capture( $signed_request )[0]['body']['accept'] ) );

$markdown_only = array( 'HTTP_USER_AGENT' => CHROME_UA, 'HTTP_ACCEPT' => 'text/markdown, text/html;q=0.9', 'REQUEST_URI' => '/' );
$posts         = bluerails_capture( $markdown_only );
bluerails_check( 'an unsigned markdown request still takes the markdown path', 1 === count( $posts ) && ! isset( $posts[0]['body']['signature'] ) && isset( $posts[0]['body']['accept'] ) );

printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
