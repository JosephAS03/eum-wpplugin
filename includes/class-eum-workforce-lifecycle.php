<?php
if (!defined('ABSPATH')) exit;

class EUM_Workforce_Lifecycle {
  const OPTION_KEY = 'eum_workforce_lifecycle_settings';
  const CPT_LEAVE = 'eum_leave_request';
  const CPT_AVAILABILITY = 'eum_availability_req';

  const META_TARGET_USER = '_eum_leave_target_user_id';
  const META_ACTOR_USER = '_eum_leave_actor_user_id';
  const META_TYPE = '_eum_leave_type';
  const META_STATUS = '_eum_leave_status';
  const META_START_DATE = '_eum_leave_start_date';
  const META_RETURN_DATE = '_eum_leave_return_date';
  const META_HOURS = '_eum_leave_hours';
  const META_REASON = '_eum_leave_reason';
  const META_NOTES = '_eum_leave_notes';
  const META_APPROVER_IDS = '_eum_leave_approver_user_ids';
  const META_APPROVED_BY = '_eum_leave_approved_by_user_id';
  const META_APPROVED_AT = '_eum_leave_approved_at';
  const META_RETAINED_ACCESS = '_eum_leave_retained_access';

  const AV_META_TARGET_USER = '_eum_availability_target_user_id';
  const AV_META_ACTOR_USER = '_eum_availability_actor_user_id';
  const AV_META_STATUS = '_eum_availability_status';
  const AV_META_SCOPE = '_eum_availability_scope';
  const AV_META_AS_OF_DATE = '_eum_availability_as_of_date';
  const AV_META_WEEK_START = '_eum_availability_week_start';
  const AV_META_SEGMENTS = '_eum_availability_segments';
  const AV_META_NOTES = '_eum_availability_notes';
  const AV_META_APPROVER_IDS = '_eum_availability_approver_user_ids';
  const AV_META_APPROVED_BY = '_eum_availability_approved_by_user_id';
  const AV_META_APPROVED_AT = '_eum_availability_approved_at';

  const USER_META_DEFAULT_AVAILABILITY = '_eum_default_availability';
  const USER_META_DEFAULT_AVAILABILITY_RULES = '_eum_default_availability_rules';
  const USER_META_WEEKLY_AVAILABILITY = '_eum_weekly_availability';

  public function init(): void {
    add_action('init', [$this, 'register_post_types']);
    add_action('admin_menu', [$this, 'admin_menu'], 42);

    add_action('admin_post_eum_lifecycle_save_settings', [$this, 'handle_save_settings']);
    add_action('admin_post_eum_leave_submit', [$this, 'handle_leave_submit']);
    add_action('admin_post_eum_leave_action', [$this, 'handle_leave_action']);
    add_action('admin_post_eum_availability_submit', [$this, 'handle_availability_submit']);
    add_action('admin_post_eum_availability_action', [$this, 'handle_availability_action']);
    add_action('admin_post_eum_employee_id_assign_missing', [$this, 'handle_assign_missing_ids']);
    add_action('admin_post_eum_employee_id_import', [$this, 'handle_employee_id_import']);

    add_action('user_register', [$this, 'handle_user_register'], 20, 1);
    add_action('eum_user_hire_applied', [$this, 'handle_hire_applied'], 20, 3);

    add_shortcode('eum_leave_portal', [$this, 'shortcode_leave_portal']);
    add_shortcode('eum_availability_portal', [$this, 'shortcode_availability_portal']);
    add_shortcode('eum_terminated_user_portal', [$this, 'shortcode_terminated_user_portal']);
    add_shortcode('eum_employee_lookup', [$this, 'shortcode_employee_lookup']);
    add_shortcode('eum_employment_anniversaries', [$this, 'shortcode_employment_anniversaries']);
    add_shortcode('eum_internal_login', [$this, 'shortcode_internal_login']);
    add_shortcode('eum_external_login', [$this, 'shortcode_external_login']);
    add_shortcode('eum_external_org_panel', [$this, 'shortcode_external_org_panel']);
  }

  public function register_post_types(): void {
    register_post_type(self::CPT_LEAVE, [
      'labels' => [
        'name' => 'Leave Requests',
        'singular_name' => 'Leave Request',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);

    register_post_type(self::CPT_AVAILABILITY, [
      'labels' => [
        'name' => 'Availability Requests',
        'singular_name' => 'Availability Request',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public static function default_settings(): array {
    return [
      'allow_terminated_login' => 1,
      'terminated_portal_url' => '',
      'employee_id_prefix' => 'E',
      'employee_id_next' => 1000,
      'employee_id_padding' => 5,
      'employee_id_default_lookup' => 1,
      'internal_login_page_id' => 0,
      'internal_login_page_title' => 'Internal Login',
      'external_general_login_page_id' => 0,
      'external_general_login_page_title' => 'External Login',
      'external_orgs' => [],
      'leave_types' => self::default_leave_types(),
      'vacation_accrual' => [
        'enabled' => 1,
        'monthly_hours' => 8,
        'max_balance_hours' => 160,
        'daily_hours' => 8,
        'allow_negative' => 0,
        'reset_mode' => 'none',
        'reset_month_day' => '',
        'set_mode' => 'hire_date',
        'set_date' => '',
        'rules' => [],
      ],
    ];
  }

  public static function default_leave_types(): array {
    return [
      [
        'key' => 'leave_tbd_return',
        'label' => 'TBD Return Leave',
        'min_return_days' => 0,
        'max_return_days' => 365,
        'return_required' => 0,
        'uses_accrual' => 0,
        'request_roles' => ['user', 'manager', 'second_level_manager', 'hr_it'],
        'approver_roles' => ['next_level_manager', 'request_manager', 'hr_it'],
        'retained_access' => ['portal'],
      ],
      [
        'key' => 'medical_leave',
        'label' => 'Medical Leave',
        'min_return_days' => 0,
        'max_return_days' => 180,
        'return_required' => 0,
        'uses_accrual' => 0,
        'request_roles' => ['user', 'manager', 'hr_it'],
        'approver_roles' => ['next_level_manager', 'hr_it'],
        'retained_access' => ['portal'],
      ],
    ];
  }

  private static function is_vacation_leave_type(array $type): bool {
    return sanitize_key((string)($type['key'] ?? '')) === 'vacation';
  }

  public static function get_settings(): array {
    $saved = get_option(self::OPTION_KEY, []);
    $saved = is_array($saved) ? $saved : [];
    $settings = array_merge(self::default_settings(), $saved);
    $settings['allow_terminated_login'] = empty($settings['allow_terminated_login']) ? 0 : 1;
    $settings['terminated_portal_url'] = esc_url_raw((string)($settings['terminated_portal_url'] ?? ''));
    $settings['employee_id_prefix'] = sanitize_text_field((string)($settings['employee_id_prefix'] ?? 'E'));
    $settings['employee_id_next'] = max(1, (int)($settings['employee_id_next'] ?? 1000));
    $settings['employee_id_padding'] = max(1, min(12, (int)($settings['employee_id_padding'] ?? 5)));
    $settings['employee_id_default_lookup'] = empty($settings['employee_id_default_lookup']) ? 0 : 1;
    $settings['internal_login_page_id'] = max(0, (int)($settings['internal_login_page_id'] ?? 0));
    $settings['internal_login_page_title'] = sanitize_text_field((string)($settings['internal_login_page_title'] ?? 'Internal Login'));
    $settings['external_general_login_page_id'] = max(0, (int)($settings['external_general_login_page_id'] ?? 0));
    $settings['external_general_login_page_title'] = sanitize_text_field((string)($settings['external_general_login_page_title'] ?? 'External Login'));
    $settings['external_orgs'] = self::normalize_external_orgs((array)($settings['external_orgs'] ?? []));
    $settings['leave_types'] = self::normalize_leave_types((array)($settings['leave_types'] ?? []));
    $settings['vacation_accrual'] = self::normalize_accrual_settings((array)($settings['vacation_accrual'] ?? []));
    return $settings;
  }

  private static function normalize_accrual_settings(array $raw): array {
    $defaults = self::default_settings()['vacation_accrual'];
    $out = array_merge($defaults, $raw);
    $out['enabled'] = empty($out['enabled']) ? 0 : 1;
    $out['monthly_hours'] = max(0, (float)($out['monthly_hours'] ?? 0));
    $out['max_balance_hours'] = max(0, (float)($out['max_balance_hours'] ?? 0));
    $out['daily_hours'] = max(1, (float)($out['daily_hours'] ?? 8));
    $out['allow_negative'] = empty($out['allow_negative']) ? 0 : 1;
    $out['reset_mode'] = in_array((string)($out['reset_mode'] ?? 'none'), ['none', 'specific_day', 'anniversary'], true) ? (string)$out['reset_mode'] : 'none';
    $out['reset_month_day'] = preg_match('/^\d{2}-\d{2}$/', (string)($out['reset_month_day'] ?? '')) ? (string)$out['reset_month_day'] : '';
    $out['set_mode'] = in_array((string)($out['set_mode'] ?? 'hire_date'), ['hire_date', 'specific_date', 'anniversary'], true) ? (string)$out['set_mode'] : 'hire_date';
    $out['set_date'] = self::sanitize_date((string)($out['set_date'] ?? ''));
    $out['rules'] = self::normalize_accrual_rules((array)($out['rules'] ?? []));
    return $out;
  }

  private static function normalize_accrual_rules(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $raw_label = sanitize_text_field((string)($row['label'] ?? ''));
      if (empty($row['enabled']) && $raw_label === '' && (float)($row['base_hours'] ?? 0) <= 0) continue;
      $label = $raw_label;
      if ($label === '') $label = 'Accrual Rule';
      $period = in_array((string)($row['period'] ?? 'month'), ['day', 'week', 'month', 'year'], true) ? (string)$row['period'] : 'month';
      $out[] = [
        'enabled' => empty($row['enabled']) ? 0 : 1,
        'label' => $label,
        'period' => $period,
        'base_hours' => max(0, (float)($row['base_hours'] ?? 0)),
        'multiplier' => max(0, (float)($row['multiplier'] ?? 1)),
        'years_min' => max(0, (float)($row['years_min'] ?? 0)),
        'years_max' => max(0, (float)($row['years_max'] ?? 0)),
        'hours_window_days' => max(0, (int)($row['hours_window_days'] ?? 0)),
        'hours_average' => empty($row['hours_average']) ? 0 : 1,
        'hours_average_cap' => max(0, (float)($row['hours_average_cap'] ?? 0)),
        'worker_types' => array_values(array_filter(array_map('sanitize_key', (array)($row['worker_types'] ?? [])))),
        'roles' => array_values(array_filter(array_map('sanitize_key', (array)($row['roles'] ?? [])))),
        'pay_structures' => sanitize_text_field((string)($row['pay_structures'] ?? '')),
        'expires_after_days' => max(0, (int)($row['expires_after_days'] ?? 0)),
      ];
    }
    return $out;
  }

  private static function normalize_external_orgs(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $key = sanitize_key((string)($row['key'] ?? ''));
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($key === '' || $label === '') continue;
      $out[$key] = [
        'key' => $key,
        'label' => $label,
        'enabled' => empty($row['enabled']) ? 0 : 1,
        'login_page_id' => max(0, (int)($row['login_page_id'] ?? 0)),
        'login_page_title' => sanitize_text_field((string)($row['login_page_title'] ?? ($label . ' Login'))),
        'panel_title' => sanitize_text_field((string)($row['panel_title'] ?? ($label . ' Portal'))),
        'panel_body' => wp_kses_post((string)($row['panel_body'] ?? '')),
      ];
    }
    return array_values($out);
  }

  private static function normalize_leave_types(array $rows): array {
    if (empty($rows)) $rows = self::default_leave_types();
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $key = sanitize_key((string)($row['key'] ?? ''));
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($key === '' || $label === '') continue;
      $out[$key] = [
        'key' => $key,
        'label' => $label,
        'min_return_days' => max(0, (int)($row['min_return_days'] ?? 0)),
        'max_return_days' => max(0, (int)($row['max_return_days'] ?? 365)),
        'return_required' => empty($row['return_required']) ? 0 : 1,
        'uses_accrual' => empty($row['uses_accrual']) ? 0 : 1,
        'request_roles' => self::sanitize_role_list((array)($row['request_roles'] ?? []), ['user', 'manager', 'second_level_manager', 'hr_it']),
        'approver_roles' => self::sanitize_role_list((array)($row['approver_roles'] ?? []), ['next_level_manager', 'request_manager', 'hr_it']),
        'retained_access' => self::sanitize_role_list((array)($row['retained_access'] ?? []), ['portal', 'mail', 'nextcloud', 'dashboard', 'files']),
      ];
      if ($out[$key]['max_return_days'] < $out[$key]['min_return_days']) {
        $out[$key]['max_return_days'] = $out[$key]['min_return_days'];
      }
    }
    return array_values($out);
  }

  private static function sanitize_role_list(array $raw, array $allowed): array {
    $out = [];
    foreach ($raw as $value) {
      $value = sanitize_key((string)$value);
      if ($value !== '' && in_array($value, $allowed, true)) $out[] = $value;
    }
    return array_values(array_unique($out));
  }

  public function admin_menu(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) return;
    add_submenu_page(
      EUM_Admin::parent_slug('workforce'),
      'Lifecycle & Leave',
      'Lifecycle & Leave',
      'read',
      'eum-lifecycle',
      [$this, 'render_admin_page']
    );
  }

  public function render_admin_page(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $settings = self::get_settings();
    $tab = sanitize_key((string)($_GET['tab'] ?? 'overview'));
    if (!in_array($tab, ['overview', 'settings', 'leave', 'availability', 'employee_ids', 'lookup'], true)) $tab = 'overview';

    echo '<div class="wrap"><h1>Lifecycle &amp; Leave</h1>';
    $this->render_notice();
    echo '<nav class="nav-tab-wrapper">';
    foreach (['overview' => 'Overview', 'settings' => 'Settings', 'leave' => 'Leave Requests', 'availability' => 'Availability', 'employee_ids' => 'Employee IDs', 'lookup' => 'Lookup'] as $key => $label) {
      echo '<a class="nav-tab ' . ($tab === $key ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=eum-lifecycle&tab=' . $key)) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';

    if ($tab === 'settings') {
      $this->render_settings_tab($settings);
    } elseif ($tab === 'leave') {
      $this->render_leave_admin_tab();
    } elseif ($tab === 'availability') {
      $this->render_availability_admin_tab();
    } elseif ($tab === 'employee_ids') {
      $this->render_employee_ids_tab();
    } elseif ($tab === 'lookup') {
      echo $this->render_employee_lookup_box();
    } else {
      $this->render_overview_tab();
    }
    echo '</div>';
  }

  private function render_notice(): void {
    $msg = isset($_GET['eum_msg']) ? sanitize_text_field((string)$_GET['eum_msg']) : '';
    if ($msg === '') return;
    $type = sanitize_key((string)($_GET['eum_type'] ?? 'success'));
    echo '<div class="notice ' . ($type === 'error' ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($msg) . '</p></div>';
  }

  private function render_overview_tab(): void {
    $pending = count($this->leave_requests(['status' => 'pending', 'limit' => 200]));
    $pending_availability = count($this->availability_requests(['status' => 'pending', 'limit' => 200]));
    $active_ooo = count(self::active_leave_user_ids(current_time('Y-m-d')));
    $missing_ids = count(array_filter(get_users(['fields' => 'ID', 'number' => 0]), fn($uid) => self::employee_id_for_user((int)$uid) === ''));
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;margin-top:18px;max-width:1100px;">';
    foreach ([
      ['Pending Leave', $pending, 'Requests waiting for approval.'],
      ['Pending Availability', $pending_availability, 'Availability updates waiting on managers.'],
      ['Out Today', $active_ooo, 'Approved leave of absence active today.'],
      ['Missing Employee IDs', $missing_ids, 'Users who still need generated IDs.'],
    ] as $card) {
      echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px;"><div style="font-size:32px;font-weight:700;">' . esc_html((string)$card[1]) . '</div><h2 style="margin:4px 0;">' . esc_html($card[0]) . '</h2><p style="margin:0;color:#646970;">' . esc_html($card[2]) . '</p></div>';
    }
    echo '</div>';
    echo '<div style="margin-top:18px;background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px;max-width:1100px;">';
    echo '<h2 style="margin-top:0;">Shortcodes</h2>';
    echo '<p><code>[eum_leave_portal]</code> leave-of-absence requests.</p>';
    echo '<p><code>[eum_availability_portal]</code> default and week-specific availability requests.</p>';
    echo '<p><code>[eum_terminated_user_portal]</code> signed-in terminated-user landing portal.</p>';
    echo '<p><code>[eum_employee_lookup]</code> global employee lookup by employee ID, username, email, or WordPress ID.</p>';
    echo '<p><code>[eum_employment_anniversaries]</code> upcoming anniversary list.</p>';
    echo '</div>';
  }

  private function render_settings_tab(array $settings): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:1100px;margin-top:18px;">';
    echo '<input type="hidden" name="action" value="eum_lifecycle_save_settings">';
    wp_nonce_field('eum_lifecycle_save_settings', 'eum_lifecycle_nonce');

    echo '<h2>Alumni / Former Staff Login</h2><table class="form-table"><tbody>';
    echo '<tr><th scope="row">Allow Alumni Login</th><td><label><input type="checkbox" name="allow_terminated_login" value="1" ' . checked(!empty($settings['allow_terminated_login']), true, false) . '> Former staff may sign in without triggering login-time provisioning.</label></td></tr>';
    echo '<tr><th scope="row">Fallback Account URL</th><td><input type="url" class="large-text" name="terminated_portal_url" value="' . esc_attr((string)$settings['terminated_portal_url']) . '" placeholder="' . esc_attr(home_url('/')) . '"></td></tr>';
    echo '</tbody></table>';

    echo '<h2>Employee IDs</h2><table class="form-table"><tbody>';
    echo '<tr><th scope="row">Prefix</th><td><input type="text" name="employee_id_prefix" value="' . esc_attr((string)$settings['employee_id_prefix']) . '" class="regular-text"></td></tr>';
    echo '<tr><th scope="row">Next Number</th><td><input type="number" min="1" name="employee_id_next" value="' . (int)$settings['employee_id_next'] . '"></td></tr>';
    echo '<tr><th scope="row">Padding</th><td><input type="number" min="1" max="12" name="employee_id_padding" value="' . (int)$settings['employee_id_padding'] . '"></td></tr>';
    echo '<tr><th scope="row">Default Lookup</th><td><label><input type="checkbox" name="employee_id_default_lookup" value="1" ' . checked(!empty($settings['employee_id_default_lookup']), true, false) . '> Treat profile URL user references as Employee IDs before WordPress IDs. Use <code>eum-id</code> for WordPress IDs.</label></td></tr>';
    echo '</tbody></table>';

    echo '<h2>Login Pages</h2><table class="form-table"><tbody>';
    echo '<tr><th scope="row">Internal Login Page</th><td><input type="text" class="regular-text" name="internal_login_page_title" value="' . esc_attr((string)$settings['internal_login_page_title']) . '"> <span class="description">Created/updated with <code>[eum_internal_login]</code>.</span></td></tr>';
    echo '<tr><th scope="row">General External Login Page</th><td><input type="text" class="regular-text" name="external_general_login_page_title" value="' . esc_attr((string)$settings['external_general_login_page_title']) . '"> <span class="description">Created/updated with <code>[eum_external_login]</code>.</span></td></tr>';
    echo '</tbody></table>';
    $this->render_external_orgs_editor((array)$settings['external_orgs']);

    echo '<h2>Leave Types</h2>';
    echo '<p class="description">Configure labels, date limits, requesters, approvers, retained access, and whether accrual is used without editing raw JSON.</p>';
    $this->render_leave_types_editor((array)$settings['leave_types']);

    echo '<h2>Vacation Accrual</h2>';
    $this->render_accrual_editor((array)$settings['vacation_accrual']);
    submit_button('Save Lifecycle Settings');
    echo '</form>';
  }

  private function render_external_orgs_editor(array $orgs): void {
    $rows = array_values($orgs);
    $rows[] = ['key' => '', 'label' => '', 'enabled' => 1, 'login_page_id' => 0, 'login_page_title' => '', 'panel_title' => '', 'panel_body' => ''];
    echo '<h2>External Organizations</h2>';
    echo '<p class="description">Each enabled organization gets its own login page and can show organization-specific portal information.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Enabled</th><th>Key</th><th>Name</th><th>Login Page Title</th><th>Panel Title</th><th>Panel Info</th></tr></thead><tbody>';
    foreach ($rows as $i => $row) {
      echo '<tr>';
      echo '<td><input type="checkbox" name="external_orgs[' . (int)$i . '][enabled]" value="1" ' . checked(!empty($row['enabled']), true, false) . '></td>';
      echo '<td><input type="text" name="external_orgs[' . (int)$i . '][key]" value="' . esc_attr((string)($row['key'] ?? '')) . '" placeholder="vendor_a"></td>';
      echo '<td><input type="text" name="external_orgs[' . (int)$i . '][label]" value="' . esc_attr((string)($row['label'] ?? '')) . '" placeholder="Vendor A"></td>';
      echo '<td><input type="text" name="external_orgs[' . (int)$i . '][login_page_title]" value="' . esc_attr((string)($row['login_page_title'] ?? '')) . '" placeholder="Vendor A Login"><input type="hidden" name="external_orgs[' . (int)$i . '][login_page_id]" value="' . (int)($row['login_page_id'] ?? 0) . '"></td>';
      echo '<td><input type="text" name="external_orgs[' . (int)$i . '][panel_title]" value="' . esc_attr((string)($row['panel_title'] ?? '')) . '" placeholder="Vendor A Portal"></td>';
      echo '<td><textarea name="external_orgs[' . (int)$i . '][panel_body]" rows="3" style="width:100%;">' . esc_textarea((string)($row['panel_body'] ?? '')) . '</textarea></td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
  }

  private function render_leave_types_editor(array $types): void {
    $types = array_values(array_filter($types, static fn($type) => is_array($type) && !self::is_vacation_leave_type((array)$type)));
    $request_options = [
      'user' => 'User',
      'manager' => 'Manager',
      'second_level_manager' => 'Second Level Manager',
      'hr_it' => 'HR/IT',
    ];
    $approver_options = [
      'next_level_manager' => 'Next Level Manager',
      'request_manager' => 'Requestor Manager',
      'hr_it' => 'HR/IT',
    ];
    $access_options = ['portal' => 'Portal', 'mail' => 'Mail', 'nextcloud' => 'Nextcloud'];
    foreach (array_values($types) as $i => $type) {
      echo '<details open style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:14px;margin:0 0 12px;">';
      echo '<summary style="font-weight:700;cursor:pointer;">' . esc_html((string)($type['label'] ?? 'Leave Type')) . '</summary>';
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:12px;">';
      echo '<label><strong>Key</strong><br><input type="text" name="leave_types[' . (int)$i . '][key]" value="' . esc_attr((string)($type['key'] ?? '')) . '"></label>';
      echo '<label><strong>Label</strong><br><input type="text" name="leave_types[' . (int)$i . '][label]" value="' . esc_attr((string)($type['label'] ?? '')) . '"></label>';
      echo '<label><strong>Minimum Return Days</strong><br><input type="number" min="0" name="leave_types[' . (int)$i . '][min_return_days]" value="' . (int)($type['min_return_days'] ?? 0) . '"></label>';
      echo '<label><strong>Maximum Return Days</strong><br><input type="number" min="0" name="leave_types[' . (int)$i . '][max_return_days]" value="' . (int)($type['max_return_days'] ?? 0) . '"></label>';
      echo '<label><input type="checkbox" name="leave_types[' . (int)$i . '][return_required]" value="1" ' . checked(!empty($type['return_required']), true, false) . '> Return date required</label>';
      echo '<label><input type="checkbox" name="leave_types[' . (int)$i . '][uses_accrual]" value="1" ' . checked(!empty($type['uses_accrual']), true, false) . '> Uses vacation accrual</label>';
      echo '</div>';
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:12px;">';
      echo '<fieldset><legend><strong>Who Can Request</strong></legend>' . $this->checkbox_group_html('leave_types[' . (int)$i . '][request_roles][]', $request_options, (array)($type['request_roles'] ?? [])) . '</fieldset>';
      echo '<fieldset><legend><strong>Who Approves</strong></legend>' . $this->checkbox_group_html('leave_types[' . (int)$i . '][approver_roles][]', $approver_options, (array)($type['approver_roles'] ?? [])) . '</fieldset>';
      echo '<fieldset><legend><strong>Retained Access</strong></legend>' . $this->checkbox_group_html('leave_types[' . (int)$i . '][retained_access][]', $access_options, (array)($type['retained_access'] ?? [])) . '</fieldset>';
      echo '</div></details>';
    }
  }

  private function render_accrual_editor(array $accrual): void {
    echo '<section style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:14px;margin-bottom:14px;">';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">';
    echo '<label><input type="checkbox" name="vacation_accrual[enabled]" value="1" ' . checked(!empty($accrual['enabled']), true, false) . '> Enable accrual</label>';
    echo '<label><strong>Legacy Monthly Hours</strong><br><input type="number" step="0.01" min="0" name="vacation_accrual[monthly_hours]" value="' . esc_attr((string)($accrual['monthly_hours'] ?? 0)) . '"></label>';
    echo '<label><strong>Maximum Balance</strong><br><input type="number" step="0.01" min="0" name="vacation_accrual[max_balance_hours]" value="' . esc_attr((string)($accrual['max_balance_hours'] ?? 0)) . '"></label>';
    echo '<label><strong>Hours Per Leave Day</strong><br><input type="number" step="0.01" min="1" name="vacation_accrual[daily_hours]" value="' . esc_attr((string)($accrual['daily_hours'] ?? 8)) . '"></label>';
    echo '<label><input type="checkbox" name="vacation_accrual[allow_negative]" value="1" ' . checked(!empty($accrual['allow_negative']), true, false) . '> Allow negative vacation balance</label>';
    echo '<label><strong>Reset Mode</strong><br><select name="vacation_accrual[reset_mode]">' . $this->select_options(['none' => 'No automatic reset', 'specific_day' => 'Specific day each year', 'anniversary' => 'Employment anniversary'], (string)($accrual['reset_mode'] ?? 'none')) . '</select></label>';
    echo '<label><strong>Reset Day</strong><br><input type="text" name="vacation_accrual[reset_month_day]" value="' . esc_attr((string)($accrual['reset_month_day'] ?? '')) . '" placeholder="MM-DD"></label>';
    echo '<label><strong>Set Mode</strong><br><select name="vacation_accrual[set_mode]">' . $this->select_options(['hire_date' => 'Hire date', 'specific_date' => 'Specific date', 'anniversary' => 'Employment anniversary'], (string)($accrual['set_mode'] ?? 'hire_date')) . '</select></label>';
    echo '<label><strong>Set Date</strong><br><input type="date" name="vacation_accrual[set_date]" value="' . esc_attr((string)($accrual['set_date'] ?? '')) . '"></label>';
    echo '</div></section>';

    $rules = array_values((array)($accrual['rules'] ?? []));
    $rules[] = ['enabled' => 0, 'label' => '', 'period' => 'month', 'base_hours' => 0, 'multiplier' => 1, 'years_min' => 0, 'years_max' => 0, 'hours_window_days' => 0, 'hours_average' => 0, 'hours_average_cap' => 0, 'worker_types' => [], 'roles' => [], 'pay_structures' => '', 'expires_after_days' => 0];
    echo '<h3>Accrual Rules</h3>';
    foreach ($rules as $i => $rule) {
      echo '<details style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:14px;margin:0 0 12px;">';
      echo '<summary style="font-weight:700;cursor:pointer;">' . esc_html((string)($rule['label'] ?: 'New Accrual Rule')) . '</summary>';
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-top:12px;">';
      echo '<label><input type="checkbox" name="vacation_accrual[rules][' . (int)$i . '][enabled]" value="1" ' . checked(!empty($rule['enabled']), true, false) . '> Enabled</label>';
      echo '<label><strong>Label</strong><br><input type="text" name="vacation_accrual[rules][' . (int)$i . '][label]" value="' . esc_attr((string)($rule['label'] ?? '')) . '"></label>';
      echo '<label><strong>Period</strong><br><select name="vacation_accrual[rules][' . (int)$i . '][period]">' . $this->select_options(['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'], (string)($rule['period'] ?? 'month')) . '</select></label>';
      echo '<label><strong>Base Hours</strong><br><input type="number" step="0.01" min="0" name="vacation_accrual[rules][' . (int)$i . '][base_hours]" value="' . esc_attr((string)($rule['base_hours'] ?? 0)) . '"></label>';
      echo '<label><strong>Multiplier</strong><br><input type="number" step="0.01" min="0" name="vacation_accrual[rules][' . (int)$i . '][multiplier]" value="' . esc_attr((string)($rule['multiplier'] ?? 1)) . '"></label>';
      echo '<label><strong>Years Min</strong><br><input type="number" step="0.01" min="0" name="vacation_accrual[rules][' . (int)$i . '][years_min]" value="' . esc_attr((string)($rule['years_min'] ?? 0)) . '"></label>';
      echo '<label><strong>Years Max</strong><br><input type="number" step="0.01" min="0" name="vacation_accrual[rules][' . (int)$i . '][years_max]" value="' . esc_attr((string)($rule['years_max'] ?? 0)) . '"></label>';
      echo '<label><strong>Hours Window Days</strong><br><input type="number" min="0" name="vacation_accrual[rules][' . (int)$i . '][hours_window_days]" value="' . (int)($rule['hours_window_days'] ?? 0) . '"></label>';
      echo '<label><input type="checkbox" name="vacation_accrual[rules][' . (int)$i . '][hours_average]" value="1" ' . checked(!empty($rule['hours_average']), true, false) . '> Average logged hours</label>';
      echo '<label><strong>Average Cap</strong><br><input type="number" step="0.01" min="0" name="vacation_accrual[rules][' . (int)$i . '][hours_average_cap]" value="' . esc_attr((string)($rule['hours_average_cap'] ?? 0)) . '"></label>';
      echo '<label><strong>Pay Structures</strong><br><input type="text" name="vacation_accrual[rules][' . (int)$i . '][pay_structures]" value="' . esc_attr((string)($rule['pay_structures'] ?? '')) . '" placeholder="hourly,salary"></label>';
      echo '<label><strong>Expires After Days</strong><br><input type="number" min="0" name="vacation_accrual[rules][' . (int)$i . '][expires_after_days]" value="' . (int)($rule['expires_after_days'] ?? 0) . '"></label>';
      echo '</div>';
      echo '<fieldset style="margin-top:12px;"><legend><strong>User Types</strong></legend>' . $this->checkbox_group_html('vacation_accrual[rules][' . (int)$i . '][worker_types][]', class_exists('EUM_Profiles') ? EUM_Profiles::worker_type_options() : [], (array)($rule['worker_types'] ?? [])) . '</fieldset>';
      echo '<fieldset style="margin-top:12px;"><legend><strong>WordPress Roles</strong></legend>' . $this->checkbox_group_html('vacation_accrual[rules][' . (int)$i . '][roles][]', $this->wp_role_options(), (array)($rule['roles'] ?? [])) . '</fieldset>';
      echo '</details>';
    }
  }

  private function checkbox_group_html(string $name, array $options, array $selected): string {
    $selected = array_map('strval', $selected);
    $html = '<div style="display:grid;gap:6px;">';
    foreach ($options as $value => $label) {
      $html .= '<label><input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr((string)$value) . '" ' . checked(in_array((string)$value, $selected, true), true, false) . '> ' . esc_html((string)$label) . '</label>';
    }
    return $html . '</div>';
  }

  private function select_options(array $options, string $selected): string {
    $html = '';
    foreach ($options as $value => $label) $html .= '<option value="' . esc_attr((string)$value) . '" ' . selected($selected, (string)$value, false) . '>' . esc_html((string)$label) . '</option>';
    return $html;
  }

  private function wp_role_options(): array {
    $out = [];
    if (function_exists('wp_roles') && wp_roles()) {
      foreach ((array)wp_roles()->roles as $key => $row) $out[sanitize_key((string)$key)] = sanitize_text_field((string)($row['name'] ?? $key));
    }
    return $out;
  }

  private function render_leave_admin_tab(): void {
    echo '<div style="margin-top:18px;max-width:1200px;">';
    echo $this->render_leave_requests_table($this->leave_requests(['limit' => 100]), true);
    echo '</div>';
  }

  private function render_availability_admin_tab(): void {
    echo '<div style="margin-top:18px;max-width:1200px;">';
    echo '<p class="description">Availability requests support multiple daily segments. Weekly overrides win for that week; otherwise the newest approved default rule whose as-of date has arrived is used.</p>';
    echo $this->render_availability_requests_table($this->availability_requests(['limit' => 100]), true);
    echo '</div>';
  }

  private function render_employee_ids_tab(): void {
    echo '<div style="margin-top:18px;display:grid;grid-template-columns:minmax(280px,520px) 1fr;gap:18px;align-items:start;">';
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px;">';
    echo '<h2 style="margin-top:0;">Assign Missing IDs</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_employee_id_assign_missing">';
    wp_nonce_field('eum_employee_id_assign_missing', 'eum_employee_id_nonce');
    submit_button('Assign Missing Employee IDs', 'primary', 'submit', false);
    echo '</form></div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px;">';
    echo '<h2 style="margin-top:0;">Import Employee IDs</h2>';
    echo '<p class="description">CSV lines: <code>user reference,employee_id</code>. User reference may be WordPress ID, login, email, or existing employee ID.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_employee_id_import">';
    wp_nonce_field('eum_employee_id_import', 'eum_employee_id_import_nonce');
    echo '<textarea name="employee_id_csv" rows="10" class="large-text code" placeholder="jsimpson,E01001"></textarea>';
    submit_button('Import Employee IDs', 'secondary');
    echo '</form></div></div>';
  }

  public function handle_save_settings(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_lifecycle_nonce']) || !wp_verify_nonce((string)$_POST['eum_lifecycle_nonce'], 'eum_lifecycle_save_settings')) wp_die('Bad nonce.');
    $settings = self::get_settings();
    $settings['allow_terminated_login'] = isset($_POST['allow_terminated_login']) ? 1 : 0;
    $settings['terminated_portal_url'] = esc_url_raw((string)($_POST['terminated_portal_url'] ?? ''));
    $settings['employee_id_prefix'] = sanitize_text_field((string)($_POST['employee_id_prefix'] ?? 'E'));
    $settings['employee_id_next'] = max(1, (int)($_POST['employee_id_next'] ?? 1000));
    $settings['employee_id_padding'] = max(1, min(12, (int)($_POST['employee_id_padding'] ?? 5)));
    $settings['employee_id_default_lookup'] = isset($_POST['employee_id_default_lookup']) ? 1 : 0;
    $settings['internal_login_page_title'] = sanitize_text_field((string)($_POST['internal_login_page_title'] ?? 'Internal Login'));
    $settings['external_general_login_page_title'] = sanitize_text_field((string)($_POST['external_general_login_page_title'] ?? 'External Login'));
    $settings['external_orgs'] = self::normalize_external_orgs((array)($_POST['external_orgs'] ?? []));

    if (isset($_POST['leave_types']) && is_array($_POST['leave_types'])) {
      $settings['leave_types'] = self::normalize_leave_types((array)$_POST['leave_types']);
    } else {
      $leave_types = json_decode((string)wp_unslash($_POST['leave_types_json'] ?? ''), true);
      if (is_array($leave_types)) $settings['leave_types'] = self::normalize_leave_types($leave_types);
    }

    if (isset($_POST['vacation_accrual']) && is_array($_POST['vacation_accrual'])) {
      $settings['vacation_accrual'] = self::normalize_accrual_settings((array)$_POST['vacation_accrual']);
    } else {
      $accrual = json_decode((string)wp_unslash($_POST['vacation_accrual_json'] ?? ''), true);
      if (is_array($accrual)) $settings['vacation_accrual'] = self::normalize_accrual_settings($accrual);
    }

    $settings = $this->ensure_login_pages_for_settings($settings);

    update_option(self::OPTION_KEY, $settings, false);
    $this->redirect_admin('settings', 'Lifecycle settings saved.');
  }

  private function ensure_login_pages_for_settings(array $settings): array {
    if (!current_user_can('edit_pages')) return $settings;
    $settings['internal_login_page_id'] = $this->ensure_login_page(
      (int)($settings['internal_login_page_id'] ?? 0),
      (string)($settings['internal_login_page_title'] ?? 'Internal Login'),
      '[eum_internal_login]'
    );
    $settings['external_general_login_page_id'] = $this->ensure_login_page(
      (int)($settings['external_general_login_page_id'] ?? 0),
      (string)($settings['external_general_login_page_title'] ?? 'External Login'),
      '[eum_external_login]'
    );
    $orgs = [];
    foreach ((array)($settings['external_orgs'] ?? []) as $org) {
      if (empty($org['enabled']) || empty($org['key'])) {
        $orgs[] = $org;
        continue;
      }
      $org['login_page_id'] = $this->ensure_login_page(
        (int)($org['login_page_id'] ?? 0),
        (string)($org['login_page_title'] ?? ((string)$org['label'] . ' Login')),
        '[eum_external_login org="' . esc_attr((string)$org['key']) . '"]'
      );
      $orgs[] = $org;
    }
    $settings['external_orgs'] = $orgs;
    return $settings;
  }

  private function ensure_login_page(int $page_id, string $title, string $content): int {
    $title = sanitize_text_field($title);
    if ($title === '') return 0;
    $existing = $page_id > 0 ? get_post($page_id) : null;
    if ($existing && $existing->post_type === 'page') {
      wp_update_post(['ID' => $page_id, 'post_title' => $title, 'post_content' => $content]);
      return $page_id;
    }
    $found = get_page_by_title($title, OBJECT, 'page');
    if ($found) {
      wp_update_post(['ID' => (int)$found->ID, 'post_content' => $content]);
      return (int)$found->ID;
    }
    return (int)wp_insert_post([
      'post_type' => 'page',
      'post_status' => 'publish',
      'post_title' => $title,
      'post_content' => $content,
    ]);
  }

  public function handle_assign_missing_ids(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_employee_id_nonce']) || !wp_verify_nonce((string)$_POST['eum_employee_id_nonce'], 'eum_employee_id_assign_missing')) wp_die('Bad nonce.');
    $count = 0;
    foreach (get_users(['fields' => 'ID', 'number' => 0]) as $user_id) {
      if (self::employee_id_for_user((int)$user_id) !== '') continue;
      if (self::assign_next_employee_id((int)$user_id) !== '') $count++;
    }
    $this->redirect_admin('employee_ids', 'Assigned ' . $count . ' employee ID(s).');
  }

  public function handle_employee_id_import(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_employee_id_import_nonce']) || !wp_verify_nonce((string)$_POST['eum_employee_id_import_nonce'], 'eum_employee_id_import')) wp_die('Bad nonce.');
    $raw = (string)wp_unslash($_POST['employee_id_csv'] ?? '');
    $updated = 0;
    $skipped = 0;
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      $cols = str_getcsv($line);
      $ref = trim((string)($cols[0] ?? ''));
      $employee_id = trim((string)($cols[1] ?? ''));
      if ($ref === '' || $employee_id === '') {
        $skipped++;
        continue;
      }
      $user_id = self::resolve_user_reference($ref, false);
      if ($user_id <= 0) {
        $skipped++;
        continue;
      }
      self::set_employee_id($user_id, $employee_id);
      $updated++;
    }
    $this->redirect_admin('employee_ids', 'Imported ' . $updated . ' employee ID(s).' . ($skipped ? ' Skipped ' . $skipped . '.' : ''));
  }

  private function redirect_admin(string $tab, string $msg, string $type = 'success'): void {
    wp_safe_redirect(add_query_arg(['page' => 'eum-lifecycle', 'tab' => $tab, 'eum_msg' => $msg, 'eum_type' => $type], admin_url('admin.php')));
    exit;
  }

  public function handle_user_register(int $user_id): void {
    self::assign_next_employee_id((int)$user_id, false);
  }

  public function handle_hire_applied(int $user_id, int $hire_id, array $context = []): void {
    self::assign_next_employee_id((int)$user_id, false);
    $effective_at = sanitize_text_field((string)($context['effective_at'] ?? ''));
    if ($effective_at !== '' && class_exists('EUM_Fields')) {
      $custom = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      if (empty($custom['hire_date'])) {
        $custom['hire_date'] = substr($effective_at, 0, 10);
        update_user_meta($user_id, EUM_Fields::USER_META_KEY, $custom);
      }
    }
  }

  public function shortcode_terminated_user_portal($atts = []): string {
    if (!is_user_logged_in()) return '<div class="eum-terminated-portal"><p>Please sign in to view your account portal.</p></div>';
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);
    $terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
    ob_start();
    echo '<div class="eum-terminated-portal" style="max-width:820px;border:1px solid #dcdcde;border-radius:14px;padding:20px;background:#fff;">';
    echo '<h3 style="margin-top:0;">Account Portal</h3>';
    if ($terminated) {
      echo '<p><strong>Status:</strong> ' . esc_html(self::employment_status_label_for_user($user_id, $user_id)) . '</p>';
      echo '<p>This portal keeps your WordPress account accessible for personal records and HR follow-up. External services and mail stay disabled unless HR/IT reactivates the account.</p>';
    } else {
      echo '<p><strong>Status:</strong> Active</p>';
    }
    echo '<p><strong>Name:</strong> ' . esc_html($user ? ($user->display_name ?: $user->user_login) : ('User #' . $user_id)) . '</p>';
    $employee_id = self::employee_id_for_user($user_id);
    if ($employee_id !== '') echo '<p><strong>Employee ID:</strong> <code>' . esc_html($employee_id) . '</code></p>';
    if (class_exists('EUM_Fields')) {
      $fields = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      if (!empty($fields['personal_email'])) echo '<p><strong>Personal Email:</strong> ' . esc_html((string)$fields['personal_email']) . '</p>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  public function shortcode_internal_login($atts = []): string {
    return $this->login_form_html('internal', '');
  }

  public function shortcode_external_login($atts = []): string {
    $atts = shortcode_atts(['org' => ''], (array)$atts, 'eum_external_login');
    return $this->login_form_html('external', sanitize_key((string)$atts['org']));
  }

  public function shortcode_external_org_panel($atts = []): string {
    $atts = shortcode_atts(['org' => ''], (array)$atts, 'eum_external_org_panel');
    $org_key = sanitize_key((string)$atts['org']);
    if ($org_key === '' && is_user_logged_in()) $org_key = self::external_org_key_for_user((int)get_current_user_id());
    return $this->external_org_panel_html($org_key);
  }

  private function login_form_html(string $kind, string $org_key = ''): string {
    $org = $org_key !== '' ? self::external_org_by_key($org_key) : [];
    ob_start();
    echo '<div class="eum-login-panel" style="max-width:520px;background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:22px;">';
    if ($kind === 'external') {
      echo $this->external_org_panel_html($org_key);
    }
    if (is_user_logged_in()) {
      $user_id = (int)get_current_user_id();
      echo '<p>You are signed in as <strong>' . esc_html(self::employee_list_label($user_id)) . '</strong>.</p>';
      echo '</div>';
      return (string)ob_get_clean();
    }
    echo '<h3 style="margin-top:0;">' . esc_html($kind === 'internal' ? 'Internal Login' : (!empty($org['label']) ? (string)$org['label'] . ' Login' : 'External Login')) . '</h3>';
    echo wp_login_form([
      'echo' => false,
      'redirect' => home_url('/'),
      'label_username' => 'Username, Email, or Employee ID',
      'label_password' => 'Password',
      'label_log_in' => 'Sign In',
    ]);
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function external_org_panel_html(string $org_key): string {
    $org = $org_key !== '' ? self::external_org_by_key($org_key) : [];
    if (empty($org)) return '';
    $title = (string)($org['panel_title'] ?? $org['label']);
    $body = (string)($org['panel_body'] ?? '');
    if ($title === '' && $body === '') return '';
    return '<section class="eum-external-org-panel" style="margin-bottom:16px;padding:14px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;"><h3 style="margin:0 0 8px;">' . esc_html($title) . '</h3>' . ($body !== '' ? '<div>' . wp_kses_post(wpautop($body)) . '</div>' : '') . '</section>';
  }

  public static function external_org_by_key(string $key): array {
    $key = sanitize_key($key);
    if ($key === '') return [];
    foreach ((array)self::get_settings()['external_orgs'] as $org) {
      if ((string)($org['key'] ?? '') === $key) return (array)$org;
    }
    return [];
  }

  public static function external_org_key_for_user(int $user_id): string {
    if ($user_id <= 0 || !class_exists('EUM_Fields')) return '';
    $fields = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    return sanitize_key((string)($fields['external_org'] ?? ''));
  }

  public static function schedule_leave_request_panel_html(int $actor_user_id, int $target_user_id, string $return_url, bool $manager_context = false): string {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return '';
    $types = array_values(array_filter(self::leave_types(), static function($type) use ($actor_user_id, $target_user_id) {
      return self::actor_can_request_leave_type($actor_user_id, $target_user_id, (array)$type);
    }));
    if (empty($types)) return '';
    ob_start();
    echo '<div class="eum-schedule-panel eum-schedule-panel--leave">';
    echo '<h4 style="margin:0 0 10px;">Request Leave of Absence</h4>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-schedule-panel__actions" style="align-items:flex-end;">';
    echo '<input type="hidden" name="action" value="eum_leave_submit"><input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '"><input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_leave_submit', 'eum_leave_nonce');
    echo '<label><span>Person</span><input type="text" value="' . esc_attr(self::employee_list_label($target_user_id)) . '" readonly></label>';
    echo '<label><span>Leave Type</span><select name="leave_type">';
    foreach ($types as $type) echo '<option value="' . esc_attr((string)$type['key']) . '">' . esc_html((string)$type['label']) . '</option>';
    echo '</select></label>';
    echo '<label><span>Start</span><input type="date" name="start_date" required></label>';
    echo '<label><span>Return</span><input type="date" name="return_date"></label>';
    echo '<label><span>Reason</span><input type="text" name="reason" placeholder="Optional"></label>';
    echo '<button type="submit" class="button button-primary">' . esc_html($manager_context ? 'Submit for Approval' : 'Request Leave of Absence') . '</button>';
    echo '</form></div>';
    return (string)ob_get_clean();
  }

  private static function actor_can_request_leave_type(int $actor_user_id, int $target_user_id, array $type): bool {
    $roles = (array)($type['request_roles'] ?? []);
    if (in_array('hr_it', $roles, true) && class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if (in_array('user', $roles, true) && $actor_user_id === $target_user_id) return true;
    if (in_array('manager', $roles, true) && class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $target_user_id)) return true;
    if (in_array('second_level_manager', $roles, true) && class_exists('EUM_Personnel') && class_exists('EUM_Profiles')) {
      $manager_id = EUM_Personnel::effective_manager_user_id($target_user_id);
      if ($manager_id > 0 && EUM_Profiles::is_manager_of_target($actor_user_id, $manager_id)) return true;
    }
    return false;
  }

  public function shortcode_leave_portal($atts = []): string {
    if (!is_user_logged_in()) return '<div class="eum-leave-portal"><p>Please sign in to request leave.</p></div>';
    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_GET['eum_leave_user']) ? max(0, (int)$_GET['eum_leave_user']) : $actor_user_id;
    if ($target_user_id <= 0 || !$this->can_request_any_leave_for_user($actor_user_id, $target_user_id)) $target_user_id = $actor_user_id;
    $types = self::leave_types();
    $recent = $this->visible_leave_requests_for_actor($actor_user_id, 50);
    $msg = isset($_GET['eum_leave_msg']) ? sanitize_text_field((string)$_GET['eum_leave_msg']) : '';
    $type = sanitize_key((string)($_GET['eum_leave_type'] ?? 'success'));

    ob_start();
    echo '<div class="eum-leave-portal" style="max-width:1100px;">';
    if ($msg !== '') echo '<div style="padding:12px 14px;margin-bottom:16px;border-radius:10px;border:1px solid ' . ($type === 'error' ? '#d63638;background:#fcf0f1;' : '#00a32a;background:#ecf7ed;') . '">' . esc_html($msg) . '</div>';
    echo '<div style="display:grid;grid-template-columns:minmax(280px,420px) 1fr;gap:18px;align-items:start;">';
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px;">';
    echo '<h3 style="margin-top:0;">Request Leave of Absence</h3>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_leave_submit"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_leave_submit', 'eum_leave_nonce');
    echo '<p><label><strong>User</strong></label><br>' . $this->render_leave_target_select($actor_user_id, $target_user_id) . '</p>';
    echo '<p><label><strong>Leave Type</strong></label><br><select name="leave_type" required>';
    foreach ($types as $row) echo '<option value="' . esc_attr((string)$row['key']) . '">' . esc_html((string)$row['label']) . '</option>';
    echo '</select></p>';
    echo '<p><label><strong>Start Date</strong></label><br><input type="date" name="start_date" required></p>';
    echo '<p><label><strong>Expected Return Date</strong></label><br><input type="date" name="return_date"></p>';
    echo '<p><label><strong>Reason</strong></label><br><input type="text" name="reason" class="regular-text"></p>';
    echo '<p><label><strong>Notes</strong></label><br><textarea name="notes" rows="4" class="large-text"></textarea></p>';
    echo '<p><button class="button button-primary" type="submit">Submit Request</button></p>';
    echo '</form></div>';
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px;">';
    echo '<h3 style="margin-top:0;">Leave of Absence Requests</h3>';
    echo $this->render_leave_requests_table($recent, false);
    echo '</div></div></div>';
    return ob_get_clean();
  }

  public function handle_leave_submit(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if (!isset($_POST['eum_leave_nonce']) || !wp_verify_nonce((string)$_POST['eum_leave_nonce'], 'eum_leave_submit')) $this->redirect_leave($return_url, 'Security check failed.', 'error');

    $target_user_id = max(0, (int)($_POST['target_user_id'] ?? $actor_user_id));
    $type_key = sanitize_key((string)($_POST['leave_type'] ?? ''));
    $leave_type = self::leave_type($type_key);
    $start_date = self::sanitize_date((string)($_POST['start_date'] ?? ''));
    $return_date = self::sanitize_date((string)($_POST['return_date'] ?? ''));
    $reason = sanitize_text_field((string)($_POST['reason'] ?? ''));
    $notes = sanitize_textarea_field((string)($_POST['notes'] ?? ''));

    if (!$target_user_id || !get_userdata($target_user_id)) $this->redirect_leave($return_url, 'Select a valid user.', 'error');
    if (empty($leave_type)) $this->redirect_leave($return_url, 'Select a valid leave type.', 'error');
    if (self::is_vacation_leave_type($leave_type)) $this->redirect_leave($return_url, 'Vacation is scheduled from the Schedule page, not the leave portal.', 'error');
    if (!$this->can_request_leave_type($actor_user_id, $target_user_id, $leave_type)) $this->redirect_leave($return_url, 'You cannot request this leave type for that user.', 'error');
    if ($start_date === '') $this->redirect_leave($return_url, 'Start date is required.', 'error');
    if (!empty($leave_type['return_required']) && $return_date === '') $this->redirect_leave($return_url, 'Expected return date is required for this leave type.', 'error');

    $date_error = $this->validate_leave_dates($start_date, $return_date, $leave_type);
    if ($date_error !== '') $this->redirect_leave($return_url, $date_error, 'error');

    $hours = self::requested_leave_hours($start_date, $return_date);
    if (!empty($leave_type['uses_accrual'])) {
      $balance = self::vacation_balance_hours($target_user_id);
      $accrual = self::get_settings()['vacation_accrual'];
      if (empty($accrual['allow_negative']) && $hours > $balance) {
        $this->redirect_leave($return_url, 'That request exceeds the current vacation balance.', 'error');
      }
    }

    $target = get_userdata($target_user_id);
    $post_id = (int)wp_insert_post([
      'post_type' => self::CPT_LEAVE,
      'post_status' => 'publish',
      'post_author' => $actor_user_id,
      'post_title' => 'Leave - ' . ($target ? ($target->display_name ?: $target->user_login) : ('User #' . $target_user_id)) . ' - ' . (string)$leave_type['label'],
    ]);
    if ($post_id <= 0) $this->redirect_leave($return_url, 'Could not save leave request.', 'error');

    $approver_ids = $this->approver_ids_for_leave($actor_user_id, $target_user_id, $leave_type);
    update_post_meta($post_id, self::META_TARGET_USER, $target_user_id);
    update_post_meta($post_id, self::META_ACTOR_USER, $actor_user_id);
    update_post_meta($post_id, self::META_TYPE, $type_key);
    update_post_meta($post_id, self::META_STATUS, 'pending');
    update_post_meta($post_id, self::META_START_DATE, $start_date);
    update_post_meta($post_id, self::META_RETURN_DATE, $return_date);
    update_post_meta($post_id, self::META_HOURS, $hours);
    update_post_meta($post_id, self::META_REASON, $reason);
    update_post_meta($post_id, self::META_NOTES, $notes);
    update_post_meta($post_id, self::META_APPROVER_IDS, $approver_ids);
    update_post_meta($post_id, self::META_RETAINED_ACCESS, (array)($leave_type['retained_access'] ?? []));

    do_action('eum_leave_request_created', $post_id, $target_user_id, $actor_user_id);
    $this->redirect_leave($return_url, 'Leave request submitted.');
  }

  public function handle_leave_action(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $leave_id = max(0, (int)($_POST['leave_id'] ?? 0));
    $action = sanitize_key((string)($_POST['leave_action'] ?? ''));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if ($leave_id <= 0 || !isset($_POST['eum_leave_action_nonce']) || !wp_verify_nonce((string)$_POST['eum_leave_action_nonce'], 'eum_leave_action_' . $leave_id)) $this->redirect_leave($return_url, 'Security check failed.', 'error');
    $post = get_post($leave_id);
    if (!$post || $post->post_type !== self::CPT_LEAVE) $this->redirect_leave($return_url, 'Leave request not found.', 'error');
    $status = (string)get_post_meta($leave_id, self::META_STATUS, true);
    $target_user_id = (int)get_post_meta($leave_id, self::META_TARGET_USER, true);
    $requestor_id = (int)get_post_meta($leave_id, self::META_ACTOR_USER, true);

    if ($action === 'cancel') {
      if (!in_array($status, ['pending', 'approved'], true) || ($actor_user_id !== $requestor_id && !EUM_Access::is_it_or_hr($actor_user_id))) $this->redirect_leave($return_url, 'You cannot cancel this request.', 'error');
      update_post_meta($leave_id, self::META_STATUS, 'cancelled');
      do_action('eum_leave_status_changed', $leave_id, $target_user_id, 'cancelled', $actor_user_id);
      $this->redirect_leave($return_url, 'Leave request cancelled.');
    }

    if (!in_array($action, ['approve', 'reject'], true)) $this->redirect_leave($return_url, 'Unknown leave action.', 'error');
    if ($status !== 'pending') $this->redirect_leave($return_url, 'Only pending requests can be approved or rejected.', 'error');
    if (!$this->can_approve_leave($actor_user_id, $leave_id)) $this->redirect_leave($return_url, 'You cannot approve this request.', 'error');

    $new_status = $action === 'approve' ? 'approved' : 'rejected';
    update_post_meta($leave_id, self::META_STATUS, $new_status);
    update_post_meta($leave_id, self::META_APPROVED_BY, $actor_user_id);
    update_post_meta($leave_id, self::META_APPROVED_AT, current_time('mysql'));
    do_action('eum_leave_status_changed', $leave_id, $target_user_id, $new_status, $actor_user_id);
    $this->redirect_leave($return_url, 'Leave request ' . $new_status . '.');
  }

  private function redirect_leave(string $url, string $msg, string $type = 'success'): void {
    wp_safe_redirect(add_query_arg(['eum_leave_msg' => $msg, 'eum_leave_type' => $type], $url ?: home_url('/')));
    exit;
  }

  public static function leave_types(): array {
    return array_values(array_filter((array)self::get_settings()['leave_types'], static fn($type) => is_array($type) && !self::is_vacation_leave_type((array)$type)));
  }

  private static function all_leave_types(): array {
    return (array)self::get_settings()['leave_types'];
  }

  public static function leave_type(string $key): array {
    $key = sanitize_key($key);
    foreach (self::all_leave_types() as $type) {
      if ((string)($type['key'] ?? '') === $key) return (array)$type;
    }
    if ($key === 'vacation') {
      return [
        'key' => 'vacation',
        'label' => 'Vacation',
        'min_return_days' => 1,
        'max_return_days' => 60,
        'return_required' => 1,
        'uses_accrual' => 1,
        'request_roles' => [],
        'approver_roles' => [],
        'retained_access' => [],
      ];
    }
    return [];
  }

  private function can_request_any_leave_for_user(int $actor_user_id, int $target_user_id): bool {
    foreach (self::leave_types() as $type) {
      if ($this->can_request_leave_type($actor_user_id, $target_user_id, $type)) return true;
    }
    return false;
  }

  private function can_request_leave_type(int $actor_user_id, int $target_user_id, array $type): bool {
    $roles = (array)($type['request_roles'] ?? []);
    if (in_array('hr_it', $roles, true) && EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if (in_array('user', $roles, true) && $actor_user_id === $target_user_id) return true;
    if (in_array('manager', $roles, true) && class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $target_user_id)) return true;
    if (in_array('second_level_manager', $roles, true) && $this->is_second_level_manager_of($actor_user_id, $target_user_id)) return true;
    return false;
  }

  private function is_second_level_manager_of(int $actor_user_id, int $target_user_id): bool {
    if (!class_exists('EUM_Personnel') || !class_exists('EUM_Profiles')) return false;
    $manager_id = EUM_Personnel::effective_manager_user_id($target_user_id);
    return $manager_id > 0 && EUM_Profiles::is_manager_of_target($actor_user_id, $manager_id);
  }

  private function can_approve_leave(int $actor_user_id, int $leave_id): bool {
    if ($actor_user_id <= 0) return false;
    $target_user_id = (int)get_post_meta($leave_id, self::META_TARGET_USER, true);
    $requestor_id = (int)get_post_meta($leave_id, self::META_ACTOR_USER, true);
    if ($actor_user_id === $target_user_id) return false;
    $type = self::leave_type((string)get_post_meta($leave_id, self::META_TYPE, true));
    $roles = (array)($type['approver_roles'] ?? []);
    if (in_array('hr_it', $roles, true) && EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if (in_array($actor_user_id, array_map('intval', (array)get_post_meta($leave_id, self::META_APPROVER_IDS, true)), true)) return true;
    if (in_array('next_level_manager', $roles, true) && class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $target_user_id)) return true;
    if (in_array('request_manager', $roles, true) && class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $requestor_id)) return true;
    return false;
  }

  private function approver_ids_for_leave(int $actor_user_id, int $target_user_id, array $type): array {
    $ids = [];
    $roles = (array)($type['approver_roles'] ?? []);
    if (class_exists('EUM_Personnel')) {
      if (in_array('next_level_manager', $roles, true)) {
        $ids[] = EUM_Personnel::effective_manager_user_id($target_user_id);
      }
      if (in_array('request_manager', $roles, true)) {
        $ids[] = EUM_Personnel::effective_manager_user_id($actor_user_id);
      }
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
  }

  private function validate_leave_dates(string $start_date, string $return_date, array $type): string {
    if ($return_date === '') return '';
    $start = strtotime($start_date . ' 00:00:00');
    $return = strtotime($return_date . ' 00:00:00');
    if (!$start || !$return) return 'Use valid leave dates.';
    if ($return < $start) return 'Expected return date cannot be before the start date.';
    $days = (int)floor(($return - $start) / DAY_IN_SECONDS);
    $min = (int)($type['min_return_days'] ?? 0);
    $max = (int)($type['max_return_days'] ?? 365);
    if ($days < $min) return 'Expected return date must be at least ' . $min . ' day(s) after the start date.';
    if ($max > 0 && $days > $max) return 'Expected return date cannot be more than ' . $max . ' day(s) after the start date.';
    return '';
  }

  public static function requested_leave_hours(string $start_date, string $return_date): float {
    if ($start_date === '' || $return_date === '') return 0.0;
    $start = strtotime($start_date . ' 00:00:00');
    $return = strtotime($return_date . ' 00:00:00');
    if (!$start || !$return || $return <= $start) return (float)self::get_settings()['vacation_accrual']['daily_hours'];
    $days = max(1, (int)ceil(($return - $start) / DAY_IN_SECONDS));
    return $days * (float)self::get_settings()['vacation_accrual']['daily_hours'];
  }

  private function render_leave_target_select(int $actor_user_id, int $selected_user_id): string {
    if (!EUM_Access::is_it_or_hr($actor_user_id) && !$this->actor_manages_anyone($actor_user_id)) {
      return '<input type="hidden" name="target_user_id" value="' . (int)$actor_user_id . '">' . esc_html($this->user_label($actor_user_id));
    }
    $html = '<select name="target_user_id">';
    foreach (get_users(['orderby' => 'display_name', 'order' => 'ASC', 'number' => 0]) as $user) {
      if (!$this->can_request_any_leave_for_user($actor_user_id, (int)$user->ID)) continue;
      $html .= '<option value="' . (int)$user->ID . '" ' . selected($selected_user_id, (int)$user->ID, false) . '>' . esc_html($this->user_label((int)$user->ID)) . '</option>';
    }
    $html .= '</select>';
    return $html;
  }

  private function actor_manages_anyone(int $actor_user_id): bool {
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if (!class_exists('EUM_Profiles')) return false;
    foreach (get_users(['fields' => 'ID', 'number' => 250]) as $user_id) {
      if (EUM_Profiles::is_manager_of_target($actor_user_id, (int)$user_id)) return true;
    }
    return false;
  }

  private function leave_requests(array $args = []): array {
    $status = sanitize_key((string)($args['status'] ?? ''));
    $meta_query = [];
    if ($status !== '') $meta_query[] = ['key' => self::META_STATUS, 'value' => $status];
    return get_posts([
      'post_type' => self::CPT_LEAVE,
      'post_status' => 'publish',
      'numberposts' => max(1, (int)($args['limit'] ?? 50)),
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => $meta_query,
    ]);
  }

  private function visible_leave_requests_for_actor(int $actor_user_id, int $limit = 50): array {
    $items = $this->leave_requests(['limit' => $limit * 4]);
    if (EUM_Access::is_it_or_hr($actor_user_id)) return array_slice($items, 0, $limit);
    $out = [];
    foreach ($items as $item) {
      $leave_id = (int)$item->ID;
      $target = (int)get_post_meta($leave_id, self::META_TARGET_USER, true);
      $requestor = (int)get_post_meta($leave_id, self::META_ACTOR_USER, true);
      if ($actor_user_id === $target || $actor_user_id === $requestor || $this->can_approve_leave($actor_user_id, $leave_id) || (class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $target))) {
        $out[] = $item;
      }
      if (count($out) >= $limit) break;
    }
    return $out;
  }

  private function render_leave_requests_table(array $items, bool $admin): string {
    if (empty($items)) return '<p>No leave requests found.</p>';
    ob_start();
    echo '<table class="widefat striped"><thead><tr><th>User</th><th>Type</th><th>Dates</th><th>Hours</th><th>Status</th><th>Reason</th><th>Actions</th></tr></thead><tbody>';
    foreach ($items as $item) {
      $leave_id = (int)$item->ID;
      $target = (int)get_post_meta($leave_id, self::META_TARGET_USER, true);
      $type = self::leave_type((string)get_post_meta($leave_id, self::META_TYPE, true));
      $status = (string)get_post_meta($leave_id, self::META_STATUS, true);
      $start = (string)get_post_meta($leave_id, self::META_START_DATE, true);
      $return = (string)get_post_meta($leave_id, self::META_RETURN_DATE, true);
      echo '<tr>';
      echo '<td><span class="eum-directory__person">' . (class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html($target, 32, 'eum-directory__avatar') : '') . '<span>' . esc_html($this->user_label($target)) . '</span></span></td>';
      echo '<td>' . esc_html((string)($type['label'] ?? get_post_meta($leave_id, self::META_TYPE, true))) . '</td>';
      echo '<td>' . esc_html($start . ' to ' . ($return !== '' ? $return : 'TBD')) . '</td>';
      echo '<td>' . esc_html(number_format((float)get_post_meta($leave_id, self::META_HOURS, true), 2)) . '</td>';
      echo '<td>' . esc_html(ucfirst($status ?: 'pending')) . '</td>';
      echo '<td>' . esc_html((string)get_post_meta($leave_id, self::META_REASON, true)) . '</td>';
      echo '<td>' . $this->leave_action_buttons($leave_id) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    return ob_get_clean();
  }

  private function leave_action_buttons(int $leave_id): string {
    if (!is_user_logged_in()) return '';
    $actor_user_id = get_current_user_id();
    $status = (string)get_post_meta($leave_id, self::META_STATUS, true);
    $requestor = (int)get_post_meta($leave_id, self::META_ACTOR_USER, true);
    $out = '';
    foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $action => $label) {
      if ($status !== 'pending' || !$this->can_approve_leave($actor_user_id, $leave_id)) continue;
      $out .= $this->leave_action_form($leave_id, $action, $label);
    }
    if (in_array($status, ['pending', 'approved'], true) && ($actor_user_id === $requestor || EUM_Access::is_it_or_hr($actor_user_id))) {
      $out .= $this->leave_action_form($leave_id, 'cancel', 'Cancel');
    }
    return $out !== '' ? $out : '-';
  }

  private function leave_action_form(int $leave_id, string $action, string $label): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;margin-right:4px;">';
    echo '<input type="hidden" name="action" value="eum_leave_action"><input type="hidden" name="leave_id" value="' . (int)$leave_id . '"><input type="hidden" name="leave_action" value="' . esc_attr($action) . '"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_leave_action_' . $leave_id, 'eum_leave_action_nonce');
    echo '<button class="button button-small" type="submit">' . esc_html($label) . '</button></form>';
    return ob_get_clean();
  }

  public function shortcode_availability_portal($atts = []): string {
    if (!is_user_logged_in()) return '<div class="eum-availability-portal"><p>You must be logged in to manage availability.</p></div>';
    $actor_user_id = get_current_user_id();
    $selected_user_id = isset($_GET['target_user_id']) ? max(0, (int)$_GET['target_user_id']) : $actor_user_id;
    if (!$this->can_request_availability_for_user($actor_user_id, $selected_user_id)) $selected_user_id = $actor_user_id;
    $source_tz = self::user_timezone($selected_user_id);
    $actor_tz = self::user_timezone($actor_user_id);
    $can_transpose = $actor_user_id !== $selected_user_id && $this->can_request_availability_for_user($actor_user_id, $selected_user_id);
    $view_tz = $source_tz;
    if ($can_transpose) {
      $requested_view_tz = isset($_GET['availability_view_timezone']) ? sanitize_text_field((string)$_GET['availability_view_timezone']) : $actor_tz->getName();
      $view_tz = self::timezone_from_string($requested_view_tz, $actor_tz);
    }
    $requested_week = isset($_GET['availability_week_start']) ? sanitize_text_field((string)$_GET['availability_week_start']) : (new DateTimeImmutable('now', $source_tz))->format('Y-m-d');
    $week_start = self::week_start_for_date($requested_week);
    $message = isset($_GET['eum_availability_msg']) ? sanitize_text_field((string)$_GET['eum_availability_msg']) : '';
    $type = isset($_GET['eum_availability_type']) ? sanitize_key((string)$_GET['eum_availability_type']) : 'success';
    $current_segments = self::availability_for_week($selected_user_id, $week_start);
    $summary_segments = self::availability_segments_for_display($current_segments, $week_start, $source_tz, $view_tz);
    $timezone_note = $can_transpose && $view_tz->getName() !== $source_tz->getName()
      ? 'Stored in ' . $source_tz->getName() . ', shown in ' . $view_tz->getName() . '.'
      : 'Times are stored in ' . $source_tz->getName() . '.';

    ob_start();
    echo '<div class="eum-availability-portal">';
    if ($message !== '') {
      echo '<div class="eum-availability-notice ' . esc_attr($type === 'error' ? 'is-error' : 'is-success') . '">' . esc_html($message) . '</div>';
    }

    echo '<section class="eum-availability-summary">';
    echo '<div><p class="eum-availability-eyebrow">Current week</p><h2>' . esc_html($week_start) . '</h2><p class="eum-availability-timezone-note">' . esc_html($timezone_note) . '</p></div>';
    echo '<div class="eum-availability-summary__body">' . esc_html(self::availability_segments_label($summary_segments)) . '</div>';
    if ($can_transpose) {
      echo '<form class="eum-availability-timezone-form" method="get">';
      foreach ($_GET as $key => $value) {
        $key = sanitize_key((string)$key);
        if ($key === '' || in_array($key, ['availability_view_timezone', 'eum_availability_msg', 'eum_availability_type'], true)) continue;
        if (is_array($value)) continue;
        echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(sanitize_text_field((string)$value)) . '">';
      }
      echo '<label><span>View Timezone</span>' . self::timezone_select('availability_view_timezone', $view_tz->getName()) . '</label>';
      echo '<button type="submit" class="button">Transpose</button>';
      echo '</form>';
    }
    echo '</section>';

    echo '<section class="eum-availability-panel">';
    echo '<div class="eum-availability-panel__header"><div><p class="eum-availability-eyebrow">Update</p><h3>Set Availability</h3></div><p>Choose each available block in the user timezone: <strong>' . esc_html($source_tz->getName()) . '</strong>.</p></div>';
    echo '<form class="eum-availability-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_availability_submit"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_availability_submit', 'eum_availability_nonce');
    echo '<div class="eum-availability-controls">';
    echo '<label class="eum-availability-field"><span>Person</span>' . $this->render_availability_target_select($actor_user_id, $selected_user_id) . '</label>';
    echo '<fieldset class="eum-availability-scope"><legend>Update Type</legend>';
    echo '<label><input type="radio" name="availability_scope" value="week" checked> <span>Specific week</span></label>';
    echo '<label><input type="radio" name="availability_scope" value="default"> <span>Default availability</span></label>';
    echo '</fieldset>';
    echo '<label class="eum-availability-field" data-eum-availability-week><span>Week Start</span><input type="date" name="week_start" value="' . esc_attr($week_start) . '" data-eum-availability-week-start><small>Changing this loads that week\'s current availability.</small></label>';
    echo '<label class="eum-availability-field" data-eum-availability-default><span>Default As Of</span><input type="date" name="as_of_date" value="' . esc_attr((new DateTimeImmutable('now', $source_tz))->format('Y-m-d')) . '"><small>Used for default availability rules.</small></label>';
    echo '</div>';

    echo '<div class="eum-availability-days">';
    $current_by_day = [];
    foreach ($current_segments as $segment) {
      if (!is_array($segment)) continue;
      $day = (int)($segment['day'] ?? 0);
      if ($day > 0) {
        if (!isset($current_by_day[$day])) $current_by_day[$day] = [];
        $current_by_day[$day][] = $segment;
      }
    }
    foreach (self::availability_day_options() as $day => $label) {
      $index = (int)$day;
      $day_segments = isset($current_by_day[$index]) && is_array($current_by_day[$index]) ? $current_by_day[$index] : [];
      if (empty($day_segments)) $day_segments = [['day' => $index, 'start' => '09:00', 'end' => '17:00']];
      $is_enabled = isset($current_by_day[$index]) && !empty($current_by_day[$index]);
      echo '<div class="eum-availability-day" data-eum-availability-day data-eum-availability-day-value="' . $index . '">';
      echo '<label class="eum-availability-day__toggle"><input type="checkbox" data-eum-availability-day-toggle value="1" ' . checked($is_enabled, true, false) . '> <span>' . esc_html($label) . '</span></label>';
      echo '<div class="eum-availability-day__blocks" data-eum-availability-blocks>';
      $block_number = 0;
      foreach ($day_segments as $segment) {
        $block_number++;
        $row_key = $index . '_' . $block_number;
        $start_value = (string)($segment['start'] ?? '09:00');
        $end_value = (string)($segment['end'] ?? '17:00');
        echo self::availability_block_fields($row_key, $index, $start_value, $end_value, $is_enabled, $block_number > 1);
      }
      echo '</div>';
      echo '<div class="eum-availability-day__tools">';
      echo '<button type="button" class="button button-small eum-availability-add-block" data-eum-availability-add-block>Add Block</button>';
      echo '<label><span>Copy to</span><select data-eum-availability-copy-target>';
      foreach (self::availability_day_options() as $copy_day => $copy_label) {
        if ((int)$copy_day === $index) continue;
        echo '<option value="' . (int)$copy_day . '">' . esc_html($copy_label) . '</option>';
      }
      echo '</select></label>';
      echo '<button type="button" class="button button-small" data-eum-availability-copy-day>Copy</button>';
      echo '</div>';
      echo '</div>';
    }
    echo '</div>';
    echo '<template id="eum-availability-block-template">' . self::availability_block_fields('__KEY__', '__DAY__', '09:00', '17:00', true, true) . '</template>';
    echo '<label class="eum-availability-field eum-availability-field--notes"><span>Notes</span><textarea name="availability_notes" rows="3"></textarea></label>';
    echo '<div class="eum-availability-actions"><button class="button button-primary" type="submit">Save Availability</button></div>';
    echo '</form></section>';

    echo '<section class="eum-availability-panel">';
    echo '<div class="eum-availability-panel__header"><div><p class="eum-availability-eyebrow">History</p><h3>Requests</h3></div></div>';
    echo $this->render_availability_requests_table($this->visible_availability_requests_for_actor($actor_user_id, 50), false);
    echo '</section></div>';
    return ob_get_clean();
  }

  public function handle_availability_submit(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    if (!isset($_POST['eum_availability_nonce']) || !wp_verify_nonce((string)$_POST['eum_availability_nonce'], 'eum_availability_submit')) wp_die('Bad nonce.');

    $actor_user_id = get_current_user_id();
    $target_user_id = max(0, (int)($_POST['target_user_id'] ?? $actor_user_id));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if (!$this->can_request_availability_for_user($actor_user_id, $target_user_id)) {
      $this->redirect_availability($return_url, 'You cannot update availability for that user.', 'error');
    }

    $scope = sanitize_key((string)($_POST['availability_scope'] ?? 'week'));
    if (!in_array($scope, ['default', 'week'], true)) $scope = 'week';
    $target_tz = self::user_timezone($target_user_id);
    $target_today = (new DateTimeImmutable('now', $target_tz))->format('Y-m-d');
    $week_start = self::week_start_for_date((string)($_POST['week_start'] ?? $target_today));
    $as_of_date = self::sanitize_date((string)($_POST['as_of_date'] ?? $target_today));
    if ($as_of_date === '') $as_of_date = $target_today;
    $segments = $this->availability_segments_from_request((array)($_POST['availability_segments'] ?? []));
    if (empty($segments)) $this->redirect_availability($return_url, 'Add at least one availability segment.', 'error');

    $notes = sanitize_textarea_field((string)($_POST['availability_notes'] ?? ''));
    $approver_ids = $this->approver_ids_for_availability($target_user_id, [$actor_user_id, $target_user_id]);
    $requires_approval = $actor_user_id === $target_user_id && !EUM_Access::is_it_or_hr($actor_user_id) && !empty($approver_ids);
    $status = $requires_approval ? 'pending' : 'approved';
    $post_id = wp_insert_post([
      'post_type' => self::CPT_AVAILABILITY,
      'post_status' => 'publish',
      'post_author' => $actor_user_id,
      'post_title' => 'Availability: ' . $this->user_label($target_user_id) . ' - ' . ($scope === 'default' ? 'Default as of ' . $as_of_date : 'Week of ' . $week_start),
    ], true);
    if (is_wp_error($post_id) || (int)$post_id <= 0) $this->redirect_availability($return_url, 'Availability request could not be saved.', 'error');

    $request_id = (int)$post_id;
    update_post_meta($request_id, self::AV_META_TARGET_USER, $target_user_id);
    update_post_meta($request_id, self::AV_META_ACTOR_USER, $actor_user_id);
    update_post_meta($request_id, self::AV_META_STATUS, $status);
    update_post_meta($request_id, self::AV_META_SCOPE, $scope);
    update_post_meta($request_id, self::AV_META_AS_OF_DATE, $as_of_date);
    update_post_meta($request_id, self::AV_META_WEEK_START, $week_start);
    update_post_meta($request_id, self::AV_META_SEGMENTS, $segments);
    update_post_meta($request_id, self::AV_META_NOTES, $notes);
    update_post_meta($request_id, self::AV_META_APPROVER_IDS, $approver_ids);
    if ($status === 'approved') {
      update_post_meta($request_id, self::AV_META_APPROVED_BY, $actor_user_id);
      update_post_meta($request_id, self::AV_META_APPROVED_AT, current_time('mysql'));
      $this->save_approved_availability($request_id);
    }
    do_action('eum_availability_request_created', $request_id, $target_user_id, $actor_user_id, $status);
    $this->redirect_availability($return_url, $status === 'pending' ? 'Availability request sent to manager.' : 'Availability saved.');
  }

  public function handle_availability_action(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $request_id = max(0, (int)($_POST['availability_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if ($request_id <= 0 || !isset($_POST['eum_availability_action_nonce']) || !wp_verify_nonce((string)$_POST['eum_availability_action_nonce'], 'eum_availability_action_' . $request_id)) wp_die('Bad nonce.');

    $action = sanitize_key((string)($_POST['availability_action'] ?? ''));
    $status = (string)get_post_meta($request_id, self::AV_META_STATUS, true);
    $requestor_id = (int)get_post_meta($request_id, self::AV_META_ACTOR_USER, true);
    if ($action === 'cancel') {
      if (!in_array($status, ['pending', 'approved'], true) || ($actor_user_id !== $requestor_id && !EUM_Access::is_it_or_hr($actor_user_id))) $this->redirect_availability($return_url, 'You cannot cancel this request.', 'error');
      update_post_meta($request_id, self::AV_META_STATUS, 'cancelled');
      do_action('eum_availability_status_changed', $request_id, (int)get_post_meta($request_id, self::AV_META_TARGET_USER, true), 'cancelled', $actor_user_id);
      $this->redirect_availability($return_url, 'Availability request cancelled.');
    }
    if (!in_array($action, ['approve', 'reject'], true) || $status !== 'pending' || !$this->can_approve_availability($actor_user_id, $request_id)) {
      $this->redirect_availability($return_url, 'You cannot perform that availability action.', 'error');
    }
    update_post_meta($request_id, self::AV_META_STATUS, $action === 'approve' ? 'approved' : 'rejected');
    update_post_meta($request_id, self::AV_META_APPROVED_BY, $actor_user_id);
    update_post_meta($request_id, self::AV_META_APPROVED_AT, current_time('mysql'));
    if ($action === 'approve') $this->save_approved_availability($request_id);
    do_action('eum_availability_status_changed', $request_id, (int)get_post_meta($request_id, self::AV_META_TARGET_USER, true), $action === 'approve' ? 'approved' : 'rejected', $actor_user_id);
    $this->redirect_availability($return_url, 'Availability request ' . ($action === 'approve' ? 'approved' : 'rejected') . '.');
  }

  private function redirect_availability(string $url, string $message, string $type = 'success'): void {
    wp_safe_redirect(add_query_arg(['eum_availability_msg' => $message, 'eum_availability_type' => $type], $url ?: home_url('/')));
    exit;
  }

  private function availability_requests(array $args = []): array {
    $status = sanitize_key((string)($args['status'] ?? ''));
    $meta_query = [];
    if ($status !== '') $meta_query[] = ['key' => self::AV_META_STATUS, 'value' => $status];
    return get_posts([
      'post_type' => self::CPT_AVAILABILITY,
      'post_status' => 'publish',
      'numberposts' => max(1, (int)($args['limit'] ?? 50)),
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => $meta_query,
    ]);
  }

  private function visible_availability_requests_for_actor(int $actor_user_id, int $limit = 50): array {
    $items = $this->availability_requests(['limit' => $limit * 4]);
    if (EUM_Access::is_it_or_hr($actor_user_id)) return array_slice($items, 0, $limit);
    $out = [];
    foreach ($items as $item) {
      $request_id = (int)$item->ID;
      $target = (int)get_post_meta($request_id, self::AV_META_TARGET_USER, true);
      $requestor = (int)get_post_meta($request_id, self::AV_META_ACTOR_USER, true);
      if ($actor_user_id === $target || $actor_user_id === $requestor || $this->can_approve_availability($actor_user_id, $request_id) || (class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $target))) {
        $out[] = $item;
      }
      if (count($out) >= $limit) break;
    }
    return $out;
  }

  private function render_availability_requests_table(array $items, bool $admin): string {
    if (empty($items)) return '<p>No availability requests found.</p>';
    ob_start();
    echo '<table class="widefat striped"><thead><tr><th>User</th><th>Type</th><th>Effective</th><th>Segments</th><th>Status</th><th>Notes</th><th>Actions</th></tr></thead><tbody>';
    foreach ($items as $item) {
      $request_id = (int)$item->ID;
      $target = (int)get_post_meta($request_id, self::AV_META_TARGET_USER, true);
      $scope = (string)get_post_meta($request_id, self::AV_META_SCOPE, true);
      $status = (string)get_post_meta($request_id, self::AV_META_STATUS, true);
      $effective = $scope === 'default'
        ? 'As of ' . (string)get_post_meta($request_id, self::AV_META_AS_OF_DATE, true)
        : 'Week of ' . (string)get_post_meta($request_id, self::AV_META_WEEK_START, true);
      echo '<tr>';
      echo '<td><span class="eum-directory__person">' . (class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html($target, 32, 'eum-directory__avatar') : '') . '<span>' . esc_html($this->user_label($target)) . '</span></span></td>';
      echo '<td>' . esc_html($scope === 'default' ? 'Default' : 'Specific Week') . '</td>';
      echo '<td>' . esc_html($effective) . '</td>';
      echo '<td>' . esc_html(self::availability_segments_label((array)get_post_meta($request_id, self::AV_META_SEGMENTS, true))) . '</td>';
      echo '<td>' . esc_html(ucfirst($status ?: 'pending')) . '</td>';
      echo '<td>' . esc_html((string)get_post_meta($request_id, self::AV_META_NOTES, true)) . '</td>';
      echo '<td>' . $this->availability_action_buttons($request_id) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    return ob_get_clean();
  }

  private function availability_action_buttons(int $request_id): string {
    if (!is_user_logged_in()) return '';
    $actor_user_id = get_current_user_id();
    $status = (string)get_post_meta($request_id, self::AV_META_STATUS, true);
    $requestor = (int)get_post_meta($request_id, self::AV_META_ACTOR_USER, true);
    $out = '';
    foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $action => $label) {
      if ($status !== 'pending' || !$this->can_approve_availability($actor_user_id, $request_id)) continue;
      $out .= $this->availability_action_form($request_id, $action, $label);
    }
    if (in_array($status, ['pending', 'approved'], true) && ($actor_user_id === $requestor || EUM_Access::is_it_or_hr($actor_user_id))) {
      $out .= $this->availability_action_form($request_id, 'cancel', 'Cancel');
    }
    return $out !== '' ? $out : '-';
  }

  private function availability_action_form(int $request_id, string $action, string $label): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;margin-right:4px;">';
    echo '<input type="hidden" name="action" value="eum_availability_action"><input type="hidden" name="availability_id" value="' . (int)$request_id . '"><input type="hidden" name="availability_action" value="' . esc_attr($action) . '"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_availability_action_' . $request_id, 'eum_availability_action_nonce');
    echo '<button class="button button-small" type="submit">' . esc_html($label) . '</button></form>';
    return ob_get_clean();
  }

  private function render_availability_target_select(int $actor_user_id, int $selected_user_id): string {
    if (!EUM_Access::is_it_or_hr($actor_user_id) && !$this->actor_manages_anyone($actor_user_id)) {
      return '<input type="hidden" name="target_user_id" value="' . (int)$actor_user_id . '">' . esc_html($this->user_label($actor_user_id));
    }
    $html = '<select name="target_user_id">';
    foreach (get_users(['orderby' => 'display_name', 'order' => 'ASC', 'number' => 0]) as $user) {
      if (!$this->can_request_availability_for_user($actor_user_id, (int)$user->ID)) continue;
      $html .= '<option value="' . (int)$user->ID . '" ' . selected($selected_user_id, (int)$user->ID, false) . '>' . esc_html($this->user_label((int)$user->ID)) . '</option>';
    }
    $html .= '</select>';
    return $html;
  }

  private static function availability_block_fields($row_key, $day, string $start, string $end, bool $enabled, bool $removable): string {
    $row_key = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$row_key);
    $day_value = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$day);
    if ($row_key === '') $row_key = '1_1';
    if ($day_value === '') $day_value = '1';
    ob_start();
    echo '<div class="eum-availability-day__block" data-eum-availability-block>';
    echo '<input type="hidden" name="availability_segments[' . esc_attr($row_key) . '][enabled]" value="1"' . ($enabled ? '' : ' disabled') . '>';
    echo '<input type="hidden" name="availability_segments[' . esc_attr($row_key) . '][day]" value="' . esc_attr($day_value) . '"' . ($enabled ? '' : ' disabled') . '>';
    echo '<label><span>From</span><input type="time" name="availability_segments[' . esc_attr($row_key) . '][start]" value="' . esc_attr($start) . '"' . ($enabled ? '' : ' disabled') . '></label>';
    echo '<label><span>Until</span><input type="time" name="availability_segments[' . esc_attr($row_key) . '][end]" value="' . esc_attr($end) . '"' . ($enabled ? '' : ' disabled') . '></label>';
    echo '<button type="button" class="button-link-delete eum-availability-remove-block" data-eum-availability-remove-block' . ($removable ? '' : ' hidden') . '>Remove</button>';
    echo '</div>';
    return ob_get_clean();
  }

  private function availability_segments_from_request(array $rows): array {
    $segments = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      if (array_key_exists('enabled', $row) && empty($row['enabled'])) continue;
      $segments[] = [
        'day' => (int)($row['day'] ?? 0),
        'start' => (string)($row['start'] ?? ''),
        'end' => (string)($row['end'] ?? ''),
      ];
    }
    return self::normalize_availability_segments($segments);
  }

  private static function availability_segments_for_display(array $segments, string $week_start, DateTimeZone $source_tz, DateTimeZone $view_tz): array {
    $segments = self::normalize_availability_segments($segments);
    if (empty($segments) || $source_tz->getName() === $view_tz->getName()) return $segments;
    $out = [];
    $week_start = self::week_start_for_date($week_start);
    foreach ($segments as $segment) {
      $day = (int)($segment['day'] ?? 0);
      $start_time = self::sanitize_time((string)($segment['start'] ?? ''));
      $end_time = self::sanitize_time((string)($segment['end'] ?? ''));
      if ($day < 1 || $day > 7 || $start_time === '' || $end_time === '') continue;
      try {
        $date = (new DateTimeImmutable($week_start . ' 00:00:00', $source_tz))->modify('+' . ($day - 1) . ' days')->format('Y-m-d');
        $start = (new DateTimeImmutable($date . ' ' . $start_time, $source_tz))->setTimezone($view_tz);
        $end = (new DateTimeImmutable($date . ' ' . $end_time, $source_tz))->setTimezone($view_tz);
        $out[] = [
          'day' => (int)$start->format('N'),
          'start' => $start->format('H:i'),
          'end' => $end->format('H:i'),
          'end_day' => (int)$end->format('N'),
        ];
      } catch (Throwable $e) {
      }
    }
    return $out;
  }

  private static function normalize_availability_segments(array $segments): array {
    $out = [];
    foreach ($segments as $segment) {
      if (!is_array($segment)) continue;
      $day = max(1, min(7, (int)($segment['day'] ?? 0)));
      $start = self::sanitize_time((string)($segment['start'] ?? ''));
      $end = self::sanitize_time((string)($segment['end'] ?? ''));
      if ($start === '' || $end === '' || $end <= $start) continue;
      $out[] = ['day' => $day, 'start' => $start, 'end' => $end];
    }
    usort($out, static function($a, $b) {
      $day_cmp = ((int)$a['day']) <=> ((int)$b['day']);
      return $day_cmp !== 0 ? $day_cmp : strcmp((string)$a['start'], (string)$b['start']);
    });
    return $out;
  }

  private function can_request_availability_for_user(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if ($actor_user_id === $target_user_id) return true;
    return class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $target_user_id);
  }

  private function can_approve_availability(int $actor_user_id, int $request_id): bool {
    if ($actor_user_id <= 0 || $request_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    $target_user_id = (int)get_post_meta($request_id, self::AV_META_TARGET_USER, true);
    $requestor_id = (int)get_post_meta($request_id, self::AV_META_ACTOR_USER, true);
    if ($target_user_id <= 0 || $actor_user_id === $target_user_id || $actor_user_id === $requestor_id) return false;
    $approvers = array_values(array_filter(array_map('intval', (array)get_post_meta($request_id, self::AV_META_APPROVER_IDS, true))));
    if (in_array($actor_user_id, $approvers, true)) return true;
    return class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $target_user_id);
  }

  private function approver_ids_for_availability(int $target_user_id, array $exclude = []): array {
    $exclude = array_values(array_filter(array_map('intval', $exclude), fn($uid) => $uid > 0));
    $ids = class_exists('EUM_Manager_IDs') && method_exists('EUM_Manager_IDs', 'manager_user_ids_for_subject_user')
      ? EUM_Manager_IDs::manager_user_ids_for_subject_user($target_user_id)
      : [];
    $ids = array_values(array_filter(array_map('intval', (array)$ids), fn($uid) => $uid > 0 && !in_array($uid, $exclude, true)));
    return array_values(array_unique($ids));
  }

  private function save_approved_availability(int $request_id): void {
    $target_user_id = (int)get_post_meta($request_id, self::AV_META_TARGET_USER, true);
    if ($target_user_id <= 0) return;
    $scope = (string)get_post_meta($request_id, self::AV_META_SCOPE, true);
    $segments = self::normalize_availability_segments((array)get_post_meta($request_id, self::AV_META_SEGMENTS, true));
    if (empty($segments)) return;

    if ($scope === 'default') {
      $as_of = self::sanitize_date((string)get_post_meta($request_id, self::AV_META_AS_OF_DATE, true));
      $target_today = (new DateTimeImmutable('now', self::user_timezone($target_user_id)))->format('Y-m-d');
      if ($as_of === '') $as_of = $target_today;
      $rules = (array)get_user_meta($target_user_id, self::USER_META_DEFAULT_AVAILABILITY_RULES, true);
      $rules[] = [
        'as_of' => $as_of,
        'segments' => $segments,
        'request_id' => $request_id,
        'updated_at' => current_time('mysql'),
      ];
      usort($rules, static function($a, $b) {
        $date_cmp = strcmp((string)($a['as_of'] ?? ''), (string)($b['as_of'] ?? ''));
        return $date_cmp !== 0 ? $date_cmp : ((int)($a['request_id'] ?? 0) <=> (int)($b['request_id'] ?? 0));
      });
      update_user_meta($target_user_id, self::USER_META_DEFAULT_AVAILABILITY_RULES, $rules);
      if ($as_of <= $target_today) update_user_meta($target_user_id, self::USER_META_DEFAULT_AVAILABILITY, $segments);
      return;
    }

    $week_start = self::week_start_for_date((string)get_post_meta($request_id, self::AV_META_WEEK_START, true));
    $weekly = (array)get_user_meta($target_user_id, self::USER_META_WEEKLY_AVAILABILITY, true);
    $weekly[$week_start] = [
      'segments' => $segments,
      'request_id' => $request_id,
      'updated_at' => current_time('mysql'),
    ];
    update_user_meta($target_user_id, self::USER_META_WEEKLY_AVAILABILITY, $weekly);
  }

  public static function availability_for_week(int $user_id, string $week_start): array {
    $week_start = self::week_start_for_date($week_start);
    return self::availability_for_date($user_id, $week_start);
  }

  public static function availability_for_date(int $user_id, string $date): array {
    if ($user_id <= 0) return [];
    $date = self::sanitize_date($date);
    if ($date === '') return [];
    $week_start = self::week_start_for_date($date);
    $weekly = (array)get_user_meta($user_id, self::USER_META_WEEKLY_AVAILABILITY, true);
    if (isset($weekly[$week_start])) {
      $entry = is_array($weekly[$week_start]) ? $weekly[$week_start] : [];
      $segments = isset($entry['segments']) ? (array)$entry['segments'] : $entry;
      return self::normalize_availability_segments($segments);
    }

    $rules = (array)get_user_meta($user_id, self::USER_META_DEFAULT_AVAILABILITY_RULES, true);
    $legacy = (array)get_user_meta($user_id, self::USER_META_DEFAULT_AVAILABILITY, true);
    if (!empty($legacy)) {
      $rules[] = [
        'as_of' => '0000-01-01',
        'segments' => $legacy,
        'request_id' => 0,
        'updated_at' => '',
      ];
    }
    usort($rules, static function($a, $b) {
      $date_cmp = strcmp((string)($a['as_of'] ?? ''), (string)($b['as_of'] ?? ''));
      return $date_cmp !== 0 ? $date_cmp : ((int)($a['request_id'] ?? 0) <=> (int)($b['request_id'] ?? 0));
    });
    $active = [];
    foreach ($rules as $rule) {
      if (!is_array($rule)) continue;
      $as_of = self::sanitize_date((string)($rule['as_of'] ?? ''));
      if ($as_of === '') $as_of = '0000-01-01';
      if ($as_of <= $date) $active = self::normalize_availability_segments((array)($rule['segments'] ?? []));
    }
    return $active;
  }

  public static function user_is_available_for_range(int $user_id, int $start_utc, int $end_utc): bool {
    if ($user_id <= 0 || $start_utc <= 0 || $end_utc <= $start_utc) return true;
    $tz = self::user_timezone($user_id);
    $start = (new DateTimeImmutable('@' . $start_utc))->setTimezone($tz);
    $end = (new DateTimeImmutable('@' . $end_utc))->setTimezone($tz);
    if ($start->format('Y-m-d') !== $end->format('Y-m-d')) return false;
    $segments = self::availability_for_date($user_id, $start->format('Y-m-d'));
    if (empty($segments)) return true;
    $day = (int)$start->format('N');
    $start_time = $start->format('H:i');
    $end_time = $end->format('H:i');
    foreach ($segments as $segment) {
      if ((int)($segment['day'] ?? 0) !== $day) continue;
      if ($start_time >= (string)$segment['start'] && $end_time <= (string)$segment['end']) return true;
    }
    return false;
  }

  public static function availability_warning_for_shift(int $shift_id, int $user_id = 0): string {
    if ($shift_id <= 0 || !class_exists('EUM_Scheduling')) return '';
    if ($user_id <= 0) $user_id = (int)get_post_meta($shift_id, EUM_Scheduling::META_USER_ID, true);
    if ($user_id <= 0) return '';
    $segments = (array)get_post_meta($shift_id, EUM_Scheduling::META_SEGMENTS, true);
    foreach ($segments as $segment) {
      if (!is_array($segment)) continue;
      if ((string)($segment['kind'] ?? 'work') !== 'work') continue;
      $start = (int)($segment['start_utc'] ?? 0);
      $end = (int)($segment['end_utc'] ?? 0);
      if ($start <= 0 || $end <= $start) continue;
      if (!self::user_is_available_for_range($user_id, $start, $end)) {
        return 'Scheduled outside availability: ' . self::local_range_label($start, $end, $user_id);
      }
    }
    return '';
  }

  public static function week_start_for_date(string $date): string {
    $date = self::sanitize_date($date);
    if ($date === '') $date = current_time('Y-m-d');
    $dt = new DateTimeImmutable($date . ' 00:00:00', wp_timezone());
    $dow = (int)$dt->format('N');
    return $dt->modify('-' . max(0, $dow - 1) . ' days')->format('Y-m-d');
  }

  private static function availability_day_options(): array {
    return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
  }

  private static function availability_segments_label(array $segments): string {
    $has_display_segments = false;
    foreach ($segments as $segment) {
      if (is_array($segment) && array_key_exists('end_day', $segment)) {
        $has_display_segments = true;
        break;
      }
    }
    if ($has_display_segments) {
      $segments = array_values(array_filter($segments, 'is_array'));
      usort($segments, static function($a, $b) {
        $day_cmp = ((int)($a['day'] ?? 0)) <=> ((int)($b['day'] ?? 0));
        return $day_cmp !== 0 ? $day_cmp : strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? ''));
      });
    } else {
      $segments = self::normalize_availability_segments($segments);
    }
    if (empty($segments)) return 'No availability set';
    $days = self::availability_day_options();
    $labels = [];
    foreach ($segments as $segment) {
      $day = (int)($segment['day'] ?? 0);
      $end_day = (int)($segment['end_day'] ?? $day);
      $prefix = ($days[$day] ?? 'Day ' . $day) . ' ' . (string)($segment['start'] ?? '');
      $suffix = ((int)$end_day !== (int)$day ? ' - ' . ($days[$end_day] ?? 'Day ' . $end_day) . ' ' : '-') . (string)($segment['end'] ?? '');
      $labels[] = $prefix . $suffix;
    }
    return implode('; ', $labels);
  }

  private static function sanitize_time(string $value): string {
    $value = trim($value);
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m)) return '';
    return $m[1] . ':' . $m[2];
  }

  private static function local_range_label(int $start_utc, int $end_utc, int $user_id = 0): string {
    $tz = self::user_timezone($user_id);
    $start = (new DateTimeImmutable('@' . $start_utc))->setTimezone($tz);
    $end = (new DateTimeImmutable('@' . $end_utc))->setTimezone($tz);
    return $start->format('D g:i A') . '-' . $end->format('g:i A');
  }

  private static function user_timezone(int $user_id): DateTimeZone {
    $tz_string = $user_id > 0 ? (string)get_user_meta($user_id, 'timezone', true) : '';
    if ($tz_string === '') $tz_string = (string)get_option('timezone_string', '');
    if ($tz_string !== '') {
      try {
        return new DateTimeZone($tz_string);
      } catch (Throwable $e) {
      }
    }
    return wp_timezone();
  }

  private static function timezone_from_string(string $tz_string, DateTimeZone $fallback): DateTimeZone {
    $tz_string = trim($tz_string);
    if ($tz_string !== '') {
      try {
        return new DateTimeZone($tz_string);
      } catch (Throwable $e) {
      }
    }
    return $fallback;
  }

  private static function timezone_select(string $name, string $selected): string {
    $zones = DateTimeZone::listIdentifiers();
    $html = '<select name="' . esc_attr($name) . '">';
    foreach ($zones as $zone) {
      $html .= '<option value="' . esc_attr($zone) . '" ' . selected($selected, $zone, false) . '>' . esc_html($zone) . '</option>';
    }
    $html .= '</select>';
    return $html;
  }

  public static function active_leave_user_ids(string $date): array {
    $ids = [];
    foreach (self::approved_leave_requests_for_date($date) as $item) {
      $ids[] = (int)get_post_meta((int)$item->ID, self::META_TARGET_USER, true);
    }
    return array_values(array_unique(array_filter($ids)));
  }

  public static function approved_leave_requests_for_date(string $date, int $user_id = 0): array {
    $date = self::sanitize_date($date);
    if ($date === '') return [];
    $meta_query = [
      ['key' => self::META_STATUS, 'value' => 'approved'],
      ['key' => self::META_START_DATE, 'value' => $date, 'compare' => '<=', 'type' => 'DATE'],
      ['key' => self::META_TYPE, 'value' => 'vacation', 'compare' => '!='],
    ];
    if ($user_id > 0) $meta_query[] = ['key' => self::META_TARGET_USER, 'value' => $user_id];
    $items = get_posts([
      'post_type' => self::CPT_LEAVE,
      'post_status' => 'publish',
      'numberposts' => 200,
      'meta_query' => $meta_query,
    ]);
    $out = [];
    foreach ($items as $item) {
      $return = (string)get_post_meta((int)$item->ID, self::META_RETURN_DATE, true);
      if ($return === '' || $date < $return) $out[] = $item;
    }
    return $out;
  }

  public static function out_of_office_label(int $user_id, string $date = ''): string {
    $date = $date !== '' ? self::sanitize_date($date) : current_time('Y-m-d');
    if ($date === '') return '';
    $items = self::approved_leave_requests_for_date($date, $user_id);
    if (empty($items)) return '';
    $item = $items[0];
    $return = (string)get_post_meta((int)$item->ID, self::META_RETURN_DATE, true);
    return 'OUT OF OFFICE RETURN ON ' . ($return !== '' ? $return : 'TBD');
  }

  public static function out_of_office_badge_html(int $user_id, string $date = '', string $class = ''): string {
    $label = self::out_of_office_label($user_id, $date);
    if ($label === '') return '';
    $classes = trim('eum-ooo-badge ' . $class);
    return '<span class="' . esc_attr($classes) . '" style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#fff4e5;color:#8a4b00;border:1px solid #f1c27d;font-size:12px;font-weight:700;">' . esc_html($label) . '</span>';
  }

  public static function schedule_absence_chip_html(int $user_id, string $date): string {
    $label = self::out_of_office_label($user_id, $date);
    if ($label === '') return '';
    return '<div class="eum-schedule-absence" style="margin:4px 0 6px;padding:6px 8px;border-radius:8px;background:#fff4e5;color:#8a4b00;border:1px solid #f1c27d;font-size:11px;font-weight:700;">' . esc_html($label) . '</div>';
  }

  public static function validate_scheduled_vacation_segments(int $target_user_id, array $segments, int $exclude_shift_id = 0) {
    $requested_hours = 0.0;
    foreach ((array)$segments as $segment) {
      if (!is_array($segment)) continue;
      $kind = class_exists('EUM_Scheduling') ? EUM_Scheduling::normalize_segment_kind_value((string)($segment['kind'] ?? 'work')) : sanitize_key((string)($segment['kind'] ?? 'work'));
      if ($kind !== 'vacation') continue;
      $start_utc = (int)($segment['start_utc'] ?? 0);
      $end_utc = (int)($segment['end_utc'] ?? 0);
      if ($start_utc > 0 && $end_utc > $start_utc) $requested_hours += ($end_utc - $start_utc) / HOUR_IN_SECONDS;
    }
    if ($requested_hours <= 0) return true;
    if ($target_user_id <= 0) return new WP_Error('vacation_requires_user', 'Vacation entries must be assigned to a user.');

    $settings = self::get_settings()['vacation_accrual'];
    if (empty($settings['enabled']) || !empty($settings['allow_negative'])) return true;

    $exclude_shift_ids = [];
    if ($exclude_shift_id > 0) {
      $exclude_shift_ids[] = $exclude_shift_id;
      if (class_exists('EUM_Scheduling')) {
        $parent_id = (int)get_post_meta($exclude_shift_id, EUM_Scheduling::META_REVISION_PARENT, true);
        if ($parent_id > 0) $exclude_shift_ids[] = $parent_id;
      }
    }

    $balance = self::vacation_balance_hours($target_user_id, $exclude_shift_ids);
    if ($requested_hours > ($balance + 0.0001)) {
      return new WP_Error('vacation_balance_exceeded', 'That scheduled vacation exceeds the current vacation balance.');
    }
    return true;
  }

  public static function vacation_balance_hours(int $user_id, array $exclude_schedule_shift_ids = []): float {
    if ($user_id <= 0) return 0.0;
    $settings = self::get_settings()['vacation_accrual'];
    if (empty($settings['enabled'])) return 0.0;
    $hire_date = self::hire_date_for_user($user_id);
    if ($hire_date === '') return 0.0;
    $hire = strtotime($hire_date . ' 00:00:00');
    if (!$hire) return 0.0;
    $now = current_datetime()->getTimestamp();
    $accrued = self::vacation_accrued_hours($user_id, $settings, $hire, $now);
    if ((float)$settings['max_balance_hours'] > 0) $accrued = min($accrued, (float)$settings['max_balance_hours']);
    $used = 0.0;
    $items = get_posts([
      'post_type' => self::CPT_LEAVE,
      'post_status' => 'publish',
      'numberposts' => 1000,
      'meta_query' => [
        ['key' => self::META_TARGET_USER, 'value' => $user_id],
        ['key' => self::META_STATUS, 'value' => ['approved', 'pending'], 'compare' => 'IN'],
        ['key' => self::META_TYPE, 'value' => 'vacation'],
      ],
    ]);
    foreach ($items as $item) $used += (float)get_post_meta((int)$item->ID, self::META_HOURS, true);

    if (class_exists('EUM_Scheduling') && method_exists('EUM_Scheduling', 'effective_vacation_segments_for_user')) {
      $exclude_schedule_shift_ids = array_values(array_unique(array_filter(array_map('intval', $exclude_schedule_shift_ids), static fn($id) => $id > 0)));
      foreach (EUM_Scheduling::effective_vacation_segments_for_user($user_id, ['publish', 'draft'], 0, 0, $exclude_schedule_shift_ids) as $segment) {
        $start_utc = (int)($segment['start_utc'] ?? 0);
        $end_utc = (int)($segment['end_utc'] ?? 0);
        if ($start_utc > 0 && $end_utc > $start_utc) $used += ($end_utc - $start_utc) / HOUR_IN_SECONDS;
      }
    }

    return max(0.0, $accrued - $used);
  }

  private static function vacation_accrued_hours(int $user_id, array $settings, int $hire_ts, int $now_ts): float {
    $months = max(0, ((int)date('Y', $now_ts) - (int)date('Y', $hire_ts)) * 12 + ((int)date('n', $now_ts) - (int)date('n', $hire_ts)));
    $legacy = $months * (float)($settings['monthly_hours'] ?? 0);
    $rules = array_values(array_filter((array)($settings['rules'] ?? []), static fn($rule) => is_array($rule) && !empty($rule['enabled'])));
    if (empty($rules)) return $legacy;

    $total = 0.0;
    foreach ($rules as $rule) {
      if (!self::accrual_rule_matches_user($user_id, (array)$rule, $hire_ts, $now_ts)) continue;
      $periods = self::accrual_periods_between($hire_ts, $now_ts, (string)($rule['period'] ?? 'month'));
      $base = (float)($rule['base_hours'] ?? 0);
      $multiplier = (float)($rule['multiplier'] ?? 1);
      $hours_factor = self::accrual_logged_hours_factor($user_id, (array)$rule);
      $total += $periods * $base * max(0, $multiplier) * $hours_factor;
    }
    return $total;
  }

  private static function accrual_periods_between(int $start_ts, int $end_ts, string $period): float {
    $seconds = max(0, $end_ts - $start_ts);
    switch ($period) {
      case 'day':
        return $seconds / DAY_IN_SECONDS;
      case 'week':
        return $seconds / WEEK_IN_SECONDS;
      case 'year':
        return $seconds / YEAR_IN_SECONDS;
      case 'month':
      default:
        return $seconds / (30 * DAY_IN_SECONDS);
    }
  }

  private static function accrual_rule_matches_user(int $user_id, array $rule, int $hire_ts, int $now_ts): bool {
    $years = ($now_ts - $hire_ts) / YEAR_IN_SECONDS;
    $min = (float)($rule['years_min'] ?? 0);
    $max = (float)($rule['years_max'] ?? 0);
    if ($min > 0 && $years < $min) return false;
    if ($max > 0 && $years > $max) return false;

    $worker_types = array_filter(array_map('sanitize_key', (array)($rule['worker_types'] ?? [])));
    if (!empty($worker_types) && class_exists('EUM_Profiles') && !in_array(EUM_Profiles::worker_type_for_user($user_id), $worker_types, true)) return false;

    $roles = array_filter(array_map('sanitize_key', (array)($rule['roles'] ?? [])));
    if (!empty($roles)) {
      $user = get_userdata($user_id);
      if (!$user || empty(array_intersect($roles, array_map('sanitize_key', (array)$user->roles)))) return false;
    }

    $pay_structures = array_filter(array_map('trim', explode(',', strtolower((string)($rule['pay_structures'] ?? '')))));
    if (!empty($pay_structures)) {
      $fields = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
      $pay_structure = strtolower(sanitize_text_field((string)($fields['pay_structure'] ?? '')));
      if ($pay_structure === '' || !in_array($pay_structure, $pay_structures, true)) return false;
    }

    return true;
  }

  private static function accrual_logged_hours_factor(int $user_id, array $rule): float {
    $window_days = (int)($rule['hours_window_days'] ?? 0);
    if ($window_days <= 0 || !class_exists('EUM_Timeclock')) return 1.0;
    $hours = self::logged_hours_for_user($user_id, $window_days);
    if (!empty($rule['hours_average'])) {
      $hours = $hours / max(1, $window_days / 7);
    }
    $cap = (float)($rule['hours_average_cap'] ?? 0);
    if ($cap > 0) $hours = min($hours, $cap);
    return max(0.0, $hours) > 0 ? max(0.0, $hours) : 1.0;
  }

  private static function logged_hours_for_user(int $user_id, int $window_days): float {
    if ($user_id <= 0 || $window_days <= 0 || !class_exists('EUM_Timeclock')) return 0.0;
    $since = current_datetime()->getTimestamp() - ($window_days * DAY_IN_SECONDS);
    $items = get_posts([
      'post_type' => EUM_Timeclock::CPT_ENTRY,
      'post_status' => 'publish',
      'numberposts' => 1000,
      'meta_query' => [
        ['key' => EUM_Timeclock::META_USER_ID, 'value' => $user_id],
        ['key' => EUM_Timeclock::META_START_UTC, 'value' => $since, 'compare' => '>=', 'type' => 'NUMERIC'],
      ],
    ]);
    $hours = 0.0;
    foreach ($items as $item) {
      $start = (int)get_post_meta((int)$item->ID, EUM_Timeclock::META_START_UTC, true);
      $end = (int)get_post_meta((int)$item->ID, EUM_Timeclock::META_END_UTC, true);
      if ($start > 0 && $end > $start) $hours += ($end - $start) / HOUR_IN_SECONDS;
    }
    return $hours;
  }

  public static function hire_date_for_user(int $user_id): string {
    if ($user_id <= 0) return '';
    if (class_exists('EUM_Fields')) {
      $fields = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      $date = self::sanitize_date((string)($fields['hire_date'] ?? ''));
      if ($date !== '') return $date;
    }
    $user = get_userdata($user_id);
    return $user ? substr((string)$user->user_registered, 0, 10) : '';
  }

  public static function employment_duration_label(int $user_id): string {
    $hire_date = self::hire_date_for_user($user_id);
    if ($hire_date === '') return '';
    $hire = new DateTimeImmutable($hire_date, wp_timezone());
    $now = current_datetime();
    if ($hire > $now) return 'Starts ' . $hire->format(get_option('date_format'));
    $diff = $hire->diff($now);
    $parts = [];
    if ($diff->y > 0) $parts[] = $diff->y . ' year' . ($diff->y === 1 ? '' : 's');
    if ($diff->m > 0) $parts[] = $diff->m . ' month' . ($diff->m === 1 ? '' : 's');
    return !empty($parts) ? implode(', ', array_slice($parts, 0, 2)) : $diff->d . ' day' . ($diff->d === 1 ? '' : 's');
  }

  public function shortcode_employment_anniversaries($atts = []): string {
    if (!is_user_logged_in()) return '';
    $atts = shortcode_atts(['days' => '60'], (array)$atts, 'eum_employment_anniversaries');
    $days = max(1, min(366, (int)$atts['days']));
    $today = current_time('Y-m-d');
    $end = date('Y-m-d', strtotime($today . ' +' . $days . ' days'));
    $rows = [];
    foreach (get_users(['fields' => 'ID', 'number' => 0]) as $user_id) {
      $hire = self::hire_date_for_user((int)$user_id);
      if ($hire === '') continue;
      $anniv = self::next_anniversary_date($hire, $today);
      if ($anniv >= $today && $anniv <= $end) $rows[] = [(int)$user_id, $anniv];
    }
    usort($rows, fn($a, $b) => strcmp($a[1], $b[1]));
    if (empty($rows)) return '<div class="eum-anniversaries"><p>No upcoming anniversaries.</p></div>';
    ob_start();
    echo '<div class="eum-anniversaries"><ul>';
    foreach ($rows as $row) {
      $user_id = (int)$row[0];
      $avatar = class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html($user_id, 32, 'eum-directory__avatar') : '';
      echo '<li><span class="eum-directory__person">' . $avatar . '<span><strong>' . esc_html($this->user_label($user_id)) . '</strong> - ' . esc_html($row[1]) . ' (' . esc_html(self::employment_duration_label($user_id)) . ')</span></span></li>';
    }
    echo '</ul></div>';
    return ob_get_clean();
  }

  private static function next_anniversary_date(string $hire_date, string $today): string {
    $month_day = substr($hire_date, 5, 5);
    $year = (int)substr($today, 0, 4);
    $anniv = $year . '-' . $month_day;
    if ($anniv < $today) $anniv = ($year + 1) . '-' . $month_day;
    return $anniv;
  }

  public function shortcode_employee_lookup($atts = []): string {
    if (!is_user_logged_in()) return '';
    return '<div class="eum-employee-lookup">' . $this->render_employee_lookup_box() . '</div>';
  }

  private function render_employee_lookup_box(): string {
    $q = sanitize_text_field((string)($_GET['eum_employee_lookup'] ?? ''));
    $viewer_user_id = (int)get_current_user_id();
    $can_view_people_finder = class_exists('EUM_Access') && EUM_Access::is_it_or_hr($viewer_user_id);
    $people_filters = $can_view_people_finder ? $this->people_directory_filters_from_request() : [];
    $has_people_filters = $can_view_people_finder && $this->people_directory_has_filters($people_filters);
    ob_start();
    echo '<div style="margin-top:18px;background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px;max-width:1000px;">';
    echo '<form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';
    echo $this->render_lookup_preserved_inputs(['eum_employee_lookup']);
    echo '<label><strong>Employee Lookup</strong><br><input type="search" name="eum_employee_lookup" value="' . esc_attr($q) . '" class="regular-text" placeholder="Employee ID, username, email, or eum-id"></label>';
    echo '<button class="button button-primary" type="submit">Search</button></form>';
    if ($q !== '') {
      $user_id = self::resolve_user_reference($q, true);
      if ($user_id > 0) {
        $u = get_userdata($user_id);
        $manager_id = class_exists('EUM_Personnel') ? EUM_Personnel::effective_manager_user_id($user_id) : 0;
        $manager = $manager_id > 0 ? get_userdata($manager_id) : false;
        $job_title = class_exists('EUM_Profiles') ? EUM_Profiles::preferred_job_title_label($user_id) : '';
        echo '<table class="widefat striped" style="margin-top:14px;"><tbody>';
        foreach ([
          'Name' => $u ? ($u->display_name ?: $u->user_login) : ('User #' . $user_id),
          'Employee ID' => self::employee_id_for_user($user_id),
          'Username' => $u ? $u->user_login : '',
          'Email' => $u ? $u->user_email : '',
          'Title' => $job_title,
          'Manager' => $manager instanceof WP_User ? ($manager->display_name ?: $manager->user_login) : '',
          'Manager Email' => $manager instanceof WP_User ? $manager->user_email : '',
          'Employment Time' => self::employment_duration_label($user_id),
          'Out of Office' => self::out_of_office_label($user_id),
        ] as $label => $value) {
          if ((string)$value === '') continue;
          if ($label === 'Name' && $u instanceof WP_User && class_exists('EUM_Profiles')) {
            echo '<tr><th style="width:190px;">' . esc_html($label) . '</th><td><span class="eum-directory__person">' . EUM_Profiles::profile_avatar_html($user_id, 36, 'eum-directory__avatar') . '<strong>' . esc_html((string)$value) . '</strong></span></td></tr>';
          } elseif ($label === 'Manager' && $manager instanceof WP_User && class_exists('EUM_Profiles')) {
            echo '<tr><th style="width:190px;">' . esc_html($label) . '</th><td><span class="eum-directory__manager">' . EUM_Profiles::profile_avatar_html((int)$manager->ID, 30, 'eum-directory__avatar') . '<span>' . esc_html((string)$value) . '</span></span></td></tr>';
          } else {
            echo '<tr><th style="width:190px;">' . esc_html($label) . '</th><td>' . esc_html((string)$value) . '</td></tr>';
          }
        }
        echo '</tbody></table>';
      } else {
        echo '<p style="margin-top:14px;">No matching user found.</p>';
      }
    }
    if ($can_view_people_finder) {
      echo '<div style="margin-top:22px;padding-top:18px;border-top:1px solid #e5e7eb;">';
      echo '<h3 style="margin:0 0 6px;">People Finder</h3>';
      echo '<p style="margin:0 0 14px;color:#50575e;">Filter employees by audience assignment and view their core contact chain.</p>';
      echo '<form method="get">';
      echo $this->render_lookup_preserved_inputs($this->people_directory_filter_query_keys());
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;align-items:start;">';
      echo '<label><strong>Name or ID</strong><br><input type="search" name="eum_people_query" value="' . esc_attr((string)$people_filters['query']) . '" class="regular-text" placeholder="Name, email, username, or Employee ID" style="width:100%;"></label>';
      echo '<label><strong>User Group</strong><br>' . $this->render_people_directory_term_select('eum_user_group', 'eum_people_group', (int)$people_filters['group_term_id'], 'Any group') . '</label>';
      echo '<label><strong>Project</strong><br>' . $this->render_people_directory_term_select('eum_project', 'eum_people_project', (int)$people_filters['project_term_id'], 'Any project') . '</label>';
      echo '<label><strong>Job Title</strong><br>' . $this->render_people_directory_job_title_select((string)$people_filters['job_title']) . '</label>';
      echo '</div>';
      echo '<div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;align-items:start;">';
      echo '<div><strong>Job Path</strong><div style="margin-top:8px;">' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_taxonomy_directory_picker('eum_job_path', 'eum_people_job_path', (int)$people_filters['job_path_term_id'], $viewer_user_id, ['placeholder' => 'Select a job path', 'none_label' => 'Any job path']) : '') . '</div></div>';
      echo '<div><strong>Location</strong><div style="margin-top:8px;">' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_taxonomy_directory_picker('eum_location', 'eum_people_location', (int)$people_filters['location_term_id'], $viewer_user_id, ['placeholder' => 'Select a location', 'none_label' => 'Any location']) : '') . '</div></div>';
      echo '</div>';
      echo '<p style="margin:14px 0 0;"><label><input type="checkbox" name="eum_people_include_inactive" value="1" ' . checked(!empty($people_filters['include_inactive']), true, false) . '> Include terminated/inactive users</label></p>';
      echo '<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';
      echo '<button class="button button-primary" type="submit">Run Search</button>';
      echo '<a class="button button-secondary" href="' . esc_url($this->people_directory_clear_url()) . '">Clear Filters</a>';
      echo '</div>';
      echo '</form>';
      if ($has_people_filters) {
        echo $this->render_people_directory_results($people_filters);
      } else {
        echo '<p style="margin-top:14px;color:#50575e;">Choose at least one filter or search term to load matching employees.</p>';
      }
      echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_lookup_preserved_inputs(array $exclude_keys = []): string {
    $excluded = [];
    foreach ($exclude_keys as $key) {
      $excluded[sanitize_key((string)$key)] = true;
    }

    ob_start();
    foreach ($_GET as $key => $value) {
      $sanitized_key = sanitize_key((string)$key);
      if (isset($excluded[$sanitized_key]) || !is_scalar($value)) continue;
      echo '<input type="hidden" name="' . esc_attr((string)$key) . '" value="' . esc_attr((string)$value) . '">';
    }
    return (string)ob_get_clean();
  }

  private function people_directory_filter_query_keys(): array {
    return [
      'eum_people_query',
      'eum_people_job_path',
      'eum_people_location',
      'eum_people_group',
      'eum_people_project',
      'eum_people_title',
      'eum_people_include_inactive',
    ];
  }

  private function people_directory_filters_from_request(): array {
    return [
      'query' => sanitize_text_field((string)($_GET['eum_people_query'] ?? '')),
      'job_path_term_id' => isset($_GET['eum_people_job_path']) ? max(0, (int)$_GET['eum_people_job_path']) : 0,
      'location_term_id' => isset($_GET['eum_people_location']) ? max(0, (int)$_GET['eum_people_location']) : 0,
      'group_term_id' => isset($_GET['eum_people_group']) ? max(0, (int)$_GET['eum_people_group']) : 0,
      'project_term_id' => isset($_GET['eum_people_project']) ? max(0, (int)$_GET['eum_people_project']) : 0,
      'job_title' => sanitize_text_field((string)($_GET['eum_people_title'] ?? '')),
      'include_inactive' => !empty($_GET['eum_people_include_inactive']),
    ];
  }

  private function people_directory_has_filters(array $filters): bool {
    return trim((string)($filters['query'] ?? '')) !== ''
      || (int)($filters['job_path_term_id'] ?? 0) > 0
      || (int)($filters['location_term_id'] ?? 0) > 0
      || (int)($filters['group_term_id'] ?? 0) > 0
      || (int)($filters['project_term_id'] ?? 0) > 0
      || trim((string)($filters['job_title'] ?? '')) !== '';
  }

  private function people_directory_clear_url(): string {
    return remove_query_arg($this->people_directory_filter_query_keys(), $this->current_url());
  }

  private function render_people_directory_term_select(string $taxonomy, string $input_name, int $selected_term_id, string $placeholder): string {
    if (!taxonomy_exists($taxonomy)) {
      return '<input type="hidden" name="' . esc_attr($input_name) . '" value="0">';
    }

    $terms = get_terms([
      'taxonomy' => $taxonomy,
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
      'number' => 0,
    ]);
    if (is_wp_error($terms) || empty($terms)) {
      return '<select name="' . esc_attr($input_name) . '" style="width:100%;"><option value="0">' . esc_html($placeholder) . '</option></select>';
    }

    ob_start();
    echo '<select name="' . esc_attr($input_name) . '" style="width:100%;max-width:100%;">';
    echo '<option value="0">' . esc_html($placeholder) . '</option>';
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      $label = sanitize_text_field((string)$term->name);
      echo '<option value="' . $term_id . '"' . selected($selected_term_id === $term_id, true, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    return (string)ob_get_clean();
  }

  private function render_people_directory_job_title_select(string $selected_title): string {
    $titles = $this->people_directory_job_title_options();
    ob_start();
    echo '<select name="eum_people_title" style="width:100%;max-width:100%;">';
    echo '<option value="">Any job title</option>';
    foreach ($titles as $title) {
      echo '<option value="' . esc_attr($title) . '"' . selected($selected_title === $title, true, false) . '>' . esc_html($title) . '</option>';
    }
    echo '</select>';
    return (string)ob_get_clean();
  }

  private function people_directory_job_title_options(): array {
    $titles = [];

    if (class_exists('EUM_Job_Titles')) {
      foreach (EUM_Job_Titles::title_posts() as $post) {
        $title = sanitize_text_field((string)($post->post_title ?? ''));
        if ($title !== '') $titles[$title] = $title;
      }
    }

    if (class_exists('EUM_Profiles')) {
      foreach (get_users(['fields' => 'ID', 'number' => 0]) as $user_id) {
        $title = EUM_Profiles::preferred_job_title_label((int)$user_id);
        if ($title !== '') $titles[$title] = $title;
      }
    }

    natcasesort($titles);
    return array_values($titles);
  }

  private function render_people_directory_results(array $filters): string {
    $matching_user_ids = $this->people_directory_matching_user_ids($filters);
    $total = count($matching_user_ids);
    $limit = 500;
    if ($total > $limit) {
      $matching_user_ids = array_slice($matching_user_ids, 0, $limit);
    }

    ob_start();
    if ($total === 0) {
      echo '<p style="margin-top:16px;">No matching employees found.</p>';
      return (string)ob_get_clean();
    }

    echo '<p style="margin-top:16px;"><strong>' . esc_html((string)$total) . '</strong> employee' . ($total === 1 ? '' : 's') . ' found.';
    if ($total > $limit) {
      echo ' Showing the first ' . esc_html((string)$limit) . '.';
    }
    echo '</p>';
    echo '<div style="margin-top:12px;overflow:auto;">';
    echo '<table class="widefat striped"><thead><tr><th>Employee ID</th><th>Name</th><th>Title</th><th>Email</th><th>Manager</th><th>Manager Email</th></tr></thead><tbody>';
    foreach ($matching_user_ids as $user_id) {
      $user = get_userdata($user_id);
      if (!$user) continue;
      $manager_id = class_exists('EUM_Personnel') ? EUM_Personnel::effective_manager_user_id($user_id) : 0;
      $manager = $manager_id > 0 ? get_userdata($manager_id) : false;
      $title = class_exists('EUM_Profiles') ? EUM_Profiles::preferred_job_title_label($user_id) : '';
      echo '<tr>';
      echo '<td>' . esc_html(self::employee_id_for_user($user_id)) . '</td>';
      echo '<td><span class="eum-directory__person">' . (class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html((int)$user_id, 32, 'eum-directory__avatar') : '') . '<span>' . esc_html($user->display_name ?: $user->user_login) . '</span></span></td>';
      echo '<td>' . esc_html($title) . '</td>';
      echo '<td>' . esc_html((string)$user->user_email) . '</td>';
      if ($manager instanceof WP_User && class_exists('EUM_Profiles')) {
        echo '<td><span class="eum-directory__manager">' . EUM_Profiles::profile_avatar_html((int)$manager->ID, 26, 'eum-directory__avatar eum-directory__avatar--small') . '<span>' . esc_html($manager->display_name ?: $manager->user_login) . '</span></span></td>';
      } else {
        echo '<td>' . esc_html($manager instanceof WP_User ? ($manager->display_name ?: $manager->user_login) : '') . '</td>';
      }
      echo '<td>' . esc_html($manager instanceof WP_User ? (string)$manager->user_email : '') . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function people_directory_matching_user_ids(array $filters): array {
    $matches = [];
    foreach (get_users(['fields' => 'ID', 'number' => 0]) as $user_id) {
      $user_id = (int)$user_id;
      if ($user_id <= 0) continue;
      if (!$this->people_directory_user_matches($user_id, $filters)) continue;
      $matches[] = $user_id;
    }

    usort($matches, static function($a, $b) {
      $user_a = get_userdata((int)$a);
      $user_b = get_userdata((int)$b);
      $label_a = $user_a ? strtolower((string)($user_a->display_name ?: $user_a->user_login)) : '';
      $label_b = $user_b ? strtolower((string)($user_b->display_name ?: $user_b->user_login)) : '';
      if ($label_a === $label_b) return ((int)$a) <=> ((int)$b);
      return $label_a <=> $label_b;
    });

    return $matches;
  }

  private function people_directory_user_matches(int $user_id, array $filters): bool {
    $user = get_userdata($user_id);
    if (!$user) return false;

    if (empty($filters['include_inactive']) && class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) {
      return false;
    }

    if ((int)($filters['job_path_term_id'] ?? 0) > 0 && !self::user_matches_taxonomy_filter($user_id, 'eum_job_path', (int)$filters['job_path_term_id'], true)) {
      return false;
    }
    if ((int)($filters['location_term_id'] ?? 0) > 0 && !self::user_matches_taxonomy_filter($user_id, 'eum_location', (int)$filters['location_term_id'], true)) {
      return false;
    }
    if ((int)($filters['group_term_id'] ?? 0) > 0 && !self::user_matches_taxonomy_filter($user_id, 'eum_user_group', (int)$filters['group_term_id'], false)) {
      return false;
    }
    if ((int)($filters['project_term_id'] ?? 0) > 0 && !self::user_matches_taxonomy_filter($user_id, 'eum_project', (int)$filters['project_term_id'], false)) {
      return false;
    }

    $job_title = class_exists('EUM_Profiles') ? EUM_Profiles::preferred_job_title_label($user_id) : '';
    if (trim((string)($filters['job_title'] ?? '')) !== '' && strcasecmp($job_title, (string)$filters['job_title']) !== 0) {
      return false;
    }

    $query = strtolower(trim((string)($filters['query'] ?? '')));
    if ($query !== '') {
      $haystacks = [
        (string)$user->display_name,
        (string)$user->user_login,
        (string)$user->user_email,
        self::employee_id_for_user($user_id),
        $job_title,
      ];
      $matched = false;
      foreach ($haystacks as $haystack) {
        if ($haystack !== '' && strpos(strtolower($haystack), $query) !== false) {
          $matched = true;
          break;
        }
      }
      if (!$matched) return false;
    }

    return true;
  }

  private static function user_matches_taxonomy_filter(int $user_id, string $taxonomy, int $selected_term_id, bool $include_descendants): bool {
    if ($selected_term_id <= 0) return true;
    if (!taxonomy_exists($taxonomy)) return false;
    $term_ids = wp_get_object_terms($user_id, $taxonomy, ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) return false;

    foreach (array_map('intval', (array)$term_ids) as $term_id) {
      if ($term_id === $selected_term_id) return true;
      if ($include_descendants && term_is_ancestor_of($selected_term_id, $term_id, $taxonomy)) return true;
    }

    return false;
  }

  public static function resolve_user_from_request(array $atts = [], string $query_var = 'eum_user', bool $fallback_current = true): int {
    $raw = '';
    $prefer_employee = !empty(self::get_settings()['employee_id_default_lookup']);
    if (!empty($atts['user_id'])) {
      $raw = (string)$atts['user_id'];
    } elseif (!empty($atts['user'])) {
      $raw = (string)$atts['user'];
    } elseif (isset($_GET['employee_id'])) {
      $raw = (string)$_GET['employee_id'];
      $prefer_employee = true;
    } elseif (isset($_GET['employee'])) {
      $raw = (string)$_GET['employee'];
      $prefer_employee = true;
    } elseif (isset($_GET['employee_username'])) {
      $u = get_user_by('login', sanitize_user((string)$_GET['employee_username'], true));
      return $u ? (int)$u->ID : ($fallback_current ? (int)get_current_user_id() : 0);
    } elseif (isset($_GET['eum-id'])) {
      $raw = 'eum-id:' . (string)$_GET['eum-id'];
      $prefer_employee = false;
    } elseif (isset($_GET['eum_id'])) {
      $raw = 'eum-id:' . (string)$_GET['eum_id'];
      $prefer_employee = false;
    } elseif ($query_var !== '' && isset($_GET[$query_var])) {
      $raw = (string)$_GET[$query_var];
    }
    $raw = trim($raw);
    if ($raw === '') return $fallback_current ? (int)get_current_user_id() : 0;
    return self::resolve_user_reference($raw, $prefer_employee);
  }

  public static function resolve_user_reference(string $raw, bool $prefer_employee_id = true): int {
    $raw = trim($raw);
    if ($raw === '') return 0;
    if (preg_match('/^(?:wp|wordpress|user|eum-id|eum_id)\s*[:=#-]\s*(\d+)$/i', $raw, $m)) {
      return get_userdata((int)$m[1]) ? (int)$m[1] : 0;
    }
    if (stripos($raw, 'employee:') === 0) {
      $found = self::find_user_by_employee_id(trim(substr($raw, 9)));
      return $found > 0 ? $found : 0;
    }
    if ($prefer_employee_id) {
      $found = self::find_user_by_employee_id($raw);
      if ($found > 0) return $found;
    }
    $u = get_user_by('login', sanitize_user($raw, true));
    if (!$u && is_email($raw)) $u = get_user_by('email', sanitize_email($raw));
    if ($u) return (int)$u->ID;
    if (!$prefer_employee_id) {
      $found = self::find_user_by_employee_id($raw);
      if ($found > 0) return $found;
    }
    if (ctype_digit($raw)) return get_userdata((int)$raw) ? (int)$raw : 0;
    return 0;
  }

  public static function employee_id_for_user(int $user_id): string {
    if ($user_id <= 0) return '';
    if (class_exists('EUM_Fields')) {
      $fields = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      $value = sanitize_text_field((string)($fields['employee_id'] ?? ''));
      if ($value !== '') return $value;
    }
    return sanitize_text_field((string)get_user_meta($user_id, 'employee_id', true));
  }

  public static function set_employee_id(int $user_id, string $employee_id): void {
    if ($user_id <= 0) return;
    $employee_id = sanitize_text_field($employee_id);
    if (class_exists('EUM_Fields')) {
      $fields = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      $fields['employee_id'] = $employee_id;
      update_user_meta($user_id, EUM_Fields::USER_META_KEY, $fields);
    }
    update_user_meta($user_id, 'employee_id', $employee_id);
  }

  public static function assign_next_employee_id(int $user_id, bool $overwrite = false): string {
    if ($user_id <= 0) return '';
    if (!$overwrite && self::employee_id_for_user($user_id) !== '') return self::employee_id_for_user($user_id);
    $settings = self::get_settings();
    $next = max(1, (int)$settings['employee_id_next']);
    $employee_id = '';
    do {
      $employee_id = (string)$settings['employee_id_prefix'] . str_pad((string)$next, (int)$settings['employee_id_padding'], '0', STR_PAD_LEFT);
      $next++;
    } while (self::find_user_by_employee_id($employee_id) > 0);
    self::set_employee_id($user_id, $employee_id);
    $settings['employee_id_next'] = $next;
    update_option(self::OPTION_KEY, $settings, false);
    return $employee_id;
  }

  public static function find_user_by_employee_id(string $employee_id): int {
    $needle = strtolower(trim($employee_id));
    if ($needle === '') return 0;
    foreach (get_users(['fields' => 'ID', 'number' => 0]) as $user_id) {
      if (strtolower(self::employee_id_for_user((int)$user_id)) === $needle) return (int)$user_id;
    }
    return 0;
  }

  public static function terminated_login_allowed(int $user_id): bool {
    if ($user_id <= 0 || !class_exists('EUM_Personnel') || !EUM_Personnel::is_terminated($user_id)) return false;
    return !empty(self::get_settings()['allow_terminated_login']);
  }

  public static function should_skip_external_provisioning(int $user_id): bool {
    return self::terminated_login_allowed($user_id);
  }

  public static function viewer_can_see_terminated_word(int $viewer_user_id = 0): bool {
    $viewer_user_id = $viewer_user_id > 0 ? $viewer_user_id : (int)get_current_user_id();
    if ($viewer_user_id <= 0 || !class_exists('EUM_Access')) return false;
    return EUM_Access::is_it_or_hr($viewer_user_id)
      || EUM_Access::is_finance_user($viewer_user_id)
      || EUM_Access::is_manager_user($viewer_user_id);
  }

  public static function terminated_label_for_viewer(int $viewer_user_id = 0): string {
    return self::viewer_can_see_terminated_word($viewer_user_id) ? 'Terminated' : 'Alumni/Former Staff';
  }

  public static function employment_status_label_for_user(int $target_user_id, int $viewer_user_id = 0): string {
    if (!class_exists('EUM_Personnel') || !EUM_Personnel::is_terminated($target_user_id)) return 'Active';
    $base = self::terminated_label_for_viewer($viewer_user_id);
    $eligibility = EUM_Personnel::is_rehirable($target_user_id) ? 'Rehirable' : 'Non-rehireable';
    return $base . ' - ' . $eligibility;
  }

  public static function employee_list_label(int $user_id, bool $include_employee_id = true): string {
    $user = get_userdata($user_id);
    if (!$user) return 'User #' . $user_id;
    $employee_id = $include_employee_id ? self::employee_id_for_user($user_id) : '';
    return ($user->display_name ?: $user->user_login) . ($employee_id !== '' ? ' (' . $employee_id . ')' : '');
  }

  private static function sanitize_date(string $value): string {
    $value = preg_replace('/[^0-9\-]/', '', trim($value));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
  }

  private function user_label(int $user_id): string {
    return self::employee_list_label($user_id);
  }

  private function current_url(): string {
    $scheme = is_ssl() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string)$_SERVER['HTTP_HOST']) : parse_url(home_url('/'), PHP_URL_HOST);
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '/';
    return esc_url_raw($scheme . '://' . $host . $request_uri);
  }
}
