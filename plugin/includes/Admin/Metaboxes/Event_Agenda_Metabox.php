<?php

namespace ORAS\Tickets\Admin\Metaboxes;

if (! defined('ABSPATH')) {
	exit;
}

final class Event_Agenda_Metabox
{

	private const META_KEY     = '_oras_agenda_v1';
	private const NONCE_ACTION = 'oras_agenda_metabox';
	private const NONCE_NAME   = 'oras_agenda_metabox_nonce';

	public static function register(): void
	{
		add_action('add_meta_boxes', array(self::class, 'add_metabox'));
		add_action('save_post_tribe_events', array(self::class, 'save'), 10, 1);
	}

	public static function add_metabox(): void
	{
		add_meta_box(
			'oras_event_agenda_metabox',
			__('Agenda', 'oras-tickets'),
			array(self::class, 'render'),
			'tribe_events',
			'normal',
			'default'
		);
	}

	public static function render(\WP_Post $post): void
	{
		if (! current_user_can('edit_post', $post->ID)) {
			return;
		}

		$envelope = get_post_meta($post->ID, self::META_KEY, true);
		if (! is_array($envelope)) {
			$envelope = array();
		}

		$settings             = isset($envelope['settings']) && is_array($envelope['settings']) ? $envelope['settings'] : array();
		$days                 = isset($envelope['days']) && is_array($envelope['days']) ? array_values($envelope['days']) : array();
		$available_speakers   = get_posts(
			array(
				'post_type'        => 'oras_speaker',
				'post_status'      => 'publish',
				'numberposts'      => 500,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		$speaker_options_html = self::render_speaker_options($available_speakers, 0);
		if (empty($days)) {
			$days[] = array(
				'day_label' => '',
				'date'      => '',
				'slots'     => array(),
			);
		}

		$enabled            = isset($settings['enabled']) ? (bool) $settings['enabled'] : false;
		$agenda_title       = isset($settings['title']) && $settings['title'] !== '' ? (string) $settings['title'] : 'Agenda';
		$highlight_current  = isset($settings['highlight_current']) ? (bool) $settings['highlight_current'] : false;
		$autoscroll_current = isset($settings['autoscroll_current']) ? (bool) $settings['autoscroll_current'] : false;

		wp_enqueue_media();
		wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

?>
		<div id="oras-agenda-metabox">
			<p>
				<label>
					<input type="checkbox" name="oras_agenda[settings][enabled]" value="1" <?php checked($enabled); ?> />
					<?php echo esc_html__('Enable agenda', 'oras-tickets'); ?>
				</label>
			</p>

			<p>
				<label for="oras-agenda-title"><strong><?php echo esc_html__('Agenda title', 'oras-tickets'); ?></strong></label><br />
				<input type="text" id="oras-agenda-title" class="widefat" name="oras_agenda[settings][title]" value="<?php echo esc_attr($agenda_title); ?>" placeholder="Agenda" />
			</p>

			<p>
				<label>
					<input type="checkbox" name="oras_agenda[settings][highlight_current]" value="1" <?php checked($highlight_current); ?> />
					<?php echo esc_html__('Highlight current agenda slot', 'oras-tickets'); ?>
				</label>
			</p>

			<p>
				<label>
					<input type="checkbox" name="oras_agenda[settings][autoscroll_current]" value="1" <?php checked($autoscroll_current); ?> />
					<?php echo esc_html__('Auto-scroll to current slot', 'oras-tickets'); ?>
				</label>
			</p>

			<div id="oras-agenda-days">
				<?php foreach ($days as $day_index => $day) : ?>
					<?php
					if (! is_array($day)) {
						continue;
					}
					$day_label = isset($day['day_label']) ? (string) $day['day_label'] : '';
					$date      = isset($day['date']) ? (string) $day['date'] : '';
					$date      = self::sanitize_day_date($date);
					$slots     = isset($day['slots']) && is_array($day['slots']) ? $day['slots'] : array();
					?>
					<div class="oras-agenda-day" data-day-index="<?php echo esc_attr((string) $day_index); ?>" style="margin:16px 0;padding:12px;border:1px solid #ddd;">
						<p>
							<label><strong><?php echo esc_html__('Day label', 'oras-tickets'); ?></strong></label><br />
							<input type="text" class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][day_label]" value="<?php echo esc_attr($day_label); ?>" />
						</p>
						<p>
							<label><strong><?php echo esc_html__('Date', 'oras-tickets'); ?></strong></label><br />
							<input type="date" class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][date]" value="<?php echo esc_attr($date); ?>" />
						</p>

						<table class="widefat striped oras-agenda-slots-table">
							<thead>
								<tr>
									<th><?php echo esc_html__('Start', 'oras-tickets'); ?></th>
									<th><?php echo esc_html__('End', 'oras-tickets'); ?></th>
									<th><?php echo esc_html__('Title', 'oras-tickets'); ?></th>
									<th><?php echo esc_html__('Description', 'oras-tickets'); ?></th>
									<th><?php echo esc_html__('Type', 'oras-tickets'); ?></th>
									<th><?php echo esc_html__('Location', 'oras-tickets'); ?></th>
									<th><?php echo esc_html__('Visibility', 'oras-tickets'); ?></th>
									<th><?php echo esc_html__('Actions', 'oras-tickets'); ?></th>
								</tr>
							</thead>
							<tbody class="oras-agenda-slots-body">
								<?php foreach ($slots as $slot_index => $slot) : ?>
									<?php
									if (! is_array($slot)) {
										continue;
									}
									$start         = isset($slot['start']) ? (string) $slot['start'] : '';
									$end           = isset($slot['end']) ? (string) $slot['end'] : '';
									$start_hm      = self::normalize_time_to_hm($start);
									$end_hm        = self::normalize_time_to_hm($end);
									$title         = isset($slot['title']) ? (string) $slot['title'] : '';
									$desc          = isset($slot['desc']) ? (string) $slot['desc'] : '';
									$type          = isset($slot['type']) ? (string) $slot['type'] : 'talk';
									$location      = isset($slot['location']) ? (string) $slot['location'] : '';
									$visibility    = isset($slot['visibility']) ? (string) $slot['visibility'] : 'public';
									$slot_speakers = isset($slot['speakers']) && is_array($slot['speakers']) ? $slot['speakers'] : array();
									?>
									<tr class="oras-agenda-slot-row" data-slot-index="<?php echo esc_attr((string) $slot_index); ?>">
										<td><input type="time" class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][start]" value="<?php echo esc_attr($start_hm); ?>" /></td>
										<td><input type="time" class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][end]" value="<?php echo esc_attr($end_hm); ?>" /></td>
										<td><input type="text" class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][title]" value="<?php echo esc_attr($title); ?>" /></td>
										<td>
											<textarea class="widefat" rows="2" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][desc]"><?php echo esc_textarea($desc); ?></textarea>
											<div class="oras-agenda-speakers" style="margin-top:8px;">
												<strong><?php echo esc_html__('Speakers', 'oras-tickets'); ?></strong>
												<div class="oras-agenda-speakers-rows" style="margin-top:6px;">
													<?php foreach ($slot_speakers as $speaker_index => $speaker_row) : ?>
														<?php
														if (! is_array($speaker_row)) {
															continue;
														}
														$speaker_id    = isset($speaker_row['speaker_id']) ? absint($speaker_row['speaker_id']) : 0;
														$speaker_label = isset($speaker_row['label']) ? (string) $speaker_row['label'] : '';
														$speaker_role  = isset($speaker_row['role']) ? (string) $speaker_row['role'] : '';
														?>
														<div class="oras-agenda-speaker-row" data-speaker-index="<?php echo esc_attr((string) $speaker_index); ?>" style="margin:6px 0;padding:6px;border:1px solid #ddd;">
															<select class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][speakers][<?php echo esc_attr((string) $speaker_index); ?>][speaker_id]">
																<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
																?>
																<?php echo self::render_speaker_options($available_speakers, $speaker_id); ?>
															</select>
															<input type="text" class="widefat" style="margin-top:6px;" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][speakers][<?php echo esc_attr((string) $speaker_index); ?>][label]" value="<?php echo esc_attr($speaker_label); ?>" placeholder="<?php echo esc_attr__('Label override', 'oras-tickets'); ?>" />
															<input type="text" class="widefat" style="margin-top:6px;" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][speakers][<?php echo esc_attr((string) $speaker_index); ?>][role]" value="<?php echo esc_attr($speaker_role); ?>" placeholder="<?php echo esc_attr__('Role', 'oras-tickets'); ?>" />
															<button type="button" class="button oras-agenda-remove-speaker" style="margin-top:6px;"><?php echo esc_html__('Remove Speaker', 'oras-tickets'); ?></button>
														</div>
													<?php endforeach; ?>
												</div>
												<button type="button" class="button oras-agenda-add-speaker" style="margin-top:6px;"><?php echo esc_html__('Add Speaker', 'oras-tickets'); ?></button>
											</div>
											<div class="oras-agenda-resources" style="margin-top:8px;">
												<strong><?php echo esc_html__('Resources', 'oras-tickets'); ?></strong>
												<div class="oras-agenda-resources-rows" style="margin-top:6px;">
													<?php
													$slot_resources = isset($slot['resources']) && is_array($slot['resources']) ? $slot['resources'] : array();
													foreach ($slot_resources as $resource_index => $resource) :
														if (! is_array($resource)) {
															continue;
														}
														$attachment_id       = isset($resource['attachment_id']) ? absint($resource['attachment_id']) : 0;
														$url                 = isset($resource['url']) ? (string) $resource['url'] : '';
														$label               = isset($resource['label']) ? (string) $resource['label'] : '';
														$type                = isset($resource['type']) ? (string) $resource['type'] : 'link';
														$visibility          = isset($resource['visibility']) ? (string) $resource['visibility'] : 'public';
														$resource_speaker_ids = isset($resource['speaker_ids']) && is_array($resource['speaker_ids']) ? $resource['speaker_ids'] : array();
														$filename            = '';
														if ($attachment_id > 0) {
															$attachment = get_post($attachment_id);
															if ($attachment) {
																$filename = $attachment->post_title;
															}
														}
													?>
														<div class="oras-agenda-resource-row" data-resource-index="<?php echo esc_attr((string) $resource_index); ?>" style="margin:6px 0;padding:6px;border:1px solid #ddd;">
															<p>
																<label><?php echo esc_html__('File', 'oras-tickets'); ?></label><br />
																<input type="hidden" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][resources][<?php echo esc_attr((string) $resource_index); ?>][attachment_id]" value="<?php echo esc_attr((string) $attachment_id); ?>" />
																<button type="button" class="button oras-agenda-select-file"><?php echo esc_html__('Select File', 'oras-tickets'); ?></button>
																<span class="oras-agenda-file-name"><?php echo esc_html($filename); ?></span>
															</p>
															<p>
																<label><?php echo esc_html__('External URL', 'oras-tickets'); ?></label><br />
																<input type="url" class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][resources][<?php echo esc_attr((string) $resource_index); ?>][url]" value="<?php echo esc_attr($url); ?>" />
															</p>
															<p>
																<label><?php echo esc_html__('Label', 'oras-tickets'); ?></label><br />
																<input type="text" class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][resources][<?php echo esc_attr((string) $resource_index); ?>][label]" value="<?php echo esc_attr($label); ?>" />
															</p>
															<p>
																<label><?php echo esc_html__('Type', 'oras-tickets'); ?></label><br />
																<select class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][resources][<?php echo esc_attr((string) $resource_index); ?>][type]">
																	<option value="slides" <?php selected($type, 'slides'); ?>><?php echo esc_html__('Slides', 'oras-tickets'); ?></option>
																	<option value="handout" <?php selected($type, 'handout'); ?>><?php echo esc_html__('Handout', 'oras-tickets'); ?></option>
																	<option value="video" <?php selected($type, 'video'); ?>><?php echo esc_html__('Video', 'oras-tickets'); ?></option>
																	<option value="link" <?php selected($type, 'link'); ?>><?php echo esc_html__('Link', 'oras-tickets'); ?></option>
																</select>
															</p>
															<p>
																<label><?php echo esc_html__('Visibility', 'oras-tickets'); ?></label><br />
																<select class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][resources][<?php echo esc_attr((string) $resource_index); ?>][visibility]">
																	<option value="public" <?php selected($visibility, 'public'); ?>><?php echo esc_html__('Public', 'oras-tickets'); ?></option>
																	<option value="internal" <?php selected($visibility, 'internal'); ?>><?php echo esc_html__('Internal', 'oras-tickets'); ?></option>
																</select>
															</p>
															<p>
																<label><?php echo esc_html__('Associated Speakers', 'oras-tickets'); ?></label><br />
																<select multiple class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][resources][<?php echo esc_attr((string) $resource_index); ?>][speaker_ids][]">
																	<?php foreach ($available_speakers as $speaker) : ?>
																		<option value="<?php echo esc_attr((string) $speaker->ID); ?>" <?php selected(in_array($speaker->ID, $resource_speaker_ids)); ?>><?php echo esc_html($speaker->post_title); ?></option>
																	<?php endforeach; ?>
																</select>
															</p>
															<button type="button" class="button oras-agenda-remove-resource" style="margin-top:6px;"><?php echo esc_html__('Remove Resource', 'oras-tickets'); ?></button>
														</div>
													<?php endforeach; ?>
												</div>
												<button type="button" class="button oras-agenda-add-resource" style="margin-top:6px;"><?php echo esc_html__('Add Resource', 'oras-tickets'); ?></button>
											</div>
										</td>
										<td>
											<select class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][type]">
												<?php foreach (self::allowed_types() as $allowed_type) : ?>
													<option value="<?php echo esc_attr($allowed_type); ?>" <?php selected($type, $allowed_type); ?>><?php echo esc_html($allowed_type); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><input type="text" class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][location]" value="<?php echo esc_attr($location); ?>" /></td>
										<td>
											<select class="widefat" name="oras_agenda[days][<?php echo esc_attr((string) $day_index); ?>][slots][<?php echo esc_attr((string) $slot_index); ?>][visibility]">
												<?php foreach (self::allowed_visibility() as $allowed_visibility) : ?>
													<option value="<?php echo esc_attr($allowed_visibility); ?>" <?php selected($visibility, $allowed_visibility); ?>><?php echo esc_html($allowed_visibility); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><button type="button" class="button oras-agenda-remove-slot"><?php echo esc_html__('Remove', 'oras-tickets'); ?></button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<p>
							<button type="button" class="button oras-agenda-add-slot"><?php echo esc_html__('Add Slot', 'oras-tickets'); ?></button>
							<button type="button" class="button oras-agenda-remove-day"><?php echo esc_html__('Remove Day', 'oras-tickets'); ?></button>
						</p>
					</div>
				<?php endforeach; ?>
			</div>

			<p>
				<button type="button" class="button" id="oras-agenda-add-day"><?php echo esc_html__('Add Day', 'oras-tickets'); ?></button>
			</p>
		</div>

		<script>
			(function() {
				var daysContainer = document.getElementById('oras-agenda-days');
				var addDayButton = document.getElementById('oras-agenda-add-day');
				if (!daysContainer || !addDayButton) {
					return;
				}

				var types = <?php echo wp_json_encode(array_values(self::allowed_types())); ?>;
				var visibilities = <?php echo wp_json_encode(array_values(self::allowed_visibility())); ?>;
				var speakerOptionsHtml = <?php echo wp_json_encode($speaker_options_html); ?>;

				function options(values) {
					var out = '';
					for (var i = 0; i < values.length; i++) {
						out += '<option value="' + values[i] + '">' + values[i] + '</option>';
					}
					return out;
				}

				function slotRowHtml(dayIndex, slotIndex) {
					return '' +
						'<tr class="oras-agenda-slot-row" data-slot-index="' + slotIndex + '">' +
						'<td><input type="time" class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][start]" value="" /></td>' +
						'<td><input type="time" class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][end]" value="" /></td>' +
						'<td><input type="text" class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][title]" value="" /></td>' +
						'<td>' +
						'<textarea class="widefat" rows="2" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][desc]"></textarea>' +
						'<div class="oras-agenda-speakers" style="margin-top:8px;">' +
						'<strong><?php echo esc_js(__('Speakers', 'oras-tickets')); ?></strong>' +
						'<div class="oras-agenda-speakers-rows" style="margin-top:6px;"></div>' +
						'<button type="button" class="button oras-agenda-add-speaker" style="margin-top:6px;"><?php echo esc_js(__('Add Speaker', 'oras-tickets')); ?></button>' +
						'</div>' +
						'<div class="oras-agenda-resources" style="margin-top:8px;">' +
						'<strong><?php echo esc_js(__('Resources', 'oras-tickets')); ?></strong>' +
						'<div class="oras-agenda-resources-rows" style="margin-top:6px;"></div>' +
						'<button type="button" class="button oras-agenda-add-resource" style="margin-top:6px;"><?php echo esc_js(__('Add Resource', 'oras-tickets')); ?></button>' +
						'</div>' +
						'</td>' +
						'<td><select class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][type]">' + options(types) + '</select></td>' +
						'<td><input type="text" class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][location]" value="" /></td>' +
						'<td><select class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][visibility]">' + options(visibilities) + '</select></td>' +
						'<td><button type="button" class="button oras-agenda-remove-slot"><?php echo esc_js(__('Remove', 'oras-tickets')); ?></button></td>' +
						'</tr>';
				}

				function speakerRowHtml(dayIndex, slotIndex, speakerIndex) {
					return '' +
						'<div class="oras-agenda-speaker-row" data-speaker-index="' + speakerIndex + '" style="margin:6px 0;padding:6px;border:1px solid #ddd;">' +
						'<select class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][speakers][' + speakerIndex + '][speaker_id]">' + speakerOptionsHtml + '</select>' +
						'<input type="text" class="widefat" style="margin-top:6px;" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][speakers][' + speakerIndex + '][label]" value="" placeholder="<?php echo esc_js(__('Label override', 'oras-tickets')); ?>" />' +
						'<input type="text" class="widefat" style="margin-top:6px;" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][speakers][' + speakerIndex + '][role]" value="" placeholder="<?php echo esc_js(__('Role', 'oras-tickets')); ?>" />' +
						'<button type="button" class="button oras-agenda-remove-speaker" style="margin-top:6px;"><?php echo esc_js(__('Remove Speaker', 'oras-tickets')); ?></button>' +
						'</div>';
				}

				function resourceRowHtml(dayIndex, slotIndex, resourceIndex) {
					return '' +
						'<div class="oras-agenda-resource-row" data-resource-index="' + resourceIndex + '" style="margin:6px 0;padding:6px;border:1px solid #ddd;">' +
						'<p><label><?php echo esc_js(__('File', 'oras-tickets')); ?></label><br />' +
						'<input type="hidden" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][resources][' + resourceIndex + '][attachment_id]" value="" />' +
						'<button type="button" class="button oras-agenda-select-file"><?php echo esc_js(__('Select File', 'oras-tickets')); ?></button>' +
						'<span class="oras-agenda-file-name"></span></p>' +
						'<p><label><?php echo esc_js(__('External URL', 'oras-tickets')); ?></label><br />' +
						'<input type="url" class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][resources][' + resourceIndex + '][url]" value="" /></p>' +
						'<p><label><?php echo esc_js(__('Label', 'oras-tickets')); ?></label><br />' +
						'<input type="text" class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][resources][' + resourceIndex + '][label]" value="" /></p>' +
						'<p><label><?php echo esc_js(__('Type', 'oras-tickets')); ?></label><br />' +
						'<select class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][resources][' + resourceIndex + '][type]">' +
						'<option value="slides"><?php echo esc_js(__('Slides', 'oras-tickets')); ?></option>' +
						'<option value="handout"><?php echo esc_js(__('Handout', 'oras-tickets')); ?></option>' +
						'<option value="video"><?php echo esc_js(__('Video', 'oras-tickets')); ?></option>' +
						'<option value="link"><?php echo esc_js(__('Link', 'oras-tickets')); ?></option>' +
						'</select></p>' +
						'<p><label><?php echo esc_js(__('Visibility', 'oras-tickets')); ?></label><br />' +
						'<select class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][resources][' + resourceIndex + '][visibility]">' +
						'<option value="public"><?php echo esc_js(__('Public', 'oras-tickets')); ?></option>' +
						'<option value="internal"><?php echo esc_js(__('Internal', 'oras-tickets')); ?></option>' +
						'</select></p>' +
						'<p><label><?php echo esc_js(__('Associated Speakers', 'oras-tickets')); ?></label><br />' +
						'<select multiple class="widefat" name="oras_agenda[days][' + dayIndex + '][slots][' + slotIndex + '][resources][' + resourceIndex + '][speaker_ids][]">' + speakerOptionsHtml + '</select></p>' +
						'<button type="button" class="button oras-agenda-remove-resource" style="margin-top:6px;"><?php echo esc_js(__('Remove Resource', 'oras-tickets')); ?></button>' +
						'</div>';
				}

				function dayHtml(dayIndex) {
					return '' +
						'<div class="oras-agenda-day" data-day-index="' + dayIndex + '" style="margin:16px 0;padding:12px;border:1px solid #ddd;">' +
						'<p><label><strong><?php echo esc_js(__('Day label', 'oras-tickets')); ?></strong></label><br />' +
						'<input type="text" class="widefat" name="oras_agenda[days][' + dayIndex + '][day_label]" value="" /></p>' +
						'<p><label><strong><?php echo esc_js(__('Date', 'oras-tickets')); ?></strong></label><br />' +
						'<input type="date" class="widefat" name="oras_agenda[days][' + dayIndex + '][date]" value="" /></p>' +
						'<table class="widefat striped oras-agenda-slots-table">' +
						'<thead><tr>' +
						'<th><?php echo esc_js(__('Start', 'oras-tickets')); ?></th>' +
						'<th><?php echo esc_js(__('End', 'oras-tickets')); ?></th>' +
						'<th><?php echo esc_js(__('Title', 'oras-tickets')); ?></th>' +
						'<th><?php echo esc_js(__('Description', 'oras-tickets')); ?></th>' +
						'<th><?php echo esc_js(__('Type', 'oras-tickets')); ?></th>' +
						'<th><?php echo esc_js(__('Location', 'oras-tickets')); ?></th>' +
						'<th><?php echo esc_js(__('Visibility', 'oras-tickets')); ?></th>' +
						'<th><?php echo esc_js(__('Actions', 'oras-tickets')); ?></th>' +
						'</tr></thead>' +
						'<tbody class="oras-agenda-slots-body"></tbody>' +
						'</table>' +
						'<p>' +
						'<button type="button" class="button oras-agenda-add-slot"><?php echo esc_js(__('Add Slot', 'oras-tickets')); ?></button> ' +
						'<button type="button" class="button oras-agenda-remove-day"><?php echo esc_js(__('Remove Day', 'oras-tickets')); ?></button>' +
						'</p>' +
						'</div>';
				}

				function nextDayIndex() {
					var max = -1;
					var days = daysContainer.querySelectorAll('.oras-agenda-day');
					for (var i = 0; i < days.length; i++) {
						var current = parseInt(days[i].getAttribute('data-day-index'), 10);
						if (!isNaN(current) && current > max) {
							max = current;
						}
					}
					return max + 1;
				}

				function nextSlotIndex(dayElement) {
					var max = -1;
					var rows = dayElement.querySelectorAll('tr.oras-agenda-slot-row');
					for (var i = 0; i < rows.length; i++) {
						var current = parseInt(rows[i].getAttribute('data-slot-index'), 10);
						if (!isNaN(current) && current > max) {
							max = current;
						}
					}
					return max + 1;
				}

				function nextSpeakerIndex(slotRow) {
					var max = -1;
					var rows = slotRow.querySelectorAll('.oras-agenda-speaker-row');
					for (var i = 0; i < rows.length; i++) {
						var current = parseInt(rows[i].getAttribute('data-speaker-index'), 10);
						if (!isNaN(current) && current > max) {
							max = current;
						}
					}
					return max + 1;
				}

				function nextResourceIndex(slotRow) {
					var max = -1;
					var rows = slotRow.querySelectorAll('.oras-agenda-resource-row');
					for (var i = 0; i < rows.length; i++) {
						var current = parseInt(rows[i].getAttribute('data-resource-index'), 10);
						if (!isNaN(current) && current > max) {
							max = current;
						}
					}
					return max + 1;
				}

				addDayButton.addEventListener('click', function() {
					var dayIndex = nextDayIndex();
					daysContainer.insertAdjacentHTML('beforeend', dayHtml(dayIndex));
				});

				daysContainer.addEventListener('click', function(event) {
					var target = event.target;
					if (!target) {
						return;
					}

					if (target.classList.contains('oras-agenda-remove-slot')) {
						var row = target.closest('tr.oras-agenda-slot-row');
						if (row) {
							row.remove();
						}
						return;
					}

					if (target.classList.contains('oras-agenda-add-slot')) {
						var dayElement = target.closest('.oras-agenda-day');
						if (!dayElement) {
							return;
						}
						var dayIndex = dayElement.getAttribute('data-day-index');
						var slotsBody = dayElement.querySelector('.oras-agenda-slots-body');
						if (!slotsBody) {
							return;
						}
						var slotIndex = nextSlotIndex(dayElement);
						slotsBody.insertAdjacentHTML('beforeend', slotRowHtml(dayIndex, slotIndex));
						return;
					}

					if (target.classList.contains('oras-agenda-add-speaker')) {
						var slotRow = target.closest('tr.oras-agenda-slot-row');
						if (!slotRow) {
							return;
						}
						var dayElement = target.closest('.oras-agenda-day');
						if (!dayElement) {
							return;
						}
						var dayIndex = dayElement.getAttribute('data-day-index');
						var slotIndex = slotRow.getAttribute('data-slot-index');
						var speakersRows = slotRow.querySelector('.oras-agenda-speakers-rows');
						if (!speakersRows) {
							return;
						}
						var speakerIndex = nextSpeakerIndex(slotRow);
						speakersRows.insertAdjacentHTML('beforeend', speakerRowHtml(dayIndex, slotIndex, speakerIndex));
						return;
					}

					if (target.classList.contains('oras-agenda-remove-speaker')) {
						var speakerRow = target.closest('.oras-agenda-speaker-row');
						if (speakerRow) {
							speakerRow.remove();
						}
						return;
					}

					if (target.classList.contains('oras-agenda-add-resource')) {
						var slotRow = target.closest('tr.oras-agenda-slot-row');
						if (!slotRow) {
							return;
						}
						var dayElement = target.closest('.oras-agenda-day');
						if (!dayElement) {
							return;
						}
						var dayIndex = dayElement.getAttribute('data-day-index');
						var slotIndex = slotRow.getAttribute('data-slot-index');
						var resourcesRows = slotRow.querySelector('.oras-agenda-resources-rows');
						if (!resourcesRows) {
							return;
						}
						var resourceIndex = nextResourceIndex(slotRow);
						resourcesRows.insertAdjacentHTML('beforeend', resourceRowHtml(dayIndex, slotIndex, resourceIndex));
						return;
					}

					if (target.classList.contains('oras-agenda-remove-resource')) {
						var resourceRow = target.closest('.oras-agenda-resource-row');
						if (resourceRow) {
							resourceRow.remove();
						}
						return;
					}

					if (target.classList.contains('oras-agenda-select-file')) {
						var resourceRow = target.closest('.oras-agenda-resource-row');
						if (!resourceRow) {
							return;
						}
						var attachmentInput = resourceRow.querySelector('input[name*="[attachment_id]"]');
						var fileNameSpan = resourceRow.querySelector('.oras-agenda-file-name');
						if (!attachmentInput || !fileNameSpan) {
							return;
						}
						var mediaUploader = wp.media({
							title: '<?php echo esc_js(__('Select File', 'oras-tickets')); ?>',
							button: {
								text: '<?php echo esc_js(__('Use this file', 'oras-tickets')); ?>'
							},
							multiple: false
						});
						mediaUploader.on('select', function() {
							var attachment = mediaUploader.state().get('selection').first().toJSON();
							attachmentInput.value = attachment.id;
							fileNameSpan.textContent = attachment.title;
						});
						mediaUploader.open();
						return;
					}

					if (target.classList.contains('oras-agenda-remove-day')) {
						var day = target.closest('.oras-agenda-day');
						if (day) {
							day.remove();
						}
					}
				});
			})();
		</script>
<?php
	}

	public static function save(int $post_id): void
	{
		if (! isset($_POST[self::NONCE_NAME])) {
			return;
		}

		$nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME]));
		if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$input              = isset($_POST['oras_agenda']) && is_array($_POST['oras_agenda']) ? $_POST['oras_agenda'] : array();
		$input_settings     = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : array();
		$enabled            = ! empty($input_settings['enabled']);
		$highlight_current  = ! empty($input_settings['highlight_current']);
		$autoscroll_current = ! empty($input_settings['autoscroll_current']);
		$title              = isset($input_settings['title']) ? sanitize_text_field(wp_unslash($input_settings['title'])) : 'Agenda';
		if ($title === '') {
			$title = 'Agenda';
		}

		$raw_days = isset($input['days']) && is_array($input['days']) ? $input['days'] : array();
		$days     = array();

		foreach ($raw_days as $raw_day) {
			if (! is_array($raw_day)) {
				continue;
			}

			$day_label = isset($raw_day['day_label']) ? sanitize_text_field(wp_unslash($raw_day['day_label'])) : '';
			$date      = isset($raw_day['date']) ? sanitize_text_field(wp_unslash($raw_day['date'])) : '';
			$date      = self::sanitize_day_date($date);

			$raw_slots = isset($raw_day['slots']) && is_array($raw_day['slots']) ? $raw_day['slots'] : array();
			$slots     = array();

			foreach ($raw_slots as $raw_slot) {
				if (! is_array($raw_slot)) {
					continue;
				}

				$start_raw    = isset($raw_slot['start']) ? sanitize_text_field(wp_unslash($raw_slot['start'])) : '';
				$end_raw      = isset($raw_slot['end']) ? sanitize_text_field(wp_unslash($raw_slot['end'])) : '';
				$start        = self::normalize_time_to_hm($start_raw);
				$end          = self::normalize_time_to_hm($end_raw);
				$slot_title   = isset($raw_slot['title']) ? sanitize_text_field(wp_unslash($raw_slot['title'])) : '';
				$desc         = isset($raw_slot['desc']) ? sanitize_textarea_field(wp_unslash($raw_slot['desc'])) : '';
				$type         = isset($raw_slot['type']) ? sanitize_text_field(wp_unslash($raw_slot['type'])) : 'talk';
				$location     = isset($raw_slot['location']) ? sanitize_text_field(wp_unslash($raw_slot['location'])) : '';
				$visibility   = isset($raw_slot['visibility']) ? sanitize_text_field(wp_unslash($raw_slot['visibility'])) : 'public';
				$raw_speakers = isset($raw_slot['speakers']) && is_array($raw_slot['speakers']) ? $raw_slot['speakers'] : array();
				$speakers     = array();

				if (! in_array($type, self::allowed_types(), true)) {
					$type = 'other';
				}

				if (! in_array($visibility, self::allowed_visibility(), true)) {
					$visibility = 'public';
				}

				if ($start === '' && $slot_title === '') {
					continue;
				}

				foreach ($raw_speakers as $raw_speaker) {
					if (! is_array($raw_speaker)) {
						continue;
					}

					$speaker_id = isset($raw_speaker['speaker_id']) ? absint($raw_speaker['speaker_id']) : 0;
					if ($speaker_id <= 0) {
						continue;
					}

					$speaker_label = isset($raw_speaker['label']) ? sanitize_text_field(wp_unslash($raw_speaker['label'])) : '';
					$speaker_role  = isset($raw_speaker['role']) ? sanitize_text_field(wp_unslash($raw_speaker['role'])) : '';

					$speaker_row = array(
						'speaker_id' => $speaker_id,
					);

					if ($speaker_label !== '') {
						$speaker_row['label'] = $speaker_label;
					}

					if ($speaker_role !== '') {
						$speaker_row['role'] = $speaker_role;
					}

					$speakers[] = $speaker_row;
				}

				$raw_resources = isset($raw_slot['resources']) && is_array($raw_slot['resources']) ? $raw_slot['resources'] : array();
				$resources     = array();

				foreach ($raw_resources as $raw_resource) {
					if (! is_array($raw_resource)) {
						continue;
					}

					$attachment_id = isset($raw_resource['attachment_id']) ? absint($raw_resource['attachment_id']) : 0;
					$url           = isset($raw_resource['url']) ? esc_url_raw(wp_unslash($raw_resource['url'])) : '';
					$label         = isset($raw_resource['label']) ? sanitize_text_field(wp_unslash($raw_resource['label'])) : '';
					$type          = isset($raw_resource['type']) ? sanitize_text_field(wp_unslash($raw_resource['type'])) : 'link';
					$visibility    = isset($raw_resource['visibility']) ? sanitize_text_field(wp_unslash($raw_resource['visibility'])) : 'public';
					$raw_speaker_ids = isset($raw_resource['speaker_ids']) && is_array($raw_resource['speaker_ids']) ? $raw_resource['speaker_ids'] : array();
					$speaker_ids   = array_map('absint', $raw_speaker_ids);
					$speaker_ids   = array_filter($speaker_ids, function ($id) {
						return $id > 0;
					});
					$speaker_ids   = array_unique($speaker_ids);

					if (! in_array($type, array('slides', 'handout', 'video', 'link'), true)) {
						$type = 'link';
					}

					if (! in_array($visibility, array('public', 'internal'), true)) {
						$visibility = 'public';
					}

					if ($attachment_id <= 0 && $url === '') {
						continue;
					}

					$resource = array(
						'attachment_id' => $attachment_id,
						'url'           => $url,
						'label'         => $label,
						'type'          => $type,
						'visibility'    => $visibility,
						'speaker_ids'   => array_values($speaker_ids),
					);

					$resources[] = $resource;
				}

				$slot_data = array(
					'start'      => $start,
					'end'        => $end,
					'title'      => $slot_title,
					'desc'       => $desc,
					'type'       => $type,
					'location'   => $location,
					'visibility' => $visibility,
				);

				if (! empty($speakers)) {
					$slot_data['speakers'] = $speakers;
				}

				if (! empty($resources)) {
					$slot_data['resources'] = $resources;
				}

				$slots[] = $slot_data;
			}

			if (empty($slots)) {
				continue;
			}

			$days[] = array(
				'day_label' => $day_label,
				'date'      => $date,
				'slots'     => $slots,
			);
		}

		if (! $enabled && empty($days)) {
			delete_post_meta($post_id, self::META_KEY);
			return;
		}

		$envelope = array(
			'version'  => 1,
			'settings' => array(
				'enabled'               => $enabled,
				'title'                 => $title,
				'show_timezone_note'    => true,
				'show_end_times'        => true,
				'show_descriptions'     => true,
				'collapse_descriptions' => true,
				'show_headshots_list'   => false,
				'default_view'          => 'list',
				'highlight_current'     => $highlight_current,
				'autoscroll_current'    => $autoscroll_current,
			),
			'days'     => $days,
		);

		update_post_meta($post_id, self::META_KEY, $envelope);

		// Rebuild speaker history index
		self::rebuild_speaker_history_index($post_id, $envelope);
	}

	private static function allowed_types(): array
	{
		return array('talk', 'workshop', 'break', 'observation', 'social', 'other');
	}

	private static function allowed_visibility(): array
	{
		return array('public', 'hidden');
	}

	private static function render_speaker_options(array $speakers, int $selected_id): string
	{
		$html = '<option value="0">' . esc_html__('Select speaker', 'oras-tickets') . '</option>';

		foreach ($speakers as $speaker) {
			if (! $speaker instanceof \WP_Post) {
				continue;
			}

			$speaker_id = (int) $speaker->ID;
			$html      .= '<option value="' . esc_attr((string) $speaker_id) . '"' . selected($selected_id, $speaker_id, false) . '>' . esc_html($speaker->post_title) . '</option>';
		}

		return $html;
	}

	private static function normalize_time_to_hm(string $raw): string
	{
		$value = strtolower(trim($raw));
		if ($value === '') {
			return '';
		}

		$value = preg_replace('/\s+/', '', $value);
		if (! is_string($value) || $value === '') {
			return '';
		}

		if (preg_match('/^(\d{1,2}):(\d{2})(am|pm)?$/', $value, $matches) === 1) {
			$hour   = (int) $matches[1];
			$minute = (int) $matches[2];
			$suffix = isset($matches[3]) ? $matches[3] : '';

			if ($minute < 0 || $minute > 59) {
				return '';
			}

			if ($suffix === '') {
				if ($hour < 0 || $hour > 23) {
					return '';
				}

				return sprintf('%02d:%02d', $hour, $minute);
			}

			if ($hour < 1 || $hour > 12) {
				return '';
			}

			if ($suffix === 'am') {
				$hour = ($hour === 12) ? 0 : $hour;
			} else {
				$hour = ($hour === 12) ? 12 : $hour + 12;
			}

			return sprintf('%02d:%02d', $hour, $minute);
		}

		if (preg_match('/^(\d{1,2})(am|pm)$/', $value, $matches) === 1) {
			$hour   = (int) $matches[1];
			$suffix = $matches[2];

			if ($hour < 1 || $hour > 12) {
				return '';
			}

			if ($suffix === 'am') {
				$hour = ($hour === 12) ? 0 : $hour;
			} else {
				$hour = ($hour === 12) ? 12 : $hour + 12;
			}

			return sprintf('%02d:00', $hour);
		}

		return '';
	}

	private static function sanitize_day_date(string $date): string
	{
		if ($date === '') {
			return '';
		}

		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
	}

	private static function rebuild_speaker_history_index(int $event_id, array $envelope): void
	{
		$current_speaker_ids = self::collect_speaker_ids_from_envelope($envelope);
		$previously_affected = self::get_speakers_with_event_in_history($event_id);
		$affected_speaker_ids = array_unique(array_merge($current_speaker_ids, $previously_affected));

		foreach ($affected_speaker_ids as $speaker_id) {
			$is_current = in_array($speaker_id, $current_speaker_ids, true);
			self::rebuild_speaker_history($speaker_id, $event_id, $envelope, $is_current);
		}
	}

	private static function collect_speaker_ids_from_envelope(array $envelope): array
	{
		$speaker_ids = array();
		$days = isset($envelope['days']) && is_array($envelope['days']) ? $envelope['days'] : array();

		foreach ($days as $day) {
			if (! is_array($day)) {
				continue;
			}

			$slots = isset($day['slots']) && is_array($day['slots']) ? $day['slots'] : array();

			foreach ($slots as $slot) {
				if (! is_array($slot)) {
					continue;
				}

				$speakers = isset($slot['speakers']) && is_array($slot['speakers']) ? $slot['speakers'] : array();
				foreach ($speakers as $speaker) {
					if (! is_array($speaker)) {
						continue;
					}

					$id = isset($speaker['speaker_id']) ? absint($speaker['speaker_id']) : 0;
					if ($id > 0) {
						$speaker_ids[] = $id;
					}
				}

				$resources = isset($slot['resources']) && is_array($slot['resources']) ? $slot['resources'] : array();
				foreach ($resources as $resource) {
					if (! is_array($resource)) {
						continue;
					}

					$ids = isset($resource['speaker_ids']) && is_array($resource['speaker_ids']) ? $resource['speaker_ids'] : array();
					foreach ($ids as $id) {
						$id = absint($id);
						if ($id > 0) {
							$speaker_ids[] = $id;
						}
					}
				}
			}
		}

		return array_unique($speaker_ids);
	}

	private static function get_speakers_with_event_in_history(int $event_id): array
	{
		$speakers = get_posts(array(
			'post_type'   => 'oras_speaker',
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_query'  => array(
				array(
					'key'     => '_oras_speaker_history_v1',
					'compare' => 'EXISTS',
				),
			),
		));

		$affected = array();
		foreach ($speakers as $speaker) {
			$history = get_post_meta($speaker->ID, '_oras_speaker_history_v1', true);
			if (is_array($history) && isset($history['events']) && is_array($history['events'])) {
				foreach ($history['events'] as $event) {
					if (isset($event['event_id']) && (int) $event['event_id'] === $event_id) {
						$affected[] = $speaker->ID;
						break;
					}
				}
			}
		}

		return $affected;
	}

	private static function rebuild_speaker_history(int $speaker_id, int $event_id, array $envelope, bool $is_current): void
	{
		$history = get_post_meta($speaker_id, '_oras_speaker_history_v1', true);
		if (! is_array($history)) {
			$history = array();
		}

		$version = isset($history['version']) ? (int) $history['version'] : 1;
		if ($version !== 1) {
			$history = array('version' => 1, 'speaker_id' => $speaker_id, 'events' => array());
		}

		$events = isset($history['events']) && is_array($history['events']) ? $history['events'] : array();

		// Remove existing entry for this event
		$events = array_filter($events, function ($event) use ($event_id) {
			return (! isset($event['event_id'])) || ((int) $event['event_id'] !== $event_id);
		});

		if ($is_current) {
			$event_entry = self::build_event_entry_for_speaker($speaker_id, $event_id, $envelope);
			$events[] = $event_entry;
		}

		// Sort events by event_start_date desc
		usort($events, function ($a, $b) {
			return strcmp($b['event_start_date'] ?? '', $a['event_start_date'] ?? '');
		});

		$history['events'] = array_values($events);
		update_post_meta($speaker_id, '_oras_speaker_history_v1', $history);
	}

	private static function build_event_entry_for_speaker(int $speaker_id, int $event_id, array $envelope): array
	{
		$event_title = sanitize_text_field(get_the_title($event_id));
		$event_start_date = self::get_event_start_date($event_id);

		$slots = array();
		$days = isset($envelope['days']) && is_array($envelope['days']) ? $envelope['days'] : array();

		foreach ($days as $day) {
			if (! is_array($day)) {
				continue;
			}

			$day_date = isset($day['date']) ? sanitize_text_field($day['date']) : '';
			$day_slots = isset($day['slots']) && is_array($day['slots']) ? $day['slots'] : array();

			foreach ($day_slots as $slot) {
				if (! is_array($slot)) {
					continue;
				}

				$slot_speakers = isset($slot['speakers']) && is_array($slot['speakers']) ? $slot['speakers'] : array();
				$slot_speaker_ids = array();
				foreach ($slot_speakers as $sp) {
					if (! is_array($sp)) {
						continue;
					}

					$id = isset($sp['speaker_id']) ? absint($sp['speaker_id']) : 0;
					if ($id > 0) {
						$slot_speaker_ids[] = $id;
					}
				}

				$slot_resources = isset($slot['resources']) && is_array($slot['resources']) ? $slot['resources'] : array();
				$filtered_resources = array();
				foreach ($slot_resources as $resource) {
					if (! is_array($resource)) {
						continue;
					}

					$res_speaker_ids = isset($resource['speaker_ids']) && is_array($resource['speaker_ids']) ? $resource['speaker_ids'] : array();
					if (in_array($speaker_id, $res_speaker_ids, true)) {
						$filtered_resources[] = $resource;
					}
				}

				if (in_array($speaker_id, $slot_speaker_ids, true) || ! empty($filtered_resources)) {
					$slot_entry = array(
						'day_date'   => $day_date,
						'slot_title' => isset($slot['title']) ? sanitize_text_field($slot['title']) : '',
						'slot_start' => isset($slot['start']) ? sanitize_text_field($slot['start']) : '',
						'slot_end'   => isset($slot['end']) ? sanitize_text_field($slot['end']) : '',
					);

					if (! empty($filtered_resources)) {
						$slot_entry['resources'] = $filtered_resources;
					}

					$slots[] = $slot_entry;
				}
			}
		}

		return array(
			'event_id'         => $event_id,
			'event_title'      => $event_title,
			'event_start_date' => $event_start_date,
			'slots'            => $slots,
		);
	}

	private static function get_event_start_date(int $event_id): string
	{
		$start_date = get_post_meta($event_id, '_EventStartDate', true);
		if ($start_date && is_string($start_date)) {
			$timestamp = strtotime($start_date);
			if ($timestamp) {
				return date('Y-m-d', $timestamp);
			}
		}

		$post = get_post($event_id);
		if ($post && $post->post_date) {
			return date('Y-m-d', strtotime($post->post_date));
		}

		return date('Y-m-d');
	}
}
