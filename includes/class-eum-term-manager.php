<?php
if (!defined('ABSPATH')) exit;

class EUM_Term_Manager {

  private array $taxes = [
    'eum_job_path'   => ['label' => 'Job Paths',   'hierarchical' => true],
    'eum_location'   => ['label' => 'Locations',   'hierarchical' => true],
    'eum_user_group' => ['label' => 'User Groups', 'hierarchical' => false],
    'eum_project'    => ['label' => 'Projects',    'hierarchical' => false],
  ];

  public function init(): void {
    add_action('admin_menu', [$this, 'menu'], 30);
  }

  public function menu(): void {
    add_submenu_page(EUM_Admin::parent_slug('workforce'), 'Enterprise Terms', 'Enterprise Terms', 'read', 'eum-terms', [$this, 'render']);
  }

  public function render(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');

    $tax = isset($_GET['tax']) ? sanitize_key($_GET['tax']) : 'eum_job_path';
    if (!isset($this->taxes[$tax])) $tax = 'eum_job_path';
    $cfg = $this->taxes[$tax];
    $is_h = (bool)$cfg['hierarchical'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['eum_action'])) {
      $this->handle_post($tax);
      return;
    }

    $edit_term_id = isset($_GET['edit_term']) ? intval($_GET['edit_term']) : 0;
    $edit_term = null;
    if ($edit_term_id > 0) {
      $term = get_term($edit_term_id, $tax);
      if ($term && !is_wp_error($term)) $edit_term = $term;
    }

    $terms = get_terms([
      'taxonomy' => $tax,
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
    ]);
    if (is_wp_error($terms)) $terms = [];

    $bulk_form_id = 'eum-term-bulk-form';

    echo '<div class="wrap eum-term-manager">';
    echo '<h1>Enterprise Terms</h1>';

    if (!empty($_GET['eum_msg'])) {
      $msg = sanitize_text_field((string)$_GET['eum_msg']);
      $type = !empty($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
      echo '<div class="notice ' . ($type === 'error' ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($msg) . '</p></div>';
    }

    echo '<h2 class="nav-tab-wrapper">';
    foreach ($this->taxes as $slug => $tab) {
      $active = $slug === $tax ? ' nav-tab-active' : '';
      echo '<a class="nav-tab' . esc_attr($active) . '" href="' . esc_url(admin_url('admin.php?page=eum-terms&tax=' . $slug)) . '">' . esc_html($tab['label']) . '</a>';
    }
    echo '</h2>';

    $this->render_mapping_datalist();
    $this->render_division_value_template();

    echo '<div style="display:flex; gap:24px; align-items:flex-start; margin-top:16px;">';

    echo '<div style="flex:1.15; min-width:400px; max-width:660px;">';
    echo '<div class="postbox" style="padding:18px;">';
    echo '<h2 style="margin:0 0 12px 0;">' . ($edit_term ? 'Edit Term' : 'Add New Term') . '</h2>';
    echo '<form method="post">';
    wp_nonce_field('eum_terms_action', 'eum_terms_nonce');
    echo '<input type="hidden" name="eum_tax" value="' . esc_attr($tax) . '">';
    echo '<input type="hidden" name="eum_action" value="' . esc_attr($edit_term ? 'update' : 'create') . '">';
    if ($edit_term) echo '<input type="hidden" name="term_id" value="' . (int)$edit_term->term_id . '">';

    echo '<p><label><strong>Name</strong></label><br>';
    echo '<input type="text" name="term_name" class="regular-text" required value="' . esc_attr($edit_term ? (string)$edit_term->name : '') . '"></p>';

    if ($is_h) {
      echo '<p><label><strong>Parent</strong></label><br>' . $this->parent_dropdown($tax, $edit_term ? (int)$edit_term->parent : 0, $edit_term ? (int)$edit_term->term_id : 0) . '</p>';
      echo '<p class="description">Hierarchy example: Engineering / Platform / SRE</p>';
    } else {
      echo '<p class="description">Flat taxonomy (no parent/child).</p>';
    }

    $this->render_term_meta_panel($tax, $edit_term ? (int)$edit_term->term_id : 0);

    echo '<p style="margin-top:16px;">';
    echo '<button type="submit" class="button button-primary">' . ($edit_term ? 'Update' : 'Add') . '</button> ';
    if ($edit_term) echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=eum-terms&tax=' . $tax)) . '">Cancel</a>';
    echo '</p>';

    if ($edit_term && in_array($tax, ['eum_job_path', 'eum_location'], true)) {
      echo '<hr style="margin:18px 0;">';
      echo '<h3 style="margin:0 0 10px 0;">Sync Users Under This ' . esc_html($cfg['label']) . '</h3>';
      echo '<p class="description">Apply this term’s effective mappings to all users assigned to this term or its descendants.</p>';
      echo '<p><button type="submit" class="button" name="eum_action" value="sync_term_users">Sync Users Now</button></p>';
    }

    echo '</form>';
    echo '</div></div>';

    echo '<div style="flex:1.9; min-width:660px;">';
    echo '<div class="postbox" style="padding:18px;">';
    echo '<h2 style="margin:0 0 12px 0;">' . esc_html($cfg['label']) . '</h2>';

    if ($tax === 'eum_job_path') {
      $this->render_bulk_panel($bulk_form_id);
      $this->render_bulk_create_panel();
      echo '<div class="eum-job-path-search" style="margin:18px 0 10px;">';
      echo '<label for="eum-job-path-search" style="display:block;font-weight:600;margin-bottom:5px;">Search all Job Paths</label>';
      echo '<input type="search" id="eum-job-path-search" class="regular-text" style="width:min(560px,100%);" placeholder="Search a term or any part of its full path" autocomplete="off">';
      echo '<p class="description" style="margin:5px 0 0;">Search includes every Job Path across the site, including the full path above each term.</p>';
      echo '<p id="eum-job-path-search-results" class="description" aria-live="polite" style="margin:4px 0 0;"></p>';
      echo '</div>';
    }

    if (empty($terms)) {
      echo '<p>No terms yet.</p>';
    } elseif ($is_h) {
      $tree = $this->build_tree($terms);
      echo '<table class="widefat striped eum-terms-table"' . ($tax === 'eum_job_path' ? ' data-eum-job-path-search-table="1"' : '') . '>';
      echo '<thead><tr>';
      if ($tax === 'eum_job_path') echo '<th style="width:42px;"><input type="checkbox" data-eum-check-all="' . esc_attr($bulk_form_id) . '"></th>';
      echo '<th>Name</th><th>Full Path</th>';
      if ($tax === 'eum_job_path' && class_exists('EUM_Company_Divisions')) {
        echo '<th>Company Division</th><th>Email Domain</th><th>Job Titles</th>';
      }
      if (in_array($tax, ['eum_job_path', 'eum_location'], true)) {
        echo '<th>Data Fields</th><th>Parent Inheritance</th>';
      }
      echo '<th style="width:170px;">Actions</th></tr></thead><tbody>';
      $this->render_tree_rows($tax, $tree, 0, $bulk_form_id);
      echo '</tbody></table>';
      if ($tax === 'eum_job_path') {
        echo '<script>(function(){var input=document.getElementById("eum-job-path-search"),table=document.querySelector("[data-eum-job-path-search-table]");if(!input||!table)return;var rows=Array.prototype.slice.call(table.querySelectorAll("tbody tr[data-eum-job-path-search]")),status=document.getElementById("eum-job-path-search-results");var normalize=function(value){return String(value||"").toLocaleLowerCase().replace(/[\\/>→]+/g," ").replace(/\\s+/g," ").trim();};var filter=function(){var query=normalize(input.value),visible=0;rows.forEach(function(row){var matches=!query||normalize(row.getAttribute("data-eum-job-path-search")).indexOf(query)!==-1;row.style.display=matches?"":"none";if(matches)visible++;});if(status)status.textContent=query?visible+" Job Path(s) found.":"Showing all "+rows.length+" Job Paths.";};input.addEventListener("input",filter);filter();}());</script>';
      }
    } else {
      echo '<table class="widefat striped eum-terms-table"><thead><tr><th>Name</th>';
      if (in_array($tax, ['eum_user_group', 'eum_project'], true)) echo '<th>Manager IDs</th>';
      echo '<th style="width:170px;">Actions</th></tr></thead><tbody>';
      foreach ($terms as $term) {
        echo '<tr><td>' . esc_html($term->name) . '</td>';
        if (in_array($tax, ['eum_user_group', 'eum_project'], true)) {
          $manager_ids = class_exists('EUM_Manager_IDs') ? EUM_Manager_IDs::manager_ids_for_term($tax, (int)$term->term_id) : [];
          echo '<td>' . esc_html((string)count($manager_ids)) . '</td>';
        }
        echo '<td>' . $this->row_actions($tax, (int)$term->term_id) . '</td></tr>';
      }
      echo '</tbody></table>';
    }

    echo '</div></div>';
    echo '</div></div>';
  }

  private function render_term_meta_panel(string $tax, int $term_id): void {
    if (!class_exists('EUM_Assignments')) return;

    $all_groups = get_terms(['taxonomy' => 'eum_user_group', 'hide_empty' => false, 'orderby' => 'name']);
    if (is_wp_error($all_groups)) $all_groups = [];
    $all_projects = get_terms(['taxonomy' => 'eum_project', 'hide_empty' => false, 'orderby' => 'name']);
    if (is_wp_error($all_projects)) $all_projects = [];

    echo '<hr style="margin:18px 0;">';
    echo '<h3 style="margin:0 0 10px 0;">Defaults / Relationships</h3>';

    if ($tax === 'eum_job_path') {
      $sel_groups = $term_id ? (array)get_term_meta($term_id, EUM_Assignments::TM_JOB_DEFAULT_GROUPS, true) : [];
      $sel_projects = $term_id ? (array)get_term_meta($term_id, EUM_Assignments::TM_JOB_DEFAULT_PROJECTS, true) : [];
      $division_direct = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::direct_job_path_division($term_id) : '';
      $division_effective = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::effective_division_for_job_path($term_id) : '';
      $division_options = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::options_for_select() : [];

      echo '<p><label><strong>Company Division</strong></label><br>';
      echo '<select name="eum_job_company_division" class="regular-text" style="min-width:360px;">';
      echo '<option value="">— Inherit from parent —</option>';
      foreach ($division_options as $division_key => $division_label) {
        echo '<option value="' . esc_attr($division_key) . '" ' . selected($division_direct, $division_key, false) . '>' . esc_html($division_label) . '</option>';
      }
      echo '</select>';
      if ($division_effective !== '') {
        echo '<br><span class="description">Effective division: ' . esc_html(EUM_Company_Divisions::division_label($division_effective)) . '.</span>';
      } else {
        echo '<br><span class="description">If left blank, this Job Path inherits the nearest parent division.</span>';
      }
      echo '</p>';

      $effective_title_options = class_exists('EUM_Job_Titles') ? EUM_Job_Titles::title_labels_for_job_term($term_id) : [];
      echo '<p><label><strong>Assignable Job Titles</strong></label><br>';
      echo '<span class="description">Job Titles are now managed from <strong>Enterprise Users → Job Titles</strong>. Assign one title to many Job Paths there, and the Job Path selector will surface them as Title choices.</span>';
      if (!empty($effective_title_options)) {
        echo '<br><span class="description">Available here: ' . esc_html(implode(', ', $effective_title_options)) . '.</span>';
      }
      echo '</p>';

      $custom_task_rows = ($term_id && class_exists('EUM_Taxonomies')) ? EUM_Taxonomies::direct_schedule_task_options_for_term($term_id) : [];
      $effective_tasks = ($term_id && class_exists('EUM_Taxonomies')) ? EUM_Taxonomies::effective_schedule_task_options_for_term($term_id) : [];
      echo '<p><label><strong>Schedule Tasks</strong></label><br>';
      echo '<textarea name="eum_schedule_tasks" rows="6" class="large-text" placeholder="Open&#10;Close&#10;Cashier">' . esc_textarea(implode("\n", $custom_task_rows)) . '</textarea>';
      echo '<br><span class="description">Add one custom task per line for this Job Path. Reusable tasks can also be managed from <strong>Enterprise Users → Job Tasks</strong>. All Job Titles allowed on this Job Path are also automatically valid scheduling tasks.</span>';
      if (!empty($effective_tasks)) {
        echo '<br><span class="description">Effective tasks here: ' . esc_html(implode(', ', $effective_tasks)) . '.</span>';
      }
      echo '</p>';

      echo '<p><label><strong>Default User Groups</strong></label><br>' . $this->multi_select('eum_default_groups[]', $all_groups, $sel_groups) . '<br><span class="description">Users inherit these from this Job Path and its ancestors.</span></p>';
      echo '<p><label><strong>Default Projects</strong></label><br>' . $this->multi_select('eum_default_projects[]', $all_projects, $sel_projects) . '<br><span class="description">Users inherit these from this Job Path and its ancestors.</span></p>';
      $this->render_mapping_editor($tax, $term_id);
      return;
    }

    if ($tax === 'eum_location') {
      $sel_groups = $term_id ? (array)get_term_meta($term_id, EUM_Assignments::TM_LOC_DEFAULT_GROUPS, true) : [];
      $sel_projects = $term_id ? (array)get_term_meta($term_id, EUM_Assignments::TM_LOC_DEFAULT_PROJECTS, true) : [];
      echo '<p><label><strong>Default User Groups</strong></label><br>' . $this->multi_select('eum_loc_default_groups[]', $all_groups, $sel_groups) . '<br><span class="description">Users inherit these from this Location and its ancestors.</span></p>';
      echo '<p><label><strong>Default Projects</strong></label><br>' . $this->multi_select('eum_loc_default_projects[]', $all_projects, $sel_projects) . '<br><span class="description">Users inherit these from this Location and its ancestors.</span></p>';
      $this->render_mapping_editor($tax, $term_id);
      return;
    }

    if ($tax === 'eum_user_group') {
      $sel_includes = $term_id ? (array)get_term_meta($term_id, EUM_Assignments::TM_GROUP_INCLUDED_GROUPS, true) : [];
      $sel_projects = $term_id ? (array)get_term_meta($term_id, EUM_Assignments::TM_GROUP_DEFAULT_PROJECTS, true) : [];
      $all_groups_filtered = array_values(array_filter($all_groups, fn($g) => (int)$g->term_id !== (int)$term_id));
      echo '<p><label><strong>Included User Groups</strong></label><br>' . $this->multi_select('eum_included_groups[]', $all_groups_filtered, $sel_includes) . '<br><span class="description">Assigning this group also assigns included groups recursively.</span></p>';
      echo '<p><label><strong>Default Projects</strong></label><br>' . $this->multi_select('eum_group_default_projects[]', $all_projects, $sel_projects) . '<br><span class="description">Assigning this group also adds these projects.</span></p>';
      $public_labels = $term_id > 0 ? (string)get_term_meta($term_id, EUM_Public_Meta::GROUP_LABELS_TERM_META, true) : '';
      echo '<p><label><strong>Add Public Labels</strong></label><br><input type="text" class="regular-text" name="eum_public_group_labels" value="' . esc_attr($public_labels) . '" placeholder="Recruiting, Leadership"><br><span class="description">Added to members’ public_labels while they belong to this group; removed when they leave. Comma-separated, and additive to rule-defined labels.</span></p>';
      $this->render_term_manager_ids_select($tax, $term_id);
      return;
    }

    if ($tax === 'eum_project') {
      $sel_includes = $term_id ? (array)get_term_meta($term_id, EUM_Assignments::TM_PROJECT_INCLUDED_PROJECTS, true) : [];
      $sel_groups = $term_id ? (array)get_term_meta($term_id, EUM_Assignments::TM_PROJECT_DEFAULT_GROUPS, true) : [];
      $all_projects_filtered = array_values(array_filter($all_projects, fn($p) => (int)$p->term_id !== (int)$term_id));
      echo '<p><label><strong>Included Projects</strong></label><br>' . $this->multi_select('eum_included_projects[]', $all_projects_filtered, $sel_includes) . '<br><span class="description">Assigning this project also assigns included projects recursively.</span></p>';
      echo '<p><label><strong>Default User Groups</strong></label><br>' . $this->multi_select('eum_project_default_groups[]', $all_groups, $sel_groups) . '<br><span class="description">Assigning this project also adds these groups.</span></p>';
      $this->render_term_manager_ids_select($tax, $term_id);
    }
  }

  private function render_term_manager_ids_select(string $tax, int $term_id): void {
    if (!class_exists('EUM_Manager_IDs')) return;

    $manager_posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 5000,
      'orderby' => 'title',
      'order' => 'ASC',
    ]);
    if (!is_array($manager_posts)) $manager_posts = [];

    $selected = $term_id > 0 ? EUM_Manager_IDs::manager_ids_for_term($tax, $term_id) : [];
    $term_label = ($tax === 'eum_user_group') ? 'User Group' : 'Project';
    echo '<p><label><strong>Manager IDs</strong></label><br>' . $this->manager_multi_select('eum_term_manager_ids[]', $manager_posts, $selected) . '<br><span class="description">Managers assigned to these Manager IDs can manage members of this ' . esc_html($term_label) . '.</span></p>';
  }

  private function manager_multi_select(string $name, array $posts, array $selected_ids): string {
    $selected_ids = array_map('intval', (array)$selected_ids);
    $out = '<select multiple size="8" style="min-width:360px;" name="' . esc_attr($name) . '">';
    foreach ($posts as $post) {
      $id = (int)$post->ID;
      $out .= '<option value="' . $id . '" ' . (in_array($id, $selected_ids, true) ? 'selected' : '') . '>' . esc_html($post->post_title) . '</option>';
    }
    $out .= '</select>';
    return $out;
  }

  private function render_mapping_editor(string $tax, int $term_id): void {
    if (!class_exists('EUM_Taxonomies')) return;

    $rows = EUM_Taxonomies::mapping_rows_from_raw($term_id ? EUM_Taxonomies::term_meta_mapping_raw($term_id) : '');
    if (empty($rows)) $rows[] = ['key' => '', 'value' => ''];
    $inherit = $term_id ? EUM_Taxonomies::term_inherits_parent($term_id) : true;
    $effective = $term_id ? EUM_Taxonomies::effective_mapping_for_term($tax, $term_id) : [];
    $direct = $term_id ? EUM_Taxonomies::parse_term_meta_mapping(EUM_Taxonomies::term_meta_mapping_raw($term_id)) : [];

    echo '<div class="eum-map-builder">';
    echo '<h4 style="margin:18px 0 8px 0;">Path Data Mapping</h4>';
    if (is_taxonomy_hierarchical($tax)) {
      echo '<p><label><input type="checkbox" name="eum_user_meta_sync_inherit" value="1" ' . checked($inherit, true, false) . '> Inherit parent path values</label><br><span class="description">When enabled, this path uses its parent’s effective values first, then overrides them with the rows below.</span></p>';
    }

    echo '<div class="eum-map-builder__head"><div>Field / Meta Key</div><div>Value</div><div style="width:40px;"></div></div>';
    echo '<div class="eum-map-builder__rows" data-eum-map-rows="1">';
    foreach ($rows as $row) {
      $this->render_mapping_row((string)($row['key'] ?? ''), (string)($row['value'] ?? ''));
    }
    echo '</div>';
    echo '<p><button type="button" class="button" data-eum-add-map-row="1">Add Row</button></p>';
    echo '<p class="description">Use the suggestions for built-in fields, or type any raw user meta key. Custom profile fields use <code>custom:field_key</code>.</p>';

    if ($term_id > 0) {
      echo '<div class="eum-map-builder__effective">';
      echo '<h4 style="margin:16px 0 8px 0;">Effective Data After Inheritance</h4>';
      if (empty($effective)) {
        echo '<p class="description">No effective data mappings yet.</p>';
      } else {
        echo '<table class="widefat striped"><thead><tr><th>Field</th><th>Value</th><th>Source</th></tr></thead><tbody>';
        foreach ($effective as $key => $value) {
          $source = array_key_exists($key, $direct) ? 'Direct' : 'Inherited';
          echo '<tr><td>' . esc_html(EUM_Taxonomies::mapping_label_for_key((string)$key)) . '<br><code>' . esc_html((string)$key) . '</code></td><td>' . esc_html(is_scalar($value) ? (string)$value : wp_json_encode($value)) . '</td><td>' . esc_html($source) . '</td></tr>';
        }
        echo '</tbody></table>';
      }
      echo '</div>';
    }

    echo '</div>';
  }

  private function render_mapping_row(string $key = '', string $value = ''): void {
    echo '<div class="eum-map-row" data-eum-map-row="1">';
    echo '<div><input type="text" name="eum_map_key[]" value="' . esc_attr($key) . '" class="regular-text" list="eum-mapping-field-options" placeholder="custom:department or company_div"></div>';
    echo '<div class="eum-map-row__value">' . $this->mapping_value_input('eum_map_value[]', $value, $key) . '</div>';
    echo '<div><button type="button" class="button-link-delete" data-eum-remove-map-row="1" aria-label="Remove row">✕</button></div>';
    echo '</div>';
  }

  private function mapping_value_input(string $name, string $value, string $key): string {
    $normalized = class_exists('EUM_Taxonomies') ? EUM_Taxonomies::normalize_mapping_key($key) : $key;
    if ($normalized === 'job_company' && class_exists('EUM_Company_Divisions')) {
      $current_key = EUM_Company_Divisions::normalize_division_key($value);
      $current_label = $current_key !== '' ? EUM_Company_Divisions::division_label($current_key) : $value;
      $out = '<select name="' . esc_attr($name) . '" class="regular-text" data-eum-division-select="1">';
      $out .= '<option value="">— Select a Division —</option>';
      foreach (EUM_Company_Divisions::label_options_for_select() as $label => $display) {
        $out .= '<option value="' . esc_attr($label) . '" ' . selected($current_label, $label, false) . '>' . esc_html($display) . '</option>';
      }
      $out .= '</select>';
      return $out;
    }
    return '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="Value">';
  }

  private function render_mapping_datalist(): void {
    if (!class_exists('EUM_Taxonomies')) return;
    echo '<datalist id="eum-mapping-field-options">';
    foreach (EUM_Taxonomies::mapping_suggestions() as $option) {
      $key = (string)($option['key'] ?? '');
      if ($key === '') continue;
      echo '<option value="' . esc_attr($key) . '" label="' . esc_attr((string)($option['label'] ?? $key)) . '"></option>';
    }
    echo '</datalist>';
  }

  private function render_division_value_template(): void {
    if (!class_exists('EUM_Company_Divisions')) return;
    echo '<template id="eum-division-value-template">';
    echo '<select class="regular-text" data-eum-division-select="1"><option value="">— Select a Division —</option>';
    foreach (EUM_Company_Divisions::label_options_for_select() as $label => $display) {
      echo '<option value="' . esc_attr($label) . '">' . esc_html($display) . '</option>';
    }
    echo '</select>';
    echo '</template>';
  }

  private function render_bulk_panel(string $form_id): void {
    echo '<div class="eum-term-bulk-panel">';
    echo '<h3 style="margin:0 0 8px 0;">Bulk Update Selected Job Paths</h3>';
    echo '<p class="description" style="margin-top:0;">Use the checkboxes in the table below, then apply one field change across all selected paths. You can still edit any single path individually with its Edit button.</p>';
    echo '<form method="post" id="' . esc_attr($form_id) . '">';
    wp_nonce_field('eum_terms_action', 'eum_terms_nonce');
    echo '<input type="hidden" name="eum_tax" value="eum_job_path">';
    echo '<input type="hidden" name="eum_action" value="bulk_update_mappings">';
    echo '<div class="eum-term-bulk-grid">';
    echo '<p><label><strong>Change Type</strong></label><br><select name="eum_bulk_mode"><option value="add_update">Add / Update field</option><option value="remove">Remove field</option></select></p>';
    echo '<p><label><strong>Field / Meta Key</strong></label><br><input type="text" name="eum_bulk_key" class="regular-text" list="eum-mapping-field-options" placeholder="custom:company_div"></p>';
    echo '<p><label><strong>Value</strong></label><br><span class="eum-bulk-value-wrap">' . $this->mapping_value_input('eum_bulk_value', '', '') . '</span></p>';
    echo '<p><label><strong>Parent Inheritance</strong></label><br><select name="eum_bulk_inherit_mode"><option value="leave">Leave as is</option><option value="enable">Enable</option><option value="disable">Disable</option></select></p>';
    echo '</div>';
    echo '<p><label><input type="checkbox" name="eum_bulk_sync_users" value="1"> Sync affected users now after saving</label></p>';
    echo '<p><button type="submit" class="button button-primary">Apply to Selected Job Paths</button></p>';
    echo '</form>';
    echo '</div>';
  }

  private function render_bulk_create_panel(): void {
    echo '<div class="eum-term-bulk-panel" style="margin-top:18px;">';
    echo '<h3 style="margin:0 0 8px 0;">Bulk Create Job Paths</h3>';
    echo '<p class="description" style="margin-top:0;">Use this to create one or many Job Paths at once. Selected parent paths act as prefixes, so you can add the same child to many parents, create full child chains downward, or do both together. Every requested item creates a new record with its own ID, even when its name matches an existing path or another input line.</p>';
    echo '<form method="post">';
    wp_nonce_field('eum_terms_action', 'eum_terms_nonce');
    echo '<input type="hidden" name="eum_tax" value="eum_job_path">';
    echo '<input type="hidden" name="eum_action" value="bulk_create_paths">';
    echo '<div class="eum-term-bulk-grid">';
    echo '<p><label><strong>Parent Job Paths</strong></label><br>' . $this->job_path_multi_select('eum_bulk_create_parent_ids[]', []) . '<br><span class="description">Leave blank to create from the root. Selecting multiple parents will create under each of them.</span></p>';
    echo '<p><label><strong>Simple Child Job Paths</strong></label><br><textarea name="eum_bulk_create_child_names" rows="8" class="large-text" placeholder="Assistant Manager&#10;Supervisor&#10;Coordinator"></textarea><br><span class="description">One child per line. Each line is created directly beneath every selected parent.</span></p>';
    echo '<p><label><strong>Create Multiple Path Items Down</strong></label><br><textarea name="eum_bulk_create_down_paths" rows="8" class="large-text" placeholder="Director > Manager > Lead&#10;Operations > Fulfillment > Picker"></textarea><br><span class="description">One chain per line. Use <code>&gt;</code> or <code>/</code> between levels. If parent paths are selected, each chain is created beneath every selected parent.</span></p>';
    echo '</div>';
    echo '<p><button type="submit" class="button button-primary">Create Job Paths in Bulk</button></p>';
    echo '</form>';
    echo '</div>';
  }

  private function job_path_multi_select(string $name, array $selected_ids): string {
    $selected_ids = array_map('intval', (array)$selected_ids);
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
    if (is_wp_error($terms)) $terms = [];
    $tree = $this->build_tree($terms);
    $out = '<select multiple size="10" style="min-width:360px; max-width:100%;" name="' . esc_attr($name) . '">';
    $out .= $this->job_path_options_from_tree($tree, $selected_ids, 0);
    $out .= '</select>';
    return $out;
  }

  private function job_path_options_from_tree(array $tree, array $selected_ids, int $depth): string {
    $out = '';
    foreach ($tree as $node) {
      $term = $node['term'];
      $term_id = (int)$term->term_id;
      $out .= '<option value="' . $term_id . '" ' . (in_array($term_id, $selected_ids, true) ? 'selected' : '') . '>' . esc_html(str_repeat('— ', $depth) . $term->name) . '</option>';
      if (!empty($node['children'])) $out .= $this->job_path_options_from_tree($node['children'], $selected_ids, $depth + 1);
    }
    return $out;
  }

  private function multi_select(string $name, array $terms, array $selected_ids): string {
    $selected_ids = array_map('intval', (array)$selected_ids);
    $out = '<select multiple size="8" style="min-width:360px;" name="' . esc_attr($name) . '">';
    foreach ($terms as $term) {
      $id = (int)$term->term_id;
      $out .= '<option value="' . $id . '" ' . (in_array($id, $selected_ids, true) ? 'selected' : '') . '>' . esc_html($term->name) . '</option>';
    }
    $out .= '</select>';
    return $out;
  }

  private function handle_post(string $tax): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_terms_nonce']) || !wp_verify_nonce($_POST['eum_terms_nonce'], 'eum_terms_action')) {
      $this->redirect_with_msg($tax, 'Security check failed.', 'error');
    }

    $action = sanitize_key((string)($_POST['eum_action'] ?? ''));
    $name = isset($_POST['term_name']) ? sanitize_text_field((string)$_POST['term_name']) : '';
    $parent = isset($_POST['term_parent']) ? intval($_POST['term_parent']) : 0;
    $actor_user_id = get_current_user_id();

    if ($action === 'create') {
      $args = [];
      if (!empty($this->taxes[$tax]['hierarchical'])) $args['parent'] = max(0, $parent);
      $inserted = wp_insert_term($name, $tax, $args);
      if (is_wp_error($inserted)) $this->redirect_with_msg($tax, $inserted->get_error_message(), 'error');
      $this->save_term_meta($tax, (int)$inserted['term_id']);
      $this->redirect_with_msg($tax, 'Term added.');
    }

    if ($action === 'update') {
      $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
      if ($term_id <= 0) $this->redirect_with_msg($tax, 'Missing term id.', 'error');
      $args = ['name' => $name];
      if (!empty($this->taxes[$tax]['hierarchical'])) $args['parent'] = max(0, $parent);
      $updated = wp_update_term($term_id, $tax, $args);
      if (is_wp_error($updated)) $this->redirect_with_msg($tax, $updated->get_error_message(), 'error');
      $this->save_term_meta($tax, $term_id);
      $message = 'Term updated.';
      $this->redirect_with_msg($tax, $message);
    }

    if ($action === 'delete') {
      $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
      if ($term_id <= 0) $this->redirect_with_msg($tax, 'Missing term id.', 'error');
      $affected_group_users = $tax === 'eum_user_group' ? get_objects_in_term($term_id, 'eum_user_group') : [];
      if (is_wp_error($affected_group_users)) $affected_group_users = [];
      $deleted = wp_delete_term($term_id, $tax);
      if (is_wp_error($deleted)) $this->redirect_with_msg($tax, $deleted->get_error_message(), 'error');
      if ($tax === 'eum_user_group' && class_exists('EUM_Public_Meta')) {
        foreach (array_unique(array_map('intval', (array)$affected_group_users)) as $user_id) {
          EUM_Public_Meta::sync_user($user_id);
          if (class_exists('EUM_Site_Manager')) EUM_Site_Manager::queue_user_sync_for_user($user_id);
        }
      }
      $message = 'Term deleted.';
      $this->redirect_with_msg($tax, $message);
    }

    if ($action === 'sync_term_users') {
      $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
      if ($term_id <= 0) $this->redirect_with_msg($tax, 'Missing term id.', 'error');
      $this->save_term_meta($tax, $term_id);
      $count = class_exists('EUM_Taxonomies') ? EUM_Taxonomies::sync_users_for_term($tax, $term_id, $actor_user_id, 'Path Data Sync') : 0;
      $this->redirect_with_msg($tax, 'Synced ' . (int)$count . ' user(s).');
    }

    if ($action === 'bulk_update_mappings' && $tax === 'eum_job_path') {
      if (!class_exists('EUM_Taxonomies')) $this->redirect_with_msg($tax, 'Mapping tools are unavailable.', 'error');
      $term_ids = isset($_POST['eum_bulk_term_ids']) ? array_values(array_filter(array_map('intval', (array)$_POST['eum_bulk_term_ids']), fn($id) => $id > 0)) : [];
      if (empty($term_ids)) $this->redirect_with_msg($tax, 'Please select at least one job path.', 'error');

      $mode = sanitize_key((string)($_POST['eum_bulk_mode'] ?? 'add_update'));
      $key = EUM_Taxonomies::normalize_mapping_key((string)($_POST['eum_bulk_key'] ?? ''));
      $value = trim((string)($_POST['eum_bulk_value'] ?? ''));
      $inherit_mode = sanitize_key((string)($_POST['eum_bulk_inherit_mode'] ?? 'leave'));
      $sync_users = !empty($_POST['eum_bulk_sync_users']);

      if ($key === '') $this->redirect_with_msg($tax, 'Please enter a field or meta key for the bulk update.', 'error');
      if (class_exists('EUM_Public_Meta') && EUM_Public_Meta::is_public_meta_key($key)) $this->redirect_with_msg($tax, 'Public metadata is managed from Public Metadata Rules.', 'error');
      if ($mode === 'add_update' && $value === '') $this->redirect_with_msg($tax, 'Please enter a value for the bulk update.', 'error');

      $updated_count = 0;
      $synced_count = 0;
      foreach ($term_ids as $term_id) {
        $existing = EUM_Taxonomies::parse_term_meta_mapping(EUM_Taxonomies::term_meta_mapping_raw($term_id));
        if ($mode === 'remove') unset($existing[$key]);
        else $existing[$key] = $value;
        update_term_meta($term_id, EUM_Taxonomies::term_meta_mapping_key(), EUM_Taxonomies::mapping_rows_to_raw(EUM_Taxonomies::mapping_rows_from_raw($existing)));
        if ($inherit_mode === 'enable') update_term_meta($term_id, EUM_Taxonomies::term_meta_inherit_key(), 1);
        elseif ($inherit_mode === 'disable') update_term_meta($term_id, EUM_Taxonomies::term_meta_inherit_key(), 0);
        $updated_count++;
        if ($sync_users) $synced_count += EUM_Taxonomies::sync_users_for_term('eum_job_path', $term_id, $actor_user_id, 'Job Path Bulk Data Update');
      }

      $message = 'Updated ' . $updated_count . ' job path(s).';
      if ($sync_users) $message .= ' Other path-derived data was synced too.';
      $this->redirect_with_msg($tax, $message);
    }

    if ($action === 'bulk_create_paths' && $tax === 'eum_job_path') {
      $parent_ids = isset($_POST['eum_bulk_create_parent_ids']) ? array_values(array_unique(array_filter(array_map('intval', (array)$_POST['eum_bulk_create_parent_ids']), fn($id) => $id > 0 && term_exists($id, 'eum_job_path')))) : [];
      $child_names = $this->bulk_lines((string)($_POST['eum_bulk_create_child_names'] ?? ''));
      $chain_lines = $this->bulk_lines((string)($_POST['eum_bulk_create_down_paths'] ?? ''));
      if (empty($child_names) && empty($chain_lines)) $this->redirect_with_msg($tax, 'Enter at least one simple child path or one downward path chain.', 'error');

      $base_parent_ids = empty($parent_ids) ? [0] : $parent_ids;
      $created = 0;
      $processed_paths = 0;

      foreach ($base_parent_ids as $base_parent_id) {
        foreach ($child_names as $child_name) {
          $result = $this->create_job_path_term($child_name, (int)$base_parent_id);
          if (is_wp_error($result)) $this->redirect_with_msg($tax, $result->get_error_message(), 'error');
          $processed_paths++;
          $created++;
        }

        foreach ($chain_lines as $chain_line) {
          $segments = $this->bulk_chain_segments($chain_line);
          if (empty($segments)) continue;
          $current_parent_id = (int)$base_parent_id;
          foreach ($segments as $segment) {
            $result = $this->create_job_path_term($segment, $current_parent_id);
            if (is_wp_error($result)) $this->redirect_with_msg($tax, $result->get_error_message(), 'error');
            $processed_paths++;
            $created++;
            $current_parent_id = (int)($result['term_id'] ?? 0);
            if ($current_parent_id <= 0) $this->redirect_with_msg($tax, 'A Job Path could not be created.', 'error');
          }
        }
      }

      $message = 'Created ' . $created . ' distinct Job Path record(s) from ' . $processed_paths . ' requested item(s).';
      $this->redirect_with_msg($tax, $message);
    }

    $this->redirect_with_msg($tax, 'Unknown action.', 'error');
  }

  private function save_term_meta(string $tax, int $term_id): void {
    if (!class_exists('EUM_Assignments')) return;

    if ($tax === 'eum_job_path') {
      $division = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::normalize_division_key((string)($_POST['eum_job_company_division'] ?? '')) : '';
      if ($division !== '') update_term_meta($term_id, EUM_Company_Divisions::META_TERM_DIVISION, $division);
      else delete_term_meta($term_id, EUM_Company_Divisions::META_TERM_DIVISION);
      update_term_meta($term_id, EUM_Assignments::TM_JOB_DEFAULT_GROUPS, array_values(array_filter(array_map('intval', (array)($_POST['eum_default_groups'] ?? [])), fn($x) => $x > 0)));
      update_term_meta($term_id, EUM_Assignments::TM_JOB_DEFAULT_PROJECTS, array_values(array_filter(array_map('intval', (array)($_POST['eum_default_projects'] ?? [])), fn($x) => $x > 0)));
      if (class_exists('EUM_Taxonomies')) {
        update_term_meta($term_id, EUM_Taxonomies::term_meta_schedule_task_options_key(), implode("\n", EUM_Taxonomies::parse_schedule_task_options((string)($_POST['eum_schedule_tasks'] ?? ''))));
      }
      $this->save_mapping_meta($term_id);
      return;
    }

    if ($tax === 'eum_location') {
      update_term_meta($term_id, EUM_Assignments::TM_LOC_DEFAULT_GROUPS, array_values(array_filter(array_map('intval', (array)($_POST['eum_loc_default_groups'] ?? [])), fn($x) => $x > 0)));
      update_term_meta($term_id, EUM_Assignments::TM_LOC_DEFAULT_PROJECTS, array_values(array_filter(array_map('intval', (array)($_POST['eum_loc_default_projects'] ?? [])), fn($x) => $x > 0)));
      $this->save_mapping_meta($term_id);
      return;
    }

    if ($tax === 'eum_user_group') {
      $includes = array_values(array_filter(array_map('intval', (array)($_POST['eum_included_groups'] ?? [])), fn($x) => $x > 0 && $x !== $term_id));
      $projects = array_values(array_filter(array_map('intval', (array)($_POST['eum_group_default_projects'] ?? [])), fn($x) => $x > 0));
      update_term_meta($term_id, EUM_Assignments::TM_GROUP_INCLUDED_GROUPS, $includes);
      update_term_meta($term_id, EUM_Assignments::TM_GROUP_DEFAULT_PROJECTS, $projects);
      $public_labels = class_exists('EUM_Public_Meta')
        ? EUM_Public_Meta::sanitize_value('public_labels', wp_unslash((string)($_POST['eum_public_group_labels'] ?? '')))
        : sanitize_text_field((string)($_POST['eum_public_group_labels'] ?? ''));
      if ($public_labels !== '') update_term_meta($term_id, EUM_Public_Meta::GROUP_LABELS_TERM_META, $public_labels);
      else delete_term_meta($term_id, EUM_Public_Meta::GROUP_LABELS_TERM_META);
      $this->save_term_manager_ids($tax, $term_id);
      if (class_exists('EUM_Public_Meta')) EUM_Public_Meta::sync_users_for_group($term_id);
      return;
    }

    if ($tax === 'eum_project') {
      $includes = array_values(array_filter(array_map('intval', (array)($_POST['eum_included_projects'] ?? [])), fn($x) => $x > 0 && $x !== $term_id));
      $groups = array_values(array_filter(array_map('intval', (array)($_POST['eum_project_default_groups'] ?? [])), fn($x) => $x > 0));
      update_term_meta($term_id, EUM_Assignments::TM_PROJECT_INCLUDED_PROJECTS, $includes);
      update_term_meta($term_id, EUM_Assignments::TM_PROJECT_DEFAULT_GROUPS, $groups);
      $this->save_term_manager_ids($tax, $term_id);
    }
  }

  private function save_term_manager_ids(string $tax, int $term_id): void {
    if (!in_array($tax, ['eum_user_group', 'eum_project'], true) || !class_exists('EUM_Manager_IDs')) return;
    $vals = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['eum_term_manager_ids'] ?? [])), fn($id) => $id > 0 && get_post_type($id) === EUM_Manager_IDs::CPT)));
    $meta_key = EUM_Manager_IDs::term_manager_meta_key();
    if (empty($vals)) delete_term_meta($term_id, $meta_key);
    else update_term_meta($term_id, $meta_key, $vals);
  }

  private function save_mapping_meta(int $term_id): void {
    if (!class_exists('EUM_Taxonomies')) return;
    $rows = EUM_Taxonomies::normalize_mapping_rows_from_post($_POST['eum_map_key'] ?? [], $_POST['eum_map_value'] ?? []);
    update_term_meta($term_id, EUM_Taxonomies::term_meta_mapping_key(), EUM_Taxonomies::mapping_rows_to_raw($rows));
    update_term_meta($term_id, EUM_Taxonomies::term_meta_inherit_key(), !empty($_POST['eum_user_meta_sync_inherit']) ? 1 : 0);
  }


  private function bulk_lines(string $raw): array {
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    if (!is_array($lines)) return [];
    $out = [];
    foreach ($lines as $line) {
      $line = trim(wp_strip_all_tags((string)$line));
      if ($line === '') continue;
      $out[] = $line;
    }
    return array_values($out);
  }

  private function bulk_chain_segments(string $line): array {
    $parts = preg_split('/\s*(?:>|\/)\s*/', trim($line));
    if (!is_array($parts)) return [];
    $out = [];
    foreach ($parts as $part) {
      $part = trim(wp_strip_all_tags((string)$part));
      if ($part === '') continue;
      $out[] = $part;
    }
    return $out;
  }

  private function create_job_path_term(string $name, int $parent_id) {
    $name = sanitize_text_field($name);
    if ($name === '') return new WP_Error('empty_name', 'A Job Path name was empty.');

    // WordPress rejects same-name siblings unless a unique slug is explicitly
    // provided. The unique slug preserves the display name while ensuring each
    // bulk-created path receives a separate term ID.
    $slug_prefix = substr(sanitize_title($name), 0, 150);
    if ($slug_prefix === '') $slug_prefix = 'job-path';
    $slug = $slug_prefix . '-eum-' . str_replace('-', '', wp_generate_uuid4());
    $inserted = wp_insert_term($name, 'eum_job_path', [
      'parent' => max(0, $parent_id),
      'slug' => $slug,
    ]);
    if (is_wp_error($inserted)) return $inserted;
    return ['term_id' => (int)($inserted['term_id'] ?? 0), 'created' => true];
  }

  private function redirect_with_msg(string $tax, string $msg, string $type = 'success'): void {
    wp_safe_redirect(admin_url('admin.php?page=eum-terms&tax=' . $tax . '&eum_msg=' . rawurlencode($msg) . '&eum_type=' . $type));
    exit;
  }

  private function row_actions(string $tax, int $term_id): string {
    $html = '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=eum-terms&tax=' . $tax . '&edit_term=' . $term_id)) . '">Edit</a> ';
    $html .= '<form method="post" style="display:inline;">';
    $html .= wp_nonce_field('eum_terms_action', 'eum_terms_nonce', true, false);
    $html .= '<input type="hidden" name="eum_tax" value="' . esc_attr($tax) . '">';
    $html .= '<input type="hidden" name="eum_action" value="delete">';
    $html .= '<input type="hidden" name="term_id" value="' . $term_id . '">';
    $html .= '<button type="submit" class="button button-small" onclick="return confirm(\'Delete this term?\')">Delete</button>';
    $html .= '</form>';
    return $html;
  }

  private function parent_dropdown(string $tax, int $selected, int $exclude_term_id = 0): string {
    $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
    if (is_wp_error($terms)) $terms = [];
    $tree = $this->build_tree($terms);
    $out = '<select name="term_parent" class="regular-text"><option value="0">— None —</option>';
    $out .= $this->parent_options_from_tree($tree, $selected, $exclude_term_id, 0);
    $out .= '</select>';
    return $out;
  }

  private function parent_options_from_tree(array $tree, int $selected, int $exclude, int $depth): string {
    $out = '';
    foreach ($tree as $node) {
      $term = $node['term'];
      if (!($exclude && (int)$term->term_id === $exclude)) {
        $out .= '<option value="' . (int)$term->term_id . '" ' . selected((int)$term->term_id, $selected, false) . '>' . esc_html(str_repeat('— ', $depth) . $term->name) . '</option>';
      }
      if (!empty($node['children'])) $out .= $this->parent_options_from_tree($node['children'], $selected, $exclude, $depth + 1);
    }
    return $out;
  }

  private function build_tree(array $terms): array {
    $by_parent = [];
    foreach ($terms as $term) {
      $by_parent[(int)$term->parent][] = $term;
    }
    $make = function(int $parent_id) use (&$make, $by_parent): array {
      $nodes = [];
      foreach (($by_parent[$parent_id] ?? []) as $term) {
        $nodes[] = ['term' => $term, 'children' => $make((int)$term->term_id)];
      }
      return $nodes;
    };
    return $make(0);
  }

  private function render_tree_rows(string $tax, array $tree, int $depth, string $bulk_form_id = ''): void {
    foreach ($tree as $node) {
      $term = $node['term'];
      $term_id = (int)$term->term_id;
      $full = $this->term_full_path($tax, $term_id, ' / ');
      $mapping_count = class_exists('EUM_Taxonomies') ? count(EUM_Taxonomies::parse_term_meta_mapping(EUM_Taxonomies::term_meta_mapping_raw($term_id))) : 0;
      $inherits = class_exists('EUM_Taxonomies') ? EUM_Taxonomies::term_inherits_parent($term_id) : false;

      echo '<tr' . ($tax === 'eum_job_path' ? ' data-eum-job-path-search="' . esc_attr($full) . '"' : '') . '>';
      if ($tax === 'eum_job_path') {
        echo '<td><input type="checkbox" name="eum_bulk_term_ids[]" value="' . $term_id . '" form="' . esc_attr($bulk_form_id) . '" data-eum-check-item="' . esc_attr($bulk_form_id) . '"></td>';
      }
      echo '<td>' . esc_html(str_repeat('— ', $depth) . $term->name) . '</td>';
      echo '<td>' . esc_html($full) . '</td>';
      if ($tax === 'eum_job_path' && class_exists('EUM_Company_Divisions')) {
        $division = EUM_Company_Divisions::effective_division_for_job_path($term_id);
        $title_count = class_exists('EUM_Job_Titles') ? count(EUM_Job_Titles::title_labels_for_job_term($term_id)) : (class_exists('EUM_Taxonomies') ? count(EUM_Taxonomies::effective_job_title_options_for_term($term_id)) : 0);
        echo '<td>' . esc_html(EUM_Company_Divisions::division_label($division)) . '</td>';
        echo '<td>' . esc_html(EUM_Company_Divisions::division_domain($division)) . '</td>';
        echo '<td>' . esc_html((string)$title_count) . '</td>';
      }
      if (in_array($tax, ['eum_job_path', 'eum_location'], true)) {
        echo '<td>' . (int)$mapping_count . '</td>';
        echo '<td>' . ($inherits ? 'Yes' : 'No') . '</td>';
      }
      echo '<td>' . $this->row_actions($tax, $term_id) . '</td>';
      echo '</tr>';

      if (!empty($node['children'])) $this->render_tree_rows($tax, $node['children'], $depth + 1, $bulk_form_id);
    }
  }

  private function term_full_path(string $tax, int $term_id, string $sep = ' / '): string {
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
}
