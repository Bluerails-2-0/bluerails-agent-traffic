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
	'omgilibot/0.5 +http://omgili.com' => 'Omgilibot',
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
	'meta-externalagent/1.0'           => 'Meta-ExternalAgent',

	// AI assistants and agents, using the operator's published UA where one exists.
	'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko; compatible; Google-Agent; +https://developers.google.com/crawling/docs/crawlers-fetchers/google-agent) Chrome/131.0.0.0 Mobile Safari/537.36' => 'Google-Agent',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GoogleOther) Chrome/131.0.0.0 Safari/537.36' => 'GoogleOther',
	'GoogleOther-Image/1.0'            => 'GoogleOther',
	'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko; compatible; Google-GeminiNotebook; +https://developers.google.com/crawling/docs/crawlers-fetchers/google-gemininotebook) Chrome/137.0.0.0 Safari/537.36' => 'Google-GeminiNotebook',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amzn-User/0.1) Chrome/131.0.0.0 Safari/537.36' => 'Amzn-User',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amzn-SearchBot/0.1) Chrome/131.0.0.0 Safari/537.36' => 'Amzn-SearchBot',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amazonbot/0.1) Chrome/131.0.0.0 Safari/537.36' => 'Amazonbot',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; MistralAI-Index/1.0; +https://docs.mistral.ai/robots)' => 'MistralAI-Index',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; MistralAI-User/1.0; +https://docs.mistral.ai/robots)' => 'MistralAI-User',
	'Mozilla/5.0 (compatible; ExaUser/1.0; +https://exauser.crawler.exa.ai)' => 'ExaUser',
	'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Liner-User/1.0; +https://liner-user.liner.com/docs/liner-user)' => 'Liner-User',
	'grok-agent/1.0'                   => 'grok-agent',
	'kagi-fetcher/1.0'                 => 'kagi-fetcher',
	'Kagibot/1.0'                      => 'Kagibot',
	'TavilyBot/1.0'                    => 'TavilyBot',
	'AzureAI-SearchBot/1.0'            => 'AzureAI-SearchBot',
	'Mozilla/5.0 (compatible; Gemini-Deep-Research)' => 'Gemini-Deep-Research',
	'cohere-ai/1.0'                    => 'cohere-ai',
	'Mozilla/5.0 (compatible; AI2Bot/1.0)' => 'AI2Bot',
	'Mozilla/5.0 (compatible; Ai2Bot-Dolma/1.0)' => 'AI2Bot',
	'Mozilla/5.0 (compatible; Kimi-User/1.0)' => 'Kimi-User',

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

// The plugin sends the matched token (the value) and the backend matches it
// case-sensitively, so each value must equal its name, spelled as in the registry.
foreach ( Bluerails_Bot_Detector::BOT_SIGNATURES as $bot_name => $substring ) {
	if ( $bot_name !== $substring ) {
		$failures++;
		printf( "[FAIL] signature name %s differs from its token %s\n", $bot_name, $substring );
	}
}

printf( "\n%d case(s), %d failure(s)\n", count( $cases ), $failures );
exit( $failures > 0 ? 1 : 0 );
