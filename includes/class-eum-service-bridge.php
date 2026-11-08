<?php
if (!defined('ABSPATH')) exit;

class EUM_Service_Bridge {
  const OPTION_SETTINGS = 'eum_service_bridge_settings';
  const META_LAST_SYNC = '_eum_service_bridge_last_sync';
  const META_LAST_ERROR = '_eum_service_bridge_last_error';

  private static array $pending_new_users = [];

  public function init(): void {
    add_action('admin_menu', [$this, 'admin_menu'], 45);
    add_action('admin_post_eum_service_bridge_save', [$this, 'handle_save']);
    add_action('admin_post_eum_service_bridge_test', [$this, 'handle_test']);
    add_action('admin_post_eum_webmail_launch', [$this, 'handle_webmail_launch']);

    add_shortcode('eum_webmail_portal', [$this, 'shortcode_webmail_portal']);

    add_filter('wp_pre_insert_user_data', [$this, 'capture_new_user_payload'], 10, 4);
    add_action('user_register', [$this, 'handle_user_register'], 10, 1);
    add_action('eum_preaccepted_candidate_user_ready', [$this, 'handle_preaccepted_candidate_user_ready'], 10, 3);
    add_action('eum_preaccepted_candidate_existing_user_ready', [$this, 'handle_preaccepted_candidate_existing_user_ready'], 10, 3);
    add_action('profile_update', [$this, 'handle_profile_update'], 10, 2);
    add_action('wp_set_password', [$this, 'handle_wp_set_password'], 10, 3);
    add_action('eum_employment_status_changed', [$this, 'handle_employment_status_changed'], 10, 4);
  }

  public static function default_settings(): array {
    $default_host = parse_url(home_url('/'), PHP_URL_HOST);
    if (!is_string($default_host) || $default_host === '') $default_host = 'localhost';

    return [
      'webmail_enabled' => 1,
      'webmail_label' => 'Open Mail',
      'webmail_mode' => 'cpanel_auto',
      'webmail_url_template' => 'https://{domain}:2096',
      'open_new_tab' => 0,
      'transport' => 'cpanel_api_token',
      'local_script_path' => '',
      'webhook_url' => '',
      'webhook_bearer' => '',
      'webhook_timeout' => 15,
      'cpanel_host' => $default_host,
      'cpanel_username' => '',
      'cpanel_api_token' => '',
      'cpanel_verify_ssl' => 0,
      'cpanel_locale' => 'en',
      'sync_on_create' => 1,
      'sync_on_email_change' => 0,
      'sync_on_password_change' => 1,
      'sync_on_termination' => 1,
      'sync_on_reactivation' => 1,
    ];
  }

  public static function get_settings(): array {
    $settings = get_option(self::OPTION_SETTINGS, []);
    if (!is_array($settings)) $settings = [];
    $settings = array_merge(self::default_settings(), $settings);

    foreach (['webmail_enabled','open_new_tab','cpanel_verify_ssl','sync_on_create','sync_on_email_change','sync_on_password_change','sync_on_termination','sync_on_reactivation'] as $key) {
      $settings[$key] = empty($settings[$key]) ? 0 : 1;
    }

    $settings['webmail_label'] = sanitize_text_field((string)$settings['webmail_label']);
    $settings['webmail_mode'] = in_array((string)$settings['webmail_mode'], ['link', 'cpanel_auto'], true) ? (string)$settings['webmail_mode'] : 'link';
    $settings['webmail_url_template'] = trim((string)$settings['webmail_url_template']);
    $settings['transport'] = in_array((string)$settings['transport'], ['disabled', 'cpanel_api_token', 'local_script', 'webhook'], true) ? (string)$settings['transport'] : 'disabled';
    $settings['local_script_path'] = sanitize_text_field((string)$settings['local_script_path']);
    $settings['webhook_url'] = esc_url_raw((string)$settings['webhook_url']);
    $settings['webhook_bearer'] = sanitize_text_field((string)$settings['webhook_bearer']);
    $settings['webhook_timeout'] = max(3, min(60, intval($settings['webhook_timeout'])));
    $settings['cpanel_host'] = sanitize_text_field((string)$settings['cpanel_host']);
    $settings['cpanel_username'] = sanitize_text_field((string)$settings['cpanel_username']);
    $settings['cpanel_api_token'] = trim((string)$settings['cpanel_api_token']);
    $settings['cpanel_locale'] = strtolower(sanitize_key((string)$settings['cpanel_locale']));
    if ($settings['cpanel_locale'] === '') $settings['cpanel_locale'] = 'en';

    return $settings;
  }

  public function admin_menu(): void {
    if (!EUM_Access::current_user_is_it()) return;
    add_submenu_page(
      EUM_Admin::parent_slug('integrations'),
      'Mail & Services',
      'Mail & Services',
      'read',
      'eum-mail-services',
      [$this, 'render_admin_page']
    );
  }

  public function render_admin_page(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $settings = self::get_settings();

    if (!empty($_GET['eum_msg'])) {
      $msg = sanitize_text_field((string)$_GET['eum_msg']);
      $type = !empty($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
      $cls = ($type === 'error') ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($cls) . '"><p>' . esc_html($msg) . '</p></div>';
      if (!empty($_GET['eum_details'])) {
        $details = wp_kses_post(wpautop((string)wp_unslash($_GET['eum_details'])));
        echo '<div class="notice notice-info"><div style="padding:10px 12px;"><strong>cPanel details</strong>' . $details . '</div></div>';
      }
    }

    echo '<div class="wrap"><h1>Mail &amp; Services</h1>';
    echo '<p>Use this screen to expose mailbox access in the front end and to sync WordPress user creation, password changes, and employment status with your local mail services.</p>';
    echo '<p class="description">For cPanel on the same server, the easiest setup is <strong>Webmail Mode = cPanel Auto Login</strong> and <strong>Transport = cPanel API Token</strong>.</p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_service_bridge_save">';
    wp_nonce_field('eum_service_bridge_save', 'eum_service_bridge_nonce');

    echo '<h2>Front-End Mail Access</h2>';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th scope="row">Mail Access</th><td>';
    echo '<label><input type="checkbox" name="webmail_enabled" value="1" ' . checked(!empty($settings['webmail_enabled']), true, false) . '> Enable mailbox access in the front-end profile tools, mail portal, and manager portal</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Button Label</th><td>';
    echo '<input type="text" class="regular-text" name="webmail_label" value="' . esc_attr($settings['webmail_label']) . '">';
    echo '</td></tr>';

    echo '<tr><th scope="row">Webmail Mode</th><td>';
    echo '<select name="webmail_mode">';
    foreach (['cpanel_auto' => 'cPanel Auto Login', 'link' => 'Plain Link'] as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($settings['webmail_mode'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">cPanel Auto Login sends the user directly into Webmail. If multiple mailboxes are available, the front end shows a mailbox chooser first.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Fallback / Plain Link URL Template</th><td>';
    echo '<input type="text" class="large-text code" name="webmail_url_template" value="' . esc_attr($settings['webmail_url_template']) . '">';
    echo '<p class="description">Used when Webmail Mode is Plain Link, or as a fallback if cPanel Auto Login is unavailable. Placeholders: <code>{email}</code>, <code>{login}</code>, <code>{domain}</code>, <code>{site_url}</code>, <code>{mailbox}</code>.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Open in New Tab</th><td>';
    echo '<label><input type="checkbox" name="open_new_tab" value="1" ' . checked(!empty($settings['open_new_tab']), true, false) . '> Open mail links in a new tab</label>';
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<h2>cPanel</h2>';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th scope="row">cPanel Server Host</th><td>';
    echo '<input type="text" class="regular-text code" name="cpanel_host" value="' . esc_attr($settings['cpanel_host']) . '" placeholder="server.example.com">';
    echo '<p class="description">The plugin calls <code>https://HOST:2083</code> for API actions and <code>https://HOST:2096</code> for Webmail login.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">cPanel Username</th><td>';
    echo '<input type="text" class="regular-text code" name="cpanel_username" value="' . esc_attr($settings['cpanel_username']) . '" placeholder="cpaneluser">';
    echo '<p class="description">This is the owning cPanel account, not the email address.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">cPanel API Token</th><td>';
    echo '<input type="text" class="large-text code" name="cpanel_api_token" value="' . esc_attr($settings['cpanel_api_token']) . '">';
    echo '<p class="description">Create this in cPanel → Security → Manage API Tokens. The same token powers built-in mailbox create, password sync, suspend/unsuspend, and auto-login session creation.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Verify cPanel SSL</th><td>';
    echo '<label><input type="checkbox" name="cpanel_verify_ssl" value="1" ' . checked(!empty($settings['cpanel_verify_ssl']), true, false) . '> Verify the TLS certificate on the cPanel API connection</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Webmail Locale</th><td>';
    echo '<input type="text" class="small-text code" name="cpanel_locale" value="' . esc_attr($settings['cpanel_locale']) . '" placeholder="en">';
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<h2>Service Sync Transport</h2>';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th scope="row">Transport</th><td>';
    echo '<select name="transport">';
    foreach (['cpanel_api_token' => 'cPanel API Token', 'disabled' => 'Disabled', 'local_script' => 'Local script', 'webhook' => 'Webhook'] as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($settings['transport'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">cPanel API Token is the lowest-friction option for same-server cPanel mail. Local script and Webhook stay available for future services.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Local Script Path</th><td>';
    echo '<input type="text" class="large-text code" name="local_script_path" value="' . esc_attr($settings['local_script_path']) . '" placeholder="/usr/local/bin/eum-service-sync">';
    echo '</td></tr>';

    echo '<tr><th scope="row">Webhook URL</th><td>';
    echo '<input type="url" class="large-text code" name="webhook_url" value="' . esc_attr($settings['webhook_url']) . '" placeholder="http://127.0.0.1:8080/sync">';
    echo '</td></tr>';

    echo '<tr><th scope="row">Webhook Bearer Token</th><td>';
    echo '<input type="text" class="regular-text code" name="webhook_bearer" value="' . esc_attr($settings['webhook_bearer']) . '">';
    echo '</td></tr>';

    echo '<tr><th scope="row">Webhook Timeout</th><td>';
    echo '<input type="number" min="3" max="60" step="1" name="webhook_timeout" value="' . (int)$settings['webhook_timeout'] . '"> seconds';
    echo '</td></tr>';

    echo '<tr><th scope="row">Sync Triggers</th><td>';
    foreach ([
      'sync_on_create' => 'Create mailbox when a WordPress user is created and a plaintext password is available',
      'sync_on_email_change' => 'Create the new mailbox on email-address changes when it does not already exist',
      'sync_on_password_change' => 'Push password changes to the service bridge',
      'sync_on_termination' => 'Disable mailbox login on termination',
      'sync_on_reactivation' => 'Re-enable mailbox login on reactivation',
    ] as $key => $label) {
      echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '> ' . esc_html($label) . '</label>';
    }
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<h2>cPanel Test</h2>';
    echo '<p class="description">Use this to test the exact mailbox auto-login call. This does not open Webmail; it shows the raw cPanel result so you can see why the session call fails.</p>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Test Mailbox</th><td>';
    echo '<input type="email" class="regular-text code" name="test_mailbox" value="" placeholder="user@example.com"> ';
    echo '<button type="submit" class="button" formaction="' . esc_url(admin_url('admin-post.php')) . '" formmethod="post" name="action" value="eum_service_bridge_test">Run cPanel Mail Test</button>';
    echo '<input type="hidden" name="_eum_test_from_settings" value="1">';
    echo '<p class="description">The mailbox must exist under the configured cPanel account. The response details will appear at the top of this page after the test.</p>';
    echo '</td></tr>';
    echo '</tbody></table>';

    submit_button('Save Mail & Services Settings');
    echo '</form></div>';
  }

  public function handle_save(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_service_bridge_nonce']) || !wp_verify_nonce($_POST['eum_service_bridge_nonce'], 'eum_service_bridge_save')) wp_die('Bad nonce.');

    $settings = [
      'webmail_enabled' => isset($_POST['webmail_enabled']) ? 1 : 0,
      'webmail_label' => sanitize_text_field((string)($_POST['webmail_label'] ?? 'Open Mail')),
      'webmail_mode' => sanitize_key((string)($_POST['webmail_mode'] ?? 'cpanel_auto')),
      'webmail_url_template' => trim((string)($_POST['webmail_url_template'] ?? '')),
      'open_new_tab' => isset($_POST['open_new_tab']) ? 1 : 0,
      'transport' => sanitize_key((string)($_POST['transport'] ?? 'disabled')),
      'local_script_path' => sanitize_text_field((string)($_POST['local_script_path'] ?? '')),
      'webhook_url' => esc_url_raw((string)($_POST['webhook_url'] ?? '')),
      'webhook_bearer' => sanitize_text_field((string)($_POST['webhook_bearer'] ?? '')),
      'webhook_timeout' => max(3, min(60, intval($_POST['webhook_timeout'] ?? 15))),
      'cpanel_host' => sanitize_text_field((string)($_POST['cpanel_host'] ?? '')),
      'cpanel_username' => sanitize_text_field((string)($_POST['cpanel_username'] ?? '')),
      'cpanel_api_token' => trim((string)($_POST['cpanel_api_token'] ?? '')),
      'cpanel_verify_ssl' => isset($_POST['cpanel_verify_ssl']) ? 1 : 0,
      'cpanel_locale' => strtolower(sanitize_key((string)($_POST['cpanel_locale'] ?? 'en'))),
      'sync_on_create' => isset($_POST['sync_on_create']) ? 1 : 0,
      'sync_on_email_change' => isset($_POST['sync_on_email_change']) ? 1 : 0,
      'sync_on_password_change' => isset($_POST['sync_on_password_change']) ? 1 : 0,
      'sync_on_termination' => isset($_POST['sync_on_termination']) ? 1 : 0,
      'sync_on_reactivation' => isset($_POST['sync_on_reactivation']) ? 1 : 0,
    ];

    update_option(self::OPTION_SETTINGS, $settings, false);
    wp_safe_redirect(admin_url('admin.php?page=eum-mail-services&eum_msg=' . rawurlencode('Mail & Services settings saved.') . '&eum_type=success'));
    exit;
  }

  public function handle_test(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_service_bridge_nonce']) || !wp_verify_nonce($_POST['eum_service_bridge_nonce'], 'eum_service_bridge_save')) wp_die('Bad nonce.');

    $settings = self::get_settings();
    $mailbox = isset($_POST['test_mailbox']) ? sanitize_email((string)wp_unslash($_POST['test_mailbox'])) : '';
    if ($mailbox === '') {
      wp_safe_redirect(admin_url('admin.php?page=eum-mail-services&eum_msg=' . rawurlencode('Enter a mailbox email address to test.') . '&eum_type=error'));
      exit;
    }

    $result = $this->create_cpanel_webmail_session($settings, $mailbox);
    $msg = !empty($result['success']) ? 'cPanel Webmail session test succeeded.' : ('cPanel Webmail session test failed: ' . (string)($result['message'] ?? 'Unknown error.'));
    $type = !empty($result['success']) ? 'success' : 'error';
    $details = rawurlencode($this->format_debug_details($result));
    wp_safe_redirect(admin_url('admin.php?page=eum-mail-services&eum_msg=' . rawurlencode($msg) . '&eum_type=' . rawurlencode($type) . '&eum_details=' . $details));
    exit;
  }

  public function capture_new_user_payload(array $data, bool $update, $user_id, array $userdata): array {
    if ($update) return $data;

    $raw_pass = isset($userdata['user_pass']) && is_scalar($userdata['user_pass']) ? (string)$userdata['user_pass'] : '';
    $user_login = isset($data['user_login']) && is_scalar($data['user_login']) ? (string)$data['user_login'] : '';
    $user_email = isset($data['user_email']) && is_scalar($data['user_email']) ? (string)$data['user_email'] : '';

    if ($user_login !== '' || $user_email !== '') {
      self::$pending_new_users[] = [
        'user_login' => $user_login,
        'user_email' => $user_email,
        'password' => $raw_pass,
      ];
    }

    return $data;
  }

  public function handle_user_register(int $user_id): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['sync_on_create'])) return;
      if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) return;

      $user = get_user_by('id', $user_id);
      if (!$user) return;

      $match_index = null;
      $password = '';
      foreach (self::$pending_new_users as $idx => $row) {
        if (($row['user_login'] !== '' && $row['user_login'] === (string)$user->user_login) || ($row['user_email'] !== '' && $row['user_email'] === (string)$user->user_email)) {
          $match_index = $idx;
          $password = (string)($row['password'] ?? '');
          break;
        }
      }
      if ($match_index !== null) unset(self::$pending_new_users[$match_index]);

      if ($this->is_preaccepted_invite_registration($user)) return;

      $payload = $this->base_payload('create_account', $user_id, [
        'plaintext_password' => $password,
      ]);
      $this->dispatch_event($payload);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_user_register', $e);
    }
  }

  public function handle_preaccepted_candidate_user_ready(int $user_id, int $candidate_id = 0, string $password = ''): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['sync_on_create'])) return;
      if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) return;

      $user = get_user_by('id', $user_id);
      if (!$user) return;
      if ($password === '') return;

      $payload = $this->base_payload('create_account', $user_id, [
        'plaintext_password' => $password,
        'candidate_id' => max(0, $candidate_id),
        'source' => 'preaccepted_candidate',
      ]);
      $this->dispatch_event($payload);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_preaccepted_candidate_user_ready', $e);
    }
  }

  public function handle_preaccepted_candidate_existing_user_ready(int $user_id, int $candidate_id = 0, string $password = ''): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['sync_on_create'])) return;
      if ($user_id <= 0 || !get_user_by('id', $user_id)) return;

      $this->dispatch_event($this->base_payload('update_profile', $user_id, [
        'candidate_id' => max(0, $candidate_id),
        'source' => 'preaccepted_candidate_existing_user',
      ]));
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_preaccepted_candidate_existing_user_ready', $e);
    }
  }

  private function is_preaccepted_invite_registration(WP_User $user): bool {
    $marker = $GLOBALS['eum_preaccepted_invite_registering_user'] ?? null;
    if (!is_array($marker)) return false;
    $username = isset($marker['username']) ? (string)$marker['username'] : '';
    $email = isset($marker['email']) ? sanitize_email((string)$marker['email']) : '';
    if ($username !== '' && $username === (string)$user->user_login) return true;
    if ($email !== '' && $email === sanitize_email((string)$user->user_email)) return true;
    return false;
  }

  public function handle_profile_update(int $user_id, WP_User $old_user_data): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['sync_on_email_change'])) return;

      $user = get_user_by('id', $user_id);
      if (!$user) return;

      $old_email = sanitize_email((string)$old_user_data->user_email);
      $new_email = sanitize_email((string)$user->user_email);
      if ($old_email === $new_email) return;

      $payload = $this->base_payload('update_email', $user_id, [
        'old_email' => $old_email,
        'new_email' => $new_email,
      ]);
      $this->dispatch_event($payload);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_profile_update', $e);
    }
  }

  public function handle_wp_set_password(string $password, int $user_id, WP_User $old_user_data): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['sync_on_password_change'])) return;
      if ($user_id <= 0 || $password === '') return;

      $payload = $this->base_payload('change_password', $user_id, [
        'plaintext_password' => $password,
        'old_user_email' => sanitize_email((string)$old_user_data->user_email),
      ]);
      $this->dispatch_event($payload);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_wp_set_password', $e);
    }
  }

  public function handle_employment_status_changed(int $user_id, array $before, array $after, int $actor_user_id): void {
    try {
      $settings = self::get_settings();
      $was_terminated = !empty($before['terminated']);
      $is_terminated = !empty($after['terminated']);

      if (!$was_terminated && $is_terminated && !empty($settings['sync_on_termination'])) {
        $payload = $this->base_payload('disable_account', $user_id, [
          'actor_user_id' => $actor_user_id,
          'employment_before' => $before,
          'employment_after' => $after,
        ]);
        $this->dispatch_event($payload);
      }

      if ($was_terminated && !$is_terminated && !empty($settings['sync_on_reactivation'])) {
        $payload = $this->base_payload('enable_account', $user_id, [
          'actor_user_id' => $actor_user_id,
          'employment_before' => $before,
          'employment_after' => $after,
        ]);
        $this->dispatch_event($payload);
      }
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_employment_status_changed', $e);
    }
  }

  private function base_payload(string $event, int $user_id, array $extra = []): array {
    $user = get_user_by('id', $user_id);
    $payload = [
      'event' => $event,
      'timestamp' => current_time('mysql'),
      'site_url' => home_url('/'),
      'user_id' => $user_id,
      'user_login' => $user ? (string)$user->user_login : '',
      'user_email' => $user ? (string)$user->user_email : '',
      'display_name' => $user ? (string)$user->display_name : '',
      'terminated' => class_exists('EUM_Personnel') ? (EUM_Personnel::is_terminated($user_id) ? 1 : 0) : 0,
      'rehirable' => class_exists('EUM_Personnel') ? (EUM_Personnel::is_rehirable($user_id) ? 1 : 0) : 0,
    ];
    return array_merge($payload, $extra);
  }

  private function dispatch_event(array $payload): array {
    $settings = self::get_settings();
    $payload = apply_filters('eum_service_bridge_payload', $payload, $settings);
    do_action('eum_service_bridge_before_dispatch', $payload, $settings);

    $result = ['success' => true, 'message' => 'Transport disabled; event skipped.'];
    if ($settings['transport'] === 'cpanel_api_token') {
      $result = $this->dispatch_cpanel($settings, $payload);
    } elseif ($settings['transport'] === 'local_script' && $settings['local_script_path'] !== '') {
      $result = $this->dispatch_local_script($settings['local_script_path'], $payload);
    } elseif ($settings['transport'] === 'webhook' && $settings['webhook_url'] !== '') {
      $result = $this->dispatch_webhook($settings, $payload);
    }

    $user_id = intval($payload['user_id'] ?? 0);
    if ($user_id > 0) {
      update_user_meta($user_id, self::META_LAST_SYNC, [
        'time' => current_time('mysql'),
        'event' => (string)($payload['event'] ?? ''),
        'success' => !empty($result['success']) ? 1 : 0,
        'message' => (string)($result['message'] ?? ''),
      ]);
      if (!empty($result['success'])) {
        delete_user_meta($user_id, self::META_LAST_ERROR);
      } else {
        update_user_meta($user_id, self::META_LAST_ERROR, [
          'time' => current_time('mysql'),
          'event' => (string)($payload['event'] ?? ''),
          'message' => (string)($result['message'] ?? 'Unknown error'),
        ]);
      }
    }

    do_action('eum_service_bridge_after_dispatch', $payload, $result, $settings);
    return $result;
  }

  private function dispatch_cpanel(array $settings, array $payload): array {
    $email = sanitize_email((string)($payload['user_email'] ?? ''));
    [$local, $domain] = $this->split_email($email);
    if ($local === '' || $domain === '') {
      return ['success' => false, 'message' => 'The user does not have a valid email address for cPanel mail sync.'];
    }

    $event = (string)($payload['event'] ?? '');
    if (!empty($payload['terminated']) && in_array($event, ['create_account', 'change_password', 'update_email'], true)) {
      return ['success' => true, 'message' => 'Mailbox sync skipped because the user is Alumni/Former Staff.'];
    }
    if ($event === 'create_account') {
      $password = (string)($payload['plaintext_password'] ?? '');
      if ($password === '') return ['success' => false, 'message' => 'Mailbox create skipped because no plaintext password was available.'];
      return $this->cpanel_create_mailbox($settings, $email, $password);
    }

    if ($event === 'change_password') {
      $password = (string)($payload['plaintext_password'] ?? '');
      if ($password === '') return ['success' => false, 'message' => 'Password sync skipped because no plaintext password was available.'];
      return $this->cpanel_uapi_request($settings, 'Email/passwd_pop', [
        'email' => $local,
        'domain' => $domain,
        'password' => $password,
      ]);
    }

    if ($event === 'disable_account') {
      return $this->cpanel_uapi_request($settings, 'Email/suspend_login', [
        'email' => $local,
        'domain' => $domain,
      ]);
    }

    if ($event === 'enable_account') {
      return $this->cpanel_uapi_request($settings, 'Email/unsuspend_login', [
        'email' => $local,
        'domain' => $domain,
      ]);
    }

    if ($event === 'update_email') {
      $new_email = sanitize_email((string)($payload['new_email'] ?? $email));
      $old_email = sanitize_email((string)($payload['old_email'] ?? ''));
      if ($new_email === '') {
        return ['success' => false, 'message' => 'Email change sync skipped because the new mailbox address was invalid.'];
      }
      if ($old_email !== '' && strtolower($old_email) === strtolower($new_email)) {
        return ['success' => true, 'message' => 'Mailbox email did not change.'];
      }

      $exists_result = $this->cpanel_mailbox_exists($settings, $new_email);
      if (empty($exists_result['success'])) return $exists_result;
      if (!empty($exists_result['exists'])) {
        return ['success' => true, 'message' => 'Mailbox already exists for the updated email address.'];
      }

      $password = (string)($payload['plaintext_password'] ?? '');
      $used_generated_password = false;
      if ($password === '') {
        $password = wp_generate_password(24, true, true);
        $used_generated_password = true;
      }

      $create_result = $this->cpanel_create_mailbox($settings, $new_email, $password);
      if (empty($create_result['success'])) return $create_result;

      $message = 'Created mailbox for the updated email address. The previous mailbox was left unchanged.';
      if ($used_generated_password) {
        $message .= ' No plaintext WordPress password was available during the email change, so the mailbox was created with a generated password and will sync to the user\'s WordPress password on their next password change.';
      }
      $create_result['message'] = $message;
      return $create_result;
    }

    return ['success' => true, 'message' => 'No cPanel action mapped for event ' . $event . '.'];
  }

  private function dispatch_local_script(string $script_path, array $payload): array {
    if (!function_exists('proc_open')) {
      return ['success' => false, 'message' => 'proc_open() is not available on this server.'];
    }
    if (!is_file($script_path) || !is_executable($script_path)) {
      return ['success' => false, 'message' => 'Configured local script is missing or not executable.'];
    }

    $command = escapeshellcmd($script_path) . ' ' . escapeshellarg((string)($payload['event'] ?? 'event'));
    $pipes = [];
    $process = @proc_open($command, [
      0 => ['pipe', 'w'],
      1 => ['pipe', 'r'],
      2 => ['pipe', 'r'],
    ], $pipes);

    if (!is_resource($process)) {
      return ['success' => false, 'message' => 'Failed to start local sync script.'];
    }

    fwrite($pipes[0], wp_json_encode($payload));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($code !== 0) {
      return ['success' => false, 'message' => trim($stderr) !== '' ? trim($stderr) : ('Local script exited with code ' . $code . '.')];
    }

    return ['success' => true, 'message' => trim($stdout) !== '' ? trim($stdout) : 'Local service sync completed.'];
  }

  private function dispatch_webhook(array $settings, array $payload): array {
    $headers = ['Content-Type' => 'application/json'];
    if ($settings['webhook_bearer'] !== '') {
      $headers['Authorization'] = 'Bearer ' . $settings['webhook_bearer'];
    }
    $response = wp_remote_post($settings['webhook_url'], [
      'timeout' => (int)$settings['webhook_timeout'],
      'headers' => $headers,
      'body' => wp_json_encode($payload),
      'data_format' => 'body',
    ]);

    if (is_wp_error($response)) {
      return ['success' => false, 'message' => $response->get_error_message()];
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $body = trim((string)wp_remote_retrieve_body($response));
    if ($code < 200 || $code >= 300) {
      return ['success' => false, 'message' => $body !== '' ? $body : ('Webhook returned HTTP ' . $code . '.')];
    }
    return ['success' => true, 'message' => $body !== '' ? $body : ('Webhook returned HTTP ' . $code . '.')];
  }

  private function cpanel_create_mailbox(array $settings, string $email, string $password): array {
    [$local, $domain] = $this->split_email($email);
    if ($local === '' || $domain === '') {
      return ['success' => false, 'message' => 'Mailbox create skipped because the target email address was invalid.'];
    }
    if ($password === '') {
      return ['success' => false, 'message' => 'Mailbox create skipped because no password was available.'];
    }

    return $this->cpanel_uapi_request($settings, 'Email/add_pop', [
      'email' => $local,
      'domain' => $domain,
      'password' => $password,
      'send_welcome_email' => 0,
    ]);
  }

  private function cpanel_mailbox_exists(array $settings, string $email): array {
    [$local, $domain] = $this->split_email($email);
    if ($local === '' || $domain === '') {
      return ['success' => false, 'message' => 'Mailbox lookup skipped because the target email address was invalid.'];
    }

    $result = $this->cpanel_uapi_request($settings, 'Email/list_pops_with_disk', [
      'email' => $local,
      'domain' => $domain,
      'no_disk' => 1,
      'no_validate' => 1,
      'maxaccounts' => 1,
    ]);
    if (empty($result['success'])) return $result;

    $response = isset($result['response']) && is_array($result['response']) ? $result['response'] : [];
    $payload = $this->cpanel_result_payload($response);
    $data = $payload['data'] ?? [];
    $exists = !empty($data);

    return [
      'success' => true,
      'exists' => $exists ? 1 : 0,
      'response' => $response,
      'http_code' => $result['http_code'] ?? null,
    ];
  }

  private function cpanel_uapi_request(array $settings, string $path, array $params): array {
    $host = $this->normalize_cpanel_host((string)$settings['cpanel_host']);
    $username = trim((string)$settings['cpanel_username']);
    $token = trim((string)$settings['cpanel_api_token']);
    if ($host === '' || $username === '' || $token === '') {
      return ['success' => false, 'message' => 'cPanel host, username, and API token are required.'];
    }

    $url = 'https://' . $host . ':2083/execute/' . ltrim($path, '/');
    if (!empty($params)) {
      $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    $response = wp_remote_get($url, [
      'timeout' => 20,
      'sslverify' => !empty($settings['cpanel_verify_ssl']),
      'headers' => [
        'Authorization' => 'cpanel ' . $username . ':' . $token,
        'Accept' => 'application/json',
      ],
    ]);

    if (is_wp_error($response)) {
      return ['success' => false, 'message' => $response->get_error_message(), 'debug' => 'Request URL: ' . $url];
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $body = (string)wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    if ($code < 200 || $code >= 300) {
      $msg = 'cPanel returned HTTP ' . $code . '.';
      if (is_array($decoded)) {
        $debug = $this->extract_cpanel_debug_details($decoded);
        if ($debug !== '') $msg .= ' ' . $debug;
      } elseif (trim($body) !== '') {
        $msg .= ' ' . wp_strip_all_tags(wp_html_excerpt($body, 800, '…'));
      }
      return ['success' => false, 'message' => $msg, 'response' => $decoded, 'http_code' => $code, 'raw_body' => $body];
    }

    if (!is_array($decoded)) {
      return ['success' => false, 'message' => 'cPanel returned an unreadable response.', 'http_code' => $code, 'raw_body' => $body];
    }

    $result = $this->cpanel_result_payload($decoded);
    $status = intval($result['status'] ?? 0);

    if ($status !== 1) {
      $message = $this->extract_cpanel_debug_details($decoded);
      if ($message === '') $message = 'cPanel reported a failed API status.';
      return ['success' => false, 'message' => $message, 'response' => $decoded, 'http_code' => $code, 'raw_body' => $body];
    }

    $message = 'cPanel action completed.';
    if (!empty($result['messages']) && is_array($result['messages'])) {
      $message = implode(' ', array_map('strval', $result['messages']));
    }

    return [
      'success' => true,
      'message' => $message,
      'response' => $decoded,
      'http_code' => $code,
    ];
  }

  private function cpanel_result_payload(array $decoded): array {
    if (isset($decoded['result']) && is_array($decoded['result'])) {
      return $decoded['result'];
    }

    $top_level_keys = ['status', 'data', 'errors', 'messages', 'warnings', 'metadata'];
    foreach ($top_level_keys as $key) {
      if (array_key_exists($key, $decoded)) {
        return $decoded;
      }
    }

    return [];
  }

  private function normalize_cpanel_host(string $host): string {
    $host = trim($host);
    if ($host === '') return '';
    if (strpos($host, '://') !== false) {
      $parsed = wp_parse_url($host, PHP_URL_HOST);
      if (is_string($parsed) && $parsed !== '') $host = $parsed;
    }
    $host = preg_replace('#/.*$#', '', $host);
    $host = preg_replace('/:\d+$/', '', (string)$host);
    return trim((string)$host);
  }

  private function extract_cpanel_debug_details(array $decoded): string {
    $parts = [];
    $result = $this->cpanel_result_payload($decoded);
    foreach (['errors', 'messages', 'warnings'] as $bucket) {
      if (!empty($result[$bucket])) {
        if (is_array($result[$bucket])) {
          foreach ($result[$bucket] as $item) {
            if (is_scalar($item) && trim((string)$item) !== '') $parts[] = trim((string)$item);
          }
        } elseif (is_scalar($result[$bucket]) && trim((string)$result[$bucket]) !== '') {
          $parts[] = trim((string)$result[$bucket]);
        }
      }
    }
    if (!empty($result['metadata']) && is_array($result['metadata'])) {
      foreach (['reason', 'result', 'output'] as $key) {
        if (isset($result['metadata'][$key]) && is_scalar($result['metadata'][$key]) && trim((string)$result['metadata'][$key]) !== '') {
          $parts[] = trim((string)$result['metadata'][$key]);
        }
      }
    }
    $parts = array_values(array_unique(array_filter($parts, static function($v){ return $v !== ''; })));
    return implode(' ', $parts);
  }

  private function format_debug_details(array $result): string {
    $lines = [];
    if (!empty($result['message'])) $lines[] = 'Message: ' . (string)$result['message'];
    if (!empty($result['http_code'])) $lines[] = 'HTTP code: ' . (string)$result['http_code'];
    if (!empty($result['response']) && is_array($result['response'])) {
      $lines[] = 'Response JSON:';
      $lines[] = '<pre style="white-space:pre-wrap;max-height:320px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:10px;">' . esc_html(wp_json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
    } elseif (!empty($result['raw_body'])) {
      $lines[] = 'Raw response:';
      $lines[] = '<pre style="white-space:pre-wrap;max-height:320px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:10px;">' . esc_html((string)$result['raw_body']) . '</pre>';
    } elseif (!empty($result['debug'])) {
      $lines[] = 'Debug:';
      $lines[] = '<pre style="white-space:pre-wrap;max-height:320px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:10px;">' . esc_html((string)$result['debug']) . '</pre>';
    }
    return implode("
", $lines);
  }

  public function shortcode_webmail_portal($atts = []): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-private-profile"><p>You must be logged in to access mail tools.</p></div>';
    }
    $atts = shortcode_atts([
      'user_id' => '',
      'title' => 'Mail Access',
      'auto_launch_single' => '1',
    ], (array)$atts, 'eum_webmail_portal');

    $actor_user_id = get_current_user_id();
    $target_user_id = intval($atts['user_id']);
    if ($target_user_id <= 0) $target_user_id = $actor_user_id;

    return $this->render_access_section($actor_user_id, $target_user_id, (string)$atts['title'], [
      'auto_launch_single' => !empty($atts['auto_launch_single']),
    ]);
  }

  public function render_access_section(int $actor_user_id, int $target_user_id, string $title = 'Mail Access', array $args = []): string {
    $settings = self::get_settings();
    if (empty($settings['webmail_enabled'])) return '';

    $target_user = get_user_by('id', $target_user_id);
    if (!$target_user) return '';

    $args = array_merge([
      'auto_launch_single' => false,
    ], $args);

    $mailboxes = $this->accessible_mailboxes($actor_user_id, $target_user_id);
    if (empty($mailboxes)) {
      return '<div class="eum-private-profile__section"><h4 style="margin-top:0;">' . esc_html($title) . '</h4><p>No mailboxes are available for this account.</p></div>';
    }

    if (!empty($args['auto_launch_single']) && count($mailboxes) === 1) {
      $launch_url = $this->build_launch_url($target_user, (string)$mailboxes[0]['email']);
      $label = sanitize_text_field((string)$mailboxes[0]['label']);
      return '<div class="eum-private-profile__section"><h4 style="margin-top:0;">' . esc_html($title) . '</h4><p>Opening ' . esc_html($label) . '…</p><script>window.location.href=' . wp_json_encode($launch_url) . ';</script><noscript><p><a class="button" href="' . esc_url($launch_url) . '">Continue to Mail</a></p></noscript></div>';
    }

    $new_tab = !empty($settings['open_new_tab']);
    $target_attrs = $new_tab ? ' target="_blank" rel="noopener"' : '';

    ob_start();
    echo '<div class="eum-private-profile__section">';
    echo '<h4 style="margin-top:0;">' . esc_html($title) . '</h4>';
    if (count($mailboxes) > 1) {
      echo '<p>Select which mailbox to open.</p>';
    }
    echo '<ul class="eum-personnel-files" style="margin-top:8px;">';
    foreach ($mailboxes as $row) {
      $email = sanitize_email((string)($row['email'] ?? ''));
      if ($email === '') continue;
      $label = sanitize_text_field((string)($row['label'] ?? $email));
      $meta = sanitize_text_field((string)($row['meta'] ?? ''));
      $link = $this->build_launch_url($target_user, $email);
      echo '<li><div><strong>' . esc_html($label) . '</strong>';
      echo '<span class="eum-personnel-files__meta">' . esc_html($email . ($meta !== '' ? ' · ' . $meta : '')) . '</span></div>';
      echo '<div><a class="button" href="' . esc_url($link) . '"' . $target_attrs . '>' . esc_html($settings['webmail_label']) . '</a></div></li>';
    }
    echo '</ul>';

    $last_sync = get_user_meta($target_user_id, self::META_LAST_SYNC, true);
    if (is_array($last_sync) && !empty($last_sync['time'])) {
      echo '<p class="description" style="margin-top:12px;">Last mail/service sync: ' . esc_html((string)$last_sync['time']) . ' · ' . esc_html((string)($last_sync['event'] ?? '')) . ' · ' . (!empty($last_sync['success']) ? 'Success' : 'Error') . '</p>';
    }

    echo '</div>';
    return ob_get_clean();
  }

  private function accessible_mailboxes(int $actor_user_id, int $target_user_id): array {
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($target_user_id)) return [];
    $rows = [];
    $seen = [];

    if ($actor_user_id === $target_user_id) {
      $user = get_user_by('id', $target_user_id);
      $primary_email = $user ? sanitize_email((string)$user->user_email) : '';
      if ($primary_email !== '') {
        $key = strtolower($primary_email);
        $seen[$key] = true;
        $rows[] = [
          'label' => 'My Mailbox',
          'email' => $primary_email,
          'meta' => 'Primary account',
          'kind' => 'primary',
        ];
      }
    }

    $extra_boxes = class_exists('EUM_Manager_IDs') ? EUM_Manager_IDs::additional_mailboxes_for_manager_user($actor_user_id) : [];
    foreach ($extra_boxes as $row) {
      $email = sanitize_email((string)($row['email'] ?? ''));
      if ($email === '') continue;
      $key = strtolower($email);
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $rows[] = [
        'label' => sanitize_text_field((string)($row['label'] ?? $email)),
        'email' => $email,
        'meta' => sanitize_text_field((string)($row['manager_id_label'] ?? 'Shared mailbox')),
        'kind' => 'manager_id',
      ];
    }

    return $rows;
  }

  private function build_launch_url(WP_User $user, string $mailbox_email = ''): string {
    return add_query_arg([
      'action' => 'eum_webmail_launch',
      'target_user' => (int)$user->ID,
      'mailbox' => sanitize_email($mailbox_email !== '' ? $mailbox_email : (string)$user->user_email),
    ], admin_url('admin-post.php'));
  }

  public function current_user_app_launch_url(): string {
    if (!is_user_logged_in()) return '';
    $user_id = (int)get_current_user_id();
    $settings = self::get_settings();
    if ($user_id <= 0 || empty($settings['webmail_enabled'])) return '';
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) return '';

    $user = get_user_by('id', $user_id);
    if (!$user instanceof WP_User) return '';
    $mailboxes = $this->accessible_mailboxes($user_id, $user_id);
    if (empty($mailboxes) || empty($mailboxes[0]['email'])) return '';

    return $this->build_launch_url($user, (string)$mailboxes[0]['email']);
  }

  public function current_user_app_launch_items(): array {
    if (!is_user_logged_in()) return [];
    $user_id = (int)get_current_user_id();
    $settings = self::get_settings();
    if ($user_id <= 0 || empty($settings['webmail_enabled'])) return [];
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) return [];

    $user = get_user_by('id', $user_id);
    if (!$user instanceof WP_User) return [];

    $items = [];
    foreach ($this->accessible_mailboxes($user_id, $user_id) as $mailbox) {
      $email = sanitize_email((string)($mailbox['email'] ?? ''));
      if ($email === '') continue;

      $label = sanitize_text_field((string)($mailbox['label'] ?? ''));
      if ($label === '') $label = $email;
      $items[] = [
        'label' => $label,
        'email' => $email,
        'url' => $this->build_launch_url($user, $email),
      ];
    }

    return $items;
  }

  public function handle_webmail_launch(): void {
    if (!is_user_logged_in()) {
      auth_redirect();
      exit;
    }

    $actor_user_id = get_current_user_id();
    $target_user_id = isset($_GET['target_user']) ? intval($_GET['target_user']) : $actor_user_id;
    if ($target_user_id <= 0) $target_user_id = $actor_user_id;

    $target_user = get_user_by('id', $target_user_id);
    if (!$target_user) wp_die('Invalid mailbox target.');

    $mailboxes = $this->accessible_mailboxes($actor_user_id, $target_user_id);
    if (empty($mailboxes)) wp_die('No mailboxes are available for this account.');

    $requested_mailbox = isset($_GET['mailbox']) ? sanitize_email(rawurldecode((string)$_GET['mailbox'])) : '';
    $selected = null;
    foreach ($mailboxes as $row) {
      if ($requested_mailbox !== '' && strtolower($requested_mailbox) === strtolower((string)$row['email'])) {
        $selected = $row;
        break;
      }
    }
    if ($selected === null && count($mailboxes) === 1) {
      $selected = $mailboxes[0];
    }
    if ($selected === null) {
      echo $this->render_mailbox_selection_screen($target_user, $mailboxes);
      exit;
    }

    $settings = self::get_settings();
    if ($settings['webmail_mode'] !== 'cpanel_auto') {
      wp_safe_redirect($this->build_fallback_webmail_url($target_user, (string)$selected['email']));
      exit;
    }

    $session = $this->create_cpanel_webmail_session($settings, (string)$selected['email']);
    if (empty($session['success'])) {
      $fallback = $this->build_fallback_webmail_url($target_user, (string)$selected['email']);
      $extra = $this->format_debug_details($session);
      wp_die(
        '<p><strong>Automatic Webmail login failed.</strong></p><p>' . esc_html((string)($session['message'] ?? 'Unknown error.')) . '</p>' . ($extra !== '' ? '<div style="margin:12px 0;">' . $extra . '</div>' : '') . '<p><a class="button button-primary" href="' . esc_url($fallback) . '">Open Webmail Manually</a></p>',
        'Mail Access Error',
        ['response' => 500]
      );
    }

    $post_url = (string)($session['post_url'] ?? '');
    $session_value = (string)($session['session'] ?? '');
    if ($post_url === '' || $session_value === '') {
      wp_die('Automatic Webmail login did not return a usable session.');
    }

    echo $this->render_auto_login_post_page($post_url, $session_value, (string)$selected['email']);
    exit;
  }

  private function render_mailbox_selection_screen(WP_User $target_user, array $mailboxes): string {
    $settings = self::get_settings();
    ob_start();
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<title>Select Mailbox</title>';
    echo '<style>*{box-sizing:border-box;}body{font-family:Arial,sans-serif;background:#f6f7f7;margin:0;padding:clamp(16px,5vw,32px);color:#111827;} .eum-mail-wrap{width:min(720px,100%);margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:clamp(18px,5vw,24px);} .eum-mail-list{list-style:none;margin:18px 0 0 0;padding:0;} .eum-mail-list li{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:14px 0;border-top:1px solid #f0f0f1;} .eum-mail-meta{display:block;color:#646970;font-size:13px;margin-top:4px;overflow-wrap:anywhere;}.button{display:inline-block;padding:10px 14px;background:#2271b1;color:#fff;text-decoration:none;border-radius:8px;text-align:center;}@media(max-width:640px){.eum-mail-list li{flex-direction:column;align-items:stretch;gap:10px;}.button{width:100%;}}</style>';
    echo '</head><body><div class="eum-mail-wrap">';
    echo '<h1 style="margin-top:0;">Choose a mailbox</h1>';
    echo '<p>Select the mailbox you want to open for ' . esc_html($target_user->display_name ?: $target_user->user_login) . '.</p>';
    echo '<ul class="eum-mail-list">';
    foreach ($mailboxes as $row) {
      $email = sanitize_email((string)($row['email'] ?? ''));
      if ($email === '') continue;
      $label = sanitize_text_field((string)($row['label'] ?? $email));
      $meta = sanitize_text_field((string)($row['meta'] ?? ''));
      echo '<li><div><strong>' . esc_html($label) . '</strong><span class="eum-mail-meta">' . esc_html($email . ($meta !== '' ? ' · ' . $meta : '')) . '</span></div>';
      echo '<div><a class="button" href="' . esc_url($this->build_launch_url($target_user, $email)) . '">' . esc_html($settings['webmail_label']) . '</a></div></li>';
    }
    echo '</ul></div></body></html>';
    return ob_get_clean();
  }

  private function render_auto_login_post_page(string $post_url, string $session_value, string $mailbox_email): string {
    ob_start();
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<title>Opening Mail</title>';
    echo '<style>*{box-sizing:border-box;}body{font-family:Arial,sans-serif;background:#f6f7f7;margin:0;padding:clamp(16px,5vw,32px);color:#111827;} .eum-mail-wrap{width:min(640px,100%);margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:clamp(18px,5vw,24px);} .button{display:inline-block;padding:10px 14px;background:#2271b1;color:#fff;text-decoration:none;border-radius:8px;border:0;cursor:pointer;text-align:center;}@media(max-width:640px){.button{width:100%;}}</style>';
    echo '</head><body><div class="eum-mail-wrap">';
    echo '<h1 style="margin-top:0;">Opening Mail</h1>';
    echo '<p>Logging you into ' . esc_html($mailbox_email) . '…</p>';
    echo '<form id="eum-cpanel-webmail-login" method="post" action="' . esc_url($post_url) . '">';
    echo '<input type="hidden" name="session" value="' . esc_attr($session_value) . '">';
    echo '<noscript><button class="button" type="submit">Continue to Mail</button></noscript>';
    echo '</form>';
    echo '<script>document.getElementById("eum-cpanel-webmail-login").submit();</script>';
    echo '</div></body></html>';
    return ob_get_clean();
  }

  private function create_cpanel_webmail_session(array $settings, string $mailbox_email): array {
    [$local, $domain] = $this->split_email($mailbox_email);
    if ($local === '' || $domain === '') {
      return ['success' => false, 'message' => 'Invalid mailbox address.'];
    }

    $params = [
      'login' => $local,
      'domain' => $domain,
      'locale' => (string)$settings['cpanel_locale'],
    ];

    $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string)$_SERVER['REMOTE_ADDR']) : '';
    if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      $params['remote_address'] = $remote;
    }

    $result = $this->cpanel_uapi_request($settings, 'Session/create_webmail_session_for_mail_user', $params);
    if (empty($result['success'])) return $result;

    $response = isset($result['response']) && is_array($result['response']) ? $result['response'] : [];
    $payload = $this->cpanel_result_payload($response);
    $data = $payload['data'] ?? [];
    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
      $data = $data[0];
    }
    if (!is_array($data)) {
      return ['success' => false, 'message' => 'cPanel did not return Webmail session data.'];
    }

    $hostname = sanitize_text_field((string)($data['hostname'] ?? ''));
    $token = sanitize_text_field((string)($data['token'] ?? ''));
    $session_value = sanitize_text_field((string)($data['session'] ?? ''));
    if ($hostname === '') $hostname = (string)$settings['cpanel_host'];

    if ($hostname === '' || $token === '' || $session_value === '') {
      return ['success' => false, 'message' => 'cPanel returned incomplete Webmail session data.'];
    }

    return [
      'success' => true,
      'message' => 'Webmail session created.',
      'post_url' => 'https://' . $hostname . ':2096' . $token . '/login',
      'session' => $session_value,
    ];
  }

  public function build_webmail_url(WP_User $user, string $mailbox_email = ''): string {
    $settings = self::get_settings();
    if ($settings['webmail_mode'] === 'cpanel_auto') {
      return $this->build_launch_url($user, $mailbox_email !== '' ? $mailbox_email : (string)$user->user_email);
    }
    return $this->build_fallback_webmail_url($user, $mailbox_email);
  }

  private function build_fallback_webmail_url(WP_User $user, string $mailbox_email = ''): string {
    $settings = self::get_settings();
    $template = (string)$settings['webmail_url_template'];
    if ($template === '') return '';

    $email = $mailbox_email !== '' ? sanitize_email($mailbox_email) : sanitize_email((string)$user->user_email);
    $domain = '';
    if ($email !== '' && strpos($email, '@') !== false) {
      $parts = explode('@', $email, 2);
      $domain = $parts[1] ?? '';
    }

    $replacements = [
      '{email}' => rawurlencode($email),
      '{login}' => rawurlencode((string)$user->user_login),
      '{domain}' => rawurlencode($domain),
      '{site_url}' => rawurlencode(home_url('/')),
      '{mailbox}' => rawurlencode($email),
    ];

    return strtr($template, $replacements);
  }

  private function split_email(string $email): array {
    $email = sanitize_email($email);
    if ($email === '' || strpos($email, '@') === false) return ['', ''];
    [$local, $domain] = explode('@', $email, 2);
    return [trim($local), trim($domain)];
  }

  private function log_runtime_issue(string $context, \Throwable $e): void {
    eum_log_message('[EUM Service Bridge] ' . $context . ': ' . $e->getMessage());
  }
}
