<?php
/**
 * Template Name: Profile
 *
 * The account screen students actually see. Before this existed the only
 * profile on the site was wp-admin/profile.php — the raw WordPress admin form,
 * on a different typeface, colour scheme and layout, with forty fields nobody
 * on this site needs (admin colour scheme, keyboard shortcuts, syntax
 * highlighting). Students land there and reasonably conclude they have left the
 * product.
 *
 * Saves through scholaris_profile_save() in inc/auth-flow.php, which owns the
 * nonce, the validation and the redirect. This file renders; it does not write.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

get_header();

$scholaris_user = wp_get_current_user();
?>

<div class="page-hero">
	<div class="wrap">
		<?php scholaris_breadcrumbs(); ?>
		<h1 class="mb-0"><?php the_title(); ?></h1>
	</div>
</div>

<div class="wrap wrap--narrow section">

	<?php if ( ! is_user_logged_in() ) : ?>

		<div class="card">
			<div class="card__body">
				<h2><?php esc_html_e( 'Sign in to see your profile', 'scholaris' ); ?></h2>
				<p class="text-muted"><?php esc_html_e( 'Your details, your results and your practice papers all live behind your account.', 'scholaris' ); ?></p>
				<a class="btn btn--primary" href="<?php echo esc_url( scholaris_signin_url( get_permalink() ) ); ?>">
					<?php esc_html_e( 'Sign in', 'scholaris' ); ?>
				</a>
			</div>
		</div>

	<?php else : ?>

		<?php scholaris_profile_notices(); ?>

		<div class="profile">

			<header class="profile__head">
				<?php echo get_avatar( $scholaris_user->ID, 88, '', '', array( 'class' => 'profile__avatar' ) ); ?>
				<div>
					<h2 class="profile__name"><?php echo esc_html( $scholaris_user->display_name ); ?></h2>
					<p class="profile__mail"><?php echo esc_html( $scholaris_user->user_email ); ?></p>
					<p class="profile__since">
						<?php
						printf(
							/* translators: %s: date the account was created */
							esc_html__( 'Member since %s', 'scholaris' ),
							esc_html( date_i18n( get_option( 'date_format' ), (int) strtotime( $scholaris_user->user_registered ) ) )
						);
						?>
					</p>
				</div>
			</header>

			<?php
			// Progress summary, but only when the assistant plugin is present —
			// the same function_exists() contract the dashboard CTAs use. A
			// profile without it is complete; a profile showing "0 papers"
			// because the feature is absent is a lie.
			if ( function_exists( 'eduai_prepare_url' ) && class_exists( 'EduAI_Exams' ) ) :
				$scholaris_stats = EduAI_Exams::stats_for_user( $scholaris_user->ID );
				?>
				<section class="profile__section">
					<h3><?php esc_html_e( 'Your practice papers', 'scholaris' ); ?></h3>

					<div class="profile__stats">
						<div class="profile__stat">
							<span class="profile__statnum"><?php echo (int) $scholaris_stats['taken']; ?></span>
							<span class="profile__statlabel"><?php esc_html_e( 'sat', 'scholaris' ); ?></span>
						</div>
						<div class="profile__stat">
							<span class="profile__statnum"><?php echo null === $scholaris_stats['average'] ? '—' : (int) $scholaris_stats['average'] . '%'; ?></span>
							<span class="profile__statlabel"><?php esc_html_e( 'average', 'scholaris' ); ?></span>
						</div>
						<div class="profile__stat">
							<span class="profile__statnum"><?php echo null === $scholaris_stats['best'] ? '—' : (int) $scholaris_stats['best'] . '%'; ?></span>
							<span class="profile__statlabel"><?php esc_html_e( 'best', 'scholaris' ); ?></span>
						</div>
					</div>

					<p class="profile__actions">
						<a class="btn btn--primary btn--sm" href="<?php echo esc_url( scholaris_progress_url() ); ?>">
							<?php esc_html_e( 'See all results', 'scholaris' ); ?>
						</a>
						<a class="btn btn--ghost btn--sm" href="<?php echo esc_url( eduai_prepare_url() ); ?>">
							<?php esc_html_e( 'Sit a new paper', 'scholaris' ); ?>
						</a>
					</p>
				</section>
			<?php endif; ?>

			<section class="profile__section">
				<h3><?php esc_html_e( 'Your details', 'scholaris' ); ?></h3>

				<form class="auth__form" method="post" action="">
					<?php wp_nonce_field( 'scholaris_profile', 'scholaris_profile_nonce' ); ?>

					<div class="auth__row">
						<div class="field">
							<label for="first_name"><?php esc_html_e( 'First name', 'scholaris' ); ?></label>
							<input type="text" name="first_name" id="first_name" autocomplete="given-name"
								value="<?php echo esc_attr( $scholaris_user->first_name ); ?>">
						</div>
						<div class="field">
							<label for="last_name"><?php esc_html_e( 'Last name', 'scholaris' ); ?></label>
							<input type="text" name="last_name" id="last_name" autocomplete="family-name"
								value="<?php echo esc_attr( $scholaris_user->last_name ); ?>">
						</div>
					</div>

					<div class="field">
						<label for="user_email"><?php esc_html_e( 'E-mail', 'scholaris' ); ?></label>
						<input type="email" name="user_email" id="user_email" autocomplete="email" required
							value="<?php echo esc_attr( $scholaris_user->user_email ); ?>">
					</div>

					<?php // Username is shown but not editable: WordPress does not support changing it, and an input that silently ignores you is worse than a read-only one. ?>
					<div class="field">
						<label for="user_login_display"><?php esc_html_e( 'Username', 'scholaris' ); ?></label>
						<input type="text" id="user_login_display" value="<?php echo esc_attr( $scholaris_user->user_login ); ?>" disabled>
						<p class="field__hint"><?php esc_html_e( 'Usernames cannot be changed.', 'scholaris' ); ?></p>
					</div>

					<button type="submit" class="btn btn--primary" name="scholaris_profile_action" value="details">
						<?php esc_html_e( 'Save details', 'scholaris' ); ?>
					</button>
				</form>
			</section>

			<section class="profile__section">
				<h3><?php esc_html_e( 'Change password', 'scholaris' ); ?></h3>

				<form class="auth__form" method="post" action="">
					<?php wp_nonce_field( 'scholaris_profile', 'scholaris_profile_nonce' ); ?>

					<?php // Current password is required even though the session already proves identity: it is what stops a walked-away-from laptop becoming a permanent account takeover. ?>
					<div class="field">
						<label for="current_pass"><?php esc_html_e( 'Current password', 'scholaris' ); ?></label>
						<input type="password" name="current_pass" id="current_pass" autocomplete="current-password" required>
					</div>

					<div class="auth__row">
						<div class="field">
							<label for="new_pass"><?php esc_html_e( 'New password', 'scholaris' ); ?></label>
							<input type="password" name="new_pass" id="new_pass" autocomplete="new-password" required minlength="8">
						</div>
						<div class="field">
							<label for="new_pass2"><?php esc_html_e( 'Repeat new password', 'scholaris' ); ?></label>
							<input type="password" name="new_pass2" id="new_pass2" autocomplete="new-password" required minlength="8">
						</div>
					</div>

					<button type="submit" class="btn btn--primary" name="scholaris_profile_action" value="password">
						<?php esc_html_e( 'Change password', 'scholaris' ); ?>
					</button>
				</form>
			</section>

			<section class="profile__section profile__section--quiet">
				<p>
					<a class="btn btn--ghost btn--sm" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
						<?php esc_html_e( 'Sign out', 'scholaris' ); ?>
					</a>
					<?php if ( current_user_can( 'list_users' ) ) : ?>
						<a class="btn btn--quiet btn--sm" href="<?php echo esc_url( admin_url( 'users.php?page=eduai-students' ) ); ?>">
							<?php esc_html_e( 'Student progress (staff)', 'scholaris' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</section>

		</div>

	<?php endif; ?>

</div>

<?php
get_footer();
