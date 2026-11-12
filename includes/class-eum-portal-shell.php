<?php
if (!defined('ABSPATH')) exit;

class EUM_Portal_Shell {
  const PAGE_OPTION = 'eum_portal_managed_pages';
  const LEGACY_MIGRATION_OPTION = 'eum_portal_legacy_shortcodes_migrated_v1';
  const PAGE_META = '_eum_managed_portal_page';
  const LINK_BOOKMARKS_META = 'eum_dynamic_link_bookmarks';
  private static $rendered = false;
  private static $fallback_render = false;

  public function init(): void {
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 30);
    add_action('parse_request', [$this, 'set_app_route_query_var'], 0);
    add_action('wp_ajax_eum_portal_link_search', [$this, 'ajax_search_dynamic_links']);
    add_action('wp_ajax_eum_portal_link_bookmark', [$this, 'ajax_bookmark_dynamic_link']);
    add_action('wp_body_open', [$this, 'render_frontend_bar'], 1);
    add_action('wp_footer', [$this, 'render_frontend_bar_fallback'], 5);
    add_action('template_redirect', [$this, 'handle_app_route'], 0);
    add_action('template_redirect', [$this, 'redirect_retired_inbox_routes'], 1);
    add_action('init', [$this, 'migrate_legacy_shortcodes'], 98);
    add_action('init', [$this, 'retire_managed_inbox_pages'], 97);
    add_action('init', [$this, 'ensure_managed_pages'], 99);
    add_action('admin_menu', [$this, 'admin_menu'], 47);
    add_filter('show_admin_bar', [$this, 'hide_frontend_admin_bar'], 99);
  }

  private static function page_definitions(): array {
    return [
      'portal_home' => ['title' => 'Home', 'slug' => 'portal-home', 'shortcode' => 'eum_home_dashboard'],
      'appearance' => ['title' => 'Appearance', 'slug' => 'appearance', 'shortcode' => 'eum_profile_appearance'],
      'work_profile' => ['title' => 'Work Profile', 'slug' => 'work-profile', 'shortcode' => 'eum_work_profile'],
      'org_chart' => ['title' => 'Org Chart', 'slug' => 'org-chart', 'shortcode' => 'eum_org_chart'],
      'job_openings' => ['title' => 'Job Openings', 'slug' => 'job-openings', 'shortcode' => 'eum_internal_job_board'],
      'events' => ['title' => 'Events', 'slug' => 'events', 'shortcode' => 'eum_events'],
      'availability' => ['title' => 'Availability', 'slug' => 'availability', 'shortcode' => 'eum_user_availability'],
      'manager_portal' => ['title' => 'Manager Portal', 'slug' => 'manager-portal', 'shortcode' => 'eum_manager_portal'],
      'employee_list' => ['title' => 'Employee List', 'slug' => 'employee-list', 'shortcode' => 'eum_employee_list'],
      'site_manager' => ['title' => 'Site Manager', 'slug' => 'site-manager', 'shortcode' => 'eum_site_manager'],
      'app_access' => ['title' => 'App Access', 'slug' => 'app-access', 'shortcode' => 'eum_click_login_manager'],
      'email' => ['title' => 'Email Access', 'slug' => 'email-access', 'shortcode' => 'eum_webmail_portal'],
      'nextcloud' => ['title' => 'Nextcloud Access', 'slug' => 'nextcloud-access', 'shortcode' => 'eum_nextcloud_portal'],
    ];
  }

  private static function can_manage_portal_pages(): bool {
    return current_user_can('edit_pages') || (class_exists('EUM_Access') && EUM_Access::current_user_is_it());
  }

  public static function page_url(string $key): string {
    $definitions = self::page_definitions();
    if (!isset($definitions[$key])) return home_url('/');

    $pages = get_option(self::PAGE_OPTION, []);
    $page_id = is_array($pages) ? (int)($pages[$key] ?? 0) : 0;
    if ($page_id > 0) {
      $page = get_post($page_id);
      if ($page instanceof WP_Post && $page->post_type === 'page' && $page->post_status !== 'trash') {
        $url = get_permalink($page_id);
        if (is_string($url) && $url !== '') return $url;
      }
    }

    $existing = get_page_by_path((string)$definitions[$key]['slug'], OBJECT, 'page');
    if ($existing instanceof WP_Post) {
      $owner = (string)get_post_meta((int)$existing->ID, self::PAGE_META, true);
      $expected_shortcode = (string)$definitions[$key]['shortcode'];
      if ($owner === $key || has_shortcode((string)$existing->post_content, $expected_shortcode)) {
        $url = get_permalink($existing->ID);
        if (is_string($url) && $url !== '') return $url;
      }
      return home_url('/');
    }

    return home_url('/' . trim((string)$definitions[$key]['slug'], '/') . '/');
  }

  public function hide_frontend_admin_bar(bool $show): bool {
    if (!is_admin() && is_user_logged_in()) return false;
    return $show;
  }

  public function set_app_route_query_var($wp): void {
    $slug = self::app_route_slug_from_request();
    if ($slug === '' || !is_object($wp) || !isset($wp->query_vars) || !is_array($wp->query_vars)) return;
    $wp->query_vars['eum_app_route'] = $slug;
  }

  public function handle_app_route(): void {
    $slug = self::app_route_slug_from_request();
    if ($slug === '') return;

    $app_url = home_url('/app/' . rawurlencode($slug) . '/');
    if (!is_user_logged_in()) {
      wp_safe_redirect(wp_login_url($app_url));
      exit;
    }

    $user = wp_get_current_user();
    if (!$user instanceof WP_User || (int)$user->ID <= 0) {
      wp_die('This app is not available to your account.', 'App unavailable', ['response' => 404]);
    }

    $destinations = $this->app_destinations_for_slug($slug, $user);
    if (empty($destinations)) {
      wp_die('This app or site is not available to your account.', 'App unavailable', ['response' => 404]);
    }
    if (count($destinations) === 1 && empty($destinations[0]['click_login_id'])) {
      $url = self::validated_app_destination((string)$destinations[0]['url']);
      if ($url !== '') {
        wp_redirect($url, 302, 'EUM Portal App Link');
        exit;
      }
      wp_die('The app destination is invalid.', 'App unavailable', ['response' => 404]);
    }

    $this->render_app_destination_chooser($slug, $destinations);
  }

  private static function app_route_slug_from_request(): string {
    $request_path = isset($_SERVER['REQUEST_URI']) ? (string)wp_parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    if ($request_path === '') return '';
    $home_path = trim((string)wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    $request_path = trim($request_path, '/');
    if ($home_path !== '') {
      if ($request_path === $home_path) return '';
      if (strpos($request_path, $home_path . '/') === 0) $request_path = substr($request_path, strlen($home_path) + 1);
    }
    $segments = explode('/', $request_path);
    if (count($segments) !== 2 || strtolower((string)$segments[0]) !== 'app') return '';
    $slug = sanitize_title(rawurldecode((string)$segments[1]));
    return $slug;
  }

  private function app_destinations_for_slug(string $slug, WP_User $user): array {
    $needle = self::normalize_app_alias($slug);
    if ($needle === '') return [];
    $matches = [];
    foreach ($this->app_launcher_items($user) as $app) {
      $app_label = sanitize_text_field((string)($app['label'] ?? ''));
      $parent_match = self::normalize_app_alias($app_label) === $needle;
      $children = [];
      foreach (['choices', 'mailboxes'] as $child_key) {
        foreach ((array)($app[$child_key] ?? []) as $child) {
          if (!is_array($child)) continue;
          $child_label = sanitize_text_field((string)($child['label'] ?? ''));
          $child_url = self::validated_app_destination((string)($child['url'] ?? ''));
          if ($child_label === '' || $child_url === '') continue;
          $child_matches = self::normalize_app_alias($child_label) === $needle;
          if (!$parent_match && !$child_matches) continue;
          $record = ['label' => $app_label . ' · ' . $child_label, 'url' => $child_url];
          foreach (['click_login_id', 'click_login_assignment_id'] as $key) {
            if (!empty($child[$key])) $record[$key] = sanitize_key((string)$child[$key]);
          }
          $children[] = $record;
        }
      }
      if (!empty($children)) {
        $matches = array_merge($matches, $children);
        continue;
      }

      if ($parent_match) {
        $url = self::validated_app_destination((string)($app['url'] ?? ''));
        if ($url !== '') {
          $record = ['label' => $app_label, 'url' => $url];
          foreach (['click_login_id', 'click_login_assignment_id'] as $key) {
            if (!empty($app[$key])) $record[$key] = sanitize_key((string)$app[$key]);
          }
          $matches[] = $record;
        }
      }
    }

    $unique = [];
    $seen = [];
    foreach ($matches as $match) {
      $key = (string)$match['url'] . '|' . (string)($match['click_login_id'] ?? '') . '|' . (string)($match['click_login_assignment_id'] ?? '');
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $unique[] = $match;
    }
    return $unique;
  }

  private static function normalize_app_alias(string $value): string {
    $value = html_entity_decode(sanitize_text_field($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('remove_accents')) $value = remove_accents($value);
    return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $value));
  }

  private static function validated_app_destination(string $url): string {
    $url = esc_url_raw(trim($url));
    if ($url === '') return '';
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) $url = home_url($url);
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) return '';
    return $url;
  }

  private function render_app_destination_chooser(string $slug, array $destinations): void {
    status_header(200);
    nocache_headers();
    get_header();
    $single_destination = count($destinations) === 1;
    $heading = $single_destination ? 'Continue to ' . (string)($destinations[0]['label'] ?? $slug) : 'Choose where to go';
    $description = $single_destination ? 'Open this app in a new tab.' : 'Select an app or site available to your account.';
    echo '<main class="eum-app-route-chooser" data-eum-click-login-rest-root="' . esc_url(rest_url(EUM_Click_Login::REST_NAMESPACE . '/')) . '" data-eum-click-login-rest-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '" style="max-width:760px;margin:36px auto;padding:24px;background:#fff;border:1px solid #eaecf0;border-radius:14px;box-shadow:0 8px 28px rgba(16,24,40,.08)">';
    echo '<h1 style="margin:0 0 8px;color:#101828;font-size:24px">' . esc_html($heading) . '</h1>';
    echo '<p style="margin:0 0 18px;color:#667085">' . esc_html($description) . '</p>';
    echo '<div style="display:grid;gap:8px">';
    foreach ($destinations as $destination) {
      $url = self::validated_app_destination((string)($destination['url'] ?? ''));
      if ($url === '') continue;
      echo '<a href="' . esc_url($url) . '"';
      if (!empty($destination['click_login_id'])) echo ' data-eum-click-login-app="' . esc_attr((string)$destination['click_login_id']) . '" data-eum-click-login-assignment="' . esc_attr((string)($destination['click_login_assignment_id'] ?? '')) . '"';
      echo ' target="_blank" rel="noopener noreferrer" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border:1px solid #eaecf0;border-radius:9px;color:#175cd3;text-decoration:none;font-weight:600">';
      echo '<span>' . esc_html((string)($destination['label'] ?? 'Open app')) . '</span><span aria-hidden="true">&#8599;</span></a>';
    }
    echo '</div></main>';
    get_footer();
    exit;
  }

  public function enqueue_assets(): void {
    if (is_admin() || !is_user_logged_in()) return;

    $css_path = EUM_PLUGIN_DIR . 'assets/portal-shell.css';
    $js_path = EUM_PLUGIN_DIR . 'assets/portal-shell.js';
    wp_enqueue_style('eum-portal-shell', EUM_PLUGIN_URL . 'assets/portal-shell.css', [], file_exists($css_path) ? (string)filemtime($css_path) : EUM_VERSION);
    wp_enqueue_script('eum-portal-shell', EUM_PLUGIN_URL . 'assets/portal-shell.js', [], file_exists($js_path) ? (string)filemtime($js_path) : EUM_VERSION, true);
    wp_localize_script('eum-portal-shell', 'EUMPortalShell', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'searchNonce' => wp_create_nonce('eum_portal_link_search'),
      'notificationNonce' => wp_create_nonce('eum_notification_popup'),
      'messageNonce' => wp_create_nonce('eum_dm_popup'),
    ]);
  }

  public function render_frontend_bar(): void {
    if (self::$rendered || is_admin() || !is_user_logged_in()) return;
    $user = wp_get_current_user();
    if (!$user instanceof WP_User || (int)$user->ID <= 0) return;
    self::$rendered = true;

    $apps = $this->app_launcher_items($user);
    $account_menu_id = 'eum-account-menu-' . (int)$user->ID;
    $apps_menu_id = 'eum-app-menu-' . (int)$user->ID;
    $display_name = $user->display_name !== '' ? $user->display_name : $user->user_login;
    ?>
    <div class="eum-portal-shell" role="region" aria-label="User tools" data-eum-click-login-rest-root="<?php echo esc_url(rest_url(EUM_Click_Login::REST_NAMESPACE . '/')); ?>" data-eum-click-login-rest-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">
      <div class="eum-portal-shell__apps eum-portal-shell__dropdown-wrap">
        <button type="button" class="eum-portal-shell__icon-button eum-portal-shell__apps-button" aria-expanded="false" aria-controls="<?php echo esc_attr($apps_menu_id); ?>" aria-label="Open apps">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h3v3H4zM10.5 4h3v3h-3zM17 4h3v3h-3zM4 10.5h3v3H4zM10.5 10.5h3v3h-3zM17 10.5h3v3h-3zM4 17h3v3H4zM10.5 17h3v3h-3zM17 17h3v3h-3z"/></svg>
        </button>
        <div class="eum-portal-shell__popup eum-portal-shell__apps-menu" id="<?php echo esc_attr($apps_menu_id); ?>" aria-hidden="true">
          <div data-apps-view="main">
            <p class="eum-portal-shell__popup-title">Apps</p>
            <?php if (empty($apps)) : ?>
              <p class="eum-portal-shell__empty">No apps are available for this account.</p>
            <?php else : ?>
              <div class="eum-portal-shell__app-grid">
                <?php foreach ($apps as $app) : ?>
                  <?php if (!empty($app['choices']) && is_array($app['choices'])) : ?>
                    <button type="button" class="eum-portal-shell__app" data-open-app-view="<?php echo esc_attr((string)$app['view_id']); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="<?php echo esc_attr((string)$app['view_id']); ?>" aria-label="Choose a site">
                      <span class="eum-portal-shell__app-icon eum-portal-shell__app-icon--<?php echo esc_attr($app['icon']); ?>" aria-hidden="true"><?php echo $this->app_icon_svg((string)$app['icon']); ?></span>
                      <span><?php echo esc_html($app['label']); ?></span>
                    </button>
                  <?php elseif (!empty($app['mailboxes']) && is_array($app['mailboxes'])) : ?>
                    <button type="button" class="eum-portal-shell__app" data-open-mailboxes aria-haspopup="true" aria-expanded="false" aria-controls="<?php echo esc_attr($apps_menu_id . '-mailboxes'); ?>" aria-label="Open mailbox choices">
                      <span class="eum-portal-shell__app-icon eum-portal-shell__app-icon--<?php echo esc_attr($app['icon']); ?>" aria-hidden="true"><?php echo $this->app_icon_svg((string)$app['icon']); ?></span>
                      <span><?php echo esc_html($app['label']); ?></span>
                    </button>
                  <?php else : ?>
                    <a class="eum-portal-shell__app" href="<?php echo esc_url($app['url']); ?>" <?php if (!empty($app['click_login_id'])) : ?>data-eum-click-login-app="<?php echo esc_attr((string)$app['click_login_id']); ?>" data-eum-click-login-assignment="<?php echo esc_attr((string)($app['click_login_assignment_id'] ?? '')); ?>"<?php endif; ?> target="_blank" rel="noopener noreferrer">
                      <span class="eum-portal-shell__app-icon eum-portal-shell__app-icon--<?php echo esc_attr($app['icon']); ?>" aria-hidden="true"><?php echo $this->app_icon_svg((string)$app['icon']); ?></span>
                      <span><?php echo esc_html($app['label']); ?></span>
                    </a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php foreach ($apps as $app) : ?>
            <?php if (!empty($app['mailboxes']) && is_array($app['mailboxes'])) : ?>
              <div id="<?php echo esc_attr($apps_menu_id . '-mailboxes'); ?>" class="eum-portal-shell__mailbox-view" data-apps-view="mailboxes" aria-hidden="true" hidden>
                <div class="eum-portal-shell__mailbox-view-header">
                  <button type="button" class="eum-portal-shell__mailbox-back" data-back-to-apps aria-label="Back to Apps"><span aria-hidden="true">&larr;</span> Back</button>
                  <p class="eum-portal-shell__popup-title">Choose a mailbox</p>
                </div>
                <div class="eum-portal-shell__mailbox-list">
                  <?php foreach ($app['mailboxes'] as $mailbox) : ?>
                    <a class="eum-portal-shell__mailbox-choice" href="<?php echo esc_url($mailbox['url']); ?>" target="_blank" rel="noopener noreferrer">
                      <span><?php echo esc_html($mailbox['label']); ?></span>
                      <?php if (!empty($mailbox['email'])) : ?><small><?php echo esc_html($mailbox['email']); ?></small><?php endif; ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
            <?php if (!empty($app['choices']) && is_array($app['choices'])) : ?>
              <div id="<?php echo esc_attr((string)$app['view_id']); ?>" class="eum-portal-shell__mailbox-view" data-apps-view="<?php echo esc_attr((string)$app['view_id']); ?>" aria-hidden="true" hidden>
                <div class="eum-portal-shell__mailbox-view-header">
                  <button type="button" class="eum-portal-shell__mailbox-back" data-back-to-apps aria-label="Back to Apps"><span aria-hidden="true">&larr;</span> Back</button>
                  <p class="eum-portal-shell__popup-title"><?php echo esc_html((string)($app['chooser_title'] ?? 'Choose an option')); ?></p>
                </div>
                <div class="eum-portal-shell__mailbox-list">
                  <?php foreach ($app['choices'] as $choice) : ?>
                    <a class="eum-portal-shell__mailbox-choice" href="<?php echo esc_url($choice['url']); ?>" <?php if (!empty($choice['click_login_id'])) : ?>data-eum-click-login-app="<?php echo esc_attr((string)$choice['click_login_id']); ?>" data-eum-click-login-assignment="<?php echo esc_attr((string)($choice['click_login_assignment_id'] ?? '')); ?>"<?php endif; ?> target="_blank" rel="noopener noreferrer"><span><?php echo esc_html($choice['label']); ?></span></a>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="eum-portal-shell__search" role="search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.6"/><path d="m16 16 4.3 4.3"/></svg>
        <label class="screen-reader-text" for="eum-portal-link-search-<?php echo (int)$user->ID; ?>">Search dynamic links</label>
        <input id="eum-portal-link-search-<?php echo (int)$user->ID; ?>" type="search" autocomplete="off" placeholder="Search links" aria-label="Search dynamic links" aria-controls="eum-portal-link-results-<?php echo (int)$user->ID; ?>" aria-expanded="false" aria-autocomplete="list">
        <div class="eum-portal-shell__popup eum-portal-shell__link-results" id="eum-portal-link-results-<?php echo (int)$user->ID; ?>" role="dialog" aria-label="Dynamic link search results" aria-hidden="true">
          <p class="eum-portal-shell__empty">Your bookmarked links will appear here.</p>
        </div>
      </div>

      <?php
      $user_id = (int)$user->ID;
      $unread_notifications = class_exists('EUM_Notification_Center') ? EUM_Notification_Center::unread_count_for_user($user_id, ['direct_message']) : 0;
      $unread_messages = class_exists('EUM_Notification_Center') ? EUM_Notification_Center::unread_count_for_user($user_id, [], ['direct_message']) : 0;
      $notifications_popup_id = 'eum-notifications-popup-' . $user_id;
      $messages_popup_id = 'eum-messages-popup-' . $user_id;
      ?>
      <div class="eum-portal-shell__activity" aria-label="Notifications and messages">
        <div class="eum-portal-shell__dropdown-wrap eum-portal-shell__click-popup eum-portal-shell__notification-wrap">
          <button type="button" class="eum-portal-shell__icon-button eum-portal-shell__notification-button" aria-expanded="false" aria-controls="<?php echo esc_attr($notifications_popup_id); ?>" aria-label="Notifications<?php echo $unread_notifications > 0 ? esc_attr(', ' . $unread_notifications . ' unread') : ''; ?>" title="Notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.2 2.2 0 0 0 2.1-1.6H9.9A2.2 2.2 0 0 0 12 22Zm7-5-1.7-2.1V10a5.3 5.3 0 0 0-4.2-5.2V4a1.1 1.1 0 0 0-2.2 0v.8A5.3 5.3 0 0 0 6.7 10v4.9L5 17v1h14v-1Z"/></svg>
            <span class="eum-portal-shell__notification-count" data-eum-notification-count <?php echo $unread_notifications > 0 ? '' : 'hidden'; ?>><?php echo esc_html($unread_notifications > 99 ? '99+' : (string)$unread_notifications); ?></span>
          </button>
          <section class="eum-portal-shell__popup eum-portal-shell__notification-popup" id="<?php echo esc_attr($notifications_popup_id); ?>" aria-hidden="true" aria-label="Notifications" data-eum-notification-popup>
            <header class="eum-portal-shell__popup-header"><div><strong>Notifications</strong><span data-eum-notification-summary>Unread updates</span></div><button type="button" data-eum-notification-read-all>Mark all read</button></header>
            <nav class="eum-portal-shell__notification-views" aria-label="Notification status"><button type="button" data-eum-notification-view="unread" aria-pressed="true">Unread</button><button type="button" data-eum-notification-view="read" aria-pressed="false">Read</button></nav>
            <div class="eum-portal-shell__notification-list" data-eum-notification-list><p class="eum-portal-shell__empty">Open notifications to load updates.</p></div>
            <button type="button" class="eum-portal-shell__notification-more" data-eum-notification-more hidden>Load older notifications</button>
          </section>
        </div>

        <div class="eum-portal-shell__dropdown-wrap eum-portal-shell__click-popup eum-portal-shell__message-wrap" data-eum-messenger>
          <button type="button" class="eum-portal-shell__icon-button eum-portal-shell__message-button" aria-expanded="false" aria-controls="<?php echo esc_attr($messages_popup_id); ?>" aria-label="Messages<?php echo $unread_messages > 0 ? esc_attr(', ' . $unread_messages . ' unread') : ''; ?>" title="Messages">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2h-9l-5.5 3v-3H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm2 5v2h12V9H6Zm0 4v2h8v-2H6Z"/></svg>
            <span class="eum-portal-shell__notification-count eum-portal-shell__message-count" data-eum-message-count <?php echo $unread_messages > 0 ? '' : 'hidden'; ?>><?php echo esc_html($unread_messages > 99 ? '99+' : (string)$unread_messages); ?></span>
          </button>
          <section class="eum-portal-shell__popup eum-messenger" id="<?php echo esc_attr($messages_popup_id); ?>" aria-hidden="true" aria-label="Messages" data-eum-messenger-popup>
            <header class="eum-messenger__header"><div><strong>Messages</strong><span>Conversations and announcements</span></div><button type="button" class="eum-messenger__close" data-eum-popup-close aria-label="Close messages">&times;</button></header>
            <div class="eum-messenger__teambar" data-eum-team-inbox hidden><label for="eum-team-inbox-<?php echo $user_id; ?>">Viewing inbox</label><select id="eum-team-inbox-<?php echo $user_id; ?>" data-eum-team-inbox-select><option value="0">My inbox</option></select></div>
            <div class="eum-messenger__layout">
              <aside class="eum-messenger__sidebar"><div class="eum-messenger__sidebar-head"><strong>Chats</strong><button type="button" data-eum-show-hidden aria-pressed="false" title="Show archived and deleted chats">Hidden</button><button type="button" data-eum-compose-open>New</button></div><div class="eum-messenger__threads" data-eum-thread-list><p class="eum-messenger__loading">Loading conversations…</p></div></aside>
              <main class="eum-messenger__main">
                <div class="eum-messenger__empty" data-eum-message-empty><span aria-hidden="true">✉</span><strong>Your messages</strong><p>Choose a conversation or start a new one.</p></div>
                <section class="eum-messenger__conversation" data-eum-conversation hidden><header class="eum-messenger__conversation-head"><div class="eum-messenger__conversation-heading"><span class="eum-messenger__thread-avatar" data-eum-chat-avatar aria-hidden="true"></span><div><strong data-eum-conversation-title></strong><span data-eum-conversation-type></span></div></div><div class="eum-messenger__conversation-tools"><button type="button" data-eum-members-open aria-expanded="false">Members</button><button type="button" data-eum-thread-options-toggle aria-expanded="false">Chat options</button><button type="button" data-eum-conversation-back aria-label="Back to conversations">Back</button></div></header><div class="eum-messenger__thread-options" data-eum-thread-options hidden><button type="button" data-eum-chat-archive>Archive for me</button><button type="button" data-eum-chat-delete>Delete for me</button><button type="button" data-eum-chat-mute>Mute</button><button type="button" data-eum-chat-restore hidden>Restore for this user</button><span role="status" data-eum-chat-state-status></span></div><div class="eum-messenger__history" data-eum-message-history></div><form class="eum-messenger__reply" data-eum-message-reply><textarea name="message" rows="1" placeholder="Write a message…" aria-label="Write a message" required></textarea><button type="submit" aria-label="Send message">Send</button></form><p class="eum-messenger__reply-error" data-eum-reply-error role="status" hidden></p><p class="eum-messenger__readonly" data-eum-message-readonly hidden>This conversation is read-only.</p>
                  <section class="eum-messenger__members-panel" data-eum-members-panel hidden><header><div><strong>Chat members</strong><span data-eum-member-count></span></div><button type="button" data-eum-members-close aria-label="Close chat members">×</button></header><div class="eum-messenger__member-list" data-eum-member-list></div><div class="eum-messenger__member-management" data-eum-member-management hidden><div class="eum-messenger__member-actions"><label class="eum-messenger__field">Add people<input type="search" data-eum-member-add-search placeholder="Search eligible people" autocomplete="off"><div class="eum-messenger__contact-results" data-eum-member-add-results hidden></div><div class="eum-messenger__chips" data-eum-member-add-chips></div></label><button type="button" data-eum-member-add> Add selected members</button></div><div class="eum-messenger__admin-editor"><strong>Chat admins</strong><div data-eum-admin-options></div><button type="button" data-eum-admin-save>Save admins</button></div><fieldset class="eum-messenger__member-rules"><legend>Membership exclusions</legend><p>These people and statuses are left out of the chat, including current members.</p><div class="eum-messenger__status-options"><label><input type="checkbox" value="active" data-eum-rule-status> Active employees</label><label><input type="checkbox" value="terminated" data-eum-rule-status> Former staff</label><label><input type="checkbox" value="rehirable" data-eum-rule-status> Rehirable former staff</label><label><input type="checkbox" value="non_rehirable" data-eum-rule-status> Non-rehirable former staff</label></div><label class="eum-messenger__field">Exclude specific people<input type="search" data-eum-exclude-search placeholder="Search people to exclude" autocomplete="off"><div class="eum-messenger__contact-results" data-eum-exclude-results hidden></div><div class="eum-messenger__chips" data-eum-exclude-chips></div></label><button type="button" data-eum-rules-save>Save exclusions</button></fieldset><div class="eum-messenger__chat-image-tools" data-eum-chat-image-tools><strong>Group image</strong><form data-eum-chat-image-form><input type="file" name="chat_image" accept="image/png,image/jpeg,image/gif,image/webp" data-eum-chat-image required><button type="submit">Set image</button></form><button type="button" data-eum-chat-image-reset>Use generated initials</button></div><p role="status" data-eum-members-status></p></div></section>
                </section>
                <section class="eum-messenger__compose" data-eum-message-composer hidden>
                  <header class="eum-messenger__conversation-head"><div><strong>New message</strong><span>Start a conversation</span></div><button type="button" data-eum-compose-cancel>Cancel</button></header>
                  <form data-eum-new-message-form>
                    <label class="eum-messenger__field">Send to<select name="recipient_mode" data-eum-recipient-mode><option value="user">User</option><option value="group">Group</option></select></label>
                    <label class="eum-messenger__field" data-eum-recipient-field>To<input type="search" data-eum-contact-search placeholder="Search people" autocomplete="off"><div class="eum-messenger__contact-results" data-eum-contact-results hidden></div><div class="eum-messenger__chips" data-eum-contact-chips></div></label>
                    <label class="eum-messenger__field">Subject <span>(optional)</span><input type="text" name="subject" maxlength="120" placeholder="Add a subject"></label>
                    <fieldset class="eum-messenger__audience" data-eum-audience-field hidden><legend>Group audience</legend><p class="eum-messenger__audience-hint">Selecting a Job Path includes its nested paths. Groups include their members.</p><div data-eum-audience-options></div><label class="eum-messenger__announcement-option" data-eum-announcement-option hidden><input type="checkbox" name="announcement" value="1" data-eum-announcement> Send as a read-only announcement</label></fieldset>
                    <details class="eum-messenger__member-rules" data-eum-create-rules><summary>Membership exclusions</summary><p>Excluded people and statuses will not be added to the new chat.</p><div class="eum-messenger__status-options"><label><input type="checkbox" value="active" data-eum-create-rule-status> Active employees</label><label><input type="checkbox" value="terminated" data-eum-create-rule-status> Former staff</label><label><input type="checkbox" value="rehirable" data-eum-create-rule-status> Rehirable former staff</label><label><input type="checkbox" value="non_rehirable" data-eum-create-rule-status> Non-rehirable former staff</label></div><label class="eum-messenger__field">Exclude specific people<input type="search" data-eum-create-exclude-search placeholder="Search people to exclude" autocomplete="off"><div class="eum-messenger__contact-results" data-eum-create-exclude-results hidden></div><div class="eum-messenger__chips" data-eum-create-exclude-chips></div></label></details>
                    <label class="eum-messenger__field eum-messenger__compose-body">Message<textarea name="message" rows="4" required placeholder="Write your message…"></textarea></label>
                    <div class="eum-messenger__compose-actions"><p role="status" data-eum-compose-error></p><button type="submit">Send message</button></div>
                  </form>
                </section>
              </main>
            </div>
          </section>
        </div>
      </div>

      <div class="eum-portal-shell__account eum-portal-shell__dropdown-wrap">
        <button type="button" class="eum-portal-shell__account-button" aria-expanded="false" aria-controls="<?php echo esc_attr($account_menu_id); ?>" aria-label="Account for <?php echo esc_attr($display_name); ?>">
          <span class="eum-portal-shell__account-name"><?php echo esc_html($display_name); ?></span>
          <?php echo class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html((int)$user->ID, 38, 'eum-portal-shell__avatar') : get_avatar((int)$user->ID, 38); ?>
        </button>
        <div class="eum-portal-shell__popup eum-portal-shell__account-menu" id="<?php echo esc_attr($account_menu_id); ?>" aria-hidden="true">
          <a class="eum-portal-shell__sign-out" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Sign out</a>
          <div class="eum-portal-shell__identity">
            <?php echo class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html((int)$user->ID, 84, 'eum-portal-shell__identity-avatar') : get_avatar((int)$user->ID, 84); ?>
            <div class="eum-portal-shell__identity-copy">
              <strong><?php echo esc_html($display_name); ?></strong>
              <span><?php echo esc_html((string)$user->user_email); ?></span>
            </div>
          </div>
          <div class="eum-portal-shell__account-links">
            <a href="<?php echo esc_url(self::page_url('appearance')); ?>">Account</a>
            <a href="<?php echo esc_url(self::page_url('work_profile')); ?>">My profile</a>
          </div>
        </div>
      </div>
    </div>
    <?php if (!self::$fallback_render) : ?><div class="eum-portal-shell-spacer" aria-hidden="true"></div><?php endif; ?>
    <?php
  }

  public function ajax_search_dynamic_links(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Please sign in to search links.'], 401);
    check_ajax_referer('eum_portal_link_search', 'nonce');
    if (!class_exists('EUM_Dynamic_Links')) wp_send_json_success(['items' => []]);

    $query = sanitize_text_field(wp_unslash((string)($_POST['q'] ?? '')));
    $query = function_exists('mb_substr') ? trim(mb_substr($query, 0, 100)) : trim(substr($query, 0, 100));
    $context = EUM_Dynamic_Links::access_context(get_current_user_id());
    $bookmark_ids = self::link_bookmark_ids(get_current_user_id());
    if ($query === '') {
      $items = [];
      foreach ($bookmark_ids as $post_id) {
        $item = self::dynamic_link_search_item($post_id, $context, true);
        if ($item !== null) $items[] = $item;
      }
      wp_send_json_success(['items' => $items, 'view' => 'bookmarks']);
    }

    $posts = get_posts([
      'post_type' => EUM_Dynamic_Links::CPT,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'orderby' => 'title',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);
    $terms = preg_split('/\\s+/', function_exists('mb_strtolower') ? mb_strtolower($query) : strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($terms)) $terms = [strtolower($query)];
    $matches = [];

    foreach ((array)$posts as $post) {
      $post_id = (int)$post->ID;
      $item = self::dynamic_link_search_item($post_id, $context, in_array($post_id, $bookmark_ids, true));
      if ($item === null) continue;
      $haystack = function_exists('mb_strtolower')
        ? mb_strtolower($item['title'] . ' ' . $item['header'] . ' ' . $item['description'] . ' ' . implode(' ', $item['categories']) . ' ' . $item['url'])
        : strtolower($item['title'] . ' ' . $item['header'] . ' ' . $item['description'] . ' ' . implode(' ', $item['categories']) . ' ' . $item['url']);
      $all_terms_match = true;
      foreach ((array)$terms as $term) {
        if ($term !== '' && strpos($haystack, $term) === false) {
          $all_terms_match = false;
          break;
        }
      }
      if (!$all_terms_match) continue;

      $title_for_match = function_exists('mb_strtolower') ? mb_strtolower($item['title']) : strtolower($item['title']);
      $query_for_match = function_exists('mb_strtolower') ? mb_strtolower($query) : strtolower($query);
      $rank = ($title_for_match === $query_for_match) ? 0 : ((strpos($title_for_match, $query_for_match) === 0) ? 1 : ((strpos($title_for_match, $query_for_match) !== false) ? 2 : 3));
      $item['rank'] = $rank;
      $matches[] = $item;
    }

    usort($matches, static function ($a, $b) {
      if ($a['rank'] !== $b['rank']) return $a['rank'] <=> $b['rank'];
      return strcasecmp($a['title'], $b['title']);
    });
    $matches = array_slice($matches, 0, 12);
    foreach ($matches as &$match) {
      unset($match['rank'], $match['categories']);
    }
    unset($match);
    wp_send_json_success(['items' => $matches, 'view' => 'search']);
  }

  public function ajax_bookmark_dynamic_link(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Please sign in to bookmark links.'], 401);
    check_ajax_referer('eum_portal_link_search', 'nonce');
    if (!class_exists('EUM_Dynamic_Links')) wp_send_json_error(['message' => 'Link search is unavailable.'], 503);

    $post_id = absint($_POST['link_id'] ?? 0);
    $bookmark = sanitize_text_field(wp_unslash((string)($_POST['bookmarked'] ?? '')));
    if ($post_id <= 0 || !in_array($bookmark, ['0', '1'], true)) {
      wp_send_json_error(['message' => 'Choose a valid link.'], 400);
    }

    $user_id = get_current_user_id();
    $context = EUM_Dynamic_Links::access_context($user_id);
    if (self::dynamic_link_search_item($post_id, $context, false) === null) {
      wp_send_json_error(['message' => 'That link is unavailable.'], 404);
    }

    $bookmark_ids = self::link_bookmark_ids($user_id);
    if ($bookmark === '1') {
      if (!in_array($post_id, $bookmark_ids, true)) $bookmark_ids[] = $post_id;
    } else {
      $bookmark_ids = array_values(array_diff($bookmark_ids, [$post_id]));
    }

    if (empty($bookmark_ids)) delete_user_meta($user_id, self::LINK_BOOKMARKS_META);
    else update_user_meta($user_id, self::LINK_BOOKMARKS_META, $bookmark_ids);
    wp_send_json_success(['link_id' => $post_id, 'bookmarked' => $bookmark === '1']);
  }

  private static function link_bookmark_ids(int $user_id): array {
    $stored = get_user_meta($user_id, self::LINK_BOOKMARKS_META, true);
    $ids = array_values(array_unique(array_filter(array_map('absint', (array)$stored))));
    return $ids;
  }

  private static function dynamic_link_search_item(int $post_id, array $context, bool $bookmarked): ?array {
    if ($post_id <= 0 || !EUM_Dynamic_Links::link_is_visible_to_context($post_id, $context, 'any')) return null;
    $post = get_post($post_id);
    if (!$post instanceof WP_Post || $post->post_type !== EUM_Dynamic_Links::CPT || $post->post_status !== 'publish') return null;

    $url = esc_url_raw((string)get_post_meta($post_id, EUM_Dynamic_Links::META_URL, true));
    if ($url === '') return null;
    $description_raw = (string)get_post_meta($post_id, EUM_Dynamic_Links::META_DESC, true);
    $description = sanitize_text_field(wp_strip_all_tags(html_entity_decode($description_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $category_names = wp_get_object_terms($post_id, EUM_Dynamic_Links::TAX_CATEGORY, ['fields' => 'names']);
    if (is_wp_error($category_names)) $category_names = [];
    $raw_title = (string)($post->post_title ?? '');
    $title = sanitize_text_field(wp_strip_all_tags(html_entity_decode($raw_title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $header_raw = EUM_Dynamic_Links::dashboard_header_for_post($post_id);
    $header = sanitize_text_field(wp_strip_all_tags(html_entity_decode($header_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $target = (string)get_post_meta($post_id, EUM_Dynamic_Links::META_TARGET, true);
    if (!in_array($target, ['_self', '_blank'], true)) $target = '_self';

    return [
      'id' => $post_id,
      'title' => $title,
      'header' => $header,
      'description' => $description,
      'url' => $url,
      'target' => $target,
      'bookmarked' => $bookmarked,
      'categories' => array_values(array_map('strval', (array)$category_names)),
    ];
  }

  public function render_frontend_bar_fallback(): void {
    if (self::$rendered || is_admin() || !is_user_logged_in()) return;
    echo '<style id="eum-portal-shell-fallback-style">body{padding-top:46px!important}@media(max-width:640px){body{padding-top:86px!important}}</style>';
    self::$fallback_render = true;
    $this->render_frontend_bar();
    self::$fallback_render = false;
  }

  private function app_launcher_items(WP_User $user): array {
    $items = [];
    $terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated((int)$user->ID);

    if (!$terminated && class_exists('EUM_Nextcloud_Bridge') && EUM_Nextcloud_Bridge::user_has_app_access((int)$user->ID)) {
      $settings = EUM_Nextcloud_Bridge::get_settings();
      $url = 'https://internal.jas.enterprises/cloud/index.php/apps/user_oidc/login/1';
      if ($url !== '') {
        $items[] = ['label' => !empty($settings['label']) ? (string)$settings['label'] : 'Nextcloud', 'url' => $url, 'icon' => 'nextcloud', 'new_tab' => !empty($settings['open_new_tab'])];
      }
    }

    if (!$terminated && class_exists('EUM_Service_Bridge')) {
      $settings = EUM_Service_Bridge::get_settings();
      if (!empty($settings['webmail_enabled'])) {
        $mailbox_apps = (new EUM_Service_Bridge())->current_user_app_launch_items();
        if (count($mailbox_apps) === 1) {
          $url = (string)($mailbox_apps[0]['url'] ?? '');
          if ($url !== '') {
            $items[] = ['label' => 'Email', 'url' => $url, 'icon' => 'email', 'new_tab' => !empty($settings['open_new_tab'])];
          }
        } elseif (count($mailbox_apps) > 1) {
          $mailbox_choices = [];
          foreach ($mailbox_apps as $mailbox_app) {
            $url = (string)($mailbox_app['url'] ?? '');
            if ($url === '') continue;
            $mailbox_label = sanitize_text_field((string)($mailbox_app['label'] ?? ''));
            if ($mailbox_label === '') $mailbox_label = sanitize_email((string)($mailbox_app['email'] ?? ''));
            if ($mailbox_label === '') continue;
            $mailbox_choices[] = [
              'label' => $mailbox_label,
              'email' => sanitize_email((string)($mailbox_app['email'] ?? '')),
              'url' => $url,
            ];
          }
          if (count($mailbox_choices) > 1) {
            $items[] = ['label' => 'Mail', 'icon' => 'email', 'mailboxes' => $mailbox_choices, 'new_tab' => !empty($settings['open_new_tab'])];
          }
        }
      }
    }

    $items = apply_filters('eum_portal_app_launcher_items', $items, $user);
    if (!is_array($items)) return [];
    $clean = [];
    foreach ($items as $item) {
      if (!is_array($item)) continue;
      $label = sanitize_text_field((string)($item['label'] ?? ''));
      $icon = sanitize_key((string)($item['icon'] ?? 'app'));
      if ($label === '') continue;
      if (!empty($item['mailboxes']) && is_array($item['mailboxes'])) {
        $mailboxes = [];
        foreach ($item['mailboxes'] as $mailbox) {
          if (!is_array($mailbox)) continue;
          $mailbox_url = esc_url_raw((string)($mailbox['url'] ?? ''));
          $mailbox_label = sanitize_text_field((string)($mailbox['label'] ?? ''));
          if ($mailbox_url === '' || $mailbox_label === '') continue;
          $mailboxes[] = [
            'url' => $mailbox_url,
            'label' => $mailbox_label,
            'email' => sanitize_email((string)($mailbox['email'] ?? '')),
          ];
        }
        if (count($mailboxes) > 1) {
          $clean[] = ['label' => $label, 'icon' => $icon, 'mailboxes' => $mailboxes, 'new_tab' => !empty($item['new_tab'])];
          continue;
        }
      }
      if (!empty($item['choices']) && is_array($item['choices'])) {
        $choices = [];
        foreach ($item['choices'] as $choice) {
          if (!is_array($choice)) continue;
          $choice_url = esc_url_raw((string)($choice['url'] ?? ''));
          $choice_label = sanitize_text_field((string)($choice['label'] ?? ''));
          if ($choice_url === '' || $choice_label === '') continue;
          $clean_choice = ['url' => $choice_url, 'label' => $choice_label];
          $choice_app_id = sanitize_key((string)($choice['click_login_id'] ?? ''));
          $choice_login_id = sanitize_key((string)($choice['click_login_assignment_id'] ?? ''));
          if ($choice_app_id !== '' && $choice_login_id !== '') {
            $clean_choice['click_login_id'] = $choice_app_id;
            $clean_choice['click_login_assignment_id'] = $choice_login_id;
          }
          $choices[] = $clean_choice;
        }
        if (!empty($choices)) {
          $view_id = 'eum-app-view-' . (int)get_current_user_id() . '-' . count($clean);
          $clean[] = [
            'label' => $label,
            'icon' => $icon,
            'choices' => $choices,
            'view_id' => $view_id,
            'chooser_title' => sanitize_text_field((string)($item['chooser_title'] ?? ('Choose ' . $label))),
          ];
          continue;
        }
      }
      $url = esc_url_raw((string)($item['url'] ?? ''));
      if ($url === '') continue;
      $clean_item = ['url' => $url, 'label' => $label, 'icon' => $icon, 'new_tab' => !empty($item['new_tab'])];
      $click_login_id = sanitize_key((string)($item['click_login_id'] ?? ''));
      if ($click_login_id !== '') $clean_item['click_login_id'] = $click_login_id;
      $click_login_assignment_id = sanitize_key((string)($item['click_login_assignment_id'] ?? ''));
      if ($click_login_id !== '' && $click_login_assignment_id !== '') $clean_item['click_login_assignment_id'] = $click_login_assignment_id;
      $clean[] = $clean_item;
    }
    return $clean;
  }

  private function app_icon_svg(string $icon): string {
    if ($icon === 'site-manager') {
      return '<svg viewBox="0 0 24 24"><path d="M3 20h18M5 20V8l7-4 7 4v12M9 20v-5h6v5M8 10h.01M12 10h.01M16 10h.01"/></svg>';
    }
    if ($icon === 'email') {
      return '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>';
    }
    if ($icon === 'nextcloud') {
      return '<svg viewBox="0 0 24 24"><circle cx="6" cy="12" r="3.3"/><circle cx="12" cy="12" r="3.3"/><circle cx="18" cy="12" r="3.3"/></svg>';
    }
    if ($icon === 'messages') {
      return '<svg viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-8l-5 3v-3H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm2 5v2h12V9H6Zm0 4v2h8v-2H6Z"/></svg>';
    }
    return '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg>';
  }

  public function migrate_legacy_shortcodes(): void {
    if (!self::can_manage_portal_pages() || get_option(self::LEGACY_MIGRATION_OPTION)) return;
    global $wpdb;
    if (!isset($wpdb) || !isset($wpdb->posts)) return;

    $aliases = [
      'eum_dashboard_home' => 'eum_home_dashboard',
      'eum_profile_editor' => 'eum_work_profile',
      'eum_profile_portal' => 'eum_work_profile',
      'eum_change_work_queue' => 'eum_change_dashboard',
      'eum_notifications' => 'eum_notification_center',
    ];
    $patterns = [];
    $params = ['revision', 'attachment', 'nav_menu_item', 'trash', 'auto-draft'];
    foreach (array_keys($aliases) as $alias) {
      $patterns[] = 'post_content LIKE %s';
      $params[] = '%' . $wpdb->esc_like('[' . $alias) . '%';
    }

    $sql = "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN (%s, %s, %s) AND post_status NOT IN (%s, %s) AND (" . implode(' OR ', $patterns) . ')';
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
    $tag_pattern = '/\[(\/?)(' . implode('|', array_map('preg_quote', array_keys($aliases))) . ')(?=[\s\]\/])/';
    $failed = false;
    foreach ((array)$rows as $row) {
      $content = (string)($row->post_content ?? '');
      $updated = preg_replace_callback($tag_pattern, static function ($match) use ($aliases) {
        return '[' . $match[1] . $aliases[$match[2]];
      }, $content);
      if (is_string($updated) && $updated !== $content) {
        $result = wp_update_post(wp_slash(['ID' => (int)$row->ID, 'post_content' => $updated]), true);
        if (is_wp_error($result) || (int)$result <= 0) $failed = true;
      }
    }

    if (!$failed) update_option(self::LEGACY_MIGRATION_OPTION, 1, false);
  }

  public function retire_managed_inbox_pages(): void {
    $pages = get_option(self::PAGE_OPTION, []);
    if (!is_array($pages)) return;
    foreach (['notifications', 'messages'] as $key) {
      $page_id = (int)($pages[$key] ?? 0);
      $page = $page_id > 0 ? get_post($page_id) : null;
      if ($page instanceof WP_Post && $page->post_type === 'page' && (string)get_post_meta($page_id, self::PAGE_META, true) === $key) {
        if ($page->post_status === 'publish') {
          $updated = wp_update_post(['ID' => $page_id, 'post_status' => 'draft'], true);
          if (is_wp_error($updated) || (int)$updated <= 0) continue;
        }
      }
    }
  }

  public function redirect_retired_inbox_routes(): void {
    if (is_admin()) return;
    $request_path = untrailingslashit((string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    $pages = get_option(self::PAGE_OPTION, []);
    if (!is_array($pages)) return;
    foreach (['messages', 'notifications'] as $legacy_slug) {
      $page_id = (int)($pages[$legacy_slug] ?? 0);
      $page = $page_id > 0 ? get_post($page_id) : null;
      if (!$page instanceof WP_Post || $page->post_type !== 'page' || (string)get_post_meta($page_id, self::PAGE_META, true) !== $legacy_slug) continue;
      $legacy_path = untrailingslashit((string)wp_parse_url(get_permalink($page_id), PHP_URL_PATH));
      if ($legacy_path === '') $legacy_path = untrailingslashit((string)wp_parse_url(home_url('/' . $legacy_slug . '/'), PHP_URL_PATH));
      if ($request_path !== $legacy_path) continue;
      $args = $legacy_slug === 'messages'
        ? ['eum_open_messages' => '1']
        : ['eum_open_notifications' => '1'];
      if ($legacy_slug === 'messages' && !empty($_GET['eum_dm_thread'])) {
        $args['eum_dm_thread'] = absint(wp_unslash($_GET['eum_dm_thread']));
      }
      if ($legacy_slug === 'messages' && !empty($_GET['eum_dm_inbox_user'])) {
        $args['eum_dm_inbox_user'] = absint(wp_unslash($_GET['eum_dm_inbox_user']));
      }
      wp_safe_redirect(add_query_arg($args, home_url('/')), 302);
      exit;
    }
  }

  public function ensure_managed_pages(): void {
    if (!self::can_manage_portal_pages()) return;

    $pages = get_option(self::PAGE_OPTION, []);
    if (!is_array($pages)) $pages = [];
    $changed = false;
    foreach (self::page_definitions() as $key => $definition) {
      $shortcode = (string)$definition['shortcode'];
      if (!shortcode_exists($shortcode)) continue;
      $page_id = $this->ensure_managed_page($key, $definition, (int)($pages[$key] ?? 0));
      if ($page_id > 0 && (int)($pages[$key] ?? 0) !== $page_id) {
        $pages[$key] = $page_id;
        $changed = true;
      }
    }
    if ($changed || !is_array(get_option(self::PAGE_OPTION, null))) {
      update_option(self::PAGE_OPTION, $pages, false);
    }
  }

  private function ensure_managed_page(string $key, array $definition, int $page_id): int {
    $page = $page_id > 0 ? get_post($page_id) : null;
    if (!$page instanceof WP_Post || $page->post_type !== 'page' || $page->post_status === 'trash') {
      $page = null;
    }
    if ($page instanceof WP_Post) {
      $owner = (string)get_post_meta((int)$page->ID, self::PAGE_META, true);
      if ($owner !== '' && $owner !== $key) $page = null;
      elseif ($owner === '' && !$this->page_can_be_adopted($page, $key, (string)$definition['shortcode'])) $page = null;
    }

    if (!$page) {
      $existing = get_page_by_path((string)$definition['slug'], OBJECT, 'page');
      if ($existing instanceof WP_Post && $this->page_can_be_adopted($existing, $key, (string)$definition['shortcode'])) {
        $page = $existing;
      }
    }

    if (!$page) {
      $unique_slug = wp_unique_post_slug((string)$definition['slug'], 0, 'publish', 'page', 0);
      $new_id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => (string)$definition['title'],
        'post_name' => $unique_slug,
        'post_content' => '[' . (string)$definition['shortcode'] . ']',
        'post_author' => (int)get_current_user_id(),
      ], true);
      if (is_wp_error($new_id) || (int)$new_id <= 0) return 0;
      $page = get_post((int)$new_id);
    }

    if (!$page instanceof WP_Post) return 0;
    $content = (string)$page->post_content;
    $update = ['ID' => (int)$page->ID];
    $needs_update = false;
    if (!has_shortcode($content, (string)$definition['shortcode'])) {
      $content = trim($content . "\n\n[" . (string)$definition['shortcode'] . ']');
      $update['post_content'] = $content;
      $needs_update = true;
    }
    if ($page->post_status !== 'publish') {
      $update['post_status'] = 'publish';
      $needs_update = true;
    }
    if ($needs_update) wp_update_post(wp_slash($update));
    update_post_meta((int)$page->ID, self::PAGE_META, $key);
    return (int)$page->ID;
  }

  private function page_can_be_adopted(WP_Post $page, string $key, string $shortcode): bool {
    $owner = (string)get_post_meta((int)$page->ID, self::PAGE_META, true);
    if ($owner !== '') return $owner === $key;
    if (has_shortcode((string)$page->post_content, $shortcode)) return true;
    return trim((string)$page->post_content) === '';
  }

  public function admin_menu(): void {
    add_submenu_page(
      EUM_Admin::parent_slug('experience'),
      'Portal Pages',
      'Portal Pages',
      'read',
      'eum-portal-pages',
      [$this, 'render_admin_page']
    );
  }

  public function render_admin_page(): void {
    if (!self::can_manage_portal_pages()) wp_die('Not allowed.');
    $pages = get_option(self::PAGE_OPTION, []);
    if (!is_array($pages)) $pages = [];
    echo '<div class="wrap"><h1>Portal Pages</h1><p>The plugin keeps these feature pages available and fills in a missing dashboard shortcode without replacing other page content.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Feature</th><th>Shortcode</th><th>Page</th><th>Actions</th></tr></thead><tbody>';
    foreach (self::page_definitions() as $key => $definition) {
      $page_id = (int)($pages[$key] ?? 0);
      $page = $page_id > 0 ? get_post($page_id) : null;
      $page_url = self::page_url($key);
      echo '<tr><td>' . esc_html((string)$definition['title']) . '</td><td><code>[' . esc_html((string)$definition['shortcode']) . ']</code></td>';
      echo '<td>' . ($page instanceof WP_Post && $page->post_status === 'publish' ? '<a href="' . esc_url($page_url) . '">View page</a>' : '<span class="description">Not yet created</span>') . '</td><td>';
      if ($page instanceof WP_Post && current_user_can('edit_post', $page_id)) echo '<a href="' . esc_url((string)get_edit_post_link($page_id, 'raw')) . '">Edit</a>';
      echo '</td></tr>';
    }
    echo '</tbody></table></div>';
  }
}
