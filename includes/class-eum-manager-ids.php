<?php
if (!defined('ABSPATH')) exit;

class EUM_Manager_IDs {
  // User meta overrides:
  // - Code override has top priority (because it's portable across environments)
  const USER_META_OVERRIDE_CODE = 'eum_manager_code_override';
  // - ID override remains supported for backward compatibility
  const USER_META_OVERRIDE_ID   = 'eum_manager_id_override';

  // CPT + meta keys
  const CPT = 'eum_manager_id';
  const META_CODE = 'eum_manager_code';
  const META_JOB_PATH_TERM = 'eum_manager_job_path_term_id';
  const META_JOB_TITLE = 'eum_manager_job_title';
  const META_MANAGER_USERS = 'eum_manager_users'; // array of user IDs
  const META_ACCESS_EMAILS = 'eum_manager_access_emails'; // array of [label,email]
  const META_VACANCY_JOB_TITLE = 'eum_manager_vacancy_job_title';
  const META_VACANCY_JOB_PATH_TERM = 'eum_manager_vacancy_job_path_term_id'; // legacy fallback
  const META_VACANCY_DIVISION_KEY = 'eum_manager_vacancy_division_key';
  const META_VACANCY_IMAGE_ID = 'eum_manager_vacancy_image_id';
  const META_VACANCY_IMAGE_URL = 'eum_manager_vacancy_image_url';
  const META_INTERIM_USER_ID = 'eum_manager_interim_user_id';
  const META_HIDE_IF_VACANT = 'eum_manager_hide_if_vacant';

  public function init(): void {
    add_action('init', [$this, 'register_cpt']);
    add_action('admin_menu', [$this, 'admin_menu'], 40);

    add_action('admin_post_eum_manager_ids_save', [$this, 'handle_save']);
    add_action('admin_post_eum_manager_ids_delete', [$this, 'handle_delete']);

    // User profile override fields
    add_action('show_user_profile', [$this, 'profile_field']);
    add_action('edit_user_profile', [$this, 'profile_field']);
    add_action('personal_options_update', [$this, 'save_profile_field']);
    add_action('edit_user_profile_update', [$this, 'save_profile_field']);
  }

  public function register_cpt(): void {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Manager IDs',
        'singular_name' => 'Manager ID',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public function admin_menu(): void {
    add_submenu_page(
      EUM_Admin::parent_slug('workforce'),
      'Manager IDs',
      'Manager IDs',
      'read',
      'eum-manager-ids',
      [$this, 'render_admin_page']
    );
  }

  public function render_admin_page(): void {
    if (!EUM_Access::current_user_is_it()) {
      wp_die('Not allowed.');
    }

    if (!empty($_GET['eum_msg'])) {
      $msg = sanitize_text_field((string)$_GET['eum_msg']);
      $type = !empty($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
      $cls = ($type === 'error') ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($cls) . '"><p>' . esc_html($msg) . '</p></div>';
    }

    $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
    $copy_id = !$edit_id && isset($_GET['copy']) ? intval($_GET['copy']) : 0;
    $edit_post = $edit_id ? get_post($edit_id) : null;
    if ($edit_post && $edit_post->post_type !== self::CPT) $edit_post = null;
    $copy_post = (!$edit_post && $copy_id) ? get_post($copy_id) : null;
    if ($copy_post && $copy_post->post_type !== self::CPT) $copy_post = null;
    $source_post = $edit_post ?: $copy_post;

    $code = $edit_post ? (string)get_post_meta($edit_post->ID, self::META_CODE, true) : '';
    $job_path_term_id = $source_post ? (int)get_post_meta($source_post->ID, self::META_JOB_PATH_TERM, true) : 0;
    $manager_job_title = $source_post ? $this->normalize_manager_job_title((string)get_post_meta($source_post->ID, self::META_JOB_TITLE, true), $job_path_term_id) : '';
    $manager_users = $source_post ? (array)get_post_meta($source_post->ID, self::META_MANAGER_USERS, true) : [];
    $manager_users = array_values(array_filter(array_map('intval', $manager_users)));
    $access_emails = $source_post ? (array)get_post_meta($source_post->ID, self::META_ACCESS_EMAILS, true) : [];
    $vacancy_job_title = $source_post ? (string)get_post_meta($source_post->ID, self::META_VACANCY_JOB_TITLE, true) : '';
    $vacancy_division_key = $source_post && class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::normalize_division_key((string)get_post_meta($source_post->ID, self::META_VACANCY_DIVISION_KEY, true)) : '';
    $vacancy_image_id = $source_post ? (int)get_post_meta($source_post->ID, self::META_VACANCY_IMAGE_ID, true) : 0;
    $vacancy_image_url = $source_post ? (string)get_post_meta($source_post->ID, self::META_VACANCY_IMAGE_URL, true) : '';
    if ($vacancy_image_url === '' && $vacancy_image_id > 0) {
      $vacancy_image_url = (string)wp_get_attachment_url($vacancy_image_id);
    }
    $interim_user_id = $source_post ? (int)get_post_meta($source_post->ID, self::META_INTERIM_USER_ID, true) : 0;
    $hide_if_vacant = $source_post ? !empty(get_post_meta($source_post->ID, self::META_HIDE_IF_VACANT, true)) : false;

    $job_terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name']);
    if (is_wp_error($job_terms)) $job_terms = [];

    $users = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
    $division_options = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::options_for_select() : [];

    echo '<div class="wrap">';
    echo '<h1>Manager IDs</h1>';
    echo '<p class="description">A Manager ID can apply to a Job Path branch alone or to a specific Job Path + Job Title lane. Job Path + Job Title lanes inherit down the branch first, then the closest generic path fills in behind them. Users may still override by Manager Code or Manager ID on their profile.</p>';

    echo '<div style="display:flex; gap:24px; align-items:flex-start; margin-top:16px;">';

    echo '<div style="flex:1; min-width:360px; max-width:520px;">';
    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin:0 0 12px 0;">' . ($edit_post ? 'Edit Manager ID' : 'Create Manager ID') . '</h2>';
    if ($copy_post) {
      echo '<div class="notice notice-info inline"><p>Copied manager data from <strong>' . esc_html($copy_post->post_title) . '</strong>. Update the name, code, and path as needed before saving.</p></div>';
    }

    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_manager_ids_save">';
    wp_nonce_field('eum_manager_ids_save', 'eum_manager_ids_nonce');
    if ($edit_post) echo '<input type="hidden" name="post_id" value="' . (int)$edit_post->ID . '">';

    echo '<p><label><strong>Name</strong></label><br>';
    echo '<input type="text" class="regular-text" name="title" required value="' . esc_attr($edit_post ? $edit_post->post_title : ($copy_post ? $copy_post->post_title . ' Copy' : '')) . '"></p>';

    echo '<p><label><strong>Manager Code</strong></label><br>';
    echo '<input type="text" class="regular-text" name="code" placeholder="e.g. MGR-ENG-PLAT" value="' . esc_attr($code) . '">';
    echo '<br><span class="description">Recommended. Used for Manager Code Overrides and portability between environments.</span></p>';

    echo '<p><label><strong>Applies to Job Path (root of branch)</strong></label><br>';
    if (class_exists('EUM_Profiles')) {
      echo EUM_Profiles::render_job_path_directory_picker('job_path_term_id', (int)$job_path_term_id, get_current_user_id(), [
        'placeholder' => '— Select a Job Path —',
        'required' => false,
        'none_label' => '— None —',
        'enforce_manageable_job_paths' => false,
      ]);
    } else {
      echo '<select name="job_path_term_id" class="regular-text">';
      echo '<option value="0">— Select a Job Path —</option>';
      foreach ($job_terms as $t) {
        $tid = (int)$t->term_id;
        $sel = ($tid === $job_path_term_id) ? 'selected' : '';
        echo '<option value="' . $tid . '" ' . $sel . '>' . esc_html($this->term_full_path('eum_job_path', $tid, ' → ')) . '</option>';
      }
      echo '</select>';
    }
    echo '<br><span class="description">Manages this Job Path and everything under it unless a deeper Manager ID exists.</span></p>';

    echo '<p><label><strong>Applies to Job Title (optional)</strong></label><br>';
    echo $this->render_manager_job_title_select('job_title', $job_path_term_id, $manager_job_title);
    echo '<br><span class="description">Optional. Use this when a path needs a different manager lane for one specific title.</span></p>';

    echo '<p><label><strong>Managers (users)</strong></label><br>';
    echo '<select name="manager_users[]" multiple size="10" style="min-width:360px;">';
    foreach ($users as $u) {
      $uid = (int)$u->ID;
      $sel = in_array($uid, $manager_users, true) ? 'selected' : '';
      $label = $u->display_name ? $u->display_name : $u->user_login;
      echo '<option value="' . $uid . '" ' . $sel . '>' . esc_html($label . ' (#' . $uid . ')') . '</option>';
    }
    echo '</select><br><span class="description">A user can be a manager for multiple Manager IDs.</span></p>';

    echo '<p><label><strong>Additional Mailboxes</strong></label><br>';
    echo '<textarea name="access_emails" rows="6" class="large-text code" placeholder="sales@example.com&#10;Sales Support | support@example.com">' . esc_textarea(self::access_emails_to_text($access_emails)) . '</textarea>';
    echo '<br><span class="description">One mailbox per line. Use either <code>email@example.com</code> or <code>Label | email@example.com</code>. Managers assigned to this Manager ID will see these mailbox links in the front end.</span></p>';


    echo '<hr style="margin:20px 0;">';
    echo '<h3 style="margin:0 0 12px 0;">Vacancy / Interim Org Chart Card</h3>';
    echo '<p><label><strong>Vacancy Job Title Placeholder</strong></label><br>';
    echo '<input type="text" class="regular-text" name="vacancy_job_title" placeholder="Example: VP of Operations" value="' . esc_attr($vacancy_job_title) . '">';
    echo '<br><span class="description">Shown as the Job Title when this Manager ID has no assigned manager user.</span></p>';

    echo '<p><label><strong>Vacancy Company Division</strong></label><br>';
    echo '<select name="vacancy_division_key" class="regular-text">';
    echo '<option value="">— Use Manager Job Path Division —</option>';
    foreach ($division_options as $division_key => $division_label) {
      echo '<option value="' . esc_attr($division_key) . '" ' . selected($vacancy_division_key, (string)$division_key, false) . '>' . esc_html($division_label) . '</option>';
    }
    echo '</select>';
    echo '<br><span class="description">Displayed as the company division on vacancy or interim manager cards.</span></p>';

    echo '<p><label><strong>Interim Display User</strong></label><br>';
    echo '<select name="interim_user_id" class="regular-text">';
    echo '<option value="0">— None —</option>';
    foreach ($users as $u) {
      $uid = (int)$u->ID;
      $label = $u->display_name ? $u->display_name : $u->user_login;
      echo '<option value="' . $uid . '" ' . selected($interim_user_id, $uid, false) . '>' . esc_html($label . ' (#' . $uid . ')') . '</option>';
    }
    echo '</select>';
    echo '<br><span class="description">Use any existing user as the interim manager on the org chart while still keeping them in their normal position elsewhere.</span></p>';

    if ($vacancy_image_url !== '') {
      echo '<p><strong>Current Vacancy / Interim Image</strong><br><img src="' . esc_url($vacancy_image_url) . '" alt="" style="max-width:96px;height:auto;border-radius:12px;border:1px solid #ccd0d4;padding:4px;background:#fff;"></p>';
    }
    echo '<p><label><strong>Vacancy / Interim Profile Image</strong></label><br>';
    echo '<input type="file" name="vacancy_profile_image_upload" accept="image/*">';
    echo '<br><span class="description">Optional. If empty, interim cards use the user\'s profile image when available. Otherwise the org chart generates initials from the vacancy title.</span></p>';
    echo '<p><label><input type="checkbox" name="vacancy_profile_image_remove" value="1"> Remove current vacancy/interim image</label></p>';

    echo '<p><label><input type="checkbox" name="hide_if_vacant" value="1" ' . checked($hide_if_vacant, true, false) . '> <strong>Disable vacancy card when vacant</strong></label>';
    echo '<br><span class="description">When enabled and this Manager ID has no assigned manager user or interim display user, the org chart skips this vacant level and uses the next manager up.</span></p>';

    echo '<p style="margin-top:14px;">';
    echo '<button type="submit" class="button button-primary">' . ($edit_post ? 'Update' : 'Create') . '</button> ';
    if ($edit_post || $copy_post) {
      echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=eum-manager-ids')) . '">Cancel</a>';
    }
    echo '</p>';

    echo '</form>';
    echo '</div></div>';

    echo '<div style="flex:2; min-width:560px;">';
    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin:0 0 12px 0;">Existing Manager IDs</h2>';

    $items = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
    ]);

    if (empty($items)) {
      echo '<p>No Manager IDs yet.</p>';
    } else {
      echo '<table class="widefat striped">';
      echo '<thead><tr><th>Name</th><th>Code</th><th>Job Path</th><th>Job Title</th><th>Managers</th><th>Vacancy</th><th>Mailboxes</th><th style="width:170px;">Actions</th></tr></thead><tbody>';

      foreach ($items as $p) {
        $pid = (int)$p->ID;
        $row_code = (string)get_post_meta($pid, self::META_CODE, true);
        $job_tid = (int)get_post_meta($pid, self::META_JOB_PATH_TERM, true);
        $row_job_title = $this->manager_job_title_label((string)get_post_meta($pid, self::META_JOB_TITLE, true));
        $mgrs = (array)get_post_meta($pid, self::META_MANAGER_USERS, true);
        $mgrs = array_values(array_filter(array_map('intval', $mgrs)));
        $mailboxes = (array)get_post_meta($pid, self::META_ACCESS_EMAILS, true);
        $row_vacancy_title = (string)get_post_meta($pid, self::META_VACANCY_JOB_TITLE, true);
        $row_vacancy_division = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::normalize_division_key((string)get_post_meta($pid, self::META_VACANCY_DIVISION_KEY, true)) : sanitize_title((string)get_post_meta($pid, self::META_VACANCY_DIVISION_KEY, true));
        $row_interim_user_id = (int)get_post_meta($pid, self::META_INTERIM_USER_ID, true);
        $row_hide_if_vacant = !empty(get_post_meta($pid, self::META_HIDE_IF_VACANT, true));

        $mgr_labels = [];
        foreach ($mgrs as $uid) {
          $u = get_user_by('id', $uid);
          if ($u) $mgr_labels[] = $u->display_name ?: $u->user_login;
        }

        $edit_url = esc_url(admin_url('admin.php?page=eum-manager-ids&edit=' . $pid));
        $copy_url = esc_url(admin_url('admin.php?page=eum-manager-ids&copy=' . $pid));

        echo '<tr>';
        echo '<td>' . esc_html($p->post_title) . '</td>';
        echo '<td>' . esc_html($row_code) . '</td>';
        echo '<td>' . esc_html($job_tid ? $this->term_full_path('eum_job_path', $job_tid, ' → ') : '') . '</td>';
        echo '<td>' . esc_html($row_job_title !== '' ? $row_job_title : 'Any title') . '</td>';
        echo '<td>' . esc_html(implode(', ', $mgr_labels)) . '</td>';
        $vacancy_bits = [];
        if ($row_hide_if_vacant) {
          $vacancy_bits[] = 'Skip vacant';
        } else {
          $vacancy_bits[] = ($row_vacancy_title !== '' ? $row_vacancy_title : 'Vacancy');
          if ($row_vacancy_division !== '') $vacancy_bits[] = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::division_label($row_vacancy_division) : $row_vacancy_division;
          if ($row_interim_user_id > 0) {
            $interim_user = get_user_by('id', $row_interim_user_id);
            if ($interim_user) $vacancy_bits[] = 'Interim: ' . ($interim_user->display_name ?: $interim_user->user_login);
          }
        }
        echo '<td>' . esc_html(implode(' • ', array_filter($vacancy_bits))) . '</td>';
        echo '<td>' . esc_html((string)count(array_filter((array)$mailboxes))) . '</td>';
        echo '<td>';
        echo '<a class="button button-small" href="' . $edit_url . '">Edit</a> ';
        echo '<a class="button button-small" href="' . $copy_url . '">Copy</a> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
        echo '<input type="hidden" name="action" value="eum_manager_ids_delete">';
        wp_nonce_field('eum_manager_ids_delete', 'eum_manager_ids_nonce');
        echo '<input type="hidden" name="post_id" value="' . $pid . '">';
        echo '<button class="button button-small" type="submit" onclick="return confirm(\'Delete this Manager ID?\')">Delete</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
      }

      echo '</tbody></table>';
    }

    echo '</div></div>';
    echo '</div>';
    echo '</div>';
  }

  public function handle_save(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_manager_ids_nonce']) || !wp_verify_nonce($_POST['eum_manager_ids_nonce'], 'eum_manager_ids_save')) wp_die('Bad nonce.');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $title = isset($_POST['title']) ? sanitize_text_field((string)$_POST['title']) : '';
    $code = isset($_POST['code']) ? sanitize_text_field((string)$_POST['code']) : '';
    $job_tid = isset($_POST['job_path_term_id']) ? intval($_POST['job_path_term_id']) : 0;
    $job_title = $this->normalize_manager_job_title((string)($_POST['job_title'] ?? ''), $job_tid);
    $mgrs = isset($_POST['manager_users']) ? array_map('intval', (array)$_POST['manager_users']) : [];
    $mgrs = array_values(array_filter($mgrs, fn($x) => $x > 0));
    $access_emails = self::parse_access_emails((string)($_POST['access_emails'] ?? ''));
    $vacancy_job_title = sanitize_text_field((string)($_POST['vacancy_job_title'] ?? ''));
    $vacancy_division_key = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::normalize_division_key((string)($_POST['vacancy_division_key'] ?? '')) : sanitize_title((string)($_POST['vacancy_division_key'] ?? ''));
    $interim_user_id = isset($_POST['interim_user_id']) ? intval($_POST['interim_user_id']) : 0;
    $hide_if_vacant = !empty($_POST['hide_if_vacant']);
    $remove_vacancy_image = !empty($_POST['vacancy_profile_image_remove']);

    if ($title === '') $this->redirect_msg('Name is required.', 'error');
    if ($job_title !== '' && $job_tid <= 0) $this->redirect_msg('Choose a Job Path before assigning a Job Title.', 'error');

    if ($post_id) {
      $p = get_post($post_id);
      if (!$p || $p->post_type !== self::CPT) $this->redirect_msg('Invalid item.', 'error');
      wp_update_post(['ID' => $post_id, 'post_title' => $title]);
    } else {
      $post_id = (int)wp_insert_post([
        'post_type' => self::CPT,
        'post_status' => 'publish',
        'post_title' => $title,
      ]);
      if (!$post_id) $this->redirect_msg('Failed to create.', 'error');
    }

    update_post_meta($post_id, self::META_CODE, $code);
    update_post_meta($post_id, self::META_JOB_PATH_TERM, $job_tid);
    if ($job_title !== '') update_post_meta($post_id, self::META_JOB_TITLE, $job_title);
    else delete_post_meta($post_id, self::META_JOB_TITLE);
    update_post_meta($post_id, self::META_MANAGER_USERS, $mgrs);
    update_post_meta($post_id, self::META_ACCESS_EMAILS, $access_emails);
    update_post_meta($post_id, self::META_VACANCY_JOB_TITLE, $vacancy_job_title);
    update_post_meta($post_id, self::META_VACANCY_DIVISION_KEY, $vacancy_division_key);
    update_post_meta($post_id, self::META_INTERIM_USER_ID, $interim_user_id > 0 ? $interim_user_id : 0);
    update_post_meta($post_id, self::META_HIDE_IF_VACANT, $hide_if_vacant ? '1' : '');

    if ($remove_vacancy_image) {
      delete_post_meta($post_id, self::META_VACANCY_IMAGE_ID);
      delete_post_meta($post_id, self::META_VACANCY_IMAGE_URL);
    }

    if (!empty($_FILES['vacancy_profile_image_upload']['name'])) {
      if (!function_exists('media_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
      }
      $attachment_id = media_handle_upload('vacancy_profile_image_upload', 0);
      if (!is_wp_error($attachment_id) && $attachment_id > 0) {
        update_post_meta($post_id, self::META_VACANCY_IMAGE_ID, (int)$attachment_id);
        update_post_meta($post_id, self::META_VACANCY_IMAGE_URL, (string)wp_get_attachment_url($attachment_id));
      }
    }

    $this->redirect_msg('Saved.');
  }

  public function handle_delete(): void {

    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_manager_ids_nonce']) || !wp_verify_nonce($_POST['eum_manager_ids_nonce'], 'eum_manager_ids_delete')) wp_die('Bad nonce.');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) $this->redirect_msg('Missing id.', 'error');

    $p = get_post($post_id);
    if (!$p || $p->post_type !== self::CPT) $this->redirect_msg('Invalid item.', 'error');

    wp_delete_post($post_id, true);
    $this->redirect_msg('Deleted.');
  }

  private function redirect_msg(string $msg, string $type = 'success'): void {
    $url = admin_url('admin.php?page=eum-manager-ids&eum_msg=' . rawurlencode($msg) . '&eum_type=' . $type);
    wp_safe_redirect($url);
    exit;
  }

  public function profile_field($user): void {
    if (!EUM_Access::current_user_is_it()) return;

    $override_code = (string)get_user_meta($user->ID, self::USER_META_OVERRIDE_CODE, true);
    $override_id   = (int)get_user_meta($user->ID, self::USER_META_OVERRIDE_ID, true);

    $effective = $this->resolve_effective_manager_id_for_user((int)$user->ID);
    $effective_title = $effective ? get_the_title($effective) : 'None';
    $effective_code  = $effective ? (string)get_post_meta($effective, self::META_CODE, true) : '';

    $items = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => 500,
      'orderby' => 'title',
      'order' => 'ASC',
    ]);

    echo '<h2>Enterprise Manager</h2>';
    echo '<table class="form-table"><tbody>';

    echo '<tr>';
    echo '<th><label for="eum_manager_code_override">Manager Code Override</label></th>';
    echo '<td>';
    echo '<input type="text" class="regular-text" name="eum_manager_code_override" id="eum_manager_code_override" value="' . esc_attr($override_code) . '" placeholder="e.g. MGR-ENG-PLAT" />';
    echo '<p class="description">If set, this overrides everything (recommended). Leave blank to inherit from Job Path + Job Title.</p>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th><label for="eum_manager_id_override">Manager ID Override (dropdown)</label></th>';
    echo '<td>';
    echo '<select name="eum_manager_id_override" id="eum_manager_id_override">';
    echo '<option value="0">— Inherit from Job Path —</option>';
    foreach ($items as $p) {
      $sel = ((int)$p->ID === $override_id) ? 'selected' : '';
      $label = $p->post_title;
      $item_code = (string)get_post_meta($p->ID, self::META_CODE, true);
      if ($item_code !== '') $label .= ' [' . $item_code . ']';
      echo '<option value="' . (int)$p->ID . '" ' . $sel . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    echo '<p class="description">Effective (computed) Manager ID: <strong>'
      . esc_html($effective_title)
      . '</strong>'
      . ($effective_code !== '' ? ' <span class="description">[' . esc_html($effective_code) . ']</span>' : '')
      . '</p>';

    echo '</td>';
    echo '</tr>';

    echo '</tbody></table>';
  }

  public function save_profile_field(int $user_id): void {
    if (!EUM_Access::current_user_is_it()) return;

    $code = isset($_POST['eum_manager_code_override']) ? sanitize_text_field((string)$_POST['eum_manager_code_override']) : '';
    $code = trim($code);

    if ($code !== '') {
      $mid = $this->find_manager_id_by_code($code);
      if ($mid > 0) {
        update_user_meta($user_id, self::USER_META_OVERRIDE_CODE, $code);
        update_user_meta($user_id, self::USER_META_OVERRIDE_ID, $mid);
      } else {
        delete_user_meta($user_id, self::USER_META_OVERRIDE_CODE);
      }
    } else {
      delete_user_meta($user_id, self::USER_META_OVERRIDE_CODE);
    }

    $override_id = isset($_POST['eum_manager_id_override']) ? intval($_POST['eum_manager_id_override']) : 0;
    if ($override_id > 0 && get_post_type($override_id) === self::CPT) {
      update_user_meta($user_id, self::USER_META_OVERRIDE_ID, $override_id);
      if ($code === '') {
        $sel_code = (string)get_post_meta($override_id, self::META_CODE, true);
        if ($sel_code !== '') update_user_meta($user_id, self::USER_META_OVERRIDE_CODE, $sel_code);
      }
    } else {
      delete_user_meta($user_id, self::USER_META_OVERRIDE_ID);
    }
  }

  public function resolve_effective_manager_id_for_user(int $user_id): int {
    $override_code = trim((string)get_user_meta($user_id, self::USER_META_OVERRIDE_CODE, true));
    if ($override_code !== '') {
      $mid = $this->find_manager_id_by_code($override_code);
      if ($mid > 0) return $mid;
    }

    $override_id = (int)get_user_meta($user_id, self::USER_META_OVERRIDE_ID, true);
    if ($override_id > 0 && get_post_type($override_id) === self::CPT) return $override_id;

    $job_context = $this->job_context_for_user($user_id);
    $leaf_id = (int)($job_context['job_path_term_id'] ?? 0);
    if ($leaf_id <= 0) return 0;

    $chain = $this->manager_id_chain_from_job_context($leaf_id, (string)($job_context['job_title'] ?? ''));
    return !empty($chain) ? (int)$chain[0] : 0;
  }

  public function find_manager_id_by_code(string $code): int {
    $code = trim($code);
    if ($code === '') return 0;

    $posts = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => 1,
      'meta_key' => self::META_CODE,
      'meta_value' => $code,
    ]);

    if (empty($posts)) return 0;
    return (int)$posts[0]->ID;
  }

  public function user_is_manager_for_id(int $user_id, int $manager_id_post): bool {
    if ($manager_id_post <= 0) return false;
    if (get_post_type($manager_id_post) !== self::CPT) return false;
    return in_array($user_id, self::manager_users_for_id($manager_id_post), true);
  }

  public static function term_manager_meta_key(): string {
    return class_exists('EUM_Manager_Messages') && defined('EUM_Manager_Messages::TERM_META_MANAGER_IDS')
      ? EUM_Manager_Messages::TERM_META_MANAGER_IDS
      : 'eum_term_manager_ids';
  }

  public static function manager_ids_for_term(string $taxonomy, int $term_id): array {
    $term_id = (int)$term_id;
    if ($term_id <= 0 || !taxonomy_exists($taxonomy)) return [];
    $ids = (array)get_term_meta($term_id, self::term_manager_meta_key(), true);
    return array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0 && get_post_type($id) === self::CPT)));
  }

  public static function term_ids_for_user(int $user_id, string $taxonomy): array {
    if ($user_id <= 0 || !taxonomy_exists($taxonomy)) return [];
    $ids = wp_get_object_terms($user_id, $taxonomy, ['fields' => 'ids']);
    if (is_wp_error($ids)) return [];
    return array_values(array_unique(array_filter(array_map('intval', (array)$ids), fn($id) => $id > 0)));
  }

  public static function applicable_manager_ids_for_user(int $user_id): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return [];

    $out = [];
    $mgr = new self();
    $effective = (int)$mgr->resolve_effective_manager_id_for_user($user_id);
    if ($effective > 0) $out[] = $effective;

    foreach (['eum_user_group', 'eum_project'] as $taxonomy) {
      foreach (self::term_ids_for_user($user_id, $taxonomy) as $term_id) {
        $out = array_merge($out, self::manager_ids_for_term($taxonomy, $term_id));
      }
    }

    return array_values(array_unique(array_filter(array_map('intval', $out), fn($id) => $id > 0)));
  }

  public static function manager_user_ids_for_subject_user(int $user_id): array {
    $depths = self::manager_depth_map_for_subject_user((int)$user_id);
    return array_values(array_map('intval', array_keys($depths)));
  }

  public static function user_is_direct_manager_of_user(int $manager_user_id, int $subject_user_id): bool {
    return self::management_depth_for_manager_user_subject($manager_user_id, $subject_user_id) === 1;
  }

  public static function management_depth_for_manager_user_subject(int $manager_user_id, int $subject_user_id): int {
    $manager_user_id = (int)$manager_user_id;
    $subject_user_id = (int)$subject_user_id;
    if ($manager_user_id <= 0 || $subject_user_id <= 0) return 0;
    if ($manager_user_id === $subject_user_id) return 0;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($manager_user_id)) return 1;

    $depths = self::manager_depth_map_for_subject_user($subject_user_id);
    return isset($depths[$manager_user_id]) ? (int)$depths[$manager_user_id] : 0;
  }

  public static function user_is_manager_of_user(int $manager_user_id, int $subject_user_id): bool {
    return self::management_depth_for_manager_user_subject($manager_user_id, $subject_user_id) > 0;
  }

  public static function managed_user_levels_for_manager_user(int $manager_user_id, bool $include_terminated = true, bool $include_indirect = false): array {
    $manager_user_id = (int)$manager_user_id;
    if ($manager_user_id <= 0) return [];

    $out = [];
    foreach ((array)get_users(['fields' => ['ID'], 'number' => 5000]) as $user) {
      $uid = is_object($user) ? (int)$user->ID : (int)$user;
      if ($uid <= 0 || $uid === $manager_user_id) continue;
      if (!$include_terminated && class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($uid)) continue;

      $depth = self::management_depth_for_manager_user_subject($manager_user_id, $uid);
      if ($depth <= 0) continue;
      if (!$include_indirect && $depth !== 1) continue;
      $out[$uid] = $depth;
    }

    return $out;
  }

  public static function managed_user_ids_for_manager_user(int $manager_user_id, bool $include_terminated = true, bool $include_indirect = false): array {
    return array_values(array_map('intval', array_keys(self::managed_user_levels_for_manager_user($manager_user_id, $include_terminated, $include_indirect))));
  }

  private static function manager_depth_map_for_subject_user(int $subject_user_id, array $visited_subject_ids = []): array {
    static $cache = [];

    $subject_user_id = (int)$subject_user_id;
    if ($subject_user_id <= 0) return [];
    if (isset($cache[$subject_user_id])) return $cache[$subject_user_id];
    if (isset($visited_subject_ids[$subject_user_id])) return [];

    $visited_subject_ids[$subject_user_id] = true;
    $depths = [];

    if (class_exists('EUM_Personnel')) {
      $direct_manager = (int)get_user_meta($subject_user_id, EUM_Personnel::META_MANAGER_ID, true);
      if ($direct_manager > 0 && $direct_manager !== $subject_user_id) {
        self::set_min_manager_depth($depths, $direct_manager, 1);
        foreach (self::manager_depth_map_for_subject_user($direct_manager, $visited_subject_ids) as $higher_user_id => $higher_depth) {
          self::set_min_manager_depth($depths, (int)$higher_user_id, ((int)$higher_depth) + 1);
        }
      }
    }

    foreach (self::direct_manager_id_chains_for_subject_user($subject_user_id) as $chain) {
      $level = 1;
      foreach ((array)$chain as $manager_id_post) {
        foreach (self::manager_users_for_id((int)$manager_id_post) as $manager_user_id) {
          if ($manager_user_id > 0 && $manager_user_id !== $subject_user_id) {
            self::set_min_manager_depth($depths, $manager_user_id, $level);
          }
        }
        $level++;
      }
    }

    $cache[$subject_user_id] = $depths;
    return $depths;
  }

  private static function direct_manager_id_chains_for_subject_user(int $subject_user_id): array {
    $subject_user_id = (int)$subject_user_id;
    if ($subject_user_id <= 0) return [];

    $mgr = new self();
    $chains = [];
    $seen = [];

    $effective = (int)$mgr->resolve_effective_manager_id_for_user($subject_user_id);
    if ($effective > 0) {
      $chain = $mgr->manager_id_ancestry_chain_from_manager_id($effective);
      if (empty($chain)) $chain = [$effective];
      $key = implode(':', array_map('intval', $chain));
      if ($key !== '' && !isset($seen[$key])) {
        $seen[$key] = true;
        $chains[] = array_values(array_unique(array_filter(array_map('intval', $chain), fn($id) => $id > 0)));
      }
    }

    foreach (['eum_user_group', 'eum_project'] as $taxonomy) {
      foreach (self::term_ids_for_user($subject_user_id, $taxonomy) as $term_id) {
        foreach (self::manager_ids_for_term($taxonomy, $term_id) as $manager_id_post) {
          $chain = $mgr->manager_id_ancestry_chain_from_manager_id((int)$manager_id_post);
          if (empty($chain)) $chain = [(int)$manager_id_post];
          $key = implode(':', array_map('intval', $chain));
          if ($key === '' || isset($seen[$key])) continue;
          $seen[$key] = true;
          $chains[] = array_values(array_unique(array_filter(array_map('intval', $chain), fn($id) => $id > 0)));
        }
      }
    }

    return $chains;
  }

  private static function set_min_manager_depth(array &$depths, int $manager_user_id, int $depth): void {
    $manager_user_id = (int)$manager_user_id;
    $depth = max(1, (int)$depth);
    if ($manager_user_id <= 0) return;
    if (!isset($depths[$manager_user_id]) || $depth < (int)$depths[$manager_user_id]) {
      $depths[$manager_user_id] = $depth;
    }
  }

  public static function manager_users_for_id(int $manager_id_post): array {
    if ($manager_id_post <= 0 || get_post_type($manager_id_post) !== self::CPT) return [];
    $mgrs = (array)get_post_meta($manager_id_post, self::META_MANAGER_USERS, true);
    $interim_user_id = (int)get_post_meta($manager_id_post, self::META_INTERIM_USER_ID, true);
    if ($interim_user_id > 0) $mgrs[] = $interim_user_id;
    return array_values(array_unique(array_filter(array_map('intval', $mgrs), fn($uid) => $uid > 0)));
  }

  public static function vacancy_config_for_id(int $manager_id_post): array {
    $manager_id_post = (int)$manager_id_post;
    if ($manager_id_post <= 0 || get_post_type($manager_id_post) !== self::CPT) {
      return [
        'job_title' => '',
        'division_key' => '',
        'division_label' => '',
        'hide_if_vacant' => false,
        'image_id' => 0,
        'image_url' => '',
        'interim_user_id' => 0,
      ];
    }

    $job_title = trim((string)get_post_meta($manager_id_post, self::META_VACANCY_JOB_TITLE, true));
    $division_key = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::normalize_division_key((string)get_post_meta($manager_id_post, self::META_VACANCY_DIVISION_KEY, true)) : sanitize_title((string)get_post_meta($manager_id_post, self::META_VACANCY_DIVISION_KEY, true));
    if ($division_key === '' && class_exists('EUM_Company_Divisions')) {
      $legacy_job_path_term_id = (int)get_post_meta($manager_id_post, self::META_VACANCY_JOB_PATH_TERM, true);
      if ($legacy_job_path_term_id > 0) {
        $division_key = EUM_Company_Divisions::effective_division_for_job_path($legacy_job_path_term_id);
      }
      if ($division_key === '') {
        $branch_job_path_term_id = (int)get_post_meta($manager_id_post, self::META_JOB_PATH_TERM, true);
        if ($branch_job_path_term_id > 0) {
          $division_key = EUM_Company_Divisions::effective_division_for_job_path($branch_job_path_term_id);
        }
      }
    }
    $hide_if_vacant = !empty(get_post_meta($manager_id_post, self::META_HIDE_IF_VACANT, true));
    $image_id = (int)get_post_meta($manager_id_post, self::META_VACANCY_IMAGE_ID, true);
    $image_url = (string)get_post_meta($manager_id_post, self::META_VACANCY_IMAGE_URL, true);
    if ($image_url === '' && $image_id > 0) {
      $image_url = (string)wp_get_attachment_url($image_id);
    }
    $interim_user_id = (int)get_post_meta($manager_id_post, self::META_INTERIM_USER_ID, true);

    return [
      'job_title' => $job_title,
      'division_key' => $division_key,
      'division_label' => class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::division_label($division_key) : $division_key,
      'hide_if_vacant' => $hide_if_vacant,
      'image_id' => $image_id,
      'image_url' => $image_url,
      'interim_user_id' => $interim_user_id,
    ];
  }

  public function manager_id_chain_for_user(int $user_id): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return [];

    $override_code = trim((string)get_user_meta($user_id, self::USER_META_OVERRIDE_CODE, true));
    if ($override_code !== '') {
      $override_id = $this->find_manager_id_by_code($override_code);
      if ($override_id > 0) return $this->manager_id_ancestry_chain_from_manager_id($override_id);
    }

    $override_id = (int)get_user_meta($user_id, self::USER_META_OVERRIDE_ID, true);
    if ($override_id > 0 && get_post_type($override_id) === self::CPT) {
      return $this->manager_id_ancestry_chain_from_manager_id($override_id);
    }

    $job_context = $this->job_context_for_user($user_id);
    $leaf_id = (int)($job_context['job_path_term_id'] ?? 0);
    if ($leaf_id <= 0) return [];

    return $this->manager_id_chain_from_job_context($leaf_id, (string)($job_context['job_title'] ?? ''));
  }

  public function manager_id_ancestry_chain_from_manager_id(int $manager_id_post): array {
    $manager_id_post = (int)$manager_id_post;
    if ($manager_id_post <= 0 || get_post_type($manager_id_post) !== self::CPT) return [];

    $job_term_id = (int)get_post_meta($manager_id_post, self::META_JOB_PATH_TERM, true);
    if ($job_term_id <= 0) return [$manager_id_post];

    $job_title = $this->normalize_manager_job_title((string)get_post_meta($manager_id_post, self::META_JOB_TITLE, true), $job_term_id);
    $chain = $this->manager_id_chain_from_job_context($job_term_id, $job_title);
    if (empty($chain)) return [$manager_id_post];

    $idx = array_search($manager_id_post, $chain, true);
    if ($idx === false) array_unshift($chain, $manager_id_post);
    return array_values(array_unique(array_filter(array_map('intval', $chain), fn($id) => $id > 0)));
  }

  private function manager_id_chain_from_job_context(int $leaf_id, string $job_title = ''): array {
    $leaf_id = (int)$leaf_id;
    if ($leaf_id <= 0 || !taxonomy_exists('eum_job_path')) return [];
    $job_title = $this->normalize_manager_job_title($job_title, $leaf_id);
    $job_title_label = $job_title !== '' ? strtolower($this->manager_job_title_label($job_title)) : '';

    $check = [$leaf_id];
    $check = array_merge($check, get_ancestors($leaf_id, 'eum_job_path'));

    $items = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'orderby' => 'title',
      'order' => 'ASC',
    ]);

    $by_job = [];
    $by_job_title = [];
    foreach ($items as $p) {
      $jt = (int)get_post_meta($p->ID, self::META_JOB_PATH_TERM, true);
      if ($jt <= 0) continue;
      $manager_job_title = $this->normalize_manager_job_title((string)get_post_meta($p->ID, self::META_JOB_TITLE, true), $jt);
      if ($manager_job_title !== '') {
        if (empty($by_job_title[$jt][$manager_job_title])) $by_job_title[$jt][$manager_job_title] = (int)$p->ID;
        continue;
      }
      if (empty($by_job[$jt])) $by_job[$jt] = (int)$p->ID;
    }

    $title_matches = [];
    $generic_matches = [];
    foreach ($check as $tid) {
      $tid = (int)$tid;
      if ($job_title !== '' && !empty($by_job_title[$tid])) {
        if (!empty($by_job_title[$tid][$job_title])) {
          $title_matches[] = (int)$by_job_title[$tid][$job_title];
        } elseif ($job_title_label !== '') {
          foreach ((array)$by_job_title[$tid] as $manager_job_title => $manager_post_id) {
            $manager_title_label = strtolower($this->manager_job_title_label((string)$manager_job_title));
            if ($manager_title_label === '' || $manager_title_label !== $job_title_label) continue;
            $title_matches[] = (int)$manager_post_id;
            break;
          }
        }
      }
      if (!empty($by_job[$tid])) $generic_matches[] = (int)$by_job[$tid];
    }

    $out = array_merge($title_matches, $generic_matches);
    return array_values(array_unique(array_filter(array_map('intval', $out), fn($id) => $id > 0)));
  }

  private function job_context_for_user(int $user_id): array {
    $job_path_term_id = 0;
    if (taxonomy_exists('eum_job_path')) {
      $job_terms = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
      if (!is_wp_error($job_terms) && !empty($job_terms)) $job_path_term_id = (int)$job_terms[0];
    }

    $job_title = '';
    if (class_exists('EUM_Profiles')) {
      $job_title = $this->normalize_manager_job_title((string)EUM_Profiles::get_field_raw_value($user_id, 'custom:job_title'), $job_path_term_id);
    } elseif (class_exists('EUM_Fields')) {
      $all = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
      $job_title = $this->normalize_manager_job_title((string)($all['job_title'] ?? ''), $job_path_term_id);
    }

    return [
      'job_path_term_id' => $job_path_term_id,
      'job_title' => $job_title,
    ];
  }

  private function normalize_manager_job_title($value, int $job_term_id = 0): string {
    if (class_exists('EUM_Profiles')) {
      return (string)EUM_Profiles::normalize_job_title_value($value, $job_term_id);
    }
    return sanitize_text_field((string)$value);
  }

  private function manager_job_title_label($value): string {
    if (class_exists('EUM_Profiles')) {
      return (string)EUM_Profiles::job_title_label_from_value($value);
    }
    return sanitize_text_field((string)$value);
  }

  private function render_manager_job_title_select(string $name, int $job_path_term_id, string $current = ''): string {
    $current = $this->normalize_manager_job_title($current, $job_path_term_id);
    $records = [];

    if (class_exists('EUM_Job_Titles')) {
      if ($job_path_term_id > 0) {
        $records = EUM_Job_Titles::title_records_for_job_term($job_path_term_id);
      } else {
        foreach (EUM_Job_Titles::title_posts() as $post) {
          $post_id = (int)$post->ID;
          $records[] = [
            'token' => EUM_Job_Titles::token_from_id($post_id),
            'label' => EUM_Job_Titles::title_label($post_id),
          ];
        }
      }
    }

    ob_start();
    echo '<select name="' . esc_attr($name) . '" class="regular-text">';
    echo '<option value="">Any title on this path</option>';
    foreach ($records as $row) {
      $token = sanitize_text_field((string)($row['token'] ?? ''));
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($token === '' || $label === '') continue;
      echo '<option value="' . esc_attr($token) . '" ' . selected($current, $token, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    if ($job_path_term_id > 0 && empty($records)) {
      echo '<div class="description" style="margin-top:4px;">No Job Titles are currently assigned to this Job Path.</div>';
    }
    return (string)ob_get_clean();
  }

  public static function parse_access_emails(string $text): array {
    $rows = preg_split('/\r\n|\r|\n/', $text);
    $out = [];
    foreach ((array)$rows as $row) {
      $row = trim((string)$row);
      if ($row === '') continue;
      $label = '';
      $email = '';
      if (strpos($row, '|') !== false) {
        [$label, $email] = array_map('trim', explode('|', $row, 2));
      } elseif (preg_match('/^(.*?)<([^>]+)>$/', $row, $m)) {
        $label = trim((string)$m[1]);
        $email = trim((string)$m[2]);
      } else {
        $email = $row;
      }
      $email = sanitize_email($email);
      if ($email === '') continue;
      if ($label === '') $label = $email;
      $out[] = ['label' => sanitize_text_field($label), 'email' => $email];
    }
    return array_values($out);
  }

  public static function access_emails_to_text(array $rows): string {
    $lines = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $email = sanitize_email((string)($row['email'] ?? ''));
      if ($email === '') continue;
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      $lines[] = ($label !== '' && $label !== $email) ? ($label . ' | ' . $email) : $email;
    }
    return implode("\n", $lines);
  }

  public static function manager_ids_for_user(int $user_id): array {
    if ($user_id <= 0) return [];
    $items = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'orderby' => 'title',
      'order' => 'ASC',
    ]);
    $ids = [];
    foreach ($items as $item) {
      $mgrs = self::manager_users_for_id((int)$item->ID);
      if (in_array($user_id, $mgrs, true)) $ids[] = (int)$item->ID;
    }
    return $ids;
  }

  public static function additional_mailboxes_for_manager_user(int $user_id): array {
    $out = [];
    $seen = [];
    foreach (self::manager_ids_for_user($user_id) as $manager_id) {
      $rows = (array)get_post_meta($manager_id, self::META_ACCESS_EMAILS, true);
      $manager_label = get_the_title($manager_id);
      foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $email = sanitize_email((string)($row['email'] ?? ''));
        if ($email === '') continue;
        $key = strtolower($email);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = [
          'label' => sanitize_text_field((string)($row['label'] ?? $email)),
          'email' => $email,
          'manager_id' => $manager_id,
          'manager_id_label' => sanitize_text_field((string)$manager_label),
        ];
      }
    }
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
}
