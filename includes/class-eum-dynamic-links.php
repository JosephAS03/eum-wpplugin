<?php
if (!defined('ABSPATH')) exit;

class EUM_Dynamic_Links {

  const CPT = 'eum_dynamic_link';
  const HEADER_CPT = 'eum_dl_header';

  // Taxonomies (avoid clashing with WP categories/tags)
  const TAX_CATEGORY = 'eum_dl_category';
  const TAX_TAG      = 'eum_dl_tag';

  // Post meta keys
  const META_URL           = 'eum_dl_url';
  const META_TARGET        = 'eum_dl_target'; // _self|_blank
  const META_DESC          = 'eum_dl_desc';
  const META_ALLOWED_JOBS  = 'eum_dl_allowed_job_paths';   // array of term IDs (eum_job_path)
  const META_ALLOWED_GROUP = 'eum_dl_allowed_user_groups'; // array of term IDs (eum_user_group)
  const META_REQUIRE_RULES = 'eum_dl_require_rules';       // '1' => must match at least one rule
  const META_DASHBOARD_HEADER = 'eum_dl_dashboard_header'; // optional group heading for related Dynamic Links
  const META_HEADER_VALUE = 'eum_dl_header_value';
  const META_DASHBOARD_HEADER_ORDER = 'eum_dl_dashboard_header_order'; // numeric order for header groups
  const META_DASHBOARD_ORDER  = 'eum_dl_dashboard_order';  // numeric default order
  const META_DASHBOARD_CATEGORY_ORDERS = 'eum_dl_dashboard_category_orders'; // [term_id => numeric order]

  const TERM_META_DASHBOARD_ORDER = 'eum_dl_category_dashboard_order';

  public function init(): void {
    add_action('init', [$this, 'register']);
    add_action('admin_init', [$this, 'sync_header_menu_items'], 20);
    add_action('admin_menu', [$this, 'menu'], 45);
    add_filter('wp_setup_nav_menu_item', [$this, 'setup_dynamic_link_menu_item']);
    add_filter('wp_nav_menu_objects', [$this, 'filter_dynamic_link_menu_items'], 25, 2);

    // Post handlers
    add_action('admin_post_eum_dl_save', [$this, 'handle_save']);
    add_action('admin_post_eum_dl_delete', [$this, 'handle_delete']);

    // ✅ Term quick-add handler must be registered here (not inside render)
    add_action('admin_post_eum_dl_save_term', [$this, 'handle_save_term']);
  }

  public function register(): void {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Dynamic Links',
        'singular_name' => 'Dynamic Link',
      ],
      'public' => false,
      'show_in_nav_menus' => true,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);

    register_post_type(self::HEADER_CPT, [
      'labels' => [
        'name' => 'Dynamic Link Headers',
        'singular_name' => 'Dynamic Link Header',
      ],
      'public' => false,
      'show_in_nav_menus' => true,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);

    register_taxonomy(self::TAX_CATEGORY, self::CPT, [
      'labels' => [
        'name' => 'Dynamic Link Categories',
        'singular_name' => 'Dynamic Link Category',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'hierarchical' => true,
      'show_admin_column' => false,
    ]);

    register_taxonomy(self::TAX_TAG, self::CPT, [
      'labels' => [
        'name' => 'Dynamic Link Tags',
        'singular_name' => 'Dynamic Link Tag',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'hierarchical' => false,
      'show_admin_column' => false,
    ]);
  }

  public function menu(): void {
    add_submenu_page(
      EUM_Admin::parent_slug('experience'),
      'Dynamic Links',
      'Dynamic Links',
      'read',
      'eum-dynamic-links',
      [$this, 'render']
    );
  }

  public function render(): void {
    if (!EUM_Access::current_user_is_it()) {
      wp_die('Not allowed.');
    }

    $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
    $edit = $edit_id ? get_post($edit_id) : null;
    if ($edit && $edit->post_type !== self::CPT) $edit = null;

    if (!empty($_GET['eum_msg'])) {
      $msg  = sanitize_text_field((string)$_GET['eum_msg']);
      $type = !empty($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
      $cls  = ($type === 'error') ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="'.$cls.'"><p>'.esc_html($msg).'</p></div>';
    }

    // Load terms for selectors
    $cats = get_terms(['taxonomy' => self::TAX_CATEGORY, 'hide_empty' => false, 'orderby' => 'name']);
    if (is_wp_error($cats)) $cats = [];
    $cats = $this->sorted_category_terms((array)$cats);

    $tags = get_terms(['taxonomy' => self::TAX_TAG, 'hide_empty' => false, 'orderby' => 'name']);
    if (is_wp_error($tags)) $tags = [];

    $job_terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name']);
    if (is_wp_error($job_terms)) $job_terms = [];

    $group_terms = get_terms(['taxonomy' => 'eum_user_group', 'hide_empty' => false, 'orderby' => 'name']);
    if (is_wp_error($group_terms)) $group_terms = [];

    // Edit values
    $title   = $edit ? $edit->post_title : '';
    $url     = $edit ? (string)get_post_meta($edit->ID, self::META_URL, true) : '';
    $target  = $edit ? (string)get_post_meta($edit->ID, self::META_TARGET, true) : '_self';
    $desc    = $edit ? (string)get_post_meta($edit->ID, self::META_DESC, true) : '';
    $dashboard_header = $edit ? self::dashboard_header_for_post((int)$edit->ID) : '';
    $dashboard_header_order = $edit ? (int)get_post_meta($edit->ID, self::META_DASHBOARD_HEADER_ORDER, true) : 0;
    $dashboard_order  = $edit ? (int)get_post_meta($edit->ID, self::META_DASHBOARD_ORDER, true) : 0;
    $dashboard_category_orders = $edit ? self::dashboard_category_orders_for_post((int)$edit->ID) : [];
    $allow_jobs  = $edit ? (array)get_post_meta($edit->ID, self::META_ALLOWED_JOBS, true) : [];
    $allow_group = $edit ? (array)get_post_meta($edit->ID, self::META_ALLOWED_GROUP, true) : [];
    $allow_jobs  = array_values(array_filter(array_map('intval', $allow_jobs)));
    $allow_group = array_values(array_filter(array_map('intval', $allow_group)));
    $require_rules = $edit ? (string)get_post_meta($edit->ID, self::META_REQUIRE_RULES, true) : '0';

    $edit_cat_ids = $edit ? wp_get_object_terms($edit->ID, self::TAX_CATEGORY, ['fields' => 'ids']) : [];
    if (is_wp_error($edit_cat_ids)) $edit_cat_ids = [];
    $edit_cat_ids = array_map('intval', (array)$edit_cat_ids);

    $edit_tag_ids = $edit ? wp_get_object_terms($edit->ID, self::TAX_TAG, ['fields' => 'ids']) : [];
    if (is_wp_error($edit_tag_ids)) $edit_tag_ids = [];
    $edit_tag_ids = array_map('intval', (array)$edit_tag_ids);

    echo '<div class="wrap">';
    echo '<h1>Dynamic Links</h1>';
    echo '<p class="description">Create links and control access by User Group and/or Job Path (job path access applies to the selected term and all descendants).</p>';

    echo '<div style="display:flex; gap:24px; align-items:flex-start; margin-top:16px;">';

    // LEFT form
    echo '<div style="flex:1; min-width:360px; max-width:560px;">';
    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin:0 0 12px 0;">'.($edit ? 'Edit Dynamic Link' : 'Add Dynamic Link').'</h2>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="eum_dl_save">';
    wp_nonce_field('eum_dl_save', 'eum_dl_nonce');
    if ($edit) echo '<input type="hidden" name="post_id" value="'.(int)$edit->ID.'">';

    echo '<p><label><strong>Title</strong></label><br>';
    echo '<input class="regular-text" type="text" name="title" required value="'.esc_attr($title).'"></p>';

    echo '<p><label><strong>URL</strong></label><br>';
    echo '<input class="regular-text" type="url" name="url" placeholder="https://..." required value="'.esc_attr($url).'"></p>';

    echo '<p><label><strong>Target</strong></label><br>';
    echo '<select name="target">';
    echo '<option value="_self" '.selected($target, '_self', false).'>Same tab</option>';
    echo '<option value="_blank" '.selected($target, '_blank', false).'>New tab</option>';
    echo '</select></p>';

    echo '<p><label><strong>Description (optional)</strong></label><br>';
    echo '<textarea name="desc" rows="3" class="large-text">'.esc_textarea($desc).'</textarea></p>';

    echo '<hr style="margin:16px 0;">';
    echo '<h3 style="margin:0 0 10px 0;">Link Grouping</h3>';

    echo '<p><label><strong>Section Header (optional)</strong></label><br>';
    echo '<input class="regular-text" type="text" name="dashboard_header" value="'.esc_attr($dashboard_header).'" placeholder="Payroll Tools">';
    echo '<br><span class="description">Headers are available under Dynamic Link Headers in Appearance &rarr; Menus. Adding a header to a menu automatically includes its links, filtered by each user&rsquo;s access.</span></p>';

    echo '<p><label><strong>Header Order</strong></label><br>';
    echo '<input type="number" name="dashboard_header_order" value="'.esc_attr((string)$dashboard_header_order).'" style="width:120px;">';
    echo '<br><span class="description">Controls header order in Dynamic Link dashboard layouts. Menu order is set in Appearance &rarr; Menus.</span></p>';

    echo '<p><label><strong>Item Order in Header</strong></label><br>';
    echo '<input type="number" name="dashboard_order" value="'.esc_attr((string)$dashboard_order).'" style="width:120px;">';
    echo '<br><span class="description">Controls the link order inside its header group.</span></p>';

    echo '<hr style="margin:16px 0;">';
    echo '<h3 style="margin:0 0 10px 0;">Categories & Tags</h3>';

    echo '<p><label><strong>Categories</strong></label><br>';
    echo $this->multi_select_terms('cat_ids[]', $cats, $edit_cat_ids, true, self::TAX_CATEGORY);
    echo '</p>';

    echo '<p class="description" style="margin:0 0 14px;">Categories decide which panel a link belongs to. Headers decide the grouped sections inside that panel.</p>';

    echo '<p><label><strong>Tags</strong></label><br>';
    echo $this->multi_select_terms('tag_ids[]', $tags, $edit_tag_ids, false, self::TAX_TAG);
    echo '<br><span class="description">Tip: Add new tags using the panel on the right.</span></p>';

    echo '<hr style="margin:16px 0;">';
    echo '<h3 style="margin:0 0 10px 0;">Access Rules</h3>';

    echo '<p><label><strong>Allowed Job Paths</strong></label><br>';
    echo class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_checkboxes('allowed_jobs', $allow_jobs, get_current_user_id(), ['enforce_manageable_job_paths' => false]) : $this->multi_select_job_paths('allowed_jobs[]', $job_terms, $allow_jobs);
    echo '<br><span class="description">Users whose Job Path is this term OR any descendant will see the link.</span></p>';

    echo '<p><label><strong>Allowed User Groups</strong></label><br>';
    echo $this->multi_select_flat('allowed_groups[]', $group_terms, $allow_group);
    echo '<br><span class="description">Exact match on user groups.</span></p>';

    echo '<p><label><input type="checkbox" name="require_rules" value="1" '.checked($require_rules, '1', false).'> Require at least one rule match</label><br>';
    echo '<span class="description">If checked, users must match Job Path or Group rules. If unchecked and no rules are set, all logged-in users can see it.</span></p>';

    echo '<p style="margin-top:14px;">';
    echo '<button type="submit" class="button button-primary">'.($edit ? 'Update Link' : 'Add Link').'</button> ';
    if ($edit) echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=eum-dynamic-links')).'">Cancel</a>';
    echo '</p>';

    echo '</form>';
    echo '</div></div>';

    // RIGHT: lists and quick term creation
    echo '<div style="flex:2; min-width:620px;">';

    echo '<div class="postbox" style="padding:16px; margin-bottom:16px;">';
    echo '<h2 style="margin:0 0 12px 0;">Categories & Tags Manager</h2>';
    echo '<p class="description">Create Dynamic Link categories/tags here.</p>';

    echo '<div style="display:flex; gap:16px; flex-wrap:wrap;">';

    // Add category
    echo '<div style="min-width:280px;">';
    echo '<h3 style="margin:0 0 8px 0;">Add Category</h3>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="eum_dl_save_term">';
    wp_nonce_field('eum_dl_save_term', 'eum_dl_term_nonce');
    echo '<input type="hidden" name="tax" value="'.esc_attr(self::TAX_CATEGORY).'">';
    echo '<input type="text" name="term_name" class="regular-text" placeholder="Category name" required> ';
    echo '<select name="term_parent">';
    echo '<option value="0">— No parent —</option>';
    foreach ($cats as $c) {
      echo '<option value="'.(int)$c->term_id.'">'.esc_html($this->term_full_path(self::TAX_CATEGORY, (int)$c->term_id, ' → ')).'</option>';
    }
    echo '</select> ';

    echo '<button type="submit" class="button">Add</button>';
    echo '</form>';
    echo '</div>';

    // Add tag
    echo '<div style="min-width:280px;">';
    echo '<h3 style="margin:0 0 8px 0;">Add Tag</h3>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="eum_dl_save_term">';
    wp_nonce_field('eum_dl_save_term', 'eum_dl_term_nonce');
    echo '<input type="hidden" name="tax" value="'.esc_attr(self::TAX_TAG).'">';
    echo '<input type="text" name="term_name" class="regular-text" placeholder="Tag name" required> ';
    echo '<button type="submit" class="button">Add</button>';
    echo '</form>';
    echo '</div>';

    echo '</div>';
    echo '<div style="margin-top:16px;">';
    echo '<h3 style="margin:0 0 8px 0;">Existing Categories</h3>';
    if (empty($cats)) {
      echo '<p class="description">No categories yet.</p>';
    } else {
      echo '<table class="widefat striped"><thead><tr><th>Category</th><th>Edit</th></tr></thead><tbody>';
      foreach ($cats as $c) {
        $term_id = (int)$c->term_id;

        echo '<tr>';
        echo '<td>' . esc_html($this->term_full_path(self::TAX_CATEGORY, $term_id, ' -> ')) . '</td>';

        echo '<td>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="hidden" name="action" value="eum_dl_save_term">';
        wp_nonce_field('eum_dl_save_term', 'eum_dl_term_nonce');
        echo '<input type="hidden" name="tax" value="' . esc_attr(self::TAX_CATEGORY) . '">';
        echo '<input type="hidden" name="term_id" value="' . (int)$term_id . '">';
        echo '<input type="text" name="term_name" class="regular-text" value="' . esc_attr((string)$c->name) . '" required>';
        echo '<select name="term_parent">';
        echo '<option value="0">-- No parent --</option>';
        foreach ($cats as $parent_term) {
          $parent_id = (int)$parent_term->term_id;
          if ($parent_id === $term_id) continue;
          echo '<option value="' . (int)$parent_id . '" ' . selected((int)$c->parent, $parent_id, false) . '>' . esc_html($this->term_full_path(self::TAX_CATEGORY, $parent_id, ' -> ')) . '</option>';
        }
        echo '</select>';

        echo '<button type="submit" class="button button-small">Save Category</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';
    echo '</div>';

    // Link list
    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin:0 0 12px 0;">Existing Links</h2>';

    $items = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => 500,
    ]);
    $items = self::sort_for_dashboard($items);

    if (empty($items)) {
      echo '<p>No links yet.</p>';
    } else {
      echo '<table class="widefat striped">';
      echo '<thead><tr><th>Header Order</th><th>Item Order</th><th>Title</th><th>Header</th><th>URL</th><th>Categories</th><th>Tags</th><th>Rules</th><th style="width:170px;">Actions</th></tr></thead><tbody>';

      foreach ($items as $p) {
        $pid = (int)$p->ID;
        $url = (string)get_post_meta($pid, self::META_URL, true);
        $header = self::dashboard_header_for_post($pid);
        $header_order = self::dashboard_header_order_for_post($pid);
        $order = self::dashboard_order_for_post($pid);

        $cat_names = wp_get_object_terms($pid, self::TAX_CATEGORY, ['fields' => 'names']);
        if (is_wp_error($cat_names)) $cat_names = [];
        $cat_ids = wp_get_object_terms($pid, self::TAX_CATEGORY, ['fields' => 'ids']);
        if (is_wp_error($cat_ids)) $cat_ids = [];
        $tag_names = wp_get_object_terms($pid, self::TAX_TAG, ['fields' => 'names']);
        if (is_wp_error($tag_names)) $tag_names = [];

        $aj = (array)get_post_meta($pid, self::META_ALLOWED_JOBS, true);
        $ag = (array)get_post_meta($pid, self::META_ALLOWED_GROUP, true);
        $aj = array_values(array_filter(array_map('intval', $aj)));
        $ag = array_values(array_filter(array_map('intval', $ag)));
        $rr = (string)get_post_meta($pid, self::META_REQUIRE_RULES, true);

        $rule_txt = [];
        if (!empty($aj)) $rule_txt[] = 'JobPaths: '.count($aj);
        if (!empty($ag)) $rule_txt[] = 'Groups: '.count($ag);
        if ($rr === '1') $rule_txt[] = 'RequireMatch';
        if (empty($rule_txt)) $rule_txt[] = 'Open';

        $edit_url = esc_url(admin_url('admin.php?page=eum-dynamic-links&edit='.$pid));



        echo '<tr>';
        echo '<td>'.(int)$header_order.'</td>';
        echo '<td>'.(int)$order.'</td>';
        echo '<td>'.esc_html($p->post_title).'</td>';
        echo '<td>'.esc_html($header).'</td>';
        echo '<td><a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($url).'</a></td>';
        echo '<td>'.esc_html(implode(', ', (array)$cat_names)).'</td>';
        echo '<td>'.esc_html(implode(', ', (array)$tag_names)).'</td>';
        echo '<td>'.esc_html(implode(' | ', $rule_txt)).'</td>';
        echo '<td>';

        echo '<a class="button button-small" href="'.$edit_url.'">Edit</a> ';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline;">';
        echo '<input type="hidden" name="action" value="eum_dl_delete">';
        wp_nonce_field('eum_dl_delete', 'eum_dl_nonce');
        echo '<input type="hidden" name="post_id" value="'.$pid.'">';
        echo '<button class="button button-small" type="submit" onclick="return confirm(\'Delete this link?\')">Delete</button>';
        echo '</form>';

        echo '</td>';
        echo '</tr>';
      }

      echo '</tbody></table>';
    }

    echo '</div>'; // postbox
    echo '</div>'; // right

    echo '</div>'; // flex
    echo '</div>'; // wrap
  }

  public function handle_save_term(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_dl_term_nonce']) || !wp_verify_nonce($_POST['eum_dl_term_nonce'], 'eum_dl_save_term')) {
      $this->redirect('Invalid nonce.', 'error');
    }

    $tax = isset($_POST['tax']) ? sanitize_key((string)$_POST['tax']) : '';
    if (!in_array($tax, [self::TAX_CATEGORY, self::TAX_TAG], true)) $this->redirect('Invalid taxonomy.', 'error');

    $term_id = isset($_POST['term_id']) ? max(0, intval($_POST['term_id'])) : 0;
    $name = isset($_POST['term_name']) ? sanitize_text_field((string)$_POST['term_name']) : '';
    if ($name === '') $this->redirect('Name required.', 'error');

    $args = [];
    if ($tax === self::TAX_CATEGORY) {
      $args['parent'] = isset($_POST['term_parent']) ? max(0, intval($_POST['term_parent'])) : 0;
      if ($term_id > 0 && $args['parent'] === $term_id) $this->redirect('A category cannot be its own parent.', 'error');
      if ($term_id > 0 && $args['parent'] > 0 && term_is_ancestor_of($term_id, $args['parent'], self::TAX_CATEGORY)) {
        $this->redirect('A category cannot move under one of its own descendants.', 'error');
      }
    }

    if ($term_id > 0) {
      $term = get_term($term_id, $tax);
      if (!$term || is_wp_error($term)) $this->redirect('Invalid term.', 'error');
      $updated = wp_update_term($term_id, $tax, array_merge(['name' => $name], $args));
      if (is_wp_error($updated)) $this->redirect($updated->get_error_message(), 'error');
      if ($tax === self::TAX_CATEGORY) {
        update_term_meta($term_id, self::TERM_META_DASHBOARD_ORDER, isset($_POST['term_order']) ? (int)$_POST['term_order'] : 0);
      }
      $this->redirect('Term saved.');
    }

    $ins = wp_insert_term($name, $tax, $args);
    if (is_wp_error($ins)) $this->redirect($ins->get_error_message(), 'error');
    if ($tax === self::TAX_CATEGORY && !empty($ins['term_id'])) {
      update_term_meta((int)$ins['term_id'], self::TERM_META_DASHBOARD_ORDER, isset($_POST['term_order']) ? (int)$_POST['term_order'] : 0);
    }

    $this->redirect('Term added.');
  }

  public function handle_save(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_dl_nonce']) || !wp_verify_nonce($_POST['eum_dl_nonce'], 'eum_dl_save')) wp_die('Bad nonce.');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    $title  = isset($_POST['title']) ? sanitize_text_field((string)$_POST['title']) : '';
    $url    = isset($_POST['url']) ? esc_url_raw((string)$_POST['url']) : '';
    $target = isset($_POST['target']) ? sanitize_key((string)$_POST['target']) : '_self';
    $desc   = isset($_POST['desc']) ? sanitize_textarea_field((string)$_POST['desc']) : '';
    $dashboard_header = isset($_POST['dashboard_header']) ? sanitize_text_field((string)$_POST['dashboard_header']) : '';
    $dashboard_header_order = isset($_POST['dashboard_header_order']) ? (int)$_POST['dashboard_header_order'] : 0;
    $dashboard_order = isset($_POST['dashboard_order']) ? (int)$_POST['dashboard_order'] : 0;
    $dashboard_category_orders = isset($_POST['dashboard_category_order']) && is_array($_POST['dashboard_category_order']) ? (array)$_POST['dashboard_category_order'] : [];

    if ($title === '' || $url === '') $this->redirect('Title and URL required.', 'error');
    if (!in_array($target, ['_self','_blank'], true)) $target = '_self';

    if ($post_id) {
      $p = get_post($post_id);
      if (!$p || $p->post_type !== self::CPT) $this->redirect('Invalid link.', 'error');
      wp_update_post(['ID' => $post_id, 'post_title' => $title]);
    } else {
      $post_id = (int)wp_insert_post([
        'post_type' => self::CPT,
        'post_status' => 'publish',
        'post_title' => $title,
      ]);
      if (!$post_id) $this->redirect('Failed to create link.', 'error');
    }

    update_post_meta($post_id, self::META_URL, $url);
    update_post_meta($post_id, self::META_TARGET, $target);
    update_post_meta($post_id, self::META_DESC, $desc);
    update_post_meta($post_id, self::META_DASHBOARD_HEADER, $dashboard_header);
    update_post_meta($post_id, self::META_DASHBOARD_HEADER_ORDER, $dashboard_header_order);
    update_post_meta($post_id, self::META_DASHBOARD_ORDER, $dashboard_order);

    $allowed_jobs = isset($_POST['allowed_jobs']) ? array_map('intval', (array)$_POST['allowed_jobs']) : [];
    $allowed_groups = isset($_POST['allowed_groups']) ? array_map('intval', (array)$_POST['allowed_groups']) : [];
    $allowed_jobs = array_values(array_filter($allowed_jobs, fn($x) => $x > 0));
    $allowed_groups = array_values(array_filter($allowed_groups, fn($x) => $x > 0));

    update_post_meta($post_id, self::META_ALLOWED_JOBS, $allowed_jobs);
    update_post_meta($post_id, self::META_ALLOWED_GROUP, $allowed_groups);

    $require_rules = isset($_POST['require_rules']) ? '1' : '0';
    update_post_meta($post_id, self::META_REQUIRE_RULES, $require_rules);

    $cat_ids = isset($_POST['cat_ids']) ? array_values(array_filter(array_map('intval', (array)$_POST['cat_ids']))) : [];
    $tag_ids = isset($_POST['tag_ids']) ? array_values(array_filter(array_map('intval', (array)$_POST['tag_ids']))) : [];
    $clean_category_orders = [];
    foreach ($dashboard_category_orders as $term_id => $order) {
      $term_id = (int)$term_id;
      if ($term_id <= 0) continue;
      $clean_category_orders[$term_id] = (int)$order;
    }
    $clean_category_orders = array_intersect_key($clean_category_orders, array_flip($cat_ids));

    wp_set_object_terms($post_id, $cat_ids, self::TAX_CATEGORY, false);
    wp_set_object_terms($post_id, $tag_ids, self::TAX_TAG, false);
    if (!empty($clean_category_orders)) update_post_meta($post_id, self::META_DASHBOARD_CATEGORY_ORDERS, $clean_category_orders);
    else delete_post_meta($post_id, self::META_DASHBOARD_CATEGORY_ORDERS);

    $this->redirect('Saved.');
  }

  public function sync_header_menu_items(): void {
    if (!current_user_can('edit_theme_options')) return;

    $links = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'fields' => 'ids',
      'no_found_rows' => true,
    ]);
    $active_headers = [];
    foreach ((array)$links as $link_id) {
      $header = self::dashboard_header_for_post((int)$link_id);
      if ($header !== '') $active_headers['header:' . $header] = $header;
    }

    $header_posts = get_posts([
      'post_type' => self::HEADER_CPT,
      'post_status' => 'any',
      'posts_per_page' => -1,
      'meta_key' => self::META_HEADER_VALUE,
      'orderby' => 'ID',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);
    $posts_by_header = [];
    $duplicate_header_post_ids = [];
    foreach ((array)$header_posts as $header_post) {
      $value = self::header_menu_value((int)$header_post->ID);
      if ($value === '') continue;
      $key = 'header:' . $value;
      if (!isset($posts_by_header[$key])) {
        $posts_by_header[$key] = $header_post;
      } elseif ((string)$posts_by_header[$key]->post_status !== 'publish' && (string)$header_post->post_status === 'publish') {
        $duplicate_header_post_ids[] = (int)$posts_by_header[$key]->ID;
        $posts_by_header[$key] = $header_post;
      } else {
        $duplicate_header_post_ids[] = (int)$header_post->ID;
      }
    }

    foreach ($active_headers as $key => $header) {
      $header_post = $posts_by_header[$key] ?? null;
      if ($header_post instanceof WP_Post) {
        $changes = ['ID' => (int)$header_post->ID];
        if ((string)$header_post->post_title !== $header) $changes['post_title'] = $header;
        if ((string)$header_post->post_status !== 'publish') $changes['post_status'] = 'publish';
        if (count($changes) > 1) wp_update_post($changes);
        update_post_meta((int)$header_post->ID, self::META_HEADER_VALUE, $header);
        continue;
      }

      $new_header_id = wp_insert_post([
        'post_type' => self::HEADER_CPT,
        'post_status' => 'publish',
        'post_title' => $header,
      ], true);
      if (!is_wp_error($new_header_id) && (int)$new_header_id > 0) {
        update_post_meta((int)$new_header_id, self::META_HEADER_VALUE, $header);
      }
    }

    foreach ((array)$header_posts as $header_post) {
      $key = 'header:' . self::header_menu_value((int)$header_post->ID);
      if ((in_array((int)$header_post->ID, $duplicate_header_post_ids, true) || !isset($active_headers[$key])) && (string)$header_post->post_status === 'publish') {
        wp_update_post(['ID' => (int)$header_post->ID, 'post_status' => 'draft']);
      }
    }
  }

  public function handle_delete(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_dl_nonce']) || !wp_verify_nonce($_POST['eum_dl_nonce'], 'eum_dl_delete')) wp_die('Bad nonce.');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) $this->redirect('Missing id.', 'error');

    $p = get_post($post_id);
    if (!$p || $p->post_type !== self::CPT) $this->redirect('Invalid link.', 'error');

    wp_delete_post($post_id, true);
    $this->redirect('Deleted.');
  }

  private function redirect(string $msg, string $type = 'success'): void {
    $url = admin_url('admin.php?page=eum-dynamic-links&eum_msg='.rawurlencode($msg).'&eum_type='.$type);
    wp_safe_redirect($url);
    exit;
  }

  public static function access_context(int $user_id): array {
    $user_id = (int)$user_id;
    $job_path_ids = [];
    $group_ids = [];

    if ($user_id > 0 && taxonomy_exists('eum_job_path')) {
      $assigned = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
      if (!is_wp_error($assigned) && !empty($assigned)) {
        $leaf = (int)$assigned[0];
        $job_path_ids = array_merge([$leaf], array_map('intval', (array)get_ancestors($leaf, 'eum_job_path')));
        $job_path_ids = array_values(array_unique(array_filter($job_path_ids)));
      }
    }

    if ($user_id > 0 && taxonomy_exists('eum_user_group')) {
      $assigned = wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']);
      if (!is_wp_error($assigned) && !empty($assigned)) {
        $group_ids = array_values(array_unique(array_filter(array_map('intval', (array)$assigned))));
      }
    }

    return [
      'user_id' => $user_id,
      'job_path_ids' => $job_path_ids,
      'group_ids' => $group_ids,
    ];
  }

  public function setup_dynamic_link_menu_item($menu_item) {
    if (!is_object($menu_item)) return $menu_item;

    if ((string)($menu_item->object ?? '') === self::HEADER_CPT) {
      $header_post_id = (int)($menu_item->object_id ?? 0);
      if ($header_post_id > 0 && get_post_type($header_post_id) === self::HEADER_CPT) {
        $menu_item->url = '#';
        $menu_item->target = '';
        if (is_array($menu_item->classes ?? null)) $menu_item->classes[] = 'eum-dynamic-link-header-menu-item';
      }
      return $menu_item;
    }

    if ((string)($menu_item->object ?? '') !== self::CPT) return $menu_item;

    $post_id = (int)($menu_item->object_id ?? 0);
    if ($post_id <= 0 || get_post_type($post_id) !== self::CPT) return $menu_item;

    $url = esc_url_raw((string)get_post_meta($post_id, self::META_URL, true));
    if ($url !== '') $menu_item->url = $url;

    $target = (string)get_post_meta($post_id, self::META_TARGET, true);
    $menu_item->target = in_array($target, ['_self', '_blank'], true) ? $target : '_self';
    if ($menu_item->target === '_blank') {
      $xfn = preg_split('/\\s+/', trim((string)($menu_item->xfn ?? '')), -1, PREG_SPLIT_NO_EMPTY);
      $xfn = is_array($xfn) ? $xfn : [];
      $menu_item->xfn = implode(' ', array_values(array_unique(array_merge($xfn, ['noopener', 'noreferrer']))));
    }

    return $menu_item;
  }

  public function filter_dynamic_link_menu_items(array $items, $args): array {
    $has_dynamic_items = false;
    foreach ($items as $item) {
      if (!is_object($item)) continue;
      $object = (string)($item->object ?? '');
      if ($object === self::CPT || $object === self::HEADER_CPT) {
        $has_dynamic_items = true;
        break;
      }
    }
    if (!$has_dynamic_items) return $items;

    $user_id = (int)get_current_user_id();
    $context = $user_id > 0 ? self::access_context($user_id) : [];
    $original_items = $items;
    $hidden_menu_item_ids = [];

    $published_links = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);
    $accessible_links = [];
    $accessible_links_by_header = [];
    foreach ((array)$published_links as $post) {
      if (!($post instanceof WP_Post)) continue;
      $post_id = (int)$post->ID;
      $url = esc_url_raw((string)get_post_meta($post_id, self::META_URL, true));
      if ($user_id <= 0 || $url === '' || !self::link_is_visible_to_context($post_id, $context, 'any')) continue;

      $accessible_links[$post_id] = $post;
      $header = self::dashboard_header_for_post($post_id);
      if ($header !== '') $accessible_links_by_header['header:' . $header][] = $post;
    }

    $header_items = [];
    foreach ($items as $item) {
      if (!is_object($item) || (string)($item->object ?? '') !== self::HEADER_CPT) continue;
      $menu_item_id = (int)($item->ID ?? 0);
      $header_post_id = (int)($item->object_id ?? 0);
      $header_post = $header_post_id > 0 ? get_post($header_post_id) : null;
      $header = $header_post instanceof WP_Post && $header_post->post_type === self::HEADER_CPT
        ? self::header_menu_value($header_post_id)
        : '';
      $key = $header !== '' ? 'header:' . $header : '';

      if ($key === '' || isset($header_items[$key])) {
        if ($menu_item_id > 0 && !in_array($menu_item_id, $hidden_menu_item_ids, true)) $hidden_menu_item_ids[] = $menu_item_id;
        continue;
      }
      $header_items[$key] = $item;
    }

    foreach ($items as $item) {
      if (!is_object($item) || (string)($item->object ?? '') !== self::CPT) continue;

      $menu_item_id = (int)($item->ID ?? 0);
      $post_id = (int)($item->object_id ?? 0);
      $post = $accessible_links[$post_id] ?? null;
      $url = $post instanceof WP_Post ? esc_url_raw((string)get_post_meta($post_id, self::META_URL, true)) : '';
      $visible = $post instanceof WP_Post && $post->post_type === self::CPT && $url !== '';

      if (!$visible) {
        if ($menu_item_id > 0) $hidden_menu_item_ids[] = $menu_item_id;
        continue;
      }

      $item->url = $url;
      $target = (string)get_post_meta($post_id, self::META_TARGET, true);
      $item->target = in_array($target, ['_self', '_blank'], true) ? $target : '_self';
      if ($item->target === '_blank') {
        $xfn = preg_split('/\\s+/', trim((string)($item->xfn ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $xfn = is_array($xfn) ? $xfn : [];
        $item->xfn = implode(' ', array_values(array_unique(array_merge($xfn, ['noopener', 'noreferrer']))));
      }
      if (is_array($item->classes ?? null)) $item->classes[] = 'eum-dynamic-link-menu-item';
    }

    // Hide inaccessible branches and headers whose links are all inaccessible.
    do {
      $changed = false;
      foreach ($items as $item) {
        if (!is_object($item)) continue;
        $menu_item_id = (int)($item->ID ?? 0);
        $parent_id = (int)($item->menu_item_parent ?? 0);
        if ($menu_item_id > 0 && $parent_id > 0 && in_array($parent_id, $hidden_menu_item_ids, true) && !in_array($menu_item_id, $hidden_menu_item_ids, true)) {
          $hidden_menu_item_ids[] = $menu_item_id;
          $changed = true;
        }
      }

      foreach ($items as $item) {
        if (!is_object($item) || (string)($item->object ?? '') !== self::HEADER_CPT) continue;

        $menu_item_id = (int)($item->ID ?? 0);
        $header_post_id = (int)($item->object_id ?? 0);
        $header_post = $header_post_id > 0 ? get_post($header_post_id) : null;
        $header = $header_post instanceof WP_Post && $header_post->post_type === self::HEADER_CPT
          ? self::header_menu_value($header_post_id)
          : '';
        $header_key = $header !== '' ? 'header:' . $header : '';

        if ($header_key === '' || empty($accessible_links_by_header[$header_key])) {
          if ($menu_item_id > 0 && !in_array($menu_item_id, $hidden_menu_item_ids, true)) {
            $hidden_menu_item_ids[] = $menu_item_id;
            $changed = true;
          }
          continue;
        }

        $item->url = '#';
        $item->target = '';
        if (is_array($item->classes ?? null) && !in_array('eum-dynamic-link-header-menu-item', $item->classes, true)) {
          $item->classes[] = 'eum-dynamic-link-header-menu-item';
        }
      }
    } while ($changed);

    $visible_items = array_values(array_filter($items, static function ($item) use ($hidden_menu_item_ids) {
      return !is_object($item) || !in_array((int)($item->ID ?? 0), $hidden_menu_item_ids, true);
    }));

    $active_header_items = [];
    foreach ($visible_items as $item) {
      if (!is_object($item) || (string)($item->object ?? '') !== self::HEADER_CPT) continue;
      $header_post_id = (int)($item->object_id ?? 0);
      $header = self::header_menu_value($header_post_id);
      $header_key = $header !== '' ? 'header:' . $header : '';
      if ($header_key !== '' && !isset($active_header_items[$header_key])) $active_header_items[$header_key] = $item;
    }
    if (empty($active_header_items)) return $visible_items;

    // Header links are injected after the menu is loaded, so ensure a configured
    // one-level menu depth cannot silently hide the generated submenu.
    if (is_object($args) && isset($args->depth) && (int)$args->depth > 0) {
      $args->depth = 0;
    }

    $existing_link_items = [];
    static $next_synthetic_id = -1;
    foreach ($original_items as $item) {
      if (!is_object($item)) continue;
      if ((string)($item->object ?? '') === self::CPT) {
        $post_id = (int)($item->object_id ?? 0);
        if ($post_id > 0) $existing_link_items[$post_id][] = $item;
      }
    }

    $grouped_items = [];
    $grouped_menu_item_ids = [];
    $duplicate_menu_item_ids = [];
    foreach ($active_header_items as $header_key => $header_item) {
      $header_menu_item_id = (int)($header_item->db_id ?? $header_item->ID ?? 0);
      $header_links = self::sort_for_dashboard((array)($accessible_links_by_header[$header_key] ?? []));
      if (!empty($header_links)) {
        if (!is_array($header_item->classes ?? null)) $header_item->classes = [];
        if (!in_array('menu-item-has-children', $header_item->classes, true)) {
          $header_item->classes[] = 'menu-item-has-children';
        }
      }
      foreach ($header_links as $post) {
        $post_id = (int)$post->ID;
        $url = esc_url_raw((string)get_post_meta($post_id, self::META_URL, true));
        if ($url === '') continue;

        $candidates = $existing_link_items[$post_id] ?? [];
        $menu_item = null;
        foreach ($candidates as $candidate) {
          $candidate_id = (int)($candidate->ID ?? 0);
          if ($menu_item === null && $candidate_id > 0) {
            $menu_item = $candidate;
          } elseif ($candidate_id > 0) {
            $duplicate_menu_item_ids[] = $candidate_id;
          }
        }

        if (!$menu_item) {
          $synthetic_id = $next_synthetic_id--;
          $menu_item = (object)[
            'ID' => $synthetic_id,
            'db_id' => $synthetic_id,
            'menu_item_parent' => $header_menu_item_id,
            'menu_order' => (int)($header_item->menu_order ?? 0),
            'object_id' => $post_id,
            'object' => self::CPT,
            'type' => 'post_type',
            'type_label' => 'Dynamic Link',
            'title' => get_the_title($post_id),
            'url' => $url,
            'target' => '',
            'attr_title' => '',
            'description' => '',
            'classes' => ['menu-item', 'menu-item-type-post_type', 'menu-item-object-' . self::CPT],
            'xfn' => '',
            'current' => false,
            'current_item_parent' => false,
            'current_item_ancestor' => false,
          ];
        }

        $menu_item->menu_item_parent = $header_menu_item_id;
        $menu_item->object_id = $post_id;
        $menu_item->object = self::CPT;
        $menu_item->url = $url;
        $target = (string)get_post_meta($post_id, self::META_TARGET, true);
        $menu_item->target = in_array($target, ['_self', '_blank'], true) ? $target : '_self';
        if (!is_array($menu_item->classes ?? null)) $menu_item->classes = [];
        if (!in_array('eum-dynamic-link-menu-item', $menu_item->classes, true)) $menu_item->classes[] = 'eum-dynamic-link-menu-item';
        if ($menu_item->target === '_blank') {
          $xfn = preg_split('/\\s+/', trim((string)($menu_item->xfn ?? '')), -1, PREG_SPLIT_NO_EMPTY);
          $xfn = is_array($xfn) ? $xfn : [];
          $menu_item->xfn = implode(' ', array_values(array_unique(array_merge($xfn, ['noopener', 'noreferrer']))));
        }

        $menu_item_id = (int)($menu_item->ID ?? 0);
        if ($menu_item_id > 0) $grouped_menu_item_ids[] = $menu_item_id;
        $grouped_items[] = $menu_item;
      }
    }

    $excluded_ids = array_values(array_unique(array_merge($grouped_menu_item_ids, $duplicate_menu_item_ids)));
    do {
      $changed = false;
      foreach ($visible_items as $item) {
        if (!is_object($item)) continue;
        $item_id = (int)($item->ID ?? 0);
        $parent_id = (int)($item->menu_item_parent ?? 0);
        if ($item_id > 0 && $parent_id > 0 && in_array($parent_id, $duplicate_menu_item_ids, true) && !in_array($item_id, $excluded_ids, true)) {
          $excluded_ids[] = $item_id;
          $duplicate_menu_item_ids[] = $item_id;
          $changed = true;
        }
      }
    } while ($changed);

    $visible_items = array_values(array_filter($visible_items, static function ($item) use ($excluded_ids) {
      return !is_object($item) || !in_array((int)($item->ID ?? 0), $excluded_ids, true);
    }));

    return array_merge($visible_items, $grouped_items);
  }

  public static function link_is_visible_to_context(int $post_id, array $context, string $mode = 'any'): bool {
    $post_id = (int)$post_id;
    if ($post_id <= 0) return false;

    $job_path_ids = array_values(array_unique(array_filter(array_map('intval', (array)($context['job_path_ids'] ?? [])))));
    $group_ids = array_values(array_unique(array_filter(array_map('intval', (array)($context['group_ids'] ?? [])))));

    $allowed_jobs = (array)get_post_meta($post_id, self::META_ALLOWED_JOBS, true);
    $allowed_groups = (array)get_post_meta($post_id, self::META_ALLOWED_GROUP, true);
    $allowed_jobs = array_values(array_filter(array_map('intval', $allowed_jobs)));
    $allowed_groups = array_values(array_filter(array_map('intval', $allowed_groups)));

    $require = ((string)get_post_meta($post_id, self::META_REQUIRE_RULES, true) === '1');
    $job_match = !empty($allowed_jobs) && !empty(array_intersect($job_path_ids, $allowed_jobs));
    $group_match = !empty($allowed_groups) && !empty(array_intersect($group_ids, $allowed_groups));
    $has_any_rules = (!empty($allowed_jobs) || !empty($allowed_groups));
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['any', 'all'], true)) $mode = 'any';

    if ($mode === 'all') {
      $allowed = true;
      if (!empty($allowed_jobs)) $allowed = $allowed && $job_match;
      if (!empty($allowed_groups)) $allowed = $allowed && $group_match;
      return $has_any_rules ? $allowed : !$require;
    }

    $allowed = ($job_match || $group_match);
    if (!$has_any_rules) $allowed = !$require;
    return $allowed;
  }

  public static function dashboard_header_for_post(int $post_id): string {
    $header = (string)get_post_meta($post_id, self::META_DASHBOARD_HEADER, true);
    return sanitize_text_field(html_entity_decode($header, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  private static function header_menu_value(int $header_post_id): string {
    $value = (string)get_post_meta($header_post_id, self::META_HEADER_VALUE, true);
    return sanitize_text_field(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  public static function dashboard_header_order_for_post(int $post_id): int {
    return (int)get_post_meta($post_id, self::META_DASHBOARD_HEADER_ORDER, true);
  }

  public static function category_dashboard_order(int $term_id): int {
    return $term_id > 0 ? (int)get_term_meta($term_id, self::TERM_META_DASHBOARD_ORDER, true) : 0;
  }

  public static function dashboard_category_orders_for_post(int $post_id): array {
    $raw = get_post_meta($post_id, self::META_DASHBOARD_CATEGORY_ORDERS, true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $term_id => $order) {
      $term_id = (int)$term_id;
      if ($term_id <= 0) continue;
      $out[$term_id] = (int)$order;
    }
    return $out;
  }

  public static function dashboard_order_for_post(int $post_id, int $category_id = 0): int {
    if ($category_id > 0) {
      $orders = self::dashboard_category_orders_for_post($post_id);
      if (array_key_exists($category_id, $orders)) return (int)$orders[$category_id];
    }
    return (int)get_post_meta($post_id, self::META_DASHBOARD_ORDER, true);
  }

  public static function sort_for_dashboard(array $posts, $category_context = 0): array {
    usort($posts, static function ($left, $right): int {
      $left_id = isset($left->ID) ? (int)$left->ID : 0;
      $right_id = isset($right->ID) ? (int)$right->ID : 0;
      $header_order_cmp = self::dashboard_header_order_for_post($left_id) <=> self::dashboard_header_order_for_post($right_id);
      if ($header_order_cmp !== 0) return $header_order_cmp;
      $header_cmp = strcasecmp(self::dashboard_header_for_post($left_id), self::dashboard_header_for_post($right_id));
      if ($header_cmp !== 0) return $header_cmp;
      $order_cmp = self::dashboard_order_for_post($left_id) <=> self::dashboard_order_for_post($right_id);
      if ($order_cmp !== 0) return $order_cmp;
      $left_title = isset($left->post_title) ? (string)$left->post_title : '';
      $right_title = isset($right->post_title) ? (string)$right->post_title : '';
      return strcasecmp($left_title, $right_title);
    });
    return $posts;
  }

  private static function normalized_category_context($category_context): array {
    if (is_array($category_context)) {
      return array_values(array_unique(array_filter(array_map('intval', $category_context), static fn($term_id) => $term_id > 0)));
    }
    $term_id = (int)$category_context;
    return $term_id > 0 ? [$term_id] : [];
  }

  private static function category_ids_for_post(int $post_id): array {
    if ($post_id <= 0) return [];
    $term_ids = wp_get_object_terms($post_id, self::TAX_CATEGORY, ['fields' => 'ids']);
    if (is_wp_error($term_ids)) return [];
    return array_values(array_unique(array_filter(array_map('intval', (array)$term_ids), static fn($term_id) => $term_id > 0)));
  }

  private static function primary_category_for_post(int $post_id, array $category_context = []): int {
    $term_ids = self::category_ids_for_post($post_id);
    if (empty($term_ids)) return 0;
    if (!empty($category_context)) {
      $term_ids = array_values(array_intersect($term_ids, $category_context));
      if (empty($term_ids)) return 0;
    }
    usort($term_ids, static function($left_id, $right_id): int {
      $order_cmp = self::category_dashboard_order((int)$left_id) <=> self::category_dashboard_order((int)$right_id);
      if ($order_cmp !== 0) return $order_cmp;
      $left_name = sanitize_text_field((string)get_term_field('name', (int)$left_id, self::TAX_CATEGORY));
      $right_name = sanitize_text_field((string)get_term_field('name', (int)$right_id, self::TAX_CATEGORY));
      return strcasecmp($left_name, $right_name);
    });
    return (int)$term_ids[0];
  }

  // UI helpers

  private function multi_select_terms(string $name, array $terms, array $selected, bool $hierarchical, string $tax): string {
    $selected = array_map('intval', (array)$selected);
    $out = '<select name="'.esc_attr($name).'" multiple size="8" style="min-width:420px;">';

    if ($hierarchical) {
      foreach ($terms as $t) {
        $label = $this->term_full_path($tax, (int)$t->term_id, ' → ');
        $sel = in_array((int)$t->term_id, $selected, true) ? 'selected' : '';
        $out .= '<option value="'.(int)$t->term_id.'" '.$sel.'>'.esc_html($label).'</option>';
      }
    } else {
      foreach ($terms as $t) {
        $sel = in_array((int)$t->term_id, $selected, true) ? 'selected' : '';
        $out .= '<option value="'.(int)$t->term_id.'" '.$sel.'>'.esc_html($t->name).'</option>';
      }
    }

    $out .= '</select>';
    return $out;
  }

  private function multi_select_job_paths(string $name, array $terms, array $selected): string {
    $selected = array_map('intval', (array)$selected);
    $out = '<select name="'.esc_attr($name).'" multiple size="10" style="min-width:420px;">';
    foreach ($terms as $t) {
      $label = $this->term_full_path('eum_job_path', (int)$t->term_id, ' → ');
      $sel = in_array((int)$t->term_id, $selected, true) ? 'selected' : '';
      $out .= '<option value="'.(int)$t->term_id.'" '.$sel.'>'.esc_html($label).'</option>';
    }
    $out .= '</select>';
    return $out;
  }

  private function multi_select_flat(string $name, array $terms, array $selected): string {
    $selected = array_map('intval', (array)$selected);
    $out = '<select name="'.esc_attr($name).'" multiple size="10" style="min-width:420px;">';
    foreach ($terms as $t) {
      $sel = in_array((int)$t->term_id, $selected, true) ? 'selected' : '';
      $out .= '<option value="'.(int)$t->term_id.'" '.$sel.'>'.esc_html($t->name).'</option>';
    }
    $out .= '</select>';
    return $out;
  }

  private function term_full_path(string $tax, int $term_id, string $sep): string {
    $t = get_term($term_id, $tax);
    if (!$t || is_wp_error($t)) return '';

    $anc = array_reverse(get_ancestors($term_id, $tax));
    $names = [];
    foreach ($anc as $aid) {
      $a = get_term($aid, $tax);
      if ($a && !is_wp_error($a)) $names[] = $a->name;
    }
    $names[] = $t->name;

    return implode($sep, $names);
  }

  private function sorted_category_terms(array $terms): array {
    usort($terms, function($left, $right): int {
      $left_id = isset($left->term_id) ? (int)$left->term_id : 0;
      $right_id = isset($right->term_id) ? (int)$right->term_id : 0;
      $order_cmp = self::category_dashboard_order($left_id) <=> self::category_dashboard_order($right_id);
      if ($order_cmp !== 0) return $order_cmp;
      return strcasecmp($this->term_full_path(self::TAX_CATEGORY, $left_id, ' -> '), $this->term_full_path(self::TAX_CATEGORY, $right_id, ' -> '));
    });
    return $terms;
  }
}


