<?php
if (!defined('ABSPATH')) exit;

class EUM_Access {
  const META_IT = 'eum_it_access';
  const META_HR = 'eum_hr_access';
  const META_FINANCE = 'eum_finance_access';
  const META_ARTIST_CREATOR = 'eum_artist_creator_access';
  const META_LEGACY = 'eum_hr_it_access';
  const META_PASSWORD_SETUP_REQUIRED = '_eum_password_setup_required';
  const META_PASSWORD_SETUP_REQUIRED_AT = '_eum_password_setup_required_at';
  const META_FIRST_PASSWORD_SETUP_AT = '_eum_first_password_setup_at';
  const META_LAST_PASSWORD_RESET_AT = '_eum_last_password_reset_at';
  const META_FORCE_PASSWORD_CHANGE = '_eum_force_password_change';
  const META_FORCE_PASSWORD_CHANGE_AT = '_eum_force_password_change_at';
  const META_LOGIN_REDIRECT_ONCE = '_eum_login_redirect_once';
  const META_LOGIN_CONTROL_AUDIT = '_eum_login_control_audit';
  const FILTER_ROOT_USER_ID = 'eum_forced_root_user_id';

  public function init(): void {
    add_action('init', [$this, 'register_user_caps_filter'], 1);
    add_action('init', [$this, 'logout_terminated_user'], 2);
    add_action('admin_init', [$this, 'enforce_forced_password_change'], 0);
    add_action('admin_init', [$this, 'enforce_admin_lockout'], 1);
    add_action('template_redirect', [$this, 'enforce_site_login'], 0);
    add_action('admin_post_eum_user_login_controls', [$this, 'handle_user_login_controls']);
    add_action('admin_post_eum_force_password_change_form', [$this, 'render_forced_password_change_form']);
    add_action('admin_post_eum_force_password_change', [$this, 'handle_forced_password_change']);
    add_action('user_register', [$this, 'mark_new_user_password_setup_required'], 20, 1);
    add_action('after_password_reset', [$this, 'handle_after_password_reset'], 20, 2);
    add_action('wp_login', [$this, 'handle_first_password_setup_login'], 35, 2);
    add_filter('authenticate', [$this, 'authenticate_employee_id_login'], 5, 3);
    add_filter('authenticate', [$this, 'block_terminated_login'], 50, 3);
    add_filter('login_redirect', [$this, 'filter_login_redirect'], 99, 3);
    add_filter('rest_authentication_errors', [$this, 'block_rest_until_password_change'], 99);

    add_action('show_user_profile', [$this, 'render_access_flags']);
    add_action('edit_user_profile', [$this, 'render_access_flags']);
    add_action('personal_options_update', [$this, 'save_access_flags']);
    add_action('edit_user_profile_update', [$this, 'save_access_flags']);
  }

  public function mark_new_user_password_setup_required(int $user_id): void {
    self::mark_password_setup_required($user_id);
  }

  public function handle_after_password_reset($user, string $new_pass = ''): void {
    if (!$user instanceof WP_User || (int)$user->ID <= 0) return;

    $user_id = (int)$user->ID;
    $now = current_time('mysql');
    $first_setup_at = (string)get_user_meta($user_id, self::META_FIRST_PASSWORD_SETUP_AT, true);
    $first_login_at = (string)get_user_meta($user_id, '_eum_first_portal_login_at', true);
    $is_first_setup = self::password_setup_required($user_id) || ($first_setup_at === '' && $first_login_at === '');
    update_user_meta($user_id, self::META_LAST_PASSWORD_RESET_AT, $now);

    if ($is_first_setup) {
      update_user_meta($user_id, self::META_FIRST_PASSWORD_SETUP_AT, $now);
      delete_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED);
      delete_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED_AT);
      do_action('eum_first_password_setup_completed', $user_id, $user);

      if (function_exists('eum_log_message')) {
        eum_log_message('[EUM Access] First password setup completed for user #' . $user_id . '.');
      }
    }

    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);

    if (!headers_sent()) {
      if (function_exists('wp_password_change_notification')) {
        wp_password_change_notification($user);
      }
      $reset_cookie = 'wp-resetpass-' . COOKIEHASH;
      setcookie($reset_cookie, ' ', time() - YEAR_IN_SECONDS, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
      wp_safe_redirect(home_url('/'));
      exit;
    }
  }

  public function handle_first_password_setup_login(string $user_login, $user): void {
    if (!$user instanceof WP_User || (int)$user->ID <= 0) return;

    $user_id = (int)$user->ID;
    if (!self::password_setup_required($user_id)) return;

    if ((string)get_user_meta($user_id, self::META_FIRST_PASSWORD_SETUP_AT, true) !== '') {
      delete_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED);
      delete_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED_AT);
      return;
    }

    update_user_meta($user_id, self::META_FIRST_PASSWORD_SETUP_AT, current_time('mysql'));
    delete_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED);
    delete_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED_AT);
    do_action('eum_first_password_setup_completed', $user_id, $user);

    if (function_exists('eum_log_message')) {
      eum_log_message('[EUM Access] First password setup completed on login for user #' . $user_id . '.');
    }
  }

  public function register_user_caps_filter(): void {
    add_filter('user_has_cap', [$this, 'grant_special_caps'], 10, 4);
  }

  public function grant_special_caps(array $allcaps, array $caps, array $args, $user): array {
    $user_id = 0;
    if ($user instanceof WP_User) {
      $user_id = (int)$user->ID;
    } elseif (is_object($user) && isset($user->ID)) {
      $user_id = (int)$user->ID;
    }

    if ($user_id <= 0) {
      return $allcaps;
    }

    if (self::is_it_user($user_id)) {
      foreach (['eum_manage','eum_edit_users','eum_manage_finance','eum_manage_artists','eum_manage_podcasts','list_users','edit_users','promote_users','create_users','upload_files'] as $cap) {
        $allcaps[$cap] = true;
      }
      return $allcaps;
    }

    if (self::is_finance_user($user_id)) {
      foreach (['read','eum_manage_finance','upload_files'] as $cap) {
        $allcaps[$cap] = true;
      }
    }

    if (self::is_artist_creator($user_id) || self::is_hr_user($user_id)) {
      foreach (['read','eum_manage_artists','upload_files'] as $cap) {
        $allcaps[$cap] = true;
      }
    }

    if (self::is_podcast_manager($user_id)) {
      foreach (['read','eum_manage_podcasts','upload_files'] as $cap) {
        $allcaps[$cap] = true;
      }
    }

    return $allcaps;
  }

  public function authenticate_employee_id_login($user, string $username = '', string $password = '') {
    if ($user instanceof WP_User || is_wp_error($user)) return $user;

    $identifier = trim($username);
    if ($identifier === '' || $password === '') return $user;

    $by_login = get_user_by('login', sanitize_user($identifier, true));
    if ($by_login instanceof WP_User) return $user;

    if (is_email($identifier)) {
      $by_email = get_user_by('email', sanitize_email($identifier));
      if ($by_email instanceof WP_User) return $user;
    }

    if (!class_exists('EUM_Workforce_Lifecycle')) return $user;

    $mapped_user_id = EUM_Workforce_Lifecycle::find_user_by_employee_id($identifier);
    if ($mapped_user_id <= 0) return $user;

    $mapped_user = get_userdata($mapped_user_id);
    if (!$mapped_user || empty($mapped_user->user_login)) return $user;

    return wp_authenticate_username_password(null, (string)$mapped_user->user_login, $password);
  }

  public function block_terminated_login($user, string $username = '', string $password = '') {
    if (is_wp_error($user) || !$user instanceof WP_User) return $user;
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated((int)$user->ID)) {
      if (class_exists('EUM_Login_As') && EUM_Login_As::is_switched()) return $user;
      if (class_exists('EUM_Workforce_Lifecycle') && EUM_Workforce_Lifecycle::terminated_login_allowed((int)$user->ID)) return $user;
      return new WP_Error('eum_terminated_account', 'This account is inactive. Please contact HR or IT if you need to discuss reactivation.');
    }
    return $user;
  }

  public function logout_terminated_user(): void {
    if (!is_user_logged_in()) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (wp_doing_cron()) return;
    if (function_exists('wp_is_json_request') && wp_is_json_request()) return;
    if (!class_exists('EUM_Personnel')) return;

    $user_id = (int)get_current_user_id();
    if ($user_id <= 0 || !EUM_Personnel::is_terminated($user_id)) return;
    if (class_exists('EUM_Login_As') && EUM_Login_As::is_switched()) return;
    if (class_exists('EUM_Workforce_Lifecycle') && EUM_Workforce_Lifecycle::terminated_login_allowed($user_id)) return;

    wp_logout();

    if (!headers_sent()) {
      wp_safe_redirect(add_query_arg('eum_account', 'inactive', home_url('/')));
      exit;
    }
  }

  public function enforce_site_login(): void {
    if (is_user_logged_in()) {
      $user_id = (int)get_current_user_id();
      if (self::must_change_password($user_id)) {
        wp_safe_redirect(self::password_change_url());
        exit;
      }
      return;
    }
    if (is_admin()) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (wp_doing_cron()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (function_exists('wp_is_json_request') && wp_is_json_request()) return;
    if (function_exists('is_customize_preview') && is_customize_preview()) return;
    // Candidate acceptance links must be able to render before the candidate
    // has an account. The landing page performs the invite-code, expiry, and
    // one-time-use checks, while the form submission remains nonce-protected.
    $candidate_invite = isset($_GET['eum_candidate_invite'])
      ? sanitize_text_field(wp_unslash((string)$_GET['eum_candidate_invite']))
      : '';
    if ($candidate_invite !== '') return;
    $candidate_join = function_exists('get_query_var') ? sanitize_text_field((string)get_query_var('eum_candidate_join')) : '';
    if ($candidate_join !== '') return;
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    if (preg_match('#/join/[^/?]+/?(?:\?|$)#i', $request_uri)) return;
    if (apply_filters('eum_allow_public_request', false)) return;

    auth_redirect();
  }

  public function enforce_forced_password_change(): void {
    if (!is_user_logged_in() || !self::must_change_password((int)get_current_user_id())) return;
    if (wp_doing_cron()) return;

    global $pagenow;
    $page = (string)$pagenow;
    $action = sanitize_key((string)wp_unslash($_REQUEST['action'] ?? ''));
    if ($page === 'admin-post.php' && in_array($action, ['eum_force_password_change_form', 'eum_force_password_change'], true)) return;
    if ($page === 'admin-ajax.php') {
      wp_die('Choose a new password to continue.', 'Password change required', ['response' => 403]);
    }

    wp_safe_redirect(self::password_change_url());
    exit;
  }

  public function block_rest_until_password_change($result) {
    if (is_wp_error($result) || !is_user_logged_in() || !self::must_change_password((int)get_current_user_id())) return $result;
    return new WP_Error('eum_password_change_required', 'Choose a new password before using this account.', ['status' => 403]);
  }

  public function filter_login_redirect(string $redirect_to, string $requested_redirect_to, $user): string {
    if (!$user instanceof WP_User || (int)$user->ID <= 0) return $redirect_to;
    $user_id = (int)$user->ID;

    if (self::must_change_password($user_id)) return self::password_change_url();

    $one_time_redirect = (string)get_user_meta($user_id, self::META_LOGIN_REDIRECT_ONCE, true);
    if ($one_time_redirect === '') return $redirect_to;

    delete_user_meta($user_id, self::META_LOGIN_REDIRECT_ONCE);
    return self::validated_internal_redirect($one_time_redirect) ?: $redirect_to;
  }

  public static function set_one_time_login_redirect(int $actor_user_id, int $target_user_id, string $destination, string $reason) {
    if (!get_userdata($target_user_id) || !self::can_manage_login_controls($actor_user_id, $target_user_id)) {
      return new WP_Error('eum_login_redirect_forbidden', 'You cannot change this user’s sign-in destination.');
    }
    $reason = sanitize_textarea_field($reason);
    if ($reason === '') return new WP_Error('eum_login_redirect_reason', 'A reason is required.');
    $destination = self::validated_internal_redirect($destination);
    if ($destination === '') return new WP_Error('eum_login_redirect_invalid', 'Enter a valid destination on this site.');

    update_user_meta($target_user_id, self::META_LOGIN_REDIRECT_ONCE, $destination);
    self::record_login_control_action($target_user_id, $actor_user_id, 'Set one-time sign-in destination to ' . $destination, $reason);
    return true;
  }

  public static function clear_one_time_login_redirect(int $actor_user_id, int $target_user_id, string $reason) {
    if (!get_userdata($target_user_id) || !self::can_manage_login_controls($actor_user_id, $target_user_id)) {
      return new WP_Error('eum_login_redirect_forbidden', 'You cannot change this user’s sign-in destination.');
    }
    $reason = sanitize_textarea_field($reason);
    if ($reason === '') return new WP_Error('eum_login_redirect_reason', 'A reason is required.');

    delete_user_meta($target_user_id, self::META_LOGIN_REDIRECT_ONCE);
    self::record_login_control_action($target_user_id, $actor_user_id, 'Cleared one-time sign-in destination', $reason);
    return true;
  }

  public static function render_user_login_controls(int $actor_user_id, int $target_user_id, string $return_url): string {
    if (!self::can_manage_login_controls($actor_user_id, $target_user_id)) return '';

    $pending_redirect = (string)get_user_meta($target_user_id, self::META_LOGIN_REDIRECT_ONCE, true);
    $force_password_change = self::must_change_password($target_user_id);
    $user = get_userdata($target_user_id);
    $label = $user instanceof WP_User ? ($user->display_name ?: $user->user_login) : ('User #' . $target_user_id);
    $safe_return_url = self::validated_internal_redirect($return_url) ?: home_url('/');

    ob_start();
    echo '<section class="eum-private-profile__section" style="margin-top:24px;">';
    echo '<h4 style="margin-top:0;">Sign-in Settings</h4>';
    echo '<p>Set a one-time destination for the next sign-in, or issue a temporary password that must be changed after the user signs in.</p>';
    if ($force_password_change) echo '<p><strong>Password change required:</strong> this user will be prompted to choose a new password the next time they sign in.</p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:grid;gap:12px;max-width:680px;">';
    echo '<input type="hidden" name="action" value="eum_user_login_controls">';
    echo '<input type="hidden" name="mode" value="redirect">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($safe_return_url) . '">';
    wp_nonce_field('eum_user_login_controls_' . $target_user_id, 'eum_user_login_controls_nonce');
    echo '<label><strong>Next sign-in destination</strong><input type="text" name="redirect_url" value="' . esc_attr($pending_redirect) . '" placeholder="https://internal.example/page/ or /page/" style="display:block;width:100%;max-width:520px;margin-top:4px;"><span class="description">Use a page on this site. It will be used once after the next successful sign-in.</span></label>';
    echo '<label><strong>Reason</strong><input type="text" name="reason" required maxlength="500" style="display:block;width:100%;max-width:520px;margin-top:4px;"></label>';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="submit" name="redirect_action" value="save" class="button button-primary">Save One-time Destination</button>';
    if ($pending_redirect !== '') echo '<button type="submit" name="redirect_action" value="clear" class="button">Clear Destination</button>';
    echo '</div></form>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:grid;gap:12px;max-width:680px;margin-top:22px;padding-top:18px;border-top:1px solid #dcdcde;">';
    echo '<input type="hidden" name="action" value="eum_user_login_controls">';
    echo '<input type="hidden" name="mode" value="temporary_password">';
    echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($safe_return_url) . '">';
    wp_nonce_field('eum_user_login_controls_' . $target_user_id, 'eum_user_login_controls_nonce');
    echo '<label><strong>Temporary password for ' . esc_html($label) . '</strong><input type="password" name="temporary_password" required minlength="12" autocomplete="new-password" style="display:block;width:100%;max-width:360px;margin-top:4px;"><span class="description">At least 12 characters. The password is stored as a hash, and the user must replace it at next sign-in.</span></label>';
    echo '<label><strong>Confirm temporary password</strong><input type="password" name="temporary_password_confirm" required minlength="12" autocomplete="new-password" style="display:block;width:100%;max-width:360px;margin-top:4px;"></label>';
    echo '<label><strong>Reason</strong><input type="text" name="reason" required maxlength="500" style="display:block;width:100%;max-width:520px;margin-top:4px;"></label>';
    echo '<p style="margin:0;"><button type="submit" class="button">Set Temporary Password</button></p>';
    echo '</form></section>';
    return (string)ob_get_clean();
  }

  public function handle_user_login_controls(): void {
    if (!is_user_logged_in()) wp_die('Please sign in.');
    $actor_user_id = (int)get_current_user_id();
    $target_user_id = isset($_POST['target_user_id']) ? absint(wp_unslash($_POST['target_user_id'])) : 0;
    $return_url = self::validated_internal_redirect((string)wp_unslash($_POST['return_url'] ?? '')) ?: home_url('/');
    $mode = sanitize_key((string)wp_unslash($_POST['mode'] ?? ''));

    if ($target_user_id <= 0 || !get_userdata($target_user_id) || !self::can_manage_login_controls($actor_user_id, $target_user_id)) {
      self::redirect_login_controls($return_url, 'You do not have permission to manage this user’s sign-in settings.', 'error');
    }
    if (!isset($_POST['eum_user_login_controls_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_user_login_controls_nonce']), 'eum_user_login_controls_' . $target_user_id)) {
      self::redirect_login_controls($return_url, 'Security check failed.', 'error');
    }

    $reason = sanitize_textarea_field((string)wp_unslash($_POST['reason'] ?? ''));
    if ($reason === '') self::redirect_login_controls($return_url, 'Please enter a reason for this change.', 'error');

    if ($mode === 'redirect') {
      $redirect_action = sanitize_key((string)wp_unslash($_POST['redirect_action'] ?? 'save'));
      if ($redirect_action === 'clear') {
        $result = self::clear_one_time_login_redirect($actor_user_id, $target_user_id, $reason);
        if (is_wp_error($result)) self::redirect_login_controls($return_url, $result->get_error_message(), 'error');
        self::redirect_login_controls($return_url, 'One-time sign-in destination cleared.');
      }

      $raw_redirect = trim((string)wp_unslash($_POST['redirect_url'] ?? ''));
      $result = self::set_one_time_login_redirect($actor_user_id, $target_user_id, $raw_redirect, $reason);
      if (is_wp_error($result)) self::redirect_login_controls($return_url, $result->get_error_message(), 'error');
      self::redirect_login_controls($return_url, 'One-time sign-in destination saved.');
    }

    if ($mode === 'temporary_password') {
      $password = (string)wp_unslash($_POST['temporary_password'] ?? '');
      $password_confirm = (string)wp_unslash($_POST['temporary_password_confirm'] ?? '');
      if (strlen($password) < 12) self::redirect_login_controls($return_url, 'Temporary passwords must be at least 12 characters.', 'error');
      if (!hash_equals($password, $password_confirm)) self::redirect_login_controls($return_url, 'The temporary passwords do not match.', 'error');

      wp_set_password($password, $target_user_id);
      update_user_meta($target_user_id, self::META_FORCE_PASSWORD_CHANGE, '1');
      update_user_meta($target_user_id, self::META_FORCE_PASSWORD_CHANGE_AT, current_time('mysql'));
      self::record_login_control_action($target_user_id, $actor_user_id, 'Set a temporary password and required a change at next sign-in', $reason);
      self::redirect_login_controls($return_url, 'Temporary password set. The user must choose a new password at next sign-in.');
    }

    self::redirect_login_controls($return_url, 'Unknown sign-in setting.', 'error');
  }

  public function render_forced_password_change_form(): void {
    if (!is_user_logged_in()) auth_redirect();
    $user_id = (int)get_current_user_id();
    if (!self::must_change_password($user_id)) {
      wp_safe_redirect(home_url('/'));
      exit;
    }

    status_header(200);
    nocache_headers();
    $user = get_userdata($user_id);
    $label = $user instanceof WP_User ? ($user->display_name ?: $user->user_login) : 'your account';
    echo '<!doctype html><html lang="en"><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Choose a new password</title><style>body{font:16px system-ui,sans-serif;background:#f3f4f6;color:#17202a;margin:0;padding:32px}.box{box-sizing:border-box;max-width:520px;margin:8vh auto;background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:28px;box-shadow:0 8px 28px #00000012}label{display:block;font-weight:600;margin:18px 0 6px}input{box-sizing:border-box;width:100%;padding:11px;border:1px solid #8c8f94;border-radius:6px;font:inherit}button{margin-top:20px;padding:11px 18px;border:0;border-radius:6px;background:#a40000;color:#fff;font:inherit;font-weight:700;cursor:pointer}.hint{color:#50575e;font-size:14px}</style></head><body><main class="box"><h1>Choose a new password</h1><p>Hello ' . esc_html($label) . '. Your manager issued a temporary password. Choose a new password to continue.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_force_password_change">';
    wp_nonce_field('eum_force_password_change_' . $user_id, 'eum_force_password_change_nonce');
    echo '<label for="eum-new-password">New password</label><input id="eum-new-password" type="password" name="new_password" minlength="12" autocomplete="new-password" required><p class="hint">Use at least 12 characters.</p><label for="eum-confirm-password">Confirm new password</label><input id="eum-confirm-password" type="password" name="confirm_password" minlength="12" autocomplete="new-password" required><button type="submit">Save new password</button></form></main></body></html>';
    exit;
  }

  public function handle_forced_password_change(): void {
    if (!is_user_logged_in()) auth_redirect();
    $user_id = (int)get_current_user_id();
    if (!self::must_change_password($user_id)) {
      wp_safe_redirect(home_url('/'));
      exit;
    }
    if (!isset($_POST['eum_force_password_change_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_force_password_change_nonce']), 'eum_force_password_change_' . $user_id)) {
      wp_die('Security check failed. Please return to the password setup page and try again.', 'Password update failed', ['response' => 403]);
    }

    $password = (string)wp_unslash($_POST['new_password'] ?? '');
    $confirm = (string)wp_unslash($_POST['confirm_password'] ?? '');
    if (strlen($password) < 12 || !hash_equals($password, $confirm)) {
      wp_die('Passwords must match and be at least 12 characters. Go back and try again.', 'Password update failed', ['response' => 400]);
    }
    $user = get_userdata($user_id);
    if (!$user instanceof WP_User || wp_check_password($password, $user->user_pass, $user_id)) {
      wp_die('Choose a password different from the temporary password.', 'Password update failed', ['response' => 400]);
    }

    wp_set_password($password, $user_id);
    delete_user_meta($user_id, self::META_FORCE_PASSWORD_CHANGE);
    delete_user_meta($user_id, self::META_FORCE_PASSWORD_CHANGE_AT);
    update_user_meta($user_id, self::META_LAST_PASSWORD_RESET_AT, current_time('mysql'));
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true, is_ssl());
    do_action('eum_forced_password_change_completed', $user_id, $user);
    if (function_exists('eum_log_message')) eum_log_message('[EUM Access] Required temporary password change completed for user #' . $user_id . '.');

    $redirect = (string)get_user_meta($user_id, self::META_LOGIN_REDIRECT_ONCE, true);
    if ($redirect !== '') delete_user_meta($user_id, self::META_LOGIN_REDIRECT_ONCE);
    wp_safe_redirect($redirect !== '' ? (self::validated_internal_redirect($redirect) ?: home_url('/')) : home_url('/'));
    exit;
  }

  private static function can_manage_login_controls(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0 || $actor_user_id === $target_user_id) return false;
    if (self::is_it_or_hr($actor_user_id)) return true;
    return class_exists('EUM_Profiles') && EUM_Profiles::can_edit_target($actor_user_id, $target_user_id);
  }

  private static function validated_internal_redirect(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    $validated = wp_validate_redirect($url, '');
    if ($validated === '') return '';
    if (!preg_match('#^https?://#i', $validated)) {
      $validated = home_url('/' . ltrim($validated, '/'));
    }
    return esc_url_raw($validated);
  }

  private static function password_change_url(): string {
    return add_query_arg('action', 'eum_force_password_change_form', admin_url('admin-post.php'));
  }

  private static function redirect_login_controls(string $return_url, string $message, string $type = 'success'): void {
    $url = self::validated_internal_redirect($return_url) ?: home_url('/');
    wp_safe_redirect(add_query_arg(['eum_profile_msg' => $message, 'eum_profile_type' => $type], $url));
    exit;
  }

  private static function record_login_control_action(int $target_user_id, int $actor_user_id, string $action, string $reason): void {
    $history = get_user_meta($target_user_id, self::META_LOGIN_CONTROL_AUDIT, true);
    $history = is_array($history) ? $history : [];
    $history[] = [
      'actor_user_id' => $actor_user_id,
      'action' => sanitize_text_field($action),
      'reason' => sanitize_textarea_field($reason),
      'at' => current_time('mysql'),
    ];
    update_user_meta($target_user_id, self::META_LOGIN_CONTROL_AUDIT, array_slice($history, -50));
    if (function_exists('eum_log_message')) {
      eum_log_message(sprintf('[EUM Access] User #%d: %s. Reason: %s', $target_user_id, sanitize_text_field($action), sanitize_textarea_field($reason)));
    }
  }

  public static function must_change_password(int $user_id): bool {
    return $user_id > 0 && (string)get_user_meta($user_id, self::META_FORCE_PASSWORD_CHANGE, true) === '1';
  }

  public function enforce_admin_lockout(): void {
    if (!is_user_logged_in()) return;

    global $pagenow;
    $pagenow = (string)$pagenow;

    if (in_array($pagenow, ['admin-ajax.php', 'admin-post.php'], true)) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (wp_doing_cron()) return;
    if (function_exists('wp_is_json_request') && wp_is_json_request()) return;

    if (self::current_user_is_it() || self::current_user_is_finance()) return;

    wp_safe_redirect(home_url('/'));
    exit;
  }

  public function render_access_flags($user): void {
    if (!self::current_user_is_it()) return;

    $target_user_id = isset($user->ID) ? (int)$user->ID : 0;
    if ($target_user_id <= 0) return;

    self::migrate_legacy_access($target_user_id);
    $forced = ($target_user_id === self::forced_root_user_id());

    $rows = [
      ['key' => 'it', 'label' => 'IT Access', 'meta' => self::META_IT, 'enabled' => self::is_it_user($target_user_id), 'desc' => 'Allow this user to access /wp-admin/ and IT-only tools.'],
      ['key' => 'hr', 'label' => 'HR Access', 'meta' => self::META_HR, 'enabled' => self::is_hr_user($target_user_id), 'desc' => 'Allow this user to use HR-only front-end tools.'],
      ['key' => 'finance', 'label' => 'Finance Access', 'meta' => self::META_FINANCE, 'enabled' => self::is_finance_user($target_user_id), 'desc' => 'Allow this user to use the Finance Suite and approve finance records.'],
      ['key' => 'artist_creator', 'label' => 'Artist Creator', 'meta' => self::META_ARTIST_CREATOR, 'enabled' => self::is_artist_creator($target_user_id), 'desc' => 'Allow this user to create artists in Artists & Releases.'],
    ];

    echo '<h2>Enterprise Access</h2>';
    echo '<table class="form-table" role="presentation">';
    foreach ($rows as $row) {
      echo '<tr><th><label for="'.esc_attr($row['meta']).'">'.esc_html($row['label']).'</label></th><td>';
      if ($forced && in_array($row['key'], ['it','hr','finance'], true)) {
        echo '<label><input type="checkbox" checked disabled> Enabled (forced root user)</label>';
        echo '<input type="hidden" name="'.esc_attr($row['meta']).'" value="1">';
      } else {
        echo '<label><input type="checkbox" name="'.esc_attr($row['meta']).'" id="'.esc_attr($row['meta']).'" value="1" '.checked($row['enabled'], true, false).'> '.esc_html($row['desc']).'</label>';
      }
      if ($row['key'] === 'hr') {
        echo '<p class="description">Legacy <code>eum_hr_it_access=1</code> is auto-migrated to both IT and HR.</p>';
      }
      echo '</td></tr>';
    }
    echo '</table>';
  }

  public function save_access_flags(int $user_id): void {
    if (!self::current_user_is_it()) return;

    $forced_user_id = self::forced_root_user_id();
    if ($user_id === $forced_user_id) {
      update_user_meta($user_id, self::META_IT, '1');
      update_user_meta($user_id, self::META_HR, '1');
      update_user_meta($user_id, self::META_FINANCE, '1');
      return;
    }

    update_user_meta($user_id, self::META_IT, isset($_POST[self::META_IT]) ? '1' : '0');
    update_user_meta($user_id, self::META_HR, isset($_POST[self::META_HR]) ? '1' : '0');
    update_user_meta($user_id, self::META_FINANCE, isset($_POST[self::META_FINANCE]) ? '1' : '0');
    update_user_meta($user_id, self::META_ARTIST_CREATOR, isset($_POST[self::META_ARTIST_CREATOR]) ? '1' : '0');
  }

  public static function forced_root_user_id(): int {
    $user_id = (int)apply_filters(self::FILTER_ROOT_USER_ID, 1);
    return $user_id > 0 ? $user_id : 1;
  }

  public static function current_user_is_it(): bool { return self::is_it_user((int)get_current_user_id()); }
  public static function current_user_is_hr(): bool { return self::is_hr_user((int)get_current_user_id()); }
  public static function current_user_is_finance(): bool { return self::is_finance_user((int)get_current_user_id()); }
  public static function current_user_is_artist_creator(): bool { return self::is_artist_creator((int)get_current_user_id()); }
  public static function current_user_is_podcast_manager(): bool { return self::is_podcast_manager((int)get_current_user_id()); }
  public static function current_user_is_podcast_member(): bool { return self::is_podcast_member((int)get_current_user_id()); }
  public static function user_is_it(int $user_id): bool { return self::is_it_user($user_id); }
  public static function user_is_hr(int $user_id): bool { return self::is_hr_user($user_id); }
  public static function user_is_finance(int $user_id): bool { return self::is_finance_user($user_id); }

  public static function is_it_user(int $user_id): bool {
    if ($user_id <= 0) return false;
    self::migrate_legacy_access($user_id);
    if ($user_id === self::forced_root_user_id()) return true;
    return ((string)get_user_meta($user_id, self::META_IT, true) === '1');
  }

  public static function is_hr_user(int $user_id): bool {
    if ($user_id <= 0) return false;
    self::migrate_legacy_access($user_id);
    if ($user_id === self::forced_root_user_id()) return true;
    return ((string)get_user_meta($user_id, self::META_HR, true) === '1');
  }

  public static function is_finance_user(int $user_id): bool {
    if ($user_id <= 0) return false;
    self::migrate_legacy_access($user_id);
    if ($user_id === self::forced_root_user_id()) return true;
    return ((string)get_user_meta($user_id, self::META_FINANCE, true) === '1');
  }

  public static function is_artist_creator(int $user_id): bool {
    if ($user_id <= 0) return false;
    self::migrate_legacy_access($user_id);
    if (self::is_it_user($user_id) || self::is_hr_user($user_id)) return true;
    return ((string)get_user_meta($user_id, self::META_ARTIST_CREATOR, true) === '1');
  }

  public static function is_it_or_hr(int $user_id): bool {
    return self::is_it_user($user_id) || self::is_hr_user($user_id);
  }

  public static function is_podcast_manager(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (self::is_it_or_hr($user_id)) return true;
    return class_exists('EUM_Podcasts') && method_exists('EUM_Podcasts', 'user_is_explicit_podcast_manager_anywhere')
      ? EUM_Podcasts::user_is_explicit_podcast_manager_anywhere($user_id)
      : false;
  }

  public static function is_podcast_member(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (self::is_podcast_manager($user_id)) return true;
    return class_exists('EUM_Podcasts') && method_exists('EUM_Podcasts', 'user_is_podcast_member_anywhere')
      ? EUM_Podcasts::user_is_podcast_member_anywhere($user_id)
      : false;
  }

  public static function is_manager_user(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (self::is_it_or_hr($user_id)) return true;

    if (class_exists('EUM_Manager_IDs') && defined('EUM_Manager_IDs::CPT') && defined('EUM_Manager_IDs::META_MANAGER_USERS')) {
      $mgr_posts = get_posts([
        'post_type' => EUM_Manager_IDs::CPT,
        'post_status' => 'publish',
        'numberposts' => 5000,
        'fields' => 'ids',
      ]);

      foreach ((array)$mgr_posts as $mid) {
        $manager_users = (array)get_post_meta((int)$mid, EUM_Manager_IDs::META_MANAGER_USERS, true);
        $manager_users = array_values(array_filter(array_map('intval', $manager_users)));
        if (in_array($user_id, $manager_users, true)) return true;
      }
    }

    if (class_exists('EUM_Manager_Messages')) {
      foreach (['eum_user_group', 'eum_project'] as $tax) {
        if (!taxonomy_exists($tax)) continue;
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'number' => 0]);
        if (is_wp_error($terms)) continue;
        foreach ($terms as $term) {
          $manager_ids = (array)get_term_meta((int)$term->term_id, EUM_Manager_Messages::TERM_META_MANAGER_IDS, true);
          $manager_ids = array_values(array_filter(array_map('intval', $manager_ids)));
          foreach ($manager_ids as $mid) {
            if (defined('EUM_Manager_IDs::META_MANAGER_USERS')) {
              $manager_users = (array)get_post_meta($mid, EUM_Manager_IDs::META_MANAGER_USERS, true);
              $manager_users = array_values(array_filter(array_map('intval', $manager_users)));
              if (in_array($user_id, $manager_users, true)) return true;
            }
          }
        }
      }
    }

    return false;
  }

  public static function mark_password_setup_required(int $user_id): void {
    if ($user_id <= 0 || !get_userdata($user_id)) return;
    if ((string)get_user_meta($user_id, self::META_FIRST_PASSWORD_SETUP_AT, true) !== '') return;
    update_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED, '1');
    if ((string)get_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED_AT, true) === '') {
      update_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED_AT, current_time('mysql'));
    }
  }

  public static function password_setup_required(int $user_id): bool {
    if ($user_id <= 0) return false;
    return (string)get_user_meta($user_id, self::META_PASSWORD_SETUP_REQUIRED, true) === '1';
  }

  public static function migrate_legacy_access(int $user_id): void {
    if ($user_id <= 0) return;

    if ($user_id === self::forced_root_user_id()) {
      update_user_meta($user_id, self::META_IT, '1');
      update_user_meta($user_id, self::META_HR, '1');
      update_user_meta($user_id, self::META_FINANCE, '1');
      return;
    }

    $legacy = (string)get_user_meta($user_id, self::META_LEGACY, true);
    if ($legacy !== '1') return;

    update_user_meta($user_id, self::META_IT, '1');
    update_user_meta($user_id, self::META_HR, '1');
  }
}
