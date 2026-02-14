<?php

/**
 * Single speaker template.
 *
 * @package ORAS\Tickets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();
		$affiliation = (string) get_post_meta( get_the_ID(), '_oras_speaker_affiliation', true );
		$website_url = (string) get_post_meta( get_the_ID(), '_oras_speaker_website_url', true );
		?>
		<main id="primary" class="site-main oras-speaker-single">
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<header class="entry-header">
					<h1 class="entry-title"><?php the_title(); ?></h1>
				</header>

				<?php if ( has_post_thumbnail() ) : ?>
					<div class="oras-speaker-headshot">
						<?php the_post_thumbnail( 'large' ); ?>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $affiliation || '' !== $website_url ) : ?>
					<div class="oras-speaker-meta">
						<?php if ( '' !== $affiliation ) : ?>
							<p class="oras-speaker-affiliation"><?php echo esc_html( $affiliation ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $website_url ) : ?>
							<p class="oras-speaker-website">
								<a href="<?php echo esc_url( $website_url ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( $website_url ); ?>
								</a>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="entry-content oras-speaker-bio">
					<?php echo wp_kses_post( apply_filters( 'the_content', get_the_content() ) ); ?>
				</div>
			</article>
		</main>
		<?php
	endwhile;
endif;

get_footer();
