<?php
/**
 * Admin-side actions: reindex, API test, usage dashboard.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the maintenance buttons on the settings screen.
 */
class EduAI_Admin {

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_menu', array( __CLASS__, 'usage_page' ) );
	}

	/**
	 * Process ?eduai_action= links from the settings page.
	 */
	public static function handle_actions(): void {
		if ( ! isset( $_GET['eduai_action'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['eduai_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'reindex' === $action ) {
			check_admin_referer( 'eduai_reindex' );

			$result = EduAI_Knowledge::reindex_all();

			set_transient( 'eduai_admin_notice', array(
				'type' => 'success',
				'text' => sprintf(
					/* translators: 1: chunk count 2: document count */
					__( 'Index rebuilt: %1$s passages from %2$s documents.', 'eduai' ),
					number_format_i18n( $result['chunks'] ),
					number_format_i18n( $result['docs'] )
				),
			), 60 );

			wp_safe_redirect( admin_url( 'options-general.php?page=eduai' ) );
			exit;
		}

		if ( 'test' === $action ) {
			check_admin_referer( 'eduai_test' );

			$ping = EduAI_Claude::ping();

			set_transient( 'eduai_admin_notice', array(
				'type' => is_wp_error( $ping ) ? 'error' : 'success',
				'text' => is_wp_error( $ping )
					? $ping->get_error_message()
					: __( 'Connected to the Claude API successfully.', 'eduai' ),
			), 60 );

			wp_safe_redirect( admin_url( 'options-general.php?page=eduai' ) );
			exit;
		}
	}

	/**
	 * Print any queued notice.
	 */
	public static function notice(): void {
		$notice = get_transient( 'eduai_admin_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'eduai_admin_notice' );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
			esc_html( $notice['text'] )
		);
	}

	/**
	 * Usage sub-page under Tools.
	 */
	public static function usage_page(): void {
		add_management_page(
			__( 'EduAI Usage', 'eduai' ),
			__( 'EduAI Usage', 'eduai' ),
			'manage_options',
			'eduai-usage',
			array( __CLASS__, 'render_usage' )
		);
	}

	public static function render_usage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$days    = 30;
		$summary = EduAI_Conversation::usage_summary( $days );
		$index   = EduAI_Knowledge::stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EduAI Usage', 'eduai' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of days */
					esc_html__( 'Activity over the last %d days.', 'eduai' ),
					(int) $days
				);
				?>
			</p>

			<div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-top:20px">
				<?php
				$tiles = array(
					array( __( 'Messages', 'eduai' ), number_format_i18n( $summary['messages'] ) ),
					array( __( 'Active students', 'eduai' ), number_format_i18n( $summary['students'] ) ),
					array( __( 'Input tokens', 'eduai' ), number_format_i18n( $summary['tokens_in'] ) ),
					array( __( 'Output tokens', 'eduai' ), number_format_i18n( $summary['tokens_out'] ) ),
					array( __( 'Indexed passages', 'eduai' ), number_format_i18n( $index['chunks'] ) ),
					array( __( 'Indexed documents', 'eduai' ), number_format_i18n( $index['docs'] ) ),
				);

				foreach ( $tiles as $tile ) :
					?>
					<div class="card" style="padding:16px;margin:0">
						<p style="margin:0;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.04em">
							<?php echo esc_html( $tile[0] ); ?>
						</p>
						<p style="margin:4px 0 0;font-size:28px;font-weight:600;line-height:1.1">
							<?php echo esc_html( $tile[1] ); ?>
						</p>
					</div>
				<?php endforeach; ?>
			</div>

			<p style="margin-top:24px">
				<?php esc_html_e( 'Token counts are what the Claude API reported. Multiply by your per-million rate to estimate spend.', 'eduai' ); ?>
			</p>
		</div>
		<?php
	}
}

add_action( 'admin_notices', array( 'EduAI_Admin', 'notice' ) );
