<?php
/**
 * The EduAi console screen.
 *
 * MARKUP OWNERSHIP: this file is the front-end developer's (docs/11 §9.2
 * item 3). What is here is deliberately plain and semantic — correct
 * structure, real data, no styling opinions — so the screen works today and
 * can be restyled without touching SL_Console. The variables it receives are
 * the contract:
 *
 *   $sl_cards   array of [ title, lead, links[] ] — links already
 *               capability-filtered by SL_Console::link(), so a card may
 *               legitimately contain none
 *   $sl_status  materials, without_media, courses (null when Tutor is off),
 *               accounts, max_upload
 *
 * @package ScholarisLibrary
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap sl-console">
	<h1><?php esc_html_e( 'EduAi', 'scholaris-library' ); ?></h1>

	<p class="sl-console__lead">
		<?php esc_html_e( 'Everything for running the course, in one place.', 'scholaris-library' ); ?>
	</p>

	<ul class="sl-console__status">
		<li>
			<strong><?php echo esc_html( (string) $sl_status['materials'] ); ?></strong>
			<span><?php esc_html_e( 'published materials', 'scholaris-library' ); ?></span>
		</li>
		<?php if ( $sl_status['without_media'] ) : ?>
			<li class="sl-console__status--warn">
				<strong><?php echo esc_html( (string) $sl_status['without_media'] ); ?></strong>
				<span><?php esc_html_e( 'with neither a document nor a video', 'scholaris-library' ); ?></span>
			</li>
		<?php endif; ?>
		<?php if ( null !== $sl_status['courses'] ) : ?>
			<li>
				<strong><?php echo esc_html( (string) $sl_status['courses'] ); ?></strong>
				<span><?php esc_html_e( 'courses', 'scholaris-library' ); ?></span>
			</li>
		<?php endif; ?>
		<li>
			<strong><?php echo esc_html( (string) $sl_status['accounts'] ); ?></strong>
			<span><?php esc_html_e( 'registered accounts', 'scholaris-library' ); ?></span>
		</li>
		<li>
			<strong><?php echo esc_html( $sl_status['max_upload'] ); ?></strong>
			<span><?php esc_html_e( 'largest upload this server accepts', 'scholaris-library' ); ?></span>
		</li>
	</ul>

	<div class="sl-console__cards">
		<?php
		foreach ( $sl_cards as $sl_card ) :
			$sl_links = '';

			foreach ( $sl_card['links'] as $sl_link ) {
				$sl_links .= SL_Console::link( $sl_link[0], $sl_link[1], $sl_link[2] );
			}

			// A card whose every link was filtered out is not a card — it is
			// a heading advertising something this user cannot do.
			if ( '' === $sl_links ) {
				continue;
			}
			?>
			<section class="sl-console__card">
				<h2><?php echo esc_html( $sl_card['title'] ); ?></h2>
				<p><?php echo esc_html( $sl_card['lead'] ); ?></p>
				<ul><?php echo wp_kses_post( $sl_links ); ?></ul>
			</section>
		<?php endforeach; ?>

		<section class="sl-console__card">
			<h2><?php esc_html_e( 'Practice papers', 'scholaris-library' ); ?></h2>
			<p>
				<?php esc_html_e( 'A question bank lives on the material it belongs to — add it in the Question bank box when you edit that material.', 'scholaris-library' ); ?>
			</p>
		</section>
	</div>
</div>
