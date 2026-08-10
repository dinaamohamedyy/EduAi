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

define( 'SL_VERSION', '1.0.2' );
define( 'SL_FILE', __FILE__ );
define( 'SL_DIR', plugin_dir_path( __FILE__ ) );
define( 'SL_URL', plugin_dir_url( __FILE__ ) );

require_once SL_DIR . 'includes/class-sl-post-types.php';
require_once SL_DIR . 'includes/class-sl-meta.php';
require_once SL_DIR . 'includes/class-sl-templates.php';
require_once SL_DIR . 'includes/class-sl-library.php';
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
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

add_action( 'plugins_loaded', array( 'Scholaris_Library', 'init' ) );
register_activation_hook( __FILE__, array( 'Scholaris_Library', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Scholaris_Library', 'deactivate' ) );
