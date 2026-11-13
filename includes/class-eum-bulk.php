<?php
if (!defined('ABSPATH')) exit;

class EUM_Bulk {

  public function init(): void {
    add_action('admin_post_eum_bulk_export_users', [$this, 'handle_export_users']);
    add_action('admin_post_eum_bulk_export_template', [$this, 'handle_export_template']);
  }

  public function render_page(): void {
    echo '<div class="wrap"><h1>Bulk Import / Export</h1>';
    echo '<p>Bulk export users, import CSV updates, and manage audit-log CSVs from one place.</p>';

    $this->render_query_notice();

    echo '<h2>Users</h2>';
    echo '<p><a class="button button-primary" href="' . esc_url($this->export_url('users')) . '">Download Users CSV</a> ';
    echo '<a class="button" href="' . esc_url($this->export_url('template')) . '">Download User Import Template</a></p>';
    echo '<p class="description">The user export includes WordPress account fields, enterprise access flags, manager links, job/location/group/project assignments, division override data, and all configured EUM custom fields.</p>';

    echo '<form method="post" enctype="multipart/form-data" style="max-width:980px; margin-top:18px;">';
    wp_nonce_field('eum_bulk_import', 'eum_bulk_nonce');
    echo '<table class="form-table"><tbody>';

    echo '<tr><th><label for="eum_csv">User CSV</label></th><td>';
    echo '<input type="file" id="eum_csv" name="eum_csv" accept=".csv" required />';
    echo '<p class="description">Use the template for new imports, or export existing users first and edit that CSV.</p>';
    echo '</td></tr>';

    echo '<tr><th><label for="eum_import_mode">Import Mode</label></th><td>';
    echo '<select id="eum_import_mode" name="eum_import_mode">';
    echo '<option value="upsert">Upsert (create missing, update existing)</option>';
    echo '<option value="update_only">Update existing only</option>';
    echo '<option value="create_only">Create new only</option>';
    echo '</select>';
    echo '<p class="description">Rows can also override this with an <code>operation</code> column using <code>upsert</code>, <code>update</code>, or <code>create</code>.</p>';
    echo '</td></tr>';

    echo '<tr><th>Safety</th><td>';
    echo '<label><input type="checkbox" name="eum_overwrite_blank_values" value="1"> Blank cells clear existing values</label>';
    echo '<p class="description">When unchecked, blank cells are ignored on updates instead of wiping data.</p>';
    echo '</td></tr>';

    echo '</tbody></table>';
    echo '<p><button class="button button-primary" type="submit" name="eum_do_import" value="1">Run User Import</button></p>';
    echo '</form>';

    if (!empty($_POST['eum_do_import'])) {
      $report = $this->do_import();
      $this->render_import_report($report);
    }

    echo '<hr />';
    echo '<h2>Audit Log</h2>';
    if (class_exists('EUM_Profiles')) {
      echo '<p class="description">These tools work with the profile change log CSV format. They do <strong>not</strong> create new audit entries for normal user bulk updates.</p>';

      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block; margin-right:12px;">';
      echo '<input type="hidden" name="action" value="eum_profile_log_export">';
      wp_nonce_field('eum_profile_log_export', 'eum_profile_log_export_nonce');
      echo '<button type="submit" class="button">Download Audit Log CSV</button>';
      echo '</form>';

      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px; max-width:980px;">';
      echo '<input type="hidden" name="action" value="eum_profile_log_import">';
      echo '<input type="hidden" name="return_url" value="' . esc_attr(admin_url('admin.php?page=eum-bulk')) . '">';
      wp_nonce_field('eum_profile_log_import', 'eum_profile_log_import_nonce');
      echo '<table class="form-table"><tbody>';
      echo '<tr><th><label for="eum_log_csv">Audit Log CSV</label></th><td><input type="file" id="eum_log_csv" name="eum_log_csv" accept=".csv" required></td></tr>';
      echo '<tr><th><label for="eum_log_import_mode">Audit Import Mode</label></th><td>';
      echo '<select id="eum_log_import_mode" name="import_mode">';
      echo '<option value="upsert">Upsert</option>';
      echo '<option value="update">Update existing only</option>';
      echo '<option value="create">Create new only</option>';
      echo '</select>';
      echo '<p class="description">This uses the same audit-log importer as the Profile Changes screen, just surfaced here in the bulk tools.</p>';
      echo '</td></tr>';
      echo '</tbody></table>';
      echo '<p><button type="submit" class="button button-secondary">Run Audit Log Import</button></p>';
      echo '</form>';
    } else {
      echo '<p>Audit log tools are unavailable because the profile change system is not loaded.</p>';
    }

    echo '<hr />';
    echo '<h2>User CSV Columns</h2>';
    echo '<p>Template / export header:</p>';
    echo '<pre>' . esc_html(implode(',', $this->columns_for_csv())) . '</pre>';

    echo '<h3>Key formats</h3>';
    echo '<ul style="max-width:980px;">';
    echo '<li><strong>operation</strong>: optional per-row override of the page mode: <code>upsert</code>, <code>update</code>, or <code>create</code></li>';
    echo '<li><strong>user lookup</strong>: existing users are matched by <code>user_id</code>, then <code>user_email</code>, then <code>user_login</code></li>';
    echo '<li><strong>user_pass</strong>: exports blank; importing a non-blank value updates the password</li>';
    echo '<li><strong>job_path</strong>: hierarchical path, e.g. <code>Engineering &gt; Platform &gt; SRE</code></li>';
    echo '<li><strong>location_path</strong>: hierarchical path, e.g. <code>USA &gt; NY &gt; NYC</code></li>';
    echo '<li><strong>user_groups</strong> / <strong>projects</strong>: semicolon-separated names, e.g. <code>Ops;Leadership</code></li>';
    echo '<li><strong>manager_id_override</strong>: Manager ID post ID, or blank to inherit</li>';
    echo '<li><strong>job_company_override</strong>: company division label or key; blank + “clear blanks” removes the manual override and falls back to Job Path</li>';
    echo '<li><strong>it_access</strong>, <strong>hr_access</strong>, <strong>terminated</strong>, <strong>rehirable</strong>: use <code>1/0</code>, <code>true/false</code>, or <code>yes/no</code></li>';
    echo '</ul>';

    echo '</div>';
  }

  private function render_query_notice(): void {
    $msg = isset($_GET['eum_msg']) ? sanitize_text_field(wp_unslash((string)$_GET['eum_msg'])) : '';
    if ($msg === '') return;
    $type = isset($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
    $class = 'notice notice-success';
    if ($type === 'error') $class = 'notice notice-error';
    elseif ($type === 'warning') $class = 'notice notice-warning';
    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($msg) . '</p></div>';
  }

  private function export_url(string $type): string {
    $action = $type === 'template' ? 'eum_bulk_export_template' : 'eum_bulk_export_users';
    return wp_nonce_url(admin_url('admin-post.php?action=' . rawurlencode($action)), $action, 'eum_export_nonce');
  }

  public function handle_export_template(): void {
    $this->do_export_template();
    exit;
  }

  public function handle_export_users(): void {
    $this->do_export_users();
    exit;
  }

  private function columns_for_csv(): array {
    $defs = EUM_Fields::get_definitions();
    $field_keys = array_map(static fn($d) => sanitize_key((string)($d['key'] ?? '')), $defs);
    $field_keys = array_values(array_filter($field_keys, static fn($key) => $key !== '' && $key !== 'employee_id'));

    return array_merge([
      'operation',
      'user_id',
      'user_login',
      'user_email',
      'user_pass',
      'role',
      'display_name',
      'first_name',
      'last_name',
      'nickname',
      'profile_image_url',
      'profile_image_color',
      'job_company_override',
      'effective_job_company',
      'manager_user_id',
      'manager_id_override',
      'effective_manager_id',
      'it_access',
      'hr_access',
      'terminated',
      'rehirable',
      'job_path',
      'location_path',
      'user_groups',
      'projects',
    ], $field_keys);
  }

  private function do_export_template(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_REQUEST['eum_export_nonce']) || !wp_verify_nonce((string)$_REQUEST['eum_export_nonce'], 'eum_bulk_export_template')) wp_die('Bad nonce.');

    if (ob_get_length()) {
      ob_end_clean();
    }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=eum-user-import-template-' . gmdate('Ymd-His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, $this->columns_for_csv());
    fclose($out);
  }

  private function do_export_users(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_REQUEST['eum_export_nonce']) || !wp_verify_nonce((string)$_REQUEST['eum_export_nonce'], 'eum_bulk_export_users')) wp_die('Bad nonce.');

    while (ob_get_level() > 0) {
      ob_end_clean();
    }
    if (function_exists('set_time_limit')) {
      @set_time_limit(0);
    }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=eum-users-' . gmdate('Ymd-His') . '.csv');

    $out = fopen('php://output', 'w');
    if (!$out) {
      wp_die('Could not open the CSV output stream.');
    }

    $columns = $this->columns_for_csv();
    fputcsv($out, $columns);

    $mgr = class_exists('EUM_Manager_IDs') ? new EUM_Manager_IDs() : null;
    $field_defs = class_exists('EUM_Fields') ? EUM_Fields::get_definitions() : [];
    $batch_size = 200;
    $offset = 0;

    do {
      $user_ids = get_users([
        'fields' => 'ids',
        'number' => $batch_size,
        'offset' => $offset,
        'orderby' => 'ID',
        'order' => 'ASC',
      ]);

      if (empty($user_ids) || !is_array($user_ids)) {
        break;
      }

      foreach ($user_ids as $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) continue;

        try {
          $ordered = $this->build_export_user_row($user_id, $columns, $field_defs, $mgr);
          if (!empty($ordered)) {
            fputcsv($out, $ordered);
          }
        } catch (Throwable $e) {
          $fallback = array_fill(0, count($columns), '');
          $col_index = array_flip($columns);
          if (isset($col_index['operation'])) $fallback[$col_index['operation']] = 'upsert';
          if (isset($col_index['user_id'])) $fallback[$col_index['user_id']] = (string)$user_id;
          if (isset($col_index['display_name'])) $fallback[$col_index['display_name']] = 'Export Error';
          if (isset($col_index['nickname'])) $fallback[$col_index['nickname']] = $e->getMessage();
          fputcsv($out, $fallback);
        }
      }

      $offset += count($user_ids);
      if (function_exists('flush')) {
        flush();
      }
    } while (count($user_ids) === $batch_size);

    fclose($out);
  }

  private function build_export_user_row(int $user_id, array $columns, array $field_defs, $mgr): array {
    $wp_user = get_user_by('id', $user_id);
    if (!$wp_user instanceof WP_User) {
      return [];
    }

    $custom_fields = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    $manager_user_id = class_exists('EUM_Personnel') ? (int)get_user_meta($user_id, EUM_Personnel::META_MANAGER_ID, true) : 0;
    $override_manager_id = class_exists('EUM_Manager_IDs') ? (int)get_user_meta($user_id, EUM_Manager_IDs::USER_META_OVERRIDE_ID, true) : 0;
    $effective_manager_id = ($mgr && method_exists($mgr, 'resolve_effective_manager_id_for_user')) ? (int)$mgr->resolve_effective_manager_id_for_user($user_id) : 0;

    $row = [
      'operation' => 'upsert',
      'user_id' => $user_id,
      'user_login' => (string)$wp_user->user_login,
      'user_email' => (string)$wp_user->user_email,
      'user_pass' => '',
      'role' => $this->primary_role_for_user($wp_user),
      'display_name' => (string)$wp_user->display_name,
      'first_name' => (string)get_user_meta($user_id, 'first_name', true),
      'last_name' => (string)get_user_meta($user_id, 'last_name', true),
      'nickname' => (string)get_user_meta($user_id, 'nickname', true),
      'profile_image_url' => class_exists('EUM_Profiles') ? (string)get_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_URL, true) : '',
      'profile_image_color' => class_exists('EUM_Profiles') ? (string)get_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_COLOR, true) : '',
      'job_company_override' => class_exists('EUM_Company_Divisions') ? (string)EUM_Company_Divisions::user_division_override($user_id) : (string)get_user_meta($user_id, 'job_company', true),
      'effective_job_company' => class_exists('EUM_Company_Divisions') ? (string)EUM_Company_Divisions::effective_division_for_user($user_id) : (string)get_user_meta($user_id, 'job_company', true),
      'manager_user_id' => $manager_user_id ?: '',
      'manager_id_override' => $override_manager_id ?: '',
      'effective_manager_id' => $effective_manager_id ?: '',
      'it_access' => EUM_Access::is_it_user($user_id) ? '1' : '0',
      'hr_access' => EUM_Access::is_hr_user($user_id) ? '1' : '0',
      'terminated' => class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id) ? '1' : '0',
      'rehirable' => class_exists('EUM_Personnel') && EUM_Personnel::is_rehirable($user_id) ? '1' : '0',
      'job_path' => $this->get_user_tax_path($user_id, 'eum_job_path', ' > '),
      'location_path' => $this->get_user_tax_path($user_id, 'eum_location', ' > '),
      'user_groups' => $this->get_user_term_names($user_id, 'eum_user_group', ';'),
      'projects' => $this->get_user_term_names($user_id, 'eum_project', ';'),
    ];

    foreach ($field_defs as $def) {
      $key = sanitize_key((string)($def['key'] ?? ''));
      if ($key === '') continue;
      if ($key === 'employee_id') continue;
      $row[$key] = is_scalar($custom_fields[$key] ?? '') ? (string)($custom_fields[$key] ?? '') : '';
    }

    $ordered = [];
    foreach ($columns as $col) {
      $ordered[] = $row[$col] ?? '';
    }
    return $ordered;
  }

  private function do_import(): array {
    $report = [
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
      'errors' => 0,
      'messages' => [],
    ];

    if (!EUM_Access::current_user_is_it()) {
      $report['errors']++;
      $report['messages'][] = ['type' => 'error', 'text' => 'Not allowed.'];
      return $report;
    }

    if (!isset($_POST['eum_bulk_nonce']) || !wp_verify_nonce((string)$_POST['eum_bulk_nonce'], 'eum_bulk_import')) {
      $report['errors']++;
      $report['messages'][] = ['type' => 'error', 'text' => 'Invalid nonce.'];
      return $report;
    }

    if (empty($_FILES['eum_csv']['tmp_name']) || !is_uploaded_file($_FILES['eum_csv']['tmp_name'])) {
      $report['errors']++;
      $report['messages'][] = ['type' => 'error', 'text' => 'No CSV file was uploaded.'];
      return $report;
    }

    $fh = fopen($_FILES['eum_csv']['tmp_name'], 'r');
    if (!$fh) {
      $report['errors']++;
      $report['messages'][] = ['type' => 'error', 'text' => 'Could not read the uploaded CSV file.'];
      return $report;
    }

    $headers = fgetcsv($fh);
    if (!is_array($headers) || empty($headers)) {
      fclose($fh);
      $report['errors']++;
      $report['messages'][] = ['type' => 'error', 'text' => 'The CSV file is empty or missing headers.'];
      return $report;
    }

    $headers = array_map([$this, 'normalize_header'], $headers);
    $import_mode = sanitize_key((string)($_POST['eum_import_mode'] ?? 'upsert'));
    if (!in_array($import_mode, ['upsert', 'update_only', 'create_only'], true)) $import_mode = 'upsert';
    $clear_blanks = !empty($_POST['eum_overwrite_blank_values']);

    $defs = EUM_Fields::get_definitions();
    $def_map = [];
    foreach ($defs as $d) {
      $k = sanitize_key((string)($d['key'] ?? ''));
      if ($k === '') continue;
      if ($k === 'employee_id') continue;
      $def_map[$k] = sanitize_key((string)($d['type'] ?? 'text'));
    }

    $row_num = 1;
    while (($row = fgetcsv($fh)) !== false) {
      $row_num++;
      $assoc = [];
      foreach ($headers as $i => $header) {
        if ($header === '') continue;
        $assoc[$header] = isset($row[$i]) ? trim((string)$row[$i]) : '';
      }
      $is_blank = true;
      foreach ($assoc as $value) {
        if ($value !== '') { $is_blank = false; break; }
      }
      if ($is_blank) continue;

      $row_result = $this->import_row($assoc, [
        'default_mode' => $import_mode,
        'clear_blanks' => $clear_blanks,
        'def_map' => $def_map,
      ]);

      $report[$row_result['status']]++;
      $report['messages'][] = [
        'type' => $row_result['status'] === 'errors' ? 'error' : ($row_result['status'] === 'skipped' ? 'warning' : 'success'),
        'text' => 'Row ' . $row_num . ': ' . $row_result['message'],
      ];
    }

    fclose($fh);
    return $report;
  }

  private function import_row(array $assoc, array $args): array {
    $def_map = (array)($args['def_map'] ?? []);
    $clear_blanks = !empty($args['clear_blanks']);

    $operation = sanitize_key((string)($assoc['operation'] ?? ''));
    if ($operation === '') $operation = sanitize_key((string)($args['default_mode'] ?? 'upsert'));
    $operation_map = [
      'update_only' => 'update',
      'create_only' => 'create',
      'upsert' => 'upsert',
      'update' => 'update',
      'create' => 'create',
    ];
    $operation = $operation_map[$operation] ?? 'upsert';

    $target = $this->resolve_target_user($assoc);
    $existing_user = $target['user'];
    $existing_user_id = $existing_user ? (int)$existing_user->ID : 0;

    if ($operation === 'create' && $existing_user_id > 0) {
      return ['status' => 'skipped', 'message' => 'Skipped because the user already exists (' . $this->user_label($existing_user) . ').'];
    }
    if ($operation === 'update' && $existing_user_id <= 0) {
      return ['status' => 'errors', 'message' => 'Could not find an existing user to update by user_id, user_email, or user_login.'];
    }

    $user_login = sanitize_user((string)($assoc['user_login'] ?? ''), true);
    $user_email = sanitize_email((string)($assoc['user_email'] ?? ''));
    $user_pass = (string)($assoc['user_pass'] ?? '');
    $role = sanitize_key((string)($assoc['role'] ?? ''));
    $warnings = [];

    if ($existing_user_id > 0) {
      $userdata = ['ID' => $existing_user_id];
      $wp_update_needed = false;

      if ($user_login !== '' && $user_login !== (string)$existing_user->user_login) {
        $warnings[] = 'user_login cannot be changed for an existing user, so that column was ignored';
      }

      if ($this->should_apply_scalar($assoc, 'user_email', $clear_blanks)) {
        if ($user_email === '') {
          $warnings[] = 'blank user_email was ignored';
        } else {
          $userdata['user_email'] = $user_email;
          $wp_update_needed = true;
        }
      }
      if ($this->should_apply_scalar($assoc, 'display_name', $clear_blanks)) {
        $userdata['display_name'] = (string)($assoc['display_name'] ?? '');
        $wp_update_needed = true;
      }
      if ($this->should_apply_scalar($assoc, 'first_name', $clear_blanks)) {
        $userdata['first_name'] = (string)($assoc['first_name'] ?? '');
        $wp_update_needed = true;
      }
      if ($this->should_apply_scalar($assoc, 'last_name', $clear_blanks)) {
        $userdata['last_name'] = (string)($assoc['last_name'] ?? '');
        $wp_update_needed = true;
      }
      if ($this->should_apply_scalar($assoc, 'nickname', $clear_blanks)) {
        $userdata['nickname'] = (string)($assoc['nickname'] ?? '');
        $wp_update_needed = true;
      }
      if ($user_pass !== '') {
        $userdata['user_pass'] = $user_pass;
        $wp_update_needed = true;
      }
      if ($role !== '') {
        $valid_role = $this->sanitize_role($role);
        if ($valid_role !== '') {
          $userdata['role'] = $valid_role;
          $wp_update_needed = true;
        } else {
          $warnings[] = 'invalid role "' . $role . '" was ignored';
        }
      }

      if ($wp_update_needed) {
        $updated = wp_update_user($userdata);
        if (is_wp_error($updated)) {
          return ['status' => 'errors', 'message' => 'WordPress update failed: ' . $updated->get_error_message()];
        }
      }

      $user_id = $existing_user_id;
      $row_state = 'updated';
      $message_prefix = 'Updated ' . $this->user_label(get_user_by('id', $user_id)) . '.';
    } else {
      if ($user_email === '') {
        return ['status' => 'errors', 'message' => 'New users require a valid user_email.'];
      }

      if ($user_login === '') {
        $user_login = $this->derive_login_from_assoc($assoc);
      }
      $base = $user_login !== '' ? $user_login : current(explode('@', $user_email));
      $user_login = $this->unique_user_login($base);
      if ($user_login === '') {
        return ['status' => 'errors', 'message' => 'Could not generate a valid unique user_login for the new user.'];
      }

      $valid_role = $this->sanitize_role($role);
      $userdata = [
        'user_login' => $user_login,
        'user_email' => $user_email,
        'user_pass' => $user_pass !== '' ? $user_pass : wp_generate_password(20, true, true),
        'role' => $valid_role !== '' ? $valid_role : get_option('default_role', 'subscriber'),
        'display_name' => (string)($assoc['display_name'] ?? ''),
        'first_name' => (string)($assoc['first_name'] ?? ''),
        'last_name' => (string)($assoc['last_name'] ?? ''),
        'nickname' => (string)($assoc['nickname'] ?? ''),
      ];

      $created = wp_insert_user($userdata);
      if (is_wp_error($created)) {
        return ['status' => 'errors', 'message' => 'WordPress create failed: ' . $created->get_error_message()];
      }

      $user_id = (int)$created;
      $row_state = 'created';
      $message_prefix = 'Created ' . $this->user_label(get_user_by('id', $user_id)) . '.';
    }

    if ($this->should_apply_scalar($assoc, 'manager_user_id', $clear_blanks)) {
      update_user_meta($user_id, EUM_Personnel::META_MANAGER_ID, $this->cell_int_assoc($assoc, 'manager_user_id'));
    }

    if (class_exists('EUM_Manager_IDs') && $this->should_apply_scalar($assoc, 'manager_id_override', $clear_blanks)) {
      $override = $this->cell_int_assoc($assoc, 'manager_id_override');
      if ($override > 0) update_user_meta($user_id, EUM_Manager_IDs::USER_META_OVERRIDE_ID, $override);
      else delete_user_meta($user_id, EUM_Manager_IDs::USER_META_OVERRIDE_ID);
    }

    if ($this->should_apply_scalar($assoc, 'it_access', $clear_blanks)) {
      update_user_meta($user_id, EUM_Access::META_IT, $this->bool_from_cell((string)($assoc['it_access'] ?? '')) ? '1' : '0');
    }
    if ($this->should_apply_scalar($assoc, 'hr_access', $clear_blanks)) {
      update_user_meta($user_id, EUM_Access::META_HR, $this->bool_from_cell((string)($assoc['hr_access'] ?? '')) ? '1' : '0');
    }

    if (class_exists('EUM_Personnel')) {
      $terminated_apply = $this->should_apply_scalar($assoc, 'terminated', $clear_blanks);
      $rehirable_apply = $this->should_apply_scalar($assoc, 'rehirable', $clear_blanks);
      if ($terminated_apply || $rehirable_apply) {
        $terminated = $terminated_apply ? $this->bool_from_cell((string)($assoc['terminated'] ?? '')) : EUM_Personnel::is_terminated($user_id);
        $rehirable = $rehirable_apply ? $this->bool_from_cell((string)($assoc['rehirable'] ?? '')) : EUM_Personnel::is_rehirable($user_id);
        EUM_Personnel::set_employment_status($user_id, $terminated, $rehirable, (int)get_current_user_id());
      }
    }

    $job_term_id = $this->current_leaf_term_id($user_id, 'eum_job_path');
    if ($this->should_apply_scalar($assoc, 'job_path', $clear_blanks)) {
      $job_path = (string)($assoc['job_path'] ?? '');
      $leaf_id = $this->ensure_hierarchical_path_term('eum_job_path', $job_path, '>');
      if ($job_path === '' && $clear_blanks) {
        wp_set_object_terms($user_id, [], 'eum_job_path', false);
        $job_term_id = 0;
      } elseif ($leaf_id > 0) {
        wp_set_object_terms($user_id, [$leaf_id], 'eum_job_path', false);
        $job_term_id = $leaf_id;
      }
    }

    if ($this->should_apply_scalar($assoc, 'location_path', $clear_blanks)) {
      $location_path = (string)($assoc['location_path'] ?? '');
      $leaf_id = $this->ensure_hierarchical_path_term('eum_location', $location_path, '>');
      if ($location_path === '' && $clear_blanks) {
        wp_set_object_terms($user_id, [], 'eum_location', false);
      } elseif ($leaf_id > 0) {
        wp_set_object_terms($user_id, [$leaf_id], 'eum_location', false);
      }
    }

    if ($this->should_apply_scalar($assoc, 'user_groups', $clear_blanks)) {
      $term_ids = $this->ensure_flat_terms('eum_user_group', (string)($assoc['user_groups'] ?? ''), ';');
      wp_set_object_terms($user_id, $term_ids, 'eum_user_group', false);
    }

    if ($this->should_apply_scalar($assoc, 'projects', $clear_blanks)) {
      $term_ids = $this->ensure_flat_terms('eum_project', (string)($assoc['projects'] ?? ''), ';');
      wp_set_object_terms($user_id, $term_ids, 'eum_project', false);
    }

    if (class_exists('EUM_Profiles')) {
      if ($this->should_apply_scalar($assoc, 'profile_image_url', $clear_blanks)) {
        $value = esc_url_raw((string)($assoc['profile_image_url'] ?? ''));
        if ($value !== '') update_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_URL, $value);
        else delete_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_URL);
      }
      if ($this->should_apply_scalar($assoc, 'profile_image_color', $clear_blanks)) {
        $color = sanitize_hex_color((string)($assoc['profile_image_color'] ?? ''));
        if ($color !== null && $color !== '') update_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_COLOR, $color);
        else delete_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_COLOR);
      }
    }

    $custom_fields = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    foreach ($def_map as $key => $type) {
      if (!$this->should_apply_scalar($assoc, $key, $clear_blanks)) continue;
      $raw = (string)($assoc[$key] ?? '');
      $custom_fields[$key] = $raw === '' ? '' : EUM_Fields::sanitize_field_value($key, $type, $raw);
    }
    update_user_meta($user_id, EUM_Fields::USER_META_KEY, $custom_fields);

    if ($this->should_apply_scalar($assoc, 'job_company_override', $clear_blanks)) {
      $override_division = (string)($assoc['job_company_override'] ?? '');
      if (class_exists('EUM_Company_Divisions')) {
        EUM_Company_Divisions::save_user_division_input($user_id, $override_division, true, $job_term_id);
      } else {
        if ($override_division !== '') update_user_meta($user_id, 'job_company', sanitize_text_field($override_division));
        elseif ($clear_blanks) delete_user_meta($user_id, 'job_company');
      }
    }

    if (class_exists('EUM_Taxonomies')) {
      EUM_Taxonomies::sync_user_classification_effects($user_id, (int)get_current_user_id(), 'Bulk Import Classification Sync');
    } elseif (class_exists('EUM_Assignments')) {
      $syncer = new EUM_Assignments();
      if (method_exists($syncer, 'sync_user')) $syncer->sync_user($user_id);
    }

    $message = $message_prefix;
    if (!empty($warnings)) {
      $message .= ' Warnings: ' . implode('; ', $warnings) . '.';
    }

    return ['status' => $row_state === 'created' ? 'created' : 'updated', 'message' => $message];
  }

  private function render_import_report(array $report): void {
    $summary = sprintf(
      'Import complete. Created %d, updated %d, skipped %d, errors %d.',
      (int)($report['created'] ?? 0),
      (int)($report['updated'] ?? 0),
      (int)($report['skipped'] ?? 0),
      (int)($report['errors'] ?? 0)
    );

    $notice_class = !empty($report['errors']) ? 'notice notice-warning' : 'notice notice-success';
    echo '<div class="' . esc_attr($notice_class) . '"><p>' . esc_html($summary) . '</p></div>';

    $messages = array_slice((array)($report['messages'] ?? []), 0, 200);
    if (empty($messages)) return;

    echo '<table class="widefat striped" style="max-width:1200px;"><thead><tr><th style="width:120px;">Result</th><th>Detail</th></tr></thead><tbody>';
    foreach ($messages as $row) {
      $type = sanitize_key((string)($row['type'] ?? 'success'));
      $label = $type === 'error' ? 'Error' : ($type === 'warning' ? 'Skipped' : 'OK');
      echo '<tr><td><strong>' . esc_html($label) . '</strong></td><td>' . esc_html((string)($row['text'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table>';
  }

  private function normalize_header($header): string {
    $header = strtolower(trim((string)$header));
    return preg_replace('/[^a-z0-9_]+/', '_', $header);
  }

  private function sanitize_role(string $role): string {
    $role = sanitize_key($role);
    if ($role === '') return '';
    $roles = wp_roles();
    return isset($roles->roles[$role]) ? $role : '';
  }

  private function primary_role_for_user(WP_User $user): string {
    $roles = is_array($user->roles) ? array_values($user->roles) : [];
    return !empty($roles) ? sanitize_key((string)$roles[0]) : '';
  }

  private function derive_login_from_assoc(array $assoc): string {
    $candidates = [
      (string)($assoc['user_login'] ?? ''),
      (string)($assoc['user_email'] ?? ''),
      trim(((string)($assoc['first_name'] ?? '')) . '.' . ((string)($assoc['last_name'] ?? ''))),
      (string)($assoc['display_name'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
      $candidate = trim((string)$candidate);
      if ($candidate === '') continue;
      if (str_contains($candidate, '@')) $candidate = (string)current(explode('@', $candidate));
      $candidate = sanitize_user(str_replace(' ', '.', $candidate), true);
      if ($candidate !== '') return $candidate;
    }

    return '';
  }

  private function unique_user_login(string $login_base): string {
    $login_base = sanitize_user($login_base, true);
    if ($login_base === '') return '';
    $login = $login_base;
    $i = 2;
    while (username_exists($login)) {
      $login = $login_base . $i;
      $i++;
      if ($i > 5000) return '';
    }
    return $login;
  }

  private function resolve_target_user(array $assoc): array {
    $user_id = $this->cell_int_assoc($assoc, 'user_id');
    if ($user_id > 0) {
      $user = get_user_by('id', $user_id);
      if ($user instanceof WP_User) return ['user' => $user, 'matched_by' => 'user_id'];
    }

    $email = sanitize_email((string)($assoc['user_email'] ?? ''));
    if ($email !== '') {
      $user = get_user_by('email', $email);
      if ($user instanceof WP_User) return ['user' => $user, 'matched_by' => 'user_email'];
    }

    $login = sanitize_user((string)($assoc['user_login'] ?? ''), true);
    if ($login !== '') {
      $user = get_user_by('login', $login);
      if ($user instanceof WP_User) return ['user' => $user, 'matched_by' => 'user_login'];
    }

    return ['user' => null, 'matched_by' => ''];
  }

  private function should_apply_scalar(array $assoc, string $key, bool $clear_blanks): bool {
    if (!array_key_exists($key, $assoc)) return false;
    $value = trim((string)$assoc[$key]);
    return $value !== '' || $clear_blanks;
  }

  private function cell_int_assoc(array $assoc, string $key): int {
    $value = isset($assoc[$key]) ? trim((string)$assoc[$key]) : '';
    return $value === '' ? 0 : (int)$value;
  }

  private function bool_from_cell(string $value): bool {
    $value = strtolower(trim($value));
    if ($value === '') return false;
    return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
  }

  private function user_label($user): string {
    if (!$user instanceof WP_User) return 'User';
    $name = $user->display_name ?: $user->user_login;
    return $name . ' (#' . (int)$user->ID . ')';
  }

  private function current_leaf_term_id(int $user_id, string $tax): int {
    $terms = wp_get_object_terms($user_id, $tax, ['fields' => 'ids']);
    if (is_wp_error($terms) || empty($terms)) return 0;
    return (int)$terms[0];
  }

  private function get_user_term_names(int $user_id, string $tax, string $sep): string {
    $terms = wp_get_object_terms($user_id, $tax);
    if (is_wp_error($terms) || empty($terms)) return '';
    $names = array_map(static fn($t) => $t->name, $terms);
    return implode($sep, $names);
  }

  private function get_user_tax_path(int $user_id, string $tax, string $sep): string {
    $terms = wp_get_object_terms($user_id, $tax);
    if (is_wp_error($terms) || empty($terms)) return '';
    $leaf = $terms[0];

    $anc = array_reverse(get_ancestors($leaf->term_id, $tax));
    $names = [];
    foreach ($anc as $aid) {
      $t = get_term($aid, $tax);
      if ($t && !is_wp_error($t)) $names[] = $t->name;
    }
    $names[] = $leaf->name;

    return implode($sep, $names);
  }

  private function ensure_hierarchical_path_term(string $tax, string $path, string $split_char = '>'): int {
    $path = trim($path);
    if ($path === '') return 0;

    $normalized = str_replace(['/', '→'], $split_char, $path);
    $parts = array_map('trim', explode($split_char, $normalized));
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
    if (empty($parts)) return 0;

    $parent = 0;
    $leaf_id = 0;
    foreach ($parts as $name) {
      $existing = get_terms([
        'taxonomy' => $tax,
        'hide_empty' => false,
        'name' => $name,
        'parent' => $parent,
        'number' => 1,
      ]);

      if (!is_wp_error($existing) && !empty($existing)) {
        $term = $existing[0];
        $leaf_id = (int)$term->term_id;
        $parent = (int)$term->term_id;
        continue;
      }

      $inserted = wp_insert_term($name, $tax, ['parent' => $parent]);
      if (is_wp_error($inserted)) {
        $maybe = get_term_by('name', $name, $tax);
        if ($maybe && !is_wp_error($maybe)) {
          $leaf_id = (int)$maybe->term_id;
          $parent = (int)$maybe->term_id;
          continue;
        }
        return 0;
      }

      $leaf_id = (int)$inserted['term_id'];
      $parent = $leaf_id;
    }

    return $leaf_id;
  }

  private function ensure_flat_terms(string $tax, string $list, string $delimiter = ';'): array {
    $list = trim($list);
    if ($list === '') return [];

    $parts = array_map('trim', explode($delimiter, $list));
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

    $ids = [];
    foreach ($parts as $name) {
      $term = get_term_by('name', $name, $tax);
      if ($term && !is_wp_error($term)) {
        $ids[] = (int)$term->term_id;
        continue;
      }
      $ins = wp_insert_term($name, $tax);
      if (!is_wp_error($ins) && !empty($ins['term_id'])) {
        $ids[] = (int)$ins['term_id'];
      }
    }
    return $ids;
  }
}
