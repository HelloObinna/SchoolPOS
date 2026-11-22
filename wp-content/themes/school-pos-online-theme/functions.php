<?php
defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', static function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'custom-logo', array( 'height' => 72, 'width' => 72, 'flex-height' => true, 'flex-width' => true ) );
	register_nav_menus( array( 'primary' => 'SchoolPOS navigation' ) );
} );

add_action( 'wp_enqueue_scripts', static function () {
	$style = get_stylesheet_directory() . '/style.css';
	$script = get_stylesheet_directory() . '/assets/app.js';
	wp_enqueue_style( 'school-pos-online', get_stylesheet_uri(), array(), file_exists( $style ) ? filemtime( $style ) : SCHOOL_POS_VERSION );
	wp_enqueue_script( 'school-pos-online-app', get_stylesheet_directory_uri() . '/assets/app.js', array(), file_exists( $script ) ? filemtime( $script ) : SCHOOL_POS_VERSION, true );
} );
