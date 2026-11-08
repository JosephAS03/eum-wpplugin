<?php
if (!defined('ABSPATH')) exit;

class EUM_User_Creation {
  const CPT = 'eum_user_hire';
  const META_STATUS = '_eum_hire_status';
  const META_ACTOR_USER = '_eum_hire_actor_user_id';
  const META_FULL_NAME = '_eum_hire_full_name';
  const META_EMAIL = '_eum_hire_email';
  const META_USERNAME = '_eum_hire_username';
  const META_JOB_TERM = '_eum_hire_job_term_id';
  const META_EFFECTIVE_AT = '_eum_hire_effective_at';
  const META_CREATED_USER = '_eum_hire_created_user_id';
  const META_ERROR = '_eum_hire_error';
  const META_APPLIED_AT = '_eum_hire_applied_at';
  const META_PROFILE_AFTER = '_eum_hire_profile_after';
  const META_CANDIDATE_ID = '_eum_hire_candidate_id';

  public function init(): void {
    add_action('init', [$this, 'register_cpt']);
    add_action('init', [$this, 'apply_due_hires'], 35);

    add_action('admin_post_eum_user_creation_submit', [$this, 'handle_submit']);
    add_action('admin_post_eum_user_creation_cancel', [$this, 'handle_cancel']);

    add_shortcode('eum_user_creation_portal', [$this, 'shortcode']);
  }

  public function register_cpt(): void {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'User Creations',
        'singular_name' => 'User Creation',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public static function can_access(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (!is_user_logged_in()) return false;
    $allowed = EUM_Access::is_it_or_hr($user_id);
    return (bool)apply_filters('eum_user_creation_can_access', $allowed, $user_id);
  }

  public function shortcode($atts): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-user-creation"><p>You must be logged in to create users.</p></div>';
    }

    $actor_user_id = get_current_user_id();
    if (!self::can_access($actor_user_id)) {
      return '<div class="eum-user-creation"><p>You do not have access to the user creation portal.</p></div>';
    }

    $msg = isset($_GET['eum_hire_msg']) ? sanitize_text_field((string)$_GET['eum_hire_msg']) : '';
    $type = isset($_GET['eum_hire_type']) ? sanitize_key((string)$_GET['eum_hire_type']) : 'success';
    $default_effective = $this->default_effective_at_for_actor($actor_user_id);
    $recent = $this->visible_hires_for_actor($actor_user_id, 25);
    $extra_fields = $this->extra_profile_fields_for_actor($actor_user_id);
    $job_term_domain_map = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::domain_map_for_job_terms(array_map(static fn($t) => (int)$t->term_id, $this->job_terms_for_actor($actor_user_id))) : [];
    $division_domain_map = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::get_divisions() : [];

    ob_start();
    echo '<div class="eum-user-creation">';
    if ($msg !== '') {
      $notice_style = ($type === 'error')
        ? 'margin-bottom:16px;padding:12px 14px;border:1px solid #d63638;background:#fcf0f1;color:#8a2424;border-radius:6px;'
        : 'margin-bottom:16px;padding:12px 14px;border:1px solid #00a32a;background:#ecf7ed;color:#166534;border-radius:6px;';
      echo '<div style="' . esc_attr($notice_style) . '">' . esc_html($msg) . '</div>';
    }

    echo '<div style="display:grid;grid-template-columns:minmax(320px,640px) minmax(320px,1fr);gap:24px;align-items:start;">';
    echo '<div style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">';
    echo '<h3 style="margin-top:0;">Create User</h3>';
    echo '<p class="description">Create a new hire record with an effective hire date. This does not require approval. If the hire date is in the future, the account will be created automatically when that time arrives.</p>';
    if (!EUM_Access::is_it_or_hr($actor_user_id)) {
      echo '<p class="description"><strong>Manager rule:</strong> the effective hire date must be at least 72 hours from now.</p>';
    } else {
      echo '<p class="description"><strong>HR / IT rule:</strong> you can create hires immediately, in the future, or retroactively.</p>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_user_creation_submit">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_user_creation_submit', 'eum_user_creation_nonce');

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Name</label>';
    echo '<input type="text" name="eum_hire_full_name" id="eum-hire-full-name" style="width:100%;max-width:520px;" required></p>';

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Username</label>';
    echo '<input type="text" name="eum_hire_username" id="eum-hire-username" style="width:100%;max-width:520px;" autocomplete="off" required>';
    echo '<br><span class="description">Auto-generated as lowercase first + last with no spaces. You can override it before saving.</span></p>';

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Job Path</label>';
    if (class_exists('EUM_Profiles')) {
      echo EUM_Profiles::render_job_path_directory_picker('eum_hire_job_term', 0, $actor_user_id, [
        'placeholder' => '— Select a Job Path —',
        'required' => true,
        'container_id' => 'eum-hire-job-path-picker',
        'input_id' => 'eum-hire-job-term',
      ]);
    } else {
      echo '<select name="eum_hire_job_term" style="width:100%;max-width:520px;" required>';
      echo '<option value="">— Select a Job Path —</option>';
      foreach ($this->job_terms_for_actor($actor_user_id) as $term) {
        echo '<option value="' . (int)$term->term_id . '">' . esc_html($this->term_full_path('eum_job_path', (int)$term->term_id, ' / ')) . '</option>';
      }
      echo '</select>';
    }
    echo '<span class="description" style="display:block;margin-top:4px;">Pick the job path one level at a time like a directory.</span></p>';

    if (!empty($extra_fields) && class_exists('EUM_Profiles')) {
      echo '<div style="margin:18px 0 8px;padding-top:14px;border-top:1px solid #e2e4e7;">';
      echo '<h4 style="margin:0 0 8px;">Profile Fields</h4>';
      echo '<p class="description">These fields match the profile fields you can edit after the user is created.</p>';
      foreach ($extra_fields as $field_id) {
        echo EUM_Profiles::render_field_input($field_id, '', 0, $actor_user_id);
      }
      echo '</div>';
    }

    if (class_exists('EUM_Timeclock')) {
      echo EUM_Timeclock::render_compensation_inputs('eum_comp', [], [
        'title' => 'Compensation',
        'description' => 'Managers can set starting pay immediately on hire. Later raises above 15%, or raises less than 3 months apart, require second-level approval when edited on the profile later.',
      ]);
    }

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Email</label>';
    echo '<input type="email" id="eum-hire-email-preview" style="width:100%;max-width:520px;" readonly placeholder="username@division-domain">';
    echo '<input type="hidden" name="eum_hire_email" id="eum-hire-email-hidden" value="">';
    echo '<br><span class="description">Email is set automatically from the username and the Company Division email domain for the selected Job Path or division override.</span></p>';

    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Effective Hire Date</label>';
    echo '<input type="datetime-local" name="eum_hire_effective_at" value="' . esc_attr($default_effective) . '" style="width:100%;max-width:320px;" required>';
    echo '<br><span class="description">The account is created at this date/time. Audit logging uses this effective hire date as the logged change time.</span></p>';

    echo '<p><button type="submit" class="button button-primary">Create User</button></p>';
    echo '</form>';
    echo '</div>';

    echo '<div style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">';
    echo '<h3 style="margin-top:0;">Recent User Creations</h3>';
    if (empty($recent)) {
      echo '<p>No user creations yet.</p>';
    } else {
      echo '<div style="overflow:auto;">';
      echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Username</th><th>Job Path</th><th>Effective Hire</th><th>Status</th><th>Created User</th><th>Actions</th></tr></thead><tbody>';
      foreach ($recent as $item) {
        $hire_id = (int)$item->ID;
        $full_name = (string)get_post_meta($hire_id, self::META_FULL_NAME, true);
        $email = (string)get_post_meta($hire_id, self::META_EMAIL, true);
        $username = (string)get_post_meta($hire_id, self::META_USERNAME, true);
        $job_term_id = (int)get_post_meta($hire_id, self::META_JOB_TERM, true);
        $effective_at = (string)get_post_meta($hire_id, self::META_EFFECTIVE_AT, true);
        $status = (string)get_post_meta($hire_id, self::META_STATUS, true);
        $created_user_id = (int)get_post_meta($hire_id, self::META_CREATED_USER, true);
        $error = (string)get_post_meta($hire_id, self::META_ERROR, true);
        $created_user = $created_user_id > 0 ? get_user_by('id', $created_user_id) : false;
        echo '<tr>';
        echo '<td>' . esc_html($full_name) . '</td>';
        echo '<td>' . esc_html($email) . '</td>';
        echo '<td><code>' . esc_html($username) . '</code></td>';
        echo '<td>' . esc_html($job_term_id ? $this->term_full_path('eum_job_path', $job_term_id, ' / ') : '') . '</td>';
        echo '<td>' . esc_html($effective_at) . '</td>';
        echo '<td>' . esc_html(ucfirst($status)) . ($error !== '' ? ' · ' . esc_html($error) : '') . '</td>';
        echo '<td>';
        if ($created_user) {
          echo esc_html($created_user->display_name ?: $created_user->user_login) . ' (#' . (int)$created_user_id . ')';
        } else {
          echo '—';
        }
        echo '</td>';
        echo '<td>';
        if ($status === 'scheduled' && $this->can_cancel_hire($hire_id, $actor_user_id)) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
          echo '<input type="hidden" name="action" value="eum_user_creation_cancel">';
          echo '<input type="hidden" name="hire_id" value="' . $hire_id . '">';
          echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
          wp_nonce_field('eum_user_creation_cancel_' . $hire_id, 'eum_user_creation_cancel_nonce');
          echo '<button type="submit" class="button button-small" onclick="return confirm(\'Cancel this scheduled user creation?\')">Cancel</button>';
          echo '</form>';
        } else {
          echo '—';
        }
        echo '</td>';
        echo '</tr>';
      }
      echo '</tbody></table></div>';
    }
    echo '</div>';
    echo '</div>';

    echo '<script>(function(){var nameInput=document.getElementById("eum-hire-full-name");var userInput=document.getElementById("eum-hire-username");var emailPreview=document.getElementById("eum-hire-email-preview");var emailHidden=document.getElementById("eum-hire-email-hidden");var jobInput=document.getElementById("eum-hire-job-term");var divisionInputs=document.getElementsByName("eum_pf[core:job_company]");var divisionInput=divisionInputs&&divisionInputs.length?divisionInputs[0]:null;var termMap=' + wp_json_encode($job_term_domain_map) + ';var divisionMap=' + wp_json_encode($division_domain_map) + ';if(!nameInput||!userInput||!emailPreview||!emailHidden)return;var touched=false;var makeUsername=function(full){full=(full||"").trim().toLowerCase();if(!full)return"";var parts=full.split(/\s+/).filter(Boolean);var first=parts[0]||"";var last=parts.length>1?parts[parts.length-1]:"";return (first+last).replace(/[^a-z0-9._-]/g,"");};var currentDomain=function(){var override=divisionInput?String(divisionInput.value||"").trim():"";if(override&&divisionMap[override]&&divisionMap[override].domain){return String(divisionMap[override].domain);}var selectedJob=jobInput?String(jobInput.value||"").trim():"";if(selectedJob&&termMap[selectedJob]&&termMap[selectedJob].domain){return String(termMap[selectedJob].domain);}return "";};var refreshEmail=function(){var username=String(userInput.value||"").trim().toLowerCase().replace(/[^a-z0-9._-]/g,"");var domain=currentDomain();var value=(username&&domain)?(username+"@"+domain):"";emailPreview.value=value;emailHidden.value=value;};userInput.addEventListener("input",function(){touched=userInput.value.trim()!=="";refreshEmail();});nameInput.addEventListener("input",function(){if(!touched){userInput.value=makeUsername(nameInput.value);}refreshEmail();});if(divisionInput){divisionInput.addEventListener("change",refreshEmail);}document.addEventListener("change",function(ev){if(ev.target&&ev.target.closest&&ev.target.closest("#eum-hire-job-path-picker")){setTimeout(refreshEmail,0);}});if(jobInput){jobInput.addEventListener("change",refreshEmail);}refreshEmail();})();</script>';
    echo '</div>';
    return ob_get_clean();
  }

  public function handle_submit(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)$_POST['return_url']) : home_url('/');
    if (!self::can_access($actor_user_id)) $this->redirect_with_msg($return_url, 'You do not have access to create users.', 'error');
    if (!isset($_POST['eum_user_creation_nonce']) || !wp_verify_nonce($_POST['eum_user_creation_nonce'], 'eum_user_creation_submit')) {
      $this->redirect_with_msg($return_url, 'Security check failed.', 'error');
    }

    $full_name = sanitize_text_field((string)($_POST['eum_hire_full_name'] ?? ''));
    $job_term_id = isset($_POST['eum_hire_job_term']) ? max(0, (int)$_POST['eum_hire_job_term']) : 0;
    $effective_at = $this->parse_datetime_local((string)($_POST['eum_hire_effective_at'] ?? ''));
    $username_input = sanitize_user((string)($_POST['eum_hire_username'] ?? ''), true);
    $username = $username_input !== '' ? $username_input : $this->generate_username_from_name($full_name, '');
    $profile_after = $this->normalize_profile_fields_for_hire($actor_user_id, isset($_POST['eum_pf']) && is_array($_POST['eum_pf']) ? $_POST['eum_pf'] : []);
    if (class_exists('EUM_Timeclock')) {
      $profile_after = array_merge($profile_after, EUM_Timeclock::compensation_payload_from_request($_POST, 'eum_comp'));
    }

    if ($full_name === '') $this->redirect_with_msg($return_url, 'Name is required.', 'error');
    if ($username === '') $this->redirect_with_msg($return_url, 'Username is required.', 'error');
    if ($job_term_id <= 0 || !term_exists($job_term_id, 'eum_job_path')) $this->redirect_with_msg($return_url, 'Please select a valid job path.', 'error');
    if (!$this->actor_can_manage_job_term($actor_user_id, $job_term_id)) $this->redirect_with_msg($return_url, 'You can only create users inside job paths that you manage.', 'error');
    if ($effective_at === '') $this->redirect_with_msg($return_url, 'Effective hire date is required.', 'error');

    if (!EUM_Access::is_it_or_hr($actor_user_id)) {
      $effective_ts = $this->local_mysql_to_timestamp($effective_at);
      $min_ts = current_datetime()->getTimestamp() + (72 * HOUR_IN_SECONDS);
      if ($effective_ts < $min_ts) {
        $this->redirect_with_msg($return_url, 'Managers must set the effective hire date at least 72 hours in the future.', 'error');
      }
    }

    $division_override = class_exists('EUM_Company_Divisions') ? (string)($profile_after['core:job_company'] ?? '') : '';
    $division_key = class_exists('EUM_Company_Divisions') ? ($division_override !== '' ? EUM_Company_Divisions::normalize_division_key($division_override) : EUM_Company_Divisions::effective_division_for_job_path($job_term_id)) : '';
    if (class_exists('EUM_Company_Divisions') && $division_key !== '') {
      $profile_after['core:job_company'] = $division_key;
    }
    if (class_exists('EUM_Company_Divisions')) {
      $username = $this->next_available_username_for_division($username, $division_key);
      $email = EUM_Company_Divisions::build_email_for_username_and_division($username, $division_key);
      if ($email === '' || !is_email($email)) {
        $this->redirect_with_msg($return_url, 'This Job Path does not resolve to a Company Division with an email domain. Configure the division domain first.', 'error');
      }
    } else {
      $username = $this->next_available_username($username);
      $email = sanitize_email((string)($_POST['eum_hire_email'] ?? ''));
    }
    if (email_exists($email)) {
      $this->redirect_with_msg($return_url, 'That email address already belongs to an existing user.', 'error');
    }
    if ($this->scheduled_hire_conflict(self::META_EMAIL, $email)) {
      $this->redirect_with_msg($return_url, 'That email address is already reserved by another scheduled user creation.', 'error');
    }
    if ($this->scheduled_hire_conflict(self::META_USERNAME, $username)) {
      $this->redirect_with_msg($return_url, 'That username is already reserved by another scheduled user creation.', 'error');
    }

    $hire_id = (int)wp_insert_post([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'post_title' => 'User Creation - ' . $full_name . ' (' . $username . ')',
      'post_author' => $actor_user_id,
    ]);

    if ($hire_id <= 0) {
      $this->redirect_with_msg($return_url, 'Could not save the user creation request.', 'error');
    }

    update_post_meta($hire_id, self::META_STATUS, 'scheduled');
    update_post_meta($hire_id, self::META_ACTOR_USER, $actor_user_id);
    update_post_meta($hire_id, self::META_FULL_NAME, $full_name);
    update_post_meta($hire_id, self::META_EMAIL, $email);
    update_post_meta($hire_id, self::META_USERNAME, $username);
    update_post_meta($hire_id, self::META_JOB_TERM, $job_term_id);
    update_post_meta($hire_id, self::META_EFFECTIVE_AT, $effective_at);
    update_post_meta($hire_id, self::META_PROFILE_AFTER, $profile_after);
    update_post_meta($hire_id, self::META_ERROR, '');

    if ($this->hire_is_due($hire_id)) {
      $result = $this->apply_hire($hire_id);
      if (is_wp_error($result)) {
        $this->redirect_with_msg($return_url, $result->get_error_message(), 'error');
      }
      $message = 'User created.';
      if ($username !== $username_input && $username_input !== '') {
        $message .= ' Final username: ' . $username . '.';
      } elseif ($username !== $username_input && $username_input === '') {
        $message .= ' Username: ' . $username . '.';
      }
      $this->redirect_with_msg($return_url, $message, 'success');
    }

    $message = 'User creation scheduled for ' . $effective_at . '.';
    if ($username !== $username_input) $message .= ' Username reserved as ' . $username . '.';
    $this->redirect_with_msg($return_url, $message, 'success');
  }

  public function handle_cancel(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $hire_id = isset($_POST['hire_id']) ? (int)$_POST['hire_id'] : 0;
    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)($_POST['return_url'] ?? '')) : home_url('/');
    if ($hire_id <= 0 || !isset($_POST['eum_user_creation_cancel_nonce']) || !wp_verify_nonce($_POST['eum_user_creation_cancel_nonce'], 'eum_user_creation_cancel_' . $hire_id)) {
      $this->redirect_with_msg($return_url, 'Security check failed.', 'error');
    }
    if (!$this->can_cancel_hire($hire_id, $actor_user_id)) {
      $this->redirect_with_msg($return_url, 'You cannot cancel this scheduled user creation.', 'error');
    }
    update_post_meta($hire_id, self::META_STATUS, 'cancelled');
    $candidate_id = (int)get_post_meta($hire_id, self::META_CANDIDATE_ID, true);
    if ($candidate_id > 0 && class_exists('EUM_Job_Boards')) {
      $restore_status = (string)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_PRE_HIRE_STATUS, true);
      if ($restore_status === '') $restore_status = 'selected';
      update_post_meta($candidate_id, EUM_Job_Boards::CAND_META_STATUS, $restore_status);
    }
    $this->redirect_with_msg($return_url, 'Scheduled user creation cancelled.', 'success');
  }

  public function apply_due_hires(): void {
    $items = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => 100,
      'meta_query' => [
        [
          'key' => self::META_STATUS,
          'value' => 'scheduled',
        ],
      ],
    ]);

    foreach ((array)$items as $item) {
      if (!$this->hire_is_due((int)$item->ID)) continue;
      $this->apply_hire((int)$item->ID);
    }
  }

  public function hire_is_due(int $hire_id): bool {
    if ($hire_id <= 0) return false;
    $effective_at = (string)get_post_meta($hire_id, self::META_EFFECTIVE_AT, true);
    if ($effective_at === '') return false;
    return $this->local_mysql_to_timestamp($effective_at) <= current_datetime()->getTimestamp();
  }

  public function apply_hire(int $hire_id) {
    $hire = get_post($hire_id);
    if (!$hire || $hire->post_type !== self::CPT) {
      return new WP_Error('invalid_hire', 'The user creation request no longer exists.');
    }

    $candidate_id = (int)get_post_meta($hire_id, self::META_CANDIDATE_ID, true);
    $lock_name = $candidate_id > 0
      ? '_eum_candidate_account_create_lock_' . $candidate_id
      : '_eum_hire_account_create_lock_' . $hire_id;
    $existing_lock = get_option($lock_name, false);
    $lock_created_at = is_array($existing_lock) ? (int)($existing_lock['created_at'] ?? 0) : 0;
    if ($lock_created_at > 0 && $lock_created_at < time() - (30 * MINUTE_IN_SECONDS)) {
      delete_option($lock_name);
    }

    $lock_token = wp_generate_password(32, false, false);
    if (!add_option($lock_name, ['token' => $lock_token, 'created_at' => time()], '', false)) {
      return new WP_Error('hire_in_progress', 'This candidate hire is already being processed.');
    }

    try {
      return $this->apply_hire_locked($hire_id);
    } finally {
      $lock = get_option($lock_name, false);
      if (is_array($lock) && !empty($lock['token']) && hash_equals((string)$lock['token'], $lock_token)) {
        delete_option($lock_name);
      }
    }
  }

  private function apply_hire_locked(int $hire_id) {
    $hire = get_post($hire_id);
    if (!$hire || $hire->post_type !== self::CPT) {
      return new WP_Error('invalid_hire', 'The user creation request no longer exists.');
    }

    $status = (string)get_post_meta($hire_id, self::META_STATUS, true);
    if (in_array($status, ['applied', 'cancelled'], true)) {
      return true;
    }

    $candidate_id = (int)get_post_meta($hire_id, self::META_CANDIDATE_ID, true);
    $existing_user_id = 0;
    if ($candidate_id > 0 && class_exists('EUM_Job_Boards')) {
      $candidate_user_id = (int)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_USER_ID, true);
      if ($candidate_user_id > 0 && get_userdata($candidate_user_id)) $existing_user_id = $candidate_user_id;
    }
    if ($existing_user_id <= 0) {
      $hire_created_user_id = (int)get_post_meta($hire_id, self::META_CREATED_USER, true);
      if ($hire_created_user_id > 0 && get_userdata($hire_created_user_id)) $existing_user_id = $hire_created_user_id;
    }

    $actor_user_id = (int)get_post_meta($hire_id, self::META_ACTOR_USER, true);
    $full_name = (string)get_post_meta($hire_id, self::META_FULL_NAME, true);
    $email = sanitize_email((string)get_post_meta($hire_id, self::META_EMAIL, true));
    $username = sanitize_user((string)get_post_meta($hire_id, self::META_USERNAME, true), true);
    $job_term_id = (int)get_post_meta($hire_id, self::META_JOB_TERM, true);
    $effective_at = (string)get_post_meta($hire_id, self::META_EFFECTIVE_AT, true);
    $profile_after = get_post_meta($hire_id, self::META_PROFILE_AFTER, true);
    $profile_after = is_array($profile_after) ? $profile_after : [];

    if (class_exists('EUM_Taxonomies')) {
      $location_term_id = (int)($profile_after['tax:eum_location'] ?? 0);
      $mapping = [];
      if ($location_term_id > 0) {
        $mapping = array_merge($mapping, EUM_Taxonomies::effective_mapping_for_term('eum_location', $location_term_id));
      }
      if ($job_term_id > 0) {
        $mapping = array_merge($mapping, EUM_Taxonomies::effective_mapping_for_term('eum_job_path', $job_term_id));
      }
      foreach ($mapping as $raw_key => $value) {
        if (EUM_Taxonomies::normalize_mapping_key((string)$raw_key) !== 'job_company') continue;
        $division_value = class_exists('EUM_Company_Divisions')
          ? EUM_Company_Divisions::normalize_division_key((string)$value)
          : sanitize_text_field((string)$value);
        if ($division_value !== '') {
          $profile_after['core:job_company'] = $division_value;
        }
      }
    }

    if (class_exists('EUM_Company_Divisions')) {
      $division_key = $this->hire_division_key($job_term_id, $profile_after);
      $division_key = EUM_Company_Divisions::normalize_division_key($division_key);
      if ($division_key !== '') {
        $profile_after['core:job_company'] = $division_key;
      }
      $derived_email = EUM_Company_Divisions::build_email_for_username_and_division($username, $division_key);
      if ($derived_email !== '') {
        $email = $derived_email;
        $profile_after['core:user_email'] = $email;
      }
      update_post_meta($hire_id, self::META_PROFILE_AFTER, $profile_after);
      update_post_meta($hire_id, self::META_EMAIL, $email);
    }

    if ($full_name === '' || $email === '' || $username === '' || $job_term_id <= 0 || $effective_at === '') {
      $missing = [];
      if ($full_name === '') $missing[] = 'name';
      if ($email === '') $missing[] = 'company email (check Company Division/domain)';
      if ($username === '') $missing[] = 'username';
      if ($job_term_id <= 0) $missing[] = 'job path';
      if ($effective_at === '') $missing[] = 'effective hire date';
      $message = 'The scheduled record is incomplete. Missing: ' . implode(', ', $missing) . '.';
      update_post_meta($hire_id, self::META_STATUS, 'failed');
      update_post_meta($hire_id, self::META_ERROR, $message);
      return new WP_Error('invalid_hire_record', $message);
    }

    if ($existing_user_id <= 0 && (username_exists($username) || email_exists($email))) {
      update_post_meta($hire_id, self::META_STATUS, 'failed');
      update_post_meta($hire_id, self::META_ERROR, 'Username or email is already in use.');
      return new WP_Error('hire_conflict', 'User creation failed because the username or email is already in use.');
    }

    [$first_name, $last_name] = $this->split_name($full_name);
    $created_new_user = $existing_user_id <= 0;
    if ($created_new_user) {
      $password = wp_generate_password(24, true, true);
      $user_id = wp_insert_user([
        'user_login' => $username,
        'user_pass' => $password,
        'user_email' => $email,
        'display_name' => $full_name,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'role' => get_option('default_role', 'subscriber'),
      ]);

      if (is_wp_error($user_id)) {
        update_post_meta($hire_id, self::META_STATUS, 'failed');
        update_post_meta($hire_id, self::META_ERROR, $user_id->get_error_message());
        return new WP_Error('hire_insert_failed', 'User creation failed: ' . $user_id->get_error_message());
      }

      // Persist the created user immediately so an interrupted retry reuses it.
      update_post_meta($hire_id, self::META_CREATED_USER, (int)$user_id);
      if ($candidate_id > 0 && class_exists('EUM_Job_Boards')) {
        update_post_meta($candidate_id, EUM_Job_Boards::CAND_META_USER_ID, (int)$user_id);
      }
    } else {
      $user_id = $existing_user_id;
      $existing_user = get_userdata($user_id);
      if ($existing_user) {
        $username = (string)$existing_user->user_login;
        if ($username !== sanitize_user((string)get_post_meta($hire_id, self::META_USERNAME, true), true)) {
          unset($profile_after['core:user_email']);
          $email = sanitize_email((string)$existing_user->user_email);
          update_post_meta($hire_id, self::META_USERNAME, $username);
          update_post_meta($hire_id, self::META_EMAIL, $email);
          update_post_meta($hire_id, self::META_PROFILE_AFTER, $profile_after);
        }
        $updated_user_id = wp_update_user([
          'ID' => $user_id,
          'display_name' => $full_name,
          'first_name' => $first_name,
          'last_name' => $last_name,
        ]);
        if (is_wp_error($updated_user_id)) {
          update_post_meta($hire_id, self::META_ERROR, $updated_user_id->get_error_message());
          return new WP_Error('hire_user_update_failed', 'Could not update the existing candidate account: ' . $updated_user_id->get_error_message());
        }
      }
    }

    wp_set_object_terms((int)$user_id, [$job_term_id], 'eum_job_path', false);
    if (class_exists('EUM_Company_Divisions')) {
      $division_key = $this->hire_division_key($job_term_id, $profile_after);
      $division_key = EUM_Company_Divisions::normalize_division_key($division_key);
      EUM_Company_Divisions::save_user_division_input((int)$user_id, $division_key, false, $job_term_id);
    }
    $this->apply_profile_fields_to_user((int)$user_id, $profile_after);
    if (class_exists('EUM_Timeclock')) {
      EUM_Timeclock::apply_compensation_payload_to_user((int)$user_id, $profile_after, $actor_user_id, $effective_at);
    }
    if ($created_new_user || (get_userdata((int)$user_id) && (string)get_userdata((int)$user_id)->user_login === $username)) {
      $email = $this->enforce_hire_company_email((int)$user_id, $hire_id, $username, $job_term_id, $profile_after, $email);
    }
    update_post_meta($hire_id, self::META_STATUS, 'applied');
    update_post_meta($hire_id, self::META_CREATED_USER, (int)$user_id);
    update_post_meta($hire_id, self::META_APPLIED_AT, current_time('mysql'));
    update_post_meta($hire_id, self::META_ERROR, '');

    if ($candidate_id > 0 && class_exists('EUM_Job_Boards')) {
      EUM_Job_Boards::ensure_candidate_application_log($candidate_id, (int)$user_id, $actor_user_id);
      EUM_Job_Boards::record_user_candidate_id((int)$user_id, $candidate_id);
      EUM_Job_Boards::apply_candidate_preset_user_meta((int)$user_id, $candidate_id);
      update_post_meta($candidate_id, EUM_Job_Boards::CAND_META_STATUS, 'hired');
      update_post_meta($candidate_id, EUM_Job_Boards::CAND_META_USER_ID, (int)$user_id);
    }

    if ($created_new_user) {
      $this->log_created_user((int)$user_id, $actor_user_id, $effective_at, $full_name, $username, $email, $job_term_id, $profile_after);
    }

    if ($created_new_user && function_exists('wp_send_new_user_notifications')) {
      wp_send_new_user_notifications((int)$user_id, 'user');
    }

    do_action('eum_user_hire_applied', (int)$user_id, $hire_id, [
      'actor_user_id' => $actor_user_id,
      'full_name' => $full_name,
      'email' => $email,
      'username' => $username,
      'job_term_id' => $job_term_id,
      'effective_at' => $effective_at,
      'profile_after' => $profile_after,
    ]);

    return (int)$user_id;
  }

  private function enforce_hire_company_email(int $user_id, int $hire_id, string $username, int $job_term_id, array &$profile_after, string $fallback_email): string {
    if ($user_id <= 0 || !class_exists('EUM_Company_Divisions')) return $fallback_email;

    $division_key = EUM_Company_Divisions::normalize_division_key($this->hire_division_key($job_term_id, $profile_after));
    if ($division_key === '') {
      $division_key = EUM_Company_Divisions::effective_division_for_user($user_id);
    }
    if ($division_key === '') $division_key = EUM_Company_Divisions::normalize_division_key(EUM_Company_Divisions::resolve_division_for_job_path($job_term_id, $division_key));
    if ($division_key !== '') {
      $profile_after['core:job_company'] = $division_key;
      EUM_Company_Divisions::save_user_division_input($user_id, $division_key, false, $job_term_id);
    }

    $email = EUM_Company_Divisions::build_email_for_username_and_division($username, $division_key);
    if ($email === '') $email = sanitize_email($fallback_email);
    if ($email === '') return '';

    $profile_after['core:user_email'] = $email;
    wp_update_user(['ID' => $user_id, 'user_email' => $email]);
    update_post_meta($hire_id, self::META_EMAIL, $email);
    update_post_meta($hire_id, self::META_PROFILE_AFTER, $profile_after);

    return $email;
  }

  private function hire_division_key(int $job_term_id, array $profile_after): string {
    if (!class_exists('EUM_Company_Divisions')) return '';
    $job_path_division = EUM_Company_Divisions::effective_division_for_job_path($job_term_id);
    if ($job_path_division !== '') return EUM_Company_Divisions::normalize_division_key($job_path_division);
    return EUM_Company_Divisions::normalize_division_key((string)($profile_after['core:job_company'] ?? ''));
  }

  private function log_created_user(int $user_id, int $actor_user_id, string $effective_at, string $full_name, string $username, string $email, int $job_term_id, array $profile_after = []): void {
    if (!class_exists('EUM_Profiles')) return;
    $settings = EUM_Profiles::get_settings();
    if (empty($settings['logging_enabled'])) return;

    [$first_name, $last_name] = $this->split_name($full_name);
    $occurred_at = $effective_at !== '' ? $effective_at : current_time('mysql');
    $change_id = (int)wp_insert_post([
      'post_type' => EUM_Profiles::CPT_CHANGE,
      'post_status' => 'publish',
      'post_title' => 'User Creation - ' . $full_name . ' (' . $username . ')',
      'post_author' => $actor_user_id > 0 ? $actor_user_id : get_current_user_id(),
    ]);
    if ($change_id <= 0) return;

    update_post_meta($change_id, EUM_Profiles::META_TARGET_USER, $user_id);
    update_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, $actor_user_id);
    update_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, []);
    update_post_meta($change_id, EUM_Profiles::META_BEFORE, []);
    $after = array_merge([
      'core:first_name' => $first_name,
      'core:last_name' => $last_name,
      'core:display_name' => $full_name,
      'core:user_email' => $email,
      'tax:eum_job_path' => $job_term_id,
    ], $profile_after);
    update_post_meta($change_id, EUM_Profiles::META_AFTER, $after);
    update_post_meta($change_id, EUM_Profiles::META_NOTE, 'Hire / User Creation');
    update_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, $effective_at);
    update_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, $occurred_at);
    update_post_meta($change_id, EUM_Profiles::META_STATUS, 'applied');
    update_post_meta($change_id, EUM_Profiles::META_MANUAL, 0);
  }

  private function extra_profile_fields_for_actor(int $actor_user_id): array {
    if (!class_exists('EUM_Profiles')) return [];
    return EUM_Profiles::editable_fields_for_new_hire_actor($actor_user_id);
  }

  private function normalize_profile_fields_for_hire(int $actor_user_id, array $posted): array {
    if (!class_exists('EUM_Profiles')) return [];
    $fields = $this->extra_profile_fields_for_actor($actor_user_id);
    $out = [];
    foreach ($fields as $field_id) {
      $normalized = EUM_Profiles::normalize_field_input($field_id, $posted[$field_id] ?? null);
      if ($normalized === null) continue;
      if (is_array($normalized)) {
        if (!empty($normalized)) $out[$field_id] = $normalized;
      } elseif ($normalized !== '' && $normalized !== 0 && $normalized !== '0') {
        $out[$field_id] = $normalized;
      } elseif (in_array($field_id, ['core:eum_manager_user_id'], true) && (int)$normalized > 0) {
        $out[$field_id] = (int)$normalized;
      }
    }
    return $out;
  }

  public function apply_profile_fields_to_user(int $user_id, array $profile_after): void {
    if ($user_id <= 0 || empty($profile_after) || !class_exists('EUM_Profiles')) return;
    EUM_Profiles::store_candidate_extended_profile_for_user($user_id, $profile_after);
    $catalog = EUM_Profiles::field_catalog();
    $custom_values = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    $assigned_job_terms = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($assigned_job_terms)) $assigned_job_terms = [];
    $job_term_id = isset($profile_after['tax:eum_job_path']) ? max(0, (int)$profile_after['tax:eum_job_path']) : (!empty($assigned_job_terms) ? (int)$assigned_job_terms[0] : 0);

    foreach ($profile_after as $field_id => $value) {
      if (!isset($catalog[$field_id])) continue;
      $def = $catalog[$field_id];

      if (str_starts_with($field_id, 'core:')) {
        $key = substr($field_id, 5);
        switch ($key) {
          case 'first_name':
          case 'last_name':
          case 'display_name':
            wp_update_user(['ID' => $user_id, $key => sanitize_text_field((string)$value)]);
            break;
          case 'user_email':
            if ((string)$value !== '') wp_update_user(['ID' => $user_id, 'user_email' => sanitize_email((string)$value)]);
            break;
          case 'eum_manager_user_id':
            if (class_exists('EUM_Personnel')) update_user_meta($user_id, EUM_Personnel::META_MANAGER_ID, max(0, (int)$value));
            break;
          case 'job_company':
            if (class_exists('EUM_Company_Divisions')) {
              EUM_Company_Divisions::save_user_division_input($user_id, (string)$value, true, $job_term_id);
            }
            break;
        }
        continue;
      }

      if (str_starts_with($field_id, 'tax:')) {
        $tax = (string)($def['taxonomy'] ?? '');
        if ($tax === '') continue;
        if (($def['type'] ?? '') === 'taxonomy_single') {
          $tid = max(0, (int)$value);
          wp_set_object_terms($user_id, $tid ? [$tid] : [], $tax, false);
        } else {
          $vals = array_values(array_filter(array_map('intval', (array)$value), fn($v) => $v > 0));
          wp_set_object_terms($user_id, $vals, $tax, false);
        }
        continue;
      }

      if (str_starts_with($field_id, 'custom:') && class_exists('EUM_Fields')) {
        $custom_key = (string)($def['custom_key'] ?? '');
        if ($custom_key === '') continue;
        $custom_values[$custom_key] = EUM_Fields::sanitize_field_value($custom_key, (string)($def['type'] ?? 'text'), $value);
      }
    }

    if (class_exists('EUM_Fields')) {
      update_user_meta($user_id, EUM_Fields::USER_META_KEY, $custom_values);
    }

    if (class_exists('EUM_Taxonomies')) {
      EUM_Taxonomies::sync_user_classification_effects($user_id, 0, 'Hire Classification Sync');
    } elseif (class_exists('EUM_Assignments')) {
      $syncer = new EUM_Assignments();
      if (method_exists($syncer, 'sync_user')) $syncer->sync_user($user_id);
    }
  }

  private function visible_hires_for_actor(int $actor_user_id, int $limit = 25): array {
    $items = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => max(1, $limit * 3),
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    if (EUM_Access::is_it_or_hr($actor_user_id)) {
      return array_slice((array)$items, 0, $limit);
    }

    $out = [];
    foreach ((array)$items as $item) {
      $creator = (int)get_post_meta((int)$item->ID, self::META_ACTOR_USER, true);
      $job_term_id = (int)get_post_meta((int)$item->ID, self::META_JOB_TERM, true);
      if ($creator === $actor_user_id || $this->actor_can_manage_job_term($actor_user_id, $job_term_id)) {
        $out[] = $item;
      }
      if (count($out) >= $limit) break;
    }
    return $out;
  }

  private function can_cancel_hire(int $hire_id, int $actor_user_id): bool {
    if ($actor_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    $hire = get_post($hire_id);
    if (!$hire || $hire->post_type !== self::CPT) return false;
    $status = (string)get_post_meta($hire_id, self::META_STATUS, true);
    if ($status !== 'scheduled') return false;
    $creator = (int)get_post_meta($hire_id, self::META_ACTOR_USER, true);
    if ($creator === $actor_user_id) return true;
    $job_term_id = (int)get_post_meta($hire_id, self::META_JOB_TERM, true);
    return $this->actor_can_manage_job_term($actor_user_id, $job_term_id);
  }

  private function job_terms_for_actor(int $actor_user_id): array {
    $terms = get_terms([
      'taxonomy' => 'eum_job_path',
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
      'number' => 0,
    ]);
    if (is_wp_error($terms) || empty($terms)) return [];
    if (EUM_Access::is_it_or_hr($actor_user_id)) return $terms;

    $out = [];
    foreach ($terms as $term) {
      if ($this->actor_can_manage_job_term($actor_user_id, (int)$term->term_id)) $out[] = $term;
    }
    return $out;
  }

  private function actor_can_manage_job_term(int $actor_user_id, int $term_id): bool {
    if ($term_id <= 0) return true;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    foreach ($this->manager_root_job_terms($actor_user_id) as $root_term_id) {
      if ($term_id === $root_term_id || term_is_ancestor_of($root_term_id, $term_id, 'eum_job_path')) return true;
    }
    return false;
  }

  private function manager_root_job_terms(int $actor_user_id): array {
    if (!class_exists('EUM_Manager_IDs')) return [];
    $posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'fields' => 'ids',
    ]);
    $term_ids = [];
    foreach ((array)$posts as $post_id) {
      $manager_users = (array)get_post_meta((int)$post_id, EUM_Manager_IDs::META_MANAGER_USERS, true);
      $manager_users = array_values(array_filter(array_map('intval', $manager_users)));
      if (!in_array($actor_user_id, $manager_users, true)) continue;
      $job_term_id = (int)get_post_meta((int)$post_id, EUM_Manager_IDs::META_JOB_PATH_TERM, true);
      if ($job_term_id > 0) $term_ids[] = $job_term_id;
    }
    return array_values(array_unique($term_ids));
  }

  private function default_effective_at_for_actor(int $actor_user_id): string {
    $dt = current_datetime();
    if (!EUM_Access::is_it_or_hr($actor_user_id)) {
      $dt = $dt->modify('+72 hours');
    }
    return $dt->format('Y-m-d\TH:i');
  }

  private function current_url(): string {
    $scheme = is_ssl() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string)$_SERVER['HTTP_HOST']) : parse_url(home_url('/'), PHP_URL_HOST);
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '/';
    return esc_url_raw($scheme . '://' . $host . $request_uri);
  }

  private function redirect_with_msg(string $url, string $msg, string $type = 'success'): void {
    $url = $url ?: home_url('/');
    $url = add_query_arg([
      'eum_hire_msg' => $msg,
      'eum_hire_type' => $type,
    ], $url);
    wp_safe_redirect($url);
    exit;
  }

  private function parse_datetime_local(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $value = str_replace('T', ' ', $value);
    $dt = date_create_immutable_from_format('Y-m-d H:i', $value, wp_timezone());
    if (!$dt) $dt = date_create_immutable_from_format('Y-m-d H:i:s', $value, wp_timezone());
    return $dt ? $dt->format('Y-m-d H:i:s') : '';
  }

  private function local_mysql_to_timestamp(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $dt = date_create_immutable_from_format('Y-m-d H:i:s', $value, wp_timezone());
    if (!$dt) return 0;
    return $dt->getTimestamp();
  }

  private function split_name(string $full_name): array {
    $parts = preg_split('/\s+/', trim($full_name)) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
    if (empty($parts)) return ['', ''];
    $first = array_shift($parts);
    $last = implode(' ', $parts);
    return [$first, $last];
  }

  private function generate_username_from_name(string $full_name, string $email = ''): string {
    [$first_name, $last_name] = $this->split_name($full_name);
    $last_token = $last_name !== '' ? preg_replace('/^.*\s/u', '', $last_name) : '';
    $base = sanitize_user(strtolower($first_name . $last_token), true);
    if ($base === '' && $email !== '') {
      $local = explode('@', $email)[0] ?? '';
      $base = sanitize_user(strtolower((string)$local), true);
    }
    return $base;
  }

  private function next_available_username(string $base): string {
    $base = sanitize_user($base, true);
    if ($base === '') $base = 'user';
    $candidate = $base;
    $i = 2;
    while (username_exists($candidate) || $this->scheduled_hire_conflict(self::META_USERNAME, $candidate)) {
      $candidate = $base . $i;
      $i++;
      if ($i > 5000) break;
    }
    return $candidate;
  }

  private function next_available_username_for_division(string $base, string $division_key): string {
    $base = sanitize_user($base, true);
    if ($base === '') $base = 'user';
    $candidate = $base;
    $i = 2;
    while (username_exists($candidate)
      || $this->scheduled_hire_conflict(self::META_USERNAME, $candidate)
      || (class_exists('EUM_Company_Divisions') && ($email = EUM_Company_Divisions::build_email_for_username_and_division($candidate, $division_key)) && (email_exists($email) || $this->scheduled_hire_conflict(self::META_EMAIL, $email)))) {
      $candidate = $base . $i;
      $i++;
      if ($i > 5000) break;
    }
    return $candidate;
  }

  private function scheduled_hire_conflict(string $meta_key, string $value): bool {
    if ($value === '') return false;
    $items = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => 1,
      'fields' => 'ids',
      'meta_query' => [
        'relation' => 'AND',
        [
          'key' => self::META_STATUS,
          'value' => 'scheduled',
        ],
        [
          'key' => $meta_key,
          'value' => $value,
        ],
      ],
    ]);
    return !empty($items);
  }

  private function term_full_path(string $taxonomy, int $term_id, string $sep = ' / '): string {
    $parts = [];
    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) return '';
    $parts[] = $term->name;
    while ($term->parent) {
      $term = get_term((int)$term->parent, $taxonomy);
      if (!$term || is_wp_error($term)) break;
      array_unshift($parts, $term->name);
    }
    return implode($sep, $parts);
  }
}
