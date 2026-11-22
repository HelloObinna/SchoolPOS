<?php
defined( 'ABSPATH' ) || exit;
get_header();
if ( get_query_var( 'school_pos_app' ) || ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( wp_unslash( $_SERVER['REQUEST_URI'] ), '/school-pos/' ) ) ) {
	echo do_shortcode( '[school_pos_app]' );
} elseif ( is_singular() ) {
	while ( have_posts() ) { the_post(); echo '<article class="pos-card"><h1>' . esc_html( get_the_title() ) . '</h1>'; the_content(); echo '</article>'; }
} else {
	echo '<section class="pos-card"><h1>SchoolPOS</h1><p>Open the secure operations workspace.</p><p><a class="pos-button" href="' . esc_url( home_url( '/school-pos/' ) ) . '">Open SchoolPOS</a></p></section>';
}
get_footer();
