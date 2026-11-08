<?php
if (!defined('ABSPATH')) exit;

class EUM_OIDC_Provider {
  const OPTION_SETTINGS = 'eum_oidc_provider_settings';
  const OPTION_KEYS = 'eum_oidc_provider_keys';
  const TRANSIENT_AUTH_CODE = 'eum_oidc_code_';
  const TRANSIENT_ACCESS = 'eum_oidc_access_';
  const TRANSIENT_REFRESH = 'eum_oidc_refresh_';

  public function init(): void {
    add_action('init', [$this, 'register_rewrite_rules']);
    add_action('rest_api_init', [$this, 'register_rest_routes']);
    add_filter('query_vars', [$this, 'query_vars']);
    add_action('parse_request', [$this, 'handle_request']);

    add_action('admin_menu', [$this, 'admin_menu'], 47);
    add_action('admin_post_eum_oidc_save', [$this, 'handle_save']);
    add_action('admin_post_nopriv_eum_oidc_token', [$this, 'handle_token_admin_post']);
    add_action('admin_post_eum_oidc_token', [$this, 'handle_token_admin_post']);
    add_action('admin_post_nopriv_eum_oidc_userinfo', [$this, 'handle_userinfo_admin_post']);
    add_action('admin_post_eum_oidc_userinfo', [$this, 'handle_userinfo_admin_post']);
    add_action('admin_post_eum_oidc_regenerate_secret', [$this, 'handle_regenerate_secret']);
    add_action('admin_post_eum_oidc_regenerate_keys', [$this, 'handle_regenerate_keys']);
  }

  public static function default_settings(): array {
    $nextcloud = class_exists('EUM_Nextcloud_Bridge') ? EUM_Nextcloud_Bridge::get_settings() : [];
    $nc_base = !empty($nextcloud['base_url']) ? trailingslashit((string)$nextcloud['base_url']) : '';
    $default_redirects = [];
    if ($nc_base !== '') {
      $default_redirects[] = $nc_base . 'apps/user_oidc/code';
      $default_redirects[] = $nc_base . 'index.php/apps/user_oidc/code';
    }

    return [
      'enabled' => 0,
      'provider_name' => get_bloginfo('name') !== '' ? get_bloginfo('name') : 'WordPress',
      'client_id' => 'nextcloud',
      'client_secret' => wp_generate_password(48, true, true),
      'allowed_redirect_uris' => implode("\n", array_filter(array_unique($default_redirects))),
      'subject_mode' => 'user_login',
      'preferred_username_mode' => 'user_login',
      'include_groups' => 1,
      'logout_wp_session' => 1,
      'token_ttl' => 3600,
      'refresh_ttl' => 2592000,
      'code_ttl' => 300,
    ];
  }

  public static function get_settings(): array {
    $settings = get_option(self::OPTION_SETTINGS, []);
    if (!is_array($settings)) $settings = [];
    $settings = array_merge(self::default_settings(), $settings);
    foreach (['enabled', 'include_groups', 'logout_wp_session'] as $k) {
      $settings[$k] = empty($settings[$k]) ? 0 : 1;
    }
    $settings['provider_name'] = sanitize_text_field((string)$settings['provider_name']);
    $settings['client_id'] = sanitize_text_field((string)$settings['client_id']);
    $settings['client_secret'] = (string)$settings['client_secret'];
    $settings['allowed_redirect_uris'] = trim((string)$settings['allowed_redirect_uris']);
    $settings['subject_mode'] = in_array((string)$settings['subject_mode'], ['user_login', 'wp_user_id', 'email_full', 'email_local'], true) ? (string)$settings['subject_mode'] : 'user_login';
    $settings['preferred_username_mode'] = in_array((string)$settings['preferred_username_mode'], ['user_login', 'email_full', 'email_local'], true) ? (string)$settings['preferred_username_mode'] : 'user_login';
    $settings['token_ttl'] = max(300, intval($settings['token_ttl']));
    $settings['refresh_ttl'] = max($settings['token_ttl'], intval($settings['refresh_ttl']));
    $settings['code_ttl'] = max(60, intval($settings['code_ttl']));
    return $settings;
  }

  public static function issuer(): string {
    return home_url('/?eum_oidc=issuer');
  }

  public static function discovery_url(): string {
    return home_url('/?eum_oidc=discovery');
  }

  public static function authorization_url(): string {
    return home_url('/?eum_oidc=authorize');
  }

  public static function token_url(): string {
    return rest_url('eum-oidc/v1/token');
  }

  public static function userinfo_url(): string {
    return rest_url('eum-oidc/v1/userinfo');
  }

  public static function jwks_url(): string {
    return home_url('/?eum_oidc=jwks');
  }

  public static function end_session_url(): string {
    return home_url('/?eum_oidc=logout');
  }

  public function register_rewrite_rules(): void {
    add_rewrite_rule('^eum-oidc/\.well-known/openid-configuration/?$', 'index.php?eum_oidc=discovery', 'top');
    add_rewrite_rule('^eum-oidc/jwks/?$', 'index.php?eum_oidc=jwks', 'top');
    add_rewrite_rule('^eum-oidc/authorize/?$', 'index.php?eum_oidc=authorize', 'top');
    add_rewrite_rule('^eum-oidc/token/?$', 'index.php?eum_oidc=token', 'top');
    add_rewrite_rule('^eum-oidc/userinfo/?$', 'index.php?eum_oidc=userinfo', 'top');
    add_rewrite_rule('^eum-oidc/logout/?$', 'index.php?eum_oidc=logout', 'top');
  }

  public function query_vars(array $vars): array {
    $vars[] = 'eum_oidc';
    return $vars;
  }


  public function register_rest_routes(): void {
    register_rest_route('eum-oidc/v1', '/token', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [$this, 'handle_token_rest'],
    ]);
    register_rest_route('eum-oidc/v1', '/userinfo', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => [$this, 'handle_userinfo_rest'],
    ]);
  }

  public function admin_menu(): void {
    if (!EUM_Access::current_user_is_it()) return;
    add_submenu_page(
      EUM_Admin::parent_slug('integrations'),
      'SSO / OIDC',
      'SSO / OIDC',
      'read',
      'eum-oidc',
      [$this, 'render_admin_page']
    );
  }

  public function render_admin_page(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $settings = self::get_settings();
    $keys = $this->get_or_create_keys();
    $redirect_lines = $this->allowed_redirect_uris($settings);
    $nextcloud = class_exists('EUM_Nextcloud_Bridge') ? EUM_Nextcloud_Bridge::get_settings() : [];
    $nextcloud_base = !empty($nextcloud['base_url']) ? trailingslashit((string)$nextcloud['base_url']) : '';
    $nextcloud_redirect = $nextcloud_base !== '' ? $nextcloud_base . 'apps/user_oidc/code' : '';

    if (!empty($_GET['eum_msg'])) {
      $msg = sanitize_text_field((string)$_GET['eum_msg']);
      $type = !empty($_GET['eum_type']) ? sanitize_key((string)$_GET['eum_type']) : 'success';
      $cls = ($type === 'error') ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($cls) . '"><p>' . esc_html($msg) . '</p></div>';
    }

    echo '<div class="wrap"><h1>SSO / OIDC</h1>';
    echo '<p>Use this WordPress site as the OpenID Connect identity provider for Site Manager connected sites and, optionally, Nextcloud. WordPress remains the source of truth for authentication.</p>';
    echo '<p><em>The client credentials below are shared by connected sites. Add each destination site’s callback URL to the allowed list.</em></p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_oidc_save">';
    wp_nonce_field('eum_oidc_save', 'eum_oidc_nonce');

    echo '<h2>Provider</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Enable WordPress OIDC Provider</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($settings['enabled']), true, false) . '> Enable OIDC discovery, authorization, token, userinfo, JWKS, and logout endpoints</label></td></tr>';
    echo '<tr><th scope="row">Provider Name</th><td><input type="text" class="regular-text" name="provider_name" value="' . esc_attr($settings['provider_name']) . '"></td></tr>';
    echo '<tr><th scope="row">Issuer</th><td><code>' . esc_html(self::issuer()) . '</code></td></tr>';
    echo '<tr><th scope="row">Discovery URI</th><td><code>' . esc_html(self::discovery_url()) . '</code></td></tr>';
    echo '<tr><th scope="row">JWKS Key ID</th><td><code>' . esc_html((string)($keys['kid'] ?? '')) . '</code></td></tr>';
    echo '</tbody></table>';

    echo '<h2>Client Credentials (Site Manager and Nextcloud)</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Client ID</th><td><input type="text" class="regular-text code" name="client_id" value="' . esc_attr($settings['client_id']) . '"><p class="description">Copy this value into the OIDC Client ID field in each connected site’s Enterprise Connected Site settings.</p></td></tr>';
    echo '<tr><th scope="row">Client Secret</th><td><input type="text" class="large-text code" name="client_secret" value="' . esc_attr($settings['client_secret']) . '"><p class="description">Copy this value into the OIDC Client Secret field on each connected site. Generating a new secret requires updating every connected site.</p><p><a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=eum_oidc_regenerate_secret'), 'eum_oidc_regenerate_secret')) . '">Generate New Secret</a></p></td></tr>';
    echo '<tr><th scope="row">Allowed Redirect URIs</th><td><textarea name="allowed_redirect_uris" rows="5" class="large-text code">' . esc_textarea($settings['allowed_redirect_uris']) . '</textarea>';
    echo '<p class="description">Add the exact OIDC callback URL shown in each connected site’s settings, one URI per line. For Nextcloud <code>user_oidc</code>, the common callback is <code>' . esc_html($nextcloud_redirect !== '' ? $nextcloud_redirect : 'https://your-nextcloud/apps/user_oidc/code') . '</code>.</p></td></tr>';
    echo '</tbody></table>';

    echo '<h2>Claims</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Subject Claim Source</th><td><select name="subject_mode">';
    foreach (['user_login' => 'WordPress Username', 'wp_user_id' => 'WordPress Numeric User ID', 'email_full' => 'Full Email Address', 'email_local' => 'Email Local Part'] as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($settings['subject_mode'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select><p class="description">Use a stable value here. For most deployments, WordPress Username is the safest choice.</p></td></tr>';
    echo '<tr><th scope="row">preferred_username Source</th><td><select name="preferred_username_mode">';
    foreach (['user_login' => 'WordPress Username', 'email_full' => 'Full Email Address', 'email_local' => 'Email Local Part'] as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($settings['preferred_username_mode'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Include groups Claim</th><td><label><input type="checkbox" name="include_groups" value="1" ' . checked(!empty($settings['include_groups']), true, false) . '> Include a groups array in ID token and userinfo</label></td></tr>';
    echo '</tbody></table>';

    echo '<h2>Token Lifetimes</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Authorization Code TTL</th><td><input type="number" min="60" step="1" name="code_ttl" value="' . esc_attr((string)$settings['code_ttl']) . '"> seconds</td></tr>';
    echo '<tr><th scope="row">Access Token TTL</th><td><input type="number" min="300" step="1" name="token_ttl" value="' . esc_attr((string)$settings['token_ttl']) . '"> seconds</td></tr>';
    echo '<tr><th scope="row">Refresh Token TTL</th><td><input type="number" min="300" step="1" name="refresh_ttl" value="' . esc_attr((string)$settings['refresh_ttl']) . '"> seconds</td></tr>';
    echo '<tr><th scope="row">Logout Behavior</th><td><label><input type="checkbox" name="logout_wp_session" value="1" ' . checked(!empty($settings['logout_wp_session']), true, false) . '> Log the WordPress session out when Nextcloud sends the user to the OIDC end-session endpoint</label></td></tr>';
    echo '</tbody></table>';

    submit_button('Save OIDC Settings');
    echo '</form>';

    echo '<hr style="margin:28px 0;">';
    echo '<h2>Site Manager Connected Site Setup</h2>';
    echo '<ol>';
    echo '<li>On the destination WordPress site, install and activate <strong>Enterprise Connected Site</strong>.</li>';
    echo '<li>In its <strong>Settings → Enterprise Connected Site</strong>, enter this internal site URL and the Client ID and Client Secret shown above, then save.</li>';
    echo '<li>Copy the destination’s displayed <strong>OIDC callback URL</strong> into the Allowed Redirect URIs field above, then save these OIDC settings.</li>';
    echo '<li>Copy the destination’s One-click sign-in URL and Provisioning API URL into a new entry under <strong>Integrations → Site Manager</strong>.</li>';
    echo '<li>Copy the generated Site API key from the internal Site Manager record back to the destination’s <strong>Site provisioning key</strong> field. This API key is separate from the OIDC credentials.</li>';
    echo '</ol>';

    echo '<h2>Nextcloud Setup (optional)</h2>';
    echo '<ol>';
    echo '<li>Install and enable the <code>user_oidc</code> app in Nextcloud.</li>';
    echo '<li>Create a provider in Nextcloud using the same Client ID and Secret shown above.</li>';
    echo '<li>Set the Discovery URI to <code>' . esc_html(self::discovery_url()) . '</code>.</li>';
    echo '<li>Set the WordPress-side Allowed Redirect URIs to include the Nextcloud callback, usually <code>' . esc_html($nextcloud_redirect !== '' ? $nextcloud_redirect : 'https://your-nextcloud/apps/user_oidc/code') . '</code>.</li>';
    echo '<li>In Nextcloud, map the User ID to <code>preferred_username</code> or <code>sub</code>.</li>';
    echo '<li>If you want base-url visits to jump straight into SSO, configure Nextcloud to make the OIDC provider the default login method.</li>';
    echo '</ol>';

    echo '<p><strong>Endpoints</strong></p>';
    echo '<ul style="list-style:disc;padding-left:20px;">';
    echo '<li>Authorize: <code>' . esc_html(self::authorization_url()) . '</code></li>';
    echo '<li>Token: <code>' . esc_html(self::token_url()) . '</code></li>';
    echo '<li>Userinfo: <code>' . esc_html(self::userinfo_url()) . '</code></li>';
    echo '<li>JWKS: <code>' . esc_html(self::jwks_url()) . '</code></li>';
    echo '<li>End session: <code>' . esc_html(self::end_session_url()) . '</code></li>';
    echo '</ul>';

    echo '<p><a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=eum_oidc_regenerate_keys'), 'eum_oidc_regenerate_keys')) . '">Regenerate RSA Signing Keys</a></p>';
    echo '<p class="description">If you rotate keys, Nextcloud may cache the old JWKS for up to an hour. Re-saving the provider in Nextcloud or clearing its JWKS cache may be required.</p>';
    if (!empty($redirect_lines)) {
      echo '<p class="description">Currently allowed redirect URIs: <code>' . esc_html(implode(' | ', $redirect_lines)) . '</code></p>';
    }
    echo '</div>';
  }

  public function handle_save(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_oidc_nonce']) || !wp_verify_nonce($_POST['eum_oidc_nonce'], 'eum_oidc_save')) wp_die('Bad nonce.');

    $settings = self::get_settings();
    $settings['enabled'] = isset($_POST['enabled']) ? 1 : 0;
    $settings['provider_name'] = sanitize_text_field((string)($_POST['provider_name'] ?? $settings['provider_name']));
    $settings['client_id'] = sanitize_text_field((string)($_POST['client_id'] ?? $settings['client_id']));
    $settings['client_secret'] = (string)($_POST['client_secret'] ?? $settings['client_secret']);
    $settings['allowed_redirect_uris'] = $this->sanitize_redirect_list((string)($_POST['allowed_redirect_uris'] ?? ''));
    $settings['subject_mode'] = sanitize_key((string)($_POST['subject_mode'] ?? $settings['subject_mode']));
    $settings['preferred_username_mode'] = sanitize_key((string)($_POST['preferred_username_mode'] ?? $settings['preferred_username_mode']));
    $settings['include_groups'] = isset($_POST['include_groups']) ? 1 : 0;
    $settings['logout_wp_session'] = isset($_POST['logout_wp_session']) ? 1 : 0;
    $settings['code_ttl'] = max(60, intval($_POST['code_ttl'] ?? $settings['code_ttl']));
    $settings['token_ttl'] = max(300, intval($_POST['token_ttl'] ?? $settings['token_ttl']));
    $settings['refresh_ttl'] = max($settings['token_ttl'], intval($_POST['refresh_ttl'] ?? $settings['refresh_ttl']));

    if ($settings['client_id'] === '') $settings['client_id'] = 'nextcloud';
    if ($settings['client_secret'] === '') $settings['client_secret'] = wp_generate_password(48, true, true);

    update_option(self::OPTION_SETTINGS, $settings, false);
    $this->get_or_create_keys();
    wp_safe_redirect(admin_url('admin.php?page=eum-oidc&eum_msg=' . rawurlencode('OIDC settings saved.') . '&eum_type=success'));
    exit;
  }

  public function handle_regenerate_secret(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    check_admin_referer('eum_oidc_regenerate_secret');
    $settings = self::get_settings();
    $settings['client_secret'] = wp_generate_password(48, true, true);
    update_option(self::OPTION_SETTINGS, $settings, false);
    wp_safe_redirect(admin_url('admin.php?page=eum-oidc&eum_msg=' . rawurlencode('OIDC client secret regenerated.') . '&eum_type=success'));
    exit;
  }

  public function handle_regenerate_keys(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    check_admin_referer('eum_oidc_regenerate_keys');
    $this->generate_and_store_keys(true);
    wp_safe_redirect(admin_url('admin.php?page=eum-oidc&eum_msg=' . rawurlencode('OIDC RSA signing keys regenerated.') . '&eum_type=success'));
    exit;
  }

  public function handle_token_admin_post(): void {
    $settings = self::get_settings();
    if (empty($settings['enabled'])) {
      $this->json_error('temporarily_unavailable', 'OIDC provider is disabled.', 503);
    }
    nocache_headers();
    $this->handle_token();
  }

  public function handle_userinfo_admin_post(): void {
    $settings = self::get_settings();
    if (empty($settings['enabled'])) {
      $this->json_error('temporarily_unavailable', 'OIDC provider is disabled.', 503);
    }
    nocache_headers();
    $this->handle_userinfo();
  }


  public function handle_token_rest(WP_REST_Request $request) {
    $settings = self::get_settings();
    if (empty($settings['enabled'])) {
      return new WP_REST_Response([
        'error' => 'temporarily_unavailable',
        'error_description' => 'OIDC provider is disabled.',
      ], 503);
    }
    nocache_headers();
    $this->emit_noindex_headers();
    $this->handle_token();
    return null;
  }

  public function handle_userinfo_rest(WP_REST_Request $request) {
    $settings = self::get_settings();
    if (empty($settings['enabled'])) {
      return new WP_REST_Response([
        'error' => 'temporarily_unavailable',
        'error_description' => 'OIDC provider is disabled.',
      ], 503);
    }
    nocache_headers();
    $this->emit_noindex_headers();
    $this->handle_userinfo();
    return null;
  }

  public function handle_request(WP $wp): void {
    $route = isset($wp->query_vars['eum_oidc']) ? (string)$wp->query_vars['eum_oidc'] : '';
    if ($route === '') return;

    $settings = self::get_settings();
    if (empty($settings['enabled'])) {
      $this->json_error('temporarily_unavailable', 'OIDC provider is disabled.', 503);
    }

    nocache_headers();
    switch ($route) {
      case 'issuer':
        $this->render_issuer();
        break;
      case 'discovery':
        $this->render_discovery();
        break;
      case 'jwks':
        $this->render_jwks();
        break;
      case 'authorize':
        $this->handle_authorize();
        break;
      case 'token':
        $this->handle_token();
        break;
      case 'userinfo':
        $this->handle_userinfo();
        break;
      case 'logout':
        $this->handle_logout();
        break;
      default:
        status_header(404);
        exit;
    }
  }

  private function render_issuer(): void {
    $this->json_response(['issuer' => self::issuer()]);
  }

  private function render_discovery(): void {
    $settings = self::get_settings();
    $payload = [
      'issuer' => self::issuer(),
      'authorization_endpoint' => self::authorization_url(),
      'token_endpoint' => self::token_url(),
      'userinfo_endpoint' => self::userinfo_url(),
      'jwks_uri' => self::jwks_url(),
      'end_session_endpoint' => self::end_session_url(),
      'response_types_supported' => ['code'],
      'subject_types_supported' => ['public'],
      'id_token_signing_alg_values_supported' => ['RS256'],
      'scopes_supported' => ['openid', 'profile', 'email', 'groups'],
      'claims_supported' => ['sub', 'preferred_username', 'name', 'given_name', 'family_name', 'email', 'email_verified', 'groups'],
      'grant_types_supported' => ['authorization_code', 'refresh_token'],
      'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
      'code_challenge_methods_supported' => ['S256', 'plain'],
      'userinfo_signing_alg_values_supported' => ['none'],
      'request_parameter_supported' => false,
      'request_uri_parameter_supported' => false,
      'require_request_uri_registration' => false,
      'claims_parameter_supported' => true,
      'frontchannel_logout_supported' => false,
      'backchannel_logout_supported' => false,
      'op_tos_uri' => home_url('/'),
      'op_policy_uri' => home_url('/privacy-policy'),
      'service_documentation' => admin_url('admin.php?page=eum-oidc'),
      'ui_locales_supported' => array_values(array_unique(array_filter([get_locale()])))
    ];
    $payload = apply_filters('eum_oidc_discovery_payload', $payload, $settings);
    $this->json_response($payload);
  }

  private function render_jwks(): void {
    $keys = $this->get_or_create_keys();
    $this->json_response(['keys' => [
      [
        'kty' => 'RSA',
        'use' => 'sig',
        'alg' => 'RS256',
        'kid' => (string)$keys['kid'],
        'n' => (string)$keys['n'],
        'e' => (string)$keys['e'],
      ]
    ]]);
  }

  private function handle_authorize(): void {
    $settings = self::get_settings();
    $client_id = isset($_REQUEST['client_id']) ? sanitize_text_field((string)wp_unslash($_REQUEST['client_id'])) : '';
    $redirect_uri = isset($_REQUEST['redirect_uri']) ? trim((string)wp_unslash($_REQUEST['redirect_uri'])) : '';
    $response_type = isset($_REQUEST['response_type']) ? sanitize_text_field((string)wp_unslash($_REQUEST['response_type'])) : '';
    $scope = isset($_REQUEST['scope']) ? (string)wp_unslash($_REQUEST['scope']) : '';
    $state = isset($_REQUEST['state']) ? (string)wp_unslash($_REQUEST['state']) : '';
    $nonce = isset($_REQUEST['nonce']) ? (string)wp_unslash($_REQUEST['nonce']) : '';
    $code_challenge = isset($_REQUEST['code_challenge']) ? (string)wp_unslash($_REQUEST['code_challenge']) : '';
    $code_challenge_method = isset($_REQUEST['code_challenge_method']) ? sanitize_text_field((string)wp_unslash($_REQUEST['code_challenge_method'])) : '';
    $prompt = isset($_REQUEST['prompt']) ? sanitize_text_field((string)wp_unslash($_REQUEST['prompt'])) : '';

    if ($client_id !== (string)$settings['client_id']) {
      $this->authorize_error($redirect_uri, 'unauthorized_client', 'Unknown client_id.', $state);
    }
    if (!$this->is_allowed_redirect_uri($settings, $redirect_uri)) {
      $this->authorize_error('', 'invalid_request', 'Redirect URI is not allowed.', $state);
    }
    if ($response_type !== 'code') {
      $this->authorize_error($redirect_uri, 'unsupported_response_type', 'Only response_type=code is supported.', $state);
    }
    if (!$this->scope_contains_openid($scope)) {
      $this->authorize_error($redirect_uri, 'invalid_scope', 'The openid scope is required.', $state);
    }
    if ($code_challenge !== '' && !in_array($code_challenge_method, ['S256', 'plain'], true)) {
      $this->authorize_error($redirect_uri, 'invalid_request', 'Unsupported PKCE code_challenge_method.', $state);
    }

    if (!is_user_logged_in()) {
      if ($prompt === 'none') {
        $this->authorize_error($redirect_uri, 'login_required', 'User is not logged in.', $state);
      }
      auth_redirect();
    }

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
      $this->authorize_error($redirect_uri, 'access_denied', 'No authenticated WordPress session was found.', $state);
    }
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) {
      $this->authorize_error($redirect_uri, 'access_denied', 'This user is terminated and cannot use SSO.', $state);
    }

    $code = $this->random_token(32);
    $payload = [
      'client_id' => $client_id,
      'redirect_uri' => $redirect_uri,
      'user_id' => $user_id,
      'scope' => $scope,
      'nonce' => $nonce,
      'code_challenge' => $code_challenge,
      'code_challenge_method' => $code_challenge_method !== '' ? $code_challenge_method : 'plain',
      'issued_at' => time(),
    ];
    set_transient(self::TRANSIENT_AUTH_CODE . $code, $payload, intval($settings['code_ttl']));

    $dest = add_query_arg(array_filter([
      'code' => $code,
      'state' => $state,
    ], static function ($v) { return $v !== ''; }), $redirect_uri);
    // The redirect URI was matched exactly against the configured allowlist above.
    wp_redirect($dest);
    exit;
  }

  private function handle_token(): void {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
      $this->json_error('invalid_request', 'POST is required.', 405);
    }

    try {
      $settings = self::get_settings();
      $params = $this->request_params();
      [$client_id, $client_secret] = $this->parse_client_auth();
      if ($client_id === '' && isset($params['client_id'])) $client_id = sanitize_text_field((string)$params['client_id']);
      if ($client_secret === '' && isset($params['client_secret'])) $client_secret = (string)$params['client_secret'];

      if ($client_id !== (string)$settings['client_id'] || !hash_equals((string)$settings['client_secret'], (string)$client_secret)) {
        $this->json_error('invalid_client', 'Client authentication failed.', 401, ['WWW-Authenticate' => 'Basic realm="EUM OIDC"']);
      }

      $grant_type = isset($params['grant_type']) ? sanitize_text_field((string)$params['grant_type']) : '';
    if ($grant_type === 'authorization_code') {
      $code = isset($params['code']) ? sanitize_text_field((string)$params['code']) : '';
      $redirect_uri = isset($params['redirect_uri']) ? trim((string)$params['redirect_uri']) : '';
      $code_verifier = isset($params['code_verifier']) ? (string)$params['code_verifier'] : '';
      if ($code === '' || $redirect_uri === '') {
        $this->json_error('invalid_request', 'code and redirect_uri are required.', 400);
      }
      $code_payload = get_transient(self::TRANSIENT_AUTH_CODE . $code);
      if (!is_array($code_payload)) {
        $this->json_error('invalid_grant', 'Authorization code is invalid or expired.', 400);
      }
      delete_transient(self::TRANSIENT_AUTH_CODE . $code);
      if (($code_payload['client_id'] ?? '') !== $client_id || ($code_payload['redirect_uri'] ?? '') !== $redirect_uri) {
        $this->json_error('invalid_grant', 'Authorization code did not match the client or redirect URI.', 400);
      }
      if (!empty($code_payload['code_challenge'])) {
        if ($code_verifier === '') {
          $this->json_error('invalid_request', 'code_verifier is required for PKCE.', 400);
        }
        $method = (string)($code_payload['code_challenge_method'] ?? 'plain');
        $expected = (string)$code_payload['code_challenge'];
        $actual = $method === 'S256' ? $this->base64url_encode(hash('sha256', $code_verifier, true)) : $code_verifier;
        if (!hash_equals($expected, $actual)) {
          $this->json_error('invalid_grant', 'PKCE verification failed.', 400);
        }
      }

      $user = get_user_by('id', intval($code_payload['user_id'] ?? 0));
      if (!$user instanceof WP_User) {
        $this->json_error('invalid_grant', 'User no longer exists.', 400);
      }
      $this->json_response($this->issue_tokens($user, (string)$client_id, (string)($code_payload['scope'] ?? 'openid'), (string)($code_payload['nonce'] ?? ''), true));
    }

    if ($grant_type === 'refresh_token') {
      $refresh_token = isset($params['refresh_token']) ? sanitize_text_field((string)$params['refresh_token']) : '';
      if ($refresh_token === '') {
        $this->json_error('invalid_request', 'refresh_token is required.', 400);
      }
      $refresh_payload = get_transient(self::TRANSIENT_REFRESH . $refresh_token);
      if (!is_array($refresh_payload)) {
        $this->json_error('invalid_grant', 'Refresh token is invalid or expired.', 400);
      }
      if (($refresh_payload['client_id'] ?? '') !== $client_id) {
        $this->json_error('invalid_grant', 'Refresh token does not belong to this client.', 400);
      }
      $user = get_user_by('id', intval($refresh_payload['user_id'] ?? 0));
      if (!$user instanceof WP_User) {
        $this->json_error('invalid_grant', 'User no longer exists.', 400);
      }
      $this->json_response($this->issue_tokens($user, (string)$client_id, (string)($refresh_payload['scope'] ?? 'openid'), '', false, $refresh_token));
    }

    $this->json_error('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.', 400);
    } catch (\Throwable $e) {
      error_log('[EUM OIDC] Token endpoint exception: ' . $e->getMessage());
      $this->json_error('server_error', 'Token endpoint internal error: ' . $e->getMessage(), 500);
    }
  }

  private function handle_userinfo(): void {
    $access_token = $this->bearer_token();
    if ($access_token === '') {
      $this->json_error('invalid_token', 'Missing bearer token.', 401, ['WWW-Authenticate' => 'Bearer']);
    }
    $payload = get_transient(self::TRANSIENT_ACCESS . $access_token);
    if (!is_array($payload)) {
      $this->json_error('invalid_token', 'Access token is invalid or expired.', 401, ['WWW-Authenticate' => 'Bearer']);
    }
    $user = get_user_by('id', intval($payload['user_id'] ?? 0));
    if (!$user instanceof WP_User) {
      $this->json_error('invalid_token', 'User no longer exists.', 401, ['WWW-Authenticate' => 'Bearer']);
    }
    $this->json_response($this->claims_for_user($user));
  }

  private function handle_logout(): void {
    $settings = self::get_settings();
    $post_logout_redirect_uri = isset($_REQUEST['post_logout_redirect_uri']) ? trim((string)wp_unslash($_REQUEST['post_logout_redirect_uri'])) : '';
    $state = isset($_REQUEST['state']) ? (string)wp_unslash($_REQUEST['state']) : '';
    if (!empty($settings['logout_wp_session']) && is_user_logged_in()) {
      wp_logout();
    }
    $dest = $post_logout_redirect_uri !== '' ? $post_logout_redirect_uri : home_url('/');
    if ($state !== '') $dest = add_query_arg('state', $state, $dest);
    wp_safe_redirect($dest);
    exit;
  }

  private function issue_tokens(WP_User $user, string $client_id, string $scope, string $nonce = '', bool $rotate_refresh = true, string $existing_refresh = ''): array {
    $settings = self::get_settings();
    $access_token = $this->random_token(48);
    $refresh_token = ($existing_refresh !== '' && !$rotate_refresh) ? $existing_refresh : $this->random_token(48);
    $claims = $this->claims_for_user($user);
    $now = time();
    $id_token = $this->build_id_token($claims, $client_id, $nonce, $now, $now + intval($settings['token_ttl']));

    set_transient(self::TRANSIENT_ACCESS . $access_token, [
      'user_id' => $user->ID,
      'client_id' => $client_id,
      'scope' => $scope,
      'issued_at' => $now,
    ], intval($settings['token_ttl']));

    set_transient(self::TRANSIENT_REFRESH . $refresh_token, [
      'user_id' => $user->ID,
      'client_id' => $client_id,
      'scope' => $scope,
      'issued_at' => $now,
    ], intval($settings['refresh_ttl']));

    return [
      'access_token' => $access_token,
      'token_type' => 'Bearer',
      'expires_in' => intval($settings['token_ttl']),
      'refresh_token' => $refresh_token,
      'refresh_expires_in' => intval($settings['refresh_ttl']),
      'scope' => $scope,
      'id_token' => $id_token,
    ];
  }

  private function build_id_token(array $claims, string $client_id, string $nonce, int $iat, int $exp): string {
    $keys = $this->get_or_create_keys();
    $header = [
      'alg' => 'RS256',
      'typ' => 'JWT',
      'kid' => (string)$keys['kid'],
    ];
    $payload = $claims;
    $payload['iss'] = self::issuer();
    $payload['aud'] = $client_id;
    $payload['iat'] = $iat;
    $payload['exp'] = $exp;
    $payload['auth_time'] = $iat;
    if ($nonce !== '') $payload['nonce'] = $nonce;

    $segments = [
      $this->base64url_encode(wp_json_encode($header, JSON_UNESCAPED_SLASHES)),
      $this->base64url_encode(wp_json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $signing_input = implode('.', $segments);
    $signature = '';
    $private_key = openssl_pkey_get_private((string)$keys['private_key']);
    if (!$private_key || !openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
      status_header(500);
      exit('Could not sign OIDC token.');
    }
    if (PHP_VERSION_ID < 80000 && is_resource($private_key)) {
      openssl_free_key($private_key);
    }
    $segments[] = $this->base64url_encode($signature);
    return implode('.', $segments);
  }

  private function claims_for_user(WP_User $user): array {
    $settings = self::get_settings();
    $first_name = trim((string)get_user_meta($user->ID, 'first_name', true));
    $last_name = trim((string)get_user_meta($user->ID, 'last_name', true));
    if ($first_name === '' && $last_name === '' && $user->display_name !== '') {
      $parts = preg_split('/\s+/', trim((string)$user->display_name));
      if (is_array($parts) && count($parts) > 1) {
        $first_name = array_shift($parts);
        $last_name = implode(' ', $parts);
      }
    }

    $claims = [
      'sub' => self::subject_identifier_for_user($user),
      'preferred_username' => $this->value_for_mode($settings['preferred_username_mode'], $user),
      'name' => (string)$user->display_name,
      'given_name' => $first_name,
      'family_name' => $last_name,
      'email' => sanitize_email((string)$user->user_email),
      'email_verified' => sanitize_email((string)$user->user_email) !== '',
    ];
    if (!empty($settings['include_groups'])) {
      $claims['groups'] = $this->groups_for_user($user->ID);
    }
    return apply_filters('eum_oidc_claims', $claims, $user, $settings);
  }

  private function groups_for_user(int $user_id): array {
    if (class_exists('EUM_Nextcloud_Bridge') && method_exists('EUM_Nextcloud_Bridge', 'oidc_group_claims_for_user')) {
      $groups = EUM_Nextcloud_Bridge::oidc_group_claims_for_user($user_id);
      return apply_filters('eum_oidc_groups', $groups, $user_id);
    }

    $groups = [];
    $user = get_user_by('id', $user_id);
    if ($user instanceof WP_User) {
      foreach ((array)$user->roles as $role) {
        $role = sanitize_key((string)$role);
        if ($role !== '') $groups[] = 'wp_' . $role;
      }
    }
    $groups = array_values(array_unique(array_filter(array_map('strval', $groups))));
    return apply_filters('eum_oidc_groups', $groups, $user_id);
  }

  private function get_or_create_keys(): array {
    $keys = get_option(self::OPTION_KEYS, []);
    if (!is_array($keys) || empty($keys['private_key']) || empty($keys['kid']) || empty($keys['n']) || empty($keys['e'])) {
      $keys = $this->generate_and_store_keys(false);
    }
    return is_array($keys) ? $keys : [];
  }

  private function generate_and_store_keys(bool $force): array {
    $existing = get_option(self::OPTION_KEYS, []);
    if (!$force && is_array($existing) && !empty($existing['private_key']) && !empty($existing['kid']) && !empty($existing['n']) && !empty($existing['e'])) {
      return $existing;
    }

    if (!function_exists('openssl_pkey_new')) {
      status_header(500);
      exit('OpenSSL is required for OIDC signing keys.');
    }

    $resource = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if (!$resource) {
      status_header(500);
      exit('Could not generate RSA signing key.');
    }
    $private_key = '';
    openssl_pkey_export($resource, $private_key);
    $details = openssl_pkey_get_details($resource);
    if (!is_array($details) || empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
      status_header(500);
      exit('Could not read generated RSA public key details.');
    }
    $n = $this->base64url_encode($details['rsa']['n']);
    $e = $this->base64url_encode($details['rsa']['e']);
    $kid = substr(hash('sha256', $n . '.' . $e), 0, 16);

    $keys = [
      'private_key' => $private_key,
      'kid' => $kid,
      'n' => $n,
      'e' => $e,
      'generated_at' => current_time('mysql'),
    ];
    update_option(self::OPTION_KEYS, $keys, false);
    return $keys;
  }

  private function allowed_redirect_uris(array $settings): array {
    $lines = preg_split('/\r\n|\r|\n/', (string)($settings['allowed_redirect_uris'] ?? '')) ?: [];
    $uris = [];
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      $uri = esc_url_raw($line);
      if ($uri !== '') $uris[] = $uri;
    }
    return array_values(array_unique($uris));
  }

  private function sanitize_redirect_list(string $raw): string {
    return implode("\n", $this->allowed_redirect_uris(['allowed_redirect_uris' => $raw]));
  }

  private function is_allowed_redirect_uri(array $settings, string $redirect_uri): bool {
    if ($redirect_uri === '') return false;
    return in_array(esc_url_raw($redirect_uri), $this->allowed_redirect_uris($settings), true);
  }

  private function scope_contains_openid(string $scope): bool {
    $scopes = preg_split('/\s+/', trim($scope)) ?: [];
    return in_array('openid', $scopes, true);
  }

  private function authorize_error(string $redirect_uri, string $error, string $description, string $state = ''): void {
    $settings = self::get_settings();
    if ($redirect_uri !== '' && $this->is_allowed_redirect_uri($settings, $redirect_uri)) {
      $dest = add_query_arg(array_filter([
        'error' => $error,
        'error_description' => $description,
        'state' => $state,
      ], static function ($v) { return $v !== ''; }), $redirect_uri);
      // Only registered callbacks can receive the authorization error response.
      wp_redirect($dest);
      exit;
    }
    $this->json_error($error, $description, 400);
  }

  private function parse_client_auth(): array {
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
      return [(string)$_SERVER['PHP_AUTH_USER'], (string)$_SERVER['PHP_AUTH_PW']];
    }

    $header = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string)$_SERVER['HTTP_AUTHORIZATION'] : '';
    if ($header === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
      $header = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ($header === '' && function_exists('getallheaders')) {
      $headers = getallheaders();
      if (is_array($headers)) {
        foreach ($headers as $key => $value) {
          if (strtolower((string)$key) === 'authorization') {
            $header = (string)$value;
            break;
          }
        }
      }
    }
    if ($header === '' && function_exists('apache_request_headers')) {
      $headers = apache_request_headers();
      if (is_array($headers)) {
        foreach ($headers as $key => $value) {
          if (strtolower((string)$key) === 'authorization') {
            $header = (string)$value;
            break;
          }
        }
      }
    }
    if (stripos($header, 'Basic ') === 0) {
      $decoded = base64_decode(substr($header, 6), true);
      if ($decoded !== false && strpos($decoded, ':') !== false) {
        [$id, $secret] = explode(':', $decoded, 2);
        return [(string)$id, (string)$secret];
      }
    }
    return ['', ''];
  }

  private function request_params(): array {
    $params = [];
    foreach ($_POST as $k => $v) {
      $params[(string)$k] = is_scalar($v) ? wp_unslash((string)$v) : $v;
    }
    if (empty($params)) {
      $raw = file_get_contents('php://input');
      if (is_string($raw) && $raw !== '') {
        $parsed = [];
        parse_str($raw, $parsed);
        if (is_array($parsed)) {
          foreach ($parsed as $k => $v) {
            $params[(string)$k] = is_scalar($v) ? (string)$v : $v;
          }
        }
      }
    }
    return $params;
  }

  private function bearer_token(): string {
    $header = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string)$_SERVER['HTTP_AUTHORIZATION'] : '';
    if ($header === '' && function_exists('getallheaders')) {
      $headers = getallheaders();
      if (is_array($headers)) {
        foreach ($headers as $key => $value) {
          if (strtolower((string)$key) === 'authorization') {
            $header = (string)$value;
            break;
          }
        }
      }
    }
    if (preg_match('/Bearer\s+(.+)$/i', $header, $m)) {
      return trim((string)$m[1]);
    }
    return '';
  }

  private function value_for_mode(string $mode, WP_User $user): string {
    $email = sanitize_email((string)$user->user_email);
    if ($mode === 'wp_user_id') return (string)$user->ID;
    if ($mode === 'email_full') return $email;
    if ($mode === 'email_local') {
      if ($email !== '' && strpos($email, '@') !== false) {
        [$local] = explode('@', $email, 2);
        return trim($local);
      }
      return '';
    }
    return (string)$user->user_login;
  }

  public static function subject_identifier_for_user(WP_User $user): string {
    $settings = self::get_settings();
    $provider = new self();
    $identifier = $provider->value_for_mode((string)$settings['subject_mode'], $user);
    return $identifier !== '' ? $identifier : 'eum-user-id-' . (int)$user->ID;
  }

  private function random_token(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
  }

  private function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }


  private function emit_noindex_headers(): void {
    header('X-Robots-Tag: noindex, nofollow', true);
  }

  private function json_response(array $payload, int $status = 200, array $headers = []): void {
    status_header($status);
    $this->emit_noindex_headers();
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    foreach ($headers as $name => $value) {
      header($name . ': ' . $value);
    }
    echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }

  private function json_error(string $error, string $description, int $status = 400, array $headers = []): void {
    $this->json_response([
      'error' => $error,
      'error_description' => $description,
    ], $status, $headers);
  }
}
