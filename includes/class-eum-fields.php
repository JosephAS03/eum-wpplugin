<?php
if (!defined('ABSPATH')) exit;

class EUM_Fields {
  const OPTION_KEY = 'eum_field_definitions';
  const USER_META_KEY = 'eum_text_fields';

  public function init(): void {
    add_action('admin_init', [$this, 'maybe_seed_defaults']);
  }

  public function maybe_seed_defaults(): void {
    $existing = get_option(self::OPTION_KEY, null);
    $existing = is_array($existing) ? $existing : [];

    $merged = self::merge_with_default_definitions($existing, $added_keys);
    update_option(self::OPTION_KEY, $merged, false);

    if (!empty($added_keys)) {
      self::migrate_profile_settings_for_new_fields($added_keys);
    }
  }

  private static function default_definitions(): array {
    return [
      ['key' => 'employee_id',       'label' => 'Employee ID',        'type' => 'text',     'required' => 0],
      ['key' => 'job_title',         'label' => 'Job Title',          'type' => 'text',     'required' => 0],
      ['key' => 'worker_type',       'label' => 'Worker Type',        'type' => 'text',     'required' => 0],
      ['key' => 'pay_structure',     'label' => 'Pay Structure',      'type' => 'text',     'required' => 0],
      ['key' => 'external_org',      'label' => 'External Organization', 'type' => 'text',   'required' => 0],
      ['key' => 'phone',             'label' => 'Phone',              'type' => 'text',     'required' => 0],
      ['key' => 'slack',             'label' => 'Slack',              'type' => 'text',     'required' => 0],
      ['key' => 'middle_name',       'label' => 'Middle Name',        'type' => 'text',     'required' => 0],
      ['key' => 'preferred_name',    'label' => 'Preferred Name',     'type' => 'text',     'required' => 0],
      ['key' => 'pronouns',          'label' => 'Pronouns',           'type' => 'text',     'required' => 0],
      ['key' => 'date_of_birth',     'label' => 'Date of Birth',      'type' => 'date',     'required' => 0],
      ['key' => 'personal_email',    'label' => 'Personal Email',     'type' => 'email',    'required' => 0],
      ['key' => 'work_phone',        'label' => 'Work Phone',         'type' => 'text',     'required' => 0],
      ['key' => 'mobile_phone',      'label' => 'Mobile Phone',       'type' => 'text',     'required' => 0],
      ['key' => 'residence_city',    'label' => 'City of Residence',  'type' => 'text',     'required' => 0],
      ['key' => 'residence_state',   'label' => 'State / Province',   'type' => 'text',     'required' => 0],
      ['key' => 'extension',         'label' => 'Extension',          'type' => 'text',     'required' => 0],
      ['key' => 'department',        'label' => 'Department',         'type' => 'text',     'required' => 0],
      ['key' => 'assistant_contact', 'label' => 'Assistant / Secondary Contact', 'type' => 'text', 'required' => 0],
      ['key' => 'timezone',          'label' => 'Time Zone',          'type' => 'text',     'required' => 0],
      ['key' => 'skills',            'label' => 'Skills / Expertise', 'type' => 'textarea', 'required' => 0],
      ['key' => 'languages',         'label' => 'Languages',          'type' => 'text',     'required' => 0],
      ['key' => 'bio',               'label' => 'Public Profile Bio', 'type' => 'textarea', 'required' => 0],
      ['key' => 'hire_date',         'label' => 'Hire Date',          'type' => 'date',     'required' => 0],
      ['key' => 'work_address',      'label' => 'Work Address',       'type' => 'textarea', 'required' => 0],
      ['key' => 'mailing_address',   'label' => 'Mailing Address',    'type' => 'textarea', 'required' => 0],
      ['key' => 'personal_address',  'label' => 'Personal Address',   'type' => 'textarea', 'required' => 0],
      ['key' => 'website',           'label' => 'Personal Website',   'type' => 'url',      'required' => 0],
      ['key' => 'linkedin',          'label' => 'LinkedIn',           'type' => 'url',      'required' => 0],
    ];
  }

  private static function merge_with_default_definitions(array $existing, ?array &$added_keys = []): array {
    $clean = [];
    $seen = [];

    foreach ($existing as $d) {
      $key = isset($d['key']) ? sanitize_key($d['key']) : '';
      $label = isset($d['label']) ? sanitize_text_field($d['label']) : '';
      $type = isset($d['type']) ? sanitize_key($d['type']) : 'text';
      $required = !empty($d['required']) ? 1 : 0;

      if (!$key || !$label) continue;
      if (!in_array($type, self::allowed_types(), true)) $type = 'text';
      if (isset($seen[$key])) continue;

      $clean[] = [
        'key' => $key,
        'label' => $label,
        'type' => $type,
        'required' => $required,
      ];
      $seen[$key] = true;
    }

    $added_keys = [];
    foreach (self::default_definitions() as $d) {
      $key = sanitize_key($d['key'] ?? '');
      if ($key === '' || isset($seen[$key])) continue;
      $clean[] = $d;
      $seen[$key] = true;
      $added_keys[] = $key;
    }

    return $clean;
  }

  private static function migrate_profile_settings_for_new_fields(array $added_keys): void {
    if (empty($added_keys) || !class_exists('EUM_Profiles')) return;

    $settings = get_option(EUM_Profiles::OPTION_SETTINGS, []);
    $settings = is_array($settings) ? $settings : [];

    $field_ids = array_map(static fn($key) => 'custom:' . sanitize_key((string)$key), $added_keys);
    $field_ids = array_values(array_filter($field_ids));
    if (empty($field_ids)) return;

    foreach (['self_edit_fields', 'manager_edit_fields'] as $settings_key) {
      $current = array_values(array_filter(array_map('strval', (array)($settings[$settings_key] ?? []))));
      foreach ($field_ids as $field_id) {
        if (!in_array($field_id, $current, true)) $current[] = $field_id;
      }
      $settings[$settings_key] = $current;
    }

    update_option(EUM_Profiles::OPTION_SETTINGS, $settings, false);
  }

  public static function get_definitions(): array {
    $defs = get_option(self::OPTION_KEY, []);
    $defs = is_array($defs) ? $defs : [];
    $merged = self::merge_with_default_definitions($defs, $added_keys);
    if (!empty($added_keys) || count($merged) !== count($defs)) {
      update_option(self::OPTION_KEY, $merged, false);
    }
    return $merged;
  }

  public static function set_definitions(array $defs): void {
    $clean = [];
    foreach ($defs as $d) {
      $key = isset($d['key']) ? sanitize_key($d['key']) : '';
      $label = isset($d['label']) ? sanitize_text_field($d['label']) : '';
      $type = isset($d['type']) ? sanitize_key($d['type']) : 'text';
      $required = !empty($d['required']) ? 1 : 0;

      if (!$key || !$label) continue;
      if (!in_array($type, self::allowed_types(), true)) $type = 'text';

      $clean[] = [
        'key' => $key,
        'label' => $label,
        'type' => $type,
        'required' => $required,
      ];
    }

    update_option(self::OPTION_KEY, $clean, false);
  }

  public static function allowed_types(): array {
    return ['text','textarea','email','url','number','date'];
  }

  public static function sanitize_field_value(string $key, string $type, $value): string {
    $key = sanitize_key($key);
    if ($key === 'worker_type' && class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'normalize_worker_type')) {
      return (string)EUM_Profiles::normalize_worker_type($value);
    }
    if ($key === 'job_title' && class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'normalize_job_title_value')) {
      return (string)EUM_Profiles::normalize_job_title_value($value);
    }
    return self::sanitize_value($type, $value);
  }

  public static function sanitize_value(string $type, $value): string {
    $value = is_scalar($value) ? (string)$value : '';

    switch ($type) {
      case 'email':
        return sanitize_email($value);
      case 'url':
        return esc_url_raw($value);
      case 'number':
        return (string)(is_numeric($value) ? $value : '');
      case 'date':
        return preg_replace('/[^0-9\-]/', '', $value);
      case 'textarea':
        return sanitize_textarea_field($value);
      case 'text':
      default:
        return sanitize_text_field($value);
    }
  }
}
