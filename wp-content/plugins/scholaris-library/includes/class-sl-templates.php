<?php
/**
 * Template loading for the study-material post type.
 *
 * Themes can override either file by copying it into
 * <theme>/scholaris/single-study_material.php or archive-study_material.php.
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves plugin templates, letting the active theme win.
 */
class SL_Templates {

	public static function init(): void {
		add_filter( 'template_include', array( __CLASS__, 'resolve' ) );
	}

	/**
	 * Swap in the plugin template when the theme has none.
	 *
	 * @param string $template Resolved template path.
	 */
	public static function resolve( string $template ): string {
		$name = '';

		if ( is_singular( 'study_material' ) ) {
			$name = 'single-study_material.php';
		} elseif ( is_post_type_archive( 'study_material' ) || is_tax( array( 'material_subject', 'material_type' ) ) ) {
			$name = 'archive-study_material.php';
		}

		if ( ! $name ) {
			return $template;
		}

		// 1. Theme root, 2. theme/scholaris/, 3. plugin default.
		$theme_file = locate_template( array( $name, 'scholaris/' . $name ) );
		if ( $theme_file ) {
			return $theme_file;
		}

		$plugin_file = SL_DIR . 'templates/' . $name;

		return file_exists( $plugin_file ) ? $plugin_file : $template;
	}
}
