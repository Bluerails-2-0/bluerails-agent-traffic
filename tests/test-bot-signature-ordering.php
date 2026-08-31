<?php
/**
 * BLUE-1527: substring-match regression test for Bluerails_Bot_Detector.
 *
 * Plain PHP, no PHPUnit/Composer -- this repo has no test harness, and
 * standing one up is a new dependency this ticket's scope excludes. Invokes
 * the real private match_bot() via reflection, not a reimplementation, so a
 * future edit to that method is covered here too.
 *
 * Run:  php tests/test-bot-signature-ordering.php
 * Exits 0 on all-pass, 1 on any failure (CI-friendly).
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // satisfy the class file's WP guard clause.
}

require __DIR__ . '/../includes/class-bluerails-bot-detector.php';

function bluerails_test_match_bot( $user_agent ) {
	$reflection = new ReflectionClass( 'Bluerails_Bot_Detector' );
	$instance   = $reflection->newInstanceWithoutConstructor(); // skip add_action(), no WP loaded.
	$method     = $reflection->getMethod( 'match_bot' );
	$result = $method->invoke( $instance, $user_agent );
	return null === $result ? null : $result['bot_name'];
}

$cases = array(
	// The 3 known substring-collision pairs (BLUE-1527 ticket review MUST-FIX) --
	// each longer/more-specific token must win over its shorter substring sibling.
	'Diffbot-User/1.0'                 => 'Diffbot-User',
	'Diffbot/1.0'                      => 'Diffbot',
	'omgilibot/0.5 +http://omgili.com' => 'omgilibot',
	'omgili/0.5 +http://omgili.com'    => 'omgili',
	'webzio-extended/1.0'              => 'webzio-extended',
	'webzio/1.0'                       => 'webzio',

	// One UA per other newly-added token, confirming each matches its own name.
	'OAI-SearchBot/1.0'                => 'OAI-SearchBot',
	'ChatGPT-User/1.0'                 => 'ChatGPT-User',
	'Claude-User/1.0'                  => 'Claude-User',
	'Claude-SearchBot/1.0'             => 'Claude-SearchBot',
	'Perplexity-User/1.0'              => 'Perplexity-User',
	'Google-CloudVertexBot/1.0'        => 'Google-CloudVertexBot',
	'Meta-ExternalFetcher/1.0'         => 'Meta-ExternalFetcher',
	'DuckAssistBot/1.0'                => 'DuckAssistBot',
	'MistralAI-User/1.0'               => 'MistralAI-User',
	'Kagibot/1.0'                      => 'Kagibot',
	'Bravebot/1.0'                     => 'Bravebot',
	'YouBot/1.0'                       => 'YouBot',
	'YiyanBot/1.0'                     => 'YiyanBot',
	'YandexAdditionalBot/1.0'          => 'YandexAdditionalBot',
	'Doubaobot/1.0'                    => 'Doubaobot',
	'QwenBot/1.0'                      => 'QwenBot',
	'TongyiBot/1.0'                    => 'TongyiBot',
	'Timpibot/1.0'                     => 'Timpibot',
	'ImagesiftBot/1.0'                 => 'ImagesiftBot',
	'Andibot/1.0'                      => 'Andibot',

	// Pre-existing tokens must still match after the append (no regression).
	'GPTBot/1.0'                       => 'GPTBot',
	'meta-externalagent/1.0'           => 'meta-externalagent',

	// An ordinary browser UA must never match anything.
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' => null,
);

$failures = 0;
foreach ( $cases as $ua => $expected ) {
	$got    = bluerails_test_match_bot( $ua );
	$passed = ( $got === $expected );
	if ( ! $passed ) {
		$failures++;
	}
	printf(
		"[%s] %-90s expected=%-24s got=%s\n",
		$passed ? 'PASS' : 'FAIL',
		$ua,
		$expected ?? 'null',
		$got ?? 'null'
	);
}

printf( "\n%d case(s), %d failure(s)\n", count( $cases ), $failures );
exit( $failures > 0 ? 1 : 0 );
