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

	const OPTION_GROUP    = 'bluerails_agent_traffic_options';
	const PAGE_SLUG       = 'bluerails-agent-traffic';
	const OPT_ENDPOINT    = 'bluerails_agent_traffic_endpoint_url';
	const OPT_API_KEY     = 'bluerails_agent_traffic_api_key';
	const OPT_HAS_CDN     = 'bluerails_agent_traffic_has_cdn';

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
	}

	public function sanitize_yes_no( $value ) {
		return ( '1' === $value ) ? '1' : '';
	}

	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Paste the endpoint URL and API key generated in your Bluerails dashboard (Settings → Agent Traffic).', 'bluerails-agent-traffic' ) . '</p>';
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
