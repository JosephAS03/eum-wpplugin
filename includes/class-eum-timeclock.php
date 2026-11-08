<?php
if (!defined('ABSPATH')) exit;

class EUM_Timeclock {
  public const CPT_ENTRY = 'eum_time_entry';

  public const META_USER_ID = '_eum_time_user_id';
  public const META_START_UTC = '_eum_time_start_utc';
  public const META_END_UTC = '_eum_time_end_utc';
  public const META_SOURCE = '_eum_time_source';
  public const META_ENTRY_KIND = '_eum_time_entry_kind';
  public const META_JOB_PATH_TERM_ID = '_eum_time_job_path_term_id';
  public const META_TASK_LABEL = '_eum_time_task_label';
  public const META_COST_CENTER_TERM_ID = '_eum_time_cost_center_term_id';
  public const META_UPDATED_BY = '_eum_time_updated_by';
  public const META_UPDATED_AT = '_eum_time_updated_at';

  public const META_COMP_TYPE = '_eum_compensation_type';
  public const META_COMP_AMOUNT = '_eum_compensation_amount';
  public const META_COMP_UNPAID_LABEL = '_eum_compensation_unpaid_label';
  public const META_COMP_EFFECTIVE_AT = '_eum_compensation_effective_at';
  public const META_COMP_LAST_RAISE_AT = '_eum_compensation_last_raise_at';
  public const META_COMP_UPDATED_BY = '_eum_compensation_updated_by';
  public const META_COMP_UPDATED_AT = '_eum_compensation_updated_at';

  public const FIELD_COMP_TYPE = 'system:eum_compensation_type';
  public const FIELD_COMP_AMOUNT = 'system:eum_compensation_amount';
  public const FIELD_COMP_UNPAID_LABEL = 'system:eum_compensation_unpaid_label';

  private const DEFAULT_TIMEZONE = 'America/New_York';
  private const HOURS_PER_DAY_RATE_DAY = 12;
  private const REGULAR_WEEKLY_HOURS = 40;
  private static bool $timeclock_taxonomy_picker_assets_printed = false;

  public function init(): void {
    add_action('init', [$this, 'register_cpt']);

    add_action('admin_post_eum_timeclock_punch', [$this, 'handle_clock_punch']);
    add_action('admin_post_eum_timeclock_clock_in', [$this, 'handle_clock_in']);
    add_action('admin_post_eum_timeclock_clock_out', [$this, 'handle_clock_out']);
    add_action('admin_post_eum_timeclock_save_entry', [$this, 'handle_save_entry']);
    add_action('admin_post_eum_timeclock_delete_entry', [$this, 'handle_delete_entry']);
    add_action('admin_post_eum_compensation_save', [$this, 'handle_compensation_save']);

    add_shortcode('eum_timeclock', [$this, 'shortcode_timeclock']);
    add_shortcode('eum_timecard', [$this, 'shortcode_timecard']);
    add_shortcode('eum_manager_timecards', [$this, 'shortcode_manager_timecards']);
  }

  public function register_cpt(): void {
    register_post_type(self::CPT_ENTRY, [
      'labels' => [
        'name' => 'Time Entries',
        'singular_name' => 'Time Entry',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public static function compensation_type_options(): array {
    return [
      'unpaid' => 'Unpaid',
      'hourly' => 'Hourly',
      'day_rate' => 'Day Rate',
      'monthly' => 'Monthly',
      'yearly' => 'Yearly',
    ];
  }

  public static function normalize_compensation_type($value): string {
    $value = sanitize_key(is_scalar($value) ? (string)$value : '');
    return array_key_exists($value, self::compensation_type_options()) ? $value : 'unpaid';
  }

  public static function compensation_type_label(string $value): string {
    $options = self::compensation_type_options();
    return (string)($options[self::normalize_compensation_type($value)] ?? 'Unpaid');
  }

  public static function compensation_field_ids(): array {
    return [self::FIELD_COMP_TYPE, self::FIELD_COMP_AMOUNT, self::FIELD_COMP_UNPAID_LABEL];
  }

  public static function clock_status_for_user(int $user_id): array {
    $instance = new self();
    return $instance->resolved_clock_status_for_user($user_id);
  }

  public static function user_is_clocked_in(int $user_id): bool {
    return !empty(self::clock_status_for_user($user_id)['is_clocked_in']);
  }

  public static function normalized_compensation_payload_from_array(array $payload): array {
    $type = self::normalize_compensation_type($payload[self::FIELD_COMP_TYPE] ?? ($payload['type'] ?? 'unpaid'));
    $amount = self::sanitize_money($payload[self::FIELD_COMP_AMOUNT] ?? ($payload['amount'] ?? '0'));
    $unpaid_label = sanitize_text_field((string)($payload[self::FIELD_COMP_UNPAID_LABEL] ?? ($payload['unpaid_label'] ?? '')));

    if ($type === 'unpaid') {
      $amount = 0.0;
    }

    return [
      self::FIELD_COMP_TYPE => $type,
      self::FIELD_COMP_AMOUNT => $amount,
      self::FIELD_COMP_UNPAID_LABEL => $unpaid_label,
    ];
  }

  public static function compensation_payload_from_request(array $source, string $prefix = 'eum_comp'): array {
    $raw = isset($source[$prefix]) && is_array($source[$prefix]) ? wp_unslash($source[$prefix]) : [];
    return self::normalized_compensation_payload_from_array([
      self::FIELD_COMP_TYPE => $raw['type'] ?? 'unpaid',
      self::FIELD_COMP_AMOUNT => $raw['amount'] ?? '0',
      self::FIELD_COMP_UNPAID_LABEL => $raw['unpaid_label'] ?? '',
    ]);
  }

  public static function compensation_for_user(int $user_id): array {
    $type = self::normalize_compensation_type((string)get_user_meta($user_id, self::META_COMP_TYPE, true));
    $amount = self::sanitize_money(get_user_meta($user_id, self::META_COMP_AMOUNT, true));
    $unpaid_label = sanitize_text_field((string)get_user_meta($user_id, self::META_COMP_UNPAID_LABEL, true));
    $effective_at = EUM_Profiles::parse_datetime_local((string)get_user_meta($user_id, self::META_COMP_EFFECTIVE_AT, true));
    $last_raise_at = EUM_Profiles::parse_datetime_local((string)get_user_meta($user_id, self::META_COMP_LAST_RAISE_AT, true));
    return self::decorate_compensation([
      'type' => $type,
      'amount' => $amount,
      'unpaid_label' => $unpaid_label,
      'effective_at' => $effective_at,
      'last_raise_at' => $last_raise_at,
    ]);
  }

  public static function compensation_summary(int $user_id): string {
    return self::format_compensation_summary(self::compensation_for_user($user_id));
  }

  public static function compensation_detail_lines(int $user_id): array {
    return self::compensation_detail_lines_from_data(self::compensation_for_user($user_id));
  }

  public static function compensation_profile_rows_html(int $user_id): string {
    $lines = self::compensation_detail_lines($user_id);
    if (empty($lines)) return '';
    ob_start();
    foreach ($lines as $label => $value) {
      if ($value === '') continue;
      echo '<dt style="font-weight:600;">' . esc_html($label) . '</dt>';
      echo '<dd style="margin:0;">' . esc_html($value) . '</dd>';
    }
    return ob_get_clean();
  }

  public static function render_compensation_inputs(string $prefix = 'eum_comp', array $current = [], array $options = []): string {
    $payload = self::normalized_compensation_payload_from_array($current);
    $show_title = !array_key_exists('show_title', $options) || !empty($options['show_title']);
    $title = (string)($options['title'] ?? 'Compensation');
    $description = (string)($options['description'] ?? 'Set the pay frequency and amount used for earned-accrual calculations. Hourly and Day Rate automatically pay overtime at time-and-a-half after 40 hours in a Sunday–Saturday workweek.');
    $prefix_attr = esc_attr($prefix);

    ob_start();
    echo '<div class="eum-compensation-fields" style="margin:18px 0 8px;padding-top:14px;border-top:1px solid #e2e4e7;">';
    if ($show_title) {
      echo '<h4 style="margin:0 0 8px;">' . esc_html($title) . '</h4>';
      if ($description !== '') echo '<p class="description">' . esc_html($description) . '</p>';
    }

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Pay Frequency</label>';
    echo '<select name="' . $prefix_attr . '[type]" style="width:100%;max-width:320px;">';
    foreach (self::compensation_type_options() as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($payload[self::FIELD_COMP_TYPE], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></p>';

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Pay Amount</label>';
    echo '<input type="number" step="0.01" min="0" name="' . $prefix_attr . '[amount]" value="' . esc_attr(number_format((float)$payload[self::FIELD_COMP_AMOUNT], 2, '.', '')) . '" style="width:100%;max-width:220px;">';
    echo '<br><span class="description">Hourly = per hour, Day Rate = per day, Monthly = per month, Yearly = per year.</span></p>';

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Unpaid Label / Reason</label>';
    echo '<input type="text" name="' . $prefix_attr . '[unpaid_label]" value="' . esc_attr((string)$payload[self::FIELD_COMP_UNPAID_LABEL]) . '" style="width:100%;max-width:420px;" placeholder="Optional reason, e.g. Volunteer or Internship">';
    echo '<br><span class="description">Only used when Pay Frequency is Unpaid.</span></p>';

    echo '</div>';
    return ob_get_clean();
  }

  public static function render_compensation_section(int $target_user_id, int $actor_user_id, string $return_url): string {
    $current = self::compensation_for_user($target_user_id);
    $can_manage = self::can_manage_compensation($actor_user_id, $target_user_id);
    $editable_change = self::editable_compensation_change_for_user($target_user_id, $actor_user_id);
    $prefill = [
      self::FIELD_COMP_TYPE => $current['type'],
      self::FIELD_COMP_AMOUNT => $current['amount'],
      self::FIELD_COMP_UNPAID_LABEL => $current['unpaid_label'],
    ];
    $effective_value = '';

    if ($editable_change > 0) {
      $after = get_post_meta($editable_change, EUM_Profiles::META_AFTER, true);
      $after = is_array($after) ? $after : [];
      $prefill = array_merge($prefill, self::normalized_compensation_payload_from_array($after));
      $effective_at = EUM_Profiles::parse_datetime_local((string)get_post_meta($editable_change, EUM_Profiles::META_EFFECTIVE_AT, true));
      $effective_value = $effective_at !== '' ? str_replace(' ', 'T', substr($effective_at, 0, 16)) : '';
    }

    $rule = self::compensation_rule_context($actor_user_id, $target_user_id, self::normalized_compensation_payload_from_array($prefill));

    ob_start();
    echo '<div class="eum-private-profile__section">';
    echo '<h4 style="margin-top:0;">Compensation</h4>';
    echo '<p><strong>Current Compensation:</strong> ' . esc_html(self::format_compensation_summary($current)) . '</p>';

    if (!$can_manage) {
      echo '<p class="description">Managers can update compensation for their own direct reports. HR and IT can update compensation for any user.</p>';
      echo '</div>';
      return ob_get_clean();
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_compensation_save">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    if ($editable_change > 0) echo '<input type="hidden" name="change_id" value="' . (int)$editable_change . '">';
    wp_nonce_field('eum_compensation_save_' . $target_user_id, 'eum_compensation_nonce');

    echo self::render_compensation_inputs('eum_comp', $prefill, [
      'show_title' => false,
    ]);

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Effective At</label>';
    echo '<input type="datetime-local" name="eum_comp_effective_at" value="' . esc_attr($effective_value) . '" style="width:100%;max-width:320px;">';
    echo '<br><span class="description">Leave blank to apply immediately. Future dates schedule the change to apply later.</span></p>';

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Reason</label>';
    echo '<input type="text" name="eum_comp_reason" value="" style="width:100%;max-width:420px;" placeholder="Compensation Update"></p>';

    echo '<p class="description">Managers can set compensation immediately for new hires or other initial setups. Later raises above 15%, or raises inside a 3-month window, route to second-level approval.</p>';
    if (!empty($rule['requires_approval'])) {
      $labels = array_map(['EUM_Profiles', 'manager_user_name'], (array)($rule['approver_ids'] ?? []));
      $labels = array_values(array_filter($labels));
      if (!empty($labels)) {
        echo '<p class="description">This compensation change requires second-level approval from: ' . esc_html(implode(', ', $labels)) . '.</p>';
      } else {
        echo '<p class="description">This compensation change requires second-level approval. If no approver resolves automatically, HR or IT can approve it.</p>';
      }
      if (!empty($rule['reasons'])) {
        echo '<p class="description">Triggered because: ' . esc_html(implode('; ', (array)$rule['reasons'])) . '.</p>';
      }
    } else {
      echo '<p class="description">This compensation change can be applied or scheduled without second-level approval.</p>';
    }

    echo '<p><button type="submit" class="button button-primary">Save Compensation</button></p>';
    echo '</form>';
    echo '</div>';
    return ob_get_clean();
  }

  public static function compensation_rule_context(int $actor_user_id, int $target_user_id, array $proposed_payload): array {
    $current = self::compensation_for_user($target_user_id);
    $proposed = self::decorate_compensation([
      'type' => self::normalize_compensation_type($proposed_payload[self::FIELD_COMP_TYPE] ?? 'unpaid'),
      'amount' => self::sanitize_money($proposed_payload[self::FIELD_COMP_AMOUNT] ?? 0),
      'unpaid_label' => sanitize_text_field((string)($proposed_payload[self::FIELD_COMP_UNPAID_LABEL] ?? '')),
      'effective_at' => '',
      'last_raise_at' => '',
    ]);

    $current_annual = (float)$current['annualized_amount'];
    $proposed_annual = (float)$proposed['annualized_amount'];
    $raise_ratio = ($current_annual > 0 && $proposed_annual > $current_annual)
      ? ($proposed_annual / $current_annual)
      : 1.0;

    $requires_approval = false;
    $reasons = [];

    if ($current_annual > 0 && $proposed_annual > $current_annual * 1.15) {
      $requires_approval = true;
      $reasons[] = 'increase exceeds 15% of the current annualized compensation';
    }

    if ($proposed_annual > $current_annual) {
      $last_raise_at = EUM_Profiles::parse_datetime_local((string)($current['last_raise_at'] ?? ''));
      if ($last_raise_at !== '') {
        $last_raise_ts = strtotime(get_gmt_from_date($last_raise_at));
        if ($last_raise_ts > 0 && $last_raise_ts > (time() - (90 * DAY_IN_SECONDS))) {
          $requires_approval = true;
          $reasons[] = 'the last raise was less than 3 months ago';
        }
      }
    }

    $approver_ids = [];
    if ($requires_approval && !EUM_Access::is_it_or_hr($actor_user_id) && class_exists('EUM_Manager_IDs')) {
      $approver_ids = array_values(array_unique(array_filter(array_map('intval', EUM_Manager_IDs::manager_user_ids_for_subject_user($actor_user_id)), static fn($uid) => $uid > 0 && $uid !== $actor_user_id && $uid !== $target_user_id)));
      if (empty($approver_ids) && class_exists('EUM_Profiles')) {
        $approver_ids = EUM_Profiles::approver_ids_for_actor($actor_user_id, $target_user_id);
      }
    }

    return [
      'requires_approval' => $requires_approval,
      'reasons' => $reasons,
      'approver_ids' => $approver_ids,
      'current' => $current,
      'proposed' => $proposed,
      'raise_ratio' => $raise_ratio,
    ];
  }

  public static function can_manage_compensation(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if (!class_exists('EUM_Manager_IDs')) return false;
    return EUM_Manager_IDs::user_is_direct_manager_of_user($actor_user_id, $target_user_id) || EUM_Profiles::is_pending_manager_for_target($actor_user_id, $target_user_id);
  }

  public static function apply_compensation_payload_to_user(int $user_id, array $payload, int $actor_user_id = 0, string $effective_at = ''): void {
    $current = self::compensation_for_user($user_id);
    $normalized = self::normalized_compensation_payload_from_array($payload);
    $new = self::decorate_compensation([
      'type' => $normalized[self::FIELD_COMP_TYPE],
      'amount' => $normalized[self::FIELD_COMP_AMOUNT],
      'unpaid_label' => $normalized[self::FIELD_COMP_UNPAID_LABEL],
      'effective_at' => EUM_Profiles::parse_datetime_local($effective_at),
      'last_raise_at' => (string)($current['last_raise_at'] ?? ''),
    ]);

    update_user_meta($user_id, self::META_COMP_TYPE, $new['type']);
    update_user_meta($user_id, self::META_COMP_AMOUNT, $new['amount']);
    if ($new['unpaid_label'] !== '') update_user_meta($user_id, self::META_COMP_UNPAID_LABEL, $new['unpaid_label']);
    else delete_user_meta($user_id, self::META_COMP_UNPAID_LABEL);

    $effective_value = $new['effective_at'] !== '' ? $new['effective_at'] : current_time('mysql');
    update_user_meta($user_id, self::META_COMP_EFFECTIVE_AT, $effective_value);
    update_user_meta($user_id, self::META_COMP_UPDATED_AT, current_time('mysql'));
    if ($actor_user_id > 0) update_user_meta($user_id, self::META_COMP_UPDATED_BY, $actor_user_id);

    if ($new['annualized_amount'] > (float)$current['annualized_amount']) {
      update_user_meta($user_id, self::META_COMP_LAST_RAISE_AT, $effective_value);
    }
  }

  public function shortcode_timeclock($atts = []): string {
    if (!is_user_logged_in()) return '<div class="eum-timeclock"><p>You must be logged in to use the timeclock.</p></div>';
    $atts = shortcode_atts([
      'show_clock' => '1',
      'show_history' => '1',
    ], (array)$atts, 'eum_timeclock');

    $actor_user_id = get_current_user_id();
    $show_clock = !empty($atts['show_clock']) && $atts['show_clock'] !== '0';
    $managed = $this->managed_timecard_context_for_actor($actor_user_id);
    if (!empty($managed['managed_user_ids'])) {
      $selected_user_id = isset($_GET['eum_time_user']) ? (int)$_GET['eum_time_user'] : $actor_user_id;
      if (!in_array($selected_user_id, (array)$managed['managed_user_ids'], true)) $selected_user_id = $actor_user_id;
      return $this->render_timecard_portal($selected_user_id, $actor_user_id, [
        'show_clock' => $show_clock,
        'allow_manager_clock' => true,
        'allow_manager_edit' => true,
        'manager_mode' => $selected_user_id !== $actor_user_id || isset($_GET['eum_time_user']),
        'allow_user_picker' => true,
        'managed_user_ids' => (array)$managed['managed_user_ids'],
        'managed_levels' => (array)$managed['managed_levels'],
        'managed_source_labels' => (array)$managed['managed_source_labels'],
      ]);
    }

    return $this->render_timecard_portal($actor_user_id, $actor_user_id, [
      'show_clock' => $show_clock,
      'allow_manager_edit' => false,
      'manager_mode' => false,
      'allow_user_picker' => false,
    ]);
  }

  public function shortcode_timecard($atts = []): string {
    $atts = shortcode_atts(['show_clock' => '0'], (array)$atts, 'eum_timecard');
    return $this->shortcode_timeclock($atts);
  }

  public function shortcode_manager_timecards($atts = []): string {
    if (!is_user_logged_in()) return '<div class="eum-timeclock"><p>You must be logged in to view team timecards.</p></div>';
    $actor_user_id = get_current_user_id();
    if (!(EUM_Access::is_it_or_hr($actor_user_id) || EUM_Access::is_manager_user($actor_user_id))) {
      return '<div class="eum-timeclock"><p>You do not have access to team timecards.</p></div>';
    }

    $managed = $this->managed_timecard_context_for_actor($actor_user_id);
    $managed_user_ids = array_values(array_filter(array_map('intval', (array)($managed['managed_user_ids'] ?? [])), static fn($id) => $id > 0 && $id !== $actor_user_id));
    if (empty($managed_user_ids)) {
      return '<div class="eum-timeclock"><p>No team members are available for timecard review.</p></div>';
    }

    sort($managed_user_ids, SORT_NUMERIC);
    $filtered_user_ids = $this->filtered_team_timecard_user_ids($managed_user_ids);
    if (empty($filtered_user_ids)) {
      return '<div class="eum-timeclock"><p>No team members matched the selected Job Path / Title filter.</p></div>';
    }

    $selected_user_id = isset($_GET['eum_time_user']) ? (int)$_GET['eum_time_user'] : (int)$filtered_user_ids[0];
    if (!in_array($selected_user_id, $filtered_user_ids, true) && !EUM_Access::is_it_or_hr($actor_user_id)) {
      $selected_user_id = (int)$filtered_user_ids[0];
    }

    $overview = $this->render_team_timecards_table($filtered_user_ids, $selected_user_id, $actor_user_id);
    $detail = $this->render_timecard_portal($selected_user_id, $actor_user_id, [
      'show_clock' => true,
      'allow_manager_clock' => true,
      'allow_manager_edit' => true,
      'manager_mode' => true,
      'allow_user_picker' => false,
      'managed_user_ids' => $filtered_user_ids,
      'managed_levels' => (array)($managed['managed_levels'] ?? []),
      'managed_source_labels' => (array)($managed['managed_source_labels'] ?? []),
    ]);

    return '<div class="eum-timeclock-team">' . $overview . $detail . '</div>';
  }

  private function managed_timecard_context_for_actor(int $actor_user_id): array {
    if ($actor_user_id <= 0) {
      return [
        'managed_user_ids' => [],
        'managed_levels' => [],
        'managed_source_labels' => [],
      ];
    }
    if (!(EUM_Access::is_it_or_hr($actor_user_id) || EUM_Access::is_manager_user($actor_user_id))) {
      return [
        'managed_user_ids' => [],
        'managed_levels' => [],
        'managed_source_labels' => [],
      ];
    }

    $levels = class_exists('EUM_Manager_IDs') ? EUM_Manager_IDs::managed_user_levels_for_manager_user($actor_user_id, true, true) : [];
    $managed_user_ids = array_values(array_unique(array_filter(array_map('intval', array_keys($levels)))));
    $source_labels = [];

    foreach ($this->manager_schedule_visible_user_ids($actor_user_id) as $scheduled_user_id) {
      if (!in_array($scheduled_user_id, $managed_user_ids, true)) {
        $managed_user_ids[] = $scheduled_user_id;
        $source_labels[$scheduled_user_id] = 'Scheduled under your paths/tasks';
      }
    }

    if (EUM_Access::is_it_or_hr($actor_user_id) && empty($managed_user_ids)) {
      $managed_user_ids = array_values(array_map(static fn($u) => (int)$u->ID, get_users(['fields' => ['ID'], 'number' => 5000])));
    }

    if (!in_array($actor_user_id, $managed_user_ids, true)) {
      $managed_user_ids[] = $actor_user_id;
    }

    $managed_user_ids = array_values(array_unique(array_filter(array_map('intval', $managed_user_ids), static fn($id) => $id > 0)));
    return [
      'managed_user_ids' => $managed_user_ids,
      'managed_levels' => is_array($levels) ? $levels : [],
      'managed_source_labels' => $source_labels,
    ];
  }

  private function render_timecard_portal(int $target_user_id, int $viewer_user_id, array $options = []): string {
    if ($target_user_id <= 0) return '<div class="eum-timeclock"><p>User not found.</p></div>';
    if (!$this->can_view_timecard($viewer_user_id, $target_user_id)) {
      return '<div class="eum-timeclock"><p>You do not have permission to view this timecard.</p></div>';
    }

    $tz = $this->user_timezone($target_user_id);
    [$default_start, $default_end] = $this->default_timecard_dates($target_user_id);
    $start_date = isset($_GET['eum_time_start']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['eum_time_start']) : $default_start;
    $end_date = isset($_GET['eum_time_end']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['eum_time_end']) : $default_end;
    if ($start_date === '') $start_date = $default_start;
    if ($end_date === '') $end_date = $start_date;
    if ($end_date < $start_date) $end_date = $start_date;

    [$range_start_utc, $range_end_utc, $range_start_local, $range_end_local] = $this->date_range_to_utc($target_user_id, $start_date, $end_date);
    [$analysis_start_utc, $analysis_end_utc] = $this->analysis_range_for_date_range($target_user_id, $start_date, $end_date);

    $entries = $this->entries_for_user_between($target_user_id, $analysis_start_utc, $analysis_end_utc, true);
    $metrics = $this->build_timecard_metrics($target_user_id, $entries, $range_start_utc, $range_end_utc, $analysis_start_utc, $analysis_end_utc);
    $target_user = get_user_by('id', $target_user_id);
    $can_edit = !empty($options['allow_manager_edit']) && $this->can_edit_timecard($viewer_user_id, $target_user_id);
    $path_options = $can_edit ? $this->job_path_options_for_form() : [];
    $task_map = $can_edit ? $this->task_options_map_for_paths(array_keys($path_options)) : [];
    $cost_center_options = $can_edit ? $this->cost_center_options_for_form() : [];
    $can_clock_target = !empty($options['show_clock']) && ($viewer_user_id === $target_user_id || (!empty($options['allow_manager_clock']) && $this->can_edit_timecard($viewer_user_id, $target_user_id)));
    $show_clock = $can_clock_target;
    $current_url = $this->current_url();
    $notice = $this->timeclock_notice_html();

    ob_start();
    echo '<div class="eum-timeclock" style="max-width:1100px;">';
    if ($notice !== '') echo $notice;

    echo '<div style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;margin-bottom:18px;">';
    echo '<h3 style="margin-top:0;">' . esc_html(!empty($options['manager_mode']) ? 'Team Timecards' : 'My Timeclock') . '</h3>';
    echo '<form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">';
    foreach ($_GET as $key => $value) {
      $key = (string)$key;
      if (in_array($key, ['eum_time_user', 'eum_time_start', 'eum_time_end'], true)) continue;
      if (is_array($value)) continue;
      echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)$value) . '">';
    }
    if (!empty($options['allow_user_picker'])) {
      $managed_user_ids = array_values(array_filter(array_map('intval', (array)($options['managed_user_ids'] ?? [])), static fn($id) => $id > 0));
      $managed_levels = is_array($options['managed_levels'] ?? null) ? (array)$options['managed_levels'] : [];
      $managed_source_labels = is_array($options['managed_source_labels'] ?? null) ? (array)$options['managed_source_labels'] : [];
      echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">User</span><select name="eum_time_user" style="min-width:320px;">';
      foreach ($managed_user_ids as $managed_user_id) {
        $u = get_user_by('id', $managed_user_id);
        if (!$u) continue;
        $label = $u->display_name ?: $u->user_login;
        $depth = isset($managed_levels[$managed_user_id]) ? (int)$managed_levels[$managed_user_id] : 0;
        if ($depth > 1) $label .= ' · Level ' . $depth;
        elseif (!empty($managed_source_labels[$managed_user_id])) $label .= ' · ' . (string)$managed_source_labels[$managed_user_id];
        echo '<option value="' . (int)$managed_user_id . '" ' . selected($target_user_id, $managed_user_id, false) . '>' . esc_html($label) . '</option>';
      }
      echo '</select></label>';
    }
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">Start Date</span><input type="date" name="eum_time_start" value="' . esc_attr($start_date) . '"></label>';
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">End Date</span><input type="date" name="eum_time_end" value="' . esc_attr($end_date) . '"></label>';
    echo '<button type="submit" class="button button-primary">View Timecard</button>';
    echo '</form>';
    echo '<p class="description" style="margin:10px 0 0;">Timecards use the selected user\'s timezone: <strong>' . esc_html($tz->getName()) . '</strong>. Weekly overtime resets Sunday at 12:00 AM local time.</p>';
    echo '</div>';

    if ($show_clock) {
      echo $this->render_clock_controls($target_user_id, $viewer_user_id, $current_url, $tz);
    }

    echo '<div style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;margin-bottom:18px;">';
    echo '<div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;">';
    echo '<div><h4 style="margin:0 0 6px;">' . esc_html(($target_user ? ($target_user->display_name ?: $target_user->user_login) : ('User #' . $target_user_id)) . ' Timecard') . '</h4>';
    echo '<p style="margin:0;color:#50575e;">' . esc_html($range_start_local->format('M j, Y')) . ' – ' . esc_html($range_end_local->modify('-1 second')->format('M j, Y')) . '</p></div>';
    echo '<div style="text-align:right;min-width:260px;">';
    echo '<p style="margin:0 0 4px;"><strong>Compensation:</strong> ' . esc_html(self::format_compensation_summary($metrics['compensation'])) . '</p>';
    echo '<p style="margin:0;"><strong>Earned Accrual:</strong> ' . esc_html($this->format_money($metrics['earnings'])) . '</p>';
    echo '</div></div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:16px;">';
    echo $this->stat_card('Worked Hours', number_format((float)$metrics['hours_total'], 2));
    echo $this->stat_card('Regular Hours', number_format((float)$metrics['regular_hours'], 2));
    echo $this->stat_card('Overtime Hours', number_format((float)$metrics['overtime_hours'], 2));
    echo $this->stat_card('Worked Blocks', (string)(int)($metrics['worked_segments_count'] ?? 0));
    echo '</div>';
    echo '</div>';

    if ($can_edit) {
      echo $this->render_manual_entry_form($target_user_id, $current_url, $tz, $path_options, $task_map, $cost_center_options);
    }

    echo '<div style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">';
    echo '<h4 style="margin-top:0;">Current Hours</h4>';
    if (empty($metrics['day_blocks'])) {
      echo '<p>No time entries were found in this range.</p>';
    } else {
      echo $this->render_timecard_day_blocks($metrics['day_blocks'], $target_user_id, $tz);
    }
    echo '</div>';

    echo '<div style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;margin-top:18px;">';
    echo '<h4 style="margin-top:0;">Punch Log</h4>';
    if (empty($metrics['display_entries'])) {
      echo '<p>No raw time entries were found in this range.</p>';
    } else {
      echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr><th>Punch / Range</th><th>Type</th><th>Assignment / Billing</th><th>Source</th><th>Hours</th>' . ($can_edit ? '<th>Edit</th><th>Delete</th>' : '') . '</tr></thead><tbody>';
      foreach ($metrics['display_entries'] as $entry) {
        $entry_id = (int)$entry['id'];
        echo '<tr>';
        echo '<td><strong>' . esc_html($entry['start_label']) . '</strong><br><span style="color:#50575e;">' . esc_html($entry['end_label']) . '</span></td>';
        echo '<td>' . esc_html((string)($entry['entry_kind_label'] ?? 'Punch')) . '</td>';
        echo '<td>' . $entry['assignment_html'] . '</td>';
        echo '<td>' . esc_html((string)($entry['source_label'] ?? 'Portal')) . '</td>';
        echo '<td>' . esc_html(number_format((float)$entry['hours'], 2)) . '</td>';
        if ($can_edit) {
          echo '<td>' . $this->render_entry_edit_form($entry_id, $target_user_id, $current_url, $entry['start_input'], $entry['end_input'], $path_options, $task_map, $cost_center_options, (int)($entry['job_path_term_id'] ?? 0), (string)($entry['task_label'] ?? ''), (int)($entry['cost_center_term_id'] ?? 0), (string)($entry['entry_kind'] ?? 'punch_in')) . '</td>';
          echo '<td>' . $this->render_entry_delete_form($entry_id, $target_user_id, $current_url) . '</td>';
        }
        echo '</tr>';
      }
      echo '</tbody></table></div>';
    }
    if ($can_edit) {
      echo '<script type="application/json" id="eum-timeclock-task-map">' . wp_json_encode($task_map) . '</script>';
      echo '<script>' . $this->inline_assignment_script() . '</script>';
    }
    echo '</div>';
    echo '</div>';
    return ob_get_clean();
  }

  private function filtered_team_timecard_user_ids(array $managed_user_ids): array {
    $mode = sanitize_key((string)($_GET['eum_time_team_filter'] ?? 'job_path'));
    if (!in_array($mode, ['job_path', 'job_path_title'], true)) $mode = 'job_path';
    $job_path_term_id = max(0, (int)($_GET['eum_time_filter_job_path'] ?? 0));
    $job_title_token = sanitize_text_field((string)($_GET['eum_time_filter_job_title'] ?? ''));

    $filtered = [];
    foreach ($managed_user_ids as $user_id) {
      $user_id = (int)$user_id;
      if ($user_id <= 0) continue;
      $user_job_path_term_id = $this->user_job_path_term_id($user_id);
      if ($job_path_term_id > 0 && $user_job_path_term_id !== $job_path_term_id) continue;
      if ($mode === 'job_path_title' && $job_title_token !== '' && $this->user_job_title_token($user_id) !== $job_title_token) continue;
      $filtered[] = $user_id;
    }

    return array_values(array_unique($filtered));
  }

  private function render_team_timecards_table(array $user_ids, int $selected_user_id, int $actor_user_id): string {
    $mode = sanitize_key((string)($_GET['eum_time_team_filter'] ?? 'job_path'));
    if (!in_array($mode, ['job_path', 'job_path_title'], true)) $mode = 'job_path';
    $job_path_term_id = max(0, (int)($_GET['eum_time_filter_job_path'] ?? 0));
    $job_title_token = sanitize_text_field((string)($_GET['eum_time_filter_job_title'] ?? ''));
    [$default_start, $default_end] = $this->default_timecard_dates($selected_user_id);
    $start_date = isset($_GET['eum_time_start']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['eum_time_start']) : $default_start;
    $end_date = isset($_GET['eum_time_end']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['eum_time_end']) : $default_end;
    if ($start_date === '') $start_date = $default_start;
    if ($end_date === '') $end_date = $start_date;
    if ($end_date < $start_date) $end_date = $start_date;
    $path_options = $this->job_path_options_for_form();
    $title_options = $job_path_term_id > 0 ? $this->job_title_options_for_job_path($job_path_term_id) : [];

    ob_start();
    echo '<div style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;margin-bottom:18px;">';
    echo '<h3 style="margin-top:0;">Team Timecards</h3>';
    echo '<form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">';
    foreach ($_GET as $key => $value) {
      $key = (string)$key;
      if (in_array($key, ['eum_time_user', 'eum_time_team_filter', 'eum_time_filter_job_path', 'eum_time_filter_job_title'], true)) continue;
      if (is_array($value)) continue;
      echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)$value) . '">';
    }
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">View By</span><select name="eum_time_team_filter">';
    echo '<option value="job_path" ' . selected($mode, 'job_path', false) . '>Job Path</option>';
    echo '<option value="job_path_title" ' . selected($mode, 'job_path_title', false) . '>Job Path + Title</option>';
    echo '</select></label>';
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">Job Path</span><select name="eum_time_filter_job_path" style="min-width:280px;"><option value="0">All Job Paths</option>';
    foreach ($path_options as $term_id => $label) {
      echo '<option value="' . (int)$term_id . '" ' . selected($job_path_term_id, (int)$term_id, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">Job Title</span><select name="eum_time_filter_job_title" style="min-width:240px;"><option value="">All Titles</option>';
    foreach ($title_options as $token => $label) {
      echo '<option value="' . esc_attr($token) . '" ' . selected($job_title_token, $token, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<button type="submit" class="button button-primary">Filter Team</button>';
    echo '</form>';
    echo '<div style="overflow:auto;margin-top:14px;"><table class="widefat striped"><thead><tr><th>Employee</th><th>Job Path</th><th>Job Title</th><th>Status</th><th>Last Punch</th><th>Worked Hours</th><th>Open</th></tr></thead><tbody>';
    foreach ($user_ids as $user_id) {
      $user_id = (int)$user_id;
      if ($user_id <= 0) continue;
      [$range_start_utc, $range_end_utc] = $this->date_range_to_utc($user_id, $start_date, $end_date);
      [$analysis_start_utc, $analysis_end_utc] = $this->analysis_range_for_date_range($user_id, $start_date, $end_date);
      $entries = $this->entries_for_user_between($user_id, $analysis_start_utc, $analysis_end_utc, true);
      $metrics = $this->build_timecard_metrics($user_id, $entries, $range_start_utc, $range_end_utc, $analysis_start_utc, $analysis_end_utc);
      $clock_status = (array)($metrics['clock_status'] ?? $this->resolved_clock_status_for_user($user_id));
      $tz = $this->user_timezone($user_id);
      $last_punch = !empty($clock_status['last_event_utc']) ? $this->format_local_ts((int)$clock_status['last_event_utc'], $tz) . ' | ' . (string)($clock_status['last_event_label'] ?? '') : 'No punches';
      $view_url = add_query_arg(['eum_time_user' => $user_id], $this->current_url());
      $row_style = $user_id === $selected_user_id ? ' style="background:#eff6ff;"' : '';
      $avatar = class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html($user_id, 32, 'eum-directory__avatar') : '';
      echo '<tr' . $row_style . '>';
      echo '<td><span class="eum-directory__person">' . $avatar . '<span>' . esc_html(class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_list_label($user_id) : $this->user_label($user_id)) . '</span></span></td>';
      echo '<td>' . esc_html($this->user_job_path_label($user_id)) . '</td>';
      echo '<td>' . esc_html($this->user_job_title_label($user_id)) . '</td>';
      echo '<td>' . esc_html(!empty($clock_status['is_clocked_in']) ? 'Clocked In' : 'Clocked Out') . '</td>';
      echo '<td>' . esc_html($last_punch) . '</td>';
      echo '<td>' . esc_html(number_format((float)($metrics['hours_total'] ?? 0), 2)) . '</td>';
      echo '<td><a class="button button-small" href="' . esc_url($view_url) . '">View</a></td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_clock_controls(int $user_id, int $viewer_user_id, string $return_url, DateTimeZone $tz): string {
    $clock_status = $this->resolved_clock_status_for_user($user_id);
    $now = new DateTimeImmutable('now', $tz);
    $is_proxy_clock = $viewer_user_id > 0 && $viewer_user_id !== $user_id;
    $status_label = !empty($clock_status['is_clocked_in']) ? 'Clocked In' : 'Clocked Out';
    $last_label = '';
    if (!empty($clock_status['last_event_utc'])) {
      $last_local = (new DateTimeImmutable('@' . (int)$clock_status['last_event_utc']))->setTimezone($tz);
      $last_label = $last_local->format('M j, Y g:i A');
    }
    ob_start();
    echo '<div style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;margin-bottom:18px;">';
    echo '<h4 style="margin-top:0;">' . esc_html($is_proxy_clock ? 'Manager Clock Punch' : 'Clock Punch') . '</h4>';
    echo '<p class="description">Times are recorded in UTC and displayed here in ' . esc_html($tz->getName()) . '. The latest punch decides whether the user is currently clocked in or clocked out.</p>';
    if (!$is_proxy_clock && !$this->user_can_bypass_schedule_clock_rule($user_id)) {
      echo '<p class="description" style="margin-top:4px;">Clock-in opens 8 minutes before a scheduled shift and stays available throughout the scheduled shift, including breaks.</p>';
    }

    echo '<p><strong>Status:</strong> ' . esc_html($status_label) . '</p>';
    if (!empty($clock_status['is_clocked_in']) && !empty($clock_status['open_started_at'])) {
      $start_local = (new DateTimeImmutable('@' . (int)$clock_status['open_started_at']))->setTimezone($tz);
      $elapsed_hours = max(0, time() - (int)$clock_status['open_started_at']) / HOUR_IN_SECONDS;
      echo '<p><strong>Clocked in since:</strong> ' . esc_html($start_local->format('M j, Y g:i A')) . ' | ' . esc_html(number_format($elapsed_hours, 2)) . ' hours elapsed</p>';
    } elseif ($last_label !== '') {
      echo '<p><strong>Last punch:</strong> ' . esc_html($last_label) . ' | ' . esc_html((string)($clock_status['last_event_label'] ?? '')) . '</p>';
    }
    echo '<p><strong>Current time:</strong> ' . esc_html($now->format('M j, Y g:i A')) . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_timeclock_punch">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$user_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_timeclock_punch', 'eum_timeclock_punch_nonce');
    echo '<button type="submit" class="button button-primary">' . esc_html($is_proxy_clock ? 'Punch User' : 'Clock Punch') . '</button>';
    echo '</form>';

    echo '</div>';
    return ob_get_clean();
  }

  private function render_manual_entry_form(int $target_user_id, string $return_url, DateTimeZone $tz, array $path_options, array $task_map, array $cost_center_options): string {
    $now = new DateTimeImmutable('now', $tz);
    $default_start = $now->setTime(9, 0);
    $default_assignment = $this->default_entry_assignment_for_user($target_user_id);
    ob_start();
    echo '<div style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;margin-bottom:18px;">';
    echo '<h4 style="margin-top:0;">Add Punch</h4>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-timeclock-entry-form" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">';
    echo '<input type="hidden" name="action" value="eum_timeclock_save_entry">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_timeclock_save_entry_' . $target_user_id, 'eum_timeclock_save_entry_nonce');
    echo $this->entry_kind_select_html('entry_kind', 'punch_in');
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">Punch Time</span><input type="datetime-local" name="entry_start" value="' . esc_attr($default_start->format('Y-m-d\TH:i')) . '" required></label>';
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">Optional End Time</span><input type="datetime-local" name="entry_end" value=""></label>';
    echo $this->render_entry_assignment_fields($path_options, $task_map, $cost_center_options, (int)($default_assignment['job_path_term_id'] ?? 0), (string)($default_assignment['task_label'] ?? ''), (int)($default_assignment['cost_center_term_id'] ?? 0), 'eum-timeclock-add');
    echo '<div style="flex:1 1 100%;margin-top:-2px;"><span class="description">Choose Punch In or Punch Out. If you supply Optional End Time for a legacy shift record, it will be saved only when editing an existing shift.</span></div>';
    echo '<button type="submit" class="button">Add Punch</button>';
    echo '</form>';
    echo '</div>';
    return ob_get_clean();
  }

  private function render_entry_edit_form(int $entry_id, int $target_user_id, string $return_url, string $start_input, string $end_input, array $path_options, array $task_map, array $cost_center_options, int $job_path_term_id, string $task_label, int $cost_center_term_id, string $entry_kind): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-timeclock-entry-form" style="display:flex;flex-wrap:wrap;gap:6px;align-items:flex-end;">';
    echo '<input type="hidden" name="action" value="eum_timeclock_save_entry">';
    echo '<input type="hidden" name="entry_id" value="' . (int)$entry_id . '">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_timeclock_save_entry_' . $target_user_id, 'eum_timeclock_save_entry_nonce');
    echo $this->entry_kind_select_html('entry_kind', $entry_kind);
    echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;color:#50575e;">Start / Punch Time<input type="datetime-local" name="entry_start" value="' . esc_attr($start_input) . '" style="max-width:180px;"></label>';
    echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;color:#50575e;">End Time<input type="datetime-local" name="entry_end" value="' . esc_attr($end_input) . '" style="max-width:180px;"></label>';
    echo $this->render_entry_assignment_fields($path_options, $task_map, $cost_center_options, $job_path_term_id, $task_label, $cost_center_term_id, 'eum-timeclock-edit-' . $entry_id);
    echo '<button type="submit" class="button button-small">Save</button>';
    echo '</form>';
    return ob_get_clean();
  }

  private function render_entry_delete_form(int $entry_id, int $target_user_id, string $return_url): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_timeclock_delete_entry">';
    echo '<input type="hidden" name="entry_id" value="' . (int)$entry_id . '">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_timeclock_delete_entry_' . $entry_id, 'eum_timeclock_delete_entry_nonce');
    echo '<button type="submit" class="button button-small" onclick="return confirm(&quot;Delete this time entry?&quot;)">Delete</button>';
    echo '</form>';
    return ob_get_clean();
  }

  public function handle_clock_punch(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    if (!isset($_POST['eum_timeclock_punch_nonce']) || !wp_verify_nonce($_POST['eum_timeclock_punch_nonce'], 'eum_timeclock_punch')) wp_die('Security check failed.');
    $actor_user_id = get_current_user_id();
    $user_id = $this->clock_target_user_id_from_request($actor_user_id);
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if ($user_id <= 0) {
      $this->redirect_with_notice($return_url, 'You do not have permission to punch this user.', 'error');
    }

    $clock_status = $this->resolved_clock_status_for_user($user_id);
    $command = !empty($clock_status['is_clocked_in']) ? 'clock_out' : 'clock_in';
    $result = $this->record_clock_command($user_id, $actor_user_id, $command, 'portal');
    if (is_wp_error($result)) {
      $this->redirect_with_notice($return_url, $result->get_error_message(), 'error');
    }
    $message = $actor_user_id === $user_id
      ? (!empty($result['message']) ? (string)$result['message'] : 'Punch saved.')
      : ($command === 'clock_in' ? 'Clocked in for ' . $this->user_label($user_id) . '.' : 'Clocked out for ' . $this->user_label($user_id) . '.');
    $this->redirect_with_notice($return_url, $message, 'success');
  }

  public function handle_clock_in(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    if (!isset($_POST['eum_timeclock_clock_in_nonce']) || !wp_verify_nonce($_POST['eum_timeclock_clock_in_nonce'], 'eum_timeclock_clock_in')) wp_die('Security check failed.');
    $actor_user_id = get_current_user_id();
    $user_id = $this->clock_target_user_id_from_request($actor_user_id);
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if ($user_id <= 0) {
      $this->redirect_with_notice($return_url, 'You do not have permission to clock this user in.', 'error');
    }
    $result = $this->record_clock_command($user_id, $actor_user_id, 'clock_in', 'portal');
    if (is_wp_error($result)) {
      $this->redirect_with_notice($return_url, $result->get_error_message(), 'error');
    }
    $message = $actor_user_id === $user_id ? (string)($result['message'] ?? 'Clocked in.') : ('Clocked in for ' . $this->user_label($user_id) . '.');
    $this->redirect_with_notice($return_url, $message, 'success');
  }

  public function handle_clock_out(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    if (!isset($_POST['eum_timeclock_clock_out_nonce']) || !wp_verify_nonce($_POST['eum_timeclock_clock_out_nonce'], 'eum_timeclock_clock_out')) wp_die('Security check failed.');
    $actor_user_id = get_current_user_id();
    $user_id = $this->clock_target_user_id_from_request($actor_user_id);
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if ($user_id <= 0) {
      $this->redirect_with_notice($return_url, 'You do not have permission to clock this user out.', 'error');
    }
    $result = $this->record_clock_command($user_id, $actor_user_id, 'clock_out', 'portal');
    if (is_wp_error($result)) {
      $this->redirect_with_notice($return_url, $result->get_error_message(), 'error');
    }
    $message = $actor_user_id !== $user_id ? ('Clocked out for ' . $this->user_label($user_id) . '.') : (string)($result['message'] ?? 'Clocked out.');
    $this->redirect_with_notice($return_url, $message, 'success');
  }

  public function handle_save_entry(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if (!isset($_POST['eum_timeclock_save_entry_nonce']) || !wp_verify_nonce($_POST['eum_timeclock_save_entry_nonce'], 'eum_timeclock_save_entry_' . $target_user_id)) wp_die('Security check failed.');
    if (!$this->can_edit_timecard($actor_user_id, $target_user_id)) {
      $this->redirect_with_notice($return_url, 'You do not have permission to edit this timecard.', 'error');
    }

    $tz = $this->user_timezone($target_user_id);
    $start_value = trim((string)($_POST['entry_start'] ?? ''));
    $end_value = trim((string)($_POST['entry_end'] ?? ''));
    $clear_end = !empty($_POST['clear_entry_end']);
    $entry_kind = $this->normalize_entry_kind((string)($_POST['entry_kind'] ?? ($entry_id > 0 ? get_post_meta($entry_id, self::META_ENTRY_KIND, true) : 'punch_in')));

    $existing_start_utc = 0;
    $existing_end_utc = 0;
    if ($entry_id > 0) {
      $entry = get_post($entry_id);
      if (!$entry || $entry->post_type !== self::CPT_ENTRY || (int)get_post_meta($entry_id, self::META_USER_ID, true) !== $target_user_id) {
        $this->redirect_with_notice($return_url, 'Time entry not found.', 'error');
      }
      $existing_start_utc = (int)get_post_meta($entry_id, self::META_START_UTC, true);
      $existing_end_utc = (int)get_post_meta($entry_id, self::META_END_UTC, true);
    }

    $start_utc = $start_value !== '' ? $this->local_input_to_utc($start_value, $tz) : $existing_start_utc;
    $end_utc = $end_value !== '' ? $this->local_input_to_utc($end_value, $tz) : ($clear_end ? 0 : $existing_end_utc);

    if ($entry_kind === 'shift') {
      if ($start_utc <= 0) {
        $this->redirect_with_notice($return_url, 'A valid clock-in time is required.', 'error');
      }
      if ($start_value !== '' && $start_utc <= 0) {
        $this->redirect_with_notice($return_url, 'The clock-in time is invalid.', 'error');
      }
      if ($end_value !== '' && $end_utc <= 0) {
        $this->redirect_with_notice($return_url, 'The clock-out time is invalid.', 'error');
      }
    } elseif ($entry_kind === 'punch_in') {
      if ($start_utc <= 0 && $end_utc > 0) $start_utc = $end_utc;
      if ($start_utc <= 0) $this->redirect_with_notice($return_url, 'A valid punch-in time is required.', 'error');
      $end_utc = 0;
    } else {
      $event_utc = $end_utc > 0 ? $end_utc : $start_utc;
      if ($event_utc <= 0) $this->redirect_with_notice($return_url, 'A valid punch-out time is required.', 'error');
      $start_utc = $event_utc;
      $end_utc = $event_utc;
    }

    $entry_assignment = $this->assignment_from_request($_POST, $target_user_id, $start_utc, $end_utc, $entry_id);
    if (is_wp_error($entry_assignment)) {
      $this->redirect_with_notice($return_url, $entry_assignment->get_error_message(), 'error');
    }

    if ($entry_id <= 0 && $entry_kind === 'punch_in') {
      $this->maybe_insert_transfer_punch_out($target_user_id, $actor_user_id, $start_utc, $entry_assignment);
    }

    $result = $this->upsert_entry($entry_id, $target_user_id, $start_utc, $end_utc, $actor_user_id, $this->time_entry_source_key('portal', $actor_user_id, $target_user_id, true), $entry_assignment, $entry_kind);
    if (is_wp_error($result)) {
      $this->redirect_with_notice($return_url, $result->get_error_message(), 'error');
    }
    if ($entry_kind === 'punch_in') {
      $success = $entry_id > 0 ? 'Punch-in event updated.' : 'Punch-in event added.';
    } elseif ($entry_kind === 'punch_out') {
      $success = $entry_id > 0 ? 'Punch-out event updated.' : 'Punch-out event added.';
    } else {
      $success = $entry_id > 0 ? 'Legacy shift entry updated.' : 'Legacy shift entry added.';
    }
    $this->redirect_with_notice($return_url, $success, 'success');
  }

  public function handle_delete_entry(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $entry_id = (int)($_POST['entry_id'] ?? 0);
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if (!isset($_POST['eum_timeclock_delete_entry_nonce']) || !wp_verify_nonce($_POST['eum_timeclock_delete_entry_nonce'], 'eum_timeclock_delete_entry_' . $entry_id)) wp_die('Security check failed.');
    if (!$this->can_edit_timecard($actor_user_id, $target_user_id)) {
      $this->redirect_with_notice($return_url, 'You do not have permission to delete this time entry.', 'error');
    }

    $entry = get_post($entry_id);
    if (!$entry || $entry->post_type !== self::CPT_ENTRY || (int)get_post_meta($entry_id, self::META_USER_ID, true) !== $target_user_id) {
      $this->redirect_with_notice($return_url, 'Time entry not found.', 'error');
    }

    wp_delete_post($entry_id, true);
    $this->redirect_with_notice($return_url, 'Time entry deleted.', 'success');
  }

  public function handle_compensation_save(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    $change_id = (int)($_POST['change_id'] ?? 0);

    if (!isset($_POST['eum_compensation_nonce']) || !wp_verify_nonce($_POST['eum_compensation_nonce'], 'eum_compensation_save_' . $target_user_id)) {
      if (class_exists('EUM_Profiles')) {
        $this->redirect_with_profile_notice($return_url, 'Security check failed.', 'error');
      }
      wp_die('Security check failed.');
    }

    if (!self::can_manage_compensation($actor_user_id, $target_user_id)) {
      $this->redirect_with_profile_notice($return_url, 'You do not have permission to change compensation for this user.', 'error');
    }

    $payload = self::compensation_payload_from_request($_POST, 'eum_comp');
    $before_user = self::compensation_for_user($target_user_id);
    $before = [
      self::FIELD_COMP_TYPE => $before_user['type'],
      self::FIELD_COMP_AMOUNT => $before_user['amount'],
      self::FIELD_COMP_UNPAID_LABEL => $before_user['unpaid_label'],
    ];
    $after = $payload;

    if (wp_json_encode($before) === wp_json_encode($after)) {
      $this->redirect_with_profile_notice($return_url, 'No compensation changes were detected.', 'error');
    }

    $effective_at = EUM_Profiles::parse_datetime_local((string)($_POST['eum_comp_effective_at'] ?? ''));
    $reason = sanitize_text_field((string)($_POST['eum_comp_reason'] ?? 'Compensation Update'));
    if ($reason === '') $reason = 'Compensation Update';

    $rule = self::compensation_rule_context($actor_user_id, $target_user_id, $after);
    $requires_approval = !EUM_Access::is_it_or_hr($actor_user_id) && !empty($rule['requires_approval']);
    $approver_ids = $requires_approval ? array_values(array_unique(array_filter(array_map('intval', (array)($rule['approver_ids'] ?? [])), static fn($uid) => $uid > 0 && $uid !== $actor_user_id))) : [];
    $status = $requires_approval ? 'pending' : ($effective_at !== '' ? 'scheduled' : 'applied');

    $target_user = get_user_by('id', $target_user_id);
    $title = 'Compensation Change - ' . ($target_user ? ($target_user->display_name ?: $target_user->user_login) : ('User #' . $target_user_id));

    if ($change_id > 0) {
      $change = get_post($change_id);
      if (!$change || $change->post_type !== EUM_Profiles::CPT_CHANGE) {
        $this->redirect_with_profile_notice($return_url, 'The pending compensation change could not be found.', 'error');
      }
      $existing_after = get_post_meta($change_id, EUM_Profiles::META_AFTER, true);
      $existing_after = is_array($existing_after) ? $existing_after : [];
      $non_comp = array_diff(array_keys($existing_after), self::compensation_field_ids());
      if (!empty($non_comp)) {
        $this->redirect_with_profile_notice($return_url, 'That pending change also includes non-compensation fields and cannot be overwritten here.', 'error');
      }

      update_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, $actor_user_id);
      update_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, $approver_ids);
      update_post_meta($change_id, EUM_Profiles::META_BEFORE, $before);
      update_post_meta($change_id, EUM_Profiles::META_AFTER, $after);
      update_post_meta($change_id, EUM_Profiles::META_NOTE, $reason);
      update_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, $effective_at);
      update_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, current_time('mysql'));
      update_post_meta($change_id, EUM_Profiles::META_STATUS, $status);
      if ($status === 'applied') {
        if (!(new EUM_Profiles())->try_apply_change($change_id)) {
          $this->redirect_with_profile_notice($return_url, 'Could not apply the compensation change right now.', 'error');
        }
      }
      $this->redirect_with_profile_notice($return_url, $status === 'pending' ? 'Compensation change updated and re-submitted.' : ($status === 'scheduled' ? 'Compensation change updated and scheduled.' : 'Compensation updated.'), 'success');
    }

    $change_id = (int)wp_insert_post([
      'post_type' => EUM_Profiles::CPT_CHANGE,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $actor_user_id,
    ]);

    if ($change_id <= 0) {
      $this->redirect_with_profile_notice($return_url, 'Could not save the compensation change.', 'error');
    }

    update_post_meta($change_id, EUM_Profiles::META_TARGET_USER, $target_user_id);
    update_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, $actor_user_id);
    update_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, $approver_ids);
    update_post_meta($change_id, EUM_Profiles::META_BEFORE, $before);
    update_post_meta($change_id, EUM_Profiles::META_AFTER, $after);
    update_post_meta($change_id, EUM_Profiles::META_NOTE, $reason);
    update_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, $effective_at);
    update_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, current_time('mysql'));
    update_post_meta($change_id, EUM_Profiles::META_STATUS, $status);
    update_post_meta($change_id, EUM_Profiles::META_MANUAL, 0);

    if ($status === 'applied') {
      if (!(new EUM_Profiles())->try_apply_change($change_id)) {
        $this->redirect_with_profile_notice($return_url, 'Could not apply the compensation update right now.', 'error');
      }
      $this->redirect_with_profile_notice($return_url, 'Compensation updated.', 'success');
    }

    $this->redirect_with_profile_notice($return_url, $status === 'pending' ? 'Compensation change submitted for second-level approval.' : 'Compensation change scheduled.', 'success');
  }

  private static function editable_compensation_change_for_user(int $target_user_id, int $actor_user_id): int {
    $items = get_posts([
      'post_type' => EUM_Profiles::CPT_CHANGE,
      'post_status' => 'publish',
      'numberposts' => 20,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        ['key' => EUM_Profiles::META_TARGET_USER, 'value' => $target_user_id],
        ['key' => EUM_Profiles::META_STATUS, 'value' => ['pending', 'scheduled'], 'compare' => 'IN'],
      ],
    ]);

    foreach ((array)$items as $item) {
      $after = get_post_meta((int)$item->ID, EUM_Profiles::META_AFTER, true);
      $after = is_array($after) ? $after : [];
      if (empty($after)) continue;
      $keys = array_keys($after);
      $non_comp = array_diff($keys, self::compensation_field_ids());
      if (!empty($non_comp)) continue;
      $change_actor = (int)get_post_meta((int)$item->ID, EUM_Profiles::META_ACTOR_USER, true);
      if ($change_actor === $actor_user_id || EUM_Access::is_it_or_hr($actor_user_id)) {
        return (int)$item->ID;
      }
    }

    return 0;
  }

  private function entries_for_user(int $user_id): array {
    if ($user_id <= 0) return [];
    return get_posts([
      'post_type' => self::CPT_ENTRY,
      'post_status' => 'publish',
      'numberposts' => -1,
      'orderby' => 'meta_value_num',
      'meta_key' => self::META_START_UTC,
      'order' => 'ASC',
      'meta_query' => [
        ['key' => self::META_USER_ID, 'value' => $user_id],
      ],
    ]);
  }

  private function entries_for_user_between(int $user_id, int $start_utc, int $end_utc, bool $include_prior_punch_context = false): array {
    if ($user_id <= 0) return [];
    $items = $this->entries_for_user($user_id);

    $out = [];
    foreach ((array)$items as $item) {
      $entry_user_id = (int)get_post_meta((int)$item->ID, self::META_USER_ID, true);
      if ($entry_user_id !== $user_id) continue;
      $entry_start = (int)get_post_meta((int)$item->ID, self::META_START_UTC, true);
      $entry_end = (int)get_post_meta((int)$item->ID, self::META_END_UTC, true);
      $entry_kind = $this->entry_kind_for_entry((int)$item->ID);
      if ($entry_kind === 'punch_in' || $entry_kind === 'punch_out') {
        $event_utc = $entry_kind === 'punch_out' ? ($entry_end > 0 ? $entry_end : $entry_start) : $entry_start;
        if ($event_utc >= $end_utc) continue;
        if (!$include_prior_punch_context && $event_utc < $start_utc) continue;
        $out[] = $item;
        continue;
      }
      $compare_end = $entry_end > 0 ? $entry_end : time();
      if ($entry_start >= $end_utc) continue;
      if ($compare_end <= $start_utc) continue;
      $out[] = $item;
    }
    usort($out, static function($a, $b) {
      $a_start = (int)get_post_meta((int)$a->ID, self::META_START_UTC, true);
      $b_start = (int)get_post_meta((int)$b->ID, self::META_START_UTC, true);
      return $a_start <=> $b_start;
    });
    return $out;
  }

  private function build_timecard_metrics(int $user_id, array $entries, int $range_start_utc, int $range_end_utc, int $analysis_start_utc, int $analysis_end_utc): array {
    $tz = $this->user_timezone($user_id);
    $display_entries = [];
    $pieces_by_week = [];
    $boundaries = [$range_start_utc, $range_end_utc];
    $activity = $this->normalize_timecard_activity($user_id, $entries, $analysis_start_utc, $analysis_end_utc);
    $worked_segments_count = 0;

    $week_cursor = $analysis_start_utc;
    while ($week_cursor < $analysis_end_utc) {
      $next = $this->next_local_week_boundary_utc($user_id, $week_cursor);
      if ($next <= $week_cursor) break;
      $boundaries[] = $week_cursor;
      $boundaries[] = $next;
      $week_cursor = $next;
    }
    $boundaries = array_values(array_unique(array_filter(array_map('intval', $boundaries), static fn($ts) => $ts > 0)));
    sort($boundaries);

    foreach ($entries as $item) {
      $display = $this->raw_display_entry_for_range((int)$item->ID, $user_id, $tz, $range_start_utc, $range_end_utc);
      if ($display !== null) $display_entries[] = $display;
    }

    foreach ((array)($activity['segments'] ?? []) as $segment) {
      $segment_start = (int)($segment['start_utc'] ?? 0);
      $segment_end = (int)($segment['end_utc'] ?? 0);
      if ($segment_end <= $segment_start) continue;
      if ($segment_start < $range_end_utc && $segment_end > $range_start_utc) $worked_segments_count++;
      $clipped_start = max($segment_start, $analysis_start_utc);
      $clipped_end = min($segment_end, $analysis_end_utc);
      if ($clipped_end <= $clipped_start) continue;

      $pieces = $this->split_interval_by_boundaries($clipped_start, $clipped_end, $boundaries);
      foreach ($pieces as $piece) {
        $piece_start = (int)$piece['start'];
        $piece_end = (int)$piece['end'];
        if ($piece_end <= $piece_start) continue;
        $week_key = $this->week_key_for_utc($user_id, $piece_start);
        if (!isset($pieces_by_week[$week_key])) $pieces_by_week[$week_key] = [];
        $pieces_by_week[$week_key][] = [
          'start' => $piece_start,
          'end' => $piece_end,
          'in_display' => ($piece_start < $range_end_utc && $piece_end > $range_start_utc),
        ];
      }
    }

    $hours_total = 0.0;
    $regular_hours = 0.0;
    $overtime_hours = 0.0;

    foreach ($pieces_by_week as $week_key => $pieces) {
      usort($pieces, static fn($a, $b) => ((int)$a['start']) <=> ((int)$b['start']));
      $cumulative_seconds = 0;
      foreach ($pieces as $piece) {
        $seconds = max(0, (int)$piece['end'] - (int)$piece['start']);
        if ($seconds <= 0) continue;
        $regular_remaining = max(0, (self::REGULAR_WEEKLY_HOURS * HOUR_IN_SECONDS) - $cumulative_seconds);
        $regular_piece_seconds = min($seconds, $regular_remaining);
        $overtime_piece_seconds = max(0, $seconds - $regular_piece_seconds);
        if (!empty($piece['in_display'])) {
          $hours_total += $seconds / HOUR_IN_SECONDS;
          $regular_hours += $regular_piece_seconds / HOUR_IN_SECONDS;
          $overtime_hours += $overtime_piece_seconds / HOUR_IN_SECONDS;
        }
        $cumulative_seconds += $seconds;
      }
    }

    $compensation = self::compensation_for_user($user_id);
    $earnings = $this->calculate_earnings_for_range($compensation, $regular_hours, $overtime_hours, $range_start_utc, $range_end_utc);

    return [
      'display_entries' => $display_entries,
      'day_blocks' => $this->build_timecard_day_blocks($user_id, (array)($activity['segments'] ?? []), (array)($activity['orphan_events'] ?? []), $range_start_utc, $range_end_utc),
      'hours_total' => $hours_total,
      'regular_hours' => $regular_hours,
      'overtime_hours' => $overtime_hours,
      'earnings' => $earnings,
      'compensation' => $compensation,
      'worked_segments_count' => $worked_segments_count,
      'clock_status' => (array)($activity['clock_status'] ?? []),
    ];
  }

  private function normalize_timecard_activity(int $user_id, array $entries, int $analysis_start_utc, int $analysis_end_utc): array {
    $events = [];
    foreach ($entries as $item) {
      if (!$item instanceof WP_Post) continue;
      $entry_id = (int)$item->ID;
      if ($entry_id <= 0) continue;
      $entry_start = (int)get_post_meta($entry_id, self::META_START_UTC, true);
      $entry_end_raw = (int)get_post_meta($entry_id, self::META_END_UTC, true);
      $entry_kind = $this->entry_kind_for_entry($entry_id);
      $assignment = $this->assignment_for_entry($entry_id, $user_id, $entry_start, $entry_end_raw > 0 ? $entry_end_raw : max($entry_start + 1, time()));
      $source = sanitize_key((string)get_post_meta($entry_id, self::META_SOURCE, true));
      $updated_by = (int)get_post_meta($entry_id, self::META_UPDATED_BY, true);
      $updated_at = (string)get_post_meta($entry_id, self::META_UPDATED_AT, true);

      $base = [
        'entry_id' => $entry_id,
        'assignment' => $assignment,
        'job_path_term_id' => (int)($assignment['job_path_term_id'] ?? 0),
        'task_label' => (string)($assignment['task_label'] ?? ''),
        'cost_center_term_id' => (int)($assignment['cost_center_term_id'] ?? 0),
        'assignment_label' => $this->format_assignment_label((int)($assignment['job_path_term_id'] ?? 0), (string)($assignment['task_label'] ?? ''), $user_id, (int)($assignment['cost_center_term_id'] ?? 0)),
        'assignment_key' => $this->assignment_key((int)($assignment['job_path_term_id'] ?? 0), (string)($assignment['task_label'] ?? ''), (int)($assignment['cost_center_term_id'] ?? 0)),
        'source' => $source,
        'source_label' => $this->time_entry_source_label($source),
        'updated_by' => $updated_by,
        'updated_by_label' => $updated_by > 0 ? $this->user_label($updated_by) : '',
        'updated_at' => $updated_at,
      ];

      if ($entry_kind === 'shift') {
        if ($entry_start > 0) {
          $events[] = $base + [
            'event_kind' => 'punch_in',
            'event_utc' => $entry_start,
            'raw_entry_kind' => 'shift',
          ];
        }
        if ($entry_end_raw > 0) {
          $events[] = $base + [
            'event_kind' => 'punch_out',
            'event_utc' => max($entry_start, $entry_end_raw),
            'raw_entry_kind' => 'shift',
          ];
        }
        continue;
      }

      $events[] = $base + [
        'event_kind' => $entry_kind,
        'event_utc' => $entry_kind === 'punch_out' ? ($entry_end_raw > 0 ? $entry_end_raw : $entry_start) : $entry_start,
        'raw_entry_kind' => $entry_kind,
      ];
    }

    usort($events, function(array $a, array $b): int {
      $cmp = ((int)($a['event_utc'] ?? 0)) <=> ((int)($b['event_utc'] ?? 0));
      if ($cmp !== 0) return $cmp;
      $a_weight = (($a['event_kind'] ?? '') === 'punch_out') ? 0 : 1;
      $b_weight = (($b['event_kind'] ?? '') === 'punch_out') ? 0 : 1;
      if ($a_weight !== $b_weight) return $a_weight <=> $b_weight;
      return ((int)($a['entry_id'] ?? 0)) <=> ((int)($b['entry_id'] ?? 0));
    });

    $segments = [];
    $orphans = [];
    $open_in = null;

    foreach ($events as $event) {
      $event_kind = (string)($event['event_kind'] ?? '');
      $event_utc = (int)($event['event_utc'] ?? 0);
      if ($event_utc <= 0) continue;

      if ($event_kind === 'punch_in') {
        if (is_array($open_in)) {
          $orphans[] = $this->orphan_item_from_event($open_in, 'missing_clock_out');
        }
        $open_in = $event;
        continue;
      }

      if (is_array($open_in)) {
        $segments[] = $this->segment_from_events($open_in, $event, false, $analysis_end_utc);
        $open_in = null;
      } else {
        $orphans[] = $this->orphan_item_from_event($event, 'missing_clock_in');
      }
    }

    if (is_array($open_in)) {
      $segments[] = $this->segment_from_events($open_in, null, true, $analysis_end_utc);
    }

    $last_event = !empty($events) ? $events[count($events) - 1] : null;
    $clock_status = [
      'is_clocked_in' => is_array($open_in),
      'open_started_at' => is_array($open_in) ? (int)($open_in['event_utc'] ?? 0) : 0,
      'open_assignment' => is_array($open_in) ? (array)($open_in['assignment'] ?? []) : [],
      'open_entry_id' => is_array($open_in) ? (int)($open_in['entry_id'] ?? 0) : 0,
      'last_event_utc' => is_array($last_event) ? (int)($last_event['event_utc'] ?? 0) : 0,
      'last_event_kind' => is_array($last_event) ? (string)($last_event['event_kind'] ?? '') : '',
      'last_event_label' => is_array($last_event) ? $this->entry_kind_label((string)($last_event['event_kind'] ?? '')) : '',
      'last_entry_id' => is_array($last_event) ? (int)($last_event['entry_id'] ?? 0) : 0,
      'source' => is_array($last_event) ? (string)($last_event['source'] ?? '') : '',
      'source_label' => is_array($last_event) ? (string)($last_event['source_label'] ?? '') : '',
    ];

    return [
      'events' => $events,
      'segments' => $segments,
      'orphan_events' => $orphans,
      'clock_status' => $clock_status,
    ];
  }

  private function segment_from_events(array $start_event, ?array $end_event, bool $is_open, int $analysis_end_utc): array {
    $start_utc = (int)($start_event['event_utc'] ?? 0);
    $candidate_end = $is_open ? min(time(), $analysis_end_utc) : (int)($end_event['event_utc'] ?? 0);
    $end_utc = max($start_utc, $candidate_end);
    return [
      'start_utc' => $start_utc,
      'end_utc' => $end_utc,
      'is_open' => $is_open,
      'start_entry_id' => (int)($start_event['entry_id'] ?? 0),
      'end_entry_id' => $end_event ? (int)($end_event['entry_id'] ?? 0) : 0,
      'job_path_term_id' => (int)($start_event['job_path_term_id'] ?? 0),
      'task_label' => (string)($start_event['task_label'] ?? ''),
      'cost_center_term_id' => (int)($start_event['cost_center_term_id'] ?? 0),
      'assignment_label' => (string)($start_event['assignment_label'] ?? ''),
      'assignment_key' => (string)($start_event['assignment_key'] ?? ''),
      'source_label' => (string)($start_event['source_label'] ?? ''),
      'start_source_label' => (string)($start_event['source_label'] ?? ''),
      'end_source_label' => $end_event ? (string)($end_event['source_label'] ?? '') : '',
      'start_updated_by_label' => (string)($start_event['updated_by_label'] ?? ''),
      'end_updated_by_label' => $end_event ? (string)($end_event['updated_by_label'] ?? '') : '',
      'start_updated_at' => (string)($start_event['updated_at'] ?? ''),
      'end_updated_at' => $end_event ? (string)($end_event['updated_at'] ?? '') : '',
    ];
  }

  private function orphan_item_from_event(array $event, string $reason): array {
    return [
      'entry_id' => (int)($event['entry_id'] ?? 0),
      'event_kind' => (string)($event['event_kind'] ?? ''),
      'event_utc' => (int)($event['event_utc'] ?? 0),
      'job_path_term_id' => (int)($event['job_path_term_id'] ?? 0),
      'task_label' => (string)($event['task_label'] ?? ''),
      'cost_center_term_id' => (int)($event['cost_center_term_id'] ?? 0),
      'assignment_label' => (string)($event['assignment_label'] ?? ''),
      'assignment_key' => (string)($event['assignment_key'] ?? ''),
      'source_label' => (string)($event['source_label'] ?? ''),
      'updated_by_label' => (string)($event['updated_by_label'] ?? ''),
      'updated_at' => (string)($event['updated_at'] ?? ''),
      'reason' => $reason,
    ];
  }

  private function raw_display_entry_for_range(int $entry_id, int $user_id, DateTimeZone $tz, int $range_start_utc, int $range_end_utc): ?array {
    if ($entry_id <= 0) return null;
    $entry_start = (int)get_post_meta($entry_id, self::META_START_UTC, true);
    $entry_end_raw = (int)get_post_meta($entry_id, self::META_END_UTC, true);
    $entry_kind = $this->entry_kind_for_entry($entry_id);
    $entry_end_compare = $entry_kind === 'shift' ? ($entry_end_raw > 0 ? $entry_end_raw : time()) : (($entry_kind === 'punch_out' && $entry_end_raw > 0) ? $entry_end_raw : $entry_start);
    if ($entry_start >= $range_end_utc || $entry_end_compare < $range_start_utc) return null;

    $assignment = $this->assignment_for_entry($entry_id, $user_id, $entry_start, $entry_end_raw > 0 ? $entry_end_raw : max($entry_start + 1, time()));
    $assignment_labels = (array)($assignment['labels'] ?? []);
    $assignment_html = implode('<br>', array_map(static fn($label) => esc_html($label), $assignment_labels));
    $source = sanitize_key((string)get_post_meta($entry_id, self::META_SOURCE, true));
    $hours = 0.0;
    $end_label = $entry_kind === 'punch_in' ? 'Punch In Only' : 'Punch Out Only';

    if ($entry_kind === 'shift') {
      $display_start = max($entry_start, $range_start_utc);
      $display_end = min($entry_end_compare, $range_end_utc);
      $hours = $display_end > $display_start ? (($display_end - $display_start) / HOUR_IN_SECONDS) : 0.0;
      $end_label = $entry_end_raw > 0 ? $this->format_local_ts($display_end, $tz) : 'Open';
      $start_label = $this->format_local_ts($display_start, $tz);
    } else {
      $event_utc = $entry_kind === 'punch_out' ? ($entry_end_raw > 0 ? $entry_end_raw : $entry_start) : $entry_start;
      $start_label = $this->format_local_ts($event_utc, $tz);
    }

    return [
      'id' => $entry_id,
      'entry_kind' => $entry_kind,
      'entry_kind_label' => $this->entry_kind_label($entry_kind),
      'start_label' => $start_label,
      'end_label' => $end_label,
      'assignment_html' => $assignment_html,
      'job_path_term_id' => (int)($assignment['job_path_term_id'] ?? 0),
      'task_label' => (string)($assignment['task_label'] ?? ''),
      'cost_center_term_id' => (int)($assignment['cost_center_term_id'] ?? 0),
      'source_label' => $this->time_entry_source_label($source),
      'hours' => max(0, $hours),
      'start_input' => $this->format_local_input($entry_start, $tz),
      'end_input' => $entry_end_raw > 0 ? $this->format_local_input($entry_end_raw, $tz) : '',
    ];
  }

  private function calculate_earnings_for_range(array $compensation, float $regular_hours, float $overtime_hours, int $range_start_utc, int $range_end_utc): float {
    $type = (string)($compensation['type'] ?? 'unpaid');
    $amount = (float)($compensation['amount'] ?? 0);
    if ($type === 'hourly') {
      return ($regular_hours * $amount) + ($overtime_hours * $amount * 1.5);
    }
    if ($type === 'day_rate') {
      $hourly = (float)($compensation['hourly_rate'] ?? 0);
      return ($regular_hours * $hourly) + ($overtime_hours * $hourly * 1.5);
    }
    if ($type === 'monthly' || $type === 'yearly') {
      $seconds = max(0, $range_end_utc - $range_start_utc);
      if ($seconds <= 0) return 0.0;
      $annualized = (float)($compensation['annualized_amount'] ?? 0);
      return $annualized * ($seconds / (365 * DAY_IN_SECONDS));
    }
    return 0.0;
  }

  public function labor_rows_for_range(int $range_start_utc, int $range_end_utc, array $args = []): array {
    if ($range_start_utc <= 0 || $range_end_utc <= $range_start_utc) return [];

    $filter_user_ids = array_values(array_map('intval', (array)($args['user_ids'] ?? [])));
    $has_user_filter = array_key_exists('user_ids', $args);
    $scope_job_path_term_id = (int)($args['job_path_term_id'] ?? 0);
    $include_descendants = !empty($args['include_descendants']);
    $scope_finance_path_term_id = (int)($args['finance_path_term_id'] ?? 0);

    $entry_ids = get_posts([
      'post_type' => self::CPT_ENTRY,
      'post_status' => 'publish',
      'numberposts' => -1,
      'fields' => 'ids',
      'orderby' => 'meta_value_num',
      'meta_key' => self::META_START_UTC,
      'order' => 'ASC',
    ]);

    $entries_by_user = [];
    foreach ((array)$entry_ids as $entry_id) {
      $entry_id = (int)$entry_id;
      if ($entry_id <= 0) continue;
      $user_id = (int)get_post_meta($entry_id, self::META_USER_ID, true);
      if ($user_id <= 0) continue;
      if ($has_user_filter && !in_array($user_id, $filter_user_ids, true)) continue;
      if (!isset($entries_by_user[$user_id])) $entries_by_user[$user_id] = [];
      $entries_by_user[$user_id][] = get_post($entry_id);
    }

    $rows = [];
    foreach ($entries_by_user as $user_id => $user_entries) {
      $activity = $this->normalize_timecard_activity((int)$user_id, (array)$user_entries, $range_start_utc, $range_end_utc);
      $entries = [];
      foreach ((array)($activity['segments'] ?? []) as $segment) {
        $entry_start = max((int)($segment['start_utc'] ?? 0), $range_start_utc);
        $entry_end = min((int)($segment['end_utc'] ?? 0), $range_end_utc);
        if ($entry_end <= $entry_start) continue;
        $job_path_term_id = (int)($segment['job_path_term_id'] ?? 0);
        $finance_path_term_id = (int)($segment['cost_center_term_id'] ?? 0);
        if (!$this->job_path_matches_scope($job_path_term_id, $scope_job_path_term_id, $include_descendants)) continue;
        if ($scope_finance_path_term_id > 0 && $finance_path_term_id !== $scope_finance_path_term_id) continue;
        $entries[] = [
          'entry_id' => (int)($segment['start_entry_id'] ?? 0),
          'user_id' => (int)$user_id,
          'start_utc' => $entry_start,
          'end_utc' => $entry_end,
          'job_path_term_id' => $job_path_term_id,
          'task_label' => sanitize_text_field((string)($segment['task_label'] ?? '')),
          'finance_path_term_id' => $finance_path_term_id,
          'assignment_labels' => [(string)($segment['assignment_label'] ?? '')],
        ];
      }
      usort($entries, static fn($a, $b) => ((int)$a['start_utc']) <=> ((int)$b['start_utc']));
      $compensation = self::compensation_for_user((int)$user_id);
      $weekly_seconds = [];
      foreach ($entries as $entry) {
        $week_key = $this->week_key_for_utc((int)$user_id, (int)$entry['start_utc']);
        $weekly_seconds[$week_key] = (int)($weekly_seconds[$week_key] ?? 0);
        $seconds = max(0, (int)$entry['end_utc'] - (int)$entry['start_utc']);
        if ($seconds <= 0) continue;
        $regular_remaining = max(0, (self::REGULAR_WEEKLY_HOURS * HOUR_IN_SECONDS) - $weekly_seconds[$week_key]);
        $regular_seconds = min($seconds, $regular_remaining);
        $overtime_seconds = max(0, $seconds - $regular_seconds);
        $weekly_seconds[$week_key] += $seconds;
        $regular_hours = $regular_seconds / HOUR_IN_SECONDS;
        $overtime_hours = $overtime_seconds / HOUR_IN_SECONDS;
        $rows[] = [
          'entry_id' => (int)$entry['entry_id'],
          'user_id' => (int)$user_id,
          'start_utc' => (int)$entry['start_utc'],
          'end_utc' => (int)$entry['end_utc'],
          'job_path_term_id' => (int)$entry['job_path_term_id'],
          'task_label' => (string)$entry['task_label'],
          'finance_path_term_id' => (int)$entry['finance_path_term_id'],
          'hours' => round($seconds / HOUR_IN_SECONDS, 4),
          'regular_hours' => round($regular_hours, 4),
          'overtime_hours' => round($overtime_hours, 4),
          'cost' => round($this->calculate_earnings_for_range($compensation, $regular_hours, $overtime_hours, (int)$entry['start_utc'], (int)$entry['end_utc']), 2),
          'path_label' => (int)$entry['job_path_term_id'] > 0 && class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', (int)$entry['job_path_term_id'], ' / ') : '',
          'billing_label' => (int)$entry['finance_path_term_id'] > 0 ? $this->cost_center_label((int)$entry['finance_path_term_id']) : (string)$entry['task_label'],
          'assignment_labels' => (array)$entry['assignment_labels'],
        ];
      }
    }

    usort($rows, static fn($a, $b) => ((int)($a['start_utc'] ?? 0)) <=> ((int)($b['start_utc'] ?? 0)));
    return $rows;
  }

  private function render_timecard_day_blocks(array $day_blocks, int $user_id, DateTimeZone $tz): string {
    if (empty($day_blocks)) return '';
    ob_start();
    echo '<div style="display:grid;gap:16px;">';
    foreach ($day_blocks as $block) {
      $items = (array)($block['items'] ?? []);
      echo '<div style="display:grid;grid-template-columns:minmax(104px,120px) 1fr;gap:18px;align-items:stretch;padding:18px;border:1px solid #e2e4e7;border-radius:18px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);box-shadow:0 10px 26px rgba(15,23,42,0.05);">';
      echo '<div style="display:flex;flex-direction:column;justify-content:center;align-items:center;border-radius:14px;background:#eef2f7;padding:12px;text-align:center;">';
      echo '<div style="font-size:12px;font-weight:700;letter-spacing:0.08em;color:#475569;text-transform:uppercase;">' . esc_html((string)($block['day_name'] ?? '')) . '</div>';
      echo '<div style="margin-top:6px;font-size:14px;font-weight:700;color:#0f172a;">' . esc_html((string)($block['month_label'] ?? '')) . '</div>';
      echo '<div style="font-size:38px;line-height:1;font-weight:800;color:#0f172a;">' . esc_html((string)($block['day_number'] ?? '')) . '</div>';
      echo '</div>';
      echo '<div>';
      if (!empty($block['transfer_label'])) {
        echo '<div style="display:inline-block;margin-bottom:10px;padding:5px 10px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">' . esc_html((string)$block['transfer_label']) . '</div>';
      }
      if (empty($items)) {
        echo '<p style="margin:0;color:#64748b;">No punches recorded.</p>';
      } else {
        foreach ($items as $index => $item) {
          $is_last = $index === (count($items) - 1);
          echo '<div style="' . ($is_last ? '' : 'margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid #e5e7eb;') . '">';
          if (($item['type'] ?? '') === 'segment') {
            echo '<div><strong>IN:</strong> ' . esc_html($this->format_local_time((int)($item['start_utc'] ?? 0), $tz)) . '</div>';
            echo '<div><strong>OUT:</strong> ' . esc_html(!empty($item['is_open']) ? 'Open' : $this->format_local_time((int)($item['end_utc'] ?? 0), $tz)) . '</div>';
          } elseif (($item['event_kind'] ?? '') === 'punch_out') {
            echo '<div><strong>OUT:</strong> ' . esc_html($this->format_local_time((int)($item['event_utc'] ?? 0), $tz)) . '</div>';
            echo '<div><strong>PAIRING:</strong> Missing clock-in</div>';
          } else {
            echo '<div><strong>IN:</strong> ' . esc_html($this->format_local_time((int)($item['event_utc'] ?? 0), $tz)) . '</div>';
            echo '<div><strong>PAIRING:</strong> Missing clock-out</div>';
          }
          echo '<div><strong>JOB TASK:</strong> ' . esc_html((string)($item['assignment_label'] ?? 'Not scheduled')) . '</div>';
          if (!empty($item['meta_lines'])) {
            echo '<div style="margin-top:6px;font-size:12px;color:#64748b;">' . esc_html(implode(' | ', array_filter(array_map('strval', (array)$item['meta_lines'])))) . '</div>';
          }
          echo '</div>';
        }
      }
      echo '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #cbd5e1;font-size:13px;font-weight:700;color:#0f172a;"><strong>WTD:</strong> ' . esc_html(number_format((float)($block['wtd_hours'] ?? 0), 2)) . ' Hours</div>';
      echo '</div>';
      echo '</div>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function build_timecard_day_blocks(int $user_id, array $segments, array $orphan_events, int $range_start_utc, int $range_end_utc): array {
    $tz = $this->user_timezone($user_id);
    $day_items = [];
    $daily_hours = [];

    foreach ($segments as $segment) {
      $slices = $this->slice_segment_by_local_day((int)($segment['start_utc'] ?? 0), (int)($segment['end_utc'] ?? 0), $tz, $range_start_utc, $range_end_utc);
      $slice_count = count($slices);
      foreach ($slices as $slice_index => $slice) {
        $local_date = (string)$slice['local_date'];
        if (!isset($day_items[$local_date])) $day_items[$local_date] = [];
        if (!isset($daily_hours[$local_date])) $daily_hours[$local_date] = 0.0;
        $daily_hours[$local_date] += max(0, ((int)$slice['end_utc'] - (int)$slice['start_utc']) / HOUR_IN_SECONDS);
        $day_items[$local_date][] = [
          'type' => 'segment',
          'sort_utc' => (int)$slice['start_utc'],
          'start_utc' => (int)$slice['start_utc'],
          'end_utc' => (int)$slice['end_utc'],
          'is_open' => !empty($segment['is_open']) && $slice_index === ($slice_count - 1),
          'assignment_label' => (string)($segment['assignment_label'] ?? 'Not scheduled'),
          'assignment_key' => (string)($segment['assignment_key'] ?? ''),
          'meta_lines' => $this->segment_meta_lines($segment, $slice_index === ($slice_count - 1)),
        ];
      }
    }

    foreach ($orphan_events as $event) {
      $event_utc = (int)($event['event_utc'] ?? 0);
      if ($event_utc < $range_start_utc || $event_utc >= $range_end_utc) continue;
      $local_date = (new DateTimeImmutable('@' . $event_utc))->setTimezone($tz)->format('Y-m-d');
      if (!isset($day_items[$local_date])) $day_items[$local_date] = [];
      $day_items[$local_date][] = [
        'type' => 'orphan',
        'sort_utc' => $event_utc,
        'event_kind' => (string)($event['event_kind'] ?? ''),
        'event_utc' => $event_utc,
        'assignment_label' => (string)($event['assignment_label'] ?? 'Not scheduled'),
        'assignment_key' => (string)($event['assignment_key'] ?? ''),
        'meta_lines' => $this->orphan_meta_lines($event),
      ];
    }

    $cursor = (new DateTimeImmutable('@' . $range_start_utc))->setTimezone($tz)->setTime(0, 0, 0);
    $end_local = (new DateTimeImmutable('@' . max($range_start_utc, $range_end_utc - 1)))->setTimezone($tz)->setTime(0, 0, 0);
    $ordered_dates = [];
    while ($cursor <= $end_local) {
      $ordered_dates[] = $cursor->format('Y-m-d');
      $cursor = $cursor->modify('+1 day');
    }

    $week_totals = [];
    $wtd_by_date = [];
    foreach ($ordered_dates as $local_date) {
      $local_dt = new DateTimeImmutable($local_date . ' 00:00:00', $tz);
      $week_key = $this->week_start_local($local_dt)->format('Y-m-d');
      if (!isset($week_totals[$week_key])) $week_totals[$week_key] = 0.0;
      $week_totals[$week_key] += (float)($daily_hours[$local_date] ?? 0.0);
      $wtd_by_date[$local_date] = $week_totals[$week_key];
    }

    $blocks = [];
    foreach ($ordered_dates as $local_date) {
      $items = (array)($day_items[$local_date] ?? []);
      usort($items, static fn($a, $b) => ((int)($a['sort_utc'] ?? 0)) <=> ((int)($b['sort_utc'] ?? 0)));
      $packed = [];
      $current = null;
      $previous_item = null;
      foreach ($items as $item) {
        $should_split = false;
        $transfer_label = '';
        if (is_array($current) && count((array)($current['items'] ?? [])) >= 2) {
          $should_split = true;
        } elseif (is_array($current) && (string)($current['assignment_key'] ?? '') !== '' && (string)($item['assignment_key'] ?? '') !== '' && (string)$current['assignment_key'] !== (string)$item['assignment_key']) {
          $should_split = true;
          if (is_array($previous_item)) {
            $gap = abs(((int)($item['sort_utc'] ?? 0)) - ((int)($previous_item['end_utc'] ?? $previous_item['event_utc'] ?? 0)));
            if ($gap <= 60) $transfer_label = 'Transfer';
          }
        }
        if ($should_split && is_array($current)) {
          $packed[] = $current;
          $current = null;
        }
        if (!is_array($current)) {
          $current = [
            'items' => [],
            'assignment_key' => (string)($item['assignment_key'] ?? ''),
            'transfer_label' => $transfer_label,
          ];
        }
        $current['items'][] = $item;
        if ($current['assignment_key'] === '') $current['assignment_key'] = (string)($item['assignment_key'] ?? '');
        $previous_item = $item;
      }
      if (is_array($current)) $packed[] = $current;
      if (empty($packed)) $packed[] = ['items' => [], 'assignment_key' => '', 'transfer_label' => ''];

      $local_dt = new DateTimeImmutable($local_date . ' 00:00:00', $tz);
      foreach ($packed as $packed_block) {
        $blocks[] = [
          'local_date' => $local_date,
          'day_name' => strtoupper($local_dt->format('l')),
          'month_label' => strtoupper($local_dt->format('M')),
          'day_number' => $local_dt->format('d'),
          'items' => (array)($packed_block['items'] ?? []),
          'transfer_label' => (string)($packed_block['transfer_label'] ?? ''),
          'wtd_hours' => (float)($wtd_by_date[$local_date] ?? 0.0),
        ];
      }
    }

    return $blocks;
  }

  private function slice_segment_by_local_day(int $start_utc, int $end_utc, DateTimeZone $tz, int $range_start_utc, int $range_end_utc): array {
    $clip_start = max($start_utc, $range_start_utc);
    $clip_end = min($end_utc, $range_end_utc);
    if ($clip_end <= $clip_start) return [];

    $out = [];
    $cursor = $clip_start;
    while ($cursor < $clip_end) {
      $local = (new DateTimeImmutable('@' . $cursor))->setTimezone($tz);
      $next_boundary_utc = $local->setTime(0, 0, 0)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
      $slice_end = min($clip_end, $next_boundary_utc);
      $out[] = [
        'local_date' => $local->format('Y-m-d'),
        'start_utc' => $cursor,
        'end_utc' => $slice_end,
      ];
      if ($slice_end <= $cursor) break;
      $cursor = $slice_end;
    }

    return $out;
  }

  private function segment_meta_lines(array $segment, bool $is_last_slice = false): array {
    $lines = [];
    $start_source = trim((string)($segment['start_source_label'] ?? $segment['source_label'] ?? ''));
    $end_source = trim((string)($segment['end_source_label'] ?? ''));
    if ($start_source !== '') $lines[] = 'In Source: ' . $start_source;
    if ($end_source !== '' && $is_last_slice) $lines[] = 'Out Source: ' . $end_source;
    $start_editor = trim((string)($segment['start_updated_by_label'] ?? ''));
    $end_editor = trim((string)($segment['end_updated_by_label'] ?? ''));
    if ($start_editor !== '') $lines[] = 'Edited By: ' . $start_editor;
    if ($end_editor !== '' && $is_last_slice && $end_editor !== $start_editor) $lines[] = 'Out Edited By: ' . $end_editor;
    return $lines;
  }

  private function orphan_meta_lines(array $event): array {
    $lines = [];
    $source = trim((string)($event['source_label'] ?? ''));
    if ($source !== '') $lines[] = 'Source: ' . $source;
    $editor = trim((string)($event['updated_by_label'] ?? ''));
    if ($editor !== '') $lines[] = 'Edited By: ' . $editor;
    return $lines;
  }

  private function assignment_key(int $job_path_term_id, string $task_label, int $cost_center_term_id): string {
    return implode('|', [$job_path_term_id, sanitize_text_field($task_label), $cost_center_term_id]);
  }

  private function entry_kind_label(string $entry_kind): string {
    $entry_kind = $this->normalize_entry_kind($entry_kind);
    if ($entry_kind === 'shift') return 'Legacy Shift';
    return $entry_kind === 'punch_out' ? 'Punch Out' : 'Punch In';
  }

  private function time_entry_source_label(string $source): string {
    $source = sanitize_key($source);
    $map = [
      'clock' => 'Portal Clock',
      'manager_clock' => 'Portal Manager Clock',
      'manual' => 'Portal Manual Entry',
      'manager_edit' => 'Portal Manager Edit',
      'portal_clock' => 'Portal Clock',
      'portal_manager_clock' => 'Portal Manager Clock',
      'portal_edit' => 'Portal Edit',
      'portal_manager_edit' => 'Portal Manager Edit',
      'discord' => 'Discord Clock',
      'discord_clock' => 'Discord Clock',
      'discord_manager' => 'Discord Manager Clock',
      'discord_manager_clock' => 'Discord Manager Clock',
      'discord_edit' => 'Discord Edit',
      'discord_manager_edit' => 'Discord Manager Edit',
      'system' => 'System',
      'system_transfer' => 'System Transfer',
    ];
    if (isset($map[$source])) return $map[$source];
    if ($source === '') return 'Portal';
    return ucwords(str_replace('_', ' ', $source));
  }

  private function maybe_insert_transfer_punch_out(int $user_id, int $actor_user_id, int $new_punch_in_utc, array $new_assignment): void {
    if ($user_id <= 0 || $new_punch_in_utc <= 0) return;
    $clock_status = $this->resolved_clock_status_for_user($user_id);
    if (empty($clock_status['is_clocked_in'])) return;
    $open_started_at = (int)($clock_status['open_started_at'] ?? 0);
    if ($open_started_at <= 0 || $new_punch_in_utc < $open_started_at) return;
    $open_assignment = !empty($clock_status['open_assignment']) && is_array($clock_status['open_assignment'])
      ? (array)$clock_status['open_assignment']
      : [];
    if ($this->assignment_key((int)($open_assignment['job_path_term_id'] ?? 0), (string)($open_assignment['task_label'] ?? ''), (int)($open_assignment['cost_center_term_id'] ?? 0)) === $this->assignment_key((int)($new_assignment['job_path_term_id'] ?? 0), (string)($new_assignment['task_label'] ?? ''), (int)($new_assignment['cost_center_term_id'] ?? 0))) {
      return;
    }
    $this->upsert_entry(0, $user_id, $new_punch_in_utc, $new_punch_in_utc, $actor_user_id, 'system_transfer', $open_assignment, 'punch_out');
  }

  private function record_clock_command(int $user_id, int $actor_user_id, string $command, string $application = 'portal') {
    $user_id = (int)$user_id;
    $actor_user_id = (int)$actor_user_id;
    $command = sanitize_key($command);
    if ($user_id <= 0) return new WP_Error('invalid_user', 'A valid user is required.');
    if (!in_array($command, ['clock_in', 'clock_out'], true)) {
      return new WP_Error('invalid_command', 'The clock command is not valid.');
    }

    $clock_status = $this->resolved_clock_status_for_user($user_id);
    $now = time();
    $source = $this->time_entry_source_key($application, $actor_user_id, $user_id, false);

    if ($command === 'clock_in') {
      if (!empty($clock_status['is_clocked_in'])) {
        return new WP_Error('already_clocked_in', 'You are already clocked in.');
      }
      if ($actor_user_id === $user_id && !$this->user_can_clock_in_now($user_id, $now, $clock_rule_message)) {
        return new WP_Error('clock_in_not_allowed', $clock_rule_message);
      }
      $assignment = $this->default_entry_assignment_for_interval($user_id, $now, 0);
      $entry_id = $this->upsert_entry(0, $user_id, $now, 0, $actor_user_id, $source, $assignment, 'punch_in');
      if (is_wp_error($entry_id)) return $entry_id;
      return [
        'entry_id' => (int)$entry_id,
        'command' => 'clock_in',
        'message' => 'Clocked in.',
      ];
    }

    if (empty($clock_status['is_clocked_in'])) {
      return new WP_Error('not_clocked_in', 'There is no active clock-in to clock out.');
    }

    $assignment = !empty($clock_status['open_assignment']) && is_array($clock_status['open_assignment'])
      ? (array)$clock_status['open_assignment']
      : $this->default_entry_assignment_for_interval($user_id, $now, $now);
    $entry_id = $this->upsert_entry(0, $user_id, $now, $now, $actor_user_id, $source, $assignment, 'punch_out');
    if (is_wp_error($entry_id)) return $entry_id;
    return [
      'entry_id' => (int)$entry_id,
      'command' => 'clock_out',
      'message' => 'Clocked out.',
    ];
  }

  private function time_entry_source_key(string $application, int $actor_user_id, int $target_user_id, bool $is_edit): string {
    $application = sanitize_key($application);
    if ($application === '') $application = 'portal';
    if ($is_edit) {
      return $actor_user_id === $target_user_id ? $application . '_edit' : $application . '_manager_edit';
    }
    return $actor_user_id === $target_user_id ? $application . '_clock' : $application . '_manager_clock';
  }

  private function resolved_clock_status_for_user(int $user_id): array {
    $entries = $this->entries_for_user($user_id);
    $activity = $this->normalize_timecard_activity($user_id, $entries, 0, time() + DAY_IN_SECONDS);
    return (array)($activity['clock_status'] ?? [
      'is_clocked_in' => false,
      'open_started_at' => 0,
      'open_assignment' => [],
      'open_entry_id' => 0,
      'last_event_utc' => 0,
      'last_event_kind' => '',
      'last_event_label' => '',
      'last_entry_id' => 0,
      'source' => '',
      'source_label' => '',
    ]);
  }

  private function upsert_entry(int $entry_id, int $user_id, int $start_utc, int $end_utc, int $actor_user_id, string $source = 'manual', array $assignment = [], string $entry_kind = 'shift') {
    if ($user_id <= 0) return new WP_Error('invalid_user', 'A valid user is required.');
    if ($start_utc <= 0) return new WP_Error('invalid_start', 'A valid start time is required.');
    $entry_kind = $this->normalize_entry_kind($entry_kind);
    if ($end_utc > 0 && ($entry_kind === 'shift' ? $end_utc <= $start_utc : $end_utc < $start_utc)) {
      return new WP_Error('invalid_end', 'The end time must be later than the start time.');
    }

    $overlap = $entry_kind === 'shift' ? $this->find_overlapping_entry($user_id, $start_utc, $end_utc > 0 ? $end_utc : time(), $entry_id) : 0;
    if ($overlap > 0) {
      return new WP_Error('overlap', 'That time entry overlaps an existing shift.');
    }

    if ($entry_id > 0) {
      $entry = get_post($entry_id);
      if (!$entry || $entry->post_type !== self::CPT_ENTRY) return new WP_Error('missing_entry', 'The time entry no longer exists.');
      wp_update_post([
        'ID' => $entry_id,
        'post_title' => 'Time Entry - User #' . $user_id . ' - ' . gmdate('Y-m-d H:i', $start_utc),
      ]);
    } else {
      $entry_id = (int)wp_insert_post([
        'post_type' => self::CPT_ENTRY,
        'post_status' => 'publish',
        'post_title' => 'Time Entry - User #' . $user_id . ' - ' . gmdate('Y-m-d H:i', $start_utc),
        'post_author' => $actor_user_id > 0 ? $actor_user_id : $user_id,
      ]);
      if ($entry_id <= 0) return new WP_Error('entry_save_failed', 'Could not save the time entry.');
    }

    update_post_meta($entry_id, self::META_USER_ID, $user_id);
    update_post_meta($entry_id, self::META_START_UTC, $start_utc);
    if ($end_utc > 0) update_post_meta($entry_id, self::META_END_UTC, $end_utc);
    else delete_post_meta($entry_id, self::META_END_UTC);
    update_post_meta($entry_id, self::META_SOURCE, sanitize_key($source));
    update_post_meta($entry_id, self::META_ENTRY_KIND, $entry_kind);
    $job_path_term_id = (int)($assignment['job_path_term_id'] ?? 0);
    $task_label = sanitize_text_field((string)($assignment['task_label'] ?? ''));
    $cost_center_term_id = (int)($assignment['cost_center_term_id'] ?? 0);
    if ($job_path_term_id > 0) update_post_meta($entry_id, self::META_JOB_PATH_TERM_ID, $job_path_term_id);
    else delete_post_meta($entry_id, self::META_JOB_PATH_TERM_ID);
    if ($task_label !== '') update_post_meta($entry_id, self::META_TASK_LABEL, $task_label);
    else delete_post_meta($entry_id, self::META_TASK_LABEL);
    if ($cost_center_term_id > 0) update_post_meta($entry_id, self::META_COST_CENTER_TERM_ID, $cost_center_term_id);
    else delete_post_meta($entry_id, self::META_COST_CENTER_TERM_ID);
    update_post_meta($entry_id, self::META_UPDATED_BY, $actor_user_id > 0 ? $actor_user_id : $user_id);
    update_post_meta($entry_id, self::META_UPDATED_AT, current_time('mysql'));

    return $entry_id;
  }

  private function find_overlapping_entry(int $user_id, int $start_utc, int $end_utc, int $exclude_entry_id = 0): int {
    $entries = get_posts([
      'post_type' => self::CPT_ENTRY,
      'post_status' => 'publish',
      'numberposts' => -1,
      'fields' => 'ids',
      'meta_query' => [
        ['key' => self::META_USER_ID, 'value' => $user_id],
      ],
    ]);
    foreach ((array)$entries as $entry_id) {
      $entry_id = (int)$entry_id;
      if ($exclude_entry_id > 0 && $entry_id === $exclude_entry_id) continue;
      if ($this->entry_kind_for_entry($entry_id) !== 'shift') continue;
      $entry_start = (int)get_post_meta($entry_id, self::META_START_UTC, true);
      $entry_end_raw = (int)get_post_meta($entry_id, self::META_END_UTC, true);
      $entry_end = $entry_end_raw > 0 ? $entry_end_raw : time();
      if ($entry_start < $end_utc && $entry_end > $start_utc) return $entry_id;
    }
    return 0;
  }

  private function open_entry_for_user(int $user_id): ?WP_Post {
    $status = $this->resolved_clock_status_for_user($user_id);
    $entry_id = (int)($status['open_entry_id'] ?? 0);
    if ($entry_id <= 0) return null;
    $entry = get_post($entry_id);
    return ($entry instanceof WP_Post && $entry->post_type === self::CPT_ENTRY) ? $entry : null;
  }

  private function can_view_timecard(int $viewer_user_id, int $target_user_id): bool {
    if ($viewer_user_id <= 0 || $target_user_id <= 0) return false;
    if ($viewer_user_id === $target_user_id) return true;
    if (EUM_Access::is_it_or_hr($viewer_user_id)) return true;
    if (class_exists('EUM_Manager_IDs') && EUM_Manager_IDs::user_is_manager_of_user($viewer_user_id, $target_user_id)) return true;
    return $this->manager_has_schedule_visibility_for_user($viewer_user_id, $target_user_id);
  }

  private function can_edit_timecard(int $viewer_user_id, int $target_user_id): bool {
    if ($viewer_user_id <= 0 || $target_user_id <= 0) return false;
    if ($viewer_user_id === $target_user_id) return true;
    if (EUM_Access::is_it_or_hr($viewer_user_id)) return true;
    if (class_exists('EUM_Manager_IDs') && EUM_Manager_IDs::user_is_manager_of_user($viewer_user_id, $target_user_id)) return true;
    return $this->manager_has_schedule_visibility_for_user($viewer_user_id, $target_user_id);
  }


  private function manager_schedule_visible_user_ids(int $actor_user_id): array {
    if ($actor_user_id <= 0) return [];
    if (EUM_Access::is_it_or_hr($actor_user_id)) {
      return array_values(array_filter(array_map('intval', get_users(['fields' => 'ID'])), static fn($uid) => $uid > 0));
    }
    if (!class_exists('EUM_Scheduling')) return [];

    $shift_ids = get_posts([
      'post_type' => EUM_Scheduling::CPT_SHIFT,
      'post_status' => ['publish', 'draft'],
      'numberposts' => -1,
      'fields' => 'ids',
      'orderby' => 'meta_value_num',
      'meta_key' => EUM_Scheduling::META_SHIFT_START_UTC,
      'order' => 'ASC',
    ]);

    $user_ids = [];
    foreach ((array)$shift_ids as $shift_id) {
      $shift_id = (int)$shift_id;
      if ($shift_id <= 0) continue;
      if (!$this->shift_has_owned_segment($actor_user_id, $shift_id)) continue;
      $scheduled_user_id = (int)get_post_meta($shift_id, EUM_Scheduling::META_USER_ID, true);
      if ($scheduled_user_id > 0) $user_ids[] = $scheduled_user_id;
    }

    return array_values(array_unique(array_filter($user_ids, static fn($uid) => $uid > 0)));
  }

  private function manager_has_schedule_visibility_for_user(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0 || !class_exists('EUM_Scheduling')) return false;
    foreach ($this->schedule_shifts_for_user($target_user_id, 0, 0, ['publish', 'draft']) as $shift) {
      if ($this->shift_has_owned_segment($actor_user_id, (int)$shift->ID)) return true;
    }
    return false;
  }

  private function schedule_shifts_for_user(int $user_id, int $range_start_utc = 0, int $range_end_utc = 0, array $post_statuses = ['publish', 'draft']): array {
    if ($user_id <= 0 || !class_exists('EUM_Scheduling')) return [];
    $items = get_posts([
      'post_type' => EUM_Scheduling::CPT_SHIFT,
      'post_status' => $post_statuses,
      'numberposts' => -1,
      'orderby' => 'meta_value_num',
      'meta_key' => EUM_Scheduling::META_SHIFT_START_UTC,
      'order' => 'ASC',
      'meta_query' => [
        ['key' => EUM_Scheduling::META_USER_ID, 'value' => $user_id],
      ],
    ]);

    $out = [];
    foreach ((array)$items as $item) {
      $shift_id = (int)$item->ID;
      $start = (int)get_post_meta($shift_id, EUM_Scheduling::META_SHIFT_START_UTC, true);
      $end = (int)get_post_meta($shift_id, EUM_Scheduling::META_SHIFT_END_UTC, true);
      if ($range_end_utc > 0 && $start >= $range_end_utc) continue;
      if ($range_start_utc > 0 && $end <= $range_start_utc) continue;
      $out[] = $item;
    }
    return $out;
  }

  private function normalized_schedule_segments_from_meta(int $shift_id): array {
    if ($shift_id <= 0 || !class_exists('EUM_Scheduling')) return [];
    $segments = get_post_meta($shift_id, EUM_Scheduling::META_SEGMENTS, true);
    if (!is_array($segments)) return [];

    $out = [];
    foreach ($segments as $segment) {
      if (!is_array($segment)) continue;
      $kind = sanitize_key((string)($segment['kind'] ?? 'work'));
      if (!in_array($kind, ['work', 'break'], true)) $kind = 'work';
      $out[] = [
        'kind' => $kind,
        'start_utc' => (int)($segment['start_utc'] ?? 0),
        'end_utc' => (int)($segment['end_utc'] ?? 0),
        'job_path_term_id' => (int)($segment['job_path_term_id'] ?? 0),
        'task_label' => sanitize_text_field((string)($segment['task_label'] ?? '')),
        'cost_center_term_id' => (int)($segment['cost_center_term_id'] ?? 0),
      ];
    }

    usort($out, static fn($a, $b) => ((int)$a['start_utc']) <=> ((int)$b['start_utc']));
    return $out;
  }

  private function shift_has_owned_segment(int $actor_user_id, int $shift_id): bool {
    foreach ($this->normalized_schedule_segments_from_meta($shift_id) as $segment) {
      if (($segment['kind'] ?? 'work') !== 'work') continue;
      if ($this->actor_owns_job_term($actor_user_id, (int)($segment['job_path_term_id'] ?? 0))) return true;
    }
    return false;
  }

  private function actor_owns_job_term(int $actor_user_id, int $term_id): bool {
    if ($actor_user_id <= 0 || $term_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    foreach ($this->manager_root_job_terms($actor_user_id) as $root_term_id) {
      if ($term_id === $root_term_id || term_is_ancestor_of($root_term_id, $term_id, 'eum_job_path')) return true;
    }
    return false;
  }

  private function manager_root_job_terms(int $actor_user_id): array {
    if ($actor_user_id <= 0 || !class_exists('EUM_Manager_IDs')) return [];
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

  private function timecard_assignment_labels_for_interval(int $user_id, int $start_utc, int $end_utc): array {
    $labels = [];
    foreach ($this->schedule_shifts_for_user($user_id, $start_utc, $end_utc, ['publish', 'draft']) as $shift) {
      foreach ($this->normalized_schedule_segments_from_meta((int)$shift->ID) as $segment) {
        if (($segment['kind'] ?? 'work') !== 'work') continue;
        $segment_start = (int)($segment['start_utc'] ?? 0);
        $segment_end = (int)($segment['end_utc'] ?? 0);
        if ($segment_start >= $end_utc || $segment_end <= $start_utc) continue;
        $labels[] = $this->format_assignment_label((int)($segment['job_path_term_id'] ?? 0), (string)($segment['task_label'] ?? ''), $user_id, (int)($segment['cost_center_term_id'] ?? 0));
      }
    }

    $labels = array_values(array_unique(array_filter(array_map('strval', $labels), static fn($label) => trim($label) !== '')));
    if (empty($labels)) $labels[] = $this->default_assignment_label_for_user($user_id);
    return $labels;
  }

  private function default_assignment_label_for_user(int $user_id): string {
    $job_path_label = '';
    if (class_exists('EUM_Profiles')) {
      $job_term_id = (int)EUM_Profiles::get_field_raw_value($user_id, 'tax:eum_job_path');
      if ($job_term_id > 0) $job_path_label = EUM_Profiles::term_full_path('eum_job_path', $job_term_id, ' / ');
      $job_title_label = EUM_Profiles::preferred_job_title_label($user_id);
    } else {
      $job_term_id = 0;
      $job_title_label = '';
    }

    if ($job_path_label === '' && taxonomy_exists('eum_job_path')) {
      $terms = wp_get_object_terms($user_id, 'eum_job_path');
      if (!is_wp_error($terms) && !empty($terms)) $job_path_label = sanitize_text_field((string)$terms[0]->name);
    }

    if ($job_path_label === '' && $job_title_label === '') return 'Not scheduled';
    if ($job_path_label === '') return $job_title_label;
    if ($job_title_label === '') return $job_path_label;
    return $job_path_label . ' / ' . $job_title_label;
  }

  private function format_assignment_label(int $job_path_term_id, string $task_label, int $user_id = 0, int $cost_center_term_id = 0): string {
    $job_path_label = $job_path_term_id > 0 && class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', $job_path_term_id, ' / ') : '';
    $task_label = sanitize_text_field($task_label);
    $cost_center_label = $this->cost_center_label($cost_center_term_id);
    if ($job_path_label === '' && $task_label === '' && $cost_center_label === '') return $user_id > 0 ? $this->default_assignment_label_for_user($user_id) : 'Not scheduled';
    $base = '';
    if ($job_path_label === '') $base = $task_label;
    elseif ($task_label === '') $base = $job_path_label;
    else $base = $job_path_label . ' / ' . $task_label;
    if ($base === '') $base = $user_id > 0 ? $this->default_assignment_label_for_user($user_id) : 'Not scheduled';
    if ($cost_center_label === '') return $base;
    return $base . ' -> ' . $cost_center_label;
  }

  private function assignment_for_entry(int $entry_id, int $user_id, int $start_utc, int $end_utc): array {
    $job_path_term_id = $entry_id > 0 ? (int)get_post_meta($entry_id, self::META_JOB_PATH_TERM_ID, true) : 0;
    $task_label = $entry_id > 0 ? sanitize_text_field((string)get_post_meta($entry_id, self::META_TASK_LABEL, true)) : '';
    $cost_center_term_id = $entry_id > 0 ? (int)get_post_meta($entry_id, self::META_COST_CENTER_TERM_ID, true) : 0;
    if ($job_path_term_id > 0 || $task_label !== '' || $cost_center_term_id > 0) {
      return [
        'job_path_term_id' => $job_path_term_id,
        'task_label' => $task_label,
        'cost_center_term_id' => $cost_center_term_id,
        'labels' => [$this->format_assignment_label($job_path_term_id, $task_label, $user_id, $cost_center_term_id)],
      ];
    }

    $labels = $this->timecard_assignment_labels_for_interval($user_id, $start_utc, $end_utc);
    $default = $this->default_entry_assignment_for_interval($user_id, $start_utc, $end_utc);
    $default['labels'] = $labels;
    return $default;
  }

  private function assignment_from_request(array $source, int $user_id, int $start_utc, int $end_utc, int $entry_id = 0) {
    if (!array_key_exists('entry_job_path', $source) && !array_key_exists('entry_task', $source) && !array_key_exists('entry_cost_center', $source)) {
      return $this->assignment_for_entry($entry_id, $user_id, $start_utc, $end_utc > 0 ? $end_utc : time());
    }

    $job_path_term_id = max(0, (int)($source['entry_job_path'] ?? 0));
    $task_label = sanitize_text_field((string)($source['entry_task'] ?? ''));
    $cost_center_term_id = max(0, (int)($source['entry_cost_center'] ?? 0));

    if ($job_path_term_id <= 0) return new WP_Error('missing_job_path', 'A Job Path is required.');
    if ($task_label === '') return new WP_Error('missing_task', 'A Job Task is required.');
    $allowed_tasks = class_exists('EUM_Taxonomies') ? EUM_Taxonomies::effective_schedule_task_options_for_term($job_path_term_id) : [];
    if (!empty($allowed_tasks) && !in_array($task_label, $allowed_tasks, true)) {
      return new WP_Error('invalid_task', 'The selected Job Task is not valid for that Job Path.');
    }
    if ($cost_center_term_id > 0 && (!class_exists('EUM_Finance') || !EUM_Finance::is_valid_labor_paycode($cost_center_term_id))) {
      return new WP_Error('invalid_cost_center', 'The selected Cost Center is not valid.');
    }

    return [
      'job_path_term_id' => $job_path_term_id,
      'task_label' => $task_label,
      'cost_center_term_id' => $cost_center_term_id,
    ];
  }

  private function default_entry_assignment_for_interval(int $user_id, int $start_utc, int $end_utc): array {
    $end_compare = $end_utc > 0 ? $end_utc : max($start_utc + 1, time());
    foreach ($this->schedule_shifts_for_user($user_id, $start_utc, $end_compare, ['publish', 'draft']) as $shift) {
      foreach ($this->normalized_schedule_segments_from_meta((int)$shift->ID) as $segment) {
        if (($segment['kind'] ?? 'work') !== 'work') continue;
        $segment_start = (int)($segment['start_utc'] ?? 0);
        $segment_end = (int)($segment['end_utc'] ?? 0);
        if ($segment_start >= $end_compare || $segment_end <= $start_utc) continue;
        return [
          'job_path_term_id' => (int)($segment['job_path_term_id'] ?? 0),
          'task_label' => sanitize_text_field((string)($segment['task_label'] ?? '')),
          'cost_center_term_id' => (int)($segment['cost_center_term_id'] ?? 0),
        ];
      }
    }
    return $this->default_entry_assignment_for_user($user_id);
  }

  private function default_entry_assignment_for_user(int $user_id): array {
    $job_path_term_id = 0;
    if (class_exists('EUM_Profiles')) {
      $job_path_term_id = (int)EUM_Profiles::get_field_raw_value($user_id, 'tax:eum_job_path');
    }
    if ($job_path_term_id <= 0 && taxonomy_exists('eum_job_path')) {
      $terms = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
      if (!is_wp_error($terms) && !empty($terms)) $job_path_term_id = (int)$terms[0];
    }
    $task_label = '';
    if ($job_path_term_id > 0 && class_exists('EUM_Taxonomies')) {
      $tasks = EUM_Taxonomies::effective_schedule_task_options_for_term($job_path_term_id);
      if (!empty($tasks)) $task_label = sanitize_text_field((string)$tasks[0]);
    }
    return [
      'job_path_term_id' => $job_path_term_id,
      'task_label' => $task_label,
      'cost_center_term_id' => 0,
    ];
  }

  private function render_entry_assignment_fields(array $path_options, array $task_map, array $cost_center_options, int $job_path_term_id, string $task_label, int $cost_center_term_id, string $field_id_prefix): string {
    $task_options = $job_path_term_id > 0 ? (array)($task_map[$job_path_term_id] ?? []) : [];
    ob_start();
    echo '<div style="display:flex;flex-direction:column;gap:4px;min-width:280px;"><strong style="font-size:11px;color:#50575e;">Job Path</strong>';
    if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'render_job_path_directory_picker')) {
      echo EUM_Profiles::render_job_path_directory_picker('entry_job_path', $job_path_term_id, 0, [
        'placeholder' => 'Select Job Path',
        'required' => false,
        'none_label' => 'Clear Job Path',
        'enforce_manageable_job_paths' => false,
        'container_id' => $field_id_prefix . '-path-picker',
        'input_id' => $field_id_prefix . '-path-input',
      ]);
    } else {
      echo '<select name="entry_job_path" class="eum-timeclock-path" data-task-target="' . esc_attr($field_id_prefix . '-task') . '"><option value="0">Select Job Path</option>';
      foreach ($path_options as $term_id => $label) {
        echo '<option value="' . (int)$term_id . '" ' . selected($job_path_term_id, (int)$term_id, false) . '>' . esc_html($label) . '</option>';
      }
      echo '</select>';
    }
    echo '</div>';
    echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;color:#50575e;min-width:180px;">Job Task<select name="entry_task" id="' . esc_attr($field_id_prefix . '-task') . '" class="eum-timeclock-task"><option value="">Select Job Task</option>';
    foreach ($task_options as $label) {
      echo '<option value="' . esc_attr($label) . '" ' . selected($task_label, $label, false) . '>' . esc_html($label) . '</option>';
    }
    if ($task_label !== '' && !in_array($task_label, $task_options, true)) {
      echo '<option value="' . esc_attr($task_label) . '" selected>' . esc_html($task_label) . '</option>';
    }
    echo '</select></label>';
    echo '<div style="display:flex;flex-direction:column;gap:4px;min-width:280px;"><strong style="font-size:11px;color:#50575e;">Paycode / Cost Center</strong>';
    echo $this->render_cost_center_directory_picker('entry_cost_center', $cost_center_term_id, $cost_center_options, $field_id_prefix . '-cost-center-picker', $field_id_prefix . '-cost-center-input');
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function entry_kind_options(bool $include_legacy_shift = false): array {
    $options = [
      'punch_in' => 'Punch In',
      'punch_out' => 'Punch Out',
    ];
    if ($include_legacy_shift) {
      $options = ['shift' => 'Legacy Shift'] + $options;
    }
    return $options;
  }

  private function normalize_entry_kind(string $entry_kind): string {
    $entry_kind = sanitize_key($entry_kind);
    return in_array($entry_kind, ['shift', 'punch_in', 'punch_out'], true) ? $entry_kind : 'punch_in';
  }

  private function entry_kind_for_entry(int $entry_id): string {
    $raw = sanitize_key((string)get_post_meta($entry_id, self::META_ENTRY_KIND, true));
    if ($raw === '') return 'shift';
    return $this->normalize_entry_kind($raw);
  }

  private function entry_kind_select_html(string $name, string $current): string {
    $current = $this->normalize_entry_kind($current);
    $options = $this->entry_kind_options($current === 'shift');
    ob_start();
    echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:600;color:#50575e;min-width:170px;">Entry Type<select name="' . esc_attr($name) . '" class="eum-timeclock-entry-kind">';
    foreach ($options as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    return (string)ob_get_clean();
  }

  private function job_path_options_for_form(): array {
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'number' => 0]);
    if (is_wp_error($terms) || empty($terms)) return [];
    $out = [];
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      $out[$term_id] = class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ') : ((string)$term->name);
    }
    natcasesort($out);
    return $out;
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

  private function cost_center_options_for_form(): array {
    if (!class_exists('EUM_Finance')) return [];
    return EUM_Finance::labor_paycode_options();
  }

  private function cost_center_label(int $term_id): string {
    if ($term_id <= 0) return '';
    if (class_exists('EUM_Finance') && method_exists('EUM_Finance', 'labor_paycode_option_label')) return (string)EUM_Finance::labor_paycode_option_label($term_id);
    if (class_exists('EUM_Finance') && method_exists('EUM_Finance', 'finance_path_option_label')) return (string)EUM_Finance::finance_path_option_label($term_id);
    return '';
  }

  private function clock_target_user_id_from_request(int $actor_user_id): int {
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    if ($target_user_id <= 0) $target_user_id = $actor_user_id;
    if ($target_user_id === $actor_user_id) return $target_user_id;
    return $this->can_edit_timecard($actor_user_id, $target_user_id) ? $target_user_id : 0;
  }

  private function user_label(int $user_id): string {
    $user = get_user_by('id', $user_id);
    if (!$user) return 'User #' . $user_id;
    return (string)($user->display_name ?: $user->user_login);
  }

  private function inline_assignment_script(): string {
    return <<<'JS'
(function(){
  var node=document.getElementById('eum-timeclock-task-map');
  if(!node){return;}
  var taskMap={};
  try{taskMap=JSON.parse(node.textContent||'{}')||{};}catch(e){taskMap={};}
  function refreshTaskSelect(form){
    if(!form){return;}
    var path=form.querySelector('.eum-timeclock-path') || form.querySelector('input[name="entry_job_path"]');
    var task=form.querySelector('.eum-timeclock-task');
    if(!path||!task){return;}
    var current=String(task.value||'');
    var opts=taskMap[String(parseInt(path.value||'0',10)||0)]||[];
    task.innerHTML='<option value="">Select Job Task</option>';
    opts.forEach(function(label){
      var option=document.createElement('option');
      option.value=label;
      option.textContent=label;
      if(label===current){option.selected=true;}
      task.appendChild(option);
    });
    if(current && opts.indexOf(current)===-1){
      var fallback=document.createElement('option');
      fallback.value=current;
      fallback.textContent=current;
      fallback.selected=true;
      task.appendChild(fallback);
    }
  }
  document.querySelectorAll('.eum-timeclock-entry-form').forEach(function(form){
    var path=form.querySelector('.eum-timeclock-path') || form.querySelector('input[name="entry_job_path"]');
    if(path){path.addEventListener('change',function(){refreshTaskSelect(form);});}
    form.querySelectorAll('.eum-job-path-picker__go,.eum-job-path-picker__back,.eum-job-path-picker__nonebtn').forEach(function(button){
      button.addEventListener('click',function(){window.setTimeout(function(){refreshTaskSelect(form);},0);});
    });
    refreshTaskSelect(form);
  });
})();
JS;
  }

  private function render_cost_center_directory_picker(string $input_name, int $selected_term_id, array $options, string $container_id, string $input_id): string {
    $terms = get_terms([
      'taxonomy' => class_exists('EUM_Finance') ? EUM_Finance::TAX_FINANCE_PATH : 'eum_finance_path',
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
      'number' => 0,
    ]);
    if (is_wp_error($terms) || empty($terms)) {
      return '<input type="hidden" name="' . esc_attr($input_name) . '" value="0"><div class="description">No paycodes are available yet.</div>';
    }

    $items = [];
    foreach ((array)$terms as $term) {
      $term_id = (int)$term->term_id;
      $label = (string)($options[$term_id] ?? ((class_exists('EUM_Finance') && method_exists('EUM_Finance', 'labor_paycode_option_label')) ? EUM_Finance::labor_paycode_option_label($term_id) : (string)$term->name));
      $items[] = [
        'id' => $term_id,
        'parent' => (int)$term->parent,
        'name' => $label,
        'label' => $label,
      ];
    }

    $map = [];
    foreach ($items as $item) {
      $map[(int)$item['id']] = $item;
    }
    $roots = [];
    foreach ($items as $item) {
      $parent_id = (int)$item['parent'];
      if ($parent_id <= 0 || !isset($map[$parent_id])) $roots[] = (int)$item['id'];
    }

    ob_start();
    if (!self::$timeclock_taxonomy_picker_assets_printed) {
      self::$timeclock_taxonomy_picker_assets_printed = true;
      echo <<<'HTML'
<style>.eum-taxonomy-path-picker{display:block;max-width:760px}.eum-taxonomy-path-picker__toolbar{display:flex;align-items:center;gap:10px;background:#111827;color:#fff;border-radius:10px;padding:10px 12px;margin-bottom:10px}.eum-taxonomy-path-picker__back{display:inline-flex;align-items:center;justify-content:center;border:0;background:#374151;color:#fff;border-radius:8px;width:28px;height:28px;padding:0;cursor:pointer;font-size:16px;line-height:1;box-shadow:none}.eum-taxonomy-path-picker__back[disabled]{opacity:.45;cursor:not-allowed}.eum-taxonomy-path-picker__meta{min-width:0}.eum-taxonomy-path-picker__meta strong{display:block;font-size:11px;color:#d1d5db;text-transform:uppercase;letter-spacing:.03em}.eum-taxonomy-path-picker__path{display:block;font-size:13px;word-break:break-word;margin-top:2px}.eum-taxonomy-path-picker__panel{border:1px solid #ccd0d4;border-radius:10px;background:#fff;overflow:hidden}.eum-taxonomy-path-picker__row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-top:1px solid #f0f0f1}.eum-taxonomy-path-picker__row:first-child{border-top:0}.eum-taxonomy-path-picker__name{font-weight:600}.eum-taxonomy-path-picker__hint{color:#6b7280;font-size:12px;margin-top:2px}.eum-taxonomy-path-picker__go{display:inline-flex;align-items:center;justify-content:center;flex:0 0 32px;border:0;background:#2563eb;color:#fff;border-radius:999px;width:32px;min-width:32px;height:32px;padding:0;cursor:pointer;font-size:18px;line-height:1;font-family:inherit;font-weight:600;box-shadow:none}.eum-taxonomy-path-picker__go span{display:block;line-height:1;transform:translateX(1px)}.eum-taxonomy-path-picker__empty{padding:14px;color:#50575e}.eum-taxonomy-path-picker__summary{margin-top:8px;color:#50575e;font-size:13px}.eum-taxonomy-path-picker__none{margin-top:10px}</style>
<script>(function(){if(window.EUMTimeclockTaxonomyPathPickerInit)return;window.EUMTimeclockTaxonomyPathPickerInit=function(root){if(!root)return;var hidden=root.querySelector("input[type=hidden]");var panel=root.querySelector(".eum-taxonomy-path-picker__panel");var summary=root.querySelector(".eum-taxonomy-path-picker__summary");var current=root.querySelector(".eum-taxonomy-path-picker__path");var back=root.querySelector(".eum-taxonomy-path-picker__back");var noneBtn=root.querySelector(".eum-taxonomy-path-picker__nonebtn");if(!hidden||!panel)return;var items=JSON.parse(root.getAttribute("data-items")||"[]");var roots=JSON.parse(root.getAttribute("data-roots")||"[]");var placeholder=root.getAttribute("data-placeholder")||"Select";var noneLabel=root.getAttribute("data-none-label")||"";var map={},children={};items.forEach(function(item){item.id=parseInt(item.id,10)||0;item.parent=parseInt(item.parent,10)||0;map[item.id]=item;var key=String(item.parent);children[key]=children[key]||[];children[key].push(item);});Object.keys(children).forEach(function(key){children[key].sort(function(a,b){return (a.name||"").localeCompare((b.name||""),undefined,{sensitivity:"base"});});});var path=[];var selected=parseInt(root.getAttribute("data-selected")||hidden.value||0,10)||0;if(selected&&map[selected]){var cursor=selected;while(cursor&&map[cursor]){path.unshift(cursor);var parent=map[cursor].parent||0;if(!parent||!map[parent])break;cursor=parent;}}if(path.length&&roots.indexOf(path[0])===-1){path=[];selected=0;}function notify(){try{hidden.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){var evt=document.createEvent('Event');evt.initEvent('change',true,false);hidden.dispatchEvent(evt);}}function currentBranch(){return path.length?path[path.length-1]:0;}function pathLabel(){if(!path.length)return '/';return '/ '+path.map(function(id){return map[id]&&map[id].name?map[id].name:'';}).filter(Boolean).join(' / ');}function render(){var branch=currentBranch();var list=(branch>0?(children[String(branch)]||[]):roots.map(function(id){return map[id];}).filter(Boolean));panel.innerHTML='';if(!list.length){panel.innerHTML='<div class="eum-taxonomy-path-picker__empty">'+(branch>0?'No deeper path items here.':'No path items available.')+'</div>';}else{list.forEach(function(item){var hasChildren=(children[String(item.id)]||[]).length>0;var row=document.createElement('div');row.className='eum-taxonomy-path-picker__row';var left=document.createElement('div');var name=document.createElement('div');name.className='eum-taxonomy-path-picker__name';name.textContent=item.name;left.appendChild(name);var hint=document.createElement('div');hint.className='eum-taxonomy-path-picker__hint';hint.textContent=hasChildren?'Select and go deeper':'Select this paycode';left.appendChild(hint);var btn=document.createElement('button');btn.type='button';btn.className='eum-taxonomy-path-picker__go';btn.setAttribute('aria-label','Select '+item.name);btn.innerHTML='<span aria-hidden="true">&#8250;</span>';btn.addEventListener('click',function(){if(path.length&&path[path.length-1]===item.id){}else{path.push(item.id);}hidden.value=String(item.id);notify();render();});row.appendChild(left);row.appendChild(btn);panel.appendChild(row);});}if(current)current.textContent=pathLabel();if(summary){var resolved=parseInt(hidden.value,10)||0;summary.textContent=resolved&&map[resolved]?'Selected: '+(map[resolved].label||map[resolved].name||''):(noneLabel?'Selected: '+noneLabel:'No paycode selected. '+placeholder);}if(back)back.disabled=path.length===0;if(noneBtn)noneBtn.hidden=!noneLabel;}if(back){back.addEventListener('click',function(){if(!path.length)return;path.pop();hidden.value=path.length?String(path[path.length-1]):'0';notify();render();});}if(noneBtn){noneBtn.addEventListener('click',function(){path=[];hidden.value='0';notify();render();});}render();};document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.eum-taxonomy-path-picker[data-timeclock-picker="1"]').forEach(function(root){window.EUMTimeclockTaxonomyPathPickerInit(root);});});}());</script>
HTML;
    }
    echo '<div id="' . esc_attr($container_id) . '" class="eum-taxonomy-path-picker" data-timeclock-picker="1" data-items="' . esc_attr(wp_json_encode(array_values($items))) . '" data-roots="' . esc_attr(wp_json_encode(array_values($roots))) . '" data-selected="' . (int)$selected_term_id . '" data-placeholder="' . esc_attr('Select Paycode') . '" data-none-label="' . esc_attr('Use Job Task Billing') . '">';
    echo '<div class="eum-taxonomy-path-picker__toolbar"><button type="button" class="eum-taxonomy-path-picker__back" disabled>&lsaquo;</button><div class="eum-taxonomy-path-picker__meta"><strong>Current Path</strong><span class="eum-taxonomy-path-picker__path">/</span></div></div>';
    echo '<input type="hidden" id="' . esc_attr($input_id) . '" class="eum-timeclock-cost-center" name="' . esc_attr($input_name) . '" value="' . (int)$selected_term_id . '">';
    echo '<div class="eum-taxonomy-path-picker__panel"></div>';
    echo '<p class="eum-taxonomy-path-picker__none"><button type="button" class="button button-secondary eum-taxonomy-path-picker__nonebtn">Use Job Task Billing</button></p>';
    echo '<div class="eum-taxonomy-path-picker__summary"></div>';
    echo '</div>';
    echo '<script>(function(){var root=document.getElementById(' . wp_json_encode($container_id) . '); if(root){root.setAttribute("data-timeclock-picker","1"); if(window.EUMTimeclockTaxonomyPathPickerInit){window.EUMTimeclockTaxonomyPathPickerInit(root);}}}());</script>';
    return (string)ob_get_clean();
  }

  private function job_path_matches_scope(int $candidate_term_id, int $scope_term_id, bool $include_descendants): bool {
    if ($scope_term_id <= 0) return true;
    if ($candidate_term_id <= 0) return false;
    if ($candidate_term_id === $scope_term_id) return true;
    return $include_descendants && term_is_ancestor_of($scope_term_id, $candidate_term_id, 'eum_job_path');
  }

  private function user_can_bypass_schedule_clock_rule(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($user_id) || EUM_Access::is_manager_user($user_id)) return true;
    $worker_type = class_exists('EUM_Profiles') ? EUM_Profiles::worker_type_for_user($user_id) : '';
    return in_array($worker_type, ['independent_contractor', 'freelancer'], true);
  }

  private function user_can_clock_in_now(int $user_id, int $now_utc, string &$message = ''): bool {
    if ($user_id <= 0) {
      $message = 'User not found.';
      return false;
    }
    if ($this->user_can_bypass_schedule_clock_rule($user_id)) return true;

    foreach ($this->schedule_shifts_for_user($user_id, $now_utc - DAY_IN_SECONDS, $now_utc + (30 * DAY_IN_SECONDS), ['publish', 'draft']) as $shift) {
      $shift_start = (int)get_post_meta((int)$shift->ID, EUM_Scheduling::META_SHIFT_START_UTC, true);
      $shift_end = (int)get_post_meta((int)$shift->ID, EUM_Scheduling::META_SHIFT_END_UTC, true);
      if ($shift_start <= 0 || $shift_end <= $shift_start) continue;
      if ($now_utc >= ($shift_start - (8 * MINUTE_IN_SECONDS)) && $now_utc <= $shift_end) return true;
    }

    $message = 'You can clock in starting 8 minutes before your scheduled shift. Managers, independent contractors, and freelancers can bypass this rule.';
    return false;
  }

  private function user_timezone(int $user_id): DateTimeZone {
    $tz_string = '';
    if ($user_id > 0) $tz_string = (string)get_user_meta($user_id, 'timezone', true);
    if ($tz_string === '') $tz_string = (string)get_option('timezone_string', '');
    if ($tz_string === '') $tz_string = self::DEFAULT_TIMEZONE;
    try {
      return new DateTimeZone($tz_string);
    } catch (Throwable $e) {
      return new DateTimeZone(self::DEFAULT_TIMEZONE);
    }
  }

  private function default_timecard_dates(int $user_id): array {
    $tz = $this->user_timezone($user_id);
    $now = new DateTimeImmutable('now', $tz);
    $week_start = $this->week_start_local($now);
    $week_end = $week_start->modify('+6 days');
    return [$week_start->format('Y-m-d'), $week_end->format('Y-m-d')];
  }

  private function date_range_to_utc(int $user_id, string $start_date, string $end_date): array {
    $tz = $this->user_timezone($user_id);
    $start_local = new DateTimeImmutable($start_date . ' 00:00:00', $tz);
    $end_local = new DateTimeImmutable($end_date . ' 00:00:00', $tz);
    $end_exclusive_local = $end_local->modify('+1 day');
    return [
      $start_local->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
      $end_exclusive_local->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
      $start_local,
      $end_exclusive_local,
    ];
  }

  private function analysis_range_for_date_range(int $user_id, string $start_date, string $end_date): array {
    $tz = $this->user_timezone($user_id);
    $start_local = new DateTimeImmutable($start_date . ' 00:00:00', $tz);
    $end_local = new DateTimeImmutable($end_date . ' 00:00:00', $tz);
    $analysis_start_local = $this->week_start_local($start_local);
    $analysis_end_local = $this->week_start_local($end_local)->modify('+7 days');
    return [
      $analysis_start_local->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
      $analysis_end_local->setTimezone(new DateTimeZone('UTC'))->getTimestamp(),
    ];
  }

  private function week_start_local(DateTimeImmutable $local_dt): DateTimeImmutable {
    $weekday = (int)$local_dt->format('w');
    return $local_dt->setTime(0, 0, 0)->modify('-' . $weekday . ' days');
  }

  private function week_key_for_utc(int $user_id, int $utc_ts): string {
    $tz = $this->user_timezone($user_id);
    $local = (new DateTimeImmutable('@' . $utc_ts))->setTimezone($tz);
    return $this->week_start_local($local)->format('Y-m-d');
  }

  private function next_local_week_boundary_utc(int $user_id, int $utc_ts): int {
    $tz = $this->user_timezone($user_id);
    $local = (new DateTimeImmutable('@' . $utc_ts))->setTimezone($tz);
    $next_local = $this->week_start_local($local)->modify('+7 days');
    return $next_local->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
  }

  private function split_interval_by_boundaries(int $start_utc, int $end_utc, array $boundaries): array {
    $points = [$start_utc, $end_utc];
    foreach ($boundaries as $boundary) {
      $boundary = (int)$boundary;
      if ($boundary > $start_utc && $boundary < $end_utc) $points[] = $boundary;
    }
    $points = array_values(array_unique($points));
    sort($points);
    $segments = [];
    for ($i = 0; $i < count($points) - 1; $i++) {
      $segments[] = ['start' => (int)$points[$i], 'end' => (int)$points[$i + 1]];
    }
    return $segments;
  }

  private function user_job_path_term_id(int $user_id): int {
    if ($user_id <= 0) return 0;
    if (class_exists('EUM_Profiles')) {
      $job_path_term_id = (int)EUM_Profiles::get_field_raw_value($user_id, 'tax:eum_job_path');
      if ($job_path_term_id > 0) return $job_path_term_id;
    }
    if (!taxonomy_exists('eum_job_path')) return 0;
    $terms = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($terms) || empty($terms)) return 0;
    return (int)$terms[0];
  }

  private function user_job_path_label(int $user_id): string {
    $job_path_term_id = $this->user_job_path_term_id($user_id);
    if ($job_path_term_id <= 0) return '';
    return class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', $job_path_term_id, ' / ') : '';
  }

  private function user_job_title_token(int $user_id): string {
    if ($user_id <= 0 || !class_exists('EUM_Fields')) return '';
    $all = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    return sanitize_text_field((string)($all['job_title'] ?? ''));
  }

  private function user_job_title_label(int $user_id): string {
    return class_exists('EUM_Profiles') ? EUM_Profiles::preferred_job_title_label($user_id) : '';
  }

  private function job_title_options_for_job_path(int $job_path_term_id): array {
    $job_path_term_id = (int)$job_path_term_id;
    if ($job_path_term_id <= 0 || !class_exists('EUM_Job_Titles')) return [];
    $out = [];
    foreach (EUM_Job_Titles::title_records_for_job_term($job_path_term_id) as $row) {
      $token = sanitize_text_field((string)($row['token'] ?? ''));
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($token !== '' && $label !== '') $out[$token] = $label;
    }
    natcasesort($out);
    return $out;
  }

  private function local_input_to_utc(string $value, DateTimeZone $tz): int {
    $value = trim($value);
    if ($value === '') return 0;
    try {
      $dt = new DateTimeImmutable($value, $tz);
      return $dt->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    } catch (Throwable $e) {
      return 0;
    }
  }

  private function format_local_ts(int $utc_ts, DateTimeZone $tz): string {
    return (new DateTimeImmutable('@' . $utc_ts))->setTimezone($tz)->format('M j, Y g:i A');
  }

  private function format_local_time(int $utc_ts, DateTimeZone $tz): string {
    return (new DateTimeImmutable('@' . $utc_ts))->setTimezone($tz)->format('g:i A');
  }

  private function format_local_input(int $utc_ts, DateTimeZone $tz): string {
    return (new DateTimeImmutable('@' . $utc_ts))->setTimezone($tz)->format('Y-m-d\TH:i');
  }

  private static function sanitize_money($value): float {
    if (is_string($value)) $value = preg_replace('/[^0-9.\-]/', '', $value);
    return round((float)$value, 2);
  }

  private static function decorate_compensation(array $data): array {
    $type = self::normalize_compensation_type((string)($data['type'] ?? 'unpaid'));
    $amount = self::sanitize_money($data['amount'] ?? 0);
    $unpaid_label = sanitize_text_field((string)($data['unpaid_label'] ?? ''));
    $annualized = 0.0;
    $hourly_rate = 0.0;

    switch ($type) {
      case 'hourly':
        $annualized = $amount * 40 * 52;
        $hourly_rate = $amount;
        break;
      case 'day_rate':
        $hourly_rate = $amount / self::HOURS_PER_DAY_RATE_DAY;
        $annualized = $hourly_rate * 40 * 52;
        break;
      case 'monthly':
        $annualized = $amount * 12;
        break;
      case 'yearly':
        $annualized = $amount;
        break;
      default:
        $annualized = 0.0;
        $hourly_rate = 0.0;
        break;
    }

    return [
      'type' => $type,
      'type_label' => self::compensation_type_label($type),
      'amount' => $amount,
      'unpaid_label' => $unpaid_label,
      'annualized_amount' => round($annualized, 2),
      'hourly_rate' => round($hourly_rate, 6),
      'effective_at' => EUM_Profiles::parse_datetime_local((string)($data['effective_at'] ?? '')),
      'last_raise_at' => EUM_Profiles::parse_datetime_local((string)($data['last_raise_at'] ?? '')),
    ];
  }

  public static function format_compensation_summary(array $comp): string {
    $comp = self::decorate_compensation($comp);
    $type = (string)$comp['type'];
    if ($type === 'unpaid') {
      $suffix = $comp['unpaid_label'] !== '' ? ' · ' . $comp['unpaid_label'] : '';
      return 'Unpaid' . $suffix;
    }

    $amount = self::money_string((float)$comp['amount']);
    switch ($type) {
      case 'hourly':
        return $amount . ' / hour';
      case 'day_rate':
        return $amount . ' / day';
      case 'monthly':
        return $amount . ' / month';
      case 'yearly':
        return $amount . ' / year';
    }

    return $comp['type_label'];
  }

  private static function compensation_detail_lines_from_data(array $comp): array {
    $comp = self::decorate_compensation($comp);
    $lines = [
      'Compensation Type' => $comp['type_label'],
      'Compensation Amount' => self::format_compensation_summary($comp),
    ];
    if ($comp['type'] === 'day_rate') {
      $lines['Day Rate Hourly Base'] = self::money_string((float)$comp['hourly_rate']) . ' / hour';
    }
    if ($comp['effective_at'] !== '') {
      $lines['Compensation Effective At'] = $comp['effective_at'];
    }
    return $lines;
  }

  private function format_money(float $amount): string {
    return self::money_string($amount);
  }

  private static function money_string(float $amount): string {
    return '$' . number_format($amount, 2, '.', ',');
  }

  private function stat_card(string $label, string $value): string {
    return '<div style="padding:14px;border:1px solid #e2e4e7;border-radius:8px;background:#f8f9fa;"><div style="font-size:12px;color:#50575e;">' . esc_html($label) . '</div><div style="font-size:24px;font-weight:700;line-height:1.2;">' . esc_html($value) . '</div></div>';
  }

  private function current_url(): string {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash((string)$_SERVER['HTTP_HOST'])) : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '/';
    return esc_url_raw($scheme . $host . $uri);
  }

  private function redirect_with_notice(string $return_url, string $message, string $type = 'success'): void {
    wp_safe_redirect(add_query_arg([
      'eum_time_msg' => $message,
      'eum_time_type' => sanitize_key($type),
    ], $return_url));
    exit;
  }

  private function redirect_with_profile_notice(string $base_url, string $msg, string $type): void {
    wp_safe_redirect(add_query_arg([
      'eum_profile_msg' => $msg,
      'eum_profile_type' => sanitize_key($type),
    ], $base_url));
    exit;
  }

  private function timeclock_notice_html(): string {
    $msg = isset($_GET['eum_time_msg']) ? sanitize_text_field(wp_unslash((string)$_GET['eum_time_msg'])) : '';
    if ($msg === '') return '';
    $type = isset($_GET['eum_time_type']) ? sanitize_key((string)$_GET['eum_time_type']) : 'success';
    $bg = $type === 'error' ? '#fbeaea' : '#eef7ee';
    $border = $type === 'error' ? '#d63638' : '#46b450';
    return '<div style="background:' . esc_attr($bg) . ';border-left:4px solid ' . esc_attr($border) . ';padding:10px 12px;margin-bottom:16px;">' . esc_html($msg) . '</div>';
  }
}
