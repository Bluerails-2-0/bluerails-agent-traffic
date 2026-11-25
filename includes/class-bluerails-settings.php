<?php
/**
 * Admin settings screen: endpoint URL, API key, and the CDN yes/no question.
 *
 * @package Bluerails_Agent_Traffic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bluerails_Settings {

	const OPTION_GROUP      = 'bluerails_agent_traffic_options';
	const PAGE_SLUG         = 'bluerails-agent-traffic';
	const OPT_ENDPOINT      = 'bluerails_agent_traffic_endpoint_url';
	const OPT_API_KEY       = 'bluerails_agent_traffic_api_key';
	const OPT_HAS_CDN       = 'bluerails_agent_traffic_has_cdn';
	const OPT_BEHAVIORAL    = 'bluerails_agent_traffic_behavioral_enabled';
	const OPT_DOOR_LISTING  = 'bluerails_agent_door_listing_id';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_cdn_notice' ) );
	}

	public function add_settings_page() {
		add_options_page(
			__( 'Bluerails Agent Traffic', 'bluerails-agent-traffic' ),
			__( 'Bluerails Agent Traffic', 'bluerails-agent-traffic' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( self::OPTION_GROUP, self::OPT_ENDPOINT, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => 'https://discovery.bluerails.com/api/agent-traffic-ingest',
		) );
		register_setting( self::OPTION_GROUP, self::OPT_API_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( self::OPTION_GROUP, self::OPT_HAS_CDN, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_yes_no' ),
			'default'           => '',
		) );
		register_setting( self::OPTION_GROUP, self::OPT_BEHAVIORAL, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_yes_no' ),
			'default'           => '',
		) );

		add_settings_section(
			'bluerails_main_section',
			__( 'Connection', 'bluerails-agent-traffic' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPT_ENDPOINT,
			__( 'Ingest Endpoint URL', 'bluerails-agent-traffic' ),
			array( $this, 'render_endpoint_field' ),
			self::PAGE_SLUG,
			'bluerails_main_section'
		);

		add_settings_field(
			self::OPT_API_KEY,
			__( 'API Key', 'bluerails-agent-traffic' ),
			array( $this, 'render_api_key_field' ),
			self::PAGE_SLUG,
			'bluerails_main_section'
		);

		add_settings_field(
			self::OPT_HAS_CDN,
			__( 'CDN in front of WordPress?', 'bluerails-agent-traffic' ),
			array( $this, 'render_cdn_field' ),
			self::PAGE_SLUG,
			'bluerails_main_section'
		);

		register_setting( self::OPTION_GROUP, self::OPT_DOOR_LISTING, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_door_listing_id' ),
			'default'           => '',
		) );

		add_settings_section(
			'bluerails_door_section',
			__( 'Agent door (poppy.json)', 'bluerails-agent-traffic' ),
			array( $this, 'render_door_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPT_DOOR_LISTING,
			__( 'Bluerails listing ID', 'bluerails-agent-traffic' ),
			array( $this, 'render_door_listing_field' ),
			self::PAGE_SLUG,
			'bluerails_door_section'
		);

		add_settings_section(
			'bluerails_behavioral_section',
			__( 'Behavioral signal (beta)', 'bluerails-agent-traffic' ),
			array( $this, 'render_behavioral_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPT_BEHAVIORAL,
			__( 'Enable behavioral signal', 'bluerails-agent-traffic' ),
			array( $this, 'render_behavioral_field' ),
			self::PAGE_SLUG,
			'bluerails_behavioral_section'
		);
	}

	public function sanitize_yes_no( $value ) {
		return ( '1' === $value ) ? '1' : '';
	}

	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Paste the endpoint URL and API key generated in your Bluerails dashboard (Settings → Agent Traffic).', 'bluerails-agent-traffic' ) . '</p>';
		echo '<p>' . esc_html__( 'No data leaves this site until both fields below are filled in: the plugin checks for a non-empty endpoint URL and API key before every send, and skips the request entirely if either is missing.', 'bluerails-agent-traffic' ) . '</p>';
		echo '<p>' . esc_html__( 'Every reported hit also includes the raw Referer header. On a request whose User-Agent matches no known bot signature, the plugin also reports a visitor who arrived from an AI assistant (ChatGPT, Perplexity, Claude, Gemini or Copilot), recognised by the Referer header or the landing URL\'s utm_source. Bluerails counts those visits as a number per day, assistant and page folder, and does not store the visitor\'s User-Agent, Referer or page address.', 'bluerails-agent-traffic' ) . '</p>';
	}

	public function render_endpoint_field() {
		$value = get_option( self::OPT_ENDPOINT, '' );
		printf(
			'<input type="url" class="regular-text code" name="%1$s" value="%2$s" placeholder="https://discovery.bluerails.com/api/agent-traffic-ingest" />',
			esc_attr( self::OPT_ENDPOINT ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Provided by Bluerails. This is a setting, not hardcoded, so it can change without a plugin update.', 'bluerails-agent-traffic' ) . '</p>';
	}

	public function render_api_key_field() {
		$value = get_option( self::OPT_API_KEY, '' );
		printf(
			'<input type="password" class="regular-text code" name="%1$s" value="%2$s" autocomplete="off" />',
			esc_attr( self::OPT_API_KEY ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Generated in your Bluerails dashboard. Sent as a Bearer token with every request; never displayed elsewhere on this site.', 'bluerails-agent-traffic' ) . '</p>';
	}

	public function render_cdn_field() {
		$value = get_option( self::OPT_HAS_CDN, '' );
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPT_HAS_CDN ),
			checked( '1', $value, false ),
			esc_html__( 'Yes, this site sits behind a CDN (Cloudflare, CloudFront, etc.)', 'bluerails-agent-traffic' )
		);
		echo '<p class="description">' . esc_html__( 'If a CDN serves cached pages directly from its edge, bot hits on those cached pages never reach WordPress and this plugin cannot see them. Answering "Yes" only turns on the reminder notice below — it does not disable capture.', 'bluerails-agent-traffic' ) . '</p>';
	}

	public function sanitize_door_listing_id( $value ) {
		$clean = Bluerails_Agent_Door::sanitize_listing_id( $value );
		if ( '' === $clean && '' !== trim( (string) $value ) ) {
			add_settings_error( self::OPT_DOOR_LISTING, 'invalid_listing_id', __( 'The Bluerails listing ID must be a UUID such as 766cb53a-6677-4963-afdb-b2c72d180c1b. The agent door stays off.', 'bluerails-agent-traffic' ) );
		}
		return $clean;
	}

	public function render_door_section_intro() {
		echo '<p>' . esc_html__( 'With a listing ID from Bluerails, this site serves /.well-known/poppy.json and /.well-known/poppy-0.4.json, which tell AI agents where to get this hotel\'s live prices and booking: a Bluerails server that answers for this hotel only. The documents come from Bluerails and are refreshed every five minutes; when Bluerails switches the door off, both answer 404. Every fetch of them is reported to your Agent Traffic dashboard, whatever the user agent. Leave empty to serve nothing.', 'bluerails-agent-traffic' ) . '</p>';
		echo '<p>' . esc_html__( 'Some hosts answer /.well-known/*.json themselves without asking WordPress. Open the two addresses below after saving: if you see your host\'s error page instead of JSON, the "Agent door" section of the plugin\'s readme.txt has the one-line Apache or nginx rule that hands these paths to WordPress.', 'bluerails-agent-traffic' ) . '</p>';
	}

	public function render_door_listing_field() {
		$value = get_option( self::OPT_DOOR_LISTING, '' );
		printf(
			'<input type="text" class="regular-text code" name="%1$s" value="%2$s" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" />',
			esc_attr( self::OPT_DOOR_LISTING ),
			esc_attr( $value )
		);
		if ( '' !== $value ) {
			foreach ( Bluerails_Bot_Detector::DOOR_DOCUMENTS as $document ) {
				$url = home_url( '/.well-known/' . $document );
				printf( '<br /><a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $url ), esc_html( $url ) );
			}
		}
		echo '<p class="description">' . esc_html__( 'Provided by Bluerails. The document is only served if it names this site\'s own host.', 'bluerails-agent-traffic' ) . '</p>';
	}

	public function render_behavioral_section_intro() {
		echo '<p>' . esc_html__( 'BLUE-1474: an additional, OFF-by-default signal that runs a small JS snippet in visitors\' browsers to help identify rendered-browser AI agents (browser-extension agentic sessions such as ChatGPT\'s Chrome extension/Work app or Anthropic\'s Claude for Chrome) that present as an ordinary Chrome browser with no distinguishing User-Agent or Referer.', 'bluerails-agent-traffic' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Requires Complianz.', 'bluerails-agent-traffic' ) . '</strong> ' . esc_html__( 'The beacon only ever runs after the site\'s own Complianz cookie-consent plugin reports the visitor granted "statistics" consent. If this site does not run Complianz, enabling this setting has no effect — the beacon never runs and nothing is sent. Support for other consent-management plugins (CookieYes, Borlabs, etc.) is not implemented yet.', 'bluerails-agent-traffic' ) . '</p>';
		echo '<p>' . esc_html__( 'When it does run, the beacon observes mouse-movement timing on a session and sends a SCORE, never a hard bot/human verdict, as its own labeled row in your Agent Traffic dashboard, separate from AI-bot and referrer-based rows. Sessions with no fine pointer (mobile/touch) are never scored on pointer-absence alone.', 'bluerails-agent-traffic' ) . '</p>';
	}

	public function render_behavioral_field() {
		$value = get_option( self::OPT_BEHAVIORAL, '' );
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPT_BEHAVIORAL ),
			checked( '1', $value, false ),
			esc_html__( 'Yes, enable the behavioral signal beacon on this site (requires Complianz for visitor consent — see above)', 'bluerails-agent-traffic' )
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bluerails Agent Traffic Capture', 'bluerails-agent-traffic' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<h2><?php echo esc_html__( 'Full-page cache: exclude AI bots', 'bluerails-agent-traffic' ); ?></h2>
			<p>
				<?php
				echo wp_kses_post( __(
					'If this site uses a full-page-cache plugin, cached hits never re-invoke PHP, so this plugin never sees them. If you use <strong>WP Rocket</strong>, add the bot names below under Settings → WP Rocket → Advanced Rules → "Never Cache User Agent(s)" so bot requests always run PHP:',
					'bluerails-agent-traffic'
				) );
				?>
			</p>
			<p><code><?php echo esc_html( implode( '|', Bluerails_Bot_Detector::get_bot_signatures_for_display() ) ); ?></code></p>
			<p class="description">
				<?php echo esc_html__( 'Using a different cache plugin or host-level caching? Look for an equivalent "exclude user agent" or "bypass cache" rule.', 'bluerails-agent-traffic' ); ?>
			</p>

			<h2><?php echo esc_html__( 'CDNs (Cloudflare, CloudFront, etc.)', 'bluerails-agent-traffic' ); ?></h2>
			<p>
				<?php echo esc_html__( 'A CDN sitting in front of WordPress can serve a cached page straight from its own edge, without the request ever reaching WordPress. This plugin only runs inside WordPress, so those edge-cached bot hits are invisible to it — there is no WordPress-level fix for that. Tell us above if a CDN fronts this site so the dashboard can flag that crawl data may be incomplete.', 'bluerails-agent-traffic' ); ?>
			</p>
			<p>
				<?php echo wp_kses_post( __(
					'This plugin is not the only capture path, though: on the paid Discovery tier, Bluerails also supports connecting your CDN\'s own logs directly (e.g. Cloudflare Logpush) via <strong>Dashboard → Agent Traffic → Connect Logs</strong>. That path sees edge-cached hits this plugin structurally cannot. The two are complementary, not either/or — connect your CDN logs there if you have a CDN, and this plugin still covers whatever isn\'t cached.',
					'bluerails-agent-traffic'
				) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Persistent (not dismissible) admin notice when the site owner has told
	 * us a CDN fronts this site, so under-reporting is explained rather than
	 * silent. Shown on every wp-admin screen, not just the settings page.
	 */
	public function maybe_show_cdn_notice() {
		if ( '1' !== get_option( self::OPT_HAS_CDN, '' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' .
			wp_kses_post( __(
				'Bluerails Agent Traffic Capture: this site is behind a CDN, so AI-bot hits served from the CDN\'s edge cache never reach WordPress and will be missing from your crawl data. This is expected and does not indicate a plugin malfunction. On the paid Discovery tier, connect your CDN\'s own logs (e.g. Cloudflare Logpush) at <strong>Dashboard → Agent Traffic → Connect Logs</strong> to cover what this plugin structurally cannot.',
				'bluerails-agent-traffic'
			) ) .
			'</p></div>';
	}
}
