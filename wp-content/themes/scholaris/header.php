<?php
/**
 * Site header.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', 'scholaris' ); ?></a>

<?php
/*
 * The app rail (16 Aug 2026, per the reference the owner sent). It is a
 * sibling of the header rather than a wrapper around it: body already has
 * header/main/footer as direct children, so the shell is one element plus the
 * has-rail body class, and this file keeps the header geometry that was
 * measured rather than reasoned about.
 *
 * The front-page test moved into scholaris_has_rail() — see the note there for
 * why a signed-in student now gets the rail on home and a visitor still does
 * not.
 */
if ( scholaris_has_rail() ) {
	get_template_part( 'template-parts/app-sidebar' );
}
?>

<header class="site-header">
	<div class="wrap site-header__inner">
		<?php if ( has_custom_logo() ) : ?>
			<?php the_custom_logo(); ?>
		<?php else : ?>
			<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
				<span class="brand__mark" aria-hidden="true"><?php echo esc_html( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ); ?></span>
				<span>
					<span class="brand__name"><?php bloginfo( 'name' ); ?></span>
					<?php $desc = get_bloginfo( 'description', 'display' ); ?>
					<?php if ( $desc ) : ?>
						<span class="brand__tag"><?php echo esc_html( $desc ); ?></span>
					<?php endif; ?>
				</span>
			</a>
		<?php endif; ?>

		<nav class="nav" id="primary-nav" aria-label="<?php esc_attr_e( 'Primary', 'scholaris' ); ?>">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'depth'          => 2,
				'fallback_cb'    => 'scholaris_fallback_menu',
			) );
			?>
		</nav>

		<div class="header-actions">
			<button class="icon-btn" type="button" data-theme-toggle
				aria-label="<?php esc_attr_e( 'Toggle dark mode', 'scholaris' ); ?>">
				<span data-icon="sun"><?php scholaris_the_icon( 'sun', 18 ); ?></span>
				<span data-icon="moon" hidden><?php scholaris_the_icon( 'moon', 18 ); ?></span>
			</button>

			<?php
			// Signed-in users get no header button: "My Progress" is the nav's
			// seventh tab (docs/06 + owner ruling), and the duplicate ghost
			// button cost ~130px of exactly the width that made the header
			// overflow between 900 and ~1190 (measured 10 Aug 2026, both
			// failure modes: horizontal document overflow and the row wrapping
			// to 134px). Anonymous visitors keep "Sign in" — the nav has no
			// auth tab, so it is an affordance, not a duplicate.
			?>
			<?php if ( ! is_user_logged_in() ) : ?>
				<a class="btn btn--primary btn--sm hide-sm" href="<?php echo esc_url( wp_login_url() ); ?>">
					<?php esc_html_e( 'Sign in', 'scholaris' ); ?>
				</a>
			<?php else : ?>
				<?php
				// The account screen still needs a way in, but as an avatar at
				// icon-button width — NOT the text button removed above. That one
				// cost ~130px and overflowed the header between 900 and ~1190;
				// this is the same footprint as the theme toggle beside it, and
				// was checked at those widths rather than reasoned about.
				?>
				<a class="icon-btn icon-btn--avatar" href="<?php echo esc_url( scholaris_profile_url() ); ?>"
					aria-label="<?php esc_attr_e( 'Your profile', 'scholaris' ); ?>">
					<?php echo get_avatar( get_current_user_id(), 26, '', '', array( 'class' => 'header-avatar' ) ); ?>
				</a>
			<?php endif; ?>

			<button class="icon-btn nav-toggle" type="button" aria-controls="primary-nav" aria-expanded="false"
				aria-label="<?php esc_attr_e( 'Toggle menu', 'scholaris' ); ?>">
				<?php scholaris_the_icon( 'menu', 20 ); ?>
			</button>
		</div>
	</div>
</header>

<main id="main" class="site-main">
