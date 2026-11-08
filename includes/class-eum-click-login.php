<?php
if (!defined('ABSPATH')) exit;

/**
 * Managed browser-assisted login for apps that do not support SSO.
 * Passwords are encrypted at rest and are returned only to the assigned user's
 * browser extension after an authenticated, nonce-protected request.
 */
class EUM_Click_Login {
  const OPTION_APPS = 'eum_click_login_apps';
  const OPTION_SHARED_CREDENTIALS = 'eum_click_login_shared_credentials';
  const USER_META_ASSIGNMENTS = '_eum_click_login_assignments';
  const CPT_REQUEST = 'eum_app_request';
  const META_STATUS = '_eum_app_request_status';
  const META_TARGET_USER = '_eum_app_request_target_user';
  const META_APP_ID = '_eum_app_request_app_id';
  const META_SHARED_CREDENTIAL_ID = '_eum_app_request_shared_credential_id';
  const META_REQUESTOR = '_eum_app_request_requestor';
  const META_APPROVER_IDS = '_eum_app_request_approver_ids';
  const META_REASON = '_eum_app_request_reason';
  const META_DECISION = '_eum_app_request_decision';
  const META_DECIDED_BY = '_eum_app_request_decided_by';
  const META_DECISION_REASON = '_eum_app_request_decision_reason';
  const REST_NAMESPACE = 'eum-click-login/v1';

  public function init(): void {
    add_action('init', [$this, 'register_request_type'], 8);
    add_action('admin_menu', [$this, 'admin_menu'], 49);
    add_action('admin_post_eum_click_login_save_app', [$this, 'handle_save_app']);
    add_action('admin_post_eum_click_login_delete_app', [$this, 'handle_delete_app']);
    add_action('admin_post_eum_click_login_create_shared', [$this, 'handle_create_shared_credential']);
    add_action('admin_post_eum_click_login_save_user_credential', [$this, 'handle_save_user_credential']);
    add_action('admin_post_eum_click_login_revoke_assignment', [$this, 'handle_revoke_assignment']);
    add_action('admin_post_eum_click_login_disable_shared', [$this, 'handle_disable_shared_credential']);
    add_action('admin_post_eum_click_login_request_shared', [$this, 'handle_request_shared_access']);
    add_action('admin_post_eum_click_login_decide_request', [$this, 'handle_decide_request']);
    add_action('rest_api_init', [$this, 'register_routes']);
    add_filter('eum_portal_app_launcher_items', [$this, 'add_launcher_items'], 40, 2);
    add_shortcode('eum_click_login_manager', [$this, 'render_manager_portal']);
  }

  public function register_request_type(): void {
    register_post_type(self::CPT_REQUEST, [
      'labels' => ['name' => 'App Access Requests', 'singular_name' => 'App Access Request'],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  private static function can_manage_app_catalog(): bool {
    return class_exists('EUM_Access') && EUM_Access::current_user_is_it();
  }

  private static function is_manager_actor(int $user_id): bool {
    if ($user_id <= 0 || !get_user_by('id', $user_id)) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) return true;
    return class_exists('EUM_Access') && EUM_Access::is_manager_user($user_id);
  }

  private static function can_manage_target(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0 || $actor_user_id === $target_user_id) return false;
    if (!get_user_by('id', $target_user_id)) return false;
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($target_user_id)) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) return true;
    if (!class_exists('EUM_Manager_IDs')) return false;
    return in_array($target_user_id, EUM_Manager_IDs::managed_user_ids_for_manager_user($actor_user_id, false, true), true);
  }

  public static function get_apps(): array {
    $raw = get_option(self::OPTION_APPS, []);
    if (!is_array($raw)) return [];
    $apps = [];
    foreach ($raw as $raw_id => $record) {
      if (!is_array($record)) continue;
      $id = sanitize_key((string)($record['id'] ?? $raw_id));
      $label = sanitize_text_field((string)($record['label'] ?? ''));
      $login_url = esc_url_raw((string)($record['login_url'] ?? ''));
      if ($id === '' || $label === '' || !self::is_https_url($login_url)) continue;
      $type = in_array((string)($record['type'] ?? ''), ['shared', 'user'], true) ? (string)$record['type'] : 'user';
      $apps[$id] = [
        'id' => $id,
        'label' => $label,
        'type' => $type,
        'login_url' => $login_url,
        'username_selector' => self::sanitize_selector((string)($record['username_selector'] ?? '')),
        'password_selector' => self::sanitize_selector((string)($record['password_selector'] ?? '')),
        'submit_selector' => self::sanitize_selector((string)($record['submit_selector'] ?? '')),
        'auto_submit' => empty($record['auto_submit']) ? 0 : 1,
        'open_new_tab' => empty($record['open_new_tab']) ? 0 : 1,
        'enabled' => empty($record['enabled']) ? 0 : 1,
      ];
    }
    return $apps;
  }

  private static function is_https_url(string $url): bool {
    $parts = wp_parse_url($url);
    return is_array($parts)
      && strtolower((string)($parts['scheme'] ?? '')) === 'https'
      && !empty($parts['host'])
      && empty($parts['user'])
      && empty($parts['pass']);
  }

  private static function sanitize_selector(string $selector): string {
    $selector = trim(wp_strip_all_tags($selector));
    return substr(preg_replace('/[\r\n\0]+/', '', $selector) ?: '', 0, 250);
  }

  private static function get_shared_credentials(): array {
    $raw = get_option(self::OPTION_SHARED_CREDENTIALS, []);
    return is_array($raw) ? $raw : [];
  }

  private static function get_user_assignments(int $user_id): array {
    $raw = get_user_meta($user_id, self::USER_META_ASSIGNMENTS, true);
    if (!is_array($raw)) return [];
    $normalized = [];
    foreach ($raw as $raw_app_id => $entry) {
      $app_id = sanitize_key((string)$raw_app_id);
      if ($app_id === '' || !is_array($entry)) continue;
      $logins = [];
      if (isset($entry['logins']) && is_array($entry['logins'])) {
        foreach ($entry['logins'] as $raw_login_id => $assignment) {
          if (!is_array($assignment)) continue;
          $login_id = sanitize_key((string)$raw_login_id);
          if ($login_id === '') continue;
          $logins[$login_id] = $assignment;
        }
      } elseif (isset($entry['type'])) {
        // Preserve assignments created before multi-login support.
        $login_id = sanitize_key((string)($entry['assignment_id'] ?? 'legacy'));
        if ($login_id === '') $login_id = 'legacy';
        $logins[$login_id] = $entry;
      } else {
        // Also accept an early nested map written during migration.
        foreach ($entry as $raw_login_id => $assignment) {
          if (!is_array($assignment) || empty($assignment['type'])) continue;
          $login_id = sanitize_key((string)$raw_login_id);
          if ($login_id !== '') $logins[$login_id] = $assignment;
        }
      }
      if (!empty($logins)) $normalized[$app_id] = ['logins' => $logins];
    }
    return $normalized;
  }

  private static function app_login_assignments(array $assignments, string $app_id): array {
    $group = $assignments[$app_id] ?? null;
    return is_array($group) && is_array($group['logins'] ?? null) ? $group['logins'] : [];
  }

  private static function has_shared_login_assignment(int $user_id, string $app_id, string $credential_id): bool {
    foreach (self::app_login_assignments(self::get_user_assignments($user_id), $app_id) as $assignment) {
      if (($assignment['type'] ?? '') === 'shared' && (string)($assignment['credential_id'] ?? '') === $credential_id) return true;
    }
    return false;
  }

  private static function generate_assignment_id(): string {
    try {
      return 'login_' . bin2hex(random_bytes(8));
    } catch (Throwable $error) {
      return '';
    }
  }

  private function add_user_assignment(int $user_id, string $app_id, array $assignment): string {
    $assignments = self::get_user_assignments($user_id);
    if (!is_array($assignments[$app_id]['logins'] ?? null)) $assignments[$app_id] = ['logins' => []];
    $login_id = '';
    for ($attempt = 0; $attempt < 3; $attempt++) {
      $candidate = self::generate_assignment_id();
      if ($candidate === '') return '';
      if (!isset($assignments[$app_id]['logins'][$candidate])) { $login_id = $candidate; break; }
    }
    if ($login_id === '') return '';
    $assignment['label'] = sanitize_text_field((string)($assignment['label'] ?? 'Login'));
    $assignment['assigned_at'] = (string)($assignment['assigned_at'] ?? current_time('mysql'));
    $assignments[$app_id]['logins'][$login_id] = $assignment;
    update_user_meta($user_id, self::USER_META_ASSIGNMENTS, $assignments);
    return $login_id;
  }

  private function remove_user_assignment(int $user_id, string $app_id, string $login_id): bool {
    $assignments = self::get_user_assignments($user_id);
    $logins = self::app_login_assignments($assignments, $app_id);
    if (!isset($logins[$login_id])) return false;
    unset($logins[$login_id]);
    if (empty($logins)) unset($assignments[$app_id]);
    else $assignments[$app_id] = ['logins' => $logins];
    if (empty($assignments)) delete_user_meta($user_id, self::USER_META_ASSIGNMENTS);
    else update_user_meta($user_id, self::USER_META_ASSIGNMENTS, $assignments);
    return true;
  }

  private static function encryption_key(): string {
    return hash_hmac('sha256', 'eum-click-login-credential-v1', wp_salt('secure_auth') . wp_salt('auth'), true);
  }

  private static function encrypt_secret(string $secret) {
    $key = self::encryption_key();
    try {
      if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'sb1:' . base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $key));
      }
      if (function_exists('openssl_encrypt')) {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (is_string($ciphertext) && $ciphertext !== '') return 'gcm1:' . base64_encode($nonce . $tag . $ciphertext);
      }
    } catch (Throwable $error) {
      return false;
    }
    return false;
  }

  private static function decrypt_secret(string $stored) {
    $key = self::encryption_key();
    if (strpos($stored, 'sb1:') === 0 && function_exists('sodium_crypto_secretbox_open')) {
      $packed = base64_decode(substr($stored, 4), true);
      if (!is_string($packed) || strlen($packed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return false;
      $nonce = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
      return sodium_crypto_secretbox_open(substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    }
    if (strpos($stored, 'gcm1:') === 0 && function_exists('openssl_decrypt')) {
      $packed = base64_decode(substr($stored, 5), true);
      if (!is_string($packed) || strlen($packed) <= 28) return false;
      return openssl_decrypt(substr($packed, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($packed, 0, 12), substr($packed, 12, 16));
    }
    return false;
  }

  private static function credentials_for_user(int $user_id, string $app_id, string $login_id = ''): ?array {
    $apps = self::get_apps();
    $app = $apps[$app_id] ?? null;
    if (!is_array($app) || empty($app['enabled'])) return null;
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) return null;
    $login_assignments = self::app_login_assignments(self::get_user_assignments($user_id), $app_id);
    if ($login_id === '') {
      if (count($login_assignments) !== 1) return null;
      $login_id = (string)array_key_first($login_assignments);
    }
    $assignment = $login_assignments[$login_id] ?? null;
    if (!is_array($assignment)) return null;

    $username = '';
    $encrypted = '';
    $account_label = sanitize_text_field((string)($assignment['label'] ?? ''));
    if (($assignment['type'] ?? '') === 'user' && $app['type'] === 'user') {
      $username = sanitize_text_field((string)($assignment['username'] ?? ''));
      $encrypted = (string)($assignment['secret'] ?? '');
    } elseif (($assignment['type'] ?? '') === 'shared' && $app['type'] === 'shared') {
      $credential_id = sanitize_key((string)($assignment['credential_id'] ?? ''));
      $all_shared = self::get_shared_credentials();
      $shared = $all_shared[$app_id][$credential_id] ?? null;
      if (!is_array($shared) || empty($shared['active'])) return null;
      $username = sanitize_text_field((string)($shared['username'] ?? ''));
      $encrypted = (string)($shared['secret'] ?? '');
      $account_label = sanitize_text_field((string)($shared['label'] ?? $account_label));
    }
    if ($username === '' || $encrypted === '') return null;
    $password = self::decrypt_secret($encrypted);
    if (!is_string($password) || $password === '') return null;

    return [
      'app_id' => $app_id,
      'login_id' => $login_id,
      'label' => $app['label'],
      'account_label' => $account_label !== '' ? $account_label : $username,
      'login_url' => $app['login_url'],
      'username_selector' => $app['username_selector'],
      'password_selector' => $app['password_selector'],
      'submit_selector' => $app['submit_selector'],
      'auto_submit' => !empty($app['auto_submit']),
      'username' => $username,
      'password' => $password,
    ];
  }

  public function add_launcher_items(array $items, $user): array {
    if (!$user instanceof WP_User || (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated((int)$user->ID))) return $items;
    $user_id = (int)$user->ID;
    $assignments = self::get_user_assignments($user_id);
    $apps = self::get_apps();
    foreach ($assignments as $app_id => $assignment_group) {
      $app_id = sanitize_key((string)$app_id);
      $app = $apps[$app_id] ?? null;
      if (!is_array($app) || empty($app['enabled'])) continue;
      $launches = [];
      foreach (self::app_login_assignments($assignments, $app_id) as $login_id => $assignment_record) {
        $credentials = self::credentials_for_user($user_id, $app_id, (string)$login_id);
        if (!$credentials) continue;
        $launches[] = [
          'login_id' => (string)$login_id,
          'label' => (string)$credentials['account_label'],
          'url' => (string)$credentials['login_url'],
        ];
      }
      if (count($launches) === 1) {
        $items[] = [
          'label' => $app['label'],
          'url' => $launches[0]['url'],
          'icon' => 'app',
          'new_tab' => true,
          'click_login_id' => $app_id,
          'click_login_assignment_id' => $launches[0]['login_id'],
        ];
      } elseif (count($launches) > 1) {
        $choices = [];
        foreach ($launches as $launch) {
          $choices[] = [
            'label' => $launch['label'],
            'url' => $launch['url'],
            'click_login_id' => $app_id,
            'click_login_assignment_id' => $launch['login_id'],
          ];
        }
        $items[] = [
          'label' => $app['label'],
          'icon' => 'app',
          'choices' => $choices,
          'chooser_title' => 'Choose ' . $app['label'] . ' login',
        ];
      }
    }

    if (self::is_manager_actor($user_id) && class_exists('EUM_Portal_Shell')) {
      $items[] = [
        'label' => 'App Access',
        'url' => EUM_Portal_Shell::page_url('app_access'),
        'icon' => 'app',
        'new_tab' => true,
      ];
    }
    return $items;
  }

  public function register_routes(): void {
    register_rest_route(self::REST_NAMESPACE, '/launch/(?P<app_id>[a-zA-Z0-9_-]+)', [
      'methods' => 'GET',
      'permission_callback' => static function () { return is_user_logged_in(); },
      'callback' => [$this, 'rest_get_launch_credentials'],
    ]);
  }

  public function rest_get_launch_credentials(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $app_id = sanitize_key((string)$request['app_id']);
    $login_id = sanitize_key((string)$request->get_param('login_id'));
    $credentials = self::credentials_for_user($user_id, $app_id, $login_id);
    if (!$credentials) return new WP_Error('eum_click_login_not_assigned', 'This app is not available to your account.', ['status' => 403]);
    $response = rest_ensure_response($credentials);
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    $response->header('Pragma', 'no-cache');
    return $response;
  }

  public function admin_menu(): void {
    add_submenu_page(EUM_Admin::parent_slug('integrations'), 'Click-to-Login Apps', 'Click-to-Login Apps', 'read', 'eum-click-login', [$this, 'render_admin_page']);
  }

  public function render_admin_page(): void {
    if (!self::can_manage_app_catalog()) wp_die('Not allowed.');
    $apps = self::get_apps();
    $users = get_users(['fields' => 'all', 'number' => 5000, 'orderby' => 'display_name', 'order' => 'ASC']);
    $message = isset($_GET['eum_click_msg']) ? sanitize_text_field((string)wp_unslash($_GET['eum_click_msg'])) : '';
    $type = isset($_GET['eum_click_type']) ? sanitize_key((string)wp_unslash($_GET['eum_click_type'])) : 'success';
    echo '<div class="wrap"><h1>Click-to-Login Apps</h1><p>Configure apps and their login forms, then assign shared logins or individual user accounts. Passwords are encrypted in WordPress and are never displayed after saving. Users need the EUM Click-to-Login browser extension.</p>';
    if ($message !== '') echo '<div class="notice ' . ($type === 'error' ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($message) . '</p></div>';
    if (!function_exists('sodium_crypto_secretbox') && !function_exists('openssl_encrypt')) {
      echo '<div class="notice notice-error"><p>Credential storage is unavailable: enable PHP Sodium or OpenSSL before saving passwords.</p></div>';
    }
    echo '<h2>Add an app</h2>';
    $this->render_app_form([], 'eum_click_login_save_app', 'Add App');
    echo '<hr><h2>Configured apps</h2>';
    if (empty($apps)) echo '<p>No click-to-login apps are configured.</p>';
    foreach ($apps as $app) {
      $assigned = $this->users_assigned_to_app((string)$app['id']);
      echo '<details style="max-width:1100px;margin:12px 0;padding:12px 16px;background:#fff;border:1px solid #ccd0d4;border-radius:6px;"><summary><strong>' . esc_html($app['label']) . '</strong> · ' . esc_html($app['type'] === 'shared' ? 'Shared login' : 'User-based') . ' · ' . count($assigned) . ' assigned user(s) · ' . (!empty($app['enabled']) ? 'Enabled' : 'Disabled') . '</summary>';
      $this->render_app_form($app, 'eum_click_login_save_app', 'Save App');
      if ($app['type'] === 'shared') $this->render_shared_credential_admin_section($app);
      else $this->render_user_credential_admin_section($app, $users, $assigned);
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this app and all its user assignments?\');" style="margin-top:14px;">';
      echo '<input type="hidden" name="action" value="eum_click_login_delete_app"><input type="hidden" name="app_id" value="' . esc_attr((string)$app['id']) . '">';
      wp_nonce_field('eum_click_login_delete_app_' . $app['id'], 'eum_click_login_nonce');
      echo '<button class="button button-link-delete" type="submit">Delete App and Assignments</button></form></details>';
    }
    echo '<h2>Browser extension</h2><p>Package location: <code>Plugin/enterprise-user-management/Apps/Click-to-Login-Extension</code>. In Chrome or Edge, open the Extensions page, enable Developer mode, choose <strong>Load unpacked</strong>, and select that folder. Extension permissions are requested for each app domain only when a user first launches that app.</p>';
    echo '</div>';
  }

  private function render_app_form(array $app, string $action, string $button_label): void {
    $app_id = (string)($app['id'] ?? '');
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:1000px;margin:12px 0;">';
    echo '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="app_id" value="' . esc_attr($app_id) . '">';
    wp_nonce_field('eum_click_login_save_app', 'eum_click_login_nonce');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label>App name</label></th><td><input class="regular-text" name="label" required maxlength="100" value="' . esc_attr((string)($app['label'] ?? '')) . '"></td></tr>';
    echo '<tr><th><label>Account type</label></th><td><select name="type"><option value="shared" ' . selected((string)($app['type'] ?? 'shared'), 'shared', false) . '>Shared login (manager approval; HR/IT can provision directly)</option><option value="user" ' . selected((string)($app['type'] ?? ''), 'user', false) . '>User-based login (no approval)</option></select></td></tr>';
    echo '<tr><th><label>Login page URL</label></th><td><input class="large-text code" type="url" name="login_url" required value="' . esc_attr((string)($app['login_url'] ?? '')) . '"><p class="description">HTTPS only. This is the exact login page the extension will open.</p></td></tr>';
    echo '<tr><th><label>Username field CSS selector</label></th><td><input class="regular-text code" name="username_selector" required maxlength="250" value="' . esc_attr((string)($app['username_selector'] ?? '')) . '"></td></tr>';
    echo '<tr><th><label>Password field CSS selector</label></th><td><input class="regular-text code" name="password_selector" required maxlength="250" value="' . esc_attr((string)($app['password_selector'] ?? '')) . '"></td></tr>';
    echo '<tr><th><label>Submit button CSS selector</label></th><td><input class="regular-text code" name="submit_selector" maxlength="250" value="' . esc_attr((string)($app['submit_selector'] ?? '')) . '"><p class="description">Optional. When auto-submit is on, the extension uses this selector or the form’s standard submit button. Email-first login pages are advanced to their password step automatically.</p></td></tr>';
    echo '<tr><th>Launch behavior</th><td><label><input type="checkbox" name="auto_submit" value="1" ' . checked(!empty($app['auto_submit']), true, false) . '> Click the submit button after filling the login form</label><br><label><input type="checkbox" name="enabled" value="1" ' . checked(!array_key_exists('enabled', $app) || !empty($app['enabled']), true, false) . '> Enabled and available for provisioning</label></td></tr>';
    echo '</tbody></table><button class="button button-primary" type="submit">' . esc_html($button_label) . '</button></form>';
  }

  private function render_shared_credential_admin_section(array $app): void {
    $all = self::get_shared_credentials();
    $credentials = is_array($all[$app['id']] ?? null) ? $all[$app['id']] : [];
    echo '<h3>Shared logins</h3><p class="description">Shared passwords are never shown again after saving. Managers can create shared logins from the App Access page and request access for their users.</p>';
    if (empty($credentials)) { echo '<p>No shared logins have been created yet.</p>'; return; }
    echo '<table class="widefat striped" style="max-width:950px;"><thead><tr><th>Login label</th><th>Created by</th><th>Status</th><th>Action</th></tr></thead><tbody>';
    foreach ($credentials as $credential_id => $credential) {
      if (!is_array($credential)) continue;
      $creator = get_user_by('id', (int)($credential['created_by'] ?? 0));
      echo '<tr><td>' . esc_html((string)($credential['label'] ?? 'Shared login')) . '</td><td>' . esc_html($creator ? (string)$creator->display_name : 'Unknown') . '</td><td>' . (!empty($credential['active']) ? 'Available' : 'Disabled') . '</td><td>';
      if (!empty($credential['active'])) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Disable this shared login and revoke its access?\');"><input type="hidden" name="action" value="eum_click_login_disable_shared"><input type="hidden" name="app_id" value="' . esc_attr((string)$app['id']) . '"><input type="hidden" name="credential_id" value="' . esc_attr((string)$credential_id) . '">';
        wp_nonce_field('eum_click_login_disable_shared_' . $app['id'] . '_' . $credential_id, 'eum_click_login_nonce');
        echo '<button class="button button-small" type="submit">Disable and revoke</button></form>';
      }
      echo '</td></tr>';
    }
    echo '</tbody></table>';
  }

  private function render_user_credential_admin_section(array $app, array $users, array $assigned): void {
    echo '<h3>User account assignments</h3><p class="description">Add another labeled login for a user. Passwords are encrypted and cannot be retrieved from this form.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px;">';
    echo '<input type="hidden" name="action" value="eum_click_login_save_user_credential"><input type="hidden" name="app_id" value="' . esc_attr((string)$app['id']) . '">';
    wp_nonce_field('eum_click_login_save_user_credential', 'eum_click_login_nonce');
    echo '<p><label>User <select name="user_id" required><option value="">Select a user</option>';
    foreach ($users as $user) {
      if (!($user instanceof WP_User)) continue;
      $label = ($user->display_name !== '' ? $user->display_name : $user->user_login) . ' · ' . $user->user_login;
      echo '<option value="' . (int)$user->ID . '">' . esc_html($label) . '</option>';
    }
    echo '</select></label></p><p><label>Login label <input class="regular-text" name="login_label" required maxlength="100" placeholder="Work account"></label></p><p><label>Username or email <input class="regular-text" name="username" required maxlength="190"></label></p><p><label>Password <input type="password" name="password" required autocomplete="new-password" maxlength="1024"></label></p><p><label>Reason <input class="regular-text" name="reason" required maxlength="500"></label></p><button class="button" type="submit">Add user login</button></form>';
    if (empty($assigned)) return;
    echo '<table class="widefat striped" style="max-width:950px;margin-top:12px;"><thead><tr><th>User</th><th>Login label</th><th>Login name</th><th>Action</th></tr></thead><tbody>';
    foreach ($assigned as $row) {
      $user = $row['user'];
      $assignment = $row['assignment'];
      echo '<tr><td>' . esc_html($user->display_name ?: $user->user_login) . '</td><td>' . esc_html((string)($assignment['label'] ?? 'Login')) . '</td><td>' . esc_html((string)($assignment['username'] ?? '')) . '</td><td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_click_login_revoke_assignment"><input type="hidden" name="user_id" value="' . (int)$user->ID . '"><input type="hidden" name="app_id" value="' . esc_attr((string)$app['id']) . '"><input type="hidden" name="login_id" value="' . esc_attr((string)$row['assignment_id']) . '">';
      wp_nonce_field('eum_click_login_revoke_' . $app['id'] . '_' . (int)$user->ID . '_' . $row['assignment_id'], 'eum_click_login_nonce');
      echo '<button class="button button-small" type="submit">Revoke</button></form></td></tr>';
    }
    echo '</tbody></table>';
  }

  private function users_assigned_to_app(string $app_id): array {
    $users = get_users(['meta_key' => self::USER_META_ASSIGNMENTS, 'fields' => 'all', 'number' => 5000]);
    $out = [];
    foreach ($users as $user) {
      if (!($user instanceof WP_User)) continue;
      $assignments = self::get_user_assignments((int)$user->ID);
      foreach (self::app_login_assignments($assignments, $app_id) as $assignment_id => $assignment) {
        if (is_array($assignment)) $out[] = ['user' => $user, 'assignment' => $assignment, 'assignment_id' => (string)$assignment_id];
      }
    }
    return $out;
  }

  public function handle_save_app(): void {
    if (!self::can_manage_app_catalog()) wp_die('Not allowed.');
    check_admin_referer('eum_click_login_save_app', 'eum_click_login_nonce');
    $app_id = sanitize_key((string)wp_unslash($_POST['app_id'] ?? ''));
    $label = sanitize_text_field((string)wp_unslash($_POST['label'] ?? ''));
    $type = sanitize_key((string)wp_unslash($_POST['type'] ?? ''));
    $login_url = esc_url_raw((string)wp_unslash($_POST['login_url'] ?? ''));
    $username_selector = self::sanitize_selector((string)wp_unslash($_POST['username_selector'] ?? ''));
    $password_selector = self::sanitize_selector((string)wp_unslash($_POST['password_selector'] ?? ''));
    $submit_selector = self::sanitize_selector((string)wp_unslash($_POST['submit_selector'] ?? ''));
    if ($label === '' || !in_array($type, ['shared', 'user'], true) || !self::is_https_url($login_url) || $username_selector === '' || $password_selector === '') {
      $this->admin_redirect('App details are invalid. Use an HTTPS login URL and valid field selectors.', 'error');
    }
    $apps = self::get_apps();
    if ($app_id === '') {
      try { $app_id = 'app_' . bin2hex(random_bytes(8)); }
      catch (Throwable $error) { $this->admin_redirect('Could not create a secure app identifier.', 'error'); }
    } elseif (!isset($apps[$app_id])) {
      $this->admin_redirect('That app no longer exists.', 'error');
    }
    $shared_credentials = self::get_shared_credentials();
    if (isset($apps[$app_id]) && (string)$apps[$app_id]['type'] !== $type
      && (!empty($this->users_assigned_to_app($app_id)) || !empty($shared_credentials[$app_id]) || $this->app_has_pending_requests($app_id))) {
      $this->admin_redirect('Remove existing assignments, shared logins, and pending requests before changing an app’s account type.', 'error');
    }
    $apps[$app_id] = [
      'id' => $app_id,
      'label' => $label,
      'type' => $type,
      'login_url' => $login_url,
      'username_selector' => $username_selector,
      'password_selector' => $password_selector,
      'submit_selector' => $submit_selector,
      'auto_submit' => empty($_POST['auto_submit']) ? 0 : 1,
      'open_new_tab' => 1,
      'enabled' => empty($_POST['enabled']) ? 0 : 1,
    ];
    update_option(self::OPTION_APPS, $apps, false);
    $this->admin_redirect('App saved.');
  }

  public function handle_delete_app(): void {
    if (!self::can_manage_app_catalog()) wp_die('Not allowed.');
    $app_id = sanitize_key((string)wp_unslash($_POST['app_id'] ?? ''));
    check_admin_referer('eum_click_login_delete_app_' . $app_id, 'eum_click_login_nonce');
    $apps = self::get_apps();
    if (!isset($apps[$app_id])) $this->admin_redirect('App not found.', 'error');
    foreach ($this->users_assigned_to_app($app_id) as $row) {
      $user_id = (int)$row['user']->ID;
      $assignments = self::get_user_assignments($user_id);
      unset($assignments[$app_id]);
      if (empty($assignments)) delete_user_meta($user_id, self::USER_META_ASSIGNMENTS);
      else update_user_meta($user_id, self::USER_META_ASSIGNMENTS, $assignments);
    }
    unset($apps[$app_id]);
    update_option(self::OPTION_APPS, $apps, false);
    $shared = self::get_shared_credentials();
    unset($shared[$app_id]);
    update_option(self::OPTION_SHARED_CREDENTIALS, $shared, false);
    $this->admin_redirect('App and its user assignments were deleted.');
  }

  public function handle_create_shared_credential(): void {
    $actor_user_id = $this->require_manager_actor();
    check_admin_referer('eum_click_login_create_shared', 'eum_click_login_nonce');
    $app_id = sanitize_key((string)wp_unslash($_POST['app_id'] ?? ''));
    $apps = self::get_apps();
    $app = $apps[$app_id] ?? null;
    $label = trim(sanitize_text_field((string)wp_unslash($_POST['label'] ?? '')));
    $username = trim(sanitize_text_field((string)wp_unslash($_POST['username'] ?? '')));
    $password = str_replace("\0", '', (string)wp_unslash($_POST['password'] ?? ''));
    if (!is_array($app) || empty($app['enabled']) || $app['type'] !== 'shared' || $label === '' || strlen($label) > 100 || $username === '' || strlen($username) > 190 || $password === '' || strlen($password) > 1024) {
      $this->front_redirect('Shared login details are incomplete.', 'error');
    }
    $encrypted = self::encrypt_secret($password);
    if (!is_string($encrypted)) $this->front_redirect('Secure password storage is unavailable. Contact IT.', 'error');
    $all = self::get_shared_credentials();
    if (!is_array($all[$app_id] ?? null)) $all[$app_id] = [];
    foreach ($all[$app_id] as $existing) {
      if (is_array($existing) && strtolower((string)($existing['label'] ?? '')) === strtolower($label)) {
        $this->front_redirect('A shared login already uses that label for this app. Choose a different label.', 'error');
      }
    }
    try { $credential_id = 'login_' . bin2hex(random_bytes(8)); }
    catch (Throwable $error) { $this->front_redirect('Could not create a secure login identifier.', 'error'); }
    $all[$app_id][$credential_id] = [
      'id' => $credential_id,
      'label' => $label,
      'username' => $username,
      'secret' => $encrypted,
      'created_by' => $actor_user_id,
      'created_at' => current_time('mysql'),
      'active' => 1,
    ];
    update_option(self::OPTION_SHARED_CREDENTIALS, $all, false);
    $this->front_redirect('Shared login created. Its password will not be shown again.');
  }

  public function handle_save_user_credential(): void {
    $actor_user_id = $this->require_manager_actor();
    check_admin_referer('eum_click_login_save_user_credential', 'eum_click_login_nonce');
    $app_id = sanitize_key((string)wp_unslash($_POST['app_id'] ?? ''));
    $apps = self::get_apps();
    $app = $apps[$app_id] ?? null;
    if (!is_array($app) || empty($app['enabled']) || $app['type'] !== 'user') $this->front_redirect('Select an enabled user-based app.', 'error');
    $reason = sanitize_textarea_field((string)wp_unslash($_POST['reason'] ?? ''));
    $batch_rows = (string)wp_unslash($_POST['bulk_rows'] ?? '');
    if ($batch_rows !== '') {
      if ($reason === '' || strlen($reason) > 500) $this->front_redirect('Enter a reason of 500 characters or fewer for bulk provisioning.', 'error');
      if (strlen($batch_rows) > 300000) $this->front_redirect('The bulk list is too large. Submit up to 200 users at a time.', 'error');
      $lines = preg_split('/\r\n|\r|\n/', $batch_rows) ?: [];
      $validated = [];
      $labels_by_user = [];
      $auto_labels_by_user = [];
      foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $columns = str_getcsv($line, "\t", '"', '');
        if (!in_array(count($columns), [3, 4], true)) $this->front_redirect('Bulk rows must have three or four tab-separated columns: user login or email, optional login label, app username, and password.', 'error');
        $identifier = trim((string)$columns[0]);
        if (count($columns) === 4) {
          $login_label = sanitize_text_field(trim((string)$columns[1]));
          $username = sanitize_text_field(trim((string)$columns[2]));
          $password = str_replace("\0", '', (string)$columns[3]);
        } else {
          $login_label = '';
          $username = sanitize_text_field(trim((string)$columns[1]));
          $password = str_replace("\0", '', (string)$columns[2]);
        }
        $target = get_user_by('login', $identifier);
        if (!$target) $target = get_user_by('email', sanitize_email($identifier));
        if (!$target || $username === '' || strlen($username) > 190 || $password === '' || strlen($password) > 1024 || strlen($login_label) > 100 || !self::can_manage_target($actor_user_id, (int)$target->ID)) {
          $this->front_redirect('A bulk row has an unknown user, missing credentials, or a user outside your scope. No credentials were saved.', 'error');
        }
        $target_id = (int)$target->ID;
        if ($login_label === '') {
          $auto_labels_by_user[$target_id] = (int)($auto_labels_by_user[$target_id] ?? 0) + 1;
          $login_label = 'Login ' . $auto_labels_by_user[$target_id];
        }
        $label_key = strtolower($login_label);
        $existing_labels = [];
        foreach (self::app_login_assignments(self::get_user_assignments($target_id), $app_id) as $existing_assignment) {
          if (is_array($existing_assignment)) $existing_labels[] = strtolower((string)($existing_assignment['label'] ?? 'Login'));
        }
        if (in_array($label_key, $existing_labels, true) || in_array($label_key, $labels_by_user[$target_id] ?? [], true)) {
          $this->front_redirect('Login labels must be unique for each user and app. No credentials were saved.', 'error');
        }
        $encrypted = self::encrypt_secret($password);
        if (!is_string($encrypted)) $this->front_redirect('Secure password storage is unavailable. No credentials were saved.', 'error');
        $validated[] = ['user_id' => $target_id, 'label' => $login_label, 'username' => $username, 'secret' => $encrypted];
        $labels_by_user[$target_id][] = $label_key;
        if (count($validated) > 200) $this->front_redirect('Bulk provisioning is limited to 200 login rows per submission.', 'error');
      }
      if (empty($validated)) $this->front_redirect('Add at least one bulk credential row.', 'error');
      foreach ($validated as $credential) {
        $saved_id = $this->add_user_assignment((int)$credential['user_id'], $app_id, [
          'type' => 'user',
          'label' => $credential['label'],
          'username' => $credential['username'],
          'secret' => $credential['secret'],
          'assigned_by' => $actor_user_id,
          'assigned_at' => current_time('mysql'),
          'reason' => $reason,
        ]);
        if ($saved_id === '') $this->front_redirect('Could not create a secure login assignment. Check current app access before retrying the bulk list.', 'error');
      }
      $this->front_redirect(sprintf('User-based app access provisioned immediately for %d login(s).', count($validated)));
    }

    $target_user_id = (int)($_POST['user_id'] ?? 0);
    $login_label = trim(sanitize_text_field((string)wp_unslash($_POST['login_label'] ?? '')));
    $username = trim(sanitize_text_field((string)wp_unslash($_POST['username'] ?? '')));
    $password = str_replace("\0", '', (string)wp_unslash($_POST['password'] ?? ''));
    if (!self::can_manage_target($actor_user_id, $target_user_id) || $login_label === '' || strlen($login_label) > 100 || $username === '' || strlen($username) > 190 || $password === '' || strlen($password) > 1024 || $reason === '' || strlen($reason) > 500) {
      $this->front_redirect('You cannot assign that user-based app, or the details are incomplete.', 'error');
    }
    foreach (self::app_login_assignments(self::get_user_assignments($target_user_id), $app_id) as $existing_assignment) {
      if (is_array($existing_assignment) && strtolower((string)($existing_assignment['label'] ?? '')) === strtolower($login_label)) {
        $this->front_redirect('That user already has a login with this label for the selected app.', 'error');
      }
    }
    $encrypted = self::encrypt_secret($password);
    if (!is_string($encrypted)) $this->front_redirect('Secure password storage is unavailable. Contact IT.', 'error');
    $saved_id = $this->add_user_assignment($target_user_id, $app_id, [
      'type' => 'user',
      'label' => $login_label,
      'username' => $username,
      'secret' => $encrypted,
      'assigned_by' => $actor_user_id,
      'assigned_at' => current_time('mysql'),
      'reason' => $reason,
    ]);
    if ($saved_id === '') $this->front_redirect('Could not create a secure login assignment. Please retry.', 'error');
    $this->front_redirect('User-based app login was provisioned.');
  }

  public function handle_request_shared_access(): void {
    $actor_user_id = $this->require_manager_actor();
    check_admin_referer('eum_click_login_request_shared', 'eum_click_login_nonce');
    $raw_user_ids = isset($_POST['user_ids']) ? (array)wp_unslash($_POST['user_ids']) : [(int)($_POST['user_id'] ?? 0)];
    $target_user_ids = array_values(array_unique(array_filter(array_map('intval', $raw_user_ids), static function ($id) { return $id > 0; })));
    $shared_ref = sanitize_text_field((string)wp_unslash($_POST['shared_ref'] ?? ''));
    $parts = explode('|', $shared_ref, 2);
    $app_id = sanitize_key((string)($parts[0] ?? ''));
    $credential_id = sanitize_key((string)($parts[1] ?? ''));
    $reason = sanitize_textarea_field((string)wp_unslash($_POST['reason'] ?? ''));
    $apps = self::get_apps();
    $app = $apps[$app_id] ?? null;
    $all = self::get_shared_credentials();
    $shared = $all[$app_id][$credential_id] ?? null;
    $is_it_or_hr = class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id);
    if (empty($target_user_ids) || count($target_user_ids) > 200 || !is_array($app) || empty($app['enabled']) || $app['type'] !== 'shared' || !is_array($shared) || empty($shared['active']) || $reason === '' || strlen($reason) > 500) {
      $this->front_redirect('Select up to 200 users, an available shared login, and enter a reason.', 'error');
    }

    // Validate every target before changing assignments or creating approval requests.
    foreach ($target_user_ids as $target_user_id) {
      if (!self::can_manage_target($actor_user_id, $target_user_id)) $this->front_redirect('One or more selected users are outside your provisioning scope.', 'error');
      if (self::has_shared_login_assignment($target_user_id, $app_id, $credential_id)) $this->front_redirect('One or more selected users already have this shared login.', 'error');
      if (!$is_it_or_hr && $this->has_open_shared_request($target_user_id, $app_id, $credential_id)) $this->front_redirect('A request for this shared login is already pending for one or more selected users.', 'error');
      if (!$is_it_or_hr && empty($this->approver_ids_for_request($actor_user_id, $target_user_id))) $this->front_redirect('No manager approver could be resolved for one or more users. Ask IT or HR to review the manager assignment.', 'error');
    }

    $provisioned = 0;
    $requested = 0;
    foreach ($target_user_ids as $target_user_id) {
      if ($is_it_or_hr) {
        $saved_id = $this->add_user_assignment($target_user_id, $app_id, [
          'type' => 'shared',
          'label' => (string)($shared['label'] ?? 'Shared login'),
          'credential_id' => $credential_id,
          'assigned_by' => $actor_user_id,
          'assigned_at' => current_time('mysql'),
          'reason' => $reason,
        ]);
        if ($saved_id === '') $this->front_redirect('Could not create a secure login assignment. Check current app access before retrying.', 'error');
        $this->supersede_pending_shared_requests($target_user_id, $app_id, $actor_user_id, $credential_id);
        $provisioned++;
        continue;
      }

      $post_id = $this->create_shared_access_request($actor_user_id, $target_user_id, $app_id, $credential_id, (string)$app['label'], (string)($shared['label'] ?? 'Shared login'), $reason);
      if (is_wp_error($post_id)) $this->front_redirect('Could not create every approval request. Review the request list before retrying.', 'error');
      $requested++;
    }

    if ($is_it_or_hr) $this->front_redirect(sprintf('Shared app access provisioned immediately for %d user(s); no approval was required.', $provisioned));
    $this->front_redirect(sprintf('Approval requests sent for %d user(s). Each selected user requires a separate manager decision.', $requested));
  }

  private function create_shared_access_request(int $actor_user_id, int $target_user_id, string $app_id, string $credential_id, string $app_label, string $login_label, string $reason) {
    $approver_ids = $this->approver_ids_for_request($actor_user_id, $target_user_id);
    if (empty($approver_ids)) return new WP_Error('eum_click_login_no_approver', 'No manager approver could be resolved.');
    $target = get_user_by('id', $target_user_id);
    $post_id = wp_insert_post([
      'post_type' => self::CPT_REQUEST,
      'post_status' => 'publish',
      'post_title' => sprintf('%s · %s access for %s', $app_label, $login_label, $target ? ($target->display_name ?: $target->user_login) : 'user'),
      'post_author' => $actor_user_id,
    ], true);
    if (is_wp_error($post_id) || (int)$post_id <= 0) return is_wp_error($post_id) ? $post_id : new WP_Error('eum_click_login_request_failed', 'Could not create the approval request.');
    update_post_meta((int)$post_id, self::META_STATUS, 'pending');
    update_post_meta((int)$post_id, self::META_TARGET_USER, $target_user_id);
    update_post_meta((int)$post_id, self::META_APP_ID, $app_id);
    update_post_meta((int)$post_id, self::META_SHARED_CREDENTIAL_ID, $credential_id);
    update_post_meta((int)$post_id, self::META_REQUESTOR, $actor_user_id);
    update_post_meta((int)$post_id, self::META_APPROVER_IDS, $approver_ids);
    update_post_meta((int)$post_id, self::META_REASON, $reason);
    return (int)$post_id;
  }

  private function approver_ids_for_request(int $actor_user_id, int $target_user_id): array {
    $approvers = [];
    if (class_exists('EUM_Profiles')) $approvers = EUM_Profiles::approver_ids_for_actor($actor_user_id, $target_user_id);
    if (empty($approvers) && class_exists('EUM_Manager_IDs')) {
      $approvers = EUM_Manager_IDs::manager_user_ids_for_subject_user($target_user_id);
      $approvers = array_values(array_diff(array_map('intval', (array)$approvers), [$actor_user_id, $target_user_id]));
    }
    if (empty($approvers) && class_exists('EUM_Access')) {
      $root_id = EUM_Access::forced_root_user_id();
      if ($root_id > 0 && $root_id !== $actor_user_id && $root_id !== $target_user_id) $approvers = [$root_id];
    }
    return array_values(array_unique(array_filter(array_map('intval', (array)$approvers), static function ($id) { return $id > 0; })));
  }

  private function has_open_shared_request(int $target_user_id, string $app_id, string $credential_id): bool {
    return !empty($this->pending_shared_requests($target_user_id, $app_id, $credential_id));
  }

  private function pending_shared_requests(int $target_user_id, string $app_id, string $credential_id = ''): array {
    $meta_query = [
      'relation' => 'AND',
      ['key' => self::META_APP_ID, 'value' => $app_id],
      ['key' => self::META_STATUS, 'value' => 'pending'],
    ];
    if ($target_user_id > 0) $meta_query[] = ['key' => self::META_TARGET_USER, 'value' => $target_user_id];
    if ($credential_id !== '') $meta_query[] = ['key' => self::META_SHARED_CREDENTIAL_ID, 'value' => $credential_id];
    return get_posts([
      'post_type' => self::CPT_REQUEST,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'meta_query' => $meta_query,
    ]);
  }

  private function supersede_pending_shared_requests(int $target_user_id, string $app_id, int $actor_user_id, string $credential_id = ''): void {
    foreach ($this->pending_shared_requests($target_user_id, $app_id, $credential_id) as $request) {
      $request_id = (int)$request->ID;
      update_post_meta($request_id, self::META_STATUS, 'superseded');
      update_post_meta($request_id, self::META_DECISION, 'provisioned');
      update_post_meta($request_id, self::META_DECIDED_BY, $actor_user_id);
      update_post_meta($request_id, self::META_DECISION_REASON, 'Provisioned directly by HR/IT.');
    }
  }

  private function app_has_pending_requests(string $app_id): bool {
    return !empty($this->pending_shared_requests(0, $app_id));
  }

  public function handle_decide_request(): void {
    $actor_user_id = $this->require_manager_actor();
    $request_id = (int)($_POST['request_id'] ?? 0);
    check_admin_referer('eum_click_login_decide_' . $request_id, 'eum_click_login_nonce');
    $request = get_post($request_id);
    if (!$request instanceof WP_Post || $request->post_type !== self::CPT_REQUEST || (string)get_post_meta($request_id, self::META_STATUS, true) !== 'pending' || !$this->can_approve_request($request_id, $actor_user_id)) {
      $this->front_redirect('You cannot review this app access request.', 'error');
    }
    $decision = sanitize_key((string)wp_unslash($_POST['decision'] ?? ''));
    $decision_reason = sanitize_textarea_field((string)wp_unslash($_POST['decision_reason'] ?? ''));
    if (!in_array($decision, ['approve', 'deny'], true)) $this->front_redirect('Choose approve or deny.', 'error');
    $target_user_id = (int)get_post_meta($request_id, self::META_TARGET_USER, true);
    $app_id = sanitize_key((string)get_post_meta($request_id, self::META_APP_ID, true));
    $credential_id = sanitize_key((string)get_post_meta($request_id, self::META_SHARED_CREDENTIAL_ID, true));
    if ($decision === 'approve') {
      $apps = self::get_apps();
      $all = self::get_shared_credentials();
      $shared = $all[$app_id][$credential_id] ?? null;
      if (!self::can_manage_target($actor_user_id, $target_user_id) && !(class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id))) {
        $this->front_redirect('The target is no longer in your manager scope.', 'error');
      }
      if (!isset($apps[$app_id]) || $apps[$app_id]['type'] !== 'shared' || empty($apps[$app_id]['enabled']) || !is_array($shared) || empty($shared['active'])) {
        $this->front_redirect('The app or shared login is no longer available.', 'error');
      }
      if (!self::has_shared_login_assignment($target_user_id, $app_id, $credential_id)) {
        $saved_id = $this->add_user_assignment($target_user_id, $app_id, [
          'type' => 'shared',
          'label' => (string)($shared['label'] ?? 'Shared login'),
          'credential_id' => $credential_id,
          'assigned_by' => (int)get_post_meta($request_id, self::META_REQUESTOR, true),
          'approved_by' => $actor_user_id,
          'assigned_at' => current_time('mysql'),
          'reason' => (string)get_post_meta($request_id, self::META_REASON, true),
        ]);
        if ($saved_id === '') $this->front_redirect('Could not create a secure login assignment. The approval remains pending; please retry.', 'error');
      }
      update_post_meta($request_id, self::META_STATUS, 'approved');
    } else {
      update_post_meta($request_id, self::META_STATUS, 'denied');
    }
    update_post_meta($request_id, self::META_DECISION, $decision);
    update_post_meta($request_id, self::META_DECIDED_BY, $actor_user_id);
    update_post_meta($request_id, self::META_DECISION_REASON, $decision_reason);
    $this->front_redirect($decision === 'approve' ? 'Shared app access approved and provisioned.' : 'Shared app access request denied.');
  }

  private function can_approve_request(int $request_id, int $actor_user_id): bool {
    $approvers = array_values(array_map('intval', (array)get_post_meta($request_id, self::META_APPROVER_IDS, true)));
    if (in_array($actor_user_id, $approvers, true)) return true;
    return empty($approvers) && class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id);
  }

  public function handle_revoke_assignment(): void {
    $actor_user_id = $this->require_manager_actor();
    $target_user_id = (int)($_POST['user_id'] ?? 0);
    $app_id = sanitize_key((string)wp_unslash($_POST['app_id'] ?? ''));
    $login_id = sanitize_key((string)wp_unslash($_POST['login_id'] ?? ''));
    check_admin_referer('eum_click_login_revoke_' . $app_id . '_' . $target_user_id . ($login_id !== '' ? '_' . $login_id : ''), 'eum_click_login_nonce');
    if (!self::can_manage_target($actor_user_id, $target_user_id) && !self::can_manage_app_catalog()) $this->front_redirect('You cannot revoke access for that user.', 'error');
    if ($login_id !== '') {
      if (!$this->remove_user_assignment($target_user_id, $app_id, $login_id)) $this->front_redirect('No matching app login was found.', 'error');
    } else {
      $assignments = self::get_user_assignments($target_user_id);
      if (!isset($assignments[$app_id])) $this->front_redirect('No app assignment was found.', 'error');
      unset($assignments[$app_id]);
      if (empty($assignments)) delete_user_meta($target_user_id, self::USER_META_ASSIGNMENTS);
      else update_user_meta($target_user_id, self::USER_META_ASSIGNMENTS, $assignments);
    }
    $this->front_redirect('App login access revoked.');
  }

  public function handle_disable_shared_credential(): void {
    $actor_user_id = $this->require_manager_actor();
    $app_id = sanitize_key((string)wp_unslash($_POST['app_id'] ?? ''));
    $credential_id = sanitize_key((string)wp_unslash($_POST['credential_id'] ?? ''));
    check_admin_referer('eum_click_login_disable_shared_' . $app_id . '_' . $credential_id, 'eum_click_login_nonce');
    $all = self::get_shared_credentials();
    $credential = $all[$app_id][$credential_id] ?? null;
    if (!is_array($credential) || ((int)($credential['created_by'] ?? 0) !== $actor_user_id && !self::can_manage_app_catalog())) {
      $this->front_redirect('You cannot disable that shared login.', 'error');
    }
    $all[$app_id][$credential_id]['active'] = 0;
    update_option(self::OPTION_SHARED_CREDENTIALS, $all, false);
    foreach ($this->pending_shared_requests(0, $app_id, $credential_id) as $request) {
      $request_id = (int)$request->ID;
      update_post_meta($request_id, self::META_STATUS, 'canceled');
      update_post_meta($request_id, self::META_DECISION, 'canceled');
      update_post_meta($request_id, self::META_DECIDED_BY, $actor_user_id);
      update_post_meta($request_id, self::META_DECISION_REASON, 'The shared login was disabled.');
    }
    foreach ($this->users_assigned_to_app($app_id) as $row) {
      $assignment = $row['assignment'];
      if (($assignment['type'] ?? '') !== 'shared' || (string)($assignment['credential_id'] ?? '') !== $credential_id) continue;
      $user_id = (int)$row['user']->ID;
      $assignments = self::get_user_assignments($user_id);
      $this->remove_user_assignment($user_id, $app_id, (string)$row['assignment_id']);
    }
    $this->front_redirect('Shared login disabled and its app access revoked.');
  }

  private function require_manager_actor(): int {
    $actor_user_id = get_current_user_id();
    if (!is_user_logged_in() || !self::is_manager_actor($actor_user_id)) wp_die('Not allowed.');
    return $actor_user_id;
  }

  public function render_manager_portal(): string {
    if (!is_user_logged_in()) return '<div class="eum-click-login"><p>Please sign in to manage app access.</p></div>';
    $actor_user_id = get_current_user_id();
    if (!self::is_manager_actor($actor_user_id)) return '<div class="eum-click-login"><p>App access management is available to managers, HR, and IT.</p></div>';

    $target_users = $this->managed_users_for_actor($actor_user_id);
    $apps = array_filter(self::get_apps(), static function ($app) { return !empty($app['enabled']); });
    $shared_refs = [];
    $shared_all = self::get_shared_credentials();
    foreach ($apps as $app_id => $app) {
      if ($app['type'] !== 'shared') continue;
      foreach ((array)($shared_all[$app_id] ?? []) as $credential_id => $credential) {
        if (!is_array($credential) || empty($credential['active'])) continue;
        $shared_refs[] = ['value' => $app_id . '|' . $credential_id, 'label' => $app['label'] . ' · ' . ($credential['label'] ?? 'Shared login')];
      }
    }

    $message = isset($_GET['eum_click_msg']) ? sanitize_text_field((string)wp_unslash($_GET['eum_click_msg'])) : '';
    $message_type = isset($_GET['eum_click_type']) ? sanitize_key((string)wp_unslash($_GET['eum_click_type'])) : 'success';
    ob_start();
    $is_it_or_hr = class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id);
    echo '<section class="eum-click-login" style="max-width:1100px;margin:24px auto;padding:24px;background:#fff;border:1px solid #dcdcde;border-radius:12px;"><header><h1 style="margin-top:0;">App Access</h1><p>Provision apps for people in your manager scope. Shared logins use one account across assigned users; user-based logins store unique credentials for each user. Passwords are encrypted and are not shown after saving.</p></header>';
    if ($message !== '') echo '<div role="status" style="padding:12px;margin:14px 0;border-left:4px solid ' . ($message_type === 'error' ? '#d63638' : '#00a32a') . ';background:#f6f7f7;">' . esc_html($message) . '</div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;">';
    echo '<section><h2>Create a shared login</h2><p class="description">Add one or more labeled accounts for a shared-login app. Regular manager assignments need approval; HR and IT can assign each login directly.</p>';
    if (!$this->has_apps_of_type($apps, 'shared')) echo '<p>No shared-login apps are enabled.</p>';
    else {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_click_login_create_shared">';
      wp_nonce_field('eum_click_login_create_shared', 'eum_click_login_nonce');
      echo '<p><label>App<br><select name="app_id" required>' . $this->app_options_html($apps, 'shared') . '</select></label></p><p><label>Login label<br><input class="regular-text" name="label" required maxlength="100" placeholder="Team account"></label></p><p><label>Username or email<br><input class="regular-text" name="username" required maxlength="190"></label></p><p><label>Password<br><input type="password" name="password" required autocomplete="new-password" maxlength="1024"></label></p><button class="button" type="submit">Create shared login</button></form>';
    }
    echo '</section>';

    echo '<section><h2>' . ($is_it_or_hr ? 'Provision shared app access' : 'Request shared app access') . '</h2><p class="description">' . ($is_it_or_hr ? 'HR and IT assignments take effect immediately without approval.' : 'Each selected user gets a separate approval request routed to your manager.') . '</p>';
    if (empty($target_users) || empty($shared_refs)) echo '<p>You need managed users and an enabled shared login before requesting access.</p>';
    else {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_click_login_request_shared">';
      wp_nonce_field('eum_click_login_request_shared', 'eum_click_login_nonce');
      echo '<p><label>Users (select one or more)<br><select name="user_ids[]" required multiple size="8" style="min-width:320px;max-width:100%;">';
      foreach ($target_users as $user) {
        if (!($user instanceof WP_User)) continue;
        $label = ($user->display_name !== '' ? $user->display_name : $user->user_login) . ' · ' . $user->user_login;
        echo '<option value="' . (int)$user->ID . '">' . esc_html($label) . '</option>';
      }
      echo '</select></label></p><p><label>Shared login<br><select name="shared_ref" required><option value="">Select a login</option>';
      foreach ($shared_refs as $ref) echo '<option value="' . esc_attr($ref['value']) . '">' . esc_html($ref['label']) . '</option>';
      echo '</select></label></p><p><label>Reason<br><textarea name="reason" rows="2" required maxlength="500" style="width:100%;"></textarea></label></p><button class="button button-primary" type="submit">' . ($is_it_or_hr ? 'Provision for selected users' : 'Request approval for selected users') . '</button></form>';
    }
    echo '</section></div>';

    echo '<section style="margin-top:24px;"><h2>Provision user-based apps</h2><p class="description">Credentials are assigned immediately without approval. Add multiple labeled logins for the same person when needed. Bulk entry supports up to 200 login rows: internal user login or email, login label, external app username, password.</p>';
    if (empty($target_users) || !$this->has_apps_of_type($apps, 'user')) echo '<p>You need managed users and an enabled user-based app before provisioning.</p>';
    else {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px;"><input type="hidden" name="action" value="eum_click_login_save_user_credential">';
      wp_nonce_field('eum_click_login_save_user_credential', 'eum_click_login_nonce');
      echo '<p><label>App<br><select name="app_id" required>' . $this->app_options_html($apps, 'user') . '</select></label></p><p><label>Credential rows<br><textarea name="bulk_rows" rows="8" required spellcheck="false" style="width:100%;font-family:monospace;" placeholder="jane.smith&#9;Work account&#9;jane.smith@example.com&#9;password-for-jane&#10;jane.smith&#9;Admin account&#9;jane.admin@example.com&#9;another-password&#10;john.smith@example.com&#9;Work account&#9;john.smith&#9;password-for-john"></textarea></label></p><p class="description">Use actual tab characters between columns. Each row provisions one login. A user can appear on multiple rows if each login label is unique for that app. Every user must be in your manager scope.</p><p><label>Reason (required for bulk provisioning)<br><input class="regular-text" name="reason" required maxlength="500"></label></p><button class="button button-primary" type="submit">Provision all rows</button></form>';
      echo '<h3 style="margin-top:20px;">Provision one user-based login</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;align-items:end;margin-top:12px;"><input type="hidden" name="action" value="eum_click_login_save_user_credential">';
      wp_nonce_field('eum_click_login_save_user_credential', 'eum_click_login_nonce');
      echo '<label>User<br><select name="user_id" required>' . $this->target_user_options_html($target_users) . '</select></label><label>App<br><select name="app_id" required>' . $this->app_options_html($apps, 'user') . '</select></label><label>Login label<br><input class="regular-text" name="login_label" required maxlength="100" placeholder="Work account"></label><label>Username or email<br><input class="regular-text" name="username" required maxlength="190"></label><label>Password<br><input type="password" name="password" required autocomplete="new-password" maxlength="1024"></label><label>Reason<br><input class="regular-text" name="reason" required maxlength="500"></label><button class="button" type="submit">Add login</button></form>';
    }
    echo '</section>';

    $this->render_manager_requests($actor_user_id);
    $this->render_manager_assignments($actor_user_id, $target_users);
    echo '</section>';
    return (string)ob_get_clean();
  }

  private function has_apps_of_type(array $apps, string $type): bool {
    foreach ($apps as $app) if (($app['type'] ?? '') === $type) return true;
    return false;
  }

  private function app_options_html(array $apps, string $type): string {
    $html = '<option value="">Select an app</option>';
    foreach ($apps as $id => $app) {
      if (($app['type'] ?? '') !== $type) continue;
      $html .= '<option value="' . esc_attr((string)$id) . '">' . esc_html((string)$app['label']) . '</option>';
    }
    return $html;
  }

  private function target_user_options_html(array $users): string {
    $html = '<option value="">Select a user</option>';
    foreach ($users as $user) {
      if (!($user instanceof WP_User)) continue;
      $label = ($user->display_name !== '' ? $user->display_name : $user->user_login) . ' · ' . $user->user_login;
      $html .= '<option value="' . (int)$user->ID . '">' . esc_html($label) . '</option>';
    }
    return $html;
  }

  private function managed_users_for_actor(int $actor_user_id): array {
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) {
      $users = get_users(['fields' => 'all', 'number' => 5000, 'orderby' => 'display_name', 'order' => 'ASC']);
    } elseif (class_exists('EUM_Manager_IDs')) {
      $ids = EUM_Manager_IDs::managed_user_ids_for_manager_user($actor_user_id, false, true);
      $users = empty($ids) ? [] : get_users(['include' => $ids, 'fields' => 'all', 'number' => 5000, 'orderby' => 'display_name', 'order' => 'ASC']);
    } else {
      $users = [];
    }
    return array_values(array_filter((array)$users, static function ($user) {
      return $user instanceof WP_User && (!class_exists('EUM_Personnel') || !EUM_Personnel::is_terminated((int)$user->ID));
    }));
  }

  private function render_manager_requests(int $actor_user_id): void {
    $requests = get_posts(['post_type' => self::CPT_REQUEST, 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'date', 'order' => 'DESC']);
    $mine = [];
    $approval = [];
    foreach ((array)$requests as $request) {
      $status = (string)get_post_meta((int)$request->ID, self::META_STATUS, true);
      $requestor = (int)get_post_meta((int)$request->ID, self::META_REQUESTOR, true);
      if ($requestor === $actor_user_id) $mine[] = $request;
      if ($status === 'pending' && $this->can_approve_request((int)$request->ID, $actor_user_id)) $approval[] = $request;
    }
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-top:24px;">';
    echo '<section><h2>Requests awaiting your approval</h2>';
    if (empty($approval)) echo '<p>No app access requests are awaiting your approval.</p>';
    foreach ($approval as $request) {
      $target = get_user_by('id', (int)get_post_meta((int)$request->ID, self::META_TARGET_USER, true));
      $reason = (string)get_post_meta((int)$request->ID, self::META_REASON, true);
      echo '<article style="padding:12px;margin:10px 0;border:1px solid #dcdcde;border-radius:8px;"><strong>' . esc_html((string)$request->post_title) . '</strong><p>' . esc_html($target ? ($target->display_name ?: $target->user_login) : 'Unknown user') . ' · Requested by ' . esc_html(get_the_author_meta('display_name', (int)$request->post_author)) . '</p><p><strong>Reason:</strong> ' . esc_html($reason) . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_click_login_decide_request"><input type="hidden" name="request_id" value="' . (int)$request->ID . '">';
      wp_nonce_field('eum_click_login_decide_' . (int)$request->ID, 'eum_click_login_nonce');
      echo '<label>Review note (optional) <input name="decision_reason" maxlength="500"></label> <button class="button button-primary" name="decision" value="approve">Approve</button> <button class="button" name="decision" value="deny">Deny</button></form></article>';
    }
    echo '</section><section><h2>Your shared-login requests</h2>';
    if (empty($mine)) echo '<p>You have not requested shared app access.</p>';
    foreach ($mine as $request) {
      $status = sanitize_key((string)get_post_meta((int)$request->ID, self::META_STATUS, true));
      $decider = get_user_by('id', (int)get_post_meta((int)$request->ID, self::META_DECIDED_BY, true));
      echo '<p style="padding:10px;border-bottom:1px solid #dcdcde;"><strong>' . esc_html((string)$request->post_title) . '</strong> · ' . esc_html(ucfirst($status)) . ($decider ? ' · reviewed by ' . esc_html($decider->display_name) : '') . '</p>';
    }
    echo '</section></div>';
  }

  private function render_manager_assignments(int $actor_user_id, array $target_users): void {
    echo '<section style="margin-top:24px;"><h2>Current app access for your users</h2>';
    $rows = [];
    $apps = self::get_apps();
    foreach ($target_users as $user) {
      $assignments = self::get_user_assignments((int)$user->ID);
      foreach ($assignments as $app_id => $assignment_group) {
        $app = $apps[(string)$app_id] ?? null;
        if (!is_array($app)) continue;
        foreach (self::app_login_assignments($assignments, (string)$app_id) as $login_id => $assignment) {
          if (!is_array($assignment)) continue;
          $rows[] = ['user' => $user, 'app' => $app, 'assignment' => $assignment, 'login_id' => (string)$login_id];
        }
      }
    }
    if (empty($rows)) { echo '<p>No app access is currently provisioned for your users.</p></section>'; return; }
    echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr><th>User</th><th>App</th><th>Login</th><th>Account type</th><th>Provisioned</th><th>Action</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $user = $row['user']; $app = $row['app']; $assignment = $row['assignment']; $login_id = $row['login_id'];
      $is_shared = ($assignment['type'] ?? '') === 'shared';
      $date = sanitize_text_field((string)($assignment['assigned_at'] ?? ''));
      $assignment_label = $is_shared ? (!empty($assignment['approved_by']) ? 'Shared · approved' : 'Shared · direct') : 'User-based';
      echo '<tr><td>' . esc_html($user->display_name ?: $user->user_login) . '</td><td>' . esc_html((string)$app['label']) . '</td><td>' . esc_html((string)($assignment['label'] ?? 'Login')) . '</td><td>' . esc_html($assignment_label) . '</td><td>' . esc_html($date) . '</td><td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_click_login_revoke_assignment"><input type="hidden" name="user_id" value="' . (int)$user->ID . '"><input type="hidden" name="app_id" value="' . esc_attr((string)$app['id']) . '"><input type="hidden" name="login_id" value="' . esc_attr($login_id) . '">';
      wp_nonce_field('eum_click_login_revoke_' . $app['id'] . '_' . (int)$user->ID . '_' . $login_id, 'eum_click_login_nonce');
      echo '<button class="button button-small" type="submit">Revoke login</button></form></td></tr>';
    }
    echo '</tbody></table></div></section>';
  }

  private function admin_redirect(string $message, string $type = 'success'): void {
    wp_safe_redirect(add_query_arg(['eum_click_msg' => $message, 'eum_click_type' => $type], admin_url('admin.php?page=eum-click-login')));
    exit;
  }

  private function front_redirect(string $message, string $type = 'success'): void {
    $url = class_exists('EUM_Portal_Shell') ? EUM_Portal_Shell::page_url('app_access') : home_url('/');
    wp_safe_redirect(add_query_arg(['eum_click_msg' => $message, 'eum_click_type' => $type], $url));
    exit;
  }
}
