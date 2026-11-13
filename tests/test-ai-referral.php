<?php
/**
 * Visitors sent by an AI assistant: Bluerails_Bot_Detector::maybe_capture()
 * reports a page landing whose referrer host or utm_source names an assistant.
 *
 * Plain PHP with minimal WordPress stubs. Calls the real maybe_capture() and
 * records what report_hit() would POST instead of sending it.
 *
 * Run:  php tests/test-ai-referral.php
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

function bluerails_test_capture( $user_agent, $request_uri, $referer = '' ) {
	$GLOBALS['bluerails_test_posts'] = array();
	$_SERVER = array(
		'HTTP_USER_AGENT' => $user_agent,
		'REQUEST_URI'     => $request_uri,
	);
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

$posts = bluerails_test_capture( CHROME_UA, '/rooms', 'https://chatgpt.com/c/abc' );
bluerails_check( 'referrer only: one hit', 1 === count( $posts ) && '' === $posts[0]['bot_name'] );

$posts = bluerails_test_capture( CHROME_UA, '/rooms?utm_source=chatgpt.com' );
bluerails_check( 'utm_source only: one hit, with the query in page_path', 1 === count( $posts ) && '/rooms?utm_source=chatgpt.com' === $posts[0]['page_path'] );

$posts = bluerails_test_capture( CHROME_UA, '/rooms?utm_source=chatgpt.com', 'https://chatgpt.com/c/abc' );
bluerails_check( 'referrer and utm_source: still one hit', 1 === count( $posts ) );

$utm_cases = array(
	'/?utm_source=ChatGPT.COM'                    => 1,
	'/?utm_source=%20perplexity%20'               => 1,
	'/?a=1&utm_source=copilot.microsoft.com'      => 1,
	'/?utm_source=gemini&utm_medium=referral'     => 1,
	'/?utm_source=claude.ai'                      => 1,
	'/?utm_source=google'                         => 0,
	'/?utm_source=newsletter'                     => 0,
	'/?utm_source='                               => 0,
	'/?utm_source[]=chatgpt.com'                  => 0,
	'/?ref=utm_source%3Dchatgpt.com'              => 0,
	'/rooms'                                      => 0,
);
foreach ( $utm_cases as $uri => $expected ) {
	$posts = bluerails_test_capture( CHROME_UA, $uri );
	bluerails_check( sprintf( '%s sends %d hit(s)', $uri, $expected ), $expected === count( $posts ) );
}

$referer_cases = array(
	'https://copilot.microsoft.com/chats/1'  => 1,
	'https://chat.chatgpt.com/'              => 1,
	'https://www.bing.com/search?q=hotel'    => 0,
	'https://www.google.com/search?q=hotel'  => 0,
	'https://www.microsoft.com/'             => 0,
	'https://evil.example/?u=chatgpt.com'    => 0,
);
foreach ( $referer_cases as $referer => $expected ) {
	$posts = bluerails_test_capture( CHROME_UA, '/', $referer );
	bluerails_check( sprintf( 'referrer %s sends %d hit(s)', $referer, $expected ), $expected === count( $posts ) );
}

$posts = bluerails_test_capture( 'Mozilla/5.0 (compatible; GPTBot/1.2)', '/?utm_source=chatgpt.com', 'https://chatgpt.com/' );
bluerails_check( 'a known bot from an assistant is sent as that bot', 1 === count( $posts ) && 'GPTBot' === $posts[0]['bot_name'] );

printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
