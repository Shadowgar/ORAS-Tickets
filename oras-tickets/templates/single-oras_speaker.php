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

				<?php
				// ORAS Contributions: Historical events this speaker was associated with
				$speaker_id = absint( get_the_ID() );
				$speaker_history = get_post_meta( $speaker_id, '_oras_speaker_history_v1', true );
				$history_events = array();

				// Extract event data from history for enrichment
				if ( is_array( $speaker_history ) && isset( $speaker_history['version'] ) && 1 === (int) $speaker_history['version'] && isset( $speaker_history['events'] ) && is_array( $speaker_history['events'] ) ) {
					foreach ( $speaker_history['events'] as $event ) {
						$event_id = isset( $event['event_id'] ) ? absint( $event['event_id'] ) : 0;
						if ( $event_id > 0 ) {
							$history_events[ $event_id ] = $event;
						}
					}
				}

				// Query TEC events where this speaker is associated
				$associated_events = array();
				$speaker_id_str = (string) $speaker_id;

				// Try multiple LIKE patterns for serialized array matching
				$meta_queries = array(
					'relation' => 'OR',
					array(
						'key'     => '_oras_speakers_v1',
						'value'   => '"speaker_id";i:' . $speaker_id_str . ';',
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_oras_speakers_v1',
						'value'   => '"speaker_id":' . $speaker_id_str,
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_oras_speakers_v1',
						'value'   => 'speaker_id";i:' . $speaker_id_str . ';',
						'compare' => 'LIKE',
					),
				);

				$events_query = new WP_Query(
					array(
						'post_type'      => 'tribe_events',
						'post_status'    => 'publish',
						'posts_per_page' => 200,
						'fields'         => 'ids',
						'meta_query'     => $meta_queries,
						'orderby'        => 'meta_value',
						'meta_key'       => '_EventStartDate',
						'order'          => 'DESC',
					)
				);

				if ( $events_query->have_posts() ) {
					/** @var int[] $event_posts */
					$event_posts = $events_query->posts;
					$event_ids = array_unique( $event_posts );

					// Get event details
					foreach ( $event_ids as $event_id ) {
						$event_post = get_post( $event_id );
						if ( ! $event_post ) {
							continue;
						}

						$event_start_date = get_post_meta( $event_id, '_EventStartDate', true );
						$formatted_date = '';
						if ( $event_start_date ) {
							$date_obj = DateTime::createFromFormat( 'Y-m-d H:i:s', $event_start_date );
							if ( $date_obj ) {
								$formatted_date = $date_obj->format( 'Y-m-d' );
							}
						} elseif ( $event_post->post_date ) {
							$date_obj = DateTime::createFromFormat( 'Y-m-d H:i:s', $event_post->post_date );
							if ( $date_obj ) {
								$formatted_date = $date_obj->format( 'Y-m-d' );
							}
						}

						$associated_events[] = array(
							'id'         => $event_id,
							'title'      => sanitize_text_field( $event_post->post_title ),
							'start_date' => $formatted_date,
							'history'    => isset( $history_events[ $event_id ] ) ? $history_events[ $event_id ] : null,
						);
					}

					// Sort by start date descending (newest first)
					usort(
						$associated_events,
						function ( $a, $b ) {
							if ( $a['start_date'] && $b['start_date'] ) {
								return strcmp( $b['start_date'], $a['start_date'] );
							}
							return 0;
						}
					);
				}

				if ( ! empty( $associated_events ) ) {
					?>
					<style>
					.oras-speaker-contrib {
						margin: 2rem 0;
					}
					.oras-speaker-contrib > h2 {
						margin-bottom: 1.5rem;
						font-size: 1.5rem;
						font-weight: 600;
					}
					.oras-speaker-event {
						border: 1px solid #e5e7eb;
						border-radius: 0.5rem;
						padding: 1.5rem;
						margin-bottom: 1rem;
						background: #fff;
					}
					.oras-speaker-event__title {
						margin: 0 0 0.5rem 0;
						font-size: 1.25rem;
						font-weight: 600;
					}
					.oras-speaker-event__title a {
						color: #1f2937;
						text-decoration: none;
					}
					.oras-speaker-event__title a:hover {
						color: #3b82f6;
					}
					.oras-speaker-event__meta {
						color: #6b7280;
						font-size: 0.875rem;
						margin-bottom: 1rem;
					}
					.oras-speaker-event__sessions {
						margin-top: 1rem;
					}
					.oras-speaker-session {
						margin-bottom: 1rem;
						padding-bottom: 1rem;
						border-bottom: 1px solid #f3f4f6;
					}
					.oras-speaker-session:last-child {
						border-bottom: none;
						margin-bottom: 0;
						padding-bottom: 0;
					}
					.oras-speaker-session__line {
						display: flex;
						justify-content: space-between;
						align-items: center;
						margin-bottom: 0.5rem;
					}
					.oras-speaker-session__title {
						font-weight: 500;
						color: #374151;
					}
					.oras-speaker-session__time {
						color: #6b7280;
						font-size: 0.875rem;
						font-weight: 500;
					}
					.oras-speaker-session__resources {
						margin-top: 0.5rem;
					}
					.oras-chip {
						display: inline-flex;
						align-items: center;
						padding: 0.25rem 0.75rem;
						margin: 0.125rem 0.25rem 0.125rem 0;
						font-size: 0.75rem;
						font-weight: 500;
						color: #374151;
						background: #f3f4f6;
						border-radius: 9999px;
						text-decoration: none;
						border: 1px solid #e5e7eb;
					}
					.oras-chip:hover {
						background: #e5e7eb;
						color: #1f2937;
					}
					.oras-speaker-no-agenda {
						color: #6b7280;
						font-style: italic;
						margin: 1rem 0;
					}
					</style>
					<section class="oras-speaker-contrib">
						<h2><?php echo esc_html( sanitize_text_field( 'ORAS Contributions' ) ); ?></h2>
						<?php foreach ( $associated_events as $event ) : ?>
							<article class="oras-speaker-event">
								<h3 class="oras-speaker-event__title">
									<a href="<?php echo esc_url( get_permalink( $event['id'] ) ); ?>">
										<?php echo esc_html( $event['title'] ); ?>
									</a>
								</h3>
								<?php if ( '' !== $event['start_date'] ) : ?>
									<div class="oras-speaker-event__meta"><?php echo esc_html( $event['start_date'] ); ?></div>
								<?php endif; ?>

								<?php
								// Get agenda slots for this speaker from the event
								$agenda = get_post_meta( $event['id'], '_oras_agenda_v1', true );
								$speaker_slots = array();

								if ( is_array( $agenda ) && 1 === (int) ( $agenda['version'] ?? 0 ) && isset( $agenda['days'] ) && is_array( $agenda['days'] ) ) {
									foreach ( $agenda['days'] as $day ) {
										if ( isset( $day['slots'] ) && is_array( $day['slots'] ) ) {
											foreach ( $day['slots'] as $slot ) {
												if ( isset( $slot['speakers'] ) && is_array( $slot['speakers'] ) ) {
													foreach ( $slot['speakers'] as $slot_speaker ) {
														if ( absint( $slot_speaker['speaker_id'] ?? 0 ) === $speaker_id ) {
															$speaker_slots[] = $slot;
															break;
														}
													}
												}
											}
										}
									}

									// Sort slots by start time
									usort(
										$speaker_slots,
										function ( $a, $b ) {
											$a_start = sanitize_text_field( $a['start'] ?? '' );
											$b_start = sanitize_text_field( $b['start'] ?? '' );
											if ( $a_start && $b_start ) {
												return strcmp( $a_start, $b_start );
											}
											return 0;
										}
									);
								}

								if ( ! empty( $speaker_slots ) ) :
									?>
									<div class="oras-speaker-event__sessions">
										<?php foreach ( $speaker_slots as $slot ) : ?>
											<div class="oras-speaker-session">
												<?php
												$slot_title = sanitize_text_field( $slot['title'] ?? '' );
												$slot_start = sanitize_text_field( $slot['start'] ?? '' );
												$slot_end = sanitize_text_field( $slot['end'] ?? '' );
												?>
												<div class="oras-speaker-session__line">
													<span class="oras-speaker-session__title"><?php echo esc_html( $slot_title ); ?></span>
													<?php if ( '' !== $slot_start || '' !== $slot_end ) : ?>
														<span class="oras-speaker-session__time">
															<?php
															if ( '' !== $slot_start && '' !== $slot_end ) {
																echo esc_html( $slot_start . '–' . $slot_end );
															} elseif ( '' !== $slot_start ) {
																echo esc_html( $slot_start );
															} elseif ( '' !== $slot_end ) {
																echo esc_html( $slot_end );
															}
															?>
														</span>
													<?php endif; ?>
												</div>

												<?php if ( isset( $slot['resources'] ) && is_array( $slot['resources'] ) && ! empty( $slot['resources'] ) ) : ?>
													<div class="oras-speaker-session__resources">
														<?php
														foreach ( $slot['resources'] as $resource ) {
															$visibility = sanitize_text_field( $resource['visibility'] ?? 'public' );
															if ( 'internal' === $visibility && ! is_user_logged_in() ) {
																continue;
															}

															$url = '';
															if ( isset( $resource['attachment_id'] ) && absint( $resource['attachment_id'] ) > 0 ) {
																$url = wp_get_attachment_url( absint( $resource['attachment_id'] ) );
															} elseif ( isset( $resource['url'] ) ) {
																$url = esc_url_raw( $resource['url'] );
															}

															if ( empty( $url ) ) {
																continue;
															}

															$label = sanitize_text_field( $resource['label'] ?? '' );
															if ( empty( $label ) ) {
																$parsed_url = wp_parse_url( $url, PHP_URL_PATH );
																$label = $parsed_url ? basename( $parsed_url ) : '';
															}

															if ( empty( $label ) ) {
																continue;
															}
															?>
															<a class="oras-chip" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
																<?php echo esc_html( $label ); ?>
															</a>
															<?php
														}
														?>
													</div>
												<?php endif; ?>
											</div>
										<?php endforeach; ?>
									</div>
								<?php else : ?>
									<p class="oras-speaker-no-agenda"><?php echo esc_html( sanitize_text_field( 'Speaker listed for this event (agenda details not available).' ) ); ?></p>
								<?php endif; ?>
							</article>
						<?php endforeach; ?>
					</section>
					<?php
				}
				?>

				<?php
				// Phase 4.6.2: Speaker Resources Archive
				$speaker_history = get_post_meta( get_the_ID(), '_oras_speaker_history_v1', true );
				$has_resources = false;

				if ( is_array( $speaker_history ) && isset( $speaker_history['version'] ) && 1 === (int) $speaker_history['version'] && isset( $speaker_history['events'] ) && is_array( $speaker_history['events'] ) ) {
					foreach ( $speaker_history['events'] as $event ) {
						if ( isset( $event['slots'] ) && is_array( $event['slots'] ) ) {
							foreach ( $event['slots'] as $slot ) {
								if ( isset( $slot['resources'] ) && is_array( $slot['resources'] ) && ! empty( $slot['resources'] ) ) {
									$has_resources = true;
									break 2;
								}
							}
						}
					}

					if ( $has_resources ) {
						?>
					<section class="oras-speaker-resources-archive">
						<h2><?php echo esc_html( sanitize_text_field( 'Resources Archive' ) ); ?></h2>
						<?php
						foreach ( $speaker_history['events'] as $event ) {
							if ( ! isset( $event['slots'] ) || ! is_array( $event['slots'] ) ) {
								continue;
							}

							$event_has_resources = false;
							foreach ( $event['slots'] as $slot ) {
								if ( isset( $slot['resources'] ) && is_array( $slot['resources'] ) && ! empty( $slot['resources'] ) ) {
									$event_has_resources = true;
									break;
								}
							}

							if ( ! $event_has_resources ) {
								continue;
							}

							$event_title = isset( $event['event_title'] ) ? sanitize_text_field( $event['event_title'] ) : '';
							$event_start_date = isset( $event['event_start_date'] ) ? sanitize_text_field( $event['event_start_date'] ) : '';
							?>
							<div class="oras-speaker-event-resources">
								<h3><?php echo esc_html( $event_title ); ?></h3>
								<?php if ( '' !== $event_start_date ) : ?>
									<p class="oras-speaker-event-date"><?php echo esc_html( $event_start_date ); ?></p>
								<?php endif; ?>

								<?php
								foreach ( $event['slots'] as $slot ) {
									if ( ! isset( $slot['resources'] ) || ! is_array( $slot['resources'] ) || empty( $slot['resources'] ) ) {
										continue;
									}

									$slot_title = isset( $slot['slot_title'] ) ? sanitize_text_field( $slot['slot_title'] ) : '';
									$slot_start = isset( $slot['slot_start'] ) ? sanitize_text_field( $slot['slot_start'] ) : '';
									$slot_end = isset( $slot['slot_end'] ) ? sanitize_text_field( $slot['slot_end'] ) : '';
									?>
									<div class="oras-speaker-slot-resources">
										<h4><?php echo esc_html( $slot_title ); ?></h4>
										<?php if ( '' !== $slot_start || '' !== $slot_end ) : ?>
											<p class="oras-speaker-slot-time">
												<?php
												if ( '' !== $slot_start && '' !== $slot_end ) {
													echo esc_html( $slot_start . ' - ' . $slot_end );
												} elseif ( '' !== $slot_start ) {
													echo esc_html( $slot_start );
												} elseif ( '' !== $slot_end ) {
													echo esc_html( $slot_end );
												}
												?>
											</p>
										<?php endif; ?>

										<ul class="oras-speaker-resource-links">
											<?php
											foreach ( $slot['resources'] as $resource ) {
												$visibility = isset( $resource['visibility'] ) ? sanitize_text_field( $resource['visibility'] ) : 'public';
												if ( 'internal' === $visibility && ! is_user_logged_in() ) {
													continue;
												}

												$label = isset( $resource['label'] ) ? sanitize_text_field( $resource['label'] ) : '';
												$url = '';

												if ( isset( $resource['attachment_id'] ) && absint( $resource['attachment_id'] ) > 0 ) {
													$url = wp_get_attachment_url( absint( $resource['attachment_id'] ) );
												} elseif ( isset( $resource['url'] ) ) {
													$url = sanitize_text_field( $resource['url'] );
												}

												if ( empty( $url ) ) {
													continue;
												}

												if ( empty( $label ) ) {
													$label = basename( $url );
												}
												?>
												<li>
													<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
														<?php echo esc_html( $label ); ?>
													</a>
												</li>
												<?php
											}
											?>
										</ul>
									</div>
									<?php
								}
								?>
							</div>
							<?php
						}
						?>
					</section>
						<?php
					}
				}
				?>
		</main>
		<?php
	endwhile;
endif;

get_footer();
