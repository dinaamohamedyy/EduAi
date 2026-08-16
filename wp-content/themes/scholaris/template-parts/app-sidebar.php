<?php
/**
 * The app sidebar — persistent left rail, per the reference the owner sent.
 *
 * WHAT IS HERE AND WHAT IS NOT
 * ----------------------------
 * The reference is a different product, and its sidebar advertises features
 * this one does not have: a Words/Images quota meter, Templates, Friends,
 * Events, Speech-to-Text and Voiceover as destinations, Help & Support.
 * template-parts/dashboard.php already set the rule this follows — every entry
 * points at something real, and a slot with nothing behind it is absent rather
 * than present-and-dead. A nav item that opens a 404, or a meter reading zero
 * because nothing counts it, is worse than a shorter sidebar: it teaches the
 * student the product is broken, and it is the same broken promise as a link
 * to a page that will bounce them.
 *
 * So the groups below carry the AI features that exist, under the reference's
 * own grouping idea. Voice is deliberately not a group: speech input lives
 * INSIDE the Q&A panel (chat.js), it is not a destination, and a rail entry
 * pointing at a control on another page is a false address.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

/**
 * One rail link, marked current when it is the page being viewed.
 *
 * @param string $slug  Page slug to resolve.
 * @param string $label Visible label.
 * @param string $icon  Icon key for scholaris_icon().
 */
function scholaris_rail_link( string $slug, string $label, string $icon ): void {
	$page = get_page_by_path( $slug );

	// Absent page, absent link — never a dead address in the primary rail.
	if ( ! $page ) {
		return;
	}

	$url     = (string) get_permalink( $page );
	$current = is_page( $page->ID ) || untrailingslashit( $url ) === untrailingslashit( home_url( add_query_arg( array() ) ) );
	?>
	<li>
		<a class="rail__link<?php echo $current ? ' is-current' : ''; ?>"
			href="<?php echo esc_url( $url ); ?>"
			<?php echo $current ? ' aria-current="page"' : ''; ?>>
			<?php scholaris_the_icon( $icon, 18 ); ?>
			<span><?php echo esc_html( $label ); ?></span>
		</a>
	</li>
	<?php
}
?>
<aside class="rail" id="app-rail" aria-label="<?php esc_attr_e( 'Study tools', 'scholaris' ); ?>">

	<a class="rail__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
		<span class="rail__mark" aria-hidden="true"><?php echo esc_html( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ); ?></span>
		<span class="rail__name"><?php bloginfo( 'name' ); ?></span>
	</a>

	<form class="rail__search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<label class="screen-reader-text" for="rail-s"><?php esc_html_e( 'Search', 'scholaris' ); ?></label>
		<?php scholaris_the_icon( 'search', 16 ); ?>
		<input type="search" id="rail-s" name="s" placeholder="<?php esc_attr_e( 'Search', 'scholaris' ); ?>"
			value="<?php echo esc_attr( get_search_query() ); ?>" autocomplete="off">
	</form>

	<nav class="rail__nav">
		<ul class="rail__list">
			<?php
			scholaris_rail_link( 'progress', __( 'Dashboard', 'scholaris' ), 'chart' );
			scholaris_rail_link( 'library', __( 'Library', 'scholaris' ), 'book' );
			?>
		</ul>

		<p class="rail__group"><?php esc_html_e( 'Study tools', 'scholaris' ); ?></p>
		<ul class="rail__list">
			<?php
			/*
			 * The four AI features, unchanged — this is a shell around them, not
			 * a replacement for them. Labels stay as the product names the owner
			 * chose; the reference's "AI Writer" and "Assistants" are its names
			 * for its own tools, and renaming ours to match would make every
			 * other surface disagree with the rail.
			 */
			scholaris_rail_link( 'summarise', __( 'Summarise', 'scholaris' ), 'sparkles' );
			scholaris_rail_link( 'ask', __( 'Q&A', 'scholaris' ), 'users' );
			scholaris_rail_link( 'calc', __( 'AiCalc', 'scholaris' ), 'chart' );
			scholaris_rail_link( 'prepare', __( 'PrepareME', 'scholaris' ), 'file' );
			?>
		</ul>
	</nav>

	<div class="rail__foot">
		<?php if ( is_user_logged_in() ) : ?>
			<a class="rail__me" href="<?php echo esc_url( scholaris_profile_url() ); ?>">
				<?php echo get_avatar( get_current_user_id(), 32, '', '', array( 'class' => 'rail__avatar' ) ); ?>
				<span>
					<strong><?php echo esc_html( wp_get_current_user()->display_name ); ?></strong>
					<em><?php esc_html_e( 'Student', 'scholaris' ); ?></em>
				</span>
			</a>
		<?php else : ?>
			<a class="btn btn--primary btn--sm btn--block" href="<?php echo esc_url( wp_login_url() ); ?>">
				<?php esc_html_e( 'Sign in', 'scholaris' ); ?>
			</a>
		<?php endif; ?>
	</div>
</aside>
