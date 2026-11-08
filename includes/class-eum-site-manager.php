<?php
if (!defined('ABSPATH')) exit;

/**
 * Central registry and manager-facing provisioning screen for connected sites.
 * The destination site remains responsible for creating its local WordPress
 * accounts through the companion plugin's authenticated REST endpoint.
 */
class EUM_Site_Manager {
  const OPTION_SITES = 'eum_site_manager_sites';
  const OPTION_AUDIT = 'eum_site_manager_audit';
  const OPTION_SYNC_QUEUE = 'eum_site_manager_user_sync_queue';
  const OPTION_DEPROVISION_QUEUE = 'eum_site_manager_user_deprovision_queue';
  const OPTION_PATH_MEMBERSHIP_BACKFILL = 'eum_site_manager_path_membership_backfill_v1';
  const OPTION_TERMINATED_BACKFILL = 'eum_site_manager_terminated_backfill_v1';
  const OPTION_JPEG_AVATAR_BACKFILL = 'eum_site_manager_jpeg_avatar_backfill_v3';
  const AUTO_DISABLE_EXEMPT_LOGINS = ['superadmin', 'josephsimpson'];
  const PAIRING_TRANSIENT_PREFIX = 'eum_site_pairing_';
  const USER_SYNC_HOOK = 'eum_site_manager_process_user_sync_queue';

  public function init(): void {
    add_action('admin_menu', [$this, 'admin_menu'], 48);
    add_action('admin_post_eum_site_manager_save_site', [$this, 'handle_save_site']);
    add_action('admin_post_eum_site_manager_delete_site', [$this, 'handle_delete_site']);
    add_action('admin_post_eum_site_manager_test_connection', [$this, 'handle_test_connection']);
    add_action('admin_post_eum_site_manager_launch', [$this, 'handle_launch_site']);
    add_action('admin_post_eum_site_manager_provision', [$this, 'handle_provision']);
    add_action('deleted_user', [$this, 'queue_deleted_user_deprovision'], 90, 3);
    add_action('profile_update', [$this, 'queue_user_sync'], 90, 2);
    add_action('added_user_meta', [$this, 'queue_user_sync_from_meta'], 90, 4);
    add_action('updated_user_meta', [$this, 'queue_user_sync_from_meta'], 90, 4);
    add_action('deleted_user_meta', [$this, 'queue_user_sync_from_meta'], 90, 4);
    add_action('set_object_terms', [$this, 'queue_user_sync_from_terms'], 90, 6);
    add_action('init', [$this, 'maybe_queue_path_membership_backfill'], 97);
    add_action('init', [$this, 'maybe_queue_placeholder_avatar_backfill'], 98);
    add_action('init', [$this, 'maybe_queue_terminated_user_backfill'], 99);
    add_action(self::USER_SYNC_HOOK, [$this, 'process_user_sync_queue']);
    add_action('wp_ajax_eum_site_manager_create_pairing_code', [$this, 'ajax_create_pairing_code']);
    add_action('rest_api_init', [$this, 'register_routes']);
    add_shortcode('eum_site_manager', [$this, 'shortcode']);
    add_filter('eum_portal_app_launcher_items', [$this, 'add_launcher_item'], 30, 2);
  }

  public static function get_sites(): array {
    $raw = get_option(self::OPTION_SITES, []);
    if (!is_array($raw)) return [];

    $sites = [];
    foreach ($raw as $site_id => $site) {
      if (!is_array($site)) continue;
      $site_id = sanitize_key((string)($site['id'] ?? $site_id));
      $label = sanitize_text_field((string)($site['label'] ?? ''));
      $site_url = esc_url_raw((string)($site['site_url'] ?? ''));
      $launch_url = esc_url_raw((string)($site['launch_url'] ?? ''));
      $provision_url = esc_url_raw((string)($site['provision_url'] ?? ''));
      $site_key = trim((string)($site['site_key'] ?? ''));
      if ($site_id === '' || $label === '' || !self::is_https_url($launch_url) || !self::is_https_url($provision_url) || $site_key === '') continue;

      $sites[$site_id] = [
        'id' => $site_id,
        'label' => $label,
        'site_url' => $site_url,
        'launch_url' => $launch_url,
        'provision_url' => $provision_url,
        'site_key' => $site_key,
        'site_role_map' => self::normalize_site_role_map($site['site_role_map'] ?? []),
        'manager_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($site['manager_user_ids'] ?? [])), static function ($id) { return $id > 0; }))),
        'job_path_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($site['job_path_ids'] ?? [])), static function ($id) { return $id > 0; }))),
        'group_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($site['group_ids'] ?? [])), static function ($id) { return $id > 0; }))),
        'path_app_launcher_enabled' => !array_key_exists('path_app_launcher_enabled', $site) || !empty($site['path_app_launcher_enabled']),
        'provisioned_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($site['provisioned_user_ids'] ?? [])), static function ($id) { return $id > 0; }))),
        'auto_provisioned_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($site['auto_provisioned_user_ids'] ?? [])), static function ($id) { return $id > 0; }))),
        'manual_scope_override_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($site['manual_scope_override_user_ids'] ?? [])), static function ($id) { return $id > 0; }))),
        'manager_provisioned_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($site['manager_provisioned_user_ids'] ?? [])), static function ($id) { return $id > 0; }))),
        'app_launcher_disabled_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($site['app_launcher_disabled_user_ids'] ?? [])), static function ($id) { return $id > 0; }))),
      ];
    }
    return $sites;
  }

  public static function get_site(string $site_id): ?array {
    $sites = self::get_sites();
    $site_id = sanitize_key($site_id);
    return isset($sites[$site_id]) ? $sites[$site_id] : null;
  }

  public function add_launcher_item(array $items, $user): array {
    if (!$user instanceof WP_User) return $items;
    $user_id = (int)$user->ID;
    $sites = self::get_sites();
    $is_terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);

    $manager_choices = [];
    foreach (self::sites_user_can_manage($user_id) as $site) {
      $manager_choices[] = [
        'label' => $site['label'],
        'url' => self::site_manager_url((string)$site['id']),
      ];
    }
    if (count($manager_choices) === 1) {
      $items[] = [
        'label' => 'Site Manager Admin',
        'icon' => 'site-manager',
        'url' => $manager_choices[0]['url'],
        'new_tab' => true,
      ];
    } elseif (!empty($manager_choices)) {
      $items[] = [
        'label' => 'Site Manager Admin',
        'icon' => 'site-manager',
        'chooser_title' => 'Choose a site to manage',
        'choices' => $manager_choices,
      ];
    }

    $connected_choices = [];
    if (!$is_terminated) {
      foreach ($sites as $site) {
        if (in_array($user_id, (array)($site['app_launcher_disabled_user_ids'] ?? []), true)) continue;
        $is_provisioned = in_array($user_id, (array)$site['provisioned_user_ids'], true);
        $can_manage = self::user_can_manage_site($user_id, $site);
        $has_scope = self::site_has_provision_scope($site);
        $is_scope_member = $has_scope && self::user_matches_site_filters($user_id, $site);
        $has_auto_source = in_array($user_id, (array)$site['auto_provisioned_user_ids'], true);
        $has_manual_scope_override = in_array($user_id, (array)$site['manual_scope_override_user_ids'], true);
        $has_manager_source = in_array($user_id, (array)$site['manager_provisioned_user_ids'], true);
        if ($is_provisioned && $has_scope && !$is_scope_member && !$can_manage && !$has_manual_scope_override) continue;
        if ($is_provisioned && !$has_scope && ($has_auto_source || $has_manager_source) && !$can_manage && !$has_manual_scope_override) continue;
        if (!$is_provisioned && !$can_manage) continue;
        $connected_choices[] = [
          'label' => $site['label'],
          'url' => $is_provisioned ? $site['launch_url'] : self::site_login_launch_url((string)$site['id']),
        ];
      }
    }
    if (count($connected_choices) === 1) {
      $items[] = [
        'label' => 'Site Manager',
        'icon' => 'site-manager',
        'url' => $connected_choices[0]['url'],
        'new_tab' => true,
      ];
    } elseif (!empty($connected_choices)) {
      $items[] = [
        'label' => 'Site Manager',
        'icon' => 'site-manager',
        'chooser_title' => 'Choose a site to open',
        'choices' => $connected_choices,
      ];
    }
    return $items;
  }

  public static function sites_user_can_manage(int $user_id): array {
    $sites = [];
    foreach (self::get_sites() as $site) {
      if (self::user_can_manage_site($user_id, $site)) $sites[] = $site;
    }
    usort($sites, static function ($a, $b) { return strcasecmp((string)$a['label'], (string)$b['label']); });
    return $sites;
  }

  public static function user_can_manage_site(int $user_id, array $site): bool {
    if ($user_id <= 0 || !get_user_by('id', $user_id)) return false;
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) return true;
    $allowed_managers = array_map('intval', (array)($site['manager_user_ids'] ?? []));
    return !empty($allowed_managers) && in_array($user_id, $allowed_managers, true);
  }

  private static function is_superadmin_user(int $user_id): bool {
    $user = $user_id > 0 ? get_user_by('id', $user_id) : false;
    return $user instanceof WP_User && strtolower((string)$user->user_login) === 'superadmin';
  }

  public static function site_manager_url(string $site_id): string {
    $base = class_exists('EUM_Portal_Shell') ? EUM_Portal_Shell::page_url('site_manager') : home_url('/site-manager/');
    return add_query_arg('eum_site_id', sanitize_key($site_id), $base);
  }

  private static function site_login_launch_url(string $site_id): string {
    $site_id = sanitize_key($site_id);
    return wp_nonce_url(
      add_query_arg(['action' => 'eum_site_manager_launch', 'site_id' => $site_id], admin_url('admin-post.php')),
      'eum_site_manager_launch_' . $site_id
    );
  }

  public static function eligible_users(int $manager_user_id, array $site, string $search = '', array $filters = []): array {
    if (!self::user_can_manage_site($manager_user_id, $site)) return [];

    $is_privileged = class_exists('EUM_Access') && EUM_Access::is_it_or_hr($manager_user_id);
    $can_provision_terminated = $is_privileged || self::is_superadmin_user($manager_user_id);
    if ($is_privileged) {
      $users = get_users(['fields' => 'all', 'number' => 0, 'orderby' => 'display_name', 'order' => 'ASC']);
    } elseif (class_exists('EUM_Manager_IDs')) {
      $managed = EUM_Manager_IDs::managed_user_levels_for_manager_user($manager_user_id, true, true);
      $ids = array_values(array_unique(array_filter(array_map('intval', array_keys($managed)), static function ($id) use ($manager_user_id) { return $id > 0 && $id !== $manager_user_id; })));
      if (empty($ids)) return [];
      $users = get_users(['include' => $ids, 'fields' => 'all', 'number' => 5000, 'orderby' => 'display_name', 'order' => 'ASC']);
    } else {
      return [];
    }

    $search = function_exists('mb_strtolower') ? mb_strtolower(trim($search)) : strtolower(trim($search));
    $out = [];
    foreach ((array)$users as $user) {
      if (!($user instanceof WP_User)) continue;
      $user_id = (int)$user->ID;
      $is_terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
      if ($is_terminated && !$can_provision_terminated) continue;
      if (!$is_privileged && !self::user_matches_site_filters($user_id, $site)) continue;
      if (!self::user_matches_provisioner_filters($user_id, $filters)) continue;
      $groups = taxonomy_exists('eum_user_group') ? wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'names']) : [];
      if (is_wp_error($groups)) $groups = [];
      $employee_id = class_exists('EUM_Workforce_Lifecycle')
        ? EUM_Workforce_Lifecycle::employee_id_for_user($user_id)
        : sanitize_text_field((string)get_user_meta($user_id, 'employee_id', true));
      if ($search !== '') {
        $haystack = strtolower((string)$user->display_name . ' ' . (string)$user->user_login . ' ' . (string)$user->user_email . ' ' . self::user_path_label($user_id) . ' ' . $employee_id . ' ' . implode(' ', array_map('strval', (array)$groups)));
        if (function_exists('mb_strtolower')) $haystack = mb_strtolower($haystack);
        if (strpos($haystack, $search) === false) continue;
      }
      $out[] = [
        'ID' => $user_id,
        'name' => (string)($user->display_name !== '' ? $user->display_name : $user->user_login),
        'username' => (string)$user->user_login,
        'email' => (string)$user->user_email,
        'employee_id' => $employee_id,
        'job_path' => self::user_path_label($user_id),
        'groups' => array_values(array_map('strval', (array)$groups)),
        'provisioned' => in_array($user_id, (array)($site['provisioned_user_ids'] ?? []), true),
        'terminated' => $is_terminated,
      ];
    }
    return $out;
  }

  private static function user_matches_provisioner_filters(int $user_id, array $filters): bool {
    $employee_filter = strtolower(trim((string)($filters['employee_id'] ?? '')));
    if ($employee_filter !== '') {
      $employee_id = class_exists('EUM_Workforce_Lifecycle')
        ? EUM_Workforce_Lifecycle::employee_id_for_user($user_id)
        : sanitize_text_field((string)get_user_meta($user_id, 'employee_id', true));
      if (strtolower(trim($employee_id)) !== $employee_filter) return false;
    }

    $requested_user_id = absint($filters['user_id'] ?? 0);
    if ($requested_user_id > 0 && $requested_user_id !== $user_id) return false;

    $path_id = absint($filters['path_id'] ?? 0);
    if ($path_id > 0) {
      $user_paths = taxonomy_exists('eum_job_path') ? wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']) : [];
      if (is_wp_error($user_paths)) return false;
      $matches_path = false;
      foreach ((array)$user_paths as $user_path_id) {
        if ((int)$user_path_id === $path_id || term_is_ancestor_of($path_id, (int)$user_path_id, 'eum_job_path')) {
          $matches_path = true;
          break;
        }
      }
      if (!$matches_path) return false;
    }

    $group_id = absint($filters['group_id'] ?? 0);
    if ($group_id > 0) {
      $user_groups = taxonomy_exists('eum_user_group') ? wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']) : [];
      if (is_wp_error($user_groups) || !in_array($group_id, array_map('intval', (array)$user_groups), true)) return false;
    }
    return true;
  }

  private static function user_matches_site_filters(int $user_id, array $site): bool {
    $allowed_paths = array_map('intval', (array)($site['job_path_ids'] ?? []));
    if (!empty($allowed_paths)) {
      $user_paths = taxonomy_exists('eum_job_path') ? wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']) : [];
      if (is_wp_error($user_paths)) $user_paths = [];
      $matches = false;
      foreach ((array)$user_paths as $user_path) {
        $user_path = (int)$user_path;
        foreach ($allowed_paths as $allowed_path) {
          if ($user_path === (int)$allowed_path || (taxonomy_exists('eum_job_path') && term_is_ancestor_of((int)$allowed_path, $user_path, 'eum_job_path'))) {
            $matches = true;
            break 2;
          }
        }
      }
      if (!$matches) return false;
    }

    $allowed_groups = array_map('intval', (array)($site['group_ids'] ?? []));
    if (!empty($allowed_groups)) {
      $user_groups = taxonomy_exists('eum_user_group') ? wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']) : [];
      if (is_wp_error($user_groups) || empty(array_intersect($allowed_groups, array_map('intval', (array)$user_groups)))) return false;
    }
    return true;
  }

  private static function user_path_label(int $user_id): string {
    if (!taxonomy_exists('eum_job_path')) return '';
    $paths = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($paths) || empty($paths)) return '';
    $labels = [];
    foreach ((array)$paths as $path_id) {
      $path = class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', (int)$path_id, ' / ') : '';
      if ($path === '') {
        $term = get_term((int)$path_id, 'eum_job_path');
        if ($term && !is_wp_error($term)) $path = (string)$term->name;
      }
      if ($path !== '') $labels[] = $path;
    }
    return implode(', ', array_values(array_unique($labels)));
  }

  public function shortcode(): string {
    if (!is_user_logged_in()) return '<div class="eum-site-manager"><p>Please sign in to manage connected sites.</p></div>';
    $site_id = isset($_GET['eum_site_id']) ? sanitize_key((string)wp_unslash($_GET['eum_site_id'])) : '';
    $site = self::get_site($site_id);
    $user_id = get_current_user_id();
    if (!$site || !self::user_can_manage_site($user_id, $site)) {
      return '<div class="eum-site-manager"><p>You do not have access to this site manager.</p></div>';
    }

    $search = isset($_GET['eum_site_q']) ? sanitize_text_field((string)wp_unslash($_GET['eum_site_q'])) : '';
    $path_filter_id = absint($_GET['eum_site_filter_path'] ?? 0);
    $group_filter_id = absint($_GET['eum_site_filter_group'] ?? 0);
    $path_filter_term = $path_filter_id > 0 && taxonomy_exists('eum_job_path') ? get_term($path_filter_id, 'eum_job_path') : null;
    $group_filter_term = $group_filter_id > 0 && taxonomy_exists('eum_user_group') ? get_term($group_filter_id, 'eum_user_group') : null;
    if (!$path_filter_term || is_wp_error($path_filter_term)) $path_filter_id = 0;
    if (!$group_filter_term || is_wp_error($group_filter_term)) $group_filter_id = 0;
    $employee_filter = sanitize_text_field((string)wp_unslash($_GET['eum_site_filter_employee'] ?? ''));
    $user_id_filter = absint($_GET['eum_site_filter_user_id'] ?? 0);
    $filters = ['path_id' => $path_filter_id, 'group_id' => $group_filter_id, 'employee_id' => $employee_filter, 'user_id' => $user_id_filter];
    $users = self::eligible_users($user_id, $site, $search, $filters);
    $is_hr_it = class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id);
    $can_provision_terminated = $is_hr_it || self::is_superadmin_user($user_id);
    $path_terms = taxonomy_exists('eum_job_path') ? get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']) : [];
    $group_terms = taxonomy_exists('eum_user_group') ? get_terms(['taxonomy' => 'eum_user_group', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']) : [];
    if (is_wp_error($path_terms)) $path_terms = [];
    if (is_wp_error($group_terms)) $group_terms = [];
    $return_url = self::site_manager_url($site_id);
    $return_args = ['eum_site_q' => $search, 'eum_site_filter_path' => $path_filter_id, 'eum_site_filter_group' => $group_filter_id, 'eum_site_filter_employee' => $employee_filter, 'eum_site_filter_user_id' => $user_id_filter];
    foreach ($return_args as $key => $value) if ($value === '' || $value === 0) unset($return_args[$key]);
    if (!empty($return_args)) $return_url = add_query_arg($return_args, $return_url);
    ob_start();
    echo '<section class="eum-site-manager" style="max-width:1120px;margin:24px auto;padding:24px;background:#fff;border:1px solid #dcdcde;border-radius:12px;">';
    $launch_url = self::site_login_launch_url($site_id);
    echo '<header style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;"><div><p style="margin:0 0 6px;color:#646970;">Site Manager</p><h1 style="margin:0;">' . esc_html($site['label']) . '</h1><p style="margin:8px 0 0;color:#646970;">Select internal users to provision on this site.' . ($is_hr_it ? ' HR/IT can select any user; path/group rules continue to control automatic provisioning.' : ' Your selection is limited to your manager scope and this site’s path/group rules.') . '</p>';
    if ($can_provision_terminated) echo '<p style="margin:6px 0 0;color:#646970;">Terminated users may be provisioned, including non-rehirable users; their connected-site accounts remain inactive.</p>';
    echo '</div><a class="button button-primary" href="' . esc_url($launch_url) . '" target="_blank" rel="noopener noreferrer">Open site with internal sign-in</a></header>';

    if (!empty($_GET['eum_site_msg'])) {
      $message = sanitize_text_field((string)wp_unslash($_GET['eum_site_msg']));
      $type = sanitize_key((string)($_GET['eum_site_type'] ?? 'success'));
      echo '<div role="status" style="margin:18px 0;padding:12px 14px;border-left:4px solid ' . ($type === 'error' ? '#d63638' : '#00a32a') . ';background:#f6f7f7;">' . esc_html($message) . '</div>';
    }

    echo '<form method="get" action="' . esc_url(EUM_Portal_Shell::page_url('site_manager')) . '" style="display:flex;gap:8px;margin:20px 0 12px;flex-wrap:wrap;align-items:end;">';
    echo '<input type="hidden" name="eum_site_id" value="' . esc_attr($site_id) . '"><label>Search users<br><input id="eum-site-manager-search" type="search" name="eum_site_q" value="' . esc_attr($search) . '" placeholder="Name, username, email, Employee ID, path, or group" style="min-width:260px;"></label>';
    echo '<label>Job path<br><select name="eum_site_filter_path"><option value="0">Any path</option>';
    foreach ((array)$path_terms as $term) {
      $term_id = (int)$term->term_id;
      $path_label = class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ') : (string)$term->name;
      if ($path_label === '') $path_label = (string)$term->name;
      echo '<option value="' . $term_id . '" ' . selected($path_filter_id, $term_id, false) . '>' . esc_html($path_label) . '</option>';
    }
    echo '</select></label><label>User group<br><select name="eum_site_filter_group"><option value="0">Any group</option>';
    foreach ((array)$group_terms as $term) echo '<option value="' . (int)$term->term_id . '" ' . selected($group_filter_id, (int)$term->term_id, false) . '>' . esc_html((string)$term->name) . '</option>';
    echo '</select></label><label>Employee ID<br><input type="search" name="eum_site_filter_employee" value="' . esc_attr($employee_filter) . '" placeholder="Exact employee ID"></label><label>WordPress user ID<br><input type="number" min="1" name="eum_site_filter_user_id" value="' . ($user_id_filter > 0 ? (string)$user_id_filter : '') . '" placeholder="Exact user ID" style="width:130px;"></label><button class="button">Filter users</button>';
    if ($search !== '' || $path_filter_id > 0 || $group_filter_id > 0 || $employee_filter !== '' || $user_id_filter > 0) echo '<a class="button" href="' . esc_url(self::site_manager_url($site_id)) . '">Clear filters</a>';
    echo '</form><p class="description" style="margin-top:0;">Filters combine to narrow the list. Selecting a job path includes its descendant paths. Employee ID and WordPress user ID are exact matches.</p>';

    if (empty($users)) {
      echo '<p>No users match the current filters and provisioning access.</p>';
    } else {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_site_manager_provision"><input type="hidden" name="site_id" value="' . esc_attr($site_id) . '"><input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
      wp_nonce_field('eum_site_manager_provision_' . $site_id, 'eum_site_manager_nonce');
      echo '<div style="overflow:auto;border:1px solid #dcdcde;border-radius:8px;"><table class="widefat striped" style="border:0;margin:0;"><thead><tr><th style="width:36px;"><input type="checkbox" aria-label="Select all eligible users" data-eum-select-all></th><th>Name</th><th>Employee ID</th><th>WordPress ID</th><th>Email</th><th>Job Path</th><th>Groups</th><th>Status</th></tr></thead><tbody>';
      foreach ($users as $user) {
        $status_html = !empty($user['terminated']) ? '<span style="color:#996800;">Terminated · will remain inactive</span><br>' : '';
        $status_html .= !empty($user['provisioned']) ? '<span style="color:#007017;">Already provisioned</span>' : 'Not provisioned';
        $avatar = class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html((int)$user['ID'], 32, 'eum-directory__avatar') : '';
        echo '<tr><td><input type="checkbox" name="user_ids[]" value="' . (int)$user['ID'] . '" aria-label="Select ' . esc_attr($user['name']) . '"></td><td><span class="eum-directory__person">' . $avatar . '<span><strong>' . esc_html($user['name']) . '</strong><br><small>' . esc_html($user['username']) . '</small></span></span></td><td>' . esc_html($user['employee_id'] !== '' ? $user['employee_id'] : '—') . '</td><td>' . (int)$user['ID'] . '</td><td>' . esc_html($user['email'] !== '' ? $user['email'] : 'No email') . '</td><td>' . esc_html($user['job_path'] !== '' ? $user['job_path'] : '—') . '</td><td>' . esc_html(!empty($user['groups']) ? implode(', ', $user['groups']) : '—') . '</td><td>' . $status_html . '</td></tr>';
      }
      echo '</tbody></table></div>';
      echo '<p style="margin:14px 0 6px;"><label for="eum-site-provision-reason"><strong>Reason for provisioning</strong></label></p><textarea id="eum-site-provision-reason" name="reason" rows="2" required maxlength="500" style="width:100%;max-width:760px;" placeholder="Why do these users need access to this site?"></textarea>';
      echo '<p style="margin:12px 0 4px;"><label><input type="checkbox" name="show_app_launcher" value="1" checked> Add a direct sign-in shortcut for this site to the selected users’ app bars</label></p><p class="description">Uncheck to provision accounts without adding a direct sign-in shortcut. Manager/Admin shortcuts remain governed by Site Manager permissions. This choice is available when provisioning users from a path-filtered list too.</p>';
      echo '<p class="description">You can provision up to 500 users at a time.</p><p><button type="submit" class="button button-primary">Provision selected users</button></p></form>';
      echo '<script>(function(){var all=document.querySelector("[data-eum-select-all]");if(!all)return;all.addEventListener("change",function(){document.querySelectorAll(".eum-site-manager input[name=\"user_ids[]\"]").forEach(function(box){box.checked=all.checked;});});}());</script>';
    }
    echo '</section>';
    return ob_get_clean();
  }

  public function handle_provision(): void {
    if (!is_user_logged_in()) wp_die('Please sign in.');
    $site_id = isset($_POST['site_id']) ? sanitize_key((string)wp_unslash($_POST['site_id'])) : '';
    $site = self::get_site($site_id);
    $manager_user_id = get_current_user_id();
    if (!$site || !self::user_can_manage_site($manager_user_id, $site)) wp_die('Not allowed.');
    if (!isset($_POST['eum_site_manager_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_site_manager_nonce']), 'eum_site_manager_provision_' . $site_id)) wp_die('Invalid request.');

    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)wp_unslash($_POST['return_url'])) : self::site_manager_url($site_id);
    if (wp_parse_url($return_url, PHP_URL_HOST) !== wp_parse_url(home_url('/'), PHP_URL_HOST)) $return_url = self::site_manager_url($site_id);
    $reason = sanitize_textarea_field((string)wp_unslash($_POST['reason'] ?? ''));
    if ($reason === '') $this->provision_redirect($return_url, 'A reason is required before provisioning.', 'error');

    $selected_ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])), static function ($id) { return $id > 0; })));
    if (empty($selected_ids)) $this->provision_redirect($return_url, 'Select at least one user to provision.', 'error');
    if (count($selected_ids) > 500) $this->provision_redirect($return_url, 'Select no more than 500 users at a time.', 'error');
    $eligible = self::eligible_users($manager_user_id, $site);
    $eligible_by_id = [];
    foreach ($eligible as $user) $eligible_by_id[(int)$user['ID']] = $user;
    foreach ($selected_ids as $selected_id) {
      if (!isset($eligible_by_id[$selected_id])) $this->provision_redirect($return_url, 'One or more selected users are outside your access scope.', 'error');
    }

    $payload_users = [];
    foreach ($selected_ids as $selected_id) {
      $user = get_user_by('id', $selected_id);
      if (!$user instanceof WP_User) continue;
      $payload_users[] = self::user_provision_payload($user, $site);
    }
    if (count($payload_users) !== count($selected_ids)) $this->provision_redirect($return_url, 'A selected account could not be loaded.', 'error');

    $response = wp_remote_post($site['provision_url'], [
      'timeout' => 25,
      'redirection' => 0,
      'sslverify' => true,
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-EUM-Site-Key' => $site['site_key'],
      ],
      'body' => wp_json_encode([
        'users' => $payload_users,
        'reason' => $reason,
        'manager_user_id' => $manager_user_id,
        'manager_sub' => class_exists('EUM_OIDC_Provider') && get_user_by('id', $manager_user_id) instanceof WP_User
          ? EUM_OIDC_Provider::subject_identifier_for_user(get_user_by('id', $manager_user_id))
          : '',
      ]),
    ]);
    if (is_wp_error($response)) $this->provision_redirect($return_url, 'Could not contact the connected site: ' . $response->get_error_message(), 'error');

    $http_code = (int)wp_remote_retrieve_response_code($response);
    $body = json_decode((string)wp_remote_retrieve_body($response), true);
    if ($http_code < 200 || $http_code >= 300 || !is_array($body) || !isset($body['results']) || !is_array($body['results'])) {
      $detail = is_array($body) ? sanitize_text_field((string)($body['message'] ?? $body['code'] ?? '')) : '';
      $this->provision_redirect($return_url, $detail !== '' ? 'Connected site rejected provisioning: ' . $detail : 'Connected site returned an unexpected response.', 'error');
    }

    $created = 0;
    $linked = 0;
    $updated = 0;
    $failed = 0;
    $provisioned_ids = (array)$site['provisioned_user_ids'];
    $launcher_disabled_ids = (array)$site['app_launcher_disabled_user_ids'];
    $auto_provisioned_ids = (array)$site['auto_provisioned_user_ids'];
    $manual_scope_override_ids = (array)$site['manual_scope_override_user_ids'];
    $is_hr_it = class_exists('EUM_Access') && EUM_Access::is_it_or_hr($manager_user_id);
    $show_app_launcher = !empty($_POST['show_app_launcher']);
    $successful_selected_ids = [];
    foreach ($body['results'] as $result) {
      if (!is_array($result)) continue;
      $central_user_id = (int)($result['central_user_id'] ?? 0);
      $status = sanitize_key((string)($result['status'] ?? 'failed'));
      if ($status === 'created') $created++;
      elseif ($status === 'linked') $linked++;
      elseif ($status === 'updated') $updated++;
      else $failed++;
      if ($central_user_id > 0 && in_array($status, ['created', 'linked', 'updated'], true)) {
        $provisioned_ids[] = $central_user_id;
        if (in_array($central_user_id, $selected_ids, true)) $successful_selected_ids[] = $central_user_id;
      }
    }
    $sites = self::get_sites();
    $sites[$site_id]['provisioned_user_ids'] = array_values(array_unique(array_map('intval', $provisioned_ids)));
    if (self::site_has_provision_scope($site)) {
      foreach ($successful_selected_ids as $successful_id) {
        if (self::user_matches_site_filters((int)$successful_id, $site)) {
          $auto_provisioned_ids[] = (int)$successful_id;
          $manual_scope_override_ids = array_values(array_diff(array_map('intval', $manual_scope_override_ids), [(int)$successful_id]));
        } elseif ($is_hr_it) {
          $manual_scope_override_ids[] = (int)$successful_id;
          $auto_provisioned_ids = array_values(array_diff(array_map('intval', $auto_provisioned_ids), [(int)$successful_id]));
        }
      }
    } elseif ($is_hr_it) {
      $manual_scope_override_ids = array_merge($manual_scope_override_ids, $successful_selected_ids);
    }
    $sites[$site_id]['auto_provisioned_user_ids'] = array_values(array_unique(array_map('intval', $auto_provisioned_ids)));
    $sites[$site_id]['manual_scope_override_user_ids'] = array_values(array_unique(array_map('intval', $manual_scope_override_ids)));
    if ($show_app_launcher) {
      $sites[$site_id]['app_launcher_disabled_user_ids'] = array_values(array_diff(
        array_map('intval', $launcher_disabled_ids),
        $successful_selected_ids
      ));
    } else {
      $sites[$site_id]['app_launcher_disabled_user_ids'] = array_values(array_unique(array_merge(
        array_map('intval', $launcher_disabled_ids),
        $successful_selected_ids
      )));
    }
    update_option(self::OPTION_SITES, $sites, false);
    self::record_audit($site_id, $manager_user_id, $reason, $body['results']);

    $summary = sprintf('Provisioning finished: %d created, %d linked by email, %d updated, %d failed.', $created, $linked, $updated, $failed);
    $this->provision_redirect($return_url, $summary, $failed > 0 ? 'error' : 'success');
  }

  private function provision_redirect(string $url, string $message, string $type): void {
    wp_safe_redirect(add_query_arg(['eum_site_msg' => $message, 'eum_site_type' => $type], $url));
    exit;
  }

  public function queue_user_sync_from_meta($meta_ids, $user_id, $meta_key, $meta_value): void {
    $meta_key = sanitize_key((string)$meta_key);
    $syncable_keys = [
      'first_name', 'last_name', 'nickname', 'description', 'locale',
      'eum_text_fields', 'eum_manager_user_id', 'job_company',
      'eum_it_access', 'eum_hr_access', 'eum_hr_it_access',
      'eum_terminated', 'eum_rehirable', 'eum_terminated_at',
      '_eum_profile_image_url', '_eum_profile_image_id', '_eum_profile_image_color',
    ];
    $is_role_key = preg_match('/(?:^|_)capabilities$/', $meta_key) === 1 || preg_match('/(?:^|_)user_level$/', $meta_key) === 1;
    $syncable_keys = apply_filters('eum_site_manager_syncable_user_meta_keys', $syncable_keys, (int)$user_id);
    if (!$is_role_key && !in_array($meta_key, array_map('sanitize_key', (array)$syncable_keys), true)) return;
    $this->queue_user_sync((int)$user_id);
  }

  public function queue_user_sync_from_terms($object_id, $terms, $term_taxonomy_ids, $taxonomy, $append, $old_term_taxonomy_ids): void {
    $taxonomy = sanitize_key((string)$taxonomy);
    if (!in_array($taxonomy, ['eum_user_group', 'eum_job_path', 'eum_location', 'eum_project'], true)) return;
    $user_id = (int)$object_id;
    if ($user_id > 0 && get_user_by('id', $user_id)) $this->queue_user_sync($user_id);
  }

  public function queue_user_sync(int $user_id, $old_user_data = null): void {
    // Keep provisioned users synced and pick up active members entering a configured site scope.
    if ($user_id <= 0 || !get_user_by('id', $user_id)) return;
    $should_queue = !empty(self::sites_user_provisioned_to($user_id));
    if (!$should_queue) {
      foreach (self::get_sites() as $site) {
        if (self::site_should_auto_provision_user($user_id, $site)) { $should_queue = true; break; }
      }
    }
    if (!$should_queue) return;
    $this->queue_user_sync_batch([$user_id]);
  }

  private function queue_user_sync_batch(array $user_ids): void {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static function ($id) { return $id > 0; })));
    if (empty($user_ids)) return;
    $queue = get_option(self::OPTION_SYNC_QUEUE, []);
    if (!is_array($queue)) $queue = [];
    foreach ($user_ids as $user_id) {
      $queue[$user_id] = ['queued_at' => microtime(true), 'revision' => wp_generate_uuid4(), 'attempts' => 0];
    }
    update_option(self::OPTION_SYNC_QUEUE, $queue, false);
    $this->schedule_user_sync(10, true);
  }

  public function queue_deleted_user_deprovision($user_id, $reassign = null, $user = null): void {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return;
    foreach (self::sites_user_provisioned_to($user_id) as $site_id) {
      $this->queue_site_user_deprovision((string)$site_id, $user_id, 'The internal user account was deleted.');
    }
  }

  private static function site_has_provision_scope(array $site): bool {
    return !empty($site['job_path_ids']) || !empty($site['group_ids']);
  }

  private static function site_should_auto_provision_user(int $user_id, array $site): bool {
    if (!self::site_has_provision_scope($site) || !get_user_by('id', $user_id) || !self::user_matches_site_filters($user_id, $site)) return false;
    return !(class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id));
  }

  private function queue_site_reconciliation(string $site_id): void {
    $site = self::get_site($site_id);
    if (!$site) return;
    $user_ids = array_map('intval', (array)$site['provisioned_user_ids']);
    if (self::site_has_provision_scope($site)) {
      $user_ids = array_merge($user_ids, array_map('intval', (array)get_users(['fields' => 'ids', 'number' => -1])));
    }
    $this->queue_user_sync_batch($user_ids);
  }

  public function maybe_queue_path_membership_backfill(): void {
    if (get_option(self::OPTION_PATH_MEMBERSHIP_BACKFILL, false)) return;
    $sites = self::get_sites();
    $all_user_ids = [];
    $changed = false;
    foreach ($sites as $site_id => $site) {
      $provisioned_ids = array_map('intval', (array)$site['provisioned_user_ids']);
      $manager_ids = array_map('intval', (array)$site['manager_provisioned_user_ids']);
      foreach (array_map('intval', (array)$site['manager_user_ids']) as $manager_id) {
        if (in_array($manager_id, $provisioned_ids, true)) $manager_ids[] = $manager_id;
      }
      foreach ($provisioned_ids as $provisioned_id) {
        if (self::user_can_manage_site($provisioned_id, $site)) $manager_ids[] = $provisioned_id;
      }
      $sites[$site_id]['manager_provisioned_user_ids'] = array_values(array_unique($manager_ids));
      if (self::site_has_provision_scope($site)) {
        // Existing connected accounts become governed by the configured path/group scope.
        $sites[$site_id]['auto_provisioned_user_ids'] = array_values(array_unique(array_merge(
          array_map('intval', (array)$site['auto_provisioned_user_ids']),
          $provisioned_ids
        )));
        $all_user_ids = array_merge($all_user_ids, $provisioned_ids, array_map('intval', (array)get_users(['fields' => 'ids', 'number' => -1])));
      }
      if ($sites[$site_id]['manager_provisioned_user_ids'] !== $site['manager_provisioned_user_ids']
        || $sites[$site_id]['auto_provisioned_user_ids'] !== $site['auto_provisioned_user_ids']) $changed = true;
    }
    if ($changed) update_option(self::OPTION_SITES, $sites, false);
    $all_user_ids = array_values(array_unique(array_filter(array_map('intval', $all_user_ids), static function ($id) { return $id > 0; })));
    if (empty($all_user_ids)) {
      update_option(self::OPTION_PATH_MEMBERSHIP_BACKFILL, time(), false);
      return;
    }
    $this->queue_user_sync_batch($all_user_ids);
    if (wp_next_scheduled(self::USER_SYNC_HOOK)) update_option(self::OPTION_PATH_MEMBERSHIP_BACKFILL, time(), false);
  }

  private function queue_site_user_deprovision(string $site_id, int $user_id, string $reason, bool $cancel_if_eligible = false): void {
    $site_id = sanitize_key($site_id);
    if ($site_id === '' || $user_id <= 0) return;
    $queue = get_option(self::OPTION_DEPROVISION_QUEUE, []);
    if (!is_array($queue)) $queue = [];
    $existing = is_array($queue[$site_id][$user_id] ?? null) ? $queue[$site_id][$user_id] : [];
    $queue[$site_id][$user_id] = [
      'queued_at' => microtime(true),
      'attempts' => (int)($existing['attempts'] ?? 0),
      'reason' => sanitize_textarea_field($reason),
      'cancel_if_eligible' => $cancel_if_eligible,
    ];
    update_option(self::OPTION_DEPROVISION_QUEUE, $queue, false);
    $this->schedule_user_sync(10, true);
  }

  public function maybe_queue_terminated_user_backfill(): void {
    if (get_option(self::OPTION_TERMINATED_BACKFILL, false) || !class_exists('EUM_Personnel')) return;

    $terminated_ids = [];
    foreach (self::get_sites() as $site) {
      foreach ((array)$site['provisioned_user_ids'] as $user_id) {
        $user_id = (int)$user_id;
        if ($user_id > 0 && EUM_Personnel::is_terminated($user_id) && get_user_by('id', $user_id)) {
          $terminated_ids[$user_id] = true;
        }
      }
    }

    $this->queue_user_sync_backfill(array_keys($terminated_ids), self::OPTION_TERMINATED_BACKFILL);
  }

  public function maybe_queue_placeholder_avatar_backfill(): void {
    if (get_option(self::OPTION_JPEG_AVATAR_BACKFILL, false) || !class_exists('EUM_Profiles')) return;

    $placeholder_ids = [];
    foreach (self::get_sites() as $site) {
      foreach ((array)$site['provisioned_user_ids'] as $user_id) {
        $user_id = (int)$user_id;
        if ($user_id > 0 && get_user_by('id', $user_id) && EUM_Profiles::profile_image_url($user_id) === '') {
          $placeholder_ids[$user_id] = true;
        }
      }
    }

    $this->queue_user_sync_backfill(array_keys($placeholder_ids), self::OPTION_JPEG_AVATAR_BACKFILL);
  }

  private function queue_user_sync_backfill(array $user_ids, string $completed_option): void {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static function ($user_id) { return $user_id > 0; })));
    if (empty($user_ids)) {
      update_option($completed_option, time(), false);
      return;
    }

    $queue = get_option(self::OPTION_SYNC_QUEUE, []);
    if (!is_array($queue)) $queue = [];
    foreach ($user_ids as $user_id) {
      if (isset($queue[$user_id])) continue;
      $queue[$user_id] = [
        'queued_at' => microtime(true),
        'revision' => wp_generate_uuid4(),
        'attempts' => 0,
      ];
    }
    update_option(self::OPTION_SYNC_QUEUE, $queue, false);
    $this->schedule_user_sync(10, true);

    // Keep the migration eligible for another request if WP-Cron could not be scheduled.
    if (wp_next_scheduled(self::USER_SYNC_HOOK)) {
      update_option($completed_option, time(), false);
    }
  }

  private function schedule_user_sync(int $delay, bool $expedite = false): void {
    $next = wp_next_scheduled(self::USER_SYNC_HOOK);
    if ($next && (!$expedite || $next <= time() + max(1, $delay))) return;
    if ($next) wp_unschedule_event($next, self::USER_SYNC_HOOK);
    wp_schedule_single_event(time() + max(1, $delay), self::USER_SYNC_HOOK);
  }

  private static function sites_user_provisioned_to(int $user_id): array {
    $site_ids = [];
    foreach (self::get_sites() as $site_id => $site) {
      if (in_array($user_id, (array)$site['provisioned_user_ids'], true)) $site_ids[] = (string)$site_id;
    }
    return $site_ids;
  }

  public function process_user_sync_queue(): void {
    $queue = get_option(self::OPTION_SYNC_QUEUE, []);
    if (!is_array($queue)) $queue = [];
    $deprovision_queue = get_option(self::OPTION_DEPROVISION_QUEUE, []);
    if (!is_array($deprovision_queue)) $deprovision_queue = [];
    if (empty($queue) && empty($deprovision_queue)) return;
    if (!wp_next_scheduled(self::USER_SYNC_HOOK)) wp_schedule_single_event(time() + 300, self::USER_SYNC_HOOK);
    $sites = self::get_sites();
    $payloads_by_site = [];
    foreach (array_keys($queue) as $raw_user_id) {
      $user_id = (int)$raw_user_id;
      $user = get_user_by('id', $user_id);
      if (!$user instanceof WP_User) continue;
      $is_terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
      foreach ($sites as $site_id => $site) {
        $is_provisioned = in_array($user_id, (array)$site['provisioned_user_ids'], true);
        $has_scope = self::site_has_provision_scope($site);
        $matches_scope = $has_scope && self::user_matches_site_filters($user_id, $site);
        $can_manage = self::user_can_manage_site($user_id, $site);
        $manager_source = in_array($user_id, (array)$site['manager_provisioned_user_ids'], true);
        $auto_source = in_array($user_id, (array)$site['auto_provisioned_user_ids'], true);
        $manual_scope_override = in_array($user_id, (array)$site['manual_scope_override_user_ids'], true);

        if ($is_provisioned && !$manual_scope_override && (($has_scope && !$matches_scope && !$can_manage) || (!$has_scope && $auto_source && !$can_manage) || (!$has_scope && $manager_source && !$can_manage && !$is_terminated))) {
          $deprovision_reason = $has_scope
            ? 'User no longer matches this connected site’s configured path/group scope.'
            : ($auto_source ? 'The connected site’s path/group provisioning scope was removed.' : 'Site Manager access was removed.');
          $this->queue_site_user_deprovision(
            (string)$site_id,
            $user_id,
            $deprovision_reason,
            $has_scope || $manager_source
          );
          continue;
        }
        if ($is_provisioned) {
          $payloads_by_site[$site_id][$user_id] = self::user_provision_payload($user, $site);
        } elseif (!$is_terminated && $matches_scope) {
          // Active users entering a provisioned path/group are provisioned automatically.
          $payloads_by_site[$site_id][$user_id] = self::user_provision_payload($user, $site);
        }
      }
    }

    $failed_user_ids = [];
    $success_ids_by_site = [];
    foreach ($payloads_by_site as $site_id => $user_payloads) {
      $site = $sites[$site_id];
      foreach (array_chunk(array_values($user_payloads), 50) as $batch) {
        $response = wp_remote_post($site['provision_url'], [
          'timeout' => 25,
          'redirection' => 0,
          'sslverify' => true,
          'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-EUM-Site-Key' => $site['site_key'],
          ],
          'body' => wp_json_encode([
            'users' => $batch,
            'reason' => 'Automatic user data sync from the internal site',
            'manager_user_id' => 0,
            'manager_sub' => '',
          ]),
        ]);

        $batch_ids = array_map(static function ($user_data) { return (int)($user_data['central_user_id'] ?? 0); }, $batch);
        if (is_wp_error($response)) {
          foreach ($batch_ids as $user_id) if ($user_id > 0) $failed_user_ids[$user_id] = $response->get_error_message();
          continue;
        }

        $http_code = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($http_code < 200 || $http_code >= 300 || !is_array($body) || !is_array($body['results'] ?? null)) {
          $detail = is_array($body) ? sanitize_text_field((string)($body['message'] ?? $body['code'] ?? '')) : '';
          foreach ($batch_ids as $user_id) if ($user_id > 0) $failed_user_ids[$user_id] = $detail !== '' ? $detail : 'The connected site returned an unexpected response.';
          continue;
        }

        $successful_ids = [];
        foreach ($body['results'] as $result) {
          if (!is_array($result)) continue;
          $central_user_id = (int)($result['central_user_id'] ?? 0);
          $status = sanitize_key((string)($result['status'] ?? 'failed'));
          if ($central_user_id > 0 && in_array($status, ['created', 'linked', 'updated'], true)) {
            $successful_ids[$central_user_id] = true;
            $success_ids_by_site[$site_id][$central_user_id] = true;
          } else if ($central_user_id > 0) {
            $failed_user_ids[$central_user_id] = sanitize_text_field((string)($result['message'] ?? 'The connected site could not update this account.'));
          }
        }
        foreach ($batch_ids as $user_id) {
          if ($user_id > 0 && !isset($successful_ids[$user_id]) && !isset($failed_user_ids[$user_id])) {
            $failed_user_ids[$user_id] = 'The connected site did not return an update result for this account.';
          }
        }
      }
    }

    foreach ($success_ids_by_site as $site_id => $successful_ids) {
      $latest_sites = self::get_sites();
      if (!isset($latest_sites[$site_id])) continue;
      $latest = $latest_sites[$site_id];
      $provisioned_ids = array_map('intval', (array)$latest['provisioned_user_ids']);
      $auto_ids = array_map('intval', (array)$latest['auto_provisioned_user_ids']);
      $manual_override_ids = array_map('intval', (array)$latest['manual_scope_override_user_ids']);
      foreach (array_keys($successful_ids) as $user_id) {
        $user_id = (int)$user_id;
        $was_provisioned = in_array($user_id, $provisioned_ids, true);
        $provisioned_ids[] = $user_id;
        if (self::site_has_provision_scope($latest) && self::user_matches_site_filters($user_id, $latest)) {
          $auto_ids[] = $user_id;
          $manual_override_ids = array_values(array_diff($manual_override_ids, [$user_id]));
          if (!$was_provisioned && empty($latest['path_app_launcher_enabled'])) {
            $latest_sites[$site_id]['app_launcher_disabled_user_ids'][] = $user_id;
          }
        }
      }
      $latest_sites[$site_id]['provisioned_user_ids'] = array_values(array_unique($provisioned_ids));
      $latest_sites[$site_id]['auto_provisioned_user_ids'] = array_values(array_unique($auto_ids));
      $latest_sites[$site_id]['manual_scope_override_user_ids'] = array_values(array_unique($manual_override_ids));
      $latest_sites[$site_id]['app_launcher_disabled_user_ids'] = array_values(array_unique(array_map('intval', (array)$latest_sites[$site_id]['app_launcher_disabled_user_ids'])));
      update_option(self::OPTION_SITES, $latest_sites, false);
    }

    // Deprovisioning is separately queued so a temporary destination outage never
    // turns a path removal into a forgotten, still-active remote account.
    $deprovision_queue = get_option(self::OPTION_DEPROVISION_QUEUE, []);
    if (!is_array($deprovision_queue)) $deprovision_queue = [];
    $failed_deprovisionals = [];
    foreach ($deprovision_queue as $site_id => $entries) {
      $site_id = sanitize_key((string)$site_id);
      if (!isset($sites[$site_id]) || !is_array($entries)) continue;
      $site = self::get_site($site_id);
      if (!$site) continue;
      $ids_to_remove = [];
      foreach ($entries as $raw_user_id => $entry) {
        $user_id = (int)$raw_user_id;
        if ($user_id <= 0) continue;
        $entry = is_array($entry) ? $entry : [];
        if (!empty($entry['cancel_if_eligible'])) {
          $user = get_user_by('id', $user_id);
          $manual_override = in_array($user_id, (array)$site['manual_scope_override_user_ids'], true);
          if ($user instanceof WP_User && ($manual_override || self::site_should_auto_provision_user($user_id, $site) || self::user_can_manage_site($user_id, $site))) {
            unset($deprovision_queue[$site_id][$raw_user_id]);
            $this->queue_user_sync_batch([$user_id]);
            continue;
          }
        }
        $ids_to_remove[$user_id] = $entry;
      }
      if (empty($ids_to_remove)) continue;
      $reason = 'Remove users no longer included in this connected site’s provisioning.';
      foreach ($ids_to_remove as $entry) {
        if (!empty($entry['reason'])) { $reason = sanitize_textarea_field((string)$entry['reason']); break; }
      }
      $result = $this->deprovision_site_user_batch($site, array_keys($ids_to_remove), $reason);
      foreach ($result['success_ids'] as $removed_id) {
        unset($deprovision_queue[$site_id][$removed_id]);
        $latest_sites = self::get_sites();
        if (isset($latest_sites[$site_id])) {
          foreach (['provisioned_user_ids', 'auto_provisioned_user_ids', 'manual_scope_override_user_ids', 'manager_provisioned_user_ids', 'app_launcher_disabled_user_ids'] as $key) {
            $latest_sites[$site_id][$key] = array_values(array_diff(array_map('intval', (array)$latest_sites[$site_id][$key]), [(int)$removed_id]));
          }
          update_option(self::OPTION_SITES, $latest_sites, false);
        }
      }
      foreach ($result['failures'] as $failed_id => $message) {
        $previous = $ids_to_remove[$failed_id] ?? [];
        $attempts = (int)($previous['attempts'] ?? 0) + 1;
        if ($attempts >= 5) eum_log_message('[EUM Site Manager] Automatic deprovision failed for site ' . $site_id . ', user ' . (int)$failed_id . ': ' . sanitize_text_field((string)$message));
        $failed_deprovisionals[$site_id][(int)$failed_id] = array_merge($previous, [
          'queued_at' => microtime(true),
          'attempts' => min(5, $attempts),
        ]);
      }
      if (isset($deprovision_queue[$site_id]) && empty($deprovision_queue[$site_id])) unset($deprovision_queue[$site_id]);
    }
    foreach ($failed_deprovisionals as $site_id => $entries) {
      if (!isset($deprovision_queue[$site_id]) || !is_array($deprovision_queue[$site_id])) $deprovision_queue[$site_id] = [];
      $deprovision_queue[$site_id] = array_merge($deprovision_queue[$site_id], $entries);
    }

    $pending = get_option(self::OPTION_SYNC_QUEUE, []);
    if (!is_array($pending)) $pending = [];
    foreach ($queue as $user_id => $original_entry) {
      $current_entry = $pending[$user_id] ?? null;
      $original_revision = is_array($original_entry) ? (string)($original_entry['revision'] ?? '') : '';
      $current_revision = is_array($current_entry) ? (string)($current_entry['revision'] ?? '') : '';
      if ($current_revision !== '' && $current_revision !== $original_revision) continue;
      unset($pending[$user_id]);
    }
    foreach ($failed_user_ids as $user_id => $message) {
      if (isset($pending[$user_id])) continue;
      $previous = is_array($queue[$user_id] ?? null) ? $queue[$user_id] : [];
      $attempts = (int)($previous['attempts'] ?? 0) + 1;
      if ($attempts >= 5) {
        eum_log_message('[EUM Site Manager] Automatic sync failed after 5 attempts for user ' . (int)$user_id . ': ' . sanitize_text_field((string)$message));
        continue;
      }
      $pending[$user_id] = [
        'queued_at' => microtime(true),
        'revision' => (string)($previous['revision'] ?? wp_generate_uuid4()),
        'attempts' => $attempts,
      ];
    }

    if (!empty($pending)) {
      update_option(self::OPTION_SYNC_QUEUE, $pending, false);
      $has_new_updates = false;
      foreach ($pending as $entry) {
        if ((int)($entry['attempts'] ?? 0) === 0) { $has_new_updates = true; break; }
      }
      $this->schedule_user_sync($has_new_updates ? 10 : 300, $has_new_updates);
    } else {
      delete_option(self::OPTION_SYNC_QUEUE);
    }
    if (!empty($deprovision_queue)) {
      update_option(self::OPTION_DEPROVISION_QUEUE, $deprovision_queue, false);
      $this->schedule_user_sync(300);
    } else {
      delete_option(self::OPTION_DEPROVISION_QUEUE);
    }
  }

  private static function public_profile_snapshot(int $user_id): array {
    if (!class_exists('EUM_Profiles')) return [];
    $settings = EUM_Profiles::get_settings();
    $catalog = EUM_Profiles::field_catalog();
    $snapshot = [];
    foreach ((array)($settings['public_fields'] ?? []) as $field_id) {
      $field_id = (string)$field_id;
      if ($field_id === 'core:user_pass' || !isset($catalog[$field_id])) continue;
      $value = EUM_Profiles::get_field_display_value($user_id, $field_id);
      if (!is_scalar($value)) continue;
      $value = sanitize_textarea_field((string)$value);
      if (function_exists('mb_substr')) $value = mb_substr($value, 0, 2000);
      else $value = substr($value, 0, 2000);
      if ($value !== '') $snapshot[$field_id] = $value;
    }
    foreach (['eum_user_group', 'eum_job_path', 'eum_location', 'eum_project'] as $taxonomy) {
      if (!taxonomy_exists($taxonomy)) continue;
      $terms = wp_get_object_terms($user_id, $taxonomy, ['fields' => 'ids']);
      if (is_wp_error($terms) || empty($terms)) continue;
      $labels = [];
      foreach ((array)$terms as $term_id) {
        $term_id = (int)$term_id;
        if (in_array($taxonomy, ['eum_job_path', 'eum_location'], true) && class_exists('EUM_Profiles')) {
          $label = EUM_Profiles::term_full_path($taxonomy, $term_id, ' / ');
        } else {
          $term = get_term($term_id, $taxonomy);
          $label = $term && !is_wp_error($term) ? (string)$term->name : '';
        }
        if ($label !== '') $labels[] = sanitize_text_field($label);
      }
      if (!empty($labels)) $snapshot['taxonomy:' . $taxonomy] = implode(', ', array_values(array_unique($labels)));
    }
    return $snapshot;
  }

  private static function user_provision_payload(WP_User $user, array $site): array {
    $user_id = (int)$user->ID;
    $is_terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
    $is_auto_disable_exempt = in_array(strtolower((string)$user->user_login), self::AUTO_DISABLE_EXEMPT_LOGINS, true);
    return [
      'central_user_id' => $user_id,
      'central_issuer' => class_exists('EUM_OIDC_Provider') ? EUM_OIDC_Provider::issuer() : home_url('/'),
      'central_sub' => class_exists('EUM_OIDC_Provider') ? EUM_OIDC_Provider::subject_identifier_for_user($user) : ('eum-user-id-' . $user_id),
      'user_login' => (string)$user->user_login,
      'user_email' => sanitize_email((string)$user->user_email),
      'display_name' => sanitize_text_field((string)$user->display_name),
      'first_name' => sanitize_text_field((string)get_user_meta($user_id, 'first_name', true)),
      'last_name' => sanitize_text_field((string)get_user_meta($user_id, 'last_name', true)),
      'nickname' => sanitize_text_field((string)get_user_meta($user_id, 'nickname', true)),
      'description' => sanitize_textarea_field((string)get_user_meta($user_id, 'description', true)),
      'user_url' => esc_url_raw((string)$user->user_url),
      'account_status' => $is_terminated && !$is_auto_disable_exempt ? 'inactive' : 'active',
      'profile_image_url' => class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_url($user_id) : '',
      'profile_data' => self::public_profile_snapshot($user_id),
      'site_role' => self::site_role_for_user($user_id, $site),
    ];
  }

  private static function site_role_for_user(int $user_id, array $site): string {
    $user = get_user_by('id', $user_id);
    $role_map = (array)($site['site_role_map'] ?? []);
    if ($user instanceof WP_User) {
      foreach ((array)$user->roles as $internal_role) {
        $internal_role = sanitize_key((string)$internal_role);
        $mapped_role = sanitize_key((string)($role_map[$internal_role] ?? ''));
        if ($mapped_role !== '') return $mapped_role;
      }
    }
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) return 'editor';
    if (class_exists('EUM_Access') && EUM_Access::is_manager_user($user_id) && in_array($user_id, array_map('intval', (array)($site['manager_user_ids'] ?? [])), true)) return 'editor';
    return 'subscriber';
  }

  private static function wordpress_role_options(): array {
    $roles = wp_roles();
    $options = [];
    foreach ((array)$roles->roles as $role_key => $role_data) {
      $role_key = sanitize_key((string)$role_key);
      if ($role_key === '') continue;
      $label = sanitize_text_field((string)($role_data['name'] ?? $role_key));
      if (function_exists('translate_user_role')) $label = translate_user_role($label);
      $options[$role_key] = $label !== '' ? $label : $role_key;
    }
    return $options;
  }

  private static function normalize_site_role_map($raw): array {
    if (!is_array($raw)) return [];
    $roles = self::wordpress_role_options();
    $normalized = [];
    foreach ($raw as $internal_role => $connected_role) {
      $internal_role = sanitize_key((string)$internal_role);
      $connected_role = sanitize_key((string)$connected_role);
      if ($internal_role === '' || $connected_role === '' || !isset($roles[$internal_role]) || !isset($roles[$connected_role])) continue;
      $normalized[$internal_role] = $connected_role;
    }
    return $normalized;
  }

  private function render_site_role_mapping(array $site): void {
    $roles = self::wordpress_role_options();
    $role_map = (array)($site['site_role_map'] ?? []);
    echo '<tr><th scope="row">WordPress role mapping</th><td>';
    echo '<p class="description">Map an internal WordPress role to a role on this connected site. If a user has multiple internal roles, the first matching mapped role is used. Unmapped users keep the current default: IT/HR and assigned site managers get Editor; others get Subscriber. Saving a mapping re-syncs provisioned accounts. Administrator grants full access on the connected site, so map it only to trusted internal roles.</p>';
    echo '<table class="widefat striped" style="max-width:760px;"><thead><tr><th>Internal role</th><th>Connected-site role</th></tr></thead><tbody>';
    foreach ($roles as $internal_role => $internal_label) {
      echo '<tr><td>' . esc_html($internal_label) . ' <code>' . esc_html($internal_role) . '</code></td><td><select name="site_role_map[' . esc_attr($internal_role) . ']">';
      echo '<option value="">Use default rule</option>';
      foreach ($roles as $connected_role => $connected_label) {
        echo '<option value="' . esc_attr($connected_role) . '" ' . selected((string)($role_map[$internal_role] ?? ''), $connected_role, false) . '>' . esc_html($connected_label . ' (' . $connected_role . ')') . '</option>';
      }
      echo '</select></td></tr>';
    }
    echo '</tbody></table></td></tr>';
  }

  public function handle_launch_site(): void {
    if (!is_user_logged_in()) wp_die('Please sign in.');
    $site_id = isset($_GET['site_id']) ? sanitize_key((string)wp_unslash($_GET['site_id'])) : '';
    $site = self::get_site($site_id);
    $manager_user_id = get_current_user_id();
    if (!$site || !self::user_can_manage_site($manager_user_id, $site)) wp_die('Not allowed.');
    check_admin_referer('eum_site_manager_launch_' . $site_id);
    $manager = get_user_by('id', $manager_user_id);
    if (!$manager instanceof WP_User) wp_die('Your account could not be loaded.');

    $response = wp_remote_post($site['provision_url'], [
      'timeout' => 20,
      'redirection' => 0,
      'sslverify' => true,
      'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-EUM-Site-Key' => $site['site_key']],
      'body' => wp_json_encode([
        'users' => [self::user_provision_payload($manager, $site)],
        'reason' => 'Site Manager access granted for ' . $site['label'],
        'manager_user_id' => $manager_user_id,
        'manager_sub' => class_exists('EUM_OIDC_Provider') ? EUM_OIDC_Provider::subject_identifier_for_user($manager) : (string)$manager->user_login,
      ]),
    ]);
    if (is_wp_error($response)) $this->provision_redirect(self::site_manager_url($site_id), 'Could not prepare your site account: ' . $response->get_error_message(), 'error');
    $body = json_decode((string)wp_remote_retrieve_body($response), true);
    $results = is_array($body) && is_array($body['results'] ?? null) ? $body['results'] : [];
    $manager_result = null;
    foreach ($results as $result) {
      if (is_array($result) && (int)($result['central_user_id'] ?? 0) === $manager_user_id) {
        $manager_result = $result;
        break;
      }
    }
    if ((int)wp_remote_retrieve_response_code($response) < 200 || (int)wp_remote_retrieve_response_code($response) >= 300 || !is_array($manager_result) || !in_array((string)($manager_result['status'] ?? ''), ['created', 'linked', 'updated'], true)) {
      $message = is_array($manager_result) ? sanitize_text_field((string)($manager_result['message'] ?? 'The connected site could not create your account.')) : 'The connected site returned an unexpected response.';
      $this->provision_redirect(self::site_manager_url($site_id), 'Could not prepare your site account: ' . $message, 'error');
    }

    $sites = self::get_sites();
    $sites[$site_id]['provisioned_user_ids'] = array_values(array_unique(array_merge((array)$site['provisioned_user_ids'], [$manager_user_id])));
    $sites[$site_id]['manager_provisioned_user_ids'] = array_values(array_unique(array_merge((array)$site['manager_provisioned_user_ids'], [$manager_user_id])));
    update_option(self::OPTION_SITES, $sites, false);
    self::record_audit($site_id, $manager_user_id, 'Site Manager access granted for ' . $site['label'], $results);
    // This external destination was entered by an IT administrator and validated as HTTPS.
    wp_redirect($site['launch_url']);
    exit;
  }

  private static function record_audit(string $site_id, int $manager_user_id, string $reason, array $results): void {
    $audit = get_option(self::OPTION_AUDIT, []);
    if (!is_array($audit)) $audit = [];
    $audit[] = [
      'time' => current_time('mysql'),
      'site_id' => sanitize_key($site_id),
      'manager_user_id' => $manager_user_id,
      'reason' => sanitize_textarea_field($reason),
      'results' => array_map(static function ($result) {
        if (!is_array($result)) return [];
        return [
          'central_user_id' => (int)($result['central_user_id'] ?? 0),
          'status' => sanitize_key((string)($result['status'] ?? 'failed')),
          'message' => sanitize_text_field((string)($result['message'] ?? '')),
        ];
      }, $results),
    ];
    update_option(self::OPTION_AUDIT, array_slice($audit, -300), false);
  }

  public function admin_menu(): void {
    add_submenu_page(EUM_Admin::parent_slug('integrations'), 'Site Manager Admin', 'Site Manager Admin', 'read', 'eum-site-manager', [$this, 'render_admin_page']);
  }

  public function register_routes(): void {
    register_rest_route('eum-site-manager/v1', '/connect', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [$this, 'handle_pairing_request'],
    ]);
  }

  public function ajax_create_pairing_code(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) {
      wp_send_json_error(['message' => 'Not allowed.'], 403);
    }
    if (!check_ajax_referer('eum_site_manager_create_pairing_code', '_ajax_nonce', false)) {
      wp_send_json_error(['message' => 'This page has expired. Refresh it and try again.'], 403);
    }
    if (!class_exists('EUM_OIDC_Provider') || !is_array(get_option(EUM_OIDC_Provider::OPTION_SETTINGS, null)) || empty(EUM_OIDC_Provider::get_settings()['enabled'])) {
      wp_send_json_error(['message' => 'Enable and save the internal SSO / OIDC provider before generating a connection code.'], 409);
    }
    try {
      $code = bin2hex(random_bytes(24));
    } catch (Throwable $error) {
      wp_send_json_error(['message' => 'Could not create a secure connection code.'], 500);
    }
    $transient_key = self::PAIRING_TRANSIENT_PREFIX . hash('sha256', $code);
    if (!set_transient($transient_key, ['created_by' => get_current_user_id()], 15 * MINUTE_IN_SECONDS)) {
      wp_send_json_error(['message' => 'Could not save the temporary connection code.'], 500);
    }
    wp_send_json_success(['code' => $code, 'expires_in' => 15 * MINUTE_IN_SECONDS]);
  }

  public function handle_pairing_request(WP_REST_Request $request) {
    $payload = $request->get_json_params();
    if (!is_array($payload)) return new WP_Error('eum_site_pairing_invalid', 'The connection details are invalid.', ['status' => 400]);

    $code = strtolower(trim(sanitize_text_field((string)($payload['pairing_code'] ?? ''))));
    if (!preg_match('/^[a-f0-9]{48}$/', $code)) {
      return new WP_Error('eum_site_pairing_denied', 'The one-time connection code is invalid or expired.', ['status' => 401]);
    }
    $transient_key = self::PAIRING_TRANSIENT_PREFIX . hash('sha256', $code);
    if (!is_array(get_transient($transient_key))) {
      return new WP_Error('eum_site_pairing_denied', 'The one-time connection code is invalid, expired, or already used.', ['status' => 401]);
    }
    if (!class_exists('EUM_OIDC_Provider') || !is_array(get_option(EUM_OIDC_Provider::OPTION_SETTINGS, null))) {
      return new WP_Error('eum_site_pairing_oidc_unconfigured', 'Enable and save the internal SSO / OIDC provider before pairing a connected site.', ['status' => 409]);
    }
    $oidc_settings = EUM_OIDC_Provider::get_settings();
    if (empty($oidc_settings['enabled']) || trim((string)($oidc_settings['client_id'] ?? '')) === '' || trim((string)($oidc_settings['client_secret'] ?? '')) === '') {
      return new WP_Error('eum_site_pairing_oidc_disabled', 'Enable and save the internal SSO / OIDC provider before pairing a connected site.', ['status' => 409]);
    }
    $connected_client_id = sanitize_text_field((string)($payload['oidc_client_id'] ?? ''));
    if ($connected_client_id === '' || !hash_equals((string)$oidc_settings['client_id'], $connected_client_id)) {
      return new WP_Error('eum_site_pairing_client_id_mismatch', 'The OIDC Client ID on this connected site does not match the internal SSO / OIDC settings.', ['status' => 400]);
    }
    $connected_client_secret = trim((string)($payload['oidc_client_secret'] ?? ''));
    if ($connected_client_secret === '' || !hash_equals((string)$oidc_settings['client_secret'], $connected_client_secret)) {
      return new WP_Error('eum_site_pairing_client_secret_mismatch', 'The OIDC Client Secret on this connected site does not match the internal SSO / OIDC settings.', ['status' => 400]);
    }

    $label = sanitize_text_field((string)($payload['site_name'] ?? ''));
    $site_url = untrailingslashit(esc_url_raw((string)($payload['site_url'] ?? '')));
    $launch_url = esc_url_raw((string)($payload['launch_url'] ?? ''));
    $provision_url = esc_url_raw((string)($payload['provision_url'] ?? ''));
    $callback_url = esc_url_raw((string)($payload['callback_url'] ?? ''));
    if ($label === '' || !self::is_https_url($site_url) || !self::is_https_url($launch_url) || !self::is_https_url($provision_url) || !self::is_https_url($callback_url) || !$this->same_https_origin($launch_url, $callback_url) || !$this->same_https_origin($provision_url, $site_url)) {
      return new WP_Error('eum_site_pairing_details_invalid', 'The connected site must provide a name and HTTPS URLs. Its sign-in and callback URLs must share a host, as must its site and provisioning URLs.', ['status' => 400]);
    }

    $claim_key = '_eum_site_pair_claim_' . hash('sha256', $code);
    if (!add_option($claim_key, time(), '', 'no')) {
      return new WP_Error('eum_site_pairing_in_progress', 'This connection code is already being used or has already been used.', ['status' => 409]);
    }
    try {
      if (!is_array(get_transient($transient_key))) {
        return new WP_Error('eum_site_pairing_denied', 'The one-time connection code is invalid, expired, or already used.', ['status' => 401]);
      }
      $sites = get_option(self::OPTION_SITES, []);
      if (!is_array($sites)) $sites = [];
      $existing_id = '';
      $existing = [];
      foreach ($sites as $candidate_id => $candidate) {
        if (!is_array($candidate)) continue;
        $candidate_url = untrailingslashit(esc_url_raw((string)($candidate['site_url'] ?? '')));
        $same_legacy_record = $candidate_url === ''
          && (string)($candidate['launch_url'] ?? '') === $launch_url
          && (string)($candidate['provision_url'] ?? '') === $provision_url;
        if (($candidate_url !== '' && $candidate_url === $site_url) || $same_legacy_record) {
          $existing_id = sanitize_key((string)($candidate['id'] ?? $candidate_id));
          $existing = $candidate;
          break;
        }
      }

      $site_id = $existing_id !== '' ? $existing_id : 'site-' . strtolower(str_replace('-', '', wp_generate_uuid4()));
      $site_key = trim((string)($existing['site_key'] ?? ''));
      if ($site_key === '') $site_key = $this->random_secret();
      $sites[$site_id] = [
        'id' => $site_id,
        'label' => $label,
        'site_url' => $site_url,
        'launch_url' => $launch_url,
        'provision_url' => $provision_url,
        'site_key' => $site_key,
        'site_role_map' => self::normalize_site_role_map($existing['site_role_map'] ?? []),
        'manager_user_ids' => array_values(array_unique(array_map('intval', (array)($existing['manager_user_ids'] ?? [])))),
        'job_path_ids' => array_values(array_unique(array_map('intval', (array)($existing['job_path_ids'] ?? [])))),
        'group_ids' => array_values(array_unique(array_map('intval', (array)($existing['group_ids'] ?? [])))),
        'path_app_launcher_enabled' => !array_key_exists('path_app_launcher_enabled', $existing) || !empty($existing['path_app_launcher_enabled']),
        'provisioned_user_ids' => array_values(array_unique(array_map('intval', (array)($existing['provisioned_user_ids'] ?? [])))),
        'auto_provisioned_user_ids' => array_values(array_unique(array_map('intval', (array)($existing['auto_provisioned_user_ids'] ?? [])))),
        'manual_scope_override_user_ids' => array_values(array_unique(array_map('intval', (array)($existing['manual_scope_override_user_ids'] ?? [])))),
        'manager_provisioned_user_ids' => array_values(array_unique(array_map('intval', (array)($existing['manager_provisioned_user_ids'] ?? [])))),
        'app_launcher_disabled_user_ids' => array_values(array_unique(array_map('intval', (array)($existing['app_launcher_disabled_user_ids'] ?? [])))),
      ];

      delete_transient($transient_key);
      update_option(self::OPTION_SITES, $sites, false);
      $this->queue_site_reconciliation($site_id);
      $this->add_allowed_oidc_redirect_uri($callback_url);
      return rest_ensure_response([
        'connected' => true,
        'site_id' => $site_id,
        'site_key' => $site_key,
      ]);
    } finally {
      delete_option($claim_key);
    }
  }

  private function add_allowed_oidc_redirect_uri(string $callback_url): void {
    if (!class_exists('EUM_OIDC_Provider')) return;
    $settings = EUM_OIDC_Provider::get_settings();
    $uris = preg_split('/\\r\\n|\\r|\\n/', (string)($settings['allowed_redirect_uris'] ?? '')) ?: [];
    $uris = array_values(array_unique(array_filter(array_map('trim', $uris), static function ($uri) { return $uri !== ''; })));
    if (in_array($callback_url, $uris, true)) return;
    $uris[] = $callback_url;
    $settings['allowed_redirect_uris'] = implode("\n", $uris);
    update_option(EUM_OIDC_Provider::OPTION_SETTINGS, $settings, false);
  }

  public function render_admin_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!empty($_GET['eum_site_error'])) echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field((string)wp_unslash($_GET['eum_site_error']))) . '</p></div>';
    if (isset($_GET['eum_site_saved'])) echo '<div class="notice notice-success"><p>Connected site saved.</p></div>';
    if (isset($_GET['eum_site_deleted'])) echo '<div class="notice notice-success"><p>Connected site removed.</p></div>';
    if (isset($_GET['eum_site_test'])) {
      $test_type = sanitize_key((string)wp_unslash($_GET['eum_site_test']));
      $test_message = sanitize_text_field((string)wp_unslash($_GET['eum_site_test_message'] ?? ''));
      if ($test_message !== '') echo '<div class="notice ' . ($test_type === 'success' ? 'notice-success' : 'notice-error') . '"><p>' . esc_html($test_message) . '</p></div>';
    }
    echo '<div class="wrap"><h1>Site Manager Admin</h1><p>Connect WordPress sites that use the companion plugin. Managers can provision users in their manager scope, limited by the job paths and groups selected for each site. HR and IT can manually provision any internal user, including non-rehirable or terminated users and accounts with blank profile fields. Terminated users’ connected-site accounts remain inactive. Superadmin retains access to manually provision terminated users within the existing manager scope.</p>';
    echo '<p>For guided setup, first enable and save <a href="' . esc_url(admin_url('admin.php?page=eum-oidc')) . '">SSO / OIDC</a>, then save the internal URL and OIDC credentials in Enterprise Connected Site. Generate a one-time code below and enter it there to send the site details and receive its provisioning key automatically. The callback is also added to the allowed OIDC redirect list. You can still add sites manually below.</p>';
    $this->render_pairing_card();

    $sites = self::get_sites();
    echo '<h2>Connected sites</h2>';
    foreach ($sites as $site) $this->render_site_form($site);
    $this->render_site_form([]);
    echo '</div>';
  }

  private function render_pairing_card(): void {
    echo '<section style="max-width:900px;margin:18px 0;padding:18px;border:1px solid #c3c4c7;background:#fff;">';
    echo '<h2 style="margin-top:0;">Pair a connected site</h2><p>Generate a one-use code that expires in 15 minutes. Enter it in the connected site’s settings and click <strong>Connect to internal portal</strong>. The site will send its name, sign-in URL, provisioning URL, and OIDC callback; the provisioning key will be returned and saved automatically. Then assign managers and any path/group limits below.</p>';
    echo '<button type="button" class="button button-secondary" id="eum-site-manager-create-pairing-code" data-nonce="' . esc_attr(wp_create_nonce('eum_site_manager_create_pairing_code')) . '">Generate one-time code</button> ';
    echo '<input type="text" id="eum-site-manager-pairing-code" class="regular-text code" readonly hidden aria-label="One-time connected site code">';
    echo '<p id="eum-site-manager-pairing-status" role="status" aria-live="polite"></p>';
    echo '<script>(function(){var button=document.getElementById("eum-site-manager-create-pairing-code");var input=document.getElementById("eum-site-manager-pairing-code");var status=document.getElementById("eum-site-manager-pairing-status");if(!button||!input||!status)return;button.addEventListener("click",function(){button.disabled=true;status.textContent="Generating a code...";var body=new URLSearchParams({action:"eum_site_manager_create_pairing_code",_ajax_nonce:button.getAttribute("data-nonce")});fetch(ajaxurl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()}).then(function(response){return response.json();}).then(function(result){if(!result.success)throw new Error(result.data&&result.data.message?result.data.message:"Could not generate a code.");input.value=result.data.code;input.hidden=false;input.focus();input.select();status.textContent="Copy this code into Enterprise Connected Site. It expires in 15 minutes and works once.";}).catch(function(error){status.textContent=error.message||"Could not generate a code.";}).finally(function(){button.disabled=false;});});}());</script>';
    echo '</section>';
  }

  private function render_site_form(array $site): void {
    $site_id = (string)($site['id'] ?? '');
    $is_new = $site_id === '';
    $heading = $is_new ? 'Add a connected site' : (string)$site['label'];
    $managers = array_map('intval', (array)($site['manager_user_ids'] ?? []));
    $job_paths = array_map('intval', (array)($site['job_path_ids'] ?? []));
    $groups = array_map('intval', (array)($site['group_ids'] ?? []));
    $users = get_users(['fields' => 'all', 'number' => 5000, 'orderby' => 'display_name', 'order' => 'ASC']);
    echo '<details id="site-' . esc_attr($site_id ?: 'new') . '" style="margin:18px 0;padding:16px;border:1px solid #c3c4c7;background:#fff;"><summary style="font-size:16px;font-weight:600;cursor:pointer;">' . esc_html($heading) . '</summary>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_site_manager_save_site"><input type="hidden" name="site_id" value="' . esc_attr($site_id) . '">';
    wp_nonce_field('eum_site_manager_save_site', 'eum_site_manager_nonce');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="eum-site-label-' . esc_attr($site_id ?: 'new') . '">Site name</label></th><td><input id="eum-site-label-' . esc_attr($site_id ?: 'new') . '" class="regular-text" type="text" name="label" required value="' . esc_attr((string)($site['label'] ?? '')) . '"></td></tr>';
    echo '<tr><th scope="row">Launch URL</th><td><input class="large-text code" type="url" name="launch_url" required value="' . esc_attr((string)($site['launch_url'] ?? '')) . '" placeholder="https://example.com/wp-admin/admin-post.php?action=eum_connected_site_login"><p class="description">The companion plugin displays its one-click internal sign-in URL in its settings.</p></td></tr>';
    echo '<tr><th scope="row">Provisioning API URL</th><td><input class="large-text code" type="url" name="provision_url" required value="' . esc_attr((string)($site['provision_url'] ?? '')) . '" placeholder="https://example.com/wp-json/eum-connected-site/v1/provision"><p class="description">Copy the exact endpoint shown by the companion plugin.</p></td></tr>';
    echo '<tr><th scope="row">Site API key</th><td>';
    if (!$is_new) echo '<label>Current key (copy to companion plugin)<br><input class="large-text code" type="text" readonly onclick="this.select()" value="' . esc_attr((string)$site['site_key']) . '"></label><br>';
    echo '<input class="large-text code" type="password" name="site_key" value="" autocomplete="new-password" placeholder="' . ($is_new ? 'Generated when this site is saved' : 'Leave blank to keep the existing key') . '"><p class="description">The key is stored on this internal site and must match the key configured on the destination site.' . (!$is_new ? ' Site ID: <code>' . esc_html($site_id) . '</code>' : '') . '</p></td></tr>';
    echo '<tr><th scope="row">Managers</th><td><select name="manager_user_ids[]" multiple size="7" style="min-width:340px;">';
    foreach ((array)$users as $user) {
      if (!($user instanceof WP_User)) continue;
      $user_label = trim((string)$user->display_name) !== '' ? (string)$user->display_name : (string)$user->user_login;
      echo '<option value="' . (int)$user->ID . '" ' . selected(in_array((int)$user->ID, $managers, true), true, false) . '>' . esc_html($user_label . ' · ' . $user->user_login) . '</option>';
    }
    echo '</select><p class="description">People selected here can manage this site only. IT and HR can manage every connected site.</p></td></tr>';
    $this->render_site_role_mapping($site);
    $this->render_term_select('Job paths allowed on this site', 'job_path_ids', 'eum_job_path', $job_paths);
    $this->render_term_select('User groups allowed on this site', 'group_ids', 'eum_user_group', $groups);
    $path_app_enabled = $is_new || !array_key_exists('path_app_launcher_enabled', $site) || !empty($site['path_app_launcher_enabled']);
    echo '<tr><th scope="row">App shortcut for automatic path/group provisioning</th><td><label><input type="checkbox" name="path_app_launcher_enabled" value="1" ' . checked($path_app_enabled, true, false) . '> Add a direct sign-in shortcut by default when users enter this site’s configured path/group scope</label><p class="description">Enabled by default. Turn this off to automatically provision eligible users without adding this site to their app bar. The bulk provisioning form still lets you choose per batch.</p></td></tr>';
    echo '</tbody></table><p><button type="submit" class="button button-primary">' . ($is_new ? 'Add site' : 'Save site') . '</button></p></form>';
    if (!$is_new) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 8px 0 0;"><input type="hidden" name="action" value="eum_site_manager_test_connection"><input type="hidden" name="site_id" value="' . esc_attr($site_id) . '">';
      wp_nonce_field('eum_site_manager_test_connection_' . $site_id, 'eum_site_manager_test_nonce');
      echo '<button type="submit" class="button">Test connection</button> <span class="description">Verifies the destination, provisioning key, internal OIDC issuer, and account-removal support; does not create or change accounts.</span></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Remove this site and delete its linked accounts from the connected site? Content will be reassigned to a site administrator.\');"><input type="hidden" name="action" value="eum_site_manager_delete_site"><input type="hidden" name="site_id" value="' . esc_attr($site_id) . '">';
      wp_nonce_field('eum_site_manager_delete_site_' . $site_id, 'eum_site_manager_delete_nonce');
      echo '<button type="submit" class="button button-link-delete">Remove site</button></form>';
    }
    echo '</details>';
  }

  private function render_term_select(string $label, string $name, string $taxonomy, array $selected_ids): void {
    if (!taxonomy_exists($taxonomy)) return;
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
    if (is_wp_error($terms)) $terms = [];
    echo '<tr><th scope="row">' . esc_html($label) . '</th><td><select name="' . esc_attr($name) . '[]" multiple size="6" style="min-width:340px;">';
    foreach ((array)$terms as $term) {
      $term_id = (int)$term->term_id;
      $term_label = $taxonomy === 'eum_job_path' && class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path($taxonomy, $term_id, ' / ') : (string)$term->name;
      echo '<option value="' . $term_id . '" ' . selected(in_array($term_id, $selected_ids, true), true, false) . '>' . esc_html($term_label) . '</option>';
    }
    echo '</select><p class="description">Users matching these path/group rules are automatically provisioned and kept in sync as membership changes. Selected path roots include descendants; selected groups match directly. When both are configured, users must match both. Leaving every configured path/group removes the linked account from this connected site; clearing the scope removes accounts managed only by the old scope. Users who still have Site Manager access are retained.</p></td></tr>';
  }

  public function handle_save_site(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_site_manager_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_site_manager_nonce']), 'eum_site_manager_save_site')) wp_die('Invalid request.');
    $site_id = isset($_POST['site_id']) ? sanitize_key((string)wp_unslash($_POST['site_id'])) : '';
    $sites = self::get_sites();
    $existing = $site_id !== '' ? ($sites[$site_id] ?? null) : null;
    if ($site_id !== '' && !$existing) wp_die('Connected site not found.');

    $label = sanitize_text_field((string)wp_unslash($_POST['label'] ?? ''));
    $launch_url = esc_url_raw(trim((string)wp_unslash($_POST['launch_url'] ?? '')));
    $provision_url = esc_url_raw(trim((string)wp_unslash($_POST['provision_url'] ?? '')));
    if ($label === '' || !self::is_https_url($launch_url) || !self::is_https_url($provision_url)) {
      wp_safe_redirect(add_query_arg(['page' => 'eum-site-manager', 'eum_site_error' => 'Name, HTTPS launch URL, and HTTPS provisioning URL are required.'], admin_url('admin.php')));
      exit;
    }
    if ($site_id === '') $site_id = 'site-' . strtolower(str_replace('-', '', wp_generate_uuid4()));
    $site_key = trim((string)wp_unslash($_POST['site_key'] ?? ''));
    if ($site_key === '' && is_array($existing)) $site_key = (string)$existing['site_key'];
    if ($site_key === '') $site_key = $this->random_secret();
    $sites[$site_id] = [
      'id' => $site_id,
      'label' => $label,
      'site_url' => is_array($existing) ? (string)($existing['site_url'] ?? '') : '',
      'launch_url' => $launch_url,
      'provision_url' => $provision_url,
      'site_key' => $site_key,
      'site_role_map' => self::normalize_site_role_map(wp_unslash($_POST['site_role_map'] ?? [])),
      'manager_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($_POST['manager_user_ids'] ?? [])), static function ($id) { return $id > 0; }))),
      'job_path_ids' => $this->valid_term_ids((array)($_POST['job_path_ids'] ?? []), 'eum_job_path'),
      'group_ids' => $this->valid_term_ids((array)($_POST['group_ids'] ?? []), 'eum_user_group'),
      'path_app_launcher_enabled' => !empty($_POST['path_app_launcher_enabled']),
      'provisioned_user_ids' => is_array($existing) ? (array)$existing['provisioned_user_ids'] : [],
      'auto_provisioned_user_ids' => is_array($existing) ? (array)$existing['auto_provisioned_user_ids'] : [],
      'manual_scope_override_user_ids' => is_array($existing) ? (array)$existing['manual_scope_override_user_ids'] : [],
      'manager_provisioned_user_ids' => is_array($existing) ? (array)$existing['manager_provisioned_user_ids'] : [],
      'app_launcher_disabled_user_ids' => is_array($existing) ? (array)$existing['app_launcher_disabled_user_ids'] : [],
    ];
    if (self::site_has_provision_scope($sites[$site_id])) {
      $sites[$site_id]['auto_provisioned_user_ids'] = array_values(array_unique(array_merge(
        array_map('intval', (array)$sites[$site_id]['auto_provisioned_user_ids']),
        array_map('intval', (array)$sites[$site_id]['provisioned_user_ids'])
      )));
    }
    update_option(self::OPTION_SITES, $sites, false);
    $this->queue_site_reconciliation($site_id);
    wp_safe_redirect(add_query_arg(['page' => 'eum-site-manager', 'eum_site_saved' => '1'], admin_url('admin.php')));
    exit;
  }

  public function handle_delete_site(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $site_id = isset($_POST['site_id']) ? sanitize_key((string)wp_unslash($_POST['site_id'])) : '';
    if ($site_id === '' || !isset($_POST['eum_site_manager_delete_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_site_manager_delete_nonce']), 'eum_site_manager_delete_site_' . $site_id)) wp_die('Invalid request.');
    $sites = self::get_sites();
    if (!isset($sites[$site_id])) wp_die('Connected site not found.');
    $site = $sites[$site_id];
    $removed_ids = [];
    $failures = [];
    $reason = 'Connected site removed from Site Manager';
    foreach (array_chunk(array_map('intval', (array)$site['provisioned_user_ids']), 50) as $batch) {
      $result = $this->deprovision_site_user_batch($site, $batch, $reason);
      $removed_ids = array_merge($removed_ids, $result['success_ids']);
      $failures = array_merge($failures, $result['failures']);
    }
    if (!empty($removed_ids)) {
      $sites[$site_id]['provisioned_user_ids'] = array_values(array_diff(array_map('intval', (array)$site['provisioned_user_ids']), $removed_ids));
      foreach (['auto_provisioned_user_ids', 'manual_scope_override_user_ids', 'manager_provisioned_user_ids', 'app_launcher_disabled_user_ids'] as $key) {
        $sites[$site_id][$key] = array_values(array_diff(array_map('intval', (array)$site[$key]), $removed_ids));
      }
    }
    if (!empty($failures)) {
      update_option(self::OPTION_SITES, $sites, false);
      foreach ($failures as $user_id => $message) {
        eum_log_message('[EUM Site Manager] Could not remove linked user ' . (int)$user_id . ' while removing site ' . $site_id . ': ' . sanitize_text_field((string)$message));
        $this->queue_site_user_deprovision($site_id, (int)$user_id, $reason);
      }
      $summary = sprintf('The site was kept in Site Manager because %d account(s) could not be removed yet. Removal will retry automatically; details were recorded in the error log.', count($failures));
      wp_safe_redirect(add_query_arg(['page' => 'eum-site-manager', 'eum_site_error' => $summary], admin_url('admin.php')));
      exit;
    }

    unset($sites[$site_id]);
    update_option(self::OPTION_SITES, $sites, false);
    $queue = get_option(self::OPTION_DEPROVISION_QUEUE, []);
    if (is_array($queue) && isset($queue[$site_id])) {
      unset($queue[$site_id]);
      if (empty($queue)) delete_option(self::OPTION_DEPROVISION_QUEUE);
      else update_option(self::OPTION_DEPROVISION_QUEUE, $queue, false);
    }
    wp_safe_redirect(add_query_arg(['page' => 'eum-site-manager', 'eum_site_deleted' => '1'], admin_url('admin.php')));
    exit;
  }

  public function handle_test_connection(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $site_id = isset($_POST['site_id']) ? sanitize_key((string)wp_unslash($_POST['site_id'])) : '';
    $site = $site_id !== '' ? self::get_site($site_id) : null;
    if (!$site || !isset($_POST['eum_site_manager_test_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_site_manager_test_nonce']), 'eum_site_manager_test_connection_' . $site_id)) wp_die('Invalid request.');

    $test_url = $this->connection_test_url((string)$site['provision_url']);
    if ($test_url === '') {
      $this->redirect_after_connection_test($site_id, 'error', 'Could not determine the test endpoint. Use the exact Provisioning API URL shown by the Enterprise Connected Site plugin.');
    }

    $response = wp_remote_get($test_url, [
      'timeout' => 15,
      'redirection' => 0,
      'headers' => [
        'X-EUM-Site-Key' => (string)$site['site_key'],
        'Accept' => 'application/json',
      ],
    ]);
    if (is_wp_error($response)) {
      $this->redirect_after_connection_test($site_id, 'error', 'Could not reach the connected site: ' . $response->get_error_message());
    }

    $status = (int)wp_remote_retrieve_response_code($response);
    $body = json_decode((string)wp_remote_retrieve_body($response), true);
    if ($status >= 200 && $status < 300 && is_array($body) && !empty($body['connected']) && !empty($body['deprovision_ready'])) {
      $this->redirect_after_connection_test($site_id, 'success', 'Connection successful. The provisioning key, internal OIDC issuer, and account-removal endpoint were verified.');
    }
    if ($status >= 200 && $status < 300 && is_array($body) && !empty($body['connected']) && empty($body['deprovision_ready'])) {
      $this->redirect_after_connection_test($site_id, 'error', 'Provisioning works, but account removal is not available yet. Update Enterprise Connected Site on this destination.');
    }
    if ($status === 401) {
      $message = 'The connected site rejected the Site provisioning key. Make sure the key matches on both sites.';
    } elseif ($status === 404) {
      $message = 'The connection-test endpoint was not found. Check that Enterprise Connected Site is installed, active, and up to date.';
    } elseif ($status >= 300 && $status < 400) {
      $message = 'The connected site redirected the test request. Check that the saved Provisioning API URL is the exact HTTPS URL shown in its settings.';
    } elseif (is_array($body) && !empty($body['message'])) {
      $message = 'The provisioning key was accepted, but OIDC validation failed: ' . sanitize_text_field((string)$body['message']);
    } else {
      $message = 'The connected site returned an unexpected response (HTTP ' . $status . '). Check that Enterprise Connected Site is active and up to date.';
    }
    $this->redirect_after_connection_test($site_id, 'error', $message);
  }

  private function connection_test_url(string $provision_url): string {
    $parts = wp_parse_url($provision_url);
    if (!is_array($parts)) return '';
    $query_args = [];
    if (!empty($parts['query'])) parse_str((string)$parts['query'], $query_args);
    if (isset($query_args['rest_route']) && is_string($query_args['rest_route'])) {
      $test_route = preg_replace('~/provision/?$~i', '/connection-test', $query_args['rest_route'], 1);
      if (is_string($test_route) && $test_route !== $query_args['rest_route']) {
        $query_args['rest_route'] = $test_route;
        return add_query_arg($query_args, remove_query_arg(array_keys($query_args), $provision_url));
      }
    }
    $test_url = preg_replace('~/provision/?(?=[?#]|$)~i', '/connection-test', $provision_url, 1);
    return is_string($test_url) && $test_url !== $provision_url ? $test_url : '';
  }

  private function deprovision_endpoint_url(string $provision_url): string {
    $parts = wp_parse_url($provision_url);
    if (!is_array($parts)) return '';
    $query_args = [];
    if (!empty($parts['query'])) parse_str((string)$parts['query'], $query_args);
    if (isset($query_args['rest_route']) && is_string($query_args['rest_route'])) {
      $route = preg_replace('~/provision/?$~i', '/deprovision', $query_args['rest_route'], 1);
      if (is_string($route) && $route !== $query_args['rest_route']) {
        $query_args['rest_route'] = $route;
        return add_query_arg($query_args, remove_query_arg(array_keys($query_args), $provision_url));
      }
    }
    $url = preg_replace('~/provision/?(?=[?#]|$)~i', '/deprovision', $provision_url, 1);
    return is_string($url) && $url !== $provision_url ? $url : '';
  }

  private function deprovision_site_user_batch(array $site, array $user_ids, string $reason): array {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static function ($id) { return $id > 0; })));
    $result = ['success_ids' => [], 'failures' => []];
    if (empty($user_ids)) return $result;
    $url = $this->deprovision_endpoint_url((string)($site['provision_url'] ?? ''));
    if ($url === '') {
      foreach ($user_ids as $user_id) $result['failures'][$user_id] = 'Could not determine the connected site deprovision endpoint.';
      return $result;
    }
    foreach (array_chunk($user_ids, 50) as $batch) {
      $response = wp_remote_post($url, [
        'timeout' => 25,
        'redirection' => 0,
        'sslverify' => true,
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'X-EUM-Site-Key' => (string)($site['site_key'] ?? ''),
        ],
        'body' => wp_json_encode([
          'central_user_ids' => $batch,
          'reason' => sanitize_textarea_field($reason),
          'manager_user_id' => get_current_user_id(),
          'manager_sub' => is_user_logged_in() && class_exists('EUM_OIDC_Provider')
            ? EUM_OIDC_Provider::subject_identifier_for_user(get_current_user_id())
            : '',
        ]),
      ]);
      if (is_wp_error($response)) {
        foreach ($batch as $user_id) $result['failures'][$user_id] = $response->get_error_message();
        continue;
      }
      $status_code = (int)wp_remote_retrieve_response_code($response);
      $body = json_decode((string)wp_remote_retrieve_body($response), true);
      if ($status_code < 200 || $status_code >= 300 || !is_array($body) || !is_array($body['results'] ?? null)) {
        $message = is_array($body) ? sanitize_text_field((string)($body['message'] ?? $body['code'] ?? '')) : '';
        if ($message === '') $message = $status_code === 404
          ? 'The connected site does not have the deprovision endpoint yet. Update its Enterprise Connected Site plugin.'
          : 'The connected site returned an unexpected response while removing accounts.';
        foreach ($batch as $user_id) $result['failures'][$user_id] = $message;
        continue;
      }
      $received = [];
      foreach ($body['results'] as $item) {
        if (!is_array($item)) continue;
        $user_id = (int)($item['central_user_id'] ?? 0);
        if ($user_id <= 0 || !in_array($user_id, $batch, true)) continue;
        $received[$user_id] = true;
        $item_status = sanitize_key((string)($item['status'] ?? 'failed'));
        if (in_array($item_status, ['deleted', 'not_found'], true)) {
          $result['success_ids'][] = $user_id;
        } else {
          $result['failures'][$user_id] = sanitize_text_field((string)($item['message'] ?? 'The connected site could not remove this account.'));
        }
      }
      foreach ($batch as $user_id) {
        if (!isset($received[$user_id])) $result['failures'][$user_id] = 'The connected site did not return a removal result for this account.';
      }
    }
    $result['success_ids'] = array_values(array_unique(array_map('intval', $result['success_ids'])));
    if (!empty($result['success_ids'])) {
      $audit_results = array_map(static function ($user_id) { return ['central_user_id' => (int)$user_id, 'status' => 'removed', 'message' => 'Account removed or was already absent from the connected site.']; }, $result['success_ids']);
      self::record_audit((string)($site['id'] ?? ''), get_current_user_id(), $reason, $audit_results);
    }
    return $result;
  }

  private function redirect_after_connection_test(string $site_id, string $type, string $message): void {
    $url = add_query_arg([
      'page' => 'eum-site-manager',
      'eum_site_test' => $type,
      'eum_site_test_message' => $message,
    ], admin_url('admin.php'));
    wp_safe_redirect($url . '#site-' . rawurlencode($site_id));
    exit;
  }

  private function valid_term_ids(array $raw_ids, string $taxonomy): array {
    if (!taxonomy_exists($taxonomy)) return [];
    $valid = [];
    foreach (array_map('intval', $raw_ids) as $term_id) {
      if ($term_id <= 0) continue;
      $term = get_term($term_id, $taxonomy);
      if ($term && !is_wp_error($term)) $valid[] = $term_id;
    }
    return array_values(array_unique($valid));
  }

  private function same_https_origin(string $url, string $reference): bool {
    if (!self::is_https_url($url) || !self::is_https_url($reference)) return false;
    $url_parts = wp_parse_url($url);
    $reference_parts = wp_parse_url($reference);
    if (!is_array($url_parts) || !is_array($reference_parts)) return false;
    $url_port = (int)($url_parts['port'] ?? 443);
    $reference_port = (int)($reference_parts['port'] ?? 443);
    return strtolower((string)$url_parts['host']) === strtolower((string)$reference_parts['host']) && $url_port === $reference_port;
  }

  private static function is_https_url(string $url): bool {
    $parts = wp_parse_url($url);
    return is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https' && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']);
  }

  private function random_secret(): string {
    return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
  }
}
