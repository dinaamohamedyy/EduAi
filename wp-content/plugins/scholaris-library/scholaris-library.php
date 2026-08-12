<?php
/**
 * Plugin Name:       EduAi Library & Progress
 * Plugin URI:        https://example.com/scholaris-library
 * Description:       EduAi's searchable study-material library (PDFs, slides, notes) organised by course, plus a student progress dashboard that reports every Tutor LMS quiz attempt and score.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            YZH Solution
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scholaris-library
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

// Bump on every admin.js/admin.css change: both are cache-keyed on ?ver=.
define( 'SL_VERSION', '1.2.2' );
define( 'SL_FILE', __FILE__ );
define( 'SL_DIR', plugin_dir_path( __FILE__ ) );
define( 'SL_URL', plugin_dir_url( __FILE__ ) );

require_once SL_DIR . 'includes/class-sl-post-types.php';
require_once SL_DIR . 'includes/class-sl-meta.php';
require_once SL_DIR . 'includes/class-sl-templates.php';
require_once SL_DIR . 'includes/class-sl-library.php';
require_once SL_DIR . 'includes/class-sl-private.php';
require_once SL_DIR . 'includes/class-sl-catalog.php';
require_once SL_DIR . 'includes/class-sl-bank.php';
require_once SL_DIR . 'includes/class-sl-console.php';
require_once SL_DIR . 'includes/class-sl-quiz-history.php';

/**
 * Plugin bootstrap.
 */
final class Scholaris_Library {

	public static function init(): void {
		SL_Post_Types::init();
		SL_Meta::init();
		SL_Templates::init();
		SL_Library::init();
		SL_Private::init();
		SL_Catalog::init();
		SL_Bank::init();
		SL_Console::init();
		SL_Quiz_History::init();

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets(): void {
		wp_register_style( 'scholaris-library', SL_URL . 'assets/css/library.css', array(), SL_VERSION );
		wp_register_script( 'scholaris-library', SL_URL . 'assets/js/library.js', array(), SL_VERSION, true );

		// Loaded on demand by the shortcodes and templates.
		if ( is_singular( 'study_material' ) || is_post_type_archive( 'study_material' ) || is_tax( array( 'material_subject', 'material_type' ) ) ) {
			wp_enqueue_style( 'scholaris-library' );
			wp_enqueue_script( 'scholaris-library' );
		}
	}

	/**
	 * Activation: register the CPT then flush rewrites so /library/ works at once.
	 */
	public static function activate(): void {
		SL_Post_Types::register();

		// Belt and braces alongside the meta-change hooks: on a fresh install
		// nothing has saved a material yet, so without this the denied
		// directory would not exist until the first save — and a gated file
		// uploaded before that moment is served by Apache directly. A newly
		// deployed site is exactly the owner's case.
		SL_Private::ensure_denied();
		SL_Private::migrate();

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

/**
 * `wp scholaris secure-files` — place every material's files by its access
 * level, then report what is still reachable.
 *
 * Exists because placement is otherwise only ever a side effect: of a save,
 * an upload, an activation or a version bump. None of those is something an
 * operator can *choose* to do after a restore, an import, or a bulk edit
 * that bypassed the editor. A non-zero exit makes it usable from a cron or a
 * deploy step rather than only by eye.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'scholaris secure-files', function () {
		$moved = SL_Private::migrate();

		WP_CLI::log( sprintf(
			'placed: %d moved, %d already correct, %d failed, across %d materials',
			$moved['moved'],
			$moved['unchanged'],
			$moved['failed'],
			$moved['materials']
		) );

		$audit = SL_Private::audit();

		foreach ( $audit['unplaced'] as $id => $title ) {
			WP_CLI::warning( sprintf( 'material %d "%s" is restricted but its file is still reachable', $id, $title ) );
		}

		// Not a failure — a boundary. These have no material and therefore no
		// access level, so there is nothing to honour. Reported so the
		// boundary is visible rather than inferred.
		foreach ( $audit['unattached'] as $id => $url ) {
			WP_CLI::log( sprintf( '  outside the library: attachment %d is public and attached to no material — %s', $id, $url ) );
		}

		if ( $audit['unplaced'] || $moved['failed'] ) {
			WP_CLI::error( sprintf( '%d material(s) could not be secured', count( $audit['unplaced'] ) + $moved['failed'] ) );
		}

		WP_CLI::success( sprintf(
			'every material is placed correctly (%d file(s) outside the library, by design)',
			count( $audit['unattached'] )
		) );
	} );
}

add_action( 'plugins_loaded', array( 'Scholaris_Library', 'init' ) );
register_activation_hook( __FILE__, array( 'Scholaris_Library', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Scholaris_Library', 'deactivate' ) );
