<?php
/**
 * Customizer settings for homepage copy.
 *
 * @package Scholaris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Scholaris customizer panel.
 *
 * @param WP_Customize_Manager $wp_customize Customizer instance.
 */
function scholaris_customize_register( $wp_customize ): void {
	$wp_customize->add_section( 'scholaris_hero', array(
		'title'       => __( 'Scholaris — Homepage', 'scholaris' ),
		'priority'    => 25,
		'description' => __( 'Copy shown in the homepage hero and the assistant panel.', 'scholaris' ),
	) );

	$fields = array(
		'scholaris_hero_eyebrow'  => array( __( 'Hero eyebrow', 'scholaris' ), __( 'AI-assisted learning', 'scholaris' ), 'text' ),
		'scholaris_hero_title'    => array( __( 'Hero title', 'scholaris' ), __( 'Everything you need to <em>study smarter</em>', 'scholaris' ), 'textarea' ),
		'scholaris_hero_lede'     => array( __( 'Hero paragraph', 'scholaris' ), __( 'Course material, past quizzes with your scores, and an assistant that answers questions and summarises your lectures — by text or by voice.', 'scholaris' ), 'textarea' ),
		'scholaris_hero_cta_text' => array( __( 'Primary button text', 'scholaris' ), __( 'Browse the library', 'scholaris' ), 'text' ),
		'scholaris_hero_cta_url'  => array( __( 'Primary button URL', 'scholaris' ), '/library/', 'url' ),
		'scholaris_hero_alt_text' => array( __( 'Secondary button text', 'scholaris' ), __( 'View my progress', 'scholaris' ), 'text' ),
		'scholaris_hero_alt_url'  => array( __( 'Secondary button URL', 'scholaris' ), '/dashboard/', 'url' ),
		'scholaris_footer_about'  => array( __( 'Footer description', 'scholaris' ), __( 'A learning platform for students: lecture material, self-assessment quizzes and an always-on study assistant.', 'scholaris' ), 'textarea' ),
	);

	foreach ( $fields as $id => $config ) {
		list( $label, $default, $type ) = $config;

		$wp_customize->add_setting( $id, array(
			'default'           => $default,
			'sanitize_callback' => 'url' === $type ? 'esc_url_raw' : 'wp_kses_post',
			'transport'         => 'refresh',
		) );

		$wp_customize->add_control( $id, array(
			'label'   => $label,
			'section' => 'scholaris_hero',
			'type'    => 'textarea' === $type ? 'textarea' : ( 'url' === $type ? 'url' : 'text' ),
		) );
	}

	$wp_customize->add_setting( 'scholaris_show_assistant', array(
		'default'           => true,
		'sanitize_callback' => 'rest_sanitize_boolean',
	) );
	$wp_customize->add_control( 'scholaris_show_assistant', array(
		'label'       => __( 'Show the assistant panel in the hero', 'scholaris' ),
		'description' => __( 'Requires the EduAI Assistant plugin to be active.', 'scholaris' ),
		'section'     => 'scholaris_hero',
		'type'        => 'checkbox',
	) );
}
add_action( 'customize_register', 'scholaris_customize_register' );
