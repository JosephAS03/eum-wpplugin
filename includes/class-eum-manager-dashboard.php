<?php
if (!defined('ABSPATH')) exit;

class EUM_Manager_Dashboard {

  public function init(): void {
    // Legacy dashboard class retained for backward compatibility only.
    // Standalone manager tool shortcodes now render from EUM_Manager_Portal.
  }

  public function menu(): void {
    // Manager features are intentionally front-end only.
  }

  public function render_admin(): void {
    wp_die('This tool is front-end only.');
  }

  public function shortcode_my_team($atts): string {
    $atts = shortcode_atts([
      'limit' => '100',
      'show_filters' => '1',
      'show_admin_links' => '0',
      'profile_page_url' => '',
      'files_page_url' => '',
      'org_page_url' => '',
      'profile_query_var' => 'eum_profile_user',
      'org_query_var' => 'eum_org_user',
      'profile_tab' => 'team',
      'org_tab' => 'org',
    ], (array)$atts, 'eum_my_team');

    $limit = max(10, min(500, intval($atts['limit'])));
    $show_filters = ($atts['show_filters'] === '1' || $atts['show_filters'] === 'true');
    $show_admin_links = ($atts['show_admin_links'] === '1' || $atts['show_admin_links'] === 'true');

    $q = isset($_GET['eum_q']) ? sanitize_text_field((string)$_GET['eum_q']) : '';
    $include_indirect = !empty($_GET['eum_include_indirect']) && in_array(strtolower(trim((string)$_GET['eum_include_indirect'])), ['1', 'true', 'yes', 'on'], true);
    $report_scope = isset($_GET['eum_report_scope']) ? sanitize_key((string)$_GET['eum_report_scope']) : 'all';
    if (!in_array($report_scope, ['all', 'direct', 'indirect'], true)) $report_scope = 'all';
    if (isset($_GET['eum_limit'])) {
      $limit = max(10, min(500, intval($_GET['eum_limit'])));
    }

    return $this->render_team_html([
      'context' => 'frontend',
      'q' => $q,
      'include_indirect' => $include_indirect,
      'report_scope' => $report_scope,
      'limit' => $limit,
      'show_filters' => $show_filters,
      'show_admin_links' => $show_admin_links,
      'profile_page_url' => esc_url_raw((string)$atts['profile_page_url']),
      'files_page_url' => esc_url_raw((string)$atts['files_page_url']),
      'org_page_url' => esc_url_raw((string)$atts['org_page_url']),
      'profile_query_var' => sanitize_key((string)$atts['profile_query_var']),
      'org_query_var' => sanitize_key((string)$atts['org_query_var']),
      'profile_tab' => sanitize_key((string)$atts['profile_tab']),
      'org_tab' => sanitize_key((string)$atts['org_tab']),
    ]);
  }

  private function render_team_html(array $args): string {
    $defaults = [
      'context' => 'frontend',
      'q' => '',
      'include_indirect' => false,
      'report_scope' => 'all',
      'limit' => 100,
      'show_filters' => true,
      'show_admin_links' => false,
      'profile_page_url' => '',
      'files_page_url' => '',
      'org_page_url' => '',
      'profile_query_var' => 'eum_profile_user',
      'org_query_var' => 'eum_org_user',
      'profile_tab' => 'team',
      'org_tab' => 'org',
    ];
    $args = array_merge($defaults, $args);

    if (!is_user_logged_in()) {
      return '<div class="eum-my-team"><p>You must be logged in to view this content.</p></div>';
    }

    if (!class_exists('EUM_Manager_IDs')) {
      return '<div class="eum-my-team"><p>Manager system is not available (EUM_Manager_IDs not loaded).</p></div>';
    }

    $current_user_id = get_current_user_id();
    $is_privileged = EUM_Access::is_it_or_hr($current_user_id);

    $mgr = new EUM_Manager_IDs();
    $managed_manager_ids = $this->get_manager_ids_for_manager_user($current_user_id);
    $include_indirect = !empty($args['include_indirect']);
    $report_scope = in_array((string)$args['report_scope'], ['all', 'direct', 'indirect'], true) ? (string)$args['report_scope'] : 'all';
    $managed_levels = class_exists('EUM_Manager_IDs') ? EUM_Manager_IDs::managed_user_levels_for_manager_user($current_user_id, true, $include_indirect) : [];

    if (!$is_privileged && empty($managed_manager_ids) && empty($managed_levels)) {
      return '<div class="eum-my-team"><p>You are not assigned as a manager for any Manager IDs.</p></div>';
    }

    $q = (string)$args['q'];
    $limit = (int)$args['limit'];
    $users = get_users(['fields' => ['ID','user_login','user_email','display_name']]);

    $active_team = [];
    $terminated_team = [];

    foreach ($users as $u) {
      $uid = (int)$u->ID;
      if ($uid === $current_user_id) continue;

      $effective_mid = (int)$mgr->resolve_effective_manager_id_for_user($uid);
      $direct_manager_id = class_exists('EUM_Personnel') ? EUM_Personnel::effective_manager_user_id($uid) : 0;
      $is_pending_manager = class_exists('EUM_Profiles') && EUM_Profiles::is_pending_manager_for_target($current_user_id, $uid);
      $report_level = isset($managed_levels[$uid]) ? (int)$managed_levels[$uid] : 0;
      if ($report_level <= 0 && !$include_indirect && class_exists('EUM_Manager_IDs')) {
        $report_level = EUM_Manager_IDs::user_is_direct_manager_of_user($current_user_id, $uid) ? 1 : 0;
      }
      if ($is_pending_manager && $report_level <= 0) $report_level = 1;

      if (!$is_privileged) {
        if ($report_level <= 0 && $direct_manager_id !== $current_user_id) continue;
        if ($report_scope === 'direct' && $report_level !== 1) continue;
        if ($report_scope === 'indirect' && $report_level < 2) continue;
      }

      if ($q !== '') {
        $hay = strtolower(($u->display_name ?? '').' '.($u->user_login ?? '').' '.($u->user_email ?? '').' '.$this->report_level_label($report_level));
        if (strpos($hay, strtolower($q)) === false) continue;
      }

      $is_terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($uid);
      $profile_url = $this->build_target_url(
        (string)$args['profile_page_url'],
        [
          (string)$args['profile_query_var'] => $uid,
          'eum_tab' => (string)$args['profile_tab'],
        ],
        [(string)$args['profile_query_var'], 'eum_tab', (string)$args['org_query_var']]
      );
      $files_url = $this->build_target_url(
        (string)($args['files_page_url'] !== '' ? $args['files_page_url'] : $args['profile_page_url']),
        [
          (string)$args['profile_query_var'] => $uid,
          'eum_tab' => (string)$args['profile_tab'],
        ],
        [(string)$args['profile_query_var'], 'eum_tab', (string)$args['org_query_var']]
      ) . '#eum-personnel-section-' . $uid;
      $org_url = $this->build_target_url(
        (string)$args['org_page_url'],
        [
          (string)$args['org_query_var'] => $uid,
          'eum_tab' => (string)$args['org_tab'],
        ],
        [(string)$args['org_query_var'], 'eum_tab', (string)$args['profile_query_var']]
      );

      $row = [
        'ID' => $uid,
        'name' => $u->display_name ?: $u->user_login,
        'email' => $u->user_email,
        'job_path' => $this->user_tax_path($uid, 'eum_job_path', ' → '),
        'location' => $this->user_tax_path($uid, 'eum_location', ' → '),
        'effective_manager_id' => $effective_mid,
        'effective_manager_name' => $effective_mid > 0 ? get_the_title($effective_mid) : '',
        'report_level' => $report_level,
        'report_level_label' => $this->report_level_label($report_level),
        'profile_url' => $profile_url,
        'files_url' => $files_url,
        'org_url' => $org_url,
        'is_terminated' => $is_terminated,
        'rehirable' => $is_terminated && class_exists('EUM_Personnel') ? EUM_Personnel::is_rehirable($uid) : false,
        'terminated_at' => $is_terminated && class_exists('EUM_Personnel') ? EUM_Personnel::terminated_at($uid) : '',
      ];

      if ($is_terminated) {
        $terminated_team[] = $row;
      } else {
        $active_team[] = $row;
      }

      if ((count($active_team) + count($terminated_team)) >= $limit) break;
    }

    ob_start();

    echo '<div class="eum-my-team">';
    echo '<h3>My Team</h3>';

    if (!empty($args['show_filters'])) {
      echo '<form method="get" data-eum-save-filters="1" data-eum-filter-context="manager-dashboard-team" style="margin:12px 0; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">';
      foreach ($_GET as $key => $value) {
        if (in_array($key, ['eum_q', 'eum_limit', 'eum_include_indirect', 'eum_report_scope'], true)) continue;
        if (is_scalar($value)) {
          echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';
        }
      }
      echo '<input type="text" name="eum_q" value="'.esc_attr($q).'" placeholder="Search name/email..." /> ';
      echo '<select name="eum_report_scope"><option value="all"'.selected($report_scope, 'all', false).'>All visible reports</option><option value="direct"'.selected($report_scope, 'direct', false).'>Direct reports only</option><option value="indirect"'.selected($report_scope, 'indirect', false).'>Only second+ level</option></select> ';
      echo '<label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="eum_include_indirect" value="1"'.checked($include_indirect, true, false).'> Show second/third/fourth level</label> ';
      echo '<input type="number" name="eum_limit" value="'.(int)$limit.'" min="10" max="500" style="width:100px;" /> ';
      echo '<button type="submit">Filter</button>';
      echo '</form>';
    }

    if (empty($active_team) && empty($terminated_team)) {
      echo '<p>No managed users found.</p>';
      echo '</div>';
      return ob_get_clean();
    }

    echo $this->render_team_table('Active Team', $active_team, false);
    echo $this->render_team_table('Terminated Team', $terminated_team, true);
    echo '</div>';

    return ob_get_clean();
  }

  private function render_team_table(string $title, array $rows, bool $terminated): string {
    if (empty($rows)) return '';

    ob_start();
    echo '<div style="margin-top:18px;">';
    echo '<h4 style="margin:0 0 10px 0;">' . esc_html($title) . '</h4>';
    if ($terminated) {
      echo '<p class="description">Terminated users stay separated here so managers, HR, and IT can review them without mixing them into the active team list.</p>';
    }
    echo '<table class="widefat striped" style="width:100%; max-width: 1200px;">';
    echo '<thead><tr>';
    echo '<th>User</th><th>Email</th><th>Job Path</th><th>Location</th><th>Effective Manager ID</th><th>Relationship</th>';
    if ($terminated) echo '<th>Status</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
      echo '<tr>';
      $avatar = class_exists('EUM_Profiles') ? EUM_Profiles::profile_avatar_html((int)$row['ID'], 36, 'eum-directory__avatar') : '';
      echo '<td><span class="eum-directory__person">' . $avatar . '<span>' . esc_html($row['name']) . ' <span class="description">(#' . (int)$row['ID'] . ')</span></span></span></td>';
      echo '<td>'.esc_html($row['email']).'</td>';
      echo '<td>'.esc_html($row['job_path']).'</td>';
      echo '<td>'.esc_html($row['location']).'</td>';
      $manager_label = $row['effective_manager_name'] !== '' ? $row['effective_manager_name'].' (#'.$row['effective_manager_id'].')' : '—';
      if ((int)$row['effective_manager_id'] > 0 && class_exists('EUM_Profiles')) {
        echo '<td><span class="eum-directory__manager">' . EUM_Profiles::profile_avatar_html((int)$row['effective_manager_id'], 26, 'eum-directory__avatar eum-directory__avatar--small') . '<span>' . esc_html($manager_label) . '</span></span></td>';
      } else {
        echo '<td>'.esc_html($manager_label).'</td>';
      }
      echo '<td>'.esc_html((string)($row['report_level_label'] ?? '—')).'</td>';
      if ($terminated) {
        $status = $row['rehirable'] ? 'Rehirable' : 'Non-rehireable';
        if ($row['terminated_at'] !== '') $status .= ' · ' . $row['terminated_at'];
        echo '<td>'.esc_html($status).'</td>';
      }
      echo '<td style="white-space:nowrap;">';
      $actor_user_id = get_current_user_id();
      $target_user_id = (int)$row['ID'];
      $can_edit = $actor_user_id !== $target_user_id && class_exists('EUM_Profiles')
        && EUM_Profiles::can_edit_target($actor_user_id, $target_user_id)
        && !empty(EUM_Profiles::editable_fields_for_actor($actor_user_id, $target_user_id));
      echo '<a href="'.esc_url($row['profile_url']).'">' . ($can_edit ? 'Edit' : 'Open Profile') . '</a> &nbsp;|&nbsp; ';
      echo '<a href="'.esc_url($row['files_url']).'">Files</a> &nbsp;|&nbsp; ';
      echo '<a href="'.esc_url($row['org_url']).'">Org Chart</a>';
      echo '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    return ob_get_clean();
  }

  private function report_level_label(int $level): string {
    $level = (int)$level;
    if ($level <= 0) return '—';
    if ($level === 1) return 'Direct';
    return 'Level ' . $level;
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

  private function current_request_url(): string {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '';
    if ($request_uri === '') {
      return home_url('/');
    }

    return home_url($request_uri);
  }

  private function build_target_url(string $base_url, array $query_args, array $remove_args = []): string {
    $url = trim($base_url) !== '' ? $base_url : $this->current_request_url();
    if (!empty($remove_args)) {
      $url = remove_query_arg(array_filter($remove_args, static fn($arg) => $arg !== ''), $url);
    }

    $clean_args = [];
    foreach ($query_args as $key => $value) {
      $key = sanitize_key((string)$key);
      if ($key === '') continue;
      if ($value === '' || $value === null) continue;
      $clean_args[$key] = $value;
    }

    return add_query_arg($clean_args, $url);
  }
}
