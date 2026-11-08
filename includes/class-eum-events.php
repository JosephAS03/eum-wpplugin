<?php
if (!defined('ABSPATH')) exit;

class EUM_Events {
  const CPT = 'eum_event';
  const META_START_UTC = '_eum_event_start_utc';
  const META_END_UTC = '_eum_event_end_utc';
  const META_LOCATION = '_eum_event_location';
  const META_IS_REMOTE = '_eum_event_is_remote';
  const META_MEETING_LINK = '_eum_event_meeting_link';
  const META_ACCESS_MODE = '_eum_event_access_mode';
  const META_MIN_ATTENDEES = '_eum_event_min_attendees';
  const META_MAX_ATTENDEES = '_eum_event_max_attendees';
  const META_INVITED_USERS = '_eum_event_invited_users';
  const META_INVITED_JOB_PATHS = '_eum_event_invited_job_paths';
  const META_INVITED_GROUPS = '_eum_event_invited_groups';
  const META_INVITED_PROJECTS = '_eum_event_invited_projects';
  const META_INVITED_LOCATIONS = '_eum_event_invited_locations';
  const META_RSVPS = '_eum_event_rsvps';
  const META_CHAT = '_eum_event_chat';
  const META_AVAILABILITY = 'eum_user_availability';
  const DEFAULT_TIMEZONE = 'America/New_York';

  public function init(): void {
    add_action('init', array($this, 'register_post_type'));
    add_action('init', array($this, 'ensure_defaults'), 30);
    add_action('init', array($this, 'maybe_handle_actions'), 40);

    add_shortcode('eum_events', array($this, 'shortcode_events'));
    add_shortcode('eum_user_availability', array($this, 'shortcode_user_availability'));
    add_shortcode('eum_manager_events', array($this, 'shortcode_manager_events'));
  }

  public static function activate(): void {
    register_post_type(self::CPT, array(
      'labels' => array('name' => 'Events', 'singular_name' => 'Event'),
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => array('title', 'editor', 'author'),
      'rewrite' => false,
      'query_var' => false,
    ));
    if (taxonomy_exists('eum_user_group') && !term_exists('Event Creator', 'eum_user_group')) {
      wp_insert_term('Event Creator', 'eum_user_group', array('slug' => 'event-creator'));
    }
    if ((string) get_option('timezone_string', '') === '') {
      update_option('timezone_string', self::DEFAULT_TIMEZONE);
    }
  }

  public function register_post_type(): void {
    register_post_type(self::CPT, array(
      'labels' => array('name' => 'Events', 'singular_name' => 'Event'),
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => array('title', 'editor', 'author'),
      'rewrite' => false,
      'query_var' => false,
    ));
  }

  public function ensure_defaults(): void {
    if (taxonomy_exists('eum_user_group') && !term_exists('Event Creator', 'eum_user_group')) {
      wp_insert_term('Event Creator', 'eum_user_group', array('slug' => 'event-creator'));
    }
    if ((string) get_option('timezone_string', '') === '') {
      update_option('timezone_string', self::DEFAULT_TIMEZONE);
    }
  }

  public static function current_user_can_create($user_id = 0): bool {
    $user_id = (int) ($user_id ?: get_current_user_id());
    if ($user_id <= 0) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) return true;
    if (class_exists('EUM_Access') && EUM_Access::is_manager_user($user_id)) return true;
    if (!taxonomy_exists('eum_user_group')) return false;
    $names = wp_get_object_terms($user_id, 'eum_user_group', array('fields' => 'names'));
    if (is_wp_error($names)) return false;
    foreach ((array) $names as $name) {
      if (strcasecmp((string) $name, 'Event Creator') === 0) return true;
    }
    return false;
  }

  public function shortcode_events($atts = array()): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-card"><p>Please sign in to view events.</p></div>';
    }
    $atts = shortcode_atts(array('limit' => '50', 'show_past' => '1'), (array) $atts, 'eum_events');
    $notice = isset($_GET['eum_events_notice']) ? sanitize_text_field(wp_unslash($_GET['eum_events_notice'])) : '';
    ob_start();
    echo '<div class="eum-events-shell eum-events-shell--user">';
    if ($notice !== '') {
      echo '<div class="eum-notice"><p>' . esc_html($notice) . '</p></div>';
    }
    echo $this->render_event_lists((int) $atts['limit'], $this->truthy($atts['show_past']), false);
    echo '</div>';
    return (string) ob_get_clean();
  }

  public function shortcode_user_availability($atts = array()): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-card"><p>Please sign in to manage availability.</p></div>';
    }
    return $this->render_availability_form((int) get_current_user_id());
  }

  public function shortcode_manager_events($atts = array()): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-card"><p>Please sign in to view events.</p></div>';
    }
    if (!self::current_user_can_create((int) get_current_user_id())) {
      return '<div class="eum-card"><p>You do not have permission to manage events.</p></div>';
    }

    $atts = shortcode_atts(array('limit' => '50', 'show_past' => '1', 'show_availability' => '0'), (array) $atts, 'eum_manager_events');
    $notice = isset($_GET['eum_events_notice']) ? sanitize_text_field(wp_unslash($_GET['eum_events_notice'])) : '';
    $editing_event_id = isset($_GET['eum_event_edit']) ? max(0, (int) $_GET['eum_event_edit']) : 0;
    if ($editing_event_id > 0 && !$this->can_edit_event((int) get_current_user_id(), $editing_event_id)) {
      $editing_event_id = 0;
    }

    ob_start();
    echo '<div class="eum-events-shell eum-events-shell--manager">';
    if ($notice !== '') {
      echo '<div class="eum-notice"><p>' . esc_html($notice) . '</p></div>';
    }
    echo $this->render_event_editor($editing_event_id);
    echo $this->render_event_lists((int) $atts['limit'], $this->truthy($atts['show_past']), true);
    if ($this->truthy($atts['show_availability'])) {
      echo '<div class="eum-events-shell__availability">' . $this->render_availability_form((int) get_current_user_id()) . '</div>';
    }
    echo '</div>';
    return (string) ob_get_clean();
  }

  public function maybe_handle_actions(): void {
    if (!is_user_logged_in()) return;
    if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') return;

    $action = isset($_POST['eum_events_action']) ? sanitize_key(wp_unslash($_POST['eum_events_action'])) : '';
    if ($action === 'save_event') {
      $this->handle_save_event();
    } elseif ($action === 'save_availability') {
      $this->handle_save_availability();
    } elseif ($action === 'rsvp_event') {
      $this->handle_rsvp_event();
    } elseif ($action === 'reset_event_rsvp') {
      $this->handle_reset_event_rsvp();
    } elseif ($action === 'event_chat') {
      $this->handle_event_chat();
    } elseif ($action === 'delete_event_chat_message') {
      $this->handle_delete_event_chat_message();
    } elseif ($action === 'delete_event') {
      $this->handle_delete_event();
    }
  }

  private function handle_save_event(): void {
    $user_id = (int) get_current_user_id();
    if (!self::current_user_can_create($user_id)) {
      $this->redirect_with_notice('You do not have permission to manage events.');
    }
    if (!isset($_POST['eum_event_nonce']) || !wp_verify_nonce(wp_unslash($_POST['eum_event_nonce']), 'eum_save_event')) {
      $this->redirect_with_notice('Your session expired. Please try again.');
    }

    $event_id = isset($_POST['event_id']) ? max(0, (int) $_POST['event_id']) : 0;
    if ($event_id > 0 && !$this->can_edit_event($user_id, $event_id)) {
      $this->redirect_with_notice('You do not have permission to edit that event.');
    }

    $title = isset($_POST['event_title']) ? sanitize_text_field(wp_unslash($_POST['event_title'])) : '';
    $description = isset($_POST['event_description']) ? wp_kses_post(wp_unslash($_POST['event_description'])) : '';
    $location = isset($_POST['event_location']) ? sanitize_text_field(wp_unslash($_POST['event_location'])) : '';
    $meeting_link = isset($_POST['event_meeting_link']) ? esc_url_raw(wp_unslash($_POST['event_meeting_link'])) : '';
    $is_remote = !empty($_POST['event_is_remote']) ? '1' : '0';
    $access_mode = (isset($_POST['event_access_mode']) && wp_unslash($_POST['event_access_mode']) === 'invite') ? 'invite' : 'open';
    $min_attendees = isset($_POST['event_min_attendees']) ? max(0, (int) $_POST['event_min_attendees']) : 0;
    $max_attendees = isset($_POST['event_max_attendees']) ? max(0, (int) $_POST['event_max_attendees']) : 0;
    $owner_user_id = $this->sanitize_event_owner_user_id(isset($_POST['event_owner_user_id']) ? (int) $_POST['event_owner_user_id'] : 0, $event_id > 0 ? (int) get_post_field('post_author', $event_id) : $user_id);
    $previous_data = $event_id > 0 ? $this->event_defaults($event_id) : $this->event_defaults(0);
    $previous_invitees = $event_id > 0 ? $this->resolve_invited_users($previous_data) : array();
    $user_tz = $this->user_timezone($user_id);
    $start_ts = $this->local_input_to_utc(isset($_POST['event_start']) ? wp_unslash($_POST['event_start']) : '', $user_tz);
    $end_ts = $this->local_input_to_utc(isset($_POST['event_end']) ? wp_unslash($_POST['event_end']) : '', $user_tz);

    if ($title === '' || $start_ts <= 0 || $end_ts <= 0 || $end_ts <= $start_ts) {
      $this->redirect_with_notice('Please enter a title, a valid start time, and a valid end time.');
    }
    if ($max_attendees > 0 && $min_attendees > $max_attendees) {
      $this->redirect_with_notice('Minimum attendees cannot exceed the maximum.');
    }

    $postarr = array(
      'post_type' => self::CPT,
      'post_title' => $title,
      'post_content' => $description,
      'post_status' => 'publish',
      'post_author' => $owner_user_id,
    );
    if ($event_id > 0) {
      $postarr['ID'] = $event_id;
      $saved = wp_update_post(wp_slash($postarr), true);
    } else {
      $saved = wp_insert_post(wp_slash($postarr), true);
    }
    if (is_wp_error($saved) || (int) $saved <= 0) {
      $this->redirect_with_notice('The event could not be saved.');
    }
    $event_id = (int) $saved;

    update_post_meta($event_id, self::META_START_UTC, $start_ts);
    update_post_meta($event_id, self::META_END_UTC, $end_ts);
    update_post_meta($event_id, self::META_LOCATION, $location);
    update_post_meta($event_id, self::META_MEETING_LINK, $meeting_link);
    update_post_meta($event_id, self::META_IS_REMOTE, $is_remote);
    update_post_meta($event_id, self::META_ACCESS_MODE, $access_mode);
    update_post_meta($event_id, self::META_MIN_ATTENDEES, $min_attendees);
    update_post_meta($event_id, self::META_MAX_ATTENDEES, $max_attendees);
    update_post_meta($event_id, self::META_INVITED_USERS, $this->sanitize_active_user_ids($this->posted_int_array('event_invited_users')));
    $invited_job_paths = $this->posted_int_array('event_invited_job_paths');
    if (class_exists('EUM_Profiles')) $invited_job_paths = EUM_Profiles::expand_job_path_ids($invited_job_paths);
    update_post_meta($event_id, self::META_INVITED_JOB_PATHS, $invited_job_paths);
    update_post_meta($event_id, self::META_INVITED_GROUPS, $this->posted_int_array('event_invited_groups'));
    update_post_meta($event_id, self::META_INVITED_PROJECTS, $this->posted_int_array('event_invited_projects'));
    update_post_meta($event_id, self::META_INVITED_LOCATIONS, $this->posted_int_array('event_invited_locations'));

    $updated_data = $this->event_defaults($event_id);
    $updated_invitees = $this->resolve_invited_users($updated_data);
    $new_invitees = ($event_id > 0 && !empty($previous_invitees)) ? array_values(array_diff($updated_invitees, $previous_invitees)) : $updated_invitees;
    $mail_summary = $this->send_invite_emails($event_id, $new_invitees, $owner_user_id);

    $notice = 'Event saved.';
    if (!empty($mail_summary['sent'])) {
      $notice .= ' ' . (int) $mail_summary['sent'] . ' invite email(s) sent.';
    } elseif (!empty($mail_summary['attempted'])) {
      $notice .= ' Invite email delivery skipped for ' . (int) $mail_summary['attempted'] . ' user(s).';
    }
    $url = $this->current_url_without(array('eum_events_notice', 'eum_event_edit'));
    $url = add_query_arg('eum_events_notice', rawurlencode($notice), $url);
    wp_safe_redirect($url);
    exit;
  }

  private function handle_save_availability(): void {
    $user_id = (int) get_current_user_id();
    if (!isset($_POST['eum_availability_nonce']) || !wp_verify_nonce(wp_unslash($_POST['eum_availability_nonce']), 'eum_save_availability')) {
      $this->redirect_with_notice('Your session expired. Please try again.');
    }

    $rows = array();
    foreach ($this->weekday_labels() as $day_key => $day_label) {
      $enabled = !empty($_POST['availability_enabled'][$day_key]) ? 1 : 0;
      $start = isset($_POST['availability_start'][$day_key]) ? sanitize_text_field(wp_unslash($_POST['availability_start'][$day_key])) : '';
      $end = isset($_POST['availability_end'][$day_key]) ? sanitize_text_field(wp_unslash($_POST['availability_end'][$day_key])) : '';
      $rows[$day_key] = array(
        'enabled' => $enabled,
        'start' => preg_match('/^\d{2}:\d{2}$/', $start) ? $start : '',
        'end' => preg_match('/^\d{2}:\d{2}$/', $end) ? $end : '',
      );
    }
    update_user_meta($user_id, self::META_AVAILABILITY, $rows);

    $url = $this->current_url_without(array('eum_events_notice'));
    $url = add_query_arg('eum_events_notice', rawurlencode('Availability saved.'), $url);
    wp_safe_redirect($url);
    exit;
  }

  private function handle_rsvp_event(): void {
    $user_id = (int) get_current_user_id();
    if (!isset($_POST['eum_rsvp_nonce']) || !wp_verify_nonce(wp_unslash($_POST['eum_rsvp_nonce']), 'eum_rsvp_event')) {
      $this->redirect_with_notice('Your session expired. Please try again.');
    }
    $event_id = isset($_POST['event_id']) ? max(0, (int) $_POST['event_id']) : 0;
    if ($event_id <= 0 || get_post_type($event_id) !== self::CPT) {
      $this->redirect_with_notice('That event could not be found.');
    }
    if (!$this->can_view_event($user_id, $event_id)) {
      $this->redirect_with_notice('You are not allowed to RSVP for that event.');
    }
    $mode = (string) get_post_meta($event_id, self::META_ACCESS_MODE, true);
    if ($mode === 'invite' && !$this->is_user_invited_to_event($user_id, $event_id)) {
      $this->redirect_with_notice('This event is invite only.');
    }

    $status = isset($_POST['rsvp_status']) ? sanitize_key(wp_unslash($_POST['rsvp_status'])) : 'accept';
    if (!in_array($status, array('accept', 'maybe', 'decline'), true)) {
      $status = 'accept';
    }
    $count = isset($_POST['rsvp_count']) ? max(1, (int) $_POST['rsvp_count']) : 1;

    $rsvps = $this->get_rsvps($event_id);
    $current = isset($rsvps[$user_id]) && is_array($rsvps[$user_id]) ? $rsvps[$user_id] : array('status' => '', 'count' => 0);
    $current_accepted = ($current['status'] === 'accept') ? max(1, (int) $current['count']) : 0;
    $new_accepted = ($status === 'accept') ? $count : 0;
    $max = (int) get_post_meta($event_id, self::META_MAX_ATTENDEES, true);
    $used = $this->accepted_attendee_total($event_id);
    $proposed_total = $used - $current_accepted + $new_accepted;
    if ($max > 0 && $proposed_total > $max) {
      $remaining = max(0, $max - ($used - $current_accepted));
      $this->redirect_with_notice($remaining > 0 ? ('Only ' . $remaining . ' attendee slot(s) remain for this event.') : 'This event is already full.');
    }

    $rsvps[$user_id] = array(
      'status' => $status,
      'count' => $count,
      'updated' => time(),
    );
    update_post_meta($event_id, self::META_RSVPS, $rsvps);
    $this->redirect_with_notice('Your RSVP was saved.');
  }


  private function handle_reset_event_rsvp(): void {
    $user_id = (int) get_current_user_id();
    $event_id = isset($_POST['event_id']) ? max(0, (int) $_POST['event_id']) : 0;
    $target_user_id = isset($_POST['target_user_id']) ? max(0, (int) $_POST['target_user_id']) : 0;
    if (!isset($_POST['eum_reset_rsvp_nonce']) || !wp_verify_nonce(wp_unslash($_POST['eum_reset_rsvp_nonce']), 'eum_reset_event_rsvp_' . $event_id . '_' . $target_user_id)) {
      $this->redirect_with_notice('Your session expired. Please try again.');
    }
    if ($event_id <= 0 || get_post_type($event_id) !== self::CPT || !$this->can_edit_event($user_id, $event_id)) {
      $this->redirect_with_notice('You do not have permission to reset that RSVP.');
    }

    $rsvps = $this->get_rsvps($event_id);
    if (isset($rsvps[$target_user_id])) {
      unset($rsvps[$target_user_id]);
      update_post_meta($event_id, self::META_RSVPS, $rsvps);
      $this->redirect_with_notice('RSVP reset.');
    }
    $this->redirect_with_notice('That RSVP was already clear.');
  }

  private function handle_event_chat(): void {
    $user_id = (int) get_current_user_id();
    $event_id = isset($_POST['event_id']) ? max(0, (int) $_POST['event_id']) : 0;
    if (!isset($_POST['eum_event_chat_nonce']) || !wp_verify_nonce(wp_unslash($_POST['eum_event_chat_nonce']), 'eum_event_chat_' . $event_id)) {
      $this->redirect_with_notice('Your session expired. Please try again.');
    }
    if ($event_id <= 0 || get_post_type($event_id) !== self::CPT) {
      $this->redirect_with_notice('That event could not be found.');
    }
    if (!$this->user_can_use_event_chat($user_id, $event_id)) {
      $this->redirect_with_notice('Only accepted attendees can use that event chat.');
    }

    $message = isset($_POST['event_chat_message']) ? trim((string) wp_unslash($_POST['event_chat_message'])) : '';
    $message = sanitize_textarea_field($message);
    if ($message === '') {
      $this->redirect_with_notice('Please enter a message before posting.');
    }
    if (function_exists('mb_substr')) {
      $message = mb_substr($message, 0, 2000);
    } else {
      $message = substr($message, 0, 2000);
    }

    $chat = $this->get_event_chat($event_id);
    $chat[] = array(
      'id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('eum_evt_', true),
      'user_id' => $user_id,
      'message' => $message,
      'created' => time(),
    );
    if (count($chat) > 200) {
      $chat = array_slice($chat, -200);
    }
    update_post_meta($event_id, self::META_CHAT, $chat);
    $this->redirect_with_notice('Message posted.');
  }

  private function handle_delete_event_chat_message(): void {
    $user_id = (int) get_current_user_id();
    $event_id = isset($_POST['event_id']) ? max(0, (int) $_POST['event_id']) : 0;
    $message_id = isset($_POST['message_id']) ? sanitize_text_field(wp_unslash($_POST['message_id'])) : '';
    if (!isset($_POST['eum_delete_event_chat_nonce']) || !wp_verify_nonce(wp_unslash($_POST['eum_delete_event_chat_nonce']), 'eum_delete_event_chat_' . $event_id . '_' . $message_id)) {
      $this->redirect_with_notice('Your session expired. Please try again.');
    }
    if ($event_id <= 0 || $message_id === '' || !$this->can_manage_event_chat($user_id, $event_id)) {
      $this->redirect_with_notice('You do not have permission to remove that message.');
    }

    $chat = $this->get_event_chat($event_id);
    $updated = array();
    $removed = false;
    foreach ($chat as $row) {
      if (!$removed && isset($row['id']) && (string) $row['id'] === $message_id) {
        $removed = true;
        continue;
      }
      $updated[] = $row;
    }
    if ($removed) {
      update_post_meta($event_id, self::META_CHAT, array_values($updated));
      $this->redirect_with_notice('Message removed.');
    }
    $this->redirect_with_notice('That message could not be found.');
  }

  private function handle_delete_event(): void {
    $user_id = (int) get_current_user_id();
    if (!isset($_POST['eum_delete_event_nonce']) || !wp_verify_nonce(wp_unslash($_POST['eum_delete_event_nonce']), 'eum_delete_event')) {
      $this->redirect_with_notice('Your session expired. Please try again.');
    }
    $event_id = isset($_POST['event_id']) ? max(0, (int) $_POST['event_id']) : 0;
    if ($event_id <= 0 || !$this->can_edit_event($user_id, $event_id)) {
      $this->redirect_with_notice('You do not have permission to delete that event.');
    }
    wp_delete_post($event_id, true);
    $this->redirect_with_notice('Event deleted.');
  }

  private function render_event_editor($event_id = 0): string {
    $event_id = (int) $event_id;
    $defaults = $this->event_defaults($event_id);
    $is_edit = $event_id > 0;

    ob_start();
    echo '<section class="eum-card eum-events-card">';
    echo '<div class="eum-events-card__header"><div><h2>' . esc_html($is_edit ? 'Edit Event' : 'Create Event') . '</h2><p>All event dates and times are shown in your timezone: <strong>' . esc_html($this->user_timezone_label((int) get_current_user_id())) . '</strong>.</p></div>';
    if ($is_edit) {
      echo '<div class="eum-events-card__actions"><a class="button" href="' . esc_url($this->current_url_without(array('eum_event_edit', 'eum_events_notice'))) . '">Create New Event</a></div>';
    }
    echo '</div>';
    echo '<form method="post" class="eum-form eum-event-editor">';
    wp_nonce_field('eum_save_event', 'eum_event_nonce');
    echo '<input type="hidden" name="eum_events_action" value="save_event">';
    echo '<input type="hidden" name="event_id" value="' . (int) $event_id . '">';

    echo '<div class="eum-event-grid">';
    echo $this->field_html('Event Title', '<input type="text" name="event_title" class="regular-text" required value="' . esc_attr($defaults['title']) . '">');
    echo $this->field_html('Owner', $this->render_user_single_select('event_owner_user_id', (int) $defaults['owner_user_id'], true) . '<p class="description">Invite emails use the owner&rsquo;s company email when available.</p>');
    echo $this->field_html('Access', '<select name="event_access_mode" data-eum-event-access><option value="open" ' . selected($defaults['access_mode'], 'open', false) . '>Open</option><option value="invite" ' . selected($defaults['access_mode'], 'invite', false) . '>Invite Only</option></select>');
    echo $this->field_html('Start', '<input type="datetime-local" name="event_start" required value="' . esc_attr($defaults['start_local']) . '">');
    echo $this->field_html('End', '<input type="datetime-local" name="event_end" required value="' . esc_attr($defaults['end_local']) . '">');
    echo $this->field_html('Location', '<input type="text" name="event_location" class="regular-text" value="' . esc_attr($defaults['location']) . '">');
    echo $this->field_html('Remote Meeting Link', '<input type="url" name="event_meeting_link" class="regular-text" value="' . esc_attr($defaults['meeting_link']) . '"><label class="eum-inline-check"><input type="checkbox" name="event_is_remote" value="1" ' . checked($defaults['is_remote'], true, false) . '> Remote / online event</label>');
    echo $this->field_html('Minimum Attendees', '<input type="number" min="0" name="event_min_attendees" value="' . esc_attr((string) $defaults['min_attendees']) . '"><p class="description">Informational only.</p>');
    echo $this->field_html('Maximum Attendees', '<input type="number" min="0" name="event_max_attendees" value="' . esc_attr((string) $defaults['max_attendees']) . '"><p class="description">Accept RSVPs stop when this limit is reached.</p>');
    echo '</div>';

    echo $this->field_html('Description', '<textarea name="event_description" rows="5" class="large-text">' . esc_textarea($defaults['description']) . '</textarea>');

    echo '<div class="eum-events-card eum-events-card--sub" data-eum-event-invite-only>'; 
    echo '<h3>Invite Targets</h3><p class="description">Users added here receive an email invite at their company email address.</p>';
    echo '<div class="eum-event-grid">';
    echo $this->field_html('Users', $this->render_users_select('event_invited_users[]', $defaults['invited_users']));
    echo $this->field_html('Job Paths', class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_checkboxes('event_invited_job_paths', $defaults['invited_job_paths'], get_current_user_id(), ['enforce_manageable_job_paths' => false]) : $this->render_term_select('eum_job_path', 'event_invited_job_paths[]', $defaults['invited_job_paths'], true));
    echo $this->field_html('Groups', $this->render_term_select('eum_user_group', 'event_invited_groups[]', $defaults['invited_groups'], false));
    echo $this->field_html('Projects', $this->render_term_select('eum_project', 'event_invited_projects[]', $defaults['invited_projects'], false));
    echo $this->field_html('Locations', $this->render_term_select('eum_location', 'event_invited_locations[]', $defaults['invited_locations'], true));
    echo '</div>';
    echo '</div>';

    if ($defaults['access_mode'] === 'invite') {
      echo '<div class="eum-events-card eum-events-card--sub">';
      echo '<h3>Likely Availability</h3>';
      echo $this->render_availability_preview($defaults);
      echo '</div>';
    }

    echo '<p><button type="submit" class="button button-primary">' . esc_html($is_edit ? 'Save Event' : 'Create Event') . '</button></p>';
    echo '</form>';

    if ($is_edit) {
      echo '<form method="post" class="eum-event-delete-form" onsubmit="return confirm(\'Delete this event?\');">';
      wp_nonce_field('eum_delete_event', 'eum_delete_event_nonce');
      echo '<input type="hidden" name="eum_events_action" value="delete_event">';
      echo '<input type="hidden" name="event_id" value="' . (int) $event_id . '">';
      echo '<p><button type="submit" class="button button-secondary">Delete Event</button></p>';
      echo '</form>';
    }

    echo '</section>';
    return (string) ob_get_clean();
  }

  private function render_event_lists($limit = 50, $show_past = true, $manager_mode = false): string {
    $events = get_posts(array(
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => max(1, (int) $limit),
      'orderby' => 'meta_value_num',
      'meta_key' => self::META_START_UTC,
      'order' => 'ASC',
      'fields' => 'all',
    ));

    $current_user_id = (int) get_current_user_id();
    $now = time();
    $upcoming = array();
    $past = array();
    foreach ((array) $events as $event) {
      if (!$this->can_view_event($current_user_id, (int) $event->ID)) continue;
      $end_ts = (int) get_post_meta((int) $event->ID, self::META_END_UTC, true);
      if ($end_ts > 0 && $end_ts < $now) $past[] = $event;
      else $upcoming[] = $event;
    }

    ob_start();
    echo '<section class="eum-card eum-events-card">';
    echo '<div class="eum-events-card__header"><div><h2>Upcoming Events</h2><p>' . esc_html($manager_mode ? 'Manage events and view RSVP activity.' : 'Browse events and RSVP below.') . '</p></div></div>';
    if (empty($upcoming)) {
      echo '<p>No upcoming events found.</p>';
    } else {
      foreach ($upcoming as $event) {
        echo $this->render_event_card((int) $event->ID, $manager_mode);
      }
    }
    echo '</section>';

    if ($show_past) {
      echo '<section class="eum-card eum-events-card">';
      echo '<div class="eum-events-card__header"><div><h2>Past Events</h2></div></div>';
      if (empty($past)) {
        echo '<p>No past events found.</p>';
      } else {
        foreach ($past as $event) {
          echo $this->render_event_card((int) $event->ID, $manager_mode);
        }
      }
      echo '</section>';
    }
    return (string) ob_get_clean();
  }

  private function render_event_card($event_id, $manager_mode = false): string {
    $event = get_post($event_id);
    if (!$event || $event->post_type !== self::CPT) return '';

    $current_user_id = (int) get_current_user_id();
    $can_edit = $this->can_edit_event($current_user_id, $event_id);
    $start_ts = (int) get_post_meta($event_id, self::META_START_UTC, true);
    $end_ts = (int) get_post_meta($event_id, self::META_END_UTC, true);
    $location = (string) get_post_meta($event_id, self::META_LOCATION, true);
    $meeting_link = (string) get_post_meta($event_id, self::META_MEETING_LINK, true);
    $is_remote = ((string) get_post_meta($event_id, self::META_IS_REMOTE, true) === '1');
    $access_mode_raw = (string) get_post_meta($event_id, self::META_ACCESS_MODE, true);
    $access_mode_label = ($access_mode_raw === 'invite') ? 'Invite Only' : 'Open';
    $creator_name = get_the_author_meta('display_name', (int) $event->post_author);
    $invitees = $this->resolve_invited_users($this->event_defaults($event_id));
    $rsvp_summary = $this->rsvp_summary($event_id, $invitees);
    $current_rsvp = $this->current_user_rsvp($event_id, $current_user_id);
    $user_can_rsvp = $this->user_can_rsvp($current_user_id, $event_id);

    ob_start();
    echo '<article class="eum-event-card">';
    echo '<div class="eum-event-card__head">';
    echo '<div><h3>' . esc_html(get_the_title($event_id)) . '</h3><p class="eum-event-card__meta">' . esc_html($this->format_event_range($start_ts, $end_ts, $current_user_id)) . '</p></div>';
    if ($can_edit) {
      $edit_url = add_query_arg('eum_event_edit', (int) $event_id, $this->current_url_without(array('eum_events_notice')));
      echo '<div><a class="button button-secondary" href="' . esc_url($edit_url) . '">Edit</a></div>';
    }
    echo '</div>';
    echo '<div class="eum-event-card__body">';
    echo '<p><strong>Access:</strong> ' . esc_html($access_mode_label) . '</p>';
    if ($location !== '') echo '<p><strong>Location:</strong> ' . esc_html($location) . '</p>';
    if ($is_remote && $meeting_link !== '') echo '<p><strong>Meeting Link:</strong> <a href="' . esc_url($meeting_link) . '" target="_blank" rel="noopener">Join meeting</a></p>';
    echo '<p><strong>Created by:</strong> ' . esc_html($creator_name !== '' ? $creator_name : 'Unknown') . '</p>';
    echo '<p><strong>Accepted attendees:</strong> ' . (int) $rsvp_summary['accepted_total'];
    $max = (int) get_post_meta($event_id, self::META_MAX_ATTENDEES, true);
    if ($max > 0) echo ' / ' . (int) $max;
    echo '</p>';
    if ($access_mode_raw === 'invite') echo '<p><strong>Invited users:</strong> ' . (int) count($invitees) . '</p>';
    echo '<p><strong>Event chat:</strong> ' . (int) count($this->get_event_chat($event_id)) . ' message(s)</p>';
    if (trim((string) $event->post_content) !== '') {
      echo '<div class="eum-event-card__description">' . wpautop(wp_kses_post($event->post_content)) . '</div>';
    }

    if ($manager_mode || $can_edit) {
      echo '<div class="eum-event-rsvp-summary">';
      echo '<p><strong>RSVP Snapshot:</strong> Accepted ' . (int) $rsvp_summary['accepted_users'] . ' (' . (int) $rsvp_summary['accepted_total'] . ' attendees), Maybe ' . (int) $rsvp_summary['maybe_users'] . ', Declined ' . (int) $rsvp_summary['declined_users'] . ', No Response ' . (int) $rsvp_summary['no_response_users'] . '</p>';
      if (!empty($rsvp_summary['accepted_names'])) {
        echo '<p><strong>Accepted:</strong> ' . esc_html(implode(', ', $rsvp_summary['accepted_names'])) . '</p>';
      }
      echo $this->render_rsvp_manager_table($event_id, $invitees, $this->get_rsvps($event_id), $can_edit);
      echo '</div>';
    }

    if ($user_can_rsvp) {
      echo $this->render_rsvp_form($event_id, $current_rsvp, $rsvp_summary);
    } elseif ($access_mode_raw === 'invite' && !$this->is_user_invited_to_event($current_user_id, $event_id) && !$can_edit) {
      echo '<p class="description">This is an invite-only event. You are not on the invite list.</p>';
    }

    echo $this->render_event_chat($event_id, $can_edit);

    echo '</div>';
    echo '</article>';
    return (string) ob_get_clean();
  }

  private function render_rsvp_form($event_id, array $current_rsvp, array $rsvp_summary): string {
    $status = isset($current_rsvp['status']) ? (string) $current_rsvp['status'] : '';
    $count = isset($current_rsvp['count']) && (int) $current_rsvp['count'] > 0 ? (int) $current_rsvp['count'] : 1;
    $max = (int) get_post_meta($event_id, self::META_MAX_ATTENDEES, true);
    $remaining = $max > 0 ? max(0, $max - ((int) $rsvp_summary['accepted_total'] - ($status === 'accept' ? $count : 0))) : 99;
    $max_select = max(1, min(20, $remaining > 0 ? $remaining : $count));

    ob_start();
    echo '<form method="post" class="eum-event-rsvp-form">';
    wp_nonce_field('eum_rsvp_event', 'eum_rsvp_nonce');
    echo '<input type="hidden" name="eum_events_action" value="rsvp_event">';
    echo '<input type="hidden" name="event_id" value="' . (int) $event_id . '">';
    echo '<div class="eum-event-rsvp-grid">';
    echo $this->field_html('Your RSVP', '<select name="rsvp_status"><option value="accept" ' . selected($status, 'accept', false) . '>Accept</option><option value="maybe" ' . selected($status, 'maybe', false) . '>Maybe</option><option value="decline" ' . selected($status, 'decline', false) . '>Decline</option></select>');
    $count_html = '<select name="rsvp_count">';
    for ($i = 1; $i <= $max_select; $i++) {
      $count_html .= '<option value="' . $i . '" ' . selected($count, $i, false) . '>' . $i . '</option>';
    }
    $count_html .= '</select><p class="description">Count only applies when accepting.</p>';
    echo $this->field_html('Attendees', $count_html);
    echo '</div>';
    echo '<p><button type="submit" class="button button-primary">Save RSVP</button></p>';
    echo '</form>';
    return (string) ob_get_clean();
  }

  private function render_availability_form($user_id): string {
    $user_id = (int) $user_id;
    $rows = $this->get_user_availability($user_id);

    ob_start();
    echo '<section class="eum-card eum-events-card">';
    echo '<div class="eum-events-card__header"><div><h2>Your Availability</h2><p>Set your usual weekly availability in your own timezone: <strong>' . esc_html($this->user_timezone_label($user_id)) . '</strong>.</p></div></div>';
    echo '<form method="post" class="eum-form">';
    wp_nonce_field('eum_save_availability', 'eum_availability_nonce');
    echo '<input type="hidden" name="eum_events_action" value="save_availability">';
    echo '<table class="widefat striped eum-availability-table"><thead><tr><th>Day</th><th>Available</th><th>Start</th><th>End</th></tr></thead><tbody>';
    foreach ($this->weekday_labels() as $day_key => $day_label) {
      $row = isset($rows[$day_key]) && is_array($rows[$day_key]) ? $rows[$day_key] : array('enabled' => 0, 'start' => '', 'end' => '');
      echo '<tr>';
      echo '<td>' . esc_html($day_label) . '</td>';
      echo '<td><label><input type="checkbox" name="availability_enabled[' . esc_attr($day_key) . ']" value="1" ' . checked(!empty($row['enabled']), true, false) . '> Yes</label></td>';
      echo '<td><input type="time" name="availability_start[' . esc_attr($day_key) . ']" value="' . esc_attr((string) $row['start']) . '"></td>';
      echo '<td><input type="time" name="availability_end[' . esc_attr($day_key) . ']" value="' . esc_attr((string) $row['end']) . '"></td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p><button type="submit" class="button button-primary">Save Availability</button></p>';
    echo '</form>';
    echo '</section>';
    return (string) ob_get_clean();
  }

  private function render_availability_preview(array $event_data): string {
    $invitees = $this->resolve_invited_users($event_data);
    if (empty($invitees)) return '<p>No matching invitees found yet for this event.</p>';

    $start_ts = isset($event_data['start_utc']) ? (int) $event_data['start_utc'] : 0;
    $end_ts = isset($event_data['end_utc']) ? (int) $event_data['end_utc'] : 0;
    if ($start_ts <= 0 || $end_ts <= 0) {
      return '<p>Availability preview will appear after you enter a valid start and end time.</p>';
    }

    $likely = array();
    $unlikely = array();
    $unknown = array();
    foreach ($invitees as $user_id) {
      $available = $this->user_is_likely_available((int) $user_id, $start_ts, $end_ts);
      $label = $this->user_label((int) $user_id);
      if ($available === true) $likely[] = $label;
      elseif ($available === false) $unlikely[] = $label;
      else $unknown[] = $label;
    }

    ob_start();
    echo '<p><strong>Likely available:</strong> ' . (int) count($likely) . ' of ' . (int) count($invitees) . '</p>';
    if (!empty($likely)) echo '<p>' . esc_html(implode(', ', $likely)) . '</p>';
    if (!empty($unlikely)) echo '<p><strong>Likely unavailable:</strong> ' . esc_html(implode(', ', $unlikely)) . '</p>';
    if (!empty($unknown)) echo '<p><strong>Unknown:</strong> ' . esc_html(implode(', ', $unknown)) . '</p>';
    return (string) ob_get_clean();
  }

  private function can_edit_event($user_id, $event_id): bool {
    $user_id = (int) $user_id;
    $event_id = (int) $event_id;
    if ($user_id <= 0 || $event_id <= 0) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) return true;
    if (class_exists('EUM_Access') && EUM_Access::is_manager_user($user_id)) return true;
    return ((int) get_post_field('post_author', $event_id) === $user_id);
  }

  private function can_view_event($user_id, $event_id): bool {
    $user_id = (int) $user_id;
    $event_id = (int) $event_id;
    if ($user_id <= 0 || $event_id <= 0) return false;
    if ($this->can_edit_event($user_id, $event_id)) return true;
    $mode = (string) get_post_meta($event_id, self::META_ACCESS_MODE, true);
    if ($mode !== 'invite') return true;
    return $this->is_user_invited_to_event($user_id, $event_id);
  }

  private function user_can_rsvp($user_id, $event_id): bool {
    if ($user_id <= 0 || $event_id <= 0) return false;
    if ($this->is_terminated_user((int) $user_id)) return false;
    $mode = (string) get_post_meta($event_id, self::META_ACCESS_MODE, true);
    if ($mode !== 'invite') return true;
    return $this->is_user_invited_to_event($user_id, $event_id);
  }

  private function is_user_invited_to_event($user_id, $event_id): bool {
    $invitees = $this->resolve_invited_users($this->event_defaults($event_id));
    return in_array((int) $user_id, $invitees, true);
  }

  private function resolve_invited_users(array $event_data): array {
    $users = array();
    foreach ((array) $event_data['invited_users'] as $user_id) {
      $user_id = (int) $user_id;
      if ($user_id > 0 && !$this->is_terminated_user($user_id)) $users[$user_id] = $user_id;
    }

    $tax_map = array(
      'eum_job_path' => 'invited_job_paths',
      'eum_user_group' => 'invited_groups',
      'eum_project' => 'invited_projects',
      'eum_location' => 'invited_locations',
    );
    foreach ($tax_map as $taxonomy => $key) {
      if (!taxonomy_exists($taxonomy)) continue;
      $term_ids = isset($event_data[$key]) ? array_values(array_filter(array_map('intval', (array) $event_data[$key]))) : array();
      if (empty($term_ids)) continue;
      $matched = get_objects_in_term($term_ids, $taxonomy);
      if (is_wp_error($matched)) continue;
      foreach ((array) $matched as $user_id) {
        $user_id = (int) $user_id;
        if ($user_id > 0 && !$this->is_terminated_user($user_id)) $users[$user_id] = $user_id;
      }
    }
    ksort($users);
    return array_values($users);
  }

  private function event_defaults($event_id = 0): array {
    $event_id = (int) $event_id;
    $user_id = (int) get_current_user_id();
    $defaults = array(
      'event_id' => $event_id,
      'owner_user_id' => $user_id,
      'title' => '', 'description' => '', 'start_utc' => 0, 'end_utc' => 0,
      'start_local' => '', 'end_local' => '', 'location' => '', 'meeting_link' => '',
      'is_remote' => false, 'access_mode' => 'open', 'min_attendees' => 0, 'max_attendees' => 0,
      'invited_users' => array(), 'invited_job_paths' => array(), 'invited_groups' => array(),
      'invited_projects' => array(), 'invited_locations' => array(),
    );
    if ($event_id <= 0) {
      $start = time() + HOUR_IN_SECONDS;
      $end = $start + HOUR_IN_SECONDS;
      $defaults['start_utc'] = $start;
      $defaults['end_utc'] = $end;
      $defaults['start_local'] = $this->utc_to_local_input($start, $this->user_timezone($user_id));
      $defaults['end_local'] = $this->utc_to_local_input($end, $this->user_timezone($user_id));
      return $defaults;
    }
    $post = get_post($event_id);
    if (!$post || $post->post_type !== self::CPT) return $defaults;

    $defaults['owner_user_id'] = (int) $post->post_author;
    $defaults['title'] = (string) $post->post_title;
    $defaults['description'] = (string) $post->post_content;
    $defaults['start_utc'] = (int) get_post_meta($event_id, self::META_START_UTC, true);
    $defaults['end_utc'] = (int) get_post_meta($event_id, self::META_END_UTC, true);
    $defaults['start_local'] = $this->utc_to_local_input($defaults['start_utc'], $this->user_timezone($user_id));
    $defaults['end_local'] = $this->utc_to_local_input($defaults['end_utc'], $this->user_timezone($user_id));
    $defaults['location'] = (string) get_post_meta($event_id, self::META_LOCATION, true);
    $defaults['meeting_link'] = (string) get_post_meta($event_id, self::META_MEETING_LINK, true);
    $defaults['is_remote'] = ((string) get_post_meta($event_id, self::META_IS_REMOTE, true) === '1');
    $defaults['access_mode'] = ((string) get_post_meta($event_id, self::META_ACCESS_MODE, true) === 'invite') ? 'invite' : 'open';
    $defaults['min_attendees'] = (int) get_post_meta($event_id, self::META_MIN_ATTENDEES, true);
    $defaults['max_attendees'] = (int) get_post_meta($event_id, self::META_MAX_ATTENDEES, true);
    $defaults['invited_users'] = $this->meta_int_array($event_id, self::META_INVITED_USERS);
    $defaults['invited_job_paths'] = $this->meta_int_array($event_id, self::META_INVITED_JOB_PATHS);
    $defaults['invited_groups'] = $this->meta_int_array($event_id, self::META_INVITED_GROUPS);
    $defaults['invited_projects'] = $this->meta_int_array($event_id, self::META_INVITED_PROJECTS);
    $defaults['invited_locations'] = $this->meta_int_array($event_id, self::META_INVITED_LOCATIONS);
    return $defaults;
  }

  private function get_rsvps($event_id): array {
    $raw = get_post_meta((int) $event_id, self::META_RSVPS, true);
    return is_array($raw) ? $raw : array();
  }

  private function current_user_rsvp($event_id, $user_id): array {
    $rsvps = $this->get_rsvps($event_id);
    return isset($rsvps[$user_id]) && is_array($rsvps[$user_id]) ? $rsvps[$user_id] : array('status' => '', 'count' => 1);
  }

  private function user_can_use_event_chat($user_id, $event_id): bool {
    $user_id = (int) $user_id;
    $event_id = (int) $event_id;
    if ($user_id <= 0 || $event_id <= 0) return false;
    $rsvp = $this->current_user_rsvp($event_id, $user_id);
    return isset($rsvp['status']) && (string) $rsvp['status'] === 'accept';
  }

  private function can_manage_event_chat($user_id, $event_id): bool {
    $user_id = (int) $user_id;
    $event_id = (int) $event_id;
    if ($user_id <= 0 || $event_id <= 0) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) return true;
    $owner_user_id = (int) get_post_field('post_author', $event_id);
    if ($owner_user_id > 0 && $owner_user_id === $user_id) return true;
    if ($owner_user_id > 0 && class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'is_manager_of_target') && EUM_Profiles::is_manager_of_target($user_id, $owner_user_id)) return true;
    return false;
  }

  private function get_event_chat($event_id): array {
    $raw = get_post_meta((int) $event_id, self::META_CHAT, true);
    $rows = is_array($raw) ? $raw : array();
    $chat = array();
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $message = isset($row['message']) ? sanitize_textarea_field((string) $row['message']) : '';
      if ($message === '') continue;
      $chat[] = array(
        'id' => sanitize_text_field((string) (isset($row['id']) ? $row['id'] : '')),
        'user_id' => (int) (isset($row['user_id']) ? $row['user_id'] : 0),
        'message' => $message,
        'created' => (int) (isset($row['created']) ? $row['created'] : 0),
      );
    }
    usort($chat, function ($a, $b) {
      return ((int) $a['created']) <=> ((int) $b['created']);
    });
    return $chat;
  }

  private function render_event_chat($event_id, $can_edit_event = false): string {
    $event_id = (int) $event_id;
    $current_user_id = (int) get_current_user_id();
    $chat = $this->get_event_chat($event_id);
    $can_chat = $this->user_can_use_event_chat($current_user_id, $event_id);
    $can_manage_chat = $this->can_manage_event_chat($current_user_id, $event_id);
    $can_view_chat = ($can_chat || $can_manage_chat);

    ob_start();
    echo '<div class="eum-events-card eum-events-card--sub eum-event-chat">';
    echo '<div class="eum-event-chat__header"><div><h4>Event Chat</h4><p class="description">Per-event discussion for accepted attendees.</p></div>';
    echo '<div class="eum-event-chat__count">' . (int) count($chat) . ' message(s)</div></div>';
    if (!$can_view_chat) {
      echo '<div class="eum-event-chat__empty"><p class="description">Accept this event to access the chat.</p></div>';
    } elseif (empty($chat)) {
      echo '<div class="eum-event-chat__empty"><p class="description">No messages yet.</p></div>';
    } else {
      echo '<div class="eum-event-chat__messages">';
      foreach ($chat as $row) {
        $name = $this->user_label((int) $row['user_id']);
        $when = ((int) $row['created'] > 0) ? $this->format_chat_timestamp((int) $row['created'], $current_user_id) : '';
        echo '<div class="eum-event-chat__message">';
        echo '<div class="eum-event-chat__message-head"><div><strong>' . esc_html($name) . '</strong>';
        if ($when !== '') {
          echo '<span class="eum-event-chat__timestamp">' . esc_html($when) . '</span>';
        }
        echo '</div>';
        if ($can_manage_chat && !empty($row['id'])) {
          echo '<form method="post" class="eum-event-chat__delete-form" onsubmit="return confirm(&quot;Remove this message?&quot;);">';
          wp_nonce_field('eum_delete_event_chat_' . $event_id . '_' . (string) $row['id'], 'eum_delete_event_chat_nonce');
          echo '<input type="hidden" name="eum_events_action" value="delete_event_chat_message">';
          echo '<input type="hidden" name="event_id" value="' . (int) $event_id . '">';
          echo '<input type="hidden" name="message_id" value="' . esc_attr((string) $row['id']) . '">';
          echo '<button type="submit" class="button-link-delete">Remove</button>';
          echo '</form>';
        }
        echo '</div>';
        echo '<div class="eum-event-chat__bubble">' . nl2br(esc_html((string) $row['message'])) . '</div>';
        echo '</div>';
      }
      echo '</div>';
    }

    if ($can_chat) {
      echo '<form method="post" class="eum-event-chat-form">';
      wp_nonce_field('eum_event_chat_' . $event_id, 'eum_event_chat_nonce');
      echo '<input type="hidden" name="eum_events_action" value="event_chat">';
      echo '<input type="hidden" name="event_id" value="' . (int) $event_id . '">';
      echo '<label class="screen-reader-text" for="eum-event-chat-' . (int) $event_id . '">Add a message</label>';
      echo '<textarea id="eum-event-chat-' . (int) $event_id . '" name="event_chat_message" rows="3" class="large-text" placeholder="Add a message for this event..."></textarea>';
      echo '<div class="eum-event-chat__composer"><span class="description">Accepted attendees can post here.</span><button type="submit" class="button button-secondary">Post Message</button></div>';
      echo '</form>';
    } elseif ($can_manage_chat) {
      echo '<p class="description">Moderators can review messages here. Accept the event yourself to post.</p>';
    }
    echo '</div>';
    return (string) ob_get_clean();
  }

  private function format_chat_timestamp($timestamp, $user_id): string {
    $timestamp = (int) $timestamp;
    if ($timestamp <= 0) return '';
    try {
      $dt = new DateTime('@' . $timestamp);
      $dt->setTimezone($this->user_timezone((int) $user_id));
      return $dt->format('M j, Y g:i A');
    } catch (Exception $e) {
      return '';
    }
  }

  private function send_invite_emails($event_id, array $invitee_ids, $sender_user_id): array {
    $event_id = (int) $event_id;
    $sender_user_id = (int) $sender_user_id;
    $summary = array('attempted' => 0, 'sent' => 0);
    if ($event_id <= 0 || empty($invitee_ids)) return $summary;

    $event = get_post($event_id);
    if (!$event || $event->post_type !== self::CPT) return $summary;

    $from_email = $this->company_email_for_user($sender_user_id);
    $from_name = $this->user_label($sender_user_id);
    $subject = 'Event Invitation: ' . wp_specialchars_decode(get_the_title($event_id), ENT_QUOTES);

    foreach (array_values(array_unique(array_filter(array_map('intval', $invitee_ids)))) as $invitee_id) {
      $to = $this->company_email_for_user($invitee_id);
      if (!is_email($to)) continue;
      $summary['attempted']++;
      $body = $this->build_invite_email_body($event_id, $invitee_id, $sender_user_id);
      $headers = array();
      if (is_email($from_email)) {
        $from_header = ($from_name !== '' ? wp_specialchars_decode($from_name, ENT_QUOTES) . ' <' . $from_email . '>' : $from_email);
        $headers[] = 'From: ' . $from_header;
        $headers[] = 'Reply-To: ' . $from_header;
      }
      try {
        if (wp_mail($to, $subject, $body, $headers)) {
          $summary['sent']++;
        }
      } catch (\Throwable $e) {
        eum_log_message('[EUM Events] send_invite_emails: ' . sanitize_text_field($e->getMessage()));
      }
    }

    return $summary;
  }

  private function build_invite_email_body($event_id, $invitee_id, $sender_user_id): string {
    $event_id = (int) $event_id;
    $invitee_id = (int) $invitee_id;
    $sender_user_id = (int) $sender_user_id;
    $title = wp_specialchars_decode(get_the_title($event_id), ENT_QUOTES);
    $start_ts = (int) get_post_meta($event_id, self::META_START_UTC, true);
    $end_ts = (int) get_post_meta($event_id, self::META_END_UTC, true);
    $location = trim((string) get_post_meta($event_id, self::META_LOCATION, true));
    $meeting_link = trim((string) get_post_meta($event_id, self::META_MEETING_LINK, true));
    $is_remote = ((string) get_post_meta($event_id, self::META_IS_REMOTE, true) === '1');
    $creator_name = $this->user_label($sender_user_id);
    $recipient_name = $this->user_label($invitee_id);

    $lines = array();
    $lines[] = 'Hello ' . $recipient_name . ',';
    $lines[] = '';
    $lines[] = 'You have been invited to this event:';
    $lines[] = $title;
    $lines[] = '';
    $lines[] = 'When: ' . $this->format_event_range($start_ts, $end_ts, $invitee_id);
    if ($location !== '') {
      $lines[] = 'Location: ' . $location;
    }
    if ($is_remote && $meeting_link !== '') {
      $lines[] = 'Meeting Link: ' . $meeting_link;
    }
    $lines[] = 'Created by: ' . $creator_name;
    $lines[] = '';
    $lines[] = 'Please sign in to the portal Events page to RSVP and join the event chat.';
    return implode("
", $lines);
  }

  private function company_email_for_user($user_id): string {
    $user = get_userdata((int) $user_id);
    if (!$user) return '';
    return sanitize_email((string) $user->user_email);
  }

  private function accepted_attendee_total($event_id): int {
    $total = 0;
    foreach ($this->get_rsvps($event_id) as $rsvp) {
      if (!is_array($rsvp)) continue;
      if (isset($rsvp['status']) && $rsvp['status'] === 'accept') {
        $total += max(1, (int) (isset($rsvp['count']) ? $rsvp['count'] : 1));
      }
    }
    return (int) $total;
  }

  private function rsvp_summary($event_id, array $invitees = array()): array {
    $rsvps = $this->get_rsvps($event_id);
    $summary = array(
      'accepted_users' => 0, 'accepted_total' => 0, 'maybe_users' => 0, 'declined_users' => 0,
      'no_response_users' => 0, 'accepted_names' => array(),
    );
    $responded = array();
    foreach ($rsvps as $user_id => $rsvp) {
      if (!is_array($rsvp)) continue;
      $user_id = (int) $user_id;
      $responded[$user_id] = true;
      $status = isset($rsvp['status']) ? (string) $rsvp['status'] : '';
      if ($status === 'accept') {
        $summary['accepted_users']++;
        $summary['accepted_total'] += max(1, (int) (isset($rsvp['count']) ? $rsvp['count'] : 1));
        $summary['accepted_names'][] = $this->user_label($user_id) . ' (' . max(1, (int) (isset($rsvp['count']) ? $rsvp['count'] : 1)) . ')';
      } elseif ($status === 'maybe') {
        $summary['maybe_users']++;
      } elseif ($status === 'decline') {
        $summary['declined_users']++;
      }
    }
    if (!empty($invitees)) {
      foreach ($invitees as $invitee_id) {
        if (empty($responded[(int) $invitee_id])) $summary['no_response_users']++;
      }
    }
    return $summary;
  }

  private function meta_int_array($post_id, $meta_key): array {
    $value = get_post_meta((int) $post_id, $meta_key, true);
    return array_values(array_filter(array_map('intval', (array) $value)));
  }

  private function posted_int_array($key): array {
    if (!isset($_POST[$key])) return array();
    return array_values(array_filter(array_map('intval', (array) wp_unslash($_POST[$key]))));
  }

  private function get_user_availability($user_id): array {
    $raw = get_user_meta((int) $user_id, self::META_AVAILABILITY, true);
    return is_array($raw) ? $raw : array();
  }

  private function user_is_likely_available($user_id, $start_ts, $end_ts) {
    $rows = $this->get_user_availability((int) $user_id);
    if (empty($rows)) return null;

    $tz = $this->user_timezone((int) $user_id);
    $start = new DateTime('@' . (int) $start_ts);
    $start->setTimezone($tz);
    $end = new DateTime('@' . (int) $end_ts);
    $end->setTimezone($tz);
    $day_key = strtolower($start->format('D'));
    $map = array('mon' => 'monday', 'tue' => 'tuesday', 'wed' => 'wednesday', 'thu' => 'thursday', 'fri' => 'friday', 'sat' => 'saturday', 'sun' => 'sunday');
    $row_key = isset($map[$day_key]) ? $map[$day_key] : '';
    if ($row_key === '' || empty($rows[$row_key]) || empty($rows[$row_key]['enabled'])) return null;
    $row = $rows[$row_key];
    if (empty($row['start']) || empty($row['end'])) return null;

    $event_start = $start->format('H:i');
    $event_end = $end->format('H:i');
    if ($event_start >= $row['start'] && $event_end <= $row['end']) return true;
    return false;
  }

  private function field_html($label, $html, $attrs = ''): string {
    return '<div class="eum-field" ' . $attrs . '><label><strong>' . esc_html($label) . '</strong></label>' . $html . '</div>';
  }

  private function render_users_select($name, array $selected): string {
    $users = get_users(array('orderby' => 'display_name', 'number' => 5000));
    $selected = $this->sanitize_active_user_ids($selected);
    $html = '<select name="' . esc_attr($name) . '" multiple size="8" class="eum-select-multi">';
    foreach ((array) $users as $user) {
      if ($this->is_terminated_user((int) $user->ID)) continue;
      $html .= '<option value="' . (int) $user->ID . '" ' . selected(in_array((int) $user->ID, $selected, true), true, false) . '>' . esc_html($user->display_name . ' (#' . $user->ID . ')') . '</option>';
    }
    $html .= '</select>';
    return $html;
  }

  private function render_user_single_select($name, $selected_user_id, $include_none = false): string {
    $selected_user_id = (int) $selected_user_id;
    $users = get_users(array('orderby' => 'display_name', 'number' => 5000));
    $html = '<select name="' . esc_attr($name) . '">';
    if ($include_none) {
      $html .= '<option value="0">— Select User —</option>';
    }
    foreach ((array) $users as $user) {
      if ($this->is_terminated_user((int) $user->ID)) continue;
      $html .= '<option value="' . (int) $user->ID . '" ' . selected((int) $user->ID, $selected_user_id, false) . '>' . esc_html($user->display_name . ' (#' . $user->ID . ')') . '</option>';
    }
    $html .= '</select>';
    return $html;
  }

  private function sanitize_active_user_ids(array $user_ids): array {
    $clean = array();
    foreach ((array) $user_ids as $user_id) {
      $user_id = (int) $user_id;
      if ($user_id <= 0 || $this->is_terminated_user($user_id)) continue;
      if (get_userdata($user_id)) $clean[$user_id] = $user_id;
    }
    ksort($clean);
    return array_values($clean);
  }

  private function is_terminated_user($user_id): bool {
    $user_id = (int) $user_id;
    return $user_id > 0 && class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
  }

  private function sanitize_event_owner_user_id($owner_user_id, $fallback_user_id): int {
    $owner_user_id = (int) $owner_user_id;
    if ($owner_user_id > 0 && get_userdata($owner_user_id) && !$this->is_terminated_user($owner_user_id)) return $owner_user_id;
    $fallback_user_id = (int) $fallback_user_id;
    if ($fallback_user_id > 0 && get_userdata($fallback_user_id) && !$this->is_terminated_user($fallback_user_id)) return $fallback_user_id;
    return (int) get_current_user_id();
  }

  private function render_rsvp_manager_table($event_id, array $invitees, array $rsvps, $can_edit): string {
    $event_id = (int) $event_id;
    $rows = array();
    foreach ($invitees as $invitee_id) {
      $invitee_id = (int) $invitee_id;
      if ($invitee_id <= 0) continue;
      $rows[$invitee_id] = array(
        'user_id' => $invitee_id,
        'status' => isset($rsvps[$invitee_id]['status']) ? (string) $rsvps[$invitee_id]['status'] : '',
        'count' => isset($rsvps[$invitee_id]['count']) ? max(1, (int) $rsvps[$invitee_id]['count']) : 1,
        'updated' => isset($rsvps[$invitee_id]['updated']) ? (int) $rsvps[$invitee_id]['updated'] : 0,
        'note' => '',
      );
    }
    foreach ($rsvps as $user_id => $rsvp) {
      if (!is_array($rsvp)) continue;
      $user_id = (int) $user_id;
      if ($user_id <= 0) continue;
      if (!isset($rows[$user_id])) {
        $note = $this->is_terminated_user($user_id) ? 'Terminated after invite' : 'Not currently invited';
        $rows[$user_id] = array(
          'user_id' => $user_id,
          'status' => isset($rsvp['status']) ? (string) $rsvp['status'] : '',
          'count' => isset($rsvp['count']) ? max(1, (int) $rsvp['count']) : 1,
          'updated' => isset($rsvp['updated']) ? (int) $rsvp['updated'] : 0,
          'note' => $note,
        );
      }
    }
    if (empty($rows)) return '<p class="description">No invitees or RSVPs yet.</p>';

    uasort($rows, function ($a, $b) {
      return strcasecmp($this->user_label((int) $a['user_id']), $this->user_label((int) $b['user_id']));
    });

    ob_start();
    echo '<div class="eum-event-rsvp-table-wrap">';
    echo '<table class="widefat striped eum-event-rsvp-table"><thead><tr><th>User</th><th>Status</th><th>Attendees</th><th>Updated</th><th>Notes</th>';
    if ($can_edit) echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $status = (string) $row['status'];
      $status_label = $status === 'accept' ? 'Accepted' : ($status === 'maybe' ? 'Maybe' : ($status === 'decline' ? 'Declined' : 'No Response'));
      echo '<tr>';
      echo '<td>' . esc_html($this->user_label((int) $row['user_id'])) . '</td>';
      echo '<td>' . esc_html($status_label) . '</td>';
      echo '<td>' . (($status === 'accept') ? (int) $row['count'] : '—') . '</td>';
      echo '<td>' . ((int) $row['updated'] > 0 ? esc_html($this->format_chat_timestamp((int) $row['updated'], (int) get_current_user_id())) : '—') . '</td>';
      echo '<td>' . esc_html((string) $row['note']) . '</td>';
      if ($can_edit) {
        echo '<td>';
        if ($status !== '') {
          echo '<form method="post" class="eum-event-rsvp-reset-form">';
          wp_nonce_field('eum_reset_event_rsvp_' . $event_id . '_' . (int) $row['user_id'], 'eum_reset_rsvp_nonce');
          echo '<input type="hidden" name="eum_events_action" value="reset_event_rsvp">';
          echo '<input type="hidden" name="event_id" value="' . (int) $event_id . '">';
          echo '<input type="hidden" name="target_user_id" value="' . (int) $row['user_id'] . '">';
          echo '<button type="submit" class="button-link">Reset RSVP</button>';
          echo '</form>';
        } else {
          echo '—';
        }
        echo '</td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    return (string) ob_get_clean();
  }

  private function render_term_select($taxonomy, $name, array $selected, $hierarchical = false): string {
    if (!taxonomy_exists($taxonomy)) return '<p class="description">This taxonomy is unavailable.</p>';
    $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 0));
    if (is_wp_error($terms)) return '<p class="description">Terms could not be loaded.</p>';
    $html = '<select name="' . esc_attr($name) . '" multiple size="8" class="eum-select-multi">';
    foreach ((array) $terms as $term) {
      $label = $hierarchical ? $this->term_path_label((int) $term->term_id, $taxonomy) : $term->name;
      $html .= '<option value="' . (int) $term->term_id . '" ' . selected(in_array((int) $term->term_id, $selected, true), true, false) . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select>';
    return $html;
  }

  private function term_path_label($term_id, $taxonomy): string {
    $term_id = (int) $term_id;
    $parts = array();
    while ($term_id > 0) {
      $term = get_term($term_id, $taxonomy);
      if (!$term || is_wp_error($term)) break;
      array_unshift($parts, $term->name);
      $term_id = (int) $term->parent;
    }
    return implode(' / ', $parts);
  }

  private function user_label($user_id): string {
    $user = get_userdata((int) $user_id);
    if (!$user) return 'User #' . (int) $user_id;
    return (string) $user->display_name;
  }

  private function user_timezone($user_id): DateTimeZone {
    $user_id = (int) $user_id;
    $tz_string = '';
    if ($user_id > 0) $tz_string = (string) get_user_meta($user_id, 'timezone', true);
    if ($tz_string === '') $tz_string = (string) get_option('timezone_string', '');
    if ($tz_string === '') $tz_string = self::DEFAULT_TIMEZONE;
    try {
      return new DateTimeZone($tz_string);
    } catch (Exception $e) {
      return new DateTimeZone(self::DEFAULT_TIMEZONE);
    }
  }

  private function user_timezone_label($user_id): string {
    $tz = $this->user_timezone((int) $user_id);
    return $tz->getName();
  }

  private function local_input_to_utc($value, DateTimeZone $timezone): int {
    $value = trim((string) $value);
    if ($value === '') return 0;
    try {
      $dt = new DateTime($value, $timezone);
      $dt->setTimezone(new DateTimeZone('UTC'));
      return (int) $dt->getTimestamp();
    } catch (Exception $e) {
      return 0;
    }
  }

  private function utc_to_local_input($timestamp, DateTimeZone $timezone): string {
    $timestamp = (int) $timestamp;
    if ($timestamp <= 0) return '';
    try {
      $dt = new DateTime('@' . $timestamp);
      $dt->setTimezone($timezone);
      return $dt->format('Y-m-d\TH:i');
    } catch (Exception $e) {
      return '';
    }
  }

  private function format_event_range($start_ts, $end_ts, $user_id): string {
    $tz = $this->user_timezone((int) $user_id);
    if ((int) $start_ts <= 0 || (int) $end_ts <= 0) return 'Time unavailable';
    $start = new DateTime('@' . (int) $start_ts);
    $start->setTimezone($tz);
    $end = new DateTime('@' . (int) $end_ts);
    $end->setTimezone($tz);
    return $start->format('M j, Y g:i A') . ' – ' . $end->format('M j, Y g:i A') . ' (' . $tz->getName() . ')';
  }

  private function current_url_without(array $remove_keys): string {
    $path = isset($GLOBALS['wp']) && isset($GLOBALS['wp']->request) ? $GLOBALS['wp']->request : '';
    $base = home_url($path === '' ? '/' : '/' . ltrim((string) $path, '/'));
    $query = array();
    foreach ($_GET as $key => $value) {
      if (in_array($key, $remove_keys, true)) continue;
      $query[$key] = wp_unslash($value);
    }
    return add_query_arg($query, $base);
  }

  private function redirect_with_notice($message): void {
    $url = $this->current_url_without(array('eum_events_notice'));
    $url = add_query_arg('eum_events_notice', rawurlencode((string) $message), $url);
    wp_safe_redirect($url);
    exit;
  }

  private function weekday_labels(): array {
    return array(
      'monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday',
      'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday',
    );
  }

  private function truthy($value): bool {
    $value = strtolower(trim((string) $value));
    return !in_array($value, array('0', 'false', 'no', ''), true);
  }
}
