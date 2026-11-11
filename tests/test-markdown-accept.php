<?php
/**
 * Markdown Accept-header path of Bluerails_Bot_Detector::maybe_capture().
 *
 * Plain PHP with minimal WordPress stubs. Calls the real maybe_capture() and
 * records what report_hit() would POST instead of sending it.
 *
 * Run:  php tests/test-markdown-accept.php
 * Exits 0 on all-pass, 1 on any failure.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['bluerails_test_posts'] = array();

function is_admin() { return false; }
function wp_doing_cron() { return false; }
function wp_doing_ajax() { return false; }
function wp_unslash( $value ) { return stripslashes( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function esc_url_raw( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_option( $name, $default = '' ) {
	$options = array(
		'bluerails_agent_traffic_endpoint_url' => 'https://ingest.example/api/agent-traffic-ingest',
		'bluerails_agent_traffic_api_key'      => 'bak_test',
	);
	return $options[ $name ] ?? $default;
}
function home_url( $path = '' ) { return 'https://hotel.example' . $path; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_remote_post( $url, $args ) {
	$GLOBALS['bluerails_test_posts'][] = json_decode( $args['body'], true );
}

require __DIR__ . '/../includes/class-bluerails-bot-detector.php';

const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

function bluerails_test_capture( $user_agent, $accept, $referer = '' ) {
	$GLOBALS['bluerails_test_posts'] = array();
	$_SERVER = array(
		'HTTP_USER_AGENT' => $user_agent,
		'REQUEST_URI'     => '/rooms',
	);
	if ( null !== $accept ) {
		$_SERVER['HTTP_ACCEPT'] = $accept;
	}
	if ( '' !== $referer ) {
		$_SERVER['HTTP_REFERER'] = $referer;
	}
	$reflection = new ReflectionClass( 'Bluerails_Bot_Detector' );
	$reflection->newInstanceWithoutConstructor()->maybe_capture();
	return $GLOBALS['bluerails_test_posts'];
}

$failures = 0;
function bluerails_check( $label, $passed ) {
	global $failures;
	if ( ! $passed ) {
		$failures++;
	}
	printf( "[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label );
}

$posts = bluerails_test_capture( CHROME_UA, 'text/markdown, text/html, */*' );
bluerails_check( 'browser UA asking for markdown sends one hit', 1 === count( $posts ) );
bluerails_check( '... with the raw Accept header', ( $posts[0]['accept'] ?? null ) === 'text/markdown, text/html, */*' );
bluerails_check( '... with no bot name and the full UA', '' === ( $posts[0]['bot_name'] ?? null ) && CHROME_UA === ( $posts[0]['matched_ua_string'] ?? null ) );
bluerails_check( '... and no IP field', ! array_key_exists( 'ip', $posts[0] ?? array() ) );

// The plugin forwards; ranking against HTML is the backend's decision.
$posts = bluerails_test_capture( CHROME_UA, 'text/html, text/x-markdown;q=0.5' );
bluerails_check( 'text/x-markdown anywhere in Accept is forwarded', 1 === count( $posts ) );

$posts = bluerails_test_capture( CHROME_UA, 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' );
bluerails_check( 'ordinary browser Accept sends nothing', 0 === count( $posts ) );

$posts = bluerails_test_capture( CHROME_UA, null );
bluerails_check( 'no Accept header sends nothing', 0 === count( $posts ) );

$posts = bluerails_test_capture( CHROME_UA, 'text/markdown, ' . str_repeat( 'x', 1024 ) );
bluerails_check( 'Accept longer than the backend limit sends nothing', 0 === count( $posts ) );

$posts = bluerails_test_capture( 'Mozilla/5.0 (compatible; ExaUser/1.0; +https://exauser.crawler.exa.ai)', 'text/markdown' );
bluerails_check( 'a known bot asking for markdown is sent as that bot', 1 === count( $posts ) && 'ExaUser' === $posts[0]['bot_name'] && ! array_key_exists( 'accept', $posts[0] ) );

$posts = bluerails_test_capture( CHROME_UA, 'text/markdown', 'https://chatgpt.com/c/abc' );
bluerails_check( 'an AI referrer is sent once, as a referrer hit', 1 === count( $posts ) && ! array_key_exists( 'accept', $posts[0] ) );

printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
