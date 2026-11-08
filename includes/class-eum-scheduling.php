<?php
if (!defined('ABSPATH')) exit;

class EUM_Scheduling {
  public const CPT_SHIFT = 'eum_schedule_shift';

  public const META_USER_ID = '_eum_schedule_user_id';
  public const META_SHIFT_START_UTC = '_eum_schedule_start_utc';
  public const META_SHIFT_END_UTC = '_eum_schedule_end_utc';
  public const META_SEGMENTS = '_eum_schedule_segments';
  public const META_NOTES = '_eum_schedule_notes';
  public const META_UPDATED_BY = '_eum_schedule_updated_by';
  public const META_UPDATED_AT = '_eum_schedule_updated_at';
  public const META_REVISION_PARENT = '_eum_schedule_revision_parent';
  public const META_REVISION_ACTION = '_eum_schedule_revision_action';

  private const USER_META_WEEK_TEMPLATE = '_eum_schedule_week_template';
  private const DEFAULT_TIMEZONE = 'America/New_York';
  private const REGULAR_WEEKLY_HOURS = 40;
  private const HOURS_PER_DAY_RATE_DAY = 12;

  public static function normalize_segment_kind_value(string $kind): string {
    $kind = sanitize_key($kind);
    return in_array($kind, ['work', 'break', 'vacation'], true) ? $kind : 'work';
  }

  public static function segment_kind_label(string $kind): string {
    $kind = self::normalize_segment_kind_value($kind);
    if ($kind === 'break') return 'Break';
    if ($kind === 'vacation') return 'Vacation';
    return 'Work';
  }

  public static function effective_vacation_segments_for_user(int $user_id, array $post_statuses = ['publish'], int $range_start_utc = 0, int $range_end_utc = 0, array $exclude_shift_ids = []): array {
    if ($user_id <= 0) return [];
    $out = [];
    foreach (self::effective_shift_posts_for_user($user_id, $post_statuses, $range_start_utc, $range_end_utc, $exclude_shift_ids) as $shift) {
      $shift_id = (int)$shift->ID;
      $raw_segments = get_post_meta($shift_id, self::META_SEGMENTS, true);
      $raw_segments = is_array($raw_segments) ? $raw_segments : [];
      foreach ($raw_segments as $segment) {
        if (!is_array($segment)) continue;
        if (self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work')) !== 'vacation') continue;
        $start_utc = (int)($segment['start_utc'] ?? 0);
        $end_utc = (int)($segment['end_utc'] ?? 0);
        if ($start_utc <= 0 || $end_utc <= $start_utc) continue;
        if ($range_start_utc > 0) $start_utc = max($range_start_utc, $start_utc);
        if ($range_end_utc > 0) $end_utc = min($range_end_utc, $end_utc);
        if ($end_utc <= $start_utc) continue;
        $out[] = [
          'shift_id' => $shift_id,
          'status' => (string)get_post_status($shift_id),
          'start_utc' => $start_utc,
          'end_utc' => $end_utc,
        ];
      }
    }
    usort($out, static fn($a, $b) => ((int)($a['start_utc'] ?? 0)) <=> ((int)($b['start_utc'] ?? 0)));
    return $out;
  }

  private static function effective_shift_posts_for_user(int $user_id, array $post_statuses = ['publish'], int $range_start_utc = 0, int $range_end_utc = 0, array $exclude_shift_ids = []): array {
    if ($user_id <= 0) return [];
    $statuses = array_values(array_filter(array_map('sanitize_key', $post_statuses), static fn($status) => in_array($status, ['publish', 'draft'], true)));
    if (empty($statuses)) $statuses = ['publish'];
    $exclude_shift_ids = array_values(array_unique(array_filter(array_map('intval', $exclude_shift_ids), static fn($shift_id) => $shift_id > 0)));
    $all = get_posts([
      'post_type' => self::CPT_SHIFT,
      'post_status' => $statuses,
      'numberposts' => -1,
      'orderby' => 'meta_value_num',
      'meta_key' => self::META_SHIFT_START_UTC,
      'order' => 'ASC',
      'meta_query' => [
        ['key' => self::META_USER_ID, 'value' => $user_id],
      ],
    ]);

    $draft_revisions = [];
    foreach ($all as $shift) {
      $shift_id = (int)$shift->ID;
      if (in_array($shift_id, $exclude_shift_ids, true)) continue;
      if ((string)get_post_status($shift_id) !== 'draft') continue;
      $parent_id = (int)get_post_meta($shift_id, self::META_REVISION_PARENT, true);
      if ($parent_id > 0) {
        $draft_revisions[$parent_id] = [
          'shift_id' => $shift_id,
          'action' => (string)get_post_meta($shift_id, self::META_REVISION_ACTION, true),
        ];
      }
    }

    $out = [];
    foreach ($all as $shift) {
      $shift_id = (int)$shift->ID;
      if (in_array($shift_id, $exclude_shift_ids, true)) continue;
      $status = (string)get_post_status($shift_id);
      if ($status === 'publish' && in_array('draft', $statuses, true) && isset($draft_revisions[$shift_id])) continue;
      if ($status === 'draft' && (string)get_post_meta($shift_id, self::META_REVISION_ACTION, true) === 'delete') continue;
      if ($range_start_utc > 0 || $range_end_utc > 0) {
        $start_utc = (int)get_post_meta($shift_id, self::META_SHIFT_START_UTC, true);
        $end_utc = (int)get_post_meta($shift_id, self::META_SHIFT_END_UTC, true);
        if ($range_end_utc > 0 && $start_utc >= $range_end_utc) continue;
        if ($range_start_utc > 0 && $end_utc <= $range_start_utc) continue;
      }
      $out[] = $shift;
    }
    return $out;
  }

  public function init(): void {
    add_action('init', [$this, 'register_cpt']);
    add_action('init', [$this, 'ensure_auto_publish_schedule']);
    add_action('eum_schedule_auto_publish_next_week', [$this, 'handle_auto_publish_next_week']);

    add_action('admin_post_eum_schedule_save_shift', [$this, 'handle_save_shift']);
    add_action('admin_post_eum_schedule_delete_shift', [$this, 'handle_delete_shift']);
    add_action('admin_post_eum_schedule_publish_drafts', [$this, 'handle_publish_drafts']);
    add_action('admin_post_eum_schedule_set_template', [$this, 'handle_set_template']);
    add_action('admin_post_eum_schedule_copy_week', [$this, 'handle_copy_week']);
    add_action('admin_post_eum_schedule_clear_week', [$this, 'handle_clear_week']);

    add_shortcode('eum_schedule', [$this, 'shortcode_schedule']);
  }

  public function register_cpt(): void {
    register_post_type(self::CPT_SHIFT, [
      'labels' => [
        'name' => 'Schedule Shifts',
        'singular_name' => 'Schedule Shift',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public function shortcode_schedule($atts = []): string {
    if (!is_user_logged_in()) return '';

    $actor_user_id = get_current_user_id();
    $can_manage_any = $this->can_manage_any_schedule($actor_user_id);

    $atts = shortcode_atts([
      'default_view' => 'week',
      'default_tab' => $can_manage_any ? 'manage' : 'schedule',
      'show_manage' => '1',
    ], (array)$atts, 'eum_schedule');

    $schedule_filters = [
      'search' => sanitize_text_field((string)($_GET['eum_schedule_filter_q'] ?? '')),
      'path_id' => max(0, (int)($_GET['eum_schedule_filter_path'] ?? 0)),
      'task' => sanitize_text_field((string)($_GET['eum_schedule_filter_task'] ?? '')),
      'group_id' => max(0, (int)($_GET['eum_schedule_filter_group'] ?? 0)),
      'assignment' => sanitize_key((string)($_GET['eum_schedule_filter_assignment'] ?? 'all')),
    ];
    if (!in_array($schedule_filters['assignment'], ['all', 'assigned', 'open'], true)) $schedule_filters['assignment'] = 'all';

    $view = sanitize_key((string)($_GET['eum_schedule_view'] ?? $atts['default_view'] ?? 'week'));
    if (!in_array($view, ['list', 'week', 'calendar'], true)) $view = 'week';

    $tab = sanitize_key((string)($_GET['eum_schedule_tab'] ?? $atts['default_tab'] ?? 'schedule'));
    if (!in_array($tab, ['schedule', 'manage'], true)) $tab = 'schedule';
    if ($tab === 'manage' && (!$can_manage_any || empty($atts['show_manage']))) $tab = 'schedule';

    $selected_user_id = isset($_GET['eum_schedule_user']) ? max(0, (int)$_GET['eum_schedule_user']) : $actor_user_id;
    if ($selected_user_id <= 0) $selected_user_id = $actor_user_id;

    $anchor_date = sanitize_text_field((string)($_GET['eum_schedule_date'] ?? current_time('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor_date)) $anchor_date = current_time('Y-m-d');

    $selected_user_id = $this->normalize_selected_user_for_tab($actor_user_id, $selected_user_id, $tab, $anchor_date, $view);
    $target_user = get_userdata($selected_user_id);
    if (!$target_user) return '<div class="eum-schedule"><p>User not found.</p></div>';

    [$range_start_utc, $range_end_utc, $anchor_utc, $range_label] = $this->determine_view_range($selected_user_id, $view, $anchor_date);
    $status_filter = $tab === 'manage' ? sanitize_key((string)($_GET['eum_schedule_status'] ?? 'all')) : 'publish';
    if (!in_array($status_filter, ['all', 'publish', 'draft'], true)) $status_filter = ($tab === 'manage' ? 'all' : 'publish');

    $shifts = $this->get_visible_shifts_for_user($actor_user_id, $selected_user_id, $range_start_utc, $range_end_utc, $tab, $status_filter);
    $shifts = $this->apply_schedule_filters_to_shifts($shifts, $selected_user_id, $schedule_filters);
    $can_manage_selected_user = $this->can_manage_user_schedule_fully($actor_user_id, $selected_user_id);
    $can_manage_current_user = $tab === 'manage' && ($can_manage_selected_user || $selected_user_id === $actor_user_id || EUM_Access::is_it_or_hr($actor_user_id) || $this->actor_has_user_on_schedule($actor_user_id, $selected_user_id, 0, 0));

    $manager_candidates = $this->schedule_candidate_user_ids($actor_user_id, $range_start_utc, $range_end_utc);
    if (!in_array($selected_user_id, $manager_candidates, true) && ($selected_user_id === $actor_user_id || $can_manage_selected_user)) {
      $manager_candidates[] = $selected_user_id;
    }
    $manager_candidates = array_values(array_unique(array_filter(array_map('intval', $manager_candidates), fn($uid) => $uid > 0)));

    $path_options = $this->job_path_options_for_form();
    $task_map = $this->task_options_map_for_paths(array_keys($path_options));
    $cost_center_options = $this->cost_center_options_for_form();
    $task_filter_options = $this->all_schedule_task_options($task_map);
    $group_filter_options = $this->schedule_group_filter_options();
    $path_tree_items = $this->schedule_path_tree_items(array_keys($path_options));
    $path_tree_roots = $this->schedule_path_tree_roots($path_tree_items);
    $user_defaults = $this->schedule_default_assignment_map(array_merge($manager_candidates, [$selected_user_id, $actor_user_id]));
    $team_summary = $tab === 'manage' ? $this->build_team_summary($actor_user_id, $range_start_utc, $range_end_utc, $status_filter, $schedule_filters) : [];

    $return_args = [
      'eum_schedule_tab' => $tab,
      'eum_schedule_view' => $view,
      'eum_schedule_user' => $selected_user_id,
      'eum_schedule_date' => $anchor_date,
    ];
    if ($tab === 'manage') $return_args['eum_schedule_status'] = $status_filter;
    $return_url = add_query_arg($return_args, $this->current_url());

    ob_start();
    echo '<div class="eum-schedule" data-eum-schedule-wrap="1">';
    echo '<style>' . $this->inline_styles() . '</style>';

    echo '<div class="eum-schedule__topbar">';
    echo '<div><h3 style="margin:0;">Schedule</h3><p style="margin:6px 0 0;color:#5f6b7a;">All times are shown in <strong>' . esc_html($this->user_timezone_label($selected_user_id)) . '</strong>.</p></div>';
    echo '<div class="eum-schedule__tabbar">';
    echo $this->tab_link('Schedule', 'schedule', $tab, ['eum_schedule_view' => $view, 'eum_schedule_user' => $selected_user_id, 'eum_schedule_date' => $anchor_date]);
    if ($can_manage_any && !empty($atts['show_manage'])) {
      echo $this->tab_link('Manage Schedule', 'manage', $tab, ['eum_schedule_view' => $view, 'eum_schedule_user' => $selected_user_id, 'eum_schedule_date' => $anchor_date, 'eum_schedule_status' => $status_filter]);
    }
    echo '</div>';
    echo '</div>';

    echo '<form method="get" class="eum-schedule__controls" data-eum-save-filters="1" data-eum-filter-context="schedule">';
    foreach ($this->preserved_query_args(['eum_schedule_tab', 'eum_schedule_view', 'eum_schedule_user', 'eum_schedule_date', 'eum_schedule_status']) as $key => $value) {
      echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
    }
    echo '<input type="hidden" name="eum_schedule_tab" value="' . esc_attr($tab) . '">';
    echo '<label><span>View</span><select name="eum_schedule_view">';
    foreach (['list' => 'List', 'week' => 'Week', 'calendar' => 'Calendar'] as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($view, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label><span>Focus Date</span><input type="date" name="eum_schedule_date" value="' . esc_attr($anchor_date) . '"></label>';
    echo '<label><span>Search</span><input type="search" name="eum_schedule_filter_q" value="' . esc_attr((string)$schedule_filters['search']) . '" placeholder="User, path, task, or notes"></label>';
    echo '<label><span>Path</span><select name="eum_schedule_filter_path" id="eum-schedule-filter-path"><option value="0">All paths</option>';
    foreach ($path_options as $term_id => $label) {
      echo '<option value="' . (int)$term_id . '" ' . selected((int)$schedule_filters['path_id'], (int)$term_id, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label><span>Task</span><select name="eum_schedule_filter_task" id="eum-schedule-filter-task"><option value="">All tasks</option>';
    foreach ($task_filter_options as $task_label) {
      echo '<option value="' . esc_attr($task_label) . '" ' . selected((string)$schedule_filters['task'], $task_label, false) . '>' . esc_html($task_label) . '</option>';
    }
    echo '</select></label>';
    echo '<label><span>User Group</span><select name="eum_schedule_filter_group" id="eum-schedule-filter-group"><option value="0">All groups</option>';
    foreach ($group_filter_options as $term_id => $label) {
      echo '<option value="' . (int)$term_id . '" ' . selected((int)$schedule_filters['group_id'], (int)$term_id, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    if ($can_manage_any) {
      echo '<label><span>Assignment</span><select name="eum_schedule_filter_assignment"><option value="all"' . selected((string)$schedule_filters['assignment'], 'all', false) . '>Assigned + Open</option><option value="assigned"' . selected((string)$schedule_filters['assignment'], 'assigned', false) . '>Assigned only</option><option value="open"' . selected((string)$schedule_filters['assignment'], 'open', false) . '>Open shifts only</option></select></label>';
    }
    if ($can_manage_any) {
      echo '<label><span>User</span><select name="eum_schedule_user">';
      foreach ($manager_candidates as $user_id) {
        $user = get_userdata($user_id);
        if (!$user) continue;
        $label = class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_list_label($user_id) : (string)$user->display_name;
        echo '<option value="' . (int)$user_id . '" ' . selected($selected_user_id, $user_id, false) . '>' . esc_html($label . ($user_id === $actor_user_id ? ' (You)' : '')) . '</option>';
      }
      echo '</select></label>';
      if ($tab === 'manage') {
        echo '<label><span>Status</span><select name="eum_schedule_status">';
        foreach (['all' => 'Published + Drafts', 'publish' => 'Published Only', 'draft' => 'Drafts Only'] as $value => $label) {
          echo '<option value="' . esc_attr($value) . '" ' . selected($status_filter, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
      }
    }
    echo '<button type="submit" class="button button-primary">Update</button>';
    echo '<a class="button" href="' . esc_url(remove_query_arg(['eum_schedule_filter_q', 'eum_schedule_filter_path', 'eum_schedule_filter_task', 'eum_schedule_filter_group', 'eum_schedule_filter_assignment'])) . '">Reset Filters</a>';
    echo '</form>';

    echo '<div class="eum-schedule__saved-filters">' . $this->render_saved_filter_hint('Saved schedule filters') . '</div>';

    echo '<div class="eum-schedule__rangebar">';
    echo '<div class="eum-schedule__rangeactions">';
    echo '<a class="button" href="' . esc_url($this->range_nav_url($tab, $view, $selected_user_id, $anchor_date, $status_filter, 'prev')) . '">Previous</a>';
    echo '<a class="button" href="' . esc_url($this->range_nav_url($tab, $view, $selected_user_id, $anchor_date, $status_filter, 'today')) . '">Today</a>';
    echo '<a class="button" href="' . esc_url($this->range_nav_url($tab, $view, $selected_user_id, $anchor_date, $status_filter, 'next')) . '">Next</a>';
    echo '</div>';
    echo '<div class="eum-schedule__rangeheadline">' . esc_html($range_label) . '</div>';
    echo '</div>';

    echo '<div class="eum-schedule__summaryrow">';
    $user_summary = $this->build_schedule_summary_for_user($selected_user_id, $shifts, $range_start_utc, $range_end_utc);
    echo $this->summary_card('Scheduled Hours', number_format((float)$user_summary['hours'], 2));
    echo $this->summary_card('Scheduled Labor Cost', $this->money($user_summary['cost']));
    echo $this->summary_card('Published Shifts', (string)(int)$user_summary['published_count']);
    echo $this->summary_card('Draft Shifts', (string)(int)$user_summary['draft_count']);
    echo '</div>';
    echo '<div class="eum-schedule__main">';
    echo '<div class="eum-schedule__viewer">';
    echo $this->render_schedule_view($actor_user_id, $selected_user_id, $shifts, $view, $range_start_utc, $range_end_utc, $tab === 'manage', $status_filter, $schedule_filters);
    echo '</div>';
    echo '</div>';

    if ($tab === 'manage' && $can_manage_any) {
      echo $this->render_manage_panel($actor_user_id, $selected_user_id, $return_url, $path_options, $task_map, $cost_center_options, $shifts, $anchor_date, $can_manage_current_user, $status_filter, $range_start_utc, $range_end_utc, $manager_candidates);
      if (!empty($team_summary)) {
        echo '<div class="eum-schedule__bottom-summary">' . $this->render_team_summary($team_summary) . '</div>';
      }
    }

    echo '<script type="application/json" id="eum-schedule-task-map">' . wp_json_encode($task_map) . '</script>';
    echo '<script type="application/json" id="eum-schedule-path-tree">' . wp_json_encode($path_tree_items) . '</script>';
    echo '<script type="application/json" id="eum-schedule-path-roots">' . wp_json_encode($path_tree_roots) . '</script>';
    echo '<script type="application/json" id="eum-schedule-user-defaults">' . wp_json_encode($user_defaults) . '</script>';
    echo '<script>' . $this->inline_script() . '</script>';
    echo '</div>';
    return ob_get_clean();
  }

  public function handle_save_shift(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');

    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? max(0, (int)$_POST['target_user_id']) : 0;
    $shift_id = isset($_POST['shift_id']) ? max(0, (int)$_POST['shift_id']) : 0;
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));

    if (!isset($_POST['eum_schedule_save_nonce']) || !wp_verify_nonce((string)($_POST['eum_schedule_save_nonce'] ?? ''), 'eum_schedule_save')) {
      wp_die('Security check failed.');
    }

    if ($target_user_id < 0) wp_die('User is required.');

    $original_status = '';
    if ($shift_id > 0) {
      $shift = get_post($shift_id);
      if (!$shift || $shift->post_type !== self::CPT_SHIFT) wp_die('Shift not found.');
      if (!$this->can_edit_shift($actor_user_id, $shift_id)) wp_die('You cannot edit this shift.');
      $original_status = (string)get_post_status($shift_id);
      if (!isset($_POST['target_user_id']) || (int)$_POST['target_user_id'] < 0) {
        $target_user_id = (int)get_post_meta($shift_id, self::META_USER_ID, true);
      }
    }

    $desired_status = sanitize_key((string)($_POST['eum_schedule_save_status'] ?? 'draft'));
    if (!in_array($desired_status, ['draft', 'publish'], true)) $desired_status = 'draft';

    $notes = sanitize_textarea_field((string)($_POST['schedule_notes'] ?? ''));
    $segments = $this->segments_from_request($_POST, $target_user_id);
    if (is_wp_error($segments)) {
      wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode($segments->get_error_message()), 'eum_schedule_type' => 'error'], $return_url));
      exit;
    }

    if ($shift_id <= 0 && !$this->can_create_shift_for_user($actor_user_id, $target_user_id, $segments)) wp_die('You cannot create shifts for this user.');

    $status_to_save = $desired_status;
    if ($shift_id <= 0) {
      $status_to_save = 'draft';
    } elseif ($original_status === 'publish' && $desired_status !== 'publish') {
      $shift_id = $this->get_or_create_revision_draft($shift_id, $actor_user_id);
      if ($shift_id <= 0) {
        wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode('Could not create a draft revision for that shift.'), 'eum_schedule_type' => 'error'], $return_url));
        exit;
      }
      $status_to_save = 'draft';
    }

    if (class_exists('EUM_Workforce_Lifecycle') && method_exists('EUM_Workforce_Lifecycle', 'validate_scheduled_vacation_segments')) {
      $vacation_validation = EUM_Workforce_Lifecycle::validate_scheduled_vacation_segments($target_user_id, $segments, $shift_id);
      if (is_wp_error($vacation_validation)) {
        wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode($vacation_validation->get_error_message()), 'eum_schedule_type' => 'error'], $return_url));
        exit;
      }
    }

    $result = $this->upsert_shift($shift_id, $target_user_id, $segments, $notes, $status_to_save, $actor_user_id);
    if (is_wp_error($result)) {
      wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode($result->get_error_message()), 'eum_schedule_type' => 'error'], $return_url));
      exit;
    }

    $message = ($desired_status === 'publish' && $shift_id > 0 && $original_status === 'publish')
      ? 'Shift updated and published.'
      : 'Shift saved as draft.';

    wp_safe_redirect(add_query_arg([
      'eum_schedule_user' => $target_user_id,
      'eum_schedule_tab' => 'manage',
      'eum_schedule_msg' => rawurlencode($message),
      'eum_schedule_type' => 'success',
    ], $return_url));
    exit;
  }

  public function handle_delete_shift(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $shift_id = isset($_POST['shift_id']) ? max(0, (int)$_POST['shift_id']) : 0;
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    if ($shift_id <= 0) wp_die('Shift not found.');
    if (!isset($_POST['eum_schedule_delete_nonce']) || !wp_verify_nonce((string)($_POST['eum_schedule_delete_nonce'] ?? ''), 'eum_schedule_delete_' . $shift_id)) wp_die('Security check failed.');
    if (!$this->can_edit_shift($actor_user_id, $shift_id)) wp_die('You cannot delete this shift.');
    $this->delete_shift_and_related_drafts($shift_id);
    wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode('Shift deleted.'), 'eum_schedule_type' => 'success'], $return_url));
    exit;
  }

  public function handle_publish_drafts(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
    $range_start_utc = isset($_POST['range_start_utc']) ? max(0, (int)$_POST['range_start_utc']) : 0;
    $range_end_utc = isset($_POST['range_end_utc']) ? max(0, (int)$_POST['range_end_utc']) : 0;
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    if (!isset($_POST['eum_schedule_publish_nonce']) || !wp_verify_nonce((string)($_POST['eum_schedule_publish_nonce'] ?? ''), 'eum_schedule_publish')) wp_die('Security check failed.');
    if (!$this->can_manage_any_schedule($actor_user_id)) wp_die('Not allowed.');

    $published = $this->publish_draft_shifts_for_actor($actor_user_id, $target_user_id, $range_start_utc, $range_end_utc);
    if ($published > 0) {
      do_action('eum_schedule_drafts_published', [
        'actor_user_id' => $actor_user_id,
        'target_user_id' => $target_user_id,
        'range_start_utc' => $range_start_utc,
        'range_end_utc' => $range_end_utc,
        'published_count' => $published,
        'user_ids' => $target_user_id > 0 ? [$target_user_id] : $this->published_user_ids_in_range($range_start_utc, $range_end_utc),
        'source' => 'manual',
      ]);
    }
    wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode('Published ' . $published . ' draft shift(s).'), 'eum_schedule_type' => 'success'], $return_url));
    exit;
  }

  public function handle_set_template(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? max(0, (int)$_POST['target_user_id']) : 0;
    $range_start_utc = isset($_POST['range_start_utc']) ? max(0, (int)$_POST['range_start_utc']) : 0;
    $range_end_utc = isset($_POST['range_end_utc']) ? max(0, (int)$_POST['range_end_utc']) : 0;
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    if (!isset($_POST['eum_schedule_template_nonce']) || !wp_verify_nonce((string)($_POST['eum_schedule_template_nonce'] ?? ''), 'eum_schedule_template_' . $target_user_id . '_' . $range_start_utc)) wp_die('Security check failed.');
    if ($target_user_id <= 0 || !$this->can_manage_user_week_actions($actor_user_id, $target_user_id, $range_start_utc, $range_end_utc)) wp_die('Not allowed.');

    $shifts = $this->get_visible_shifts_for_user($actor_user_id, $target_user_id, $range_start_utc, $range_end_utc, 'manage', 'all');
    $template = $this->build_week_template_from_shifts($target_user_id, $shifts, $range_start_utc, $range_end_utc);
    if (empty($template['shifts'])) {
      wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode('No shifts were found in that week to save as a template.'), 'eum_schedule_type' => 'error'], $return_url));
      exit;
    }

    update_user_meta($target_user_id, self::USER_META_WEEK_TEMPLATE, $template);
    wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode('Schedule template saved for ' . $this->user_display_name($target_user_id) . '.'), 'eum_schedule_type' => 'success'], $return_url));
    exit;
  }

  public function handle_copy_week(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? max(0, (int)$_POST['target_user_id']) : 0;
    $range_start_utc = isset($_POST['range_start_utc']) ? max(0, (int)$_POST['range_start_utc']) : 0;
    $range_end_utc = isset($_POST['range_end_utc']) ? max(0, (int)$_POST['range_end_utc']) : 0;
    $source_week_date = sanitize_text_field((string)($_POST['source_week_date'] ?? ''));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    if (!isset($_POST['eum_schedule_copy_week_nonce']) || !wp_verify_nonce((string)($_POST['eum_schedule_copy_week_nonce'] ?? ''), 'eum_schedule_copy_week_' . $target_user_id . '_' . $range_start_utc)) wp_die('Security check failed.');
    if ($target_user_id <= 0 || !$this->can_manage_user_week_actions($actor_user_id, $target_user_id, $range_start_utc, $range_end_utc)) wp_die('Not allowed.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $source_week_date)) {
      wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode('Select a source week date first.'), 'eum_schedule_type' => 'error'], $return_url));
      exit;
    }

    [$source_start_utc, $source_end_utc] = $this->week_range_for_user_from_date($target_user_id, $source_week_date);
    $source_shifts = $this->get_visible_shifts_for_user($actor_user_id, $target_user_id, $source_start_utc, $source_end_utc, 'manage', 'all');
    $template = $this->build_week_template_from_shifts($target_user_id, $source_shifts, $source_start_utc, $source_end_utc);
    if (empty($template['shifts'])) {
      wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode('No shifts were found in the selected source week.'), 'eum_schedule_type' => 'error'], $return_url));
      exit;
    }

    $cleared = $this->clear_user_week_to_drafts($actor_user_id, $target_user_id, $range_start_utc, $range_end_utc);
    $created = $this->apply_week_template_to_user($target_user_id, $template, $range_start_utc, $actor_user_id);
    $message = 'Copied ' . $created . ' shift(s) into ' . $this->user_display_name($target_user_id) . '\'s viewed week as drafts.';
    if ($cleared['delete_markers'] > 0 || $cleared['drafts_deleted'] > 0) {
      $message .= ' Existing draft changes for that week were replaced.';
    }
    wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode($message), 'eum_schedule_type' => 'success'], $return_url));
    exit;
  }

  public function handle_clear_week(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? max(0, (int)$_POST['target_user_id']) : 0;
    $range_start_utc = isset($_POST['range_start_utc']) ? max(0, (int)$_POST['range_start_utc']) : 0;
    $range_end_utc = isset($_POST['range_end_utc']) ? max(0, (int)$_POST['range_end_utc']) : 0;
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    if (!isset($_POST['eum_schedule_clear_week_nonce']) || !wp_verify_nonce((string)($_POST['eum_schedule_clear_week_nonce'] ?? ''), 'eum_schedule_clear_week_' . $target_user_id . '_' . $range_start_utc)) wp_die('Security check failed.');
    if ($target_user_id <= 0 || !$this->can_manage_user_week_actions($actor_user_id, $target_user_id, $range_start_utc, $range_end_utc)) wp_die('Not allowed.');

    $result = $this->clear_user_week_to_drafts($actor_user_id, $target_user_id, $range_start_utc, $range_end_utc);
    $message = 'Cleared the viewed week for ' . $this->user_display_name($target_user_id) . '.';
    if ($result['delete_markers'] > 0) $message .= ' Published shifts are now pending deletion until you publish drafts.';
    wp_safe_redirect(add_query_arg(['eum_schedule_msg' => rawurlencode($message), 'eum_schedule_type' => 'success'], $return_url));
    exit;
  }

  private function normalize_selected_user_for_tab(int $actor_user_id, int $selected_user_id, string $tab, string $anchor_date, string $view): int {
    if ($selected_user_id <= 0) return $actor_user_id;
    if ($selected_user_id > 0 && !$this->is_schedulable_user($selected_user_id)) return $actor_user_id;
    if ($selected_user_id === $actor_user_id) return $selected_user_id;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return $selected_user_id;
    if ($tab === 'schedule' && $this->can_manage_user_schedule_fully($actor_user_id, $selected_user_id)) return $selected_user_id;
    if ($tab === 'manage') {
      [$range_start_utc, $range_end_utc] = $this->determine_view_range($selected_user_id, $view, $anchor_date);
      if ($this->can_manage_user_schedule_fully($actor_user_id, $selected_user_id)) return $selected_user_id;
      if ($this->actor_has_user_on_schedule($actor_user_id, $selected_user_id, $range_start_utc, $range_end_utc)) return $selected_user_id;
    }
    return $actor_user_id;
  }

  private function determine_view_range(int $user_id, string $view, string $anchor_date): array {
    $tz = $this->user_timezone($user_id);
    try {
      $anchor = new DateTime($anchor_date . ' 12:00:00', $tz);
    } catch (Exception $e) {
      $anchor = new DateTime('now', $tz);
    }

    if ($view === 'list') {
      $start = clone $anchor;
      $start->modify('Sunday this week')->setTime(0, 0, 0);
      $end = clone $start;
      $end->modify('+14 days');
      return [$start->getTimestamp(), $end->getTimestamp(), $anchor->getTimestamp(), $start->format('M j') . ' – ' . $end->modify('-1 second')->format('M j, Y')];
    }

    if ($view === 'calendar') {
      $start = new DateTime($anchor->format('Y-m-01 00:00:00'), $tz);
      $grid_start = clone $start;
      $day_of_week = (int)$grid_start->format('w');
      if ($day_of_week > 0) $grid_start->modify('-' . $day_of_week . ' days');
      $end = clone $grid_start;
      $end->modify('+42 days');
      return [$grid_start->getTimestamp(), $end->getTimestamp(), $anchor->getTimestamp(), $anchor->format('F Y')];
    }

    $start = clone $anchor;
    $start->modify('Sunday this week')->setTime(0, 0, 0);
    $end = clone $start;
    $end->modify('+7 days');
    return [$start->getTimestamp(), $end->getTimestamp(), $anchor->getTimestamp(), $start->format('M j') . ' – ' . $end->modify('-1 second')->format('M j, Y')];
  }

  private function range_nav_url(string $tab, string $view, int $selected_user_id, string $anchor_date, string $status_filter, string $direction): string {
    $tz = $this->user_timezone($selected_user_id);
    $anchor = new DateTime($anchor_date . ' 12:00:00', $tz);
    if ($direction === 'today') {
      $anchor = new DateTime('now', $tz);
    } else {
      $modify = '+7 days';
      if ($view === 'calendar') $modify = '+1 month';
      elseif ($view === 'list') $modify = '+14 days';
      if ($direction === 'prev') $modify = str_replace('+', '-', $modify);
      $anchor->modify($modify);
    }

    $args = [
      'eum_schedule_tab' => $tab,
      'eum_schedule_view' => $view,
      'eum_schedule_user' => $selected_user_id,
      'eum_schedule_date' => $anchor->format('Y-m-d'),
    ];
    if ($tab === 'manage') $args['eum_schedule_status'] = $status_filter;
    foreach (['eum_schedule_filter_q', 'eum_schedule_filter_path', 'eum_schedule_filter_task', 'eum_schedule_filter_group', 'eum_schedule_filter_assignment'] as $key) {
      if (isset($_GET[$key]) && (string)$_GET[$key] !== '') $args[$key] = sanitize_text_field((string)$_GET[$key]);
    }
    return add_query_arg($args, $this->current_url());
  }

  private function render_schedule_view(int $actor_user_id, int $target_user_id, array $shifts, string $view, int $range_start_utc, int $range_end_utc, bool $manage_mode, string $status_filter = 'publish', array $filters = []): string {
    if ($manage_mode && $view === 'week' && $this->can_manage_any_schedule($actor_user_id)) {
      $candidate_user_ids = $this->schedule_candidate_user_ids($actor_user_id, $range_start_utc, $range_end_utc);
      if (!in_array($target_user_id, $candidate_user_ids, true) && ($this->can_create_shift_for_user($actor_user_id, $target_user_id) || $this->can_manage_user_schedule_fully($actor_user_id, $target_user_id))) {
        $candidate_user_ids[] = $target_user_id;
      }
      return $this->render_manage_week_planner($actor_user_id, $candidate_user_ids, $range_start_utc, $range_end_utc, $status_filter, $target_user_id, $filters);
    }
    if ($view === 'list') return $this->render_list_view($actor_user_id, $target_user_id, $shifts, $range_start_utc, $range_end_utc, $manage_mode);
    if ($view === 'calendar') return $this->render_calendar_view($actor_user_id, $target_user_id, $shifts, $range_start_utc, $range_end_utc, $manage_mode);
    return $this->render_week_view($actor_user_id, $target_user_id, $shifts, $range_start_utc, $range_end_utc, $manage_mode);
  }

  private function render_week_view(int $actor_user_id, int $target_user_id, array $shifts, int $range_start_utc, int $range_end_utc, bool $manage_mode): string {
    $tz = $this->user_timezone($target_user_id);
    $days = [];
    $cursor = $range_start_utc;
    while ($cursor < $range_end_utc) {
      $days[] = $cursor;
      $cursor += DAY_IN_SECONDS;
    }

    ob_start();
    echo '<div class="eum-schedule-grid eum-schedule-grid--week">';
    foreach ($days as $day_start) {
      $day_end = $day_start + DAY_IN_SECONDS;
      $dt = new DateTime('@' . $day_start);
      $dt->setTimezone($tz);
      echo '<div class="eum-schedule-day">';
      echo '<div class="eum-schedule-day__head eum-schedule-paste-target" data-target-user="' . (int)$target_user_id . '" data-target-date="' . esc_attr($dt->format('Y-m-d')) . '"><strong>' . esc_html($dt->format('D')) . '</strong><span>' . esc_html($dt->format('M j')) . '</span></div>';
      if (class_exists('EUM_Workforce_Lifecycle')) echo EUM_Workforce_Lifecycle::schedule_absence_chip_html($target_user_id, $dt->format('Y-m-d'));
      $count = 0;
      foreach ($shifts as $shift) {
        if (!$this->shift_overlaps_range((int)$shift->ID, $day_start, $day_end)) continue;
        echo $this->render_shift_card($actor_user_id, $target_user_id, $shift, $manage_mode);
        $count++;
      }
      if ($count === 0) echo '<div class="eum-schedule-empty">No shifts</div>';
      echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_manage_week_planner(int $actor_user_id, array $candidate_user_ids, int $range_start_utc, int $range_end_utc, string $status_filter, int $selected_user_id, array $filters = []): string {
    $candidate_user_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_user_ids), fn($uid) => $uid > 0)));
    usort($candidate_user_ids, static function($a, $b) {
      $ua = get_userdata($a);
      $ub = get_userdata($b);
      return strcasecmp($ua ? $ua->display_name : '', $ub ? $ub->display_name : '');
    });

    array_unshift($candidate_user_ids, 0);

    $header_tz = $this->user_timezone($selected_user_id > 0 ? $selected_user_id : $actor_user_id);
    $days = [];
    $cursor = $range_start_utc;
    while ($cursor < $range_end_utc) {
      $days[] = $cursor;
      $cursor += DAY_IN_SECONDS;
    }

    ob_start();
    echo '<div class="eum-scheduler-board">';
    echo '<div class="eum-scheduler-board__top">';
    echo '<div><h4 style="margin:0;">Schedule Planner</h4><p class="description" style="margin:6px 0 0;">Click any user/day cell to open a popup editor. Click an existing bar to edit it. Right-click any bar to copy, paste a draft copy, or delete it. Open shifts are supported in the top row.</p></div>';
    echo '<div class="eum-scheduler-board__legend"><span class="eum-scheduler-legend eum-scheduler-legend--publish">Published</span><span class="eum-scheduler-legend eum-scheduler-legend--draft">Draft</span><span class="eum-scheduler-legend eum-scheduler-legend--open">Open Shift</span></div>';
    echo '</div>';
    echo '<div class="eum-scheduler-board__scroll">';
    echo '<table class="eum-scheduler-table">';
    echo '<thead><tr><th>Employee</th>';
    foreach ($days as $day_start) {
      $dt = new DateTime('@' . $day_start);
      $dt->setTimezone($header_tz);
      echo '<th><span class="eum-scheduler-table__dow">' . esc_html($dt->format('D')) . '</span><span class="eum-scheduler-table__date">' . esc_html($dt->format('n/j')) . '</span></th>';
    }
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($candidate_user_ids as $user_id) {
      $is_open_row = $user_id === 0;
      $user = $is_open_row ? null : get_userdata($user_id);
      if (!$is_open_row && (!$user || !$this->is_schedulable_user($user_id))) continue;
      $row_shifts = $this->get_visible_shifts_for_user($actor_user_id, $user_id, $range_start_utc, $range_end_utc, 'manage', $status_filter);
      $row_shifts = $this->apply_schedule_filters_to_shifts($row_shifts, $user_id, $filters);
      $user_matches = $this->user_matches_schedule_filters($user_id, $filters);
      if (!$is_open_row && $user_id !== $selected_user_id && !$user_matches && empty($row_shifts)) continue;
      if ($is_open_row && (($filters['assignment'] ?? 'all') === 'assigned') && empty($row_shifts)) continue;
      if ($is_open_row && !empty($filters['search']) && empty($row_shifts)) continue;
      $row_summary = $this->build_schedule_summary_for_user($user_id, $row_shifts, $range_start_utc, $range_end_utc);
      echo '<tr' . (!$is_open_row && $user_id === $selected_user_id ? ' class="is-selected"' : ($is_open_row ? ' class="is-open-row"' : '')) . ' data-schedule-user-row="' . (int)$user_id . '">';
      if ($is_open_row) {
        echo '<th class="eum-scheduler-user eum-scheduler-user--open"><div class="eum-scheduler-user__name">Open Shifts</div><div class="eum-scheduler-user__meta">' . esc_html(number_format((float)$row_summary['hours'], 2)) . 'h · ' . esc_html($this->money((float)$row_summary['cost'])) . '</div></th>';
      } else {
        $user_label = class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_list_label($user_id) : (string)$user->display_name;
        $avatar = class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html($user_id, 34, 'eum-scheduler-user__avatar') : '';
        echo '<th class="eum-scheduler-user"><div class="eum-scheduler-user__name">' . $avatar . '<span>' . esc_html($user_label) . '</span>' . ($user_id === $actor_user_id ? ' <span class="eum-scheduler-user__you">You</span>' : '') . '</div><div class="eum-scheduler-user__meta">' . esc_html(number_format((float)$row_summary['hours'], 2)) . 'h · ' . esc_html($this->money((float)$row_summary['cost'])) . '</div>' . $this->render_user_week_actions($actor_user_id, $user_id, $range_start_utc, $range_end_utc) . '</th>';
      }

      foreach ($days as $day_start) {
        $day_end = $day_start + DAY_IN_SECONDS;
        $dt = new DateTime('@' . $day_start);
        $dt->setTimezone($this->user_timezone($user_id > 0 ? $user_id : $actor_user_id));
        $day_date = $dt->format('Y-m-d');
        echo '<td class="eum-scheduler-cell eum-schedule-paste-target' . ($is_open_row ? ' is-open-cell' : '') . '" data-add-shift-cell="1" data-target-user="' . (int)$user_id . '" data-target-date="' . esc_attr($day_date) . '">';
        if (!$is_open_row && class_exists('EUM_Workforce_Lifecycle')) echo EUM_Workforce_Lifecycle::schedule_absence_chip_html($user_id, $day_date);
        $count = 0;
        foreach ($row_shifts as $shift) {
          if (!$this->shift_overlaps_range((int)$shift->ID, $day_start, $day_end)) continue;
          echo $this->render_planner_shift_chip($actor_user_id, $user_id, $shift);
          $count++;
        }
        if ($count === 0) {
          echo '<div class="eum-scheduler-cell__empty">' . ($is_open_row ? 'Add open shift' : 'Add shift') . '</div>';
        }
        echo '</td>';
      }
      echo '</tr>';
    }

    if (count($candidate_user_ids) === 1 && $candidate_user_ids[0] === 0) {
      echo '<tr><td colspan="8"><div class="eum-schedule-empty">No employees are available in this schedule view yet.</div></td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    return ob_get_clean();
  }

  private function render_list_view(int $actor_user_id, int $target_user_id, array $shifts, int $range_start_utc, int $range_end_utc, bool $manage_mode): string {
    $tz = $this->user_timezone($target_user_id);
    ob_start();
    echo '<div class="eum-schedule-list">';
    $cursor = $range_start_utc;
    while ($cursor < $range_end_utc) {
      $day_end = $cursor + DAY_IN_SECONDS;
      $dt = new DateTime('@' . $cursor);
      $dt->setTimezone($tz);
      echo '<section class="eum-schedule-list__day">';
      echo '<header class="eum-schedule-list__header eum-schedule-paste-target" data-target-user="' . (int)$target_user_id . '" data-target-date="' . esc_attr($dt->format('Y-m-d')) . '"><h4>' . esc_html($dt->format('l, M j')) . '</h4><span>Right-click here to paste</span></header>';
      if (class_exists('EUM_Workforce_Lifecycle')) echo EUM_Workforce_Lifecycle::schedule_absence_chip_html($target_user_id, $dt->format('Y-m-d'));
      $count = 0;
      foreach ($shifts as $shift) {
        if (!$this->shift_overlaps_range((int)$shift->ID, $cursor, $day_end)) continue;
        echo $this->render_shift_card($actor_user_id, $target_user_id, $shift, $manage_mode);
        $count++;
      }
      if ($count === 0) echo '<div class="eum-schedule-empty">No shifts</div>';
      echo '</section>';
      $cursor = $day_end;
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_calendar_view(int $actor_user_id, int $target_user_id, array $shifts, int $range_start_utc, int $range_end_utc, bool $manage_mode): string {
    $tz = $this->user_timezone($target_user_id);
    $grid_month = (new DateTime('@' . $range_start_utc))->setTimezone($tz)->format('n');
    ob_start();
    echo '<div class="eum-schedule-grid eum-schedule-grid--calendar">';
    $cursor = $range_start_utc;
    while ($cursor < $range_end_utc) {
      $day_end = $cursor + DAY_IN_SECONDS;
      $dt = new DateTime('@' . $cursor);
      $dt->setTimezone($tz);
      $outside = $dt->format('n') !== $grid_month;
      echo '<div class="eum-schedule-day eum-schedule-day--calendar' . ($outside ? ' is-outside' : '') . '">';
      echo '<div class="eum-schedule-day__head eum-schedule-paste-target" data-target-user="' . (int)$target_user_id . '" data-target-date="' . esc_attr($dt->format('Y-m-d')) . '"><strong>' . esc_html($dt->format('M j')) . '</strong></div>';
      if (class_exists('EUM_Workforce_Lifecycle')) echo EUM_Workforce_Lifecycle::schedule_absence_chip_html($target_user_id, $dt->format('Y-m-d'));
      $count = 0;
      foreach ($shifts as $shift) {
        if (!$this->shift_overlaps_range((int)$shift->ID, $cursor, $day_end)) continue;
        echo $this->render_shift_card($actor_user_id, $target_user_id, $shift, $manage_mode, true);
        $count++;
      }
      if ($count === 0) echo '<div class="eum-schedule-empty">No shifts</div>';
      echo '</div>';
      $cursor = $day_end;
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_shift_card(int $actor_user_id, int $target_user_id, WP_Post $shift, bool $manage_mode, bool $compact = false): string {
    $shift_id = (int)$shift->ID;
    $payload = $this->shift_payload_for_js($shift_id);
    $status = get_post_status($shift_id);
    $summary = $this->shift_summary($shift_id, $target_user_id, !$compact);
    $can_edit = $manage_mode && $this->can_edit_shift($actor_user_id, $shift_id);

    ob_start();
    echo '<article class="eum-schedule-shift" data-shift-id="' . $shift_id . '" data-shift="' . esc_attr(wp_json_encode($payload)) . '">';
    echo '<div class="eum-schedule-shift__head">';
    echo '<div><strong>' . esc_html($summary['time']) . '</strong><span class="eum-schedule-badge eum-schedule-badge--' . esc_attr($status) . '">' . esc_html($status === 'publish' ? 'Published' : 'Draft') . '</span></div>';
    echo '<div class="eum-schedule-shift__cost">' . esc_html($this->money((float)$summary['cost'])) . '</div>';
    echo '</div>';
    echo '<div class="eum-schedule-shift__segments">';
    foreach ($summary['segments'] as $segment_label) {
      echo '<div class="eum-schedule-shift__segment">' . esc_html($segment_label) . '</div>';
    }
    echo '</div>';
    if (!empty($summary['notes'])) echo '<div class="eum-schedule-shift__notes">' . esc_html($summary['notes']) . '</div>';
    if (class_exists('EUM_Workforce_Lifecycle')) {
      $availability_warning = EUM_Workforce_Lifecycle::availability_warning_for_shift($shift_id, $target_user_id);
      if ($availability_warning !== '') echo '<div class="eum-schedule-availability-warning" style="margin-top:8px;padding:6px 8px;border-radius:8px;background:#fff1f2;color:#9f1239;border:1px solid #fecdd3;font-size:11px;font-weight:700;">' . esc_html($availability_warning) . '</div>';
    }
    if ($can_edit) {
      echo '<div class="eum-schedule-shift__actions"><button type="button" class="button button-small eum-schedule-edit-shift" data-shift-edit="' . $shift_id . '">Edit</button><span class="description">Right-click to copy</span></div>';
    }
    echo '</article>';
    return ob_get_clean();
  }

  private function render_planner_shift_chip(int $actor_user_id, int $target_user_id, WP_Post $shift): string {
    $shift_id = (int)$shift->ID;
    $payload = $this->shift_payload_for_js($shift_id);
    $status = get_post_status($shift_id);
    $summary = $this->shift_summary($shift_id, $target_user_id, false);
    $segments = $this->normalized_segments_from_meta($shift_id);
    $primary_label = '';
    foreach ($segments as $segment) {
      $kind = self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work'));
      if ($kind === 'vacation') {
        $primary_label = 'Vacation';
        break;
      }
      if ($kind !== 'work') continue;
      $primary_label = trim($this->condensed_job_path_label((int)($segment['job_path_term_id'] ?? 0)) . ' · ' . (string)($segment['task_label'] ?? ''));
      if ($primary_label !== '' && $primary_label !== '·') break;
    }
    if ($primary_label === '') {
      $primary_label = !empty($segments)
        ? self::segment_kind_label((string)($segments[0]['kind'] ?? 'work'))
        : 'Shift';
    }

    ob_start();
    echo '<button type="button" class="eum-schedule-shift eum-schedule-planner-shift ' . ($status === 'draft' ? 'is-draft' : 'is-published') . '" data-shift-id="' . $shift_id . '" data-shift="' . esc_attr(wp_json_encode($payload)) . '">';
    echo '<span class="eum-schedule-planner-shift__time">' . esc_html($summary['time']) . '</span>';
    echo '<span class="eum-schedule-planner-shift__task">' . esc_html($primary_label) . '</span>';
    if (!empty($summary['notes'])) {
      echo '<span class="eum-schedule-planner-shift__notes">' . esc_html(wp_trim_words($summary['notes'], 8, '…')) . '</span>';
    }
    if (class_exists('EUM_Workforce_Lifecycle')) {
      $availability_warning = EUM_Workforce_Lifecycle::availability_warning_for_shift($shift_id, $target_user_id);
      if ($availability_warning !== '') echo '<span class="eum-schedule-planner-shift__warning" style="display:block;margin-top:4px;color:#ffe4e6;font-size:11px;font-weight:800;">Outside availability</span>';
    }
    echo '</button>';
    return ob_get_clean();
  }

  private function render_manage_panel(int $actor_user_id, int $target_user_id, string $return_url, array $path_options, array $task_map, array $cost_center_options, array $shifts, string $anchor_date, bool $can_manage_current_user, string $status_filter, int $range_start_utc, int $range_end_utc, array $manager_candidates = []): string {
    $message = sanitize_text_field((string)($_GET['eum_schedule_msg'] ?? ''));
    $message_type = sanitize_key((string)($_GET['eum_schedule_type'] ?? 'success'));
    $tz = $this->user_timezone($target_user_id > 0 ? $target_user_id : $actor_user_id);
    $anchor = new DateTime($anchor_date . ' 09:00:00', $tz);
    $default_start = $anchor->format('Y-m-d\TH:i');
    $default_end = $anchor->modify('+8 hours')->format('Y-m-d\TH:i');

    $default_task_options = !empty($path_options) ? reset($task_map) : [];
    $manager_candidates = array_values(array_unique(array_filter(array_map('intval', $manager_candidates), fn($uid) => $uid > 0 && $this->is_schedulable_user((int)$uid))));
    if ($target_user_id > 0 && $this->is_schedulable_user($target_user_id) && !in_array($target_user_id, $manager_candidates, true)) $manager_candidates[] = $target_user_id;
    usort($manager_candidates, static function($a, $b) {
      $ua = get_userdata($a);
      $ub = get_userdata($b);
      return strcasecmp($ua ? $ua->display_name : '', $ub ? $ub->display_name : '');
    });

    ob_start();
    if ($message !== '') {
      echo '<div class="notice ' . ($message_type === 'error' ? 'notice-error' : 'notice-success') . '"><p>' . esc_html(rawurldecode($message)) . '</p></div>';
    }

    echo '<div class="eum-schedule-panel eum-schedule-panel--publish">';
    echo '<div class="eum-schedule-panel__actions" style="justify-content:space-between;align-items:center;">';
    echo '<div><h4 style="margin:0 0 6px;">Draft Publishing</h4><p class="description" style="margin:0;">New shifts are saved as drafts. The Discord bot applies next week&#8217;s templates on Monday, posts next week&#8217;s schedule on Thursday, and catches up current and next week templates if a run was missed.</p></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-schedule-panel__actions">';
    echo '<input type="hidden" name="action" value="eum_schedule_publish_drafts">';
    echo '<input type="hidden" name="target_user_id" value="0">';
    echo '<input type="hidden" name="range_start_utc" value="' . (int)$range_start_utc . '">';
    echo '<input type="hidden" name="range_end_utc" value="' . (int)$range_end_utc . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_schedule_publish', 'eum_schedule_publish_nonce');
    echo '<button type="submit" class="button button-primary">Publish Draft Changes</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '<div class="eum-schedule-modal" id="eum-schedule-modal" hidden>';
    echo '<div class="eum-schedule-modal__backdrop" data-schedule-modal-close="1"></div>';
    echo '<div class="eum-schedule-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="eum-schedule-modal-title">';
    echo '<div class="eum-schedule-modal__head"><div><h4 id="eum-schedule-modal-title" style="margin:0;">Shift Editor</h4><p class="description" style="margin:6px 0 0;">Create or update draft shifts from this popup. Use the Assigned User field to keep a shift open or assign it to someone. Right-click in the planner to copy, paste, or delete shifts. New shifts automatically start on the scheduled user’s current Job Path.</p></div><button type="button" class="button-link" data-schedule-modal-close="1" aria-label="Close">Close</button></div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-schedule-editor" id="eum-schedule-editor">';
    echo '<input type="hidden" name="action" value="eum_schedule_save_shift">';
    echo '<input type="hidden" name="shift_id" id="eum-schedule-shift-id" value="0">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_schedule_save', 'eum_schedule_save_nonce');

    echo '<div class="eum-schedule-editor__grid eum-schedule-editor__grid--popup">';
    echo '<label><span>Assigned User</span><select name="target_user_id" id="eum-schedule-target-user">';
    echo '<option value="0">Open Shift</option>';
    foreach ($manager_candidates as $user_id) {
      $user = $user_id > 0 ? get_userdata($user_id) : null;
      if ($user_id > 0 && !$user) continue;
      $label = class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_list_label($user_id) : (string)$user->display_name;
      echo '<option value="' . (int)$user_id . '" ' . selected($target_user_id, $user_id, false) . '>' . esc_html($label . ($user_id === $actor_user_id ? ' (You)' : '')) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="eum-schedule-editor__notes"><span>Notes</span><textarea name="schedule_notes" id="eum-schedule-notes" rows="3" placeholder="Optional notes"></textarea></label>';
    echo '</div>';
    echo '<div id="eum-schedule-availability-warning" class="eum-schedule-availability-warning" style="display:none;margin:0 0 4px;padding:10px 12px;border-radius:10px;background:#fff1f2;color:#9f1239;border:1px solid #fecdd3;font-size:12px;font-weight:700;"></div>';

    echo '<div class="eum-schedule-editor__clipboard">';
    echo '<div class="description">Copy, paste, and delete are also available from the right-click menu in the planner.</div>';
    echo '<button type="button" class="button-link-delete" id="eum-schedule-delete-button" style="display:none;">Delete Shift</button>';
    echo '</div>';

    echo '<div class="eum-schedule-editor__segments">';
    echo '<div class="eum-schedule-editor__segments-head"><strong>Segments</strong><span class="description">Use Work segments for path/task assignments and Break segments for unpaid gaps. Cost Centers keep the job task for tracking, but bill the hours to the selected cost center.</span></div>';
    echo '<div id="eum-schedule-segments">';
    echo $this->render_segment_row(0, [
      'kind' => 'work',
      'start_local' => $default_start,
      'end_local' => $default_end,
      'job_path_term_id' => !empty($path_options) ? (int)array_key_first($path_options) : 0,
      'task_label' => !empty($default_task_options) ? (string)$default_task_options[0] : '',
      'cost_center_term_id' => 0,
    ], $path_options, $task_map, $cost_center_options);
    echo '</div>';
    echo '<div class="eum-schedule-editor__buttons"><button type="button" class="button" data-add-segment="work">Add Work Segment</button><button type="button" class="button" data-add-segment="vacation">Add Vacation Segment</button><button type="button" class="button" data-add-segment="break">Add Break</button></div>';
    echo '</div>';

    echo '<div class="eum-schedule-editor__submit">';
    echo '<button type="submit" class="button button-primary" name="eum_schedule_save_status" value="draft">Save Draft</button>';
    echo '<span class="description">New shifts and unpublished edits stay in Draft until you use Publish Draft Changes.</span>';
    echo '</div>';
    echo '</form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="eum-schedule-delete-form" style="display:none;">';
    echo '<input type="hidden" name="action" value="eum_schedule_delete_shift">';
    echo '<input type="hidden" name="shift_id" id="eum-schedule-delete-id" value="0">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '<div class="eum-schedule-contextmenu" id="eum-schedule-contextmenu" hidden>';
    echo '<button type="button" class="eum-schedule-contextmenu__item" data-context-action="copy">Copy Shift</button>';
    echo '<button type="button" class="eum-schedule-contextmenu__item" data-context-action="paste">Paste Shift</button>';
    echo '<button type="button" class="eum-schedule-contextmenu__item" data-context-action="delete">Delete Shift</button>';
    echo '<button type="button" class="eum-schedule-contextmenu__item" data-context-action="new">Add Shift Here</button>';
    echo '</div>';

    echo '<template id="eum-schedule-segment-template">' . $this->render_segment_row('__INDEX__', [
      'kind' => 'work',
      'start_local' => '',
      'end_local' => '',
      'job_path_term_id' => !empty($path_options) ? (int)array_key_first($path_options) : 0,
      'task_label' => '',
      'cost_center_term_id' => 0,
    ], $path_options, $task_map, $cost_center_options) . '</template>';
    return ob_get_clean();
  }

  private function render_segment_row($index, array $segment, array $path_options, array $task_map, array $cost_center_options): string {
    $kind = self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work'));
    $path_id = (int)($segment['job_path_term_id'] ?? 0);
    $task_value = sanitize_text_field((string)($segment['task_label'] ?? ''));
    $cost_center_term_id = (int)($segment['cost_center_term_id'] ?? 0);
    $task_options = $path_id > 0 ? (array)($task_map[$path_id] ?? []) : [];
    $safe_index = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$index);
    $path_select_id = 'eum-segment-path-' . $safe_index . '-' . wp_rand(1000, 999999);
    $task_select_id = 'eum-segment-task-' . $safe_index . '-' . wp_rand(1000, 999999);

    $row_class = 'eum-schedule-segment-row';
    if ($kind === 'break') $row_class .= ' is-break';
    if ($kind === 'vacation') $row_class .= ' is-vacation';

    ob_start();
    echo '<div class="' . esc_attr($row_class) . '" data-segment-row="1">';
    echo '<label><span>Type</span><select name="schedule_segment_kind[]" class="eum-segment-kind">';
    echo '<option value="work" ' . selected($kind, 'work', false) . '>Work</option>';
    echo '<option value="vacation" ' . selected($kind, 'vacation', false) . '>Vacation</option>';
    echo '<option value="break" ' . selected($kind, 'break', false) . '>Break</option>';
    echo '</select></label>';
    echo '<label><span>Start</span><input type="datetime-local" name="schedule_segment_start[]" value="' . esc_attr((string)($segment['start_local'] ?? '')) . '" required></label>';
    echo '<label><span>End</span><input type="datetime-local" name="schedule_segment_end[]" value="' . esc_attr((string)($segment['end_local'] ?? '')) . '" required></label>';
    echo '<div class="eum-segment-work-field eum-segment-path-task-group">';
    echo '<span>Path / Task</span>';
    echo '<div class="eum-schedule-path-task-picker" data-path-select="' . esc_attr($path_select_id) . '" data-task-select="' . esc_attr($task_select_id) . '">';
    echo '<div class="eum-schedule-path-task-picker__toolbar"><button type="button" class="eum-schedule-path-task-picker__back" disabled>&lsaquo;</button><div class="eum-schedule-path-task-picker__meta"><strong>Current Path</strong><span class="eum-schedule-path-task-picker__path">/</span></div></div>';
    echo '<div class="eum-schedule-path-task-picker__panel"></div>';
    echo '<div class="eum-schedule-path-task-picker__summary"></div>';
    echo '</div>';
    echo '<div class="eum-segment-select-fallback">';
    echo '<select name="schedule_segment_job_path[]" id="' . esc_attr($path_select_id) . '" class="eum-segment-path">';
    echo '<option value="0">— Select Path —</option>';
    foreach ($path_options as $term_id => $label) {
      echo '<option value="' . (int)$term_id . '" ' . selected($path_id, (int)$term_id, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '<select name="schedule_segment_task[]" id="' . esc_attr($task_select_id) . '" class="eum-segment-task">';
    echo '<option value="">— Select Task —</option>';
    foreach ($task_options as $label) {
      echo '<option value="' . esc_attr($label) . '" ' . selected($task_value, $label, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';
    echo '<label class="eum-segment-work-field"><span>Cost Center</span><select name="schedule_segment_cost_center[]" class="eum-segment-cost-center">';
    echo '<option value="0">Use Job Task Billing</option>';
    foreach ($cost_center_options as $term_id => $label) {
      echo '<option value="' . (int)$term_id . '" ' . selected($cost_center_term_id, (int)$term_id, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<button type="button" class="button-link-delete" data-remove-segment="1" aria-label="Remove segment">✕</button>';
    echo '</div>';
    return ob_get_clean();
  }

  private function render_team_summary(array $summary): string {
    ob_start();
    echo '<div class="eum-schedule-panel eum-schedule-panel--summary">';
    echo '<h4 style="margin:0 0 12px;">Team Scheduling Summary</h4>';
    echo '<div class="eum-schedule__summaryrow">';
    echo $this->summary_card('Team Hours (Owned Paths)', number_format((float)$summary['team_hours'], 2));
    echo $this->summary_card('Team Labor Cost (Owned Paths)', $this->money((float)$summary['team_cost']));
    echo '</div>';

    echo '<h5 style="margin:16px 0 8px;">Per Person</h5>';
    echo '<table class="widefat striped"><thead><tr><th>User</th><th>Hours</th><th>Labor Cost</th></tr></thead><tbody>';
    if (empty($summary['per_person'])) {
      echo '<tr><td colspan="3">No scheduled time in this range.</td></tr>';
    } else {
      foreach ($summary['per_person'] as $row) {
        $avatar = !empty($row['user_id']) && class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html((int)$row['user_id'], 30, 'eum-schedule-summary-person__avatar') : '';
        echo '<tr><td><span class="eum-schedule-summary-person">' . $avatar . '<span>' . esc_html($row['user_label']) . '</span></span></td><td>' . esc_html(number_format((float)$row['hours'], 2)) . '</td><td>' . esc_html($this->money((float)$row['cost'])) . '</td></tr>';
      }
    }
    echo '</tbody></table>';

    echo '<h5 style="margin:16px 0 8px;">Owned Paths / Billing</h5>';
    echo '<table class="widefat striped"><thead><tr><th>Path</th><th>Task</th><th>Billing To</th><th>Hours</th><th>Labor Cost</th></tr></thead><tbody>';
    if (empty($summary['per_path_task'])) {
      echo '<tr><td colspan="5">No owned-path shifts in this range.</td></tr>';
    } else {
      foreach ($summary['per_path_task'] as $row) {
        echo '<tr><td>' . esc_html($row['path_label']) . '</td><td>' . esc_html($row['task_label']) . '</td><td>' . esc_html($row['billing_label']) . '</td><td>' . esc_html(number_format((float)$row['hours'], 2)) . '</td><td>' . esc_html($this->money((float)$row['cost'])) . '</td></tr>';
      }
    }
    echo '</tbody></table>';
    echo '</div>';
    return ob_get_clean();
  }

  private function summary_card(string $label, string $value): string {
    return '<div class="eum-schedule-summary"><div class="eum-schedule-summary__value">' . esc_html($value) . '</div><div class="eum-schedule-summary__label">' . esc_html($label) . '</div></div>';
  }

  private function tab_link(string $label, string $target_tab, string $current_tab, array $args): string {
    $args['eum_schedule_tab'] = $target_tab;
    $url = add_query_arg($args, $this->current_url());
    return '<a class="eum-schedule__tab' . ($target_tab === $current_tab ? ' is-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
  }

  private function preserved_query_args(array $exclude = []): array {
    $out = [];
    foreach ($_GET as $key => $value) {
      $key = sanitize_key((string)$key);
      if ($key === '' || in_array($key, $exclude, true)) continue;
      if (is_scalar($value)) $out[$key] = sanitize_text_field((string)$value);
    }
    return $out;
  }

  private function current_url(): string {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    return $request_uri !== '' ? home_url($request_uri) : home_url('/');
  }

  private function clean_return_url(): string {
    return remove_query_arg(['eum_schedule_msg', 'eum_schedule_type'], $this->current_url());
  }

  private function user_display_name(int $user_id): string {
    $user = $user_id > 0 ? get_userdata($user_id) : null;
    if (!$user) return 'User #' . $user_id;
    return class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_list_label($user_id) : (string)$user->display_name;
  }

  private function can_manage_user_week_actions(int $actor_user_id, int $target_user_id, int $range_start_utc, int $range_end_utc): bool {
    if ($target_user_id <= 0) return false;
    if ($this->can_manage_user_schedule_fully($actor_user_id, $target_user_id)) return true;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    return $this->actor_has_user_on_schedule($actor_user_id, $target_user_id, $range_start_utc, $range_end_utc);
  }

  private function week_range_for_user_from_date(int $user_id, string $anchor_date): array {
    $tz = $this->user_timezone($user_id);
    try {
      $anchor = new DateTime($anchor_date . ' 12:00:00', $tz);
    } catch (Exception $e) {
      $anchor = new DateTime('now', $tz);
    }
    $start = clone $anchor;
    $start->modify('Sunday this week')->setTime(0, 0, 0);
    $end = clone $start;
    $end->modify('+7 days');
    return [$start->getTimestamp(), $end->getTimestamp()];
  }

  private function render_user_week_actions(int $actor_user_id, int $user_id, int $range_start_utc, int $range_end_utc): string {
    if (!$this->can_manage_user_week_actions($actor_user_id, $user_id, $range_start_utc, $range_end_utc)) return '';
    $return_url = $this->clean_return_url();
    $tz = $this->user_timezone($user_id);
    $source_date = new DateTime('@' . $range_start_utc);
    $source_date->setTimezone($tz);
    $source_date->modify('-7 days');

    ob_start();
    echo '<div class="eum-scheduler-user__actions">';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-scheduler-user__actionform">';
    echo '<input type="hidden" name="action" value="eum_schedule_set_template">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$user_id . '">';
    echo '<input type="hidden" name="range_start_utc" value="' . (int)$range_start_utc . '">';
    echo '<input type="hidden" name="range_end_utc" value="' . (int)$range_end_utc . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_schedule_template_' . $user_id . '_' . $range_start_utc, 'eum_schedule_template_nonce');
    echo '<button type="submit" class="button button-small">Set Template</button>';
    echo '</form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-scheduler-user__actionform eum-scheduler-user__actionform--copy">';
    echo '<input type="hidden" name="action" value="eum_schedule_copy_week">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$user_id . '">';
    echo '<input type="hidden" name="range_start_utc" value="' . (int)$range_start_utc . '">';
    echo '<input type="hidden" name="range_end_utc" value="' . (int)$range_end_utc . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_schedule_copy_week_' . $user_id . '_' . $range_start_utc, 'eum_schedule_copy_week_nonce');
    echo '<input type="date" name="source_week_date" value="' . esc_attr($source_date->format('Y-m-d')) . '" aria-label="Copy source week">';
    echo '<button type="submit" class="button button-small">Copy Week</button>';
    echo '</form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-scheduler-user__actionform" onsubmit="return window.confirm(\'Clear this viewed week for this user? Published shifts will stay pending deletion until draft changes are published.\');">';
    echo '<input type="hidden" name="action" value="eum_schedule_clear_week">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$user_id . '">';
    echo '<input type="hidden" name="range_start_utc" value="' . (int)$range_start_utc . '">';
    echo '<input type="hidden" name="range_end_utc" value="' . (int)$range_end_utc . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_schedule_clear_week_' . $user_id . '_' . $range_start_utc, 'eum_schedule_clear_week_nonce');
    echo '<button type="submit" class="button button-small button-link-delete">Clear Week</button>';
    echo '</form>';

    echo $this->render_user_template_preview($user_id);
    echo '</div>';
    return ob_get_clean();
  }

  private function get_visible_shifts_for_user(int $actor_user_id, int $target_user_id, int $range_start_utc, int $range_end_utc, string $tab, string $status_filter): array {
    if ($target_user_id > 0 && !$this->is_schedulable_user($target_user_id)) return [];
    $statuses = $status_filter === 'all' ? ['publish', 'draft'] : [$status_filter];
    $all = $this->get_shifts_for_user($target_user_id, $range_start_utc, $range_end_utc, $statuses);
    $draft_revisions = [];
    foreach ($all as $shift) {
      if (get_post_status((int)$shift->ID) !== 'draft') continue;
      $parent_id = (int)get_post_meta((int)$shift->ID, self::META_REVISION_PARENT, true);
      if ($parent_id > 0) {
        $draft_revisions[$parent_id] = [
          'shift_id' => (int)$shift->ID,
          'action' => (string)get_post_meta((int)$shift->ID, self::META_REVISION_ACTION, true),
        ];
      }
    }

    $out = [];
    foreach ($all as $shift) {
      $shift_id = (int)$shift->ID;
      $status = (string)get_post_status($shift_id);
      $revision_action = $status === 'draft' ? (string)get_post_meta($shift_id, self::META_REVISION_ACTION, true) : '';
      if ($tab === 'schedule') {
        if ($target_user_id <= 0) continue;
        if ($status !== 'publish') continue;
        if ($actor_user_id !== $target_user_id && !$this->can_manage_user_schedule_fully($actor_user_id, $target_user_id) && !EUM_Access::is_it_or_hr($actor_user_id)) continue;
        $out[] = $shift;
        continue;
      }
      if ($status_filter === 'all' && $status === 'publish' && isset($draft_revisions[$shift_id])) continue;
      if ($status === 'draft' && $revision_action === 'delete') continue;
      if ($target_user_id === 0) {
        if ($this->can_edit_shift($actor_user_id, $shift_id) || $this->shift_has_owned_segment($actor_user_id, $shift_id)) $out[] = $shift;
        continue;
      }
      if ($this->can_manage_user_schedule_fully($actor_user_id, $target_user_id) || $actor_user_id === $target_user_id || EUM_Access::is_it_or_hr($actor_user_id) || $this->actor_has_user_on_schedule($actor_user_id, $target_user_id, 0, 0)) {
        $out[] = $shift;
      }
    }
    return $out;
  }

  private function get_shifts_for_user(int $user_id, int $range_start_utc = 0, int $range_end_utc = 0, array $post_statuses = ['publish']): array {
    $query = [
      'post_type' => self::CPT_SHIFT,
      'post_status' => $post_statuses,
      'numberposts' => -1,
      'orderby' => 'meta_value_num',
      'meta_key' => self::META_SHIFT_START_UTC,
      'order' => 'ASC',
      'meta_query' => [
        ['key' => self::META_USER_ID, 'value' => $user_id],
      ],
    ];

    $items = get_posts($query);
    $out = [];
    foreach ((array)$items as $item) {
      $shift_id = (int)$item->ID;
      if ($range_start_utc > 0 || $range_end_utc > 0) {
        $start = (int)get_post_meta($shift_id, self::META_SHIFT_START_UTC, true);
        $end = (int)get_post_meta($shift_id, self::META_SHIFT_END_UTC, true);
        if ($range_end_utc > 0 && $start >= $range_end_utc) continue;
        if ($range_start_utc > 0 && $end <= $range_start_utc) continue;
      }
      $out[] = $item;
    }
    return $out;
  }

  private function shift_overlaps_range(int $shift_id, int $range_start_utc, int $range_end_utc): bool {
    $start = (int)get_post_meta($shift_id, self::META_SHIFT_START_UTC, true);
    $end = (int)get_post_meta($shift_id, self::META_SHIFT_END_UTC, true);
    return $start < $range_end_utc && $end > $range_start_utc;
  }

  public function labor_rows_for_range(int $range_start_utc, int $range_end_utc, array $args = []): array {
    if ($range_start_utc <= 0 || $range_end_utc <= $range_start_utc) return [];

    $statuses = array_values(array_filter(array_map('sanitize_key', (array)($args['statuses'] ?? ['publish'])), static fn($status) => in_array($status, ['publish', 'draft'], true)));
    if (empty($statuses)) $statuses = ['publish'];
    $user_ids = array_values(array_map('intval', (array)($args['user_ids'] ?? [])));
    $filter_users = array_key_exists('user_ids', $args);
    $scope_job_path_term_id = (int)($args['job_path_term_id'] ?? 0);
    $include_descendants = !empty($args['include_descendants']);
    $scope_finance_path_term_id = (int)($args['finance_path_term_id'] ?? 0);

    $shift_ids = get_posts([
      'post_type' => self::CPT_SHIFT,
      'post_status' => $statuses,
      'numberposts' => -1,
      'fields' => 'ids',
      'orderby' => 'meta_value_num',
      'meta_key' => self::META_SHIFT_START_UTC,
      'order' => 'ASC',
    ]);

    $segments_by_user = [];
    foreach ((array)$shift_ids as $shift_id) {
      $shift_id = (int)$shift_id;
      if ($shift_id <= 0) continue;
      if (!$this->shift_overlaps_range($shift_id, $range_start_utc, $range_end_utc)) continue;
      $user_id = (int)get_post_meta($shift_id, self::META_USER_ID, true);
      if ($filter_users && !in_array($user_id, $user_ids, true)) continue;
      $status = (string)get_post_status($shift_id);
      foreach ($this->normalized_segments_from_meta($shift_id) as $segment) {
        if (($segment['kind'] ?? 'work') !== 'work') continue;
        $segment_start = max($range_start_utc, (int)($segment['start_utc'] ?? 0));
        $segment_end = min($range_end_utc, (int)($segment['end_utc'] ?? 0));
        if ($segment_end <= $segment_start) continue;
        $job_path_term_id = (int)($segment['job_path_term_id'] ?? 0);
        $finance_path_term_id = (int)($segment['cost_center_term_id'] ?? 0);
        if (!$this->job_path_matches_scope($job_path_term_id, $scope_job_path_term_id, $include_descendants)) continue;
        if ($scope_finance_path_term_id > 0 && $finance_path_term_id !== $scope_finance_path_term_id) continue;
        $segment['start_utc'] = $segment_start;
        $segment['end_utc'] = $segment_end;
        $segment['shift_id'] = $shift_id;
        $segment['user_id'] = $user_id;
        $segment['status'] = $status;
        if (!isset($segments_by_user[$user_id])) $segments_by_user[$user_id] = [];
        $segments_by_user[$user_id][] = $segment;
      }
    }

    $rows = [];
    foreach ($segments_by_user as $user_id => $segments) {
      $allocation = $this->allocate_costs_for_user_segments((int)$user_id, $segments);
      foreach ((array)($allocation['segments'] ?? []) as $segment) {
        $rows[] = [
          'shift_id' => (int)($segment['shift_id'] ?? 0),
          'user_id' => (int)$user_id,
          'status' => (string)($segment['status'] ?? 'publish'),
          'start_utc' => (int)($segment['start_utc'] ?? 0),
          'end_utc' => (int)($segment['end_utc'] ?? 0),
          'job_path_term_id' => (int)($segment['job_path_term_id'] ?? 0),
          'task_label' => sanitize_text_field((string)($segment['task_label'] ?? '')),
          'finance_path_term_id' => (int)($segment['cost_center_term_id'] ?? 0),
          'hours' => round((float)($segment['hours'] ?? 0), 4),
          'regular_hours' => round((float)($segment['regular_hours'] ?? 0), 4),
          'overtime_hours' => round((float)($segment['overtime_hours'] ?? 0), 4),
          'cost' => round((float)($segment['cost'] ?? 0), 2),
          'path_label' => $this->job_path_label((int)($segment['job_path_term_id'] ?? 0)),
          'billing_label' => (string)($segment['billing_label'] ?? ''),
        ];
      }
    }

    usort($rows, static fn($a, $b) => ((int)($a['start_utc'] ?? 0)) <=> ((int)($b['start_utc'] ?? 0)));
    return $rows;
  }

  private function build_schedule_summary_for_user(int $user_id, array $shifts, int $range_start_utc, int $range_end_utc): array {
    $hour_segments = [];
    $cost_segments = [];
    $published_count = 0;
    $draft_count = 0;
    foreach ($shifts as $shift) {
      $shift_id = (int)$shift->ID;
      $status = get_post_status($shift_id);
      if ($status === 'publish') $published_count++;
      else $draft_count++;
      foreach ($this->normalized_segments_from_meta($shift_id) as $segment) {
        $kind = self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work'));
        if ($kind === 'break') continue;
        $start = max($range_start_utc, (int)$segment['start_utc']);
        $end = min($range_end_utc, (int)$segment['end_utc']);
        if ($end <= $start) continue;
        $segment['start_utc'] = $start;
        $segment['end_utc'] = $end;
        $hour_segments[] = $segment;
        if ($kind === 'work') $cost_segments[] = $segment;
      }
    }
    $allocation = $this->allocate_costs_for_user_segments($user_id, $cost_segments);
    return [
      'hours' => $this->scheduled_hours_for_segments($hour_segments),
      'cost' => (float)$allocation['cost'],
      'published_count' => $published_count,
      'draft_count' => $draft_count,
    ];
  }

  private function build_team_summary(int $actor_user_id, int $range_start_utc, int $range_end_utc, string $status_filter, array $filters = []): array {
    $candidate_users = $this->schedule_candidate_user_ids($actor_user_id, $range_start_utc, $range_end_utc);
    array_unshift($candidate_users, 0);
    $candidate_users = array_values(array_unique(array_map('intval', $candidate_users)));
    $statuses = $status_filter === 'all' ? ['publish', 'draft'] : [$status_filter];
    $team_hours = 0.0;
    $team_cost = 0.0;
    $per_person = [];
    $per_path_task = [];

    foreach ($candidate_users as $user_id) {
      $user = $user_id > 0 ? get_userdata($user_id) : null;
      if ($user_id > 0 && !$user) continue;
      if ($user_id > 0 && !$this->user_matches_schedule_filters($user_id, $filters)) {
        $matching_user_shifts = $this->apply_schedule_filters_to_shifts($this->get_shifts_for_user($user_id, $range_start_utc, $range_end_utc, $statuses), $user_id, $filters);
        if (empty($matching_user_shifts)) continue;
      }
      $all_user_segments = [];
      $owned_segments = [];
      foreach ($this->apply_schedule_filters_to_shifts($this->get_shifts_for_user($user_id, $range_start_utc, $range_end_utc, $statuses), $user_id, $filters) as $shift) {
        $shift_id = (int)$shift->ID;
        $can_see_all = $this->can_manage_user_schedule_fully($actor_user_id, $user_id) || EUM_Access::is_it_or_hr($actor_user_id) || $user_id === $actor_user_id;
        foreach ($this->normalized_segments_from_meta($shift_id) as $segment) {
          $kind = self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work'));
          if ($kind === 'break') continue;
          $start = max($range_start_utc, (int)$segment['start_utc']);
          $end = min($range_end_utc, (int)$segment['end_utc']);
          if ($end <= $start) continue;
          $segment['start_utc'] = $start;
          $segment['end_utc'] = $end;
          if ($can_see_all) $all_user_segments[] = $segment;
          if ($kind === 'work' && $this->actor_owns_job_term($actor_user_id, (int)($segment['job_path_term_id'] ?? 0))) $owned_segments[] = $segment;
        }
      }

      $base_segments = !empty($all_user_segments) ? $all_user_segments : $owned_segments;
      $allocation_all = $this->allocate_costs_for_user_segments($user_id, array_values(array_filter($base_segments, static fn($segment) => self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work')) === 'work')));
      $scheduled_hours = $this->scheduled_hours_for_segments($base_segments);
      if ($scheduled_hours > 0 || $allocation_all['cost'] > 0) {
        $per_person[] = [
          'user_id' => $user_id,
          'user_label' => ($user_id > 0 && $user) ? (class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_list_label($user_id) : $user->display_name) : 'Open Shifts',
          'hours' => $scheduled_hours,
          'cost' => $allocation_all['cost'],
        ];
      }

      $allocation_owned = $this->allocate_costs_for_user_segments($user_id, $owned_segments);
      $team_hours += (float)$allocation_owned['hours'];
      $team_cost += (float)$allocation_owned['cost'];
      foreach ($allocation_owned['segments'] as $segment) {
        $key = ((int)$segment['job_path_term_id']) . '|' . strtolower((string)$segment['task_label']) . '|' . (int)($segment['cost_center_term_id'] ?? 0);
        if (!isset($per_path_task[$key])) {
          $per_path_task[$key] = [
            'path_label' => $this->job_path_label((int)$segment['job_path_term_id']),
            'task_label' => (string)$segment['task_label'],
            'billing_label' => (string)($segment['billing_label'] ?? (string)$segment['task_label']),
            'hours' => 0.0,
            'cost' => 0.0,
          ];
        }
        $per_path_task[$key]['hours'] += (float)$segment['hours'];
        $per_path_task[$key]['cost'] += (float)$segment['cost'];
      }
    }

    usort($per_person, static fn($a, $b) => strcasecmp((string)$a['user_label'], (string)$b['user_label']));
    uasort($per_path_task, static function($a, $b) {
      $cmp = strcasecmp((string)$a['path_label'], (string)$b['path_label']);
      if ($cmp !== 0) return $cmp;
      $task_cmp = strcasecmp((string)$a['task_label'], (string)$b['task_label']);
      return $task_cmp !== 0 ? $task_cmp : strcasecmp((string)$a['billing_label'], (string)$b['billing_label']);
    });

    return [
      'team_hours' => $team_hours,
      'team_cost' => $team_cost,
      'per_person' => array_values($per_person),
      'per_path_task' => array_values($per_path_task),
    ];
  }


  private function all_schedule_task_options(array $task_map): array {
    $tasks = [];
    foreach ($task_map as $labels) {
      foreach ((array)$labels as $label) {
        $label = sanitize_text_field((string)$label);
        if ($label === '') continue;
        $tasks[$label] = $label;
      }
    }
    natcasesort($tasks);
    return array_values($tasks);
  }

  private function apply_schedule_filters_to_shifts(array $shifts, int $user_id, array $filters = []): array {
    if (empty($filters)) return $shifts;
    return array_values(array_filter($shifts, function($shift) use ($user_id, $filters) {
      return $shift instanceof WP_Post && $this->shift_matches_schedule_filters((int)$shift->ID, $user_id, $filters);
    }));
  }

  private function shift_matches_schedule_filters(int $shift_id, int $user_id, array $filters = []): bool {
    $assignment = (string)($filters['assignment'] ?? 'all');
    $search = strtolower(trim((string)($filters['search'] ?? '')));
    $path_id = (int)($filters['path_id'] ?? 0);
    $task = strtolower(trim((string)($filters['task'] ?? '')));
    $group_id = (int)($filters['group_id'] ?? 0);
    $shift_user_id = (int)get_post_meta($shift_id, self::META_USER_ID, true);

    if ($group_id > 0 && ($shift_user_id <= 0 || !$this->user_matches_schedule_group($shift_user_id, $group_id))) return false;

    if ($assignment === 'open' && $shift_user_id > 0) return false;
    if ($assignment === 'assigned' && $shift_user_id <= 0) return false;

    if ($path_id <= 0 && $task === '' && $search === '') return true;

    $segments = $this->normalized_segments_from_meta($shift_id);
    $matched_path_or_task = ($path_id <= 0 && $task === '');
    $haystack = [];
    if ($shift_user_id > 0) {
      $user = get_userdata($shift_user_id);
      if ($user) {
        $haystack[] = strtolower((string)$user->display_name);
        $haystack[] = strtolower((string)$user->user_email);
        $haystack[] = strtolower((string)$user->user_login);
      }
    } else {
      $haystack[] = 'open shift';
    }
    $notes = sanitize_textarea_field((string)get_post_meta($shift_id, self::META_NOTES, true));
    if ($notes !== '') $haystack[] = strtolower($notes);

    foreach ($segments as $segment) {
      $kind = self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work'));
      if ($kind === 'vacation') {
        $haystack[] = 'vacation';
        continue;
      }
      if ($kind !== 'work') continue;
      $segment_path_id = (int)($segment['job_path_term_id'] ?? 0);
      $segment_task = sanitize_text_field((string)($segment['task_label'] ?? ''));
      $cost_center_label = $this->cost_center_label((int)($segment['cost_center_term_id'] ?? 0));
      $path_label = $this->job_path_label($segment_path_id);
      if ($path_label !== '') $haystack[] = strtolower($path_label);
      if ($segment_task !== '') $haystack[] = strtolower($segment_task);
      if ($cost_center_label !== '') $haystack[] = strtolower($cost_center_label);

      $path_ok = ($path_id <= 0 || $segment_path_id === $path_id);
      $task_ok = ($task === '' || strtolower($segment_task) === $task);
      if ($path_ok && $task_ok) $matched_path_or_task = true;
    }

    if (!$matched_path_or_task) return false;
    if ($search === '') return true;
    foreach ($haystack as $piece) {
      if ($piece !== '' && strpos($piece, $search) !== false) return true;
    }
    return false;
  }

  private function user_matches_schedule_filters(int $user_id, array $filters = []): bool {
    if ($user_id <= 0) return (($filters['assignment'] ?? 'all') !== 'assigned');
    $group_id = (int)($filters['group_id'] ?? 0);
    if ($group_id > 0 && !$this->user_matches_schedule_group($user_id, $group_id)) return false;
    $search = strtolower(trim((string)($filters['search'] ?? '')));
    if ($search === '') return true;
    $user = get_userdata($user_id);
    if (!$user) return false;
    $haystack = [strtolower((string)$user->display_name), strtolower((string)$user->user_email), strtolower((string)$user->user_login)];
    foreach ($haystack as $piece) {
      if ($piece !== '' && strpos($piece, $search) !== false) return true;
    }
    return false;
  }

  private function schedule_group_filter_options(): array {
    if (!taxonomy_exists('eum_user_group')) return [];
    $terms = get_terms(['taxonomy' => 'eum_user_group', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
    if (is_wp_error($terms)) return [];
    $out = [];
    foreach ($terms as $term) $out[(int)$term->term_id] = (string)$term->name;
    return $out;
  }

  private function user_matches_schedule_group(int $user_id, int $term_id): bool {
    if ($user_id <= 0 || $term_id <= 0 || !taxonomy_exists('eum_user_group')) return false;
    $groups = wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']);
    return !is_wp_error($groups) && in_array($term_id, array_map('intval', (array)$groups), true);
  }

  private function render_saved_filter_hint(string $label = 'Saved Filters'): string {
    return '<div class="eum-saved-filters" data-eum-filter-manager="1"><div class="eum-saved-filters__title">' . esc_html($label) . '</div><div class="eum-saved-filters__body"></div></div>';
  }

  private function allocate_costs_for_user_segments(int $user_id, array $segments): array {
    $segments = array_values(array_filter($segments, function($segment) {
      return self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work')) === 'work'
        && (int)($segment['end_utc'] ?? 0) > (int)($segment['start_utc'] ?? 0);
    }));
    usort($segments, static fn($a, $b) => ((int)$a['start_utc']) <=> ((int)$b['start_utc']));

    $hours_total = 0.0;
    $cost_total = 0.0;
    $compensation = ($user_id > 0 && class_exists('EUM_Timeclock')) ? EUM_Timeclock::compensation_for_user($user_id) : ['type' => 'unpaid', 'amount' => 0, 'hourly_rate' => 0, 'annualized_amount' => 0];
    $weekly_seconds = [];
    $allocated_segments = [];

    foreach ($segments as $segment) {
      $week_key = $this->week_key_for_utc($user_id, (int)$segment['start_utc']);
      $weekly_seconds[$week_key] = (int)($weekly_seconds[$week_key] ?? 0);
      $seconds = max(0, (int)$segment['end_utc'] - (int)$segment['start_utc']);
      if ($seconds <= 0) continue;

      $regular_remaining = max(0, (self::REGULAR_WEEKLY_HOURS * HOUR_IN_SECONDS) - $weekly_seconds[$week_key]);
      $regular_seconds = min($seconds, $regular_remaining);
      $overtime_seconds = max(0, $seconds - $regular_seconds);
      $weekly_seconds[$week_key] += $seconds;

      $regular_hours = $regular_seconds / HOUR_IN_SECONDS;
      $overtime_hours = $overtime_seconds / HOUR_IN_SECONDS;
      $segment_cost = $this->cost_for_hours($compensation, $regular_hours, $overtime_hours, (int)$segment['start_utc'], (int)$segment['end_utc']);

      $hours_total += $seconds / HOUR_IN_SECONDS;
      $cost_total += $segment_cost;
      $segment['hours'] = $seconds / HOUR_IN_SECONDS;
      $segment['regular_hours'] = $regular_hours;
      $segment['overtime_hours'] = $overtime_hours;
      $segment['cost'] = $segment_cost;
      $segment['cost_center_term_id'] = (int)($segment['cost_center_term_id'] ?? 0);
      $segment['cost_center_label'] = $this->cost_center_label((int)$segment['cost_center_term_id']);
      $segment['billing_label'] = $segment['cost_center_label'] !== '' ? $segment['cost_center_label'] : (string)($segment['task_label'] ?? '');
      $allocated_segments[] = $segment;
    }

    return [
      'hours' => $hours_total,
      'cost' => $cost_total,
      'segments' => $allocated_segments,
    ];
  }

  private function cost_for_hours(array $compensation, float $regular_hours, float $overtime_hours, int $start_utc, int $end_utc): float {
    $type = (string)($compensation['type'] ?? 'unpaid');
    $amount = (float)($compensation['amount'] ?? 0);
    if ($type === 'hourly') return ($regular_hours * $amount) + ($overtime_hours * $amount * 1.5);
    if ($type === 'day_rate') {
      $hourly = (float)($compensation['hourly_rate'] ?? ($amount / self::HOURS_PER_DAY_RATE_DAY));
      return ($regular_hours * $hourly) + ($overtime_hours * $hourly * 1.5);
    }
    if ($type === 'monthly' || $type === 'yearly') {
      $annualized = (float)($compensation['annualized_amount'] ?? 0);
      $seconds = max(0, $end_utc - $start_utc);
      return $annualized * ($seconds / (365 * DAY_IN_SECONDS));
    }
    return 0.0;
  }

  private function job_path_matches_scope(int $candidate_term_id, int $scope_term_id, bool $include_descendants): bool {
    if ($scope_term_id <= 0) return true;
    if ($candidate_term_id <= 0) return false;
    if ($candidate_term_id === $scope_term_id) return true;
    return $include_descendants && term_is_ancestor_of($scope_term_id, $candidate_term_id, 'eum_job_path');
  }

  private function shift_summary(int $shift_id, int $user_id, bool $show_all_segments = true): array {
    $tz = $this->user_timezone($user_id);
    $start = (int)get_post_meta($shift_id, self::META_SHIFT_START_UTC, true);
    $end = (int)get_post_meta($shift_id, self::META_SHIFT_END_UTC, true);
    $segments = $this->normalized_segments_from_meta($shift_id);
    $allocation = $this->allocate_costs_for_user_segments($user_id, $segments);

    $labels = [];
    foreach ($segments as $segment) {
      $seg_start = new DateTime('@' . (int)$segment['start_utc']);
      $seg_end = new DateTime('@' . (int)$segment['end_utc']);
      $seg_start->setTimezone($tz);
      $seg_end->setTimezone($tz);
      $line = $seg_start->format('g:i A') . '–' . $seg_end->format('g:i A') . ' · ';
      $kind = self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work'));
      if ($kind === 'break') {
        $line .= 'Break';
      } elseif ($kind === 'vacation') {
        $line .= 'Vacation';
      } else {
        $line .= $this->job_path_label((int)($segment['job_path_term_id'] ?? 0)) . ' · ' . (string)($segment['task_label'] ?? '');
      }
      if ($kind === 'work') {
        $billing_label = $this->cost_center_label((int)($segment['cost_center_term_id'] ?? 0));
        if ($billing_label !== '') $line .= ' -> ' . $billing_label;
      }
      $labels[] = $line;
    }
    if (!$show_all_segments && !empty($labels)) $labels = array_slice($labels, 0, 2);

    return [
      'time' => $this->format_local_ts($start, $tz) . ' – ' . $this->format_local_ts($end, $tz),
      'segments' => $labels,
      'notes' => (string)get_post_meta($shift_id, self::META_NOTES, true),
      'cost' => (float)$allocation['cost'],
    ];
  }

  private function shift_payload_for_js(int $shift_id): array {
    $user_id = (int)get_post_meta($shift_id, self::META_USER_ID, true);
    $tz = $this->user_timezone($user_id);
    $segments = [];
    foreach ($this->normalized_segments_from_meta($shift_id) as $segment) {
      $segments[] = [
        'kind' => (string)($segment['kind'] ?? 'work'),
        'start_local' => $this->format_local_input((int)$segment['start_utc'], $tz),
        'end_local' => $this->format_local_input((int)$segment['end_utc'], $tz),
        'job_path_term_id' => (int)($segment['job_path_term_id'] ?? 0),
        'task_label' => (string)($segment['task_label'] ?? ''),
        'cost_center_term_id' => (int)($segment['cost_center_term_id'] ?? 0),
      ];
    }
    return [
      'shift_id' => $shift_id,
      'user_id' => $user_id,
      'notes' => (string)get_post_meta($shift_id, self::META_NOTES, true),
      'segments' => $segments,
      'availability_warning' => class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::availability_warning_for_shift($shift_id, $user_id) : '',
      'delete_nonce' => wp_create_nonce('eum_schedule_delete_' . $shift_id),
    ];
  }

  private function normalized_segments_from_meta(int $shift_id): array {
    $raw = get_post_meta($shift_id, self::META_SEGMENTS, true);
    $segments = is_array($raw) ? $raw : [];
    $out = [];
    foreach ($segments as $segment) {
      if (!is_array($segment)) continue;
      $start = (int)($segment['start_utc'] ?? 0);
      $end = (int)($segment['end_utc'] ?? 0);
      if ($start <= 0 || $end <= $start) continue;
      $kind = self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work'));
      $out[] = [
        'kind' => $kind,
        'start_utc' => $start,
        'end_utc' => $end,
        'job_path_term_id' => $kind === 'work' ? max(0, (int)($segment['job_path_term_id'] ?? 0)) : 0,
        'task_label' => $kind === 'work' ? sanitize_text_field((string)($segment['task_label'] ?? '')) : '',
        'cost_center_term_id' => $kind === 'work' ? max(0, (int)($segment['cost_center_term_id'] ?? 0)) : 0,
      ];
    }
    usort($out, static fn($a, $b) => ((int)$a['start_utc']) <=> ((int)$b['start_utc']));
    return $out;
  }

  private function segments_from_request(array $source, int $target_user_id) {
    $kinds = (array)($source['schedule_segment_kind'] ?? []);
    $starts = (array)($source['schedule_segment_start'] ?? []);
    $ends = (array)($source['schedule_segment_end'] ?? []);
    $paths = (array)($source['schedule_segment_job_path'] ?? []);
    $tasks = (array)($source['schedule_segment_task'] ?? []);
    $cost_centers = (array)($source['schedule_segment_cost_center'] ?? []);

    $count = max(count($kinds), count($starts), count($ends), count($paths), count($tasks), count($cost_centers));
    if ($count <= 0) return new WP_Error('missing_segments', 'At least one schedule segment is required.');

    $tz = $this->user_timezone($target_user_id);
    $segments = [];
    for ($i = 0; $i < $count; $i++) {
      $kind = self::normalize_segment_kind_value((string)($kinds[$i] ?? 'work'));
      $start_utc = $this->local_input_to_utc((string)($starts[$i] ?? ''), $tz);
      $end_utc = $this->local_input_to_utc((string)($ends[$i] ?? ''), $tz);
      if ($start_utc <= 0 || $end_utc <= 0 || $end_utc <= $start_utc) {
        return new WP_Error('invalid_segment_time', 'Each segment needs a valid start and end time.');
      }
      if ($kind === 'vacation' && $target_user_id <= 0) {
        return new WP_Error('vacation_requires_user', 'Vacation entries must be assigned to a user.');
      }
      $job_path_term_id = $kind === 'work' ? max(0, (int)($paths[$i] ?? 0)) : 0;
      $task_label = $kind === 'work' ? sanitize_text_field((string)($tasks[$i] ?? '')) : '';
      $cost_center_term_id = $kind === 'work' ? max(0, (int)($cost_centers[$i] ?? 0)) : 0;
      if ($kind === 'work') {
        if ($job_path_term_id <= 0) return new WP_Error('missing_path', 'Each Work segment needs a Job Path.');
        if ($task_label === '') return new WP_Error('missing_task', 'Each Work segment needs a Task.');
        if ($cost_center_term_id > 0 && (!class_exists('EUM_Finance') || !EUM_Finance::is_valid_labor_paycode($cost_center_term_id))) {
          return new WP_Error('invalid_cost_center', 'Each Cost Center must be a valid labor paycode.');
        }
        $allowed_tasks = class_exists('EUM_Taxonomies') ? EUM_Taxonomies::effective_schedule_task_options_for_term($job_path_term_id) : [];
        if (!empty($allowed_tasks) && !in_array($task_label, $allowed_tasks, true)) {
          return new WP_Error('invalid_task', 'Task “' . $task_label . '” is not listed under the selected Job Path.');
        }
      }
      $segments[] = [
        'kind' => $kind,
        'start_utc' => $start_utc,
        'end_utc' => $end_utc,
        'job_path_term_id' => $job_path_term_id,
        'task_label' => $task_label,
        'cost_center_term_id' => $cost_center_term_id,
      ];
    }

    usort($segments, static fn($a, $b) => ((int)$a['start_utc']) <=> ((int)$b['start_utc']));
    $previous_end = 0;
    foreach ($segments as $segment) {
      if ($previous_end > 0 && (int)$segment['start_utc'] < $previous_end) {
        return new WP_Error('overlap_segments', 'Segments cannot overlap.');
      }
      $previous_end = (int)$segment['end_utc'];
    }

    return $segments;
  }

  private function upsert_shift(int $shift_id, int $target_user_id, array $segments, string $notes, string $post_status, int $actor_user_id) {
    $shift_start = min(array_map(fn($segment) => (int)$segment['start_utc'], $segments));
    $shift_end = max(array_map(fn($segment) => (int)$segment['end_utc'], $segments));

    $exclude_shift_ids = [];
    if ($shift_id > 0) $exclude_shift_ids[] = $shift_id;
    $revision_parent_id = $shift_id > 0 ? (int)get_post_meta($shift_id, self::META_REVISION_PARENT, true) : 0;
    if ($revision_parent_id > 0) $exclude_shift_ids[] = $revision_parent_id;
    $overlap = $this->find_overlapping_shift($target_user_id, $shift_start, $shift_end, $exclude_shift_ids);
    if ($overlap > 0) {
      return new WP_Error('overlap', 'That scheduled time overlaps an existing shift for this user.');
    }

    if ($shift_id > 0) {
      $shift = get_post($shift_id);
      if (!$shift || $shift->post_type !== self::CPT_SHIFT) return new WP_Error('missing_shift', 'The shift no longer exists.');
      wp_update_post([
        'ID' => $shift_id,
        'post_status' => $post_status,
        'post_title' => 'Schedule Shift - User #' . $target_user_id . ' - ' . gmdate('Y-m-d H:i', $shift_start),
      ]);
    } else {
      $inserted = wp_insert_post([
        'post_type' => self::CPT_SHIFT,
        'post_status' => $post_status,
        'post_title' => 'Schedule Shift - User #' . $target_user_id . ' - ' . gmdate('Y-m-d H:i', $shift_start),
        'post_author' => $actor_user_id,
      ], true);
      if (is_wp_error($inserted) || (int)$inserted <= 0) return new WP_Error('save_failed', 'Could not save the shift.');
      $shift_id = (int)$inserted;
    }

    update_post_meta($shift_id, self::META_USER_ID, $target_user_id);
    update_post_meta($shift_id, self::META_SHIFT_START_UTC, $shift_start);
    update_post_meta($shift_id, self::META_SHIFT_END_UTC, $shift_end);
    update_post_meta($shift_id, self::META_SEGMENTS, $segments);
    update_post_meta($shift_id, self::META_NOTES, $notes);
    update_post_meta($shift_id, self::META_UPDATED_BY, $actor_user_id);
    update_post_meta($shift_id, self::META_UPDATED_AT, current_time('mysql'));
    delete_post_meta($shift_id, self::META_REVISION_ACTION);

    return $shift_id;
  }

  private function find_overlapping_shift(int $user_id, int $start_utc, int $end_utc, array $exclude_shift_ids = []): int {
    if ($user_id <= 0) return 0;
    $exclude_shift_ids = array_values(array_unique(array_filter(array_map('intval', $exclude_shift_ids))));
    $all = $this->get_shifts_for_user($user_id, 0, 0, ['publish', 'draft']);
    $delete_parents = [];
    foreach ($all as $shift) {
      $shift_id = (int)$shift->ID;
      if ((string)get_post_status($shift_id) !== 'draft') continue;
      $parent_id = (int)get_post_meta($shift_id, self::META_REVISION_PARENT, true);
      $action = (string)get_post_meta($shift_id, self::META_REVISION_ACTION, true);
      if ($parent_id > 0 && $action === 'delete') $delete_parents[$parent_id] = true;
    }
    foreach ($all as $shift) {
      $shift_id = (int)$shift->ID;
      if (in_array($shift_id, $exclude_shift_ids, true)) continue;
      $status = (string)get_post_status($shift_id);
      if ($status === 'draft' && (string)get_post_meta($shift_id, self::META_REVISION_ACTION, true) === 'delete') continue;
      if ($status === 'publish' && isset($delete_parents[$shift_id])) continue;
      $existing_start = (int)get_post_meta($shift_id, self::META_SHIFT_START_UTC, true);
      $existing_end = (int)get_post_meta($shift_id, self::META_SHIFT_END_UTC, true);
      if ($existing_start < $end_utc && $existing_end > $start_utc) return $shift_id;
    }
    return 0;
  }


  private function is_schedulable_user(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (!get_userdata($user_id)) return false;
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) return false;
    return true;
  }

  private function default_job_path_for_user(int $user_id): int {
    if ($user_id <= 0) return 0;
    if (class_exists('EUM_Profiles')) {
      $term_id = (int)EUM_Profiles::get_field_raw_value($user_id, 'tax:eum_job_path');
      if ($term_id > 0) return $term_id;
    }
    $terms = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    return !empty($terms) ? (int)$terms[0] : 0;
  }

  private function default_task_label_for_user(int $user_id, int $job_path_term_id = 0): string {
    if ($user_id <= 0) return '';
    if ($job_path_term_id <= 0) $job_path_term_id = $this->default_job_path_for_user($user_id);
    $job_title_value = class_exists('EUM_Profiles') ? (string)EUM_Profiles::get_field_raw_value($user_id, 'custom:job_title') : '';
    $job_title_label = class_exists('EUM_Profiles') ? (string)EUM_Profiles::job_title_label_from_value($job_title_value) : '';
    if ($job_title_label !== '') return $job_title_label;
    $tasks = $job_path_term_id > 0 ? (class_exists('EUM_Taxonomies') ? EUM_Taxonomies::effective_schedule_task_options_for_term($job_path_term_id) : []) : [];
    return !empty($tasks) ? (string)$tasks[0] : '';
  }

  private function schedule_default_assignment_map(array $user_ids): array {
    $out = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $user_ids)))) as $user_id) {
      if (!$this->is_schedulable_user($user_id)) continue;
      $path_id = $this->default_job_path_for_user($user_id);
      $out[(string)$user_id] = [
        'path_id' => $path_id,
        'task_label' => $this->default_task_label_for_user($user_id, $path_id),
      ];
    }
    return $out;
  }

  private function schedule_path_tree_items(array $term_ids): array {
    $out = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $term_ids), fn($id) => $id > 0))) as $term_id) {
      $term = get_term($term_id, 'eum_job_path');
      if (!$term || is_wp_error($term)) continue;
      $out[] = [
        'id' => (int)$term->term_id,
        'parent' => (int)$term->parent,
        'name' => (string)$term->name,
        'label' => $this->job_path_label((int)$term->term_id),
      ];
    }
    return $out;
  }

  private function schedule_path_tree_roots(array $items): array {
    $map = [];
    foreach ($items as $item) {
      if (!is_array($item)) continue;
      $map[(int)($item['id'] ?? 0)] = true;
    }
    $roots = [];
    foreach ($items as $item) {
      $id = (int)($item['id'] ?? 0);
      $parent = (int)($item['parent'] ?? 0);
      if ($id <= 0) continue;
      if ($parent <= 0 || empty($map[$parent])) $roots[] = $id;
    }
    return array_values(array_unique($roots));
  }

  private function schedule_candidate_user_ids(int $actor_user_id, int $range_start_utc, int $range_end_utc): array {
    if (EUM_Access::is_it_or_hr($actor_user_id)) {
      $ids = get_users(['fields' => 'ID']);
      return array_values(array_unique(array_filter(array_map('intval', (array)$ids), fn($uid) => $uid > 0 && $this->is_schedulable_user((int)$uid))));
    }

    $ids = [$actor_user_id];
    foreach ((array)get_users(['fields' => 'ID']) as $user_id) {
      $user_id = (int)$user_id;
      if ($user_id <= 0 || !$this->is_schedulable_user($user_id)) continue;
      if ($user_id === $actor_user_id) {
        $ids[] = $user_id;
        continue;
      }
      if ($this->can_manage_user_schedule_fully($actor_user_id, $user_id) || $this->actor_has_user_on_schedule($actor_user_id, $user_id, $range_start_utc, $range_end_utc)) {
        $ids[] = $user_id;
      }
    }
    return array_values(array_unique(array_filter($ids, fn($uid) => $uid > 0 && $this->is_schedulable_user((int)$uid))));
  }

  private function can_manage_any_schedule(int $actor_user_id): bool {
    if ($actor_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if (class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_anything($actor_user_id)) return true;
    return false;
  }

  private function can_create_shift_for_user(int $actor_user_id, int $target_user_id, array $segments = []): bool {
    if ($actor_user_id <= 0) return false;
    if ($target_user_id < 0) return false;
    if ($target_user_id > 0 && !$this->is_schedulable_user($target_user_id)) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if ($target_user_id > 0 && $actor_user_id === $target_user_id) return true;
    if ($this->can_manage_user_schedule_fully($actor_user_id, $target_user_id)) return true;
    if (empty($this->manager_root_job_terms($actor_user_id))) return false;
    if (empty($segments)) return true;
    foreach ($segments as $segment) {
      if (($segment['kind'] ?? 'work') !== 'work') continue;
      if ($this->actor_owns_job_term($actor_user_id, (int)($segment['job_path_term_id'] ?? 0))) return true;
    }
    return false;
  }

  private function can_manage_user_schedule_fully(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if ($actor_user_id === $target_user_id) return true;
    return class_exists('EUM_Manager_IDs') && EUM_Manager_IDs::user_is_manager_of_user($actor_user_id, $target_user_id);
  }

  private function can_edit_shift(int $actor_user_id, int $shift_id): bool {
    if ($actor_user_id <= 0 || $shift_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    $user_id = (int)get_post_meta($shift_id, self::META_USER_ID, true);
    if ($user_id > 0 && $actor_user_id === $user_id) return true;
    if ($user_id > 0 && $this->can_manage_user_schedule_fully($actor_user_id, $user_id)) return true;
    if ($user_id > 0 && $this->actor_has_user_on_schedule($actor_user_id, $user_id, 0, 0)) return true;
    return $this->shift_has_owned_segment($actor_user_id, $shift_id) || ($user_id === 0 && $this->can_manage_any_schedule($actor_user_id));
  }

  private function actor_has_user_on_schedule(int $actor_user_id, int $target_user_id, int $range_start_utc, int $range_end_utc): bool {
    if ($target_user_id <= 0) return false;
    foreach ($this->get_shifts_for_user($target_user_id, $range_start_utc, $range_end_utc, ['publish', 'draft']) as $shift) {
      if ($this->shift_has_owned_segment($actor_user_id, (int)$shift->ID)) return true;
    }
    return false;
  }

  private function shift_has_owned_segment(int $actor_user_id, int $shift_id): bool {
    foreach ($this->normalized_segments_from_meta($shift_id) as $segment) {
      if (self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work')) !== 'work') continue;
      if ($this->actor_owns_job_term($actor_user_id, (int)($segment['job_path_term_id'] ?? 0))) return true;
    }
    return false;
  }

  private function scheduled_hours_for_segments(array $segments): float {
    $hours = 0.0;
    foreach ($segments as $segment) {
      if (self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work')) === 'break') continue;
      $start_utc = (int)($segment['start_utc'] ?? 0);
      $end_utc = (int)($segment['end_utc'] ?? 0);
      if ($start_utc <= 0 || $end_utc <= $start_utc) continue;
      $hours += ($end_utc - $start_utc) / HOUR_IN_SECONDS;
    }
    return $hours;
  }

  private function actor_owns_job_term(int $actor_user_id, int $term_id): bool {
    if ($term_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    foreach ($this->manager_root_job_terms($actor_user_id) as $root_term_id) {
      if ($term_id === $root_term_id || term_is_ancestor_of($root_term_id, $term_id, 'eum_job_path')) return true;
    }
    return false;
  }

  private function manager_root_job_terms(int $actor_user_id): array {
    if (!class_exists('EUM_Manager_IDs')) return [];
    $posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'fields' => 'ids',
    ]);
    $term_ids = [];
    foreach ((array)$posts as $post_id) {
      $manager_users = EUM_Manager_IDs::manager_users_for_id((int)$post_id);
      if (!in_array($actor_user_id, $manager_users, true)) continue;
      $job_term_id = (int)get_post_meta((int)$post_id, EUM_Manager_IDs::META_JOB_PATH_TERM, true);
      if ($job_term_id > 0) $term_ids[] = $job_term_id;
    }
    return array_values(array_unique($term_ids));
  }

  private function get_or_create_revision_draft(int $published_shift_id, int $actor_user_id): int {
    if ($published_shift_id <= 0) return 0;
    $existing = get_posts([
      'post_type' => self::CPT_SHIFT,
      'post_status' => 'draft',
      'numberposts' => 1,
      'orderby' => 'ID',
      'order' => 'DESC',
      'meta_query' => [
        ['key' => self::META_REVISION_PARENT, 'value' => $published_shift_id],
      ],
      'fields' => 'ids',
    ]);
    if (!empty($existing[0])) {
      $draft_id = (int)$existing[0];
      delete_post_meta($draft_id, self::META_REVISION_ACTION);
      return $draft_id;
    }

    $source = get_post($published_shift_id);
    if (!$source || $source->post_type !== self::CPT_SHIFT) return 0;
    $user_id = (int)get_post_meta($published_shift_id, self::META_USER_ID, true);
    $start_utc = (int)get_post_meta($published_shift_id, self::META_SHIFT_START_UTC, true);
    $inserted = wp_insert_post([
      'post_type' => self::CPT_SHIFT,
      'post_status' => 'draft',
      'post_title' => 'Schedule Draft Revision - User #' . $user_id . ' - ' . gmdate('Y-m-d H:i', $start_utc),
      'post_author' => $actor_user_id,
    ], true);
    if (is_wp_error($inserted) || (int)$inserted <= 0) return 0;
    $draft_id = (int)$inserted;
    update_post_meta($draft_id, self::META_USER_ID, $user_id);
    update_post_meta($draft_id, self::META_SHIFT_START_UTC, $start_utc);
    update_post_meta($draft_id, self::META_SHIFT_END_UTC, (int)get_post_meta($published_shift_id, self::META_SHIFT_END_UTC, true));
    update_post_meta($draft_id, self::META_SEGMENTS, $this->normalized_segments_from_meta($published_shift_id));
    update_post_meta($draft_id, self::META_NOTES, (string)get_post_meta($published_shift_id, self::META_NOTES, true));
    update_post_meta($draft_id, self::META_UPDATED_BY, $actor_user_id);
    update_post_meta($draft_id, self::META_UPDATED_AT, current_time('mysql'));
    update_post_meta($draft_id, self::META_REVISION_PARENT, $published_shift_id);
    delete_post_meta($draft_id, self::META_REVISION_ACTION);
    return $draft_id;
  }

  private function create_delete_revision_draft(int $published_shift_id, int $actor_user_id): int {
    if ($published_shift_id <= 0) return 0;
    $source = get_post($published_shift_id);
    if (!$source || $source->post_type !== self::CPT_SHIFT) return 0;

    $existing = get_posts([
      'post_type' => self::CPT_SHIFT,
      'post_status' => 'draft',
      'numberposts' => -1,
      'orderby' => 'ID',
      'order' => 'DESC',
      'meta_query' => [
        ['key' => self::META_REVISION_PARENT, 'value' => $published_shift_id],
      ],
      'fields' => 'ids',
    ]);

    $user_id = (int)get_post_meta($published_shift_id, self::META_USER_ID, true);
    $start_utc = (int)get_post_meta($published_shift_id, self::META_SHIFT_START_UTC, true);
    $end_utc = (int)get_post_meta($published_shift_id, self::META_SHIFT_END_UTC, true);
    $draft_id = !empty($existing) ? (int)array_shift($existing) : 0;
    foreach ((array)$existing as $extra_id) {
      wp_delete_post((int)$extra_id, true);
    }

    if ($draft_id <= 0) {
      $inserted = wp_insert_post([
        'post_type' => self::CPT_SHIFT,
        'post_status' => 'draft',
        'post_title' => 'Schedule Draft Delete - User #' . $user_id . ' - ' . gmdate('Y-m-d H:i', $start_utc),
        'post_author' => $actor_user_id,
      ], true);
      if (is_wp_error($inserted) || (int)$inserted <= 0) return 0;
      $draft_id = (int)$inserted;
    } else {
      wp_update_post([
        'ID' => $draft_id,
        'post_status' => 'draft',
        'post_title' => 'Schedule Draft Delete - User #' . $user_id . ' - ' . gmdate('Y-m-d H:i', $start_utc),
      ]);
    }

    update_post_meta($draft_id, self::META_USER_ID, $user_id);
    update_post_meta($draft_id, self::META_SHIFT_START_UTC, $start_utc);
    update_post_meta($draft_id, self::META_SHIFT_END_UTC, $end_utc);
    update_post_meta($draft_id, self::META_SEGMENTS, []);
    update_post_meta($draft_id, self::META_NOTES, '');
    update_post_meta($draft_id, self::META_UPDATED_BY, $actor_user_id);
    update_post_meta($draft_id, self::META_UPDATED_AT, current_time('mysql'));
    update_post_meta($draft_id, self::META_REVISION_PARENT, $published_shift_id);
    update_post_meta($draft_id, self::META_REVISION_ACTION, 'delete');
    return $draft_id;
  }

  private function clear_user_week_to_drafts(int $actor_user_id, int $target_user_id, int $range_start_utc, int $range_end_utc): array {
    $all = $this->get_shifts_for_user($target_user_id, $range_start_utc, $range_end_utc, ['publish', 'draft']);
    $published_ids = [];
    $draft_children = [];
    $drafts_deleted = 0;
    $delete_markers = 0;

    foreach ($all as $shift) {
      $shift_id = (int)$shift->ID;
      $status = (string)get_post_status($shift_id);
      if ($status === 'draft') {
        $parent_id = (int)get_post_meta($shift_id, self::META_REVISION_PARENT, true);
        if ($parent_id > 0) {
          $draft_children[$parent_id][] = $shift_id;
        } else {
          wp_delete_post($shift_id, true);
          $drafts_deleted++;
        }
        continue;
      }
      $published_ids[] = $shift_id;
    }

    foreach ($published_ids as $published_id) {
      $child_ids = $draft_children[$published_id] ?? [];
      $has_delete_marker = false;
      foreach ($child_ids as $child_id) {
        $action = (string)get_post_meta($child_id, self::META_REVISION_ACTION, true);
        if ($action === 'delete') {
          $has_delete_marker = true;
          continue;
        }
        wp_delete_post($child_id, true);
        $drafts_deleted++;
      }
      if (!$has_delete_marker && $this->create_delete_revision_draft($published_id, $actor_user_id) > 0) {
        $delete_markers++;
      }
    }

    return [
      'drafts_deleted' => $drafts_deleted,
      'delete_markers' => $delete_markers,
    ];
  }

  private function template_day_label(int $day_offset): string {
    $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $index = (($day_offset % 7) + 7) % 7;
    return $days[$index] ?? ('Day ' . $day_offset);
  }

  private function format_template_minutes(int $minutes): string {
    $minutes = max(0, min((24 * 60) - 1, $minutes));
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    $suffix = $hours >= 12 ? 'PM' : 'AM';
    $hour12 = $hours % 12;
    if ($hour12 === 0) $hour12 = 12;
    return sprintf('%d:%02d %s', $hour12, $mins, $suffix);
  }

  private function render_user_template_preview(int $user_id): string {
    $template = $this->saved_week_template_for_user($user_id);
    ob_start();
    echo '<details class="eum-scheduler-user__template">';
    echo '<summary class="button button-small">View Template</summary>';
    echo '<div class="eum-scheduler-user__templatebody">';
    if (empty($template['shifts'])) {
      echo '<div class="eum-scheduler-user__templaterow is-empty">No saved template.</div>';
    } else {
      foreach ((array)$template['shifts'] as $shift_template) {
        $segments = (array)($shift_template['segments'] ?? []);
        if (empty($segments)) continue;
        $first = $segments[0];
        $last = $segments[count($segments) - 1];
        $range = $this->template_day_label((int)($first['start_day_offset'] ?? 0)) . ' ' . $this->format_template_minutes((int)($first['start_minutes'] ?? 0));
        $range .= ' → ' . $this->template_day_label((int)($last['end_day_offset'] ?? 0)) . ' ' . $this->format_template_minutes((int)($last['end_minutes'] ?? 0));
        echo '<div class="eum-scheduler-user__templaterow">';
        echo '<div class="eum-scheduler-user__templaterange">' . esc_html($range) . '</div>';
        foreach ($segments as $segment) {
          $kind_value = self::normalize_segment_kind_value((string)($segment['kind'] ?? 'work'));
          $kind = self::segment_kind_label($kind_value);
          $line = $kind . ': ';
          $line .= $this->template_day_label((int)($segment['start_day_offset'] ?? 0)) . ' ' . $this->format_template_minutes((int)($segment['start_minutes'] ?? 0));
          $line .= ' → ' . $this->template_day_label((int)($segment['end_day_offset'] ?? 0)) . ' ' . $this->format_template_minutes((int)($segment['end_minutes'] ?? 0));
          if ($kind === 'Work') {
            $path_label = $this->job_path_label((int)($segment['job_path_term_id'] ?? 0));
            $task_label = sanitize_text_field((string)($segment['task_label'] ?? ''));
            if ($path_label || $task_label) {
              $line .= ' · ' . trim($path_label . ($task_label ? ' / ' . $task_label : ''), ' /');
            }
          }
          if ($kind === 'Work') {
            $billing_label = $this->cost_center_label((int)($segment['cost_center_term_id'] ?? 0));
            if ($billing_label !== '') $line .= ' -> ' . $billing_label;
          }
          echo '<div class="eum-scheduler-user__templatesegment">' . esc_html($line) . '</div>';
        }
        $notes = sanitize_textarea_field((string)($shift_template['notes'] ?? ''));
        if ($notes !== '') echo '<div class="eum-scheduler-user__templatenotes">' . esc_html($notes) . '</div>';
        echo '</div>';
      }
    }
    echo '</div>';
    echo '</details>';
    return ob_get_clean();
  }

  private function build_week_template_from_shifts(int $user_id, array $shifts, int $range_start_utc, int $range_end_utc): array {
    $tz = $this->user_timezone($user_id);
    $week_start_local = new DateTime('@' . $range_start_utc);
    $week_start_local->setTimezone($tz);
    $week_start_local->setTime(0, 0, 0);

    $template = [
      'version' => 1,
      'saved_at' => current_time('mysql'),
      'week_start' => $week_start_local->format('Y-m-d'),
      'shifts' => [],
    ];

    foreach ($shifts as $shift) {
      $segments_out = [];
      foreach ($this->normalized_segments_from_meta((int)$shift->ID) as $segment) {
        $start_utc = (int)$segment['start_utc'];
        $end_utc = (int)$segment['end_utc'];
        if ($end_utc <= $range_start_utc || $start_utc >= $range_end_utc) continue;

        $start_local = new DateTime('@' . $start_utc);
        $start_local->setTimezone($tz);
        $end_local = new DateTime('@' . $end_utc);
        $end_local->setTimezone($tz);

        $start_day = clone $start_local;
        $start_day->setTime(0, 0, 0);
        $end_day = clone $end_local;
        $end_day->setTime(0, 0, 0);

        $segments_out[] = [
          'kind' => (string)($segment['kind'] ?? 'work'),
          'start_day_offset' => (int)$week_start_local->diff($start_day)->format('%r%a'),
          'start_minutes' => ((int)$start_local->format('G') * 60) + (int)$start_local->format('i'),
          'end_day_offset' => (int)$week_start_local->diff($end_day)->format('%r%a'),
          'end_minutes' => ((int)$end_local->format('G') * 60) + (int)$end_local->format('i'),
          'job_path_term_id' => (int)($segment['job_path_term_id'] ?? 0),
          'task_label' => (string)($segment['task_label'] ?? ''),
          'cost_center_term_id' => (int)($segment['cost_center_term_id'] ?? 0),
        ];
      }
      if (empty($segments_out)) continue;
      $template['shifts'][] = [
        'notes' => (string)get_post_meta((int)$shift->ID, self::META_NOTES, true),
        'segments' => $segments_out,
      ];
    }

    return $template;
  }

  private function template_local_datetime_to_utc(DateTime $week_start_local, int $day_offset, int $minutes, DateTimeZone $tz): int {
    $dt = clone $week_start_local;
    if ($day_offset !== 0) $dt->modify(($day_offset > 0 ? '+' : '') . $day_offset . ' days');
    $hours = (int)floor($minutes / 60);
    $mins = max(0, $minutes % 60);
    $dt->setTime($hours, $mins, 0);
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->getTimestamp();
  }

  private function apply_week_template_to_user(int $user_id, array $template, int $target_range_start_utc, int $actor_user_id): int {
    if ($user_id <= 0 || empty($template['shifts']) || !$this->is_schedulable_user($user_id)) return 0;
    $tz = $this->user_timezone($user_id);
    $week_start_local = new DateTime('@' . $target_range_start_utc);
    $week_start_local->setTimezone($tz);
    $week_start_local->setTime(0, 0, 0);
    $created = 0;

    foreach ((array)$template['shifts'] as $shift_template) {
      $segments = [];
      foreach ((array)($shift_template['segments'] ?? []) as $segment_template) {
        $start_utc = $this->template_local_datetime_to_utc($week_start_local, (int)($segment_template['start_day_offset'] ?? 0), (int)($segment_template['start_minutes'] ?? 0), $tz);
        $end_utc = $this->template_local_datetime_to_utc($week_start_local, (int)($segment_template['end_day_offset'] ?? 0), (int)($segment_template['end_minutes'] ?? 0), $tz);
        if ($start_utc <= 0 || $end_utc <= $start_utc) continue;
        $kind = self::normalize_segment_kind_value((string)($segment_template['kind'] ?? 'work'));
        $segments[] = [
          'kind' => $kind,
          'start_utc' => $start_utc,
          'end_utc' => $end_utc,
          'job_path_term_id' => (int)($segment_template['job_path_term_id'] ?? 0),
          'task_label' => sanitize_text_field((string)($segment_template['task_label'] ?? '')),
          'cost_center_term_id' => (int)($segment_template['cost_center_term_id'] ?? 0),
        ];
      }
      if (empty($segments)) continue;
      $result = $this->upsert_shift(0, $user_id, $segments, sanitize_textarea_field((string)($shift_template['notes'] ?? '')), 'draft', $actor_user_id);
      if (!is_wp_error($result) && (int)$result > 0) $created++;
    }

    return $created;
  }

  private function saved_week_template_for_user(int $user_id): array {
    $template = get_user_meta($user_id, self::USER_META_WEEK_TEMPLATE, true);
    return is_array($template) && !empty($template['shifts']) ? $template : [];
  }

  private function week_range_for_user_offset(int $user_id, int $week_offset = 0): array {
    $tz = $this->user_timezone($user_id);
    $now = new DateTime('now', $tz);
    $week_start = clone $now;
    $week_start->setTime(0, 0, 0);
    $days_since_sunday = (int)$week_start->format('w');
    if ($days_since_sunday > 0) $week_start->modify('-' . $days_since_sunday . ' days');
    if ($week_offset !== 0) $week_start->modify(($week_offset > 0 ? '+' : '') . $week_offset . ' weeks');
    $week_end = clone $week_start;
    $week_end->modify('+7 days');
    return [$week_start->getTimestamp(), $week_end->getTimestamp()];
  }

  private function apply_default_templates_for_week_offsets(array $week_offsets): int {
    $created = 0;
    $week_offsets = array_values(array_unique(array_map('intval', $week_offsets)));
    $user_ids = get_users([
      'fields' => 'ids',
      'meta_key' => self::USER_META_WEEK_TEMPLATE,
    ]);
    foreach ((array)$user_ids as $user_id) {
      $user_id = (int)$user_id;
      if ($user_id <= 0 || !$this->is_schedulable_user($user_id)) continue;
      $template = $this->saved_week_template_for_user($user_id);
      if (empty($template['shifts'])) continue;
      foreach ($week_offsets as $week_offset) {
        [$range_start_utc, $range_end_utc] = $this->week_range_for_user_offset($user_id, $week_offset);
        $existing = $this->get_visible_shifts_for_user($user_id, $user_id, $range_start_utc, $range_end_utc, 'manage', 'all');
        if (!empty($existing)) continue;
        $created += $this->apply_week_template_to_user($user_id, $template, $range_start_utc, 0);
      }
    }
    return $created;
  }

  private function publish_draft_shift(int $draft_shift_id): bool {
    if ($draft_shift_id <= 0 || get_post_status($draft_shift_id) !== 'draft') return false;
    $parent_id = (int)get_post_meta($draft_shift_id, self::META_REVISION_PARENT, true);
    $revision_action = (string)get_post_meta($draft_shift_id, self::META_REVISION_ACTION, true);
    if ($parent_id > 0 && $revision_action === 'delete') {
      $this->delete_shift_and_related_drafts($parent_id);
      $this->delete_shift_and_related_drafts($draft_shift_id, true);
      return true;
    }
    if ($parent_id > 0) {
      $parent = get_post($parent_id);
      if (!$parent || $parent->post_type !== self::CPT_SHIFT) return false;
      $user_id = (int)get_post_meta($draft_shift_id, self::META_USER_ID, true);
      $start_utc = (int)get_post_meta($draft_shift_id, self::META_SHIFT_START_UTC, true);
      wp_update_post([
        'ID' => $parent_id,
        'post_status' => 'publish',
        'post_title' => 'Schedule Shift - User #' . $user_id . ' - ' . gmdate('Y-m-d H:i', $start_utc),
      ]);
      update_post_meta($parent_id, self::META_USER_ID, $user_id);
      update_post_meta($parent_id, self::META_SHIFT_START_UTC, $start_utc);
      update_post_meta($parent_id, self::META_SHIFT_END_UTC, (int)get_post_meta($draft_shift_id, self::META_SHIFT_END_UTC, true));
      update_post_meta($parent_id, self::META_SEGMENTS, $this->normalized_segments_from_meta($draft_shift_id));
      update_post_meta($parent_id, self::META_NOTES, (string)get_post_meta($draft_shift_id, self::META_NOTES, true));
      update_post_meta($parent_id, self::META_UPDATED_BY, (int)get_post_meta($draft_shift_id, self::META_UPDATED_BY, true));
      update_post_meta($parent_id, self::META_UPDATED_AT, current_time('mysql'));
      delete_post_meta($parent_id, self::META_REVISION_ACTION);
      $this->delete_shift_and_related_drafts($draft_shift_id, true);
      return true;
    }

    wp_update_post(['ID' => $draft_shift_id, 'post_status' => 'publish']);
    delete_post_meta($draft_shift_id, self::META_REVISION_PARENT);
    delete_post_meta($draft_shift_id, self::META_REVISION_ACTION);
    return true;
  }

  private function delete_shift_and_related_drafts(int $shift_id, bool $delete_only_current = false): void {
    if ($shift_id <= 0) return;
    $related = [];
    $parent_id = (int)get_post_meta($shift_id, self::META_REVISION_PARENT, true);
    if (!$delete_only_current && $parent_id <= 0) {
      $related = get_posts([
        'post_type' => self::CPT_SHIFT,
        'post_status' => 'draft',
        'numberposts' => -1,
        'fields' => 'ids',
        'meta_query' => [
          ['key' => self::META_REVISION_PARENT, 'value' => $shift_id],
        ],
      ]);
    }
    wp_delete_post($shift_id, true);
    if (!$delete_only_current) {
      foreach ((array)$related as $draft_id) {
        wp_delete_post((int)$draft_id, true);
      }
    }
  }

  private function publish_draft_shifts_for_actor(int $actor_user_id, int $target_user_id = 0, int $range_start_utc = 0, int $range_end_utc = 0): int {
    $published = 0;
    foreach ((array)get_posts([
      'post_type' => self::CPT_SHIFT,
      'post_status' => 'draft',
      'numberposts' => -1,
      'orderby' => 'meta_value_num',
      'meta_key' => self::META_SHIFT_START_UTC,
      'order' => 'ASC',
    ]) as $shift) {
      $shift_id = (int)$shift->ID;
      $user_id = (int)get_post_meta($shift_id, self::META_USER_ID, true);
      if ($target_user_id > 0 && $user_id !== $target_user_id) continue;
      $start_utc = (int)get_post_meta($shift_id, self::META_SHIFT_START_UTC, true);
      $end_utc = (int)get_post_meta($shift_id, self::META_SHIFT_END_UTC, true);
      if ($range_end_utc > 0 && $start_utc >= $range_end_utc) continue;
      if ($range_start_utc > 0 && $end_utc <= $range_start_utc) continue;
      if ($actor_user_id > 0 && !$this->can_edit_shift($actor_user_id, $shift_id)) continue;
      if ($this->publish_draft_shift($shift_id)) $published++;
    }
    return $published;
  }

  public function ensure_auto_publish_schedule(): void {
    if (!wp_next_scheduled('eum_schedule_auto_publish_next_week')) {
      wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'eum_schedule_auto_publish_next_week');
    }
  }

  public function handle_auto_publish_next_week(): void {
    $this->maybe_apply_template_catchup_for_current_and_next_week();
    $this->maybe_apply_next_week_templates_on_monday();
    $this->maybe_auto_publish_next_week_on_thursday();
  }

  public function maybe_apply_template_catchup_for_current_and_next_week(): int {
    $context = $this->automation_calendar_context();
    $day_code = (string)$context['day_code'];
    $catchup_marker = (string)get_option('eum_schedule_template_auto_catchup_last_run', '');
    if ($catchup_marker === $day_code) return 0;
    $offsets = [0];
    if ((int)$context['now']->format('N') !== 7) $offsets[] = 1;
    $created = $this->apply_default_templates_for_week_offsets($offsets);
    update_option('eum_schedule_template_auto_catchup_last_run', $day_code, false);
    return $created;
  }

  public function maybe_apply_next_week_templates_on_monday(): int {
    $context = $this->automation_calendar_context();
    $day_number = (int)$context['now']->format('N');
    if ($day_number === 7) return 0;
    $week_code = (string)$context['next_week_code'];
    $marker = (string)get_option('eum_schedule_template_next_week_last_run', '');
    if ($marker === $week_code) return 0;
    $created = $this->apply_default_templates_for_week_offsets([1]);
    update_option('eum_schedule_template_next_week_last_run', $week_code, false);
    return $created;
  }

  public function maybe_auto_publish_next_week_on_thursday(int $actor_user_id = 0): int {
    $context = $this->automation_calendar_context();
    if ((int)$context['now']->format('N') < 4) return 0;
    $week_code = (string)$context['next_week_code'];
    $marker = (string)get_option('eum_schedule_auto_publish_last_run', '');
    if ($marker === $week_code) return 0;

    $range_start_utc = (int)$context['next_week_start']->getTimestamp();
    $range_end_utc = (int)$context['next_week_end']->getTimestamp();
    $published = $this->publish_draft_shifts_for_actor($actor_user_id, 0, $range_start_utc, $range_end_utc);
    update_option('eum_schedule_auto_publish_last_run', $week_code, false);

    if ($published > 0) {
      do_action('eum_schedule_drafts_published', [
        'actor_user_id' => $actor_user_id,
        'target_user_id' => 0,
        'range_start_utc' => $range_start_utc,
        'range_end_utc' => $range_end_utc,
        'published_count' => $published,
        'user_ids' => $this->published_user_ids_in_range($range_start_utc, $range_end_utc),
        'source' => 'automation',
      ]);
    }

    return $published;
  }

  private function automation_calendar_context(): array {
    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $today = clone $now;
    $today->setTime(0, 0, 0);
    $current_week_start = clone $today;
    $days_since_sunday = (int)$current_week_start->format('w');
    if ($days_since_sunday > 0) $current_week_start->modify('-' . $days_since_sunday . ' days');
    $next_week_start = clone $current_week_start;
    $next_week_start->modify('+1 week');
    $next_week_end = clone $next_week_start;
    $next_week_end->modify('+7 days');

    return [
      'now' => $now,
      'day_code' => $today->format('Ymd'),
      'current_week_start' => $current_week_start,
      'next_week_start' => $next_week_start,
      'next_week_end' => $next_week_end,
      'next_week_code' => $next_week_start->format('Ymd'),
    ];
  }

  private function published_user_ids_in_range(int $range_start_utc, int $range_end_utc): array {
    if ($range_end_utc <= $range_start_utc) return [];
    $posts = get_posts([
      'post_type' => self::CPT_SHIFT,
      'post_status' => 'publish',
      'numberposts' => -1,
      'fields' => 'ids',
      'meta_query' => [
        'relation' => 'AND',
        ['key' => self::META_SHIFT_START_UTC, 'value' => $range_end_utc, 'compare' => '<', 'type' => 'NUMERIC'],
        ['key' => self::META_SHIFT_END_UTC, 'value' => $range_start_utc, 'compare' => '>', 'type' => 'NUMERIC'],
      ],
    ]);

    $user_ids = [];
    foreach ((array)$posts as $post_id) {
      $user_id = (int)get_post_meta((int)$post_id, self::META_USER_ID, true);
      if ($user_id > 0) $user_ids[] = $user_id;
    }

    return array_values(array_unique(array_filter(array_map('intval', $user_ids), fn($user_id) => $user_id > 0)));
  }

  private function condensed_job_path_label(int $term_id, int $keep = 3): string {
    $full = $this->job_path_label($term_id);
    if ($full === '' || $full === 'Unassigned Path') return $full;
    $parts = array_values(array_filter(array_map('trim', preg_split('/\s*\/\s*/', $full) ?: []), static fn($item) => $item !== ''));
    if (count($parts) <= $keep) return implode(' / ', $parts);
    return '.../ ' . implode(' / ', array_slice($parts, -1 * $keep));
  }

  private function job_path_options_for_form(): array {
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'number' => 0]);
    if (is_wp_error($terms) || empty($terms)) return [];
    $out = [];
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      $out[$term_id] = $this->job_path_label($term_id);
    }
    natcasesort($out);
    return $out;
  }

  private function cost_center_options_for_form(): array {
    if (!class_exists('EUM_Finance')) return [];
    return EUM_Finance::labor_paycode_options();
  }

  private function task_options_map_for_paths(array $term_ids): array {
    $out = [];
    foreach ($term_ids as $term_id) {
      $term_id = (int)$term_id;
      if ($term_id <= 0) continue;
      $out[$term_id] = class_exists('EUM_Taxonomies') ? EUM_Taxonomies::effective_schedule_task_options_for_term($term_id) : [];
    }
    return $out;
  }

  private function job_path_label(int $term_id): string {
    if ($term_id <= 0) return 'Unassigned Path';
    if (class_exists('EUM_Profiles')) return EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ');
    $term = get_term($term_id, 'eum_job_path');
    return ($term && !is_wp_error($term)) ? (string)$term->name : ('Job Path #' . $term_id);
  }

  private function cost_center_label(int $term_id): string {
    if ($term_id <= 0) return '';
    if (class_exists('EUM_Finance') && method_exists('EUM_Finance', 'labor_paycode_option_label')) {
      return (string)EUM_Finance::labor_paycode_option_label($term_id);
    }
    if (class_exists('EUM_Finance') && method_exists('EUM_Finance', 'finance_path_option_label')) {
      return (string)EUM_Finance::finance_path_option_label($term_id);
    }
    return '';
  }

  private function user_timezone(int $user_id): DateTimeZone {
    $tz_string = '';
    if ($user_id > 0) $tz_string = (string)get_user_meta($user_id, 'timezone', true);
    if ($tz_string === '') $tz_string = (string)get_option('timezone_string', '');
    if ($tz_string === '') $tz_string = self::DEFAULT_TIMEZONE;
    try {
      return new DateTimeZone($tz_string);
    } catch (Exception $e) {
      return new DateTimeZone(self::DEFAULT_TIMEZONE);
    }
  }

  private function user_timezone_label(int $user_id): string {
    return $this->user_timezone($user_id)->getName();
  }

  private function local_input_to_utc(string $value, DateTimeZone $timezone): int {
    $value = trim($value);
    if ($value === '') return 0;
    try {
      $dt = new DateTime($value, $timezone);
      $dt->setTimezone(new DateTimeZone('UTC'));
      return $dt->getTimestamp();
    } catch (Exception $e) {
      return 0;
    }
  }

  private function format_local_input(int $timestamp, DateTimeZone $timezone): string {
    if ($timestamp <= 0) return '';
    $dt = new DateTime('@' . $timestamp);
    $dt->setTimezone($timezone);
    return $dt->format('Y-m-d\TH:i');
  }

  private function format_local_ts(int $timestamp, DateTimeZone $timezone): string {
    if ($timestamp <= 0) return '';
    $dt = new DateTime('@' . $timestamp);
    $dt->setTimezone($timezone);
    return $dt->format('M j, g:i A');
  }

  private function week_key_for_utc(int $user_id, int $timestamp): string {
    $tz = $this->user_timezone($user_id);
    $dt = new DateTime('@' . $timestamp);
    $dt->setTimezone($tz);
    $dt->modify('Sunday this week')->setTime(0, 0, 0);
    return $dt->format('Y-m-d');
  }

  private function money(float $amount): string {
    return '$' . number_format($amount, 2);
  }

  private function inline_styles(): string {
    return <<<'CSS'
.eum-schedule{display:grid;gap:16px}.eum-schedule__topbar,.eum-schedule__controls,.eum-schedule__rangebar,.eum-schedule__summaryrow{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap}.eum-schedule__controls{padding:12px;border:1px solid #d7dde6;border-radius:14px;background:#fff}.eum-schedule__controls label{display:flex;flex-direction:column;gap:4px;min-width:170px}.eum-schedule__controls span,.eum-schedule-editor label span{font-size:12px;font-weight:600;color:#455469}.eum-schedule__tabbar{display:flex;gap:8px;flex-wrap:wrap}.eum-schedule__tab{display:inline-flex;padding:8px 12px;border:1px solid #cfd6e0;border-radius:999px;text-decoration:none}.eum-schedule__tab.is-active{background:#1d2327;color:#fff;border-color:#1d2327}.eum-schedule__main{display:block}.eum-schedule-grid{display:grid;gap:12px}.eum-schedule-grid--week,.eum-schedule-grid--calendar{grid-template-columns:repeat(7,minmax(0,1fr))}.eum-schedule-day,.eum-schedule-list__day,.eum-schedule-panel,.eum-scheduler-board{background:#fff;border:1px solid #d7dde6;border-radius:14px;padding:12px;box-shadow:0 1px 2px rgba(15,23,42,.04)}.eum-schedule-day--calendar{min-height:170px}.eum-schedule-day.is-outside{opacity:.7}.eum-schedule-day__head,.eum-schedule-list__header{display:flex;justify-content:space-between;gap:8px;align-items:center;padding-bottom:8px;margin-bottom:10px;border-bottom:1px solid #edf1f5;cursor:context-menu}.eum-schedule-list{display:grid;gap:12px}.eum-schedule-shift{border:1px solid #e4e8ef;border-radius:12px;padding:10px;margin-bottom:8px;background:#fbfcfe;cursor:context-menu}.eum-schedule-shift__head{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:6px}.eum-schedule-shift__segments{display:grid;gap:4px}.eum-schedule-shift__segment,.eum-schedule-shift__notes{font-size:12px;color:#465468}.eum-schedule-badge{display:inline-flex;margin-left:8px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}.eum-schedule-badge--publish{background:#e8f7ed;color:#0f6a2d}.eum-schedule-badge--draft{background:#fff3d6;color:#8a5b00}.eum-schedule-shift__actions{display:flex;justify-content:space-between;align-items:center;margin-top:8px}.eum-schedule-empty{color:#7a8697;font-size:13px;padding:8px 0}.eum-schedule-summary{flex:1 1 160px;background:#fff;border:1px solid #d7dde6;border-radius:14px;padding:14px}.eum-schedule-summary__value{font-size:22px;font-weight:700;line-height:1.1}.eum-schedule-summary__label{margin-top:6px;color:#596579}.eum-schedule-editor{display:grid;gap:16px;margin-top:12px}.eum-schedule-editor__grid{display:grid;gap:12px}.eum-schedule-editor__grid--popup{grid-template-columns:minmax(260px,320px) minmax(0,1fr)}.eum-schedule-editor__notes textarea{min-height:88px}.eum-schedule-editor__segments{display:grid;gap:10px}.eum-schedule-editor__segments-head{display:grid;gap:4px}.eum-schedule-editor__buttons,.eum-schedule-editor__submit,.eum-schedule-panel__actions,.eum-schedule-editor__clipboard{display:flex;gap:8px;flex-wrap:wrap}.eum-schedule-editor__clipboard{align-items:center;justify-content:space-between}.eum-schedule-segment-row{display:grid;grid-template-columns:120px minmax(0,1fr) minmax(0,1fr) minmax(280px,1.35fr) minmax(220px,.85fr) 28px;gap:8px;align-items:start;padding:12px;border:1px solid #e4e8ef;border-radius:12px;background:#fcfdff}.eum-schedule-segment-row label,.eum-segment-path-task-group{display:grid;gap:4px}.eum-schedule-segment-row.is-break .eum-segment-work-field,.eum-schedule-segment-row.is-vacation .eum-segment-work-field{opacity:.55}.eum-schedule-segment-row input,.eum-schedule-segment-row select,.eum-schedule-editor textarea,.eum-schedule__controls input,.eum-schedule__controls select{width:100%}.eum-segment-select-fallback{display:none!important}.eum-schedule-path-task-picker{display:block;border:1px solid #d7dde6;border-radius:12px;background:#fff;overflow:hidden}.eum-schedule-path-task-picker__toolbar{display:flex;align-items:center;gap:10px;background:#111827;color:#fff;padding:10px 12px}.eum-schedule-path-task-picker__back{display:inline-flex;align-items:center;justify-content:center;border:0;background:#374151;color:#fff;border-radius:8px;width:28px;height:28px;padding:0;cursor:pointer}.eum-schedule-path-task-picker__back[disabled]{opacity:.45;cursor:not-allowed}.eum-schedule-path-task-picker__meta strong{display:block;font-size:11px;color:#d1d5db;text-transform:uppercase;letter-spacing:.03em}.eum-schedule-path-task-picker__path{display:block;font-size:13px;margin-top:2px;word-break:break-word}.eum-schedule-path-task-picker__panel{max-height:220px;overflow:auto}.eum-schedule-path-task-picker__section{padding:10px 14px 4px;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;background:#f9fafb;border-top:1px solid #f0f0f1}.eum-schedule-path-task-picker__section:first-child{border-top:0}.eum-schedule-path-task-picker__row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-top:1px solid #f0f0f1}.eum-schedule-path-task-picker__row:first-of-type{border-top:0}.eum-schedule-path-task-picker__name{font-weight:600}.eum-schedule-path-task-picker__hint{color:#6b7280;font-size:12px;margin-top:2px}.eum-schedule-path-task-picker__badge{display:inline-flex;align-items:center;gap:6px;margin-left:8px;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:700}.eum-schedule-path-task-picker__badge--path{background:#eff6ff;color:#1d4ed8}.eum-schedule-path-task-picker__go{display:inline-flex;align-items:center;justify-content:center;flex:0 0 32px;border:0;background:#2563eb;color:#fff;border-radius:999px;width:32px;min-width:32px;height:32px;padding:0;cursor:pointer;font-size:18px;line-height:1}.eum-schedule-path-task-picker__empty{padding:14px;color:#50575e}.eum-schedule-path-task-picker__summary{padding:10px 14px;border-top:1px solid #edf1f5;background:#fbfcfe;color:#475467;font-size:13px}.eum-schedule-path-task-picker.is-break,.eum-schedule-path-task-picker.is-vacation{pointer-events:none;opacity:.65}.eum-schedule-panel--summary{margin-top:18px}.eum-schedule-panel--publish{margin-top:18px}.eum-schedule__bottom-summary{margin-top:18px}.notice{margin:0 0 12px}.eum-schedule__rangebar{margin:12px 0}.eum-schedule__rangeheadline{font-weight:700}.eum-schedule__rangeactions{display:flex;gap:8px;flex-wrap:wrap}.eum-scheduler-board{padding:0;overflow:hidden}.eum-scheduler-board__top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:14px 16px;border-bottom:1px solid #edf1f5;background:#f8fafc}.eum-scheduler-board__legend{display:flex;gap:8px;flex-wrap:wrap}.eum-scheduler-legend{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700}.eum-scheduler-legend--publish{background:#ece8ff;color:#4a2b91}.eum-scheduler-legend--draft{background:#fff1d8;color:#8a5b00}.eum-scheduler-legend--open{background:#e8f5ff;color:#175cd3}.eum-scheduler-board__scroll{overflow:auto;max-width:100%}.eum-scheduler-table{width:100%;min-width:1180px;border-collapse:separate;border-spacing:0;table-layout:fixed}.eum-scheduler-table thead th{position:sticky;top:0;z-index:4;background:#f8fafc;font-size:12px;font-weight:700;color:#344054;text-align:center;padding:10px 8px;border-bottom:1px solid #dce3ed}.eum-scheduler-table thead th:first-child{left:0;z-index:6;text-align:left;padding-left:14px}.eum-scheduler-table th:first-child,.eum-scheduler-table td:first-child{position:sticky;left:0;background:#fff;z-index:3}.eum-scheduler-table tbody th,.eum-scheduler-table tbody td{border-bottom:1px solid #edf1f5;border-right:1px solid #edf1f5;vertical-align:top}.eum-scheduler-table tbody tr.is-selected th:first-child{box-shadow:inset 4px 0 0 #2271b1}.eum-scheduler-table tbody tr.is-open-row th:first-child{box-shadow:inset 4px 0 0 #175cd3;background:#f7fbff}.eum-scheduler-table__dow{display:block;font-size:12px;font-weight:700}.eum-scheduler-table__date{display:block;font-size:12px;color:#667085}.eum-scheduler-user{width:240px;min-width:240px;max-width:240px;padding:12px 14px;text-align:left}.eum-scheduler-user--open .eum-scheduler-user__name{color:#175cd3}.eum-scheduler-user__name{font-weight:700;color:#1d2939}.eum-scheduler-user__meta{margin-top:4px;font-size:12px;color:#667085}.eum-scheduler-user__actions{display:grid;gap:6px;margin-top:10px}.eum-scheduler-user__actionform{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.eum-scheduler-user__actionform input[type=date]{width:100%;min-width:0;max-width:150px}.eum-scheduler-user__actionform .button{margin:0}.eum-scheduler-user__you{display:inline-flex;margin-left:6px;padding:2px 8px;border-radius:999px;background:#eef4ff;color:#175cd3;font-size:11px;vertical-align:middle}.eum-scheduler-user__template{display:block}.eum-scheduler-user__template summary{list-style:none}.eum-scheduler-user__template summary::-webkit-details-marker{display:none}.eum-scheduler-user__templatebody{margin-top:8px;padding:10px;border:1px solid #d7dde6;border-radius:12px;background:#fbfcfe;display:grid;gap:8px;min-width:280px;max-width:520px}.eum-scheduler-user__templaterow{display:grid;gap:4px;padding:8px;border-radius:10px;background:#fff;border:1px solid #e4e8ef}.eum-scheduler-user__templaterow.is-empty{color:#667085}.eum-scheduler-user__templaterange{font-size:12px;font-weight:700;color:#1d2939}.eum-scheduler-user__templatesegment,.eum-scheduler-user__templatenotes{font-size:12px;color:#465468}.eum-scheduler-user__templatenotes{font-style:italic}.eum-scheduler-cell{min-width:0;height:132px;padding:8px;background:#fcfdff;cursor:pointer;overflow:hidden}.eum-scheduler-cell:hover{background:#f4f8ff}.eum-scheduler-cell.is-open-cell{background:#fbfdff}.eum-scheduler-cell.is-active{outline:2px solid #2271b1;outline-offset:-2px;background:#eef6ff}.eum-scheduler-cell__empty{display:flex;align-items:center;justify-content:center;height:100%;min-height:88px;border:1px dashed #d5dde8;border-radius:10px;background:#fff;color:#7a8697;font-size:12px;font-weight:600}.eum-scheduler-cell:hover .eum-scheduler-cell__empty{color:#175cd3;border-color:#a9c7ea;background:#f8fbff}.eum-schedule-planner-shift{display:block;width:100%;margin:0 0 6px;padding:8px 10px;border:0;border-radius:10px;text-align:left;cursor:pointer;box-shadow:inset 0 0 0 1px rgba(17,24,39,.08)}.eum-schedule-planner-shift.is-published{background:linear-gradient(135deg,#efe9ff 0%,#f8f5ff 100%);border-left:4px solid #6f42c1}.eum-schedule-planner-shift.is-draft{background:linear-gradient(135deg,#fff4dc 0%,#fffaf0 100%);border-left:4px solid #c77800}.eum-schedule-planner-shift__time,.eum-schedule-planner-shift__task,.eum-schedule-planner-shift__notes{display:block}.eum-schedule-planner-shift__time{font-size:12px;font-weight:700;color:#1d2939}.eum-schedule-planner-shift__task{margin-top:2px;font-size:12px;color:#344054;white-space:normal;overflow:visible;text-overflow:clip;overflow-wrap:anywhere;word-break:break-word;line-height:1.25}.eum-schedule-planner-shift__notes{margin-top:3px;font-size:11px;color:#667085;white-space:normal;overflow:visible;text-overflow:clip;overflow-wrap:anywhere;word-break:break-word;line-height:1.25}.eum-schedule-modal[hidden]{display:none!important}.eum-schedule-modal{position:fixed;inset:0;z-index:99999}.eum-schedule-modal__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.42)}.eum-schedule-modal__dialog{position:relative;z-index:2;width:min(1120px,calc(100vw - 40px));max-height:calc(100vh - 40px);overflow:auto;margin:20px auto;background:#fff;border-radius:18px;border:1px solid #d7dde6;box-shadow:0 24px 80px rgba(15,23,42,.22);padding:20px}.eum-schedule-modal__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid #edf1f5}.eum-schedule-contextmenu{position:fixed;z-index:100001;min-width:180px;padding:8px;background:#fff;border:1px solid #d7dde6;border-radius:12px;box-shadow:0 16px 40px rgba(15,23,42,.18)}.eum-schedule-contextmenu__item{display:block;width:100%;padding:10px 12px;border:0;background:transparent;text-align:left;border-radius:8px;cursor:pointer}.eum-schedule-contextmenu__item:hover{background:#f3f6fa}.eum-schedule-contextmenu__item[hidden]{display:none}.eum-schedule-contextmenu__item.is-disabled{opacity:.45;cursor:not-allowed}.eum-schedule-contextmenu__item.is-disabled:hover{background:transparent}@media (max-width:1180px){.eum-schedule-editor__grid--popup{grid-template-columns:1fr}.eum-schedule-segment-row{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:782px){.eum-schedule-grid--week,.eum-schedule-grid--calendar{grid-template-columns:repeat(2,minmax(0,1fr))}.eum-schedule-segment-row{grid-template-columns:1fr}.eum-scheduler-board__top,.eum-schedule-modal__head{flex-direction:column}.eum-schedule-modal__dialog{width:min(100vw - 20px,1120px);margin:10px auto;padding:16px}}@media (max-width:680px){.eum-schedule-grid--week,.eum-schedule-grid--calendar{grid-template-columns:1fr}.eum-scheduler-table{min-width:980px}}
CSS;
  }

  private function inline_script(): string {
    return <<<'JS'
(function(){
  var wrap=document.querySelector('[data-eum-schedule-wrap]');
  if(!wrap){return;}
  function parseJsonNode(id,fallback){var node=document.getElementById(id);if(!node){return fallback;}try{return JSON.parse(node.textContent||'');}catch(e){return fallback;}}
  var taskMap=parseJsonNode('eum-schedule-task-map',{}),pathItems=parseJsonNode('eum-schedule-path-tree',[]),pathRoots=parseJsonNode('eum-schedule-path-roots',[]),userDefaults=parseJsonNode('eum-schedule-user-defaults',{});
  var editor=document.getElementById('eum-schedule-editor'),modal=document.getElementById('eum-schedule-modal'),segmentsWrap=document.getElementById('eum-schedule-segments'),template=document.getElementById('eum-schedule-segment-template'),deleteForm=document.getElementById('eum-schedule-delete-form'),deleteButton=document.getElementById('eum-schedule-delete-button'),contextMenu=document.getElementById('eum-schedule-contextmenu'),availabilityWarningNode=document.getElementById('eum-schedule-availability-warning');
  function setAvailabilityWarning(message){if(!availabilityWarningNode){return;}message=String(message||'').trim();availabilityWarningNode.textContent=message;if(message){availabilityWarningNode.style.display='block';}else{availabilityWarningNode.style.display='none';}}
  var copyKey='eumScheduleCopiedShift',activeCell=null,contextState={shiftEl:null,target:null},pathMap={},pathChildren={};
  pathItems.forEach(function(item){if(!item){return;}item.id=parseInt(item.id||'0',10)||0;item.parent=parseInt(item.parent||'0',10)||0;pathMap[String(item.id)]=item;var key=String(item.parent);pathChildren[key]=pathChildren[key]||[];pathChildren[key].push(item);});
  Object.keys(pathChildren).forEach(function(key){pathChildren[key].sort(function(a,b){return String(a.name||'').localeCompare(String(b.name||''),undefined,{sensitivity:'base'});});});
  function segmentRows(){return segmentsWrap?Array.prototype.slice.call(segmentsWrap.querySelectorAll('[data-segment-row]')):[];}
  function getUserDefault(userId){return userDefaults[String(parseInt(userId||'0',10)||0)]||null;}
  function pathTrail(pathId){var out=[],seen={},current=parseInt(pathId||'0',10)||0;while(current>0&&pathMap[String(current)]&&!seen[String(current)]){seen[String(current)]=true;out.unshift(pathMap[String(current)]);current=parseInt(pathMap[String(current)].parent||'0',10)||0;}return out;}
  function taskOptionsForPath(pathId){return taskMap[String(parseInt(pathId||'0',10)||0)]||[];}
  function setTaskOptionsForRow(row,preserveCurrent){if(!row){return;}var kind=row.querySelector('.eum-segment-kind'),path=row.querySelector('.eum-segment-path'),task=row.querySelector('.eum-segment-task'),picker=row.querySelector('.eum-schedule-path-task-picker');var currentTask=task?String(task.value||''):'';var kindValue=kind?String(kind.value||'work'):'work';var isBreak=kindValue==='break';var isVacation=kindValue==='vacation';var isNonWork=kindValue!=='work';var pathId=path?String(path.value||'0'):'0';var opts=isNonWork?[]:taskOptionsForPath(pathId);row.classList.toggle('is-break',isBreak);row.classList.toggle('is-vacation',isVacation);if(isNonWork&&path){path.value='0';}if(task){task.innerHTML='<option value="">— Select Task —</option>';opts.forEach(function(label){var option=document.createElement('option');option.value=label;option.textContent=label;if(preserveCurrent&&label===currentTask){option.selected=true;}task.appendChild(option);});if(!isNonWork&&preserveCurrent&&currentTask&&opts.indexOf(currentTask)===-1){var custom=document.createElement('option');custom.value=currentTask;custom.textContent=currentTask;custom.selected=true;task.appendChild(custom);}if(isNonWork){task.value='';}}if(picker){picker.classList.toggle('is-break',isBreak);picker.classList.toggle('is-vacation',isVacation);picker.dispatchEvent(new CustomEvent('eum:picker-refresh'));}}
  function renderPickerRow(panel,name,hint,kind,clickHandler){var row=document.createElement('div');row.className='eum-schedule-path-task-picker__row';var left=document.createElement('div');var nameEl=document.createElement('div');nameEl.className='eum-schedule-path-task-picker__name';nameEl.textContent=name;var badge=document.createElement('span');badge.className='eum-schedule-path-task-picker__badge '+(kind==='task'?'':'eum-schedule-path-task-picker__badge--path');badge.textContent=kind==='task'?'Task':'Further Path';nameEl.appendChild(badge);left.appendChild(nameEl);var hintEl=document.createElement('div');hintEl.className='eum-schedule-path-task-picker__hint';hintEl.textContent=hint;left.appendChild(hintEl);var button=document.createElement('button');button.type='button';button.className='eum-schedule-path-task-picker__go';button.setAttribute('aria-label',name);button.innerHTML='&#8250;';button.addEventListener('click',clickHandler);row.appendChild(left);row.appendChild(button);panel.appendChild(row);}
  function bindPathTaskPicker(row){if(!row){return;}var picker=row.querySelector('.eum-schedule-path-task-picker');if(!picker||picker.getAttribute('data-bound')==='1'){return;}picker.setAttribute('data-bound','1');var path=document.getElementById(picker.getAttribute('data-path-select')||''),task=document.getElementById(picker.getAttribute('data-task-select')||''),kind=row.querySelector('.eum-segment-kind'),panel=picker.querySelector('.eum-schedule-path-task-picker__panel'),current=picker.querySelector('.eum-schedule-path-task-picker__path'),summary=picker.querySelector('.eum-schedule-path-task-picker__summary'),back=picker.querySelector('.eum-schedule-path-task-picker__back');function render(){if(!panel||!path||!task){return;}var trail=pathTrail(path.value),branch=trail.length?trail[trail.length-1].id:0;panel.innerHTML='';if(kind&&kind.value!=='work'){var label=kind.value==='vacation'?'Vacation':'Break';panel.innerHTML='<div class="eum-schedule-path-task-picker__empty">'+label+' segments do not use Job Path or Task.</div>';if(current){current.textContent='/';}if(summary){summary.textContent=label+' segment';}if(back){back.disabled=true;}return;}var childItems=branch>0?(pathChildren[String(branch)]||[]):pathRoots.map(function(id){return pathMap[String(id)]||null;}).filter(Boolean);if(childItems.length){var section=document.createElement('div');section.className='eum-schedule-path-task-picker__section';section.textContent='Further Paths';panel.appendChild(section);childItems.forEach(function(item){var hasChildren=(pathChildren[String(item.id)]||[]).length>0;renderPickerRow(panel,String(item.name||''),hasChildren?'Select this path and continue deeper':'Select this path','path',function(){path.value=String(item.id);task.value='';setTaskOptionsForRow(row,false);});});}var tasks=branch>0?taskOptionsForPath(branch):[];if(tasks.length){var section2=document.createElement('div');section2.className='eum-schedule-path-task-picker__section';section2.textContent='Tasks';panel.appendChild(section2);tasks.forEach(function(label){renderPickerRow(panel,String(label||''),'Use this task on the current path','task',function(){path.value=String(branch);setTaskOptionsForRow(row,false);task.value=String(label||'');picker.dispatchEvent(new CustomEvent('eum:picker-refresh'));});});}if(!childItems.length&&!tasks.length){panel.innerHTML='<div class="eum-schedule-path-task-picker__empty">'+(branch>0?'No deeper paths or tasks here yet.':'No path items available.')+'</div>';}if(current){current.textContent=trail.length?('/ '+trail.map(function(item){return item.name||'';}).filter(Boolean).join(' / ')):'/';}if(summary){var selectedPath=parseInt(path.value||'0',10)||0;var selectedPathLabel=(selectedPath&&pathMap[String(selectedPath)])?String(pathMap[String(selectedPath)].label||pathMap[String(selectedPath)].name||''):'No path selected.';var text=selectedPath?'Selected Path: '+selectedPathLabel:'No path selected.';if(task.value){text+=' • Task: '+task.value;}summary.textContent=text;}if(back){back.disabled=trail.length===0;}}if(back){back.addEventListener('click',function(){var trail=pathTrail(path.value);if(!trail.length){return;}trail.pop();path.value=trail.length?String(trail[trail.length-1].id):'0';task.value='';setTaskOptionsForRow(row,false);});}if(path){path.addEventListener('change',render);}if(task){task.addEventListener('change',render);}if(kind){kind.addEventListener('change',render);}picker.addEventListener('eum:picker-refresh',render);render();}
  function applyUserDefaultToRow(row,userId,force){if(!row){return;}var kind=row.querySelector('.eum-segment-kind');if(kind&&kind.value!=='work'){return;}var path=row.querySelector('.eum-segment-path'),task=row.querySelector('.eum-segment-task'),defaults=getUserDefault(userId);if(!defaults||!path||!task){return;}if(!force&&((parseInt(path.value||'0',10)||0)>0||String(task.value||'')!=='')){return;}path.value=String(parseInt(defaults.path_id||'0',10)||0);setTaskOptionsForRow(row,false);task.value=String(defaults.task_label||'');setTaskOptionsForRow(row,true);}
  function bindRow(row){if(!row){return;}var kind=row.querySelector('.eum-segment-kind'),path=row.querySelector('.eum-segment-path'),remove=row.querySelector('[data-remove-segment]');if(kind){kind.addEventListener('change',function(){setTaskOptionsForRow(row,true);});}if(path){path.addEventListener('change',function(){setTaskOptionsForRow(row,true);});}if(remove){remove.addEventListener('click',function(){if(segmentRows().length>1){row.remove();}});}bindPathTaskPicker(row);setTaskOptionsForRow(row,true);}
  segmentRows().forEach(bindRow);
  wrap.querySelectorAll('[data-add-segment]').forEach(function(button){button.addEventListener('click',function(){if(!template||!segmentsWrap){return;}var html=template.innerHTML.replace(/__INDEX__/g,String(Date.now()+Math.floor(Math.random()*1000)));var holder=document.createElement('div');holder.innerHTML=html;var row=holder.firstElementChild;if(!row){return;}var kind=row.querySelector('.eum-segment-kind');if(kind){kind.value=button.getAttribute('data-add-segment')||'work';}segmentsWrap.appendChild(row);bindRow(row);if(kind&&kind.value==='work'){var userField=editor?editor.querySelector('#eum-schedule-target-user'):null;applyUserDefaultToRow(row,userField?userField.value:'0',true);}});});
  function setActivePlannerCell(cell){wrap.querySelectorAll('.eum-scheduler-cell.is-active').forEach(function(node){node.classList.remove('is-active');});activeCell=cell||null;if(activeCell){activeCell.classList.add('is-active');}}
  function shiftDate(value,sourceDate,targetDate){if(!value||!sourceDate||!targetDate){return value||'';}var raw=String(value||'');var valueDate=raw.slice(0,10);var timePart=raw.slice(10);if(!/^\d{4}-\d{2}-\d{2}$/.test(valueDate)||!/^\d{4}-\d{2}-\d{2}$/.test(String(sourceDate))||!/^\d{4}-\d{2}-\d{2}$/.test(String(targetDate))){return raw;}var sourceMidnight=new Date(String(sourceDate)+'T00:00:00');var valueMidnight=new Date(valueDate+'T00:00:00');var targetMidnight=new Date(String(targetDate)+'T00:00:00');if(isNaN(sourceMidnight.getTime())||isNaN(valueMidnight.getTime())||isNaN(targetMidnight.getTime())){return raw;}var dayOffset=Math.round((valueMidnight.getTime()-sourceMidnight.getTime())/86400000);targetMidnight.setDate(targetMidnight.getDate()+dayOffset);var yyyy=targetMidnight.getFullYear();var mm=String(targetMidnight.getMonth()+1).padStart(2,'0');var dd=String(targetMidnight.getDate()).padStart(2,'0');return yyyy+'-'+mm+'-'+dd+timePart;}
  function blankShift(targetUser,targetDate){var defaults=getUserDefault(targetUser)||{};return{shift_id:0,user_id:(typeof targetUser==='undefined'?0:parseInt(targetUser||'0',10)||0),notes:'',segments:[{kind:'work',start_local:(targetDate||'')+'T09:00',end_local:(targetDate||'')+'T17:00',job_path_term_id:parseInt(defaults.path_id||'0',10)||0,task_label:String(defaults.task_label||''),cost_center_term_id:0}]};}
  function openModal(){if(!modal){return;}modal.hidden=false;document.body.style.overflow='hidden';}
  function closeModal(){if(!modal){return;}modal.hidden=true;document.body.style.overflow='';hideContextMenu();}
  function currentShiftPayload(){if(!editor){return null;}var shiftField=editor.querySelector('#eum-schedule-shift-id'),userField=editor.querySelector('#eum-schedule-target-user'),notesField=editor.querySelector('#eum-schedule-notes');var data={shift_id:shiftField?parseInt(shiftField.value||'0',10):0,user_id:userField?parseInt(userField.value||'0',10):0,notes:notesField?notesField.value||'':'',segments:[]};segmentRows().forEach(function(row){data.segments.push({kind:(row.querySelector('.eum-segment-kind')||{}).value||'work',start_local:(row.querySelector('input[name="schedule_segment_start[]"]')||{}).value||'',end_local:(row.querySelector('input[name="schedule_segment_end[]"]')||{}).value||'',job_path_term_id:parseInt(((row.querySelector('.eum-segment-path')||{}).value||'0'),10)||0,task_label:(row.querySelector('.eum-segment-task')||{}).value||'',cost_center_term_id:parseInt(((row.querySelector('.eum-segment-cost-center')||{}).value||'0'),10)||0});});return data;}
  function fillEditor(data,targetUserId,targetDate,cell){if(!editor||!data||!segmentsWrap){return;}var shiftField=editor.querySelector('#eum-schedule-shift-id'),userField=editor.querySelector('#eum-schedule-target-user'),notesField=editor.querySelector('#eum-schedule-notes');if(shiftField){shiftField.value=data.shift_id?String(data.shift_id):'0';}if(userField){userField.value=String(typeof targetUserId!=='undefined'?targetUserId:(data.user_id||0));}if(notesField){notesField.value=data.notes||'';}setAvailabilityWarning(data.availability_warning||'');segmentsWrap.innerHTML='';var baseDate=targetDate||null;var sourceDate=(data.segments&&data.segments[0])?String(data.segments[0].start_local||'').slice(0,10):baseDate;var rows=(data.segments&&data.segments.length)?data.segments:[{kind:'work',start_local:'',end_local:'',job_path_term_id:0,task_label:'',cost_center_term_id:0}];rows.forEach(function(segment){if(!template||!segmentsWrap){return;}var html=template.innerHTML.replace(/__INDEX__/g,String(Date.now()+Math.floor(Math.random()*1000)));var holder=document.createElement('div');holder.innerHTML=html;var row=holder.firstElementChild;if(!row){return;}var kind=row.querySelector('.eum-segment-kind'),start=row.querySelector('input[name="schedule_segment_start[]"]'),end=row.querySelector('input[name="schedule_segment_end[]"]'),path=row.querySelector('.eum-segment-path'),task=row.querySelector('.eum-segment-task'),costCenter=row.querySelector('.eum-segment-cost-center');if(kind){kind.value=segment.kind||'work';}if(start){start.value=shiftDate(segment.start_local,sourceDate,baseDate);}if(end){end.value=shiftDate(segment.end_local,sourceDate,baseDate);}if(path){path.value=String(segment.job_path_term_id||0);}segmentsWrap.appendChild(row);bindRow(row);if(task){task.value=segment.task_label||'';setTaskOptionsForRow(row,true);}if(costCenter){costCenter.value=String(parseInt(segment.cost_center_term_id||'0',10)||0);}});if(!data.shift_id&&userField){var firstWork=segmentRows().find(function(row){var kind=row.querySelector('.eum-segment-kind');return !kind||kind.value==='work';});applyUserDefaultToRow(firstWork,userField.value,false);}if(deleteButton){deleteButton.style.display=(data.shift_id&&String(data.shift_id)!=='0')?'inline-flex':'none';}setActivePlannerCell(cell||null);openModal();}
  function copyShift(raw){if(!raw){return;}localStorage.setItem(copyKey,raw);}
  function buildSaveDraftForm(payload,targetUser,targetDate){if(!editor||!payload){return null;}var sourceDate=payload&&payload.segments&&payload.segments[0]?String(payload.segments[0].start_local||'').slice(0,10):targetDate;var userId=(typeof targetUser==='undefined'||targetUser===null)?payload.user_id:parseInt(targetUser||'0',10)||0;var notes=payload.notes||'';var form=document.createElement('form');form.method='post';form.action=editor.getAttribute('action')||'';form.style.display='none';function add(name,value){var input=document.createElement('input');input.type='hidden';input.name=name;input.value=String(value==null?'':value);form.appendChild(input);}add('action','eum_schedule_save_shift');add('shift_id','0');add('target_user_id',userId);add('return_url',((editor.querySelector('input[name="return_url"]')||{}).value||''));add('eum_schedule_save_nonce',((editor.querySelector('input[name="eum_schedule_save_nonce"]')||{}).value||''));add('schedule_notes',notes);add('eum_schedule_save_status','draft');(payload.segments||[]).forEach(function(segment){add('schedule_segment_kind[]',segment.kind||'work');add('schedule_segment_start[]',shiftDate(segment.start_local||'',sourceDate,targetDate));add('schedule_segment_end[]',shiftDate(segment.end_local||'',sourceDate,targetDate));add('schedule_segment_job_path[]',parseInt(segment.job_path_term_id||'0',10)||0);add('schedule_segment_task[]',segment.task_label||'');add('schedule_segment_cost_center[]',parseInt(segment.cost_center_term_id||'0',10)||0);});return form;}
  function pasteIntoContext(targetUser,targetDate,cell){var raw=localStorage.getItem(copyKey);if(!raw||!targetDate){return false;}try{var data=JSON.parse(raw||'{}');data.shift_id=0;var form=buildSaveDraftForm(data,targetUser,targetDate);if(!form){return false;}document.body.appendChild(form);form.submit();return true;}catch(e){return false;}}
  function hideContextMenu(){if(!contextMenu){return;}contextMenu.hidden=true;contextState={shiftEl:null,target:null};}
  function showContextMenu(x,y,state){if(!contextMenu){return;}contextState=state||{shiftEl:null,target:null};var copyItem=contextMenu.querySelector('[data-context-action="copy"]'),pasteItem=contextMenu.querySelector('[data-context-action="paste"]'),deleteItem=contextMenu.querySelector('[data-context-action="delete"]'),newItem=contextMenu.querySelector('[data-context-action="new"]');var canPaste=!!(contextState.target&&localStorage.getItem(copyKey));if(copyItem){copyItem.hidden=!contextState.shiftEl;}if(pasteItem){pasteItem.hidden=!contextState.target;pasteItem.classList.toggle('is-disabled',!!contextState.target&&!canPaste);}if(deleteItem){deleteItem.hidden=!contextState.shiftEl;}if(newItem){newItem.hidden=!contextState.target;}contextMenu.style.left=Math.max(12,Math.min(window.innerWidth-220,x))+'px';contextMenu.style.top=Math.max(12,Math.min(window.innerHeight-220,y))+'px';contextMenu.hidden=false;}
  wrap.addEventListener('click',function(event){hideContextMenu();var shiftEl=event.target.closest('.eum-schedule-shift');if(shiftEl&&shiftEl.getAttribute('data-shift')){event.preventDefault();try{fillEditor(JSON.parse(shiftEl.getAttribute('data-shift')||'{}'),undefined,undefined,shiftEl.closest('[data-add-shift-cell]'));}catch(e){}return;}var cell=event.target.closest('[data-add-shift-cell]');if(cell){event.preventDefault();fillEditor(blankShift(parseInt(cell.getAttribute('data-target-user')||'0',10),cell.getAttribute('data-target-date')),cell.getAttribute('data-target-user'),cell.getAttribute('data-target-date'),cell);}});
  wrap.addEventListener('contextmenu',function(event){var shiftEl=event.target.closest('.eum-schedule-shift');var target=event.target.closest('[data-add-shift-cell],.eum-schedule-paste-target');if(!shiftEl&&!target){return;}event.preventDefault();if(shiftEl&&!target){target=shiftEl.closest('[data-add-shift-cell],.eum-schedule-paste-target');}showContextMenu(event.clientX,event.clientY,{shiftEl:shiftEl,target:target});});
  if(contextMenu){contextMenu.addEventListener('click',function(event){var actionEl=event.target.closest('[data-context-action]');if(!actionEl){return;}var action=actionEl.getAttribute('data-context-action');if(action==='copy'&&contextState.shiftEl){var raw=contextState.shiftEl.getAttribute('data-shift');try{var payload=JSON.parse(raw||'{}');payload.shift_id=0;delete payload.delete_nonce;localStorage.setItem(copyKey,JSON.stringify(payload));}catch(e){}hideContextMenu();return;}if(action==='paste'&&contextState.target&&!actionEl.classList.contains('is-disabled')){pasteIntoContext(contextState.target.getAttribute('data-target-user'),contextState.target.getAttribute('data-target-date'),contextState.target.closest('[data-add-shift-cell]')||null);hideContextMenu();return;}if(action==='delete'&&contextState.shiftEl&&deleteForm){var payload=null;try{payload=JSON.parse(contextState.shiftEl.getAttribute('data-shift')||'{}');}catch(e){payload=null;}if(!payload||!payload.shift_id||!payload.delete_nonce){hideContextMenu();return;}if(!window.confirm('Delete this shift?')){hideContextMenu();return;}var idField=deleteForm.querySelector('#eum-schedule-delete-id');if(idField){idField.value=String(payload.shift_id);}var existingNonce=deleteForm.querySelector('input[name="eum_schedule_delete_nonce"]');if(existingNonce){existingNonce.remove();}var nonce=document.createElement('input');nonce.type='hidden';nonce.name='eum_schedule_delete_nonce';nonce.value=payload.delete_nonce;deleteForm.appendChild(nonce);hideContextMenu();deleteForm.submit();return;}if(action==='new'&&contextState.target){fillEditor(blankShift(parseInt(contextState.target.getAttribute('data-target-user')||'0',10),contextState.target.getAttribute('data-target-date')),contextState.target.getAttribute('data-target-user'),contextState.target.getAttribute('data-target-date'),contextState.target.closest('[data-add-shift-cell]')||null);hideContextMenu();}});}
  document.addEventListener('click',function(event){if(contextMenu&&!contextMenu.hidden&&!contextMenu.contains(event.target)){hideContextMenu();}});
  window.addEventListener('scroll',hideContextMenu,true);window.addEventListener('resize',hideContextMenu);
  if(modal){modal.addEventListener('click',function(event){if(event.target.matches('[data-schedule-modal-close]')){closeModal();}});}document.addEventListener('keydown',function(event){if(event.key==='Escape'){closeModal();}});
  if(deleteButton&&deleteForm){deleteButton.addEventListener('click',function(){var payload=currentShiftPayload();if(!payload||!payload.shift_id){return;}if(!window.confirm('Delete this shift?')){return;}var copied=null;wrap.querySelectorAll('.eum-schedule-shift').forEach(function(card){if(card.getAttribute('data-shift-id')===String(payload.shift_id)){try{copied=JSON.parse(card.getAttribute('data-shift')||'{}');}catch(e){copied=null;}}});if(!copied||!copied.delete_nonce){return;}var idField=deleteForm.querySelector('#eum-schedule-delete-id');if(idField){idField.value=String(payload.shift_id);}var existingNonce=deleteForm.querySelector('input[name="eum_schedule_delete_nonce"]');if(existingNonce){existingNonce.remove();}var nonce=document.createElement('input');nonce.type='hidden';nonce.name='eum_schedule_delete_nonce';nonce.value=copied.delete_nonce;deleteForm.appendChild(nonce);deleteForm.submit();});}
  var userField=editor?editor.querySelector('#eum-schedule-target-user'):null;if(userField){userField.addEventListener('change',function(){var shiftField=editor.querySelector('#eum-schedule-shift-id');if(shiftField&&parseInt(shiftField.value||'0',10)>0){return;}var firstWork=segmentRows().find(function(row){var kind=row.querySelector('.eum-segment-kind');return !kind||kind.value==='work';});applyUserDefaultToRow(firstWork,userField.value,true);});}
  var filterPath=wrap.querySelector('#eum-schedule-filter-path'),filterTask=wrap.querySelector('#eum-schedule-filter-task');
  function uniqueTasksForFilter(pathId){var set={},out=[];if(pathId&&taskMap[pathId]){taskMap[pathId].forEach(function(label){var key=String(label||'');if(key&&!set[key]){set[key]=1;out.push(key);}});return out.sort(function(a,b){return a.localeCompare(b);});}Object.keys(taskMap).forEach(function(key){(taskMap[key]||[]).forEach(function(label){var safe=String(label||'');if(safe&&!set[safe]){set[safe]=1;out.push(safe);}});});return out.sort(function(a,b){return a.localeCompare(b);});}
  function refreshFilterTasks(){if(!filterPath||!filterTask){return;}var current=filterTask.value||'';var options=uniqueTasksForFilter(parseInt(filterPath.value||'0',10)||0);filterTask.innerHTML='';var any=document.createElement('option');any.value='';any.textContent='All tasks';filterTask.appendChild(any);options.forEach(function(label){var option=document.createElement('option');option.value=label;option.textContent=label;if(label===current){option.selected=true;}filterTask.appendChild(option);});if(current&&Array.prototype.every.call(filterTask.options,function(option){return option.value!==current;})){filterTask.value='';}}
  if(filterPath&&filterTask){filterPath.addEventListener('change',refreshFilterTasks);refreshFilterTasks();}
})();
JS;
  }
}
