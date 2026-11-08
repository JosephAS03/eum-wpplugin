<?php
if (!defined('ABSPATH')) exit;

class EUM_Public_Meta {
  const STATUS_RULES_OPTION = 'eum_public_meta_activation_rules';
  const USER_OVERRIDES_META = '_eum_public_meta_overrides';
  const JOB_TITLE_META = '_eum_public_meta_values';
  const PROFILE_MIGRATION_OPTION = 'eum_public_meta_profile_fields_migrated';
  const REBUILD_HOOK = 'eum_public_meta_rebuild_batch';

  private static function fields(): array {
    return [
      'public_nickname' => ['label' => 'Public Nickname', 'profile_label' => 'Public Nickname', 'help' => 'Leave inherited to use the Job Path, Job Title, or activation status value.'],
      'public_jobtitle' => ['label' => 'Public Job Title', 'profile_label' => 'Public Job Title', 'help' => 'Leave inherited to use the Job Path, Job Title, or activation status value.'],
      'public_division' => ['label' => 'Public Division', 'profile_label' => 'Public Division', 'help' => 'Leave inherited to use the Job Path, Job Title, or activation status value.'],
      'public_labels' => ['label' => 'Public Labels', 'profile_label' => 'Public Labels', 'help' => 'Enter labels separated by commas. Leave inherited to use the Job Path, Job Title, or activation status value.'],
    ];
  }

  public function init(): void {
    add_action('admin_menu', [$this, 'admin_menu'], 55);
    add_action('init', [$this, 'maybe_migrate_profile_fields'], 30);
    add_action('admin_post_eum_public_meta_save_activation_rules', [$this, 'handle_activation_rules_save']);
    add_action('added_user_meta', [$this, 'maybe_sync_user_from_meta'], 80, 4);
    add_action('updated_user_meta', [$this, 'maybe_sync_user_from_meta'], 80, 4);
    add_action('deleted_user_meta', [$this, 'maybe_sync_user_from_meta'], 80, 4);
    add_action('set_object_terms', [$this, 'maybe_sync_user_from_terms'], 80, 6);
    add_action(self::REBUILD_HOOK, [$this, 'process_rebuild_batch'], 10, 1);
  }

  public function maybe_migrate_profile_fields(): void {
    if (get_option(self::PROFILE_MIGRATION_OPTION)) return;
    if (!class_exists('EUM_Profiles')) return;
    $settings = EUM_Profiles::get_settings();
    $field_ids = array_keys(self::profile_field_definitions());
    $key = 'manager_edit_fields';
    $current = array_values(array_filter(array_map('strval', (array)($settings[$key] ?? []))));
    foreach ($field_ids as $field_id) {
      if (!in_array($field_id, $current, true)) $current[] = $field_id;
    }
    $settings[$key] = $current;
    update_option(EUM_Profiles::OPTION_SETTINGS, $settings, false);
    update_option(self::PROFILE_MIGRATION_OPTION, 1, false);
  }

  public static function profile_field_definitions(): array {
    $out = [];
    foreach (self::fields() as $key => $field) {
      $out['public:' . $key] = [
        'label' => (string)$field['profile_label'],
        'group' => 'Public Metadata',
        'type' => 'public_meta_override',
        'meta_key' => $key,
      ];
    }
    return $out;
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
      $values[$key] = self::sanitize_value($key, get_user_meta($user_id, $key, true));
    }
    return $values;
  }

  public static function sync_user(int $user_id): void {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !get_user_by('id', $user_id)) return;

    // More specific sources replace broader defaults: user, activation status,
    // Job Title, then the nearest Job Path rule inherited from its ancestors.
    $values = array_merge(
      self::job_path_values_for_user($user_id),
      self::job_title_values_for_user($user_id),
      self::activation_values_for_user($user_id),
      self::individual_values_for_user($user_id)
    );

    foreach (self::fields() as $key => $_field) {
      $value = array_key_exists($key, $values) ? self::sanitize_value($key, $values[$key]) : '';
      $current = self::sanitize_value($key, get_user_meta($user_id, $key, true));
      if ($value === '') {
        if (metadata_exists('user', $user_id, $key)) delete_user_meta($user_id, $key);
      } elseif ($current !== $value) {
        update_user_meta($user_id, $key, $value);
      }
    }
  }

  public static function sync_users_for_job_path(int $term_id): int {
    $term_id = (int)$term_id;
    if ($term_id <= 0 || !taxonomy_exists('eum_job_path')) return 0;
    $term_ids = [$term_id];
    $descendants = get_term_children($term_id, 'eum_job_path');
    if (!is_wp_error($descendants) && is_array($descendants)) $term_ids = array_merge($term_ids, array_map('intval', $descendants));
    $user_ids = get_objects_in_term($term_ids, 'eum_job_path');
    if (is_wp_error($user_ids)) return 0;
    $user_ids = array_values(array_unique(array_map('intval', (array)$user_ids)));
    foreach ($user_ids as $user_id) {
      self::sync_user($user_id);
      if (class_exists('EUM_Site_Manager')) EUM_Site_Manager::queue_user_sync($user_id);
    }
    return count($user_ids);
  }

  public static function sync_users_for_job_title(int $title_id, array $path_ids = []): int {
    $title_id = (int)$title_id;
    if ($title_id <= 0 || !class_exists('EUM_Job_Titles')) return 0;
    if (empty($path_ids)) $path_ids = EUM_Job_Titles::allowed_job_paths($title_id);
    $term_ids = [];
    foreach (array_unique(array_map('intval', $path_ids)) as $path_id) {
      if ($path_id <= 0) continue;
      $term_ids[] = $path_id;
      $descendants = get_term_children($path_id, 'eum_job_path');
      if (!is_wp_error($descendants) && is_array($descendants)) $term_ids = array_merge($term_ids, array_map('intval', $descendants));
    }
    if (empty($term_ids)) return 0;
    $user_ids = get_objects_in_term(array_values(array_unique($term_ids)), 'eum_job_path');
    if (is_wp_error($user_ids)) return 0;
    $count = 0;
    foreach (array_unique(array_map('intval', (array)$user_ids)) as $user_id) {
      $custom = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
      if (class_exists('EUM_Job_Titles') && EUM_Job_Titles::id_from_token((string)($custom['job_title'] ?? '')) !== $title_id) continue;
      self::sync_user($user_id);
      $count++;
    }
    return $count;
  }

  public function maybe_sync_user_from_meta($meta_ids, $user_id, $meta_key, $meta_value): void {
    $watched = [self::USER_OVERRIDES_META, 'eum_terminated'];
    if (class_exists('EUM_Fields')) $watched[] = EUM_Fields::USER_META_KEY;
    if (in_array(sanitize_key((string)$meta_key), $watched, true)) self::sync_user((int)$user_id);
  }

  public function maybe_sync_user_from_terms($object_id, $terms, $term_taxonomy_ids, $taxonomy, $append, $old_term_taxonomy_ids): void {
    if (sanitize_key((string)$taxonomy) === 'eum_job_path' && get_user_by('id', (int)$object_id)) self::sync_user((int)$object_id);
  }

  private static function job_path_values_for_user(int $user_id): array {
    if (!taxonomy_exists('eum_job_path')) return [];
    $assigned = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($assigned) || empty($assigned)) return [];
    $term_id = (int)$assigned[0];
    return self::public_values_from_map(self::effective_job_path_mapping($term_id));
  }

  private static function effective_job_path_mapping(int $term_id): array {
    $term = get_term($term_id, 'eum_job_path');
    if (!$term || is_wp_error($term)) return [];
    $mapping = [];
    if ((int)$term->parent > 0 && class_exists('EUM_Taxonomies') && EUM_Taxonomies::term_inherits_parent($term_id)) {
      $mapping = self::effective_job_path_mapping((int)$term->parent);
    }
    if (!class_exists('EUM_Taxonomies')) return $mapping;
    $direct = EUM_Taxonomies::parse_term_meta_mapping(EUM_Taxonomies::term_meta_mapping_raw($term_id));
    foreach (array_keys(self::fields()) as $key) {
      if (array_key_exists($key, $direct)) $mapping[$key] = self::sanitize_value($key, $direct[$key]);
    }
    return $mapping;
  }

  private static function job_title_values_for_user(int $user_id): array {
    if (!class_exists('EUM_Fields') || !class_exists('EUM_Job_Titles')) return [];
    $custom = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    $title_id = EUM_Job_Titles::id_from_token((string)($custom['job_title'] ?? ''));
    if ($title_id <= 0 || get_post_type($title_id) !== EUM_Job_Titles::CPT) return [];
    $values = get_post_meta($title_id, self::JOB_TITLE_META, true);
    return self::public_values_from_map(is_array($values) ? $values : []);
  }

  private static function activation_values_for_user(int $user_id): array {
    $status = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id) ? 'terminated' : 'active';
    $rules = self::activation_rules();
    return self::public_values_from_map((array)($rules[$status] ?? []));
  }

  private static function individual_values_for_user(int $user_id): array {
    $overrides = get_user_meta($user_id, self::USER_OVERRIDES_META, true);
    return self::public_values_from_map(is_array($overrides) ? $overrides : []);
  }

  private static function public_values_from_map(array $values): array {
    $out = [];
    foreach (array_keys(self::fields()) as $key) {
      if (array_key_exists($key, $values)) $out[$key] = self::sanitize_value($key, $values[$key]);
    }
    return $out;
  }

  private static function activation_rules(): array {
    $saved = get_option(self::STATUS_RULES_OPTION, []);
    $saved = is_array($saved) ? $saved : [];
    $out = [];
    foreach (['active', 'terminated'] as $status) {
      $out[$status] = self::public_values_from_map(is_array($saved[$status] ?? null) ? $saved[$status] : []);
    }
    return $out;
  }

  public static function render_job_title_fields(int $title_id): void {
    $values = $title_id > 0 ? get_post_meta($title_id, self::JOB_TITLE_META, true) : [];
    $values = self::public_values_from_map(is_array($values) ? $values : []);
    echo '<hr><h3>Connected Site Public Metadata</h3><p class="description">These values apply to users assigned this Job Title and override Job Path defaults. Activation-status and individual-user values take precedence.</p>';
    foreach (self::fields() as $key => $field) {
      $enabled = array_key_exists($key, $values);
      echo '<p><label><input type="checkbox" name="eum_public_meta_title_enabled[' . esc_attr($key) . ']" value="1" ' . checked($enabled, true, false) . '> <strong>' . esc_html((string)$field['label']) . '</strong></label><br>';
      echo '<input type="text" class="regular-text" name="eum_public_meta_title_values[' . esc_attr($key) . ']" value="' . esc_attr((string)($values[$key] ?? '')) . '" style="max-width:520px;width:100%;">';
      if ($key === 'public_labels') echo '<br><span class="description">Comma-separated labels.</span>';
      echo '</p>';
    }
  }

  public static function save_job_title_fields(int $title_id, array $post): void {
    $enabled = is_array($post['eum_public_meta_title_enabled'] ?? null) ? $post['eum_public_meta_title_enabled'] : [];
    $values = is_array($post['eum_public_meta_title_values'] ?? null) ? $post['eum_public_meta_title_values'] : [];
    $clean = [];
    foreach (array_keys(self::fields()) as $key) {
      if (empty($enabled[$key])) continue;
      $raw = $values[$key] ?? '';
      if (is_string($raw)) $raw = wp_unslash($raw);
      $clean[$key] = self::sanitize_value($key, $raw);
    }
    if (empty($clean)) delete_post_meta($title_id, self::JOB_TITLE_META);
    else update_post_meta($title_id, self::JOB_TITLE_META, $clean);
  }

  public function admin_menu(): void {
    add_submenu_page(EUM_Admin::parent_slug('workforce'), 'Public Metadata Rules', 'Public Metadata Rules', 'read', 'eum-public-meta', [$this, 'render_activation_rules_page']);
  }

  public function render_activation_rules_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');
    $rules = self::activation_rules();
    echo '<div class="wrap"><h1>Public Metadata Rules</h1>';
    if (!empty($_GET['updated'])) echo '<div class="notice notice-success"><p>Activation rules saved. User metadata will be recalculated in batches and connected-site sync will follow.</p></div>';
    echo '<p>Set fallback values for active and terminated users. Values are applied in this order: individual user override, activation status, Job Title, then the nearest Job Path value (inherited from its parent unless overridden).</p>';
    echo '<p class="description">Check <strong>Set value</strong> and leave the input blank to intentionally clear values from lower-precedence rules.</p>';
    echo '<p>Job Path values are configured under <strong>Enterprise Users → Terms → Job Path → Path Data Mapping</strong>. Job Title values are configured in each Job Title. Individual overrides are available in a user’s EUM profile.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_public_meta_save_activation_rules">';
    wp_nonce_field('eum_public_meta_save_activation_rules', 'eum_public_meta_nonce');
    echo '<table class="widefat striped"><thead><tr><th>Field</th><th>Active</th><th>Terminated</th></tr></thead><tbody>';
    foreach (self::fields() as $key => $field) {
      echo '<tr><th scope="row">' . esc_html((string)$field['label']) . '<br><code>' . esc_html($key) . '</code></th>';
      foreach (['active', 'terminated'] as $status) {
        $enabled = array_key_exists($key, $rules[$status]);
        $value = $enabled ? (string)$rules[$status][$key] : '';
        echo '<td><label><input type="checkbox" name="rule_enabled[' . esc_attr($status) . '][' . esc_attr($key) . ']" value="1" ' . checked($enabled, true, false) . '> Set value</label><br>';
        echo '<input type="text" class="regular-text" name="rule_value[' . esc_attr($status) . '][' . esc_attr($key) . ']" value="' . esc_attr($value) . '">';
        if ($key === 'public_labels') echo '<br><span class="description">Comma-separated.</span>';
        echo '</td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table><p><button type="submit" class="button button-primary">Save Rules</button></p></form></div>';
  }

  public function handle_activation_rules_save(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr((int)get_current_user_id())) wp_die('Not allowed.');
    if (!isset($_POST['eum_public_meta_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_public_meta_nonce']), 'eum_public_meta_save_activation_rules')) wp_die('Security check failed.');
    $enabled = isset($_POST['rule_enabled']) && is_array($_POST['rule_enabled']) ? wp_unslash($_POST['rule_enabled']) : [];
    $values = isset($_POST['rule_value']) && is_array($_POST['rule_value']) ? wp_unslash($_POST['rule_value']) : [];
    $rules = [];
    foreach (['active', 'terminated'] as $status) {
      $rules[$status] = [];
      foreach (array_keys(self::fields()) as $key) {
        if (empty($enabled[$status][$key])) continue;
        $rules[$status][$key] = self::sanitize_value($key, $values[$status][$key] ?? '');
      }
    }
    update_option(self::STATUS_RULES_OPTION, $rules, false);
    $this->schedule_rebuild();
    wp_safe_redirect(add_query_arg(['page' => 'eum-public-meta', 'updated' => '1'], admin_url('admin.php')));
    exit;
  }

  private function schedule_rebuild(): void {
    $user_ids = get_users(['fields' => 'ids', 'number' => -1]);
    if (empty($user_ids)) return;
    $chunks = array_chunk(array_map('intval', $user_ids), 100);
    $start = time() + 10;
    foreach ($chunks as $index => $batch) {
      $args = [$batch];
      if (!wp_next_scheduled(self::REBUILD_HOOK, $args)) {
        wp_schedule_single_event($start + ($index * 10), self::REBUILD_HOOK, $args);
      }
    }
  }

  public function process_rebuild_batch($user_ids): void {
    foreach (array_values(array_unique(array_map('intval', (array)$user_ids))) as $user_id) {
      self::sync_user($user_id);
      if (class_exists('EUM_Site_Manager')) EUM_Site_Manager::queue_user_sync($user_id);
    }
  }
}
