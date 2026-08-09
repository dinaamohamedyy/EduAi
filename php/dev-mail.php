<?php
/**
 * Plugin Name: Scholaris dev mail routing
 * Description: Routes wp_mail() to the local Mailpit container so password resets and notifications are visible at http://localhost:8025. Mounted as an mu-plugin by docker-compose.yml only — inert unless SCHOLARIS_DEV_SMTP is defined, and never deployed (deploy.yml ships wp-content only; this file lives in php/).
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

/**
 * WordPress derives the default From address from the site host, so on the
 * Docker stack it is `wordpress@localhost`. PHPMailer rejects that outright —
 * "Invalid address: (From)" — because `localhost` has no TLD, and it rejects it
 * *before* opening the SMTP connection. The result is that every e-mail the
 * local site sends fails silently: no admin notification, and no password-reset
 * mail to test against, however healthy the catcher is.
 *
 * Only rewrites the localhost default; a real From set by the site or a mailer
 * plugin is left alone. Dev stack only — same SCHOLARIS_DEV_SMTP guard as the
 * routing below, so this file stays inert in every other context.
 */
add_filter( 'wp_mail_from', function ( $from ) {
	if ( ! defined( 'SCHOLARIS_DEV_SMTP' ) || '' === SCHOLARIS_DEV_SMTP ) {
		return $from;
	}

	return preg_match( '/@localhost$/i', (string) $from ) ? 'scholaris@scholaris.test' : $from;
} );

add_action( 'phpmailer_init', function ( $phpmailer ): void {
	if ( ! defined( 'SCHOLARIS_DEV_SMTP' ) || '' === SCHOLARIS_DEV_SMTP ) {
		return;
	}

	list( $host, $port ) = array_pad( explode( ':', SCHOLARIS_DEV_SMTP, 2 ), 2, '1025' );

	$phpmailer->isSMTP();
	$phpmailer->Host        = $host;
	$phpmailer->Port        = (int) $port;
	$phpmailer->SMTPAuth    = false;
	$phpmailer->SMTPSecure  = '';
	$phpmailer->SMTPAutoTLS = false;
	// PHPMailer's default is 300s — with the catcher down, every wp_mail()
	// would freeze the request for five minutes. The stall test raises this
	// deliberately via DEV_SMTP_TIMEOUT to measure the real behaviour.
	$phpmailer->Timeout     = defined( 'SCHOLARIS_DEV_SMTP_TIMEOUT' ) ? max( 1, (int) SCHOLARIS_DEV_SMTP_TIMEOUT ) : 10;
} );
