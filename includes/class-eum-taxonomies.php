<?php

if (!defined('ABSPATH')) exit;

class EUM_Taxonomies {

  public function init(): void {
    add_action('init', [$this, 'register_taxonomies']);
    add_action('show_user_profile', [$this, 'render_user_taxonomies']);
    add_action('edit_user_profile', [$this, 'render_user_taxonomies']);
    add_action('personal_options_update', [$this, 'save_user_taxonomies']);
    add_action('edit_user_profile_update', [$this, 'save_user_taxonomies']);
    add_action('eum_sync_term_user_meta', [__CLASS__, 'sync_users_for_term'], 10, 4);
  }

  public function register_taxonomies(): void {
    $taxes = [
      'eum_user_group' => ['label' => 'User Groups', 'hierarchical' => false],
      'eum_job_path'   => ['label' => 'Job Path',    'hierarchical' => true],
      'eum_location'   => ['label' => 'Location',    'hierarchical' => true],
      'eum_project'    => ['label' => 'Projects',    'hierarchical' => false],
    ];

    foreach ($taxes as $tax => $cfg) {
      register_taxonomy($tax, ['user'], [
        'public' => false,
        'show_ui' => false,
        'show_in_menu' => false,
        'hierarchical' => (bool)$cfg['hierarchical'],
        'labels' => [
          'name' => $cfg['label'],
          'singular_name' => $cfg['label'],
        ],
        'rewrite' => false,
        'show_in_rest' => false,
      ]);
    }
  }

  public function render_user_taxonomies(\WP_User $user): void {
    if (!current_user_can('edit_user', $user->ID)) return;

    $taxes = [
      'eum_user_group' => ['label' => 'User Groups', 'multiple' => true],
      'eum_job_path'   => ['label' => 'Job Path', 'multiple' => false],
      'eum_location'   => ['label' => 'Location', 'multiple' => false],
      'eum_project'    => ['label' => 'Projects', 'multiple' => true],
    ];

    wp_nonce_field('eum_save_taxonomies', 'eum_tax_nonce');

    echo '<h2>Enterprise Classification</h2>';
    echo '<table class="form-table"><tbody>';

    foreach ($taxes as $tax => $cfg) {
      $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
      $assigned = wp_get_object_terms($user->ID, $tax, ['fields' => 'ids']);
      if (is_wp_error($assigned)) $assigned = [];

      echo '<tr><th><label>' . esc_html($cfg['label']) . '</label></th><td>';

      $multiple = (bool)$cfg['multiple'];
      $name = $multiple ? $tax . '[]' : $tax;

      echo '<select name="' . esc_attr($name) . '" ' . ($multiple ? 'multiple size="7"' : '') . ' style="min-width:360px;">';
      if (!$multiple) echo '<option value="">— None —</option>';

      foreach ($terms as $t) {
        $selected = in_array((int)$t->term_id, array_map('intval', $assigned), true) ? 'selected' : '';
        $prefix = '';
        if (is_taxonomy_hierarchical($tax) && $t->parent) {
          $prefix = str_repeat('— ', max(0, count(get_ancestors($t->term_id, $tax))));
        }
        echo '<option value="' . (int)$t->term_id . '" ' . $selected . '>' . esc_html($prefix . $t->name) . '</option>';
      }
      echo '</select>';

      if ($tax === 'eum_job_path') {
        echo '<p class="description">Select the classification path. Assignable Job Title options are configured on the Job Path term itself.</p>';
      } else {
        echo '<p class="description">Manage available terms via the Enterprise Terms screen.</p>';
      }

      echo '</td></tr>';
    }

    echo '</tbody></table>';
  }

  public function save_user_taxonomies(int $user_id): void {
    try {
      if (!current_user_can('edit_user', $user_id)) return;
      if (!isset($_POST['eum_tax_nonce']) || !wp_verify_nonce($_POST['eum_tax_nonce'], 'eum_save_taxonomies')) return;

      $map = [
        'eum_user_group' => true,
        'eum_project'    => true,
        'eum_job_path'   => false,
        'eum_location'   => false,
      ];

      foreach ($map as $tax => $multiple) {
        if ($multiple) {
          $vals = isset($_POST[$tax]) ? array_values(array_unique(array_map('intval', (array)$_POST[$tax]))) : [];
        } else {
          $val = isset($_POST[$tax]) ? intval($_POST[$tax]) : 0;
          $vals = $val ? [$val] : [];
        }

        $current = wp_get_object_terms($user_id, $tax, ['fields' => 'ids']);
        $current = is_wp_error($current) ? [] : array_values(array_unique(array_map('intval', (array)$current)));
        sort($current);
        sort($vals);

        if ($current !== $vals) {
          wp_set_object_terms($user_id, $vals, $tax, false);
        }
      }
      self::sync_user_classification_effects($user_id);
    } catch (\Throwable $e) {
      self::log_runtime_issue('save_user_taxonomies', $e);
    }
  }

  public static function term_meta_mapping_key(): string {
    return 'eum_user_meta_sync_map';
  }

  public static function term_meta_inherit_key(): string {
    return 'eum_user_meta_sync_inherit';
  }

  public static function term_meta_job_title_options_key(): string {
    return 'eum_job_title_options';
  }

  public static function parse_job_title_options($raw): array {
    if (is_array($raw)) {
      $rows = $raw;
    } else {
      $raw = trim((string)$raw);
      if ($raw === '') return [];
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $rows = $decoded;
      else $rows = preg_split('/\r\n|\r|\n/', $raw);
    }

    $out = [];
    foreach ((array)$rows as $row) {
      $label = sanitize_text_field(is_scalar($row) ? (string)$row : '');
      if ($label === '') continue;
      $out[] = $label;
    }
    return array_values(array_unique($out));
  }

  public static function direct_job_title_options_for_term(int $term_id): array {
    $term_id = (int)$term_id;
    if ($term_id <= 0) return [];
    return self::parse_job_title_options(get_term_meta($term_id, self::term_meta_job_title_options_key(), true));
  }

  public static function effective_job_title_options_for_term(int $term_id): array {
    $term_id = (int)$term_id;
    if ($term_id <= 0 || !taxonomy_exists('eum_job_path')) return [];

    $chain = array_reverse((array)get_ancestors($term_id, 'eum_job_path'));
    $chain[] = $term_id;
    $options = [];
    foreach ($chain as $check_term_id) {
      foreach (self::direct_job_title_options_for_term((int)$check_term_id) as $label) {
        $options[] = $label;
      }
    }
    return array_values(array_unique(array_filter($options)));
  }

  public static function term_meta_schedule_task_options_key(): string {
    return 'eum_schedule_task_options';
  }

  public static function parse_schedule_task_options($raw): array {
    return self::parse_job_title_options($raw);
  }

  public static function direct_schedule_task_options_for_term(int $term_id): array {
    $term_id = (int)$term_id;
    if ($term_id <= 0) return [];
    return self::parse_schedule_task_options(get_term_meta($term_id, self::term_meta_schedule_task_options_key(), true));
  }

  public static function effective_schedule_task_options_for_term(int $term_id): array {
    $term_id = (int)$term_id;
    if ($term_id <= 0 || !taxonomy_exists('eum_job_path')) return [];

    $chain = array_reverse((array)get_ancestors($term_id, 'eum_job_path'));
    $chain[] = $term_id;
    $options = [];
    foreach ($chain as $check_term_id) {
      foreach (self::direct_schedule_task_options_for_term((int)$check_term_id) as $label) {
        $options[] = $label;
      }
    }
    if (class_exists('EUM_Job_Titles')) {
      foreach (EUM_Job_Titles::title_labels_for_job_term($term_id) as $label) {
        $options[] = $label;
      }
    }
    if (class_exists('EUM_Job_Tasks')) {
      foreach (EUM_Job_Tasks::task_labels_for_job_term($term_id) as $label) {
        $options[] = $label;
      }
    }
    return array_values(array_unique(array_filter(array_map('sanitize_text_field', $options))));
  }

  public static function term_meta_mapping_raw(int $term_id): string {
    return (string)get_term_meta($term_id, self::term_meta_mapping_key(), true);
  }

  public static function term_inherits_parent(int $term_id): bool {
    if ($term_id <= 0) return true;
    $raw = get_term_meta($term_id, self::term_meta_inherit_key(), true);
    if ($raw === '' || $raw === null) return true;
    return !empty($raw);
  }

  public static function parse_term_meta_mapping($raw): array {
    if (is_array($raw)) {
      if (self::is_assoc($raw)) return $raw;
      $out = [];
      foreach ($raw as $row) {
        if (!is_array($row)) continue;
        $key = self::normalize_mapping_key((string)($row['key'] ?? ''));
        if ($key === '') continue;
        $out[$key] = is_scalar($row['value'] ?? null) ? (string)$row['value'] : wp_json_encode($row['value']);
      }
      return $out;
    }

    $raw = trim((string)$raw);
    if ($raw === '') return [];

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
      return self::parse_term_meta_mapping($decoded);
    }

    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
      $line = trim((string)$line);
      if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) continue;
      [$k, $v] = array_map('trim', explode('=', $line, 2));
      $k = self::normalize_mapping_key($k);
      if ($k !== '') $out[$k] = $v;
    }
    return $out;
  }

  public static function mapping_rows_from_raw($raw): array {
    $parsed = self::parse_term_meta_mapping($raw);
    $rows = [];
    foreach ($parsed as $key => $value) {
      $rows[] = [
        'key' => (string)$key,
        'label' => self::mapping_label_for_key((string)$key),
        'value' => is_scalar($value) ? (string)$value : wp_json_encode($value),
      ];
    }
    return $rows;
  }

  public static function mapping_rows_to_raw(array $rows): string {
    $lines = [];
    foreach ($rows as $row) {
      $key = self::normalize_mapping_key((string)($row['key'] ?? ''));
      if ($key === '') continue;
      $value = is_scalar($row['value'] ?? null) ? trim((string)$row['value']) : wp_json_encode($row['value']);
      $lines[] = $key . '=' . $value;
    }
    return implode("\n", $lines);
  }

  public static function normalize_mapping_rows_from_post($keys, $values): array {
    $keys = is_array($keys) ? $keys : [];
    $values = is_array($values) ? $values : [];
    $rows = [];
    $count = max(count($keys), count($values));
    for ($i = 0; $i < $count; $i++) {
      $key = self::normalize_mapping_key((string)($keys[$i] ?? ''));
      $value = trim((string)($values[$i] ?? ''));
      if ($key === '') continue;
      $rows[] = ['key' => $key, 'value' => $value];
    }
    return $rows;
  }

  public static function normalize_mapping_key(string $key): string {
    $key = trim($key);
    if ($key === '') return '';

    $alias_map = [
      'core:first_name' => 'first_name',
      'core:last_name' => 'last_name',
      'core:display_name' => 'display_name',
      'core:user_email' => 'user_email',
      'core:eum_manager_user_id' => 'eum_manager_user_id',
      'core:job_company' => 'job_company',
    ];

    $key = str_replace(['[', ']'], '', $key);
    if (isset($alias_map[$key])) return $alias_map[$key];
    if (str_starts_with($key, 'custom:')) {
      return 'custom:' . sanitize_key(substr($key, 7));
    }
    return sanitize_key($key);
  }

  public static function mapping_key_to_log_field_id(string $key): string {
    $key = self::normalize_mapping_key($key);
    switch ($key) {
      case 'first_name':
        return 'core:first_name';
      case 'last_name':
        return 'core:last_name';
      case 'display_name':
        return 'core:display_name';
      case 'user_email':
        return 'core:user_email';
      case 'eum_manager_user_id':
        return 'core:eum_manager_user_id';
      case 'job_company':
        return 'core:job_company';
      default:
        return $key;
    }
  }

  public static function mapping_label_for_key(string $key): string {
    $normalized = self::normalize_mapping_key($key);
    $field_id = self::mapping_key_to_log_field_id($normalized);
    if (class_exists('EUM_Profiles')) {
      $catalog = EUM_Profiles::field_catalog();
      if (isset($catalog[$field_id]['label'])) {
        return (string)$catalog[$field_id]['label'];
      }
    }
    if (str_starts_with($normalized, 'custom:')) {
      $custom_key = substr($normalized, 7);
      return ucwords(str_replace(['_', '-'], ' ', $custom_key));
    }
    return ucwords(str_replace(['_', '-'], ' ', $normalized));
  }

  private static function mapping_keys_reserved_for_explicit_selection(): array {
    return ['custom:job_title'];
  }

  private static function filter_reserved_mapping_keys(array $mapping): array {
    if (empty($mapping)) return [];
    $reserved = array_fill_keys(self::mapping_keys_reserved_for_explicit_selection(), true);
    $clean = [];
    foreach ($mapping as $raw_key => $value) {
      $normalized = self::normalize_mapping_key((string)$raw_key);
      if ($normalized === '' || isset($reserved[$normalized])) continue;
      $clean[$normalized] = $value;
    }
    return $clean;
  }

  public static function mapping_suggestions(): array {
    $options = [
      ['key' => 'first_name', 'label' => 'First Name'],
      ['key' => 'last_name', 'label' => 'Last Name'],
      ['key' => 'display_name', 'label' => 'Display Name'],
      ['key' => 'user_email', 'label' => 'Work Email'],
      ['key' => 'eum_manager_user_id', 'label' => 'Manager User ID'],
      ['key' => 'job_company', 'label' => 'Company Division'],
    ];

    $reserved = array_fill_keys(self::mapping_keys_reserved_for_explicit_selection(), true);
    if (class_exists('EUM_Fields')) {
      foreach ((array)EUM_Fields::get_definitions() as $def) {
        $custom_key = sanitize_key((string)($def['key'] ?? ''));
        if ($custom_key === '') continue;
        $mapping_key = 'custom:' . $custom_key;
        if (isset($reserved[$mapping_key])) continue;
        $options[] = [
          'key' => $mapping_key,
          'label' => sanitize_text_field((string)($def['label'] ?? $custom_key)),
        ];
      }
    }

    return $options;
  }

  public static function effective_mapping_for_term(string $tax, int $term_id): array {
    static $cache = [];
    $term_id = (int)$term_id;
    if ($term_id <= 0 || !taxonomy_exists($tax)) return [];

    $cache_key = $tax . ':' . $term_id;
    if (isset($cache[$cache_key])) return $cache[$cache_key];

    $term = get_term($term_id, $tax);
    if (!$term || is_wp_error($term)) {
      $cache[$cache_key] = [];
      return [];
    }

    $direct = self::filter_reserved_mapping_keys(self::parse_term_meta_mapping(self::term_meta_mapping_raw($term_id)));
    $effective = [];

    if (is_taxonomy_hierarchical($tax) && $term->parent && self::term_inherits_parent($term_id)) {
      $effective = self::effective_mapping_for_term($tax, (int)$term->parent);
    }

    $cache[$cache_key] = array_merge($effective, $direct);
    return $cache[$cache_key];
  }

  public static function effective_term_meta_mapping_for_user_state(int $user_id, array $overrides = []): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return [];

    $mapping = [];
    foreach (['eum_location', 'eum_job_path'] as $tax) {
      $override = 0;
      if (isset($overrides['tax:' . $tax])) $override = (int)$overrides['tax:' . $tax];
      elseif (isset($overrides[$tax])) $override = (int)$overrides[$tax];

      if ($override > 0) {
        $term_ids = [$override];
      } elseif ($override === 0 && array_key_exists('tax:' . $tax, $overrides)) {
        $term_ids = [];
      } else {
        $term_ids = wp_get_object_terms($user_id, $tax, ['fields' => 'ids']);
        if (is_wp_error($term_ids)) $term_ids = [];
      }

      foreach ((array)$term_ids as $term_id) {
        $mapping = array_merge($mapping, self::effective_mapping_for_term($tax, (int)$term_id));
      }
    }

    return self::filter_reserved_mapping_keys($mapping);
  }

  public static function mapping_change_set_for_user(int $user_id, array $mapping): array {
    $before = [];
    $after = [];

    foreach ($mapping as $raw_key => $value) {
      $key = self::normalize_mapping_key((string)$raw_key);
      if ($key === '') continue;
      $field_id = self::mapping_key_to_log_field_id($key);
      $current = self::get_user_value_for_mapping_key($user_id, $key);
      $new_value = is_scalar($value) ? (string)$value : wp_json_encode($value);
      if (!self::mapping_values_differ($current, $new_value)) continue;
      $before[$field_id] = $current;
      $after[$field_id] = $new_value;
    }

    return [$before, $after];
  }

  public static function sync_user_term_meta_for_user(int $user_id, int $actor_user_id = 0, string $reason = 'Term Data Sync'): void {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return;

    $mapping = self::effective_term_meta_mapping_for_user_state($user_id);
    if (!empty($mapping)) {
      self::apply_meta_mapping_to_user($user_id, $mapping, $actor_user_id, $reason, false);
    }

    if (class_exists('EUM_Company_Divisions')) {
      EUM_Company_Divisions::sync_user_division_from_classification($user_id, $mapping);
    }
  }

  public static function sync_user_classification_effects(int $user_id, int $actor_user_id = 0, string $reason = 'Term Data Sync'): void {
    try {
      $user_id = (int)$user_id;
      if ($user_id <= 0) return;

      self::sync_user_term_meta_for_user($user_id, $actor_user_id, $reason);

      if (class_exists('EUM_Assignments')) {
        $syncer = new EUM_Assignments();
        if (method_exists($syncer, 'sync_user')) {
          $syncer->sync_user($user_id);
        }
      }
    } catch (\Throwable $e) {
      self::log_runtime_issue('sync_user_classification_effects', $e);
    }
  }

  public static function apply_meta_mapping_to_user(int $user_id, array $mapping, int $actor_user_id = 0, string $reason = 'Term Data Sync', bool $record_change = false): void {
    if ($user_id <= 0 || empty($mapping)) return;

    [$before, $after] = self::mapping_change_set_for_user($user_id, $mapping);

    $custom_values = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    foreach ($mapping as $key => $value) {
      $key = self::normalize_mapping_key((string)$key);
      if ($key === '') continue;
      $value = is_scalar($value) ? (string)$value : wp_json_encode($value);
      switch ($key) {
        case 'first_name':
        case 'last_name':
          update_user_meta($user_id, $key, sanitize_text_field($value));
          break;
        case 'display_name':
          wp_update_user(['ID' => $user_id, 'display_name' => sanitize_text_field($value)]);
          break;
        case 'user_email':
          if (is_email($value)) wp_update_user(['ID' => $user_id, 'user_email' => sanitize_email($value)]);
          break;
        case 'job_company':
          if (class_exists('EUM_Company_Divisions')) {
            EUM_Company_Divisions::save_user_division_input($user_id, (string)$value, false);
          } else {
            $division = sanitize_key($value);
            if ($division !== '') update_user_meta($user_id, 'job_company', $division);
            else delete_user_meta($user_id, 'job_company');
          }
          break;
        case 'eum_manager_user_id':
          update_user_meta($user_id, class_exists('EUM_Personnel') ? EUM_Personnel::META_MANAGER_ID : 'eum_manager_user_id', max(0, (int)$value));
          break;
        default:
          if (str_starts_with($key, 'custom:') && class_exists('EUM_Fields')) {
            $custom_key = sanitize_key(substr($key, 7));
            if ($custom_key !== '') $custom_values[$custom_key] = sanitize_text_field($value);
          } else {
            update_user_meta($user_id, sanitize_key($key), sanitize_text_field($value));
          }
          break;
      }
    }

    if (class_exists('EUM_Fields')) {
      update_user_meta($user_id, EUM_Fields::USER_META_KEY, $custom_values);
    }

    if ($record_change && !empty($after) && class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'insert_applied_change_log')) {
      EUM_Profiles::insert_applied_change_log($user_id, $actor_user_id > 0 ? $actor_user_id : $user_id, $before, $after, $reason, 0);
    }
  }

  public static function sync_users_for_term(string $tax, int $term_id, int $actor_user_id = 0, string $reason = 'Term Data Sync'): int {
    $term_id = (int)$term_id;
    if ($term_id <= 0 || !taxonomy_exists($tax)) return 0;

    $term_ids = [$term_id];
    if (is_taxonomy_hierarchical($tax)) {
      $desc = get_term_children($term_id, $tax);
      if (!is_wp_error($desc) && is_array($desc)) {
        $term_ids = array_merge($term_ids, array_map('intval', $desc));
      }
    }

    $user_ids = array_values(array_unique(array_map('intval', get_objects_in_term($term_ids, $tax))));
    $count = 0;
    foreach ($user_ids as $uid) {
      self::sync_user_classification_effects($uid, $actor_user_id, $reason);
      $count++;
    }
    return $count;
  }

  public static function get_user_value_for_mapping_key(int $user_id, string $key) {
    $key = self::normalize_mapping_key($key);
    if ($user_id <= 0 || $key === '') return null;

    switch ($key) {
      case 'first_name':
      case 'last_name':
        return (string)get_user_meta($user_id, $key, true);
      case 'display_name':
        $u = get_user_by('id', $user_id);
        return $u ? (string)$u->display_name : '';
      case 'user_email':
        $u = get_user_by('id', $user_id);
        return $u ? (string)$u->user_email : '';
      case 'job_company':
        return class_exists('EUM_Company_Divisions') ? (string)EUM_Company_Divisions::effective_division_for_user($user_id) : (string)get_user_meta($user_id, 'job_company', true);
      case 'eum_manager_user_id':
        return (int)get_user_meta($user_id, class_exists('EUM_Personnel') ? EUM_Personnel::META_MANAGER_ID : 'eum_manager_user_id', true);
      default:
        if (str_starts_with($key, 'custom:') && class_exists('EUM_Fields')) {
          $all = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
          return (string)($all[sanitize_key(substr($key, 7))] ?? '');
        }
        return get_user_meta($user_id, sanitize_key($key), true);
    }
  }

  private static function mapping_values_differ($a, $b): bool {
    if (is_array($a) || is_array($b)) {
      return wp_json_encode($a) !== wp_json_encode($b);
    }
    return (string)$a !== (string)$b;
  }

  private static function is_assoc(array $array): bool {
    if ($array === []) return false;
    return array_keys($array) !== range(0, count($array) - 1);
  }

  private static function log_runtime_issue(string $context, \Throwable $e): void {
    eum_log_message('[EUM Taxonomies] ' . $context . ': ' . $e->getMessage());
  }
}
