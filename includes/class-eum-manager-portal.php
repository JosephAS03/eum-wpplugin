<?php
if (!defined('ABSPATH')) exit;

class EUM_Manager_Portal {
  private const VALID_TABS = ['team', 'employee-list', 'messages', 'org', 'mail', 'nextcloud', 'jobs', 'learning', 'create-user'];

  public static function is_manager(int $user_id): bool {
    return class_exists('EUM_Access') && EUM_Access::is_manager_user($user_id);
  }

  public function init(): void {
    // Legacy combined portal shortcode (kept for backward compatibility).
    add_shortcode('eum_manager_portal', [$this, 'shortcode']);

    // Standalone manager tool shortcodes.
    add_shortcode('eum_manager_team', [$this, 'shortcode_team']);
    add_shortcode('eum_manager_terminated_users', [$this, 'shortcode_terminated_users']);
    add_shortcode('eum_employee_list', [$this, 'shortcode_employee_list']);
    add_shortcode('eum_manager_team_profile', [$this, 'shortcode_team_profile']);
    add_shortcode('eum_manager_messages_editor', [$this, 'shortcode_messages']);
    add_shortcode('eum_manager_org_chart', [$this, 'shortcode_org_chart']);
    add_shortcode('eum_manager_mail', [$this, 'shortcode_mail']);
    add_shortcode('eum_manager_nextcloud', [$this, 'shortcode_nextcloud']);
    add_shortcode('eum_manager_jobs', [$this, 'shortcode_jobs']);
    add_shortcode('eum_manager_learning', [$this, 'shortcode_learning']);
    add_shortcode('eum_manager_create_user', [$this, 'shortcode_create_user']);
    add_action('admin_post_eum_manager_team_bulk_edit', [$this, 'handle_team_bulk_edit']);
  }

  public function shortcode($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    $user_id = get_current_user_id();
    $atts = shortcode_atts([
      'default_tab' => 'team',
    ], (array)$atts, 'eum_manager_portal');

    $requested_tab = isset($_GET['eum_tab']) ? sanitize_key((string)$_GET['eum_tab']) : sanitize_key((string)$atts['default_tab']);
    if (!in_array($requested_tab, self::VALID_TABS, true)) {
      $requested_tab = 'team';
    }

    ob_start();
    echo '<div class="eum-manager-portal" data-eum-ajax-region="manager-portal">';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">';
    echo $this->tab_link('team', 'My Team', $requested_tab === 'team');
    echo $this->tab_link('employee-list', 'Employee List', $requested_tab === 'employee-list');
    echo $this->tab_link('messages', 'Manager Messages Editor', $requested_tab === 'messages');
    echo $this->tab_link('org', 'Org Chart', $requested_tab === 'org');
    echo $this->tab_link('mail', 'Mail', $requested_tab === 'mail');
    echo $this->tab_link('nextcloud', 'Nextcloud', $requested_tab === 'nextcloud');
    echo $this->tab_link('jobs', 'Jobs', $requested_tab === 'jobs');
    echo $this->tab_link('learning', 'Learning', $requested_tab === 'learning');
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) {
      echo $this->tab_link('create-user', 'Create User', $requested_tab === 'create-user');
    }
    echo '</div>';

    echo '<div class="eum-manager-portal-panel">';
    echo $this->render_tab($requested_tab, $user_id);
    echo '</div>';
    echo '</div>';

    return ob_get_clean();
  }

  public function shortcode_team($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    $atts = shortcode_atts([
      'limit' => '100',
      'show_filters' => '1',
      'show_profile' => '1',
      'profile_query_var' => 'eum_profile_user',
      'profile_title' => 'Team Member Profile',
      'profile_page_url' => '',
      'files_page_url' => '',
      'org_page_url' => '',
      'profile_tab' => '',
      'org_tab' => '',
      'standalone' => '1',
    ], (array)$atts, 'eum_manager_team');

    return $this->render_people_directory('active', $atts);
  }

  public function shortcode_terminated_users($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    $atts = shortcode_atts([
      'limit' => '100',
      'show_filters' => '1',
      'show_profile' => '1',
      'profile_query_var' => 'eum_profile_user',
      'profile_title' => 'Terminated User Profile',
      'profile_page_url' => '',
      'files_page_url' => '',
      'org_page_url' => '',
      'profile_tab' => '',
      'org_tab' => '',
      'standalone' => '1',
    ], (array)$atts, 'eum_manager_terminated_users');

    return $this->render_people_directory('terminated', $atts);
  }

  public function shortcode_employee_list($atts = []): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    $atts = shortcode_atts([
      'limit' => '100',
      'show_filters' => '1',
      'show_profile' => '1',
      'profile_query_var' => 'eum_profile_user',
      'profile_title' => 'Employee Profile',
      'profile_page_url' => '',
      'files_page_url' => '',
      'org_page_url' => '',
      'profile_tab' => '',
      'org_tab' => '',
      'standalone' => '1',
    ], (array)$atts, 'eum_employee_list');

    return $this->render_people_directory('employee', $atts);
  }

  public function shortcode_team_profile($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    if (!class_exists('EUM_Profiles')) {
      return '<div class="eum-manager-team-profile"><p>Team member profiles are unavailable.</p></div>';
    }

    $atts = shortcode_atts([
      'user_id' => '',
      'query_var' => 'eum_profile_user',
      'title' => 'Team Member Profile',
    ], (array)$atts, 'eum_manager_team_profile');

    return (new EUM_Profiles())->shortcode_private_profile($atts);
  }

  public function shortcode_messages($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    if (!class_exists('EUM_Manager_Messages')) {
      return '<div class="eum-manager-messages"><p>Manager Messages is unavailable.</p></div>';
    }

    return (new EUM_Manager_Messages())->render_frontend_manager_ui();
  }

  public function shortcode_org_chart($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    if (!class_exists('EUM_Org_Chart')) {
      return '<div class="eum-manager-org-chart"><p>Org Chart is unavailable.</p></div>';
    }

    $atts = shortcode_atts([
      'user_id' => '',
      'user' => '',
      'query_var' => 'eum_org_user',
      'title' => 'Org Chart',
      'show_title' => '1',
    ], (array)$atts, 'eum_manager_org_chart');

    return (new EUM_Org_Chart())->shortcode($atts);
  }

  public function shortcode_mail($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    if (!class_exists('EUM_Service_Bridge')) {
      return '<div class="eum-manager-mail"><p>Mail access is unavailable.</p></div>';
    }

    $atts = shortcode_atts([
      'user_id' => '',
      'title' => 'Mail Access',
      'auto_launch_single' => '1',
    ], (array)$atts, 'eum_manager_mail');

    $actor_user_id = get_current_user_id();
    $target_user_id = (int)$atts['user_id'];
    if ($target_user_id <= 0) $target_user_id = $actor_user_id;

    return (new EUM_Service_Bridge())->render_access_section($actor_user_id, $target_user_id, (string)$atts['title'], [
      'auto_launch_single' => $this->is_truthy($atts['auto_launch_single']),
    ]);
  }

  public function shortcode_nextcloud($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    if (!class_exists('EUM_Nextcloud_Bridge')) {
      return '<div class="eum-manager-nextcloud"><p>Nextcloud access is unavailable.</p></div>';
    }

    $atts = shortcode_atts([
      'title' => 'Nextcloud',
      'user_id' => '',
    ], (array)$atts, 'eum_manager_nextcloud');

    $target_user_id = (int)$atts['user_id'];
    if ($target_user_id <= 0) $target_user_id = get_current_user_id();

    return (new EUM_Nextcloud_Bridge())->shortcode_portal([
      'title' => (string)$atts['title'],
      'user_id' => (string)$target_user_id,
    ]);
  }

  public function shortcode_jobs($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    if (!class_exists('EUM_Job_Boards')) {
      return '<div class="eum-manager-jobs"><p>Jobs are unavailable.</p></div>';
    }

    return (new EUM_Job_Boards())->shortcode_manage((array)$atts);
  }

  public function shortcode_learning($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    if (!class_exists('EUM_LMS')) {
      return '<div class="eum-manager-learning"><p>Learning management is unavailable.</p></div>';
    }

    return (new EUM_LMS())->shortcode_manager((array)$atts);
  }

  public function shortcode_create_user($atts): string {
    $access = $this->manager_access_message();
    if ($access !== '') return $access;

    if (!class_exists('EUM_User_Creation')) {
      return '<div class="eum-manager-create-user"><p>User creation is unavailable.</p></div>';
    }

    return (new EUM_User_Creation())->shortcode((array)$atts);
  }

  private function render_tab(string $requested_tab, int $user_id): string {
    if ($requested_tab === 'employee-list') {
      return $this->shortcode_employee_list([
        'standalone' => '0',
        'profile_tab' => 'employee-list',
        'org_tab' => 'org',
      ]);
    }

    if ($requested_tab === 'messages') {
      return class_exists('EUM_Manager_Messages')
        ? $this->shortcode_messages([])
        : '<p>Manager Messages is unavailable.</p>';
    }

    if ($requested_tab === 'org') {
      return $this->shortcode_org_chart([
        'user_id' => (string)$user_id,
        'query_var' => 'eum_org_user',
        'title' => 'Org Chart',
      ]);
    }

    if ($requested_tab === 'mail') {
      return $this->shortcode_mail([
        'user_id' => (string)$user_id,
        'title' => 'Mail Access',
        'auto_launch_single' => '1',
      ]);
    }

    if ($requested_tab === 'nextcloud') {
      return $this->shortcode_nextcloud([
        'user_id' => (string)$user_id,
        'title' => 'Nextcloud',
      ]);
    }

    if ($requested_tab === 'jobs') {
      return $this->shortcode_jobs([]);
    }

    if ($requested_tab === 'learning') {
      return $this->shortcode_learning([]);
    }

    if ($requested_tab === 'create-user') {
      return $this->shortcode_create_user([]);
    }

    return $this->shortcode_team([
      'limit' => '100',
      'show_filters' => '1',
      'show_admin_links' => '0',
      'show_profile' => '1',
      'profile_query_var' => 'eum_profile_user',
      'profile_title' => 'Team Member Profile',
      'standalone' => '0',
      'profile_tab' => 'team',
      'org_tab' => 'org',
    ]);
  }

  private function render_people_directory(string $mode, array $atts): string {
    $is_employee_list = $mode === 'employee';
    $include_terminated = $is_employee_list && isset($_GET['eum_include_terminated'])
      && !is_array($_GET['eum_include_terminated'])
      && $this->is_truthy((string)wp_unslash($_GET['eum_include_terminated']));
    $is_standalone = $this->is_truthy($atts['standalone'] ?? '1');
    $current_url = $this->current_request_url();

    if ($is_standalone) {
      if ((string)$atts['profile_page_url'] === '') $atts['profile_page_url'] = $current_url;
      if ((string)$atts['files_page_url'] === '') $atts['files_page_url'] = (string)$atts['profile_page_url'];
      if ((string)$atts['org_page_url'] === '') $atts['org_page_url'] = $current_url;
    } else {
      if ((string)$atts['profile_tab'] === '') $atts['profile_tab'] = 'team';
      if ((string)$atts['org_tab'] === '') $atts['org_tab'] = 'org';
      if ((string)$atts['profile_page_url'] === '') $atts['profile_page_url'] = $current_url;
      if ((string)$atts['files_page_url'] === '') $atts['files_page_url'] = (string)$atts['profile_page_url'];
      if ((string)$atts['org_page_url'] === '') $atts['org_page_url'] = $current_url;
    }

    $limit = max(10, min(500, (int)($atts['limit'] ?? 100)));
    $show_filters = $this->is_truthy($atts['show_filters'] ?? '1');
    $query_var = sanitize_key((string)($atts['profile_query_var'] ?? 'eum_profile_user'));

    $q = isset($_GET['eum_q']) ? sanitize_text_field((string)$_GET['eum_q']) : '';
    $job_filters = $this->sanitize_job_path_filter_ids($_GET['eum_job_path_filter'] ?? []);
    $group_filter = max(0, (int)($_GET['eum_user_group_filter'] ?? 0));
    $include_indirect = !empty($_GET['eum_include_indirect']) && $this->is_truthy((string)$_GET['eum_include_indirect']);
    $report_scope = isset($_GET['eum_report_scope']) ? sanitize_key((string)$_GET['eum_report_scope']) : 'all';
    if (!in_array($report_scope, ['all', 'direct', 'indirect'], true)) $report_scope = 'all';
    $sort = isset($_GET['eum_sort']) ? sanitize_key((string)$_GET['eum_sort']) : 'name';
    $order = isset($_GET['eum_order']) ? strtolower(sanitize_key((string)$_GET['eum_order'])) : 'asc';
    if (!in_array($sort, ['name','employee_id','email','job_path','location','manager','status'], true)) $sort = 'name';
    if (!in_array($order, ['asc','desc'], true)) $order = 'asc';
    if (isset($_GET['eum_limit'])) $limit = max(10, min(500, (int)$_GET['eum_limit']));

    $collect_mode = $is_employee_list ? ($include_terminated ? 'all' : 'active') : $mode;
    $rows = $this->collect_directory_rows($collect_mode, [
      'q' => $q,
      'job_filters' => $job_filters,
      'group_filter' => $group_filter,
      'include_indirect' => $include_indirect,
      'report_scope' => $report_scope,
      'global_employee_list' => $is_employee_list,
      'limit' => $limit,
      'profile_page_url' => (string)$atts['profile_page_url'],
      'files_page_url' => (string)$atts['files_page_url'],
      'org_page_url' => (string)$atts['org_page_url'],
      'profile_query_var' => $query_var,
      'org_query_var' => 'eum_org_user',
      'profile_tab' => sanitize_key((string)($atts['profile_tab'] ?? 'team')),
      'org_tab' => sanitize_key((string)($atts['org_tab'] ?? 'org')),
    ]);

    $rows = $this->sort_directory_rows($rows, $sort, $order);

    ob_start();
    $title = $is_employee_list ? 'Employee List' : ($mode === 'terminated' ? 'Terminated Users' : 'My Team');
    $subtitle = $is_employee_list
      ? 'Browse the global employee directory. Edit is available when you manage the employee.'
      : ($mode === 'terminated'
      ? 'Separated, simpler management for rehirable and non-rehireable former employees.'
      : 'A cleaner directory of your active team members.');
    echo '<section class="eum-directory eum-directory--' . esc_attr($mode) . '" data-eum-ajax-region="manager-directory">';
    echo '<div class="eum-directory__hero"><div><h3>' . esc_html($title) . '</h3><p>' . esc_html($subtitle) . '</p></div></div>';

    if ($show_filters) {
      echo $this->render_directory_filters($q, $job_filters, $group_filter, $include_indirect, $report_scope, $sort, $order, $limit, $mode, $include_terminated);
    }

    echo $this->render_team_bulk_notice();
    echo $this->render_email_list_result();

    if (empty($rows)) {
      echo '<div class="eum-directory__empty">No matching users were found.</div>';
    } else {
      if ($is_employee_list) {
        $bulk_form_id = 'eum-manager-employee-list-form-' . wp_rand(1000, 999999);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-directory__bulk-form" id="' . esc_attr($bulk_form_id) . '">';
        echo '<input type="hidden" name="action" value="eum_manager_team_bulk_edit"><input type="hidden" name="return_url" value="' . esc_attr($current_url) . '"><input type="hidden" name="directory_mode" value="employee"><input type="hidden" name="directory_limit" value="' . (int)$limit . '"><input type="hidden" name="include_terminated" value="' . ($include_terminated ? '1' : '0') . '">';
        wp_nonce_field('eum_manager_team_bulk_edit', 'eum_manager_team_bulk_nonce');
        echo $this->render_team_email_export_controls();
        echo $this->render_employee_bulk_login_redirect_controls(get_current_user_id(), $rows);
        echo $this->render_directory_table('Employee List', $rows, true, true, true);
        echo '</form>';
      } elseif ($mode === 'terminated') {
        $rehire = array_values(array_filter($rows, static fn($row) => !empty($row['rehirable'])));
        $nonrehire = array_values(array_filter($rows, static fn($row) => empty($row['rehirable'])));
        $bulk_form_id = 'eum-manager-terminated-email-form-' . wp_rand(1000, 999999);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-directory__bulk-form" id="' . esc_attr($bulk_form_id) . '">';
        echo '<input type="hidden" name="action" value="eum_manager_team_bulk_edit"><input type="hidden" name="return_url" value="' . esc_attr($current_url) . '"><input type="hidden" name="directory_mode" value="terminated"><input type="hidden" name="directory_limit" value="' . (int)$limit . '">';
        wp_nonce_field('eum_manager_team_bulk_edit', 'eum_manager_team_bulk_nonce');
        echo $this->render_team_email_export_controls();
        echo $this->render_directory_table('Rehirable', $rehire, true, true);
        echo $this->render_directory_table('Non-Rehireable', $nonrehire, true, true);
        echo '</form>';
      } else {
        $bulk_form_id = 'eum-manager-team-bulk-form-' . wp_rand(1000, 999999);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-directory__bulk-form" id="' . esc_attr($bulk_form_id) . '">';
        echo '<input type="hidden" name="action" value="eum_manager_team_bulk_edit">';
        echo '<input type="hidden" name="return_url" value="' . esc_attr($current_url) . '">';
        echo '<input type="hidden" name="directory_mode" value="active"><input type="hidden" name="directory_limit" value="' . (int)$limit . '">';
        wp_nonce_field('eum_manager_team_bulk_edit', 'eum_manager_team_bulk_nonce');
        echo $this->render_team_email_export_controls();
        echo $this->render_team_bulk_edit_panel(get_current_user_id(), $rows, $bulk_form_id);
        echo $this->render_directory_table('Active Team', $rows, false, true);
        echo '</form>';
      }
    }

    if ($this->is_truthy($atts['show_profile'] ?? '1') && class_exists('EUM_Profiles') && isset($_GET[$query_var])) {
      echo '<div class="eum-directory__profile">' . (new EUM_Profiles())->shortcode_private_profile([
        'user_id' => sanitize_text_field((string)$_GET[$query_var]),
        'query_var' => $query_var,
        'title' => (string)($atts['profile_title'] ?? 'Profile'),
      ]) . '</div>';
    }

    echo '</section>';
    return ob_get_clean();
  }

  private function collect_directory_rows(string $mode, array $args): array {
    if (!is_user_logged_in()) return [];
    if (!class_exists('EUM_Manager_IDs')) return [];

    $current_user_id = get_current_user_id();
    $is_privileged = class_exists('EUM_Access') && EUM_Access::is_it_or_hr($current_user_id);
    $mgr = new EUM_Manager_IDs();
    $managed_manager_ids = $this->get_manager_ids_for_manager_user($current_user_id);
    $include_indirect = !empty($args['include_indirect']);
    $global_employee_list = !empty($args['global_employee_list']);
    $report_scope = in_array((string)($args['report_scope'] ?? 'all'), ['all', 'direct', 'indirect'], true) ? (string)$args['report_scope'] : 'all';
    $managed_levels = class_exists('EUM_Manager_IDs') ? EUM_Manager_IDs::managed_user_levels_for_manager_user($current_user_id, true, $include_indirect) : [];
    if (!$is_privileged && !$global_employee_list && empty($managed_manager_ids) && empty($managed_levels)) return [];

    $rows = [];
    foreach (get_users(['fields' => ['ID','user_login','user_email','display_name']]) as $u) {
      $uid = (int)$u->ID;
      if ($uid === $current_user_id && !$global_employee_list) continue;

      $effective_mid = (int)$mgr->resolve_effective_manager_id_for_user($uid);
      $direct_manager_id = class_exists('EUM_Personnel') ? EUM_Personnel::effective_manager_user_id($uid) : 0;
      $is_pending_manager = class_exists('EUM_Profiles') && EUM_Profiles::is_pending_manager_for_target($current_user_id, $uid);
      $report_level = isset($managed_levels[$uid]) ? (int)$managed_levels[$uid] : 0;
      if ($report_level <= 0 && !$include_indirect && class_exists('EUM_Manager_IDs')) {
        $report_level = EUM_Manager_IDs::user_is_direct_manager_of_user($current_user_id, $uid) ? 1 : 0;
      }
      if ($is_pending_manager && $report_level <= 0) $report_level = 1;

      if (!$is_privileged && !$global_employee_list) {
        if ($report_level <= 0 && $direct_manager_id !== $current_user_id) continue;
        if ($report_scope === 'direct' && $report_level !== 1) continue;
        if ($report_scope === 'indirect' && $report_level < 2) continue;
      } elseif (!$is_privileged && $report_scope !== 'all') {
        if ($report_scope === 'direct' && $report_level !== 1) continue;
        if ($report_scope === 'indirect' && $report_level < 2) continue;
      }

      $is_terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($uid);
      if ($mode === 'active' && $is_terminated) continue;
      if ($mode === 'terminated' && !$is_terminated) continue;

      $job_path = $this->user_tax_path($uid, 'eum_job_path', ' / ');
      $location = $this->user_tax_path($uid, 'eum_location', ' / ');
      $employee_id = class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_id_for_user($uid) : '';
      if (!empty($args['q'])) {
        $haystack = strtolower(trim(($u->display_name ?? '') . ' ' . ($u->user_login ?? '') . ' ' . ($u->user_email ?? '') . ' ' . $employee_id . ' ' . $job_path . ' ' . $location . ' ' . $this->report_level_label($report_level)));
        if (strpos($haystack, strtolower((string)$args['q'])) === false) continue;
      }
      $job_filters = $this->sanitize_job_path_filter_ids($args['job_filters'] ?? []);
      if (!empty($job_filters) && !$this->user_matches_job_filters($uid, $job_filters)) continue;
      if ((int)($args['group_filter'] ?? 0) > 0 && !$this->user_matches_group_filter($uid, (int)$args['group_filter'])) continue;

      $user_ref = $employee_id !== '' ? $employee_id : $uid;
      $profile_url = $this->build_target_url((string)$args['profile_page_url'], [
        (string)$args['profile_query_var'] => $user_ref,
        'eum_tab' => (string)$args['profile_tab'],
      ], [(string)$args['profile_query_var'], 'eum_tab', (string)$args['org_query_var']]);
      $files_url = $this->build_target_url((string)($args['files_page_url'] !== '' ? $args['files_page_url'] : $args['profile_page_url']), [
        (string)$args['profile_query_var'] => $user_ref,
        'eum_tab' => (string)$args['profile_tab'],
      ], [(string)$args['profile_query_var'], 'eum_tab', (string)$args['org_query_var']]) . '#eum-personnel-section-' . $uid;
      $org_url = $this->build_target_url((string)$args['org_page_url'], [
        (string)$args['org_query_var'] => $user_ref,
        'eum_tab' => (string)$args['org_tab'],
      ], [(string)$args['org_query_var'], 'eum_tab', (string)$args['profile_query_var']]);

      $rows[] = [
        'ID' => $uid,
        'name' => $u->display_name ?: $u->user_login,
        'employee_id' => $employee_id,
        'email' => $u->user_email,
        'job_path' => $job_path,
        'location' => $location,
        'manager' => $effective_mid > 0 ? get_the_title($effective_mid) : '',
        'manager_id' => $effective_mid,
        'report_level' => $report_level,
        'report_level_label' => $this->report_level_label($report_level),
        'profile_url' => $profile_url,
        'files_url' => $files_url,
        'org_url' => $org_url,
        'is_terminated' => $is_terminated,
        'rehirable' => $is_terminated && class_exists('EUM_Personnel') ? EUM_Personnel::is_rehirable($uid) : false,
        'terminated_at' => $is_terminated && class_exists('EUM_Personnel') ? (string)EUM_Personnel::terminated_at($uid) : '',
      ];
      if (count($rows) >= (int)$args['limit']) break;
    }
    return $rows;
  }

  private function render_directory_filters(string $q, array $job_filters, int $group_filter, bool $include_indirect, string $report_scope, string $sort, string $order, int $limit, string $mode, bool $include_terminated = false): string {
    $job_filters = $this->sanitize_job_path_filter_ids($job_filters);
    ob_start();
    echo '<form method="get" class="eum-directory__filters" data-eum-save-filters="1" data-eum-filter-context="manager-directory">';
    foreach ($_GET as $key => $value) {
      if (in_array($key, ['eum_q', 'eum_job_path_filter', 'eum_user_group_filter', 'eum_include_indirect', 'eum_include_terminated', 'eum_report_scope', 'eum_sort', 'eum_order', 'eum_limit', 'eum_email_list', 'eum_team_msg', 'eum_team_type'], true)) continue;
      $this->render_hidden_query_inputs((string)$key, $value);
    }
    echo '<div class="eum-directory__filter-grid">';
    echo '<label><span>Search</span><input type="text" name="eum_q" value="' . esc_attr($q) . '" placeholder="Search people, email, path, or location"></label>';
    echo '<div class="eum-directory__filter-field eum-directory__filter-field--paths"><span>Job Paths</span>' . $this->render_job_path_filter_picker_group($job_filters) . '</div>';
    echo '<label><span>User Group</span><select name="eum_user_group_filter"><option value="0">All groups</option>';
    foreach ($this->user_group_filter_options() as $term_id => $label) echo '<option value="' . (int)$term_id . '" ' . selected($group_filter, (int)$term_id, false) . '>' . esc_html($label) . '</option>';
    echo '</select></label>';
    echo '<label><span>Report Scope</span><select name="eum_report_scope"><option value="all"' . selected($report_scope, 'all', false) . '>All visible reports</option><option value="direct"' . selected($report_scope, 'direct', false) . '>Direct reports only</option><option value="indirect"' . selected($report_scope, 'indirect', false) . '>Only second+ level</option></select></label>';
    echo '<label><span>Sort</span><select name="eum_sort"><option value="name"' . selected($sort, 'name', false) . '>Name</option><option value="employee_id"' . selected($sort, 'employee_id', false) . '>Employee ID</option><option value="email"' . selected($sort, 'email', false) . '>Email</option><option value="job_path"' . selected($sort, 'job_path', false) . '>Job Path</option><option value="location"' . selected($sort, 'location', false) . '>Location</option><option value="manager"' . selected($sort, 'manager', false) . '>Manager</option><option value="status"' . selected($sort, 'status', false) . '>Status</option></select></label>';
    echo '<label><span>Order</span><select name="eum_order"><option value="asc"' . selected($order, 'asc', false) . '>Ascending</option><option value="desc"' . selected($order, 'desc', false) . '>Descending</option></select></label>';
    echo '<label><span>Show</span><input type="number" name="eum_limit" value="' . (int)$limit . '" min="10" max="500"></label>';
    echo '<label style="display:flex;align-items:flex-end;gap:8px;"><input type="checkbox" name="eum_include_indirect" value="1"' . checked($include_indirect, true, false) . '><span>Show second/third/fourth level reports</span></label>';
    if ($mode === 'employee') echo '<label style="display:flex;align-items:flex-end;gap:8px;"><input type="checkbox" name="eum_include_terminated" value="1"' . checked($include_terminated, true, false) . '><span>Include terminated users</span></label>';
    echo '</div>';
    echo '<div class="eum-directory__filter-actions"><button type="submit" class="button button-primary">Apply</button><a class="button" href="' . esc_url(remove_query_arg(['eum_q', 'eum_job_path_filter', 'eum_user_group_filter', 'eum_include_indirect', 'eum_include_terminated', 'eum_report_scope', 'eum_sort', 'eum_order', 'eum_limit'])) . '">Reset</a></div>';
    echo '</form>';
    return ob_get_clean();
  }

  private function render_directory_table(string $title, array $rows, bool $terminated, bool $bulk_selectable = false, bool $global_employee_list = false): string {
    if (empty($rows)) return '';
    ob_start();
    echo '<section class="eum-directory__panel">';
    echo '<div class="eum-directory__panel-head"><h4>' . esc_html($title) . '</h4><span>' . count($rows) . ' people</span></div>';
    echo '<div class="eum-directory__table-wrap"><table class="eum-directory__table"><thead><tr>';
    if ($bulk_selectable) echo '<th class="eum-directory__select-col"><input type="checkbox" data-eum-select-all="1" aria-label="Select all visible users"></th>';
    echo '<th>Person</th><th>Employee ID</th><th>Job Path</th><th>Location</th><th>Manager</th><th>Relationship</th>' . ($terminated ? '<th>Status</th>' : '') . '<th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $manager_label = $row['manager'] !== '' ? $row['manager'] . ($row['manager_id'] ? ' (#' . (int)$row['manager_id'] . ')' : '') : '—';
      $is_terminated = !empty($row['is_terminated']);
      $status = !$is_terminated ? 'Active' : ($row['rehirable'] ? 'Terminated · Rehirable' : 'Terminated · Non-rehireable');
      if ($row['terminated_at'] !== '') $status .= ' · ' . $row['terminated_at'];
      $avatar = class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html((int)$row['ID'], 36, 'eum-directory__avatar') : '';
      echo '<tr data-eum-row-id="' . (int)$row['ID'] . '">';
      if ($bulk_selectable) echo '<td class="eum-directory__select-col"><input type="checkbox" name="selected_user_ids[]" value="' . (int)$row['ID'] . '" data-eum-row-select="1"></td>';
      echo '<td><div class="eum-directory__person">' . $avatar . '<div class="eum-directory__person-details"><strong>' . esc_html($row['name']) . '</strong><span>' . esc_html($row['email']) . '</span></div></div></td>';
      echo '<td>' . esc_html((string)($row['employee_id'] ?? '')) . '</td>';
      echo '<td>' . esc_html($row['job_path'] !== '' ? '/ ' . ltrim($row['job_path'], '/ ') : '—') . '</td>';
      echo '<td>' . esc_html($row['location'] !== '' ? '/ ' . ltrim($row['location'], '/ ') : '—') . '</td>';
      if ((int)$row['manager_id'] > 0 && class_exists('EUM_Profiles')) {
        echo '<td><div class="eum-directory__manager">' . EUM_Profiles::profile_avatar_html((int)$row['manager_id'], 26, 'eum-directory__avatar eum-directory__avatar--small') . '<span>' . esc_html($manager_label) . '</span></div></td>';
      } else {
        echo '<td>' . esc_html($manager_label) . '</td>';
      }
      echo '<td>' . esc_html((string)($row['report_level_label'] ?? '—')) . '</td>';
      if ($terminated) echo '<td>' . esc_html($status) . '</td>';
      echo '<td><div class="eum-directory__actions">';
      $actor_user_id = get_current_user_id();
      $target_user_id = (int)$row['ID'];
      $can_edit = $actor_user_id !== $target_user_id && class_exists('EUM_Profiles')
        && EUM_Profiles::can_edit_target($actor_user_id, $target_user_id)
        && !empty(EUM_Profiles::editable_fields_for_actor($actor_user_id, $target_user_id));
      if (!$global_employee_list || $can_edit) {
        echo '<a class="button" href="' . esc_url($row['profile_url']) . '">' . ($can_edit ? 'Edit' : 'Profile') . '</a>';
        echo '<a class="button" href="' . esc_url($row['files_url']) . '">Files</a><a class="button" href="' . esc_url($row['org_url']) . '">Org</a>';
      } else {
        echo '<span class="description">—</span>';
      }
      echo '</div></td>';
      echo '</tr>';
    }
    echo '</tbody></table></div></section>';
    return ob_get_clean();
  }

  private function render_job_path_filter_picker_group(array $selected_ids): string {
    $selected_ids = $this->sanitize_job_path_filter_ids($selected_ids);
    $list_id = 'eum-job-path-filter-list-' . wp_rand(1000, 999999);
    $popup_id = 'eum-job-path-filter-popup-' . wp_rand(1000, 999999);
    $picker_name = 'eum_job_path_filter_picker_' . wp_rand(1000, 999999);
    $is_open = !empty($selected_ids) ? ' open' : '';

    ob_start();
    echo '<details class="eum-directory__path-filter"' . $is_open . '>';
    echo '<summary>Job Path Filters</summary>';
    echo '<div class="eum-directory__path-filter-body">';
    echo '<div class="eum-directory__path-filter-list" id="' . esc_attr($list_id) . '" data-eum-job-filter-list="1">';
    if (empty($selected_ids)) {
      echo '<div class="eum-directory__path-filter-empty">No job path filters selected yet.</div>';
    } else {
      foreach ($selected_ids as $term_id) {
        echo $this->render_job_path_filter_pill($term_id, true);
      }
    }
    echo '</div>';
    echo '<div class="eum-directory__path-filter-actions">';
    echo '<button type="button" class="button" data-eum-popup-target="' . esc_attr($popup_id) . '" data-eum-popup-title="Add Job Path Filter">Add Job Path</button>';
    echo '</div>';
    echo '<p class="description">Use the popup explorer to add one or more Job Paths. Uncheck a path to remove it from the filter set.</p>';
    echo '</div>';
    echo '</details>';

    echo '<div id="' . esc_attr($popup_id) . '" hidden>';
    echo '<div class="eum-directory__job-filter-popup" data-eum-job-filter-popup="1" data-eum-job-filter-list-selector="#' . esc_attr($list_id) . '" data-eum-job-filter-input-name="' . esc_attr($picker_name) . '">';
    if (class_exists('EUM_Profiles')) {
      echo EUM_Profiles::render_job_path_directory_picker($picker_name, 0, get_current_user_id(), [
        'placeholder' => '— Select a Job Path —',
        'required' => false,
        'none_label' => '— None —',
        'enforce_manageable_job_paths' => false,
      ]);
    } else {
      echo '<p class="description">Job Path picker unavailable.</p>';
    }
    echo '<p style="margin-top:12px;"><button type="button" class="button button-primary" data-eum-job-filter-confirm="1">Add Selected Path</button></p>';
    echo '</div>';
    echo '</div>';

    return ob_get_clean();
  }

  private function render_job_path_filter_pill(int $term_id, bool $checked = true): string {
    $term_id = (int)$term_id;
    if ($term_id <= 0) return '';
    $label = $this->job_path_filter_term_label($term_id);
    ob_start();
    echo '<div class="eum-directory__path-pill" data-eum-job-filter-pill="1" data-term-id="' . (int)$term_id . '">';
    echo '<label><input type="checkbox" name="eum_job_path_filter[]" value="' . (int)$term_id . '"' . checked($checked, true, false) . '> <span>' . esc_html('/ ' . ltrim($label, '/ ')) . '</span></label>';
    echo '<button type="button" class="eum-directory__path-pill-remove" data-eum-job-filter-remove="1" aria-label="Remove path">×</button>';
    echo '</div>';
    return ob_get_clean();
  }

  private function job_path_filter_term_label(int $term_id): string {
    $term_id = (int)$term_id;
    if ($term_id <= 0 || !taxonomy_exists('eum_job_path')) return 'Job Path #' . $term_id;
    if (class_exists('EUM_Profiles')) {
      $label = EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ');
      if ($label !== '') return $label;
    }
    $term = get_term($term_id, 'eum_job_path');
    if (!$term || is_wp_error($term)) return 'Job Path #' . $term_id;
    return (string)$term->name;
  }


  private function sanitize_job_path_filter_ids($raw): array {
    if (is_array($raw)) {
      $ids = $raw;
    } elseif ($raw === '' || $raw === null) {
      $ids = [];
    } else {
      $ids = preg_split('/[\s,]+/', (string)$raw);
    }
    return array_values(array_unique(array_filter(array_map('intval', (array)$ids), static fn($id) => $id > 0)));
  }

  private function user_matches_job_filters(int $user_id, array $term_ids): bool {
    $term_ids = $this->sanitize_job_path_filter_ids($term_ids);
    if (empty($term_ids)) return true;
    foreach ($term_ids as $term_id) {
      if ($this->user_matches_job_filter($user_id, (int)$term_id)) return true;
    }
    return false;
  }

  private function user_group_filter_options(): array {
    if (!taxonomy_exists('eum_user_group')) return [];
    $terms = get_terms(['taxonomy' => 'eum_user_group', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
    if (is_wp_error($terms)) return [];
    $out = [];
    foreach ($terms as $term) $out[(int)$term->term_id] = (string)$term->name;
    return $out;
  }

  private function user_matches_group_filter(int $user_id, int $term_id): bool {
    if ($user_id <= 0 || $term_id <= 0 || !taxonomy_exists('eum_user_group')) return false;
    $groups = wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']);
    return !is_wp_error($groups) && in_array($term_id, array_map('intval', (array)$groups), true);
  }

  private function render_hidden_query_inputs(string $key, $value): void {
    if (is_array($value)) {
      foreach ($value as $item) {
        $this->render_hidden_query_inputs($key . '[]', $item);
      }
      return;
    }
    if (!is_scalar($value)) return;
    echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)$value) . '">';
  }

  private function report_level_label(int $level): string {
    $level = (int)$level;
    if ($level <= 0) return '—';
    if ($level === 1) return 'Direct';
    return 'Level ' . $level;
  }

  private function render_team_bulk_notice(): string {
    $msg = isset($_GET['eum_team_msg']) ? sanitize_text_field(wp_unslash((string)$_GET['eum_team_msg'])) : '';
    if ($msg === '') return '';
    $type = isset($_GET['eum_team_type']) ? sanitize_key((string)$_GET['eum_team_type']) : 'success';
    $class = 'notice notice-success';
    if ($type === 'error') $class = 'notice notice-error';
    elseif ($type === 'warning') $class = 'notice notice-warning';
    return '<div class="' . esc_attr($class) . '"><p>' . esc_html($msg) . '</p></div>';
  }

  private function render_team_email_export_controls(): string {
    ob_start();
    echo '<section class="eum-directory__panel eum-directory__email-export">';
    echo '<div class="eum-directory__panel-head"><h4>Export Email Addresses</h4><span>Selected people</span></div>';
    echo '<div class="eum-directory__email-export-body">';
    echo '<label><span>Email list</span><select name="team_email_list_type">';
    echo '<option value="bosses_company">Bosses’ company emails (unique)</option>';
    echo '<option value="users_personal">Selected users’ personal emails</option>';
    echo '<option value="bosses_personal">Bosses’ personal emails (unique)</option>';
    echo '<option value="users_company">Selected users’ company emails</option>';
    echo '</select></label>';
    echo '<button type="submit" class="button button-primary" name="team_bulk_action" value="email_list">Create comma-separated list</button>';
    echo '<p class="description">Select people in the table, then copy the generated list into your email software. Duplicate addresses are removed.</p>';
    echo '</div></section>';
    return ob_get_clean();
  }

  private function render_employee_bulk_login_redirect_controls(int $actor_user_id, array $rows): string {
    if (!class_exists('EUM_Access') || !class_exists('EUM_Profiles')) return '';

    $reason_options = [];
    foreach ($rows as $row) {
      $target_user_id = (int)($row['ID'] ?? 0);
      if ($target_user_id <= 0 || $target_user_id === $actor_user_id) continue;
      if (!EUM_Access::is_it_or_hr($actor_user_id) && !EUM_Profiles::can_edit_target($actor_user_id, $target_user_id)) continue;
      $reason_options = EUM_Profiles::reason_options_for_actor($actor_user_id, $target_user_id);
      if (!empty($reason_options)) break;
    }
    if (empty($reason_options)) return '';

    ob_start();
    echo '<section class="eum-directory__panel eum-directory__bulk-login-redirect">';
    echo '<div class="eum-directory__panel-head"><h4>Bulk Next Sign-in Destination</h4><span>Selected people</span></div>';
    echo '<div style="padding:16px;display:grid;gap:12px;">';
    echo '<p class="description" style="margin:0;">Set or clear a one-time destination for selected employees. The destination is used after their next successful sign-in. You can update employees within your management scope; HR and IT can update any employee except themselves.</p>';
    echo '<div class="eum-directory__filter-grid">';
    echo '<label><span>Action</span><select name="bulk_login_redirect_action"><option value="set">Set destination</option><option value="clear">Clear destination</option></select></label>';
    echo '<label><span>Destination URL</span><input type="text" name="bulk_login_redirect_url" placeholder="https://internal.example/page/ or /page/"><span class="description">Required when setting a destination.</span></label>';
    echo '<label><span>Reason</span><select name="bulk_reason" required><option value="">— Select a reason —</option>';
    foreach ($reason_options as $reason) echo '<option value="' . esc_attr($reason) . '">' . esc_html($reason) . '</option>';
    echo '</select></label>';
    echo '</div>';
    echo '<div class="eum-directory__filter-actions"><button type="submit" class="button button-primary">Apply to Selected Employees</button></div>';
    echo '</div></section>';
    return ob_get_clean();
  }

  private function render_email_list_result(): string {
    if (!is_user_logged_in()) return '';
    $token = isset($_GET['eum_email_list']) ? sanitize_key(wp_unslash((string)$_GET['eum_email_list'])) : '';
    if (!preg_match('/^[a-z0-9]{32}$/', $token)) return '';
    $result = get_transient('eum_manager_email_list_' . get_current_user_id() . '_' . $token);
    if (!is_array($result) || (int)($result['owner_user_id'] ?? 0) !== get_current_user_id()) return '';

    $email_text = (string)($result['email_text'] ?? '');
    $label = (string)($result['label'] ?? 'Email addresses');
    $count = (int)($result['count'] ?? 0);
    $missing = (int)($result['missing'] ?? 0);
    ob_start();
    echo '<section class="eum-directory__panel eum-directory__email-result" aria-live="polite">';
    echo '<div class="eum-directory__panel-head"><h4>' . esc_html($label) . '</h4><span>' . esc_html((string)$count) . ' addresses</span></div>';
    echo '<div class="eum-directory__email-result-body">';
    echo '<textarea readonly rows="3" data-eum-email-list-text="1" aria-label="Comma-separated email addresses">' . esc_textarea($email_text) . '</textarea>';
    echo '<div class="eum-directory__email-result-actions"><button type="button" class="button button-primary" data-eum-copy-email-list="1">Copy email list</button><span data-eum-copy-email-status="1" role="status"></span></div>';
    if ($missing > 0) echo '<p class="description">' . esc_html((string)$missing) . ' selected people did not have a valid email address for this list.</p>';
    echo '</div></section>';
    return ob_get_clean();
  }

  private function directory_rows_for_email_export(string $mode, string $return_url, int $limit): array {
    $query = (string)wp_parse_url($return_url, PHP_URL_QUERY);
    $query_args = [];
    if ($query !== '') parse_str($query, $query_args);
    $report_scope = sanitize_key((string)($query_args['eum_report_scope'] ?? 'all'));
    if (!in_array($report_scope, ['all', 'direct', 'indirect'], true)) $report_scope = 'all';
    $collect_mode = $mode === 'employee'
      ? (!empty($query_args['eum_include_terminated']) && $this->is_truthy((string)$query_args['eum_include_terminated']) ? 'all' : 'active')
      : $mode;

    return $this->collect_directory_rows($collect_mode, [
      'q' => sanitize_text_field((string)($query_args['eum_q'] ?? '')),
      'job_filters' => $this->sanitize_job_path_filter_ids($query_args['eum_job_path_filter'] ?? []),
      'group_filter' => max(0, (int)($query_args['eum_user_group_filter'] ?? 0)),
      'include_indirect' => !empty($query_args['eum_include_indirect']) && $this->is_truthy((string)$query_args['eum_include_indirect']),
      'report_scope' => $report_scope,
      'global_employee_list' => $mode === 'employee',
      'limit' => max(10, min(500, $limit)),
      'profile_page_url' => $return_url,
      'files_page_url' => $return_url,
      'org_page_url' => $return_url,
      'profile_query_var' => 'eum_profile_user',
      'org_query_var' => 'eum_org_user',
      'profile_tab' => 'team',
      'org_tab' => 'org',
    ]);
  }

  private function handle_team_email_list_export(int $actor_user_id, array $selected_ids, string $return_url): void {
    $mode = sanitize_key((string)wp_unslash($_POST['directory_mode'] ?? ''));
    $list_type = sanitize_key((string)wp_unslash($_POST['team_email_list_type'] ?? ''));
    $allowed_types = [
      'bosses_company' => 'Bosses’ Company Emails',
      'users_personal' => 'Selected Users’ Personal Emails',
      'bosses_personal' => 'Bosses’ Personal Emails',
      'users_company' => 'Selected Users’ Company Emails',
    ];
    if (!in_array($mode, ['active', 'terminated', 'employee'], true) || !isset($allowed_types[$list_type])) {
      wp_safe_redirect(add_query_arg(['eum_team_msg' => 'Choose a valid user list and directory.', 'eum_team_type' => 'error'], $return_url));
      exit;
    }
    if (count($selected_ids) > 500) {
      wp_safe_redirect(add_query_arg(['eum_team_msg' => 'Select no more than 500 users at a time.', 'eum_team_type' => 'error'], $return_url));
      exit;
    }

    $limit = max(10, min(500, (int)($_POST['directory_limit'] ?? 100)));
    $visible_rows = $this->directory_rows_for_email_export($mode, $return_url, $limit);
    $visible_ids = array_map(static fn($row) => (int)$row['ID'], $visible_rows);
    if (array_diff($selected_ids, $visible_ids)) {
      wp_die('One or more selected users are outside your visible directory. Reload the directory and try again.', 'Unable to export email addresses', ['response' => 403]);
    }

    $emails = [];
    $missing = 0;
    foreach ($selected_ids as $selected_id) {
      $source_user_id = $selected_id;
      if (strpos($list_type, 'bosses_') === 0) {
        $source_user_id = class_exists('EUM_Personnel') ? (int)EUM_Personnel::effective_manager_user_id($selected_id) : 0;
      }
      $source_user = $source_user_id > 0 ? get_userdata($source_user_id) : false;
      $email = '';
      if ($source_user) {
        if (strpos($list_type, '_personal') !== false) {
          $meta = get_user_meta($source_user_id, EUM_Fields::USER_META_KEY, true);
          $email = is_array($meta) ? (string)($meta['personal_email'] ?? '') : '';
        } else {
          $email = (string)$source_user->user_email;
        }
      }
      $email = sanitize_email($email);
      if ($email === '' || !is_email($email)) {
        $missing++;
        continue;
      }
      $key = strtolower($email);
      $emails[$key] = $email;
    }

    $token = strtolower(wp_generate_password(32, false, false));
    $transient_key = 'eum_manager_email_list_' . $actor_user_id . '_' . $token;
    set_transient($transient_key, [
      'owner_user_id' => $actor_user_id,
      'label' => $allowed_types[$list_type],
      'email_text' => implode(', ', array_values($emails)),
      'count' => count($emails),
      'missing' => $missing,
    ], 30 * MINUTE_IN_SECONDS);

    wp_safe_redirect(add_query_arg('eum_email_list', $token, $return_url));
    exit;
  }

  private function render_team_bulk_edit_panel(int $actor_user_id, array $rows, string $form_id = ''): string {
    $field_defs = $this->manager_bulk_editable_field_defs($actor_user_id);
    if (empty($field_defs) || empty($rows)) return '';
    $show_skip_flags = class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id);
    $modal_id = 'eum-team-bulk-modal-' . wp_rand(1000, 999999);

    $grouped = [];
    foreach ($field_defs as $field_id => $def) {
      $group = sanitize_text_field((string)($def['group'] ?? 'Profile Fields'));
      if ($group === '') $group = 'Profile Fields';
      if (!isset($grouped[$group])) $grouped[$group] = [];
      $grouped[$group][$field_id] = $def;
    }

    ob_start();
    echo '<section class="eum-directory__panel eum-directory__bulk-launcher">';
    echo '<div class="eum-directory__panel-head"><h4>Bulk Edit Team</h4><span data-eum-team-selected-count="1">0 selected</span></div>';
    echo '<div style="padding:16px;display:grid;gap:14px;">';
    echo '<p class="description" style="margin:0;">Highlight the people you want to edit, then use Edit Users to open a ghost profile editor. Only the fields you fill in will be applied.</p>';
    echo '<div class="eum-directory__bulk-toolbar">';
    echo '<label style="display:flex;align-items:center;gap:8px;font-weight:600;"><input type="checkbox" data-eum-select-all="1"> Select all visible filtered people</label>';
    echo '<button type="button" class="button button-primary" data-eum-team-bulk-open="1" data-eum-team-bulk-modal-target="' . esc_attr($modal_id) . '" disabled>Edit Users</button>';
    echo '</div>';
    echo '</div></section>';

    echo '<div id="' . esc_attr($modal_id) . '" class="eum-team-bulk-modal" data-eum-team-bulk-modal="1" hidden>';
    echo '<div class="eum-team-bulk-modal__backdrop" data-eum-team-bulk-close="1"></div>';
    echo '<div class="eum-team-bulk-modal__panel" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr($modal_id . '-title') . '">';
    echo '<div class="eum-team-bulk-modal__header"><div><h3 id="' . esc_attr($modal_id . '-title') . '" style="margin:0;">Edit Users</h3><p class="description" style="margin:6px 0 0;">Using the same profile editor fields, but as a ghost profile. Blank fields are ignored.</p></div><button type="button" class="eum-team-bulk-modal__close" data-eum-team-bulk-close="1" aria-label="Close">×</button></div>';
    echo '<div class="eum-team-bulk-modal__body">';
    echo '<p class="eum-team-bulk-modal__selected"><strong data-eum-team-selected-count="1">0 selected</strong><span data-eum-team-selected-help="1">Choose at least one visible person to enable bulk editing.</span></p>';
    if ($show_skip_flags) {
      echo '<div class="eum-team-bulk-modal__flags">';
      echo '<label><input type="checkbox" name="skip_log" value="1" checked> Skip audit log</label>';
      echo '<label><input type="checkbox" name="skip_email" value="1" checked> Skip email notification</label>';
      echo '</div>';
    }
    $reason_target_id = !empty($rows[0]['ID']) ? (int)$rows[0]['ID'] : 0;
    $reason_options = class_exists('EUM_Profiles') ? EUM_Profiles::reason_options_for_actor($actor_user_id, $reason_target_id) : [];
    echo '<label style="display:block;margin:0 0 14px;"><strong>Change reason</strong><br><select name="bulk_reason" style="min-width:280px;max-width:520px;"><option value="">— Select a reason for the changes —</option>';
    foreach ($reason_options as $reason) echo '<option value="' . esc_attr($reason) . '">' . esc_html($reason) . '</option>';
    echo '</select><span class="description" style="display:block;margin-top:4px;">One reason is applied to every selected change.</span></label>';
    echo '<section class="eum-team-bulk-modal__section"><h4>One-time Sign-in Destination</h4>';
    echo '<p class="description">The same reason applies to every selected user. Sign-in destination changes are always recorded.</p>';
    echo '<div class="eum-directory__filter-grid eum-team-bulk-modal__grid">';
    echo '<label class="eum-team-bulk-modal__field"><span>Action</span><select name="bulk_login_redirect_action"><option value="none">Do not change</option><option value="set">Set destination</option><option value="clear">Clear destination</option></select></label>';
    echo '<label class="eum-team-bulk-modal__field"><span>Destination URL</span><input type="text" name="bulk_login_redirect_url" placeholder="https://internal.example/page/ or /page/"><span class="description">Used once after the next successful sign-in.</span></label>';
    echo '</div></section>';
    foreach ($grouped as $group_label => $defs) {
      echo '<section class="eum-team-bulk-modal__section">';
      echo '<h4>' . esc_html($group_label) . '</h4>';
      echo '<div class="eum-directory__filter-grid eum-team-bulk-modal__grid">';
      foreach ($defs as $field_id => $def) {
        echo '<div class="eum-team-bulk-modal__field">';
        echo '<div class="eum-team-bulk-modal__field-label">' . esc_html((string)($def['label'] ?? $field_id)) . '</div>';
        echo $this->render_bulk_ghost_field_input($field_id, $def, $actor_user_id);
        echo '</div>';
      }
      echo '</div>';
      echo '</section>';
    }
    echo '<div class="eum-directory__filter-actions eum-team-bulk-modal__actions">';
    echo '<button type="button" class="button" data-eum-team-bulk-close="1">Cancel</button>';
    echo '<button type="submit" class="button button-primary">Apply Changes to Selected Users</button>';
    echo '</div>';
    echo '</div></div></div>';
    return ob_get_clean();
  }

  private function manager_bulk_editable_field_defs(int $actor_user_id): array {
    if (!class_exists('EUM_Profiles')) return [];
    $catalog = EUM_Profiles::field_catalog();
    $allowed = method_exists('EUM_Profiles', 'manager_editable_field_ids') ? EUM_Profiles::manager_editable_field_ids() : (array)(EUM_Profiles::get_settings()['manager_edit_fields'] ?? []);
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) {
      $allowed = array_keys($catalog);
    }
    $defs = [];
    foreach (array_values(array_filter(array_map('strval', $allowed))) as $field_id) {
      if (!isset($catalog[$field_id])) continue;
      if (in_array($field_id, ['core:user_pass', 'core:user_email', 'core:job_company', 'custom:job_title'], true)) continue;
      $defs[$field_id] = $catalog[$field_id];
    }
    if (class_exists('EUM_Personnel')) {
      $defs['employment:terminated'] = ['label' => 'Termination Status', 'group' => 'Employment', 'type' => 'employment_status'];
      $defs['employment:rehirable'] = ['label' => 'Rehire Eligibility', 'group' => 'Employment', 'type' => 'employment_status'];
    }
    return $defs;
  }

  private function render_bulk_ghost_field_input(string $field_id, array $def, int $actor_user_id): string {
    $type = (string)($def['type'] ?? 'text');
    $name = 'ghost[' . $field_id . ']';

    if ($type === 'employment_status') {
      $yes_label = $field_id === 'employment:terminated' ? 'Terminated' : 'Yes';
      $no_label = $field_id === 'employment:terminated' ? 'Active' : 'No';
      return '<select name="' . esc_attr($name) . '" style="min-width:260px;max-width:420px;">'
        . '<option value="">— Leave unchanged —</option>'
        . '<option value="1">' . esc_html($yes_label) . '</option><option value="0">' . esc_html($no_label) . '</option></select>';
    }

    if ($field_id === 'tax:eum_job_path' && class_exists('EUM_Profiles')) {
      $picker = EUM_Profiles::render_job_path_directory_picker($name, 0, $actor_user_id, [
        'placeholder' => '— Leave unchanged —',
        'required' => false,
        'none_label' => '',
        'enforce_manageable_job_paths' => true,
        'job_title_input_name' => 'ghost[custom:job_title]',
        'job_title_current' => '',
      ]);
      $title = EUM_Profiles::render_job_title_select_html('ghost[custom:job_title]', '', 0, 'Selected in Job Path', false);
      return $picker . '<div style="margin-top:10px;"><span style="display:block;margin-bottom:6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;">Job Title</span>' . $title . '<span class="description" style="display:block;margin-top:6px;">Choose a title from the Job Path explorer above.</span></div>';
    }

    if ($field_id === 'custom:worker_type' && class_exists('EUM_Profiles')) {
      $html = '<select name="' . esc_attr($name) . '" style="min-width:260px;max-width:420px;">';
      $html .= '<option value="">— Leave unchanged —</option>';
      foreach (EUM_Profiles::worker_type_options() as $value => $label) {
        $html .= '<option value="' . esc_attr((string)$value) . '">' . esc_html((string)$label) . '</option>';
      }
      $html .= '</select>';
      return $html;
    }

    if ($type === 'taxonomy_single' && class_exists('EUM_Profiles')) {
      $tax = (string)($def['taxonomy'] ?? '');
      return EUM_Profiles::render_taxonomy_directory_picker($tax, $name, 0, $actor_user_id, [
        'placeholder' => '— Leave unchanged —',
        'required' => false,
        'none_label' => '',
      ]);
    }

    if ($type === 'taxonomy_multi') {
      $tax = (string)($def['taxonomy'] ?? '');
      $terms = $tax !== '' ? get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name']) : [];
      $html = '<select name="' . esc_attr($name) . '[]" multiple size="5" style="min-width:260px;max-width:420px;">';
      if (!is_wp_error($terms)) {
        foreach ((array)$terms as $term) {
          $html .= '<option value="' . (int)$term->term_id . '">' . esc_html((string)$term->name) . '</option>';
        }
      }
      $html .= '</select><br><span class="description">Selecting one or more values replaces this field on the selected people.</span>';
      return $html;
    }

    if ($type === 'user_select') {
      $users = get_users(['fields' => ['ID', 'display_name', 'user_login'], 'number' => 5000, 'orderby' => 'display_name', 'order' => 'ASC']);
      $html = '<select name="' . esc_attr($name) . '" style="min-width:260px;max-width:420px;">';
      $html .= '<option value="0">— Leave unchanged —</option>';
      foreach ((array)$users as $user) {
        $label = trim((string)$user->display_name) !== '' ? (string)$user->display_name : (string)$user->user_login;
        $html .= '<option value="' . (int)$user->ID . '">' . esc_html($label . ' (#' . (int)$user->ID . ')') . '</option>';
      }
      $html .= '</select>';
      return $html;
    }

    if ($type === 'division_select' && class_exists('EUM_Company_Divisions')) {
      $html = '<select name="' . esc_attr($name) . '" style="min-width:260px;max-width:420px;">';
      $html .= '<option value="">— Leave unchanged —</option>';
      foreach (EUM_Company_Divisions::options_for_select() as $division_key => $division_label) {
        $html .= '<option value="' . esc_attr((string)$division_key) . '">' . esc_html((string)$division_label) . '</option>';
      }
      $html .= '</select>';
      return $html;
    }

    if ($type === 'textarea') {
      return '<textarea name="' . esc_attr($name) . '" rows="3" style="width:100%;max-width:520px;" placeholder="Leave blank to ignore"></textarea>';
    }
    if ($type === 'number') {
      return '<input type="number" step="1" name="' . esc_attr($name) . '" value="" placeholder="Leave blank to ignore">';
    }
    if ($type === 'date') {
      return '<input type="date" name="' . esc_attr($name) . '" value="">';
    }
    if ($type === 'email') {
      return '<input type="email" name="' . esc_attr($name) . '" value="" placeholder="Leave blank to ignore">';
    }
    if ($type === 'url') {
      return '<input type="url" name="' . esc_attr($name) . '" value="" placeholder="Leave blank to ignore">';
    }
    return '<input type="text" name="' . esc_attr($name) . '" value="" placeholder="Leave blank to ignore">';
  }

  private function parse_team_bulk_payload(int $actor_user_id): array {
    if (!class_exists('EUM_Profiles')) return [];
    $payload = isset($_POST['ghost']) && is_array($_POST['ghost']) ? wp_unslash($_POST['ghost']) : [];
    if (empty($payload)) return [];

    $field_defs = $this->manager_bulk_editable_field_defs($actor_user_id);
    $out = [];
    foreach ($field_defs as $field_id => $def) {
      if (!array_key_exists($field_id, $payload)) continue;
      $type = (string)($def['type'] ?? 'text');
      $value = $payload[$field_id];
      if ($type === 'taxonomy_multi') {
        $vals = array_values(array_filter(array_map('intval', (array)$value), static fn($v) => $v > 0));
        if (!empty($vals)) $out[$field_id] = $vals;
        continue;
      }
      if ($type === 'taxonomy_single' || $type === 'user_select') {
        $term_id = max(0, (int)$value);
        if ($term_id > 0) $out[$field_id] = $term_id;
        continue;
      }
      if ($type === 'employment_status') {
        $scalar = is_scalar($value) ? (string)$value : '';
        if ($scalar === '0' || $scalar === '1') $out[$field_id] = $scalar;
        continue;
      }
      $scalar = is_scalar($value) ? trim((string)$value) : '';
      if ($scalar === '') continue;
      if ($type === 'date') $scalar = preg_replace('/[^0-9\-]/', '', $scalar);
      $out[$field_id] = $scalar;
    }

    if (array_key_exists('custom:job_title', $payload) && is_scalar($payload['custom:job_title'])) {
      $title_value = sanitize_text_field((string)$payload['custom:job_title']);
      if ($title_value !== '' || array_key_exists('tax:eum_job_path', $out)) $out['custom:job_title'] = $title_value;
    }

    return $out;
  }

  private function visual_bulk_row_from_profile_fields(array $field_values): array {
    $row = [];
    foreach ($field_values as $field_id => $value) {
      if (str_starts_with($field_id, 'custom:')) {
        $row[$field_id] = $value;
        continue;
      }
      switch ($field_id) {
        case 'core:display_name':
          $row['display_name'] = $value;
          break;
        case 'core:first_name':
          $row['first_name'] = $value;
          break;
        case 'core:last_name':
          $row['last_name'] = $value;
          break;
        case 'core:eum_profile_image_url':
          $row['profile_image_url'] = $value;
          break;
        case 'core:eum_profile_image_color':
          $row['profile_image_color'] = $value;
          break;
        case 'core:eum_manager_user_id':
          $row['manager_user_id'] = $value;
          break;
        case 'tax:eum_job_path':
          $row['job_path'] = $this->user_tax_path_from_term_id('eum_job_path', (int)$value, ' > ');
          break;
        case 'tax:eum_location':
          $row['location_path'] = $this->user_tax_path_from_term_id('eum_location', (int)$value, ' > ');
          break;
        case 'tax:eum_user_group':
          $row['user_groups'] = $this->flat_term_names_from_ids('eum_user_group', (array)$value, ';');
          break;
        case 'tax:eum_project':
          $row['projects'] = $this->flat_term_names_from_ids('eum_project', (array)$value, ';');
          break;
        case 'employment:terminated':
          $row['terminated'] = $value;
          break;
        case 'employment:rehirable':
          $row['rehirable'] = $value;
          break;
      }
    }
    return $row;
  }

  private function user_tax_path_from_term_id(string $taxonomy, int $term_id, string $sep = ' > '): string {
    if ($term_id <= 0 || !taxonomy_exists($taxonomy)) return '';
    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) return '';
    $anc = array_reverse(get_ancestors($term_id, $taxonomy));
    $names = [];
    foreach ($anc as $aid) {
      $ancestor = get_term((int)$aid, $taxonomy);
      if ($ancestor && !is_wp_error($ancestor)) $names[] = (string)$ancestor->name;
    }
    $names[] = (string)$term->name;
    return implode($sep, array_filter($names, static fn($name) => $name !== ''));
  }

  private function flat_term_names_from_ids(string $taxonomy, array $term_ids, string $sep = ';'): string {
    $names = [];
    foreach (array_values(array_filter(array_map('intval', $term_ids), static fn($id) => $id > 0)) as $term_id) {
      $term = get_term($term_id, $taxonomy);
      if ($term && !is_wp_error($term)) $names[] = (string)$term->name;
    }
    return implode($sep, $names);
  }

  private function record_bulk_change_entry(int $target_user_id, int $actor_user_id, array $after, string $reason, bool $skip_log, bool $skip_email): void {
    if ((!$skip_log || !$skip_email) && class_exists('EUM_Profiles')) {
      $user = get_user_by('id', $target_user_id);
      $change_id = (int)wp_insert_post([
        'post_type' => EUM_Profiles::CPT_CHANGE,
        'post_status' => 'publish',
        'post_title' => 'Bulk Team Edit - ' . ($user ? ($user->display_name ?: $user->user_login) : ('User #' . $target_user_id)),
        'post_author' => $actor_user_id,
      ]);
      if ($change_id > 0) {
        update_post_meta($change_id, EUM_Profiles::META_TARGET_USER, $target_user_id);
        update_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, $actor_user_id);
        update_post_meta($change_id, EUM_Profiles::META_BEFORE, []);
        update_post_meta($change_id, EUM_Profiles::META_AFTER, $after);
        update_post_meta($change_id, EUM_Profiles::META_NOTE, $reason);
        update_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, current_time('mysql'));
        update_post_meta($change_id, EUM_Profiles::META_STATUS, 'applied');
        update_post_meta($change_id, EUM_Profiles::META_MANUAL, $skip_email ? 1 : 0);
        if (!$skip_email) {
          do_action('eum_profile_change_applied', $change_id, $target_user_id);
        }
        if ($skip_log) {
          wp_delete_post($change_id, true);
        }
      }
    }
  }

  public function handle_team_bulk_edit(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    if (!class_exists('EUM_Access') || (!EUM_Access::is_manager_user($actor_user_id) && !EUM_Access::is_it_or_hr($actor_user_id))) {
      wp_die('Not allowed.');
    }
    check_admin_referer('eum_manager_team_bulk_edit', 'eum_manager_team_bulk_nonce');

    $return_url = isset($_POST['return_url']) ? esc_url_raw((string)wp_unslash($_POST['return_url'])) : $this->current_request_url();
    $selected_ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_user_ids'] ?? [])), static fn($id) => $id > 0)));
    if (count($selected_ids) < 1) {
      wp_safe_redirect(add_query_arg(['eum_team_msg' => 'Select at least one visible user first.', 'eum_team_type' => 'error'], $return_url));
      exit;
    }

    if (sanitize_key((string)wp_unslash($_POST['team_bulk_action'] ?? '')) === 'email_list') {
      $this->handle_team_email_list_export($actor_user_id, $selected_ids, $return_url);
    }

    $field_values = $this->parse_team_bulk_payload($actor_user_id);
    $reason = sanitize_text_field((string)($_POST['bulk_reason'] ?? $_POST['bulk_profile_reason'] ?? $_POST['bulk_termination_reason'] ?? ''));
    $has_employment_changes = array_key_exists('employment:terminated', $field_values) || array_key_exists('employment:rehirable', $field_values);
    $has_profile_changes = count(array_diff(array_keys($field_values), ['employment:terminated', 'employment:rehirable'])) > 0;
    $redirect_action = sanitize_key((string)wp_unslash($_POST['bulk_login_redirect_action'] ?? 'none'));
    $has_redirect_change = in_array($redirect_action, ['set', 'clear'], true);
    $redirect_destination = trim((string)wp_unslash($_POST['bulk_login_redirect_url'] ?? ''));
    if (!in_array($redirect_action, ['none', 'set', 'clear'], true)) {
      wp_safe_redirect(add_query_arg(['eum_team_msg' => 'Choose a valid sign-in destination action.', 'eum_team_type' => 'error'], $return_url));
      exit;
    }
    if ($redirect_action === 'set' && $redirect_destination === '') {
      wp_safe_redirect(add_query_arg(['eum_team_msg' => 'Enter a destination URL to set.', 'eum_team_type' => 'error'], $return_url));
      exit;
    }
    if (!$has_profile_changes && !$has_employment_changes && !$has_redirect_change) {
      wp_safe_redirect(add_query_arg(['eum_team_msg' => 'Choose a profile field or sign-in destination change to apply.', 'eum_team_type' => 'error'], $return_url));
      exit;
    }
    if (($has_profile_changes || $has_employment_changes || $has_redirect_change) && $reason === '') {
      wp_safe_redirect(add_query_arg(['eum_team_msg' => 'Provide a reason for the changes being applied.', 'eum_team_type' => 'error'], $return_url));
      exit;
    }
    $row = $this->visual_bulk_row_from_profile_fields($field_values);

    $skip_log = !empty($_POST['skip_log']);
    $skip_email = !empty($_POST['skip_email']);
    $helper = class_exists('EUM_Visual_Bulk') ? new EUM_Visual_Bulk() : null;
    if (!$helper && !empty($row)) {
      wp_safe_redirect(add_query_arg(['eum_team_msg' => 'Bulk update tools are unavailable.', 'eum_team_type' => 'error'], $return_url));
      exit;
    }

    $updated = 0;
    $errors = [];
    foreach ($selected_ids as $target_user_id) {
      $valid_reasons = class_exists('EUM_Profiles') ? EUM_Profiles::reason_options_for_actor($actor_user_id, (int)$target_user_id) : [];
      if (($has_profile_changes || $has_employment_changes || $has_redirect_change) && !in_array($reason, $valid_reasons, true)) {
        $errors[] = '#' . (int)$target_user_id . ': The selected reason is not valid for this user.';
        continue;
      }
      $did_update = false;
      if ($has_employment_changes && !$has_profile_changes && class_exists('EUM_Personnel')) {
        $terminated = array_key_exists('employment:terminated', $field_values)
          ? in_array((string)$field_values['employment:terminated'], ['1', 'true', 'yes'], true)
          : EUM_Personnel::is_terminated((int)$target_user_id);
        $rehirable = array_key_exists('employment:rehirable', $field_values)
          ? in_array((string)$field_values['employment:rehirable'], ['1', 'true', 'yes'], true)
          : EUM_Personnel::is_rehirable((int)$target_user_id);
        EUM_Personnel::set_employment_status((int)$target_user_id, $terminated, $rehirable, $actor_user_id);
        $did_update = true;
        if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) {
          $this->record_bulk_change_entry((int)$target_user_id, $actor_user_id, $field_values, $reason, $skip_log, $skip_email);
        }
      } elseif (!empty($row)) {
        try {
          $result = $helper->apply_row_for_actor($actor_user_id, (int)$target_user_id, $row, false);
        } catch (Throwable $e) {
          $result = new WP_Error('bulk_update_exception', 'Bulk update failed: ' . $e->getMessage());
        }
        if (is_wp_error($result)) {
          $errors[] = '#' . (int)$target_user_id . ': ' . $result->get_error_message();
          continue;
        }
        $did_update = true;
        if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) {
          try {
            $this->record_bulk_change_entry((int)$target_user_id, $actor_user_id, $field_values, $reason, $skip_log, $skip_email);
          } catch (Throwable $e) {
            if (function_exists('eum_log_message')) eum_log_message('[EUM Manager Bulk] Could not write audit entry for user #' . (int)$target_user_id . ': ' . $e->getMessage());
          }
        }
      }

      if ($has_redirect_change) {
        $redirect_result = $redirect_action === 'clear'
          ? EUM_Access::clear_one_time_login_redirect($actor_user_id, (int)$target_user_id, $reason)
          : EUM_Access::set_one_time_login_redirect($actor_user_id, (int)$target_user_id, $redirect_destination, $reason);
        if (is_wp_error($redirect_result)) {
          $errors[] = '#' . (int)$target_user_id . ': ' . $redirect_result->get_error_message();
          if ($did_update) $updated++;
          continue;
        }
        $did_update = true;
      }

      if ($did_update) $updated++;
    }

    $parts = [];
    if ($updated > 0) $parts[] = sprintf('Updated %d people.', $updated);
    if (!empty($errors)) $parts[] = implode(' | ', array_slice($errors, 0, 3));
    if (empty($parts)) $parts[] = 'No changes were applied.';
    $type = !empty($errors) ? 'warning' : 'success';
    wp_safe_redirect(add_query_arg(['eum_team_msg' => implode(' ', $parts), 'eum_team_type' => $type], $return_url));
    exit;
  }

  private function sort_directory_rows(array $rows, string $sort, string $order): array {
    usort($rows, static function ($a, $b) use ($sort, $order) {
      $map = [
        'name' => $a['name'] <=> $b['name'],
      ];
      $va = '';
      $vb = '';
      switch ($sort) {
        case 'email': $va = strtolower((string)$a['email']); $vb = strtolower((string)$b['email']); break;
        case 'employee_id': $va = strtolower((string)($a['employee_id'] ?? '')); $vb = strtolower((string)($b['employee_id'] ?? '')); break;
        case 'job_path': $va = strtolower((string)$a['job_path']); $vb = strtolower((string)$b['job_path']); break;
        case 'location': $va = strtolower((string)$a['location']); $vb = strtolower((string)$b['location']); break;
        case 'manager': $va = strtolower((string)$a['manager']); $vb = strtolower((string)$b['manager']); break;
        case 'status': $va = ($a['rehirable'] ? '0' : '1') . strtolower((string)$a['terminated_at']); $vb = ($b['rehirable'] ? '0' : '1') . strtolower((string)$b['terminated_at']); break;
        default: $va = strtolower((string)$a['name']); $vb = strtolower((string)$b['name']); break;
      }
      $cmp = $va <=> $vb;
      return $order === 'desc' ? -$cmp : $cmp;
    });
    return $rows;
  }

  private function get_manager_ids_for_manager_user(int $user_id): array {
    if (!class_exists('EUM_Manager_IDs')) return [];
    $posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'fields' => 'ids',
    ]);
    $out = [];
    foreach ((array)$posts as $post_id) {
      $users = (array)get_post_meta((int)$post_id, EUM_Manager_IDs::META_MANAGER_USERS, true);
      $users = array_values(array_filter(array_map('intval', $users)));
      if (in_array($user_id, $users, true)) $out[] = (int)$post_id;
    }
    return $out;
  }

  private function user_tax_path(int $user_id, string $tax, string $sep): string {
    if (!taxonomy_exists($tax)) return '';
    $terms = wp_get_object_terms($user_id, $tax);
    if (is_wp_error($terms) || empty($terms)) return '';
    $leaf = $terms[0];
    $anc = array_reverse(get_ancestors($leaf->term_id, $tax));
    $names = [];
    foreach ($anc as $aid) {
      $t = get_term($aid, $tax);
      if ($t && !is_wp_error($t)) $names[] = $t->name;
    }
    $names[] = $leaf->name;
    return implode($sep, $names);
  }

  private function user_matches_job_filter(int $user_id, int $term_id): bool {
    if ($term_id <= 0 || !taxonomy_exists('eum_job_path')) return true;
    $terms = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($terms) || empty($terms)) return false;
    $user_term = (int)$terms[0];
    if ($user_term === $term_id) return true;
    return term_is_ancestor_of($term_id, $user_term, 'eum_job_path');
  }

  private function manager_access_message(): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-manager-portal"><p>You must be logged in to access manager tools.</p></div>';
    }

    $user_id = get_current_user_id();
    if (!class_exists('EUM_Access') || (!EUM_Access::is_manager_user($user_id) && !EUM_Access::is_it_or_hr($user_id))) {
      return '<div class="eum-manager-portal"><p>You do not have access to these manager tools.</p></div>';
    }

    return '';
  }

  private function is_truthy($value): bool {
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
  }

  private function current_request_url(): string {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '';
    if ($request_uri === '') {
      return home_url('/');
    }

    return home_url($request_uri);
  }

  private function build_target_url(string $base_url, array $add_args = [], array $remove_args = []): string {
    $url = $base_url !== '' ? $base_url : $this->current_request_url();
    if (!empty($remove_args)) {
      $url = remove_query_arg($remove_args, $url);
    }
    if (!empty($add_args)) {
      $clean = [];
      foreach ($add_args as $key => $value) {
        if ($value === '' || $value === null) continue;
        $clean[(string)$key] = $value;
      }
      if (!empty($clean)) {
        $url = add_query_arg($clean, $url);
      }
    }
    return $url;
  }

  private function tab_link(string $tab, string $label, bool $active): string {
    $url = add_query_arg(['eum_tab' => $tab]);
    $ajax_ignore = $tab === 'jobs' ? ' data-eum-ajax-ignore="1"' : '';
    $style = $active
      ? 'display:inline-block;padding:10px 14px;border:1px solid #1d2327;background:#1d2327;color:#fff;text-decoration:none;border-radius:4px;'
      : 'display:inline-block;padding:10px 14px;border:1px solid #ccd0d4;background:#fff;color:#1d2327;text-decoration:none;border-radius:4px;';
    return '<a href="'.esc_url($url).'" style="'.$style.'"'.$ajax_ignore.'>'.esc_html($label).'</a>';
  }
}
