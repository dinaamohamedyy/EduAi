<?php
/**
 * The settings screen, and the honesty panel above it.
 *
 * The most useful thing this page does is not the form. It is the coverage
 * table: how many courses actually carry a description, a price, a duration, a
 * schedule. A bot that answers "the fee is not listed" four times looks broken,
 * and an administrator who cannot see why will report it as a bug in the bot.
 * The cause is empty fields, and this says so before anybody files anything.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class EduAI_Enquiry_Admin {

	private const OPTION = 'eduai_enquiry_settings';

	/**
	 * Hook up the screen.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu(): void {
		add_options_page(
			__( 'EduAI Enquiry', 'eduai-enquiry' ),
			__( 'EduAI Enquiry', 'eduai-enquiry' ),
			'manage_options',
			'eduai-enquiry',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings registration.
	 */
	public static function register(): void {
		register_setting(
			'eduai_enquiry',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	/**
	 * Defaults.
	 */
	public static function defaults(): array {
		return array(
			'enabled'        => 1,
			'language'       => 'en',
			'tier'           => 'balanced',
			'accent'         => '#0F5F66',
			'position'       => 'end',
			'source'         => '',
			'api_key'        => '',
			'endpoint'       => 'https://api.groq.com/openai/v1/chat/completions',
			'model'          => 'openai/gpt-oss-20b',
			'crm_webhook'    => '',
			'crm_secret'     => '',
			'retention_days' => 365,
		);
	}

	/**
	 * Clean submitted values.
	 */
	public static function sanitize( $input ): array {
		$d   = self::defaults();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$out['enabled']  = empty( $in['enabled'] ) ? 0 : 1;
		$out['language'] = in_array( $in['language'] ?? '', array( 'en', 'ar' ), true ) ? $in['language'] : $d['language'];
		$out['tier']     = in_array( $in['tier'] ?? '', array( 'strongest', 'balanced', 'fast' ), true ) ? $in['tier'] : $d['tier'];
		$out['position'] = 'start' === ( $in['position'] ?? '' ) ? 'start' : 'end';
		$out['source']   = in_array( $in['source'] ?? '', array( '', 'learndash', 'woo', 'generic' ), true ) ? $in['source'] : '';

		$accent          = sanitize_hex_color( (string) ( $in['accent'] ?? '' ) );
		$out['accent']   = $accent ? $accent : $d['accent'];

		$out['api_key']  = trim( wp_strip_all_tags( (string) ( $in['api_key'] ?? '' ) ) );
		$out['endpoint'] = esc_url_raw( (string) ( $in['endpoint'] ?? $d['endpoint'] ) );
		$out['model']    = trim( wp_strip_all_tags( (string) ( $in['model'] ?? $d['model'] ) ) );

		$out['crm_webhook'] = esc_url_raw( (string) ( $in['crm_webhook'] ?? '' ) );
		$out['crm_secret']  = trim( wp_strip_all_tags( (string) ( $in['crm_secret'] ?? '' ) ) );

		$out['retention_days'] = max( 0, min( 3650, (int) ( $in['retention_days'] ?? $d['retention_days'] ) ) );

		return $out;
	}

	/**
	 * The page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s        = array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
		$coverage = EduAI_Enquiry_Catalog::coverage();
		$route    = EduAI_Enquiry_Model::route();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EduAI Enquiry', 'eduai-enquiry' ); ?></h1>

			<div class="notice notice-<?php echo 'none' === $route ? 'error' : 'info'; ?> inline" style="margin:16px 0;padding:10px 12px">
				<p style="margin:0">
					<strong><?php esc_html_e( 'Model:', 'eduai-enquiry' ); ?></strong>
					<?php
					if ( 'eduai-assistant' === $route ) {
						esc_html_e( 'using the EduAI Assistant plugin’s provider and key. Nothing to configure here.', 'eduai-enquiry' );
					} elseif ( 'direct' === $route ) {
						esc_html_e( 'using this plugin’s own key.', 'eduai-enquiry' );
					} else {
						esc_html_e( 'no key found. The assistant still answers from your catalogue, but cannot write recommendations or understand unusual phrasing.', 'eduai-enquiry' );
					}
					?>
				</p>
			</div>

			<?php if ( ! empty( $coverage['total'] ) ) : ?>
				<h2 style="margin-top:24px"><?php esc_html_e( 'What the assistant can honestly say', 'eduai-enquiry' ); ?></h2>
				<p class="description" style="max-width:70ch">
					<?php esc_html_e( 'The assistant will never invent a price, a date or a duration. Where a field is empty it says so. Any row below that is not full is a question it will have to decline to answer.', 'eduai-enquiry' ); ?>
				</p>
				<table class="widefat striped" style="max-width:640px;margin:12px 0 24px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Field', 'eduai-enquiry' ); ?></th>
							<th><?php esc_html_e( 'Courses with it', 'eduai-enquiry' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$labels = array(
						'description' => __( 'Description', 'eduai-enquiry' ),
						'duration'    => __( 'Duration', 'eduai-enquiry' ),
						'format'      => __( 'Format', 'eduai-enquiry' ),
						'price'       => __( 'Price', 'eduai-enquiry' ),
						'schedule'    => __( 'Schedule', 'eduai-enquiry' ),
						'categories'  => __( 'Categories', 'eduai-enquiry' ),
					);

					foreach ( $labels as $key => $label ) {
						$have  = (int) ( $coverage[ $key ] ?? 0 );
						$total = (int) $coverage['total'];
						printf(
							'<tr><td>%s</td><td><strong style="color:%s">%d of %d</strong></td></tr>',
							esc_html( $label ),
							$have === $total ? '#14634A' : ( 0 === $have ? '#A81F1A' : '#8A5A00' ),
							$have,
							$total
						);
					}
					?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'eduai_enquiry' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show on the site', 'eduai-enquiry' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?>> <?php esc_html_e( 'Display the chat widget to visitors', 'eduai-enquiry' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="cc-language"><?php esc_html_e( 'Opening language', 'eduai-enquiry' ); ?></label></th>
						<td>
							<select id="cc-language" name="<?php echo esc_attr( self::OPTION ); ?>[language]">
								<option value="en" <?php selected( $s['language'], 'en' ); ?>>English</option>
								<option value="ar" <?php selected( $s['language'], 'ar' ); ?>>العربية</option>
							</select>
							<p class="description"><?php esc_html_e( 'Visitors can switch at any time, and the assistant follows whichever language they type in.', 'eduai-enquiry' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cc-tier"><?php esc_html_e( 'Model tier', 'eduai-enquiry' ); ?></label></th>
						<td>
							<select id="cc-tier" name="<?php echo esc_attr( self::OPTION ); ?>[tier]">
								<option value="strongest" <?php selected( $s['tier'], 'strongest' ); ?>><?php esc_html_e( 'Strongest', 'eduai-enquiry' ); ?></option>
								<option value="balanced" <?php selected( $s['tier'], 'balanced' ); ?>><?php esc_html_e( 'Balanced (recommended)', 'eduai-enquiry' ); ?></option>
								<option value="fast" <?php selected( $s['tier'], 'fast' ); ?>><?php esc_html_e( 'Fastest', 'eduai-enquiry' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Only recommendations and unusual questions use the model at all. Balanced keeps replies inside two seconds.', 'eduai-enquiry' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cc-accent"><?php esc_html_e( 'Accent colour', 'eduai-enquiry' ); ?></label></th>
						<td><input id="cc-accent" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="cc-crm"><?php esc_html_e( 'CRM webhook', 'eduai-enquiry' ); ?></label></th>
						<td>
							<input id="cc-crm" type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[crm_webhook]" value="<?php echo esc_attr( $s['crm_webhook'] ); ?>" placeholder="https://">
							<p class="description"><?php esc_html_e( 'Enquiries are saved here first and sent second, with retries, so nothing is lost if the CRM is down. Leave blank to collect them on this site only.', 'eduai-enquiry' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cc-secret"><?php esc_html_e( 'Webhook signing secret', 'eduai-enquiry' ); ?></label></th>
						<td>
							<input id="cc-secret" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[crm_secret]" value="<?php echo esc_attr( $s['crm_secret'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Sends an X-EduAI-Signature header so the receiver can verify the request came from you.', 'eduai-enquiry' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cc-retention"><?php esc_html_e( 'Keep enquiries for', 'eduai-enquiry' ); ?></label></th>
						<td>
							<input id="cc-retention" type="number" min="0" max="3650" name="<?php echo esc_attr( self::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>"> <?php esc_html_e( 'days', 'eduai-enquiry' ); ?>
							<p class="description"><?php esc_html_e( 'Delivered enquiries are deleted after this. Zero keeps them for ever, which is a decision rather than a default.', 'eduai-enquiry' ); ?></p>
						</td>
					</tr>
				</table>

				<?php if ( 'eduai-assistant' !== $route ) : ?>
					<h2><?php esc_html_e( 'Standalone model access', 'eduai-enquiry' ); ?></h2>
					<p class="description" style="max-width:70ch"><?php esc_html_e( 'Only needed when the EduAI Assistant plugin is not installed. A key set in wp-config.php as EDUAI_ENQUIRY_API_KEY is used in preference to anything stored here.', 'eduai-enquiry' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="cc-key"><?php esc_html_e( 'API key', 'eduai-enquiry' ); ?></label></th>
							<td><input id="cc-key" type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" value="<?php echo esc_attr( $s['api_key'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="cc-endpoint"><?php esc_html_e( 'Endpoint', 'eduai-enquiry' ); ?></label></th>
							<td><input id="cc-endpoint" type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="cc-model"><?php esc_html_e( 'Model', 'eduai-enquiry' ); ?></label></th>
							<td><input id="cc-model" type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[model]" value="<?php echo esc_attr( $s['model'] ); ?>"></td>
						</tr>
					</table>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>

			<?php self::leads(); ?>
		</div>
		<?php
	}

	/**
	 * Recent enquiries, with their delivery state.
	 */
	private static function leads(): void {
		$rows = EduAI_Enquiry_Leads::recent( 25 );

		echo '<h2>' . esc_html__( 'Recent enquiries', 'eduai-enquiry' ) . '</h2>';

		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'None yet.', 'eduai-enquiry' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'When', 'eduai-enquiry' ), __( 'Name', 'eduai-enquiry' ), __( 'Contact', 'eduai-enquiry' ), __( 'Interest', 'eduai-enquiry' ), __( 'CRM', 'eduai-enquiry' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$state = $r['crm_state'];
			$colour = 'sent' === $state ? '#14634A' : ( 'failed' === $state ? '#A81F1A' : '#8A5A00' );

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><strong style="color:%s">%s</strong>%s</td></tr>',
				esc_html( $r['created_at'] ),
				esc_html( $r['name'] ?: '—' ),
				esc_html( $r['email'] ?: $r['phone'] ?: '—' ),
				esc_html( mb_substr( (string) $r['interest'], 0, 60 ) ?: '—' ),
				esc_attr( $colour ),
				esc_html( $state ),
				$r['crm_error'] ? '<br><span class="description">' . esc_html( $r['crm_error'] ) . '</span>' : ''
			);
		}

		echo '</tbody></table>';
	}
}
