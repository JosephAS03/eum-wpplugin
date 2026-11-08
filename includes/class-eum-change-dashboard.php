<?php
if (!defined('ABSPATH')) exit;

class EUM_Change_Dashboard {
  const PAGE_SLUG = 'eum-change-dashboard';

  public function init(): void {
    add_action('admin_menu', [$this, 'admin_menu'], 50);
    add_action('admin_post_eum_change_dashboard_change_save', [$this, 'handle_change_save']);
    add_action('admin_post_eum_change_dashboard_hire_save', [$this, 'handle_hire_save']);
    add_action('pre_get_users', [$this, 'filter_users_admin_list']);
    add_filter('views_users', [$this, 'users_views']);
    add_shortcode('eum_change_dashboard', [$this, 'shortcode']);
  }

  public static function can_access(int $user_id = 0): bool {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    if ($user_id <= 0) return false;
    if (!is_user_logged_in()) return false;
    if (EUM_Access::is_it_or_hr($user_id)) return true;
    if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'is_manager_of_anything')) {
      if (EUM_Profiles::is_manager_of_anything($user_id)) return true;
    }
    if (class_exists('EUM_Access') && method_exists('EUM_Access', 'is_manager_user')) {
      if (EUM_Access::is_manager_user($user_id)) return true;
    }
    return false;
  }

  public function admin_menu(): void {
    if (!self::can_access()) return;
    add_dashboard_page(
      'Change Work Queue',
      'Change Work Queue',
      'read',
      self::PAGE_SLUG,
      [$this, 'render_page']
    );
  }

  public function filter_users_admin_list($query): void {
    if (!is_admin() || !($query instanceof WP_User_Query)) return;
    global $pagenow;
    if ($pagenow !== 'users.php') return;
    if (!current_user_can('list_users')) return;
    if (!empty($_GET['eum_show_terminated']) && (string)$_GET['eum_show_terminated'] === '1') return;

    $meta_query = (array)$query->get('meta_query');
    $meta_query[] = [
      'relation' => 'OR',
      [
        'key' => EUM_Personnel::META_TERMINATED,
        'compare' => 'NOT EXISTS',
      ],
      [
        'key' => EUM_Personnel::META_TERMINATED,
        'value' => '1',
        'compare' => '!=',
      ],
    ];
    $query->set('meta_query', $meta_query);
  }

  public function users_views(array $views): array {
    if (!current_user_can('list_users')) return $views;
    $base = admin_url('users.php');
    $show_terminated = !empty($_GET['eum_show_terminated']) && (string)$_GET['eum_show_terminated'] === '1';
    $active_url = remove_query_arg('eum_show_terminated', $base);
    $terminated_url = add_query_arg('eum_show_terminated', '1', $base);
    $views['eum_active_default'] = '<a href="' . esc_url($active_url) . '"' . ($show_terminated ? '' : ' class="current" aria-current="page"') . '>Active Users</a>';
    $views['eum_show_terminated'] = '<a href="' . esc_url($terminated_url) . '"' . ($show_terminated ? ' class="current" aria-current="page"' : '') . '>Show Terminated</a>';
    return $views;
  }

  private function current_url(array $extra = [], string $context = 'admin'): string {
    $url = $this->base_url($context);
    foreach ($this->current_filters() as $key => $value) {
      if ($value === '' || $value === 'all') continue;
      $url = add_query_arg($key, $value, $url);
    }
    foreach ($extra as $key => $value) {
      $url = add_query_arg($key, $value, $url);
    }
    return $url;
  }

  private function base_url(string $context = 'admin'): string {
    if ($context === 'front') {
      $scheme = is_ssl() ? 'https' : 'http';
      $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
      $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
      $raw = $host !== '' ? $scheme . '://' . $host . $uri : home_url('/');
      $base = remove_query_arg(array_merge(array_keys($this->current_filters()), ['page']), $raw);
      return $base;
    }
    return admin_url('index.php?page=' . self::PAGE_SLUG);
  }

  public function shortcode($atts = []): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-change-dashboard eum-change-dashboard--locked"><p>Please log in to view the change dashboard.</p></div>';
    }
    if (!self::can_access(get_current_user_id())) {
      return '<div class="eum-change-dashboard eum-change-dashboard--locked"><p>You do not have access to the change dashboard.</p></div>';
    }
    ob_start();
    try {
      $this->render_page('front');
    } catch (\Throwable $e) {
      echo $this->render_runtime_error($e, 'front');
    }
    return ob_get_clean();
  }

  private function current_filters(): array {
    $allowed = [
      'item_type' => ['all', 'change', 'hire'],
      'queue' => ['all', 'recent', 'pending', 'approval', 'pending_hires'],
      'status' => ['all', 'pending', 'scheduled', 'applied', 'rejected', 'cancelled', 'failed'],
      'needs_my_approval' => ['all', 'yes', 'no'],
      'needs_other_manager' => ['all', 'yes', 'no'],
    ];
    $filters = [
      'item_type' => 'all',
      'queue' => 'all',
      'status' => 'all',
      'needs_my_approval' => 'all',
      'needs_other_manager' => 'all',
      's' => sanitize_text_field((string)($_GET['s'] ?? '')),
      'limit' => max(10, min(300, intval($_GET['limit'] ?? 60))),
    ];
    foreach ($allowed as $key => $vals) {
      $raw = sanitize_key((string)($_GET[$key] ?? 'all'));
      if (in_array($raw, $vals, true)) $filters[$key] = $raw;
    }
    return $filters;
  }

  private function dashboard_counts(int $viewer_user_id): array {
    $changes = $this->load_change_items($viewer_user_id, 300);
    $hires = $this->load_hire_items($viewer_user_id, 200);
    $counts = [
      'recent' => 0,
      'pending' => 0,
      'approval' => 0,
      'pending_hires' => 0,
    ];
    foreach ($changes as $item) {
      if ($item['queue'] === 'recent') $counts['recent']++;
      if ($item['queue'] === 'pending') $counts['pending']++;
      if (!empty($item['needs_my_approval'])) $counts['approval']++;
    }
    foreach ($hires as $item) {
      if ($item['queue'] === 'recent') $counts['recent']++;
      if ($item['queue'] === 'pending_hires') $counts['pending_hires']++;
    }
    return $counts;
  }

  public function render_page(string $context = 'admin'): void {
    $viewer_user_id = get_current_user_id();
    if (!self::can_access($viewer_user_id)) wp_die('Not allowed.');

    try {
      $filters = $this->current_filters();
      $counts = $this->dashboard_counts($viewer_user_id);
      $items = $this->filtered_items($viewer_user_id, $filters);

      $wrapper_class = $context === 'front' ? 'eum-change-dashboard eum-change-dashboard--front' : 'wrap eum-change-dashboard';
      echo '<div class="' . esc_attr($wrapper_class) . '">';
      echo '<h1>Change Work Queue</h1>';
      echo '<p>Review recent changes, pending changes, approvals that need your attention, and pending hires. You can edit actionable records right here instead of bouncing between screens.</p>';
      $this->render_notice();
      $this->render_dashboard_stats($counts);
      $this->render_filter_form($filters, $context);

      if (empty($items)) {
        echo '<div class="notice notice-info"><p>No queue items matched the current filters.</p></div>';
        echo '</div>';
        return;
      }

      echo '<div class="eum-change-dashboard__list">';
      foreach ($items as $item) {
        try {
          if (($item['kind'] ?? '') === 'change') {
            $this->render_change_card($item, $viewer_user_id, $context);
          } else {
            $this->render_hire_card($item, $viewer_user_id, $context);
          }
        } catch (\Throwable $e) {
          $this->render_broken_item_card($item, $e);
        }
      }
      echo '</div></div>';
    } catch (\Throwable $e) {
      echo $this->render_runtime_error($e, $context);
    }
  }

  private function render_notice(): void {
    $msg = sanitize_text_field((string)($_GET['eum_msg'] ?? ''));
    if ($msg === '') return;
    $type = sanitize_key((string)($_GET['eum_type'] ?? 'success'));
    $class = 'notice notice-success';
    if ($type === 'error') $class = 'notice notice-error';
    elseif ($type === 'warning') $class = 'notice notice-warning';
    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($msg) . '</p></div>';
  }

  private function render_dashboard_stats(array $counts): void {
    $cards = [
      'Recent Changes' => $counts['recent'],
      'Pending Changes' => $counts['pending'],
      'Needs My Approval' => $counts['approval'],
      'Pending Hires' => $counts['pending_hires'],
    ];
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 18px;max-width:980px;">';
    foreach ($cards as $label => $count) {
      echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px;box-shadow:0 1px 2px rgba(0,0,0,.04);">';
      echo '<div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;">' . esc_html($label) . '</div>';
      echo '<div style="font-size:28px;font-weight:700;line-height:1.1;margin-top:6px;">' . (int)$count . '</div>';
      echo '</div>';
    }
    echo '</div>';
  }

  private function render_filter_form(array $filters, string $context = 'admin'): void {
    $action = $context === 'front' ? $this->base_url('front') : admin_url('index.php');
    echo '<form method="get" action="' . esc_url($action) . '" data-eum-save-filters="1" data-eum-filter-context="change-dashboard-' . esc_attr($context) . '" style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px;margin-bottom:18px;max-width:1180px;">';
    if ($context !== 'front') echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">';
    echo '<p style="margin:0;"><label><strong>Search</strong></label><br><input type="search" name="s" value="' . esc_attr($filters['s']) . '" placeholder="Name, reason, email, username…" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>Type</strong></label><br><select name="item_type" style="width:100%;"><option value="all"' . selected($filters['item_type'], 'all', false) . '>All items</option><option value="change"' . selected($filters['item_type'], 'change', false) . '>Profile changes</option><option value="hire"' . selected($filters['item_type'], 'hire', false) . '>Hires</option></select></p>';
    echo '<p style="margin:0;"><label><strong>Queue</strong></label><br><select name="queue" style="width:100%;"><option value="all"' . selected($filters['queue'], 'all', false) . '>All queues</option><option value="recent"' . selected($filters['queue'], 'recent', false) . '>Recent changes</option><option value="pending"' . selected($filters['queue'], 'pending', false) . '>Pending / scheduled</option><option value="approval"' . selected($filters['queue'], 'approval', false) . '>Needs my approval</option><option value="pending_hires"' . selected($filters['queue'], 'pending_hires', false) . '>Pending hires</option></select></p>';
    echo '<p style="margin:0;"><label><strong>Status</strong></label><br><select name="status" style="width:100%;"><option value="all"' . selected($filters['status'], 'all', false) . '>All statuses</option><option value="pending"' . selected($filters['status'], 'pending', false) . '>Pending</option><option value="scheduled"' . selected($filters['status'], 'scheduled', false) . '>Scheduled</option><option value="applied"' . selected($filters['status'], 'applied', false) . '>Applied</option><option value="rejected"' . selected($filters['status'], 'rejected', false) . '>Rejected</option><option value="cancelled"' . selected($filters['status'], 'cancelled', false) . '>Cancelled</option><option value="failed"' . selected($filters['status'], 'failed', false) . '>Failed</option></select></p>';
    echo '<p style="margin:0;"><label><strong>Needs approval from current user?</strong></label><br><select name="needs_my_approval" style="width:100%;"><option value="all"' . selected($filters['needs_my_approval'], 'all', false) . '>All</option><option value="yes"' . selected($filters['needs_my_approval'], 'yes', false) . '>Yes</option><option value="no"' . selected($filters['needs_my_approval'], 'no', false) . '>No</option></select></p>';
    echo '<p style="margin:0;"><label><strong>Needs approval from another manager?</strong></label><br><select name="needs_other_manager" style="width:100%;"><option value="all"' . selected($filters['needs_other_manager'], 'all', false) . '>All</option><option value="yes"' . selected($filters['needs_other_manager'], 'yes', false) . '>Yes</option><option value="no"' . selected($filters['needs_other_manager'], 'no', false) . '>No</option></select></p>';
    echo '<p style="margin:0;"><label><strong>Limit</strong></label><br><input type="number" name="limit" min="10" max="300" value="' . (int)$filters['limit'] . '" style="width:100%;"></p>';
    echo '</div>';
    echo '<p style="margin:12px 0 0;"><button type="submit" class="button button-primary">Apply Filters</button> <a class="button" href="' . esc_url(admin_url('index.php?page=' . self::PAGE_SLUG)) . '">Reset</a></p>';
    echo '</form>';
  }

  private function filtered_items(int $viewer_user_id, array $filters): array {
    $items = [];
    if ($filters['item_type'] !== 'hire') {
      $items = array_merge($items, $this->load_change_items($viewer_user_id, $filters['limit'] * 4));
    }
    if ($filters['item_type'] !== 'change') {
      $items = array_merge($items, $this->load_hire_items($viewer_user_id, $filters['limit'] * 3));
    }

    $items = array_values(array_filter($items, function(array $item) use ($filters) {
      if ($filters['queue'] !== 'all' && (string)$item['queue'] !== $filters['queue']) return false;
      if ($filters['status'] !== 'all' && (string)$item['status'] !== $filters['status']) return false;
      if ($filters['needs_my_approval'] === 'yes' && empty($item['needs_my_approval'])) return false;
      if ($filters['needs_my_approval'] === 'no' && !empty($item['needs_my_approval'])) return false;
      if ($filters['needs_other_manager'] === 'yes' && empty($item['needs_other_manager'])) return false;
      if ($filters['needs_other_manager'] === 'no' && !empty($item['needs_other_manager'])) return false;
      if ($filters['s'] !== '') {
        $hay = strtolower((string)($item['search'] ?? ''));
        if (strpos($hay, strtolower($filters['s'])) === false) return false;
      }
      return true;
    }));

    usort($items, static function(array $a, array $b): int {
      return strcmp((string)$b['sort_time'], (string)$a['sort_time']);
    });

    return array_slice($items, 0, $filters['limit']);
  }

  private function load_change_items(int $viewer_user_id, int $limit): array {
    $posts = get_posts([
      'post_type' => EUM_Profiles::CPT_CHANGE,
      'post_status' => 'publish',
      'numberposts' => max(20, $limit),
      'orderby' => 'date',
      'order' => 'DESC',
    ]);
    $items = [];
    foreach ((array)$posts as $post) {
      try {
        $change_id = (int)$post->ID;
        $status = (string)get_post_meta($change_id, EUM_Profiles::META_STATUS, true);
        $target_user_id = (int)get_post_meta($change_id, EUM_Profiles::META_TARGET_USER, true);
        $actor_user_id = (int)get_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, true);
        $approver_ids = array_values(array_filter(array_map('intval', (array)get_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, true))));
        if (!$this->can_view_change($viewer_user_id, $target_user_id, $actor_user_id, $approver_ids)) continue;
        $reason = (string)get_post_meta($change_id, EUM_Profiles::META_NOTE, true);
        $effective_at = (string)get_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, true);
        $occurred_at = (string)get_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, true);
        $after = get_post_meta($change_id, EUM_Profiles::META_AFTER, true);
        $after = is_array($after) ? $after : [];
        $before = get_post_meta($change_id, EUM_Profiles::META_BEFORE, true);
        $before = is_array($before) ? $before : [];
        $needs_my_approval = ($status === 'pending') && $this->can_approve_change($viewer_user_id, $approver_ids);
        $needs_other_manager = ($status === 'pending') && !$needs_my_approval && !empty($approver_ids);
        $queue = in_array($status, ['pending', 'scheduled'], true) ? 'pending' : 'recent';
        if ($needs_my_approval) $queue = 'approval';
        $target_name = EUM_Profiles::manager_user_name($target_user_id);
        $actor_name = EUM_Profiles::manager_user_name($actor_user_id);
        $search = implode(' | ', array_filter([
          'profile change',
          $status,
          $reason,
          $target_name,
          $actor_name,
          (string)$post->post_title,
          implode(' ', array_keys($after)),
        ]));
        $items[] = [
          'kind' => 'change',
          'id' => $change_id,
          'post' => $post,
          'status' => $status,
          'queue' => $queue,
          'needs_my_approval' => $needs_my_approval,
          'needs_other_manager' => $needs_other_manager,
          'target_user_id' => $target_user_id,
          'actor_user_id' => $actor_user_id,
          'target_name' => $target_name,
          'actor_name' => $actor_name,
          'reason' => $reason,
          'effective_at' => $effective_at,
          'occurred_at' => $occurred_at,
          'approver_ids' => $approver_ids,
          'after' => $after,
          'before' => $before,
          'sort_time' => $effective_at !== '' ? $effective_at : ($occurred_at !== '' ? $occurred_at : (string)$post->post_date),
          'search' => strtolower($search),
        ];
      } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Change Dashboard] Skipping broken change #' . (int)($post->ID ?? 0) . ': ' . $e->getMessage());
      }
    }
    return $items;
  }

  private function load_hire_items(int $viewer_user_id, int $limit): array {
    $posts = get_posts([
      'post_type' => EUM_User_Creation::CPT,
      'post_status' => 'publish',
      'numberposts' => max(20, $limit),
      'orderby' => 'date',
      'order' => 'DESC',
    ]);
    $items = [];
    foreach ((array)$posts as $post) {
      try {
        $hire_id = (int)$post->ID;
        $status = (string)get_post_meta($hire_id, EUM_User_Creation::META_STATUS, true);
        $actor_user_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_ACTOR_USER, true);
        $job_term_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_JOB_TERM, true);
        if (!$this->can_view_hire($viewer_user_id, $hire_id, $actor_user_id, $job_term_id)) continue;
        $full_name = (string)get_post_meta($hire_id, EUM_User_Creation::META_FULL_NAME, true);
        $username = (string)get_post_meta($hire_id, EUM_User_Creation::META_USERNAME, true);
        $email = (string)get_post_meta($hire_id, EUM_User_Creation::META_EMAIL, true);
        $effective_at = (string)get_post_meta($hire_id, EUM_User_Creation::META_EFFECTIVE_AT, true);
        $profile_after = get_post_meta($hire_id, EUM_User_Creation::META_PROFILE_AFTER, true);
        $profile_after = is_array($profile_after) ? $profile_after : [];
        $error = (string)get_post_meta($hire_id, EUM_User_Creation::META_ERROR, true);
        $queue = ($status === 'scheduled') ? 'pending_hires' : 'recent';
        $job_path = $job_term_id > 0 ? EUM_Profiles::term_full_path('eum_job_path', $job_term_id, ' / ') : '';
        $search = implode(' | ', array_filter(['hire', $status, $full_name, $username, $email, $job_path, $error]));
        $items[] = [
          'kind' => 'hire',
          'id' => $hire_id,
          'post' => $post,
          'status' => $status,
          'queue' => $queue,
          'needs_my_approval' => false,
          'needs_other_manager' => false,
          'actor_user_id' => $actor_user_id,
          'actor_name' => EUM_Profiles::manager_user_name($actor_user_id),
          'full_name' => $full_name,
          'username' => $username,
          'email' => $email,
          'job_term_id' => $job_term_id,
          'job_path' => $job_path,
          'effective_at' => $effective_at,
          'profile_after' => $profile_after,
          'error' => $error,
          'created_user_id' => (int)get_post_meta($hire_id, EUM_User_Creation::META_CREATED_USER, true),
          'candidate_id' => (int)get_post_meta($hire_id, EUM_User_Creation::META_CANDIDATE_ID, true),
          'sort_time' => $effective_at !== '' ? $effective_at : (string)$post->post_date,
          'search' => strtolower($search),
        ];
      } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Change Dashboard] Skipping broken hire #' . (int)($post->ID ?? 0) . ': ' . $e->getMessage());
      }
    }
    return $items;
  }

  private function can_view_change(int $viewer_user_id, int $target_user_id, int $actor_user_id, array $approver_ids): bool {
    if ($viewer_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($viewer_user_id)) return true;
    if ($viewer_user_id === $target_user_id || $viewer_user_id === $actor_user_id) return true;
    if (in_array($viewer_user_id, $approver_ids, true)) return true;
    if (EUM_Profiles::is_manager_of_target($viewer_user_id, $target_user_id)) return true;
    if (EUM_Profiles::is_pending_manager_for_target($viewer_user_id, $target_user_id)) return true;
    return false;
  }

  private function can_manage_change(int $viewer_user_id, int $change_id, int $target_user_id, array $after): bool {
    if ($viewer_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($viewer_user_id)) return true;
    $approver_ids = array_values(array_filter(array_map('intval', (array)get_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, true))));
    if ($this->can_approve_change($viewer_user_id, $approver_ids)) return true;
    $change_actor = (int)get_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, true);
    if ($change_actor === $viewer_user_id) return true;
    if ((int)($after['core:eum_manager_user_id'] ?? 0) === $viewer_user_id) return true;
    return EUM_Profiles::can_edit_target($viewer_user_id, $target_user_id);
  }

  private function can_approve_change(int $viewer_user_id, array $approver_ids): bool {
    if ($viewer_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($viewer_user_id)) return true;
    return in_array($viewer_user_id, array_map('intval', $approver_ids), true);
  }

  private function can_view_hire(int $viewer_user_id, int $hire_id, int $actor_user_id, int $job_term_id): bool {
    if ($viewer_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($viewer_user_id)) return true;
    if ($actor_user_id === $viewer_user_id) return true;
    return $this->actor_can_manage_job_term($viewer_user_id, $job_term_id);
  }

  private function can_manage_hire(int $viewer_user_id, int $hire_id, int $actor_user_id, int $job_term_id, string $status): bool {
    if ($viewer_user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($viewer_user_id)) return true;
    if ($actor_user_id === $viewer_user_id) return true;
    return $this->actor_can_manage_job_term($viewer_user_id, $job_term_id);
  }

  private function actor_can_manage_job_term(int $viewer_user_id, int $job_term_id): bool {
    if ($viewer_user_id <= 0) return false;
    if ($job_term_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($viewer_user_id)) return true;
    if (!class_exists('EUM_Manager_IDs')) return false;
    $posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => -1,
      'fields' => 'ids',
    ]);
    foreach ((array)$posts as $post_id) {
      $manager_users = array_values(array_filter(array_map('intval', (array)get_post_meta((int)$post_id, EUM_Manager_IDs::META_MANAGER_USERS, true))));
      if (!in_array($viewer_user_id, $manager_users, true)) continue;
      $root_term_id = (int)get_post_meta((int)$post_id, EUM_Manager_IDs::META_JOB_PATH_TERM, true);
      if ($root_term_id <= 0) continue;
      if ($job_term_id === $root_term_id || term_is_ancestor_of($root_term_id, $job_term_id, 'eum_job_path')) return true;
    }
    return false;
  }

  private function render_change_card(array $item, int $viewer_user_id, string $context = 'admin'): void {
    $change_id = (int)$item['id'];
    $status = (string)$item['status'];
    $can_manage = $this->can_manage_change($viewer_user_id, $change_id, (int)$item['target_user_id'], (array)$item['after']);
    $can_approve = $this->can_approve_change($viewer_user_id, (array)$item['approver_ids']) && $status === 'pending';
    $open = in_array($item['queue'], ['approval', 'pending'], true) ? ' open' : '';
    $approver_labels = array_filter(array_map(['EUM_Profiles', 'manager_user_name'], (array)$item['approver_ids']));

    echo '<details class="eum-change-dashboard__card"' . $open . ' style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:0;margin:0 0 14px;overflow:hidden;">';
    echo '<summary style="cursor:pointer;list-style:none;padding:14px 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">';
    echo '<strong>Profile Change #' . $change_id . '</strong>';
    echo '<span style="padding:2px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;">' . esc_html(ucfirst($status)) . '</span>';
    if (!empty($item['needs_my_approval'])) echo '<span style="padding:2px 8px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:12px;">Needs my approval</span>';
    if (!empty($item['needs_other_manager'])) echo '<span style="padding:2px 8px;border-radius:999px;background:#f3f4f6;color:#374151;font-size:12px;">Waiting on another manager</span>';
    echo '<span style="color:#6b7280;">' . esc_html($item['target_name'] !== '' ? $item['target_name'] : ('User #' . (int)$item['target_user_id'])) . '</span>';
    echo '<span style="color:#6b7280;">Changed by ' . esc_html($item['actor_name'] !== '' ? $item['actor_name'] : ('User #' . (int)$item['actor_user_id'])) . '</span>';
    if ($item['reason'] !== '') echo '<span style="color:#6b7280;">' . esc_html($item['reason']) . '</span>';
    echo '<span style="margin-left:auto;color:#6b7280;">' . esc_html($item['effective_at'] !== '' ? $item['effective_at'] : $item['occurred_at']) . '</span>';
    echo '</summary>';
    echo '<div style="padding:16px;border-top:1px solid #f0f0f1;">';
    echo '<p style="margin-top:0;">';
    echo '<strong>Target:</strong> ' . esc_html($item['target_name'] !== '' ? $item['target_name'] : ('User #' . (int)$item['target_user_id'])) . ' &nbsp; '; 
    echo '<strong>Requestor:</strong> ' . esc_html($item['actor_name'] !== '' ? $item['actor_name'] : ('User #' . (int)$item['actor_user_id'])) . ' &nbsp; ';
    echo '<strong>Occurred:</strong> ' . esc_html($item['occurred_at'] !== '' ? $item['occurred_at'] : (string)$item['post']->post_date);
    echo '</p>';
    if (!empty($approver_labels)) {
      echo '<p><strong>Approvers:</strong> ' . esc_html(implode(', ', $approver_labels)) . '</p>';
    }

    if ($can_approve) {
      echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 16px;">';
      echo $this->change_action_form($change_id, 'approve', 'Approve', $this->current_url([], $context));
      echo $this->change_action_form($change_id, 'reject', 'Reject', $this->current_url([], $context));
      echo '</div>';
    }
    if ($can_manage && in_array($status, ['pending', 'scheduled'], true)) {
      echo '<div style="margin:0 0 14px;">' . $this->change_action_form($change_id, 'cancel', 'Cancel', $this->current_url([], $context)) . '</div>';
    }

    if ($can_manage) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_change_dashboard_change_save">';
      echo '<input type="hidden" name="change_id" value="' . $change_id . '">';
      echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url([], $context)) . '">';
      wp_nonce_field('eum_change_dashboard_change_save_' . $change_id, 'eum_change_dashboard_change_nonce');
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">';
      echo '<p style="margin:0;"><label><strong>Status</strong></label><br><select name="change_status" style="width:100%;">';
      foreach (['pending', 'scheduled', 'applied', 'rejected', 'cancelled'] as $status_option) {
        echo '<option value="' . esc_attr($status_option) . '" ' . selected($status, $status_option, false) . '>' . esc_html(ucfirst($status_option)) . '</option>';
      }
      echo '</select></p>';
      echo '<p style="margin:0;"><label><strong>Effective At</strong></label><br><input type="datetime-local" name="effective_at" value="' . esc_attr($this->datetime_local_value((string)$item['effective_at'])) . '" style="width:100%;"></p>';
      echo '<p style="margin:0;grid-column:1/-1;"><label><strong>Reason</strong></label><br><input type="text" name="change_note" value="' . esc_attr((string)$item['reason']) . '" style="width:100%;"></p>';
      echo '</div>';
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:14px;">';
      $field_keys = array_keys((array)$item['after']);
      sort($field_keys, SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($field_keys as $field_id) {
        $value = $item['after'][$field_id];
        echo '<div>' . $this->safe_dashboard_change_field($field_id, $value, (int)$item['target_user_id'], $viewer_user_id, 'eum_change_after') . '</div>';
      }
      if (empty($field_keys)) {
        echo '<p class="description" style="margin:0;grid-column:1/-1;">This record does not carry editable field payloads.</p>';
      }
      echo '</div>';
      echo '<p style="margin:16px 0 0;"><button type="submit" class="button button-primary">Save Change</button> <button type="submit" name="apply_now" value="1" class="button">Save and Apply Now</button></p>';
      echo '</form>';
    } else {
      echo '<p class="description" style="margin-bottom:0;">You can review this item here, but editing is limited to the requestor, approver, or HR / IT.</p>';
    }
    echo '</div></details>';
  }

  private function render_hire_card(array $item, int $viewer_user_id, string $context = 'admin'): void {
    $hire_id = (int)$item['id'];
    $status = (string)$item['status'];
    $can_manage = $this->can_manage_hire($viewer_user_id, $hire_id, (int)$item['actor_user_id'], (int)$item['job_term_id'], $status);
    $created_user = $item['created_user_id'] > 0 ? get_user_by('id', (int)$item['created_user_id']) : false;
    $open = in_array($item['queue'], ['pending_hires'], true) ? ' open' : '';

    echo '<details class="eum-change-dashboard__card"' . $open . ' style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:0;margin:0 0 14px;overflow:hidden;">';
    echo '<summary style="cursor:pointer;list-style:none;padding:14px 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">';
    echo '<strong>Hire #' . $hire_id . '</strong>';
    echo '<span style="padding:2px 8px;border-radius:999px;background:#ecfdf5;color:#166534;font-size:12px;">' . esc_html(ucfirst($status)) . '</span>';
    echo '<span style="color:#6b7280;">' . esc_html((string)$item['full_name']) . '</span>';
    if ($item['job_path'] !== '') echo '<span style="color:#6b7280;">' . esc_html($item['job_path']) . '</span>';
    echo '<span style="margin-left:auto;color:#6b7280;">' . esc_html((string)$item['effective_at']) . '</span>';
    echo '</summary>';
    echo '<div style="padding:16px;border-top:1px solid #f0f0f1;">';
    echo '<p style="margin-top:0;">';
    echo '<strong>Username:</strong> <code>' . esc_html((string)$item['username']) . '</code> &nbsp; ';
    echo '<strong>Email:</strong> ' . esc_html((string)$item['email']) . ' &nbsp; ';
    echo '<strong>Created by:</strong> ' . esc_html($item['actor_name'] !== '' ? $item['actor_name'] : ('User #' . (int)$item['actor_user_id']));
    echo '</p>';
    if ($item['error'] !== '') {
      echo '<p style="margin:0 0 12px;color:#b42318;"><strong>Error:</strong> ' . esc_html((string)$item['error']) . '</p>';
    }
    if ($created_user) {
      echo '<p><strong>Created User:</strong> ' . esc_html($created_user->display_name ?: $created_user->user_login) . ' (#' . (int)$created_user->ID . ')</p>';
    }

    if ($can_manage) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_change_dashboard_hire_save">';
      echo '<input type="hidden" name="hire_id" value="' . $hire_id . '">';
      echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url([], $context)) . '">';
      wp_nonce_field('eum_change_dashboard_hire_save_' . $hire_id, 'eum_change_dashboard_hire_nonce');
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">';
      echo '<p style="margin:0;"><label><strong>Name</strong></label><br><input type="text" name="hire_full_name" value="' . esc_attr((string)$item['full_name']) . '" style="width:100%;" required></p>';
      echo '<p style="margin:0;"><label><strong>Username</strong></label><br><input type="text" name="hire_username" value="' . esc_attr((string)$item['username']) . '" style="width:100%;"' . ($status === 'applied' ? ' readonly' : '') . '></p>';
      echo '<p style="margin:0;"><label><strong>Status</strong></label><br><select name="hire_status" style="width:100%;">';
      $hire_status_options = ($status === 'applied') ? ['applied'] : ['scheduled', 'applied', 'cancelled', 'failed'];
      foreach ($hire_status_options as $status_option) {
        echo '<option value="' . esc_attr($status_option) . '" ' . selected($status, $status_option, false) . '>' . esc_html(ucfirst($status_option)) . '</option>';
      }
      echo '</select></p>';
      echo '<p style="margin:0;"><label><strong>Effective Hire Date</strong></label><br><input type="datetime-local" name="hire_effective_at" value="' . esc_attr($this->datetime_local_value((string)$item['effective_at'])) . '" style="width:100%;"></p>';
      echo '</div>';
      echo '<div style="margin-top:14px;">';
      echo '<label><strong>Job Path</strong></label><br>';
      echo EUM_Profiles::render_job_path_directory_picker('hire_job_term_id', (int)$item['job_term_id'], $viewer_user_id, [
        'placeholder' => '— Select a Job Path —',
        'required' => true,
        'container_id' => 'eum-dashboard-hire-picker-' . $hire_id,
        'input_id' => 'eum-dashboard-hire-input-' . $hire_id,
      ]);
      echo '</div>';
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:14px;">';
      $field_keys = array_keys((array)$item['profile_after']);
      if (!in_array('core:job_company', $field_keys, true)) $field_keys[] = 'core:job_company';
      $field_keys = array_values(array_unique(array_filter($field_keys)));
      sort($field_keys, SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($field_keys as $field_id) {
        if ($field_id === 'tax:eum_job_path') continue;
        $value = $item['profile_after'][$field_id] ?? '';
        echo '<div>' . $this->safe_dashboard_change_field($field_id, $value, 0, $viewer_user_id, 'eum_hire_pf') . '</div>';
      }
      echo '</div>';
      echo '<p style="margin:16px 0 0;"><button type="submit" class="button button-primary">Save Hire</button> <button type="submit" name="apply_now" value="1" class="button">Save and Apply Now</button></p>';
      echo '</form>';
      if ($status === 'applied') {
        echo '<p class="description" style="margin-top:10px;">Applied hires update the existing created user when you save here. Username stays read-only because WordPress cannot safely rename user_login after creation.</p>';
      }
    } else {
      echo '<p class="description" style="margin-bottom:0;">You can review this hire here. Editing is limited to the creator, job-path manager, or HR / IT.</p>';
    }
    echo '</div></details>';
  }

  private function render_dashboard_change_field(string $field_id, $value, int $target_user_id, int $actor_user_id, string $prefix): string {
    if ($field_id === 'system:eum_terminated') {
      return '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Terminated</label><select name="' . esc_attr($prefix . '[' . $field_id . ']') . '" style="width:100%;"><option value="0"' . selected(!empty($value), false, false) . '>No</option><option value="1"' . selected(!empty($value), true, false) . '>Yes</option></select></p>';
    }
    if ($field_id === 'system:eum_rehirable') {
      return '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Rehirable</label><select name="' . esc_attr($prefix . '[' . $field_id . ']') . '" style="width:100%;"><option value="0"' . selected(!empty($value), false, false) . '>No</option><option value="1"' . selected(!empty($value), true, false) . '>Yes</option></select></p>';
    }
    if ($field_id === 'system:eum_terminated_at') {
      return '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Termination Date/Time</label><input type="datetime-local" name="' . esc_attr($prefix . '[' . $field_id . ']') . '" value="' . esc_attr($this->datetime_local_value((string)$value)) . '" style="width:100%;"></p>';
    }
    $html = EUM_Profiles::render_field_input($field_id, $value, $target_user_id, $actor_user_id);
    if ($html === '') {
      return '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html($field_id) . '</label><input type="text" name="' . esc_attr($prefix . '[' . $field_id . ']') . '" value="' . esc_attr(is_scalar($value) ? (string)$value : wp_json_encode($value)) . '" style="width:100%;"></p>';
    }
    return str_replace('name="eum_pf[', 'name="' . esc_attr($prefix) . '[', $html);
  }

  private function safe_dashboard_change_field(string $field_id, $value, int $target_user_id, int $actor_user_id, string $prefix): string {
    try {
      return $this->render_dashboard_change_field($field_id, $value, $target_user_id, $actor_user_id, $prefix);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Change Dashboard field] ' . $field_id . ': ' . $e->getMessage());
      return '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html($field_id) . '</label><input type="text" name="' . esc_attr($prefix . '[' . $field_id . ']') . '" value="' . esc_attr(is_scalar($value) ? (string)$value : wp_json_encode($value)) . '" style="width:100%;"></p>';
    }
  }

  private function change_action_form(int $change_id, string $action, string $label, string $return_url): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 8px 0 0;">';
    echo '<input type="hidden" name="action" value="eum_profile_change_action">';
    echo '<input type="hidden" name="change_id" value="' . $change_id . '">';
    echo '<input type="hidden" name="eum_change_action" value="' . esc_attr($action) . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_change_action_' . $change_id, 'eum_change_nonce');
    echo '<button type="submit" class="button">' . esc_html($label) . '</button>';
    echo '</form>';
    return ob_get_clean();
  }

  public function handle_change_save(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $viewer_user_id = get_current_user_id();
    $change_id = max(0, intval($_POST['change_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? admin_url('index.php?page=' . self::PAGE_SLUG)));
    if ($change_id <= 0 || !self::can_access($viewer_user_id)) wp_die('Not allowed.');
    if (!isset($_POST['eum_change_dashboard_change_nonce']) || !wp_verify_nonce($_POST['eum_change_dashboard_change_nonce'], 'eum_change_dashboard_change_save_' . $change_id)) wp_die('Bad nonce.');

    $target_user_id = (int)get_post_meta($change_id, EUM_Profiles::META_TARGET_USER, true);
    $after_existing = get_post_meta($change_id, EUM_Profiles::META_AFTER, true);
    $after_existing = is_array($after_existing) ? $after_existing : [];
    if (!$this->can_manage_change($viewer_user_id, $change_id, $target_user_id, $after_existing)) {
      wp_safe_redirect(add_query_arg(['eum_msg' => 'You cannot edit this change.', 'eum_type' => 'error'], $return_url));
      exit;
    }

    $after = $after_existing;
    $posted_after = isset($_POST['eum_change_after']) && is_array($_POST['eum_change_after']) ? wp_unslash($_POST['eum_change_after']) : [];
    foreach ($posted_after as $field_id => $raw_value) {
      $field_id = (string)$field_id;
      if (str_starts_with($field_id, 'system:')) {
        switch ($field_id) {
          case 'system:eum_terminated':
          case 'system:eum_rehirable':
            $after[$field_id] = !empty($raw_value) ? 1 : 0;
            break;
          case 'system:eum_terminated_at':
            $after[$field_id] = EUM_Profiles::parse_datetime_local((string)$raw_value);
            break;
        }
        continue;
      }
      $normalized = EUM_Profiles::normalize_field_input($field_id, $raw_value);
      if ($normalized !== null) $after[$field_id] = $normalized;
    }

    $approver_ids = array_values(array_filter(array_map('intval', (array)get_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, true))));
    $status_current = (string)get_post_meta($change_id, EUM_Profiles::META_STATUS, true);
    $status = sanitize_key((string)($_POST['change_status'] ?? $status_current));
    if (!in_array($status, ['pending', 'scheduled', 'applied', 'rejected', 'cancelled'], true)) {
      $status = $status_current;
    }
    if (!empty($_POST['apply_now'])) $status = 'applied';
    if ($status === 'applied' && $status_current === 'pending' && !$this->can_approve_change($viewer_user_id, $approver_ids)) {
      wp_safe_redirect(add_query_arg(['eum_msg' => 'Only an approver, HR, or IT can apply this pending change.', 'eum_type' => 'error'], $return_url));
      exit;
    }

    $change_note = sanitize_text_field((string)($_POST['change_note'] ?? ''));
    $effective_at = EUM_Profiles::parse_datetime_local((string)($_POST['effective_at'] ?? ''));

    update_post_meta($change_id, EUM_Profiles::META_AFTER, $after);
    update_post_meta($change_id, EUM_Profiles::META_NOTE, $change_note);
    update_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, $effective_at);
    update_post_meta($change_id, EUM_Profiles::META_STATUS, $status);

    if ($status === 'pending') {
      delete_post_meta($change_id, EUM_Profiles::META_APPROVED_BY);
      delete_post_meta($change_id, EUM_Profiles::META_APPROVED_AT);
    }

    if ($status === 'applied' && class_exists('EUM_Profiles')) {
      $profiles = new EUM_Profiles();
      if (!$profiles->try_apply_change($change_id, $viewer_user_id)) {
        wp_safe_redirect(add_query_arg(['eum_msg' => 'Change could not be applied right now.', 'eum_type' => 'error'], $return_url));
        exit;
      }
      $message = 'Change saved and applied.';
    } else {
      $message = 'Change updated.';
    }

    wp_safe_redirect(add_query_arg(['eum_msg' => $message, 'eum_type' => 'success'], $return_url));
    exit;
  }

  public function handle_hire_save(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $viewer_user_id = get_current_user_id();
    $hire_id = max(0, intval($_POST['hire_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? admin_url('index.php?page=' . self::PAGE_SLUG)));
    if ($hire_id <= 0 || !self::can_access($viewer_user_id)) wp_die('Not allowed.');
    if (!isset($_POST['eum_change_dashboard_hire_nonce']) || !wp_verify_nonce($_POST['eum_change_dashboard_hire_nonce'], 'eum_change_dashboard_hire_save_' . $hire_id)) wp_die('Bad nonce.');

    $status_current = (string)get_post_meta($hire_id, EUM_User_Creation::META_STATUS, true);
    $actor_user_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_ACTOR_USER, true);
    $job_term_id_current = (int)get_post_meta($hire_id, EUM_User_Creation::META_JOB_TERM, true);
    if (!$this->can_view_hire($viewer_user_id, $hire_id, $actor_user_id, $job_term_id_current)) {
      wp_safe_redirect(add_query_arg(['eum_msg' => 'You cannot edit this hire.', 'eum_type' => 'error'], $return_url));
      exit;
    }

    $full_name = sanitize_text_field((string)($_POST['hire_full_name'] ?? get_post_meta($hire_id, EUM_User_Creation::META_FULL_NAME, true)));
    $username = sanitize_user((string)($_POST['hire_username'] ?? get_post_meta($hire_id, EUM_User_Creation::META_USERNAME, true)), true);
    $job_term_id = max(0, intval($_POST['hire_job_term_id'] ?? $job_term_id_current));
    $effective_at = EUM_Profiles::parse_datetime_local((string)($_POST['hire_effective_at'] ?? get_post_meta($hire_id, EUM_User_Creation::META_EFFECTIVE_AT, true)));
    $status = sanitize_key((string)($_POST['hire_status'] ?? $status_current));
    if (!in_array($status, ['scheduled', 'applied', 'cancelled', 'failed'], true)) $status = $status_current;
    if (!empty($_POST['apply_now'])) $status = 'applied';

    if ($full_name === '' || $job_term_id <= 0 || $effective_at === '') {
      wp_safe_redirect(add_query_arg(['eum_msg' => 'Name, job path, and effective hire date are required.', 'eum_type' => 'error'], $return_url));
      exit;
    }

    $profile_after = get_post_meta($hire_id, EUM_User_Creation::META_PROFILE_AFTER, true);
    $profile_after = is_array($profile_after) ? $profile_after : [];
    $posted_pf = isset($_POST['eum_hire_pf']) && is_array($_POST['eum_hire_pf']) ? wp_unslash($_POST['eum_hire_pf']) : [];
    foreach ($posted_pf as $field_id => $raw_value) {
      $normalized = EUM_Profiles::normalize_field_input((string)$field_id, $raw_value);
      if ($normalized !== null) $profile_after[(string)$field_id] = $normalized;
    }

    $override_division = (string)($profile_after['core:job_company'] ?? '');
    $derived = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::derive_transfer_values($username, $job_term_id, $override_division) : ['email' => '', 'division' => ''];
    $email = class_exists('EUM_Company_Divisions') ? sanitize_email((string)($derived['email'] ?? '')) : sanitize_email((string)get_post_meta($hire_id, EUM_User_Creation::META_EMAIL, true));
    if ($email === '') {
      wp_safe_redirect(add_query_arg(['eum_msg' => 'Company email could not be generated. Check the job path division/domain and username.', 'eum_type' => 'error'], $return_url));
      exit;
    }

    update_post_meta($hire_id, EUM_User_Creation::META_FULL_NAME, $full_name);
    update_post_meta($hire_id, EUM_User_Creation::META_USERNAME, $username);
    update_post_meta($hire_id, EUM_User_Creation::META_JOB_TERM, $job_term_id);
    update_post_meta($hire_id, EUM_User_Creation::META_EFFECTIVE_AT, $effective_at);
    update_post_meta($hire_id, EUM_User_Creation::META_EMAIL, $email);
    update_post_meta($hire_id, EUM_User_Creation::META_PROFILE_AFTER, $profile_after);
    update_post_meta($hire_id, EUM_User_Creation::META_STATUS, $status === 'applied' ? 'scheduled' : $status);
    update_post_meta($hire_id, EUM_User_Creation::META_ERROR, '');

    $user_creation = new EUM_User_Creation();
    $created_user_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_CREATED_USER, true);
    if ($status_current === 'applied' && $created_user_id > 0 && $status === 'applied') {
      [$first_name, $last_name] = $this->split_name($full_name);
      $payload = array_merge($profile_after, [
        'core:first_name' => $first_name,
        'core:last_name' => $last_name,
        'core:display_name' => $full_name,
        'core:user_email' => $email,
        'tax:eum_job_path' => $job_term_id,
      ]);
      wp_set_object_terms($created_user_id, [$job_term_id], 'eum_job_path', false);
      $user_creation->apply_profile_fields_to_user($created_user_id, $payload);
      update_post_meta($hire_id, EUM_User_Creation::META_STATUS, 'applied');
      $message = 'Applied hire and user profile updated.';
    } elseif ($status === 'cancelled') {
      update_post_meta($hire_id, EUM_User_Creation::META_STATUS, 'cancelled');
      $candidate_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_CANDIDATE_ID, true);
      if ($candidate_id > 0) {
        $restore_status = (string)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_PRE_HIRE_STATUS, true);
        if ($restore_status === '') $restore_status = 'selected';
        update_post_meta($candidate_id, EUM_Job_Boards::CAND_META_STATUS, $restore_status);
      }
      $message = 'Hire cancelled.';
    } elseif ($status === 'applied') {
      $result = $user_creation->apply_hire($hire_id);
      $message = is_wp_error($result) ? $result->get_error_message() : 'Hire saved and applied.';
      $type = is_wp_error($result) ? 'error' : 'success';
      wp_safe_redirect(add_query_arg(['eum_msg' => $message, 'eum_type' => $type], $return_url));
      exit;
    } else {
      $message = 'Hire updated.';
    }

    wp_safe_redirect(add_query_arg(['eum_msg' => $message, 'eum_type' => 'success'], $return_url));
    exit;
  }

  private function datetime_local_value(string $value): string {
    if ($value === '') return '';
    return str_replace(' ', 'T', substr($value, 0, 16));
  }

  private function split_name(string $full_name): array {
    $full_name = trim($full_name);
    if ($full_name === '') return ['', ''];
    $parts = preg_split('/\s+/', $full_name) ?: [];
    $first = array_shift($parts) ?: '';
    $last = !empty($parts) ? implode(' ', $parts) : '';
    return [$first, $last];
  }

  private function render_runtime_error(\Throwable $e, string $context = 'admin'): string {
    $wrapper_class = $context === 'front' ? 'eum-change-dashboard eum-change-dashboard--front' : 'wrap eum-change-dashboard';
    $detail = (defined('WP_DEBUG') && WP_DEBUG) ? '<div class="description" style="margin-top:8px;">' . esc_html($e->getMessage()) . '</div>' : '';
    return '<div class="' . esc_attr($wrapper_class) . '"><h1>Change Work Queue</h1><div class="notice notice-error"><p>The queue hit a runtime error, but the rest of the site is still loading.</p></div>' . $detail . '</div>';
  }

  private function render_broken_item_card(array $item, \Throwable $e): void {
    $kind = (($item['kind'] ?? '') === 'hire') ? 'Hire' : 'Profile Change';
    $item_id = (int)($item['id'] ?? 0);
    echo '<details class="eum-change-dashboard__card" open style="background:#fff;border:1px solid #f1b6b2;border-radius:10px;padding:0;margin:0 0 14px;overflow:hidden;">';
    echo '<summary style="cursor:pointer;list-style:none;padding:14px 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;background:#fff7f7;">';
    echo '<strong>' . esc_html($kind) . ' #' . $item_id . '</strong>';
    echo '<span style="padding:2px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:12px;">Render issue</span>';
    echo '</summary>';
    echo '<div style="padding:16px;border-top:1px solid #f0f0f1;">';
    echo '<p style="margin:0;">This queue item could not be rendered safely. The rest of the queue is still available.</p>';
    if (defined('WP_DEBUG') && WP_DEBUG) {
      echo '<p class="description" style="margin:10px 0 0;">' . esc_html($e->getMessage()) . '</p>';
    }
    echo '</div></details>';
    if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Change Dashboard render] Broken item #' . $item_id . ': ' . $e->getMessage());
  }
}
