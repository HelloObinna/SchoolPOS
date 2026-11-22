<?php defined( 'ABSPATH' ) || exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?>
<header class="pos-header"><div class="pos-header-inner">
	<a class="pos-brand" href="<?php echo esc_url( home_url( '/school-pos/' ) ); ?>"><span class="pos-brand-mark">SP</span><span><?php echo esc_html( get_option( 'school_pos_school_name', get_bloginfo( 'name' ) ) ); ?></span></a>
	<div class="pos-header-actions"><?php if ( is_user_logged_in() ) : ?><a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php echo esc_html( wp_get_current_user()->display_name ); ?></a><a href="<?php echo esc_url( wp_logout_url( home_url( '/school-pos/login/' ) ) ); ?>">Sign out</a><?php else : ?><a href="<?php echo esc_url( home_url( '/school-pos/login/' ) ); ?>">Sign in</a><a href="<?php echo esc_url( home_url( '/school-pos/register/' ) ); ?>">Create account</a><?php endif; ?></div>
</div></header>
<main class="pos-main">
