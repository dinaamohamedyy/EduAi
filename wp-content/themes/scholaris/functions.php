<?php
/**
 * Scholaris theme bootstrap.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

define( 'SCHOLARIS_VERSION', '1.0.3' );
define( 'SCHOLARIS_DIR', get_template_directory() );
define( 'SCHOLARIS_URI', get_template_directory_uri() );

/**
 * Theme supports, menus, image sizes.
 */
function scholaris_setup(): void {
	load_theme_textdomain( 'scholaris', SCHOLARIS_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'custom-logo', array(
		'height'      => 48,
		'width'       => 200,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array(
		'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script',
	) );

	// Tutor LMS integration flags.
	add_theme_support( 'tutor' );

	// Admin bar: disable core's `html { margin-top: … !important }` bump and
	// space the page in the theme's own CSS instead. Root-margin interacts
	// with the sticky header differently per engine (it is what left a 46px
	// hole above the header on mobile once the absolute bar scrolled away),
	// so the offsets live in main.css §6 where the geometry is deterministic.
	add_theme_support( 'admin-bar', array( 'callback' => '__return_false' ) );

	register_nav_menus( array(
		'primary' => __( 'Primary Menu', 'scholaris' ),
		'footer'  => __( 'Footer Menu', 'scholaris' ),
	) );

	add_image_size( 'scholaris-card', 720, 405, true );
}
add_action( 'after_setup_theme', 'scholaris_setup' );

/**
 * Front-end assets.
 */
function scholaris_assets(): void {
	// Self-hosting the fonts is recommended for production; see docs/03-hosting-deployment.md.
	wp_enqueue_style(
		'scholaris-fonts',
		'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500..700;1,9..144,500..700&family=Inter:wght@400;500;600;700&family=Spline+Sans+Mono:wght@500;600&display=swap',
		array(),
		null
	);

	wp_enqueue_style( 'scholaris', SCHOLARIS_URI . '/assets/css/main.css', array(), SCHOLARIS_VERSION );
	wp_enqueue_style( 'scholaris-style', get_stylesheet_uri(), array( 'scholaris' ), SCHOLARIS_VERSION );

	wp_enqueue_script( 'scholaris', SCHOLARIS_URI . '/assets/js/main.js', array(), SCHOLARIS_VERSION, true );

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'scholaris_assets' );

/**
 * Set the colour-scheme attribute before paint to avoid a flash of the wrong theme.
 */
function scholaris_color_scheme_boot(): void {
	?>
	<script>
	(function () {
		try {
			var stored = localStorage.getItem('scholaris-theme');
			var prefers = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
			document.documentElement.setAttribute('data-theme', stored || prefers);
		} catch (e) {
			document.documentElement.setAttribute('data-theme', 'light');
		}
	})();
	</script>
	<?php
}
add_action( 'wp_head', 'scholaris_color_scheme_boot', 1 );

/**
 * Widget areas.
 */
function scholaris_widgets(): void {
	register_sidebar( array(
		'name'          => __( 'Sidebar', 'scholaris' ),
		'id'            => 'sidebar-1',
		'before_widget' => '<section id="%1$s" class="card widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="card__title">',
		'after_title'   => '</h3>',
	) );
}
add_action( 'widgets_init', 'scholaris_widgets' );

/**
 * Fallback menu when no primary menu is assigned.
 */
function scholaris_fallback_menu(): void {
	echo '<ul>';
	echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'scholaris' ) . '</a></li>';

	// The seven-tab nav: docs/06-eduai-rebuild.md plus the owner's ruling
	// that My Progress stays. Material is reached through Library.
	foreach ( array(
		'library'   => __( 'Library', 'scholaris' ),
		'summarise' => __( 'Summarise', 'scholaris' ),
		'calc'      => __( 'AiCalc', 'scholaris' ),
		'ask'       => __( 'Q&A', 'scholaris' ),
		'prepare'   => __( 'PrepareME', 'scholaris' ),
		'progress'  => __( 'My Progress', 'scholaris' ),
	) as $slug => $label ) {
		$page = get_page_by_path( $slug );
		if ( $page ) {
			echo '<li><a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( $label ) . '</a></li>';
		}
	}

	echo '</ul>';
}

/**
 * Simple breadcrumb trail.
 */
function scholaris_breadcrumbs(): void {
	if ( is_front_page() ) {
		return;
	}

	echo '<nav class="breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'scholaris' ) . '">';
	echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'scholaris' ) . '</a>';

	if ( is_singular() ) {
		$post_type = get_post_type_object( get_post_type() );
		if ( $post_type && ! empty( $post_type->has_archive ) ) {
			$link = get_post_type_archive_link( get_post_type() );
			if ( $link ) {
				echo ' <span aria-hidden="true">/</span> <a href="' . esc_url( $link ) . '">' . esc_html( $post_type->labels->name ) . '</a>';
			}
		}
		echo ' <span aria-hidden="true">/</span> <span>' . esc_html( wp_trim_words( get_the_title(), 8 ) ) . '</span>';
	} elseif ( is_archive() ) {
		echo ' <span aria-hidden="true">/</span> <span>' . esc_html( wp_strip_all_tags( get_the_archive_title() ) ) . '</span>';
	} elseif ( is_search() ) {
		echo ' <span aria-hidden="true">/</span> <span>' . esc_html__( 'Search results', 'scholaris' ) . '</span>';
	}

	echo '</nav>';
}

/**
 * Excerpt tweaks.
 */
add_filter( 'excerpt_length', fn() => 24 );
add_filter( 'excerpt_more', fn() => '&hellip;' );

/**
 * Body classes used by the CSS to opt pages into full-bleed layouts.
 */
function scholaris_body_class( array $classes ): array {
	if ( is_front_page() ) {
		$classes[] = 'is-front';
	}
	if ( ! is_active_sidebar( 'sidebar-1' ) ) {
		$classes[] = 'no-sidebar';
	}
	return $classes;
}
add_filter( 'body_class', 'scholaris_body_class' );

/**
 * Customizer options — homepage copy and the chatbot slot.
 */
require_once SCHOLARIS_DIR . '/inc/customizer.php';
require_once SCHOLARIS_DIR . '/inc/template-tags.php';
require_once SCHOLARIS_DIR . '/inc/auth.php';
require_once SCHOLARIS_DIR . '/inc/auth-flow.php';
