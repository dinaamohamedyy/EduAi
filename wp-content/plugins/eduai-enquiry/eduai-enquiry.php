<?php
/**
 * Plugin Name:       EduAI Enquiry
 * Plugin URI:        https://example.org/eduai-enquiry
 * Description:       Bilingual (English/Arabic) enquiry assistant for visitors: finds courses, recommends, guides enrolment, captures leads to a CRM, and hands over to a human.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            EduAi
 * Text Domain:       eduai-enquiry
 * Domain Path:       /languages
 *
 * WHY THIS IS A SEPARATE PLUGIN, AND WHAT THAT COSTS
 *
 * The owner wants to lift this onto another site, so it must not require
 * `eduai-assistant`. That is a real constraint rather than a preference: the
 * study assistant answers ENROLLED students from indexed course material, and
 * this answers STRANGERS about a catalogue. Different audience, different data,
 * different failure modes.
 *
 * The cost is a model client. Rather than fork `EduAI_Claude` — which is how a
 * codebase ends up with two copies of one judgement, drifting — this USES that
 * gateway when the plugin is present and falls back to a small self-contained
 * client when it is not. One implementation is authoritative wherever both
 * exist; see EduAI_Enquiry_Model.
 *
 * THE RULE THIS PLUGIN IS BUILT AROUND
 *
 * A language model must never author a course fact. Prices, dates, durations
 * and formats are read from the database and rendered by PHP into cards. The
 * model chooses WHICH courses are relevant and writes the sentence around them.
 * When a field is missing it is reported missing, never omitted and never
 * filled — a blank handed to a model comes back as an invented fee.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

define( 'EDUAI_ENQUIRY_VERSION', '1.1.0' );
define( 'EDUAI_ENQUIRY_FILE', __FILE__ );
define( 'EDUAI_ENQUIRY_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDUAI_ENQUIRY_URL', plugin_dir_url( __FILE__ ) );

foreach ( array(
	'class-enquiry-i18n',
	'class-enquiry-model',
	'class-enquiry-nlu',
	'class-enquiry-session',
	'class-enquiry-catalog',
	'class-enquiry-leads',
	'class-enquiry-flows',
	'class-enquiry-rest',
	'class-enquiry-widget',
	'class-enquiry-admin',
) as $file ) {
	require_once EDUAI_ENQUIRY_DIR . 'includes/' . $file . '.php';
}

/**
 * Create storage. Runs on activation only.
 */
function eduai_enquiry_activate(): void {
	EduAI_Enquiry_Leads::install();
	EduAI_Enquiry_Session::install();

	/*
	 * Write the defaults, so the plugin is VISIBLE the moment it is activated.
	 *
	 * Without this it activated into a working-but-invisible state: the option
	 * was absent, `enabled` read as empty, the widget rendered nothing, and the
	 * settings screen offered no clue. An administrator sees a plugin that
	 * "does not work" and a developer sees code with no bug in it. Found by
	 * loading the front page and looking for the mount point, which is the only
	 * way this class of fault ever gets found.
	 *
	 * Existing settings win — an update must never reset somebody's choices.
	 */
	$existing = get_option( 'eduai_enquiry_settings' );

	update_option(
		'eduai_enquiry_settings',
		array_merge( EduAI_Enquiry_Admin::defaults(), is_array( $existing ) ? $existing : array() ),
		false
	);

	update_option( 'eduai_enquiry_version', EDUAI_ENQUIRY_VERSION, false );
}
register_activation_hook( __FILE__, 'eduai_enquiry_activate' );

/**
 * Stop scheduled work. Deliberately does NOT drop the leads table — a
 * deactivation is not a decision to destroy captured enquiries.
 */
function eduai_enquiry_deactivate(): void {
	wp_clear_scheduled_hook( EduAI_Enquiry_Leads::RETENTION_HOOK );
}
register_deactivation_hook( __FILE__, 'eduai_enquiry_deactivate' );

/**
 * Wire everything, once WordPress is ready for it.
 */
function eduai_enquiry_boot(): void {
	EduAI_Enquiry_I18n::init();
	EduAI_Enquiry_Leads::init();
	EduAI_Enquiry_Rest::init();
	EduAI_Enquiry_Widget::init();

	if ( is_admin() ) {
		EduAI_Enquiry_Admin::init();
	}

	// A schema change ships as a version bump; without this an updated plugin
	// runs against yesterday's tables and fails in a way that reads as a bug in
	// whatever touched the table last.
	if ( get_option( 'eduai_enquiry_version' ) !== EDUAI_ENQUIRY_VERSION ) {
		eduai_enquiry_activate();
	}
}
add_action( 'plugins_loaded', 'eduai_enquiry_boot' );
