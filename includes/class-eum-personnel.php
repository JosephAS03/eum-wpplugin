<?php
if (!defined('ABSPATH')) exit;

class EUM_Personnel {
  const META_MANAGER_ID = 'eum_manager_user_id';
  const META_FILES = 'eum_personnel_files';   // array of file entries
  const META_TERMINATED = 'eum_terminated';
  const META_REHIRABLE = 'eum_rehirable';
  const META_TERMINATED_AT = 'eum_terminated_at';
  const META_TERMINATED_BY = 'eum_terminated_by_user_id';

  public function init(): void {
    add_action('show_user_profile', [$this, 'render_personnel_panel']);
    add_action('edit_user_profile', [$this, 'render_personnel_panel']);
    add_action('personal_options_update', [$this, 'save_personnel_panel']);
    add_action('edit_user_profile_update', [$this, 'save_personnel_panel']);
  }

  public static function base_upload_dir(): array {
    $u = wp_upload_dir();
    $base_dir = trailingslashit($u['basedir']) . 'eum/personnel/';
    $base_url = trailingslashit($u['baseurl']) . 'eum/personnel/';
    return [$base_dir, $base_url];
  }

  public static function user_dir(int $user_id): array {
    [$base_dir, $base_url] = self::base_upload_dir();
    return [trailingslashit($base_dir) . $user_id . '/', trailingslashit($base_url) . $user_id . '/'];
  }

  public static function get_files(int $user_id): array {
    $files = (array)get_user_meta($user_id, self::META_FILES, true);
    $clean = [];
    foreach ($files as $file) {
      if (!is_array($file)) continue;
      $clean[] = [
        'name' => sanitize_file_name((string)($file['name'] ?? 'file')),
        'path' => (string)($file['path'] ?? ''),
        'url' => esc_url_raw((string)($file['url'] ?? '')),
        'type' => sanitize_text_field((string)($file['type'] ?? 'application/octet-stream')),
        'uploaded_at' => sanitize_text_field((string)($file['uploaded_at'] ?? '')),
      ];
    }
    return array_values($clean);
  }

  public static function set_files(int $user_id, array $files): void {
    update_user_meta($user_id, self::META_FILES, array_values($files));
  }

  public static function is_terminated(int $user_id): bool {
    return $user_id > 0 && (string)get_user_meta($user_id, self::META_TERMINATED, true) === '1';
  }

  public static function is_rehirable(int $user_id): bool {
    return $user_id > 0 && (string)get_user_meta($user_id, self::META_REHIRABLE, true) === '1';
  }

  public static function terminated_at(int $user_id): string {
    return sanitize_text_field((string)get_user_meta($user_id, self::META_TERMINATED_AT, true));
  }

  public static function assigned_manager_user_id(int $user_id): int {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return 0;
    $manager_id = (int)get_user_meta($user_id, self::META_MANAGER_ID, true);
    if ($manager_id > 0 && $manager_id !== $user_id && get_user_by('id', $manager_id)) {
      return $manager_id;
    }
    return 0;
  }

  public static function effective_manager_user_id(int $user_id): int {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return 0;

    $assigned = self::assigned_manager_user_id($user_id);
    if ($assigned > 0) {
      return $assigned;
    }

    if (class_exists('EUM_Manager_IDs') && method_exists('EUM_Manager_IDs', 'manager_user_ids_for_subject_user')) {
      $ids = array_values(array_filter(array_map('intval', (array)EUM_Manager_IDs::manager_user_ids_for_subject_user($user_id))));
      if (!empty($ids)) {
        return (int)$ids[0];
      }
    }

    return 0;
  }

  public static function employment_status_summary(int $user_id): array {
    return [
      'terminated' => self::is_terminated($user_id),
      'rehirable' => self::is_rehirable($user_id),
      'terminated_at' => self::terminated_at($user_id),
      'terminated_by_user_id' => (int)get_user_meta($user_id, self::META_TERMINATED_BY, true),
    ];
  }

  public static function set_employment_status(int $user_id, bool $terminated, ?bool $rehirable = null, int $actor_user_id = 0, string $terminated_at = ''): void {
    update_user_meta($user_id, self::META_TERMINATED, $terminated ? '1' : '0');

    if ($rehirable !== null) {
      update_user_meta($user_id, self::META_REHIRABLE, $rehirable ? '1' : '0');
    }

    if ($terminated) {
      update_user_meta($user_id, self::META_TERMINATED_AT, $terminated_at !== '' ? sanitize_text_field($terminated_at) : current_time('mysql'));
      if ($actor_user_id > 0) update_user_meta($user_id, self::META_TERMINATED_BY, $actor_user_id);
    } else {
      delete_user_meta($user_id, self::META_TERMINATED_AT);
      if ($actor_user_id > 0) update_user_meta($user_id, self::META_TERMINATED_BY, $actor_user_id);
    }
  }

  public static function delete_file_indices(int $user_id, array $indices): array {
    $files = self::get_files($user_id);
    $deleted = [];
    $indices = array_values(array_unique(array_filter(array_map('intval', $indices), fn($i) => $i >= 0)));
    rsort($indices);

    foreach ($indices as $index) {
      if (!isset($files[$index])) continue;
      $file = $files[$index];
      if (!empty($file['path']) && file_exists($file['path'])) {
        @unlink($file['path']);
      }
      $deleted[] = (string)($file['name'] ?? ('file-' . $index));
      unset($files[$index]);
    }

    self::set_files($user_id, array_values($files));
    return array_values(array_reverse($deleted));
  }

  public static function upload_from_request(int $user_id, string $file_key = 'eum_personnel_upload'): array {
    if (empty($_FILES[$file_key]) || empty($_FILES[$file_key]['name'])) {
      return [];
    }

    $uploaded = $_FILES[$file_key];
    if (empty($uploaded['tmp_name']) || !is_uploaded_file($uploaded['tmp_name'])) {
      return [];
    }

    [$dir, $url] = self::user_dir($user_id);
    wp_mkdir_p($dir);

    $filename = wp_unique_filename($dir, sanitize_file_name((string)$uploaded['name']));
    $target = $dir . $filename;
    if (!move_uploaded_file($uploaded['tmp_name'], $target)) {
      return [];
    }

    $mime = function_exists('mime_content_type') ? mime_content_type($target) : '';
    $files = self::get_files($user_id);
    $entry = [
      'name' => $filename,
      'path' => $target,
      'url'  => $url . $filename,
      'type' => $mime ?: 'application/octet-stream',
      'uploaded_at' => current_time('mysql'),
    ];
    $files[] = $entry;
    self::set_files($user_id, $files);

    return [$filename];
  }

  public function render_personnel_panel(\WP_User $user): void {
    if (!current_user_can('edit_user', $user->ID)) return;

    wp_nonce_field('eum_save_personnel', 'eum_personnel_nonce');

    $manager_id = (int) get_user_meta($user->ID, self::META_MANAGER_ID, true);
    $files = self::get_files($user->ID);
    $employment = self::employment_status_summary($user->ID);

    $defs = EUM_Fields::get_definitions();
    $text_fields = (array) get_user_meta($user->ID, EUM_Fields::USER_META_KEY, true);

    echo '<h2>Enterprise Data</h2>';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th><label>Manager</label></th><td>';
    wp_dropdown_users([
      'name' => 'eum_manager_user_id',
      'selected' => $manager_id,
      'show_option_none' => '— None —',
      'exclude' => [$user->ID],
    ]);
    echo '<p class="description">Assign this user’s manager.</p>';
    echo '</td></tr>';

    echo '<tr><th><label>Employment Status</label></th><td>';
    echo $employment['terminated']
      ? '<strong>Terminated</strong>' . ($employment['rehirable'] ? ' · Rehirable' : ' · Non-rehireable') . (!empty($employment['terminated_at']) ? ' · ' . esc_html($employment['terminated_at']) : '')
      : '<strong>Active</strong>';
    echo '<p class="description">Termination and reactivation are managed from the front-end profile workflow.</p>';
    echo '</td></tr>';

    echo '<tr><th><label>Custom Fields</label></th><td>';
    if (empty($defs)) {
      echo '<p class="description">No fields configured. Go to Enterprise Users → Custom Fields.</p>';
    } else {
      foreach ($defs as $d) {
        $key = sanitize_key($d['key'] ?? '');
        $label = sanitize_text_field($d['label'] ?? '');
        $type = sanitize_key($d['type'] ?? 'text');
        $required = !empty($d['required']);
        if (!$key || !$label) continue;

        $val = $text_fields[$key] ?? '';

        echo '<p style="margin-bottom:12px;">';
        echo '<label style="display:block;font-weight:600;margin-bottom:4px;">'.esc_html($label);
        if ($required) echo ' <span style="color:#b32d2e">*</span>';
        echo '</label>';

        $name = 'eum_text_fields['.esc_attr($key).']';

        if ($key === 'employee_id') {
          echo '<input type="text" value="' . esc_attr((string)$val) . '" class="regular-text" readonly>';
          echo '<p class="description">Employee IDs are generated on hire and can only be changed directly in the database.</p>';
        } elseif ($key === 'job_title' && class_exists('EUM_Profiles')) {
          $job_term_ids = wp_get_object_terms($user->ID, 'eum_job_path', ['fields' => 'ids']);
          $job_term_id = (!is_wp_error($job_term_ids) && !empty($job_term_ids)) ? (int)$job_term_ids[0] : 0;
          echo EUM_Profiles::render_job_title_select_html($name, (string)$val, $job_term_id);
          $options = EUM_Profiles::job_title_options_for_job_term($job_term_id);
          if (!empty($options)) echo '<p class="description">Options come from the current Job Path.</p>';
        } elseif ($key === 'worker_type' && class_exists('EUM_Profiles')) {
          echo EUM_Profiles::render_worker_type_select_html($name, (string)$val);
          echo '<p class="description">Adds a badge on profiles and the org chart for contractors and freelancers.</p>';
        } elseif ($type === 'textarea') {
          echo '<textarea name="'.$name.'" rows="3" class="large-text">'.esc_textarea($val).'</textarea>';
        } else {
          echo '<input type="'.esc_attr($type).'" name="'.$name.'" value="'.esc_attr($val).'" class="regular-text" />';
        }
        echo '</p>';
      }
    }
    echo '</td></tr>';

    echo '<tr><th><label>Personnel Files</label></th><td>';
    echo '<input type="file" name="eum_personnel_upload" />';
    echo '<p class="description">Uploads to /uploads/eum/personnel/'.(int)$user->ID.'/</p>';

    if (!empty($files)) {
      echo '<ul style="margin-top:10px;">';
      foreach ($files as $idx => $f) {
        $name = isset($f['name']) ? $f['name'] : 'file';
        $url  = isset($f['url']) ? $f['url'] : '';
        $type = isset($f['type']) ? $f['type'] : '';
        echo '<li>';
        echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($name).'</a> ';
        echo '<em style="opacity:.7">('.esc_html($type).')</em> ';
        echo '<label style="margin-left:10px;"><input type="checkbox" name="eum_delete_file[]" value="'.esc_attr((string)$idx).'"> delete</label>';
        echo '</li>';
      }
      echo '</ul>';
    }

    echo '</td></tr>';
    echo '</tbody></table>';
  }

  public function save_personnel_panel(int $user_id): void {
    if (!current_user_can('edit_user', $user_id)) return;
    if (!isset($_POST['eum_personnel_nonce']) || !wp_verify_nonce($_POST['eum_personnel_nonce'], 'eum_save_personnel')) return;

    $manager_id = isset($_POST['eum_manager_user_id']) ? (int)$_POST['eum_manager_user_id'] : 0;
    update_user_meta($user_id, self::META_MANAGER_ID, $manager_id);

    $defs = EUM_Fields::get_definitions();
    $posted = isset($_POST['eum_text_fields']) && is_array($_POST['eum_text_fields']) ? $_POST['eum_text_fields'] : [];
    $existing_text_fields = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);

    $clean = [];
    foreach ($defs as $d) {
      $key = sanitize_key($d['key'] ?? '');
      $type = sanitize_key($d['type'] ?? 'text');
      if (!$key) continue;
      if ($key === 'employee_id') {
        if (isset($existing_text_fields[$key])) $clean[$key] = sanitize_text_field((string)$existing_text_fields[$key]);
        continue;
      }

      $raw = $posted[$key] ?? '';
      $clean[$key] = EUM_Fields::sanitize_field_value($key, $type, $raw);
    }
    update_user_meta($user_id, EUM_Fields::USER_META_KEY, $clean);

    if (!empty($_POST['eum_delete_file']) && is_array($_POST['eum_delete_file'])) {
      self::delete_file_indices($user_id, (array)$_POST['eum_delete_file']);
    }

    self::upload_from_request($user_id, 'eum_personnel_upload');
  }
}
