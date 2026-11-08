<?php
if (!defined('ABSPATH')) exit;

class EUM_Profiles {
  const OPTION_SETTINGS = 'eum_profile_settings';
  const CPT_CHANGE = 'eum_profile_change';

  const META_STATUS = '_eum_change_status';
  const META_TARGET_USER = '_eum_target_user_id';
  const META_ACTOR_USER = '_eum_actor_user_id';
  const META_APPROVER_IDS = '_eum_approver_user_ids';
  const META_APPROVED_BY = '_eum_approved_by_user_id';
  const META_APPROVED_AT = '_eum_approved_at';
  const META_BEFORE = '_eum_before';
  const META_AFTER = '_eum_after';
  const META_NOTE = '_eum_note';
  const META_EFFECTIVE_AT = '_eum_effective_at';
  const META_OCCURRED_AT = '_eum_occurred_at';
  const META_MANUAL = '_eum_manual';
  const META_SKIP_LOG = '_eum_skip_log';
  const META_SKIP_EMAIL = '_eum_skip_email';
  const META_APPLY_ERROR = '_eum_apply_error';
  const META_TRANSFER_PROTOCOL = '_eum_transfer_protocol';
  const META_TRANSFER_OLD_MANAGER = '_eum_transfer_old_manager_user_id';
  const META_TRANSFER_NEW_MANAGER = '_eum_transfer_new_manager_user_id';
  const META_TRANSFER_PROPOSED_MAX_AT = '_eum_transfer_proposed_max_effective_at';
  const META_PROFILE_IMAGE_URL = '_eum_profile_image_url';
  const META_PROFILE_IMAGE_ID = '_eum_profile_image_id';
  const META_PROFILE_IMAGE_COLOR = '_eum_profile_image_color';
  const USER_META_WORK_HISTORY = '_eum_candidate_work_history';
  const USER_META_EDUCATION_HISTORY = '_eum_candidate_education_history';
  const USER_META_RESUME_ATTACHMENT_ID = '_eum_candidate_resume_attachment_id';
  const USER_META_COVER_LETTER_ATTACHMENT_ID = '_eum_candidate_cover_letter_attachment_id';

  private static $job_picker_assets_printed = false;
  private static $taxonomy_picker_assets_printed = false;

  public function init(): void {
    add_action('init', [$this, 'register_change_cpt']);
    add_action('init', [$this, 'apply_due_changes'], 30);

    add_action('admin_menu', [$this, 'admin_menu'], 45);

    add_action('admin_post_eum_profile_settings_save', [$this, 'handle_settings_save']);
    add_action('admin_post_eum_profile_log_save', [$this, 'handle_admin_log_save']);
    add_action('admin_post_eum_profile_log_update', [$this, 'handle_admin_log_update']);
    add_action('admin_post_eum_profile_log_delete', [$this, 'handle_admin_log_delete']);
    add_action('admin_post_eum_profile_log_bulk_action', [$this, 'handle_admin_log_bulk_action']);
    add_action('admin_post_eum_profile_log_export', [$this, 'handle_admin_log_export']);
    add_action('admin_post_eum_profile_log_import', [$this, 'handle_admin_log_import']);

    add_action('admin_post_eum_profile_save', [$this, 'handle_frontend_save']);
    add_action('admin_post_eum_profile_change_action', [$this, 'handle_change_action']);
    add_action('admin_post_eum_profile_personnel_save', [$this, 'handle_frontend_personnel_save']);
    add_action('admin_post_eum_profile_employment_action', [$this, 'handle_frontend_employment_action']);
    add_action('admin_post_eum_profile_image_save', [$this, 'handle_profile_image_save']);
    add_action('admin_post_eum_profile_generated_avatar', [$this, 'handle_generated_profile_avatar']);
    add_action('admin_post_nopriv_eum_profile_generated_avatar', [$this, 'handle_generated_profile_avatar']);

    add_shortcode('eum_public_profile', [$this, 'shortcode_public_profile']);
    add_shortcode('eum_private_profile', [$this, 'shortcode_private_profile']);
    add_shortcode('eum_work_profile', [$this, 'shortcode_work_profile']);
    add_shortcode('eum_profile_appearance', [$this, 'shortcode_profile_appearance']);
  }

  public function register_change_cpt(): void {
    register_post_type(self::CPT_CHANGE, [
      'labels' => [
        'name' => 'Profile Changes',
        'singular_name' => 'Profile Change',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public function admin_menu(): void {
    add_submenu_page(
      EUM_Admin::parent_slug('workforce'),
      'Profile Access',
      'Profile Access',
      'read',
      'eum-profile-access',
      [$this, 'render_settings_page']
    );

    add_submenu_page(
      EUM_Admin::parent_slug('workforce'),
      'Profile Changes',
      'Profile Changes',
      'read',
      'eum-profile-changes',
      [$this, 'render_changes_page']
    );
  }

  public static function default_settings(): array {
    $custom_ids = [];
    if (class_exists('EUM_Fields')) {
      foreach (EUM_Fields::get_definitions() as $def) {
        $key = sanitize_key($def['key'] ?? '');
        if ($key !== '' && $key !== 'employee_id') $custom_ids[] = 'custom:' . $key;
      }
    }
    $public_meta_ids = class_exists('EUM_Public_Meta') ? array_keys(EUM_Public_Meta::profile_field_definitions()) : [];

    return [
      'public_profiles_enabled' => 1,
      'public_fields' => ['core:display_name', 'tax:eum_location', 'core:eum_manager_user_id', 'tax:eum_job_path'],
      'self_edit_fields' => array_values(array_unique(array_merge(['core:first_name', 'core:last_name', 'core:display_name'], $custom_ids))),
      'manager_edit_fields' => array_values(array_unique(array_merge(['core:first_name', 'core:last_name', 'core:display_name', 'core:eum_manager_user_id', 'tax:eum_location', 'tax:eum_job_path'], $custom_ids, $public_meta_ids))),
      'manager_edit_fields_without_approval' => [],
      'logging_enabled' => 1,
      'force_next_sunday' => 0,
      'query_var' => 'eum_user',
      'reason_options' => self::default_reason_options(),
    ];
  }

  public static function get_settings(): array {
    $saved = get_option(self::OPTION_SETTINGS, []);
    $saved = is_array($saved) ? $saved : [];
    $settings = array_merge(self::default_settings(), $saved);

    foreach (['public_fields', 'self_edit_fields', 'manager_edit_fields', 'manager_edit_fields_without_approval'] as $key) {
      $settings[$key] = array_values(array_filter(array_map('strval', (array)($settings[$key] ?? []))));
      $settings[$key] = self::strip_locked_profile_fields($settings[$key]);
    }

    $settings['public_profiles_enabled'] = !empty($settings['public_profiles_enabled']) ? 1 : 0;
    $settings['logging_enabled'] = !empty($settings['logging_enabled']) ? 1 : 0;
    $settings['force_next_sunday'] = !empty($settings['force_next_sunday']) ? 1 : 0;
    $settings['query_var'] = sanitize_key((string)($settings['query_var'] ?? 'eum_user')) ?: 'eum_user';
    $settings['reason_options'] = self::normalize_reason_options((array)($settings['reason_options'] ?? self::default_reason_options()));

    foreach (['self_edit_fields', 'manager_edit_fields', 'manager_edit_fields_without_approval'] as $settings_key) {
      if (!in_array('custom:job_title', $settings[$settings_key], true)) $settings[$settings_key][] = 'custom:job_title';
    }
    if (!in_array('custom:worker_type', $settings['manager_edit_fields'], true)) $settings['manager_edit_fields'][] = 'custom:worker_type';

    return $settings;
  }

  private static function locked_profile_field_ids(): array {
    return ['custom:employee_id'];
  }

  private static function strip_locked_profile_fields(array $field_ids): array {
    return array_values(array_diff(array_values(array_filter(array_map('strval', $field_ids))), self::locked_profile_field_ids()));
  }

  public static function set_settings(array $settings): void {
    $clean = self::get_settings();
    $clean['public_profiles_enabled'] = !empty($settings['public_profiles_enabled']) ? 1 : 0;
    $clean['logging_enabled'] = !empty($settings['logging_enabled']) ? 1 : 0;
    $clean['force_next_sunday'] = !empty($settings['force_next_sunday']) ? 1 : 0;
    $clean['query_var'] = sanitize_key((string)($settings['query_var'] ?? 'eum_user')) ?: 'eum_user';
    $clean['reason_options'] = self::normalize_reason_options((array)($settings['reason_options'] ?? self::default_reason_options()));

    $catalog = self::field_catalog();
    foreach (['public_fields', 'self_edit_fields', 'manager_edit_fields', 'manager_edit_fields_without_approval'] as $key) {
      $vals = array_values(array_filter(array_map('strval', (array)($settings[$key] ?? []))));
      $vals = array_values(array_filter($vals, fn($id) => isset($catalog[$id])));
      $vals = self::strip_locked_profile_fields($vals);
      $clean[$key] = $vals;
    }

    update_option(self::OPTION_SETTINGS, $clean, false);
  }


  public static function default_reason_options(): array {
    return [
      ['label' => 'Profile correction', 'roles' => ['self', 'manager', 'hr', 'it']],
      ['label' => 'Promotion', 'roles' => ['manager', 'hr', 'it']],
      ['label' => 'Transfer', 'roles' => ['manager', 'hr', 'it']],
      ['label' => 'Manager change', 'roles' => ['manager', 'hr', 'it']],
      ['label' => 'Scheduled update', 'roles' => ['manager', 'hr', 'it']],
      ['label' => 'Termination / Reactivation', 'roles' => ['manager', 'hr', 'it']],
    ];
  }

  public static function normalize_reason_options(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($label === '') continue;
      $roles = array_values(array_intersect(['self', 'manager', 'hr', 'it'], array_map('sanitize_key', (array)($row['roles'] ?? []))));
      if (empty($roles)) $roles = ['it'];
      $out[] = ['label' => $label, 'roles' => array_values(array_unique($roles))];
    }
    return empty($out) ? self::default_reason_options() : $out;
  }

  public static function actor_reason_role_key(int $actor_user_id, int $target_user_id): string {
    if (EUM_Access::is_it_user($actor_user_id)) return 'it';
    if (EUM_Access::is_hr_user($actor_user_id)) return 'hr';
    if ($actor_user_id !== $target_user_id && self::is_manager_of_target($actor_user_id, $target_user_id)) return 'manager';
    return 'self';
  }

  public static function reason_options_for_actor(int $actor_user_id, int $target_user_id): array {
    $settings = self::get_settings();
    $role = self::actor_reason_role_key($actor_user_id, $target_user_id);
    $out = [];
    foreach ((array)$settings['reason_options'] as $row) {
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      $roles = array_map('sanitize_key', (array)($row['roles'] ?? []));
      if ($label !== '' && in_array($role, $roles, true)) $out[] = $label;
    }
    return array_values(array_unique($out));
  }

  public static function field_catalog(): array {
    $fields = [
      'core:first_name' => ['label' => 'First Name', 'group' => 'Core', 'type' => 'text'],
      'core:last_name' => ['label' => 'Last Name', 'group' => 'Core', 'type' => 'text'],
      'core:display_name' => ['label' => 'Display Name', 'group' => 'Core', 'type' => 'text'],
      'core:eum_profile_image_url' => ['label' => 'Profile Image', 'group' => 'Personal', 'type' => 'text'],
      'core:eum_profile_image_color' => ['label' => 'Profile Accent Color', 'group' => 'Personal', 'type' => 'text'],
      'core:user_email' => ['label' => 'Email', 'group' => 'Core', 'type' => 'email'],
      'core:user_pass' => ['label' => 'Password', 'group' => 'Core', 'type' => 'password'],
      'core:eum_manager_user_id' => ['label' => 'Direct Report / Manager', 'group' => 'Core', 'type' => 'user_select'],
      'core:job_company' => ['label' => 'Company Division', 'group' => 'Classification', 'type' => 'division_select'],

      'tax:eum_job_path' => ['label' => 'Job Path', 'group' => 'Classification', 'type' => 'taxonomy_single', 'taxonomy' => 'eum_job_path'],
      'tax:eum_location' => ['label' => 'Location', 'group' => 'Classification', 'type' => 'taxonomy_single', 'taxonomy' => 'eum_location'],
      'tax:eum_user_group' => ['label' => 'Groups', 'group' => 'Classification', 'type' => 'taxonomy_multi', 'taxonomy' => 'eum_user_group'],
      'tax:eum_project' => ['label' => 'Projects', 'group' => 'Classification', 'type' => 'taxonomy_multi', 'taxonomy' => 'eum_project'],
    ];

    if (class_exists('EUM_Fields')) {
      foreach (EUM_Fields::get_definitions() as $def) {
        $key = sanitize_key($def['key'] ?? '');
        if ($key === '') continue;
        $type = sanitize_key($def['type'] ?? 'text');
        if ($key === 'job_title') $type = 'job_title_select';
        if ($key === 'worker_type') $type = 'worker_type_select';
        $fields['custom:' . $key] = [
          'label' => sanitize_text_field($def['label'] ?? $key),
          'group' => 'Custom Fields',
          'type' => $type,
          'custom_key' => $key,
        ];
      }
    }

    if (!isset($fields['custom:job_title'])) {
      $fields['custom:job_title'] = ['label' => 'Job Title', 'group' => 'Custom Fields', 'type' => 'job_title_select', 'custom_key' => 'job_title'];
    }
    if (!isset($fields['custom:worker_type'])) {
      $fields['custom:worker_type'] = ['label' => 'Worker Type', 'group' => 'Custom Fields', 'type' => 'worker_type_select', 'custom_key' => 'worker_type'];
    }

    if (class_exists('EUM_Public_Meta')) $fields = array_merge($fields, EUM_Public_Meta::profile_field_definitions());

    return $fields;
  }


  public static function candidate_extended_profile_keys(): array {
    return [
      'custom:work_history',
      'custom:education_history',
      'custom:resume_attachment_id',
      'custom:cover_letter_attachment_id',
    ];
  }

  private static function sanitize_candidate_history_items(array $items, array $allowed_fields): array {
    $out = [];
    foreach ($items as $row) {
      if (!is_array($row)) continue;
      $clean = [];
      foreach ($allowed_fields as $field) {
        $value = $row[$field] ?? '';
        if (in_array($field, ['start_date', 'end_date'], true)) {
          $value = preg_replace('/[^0-9\-]/', '', is_scalar($value) ? (string)$value : '');
        } elseif (in_array($field, ['responsibilities', 'details'], true)) {
          $value = sanitize_textarea_field((string)$value);
        } else {
          $value = sanitize_text_field((string)$value);
        }
        if ($value !== '') $clean[$field] = $value;
      }
      if (!empty($clean)) $out[] = $clean;
    }
    return $out;
  }

  public static function candidate_extended_profile_for_user(int $user_id): array {
    if ($user_id <= 0) return [];
    $out = [];

    $work = get_user_meta($user_id, self::USER_META_WORK_HISTORY, true);
    if (is_array($work) && !empty($work)) {
      $out['custom:work_history'] = self::sanitize_candidate_history_items($work, ['job_title', 'company', 'start_date', 'end_date', 'responsibilities']);
    }

    $education = get_user_meta($user_id, self::USER_META_EDUCATION_HISTORY, true);
    if (is_array($education) && !empty($education)) {
      $out['custom:education_history'] = self::sanitize_candidate_history_items($education, ['school', 'degree', 'field', 'start_date', 'end_date', 'details']);
    }

    $resume_id = (int)get_user_meta($user_id, self::USER_META_RESUME_ATTACHMENT_ID, true);
    if ($resume_id > 0) $out['custom:resume_attachment_id'] = $resume_id;

    $cover_id = (int)get_user_meta($user_id, self::USER_META_COVER_LETTER_ATTACHMENT_ID, true);
    if ($cover_id > 0) $out['custom:cover_letter_attachment_id'] = $cover_id;

    return $out;
  }

  public static function store_candidate_extended_profile_for_user(int $user_id, array $profile): void {
    if ($user_id <= 0) return;

    if (array_key_exists('custom:work_history', $profile)) {
      $work = self::sanitize_candidate_history_items((array)$profile['custom:work_history'], ['job_title', 'company', 'start_date', 'end_date', 'responsibilities']);
      if (!empty($work)) update_user_meta($user_id, self::USER_META_WORK_HISTORY, $work);
      else delete_user_meta($user_id, self::USER_META_WORK_HISTORY);
    }

    if (array_key_exists('custom:education_history', $profile)) {
      $education = self::sanitize_candidate_history_items((array)$profile['custom:education_history'], ['school', 'degree', 'field', 'start_date', 'end_date', 'details']);
      if (!empty($education)) update_user_meta($user_id, self::USER_META_EDUCATION_HISTORY, $education);
      else delete_user_meta($user_id, self::USER_META_EDUCATION_HISTORY);
    }

    if (array_key_exists('custom:resume_attachment_id', $profile)) {
      $resume_id = max(0, (int)$profile['custom:resume_attachment_id']);
      if ($resume_id > 0) update_user_meta($user_id, self::USER_META_RESUME_ATTACHMENT_ID, $resume_id);
      else delete_user_meta($user_id, self::USER_META_RESUME_ATTACHMENT_ID);
    }

    if (array_key_exists('custom:cover_letter_attachment_id', $profile)) {
      $cover_id = max(0, (int)$profile['custom:cover_letter_attachment_id']);
      if ($cover_id > 0) update_user_meta($user_id, self::USER_META_COVER_LETTER_ATTACHMENT_ID, $cover_id);
      else delete_user_meta($user_id, self::USER_META_COVER_LETTER_ATTACHMENT_ID);
    }
  }

  public static function preferred_job_title_label(int $user_id): string {
    if ($user_id <= 0) return '';

    if (class_exists('EUM_Fields')) {
      $all = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      $custom = (string)($all['job_title'] ?? '');
      $label = self::job_title_label_from_value($custom);
      if ($label !== '') return $label;
    }

    if (!taxonomy_exists('eum_job_path')) return '';
    $terms = wp_get_object_terms($user_id, 'eum_job_path');
    if (is_wp_error($terms) || empty($terms)) return '';
    $term = $terms[0];
    return isset($term->name) ? sanitize_text_field((string)$term->name) : '';
  }

  public static function job_title_token_from_id(int $title_id): string {
    return class_exists('EUM_Job_Titles') ? EUM_Job_Titles::token_from_id($title_id) : ($title_id > 0 ? 'eum_job_title:' . $title_id : '');
  }

  public static function job_title_post_id_from_value($value): int {
    if (class_exists('EUM_Job_Titles')) return EUM_Job_Titles::id_from_token($value);
    $value = trim((string)$value);
    if (preg_match('/^eum_job_title:(\d+)$/', $value, $m)) return (int)$m[1];
    return 0;
  }

  public static function job_title_label_from_value($value): string {
    $value = is_scalar($value) ? (string)$value : '';
    $title_id = self::job_title_post_id_from_value($value);
    if ($title_id > 0 && class_exists('EUM_Job_Titles')) {
      $label = EUM_Job_Titles::title_label($title_id);
      if ($label !== '') return $label;
    }
    return sanitize_text_field($value);
  }

  public static function normalize_job_title_value($value, int $job_term_id = 0): string {
    $value = is_scalar($value) ? trim((string)$value) : '';
    if ($value === '') return '';

    $title_id = self::job_title_post_id_from_value($value);
    if ($title_id <= 0 && ctype_digit($value)) $title_id = (int)$value;

    if ($title_id > 0) {
      if ($job_term_id > 0 && class_exists('EUM_Job_Titles') && !EUM_Job_Titles::is_title_allowed_for_job_term($title_id, $job_term_id)) {
        return '';
      }
      return self::job_title_token_from_id($title_id);
    }

    return sanitize_text_field($value);
  }

  private static function job_title_dom_ids_from_name(string $name): array {
    $hash = substr(md5($name), 0, 10);
    return [
      'hidden' => 'eum-job-title-hidden-' . $hash,
      'display' => 'eum-job-title-display-' . $hash,
    ];
  }

  public static function job_title_options_for_job_term(int $job_term_id): array {
    $job_term_id = (int)$job_term_id;
    if ($job_term_id <= 0) return [];

    $options = class_exists('EUM_Job_Titles')
      ? EUM_Job_Titles::title_labels_for_job_term($job_term_id)
      : [];

    if (empty($options) && class_exists('EUM_Taxonomies') && method_exists('EUM_Taxonomies', 'effective_job_title_options_for_term')) {
      $options = EUM_Taxonomies::effective_job_title_options_for_term($job_term_id);
    }

    $clean = [];
    foreach ((array)$options as $label) {
      $label = sanitize_text_field((string)$label);
      if ($label !== '') $clean[] = $label;
    }
    return array_values(array_unique($clean));
  }

  public static function job_title_options_for_user(int $user_id, array $overrides = []): array {
    $job_term_id = 0;
    if (isset($overrides['tax:eum_job_path'])) $job_term_id = (int)$overrides['tax:eum_job_path'];
    elseif (isset($overrides['eum_job_path'])) $job_term_id = (int)$overrides['eum_job_path'];
    if ($job_term_id <= 0 && taxonomy_exists('eum_job_path')) {
      $assigned = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
      if (!is_wp_error($assigned) && !empty($assigned)) $job_term_id = (int)$assigned[0];
    }
    return self::job_title_options_for_job_term($job_term_id);
  }

  public static function normalize_worker_type($value): string {
    $value = sanitize_key(is_scalar($value) ? (string)$value : '');
    return in_array($value, ['regular_employee', 'independent_contractor', 'freelancer', 'external_user', 'partner', 'vendor'], true) ? $value : '';
  }

  public static function worker_type_options(): array {
    return [
      '' => 'Employee / Standard',
      'regular_employee' => 'Regular Employee',
      'independent_contractor' => 'Independent Contractor',
      'freelancer' => 'Freelance',
      'external_user' => 'External User',
      'partner' => 'Partner',
      'vendor' => 'Vendor',
    ];
  }

  public static function worker_type_label(string $value): string {
    $value = self::normalize_worker_type($value);
    $options = self::worker_type_options();
    return (string)($options[$value] ?? '');
  }

  public static function worker_type_for_user(int $user_id): string {
    if ($user_id <= 0 || !class_exists('EUM_Fields')) return '';
    $all = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    return self::normalize_worker_type((string)($all['worker_type'] ?? ''));
  }

  public static function worker_type_badge_label(int $user_id): string {
    return self::worker_type_label(self::worker_type_for_user($user_id));
  }

  public static function render_worker_type_badge_html(int $user_id, string $class = ''): string {
    $label = self::worker_type_badge_label($user_id);
    if ($label === '') return '';
    $classes = trim('eum-worker-type-badge ' . $class);
    return '<span class="' . esc_attr($classes) . '" style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:600;">' . esc_html($label) . '</span>';
  }

  public static function render_worker_type_select_html(string $name, string $current = ''): string {
    $current = self::normalize_worker_type($current);
    $html = '<select name="' . esc_attr($name) . '" style="min-width:260px;max-width:420px;">';
    foreach (self::worker_type_options() as $value => $label) {
      $html .= '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select>';
    return $html;
  }

  public static function render_job_title_select_html(string $name, string $current = '', int $job_term_id = 0, string $placeholder = 'Selected in Job Path', bool $include_hidden = true): string {
    $current = self::normalize_job_title_value($current, $job_term_id);
    $display = self::job_title_label_from_value($current);
    $ids = self::job_title_dom_ids_from_name($name);
    $html = '';
    if ($include_hidden) {
      $html .= '<input type="hidden" id="' . esc_attr($ids['hidden']) . '" name="' . esc_attr($name) . '" value="' . esc_attr($current) . '">';
    }
    $html .= '<input type="text" id="' . esc_attr($ids['display']) . '" value="' . esc_attr($display) . '" placeholder="' . esc_attr($placeholder) . '" readonly disabled style="width:100%;max-width:520px;background:#f6f7f7;color:#50575e;border-color:#dcdcde;">';
    return $html;
  }

  public static function manager_user_name(int $user_id): string {
    $u = get_user_by('id', $user_id);
    if (!$u) return '';
    return (string)($u->display_name ?: $u->user_login);
  }

  public static function next_sunday_midnight(): string {
    $ts = current_time('timestamp');
    $weekday = (int) gmdate('w', $ts + (int)(get_option('gmt_offset', 0) * HOUR_IN_SECONDS));
    $days_until = (7 - $weekday) % 7;
    if ($days_until === 0) $days_until = 7;

    $date = date_i18n('Y-m-d', strtotime('+' . $days_until . ' day', $ts));
    return $date . ' 00:00:00';
  }

  public static function transfer_protocol_min_effective_at(): string {
    $ts = current_time('timestamp');
    $weekday = (int) gmdate('w', $ts + (int)(get_option('gmt_offset', 0) * HOUR_IN_SECONDS));
    $days_until = ($weekday === 0) ? 7 : (((7 - $weekday) % 7) + 7);
    $date = date_i18n('Y-m-d', strtotime('+' . $days_until . ' day', $ts));
    return $date . ' 00:00:00';
  }

  public static function transfer_protocol_standard_max_effective_at(): string {
    return date_i18n('Y-m-d H:i:s', current_time('timestamp') + (6 * WEEK_IN_SECONDS));
  }

  public static function current_manager_user_id(int $user_id): int {
    if ($user_id <= 0 || !class_exists('EUM_Personnel')) return 0;
    return EUM_Personnel::effective_manager_user_id($user_id);
  }

  public static function transfer_protocol_context(int $target_user_id, array $after = [], int $change_id = 0): array {
    $current_manager_user_id = self::current_manager_user_id($target_user_id);
    $existing_flag = $change_id > 0 && !empty(get_post_meta($change_id, self::META_TRANSFER_PROTOCOL, true));
    $old_manager_user_id = $change_id > 0 ? (int)get_post_meta($change_id, self::META_TRANSFER_OLD_MANAGER, true) : 0;
    $new_manager_user_id = $change_id > 0 ? (int)get_post_meta($change_id, self::META_TRANSFER_NEW_MANAGER, true) : 0;

    if ($old_manager_user_id <= 0) $old_manager_user_id = $current_manager_user_id;
    if (array_key_exists('core:eum_manager_user_id', $after)) $new_manager_user_id = max(0, (int)$after['core:eum_manager_user_id']);

    $is_transfer = $existing_flag || ($new_manager_user_id > 0 && $new_manager_user_id !== $current_manager_user_id);

    $proposed_max_at = $change_id > 0 ? self::parse_datetime_local((string)get_post_meta($change_id, self::META_TRANSFER_PROPOSED_MAX_AT, true)) : '';
    $current_effective_at = $change_id > 0 ? self::parse_datetime_local((string)get_post_meta($change_id, self::META_EFFECTIVE_AT, true)) : '';
    if ($current_effective_at !== '' && ($proposed_max_at === '' || $current_effective_at > $proposed_max_at)) $proposed_max_at = $current_effective_at;

    return [
      'is_transfer' => $is_transfer,
      'current_manager_user_id' => $current_manager_user_id,
      'old_manager_user_id' => $old_manager_user_id,
      'new_manager_user_id' => $new_manager_user_id,
      'proposed_max_at' => $proposed_max_at,
    ];
  }

  public static function normalize_transfer_effective_at_for_save(int $actor_user_id, int $target_user_id, string $effective_at, array $after = [], int $change_id = 0): array {
    $effective_at = self::parse_datetime_local($effective_at);
    $context = self::transfer_protocol_context($target_user_id, $after, $change_id);
    if (empty($context['is_transfer'])) {
      return [
        'is_transfer' => false,
        'effective_at' => $effective_at,
        'proposed_max_at' => $context['proposed_max_at'] ?? '',
        'error' => '',
        'min_effective_at' => '',
        'standard_max_effective_at' => '',
        'context' => $context,
      ];
    }

    $min_effective_at = self::transfer_protocol_min_effective_at();
    $standard_max_effective_at = self::transfer_protocol_standard_max_effective_at();
    $proposed_max_at = (string)($context['proposed_max_at'] ?? '');

    if ($effective_at === '') $effective_at = $min_effective_at;
    if ($effective_at < $min_effective_at) {
      return [
        'is_transfer' => true,
        'effective_at' => $effective_at,
        'proposed_max_at' => $proposed_max_at,
        'error' => 'Transfers must be scheduled for at least ' . $min_effective_at . '.',
        'min_effective_at' => $min_effective_at,
        'standard_max_effective_at' => $standard_max_effective_at,
        'context' => $context,
      ];
    }

    $is_old_manager = !empty($context['old_manager_user_id']) && (int)$context['old_manager_user_id'] === $actor_user_id;
    if ($effective_at > $standard_max_effective_at) {
      if ($is_old_manager) {
        $cap = ($proposed_max_at !== '' && $proposed_max_at > $standard_max_effective_at) ? $proposed_max_at : $standard_max_effective_at;
        if ($effective_at > $cap) {
          return [
            'is_transfer' => true,
            'effective_at' => $effective_at,
            'proposed_max_at' => $proposed_max_at,
            'error' => 'The current manager can schedule this transfer no later than ' . $cap . ' unless the new manager proposes a later date first.',
            'min_effective_at' => $min_effective_at,
            'standard_max_effective_at' => $standard_max_effective_at,
            'context' => $context,
          ];
        }
      } else {
        if ($proposed_max_at === '' || $effective_at > $proposed_max_at) $proposed_max_at = $effective_at;
      }
    }

    if ($proposed_max_at !== '' && $proposed_max_at < $min_effective_at) $proposed_max_at = $min_effective_at;

    return [
      'is_transfer' => true,
      'effective_at' => $effective_at,
      'proposed_max_at' => $proposed_max_at,
      'error' => '',
      'min_effective_at' => $min_effective_at,
      'standard_max_effective_at' => $standard_max_effective_at,
      'context' => $context,
    ];
  }

  public static function parse_datetime_local(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $raw = str_replace('T', ' ', $raw);
    $raw = preg_replace('/[^0-9\-: ]/', '', $raw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $raw)) return '';
    if (strlen($raw) === 16) $raw .= ':00';
    return $raw;
  }

  public static function get_field_raw_value(int $user_id, string $field_id) {
    $catalog = self::field_catalog();
    if (!isset($catalog[$field_id])) return null;
    $def = $catalog[$field_id];

    if (($def['type'] ?? '') === 'public_meta_override' && class_exists('EUM_Public_Meta')) {
      return EUM_Public_Meta::profile_override_state($user_id, (string)($def['meta_key'] ?? ''));
    }

    if (str_starts_with($field_id, 'core:')) {
      $key = substr($field_id, 5);
      $u = get_user_by('id', $user_id);
      if (!$u) return null;
      switch ($key) {
        case 'first_name':
        case 'last_name':
          return (string)get_user_meta($user_id, $key, true);
        case 'display_name':
          return (string)$u->display_name;
        case 'eum_profile_image_url':
          return (string)get_user_meta($user_id, self::META_PROFILE_IMAGE_URL, true);
        case 'eum_profile_image_color':
          return (string)get_user_meta($user_id, self::META_PROFILE_IMAGE_COLOR, true);
        case 'user_email':
          return (string)$u->user_email;
        case 'user_pass':
          return '';
        case 'eum_manager_user_id':
          return class_exists('EUM_Personnel') ? EUM_Personnel::effective_manager_user_id($user_id) : 0;
        case 'job_company':
          return class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::effective_division_for_user($user_id) : (string)get_user_meta($user_id, 'job_company', true);
      }
    }

    if (str_starts_with($field_id, 'tax:')) {
      $tax = (string)($def['taxonomy'] ?? '');
      if ($tax === '') return null;
      $assigned = wp_get_object_terms($user_id, $tax, ['fields' => 'ids']);
      if (is_wp_error($assigned)) return [];
      if (($def['type'] ?? '') === 'taxonomy_single') {
        return empty($assigned) ? 0 : (int)$assigned[0];
      }
      return array_values(array_map('intval', (array)$assigned));
    }

    if (str_starts_with($field_id, 'custom:')) {
      $custom_key = (string)($def['custom_key'] ?? '');
      $all = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      return (string)($all[$custom_key] ?? '');
    }

    return null;
  }

  public static function get_field_display_value(int $user_id, string $field_id) {
    $catalog = self::field_catalog();
    if (!isset($catalog[$field_id])) return '';
    $def = $catalog[$field_id];
    $raw = self::get_field_raw_value($user_id, $field_id);

    if (($def['type'] ?? '') === 'public_meta_override') {
      $key = (string)($def['meta_key'] ?? '');
      return class_exists('EUM_Public_Meta') ? (string)(EUM_Public_Meta::values_for_user($user_id)[$key] ?? '') : '';
    }

    if ($field_id === 'core:eum_manager_user_id') {
      return $raw ? self::manager_user_name((int)$raw) : '';
    }
    if ($field_id === 'core:job_company') {
      return class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::division_label((string)$raw) : (string)$raw;
    }

    if (($def['type'] ?? '') === 'taxonomy_single') {
      $tid = (int)$raw;
      if ($tid <= 0) return '';
      $term = get_term($tid, (string)$def['taxonomy']);
      if (!$term || is_wp_error($term)) return '';
      if ((string)$def['taxonomy'] === 'eum_job_path') {
        return self::term_full_path((string)$def['taxonomy'], $tid, ' / ');
      }
      return self::term_full_path((string)$def['taxonomy'], $tid, ' / ');
    }

    if (($def['type'] ?? '') === 'taxonomy_multi') {
      $labels = [];
      foreach ((array)$raw as $tid) {
        $term = get_term((int)$tid, (string)$def['taxonomy']);
        if ($term && !is_wp_error($term)) $labels[] = $term->name;
      }
      return implode(', ', $labels);
    }

    if ($field_id === 'custom:job_title') {
      return self::job_title_label_from_value((string)$raw);
    }
    if ($field_id === 'custom:worker_type') {
      return self::worker_type_label((string)$raw);
    }

    return is_array($raw) ? wp_json_encode($raw) : (string)$raw;
  }

  public static function term_full_path(string $tax, int $term_id, string $sep = ' / '): string {
    $term = get_term($term_id, $tax);
    if (!$term || is_wp_error($term)) return '';

    $ancestors = array_reverse(get_ancestors($term_id, $tax));
    $parts = [];
    foreach ($ancestors as $ancestor_id) {
      $ancestor = get_term($ancestor_id, $tax);
      if ($ancestor && !is_wp_error($ancestor)) $parts[] = $ancestor->name;
    }
    $parts[] = $term->name;
    return implode($sep, $parts);
  }

  public static function resolve_target_user_from_request(array $atts = [], bool $fallback_current = true): int {
    $settings = self::get_settings();
    $query_var = sanitize_key((string)($atts['query_var'] ?? $settings['query_var']));

    if (class_exists('EUM_Workforce_Lifecycle')) {
      $resolved = EUM_Workforce_Lifecycle::resolve_user_from_request($atts, $query_var, $fallback_current);
      if ($resolved > 0) return $resolved;
    }

    $raw = '';
    if (!empty($atts['user_id'])) {
      $raw = (string)$atts['user_id'];
    } elseif (!empty($atts['user'])) {
      $raw = (string)$atts['user'];
    } elseif ($query_var !== '' && isset($_GET[$query_var])) {
      $raw = (string)$_GET[$query_var];
    }

    $raw = trim($raw);
    if ($raw === '' && $fallback_current) {
      return (int)get_current_user_id();
    }

    if ($raw === '') return 0;
    if (ctype_digit($raw)) return max(0, (int)$raw);

    $u = get_user_by('login', sanitize_user($raw));
    if (!$u) $u = get_user_by('email', sanitize_email($raw));
    return $u ? (int)$u->ID : 0;
  }

  public static function can_edit_target(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if ($actor_user_id === $target_user_id) return true;
    if (self::is_manager_of_target($actor_user_id, $target_user_id)) return true;
    return self::is_pending_manager_for_target($actor_user_id, $target_user_id);
  }

  public static function is_manager_of_target(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
    if (!class_exists('EUM_Manager_IDs')) return false;
    return EUM_Manager_IDs::user_is_manager_of_user($actor_user_id, $target_user_id);
  }

  public static function manager_edit_fields_with_approval(): array {
    $settings = self::get_settings();
    $catalog = self::field_catalog();
    return array_values(array_filter(array_map('strval', (array)($settings['manager_edit_fields'] ?? [])), fn($id) => isset($catalog[$id])));
  }

  public static function manager_edit_fields_without_approval(): array {
    $settings = self::get_settings();
    $catalog = self::field_catalog();
    return array_values(array_filter(array_map('strval', (array)($settings['manager_edit_fields_without_approval'] ?? [])), fn($id) => isset($catalog[$id])));
  }

  public static function manager_editable_field_ids(): array {
    return array_values(array_unique(array_merge(self::manager_edit_fields_with_approval(), self::manager_edit_fields_without_approval())));
  }

  public static function manager_change_requires_approval(int $actor_user_id, int $target_user_id, array $changed_field_ids): bool {
    if (EUM_Access::is_it_or_hr($actor_user_id)) return false;
    if ($actor_user_id === $target_user_id) return false;
    if (!(self::is_manager_of_target($actor_user_id, $target_user_id) || self::is_pending_manager_for_target($actor_user_id, $target_user_id))) return true;

    $changed = array_values(array_unique(array_filter(array_map('strval', $changed_field_ids))));
    if (empty($changed)) return true;

    $review_fields = array_values(array_diff($changed, ['core:eum_manager_user_id']));
    if (empty($review_fields)) return false;

    $without = self::manager_edit_fields_without_approval();
    foreach ($review_fields as $field_id) {
      if (!in_array($field_id, $without, true)) return true;
    }
    return false;
  }

  public static function editable_fields_for_actor(int $actor_user_id, int $target_user_id): array {
    $settings = self::get_settings();
    $catalog = self::field_catalog();

    if (EUM_Access::is_it_user($actor_user_id)) {
      return self::strip_locked_profile_fields(array_keys($catalog));
    }

    if (EUM_Access::is_hr_user($actor_user_id)) {
      return self::strip_locked_profile_fields(array_values(array_filter(array_keys($catalog), fn($id) => !in_array($id, ['core:user_email', 'core:user_pass'], true))));
    }

    if ($actor_user_id === $target_user_id) {
      return self::strip_locked_profile_fields(array_values(array_filter($settings['self_edit_fields'], fn($id) => isset($catalog[$id]) && !in_array($id, ['core:user_email', 'core:user_pass', 'core:job_company'], true))));
    }

    if (self::is_manager_of_target($actor_user_id, $target_user_id) || self::is_pending_manager_for_target($actor_user_id, $target_user_id)) {
      return self::strip_locked_profile_fields(array_values(array_filter(self::manager_editable_field_ids(), fn($id) => isset($catalog[$id]) && !in_array($id, ['core:user_email', 'core:user_pass', 'core:job_company'], true))));
    }

    return [];
  }

  public static function editable_fields_for_new_hire_actor(int $actor_user_id): array {
    $catalog = self::field_catalog();
    $excluded = ['core:first_name', 'core:last_name', 'core:display_name', 'core:user_email', 'core:user_pass', 'tax:eum_job_path', 'custom:employee_id'];

    if (EUM_Access::is_it_user($actor_user_id)) {
      return array_values(array_filter(array_keys($catalog), fn($id) => !in_array($id, $excluded, true)));
    }

    if (EUM_Access::is_hr_user($actor_user_id)) {
      return array_values(array_filter(array_keys($catalog), fn($id) => !in_array($id, $excluded, true)));
    }

    return array_values(array_filter(self::manager_editable_field_ids(), fn($id) => isset($catalog[$id]) && !in_array($id, array_merge($excluded, ['core:job_company']), true)));
  }

  public static function approver_ids_for_actor(int $actor_user_id, int $target_user_id, array $after = [], int $change_id = 0): array {
    if (EUM_Access::is_it_or_hr($actor_user_id)) return [];

    $transfer = self::transfer_protocol_context($target_user_id, $after, $change_id);
    if (!empty($transfer['is_transfer']) && !empty($transfer['new_manager_user_id'])) {
      $exclude = [$actor_user_id, $target_user_id, (int)($transfer['old_manager_user_id'] ?? 0), (int)($transfer['new_manager_user_id'] ?? 0)];
      $approvers = self::manager_user_ids_for_subject((int)$transfer['new_manager_user_id'], $exclude);
      if (!empty($approvers)) {
        return array_values(array_unique(array_filter(array_map('intval', $approvers), fn($uid) => $uid > 0 && $uid !== $actor_user_id)));
      }
    }

    $subject_user_id = ($actor_user_id !== $target_user_id && self::is_manager_of_target($actor_user_id, $target_user_id))
      ? $actor_user_id
      : $target_user_id;

    $approvers = self::manager_user_ids_for_subject($subject_user_id, [$actor_user_id, $target_user_id]);
    if (empty($approvers) && $subject_user_id !== $target_user_id) {
      $approvers = self::manager_user_ids_for_subject($target_user_id, [$actor_user_id, $target_user_id]);
    }

    return array_values(array_unique(array_filter(array_map('intval', $approvers), fn($uid) => $uid > 0 && $uid !== $actor_user_id)));
  }

  private static function manager_user_ids_for_subject(int $subject_user_id, array $exclude = []): array {
    $exclude = array_values(array_filter(array_map('intval', $exclude), fn($uid) => $uid > 0));
    $manager_users = class_exists('EUM_Manager_IDs')
      ? EUM_Manager_IDs::manager_user_ids_for_subject_user($subject_user_id)
      : [];

    return array_values(array_unique(array_filter(array_map('intval', $manager_users), fn($uid) => $uid > 0 && !in_array($uid, $exclude, true))));
  }

  private static function manager_root_job_terms(int $actor_user_id): array {
    if (!class_exists('EUM_Manager_IDs')) return [];
    $posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'fields' => 'ids',
    ]);

    $term_ids = [];
    foreach ((array)$posts as $post_id) {
      $manager_users = (array)get_post_meta((int)$post_id, EUM_Manager_IDs::META_MANAGER_USERS, true);
      $manager_users = array_values(array_filter(array_map('intval', $manager_users)));
      if (!in_array($actor_user_id, $manager_users, true)) continue;
      $job_term_id = (int)get_post_meta((int)$post_id, EUM_Manager_IDs::META_JOB_PATH_TERM, true);
      if ($job_term_id > 0) $term_ids[] = $job_term_id;
    }
    return array_values(array_unique($term_ids));
  }

  private static function actor_can_manage_job_term(int $actor_user_id, int $term_id): bool {
    if ($term_id <= 0) return true;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    foreach (self::manager_root_job_terms($actor_user_id) as $root_term_id) {
      if ($term_id === $root_term_id || term_is_ancestor_of($root_term_id, $term_id, 'eum_job_path')) return true;
    }
    return false;
  }

  public static function is_pending_manager_for_target(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
    $items = get_posts([
      'post_type' => self::CPT_CHANGE,
      'post_status' => 'publish',
      'numberposts' => 20,
      'meta_query' => [
        'relation' => 'AND',
        ['key' => self::META_TARGET_USER, 'value' => $target_user_id],
        ['key' => self::META_STATUS, 'value' => ['pending', 'scheduled'], 'compare' => 'IN'],
      ],
    ]);
    foreach ($items as $item) {
      $after = get_post_meta((int)$item->ID, self::META_AFTER, true);
      $after = is_array($after) ? $after : [];
      if ((int)($after['core:eum_manager_user_id'] ?? 0) === $actor_user_id) return true;
      if (!empty($after['tax:eum_job_path']) && self::actor_can_manage_job_term($actor_user_id, (int)$after['tax:eum_job_path'])) return true;
    }
    return false;
  }

  public static function is_manager_of_anything(int $user_id): bool {
    if ($user_id <= 0 || !class_exists('EUM_Manager_IDs')) return false;

    $posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'fields' => 'ids',
    ]);

    foreach ((array)$posts as $post_id) {
      $manager_users = (array)get_post_meta((int)$post_id, EUM_Manager_IDs::META_MANAGER_USERS, true);
      $manager_users = array_map('intval', $manager_users);
      if (in_array($user_id, $manager_users, true)) return true;
    }

    return false;
  }

  public static function actor_role_label(int $actor_user_id, int $target_user_id): string {
    if (EUM_Access::is_it_user($actor_user_id)) return 'IT';
    if (EUM_Access::is_hr_user($actor_user_id)) return 'HR';
    if ($actor_user_id === $target_user_id) return 'Self';
    if (self::is_manager_of_target($actor_user_id, $target_user_id) || self::is_pending_manager_for_target($actor_user_id, $target_user_id)) return 'Manager';
    return 'User';
  }

  private static function employment_before_for_user(int $user_id): array {
    if (!class_exists('EUM_Personnel')) return [];
    return [
      'system:eum_terminated' => EUM_Personnel::is_terminated($user_id) ? 1 : 0,
      'system:eum_rehirable' => EUM_Personnel::is_rehirable($user_id) ? 1 : 0,
      'system:eum_terminated_at' => EUM_Personnel::terminated_at($user_id),
    ];
  }

  private static function employment_status_label(int $user_id): string {
    if (!class_exists('EUM_Personnel') || !EUM_Personnel::is_terminated($user_id)) return 'Active';
    if (class_exists('EUM_Workforce_Lifecycle')) return EUM_Workforce_Lifecycle::employment_status_label_for_user($user_id, (int)get_current_user_id());
    return EUM_Personnel::is_rehirable($user_id) ? 'Terminated · Rehirable' : 'Terminated · Non-rehireable';
  }

  private static function can_manage_employment_action(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0 || $actor_user_id === $target_user_id) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    return self::is_manager_of_target($actor_user_id, $target_user_id) || self::is_pending_manager_for_target($actor_user_id, $target_user_id);
  }

  private static function can_reactivate_target(int $actor_user_id, int $target_user_id): bool {
    if (!class_exists('EUM_Personnel') || !EUM_Personnel::is_terminated($target_user_id)) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return self::can_manage_employment_action($actor_user_id, $target_user_id);
    return self::can_manage_employment_action($actor_user_id, $target_user_id) && EUM_Personnel::is_rehirable($target_user_id);
  }

  private static function can_manage_rehire_eligibility_action(int $actor_user_id, int $target_user_id): bool {
    if (!class_exists('EUM_Personnel') || !EUM_Personnel::is_terminated($target_user_id)) return false;
    if ($actor_user_id <= 0 || $target_user_id <= 0 || $actor_user_id === $target_user_id) return false;
    if (EUM_Access::is_hr_user($actor_user_id)) return self::can_manage_employment_action($actor_user_id, $target_user_id);
    if (EUM_Access::is_it_user($actor_user_id)) return self::can_manage_employment_action($actor_user_id, $target_user_id);
    return false;
  }

  private static function can_mark_non_rehirable(int $actor_user_id): bool {
    return $actor_user_id > 0 && EUM_Access::is_hr_user($actor_user_id);
  }

  private static function log_field_label(string $field_id): string {
    $special = [
      'system:eum_terminated' => 'Employment Status',
      'system:eum_rehirable' => 'Rehire Eligibility',
      'system:eum_terminated_at' => 'Termination Date/Time',
      'system:eum_compensation_type' => 'Compensation Type',
      'system:eum_compensation_amount' => 'Compensation Amount',
      'system:eum_compensation_unpaid_label' => 'Unpaid Label / Reason',
    ];
    if (isset($special[$field_id])) return $special[$field_id];
    if (isset(self::field_catalog()[$field_id]['label'])) return (string)self::field_catalog()[$field_id]['label'];
    if (str_starts_with($field_id, 'custom:')) {
      return ucwords(str_replace(['_', '-'], ' ', substr($field_id, 7)));
    }
    return ucwords(str_replace(['_', '-'], ' ', $field_id));
  }

  public static function insert_applied_change_log(int $target_user_id, int $actor_user_id, array $before, array $after, string $reason = 'Profile Update', int $manual = 0): int {
    $target_user_id = (int)$target_user_id;
    $actor_user_id = (int)$actor_user_id;
    if ($target_user_id <= 0 || empty($after)) return 0;

    $has_changes = false;

    foreach ($after as $field_id => $value) {
      $before_value = $before[$field_id] ?? null;
      if (is_array($before_value) || is_array($value)) {
        if (wp_json_encode($before_value) !== wp_json_encode($value)) { $has_changes = true; break; }
      } elseif ((string)$before_value !== (string)$value) {
        $has_changes = true; break;
      }
    }
    if (!$has_changes) return 0;

    $settings = self::get_settings();
    if (empty($settings['logging_enabled'])) return 0;

    $target_user = get_user_by('id', $target_user_id);
    $title = 'Profile Change - ' . ($target_user ? ($target_user->display_name ?: $target_user->user_login) : ('User #' . $target_user_id));
    $change_id = (int)wp_insert_post([
      'post_type' => self::CPT_CHANGE,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $actor_user_id > 0 ? $actor_user_id : $target_user_id,
    ]);
    if ($change_id <= 0) return 0;

    update_post_meta($change_id, self::META_TARGET_USER, $target_user_id);
    update_post_meta($change_id, self::META_ACTOR_USER, $actor_user_id > 0 ? $actor_user_id : $target_user_id);
    update_post_meta($change_id, self::META_BEFORE, $before);
    update_post_meta($change_id, self::META_AFTER, $after);
    update_post_meta($change_id, self::META_NOTE, sanitize_text_field($reason));
    update_post_meta($change_id, self::META_OCCURRED_AT, current_time('mysql'));
    update_post_meta($change_id, self::META_STATUS, 'applied');
    update_post_meta($change_id, self::META_MANUAL, $manual ? 1 : 0);
    return $change_id;
  }

  public function render_settings_page(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');

    $settings = self::get_settings();
    $catalog = self::field_catalog();

    echo '<div class="wrap"><h1>Profile Access</h1>';
    echo '<p>Configure public profiles, self-edit permissions, manager-edit permissions, logging, scheduling behavior, and available reasons.</p>';

    if (!empty($_GET['eum_msg'])) {
      echo '<div class="notice notice-success"><p>' . esc_html((string)$_GET['eum_msg']) . '</p></div>';
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_profile_settings_save">';
    wp_nonce_field('eum_profile_settings_save', 'eum_profile_settings_nonce');

    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Public Profiles</th><td>';
    echo '<label><input type="checkbox" name="public_profiles_enabled" value="1" ' . checked(!empty($settings['public_profiles_enabled']), true, false) . '> Enable public profile shortcode output</label>';
    echo '<p class="description">Use <code>[eum_public_profile]</code>. The user can be specified with the shortcode or the query parameter below.</p>';
    echo '</td></tr>';

    echo '<tr><th>Query Parameter</th><td>';
    echo '<input type="text" name="query_var" class="regular-text" value="' . esc_attr($settings['query_var']) . '">';
    echo '<p class="description">Example: <code>?' . esc_html($settings['query_var']) . '=123</code> or a user login.</p>';
    echo '</td></tr>';

    echo '<tr><th>Public Profile Fields</th><td>' . $this->render_field_multiselect('public_fields[]', $settings['public_fields'], $catalog) . '</td></tr>';
    echo '<tr><th>Self Editable Fields</th><td>' . $this->render_field_multiselect('self_edit_fields[]', $settings['self_edit_fields'], $catalog) . '<p class="description">Email and password remain IT-only even if selected here.</p></td></tr>';
    echo '<tr><th>Manager Editable Fields (With Approval)</th><td>' . $this->render_field_multiselect('manager_edit_fields[]', $settings['manager_edit_fields'], $catalog) . '<p class="description">These fields remain manager-editable but continue to require approval. Email and password remain IT-only. Job-title changes are still limited to branches that manager controls.</p></td></tr>';
    echo '<tr><th>Manager Editable Fields (Without Approval)</th><td>' . $this->render_field_multiselect('manager_edit_fields_without_approval[]', $settings['manager_edit_fields_without_approval'], $catalog) . '<p class="description">Managers can edit these fields and apply them without approval. If a change includes any field not listed here, the full request still requires approval.</p></td></tr>';

    echo '<tr><th>Change Logging</th><td>';
    echo '<label><input type="checkbox" name="logging_enabled" value="1" ' . checked(!empty($settings['logging_enabled']), true, false) . '> Keep change logs after edits are applied</label>';
    echo '</td></tr>';

    echo '<tr><th>Effective Date Rule</th><td>';
    echo '<label><input type="checkbox" name="force_next_sunday" value="1" ' . checked(!empty($settings['force_next_sunday']), true, false) . '> Default non-IT edits to the next Sunday at 12:00 AM</label>';
    echo '<p class="description">IT and HR can still apply immediately or schedule a future time from the front end.</p>';
    echo '</td></tr>';

    echo '<tr><th>Reason Options</th><td>';
    echo '<p class="description">These appear as the required Reason selector in the front-end editor. Each reason can be enabled for Self, Manager, HR, and IT request types.</p>';
    $reason_rows = array_values((array)$settings['reason_options']);
    $display_rows = max(8, count($reason_rows) + 2);
    echo '<table style="border-collapse:collapse;"><thead><tr><th style="text-align:left;padding:4px 8px 4px 0;">Reason</th><th style="padding:4px 8px;">Self</th><th style="padding:4px 8px;">Manager</th><th style="padding:4px 8px;">HR</th><th style="padding:4px 8px;">IT</th></tr></thead><tbody>';
    for ($i = 0; $i < $display_rows; $i++) {
      $row = $reason_rows[$i] ?? ['label' => '', 'roles' => []];
      $roles = array_map('sanitize_key', (array)($row['roles'] ?? []));
      echo '<tr>';
      echo '<td style="padding:4px 8px 4px 0;"><input type="text" class="regular-text" name="reason_label[' . $i . ']" value="' . esc_attr((string)($row['label'] ?? '')) . '"></td>';
      foreach (['self', 'manager', 'hr', 'it'] as $role_key) {
        echo '<td style="padding:4px 8px;text-align:center;"><input type="checkbox" name="reason_roles[' . $i . '][]" value="' . esc_attr($role_key) . '" ' . checked(in_array($role_key, $roles, true), true, false) . '></td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</td></tr>';

    echo '</tbody></table>';
    echo '<p><button class="button button-primary" type="submit">Save Settings</button></p>';
    echo '</form>';
    echo '</div>';
  }

  private function render_field_multiselect(string $name, array $selected, array $catalog): string {
    $selected = array_values(array_map('strval', $selected));
    $out = '<select name="' . esc_attr($name) . '" multiple size="12" style="min-width:420px;max-width:640px;">';

    $groups = [];
    foreach ($catalog as $id => $def) {
      $groups[(string)($def['group'] ?? 'Other')][$id] = $def;
    }

    foreach ($groups as $group => $items) {
      $out .= '<optgroup label="' . esc_attr($group) . '">';
      foreach ($items as $id => $def) {
        $sel = in_array($id, $selected, true) ? 'selected' : '';
        $out .= '<option value="' . esc_attr($id) . '" ' . $sel . '>' . esc_html($def['label'] ?? $id) . ' [' . esc_html($id) . ']</option>';
      }
      $out .= '</optgroup>';
    }

    $out .= '</select>';
    return $out;
  }

  public function handle_settings_save(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_profile_settings_nonce']) || !wp_verify_nonce($_POST['eum_profile_settings_nonce'], 'eum_profile_settings_save')) wp_die('Bad nonce.');

    $reason_labels = isset($_POST['reason_label']) && is_array($_POST['reason_label']) ? $_POST['reason_label'] : [];
    $reason_roles = isset($_POST['reason_roles']) && is_array($_POST['reason_roles']) ? $_POST['reason_roles'] : [];
    $reason_options = [];
    foreach ($reason_labels as $idx => $label) {
      $reason_options[] = [
        'label' => (string)$label,
        'roles' => isset($reason_roles[$idx]) ? (array)$reason_roles[$idx] : [],
      ];
    }

    self::set_settings([
      'public_profiles_enabled' => !empty($_POST['public_profiles_enabled']) ? 1 : 0,
      'public_fields' => (array)($_POST['public_fields'] ?? []),
      'self_edit_fields' => (array)($_POST['self_edit_fields'] ?? []),
      'manager_edit_fields' => (array)($_POST['manager_edit_fields'] ?? []),
      'manager_edit_fields_without_approval' => (array)($_POST['manager_edit_fields_without_approval'] ?? []),
      'logging_enabled' => !empty($_POST['logging_enabled']) ? 1 : 0,
      'force_next_sunday' => !empty($_POST['force_next_sunday']) ? 1 : 0,
      'query_var' => sanitize_key((string)($_POST['query_var'] ?? 'eum_user')),
      'reason_options' => $reason_options,
    ]);

    wp_safe_redirect(admin_url('admin.php?page=eum-profile-access&eum_msg=' . rawurlencode('Profile settings saved.')));
    exit;
  }

  public function shortcode_public_profile($atts): string {
    $settings = self::get_settings();
    if (empty($settings['public_profiles_enabled'])) {
      return '<div class="eum-public-profile"><p>Public profiles are disabled.</p></div>';
    }

    $atts = shortcode_atts([
      'user_id' => '',
      'user' => '',
      'query_var' => $settings['query_var'],
      'show_last_update' => '1',
    ], (array)$atts, 'eum_public_profile');

    $user_id = self::resolve_target_user_from_request($atts, false);
    if ($user_id <= 0) return '<div class="eum-public-profile"><p>No user selected.</p></div>';

    $user = get_user_by('id', $user_id);
    if (!$user) return '<div class="eum-public-profile"><p>User not found.</p></div>';

    $fields = array_values(array_filter($settings['public_fields'], fn($id) => isset(self::field_catalog()[$id])));

    ob_start();
    echo '<div class="eum-profile-card eum-profile-card--public" style="border:1px solid #dcdcde;padding:18px;border-radius:8px;max-width:760px;">';
    echo '<div class="eum-profile-card__hero">';
    echo self::profile_avatar_html($user_id, 92, 'eum-profile-card__avatar');
    echo '<div class="eum-profile-card__hero-copy">';
    echo '<h3 style="margin:0;">' . esc_html($user->display_name ?: $user->user_login) . '</h3>';
    $job_title = self::preferred_job_title_label($user_id);
    $company_division = self::get_field_display_value($user_id, 'core:job_company');
    if ($job_title !== '' || $company_division !== '') {
      echo '<p class="eum-profile-card__hero-meta" style="margin:6px 0 0;">';
      if ($job_title !== '') echo '<span>' . esc_html($job_title) . '</span>';
      if ($company_division !== '') echo '<span>' . esc_html($company_division) . '</span>';
      echo '</p>';
    }
    $worker_badge = self::render_worker_type_badge_html($user_id);
    if ($worker_badge !== '') echo '<p style="margin:10px 0 0;">' . $worker_badge . '</p>';
    if (class_exists('EUM_Workforce_Lifecycle')) {
      $ooo_badge = EUM_Workforce_Lifecycle::out_of_office_badge_html($user_id);
      if ($ooo_badge !== '') echo '<p style="margin:10px 0 0;">' . $ooo_badge . '</p>';
    }
    echo '</div></div>';
    echo '<dl style="display:grid;grid-template-columns:minmax(160px,220px) 1fr;gap:10px 16px;">';
    foreach ($fields as $field_id) {
      $label = self::field_catalog()[$field_id]['label'] ?? $field_id;
      $value = self::get_field_display_value($user_id, $field_id);
      if ($value === '') continue;
      echo '<dt style="font-weight:600;">' . esc_html($label) . '</dt>';
      echo '<dd style="margin:0;">' . esc_html($value) . '</dd>';
    }
    echo '</dl>';
    if ($atts['show_last_update'] === '1' || $atts['show_last_update'] === 'true') {
      echo $this->render_logs_modal($user_id, get_current_user_id(), 'public');
    }
    echo '</div>';
    return ob_get_clean();
  }

  public function shortcode_private_profile($atts): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-private-profile"><p>You must be logged in to access profile tools.</p></div>';
    }

    $settings = self::get_settings();
    $atts = shortcode_atts([
      'user_id' => '',
      'user' => '',
      'query_var' => $settings['query_var'],
      'title' => 'Work Profile',
      'show_history' => '1',
    ], (array)$atts, 'eum_private_profile');

    $actor_user_id = get_current_user_id();
    $target_user_id = self::resolve_target_user_from_request($atts, true);
    if ($target_user_id <= 0) $target_user_id = $actor_user_id;

    if (!self::can_edit_target($actor_user_id, $target_user_id)) {
      return '<div class="eum-private-profile"><p>You do not have permission to edit this profile.</p></div>';
    }

    $target_user = get_user_by('id', $target_user_id);
    if (!$target_user) return '<div class="eum-private-profile"><p>User not found.</p></div>';

    $editable_fields = self::editable_fields_for_actor($actor_user_id, $target_user_id);
    $catalog = self::field_catalog();
    $status_msg = isset($_GET['eum_profile_msg']) ? sanitize_text_field((string)$_GET['eum_profile_msg']) : '';
    $status_type = isset($_GET['eum_profile_type']) ? sanitize_key((string)$_GET['eum_profile_type']) : 'success';
    $reason_options = self::reason_options_for_actor($actor_user_id, $target_user_id);

    $edit_change_id = isset($_GET['eum_change_id']) ? intval($_GET['eum_change_id']) : 0;
    if ($edit_change_id <= 0 && $actor_user_id !== $target_user_id) {
      $edit_change_id = $this->find_editable_transfer_change_for_form($target_user_id, $actor_user_id);
    }
    $prefill = [];
    if ($edit_change_id > 0) {
      $prefill = $this->get_change_after_for_form($edit_change_id, $actor_user_id, $target_user_id);
    }

    ob_start();
    echo '<div class="eum-private-profile eum-private-profile__section" style="max-width:1100px;">';
    echo '<div class="eum-private-profile__hero">';
    echo self::profile_avatar_html($target_user_id, 108, 'eum-private-profile__hero-avatar');
    echo '<div class="eum-private-profile__hero-copy">';
    echo '<h3 style="margin:0;">' . esc_html((string)$atts['title']) . ': ' . esc_html($target_user->display_name ?: $target_user->user_login) . '</h3>';
    $hero_job = self::preferred_job_title_label($target_user_id);
    $hero_division = self::get_field_display_value($target_user_id, 'core:job_company');
    if ($hero_job !== '' || $hero_division !== '') {
      echo '<p class="eum-private-profile__hero-meta" style="margin:6px 0 0;">';
      if ($hero_job !== '') echo '<span>' . esc_html($hero_job) . '</span>';
      if ($hero_division !== '') echo '<span>' . esc_html($hero_division) . '</span>';
      echo '</p>';
    }
    $hero_worker_badge = self::render_worker_type_badge_html($target_user_id);
    if ($hero_worker_badge !== '') echo '<p style="margin:10px 0 0;">' . $hero_worker_badge . '</p>';
    if (class_exists('EUM_Workforce_Lifecycle')) {
      $hero_ooo_badge = EUM_Workforce_Lifecycle::out_of_office_badge_html($target_user_id);
      if ($hero_ooo_badge !== '') echo '<p style="margin:10px 0 0;">' . $hero_ooo_badge . '</p>';
    }
    echo '</div></div>';

    if ($status_msg !== '') {
      $bg = ($status_type === 'error') ? '#fbeaea' : '#eef7ee';
      $border = ($status_type === 'error') ? '#d63638' : '#46b450';
      echo '<div style="background:' . esc_attr($bg) . ';border-left:4px solid ' . esc_attr($border) . ';padding:10px 12px;margin-bottom:16px;">' . esc_html($status_msg) . '</div>';
    }

    echo '<div class="eum-private-profile__grid">';
    echo '<div class="eum-private-profile__section">';
    echo '<div class="eum-private-profile__current-header">';
    echo self::profile_avatar_html($target_user_id, 88, 'eum-private-profile__current-avatar');
    echo '<div><h4 style="margin-top:0;">Current Profile</h4></div>';
    echo '</div>';
    echo '<dl style="display:grid;grid-template-columns:minmax(150px,220px) 1fr;gap:8px 14px;">';
    foreach ($catalog as $field_id => $def) {
      $value = self::get_field_display_value($target_user_id, $field_id);
      if ($value === '') continue;
      echo '<dt style="font-weight:600;">' . esc_html($def['label'] ?? $field_id) . '</dt>';
      echo '<dd style="margin:0;">' . esc_html($value) . '</dd>';
    }
    echo '<dt style="font-weight:600;">Employment Status</dt>';
    echo '<dd style="margin:0;">' . esc_html(self::employment_status_label($target_user_id)) . '</dd>';
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($target_user_id) && EUM_Personnel::terminated_at($target_user_id) !== '') {
      echo '<dt style="font-weight:600;">Termination Date/Time</dt>';
      echo '<dd style="margin:0;">' . esc_html(EUM_Personnel::terminated_at($target_user_id)) . '</dd>';
    }
    if (class_exists('EUM_Timeclock')) {
      echo EUM_Timeclock::compensation_profile_rows_html($target_user_id);
    }
    echo '</dl>';
    if ($atts['show_history'] === '1' || $atts['show_history'] === 'true') {
      echo '<div style="margin-top:14px;">' . $this->render_logs_modal($target_user_id, $actor_user_id, 'private') . '</div>';
    }
    echo '</div>';

    echo '<div class="eum-private-profile__section">';
    echo '<h4 style="margin-top:0;">Edit Profile</h4>';
    if (empty($editable_fields)) {
      echo '<p>No editable fields are configured for your role.</p>';
    } else {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_profile_save">';
      echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
      echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
      if ($edit_change_id > 0) echo '<input type="hidden" name="change_id" value="' . (int)$edit_change_id . '">';
      wp_nonce_field('eum_profile_save_' . $target_user_id, 'eum_profile_nonce');

      foreach ($editable_fields as $field_id) {
        if (!isset($catalog[$field_id])) continue;
        $current_raw = array_key_exists($field_id, $prefill) ? $prefill[$field_id] : self::get_field_raw_value($target_user_id, $field_id);
        echo $this->render_frontend_input($field_id, $catalog[$field_id], $current_raw, $target_user_id, $actor_user_id);
      }

      $actor_role = self::actor_role_label($actor_user_id, $target_user_id);
      $transfer_preview = $edit_change_id > 0 ? self::transfer_protocol_context($target_user_id, $prefill, $edit_change_id) : ['is_transfer' => false];
      $requires_approval = !EUM_Access::is_it_or_hr($actor_user_id) && $actor_user_id !== $target_user_id;
      $approver_ids = $requires_approval ? self::approver_ids_for_actor($actor_user_id, $target_user_id, $prefill, $edit_change_id) : [];
      $default_effective = '';
      if (!empty($transfer_preview['is_transfer'])) {
        $existing_effective = $edit_change_id > 0 ? self::parse_datetime_local((string)get_post_meta($edit_change_id, self::META_EFFECTIVE_AT, true)) : '';
        $default_effective = $existing_effective !== '' ? $existing_effective : self::transfer_protocol_min_effective_at();
      } elseif (!EUM_Access::is_it_or_hr($actor_user_id) && $actor_user_id !== $target_user_id && !empty($settings['force_next_sunday'])) {
        $default_effective = self::next_sunday_midnight();
      }

      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Change reason</label>';
      echo '<select name="eum_change_reason" style="min-width:320px;max-width:520px;">';
      echo '<option value="">— Select a reason —</option>';
      foreach ($reason_options as $reason_label) {
        echo '<option value="' . esc_attr($reason_label) . '">' . esc_html($reason_label) . '</option>';
      }
      echo '</select></p>';

      echo '<p style="margin-bottom:8px;"><strong>Request Type:</strong> ' . esc_html($actor_role) . '</p>';
      if (!empty($transfer_preview['is_transfer'])) {
        $old_manager_label = self::manager_user_name((int)($transfer_preview['old_manager_user_id'] ?? 0));
        $new_manager_label = self::manager_user_name((int)($transfer_preview['new_manager_user_id'] ?? 0));
        echo '<p class="description">This transfer stays pending until the scheduled date. ' . ($new_manager_label !== '' ? esc_html($new_manager_label) . ' can keep editing the staged change before it takes effect. ' : '') . ($old_manager_label !== '' ? esc_html($old_manager_label) . ' has final say on the effective date.' : '') . '</p>';
      } elseif ($actor_user_id !== $target_user_id && !EUM_Access::is_it_or_hr($actor_user_id)) {
        echo '<p class="description">Manager fields marked <em>without approval</em> apply immediately. Fields marked <em>with approval</em> continue to route through approval.</p>';
      }
      if ($requires_approval) {
        if (!empty($approver_ids)) {
          $labels = array_map([__CLASS__, 'manager_user_name'], $approver_ids);
          echo '<p class="description">This request will wait for approval from: ' . esc_html(implode(', ', array_filter($labels))) . '.</p>';
        } else {
          echo '<p class="description">No approver could be resolved automatically. IT or HR will need to approve this request.</p>';
        }
      } elseif ($actor_user_id === $target_user_id && !EUM_Access::is_it_or_hr($actor_user_id)) {
        echo '<p class="description">Your permitted self-edits do not require approval.</p>';
      }

      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Effective At</label>';
      echo '<input type="datetime-local" name="eum_effective_at" value="' . esc_attr($default_effective ? str_replace(' ', 'T', substr($default_effective, 0, 16)) : '') . '">';
      if (!empty($transfer_preview['is_transfer'])) {
        echo '<br><span class="description">Transfers apply no earlier than ' . esc_html(self::transfer_protocol_min_effective_at()) . '. The current manager can lock in the final date up to six weeks out, or later if the incoming manager has already proposed a later date.</span></p>';
      } else {
        echo '<br><span class="description">Leave blank to apply immediately' . ($requires_approval ? ' after approval' : '') . '. With Sunday enforcement enabled, manager-originated edits default to next Sunday 12:00 AM, but self-service personal data updates apply immediately.</span></p>';
      }

      if (EUM_Access::is_it_or_hr($actor_user_id)) {
        echo '<p style="display:flex;gap:18px;flex-wrap:wrap;"><label><input type="checkbox" name="eum_skip_log" value="1"> Skip audit log</label><label><input type="checkbox" name="eum_skip_email" value="1"> Skip email notification</label></p>';
      }

      echo '<p><button class="button button-primary" type="submit">' . ($edit_change_id > 0 ? 'Update Pending Change' : 'Save Changes') . '</button></p>';
      echo '</form>';
    }

    if ($actor_user_id !== $target_user_id) {
      echo $this->render_profile_image_section($target_user_id, $actor_user_id);
    }
    if (class_exists('EUM_Access') && $actor_user_id !== $target_user_id) {
      echo EUM_Access::render_user_login_controls($actor_user_id, $target_user_id, $this->current_url());
    }
    echo $this->render_employment_section($target_user_id, $actor_user_id);
    if (class_exists('EUM_Timeclock')) {
      echo EUM_Timeclock::render_compensation_section($target_user_id, $actor_user_id, $this->current_url());
    }
    echo $this->render_pending_changes($target_user_id, $actor_user_id);
    echo '</div>';
    echo '</div>';
    if ($actor_user_id === $target_user_id && class_exists('EUM_Nextcloud_Bridge')) {
      echo '<div style="margin-top:24px;">' . (new EUM_Nextcloud_Bridge())->render_access_section($target_user_id, 'Nextcloud') . '</div>';
    }
    echo '<div id="eum-personnel-section-' . (int)$target_user_id . '" style="margin-top:24px;">' . $this->render_personnel_section($target_user_id, $actor_user_id) . '</div>';
    echo '</div>';
    return ob_get_clean();
  }

  public function shortcode_work_profile($atts = []): string {
    $atts = array_merge((array)$atts, ['title' => 'Work Profile']);
    return $this->shortcode_private_profile($atts);
  }

  public function shortcode_profile_appearance($atts = []): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-profile-appearance"><p>You must be logged in to manage your appearance.</p></div>';
    }

    $user_id = (int)get_current_user_id();
    $user = get_user_by('id', $user_id);
    if (!$user) return '<div class="eum-profile-appearance"><p>User not found.</p></div>';

    ob_start();
    echo '<div class="eum-profile-appearance eum-private-profile" style="max-width:900px;">';
    echo '<header class="eum-profile-appearance__header"><h1>Appearance</h1><p>Manage the profile photo and color shown across the portal.</p></header>';
    echo $this->render_profile_image_section($user_id, $user_id);
    echo '</div>';
    return (string)ob_get_clean();
  }


  public static function profile_image_url(int $user_id): string {
    $attachment_id = (int)get_user_meta($user_id, self::META_PROFILE_IMAGE_ID, true);
    if ($attachment_id > 0) {
      $image = wp_get_attachment_image_url($attachment_id, 'full');
      if (is_string($image) && $image !== '') return esc_url_raw($image);
    }
    $url = esc_url_raw((string)get_user_meta($user_id, self::META_PROFILE_IMAGE_URL, true));
    return $url;
  }

  public static function profile_avatar_url(int $user_id): string {
    $image_url = self::profile_image_url($user_id);
    if ($image_url !== '') return $image_url;
    if (!get_user_by('id', $user_id)) return '';

    $initials = self::profile_initials($user_id);
    $color = self::profile_image_color($user_id);
    $version = substr(md5('jpeg-v3|' . $initials . '|' . $color), 0, 12);
    $jpeg_url = self::generated_profile_avatar_jpeg_url($user_id, $initials, $color, $version);
    if ($jpeg_url !== '') return add_query_arg('v', $version, $jpeg_url);

    return add_query_arg([
      'action' => 'eum_profile_generated_avatar',
      'user_id' => $user_id,
      'v' => $version,
    ], admin_url('admin-post.php'));
  }

  public function handle_generated_profile_avatar(): void {
    $user_id = isset($_GET['user_id']) ? absint(wp_unslash($_GET['user_id'])) : 0;
    if ($user_id <= 0 || !get_user_by('id', $user_id)) {
      status_header(404);
      exit;
    }

    $initials = self::profile_initials($user_id);
    $color = self::profile_image_color($user_id);
    header('Content-Type: image/jpeg');
    header('Content-Disposition: inline; filename="profile-' . $user_id . '.jpg"');
    header('Cache-Control: public, max-age=86400, s-maxage=86400');
    header('X-Content-Type-Options: nosniff');
    if (!self::render_generated_profile_avatar_jpeg($initials, $color, null)) {
      status_header(503);
    }
    exit;
  }

  private static function generated_profile_avatar_jpeg_url(int $user_id, string $initials, string $color, string $version): string {
    $uploads = wp_upload_dir();
    $base_dir = rtrim((string)($uploads['basedir'] ?? ''), '/\\');
    $base_url = rtrim((string)($uploads['baseurl'] ?? ''), '/');
    if (!empty($uploads['error']) || $base_dir === '' || $base_url === '') return '';

    $directory = $base_dir . DIRECTORY_SEPARATOR . 'eum' . DIRECTORY_SEPARATOR . 'profile-avatars';
    if (!is_dir($directory) && !wp_mkdir_p($directory)) return '';

    $filename = 'user-' . $user_id . '-' . $version . '.jpg';
    $file_path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($file_path) || (int)@filesize($file_path) <= 0) {
      $temp_path = $directory . DIRECTORY_SEPARATOR . '.' . $filename . '-' . wp_generate_password(8, false, false) . '.tmp';
      if (!self::render_generated_profile_avatar_jpeg($initials, $color, $temp_path)) {
        if (is_file($temp_path)) @unlink($temp_path);
        return '';
      }
      if (!is_file($file_path) && !@rename($temp_path, $file_path) && !is_file($file_path)) {
        if (is_file($temp_path)) @unlink($temp_path);
        return '';
      }
      if (is_file($temp_path)) @unlink($temp_path);
    }

    return esc_url_raw($base_url . '/eum/profile-avatars/' . rawurlencode($filename));
  }

  private static function render_generated_profile_avatar_jpeg(string $initials, string $color, ?string $output_path): bool {
    $color = sanitize_hex_color($color) ?: '#2563eb';
    if (strlen($color) === 4) {
      $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
    }
    $red = hexdec(substr($color, 1, 2));
    $green = hexdec(substr($color, 3, 2));
    $blue = hexdec(substr($color, 5, 2));
    $font_path = self::profile_avatar_font_path();

    if ($font_path !== '' && function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagettftext') && function_exists('imagettfbbox')) {
      $image = @imagecreatetruecolor(256, 256);
      if ($image) {
        $background = imagecolorallocate($image, $red, $green, $blue);
        $foreground = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $background);
        $font_size = 96;
        $bounds = @imagettfbbox($font_size, 0, $font_path, $initials);
        $drawn = false;
        if (is_array($bounds)) {
          $left = min($bounds[0], $bounds[2], $bounds[4], $bounds[6]);
          $right = max($bounds[0], $bounds[2], $bounds[4], $bounds[6]);
          $top = min($bounds[1], $bounds[3], $bounds[5], $bounds[7]);
          $bottom = max($bounds[1], $bounds[3], $bounds[5], $bounds[7]);
          $x = (int)round((256 - ($right - $left)) / 2 - $left);
          $y = (int)round((256 - ($bottom - $top)) / 2 - $top);
          $drawn = @imagettftext($image, $font_size, 0, $x, $y, $foreground, $font_path, $initials) !== false;
        }
        if ($drawn) {
          $saved = @imagejpeg($image, $output_path, 90);
          imagedestroy($image);
          if ($saved) return true;
        } else {
          imagedestroy($image);
        }
      }
    }

    if (class_exists('Imagick') && class_exists('ImagickDraw') && class_exists('ImagickPixel')) {
      $font_candidates = array_values(array_unique(array_filter(array_merge(
        $font_path !== '' ? [$font_path] : [],
        ['Arial', 'Helvetica', 'Liberation Sans', 'DejaVu-Sans-Bold', 'DejaVu Sans']
      ))));
      foreach ($font_candidates as $font_candidate) {
        $image = null;
        try {
          $image = new Imagick();
          $image->newImage(256, 256, new ImagickPixel($color));
          $image->setImageFormat('jpeg');
          $image->setImageCompressionQuality(90);
          $draw = new ImagickDraw();
          if ($font_candidate !== '') $draw->setFont($font_candidate);
          $draw->setFontSize(96);
          if (method_exists($draw, 'setFontWeight')) $draw->setFontWeight(700);
          $draw->setFillColor(new ImagickPixel('#ffffff'));
          $draw->setGravity(Imagick::GRAVITY_CENTER);
          $image->annotateImage($draw, 0, 0, 0, $initials);
          if ($output_path === null) {
            $blob = $image->getImageBlob();
            if ($blob !== '') {
              echo $blob;
              $image->clear();
              $image->destroy();
              return true;
            }
          } elseif ($image->writeImage($output_path) && is_file($output_path)) {
            $image->clear();
            $image->destroy();
            return true;
          }
        } catch (Throwable $error) {
          // Try the next available ImageMagick font name.
        }
        if ($image instanceof Imagick) {
          $image->clear();
          $image->destroy();
        }
      }
    }

    // Last resort for GD builds without FreeType/Imagick: resample instead of using
    // nearest-neighbor enlargement, which made the letters look blocky.
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists('imagecopyresampled') && function_exists('imagestring')) {
      $image = @imagecreatetruecolor(256, 256);
      $label = @imagecreatetruecolor(64, 32);
      if ($image && $label) {
        $background = imagecolorallocate($image, $red, $green, $blue);
        imagefill($image, 0, 0, $background);
        imagealphablending($label, false);
        imagesavealpha($label, true);
        $transparent = imagecolorallocatealpha($label, 0, 0, 0, 127);
        imagefill($label, 0, 0, $transparent);
        $fallback_initials = function_exists('remove_accents') ? remove_accents($initials) : $initials;
        $fallback_initials = preg_replace('/[^A-Za-z0-9]/', '', strtoupper((string)$fallback_initials));
        if ($fallback_initials === '') $fallback_initials = 'U';
        $fallback_initials = substr($fallback_initials, 0, 2);
        $foreground = imagecolorallocatealpha($label, 255, 255, 255, 0);
        $text_width = imagefontwidth(5) * strlen($fallback_initials);
        imagestring($label, 5, max(0, (int)floor((64 - $text_width) / 2)), 8, $fallback_initials, $foreground);
        imagealphablending($image, true);
        $target_width = strlen($fallback_initials) > 1 ? 184 : 132;
        $target_height = 132;
        $copied = imagecopyresampled($image, $label, (int)((256 - $target_width) / 2), (int)((256 - $target_height) / 2), 0, 0, $target_width, $target_height, 64, 32);
        imagedestroy($label);
        $saved = $copied && @imagejpeg($image, $output_path, 90);
        imagedestroy($image);
        if ($saved) return true;
      } else {
        if ($image) imagedestroy($image);
        if ($label) imagedestroy($label);
      }
    }

    return false;
  }

  private static function profile_avatar_font_path(): string {
    static $font_path = null;
    if ($font_path !== null) return $font_path;
    $font_path = '';
    $candidates = [
      dirname(__DIR__) . '/assets/fonts/DejaVuSans-Bold.ttf',
      '/usr/share/fonts/truetype/msttcorefonts/Arial_Bold.ttf',
      '/usr/share/fonts/truetype/msttcorefonts/arialbd.ttf',
      '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
      '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
      '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
      '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed-Bold.ttf',
      '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
      '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
      '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
      'C:\\Windows\\Fonts\\arialbd.ttf',
    ];
    foreach ($candidates as $candidate) {
      if (is_readable($candidate)) {
        $font_path = $candidate;
        break;
      }
    }
    return $font_path;
  }

  public static function profile_image_color(int $user_id): string {
    $stored = sanitize_hex_color((string)get_user_meta($user_id, self::META_PROFILE_IMAGE_COLOR, true));
    if ($stored) return $stored;
    $user = get_user_by('id', $user_id);
    $seed = strtolower(trim((string)($user ? ($user->display_name ?: $user->user_login) : ('user-' . $user_id))));
    $palette = ['#2563eb', '#0f766e', '#7c3aed', '#db2777', '#ea580c', '#0891b2', '#4f46e5', '#16a34a'];
    $index = abs(crc32($seed)) % count($palette);
    return $palette[$index];
  }

  public static function profile_initials(int $user_id): string {
    $user = get_user_by('id', $user_id);
    if (!$user) return 'U';
    $parts = preg_split('/\s+/', trim((string)($user->display_name ?: $user->user_login))) ?: [];
    $letters = '';
    foreach ($parts as $part) {
      if ($part === '') continue;
      $initial = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
      $letters .= function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
      $length = function_exists('mb_strlen') ? mb_strlen($letters, 'UTF-8') : strlen($letters);
      if ($length >= 2) break;
    }
    if ($letters === '') {
      $login = (string)$user->user_login;
      $letters = function_exists('mb_substr') ? mb_substr($login, 0, 2, 'UTF-8') : substr($login, 0, 2);
      $letters = function_exists('mb_strtoupper') ? mb_strtoupper($letters, 'UTF-8') : strtoupper($letters);
    }
    return $letters;
  }

  public static function profile_avatar_html(int $user_id, int $size = 96, string $class = ''): string {
    $url = self::profile_image_url($user_id);
    $style = 'width:' . (int)$size . 'px;height:' . (int)$size . 'px;';
    $classes = trim('eum-profile-avatar ' . $class);
    if ($url !== '') {
      return '<span class="' . esc_attr($classes . ' has-image') . '" style="' . esc_attr($style) . '"><img src="' . esc_url($url) . '" alt="" loading="lazy"></span>';
    }
    $generated_url = self::profile_avatar_url($user_id);
    if ($generated_url !== '') {
      return '<span class="' . esc_attr($classes . ' is-generated has-image') . '" style="' . esc_attr($style) . '"><img src="' . esc_url($generated_url) . '" alt="" loading="lazy"></span>';
    }
    return '<span class="' . esc_attr($classes . ' is-generated') . '" style="' . esc_attr($style . 'background:' . self::profile_image_color($user_id) . ';') . '"><span>' . esc_html(self::profile_initials($user_id)) . '</span></span>';
  }

  public static function public_profile_snapshot_html(int $target_user_id, int $viewer_user_id = 0, array $args = []): string {
    $user = get_user_by('id', $target_user_id);
    if (!$user) return '<div class="eum-org-sidepanel__empty">User not found.</div>';
    $default_work_profile_url = class_exists('EUM_Portal_Shell') ? EUM_Portal_Shell::page_url('work_profile') : home_url('/work-profile/');
    $manager_edit_url = (string)($args['manager_edit_url'] ?? $default_work_profile_url);
    $self_edit_url = (string)($args['self_edit_url'] ?? $default_work_profile_url);
    $settings = self::get_settings();
    $query_var = sanitize_key((string)($args['query_var'] ?? $settings['query_var']));
    $fields = array_values(array_filter($settings['public_fields'], fn($id) => isset(self::field_catalog()[$id])));
    ob_start();
    echo '<div class="eum-org-sidepanel__profile">';
    echo '<div class="eum-org-sidepanel__hero">';
    echo self::profile_avatar_html($target_user_id, 84, 'eum-org-sidepanel__avatar');
    echo '<div class="eum-org-sidepanel__hero-copy">';
    echo '<h4>' . esc_html($user->display_name ?: $user->user_login) . '</h4>';
    $job = self::preferred_job_title_label($target_user_id);
    $div = self::get_field_display_value($target_user_id, 'core:job_company');
    if ($job !== '') echo '<p>' . esc_html($job) . '</p>';
    if ($div !== '') echo '<p>' . esc_html($div) . '</p>';
    $worker_badge = self::render_worker_type_badge_html($target_user_id);
    if ($worker_badge !== '') echo '<p style="margin:10px 0 0;">' . $worker_badge . '</p>';
    if (class_exists('EUM_Workforce_Lifecycle')) {
      $ooo_badge = EUM_Workforce_Lifecycle::out_of_office_badge_html($target_user_id);
      if ($ooo_badge !== '') echo '<p style="margin:10px 0 0;">' . $ooo_badge . '</p>';
    }
    echo '</div></div>';
    echo '<dl class="eum-org-sidepanel__details">';
    foreach ($fields as $field_id) {
      $label = self::field_catalog()[$field_id]['label'] ?? $field_id;
      $value = self::get_field_display_value($target_user_id, $field_id);
      if ($value === '') continue;
      echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>';
    }
    echo '</dl>';
    $image_url = self::profile_image_url($target_user_id);
    if ($image_url !== '' && stripos($image_url, 'nextcloud') !== false) {
      echo '<p class="eum-org-sidepanel__hint"><a href="' . esc_url($image_url) . '" target="_blank" rel="noopener">Open profile image</a></p>';
    }
    $can_edit = self::can_edit_target($viewer_user_id, $target_user_id);
    if ($can_edit) {
      $base = ($viewer_user_id === $target_user_id) ? $self_edit_url : $manager_edit_url;
      $edit_url = add_query_arg([$query_var => $target_user_id], $base);
      echo '<p class="eum-org-sidepanel__actions"><a class="button button-primary" href="' . esc_url($edit_url) . '">Edit Profile</a></p>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_profile_image_section(int $target_user_id, int $actor_user_id): string {
    if (!self::can_edit_target($actor_user_id, $target_user_id)) return '';
    $target_user = get_user_by('id', $target_user_id);
    if (!$target_user) return '';
    $section_title = $actor_user_id === $target_user_id
      ? 'Profile Image'
      : 'Profile Image: ' . ($target_user->display_name ?: $target_user->user_login);
    ob_start();
    echo '<div class="eum-private-profile__section eum-private-profile__section--image" style="margin-top:24px;">';
    echo '<h4 style="margin-top:0;">' . esc_html($section_title) . '</h4>';
    echo '<div class="eum-profile-image-editor">';
    echo self::profile_avatar_html($target_user_id, 96, 'eum-profile-image-editor__avatar');
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-profile-image-editor__form" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="eum_profile_image_save">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_profile_image_save_' . $target_user_id, 'eum_profile_image_nonce');
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Upload Image</label><input type="file" name="eum_profile_image_upload" accept="image/*"></p>';
    echo '<p><label><input type="checkbox" name="eum_profile_image_remove" value="1"> Remove current image</label></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Primary Color</label><input type="color" name="eum_profile_image_color" value="' . esc_attr(self::profile_image_color($target_user_id)) . '"></p>';
    echo '<p class="description">Upload an image from your device. If you leave the image empty, the profile will use your initials on the selected color.</p>';
    echo '<p><button type="submit" class="button button-secondary">Save Profile Image</button></p>';
    echo '</form></div></div>';
    return ob_get_clean();
  }

  public function handle_profile_image_save(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)$_POST['return_url']) : home_url('/');
    if ($target_user_id <= 0 || !get_user_by('id', $target_user_id) || !self::can_edit_target($actor_user_id, $target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'You do not have permission to update this profile image.', 'error');
    }
    if (!isset($_POST['eum_profile_image_nonce']) || !wp_verify_nonce($_POST['eum_profile_image_nonce'], 'eum_profile_image_save_' . $target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'Security check failed.', 'error');
    }

    $color = sanitize_hex_color((string)($_POST['eum_profile_image_color'] ?? '')) ?: self::profile_image_color($target_user_id);
    $remove = !empty($_POST['eum_profile_image_remove']);
    $uploaded_attachment_id = 0;
    $uploaded_attachment_path = '';
    $uploaded_attachment_url = '';

    try {
      if (!empty($_FILES['eum_profile_image_upload']['name'])) {
        $upload = $this->handle_profile_image_upload('eum_profile_image_upload', $target_user_id);
        if (is_wp_error($upload)) {
          $this->redirect_with_profile_msg($return_url, $upload->get_error_message(), 'error');
        }
        $this->delete_local_profile_image_for_user($target_user_id);
        $uploaded_attachment_path = (string)($upload['file'] ?? '');
        $uploaded_attachment_url = esc_url_raw((string)($upload['url'] ?? ''));
        delete_user_meta($target_user_id, self::META_PROFILE_IMAGE_ID);
        update_user_meta($target_user_id, self::META_PROFILE_IMAGE_URL, $uploaded_attachment_url);
      } elseif ($remove) {
        $this->delete_local_profile_image_for_user($target_user_id);
        delete_user_meta($target_user_id, self::META_PROFILE_IMAGE_ID);
        delete_user_meta($target_user_id, self::META_PROFILE_IMAGE_URL);
      }

      update_user_meta($target_user_id, self::META_PROFILE_IMAGE_COLOR, $color);
    } catch (\Throwable $e) {
      $message = sanitize_text_field($e->getMessage());
      eum_log_message('[EUM Profiles] Profile image save failed for user ' . $target_user_id . ': ' . $message);
      $this->redirect_with_profile_msg($return_url, $message !== '' ? ('Profile image save failed: ' . $message) : 'Could not update the profile image right now. Please try again.', 'error');
    }

    try {
      if ($uploaded_attachment_path !== '' || $uploaded_attachment_url !== '') {
        $attachment_url = $uploaded_attachment_url ?: self::profile_image_url($target_user_id);
        do_action('eum_profile_image_uploaded', $target_user_id, $uploaded_attachment_id, $uploaded_attachment_path, $attachment_url);
      } elseif ($remove) {
        do_action('eum_profile_image_removed', $target_user_id, $actor_user_id);
      }
    } catch (\Throwable $e) {
      eum_log_message('[EUM Profiles] Post-save profile image sync failed for user ' . $target_user_id . ': ' . sanitize_text_field($e->getMessage()));
    }

    $this->redirect_with_profile_msg($return_url, 'Profile image updated.', 'success');
  }

  private function handle_profile_image_upload(string $field_name, int $user_id) {
    $file = $_FILES[$field_name] ?? null;
    if (!is_array($file) || empty($file['name']) || empty($file['tmp_name'])) {
      return new WP_Error('eum_profile_image_missing', 'No image upload was received.');
    }

    if (!function_exists('wp_handle_upload')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $uploaded = wp_handle_upload($file, ['test_form' => false]);
    if (is_wp_error($uploaded)) {
      return $uploaded;
    }
    if (!is_array($uploaded)) {
      return new WP_Error('eum_profile_image_upload_failed', 'The upload handler returned an unexpected response.');
    }
    if (!empty($uploaded['error']) || empty($uploaded['file'])) {
      return new WP_Error('eum_profile_image_upload_failed', sanitize_text_field((string)($uploaded['error'] ?? 'Could not upload the selected image.')));
    }

    $mime_type = (string)($uploaded['type'] ?? '');
    if ($mime_type === '') {
      $filetype = wp_check_filetype(basename($uploaded['file']), null);
      $mime_type = (string)($filetype['type'] ?? '');
    }
    if ($mime_type === '' || strpos($mime_type, 'image/') !== 0) {
      $this->delete_uploaded_file($uploaded['file']);
      return new WP_Error('eum_profile_image_invalid_type', 'Please upload a valid image file.');
    }

    if (empty($uploaded['url'])) {
      $uploads = wp_get_upload_dir();
      if (!is_array($uploads)) {
        $this->delete_uploaded_file((string)$uploaded['file']);
        return new WP_Error('eum_profile_image_missing_url', 'The upload directory could not be resolved for this image.');
      }
      $baseurl = isset($uploads['baseurl']) ? rtrim((string)$uploads['baseurl'], '/') : '';
      $basedir = isset($uploads['basedir']) ? rtrim((string)$uploads['basedir'], '\\/') : '';
      $filepath = (string)$uploaded['file'];
      if ($baseurl !== '' && $basedir !== '' && strpos($filepath, $basedir) === 0) {
        $relative = ltrim(str_replace('\\', '/', substr($filepath, strlen($basedir))), '/');
        $uploaded['url'] = $baseurl . '/' . $relative;
      }
    }

    $url = esc_url_raw((string)($uploaded['url'] ?? ''));
    if ($url === '') {
      $this->delete_uploaded_file((string)$uploaded['file']);
      return new WP_Error('eum_profile_image_missing_url', 'The uploaded image could not be published.');
    }

    return [
      'file' => (string)$uploaded['file'],
      'url' => $url,
      'mime' => $mime_type,
      'user_id' => $user_id,
    ];
  }

  private function delete_local_profile_image_for_user(int $user_id): void {
    $attachment_id = (int)get_user_meta($user_id, self::META_PROFILE_IMAGE_ID, true);
    if ($attachment_id > 0) {
      return;
    }
    $url = esc_url_raw((string)get_user_meta($user_id, self::META_PROFILE_IMAGE_URL, true));
    if ($url === '') {
      return;
    }
    $uploads = wp_get_upload_dir();
    if (!is_array($uploads)) {
      return;
    }
    $baseurl = isset($uploads['baseurl']) ? rtrim((string)$uploads['baseurl'], '/') : '';
    $basedir = isset($uploads['basedir']) ? rtrim((string)$uploads['basedir'], '\\/') : '';
    if ($baseurl === '' || $basedir === '' || strpos($url, $baseurl . '/') !== 0) {
      return;
    }
    $relative = ltrim(substr($url, strlen($baseurl)), '/');
    if ($relative === '') {
      return;
    }
    $path = $basedir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    $this->delete_uploaded_file($path);
  }

  private function delete_uploaded_file(string $path): void {
    $path = trim($path);
    if ($path === '' || !file_exists($path)) {
      return;
    }
    if (function_exists('wp_delete_file')) {
      wp_delete_file($path);
      return;
    }
    @unlink($path);
  }

  public static function render_field_input(string $field_id, $raw, int $target_user_id = 0, int $actor_user_id = 0): string {
    $catalog = self::field_catalog();
    if (!isset($catalog[$field_id])) return '';
    return (new self())->render_frontend_input($field_id, $catalog[$field_id], $raw, $target_user_id, $actor_user_id);
  }

  public static function normalize_field_input(string $field_id, $posted) {
    $catalog = self::field_catalog();
    if (!isset($catalog[$field_id])) return null;
    return (new self())->normalize_posted_field($field_id, $catalog[$field_id], $posted);
  }

  public static function render_taxonomy_directory_picker(string $taxonomy, string $input_name, int $selected_term_id = 0, int $actor_user_id = 0, array $args = []): string {
    if (!taxonomy_exists($taxonomy)) {
      return '<input type="hidden" name="' . esc_attr($input_name) . '" value="0">';
    }

    $taxonomy_obj = get_taxonomy($taxonomy);
    if (!$taxonomy_obj || empty($taxonomy_obj->hierarchical)) {
      return '<input type="hidden" name="' . esc_attr($input_name) . '" value="0">';
    }

    $placeholder = (string)($args['placeholder'] ?? '— Select —');
    $required = !empty($args['required']);
    $input_id = sanitize_html_class((string)($args['input_id'] ?? ('eum-tax-path-' . wp_rand(1000, 999999))));
    $container_id = sanitize_html_class((string)($args['container_id'] ?? ('eum-tax-path-picker-' . wp_rand(1000, 999999))));
    $none_label = array_key_exists('none_label', $args) ? (string)$args['none_label'] : '';
    $enforce_manageable = !empty($args['enforce_manageable_job_paths']);

    $terms = get_terms([
      'taxonomy' => $taxonomy,
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
      'number' => 0,
    ]);
    if (is_wp_error($terms) || empty($terms)) {
      return '<input type="hidden" name="' . esc_attr($input_name) . '" value="0"><p class="description">No paths are available yet.</p>';
    }

    $allowed_terms = [];
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      if ($taxonomy === 'eum_job_path' && $enforce_manageable && $actor_user_id > 0 && !EUM_Access::is_it_or_hr($actor_user_id) && !self::actor_can_manage_job_term($actor_user_id, $term_id)) {
        continue;
      }
      $allowed_terms[$term_id] = [
        'id' => $term_id,
        'parent' => (int)$term->parent,
        'name' => (string)$term->name,
        'label' => self::term_full_path($taxonomy, $term_id, ' / '),
      ];
    }

    if (empty($allowed_terms)) {
      return '<input type="hidden" name="' . esc_attr($input_name) . '" value="0"><p class="description">No paths are available for your role.</p>';
    }

    $roots = [];
    foreach ($allowed_terms as $term_id => $item) {
      if ($item['parent'] <= 0 || !isset($allowed_terms[$item['parent']])) {
        $roots[] = $term_id;
      }
    }
    usort($roots, static function($a, $b) use ($allowed_terms) {
      return strcasecmp((string)($allowed_terms[$a]['name'] ?? ''), (string)($allowed_terms[$b]['name'] ?? ''));
    });

    ob_start();
    if (!self::$taxonomy_picker_assets_printed) {
      self::$taxonomy_picker_assets_printed = true;
      $picker_css = <<<'EUMCSS'
<style>.eum-taxonomy-path-picker{display:block;max-width:760px}.eum-taxonomy-path-picker__toolbar{display:flex;align-items:center;gap:10px;background:#111827;color:#fff;border-radius:10px;padding:10px 12px;margin-bottom:10px}.eum-taxonomy-path-picker__back{display:inline-flex;align-items:center;justify-content:center;border:0;background:#374151;color:#fff;border-radius:8px;width:28px;height:28px;padding:0;cursor:pointer;font-size:16px;line-height:1;box-shadow:none}.eum-taxonomy-path-picker__back[disabled]{opacity:.45;cursor:not-allowed}.eum-taxonomy-path-picker__meta{min-width:0}.eum-taxonomy-path-picker__meta strong{display:block;font-size:11px;color:#d1d5db;text-transform:uppercase;letter-spacing:.03em}.eum-taxonomy-path-picker__path{display:block;font-size:13px;word-break:break-word;margin-top:2px}.eum-taxonomy-path-picker__panel{border:1px solid #ccd0d4;border-radius:10px;background:#fff;overflow:hidden}.eum-taxonomy-path-picker__row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-top:1px solid #f0f0f1}.eum-taxonomy-path-picker__row:first-child{border-top:0}.eum-taxonomy-path-picker__name{font-weight:600}.eum-taxonomy-path-picker__hint{color:#6b7280;font-size:12px;margin-top:2px}.eum-taxonomy-path-picker__go{display:inline-flex;align-items:center;justify-content:center;flex:0 0 32px;border:0;background:#2563eb;color:#fff;border-radius:999px;width:32px;min-width:32px;height:32px;padding:0;cursor:pointer;font-size:18px;line-height:1;font-family:inherit;font-weight:600;box-shadow:none}.eum-taxonomy-path-picker__go span{display:block;line-height:1;transform:translateX(1px)}.eum-taxonomy-path-picker__empty{padding:14px;color:#50575e}.eum-taxonomy-path-picker__summary{margin-top:8px;color:#50575e;font-size:13px}.eum-taxonomy-path-picker__none{margin-top:10px}</style>
EUMCSS;
      $picker_js = <<<'EUMJS'
<script>(function(){if(window.EUMTaxonomyPathPickerInit)return;window.EUMTaxonomyPathPickerInit=function(root){var hidden=root.querySelector("input[type=hidden]");var panel=root.querySelector(".eum-taxonomy-path-picker__panel");var summary=root.querySelector(".eum-taxonomy-path-picker__summary");var current=root.querySelector(".eum-taxonomy-path-picker__path");var back=root.querySelector(".eum-taxonomy-path-picker__back");var noneBtn=root.querySelector(".eum-taxonomy-path-picker__nonebtn");if(!hidden||!panel)return;var items=JSON.parse(root.getAttribute("data-items")||"[]");var roots=JSON.parse(root.getAttribute("data-roots")||"[]");var placeholder=root.getAttribute("data-placeholder")||"— Select —";var noneLabel=root.getAttribute("data-none-label")||"";var map={},children={};items.forEach(function(item){item.id=parseInt(item.id,10)||0;item.parent=parseInt(item.parent,10)||0;map[item.id]=item;var key=String(item.parent);children[key]=children[key]||[];children[key].push(item);});Object.keys(children).forEach(function(key){children[key].sort(function(a,b){return (a.name||"").localeCompare((b.name||""),undefined,{sensitivity:"base"});});});var path=[];var selected=parseInt(root.getAttribute("data-selected")||hidden.value||0,10)||0;if(selected&&map[selected]){var cursor=selected;while(cursor&&map[cursor]){path.unshift(cursor);var parent=map[cursor].parent||0;if(!parent||!map[parent])break;cursor=parent;}}if(path.length&&roots.indexOf(path[0])===-1){path=[];selected=0;}var currentBranch=function(){return path.length?path[path.length-1]:0;};var pathLabel=function(){if(!path.length)return '/';return '/ ' + path.map(function(id){return map[id]&&map[id].name?map[id].name:'';}).filter(Boolean).join(' / ');};var render=function(){var branch=currentBranch();var list=(branch>0?(children[String(branch)]||[]):roots.map(function(id){return map[id];}).filter(Boolean));panel.innerHTML='';if(!list.length){panel.innerHTML='<div class="eum-taxonomy-path-picker__empty">' + (branch>0?'No deeper path items here.':'No path items available.') + '</div>';}else{list.forEach(function(item){var hasChildren=(children[String(item.id)]||[]).length>0;var row=document.createElement('div');row.className='eum-taxonomy-path-picker__row';var left=document.createElement('div');var name=document.createElement('div');name.className='eum-taxonomy-path-picker__name';name.textContent=item.name;left.appendChild(name);var hint=document.createElement('div');hint.className='eum-taxonomy-path-picker__hint';hint.textContent=hasChildren?'Select and go deeper':'Select this path';left.appendChild(hint);var btn=document.createElement('button');btn.type='button';btn.className='eum-taxonomy-path-picker__go';btn.setAttribute('aria-label','Select ' + item.name);btn.innerHTML='<span aria-hidden="true">&#8250;</span>';btn.addEventListener('click',function(){if(path.length&&path[path.length-1]===item.id){}else{path.push(item.id);}hidden.value=String(item.id);render();});row.appendChild(left);row.appendChild(btn);panel.appendChild(row);});}if(current)current.textContent=pathLabel();if(summary){var resolved=parseInt(hidden.value,10)||0;summary.textContent=resolved&&map[resolved]?'Selected: ' + (map[resolved].label||map[resolved].name||''):(noneLabel?'Selected: ' + noneLabel:'No path selected. ' + placeholder);}if(back)back.disabled=path.length===0;if(noneBtn)noneBtn.hidden=!noneLabel;};if(back){back.addEventListener('click',function(){if(!path.length)return;path.pop();hidden.value=path.length?String(path[path.length-1]):'0';render();});}if(noneBtn){noneBtn.addEventListener('click',function(){path=[];hidden.value='0';render();});}render();};document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.eum-taxonomy-path-picker').forEach(function(root){window.EUMTaxonomyPathPickerInit(root);});});}());</script>
EUMJS;
      echo $picker_css;
      echo $picker_js;
    }

    echo '<div id="' . esc_attr($container_id) . '" class="eum-taxonomy-path-picker" data-items="' . esc_attr(wp_json_encode(array_values($allowed_terms))) . '" data-roots="' . esc_attr(wp_json_encode(array_values($roots))) . '" data-selected="' . (int)$selected_term_id . '" data-placeholder="' . esc_attr($placeholder) . '" data-none-label="' . esc_attr($none_label) . '">';
    echo '<div class="eum-taxonomy-path-picker__toolbar"><button type="button" class="eum-taxonomy-path-picker__back" disabled>&lsaquo;</button><div class="eum-taxonomy-path-picker__meta"><strong>Current Path</strong><span class="eum-taxonomy-path-picker__path">/</span></div></div>';
    echo '<input type="hidden" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="' . (int)$selected_term_id . '"' . ($required ? ' required' : '') . '>';
    echo '<div class="eum-taxonomy-path-picker__panel"></div>';
    if ($none_label !== '') echo '<p class="eum-taxonomy-path-picker__none"><button type="button" class="button button-secondary eum-taxonomy-path-picker__nonebtn">' . esc_html($none_label) . '</button></p>';
    echo '<div class="eum-taxonomy-path-picker__summary"></div>';
    echo '</div>';
    return ob_get_clean();
  }

  public static function render_job_path_directory_picker(string $input_name, int $selected_term_id = 0, int $actor_user_id = 0, array $args = []): string {
    $args['enforce_manageable_job_paths'] = !array_key_exists('enforce_manageable_job_paths', $args) ? true : !empty($args['enforce_manageable_job_paths']);
    $placeholder = (string)($args['placeholder'] ?? '— Select a Job Path —');
    $required = !empty($args['required']);
    $none_label = array_key_exists('none_label', $args) ? (string)$args['none_label'] : '';
    $enforce_manageable = !empty($args['enforce_manageable_job_paths']);

    $terms = get_terms([
      'taxonomy' => 'eum_job_path',
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
      'number' => 0,
    ]);
    if (is_wp_error($terms) || empty($terms)) {
      return '<input type="hidden" name="' . esc_attr($input_name) . '" value="0"><p class="description">No job paths are available yet.</p>';
    }

    $allowed_terms = [];
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      if ($enforce_manageable && $actor_user_id > 0 && !EUM_Access::is_it_or_hr($actor_user_id) && !self::actor_can_manage_job_term($actor_user_id, $term_id)) continue;
      $allowed_terms[$term_id] = [
        'id' => $term_id,
        'parent' => (int)$term->parent,
        'name' => (string)$term->name,
        'label' => self::term_full_path('eum_job_path', $term_id, ' / '),
      ];
    }
    if (empty($allowed_terms)) {
      return '<input type="hidden" name="' . esc_attr($input_name) . '" value="0"><p class="description">No job paths are available for your role.</p>';
    }

    $roots = [];
    foreach ($allowed_terms as $term_id => $item) {
      if ($item['parent'] <= 0 || !isset($allowed_terms[$item['parent']])) $roots[] = $term_id;
    }
    usort($roots, static function($a, $b) use ($allowed_terms) {
      return strcasecmp((string)($allowed_terms[$a]['name'] ?? ''), (string)($allowed_terms[$b]['name'] ?? ''));
    });

    $title_input_name = (string)($args['job_title_input_name'] ?? '');
    $title_current = (string)($args['job_title_current'] ?? '');
    $title_ids = $title_input_name !== '' ? self::job_title_dom_ids_from_name($title_input_name) : ['hidden' => '', 'display' => ''];
    $title_current = self::normalize_job_title_value($title_current, $selected_term_id);
    $title_label = self::job_title_label_from_value($title_current);
    $titles_by_path = [];
    if ($title_input_name !== '' && class_exists('EUM_Job_Titles')) {
      foreach (array_keys($allowed_terms) as $term_id) {
        $rows = [];
        foreach (EUM_Job_Titles::title_records_for_job_term((int)$term_id) as $row) {
          $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'label' => (string)($row['label'] ?? ''),
            'token' => (string)($row['token'] ?? ''),
          ];
        }
        $titles_by_path[(string)$term_id] = $rows;
      }
    }

    $input_id = sanitize_html_class((string)($args['input_id'] ?? ('eum-tax-path-' . wp_rand(1000, 999999))));
    $container_id = sanitize_html_class((string)($args['container_id'] ?? ('eum-tax-path-picker-' . wp_rand(1000, 999999))));

    ob_start();
    if (!self::$job_picker_assets_printed) {
      self::$job_picker_assets_printed = true;
      $picker_css = <<<'EUMCSS'
<style>.eum-job-path-picker{display:block;max-width:760px}.eum-job-path-picker__toolbar{display:flex;align-items:center;gap:10px;background:#111827;color:#fff;border-radius:10px;padding:10px 12px;margin-bottom:10px}.eum-job-path-picker__back{display:inline-flex;align-items:center;justify-content:center;border:0;background:#374151;color:#fff;border-radius:8px;width:28px;height:28px;padding:0;cursor:pointer;font-size:16px;line-height:1;box-shadow:none}.eum-job-path-picker__back[disabled]{opacity:.45;cursor:not-allowed}.eum-job-path-picker__meta{min-width:0}.eum-job-path-picker__meta strong{display:block;font-size:11px;color:#d1d5db;text-transform:uppercase;letter-spacing:.03em}.eum-job-path-picker__path{display:block;font-size:13px;word-break:break-word;margin-top:2px}.eum-job-path-picker__panel{border:1px solid #ccd0d4;border-radius:10px;background:#fff;overflow:hidden}.eum-job-path-picker__section{padding:10px 14px 4px;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;background:#f9fafb;border-top:1px solid #f0f0f1}.eum-job-path-picker__section:first-child{border-top:0}.eum-job-path-picker__row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-top:1px solid #f0f0f1}.eum-job-path-picker__row:first-of-type{border-top:0}.eum-job-path-picker__name{font-weight:600}.eum-job-path-picker__hint{color:#6b7280;font-size:12px;margin-top:2px}.eum-job-path-picker__badge{display:inline-flex;align-items:center;gap:6px;margin-left:8px;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:700}.eum-job-path-picker__badge--path{background:#eff6ff;color:#1d4ed8}.eum-job-path-picker__go{display:inline-flex;align-items:center;justify-content:center;flex:0 0 32px;border:0;background:#2563eb;color:#fff;border-radius:999px;width:32px;min-width:32px;height:32px;padding:0;cursor:pointer;font-size:18px;line-height:1;font-family:inherit;font-weight:600;box-shadow:none}.eum-job-path-picker__go span{display:block;line-height:1;transform:translateX(1px)}.eum-job-path-picker__empty{padding:14px;color:#50575e}.eum-job-path-picker__summary{margin-top:8px;color:#50575e;font-size:13px}.eum-job-path-picker__none{margin-top:10px}</style>
EUMCSS;
      $picker_js = <<<'EUMJS'
<script>(function(){if(window.EUMJobPathPickerInit)return;window.EUMJobPathPickerInit=function(root){var pathHidden=root.querySelector('.eum-job-path-picker__value');var panel=root.querySelector('.eum-job-path-picker__panel');var summary=root.querySelector('.eum-job-path-picker__summary');var current=root.querySelector('.eum-job-path-picker__path');var back=root.querySelector('.eum-job-path-picker__back');var noneBtn=root.querySelector('.eum-job-path-picker__nonebtn');if(!pathHidden||!panel)return;var items=JSON.parse(root.getAttribute('data-items')||'[]');var roots=JSON.parse(root.getAttribute('data-roots')||'[]');var titlesByPath=JSON.parse(root.getAttribute('data-titles')||'{}');var placeholder=root.getAttribute('data-placeholder')||'— Select —';var noneLabel=root.getAttribute('data-none-label')||'';var titleHiddenId=root.getAttribute('data-title-hidden')||'';var titleDisplayId=root.getAttribute('data-title-display')||'';var titleHidden=titleHiddenId?document.getElementById(titleHiddenId):null;var titleDisplay=titleDisplayId?document.getElementById(titleDisplayId):null;var selectedTitleValue=(titleHidden&&titleHidden.value)||root.getAttribute('data-selected-title')||'';var selectedTitleLabel=(titleDisplay&&titleDisplay.value)||root.getAttribute('data-selected-title-label')||'';var map={},children={};items.forEach(function(item){item.id=parseInt(item.id,10)||0;item.parent=parseInt(item.parent,10)||0;map[item.id]=item;var key=String(item.parent);children[key]=children[key]||[];children[key].push(item);});Object.keys(children).forEach(function(key){children[key].sort(function(a,b){return (a.name||'').localeCompare((b.name||''),undefined,{sensitivity:'base'});});});var path=[];var selected=parseInt(root.getAttribute('data-selected')||pathHidden.value||0,10)||0;if(selected&&map[selected]){var cursor=selected;while(cursor&&map[cursor]){path.unshift(cursor);var parent=map[cursor].parent||0;if(!parent||!map[parent])break;cursor=parent;}}if(path.length&&roots.indexOf(path[0])===-1){path=[];selected=0;}var currentBranch=function(){return path.length?path[path.length-1]:0;};var pathLabel=function(){if(!path.length)return '/';return '/ ' + path.map(function(id){return map[id]&&map[id].name?map[id].name:'';}).filter(Boolean).join(' / ');};var setTitle=function(value,label){selectedTitleValue=value||'';selectedTitleLabel=label||'';if(titleHidden)titleHidden.value=selectedTitleValue;if(titleDisplay)titleDisplay.value=selectedTitleLabel;root.setAttribute('data-selected-title',selectedTitleValue);root.setAttribute('data-selected-title-label',selectedTitleLabel);};var renderSection=function(label){var section=document.createElement('div');section.className='eum-job-path-picker__section';section.textContent=label;panel.appendChild(section);};var renderRow=function(name,hint,kind,clickHandler){var row=document.createElement('div');row.className='eum-job-path-picker__row';var left=document.createElement('div');var nameEl=document.createElement('div');nameEl.className='eum-job-path-picker__name';nameEl.textContent=name;var badge=document.createElement('span');badge.className='eum-job-path-picker__badge ' + (kind==='title'?'':'eum-job-path-picker__badge--path');badge.textContent=kind==='title'?'Title':'Further Path';nameEl.appendChild(badge);left.appendChild(nameEl);var hintEl=document.createElement('div');hintEl.className='eum-job-path-picker__hint';hintEl.textContent=hint;left.appendChild(hintEl);var btn=document.createElement('button');btn.type='button';btn.className='eum-job-path-picker__go';btn.setAttribute('aria-label',name);btn.innerHTML='<span aria-hidden="true">&#8250;</span>';btn.addEventListener('click',clickHandler);row.appendChild(left);row.appendChild(btn);panel.appendChild(row);};var render=function(){var branch=currentBranch();var pathList=(branch>0?(children[String(branch)]||[]):roots.map(function(id){return map[id];}).filter(Boolean));var titleList=branch>0?(titlesByPath[String(branch)]||[]):[];panel.innerHTML='';if(pathList.length){renderSection('Further Paths');pathList.forEach(function(item){var hasChildren=(children[String(item.id)]||[]).length>0;renderRow(item.name,hasChildren?'Select this path and continue deeper':'Select this path', 'path', function(){if(path.length&&path[path.length-1]===item.id){}else{path.push(item.id);}pathHidden.value=String(item.id);setTitle('','');render();});});}if(titleList.length){renderSection('Titles');titleList.forEach(function(item){renderRow(item.label,'Use this title on the current path','title',function(){if(branch<=0)return;pathHidden.value=String(branch);setTitle(item.token||'',item.label||'');render();});});}if(!pathList.length&&!titleList.length){panel.innerHTML='<div class="eum-job-path-picker__empty">' + (branch>0?'No deeper paths or titles here yet.':'No path items available.') + '</div>';}if(current)current.textContent=pathLabel();if(summary){var resolved=parseInt(pathHidden.value,10)||0;var text=resolved&&map[resolved]?'Selected Path: ' + (map[resolved].label||map[resolved].name||''):((noneLabel?'Selected: ' + noneLabel:'No path selected. ' + placeholder));if(selectedTitleLabel){text += ' • Title: ' + selectedTitleLabel;}summary.textContent=text;}if(back)back.disabled=path.length===0;if(noneBtn)noneBtn.hidden=!noneLabel;};if(back){back.addEventListener('click',function(){if(!path.length)return;path.pop();pathHidden.value=path.length?String(path[path.length-1]):'0';setTitle('','');render();});}if(noneBtn){noneBtn.addEventListener('click',function(){path=[];pathHidden.value='0';setTitle('','');render();});}render();};document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.eum-job-path-picker').forEach(function(root){window.EUMJobPathPickerInit(root);});});}());</script>
EUMJS;
      echo $picker_css;
      echo $picker_js;
    }

    echo '<div id="' . esc_attr($container_id) . '" class="eum-job-path-picker" data-items="' . esc_attr(wp_json_encode(array_values($allowed_terms))) . '" data-roots="' . esc_attr(wp_json_encode(array_values($roots))) . '" data-titles="' . esc_attr(wp_json_encode($titles_by_path)) . '" data-selected="' . (int)$selected_term_id . '" data-selected-title="' . esc_attr($title_current) . '" data-selected-title-label="' . esc_attr($title_label) . '" data-placeholder="' . esc_attr($placeholder) . '" data-none-label="' . esc_attr($none_label) . '" data-title-hidden="' . esc_attr($title_ids['hidden']) . '" data-title-display="' . esc_attr($title_ids['display']) . '">';
    echo '<div class="eum-job-path-picker__toolbar"><button type="button" class="eum-job-path-picker__back" disabled>&lsaquo;</button><div class="eum-job-path-picker__meta"><strong>Current Path</strong><span class="eum-job-path-picker__path">/</span></div></div>';
    echo '<input type="hidden" class="eum-job-path-picker__value" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="' . (int)$selected_term_id . '"' . ($required ? ' required' : '') . '>';
    if ($title_input_name !== '') {
      echo '<input type="hidden" id="' . esc_attr($title_ids['hidden']) . '" name="' . esc_attr($title_input_name) . '" value="' . esc_attr($title_current) . '">';
    }
    echo '<div class="eum-job-path-picker__panel"></div>';
    if ($none_label !== '') echo '<p class="eum-job-path-picker__none"><button type="button" class="button button-secondary eum-job-path-picker__nonebtn">' . esc_html($none_label) . '</button></p>';
    echo '<div class="eum-job-path-picker__summary"></div>';
    echo '</div>';
    return ob_get_clean();
  }

  /**
   * Render a hierarchical, multi-select Job Path explorer. Keeping this next
   * to the profile picker gives every Job Path field the same folder layout.
   */
  public static function render_job_path_directory_checkboxes(string $input_name, array $selected_term_ids = [], int $actor_user_id = 0, array $args = []): string {
    $args['enforce_manageable_job_paths'] = !array_key_exists('enforce_manageable_job_paths', $args) ? true : !empty($args['enforce_manageable_job_paths']);
    $selected_term_ids = array_values(array_unique(array_filter(array_map('intval', $selected_term_ids), static function($term_id) { return $term_id > 0; })));
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'number' => 0]);
    if (is_wp_error($terms) || empty($terms)) return '<p class="description">No job paths are available yet.</p>';

    $allowed = [];
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      if (!empty($args['enforce_manageable_job_paths']) && $actor_user_id > 0 && !EUM_Access::is_it_or_hr($actor_user_id) && !self::actor_can_manage_job_term($actor_user_id, $term_id)) continue;
      $allowed[$term_id] = ['id' => $term_id, 'parent' => (int)$term->parent, 'name' => (string)$term->name, 'label' => self::term_full_path('eum_job_path', $term_id, ' / ')];
    }
    if (empty($allowed)) return '<p class="description">No job paths are available for your role.</p>';

    $children = [];
    foreach ($allowed as $term_id => $item) {
      $parent = isset($allowed[$item['parent']]) ? $item['parent'] : 0;
      $children[$parent][] = $term_id;
    }
    foreach ($children as &$branch) {
      usort($branch, static function($a, $b) use ($allowed) {
        return strcasecmp((string)$allowed[$a]['name'], (string)$allowed[$b]['name']);
      });
    }
    unset($branch);

    $name = $input_name;
    if (substr($name, -2) !== '[]') $name .= '[]';
    $container_id = sanitize_html_class((string)($args['container_id'] ?? ('eum-job-path-multi-' . wp_rand(1000, 999999))));
    $html = '<style>.eum-job-path-multi-picker{display:block;max-width:760px}.eum-job-path-multi-picker__toolbar{display:flex;align-items:center;gap:10px;background:#111827;color:#fff;border-radius:10px;padding:10px 12px;margin-bottom:10px}.eum-job-path-multi-picker__back{display:inline-flex;align-items:center;justify-content:center;border:0;background:#374151;color:#fff;border-radius:8px;width:28px;height:28px;padding:0;cursor:pointer;font-size:16px;line-height:1}.eum-job-path-multi-picker__back[disabled]{opacity:.45;cursor:not-allowed}.eum-job-path-multi-picker__meta{min-width:0}.eum-job-path-multi-picker__meta strong{display:block;font-size:11px;color:#d1d5db;text-transform:uppercase;letter-spacing:.03em}.eum-job-path-multi-picker__path{display:block;font-size:13px;word-break:break-word;margin-top:2px}.eum-job-path-multi-picker__panel{border:1px solid #ccd0d4;border-radius:10px;background:#fff;overflow:hidden;max-height:320px;overflow-y:auto}.eum-job-path-multi-picker__row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-top:1px solid #f0f0f1}.eum-job-path-multi-picker__row:first-child{border-top:0}.eum-job-path-multi-picker__name{font-weight:600}.eum-job-path-multi-picker__hint{color:#6b7280;font-size:12px;margin-top:2px}.eum-job-path-multi-picker__select{display:flex;align-items:center;gap:8px;min-width:0}.eum-job-path-multi-picker__select label{display:flex;align-items:center;gap:8px;min-width:0;cursor:pointer}.eum-job-path-multi-picker__select label span{overflow-wrap:anywhere}.eum-job-path-multi-picker__go{display:inline-flex;align-items:center;justify-content:center;flex:0 0 32px;border:0;background:#2563eb;color:#fff;border-radius:999px;width:32px;min-width:32px;height:32px;padding:0;cursor:pointer;font-size:18px;line-height:1;font-family:inherit;font-weight:600}.eum-job-path-multi-picker__empty{padding:14px;color:#50575e}.eum-job-path-multi-picker__summary{margin-top:8px;color:#50575e;font-size:13px;word-break:break-word}</style>';
    $html .= '<div id="' . esc_attr($container_id) . '" class="eum-job-path-multi-picker" data-items="' . esc_attr(wp_json_encode(array_values($allowed))) . '" data-roots="' . esc_attr(wp_json_encode(array_values(array_map('intval', $children[0] ?? [])))) . '">';
    $html .= '<div class="eum-job-path-multi-picker__toolbar"><button type="button" class="eum-job-path-multi-picker__back" disabled>&lsaquo;</button><div class="eum-job-path-multi-picker__meta"><strong>Current Path</strong><span class="eum-job-path-multi-picker__path">/</span></div></div><div class="eum-job-path-multi-picker__panel"></div><div class="eum-job-path-multi-picker__summary"></div></div>';
    $html .= '<script>(function(){var root=document.getElementById(' . wp_json_encode($container_id) . ');if(!root||root.getAttribute("data-ready"))return;root.setAttribute("data-ready","1");var items=JSON.parse(root.getAttribute("data-items")||"[]"),map={},children={},path=[];items.forEach(function(item){item.id=parseInt(item.id,10)||0;item.parent=parseInt(item.parent,10)||0;map[item.id]=item;(children[String(item.parent)]||(children[String(item.parent)]=[])).push(item);});Object.keys(children).forEach(function(key){children[key].sort(function(a,b){return(a.name||"").localeCompare(b.name||"",undefined,{sensitivity:"base"});});});var panel=root.querySelector(".eum-job-path-multi-picker__panel"),current=root.querySelector(".eum-job-path-multi-picker__path"),summary=root.querySelector(".eum-job-path-multi-picker__summary"),back=root.querySelector(".eum-job-path-multi-picker__back"),selected=' . wp_json_encode($selected_term_ids) . ';var has=function(id){return selected.indexOf(id)!==-1;};var descendants=function(id){var out=[id];(children[String(id)]||[]).forEach(function(child){out=out.concat(descendants(child.id));});return out;};var setBranch=function(id,checked){descendants(id).forEach(function(childId){if(checked){if(!has(childId))selected.push(childId);}else{selected=selected.filter(function(existing){return existing!==childId;});}});};var render=function(){var branch=path.length?path[path.length-1]:0,list=children[String(branch)]||[];panel.innerHTML="";if(!list.length){panel.innerHTML="<div class=\"eum-job-path-multi-picker__empty\">No path items available.</div>";}list.forEach(function(item){var row=document.createElement("div"),left=document.createElement("div"),select=document.createElement("div"),label=document.createElement("label"),box=document.createElement("input"),text=document.createElement("span"),hint=document.createElement("div"),go=document.createElement("button"),hasChildren=(children[String(item.id)]||[]).length>0;row.className="eum-job-path-multi-picker__row";left.className="eum-job-path-multi-picker__name";select.className="eum-job-path-multi-picker__select";box.type="checkbox";box.checked=has(item.id);box.addEventListener("click",function(e){e.stopPropagation();setBranch(item.id,box.checked);update();});text.textContent=item.name;label.appendChild(box);label.appendChild(text);select.appendChild(label);hint.className="eum-job-path-multi-picker__hint";hint.textContent=hasChildren?"Open folder to browse deeper; selecting this folder includes everything underneath":"Job Path";left.appendChild(select);left.appendChild(hint);go.type="button";go.className="eum-job-path-multi-picker__go";go.setAttribute("aria-label","Open "+item.name);go.innerHTML="<span aria-hidden=\"true\">&#8250;</span>";go.addEventListener("click",function(){path.push(item.id);render();});row.appendChild(left);row.appendChild(go);panel.appendChild(row);});current.textContent=path.length?"/ "+path.map(function(id){return map[id]?map[id].name:"";}).join(" / "):"/";summary.textContent=selected.length?"Selected: "+selected.map(function(id){return map[id]?map[id].label||map[id].name:"";}).filter(Boolean).join(", "):"No Job Paths selected.";back.disabled=!path.length;};var update=function(){render();};back.addEventListener("click",function(){path.pop();render();});render();}());</script>';
    return $html;
  }

  public static function expand_job_path_ids(array $term_ids): array {
    $expanded = [];
    foreach (array_unique(array_filter(array_map('intval', $term_ids), static function($term_id) { return $term_id > 0; })) as $term_id) {
      $expanded[] = $term_id;
      if (taxonomy_exists('eum_job_path')) {
        $children = get_term_children($term_id, 'eum_job_path');
        if (!is_wp_error($children)) $expanded = array_merge($expanded, array_map('intval', (array)$children));
      }
    }
    return array_values(array_unique(array_filter($expanded, static function($term_id) { return $term_id > 0; })));
  }

  private function render_frontend_input(string $field_id, array $def, $raw, int $target_user_id, int $actor_user_id = 0): string {
    $label = (string)($def['label'] ?? $field_id);
    $type = (string)($def['type'] ?? 'text');
    $name = 'eum_pf[' . $field_id . ']';

    ob_start();
    echo '<p style="margin-bottom:14px;">';
    echo '<label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html($label) . '</label>';

    if ($type === 'public_meta_override') {
      $state = is_array($raw) ? $raw : ['enabled' => false, 'value' => ''];
      echo '<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;"><input type="checkbox" name="' . esc_attr($name) . '[enabled]" value="1" ' . checked(!empty($state['enabled']), true, false) . '> Override inherited value</label>';
      echo '<input type="text" name="' . esc_attr($name) . '[value]" value="' . esc_attr((string)($state['value'] ?? '')) . '" style="min-width:320px;max-width:520px;width:100%;">';
      if ((string)($def['meta_key'] ?? '') === 'public_labels') echo '<br><span class="description">Overrides Job Path, Job Title, and activation-status values for this user. Enter labels separated by commas; enabled blank clears inherited labels.</span>';
      else echo '<br><span class="description">Overrides Job Path, Job Title, and activation-status values for this user. An enabled blank value clears inherited metadata.</span>';
    } elseif ($type === 'job_title_select') {
      $job_term_id = (int)self::get_field_raw_value($target_user_id, 'tax:eum_job_path');
      echo self::render_job_title_select_html($name, (string)$raw, $job_term_id, 'Selected in Job Path', false);
      echo '<br><span class="description">Job Title is selected from the Job Path field above. Titles are managed centrally in Enterprise Users → Job Titles.</span>';
    } elseif ($type === 'worker_type_select') {
      echo self::render_worker_type_select_html($name, (string)$raw);
      echo '<br><span class="description">Badges independent contractors and freelancers on org charts and profiles.</span>';
    } elseif ($type === 'division_select') {
      $current = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::normalize_division_key((string)$raw) : sanitize_title((string)$raw);
      echo '<select name="' . esc_attr($name) . '" style="min-width:320px;max-width:520px;">';
      echo '<option value="">— Inherit from Job Path —</option>';
      if (class_exists('EUM_Company_Divisions')) {
        foreach (EUM_Company_Divisions::options_for_select() as $division_key => $division_label) {
          echo '<option value="' . esc_attr($division_key) . '" ' . selected($current, $division_key, false) . '>' . esc_html($division_label) . '</option>';
        }
      }
      echo '</select>';
      echo '<br><span class="description">IT and HR can override the inherited company division when needed.</span>';
    } elseif ($type === 'textarea') {
      echo '<textarea name="' . esc_attr($name) . '" rows="3" style="width:100%;max-width:520px;">' . esc_textarea((string)$raw) . '</textarea>';
    } elseif ($type === 'taxonomy_single') {
      $tax = (string)($def['taxonomy'] ?? '');
      $tax_obj = $tax ? get_taxonomy($tax) : null;
      if ($tax_obj && !empty($tax_obj->hierarchical)) {
        $args = [
          'placeholder' => ($field_id === 'tax:eum_job_path') ? '— Select a Job Path —' : '— Select —',
          'required' => false,
          'none_label' => '— None —',
          'enforce_manageable_job_paths' => ($field_id === 'tax:eum_job_path'),
        ];
        if ($field_id === 'tax:eum_job_path') {
          $args['job_title_input_name'] = 'eum_pf[custom:job_title]';
          $args['job_title_current'] = (string)self::get_field_raw_value($target_user_id, 'custom:job_title');
          echo self::render_job_path_directory_picker($name, (int)$raw, $actor_user_id, $args);
        } else {
          echo self::render_taxonomy_directory_picker($tax, $name, (int)$raw, $actor_user_id, $args);
        }
      } else {
        echo '<select name="' . esc_attr($name) . '" style="min-width:320px;">';
        echo '<option value="0">— None —</option>';
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name']);
        if (!is_wp_error($terms)) {
          foreach ($terms as $term) {
            $selected = ((int)$raw === (int)$term->term_id) ? 'selected' : '';
            echo '<option value="' . (int)$term->term_id . '" ' . $selected . '>' . esc_html($term->name) . '</option>';
          }
        }
        echo '</select>';
      }
    } elseif ($type === 'taxonomy_multi') {
      $tax = (string)($def['taxonomy'] ?? '');
      $selected_ids = array_values(array_map('intval', (array)$raw));
      echo '<select name="' . esc_attr($name) . '[]" multiple size="6" style="min-width:320px;">';
      $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name']);
      if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
          $selected = in_array((int)$term->term_id, $selected_ids, true) ? 'selected' : '';
          echo '<option value="' . (int)$term->term_id . '" ' . $selected . '>' . esc_html($term->name) . '</option>';
        }
      }
      echo '</select>';
    } elseif ($type === 'user_select') {
      $users = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
      echo '<select name="' . esc_attr($name) . '" style="min-width:320px;">';
      echo '<option value="0">— None —</option>';
      foreach ($users as $u) {
        $selected = ((int)$raw === (int)$u->ID) ? 'selected' : '';
        $label_out = $u->display_name ?: $u->user_login;
        if ((int)$u->ID === $target_user_id) continue;
        echo '<option value="' . (int)$u->ID . '" ' . $selected . '>' . esc_html($label_out . ' (#' . (int)$u->ID . ')') . '</option>';
      }
      echo '</select>';
    } else {
      $html_type = in_array($type, ['text', 'email', 'url', 'number', 'date', 'password'], true) ? $type : 'text';
      echo '<input type="' . esc_attr($html_type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string)$raw) . '" style="width:100%;max-width:520px;">';
    }

    echo '</p>';
    return ob_get_clean();
  }

  private function can_manage_personnel_files(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    return self::is_manager_of_target($actor_user_id, $target_user_id) || self::is_pending_manager_for_target($actor_user_id, $target_user_id);
  }

  private function render_employment_section(int $target_user_id, int $actor_user_id): string {
    if (!class_exists('EUM_Personnel')) return '';

    $is_terminated = EUM_Personnel::is_terminated($target_user_id);
    $is_rehirable = EUM_Personnel::is_rehirable($target_user_id);
    $terminated_at = EUM_Personnel::terminated_at($target_user_id);
    $can_manage = self::can_manage_employment_action($actor_user_id, $target_user_id);
    $can_reactivate = self::can_reactivate_target($actor_user_id, $target_user_id);
    $can_manage_eligibility = self::can_manage_rehire_eligibility_action($actor_user_id, $target_user_id);
    $can_mark_non_rehirable = self::can_mark_non_rehirable($actor_user_id);
    $reason_options = self::reason_options_for_actor($actor_user_id, $target_user_id);
    $settings = self::get_settings();
    $default_effective = '';
    if ($can_manage && !EUM_Access::is_it_or_hr($actor_user_id) && !empty($settings['force_next_sunday'])) {
      $default_effective = self::next_sunday_midnight();
    }
    $effective_value = esc_attr($default_effective ? str_replace(' ', 'T', substr($default_effective, 0, 16)) : '');

    ob_start();
    echo '<div class="eum-private-profile__section" style="margin-top:24px;">';
    echo '<h4 style="margin-top:0;">Employment Status</h4>';
    $status_label = $is_terminated && class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::terminated_label_for_viewer($actor_user_id) : ($is_terminated ? 'Terminated' : 'Active');
    echo '<p><strong>' . esc_html($status_label) . '</strong>';
    if ($is_terminated) {
      echo ' · ' . esc_html($is_rehirable ? 'Rehirable' : 'Non-rehireable');
      if ($terminated_at !== '') echo ' · ' . esc_html($terminated_at);
    }
    echo '</p>';
    echo '<p class="description">Termination, reactivation, and rehire-eligibility changes run through the profile change log so they can be tracked, scheduled, approved, and audited the same way as other profile edits.</p>';

    if (!$can_manage) {
      echo '</div>';
      return ob_get_clean();
    }

    if ($is_terminated && !$can_reactivate) {
      echo '<p class="description">This user is marked non-rehireable, so only HR or IT can reactivate them.</p>';
    }

    if (!$is_terminated) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;display:grid;gap:12px;max-width:680px;">';
      echo '<input type="hidden" name="action" value="eum_profile_employment_action">';
      echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
      echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
      wp_nonce_field('eum_profile_employment_' . $target_user_id, 'eum_profile_employment_nonce');

      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Change reason</label><select name="eum_change_reason" style="min-width:320px;max-width:520px;"><option value="">— Select a reason —</option>';
      foreach ($reason_options as $reason_label) {
        echo '<option value="' . esc_attr($reason_label) . '">' . esc_html($reason_label) . '</option>';
      }
      echo '</select></p>';

      echo '<input type="hidden" name="employment_action_mode" value="terminate">';
      if ($can_mark_non_rehirable) {
        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Rehire Eligibility</label><select name="eum_rehirable" style="min-width:320px;max-width:520px;"><option value="1">Rehirable</option><option value="0">Non-rehireable</option></select></p>';
      } else {
        echo '<input type="hidden" name="eum_rehirable" value="1">';
        echo '<p class="description">Only HR can mark a terminated user as non-rehireable. This termination will keep the user rehirable.</p>';
      }
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Effective At</label><input type="datetime-local" name="eum_effective_at" value="' . $effective_value . '"><br><span class="description">Leave blank to apply immediately. With Sunday enforcement enabled, manager-originated requests default to next Sunday at 12:00 AM.</span></p>';
      echo '<p><button type="submit" class="button button-secondary">Terminate User</button></p>';
      echo '</form>';
    } else {
      if ($can_manage_eligibility) {
        $show_eligibility_form = $can_mark_non_rehirable || !$is_rehirable;
        if ($show_eligibility_form) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;display:grid;gap:12px;max-width:680px;">';
          echo '<input type="hidden" name="action" value="eum_profile_employment_action">';
          echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
          echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
          wp_nonce_field('eum_profile_employment_' . $target_user_id, 'eum_profile_employment_nonce');

          echo '<input type="hidden" name="employment_action_mode" value="eligibility">';
          echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Change reason</label><select name="eum_change_reason" style="min-width:320px;max-width:520px;"><option value="">— Select a reason —</option>';
          foreach ($reason_options as $reason_label) {
            echo '<option value="' . esc_attr($reason_label) . '">' . esc_html($reason_label) . '</option>';
          }
          echo '</select></p>';

          if ($can_mark_non_rehirable) {
            echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Rehire Eligibility</label><select name="eum_rehirable" style="min-width:320px;max-width:520px;">';
            echo '<option value="1" ' . selected($is_rehirable, true, false) . '>Rehirable</option>';
            echo '<option value="0" ' . selected($is_rehirable, false, false) . '>Non-rehireable</option>';
            echo '</select></p>';
          } else {
            echo '<input type="hidden" name="eum_rehirable" value="1">';
            echo '<p class="description">Only HR can mark a terminated user as non-rehireable. IT can schedule or apply a move back to rehirable status.</p>';
          }

          echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Effective At</label><input type="datetime-local" name="eum_effective_at" value="' . $effective_value . '"><br><span class="description">Use this to change rehire eligibility later without reactivating the user.</span></p>';
          echo '<p><button type="submit" class="button button-secondary">Update Rehire Eligibility</button></p>';
          echo '</form>';
        }
      }

      if ($can_reactivate) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;display:grid;gap:12px;max-width:680px;">';
        echo '<input type="hidden" name="action" value="eum_profile_employment_action">';
        echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
        wp_nonce_field('eum_profile_employment_' . $target_user_id, 'eum_profile_employment_nonce');

        echo '<input type="hidden" name="employment_action_mode" value="reactivate">';
        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Change reason</label><select name="eum_change_reason" style="min-width:320px;max-width:520px;"><option value="">— Select a reason —</option>';
        foreach ($reason_options as $reason_label) {
          echo '<option value="' . esc_attr($reason_label) . '">' . esc_html($reason_label) . '</option>';
        }
        echo '</select></p>';
        echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Effective At</label><input type="datetime-local" name="eum_effective_at" value="' . $effective_value . '"><br><span class="description">Leave blank to apply immediately. HR and IT can reactivate any terminated user. Managers can reactivate only users marked rehirable.</span></p>';
        echo '<p><button type="submit" class="button button-secondary">Reactivate User</button></p>';
        echo '</form>';
      }
    }

    echo '</div>';
    return ob_get_clean();
  }

  public function handle_frontend_employment_action(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');

    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)$_POST['return_url']) : home_url('/');
    $mode = isset($_POST['employment_action_mode']) ? sanitize_key((string)$_POST['employment_action_mode']) : '';

    if ($target_user_id <= 0 || !self::can_manage_employment_action($actor_user_id, $target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'You do not have permission to change employment status for this user.', 'error');
    }

    if (!isset($_POST['eum_profile_employment_nonce']) || !wp_verify_nonce($_POST['eum_profile_employment_nonce'], 'eum_profile_employment_' . $target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'Security check failed.', 'error');
    }

    if (!in_array($mode, ['terminate', 'reactivate', 'eligibility'], true)) {
      $this->redirect_with_profile_msg($return_url, 'Invalid employment action.', 'error');
    }

    if ($mode === 'reactivate' && !self::can_reactivate_target($actor_user_id, $target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'You do not have permission to reactivate this user.', 'error');
    }

    if ($mode === 'eligibility' && !self::can_manage_rehire_eligibility_action($actor_user_id, $target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'You do not have permission to change rehire eligibility for this user.', 'error');
    }

    if ($mode === 'terminate' && class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'This user is already terminated.', 'error');
    }

    if (($mode === 'reactivate' || $mode === 'eligibility') && class_exists('EUM_Personnel') && !EUM_Personnel::is_terminated($target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'This employment action only applies to terminated users.', 'error');
    }

    $reason = sanitize_text_field((string)($_POST['eum_change_reason'] ?? ''));
    if ($reason === '' || !in_array($reason, self::reason_options_for_actor($actor_user_id, $target_user_id), true)) {
      $this->redirect_with_profile_msg($return_url, 'Please choose a valid reason.', 'error');
    }

    $settings = self::get_settings();
    $effective_at = self::parse_datetime_local((string)($_POST['eum_effective_at'] ?? ''));
    if (!EUM_Access::is_it_or_hr($actor_user_id) && $actor_user_id !== $target_user_id && $effective_at === '' && !empty($settings['force_next_sunday'])) {
      $effective_at = self::next_sunday_midnight();
    }

    $before = self::employment_before_for_user($target_user_id);
    $after = $before;

    if ($mode === 'terminate') {
      $requested_rehirable = (isset($_POST['eum_rehirable']) && (string)$_POST['eum_rehirable'] === '0') ? 0 : 1;
      if (!$requested_rehirable && !self::can_mark_non_rehirable($actor_user_id)) {
        $this->redirect_with_profile_msg($return_url, 'Only HR can mark a user as non-rehireable.', 'error');
      }
      $after['system:eum_terminated'] = 1;
      $after['system:eum_rehirable'] = $requested_rehirable;
      $after['system:eum_terminated_at'] = $effective_at;
    } elseif ($mode === 'reactivate') {
      $after['system:eum_terminated'] = 0;
      $after['system:eum_terminated_at'] = '';
    } else {
      $requested_rehirable = (isset($_POST['eum_rehirable']) && (string)$_POST['eum_rehirable'] === '0') ? 0 : 1;
      if (!$requested_rehirable && !self::can_mark_non_rehirable($actor_user_id)) {
        $this->redirect_with_profile_msg($return_url, 'Only HR can mark a user as non-rehireable.', 'error');
      }
      $after['system:eum_rehirable'] = $requested_rehirable;
    }

    if (wp_json_encode($before) === wp_json_encode($after)) {
      $this->redirect_with_profile_msg($return_url, 'No employment-status changes were detected.', 'error');
    }

    $requires_approval = !EUM_Access::is_it_or_hr($actor_user_id);
    $approver_ids = $requires_approval ? self::approver_ids_for_actor($actor_user_id, $target_user_id) : [];
    $status = $requires_approval ? 'pending' : ($effective_at !== '' ? 'scheduled' : 'applied');

    $target_user = get_user_by('id', $target_user_id);
    if ($mode === 'terminate') {
      $title_prefix = 'Termination Change - ';
    } elseif ($mode === 'reactivate') {
      $title_prefix = 'Reactivation Change - ';
    } else {
      $title_prefix = 'Rehire Eligibility Change - ';
    }
    $title = $title_prefix . ($target_user ? ($target_user->display_name ?: $target_user->user_login) : ('User #' . $target_user_id));
    $change_id = (int)wp_insert_post([
      'post_type' => self::CPT_CHANGE,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $actor_user_id,
    ]);

    if ($change_id <= 0) {
      $this->redirect_with_profile_msg($return_url, 'Could not save the employment change.', 'error');
    }

    update_post_meta($change_id, self::META_TARGET_USER, $target_user_id);
    update_post_meta($change_id, self::META_ACTOR_USER, $actor_user_id);
    update_post_meta($change_id, self::META_APPROVER_IDS, $approver_ids);
    update_post_meta($change_id, self::META_BEFORE, $before);
    update_post_meta($change_id, self::META_AFTER, $after);
    update_post_meta($change_id, self::META_NOTE, $reason);
    update_post_meta($change_id, self::META_EFFECTIVE_AT, $effective_at);
    update_post_meta($change_id, self::META_OCCURRED_AT, current_time('mysql'));
    update_post_meta($change_id, self::META_STATUS, $status);
    update_post_meta($change_id, self::META_MANUAL, 0);

    if ($status === 'applied') {
      if (!$this->try_apply_change($change_id)) {
        $this->redirect_with_profile_msg($return_url, 'Could not apply the employment change right now.', 'error');
      }
      if ($mode === 'terminate') {
        $message = 'User terminated.';
      } elseif ($mode === 'reactivate') {
        $message = 'User reactivated.';
      } else {
        $message = 'Rehire eligibility updated.';
      }
      $this->redirect_with_profile_msg($return_url, $message, 'success');
    }

    if ($status === 'scheduled') {
      if ($mode === 'terminate') {
        $message = 'Termination scheduled.';
      } elseif ($mode === 'reactivate') {
        $message = 'Reactivation scheduled.';
      } else {
        $message = 'Rehire eligibility change scheduled.';
      }
      $this->redirect_with_profile_msg($return_url, $message, 'success');
    }

    if ($mode === 'terminate') {
      $message = 'Termination request submitted for approval.';
    } elseif ($mode === 'reactivate') {
      $message = 'Reactivation request submitted for approval.';
    } else {
      $message = 'Rehire eligibility request submitted for approval.';
    }
    do_action('eum_profile_change_pending', $change_id, $target_user_id, $approver_ids, $actor_user_id);
    $this->redirect_with_profile_msg($return_url, $message, 'success');
  }

  private function render_personnel_section(int $target_user_id, int $actor_user_id): string {
    $files = class_exists('EUM_Personnel') ? EUM_Personnel::get_files($target_user_id) : [];
    $can_manage = $this->can_manage_personnel_files($actor_user_id, $target_user_id);

    ob_start();
    echo '<div class="eum-private-profile__section">';
    echo '<h4 style="margin-top:0;">Personnel Files</h4>';
    echo '<p class="description">This stays separate from editable profile fields so team-file uploads and removals can be managed on their own.</p>';
    if (empty($files)) {
      echo '<p>No personnel files uploaded yet.</p>';
    } else {
      echo '<ul class="eum-personnel-files">';
      foreach ($files as $idx => $file) {
        echo '<li><div><a href="' . esc_url((string)($file['url'] ?? '')) . '" target="_blank" rel="noopener">' . esc_html((string)($file['name'] ?? 'file')) . '</a><span class="eum-personnel-files__meta">' . esc_html((string)($file['type'] ?? 'application/octet-stream')) . (!empty($file['uploaded_at']) ? ' · ' . esc_html((string)$file['uploaded_at']) : '') . '</span></div>';
        if ($can_manage) echo '<label><input type="checkbox" name="eum_delete_file[]" value="' . (int)$idx . '" form="eum-personnel-form-' . (int)$target_user_id . '"> Remove</label>';
        echo '</li>';
      }
      echo '</ul>';
    }
    if ($can_manage) {
      echo '<form id="eum-personnel-form-' . (int)$target_user_id . '" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
      echo '<input type="hidden" name="action" value="eum_profile_personnel_save"><input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
      wp_nonce_field('eum_profile_personnel_' . $target_user_id, 'eum_personnel_frontend_nonce');
       echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Change reason</label><select name="eum_change_reason" style="min-width:320px;max-width:520px;"><option value="">— Select a reason —</option>';
      foreach (self::reason_options_for_actor($actor_user_id, $target_user_id) as $reason_label) echo '<option value="' . esc_attr($reason_label) . '">' . esc_html($reason_label) . '</option>';
      echo '</select></p>';
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Upload file</label><input type="file" name="eum_personnel_upload"></p><p><button type="submit" class="button">Save Personnel Files</button></p></form>';
    } elseif ($actor_user_id === $target_user_id) {
      echo '<p class="description">Personnel files are view-only here. Managers, HR, and IT can upload or remove them in the team section.</p>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  public function handle_frontend_personnel_save(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)$_POST['return_url']) : home_url('/');
    if ($target_user_id <= 0 || !$this->can_manage_personnel_files($actor_user_id, $target_user_id)) $this->redirect_with_profile_msg($return_url, 'You do not have permission to manage personnel files.', 'error');
    if (!isset($_POST['eum_personnel_frontend_nonce']) || !wp_verify_nonce($_POST['eum_personnel_frontend_nonce'], 'eum_profile_personnel_' . $target_user_id)) $this->redirect_with_profile_msg($return_url, 'Security check failed.', 'error');
    $reason = sanitize_text_field((string)($_POST['eum_change_reason'] ?? ''));
    if ($reason === '' || !in_array($reason, self::reason_options_for_actor($actor_user_id, $target_user_id), true)) $this->redirect_with_profile_msg($return_url, 'Please choose a valid reason.', 'error');
    $deleted = !empty($_POST['eum_delete_file']) && is_array($_POST['eum_delete_file']) ? EUM_Personnel::delete_file_indices($target_user_id, (array)$_POST['eum_delete_file']) : [];
    $uploaded = EUM_Personnel::upload_from_request($target_user_id, 'eum_personnel_upload');
    if (empty($deleted) && empty($uploaded)) $this->redirect_with_profile_msg($return_url, 'No personnel file changes were detected.', 'error');
    $settings = self::get_settings();
    if (!empty($settings['logging_enabled'])) {
      $target_user = get_user_by('id', $target_user_id);
      $change_id = (int)wp_insert_post(['post_type' => self::CPT_CHANGE, 'post_status' => 'publish', 'post_title' => 'Personnel File Update - ' . ($target_user ? ($target_user->display_name ?: $target_user->user_login) : ('User #' . $target_user_id)), 'post_author' => $actor_user_id]);
      if ($change_id > 0) {
        update_post_meta($change_id, self::META_TARGET_USER, $target_user_id);
        update_post_meta($change_id, self::META_ACTOR_USER, $actor_user_id);
        update_post_meta($change_id, self::META_BEFORE, []);
        update_post_meta($change_id, self::META_AFTER, []);
        update_post_meta($change_id, self::META_NOTE, $reason);
        update_post_meta($change_id, self::META_OCCURRED_AT, current_time('mysql'));
        update_post_meta($change_id, self::META_STATUS, 'applied');
        update_post_meta($change_id, self::META_MANUAL, 0);
      }
    }
    $this->redirect_with_profile_msg($return_url, 'Personnel files updated.', 'success');
  }

  public function handle_frontend_save(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');

    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)$_POST['return_url']) : home_url('/');

    if ($target_user_id <= 0 || !self::can_edit_target($actor_user_id, $target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'You do not have permission to edit this profile.', 'error');
    }

    if (!isset($_POST['eum_profile_nonce']) || !wp_verify_nonce($_POST['eum_profile_nonce'], 'eum_profile_save_' . $target_user_id)) {
      $this->redirect_with_profile_msg($return_url, 'Security check failed.', 'error');
    }

    $editable = self::editable_fields_for_actor($actor_user_id, $target_user_id);
    if (empty($editable)) {
      $this->redirect_with_profile_msg($return_url, 'No editable fields are available for your role.', 'error');
    }

    $posted = isset($_POST['eum_pf']) && is_array($_POST['eum_pf']) ? $_POST['eum_pf'] : [];
    $catalog = self::field_catalog();
    $before = [];
    $after = [];

    foreach ($editable as $field_id) {
      if (!isset($catalog[$field_id])) continue;
      $def = $catalog[$field_id];
      $before[$field_id] = self::get_field_raw_value($target_user_id, $field_id);
      $after[$field_id] = $this->normalize_posted_field($field_id, $def, $posted[$field_id] ?? null);
    }

    if (!EUM_Access::is_it_or_hr($actor_user_id) && $actor_user_id !== $target_user_id && array_key_exists('tax:eum_job_path', $after)) {
      $new_job_term = (int)$after['tax:eum_job_path'];
      if ($new_job_term > 0 && !self::actor_can_manage_job_term($actor_user_id, $new_job_term)) {
        $this->redirect_with_profile_msg($return_url, 'Managers can only move job titles within branches they manage. Change the manager first, then the new boss can continue the pending update.', 'error');
      }
    }

    if (array_key_exists('tax:eum_job_path', $after)) {
      $new_job_term = (int)$after['tax:eum_job_path'];
      if ($new_job_term > 0 && class_exists('EUM_Company_Divisions')) {
        $username_basis = '';
        $target_user = get_user_by('id', $target_user_id);
        if ($target_user) $username_basis = (string)$target_user->user_login;
        $override_division = EUM_Access::is_it_or_hr($actor_user_id) ? (string)($after['core:job_company'] ?? '') : '';
        $derived = EUM_Company_Divisions::derive_transfer_values($username_basis, $new_job_term, $override_division);
        if ($derived['division'] !== '') {
          $before['core:job_company'] = self::get_field_raw_value($target_user_id, 'core:job_company');
          $after['core:job_company'] = $derived['division'];
        }
        if ($derived['email'] === '') {
          $this->redirect_with_profile_msg($return_url, 'This Job Path does not resolve to a Company Division with an email domain. Configure the division domain first.', 'error');
        }
        $before['core:user_email'] = self::get_field_raw_value($target_user_id, 'core:user_email');
        $after['core:user_email'] = $derived['email'];
      }
    }

    if (class_exists('EUM_Taxonomies') && (array_key_exists('tax:eum_job_path', $after) || array_key_exists('tax:eum_location', $after))) {
      $effective_mapping = EUM_Taxonomies::effective_term_meta_mapping_for_user_state($target_user_id, $after);
      [$mapping_before, $mapping_after] = EUM_Taxonomies::mapping_change_set_for_user($target_user_id, $effective_mapping);
      foreach ($mapping_after as $field_id => $value) {
        $before[$field_id] = $mapping_before[$field_id] ?? ($before[$field_id] ?? null);
        $after[$field_id] = $value;
      }
    }

    $has_changes = false;
    foreach ($after as $field_id => $value) {
      if ($this->values_differ($before[$field_id] ?? null, $value)) {
        $has_changes = true;
        break;
      }
    }
    if (!$has_changes) {
      $this->redirect_with_profile_msg($return_url, 'No changes were detected.', 'error');
    }

    $change_id = isset($_POST['change_id']) ? intval($_POST['change_id']) : 0;
    if ($change_id <= 0 && $actor_user_id !== $target_user_id) {
      $change_id = $this->find_editable_transfer_change_for_form($target_user_id, $actor_user_id);
    }
    $change_note = sanitize_text_field((string)($_POST['eum_change_reason'] ?? ''));
    if ($change_note === '' || !in_array($change_note, self::reason_options_for_actor($actor_user_id, $target_user_id), true)) {
      $this->redirect_with_profile_msg($return_url, 'Please choose a valid reason.', 'error');
    }
    $effective_at = self::parse_datetime_local((string)($_POST['eum_effective_at'] ?? ''));
    $skip_log = EUM_Access::is_it_or_hr($actor_user_id) && !empty($_POST['eum_skip_log']);
    $skip_email = EUM_Access::is_it_or_hr($actor_user_id) && !empty($_POST['eum_skip_email']);
    $settings = self::get_settings();

    $transfer_effective = self::normalize_transfer_effective_at_for_save($actor_user_id, $target_user_id, $effective_at, $after, $change_id);
    if (!empty($transfer_effective['error'])) {
      $this->redirect_with_profile_msg($return_url, (string)$transfer_effective['error'], 'error');
    }
    if (!empty($transfer_effective['is_transfer'])) {
      $effective_at = (string)$transfer_effective['effective_at'];
    } elseif (!EUM_Access::is_it_or_hr($actor_user_id) && $actor_user_id !== $target_user_id && $effective_at === '' && !empty($settings['force_next_sunday'])) {
      $effective_at = self::next_sunday_midnight();
    }

    $transfer_context = self::transfer_protocol_context($target_user_id, $after, $change_id);
    $requires_approval = self::manager_change_requires_approval($actor_user_id, $target_user_id, array_keys($after));
    $approver_ids = $requires_approval ? self::approver_ids_for_actor($actor_user_id, $target_user_id, $after, $change_id) : [];
    $status = $requires_approval ? 'pending' : ($effective_at !== '' ? 'scheduled' : 'applied');

    if ($change_id > 0) {
      $change = get_post($change_id);
      if (!$change || $change->post_type !== self::CPT_CHANGE) {
        $this->redirect_with_profile_msg($return_url, 'Pending change not found.', 'error');
      }
      $current_status = (string)get_post_meta($change_id, self::META_STATUS, true);
      if (!in_array($current_status, ['pending', 'scheduled'], true)) {
        $this->redirect_with_profile_msg($return_url, 'Only pending or scheduled changes can be edited.', 'error');
      }
      $existing_target = (int)get_post_meta($change_id, self::META_TARGET_USER, true);
      if ($existing_target !== $target_user_id) {
        $this->redirect_with_profile_msg($return_url, 'This pending change belongs to a different user.', 'error');
      }
      if (!$this->can_manage_change($change_id, $actor_user_id, $target_user_id)) {
        $this->redirect_with_profile_msg($return_url, 'You cannot edit this pending change.', 'error');
      }

      update_post_meta($change_id, self::META_AFTER, $after);
      update_post_meta($change_id, self::META_NOTE, $change_note);
      update_post_meta($change_id, self::META_EFFECTIVE_AT, $effective_at);
      update_post_meta($change_id, self::META_APPROVER_IDS, $approver_ids);
      update_post_meta($change_id, self::META_SKIP_LOG, $skip_log ? '1' : '0');
      update_post_meta($change_id, self::META_SKIP_EMAIL, $skip_email ? '1' : '0');
      if (!empty($transfer_context['is_transfer'])) {
        update_post_meta($change_id, self::META_TRANSFER_PROTOCOL, '1');
        update_post_meta($change_id, self::META_TRANSFER_OLD_MANAGER, (int)($transfer_context['old_manager_user_id'] ?? 0));
        update_post_meta($change_id, self::META_TRANSFER_NEW_MANAGER, (int)($transfer_context['new_manager_user_id'] ?? 0));
        if (!empty($transfer_effective['proposed_max_at'])) update_post_meta($change_id, self::META_TRANSFER_PROPOSED_MAX_AT, (string)$transfer_effective['proposed_max_at']);
        elseif ((string)get_post_meta($change_id, self::META_TRANSFER_PROPOSED_MAX_AT, true) === '') delete_post_meta($change_id, self::META_TRANSFER_PROPOSED_MAX_AT);
      }
      if ($requires_approval) update_post_meta($change_id, self::META_STATUS, 'pending');
      if (!$requires_approval) update_post_meta($change_id, self::META_STATUS, $status);

      if (!$requires_approval && $status === 'applied') {
        if (!$this->try_apply_change($change_id)) {
          $this->redirect_with_profile_msg($return_url, 'Could not apply the updated change right now.', 'error');
        }
      }

      $this->redirect_with_profile_msg($return_url, 'Pending change updated.', 'success');
    }

    $target_user = get_user_by('id', $target_user_id);
    $title = 'Profile Change - ' . ($target_user ? ($target_user->display_name ?: $target_user->user_login) : ('User #' . $target_user_id));

    $change_id = (int)wp_insert_post([
      'post_type' => self::CPT_CHANGE,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $actor_user_id,
    ]);

    if ($change_id <= 0) {
      $this->redirect_with_profile_msg($return_url, 'Could not save the change request.', 'error');
    }

    update_post_meta($change_id, self::META_TARGET_USER, $target_user_id);
    update_post_meta($change_id, self::META_ACTOR_USER, $actor_user_id);
    update_post_meta($change_id, self::META_APPROVER_IDS, $approver_ids);
    update_post_meta($change_id, self::META_BEFORE, $before);
    update_post_meta($change_id, self::META_AFTER, $after);
    update_post_meta($change_id, self::META_NOTE, $change_note);
    update_post_meta($change_id, self::META_EFFECTIVE_AT, $effective_at);
    update_post_meta($change_id, self::META_OCCURRED_AT, current_time('mysql'));
    update_post_meta($change_id, self::META_STATUS, $status);
    update_post_meta($change_id, self::META_MANUAL, 0);
    update_post_meta($change_id, self::META_SKIP_LOG, $skip_log ? '1' : '0');
    update_post_meta($change_id, self::META_SKIP_EMAIL, $skip_email ? '1' : '0');
    if (!empty($transfer_context['is_transfer'])) {
      update_post_meta($change_id, self::META_TRANSFER_PROTOCOL, '1');
      update_post_meta($change_id, self::META_TRANSFER_OLD_MANAGER, (int)($transfer_context['old_manager_user_id'] ?? 0));
      update_post_meta($change_id, self::META_TRANSFER_NEW_MANAGER, (int)($transfer_context['new_manager_user_id'] ?? 0));
      if (!empty($transfer_effective['proposed_max_at'])) update_post_meta($change_id, self::META_TRANSFER_PROPOSED_MAX_AT, (string)$transfer_effective['proposed_max_at']);
    }

    if ($status === 'applied') {
      if (!$this->try_apply_change($change_id)) {
        $this->redirect_with_profile_msg($return_url, 'Could not apply the profile changes right now.', 'error');
      }
      $this->redirect_with_profile_msg($return_url, 'Changes applied.', 'success');
    }

    if ($status === 'scheduled') {
      $this->redirect_with_profile_msg($return_url, 'Changes saved and scheduled.', 'success');
    }

    if ($requires_approval) {
      do_action('eum_profile_change_pending', $change_id, $target_user_id, $approver_ids, $actor_user_id);
    }
    $this->redirect_with_profile_msg($return_url, $requires_approval ? 'Change request submitted for approval.' : 'Changes applied.', 'success');
  }

  private function normalize_posted_field(string $field_id, array $def, $posted) {
    $type = (string)($def['type'] ?? 'text');

    if ($type === 'public_meta_override' && class_exists('EUM_Public_Meta')) {
      $posted = is_array($posted) ? $posted : [];
      $key = (string)($def['meta_key'] ?? '');
      return [
        'enabled' => !empty($posted['enabled']),
        'value' => EUM_Public_Meta::sanitize_value($key, $posted['value'] ?? ''),
      ];
    }

    if ($type === 'taxonomy_multi') {
      $vals = is_array($posted) ? $posted : [];
      $vals = array_values(array_filter(array_map('intval', $vals), fn($v) => $v > 0));
      sort($vals);
      return $vals;
    }

    if ($type === 'taxonomy_single') {
      return max(0, intval($posted));
    }

    if ($type === 'user_select') {
      return max(0, intval($posted));
    }

    if ($type === 'division_select') {
      return class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::normalize_division_key($posted) : sanitize_title(is_scalar($posted) ? (string)$posted : '');
    }

    if ($field_id === 'custom:worker_type' || $type === 'worker_type_select') {
      return self::normalize_worker_type($posted);
    }

    if ($field_id === 'custom:job_title' || $type === 'job_title_select') {
      $job_term_id = 0;
      if (!empty($_POST['eum_pf']) && is_array($_POST['eum_pf']) && isset($_POST['eum_pf']['tax:eum_job_path'])) {
        $job_term_id = max(0, (int)$_POST['eum_pf']['tax:eum_job_path']);
      }
      return self::normalize_job_title_value($posted, $job_term_id);
    }

    if ($field_id === 'core:user_pass') {
      return is_string($posted) ? (string)$posted : '';
    }

    if (str_starts_with($field_id, 'custom:')) {
      return EUM_Fields::sanitize_field_value(substr($field_id, 7), $type, $posted);
    }

    $posted = is_scalar($posted) ? (string)$posted : '';
    switch ($field_id) {
      case 'core:user_email': return sanitize_email($posted);
      case 'core:first_name':
      case 'core:last_name':
      case 'core:display_name':
      default:
        if ($type === 'textarea') return sanitize_textarea_field($posted);
        if ($type === 'url') return esc_url_raw($posted);
        if ($type === 'number') return is_numeric($posted) ? (string)$posted : '';
        if ($type === 'date') return preg_replace('/[^0-9\-]/', '', $posted);
        return sanitize_text_field($posted);
    }
  }

  private function values_differ($a, $b): bool {
    if (is_array($a) || is_array($b)) {
      return wp_json_encode($a) !== wp_json_encode($b);
    }
    return (string)$a !== (string)$b;
  }

  public function handle_change_action(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();

    $change_id = isset($_POST['change_id']) ? intval($_POST['change_id']) : 0;
    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)$_POST['return_url']) : home_url('/');
    $action = isset($_POST['eum_change_action']) ? sanitize_key((string)$_POST['eum_change_action']) : '';

    if ($change_id <= 0 || !isset($_POST['eum_change_nonce']) || !wp_verify_nonce($_POST['eum_change_nonce'], 'eum_change_action_' . $change_id)) {
      $this->redirect_with_profile_msg($return_url, 'Security check failed.', 'error');
    }

    $change = get_post($change_id);
    if (!$change || $change->post_type !== self::CPT_CHANGE) {
      $this->redirect_with_profile_msg($return_url, 'Change request not found.', 'error');
    }

    $status = (string)get_post_meta($change_id, self::META_STATUS, true);
    $target_user_id = (int)get_post_meta($change_id, self::META_TARGET_USER, true);

    if ($action === 'approve') {
      if (!$this->can_approve_change($change_id, $actor_user_id)) {
        $this->redirect_with_profile_msg($return_url, 'You cannot approve this change.', 'error');
      }
      update_post_meta($change_id, self::META_APPROVED_BY, $actor_user_id);
      update_post_meta($change_id, self::META_APPROVED_AT, current_time('mysql'));
      $effective_at = (string)get_post_meta($change_id, self::META_EFFECTIVE_AT, true);
      if ($effective_at !== '') {
        update_post_meta($change_id, self::META_STATUS, 'scheduled');
        $this->redirect_with_profile_msg($return_url, 'Change approved and scheduled.', 'success');
      }
      if (!$this->try_apply_change($change_id, $actor_user_id)) {
        $this->redirect_with_profile_msg($return_url, 'Could not approve and apply this change right now.', 'error');
      }
      $this->redirect_with_profile_msg($return_url, 'Change approved and applied.', 'success');
    }

    if ($action === 'reject') {
      if (!$this->can_approve_change($change_id, $actor_user_id)) {
        $this->redirect_with_profile_msg($return_url, 'You cannot reject this change.', 'error');
      }
      update_post_meta($change_id, self::META_STATUS, 'rejected');
      update_post_meta($change_id, self::META_APPROVED_BY, $actor_user_id);
      update_post_meta($change_id, self::META_APPROVED_AT, current_time('mysql'));
      $this->redirect_with_profile_msg($return_url, 'Change rejected.', 'success');
    }

    if ($action === 'cancel') {
      if (!$this->can_manage_change($change_id, $actor_user_id, $target_user_id)) {
        $this->redirect_with_profile_msg($return_url, 'You cannot cancel this change.', 'error');
      }
      if (!in_array($status, ['pending', 'scheduled'], true)) {
        $this->redirect_with_profile_msg($return_url, 'Only pending or scheduled changes can be cancelled.', 'error');
      }
      update_post_meta($change_id, self::META_STATUS, 'cancelled');
      $this->redirect_with_profile_msg($return_url, 'Change cancelled.', 'success');
    }

    $this->redirect_with_profile_msg($return_url, 'Unknown action.', 'error');
  }

  public function apply_due_changes(): void {
    $items = get_posts([
      'post_type' => self::CPT_CHANGE,
      'post_status' => 'publish',
      'numberposts' => 100,
      'meta_query' => [
        [
          'key' => self::META_STATUS,
          'value' => 'scheduled',
        ],
      ],
    ]);

    $now = current_time('mysql');
    foreach ((array)$items as $item) {
      $effective_at = (string)get_post_meta((int)$item->ID, self::META_EFFECTIVE_AT, true);
      if ($effective_at === '' || $effective_at > $now) continue;
      $this->try_apply_change((int)$item->ID);
    }
  }

  public function try_apply_change(int $change_id, int $approved_by_user_id = 0): bool {
    try {
      $this->apply_change($change_id, $approved_by_user_id);
      if ($change_id > 0) {
        delete_post_meta($change_id, self::META_APPLY_ERROR);
      }
      return true;
    } catch (\Throwable $e) {
      if ($change_id > 0 && get_post($change_id)) {
        update_post_meta($change_id, self::META_STATUS, 'failed');
        update_post_meta($change_id, self::META_APPLY_ERROR, sanitize_text_field($e->getMessage()));
      }
      $this->log_runtime_issue('apply_change', $e, $change_id);
      return false;
    }
  }

  public function apply_change(int $change_id, int $approved_by_user_id = 0): void {
    $change = get_post($change_id);
    if (!$change || $change->post_type !== self::CPT_CHANGE) return;

    $target_user_id = (int)get_post_meta($change_id, self::META_TARGET_USER, true);
    $after = get_post_meta($change_id, self::META_AFTER, true);
    $after = is_array($after) ? $after : [];

    if (class_exists('EUM_Company_Divisions') && !empty($after['tax:eum_job_path'])) {
      $target_user = get_user_by('id', $target_user_id);
      $username_basis = $target_user ? (string)$target_user->user_login : '';
      $override_division = (string)($after['core:job_company'] ?? '');
      $derived = EUM_Company_Divisions::derive_transfer_values($username_basis, (int)$after['tax:eum_job_path'], $override_division);
      if (empty($after['core:job_company']) && $derived['division'] !== '') {
        $after['core:job_company'] = $derived['division'];
      }
      if (empty($after['core:user_email']) && $derived['email'] !== '') {
        $after['core:user_email'] = $derived['email'];
      }
    }

    self::store_candidate_extended_profile_for_user($target_user_id, $after);

    $catalog = self::field_catalog();
    $custom_values = class_exists('EUM_Fields') ? (array)get_user_meta($target_user_id, EUM_Fields::USER_META_KEY, true) : [];
    $employment_updates = [
      'terminated' => null,
      'rehirable' => null,
      'terminated_at' => null,
    ];
    $compensation_updates = [];
    $current_job_terms = wp_get_object_terms($target_user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($current_job_terms)) $current_job_terms = [];
    $pending_job_term_id = isset($after['tax:eum_job_path']) ? max(0, (int)$after['tax:eum_job_path']) : (!empty($current_job_terms) ? (int)$current_job_terms[0] : 0);

    foreach ($after as $field_id => $value) {
      if (str_starts_with($field_id, 'system:')) {
        switch ($field_id) {
          case 'system:eum_terminated':
            $employment_updates['terminated'] = !empty($value) ? 1 : 0;
            break;
          case 'system:eum_rehirable':
            $employment_updates['rehirable'] = !empty($value) ? 1 : 0;
            break;
          case 'system:eum_terminated_at':
            $employment_updates['terminated_at'] = is_scalar($value) ? (string)$value : '';
            break;
          case 'system:eum_compensation_type':
          case 'system:eum_compensation_amount':
          case 'system:eum_compensation_unpaid_label':
            $compensation_updates[$field_id] = $value;
            break;
        }
        continue;
      }

      if (!isset($catalog[$field_id])) continue;
      $def = $catalog[$field_id];

      if (($def['type'] ?? '') === 'public_meta_override' && class_exists('EUM_Public_Meta')) {
        EUM_Public_Meta::save_profile_override($target_user_id, (string)($def['meta_key'] ?? ''), $value);
        continue;
      }

      if (str_starts_with($field_id, 'core:')) {
        $key = substr($field_id, 5);
        switch ($key) {
          case 'first_name':
          case 'last_name':
            update_user_meta($target_user_id, $key, sanitize_text_field((string)$value));
            break;
          case 'display_name':
            wp_update_user(['ID' => $target_user_id, 'display_name' => sanitize_text_field((string)$value)]);
            break;
          case 'user_email':
            if ((string)$value !== '') wp_update_user(['ID' => $target_user_id, 'user_email' => sanitize_email((string)$value)]);
            break;
          case 'user_pass':
            if ((string)$value !== '') wp_set_password((string)$value, $target_user_id);
            break;
          case 'eum_manager_user_id':
            update_user_meta($target_user_id, EUM_Personnel::META_MANAGER_ID, max(0, (int)$value));
            break;
          case 'job_company':
            if (class_exists('EUM_Company_Divisions')) {
              EUM_Company_Divisions::save_user_division_input($target_user_id, (string)$value, true, $pending_job_term_id);
            } else {
              $division_value = sanitize_title((string)$value);
              if ($division_value !== '') update_user_meta($target_user_id, 'job_company', $division_value);
              else delete_user_meta($target_user_id, 'job_company');
            }
            break;
        }
        continue;
      }

      if (str_starts_with($field_id, 'tax:')) {
        $tax = (string)($def['taxonomy'] ?? '');
        if ($tax === '') continue;
        if (($def['type'] ?? '') === 'taxonomy_single') {
          $tid = max(0, (int)$value);
          wp_set_object_terms($target_user_id, $tid ? [$tid] : [], $tax, false);
        } else {
          $vals = array_values(array_filter(array_map('intval', (array)$value), fn($v) => $v > 0));
          wp_set_object_terms($target_user_id, $vals, $tax, false);
        }
        continue;
      }

      if (str_starts_with($field_id, 'custom:')) {
        $custom_key = (string)($def['custom_key'] ?? '');
        if ($custom_key === '') continue;
        $custom_values[$custom_key] = EUM_Fields::sanitize_field_value($custom_key, (string)($def['type'] ?? 'text'), $value);
      }
    }

    if (class_exists('EUM_Fields')) {
      update_user_meta($target_user_id, EUM_Fields::USER_META_KEY, $custom_values);
    }

    if (($employment_updates['terminated'] !== null || $employment_updates['rehirable'] !== null || $employment_updates['terminated_at'] !== null) && class_exists('EUM_Personnel')) {
      $change_actor_id = (int)get_post_meta($change_id, self::META_ACTOR_USER, true);
      $actor_for_status = $approved_by_user_id > 0 ? $approved_by_user_id : $change_actor_id;
      $terminated = ($employment_updates['terminated'] !== null) ? !empty($employment_updates['terminated']) : EUM_Personnel::is_terminated($target_user_id);
      $rehirable = $employment_updates['rehirable'];
      if ($rehirable === null) $rehirable = EUM_Personnel::is_rehirable($target_user_id) ? 1 : 0;
      if (!$rehirable && !self::can_mark_non_rehirable($actor_for_status)) $rehirable = 1;
      $terminated_at = self::parse_datetime_local((string)($employment_updates['terminated_at'] ?? ''));
      if ($terminated && $terminated_at === '') $terminated_at = EUM_Personnel::terminated_at($target_user_id);
      if ($terminated && $terminated_at === '') $terminated_at = current_time('mysql');
      EUM_Personnel::set_employment_status($target_user_id, $terminated, (bool)$rehirable, $actor_for_status, $terminated_at);
      $after['system:eum_terminated'] = $terminated ? 1 : 0;
      $after['system:eum_rehirable'] = $rehirable ? 1 : 0;
      $after['system:eum_terminated_at'] = $terminated ? $terminated_at : '';
      update_post_meta($change_id, self::META_AFTER, $after);
    }

    if (!empty($compensation_updates) && class_exists('EUM_Timeclock')) {
      $change_actor_id = (int)get_post_meta($change_id, self::META_ACTOR_USER, true);
      $actor_for_comp = $approved_by_user_id > 0 ? $approved_by_user_id : $change_actor_id;
      $effective_for_comp = self::parse_datetime_local((string)get_post_meta($change_id, self::META_EFFECTIVE_AT, true));
      EUM_Timeclock::apply_compensation_payload_to_user($target_user_id, $compensation_updates, $actor_for_comp, $effective_for_comp);
      $applied_comp = EUM_Timeclock::compensation_for_user($target_user_id);
      $after['system:eum_compensation_type'] = (string)($applied_comp['type'] ?? 'unpaid');
      $after['system:eum_compensation_amount'] = (float)($applied_comp['amount'] ?? 0);
      $after['system:eum_compensation_unpaid_label'] = (string)($applied_comp['unpaid_label'] ?? '');
      update_post_meta($change_id, self::META_AFTER, $after);
    }

    if ($approved_by_user_id > 0) {
      update_post_meta($change_id, self::META_APPROVED_BY, $approved_by_user_id);
      update_post_meta($change_id, self::META_APPROVED_AT, current_time('mysql'));
    }

    if (class_exists('EUM_Taxonomies')) {
      EUM_Taxonomies::sync_user_classification_effects($target_user_id, $approved_by_user_id > 0 ? $approved_by_user_id : 0, 'Profile Classification Sync');
    } elseif (class_exists('EUM_Assignments')) {
      $syncer = new EUM_Assignments();
      if (method_exists($syncer, 'sync_user')) $syncer->sync_user($target_user_id);
    }

    update_post_meta($change_id, self::META_STATUS, 'applied');
    update_post_meta($change_id, self::META_OCCURRED_AT, current_time('mysql'));

    $skip_email = (string)get_post_meta($change_id, self::META_SKIP_EMAIL, true) === '1';
    if (!$skip_email) {
      do_action('eum_profile_change_applied', $change_id, $target_user_id);
    }

    $settings = self::get_settings();
    $skip_log = (string)get_post_meta($change_id, self::META_SKIP_LOG, true) === '1';
    if ($skip_log || empty($settings['logging_enabled'])) {
      wp_delete_post($change_id, true);
    }
  }

  public function can_approve_change(int $change_id, int $actor_user_id): bool {
    if ($actor_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    $approvers = (array)get_post_meta($change_id, self::META_APPROVER_IDS, true);
    $approvers = array_map('intval', $approvers);
    return in_array($actor_user_id, $approvers, true);
  }

  public function can_manage_change(int $change_id, int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if ($this->can_approve_change($change_id, $actor_user_id)) return true;
    $change_actor = (int)get_post_meta($change_id, self::META_ACTOR_USER, true);
    if ($change_actor === $actor_user_id) return true;
    $after = get_post_meta($change_id, self::META_AFTER, true);
    $after = is_array($after) ? $after : [];
    if ((int)($after['core:eum_manager_user_id'] ?? 0) === $actor_user_id) return true;
    if (!empty($after['tax:eum_job_path']) && self::actor_can_manage_job_term($actor_user_id, (int)$after['tax:eum_job_path'])) return true;
    return self::can_edit_target($actor_user_id, $target_user_id);
  }

  private function find_editable_transfer_change_for_form(int $target_user_id, int $actor_user_id): int {
    if ($target_user_id <= 0 || $actor_user_id <= 0) return 0;
    $items = get_posts([
      'post_type' => self::CPT_CHANGE,
      'post_status' => 'publish',
      'numberposts' => 15,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        'relation' => 'AND',
        ['key' => self::META_TARGET_USER, 'value' => $target_user_id],
        ['key' => self::META_STATUS, 'value' => ['pending', 'scheduled'], 'compare' => 'IN'],
      ],
    ]);

    foreach ((array)$items as $item) {
      $change_id = (int)$item->ID;
      $after = get_post_meta($change_id, self::META_AFTER, true);
      $after = is_array($after) ? $after : [];
      if (empty(self::transfer_protocol_context($target_user_id, $after, $change_id)['is_transfer'])) continue;
      if ($this->can_manage_change($change_id, $actor_user_id, $target_user_id)) return $change_id;
    }

    return 0;
  }

  private function get_change_after_for_form(int $change_id, int $actor_user_id, int $target_user_id): array {
    $change = get_post($change_id);
    if (!$change || $change->post_type !== self::CPT_CHANGE) return [];
    if (!$this->can_manage_change($change_id, $actor_user_id, $target_user_id)) return [];
    $status = (string)get_post_meta($change_id, self::META_STATUS, true);
    if (!in_array($status, ['pending', 'scheduled'], true)) return [];
    $after = get_post_meta($change_id, self::META_AFTER, true);
    return is_array($after) ? $after : [];
  }

  private function render_pending_changes(int $target_user_id, int $viewer_user_id): string {
    $items = get_posts([
      'post_type' => self::CPT_CHANGE,
      'post_status' => 'publish',
      'numberposts' => 15,
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        'relation' => 'AND',
        [
          'key' => self::META_TARGET_USER,
          'value' => $target_user_id,
        ],
        [
          'key' => self::META_STATUS,
          'value' => ['pending', 'scheduled'],
          'compare' => 'IN',
        ],
      ],
    ]);

    if (empty($items)) return '';

    ob_start();
    echo '<div style="margin-top:24px;">';
    echo '<h4>Pending / Scheduled Changes</h4>';
    echo '<table style="width:100%;border-collapse:collapse;">';
    echo '<thead><tr><th style="text-align:left;border-bottom:1px solid #dcdcde;padding:6px 8px;">When</th><th style="text-align:left;border-bottom:1px solid #dcdcde;padding:6px 8px;">Requested By</th><th style="text-align:left;border-bottom:1px solid #dcdcde;padding:6px 8px;">Status</th><th style="text-align:left;border-bottom:1px solid #dcdcde;padding:6px 8px;">Actions</th></tr></thead><tbody>';

    foreach ($items as $item) {
      $change_id = (int)$item->ID;
      $status = (string)get_post_meta($change_id, self::META_STATUS, true);
      $actor_user_id = (int)get_post_meta($change_id, self::META_ACTOR_USER, true);
      $effective_at = (string)get_post_meta($change_id, self::META_EFFECTIVE_AT, true);
      echo '<tr>';
      echo '<td style="padding:6px 8px;border-bottom:1px solid #f0f0f1;">' . esc_html($effective_at !== '' ? $effective_at : (string)$item->post_date) . '</td>';
      echo '<td style="padding:6px 8px;border-bottom:1px solid #f0f0f1;">' . esc_html(self::manager_user_name($actor_user_id)) . '</td>';
      echo '<td style="padding:6px 8px;border-bottom:1px solid #f0f0f1;">' . esc_html(ucfirst($status)) . '</td>';
      echo '<td style="padding:6px 8px;border-bottom:1px solid #f0f0f1;white-space:nowrap;">';
      if ($this->can_manage_change($change_id, $viewer_user_id, $target_user_id)) {
        $edit_url = add_query_arg(['eum_change_id' => $change_id], $this->current_url());
        echo '<a href="' . esc_url($edit_url) . '" style="margin-right:8px;">Edit</a>';
      }
      if ($this->can_approve_change($change_id, $viewer_user_id) && $status === 'pending') {
        echo $this->change_action_form($change_id, 'approve', 'Approve', $this->current_url());
        echo $this->change_action_form($change_id, 'reject', 'Reject', $this->current_url());
      }
      if ($this->can_manage_change($change_id, $viewer_user_id, $target_user_id)) {
        echo $this->change_action_form($change_id, 'cancel', 'Cancel', $this->current_url());
      }
      echo '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    return ob_get_clean();
  }

  private function change_action_form(int $change_id, string $action, string $label, string $return_url): string {
    $style = 'display:inline-block;margin-right:8px;';
    $btn_style = 'background:none;border:none;padding:0;color:#2271b1;cursor:pointer;text-decoration:underline;font:inherit;';
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="' . esc_attr($style) . '">';
    echo '<input type="hidden" name="action" value="eum_profile_change_action">';
    echo '<input type="hidden" name="change_id" value="' . (int)$change_id . '">';
    echo '<input type="hidden" name="eum_change_action" value="' . esc_attr($action) . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_change_action_' . $change_id, 'eum_change_nonce');
    echo '<button type="submit" style="' . esc_attr($btn_style) . '">' . esc_html($label) . '</button>';
    echo '</form>';
    return ob_get_clean();
  }

  private function render_logs_modal(int $target_user_id, int $viewer_user_id, string $context): string {
    if (!EUM_Access::is_it_or_hr($viewer_user_id) && $viewer_user_id !== $target_user_id && !self::is_manager_of_target($viewer_user_id, $target_user_id) && !self::is_pending_manager_for_target($viewer_user_id, $target_user_id)) {
      return '';
    }

    $items = $this->get_filtered_change_posts([
      'target_user_id' => $target_user_id,
      'actor_user_id' => 0,
      'status' => '',
      'sort_by' => 'occurred',
      'sort_dir' => 'desc',
    ], 100);

    $items = array_values(array_filter($items, function($item) {
      $status = (string)get_post_meta((int)$item->ID, self::META_STATUS, true);
      return in_array($status, ['candidate', 'applied'], true);
    }));

    if (!empty($items)) {
      $items = array_slice($items, 0, 25);
    }

    if (empty($items)) return '';

    $popup_id = 'eum-log-' . $context . '-' . $target_user_id . '-' . wp_rand(1000, 9999);
    ob_start();
    echo '<a href="#" class="eum-popup-trigger" data-eum-popup-target="' . esc_attr($popup_id) . '" data-eum-popup-title="Profile History">Last Update / History</a>';
    echo '<div id="' . esc_attr($popup_id) . '" class="eum-popup-content" hidden><div><h4 style="margin-top:0;">Recent Profile History</h4>';
    foreach ($items as $item) {
      $change_id = (int)$item->ID;
      $actor_id = (int)get_post_meta($change_id, self::META_ACTOR_USER, true);
      $reason = (string)get_post_meta($change_id, self::META_NOTE, true);
      $status = (string)get_post_meta($change_id, self::META_STATUS, true);
      $before = get_post_meta($change_id, self::META_BEFORE, true);
      $after = get_post_meta($change_id, self::META_AFTER, true);
      $before = is_array($before) ? $before : [];
      $after = is_array($after) ? $after : [];
      $timestamp = self::change_display_timestamp($item);
      $status_label = $status !== '' ? ucfirst($status) : 'Applied';
      echo '<div class="eum-log-entry"><div class="eum-log-entry__meta">' . esc_html($status_label) . ' by ' . esc_html(self::manager_user_name($actor_id)) . ' at ' . esc_html($timestamp) . ($reason !== '' ? ', ' . esc_html($reason) : '') . '</div>';
      if (!empty($after)) {
        echo '<ul style="margin:8px 0 0 18px;">';
        foreach ($after as $field_id => $new_value) {
          $old_value = $before[$field_id] ?? null;
          if (!$this->values_differ($old_value, $new_value)) continue;
          $label = self::log_field_label($field_id);
          $old_label = $this->display_value_for_log($field_id, $old_value);
          $new_label = $this->display_value_for_log($field_id, $new_value);
          echo '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html($old_label) . ' → ' . esc_html($new_label) . '</li>';
        }
        echo '</ul>';
      }
      echo '</div>';
    }
    echo '</div></div>';
    return ob_get_clean();
  }

  private static function is_log_custom_text_value($raw): bool {
    if (!is_scalar($raw)) return false;
    $value = trim((string)$raw);
    if ($value === '') return false;
    return !preg_match('/^-?\d+$/', $value);
  }

  private static function display_scalar_log_value($raw): string {
    if ($raw === null) return '—';
    if (is_bool($raw)) return $raw ? '1' : '0';
    if (is_scalar($raw)) {
      $value = trim((string)$raw);
      return $value !== '' ? $value : '—';
    }
    return '—';
  }

  private static function sanitize_visual_log_custom_text($raw): string {
    return is_scalar($raw) ? sanitize_text_field((string)$raw) : '';
  }

  private static function visual_log_custom_text_value($value): string {
    if (self::is_log_custom_text_value($value)) {
      return trim((string)$value);
    }
    if (is_array($value)) {
      $parts = [];
      foreach ($value as $item) {
        if (self::is_log_custom_text_value($item)) {
          $parts[] = trim((string)$item);
        }
      }
      return implode(', ', array_values(array_filter($parts, static fn($part) => $part !== '')));
    }
    return '';
  }

  private static function render_visual_log_custom_text_control(string $name, string $value, string $placeholder = 'Optional custom text override'): string {
    return '<div class="eum-log-custom-text" style="margin-top:8px;"><label style="display:block;font-size:12px;font-weight:600;">Custom text override</label><input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" style="width:100%;"></div>';
  }

  private function display_taxonomy_log_value(string $taxonomy, $raw, bool $multiple = false): string {
    if (self::is_log_custom_text_value($raw)) {
      return self::display_scalar_log_value($raw);
    }

    if (!$multiple) {
      $term_id = is_scalar($raw) ? (int)$raw : 0;
      if ($term_id <= 0) return '—';
      $term = get_term($term_id, $taxonomy);
      if (!$term || is_wp_error($term)) {
        return self::display_scalar_log_value($raw);
      }
      return ($taxonomy === 'eum_job_path' || $taxonomy === 'eum_location')
        ? self::term_full_path($taxonomy, $term_id, ' / ')
        : (string)$term->name;
    }

    $items = is_array($raw) ? $raw : (is_scalar($raw) && trim((string)$raw) !== '' ? [(string)$raw] : []);
    $labels = [];
    foreach ($items as $item) {
      if (self::is_log_custom_text_value($item)) {
        $labels[] = trim((string)$item);
        continue;
      }
      $term_id = is_scalar($item) ? (int)$item : 0;
      if ($term_id <= 0) continue;
      $term = get_term($term_id, $taxonomy);
      if ($term && !is_wp_error($term)) {
        $labels[] = ($taxonomy === 'eum_job_path' || $taxonomy === 'eum_location')
          ? self::term_full_path($taxonomy, $term_id, ' / ')
          : (string)$term->name;
      } else {
        $fallback = self::display_scalar_log_value($item);
        if ($fallback !== '—') $labels[] = $fallback;
      }
    }

    return empty($labels) ? '—' : implode(', ', array_values(array_unique($labels)));
  }

  private function display_value_for_log(string $field_id, $raw): string {
    if ($field_id === 'system:eum_terminated') {
      if (self::is_log_custom_text_value($raw)) return self::display_scalar_log_value($raw);
      return !empty($raw) ? (class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::terminated_label_for_viewer((int)get_current_user_id()) : 'Terminated') : 'Active';
    }
    if ($field_id === 'system:eum_rehirable') {
      if (self::is_log_custom_text_value($raw)) return self::display_scalar_log_value($raw);
      return !empty($raw) ? 'Rehirable' : 'Non-rehireable';
    }
    if ($field_id === 'system:eum_terminated_at') return self::display_scalar_log_value($raw);
    if ($field_id === 'system:eum_compensation_type') {
      if (self::is_log_custom_text_value($raw)) return self::display_scalar_log_value($raw);
      return class_exists('EUM_Timeclock') ? EUM_Timeclock::compensation_type_label((string)$raw) : self::display_scalar_log_value($raw);
    }
    if ($field_id === 'system:eum_compensation_amount') {
      if (self::is_log_custom_text_value($raw)) return self::display_scalar_log_value($raw);
      $amount = is_scalar($raw) ? (float)$raw : 0.0;
      return '$' . number_format($amount, 2, '.', ',');
    }
    if ($field_id === 'system:eum_compensation_unpaid_label') return self::display_scalar_log_value($raw);

    $catalog = self::field_catalog();
    if (!isset($catalog[$field_id])) return is_scalar($raw) ? (string)$raw : wp_json_encode($raw);
    $def = $catalog[$field_id];
    if (($def['type'] ?? '') === 'public_meta_override') {
      if (!is_array($raw)) return 'Inherit';
      if (empty($raw['enabled'])) return 'Inherit';
      $value = (string)($raw['value'] ?? '');
      return $value !== '' ? $value : '(cleared inherited value)';
    }
    if ($field_id === 'core:eum_manager_user_id') {
      if (self::is_log_custom_text_value($raw)) return self::display_scalar_log_value($raw);
      return $raw ? (self::manager_user_name((int)$raw) ?: self::display_scalar_log_value($raw)) : '—';
    }
    if ($field_id === 'core:job_company') {
      if (self::is_log_custom_text_value($raw)) return self::display_scalar_log_value($raw);
      if (!is_scalar($raw) || (string)$raw === '') return '—';
      return class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::division_label((string)$raw) : (string)$raw;
    }
    if (($def['type'] ?? '') === 'taxonomy_single') {
      return $this->display_taxonomy_log_value((string)$def['taxonomy'], $raw, false);
    }
    if (($def['type'] ?? '') === 'taxonomy_multi') {
      return $this->display_taxonomy_log_value((string)$def['taxonomy'], $raw, true);
    }
    if (($def['type'] ?? '') === 'user_select') {
      if (self::is_log_custom_text_value($raw)) return self::display_scalar_log_value($raw);
      return $raw ? (self::manager_user_name((int)$raw) ?: self::display_scalar_log_value($raw)) : '—';
    }
    if ($field_id === 'core:user_pass') return $raw === '' ? '—' : '••••••••';
    return is_scalar($raw) && (string)$raw !== '' ? (string)$raw : '—';
  }

  private static function log_field_catalog(): array {
    $catalog = self::field_catalog();
    $catalog['system:eum_terminated'] = ['label' => 'Employment Status', 'group' => 'Employment', 'type' => 'boolean_status'];
    $catalog['system:eum_rehirable'] = ['label' => 'Rehire Eligibility', 'group' => 'Employment', 'type' => 'boolean_rehirable'];
    $catalog['system:eum_terminated_at'] = ['label' => 'Termination Effective At', 'group' => 'Employment', 'type' => 'datetime'];
    $catalog['system:eum_compensation_type'] = ['label' => 'Compensation Type', 'group' => 'Compensation', 'type' => 'text'];
    $catalog['system:eum_compensation_amount'] = ['label' => 'Compensation Amount', 'group' => 'Compensation', 'type' => 'number'];
    $catalog['system:eum_compensation_unpaid_label'] = ['label' => 'Unpaid Label / Reason', 'group' => 'Compensation', 'type' => 'text'];
    return $catalog;
  }

  private static function log_group_order(): array {
    return [
      'Core',
      'Classification',
      'Employment',
      'Compensation',
      'Custom Fields',
      'Other',
    ];
  }

  private static function order_log_payload(array $data): array {
    $catalog = self::log_field_catalog();
    $ordered = [];

    foreach (array_keys($catalog) as $field_id) {
      if (array_key_exists($field_id, $data)) {
        $ordered[$field_id] = $data[$field_id];
      }
    }

    $extras = [];
    foreach ($data as $field_id => $value) {
      if (!array_key_exists($field_id, $ordered)) {
        $extras[(string)$field_id] = $value;
      }
    }
    if (!empty($extras)) {
      ksort($extras, SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($extras as $field_id => $value) {
        $ordered[$field_id] = $value;
      }
    }

    return $ordered;
  }

  private static function pretty_log_json(array $data): string {
    $ordered = self::order_log_payload($data);
    if (empty($ordered)) return '{}';
    $json = wp_json_encode($ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) && $json !== '' ? $json : '{}';
  }

  private static function parse_log_json_payload($raw): array {
    if (is_array($raw)) return self::order_log_payload($raw);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? self::order_log_payload($decoded) : [];
  }

  private static function unknown_log_fields(array $before, array $after): array {
    $catalog = self::log_field_catalog();
    $unknown = [];
    foreach (array_merge(array_keys($before), array_keys($after)) as $field_id) {
      if (!isset($catalog[$field_id])) $unknown[] = (string)$field_id;
    }
    $unknown = array_values(array_unique($unknown));
    sort($unknown, SORT_NATURAL | SORT_FLAG_CASE);
    return $unknown;
  }

  private function taxonomy_option_map(string $taxonomy): array {
    static $cache = [];
    if (isset($cache[$taxonomy])) return $cache[$taxonomy];
    $cache[$taxonomy] = [];
    if (!taxonomy_exists($taxonomy)) return $cache[$taxonomy];

    $terms = get_terms([
      'taxonomy' => $taxonomy,
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
    ]);
    if (is_wp_error($terms)) return $cache[$taxonomy];

    foreach ((array)$terms as $term) {
      $term_id = (int)($term->term_id ?? 0);
      if ($term_id <= 0) continue;
      $cache[$taxonomy][$term_id] = self::term_full_path($taxonomy, $term_id, ' / ');
    }
    return $cache[$taxonomy];
  }

  private static function datetime_local_input_value($value): string {
    if (!is_scalar($value)) return '';
    $raw = self::parse_datetime_local((string)$value);
    return $raw !== '' ? str_replace(' ', 'T', substr($raw, 0, 16)) : '';
  }

  private function render_log_editor_control(string $field_id, array $def, string $name, $value, array $users): string {
    $type = (string)($def['type'] ?? 'text');
    $attr_name = esc_attr($name);
    $custom_name = $name . '_custom_text';
    $custom_value = self::visual_log_custom_text_value($value);

    if ($field_id === 'system:eum_terminated') {
      $current = self::is_log_custom_text_value($value) ? '' : (($value === '' || $value === null) ? '' : (!empty($value) ? '1' : '0'));
      $html = '<select name="' . $attr_name . '"><option value="">— Select —</option><option value="0" ' . selected($current, '0', false) . '>Active</option><option value="1" ' . selected($current, '1', false) . '>Terminated</option></select>';
      return $html . self::render_visual_log_custom_text_control($custom_name, $custom_value, 'Optional historical status text');
    }

    if ($field_id === 'system:eum_rehirable') {
      $current = self::is_log_custom_text_value($value) ? '' : (($value === '' || $value === null) ? '' : (!empty($value) ? '1' : '0'));
      $html = '<select name="' . $attr_name . '"><option value="">— Select —</option><option value="1" ' . selected($current, '1', false) . '>Rehirable</option><option value="0" ' . selected($current, '0', false) . '>Non-rehireable</option></select>';
      return $html . self::render_visual_log_custom_text_control($custom_name, $custom_value, 'Optional historical status text');
    }

    if ($field_id === 'system:eum_terminated_at' || $type === 'datetime') {
      return '<input type="datetime-local" name="' . $attr_name . '" value="' . esc_attr(self::datetime_local_input_value($value)) . '">';
    }

    if ($type === 'division_select') {
      $current = self::is_log_custom_text_value($value) ? '' : (class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::normalize_division_key((string)$value) : sanitize_title((string)$value));
      $html = '<select name="' . $attr_name . '"><option value="">— Inherit / None —</option>';
      if (class_exists('EUM_Company_Divisions')) {
        foreach (EUM_Company_Divisions::options_for_select() as $division_key => $division_label) {
          $html .= '<option value="' . esc_attr($division_key) . '" ' . selected($current, $division_key, false) . '>' . esc_html($division_label) . '</option>';
        }
      }
      $html .= '</select>';
      return $html . self::render_visual_log_custom_text_control($custom_name, $custom_value, 'Optional division label override');
    }

    if ($type === 'user_select') {
      $html = '<select name="' . $attr_name . '"><option value="0">— None —</option>';
      foreach ($users as $u) {
        $label = $u->display_name ?: $u->user_login;
        $selected_user_id = self::is_log_custom_text_value($value) ? 0 : (int)$value;
        $html .= '<option value="' . (int)$u->ID . '" ' . selected($selected_user_id, (int)$u->ID, false) . '>' . esc_html($label . ' (#' . (int)$u->ID . ')') . '</option>';
      }
      $html .= '</select>';
      return $html . self::render_visual_log_custom_text_control($custom_name, $custom_value, 'Optional user label override');
    }

    if ($type === 'taxonomy_single') {
      $taxonomy = (string)($def['taxonomy'] ?? '');
      $selected_term_id = self::is_log_custom_text_value($value) ? 0 : (int)$value;
      $html = '<select name="' . $attr_name . '"><option value="0">— None —</option>';
      foreach ($this->taxonomy_option_map($taxonomy) as $term_id => $label) {
        $html .= '<option value="' . (int)$term_id . '" ' . selected($selected_term_id, (int)$term_id, false) . '>' . esc_html($label) . '</option>';
      }
      $html .= '</select>';
      return $html . self::render_visual_log_custom_text_control($custom_name, $custom_value, 'Optional historical label override');
    }

    if ($type === 'taxonomy_multi') {
      $selected_values = array_values(array_filter(array_map('intval', is_array($value) ? $value : [])));
      $taxonomy = (string)($def['taxonomy'] ?? '');
      $html = '<select name="' . $attr_name . '[]" multiple size="4">';
      foreach ($this->taxonomy_option_map($taxonomy) as $term_id => $label) {
        $html .= '<option value="' . (int)$term_id . '" ' . selected(in_array((int)$term_id, $selected_values, true), true, false) . '>' . esc_html($label) . '</option>';
      }
      $html .= '</select>';
      return $html . self::render_visual_log_custom_text_control($custom_name, $custom_value, 'Optional custom text instead of selected terms');
    }

    if ($type === 'textarea') {
      return '<textarea name="' . $attr_name . '" rows="3">' . esc_textarea(is_scalar($value) ? (string)$value : '') . '</textarea>';
    }

    $input_type = 'text';
    if (in_array($type, ['email', 'url', 'number', 'date'], true)) $input_type = $type;
    if ($field_id === 'core:user_pass') $input_type = 'text';

    $step = $input_type === 'number' ? ' step="any"' : '';
    return '<input type="' . esc_attr($input_type) . '" name="' . $attr_name . '" value="' . esc_attr(is_scalar($value) ? (string)$value : '') . '"' . $step . '>';
  }

  private function render_visual_log_editor(string $name_prefix, array $before, array $after, array $users): string {
    $catalog = self::log_field_catalog();
    $groups = [];
    $group_order_index = array_flip(self::log_group_order());
    foreach ($catalog as $field_id => $def) {
      $group = (string)($def['group'] ?? 'Other');
      if (!isset($groups[$group])) $groups[$group] = [];
      $groups[$group][$field_id] = $def;
    }
    uksort($groups, static function ($a, $b) use ($group_order_index) {
      $a_index = $group_order_index[$a] ?? 999;
      $b_index = $group_order_index[$b] ?? 999;
      if ($a_index === $b_index) return strnatcasecmp((string)$a, (string)$b);
      return $a_index <=> $b_index;
    });

    $unknown_fields = self::unknown_log_fields($before, $after);

    ob_start();
    echo '<div class="eum-visual-log-editor">';
    echo '<p class="description" style="margin-top:0;">Check the fields you want this log entry to track, then edit the before and after values directly. Unchecked fields are not stored in the log. Option-based fields also include a custom text override box for historical labels that no longer exist in today\'s dropdowns.</p>';
    if (!empty($unknown_fields)) {
      $unknown_html = implode(', ', array_map(static fn($field_id) => '<code>' . esc_html((string)$field_id) . '</code>', $unknown_fields));
      echo '<div class="notice notice-warning inline"><p>This log also contains fields that are not available in the visual editor: ' . $unknown_html . '. Use JSON mode to edit or preserve them.</p></div>';
    }
    foreach ($groups as $group_label => $fields) {
      echo '<div class="eum-visual-log-group">';
      echo '<h4>' . esc_html($group_label) . '</h4>';
      echo '<table class="widefat striped eum-visual-log-grid"><thead><tr><th style="width:70px;">Track</th><th style="width:220px;">Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
      foreach ($fields as $field_id => $def) {
        $enabled = array_key_exists($field_id, $before) || array_key_exists($field_id, $after);
        $label = (string)($def['label'] ?? $field_id);
        $before_value = $before[$field_id] ?? null;
        $after_value = $after[$field_id] ?? null;
        echo '<tr>';
        echo '<td><label><input type="checkbox" name="' . esc_attr($name_prefix . '[' . $field_id . '][enabled]') . '" value="1" ' . checked($enabled, true, false) . '> Use</label></td>';
        echo '<td><strong>' . esc_html($label) . '</strong><br><code>' . esc_html($field_id) . '</code></td>';
        echo '<td>' . $this->render_log_editor_control($field_id, $def, $name_prefix . '[' . $field_id . '][before_value]', $before_value, $users) . '</td>';
        echo '<td>' . $this->render_log_editor_control($field_id, $def, $name_prefix . '[' . $field_id . '][after_value]', $after_value, $users) . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
      echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_json_log_editor(string $before_name, string $after_name, array $before, array $after): string {
    ob_start();
    echo '<div class="eum-json-log-editor">';
    echo '<p class="description" style="margin-top:0;">JSON mode is useful when you need to preserve or edit values that no longer appear as choices in the visual editor. You can also enter custom free-text values for historical records, even for fields that normally use dropdowns or taxonomy terms.</p>';
    echo '<div class="eum-json-log-grid">';
    echo '<p><label><strong>Before JSON</strong></label><br><textarea name="' . esc_attr($before_name) . '" rows="12" class="large-text code">' . esc_textarea(self::pretty_log_json($before)) . '</textarea></p>';
    echo '<p><label><strong>After JSON</strong></label><br><textarea name="' . esc_attr($after_name) . '" rows="12" class="large-text code">' . esc_textarea(self::pretty_log_json($after)) . '</textarea></p>';
    echo '</div>';
    echo '</div>';
    return ob_get_clean();
  }

  private function parse_visual_log_editor_payload($payload): array {
    $payload = is_array($payload) ? $payload : [];
    $catalog = self::log_field_catalog();
    $before = [];
    $after = [];

    foreach ($catalog as $field_id => $def) {
      $row = (isset($payload[$field_id]) && is_array($payload[$field_id])) ? $payload[$field_id] : [];
      if (empty($row['enabled'])) continue;

      $before_custom = self::sanitize_visual_log_custom_text($row['before_value_custom_text'] ?? $row['before_custom_text'] ?? '');
      $after_custom = self::sanitize_visual_log_custom_text($row['after_value_custom_text'] ?? $row['after_custom_text'] ?? '');

      if ($field_id === 'system:eum_terminated' || $field_id === 'system:eum_rehirable') {
        $before[$field_id] = ($before_custom !== '') ? $before_custom : ((((string)($row['before_value'] ?? '') === '1')) ? 1 : 0);
        $after[$field_id] = ($after_custom !== '') ? $after_custom : ((((string)($row['after_value'] ?? '') === '1')) ? 1 : 0);
        continue;
      }

      if ($field_id === 'system:eum_terminated_at') {
        $before[$field_id] = self::parse_datetime_local((string)($row['before_value'] ?? ''));
        $after[$field_id] = self::parse_datetime_local((string)($row['after_value'] ?? ''));
        continue;
      }

      $type = (string)($def['type'] ?? 'text');
      if ($type === 'taxonomy_multi') {
        $before[$field_id] = ($before_custom !== '') ? $before_custom : $this->normalize_posted_field($field_id, $def, (array)($row['before_value'] ?? []));
        $after[$field_id] = ($after_custom !== '') ? $after_custom : $this->normalize_posted_field($field_id, $def, (array)($row['after_value'] ?? []));
        continue;
      }

      $before[$field_id] = ($before_custom !== '') ? $before_custom : $this->normalize_posted_field($field_id, $def, $row['before_value'] ?? null);
      $after[$field_id] = ($after_custom !== '') ? $after_custom : $this->normalize_posted_field($field_id, $def, $row['after_value'] ?? null);
    }

    return [$before, $after];
  }

  public static function allowed_change_statuses(): array {
    return ['candidate', 'applied', 'scheduled', 'pending', 'rejected', 'cancelled', 'failed'];
  }

  private function log_runtime_issue(string $context, \Throwable $e, int $change_id = 0): void {
    $suffix = $change_id > 0 ? ' (change #' . $change_id . ')' : '';
    eum_log_message('[EUM Profiles] ' . $context . $suffix . ': ' . sanitize_text_field($e->getMessage()));
  }

  private static function admin_change_sort_options(): array {
    return [
      'occurred' => 'Occurred time',
      'target' => 'Target user',
      'actor' => 'Changed by',
      'status' => 'Status',
      'created' => 'Created time',
    ];
  }

  private static function current_change_filters_from_request(): array {
    $sort_options = array_keys(self::admin_change_sort_options());
    $statuses = self::allowed_change_statuses();

    $filters = [
      'target_user_id' => isset($_REQUEST['filter_target_user_id']) ? max(0, intval($_REQUEST['filter_target_user_id'])) : 0,
      'actor_user_id' => isset($_REQUEST['filter_actor_user_id']) ? max(0, intval($_REQUEST['filter_actor_user_id'])) : 0,
      'status' => isset($_REQUEST['filter_status']) ? sanitize_key((string)$_REQUEST['filter_status']) : '',
      'sort_by' => isset($_REQUEST['sort_by']) ? sanitize_key((string)$_REQUEST['sort_by']) : 'occurred',
      'sort_dir' => isset($_REQUEST['sort_dir']) ? strtolower(sanitize_key((string)$_REQUEST['sort_dir'])) : 'desc',
    ];

    if (!in_array($filters['status'], $statuses, true)) $filters['status'] = '';
    if (!in_array($filters['sort_by'], $sort_options, true)) $filters['sort_by'] = 'occurred';
    if (!in_array($filters['sort_dir'], ['asc', 'desc'], true)) $filters['sort_dir'] = 'desc';

    return $filters;
  }

  private static function change_created_timestamp($item): int {
    $raw = '';
    if (!empty($item->post_date_gmt) && $item->post_date_gmt !== '0000-00-00 00:00:00') {
      $raw = (string)$item->post_date_gmt;
    } elseif (!empty($item->post_date)) {
      $raw = (string)$item->post_date;
    }
    $ts = $raw !== '' ? strtotime($raw) : 0;
    return $ts ?: 0;
  }

  private static function change_occurred_timestamp($item): int {
    $occurred_at = (string)get_post_meta((int)$item->ID, self::META_OCCURRED_AT, true);
    $ts = $occurred_at !== '' ? strtotime($occurred_at) : 0;
    if (!$ts) $ts = self::change_created_timestamp($item);
    return $ts ?: 0;
  }

  private static function change_display_timestamp($item): string {
    $occurred_at = (string)get_post_meta((int)$item->ID, self::META_OCCURRED_AT, true);
    if ($occurred_at !== '') return $occurred_at;
    return (string)($item->post_date ?? '');
  }

  private static function sort_text_value($value): string {
    $value = is_scalar($value) ? (string)$value : wp_json_encode($value);
    $value = function_exists('remove_accents') ? remove_accents($value) : $value;
    return strtolower(trim($value));
  }

  private static function compare_change_items($a, $b, string $sort_by, string $sort_dir): int {
    $direction = ($sort_dir === 'asc') ? 1 : -1;

    switch ($sort_by) {
      case 'target':
        $cmp = strcmp(
          self::sort_text_value(self::manager_user_name((int)get_post_meta((int)$a->ID, self::META_TARGET_USER, true))),
          self::sort_text_value(self::manager_user_name((int)get_post_meta((int)$b->ID, self::META_TARGET_USER, true)))
        );
        break;
      case 'actor':
        $cmp = strcmp(
          self::sort_text_value(self::manager_user_name((int)get_post_meta((int)$a->ID, self::META_ACTOR_USER, true))),
          self::sort_text_value(self::manager_user_name((int)get_post_meta((int)$b->ID, self::META_ACTOR_USER, true)))
        );
        break;
      case 'status':
        $cmp = strcmp(
          self::sort_text_value((string)get_post_meta((int)$a->ID, self::META_STATUS, true)),
          self::sort_text_value((string)get_post_meta((int)$b->ID, self::META_STATUS, true))
        );
        break;
      case 'created':
        $cmp = self::change_created_timestamp($a) <=> self::change_created_timestamp($b);
        break;
      case 'occurred':
      default:
        $cmp = self::change_occurred_timestamp($a) <=> self::change_occurred_timestamp($b);
        break;
    }

    if ($cmp === 0) {
      $cmp = self::change_created_timestamp($a) <=> self::change_created_timestamp($b);
    }
    if ($cmp === 0) {
      $cmp = ((int)$a->ID) <=> ((int)$b->ID);
    }

    return $cmp * $direction;
  }

  private function get_filtered_change_posts(array $filters = [], int $limit = 100): array {
    $defaults = [
      'target_user_id' => 0,
      'actor_user_id' => 0,
      'status' => '',
      'sort_by' => 'occurred',
      'sort_dir' => 'desc',
    ];
    $filters = array_merge($defaults, $filters);

    $args = [
      'post_type' => self::CPT_CHANGE,
      'post_status' => 'publish',
      'numberposts' => -1,
      'orderby' => 'date',
      'order' => 'DESC',
    ];

    $meta_query = [];
    if (!empty($filters['target_user_id'])) {
      $meta_query[] = ['key' => self::META_TARGET_USER, 'value' => (int)$filters['target_user_id']];
    }
    if (!empty($filters['actor_user_id'])) {
      $meta_query[] = ['key' => self::META_ACTOR_USER, 'value' => (int)$filters['actor_user_id']];
    }
    if (!empty($filters['status']) && in_array($filters['status'], self::allowed_change_statuses(), true)) {
      $meta_query[] = ['key' => self::META_STATUS, 'value' => (string)$filters['status']];
    }
    if (!empty($meta_query)) {
      if (count($meta_query) > 1) $meta_query['relation'] = 'AND';
      $args['meta_query'] = $meta_query;
    }

    $items = get_posts($args);
    if (empty($items)) return [];

    usort($items, function($a, $b) use ($filters) {
      return self::compare_change_items($a, $b, (string)$filters['sort_by'], (string)$filters['sort_dir']);
    });

    if ($limit > -1) {
      $items = array_slice($items, 0, $limit);
    }

    return array_values($items);
  }

  private static function resolve_import_user_id(array $row, string $prefix): int {
    $id_key = $prefix . '_user_id';
    if (!empty($row[$id_key])) {
      $id = intval($row[$id_key]);
      if ($id > 0 && get_user_by('id', $id)) return $id;
    }

    $login_keys = [$prefix . '_user_login', $prefix . '_user_email', $prefix . '_user'];
    foreach ($login_keys as $key) {
      if (empty($row[$key])) continue;
      $raw = trim((string)$row[$key]);
      if ($raw === '') continue;
      if (is_email($raw)) {
        $u = get_user_by('email', sanitize_email($raw));
      } else {
        $u = get_user_by('login', sanitize_user($raw));
      }
      if ($u) return (int)$u->ID;
    }

    return 0;
  }

  private static function csv_bool_value($value): int {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'y'], true) ? 1 : 0;
  }

  public function render_changes_page(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');

    $filters = self::current_change_filters_from_request();
    $all_items = $this->get_filtered_change_posts($filters, -1);
    $total_items = count($all_items);
    $items = array_slice($all_items, 0, 250);
    $users = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
    $reason_options = self::get_settings()['reason_options'];

    echo '<div class="wrap"><h1>Profile Changes</h1>';
    echo '<p>Review pending changes, see the audit trail, add retroactive entries, sort by user or change time, and import/export logs in bulk.</p>';
    if (!empty($_GET['eum_msg'])) {
      $type = !empty($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
      $cls = ($type === 'error') ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($cls) . '"><p>' . esc_html((string)$_GET['eum_msg']) . '</p></div>';
    }

    echo '<div class="postbox" style="padding:16px;margin-bottom:18px;">';
    echo '<h2 style="margin-top:0;">Audit Log Filters and Export</h2>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" data-eum-save-filters="1" data-eum-filter-context="profile-change-log" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">';
    echo '<input type="hidden" name="page" value="eum-profile-changes">';
    echo '<p style="margin:0;"><label><strong>Target User</strong></label><br><select name="filter_target_user_id"><option value="0">All targets</option>';
    foreach ($users as $u) {
      $label = $u->display_name ?: $u->user_login;
      echo '<option value="' . (int)$u->ID . '" ' . selected((int)$filters['target_user_id'], (int)$u->ID, false) . '>' . esc_html($label . ' (#' . (int)$u->ID . ')') . '</option>';
    }
    echo '</select></p>';
    echo '<p style="margin:0;"><label><strong>Changed By</strong></label><br><select name="filter_actor_user_id"><option value="0">All actors</option>';
    foreach ($users as $u) {
      $label = $u->display_name ?: $u->user_login;
      echo '<option value="' . (int)$u->ID . '" ' . selected((int)$filters['actor_user_id'], (int)$u->ID, false) . '>' . esc_html($label . ' (#' . (int)$u->ID . ')') . '</option>';
    }
    echo '</select></p>';
    echo '<p style="margin:0;"><label><strong>Status</strong></label><br><select name="filter_status"><option value="">All statuses</option>';
    foreach (self::allowed_change_statuses() as $status_option) {
      echo '<option value="' . esc_attr($status_option) . '" ' . selected((string)$filters['status'], $status_option, false) . '>' . esc_html(ucfirst($status_option)) . '</option>';
    }
    echo '</select></p>';
    echo '<p style="margin:0;"><label><strong>Sort By</strong></label><br><select name="sort_by">';
    foreach (self::admin_change_sort_options() as $sort_key => $sort_label) {
      echo '<option value="' . esc_attr($sort_key) . '" ' . selected((string)$filters['sort_by'], $sort_key, false) . '>' . esc_html($sort_label) . '</option>';
    }
    echo '</select></p>';
    echo '<p style="margin:0;"><label><strong>Direction</strong></label><br><select name="sort_dir"><option value="desc" ' . selected((string)$filters['sort_dir'], 'desc', false) . '>Descending</option><option value="asc" ' . selected((string)$filters['sort_dir'], 'asc', false) . '>Ascending</option></select></p>';
    echo '<p style="margin:0;"><button type="submit" class="button button-primary">Apply</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=eum-profile-changes')) . '">Reset</a></p>';
    echo '</form>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">';
    echo '<input type="hidden" name="action" value="eum_profile_log_export">';
    wp_nonce_field('eum_profile_log_export', 'eum_profile_log_export_nonce');
    echo '<input type="hidden" name="filter_target_user_id" value="' . (int)$filters['target_user_id'] . '">';
    echo '<input type="hidden" name="filter_actor_user_id" value="' . (int)$filters['actor_user_id'] . '">';
    echo '<input type="hidden" name="filter_status" value="' . esc_attr((string)$filters['status']) . '">';
    echo '<input type="hidden" name="sort_by" value="' . esc_attr((string)$filters['sort_by']) . '">';
    echo '<input type="hidden" name="sort_dir" value="' . esc_attr((string)$filters['sort_dir']) . '">';
    echo '<button type="submit" class="button">Export Filtered CSV</button>';
    echo '<span class="description">Exports the current filtered list in the same order shown below. CSV includes before_json and after_json for mass editing and re-import.</span>';
    echo '</form>';
    echo '</div>';

    echo '<div style="display:flex;gap:24px;align-items:flex-start;">';
    echo '<div style="flex:1;min-width:360px;max-width:580px;">';
    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">Add Retroactive Log Entry</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_profile_log_save">';
    wp_nonce_field('eum_profile_log_save', 'eum_profile_log_nonce');
    echo '<p><label><strong>Target Users</strong></label><br><select name="target_user_ids[]" class="regular-text" multiple size="8" style="min-width:320px;max-width:100%;">';
    foreach ($users as $u) {
      $label = $u->display_name ?: $u->user_login;
      echo '<option value="' . (int)$u->ID . '">' . esc_html($label . ' (#' . (int)$u->ID . ')') . '</option>';
    }
    echo '</select><br><span class="description">Select one or many users. For the same target user and the same occurred-at date/time, added fields merge into one log entry instead of creating duplicates.</span></p><p><label><strong>Changed By</strong></label><br><select name="actor_user_id" class="regular-text">';
    foreach ($users as $u) {
      $label = $u->display_name ?: $u->user_login;
      echo '<option value="' . (int)$u->ID . '">' . esc_html($label . ' (#' . (int)$u->ID . ')') . '</option>';
    }
    echo '</select></p>';
    echo '<p><label><strong>Occurred At</strong></label><br><input type="datetime-local" name="occurred_at" value="' . esc_attr(date_i18n('Y-m-d\TH:i')) . '"></p>';
    echo '<p><label><strong>Status</strong></label><br><select name="status"><option value="applied">Applied</option><option value="scheduled">Scheduled</option><option value="pending">Pending</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option></select></p>';
    echo '<p><label><strong>Reason</strong></label><br><select name="change_note" class="regular-text"><option value="">— Select —</option>';
    foreach ($reason_options as $row) {
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($label !== '') echo '<option value="' . esc_attr($label) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></p>';
    echo '<h3 style="margin:20px 0 10px;">Change Editor</h3>';
    echo '<fieldset class="eum-editor-mode"><legend class="screen-reader-text">Editor Mode</legend><label><input type="radio" name="editor_mode" value="visual" checked> Visual editor</label> <label><input type="radio" name="editor_mode" value="json"> JSON editor</label></fieldset>';
    echo '<div class="eum-editor-panel" data-eum-editor-panel="visual">' . $this->render_visual_log_editor('log_fields', [], [], $users) . '</div>';
    echo '<div class="eum-editor-panel" data-eum-editor-panel="json" hidden>' . $this->render_json_log_editor('before_json', 'after_json', [], []) . '</div>';
    echo '<p><button class="button button-primary" type="submit">Save Log Entry</button></p></form></div>';

    echo '<div class="postbox" style="padding:16px;margin-top:18px;">';
    echo '<h2 style="margin-top:0;">Import Audit Log CSV</h2>';
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_profile_log_import">';
    wp_nonce_field('eum_profile_log_import', 'eum_profile_log_import_nonce');
    echo '<p><label><strong>CSV File</strong></label><br><input type="file" name="eum_log_csv" accept=".csv,text/csv"></p>';
    echo '<p><label><strong>Import Mode</strong></label><br><select name="import_mode"><option value="upsert">Create new rows and update rows with a matching change_id</option><option value="create">Create only</option><option value="update">Update only (requires change_id)</option></select></p>';
    echo '<p><button type="submit" class="button">Import CSV</button></p>';
    echo '<p class="description">Supported columns: change_id, target_user_id, target_user_login, actor_user_id, actor_user_login, status, reason, occurred_at, effective_at, manual, before_json, after_json. Export first if you want a ready-to-edit template.</p>';
    echo '</form></div></div>';

    echo '<div style="flex:2;min-width:820px;"><div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">Inline Log Editor</h2>';
    echo '<p class="description">Showing ' . (int)count($items) . ' of ' . (int)$total_items . ' matching log entries. Lists are sorted by the selected sort mode, with Occurred Time using the logged change time rather than post creation time.</p>';
    if ($total_items > count($items)) {
      echo '<p class="description">Export CSV if you need to work with more than the first 250 matching rows in one pass.</p>';
    }
    echo '<form id="eum-log-bulk-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0 22px;padding:14px;border:1px solid #dcdcde;background:#fff;">';
    echo '<input type="hidden" name="action" value="eum_profile_log_bulk_action">';
    wp_nonce_field('eum_profile_log_bulk_action', 'eum_profile_log_bulk_nonce');
    echo '<h3 style="margin-top:0;">Bulk Update / Remove Selected Logs</h3>';
    echo '<p class="description">Use the checkboxes on the log cards below, then update shared metadata, merge checked field rows into each selected entry, or delete the selection in one step.</p>';
    echo '<p style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">';
    echo '<label><strong>Action</strong><br><select name="bulk_action"><option value="update">Update selected</option><option value="delete">Delete selected</option></select></label>';
    echo '<label><strong>Occurred At</strong><br><input type="datetime-local" name="occurred_at" value=""></label>';
    echo '<label><strong>Status</strong><br><select name="status"><option value="">Keep current</option>';
    foreach (self::allowed_change_statuses() as $status_option) {
      echo '<option value="' . esc_attr($status_option) . '">' . esc_html(ucfirst($status_option)) . '</option>';
    }
    echo '</select></label>';
    echo '<label><strong>Reason</strong><br><select name="reason_mode"><option value="keep">Keep current</option><option value="set">Set to:</option><option value="clear">Clear reason</option></select></label>';
    echo '<label><strong>Reason Value</strong><br><select name="change_note"><option value="">— Select —</option>';
    foreach ($reason_options as $row) {
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($label !== '') echo '<option value="' . esc_attr($label) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label style="display:flex;align-items:center;gap:6px;margin-bottom:4px;"><input type="checkbox" onclick="document.querySelectorAll(&quot;.eum-log-bulk-checkbox&quot;).forEach(function(cb){cb.checked=this.checked;}.bind(this));"> Select all visible</label>';
    echo '</p>';
    echo '<fieldset class="eum-editor-mode"><legend class="screen-reader-text">Editor Mode</legend><label><input type="radio" name="editor_mode" value="visual" checked> Visual editor</label> <label><input type="radio" name="editor_mode" value="json"> JSON editor</label></fieldset>';
    echo '<div class="eum-editor-panel" data-eum-editor-panel="visual">' . $this->render_visual_log_editor('log_fields', [], [], $users) . '</div>';
    echo '<div class="eum-editor-panel" data-eum-editor-panel="json" hidden>' . $this->render_json_log_editor('before_json', 'after_json', [], []) . '</div>';
    echo '<p style="margin-top:14px;"><button type="submit" class="button button-primary">Run Bulk Action</button></p>';
    echo '</form>';
    foreach ((array)$items as $item) {
      $change_id = (int)$item->ID;
      $target_user_id = (int)get_post_meta($change_id, self::META_TARGET_USER, true);
      $actor_user_id = (int)get_post_meta($change_id, self::META_ACTOR_USER, true);
      $status = (string)get_post_meta($change_id, self::META_STATUS, true);
      $reason = (string)get_post_meta($change_id, self::META_NOTE, true);
      $occurred_at = (string)get_post_meta($change_id, self::META_OCCURRED_AT, true);
      $before = get_post_meta($change_id, self::META_BEFORE, true);
      $after = get_post_meta($change_id, self::META_AFTER, true);
      $before = is_array($before) ? $before : [];
      $after = is_array($after) ? $after : [];
      $summary_target = self::manager_user_name($target_user_id);
      $summary_actor = self::manager_user_name($actor_user_id);
      $summary = '#' . $change_id . ' · ' . ($summary_target !== '' ? $summary_target : ('User #' . $target_user_id)) . ' · ' . ucfirst($status ?: 'applied') . ($reason !== '' ? ' · ' . $reason : '');

      echo '<details class="eum-log-card"><summary><label style="margin-right:10px;display:inline-flex;align-items:center;gap:6px;" onclick="event.stopPropagation();"><input type="checkbox" class="eum-log-bulk-checkbox" name="change_ids[]" value="' . $change_id . '" form="eum-log-bulk-form"> Select</label><strong>' . esc_html($summary) . '</strong><span style="margin-left:10px;color:#50575e;">Changed by ' . esc_html($summary_actor !== '' ? $summary_actor : ('User #' . $actor_user_id)) . ' at ' . esc_html(self::change_display_timestamp($item)) . '</span></summary>';
      echo '<div class="eum-log-card__body">';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_profile_log_update"><input type="hidden" name="change_id" value="' . $change_id . '">';
      wp_nonce_field('eum_profile_log_update_' . $change_id, 'eum_profile_log_update_nonce');
      echo '<div class="eum-log-card__meta">';
      echo '<p><label><strong>Occurred</strong></label><br><input type="datetime-local" name="occurred_at" value="' . esc_attr(self::datetime_local_input_value($occurred_at)) . '"></p>';
      echo '<p><label><strong>Target</strong></label><br><select name="target_user_id">';
      foreach ($users as $u) {
        $label = $u->display_name ?: $u->user_login;
        echo '<option value="' . (int)$u->ID . '" ' . selected($target_user_id, (int)$u->ID, false) . '>' . esc_html($label . ' (#' . (int)$u->ID . ')') . '</option>';
      }
      echo '</select></p>';
      echo '<p><label><strong>Changed By</strong></label><br><select name="actor_user_id">';
      foreach ($users as $u) {
        $label = $u->display_name ?: $u->user_login;
        echo '<option value="' . (int)$u->ID . '" ' . selected($actor_user_id, (int)$u->ID, false) . '>' . esc_html($label . ' (#' . (int)$u->ID . ')') . '</option>';
      }
      echo '</select></p>';
      echo '<p><label><strong>Status</strong></label><br><select name="status">';
      foreach (self::allowed_change_statuses() as $status_option) {
        echo '<option value="' . esc_attr($status_option) . '" ' . selected($status, $status_option, false) . '>' . esc_html(ucfirst($status_option)) . '</option>';
      }
      echo '</select></p>';
      echo '<p><label><strong>Reason</strong></label><br><select name="change_note"><option value="">— Select —</option>';
      $rendered_reason_labels = [];
      foreach ($reason_options as $row) {
        $label = sanitize_text_field((string)($row['label'] ?? ''));
        if ($label === '') continue;
        $rendered_reason_labels[] = $label;
        echo '<option value="' . esc_attr($label) . '" ' . selected($reason, $label, false) . '>' . esc_html($label) . '</option>';
      }
      if ($reason !== '' && !in_array($reason, $rendered_reason_labels, true)) {
        echo '<option value="' . esc_attr($reason) . '" selected>' . esc_html($reason) . '</option>';
      }
      echo '</select></p>';
      echo '</div>';
      echo '<fieldset class="eum-editor-mode"><legend class="screen-reader-text">Editor Mode</legend><label><input type="radio" name="editor_mode" value="visual" checked> Visual editor</label> <label><input type="radio" name="editor_mode" value="json"> JSON editor</label></fieldset>';
      echo '<div class="eum-editor-panel" data-eum-editor-panel="visual">' . $this->render_visual_log_editor('log_fields', $before, $after, $users) . '</div>';
      echo '<div class="eum-editor-panel" data-eum-editor-panel="json" hidden>' . $this->render_json_log_editor('before_json', 'after_json', $before, $after) . '</div>';
      echo '<p style="margin-top:16px;"><button type="submit" class="button button-primary">Save Changes</button></p>';
      echo '</form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
      echo '<input type="hidden" name="action" value="eum_profile_log_delete"><input type="hidden" name="change_id" value="' . $change_id . '">';
      wp_nonce_field('eum_profile_log_delete_' . $change_id, 'eum_profile_log_delete_nonce');
      echo '<button type="submit" class="button" onclick="return confirm(\'Delete this log entry?\')">Delete</button>';
      echo '</form>';
      echo '</div></details>';
    }
    echo '</div></div></div></div>';
  }

  private static function admin_log_target_ids_from_post(): array {
    $target_ids = isset($_POST['target_user_ids']) ? (array)wp_unslash($_POST['target_user_ids']) : [];
    if (empty($target_ids) && !empty($_POST['target_user_id'])) {
      $target_ids = [wp_unslash($_POST['target_user_id'])];
    }
    $target_ids = array_values(array_unique(array_filter(array_map('intval', $target_ids), static fn($id) => $id > 0)));
    return $target_ids;
  }

  private static function find_manual_log_entry(int $target_user_id, string $occurred_at): int {
    if ($target_user_id <= 0 || $occurred_at === '') return 0;
    $items = get_posts([
      'post_type' => self::CPT_CHANGE,
      'post_status' => 'publish',
      'numberposts' => 1,
      'fields' => 'ids',
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        'relation' => 'AND',
        [
          'key' => self::META_TARGET_USER,
          'value' => $target_user_id,
        ],
        [
          'key' => self::META_OCCURRED_AT,
          'value' => $occurred_at,
        ],
        [
          'key' => self::META_MANUAL,
          'value' => 1,
        ],
      ],
    ]);
    return !empty($items[0]) ? (int)$items[0] : 0;
  }

  private static function merge_log_editor_arrays(array $existing, array $updates): array {
    foreach ($updates as $field_id => $value) {
      $existing[$field_id] = $value;
    }
    return self::order_log_payload($existing);
  }

  public function handle_admin_log_save(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_profile_log_nonce']) || !wp_verify_nonce($_POST['eum_profile_log_nonce'], 'eum_profile_log_save')) wp_die('Bad nonce.');
    $target_user_ids = self::admin_log_target_ids_from_post();
    if (empty($target_user_ids)) {
      wp_safe_redirect(admin_url('admin.php?page=eum-profile-changes&eum_msg=' . rawurlencode('Pick at least one target user.') . '&eum_type=error'));
      exit;
    }
    $actor_user_id = isset($_POST['actor_user_id']) ? intval($_POST['actor_user_id']) : 0;
    $occurred_at = self::parse_datetime_local((string)($_POST['occurred_at'] ?? ''));
    $reason = sanitize_text_field((string)($_POST['change_note'] ?? ''));
    $status = sanitize_key((string)($_POST['status'] ?? 'applied')) ?: 'applied';
    $editor_mode = sanitize_key((string)($_POST['editor_mode'] ?? 'visual'));
    if ($editor_mode === 'json') {
      $before_updates = self::parse_log_json_payload(wp_unslash($_POST['before_json'] ?? ''));
      $after_updates = self::parse_log_json_payload(wp_unslash($_POST['after_json'] ?? ''));
    } else {
      [$before_updates, $after_updates] = isset($_POST['log_fields']) ? $this->parse_visual_log_editor_payload(wp_unslash($_POST['log_fields'])) : [[], []];
    }
    $before_updates = is_array($before_updates) ? self::order_log_payload($before_updates) : [];
    $after_updates = is_array($after_updates) ? self::order_log_payload($after_updates) : [];
    $occurred_at_value = $occurred_at ?: current_time('mysql');
    $saved = 0;
    $merged = 0;

    foreach ($target_user_ids as $target_user_id) {
      $target = get_user_by('id', $target_user_id);
      $change_id = self::find_manual_log_entry($target_user_id, $occurred_at_value);
      if ($change_id > 0) {
        $existing_before = get_post_meta($change_id, self::META_BEFORE, true);
        $existing_after = get_post_meta($change_id, self::META_AFTER, true);
        $before = self::merge_log_editor_arrays(is_array($existing_before) ? $existing_before : [], $before_updates);
        $after = self::merge_log_editor_arrays(is_array($existing_after) ? $existing_after : [], $after_updates);
        wp_update_post(['ID' => $change_id, 'post_title' => 'Retroactive Profile Log - ' . ($target ? ($target->display_name ?: $target->user_login) : ('User #' . $target_user_id))]);
        update_post_meta($change_id, self::META_TARGET_USER, $target_user_id);
        update_post_meta($change_id, self::META_ACTOR_USER, $actor_user_id);
        update_post_meta($change_id, self::META_BEFORE, $before);
        update_post_meta($change_id, self::META_AFTER, $after);
        update_post_meta($change_id, self::META_NOTE, $reason);
        update_post_meta($change_id, self::META_OCCURRED_AT, $occurred_at_value);
        update_post_meta($change_id, self::META_STATUS, $status);
        update_post_meta($change_id, self::META_MANUAL, 1);
        $merged++;
        continue;
      }

      $before = $before_updates;
      $after = $after_updates;
      $change_id = (int)wp_insert_post([
        'post_type' => self::CPT_CHANGE,
        'post_status' => 'publish',
        'post_title' => 'Retroactive Profile Log - ' . ($target ? ($target->display_name ?: $target->user_login) : ('User #' . $target_user_id)),
        'post_author' => get_current_user_id(),
      ]);
      if ($change_id > 0) {
        update_post_meta($change_id, self::META_TARGET_USER, $target_user_id);
        update_post_meta($change_id, self::META_ACTOR_USER, $actor_user_id);
        update_post_meta($change_id, self::META_BEFORE, $before);
        update_post_meta($change_id, self::META_AFTER, $after);
        update_post_meta($change_id, self::META_NOTE, $reason);
        update_post_meta($change_id, self::META_OCCURRED_AT, $occurred_at_value);
        update_post_meta($change_id, self::META_STATUS, $status);
        update_post_meta($change_id, self::META_MANUAL, 1);
        $saved++;
      }
    }

    $msg = 'Retroactive log entry saved.';
    if (count($target_user_ids) > 1 || $merged > 0) {
      $msg = 'Saved ' . (int)$saved . ' new log entr' . ($saved === 1 ? 'y' : 'ies') . ' and merged into ' . (int)$merged . ' existing entr' . ($merged === 1 ? 'y' : 'ies') . '.';
    }
    wp_safe_redirect(admin_url('admin.php?page=eum-profile-changes&eum_msg=' . rawurlencode($msg)));
    exit;
  }

  public function handle_admin_log_update(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $change_id = isset($_POST['change_id']) ? intval($_POST['change_id']) : 0;
    if ($change_id <= 0 || !isset($_POST['eum_profile_log_update_nonce']) || !wp_verify_nonce($_POST['eum_profile_log_update_nonce'], 'eum_profile_log_update_' . $change_id)) wp_die('Bad nonce.');
    $change = get_post($change_id); if (!$change || $change->post_type !== self::CPT_CHANGE) wp_die('Invalid log entry.');
    $target_user_id = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0; $actor_user_id = isset($_POST['actor_user_id']) ? intval($_POST['actor_user_id']) : 0; $occurred_at = self::parse_datetime_local((string)($_POST['occurred_at'] ?? '')); $reason = sanitize_text_field((string)($_POST['change_note'] ?? '')); $status = sanitize_key((string)($_POST['status'] ?? 'applied')) ?: 'applied';
    $editor_mode = sanitize_key((string)($_POST['editor_mode'] ?? 'visual'));
    if ($editor_mode === 'json') {
      $before = self::parse_log_json_payload(wp_unslash($_POST['before_json'] ?? ''));
      $after = self::parse_log_json_payload(wp_unslash($_POST['after_json'] ?? ''));
    } else {
      [$before, $after] = isset($_POST['log_fields']) ? $this->parse_visual_log_editor_payload(wp_unslash($_POST['log_fields'])) : [[], []];
    }
    $before = is_array($before) ? self::order_log_payload($before) : [];
    $after = is_array($after) ? self::order_log_payload($after) : [];
    $target = get_user_by('id', $target_user_id); wp_update_post(['ID' => $change_id, 'post_title' => 'Profile Change - ' . ($target ? ($target->display_name ?: $target->user_login) : ('User #' . $target_user_id))]);
    update_post_meta($change_id, self::META_TARGET_USER, $target_user_id); update_post_meta($change_id, self::META_ACTOR_USER, $actor_user_id); update_post_meta($change_id, self::META_BEFORE, $before); update_post_meta($change_id, self::META_AFTER, $after); update_post_meta($change_id, self::META_NOTE, $reason); update_post_meta($change_id, self::META_OCCURRED_AT, $occurred_at ?: current_time('mysql')); update_post_meta($change_id, self::META_STATUS, $status);
    wp_safe_redirect(admin_url('admin.php?page=eum-profile-changes&eum_msg=' . rawurlencode('Log entry updated.'))); exit;
  }

  public function handle_admin_log_bulk_action(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_profile_log_bulk_nonce']) || !wp_verify_nonce($_POST['eum_profile_log_bulk_nonce'], 'eum_profile_log_bulk_action')) wp_die('Bad nonce.');

    $change_ids = isset($_POST['change_ids']) ? array_values(array_unique(array_filter(array_map('intval', (array)wp_unslash($_POST['change_ids'])), static fn($id) => $id > 0))) : [];
    if (empty($change_ids)) {
      wp_safe_redirect(admin_url('admin.php?page=eum-profile-changes&eum_msg=' . rawurlencode('Select at least one log entry first.') . '&eum_type=error'));
      exit;
    }

    $bulk_action = sanitize_key((string)($_POST['bulk_action'] ?? 'update'));
    if ($bulk_action === 'delete') {
      foreach ($change_ids as $change_id) {
        wp_delete_post($change_id, true);
      }
      wp_safe_redirect(admin_url('admin.php?page=eum-profile-changes&eum_msg=' . rawurlencode('Deleted selected log entries.')));
      exit;
    }

    $editor_mode = sanitize_key((string)($_POST['editor_mode'] ?? 'visual'));
    if ($editor_mode === 'json') {
      $before_updates = self::parse_log_json_payload(wp_unslash($_POST['before_json'] ?? ''));
      $after_updates = self::parse_log_json_payload(wp_unslash($_POST['after_json'] ?? ''));
    } else {
      [$before_updates, $after_updates] = isset($_POST['log_fields']) ? $this->parse_visual_log_editor_payload(wp_unslash($_POST['log_fields'])) : [[], []];
    }
    $before_updates = is_array($before_updates) ? self::order_log_payload($before_updates) : [];
    $after_updates = is_array($after_updates) ? self::order_log_payload($after_updates) : [];

    $occurred_at = self::parse_datetime_local((string)($_POST['occurred_at'] ?? ''));
    $status = sanitize_key((string)($_POST['status'] ?? ''));
    if (!in_array($status, self::allowed_change_statuses(), true)) $status = '';
    $reason_mode = sanitize_key((string)($_POST['reason_mode'] ?? 'keep'));
    $reason = sanitize_text_field((string)($_POST['change_note'] ?? ''));

    foreach ($change_ids as $change_id) {
      $change = get_post($change_id);
      if (!$change || $change->post_type !== self::CPT_CHANGE) continue;
      $existing_before = get_post_meta($change_id, self::META_BEFORE, true);
      $existing_after = get_post_meta($change_id, self::META_AFTER, true);
      update_post_meta($change_id, self::META_BEFORE, self::merge_log_editor_arrays(is_array($existing_before) ? $existing_before : [], $before_updates));
      update_post_meta($change_id, self::META_AFTER, self::merge_log_editor_arrays(is_array($existing_after) ? $existing_after : [], $after_updates));
      if ($occurred_at !== '') update_post_meta($change_id, self::META_OCCURRED_AT, $occurred_at);
      if ($status !== '') update_post_meta($change_id, self::META_STATUS, $status);
      if ($reason_mode === 'set') update_post_meta($change_id, self::META_NOTE, $reason);
      if ($reason_mode === 'clear') delete_post_meta($change_id, self::META_NOTE);
    }

    wp_safe_redirect(admin_url('admin.php?page=eum-profile-changes&eum_msg=' . rawurlencode('Updated selected log entries.')));
    exit;
  }

  public function handle_admin_log_delete(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $change_id = isset($_POST['change_id']) ? intval($_POST['change_id']) : 0;
    if ($change_id <= 0 || !isset($_POST['eum_profile_log_delete_nonce']) || !wp_verify_nonce($_POST['eum_profile_log_delete_nonce'], 'eum_profile_log_delete_' . $change_id)) wp_die('Bad nonce.');
    wp_delete_post($change_id, true);
    wp_safe_redirect(admin_url('admin.php?page=eum-profile-changes&eum_msg=' . rawurlencode('Log entry deleted.'))); exit;
  }

  public function handle_admin_log_export(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_profile_log_export_nonce']) || !wp_verify_nonce($_POST['eum_profile_log_export_nonce'], 'eum_profile_log_export')) wp_die('Bad nonce.');

    $filters = self::current_change_filters_from_request();
    $items = $this->get_filtered_change_posts($filters, -1);

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=eum-audit-log-' . gmdate('Ymd-His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['change_id', 'target_user_id', 'target_user_login', 'target_user_name', 'actor_user_id', 'actor_user_login', 'actor_user_name', 'status', 'reason', 'occurred_at', 'effective_at', 'manual', 'before_json', 'after_json']);
    foreach ($items as $item) {
      $change_id = (int)$item->ID;
      $target_user_id = (int)get_post_meta($change_id, self::META_TARGET_USER, true);
      $actor_user_id = (int)get_post_meta($change_id, self::META_ACTOR_USER, true);
      $target_user = $target_user_id ? get_user_by('id', $target_user_id) : null;
      $actor_user = $actor_user_id ? get_user_by('id', $actor_user_id) : null;
      $before = get_post_meta($change_id, self::META_BEFORE, true);
      $after = get_post_meta($change_id, self::META_AFTER, true);
      $before = is_array($before) ? self::order_log_payload($before) : [];
      $after = is_array($after) ? self::order_log_payload($after) : [];
      fputcsv($out, [
        $change_id,
        $target_user_id,
        $target_user ? $target_user->user_login : '',
        $target_user ? ($target_user->display_name ?: $target_user->user_login) : '',
        $actor_user_id,
        $actor_user ? $actor_user->user_login : '',
        $actor_user ? ($actor_user->display_name ?: $actor_user->user_login) : '',
        (string)get_post_meta($change_id, self::META_STATUS, true),
        (string)get_post_meta($change_id, self::META_NOTE, true),
        (string)get_post_meta($change_id, self::META_OCCURRED_AT, true),
        (string)get_post_meta($change_id, self::META_EFFECTIVE_AT, true),
        (int)get_post_meta($change_id, self::META_MANUAL, true),
        self::pretty_log_json($before),
        self::pretty_log_json($after),
      ]);
    }
    fclose($out);
    exit;
  }

  public function handle_admin_log_import(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_profile_log_import_nonce']) || !wp_verify_nonce($_POST['eum_profile_log_import_nonce'], 'eum_profile_log_import')) wp_die('Bad nonce.');

    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)$_POST['return_url']) : '';
    if ($return_url === '' || !wp_http_validate_url($return_url)) {
      $return_url = admin_url('admin.php?page=eum-profile-changes');
    }

    if (empty($_FILES['eum_log_csv']['tmp_name']) || !is_uploaded_file($_FILES['eum_log_csv']['tmp_name'])) {
      wp_safe_redirect(add_query_arg(['eum_type' => 'error', 'eum_msg' => 'Please choose a CSV file to import.'], $return_url)); exit;
    }

    $mode = sanitize_key((string)($_POST['import_mode'] ?? 'upsert'));
    if (!in_array($mode, ['upsert', 'create', 'update'], true)) $mode = 'upsert';

    $fh = fopen($_FILES['eum_log_csv']['tmp_name'], 'r');
    if (!$fh) {
      wp_safe_redirect(add_query_arg(['eum_type' => 'error', 'eum_msg' => 'Could not open the uploaded CSV file.'], $return_url)); exit;
    }

    $headers = fgetcsv($fh);
    if (!is_array($headers) || empty($headers)) {
      fclose($fh);
      wp_safe_redirect(add_query_arg(['eum_type' => 'error', 'eum_msg' => 'The CSV file is empty or missing headers.'], $return_url)); exit;
    }

    $headers = array_map(function($header) {
      $header = strtolower(trim((string)$header));
      return preg_replace('/[^a-z0-9_]+/', '_', $header);
    }, $headers);

    $created = 0; $updated = 0; $skipped = 0; $line = 1;
    while (($row_values = fgetcsv($fh)) !== false) {
      $line++;
      if ($row_values === [null] || $row_values === false) continue;
      if (count($row_values) < count($headers)) {
        $row_values = array_pad($row_values, count($headers), '');
      }
      $row = array_combine($headers, array_slice($row_values, 0, count($headers)));
      if (!is_array($row)) { $skipped++; continue; }

      $is_blank = true;
      foreach ($row as $value) {
        if (trim((string)$value) !== '') { $is_blank = false; break; }
      }
      if ($is_blank) continue;

      $change_id = !empty($row['change_id']) ? intval($row['change_id']) : (!empty($row['id']) ? intval($row['id']) : 0);
      $existing = $change_id > 0 ? get_post($change_id) : null;
      $is_existing = $existing && $existing->post_type === self::CPT_CHANGE;

      if ($mode === 'create' && $is_existing) { $skipped++; continue; }
      if ($mode === 'update' && !$is_existing) { $skipped++; continue; }

      $target_user_id = self::resolve_import_user_id($row, 'target');
      $actor_user_id = self::resolve_import_user_id($row, 'actor');
      $status = !empty($row['status']) ? sanitize_key((string)$row['status']) : '';
      if (!in_array($status, self::allowed_change_statuses(), true)) {
        $status = $is_existing ? (string)get_post_meta($change_id, self::META_STATUS, true) : 'applied';
      }
      $reason = array_key_exists('reason', $row) ? sanitize_text_field((string)$row['reason']) : (array_key_exists('change_note', $row) ? sanitize_text_field((string)$row['change_note']) : ($is_existing ? (string)get_post_meta($change_id, self::META_NOTE, true) : ''));
      $occurred_at = array_key_exists('occurred_at', $row) ? self::parse_datetime_local((string)$row['occurred_at']) : ($is_existing ? (string)get_post_meta($change_id, self::META_OCCURRED_AT, true) : '');
      $effective_at = array_key_exists('effective_at', $row) ? self::parse_datetime_local((string)$row['effective_at']) : ($is_existing ? (string)get_post_meta($change_id, self::META_EFFECTIVE_AT, true) : '');
      $before = array_key_exists('before_json', $row) ? self::parse_log_json_payload((string)$row['before_json']) : ($is_existing ? self::parse_log_json_payload(get_post_meta($change_id, self::META_BEFORE, true)) : []);
      $after = array_key_exists('after_json', $row) ? self::parse_log_json_payload((string)$row['after_json']) : ($is_existing ? self::parse_log_json_payload(get_post_meta($change_id, self::META_AFTER, true)) : []);
      $manual = array_key_exists('manual', $row) ? self::csv_bool_value($row['manual']) : ($is_existing ? (int)get_post_meta($change_id, self::META_MANUAL, true) : 1);

      if ($is_existing) {
        if ($target_user_id <= 0) $target_user_id = (int)get_post_meta($change_id, self::META_TARGET_USER, true);
        if ($actor_user_id <= 0) $actor_user_id = (int)get_post_meta($change_id, self::META_ACTOR_USER, true);
      }

      if ($target_user_id <= 0) {
        $skipped++;
        continue;
      }

      $target = get_user_by('id', $target_user_id);
      $title = 'Profile Change - ' . ($target ? ($target->display_name ?: $target->user_login) : ('User #' . $target_user_id));

      if ($is_existing) {
        wp_update_post(['ID' => $change_id, 'post_title' => $title]);
        update_post_meta($change_id, self::META_TARGET_USER, $target_user_id);
        update_post_meta($change_id, self::META_ACTOR_USER, $actor_user_id);
        update_post_meta($change_id, self::META_BEFORE, is_array($before) ? self::order_log_payload($before) : []);
        update_post_meta($change_id, self::META_AFTER, is_array($after) ? self::order_log_payload($after) : []);
        update_post_meta($change_id, self::META_NOTE, $reason);
        update_post_meta($change_id, self::META_OCCURRED_AT, $occurred_at ?: current_time('mysql'));
        update_post_meta($change_id, self::META_EFFECTIVE_AT, $effective_at);
        update_post_meta($change_id, self::META_STATUS, $status ?: 'applied');
        update_post_meta($change_id, self::META_MANUAL, $manual ? 1 : 0);
        $updated++;
      } else {
        $new_change_id = (int)wp_insert_post(['post_type' => self::CPT_CHANGE, 'post_status' => 'publish', 'post_title' => $title, 'post_author' => get_current_user_id()]);
        if ($new_change_id <= 0) { $skipped++; continue; }
        update_post_meta($new_change_id, self::META_TARGET_USER, $target_user_id);
        update_post_meta($new_change_id, self::META_ACTOR_USER, $actor_user_id);
        update_post_meta($new_change_id, self::META_BEFORE, is_array($before) ? self::order_log_payload($before) : []);
        update_post_meta($new_change_id, self::META_AFTER, is_array($after) ? self::order_log_payload($after) : []);
        update_post_meta($new_change_id, self::META_NOTE, $reason);
        update_post_meta($new_change_id, self::META_OCCURRED_AT, $occurred_at ?: current_time('mysql'));
        update_post_meta($new_change_id, self::META_EFFECTIVE_AT, $effective_at);
        update_post_meta($new_change_id, self::META_STATUS, $status ?: 'applied');
        update_post_meta($new_change_id, self::META_MANUAL, $manual ? 1 : 0);
        $created++;
      }
    }
    fclose($fh);

    $message = sprintf('CSV import finished. Created %d, updated %d, skipped %d.', $created, $updated, $skipped);
    wp_safe_redirect(add_query_arg(['eum_type' => 'success', 'eum_msg' => $message], $return_url));
    exit;
  }

  private function redirect_with_profile_msg(string $base_url, string $msg, string $type): void {
    $url = add_query_arg([
      'eum_profile_msg' => $msg,
      'eum_profile_type' => $type,
    ], $base_url ?: home_url('/'));
    wp_safe_redirect($url);
    exit;
  }

  private function current_url(): string {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? wp_unslash((string)$_SERVER['HTTP_HOST']) : parse_url(home_url('/'), PHP_URL_HOST);
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '/';
    return esc_url_raw($scheme . $host . $uri);
  }
}
