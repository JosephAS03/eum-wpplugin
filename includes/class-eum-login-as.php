<?php
if (!defined('ABSPATH')) exit;

class EUM_Login_As {
  const CPT_AUDIT = 'eum_login_audit';
  const COOKIE_STATE = 'eum_login_as_state';

  const META_ORIGINAL_USER = '_eum_original_user_id';
  const META_TARGET_USER   = '_eum_target_user_id';
  const META_REASON        = '_eum_reason';
  const META_STARTED_AT    = '_eum_started_at';
  const META_STOPPED_AT    = '_eum_stopped_at';
  const META_STARTED_IP    = '_eum_started_ip';
  const META_STOPPED_IP    = '_eum_stopped_ip';
  const META_USER_AGENT    = '_eum_user_agent';
  const META_STATUS        = '_eum_status';

  public function init(): void {
    add_action('init', [$this, 'register_audit_cpt']);
    add_action('admin_menu', [$this, 'admin_menu'], 46);

    add_action('admin_post_eum_login_as_start', [$this, 'handle_start']);
    add_action('admin_post_eum_login_as_stop', [$this, 'handle_stop']);

    add_filter('user_row_actions', [$this, 'add_user_row_action'], 20, 2);
    add_action('admin_bar_menu', [$this, 'add_admin_bar_node'], 999);
    add_action('wp_body_open', [$this, 'render_frontend_banner']);
    add_action('wp_footer', [$this, 'render_frontend_banner']);
    add_action('admin_notices', [$this, 'render_admin_notice']);
    add_action('wp_logout', [$this, 'clear_state_cookie']);
  }

  public function register_audit_cpt(): void {
    register_post_type(self::CPT_AUDIT, [
      'labels' => [
        'name' => 'Login As Audit',
        'singular_name' => 'Login As Audit',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  public function admin_menu(): void {
    if (!EUM_Access::current_user_is_it()) return;

    add_submenu_page(
      EUM_Admin::parent_slug('core'),
      'Login As User',
      'Login As User',
      'read',
      'eum-login-as',
      [$this, 'render_page']
    );
  }

  public static function is_switched(): bool {
    return !empty(self::get_state());
  }

  public static function get_state(): array {
    $state = self::read_state_cookie();
    if (empty($state)) return [];
    if ((int)get_current_user_id() !== (int)$state['target_user_id']) return [];
    return $state;
  }

  private static function read_state_cookie(): array {
    if (empty($_COOKIE[self::COOKIE_STATE])) return [];

    $raw = wp_unslash((string)$_COOKIE[self::COOKIE_STATE]);
    $decoded = json_decode(rawurldecode($raw), true);
    if (!is_array($decoded)) return [];

    $state = [
      'original_user_id' => (int)($decoded['original_user_id'] ?? 0),
      'target_user_id'   => (int)($decoded['target_user_id'] ?? 0),
      'audit_id'         => (int)($decoded['audit_id'] ?? 0),
      'expires'          => (int)($decoded['expires'] ?? 0),
      'sig'              => (string)($decoded['sig'] ?? ''),
    ];

    if ($state['original_user_id'] <= 0 || $state['target_user_id'] <= 0 || $state['audit_id'] <= 0 || $state['expires'] <= time() || $state['sig'] === '') {
      return [];
    }

    $check = self::sign_state($state['original_user_id'], $state['target_user_id'], $state['audit_id'], $state['expires']);
    if (!hash_equals($check, $state['sig'])) return [];

    return $state;
  }

  private static function sign_state(int $original_user_id, int $target_user_id, int $audit_id, int $expires): string {
    $payload = implode('|', [$original_user_id, $target_user_id, $audit_id, $expires]);
    return hash_hmac('sha256', $payload, wp_salt('auth'));
  }

  private static function set_state_cookie(int $original_user_id, int $target_user_id, int $audit_id): void {
    $ttl = (int)apply_filters('eum_login_as_cookie_ttl', DAY_IN_SECONDS);
    if ($ttl < HOUR_IN_SECONDS) $ttl = DAY_IN_SECONDS;

    $expires = time() + $ttl;
    $state = [
      'original_user_id' => $original_user_id,
      'target_user_id'   => $target_user_id,
      'audit_id'         => $audit_id,
      'expires'          => $expires,
      'sig'              => self::sign_state($original_user_id, $target_user_id, $audit_id, $expires),
    ];

    $value = rawurlencode(wp_json_encode($state));
    self::write_cookie(self::COOKIE_STATE, $value, $expires);
    $_COOKIE[self::COOKIE_STATE] = $value;
  }

  public function clear_state_cookie(): void {
    self::write_cookie(self::COOKIE_STATE, '', time() - HOUR_IN_SECONDS);
    unset($_COOKIE[self::COOKIE_STATE]);
  }

  private static function write_cookie(string $name, string $value, int $expires): void {
    $secure = is_ssl();
    $httponly = true;

    $paths = array_unique(array_filter([
      defined('COOKIEPATH') ? COOKIEPATH : '/',
      defined('SITECOOKIEPATH') ? SITECOOKIEPATH : '/',
      '/',
    ]));

    foreach ($paths as $path) {
      setcookie($name, $value, $expires, $path, defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '', $secure, $httponly);
    }
  }

  private static function current_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
      if (empty($_SERVER[$key])) continue;
      $raw = (string)$_SERVER[$key];
      if ($key === 'HTTP_X_FORWARDED_FOR') {
        $parts = array_map('trim', explode(',', $raw));
        $raw = (string)reset($parts);
      }
      $ip = sanitize_text_field($raw);
      if ($ip !== '') return $ip;
    }
    return '';
  }

  private static function current_user_agent(): string {
    return sanitize_text_field((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  }

  private static function login_as_url(int $target_user_id, string $redirect_to = '', string $reason = ''): string {
    $args = [
      'action' => 'eum_login_as_start',
      'target_user_id' => $target_user_id,
    ];
    if ($redirect_to !== '') $args['redirect_to'] = $redirect_to;
    if ($reason !== '') $args['reason'] = $reason;

    return wp_nonce_url(admin_url('admin-post.php?' . http_build_query($args)), 'eum_login_as_start_' . $target_user_id);
  }

  private static function stop_url(string $redirect_to = ''): string {
    $state = self::get_state();
    if (empty($state)) return '';

    $args = ['action' => 'eum_login_as_stop'];
    if ($redirect_to !== '') $args['redirect_to'] = $redirect_to;

    return wp_nonce_url(admin_url('admin-post.php?' . http_build_query($args)), 'eum_login_as_stop_' . (int)$state['audit_id']);
  }

  public static function can_impersonate(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0 || $actor_user_id === $target_user_id) return false;
    if (!EUM_Access::is_it_user($actor_user_id)) return false;
    if ($target_user_id === EUM_Access::forced_root_user_id()) return false;
    if (EUM_Access::is_it_user($target_user_id) && !apply_filters('eum_login_as_allow_it_targets', false, $actor_user_id, $target_user_id)) return false;
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($target_user_id) && !apply_filters('eum_login_as_allow_terminated_targets', true, $actor_user_id, $target_user_id)) return false;

    $allowed = apply_filters('eum_login_as_can_target_user', true, $actor_user_id, $target_user_id);
    return (bool)$allowed;
  }

  private static function format_user_label(int $user_id): string {
    $user = get_user_by('id', $user_id);
    if (!$user) return 'Unknown user #' . $user_id;
    $name = (string)($user->display_name ?: $user->user_login);
    return $name . ' (#' . $user_id . ')';
  }

  private static function audit_title(int $original_user_id, int $target_user_id): string {
    return sprintf(
      '%s → %s · %s',
      self::format_user_label($original_user_id),
      self::format_user_label($target_user_id),
      current_time('mysql')
    );
  }

  private function redirect_back(string $message, string $type = 'success', string $fallback = ''): void {
    $url = $fallback !== '' ? $fallback : admin_url('admin.php?page=eum-login-as');
    $url = add_query_arg([
      'eum_msg' => rawurlencode($message),
      'eum_type' => rawurlencode($type),
    ], $url);
    wp_safe_redirect($url);
    exit;
  }

  public function handle_start(): void {
    if (!is_user_logged_in()) wp_die('You must be logged in.');

    $actor_user_id = (int)get_current_user_id();
    $target_user_id = isset($_REQUEST['target_user_id']) ? (int)$_REQUEST['target_user_id'] : 0;
    check_admin_referer('eum_login_as_start_' . $target_user_id);

    if (self::is_switched()) {
      $this->redirect_back('Stop the current “login as” session before starting another one.', 'error');
    }

    if (!self::can_impersonate($actor_user_id, $target_user_id)) {
      $this->redirect_back('That user cannot be accessed with Login As.', 'error');
    }

    $redirect_to = wp_validate_redirect((string)($_REQUEST['redirect_to'] ?? ''), '');
    if ($redirect_to === '') {
      $redirect_to = (string)apply_filters('eum_login_as_redirect_url', home_url('/'), $actor_user_id, $target_user_id);
    }

    $reason = sanitize_text_field((string)($_REQUEST['reason'] ?? ''));
    $audit_id = wp_insert_post([
      'post_type' => self::CPT_AUDIT,
      'post_status' => 'publish',
      'post_title' => self::audit_title($actor_user_id, $target_user_id),
      'post_author' => $actor_user_id,
    ], true);

    if (is_wp_error($audit_id) || !$audit_id) {
      $this->redirect_back('The audit log could not be created, so Login As was not started.', 'error');
    }

    update_post_meta($audit_id, self::META_ORIGINAL_USER, $actor_user_id);
    update_post_meta($audit_id, self::META_TARGET_USER, $target_user_id);
    update_post_meta($audit_id, self::META_REASON, $reason);
    update_post_meta($audit_id, self::META_STARTED_AT, current_time('mysql'));
    update_post_meta($audit_id, self::META_STARTED_IP, self::current_ip());
    update_post_meta($audit_id, self::META_USER_AGENT, self::current_user_agent());
    update_post_meta($audit_id, self::META_STATUS, 'active');

    self::set_state_cookie($actor_user_id, $target_user_id, (int)$audit_id);

    wp_clear_auth_cookie();
    wp_set_current_user($target_user_id);
    wp_set_auth_cookie($target_user_id, false, is_ssl());

    do_action('eum_login_as_started', $actor_user_id, $target_user_id, (int)$audit_id);

    wp_safe_redirect($redirect_to);
    exit;
  }

  public function handle_stop(): void {
    $state = self::get_state();
    if (empty($state)) {
      wp_die('No active Login As session was found.');
    }

    check_admin_referer('eum_login_as_stop_' . (int)$state['audit_id']);

    $original_user_id = (int)$state['original_user_id'];
    $target_user_id = (int)$state['target_user_id'];
    $audit_id = (int)$state['audit_id'];

    update_post_meta($audit_id, self::META_STOPPED_AT, current_time('mysql'));
    update_post_meta($audit_id, self::META_STOPPED_IP, self::current_ip());
    update_post_meta($audit_id, self::META_STATUS, 'ended');

    self::clear_state_cookie();

    wp_clear_auth_cookie();
    wp_set_current_user($original_user_id);
    wp_set_auth_cookie($original_user_id, false, is_ssl());

    do_action('eum_login_as_stopped', $original_user_id, $target_user_id, $audit_id);

    $redirect_to = wp_validate_redirect((string)($_REQUEST['redirect_to'] ?? ''), admin_url('admin.php?page=eum-login-as'));
    $this->redirect_back('Returned to your IT account.', 'success', $redirect_to);
  }

  public function add_user_row_action(array $actions, $user): array {
    if (!$user instanceof WP_User) return $actions;
    if (!EUM_Access::current_user_is_it()) return $actions;
    if (self::is_switched()) return $actions;
    if (!self::can_impersonate((int)get_current_user_id(), (int)$user->ID)) return $actions;

    $url = self::login_as_url((int)$user->ID, home_url('/'));
    $actions['eum_login_as'] = '<a href="' . esc_url($url) . '">Login as</a>';
    return $actions;
  }

  public function add_admin_bar_node($wp_admin_bar): void {
    if (!is_object($wp_admin_bar)) return;

    $state = self::get_state();
    if (empty($state)) return;

    $target_label = self::format_user_label((int)$state['target_user_id']);
    $original_label = self::format_user_label((int)$state['original_user_id']);
    $stop_url = self::stop_url(admin_url('admin.php?page=eum-login-as'));

    $wp_admin_bar->add_node([
      'id' => 'eum-login-as-root',
      'title' => 'Login As: ' . esc_html($target_label),
      'href' => false,
      'meta' => ['class' => 'eum-login-as-adminbar'],
    ]);

    $wp_admin_bar->add_node([
      'id' => 'eum-login-as-return',
      'parent' => 'eum-login-as-root',
      'title' => 'Return to ' . esc_html($original_label),
      'href' => esc_url($stop_url),
    ]);
  }

  public function render_admin_notice(): void {
    if (!is_admin()) return;

    if (!empty($_GET['eum_msg'])) {
      $type = sanitize_key((string)($_GET['eum_type'] ?? 'success'));
      if (!in_array($type, ['success', 'error', 'warning', 'info'], true)) $type = 'success';
      echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html(rawurldecode((string)$_GET['eum_msg'])) . '</p></div>';
    }

    $state = self::get_state();
    if (empty($state)) return;

    echo '<div class="notice notice-warning"><p><strong>Login As is active.</strong> You are currently using <strong>' . esc_html(self::format_user_label((int)$state['target_user_id'])) . '</strong>. <a href="' . esc_url(self::stop_url(admin_url('admin.php?page=eum-login-as'))) . '">Return to your IT account</a>.</p></div>';
  }

  public function render_frontend_banner(): void {
    static $rendered = false;
    if ($rendered) return;

    $state = self::get_state();
    if (empty($state)) return;
    $rendered = true;

    $target_label = self::format_user_label((int)$state['target_user_id']);
    $original_label = self::format_user_label((int)$state['original_user_id']);
    $stop_url = self::stop_url(admin_url('admin.php?page=eum-login-as'));

    echo '<div class="eum-login-as-banner" style="position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;background:#111;color:#fff;padding:12px 16px;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.25);font-size:14px;line-height:1.4;">';
    echo '<div style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;">';
    echo '<div><strong>Login As active.</strong> You are troubleshooting as <strong>' . esc_html($target_label) . '</strong>. All activity is audited and will return to <strong>' . esc_html($original_label) . '</strong>.</div>';
    echo '<div><a href="' . esc_url($stop_url) . '" style="display:inline-block;background:#fff;color:#111;text-decoration:none;padding:8px 12px;border-radius:8px;font-weight:600;">Return to IT account</a></div>';
    echo '</div>';
    echo '</div>';
  }

  private function render_user_search_results(string $search): void {
    $args = [
      'number' => 50,
      'orderby' => 'display_name',
      'order' => 'ASC',
      'fields' => ['ID', 'user_login', 'user_email', 'display_name'],
    ];

    if ($search !== '') {
      $args['search'] = '*' . $search . '*';
      $args['search_columns'] = ['ID', 'user_login', 'user_email', 'display_name'];
    }

    $users = get_users($args);

    echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr><th>User</th><th>Email</th><th>Employment</th><th>Manager</th><th>Action</th></tr></thead><tbody>';
    if (empty($users)) {
      echo '<tr><td colspan="5">No users found.</td></tr>';
    } else {
      $actor_user_id = (int)get_current_user_id();
      foreach ($users as $user) {
        $target_user_id = (int)$user->ID;
        $can = self::can_impersonate($actor_user_id, $target_user_id);
        $manager_id = class_exists('EUM_Personnel') ? (int)get_user_meta($target_user_id, EUM_Personnel::META_MANAGER_ID, true) : 0;
        $status = 'Active';
        if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($target_user_id)) {
          $status = EUM_Personnel::is_rehirable($target_user_id) ? 'Terminated · Rehirable' : 'Terminated · Non-rehireable';
        }

        echo '<tr>';
        echo '<td><strong>' . esc_html((string)($user->display_name ?: $user->user_login)) . '</strong><br><code>#' . (int)$target_user_id . '</code> · ' . esc_html((string)$user->user_login) . '</td>';
        echo '<td>' . esc_html((string)$user->user_email) . '</td>';
        echo '<td>' . esc_html($status) . '</td>';
        echo '<td>' . ($manager_id > 0 ? esc_html(self::format_user_label($manager_id)) : '—') . '</td>';
        echo '<td>';
        if ($can) {
          echo '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">';
          echo '<input type="hidden" name="action" value="eum_login_as_start">';
          echo '<input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '">';
          echo '<input type="hidden" name="redirect_to" value="' . esc_attr(home_url('/')) . '">';
          wp_nonce_field('eum_login_as_start_' . $target_user_id);
          echo '<input type="text" name="reason" value="" placeholder="Reason / ticket (optional)" class="regular-text" style="max-width:220px;">';
          echo '<button class="button button-secondary">Login as</button>';
          echo '</form>';
        } else {
          echo '<span style="opacity:.7;">Unavailable</span>';
        }
        echo '</td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';
  }

  private function render_audit_table(): void {
    $items = get_posts([
      'post_type' => self::CPT_AUDIT,
      'post_status' => 'publish',
      'numberposts' => 50,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    echo '<h2 style="margin-top:28px;">Recent Login As Audit</h2>';
    echo '<table class="widefat striped" style="max-width:1200px;"><thead><tr><th>Started</th><th>Stopped</th><th>IT User</th><th>Target User</th><th>Reason / Ticket</th><th>Status</th><th>IP</th></tr></thead><tbody>';
    if (empty($items)) {
      echo '<tr><td colspan="7">No Login As audit entries yet.</td></tr>';
    } else {
      foreach ($items as $item) {
        $audit_id = (int)$item->ID;
        $original_user_id = (int)get_post_meta($audit_id, self::META_ORIGINAL_USER, true);
        $target_user_id = (int)get_post_meta($audit_id, self::META_TARGET_USER, true);
        $reason = (string)get_post_meta($audit_id, self::META_REASON, true);
        $started_at = (string)get_post_meta($audit_id, self::META_STARTED_AT, true);
        $stopped_at = (string)get_post_meta($audit_id, self::META_STOPPED_AT, true);
        $status = (string)get_post_meta($audit_id, self::META_STATUS, true);
        $ip = (string)get_post_meta($audit_id, self::META_STARTED_IP, true);

        echo '<tr>';
        echo '<td>' . esc_html($started_at ?: '—') . '</td>';
        echo '<td>' . esc_html($stopped_at ?: '—') . '</td>';
        echo '<td>' . esc_html(self::format_user_label($original_user_id)) . '</td>';
        echo '<td>' . esc_html(self::format_user_label($target_user_id)) . '</td>';
        echo '<td>' . esc_html($reason !== '' ? $reason : '—') . '</td>';
        echo '<td>' . esc_html($status !== '' ? ucfirst($status) : '—') . '</td>';
        echo '<td>' . esc_html($ip !== '' ? $ip : '—') . '</td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';
  }

  public function render_page(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');

    $search = sanitize_text_field((string)($_GET['s'] ?? ''));

    echo '<div class="wrap"><h1>Login As User</h1>';
    echo '<p>Start a fully audited troubleshooting session as another user. This is restricted to IT, logs the original user, target user, timestamps, IP, and an optional reason, and provides a one-click return link.</p>';
    echo '<p><strong>Safeguards:</strong> by default you cannot switch into the forced root account, another IT user, or a terminated account.</p>';

    if (self::is_switched()) {
      $state = self::get_state();
      echo '<div class="notice notice-warning inline"><p><strong>Active session:</strong> You are currently using <strong>' . esc_html(self::format_user_label((int)$state['target_user_id'])) . '</strong>. <a class="button button-secondary" href="' . esc_url(self::stop_url(admin_url('admin.php?page=eum-login-as'))) . '">Return to your IT account</a></p></div>';
    }

    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:16px 0 20px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">';
    echo '<input type="hidden" name="page" value="eum-login-as">';
    echo '<p style="margin:0;"><label for="eum-login-as-search" style="display:block;font-weight:600;margin-bottom:4px;">Find user</label><input type="search" id="eum-login-as-search" name="s" value="' . esc_attr($search) . '" class="regular-text" placeholder="Name, login, email, or ID"></p>';
    echo '<p style="margin:0;"><button class="button button-primary">Search</button></p>';
    echo '</form>';

    $this->render_user_search_results($search);
    $this->render_audit_table();
    echo '</div>';
  }
}
