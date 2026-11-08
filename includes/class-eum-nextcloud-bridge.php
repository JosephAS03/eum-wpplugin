<?php
if (!defined('ABSPATH')) exit;

class EUM_Nextcloud_Bridge {
  const OPTION_SETTINGS = 'eum_nextcloud_settings';
  const META_LAST_SYNC = '_eum_nextcloud_last_sync';
  const META_APP_ACCESS = '_eum_nextcloud_app_access';
  const META_LAST_AVATAR_SYNC = '_eum_nextcloud_last_avatar_sync';
  const META_LAST_ERROR = '_eum_nextcloud_last_error';
  const META_LAST_AVATAR_FINGERPRINT = '_eum_nextcloud_last_avatar_fingerprint';
  const CRON_HOOK = 'eum_nextcloud_periodic_sync';
  const CRON_FULL_SYNC_HOOK = 'eum_nextcloud_queued_full_sync';
  const CRON_SINGLE_HOOK = 'eum_nextcloud_single_sync';
  const CRON_SCHEDULE = 'eum_nextcloud_every_fifteen_minutes';
  const MANAGED_GROUP_PREFIX = 'eum-';

  private static array $pending_new_users = [];

  public function init(): void {
    add_filter('cron_schedules', [$this, 'cron_schedules']);
    add_action('init', [$this, 'ensure_cron_scheduled'], 20);
    add_action(self::CRON_HOOK, [$this, 'handle_periodic_sync']);
    add_action(self::CRON_FULL_SYNC_HOOK, [$this, 'handle_queued_full_sync']);
    add_action(self::CRON_SINGLE_HOOK, [$this, 'handle_single_user_sync'], 10, 1);

    add_action('admin_menu', [$this, 'admin_menu'], 46);
    add_action('admin_post_eum_nextcloud_save', [$this, 'handle_save']);
    add_action('admin_post_eum_nextcloud_test', [$this, 'handle_test']);
    add_action('admin_post_eum_nextcloud_test_companion', [$this, 'handle_test_companion']);
    add_action('admin_post_eum_nextcloud_provision', [$this, 'handle_provision']);
    add_action('admin_post_eum_nextcloud_full_sync', [$this, 'handle_full_sync']);

    add_shortcode('eum_nextcloud_portal', [$this, 'shortcode_portal']);

    add_action('eum_profile_image_uploaded', [$this, 'handle_profile_image_uploaded'], 10, 4);
    add_action('eum_profile_image_removed', [$this, 'handle_profile_image_removed'], 10, 2);

    add_filter('wp_pre_insert_user_data', [$this, 'capture_new_user_payload'], 11, 4);
    add_action('user_register', [$this, 'handle_user_register'], 11, 1);
    add_action('eum_preaccepted_candidate_user_ready', [$this, 'handle_preaccepted_candidate_user_ready'], 11, 3);
    add_action('eum_preaccepted_candidate_existing_user_ready', [$this, 'handle_preaccepted_candidate_existing_user_ready'], 11, 3);
    add_action('delete_user', [$this, 'handle_user_delete'], 11, 3);
    add_action('profile_update', [$this, 'handle_profile_update'], 11, 2);
    add_action('wp_set_password', [$this, 'handle_wp_set_password'], 11, 3);
    add_action('wp_login', [$this, 'handle_wp_login'], 11, 2);
    add_action('set_user_role', [$this, 'handle_set_user_role'], 11, 3);
    add_action('eum_employment_status_changed', [$this, 'handle_employment_status_changed'], 11, 4);

    add_action('set_object_terms', [$this, 'handle_object_terms_change'], 10, 6);
    add_action('updated_user_meta', [$this, 'handle_user_meta_change'], 10, 4);
    add_action('added_user_meta', [$this, 'handle_user_meta_change'], 10, 4);
    add_action('updated_term_meta', [$this, 'handle_term_meta_change'], 10, 4);
    add_action('added_term_meta', [$this, 'handle_term_meta_change'], 10, 4);
    add_action('updated_post_meta', [$this, 'handle_post_meta_change'], 10, 4);
    add_action('added_post_meta', [$this, 'handle_post_meta_change'], 10, 4);
    add_action('save_post', [$this, 'handle_general_post_save'], 10, 3);
    add_action('deleted_post', [$this, 'handle_general_post_delete'], 10, 2);
    add_action('trashed_post', [$this, 'handle_general_post_status_change'], 10, 1);
    add_action('untrashed_post', [$this, 'handle_general_post_status_change'], 10, 1);
    add_action('created_term', [$this, 'handle_general_term_change'], 10, 3);
    add_action('edited_term', [$this, 'handle_general_term_change'], 10, 3);
    add_action('delete_term', [$this, 'handle_general_term_deleted'], 10, 5);
  }

  public static function default_settings(): array {
    $default_base = trailingslashit(home_url('/nextcloud'));
    return [
      'enabled' => 1,
      'label' => 'Open Nextcloud',
      'base_url' => $default_base,
      'open_new_tab' => 1,
      'userid_mode' => 'user_login',
      'admin_username' => '',
      'admin_secret' => '',
      'verify_ssl' => 0,
      'sync_on_create' => 1,
      'sync_on_delete' => 1,
      'sync_on_password_change' => 1,
      'sync_on_email_change' => 1,
      'sync_on_termination' => 1,
      'sync_on_reactivation' => 1,
      'sync_groups' => 1,
      'sync_manager_groups' => 1,
      'sync_role_groups' => 1,
      'sync_group_managers' => 1,
      'force_wp_login_non_it' => 1,
      'oidc_login_url' => 'https://internal.jas.enterprises/cloud/index.php/apps/user_oidc/login/1',
      'project_group_prefix' => 'Project: ',
      'manager_group_prefix' => 'Manager: ',
      'it_group_label' => 'IT',
      'hr_group_label' => 'HR',
      'manager_role_group_label' => 'Managers',
      'clocked_in_role_group_label' => 'Clocked in',
      'it_direct_login_path' => 'login?direct=1',
      'sync_interval_minutes' => 15,
      'companion_app_enabled' => 0,
      'companion_app_endpoint' => '',
      'companion_app_token' => '',
      'companion_app_preferred' => 1,
      'avatar_sync_enabled' => 0,
      'avatar_sync_endpoint' => '',
      'avatar_sync_token' => '',
      'avatar_sync_on_remove' => 1,
    ];
  }

  public static function get_settings(): array {
    $settings = get_option(self::OPTION_SETTINGS, []);
    if (!is_array($settings)) $settings = [];
    $settings = array_merge(self::default_settings(), $settings);
    $legacy_oidc_url = 'https://internal.josephalexandersimpsonenterprises.com/cloud/index.php/apps/user_oidc/login/4';
    if ((string)$settings['oidc_login_url'] === $legacy_oidc_url) {
      $settings['oidc_login_url'] = 'https://internal.jas.enterprises/cloud/index.php/apps/user_oidc/login/1';
      update_option(self::OPTION_SETTINGS, $settings, false);
    }

    foreach (['enabled', 'open_new_tab', 'verify_ssl', 'sync_on_create', 'sync_on_delete', 'sync_on_password_change', 'sync_on_email_change', 'sync_on_termination', 'sync_on_reactivation', 'sync_groups', 'sync_manager_groups', 'sync_role_groups', 'sync_group_managers', 'force_wp_login_non_it', 'companion_app_enabled', 'companion_app_preferred', 'avatar_sync_enabled', 'avatar_sync_on_remove'] as $key) {
      $settings[$key] = empty($settings[$key]) ? 0 : 1;
    }

    $settings['label'] = sanitize_text_field((string)$settings['label']);
    $settings['base_url'] = esc_url_raw((string)$settings['base_url']);
    $settings['userid_mode'] = in_array((string)$settings['userid_mode'], ['user_login', 'email_full', 'email_local'], true) ? (string)$settings['userid_mode'] : 'user_login';
    $settings['admin_username'] = sanitize_text_field((string)$settings['admin_username']);
    $settings['admin_secret'] = (string)$settings['admin_secret'];
    $settings['project_group_prefix'] = sanitize_text_field((string)$settings['project_group_prefix']);
    $settings['manager_group_prefix'] = sanitize_text_field((string)$settings['manager_group_prefix']);
    $settings['it_group_label'] = sanitize_text_field((string)$settings['it_group_label']);
    $settings['hr_group_label'] = sanitize_text_field((string)$settings['hr_group_label']);
    $settings['manager_role_group_label'] = sanitize_text_field((string)$settings['manager_role_group_label']);
    $settings['clocked_in_role_group_label'] = sanitize_text_field((string)$settings['clocked_in_role_group_label']);
    $settings['oidc_login_url'] = esc_url_raw((string)$settings['oidc_login_url']);
    $settings['it_direct_login_path'] = ltrim(sanitize_text_field((string)$settings['it_direct_login_path']), '/');
    $settings['sync_interval_minutes'] = max(5, (int)$settings['sync_interval_minutes']);
    $settings['companion_app_endpoint'] = esc_url_raw((string)$settings['companion_app_endpoint']);
    $settings['companion_app_token'] = trim((string)$settings['companion_app_token']);
    $settings['avatar_sync_endpoint'] = esc_url_raw((string)$settings['avatar_sync_endpoint']);
    $settings['avatar_sync_token'] = trim((string)$settings['avatar_sync_token']);

    return $settings;
  }

  public static function user_has_app_access(int $user_id): bool {
    if ($user_id <= 0 || !get_user_by('id', $user_id)) return false;
    if (empty(self::get_settings()['enabled'])) return false;
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) return false;

    $known_access = get_user_meta($user_id, self::META_APP_ACCESS, true);
    if ($known_access !== '') return $known_access === '1';

    $last_sync = get_user_meta($user_id, self::META_LAST_SYNC, true);
    if (!is_array($last_sync)) return false;

    $last_event = sanitize_key((string)($last_sync['event'] ?? ''));
    if (in_array($last_event, ['disable_account', 'delete_account'], true)) return false;

    // Older versions stored avatar failures as the user's last account sync,
    // which could hide an otherwise provisioned Nextcloud app from the launcher.
    if (in_array($last_event, ['avatar_upload', 'avatar_remove'], true)) {
      update_user_meta($user_id, self::META_APP_ACCESS, '1');
      return true;
    }
    if (empty($last_sync['success'])) return false;

    update_user_meta($user_id, self::META_APP_ACCESS, '1');
    return true;
  }

  public function admin_menu(): void {
    if (!EUM_Access::current_user_is_it()) return;
    add_submenu_page(
      EUM_Admin::parent_slug('integrations'),
      'Nextcloud',
      'Nextcloud',
      'read',
      'eum-nextcloud',
      [$this, 'render_admin_page']
    );
  }

  public function render_admin_page(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $settings = self::get_settings();
    $base_url = $this->normalize_base_url((string)$settings['base_url']);
    $oidc_default_url = !empty($settings['oidc_login_url']) ? esc_url((string)$settings['oidc_login_url']) : $base_url;
    $it_direct_url = $base_url !== '' ? $this->append_path_or_query($base_url, (string)$settings['it_direct_login_path']) : '';
    $default_companion_endpoint = $this->resolve_companion_app_endpoint($settings);
    $default_avatar_endpoint = $this->resolve_avatar_sync_endpoint($settings, '{uid}');
    $next_run = wp_next_scheduled(self::CRON_HOOK);

    if (!empty($_GET['eum_msg'])) {
      $msg = sanitize_text_field((string)$_GET['eum_msg']);
      $type = !empty($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
      $cls = ($type === 'error') ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($cls) . '"><p>' . esc_html($msg) . '</p></div>';
      if (!empty($_GET['eum_details'])) {
        $details = wp_kses_post(wpautop((string)wp_unslash($_GET['eum_details'])));
        echo '<div class="notice notice-info"><div style="padding:10px 12px;">' . $details . '</div></div>';
      }
    }

    echo '<div class="wrap"><h1>Nextcloud</h1>';
    echo '<p>Provision and sync Nextcloud accounts from WordPress, give users a front-end link into your Nextcloud server, and keep Nextcloud groups aligned with Enterprise Groups, Projects, managers, IT, and HR.</p>';
    echo '<p class="description">WordPress is the source of truth for account lifecycle here: user creation, updates, deletion, and termination/reactivation state all flow from WordPress into Nextcloud.</p>';
    echo '<p class="description">The native Nextcloud server API and the optional <code>jasavatar</code> companion app can both be connected here. WordPress can push lifecycle/profile/group data through the companion app first, with the standard Nextcloud server API still available for fallback and group-manager/subadmin sync.</p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_nextcloud_save">';
    wp_nonce_field('eum_nextcloud_save', 'eum_nextcloud_nonce');

    echo '<h2>Front-End Access</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Enable Nextcloud Access</th><td>';
    echo '<label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($settings['enabled']), true, false) . '> Show Nextcloud links in the front end and manager portal</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Button Label</th><td>';
    echo '<input type="text" class="regular-text" name="label" value="' . esc_attr($settings['label']) . '">';
    echo '</td></tr>';

    echo '<tr><th scope="row">Nextcloud Base URL</th><td>';
    echo '<input type="url" class="large-text code" name="base_url" value="' . esc_attr($settings['base_url']) . '" placeholder="https://cloud.example.com/">';
    echo '<p class="description">Use the main browser URL for your Nextcloud server.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Open in New Tab</th><td>';
    echo '<label><input type="checkbox" name="open_new_tab" value="1" ' . checked(!empty($settings['open_new_tab']), true, false) . '> Open Nextcloud in a new tab</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Require WordPress Login for Non-IT</th><td>';
    echo '<label><input type="checkbox" name="force_wp_login_non_it" value="1" ' . checked(!empty($settings['force_wp_login_non_it']), true, false) . '> Non-IT users must launch Nextcloud through the WordPress OIDC login URL; IT keeps a direct local-login path</label>';
    if ($oidc_default_url !== '') {
      echo '<p class="description">Non-IT launch URL: <code>' . esc_html($oidc_default_url) . '</code></p>';
    }
    if ($it_direct_url !== '') {
      echo '<p class="description">IT direct login URL: <code>' . esc_html($it_direct_url) . '</code></p>';
    }
    echo '<p class="description">For true instance-wide enforcement on the Nextcloud side, keep OIDC as the default login method and reserve the direct path for IT/admin access.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Non-IT OIDC Login URL</th><td>';
    echo '<input type="url" class="large-text code" name="oidc_login_url" value="' . esc_attr((string)$settings['oidc_login_url']) . '" placeholder="https://internal.jas.enterprises/cloud/index.php/apps/user_oidc/login/1">';
    echo '<p class="description">Exact URL used for non-IT launches so Nextcloud always begins the WordPress OIDC flow on the internal server path.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">IT Direct Login Path</th><td>';
    echo '<input type="text" class="regular-text code" name="it_direct_login_path" value="' . esc_attr($settings['it_direct_login_path']) . '" placeholder="login?direct=1">';
    echo '<p class="description">Path or query appended to the Nextcloud base URL for IT users.</p>';
    echo '</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Provisioning API</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Nextcloud User ID Source</th><td>';
    echo '<select name="userid_mode">';
    foreach (['user_login' => 'WordPress Username', 'email_full' => 'Full Email Address', 'email_local' => 'Email Local Part'] as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($settings['userid_mode'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Nextcloud login names cannot be renamed later, so <strong>WordPress Username</strong> is the safest long-term choice. Email-based IDs are convenient, but changing the WordPress email later will not rename the Nextcloud login automatically.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Admin Username</th><td>';
    echo '<input type="text" class="regular-text code" name="admin_username" value="' . esc_attr($settings['admin_username']) . '" placeholder="nextcloudadmin">';
    echo '<p class="description">This account must be able to use the Nextcloud OCS Provisioning API.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Admin Password / App Token</th><td>';
    echo '<input type="password" class="large-text code" name="admin_secret" value="' . esc_attr($settings['admin_secret']) . '">';
    echo '<p class="description">Nextcloud OCS requests accept Basic Auth, including username/password or an app token.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Verify Nextcloud SSL</th><td>';
    echo '<label><input type="checkbox" name="verify_ssl" value="1" ' . checked(!empty($settings['verify_ssl']), true, false) . '> Verify the TLS certificate on Nextcloud API calls</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Sync Triggers</th><td>';
    foreach ([
      'sync_on_create' => 'Create the Nextcloud user when a WordPress user is created and a plaintext password is available',
      'sync_on_delete' => 'Delete the Nextcloud account when the WordPress user is deleted',
      'sync_on_password_change' => 'Push password changes to Nextcloud',
      'sync_on_email_change' => 'Push email/display name changes to Nextcloud',
      'sync_on_termination' => 'Disable the Nextcloud account when the user is terminated',
      'sync_on_reactivation' => 'Re-enable the Nextcloud account when the user is reactivated',
      'sync_groups' => 'Continuously sync Enterprise Groups, Projects, line-manager data, manager mappings, and role groups',
      'sync_group_managers' => 'Keep designated managers synced as Nextcloud group managers (subadmins)',
      'sync_manager_groups' => 'Create and sync dedicated manager-to-subordinate groups',
      'sync_role_groups' => 'Add users to dedicated IT, HR, Managers, and Clocked in Nextcloud groups',
    ] as $key => $label) {
      echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '> ' . esc_html($label) . '</label>';
    }
    echo '</td></tr>';

    echo '<tr><th scope="row">Companion App Bridge</th><td>';
    echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="companion_app_enabled" value="1" ' . checked(!empty($settings['companion_app_enabled']), true, false) . '> Use the <code>jasavatar</code> Nextcloud companion app for WordPress-authored provisioning, profile sync, and managed-group snapshots</label>';
    echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="companion_app_preferred" value="1" ' . checked(!empty($settings['companion_app_preferred']), true, false) . '> Prefer the companion app first, then fall back to the native Nextcloud server API if needed</label>';
    echo '<p class="description">This is the missing bridge between the WordPress plugin and the Nextcloud app package. Use the same shared token you saved in Nextcloud under the JAS Enterprise Bridge admin settings.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Companion Provision Endpoint</th><td>';
    echo '<input type="url" class="large-text code" name="companion_app_endpoint" value="' . esc_attr((string)$settings['companion_app_endpoint']) . '" placeholder="' . esc_attr($default_companion_endpoint) . '">';
    echo '<p class="description">Leave this blank to use the default companion-app route: <code>' . esc_html($default_companion_endpoint) . '</code>.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Companion Shared Token</th><td>';
    echo '<input type="text" class="large-text code" name="companion_app_token" value="' . esc_attr((string)$settings['companion_app_token']) . '" placeholder="shared-secret-token">';
    echo '<p class="description">This must match the shared bridge token configured inside the Nextcloud <code>jasavatar</code> app.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Project Group Prefix</th><td><input type="text" class="regular-text" name="project_group_prefix" value="' . esc_attr($settings['project_group_prefix']) . '"><p class="description">Displayed as the Nextcloud group name for project groups.</p></td></tr>';
    echo '<tr><th scope="row">Manager Group Prefix</th><td><input type="text" class="regular-text" name="manager_group_prefix" value="' . esc_attr($settings['manager_group_prefix']) . '"><p class="description">Displayed as the Nextcloud group name for manager/subordinate mapping groups.</p></td></tr>';
    echo '<tr><th scope="row">Role Group Labels</th><td>';
    echo '<label style="display:block;margin-bottom:6px;">IT <input type="text" class="regular-text" name="it_group_label" value="' . esc_attr($settings['it_group_label']) . '"></label>';
    echo '<label style="display:block;margin-bottom:6px;">HR <input type="text" class="regular-text" name="hr_group_label" value="' . esc_attr($settings['hr_group_label']) . '"></label>';
    echo '<label style="display:block;margin-bottom:6px;">Managers <input type="text" class="regular-text" name="manager_role_group_label" value="' . esc_attr($settings['manager_role_group_label']) . '"></label>';
    echo '<label style="display:block;margin-bottom:6px;">Clocked In <input type="text" class="regular-text" name="clocked_in_role_group_label" value="' . esc_attr($settings['clocked_in_role_group_label']) . '"></label>';
    echo '</td></tr>';
    echo '<tr><th scope="row">Periodic Sync Interval</th><td><input type="number" min="5" step="1" name="sync_interval_minutes" value="' . esc_attr((string)$settings['sync_interval_minutes']) . '"> minutes';
    if ($next_run) {
      echo '<p class="description">Next scheduled sync: ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int)$next_run)) . '</p>';
    }
    echo '</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Avatar Sync</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Enable Avatar Sync</th><td>';
    echo '<label><input type="checkbox" name="avatar_sync_enabled" value="1" ' . checked(!empty($settings['avatar_sync_enabled']), true, false) . '> Push WordPress profile image uploads into the matching Nextcloud avatar</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Avatar Sync Endpoint</th><td>';
    echo '<input type="url" class="large-text code" name="avatar_sync_endpoint" value="' . esc_attr((string)$settings['avatar_sync_endpoint']) . '" placeholder="' . esc_attr($default_avatar_endpoint) . '">';
    echo '<p class="description">Use the full Nextcloud companion-app endpoint. You can leave this blank if your base URL is set and you install the included <code>jasavatar</code> app; the plugin will use <code>' . esc_html($default_avatar_endpoint) . '</code>.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Avatar Sync Token</th><td>';
    echo '<input type="text" class="large-text code" name="avatar_sync_token" value="' . esc_attr((string)$settings['avatar_sync_token']) . '" placeholder="shared-secret-token">';
    echo '<p class="description">Optional override. If left blank, avatar sync reuses the companion shared token above.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Remove Avatar in Nextcloud Too</th><td>';
    echo '<label><input type="checkbox" name="avatar_sync_on_remove" value="1" ' . checked(!empty($settings['avatar_sync_on_remove']), true, false) . '> When a user removes their WordPress profile image, also clear the Nextcloud avatar</label>';
    echo '</td></tr>';
    echo '</tbody></table>';

    submit_button('Save Nextcloud Settings');
    echo '</form>';

    echo '<hr style="margin:28px 0;">';
    echo '<h2>Test Nextcloud Connection</h2>';
    echo '<p class="description">This tests the OCS Provisioning API with the configured admin credentials and base URL.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_nextcloud_test">';
    wp_nonce_field('eum_nextcloud_save', 'eum_nextcloud_nonce');
    submit_button('Run Nextcloud Test', 'secondary', 'submit', false);
    echo '</form>';

    echo '<h2 style="margin-top:28px;">Test Companion App Bridge</h2>';
    echo '<p class="description">This checks the <code>jasavatar</code> app endpoint and confirms the WordPress side can discover the provisioning route.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_nextcloud_test_companion">';
    wp_nonce_field('eum_nextcloud_save', 'eum_nextcloud_nonce');
    submit_button('Run Companion App Test', 'secondary', 'submit', false);
    echo '</form>';

    echo '<h2 style="margin-top:28px;">Full Group / Manager Sync</h2>';
    echo '<p class="description">Creates the managed Nextcloud groups, updates display names, syncs memberships, syncs group managers (subadmins), and refreshes the manager-to-subordinate mapping groups for all WordPress users.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_nextcloud_full_sync">';
    wp_nonce_field('eum_nextcloud_save', 'eum_nextcloud_nonce');
    submit_button('Run Full Sync Now', 'secondary', 'submit', false);
    echo '</form>';

    echo '<h2 style="margin-top:28px;">Provision Existing WordPress User</h2>';
    echo '<p class="description">Useful for users that already exist in WordPress before this integration was enabled. If you leave the password blank, Nextcloud can still receive the email and display name, but password parity will require a later reset/change or an explicit password you enter here.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_nextcloud_provision">';
    wp_nonce_field('eum_nextcloud_save', 'eum_nextcloud_nonce');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">WordPress User</th><td><input type="text" class="regular-text code" name="target_user" placeholder="ID, username, or email"></td></tr>';
    echo '<tr><th scope="row">Temporary / Initial Password</th><td><input type="password" class="regular-text code" name="plaintext_password" value=""> <p class="description">Optional. If blank, creation will rely on the user email existing in Nextcloud so an activation mail can be sent.</p></td></tr>';
    echo '</tbody></table>';
    submit_button('Provision / Sync User', 'secondary', 'submit', false);
    echo '</form>';
    echo '</div>';
  }

  public function handle_save(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_nextcloud_nonce']) || !wp_verify_nonce($_POST['eum_nextcloud_nonce'], 'eum_nextcloud_save')) wp_die('Bad nonce.');

    $settings = [
      'enabled' => isset($_POST['enabled']) ? 1 : 0,
      'label' => sanitize_text_field((string)($_POST['label'] ?? 'Open Nextcloud')),
      'base_url' => esc_url_raw((string)($_POST['base_url'] ?? '')),
      'open_new_tab' => isset($_POST['open_new_tab']) ? 1 : 0,
      'userid_mode' => sanitize_key((string)($_POST['userid_mode'] ?? 'user_login')),
      'admin_username' => sanitize_text_field((string)($_POST['admin_username'] ?? '')),
      'admin_secret' => (string)($_POST['admin_secret'] ?? ''),
      'verify_ssl' => isset($_POST['verify_ssl']) ? 1 : 0,
      'sync_on_create' => isset($_POST['sync_on_create']) ? 1 : 0,
      'sync_on_delete' => isset($_POST['sync_on_delete']) ? 1 : 0,
      'sync_on_password_change' => isset($_POST['sync_on_password_change']) ? 1 : 0,
      'sync_on_email_change' => isset($_POST['sync_on_email_change']) ? 1 : 0,
      'sync_on_termination' => isset($_POST['sync_on_termination']) ? 1 : 0,
      'sync_on_reactivation' => isset($_POST['sync_on_reactivation']) ? 1 : 0,
      'sync_groups' => isset($_POST['sync_groups']) ? 1 : 0,
      'sync_manager_groups' => isset($_POST['sync_manager_groups']) ? 1 : 0,
      'sync_role_groups' => isset($_POST['sync_role_groups']) ? 1 : 0,
      'sync_group_managers' => isset($_POST['sync_group_managers']) ? 1 : 0,
      'force_wp_login_non_it' => isset($_POST['force_wp_login_non_it']) ? 1 : 0,
      'oidc_login_url' => esc_url_raw((string)($_POST['oidc_login_url'] ?? 'https://internal.jas.enterprises/cloud/index.php/apps/user_oidc/login/1')),
      'project_group_prefix' => sanitize_text_field((string)($_POST['project_group_prefix'] ?? 'Project: ')),
      'manager_group_prefix' => sanitize_text_field((string)($_POST['manager_group_prefix'] ?? 'Manager: ')),
      'it_group_label' => sanitize_text_field((string)($_POST['it_group_label'] ?? 'IT')),
      'hr_group_label' => sanitize_text_field((string)($_POST['hr_group_label'] ?? 'HR')),
      'manager_role_group_label' => sanitize_text_field((string)($_POST['manager_role_group_label'] ?? 'Managers')),
      'clocked_in_role_group_label' => sanitize_text_field((string)($_POST['clocked_in_role_group_label'] ?? 'Clocked in')),
      'it_direct_login_path' => ltrim(sanitize_text_field((string)($_POST['it_direct_login_path'] ?? 'login?direct=1')), '/'),
      'sync_interval_minutes' => max(5, (int)($_POST['sync_interval_minutes'] ?? 15)),
      'companion_app_enabled' => isset($_POST['companion_app_enabled']) ? 1 : 0,
      'companion_app_endpoint' => esc_url_raw((string)($_POST['companion_app_endpoint'] ?? '')),
      'companion_app_token' => trim((string)($_POST['companion_app_token'] ?? '')),
      'companion_app_preferred' => isset($_POST['companion_app_preferred']) ? 1 : 0,
      'avatar_sync_enabled' => isset($_POST['avatar_sync_enabled']) ? 1 : 0,
      'avatar_sync_endpoint' => esc_url_raw((string)($_POST['avatar_sync_endpoint'] ?? '')),
      'avatar_sync_token' => trim((string)($_POST['avatar_sync_token'] ?? '')),
      'avatar_sync_on_remove' => isset($_POST['avatar_sync_on_remove']) ? 1 : 0,
    ];

    update_option(self::OPTION_SETTINGS, $settings, false);
    $this->ensure_cron_scheduled(true);
    wp_safe_redirect(admin_url('admin.php?page=eum-nextcloud&eum_msg=' . rawurlencode('Nextcloud settings saved.') . '&eum_type=success'));
    exit;
  }

  public function handle_full_sync(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_nextcloud_nonce']) || !wp_verify_nonce($_POST['eum_nextcloud_nonce'], 'eum_nextcloud_save')) wp_die('Bad nonce.');

    $result = $this->perform_full_sync();
    $msg = !empty($result['success']) ? 'Nextcloud full sync completed.' : ('Nextcloud full sync finished with errors: ' . (string)($result['message'] ?? 'Unknown error.'));
    $type = !empty($result['success']) ? 'success' : 'error';
    $details = rawurlencode($this->format_debug_details($result));
    wp_safe_redirect(admin_url('admin.php?page=eum-nextcloud&eum_msg=' . rawurlencode($msg) . '&eum_type=' . rawurlencode($type) . '&eum_details=' . $details));
    exit;
  }

  public function handle_test(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_nextcloud_nonce']) || !wp_verify_nonce($_POST['eum_nextcloud_nonce'], 'eum_nextcloud_save')) wp_die('Bad nonce.');

    $result = $this->nextcloud_request(self::get_settings(), 'GET', 'users', ['limit' => 1]);
    $msg = !empty($result['success']) ? 'Nextcloud test succeeded.' : ('Nextcloud test failed: ' . (string)($result['message'] ?? 'Unknown error.'));
    $type = !empty($result['success']) ? 'success' : 'error';
    $details = rawurlencode($this->format_debug_details($result));
    wp_safe_redirect(admin_url('admin.php?page=eum-nextcloud&eum_msg=' . rawurlencode($msg) . '&eum_type=' . rawurlencode($type) . '&eum_details=' . $details));
    exit;
  }

  public function handle_test_companion(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_nextcloud_nonce']) || !wp_verify_nonce($_POST['eum_nextcloud_nonce'], 'eum_nextcloud_save')) wp_die('Bad nonce.');

    $result = $this->nextcloud_companion_ping(self::get_settings());
    $msg = !empty($result['success']) ? 'Nextcloud companion app test succeeded.' : ('Nextcloud companion app test failed: ' . (string)($result['message'] ?? 'Unknown error.'));
    $type = !empty($result['success']) ? 'success' : 'error';
    $details = rawurlencode($this->format_debug_details($result));
    wp_safe_redirect(admin_url('admin.php?page=eum-nextcloud&eum_msg=' . rawurlencode($msg) . '&eum_type=' . rawurlencode($type) . '&eum_details=' . $details));
    exit;
  }

  public function handle_provision(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_nextcloud_nonce']) || !wp_verify_nonce($_POST['eum_nextcloud_nonce'], 'eum_nextcloud_save')) wp_die('Bad nonce.');

    $target = isset($_POST['target_user']) ? trim((string)wp_unslash($_POST['target_user'])) : '';
    $plaintext = isset($_POST['plaintext_password']) ? (string)wp_unslash($_POST['plaintext_password']) : '';
    $user = $this->resolve_wp_user($target);
    if (!$user) {
      wp_safe_redirect(admin_url('admin.php?page=eum-nextcloud&eum_msg=' . rawurlencode('Could not find that WordPress user.') . '&eum_type=error'));
      exit;
    }

    $payload = $this->base_payload('create_account', (int)$user->ID, ['plaintext_password' => $plaintext]);
    $result = $this->dispatch_event($payload);
    $msg = !empty($result['success']) ? ('Provisioned / synced ' . $user->user_login . ' to Nextcloud.') : ('Provision failed for ' . $user->user_login . ': ' . (string)($result['message'] ?? 'Unknown error.'));
    $type = !empty($result['success']) ? 'success' : 'error';
    $details = rawurlencode($this->format_debug_details($result));
    wp_safe_redirect(admin_url('admin.php?page=eum-nextcloud&eum_msg=' . rawurlencode($msg) . '&eum_type=' . rawurlencode($type) . '&eum_details=' . $details));
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
      if (empty($settings['enabled']) || empty($settings['sync_on_create'])) return;

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
      $this->queue_single_user_sync($user_id, 15);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_user_register', $e);
    }
  }

  public function handle_preaccepted_candidate_user_ready(int $user_id, int $candidate_id = 0, string $password = ''): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['enabled']) || empty($settings['sync_on_create'])) return;

      $user = get_user_by('id', $user_id);
      if (!$user) return;

      if ($password === '') {
        $this->dispatch_event($this->sync_snapshot_payload_for_user($user_id));
        $this->queue_single_user_sync($user_id, 5);
        return;
      }

      $payload = $this->base_payload('create_account', $user_id, [
        'plaintext_password' => $password,
        'candidate_id' => max(0, $candidate_id),
        'source' => 'preaccepted_candidate',
      ]);
      $this->dispatch_event($payload);
      $this->queue_single_user_sync($user_id, 5);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_preaccepted_candidate_user_ready', $e);
    }
  }

  public function handle_preaccepted_candidate_existing_user_ready(int $user_id, int $candidate_id = 0, string $password = ''): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['enabled']) || empty($settings['sync_on_create'])) return;
      if ($user_id <= 0 || !get_user_by('id', $user_id)) return;

      $this->dispatch_event($this->sync_snapshot_payload_for_user($user_id));
      $this->queue_single_user_sync($user_id, 5);
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

  public function handle_user_delete(int $user_id, $reassign = null, $user = null): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['enabled']) || empty($settings['sync_on_delete'])) return;

      $payload = $this->base_payload('delete_account', $user_id, [
        'reassign_user_id' => is_numeric($reassign) ? (int)$reassign : 0,
      ]);
      $this->dispatch_event($payload);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_user_delete', $e);
    }
  }

  public function handle_profile_update(int $user_id, WP_User $old_user_data): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['enabled'])) return;

      $user = get_user_by('id', $user_id);
      if (!$user) return;

      $old_email = sanitize_email((string)$old_user_data->user_email);
      $new_email = sanitize_email((string)$user->user_email);
      $old_display = (string)$old_user_data->display_name;
      $new_display = (string)$user->display_name;

      if (!empty($settings['sync_on_email_change']) && $old_email !== $new_email) {
        $payload = $this->base_payload('update_email', $user_id, [
          'old_email' => $old_email,
          'new_email' => $new_email,
          'old_user_login' => (string)$old_user_data->user_login,
        ]);
        $this->dispatch_event($payload);
      }

      if (!empty($settings['sync_on_email_change']) && $old_display !== $new_display) {
        $payload = $this->base_payload('update_display_name', $user_id, [
          'old_display_name' => $old_display,
          'new_display_name' => $new_display,
        ]);
        $this->dispatch_event($payload);
      }

      $this->queue_single_user_sync($user_id, 15);
      if ($old_display !== $new_display) {
        $this->queue_full_sync(45);
      }
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_profile_update', $e);
    }
  }

  public function handle_wp_set_password(string $password, int $user_id, WP_User $old_user_data): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['enabled']) || empty($settings['sync_on_password_change'])) return;
      if ($user_id <= 0 || $password === '') return;

      $payload = $this->base_payload('change_password', $user_id, [
        'plaintext_password' => $password,
        'old_user_email' => sanitize_email((string)$old_user_data->user_email),
        'old_user_login' => (string)$old_user_data->user_login,
      ]);
      $this->dispatch_event($payload);
      $this->queue_single_user_sync($user_id, 15);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_wp_set_password', $e);
    }
  }

  public function handle_wp_login(string $user_login, WP_User $user): void {
    try {
      if (!$user instanceof WP_User) return;
      if (class_exists('EUM_Workforce_Lifecycle') && EUM_Workforce_Lifecycle::should_skip_external_provisioning((int)$user->ID)) return;
      $this->queue_single_user_sync((int)$user->ID, 10);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_wp_login', $e);
    }
  }

  public function handle_set_user_role(int $user_id, string $role, array $old_roles): void {
    try {
      $user_id = (int)$user_id;
      if ($user_id <= 0) return;
      $this->queue_single_user_sync($user_id, 20);
      $this->queue_full_sync(45);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_set_user_role', $e);
    }
  }

  public function handle_employment_status_changed(int $user_id, array $before, array $after, int $actor_user_id): void {
    try {
      $settings = self::get_settings();
      if (empty($settings['enabled'])) return;

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

      if (!empty($settings['sync_groups'])) $this->queue_single_user_sync($user_id, 15);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_employment_status_changed', $e);
    }
  }

  private function base_payload(string $event, int $user_id, array $extra = []): array {
    $user = get_user_by('id', $user_id);
    $settings = self::get_settings();
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
      'nextcloud_userid' => $user ? $this->nextcloud_userid_for_user($user, $settings) : '',
    ];
    return array_merge($payload, $extra);
  }

  private function sync_snapshot_payload_for_user(int $user_id): array {
    return $this->base_payload('sync_snapshot', $user_id, [
      // Snapshot syncs may need to create a missing Nextcloud account even when
      // WordPress does not currently have the plaintext password available.
      'plaintext_password' => wp_generate_password(24, true, true),
    ]);
  }

  private function dispatch_event(array $payload): array {
    $settings = self::get_settings();
    if (empty($settings['enabled'])) {
      return ['success' => true, 'message' => 'Nextcloud integration disabled; event skipped.'];
    }

    $payload = apply_filters('eum_nextcloud_payload', $payload, $settings);
    $result = $this->dispatch_nextcloud($settings, $payload);
    $event = (string)($payload['event'] ?? '');
    $user_id = intval($payload['user_id'] ?? 0);

    if (!empty($result['success']) && $user_id > 0 && $this->event_should_sync_avatar($event)) {
      $avatar_result = $this->sync_current_avatar_to_nextcloud($user_id, $settings);
      if (!empty($avatar_result['performed']) || empty($avatar_result['success'])) {
        $this->record_avatar_sync_meta($user_id, $event, $avatar_result);
      }
    }

    if ($user_id > 0) {
      update_user_meta($user_id, self::META_LAST_SYNC, [
        'time' => current_time('mysql'),
        'event' => $event,
        'success' => !empty($result['success']) ? 1 : 0,
        'message' => (string)($result['message'] ?? ''),
      ]);
      if (!empty($result['success'])) {
        update_user_meta(
          $user_id,
          self::META_APP_ACCESS,
          in_array($event, ['disable_account', 'delete_account'], true) ? '0' : '1'
        );
      }
      if (!empty($result['success'])) {
        delete_user_meta($user_id, self::META_LAST_ERROR);
      } else {
        update_user_meta($user_id, self::META_LAST_ERROR, [
          'time' => current_time('mysql'),
          'event' => $event,
          'message' => (string)($result['message'] ?? 'Unknown error'),
        ]);
      }
    }

    return $result;
  }

  private function dispatch_nextcloud(array $settings, array $payload): array {
    $user_id = intval($payload['user_id'] ?? 0);
    $user = get_user_by('id', $user_id);
    $event = (string)($payload['event'] ?? '');
    if (!$user && $event !== 'delete_account') return ['success' => false, 'message' => 'Target WordPress user not found.'];

    $nextcloud_userid = isset($payload['nextcloud_userid'])
      ? (string)$payload['nextcloud_userid']
      : ($user ? $this->nextcloud_userid_for_user($user, $settings) : '');
    if ($nextcloud_userid === '') {
      return ['success' => false, 'message' => 'Could not derive a Nextcloud user ID from this WordPress user.'];
    }

    if ($this->should_prefer_companion_app($settings, $event)) {
      $companion_result = $this->dispatch_companion_event($settings, $payload, $user, $nextcloud_userid);
      if (!empty($companion_result['success'])) {
        return $companion_result;
      }
    }

    return $this->dispatch_nextcloud_native($settings, $payload, $user, $nextcloud_userid);
  }

  private function dispatch_nextcloud_native(array $settings, array $payload, ?WP_User $user, string $nextcloud_userid): array {
    $user_id = intval($payload['user_id'] ?? 0);
    $event = (string)($payload['event'] ?? '');

    if (in_array($event, ['sync_snapshot', 'update_profile'], true)) {
      return $this->sync_native_user_snapshot($settings, $payload, $user, $nextcloud_userid, false);
    }

    if ($event === 'create_account') {
      return $this->sync_native_user_snapshot($settings, $payload, $user, $nextcloud_userid, true);
    }

    if ($event === 'change_password') {
      $password = (string)($payload['plaintext_password'] ?? '');
      if ($password === '') return ['success' => false, 'message' => 'Nextcloud password sync skipped because no plaintext password was available.'];
      return $this->nextcloud_edit_user($settings, $nextcloud_userid, 'password', $password);
    }

    if ($event === 'update_email') {
      $old_user = [
        'user_login' => (string)($payload['old_user_login'] ?? $user->user_login),
        'user_email' => (string)($payload['old_email'] ?? $user->user_email),
      ];
      $old_nextcloud_userid = $this->nextcloud_userid_from_values($old_user, $settings['userid_mode']);
      if ($old_nextcloud_userid !== $nextcloud_userid) {
        return ['success' => false, 'message' => 'Nextcloud login IDs cannot be renamed automatically. Update the Nextcloud login manually or keep the user ID source stable.'];
      }
      return $this->nextcloud_edit_user($settings, $nextcloud_userid, 'email', sanitize_email((string)($payload['new_email'] ?? $user->user_email)));
    }

    if ($event === 'update_display_name') {
      return $this->nextcloud_edit_user($settings, $nextcloud_userid, 'displayname', (string)($payload['new_display_name'] ?? $user->display_name));
    }

    if ($event === 'disable_account') {
      return $this->nextcloud_request($settings, 'PUT', 'users/' . rawurlencode($nextcloud_userid) . '/disable');
    }

    if ($event === 'enable_account') {
      $result = $this->nextcloud_request($settings, 'PUT', 'users/' . rawurlencode($nextcloud_userid) . '/enable');
      if (!empty($result['success']) && !empty($settings['sync_groups'])) {
        $sync = $this->sync_user_access_and_memberships($user_id, $settings);
        return $this->combine_results([$result, $sync], 'Nextcloud account re-enabled and memberships synced.');
      }
      return $result;
    }

    if ($event === 'delete_account') {
      $exists = $this->nextcloud_request($settings, 'GET', 'users/' . rawurlencode($nextcloud_userid));
      if (empty($exists['success'])) {
        return ['success' => true, 'message' => 'Nextcloud account was already absent.'];
      }
      return $this->nextcloud_request($settings, 'DELETE', 'users/' . rawurlencode($nextcloud_userid));
    }

    if ($event === 'sync_memberships') {
      return $this->sync_user_access_and_memberships($user_id, $settings);
    }

    return ['success' => true, 'message' => 'No Nextcloud action mapped for event ' . $event . '.'];
  }

  private function dispatch_companion_event(array $settings, array $payload, ?WP_User $user, string $nextcloud_userid): array {
    $event = (string)($payload['event'] ?? '');
    $body = $this->build_companion_payload($settings, $payload, $user, $nextcloud_userid);
    $result = $this->nextcloud_companion_request($settings, 'POST', $this->resolve_companion_app_endpoint($settings), $body, true);
    if (empty($result['success'])) {
      return $result;
    }

    if (in_array($event, ['create_account', 'enable_account', 'sync_memberships', 'sync_snapshot', 'update_profile'], true)) {
      $subadmin_sync = $this->sync_user_subadmin_assignments(intval($payload['user_id'] ?? 0), $settings, $nextcloud_userid);
      if (!empty($subadmin_sync['performed'])) {
        return $this->combine_results([$result, $subadmin_sync], 'Nextcloud companion sync completed, including group-manager assignments.');
      }
    }

    return $result;
  }

  private function build_companion_payload(array $settings, array $payload, ?WP_User $user, string $nextcloud_userid): array {
    $user_id = intval($payload['user_id'] ?? 0);
    $body = [
      'event' => (string)($payload['event'] ?? ''),
      'timestamp' => (string)($payload['timestamp'] ?? current_time('mysql')),
      'site_url' => (string)($payload['site_url'] ?? home_url('/')),
      'user_id' => $user_id,
      'nextcloud_userid' => $nextcloud_userid,
      'uid' => $nextcloud_userid,
      'user_login' => $user ? (string)$user->user_login : (string)($payload['user_login'] ?? ''),
      'user_email' => $user ? sanitize_email((string)$user->user_email) : sanitize_email((string)($payload['user_email'] ?? '')),
      'display_name' => $user ? (string)$user->display_name : (string)($payload['display_name'] ?? ''),
      'terminated' => !empty($payload['terminated']) ? 1 : 0,
      'enabled' => !empty($payload['terminated']) ? 0 : 1,
    ];

    if (!empty($payload['plaintext_password'])) {
      $body['plaintext_password'] = (string)$payload['plaintext_password'];
    }
    if (!empty($payload['new_email'])) {
      $body['new_email'] = sanitize_email((string)$payload['new_email']);
    }
    if (!empty($payload['new_display_name'])) {
      $body['new_display_name'] = (string)$payload['new_display_name'];
    }

    if ($user) {
      $line_manager_uid = $this->desired_line_manager_uid_for_user($user_id, $settings);
      if ($line_manager_uid !== '') {
        $body['line_manager_uid'] = $line_manager_uid;
      }
      $body['manager_uids'] = $this->desired_manager_uids_for_user($user_id, $settings);
    }

    if ($user && !empty($settings['sync_groups'])) {
      $group_defs = array_values($this->desired_group_definitions_for_user($user_id, $settings));
      $body['group_definitions'] = $group_defs;
      $body['desired_group_ids'] = array_values(array_map(static fn($def) => (string)($def['groupid'] ?? ''), $group_defs));
    }

    return $body;
  }

  private function sync_native_user_snapshot(array $settings, array $payload, ?WP_User $user, string $nextcloud_userid, bool $allow_password_update): array {
    $user_id = intval($payload['user_id'] ?? 0);
    if (!$user) {
      return ['success' => false, 'message' => 'Target WordPress user not found.'];
    }

    $password = (string)($payload['plaintext_password'] ?? '');
    if ($password === '') {
      $password = wp_generate_password(24, true, true);
    }

    $display_name = (string)($payload['new_display_name'] ?? $user->display_name);
    $email = sanitize_email((string)($payload['new_email'] ?? $user->user_email));
    $enabled = array_key_exists('enabled', $payload)
      ? !empty($payload['enabled'])
      : empty($payload['terminated']);

    $exists = $this->nextcloud_request($settings, 'GET', 'users/' . rawurlencode($nextcloud_userid));
    $syncs = [];

    if (empty($exists['success'])) {
      if (class_exists('EUM_Workforce_Lifecycle') && EUM_Workforce_Lifecycle::should_skip_external_provisioning($user_id)) {
        return ['success' => true, 'message' => 'Nextcloud provisioning skipped for terminated portal user.'];
      }

      $managed_groups = !empty($settings['sync_groups']) ? $this->desired_group_definitions_for_user($user_id, $settings) : [];
      if (!empty($settings['sync_groups'])) {
        $syncs[] = $this->ensure_group_definitions($settings, $managed_groups);
      }

      $body = [
        'userid' => $nextcloud_userid,
        'displayName' => $display_name,
        'email' => $email,
        'password' => $password,
      ];
      $group_ids = array_keys($managed_groups);
      $subadmin_ids = !empty($settings['sync_groups']) ? $this->desired_subadmin_group_ids_for_user($user_id, $settings) : [];
      if (!empty($group_ids)) $body['groups'] = $group_ids;
      if (!empty($subadmin_ids)) $body['subadmin'] = $subadmin_ids;

      $create = $this->nextcloud_request($settings, 'POST', 'users', $body);
      $statuscode = intval($create['statuscode'] ?? 0);
      if (empty($create['success']) && $statuscode !== 102) {
        return $this->combine_results(array_merge($syncs, [$create]), 'Nextcloud snapshot sync attempted.');
      }
      $syncs[] = $create;
    }

    if ($display_name !== '') {
      $syncs[] = $this->nextcloud_edit_user($settings, $nextcloud_userid, 'displayname', $display_name);
    }
    if ($email !== '') {
      $syncs[] = $this->nextcloud_edit_user($settings, $nextcloud_userid, 'email', $email);
    }
    if ($allow_password_update && $password !== '') {
      $syncs[] = $this->nextcloud_edit_user($settings, $nextcloud_userid, 'password', $password);
    }

    $syncs[] = $this->nextcloud_request(
      $settings,
      'PUT',
      'users/' . rawurlencode($nextcloud_userid) . '/' . ($enabled ? 'enable' : 'disable')
    );
    $syncs[] = $this->sync_user_access_and_memberships($user_id, $settings);

    return $this->combine_results($syncs, 'Nextcloud account synchronized from WordPress.');
  }

  private function nextcloud_edit_user(array $settings, string $nextcloud_userid, string $key, string $value): array {
    return $this->nextcloud_request($settings, 'PUT', 'users/' . rawurlencode($nextcloud_userid), [
      'key' => $key,
      'value' => $value,
    ]);
  }

  private function should_prefer_companion_app(array $settings, string $event): bool {
    if (empty($settings['companion_app_enabled']) || empty($settings['companion_app_preferred'])) return false;
    if ($this->resolve_companion_app_endpoint($settings) === '') return false;
    if ($this->resolve_companion_app_token($settings) === '') return false;
    return in_array($event, ['create_account', 'sync_snapshot', 'update_profile', 'change_password', 'update_email', 'update_display_name', 'disable_account', 'enable_account', 'delete_account', 'sync_memberships'], true);
  }

  private function resolve_companion_app_endpoint(array $settings): string {
    $endpoint = trim((string)($settings['companion_app_endpoint'] ?? ''));
    if ($endpoint !== '') return $endpoint;

    $base_url = $this->normalize_base_url((string)($settings['base_url'] ?? ''));
    if ($base_url === '') return '';
    return trailingslashit($base_url) . 'index.php/apps/jasavatar/api/v1/provision';
  }

  private function resolve_companion_ping_endpoint(array $settings): string {
    $endpoint = $this->resolve_companion_app_endpoint($settings);
    if ($endpoint === '') return '';
    return preg_replace('#/api/v1/provision/?$#', '/api/v1/ping', $endpoint) ?: '';
  }

  private function resolve_companion_app_token(array $settings): string {
    $token = trim((string)($settings['companion_app_token'] ?? ''));
    if ($token !== '') return $token;
    return trim((string)($settings['avatar_sync_token'] ?? ''));
  }

  private function nextcloud_companion_ping(array $settings): array {
    $endpoint = $this->resolve_companion_ping_endpoint($settings);
    if ($endpoint === '') {
      return ['success' => false, 'message' => 'Companion app endpoint is not configured and could not be derived from the Nextcloud base URL.'];
    }
    return $this->nextcloud_companion_request($settings, 'GET', $endpoint, [], false);
  }

  private function nextcloud_companion_request(array $settings, string $method, string $endpoint, array $body = [], bool $with_token = true): array {
    $endpoint = trim($endpoint);
    if ($endpoint === '') {
      return ['success' => false, 'message' => 'Companion app endpoint is blank.'];
    }

    $headers = [
      'Accept' => 'application/json',
    ];
    if ($with_token) {
      $token = $this->resolve_companion_app_token($settings);
      if ($token === '') {
        return ['success' => false, 'message' => 'Companion app shared token is required.'];
      }
      $headers['X-EUM-Bridge-Token'] = $token;
    }

    $args = [
      'method' => strtoupper($method),
      'timeout' => 20,
      'sslverify' => !empty($settings['verify_ssl']),
      'headers' => $headers,
    ];
    if (!empty($body)) {
      $args['body'] = $body;
    }

    $response = wp_remote_request($endpoint, $args);
    if (is_wp_error($response)) {
      return ['success' => false, 'message' => $response->get_error_message(), 'debug' => 'Request URL: ' . $endpoint];
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $raw_body = (string)wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);
    $success = $code >= 200 && $code < 300;
    if (is_array($decoded) && array_key_exists('success', $decoded)) {
      $success = $success && !empty($decoded['success']);
    }

    return [
      'success' => $success,
      'message' => is_array($decoded) && !empty($decoded['message'])
        ? (string)$decoded['message']
        : ($success ? 'Nextcloud companion app request completed.' : 'Nextcloud companion app request failed.'),
      'http_code' => $code,
      'response' => $decoded,
      'raw_body' => $raw_body,
      'endpoint' => $endpoint,
    ];
  }

  private function desired_manager_uids_for_user(int $user_id, array $settings): array {
    $uids = [];
    $seen = [];

    $line_manager_uid = $this->desired_line_manager_uid_for_user($user_id, $settings);
    if ($line_manager_uid !== '') {
      $uids[] = $line_manager_uid;
      $seen[strtolower($line_manager_uid)] = true;
    }

    foreach ($this->effective_manager_user_ids_for_subject($user_id) as $manager_user_id) {
      $manager = get_user_by('id', $manager_user_id);
      if (!$manager) continue;
      $uid = $this->nextcloud_userid_for_user($manager, $settings);
      $key = strtolower($uid);
      if ($uid !== '' && !isset($seen[$key])) {
        $uids[] = $uid;
        $seen[$key] = true;
      }
    }
    return array_values(array_filter(array_map('strval', $uids)));
  }

  private function desired_line_manager_uid_for_user(int $user_id, array $settings): string {
    $manager_user_id = $this->direct_manager_user_id_for_subject($user_id);
    if ($manager_user_id <= 0) return '';
    $manager = get_user_by('id', $manager_user_id);
    if (!$manager) return '';
    return $this->nextcloud_userid_for_user($manager, $settings);
  }

  private function sync_user_subadmin_assignments(int $user_id, array $settings, string $nextcloud_userid = ''): array {
    if ($user_id <= 0 || empty($settings['sync_group_managers'])) {
      return ['success' => true, 'performed' => false, 'message' => 'Subadmin sync skipped.'];
    }

    if (trim((string)($settings['admin_username'] ?? '')) === '' || (string)($settings['admin_secret'] ?? '') === '') {
      return ['success' => true, 'performed' => false, 'message' => 'Subadmin sync skipped because native Nextcloud API credentials are not configured.'];
    }

    $user = get_user_by('id', $user_id);
    if (!$user) {
      return ['success' => false, 'performed' => false, 'message' => 'Target WordPress user not found for subadmin sync.'];
    }

    if ($nextcloud_userid === '') {
      $nextcloud_userid = $this->nextcloud_userid_for_user($user, $settings);
    }
    if ($nextcloud_userid === '') {
      return ['success' => false, 'performed' => false, 'message' => 'Could not derive a Nextcloud user ID for subadmin sync.'];
    }

    $current_subadmin = $this->nextcloud_user_subadmins($settings, $nextcloud_userid);
    $current_subadmin_ids = array_values(array_filter((array)($current_subadmin['groups'] ?? []), [$this, 'is_managed_group_id']));
    $desired_subadmin_ids = $this->desired_subadmin_group_ids_for_user($user_id, $settings);

    $results = [];
    foreach (array_diff($current_subadmin_ids, $desired_subadmin_ids) as $groupid) {
      $results[] = $this->nextcloud_request($settings, 'DELETE', 'users/' . rawurlencode($nextcloud_userid) . '/subadmins', ['groupid' => $groupid]);
    }
    foreach (array_diff($desired_subadmin_ids, $current_subadmin['groups'] ?? []) as $groupid) {
      $results[] = $this->nextcloud_request($settings, 'POST', 'users/' . rawurlencode($nextcloud_userid) . '/subadmins', ['groupid' => $groupid]);
    }

    if (empty($results)) {
      return ['success' => true, 'performed' => true, 'message' => 'Nextcloud group-manager assignments were already current.'];
    }

    $result = $this->combine_results($results, 'Nextcloud group-manager assignments synced.');
    $result['performed'] = true;
    return $result;
  }

  private function nextcloud_request(array $settings, string $method, string $path, array $body = []): array {
    $base_url = $this->normalize_base_url((string)$settings['base_url']);
    $admin_username = trim((string)$settings['admin_username']);
    $admin_secret = (string)$settings['admin_secret'];
    if ($base_url === '' || $admin_username === '' || $admin_secret === '') {
      return ['success' => false, 'message' => 'Nextcloud base URL, admin username, and admin password/app token are required.'];
    }

    $url = trailingslashit($base_url) . 'ocs/v1.php/cloud/' . ltrim($path, '/');
    $url = add_query_arg(['format' => 'json'], $url);

    $args = [
      'method' => strtoupper($method),
      'timeout' => 20,
      'sslverify' => !empty($settings['verify_ssl']),
      'headers' => [
        'OCS-APIRequest' => 'true',
        'Accept' => 'application/json',
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Authorization' => 'Basic ' . base64_encode($admin_username . ':' . $admin_secret),
      ],
    ];
    if (!empty($body)) {
      $args['body'] = $body;
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
      return ['success' => false, 'message' => $response->get_error_message(), 'debug' => 'Request URL: ' . $url];
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $raw_body = (string)wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);

    if ($code < 200 || $code >= 300) {
      return [
        'success' => false,
        'message' => 'Nextcloud returned HTTP ' . $code . '.',
        'http_code' => $code,
        'raw_body' => $raw_body,
        'response' => $decoded,
      ];
    }

    if (!is_array($decoded)) {
      return [
        'success' => false,
        'message' => 'Nextcloud returned an unreadable response.',
        'http_code' => $code,
        'raw_body' => $raw_body,
      ];
    }

    $meta = [];
    $data = null;
    if (isset($decoded['ocs']) && is_array($decoded['ocs'])) {
      $meta = isset($decoded['ocs']['meta']) && is_array($decoded['ocs']['meta']) ? $decoded['ocs']['meta'] : [];
      $data = $decoded['ocs']['data'] ?? null;
    } else {
      $meta = isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [];
      $data = $decoded['data'] ?? null;
    }

    $statuscode = intval($meta['statuscode'] ?? 0);
    $message = trim((string)($meta['message'] ?? ''));
    $success = ($statuscode === 100 || strtolower((string)($meta['status'] ?? '')) === 'ok');

    return [
      'success' => $success,
      'message' => $message !== '' ? $message : ($success ? 'Nextcloud action completed.' : 'Nextcloud reported a failed OCS status.'),
      'statuscode' => $statuscode,
      'response' => $decoded,
      'data' => $data,
      'http_code' => $code,
      'raw_body' => $raw_body,
    ];
  }

  private function combine_results(array $results, string $success_message): array {
    $errors = [];
    $details = [];
    foreach ($results as $result) {
      if (empty($result['success'])) {
        $errors[] = (string)($result['message'] ?? 'Unknown error');
      }
      $details[] = $result;
    }

    if (empty($errors)) {
      return ['success' => true, 'message' => $success_message, 'details' => $details];
    }

    return ['success' => false, 'message' => implode(' ', array_unique($errors)), 'details' => $details];
  }

  private function resolve_wp_user(string $input): ?WP_User {
    $input = trim($input);
    if ($input === '') return null;
    if (ctype_digit($input)) {
      $user = get_user_by('id', intval($input));
      if ($user) return $user;
    }
    if (strpos($input, '@') !== false) {
      $user = get_user_by('email', sanitize_email($input));
      if ($user) return $user;
    }
    return get_user_by('login', sanitize_user($input, true)) ?: null;
  }

  public function shortcode_portal($atts = []): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-private-profile"><p>You must be logged in to access Nextcloud.</p></div>';
    }

    $atts = shortcode_atts([
      'title' => 'Nextcloud',
      'user_id' => '',
    ], (array)$atts, 'eum_nextcloud_portal');

    $target_user_id = intval($atts['user_id']);
    if ($target_user_id <= 0) $target_user_id = get_current_user_id();

    if ($target_user_id !== get_current_user_id()) {
      return '<div class="eum-private-profile"><p>Nextcloud access is only available for your own account.</p></div>';
    }

    return $this->render_access_section($target_user_id, (string)$atts['title']);
  }

  public function render_access_section(int $user_id, string $title = 'Nextcloud'): string {
    $settings = self::get_settings();
    if (empty($settings['enabled'])) return '';

    $user = get_user_by('id', $user_id);
    if (!$user) return '';

    $link = $this->build_launch_url($user);
    if ($link === '') {
      return '<div class="eum-private-profile__section"><h4 style="margin-top:0;">' . esc_html($title) . '</h4><p>Nextcloud is not configured yet.</p></div>';
    }

    $new_tab = !empty($settings['open_new_tab']);
    $target_attrs = $new_tab ? ' target="_blank" rel="noopener"' : '';
    $last_sync = get_user_meta($user_id, self::META_LAST_SYNC, true);
    $last_avatar_sync = get_user_meta($user_id, self::META_LAST_AVATAR_SYNC, true);
    $last_error = get_user_meta($user_id, self::META_LAST_ERROR, true);

    ob_start();
    echo '<div class="eum-private-profile__section">';
    echo '<h4 style="margin-top:0;">' . esc_html($title) . '</h4>';
    echo '<p><a class="button" href="' . esc_url($link) . '"' . $target_attrs . '>' . esc_html($settings['label']) . '</a></p>';
    echo '<p class="description">Login ID: <code>' . esc_html($this->nextcloud_userid_for_user($user, $settings)) . '</code></p>';

    if (is_array($last_sync) && !empty($last_sync['time'])) {
      echo '<p class="description">Last Nextcloud sync: ' . esc_html((string)$last_sync['time']) . ' · ' . esc_html((string)($last_sync['event'] ?? '')) . ' · ' . (!empty($last_sync['success']) ? 'Success' : 'Error') . '</p>';
    }
    if (is_array($last_avatar_sync) && !empty($last_avatar_sync['time'])) {
      echo '<p class="description">Last Nextcloud avatar sync: ' . esc_html((string)$last_avatar_sync['time']) . ' · ' . esc_html((string)($last_avatar_sync['event'] ?? '')) . ' · ' . (!empty($last_avatar_sync['success']) ? 'Success' : 'Error');
      if (empty($last_avatar_sync['success']) && !empty($last_avatar_sync['message'])) echo ' · ' . esc_html((string)$last_avatar_sync['message']);
      echo '</p>';
    }
    if (is_array($last_error) && !empty($last_error['message'])) {
      echo '<p class="description" style="color:#b32d2e;">Last Nextcloud error: ' . esc_html((string)$last_error['message']) . '</p>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  public function build_launch_url(WP_User $user): string {
    $settings = self::get_settings();
    $base_url = $this->normalize_base_url((string)$settings['base_url']);
    if ($base_url === '') return '';

    if (!empty($settings['force_wp_login_non_it']) && !EUM_Access::is_it_user((int)$user->ID)) {
      $oidc_login_url = esc_url_raw((string)($settings['oidc_login_url'] ?? ''));
      return $oidc_login_url !== '' ? $oidc_login_url : $base_url;
    }

    $path = trim((string)$settings['it_direct_login_path']);
    if ($path === '') return $base_url;
    return $this->append_path_or_query($base_url, $path);
  }

  private function nextcloud_userid_for_user(WP_User $user, array $settings): string {
    return $this->nextcloud_userid_from_values([
      'user_login' => (string)$user->user_login,
      'user_email' => (string)$user->user_email,
    ], (string)$settings['userid_mode']);
  }

  private function nextcloud_userid_from_values(array $values, string $mode): string {
    $user_login = trim((string)($values['user_login'] ?? ''));
    $user_email = sanitize_email((string)($values['user_email'] ?? ''));

    if ($mode === 'email_full') {
      $id = $user_email;
    } elseif ($mode === 'email_local') {
      if ($user_email === '' || strpos($user_email, '@') === false) return '';
      [$local] = explode('@', $user_email, 2);
      $id = trim($local);
    } else {
      $id = $user_login;
    }

    $id = apply_filters('eum_nextcloud_userid', $id, $values, $mode);
    return trim((string)$id);
  }

  public function handle_profile_image_uploaded($user_id, $attachment_id = 0, $attachment_path = '', $attachment_url = ''): void {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return;

    try {
      $settings = self::get_settings();
      $attachment_id = (int)$attachment_id;
      $attachment_path = is_string($attachment_path) ? $attachment_path : '';
      if ($attachment_path === '' && $attachment_id > 0) {
        $resolved_path = get_attached_file($attachment_id);
        $attachment_path = is_string($resolved_path) ? $resolved_path : '';
      }
      $attachment_url = is_string($attachment_url) ? $attachment_url : '';

      $result = $this->sync_current_avatar_to_nextcloud($user_id, $settings, [
        'force' => true,
        'path' => $attachment_path,
        'url' => $attachment_url,
      ]);
      if (!empty($result['performed']) || empty($result['success'])) {
        $this->record_avatar_sync_meta($user_id, 'avatar_upload', $result);
      }
    } catch (\Throwable $e) {
      $this->record_avatar_sync_meta($user_id, 'avatar_upload', [
        'success' => false,
        'message' => 'Avatar sync crashed: ' . $e->getMessage(),
      ]);
    }
  }

  public function handle_profile_image_removed($user_id, $actor_user_id = 0): void {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return;

    try {
      $settings = self::get_settings();
      $result = $this->sync_current_avatar_to_nextcloud($user_id, $settings, [
        'force' => true,
        'remove' => true,
      ]);
      if (!empty($result['performed']) || empty($result['success'])) {
        $this->record_avatar_sync_meta($user_id, 'avatar_remove', $result);
      }
    } catch (\Throwable $e) {
      $this->record_avatar_sync_meta($user_id, 'avatar_remove', [
        'success' => false,
        'message' => 'Avatar removal sync crashed: ' . $e->getMessage(),
      ]);
    }
  }

  private function nextcloud_avatar_upload(array $settings, WP_User $user, string $filepath): array {
    $uid = $this->nextcloud_userid_for_user($user, $settings);
    if ($uid === '') {
      return ['success' => false, 'message' => 'Could not derive a Nextcloud user ID for avatar sync.'];
    }
    if (!is_readable($filepath)) {
      return ['success' => false, 'message' => 'Avatar file is not readable.'];
    }

    $filename = basename($filepath);
    $mime = function_exists('mime_content_type') ? (string)@mime_content_type($filepath) : '';
    if ($mime === '') {
      $mime = 'application/octet-stream';
    }
    $contents = file_get_contents($filepath);
    if ($contents === false) {
      return ['success' => false, 'message' => 'Avatar file could not be read into memory for sync.'];
    }
    $boundary = '----EUMBoundary' . wp_generate_password(16, false, false);
    $eol = "\r\n";
    $body = '';
    $body .= '--' . $boundary . $eol;
    $body .= 'Content-Disposition: form-data; name="avatar"; filename="' . str_replace('"', '', $filename) . '"' . $eol;
    $body .= 'Content-Type: ' . $mime . $eol . $eol;
    $body .= $contents . $eol;
    $body .= '--' . $boundary . '--' . $eol;

    return $this->nextcloud_avatar_request($settings, 'POST', $uid, $body, [
      'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
      'Content-Length' => (string)strlen($body),
    ]);
  }

  private function nextcloud_avatar_delete(array $settings, WP_User $user): array {
    $uid = $this->nextcloud_userid_for_user($user, $settings);
    if ($uid === '') {
      return ['success' => false, 'message' => 'Could not derive a Nextcloud user ID for avatar removal sync.'];
    }
    return $this->nextcloud_avatar_request($settings, 'DELETE', $uid);
  }

  private function nextcloud_avatar_request(array $settings, string $method, string $uid, string $body = '', array $extra_headers = []): array {
    $endpoint = $this->resolve_avatar_sync_endpoint($settings, $uid);
    $token = trim((string)($settings['avatar_sync_token'] ?? ''));
    if ($token === '') {
      $token = $this->resolve_companion_app_token($settings);
    }
    if ($endpoint === '' || $token === '') {
      return ['success' => false, 'message' => 'Avatar sync endpoint and token are required.'];
    }

    $headers = array_merge([
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $token,
      'X-EUM-Avatar-Token' => $token,
      'X-EUM-Source' => home_url('/'),
    ], $extra_headers);

    $args = [
      'method' => strtoupper($method),
      'timeout' => 45,
      'sslverify' => !empty($settings['verify_ssl']),
      'headers' => $headers,
      'body' => $body,
    ];

    $response = wp_remote_request($endpoint, $args);
    if (is_wp_error($response)) {
      return ['success' => false, 'message' => $response->get_error_message(), 'debug' => 'Avatar endpoint: ' . $endpoint];
    }

    $code = (int)wp_remote_retrieve_response_code($response);
    $raw_body = (string)wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);
    $success = $code >= 200 && $code < 300;
    $message = '';
    if (is_array($decoded)) {
      $message = (string)($decoded['message'] ?? '');
      if ($message === '' && !empty($decoded['ocs']) && is_array($decoded['ocs']) && !empty($decoded['ocs']['meta']) && is_array($decoded['ocs']['meta'])) {
        $message = (string)($decoded['ocs']['meta']['message'] ?? '');
      }
    }
    if ($message === '') {
      $message = $success ? 'Avatar sync completed.' : ('Avatar sync returned HTTP ' . $code . '.');
    }

    return [
      'success' => $success,
      'message' => $message,
      'http_code' => $code,
      'response' => $decoded,
      'raw_body' => $raw_body,
      'debug' => 'Avatar endpoint: ' . $endpoint,
    ];
  }

  private function event_should_sync_avatar(string $event): bool {
    return in_array($event, ['create_account', 'sync_snapshot', 'update_profile', 'sync_memberships', 'enable_account'], true);
  }

  private function should_attempt_avatar_sync(array $settings): bool {
    if (empty($settings['enabled'])) return false;
    return $this->resolve_avatar_sync_endpoint($settings, '{uid}') !== '' && $this->resolve_companion_app_token($settings) !== '';
  }

  private function sync_current_avatar_to_nextcloud(int $user_id, array $settings, array $args = []): array {
    if (!$this->should_attempt_avatar_sync($settings)) {
      return ['success' => true, 'performed' => false, 'message' => 'Nextcloud avatar sync is not configured.'];
    }

    $user = get_user_by('id', $user_id);
    if (!$user) {
      return ['success' => false, 'performed' => false, 'message' => 'Target WordPress user not found for avatar sync.'];
    }

    $force = !empty($args['force']);
    $remove = !empty($args['remove']);
    $asset = $remove ? [
      'path' => '',
      'url' => '',
      'present' => false,
      'unresolved' => false,
      'fingerprint' => 'none',
    ] : $this->resolve_profile_image_sync_asset($user_id, $args);

    $last_fingerprint = (string)get_user_meta($user_id, self::META_LAST_AVATAR_FINGERPRINT, true);
    if (!$asset['present'] && !empty($asset['unresolved'])) {
      return ['success' => false, 'performed' => false, 'message' => 'WordPress profile image exists but could not be resolved to a local file for Nextcloud sync.'];
    }
    if (!$asset['present']) {
      if (empty($settings['avatar_sync_on_remove']) && !$remove) {
        return ['success' => true, 'performed' => false, 'message' => 'WordPress profile image is absent and avatar removal sync is disabled.'];
      }
      if (!$force && $last_fingerprint === 'none') {
        return ['success' => true, 'performed' => false, 'message' => 'Nextcloud avatar already matches the missing WordPress profile image.'];
      }

      $result = $this->nextcloud_avatar_delete($settings, $user);
      $result['performed'] = true;
      if (!empty($result['success'])) {
        update_user_meta($user_id, self::META_LAST_AVATAR_FINGERPRINT, 'none');
      }
      return $result;
    }

    if (!$force && $last_fingerprint !== '' && $last_fingerprint === (string)$asset['fingerprint']) {
      return ['success' => true, 'performed' => false, 'message' => 'Nextcloud avatar already matches the current WordPress profile image.'];
    }

    $result = $this->nextcloud_avatar_upload($settings, $user, (string)$asset['path']);
    $result['performed'] = true;
    if (!empty($result['success'])) {
      update_user_meta($user_id, self::META_LAST_AVATAR_FINGERPRINT, (string)$asset['fingerprint']);
    }
    return $result;
  }

  private function resolve_profile_image_sync_asset(int $user_id, array $args = []): array {
    $path = isset($args['path']) && is_string($args['path']) ? trim((string)$args['path']) : '';
    $url = isset($args['url']) && is_string($args['url']) ? esc_url_raw((string)$args['url']) : '';

    if ($path === '' && class_exists('EUM_Profiles')) {
      $attachment_id = (int)get_user_meta($user_id, EUM_Profiles::META_PROFILE_IMAGE_ID, true);
      if ($attachment_id > 0) {
        $resolved = get_attached_file($attachment_id);
        $path = is_string($resolved) ? trim($resolved) : '';
        if ($url === '') {
          $resolved_url = wp_get_attachment_url($attachment_id);
          $url = is_string($resolved_url) ? esc_url_raw($resolved_url) : '';
        }
      }
      if ($url === '' && method_exists('EUM_Profiles', 'profile_image_url')) {
        $url = esc_url_raw((string)EUM_Profiles::profile_image_url($user_id));
      }
    }

    if ($path === '' && $url !== '') {
      $path = $this->local_upload_path_from_url($url);
    }

    $present = ($path !== '' && is_readable($path));
    $unresolved = (!$present && $url !== '');
    $fingerprint = 'none';
    if ($present) {
      $fingerprint = sha1('avatar|' . $path . '|' . (string)@filesize($path) . '|' . (string)@filemtime($path));
    }

    return [
      'path' => $present ? $path : '',
      'url' => $url,
      'present' => $present,
      'unresolved' => $unresolved,
      'fingerprint' => $fingerprint,
    ];
  }

  private function local_upload_path_from_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';

    $uploads = wp_get_upload_dir();
    if (!is_array($uploads)) return '';

    $baseurl = isset($uploads['baseurl']) ? rtrim((string)$uploads['baseurl'], '/') : '';
    $basedir = isset($uploads['basedir']) ? rtrim((string)$uploads['basedir'], '\\/') : '';
    if ($baseurl === '' || $basedir === '' || strpos($url, $baseurl . '/') !== 0) {
      return '';
    }

    $relative = ltrim(substr($url, strlen($baseurl)), '/');
    if ($relative === '') return '';

    return $basedir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rawurldecode($relative));
  }

  private function record_avatar_sync_meta(int $user_id, string $event, array $result): void {
    update_user_meta($user_id, self::META_LAST_AVATAR_SYNC, [
      'time' => current_time('mysql'),
      'event' => $event,
      'success' => !empty($result['success']) ? 1 : 0,
      'message' => (string)($result['message'] ?? ''),
    ]);
  }

  private function resolve_avatar_sync_endpoint(array $settings, string $uid): string {
    $endpoint = trim((string)($settings['avatar_sync_endpoint'] ?? ''));
    if ($endpoint === '') {
      $base_url = $this->normalize_base_url((string)($settings['base_url'] ?? ''));
      if ($base_url === '') return '';
      $endpoint = trailingslashit($base_url) . 'index.php/apps/jasavatar/api/v1/users/{uid}/avatar';
    }
    return str_replace('{uid}', rawurlencode($uid), $endpoint);
  }

  public function cron_schedules(array $schedules): array {
    $settings = self::get_settings();
    $minutes = max(5, (int)$settings['sync_interval_minutes']);
    $schedules[self::CRON_SCHEDULE] = [
      'interval' => $minutes * 60,
      'display' => 'Enterprise User Management Nextcloud sync every ' . $minutes . ' minutes',
    ];
    return $schedules;
  }

  public function ensure_cron_scheduled(bool $force_reschedule = false): void {
    $settings = self::get_settings();
    if (empty($settings['enabled'])) {
      self::unschedule_cron();
      return;
    }
    if (empty($settings['sync_groups'])) {
      wp_clear_scheduled_hook(self::CRON_HOOK);
      wp_clear_scheduled_hook(self::CRON_FULL_SYNC_HOOK);
      return;
    }

    $existing = wp_next_scheduled(self::CRON_HOOK);
    if ($force_reschedule && $existing) {
      wp_clear_scheduled_hook(self::CRON_HOOK);
      $existing = false;
    }
    if (!$existing) {
      wp_schedule_event(time() + 120, self::CRON_SCHEDULE, self::CRON_HOOK);
    }
  }

  public static function unschedule_cron(): void {
    wp_clear_scheduled_hook(self::CRON_HOOK);
    wp_clear_scheduled_hook(self::CRON_FULL_SYNC_HOOK);
    wp_clear_scheduled_hook(self::CRON_SINGLE_HOOK);
  }

  public function handle_periodic_sync(): void {
    $this->perform_full_sync();
  }

  public function handle_queued_full_sync(): void {
    $this->perform_full_sync();
  }

  public function handle_single_user_sync(int $user_id): void {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return;
    $this->dispatch_event($this->sync_snapshot_payload_for_user($user_id));
  }

  private function queue_single_user_sync(int $user_id, int $delay = 20): void {
    $settings = self::get_settings();
    if (empty($settings['enabled']) || $user_id <= 0) return;
    if (!wp_next_scheduled(self::CRON_SINGLE_HOOK, [$user_id])) {
      wp_schedule_single_event(time() + max(5, $delay), self::CRON_SINGLE_HOOK, [$user_id]);
    }
  }

  private function queue_full_sync(int $delay = 60): void {
    $settings = self::get_settings();
    if (empty($settings['enabled'])) return;
    if (!wp_next_scheduled(self::CRON_FULL_SYNC_HOOK)) {
      wp_schedule_single_event(time() + max(15, $delay), self::CRON_FULL_SYNC_HOOK);
    }
  }

  public function handle_general_post_save(int $post_id, WP_Post $post, bool $update): void {
    try {
      if (!$this->should_trigger_general_post_sync($post_id, $post)) return;
      $this->queue_full_sync(45);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_general_post_save', $e);
    }
  }

  public function handle_general_post_delete(int $post_id, WP_Post $post): void {
    try {
      if (!$this->should_trigger_general_post_sync($post_id, $post)) return;
      $this->queue_full_sync(45);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_general_post_delete', $e);
    }
  }

  public function handle_general_post_status_change(int $post_id): void {
    try {
      $post = get_post((int)$post_id);
      if (!$this->should_trigger_general_post_sync((int)$post_id, $post instanceof WP_Post ? $post : null)) return;
      $this->queue_full_sync(45);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_general_post_status_change', $e);
    }
  }

  public function handle_general_term_change(int $term_id, int $tt_id, string $taxonomy): void {
    try {
      if (!$this->should_trigger_general_term_sync($taxonomy)) return;
      $this->queue_full_sync(30);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_general_term_change', $e);
    }
  }

  public function handle_general_term_deleted(int $term_id, int $tt_id, string $taxonomy, $deleted_term, array $object_ids): void {
    try {
      if (!$this->should_trigger_general_term_sync($taxonomy)) return;
      $this->queue_full_sync(30);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_general_term_deleted', $e);
    }
  }

  public function handle_object_terms_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void {
    try {
      if (!in_array((string)$taxonomy, ['eum_user_group', 'eum_project', 'eum_job_path', 'eum_location'], true)) return;
      $this->queue_single_user_sync((int)$object_id, 20);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_object_terms_change', $e);
    }
  }

  public function handle_user_meta_change($meta_id, $user_id, $meta_key, $_value): void {
    try {
      $watched = [EUM_Access::META_IT, EUM_Access::META_HR, EUM_Personnel::META_MANAGER_ID];
      if (class_exists('EUM_Profiles')) {
        $watched[] = EUM_Profiles::META_PROFILE_IMAGE_ID;
        $watched[] = EUM_Profiles::META_PROFILE_IMAGE_URL;
      }
      if (!in_array((string)$meta_key, $watched, true)) return;
      $this->queue_single_user_sync((int)$user_id, 20);
      if ((string)$meta_key === EUM_Personnel::META_MANAGER_ID) $this->queue_full_sync(45);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_user_meta_change', $e);
    }
  }

  public function handle_term_meta_change($meta_id, $term_id, $meta_key, $_value): void {
    try {
      if (!class_exists('EUM_Manager_Messages')) return;
      if ((string)$meta_key !== EUM_Manager_Messages::TERM_META_MANAGER_IDS) return;
      $this->queue_full_sync(30);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_term_meta_change', $e);
    }
  }

  public function handle_post_meta_change($meta_id, $post_id, $meta_key, $_value): void {
    try {
      $post_id = (int)$post_id;
      $post_type = get_post_type($post_id);

      if (class_exists('EUM_Timeclock') && $post_type === EUM_Timeclock::CPT_ENTRY) {
        if (in_array((string)$meta_key, [EUM_Timeclock::META_USER_ID, EUM_Timeclock::META_END_UTC, EUM_Timeclock::META_ENTRY_KIND], true)) {
          $user_id = (int)get_post_meta($post_id, EUM_Timeclock::META_USER_ID, true);
          if ($user_id > 0) $this->queue_single_user_sync($user_id, 10);
        }
        return;
      }

      if (!class_exists('EUM_Manager_IDs') || $post_type !== EUM_Manager_IDs::CPT) return;
      if (!in_array((string)$meta_key, [EUM_Manager_IDs::META_MANAGER_USERS, EUM_Manager_IDs::META_JOB_PATH_TERM], true)) return;
      $this->queue_full_sync(30);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_post_meta_change', $e);
    }
  }

  public function perform_full_sync(): array {
    $settings = self::get_settings();
    if (empty($settings['enabled'])) {
      return ['success' => false, 'message' => 'Nextcloud integration is disabled.'];
    }

    $results = [];
    if (!empty($settings['sync_groups'])) {
      $defs = $this->all_managed_group_definitions($settings);
      $results[] = $this->ensure_group_definitions($settings, $defs);
    }

    $users = get_users(['fields' => 'ID', 'number' => 0]);
    foreach ((array)$users as $user_id) {
      $results[] = $this->dispatch_event($this->sync_snapshot_payload_for_user((int)$user_id));
    }

    return $this->combine_results($results, 'Nextcloud full sync completed.');
  }

  private function sync_user_access_and_memberships(int $user_id, array $settings): array {
    if (empty($settings['sync_groups'])) {
      return ['success' => true, 'message' => 'Group sync disabled.'];
    }

    $user = get_user_by('id', $user_id);
    if (!$user) return ['success' => false, 'message' => 'Target WordPress user not found for membership sync.'];

    $nextcloud_userid = $this->nextcloud_userid_for_user($user, $settings);
    if ($nextcloud_userid === '') return ['success' => false, 'message' => 'Could not derive a Nextcloud user ID for membership sync.'];

    $exists = $this->nextcloud_request($settings, 'GET', 'users/' . rawurlencode($nextcloud_userid));
    if (empty($exists['success'])) {
      return ['success' => true, 'message' => 'Membership sync skipped because the Nextcloud user does not exist yet.'];
    }

    $desired_defs = $this->desired_group_definitions_for_user($user_id, $settings);
    $ensure = $this->ensure_group_definitions($settings, $desired_defs);
    $desired_group_ids = array_keys($desired_defs);

    $managed_groups = $this->nextcloud_user_groups($settings, $nextcloud_userid);
    $current_group_ids = (array)($managed_groups['groups'] ?? []);
    $managed_current_group_ids = array_values(array_filter($current_group_ids, [$this, 'is_managed_group_id']));

    $results = [$ensure];
    foreach (array_diff($managed_current_group_ids, $desired_group_ids) as $groupid) {
      $results[] = $this->nextcloud_request($settings, 'DELETE', 'users/' . rawurlencode($nextcloud_userid) . '/groups', ['groupid' => $groupid]);
    }
    foreach (array_diff($desired_group_ids, $current_group_ids) as $groupid) {
      $results[] = $this->nextcloud_request($settings, 'POST', 'users/' . rawurlencode($nextcloud_userid) . '/groups', ['groupid' => $groupid]);
    }

    $subadmin_sync = $this->sync_user_subadmin_assignments($user_id, $settings, $nextcloud_userid);
    if (!empty($subadmin_sync['performed'])) {
      $results[] = $subadmin_sync;
    }

    return $this->combine_results($results, 'Nextcloud memberships and manager mappings synced.');
  }

  private function all_managed_group_definitions(array $settings): array {
    $defs = [];
    foreach (get_users(['fields' => 'ID', 'number' => 0]) as $user_id) {
      foreach ($this->desired_group_definitions_for_user((int)$user_id, $settings) as $gid => $def) {
        $defs[$gid] = $def;
      }
    }
    return $defs;
  }

  private function desired_group_definitions_for_user(int $user_id, array $settings): array {
    $defs = [];
    $user = get_user_by('id', $user_id);
    if (!$user) return $defs;

    if (!empty($settings['sync_role_groups'])) {
      if (EUM_Access::is_it_user($user_id)) {
        $defs['eum-role-it'] = ['groupid' => 'eum-role-it', 'displayname' => $settings['it_group_label'] !== '' ? $settings['it_group_label'] : 'IT'];
      }
      if (EUM_Access::is_hr_user($user_id)) {
        $defs['eum-role-hr'] = ['groupid' => 'eum-role-hr', 'displayname' => $settings['hr_group_label'] !== '' ? $settings['hr_group_label'] : 'HR'];
      }
      if ($this->is_actual_manager_user($user_id)) {
        $defs['eum-role-managers'] = ['groupid' => 'eum-role-managers', 'displayname' => $settings['manager_role_group_label'] !== '' ? $settings['manager_role_group_label'] : 'Managers'];
      }
      if ($this->is_user_currently_clocked_in($user_id)) {
        $defs['eum-role-clocked-in'] = ['groupid' => 'eum-role-clocked-in', 'displayname' => $settings['clocked_in_role_group_label'] !== '' ? $settings['clocked_in_role_group_label'] : 'Clocked in'];
      }
    }

    foreach ((array)wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'all']) as $term) {
      if ($term instanceof WP_Term) {
        $gid = 'eum-group-' . (int)$term->term_id;
        $defs[$gid] = ['groupid' => $gid, 'displayname' => (string)$term->name];
      }
    }
    foreach ((array)wp_get_object_terms($user_id, 'eum_project', ['fields' => 'all']) as $term) {
      if ($term instanceof WP_Term) {
        $gid = 'eum-project-' . (int)$term->term_id;
        $display = ($settings['project_group_prefix'] !== '' ? $settings['project_group_prefix'] : 'Project: ') . (string)$term->name;
        $defs[$gid] = ['groupid' => $gid, 'displayname' => trim($display)];
      }
    }

    if (!empty($settings['sync_manager_groups'])) {
      foreach ($this->effective_manager_user_ids_for_subject($user_id) as $manager_user_id) {
        $group = $this->manager_mapping_group_definition($manager_user_id, $settings);
        if (!empty($group)) $defs[$group['groupid']] = $group;
      }
      if ($this->is_actual_manager_user($user_id)) {
        $group = $this->manager_mapping_group_definition($user_id, $settings);
        if (!empty($group)) $defs[$group['groupid']] = $group;
      }
    }

    return $defs;
  }

  private function desired_subadmin_group_ids_for_user(int $user_id, array $settings): array {
    $group_ids = [];
    if (!empty($settings['sync_group_managers'])) {
      foreach ($this->managed_term_group_ids_for_manager($user_id) as $gid) {
        $group_ids[] = $gid;
      }
    }
    if (!empty($settings['sync_manager_groups']) && $this->is_actual_manager_user($user_id)) {
      $group = $this->manager_mapping_group_definition($user_id, $settings);
      if (!empty($group['groupid'])) $group_ids[] = $group['groupid'];
    }
    return array_values(array_unique(array_filter(array_map('strval', $group_ids))));
  }

  private function ensure_group_definitions(array $settings, array $defs): array {
    if (empty($defs)) return ['success' => true, 'message' => 'No Nextcloud groups to sync.'];
    $results = [];
    foreach ($defs as $gid => $def) {
      if ($gid === '') continue;
      $create = $this->nextcloud_request($settings, 'POST', 'groups', ['groupid' => $gid]);
      $status = (int)($create['statuscode'] ?? 0);
      if (!empty($create['success']) || $status === 102) {
        $displayname = trim((string)($def['displayname'] ?? ''));
        if ($displayname !== '') {
          $results[] = $this->nextcloud_request($settings, 'PUT', 'groups/' . rawurlencode($gid), ['key' => 'displayname', 'value' => $displayname]);
        } else {
          $results[] = ['success' => true, 'message' => 'Group exists.', 'groupid' => $gid];
        }
      } else {
        $results[] = $create;
      }
    }
    return $this->combine_results($results, 'Nextcloud groups ensured.');
  }

  private function nextcloud_user_groups(array $settings, string $nextcloud_userid): array {
    $result = $this->nextcloud_request($settings, 'GET', 'users/' . rawurlencode($nextcloud_userid) . '/groups');
    $groups = [];
    if (!empty($result['success'])) {
      $data = $result['data'] ?? [];
      if (is_array($data)) {
        if (isset($data['groups']) && is_array($data['groups'])) $groups = array_values(array_map('strval', $data['groups']));
        elseif (array_values($data) === $data) $groups = array_values(array_map('strval', $data));
      }
    }
    $result['groups'] = $groups;
    return $result;
  }

  private function nextcloud_user_subadmins(array $settings, string $nextcloud_userid): array {
    $result = $this->nextcloud_request($settings, 'GET', 'users/' . rawurlencode($nextcloud_userid) . '/subadmins');
    $groups = [];
    if (!empty($result['success'])) {
      $data = $result['data'] ?? [];
      if (is_array($data)) {
        if (isset($data['groups']) && is_array($data['groups'])) $groups = array_values(array_map('strval', $data['groups']));
        elseif (array_values($data) === $data) $groups = array_values(array_map('strval', $data));
      }
    }
    $result['groups'] = $groups;
    return $result;
  }

  private function managed_term_group_ids_for_manager(int $user_id): array {
    $group_ids = [];
    if (!class_exists('EUM_Manager_Messages') || !class_exists('EUM_Manager_IDs')) return [];

    foreach (['eum_user_group', 'eum_project'] as $tax) {
      $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
      if (is_wp_error($terms)) continue;
      foreach ((array)$terms as $term) {
        if (!$term instanceof WP_Term) continue;
        $manager_ids = (array)get_term_meta((int)$term->term_id, EUM_Manager_Messages::TERM_META_MANAGER_IDS, true);
        $manager_ids = array_values(array_filter(array_map('intval', $manager_ids)));
        foreach ($manager_ids as $manager_id_post) {
          $manager_users = (array)get_post_meta($manager_id_post, EUM_Manager_IDs::META_MANAGER_USERS, true);
          $manager_users = array_values(array_filter(array_map('intval', $manager_users)));
          if (!in_array($user_id, $manager_users, true)) continue;
          if ($tax === 'eum_project') {
            $group_ids[] = 'eum-project-' . (int)$term->term_id;
          } else {
            $group_ids[] = 'eum-group-' . (int)$term->term_id;
          }
          break;
        }
      }
    }
    return array_values(array_unique($group_ids));
  }

  private function effective_manager_user_ids_for_subject(int $user_id): array {
    $manager_user_ids = [];
    $direct = (int)get_user_meta($user_id, EUM_Personnel::META_MANAGER_ID, true);
    if ($direct > 0 && $direct !== $user_id) $manager_user_ids[] = $direct;

    if (class_exists('EUM_Manager_IDs')) {
      $mgr = new EUM_Manager_IDs();
      $mid = (int)$mgr->resolve_effective_manager_id_for_user($user_id);
      if ($mid > 0) {
        $manager_users = (array)get_post_meta($mid, EUM_Manager_IDs::META_MANAGER_USERS, true);
        foreach ((array)$manager_users as $manager_user_id) {
          $manager_user_id = (int)$manager_user_id;
          if ($manager_user_id > 0 && $manager_user_id !== $user_id) $manager_user_ids[] = $manager_user_id;
        }
      }
    }

    return array_values(array_unique(array_filter(array_map('intval', $manager_user_ids))));
  }

  private function direct_manager_user_id_for_subject(int $user_id): int {
    if (class_exists('EUM_Personnel') && method_exists('EUM_Personnel', 'effective_manager_user_id')) {
      $direct = EUM_Personnel::effective_manager_user_id($user_id);
      if ($direct > 0 && $direct !== $user_id && get_user_by('id', $direct)) {
        return $direct;
      }
    }
    $effective = $this->effective_manager_user_ids_for_subject($user_id);
    return !empty($effective) ? (int)$effective[0] : 0;
  }

  private function manager_mapping_group_definition(int $manager_user_id, array $settings): array {
    $user = get_user_by('id', $manager_user_id);
    if (!$user) return [];
    $prefix = $settings['manager_group_prefix'] !== '' ? $settings['manager_group_prefix'] : 'Manager: ';
    return [
      'groupid' => 'eum-manager-' . (int)$manager_user_id,
      'displayname' => trim($prefix . (string)$user->display_name),
    ];
  }

  private function is_actual_manager_user(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'is_manager_of_anything') && EUM_Profiles::is_manager_of_anything($user_id)) {
      return true;
    }
    $directs = get_users([
      'number' => 1,
      'fields' => 'ids',
      'meta_key' => EUM_Personnel::META_MANAGER_ID,
      'meta_value' => $user_id,
    ]);
    return !empty($directs);
  }

  private function is_user_currently_clocked_in(int $user_id): bool {
    if ($user_id <= 0 || !class_exists('EUM_Timeclock')) return false;
    return EUM_Timeclock::user_is_clocked_in($user_id);
  }

  private function is_managed_group_id(string $group_id): bool {
    return strpos($group_id, self::MANAGED_GROUP_PREFIX) === 0;
  }

  private function should_trigger_general_post_sync(int $post_id, ?WP_Post $post = null): bool {
    if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return false;
    if (!$post instanceof WP_Post) {
      $post = get_post($post_id);
    }
    if (!$post instanceof WP_Post) return false;

    $type = get_post_type_object((string)$post->post_type);
    if (!$type) return false;

    return !empty($type->public) || !empty($type->show_ui);
  }

  private function should_trigger_general_term_sync(string $taxonomy): bool {
    $taxonomy = sanitize_key($taxonomy);
    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) return false;

    $tax = get_taxonomy($taxonomy);
    if (!$tax) return false;

    return !empty($tax->public) || !empty($tax->show_ui);
  }

  private function append_path_or_query(string $base_url, string $path): string {
    $base_url = $this->normalize_base_url($base_url);
    $path = trim($path);
    if ($base_url === '' || $path === '') return $base_url;
    if ($path[0] === '?') {
      return rtrim($base_url, '/') . '/' . ltrim($path, '/');
    }
    return $base_url . ltrim($path, '/');
  }

  public static function oidc_group_claims_for_user(int $user_id): array {
    $bridge = new self();
    $defs = $bridge->desired_group_definitions_for_user($user_id, self::get_settings());
    $claims = [];
    foreach ($defs as $def) {
      $gid = trim((string)($def['groupid'] ?? ''));
      $display = trim((string)($def['displayname'] ?? ''));
      if ($gid === '') continue;
      $claims[] = ['gid' => $gid, 'displayName' => ($display !== '' ? $display : $gid)];
    }
    return array_values($claims);
  }

  private function normalize_base_url(string $base_url): string {
    $base_url = trim($base_url);
    if ($base_url === '') return '';
    return trailingslashit($base_url);
  }

  private function format_debug_details(array $result): string {
    $lines = [];
    if (!empty($result['message'])) $lines[] = 'Message: ' . (string)$result['message'];
    if (!empty($result['endpoint'])) $lines[] = 'Endpoint: ' . (string)$result['endpoint'];
    if (!empty($result['statuscode'])) $lines[] = 'OCS status code: ' . (string)$result['statuscode'];
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
    if (!empty($result['details']) && is_array($result['details'])) {
      $lines[] = 'Combined sync details:';
      $lines[] = '<pre style="white-space:pre-wrap;max-height:320px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:10px;">' . esc_html(wp_json_encode($result['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
    }
    return implode("\n", $lines);
  }

  private function log_runtime_issue(string $context, \Throwable $e): void {
    eum_log_message('[EUM Nextcloud] ' . $context . ': ' . $e->getMessage());
  }
}
