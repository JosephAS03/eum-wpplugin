<?php
if (!defined('ABSPATH')) exit;

interface EUM_DTC_Connector_Interface {
  public function key(): string;
  public function label(): string;
  public function default_config(): array;
  public function fields_html(array $config): string;
  public function normalize_config(array $posted): array;
  public function generate_placeholder_id(array $channel_context = [], array $video_context = []): string;
  public function push_video(array $channel_context, array $video_context): array;
}

class EUM_DTC {
  const PAGE_SLUG = 'eum-dtc';
  const CPT_CHANNEL = 'eum_dtc_channel';
  const CPT_VIDEO = 'eum_dtc_video';
  const NONCE = 'eum_dtc_action';

  const META_CHANNEL_STATUS = '_eum_dtc_channel_status';
  const META_CHANNEL_DESCRIPTION = '_eum_dtc_channel_description';
  const META_CHANNEL_BUDGET = '_eum_dtc_channel_budget';
  const META_CHANNEL_FINANCE_PATH_TERM_ID = '_eum_dtc_channel_finance_path_term_id';
  const META_CHANNEL_BILLING_JOB_PATH_TERM_ID = '_eum_dtc_channel_billing_job_path_term_id';
  const META_CHANNEL_DIVISION = '_eum_dtc_channel_division';
  const META_CHANNEL_CONNECTOR_KEY = '_eum_dtc_channel_connector_key';
  const META_CHANNEL_CONNECTOR_CONFIG = '_eum_dtc_channel_connector_config';
  const META_CHANNEL_PERMISSIONS = '_eum_dtc_channel_permissions';
  const META_CHANNEL_ACTIVITY = '_eum_dtc_channel_activity';
  const META_CHANNEL_GROUP_TERM_ID = '_eum_dtc_channel_group_term_id';
  const META_CHANNEL_EXTERNAL_ID = '_eum_dtc_channel_external_id';

  const META_VIDEO_CHANNEL_ID = '_eum_dtc_video_channel_id';
  const META_VIDEO_STATUS = '_eum_dtc_video_status';
  const META_VIDEO_CONCEPT = '_eum_dtc_video_concept';
  const META_VIDEO_RESEARCH = '_eum_dtc_video_research';
  const META_VIDEO_SCRIPT = '_eum_dtc_video_script';
  const META_VIDEO_DESCRIPTION = '_eum_dtc_video_description';
  const META_VIDEO_TAGS = '_eum_dtc_video_tags';
  const META_VIDEO_PLACEHOLDER_ID = '_eum_dtc_video_placeholder_id';
  const META_VIDEO_PERMISSIONS = '_eum_dtc_video_permissions';
  const META_VIDEO_TAB_RULES = '_eum_dtc_video_tab_rules';
  const META_VIDEO_ASSETS = '_eum_dtc_video_assets';
  const META_VIDEO_ACTIVITY = '_eum_dtc_video_activity';
  const META_VIDEO_EVENT_ID = '_eum_dtc_video_event_id';
  const META_VIDEO_EVENT_ROLES = '_eum_dtc_video_event_roles';
  const META_VIDEO_LAST_PUSH_AT = '_eum_dtc_video_last_push_at';
  const META_VIDEO_LAST_PUSH_RESULT = '_eum_dtc_video_last_push_result';

  const GROUP_GLOBAL_SLUG = 'dtc-user';
  const GROUP_GLOBAL_LABEL = 'DTC User';
  const CHANNEL_GROUP_SLUG_PREFIX = 'dtc-user-channel-';
  const CHANNEL_GROUP_LABEL_PREFIX = 'DTC User + ';
  const SNAPSHOT_TABLE = 'eum_video_snapshots';

  private static $styles_printed = false;
  private static $allow_any_file_upload = false;
  private static $syncing_access_groups = false;
  private static $sync_access_groups_needed = false;

  public function init(): void {
    add_action('init', [$this, 'register_post_types']);
    add_action('init', [$this, 'ensure_default_groups'], 25);
    add_action('admin_menu', [$this, 'admin_menu'], 37);

    add_shortcode('eum_dtc_portal', [$this, 'shortcode_portal']);
    add_shortcode('eum_dtc_workspace', [$this, 'shortcode_workspace']);

    add_action('admin_post_eum_dtc_create_channel', [$this, 'handle_create_channel']);
    add_action('admin_post_eum_dtc_save_channel', [$this, 'handle_save_channel']);
    add_action('admin_post_eum_dtc_create_video', [$this, 'handle_create_video']);
    add_action('admin_post_eum_dtc_save_video', [$this, 'handle_save_video']);
    add_action('admin_post_eum_dtc_add_channel_permission', [$this, 'handle_add_channel_permission']);
    add_action('admin_post_eum_dtc_add_video_permission', [$this, 'handle_add_video_permission']);
    add_action('admin_post_eum_dtc_delete_channel_permission', [$this, 'handle_delete_channel_permission']);
    add_action('admin_post_eum_dtc_delete_video_permission', [$this, 'handle_delete_video_permission']);
    add_action('admin_post_eum_dtc_upload_asset', [$this, 'handle_upload_asset']);
    add_action('admin_post_eum_dtc_restore_asset', [$this, 'handle_restore_asset']);
    add_action('admin_post_eum_dtc_revert_snapshot', [$this, 'handle_revert_snapshot']);
    add_action('admin_post_eum_dtc_add_event_role', [$this, 'handle_add_event_role']);
    add_action('admin_post_eum_dtc_remove_event_role', [$this, 'handle_remove_event_role']);
    add_action('admin_post_eum_dtc_signup_event_role', [$this, 'handle_signup_event_role']);
    add_action('admin_post_eum_dtc_create_event', [$this, 'handle_create_event']);
    add_action('admin_post_eum_dtc_push_video', [$this, 'handle_push_video']);
    add_action('admin_post_eum_dtc_delete_video', [$this, 'handle_delete_video']);
    add_action('admin_post_eum_dtc_delete_channel', [$this, 'handle_delete_channel']);
    add_action('admin_post_eum_dtc_sync_groups', [$this, 'handle_sync_groups']);

    add_action('profile_update', [$this, 'handle_profile_change'], 20, 2);
    add_action('user_register', [$this, 'handle_user_register'], 20, 1);
    add_action('set_object_terms', [$this, 'handle_set_object_terms'], 20, 6);
    add_action('shutdown', [$this, 'maybe_sync_access_groups_on_shutdown'], 100);

    add_filter('upload_mimes', [$this, 'extend_upload_mimes']);
    add_filter('wp_check_filetype_and_ext', [$this, 'relax_filetype_check'], 20, 5);
  }

  public function register_post_types(): void {
    $common = [
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'rewrite' => false,
      'map_meta_cap' => true,
      'capability_type' => 'post',
      'supports' => ['title', 'editor', 'author'],
    ];

    register_post_type(self::CPT_CHANNEL, array_merge($common, [
      'labels' => [
        'name' => 'DTC Channels',
        'singular_name' => 'DTC Channel',
      ],
    ]));

    register_post_type(self::CPT_VIDEO, array_merge($common, [
      'labels' => [
        'name' => 'DTC Videos',
        'singular_name' => 'DTC Video',
      ],
    ]));
  }

  public function ensure_default_groups(): void {
    if (!taxonomy_exists('eum_user_group')) return;
    if (!term_exists(self::GROUP_GLOBAL_LABEL, 'eum_user_group')) {
      wp_insert_term(self::GROUP_GLOBAL_LABEL, 'eum_user_group', ['slug' => self::GROUP_GLOBAL_SLUG]);
    }
  }

  public function install_schema(): void {
    global $wpdb;
    $table = $this->snapshot_table_name();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      video_id BIGINT(20) UNSIGNED NOT NULL,
      snapshot_key VARCHAR(64) NOT NULL,
      snapshot_value LONGTEXT NULL,
      actor_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      note TEXT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY  (id),
      KEY video_id (video_id),
      KEY video_key (video_id, snapshot_key),
      KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
  }

  public function admin_menu(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) return;
    add_submenu_page(
      EUM_Admin::parent_slug('experience'),
      'DTC Video Hub',
      'DTC Video Hub',
      'read',
      self::PAGE_SLUG,
      [$this, 'render_admin_page']
    );
  }

  public function render_admin_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    echo '<div class="wrap">';
    echo '<h1>DTC Video Hub</h1>';
    echo '<p><code>[eum_dtc_portal]</code> renders the same DTC workspace on the front end for users with resolved access.</p>';
    echo $this->render_portal((int)get_current_user_id(), ['admin' => true]);
    echo '</div>';
  }

  public function shortcode_portal($atts = []): string {
    try {
      $user_id = (int)get_current_user_id();
      if ($user_id <= 0) return '';
      return $this->render_portal($user_id, (array)$atts);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) eum_log_message('[EUM DTC portal] ' . $e->getMessage());
      return $this->render_runtime_error('DTC Video Hub', $e);
    }
  }

  public function shortcode_workspace($atts = []): string {
    try {
      $user_id = (int)get_current_user_id();
      if ($user_id <= 0) return '';
      $atts = shortcode_atts(['channel_id' => 0, 'video_id' => 0], $atts, 'eum_dtc_workspace');
      if ((int)$atts['video_id'] > 0) {
        return $this->render_video_workspace((int)$atts['video_id'], $user_id);
      }
      if ((int)$atts['channel_id'] > 0) {
        return $this->render_channel_workspace((int)$atts['channel_id'], $user_id);
      }
      return $this->render_portal($user_id, (array)$atts);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) eum_log_message('[EUM DTC workspace] ' . $e->getMessage());
      return $this->render_runtime_error('DTC Workspace', $e);
    }
  }

  private function render_portal(int $user_id, array $atts = []): string {
    $video_id = !empty($atts['video_id']) ? (int)$atts['video_id'] : (isset($_GET['dtc_video_id']) ? (int)$_GET['dtc_video_id'] : 0);
    $channel_id = !empty($atts['channel_id']) ? (int)$atts['channel_id'] : (isset($_GET['dtc_channel_id']) ? (int)$_GET['dtc_channel_id'] : 0);
    if ($video_id > 0) return $this->render_video_workspace($video_id, $user_id);
    if ($channel_id > 0) return $this->render_channel_workspace($channel_id, $user_id);
    return $this->render_directory($user_id);
  }

  private function render_runtime_error(string $area, \Throwable $e): string {
    $detail = (defined('WP_DEBUG') && WP_DEBUG) ? '<div class="description" style="margin-top:8px;">' . esc_html($e->getMessage()) . '</div>' : '';
    return '<div class="eum-dtc"><div class="eum-dtc__panel"><h3>' . esc_html($area) . ' temporarily unavailable.</h3><p>A runtime error was caught so the rest of the site can keep loading.</p>' . $detail . '</div></div>';
  }

  private function render_directory(int $user_id): string {
    $this->print_styles_once();

    $visible_channels = $this->visible_channel_rows_for_user($user_id);
    $visible_videos = $this->visible_video_rows_for_user($user_id);
    $managed_channels = array_values(array_filter($visible_channels, fn($row) => $this->can_manage_channel((int)$row['id'], $user_id)));

    $search = sanitize_text_field((string)($_GET['dtc_search'] ?? ''));
    $status_filter = sanitize_key((string)($_GET['dtc_status'] ?? 'active'));
    if ($status_filter === '') $status_filter = 'active';

    $visible_videos = array_values(array_filter($visible_videos, function (array $row) use ($search, $status_filter) {
      $title = strtolower((string)($row['title'] ?? ''));
      $channel = strtolower((string)($row['channel_title'] ?? ''));
      $needle = strtolower($search);
      if ($needle !== '' && strpos($title . ' ' . $channel, $needle) === false) return false;
      if ($status_filter === 'all') return true;
      if ($status_filter === 'active') return !in_array((string)($row['status'] ?? ''), ['published', 'canceled'], true);
      return (string)($row['status'] ?? '') === $status_filter;
    }));

    ob_start();
    echo '<div class="eum-dtc">';
    echo $this->render_notice_banner();
    echo '<div class="eum-dtc__hero">';
    echo '<div><div class="eum-dtc__eyebrow">Direct to Consumer</div><h2>DTC Video Hub</h2><p>Ideas, scripts, assets, publishing, budgets, and role signups stay in one versioned workspace.</p></div>';
    echo '<div class="eum-dtc__hero-badges">';
    echo '<span class="eum-dtc__badge">Channels: ' . count($visible_channels) . '</span>';
    echo '<span class="eum-dtc__badge">Visible Projects: ' . count($visible_videos) . '</span>';
    echo '<span class="eum-dtc__badge">Active Projects: ' . count(array_filter($visible_videos, fn($row) => !in_array((string)($row['status'] ?? ''), ['published', 'canceled'], true))) . '</span>';
    echo '</div></div>';

    echo '<div class="eum-dtc__panel">';
    echo '<form method="get" class="eum-dtc__filters">';
    if (is_admin()) {
      echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
    }
    echo '<label><span>Search</span><input type="text" name="dtc_search" value="' . esc_attr($search) . '" placeholder="Video title or channel"></label>';
    echo '<label><span>Status</span><select name="dtc_status">' . $this->select_options(['active' => 'My Active Projects', 'all' => 'All Statuses'] + $this->status_options(), $status_filter) . '</select></label>';
    echo '<button class="button">Filter</button>';
    echo '</form>';
    echo '</div>';

    if ($this->current_user_can_create_channel($user_id)) {
      echo '<section class="eum-dtc__panel">';
      echo '<div class="eum-dtc__panel-head"><h3>Create DTC Channel</h3><span>Budgets, billing, division ownership, and connector settings live on the channel.</span></div>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__form-grid">';
      echo '<input type="hidden" name="action" value="eum_dtc_create_channel">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Channel Name</span><input type="text" name="channel_title" required></label>';
      echo '<label><span>Division Owner</span><select name="division">' . $this->division_options_html('') . '</select></label>';
      echo '<label><span>Connector</span><select name="connector_key">' . $this->connector_options_html('youtube') . '</select></label>';
      echo '<label><span>Budget</span><input type="number" step="0.01" name="budget" value="0"></label>';
      echo '<label><span>Budget Cost Center</span><select name="finance_path_term_id">' . $this->finance_path_options_html(0) . '</select></label>';
      echo '<label><span>Bill Up Through Job Path</span>' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_picker('billing_job_path_term_id', 0, get_current_user_id(), ['placeholder' => '— None —', 'none_label' => '— None —', 'enforce_manageable_job_paths' => false]) : '<select name="billing_job_path_term_id">' . $this->job_path_options_html(0, true) . '</select>') . '</label>';
      echo '<label class="eum-dtc__full"><span>Description</span><textarea name="description" rows="3"></textarea></label>';
      echo '<div class="eum-dtc__full"><button class="button button-primary">Create Channel</button></div>';
      echo '</form></section>';
    }

    if (!empty($managed_channels)) {
      echo '<section class="eum-dtc__panel">';
      echo '<div class="eum-dtc__panel-head"><h3>Create Video Project</h3><span>Each project gets snapshots, versioned assets, role signups, and a platform placeholder ID.</span></div>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__form-grid">';
      echo '<input type="hidden" name="action" value="eum_dtc_create_video">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Project Title</span><input type="text" name="video_title" required></label>';
      echo '<label><span>Channel</span><select name="channel_id" required><option value="0">Choose channel...</option>';
      foreach ($managed_channels as $row) {
        echo '<option value="' . (int)$row['id'] . '">' . esc_html((string)$row['title']) . '</option>';
      }
      echo '</select></label>';
      echo '<label><span>Status</span><select name="status">' . $this->select_options($this->status_options(), 'idea') . '</select></label>';
      echo '<label><span>Placeholder ID</span><input type="text" name="placeholder_id" placeholder="Manual YouTube ID or leave blank"></label>';
      echo '<label class="eum-dtc__check"><input type="checkbox" name="generate_placeholder_id" value="1"> Generate connector placeholder automatically</label>';
      echo '<label class="eum-dtc__full"><span>Concept</span><textarea name="concept" rows="3"></textarea></label>';
      echo '<div class="eum-dtc__full"><button class="button button-primary">Create Video Project</button></div>';
      echo '</form></section>';
    }

    echo '<section class="eum-dtc__panel">';
    echo '<div class="eum-dtc__panel-head"><h3>My Active Projects</h3><span>Filtered by job path, group inheritance, direct project rights, and creator/manager assignments.</span></div>';
    if (empty($visible_videos)) {
      echo '<div class="eum-dtc__empty">No projects are visible with the current filters.</div>';
    } else {
      echo '<table class="widefat striped"><thead><tr><th>Project</th><th>Channel</th><th>Status</th><th>Placeholder</th><th>Your Roles</th><th>Updated</th></tr></thead><tbody>';
      foreach ($visible_videos as $row) {
        $url = $this->workspace_url((int)$row['channel_id'], (int)$row['id']);
        echo '<tr>';
        echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html((string)$row['title']) . '</strong></a></td>';
        echo '<td>' . esc_html((string)$row['channel_title']) . '</td>';
        echo '<td>' . esc_html($this->status_label((string)$row['status'])) . '</td>';
        echo '<td>' . esc_html((string)($row['placeholder_id'] ?: 'Not set')) . '</td>';
        echo '<td>' . esc_html(implode(', ', array_map([$this, 'permission_label'], (array)($row['roles'] ?? [])))) . '</td>';
        echo '<td>' . esc_html((string)$row['updated_at']) . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</section>';

    echo '<section class="eum-dtc__panel">';
    echo '<div class="eum-dtc__panel-head"><h3>Channels</h3><span>Budget ownership, connector setup, and channel-level permissions.</span></div>';
    if (empty($visible_channels)) {
      echo '<div class="eum-dtc__empty">No DTC channels are visible yet.</div>';
    } else {
      echo '<div class="eum-dtc__grid">';
      foreach ($visible_channels as $row) {
        $url = $this->workspace_url((int)$row['id'], 0);
        echo '<article class="eum-dtc__card">';
        echo '<div class="eum-dtc__card-head"><div><h4><a href="' . esc_url($url) . '">' . esc_html((string)$row['title']) . '</a></h4><div class="eum-dtc__muted">' . esc_html((string)$row['division_label']) . ' / ' . esc_html((string)$row['connector_label']) . '</div></div><span class="eum-dtc__pill">' . esc_html($this->status_label((string)$row['status'])) . '</span></div>';
        echo '<div class="eum-dtc__card-meta">';
        echo '<span>Budget ' . esc_html($this->format_money((float)$row['budget'])) . '</span>';
        echo '<span>Projects ' . (int)$row['video_count'] . '</span>';
        echo '<span>Your roles: ' . esc_html(implode(', ', array_map([$this, 'permission_label'], (array)$row['roles']))) . '</span>';
        echo '</div>';
        echo '<p>' . esc_html((string)$row['description']) . '</p>';
        echo '</article>';
      }
      echo '</div>';
    }
    echo '</section>';

    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_channel_workspace(int $channel_id, int $user_id): string {
    $channel = get_post($channel_id);
    if (!$channel || $channel->post_type !== self::CPT_CHANNEL || !$this->can_view_channel($channel_id, $user_id)) {
      return '<div class="eum-dtc"><div class="eum-dtc__panel"><p>You do not have access to that DTC channel.</p></div></div>';
    }

    $this->print_styles_once();
    $roles = $this->roles_for_channel($channel_id, $user_id);
    $channel_meta = $this->channel_row($channel_id);
    $videos = $this->videos_for_channel($channel_id, $user_id);
    $permissions = $this->channel_permissions($channel_id);
    $connector = $this->connector_for_key((string)$channel_meta['connector_key']);

    ob_start();
    echo '<div class="eum-dtc">';
    echo $this->render_notice_banner();
    echo '<div class="eum-dtc__hero">';
    echo '<div><div class="eum-dtc__eyebrow">DTC Channel</div><h2>' . esc_html((string)$channel->post_title) . '</h2><p>' . esc_html((string)$channel_meta['description']) . '</p></div>';
    echo '<div class="eum-dtc__hero-badges">';
    echo '<span class="eum-dtc__badge">Division: ' . esc_html((string)$channel_meta['division_label']) . '</span>';
    echo '<span class="eum-dtc__badge">Budget: ' . esc_html($this->format_money((float)$channel_meta['budget'])) . '</span>';
    echo '<span class="eum-dtc__badge">Connector: ' . esc_html((string)$channel_meta['connector_label']) . '</span>';
    echo '<span class="eum-dtc__badge">Your roles: ' . esc_html(implode(', ', array_map([$this, 'permission_label'], $roles))) . '</span>';
    echo '</div></div>';

    echo '<nav class="eum-dtc__tabs"><a href="#dtc-channel-overview">Overview</a><a href="#dtc-channel-videos">Projects</a><a href="#dtc-channel-platform">Platform</a><a href="#dtc-channel-permissions">Permissions</a><a href="#dtc-channel-activity">Activity</a></nav>';

    echo '<section class="eum-dtc__panel" id="dtc-channel-overview">';
    echo '<div class="eum-dtc__panel-head"><h3>Channel Overview</h3><span>Division ownership, budget routing, and top-level channel settings.</span></div>';
    if ($this->can_manage_channel($channel_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__form-grid">';
      echo '<input type="hidden" name="action" value="eum_dtc_save_channel">';
      echo '<input type="hidden" name="channel_id" value="' . (int)$channel_id . '">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Channel Name</span><input type="text" name="channel_title" value="' . esc_attr((string)$channel->post_title) . '" required></label>';
      echo '<label><span>Status</span><select name="status">' . $this->select_options(['active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived', 'canceled' => 'Canceled'], (string)$channel_meta['status']) . '</select></label>';
      echo '<label><span>Division Owner</span><select name="division">' . $this->division_options_html((string)$channel_meta['division']) . '</select></label>';
      echo '<label><span>Budget</span><input type="number" step="0.01" name="budget" value="' . esc_attr((string)$channel_meta['budget']) . '"></label>';
      echo '<label><span>Budget Cost Center</span><select name="finance_path_term_id">' . $this->finance_path_options_html((int)$channel_meta['finance_path_term_id']) . '</select></label>';
      echo '<label><span>Bill Up Through Job Path</span>' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_picker('billing_job_path_term_id', (int)$channel_meta['billing_job_path_term_id'], get_current_user_id(), ['placeholder' => '— None —', 'none_label' => '— None —', 'enforce_manageable_job_paths' => false]) : '<select name="billing_job_path_term_id">' . $this->job_path_options_html((int)$channel_meta['billing_job_path_term_id'], true) . '</select>') . '</label>';
      echo '<label><span>External Channel ID</span><input type="text" name="external_id" value="' . esc_attr((string)$channel_meta['external_id']) . '" placeholder="YouTube channel ID or other platform key"></label>';
      echo '<label><span>Connector</span><select name="connector_key">' . $this->connector_options_html((string)$channel_meta['connector_key']) . '</select></label>';
      echo '<label class="eum-dtc__full"><span>Description</span><textarea name="description" rows="3">' . esc_textarea((string)$channel_meta['description']) . '</textarea></label>';
      echo '<div class="eum-dtc__full"><div class="eum-dtc__subpanel"><h4>Connector Configuration</h4>' . $connector->fields_html((array)$channel_meta['connector_config']) . '</div></div>';
      echo '<div class="eum-dtc__toolbar eum-dtc__full"><button class="button button-primary">Save Channel</button></div>';
      echo '</form>';
      echo '<div class="eum-dtc__toolbar" style="margin-top:12px;">';
      if ($this->current_user_can_hard_delete($user_id)) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Hard delete this channel and all linked videos?\');">';
        echo '<input type="hidden" name="action" value="eum_dtc_delete_channel"><input type="hidden" name="channel_id" value="' . (int)$channel_id . '"><input type="hidden" name="hard_delete" value="1">';
        wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
        echo '<button class="button button-link-delete">Hard Delete</button></form>';
      } else {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Cancel this channel?\');">';
        echo '<input type="hidden" name="action" value="eum_dtc_delete_channel"><input type="hidden" name="channel_id" value="' . (int)$channel_id . '">';
        wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
        echo '<button class="button">Mark Canceled</button></form>';
      }
      echo '</div>';
    } else {
      echo '<div class="eum-dtc__card-meta"><span>Budget Cost Center: ' . esc_html((string)$this->finance_path_label((int)$channel_meta['finance_path_term_id'])) . '</span><span>Bill Up Through: ' . esc_html((string)$this->job_path_label((int)$channel_meta['billing_job_path_term_id'])) . '</span><span>External ID: ' . esc_html((string)($channel_meta['external_id'] ?: 'Not set')) . '</span></div>';
    }
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-channel-videos">';
    echo '<div class="eum-dtc__panel-head"><h3>Projects</h3><span>Every video keeps its own snapshots, assets, event staffing, and publishing state.</span></div>';
    if ($this->can_manage_channel($channel_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__form-grid">';
      echo '<input type="hidden" name="action" value="eum_dtc_create_video">';
      echo '<input type="hidden" name="channel_id" value="' . (int)$channel_id . '">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Project Title</span><input type="text" name="video_title" required></label>';
      echo '<label><span>Status</span><select name="status">' . $this->select_options($this->status_options(), 'idea') . '</select></label>';
      echo '<label><span>Placeholder ID</span><input type="text" name="placeholder_id"></label>';
      echo '<label class="eum-dtc__check"><input type="checkbox" name="generate_placeholder_id" value="1"> Generate connector placeholder automatically</label>';
      echo '<label class="eum-dtc__full"><span>Concept</span><textarea name="concept" rows="2"></textarea></label>';
      echo '<div class="eum-dtc__full"><button class="button button-primary">Create Video Project</button></div>';
      echo '</form>';
    }

    if (empty($videos)) {
      echo '<div class="eum-dtc__empty">No projects are visible in this channel yet.</div>';
    } else {
      echo '<table class="widefat striped"><thead><tr><th>Project</th><th>Status</th><th>Placeholder</th><th>Your Roles</th><th>Updated</th></tr></thead><tbody>';
      foreach ($videos as $row) {
        $url = $this->workspace_url($channel_id, (int)$row['id']);
        echo '<tr>';
        echo '<td><a href="' . esc_url($url) . '"><strong>' . esc_html((string)$row['title']) . '</strong></a></td>';
        echo '<td>' . esc_html($this->status_label((string)$row['status'])) . '</td>';
        echo '<td>' . esc_html((string)($row['placeholder_id'] ?: 'Not set')) . '</td>';
        echo '<td>' . esc_html(implode(', ', array_map([$this, 'permission_label'], (array)$row['roles']))) . '</td>';
        echo '<td>' . esc_html((string)$row['updated_at']) . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-channel-platform">';
    echo '<div class="eum-dtc__panel-head"><h3>Platform & API</h3><span>Connector adapters keep platform logic isolated so future sites can slot in without a rewrite.</span></div>';
    echo '<div class="eum-dtc__grid">';
    echo '<article class="eum-dtc__card"><h4>Active Connector</h4><p>' . esc_html($connector->label()) . '</p></article>';
    echo '<article class="eum-dtc__card"><h4>Channel External ID</h4><p>' . esc_html((string)($channel_meta['external_id'] ?: 'Not set')) . '</p></article>';
    echo '<article class="eum-dtc__card"><h4>Connector Readiness</h4><p>' . esc_html($this->connector_readiness_label($connector, (array)$channel_meta['connector_config'])) . '</p></article>';
    echo '</div>';
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-channel-permissions">';
    echo '<div class="eum-dtc__panel-head"><h3>Channel Permissions</h3><span>Grant live or snapshot access through users, groups, or job paths.</span></div>';
    echo $this->render_permission_table($permissions, 'channel', $channel_id, $user_id);
    if ($this->can_manage_channel($channel_id, $user_id)) {
      echo '<div style="margin-top:18px;">' . $this->render_channel_member_roles_table($channel_id) . '</div>';
      echo $this->render_permission_form('channel', $channel_id);
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
      echo '<input type="hidden" name="action" value="eum_dtc_sync_groups"><input type="hidden" name="channel_id" value="' . (int)$channel_id . '">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<button class="button">Sync DTC Access Groups</button>';
      echo '</form>';
    }
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-channel-activity">';
    echo '<div class="eum-dtc__panel-head"><h3>Activity</h3><span>Channel-level changes stay visible for audit and handoff purposes.</span></div>';
    echo $this->render_activity_list((array)get_post_meta($channel_id, self::META_CHANNEL_ACTIVITY, true));
    echo '</section>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_video_workspace(int $video_id, int $user_id): string {
    $video = get_post($video_id);
    if (!$video || $video->post_type !== self::CPT_VIDEO || !$this->can_view_video($video_id, $user_id)) {
      return '<div class="eum-dtc"><div class="eum-dtc__panel"><p>You do not have access to that video project.</p></div></div>';
    }

    $this->print_styles_once();
    $channel_id = (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true);
    $channel = $channel_id > 0 ? get_post($channel_id) : null;
    $video_meta = $this->video_row($video_id, $user_id);
    $roles = (array)$video_meta['roles'];
    $tab_rules = $this->video_tab_rules($video_id);
    $assets = $this->video_assets($video_id);
    $snapshots = $this->snapshot_rows($video_id, 120);
    $event_roles = $this->video_event_roles($video_id);
    $linked_event = (int)get_post_meta($video_id, self::META_VIDEO_EVENT_ID, true);
    $channel_meta = $channel_id > 0 ? $this->channel_row($channel_id) : [];

    ob_start();
    echo '<div class="eum-dtc">';
    echo $this->render_notice_banner();
    echo '<div class="eum-dtc__hero">';
    echo '<div><div class="eum-dtc__eyebrow">DTC Project</div><h2>' . esc_html((string)$video->post_title) . '</h2><p>' . esc_html((string)($video_meta['concept'] ?: 'No concept added yet.')) . '</p></div>';
    echo '<div class="eum-dtc__hero-badges">';
    echo '<span class="eum-dtc__badge">Status: ' . esc_html($this->status_label((string)$video_meta['status'])) . '</span>';
    echo '<span class="eum-dtc__badge">Channel: ' . esc_html($channel ? (string)$channel->post_title : 'Unknown') . '</span>';
    echo '<span class="eum-dtc__badge">Placeholder: ' . esc_html((string)($video_meta['placeholder_id'] ?: 'Not set')) . '</span>';
    echo '<span class="eum-dtc__badge">Your roles: ' . esc_html(implode(', ', array_map([$this, 'permission_label'], $roles))) . '</span>';
    echo '</div></div>';

    echo '<nav class="eum-dtc__tabs"><a href="#dtc-video-overview">Overview</a><a href="#dtc-video-research">Research</a><a href="#dtc-video-script">Script</a><a href="#dtc-video-assets">Assets</a><a href="#dtc-video-platform">Platform</a><a href="#dtc-video-event">Event Roles</a><a href="#dtc-video-permissions">Permissions</a><a href="#dtc-video-history">History</a><a href="#dtc-video-activity">Activity</a></nav>';

    echo '<section class="eum-dtc__panel" id="dtc-video-overview">';
    echo '<div class="eum-dtc__panel-head"><h3>Overview</h3><span>Lifecycle status, concept, placeholder ID, and core metadata live at the project level.</span></div>';
    if ($this->can_manage_video($video_id, $user_id) || $this->can_edit_video_tab($video_id, $user_id, 'overview') || $this->can_edit_video_tab($video_id, $user_id, 'platform')) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__form-grid">';
      echo '<input type="hidden" name="action" value="eum_dtc_save_video"><input type="hidden" name="video_id" value="' . (int)$video_id . '">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Project Title</span><input type="text" name="title" value="' . esc_attr((string)$video->post_title) . '"' . ($this->can_edit_video_tab($video_id, $user_id, 'overview') || $this->can_manage_video($video_id, $user_id) ? '' : ' readonly') . '></label>';
      echo '<label><span>Status</span><select name="status"' . ($this->can_edit_video_tab($video_id, $user_id, 'overview') || $this->can_manage_video($video_id, $user_id) ? '' : ' disabled') . '>' . $this->select_options($this->status_options(), (string)$video_meta['status']) . '</select></label>';
      echo '<label><span>Placeholder / Video ID</span><input type="text" name="placeholder_id" value="' . esc_attr((string)$video_meta['placeholder_id']) . '"' . ($this->can_edit_video_tab($video_id, $user_id, 'platform') || $this->can_manage_video($video_id, $user_id) ? '' : ' readonly') . '></label>';
      echo '<label><span>Tags</span><input type="text" name="tags" value="' . esc_attr((string)$video_meta['tags']) . '"' . ($this->can_edit_video_tab($video_id, $user_id, 'platform') || $this->can_manage_video($video_id, $user_id) ? '' : ' readonly') . '></label>';
      echo '<label class="eum-dtc__full"><span>Concept / Idea</span><textarea name="concept" rows="3"' . ($this->can_edit_video_tab($video_id, $user_id, 'overview') || $this->can_manage_video($video_id, $user_id) ? '' : ' readonly') . '>' . esc_textarea((string)$video_meta['concept']) . '</textarea></label>';
      echo '<label class="eum-dtc__full"><span>Description / Metadata</span><textarea name="description" rows="5"' . ($this->can_edit_video_tab($video_id, $user_id, 'platform') || $this->can_manage_video($video_id, $user_id) ? '' : ' readonly') . '>' . esc_textarea((string)$video_meta['description']) . '</textarea></label>';
      echo '<div class="eum-dtc__full"><button class="button button-primary">Save Project</button></div>';
      echo '</form>';
    } else {
      echo '<div class="eum-dtc__card-meta"><span>Status: ' . esc_html($this->status_label((string)$video_meta['status'])) . '</span><span>Placeholder: ' . esc_html((string)($video_meta['placeholder_id'] ?: 'Not set')) . '</span><span>Tags: ' . esc_html((string)($video_meta['tags'] ?: 'Not set')) . '</span></div>';
      echo '<div class="eum-dtc__text-block"><h4>Description</h4><p>' . nl2br(esc_html((string)$video_meta['description'])) . '</p></div>';
    }
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-video-research">';
    echo '<div class="eum-dtc__panel-head"><h3>Research</h3><span>Concept notes, research links, and discovery work stay attached to the project and can be restored later.</span></div>';
    if ($this->can_edit_video_tab($video_id, $user_id, 'research') || $this->can_manage_video($video_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_dtc_save_video"><input type="hidden" name="video_id" value="' . (int)$video_id . '">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Research Notes / Links</span><textarea name="research" rows="12" class="large-text">' . esc_textarea((string)$video_meta['research']) . '</textarea></label>';
      echo '<p style="margin-top:12px;"><button class="button button-primary">Save Research</button></p>';
      echo '</form>';
    } else {
      echo '<div class="eum-dtc__text-block"><p>' . nl2br(esc_html((string)($video_meta['research'] ?: 'No research notes yet.'))) . '</p></div>';
    }
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-video-script">';
    echo '<div class="eum-dtc__panel-head"><h3>Script</h3><span>Scripts are never overwritten without a historical snapshot available for rollback.</span></div>';
    if ($this->can_edit_video_tab($video_id, $user_id, 'script') || $this->can_manage_video($video_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_dtc_save_video"><input type="hidden" name="video_id" value="' . (int)$video_id . '">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Script Draft</span><textarea name="script" rows="16" class="large-text code">' . esc_textarea((string)$video_meta['script']) . '</textarea></label>';
      echo '<p style="margin-top:12px;"><button class="button button-primary">Save Script</button></p>';
      echo '</form>';
    } else {
      echo '<div class="eum-dtc__text-block"><p>' . nl2br(esc_html((string)($video_meta['script'] ?: 'No script added yet.'))) . '</p></div>';
    }
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-video-assets">';
    echo '<div class="eum-dtc__panel-head"><h3>Assets & Version Tree</h3><span>Every uploaded file becomes a new version, and any previous version can be restored or downloaded.</span></div>';
    if ($this->can_edit_video_tab($video_id, $user_id, 'assets') || $this->can_manage_video($video_id, $user_id)) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__form-grid">';
      echo '<input type="hidden" name="action" value="eum_dtc_upload_asset"><input type="hidden" name="video_id" value="' . (int)$video_id . '">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Asset Family Label</span><input type="text" name="asset_label" placeholder="Master Edit, Final MP4, Thumbnail PSD" required></label>';
      echo '<label><span>Asset Group</span><select name="asset_group">' . $this->select_options($this->asset_group_options(), 'general') . '</select></label>';
      echo '<label><span>Asset Kind</span><input type="text" name="asset_kind" placeholder=".prproj, .psd, .mp4, .wav"></label>';
      echo '<label><span>Upload File</span><input type="file" name="asset_file" required></label>';
      echo '<label class="eum-dtc__full"><span>Version Notes</span><textarea name="notes" rows="2"></textarea></label>';
      echo '<div class="eum-dtc__full"><button class="button button-primary">Upload New Version</button></div>';
      echo '</form>';
    }
    echo $this->render_asset_tree($video_id, $assets, $user_id);
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-video-platform">';
    echo '<div class="eum-dtc__panel-head"><h3>Platform & Publishing</h3><span>The adapter layer keeps YouTube-specific logic isolated while still letting the project act as the source of truth.</span></div>';
    echo '<div class="eum-dtc__grid">';
    echo '<article class="eum-dtc__card"><h4>Channel Connector</h4><p>' . esc_html((string)($channel_meta['connector_label'] ?? 'Unknown')) . '</p></article>';
    echo '<article class="eum-dtc__card"><h4>Tracked Video ID</h4><p>' . esc_html((string)($video_meta['placeholder_id'] ?: 'Not set')) . '</p></article>';
    echo '<article class="eum-dtc__card"><h4>Last Push</h4><p>' . esc_html((string)($video_meta['last_push_at'] ?: 'Never')) . '</p></article>';
    echo '</div>';
    if ($this->can_edit_video_tab($video_id, $user_id, 'platform') || $this->can_manage_video($video_id, $user_id)) {
      echo '<div class="eum-dtc__toolbar" style="margin-top:12px;">';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_dtc_push_video"><input type="hidden" name="video_id" value="' . (int)$video_id . '"><input type="hidden" name="mode" value="generate_placeholder">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<button class="button">Generate Placeholder ID</button></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_dtc_push_video"><input type="hidden" name="video_id" value="' . (int)$video_id . '"><input type="hidden" name="mode" value="push">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<button class="button button-primary">Push To Publish</button></form>';
      echo '</div>';
    }
    if ((string)$video_meta['last_push_result'] !== '') {
      echo '<div class="eum-dtc__text-block" style="margin-top:12px;"><strong>Last push result</strong><p>' . nl2br(esc_html((string)$video_meta['last_push_result'])) . '</p></div>';
    }
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-video-event">';
    echo '<div class="eum-dtc__panel-head"><h3>Event Roles & Signups</h3><span>Video-specific staffing roles tie into the event system while keeping role signup ownership on the project.</span></div>';
    if ($this->can_manage_video($video_id, $user_id)) {
      $event_defaults = $this->linked_event_defaults($video_id, $linked_event);
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__form-grid">';
      echo '<input type="hidden" name="action" value="eum_dtc_create_event"><input type="hidden" name="video_id" value="' . (int)$video_id . '">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Event Title</span><input type="text" name="event_title" value="' . esc_attr((string)$event_defaults['title']) . '" required></label>';
      echo '<label><span>Date</span><input type="date" name="event_date" value="' . esc_attr((string)$event_defaults['date']) . '" required></label>';
      echo '<label><span>Start Time</span><input type="time" name="event_start_time" value="' . esc_attr((string)$event_defaults['start_time']) . '" required></label>';
      echo '<label><span>End Time</span><input type="time" name="event_end_time" value="' . esc_attr((string)$event_defaults['end_time']) . '" required></label>';
      echo '<label><span>Location</span><input type="text" name="event_location" value="' . esc_attr((string)$event_defaults['location']) . '"></label>';
      echo '<label><span>Meeting Link</span><input type="url" name="event_meeting_link" value="' . esc_attr((string)$event_defaults['meeting_link']) . '"></label>';
      echo '<label class="eum-dtc__check"><input type="checkbox" name="event_is_remote" value="1"' . checked(!empty($event_defaults['is_remote']), true, false) . '> Remote event</label>';
      echo '<div class="eum-dtc__full"><button class="button button-primary">' . ($linked_event > 0 ? 'Update Linked Event' : 'Create Linked Event') . '</button></div>';
      echo '</form>';

      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__form-grid" style="margin-top:18px;">';
      echo '<input type="hidden" name="action" value="eum_dtc_add_event_role"><input type="hidden" name="video_id" value="' . (int)$video_id . '">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<label><span>Role Label</span><input type="text" name="role_label" required placeholder="On-Screen Talent, Director, Lighting"></label>';
      echo '<label><span>Seats</span><input type="number" min="1" name="seat_count" value="1"></label>';
      echo '<label><span>Invite Job Paths</span>' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_checkboxes('invited_job_paths', [], get_current_user_id(), ['enforce_manageable_job_paths' => false]) : '<select name="invited_job_paths[]" multiple size="6">' . $this->job_path_options_html(0, false, true) . '</select>') . '</label>';
      echo '<label><span>Invite Groups</span><select name="invited_groups[]" multiple size="6">' . $this->group_options_html() . '</select></label>';
      echo '<label><span>Invite Users</span><select name="invited_users[]" multiple size="6">' . $this->user_options_html() . '</select></label>';
      echo '<div class="eum-dtc__full"><button class="button">Add Project Role</button></div>';
      echo '</form>';
    }
    echo $this->render_event_role_cards($video_id, $event_roles, $user_id, $linked_event);
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-video-permissions">';
    echo '<div class="eum-dtc__panel-head"><h3>Permissions & Tab Rules</h3><span>Channel rights and project-specific rights layer together, then tab rules decide who can edit each workspace surface.</span></div>';
    if ($this->can_manage_video($video_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__full">';
      echo '<input type="hidden" name="action" value="eum_dtc_save_video"><input type="hidden" name="video_id" value="' . (int)$video_id . '"><input type="hidden" name="save_tab_rules" value="1">';
      wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
      echo '<div class="eum-dtc__grid">';
      foreach ($this->default_tab_rules() as $tab => $default_roles) {
        $selected = array_values(array_unique(array_map('sanitize_key', (array)($tab_rules[$tab] ?? $default_roles))));
        echo '<article class="eum-dtc__card"><h4>' . esc_html(ucwords(str_replace('_', ' ', $tab))) . '</h4>';
        foreach ($this->permission_options() as $role_key => $role_label) {
          echo '<label class="eum-dtc__check"><input type="checkbox" name="tab_rules[' . esc_attr($tab) . '][]" value="' . esc_attr($role_key) . '"' . checked(in_array($role_key, $selected, true), true, false) . '> ' . esc_html($role_label) . '</label>';
        }
        echo '</article>';
      }
      echo '</div><p style="margin-top:14px;"><button class="button button-primary">Save Tab Rules</button></p></form>';
    }

    echo '<div style="margin-top:18px;">' . $this->render_permission_table($this->video_permissions($video_id), 'video', $video_id, $user_id) . '</div>';
    if ($this->can_manage_video($video_id, $user_id)) {
      echo $this->render_permission_form('video', $video_id);
    }
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-video-history">';
    echo '<div class="eum-dtc__panel-head"><h3>Metadata Snapshot History</h3><span>Title, metadata, idea notes, research, and script revisions can be reviewed and restored without losing later work.</span></div>';
    echo $this->render_snapshot_list($snapshots, $video_id, $user_id);
    echo '</section>';

    echo '<section class="eum-dtc__panel" id="dtc-video-activity">';
    echo '<div class="eum-dtc__panel-head"><h3>Activity</h3><span>This is the local audit trail for project saves, uploads, publishing attempts, staffing, and deletion/cancel actions.</span></div>';
    echo $this->render_activity_list((array)get_post_meta($video_id, self::META_VIDEO_ACTIVITY, true));
    if ($this->can_manage_video($video_id, $user_id)) {
      echo '<div class="eum-dtc__toolbar" style="margin-top:12px;">';
      if ($this->current_user_can_hard_delete($user_id)) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Hard delete this video project?\');">';
        echo '<input type="hidden" name="action" value="eum_dtc_delete_video"><input type="hidden" name="video_id" value="' . (int)$video_id . '"><input type="hidden" name="hard_delete" value="1">';
        wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
        echo '<button class="button button-link-delete">Hard Delete</button></form>';
      } else {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Cancel this project?\');">';
        echo '<input type="hidden" name="action" value="eum_dtc_delete_video"><input type="hidden" name="video_id" value="' . (int)$video_id . '">';
        wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
        echo '<button class="button">Mark Canceled</button></form>';
      }
      echo '</div>';
    }
    echo '</section>';

    echo '</div>';
    return (string)ob_get_clean();
  }

  public function handle_create_channel(): void {
    $user_id = $this->verify_post();
    if (!$this->current_user_can_create_channel($user_id)) wp_die('Not allowed.');

    $channel_id = wp_insert_post([
      'post_type' => self::CPT_CHANNEL,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field((string)($_POST['channel_title'] ?? 'New DTC Channel')),
      'post_author' => $user_id,
    ], true);
    if (is_wp_error($channel_id) || (int)$channel_id <= 0) {
      $this->redirect_notice([], 'The channel could not be created.');
    }

    $channel_id = (int)$channel_id;
    update_post_meta($channel_id, self::META_CHANNEL_STATUS, 'active');
    update_post_meta($channel_id, self::META_CHANNEL_DESCRIPTION, sanitize_textarea_field((string)($_POST['description'] ?? '')));
    update_post_meta($channel_id, self::META_CHANNEL_BUDGET, (float)($_POST['budget'] ?? 0));
    update_post_meta($channel_id, self::META_CHANNEL_FINANCE_PATH_TERM_ID, max(0, (int)($_POST['finance_path_term_id'] ?? 0)));
    update_post_meta($channel_id, self::META_CHANNEL_BILLING_JOB_PATH_TERM_ID, max(0, (int)($_POST['billing_job_path_term_id'] ?? 0)));
    update_post_meta($channel_id, self::META_CHANNEL_DIVISION, $this->sanitize_division((string)($_POST['division'] ?? '')));
    update_post_meta($channel_id, self::META_CHANNEL_CONNECTOR_KEY, sanitize_key((string)($_POST['connector_key'] ?? 'youtube')));
    update_post_meta($channel_id, self::META_CHANNEL_CONNECTOR_CONFIG, $this->normalize_connector_config_from_request((string)($_POST['connector_key'] ?? 'youtube')));
    update_post_meta($channel_id, self::META_CHANNEL_PERMISSIONS, [[
      'id' => wp_generate_uuid4(),
      'subject_type' => 'user',
      'subject_id' => $user_id,
      'permission' => 'manager',
      'inherit_mode' => 'live',
      'source_note' => 'Channel creator',
    ]]);

    $this->ensure_channel_group_term($channel_id);
    $this->push_activity($channel_id, self::META_CHANNEL_ACTIVITY, $user_id, 'Channel created');
    $this->sync_access_groups();
    do_action('eum_activity_logged', 'DTC channel created: ' . get_the_title($channel_id), ['source' => 'portal', 'type' => 'dtc_channel_created', 'user_id' => $user_id, 'details' => ['channel_id' => $channel_id]]);
    $this->redirect_notice(['dtc_channel_id' => $channel_id], 'DTC channel created.');
  }

  public function handle_save_channel(): void {
    $user_id = $this->verify_post();
    $channel_id = max(0, (int)($_POST['channel_id'] ?? 0));
    if ($channel_id <= 0 || !$this->can_manage_channel($channel_id, $user_id)) wp_die('Not allowed.');

    wp_update_post([
      'ID' => $channel_id,
      'post_title' => sanitize_text_field((string)($_POST['channel_title'] ?? get_the_title($channel_id))),
    ]);
    update_post_meta($channel_id, self::META_CHANNEL_STATUS, sanitize_key((string)($_POST['status'] ?? 'active')));
    update_post_meta($channel_id, self::META_CHANNEL_DESCRIPTION, sanitize_textarea_field((string)($_POST['description'] ?? '')));
    update_post_meta($channel_id, self::META_CHANNEL_BUDGET, (float)($_POST['budget'] ?? 0));
    update_post_meta($channel_id, self::META_CHANNEL_FINANCE_PATH_TERM_ID, max(0, (int)($_POST['finance_path_term_id'] ?? 0)));
    update_post_meta($channel_id, self::META_CHANNEL_BILLING_JOB_PATH_TERM_ID, max(0, (int)($_POST['billing_job_path_term_id'] ?? 0)));
    update_post_meta($channel_id, self::META_CHANNEL_DIVISION, $this->sanitize_division((string)($_POST['division'] ?? '')));
    update_post_meta($channel_id, self::META_CHANNEL_EXTERNAL_ID, sanitize_text_field((string)($_POST['external_id'] ?? '')));
    $connector_key = sanitize_key((string)($_POST['connector_key'] ?? 'youtube'));
    update_post_meta($channel_id, self::META_CHANNEL_CONNECTOR_KEY, $connector_key);
    update_post_meta($channel_id, self::META_CHANNEL_CONNECTOR_CONFIG, $this->normalize_connector_config_from_request($connector_key));

    $this->ensure_channel_group_term($channel_id);
    $this->push_activity($channel_id, self::META_CHANNEL_ACTIVITY, $user_id, 'Channel settings updated');
    $this->sync_access_groups();
    do_action('eum_activity_logged', 'DTC channel updated: ' . get_the_title($channel_id), ['source' => 'portal', 'type' => 'dtc_channel_updated', 'user_id' => $user_id, 'details' => ['channel_id' => $channel_id]]);
    $this->redirect_notice(['dtc_channel_id' => $channel_id], 'Channel saved.');
  }

  public function handle_create_video(): void {
    $user_id = $this->verify_post();
    $channel_id = max(0, (int)($_POST['channel_id'] ?? 0));
    if ($channel_id <= 0 || !$this->can_manage_channel($channel_id, $user_id)) wp_die('Not allowed.');

    $video_id = wp_insert_post([
      'post_type' => self::CPT_VIDEO,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field((string)($_POST['video_title'] ?? 'New DTC Video')),
      'post_author' => $user_id,
    ], true);
    if (is_wp_error($video_id) || (int)$video_id <= 0) {
      $this->redirect_notice(['dtc_channel_id' => $channel_id], 'The video project could not be created.');
    }

    $video_id = (int)$video_id;
    $placeholder_id = sanitize_text_field((string)($_POST['placeholder_id'] ?? ''));
    if ($placeholder_id === '' && !empty($_POST['generate_placeholder_id'])) {
      $placeholder_id = $this->generate_placeholder_id_for_video($channel_id, $video_id);
    }

    update_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, $channel_id);
    update_post_meta($video_id, self::META_VIDEO_STATUS, $this->normalize_status((string)($_POST['status'] ?? 'idea')));
    update_post_meta($video_id, self::META_VIDEO_CONCEPT, sanitize_textarea_field((string)($_POST['concept'] ?? '')));
    update_post_meta($video_id, self::META_VIDEO_RESEARCH, '');
    update_post_meta($video_id, self::META_VIDEO_SCRIPT, '');
    update_post_meta($video_id, self::META_VIDEO_DESCRIPTION, '');
    update_post_meta($video_id, self::META_VIDEO_TAGS, '');
    update_post_meta($video_id, self::META_VIDEO_PLACEHOLDER_ID, $placeholder_id);
    update_post_meta($video_id, self::META_VIDEO_PERMISSIONS, [[
      'id' => wp_generate_uuid4(),
      'subject_type' => 'user',
      'subject_id' => $user_id,
      'permission' => 'creator',
      'inherit_mode' => 'live',
      'source_note' => 'Video creator',
    ]]);
    update_post_meta($video_id, self::META_VIDEO_TAB_RULES, $this->default_tab_rules());
    update_post_meta($video_id, self::META_VIDEO_ASSETS, []);
    update_post_meta($video_id, self::META_VIDEO_EVENT_ROLES, []);

    $this->record_initial_snapshots($video_id, $user_id);
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Video project created');
    $this->sync_access_groups();
    do_action('eum_activity_logged', 'DTC video project created: ' . get_the_title($video_id), ['source' => 'portal', 'type' => 'dtc_video_created', 'user_id' => $user_id, 'details' => ['channel_id' => $channel_id, 'video_id' => $video_id]]);
    $this->redirect_notice(['dtc_channel_id' => $channel_id, 'dtc_video_id' => $video_id], 'Video project created.');
  }

  public function handle_save_video(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    if ($video_id <= 0 || !$this->can_view_video($video_id, $user_id)) wp_die('Not allowed.');

    $channel_id = (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true);
    $before = $this->snapshot_source_values($video_id);
    $changed = [];

    if ($this->can_manage_video($video_id, $user_id) || $this->can_edit_video_tab($video_id, $user_id, 'overview')) {
      $title = sanitize_text_field((string)($_POST['title'] ?? get_the_title($video_id)));
      $status = $this->normalize_status((string)($_POST['status'] ?? (string)get_post_meta($video_id, self::META_VIDEO_STATUS, true)));
      $concept = sanitize_textarea_field((string)($_POST['concept'] ?? (string)get_post_meta($video_id, self::META_VIDEO_CONCEPT, true)));
      if ($title !== $before['title']) $changed['title'] = [$before['title'], $title];
      if ($concept !== $before['concept']) $changed['concept'] = [$before['concept'], $concept];
      wp_update_post(['ID' => $video_id, 'post_title' => $title]);
      update_post_meta($video_id, self::META_VIDEO_STATUS, $status);
      update_post_meta($video_id, self::META_VIDEO_CONCEPT, $concept);
    }

    if ($this->can_manage_video($video_id, $user_id) || $this->can_edit_video_tab($video_id, $user_id, 'research')) {
      $research = sanitize_textarea_field((string)($_POST['research'] ?? (string)get_post_meta($video_id, self::META_VIDEO_RESEARCH, true)));
      if ($research !== $before['research']) $changed['research'] = [$before['research'], $research];
      update_post_meta($video_id, self::META_VIDEO_RESEARCH, $research);
    }

    if ($this->can_manage_video($video_id, $user_id) || $this->can_edit_video_tab($video_id, $user_id, 'script')) {
      $script = sanitize_textarea_field((string)($_POST['script'] ?? (string)get_post_meta($video_id, self::META_VIDEO_SCRIPT, true)));
      if ($script !== $before['script']) $changed['script'] = [$before['script'], $script];
      update_post_meta($video_id, self::META_VIDEO_SCRIPT, $script);
    }

    if ($this->can_manage_video($video_id, $user_id) || $this->can_edit_video_tab($video_id, $user_id, 'platform')) {
      $description = sanitize_textarea_field((string)($_POST['description'] ?? (string)get_post_meta($video_id, self::META_VIDEO_DESCRIPTION, true)));
      $tags = sanitize_text_field((string)($_POST['tags'] ?? (string)get_post_meta($video_id, self::META_VIDEO_TAGS, true)));
      $placeholder_id = sanitize_text_field((string)($_POST['placeholder_id'] ?? (string)get_post_meta($video_id, self::META_VIDEO_PLACEHOLDER_ID, true)));
      if ($description !== $before['description']) $changed['description'] = [$before['description'], $description];
      if ($tags !== $before['tags']) $changed['tags'] = [$before['tags'], $tags];
      update_post_meta($video_id, self::META_VIDEO_DESCRIPTION, $description);
      update_post_meta($video_id, self::META_VIDEO_TAGS, $tags);
      update_post_meta($video_id, self::META_VIDEO_PLACEHOLDER_ID, $placeholder_id);
    }

    if ($this->can_manage_video($video_id, $user_id) && !empty($_POST['save_tab_rules'])) {
      update_post_meta($video_id, self::META_VIDEO_TAB_RULES, $this->sanitize_tab_rules((array)($_POST['tab_rules'] ?? [])));
      $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Tab rules updated');
    }

    foreach ($changed as $key => $pair) {
      [$old_value, $new_value] = $pair;
      if ((string)$old_value === (string)$new_value) continue;
      $this->insert_snapshot($video_id, $key, $old_value, $user_id, 'Saved before update');
    }

    if (!empty($changed)) {
      $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Project metadata updated');
      do_action('eum_activity_logged', 'DTC video project updated: ' . get_the_title($video_id), ['source' => 'portal', 'type' => 'dtc_video_updated', 'user_id' => $user_id, 'details' => ['video_id' => $video_id, 'fields' => array_keys($changed)]]);
    }
    $this->sync_access_groups();
    $this->redirect_notice(['dtc_channel_id' => $channel_id, 'dtc_video_id' => $video_id], 'Project saved.');
  }

  public function handle_add_channel_permission(): void {
    $user_id = $this->verify_post();
    $channel_id = max(0, (int)($_POST['channel_id'] ?? 0));
    if ($channel_id <= 0 || !$this->can_manage_channel($channel_id, $user_id)) wp_die('Not allowed.');
    $rows = $this->channel_permissions($channel_id);
    $rows = array_merge($rows, $this->permission_rows_from_request());
    update_post_meta($channel_id, self::META_CHANNEL_PERMISSIONS, $rows);
    $this->push_activity($channel_id, self::META_CHANNEL_ACTIVITY, $user_id, 'Channel permissions updated');
    $this->sync_access_groups();
    $this->redirect_notice(['dtc_channel_id' => $channel_id], 'Channel permissions saved.');
  }

  public function handle_add_video_permission(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    if ($video_id <= 0 || !$this->can_manage_video($video_id, $user_id)) wp_die('Not allowed.');
    $rows = $this->video_permissions($video_id);
    $rows = array_merge($rows, $this->permission_rows_from_request());
    update_post_meta($video_id, self::META_VIDEO_PERMISSIONS, $rows);
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Video permissions updated');
    $this->sync_access_groups();
    $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'Video permissions saved.');
  }

  public function handle_delete_channel_permission(): void {
    $user_id = $this->verify_post();
    $channel_id = max(0, (int)($_POST['channel_id'] ?? 0));
    $permission_id = sanitize_text_field((string)($_POST['permission_id'] ?? ''));
    if ($channel_id <= 0 || !$this->can_manage_channel($channel_id, $user_id)) wp_die('Not allowed.');
    $rows = array_values(array_filter($this->channel_permissions($channel_id), fn($row) => (string)($row['id'] ?? '') !== $permission_id));
    update_post_meta($channel_id, self::META_CHANNEL_PERMISSIONS, $rows);
    $this->push_activity($channel_id, self::META_CHANNEL_ACTIVITY, $user_id, 'Channel permission removed');
    $this->sync_access_groups();
    $this->redirect_notice(['dtc_channel_id' => $channel_id], 'Channel permission removed.');
  }

  public function handle_delete_video_permission(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    $permission_id = sanitize_text_field((string)($_POST['permission_id'] ?? ''));
    if ($video_id <= 0 || !$this->can_manage_video($video_id, $user_id)) wp_die('Not allowed.');
    $rows = array_values(array_filter($this->video_permissions($video_id), fn($row) => (string)($row['id'] ?? '') !== $permission_id));
    update_post_meta($video_id, self::META_VIDEO_PERMISSIONS, $rows);
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Video permission removed');
    $this->sync_access_groups();
    $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'Video permission removed.');
  }

  public function handle_upload_asset(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    if ($video_id <= 0 || !($this->can_manage_video($video_id, $user_id) || $this->can_edit_video_tab($video_id, $user_id, 'assets'))) wp_die('Not allowed.');

    $attachment_id = $this->handle_media_upload('asset_file', $video_id);
    if ($attachment_id <= 0) {
      $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'Asset upload failed.');
    }

    $this->append_asset_version(
      $video_id,
      sanitize_text_field((string)($_POST['asset_label'] ?? 'Asset')),
      sanitize_key((string)($_POST['asset_group'] ?? 'general')),
      sanitize_text_field((string)($_POST['asset_kind'] ?? 'File')),
      $attachment_id,
      $user_id,
      sanitize_textarea_field((string)($_POST['notes'] ?? ''))
    );

    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'New asset version uploaded');
    do_action('eum_activity_logged', 'DTC asset uploaded for video #' . $video_id, ['source' => 'portal', 'type' => 'dtc_asset_uploaded', 'user_id' => $user_id, 'details' => ['video_id' => $video_id]]);
    $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'Asset version uploaded.');
  }

  public function handle_restore_asset(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    $asset_id = sanitize_text_field((string)($_POST['asset_id'] ?? ''));
    $version_id = sanitize_text_field((string)($_POST['version_id'] ?? ''));
    if ($video_id <= 0 || !$this->can_manage_video($video_id, $user_id)) wp_die('Not allowed.');
    $assets = $this->video_assets($video_id);
    foreach ($assets as &$asset) {
      if ((string)($asset['id'] ?? '') !== $asset_id) continue;
      $asset['current_version_id'] = $version_id;
    }
    unset($asset);
    update_post_meta($video_id, self::META_VIDEO_ASSETS, $assets);
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Asset version restored');
    $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'Asset version restored.');
  }

  public function handle_revert_snapshot(): void {
    $user_id = $this->verify_post();
    $snapshot_id = max(0, (int)($_POST['snapshot_id'] ?? 0));
    $snapshot = $this->snapshot_by_id($snapshot_id);
    if (empty($snapshot)) wp_die('Not allowed.');
    $video_id = (int)$snapshot['video_id'];
    if ($video_id <= 0 || !($this->can_manage_video($video_id, $user_id) || $this->can_edit_snapshot_key($video_id, $user_id, (string)$snapshot['snapshot_key']))) wp_die('Not allowed.');

    $current = $this->snapshot_value_for_key($video_id, (string)$snapshot['snapshot_key']);
    $this->insert_snapshot($video_id, (string)$snapshot['snapshot_key'], $current, $user_id, 'Captured before snapshot revert');
    $this->apply_snapshot_value($video_id, (string)$snapshot['snapshot_key'], (string)$snapshot['snapshot_value']);
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Snapshot reverted for ' . (string)$snapshot['snapshot_key']);
    do_action('eum_activity_logged', 'DTC snapshot reverted', ['source' => 'portal', 'type' => 'dtc_snapshot_reverted', 'user_id' => $user_id, 'details' => ['video_id' => $video_id, 'snapshot_key' => (string)$snapshot['snapshot_key']]]);
    $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'Snapshot reverted.');
  }

  public function handle_add_event_role(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    if ($video_id <= 0 || !$this->can_manage_video($video_id, $user_id)) wp_die('Not allowed.');
    $roles = $this->video_event_roles($video_id);
    $roles[] = [
      'id' => wp_generate_uuid4(),
      'label' => sanitize_text_field((string)($_POST['role_label'] ?? 'Video Role')),
      'seat_count' => max(1, (int)($_POST['seat_count'] ?? 1)),
      'invited_user_ids' => $this->sanitize_id_array((array)($_POST['invited_users'] ?? [])),
      'invited_group_ids' => $this->sanitize_id_array((array)($_POST['invited_groups'] ?? [])),
      'invited_job_path_ids' => class_exists('EUM_Profiles') ? EUM_Profiles::expand_job_path_ids((array)($_POST['invited_job_paths'] ?? [])) : $this->sanitize_id_array((array)($_POST['invited_job_paths'] ?? [])),
      'signups' => [],
    ];
    update_post_meta($video_id, self::META_VIDEO_EVENT_ROLES, $roles);
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Event role added');
    $this->sync_access_groups();
    $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'Event role added.');
  }

  public function handle_remove_event_role(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    $role_id = sanitize_text_field((string)($_POST['role_id'] ?? ''));
    if ($video_id <= 0 || !$this->can_manage_video($video_id, $user_id)) wp_die('Not allowed.');
    $roles = array_values(array_filter($this->video_event_roles($video_id), fn($row) => (string)($row['id'] ?? '') !== $role_id));
    update_post_meta($video_id, self::META_VIDEO_EVENT_ROLES, $roles);
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Event role removed');
    $this->sync_access_groups();
    $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'Event role removed.');
  }

  public function handle_signup_event_role(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    $role_id = sanitize_text_field((string)($_POST['role_id'] ?? ''));
    if ($video_id <= 0 || !$this->can_view_video($video_id, $user_id)) wp_die('Not allowed.');
    $roles = $this->video_event_roles($video_id);
    foreach ($roles as &$row) {
      if ((string)($row['id'] ?? '') !== $role_id) continue;
      if (!$this->event_role_user_is_eligible($row, $video_id, $user_id)) {
        $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'You are not eligible for that project role.');
      }
      $signups = $this->event_role_signups($row);
      $existing_ids = array_values(array_map('intval', array_column($signups, 'user_id')));
      if (in_array($user_id, $existing_ids, true)) {
        $signups = array_values(array_filter($signups, fn($signup) => (int)($signup['user_id'] ?? 0) !== $user_id));
        $message = 'You were removed from that project role.';
      } else {
        if ((int)($row['seat_count'] ?? 1) > 0 && count($signups) >= (int)$row['seat_count']) {
          $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'That project role is already full.');
        }
        $signups[] = ['user_id' => $user_id, 'signed_up_at' => current_time('mysql')];
        $message = 'You signed up for that project role.';
      }
      $row['signups'] = $signups;
      $linked_event = (int)get_post_meta($video_id, self::META_VIDEO_EVENT_ID, true);
      if ($linked_event > 0) {
        $this->sync_event_rsvp_from_project_roles($linked_event, $video_id, $user_id);
      }
      break;
    }
    unset($row);
    update_post_meta($video_id, self::META_VIDEO_EVENT_ROLES, $roles);
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, $message);
    $this->sync_access_groups();
    $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], $message);
  }

  public function handle_create_event(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    if ($video_id <= 0 || !$this->can_manage_video($video_id, $user_id)) wp_die('Not allowed.');
    if (!class_exists('EUM_Events')) {
      $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'The Events module is not available.');
    }

    $date = sanitize_text_field((string)($_POST['event_date'] ?? ''));
    $start_time = sanitize_text_field((string)($_POST['event_start_time'] ?? '09:00'));
    $end_time = sanitize_text_field((string)($_POST['event_end_time'] ?? '10:00'));
    $start_utc = $this->local_datetime_to_utc($date . ' ' . $start_time, $user_id);
    $end_utc = $this->local_datetime_to_utc($date . ' ' . $end_time, $user_id);
    if ($start_utc <= 0 || $end_utc <= $start_utc) {
      $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'Please choose a valid event date and time range.');
    }

    $linked_event = (int)get_post_meta($video_id, self::META_VIDEO_EVENT_ID, true);
    $postarr = [
      'post_type' => EUM_Events::CPT,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field((string)($_POST['event_title'] ?? ('DTC Event - ' . get_the_title($video_id)))),
      'post_author' => $user_id,
    ];
    if ($linked_event > 0 && get_post_type($linked_event) === EUM_Events::CPT) {
      $postarr['ID'] = $linked_event;
      $saved = wp_update_post(wp_slash($postarr), true);
    } else {
      $saved = wp_insert_post(wp_slash($postarr), true);
    }
    if (is_wp_error($saved) || (int)$saved <= 0) {
      $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], 'The linked event could not be saved.');
    }

    $event_id = (int)$saved;
    $invitees = $this->event_invite_targets_for_video($video_id);
    update_post_meta($event_id, EUM_Events::META_START_UTC, $start_utc);
    update_post_meta($event_id, EUM_Events::META_END_UTC, $end_utc);
    update_post_meta($event_id, EUM_Events::META_LOCATION, sanitize_text_field((string)($_POST['event_location'] ?? '')));
    update_post_meta($event_id, EUM_Events::META_IS_REMOTE, !empty($_POST['event_is_remote']) ? '1' : '0');
    update_post_meta($event_id, EUM_Events::META_MEETING_LINK, esc_url_raw((string)($_POST['event_meeting_link'] ?? '')));
    update_post_meta($event_id, EUM_Events::META_ACCESS_MODE, (!empty($invitees['users']) || !empty($invitees['job_paths']) || !empty($invitees['groups'])) ? 'invite' : 'open');
    update_post_meta($event_id, EUM_Events::META_MIN_ATTENDEES, 0);
    update_post_meta($event_id, EUM_Events::META_MAX_ATTENDEES, max(0, array_sum(array_map(fn($row) => max(1, (int)($row['seat_count'] ?? 1)), $this->video_event_roles($video_id)))));
    update_post_meta($event_id, EUM_Events::META_INVITED_USERS, $invitees['users']);
    update_post_meta($event_id, EUM_Events::META_INVITED_JOB_PATHS, $invitees['job_paths']);
    update_post_meta($event_id, EUM_Events::META_INVITED_GROUPS, $invitees['groups']);
    update_post_meta($event_id, EUM_Events::META_INVITED_PROJECTS, []);
    update_post_meta($event_id, EUM_Events::META_INVITED_LOCATIONS, []);
    update_post_meta($video_id, self::META_VIDEO_EVENT_ID, $event_id);

    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, $linked_event > 0 ? 'Linked event updated' : 'Linked event created');
    do_action('eum_activity_logged', 'DTC linked event saved', ['source' => 'portal', 'type' => 'dtc_event_saved', 'user_id' => $user_id, 'details' => ['video_id' => $video_id, 'event_id' => $event_id]]);
    $this->redirect_notice(['dtc_channel_id' => (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), 'dtc_video_id' => $video_id], $linked_event > 0 ? 'Linked event updated.' : 'Linked event created.');
  }

  public function handle_push_video(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    if ($video_id <= 0 || !($this->can_manage_video($video_id, $user_id) || $this->can_edit_video_tab($video_id, $user_id, 'platform'))) wp_die('Not allowed.');
    $channel_id = (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true);
    $channel_row = $this->channel_row($channel_id);
    $video_row = $this->video_row($video_id, $user_id);
    $connector = $this->connector_for_key((string)$channel_row['connector_key']);
    $mode = sanitize_key((string)($_POST['mode'] ?? 'push'));

    if ($mode === 'generate_placeholder') {
      $placeholder_id = $connector->generate_placeholder_id($channel_row, $video_row);
      update_post_meta($video_id, self::META_VIDEO_PLACEHOLDER_ID, $placeholder_id);
      $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Connector placeholder ID generated');
      $this->redirect_notice(['dtc_channel_id' => $channel_id, 'dtc_video_id' => $video_id], 'Placeholder ID generated.');
    }

    $result = $connector->push_video($channel_row, $video_row + ['video_id' => $video_id, 'primary_video_attachment_id' => $this->current_primary_video_attachment_id($video_id)]);
    update_post_meta($video_id, self::META_VIDEO_LAST_PUSH_AT, current_time('mysql'));
    update_post_meta($video_id, self::META_VIDEO_LAST_PUSH_RESULT, sanitize_textarea_field((string)($result['message'] ?? 'Push attempted.')));
    if (!empty($result['placeholder_id'])) {
      update_post_meta($video_id, self::META_VIDEO_PLACEHOLDER_ID, sanitize_text_field((string)$result['placeholder_id']));
    }
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Push-to-publish executed');
    do_action('eum_activity_logged', 'DTC push-to-publish executed', ['source' => 'portal', 'type' => 'dtc_push', 'user_id' => $user_id, 'details' => ['video_id' => $video_id, 'connector' => $connector->key()]]);
    $this->redirect_notice(['dtc_channel_id' => $channel_id, 'dtc_video_id' => $video_id], (string)($result['message'] ?? 'Push attempted.'));
  }

  public function handle_delete_video(): void {
    $user_id = $this->verify_post();
    $video_id = max(0, (int)($_POST['video_id'] ?? 0));
    if ($video_id <= 0 || !$this->can_manage_video($video_id, $user_id)) wp_die('Not allowed.');
    $channel_id = (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true);
    if (!empty($_POST['hard_delete']) && $this->current_user_can_hard_delete($user_id)) {
      wp_delete_post($video_id, true);
      do_action('eum_activity_logged', 'DTC video hard deleted', ['source' => 'portal', 'type' => 'dtc_video_hard_deleted', 'user_id' => $user_id, 'details' => ['video_id' => $video_id]]);
      $this->sync_access_groups();
      $this->redirect_notice(['dtc_channel_id' => $channel_id], 'Video project hard deleted.');
    }
    update_post_meta($video_id, self::META_VIDEO_STATUS, 'canceled');
    $this->push_activity($video_id, self::META_VIDEO_ACTIVITY, $user_id, 'Video project marked canceled');
    do_action('eum_activity_logged', 'DTC video canceled', ['source' => 'portal', 'type' => 'dtc_video_canceled', 'user_id' => $user_id, 'details' => ['video_id' => $video_id]]);
    $this->redirect_notice(['dtc_channel_id' => $channel_id, 'dtc_video_id' => $video_id], 'Video project marked canceled.');
  }

  public function handle_delete_channel(): void {
    $user_id = $this->verify_post();
    $channel_id = max(0, (int)($_POST['channel_id'] ?? 0));
    if ($channel_id <= 0 || !$this->can_manage_channel($channel_id, $user_id)) wp_die('Not allowed.');
    if (!empty($_POST['hard_delete']) && $this->current_user_can_hard_delete($user_id)) {
      foreach ($this->video_ids_for_channel($channel_id) as $video_id) {
        wp_delete_post((int)$video_id, true);
      }
      wp_delete_post($channel_id, true);
      do_action('eum_activity_logged', 'DTC channel hard deleted', ['source' => 'portal', 'type' => 'dtc_channel_hard_deleted', 'user_id' => $user_id, 'details' => ['channel_id' => $channel_id]]);
      $this->sync_access_groups();
      $this->redirect_notice([], 'Channel hard deleted.');
    }
    update_post_meta($channel_id, self::META_CHANNEL_STATUS, 'canceled');
    $this->push_activity($channel_id, self::META_CHANNEL_ACTIVITY, $user_id, 'Channel marked canceled');
    do_action('eum_activity_logged', 'DTC channel canceled', ['source' => 'portal', 'type' => 'dtc_channel_canceled', 'user_id' => $user_id, 'details' => ['channel_id' => $channel_id]]);
    $this->redirect_notice(['dtc_channel_id' => $channel_id], 'Channel marked canceled.');
  }

  public function handle_sync_groups(): void {
    try {
      $user_id = $this->verify_post();
      if (!$this->current_user_can_hard_delete($user_id)) wp_die('Not allowed.');
      $channel_id = max(0, (int)($_POST['channel_id'] ?? 0));
      $this->sync_access_groups();
      $this->redirect_notice($channel_id > 0 ? ['dtc_channel_id' => $channel_id] : [], 'DTC access groups synced.');
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_sync_groups', $e);
      wp_die('DTC access groups could not be synced.');
    }
  }

  public function handle_profile_change(int $user_id, $old_user_data = null): void {
    try {
      $this->schedule_access_group_sync();
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_profile_change', $e);
    }
  }

  public function handle_user_register(int $user_id): void {
    try {
      $this->schedule_access_group_sync();
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_user_register', $e);
    }
  }

  public function handle_set_object_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void {
    try {
      if (self::$syncing_access_groups) return;
      if (!in_array((string)$taxonomy, ['eum_user_group', 'eum_job_path'], true)) return;
      if (get_userdata((int)$object_id)) {
        $this->schedule_access_group_sync();
      }
    } catch (\Throwable $e) {
      $this->log_runtime_issue('handle_set_object_terms', $e);
    }
  }

  public function maybe_sync_access_groups_on_shutdown(): void {
    try {
      if (!self::$sync_access_groups_needed || self::$syncing_access_groups) return;
      $this->sync_access_groups();
    } catch (\Throwable $e) {
      $this->log_runtime_issue('maybe_sync_access_groups_on_shutdown', $e);
    }
  }

  private function visible_channel_rows_for_user(int $user_id): array {
    $rows = [];
    foreach ($this->all_channel_ids() as $channel_id) {
      if (!$this->can_view_channel((int)$channel_id, $user_id)) continue;
      $rows[] = $this->channel_row((int)$channel_id, $user_id);
    }
    return $rows;
  }

  private function visible_video_rows_for_user(int $user_id): array {
    $rows = [];
    foreach ($this->all_video_ids() as $video_id) {
      if (!$this->can_view_video((int)$video_id, $user_id)) continue;
      $rows[] = $this->video_row((int)$video_id, $user_id);
    }
    usort($rows, fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    return $rows;
  }

  private function channel_row(int $channel_id, int $user_id = 0): array {
    $post = get_post($channel_id);
    $division = sanitize_key((string)get_post_meta($channel_id, self::META_CHANNEL_DIVISION, true));
    $connector_key = sanitize_key((string)get_post_meta($channel_id, self::META_CHANNEL_CONNECTOR_KEY, true));
    $connector = $this->connector_for_key($connector_key);
    return [
      'id' => $channel_id,
      'title' => $post ? (string)$post->post_title : 'Unknown Channel',
      'description' => (string)get_post_meta($channel_id, self::META_CHANNEL_DESCRIPTION, true),
      'status' => sanitize_key((string)get_post_meta($channel_id, self::META_CHANNEL_STATUS, true)) ?: 'active',
      'budget' => (float)get_post_meta($channel_id, self::META_CHANNEL_BUDGET, true),
      'finance_path_term_id' => (int)get_post_meta($channel_id, self::META_CHANNEL_FINANCE_PATH_TERM_ID, true),
      'billing_job_path_term_id' => (int)get_post_meta($channel_id, self::META_CHANNEL_BILLING_JOB_PATH_TERM_ID, true),
      'division' => $division,
      'division_label' => $this->division_label($division),
      'connector_key' => $connector_key ?: 'youtube',
      'connector_label' => $connector->label(),
      'connector_config' => $this->channel_connector_config($channel_id),
      'external_id' => (string)get_post_meta($channel_id, self::META_CHANNEL_EXTERNAL_ID, true),
      'roles' => $user_id > 0 ? $this->roles_for_channel($channel_id, $user_id) : [],
      'video_count' => count($this->video_ids_for_channel($channel_id)),
      'updated_at' => $post ? (string)$post->post_modified : '',
    ];
  }

  private function video_row(int $video_id, int $user_id = 0): array {
    $post = get_post($video_id);
    $channel_id = (int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true);
    $channel = $channel_id > 0 ? get_post($channel_id) : null;
    return [
      'id' => $video_id,
      'channel_id' => $channel_id,
      'title' => $post ? (string)$post->post_title : 'Unknown Project',
      'status' => $this->normalize_status((string)get_post_meta($video_id, self::META_VIDEO_STATUS, true)),
      'concept' => (string)get_post_meta($video_id, self::META_VIDEO_CONCEPT, true),
      'research' => (string)get_post_meta($video_id, self::META_VIDEO_RESEARCH, true),
      'script' => (string)get_post_meta($video_id, self::META_VIDEO_SCRIPT, true),
      'description' => (string)get_post_meta($video_id, self::META_VIDEO_DESCRIPTION, true),
      'tags' => (string)get_post_meta($video_id, self::META_VIDEO_TAGS, true),
      'placeholder_id' => (string)get_post_meta($video_id, self::META_VIDEO_PLACEHOLDER_ID, true),
      'roles' => $user_id > 0 ? $this->roles_for_video($video_id, $user_id) : [],
      'channel_title' => $channel ? (string)$channel->post_title : 'Unknown Channel',
      'updated_at' => $post ? (string)$post->post_modified : '',
      'last_push_at' => (string)get_post_meta($video_id, self::META_VIDEO_LAST_PUSH_AT, true),
      'last_push_result' => (string)get_post_meta($video_id, self::META_VIDEO_LAST_PUSH_RESULT, true),
    ];
  }

  private function videos_for_channel(int $channel_id, int $user_id): array {
    $rows = [];
    foreach ($this->video_ids_for_channel($channel_id) as $video_id) {
      if (!$this->can_view_video((int)$video_id, $user_id)) continue;
      $rows[] = $this->video_row((int)$video_id, $user_id);
    }
    usort($rows, fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    return $rows;
  }

  private function all_channel_ids(): array {
    return array_values(array_map('intval', (array)get_posts([
      'post_type' => self::CPT_CHANNEL,
      'post_status' => 'publish',
      'numberposts' => 500,
      'fields' => 'ids',
      'orderby' => 'title',
      'order' => 'ASC',
    ])));
  }

  private function all_video_ids(): array {
    return array_values(array_map('intval', (array)get_posts([
      'post_type' => self::CPT_VIDEO,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'fields' => 'ids',
      'orderby' => 'modified',
      'order' => 'DESC',
    ])));
  }

  private function video_ids_for_channel(int $channel_id): array {
    return array_values(array_map('intval', (array)get_posts([
      'post_type' => self::CPT_VIDEO,
      'post_status' => 'publish',
      'numberposts' => 1000,
      'fields' => 'ids',
      'meta_key' => self::META_VIDEO_CHANNEL_ID,
      'meta_value' => $channel_id,
      'orderby' => 'modified',
      'order' => 'DESC',
    ])));
  }

  private function channel_permissions(int $channel_id): array {
    $rows = get_post_meta($channel_id, self::META_CHANNEL_PERMISSIONS, true);
    return is_array($rows) ? array_values($rows) : [];
  }

  private function video_permissions(int $video_id): array {
    $rows = get_post_meta($video_id, self::META_VIDEO_PERMISSIONS, true);
    return is_array($rows) ? array_values($rows) : [];
  }

  private function video_assets(int $video_id): array {
    $rows = get_post_meta($video_id, self::META_VIDEO_ASSETS, true);
    return is_array($rows) ? array_values($rows) : [];
  }

  private function video_event_roles(int $video_id): array {
    $rows = get_post_meta($video_id, self::META_VIDEO_EVENT_ROLES, true);
    return is_array($rows) ? array_values($rows) : [];
  }

  private function channel_connector_config(int $channel_id): array {
    $config = get_post_meta($channel_id, self::META_CHANNEL_CONNECTOR_CONFIG, true);
    return is_array($config) ? $config : [];
  }

  private function default_tab_rules(): array {
    return [
      'overview' => ['manager', 'creator', 'editor', 'researcher', 'scriptwriter'],
      'research' => ['manager', 'creator', 'researcher', 'scriptwriter'],
      'script' => ['manager', 'creator', 'scriptwriter'],
      'assets' => ['manager', 'creator', 'editor', 'asset_manager'],
      'platform' => ['manager', 'creator', 'editor'],
      'event' => ['manager', 'creator'],
      'permissions' => ['manager'],
      'history' => ['manager', 'creator', 'editor', 'researcher', 'scriptwriter', 'asset_manager'],
      'activity' => ['manager', 'creator', 'editor', 'researcher', 'scriptwriter', 'asset_manager', 'viewer'],
    ];
  }

  private function video_tab_rules(int $video_id): array {
    $stored = get_post_meta($video_id, self::META_VIDEO_TAB_RULES, true);
    return $this->sanitize_tab_rules(is_array($stored) ? $stored : []);
  }

  private function sanitize_tab_rules(array $raw): array {
    $defaults = $this->default_tab_rules();
    $allowed_roles = array_keys($this->permission_options());
    $out = [];
    foreach ($defaults as $tab => $fallback) {
      $rows = array_values(array_unique(array_filter(array_map('sanitize_key', (array)($raw[$tab] ?? $fallback)), fn($role) => in_array($role, $allowed_roles, true))));
      if (empty($rows)) $rows = $fallback;
      $out[$tab] = $rows;
    }
    return $out;
  }

  private function current_user_can_create_channel(int $user_id): bool {
    if ($user_id <= 0) return false;
    return class_exists('EUM_Access') && EUM_Access::is_it_user($user_id);
  }

  private function current_user_can_hard_delete(int $user_id): bool {
    return $user_id > 0 && class_exists('EUM_Access') && EUM_Access::is_it_user($user_id);
  }

  private function can_view_channel(int $channel_id, int $user_id): bool {
    if ($channel_id <= 0 || $user_id <= 0) return false;
    if ($this->current_user_can_hard_delete($user_id) || (class_exists('EUM_Access') && EUM_Access::is_hr_user($user_id))) return true;
    if (!empty($this->roles_for_channel($channel_id, $user_id))) return true;
    foreach ($this->video_ids_for_channel($channel_id) as $video_id) {
      if ($this->can_view_video((int)$video_id, $user_id)) return true;
    }
    return false;
  }

  private function can_manage_channel(int $channel_id, int $user_id): bool {
    if ($channel_id <= 0 || $user_id <= 0) return false;
    if ($this->current_user_can_hard_delete($user_id) || (class_exists('EUM_Access') && EUM_Access::is_hr_user($user_id))) return true;
    $roles = $this->roles_for_channel($channel_id, $user_id);
    return in_array('manager', $roles, true) || in_array('creator', $roles, true);
  }

  private function can_view_video(int $video_id, int $user_id): bool {
    if ($video_id <= 0 || $user_id <= 0) return false;
    if ($this->current_user_can_hard_delete($user_id) || (class_exists('EUM_Access') && EUM_Access::is_hr_user($user_id))) return true;
    return !empty($this->roles_for_video($video_id, $user_id));
  }

  private function can_manage_video(int $video_id, int $user_id): bool {
    if ($video_id <= 0 || $user_id <= 0) return false;
    if ($this->current_user_can_hard_delete($user_id) || (class_exists('EUM_Access') && EUM_Access::is_hr_user($user_id))) return true;
    $roles = $this->roles_for_video($video_id, $user_id);
    return in_array('manager', $roles, true) || in_array('creator', $roles, true);
  }

  private function can_edit_video_tab(int $video_id, int $user_id, string $tab): bool {
    if ($this->can_manage_video($video_id, $user_id)) return true;
    $tab = sanitize_key($tab);
    $rules = $this->video_tab_rules($video_id);
    $allowed = array_values(array_unique(array_map('sanitize_key', (array)($rules[$tab] ?? []))));
    if (empty($allowed)) return false;
    return !empty(array_intersect($allowed, $this->roles_for_video($video_id, $user_id)));
  }

  private function can_edit_snapshot_key(int $video_id, int $user_id, string $key): bool {
    $map = [
      'title' => 'overview',
      'concept' => 'overview',
      'research' => 'research',
      'script' => 'script',
      'description' => 'platform',
      'tags' => 'platform',
    ];
    return $this->can_edit_video_tab($video_id, $user_id, (string)($map[$key] ?? 'history'));
  }

  private function roles_for_channel(int $channel_id, int $user_id): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return [];
    if ($this->current_user_can_hard_delete($user_id) || (class_exists('EUM_Access') && EUM_Access::is_hr_user($user_id))) {
      return array_keys($this->permission_options());
    }

    $roles = [];
    $post = get_post($channel_id);
    if ($post && (int)$post->post_author === $user_id) $roles[] = 'creator';
    foreach ($this->channel_permissions($channel_id) as $row) {
      if ($this->permission_row_matches_user($row, $user_id)) {
        $roles[] = sanitize_key((string)($row['permission'] ?? 'viewer'));
      }
    }
    return $this->normalize_roles($roles);
  }

  private function roles_for_video(int $video_id, int $user_id): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return [];
    if ($this->current_user_can_hard_delete($user_id) || (class_exists('EUM_Access') && EUM_Access::is_hr_user($user_id))) {
      return array_keys($this->permission_options());
    }

    $roles = $this->roles_for_channel((int)get_post_meta($video_id, self::META_VIDEO_CHANNEL_ID, true), $user_id);
    $post = get_post($video_id);
    if ($post && (int)$post->post_author === $user_id) $roles[] = 'creator';
    foreach ($this->video_permissions($video_id) as $row) {
      if ($this->permission_row_matches_user($row, $user_id)) {
        $roles[] = sanitize_key((string)($row['permission'] ?? 'viewer'));
      }
    }
    foreach ($this->video_event_roles($video_id) as $role_row) {
      foreach ($this->event_role_signups($role_row) as $signup) {
        if ((int)($signup['user_id'] ?? 0) === $user_id) $roles[] = 'viewer';
      }
    }
    return $this->normalize_roles($roles);
  }

  private function normalize_roles(array $roles): array {
    $allowed = array_keys($this->permission_options());
    $roles = array_values(array_unique(array_filter(array_map('sanitize_key', $roles), fn($role) => in_array($role, $allowed, true))));
    usort($roles, function ($a, $b) {
      return $this->permission_weight($b) <=> $this->permission_weight($a);
    });
    return $roles;
  }

  private function permission_weight(string $permission): int {
    $weights = ['viewer' => 1, 'researcher' => 2, 'scriptwriter' => 3, 'asset_manager' => 4, 'editor' => 5, 'creator' => 6, 'manager' => 7];
    return (int)($weights[$permission] ?? 0);
  }

  private function permission_row_matches_user(array $row, int $user_id): bool {
    $subject_type = sanitize_key((string)($row['subject_type'] ?? 'user'));
    $subject_id = (int)($row['subject_id'] ?? 0);
    if ($subject_id <= 0 || $user_id <= 0) return false;
    if ($subject_type === 'user') return $subject_id === $user_id;
    $context = $this->user_access_context($user_id);
    if ($subject_type === 'group') return in_array($subject_id, (array)$context['group_ids'], true);
    if ($subject_type === 'job_path') return in_array($subject_id, (array)$context['job_path_ids'], true);
    return false;
  }

  private function user_access_context(int $user_id): array {
    if (class_exists('EUM_Dynamic_Links') && method_exists('EUM_Dynamic_Links', 'access_context')) {
      $context = (array)EUM_Dynamic_Links::access_context($user_id);
      $context['job_path_ids'] = array_values(array_unique(array_filter(array_map('intval', (array)($context['job_path_ids'] ?? [])))));
      $context['group_ids'] = array_values(array_unique(array_filter(array_map('intval', (array)($context['group_ids'] ?? [])))));
      return $context;
    }
    return ['job_path_ids' => [], 'group_ids' => []];
  }

  private function permission_rows_from_request(): array {
    $subject_type = sanitize_key((string)($_POST['subject_type'] ?? 'user'));
    $inherit_mode = sanitize_key((string)($_POST['inherit_mode'] ?? 'live'));
    if (!in_array($subject_type, ['user', 'group', 'job_path'], true)) $subject_type = 'user';
    if (!in_array($inherit_mode, ['live', 'snapshot'], true)) $inherit_mode = 'live';
    $permission = sanitize_key((string)($_POST['permission'] ?? 'viewer'));
    if (!isset($this->permission_options()[$permission])) $permission = 'viewer';

    $subject_id = 0;
    if ($subject_type === 'user') $subject_id = max(0, (int)($_POST['subject_user_id'] ?? 0));
    if ($subject_type === 'group') $subject_id = max(0, (int)($_POST['subject_group_id'] ?? 0));
    if ($subject_type === 'job_path') $subject_id = max(0, (int)($_POST['subject_job_path_id'] ?? 0));
    if ($subject_id <= 0) return [];

    if ($inherit_mode === 'snapshot' && in_array($subject_type, ['group', 'job_path'], true)) {
      $materialized = [];
      foreach ($this->current_users_for_subject($subject_type, $subject_id) as $user_id) {
        $materialized[] = [
          'id' => wp_generate_uuid4(),
          'subject_type' => 'user',
          'subject_id' => (int)$user_id,
          'permission' => $permission,
          'inherit_mode' => 'snapshot',
          'source_note' => 'Snapshot from ' . $subject_type . ' #' . $subject_id . ' on ' . current_time('mysql'),
        ];
      }
      return $materialized;
    }

    return [[
      'id' => wp_generate_uuid4(),
      'subject_type' => $subject_type,
      'subject_id' => $subject_id,
      'permission' => $permission,
      'inherit_mode' => $inherit_mode,
      'source_note' => '',
    ]];
  }

  private function current_users_for_subject(string $subject_type, int $subject_id): array {
    return $this->resolved_user_ids_for_permission_subject($subject_type, $subject_id);
  }

  private function render_permission_table(array $rows, string $scope, int $object_id, int $user_id): string {
    if (empty($rows)) return '<div class="eum-dtc__empty">No ' . esc_html($scope) . '-level permissions have been added yet.</div>';
    ob_start();
    echo '<table class="widefat striped"><thead><tr><th>Subject</th><th>Permission</th><th>Mode</th><th>Resolved Members</th><th>Notes</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $permission_id = sanitize_text_field((string)($row['id'] ?? ''));
      $subject_type = sanitize_key((string)($row['subject_type'] ?? 'user'));
      $subject_id = (int)($row['subject_id'] ?? 0);
      $resolved_members = $this->permission_row_resolved_members_label($row);
      echo '<tr>';
      echo '<td>' . esc_html($this->permission_subject_label($subject_type, $subject_id)) . '</td>';
      echo '<td>' . esc_html($this->permission_label((string)($row['permission'] ?? 'viewer'))) . '</td>';
      echo '<td>' . esc_html(ucfirst((string)($row['inherit_mode'] ?? 'live'))) . '</td>';
      echo '<td>' . esc_html($resolved_members) . '</td>';
      echo '<td>' . esc_html((string)($row['source_note'] ?? '')) . '</td>';
      echo '<td>';
      if (($scope === 'channel' && $this->can_manage_channel($object_id, $user_id)) || ($scope === 'video' && $this->can_manage_video($object_id, $user_id))) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Remove this permission?\');">';
        echo '<input type="hidden" name="action" value="eum_dtc_delete_' . esc_attr($scope) . '_permission"><input type="hidden" name="' . esc_attr($scope) . '_id" value="' . (int)$object_id . '"><input type="hidden" name="permission_id" value="' . esc_attr($permission_id) . '">';
        wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
        echo '<button class="button button-small">Remove</button></form>';
      }
      echo '</td></tr>';
    }
    echo '</tbody></table>';
    return (string)ob_get_clean();
  }

  private function render_permission_form(string $scope, int $object_id): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-dtc__form-grid" style="margin-top:14px;">';
    echo '<input type="hidden" name="action" value="eum_dtc_add_' . esc_attr($scope) . '_permission"><input type="hidden" name="' . esc_attr($scope) . '_id" value="' . (int)$object_id . '">';
    wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
    echo '<label><span>Subject Type</span><select name="subject_type">' . $this->select_options(['user' => 'User', 'group' => 'Group', 'job_path' => 'Job Path'], 'user') . '</select></label>';
    echo '<label><span>User</span><select name="subject_user_id"><option value="0">Choose user...</option>' . $this->user_options_html() . '</select></label>';
    echo '<label><span>Group</span><select name="subject_group_id"><option value="0">Choose group...</option>' . $this->group_options_html() . '</select></label>';
    echo '<label><span>Job Path</span>' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_picker('subject_job_path_id', 0, get_current_user_id(), ['placeholder' => 'Choose job path...', 'none_label' => '— None —', 'enforce_manageable_job_paths' => false]) : '<select name="subject_job_path_id"><option value="0">Choose job path...</option>' . $this->job_path_options_html(0, false) . '</select>') . '</label>';
    echo '<label><span>Permission</span><select name="permission">' . $this->select_options($this->permission_options(), 'viewer') . '</select></label>';
    echo '<label><span>Inheritance Mode</span><select name="inherit_mode">' . $this->select_options(['live' => 'Live if membership stays true', 'snapshot' => 'Snapshot current members only'], 'live') . '</select></label>';
    echo '<div class="eum-dtc__full"><button class="button">Add Permission</button></div>';
    echo '</form>';
    return (string)ob_get_clean();
  }

  private function resolved_user_ids_for_permission_subject(string $subject_type, int $subject_id): array {
    $subject_type = sanitize_key($subject_type);
    $subject_id = (int)$subject_id;
    if ($subject_id <= 0 || !in_array($subject_type, ['user', 'group', 'job_path'], true)) return [];

    if ($subject_type === 'user') {
      return get_userdata($subject_id) ? [$subject_id] : [];
    }

    $probe = [
      'subject_type' => $subject_type,
      'subject_id' => $subject_id,
      'permission' => 'viewer',
    ];

    $matched = [];
    foreach ($this->all_user_ids() as $user_id) {
      if ($this->permission_row_matches_user($probe, $user_id)) {
        $matched[$user_id] = $user_id;
      }
    }

    return array_values($matched);
  }

  private function permission_row_resolved_members_label(array $row, int $limit = 5): string {
    $subject_type = sanitize_key((string)($row['subject_type'] ?? 'user'));
    $subject_id = (int)($row['subject_id'] ?? 0);
    $user_ids = $this->resolved_user_ids_for_permission_subject($subject_type, $subject_id);
    $count = count($user_ids);
    if ($count === 0) return 'No matching users right now';

    $labels = [];
    foreach (array_slice($user_ids, 0, max(1, $limit)) as $user_id) {
      $labels[] = $this->user_label((int)$user_id);
    }

    $summary = $count . ' member' . ($count === 1 ? '' : 's');
    if (!empty($labels)) {
      $summary .= ': ' . implode(', ', $labels);
    }
    if ($count > count($labels)) {
      $summary .= ' +' . ($count - count($labels)) . ' more';
    }

    return $summary;
  }

  private function render_channel_member_roles_table(int $channel_id): string {
    $rows = $this->resolved_channel_member_rows($channel_id);
    ob_start();
    echo '<div class="eum-dtc__subpanel"><h4>DTC Member Roles</h4><p class="description" style="margin-top:0;">Resolved from channel permissions, project permissions, creators, and role signups. This is the same audience used for DTC access-group sync.</p>';
    if (empty($rows)) {
      echo '<div class="eum-dtc__empty">No DTC members are currently resolved for this channel.</div>';
      echo '</div>';
      return (string)ob_get_clean();
    }

    echo '<table class="widefat striped"><thead><tr><th>Member</th><th>Email</th><th>DTC Role</th><th>Workspace Permissions</th><th>Access Source</th><th>DTC Groups</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      echo '<tr>';
      echo '<td>' . esc_html((string)$row['member_label']) . '</td>';
      echo '<td>' . esc_html((string)$row['email']) . '</td>';
      echo '<td>' . esc_html((string)$row['dtc_role_label']) . '</td>';
      echo '<td>' . esc_html((string)$row['roles_label']) . '</td>';
      echo '<td>' . esc_html((string)$row['access_source']) . '</td>';
      echo '<td>' . esc_html((string)$row['dtc_groups_label']) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function resolved_channel_member_rows(int $channel_id): array {
    $channel_id = (int)$channel_id;
    if ($channel_id <= 0) return [];

    $video_ids = $this->video_ids_for_channel($channel_id);
    $global_term = get_term_by('slug', self::GROUP_GLOBAL_SLUG, 'eum_user_group');
    $global_term_id = ($global_term && !is_wp_error($global_term)) ? (int)$global_term->term_id : 0;
    $channel_group_term_id = $this->ensure_channel_group_term($channel_id);
    $expected_group_ids = array_values(array_filter([$global_term_id, $channel_group_term_id], fn($id) => $id > 0));

    $rows = [];
    foreach ($this->resolved_access_user_ids_for_channel($channel_id) as $user_id) {
      $user_id = (int)$user_id;
      $user = get_userdata($user_id);
      if (!$user) continue;

      $channel_roles = $this->roles_for_channel($channel_id, $user_id);
      $effective_roles = $channel_roles;
      foreach ($video_ids as $video_id) {
        $effective_roles = array_merge($effective_roles, $this->roles_for_video((int)$video_id, $user_id));
      }
      $effective_roles = $this->normalize_roles($effective_roles);

      $current_group_ids = wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']);
      $current_group_ids = is_wp_error($current_group_ids) ? [] : array_values(array_unique(array_map('intval', (array)$current_group_ids)));
      $present_dtc_group_ids = array_values(array_intersect($expected_group_ids, $current_group_ids));

      $dtc_group_labels = [];
      foreach ($present_dtc_group_ids as $term_id) {
        $label = $this->term_label('eum_user_group', (int)$term_id);
        if ($label !== '') $dtc_group_labels[] = $label;
      }
      if (count($present_dtc_group_ids) < count($expected_group_ids)) {
        $missing = array_values(array_diff($expected_group_ids, $present_dtc_group_ids));
        foreach ($missing as $term_id) {
          $label = $this->term_label('eum_user_group', (int)$term_id);
          if ($label !== '') $dtc_group_labels[] = 'Pending sync: ' . $label;
        }
      }

      $video_specific = $this->channel_has_video_specific_access_for_user($channel_id, $user_id, $video_ids);
      $access_source = !empty($channel_roles)
        ? ($video_specific ? 'Channel + Project' : 'Channel')
        : ($video_specific ? 'Project' : 'Channel');

      $rows[] = [
        'user_id' => $user_id,
        'member_label' => $this->user_label($user_id),
        'email' => (string)$user->user_email,
        'dtc_role_label' => implode(', ', $this->dtc_role_labels_for_channel_member($channel_id, $user_id, $effective_roles, $channel_roles, $video_ids)),
        'roles_label' => !empty($effective_roles) ? implode(', ', array_map([$this, 'permission_label'], $effective_roles)) : 'Viewer',
        'access_source' => $access_source,
        'dtc_groups_label' => !empty($dtc_group_labels) ? implode(', ', $dtc_group_labels) : 'No DTC groups assigned',
      ];
    }

    usort($rows, static function(array $a, array $b): int {
      return strcasecmp((string)($a['member_label'] ?? ''), (string)($b['member_label'] ?? ''));
    });

    return $rows;
  }

  private function channel_has_video_specific_access_for_user(int $channel_id, int $user_id, array $video_ids = []): bool {
    $channel_id = (int)$channel_id;
    $user_id = (int)$user_id;
    if ($channel_id <= 0 || $user_id <= 0) return false;
    if (empty($video_ids)) $video_ids = $this->video_ids_for_channel($channel_id);

    foreach ($video_ids as $video_id) {
      $video_id = (int)$video_id;
      if ($video_id <= 0) continue;

      $video = get_post($video_id);
      if ($video && (int)$video->post_author === $user_id) return true;

      foreach ($this->video_permissions($video_id) as $row) {
        if ($this->permission_row_matches_user($row, $user_id)) return true;
      }

      foreach ($this->video_event_roles($video_id) as $role_row) {
        foreach ($this->event_role_signups($role_row) as $signup) {
          if ((int)($signup['user_id'] ?? 0) === $user_id) return true;
        }
      }
    }

    return false;
  }

  private function dtc_role_labels_for_channel_member(int $channel_id, int $user_id, array $effective_roles = [], array $channel_roles = [], array $video_ids = []): array {
    $labels = ['DTC User'];
    if ($this->channel_member_is_manager($channel_id, $user_id, $effective_roles, $channel_roles, $video_ids)) {
      array_unshift($labels, 'DTC Manager');
    }
    return array_values(array_unique(array_filter($labels)));
  }

  private function channel_member_is_manager(int $channel_id, int $user_id, array $effective_roles = [], array $channel_roles = [], array $video_ids = []): bool {
    $channel_id = (int)$channel_id;
    $user_id = (int)$user_id;
    if ($channel_id <= 0 || $user_id <= 0) return false;

    if (empty($channel_roles)) $channel_roles = $this->roles_for_channel($channel_id, $user_id);
    if (empty($effective_roles)) {
      $effective_roles = $channel_roles;
      if (empty($video_ids)) $video_ids = $this->video_ids_for_channel($channel_id);
      foreach ($video_ids as $video_id) {
        $effective_roles = array_merge($effective_roles, $this->roles_for_video((int)$video_id, $user_id));
      }
      $effective_roles = $this->normalize_roles($effective_roles);
    }

    if (in_array('manager', $channel_roles, true) || in_array('creator', $channel_roles, true)) return true;
    if (in_array('manager', $effective_roles, true) || in_array('creator', $effective_roles, true)) return true;

    $channel = get_post($channel_id);
    if ($channel && (int)$channel->post_author === $user_id) return true;

    if (empty($video_ids)) $video_ids = $this->video_ids_for_channel($channel_id);
    foreach ($video_ids as $video_id) {
      $video = get_post((int)$video_id);
      if ($video && (int)$video->post_author === $user_id) return true;
    }

    return false;
  }

  private function render_asset_tree(int $video_id, array $assets, int $user_id): string {
    if (empty($assets)) return '<div class="eum-dtc__empty" style="margin-top:14px;">No asset versions have been uploaded yet.</div>';
    ob_start();
    echo '<div style="display:grid;gap:14px;margin-top:16px;">';
    foreach ($assets as $asset) {
      $current_version_id = sanitize_text_field((string)($asset['current_version_id'] ?? ''));
      echo '<article class="eum-dtc__card">';
      echo '<div class="eum-dtc__card-head"><div><h4>' . esc_html((string)($asset['label'] ?? 'Asset')) . '</h4><div class="eum-dtc__muted">' . esc_html((string)($asset['asset_group'] ?? 'general')) . ' / ' . esc_html((string)($asset['asset_kind'] ?? 'File')) . '</div></div></div>';
      $versions = is_array($asset['versions'] ?? null) ? (array)$asset['versions'] : [];
      if (empty($versions)) {
        echo '<div class="eum-dtc__empty">No versions saved.</div>';
      } else {
        echo '<div class="eum-dtc__version-tree">';
        foreach ($versions as $version) {
          $attachment_id = (int)($version['attachment_id'] ?? 0);
          $is_current = $current_version_id !== '' && $current_version_id === (string)($version['id'] ?? '');
          $filename = $attachment_id > 0 ? basename((string)get_attached_file($attachment_id)) : (string)($version['filename'] ?? 'file');
          $download = $attachment_id > 0 ? wp_get_attachment_url($attachment_id) : '';
          echo '<div class="eum-dtc__version-node' . ($is_current ? ' is-live' : '') . '">';
          echo '<div><strong>' . esc_html($filename) . '</strong><div class="eum-dtc__muted">' . esc_html((string)($version['uploaded_at'] ?? '')) . ' / ' . esc_html($this->user_label((int)($version['uploaded_by'] ?? 0))) . '</div>';
          if (!empty($version['notes'])) echo '<div class="eum-dtc__muted">' . esc_html((string)$version['notes']) . '</div>';
          echo '</div><div class="eum-dtc__toolbar">';
          if ($download !== '') echo '<a class="button button-small" href="' . esc_url($download) . '" target="_blank" rel="noopener">Download</a>';
          if (!$is_current && $this->can_manage_video($video_id, $user_id)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="eum_dtc_restore_asset"><input type="hidden" name="video_id" value="' . (int)$video_id . '"><input type="hidden" name="asset_id" value="' . esc_attr((string)($asset['id'] ?? '')) . '"><input type="hidden" name="version_id" value="' . esc_attr((string)($version['id'] ?? '')) . '">';
            wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
            echo '<button class="button button-small">Make Current</button></form>';
          } elseif ($is_current) {
            echo '<span class="eum-dtc__pill">Current</span>';
          }
          echo '</div></div>';
        }
        echo '</div>';
      }
      echo '</article>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_snapshot_list(array $rows, int $video_id, int $user_id): string {
    if (empty($rows)) return '<div class="eum-dtc__empty">No metadata snapshots have been stored yet.</div>';
    ob_start();
    echo '<table class="widefat striped"><thead><tr><th>Field</th><th>Saved At</th><th>Saved By</th><th>Value</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $snapshot_id = (int)($row['id'] ?? 0);
      $key = sanitize_key((string)($row['snapshot_key'] ?? ''));
      $value = (string)($row['snapshot_value'] ?? '');
      echo '<tr>';
      echo '<td>' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</td>';
      echo '<td>' . esc_html((string)($row['created_at'] ?? '')) . '</td>';
      echo '<td>' . esc_html($this->user_label((int)($row['actor_user_id'] ?? 0))) . '</td>';
      echo '<td><div style="max-width:520px;white-space:pre-wrap;">' . esc_html($this->string_excerpt($value, 600)) . '</div></td>';
      echo '<td>';
      if ($this->can_manage_video($video_id, $user_id) || $this->can_edit_snapshot_key($video_id, $user_id, $key)) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Revert this field to the selected snapshot?\');">';
        echo '<input type="hidden" name="action" value="eum_dtc_revert_snapshot"><input type="hidden" name="snapshot_id" value="' . (int)$snapshot_id . '">';
        wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
        echo '<button class="button button-small">Revert</button></form>';
      }
      echo '</td></tr>';
    }
    echo '</tbody></table>';
    return (string)ob_get_clean();
  }

  private function render_event_role_cards(int $video_id, array $roles, int $user_id, int $linked_event): string {
    if (empty($roles)) return '<div class="eum-dtc__empty" style="margin-top:16px;">No project roles are configured yet.</div>';
    ob_start();
    echo '<div class="eum-dtc__grid" style="margin-top:16px;">';
    foreach ($roles as $role) {
      $signups = $this->event_role_signups($role);
      $signed_up = in_array($user_id, array_map('intval', array_column($signups, 'user_id')), true);
      $eligible = $this->event_role_user_is_eligible($role, $video_id, $user_id);
      $role_id = sanitize_text_field((string)($role['id'] ?? ''));
      echo '<article class="eum-dtc__card">';
      echo '<div class="eum-dtc__card-head"><div><h4>' . esc_html((string)($role['label'] ?? 'Role')) . '</h4><div class="eum-dtc__muted">Seats ' . count($signups) . ' / ' . max(1, (int)($role['seat_count'] ?? 1)) . '</div></div>';
      if ($this->can_manage_video($video_id, $user_id)) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Remove this project role?\');">';
        echo '<input type="hidden" name="action" value="eum_dtc_remove_event_role"><input type="hidden" name="video_id" value="' . (int)$video_id . '"><input type="hidden" name="role_id" value="' . esc_attr($role_id) . '">';
        wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
        echo '<button class="button button-small">Remove</button></form>';
      }
      echo '</div>';
      echo '<div class="eum-dtc__muted">Invites: ' . esc_html($this->role_invite_summary($role)) . '</div>';
      if (empty($signups)) {
        echo '<div class="eum-dtc__empty" style="margin-top:10px;">No one has signed up yet.</div>';
      } else {
        echo '<ul class="eum-dtc__list">';
        foreach ($signups as $signup) {
          echo '<li>' . esc_html($this->user_label((int)($signup['user_id'] ?? 0))) . ' / ' . esc_html((string)($signup['signed_up_at'] ?? '')) . '</li>';
        }
        echo '</ul>';
      }
      echo '<div class="eum-dtc__toolbar" style="margin-top:12px;">';
      if ($eligible || $this->can_manage_video($video_id, $user_id)) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="eum_dtc_signup_event_role"><input type="hidden" name="video_id" value="' . (int)$video_id . '"><input type="hidden" name="role_id" value="' . esc_attr($role_id) . '">';
        wp_nonce_field(self::NONCE, 'eum_dtc_nonce');
        echo '<button class="button' . ($signed_up ? '' : ' button-primary') . '">' . esc_html($signed_up ? 'Withdraw' : 'Sign Up') . '</button></form>';
      }
      if ($linked_event > 0) {
        echo '<a class="button button-small" href="' . esc_url(home_url('/?eum_event_edit=' . $linked_event)) . '">Open Event</a>';
      }
      echo '</div></article>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function role_invite_summary(array $role): string {
    $bits = [];
    if (!empty($role['invited_job_path_ids'])) $bits[] = count((array)$role['invited_job_path_ids']) . ' job path(s)';
    if (!empty($role['invited_group_ids'])) $bits[] = count((array)$role['invited_group_ids']) . ' group(s)';
    if (!empty($role['invited_user_ids'])) $bits[] = count((array)$role['invited_user_ids']) . ' direct user(s)';
    return !empty($bits) ? implode(' / ', $bits) : 'Open to anyone with project visibility';
  }

  private function event_role_signups(array $role): array {
    $rows = is_array($role['signups'] ?? null) ? (array)$role['signups'] : [];
    return array_values(array_filter($rows, fn($row) => (int)($row['user_id'] ?? 0) > 0));
  }

  private function event_role_user_is_eligible(array $role, int $video_id, int $user_id): bool {
    if ($this->can_manage_video($video_id, $user_id)) return true;
    $invited_users = $this->sanitize_id_array((array)($role['invited_user_ids'] ?? []));
    $invited_groups = $this->sanitize_id_array((array)($role['invited_group_ids'] ?? []));
    $invited_job_paths = $this->sanitize_id_array((array)($role['invited_job_path_ids'] ?? []));
    if (empty($invited_users) && empty($invited_groups) && empty($invited_job_paths)) return $this->can_view_video($video_id, $user_id);
    if (in_array($user_id, $invited_users, true)) return true;
    $context = $this->user_access_context($user_id);
    if (!empty(array_intersect($invited_groups, (array)$context['group_ids']))) return true;
    if (!empty(array_intersect($invited_job_paths, (array)$context['job_path_ids']))) return true;
    return false;
  }

  private function linked_event_defaults(int $video_id, int $event_id): array {
    $defaults = [
      'title' => 'DTC Event - ' . get_the_title($video_id),
      'date' => current_time('Y-m-d'),
      'start_time' => '09:00',
      'end_time' => '10:00',
      'location' => '',
      'meeting_link' => '',
      'is_remote' => false,
    ];
    if ($event_id <= 0 || !class_exists('EUM_Events') || get_post_type($event_id) !== EUM_Events::CPT) return $defaults;
    $defaults['title'] = get_the_title($event_id);
    $start_utc = (int)get_post_meta($event_id, EUM_Events::META_START_UTC, true);
    $end_utc = (int)get_post_meta($event_id, EUM_Events::META_END_UTC, true);
    if ($start_utc > 0) {
      $start_local = $this->utc_to_local_datetime($start_utc);
      $defaults['date'] = $start_local->format('Y-m-d');
      $defaults['start_time'] = $start_local->format('H:i');
    }
    if ($end_utc > 0) {
      $end_local = $this->utc_to_local_datetime($end_utc);
      $defaults['end_time'] = $end_local->format('H:i');
    }
    $defaults['location'] = (string)get_post_meta($event_id, EUM_Events::META_LOCATION, true);
    $defaults['meeting_link'] = (string)get_post_meta($event_id, EUM_Events::META_MEETING_LINK, true);
    $defaults['is_remote'] = ((string)get_post_meta($event_id, EUM_Events::META_IS_REMOTE, true) === '1');
    return $defaults;
  }

  private function event_invite_targets_for_video(int $video_id): array {
    $users = [];
    $groups = [];
    $job_paths = [];
    foreach ($this->video_event_roles($video_id) as $row) {
      foreach ($this->sanitize_id_array((array)($row['invited_user_ids'] ?? [])) as $user_id) $users[$user_id] = $user_id;
      foreach ($this->sanitize_id_array((array)($row['invited_group_ids'] ?? [])) as $term_id) $groups[$term_id] = $term_id;
      foreach ($this->sanitize_id_array((array)($row['invited_job_path_ids'] ?? [])) as $term_id) $job_paths[$term_id] = $term_id;
    }
    return [
      'users' => array_values($users),
      'groups' => array_values($groups),
      'job_paths' => array_values($job_paths),
    ];
  }

  private function sync_event_rsvp_from_project_roles(int $event_id, int $video_id, int $user_id): void {
    if ($event_id <= 0 || !class_exists('EUM_Events') || get_post_type($event_id) !== EUM_Events::CPT) return;
    $should_accept = false;
    foreach ($this->video_event_roles($video_id) as $role) {
      foreach ($this->event_role_signups($role) as $signup) {
        if ((int)($signup['user_id'] ?? 0) === $user_id) $should_accept = true;
      }
    }
    $rsvps = get_post_meta($event_id, EUM_Events::META_RSVPS, true);
    $rsvps = is_array($rsvps) ? $rsvps : [];
    if ($should_accept) {
      $rsvps[$user_id] = ['status' => 'accept', 'count' => 1, 'updated' => time()];
    } else {
      unset($rsvps[$user_id]);
    }
    update_post_meta($event_id, EUM_Events::META_RSVPS, $rsvps);
  }

  private function snapshot_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . self::SNAPSHOT_TABLE;
  }

  private function record_initial_snapshots(int $video_id, int $user_id): void {
    foreach ($this->snapshot_source_values($video_id) as $key => $value) {
      $this->insert_snapshot($video_id, $key, $value, $user_id, 'Initial value');
    }
  }

  private function snapshot_source_values(int $video_id): array {
    $post = get_post($video_id);
    return [
      'title' => $post ? (string)$post->post_title : '',
      'concept' => (string)get_post_meta($video_id, self::META_VIDEO_CONCEPT, true),
      'research' => (string)get_post_meta($video_id, self::META_VIDEO_RESEARCH, true),
      'script' => (string)get_post_meta($video_id, self::META_VIDEO_SCRIPT, true),
      'description' => (string)get_post_meta($video_id, self::META_VIDEO_DESCRIPTION, true),
      'tags' => (string)get_post_meta($video_id, self::META_VIDEO_TAGS, true),
    ];
  }

  private function insert_snapshot(int $video_id, string $key, string $value, int $actor_user_id, string $note = ''): void {
    global $wpdb;
    if ($video_id <= 0 || $key === '') return;
    $latest = $wpdb->get_var($wpdb->prepare(
      "SELECT snapshot_value FROM {$this->snapshot_table_name()} WHERE video_id = %d AND snapshot_key = %s ORDER BY id DESC LIMIT 1",
      $video_id,
      $key
    ));
    if ((string)$latest === (string)$value) return;
    $wpdb->insert($this->snapshot_table_name(), [
      'video_id' => $video_id,
      'snapshot_key' => sanitize_key($key),
      'snapshot_value' => (string)$value,
      'actor_user_id' => max(0, (int)$actor_user_id),
      'note' => sanitize_text_field($note),
      'created_at' => current_time('mysql'),
    ]);
  }

  private function snapshot_rows(int $video_id, int $limit = 100): array {
    global $wpdb;
    return (array)$wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$this->snapshot_table_name()} WHERE video_id = %d ORDER BY id DESC LIMIT %d",
      $video_id,
      $limit
    ), ARRAY_A);
  }

  private function snapshot_by_id(int $snapshot_id): array {
    global $wpdb;
    if ($snapshot_id <= 0) return [];
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$this->snapshot_table_name()} WHERE id = %d LIMIT 1",
      $snapshot_id
    ), ARRAY_A);
    return is_array($row) ? $row : [];
  }

  private function snapshot_value_for_key(int $video_id, string $key): string {
    $values = $this->snapshot_source_values($video_id);
    return (string)($values[$key] ?? '');
  }

  private function apply_snapshot_value(int $video_id, string $key, string $value): void {
    switch ($key) {
      case 'title':
        wp_update_post(['ID' => $video_id, 'post_title' => sanitize_text_field($value)]);
        return;
      case 'concept':
        update_post_meta($video_id, self::META_VIDEO_CONCEPT, sanitize_textarea_field($value));
        return;
      case 'research':
        update_post_meta($video_id, self::META_VIDEO_RESEARCH, sanitize_textarea_field($value));
        return;
      case 'script':
        update_post_meta($video_id, self::META_VIDEO_SCRIPT, sanitize_textarea_field($value));
        return;
      case 'description':
        update_post_meta($video_id, self::META_VIDEO_DESCRIPTION, sanitize_textarea_field($value));
        return;
      case 'tags':
        update_post_meta($video_id, self::META_VIDEO_TAGS, sanitize_text_field($value));
        return;
    }
  }

  private function append_asset_version(int $video_id, string $label, string $group, string $kind, int $attachment_id, int $user_id, string $notes = ''): void {
    $assets = $this->video_assets($video_id);
    $group = sanitize_key($group);
    $kind = sanitize_text_field($kind);
    $label = sanitize_text_field($label);
    $version = [
      'id' => wp_generate_uuid4(),
      'attachment_id' => $attachment_id,
      'uploaded_by' => $user_id,
      'uploaded_at' => current_time('mysql'),
      'notes' => sanitize_text_field($notes),
      'filename' => basename((string)get_attached_file($attachment_id)),
      'mime_type' => (string)get_post_mime_type($attachment_id),
    ];

    $matched = false;
    foreach ($assets as &$asset) {
      if (sanitize_text_field((string)($asset['label'] ?? '')) !== $label) continue;
      if (sanitize_key((string)($asset['asset_group'] ?? 'general')) !== $group) continue;
      $versions = is_array($asset['versions'] ?? null) ? (array)$asset['versions'] : [];
      $versions[] = $version;
      $asset['versions'] = $versions;
      $asset['asset_kind'] = $kind !== '' ? $kind : (string)($asset['asset_kind'] ?? 'File');
      $asset['current_version_id'] = (string)$version['id'];
      $matched = true;
      break;
    }
    unset($asset);

    if (!$matched) {
      $assets[] = [
        'id' => wp_generate_uuid4(),
        'label' => $label !== '' ? $label : 'Asset',
        'asset_group' => $group !== '' ? $group : 'general',
        'asset_kind' => $kind !== '' ? $kind : 'File',
        'current_version_id' => (string)$version['id'],
        'versions' => [$version],
      ];
    }

    update_post_meta($video_id, self::META_VIDEO_ASSETS, array_values($assets));
  }

  private function current_primary_video_attachment_id(int $video_id): int {
    foreach ($this->video_assets($video_id) as $asset) {
      $group = sanitize_key((string)($asset['asset_group'] ?? 'general'));
      $versions = is_array($asset['versions'] ?? null) ? (array)$asset['versions'] : [];
      $current_id = sanitize_text_field((string)($asset['current_version_id'] ?? ''));
      foreach ($versions as $version) {
        if ($current_id !== '' && $current_id !== (string)($version['id'] ?? '')) continue;
        $attachment_id = (int)($version['attachment_id'] ?? 0);
        $mime = (string)($version['mime_type'] ?? '');
        if ($group === 'final_video' || str_starts_with($mime, 'video/')) return $attachment_id;
      }
    }
    return 0;
  }

  private function permission_options(): array {
    return [
      'viewer' => 'Viewer',
      'researcher' => 'Researcher',
      'scriptwriter' => 'Scriptwriter',
      'asset_manager' => 'Asset Manager',
      'editor' => 'Editor',
      'creator' => 'Creator',
      'manager' => 'Manager',
    ];
  }

  private function status_options(): array {
    return [
      'idea' => 'Idea',
      'research' => 'Research',
      'scripting' => 'Scripting',
      'in_production' => 'In-Production',
      'post_production' => 'Post-Production',
      'uploaded_needs_metadata' => 'Uploaded (Needs Metadata)',
      'scheduled' => 'Scheduled',
      'published' => 'Published',
      'canceled' => 'Canceled',
    ];
  }

  private function asset_group_options(): array {
    return [
      'general' => 'General',
      'research' => 'Research Materials',
      'script' => 'Script Files',
      'project_file' => 'Project Files',
      'graphics' => 'Graphics / Thumbnail',
      'audio' => 'Audio',
      'final_video' => 'Final Video',
      'deliverable' => 'Deliverable / Upload Package',
    ];
  }

  private function normalize_status(string $status): string {
    $status = sanitize_key($status);
    return isset($this->status_options()[$status]) ? $status : 'idea';
  }

  private function status_label(string $status): string {
    return (string)($this->status_options()[$this->normalize_status($status)] ?? 'Idea');
  }

  private function permission_label(string $permission): string {
    return (string)($this->permission_options()[sanitize_key($permission)] ?? ucwords(str_replace('_', ' ', sanitize_key($permission))));
  }

  private function permission_subject_label(string $subject_type, int $subject_id): string {
    if ($subject_type === 'user') return $this->user_label($subject_id);
    if ($subject_type === 'group') return 'Group: ' . $this->term_label('eum_user_group', $subject_id);
    if ($subject_type === 'job_path') return 'Job Path: ' . $this->term_label('eum_job_path', $subject_id);
    return 'Unknown';
  }

  private function user_label(int $user_id): string {
    if ($user_id <= 0) return 'User #' . $user_id;
    if (class_exists('EUM_Workforce_Lifecycle') && method_exists('EUM_Workforce_Lifecycle', 'employee_list_label')) {
      return (string)EUM_Workforce_Lifecycle::employee_list_label($user_id);
    }
    $user = get_userdata($user_id);
    return $user ? (string)($user->display_name ?: $user->user_login) : ('User #' . $user_id);
  }

  private function term_label(string $taxonomy, int $term_id): string {
    if ($term_id <= 0 || !taxonomy_exists($taxonomy)) return '';
    if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'term_full_path')) {
      return (string)EUM_Profiles::term_full_path($taxonomy, $term_id, ' / ');
    }
    $term = get_term($term_id, $taxonomy);
    return ($term && !is_wp_error($term)) ? (string)$term->name : '';
  }

  private function job_path_label(int $term_id): string {
    return $term_id > 0 ? $this->term_label('eum_job_path', $term_id) : 'Not set';
  }

  private function finance_path_label(int $term_id): string {
    if ($term_id <= 0) return 'Not set';
    if (class_exists('EUM_Finance') && method_exists('EUM_Finance', 'finance_path_option_label')) {
      return (string)EUM_Finance::finance_path_option_label($term_id);
    }
    return $this->term_label('eum_finance_path', $term_id);
  }

  private function division_label(string $division_key): string {
    if (class_exists('EUM_Company_Divisions') && method_exists('EUM_Company_Divisions', 'division_label')) {
      return (string)EUM_Company_Divisions::division_label($division_key);
    }
    return ucwords(str_replace(['-', '_'], ' ', sanitize_key($division_key)));
  }

  private function sanitize_division(string $division_key): string {
    if (class_exists('EUM_Company_Divisions') && method_exists('EUM_Company_Divisions', 'normalize_division_key')) {
      return (string)EUM_Company_Divisions::normalize_division_key($division_key);
    }
    return sanitize_key($division_key);
  }

  private function format_money(float $amount): string {
    return '$' . number_format($amount, 2);
  }

  private function workspace_url(int $channel_id, int $video_id = 0): string {
    $args = [];
    if (is_admin()) $args['page'] = self::PAGE_SLUG;
    if ($channel_id > 0) $args['dtc_channel_id'] = $channel_id;
    if ($video_id > 0) $args['dtc_video_id'] = $video_id;
    return add_query_arg($args, $this->portal_base_url());
  }

  private function redirect_notice(array $args, string $message): void {
    $args['eum_dtc_notice'] = rawurlencode($message);
    $base = wp_get_referer() ?: $this->portal_base_url();
    wp_safe_redirect(add_query_arg($args, $base));
    exit;
  }

  private function portal_base_url(): string {
    if (is_admin()) {
      return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }

    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string)wp_unslash($_SERVER['HTTP_HOST'])) : (string)parse_url(home_url('/'), PHP_URL_HOST);
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '/';
    $base = (is_ssl() ? 'https://' : 'http://') . $host . $request_uri;
    return remove_query_arg(['dtc_channel_id', 'dtc_video_id', 'dtc_search', 'dtc_status', 'eum_dtc_notice'], $base);
  }

  private function render_notice_banner(): string {
    $raw = isset($_GET['eum_dtc_notice']) ? (string)wp_unslash($_GET['eum_dtc_notice']) : '';
    $message = sanitize_text_field(rawurldecode($raw));
    if ($message === '') return '';
    return '<div class="notice notice-success" style="margin:0 0 16px;padding:10px 14px;border-radius:12px;"><p style="margin:0;">' . esc_html($message) . '</p></div>';
  }

  private function verify_post(): int {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    if (!isset($_POST['eum_dtc_nonce']) || !wp_verify_nonce((string)wp_unslash($_POST['eum_dtc_nonce']), self::NONCE)) {
      wp_die('Security check failed.');
    }
    return (int)get_current_user_id();
  }

  private function sanitize_id_array(array $values): array {
    return array_values(array_unique(array_filter(array_map('intval', $values), fn($id) => $id > 0)));
  }

  private function print_styles_once(): void {
    if (self::$styles_printed) return;
    self::$styles_printed = true;
    echo '<style>
      .eum-dtc{display:grid;gap:18px;max-width:1320px}
      .eum-dtc__hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap;background:linear-gradient(135deg,#0b2545 0%,#13315c 55%,#3e5c76 100%);color:#fff;border-radius:28px;padding:26px 28px;box-shadow:0 18px 36px rgba(15,23,42,.14)}
      .eum-dtc__hero h2{margin:0 0 6px;font-size:38px;line-height:1.05}
      .eum-dtc__hero p{margin:0;max-width:760px;color:rgba(255,255,255,.85)}
      .eum-dtc__eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:12px;font-weight:700;color:#dbeafe;margin-bottom:10px}
      .eum-dtc__hero-badges{display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end}
      .eum-dtc__badge,.eum-dtc__pill{display:inline-flex;align-items:center;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.14);font-size:12px;font-weight:700}
      .eum-dtc__panel,.eum-dtc__card{background:#fff;border:1px solid #d9e2ec;border-radius:20px;padding:18px;box-shadow:0 10px 30px rgba(15,23,42,.06)}
      .eum-dtc__panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px}
      .eum-dtc__panel-head h3,.eum-dtc__card h4{margin:0 0 4px}
      .eum-dtc__panel-head span,.eum-dtc__muted{color:#526174;font-size:13px}
      .eum-dtc__filters,.eum-dtc__form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
      .eum-dtc__form-grid label,.eum-dtc__filters label{display:grid;gap:6px;font-weight:600;color:#132030}
      .eum-dtc__form-grid input:not([type=checkbox]),.eum-dtc__form-grid select,.eum-dtc__form-grid textarea,.eum-dtc__filters input,.eum-dtc__filters select{width:100%;border:1px solid #c9d4df;border-radius:12px;padding:10px 12px;background:#f8fbff}
      .eum-dtc__form-grid textarea{min-height:96px}
      .eum-dtc__full{grid-column:1/-1}
      .eum-dtc__check{display:flex !important;gap:8px;align-items:center;font-weight:600}
      .eum-dtc__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}
      .eum-dtc__card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px}
      .eum-dtc__card-meta{display:flex;flex-wrap:wrap;gap:8px 14px;font-size:13px;color:#334155;margin-bottom:10px}
      .eum-dtc__empty{padding:16px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#475569}
      .eum-dtc__tabs{display:flex;flex-wrap:wrap;gap:8px}
      .eum-dtc__tabs a{text-decoration:none;padding:10px 14px;border-radius:999px;background:#e5edf7;color:#14304d;font-weight:700}
      .eum-dtc__toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
      .eum-dtc__list{margin:10px 0 0;padding-left:18px}
      .eum-dtc__subpanel{border:1px solid #d7e0ea;border-radius:16px;padding:14px;background:#fbfdff}
      .eum-dtc__subpanel h4{margin:0 0 10px}
      .eum-dtc__text-block{border:1px solid #d7e0ea;border-radius:14px;padding:14px;background:#fbfdff}
      .eum-dtc__version-tree{display:grid;gap:10px;margin-top:12px}
      .eum-dtc__version-node{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:12px;border:1px solid #dbe5ef;border-radius:14px;background:#f8fbff}
      .eum-dtc__version-node.is-live{border-color:#0f766e;background:#ecfdf5}
      @media (max-width:782px){.eum-dtc__hero h2{font-size:30px}.eum-dtc__hero{padding:22px}.eum-dtc__version-node{flex-direction:column}}
    </style>';
  }

  private function user_options_html($selected = []): string {
    $selected_ids = is_array($selected) ? $this->sanitize_id_array($selected) : ($selected > 0 ? [(int)$selected] : []);
    $html = '';
    foreach (get_users(['orderby' => 'display_name', 'order' => 'ASC', 'number' => 0]) as $user) {
      $uid = (int)$user->ID;
      $html .= '<option value="' . $uid . '"' . selected(in_array($uid, $selected_ids, true), true, false) . '>' . esc_html($this->user_label($uid)) . '</option>';
    }
    return $html;
  }

  private function all_user_ids(): array {
    return array_values(array_filter(array_map('intval', (array)get_users([
      'fields' => 'ID',
      'number' => 0,
      'orderby' => 'ID',
      'order' => 'ASC',
    ])), fn($user_id) => $user_id > 0));
  }

  private function group_options_html($selected = []): string {
    $selected_ids = is_array($selected) ? $this->sanitize_id_array($selected) : ($selected > 0 ? [(int)$selected] : []);
    $html = '';
    $terms = get_terms(['taxonomy' => 'eum_user_group', 'hide_empty' => false, 'orderby' => 'name', 'number' => 0]);
    if (is_wp_error($terms)) return '';
    foreach ($terms as $term) {
      $html .= '<option value="' . (int)$term->term_id . '"' . selected(in_array((int)$term->term_id, $selected_ids, true), true, false) . '>' . esc_html((string)$term->name) . '</option>';
    }
    return $html;
  }

  private function job_path_options_html($selected = 0, bool $include_none = false, bool $multiple = false): string {
    $selected_ids = is_array($selected) ? $this->sanitize_id_array($selected) : ($selected > 0 ? [(int)$selected] : []);
    $html = $include_none && !$multiple ? '<option value="0">-- None --</option>' : '';
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name', 'number' => 0]);
    if (is_wp_error($terms)) return $html;
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      $html .= '<option value="' . $term_id . '"' . selected(in_array($term_id, $selected_ids, true), true, false) . '>' . esc_html($this->term_label('eum_job_path', $term_id)) . '</option>';
    }
    return $html;
  }

  private function finance_path_options_html(int $selected = 0): string {
    $html = '<option value="0">-- None --</option>';
    if (!taxonomy_exists('eum_finance_path')) return $html;
    $terms = get_terms(['taxonomy' => 'eum_finance_path', 'hide_empty' => false, 'orderby' => 'name', 'number' => 0]);
    if (is_wp_error($terms)) return $html;
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      $label = $this->finance_path_label($term_id);
      $html .= '<option value="' . $term_id . '"' . selected($selected === $term_id, true, false) . '>' . esc_html($label) . '</option>';
    }
    return $html;
  }

  private function division_options_html(string $selected = ''): string {
    $selected = $this->sanitize_division($selected);
    $html = '<option value="">-- None --</option>';
    if (class_exists('EUM_Company_Divisions') && method_exists('EUM_Company_Divisions', 'options_for_select')) {
      foreach ((array)EUM_Company_Divisions::options_for_select() as $key => $label) {
        $html .= '<option value="' . esc_attr((string)$key) . '"' . selected($selected === (string)$key, true, false) . '>' . esc_html((string)$label) . '</option>';
      }
    }
    return $html;
  }

  private function connector_options_html(string $selected = 'youtube'): string {
    $options = [];
    foreach ($this->connectors() as $key => $connector) {
      $options[$key] = $connector->label();
    }
    return $this->select_options($options, $selected);
  }

  private function connector_readiness_label(EUM_DTC_Connector_Interface $connector, array $config): string {
    $required = array_filter(array_map('trim', array_values($config)));
    return empty($required) ? 'No connector credentials saved yet.' : 'Connector settings are stored for this channel.';
  }

  private function connectors(): array {
    $connectors = [
      'youtube' => new EUM_DTC_YouTube_Connector(),
    ];
    return (array)apply_filters('eum_dtc_connectors', $connectors);
  }

  private function connector_for_key(string $key): EUM_DTC_Connector_Interface {
    $connectors = $this->connectors();
    if (isset($connectors[$key]) && $connectors[$key] instanceof EUM_DTC_Connector_Interface) return $connectors[$key];
    return $connectors['youtube'];
  }

  private function normalize_connector_config_from_request(string $connector_key): array {
    $connector = $this->connector_for_key($connector_key);
    $posted = isset($_POST['connector']) && is_array($_POST['connector']) ? (array)$_POST['connector'] : [];
    return $connector->normalize_config($posted);
  }

  private function generate_placeholder_id_for_video(int $channel_id, int $video_id): string {
    $channel = $this->channel_row($channel_id);
    $video = $this->video_row($video_id);
    return $this->connector_for_key((string)$channel['connector_key'])->generate_placeholder_id($channel, $video);
  }

  private function select_options(array $options, string $selected = ''): string {
    $html = '';
    foreach ($options as $value => $label) {
      $html .= '<option value="' . esc_attr((string)$value) . '"' . selected((string)$selected === (string)$value, true, false) . '>' . esc_html((string)$label) . '</option>';
    }
    return $html;
  }

  private function string_excerpt(string $value, int $limit): string {
    if ($limit <= 0) return '';
    if (function_exists('mb_substr') && function_exists('mb_strlen')) {
      $excerpt = mb_substr($value, 0, $limit);
      return mb_strlen($value) > $limit ? $excerpt . '...' : $excerpt;
    }
    $excerpt = substr($value, 0, $limit);
    return strlen($value) > $limit ? $excerpt . '...' : $excerpt;
  }

  private function local_datetime_to_utc(string $value, int $user_id): int {
    $value = trim(str_replace('T', ' ', $value));
    if ($value === '') return 0;
    try {
      $dt = new DateTimeImmutable($value, $this->user_timezone($user_id));
      return $dt->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    } catch (\Throwable $e) {
      return 0;
    }
  }

  private function utc_to_local_datetime(int $utc_ts): DateTimeImmutable {
    return (new DateTimeImmutable('@' . $utc_ts))->setTimezone(wp_timezone());
  }

  private function user_timezone(int $user_id): DateTimeZone {
    $tz_string = $user_id > 0 ? (string)get_user_meta($user_id, 'timezone', true) : '';
    if ($tz_string === '') $tz_string = (string)get_option('timezone_string', '');
    if ($tz_string !== '') {
      try {
        return new DateTimeZone($tz_string);
      } catch (\Throwable $e) {
      }
    }
    return wp_timezone();
  }

  public function extend_upload_mimes(array $mimes): array {
    $mimes['prproj'] = 'application/octet-stream';
    $mimes['psd'] = 'image/vnd.adobe.photoshop';
    $mimes['psb'] = 'application/octet-stream';
    $mimes['aep'] = 'application/octet-stream';
    $mimes['ai'] = 'application/postscript';
    $mimes['eps'] = 'application/postscript';
    $mimes['wav'] = 'audio/wav';
    $mimes['flac'] = 'audio/flac';
    $mimes['m4a'] = 'audio/mp4';
    $mimes['mov'] = 'video/quicktime';
    $mimes['mxf'] = 'application/octet-stream';
    $mimes['blend'] = 'application/octet-stream';
    $mimes['project'] = 'application/octet-stream';
    $mimes['session'] = 'application/octet-stream';
    $mimes['srt'] = 'text/plain';
    $mimes['vtt'] = 'text/plain';
    return $mimes;
  }

  public function relax_filetype_check(array $data, string $file, string $filename, $mimes = null, $real_mime = ''): array {
    if (!self::$allow_any_file_upload) return $data;
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if ($ext === '') return $data;
    $ext = strtolower((string)$ext);
    if (!empty($data['ext']) && !empty($data['type'])) return $data;
    $data['ext'] = $ext;
    $data['type'] = $data['type'] ?: 'application/octet-stream';
    return $data;
  }

  private function handle_media_upload(string $field, int $parent_id = 0): int {
    if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) return 0;
    if (!function_exists('media_handle_upload')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    self::$allow_any_file_upload = true;
    $attachment_id = media_handle_upload($field, $parent_id);
    self::$allow_any_file_upload = false;
    return is_wp_error($attachment_id) ? 0 : (int)$attachment_id;
  }

  private function push_activity(int $object_id, string $meta_key, int $user_id, string $message): void {
    $rows = get_post_meta($object_id, $meta_key, true);
    $rows = is_array($rows) ? $rows : [];
    $rows[] = [
      'time' => current_time('mysql'),
      'user_id' => $user_id,
      'message' => sanitize_text_field($message),
    ];
    update_post_meta($object_id, $meta_key, array_slice($rows, -150));
  }

  private function render_activity_list(array $rows): string {
    if (empty($rows)) return '<div class="eum-dtc__empty">No activity has been logged yet.</div>';
    ob_start();
    echo '<ul class="eum-dtc__list">';
    foreach (array_reverse(array_slice($rows, -40)) as $row) {
      if (!is_array($row)) continue;
      echo '<li><strong>' . esc_html((string)($row['time'] ?? '')) . '</strong> / ' . esc_html($this->user_label((int)($row['user_id'] ?? 0))) . ' / ' . esc_html((string)($row['message'] ?? '')) . '</li>';
    }
    echo '</ul>';
    return (string)ob_get_clean();
  }

  private function sync_access_groups(): void {
    if (self::$syncing_access_groups) return;
    if (!taxonomy_exists('eum_user_group')) return;
    self::$syncing_access_groups = true;
    self::$sync_access_groups_needed = false;
    try {
      $this->ensure_default_groups();
      $global_term = get_term_by('slug', self::GROUP_GLOBAL_SLUG, 'eum_user_group');
      if (!$global_term || is_wp_error($global_term)) return;
      $global_term_id = (int)$global_term->term_id;

      $dtc_group_ids = [$global_term_id];
      $required_by_user = [];

      foreach ($this->all_channel_ids() as $channel_id) {
        $channel_group_term_id = $this->ensure_channel_group_term((int)$channel_id);
        if ($channel_group_term_id > 0) $dtc_group_ids[] = $channel_group_term_id;
        foreach ($this->resolved_access_user_ids_for_channel((int)$channel_id) as $user_id) {
          $required_by_user[$user_id][$global_term_id] = $global_term_id;
          if ($channel_group_term_id > 0) $required_by_user[$user_id][$channel_group_term_id] = $channel_group_term_id;
        }
      }

      $dtc_group_ids = array_values(array_unique(array_filter(array_map('intval', $dtc_group_ids), fn($id) => $id > 0)));
      foreach (get_users(['fields' => 'ID', 'number' => 0]) as $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) continue;
        $current = wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']);
        $current = is_wp_error($current) ? [] : array_values(array_unique(array_map('intval', (array)$current)));
        sort($current);
        $preserved = array_values(array_diff($current, $dtc_group_ids));
        $required = array_values(array_keys((array)($required_by_user[$user_id] ?? [])));
        $target = array_values(array_unique(array_merge($preserved, $required)));
        sort($target);
        if ($current === $target) continue;
        wp_set_object_terms($user_id, $target, 'eum_user_group', false);
      }
    } finally {
      self::$syncing_access_groups = false;
    }
  }

  private function schedule_access_group_sync(): void {
    if (self::$syncing_access_groups) return;
    self::$sync_access_groups_needed = true;
  }

  private function log_runtime_issue(string $context, \Throwable $e): void {
    eum_log_message('[EUM DTC] ' . $context . ': ' . $e->getMessage());
  }

  private function ensure_channel_group_term(int $channel_id): int {
    if ($channel_id <= 0 || !taxonomy_exists('eum_user_group')) return 0;
    $name = self::CHANNEL_GROUP_LABEL_PREFIX . get_the_title($channel_id);
    $slug = self::CHANNEL_GROUP_SLUG_PREFIX . sanitize_title((string)get_the_title($channel_id));
    $stored = (int)get_post_meta($channel_id, self::META_CHANNEL_GROUP_TERM_ID, true);
    if ($stored > 0) {
      $term = get_term($stored, 'eum_user_group');
      if ($term && !is_wp_error($term)) {
        if ((string)$term->name !== $name || (string)$term->slug !== $slug) {
          wp_update_term($stored, 'eum_user_group', ['name' => $name, 'slug' => $slug]);
        }
        return $stored;
      }
    }

    $term = get_term_by('slug', $slug, 'eum_user_group');
    if ($term && !is_wp_error($term)) {
      $term_id = (int)$term->term_id;
      wp_update_term($term_id, 'eum_user_group', ['name' => $name]);
      update_post_meta($channel_id, self::META_CHANNEL_GROUP_TERM_ID, $term_id);
      return $term_id;
    }

    $created = wp_insert_term($name, 'eum_user_group', ['slug' => $slug]);
    $term_id = (!is_wp_error($created) && !empty($created['term_id'])) ? (int)$created['term_id'] : 0;
    if ($term_id > 0) update_post_meta($channel_id, self::META_CHANNEL_GROUP_TERM_ID, $term_id);
    return $term_id;
  }

  private function resolved_access_user_ids_for_channel(int $channel_id): array {
    $out = [];
    $channel_id = (int)$channel_id;
    if ($channel_id <= 0) return [];

    $channel = get_post($channel_id);
    if ($channel && (int)$channel->post_author > 0) {
      $out[(int)$channel->post_author] = (int)$channel->post_author;
    }

    foreach ($this->channel_permissions($channel_id) as $row) {
      foreach ($this->resolved_user_ids_for_permission_subject(
        sanitize_key((string)($row['subject_type'] ?? 'user')),
        (int)($row['subject_id'] ?? 0)
      ) as $user_id) {
        $out[(int)$user_id] = (int)$user_id;
      }
    }

    foreach ($this->video_ids_for_channel($channel_id) as $video_id) {
      $video_id = (int)$video_id;
      if ($video_id <= 0) continue;

      $video = get_post($video_id);
      if ($video && (int)$video->post_author > 0) {
        $out[(int)$video->post_author] = (int)$video->post_author;
      }

      foreach ($this->video_permissions($video_id) as $row) {
        foreach ($this->resolved_user_ids_for_permission_subject(
          sanitize_key((string)($row['subject_type'] ?? 'user')),
          (int)($row['subject_id'] ?? 0)
        ) as $user_id) {
          $out[(int)$user_id] = (int)$user_id;
        }
      }

      foreach ($this->video_event_roles($video_id) as $role_row) {
        foreach ($this->event_role_signups($role_row) as $signup) {
          $user_id = (int)($signup['user_id'] ?? 0);
          if ($user_id > 0) $out[$user_id] = $user_id;
        }
      }
    }
    return array_values($out);
  }
}

class EUM_DTC_YouTube_Connector implements EUM_DTC_Connector_Interface {
  public function key(): string {
    return 'youtube';
  }

  public function label(): string {
    return 'YouTube';
  }

  public function default_config(): array {
    return [
      'api_key' => '',
      'client_id' => '',
      'client_secret' => '',
      'refresh_token' => '',
      'default_privacy' => 'private',
    ];
  }

  public function fields_html(array $config): string {
    $config = wp_parse_args($config, $this->default_config());
    $html = '<div class="eum-dtc__form-grid">';
    $html .= '<label><span>YouTube API Key</span><input type="text" name="connector[api_key]" value="' . esc_attr((string)$config['api_key']) . '"></label>';
    $html .= '<label><span>OAuth Client ID</span><input type="text" name="connector[client_id]" value="' . esc_attr((string)$config['client_id']) . '"></label>';
    $html .= '<label><span>OAuth Client Secret</span><input type="text" name="connector[client_secret]" value="' . esc_attr((string)$config['client_secret']) . '"></label>';
    $html .= '<label><span>Refresh Token</span><input type="text" name="connector[refresh_token]" value="' . esc_attr((string)$config['refresh_token']) . '"></label>';
    $html .= '<label><span>Default Privacy</span><select name="connector[default_privacy]">';
    foreach (['private' => 'Private', 'unlisted' => 'Unlisted', 'public' => 'Public'] as $value => $label) {
      $html .= '<option value="' . esc_attr($value) . '"' . selected((string)$config['default_privacy'] === $value, true, false) . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select></label>';
    $html .= '</div>';
    return $html;
  }

  public function normalize_config(array $posted): array {
    return [
      'api_key' => sanitize_text_field((string)($posted['api_key'] ?? '')),
      'client_id' => sanitize_text_field((string)($posted['client_id'] ?? '')),
      'client_secret' => sanitize_text_field((string)($posted['client_secret'] ?? '')),
      'refresh_token' => sanitize_text_field((string)($posted['refresh_token'] ?? '')),
      'default_privacy' => in_array((string)($posted['default_privacy'] ?? 'private'), ['private', 'unlisted', 'public'], true) ? (string)$posted['default_privacy'] : 'private',
    ];
  }

  public function generate_placeholder_id(array $channel_context = [], array $video_context = []): string {
    $seed = preg_replace('/[^A-Za-z0-9]/', '', wp_generate_password(16, false, false));
    return substr((string)$seed, 0, 11);
  }

  public function push_video(array $channel_context, array $video_context): array {
    $placeholder_id = sanitize_text_field((string)($video_context['placeholder_id'] ?? ''));
    if ($placeholder_id === '') $placeholder_id = $this->generate_placeholder_id($channel_context, $video_context);

    $title = sanitize_text_field((string)($video_context['title'] ?? 'Untitled Video'));
    $description = sanitize_textarea_field((string)($video_context['description'] ?? ''));
    $tags = array_values(array_filter(array_map('trim', explode(',', (string)($video_context['tags'] ?? '')))));
    $attachment_id = (int)($video_context['primary_video_attachment_id'] ?? 0);
    $privacy = sanitize_key((string)(($channel_context['connector_config']['default_privacy'] ?? 'private')));
    if (!in_array($privacy, ['private', 'unlisted', 'public'], true)) $privacy = 'private';

    $message = 'YouTube adapter prepared a publish payload for video ID ' . $placeholder_id . '. ';
    if ($attachment_id > 0) {
      $message .= 'The current live video file is attached in the workspace and ready for transport by this adapter. ';
    } else {
      $message .= 'No current live video file was found, so this push only carried metadata. ';
    }
    $message .= 'The adapter pattern is in place, but live remote upload still depends on server API credentials and outbound transport.';

    return [
      'ok' => true,
      'placeholder_id' => $placeholder_id,
      'message' => $message,
      'payload' => [
        'title' => $title,
        'description' => $description,
        'tags' => $tags,
        'privacyStatus' => $privacy,
        'attachment_id' => $attachment_id,
      ],
    ];
  }
}


