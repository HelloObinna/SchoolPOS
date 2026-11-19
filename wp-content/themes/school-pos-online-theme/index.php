<?php
defined( 'ABSPATH' ) || exit;
get_header();
if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();
		?>
		<article class="pos-card">
			<h1><?php the_title(); ?></h1>
			<?php the_content(); ?>
		</article>
		<?php
	endwhile;
else :
	?>
	<section class="pos-card"><h1><?php esc_html_e( 'SchoolPOS', 'school-pos-online-theme' ); ?></h1><p><?php esc_html_e( 'Use the assigned SchoolPOS application route to continue.', 'school-pos-online-theme' ); ?></p></section>
	<?php
endif;
get_footer();
