<?php
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="pos-shell">
	<header class="pos-header">
		<div class="pos-header-inner">
			<a class="pos-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_option( 'school_pos_school_name', get_bloginfo( 'name' ) ) ); ?></a>
			<nav class="pos-nav" aria-label="<?php esc_attr_e( 'Primary navigation', 'school-pos-online-theme' ); ?>">
				<?php wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'fallback_cb' => false ) ); ?>
			</nav>
		</div>
	</header>
	<main class="pos-main">
