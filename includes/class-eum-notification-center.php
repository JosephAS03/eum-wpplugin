<?php
if (!defined('ABSPATH')) exit;

class EUM_Notification_Center {
  public const CPT_NOTIFICATION = 'eum_notification';
  public const META_USER_ID = '_eum_notification_user_id';
  public const META_ACTOR_USER_ID = '_eum_notification_actor_user_id';
  public const META_TYPE = '_eum_notification_type';
  public const META_ACTION_URL = '_eum_notification_action_url';
  public const META_IS_READ = '_eum_notification_is_read';
  public const META_READ_AT = '_eum_notification_read_at';
  public const META_RELATED_OBJECT_ID = '_eum_notification_related_object_id';
  public const META_IMPORTANCE = '_eum_notification_importance';
  public const USER_META_FIRST_PORTAL_LOGIN_AT = '_eum_first_portal_login_at';

  public function init(): void {
    add_action('init', [$this, 'register_post_type']);
    add_shortcode('eum_notification_center', [$this, 'shortcode']);
    add_action('admin_post_eum_notification_toggle', [$this, 'handle_toggle']);
    add_action('wp_ajax_eum_notification_popup_list', [$this, 'ajax_popup_list']);
    add_action('wp_ajax_eum_notification_popup_read', [$this, 'ajax_popup_mark_read']);
    add_action('wp_ajax_eum_notification_popup_unread', [$this, 'ajax_popup_mark_unread']);
    add_action('wp_ajax_eum_notification_popup_read_all', [$this, 'ajax_popup_mark_all_read']);

    add_action('wp_login', [$this, 'handle_first_portal_login'], 40, 2);
    add_action('eum_profile_change_pending', [$this, 'handle_profile_change_pending'], 20, 4);
    add_action('eum_profile_change_applied', [$this, 'handle_profile_change_applied'], 20, 2);
    add_action('eum_leave_request_created', [$this, 'handle_leave_request_created'], 20, 3);
    add_action('eum_leave_status_changed', [$this, 'handle_leave_status_changed'], 20, 4);
    add_action('eum_availability_request_created', [$this, 'handle_availability_request_created'], 20, 4);
    add_action('eum_availability_status_changed', [$this, 'handle_availability_status_changed'], 20, 4);
    add_action('eum_schedule_drafts_published', [$this, 'handle_schedule_drafts_published'], 20, 1);
  }

  public function register_post_type(): void {
    register_post_type(self::CPT_NOTIFICATION, [
      'labels' => [
        'name' => 'Notifications',
        'singular_name' => 'Notification',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'editor', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public static function create_notification(int $user_id, array $args): int {
    try {
      $user_id = (int)$user_id;
      if ($user_id <= 0 || !get_userdata($user_id)) return 0;

      $title = sanitize_text_field((string)($args['title'] ?? ''));
      if ($title === '') return 0;

      $body = sanitize_textarea_field((string)($args['body'] ?? ''));
      $type = sanitize_key((string)($args['type'] ?? 'general'));
      if ($type === '') $type = 'general';
      $action_url = esc_url_raw((string)($args['action_url'] ?? home_url('/')));
      $actor_user_id = max(0, (int)($args['actor_user_id'] ?? 0));
      $related_object_id = max(0, (int)($args['related_object_id'] ?? 0));
      $importance = in_array((string)($args['importance'] ?? 'normal'), ['normal', 'high'], true) ? (string)$args['importance'] : 'normal';

      $post_id = wp_insert_post([
        'post_type' => self::CPT_NOTIFICATION,
        'post_status' => 'publish',
        'post_title' => $title,
        'post_content' => $body,
        'post_author' => $actor_user_id > 0 ? $actor_user_id : $user_id,
      ], true);

      if (is_wp_error($post_id) || (int)$post_id <= 0) return 0;
      $post_id = (int)$post_id;

      update_post_meta($post_id, self::META_USER_ID, $user_id);
      update_post_meta($post_id, self::META_ACTOR_USER_ID, $actor_user_id);
      update_post_meta($post_id, self::META_TYPE, $type);
      update_post_meta($post_id, self::META_ACTION_URL, $action_url);
      update_post_meta($post_id, self::META_IS_READ, 0);
      update_post_meta($post_id, self::META_RELATED_OBJECT_ID, $related_object_id);
      update_post_meta($post_id, self::META_IMPORTANCE, $importance);

      $payload = [
        'notification_id' => $post_id,
        'user_id' => $user_id,
        'title' => $title,
        'body' => $body,
        'type' => $type,
        'action_url' => $action_url,
        'actor_user_id' => $actor_user_id,
        'related_object_id' => $related_object_id,
        'importance' => $importance,
        'deliver_email' => !empty($args['deliver_email']),
        'deliver_discord' => !empty($args['deliver_discord']),
      ];

      if (!empty($args['deliver_email'])) {
        self::email_notification_to_user($user_id, $title, $body, $action_url);
      }

      do_action('eum_notification_created', $post_id, $user_id, $payload);
      return $post_id;
    } catch (\Throwable $e) {
      self::log_runtime_issue('create_notification', $e);
      return 0;
    }
  }

  public static function create_notifications(array $user_ids, array $args): array {
    $created = [];
    foreach (self::normalized_user_ids($user_ids) as $user_id) {
      $notification_id = self::create_notification($user_id, $args);
      if ($notification_id > 0) $created[] = $notification_id;
    }
    return $created;
  }

  public static function unread_count_for_user(int $user_id, array $exclude_types = [], array $include_types = []): int {
    return self::notification_count_for_user($user_id, 'unread', $exclude_types, $include_types);
  }

  private static function notification_count_for_user(int $user_id, string $status, array $exclude_types = [], array $include_types = []): int {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return 0;
    $meta_query = [
      ['key' => self::META_USER_ID, 'value' => $user_id],
      ['key' => self::META_IS_READ, 'value' => $status === 'read' ? '1' : '0'],
    ];
    $exclude_types = array_values(array_filter(array_map('sanitize_key', $exclude_types)));
    $include_types = array_values(array_filter(array_map('sanitize_key', $include_types)));
    if (!empty($exclude_types)) $meta_query[] = ['key' => self::META_TYPE, 'value' => $exclude_types, 'compare' => 'NOT IN'];
    if (!empty($include_types)) $meta_query[] = ['key' => self::META_TYPE, 'value' => $include_types, 'compare' => 'IN'];
    $query = new WP_Query([
      'post_type' => self::CPT_NOTIFICATION,
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'fields' => 'ids',
      'no_found_rows' => false,
      'meta_query' => $meta_query,
    ]);
    return (int)$query->found_posts;
  }

  public function ajax_popup_list(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Please sign in again.'], 401);
    check_ajax_referer('eum_notification_popup', 'nonce');
    $user_id = get_current_user_id();
    $limit = 30;
    $offset = absint($_POST['offset'] ?? 0);
    $status = sanitize_key(wp_unslash((string)($_POST['status'] ?? 'unread')));
    if (!in_array($status, ['unread', 'read'], true)) $status = 'unread';
    $items = self::notifications_for_user($user_id, [
      'limit' => $limit,
      'offset' => $offset,
      'status' => $status,
      'exclude_types' => ['direct_message'],
    ]);
    $rows = [];
    foreach ($items as $item) {
      $notification_id = (int)$item->ID;
      $rows[] = [
        'id' => $notification_id,
        'title' => (string)$item->post_title,
        'body' => (string)$item->post_content,
        'type' => (string)get_post_meta($notification_id, self::META_TYPE, true),
        'action_url' => (string)get_post_meta($notification_id, self::META_ACTION_URL, true),
        'related_object_id' => (int)get_post_meta($notification_id, self::META_RELATED_OBJECT_ID, true),
        'importance' => (string)get_post_meta($notification_id, self::META_IMPORTANCE, true),
        'time' => self::human_time_for_post($notification_id),
      ];
    }
    $unread_count = self::unread_count_for_user($user_id, ['direct_message']);
    $total_count = self::notification_count_for_user($user_id, $status, ['direct_message']);
    wp_send_json_success([
      'items' => $rows,
      'unread_count' => $unread_count,
      'total_count' => $total_count,
      'has_more' => ($offset + count($rows)) < $total_count,
      'status' => $status,
    ]);
  }

  public function ajax_popup_mark_read(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Please sign in again.'], 401);
    check_ajax_referer('eum_notification_popup', 'nonce');
    $notification_id = absint($_POST['notification_id'] ?? 0);
    if ($notification_id <= 0
      || (int)get_post_meta($notification_id, self::META_USER_ID, true) !== get_current_user_id()
      || (string)get_post_meta($notification_id, self::META_TYPE, true) === 'direct_message') {
      wp_send_json_error(['message' => 'That notification is not available.'], 404);
    }
    update_post_meta($notification_id, self::META_IS_READ, 1);
    update_post_meta($notification_id, self::META_READ_AT, current_time('mysql'));
    wp_send_json_success(['unread_count' => self::unread_count_for_user(get_current_user_id(), ['direct_message'])]);
  }

  public function ajax_popup_mark_unread(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Please sign in again.'], 401);
    check_ajax_referer('eum_notification_popup', 'nonce');
    $notification_id = absint($_POST['notification_id'] ?? 0);
    if ($notification_id <= 0
      || (int)get_post_meta($notification_id, self::META_USER_ID, true) !== get_current_user_id()
      || (string)get_post_meta($notification_id, self::META_TYPE, true) === 'direct_message') {
      wp_send_json_error(['message' => 'That notification is not available.'], 404);
    }
    update_post_meta($notification_id, self::META_IS_READ, 0);
    delete_post_meta($notification_id, self::META_READ_AT);
    wp_send_json_success(['unread_count' => self::unread_count_for_user(get_current_user_id(), ['direct_message'])]);
  }

  public function ajax_popup_mark_all_read(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Please sign in again.'], 401);
    check_ajax_referer('eum_notification_popup', 'nonce');
    $user_id = get_current_user_id();
    $items = self::notifications_for_user($user_id, [
      'limit' => -1,
      'status' => 'unread',
      'exclude_types' => ['direct_message'],
    ]);
    $read_at = current_time('mysql');
    foreach ($items as $item) {
      update_post_meta((int)$item->ID, self::META_IS_READ, 1);
      update_post_meta((int)$item->ID, self::META_READ_AT, $read_at);
    }
    wp_send_json_success(['unread_count' => self::unread_count_for_user($user_id, ['direct_message'])]);
  }

  public static function dashboard_panel_html(int $user_id, int $limit = 6): string {
    $items = self::notifications_for_user($user_id, ['limit' => $limit, 'status' => 'all']);
    if (empty($items)) {
      return '<p class="eum-home-card__empty">No notifications yet.</p>';
    }

    ob_start();
    echo '<div class="eum-home-attention eum-home-attention--notifications">';
    foreach ($items as $item) {
      $notification_id = (int)$item->ID;
      $is_read = self::is_read($notification_id);
      $action_url = esc_url((string)get_post_meta($notification_id, self::META_ACTION_URL, true));
      echo '<article class="eum-home-attention__item' . ($is_read ? ' is-read' : '') . '">';
      echo '<div class="eum-home-attention__copy">';
      echo '<strong>' . esc_html((string)$item->post_title) . '</strong>';
      if (trim((string)$item->post_content) !== '') {
        echo '<span>' . esc_html((string)$item->post_content) . '</span>';
      }
      echo '<span>' . esc_html(self::human_time_for_post($notification_id)) . ($is_read ? ' | Read' : ' | Unread') . '</span>';
      echo '</div>';
      echo '<div class="eum-home-attention__actions">';
      if ($action_url !== '') echo '<a class="button button-small" href="' . $action_url . '">Open</a>';
      echo self::toggle_form($notification_id, $is_read, self::current_url());
      echo '</div>';
      echo '</article>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  public function shortcode($atts = []): string {
    if (!is_user_logged_in()) return '<p>Please sign in to view notifications.</p>';
    return '<p class="eum-notification-topbar-hint">Open Notifications from the portal top bar to review and clear updates.</p>';
  }

  public function handle_toggle(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $notification_id = max(0, (int)($_POST['notification_id'] ?? 0));
    $mark = sanitize_key((string)($_POST['mark'] ?? 'read'));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? self::current_url()));
    if ($notification_id <= 0 || !isset($_POST['eum_notification_nonce']) || !wp_verify_nonce((string)$_POST['eum_notification_nonce'], 'eum_notification_toggle_' . $notification_id)) {
      wp_die('Security check failed.');
    }
    $user_id = get_current_user_id();
    $owner_user_id = (int)get_post_meta($notification_id, self::META_USER_ID, true);
    if ($owner_user_id !== $user_id) wp_die('Not allowed.');

    if ($mark === 'unread') {
      update_post_meta($notification_id, self::META_IS_READ, 0);
      delete_post_meta($notification_id, self::META_READ_AT);
    } else {
      update_post_meta($notification_id, self::META_IS_READ, 1);
      update_post_meta($notification_id, self::META_READ_AT, current_time('mysql'));
    }

    wp_safe_redirect($return_url ?: home_url('/'));
    exit;
  }

  public function handle_first_portal_login(string $user_login, $user): void {
    try {
      if (!$user instanceof WP_User) return;
      $user_id = (int)$user->ID;
      if ($user_id <= 0) return;
      if ((string)get_user_meta($user_id, self::USER_META_FIRST_PORTAL_LOGIN_AT, true) !== '') return;

      update_user_meta($user_id, self::USER_META_FIRST_PORTAL_LOGIN_AT, current_time('mysql'));
      $manager_ids = $this->manager_chain_ids_for_user($user_id);
      if (empty($manager_ids)) return;

      $title = 'First Portal Login';
      $body = self::employee_label($user_id) . ' signed into the Enterprise portal for the first time.';
      self::create_notifications($manager_ids, [
        'title' => $title,
        'body' => $body,
        'type' => 'first_login',
        'action_url' => home_url('/'),
        'related_object_id' => $user_id,
        'deliver_email' => true,
        'deliver_discord' => true,
      ]);
      do_action('eum_activity_logged', $body, ['source' => 'portal', 'type' => 'first_login', 'user_id' => $user_id]);
    } catch (\Throwable $e) {
      self::log_runtime_issue('handle_first_portal_login', $e);
    }
  }

  public function handle_profile_change_pending(int $change_id, int $target_user_id, array $approver_ids, int $actor_user_id = 0): void {
    try {
      $approver_ids = self::normalized_user_ids($approver_ids);
      if ($change_id <= 0 || $target_user_id <= 0 || empty($approver_ids)) return;
      $title = 'Action Item: Profile Approval';
      $body = 'A profile change for ' . self::employee_label($target_user_id) . ' is waiting for approval.';
      self::create_notifications($approver_ids, [
        'title' => $title,
        'body' => $body,
        'type' => 'profile_approval',
        'action_url' => home_url('/'),
        'actor_user_id' => $actor_user_id,
        'related_object_id' => $change_id,
        'deliver_email' => true,
        'deliver_discord' => true,
        'importance' => 'high',
      ]);
      do_action('eum_activity_logged', $body, ['source' => 'portal', 'type' => 'profile_approval', 'user_id' => $target_user_id, 'actor_user_id' => $actor_user_id]);
    } catch (\Throwable $e) {
      self::log_runtime_issue('handle_profile_change_pending', $e);
    }
  }

  public function handle_profile_change_applied(int $change_id, int $target_user_id): void {
    try {
      if ($change_id <= 0 || $target_user_id <= 0 || !class_exists('EUM_Profiles')) return;
      $actor_user_id = (int)get_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, true);
      $reason = sanitize_text_field((string)get_post_meta($change_id, EUM_Profiles::META_NOTE, true));
      $actor_label = $actor_user_id > 0 ? self::employee_label($actor_user_id) : 'the portal team';
      $body = 'Your profile was updated by ' . $actor_label . '.';
      if ($reason !== '') $body .= ' Reason: ' . $reason . '.';
      self::create_notification($target_user_id, [
        'title' => 'Profile Updated',
        'body' => $body,
        'type' => 'profile_update',
        'action_url' => home_url('/'),
        'actor_user_id' => $actor_user_id,
        'related_object_id' => $change_id,
        'deliver_email' => true,
        'deliver_discord' => true,
      ]);
      do_action('eum_activity_logged', 'Profile updated for ' . self::employee_label($target_user_id) . '.', ['source' => 'portal', 'type' => 'profile_update', 'user_id' => $target_user_id, 'actor_user_id' => $actor_user_id]);
    } catch (\Throwable $e) {
      self::log_runtime_issue('handle_profile_change_applied', $e);
    }
  }

  public function handle_leave_request_created(int $leave_id, int $target_user_id = 0, int $actor_user_id = 0): void {
    try {
      if ($leave_id <= 0 || !class_exists('EUM_Workforce_Lifecycle')) return;
      $status = (string)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_STATUS, true);
      if ($status !== 'pending') return;
      $approver_ids = self::normalized_user_ids((array)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_APPROVER_IDS, true));
      if (empty($approver_ids)) return;
      $type = EUM_Workforce_Lifecycle::leave_type((string)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_TYPE, true));
      $title = 'Action Item: Leave Approval';
      $body = (string)($type['label'] ?? 'Leave Request') . ' for ' . self::employee_label($target_user_id) . ' needs approval.';
      self::create_notifications($approver_ids, [
        'title' => $title,
        'body' => $body,
        'type' => 'leave_approval',
        'action_url' => home_url('/'),
        'actor_user_id' => $actor_user_id,
        'related_object_id' => $leave_id,
        'deliver_email' => true,
        'deliver_discord' => true,
        'importance' => 'high',
      ]);
      do_action('eum_activity_logged', $body, ['source' => 'portal', 'type' => 'leave_approval', 'user_id' => $target_user_id, 'actor_user_id' => $actor_user_id]);
    } catch (\Throwable $e) {
      self::log_runtime_issue('handle_leave_request_created', $e);
    }
  }

  public function handle_leave_status_changed(int $leave_id, int $target_user_id = 0, string $status = '', int $actor_user_id = 0): void {
    try {
      if ($leave_id <= 0 || $target_user_id <= 0 || !class_exists('EUM_Workforce_Lifecycle')) return;
      $status = sanitize_key($status);
      if ($status === '') return;
      $requestor_id = (int)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_ACTOR_USER, true);
      $type = EUM_Workforce_Lifecycle::leave_type((string)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_TYPE, true));
      $title = 'Leave Request ' . ucfirst($status);
      $body = (string)($type['label'] ?? 'Leave Request') . ' for ' . self::employee_label($target_user_id) . ' was ' . $status . '.';
      self::create_notifications(self::normalized_user_ids([$target_user_id, $requestor_id]), [
        'title' => $title,
        'body' => $body,
        'type' => 'leave_status',
        'action_url' => home_url('/'),
        'actor_user_id' => $actor_user_id,
        'related_object_id' => $leave_id,
        'deliver_email' => true,
        'deliver_discord' => true,
      ]);
      do_action('eum_activity_logged', $body, ['source' => 'portal', 'type' => 'leave_status', 'user_id' => $target_user_id, 'actor_user_id' => $actor_user_id]);
    } catch (\Throwable $e) {
      self::log_runtime_issue('handle_leave_status_changed', $e);
    }
  }

  public function handle_availability_request_created(int $request_id, int $target_user_id = 0, int $actor_user_id = 0, string $status = 'pending'): void {
    try {
      if ($request_id <= 0 || $target_user_id <= 0 || !class_exists('EUM_Workforce_Lifecycle') || sanitize_key($status) !== 'pending') return;
      $approver_ids = self::normalized_user_ids((array)get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_APPROVER_IDS, true));
      if (empty($approver_ids)) return;
      $title = 'Action Item: Availability Approval';
      $body = 'An availability update for ' . self::employee_label($target_user_id) . ' needs approval.';
      self::create_notifications($approver_ids, [
        'title' => $title,
        'body' => $body,
        'type' => 'availability_approval',
        'action_url' => home_url('/'),
        'actor_user_id' => $actor_user_id,
        'related_object_id' => $request_id,
        'deliver_email' => true,
        'deliver_discord' => true,
        'importance' => 'high',
      ]);
      do_action('eum_activity_logged', $body, ['source' => 'portal', 'type' => 'availability_approval', 'user_id' => $target_user_id, 'actor_user_id' => $actor_user_id]);
    } catch (\Throwable $e) {
      self::log_runtime_issue('handle_availability_request_created', $e);
    }
  }

  public function handle_availability_status_changed(int $request_id, int $target_user_id = 0, string $status = '', int $actor_user_id = 0): void {
    try {
      if ($request_id <= 0 || $target_user_id <= 0 || !class_exists('EUM_Workforce_Lifecycle')) return;
      $status = sanitize_key($status);
      if ($status === '') return;
      $requestor_id = (int)get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_ACTOR_USER, true);
      $title = 'Availability Request ' . ucfirst($status);
      $body = 'Availability for ' . self::employee_label($target_user_id) . ' was ' . $status . '.';
      self::create_notifications(self::normalized_user_ids([$target_user_id, $requestor_id]), [
        'title' => $title,
        'body' => $body,
        'type' => 'availability_status',
        'action_url' => home_url('/'),
        'actor_user_id' => $actor_user_id,
        'related_object_id' => $request_id,
        'deliver_email' => true,
        'deliver_discord' => true,
      ]);
      do_action('eum_activity_logged', $body, ['source' => 'portal', 'type' => 'availability_status', 'user_id' => $target_user_id, 'actor_user_id' => $actor_user_id]);
    } catch (\Throwable $e) {
      self::log_runtime_issue('handle_availability_status_changed', $e);
    }
  }

  public function handle_schedule_drafts_published(array $payload): void {
    try {
      $user_ids = self::normalized_user_ids((array)($payload['user_ids'] ?? []));
      $range_start_utc = max(0, (int)($payload['range_start_utc'] ?? 0));
      $actor_user_id = max(0, (int)($payload['actor_user_id'] ?? 0));
      if (empty($user_ids)) return;
      $week_label = $range_start_utc > 0 ? wp_date('F j, Y', $range_start_utc, wp_timezone()) : 'the upcoming week';
      self::create_notifications($user_ids, [
        'title' => 'Schedule Posted',
        'body' => 'Your schedule for the week of ' . $week_label . ' was posted or updated.',
        'type' => 'schedule_posted',
        'action_url' => home_url('/'),
        'actor_user_id' => $actor_user_id,
        'deliver_email' => true,
        'deliver_discord' => true,
      ]);
      do_action('eum_activity_logged', 'Schedule posted for ' . count($user_ids) . ' user(s) for the week of ' . $week_label . '.', ['source' => 'portal', 'type' => 'schedule_posted', 'actor_user_id' => $actor_user_id]);
    } catch (\Throwable $e) {
      self::log_runtime_issue('handle_schedule_drafts_published', $e);
    }
  }

  public static function notifications_for_user(int $user_id, array $args = []): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return [];
    $status = in_array((string)($args['status'] ?? 'all'), ['all', 'unread', 'read'], true) ? (string)$args['status'] : 'all';
    $meta_query = [
      ['key' => self::META_USER_ID, 'value' => $user_id],
    ];
    if ($status === 'unread') $meta_query[] = ['key' => self::META_IS_READ, 'value' => '0'];
    if ($status === 'read') $meta_query[] = ['key' => self::META_IS_READ, 'value' => '1'];
    $exclude_types = array_values(array_filter(array_map('sanitize_key', (array)($args['exclude_types'] ?? []))));
    if (!empty($exclude_types)) $meta_query[] = ['key' => self::META_TYPE, 'value' => $exclude_types, 'compare' => 'NOT IN'];
    $limit = (int)($args['limit'] ?? 20);
    return get_posts([
      'post_type' => self::CPT_NOTIFICATION,
      'post_status' => 'publish',
      'numberposts' => $limit === -1 ? -1 : max(1, $limit),
      'orderby' => 'date',
      'order' => 'DESC',
      'offset' => max(0, (int)($args['offset'] ?? 0)),
      'meta_query' => $meta_query,
    ]);
  }

  private static function render_notification_center_list(array $items, int $user_id): string {
    if (empty($items)) return '<p>No notifications were found.</p>';
    ob_start();
    echo '<div style="display:grid;gap:14px;">';
    foreach ($items as $item) {
      $notification_id = (int)$item->ID;
      $is_read = self::is_read($notification_id);
      $action_url = esc_url((string)get_post_meta($notification_id, self::META_ACTION_URL, true));
      $importance = (string)get_post_meta($notification_id, self::META_IMPORTANCE, true);
      $border = $importance === 'high' ? '#d63638' : '#dcdcde';
      echo '<article style="background:#fff;border:1px solid ' . esc_attr($border) . ';border-radius:14px;padding:16px;">';
      echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
      echo '<div style="min-width:0;flex:1 1 320px;">';
      echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">';
      echo '<strong>' . esc_html((string)$item->post_title) . '</strong>';
      echo '<span style="display:inline-flex;padding:2px 8px;border-radius:999px;background:' . ($is_read ? '#eef2f7;color:#475569' : '#dbeafe;color:#1d4ed8') . ';font-size:12px;font-weight:700;">' . esc_html($is_read ? 'Read' : 'Unread') . '</span>';
      echo '</div>';
      if (trim((string)$item->post_content) !== '') echo '<p style="margin:0 0 10px;">' . esc_html((string)$item->post_content) . '</p>';
      echo '<p style="margin:0;color:#667085;font-size:12px;">' . esc_html(self::human_time_for_post($notification_id)) . '</p>';
      echo '</div>';
      echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
      if ($action_url !== '') echo '<a class="button button-secondary" href="' . $action_url . '">Open</a>';
      echo self::toggle_form($notification_id, $is_read, self::current_url());
      echo '</div></div></article>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  private static function toggle_form(int $notification_id, bool $is_read, string $return_url): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
    echo '<input type="hidden" name="action" value="eum_notification_toggle">';
    echo '<input type="hidden" name="notification_id" value="' . (int)$notification_id . '">';
    echo '<input type="hidden" name="mark" value="' . esc_attr($is_read ? 'unread' : 'read') . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_notification_toggle_' . $notification_id, 'eum_notification_nonce');
    echo '<button class="button button-small" type="submit">' . esc_html($is_read ? 'Mark Unread' : 'Mark Read') . '</button>';
    echo '</form>';
    return (string)ob_get_clean();
  }

  private static function email_notification_to_user(int $user_id, string $title, string $body, string $action_url = ''): bool {
    try {
      $user = get_userdata($user_id);
      if (!$user || self::is_terminated_user($user_id)) return false;
      $to = sanitize_email((string)$user->user_email);
      if ($to === '') return false;

      $message = '<p>' . esc_html($title) . '</p>';
      if ($body !== '') $message .= '<p>' . nl2br(esc_html($body)) . '</p>';
      if ($action_url !== '') $message .= '<p><a href="' . esc_url($action_url) . '">Open in the portal</a></p>';
      return wp_mail($to, $title, $message, ['Content-Type: text/html; charset=UTF-8']);
    } catch (\Throwable $e) {
      self::log_runtime_issue('email_notification_to_user', $e);
      return false;
    }
  }

  private function manager_chain_ids_for_user(int $user_id): array {
    if ($user_id <= 0) return [];
    if (class_exists('EUM_Manager_IDs') && method_exists('EUM_Manager_IDs', 'manager_user_ids_for_subject_user')) {
      return self::normalized_user_ids((array)EUM_Manager_IDs::manager_user_ids_for_subject_user($user_id));
    }
    if (class_exists('EUM_Personnel')) {
      $manager_id = (int)EUM_Personnel::effective_manager_user_id($user_id);
      return self::normalized_user_ids([$manager_id]);
    }
    return [];
  }

  private static function employee_label(int $user_id): string {
    if ($user_id <= 0) return 'User #' . $user_id;
    if (class_exists('EUM_Workforce_Lifecycle') && method_exists('EUM_Workforce_Lifecycle', 'employee_list_label')) {
      return (string)EUM_Workforce_Lifecycle::employee_list_label($user_id);
    }
    $user = get_userdata($user_id);
    return $user ? (string)($user->display_name ?: $user->user_login) : ('User #' . $user_id);
  }

  private static function normalized_user_ids(array $user_ids): array {
    return array_values(array_unique(array_filter(array_map('intval', $user_ids), static fn($user_id) => $user_id > 0)));
  }

  private static function is_read(int $notification_id): bool {
    return (string)get_post_meta($notification_id, self::META_IS_READ, true) === '1';
  }

  private static function human_time_for_post(int $post_id): string {
    $post = get_post($post_id);
    if (!$post) return '';
    return get_date_from_gmt((string)$post->post_date_gmt, 'Y-m-d g:i A');
  }

  private static function current_url(): string {
    $scheme = is_ssl() ? 'https' : 'http';
    $host = sanitize_text_field((string)($_SERVER['HTTP_HOST'] ?? ''));
    $uri = wp_unslash((string)($_SERVER['REQUEST_URI'] ?? '/'));
    if ($host === '') return home_url('/');
    return esc_url_raw($scheme . '://' . $host . $uri);
  }

  private static function is_terminated_user(int $user_id): bool {
    return class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
  }

  private static function log_runtime_issue(string $context, \Throwable $e): void {
    eum_log_message('[EUM Notification Center] ' . $context . ': ' . sanitize_text_field($e->getMessage()));
  }
}
