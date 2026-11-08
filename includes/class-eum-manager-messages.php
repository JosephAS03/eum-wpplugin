<?php
if (!defined('ABSPATH')) exit;

class EUM_Manager_Messages {

  const CPT = 'eum_mgr_message';

  // User meta
  const USER_META_HR_IT = 'eum_hr_it_access';

  // Post meta
  const META_TARGET_TYPE          = 'eum_mm_target_type';        // jobpath|group|project
  const META_TARGET_ID            = 'eum_mm_target_id';          // term_id
  const META_MESSAGE_HTML         = 'eum_mm_message_html';       // rich text (sanitized)
  const META_TEXT_COLOR           = 'eum_mm_text_color';         // hex/css
  const META_BANNER_COLOR         = 'eum_mm_banner_color';       // hex/css (not grey)
  const META_AUTHOR_NAME          = 'eum_mm_author_name';        // text label (editable by HR/IT)
  const META_EFFECTIVE_TS         = 'eum_mm_effective_ts';       // unix
  const META_EXPIRES_TS           = 'eum_mm_expires_ts';         // unix
  const META_DISMISSIBLE          = 'eum_mm_dismissible';        // '1' or '0'
  const META_OWNER_UID            = 'eum_mm_owner_uid';          // current owner (editable by HR/IT)
  const META_ORIGINAL_AUTHOR_UID  = 'eum_mm_original_author_uid';// original submitter (immutable)
  const META_UPDATED_TS           = 'eum_mm_updated_ts';         // unix
  const META_NOTES                = 'eum_mm_notes';              // backend-only notes

  // Term meta (who manages this project/group)
  const TERM_META_MANAGER_IDS = 'eum_term_manager_ids'; // array of manager-id post IDs

  public function init(): void {
    add_action('init', [$this, 'register']);

    add_action('show_user_profile', [$this, 'render_hr_it_profile_field']);
    add_action('edit_user_profile', [$this, 'render_hr_it_profile_field']);
    add_action('personal_options_update', [$this, 'save_hr_it_profile_field']);
    add_action('edit_user_profile_update', [$this, 'save_hr_it_profile_field']);

    add_action('admin_post_eum_mm_save', [$this, 'handle_save']);
    add_action('admin_post_eum_mm_delete', [$this, 'handle_delete']);

    // Projects & Groups: attach manager IDs
    add_action('eum_project_add_form_fields', [$this, 'term_add_fields_project']);
    add_action('eum_project_edit_form_fields', [$this, 'term_edit_fields_project']);
    add_action('created_eum_project', [$this, 'term_save_fields'], 10, 2);
    add_action('edited_eum_project', [$this, 'term_save_fields'], 10, 2);

    add_action('eum_user_group_add_form_fields', [$this, 'term_add_fields_group']);
    add_action('eum_user_group_edit_form_fields', [$this, 'term_edit_fields_group']);
    add_action('created_eum_user_group', [$this, 'term_save_fields'], 10, 2);
    add_action('edited_eum_user_group', [$this, 'term_save_fields'], 10, 2);
  }

  public function register(): void {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Manager Messages',
        'singular_name' => 'Manager Message',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public function menu(): void {
    // Manager features are intentionally front-end only.
  }

  // =============================================================================
  // HR & IT profile field (User ID 1 always forced on)
  // =============================================================================

  public function render_hr_it_profile_field($user): void {
    if (!current_user_can('manage_options') && !$this->is_hr_it_user(get_current_user_id())) {
      return;
    }

    $uid = (int)$user->ID;
    $forced = ($uid === EUM_Access::forced_root_user_id());
    $enabled = $forced ? true : $this->is_hr_it_user($uid);

    echo '<h2>Enterprise User Management</h2>';
    echo '<table class="form-table" role="presentation">';
    echo '<tr>';
    echo '<th><label for="eum_hr_it_access">HR &amp; IT Access</label></th>';
    echo '<td>';

    if ($forced) {
      echo '<label><input type="checkbox" checked disabled> Enabled (User ID 1 is always HR &amp; IT)</label>';
      echo '<input type="hidden" name="eum_hr_it_access" value="1">';
    } else {
      echo '<label><input type="checkbox" name="eum_hr_it_access" id="eum_hr_it_access" value="1" '.checked($enabled, true, false).'> Enable full HR &amp; IT access (Manager Messages + permissions)</label>';
    }

    echo '<p class="description">HR &amp; IT users can bypass time/assignment restrictions, edit Author Name, and reassign ownership.</p>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
  }

  public function save_hr_it_profile_field($user_id): void {
    $user_id = (int)$user_id;

    if ($user_id === EUM_Access::forced_root_user_id()) {
      update_user_meta($user_id, self::USER_META_HR_IT, '1');
      return;
    }

    if (!current_user_can('manage_options') && !$this->is_hr_it_user(get_current_user_id())) {
      return;
    }

    $val = isset($_POST['eum_hr_it_access']) ? '1' : '0';
    update_user_meta($user_id, self::USER_META_HR_IT, $val);
  }

  private function is_hr_it_user(int $user_id): bool {
    return EUM_Access::is_it_or_hr($user_id);
  }

  private function is_privileged(int $user_id): bool {
    return EUM_Access::is_it_or_hr($user_id);
  }

  // =============================================================================
  // Admin UI
  // =============================================================================

  public function render(): void {
    if (!$this->can_access_tool()) wp_die('Not allowed.');

    $uid = get_current_user_id();
    $priv = $this->is_privileged($uid);

    $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
    $edit = $edit_id ? get_post($edit_id) : null;
    if ($edit && $edit->post_type !== self::CPT) $edit = null;

    if ($edit && !$this->can_manage_message($uid, (int)$edit->ID)) {
      wp_die('Not allowed to edit this message.');
    }

    if (!empty($_GET['eum_msg'])) {
      $msg  = sanitize_text_field((string)$_GET['eum_msg']);
      $type = !empty($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
      $cls  = ($type === 'error') ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="'.$cls.'"><p>'.esc_html($msg).'</p></div>';
    }

    $now = time();
    $default_eff = $now + 300;
    $default_exp = $now + 72 * 3600;

    // Defaults / edit values
    $message_html = $edit ? (string)get_post_meta($edit->ID, self::META_MESSAGE_HTML, true) : '';
    $text_color   = $edit ? (string)get_post_meta($edit->ID, self::META_TEXT_COLOR, true) : '#ffffff';
    $banner_color = $edit ? (string)get_post_meta($edit->ID, self::META_BANNER_COLOR, true) : '#b91c1c';

    $owner_uid = $edit ? (int)get_post_meta($edit->ID, self::META_OWNER_UID, true) : $uid;
    if ($owner_uid <= 0) $owner_uid = $uid;

    $original_uid = $edit ? (int)get_post_meta($edit->ID, self::META_ORIGINAL_AUTHOR_UID, true) : $uid;
    if ($original_uid <= 0) $original_uid = $uid;

    $default_author_name = $this->user_full_name($uid);
    $author_name = $edit ? (string)get_post_meta($edit->ID, self::META_AUTHOR_NAME, true) : $default_author_name;
    if (!$edit && $author_name === '') $author_name = $default_author_name;

    $eff_ts = $edit ? (int)get_post_meta($edit->ID, self::META_EFFECTIVE_TS, true) : $default_eff;
    $exp_ts = $edit ? (int)get_post_meta($edit->ID, self::META_EXPIRES_TS, true) : $default_exp;
    if (!$edit && $eff_ts <= 0) $eff_ts = $default_eff;
    if (!$edit && $exp_ts <= 0) $exp_ts = $default_exp;

    $eff_str = gmdate('Y-m-d\TH:i', $eff_ts);
    $exp_str = gmdate('Y-m-d\TH:i', $exp_ts);

    $dismissible = $edit ? (string)get_post_meta($edit->ID, self::META_DISMISSIBLE, true) : '1';
    if ($dismissible !== '0') $dismissible = '1';

    $notes = $edit ? (string)get_post_meta($edit->ID, self::META_NOTES, true) : '';

    $target_type = $edit ? (string)get_post_meta($edit->ID, self::META_TARGET_TYPE, true) : 'jobpath';
    $target_id   = $edit ? (int)get_post_meta($edit->ID, self::META_TARGET_ID, true) : 0;

    // Effective Users dropdown options
    $allowed_targets = $priv ? $this->get_all_targets() : $this->get_allowed_targets_for_user($uid);

    // Current selection value
    $current_target_value = ($target_type && $target_id) ? ($target_type.':'.$target_id) : '';

    // If editing and selection is not in dropdown for non-privileged (e.g., ownership changed), still show it
    if (!$priv && $edit && $current_target_value !== '' && !$this->value_in_targets($current_target_value, $allowed_targets)) {
      $allowed_targets = array_merge(
        [['value' => $current_target_value, 'label' => '(Current) '.$this->format_target_label($target_type, $target_id)]],
        $allowed_targets
      );
    }

    // Owner dropdown (privileged only)
    $owner_user_options = [];
    if ($priv) {
      $owner_user_options = get_users([
        'fields' => ['ID', 'display_name', 'user_login'],
        'number' => 2000,
        'orderby' => 'display_name',
        'order' => 'ASC',
      ]);
      if (!is_array($owner_user_options)) $owner_user_options = [];
    }

    echo '<div class="wrap">';
    echo '<h1>Manager Messages</h1>';
    echo '<p class="description">Create full-width banners for users you manage. HR &amp; IT can bypass restrictions and reassign ownership.</p>';

    echo '<div style="display:flex; gap:24px; align-items:flex-start; margin-top:16px;">';

    // Form
    echo '<div style="flex:1; min-width:420px; max-width:820px;">';
    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin:0 0 12px 0;">'.($edit ? 'Edit Message' : 'Add Message').'</h2>';

    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="eum_mm_save">';
    wp_nonce_field('eum_mm_save', 'eum_mm_nonce');
    if ($edit) echo '<input type="hidden" name="post_id" value="'.(int)$edit->ID.'">';

    // Message rich text / code editor
    echo '<p><label><strong>Message</strong></label></p>';
    if (function_exists('wp_enqueue_editor')) {
      wp_enqueue_editor();
    }
    wp_editor(
      $message_html,
      'eum_mm_message_html',
      [
        'textarea_name' => 'message_html',
        'textarea_rows' => 10,
        'media_buttons' => false,
        'teeny' => false,
        'quicktags' => true,
        'tinymce' => [
          'wpautop' => true,
          'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo,removeformat,code',
          'toolbar2' => 'pastetext,charmap,outdent,indent,hr,forecolor',
        ],
      ]
    );

    // Colors (picker + text, synced)
    echo '<p style="margin-top:14px;"><label><strong>Text Color</strong> (Default White)</label><br>';
    echo '<input type="color" id="eum_mm_text_color" name="text_color" value="'.esc_attr($this->normalize_hex_color($text_color, '#ffffff')).'"> ';
    echo '<input type="text" id="eum_mm_text_color_text" name="text_color_text" value="'.esc_attr($text_color).'" class="regular-text" placeholder="#ffffff" style="max-width:180px;"></p>';

    echo '<p><label><strong>Banner Color</strong> (Default Red)</label><br>';
    echo '<input type="color" id="eum_mm_banner_color" name="banner_color" value="'.esc_attr($this->normalize_hex_color($banner_color, '#b91c1c')).'"> ';
    echo '<input type="text" id="eum_mm_banner_color_text" name="banner_color_text" value="'.esc_attr($banner_color).'" class="regular-text" placeholder="#b91c1c" style="max-width:180px;">';
    echo '<br><span class="description">Banner color cannot be grey.</span></p>';

    // Keep text inputs in sync with pickers (prevents saving stale *text* values)
    echo '<script>
      (function(){
        function sync(pickerId, textId){
          var p = document.getElementById(pickerId);
          var t = document.getElementById(textId);
          if(!p || !t) return;
          p.addEventListener("input", function(){ t.value = p.value; });
        }
        sync("eum_mm_text_color","eum_mm_text_color_text");
        sync("eum_mm_banner_color","eum_mm_banner_color_text");
      })();
    </script>';

    // Original author (immutable)
    $orig_user = get_user_by('id', $original_uid);
    $orig_label = $orig_user ? ($this->user_full_name($original_uid).' (ID '.$original_uid.')') : ('User ID '.$original_uid);
    echo '<p><label><strong>Original Author</strong> (locked)</label><br>';
    echo '<input type="text" class="regular-text" value="'.esc_attr($orig_label).'" readonly style="background:#f6f7f7;min-width:420px;"></p>';

    // Owner (editable by privileged only)
    if ($priv) {
      echo '<p><label><strong>Owner</strong> (controls who can manage this message)</label><br>';
      echo '<select name="owner_uid" required style="min-width:460px;">';
      foreach ($owner_user_options as $u) {
        $label = trim(($u->display_name ?: $u->user_login).' (ID '.$u->ID.')');
        echo '<option value="'.(int)$u->ID.'" '.selected($owner_uid, (int)$u->ID, false).'>'.esc_html($label).'</option>';
      }
      echo '</select>';
      echo '<br><span class="description">If owner changes, access shifts to the new owner and that owner’s manager chain.</span></p>';
    } else {
      echo '<input type="hidden" name="owner_uid" value="'.(int)$owner_uid.'">';
    }

    // Author name (text label; editable by HR/IT/admin)
    echo '<p><label><strong>Author Name</strong></label><br>';
    if ($priv) {
      echo '<input type="text" name="author_name" class="regular-text" value="'.esc_attr($author_name).'" style="min-width:420px;" placeholder="e.g., Management Team">';
      echo '<br><span class="description">Displayed on banner footer. Original Author remains unchanged.</span>';
    } else {
      echo '<input type="text" class="regular-text" value="'.esc_attr($author_name).'" readonly style="background:#f6f7f7;min-width:420px;">';
      echo '<input type="hidden" name="author_name" value="'.esc_attr($author_name).'">';
    }
    echo '</p>';

    // Effective/Expiration (privileged can bypass time restriction)
    echo '<p><label><strong>Effective Date/Time (UTC)</strong></label><br>';
    echo '<input type="datetime-local" name="effective" value="'.esc_attr($eff_str).'" required>';
    if (!$priv) {
      echo '<br><span class="description">Must be at least 5 minutes from now.</span>';
    } else {
      echo '<br><span class="description">HR &amp; IT can set any effective time.</span>';
    }
    echo '</p>';

    echo '<p><label><strong>Expiration Date (UTC)</strong></label><br>';
    echo '<input type="datetime-local" name="expires" value="'.esc_attr($exp_str).'" required>';
    echo '<br><span class="description">Default is 72 hours from now.</span></p>';

    // Effective Users dropdown
    echo '<p><label><strong>Effective Users</strong></label><br>';
    echo '<select name="effective_target" required style="min-width:460px;">';
    echo '<option value="">— Select —</option>';

    foreach ($allowed_targets as $opt) {
      $value = $opt['value'];
      $label = $opt['label'];
      $sel = ($value === $current_target_value) ? 'selected' : '';
      echo '<option value="'.esc_attr($value).'" '.$sel.'>'.esc_html($label).'</option>';
    }

    echo '</select>';
    echo '<br><span class="description">'.($priv
      ? 'HR & IT can target any Job Path, Group, or Project.'
      : 'Only shows Job Paths, Groups, and Projects you manage. Server re-verifies selection on save.'
    ).'</span></p>';

    // Dismissible (default checked; only HR/IT/admin can uncheck)
    $dismiss_checked = ($dismissible === '1');
    echo '<p><label><strong>Dismissible</strong></label><br>';
    if ($priv) {
      echo '<label><input type="checkbox" name="dismissible" value="1" '.checked($dismiss_checked, true, false).'> Dismissible (dismisses one-time only)</label>';
    } else {
      echo '<label><input type="checkbox" checked disabled> Dismissible (one-time only) — Only HR &amp; IT can disable</label>';
      echo '<input type="hidden" name="dismissible" value="1">';
    }
    echo '</p>';

    // Notes (backend-only)
    echo '<p><label><strong>Notes</strong> (backend only)</label><br>';
    echo '<textarea name="notes" rows="3" class="large-text" placeholder="Internal notes (not shown on site)">'.esc_textarea($notes).'</textarea>';
    echo '<br><span class="description">Visible only to users with access to this tool.</span></p>';

    echo '<p style="margin-top:14px;">';
    echo '<button type="submit" class="button button-primary">'.($edit ? 'Update Message' : 'Add Message').'</button> ';
    if ($edit) echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=eum-manager-messages')).'">Cancel</a>';
    echo '</p>';

    echo '</form>';
    echo '</div>';
    echo '</div>';

    // List
    echo '<div style="flex:1; min-width:680px;">';
    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin:0 0 12px 0;">Messages You Can Manage</h2>';

    $manageable_ids = $this->get_manageable_message_ids($uid);

    if (empty($manageable_ids)) {
      echo '<p>No messages yet.</p>';
    } else {
      $posts = get_posts([
        'post_type' => self::CPT,
        'post_status' => 'publish',
        'numberposts' => 2000,
        'post__in' => $manageable_ids,
        'orderby' => 'date',
        'order' => 'DESC',
      ]);

      echo '<table class="widefat striped">';
      echo '<thead><tr><th>Message</th><th>Effective Users</th><th>Effective</th><th>Expires</th><th>Owner</th><th>Author Name</th><th style="width:170px;">Actions</th></tr></thead><tbody>';

      foreach ($posts as $p) {
        $pid = (int)$p->ID;
        $t_type = (string)get_post_meta($pid, self::META_TARGET_TYPE, true);
        $t_id   = (int)get_post_meta($pid, self::META_TARGET_ID, true);
        $eff    = (int)get_post_meta($pid, self::META_EFFECTIVE_TS, true);
        $exp    = (int)get_post_meta($pid, self::META_EXPIRES_TS, true);

        $owner  = (int)get_post_meta($pid, self::META_OWNER_UID, true);
        $owner_u = $owner ? get_user_by('id', $owner) : null;
        $owner_label = $owner_u ? ($this->user_full_name($owner).' (ID '.$owner.')') : ('User ID '.$owner);

        $auth_name = (string)get_post_meta($pid, self::META_AUTHOR_NAME, true);

        $label = $this->format_target_label($t_type, $t_id);
        $edit_url = esc_url(admin_url('admin.php?page=eum-manager-messages&edit='.$pid));

        $plain = wp_strip_all_tags((string)get_post_meta($pid, self::META_MESSAGE_HTML, true));
        echo '<tr>';
        echo '<td>'.esc_html(wp_trim_words($plain, 14)).'</td>';
        echo '<td>'.esc_html($label).'</td>';
        echo '<td>'.esc_html($eff ? gmdate('Y-m-d H:i', $eff).' UTC' : '—').'</td>';
        echo '<td>'.esc_html($exp ? gmdate('Y-m-d H:i', $exp).' UTC' : '—').'</td>';
        echo '<td>'.esc_html($owner_label).'</td>';
        echo '<td>'.esc_html($auth_name ?: '—').'</td>';
        echo '<td>';
        echo '<a class="button button-small" href="'.$edit_url.'">Edit</a> ';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline;">';
        echo '<input type="hidden" name="action" value="eum_mm_delete">';
        wp_nonce_field('eum_mm_delete', 'eum_mm_nonce');
        echo '<input type="hidden" name="post_id" value="'.$pid.'">';
        echo '<button class="button button-small" type="submit" onclick="return confirm(\'Delete this message?\')">Delete</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
      }

      echo '</tbody></table>';
    }

    echo '</div>';
    echo '</div>';

    echo '</div>'; // flex
    echo '</div>'; // wrap
  }

  // =============================================================================
  // Save/Delete
  // =============================================================================


  public function render_frontend_manager_ui(): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-manager-messages-ui"><p>You must be logged in to manage messages.</p></div>';
    }

    if (!$this->can_access_tool()) {
      return '<div class="eum-manager-messages-ui"><p>You do not have permission to manage messages.</p></div>';
    }

    $uid = get_current_user_id();
    $priv = $this->is_privileged($uid);

    $edit_id = isset($_GET['eum_mm_edit']) ? intval($_GET['eum_mm_edit']) : 0;
    $edit = $edit_id ? get_post($edit_id) : null;
    if ($edit && $edit->post_type !== self::CPT) $edit = null;
    if ($edit && !$this->can_manage_message($uid, (int)$edit->ID)) $edit = null;

    $now = time();
    $default_eff = $now + 300;
    $default_exp = $now + 72 * 3600;

    $message_html = $edit ? (string)get_post_meta($edit->ID, self::META_MESSAGE_HTML, true) : '';
    $text_color   = $edit ? (string)get_post_meta($edit->ID, self::META_TEXT_COLOR, true) : '#ffffff';
    $banner_color = $edit ? (string)get_post_meta($edit->ID, self::META_BANNER_COLOR, true) : '#b91c1c';
    $owner_uid    = $edit ? (int)get_post_meta($edit->ID, self::META_OWNER_UID, true) : $uid;
    $author_name  = $edit ? (string)get_post_meta($edit->ID, self::META_AUTHOR_NAME, true) : $this->user_full_name($uid);
    $eff_ts       = $edit ? (int)get_post_meta($edit->ID, self::META_EFFECTIVE_TS, true) : $default_eff;
    $exp_ts       = $edit ? (int)get_post_meta($edit->ID, self::META_EXPIRES_TS, true) : $default_exp;
    $dismissible  = $edit ? (string)get_post_meta($edit->ID, self::META_DISMISSIBLE, true) : '1';
    $notes        = $edit ? (string)get_post_meta($edit->ID, self::META_NOTES, true) : '';
    $target_type  = $edit ? (string)get_post_meta($edit->ID, self::META_TARGET_TYPE, true) : 'jobpath';
    $target_id    = $edit ? (int)get_post_meta($edit->ID, self::META_TARGET_ID, true) : 0;

    $allowed_targets = $priv ? $this->get_all_targets() : $this->get_allowed_targets_for_user($uid);
    $current_target_value = ($target_type && $target_id) ? ($target_type.':'.$target_id) : '';
    if ($current_target_value !== '' && !$this->value_in_targets($current_target_value, $allowed_targets)) {
      $allowed_targets = array_merge(
        [['value' => $current_target_value, 'label' => '(Current) '.$this->format_target_label($target_type, $target_id)]],
        $allowed_targets
      );
    }

    $owner_user_options = [];
    if ($priv) {
      $owner_user_options = get_users([
        'fields' => ['ID', 'display_name', 'user_login'],
        'number' => 2000,
        'orderby' => 'display_name',
        'order' => 'ASC',
      ]);
      if (!is_array($owner_user_options)) $owner_user_options = [];
    }

    ob_start();
    echo '<div class="eum-manager-messages-ui">';
    if (!empty($_GET['eum_msg'])) {
      $msg  = sanitize_text_field((string)$_GET['eum_msg']);
      $type = !empty($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
      $bg = ($type === 'error') ? '#fef2f2' : '#f0fdf4';
      $bd = ($type === 'error') ? '#fecaca' : '#bbf7d0';
      echo '<div style="padding:12px 14px;margin:0 0 16px 0;border:1px solid '.esc_attr($bd).';background:'.esc_attr($bg).';border-radius:6px;">'.esc_html($msg).'</div>';
    }

    echo '<div style="display:grid;grid-template-columns:minmax(320px,1fr);gap:18px;">';
    echo '<div style="border:1px solid #dcdcde;background:#fff;padding:18px;border-radius:6px;">';
    echo '<h3 style="margin-top:0;">'.($edit ? 'Edit Message' : 'New Manager Message').'</h3>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="eum_mm_save">';
    wp_nonce_field('eum_mm_save', 'eum_mm_nonce');
    if ($edit) echo '<input type="hidden" name="post_id" value="'.(int)$edit->ID.'">';

    echo '<p><label><strong>Message</strong></label></p>';
    if (function_exists('wp_enqueue_editor')) {
      wp_enqueue_editor();
    }
    wp_editor(
      $message_html,
      'eum_mm_message_html_front',
      [
        'textarea_name' => 'message_html',
        'textarea_rows' => 10,
        'media_buttons' => false,
        'teeny' => false,
        'quicktags' => true,
        'tinymce' => [
          'wpautop' => true,
          'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo,removeformat,code',
          'toolbar2' => 'pastetext,charmap,outdent,indent,hr,forecolor',
        ],
      ]
    );

    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
    echo '<p><label><strong>Text Color</strong></label><br><input type="color" name="text_color" value="'.esc_attr($this->normalize_hex_color($text_color, '#ffffff')).'"><input type="hidden" name="text_color_text" value="'.esc_attr($text_color).'"></p>';
    echo '<p><label><strong>Banner Color</strong></label><br><input type="color" name="banner_color" value="'.esc_attr($this->normalize_hex_color($banner_color, '#b91c1c')).'"><input type="hidden" name="banner_color_text" value="'.esc_attr($banner_color).'"></p>';
    echo '</div>';

    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
    echo '<p><label><strong>Effective</strong></label><br><input type="datetime-local" name="effective" value="'.esc_attr(gmdate('Y-m-d\TH:i', $eff_ts)).'"></p>';
    echo '<p><label><strong>Expires</strong></label><br><input type="datetime-local" name="expires" value="'.esc_attr(gmdate('Y-m-d\TH:i', $exp_ts)).'"></p>';
    echo '</div>';

    echo '<p><label><strong>Target</strong></label><br><select name="effective_target" style="min-width:320px;max-width:100%;">';
    echo '<option value="">— Select —</option>';
    foreach ($allowed_targets as $target) {
      $value = (string)($target['value'] ?? '');
      $label = (string)($target['label'] ?? '');
      if ($value === '' || $label === '') continue;
      echo '<option value="'.esc_attr($value).'" '.selected($current_target_value, $value, false).'>'.esc_html($label).'</option>';
    }
    echo '</select></p>';

    if ($priv) {
      echo '<p><label><strong>Owner</strong></label><br><select name="owner_uid" style="min-width:320px;max-width:100%;">';
      foreach ($owner_user_options as $u) {
        $label = $u->display_name ? $u->display_name : $u->user_login;
        echo '<option value="'.(int)$u->ID.'" '.selected($owner_uid, (int)$u->ID, false).'>'.esc_html($label.' (#'.(int)$u->ID.')').'</option>';
      }
      echo '</select></p>';

      echo '<p><label><strong>Author Label</strong></label><br><input type="text" name="author_name" value="'.esc_attr($author_name).'" style="width:100%;max-width:420px;"></p>';
      echo '<p><label><input type="checkbox" name="dismissible" value="1" '.checked($dismissible !== '0', true, false).'> Dismissible</label></p>';
      echo '<p><label><strong>Notes</strong></label><br><textarea name="notes" rows="3" style="width:100%;">'.esc_textarea($notes).'</textarea></p>';
    } else {
      echo '<input type="hidden" name="dismissible" value="1">';
    }

    echo '<p style="display:flex;gap:8px;flex-wrap:wrap;">';
    echo '<button type="submit">'.($edit ? 'Update Message' : 'Create Message').'</button>';
    if ($edit) {
      $cancel_url = remove_query_arg(['eum_mm_edit', 'eum_msg', 'eum_type']);
      echo '<a href="'.esc_url($cancel_url).'" style="display:inline-block;padding:8px 12px;border:1px solid #ccd0d4;text-decoration:none;background:#fff;">Cancel</a>';
    }
    echo '</p>';
    echo '</form>';
    echo '</div>';

    $message_ids = $this->get_manageable_message_ids($uid);
    echo '<div style="border:1px solid #dcdcde;background:#fff;padding:18px;border-radius:6px;">';
    echo '<h3 style="margin-top:0;">Manage Existing Messages</h3>';
    if (empty($message_ids)) {
      echo '<p>No messages available.</p>';
    } else {
      echo '<table style="width:100%;border-collapse:collapse;">';
      echo '<thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #dcdcde;">Message</th><th style="text-align:left;padding:8px;border-bottom:1px solid #dcdcde;">Target</th><th style="text-align:left;padding:8px;border-bottom:1px solid #dcdcde;">Window</th><th style="text-align:left;padding:8px;border-bottom:1px solid #dcdcde;">Actions</th></tr></thead><tbody>';
      foreach ($message_ids as $message_id) {
        $post = get_post($message_id);
        if (!$post) continue;
        $type = (string)get_post_meta($message_id, self::META_TARGET_TYPE, true);
        $tid = (int)get_post_meta($message_id, self::META_TARGET_ID, true);
        $eff = (int)get_post_meta($message_id, self::META_EFFECTIVE_TS, true);
        $exp = (int)get_post_meta($message_id, self::META_EXPIRES_TS, true);
        $edit_url = add_query_arg(['eum_tab' => 'messages', 'eum_mm_edit' => $message_id], remove_query_arg(['eum_msg', 'eum_type', 'eum_mm_edit']));
        echo '<tr>';
        echo '<td style="padding:8px;border-bottom:1px solid #f0f0f1;">'.esc_html($post->post_title).'</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #f0f0f1;">'.esc_html($this->format_target_label($type, $tid)).'</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #f0f0f1;">'.esc_html(gmdate('Y-m-d H:i', $eff)).' → '.esc_html(gmdate('Y-m-d H:i', $exp)).' UTC</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #f0f0f1;white-space:nowrap;">';
        echo '<a href="'.esc_url($edit_url).'">Edit</a> ';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline;">';
        echo '<input type="hidden" name="action" value="eum_mm_delete">';
        wp_nonce_field('eum_mm_delete', 'eum_mm_nonce');
        echo '<input type="hidden" name="post_id" value="'.(int)$message_id.'">';
        echo '<button type="submit" onclick="return confirm(\'Delete this message?\')">Delete</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
    return ob_get_clean();
  }

  public function handle_save(): void {
    if (!$this->can_access_tool()) wp_die('Not allowed.');
    if (!isset($_POST['eum_mm_nonce']) || !wp_verify_nonce($_POST['eum_mm_nonce'], 'eum_mm_save')) wp_die('Bad nonce.');

    $uid = get_current_user_id();
    $priv = $this->is_privileged($uid);

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $editing = false;

    if ($post_id) {
      $p = get_post($post_id);
      if (!$p || $p->post_type !== self::CPT) $this->redirect('Invalid message.', 'error');
      if (!$this->can_manage_message($uid, $post_id)) $this->redirect('Not allowed to edit this message.', 'error');
      $editing = true;
    }

    // Message (rich text)
    $raw_html = isset($_POST['message_html']) ? (string)$_POST['message_html'] : '';
    $raw_html = wp_unslash($raw_html);
    $message_html = wp_kses_post($raw_html);
    if (trim(wp_strip_all_tags($message_html)) === '') $this->redirect('Message is required.', 'error');

    // Colors
    // FIX: Prefer the picker values (always accurate), fallback to text inputs.
    $text_color_picker = isset($_POST['text_color']) ? sanitize_text_field((string)$_POST['text_color']) : '';
    $text_color_text   = isset($_POST['text_color_text']) ? sanitize_text_field((string)$_POST['text_color_text']) : '';
    $text_color = trim($text_color_picker) !== '' ? $text_color_picker : ($text_color_text !== '' ? $text_color_text : '#ffffff');

    $banner_color_picker = isset($_POST['banner_color']) ? sanitize_text_field((string)$_POST['banner_color']) : '';
    $banner_color_text   = isset($_POST['banner_color_text']) ? sanitize_text_field((string)$_POST['banner_color_text']) : '';
    $banner_color = trim($banner_color_picker) !== '' ? $banner_color_picker : ($banner_color_text !== '' ? $banner_color_text : '#b91c1c');

    if ($this->is_grey_color($banner_color)) $this->redirect('Banner color cannot be grey.', 'error');

    // Owner / Original author
    $now = time();

    $original_author_uid = $editing
      ? (int)get_post_meta($post_id, self::META_ORIGINAL_AUTHOR_UID, true)
      : $uid;

    if ($original_author_uid <= 0) $original_author_uid = $uid; // fallback

    $owner_uid = $editing
      ? (int)get_post_meta($post_id, self::META_OWNER_UID, true)
      : $uid;

    if ($owner_uid <= 0) $owner_uid = $uid;

    if ($priv) {
      $posted_owner = isset($_POST['owner_uid']) ? intval($_POST['owner_uid']) : $owner_uid;
      if ($posted_owner > 0 && get_user_by('id', $posted_owner)) {
        $owner_uid = $posted_owner;
      }
    }

    // Author name (text label)
    $author_name = $editing
      ? (string)get_post_meta($post_id, self::META_AUTHOR_NAME, true)
      : $this->user_full_name($uid);

    if ($priv) {
      $posted_author_name = isset($_POST['author_name']) ? sanitize_text_field((string)$_POST['author_name']) : '';
      $posted_author_name = trim($posted_author_name);
      if ($posted_author_name !== '') $author_name = $posted_author_name;
    } else {
      // non-privileged: keep existing/derived
      if (!$editing) {
        $author_name = $this->user_full_name($uid);
      }
    }

    // Time (HR/IT bypass)
    $eff_raw = isset($_POST['effective']) ? sanitize_text_field((string)$_POST['effective']) : '';
    $exp_raw = isset($_POST['expires']) ? sanitize_text_field((string)$_POST['expires']) : '';

    $eff_ts = $this->datetime_local_to_ts($eff_raw);
    $exp_ts = $this->datetime_local_to_ts($exp_raw);

    if ($eff_ts <= 0) $eff_ts = $now + 300;
    if ($exp_ts <= 0) $exp_ts = $now + 72 * 3600;

    if (!$priv) {
      if ($eff_ts < ($now + 300)) $this->redirect('Effective time must be at least 5 minutes from now.', 'error');
    }
    if ($exp_ts <= $eff_ts) $this->redirect('Expiration must be after effective time.', 'error');

    // Target
    $target = isset($_POST['effective_target']) ? sanitize_text_field((string)$_POST['effective_target']) : '';
    if ($target === '' || strpos($target, ':') === false) $this->redirect('Effective Users is required.', 'error');

    [$target_type, $target_id_str] = array_map('trim', explode(':', $target, 2));
    $target_type = sanitize_key($target_type);
    $target_id = intval($target_id_str);

    if (!in_array($target_type, ['jobpath','group','project'], true) || $target_id <= 0) {
      $this->redirect('Invalid Effective Users selection.', 'error');
    }

    if (!$priv) {
      if (!$this->is_target_allowed_for_user($uid, $target_type, $target_id)) {
        $this->redirect('You are not authorized to target that selection.', 'error');
      }
    }

    // Dismissible
    $dismissible = '1';
    if ($priv) {
      $dismissible = isset($_POST['dismissible']) ? '1' : '0';
    } else {
      $dismissible = '1';
    }

    // Notes (backend-only, visible to tool users)
    $notes = isset($_POST['notes']) ? sanitize_textarea_field((string)$_POST['notes']) : '';

    // Save post
    $title = wp_trim_words(trim(wp_strip_all_tags($message_html)), 8, '');

    if ($editing) {
      wp_update_post(['ID' => $post_id, 'post_title' => $title]);
    } else {
      $post_id = (int)wp_insert_post([
        'post_type' => self::CPT,
        'post_status' => 'publish',
        'post_title' => $title,
      ]);
      if (!$post_id) $this->redirect('Failed to create message.', 'error');
    }

    update_post_meta($post_id, self::META_MESSAGE_HTML, $message_html);
    update_post_meta($post_id, self::META_TEXT_COLOR, $text_color);
    update_post_meta($post_id, self::META_BANNER_COLOR, $banner_color);
    update_post_meta($post_id, self::META_AUTHOR_NAME, $author_name);

    update_post_meta($post_id, self::META_EFFECTIVE_TS, $eff_ts);
    update_post_meta($post_id, self::META_EXPIRES_TS, $exp_ts);

    update_post_meta($post_id, self::META_TARGET_TYPE, $target_type);
    update_post_meta($post_id, self::META_TARGET_ID, $target_id);

    update_post_meta($post_id, self::META_DISMISSIBLE, $dismissible);
    update_post_meta($post_id, self::META_UPDATED_TS, time());

    // Owner + Original author
    update_post_meta($post_id, self::META_OWNER_UID, $owner_uid);

    if (!$editing || !get_post_meta($post_id, self::META_ORIGINAL_AUTHOR_UID, true)) {
      update_post_meta($post_id, self::META_ORIGINAL_AUTHOR_UID, $original_author_uid);
    } else {
      // enforce immutability
      update_post_meta($post_id, self::META_ORIGINAL_AUTHOR_UID, (int)get_post_meta($post_id, self::META_ORIGINAL_AUTHOR_UID, true));
    }

    update_post_meta($post_id, self::META_NOTES, $notes);

    $this->redirect('Saved.');
  }

  public function handle_delete(): void {
    if (!$this->can_access_tool()) wp_die('Not allowed.');
    if (!isset($_POST['eum_mm_nonce']) || !wp_verify_nonce($_POST['eum_mm_nonce'], 'eum_mm_delete')) wp_die('Bad nonce.');

    $uid = get_current_user_id();
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) $this->redirect('Missing id.', 'error');

    $p = get_post($post_id);
    if (!$p || $p->post_type !== self::CPT) $this->redirect('Invalid message.', 'error');
    if (!$this->can_manage_message($uid, $post_id)) $this->redirect('Not allowed.', 'error');

    wp_delete_post($post_id, true);
    $this->redirect('Deleted.');
  }

  private function redirect(string $msg, string $type = 'success'): void {
    $fallback = home_url('/');
    $ref = wp_get_referer();
    $base = $ref ? remove_query_arg(['eum_msg','eum_type'], $ref) : $fallback;
    $url = add_query_arg(['eum_msg' => $msg, 'eum_type' => $type], $base);
    wp_safe_redirect($url);
    exit;
  }

  // =============================================================================
  // Access / Permissions
  // =============================================================================

  private function can_access_tool(): bool {
    $uid = get_current_user_id();
    if ($this->is_privileged($uid)) return true;
    return $this->current_user_has_any_manager_role();
  }

  private function current_user_has_any_manager_role(): bool {
    if (!class_exists('EUM_Manager_IDs')) return false;
    if (!defined('EUM_Manager_IDs::CPT') || !defined('EUM_Manager_IDs::META_MANAGER_USERS')) return false;

    $uid = get_current_user_id();
    if ($uid <= 0) return false;

    $mgr_posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 5000,
      'fields' => 'ids',
    ]);

    foreach ((array)$mgr_posts as $mid) {
      $ids = (array)get_post_meta((int)$mid, EUM_Manager_IDs::META_MANAGER_USERS, true);
      $ids = array_values(array_filter(array_map('intval', $ids)));
      if (in_array($uid, $ids, true)) return true;
    }
    return false;
  }

  private function get_manageable_message_ids(int $user_id): array {
    if ($this->is_privileged($user_id)) {
      $all = get_posts(['post_type'=>self::CPT,'post_status'=>'publish','numberposts'=>5000,'fields'=>'ids']);
      return array_map('intval', (array)$all);
    }

    $all_ids = get_posts(['post_type'=>self::CPT,'post_status'=>'publish','numberposts'=>5000,'fields'=>'ids']);
    $out = [];
    foreach ((array)$all_ids as $pid) {
      $pid = (int)$pid;
      if ($this->can_manage_message($user_id, $pid)) $out[] = $pid;
    }
    return $out;
  }

  /**
   * Management rule:
   * - HR/IT/Admin: full access
   * - Otherwise: you can manage if:
   *   A) you are the message owner, OR
   *   B) you are in the OWNER’s manager chain (owner’s effective manager + ancestors), OR
   *   C) (fallback) you can target the message selection (kept to avoid lockouts)
   *
   * When owner changes, B) recalculates automatically, shifting access to new owner chain.
   */
  private function can_manage_message(int $user_id, int $message_post_id): bool {
    if ($this->is_privileged($user_id)) return true;

    $owner_uid = (int)get_post_meta($message_post_id, self::META_OWNER_UID, true);
    if ($owner_uid <= 0) $owner_uid = (int)get_post_meta($message_post_id, self::META_ORIGINAL_AUTHOR_UID, true);

    if ($owner_uid > 0 && $user_id === $owner_uid) return true;

    if ($owner_uid > 0 && $this->user_is_in_owner_chain($user_id, $owner_uid)) return true;

    // fallback: allow managing if they can target that selection (prevents edge-case lockouts)
    $type = (string)get_post_meta($message_post_id, self::META_TARGET_TYPE, true);
    $tid  = (int)get_post_meta($message_post_id, self::META_TARGET_ID, true);
    if (in_array($type, ['jobpath','group','project'], true) && $tid > 0) {
      return $this->is_target_allowed_for_user($user_id, $type, $tid);
    }

    return false;
  }

  private function user_is_in_owner_chain(int $user_id, int $owner_uid): bool {
    if ($user_id <= 0 || $owner_uid <= 0) return false;
    if (!class_exists('EUM_Manager_IDs')) return false;

    $mgr = new EUM_Manager_IDs();
    if (!method_exists($mgr, 'resolve_effective_manager_id_for_user')) return false;

    $owner_mid = (int)$mgr->resolve_effective_manager_id_for_user($owner_uid);
    if ($owner_mid <= 0) return false;

    // If editor is direct manager of owner's effective manager id
    if ($this->user_is_manager_for_manager_id($user_id, $owner_mid)) return true;

    // Ancestor chain by job term ancestry
    $owner_term = $this->get_manager_job_term_id($owner_mid);
    if ($owner_term <= 0) return false;

    $my_manager_ids = $this->get_manager_ids_for_user($user_id);
    foreach ($my_manager_ids as $my_mid) {
      $my_term = $this->get_manager_job_term_id($my_mid);
      if ($my_term <= 0) continue;

      // If my_term is ancestor of owner_term, I’m “above” the owner’s manager chain
      $anc = get_ancestors($owner_term, 'eum_job_path');
      $anc = array_map('intval', (array)$anc);
      if ($my_term === $owner_term || in_array($my_term, $anc, true)) return true;
    }

    return false;
  }

  private function user_is_manager_for_manager_id(int $user_id, int $manager_post_id): bool {
    if (!class_exists('EUM_Manager_IDs')) return false;
    if (!defined('EUM_Manager_IDs::META_MANAGER_USERS')) return false;

    $ids = (array)get_post_meta($manager_post_id, EUM_Manager_IDs::META_MANAGER_USERS, true);
    $ids = array_values(array_filter(array_map('intval', $ids)));
    return in_array($user_id, $ids, true);
  }

  // =============================================================================
  // Allowed Effective Users (STRICT — “verify HARD” for non-privileged)
  // =============================================================================

  private function get_allowed_targets_for_user(int $user_id): array {
    $targets = [];

    $managed_job_terms = $this->get_job_path_terms_managed_by_user($user_id);
    $job_options = $this->expand_job_terms_to_all_selectable_nodes($managed_job_terms);

    foreach ($job_options as $term_id) {
      $label = $this->term_full_path('eum_job_path', $term_id, ' → ');
      if ($label !== '') {
        $targets[] = ['value' => 'jobpath:'.$term_id, 'label' => 'Job Path: '.$label];
      }
    }

    $my_manager_ids = $this->get_manager_ids_for_user($user_id);

    if (!empty($my_manager_ids)) {
      if (taxonomy_exists('eum_user_group')) {
        $terms = get_terms(['taxonomy'=>'eum_user_group','hide_empty'=>false,'number'=>0]);
        if (!is_wp_error($terms)) {
          foreach ($terms as $t) {
            $mgrs = (array)get_term_meta((int)$t->term_id, self::TERM_META_MANAGER_IDS, true);
            $mgrs = array_values(array_filter(array_map('intval', $mgrs)));
            if (!empty(array_intersect($mgrs, $my_manager_ids))) {
              $targets[] = ['value' => 'group:'.(int)$t->term_id, 'label' => 'Group: '.$t->name];
            }
          }
        }
      }

      if (taxonomy_exists('eum_project')) {
        $terms = get_terms(['taxonomy'=>'eum_project','hide_empty'=>false,'number'=>0]);
        if (!is_wp_error($terms)) {
          foreach ($terms as $t) {
            $mgrs = (array)get_term_meta((int)$t->term_id, self::TERM_META_MANAGER_IDS, true);
            $mgrs = array_values(array_filter(array_map('intval', $mgrs)));
            if (!empty(array_intersect($mgrs, $my_manager_ids))) {
              $targets[] = ['value' => 'project:'.(int)$t->term_id, 'label' => 'Project: '.$t->name];
            }
          }
        }
      }
    }

    // De-dupe + sort
    $seen = [];
    $final = [];
    foreach ($targets as $t) {
      if (isset($seen[$t['value']])) continue;
      $seen[$t['value']] = true;
      $final[] = $t;
    }

    usort($final, function($a, $b){
      $pa = (strpos($a['value'], 'jobpath:') === 0) ? 0 : ((strpos($a['value'], 'group:') === 0) ? 1 : 2);
      $pb = (strpos($b['value'], 'jobpath:') === 0) ? 0 : ((strpos($b['value'], 'group:') === 0) ? 1 : 2);
      if ($pa !== $pb) return $pa <=> $pb;
      return strcasecmp($a['label'], $b['label']);
    });

    return $final;
  }

  private function is_target_allowed_for_user(int $user_id, string $target_type, int $target_id): bool {
    $allowed = $this->get_allowed_targets_for_user($user_id);
    $needle = $target_type.':'.$target_id;
    foreach ($allowed as $opt) {
      if ($opt['value'] === $needle) return true;
    }
    return false;
  }

  private function value_in_targets(string $value, array $targets): bool {
    foreach ($targets as $t) {
      if (!empty($t['value']) && $t['value'] === $value) return true;
    }
    return false;
  }

  private function get_all_targets(): array {
    $out = [];

    if (taxonomy_exists('eum_job_path')) {
      $terms = get_terms(['taxonomy'=>'eum_job_path','hide_empty'=>false,'number'=>0]);
      if (!is_wp_error($terms)) {
        foreach ($terms as $t) {
          $label = $this->term_full_path('eum_job_path', (int)$t->term_id, ' → ');
          if ($label !== '') $out[] = ['value'=>'jobpath:'.(int)$t->term_id, 'label'=>'Job Path: '.$label];
        }
      }
    }

    if (taxonomy_exists('eum_user_group')) {
      $terms = get_terms(['taxonomy'=>'eum_user_group','hide_empty'=>false,'number'=>0]);
      if (!is_wp_error($terms)) {
        foreach ($terms as $t) $out[] = ['value'=>'group:'.(int)$t->term_id, 'label'=>'Group: '.$t->name];
      }
    }

    if (taxonomy_exists('eum_project')) {
      $terms = get_terms(['taxonomy'=>'eum_project','hide_empty'=>false,'number'=>0]);
      if (!is_wp_error($terms)) {
        foreach ($terms as $t) $out[] = ['value'=>'project:'.(int)$t->term_id, 'label'=>'Project: '.$t->name];
      }
    }

    return $out;
  }

  // =============================================================================
  // Manager IDs helpers (job term mapping + manager ids for user)
  // =============================================================================

  private function get_job_path_terms_managed_by_user(int $user_id): array {
    $terms = [];
    $my_manager_ids = $this->get_manager_ids_for_user($user_id);
    if (empty($my_manager_ids)) return [];

    foreach ($my_manager_ids as $mid) {
      $term_id = $this->get_manager_job_term_id($mid);
      if ($term_id > 0) $terms[] = $term_id;
    }

    return array_values(array_unique(array_filter(array_map('intval', $terms))));
  }

  private function expand_job_terms_to_all_selectable_nodes(array $root_terms): array {
    if (!taxonomy_exists('eum_job_path')) return [];
    $root_terms = array_values(array_unique(array_filter(array_map('intval', $root_terms))));
    if (empty($root_terms)) return [];

    $all = [];
    foreach ($root_terms as $root) {
      $all[] = $root;

      $desc = get_terms([
        'taxonomy' => 'eum_job_path',
        'hide_empty' => false,
        'child_of' => $root,
        'fields' => 'ids',
        'number' => 0,
      ]);
      if (!is_wp_error($desc)) {
        foreach ((array)$desc as $id) $all[] = (int)$id;
      }
    }

    $all = array_values(array_unique(array_filter(array_map('intval', $all))));
    sort($all);
    return $all;
  }

  private function get_manager_ids_for_user(int $user_id): array {
    $out = [];
    if (!class_exists('EUM_Manager_IDs')) return $out;
    if (!defined('EUM_Manager_IDs::CPT') || !defined('EUM_Manager_IDs::META_MANAGER_USERS')) return $out;

    $mgr_posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 5000,
      'fields' => 'ids',
    ]);

    foreach ((array)$mgr_posts as $mid) {
      $ids = (array)get_post_meta((int)$mid, EUM_Manager_IDs::META_MANAGER_USERS, true);
      $ids = array_values(array_filter(array_map('intval', $ids)));
      if (in_array($user_id, $ids, true)) $out[] = (int)$mid;
    }
    return $out;
  }

  private function get_manager_job_term_id(int $manager_post_id): int {
    $keys = [
      (defined('EUM_Manager_IDs::META_JOB_TERM') ? EUM_Manager_IDs::META_JOB_TERM : null),
      'eum_manager_job_term',
      'eum_manager_job_path_term_id',
      'eum_manager_term_id',
    ];
    foreach ($keys as $k) {
      if (!$k) continue;
      $v = (int)get_post_meta($manager_post_id, $k, true);
      if ($v > 0) return $v;
    }
    return 0;
  }

  // =============================================================================
  // Projects/Groups term manager IDs UI
  // =============================================================================

  public function term_add_fields_project(): void { $this->term_add_fields('eum_project'); }
  public function term_edit_fields_project($term): void { $this->term_edit_fields('eum_project', $term); }
  public function term_add_fields_group(): void { $this->term_add_fields('eum_user_group'); }
  public function term_edit_fields_group($term): void { $this->term_edit_fields('eum_user_group', $term); }

  private function term_add_fields(string $tax): void {
    if (!class_exists('EUM_Manager_IDs') || !defined('EUM_Manager_IDs::CPT')) return;
    $mids = get_posts(['post_type'=>EUM_Manager_IDs::CPT,'post_status'=>'publish','numberposts'=>5000]);
    if (!is_array($mids)) $mids = [];

    echo '<div class="form-field term-group">';
    echo '<label>Manager IDs</label>';
    echo '<select name="eum_term_manager_ids[]" multiple size="6" style="min-width:320px;">';
    foreach ($mids as $p) {
      echo '<option value="'.(int)$p->ID.'">'.esc_html($p->post_title).'</option>';
    }
    echo '</select>';
    echo '<p class="description">Assign Manager IDs that manage this term (project/group).</p>';
    echo '</div>';
  }

  private function term_edit_fields(string $tax, $term): void {
    if (!class_exists('EUM_Manager_IDs') || !defined('EUM_Manager_IDs::CPT')) return;
    $term_id = (int)$term->term_id;

    $selected = (array)get_term_meta($term_id, self::TERM_META_MANAGER_IDS, true);
    $selected = array_values(array_filter(array_map('intval', $selected)));

    $mids = get_posts(['post_type'=>EUM_Manager_IDs::CPT,'post_status'=>'publish','numberposts'=>5000]);
    if (!is_array($mids)) $mids = [];

    echo '<tr class="form-field term-group-wrap">';
    echo '<th scope="row"><label>Manager IDs</label></th>';
    echo '<td>';
    echo '<select name="eum_term_manager_ids[]" multiple size="8" style="min-width:360px;">';
    foreach ($mids as $p) {
      $sel = in_array((int)$p->ID, $selected, true) ? 'selected' : '';
      echo '<option value="'.(int)$p->ID.'" '.$sel.'>'.esc_html($p->post_title).'</option>';
    }
    echo '</select>';
    echo '<p class="description">Assign Manager IDs that manage this '.$tax.'.</p>';
    echo '</td>';
    echo '</tr>';
  }

  public function term_save_fields(int $term_id, int $tt_id): void {
    if (!isset($_POST['eum_term_manager_ids'])) {
      delete_term_meta($term_id, self::TERM_META_MANAGER_IDS);
      return;
    }
    $vals = array_values(array_filter(array_map('intval', (array)$_POST['eum_term_manager_ids'])));
    update_term_meta($term_id, self::TERM_META_MANAGER_IDS, $vals);
  }

  // =============================================================================
  // Utility helpers
  // =============================================================================

  private function user_full_name(int $user_id): string {
    $u = get_user_by('id', $user_id);
    if (!$u) return '—';
    $first = (string)get_user_meta($user_id, 'first_name', true);
    $last  = (string)get_user_meta($user_id, 'last_name', true);
    $name = trim($first.' '.$last);
    if ($name !== '') return $name;
    return (string)($u->display_name ?: $u->user_login);
  }

  private function datetime_local_to_ts(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    $ts = strtotime($val.' UTC');
    return $ts ? (int)$ts : 0;
  }

  private function normalize_hex_color(string $val, string $fallback): string {
    $v = trim($val);
    if (preg_match('/^\#([0-9a-fA-F]{6})$/', $v)) return strtolower($v);
    return $fallback;
  }

  private function is_grey_color(string $c): bool {
    $c = strtolower(trim($c));
    if ($c === '') return false;

    $grey_words = ['grey','gray','gainsboro','silver','darkgray','darkgrey','dimgray','dimgrey','slategray','slategrey','lightgray','lightgrey'];
    if (in_array($c, $grey_words, true)) return true;

    $blocked = ['#808080','#888888','#999999','#aaaaaa','#bbbbbb','#cccccc','#dddddd','#eeeeee','#777777','#666666','#555555'];
    if (in_array($c, $blocked, true)) return true;

    if (preg_match('/^\#([0-9a-f]{6})$/', $c, $m)) {
      $hex = $m[1];
      $r = hexdec(substr($hex, 0, 2));
      $g = hexdec(substr($hex, 2, 2));
      $b = hexdec(substr($hex, 4, 2));
      if ($r === $g && $g === $b) return true;
    }
    return false;
  }

  private function format_target_label(string $type, int $id): string {
    if ($type === 'jobpath') return 'Job Path: '.$this->term_full_path('eum_job_path', $id, ' → ');
    if ($type === 'group')   return 'Group: '.$this->term_name('eum_user_group', $id);
    if ($type === 'project') return 'Project: '.$this->term_name('eum_project', $id);
    return '—';
  }

  private function term_name(string $tax, int $id): string {
    $t = get_term($id, $tax);
    if (!$t || is_wp_error($t)) return '—';
    return (string)$t->name;
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
