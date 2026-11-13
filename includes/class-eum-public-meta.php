<?php
if (!defined('ABSPATH')) exit;

class EUM_Public_Meta {
  const RULES_OPTION = 'eum_public_meta_conditional_rules';
  const GROUP_LABELS_PAGE_SLUG = 'eum-public-group-labels';
  const STATUS_RULES_OPTION = 'eum_public_meta_activation_rules';
  const USER_OVERRIDES_META = '_eum_public_meta_overrides';
  const JOB_TITLE_META = '_eum_public_meta_values';
  const CLEARED_FIELDS_META = '_eum_public_meta_cleared_fields';
  const LABELS_BASE_META = '_eum_public_meta_labels_base';
  const GROUP_LABELS_TERM_META = '_eum_public_meta_add_labels';
  const PROFILE_MIGRATION_OPTION = 'eum_public_meta_rule_suite_migrated';
  const REBUILD_HOOK = 'eum_public_meta_rebuild_batch';
  private static $syncing_users = [];

  private static function fields(): array {
    return [
      'public_nickname' => ['label' => 'Public Nickname'],
      'public_jobtitle' => ['label' => 'Public Job Title'],
      'public_division' => ['label' => 'Public Division'],
      'public_labels' => ['label' => 'Public Labels'],
    ];
  }

  public static function public_fields(): array {
    return self::fields();
  }

  public static function preview_user_rules(int $user_id): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !get_user_by('id', $user_id)) return ['user_id' => $user_id, 'rules' => [], 'fields' => []];

    $rule_values = [];
    $rule_sources = [];
    $timestamps = [];
    $matched_rules = [];
    foreach (self::conditional_rules() as $index => $rule) {
      $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];
      $metadata = is_array($rule['metadata'] ?? null) ? $rule['metadata'] : [];
      if (!self::conditions_match_user($user_id, $conditions)) continue;
      $rule_id = sanitize_text_field((string)($rule['id'] ?? ''));
      $rule_label = $rule_id !== '' ? 'Rule ' . substr($rule_id, 0, 8) : 'Rule ' . ((int)$index + 1);
      $matched_rules[] = ['id' => $rule_id, 'label' => $rule_label, 'updated_at' => (float)($rule['updated_at'] ?? 0)];
      $updated_at = (float)($rule['updated_at'] ?? 0);
      foreach ($metadata as $key => $value) {
        $key = sanitize_key((string)$key);
        if (!self::is_public_meta_key($key)) continue;
        if (!array_key_exists($key, $timestamps) || $updated_at >= $timestamps[$key]) {
          $rule_values[$key] = self::sanitize_value($key, $value);
          $rule_sources[$key] = $rule_label;
          $timestamps[$key] = $updated_at;
        }
      }
    }

    $overrides = get_user_meta($user_id, self::USER_OVERRIDES_META, true);
    if (!is_array($overrides)) $overrides = [];
    $preview_fields = [];
    foreach (self::fields() as $key => $definition) {
      $current_exists = metadata_exists('user', $user_id, $key);
      $current = $current_exists ? self::sanitize_value($key, get_user_meta($user_id, $key, true)) : '';
      $override_enabled = array_key_exists($key, $overrides);
      $override_value = $override_enabled ? self::sanitize_value($key, $overrides[$key]) : '';
      $has_rule = array_key_exists($key, $rule_values);
      $effective = $override_enabled ? $override_value : ($has_rule ? $rule_values[$key] : $current);
      $source = $override_enabled ? 'User override' : ($has_rule ? $rule_sources[$key] : 'No matching rule; current value stays as-is');
      $base_value = $effective;
      if ($key === 'public_labels') {
        $group_labels = implode(', ', self::group_labels_for_user($user_id));
        $base = $override_enabled ? $override_value : ($has_rule ? $rule_values[$key] : (string)get_user_meta($user_id, self::LABELS_BASE_META, true));
        if ($base === '' && !$override_enabled && !$has_rule) $base = $current;
        $base_value = self::sanitize_value('public_labels', $base);
        $effective = self::merge_labels($base, $group_labels);
      }
      $preview_fields[$key] = [
        'label' => (string)($definition['label'] ?? $key),
        'current' => $current_exists ? $current : '(not set)',
        'base_value' => $base_value,
        'source' => $source,
        'effective' => $effective,
        'would_change' => $effective !== $current,
        'override_enabled' => $override_enabled,
      ];
    }
    return ['user_id' => $user_id, 'rules' => $matched_rules, 'fields' => $preview_fields];
  }

  public function init(): void {
    add_action('admin_menu', [$this, 'admin_menu'], 55);
    add_action('init', [$this, 'maybe_migrate_profile_fields'], 30);
    add_action('show_user_profile', [$this, 'render_user_settings_metadata']);
    add_action('edit_user_profile', [$this, 'render_user_settings_metadata']);
    add_action('personal_options_update', [$this, 'save_user_settings_metadata']);
    add_action('edit_user_profile_update', [$this, 'save_user_settings_metadata']);
    add_action('admin_post_eum_public_meta_save_rules', [$this, 'handle_rules_save']);
    add_action('admin_post_eum_public_meta_bulk_sync', [$this, 'handle_bulk_sync']);
    add_action('admin_post_eum_public_meta_bulk_sync_rule', [$this, 'handle_rule_bulk_sync']);
    add_action('admin_post_eum_public_meta_check_user_rules', [$this, 'handle_user_rule_check']);
    add_action('admin_post_eum_public_meta_save_group_labels', [$this, 'handle_group_labels_save']);
    add_action('added_user_meta', [$this, 'maybe_sync_user_from_meta'], 80, 4);
    add_action('updated_user_meta', [$this, 'maybe_sync_user_from_meta'], 80, 4);
    add_action('deleted_user_meta', [$this, 'maybe_sync_user_from_meta'], 80, 4);
    add_action('set_object_terms', [$this, 'maybe_sync_user_from_terms'], 80, 6);
    add_action(self::REBUILD_HOOK, [$this, 'process_rebuild_batch'], 10, 1);
  }

  public function maybe_migrate_profile_fields(): void {
    if (get_option(self::PROFILE_MIGRATION_OPTION)) return;
    if (class_exists('EUM_Profiles')) {
      $settings = get_option(EUM_Profiles::OPTION_SETTINGS, []);
      if (is_array($settings)) {
        foreach (['public_fields', 'self_edit_fields', 'manager_edit_fields', 'manager_edit_fields_without_approval'] as $key) {
          $settings[$key] = array_values(array_filter((array)($settings[$key] ?? []), static function($field_id) {
            return !str_starts_with((string)$field_id, 'public:');
          }));
        }
        update_option(EUM_Profiles::OPTION_SETTINGS, $settings, false);
      }
    }

    // These legacy sources no longer drive public metadata. Preserve the
    // resolved public_* user values; remove only their old rule definitions.
    delete_option(self::STATUS_RULES_OPTION);
    if (function_exists('delete_metadata')) {
      delete_metadata('user', 0, self::USER_OVERRIDES_META, '', true);
      delete_metadata('post', 0, self::JOB_TITLE_META, '', true);
    }
    if (class_exists('EUM_Taxonomies') && taxonomy_exists('eum_job_path')) {
      $term_ids = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'fields' => 'ids', 'number' => -1]);
      if (!is_wp_error($term_ids)) {
        foreach ((array)$term_ids as $term_id) {
          $rows = EUM_Taxonomies::mapping_rows_from_raw(EUM_Taxonomies::term_meta_mapping_raw((int)$term_id));
          update_term_meta((int)$term_id, EUM_Taxonomies::term_meta_mapping_key(), EUM_Taxonomies::mapping_rows_to_raw($rows));
        }
      }
    }
    update_option(self::PROFILE_MIGRATION_OPTION, 1, false);
  }

  public static function profile_field_definitions(): array {
    return [];
  }

  public static function is_public_meta_key(string $key): bool {
    return isset(self::fields()[sanitize_key($key)]);
  }

  public static function profile_meta_key(string $field_id): string {
    $key = str_starts_with($field_id, 'public:') ? sanitize_key(substr($field_id, 7)) : '';
    return self::is_public_meta_key($key) ? $key : '';
  }

  public static function sanitize_value(string $key, $value): string {
    $key = sanitize_key($key);
    if (!self::is_public_meta_key($key)) return '';
    $value = is_scalar($value) ? sanitize_text_field((string)$value) : '';
    if ($key === 'public_labels') {
      $labels = [];
      foreach (explode(',', $value) as $label) {
        $label = trim(sanitize_text_field($label));
        if ($label === '') continue;
        if (function_exists('mb_substr')) $label = mb_substr($label, 0, 100);
        else $label = substr($label, 0, 100);
        $labels[] = $label;
      }
      $value = implode(', ', array_values(array_unique($labels)));
    }
    if (function_exists('mb_substr')) return mb_substr($value, 0, 250);
    return substr($value, 0, 250);
  }

  public static function profile_override_state(int $user_id, string $key): array {
    $key = sanitize_key($key);
    $overrides = (array)get_user_meta($user_id, self::USER_OVERRIDES_META, true);
    $enabled = array_key_exists($key, $overrides) && self::is_public_meta_key($key);
    return [
      'enabled' => $enabled,
      'value' => $enabled ? self::sanitize_value($key, $overrides[$key]) : '',
    ];
  }

  public static function save_profile_override(int $user_id, string $key, $posted): void {
    $key = sanitize_key($key);
    if ($user_id <= 0 || !self::is_public_meta_key($key)) return;
    $overrides = (array)get_user_meta($user_id, self::USER_OVERRIDES_META, true);
    $enabled = is_array($posted) && !empty($posted['enabled']);
    if ($enabled) {
      $overrides[$key] = self::sanitize_value($key, $posted['value'] ?? '');
    } else {
      unset($overrides[$key]);
    }
    if (empty($overrides)) delete_user_meta($user_id, self::USER_OVERRIDES_META);
    else update_user_meta($user_id, self::USER_OVERRIDES_META, $overrides);
  }

  public static function values_for_user(int $user_id): array {
    $values = [];
    foreach (self::fields() as $key => $_field) {
      if (metadata_exists('user', $user_id, $key)) {
        $value = self::sanitize_value($key, get_user_meta($user_id, $key, true));
        if ($value !== '') $values[$key] = $value;
      }
    }
    foreach (self::cleared_fields_for_user($user_id) as $key) {
      $values[$key] = '';
    }
    return $values;
  }

  public function render_user_settings_metadata($user): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) return;
    $user_id = isset($user->ID) ? (int)$user->ID : 0;
    if ($user_id <= 0) return;
    $values = self::values_for_user($user_id);

    echo '<h2 id="eum-public-metadata">Public Metadata</h2>';
    $this->render_user_rule_check_notice($user_id);
    echo '<p>Check a field to set a user-specific value. Unchecked fields continue to follow matching rules. User-specific values take priority over rules; Group labels are still added to Public Labels. A checked blank value clears its base value; Group labels remain additive.</p>';
    wp_nonce_field('eum_public_meta_profile_' . $user_id, 'eum_public_meta_profile_nonce');
    echo '<table class="form-table" role="presentation"><tbody>';
    foreach (self::fields() as $key => $field) {
      $override = self::profile_override_state($user_id, $key);
      $value = $override['enabled'] ? $override['value'] : (string)($values[$key] ?? '');
      echo '<tr><th><label for="eum_public_meta_' . esc_attr($key) . '">' . esc_html((string)$field['label']) . '</label></th><td>';
      echo '<label><input type="checkbox" name="eum_public_meta[' . esc_attr($key) . '][enabled]" value="1" ' . checked($override['enabled'], true, false) . '> Set a user-specific value</label><br>';
      echo '<input type="text" class="regular-text" maxlength="250" name="eum_public_meta[' . esc_attr($key) . '][value]" id="eum_public_meta_' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
      if ($key === 'public_labels') echo '<p class="description">Comma-separated. Labels from assigned Groups are added automatically.</p>';
      echo '</td></tr>';
    }
    echo '</tbody></table>';
    $check_url = add_query_arg(['action' => 'eum_public_meta_check_user_rules', 'user_id' => $user_id], admin_url('admin-post.php'));
    $check_url = wp_nonce_url($check_url, 'eum_public_meta_check_user_rules_' . $user_id);
    echo '<p><a class="button" href="' . esc_url($check_url) . '">Check Rules</a> <span class="description">Preview matching rules and resulting values. This does not save changes.</span></p>';
  }

  public function save_user_settings_metadata(int $user_id): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id()) || !current_user_can('edit_user', $user_id)) return;
    $nonce = isset($_POST['eum_public_meta_profile_nonce']) ? (string)wp_unslash($_POST['eum_public_meta_profile_nonce']) : '';
    if (!wp_verify_nonce($nonce, 'eum_public_meta_profile_' . $user_id)) return;
    $posted = isset($_POST['eum_public_meta']) && is_array($_POST['eum_public_meta']) ? wp_unslash($_POST['eum_public_meta']) : [];
    foreach (array_keys(self::fields()) as $key) {
      self::save_profile_override($user_id, $key, is_array($posted[$key] ?? null) ? $posted[$key] : []);
    }
    self::sync_user($user_id);
  }

  public function handle_user_rule_check(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.', 'Not allowed', ['response' => 403]);
    $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
    if ($user_id <= 0 || !get_user_by('id', $user_id) || !current_user_can('edit_user', $user_id)) wp_die('User not found or not allowed.', 'Not allowed', ['response' => 403]);
    check_admin_referer('eum_public_meta_check_user_rules_' . $user_id);
    $actor_user_id = (int)get_current_user_id();
    $token = strtolower(wp_generate_password(32, false, false));
    set_transient('eum_public_meta_user_check_' . $actor_user_id . '_' . $token, [
      'owner_user_id' => $actor_user_id,
      'target_user_id' => $user_id,
      'report' => self::preview_user_rules($user_id),
    ], 15 * MINUTE_IN_SECONDS);
    $profile_url = $user_id === $actor_user_id ? admin_url('profile.php') : admin_url('user-edit.php?user_id=' . $user_id);
    wp_safe_redirect(add_query_arg('eum_public_meta_rule_check', $token, $profile_url) . '#eum-public-metadata');
    exit;
  }

  private function render_user_rule_check_notice(int $user_id): void {
    $token = isset($_GET['eum_public_meta_rule_check']) ? sanitize_key(wp_unslash((string)$_GET['eum_public_meta_rule_check'])) : '';
    if (!preg_match('/^[a-z0-9]{32}$/', $token)) return;
    $actor_user_id = (int)get_current_user_id();
    $stored = get_transient('eum_public_meta_user_check_' . $actor_user_id . '_' . $token);
    if (!is_array($stored) || (int)($stored['owner_user_id'] ?? 0) !== $actor_user_id || (int)($stored['target_user_id'] ?? 0) !== $user_id) return;
    $report = is_array($stored['report'] ?? null) ? $stored['report'] : [];
    $rules = array_values(array_filter((array)($report['rules'] ?? []), 'is_array'));
    echo '<div class="notice notice-info inline" style="margin:12px 0;padding:10px 12px;"><p><strong>Rule check preview</strong> — ' . (int)count($rules) . ' matching rule(s). No values were changed.</p>';
    if (!empty($rules)) {
      echo '<p>Matching: ' . esc_html(implode(', ', array_map(static fn($rule) => (string)($rule['label'] ?? 'Rule'), $rules))) . '</p>';
    } else {
      echo '<p>No conditional public metadata rules match this user.</p>';
    }
    echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Field</th><th>Current value</th><th>Value after sync</th><th>Source</th></tr></thead><tbody>';
    foreach ((array)($report['fields'] ?? []) as $field) {
      if (!is_array($field)) continue;
      echo '<tr><td>' . esc_html((string)($field['label'] ?? '')) . '</td><td>' . esc_html((string)($field['current'] ?? '')) . '</td><td>' . esc_html((string)($field['effective'] ?? '')) . '</td><td>' . esc_html((string)($field['source'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    delete_transient('eum_public_meta_user_check_' . $actor_user_id . '_' . $token);
  }

  public static function sync_user(int $user_id): bool {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !get_user_by('id', $user_id) || !empty(self::$syncing_users[$user_id])) return false;
    self::$syncing_users[$user_id] = true;
    $changed = false;
    try {
      $values = self::matching_rule_values($user_id);
      $overrides = (array)get_user_meta($user_id, self::USER_OVERRIDES_META, true);
      foreach (self::fields() as $key => $_field) {
        if (array_key_exists($key, $overrides)) $values[$key] = self::sanitize_value($key, $overrides[$key]);
      }
      $has_label_rule = array_key_exists('public_labels', $values);
      if ($has_label_rule) {
        $base_labels = self::sanitize_value('public_labels', $values['public_labels']);
        if (!metadata_exists('user', $user_id, self::LABELS_BASE_META)
          || self::sanitize_value('public_labels', get_user_meta($user_id, self::LABELS_BASE_META, true)) !== $base_labels) {
          update_user_meta($user_id, self::LABELS_BASE_META, $base_labels);
        }
        unset($values['public_labels']);
      } elseif (!metadata_exists('user', $user_id, self::LABELS_BASE_META)
        && metadata_exists('user', $user_id, 'public_labels')) {
        update_user_meta($user_id, self::LABELS_BASE_META, self::sanitize_value('public_labels', get_user_meta($user_id, 'public_labels', true)));
      }

      $has_label_basis = $has_label_rule
        || metadata_exists('user', $user_id, self::LABELS_BASE_META)
        || metadata_exists('user', $user_id, 'public_labels')
        || !empty(self::group_labels_for_user($user_id))
        || in_array('public_labels', self::cleared_fields_for_user($user_id), true);
      if ($has_label_basis) {
        $base_labels = self::sanitize_value('public_labels', get_user_meta($user_id, self::LABELS_BASE_META, true));
        $group_labels = implode(', ', self::group_labels_for_user($user_id));
        $values['public_labels'] = self::merge_labels($base_labels, $group_labels);
      }

      foreach ($values as $key => $raw_value) {
        $value = self::sanitize_value($key, $raw_value);
        $exists = metadata_exists('user', $user_id, $key);
        $current = $exists ? self::sanitize_value($key, get_user_meta($user_id, $key, true)) : '';
        if ($value === '') {
          $cleared = self::cleared_fields_for_user($user_id);
          if (!in_array($key, $cleared, true)) {
            $cleared[] = $key;
            self::set_cleared_fields($user_id, $cleared);
            $changed = true;
          }
          if ($exists) {
            delete_user_meta($user_id, $key);
            $changed = true;
          }
        } else {
          if (!$exists || $current !== $value) {
            update_user_meta($user_id, $key, $value);
            $changed = true;
          }
          $cleared = array_values(array_diff(self::cleared_fields_for_user($user_id), [$key]));
          if (self::set_cleared_fields($user_id, $cleared)) $changed = true;
        }
      }
    } finally {
      unset(self::$syncing_users[$user_id]);
    }
    if ($changed && class_exists('EUM_Site_Manager')) EUM_Site_Manager::queue_user_sync_for_user($user_id);
    return $changed;
  }

  public function maybe_sync_user_from_meta($meta_ids, $user_id, $meta_key, $meta_value): void {
    $meta_key = sanitize_key((string)$meta_key);
    if (self::is_public_meta_key($meta_key) || in_array($meta_key, [self::USER_OVERRIDES_META, self::CLEARED_FIELDS_META, self::LABELS_BASE_META], true)) return;
    self::sync_user((int)$user_id);
  }

  public function maybe_sync_user_from_terms($object_id, $terms, $term_taxonomy_ids, $taxonomy, $append, $old_term_taxonomy_ids): void {
    if (in_array(sanitize_key((string)$taxonomy), ['eum_user_group', 'eum_job_path'], true) && get_user_by('id', (int)$object_id)) self::sync_user((int)$object_id);
  }

  private static function conditional_rules(): array {
    $saved = get_option(self::RULES_OPTION, []);
    return is_array($saved) ? array_values(array_filter($saved, 'is_array')) : [];
  }

  private static function sanitize_condition_key(string $key): string {
    $key = trim(sanitize_text_field($key));
    return preg_replace('/[^a-zA-Z0-9_:.\-]/', '', $key) ?: '';
  }

  private static function conditions_match_user(int $user_id, array $conditions): bool {
    if (empty($conditions)) return false;
    foreach ($conditions as $condition) {
      $key = self::sanitize_condition_key((string)($condition['meta_key'] ?? ''));
      if ($key === '') return false;
      $expected = (string)($condition['value'] ?? '');
      $actual = self::condition_value_for_user($user_id, $key);
      if (is_array($actual)) {
        $matched = array_key_exists($expected, $actual) && !empty($actual[$expected]);
        if (!$matched) {
          foreach ($actual as $item) {
            if (self::condition_values_match($key, $item, $expected)) { $matched = true; break; }
          }
        }
        if (!$matched) return false;
      } elseif ($actual === null || $actual === '') {
        if ($expected !== '') return false;
      } elseif (!self::condition_values_match($key, $actual, $expected)) {
        return false;
      }
    }
    return true;
  }

  private static function condition_values_match(string $key, $actual, string $expected): bool {
    if (!is_scalar($actual)) return false;
    if (strtolower($key) === 'eum_job_title:id' && class_exists('EUM_Job_Titles')) {
      $actual_id = EUM_Job_Titles::id_from_token($actual);
      $expected_id = EUM_Job_Titles::id_from_token($expected);
      return $actual_id > 0 && $expected_id > 0 && $actual_id === $expected_id;
    }
    if ($key === 'custom:job_title' && class_exists('EUM_Job_Titles')) {
      $actual_id = EUM_Job_Titles::id_from_token($actual);
      $expected_id = EUM_Job_Titles::id_from_token($expected);
      if ($actual_id > 0 && $expected_id > 0) return $actual_id === $expected_id;
      if ($actual_id > 0 && $expected_id <= 0) {
        $actual_label = EUM_Job_Titles::title_label($actual_id);
        if ($actual_label !== '' && strcasecmp($actual_label, trim($expected)) === 0) return true;
      }
    }
    return (string)$actual === $expected;
  }

  private static function condition_value_for_user(int $user_id, string $meta_key) {
    $normalized_key = strtolower($meta_key);
    if ($normalized_key === 'eum_terminated' && class_exists('EUM_Personnel')) {
      // Active users may not have a termination meta row at all. Treat the
      // absent value as the same false/0 state used by the rules UI.
      return EUM_Personnel::is_terminated($user_id) ? '1' : '0';
    }
    if ($normalized_key === 'eum_rehirable' && class_exists('EUM_Personnel')) {
      return EUM_Personnel::is_rehirable($user_id) ? '1' : '0';
    }
    if (strtolower($meta_key) === 'eum_job_title:id') {
      $custom = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
      return (string)($custom['job_title'] ?? '');
    }
    if (str_starts_with($meta_key, 'taxonomy:')) {
      return self::taxonomy_condition_values_for_user($user_id, sanitize_key(substr($meta_key, 9)));
    }
    if (str_starts_with($meta_key, 'custom:') && class_exists('EUM_Fields')) {
      $custom_key = sanitize_key(substr($meta_key, 7));
      $custom = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      return array_key_exists($custom_key, $custom) ? $custom[$custom_key] : null;
    }
    if (strpos($meta_key, '.') !== false) {
      $segments = explode('.', $meta_key);
      $root_key = sanitize_key((string)array_shift($segments));
      $value = get_user_meta($user_id, $root_key, true);
      foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) return null;
        $value = $value[$segment];
      }
      return $value;
    }
    return get_user_meta($user_id, $meta_key, true);
  }

  private static function taxonomy_condition_values_for_user(int $user_id, string $taxonomy): array {
    if ($user_id <= 0 || $taxonomy === '' || !taxonomy_exists($taxonomy)) return [];
    $term_ids = wp_get_object_terms($user_id, $taxonomy, ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) return [];
    $values = [];
    foreach (array_unique(array_map('intval', (array)$term_ids)) as $term_id) {
      if ($term_id <= 0) continue;
      $values[] = (string)$term_id;
      $path_ids = is_taxonomy_hierarchical($taxonomy)
        ? array_reverse(array_map('intval', (array)get_ancestors($term_id, $taxonomy)))
        : [];
      $path_ids[] = $term_id;
      $path_names = [];
      foreach ($path_ids as $path_id) {
        $term = get_term($path_id, $taxonomy);
        if (!$term || is_wp_error($term)) continue;
        $path_names[] = sanitize_text_field((string)$term->name);
      }
      if (!empty($path_names)) $values[] = implode(' / ', $path_names);
    }
    return array_values(array_unique($values));
  }

  private static function matching_rule_values(int $user_id): array {
    $values = [];
    $timestamps = [];
    foreach (self::conditional_rules() as $rule) {
      $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];
      $metadata = is_array($rule['metadata'] ?? null) ? $rule['metadata'] : [];
      if (!self::conditions_match_user($user_id, $conditions)) continue;
      $updated_at = (float)($rule['updated_at'] ?? 0);
      foreach ($metadata as $key => $value) {
        $key = sanitize_key((string)$key);
        if (!self::is_public_meta_key($key)) continue;
        if (!array_key_exists($key, $timestamps) || $updated_at >= $timestamps[$key]) {
          $values[$key] = is_scalar($value) ? (string)$value : '';
          $timestamps[$key] = $updated_at;
        }
      }
    }
    return $values;
  }

  private static function cleared_fields_for_user(int $user_id): array {
    $saved = get_user_meta($user_id, self::CLEARED_FIELDS_META, true);
    return array_values(array_intersect(array_keys(self::fields()), array_map('sanitize_key', (array)$saved)));
  }

  private static function set_cleared_fields(int $user_id, array $fields): bool {
    $fields = array_values(array_intersect(array_keys(self::fields()), array_unique(array_map('sanitize_key', $fields))));
    $current = self::cleared_fields_for_user($user_id);
    sort($fields);
    sort($current);
    if ($fields === $current) return false;
    if (empty($fields)) delete_user_meta($user_id, self::CLEARED_FIELDS_META);
    else update_user_meta($user_id, self::CLEARED_FIELDS_META, $fields);
    return true;
  }

  private static function group_labels_for_user(int $user_id): array {
    if (!taxonomy_exists('eum_user_group')) return [];
    $group_ids = wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']);
    if (is_wp_error($group_ids) || empty($group_ids)) return [];
    $labels = [];
    foreach (array_unique(array_map('intval', (array)$group_ids)) as $group_id) {
      $raw = (string)get_term_meta($group_id, self::GROUP_LABELS_TERM_META, true);
      foreach (explode(',', $raw) as $label) {
        $label = trim(sanitize_text_field($label));
        if ($label !== '') $labels[] = $label;
      }
    }
    return array_values(array_unique($labels));
  }

  private static function merge_labels(string ...$sources): string {
    $labels = [];
    foreach ($sources as $source) {
      foreach (explode(',', $source) as $label) {
        $label = trim(sanitize_text_field($label));
        if ($label !== '') $labels[] = $label;
      }
    }
    return self::sanitize_value('public_labels', implode(', ', array_values(array_unique($labels))));
  }

  public static function sync_users_for_group(int $group_id): int {
    $group_id = (int)$group_id;
    if ($group_id <= 0 || !taxonomy_exists('eum_user_group')) return 0;
    $user_ids = get_objects_in_term($group_id, 'eum_user_group');
    if (is_wp_error($user_ids)) return 0;
    $assignments = class_exists('EUM_Assignments') ? new EUM_Assignments() : null;
    foreach (array_unique(array_map('intval', (array)$user_ids)) as $user_id) {
      if ($assignments && method_exists($assignments, 'sync_user')) $assignments->sync_user($user_id);
      $changed = self::sync_user($user_id);
      if (!$changed && class_exists('EUM_Site_Manager')) EUM_Site_Manager::queue_user_sync_for_user($user_id);
    }
    return count(array_unique(array_map('intval', (array)$user_ids)));
  }

  public function admin_menu(): void {
    add_submenu_page(EUM_Admin::parent_slug('workforce'), 'Public Metadata Rules', 'Public Metadata Rules', 'read', 'eum-public-meta', [$this, 'render_rules_page']);
    add_submenu_page(EUM_Admin::parent_slug('workforce'), 'Group Label Additions', 'Group Label Additions', 'read', self::GROUP_LABELS_PAGE_SLUG, [$this, 'render_group_labels_page']);
  }

  public function render_group_labels_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');
    $groups = taxonomy_exists('eum_user_group')
      ? get_terms(['taxonomy' => 'eum_user_group', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'])
      : [];
    if (is_wp_error($groups)) $groups = [];

    echo '<div class="wrap"><h1>Group Label Additions</h1>';
    if (!empty($_GET['eum_group_labels_msg'])) {
      $message = sanitize_text_field((string)wp_unslash($_GET['eum_group_labels_msg']));
      $type = sanitize_key((string)wp_unslash($_GET['eum_group_labels_type'] ?? 'success'));
      echo '<div class="notice ' . ($type === 'error' ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($message) . '</p></div>';
    }
    echo '<p>Set labels that are added to each group member’s Public Labels. These additions are combined with rule and user values, and disappear when a user leaves the group.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_public_meta_save_group_labels">';
    wp_nonce_field('eum_public_meta_save_group_labels', 'eum_public_meta_group_labels_nonce');
    if (empty($groups)) {
      echo '<p>No user groups exist yet.</p>';
    } else {
      echo '<table class="widefat striped"><thead><tr><th scope="col">Group</th><th scope="col">Labels to add</th></tr></thead><tbody>';
      foreach ($groups as $group) {
        $term_id = (int)$group->term_id;
        $labels = (string)get_term_meta($term_id, self::GROUP_LABELS_TERM_META, true);
        echo '<tr><th scope="row"><label for="eum-group-public-labels-' . $term_id . '">' . esc_html((string)$group->name) . '</label></th>';
        echo '<td><input type="text" class="regular-text" style="width:min(560px,100%)" id="eum-group-public-labels-' . $term_id . '" name="group_labels[' . $term_id . ']" value="' . esc_attr($labels) . '" placeholder="Recruiting, Leadership"><p class="description">Comma-separated. Leave blank to remove this group’s additions.</p></td></tr>';
      }
      echo '</tbody></table>';
      echo '<p><button type="submit" class="button button-primary">Save Group Label Additions</button></p>';
    }
    echo '</form></div>';
  }

  public function handle_group_labels_save(): void {
    $actor_user_id = (int)get_current_user_id();
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr($actor_user_id)) wp_die('Not allowed.', 'Not allowed', ['response' => 403]);
    check_admin_referer('eum_public_meta_save_group_labels', 'eum_public_meta_group_labels_nonce');
    if (!taxonomy_exists('eum_user_group')) wp_die('User Groups are unavailable.');

    $posted = isset($_POST['group_labels']) && is_array($_POST['group_labels']) ? wp_unslash($_POST['group_labels']) : [];
    $updated = 0;
    foreach ($posted as $raw_term_id => $raw_labels) {
      $term_id = absint($raw_term_id);
      if ($term_id <= 0 || !is_scalar($raw_labels)) continue;
      $term = get_term($term_id, 'eum_user_group');
      if (!$term || is_wp_error($term)) continue;
      $labels = self::sanitize_value('public_labels', (string)$raw_labels);
      $current = (string)get_term_meta($term_id, self::GROUP_LABELS_TERM_META, true);
      if ($labels === $current) continue;
      if ($labels === '') delete_term_meta($term_id, self::GROUP_LABELS_TERM_META);
      else update_term_meta($term_id, self::GROUP_LABELS_TERM_META, $labels);
      self::sync_users_for_group($term_id);
      $updated++;
    }

    $target_url = add_query_arg([
      'page' => self::GROUP_LABELS_PAGE_SLUG,
      'eum_group_labels_msg' => sprintf('Saved changes for %d group(s).', $updated),
      'eum_group_labels_type' => 'success',
    ], admin_url('admin.php'));
    wp_safe_redirect($target_url);
    exit;
  }

  public function render_rules_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');
    $rules = self::conditional_rules();
    $next_index = count($rules);
    echo '<div class="wrap"><h1>Public Metadata Rules</h1>';
    echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::GROUP_LABELS_PAGE_SLUG)) . '">Manage Group Label Additions</a></p>';
    if (!empty($_GET['eum_public_meta_msg'])) {
      $message = sanitize_text_field((string)wp_unslash($_GET['eum_public_meta_msg']));
      $type = sanitize_key((string)wp_unslash($_GET['eum_public_meta_type'] ?? 'success'));
      echo '<div class="notice ' . ($type === 'error' ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($message) . '</p></div>';
    }
    echo '<p>Create rules such as <code>custom:worker_type = contractor</code> and <code>eum_terminated = 0</code>. All conditions in one rule must match. For each public field, the most recently edited matching rule wins. User-specific values set on the backend user profile take priority. Unchecked fields stay unchanged; check a field with a blank value to clear it.</p>';
    echo '<p>Condition keys can be WordPress user-meta keys, EUM custom fields, or taxonomies. Match a job title by ID with <code>eum_job_title:ID = 382</code> or <code>custom:job_title = 382</code> (the <code>eum_job_title:382</code> token also works). Match a Job Path with <code>taxonomy:eum_job_path</code> and a term ID or full path such as <code>JAS Enterprises / Xander Brand Digital</code>. Add another condition to require both. Dot notation works for nested user meta such as <code>eum_text_fields.department</code>. Public labels from assigned Groups are additive and removed when the user leaves those Groups.</p>';
    echo '<p>Use <strong>Bulk Sync This Rule</strong> on a saved rule to queue only its matching users; the normal rule priority is still applied for each user. This uses the saved rule, so save edits first. The button below the rules syncs all users.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-eum-public-meta-rules="1">';
    echo '<input type="hidden" name="action" value="eum_public_meta_save_rules">';
    wp_nonce_field('eum_public_meta_save_rules', 'eum_public_meta_nonce');
    wp_nonce_field('eum_public_meta_bulk_sync_rule', 'eum_public_meta_bulk_sync_rule_nonce');
    echo '<input type="hidden" name="rule_id" value="" data-eum-public-meta-sync-rule-id>';
    echo '<div data-eum-public-meta-rule-list data-eum-next-index="' . (int)$next_index . '" style="display:grid;gap:14px;">';
    if (empty($rules)) echo self::render_rule_row(0, []);
    else foreach ($rules as $index => $rule) echo self::render_rule_row((string)$index, $rule);
    echo '</div>';
    echo '<template data-eum-public-meta-rule-template>' . self::render_rule_row('__INDEX__', []) . '</template>';
    echo '<p><button type="button" class="button" data-eum-public-meta-add-rule>Add Rule</button> <button type="submit" class="button button-primary">Save Rules and Bulk Update Users</button></p>';
    echo '</form>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:22px;padding-top:16px;border-top:1px solid #dcdcde;">';
    echo '<input type="hidden" name="action" value="eum_public_meta_bulk_sync">';
    wp_nonce_field('eum_public_meta_bulk_sync', 'eum_public_meta_bulk_sync_nonce');
    echo '<p><button type="submit" class="button">Bulk Sync Public Metadata to Connected Sites</button> <span class="description">Reapply saved rules to every user and queue connected-site syncs in background batches.</span></p></form>';
    echo '<script>(function(){var root=document.querySelector("[data-eum-public-meta-rules]");if(!root)return;var list=root.querySelector("[data-eum-public-meta-rule-list]"),template=root.querySelector("template[data-eum-public-meta-rule-template]");root.addEventListener("click",function(event){var syncRule=event.target.closest("[data-eum-public-meta-sync-rule]");if(syncRule){event.preventDefault();var action=root.querySelector("input[name=action]"),ruleId=root.querySelector("[data-eum-public-meta-sync-rule-id]");if(action&&ruleId){action.value="eum_public_meta_bulk_sync_rule";ruleId.value=syncRule.getAttribute("data-rule-id")||"";root.submit();}return;}var add=event.target.closest("[data-eum-public-meta-add-rule]");if(add){event.preventDefault();var index=parseInt(list.getAttribute("data-eum-next-index")||"0",10)||0;list.setAttribute("data-eum-next-index",String(index+1));list.insertAdjacentHTML("beforeend",template.innerHTML.replace(/__INDEX__/g,String(index)));return;}var addCondition=event.target.closest("[data-eum-public-meta-add-condition]");if(addCondition){event.preventDefault();var row=addCondition.closest("[data-eum-public-meta-rule]"),conditions=row&&row.querySelector("[data-eum-public-meta-conditions]");if(!conditions)return;var conditionIndex=parseInt(row.getAttribute("data-eum-next-condition-index")||"0",10)||0;row.setAttribute("data-eum-next-condition-index",String(conditionIndex+1));var conditionTemplate=row.querySelector("template[data-eum-public-meta-condition-template]");if(conditionTemplate)conditions.insertAdjacentHTML("beforeend",conditionTemplate.innerHTML.replace(/__COND_INDEX__/g,String(conditionIndex)));return;}var removeCondition=event.target.closest("[data-eum-public-meta-remove-condition]");if(removeCondition){event.preventDefault();var condition=removeCondition.closest("[data-eum-public-meta-condition]");if(condition)condition.remove();return;}var removeRule=event.target.closest("[data-eum-public-meta-remove-rule]");if(removeRule){event.preventDefault();var rule=removeRule.closest("[data-eum-public-meta-rule]");if(rule)rule.remove();}});}());</script></div>';
  }

  private static function render_rule_row($index, array $rule): string {
    $conditions = is_array($rule['conditions'] ?? null) ? array_values($rule['conditions']) : [];
    if (empty($conditions)) $conditions = [[]];
    $metadata = is_array($rule['metadata'] ?? null) ? $rule['metadata'] : [];
    $rule_id = sanitize_text_field((string)($rule['id'] ?? ''));
    ob_start();
    echo '<section data-eum-public-meta-rule data-eum-next-condition-index="' . (int)count($conditions) . '" style="padding:16px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;">';
    echo '<input type="hidden" name="rules[' . esc_attr((string)$index) . '][id]" value="' . esc_attr((string)($rule['id'] ?? '')) . '">';
    echo '<h2 style="margin:0 0 12px;font-size:16px;">Conditional Rule</h2>';
    echo '<div data-eum-public-meta-conditions style="display:grid;gap:8px;">';
    foreach ($conditions as $condition_index => $condition) {
      echo self::render_condition_row($index, (string)$condition_index, is_array($condition) ? $condition : []);
    }
    echo '</div>';
    echo '<template data-eum-public-meta-condition-template>' . self::render_condition_row($index, '__COND_INDEX__', []) . '</template>';
    echo '<p><button type="button" class="button" data-eum-public-meta-add-condition>Add AND condition</button></p>';
    echo '<h3 style="margin:16px 0 8px;">Set Public Metadata</h3>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;">';
    foreach (self::fields() as $key => $field) {
      $enabled = array_key_exists($key, $metadata);
      $value = $enabled ? (string)$metadata[$key] : '';
      echo '<div><label><input type="checkbox" name="rules[' . esc_attr((string)$index) . '][metadata][' . esc_attr($key) . '][set]" value="1" ' . checked($enabled, true, false) . '> <strong>' . esc_html((string)$field['label']) . '</strong></label><br>';
      echo '<input type="text" name="rules[' . esc_attr((string)$index) . '][metadata][' . esc_attr($key) . '][value]" value="' . esc_attr($value) . '" class="regular-text" style="max-width:100%;width:100%;">';
      if ($key === 'public_labels') echo '<span class="description">Comma-separated. Group labels are added separately.</span>';
      echo '</div>';
    }
    echo '</div><p style="margin:14px 0 0;"><button type="button" class="button-link-delete" data-eum-public-meta-remove-rule>Remove Rule</button>';
    if ($rule_id !== '') echo ' <button type="button" class="button" data-eum-public-meta-sync-rule data-rule-id="' . esc_attr($rule_id) . '">Bulk Sync This Rule</button>';
    echo '</p></section>';
    return ob_get_clean();
  }

  private static function render_condition_row($rule_index, $condition_index, array $condition): string {
    $key = (string)($condition['meta_key'] ?? '');
    $value = (string)($condition['value'] ?? '');
    ob_start();
    echo '<div data-eum-public-meta-condition style="display:grid;grid-template-columns:minmax(220px,1fr) minmax(220px,1fr) auto;gap:8px;align-items:end;">';
    echo '<label>Condition Key<br><input type="text" name="rules[' . esc_attr((string)$rule_index) . '][conditions][' . esc_attr((string)$condition_index) . '][meta_key]" value="' . esc_attr($key) . '" class="regular-text" placeholder="eum_terminated, eum_job_title:ID, or taxonomy:eum_job_path"></label>';
    echo '<label>Equals<br><input type="text" name="rules[' . esc_attr((string)$rule_index) . '][conditions][' . esc_attr((string)$condition_index) . '][value]" value="' . esc_attr($value) . '" class="regular-text"></label>';
    echo '<button type="button" class="button" data-eum-public-meta-remove-condition>Remove condition</button></div>';
    return ob_get_clean();
  }

  public function handle_rules_save(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');
    if (!isset($_POST['eum_public_meta_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_public_meta_nonce']), 'eum_public_meta_save_rules')) wp_die('Security check failed.');
    $posted_rows = isset($_POST['rules']) && is_array($_POST['rules']) ? wp_unslash($_POST['rules']) : [];
    $existing = [];
    foreach (self::conditional_rules() as $rule) {
      $id = sanitize_text_field((string)($rule['id'] ?? ''));
      if ($id !== '') $existing[$id] = $rule;
    }
    $rules = [];
    $latest_stamp = microtime(true);
    foreach ($existing as $old_rule) $latest_stamp = max($latest_stamp, (float)($old_rule['updated_at'] ?? 0));
    foreach ($posted_rows as $row) {
      if (!is_array($row)) continue;
      $posted_conditions = is_array($row['conditions'] ?? null) ? $row['conditions'] : [];
      $conditions = [];
      $has_partial_condition = false;
      foreach ($posted_conditions as $condition) {
        if (!is_array($condition)) continue;
        $key = self::sanitize_condition_key((string)($condition['meta_key'] ?? ''));
        $value = sanitize_text_field((string)($condition['value'] ?? ''));
        if ($key === '' && $value === '') continue;
        if ($key === '') { $has_partial_condition = true; continue; }
        $conditions[] = ['meta_key' => $key, 'value' => $value];
      }
      $metadata = [];
      $posted_metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
      foreach (array_keys(self::fields()) as $key) {
        if (empty($posted_metadata[$key]['set'])) continue;
        $metadata[$key] = self::sanitize_value($key, $posted_metadata[$key]['value'] ?? '');
      }
      if (empty($conditions) && empty($metadata) && !$has_partial_condition) continue;
      if ($has_partial_condition || empty($conditions) || empty($metadata)) {
        $this->redirect_with_notice('Each rule needs at least one complete AND condition and one selected public metadata field.', 'error');
      }
      $id = sanitize_text_field((string)($row['id'] ?? ''));
      if ($id === '' || strlen($id) > 80) $id = wp_generate_uuid4();
      $new_rule = ['id' => $id, 'conditions' => array_values($conditions), 'metadata' => $metadata];
      $old = $existing[$id] ?? null;
      if (is_array($old)
        && ($old['conditions'] ?? []) === $new_rule['conditions']
        && ($old['metadata'] ?? []) === $new_rule['metadata']) {
        $new_rule['updated_at'] = (float)($old['updated_at'] ?? $latest_stamp);
      } else {
        $latest_stamp += 0.000001;
        $new_rule['updated_at'] = $latest_stamp;
      }
      $rules[] = $new_rule;
    }
    update_option(self::RULES_OPTION, array_values($rules), false);
    $result = $this->schedule_rebuild();
    $message = $result['queued']
      ? sprintf('Saved %d rule(s). Bulk update and connected-site sync queued for %d user(s).', count($rules), $result['count'])
      : sprintf('Saved %d rule(s). Public metadata applied to %d user(s); connected-site sync queued.', count($rules), $result['count']);
    $this->redirect_with_notice($message);
  }

  public function handle_bulk_sync(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');
    if (!isset($_POST['eum_public_meta_bulk_sync_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_public_meta_bulk_sync_nonce']), 'eum_public_meta_bulk_sync')) wp_die('Security check failed.');
    $result = $this->schedule_rebuild();
    $message = $result['queued']
      ? sprintf('Bulk public metadata update and connected-site sync queued for %d user(s).', $result['count'])
      : sprintf('Public metadata applied to %d user(s); connected-site sync queued.', $result['count']);
    $this->redirect_with_notice($message);
  }

  public function handle_rule_bulk_sync(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');
    $nonce = isset($_POST['eum_public_meta_bulk_sync_rule_nonce']) ? (string)wp_unslash($_POST['eum_public_meta_bulk_sync_rule_nonce']) : '';
    if (!wp_verify_nonce($nonce, 'eum_public_meta_bulk_sync_rule')) wp_die('Security check failed.');
    $rule_id = sanitize_text_field((string)wp_unslash($_POST['rule_id'] ?? ''));
    if ($rule_id === '') $this->redirect_with_notice('Select a saved rule to sync.', 'error');
    foreach (self::conditional_rules() as $rule) {
      if (!hash_equals((string)($rule['id'] ?? ''), $rule_id)) continue;
      $result = $this->schedule_rebuild($rule);
      $message = $result['queued']
        ? sprintf('Rule update and connected-site sync queued for %d matching user(s).', $result['count'])
        : sprintf('Rule applied to %d matching user(s); connected-site sync queued.', $result['count']);
      $this->redirect_with_notice($message);
    }
    $this->redirect_with_notice('The selected rule was not found. Save the rules and try again.', 'error');
  }

  private function redirect_with_notice(string $message, string $type = 'success'): void {
    wp_safe_redirect(add_query_arg([
      'page' => 'eum-public-meta',
      'eum_public_meta_msg' => $message,
      'eum_public_meta_type' => $type,
    ], admin_url('admin.php')));
    exit;
  }

  private function schedule_rebuild(?array $rule = null): array {
    $user_ids = get_users(['fields' => 'ids', 'number' => -1]);
    if (empty($user_ids)) return ['count' => 0, 'queued' => false];
    if ($rule !== null) {
      $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];
      $user_ids = array_values(array_filter(array_map('intval', $user_ids), static function($user_id) use ($conditions) {
        return self::conditions_match_user($user_id, $conditions);
      }));
    }
    if (empty($user_ids)) return ['count' => 0, 'queued' => false];
    $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
    // Small rule and site-wide updates should not depend on a later WP-Cron
    // request to actually apply the metadata. Large updates remain batched.
    if (count($user_ids) <= 100) {
      $this->process_rebuild_batch($user_ids);
      return ['count' => count($user_ids), 'queued' => false];
    }
    $chunks = array_chunk(array_map('intval', $user_ids), 100);
    $start = time() + 10;
    foreach ($chunks as $index => $batch) {
      $args = [$batch];
      if (!wp_next_scheduled(self::REBUILD_HOOK, $args)) {
        wp_schedule_single_event($start + ($index * 10), self::REBUILD_HOOK, $args);
      }
    }
    return ['count' => count($user_ids), 'queued' => true];
  }

  public function process_rebuild_batch($user_ids): void {
    foreach (array_values(array_unique(array_map('intval', (array)$user_ids))) as $user_id) {
      $changed = self::sync_user($user_id);
      if (!$changed && class_exists('EUM_Site_Manager')) EUM_Site_Manager::queue_user_sync_for_user($user_id);
    }
  }
}
