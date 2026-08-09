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

			<?php if ( is_user_logged_in() ) : ?>
				<a class="btn btn--ghost btn--sm hide-sm" href="<?php echo esc_url( scholaris_opt( 'scholaris_hero_alt_url', home_url( '/dashboard/' ) ) ); ?>">
					<?php esc_html_e( 'My progress', 'scholaris' ); ?>
				</a>
			<?php else : ?>
				<a class="btn btn--primary btn--sm hide-sm" href="<?php echo esc_url( wp_login_url() ); ?>">
					<?php esc_html_e( 'Sign in', 'scholaris' ); ?>
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
