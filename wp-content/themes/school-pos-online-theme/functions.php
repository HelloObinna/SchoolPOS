<?php
defined( 'ABSPATH' ) || exit;

add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'title-tag' );
		add_theme_support( 'custom-logo' );
		register_nav_menus( array( 'primary' => 'Primary navigation' ) );
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		$style = get_stylesheet_directory() . '/style.css';
		wp_enqueue_style( 'school-pos-online', get_stylesheet_uri(), array(), file_exists( $style ) ? filemtime( $style ) : '0.1.0' );
		$script = get_stylesheet_directory() . '/assets/app.js';
		wp_enqueue_script( 'school-pos-online-app', get_stylesheet_directory_uri() . '/assets/app.js', array(), file_exists( $script ) ? filemtime( $script ) : '0.1.0', true );
	}
);

add_filter(
	'body_class',
	static function ( $classes ) {
		$classes[] = 'school-pos-online';
		return $classes;
	}
);
