<?php
if (!defined('ABSPATH')) exit;

class EUM_Visual_Bulk {

  public function init(): void {
    add_action('admin_menu', [$this, 'admin_menu'], 60);
    add_action('admin_post_eum_visual_bulk_save', [$this, 'handle_post']);
  }

  private function can_manage(): bool {
    return current_user_can('list_users') && class_exists('EUM_Access') && EUM_Access::is_it_or_hr(get_current_user_id());
  }

  public function admin_menu(): void {
    if (!$this->can_manage()) return;

    add_users_page(
      'Visual Bulk Editor',
      'Visual Bulk Editor',
      'list_users',
      'eum-visual-bulk',
      [$this, 'render_page']
    );
  }

  public function handle_post(): void {
    if (!$this->can_manage()) wp_die('Not allowed.');
    check_admin_referer('eum_visual_bulk_save', 'eum_visual_bulk_nonce');

    $mode = sanitize_key((string)($_POST['eum_visual_bulk_mode'] ?? ''));
    $return_url = $this->sanitize_return_url((string)($_POST['return_url'] ?? ''));

    $created = 0;
    $updated = 0;
    $errors = 0;
    $messages = [];
    $reason = sanitize_text_field((string)($_POST['bulk_reason'] ?? $_POST['bulk_profile_reason'] ?? $_POST['bulk_termination_reason'] ?? ''));

    if ($mode === 'bulk_apply') {
      $selected_ids = array_values(array_filter(array_map('intval', (array)($_POST['selected_user_ids'] ?? []))));
      $selected_ids = array_values(array_unique(array_filter($selected_ids, static fn($id) => $id > 0)));
      $field_key = sanitize_text_field((string)($_POST['bulk_field'] ?? ''));
      $catalog = $this->field_catalog();
      if (!empty($_POST['bulk_apply_filtered'])) {
        $selected_ids = $this->matching_user_ids_for_posted_context($catalog);
      } elseif (empty($selected_ids) && !empty($_POST['select_visible_page'])) {
        $selected_ids = array_values(array_filter(array_map('intval', (array)($_POST['visible_user_ids'] ?? []))));
        $selected_ids = array_values(array_unique(array_filter($selected_ids, static fn($id) => $id > 0)));
      }

      if (empty($selected_ids)) {
        $errors++;
        $messages[] = 'No users were selected.';
      } elseif (!isset($catalog[$field_key]) || empty($catalog[$field_key]['editable'])) {
        $errors++;
        $messages[] = 'Please choose a valid editable field.';
      } else {
        $employment_change = in_array($field_key, ['terminated', 'rehirable'], true);
        if ($reason === '') {
          $errors++;
          $messages[] = 'Choose a reason for the changes.';
        }
        $clear = !empty($_POST['bulk_clear_value']);
        $value = $this->bulk_value_from_post($field_key, $catalog[$field_key], $clear);
        foreach ($selected_ids as $user_id) {
          if ($reason === '') continue;
          if (class_exists('EUM_Profiles') && !in_array($reason, EUM_Profiles::reason_options_for_actor(get_current_user_id(), $user_id), true)) {
            $errors++;
            $messages[] = '#' . $user_id . ': The selected reason is not valid for this user.';
            continue;
          }
          try {
            $result = $this->apply_bulk_field_to_user($user_id, $field_key, $value, $clear, $reason);
          } catch (Throwable $e) {
            $result = new WP_Error('bulk_update_exception', 'Bulk update failed: ' . $e->getMessage());
          }
          if (is_wp_error($result)) {
            $errors++;
            $messages[] = '#' . $user_id . ': ' . $result->get_error_message();
            continue;
          }
          $updated++;
        }
      }
    } elseif ($mode === 'bulk_login_redirect') {
      $selected_ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_user_ids'] ?? [])), static fn($id) => $id > 0)));
      $catalog = $this->field_catalog();
      if (!empty($_POST['bulk_apply_filtered'])) {
        $filter_context = $this->posted_context($catalog);
        if ($filter_context['search'] === '' && $filter_context['filter_field'] === '') {
          $errors++;
          $messages[] = 'Add a search or filter before applying to all filtered matches.';
        } else {
          $selected_ids = $this->matching_user_ids_for_posted_context($catalog);
        }
      } elseif (empty($selected_ids) && !empty($_POST['select_visible_page'])) {
        $selected_ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['visible_user_ids'] ?? [])), static fn($id) => $id > 0)));
      }

      $redirect_action = sanitize_key((string)($_POST['bulk_login_redirect_action'] ?? 'set'));
      $destination = trim((string)wp_unslash($_POST['bulk_login_redirect_url'] ?? ''));
      if (empty($selected_ids)) {
        $errors++;
        $messages[] = 'Select users or choose all filtered matches.';
      } elseif (!in_array($redirect_action, ['set', 'clear'], true)) {
        $errors++;
        $messages[] = 'Choose whether to set or clear the one-time destination.';
      } elseif ($redirect_action === 'set' && $destination === '') {
        $errors++;
        $messages[] = 'Enter a destination to set.';
      } elseif ($reason === '') {
        $errors++;
        $messages[] = 'Choose a reason for the sign-in redirect change.';
      } else {
        foreach ($selected_ids as $user_id) {
          if (!get_userdata($user_id)) {
            $errors++;
            $messages[] = '#' . $user_id . ': User not found.';
            continue;
          }
          if (class_exists('EUM_Profiles') && !in_array($reason, EUM_Profiles::reason_options_for_actor(get_current_user_id(), $user_id), true)) {
            $errors++;
            $messages[] = '#' . $user_id . ': The selected reason is not valid for this user.';
            continue;
          }
          $result = $redirect_action === 'clear'
            ? EUM_Access::clear_one_time_login_redirect(get_current_user_id(), $user_id, $reason)
            : EUM_Access::set_one_time_login_redirect(get_current_user_id(), $user_id, $destination, $reason);
          if (is_wp_error($result)) {
            $errors++;
            $messages[] = '#' . $user_id . ': ' . $result->get_error_message();
            continue;
          }
          $updated++;
        }
      }
    } elseif ($mode === 'save_displayed') {
      $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? $_POST['rows'] : [];
      if (empty($rows)) {
        $errors++;
        $messages[] = 'No rows were posted from the editor.';
      } else {
        foreach ($rows as $raw_user_id => $row) {
          $user_id = (int)$raw_user_id;
          if ($user_id <= 0 || !is_array($row)) continue;
          $has_employment_changes = array_key_exists('terminated', $row) || array_key_exists('rehirable', $row);
          $has_profile_changes = count(array_diff(array_keys($row), ['terminated', 'rehirable'])) > 0;
          if (($has_profile_changes || $has_employment_changes) && $reason === '') {
            $errors++;
            $messages[] = '#' . $user_id . ': Provide a reason for the changes.';
            continue;
          }
          $valid_reasons = class_exists('EUM_Profiles') ? EUM_Profiles::reason_options_for_actor(get_current_user_id(), $user_id) : [];
          if (($has_profile_changes || $has_employment_changes) && !in_array($reason, $valid_reasons, true)) {
            $errors++;
            $messages[] = '#' . $user_id . ': The selected reason is not valid for this user.';
            continue;
          }
          try {
            $result = $this->apply_row_to_user($user_id, $row, true, $reason);
          } catch (Throwable $e) {
            $result = new WP_Error('bulk_update_exception', 'Bulk update failed: ' . $e->getMessage());
          }
          if (is_wp_error($result)) {
            $errors++;
            $messages[] = '#' . $user_id . ': ' . $result->get_error_message();
            continue;
          }
          $updated++;
        }
      }
    } else {
      $errors++;
      $messages[] = 'Unknown bulk editor action.';
    }

    $summary = [];
    if ($updated > 0) $summary[] = sprintf('Updated %d user%s.', $updated, $updated === 1 ? '' : 's');
    if ($created > 0) $summary[] = sprintf('Created %d user%s.', $created, $created === 1 ? '' : 's');
    if ($errors > 0) $summary[] = sprintf('Hit %d error%s.', $errors, $errors === 1 ? '' : 's');
    if (empty($summary)) $summary[] = 'No changes were applied.';

    if (!empty($messages)) {
      $summary[] = implode(' | ', array_slice($messages, 0, 5));
      if (count($messages) > 5) $summary[] = '…';
    }

    $query = [
      'eum_msg' => implode(' ', $summary),
      'eum_type' => $errors > 0 ? 'warning' : 'success',
    ];

    wp_safe_redirect(add_query_arg($query, $return_url));
    exit;
  }

  public function render_page(): void {
    if (!$this->can_manage()) wp_die('Not allowed.');

    $catalog = $this->field_catalog();
    $context = $this->current_context($catalog);
    try {
      $dataset = $this->load_dataset($catalog, $context);
    } catch (Throwable $e) {
      $dataset = ['total' => 0, 'page_rows' => [], 'page_count' => 0];
      echo '<div class="notice notice-error"><p>' . esc_html('Visual Bulk Editor failed to load data: ' . $e->getMessage()) . '</p></div>';
    }
    $return_url = $this->current_page_url($context);

    echo '<div class="wrap eum-visual-bulk-wrap">';
    echo '<h1 class="wp-heading-inline">Visual Bulk Editor</h1>';
    echo '<p class="description eum-visual-bulk__intro">Manage users with a more familiar list-table layout. Filter first, bulk-change selected users, then open Quick Edit on any row for deeper changes.</p>';

    $this->render_notice();
    $this->render_filter_form($catalog, $context, $dataset);

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-visual-bulk__list-form">';
    echo '<input type="hidden" name="action" value="eum_visual_bulk_save">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    $this->render_context_hidden_inputs($context);
    wp_nonce_field('eum_visual_bulk_save', 'eum_visual_bulk_nonce');

    $this->render_bulk_apply_box($catalog, 'top', $context, (int)$dataset['total']);
    $this->render_table($catalog, $dataset['page_rows']);
    $this->render_bulk_apply_box($catalog, 'bottom', $context, (int)$dataset['total']);
    $this->render_pagination((int)$dataset['total'], $context);

    echo '</form>';
    echo '</div>';
  }

  private function render_notice(): void {
    $msg = isset($_GET['eum_msg']) ? sanitize_text_field(wp_unslash((string)$_GET['eum_msg'])) : '';
    if ($msg === '') return;
    $type = isset($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
    $class = 'notice notice-success';
    if ($type === 'error') $class = 'notice notice-error';
    elseif ($type === 'warning') $class = 'notice notice-warning';
    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($msg) . '</p></div>';
  }

  private function render_filter_form(array $catalog, array $context, array $dataset): void {
    echo '<form method="get" class="eum-visual-bulk__toolbar">';
    echo '<input type="hidden" name="page" value="eum-visual-bulk">';

    echo '<div class="tablenav top">';
    echo '<div class="alignleft actions eum-visual-bulk__filters-left">';

    echo '<label class="screen-reader-text" for="eum-visual-bulk-filter-field">Filter field</label>';
    echo '<select id="eum-visual-bulk-filter-field" name="filter_field">';
    echo '<option value="">All fields</option>';
    foreach ($catalog as $key => $field) {
      echo '<option value="' . esc_attr($key) . '" ' . selected($context['filter_field'], $key, false) . '>' . esc_html($field['label']) . '</option>';
    }
    echo '</select>';

    echo '<label class="screen-reader-text" for="eum-visual-bulk-filter-compare">Compare</label>';
    echo '<select id="eum-visual-bulk-filter-compare" name="filter_compare">';
    $compare_options = [
      'contains' => 'Contains',
      'exact' => 'Exact',
      'starts' => 'Starts with',
      'blank' => 'Is blank',
      'not_blank' => 'Is not blank',
    ];
    foreach ($compare_options as $key => $label) {
      echo '<option value="' . esc_attr($key) . '" ' . selected($context['filter_compare'], $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    echo '<label class="screen-reader-text" for="eum-visual-bulk-filter-value">Filter value</label>';
    echo '<input id="eum-visual-bulk-filter-value" type="text" name="filter_value" value="' . esc_attr($context['filter_value']) . '" placeholder="Filter value">';

    echo '<label class="screen-reader-text" for="eum-visual-bulk-orderby">Sort by</label>';
    echo '<select id="eum-visual-bulk-orderby" name="orderby">';
    foreach ($catalog as $key => $field) {
      echo '<option value="' . esc_attr($key) . '" ' . selected($context['orderby'], $key, false) . '>' . esc_html('Sort: ' . $field['label']) . '</option>';
    }
    echo '</select>';

    echo '<select name="order">';
    echo '<option value="asc" ' . selected($context['order'], 'asc', false) . '>Ascending</option>';
    echo '<option value="desc" ' . selected($context['order'], 'desc', false) . '>Descending</option>';
    echo '</select>';

    echo '<select name="per_page">';
    foreach ([10, 25, 50, 100, 250] as $n) {
      echo '<option value="' . (int)$n . '" ' . selected((int)$context['per_page'], $n, false) . '>' . sprintf('%d / page', (int)$n) . '</option>';
    }
    echo '</select>';

    submit_button('Filter', 'secondary', '', false, ['class' => 'button']);
    echo '<a class="button" href="' . esc_url(admin_url('users.php?page=eum-visual-bulk')) . '">Reset</a>';
    echo '</div>';

    echo '<p class="search-box">';
    echo '<label class="screen-reader-text" for="eum-visual-bulk-search">Search users:</label>';
    echo '<input id="eum-visual-bulk-search" type="search" name="s" value="' . esc_attr($context['search']) . '" placeholder="Search users">';
    submit_button('Search Users', 'button', '', false, ['id' => 'search-submit']);
    echo '</p>';
    echo '</div>';

    echo '<div class="eum-visual-bulk__result-meta">';
    echo '<strong>' . (int)$dataset['page_count'] . '</strong> shown of <strong>' . (int)$dataset['total'] . '</strong> matched users.';
    if ($context['filter_field'] !== '' || $context['search'] !== '') {
      echo ' <span class="description">Filters are active.</span>';
    }
    echo '</div>';

    echo '</form>';
  }

  private function render_bulk_apply_box(array $catalog, string $position, array $context = [], int $total = 0): void {
    $roles = $this->role_options();
    $divisions = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::get_divisions() : [];
    $filters_active = !empty($context['search']) || !empty($context['filter_field']);

    echo '<div class="tablenav ' . ($position === 'top' ? 'top' : 'bottom') . ' eum-visual-bulk__bulk-bar">';

    if ($position === 'top') {
      echo '<div class="alignleft actions bulkactions eum-visual-bulk__bulk-controls">';
      echo '<label class="eum-visual-bulk__select-all-inline"><input type="checkbox" name="select_visible_page" value="1" data-eum-select-all="1"> Select all on this page</label>';
      if ($filters_active) {
        echo '<label class="eum-visual-bulk__select-all-inline"><input type="checkbox" name="bulk_apply_filtered" value="1"> Apply to all ' . (int)$total . ' filtered matches</label>';
      }

      echo '<label class="screen-reader-text" for="eum-bulk-field-' . esc_attr($position) . '">Bulk field</label>';
      echo '<select id="eum-bulk-field-' . esc_attr($position) . '" name="bulk_field" data-eum-bulk-field="1">';
      echo '<option value="">Bulk change field…</option>';
      foreach ($catalog as $key => $field) {
        if (empty($field['editable'])) continue;
        $kind = esc_attr((string)($field['input_kind'] ?? 'text'));
        echo '<option value="' . esc_attr($key) . '" data-input-kind="' . $kind . '">' . esc_html($field['label']) . '</option>';
      }
      echo '</select>';

      echo '<label class="eum-bulk-value eum-bulk-value--text" hidden><span class="screen-reader-text">Bulk value</span><input type="text" name="bulk_text_value" placeholder="Value"></label>';
      echo '<label class="eum-bulk-value eum-bulk-value--textarea" hidden><span class="screen-reader-text">Bulk value</span><textarea name="bulk_textarea_value" rows="2" placeholder="Value"></textarea></label>';
      echo '<label class="eum-bulk-value eum-bulk-value--number" hidden><span class="screen-reader-text">Bulk value</span><input type="number" step="1" name="bulk_number_value" placeholder="0"></label>';
      echo '<label class="eum-bulk-value eum-bulk-value--date" hidden><span class="screen-reader-text">Bulk value</span><input type="date" name="bulk_date_value"></label>';
      echo '<label class="eum-bulk-value eum-bulk-value--bool" hidden><span class="screen-reader-text">Bulk value</span><select name="bulk_bool_value"><option value="1">Yes</option><option value="0">No</option></select></label>';

      echo '<label class="eum-bulk-value eum-bulk-value--role" hidden><span class="screen-reader-text">Bulk value</span><select name="bulk_role_value">';
      echo '<option value="">Select role…</option>';
      foreach ($roles as $role_key => $role_label) {
        echo '<option value="' . esc_attr($role_key) . '">' . esc_html($role_label) . '</option>';
      }
      echo '</select></label>';

      echo '<label class="eum-bulk-value eum-bulk-value--division" hidden><span class="screen-reader-text">Bulk value</span><select name="bulk_division_value">';
      echo '<option value="">Inherit from Job Path</option>';
      foreach ($divisions as $key => $row) {
        echo '<option value="' . esc_attr($key) . '">' . esc_html((string)($row['label'] ?? $key)) . '</option>';
      }
      echo '</select></label>';

      echo '<label class="eum-visual-bulk__clear-flag"><input type="checkbox" name="bulk_clear_value" value="1"> Clear instead of set</label>';
      $reason_options = class_exists('EUM_Profiles') ? EUM_Profiles::reason_options_for_actor(get_current_user_id(), 0) : [];
      echo '<label class="eum-bulk-reason"><span>Change reason</span><select name="bulk_reason"><option value="">— Select a reason for the changes —</option>';
      foreach ($reason_options as $reason) echo '<option value="' . esc_attr($reason) . '">' . esc_html($reason) . '</option>';
      echo '</select><span class="description">One reason is applied to each selected change.</span></label>';
      echo '<button class="button action" type="submit" name="eum_visual_bulk_mode" value="bulk_apply">Apply</button>';
      echo '<div class="eum-visual-bulk__redirect-controls"><strong>One-time sign-in destination</strong>';
      echo '<select name="bulk_login_redirect_action"><option value="set">Set destination</option><option value="clear">Clear destination</option></select>';
      echo '<input type="text" name="bulk_login_redirect_url" placeholder="https://internal.example/page/ or /page/" aria-label="One-time sign-in destination">';
      echo '<button class="button" type="submit" name="eum_visual_bulk_mode" value="bulk_login_redirect">Apply to selected users</button>';
      echo '<span class="description">Uses the selected users above and the same change reason. You can apply it to every filtered match.</span></div>';
      echo '</div>';
    } else {
      echo '<div class="alignleft actions eum-visual-bulk__bulk-controls">';
      echo '<label class="eum-visual-bulk__select-all-inline"><input type="checkbox" name="select_visible_page" value="1" data-eum-select-all="1"> Select all on this page</label>';
      echo '<span class="description">Use the top bulk controls to set one field across the selected users.</span>';
      echo '</div>';
    }

    echo '<div class="alignright actions eum-visual-bulk__save-actions">';
    echo '<button class="button button-primary" type="submit" name="eum_visual_bulk_mode" value="save_displayed">Save Visible Changes</button>';
    echo '</div>';
    echo '</div>';
  }

  private function render_table(array $catalog, array $rows): void {
    $columns = $this->visible_columns($catalog);

    echo '<table class="wp-list-table widefat fixed striped table-view-list users eum-visual-bulk__table">';
    echo '<thead><tr>';
    echo '<td class="manage-column column-cb check-column"><input type="checkbox" name="select_visible_page" value="1" data-eum-select-all="1"></td>';
    foreach ($columns as $key => $label) {
      echo '<th scope="col" class="manage-column column-' . esc_attr($key) . '">' . esc_html($label) . '</th>';
    }
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
      echo '<tr class="no-items"><td class="colspanchange" colspan="' . (count($columns) + 1) . '">No users matched the current filters.</td></tr>';
      echo '</tbody></table>';
      return;
    }

    foreach ($rows as $row) {
      $user_id = (int)$row['user_id'];
      $name = (string)($row['values']['display_name'] ?? '');
      $employee_id = (string)($row['values']['employee_id'] ?? '');
      $email = (string)($row['values']['user_email'] ?? '');
      $role = (string)($row['values']['role'] ?? '');
      $division = $this->summary_value($row, 'effective_job_company');
      $job_path = (string)($row['values']['job_path'] ?? '');
      $location = (string)($row['values']['location_path'] ?? '');
      $manager = $this->manager_label_from_row($row);
      $job_title = $this->summary_job_title($row);
      $status = $this->employment_status_badges($row);

      echo '<tr class="iedit author-self level-0 eum-visual-bulk__summary-row" data-eum-row-id="' . $user_id . '">';
      echo '<th scope="row" class="check-column"><input type="hidden" name="visible_user_ids[]" value="' . $user_id . '"><input type="checkbox" name="selected_user_ids[]" value="' . $user_id . '" data-eum-row-select="1"></th>';

      echo '<td class="column-primary has-row-actions column-name">';
      echo '<div class="eum-visual-bulk__person">' . get_avatar($user_id, 32) . '<div>';
      echo '<strong>' . esc_html($name !== '' ? $name : ('User #' . $user_id)) . '</strong>';
      if ($job_title !== '') echo '<div class="description">' . esc_html($job_title) . '</div>';
      echo '</div></div>';
      echo '<div class="row-actions">';
      echo '<span class="edit"><button type="button" class="button-link" data-eum-quick-edit-toggle="' . $user_id . '">Quick Edit</button></span>';
      echo ' | <span class="view"><button type="button" class="button-link" data-eum-row-select-toggle="' . $user_id . '">Select</button></span>';
      echo '</div>';
      echo '</td>';

      echo '<td><code>' . esc_html((string)($row['values']['user_login'] ?? '')) . '</code></td>';
      echo '<td>' . ($employee_id !== '' ? '<code>' . esc_html($employee_id) . '</code>' : '—') . '</td>';
      echo '<td>' . ($email !== '' ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '—') . '</td>';
      echo '<td>' . esc_html($role !== '' ? $role : '—') . '</td>';
      echo '<td>' . esc_html($division !== '' ? $division : '—') . '</td>';
      echo '<td>' . esc_html($job_path !== '' ? $job_path : '—') . '</td>';
      echo '<td>' . esc_html($location !== '' ? $location : '—') . '</td>';
      echo '<td>' . esc_html($manager !== '' ? $manager : '—') . '</td>';
      echo '<td>' . $status . '</td>';
      echo '<td><button type="button" class="button button-small" data-eum-quick-edit-toggle="' . $user_id . '">Edit</button></td>';
      echo '</tr>';

      echo '<tr class="inline-edit-row inline-edit-row-post quick-edit-row quick-edit-row-post eum-visual-bulk__edit-row" data-eum-edit-row="' . $user_id . '" hidden>';
      echo '<td colspan="' . (count($columns) + 1) . '">';
      echo '<fieldset class="inline-edit-col-left eum-visual-bulk__editor-grid">';
      echo '<div class="inline-edit-col eum-visual-bulk__editor-section">';
      echo '<h4>Identity</h4>';
      echo $this->render_field_editor($user_id, 'display_name', $catalog['display_name'], $row);
      echo $this->render_field_editor($user_id, 'first_name', $catalog['first_name'], $row);
      echo $this->render_field_editor($user_id, 'last_name', $catalog['last_name'], $row);
      echo $this->render_field_editor($user_id, 'nickname', $catalog['nickname'], $row);
      echo $this->render_field_editor($user_id, 'user_email', $catalog['user_email'], $row);
      echo $this->render_field_editor($user_id, 'role', $catalog['role'], $row);
      echo '</div>';

      echo '<div class="inline-edit-col eum-visual-bulk__editor-section">';
      echo '<h4>Classification</h4>';
      echo $this->render_field_editor($user_id, 'job_company_override', $catalog['job_company_override'], $row);
      echo '<div class="description eum-visual-bulk__effective-note">Effective division: ' . esc_html($division !== '' ? $division : '—') . '</div>';
      echo $this->render_field_editor($user_id, 'job_path', $catalog['job_path'], $row);
      echo $this->render_field_editor($user_id, 'location_path', $catalog['location_path'], $row);
      echo $this->render_field_editor($user_id, 'user_groups', $catalog['user_groups'], $row);
      echo $this->render_field_editor($user_id, 'projects', $catalog['projects'], $row);
      echo '</div>';

      echo '<div class="inline-edit-col eum-visual-bulk__editor-section">';
      echo '<h4>Manager & access</h4>';
      echo $this->render_field_editor($user_id, 'manager_user_id', $catalog['manager_user_id'], $row);
      echo $this->render_field_editor($user_id, 'manager_id_override', $catalog['manager_id_override'], $row);
      echo $this->render_field_editor($user_id, 'it_access', $catalog['it_access'], $row);
      echo $this->render_field_editor($user_id, 'hr_access', $catalog['hr_access'], $row);
      echo $this->render_field_editor($user_id, 'terminated', $catalog['terminated'], $row);
      echo $this->render_field_editor($user_id, 'rehirable', $catalog['rehirable'], $row);
      echo '</div>';

      echo '<div class="inline-edit-col eum-visual-bulk__editor-section">';
      echo '<h4>Profile</h4>';
      echo $this->render_field_editor($user_id, 'profile_image_url', $catalog['profile_image_url'], $row);
      echo $this->render_field_editor($user_id, 'profile_image_color', $catalog['profile_image_color'], $row);
      foreach ($this->custom_field_definitions(true) as $custom_key => $def) {
        $field_key = 'custom:' . $custom_key;
        if (!isset($catalog[$field_key])) continue;
        echo $this->render_field_editor($user_id, $field_key, $catalog[$field_key], $row);
      }
      echo '</div>';
      echo '</fieldset>';

      echo '<div class="submit inline-edit-save">';
      echo '<button type="submit" class="button button-primary save" name="eum_visual_bulk_mode" value="save_displayed">Save Changes</button>';
      echo '<button type="button" class="button cancel" data-eum-quick-edit-cancel="' . $user_id . '">Cancel</button>';
      echo '<span class="spinner"></span>';
      echo '</div>';
      echo '</td>';
      echo '</tr>';
    }

    echo '</tbody>';
    echo '<tfoot><tr>';
    echo '<td class="manage-column column-cb check-column"><input type="checkbox" name="select_visible_page" value="1" data-eum-select-all="1"></td>';
    foreach ($columns as $key => $label) {
      echo '<th scope="col" class="manage-column column-' . esc_attr($key) . '">' . esc_html($label) . '</th>';
    }
    echo '</tr></tfoot>';
    echo '</table>';
  }

  private function visible_columns(array $catalog): array {
    return [
      'name' => 'Name',
      'login' => 'Username',
      'employee_id' => 'Employee ID',
      'email' => 'Email',
      'role' => 'Role',
      'division' => 'Division',
      'job_path' => 'Job Path',
      'location' => 'Location',
      'manager' => 'Manager',
      'status' => 'Status',
      'actions' => 'Actions',
    ];
  }

  private function render_field_editor(int $user_id, string $field_key, array $field, array $row): string {
    $value = (string)($row['values'][$field_key] ?? '');
    $html = '<label class="eum-visual-bulk__field">';
    $html .= '<span>' . esc_html((string)($field['label'] ?? $field_key)) . '</span>';
    $html .= $this->render_cell_input($user_id, $field_key, $field, $value, $row);
    $html .= '</label>';
    return $html;
  }

  private function summary_value(array $row, string $field_key): string {
    $value = (string)($row['values'][$field_key] ?? '');
    if ($field_key === 'effective_job_company' && $value !== '' && class_exists('EUM_Company_Divisions')) {
      return (string)EUM_Company_Divisions::division_label($value);
    }
    return $value;
  }

  private function summary_job_title(array $row): string {
    foreach (['custom:job_title', 'custom:title'] as $key) {
      $value = trim((string)($row['values'][$key] ?? ''));
      if ($value !== '') {
        if ($key === 'custom:job_title' && class_exists('EUM_Profiles')) return EUM_Profiles::job_title_label_from_value($value);
        return $value;
      }
    }
    $job_path = trim((string)($row['values']['job_path'] ?? ''));
    if ($job_path === '') return '';
    $parts = preg_split('/\s*>\s*/', $job_path);
    return is_array($parts) && !empty($parts) ? (string)end($parts) : $job_path;
  }

  private function manager_label_from_row(array $row): string {
    $manager_user_id = (int)($row['values']['manager_user_id'] ?? 0);
    if ($manager_user_id <= 0) return '';
    $user = get_user_by('id', $manager_user_id);
    if ($user instanceof WP_User) {
      return trim((string)$user->display_name) !== '' ? (string)$user->display_name : ('User #' . $manager_user_id);
    }
    return 'User #' . $manager_user_id;
  }

  private function employment_status_badges(array $row): string {
    $badges = [];
    $terminated = ((string)($row['values']['terminated'] ?? '0')) === '1';
    $rehirable = ((string)($row['values']['rehirable'] ?? '0')) === '1';
    $badges[] = '<span class="eum-visual-bulk__badge ' . ($terminated ? 'is-danger' : 'is-success') . '">' . esc_html($terminated ? 'Terminated' : 'Active') . '</span>';
    if ($terminated) {
      $badges[] = '<span class="eum-visual-bulk__badge ' . ($rehirable ? 'is-warning' : 'is-muted') . '">' . esc_html($rehirable ? 'Rehirable' : 'Not rehirable') . '</span>';
    }
    if (((string)($row['values']['it_access'] ?? '0')) === '1') $badges[] = '<span class="eum-visual-bulk__badge">IT</span>';
    if (((string)($row['values']['hr_access'] ?? '0')) === '1') $badges[] = '<span class="eum-visual-bulk__badge">HR</span>';
    $worker_type = trim((string)($row['values']['custom:worker_type'] ?? ''));
    if ($worker_type !== '' && class_exists('EUM_Profiles')) {
      $label = EUM_Profiles::worker_type_label($worker_type);
      if ($label !== '') $badges[] = '<span class="eum-visual-bulk__badge">' . esc_html($label) . '</span>';
    }
    return implode(' ', $badges);
  }

  private function render_cell_input(int $user_id, string $field_key, array $field, string $value, array $row): string {
    $name = 'rows[' . $user_id . '][' . $field_key . ']';
    $kind = (string)($field['input_kind'] ?? 'text');

    if (empty($field['editable'])) {
      return '<span>' . esc_html($value) . '</span>';
    }

    switch ($kind) {
      case 'email':
      case 'url':
      case 'text':
      case 'path':
      case 'multilist':
        return '<input type="' . esc_attr($kind === 'email' ? 'email' : ($kind === 'url' ? 'url' : 'text')) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text">';

      case 'number':
        return '<input type="number" step="1" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="small-text">';

      case 'textarea':
        return '<textarea name="' . esc_attr($name) . '" rows="3">' . esc_textarea($value) . '</textarea>';

      case 'date':
        return '<input type="date" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';

      case 'role':
        $html = '<select name="' . esc_attr($name) . '">';
        foreach ($this->role_options() as $role_key => $role_label) {
          $html .= '<option value="' . esc_attr($role_key) . '" ' . selected($value, $role_key, false) . '>' . esc_html($role_label) . '</option>';
        }
        $html .= '</select>';
        return $html;

      case 'division':
        $html = '<select name="' . esc_attr($name) . '">';
        $html .= '<option value="">— Inherit —</option>';
        $divisions = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::get_divisions() : [];
        foreach ($divisions as $key => $row_div) {
          $html .= '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html((string)($row_div['label'] ?? $key)) . '</option>';
        }
        $html .= '</select>';
        $effective = (string)($row['values']['effective_job_company'] ?? '');
        if ($effective !== '') {
          $effective_label = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::division_label($effective) : $effective;
          $html .= '<div class="description">Effective: ' . esc_html($effective_label) . '</div>';
        }
        return $html;

      case 'worker_type':
        if (class_exists('EUM_Profiles')) {
          return EUM_Profiles::render_worker_type_select_html($name, $value);
        }
        return '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text">';

      case 'job_title_select':
        if (class_exists('EUM_Profiles')) {
          $job_term_id = $this->current_leaf_term_id($user_id, 'eum_job_path');
          $html = EUM_Profiles::render_job_title_select_html($name, $value, $job_term_id);
          $options = EUM_Profiles::job_title_options_for_job_term($job_term_id);
          if (!empty($options)) $html .= '<div class="description">Selected from Job Path. Available titles: ' . esc_html(implode(', ', $options)) . '</div>';
          return $html;
        }
        return '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text">';

      case 'bool':
        return '<label class="eum-visual-bulk__bool"><input type="hidden" name="' . esc_attr($name) . '" value="0"><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($value, '1', false) . '> Yes</label>';

      default:
        return '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }
  }

  private function render_pagination(int $total, array $context): void {
    $per_page = max(1, (int)$context['per_page']);
    $pages = max(1, (int)ceil($total / $per_page));
    if ($pages <= 1) return;

    $base_args = $context;
    unset($base_args['paged']);
    $base_url = add_query_arg(array_merge(['page' => 'eum-visual-bulk'], $this->query_args_from_context($base_args)), admin_url('users.php'));

    echo '<div class="tablenav"><div class="tablenav-pages">';
    echo wp_kses_post((string)paginate_links([
      'base' => esc_url_raw(add_query_arg('paged', '%#%', $base_url)),
      'format' => '',
      'current' => max(1, (int)$context['paged']),
      'total' => $pages,
      'prev_text' => '&laquo;',
      'next_text' => '&raquo;',
    ]));
    echo '</div></div>';
  }

  private function current_context(array $catalog): array {
    return $this->normalize_context([
      'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash((string)$_GET['s'])) : (isset($_GET['search']) ? sanitize_text_field(wp_unslash((string)$_GET['search'])) : ''),
      'filter_field' => isset($_GET['filter_field']) ? sanitize_text_field(wp_unslash((string)$_GET['filter_field'])) : '',
      'filter_compare' => isset($_GET['filter_compare']) ? sanitize_key((string)$_GET['filter_compare']) : 'contains',
      'filter_value' => isset($_GET['filter_value']) ? sanitize_text_field(wp_unslash((string)$_GET['filter_value'])) : '',
      'orderby' => isset($_GET['orderby']) ? sanitize_text_field(wp_unslash((string)$_GET['orderby'])) : 'display_name',
      'order' => isset($_GET['order']) ? sanitize_key((string)$_GET['order']) : 'asc',
      'per_page' => isset($_GET['per_page']) ? max(1, min(250, (int)$_GET['per_page'])) : 25,
      'paged' => isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1,
    ], $catalog);
  }

  private function posted_context(array $catalog): array {
    $raw = isset($_POST['filter_context']) && is_array($_POST['filter_context']) ? wp_unslash($_POST['filter_context']) : [];
    return $this->normalize_context([
      'search' => sanitize_text_field((string)($raw['search'] ?? '')),
      'filter_field' => sanitize_text_field((string)($raw['filter_field'] ?? '')),
      'filter_compare' => sanitize_key((string)($raw['filter_compare'] ?? 'contains')),
      'filter_value' => sanitize_text_field((string)($raw['filter_value'] ?? '')),
      'orderby' => sanitize_text_field((string)($raw['orderby'] ?? 'display_name')),
      'order' => sanitize_key((string)($raw['order'] ?? 'asc')),
      'per_page' => max(1, min(250, (int)($raw['per_page'] ?? 25))),
      'paged' => max(1, (int)($raw['paged'] ?? 1)),
    ], $catalog);
  }

  private function normalize_context(array $ctx, array $catalog): array {
    $ctx = array_merge([
      'search' => '',
      'filter_field' => '',
      'filter_compare' => 'contains',
      'filter_value' => '',
      'orderby' => 'display_name',
      'order' => 'asc',
      'per_page' => 25,
      'paged' => 1,
    ], $ctx);

    if (!isset($catalog[$ctx['filter_field']])) $ctx['filter_field'] = '';
    if (!isset($catalog[$ctx['orderby']])) $ctx['orderby'] = 'display_name';
    if (!in_array($ctx['filter_compare'], ['contains', 'exact', 'starts', 'blank', 'not_blank'], true)) $ctx['filter_compare'] = 'contains';
    if (!in_array($ctx['order'], ['asc', 'desc'], true)) $ctx['order'] = 'asc';
    $ctx['search'] = sanitize_text_field((string)$ctx['search']);
    $ctx['filter_value'] = sanitize_text_field((string)$ctx['filter_value']);
    $ctx['per_page'] = max(1, min(250, (int)$ctx['per_page']));
    $ctx['paged'] = max(1, (int)$ctx['paged']);

    return $ctx;
  }

  private function render_context_hidden_inputs(array $context): void {
    foreach (['search', 'filter_field', 'filter_compare', 'filter_value', 'orderby', 'order', 'per_page', 'paged'] as $key) {
      echo '<input type="hidden" name="filter_context[' . esc_attr($key) . ']" value="' . esc_attr((string)($context[$key] ?? '')) . '">';
    }
  }

  private function matching_user_ids_for_posted_context(array $catalog): array {
    $context = $this->posted_context($catalog);
    $chunk = 200;
    $offset = 0;
    $ids = [];

    do {
      $batch = get_users(array_merge($this->base_user_query_args(), [
        'fields' => 'ids',
        'number' => $chunk,
        'offset' => $offset,
        'orderby' => 'ID',
        'order' => 'ASC',
      ]));
      if (!is_array($batch) || empty($batch)) break;
      foreach ($batch as $uid) {
        $uid = (int)$uid;
        if ($uid <= 0) continue;
        $snapshot = $this->build_user_snapshot($uid, $catalog);
        if (!$this->matches_search($snapshot, $context['search'])) continue;
        if (!$this->matches_filter($snapshot, $context['filter_field'], $context['filter_compare'], $context['filter_value'])) continue;
        $ids[] = $uid;
      }
      $offset += $chunk;
    } while (count($batch) === $chunk);

    return array_values(array_unique($ids));
  }

  private function current_page_url(array $context): string {
    return add_query_arg(array_merge(['page' => 'eum-visual-bulk'], $this->query_args_from_context($context)), admin_url('users.php'));
  }

  private function query_args_from_context(array $context): array {
    $args = $context;
    $search = (string)($args['search'] ?? '');
    unset($args['search']);
    if ($search !== '') $args['s'] = $search;
    return $args;
  }

  private function sanitize_return_url(string $url): string {
    $fallback = admin_url('users.php?page=eum-visual-bulk');
    $url = wp_validate_redirect($url, $fallback);
    return $url ?: $fallback;
  }

  private function load_dataset(array $catalog, array $context): array {
    if ($this->can_use_simple_query_mode($context)) {
      $simple = $this->load_dataset_simple($catalog, $context);
      if (is_array($simple)) return $simple;
    }

    return $this->load_dataset_chunked($catalog, $context);
  }

  private function can_use_simple_query_mode(array $context): bool {
    if ($context['search'] !== '') return false;
    if ($context['filter_field'] !== '') return false;
    return in_array($context['orderby'], ['user_id', 'user_login', 'user_email', 'display_name', 'first_name', 'last_name', 'nickname'], true);
  }

  private function base_user_query_args(): array {
    if (!class_exists('EUM_Personnel')) {
      return [];
    }

    return [
      'meta_query' => [
        'relation' => 'OR',
        [
          'key' => EUM_Personnel::META_TERMINATED,
          'compare' => 'NOT EXISTS',
        ],
        [
          'key' => EUM_Personnel::META_TERMINATED,
          'value' => '1',
          'compare' => '!=',
        ],
      ],
    ];
  }

  private function load_dataset_simple(array $catalog, array $context): ?array {
    $per_page = max(1, (int)$context['per_page']);
    $offset = ($context['paged'] - 1) * $per_page;
    $orderby = $context['orderby'];
    $order = strtoupper($context['order']) === 'DESC' ? 'DESC' : 'ASC';

    $query_args = array_merge($this->base_user_query_args(), [
      'number' => $per_page,
      'offset' => $offset,
      'count_total' => true,
      'fields' => 'all_with_meta',
      'order' => $order,
    ]);

    switch ($orderby) {
      case 'user_id':
        $query_args['orderby'] = 'ID';
        break;
      case 'user_login':
        $query_args['orderby'] = 'login';
        break;
      case 'user_email':
        $query_args['orderby'] = 'email';
        break;
      case 'display_name':
        $query_args['orderby'] = 'display_name';
        break;
      case 'first_name':
      case 'last_name':
      case 'nickname':
        $query_args['orderby'] = 'meta_value';
        $query_args['meta_key'] = $orderby;
        break;
      default:
        return null;
    }

    $query = new WP_User_Query($query_args);
    $users = $query->get_results();
    if (!is_array($users)) $users = [];

    $page_rows = [];
    foreach ($users as $wp_user) {
      if (!$wp_user instanceof WP_User) continue;
      $page_rows[] = $this->build_user_snapshot((int)$wp_user->ID, $catalog, $wp_user);
    }

    $total = (int)$query->get_total();
    if ($total === 0 && $context['paged'] === 1) {
      $fallback = $this->load_dataset_chunked($catalog, $context);
      if (!empty($fallback['total'])) {
        return $fallback;
      }
    }

    return [
      'total' => $total,
      'page_rows' => $page_rows,
      'page_count' => count($page_rows),
    ];
  }

  private function load_dataset_chunked(array $catalog, array $context): array {
    $chunk = 200;
    $offset_ids = 0;
    $user_ids = [];

    do {
      $batch = get_users(array_merge($this->base_user_query_args(), [
        'fields' => 'ids',
        'number' => $chunk,
        'offset' => $offset_ids,
        'orderby' => 'ID',
        'order' => 'ASC',
      ]));
      if (!is_array($batch) || empty($batch)) break;
      foreach ($batch as $uid) {
        $uid = (int)$uid;
        if ($uid > 0) $user_ids[] = $uid;
      }
      $offset_ids += $chunk;
    } while (count($batch) === $chunk);

    $rows = [];
    foreach ($user_ids as $user_id) {
      $snapshot = $this->build_user_snapshot($user_id, $catalog);
      if (!$this->matches_search($snapshot, $context['search'])) continue;
      if (!$this->matches_filter($snapshot, $context['filter_field'], $context['filter_compare'], $context['filter_value'])) continue;
      $rows[] = $snapshot;
    }

    $orderby = $context['orderby'];
    $order = $context['order'];
    $field = $catalog[$orderby] ?? ['sort_type' => 'text'];

    usort($rows, function (array $a, array $b) use ($orderby, $order, $field): int {
      $av = (string)($a['values'][$orderby] ?? '');
      $bv = (string)($b['values'][$orderby] ?? '');
      $cmp = $this->compare_values($av, $bv, (string)($field['sort_type'] ?? 'text'));
      if ($cmp === 0) {
        $cmp = ((int)$a['user_id'] <=> (int)$b['user_id']);
      }
      return $order === 'desc' ? -1 * $cmp : $cmp;
    });

    $total = count($rows);
    $per_page = max(1, (int)$context['per_page']);
    $offset = ($context['paged'] - 1) * $per_page;
    $page_rows = array_slice($rows, $offset, $per_page);

    return [
      'total' => $total,
      'page_rows' => $page_rows,
      'page_count' => count($page_rows),
    ];
  }

  private function build_user_snapshot(int $user_id, array $catalog, ?WP_User $wp_user = null): array {
    $wp_user = $wp_user instanceof WP_User ? $wp_user : get_user_by('id', $user_id);
    $custom_fields = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    $values = [];

    foreach ($catalog as $key => $field) {
      $values[$key] = $this->read_user_field_value($user_id, $key, $field, $wp_user, $custom_fields);
    }

    $blob_parts = [];
    foreach ($values as $v) {
      if ($v !== '') $blob_parts[] = strtolower((string)$v);
    }

    return [
      'user_id' => $user_id,
      'values' => $values,
      'search_blob' => implode(' | ', $blob_parts),
    ];
  }

  private function read_user_field_value(int $user_id, string $key, array $field, ?WP_User $wp_user = null, ?array $custom_fields = null): string {
    $wp_user = $wp_user instanceof WP_User ? $wp_user : get_user_by('id', $user_id);
    $custom_fields = is_array($custom_fields) ? $custom_fields : (class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : []);

    switch ($key) {
      case 'user_id':
        return (string)$user_id;
      case 'employee_id':
        return class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_id_for_user($user_id) : (string)($custom_fields['employee_id'] ?? get_user_meta($user_id, 'employee_id', true));
      case 'user_login':
        return $wp_user ? (string)$wp_user->user_login : '';
      case 'user_email':
        return $wp_user ? (string)$wp_user->user_email : '';
      case 'role':
        return $wp_user ? $this->primary_role_for_user($wp_user) : '';
      case 'display_name':
        return $wp_user ? (string)$wp_user->display_name : '';
      case 'first_name':
      case 'last_name':
      case 'nickname':
        return (string)get_user_meta($user_id, $key, true);
      case 'profile_image_url':
        return class_exists('EUM_Profiles') ? (string)get_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_URL, true) : '';
      case 'profile_image_color':
        return class_exists('EUM_Profiles') ? (string)get_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_COLOR, true) : '';
      case 'job_company_override':
        return class_exists('EUM_Company_Divisions') ? (string)EUM_Company_Divisions::user_division_override($user_id) : (string)get_user_meta($user_id, 'job_company', true);
      case 'effective_job_company':
        return class_exists('EUM_Company_Divisions') ? (string)EUM_Company_Divisions::effective_division_for_user($user_id) : (string)get_user_meta($user_id, 'job_company', true);
      case 'manager_user_id':
        return class_exists('EUM_Personnel') ? (string)((int)get_user_meta($user_id, EUM_Personnel::META_MANAGER_ID, true)) : '';
      case 'manager_id_override':
        return class_exists('EUM_Manager_IDs') ? (string)((int)get_user_meta($user_id, EUM_Manager_IDs::USER_META_OVERRIDE, true)) : '';
      case 'it_access':
        return class_exists('EUM_Access') && EUM_Access::is_it_user($user_id) ? '1' : '0';
      case 'hr_access':
        return class_exists('EUM_Access') && EUM_Access::is_hr_user($user_id) ? '1' : '0';
      case 'terminated':
        return class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id) ? '1' : '0';
      case 'rehirable':
        return class_exists('EUM_Personnel') && EUM_Personnel::is_rehirable($user_id) ? '1' : '0';
      case 'job_path':
        return $this->get_user_tax_path($user_id, 'eum_job_path', ' > ');
      case 'location_path':
        return $this->get_user_tax_path($user_id, 'eum_location', ' > ');
      case 'user_groups':
        return $this->get_user_term_names($user_id, 'eum_user_group', ';');
      case 'projects':
        return $this->get_user_term_names($user_id, 'eum_project', ';');
      default:
        if (str_starts_with($key, 'custom:')) {
          $custom_key = substr($key, 7);
          return is_scalar($custom_fields[$custom_key] ?? '') ? (string)($custom_fields[$custom_key] ?? '') : '';
        }
        return '';
    }
  }

  private function matches_search(array $snapshot, string $search): bool {
    $search = strtolower(trim($search));
    if ($search === '') return true;
    return str_contains((string)$snapshot['search_blob'], $search);
  }

  private function matches_filter(array $snapshot, string $field, string $compare, string $value): bool {
    if ($field === '') return true;
    $haystack = strtolower(trim((string)($snapshot['values'][$field] ?? '')));
    $needle = strtolower(trim($value));

    switch ($compare) {
      case 'blank':
        return $haystack === '';
      case 'not_blank':
        return $haystack !== '';
      case 'exact':
        return $haystack === $needle;
      case 'starts':
        return $needle === '' ? true : str_starts_with($haystack, $needle);
      case 'contains':
      default:
        return $needle === '' ? true : str_contains($haystack, $needle);
    }
  }

  private function compare_values(string $a, string $b, string $sort_type): int {
    switch ($sort_type) {
      case 'number':
      case 'bool':
        return ((float)$a <=> (float)$b);
      case 'date':
        return (strtotime($a ?: '1970-01-01') <=> strtotime($b ?: '1970-01-01'));
      case 'text':
      default:
        return strnatcasecmp($a, $b);
    }
  }

  public function apply_row_for_actor(int $actor_user_id, int $user_id, array $row, bool $clear_mode = false) {
    $actor_user_id = max(0, (int)$actor_user_id);
    $user_id = max(0, (int)$user_id);
    if ($actor_user_id <= 0 || $user_id <= 0) {
      return new WP_Error('invalid_actor_or_target', 'A valid actor and target user are required.');
    }
    if (!$this->actor_can_edit_target($actor_user_id, $user_id)) {
      return new WP_Error('forbidden_target', 'You cannot bulk-edit this user.');
    }

    $filtered = $this->filter_row_for_actor_target($actor_user_id, $user_id, $row);
    if (empty($filtered)) {
      return new WP_Error('no_editable_fields', 'No editable fields were supplied for this user.');
    }

    return $this->apply_row_to_user($user_id, $filtered, $clear_mode);
  }

  private function actor_can_edit_target(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) return true;
    return class_exists('EUM_Profiles') && EUM_Profiles::can_edit_target($actor_user_id, $target_user_id);
  }

  private function filter_row_for_actor_target(int $actor_user_id, int $target_user_id, array $row): array {
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) {
      return $row;
    }

    if (!class_exists('EUM_Profiles')) {
      return [];
    }

    $allowed_profile_fields = EUM_Profiles::editable_fields_for_actor($actor_user_id, $target_user_id);
    $allowed_profile_fields = array_values(array_filter(array_map('strval', $allowed_profile_fields)));
    if (empty($allowed_profile_fields)) return [];

    $filtered = [];
    foreach ($row as $field_key => $value) {
      if (in_array((string)$field_key, ['terminated', 'rehirable'], true)
        && EUM_Profiles::can_edit_target($actor_user_id, $target_user_id)) {
        $filtered[(string)$field_key] = $value;
        continue;
      }
      $profile_field_id = $this->profile_field_id_for_visual_key((string)$field_key);
      if ($profile_field_id === '' || !in_array($profile_field_id, $allowed_profile_fields, true)) continue;
      $filtered[(string)$field_key] = $value;
    }
    return $filtered;
  }

  private function profile_field_id_for_visual_key(string $field_key): string {
    if ($field_key === '') return '';
    if (str_starts_with($field_key, 'custom:')) return $field_key;

    $map = [
      'user_email' => 'core:user_email',
      'display_name' => 'core:display_name',
      'first_name' => 'core:first_name',
      'last_name' => 'core:last_name',
      'profile_image_url' => 'core:eum_profile_image_url',
      'profile_image_color' => 'core:eum_profile_image_color',
      'job_company_override' => 'core:job_company',
      'manager_user_id' => 'core:eum_manager_user_id',
      'job_path' => 'tax:eum_job_path',
      'location_path' => 'tax:eum_location',
      'user_groups' => 'tax:eum_user_group',
      'projects' => 'tax:eum_project',
      'terminated' => 'employment:terminated',
      'rehirable' => 'employment:rehirable',
    ];

    return (string)($map[$field_key] ?? '');
  }

  private function apply_bulk_field_to_user(int $user_id, string $field_key, string $value, bool $clear = false, string $reason = '') {
    if ($field_key === 'user_email' && $clear) {
      return new WP_Error('cannot_clear_email', 'Email cannot be cleared in bulk.');
    }
    if ($field_key === 'role' && $clear) {
      return new WP_Error('cannot_clear_role', 'Role cannot be cleared in bulk.');
    }
    return $this->apply_row_to_user($user_id, [$field_key => $clear ? '' : $value], $clear, $reason);
  }

  private function apply_row_to_user(int $user_id, array $row, bool $clear_mode = true, string $reason = '') {
    $user = get_user_by('id', $user_id);
    if (!$user instanceof WP_User) {
      return new WP_Error('missing_user', 'User no longer exists.');
    }

    $field_catalog = $this->field_catalog();
    $before_snapshot = $this->build_user_snapshot($user_id, $field_catalog, $user);
    $custom_defs = $this->custom_field_definitions();

    $userdata = ['ID' => $user_id];
    $wp_update_needed = false;

    foreach (['user_email', 'display_name', 'first_name', 'last_name', 'nickname'] as $core_key) {
      if (!array_key_exists($core_key, $row)) continue;
      $raw = is_scalar($row[$core_key]) ? (string)$row[$core_key] : '';
      if ($core_key === 'user_email') {
        $raw = sanitize_email($raw);
        if ($raw === '') continue;
      } else {
        $raw = sanitize_text_field($raw);
      }
      $userdata[$core_key] = $raw;
      $wp_update_needed = true;
    }

    if (array_key_exists('role', $row)) {
      $role = $this->sanitize_role((string)$row['role']);
      if ($role !== '') {
        $userdata['role'] = $role;
        $wp_update_needed = true;
      }
    }

    if ($wp_update_needed) {
      $updated = wp_update_user($userdata);
      if (is_wp_error($updated)) return $updated;
    }

    if (array_key_exists('manager_user_id', $row) && class_exists('EUM_Personnel')) {
      update_user_meta($user_id, EUM_Personnel::META_MANAGER_ID, max(0, (int)$row['manager_user_id']));
    }

    if (array_key_exists('manager_id_override', $row) && class_exists('EUM_Manager_IDs')) {
      $override = max(0, (int)$row['manager_id_override']);
      if ($override > 0) update_user_meta($user_id, EUM_Manager_IDs::USER_META_OVERRIDE, $override);
      else delete_user_meta($user_id, EUM_Manager_IDs::USER_META_OVERRIDE);
    }

    if (array_key_exists('it_access', $row) && class_exists('EUM_Access')) {
      update_user_meta($user_id, EUM_Access::META_IT, $this->bool_from_mixed($row['it_access']) ? '1' : '0');
    }
    if (array_key_exists('hr_access', $row) && class_exists('EUM_Access')) {
      update_user_meta($user_id, EUM_Access::META_HR, $this->bool_from_mixed($row['hr_access']) ? '1' : '0');
    }

    if (class_exists('EUM_Personnel') && (array_key_exists('terminated', $row) || array_key_exists('rehirable', $row))) {
      $terminated = array_key_exists('terminated', $row) ? $this->bool_from_mixed($row['terminated']) : EUM_Personnel::is_terminated($user_id);
      $rehirable = array_key_exists('rehirable', $row) ? $this->bool_from_mixed($row['rehirable']) : EUM_Personnel::is_rehirable($user_id);
      EUM_Personnel::set_employment_status($user_id, $terminated, $rehirable, (int)get_current_user_id());
    }

    $job_term_id = $this->current_leaf_term_id($user_id, 'eum_job_path');
    if (array_key_exists('job_path', $row)) {
      $job_path = trim((string)$row['job_path']);
      if ($job_path === '' && $clear_mode) {
        wp_set_object_terms($user_id, [], 'eum_job_path', false);
        $job_term_id = 0;
      } elseif ($job_path !== '') {
        $leaf_id = $this->ensure_hierarchical_path_term('eum_job_path', $job_path, '>');
        if ($leaf_id <= 0) return new WP_Error('invalid_job_path', 'Could not resolve Job Path: ' . $job_path);
        wp_set_object_terms($user_id, [$leaf_id], 'eum_job_path', false);
        $job_term_id = $leaf_id;
      }
    }

    if (array_key_exists('location_path', $row)) {
      $location_path = trim((string)$row['location_path']);
      if ($location_path === '' && $clear_mode) {
        wp_set_object_terms($user_id, [], 'eum_location', false);
      } elseif ($location_path !== '') {
        $leaf_id = $this->ensure_hierarchical_path_term('eum_location', $location_path, '>');
        if ($leaf_id <= 0) return new WP_Error('invalid_location_path', 'Could not resolve Location Path: ' . $location_path);
        wp_set_object_terms($user_id, [$leaf_id], 'eum_location', false);
      }
    }

    if (array_key_exists('user_groups', $row)) {
      $group_ids = $this->ensure_flat_terms('eum_user_group', (string)$row['user_groups'], ';');
      wp_set_object_terms($user_id, $group_ids, 'eum_user_group', false);
    }

    if (array_key_exists('projects', $row)) {
      $project_ids = $this->ensure_flat_terms('eum_project', (string)$row['projects'], ';');
      wp_set_object_terms($user_id, $project_ids, 'eum_project', false);
    }

    if (class_exists('EUM_Profiles')) {
      if (array_key_exists('profile_image_url', $row)) {
        $url = esc_url_raw((string)$row['profile_image_url']);
        if ($url !== '') update_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_URL, $url);
        else delete_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_URL);
      }
      if (array_key_exists('profile_image_color', $row)) {
        $color = sanitize_hex_color((string)$row['profile_image_color']);
        if ($color !== null && $color !== '') update_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_COLOR, $color);
        else delete_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_COLOR);
      }
    }

    $job_division_touched = array_key_exists('job_company_override', $row);
    if ($job_division_touched) {
      $division = sanitize_text_field((string)$row['job_company_override']);
      if (class_exists('EUM_Company_Divisions')) {
        EUM_Company_Divisions::save_user_division_input($user_id, $division, true, $job_term_id);
      } else {
        if ($division !== '') update_user_meta($user_id, 'job_company', $division);
        else delete_user_meta($user_id, 'job_company');
      }
    }

    if (class_exists('EUM_Company_Divisions') && ($job_term_id > 0 || $job_division_touched || array_key_exists('job_path', $row))) {
      $override_division = $job_division_touched
        ? sanitize_text_field((string)$row['job_company_override'])
        : EUM_Company_Divisions::user_division_override($user_id);
      $derived = EUM_Company_Divisions::derive_transfer_values((string)$user->user_login, $job_term_id, $override_division);
      if (!$job_division_touched && $derived['division'] !== '') {
        EUM_Company_Divisions::save_user_division_input($user_id, $derived['division'], false, $job_term_id);
      }
      if (!array_key_exists('user_email', $row) && $derived['email'] !== '' && is_email($derived['email'])) {
        wp_update_user(['ID' => $user_id, 'user_email' => sanitize_email($derived['email'])]);
      }
    }

    if (class_exists('EUM_Taxonomies')) {
      EUM_Taxonomies::sync_user_classification_effects($user_id, (int)get_current_user_id(), 'Visual Bulk Editor Sync');
    } elseif (class_exists('EUM_Assignments')) {
      $syncer = new EUM_Assignments();
      if (method_exists($syncer, 'sync_user')) $syncer->sync_user($user_id);
    }

    // Classification sync can apply inherited values from the newly selected Job Path.
    // Reapply only explicitly selected custom fields afterward so those edits take precedence.
    if (!empty($custom_defs) && class_exists('EUM_Fields')) {
      $custom_values = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      $custom_values_changed = false;
      foreach ($custom_defs as $custom_key => $type) {
        $field_key = 'custom:' . $custom_key;
        if (!array_key_exists($field_key, $row)) continue;
        $raw = is_scalar($row[$field_key]) ? (string)$row[$field_key] : '';
        $custom_values[$custom_key] = $raw === '' ? '' : EUM_Fields::sanitize_field_value($custom_key, $type, $raw);
        $custom_values_changed = true;
      }
      if ($custom_values_changed) update_user_meta($user_id, EUM_Fields::USER_META_KEY, $custom_values);
    }

    if ($reason !== '' && class_exists('EUM_Profiles')) {
      try {
        $after_snapshot = $this->build_user_snapshot($user_id, $field_catalog);
        $before = [];
        $after = [];
        foreach (array_keys($row) as $field_key) {
          if (array_key_exists($field_key, (array)($before_snapshot['values'] ?? []))) $before[$field_key] = $before_snapshot['values'][$field_key];
          if (array_key_exists($field_key, (array)($after_snapshot['values'] ?? []))) $after[$field_key] = $after_snapshot['values'][$field_key];
        }
        EUM_Profiles::insert_applied_change_log($user_id, (int)get_current_user_id(), $before, $after, $reason, 1);
      } catch (Throwable $e) {
        if (function_exists('eum_log_message')) eum_log_message('[EUM Visual Bulk] Could not write bulk change audit entry: ' . $e->getMessage());
      }
    }

    return true;
  }

  private function bulk_value_from_post(string $field_key, array $field, bool $clear): string {
    if ($clear) return '';
    $kind = (string)($field['input_kind'] ?? 'text');
    switch ($kind) {
      case 'textarea':
        return isset($_POST['bulk_textarea_value']) ? sanitize_textarea_field((string)wp_unslash($_POST['bulk_textarea_value'])) : '';
      case 'number':
        return isset($_POST['bulk_number_value']) ? trim((string)wp_unslash($_POST['bulk_number_value'])) : '';
      case 'date':
        return isset($_POST['bulk_date_value']) ? preg_replace('/[^0-9\-]/', '', (string)wp_unslash($_POST['bulk_date_value'])) : '';
      case 'bool':
        return !empty($_POST['bulk_bool_value']) ? (sanitize_text_field((string)wp_unslash($_POST['bulk_bool_value'])) === '1' ? '1' : '0') : '0';
      case 'role':
        return isset($_POST['bulk_role_value']) ? sanitize_key((string)wp_unslash($_POST['bulk_role_value'])) : '';
      case 'division':
        return isset($_POST['bulk_division_value']) ? sanitize_text_field((string)wp_unslash($_POST['bulk_division_value'])) : '';
      case 'email':
        return isset($_POST['bulk_text_value']) ? sanitize_email((string)wp_unslash($_POST['bulk_text_value'])) : '';
      case 'url':
        return isset($_POST['bulk_text_value']) ? esc_url_raw((string)wp_unslash($_POST['bulk_text_value'])) : '';
      default:
        return isset($_POST['bulk_text_value']) ? sanitize_text_field((string)wp_unslash($_POST['bulk_text_value'])) : '';
    }
  }

  private function field_catalog(): array {
    $fields = [
      'user_id' => ['label' => 'User ID', 'editable' => false, 'input_kind' => 'number', 'sort_type' => 'number'],
      'employee_id' => ['label' => 'Employee ID', 'editable' => false, 'input_kind' => 'text', 'sort_type' => 'text'],
      'user_login' => ['label' => 'Login', 'editable' => false, 'input_kind' => 'text', 'sort_type' => 'text'],
      'user_email' => ['label' => 'Email', 'editable' => true, 'input_kind' => 'email', 'sort_type' => 'text'],
      'role' => ['label' => 'Role', 'editable' => true, 'input_kind' => 'role', 'sort_type' => 'text'],
      'display_name' => ['label' => 'Display Name', 'editable' => true, 'input_kind' => 'text', 'sort_type' => 'text'],
      'first_name' => ['label' => 'First Name', 'editable' => true, 'input_kind' => 'text', 'sort_type' => 'text'],
      'last_name' => ['label' => 'Last Name', 'editable' => true, 'input_kind' => 'text', 'sort_type' => 'text'],
      'nickname' => ['label' => 'Nickname', 'editable' => true, 'input_kind' => 'text', 'sort_type' => 'text'],
      'profile_image_url' => ['label' => 'Profile Image URL', 'editable' => true, 'input_kind' => 'url', 'sort_type' => 'text'],
      'profile_image_color' => ['label' => 'Profile Accent', 'editable' => true, 'input_kind' => 'text', 'sort_type' => 'text'],
      'job_company_override' => ['label' => 'Division Override', 'editable' => true, 'input_kind' => 'division', 'sort_type' => 'text'],
      'effective_job_company' => ['label' => 'Effective Division', 'editable' => false, 'input_kind' => 'text', 'sort_type' => 'text'],
      'manager_user_id' => ['label' => 'Manager User ID', 'editable' => true, 'input_kind' => 'number', 'sort_type' => 'number'],
      'manager_id_override' => ['label' => 'Manager ID Override', 'editable' => true, 'input_kind' => 'number', 'sort_type' => 'number'],
      'it_access' => ['label' => 'IT Access', 'editable' => true, 'input_kind' => 'bool', 'sort_type' => 'bool'],
      'hr_access' => ['label' => 'HR Access', 'editable' => true, 'input_kind' => 'bool', 'sort_type' => 'bool'],
      'terminated' => ['label' => 'Terminated', 'editable' => true, 'input_kind' => 'bool', 'sort_type' => 'bool'],
      'rehirable' => ['label' => 'Rehirable', 'editable' => true, 'input_kind' => 'bool', 'sort_type' => 'bool'],
      'job_path' => ['label' => 'Job Path', 'editable' => true, 'input_kind' => 'path', 'sort_type' => 'text'],
      'location_path' => ['label' => 'Location Path', 'editable' => true, 'input_kind' => 'path', 'sort_type' => 'text'],
      'user_groups' => ['label' => 'User Groups', 'editable' => true, 'input_kind' => 'multilist', 'sort_type' => 'text'],
      'projects' => ['label' => 'Projects', 'editable' => true, 'input_kind' => 'multilist', 'sort_type' => 'text'],
    ];

    foreach ($this->custom_field_definitions(true) as $custom_key => $def) {
      $input_kind = $this->input_kind_from_type((string)($def['type'] ?? 'text'));
      if ($custom_key === 'job_title') $input_kind = 'job_title_select';
      if ($custom_key === 'worker_type') $input_kind = 'worker_type';
      $fields['custom:' . $custom_key] = [
        'label' => (string)($def['label'] ?? $custom_key),
        'editable' => true,
        'input_kind' => $input_kind,
        'sort_type' => $this->sort_type_from_field_type((string)($def['type'] ?? 'text')),
      ];
    }

    return $fields;
  }

  private function custom_field_definitions(bool $with_meta = false): array {
    $out = [];
    if (!class_exists('EUM_Fields')) return $out;
    foreach (EUM_Fields::get_definitions() as $def) {
      $key = sanitize_key((string)($def['key'] ?? ''));
      if ($key === '') continue;
      if ($key === 'employee_id') continue;
      if ($with_meta) {
        $out[$key] = [
          'label' => sanitize_text_field((string)($def['label'] ?? $key)),
          'type' => sanitize_key((string)($def['type'] ?? 'text')),
        ];
      } else {
        $out[$key] = sanitize_key((string)($def['type'] ?? 'text'));
      }
    }
    return $out;
  }

  private function input_kind_from_type(string $type): string {
    switch ($type) {
      case 'textarea':
        return 'textarea';
      case 'number':
        return 'number';
      case 'date':
        return 'date';
      case 'email':
        return 'email';
      case 'url':
        return 'url';
      default:
        return 'text';
    }
  }

  private function sort_type_from_field_type(string $type): string {
    switch ($type) {
      case 'number':
        return 'number';
      case 'date':
        return 'date';
      default:
        return 'text';
    }
  }

  private function role_options(): array {
    $roles = wp_roles();
    $out = [];
    foreach ((array)$roles->roles as $key => $data) {
      $out[$key] = (string)($data['name'] ?? $key);
    }
    return $out;
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

  private function bool_from_mixed($value): bool {
    if (is_bool($value)) return $value;
    $value = strtolower(trim(is_scalar($value) ? (string)$value : ''));
    return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
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
