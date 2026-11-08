<?php
if (!defined('ABSPATH')) exit;

class EUM_Podcasts {
  const PAGE_SLUG = 'eum-podcasts';
  const CPT_PODCAST = 'eum_podcast';
  const CPT_EPISODE = 'eum_podcast_episode';

  const META_CREATED_BY = '_eum_podcast_created_by';
  const META_STATUS = '_eum_podcast_status';
  const META_SUBTITLE = '_eum_podcast_subtitle';
  const META_DESCRIPTION = '_eum_podcast_description';
  const META_AUTHOR = '_eum_podcast_author';
  const META_OWNER_EMAIL = '_eum_podcast_owner_email';
  const META_LANGUAGE = '_eum_podcast_language';
  const META_CATEGORY = '_eum_podcast_category';
  const META_WEBSITE_URL = '_eum_podcast_website_url';
  const META_COPYRIGHT = '_eum_podcast_copyright';
  const META_FINANCE_PATH_TERM_ID = '_eum_podcast_finance_path_term_id';
  const META_MANAGER_USER_IDS = '_eum_podcast_manager_user_ids';
  const META_MEMBER_USER_IDS = '_eum_podcast_member_user_ids';
  const META_COVER_ATTACHMENT_ID = '_eum_podcast_cover_attachment_id';
  const META_ACTIVITY = '_eum_podcast_activity';
  const META_EXPENSE_LINES = '_eum_podcast_expense_lines';
  const META_SOURCE_LINKS = '_eum_podcast_source_links';

  const META_EP_PODCAST_ID = '_eum_podcast_episode_podcast_id';
  const META_EP_SUMMARY = '_eum_podcast_episode_summary';
  const META_EP_NOTES = '_eum_podcast_episode_notes';
  const META_EP_AUDIO_ATTACHMENT_ID = '_eum_podcast_episode_audio_attachment_id';
  const META_EP_AUDIO_URL = '_eum_podcast_episode_audio_url';
  const META_EP_VIDEO_ATTACHMENT_ID = '_eum_podcast_episode_video_attachment_id';
  const META_EP_VIDEO_URL = '_eum_podcast_episode_video_url';
  const META_EP_STATUS = '_eum_podcast_episode_status';
  const META_EP_PUBLISH_AT = '_eum_podcast_episode_publish_at';
  const META_EP_DURATION = '_eum_podcast_episode_duration';
  const META_EP_EXPLICIT = '_eum_podcast_episode_explicit';
  const META_EP_SEASON = '_eum_podcast_episode_season';
  const META_EP_NUMBER = '_eum_podcast_episode_number';
  const META_EP_GUID = '_eum_podcast_episode_guid';
  const META_EP_PREPRO_RESOURCES = '_eum_podcast_episode_prepro_resources';
  const META_EP_SOURCE_LINKS = '_eum_podcast_episode_source_links';

  const NONCE = 'eum_podcast_action';

  private static $manager_anywhere_cache = [];
  private static $member_anywhere_cache = [];

  public function init(): void {
    add_action('init', [$this, 'register_post_types']);
    add_action('admin_menu', [$this, 'admin_menu'], 36);

    add_shortcode('eum_podcasts_portal', [$this, 'shortcode_portal']);
    add_shortcode('eum_podcast_workspace', [$this, 'shortcode_workspace']);

    add_action('admin_post_eum_podcast_create', [$this, 'handle_create_podcast']);
    add_action('admin_post_eum_podcast_save', [$this, 'handle_save_podcast']);
    add_action('admin_post_eum_podcast_add_member', [$this, 'handle_add_member']);
    add_action('admin_post_eum_podcast_remove_member', [$this, 'handle_remove_member']);
    add_action('admin_post_eum_podcast_add_episode', [$this, 'handle_add_episode']);
    add_action('admin_post_eum_podcast_save_episode', [$this, 'handle_save_episode']);
    add_action('admin_post_eum_podcast_add_expense', [$this, 'handle_add_expense']);
    add_action('admin_post_eum_podcast_export_episode_xml', [$this, 'handle_export_episode_xml']);
    add_action('admin_post_eum_podcast_export_episodes_csv', [$this, 'handle_export_episodes_csv']);
    add_action('admin_post_eum_podcast_export_podcast_xml', [$this, 'handle_export_podcast_xml']);
    add_action('admin_post_eum_podcast_import_episode_xml', [$this, 'handle_import_episode_xml']);
    add_action('admin_post_eum_podcast_import_episodes_csv', [$this, 'handle_import_episodes_csv']);
    add_action('admin_post_eum_podcast_import_podcast_xml', [$this, 'handle_import_podcast_xml']);
    add_action('admin_post_eum_podcast_import_rss', [$this, 'handle_import_rss']);
    add_action('admin_post_eum_podcast_import_zip', [$this, 'handle_import_podcast_zip']);
    add_action('wp_ajax_eum_podcast_folder_import_start', [$this, 'ajax_folder_import_start']);
    add_action('wp_ajax_eum_podcast_folder_import_next', [$this, 'ajax_folder_import_next']);

    add_filter('query_vars', [$this, 'register_query_vars']);
    add_filter('eum_allow_public_request', [$this, 'allow_public_feed_request']);
    add_action('template_redirect', [$this, 'maybe_render_public_feed']);
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

    register_post_type(self::CPT_PODCAST, array_merge($common, [
      'labels' => [
        'name' => 'Podcasts',
        'singular_name' => 'Podcast',
      ],
    ]));

    register_post_type(self::CPT_EPISODE, array_merge($common, [
      'labels' => [
        'name' => 'Podcast Episodes',
        'singular_name' => 'Podcast Episode',
      ],
    ]));
  }

  public function admin_menu(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) return;
    add_submenu_page(
      EUM_Admin::parent_slug('experience'),
      'Podcasts',
      'Podcasts',
      'read',
      self::PAGE_SLUG,
      [$this, 'render_admin_page']
    );
  }

  public function render_admin_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');

    echo '<div class="wrap">';
    echo '<h1>Podcast Manager</h1>';
    echo '<p><code>[eum_podcasts_portal]</code> renders the same workspace on the front end for podcast managers and members.</p>';
    echo $this->render_portal((int)get_current_user_id());
    echo '</div>';
  }

  public function register_query_vars(array $vars): array {
    $vars[] = 'eum_podcast_feed';
    return $vars;
  }

  public function allow_public_feed_request(bool $allowed): bool {
    $podcast_id = isset($_GET['eum_podcast_feed']) ? (int)$_GET['eum_podcast_feed'] : 0;
    return $allowed || $podcast_id > 0;
  }

  public function maybe_render_public_feed(): void {
    $podcast_id = (int)get_query_var('eum_podcast_feed');
    if ($podcast_id <= 0 && isset($_GET['eum_podcast_feed'])) {
      $podcast_id = (int)$_GET['eum_podcast_feed'];
    }
    if ($podcast_id <= 0) return;

    $podcast = get_post($podcast_id);
    if (!$podcast || $podcast->post_type !== self::CPT_PODCAST || $podcast->post_status !== 'publish') {
      status_header(404);
      exit;
    }

    nocache_headers();
    header('Content-Type: application/rss+xml; charset=' . get_option('blog_charset'));
    $variant = isset($_GET['variant']) ? sanitize_key((string)$_GET['variant']) : 'audio';
    if (!in_array($variant, ['audio', 'video'], true)) $variant = 'audio';
    echo $this->build_feed_xml($podcast_id, $variant);
    exit;
  }

  public function shortcode_portal($atts = []): string {
    try {
      $user_id = (int)get_current_user_id();
      if ($user_id <= 0) return '';
      return $this->render_portal($user_id, $atts);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Podcasts portal] ' . $e->getMessage());
      return $this->render_runtime_error('Podcasts', $e);
    }
  }

  public function shortcode_workspace($atts = []): string {
    try {
      $user_id = (int)get_current_user_id();
      if ($user_id <= 0) return '';
      $atts = shortcode_atts(['id' => 0], $atts, 'eum_podcast_workspace');
      $podcast_id = (int)$atts['id'];
      if ($podcast_id <= 0 && isset($_GET['podcast_id'])) $podcast_id = (int)$_GET['podcast_id'];
      return $podcast_id > 0 ? $this->render_workspace($podcast_id, $user_id) : $this->render_portal($user_id, $atts);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Podcast workspace] ' . $e->getMessage());
      return $this->render_runtime_error('Podcast Workspace', $e);
    }
  }

  public static function user_is_explicit_podcast_manager_anywhere(int $user_id): bool {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return false;
    if (isset(self::$manager_anywhere_cache[$user_id])) return self::$manager_anywhere_cache[$user_id];

    $podcast_ids = get_posts([
      'post_type' => self::CPT_PODCAST,
      'post_status' => 'publish',
      'numberposts' => 500,
      'fields' => 'ids',
      'orderby' => 'title',
      'order' => 'ASC',
    ]);

    foreach ((array)$podcast_ids as $podcast_id) {
      $manager_ids = array_values(array_filter(array_map('intval', (array)get_post_meta((int)$podcast_id, self::META_MANAGER_USER_IDS, true))));
      if (in_array($user_id, $manager_ids, true)) {
        return self::$manager_anywhere_cache[$user_id] = true;
      }
    }

    return self::$manager_anywhere_cache[$user_id] = false;
  }

  public static function user_is_podcast_member_anywhere(int $user_id): bool {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return false;
    if (isset(self::$member_anywhere_cache[$user_id])) return self::$member_anywhere_cache[$user_id];
    if (self::user_is_explicit_podcast_manager_anywhere($user_id)) {
      return self::$member_anywhere_cache[$user_id] = true;
    }

    $podcast_ids = get_posts([
      'post_type' => self::CPT_PODCAST,
      'post_status' => 'publish',
      'numberposts' => 500,
      'fields' => 'ids',
      'orderby' => 'title',
      'order' => 'ASC',
    ]);

    foreach ((array)$podcast_ids as $podcast_id) {
      $member_ids = array_values(array_filter(array_map('intval', (array)get_post_meta((int)$podcast_id, self::META_MEMBER_USER_IDS, true))));
      if (in_array($user_id, $member_ids, true)) {
        return self::$member_anywhere_cache[$user_id] = true;
      }
    }

    return self::$member_anywhere_cache[$user_id] = false;
  }

  private function render_portal(int $user_id, array $atts = []): string {
    $podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
    if (!empty($atts['id'])) $podcast_id = (int)$atts['id'];
    if ($podcast_id > 0) return $this->render_workspace($podcast_id, $user_id);
    return $this->render_directory($user_id);
  }

  private function render_runtime_error(string $area, \Throwable $e): string {
    $detail = (defined('WP_DEBUG') && WP_DEBUG) ? '<div class="description" style="margin-top:8px;">' . esc_html($e->getMessage()) . '</div>' : '';
    return '<div class="eum-pod"><div class="eum-pod__panel"><h3>' . esc_html($area) . ' temporarily unavailable.</h3><p>A runtime error was caught so the rest of the site can continue loading.</p>' . $detail . '</div></div>';
  }

  private function all_podcast_ids(): array {
    return array_values(array_map('intval', (array)get_posts([
      'post_type' => self::CPT_PODCAST,
      'post_status' => 'publish',
      'numberposts' => 500,
      'fields' => 'ids',
      'orderby' => 'title',
      'order' => 'ASC',
    ])));
  }

  private function explicit_manager_user_ids(int $podcast_id): array {
    return array_values(array_unique(array_filter(array_map('intval', (array)get_post_meta($podcast_id, self::META_MANAGER_USER_IDS, true)))));
  }

  private function explicit_member_user_ids(int $podcast_id): array {
    $members = array_values(array_unique(array_filter(array_map('intval', (array)get_post_meta($podcast_id, self::META_MEMBER_USER_IDS, true)))));
    return $members;
  }

  private function inherited_manager_user_ids(int $podcast_id): array {
    $out = [];
    foreach ($this->explicit_manager_user_ids($podcast_id) as $manager_user_id) {
      if (class_exists('EUM_Manager_IDs') && method_exists('EUM_Manager_IDs', 'manager_user_ids_for_subject_user')) {
        $out = array_merge($out, (array)EUM_Manager_IDs::manager_user_ids_for_subject_user((int)$manager_user_id));
      } elseif (class_exists('EUM_Personnel') && method_exists('EUM_Personnel', 'effective_manager_user_id')) {
        $fallback = (int)EUM_Personnel::effective_manager_user_id((int)$manager_user_id);
        if ($fallback > 0) $out[] = $fallback;
      }
    }
    $explicit = $this->explicit_manager_user_ids($podcast_id);
    return array_values(array_unique(array_filter(array_map('intval', $out), static function ($user_id) use ($explicit) {
      return $user_id > 0 && !in_array($user_id, $explicit, true);
    })));
  }

  private function user_roles_for_podcast(int $podcast_id, int $user_id): array {
    if ($podcast_id <= 0 || $user_id <= 0) return [];

    $roles = [];
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) $roles['manager'] = 'manager';
    if (in_array($user_id, $this->explicit_manager_user_ids($podcast_id), true)) $roles['manager'] = 'manager';
    if (in_array($user_id, $this->inherited_manager_user_ids($podcast_id), true)) $roles['manager'] = 'manager';
    if (in_array($user_id, $this->explicit_member_user_ids($podcast_id), true)) $roles['member'] = 'member';

    $created_by = (int)get_post_meta($podcast_id, self::META_CREATED_BY, true);
    if ($created_by > 0 && $created_by === $user_id) $roles['manager'] = 'manager';

    return array_values($roles);
  }

  private function user_role_for_podcast(int $podcast_id, int $user_id): string {
    $roles = $this->user_roles_for_podcast($podcast_id, $user_id);
    return in_array('manager', $roles, true) ? 'manager' : (in_array('member', $roles, true) ? 'member' : 'none');
  }

  private function can_view_podcast(int $podcast_id, int $user_id): bool {
    return $this->user_role_for_podcast($podcast_id, $user_id) !== 'none';
  }

  private function can_manage_podcast(int $podcast_id, int $user_id): bool {
    return $this->user_role_for_podcast($podcast_id, $user_id) === 'manager';
  }

  private function can_create_podcast(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) return true;
    return self::user_is_explicit_podcast_manager_anywhere($user_id);
  }

  private function visible_podcast_ids_for_user(int $user_id): array {
    if ($user_id <= 0) return [];
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id)) return $this->all_podcast_ids();

    $out = [];
    foreach ($this->all_podcast_ids() as $podcast_id) {
      if ($this->can_view_podcast((int)$podcast_id, $user_id)) $out[] = (int)$podcast_id;
    }
    return array_values(array_unique($out));
  }

  private function podcast_settings(int $podcast_id): array {
    $cover_attachment_id = (int)get_post_meta($podcast_id, self::META_COVER_ATTACHMENT_ID, true);
    return [
      'status' => $this->sanitize_podcast_status((string)get_post_meta($podcast_id, self::META_STATUS, true)),
      'subtitle' => sanitize_text_field((string)get_post_meta($podcast_id, self::META_SUBTITLE, true)),
      'description' => (string)get_post_meta($podcast_id, self::META_DESCRIPTION, true),
      'author' => sanitize_text_field((string)get_post_meta($podcast_id, self::META_AUTHOR, true)),
      'owner_email' => sanitize_email((string)get_post_meta($podcast_id, self::META_OWNER_EMAIL, true)),
      'language' => sanitize_text_field((string)get_post_meta($podcast_id, self::META_LANGUAGE, true)) ?: 'en-us',
      'category' => sanitize_text_field((string)get_post_meta($podcast_id, self::META_CATEGORY, true)),
      'website_url' => esc_url_raw((string)get_post_meta($podcast_id, self::META_WEBSITE_URL, true)),
      'copyright' => sanitize_text_field((string)get_post_meta($podcast_id, self::META_COPYRIGHT, true)),
      'finance_path_term_id' => (int)get_post_meta($podcast_id, self::META_FINANCE_PATH_TERM_ID, true),
      'cover_attachment_id' => $cover_attachment_id,
      'cover_url' => $cover_attachment_id > 0 ? (string)wp_get_attachment_url($cover_attachment_id) : '',
      'source_links' => $this->normalize_source_links((array)get_post_meta($podcast_id, self::META_SOURCE_LINKS, true)),
      'created_by' => (int)get_post_meta($podcast_id, self::META_CREATED_BY, true),
    ];
  }

  private function podcast_episode_rows(int $podcast_id, bool $include_non_public = true): array {
    $ids = get_posts([
      'post_type' => self::CPT_EPISODE,
      'post_status' => 'publish',
      'numberposts' => 500,
      'fields' => 'ids',
      'meta_key' => self::META_EP_PODCAST_ID,
      'meta_value' => $podcast_id,
      'orderby' => 'date',
      'order' => 'DESC',
      'suppress_filters' => false,
    ]);

    $rows = [];
    foreach ((array)$ids as $episode_id) {
      $row = $this->episode_row((int)$episode_id);
      if (!$include_non_public && !$this->episode_is_public($row)) continue;
      $rows[] = $row;
    }

    usort($rows, function ($left, $right) {
      $left_time = $this->podcast_datetime_timestamp((string)($left['publish_at'] ?? ''));
      $right_time = $this->podcast_datetime_timestamp((string)($right['publish_at'] ?? ''));
      if ($left_time === $right_time) return ((int)($right['ID'] ?? 0)) <=> ((int)($left['ID'] ?? 0));
      return $right_time <=> $left_time;
    });

    return $rows;
  }

  private function episode_row(int $episode_id): array {
    $post = get_post($episode_id);
    $audio_attachment_id = (int)get_post_meta($episode_id, self::META_EP_AUDIO_ATTACHMENT_ID, true);
    $video_attachment_id = (int)get_post_meta($episode_id, self::META_EP_VIDEO_ATTACHMENT_ID, true);
    $guid = sanitize_text_field((string)get_post_meta($episode_id, self::META_EP_GUID, true));
    if ($guid === '') $guid = wp_generate_uuid4();
    $audio_url = $audio_attachment_id > 0 ? (string)wp_get_attachment_url($audio_attachment_id) : esc_url_raw((string)get_post_meta($episode_id, self::META_EP_AUDIO_URL, true));
    $video_url = $video_attachment_id > 0 ? (string)wp_get_attachment_url($video_attachment_id) : esc_url_raw((string)get_post_meta($episode_id, self::META_EP_VIDEO_URL, true));
    return [
      'ID' => $episode_id,
      'post' => $post,
      'podcast_id' => (int)get_post_meta($episode_id, self::META_EP_PODCAST_ID, true),
      'summary' => sanitize_textarea_field((string)get_post_meta($episode_id, self::META_EP_SUMMARY, true)),
      'notes' => (string)get_post_meta($episode_id, self::META_EP_NOTES, true),
      'status' => $this->sanitize_episode_status((string)get_post_meta($episode_id, self::META_EP_STATUS, true)),
      'publish_at' => sanitize_text_field((string)get_post_meta($episode_id, self::META_EP_PUBLISH_AT, true)),
      'duration' => sanitize_text_field((string)get_post_meta($episode_id, self::META_EP_DURATION, true)),
      'explicit' => !empty(get_post_meta($episode_id, self::META_EP_EXPLICIT, true)),
      'season' => max(0, (int)get_post_meta($episode_id, self::META_EP_SEASON, true)),
      'number' => max(0, (int)get_post_meta($episode_id, self::META_EP_NUMBER, true)),
      'audio_attachment_id' => $audio_attachment_id,
      'audio_url' => $audio_url,
      'video_attachment_id' => $video_attachment_id,
      'video_url' => $video_url,
      'prepro_resources' => $this->normalize_prepro_resources((array)get_post_meta($episode_id, self::META_EP_PREPRO_RESOURCES, true)),
      'source_links' => $this->normalize_source_links((array)get_post_meta($episode_id, self::META_EP_SOURCE_LINKS, true)),
      'guid' => $guid,
    ];
  }

  private function episode_is_public(array $row): bool {
    return $this->episode_is_public_for_variant($row, 'audio') || $this->episode_is_public_for_variant($row, 'video');
  }

  private function episode_is_public_for_variant(array $row, string $variant): bool {
    if (($row['status'] ?? '') !== 'published') return false;
    $variant = in_array($variant, ['audio', 'video'], true) ? $variant : 'audio';
    $media_url = $variant === 'video' ? (string)($row['video_url'] ?? '') : (string)($row['audio_url'] ?? '');
    if ($media_url === '') return false;
    $publish_at = (string)($row['publish_at'] ?? '');
    if ($publish_at === '') return true;
    $publish_time = $this->podcast_datetime_timestamp($publish_at);
    return !$publish_time || $publish_time <= time();
  }

  private function sanitize_podcast_status(string $status): string {
    $status = sanitize_key($status);
    if (!in_array($status, ['active', 'paused', 'archived'], true)) $status = 'active';
    return $status;
  }

  private function sanitize_episode_status(string $status): string {
    $status = sanitize_key($status);
    if (!in_array($status, ['draft', 'scheduled', 'published', 'archived'], true)) $status = 'draft';
    return $status;
  }

  private function render_directory(int $user_id): string {
    $podcasts = [];
    foreach ($this->visible_podcast_ids_for_user($user_id) as $podcast_id) {
      $podcasts[] = $this->podcast_summary((int)$podcast_id, $user_id);
    }

    $can_create = $this->can_create_podcast($user_id);
    $published_episodes = 0;
    $total_members = 0;
    foreach ($podcasts as $row) {
      $published_episodes += (int)($row['published_episode_count'] ?? 0);
      $total_members += (int)($row['member_count'] ?? 0);
    }

    ob_start();
    $this->print_assets();
    echo '<div class="eum-pod">';
    echo $this->render_notice();

    echo '<section class="eum-pod__hero">';
    echo '<div><div class="eum-pod__eyebrow">Podcast Manager</div><h2>Podcasts</h2><div class="eum-pod__sub">Manage ownership, members, episodes, and public RSS feeds from one workspace.</div></div>';
    echo '<div class="eum-pod__hero-badges"><span class="eum-pod__badge">Visible podcasts: ' . count($podcasts) . '</span><span class="eum-pod__badge">Published episodes: ' . $published_episodes . '</span><span class="eum-pod__badge">Team seats: ' . $total_members . '</span></div>';
    echo '</section>';

    echo '<section class="eum-pod__cards">';
    echo $this->metric('Visible podcasts', (string)count($podcasts));
    echo $this->metric('Published episodes', (string)$published_episodes);
    echo $this->metric('Podcast managers', (string)array_sum(array_map(static fn($row) => (int)($row['manager_count'] ?? 0), $podcasts)));
    echo $this->metric('Finance-linked shows', (string)array_sum(array_map(static fn($row) => !empty($row['finance_path_label']) ? 1 : 0, $podcasts)));
    echo '</section>';

    echo '<section class="eum-pod__panel">';
    echo '<div class="eum-pod__panel-head"><h3>Podcast Directory</h3><span>Open a show to manage its team, episodes, feed, and finance path.</span></div>';
    if (empty($podcasts)) {
      echo '<div class="eum-pod__empty">No podcasts are visible for your current role yet.</div>';
    } else {
      echo '<div class="eum-pod__grid">';
      foreach ($podcasts as $row) {
        echo $this->render_podcast_card($row);
      }
      echo '</div>';
    }
    echo '</section>';

    if ($can_create) {
      echo '<section class="eum-pod__panel">';
      echo '<div class="eum-pod__panel-head"><h3>Create Podcast</h3><span>Every show must have at least one Podcast Manager. Members automatically receive Podcast Member status from the assignment.</span></div>';
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_create">';
      echo '<div class="eum-pod__form-grid">';
      echo '<label>Podcast Name<input type="text" name="podcast_title" required></label>';
      echo '<label>Status<select name="status">' . $this->select_options(['active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived'], 'active') . '</select></label>';
      echo '<label>Subtitle<input type="text" name="subtitle"></label>';
      echo '<label>Author<input type="text" name="author" value="' . esc_attr($this->user_label($user_id)) . '"></label>';
      echo '<label>Owner Email<input type="email" name="owner_email"></label>';
      echo '<label>Language<input type="text" name="language" value="en-us"></label>';
      echo '<label>Category<input type="text" name="category" placeholder="Business, Music, News"></label>';
      echo '<label>Website URL<input type="url" name="website_url"></label>';
      echo '<label>Copyright<input type="text" name="copyright"></label>';
      echo '<label>Cover Art<input type="file" name="cover_upload" accept="image/*"></label>';
      echo '</div>';
      echo '<label>Description<textarea name="description" rows="5"></textarea></label>';
      echo '<label>Podcast Source Links<textarea name="podcast_source_links" rows="3" placeholder="One source per line, or Label | https://..."></textarea></label>';
      echo '<label>Finance Path';
      echo $this->render_finance_path_picker('finance_path_term_id', 0, $user_id);
      echo '</label>';
      echo '<div class="eum-pod__two-up">';
      echo '<label>Podcast Managers<select name="manager_user_ids[]" multiple size="8">' . $this->user_options_html(class_exists('EUM_Access') && !EUM_Access::is_it_or_hr($user_id) ? [$user_id] : []) . '</select></label>';
      echo '<label>Podcast Members<select name="member_user_ids[]" multiple size="8">' . $this->user_options_html() . '</select></label>';
      echo '</div>';
      if (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr($user_id)) {
        echo '<input type="hidden" name="force_manager_user_id" value="' . (int)$user_id . '">';
      }
      echo '<button class="button button-primary">Create Podcast</button>';
      echo '</form>';
      echo '</section>';
    }

    echo '</div>';
    return ob_get_clean();
  }

  private function render_workspace(int $podcast_id, int $user_id): string {
    if (!$this->can_view_podcast($podcast_id, $user_id)) {
      return '<div class="eum-pod eum-pod--denied">You do not have access to this podcast.</div>';
    }

    $podcast = get_post($podcast_id);
    if (!$podcast || $podcast->post_type !== self::CPT_PODCAST) {
      return '<div class="eum-pod eum-pod--denied">Podcast not found.</div>';
    }

    $settings = $this->podcast_settings($podcast_id);
    $roles = $this->user_roles_for_podcast($podcast_id, $user_id);
    $role = $this->user_role_for_podcast($podcast_id, $user_id);
    $role_label = in_array('manager', $roles, true) && in_array('member', $roles, true)
      ? 'Podcast Manager + Member'
      : ($role === 'manager' ? 'Podcast Manager' : 'Podcast Member');
    $manager_ids = $this->explicit_manager_user_ids($podcast_id);
    $member_ids = $this->explicit_member_user_ids($podcast_id);
    $episodes = $this->podcast_episode_rows($podcast_id, true);
    $public_audio_episodes = array_values(array_filter($episodes, fn($row) => $this->episode_is_public_for_variant($row, 'audio')));
    $public_video_episodes = array_values(array_filter($episodes, fn($row) => $this->episode_is_public_for_variant($row, 'video')));
    $finance_label = $this->finance_path_label((int)$settings['finance_path_term_id']);
    $audio_feed_url = $this->feed_url($podcast_id, 'audio');
    $video_feed_url = $this->feed_url($podcast_id, 'video');
    $expenses = $this->podcast_expense_lines($podcast_id);
    $tab_group = 'podcast-tabs-' . $podcast_id;

    ob_start();
    $this->print_assets();
    echo '<div class="eum-pod">';
    echo $this->render_notice();

    echo '<section class="eum-pod__hero">';
    echo '<div><div class="eum-pod__eyebrow">Podcast Workspace</div><h2>' . esc_html($podcast->post_title) . '</h2><div class="eum-pod__sub">' . esc_html($settings['status']) . ' | ' . esc_html($finance_label !== '' ? $finance_label : 'No finance path') . ' | Audio feed: ' . esc_html(count($public_audio_episodes)) . ' | Video feed: ' . esc_html(count($public_video_episodes)) . '</div></div>';
    echo '<div class="eum-pod__hero-badges">';
    echo '<span class="eum-pod__badge">Role: ' . esc_html($role_label) . '</span>';
    echo '<span class="eum-pod__badge">Managers: ' . count($manager_ids) . '</span>';
    echo '<span class="eum-pod__badge">Members: ' . count($member_ids) . '</span>';
    echo '</div>';
    echo '</section>';

    echo '<nav class="eum-pod__tabs">';
    foreach ([
      'overview' => 'Overview',
      'team' => 'Team',
      'episodes' => 'Episodes',
      'feed' => 'Feed',
      'finance' => 'Finance',
      'settings' => 'Settings',
    ] as $key => $label) {
      echo '<button type="button" class="' . ($key === 'overview' ? 'is-active' : '') . '" data-eum-pod-tab-group="' . esc_attr($tab_group) . '" data-eum-pod-tab-target="' . esc_attr($key) . '">' . esc_html($label) . '</button>';
    }
    echo '</nav>';

    echo '<div class="eum-pod__tab-panels">';
    echo '<section class="eum-pod__panel is-active" data-eum-pod-tab-panel="' . esc_attr($tab_group) . '" data-eum-pod-tab-key="overview">';
    echo '<div class="eum-pod__panel-head"><h3>Overview</h3><span>Core show details, current feed status, and ownership at a glance.</span></div>';
    echo '<div class="eum-pod__overview">';
    echo '<div class="eum-pod__cover">';
    if (!empty($settings['cover_url'])) {
      echo '<img src="' . esc_url($settings['cover_url']) . '" alt="">';
    } else {
      echo '<div class="eum-pod__cover-fallback">No Cover</div>';
    }
    echo '</div>';
    echo '<div class="eum-pod__overview-copy">';
    echo '<div class="eum-pod__stack">';
    echo '<div class="eum-pod__card-sm"><strong>Subtitle</strong><div>' . esc_html($settings['subtitle'] !== '' ? $settings['subtitle'] : 'No subtitle yet.') . '</div></div>';
    echo '<div class="eum-pod__card-sm"><strong>Description</strong><div>' . wp_kses_post(wpautop($settings['description'] !== '' ? $settings['description'] : 'No podcast description yet.')) . '</div></div>';
    echo '<div class="eum-pod__card-sm"><strong>Author</strong><div>' . esc_html($settings['author'] !== '' ? $settings['author'] : 'No author set.') . '</div></div>';
    if (!empty($settings['source_links'])) {
      echo '<div class="eum-pod__card-sm"><strong>Podcast Sources</strong><ul class="eum-pod__resource-list">';
      foreach ((array)$settings['source_links'] as $source_link) {
        $source_url = esc_url((string)($source_link['url'] ?? ''));
        if ($source_url === '') continue;
        echo '<li><a href="' . $source_url . '" target="_blank" rel="noopener noreferrer">' . esc_html((string)($source_link['label'] ?? 'Source Link')) . '</a></li>';
      }
      echo '</ul></div>';
    }
    echo '<div class="eum-pod__card-sm"><strong>Audio Feed</strong><div><a href="' . esc_url($audio_feed_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($audio_feed_url) . '</a></div></div>';
    echo '<div class="eum-pod__card-sm"><strong>Video Feed</strong><div><a href="' . esc_url($video_feed_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($video_feed_url) . '</a></div></div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</section>';

    echo '<section class="eum-pod__panel" data-eum-pod-tab-panel="' . esc_attr($tab_group) . '" data-eum-pod-tab-key="team">';
    echo '<div class="eum-pod__panel-head"><h3>Team</h3><span>Podcast Managers own the show, and the same person can stay assigned as both a manager and a member when needed.</span></div>';
    echo '<div class="eum-pod__stack">';
    echo '<div class="eum-pod__section-title">Podcast Managers</div>';
    if (empty($manager_ids)) {
      echo '<div class="eum-pod__empty">No explicit podcast managers are assigned yet.</div>';
    } else {
      foreach ($manager_ids as $manager_user_id) {
        $manager_note = in_array((int)$manager_user_id, $member_ids, true) ? 'Explicit podcast owner and Podcast Member.' : 'Explicit podcast owner.';
        echo '<div class="eum-pod__card-sm"><div class="eum-pod__row"><div><strong>' . esc_html($this->user_label((int)$manager_user_id)) . '</strong><div class="description">' . esc_html($manager_note) . '</div></div>';
        if ($this->can_manage_podcast($podcast_id, $user_id)) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
          wp_nonce_field(self::NONCE, '_wpnonce');
          echo '<input type="hidden" name="action" value="eum_podcast_remove_member">';
          echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
          echo '<input type="hidden" name="user_id" value="' . (int)$manager_user_id . '">';
          echo '<input type="hidden" name="role" value="manager">';
          echo '<button class="button button-small">Remove</button>';
          echo '</form>';
        }
        echo '</div></div>';
      }
    }
    echo '</div>';

    echo '<div class="eum-pod__stack" style="margin-top:18px;">';
    echo '<div class="eum-pod__section-title">Podcast Members</div>';
    if (empty($member_ids)) {
      echo '<div class="eum-pod__empty">No podcast members are assigned yet.</div>';
    } else {
      foreach ($member_ids as $member_user_id) {
        $member_note = in_array((int)$member_user_id, $manager_ids, true) ? 'Podcast Member and Podcast Manager.' : 'Podcast Member.';
        echo '<div class="eum-pod__card-sm"><div class="eum-pod__row"><div><strong>' . esc_html($this->user_label((int)$member_user_id)) . '</strong><div class="description">' . esc_html($member_note) . '</div></div>';
        if ($this->can_manage_podcast($podcast_id, $user_id)) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
          wp_nonce_field(self::NONCE, '_wpnonce');
          echo '<input type="hidden" name="action" value="eum_podcast_remove_member">';
          echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
          echo '<input type="hidden" name="user_id" value="' . (int)$member_user_id . '">';
          echo '<input type="hidden" name="role" value="member">';
          echo '<button class="button button-small">Remove</button>';
          echo '</form>';
        }
        echo '</div></div>';
      }
    }
    echo '</div>';

    if ($this->can_manage_podcast($podcast_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form eum-pod__form--compact" style="margin-top:18px;">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_add_member">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<div class="eum-pod__form-grid">';
      echo '<label>User<select name="user_id"><option value="0">Choose a user...</option>' . $this->user_options_html() . '</select></label>';
      echo '<label>Role<select name="role">' . $this->select_options(['manager' => 'Podcast Manager', 'member' => 'Podcast Member'], 'member') . '</select></label>';
      echo '</div>';
      echo '<button class="button">Add To Podcast</button>';
      echo '</form>';
    }
    echo '</section>';

    echo '<section class="eum-pod__panel" data-eum-pod-tab-panel="' . esc_attr($tab_group) . '" data-eum-pod-tab-key="episodes">';
    echo '<div class="eum-pod__panel-head"><h3>Episodes</h3><span>Published episodes appear in the public feed. Drafts and scheduled items stay internal until they go live.</span></div>';
    if ($this->can_manage_podcast($podcast_id, $user_id)) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_add_episode">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<div class="eum-pod__form-grid">';
      echo '<label>Episode Title<input type="text" name="episode_title" required></label>';
      echo '<label>Status<select name="episode_status">' . $this->select_options(['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived'], 'draft') . '</select></label>';
      echo '<label>Publish At<input type="datetime-local" name="publish_at"></label>';
      echo '<label>Duration<input type="text" name="duration" placeholder="00:45:00"></label>';
      echo '<label>Season<input type="number" min="0" name="season"></label>';
      echo '<label>Episode #<input type="number" min="0" name="episode_number"></label>';
      echo '<label>Audio File<input type="file" name="audio_upload" accept="audio/*"></label>';
      echo '<label>Audio URL<input type="url" name="audio_url" placeholder="https://..."></label>';
      echo '<label>Video File<input type="file" name="video_upload" accept="video/*"></label>';
      echo '<label>Video URL<input type="url" name="video_url" placeholder="https://..."></label>';
      echo '<label>Pre-production Resources<input type="file" name="prepro_uploads[]" multiple></label>';
      echo '<label class="eum-pod__check"><input type="checkbox" name="explicit" value="1"> Explicit</label>';
      echo '</div>';
      echo '<label>Episode Summary<textarea name="summary" rows="3"></textarea></label>';
      echo '<label>Show Notes<textarea name="notes" rows="5"></textarea></label>';
      echo '<label>Episode Source Links<textarea name="episode_source_links" rows="3" placeholder="One source per line, or Label | https://..."></textarea></label>';
      echo '<label>Additional Pre-production Resource URLs<textarea name="prepro_resource_urls" rows="3" placeholder="One URL per line"></textarea></label>';
      echo '<button class="button">Add Episode</button>';
      echo '</form>';
      echo '<div class="eum-pod__two-up" style="margin-top:18px;">';
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form eum-pod__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_import_episode_xml">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<label>Import Episode XML<input type="file" name="import_episode_xml" accept=".xml,text/xml,application/xml"></label>';
      echo '<button class="button">Import Episode XML</button>';
      echo '</form>';
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form eum-pod__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_import_episodes_csv">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<label>Import Episodes CSV<input type="file" name="import_episodes_csv" accept=".csv,text/csv"></label>';
      echo '<button class="button">Import Episodes CSV</button>';
      echo '</form>';
      echo '</div>';
    }

    if (empty($episodes)) {
      echo '<div class="eum-pod__empty">No episodes yet.</div>';
    } else {
      echo '<div class="eum-pod__stack" style="margin-top:18px;">';
      foreach ($episodes as $episode) {
        echo $this->render_episode_card($podcast_id, $episode, $user_id);
      }
      echo '</div>';
    }
    echo '</section>';

    echo '<section class="eum-pod__panel" data-eum-pod-tab-panel="' . esc_attr($tab_group) . '" data-eum-pod-tab-key="feed">';
    echo '<div class="eum-pod__panel-head"><h3>Feed</h3><span>Audio and video each get their own RSS feed, so distributors can ingest the exact version they need.</span></div>';
    echo '<div class="eum-pod__stack">';
    echo '<div class="eum-pod__card-sm"><strong>Audio Feed URL</strong><div><a href="' . esc_url($audio_feed_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($audio_feed_url) . '</a></div></div>';
    echo '<div class="eum-pod__card-sm"><strong>Video Feed URL</strong><div><a href="' . esc_url($video_feed_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($video_feed_url) . '</a></div></div>';
    echo '<div class="eum-pod__card-sm"><strong>Feed Checklist</strong><ul class="eum-pod__checklist">';
    foreach ($this->feed_checklist($podcast_id, $settings, $public_audio_episodes, $public_video_episodes) as $item) {
      echo '<li class="' . (!empty($item['ok']) ? 'is-ok' : 'is-missing') . '">' . esc_html((string)$item['label']) . '</li>';
    }
    echo '</ul></div>';
    echo '<div class="eum-pod__card-sm"><strong>Audio Episodes In Feed</strong><div>' . count($public_audio_episodes) . '</div></div>';
    echo '<div class="eum-pod__card-sm"><strong>Video Episodes In Feed</strong><div>' . count($public_video_episodes) . '</div></div>';
    echo '</div>';
    echo '<div class="eum-pod__two-up" style="margin-top:18px;">';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form eum-pod__form--compact">';
    wp_nonce_field(self::NONCE, '_wpnonce');
    echo '<input type="hidden" name="action" value="eum_podcast_export_episodes_csv">';
    echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
    echo '<button class="button">Export Episodes CSV</button>';
    echo '</form>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form eum-pod__form--compact">';
    wp_nonce_field(self::NONCE, '_wpnonce');
    echo '<input type="hidden" name="action" value="eum_podcast_export_podcast_xml">';
    echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
    echo '<button class="button">Export Podcast XML</button>';
    echo '</form>';
    if ($this->can_manage_podcast($podcast_id, $user_id)) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form eum-pod__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_import_podcast_xml">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<label>Import Podcast XML<input type="file" name="import_podcast_xml" accept=".xml,text/xml,application/xml"></label>';
      echo '<button class="button">Import Podcast XML</button>';
      echo '</form>';
    }
    echo '</div>';
    if ($this->can_manage_podcast($podcast_id, $user_id)) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form" style="margin-top:18px;">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_import_rss">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<div class="eum-pod__panel-head" style="padding:0;border:0;background:transparent;"><h3>RSS Import</h3><span>Import episodes from another podcast feed by URL or uploaded RSS/XML file. Existing matching GUIDs are skipped.</span></div>';
      echo '<div class="eum-pod__form-grid">';
      echo '<label>RSS Feed URL<input type="url" name="rss_import_url" placeholder="https://example.com/feed.xml"></label>';
      echo '<label>RSS/XML File<input type="file" name="rss_import_file" accept=".xml,.rss,text/xml,application/rss+xml,application/xml"></label>';
      echo '<label>Status For Imported Episodes<select name="rss_import_status">' . $this->select_options(['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published'], 'draft') . '</select></label>';
      echo '<label class="eum-pod__check"><input type="checkbox" name="rss_update_podcast_details" value="1"> Update podcast settings from RSS channel details</label>';
      echo '</div>';
      echo '<button class="button">Import RSS Feed</button>';
      echo '</form>';
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form" style="margin-top:18px;">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_import_zip">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<div class="eum-pod__panel-head" style="padding:0;border:0;background:transparent;"><h3>Import Podcast ZIP</h3><span>For smaller archives. Large libraries should use the server folder below.</span></div>';
      echo '<div class="eum-pod__form-grid">';
      echo '<label>Podcast ZIP File<input type="file" name="podcast_zip" accept=".zip,application/zip" required></label>';
      echo '<label>Status For Imported Episodes<select name="zip_import_status">' . $this->select_options(['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published'], 'draft') . '</select></label>';
      echo '</div>';
      echo '<p class="description">Include one feed.xml, the episode XML files, and their MP3s. Matching uses feed enclosure/GUID filenames or matching XML and MP3 basenames. The feed updates show details and supports episode metadata; MP3s are copied into this site’s media library. Existing episodes with matching GUIDs are updated. The server’s maximum upload size still applies.</p>';
      echo '<button class="button button-primary">Import Podcast ZIP</button>';
      echo '</form>';
      $import_directory = $this->podcast_import_directory($podcast_id, true);
      $import_nonce = wp_create_nonce('eum_podcast_folder_import_' . $podcast_id);
      echo '<section class="eum-pod__form" id="eum-podcast-folder-import-' . (int)$podcast_id . '" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-podcast-id="' . (int)$podcast_id . '" data-nonce="' . esc_attr($import_nonce) . '" style="margin-top:18px;">';
      echo '<div class="eum-pod__panel-head" style="padding:0;border:0;background:transparent;"><h3>Import From Server Folder</h3><span>For large archives that time out in a browser upload.</span></div>';
      if ($import_directory !== '') {
        echo '<p>Upload episode XML files and their MP3s directly into this folder. An RSS <code>feed.xml</code> is optional; include it to import show details and episode publish dates:</p><p><code style="user-select:all;word-break:break-all;">' . esc_html($import_directory) . '</code></p>';
        echo '<p class="description">Put files directly in this folder, not inside another subfolder. Saving podcast details, episodes, team changes, and expenses writes the current data to feed.xml, podcast.xml, and per-episode XML; uploaded cover art and episode MP3s are copied here too. Edit the XML or replace an MP3, then click Import Folder Contents to apply your file changes. The importer updates matching episodes and refreshes the generated files when it finishes.</p>';
        echo '<p class="description">To update publish times from the RSS feed without changing any episode audio, use the date-only action:</p>';
        echo '<button type="button" class="button button-primary" data-eum-podcast-folder-import-start="1" data-mode="full">Import Folder Contents</button> ';
        echo '<button type="button" class="button" data-eum-podcast-folder-import-start="1" data-mode="publish_dates">Update Publish Times from Feed (Preserve Audio)</button> ';
        echo '<span role="status" aria-live="polite" data-eum-podcast-folder-import-status="1"></span>';
      } else {
        echo '<p class="eum-pod__notice is-error">Could not create the podcast import folder. Check that the WordPress uploads directory is writable.</p>';
      }
      echo '</section>';
      if ($import_directory !== '') {
        echo '<script>(function(){var box=document.getElementById("eum-podcast-folder-import-' . (int)$podcast_id . '");if(!box||box.dataset.bound)return;box.dataset.bound="1";var buttons=Array.prototype.slice.call(box.querySelectorAll("[data-eum-podcast-folder-import-start]"));var status=box.querySelector("[data-eum-podcast-folder-import-status]");function busy(value){buttons.forEach(function(button){button.disabled=value;});}function send(action,mode){var body=new URLSearchParams({action:action,podcast_id:box.dataset.podcastId,nonce:box.dataset.nonce});if(mode)body.set("mode",mode);return fetch(box.dataset.ajaxUrl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()}).then(function(response){return response.json();}).then(function(result){if(!result.success)throw new Error(result.data&&result.data.message?result.data.message:"Folder import request failed.");return result.data;});}function next(){send("eum_podcast_folder_import_next","").then(function(data){status.textContent=data.message||("Processed "+data.processed+" of "+data.total+" entries...");if(data.complete){busy(false);return;}next();}).catch(function(error){busy(false);status.textContent=error.message||"Folder import stopped.";});}buttons.forEach(function(button){button.addEventListener("click",function(){var mode=button.getAttribute("data-mode")||"full";busy(true);status.textContent=mode==="publish_dates"?"Reading feed.xml; audio sources will be preserved...":"Scanning the import folder...";send("eum_podcast_folder_import_start",mode).then(function(data){status.textContent=data.message;if(data.complete){busy(false);return;}next();}).catch(function(error){busy(false);status.textContent=error.message||"Could not start folder import.";});});});}());</script>';
      }
    }
    echo '</section>';

    echo '<section class="eum-pod__panel" data-eum-pod-tab-panel="' . esc_attr($tab_group) . '" data-eum-pod-tab-key="finance">';
    echo '<div class="eum-pod__panel-head"><h3>Finance</h3><span>Podcast expenses can use a finance path, a billable job path, or both.</span></div>';
    if (empty($expenses)) {
      echo '<div class="eum-pod__empty">No podcast expenses logged yet.</div>';
    } else {
      echo '<div class="eum-pod__stack">';
      foreach ($expenses as $expense) {
        echo '<div class="eum-pod__card-sm"><strong>' . esc_html((string)($expense['title'] ?? 'Expense')) . '</strong><div>' . esc_html($this->format_money((float)($expense['amount'] ?? 0))) . ' | ' . esc_html((string)($expense['occurred_on'] ?? '')) . '</div><div class="description">Finance: ' . esc_html($this->finance_path_label((int)($expense['finance_path_term_id'] ?? 0)) ?: 'None') . ' | Billable Job Path: ' . esc_html($this->job_path_label((int)($expense['job_path_term_id'] ?? 0)) ?: 'None') . '</div><div class="description">' . esc_html((string)($expense['notes'] ?? '')) . '</div></div>';
      }
      echo '</div>';
    }
    if ($this->can_manage_podcast($podcast_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form" style="margin-top:18px;">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_add_expense">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<div class="eum-pod__form-grid">';
      echo '<label>Expense Title<input type="text" name="expense_title" required></label>';
      echo '<label>Amount<input type="number" step="0.01" min="0" name="expense_amount" required></label>';
      echo '<label>Occurred On<input type="date" name="expense_occurred_on" value="' . esc_attr(current_time('Y-m-d')) . '"></label>';
      echo '</div>';
      echo '<label>Finance Path';
      echo $this->render_finance_path_picker('expense_finance_path_term_id', (int)$settings['finance_path_term_id'], $user_id);
      echo '</label>';
      echo '<label>Billable Job Path';
      echo $this->render_job_path_picker('expense_job_path_term_id', 0, $user_id);
      echo '</label>';
      echo '<label>Notes<textarea name="expense_notes" rows="3"></textarea></label>';
      echo '<button class="button">Add Expense</button>';
      echo '</form>';
    }
    echo '</section>';

    echo '<section class="eum-pod__panel" data-eum-pod-tab-panel="' . esc_attr($tab_group) . '" data-eum-pod-tab-key="settings">';
    echo '<div class="eum-pod__panel-head"><h3>Settings</h3><span>Podcast managers and IT / HR can manage these settings here.</span></div>';
    if ($this->can_manage_podcast($podcast_id, $user_id)) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_save">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<div class="eum-pod__form-grid">';
      echo '<label>Podcast Name<input type="text" name="podcast_title" required value="' . esc_attr($podcast->post_title) . '"></label>';
      echo '<label>Status<select name="status">' . $this->select_options(['active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived'], $settings['status']) . '</select></label>';
      echo '<label>Subtitle<input type="text" name="subtitle" value="' . esc_attr($settings['subtitle']) . '"></label>';
      echo '<label>Author<input type="text" name="author" value="' . esc_attr($settings['author']) . '"></label>';
      echo '<label>Owner Email<input type="email" name="owner_email" value="' . esc_attr($settings['owner_email']) . '"></label>';
      echo '<label>Language<input type="text" name="language" value="' . esc_attr($settings['language']) . '"></label>';
      echo '<label>Category<input type="text" name="category" value="' . esc_attr($settings['category']) . '"></label>';
      echo '<label>Website URL<input type="url" name="website_url" value="' . esc_attr($settings['website_url']) . '"></label>';
      echo '<label>Copyright<input type="text" name="copyright" value="' . esc_attr($settings['copyright']) . '"></label>';
      echo '<label>Cover Art<input type="file" name="cover_upload" accept="image/*"></label>';
      echo '</div>';
      if (!empty($settings['cover_url'])) {
        echo '<p><label><input type="checkbox" name="remove_cover" value="1"> Remove current cover</label></p>';
      }
      echo '<label>Description<textarea name="description" rows="5">' . esc_textarea($settings['description']) . '</textarea></label>';
      echo '<label>Podcast Source Links<textarea name="podcast_source_links" rows="4" placeholder="One source per line, or Label | https://...">' . esc_textarea($this->source_links_to_text((array)($settings['source_links'] ?? []))) . '</textarea></label>';
      echo '<label>Finance Path';
      echo $this->render_finance_path_picker('finance_path_term_id', (int)$settings['finance_path_term_id'], $user_id);
      echo '</label>';
      echo '<div class="eum-pod__two-up">';
      echo '<label>Podcast Managers<select name="manager_user_ids[]" multiple size="8">' . $this->user_options_html($manager_ids) . '</select></label>';
      echo '<label>Podcast Members<select name="member_user_ids[]" multiple size="8">' . $this->user_options_html($member_ids) . '</select></label>';
      echo '</div>';
      echo '<button class="button button-primary">Save Podcast</button>';
      echo '</form>';
    } else {
      echo '<div class="eum-pod__empty">You can view this podcast, but only podcast managers can change settings directly.</div>';
    }
    echo '</section>';

    echo '</div>';
    echo '</div>';
    return ob_get_clean();
  }

  private function podcast_summary(int $podcast_id, int $user_id): array {
    $podcast = get_post($podcast_id);
    $settings = $this->podcast_settings($podcast_id);
    $episodes = $this->podcast_episode_rows($podcast_id, true);
    $public_episodes = array_values(array_filter($episodes, fn($row) => $this->episode_is_public($row)));
    $managers = $this->explicit_manager_user_ids($podcast_id);
    $members = $this->explicit_member_user_ids($podcast_id);
    return [
      'ID' => $podcast_id,
      'title' => $podcast ? (string)$podcast->post_title : 'Podcast #' . $podcast_id,
      'status' => (string)$settings['status'],
      'subtitle' => (string)$settings['subtitle'],
      'finance_path_label' => $this->finance_path_label((int)$settings['finance_path_term_id']),
      'manager_count' => count($managers),
      'member_count' => count($members),
      'audio_episode_count' => count(array_values(array_filter($episodes, fn($row) => $this->episode_is_public_for_variant($row, 'audio')))),
      'video_episode_count' => count(array_values(array_filter($episodes, fn($row) => $this->episode_is_public_for_variant($row, 'video')))),
      'published_episode_count' => count($public_episodes),
      'latest_episode_title' => !empty($public_episodes) && !empty($public_episodes[0]['post']) ? (string)$public_episodes[0]['post']->post_title : '',
      'roles' => $this->user_roles_for_podcast($podcast_id, $user_id),
      'feed_url' => $this->feed_url($podcast_id),
      'audio_feed_url' => $this->feed_url($podcast_id, 'audio'),
      'video_feed_url' => $this->feed_url($podcast_id, 'video'),
    ];
  }

  private function render_podcast_card(array $row): string {
    $url = esc_url(add_query_arg(['podcast_id' => (int)($row['ID'] ?? 0)], remove_query_arg(['eum_podcast_msg', 'eum_podcast_type'])));
    ob_start();
    echo '<article class="eum-pod__podcast-card">';
    echo '<div class="eum-pod__podcast-card-head"><h3><a href="' . $url . '">' . esc_html((string)($row['title'] ?? 'Podcast')) . '</a></h3><span class="eum-pod__pill">' . esc_html(ucfirst((string)($row['status'] ?? 'active'))) . '</span></div>';
    if (!empty($row['subtitle'])) echo '<p class="eum-pod__muted">' . esc_html((string)$row['subtitle']) . '</p>';
    echo '<div class="eum-pod__meta">';
    echo '<span>Managers: ' . (int)($row['manager_count'] ?? 0) . '</span>';
    echo '<span>Members: ' . (int)($row['member_count'] ?? 0) . '</span>';
    echo '<span>Audio: ' . (int)($row['audio_episode_count'] ?? 0) . '</span>';
    echo '<span>Video: ' . (int)($row['video_episode_count'] ?? 0) . '</span>';
    echo '</div>';
    if (!empty($row['finance_path_label'])) echo '<div class="eum-pod__muted">Finance: ' . esc_html((string)$row['finance_path_label']) . '</div>';
    if (!empty($row['latest_episode_title'])) echo '<div class="eum-pod__muted">Latest: ' . esc_html((string)$row['latest_episode_title']) . '</div>';
    echo '<div class="eum-pod__actions"><a class="button button-primary" href="' . $url . '">Open</a><a class="button" href="' . esc_url((string)($row['audio_feed_url'] ?? '')) . '" target="_blank" rel="noopener noreferrer">Audio Feed</a><a class="button" href="' . esc_url((string)($row['video_feed_url'] ?? '')) . '" target="_blank" rel="noopener noreferrer">Video Feed</a></div>';
    echo '</article>';
    return ob_get_clean();
  }

  private function render_episode_card(int $podcast_id, array $episode, int $viewer_user_id): string {
    $post = $episode['post'] ?? null;
    if (!$post instanceof WP_Post) return '';

    ob_start();
    echo '<article class="eum-pod__episode-card">';
    echo '<div class="eum-pod__episode-head">';
    echo '<div><h4>' . esc_html($post->post_title) . '</h4><div class="eum-pod__muted">' . esc_html($this->episode_status_label($episode)) . '</div></div>';
    echo '<div class="eum-pod__actions">';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field(self::NONCE, '_wpnonce');
    echo '<input type="hidden" name="action" value="eum_podcast_export_episode_xml">';
    echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
    echo '<input type="hidden" name="episode_id" value="' . (int)$post->ID . '">';
    echo '<button class="button button-small">Export XML</button>';
    echo '</form>';
    if (!empty($episode['audio_url'])) {
      echo '<a class="button button-small" href="' . esc_url((string)$episode['audio_url']) . '" target="_blank" rel="noopener noreferrer">Audio</a>';
    }
    if (!empty($episode['video_url'])) {
      echo '<a class="button button-small" href="' . esc_url((string)$episode['video_url']) . '" target="_blank" rel="noopener noreferrer">Video</a>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="eum-pod__stack">';
    if (!empty($episode['summary'])) echo '<div class="eum-pod__card-sm"><strong>Summary</strong><div>' . esc_html((string)$episode['summary']) . '</div></div>';
    if (!empty($episode['notes'])) echo '<div class="eum-pod__card-sm"><strong>Show Notes</strong><div>' . wp_kses_post(wpautop((string)$episode['notes'])) . '</div></div>';
    if (!empty($episode['source_links'])) {
      echo '<div class="eum-pod__card-sm"><strong>Episode Sources</strong><ul class="eum-pod__resource-list">';
      foreach ((array)$episode['source_links'] as $source_link) {
        $source_url = esc_url((string)($source_link['url'] ?? ''));
        if ($source_url === '') continue;
        echo '<li><a href="' . $source_url . '" target="_blank" rel="noopener noreferrer">' . esc_html((string)($source_link['label'] ?? 'Source Link')) . '</a></li>';
      }
      echo '</ul></div>';
    }
    if (!empty($episode['audio_url'])) {
      echo '<div class="eum-pod__card-sm"><strong>Audio Preview</strong><div><audio controls preload="none" src="' . esc_url((string)$episode['audio_url']) . '"></audio></div></div>';
    }
    if (!empty($episode['video_url'])) {
      echo '<div class="eum-pod__card-sm"><strong>Video Preview</strong><div><video controls preload="metadata" style="max-width:420px;width:100%;" src="' . esc_url((string)$episode['video_url']) . '"></video></div></div>';
    }
    if (!empty($episode['prepro_resources'])) {
      echo '<div class="eum-pod__card-sm"><strong>Pre-production Resources</strong><ul class="eum-pod__resource-list">';
      foreach ((array)$episode['prepro_resources'] as $resource) {
        $resource_url = esc_url((string)($resource['url'] ?? ''));
        if ($resource_url === '') continue;
        $label = (string)($resource['label'] ?? basename((string)$resource_url));
        echo '<li><a href="' . $resource_url . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a></li>';
      }
      echo '</ul></div>';
    }
    echo '</div>';

    if ($this->can_manage_podcast($podcast_id, $viewer_user_id)) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-pod__form eum-pod__form--compact" style="margin-top:16px;">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_podcast_save_episode">';
      echo '<input type="hidden" name="podcast_id" value="' . (int)$podcast_id . '">';
      echo '<input type="hidden" name="episode_id" value="' . (int)$post->ID . '">';
      echo '<div class="eum-pod__form-grid">';
      echo '<label>Episode Title<input type="text" name="episode_title" required value="' . esc_attr($post->post_title) . '"></label>';
      echo '<label>Status<select name="episode_status">' . $this->select_options(['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived'], (string)$episode['status']) . '</select></label>';
      echo '<label>Publish At<input type="datetime-local" name="publish_at" value="' . esc_attr($this->datetime_local_value((string)$episode['publish_at'])) . '"></label>';
      echo '<label>Duration<input type="text" name="duration" value="' . esc_attr((string)$episode['duration']) . '"></label>';
      echo '<label>Season<input type="number" min="0" name="season" value="' . (int)$episode['season'] . '"></label>';
      echo '<label>Episode #<input type="number" min="0" name="episode_number" value="' . (int)$episode['number'] . '"></label>';
      echo '<label>Replace Audio<input type="file" name="audio_upload" accept="audio/*"></label>';
      echo '<label>Audio URL<input type="url" name="audio_url" value="' . esc_attr((string)$episode['audio_url']) . '"></label>';
      echo '<label>Replace Video<input type="file" name="video_upload" accept="video/*"></label>';
      echo '<label>Video URL<input type="url" name="video_url" value="' . esc_attr((string)$episode['video_url']) . '"></label>';
      echo '<label>Pre-production Resources<input type="file" name="prepro_uploads[]" multiple></label>';
      echo '<label class="eum-pod__check"><input type="checkbox" name="explicit" value="1" ' . checked(!empty($episode['explicit']), true, false) . '> Explicit</label>';
      echo '</div>';
      echo '<label>Episode Summary<textarea name="summary" rows="3">' . esc_textarea((string)$episode['summary']) . '</textarea></label>';
      echo '<label>Show Notes<textarea name="notes" rows="5">' . esc_textarea((string)$episode['notes']) . '</textarea></label>';
      echo '<label>Episode Source Links<textarea name="episode_source_links" rows="3" placeholder="One source per line, or Label | https://...">' . esc_textarea($this->source_links_to_text((array)($episode['source_links'] ?? []))) . '</textarea></label>';
      echo '<label>Additional Pre-production Resource URLs<textarea name="prepro_resource_urls" rows="3" placeholder="One URL per line"></textarea></label>';
      echo '<button class="button">Save Episode</button>';
      echo '</form>';
    }

    echo '</article>';
    return ob_get_clean();
  }

  private function episode_status_label(array $episode): string {
    $bits = [ucfirst((string)($episode['status'] ?? 'draft'))];
    if (!empty($episode['publish_at'])) $bits[] = (string)($episode['publish_at']);
    if (!empty($episode['duration'])) $bits[] = (string)($episode['duration']);
    if (!empty($episode['explicit'])) $bits[] = 'Explicit';
    return implode(' | ', array_filter($bits));
  }

  private function render_finance_path_picker(string $input_name, int $selected_term_id, int $actor_user_id): string {
    if (class_exists('EUM_Profiles') && class_exists('EUM_Finance')) {
      return EUM_Profiles::render_taxonomy_directory_picker(EUM_Finance::TAX_FINANCE_PATH, $input_name, $selected_term_id, $actor_user_id, [
        'placeholder' => 'Select a finance path',
        'required' => false,
        'none_label' => '-- None --',
        'enforce_manageable_job_paths' => false,
      ]);
    }

    return '<input type="number" name="' . esc_attr($input_name) . '" value="' . (int)$selected_term_id . '" min="0">';
  }

  private function finance_path_label(int $term_id): string {
    if ($term_id <= 0) return '';
    if (class_exists('EUM_Finance') && method_exists('EUM_Finance', 'finance_path_option_label')) {
      return (string)EUM_Finance::finance_path_option_label($term_id);
    }
    $term = get_term($term_id, class_exists('EUM_Finance') ? EUM_Finance::TAX_FINANCE_PATH : '');
    return ($term && !is_wp_error($term)) ? (string)$term->name : '';
  }

  private function user_label(int $user_id): string {
    $user = get_user_by('id', $user_id);
    if (!$user) return 'User #' . $user_id;
    return $user->display_name ?: $user->user_login;
  }

  private function user_options_html(array $selected = []): string {
    $html = '';
    foreach ((array)get_users(['fields' => ['ID', 'display_name', 'user_login'], 'number' => 1000]) as $user) {
      $user_id = (int)$user->ID;
      $label = $user->display_name ?: $user->user_login;
      $html .= '<option value="' . $user_id . '" ' . selected(in_array($user_id, $selected, true), true, false) . '>' . esc_html($label . ' (#' . $user_id . ')') . '</option>';
    }
    return $html;
  }

  private function select_options(array $options, string $current): string {
    $html = '';
    foreach ($options as $value => $label) {
      $html .= '<option value="' . esc_attr((string)$value) . '" ' . selected((string)$current, (string)$value, false) . '>' . esc_html((string)$label) . '</option>';
    }
    return $html;
  }

  private function metric(string $label, string $value): string {
    return '<div class="eum-pod__metric"><div class="eum-pod__metric-value">' . esc_html($value) . '</div><div class="eum-pod__metric-label">' . esc_html($label) . '</div></div>';
  }

  private function feed_url(int $podcast_id, string $variant = 'audio'): string {
    $variant = in_array($variant, ['audio', 'video'], true) ? $variant : 'audio';
    return add_query_arg(['eum_podcast_feed' => $podcast_id, 'variant' => $variant], home_url('/'));
  }

  private function feed_checklist(int $podcast_id, array $settings, array $public_audio_episodes, array $public_video_episodes): array {
    $items = [
      ['label' => 'Podcast title is set', 'ok' => get_the_title($podcast_id) !== ''],
      ['label' => 'Description is set', 'ok' => (string)($settings['description'] ?? '') !== ''],
      ['label' => 'Author is set', 'ok' => (string)($settings['author'] ?? '') !== ''],
      ['label' => 'Owner email is set', 'ok' => (string)($settings['owner_email'] ?? '') !== ''],
      ['label' => 'Cover art is uploaded', 'ok' => !empty($settings['cover_url'])],
      ['label' => 'At least one public audio episode exists', 'ok' => !empty($public_audio_episodes)],
    ];
    if (!empty($public_video_episodes)) {
      $items[] = ['label' => 'Video feed is ready for distribution', 'ok' => true];
    }
    return $items;
  }

  private function podcast_expense_lines(int $podcast_id): array {
    $rows = (array)get_post_meta($podcast_id, self::META_EXPENSE_LINES, true);
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $out[] = [
        'title' => sanitize_text_field((string)($row['title'] ?? '')),
        'amount' => round((float)($row['amount'] ?? 0), 2),
        'occurred_on' => sanitize_text_field((string)($row['occurred_on'] ?? '')),
        'finance_path_term_id' => max(0, (int)($row['finance_path_term_id'] ?? 0)),
        'job_path_term_id' => max(0, (int)($row['job_path_term_id'] ?? 0)),
        'notes' => sanitize_textarea_field((string)($row['notes'] ?? '')),
        'submitted_by' => max(0, (int)($row['submitted_by'] ?? 0)),
        'created_at' => sanitize_text_field((string)($row['created_at'] ?? '')),
      ];
    }
    usort($out, static function ($left, $right) {
      return strcmp((string)($right['occurred_on'] ?? ''), (string)($left['occurred_on'] ?? ''));
    });
    return $out;
  }

  private function render_job_path_picker(string $input_name, int $selected_term_id, int $actor_user_id): string {
    if (class_exists('EUM_Profiles')) {
      return EUM_Profiles::render_job_path_directory_picker($input_name, $selected_term_id, $actor_user_id, [
        'placeholder' => 'Select a billable job path',
        'required' => false,
        'none_label' => '-- None --',
        'enforce_manageable_job_paths' => false,
      ]);
    }
    return '<input type="number" name="' . esc_attr($input_name) . '" value="' . (int)$selected_term_id . '" min="0">';
  }

  private function job_path_label(int $term_id): string {
    if ($term_id <= 0) return '';
    if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'term_full_path')) {
      return (string)EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ');
    }
    $term = get_term($term_id, 'eum_job_path');
    return ($term && !is_wp_error($term)) ? (string)$term->name : '';
  }

  private function format_money(float $amount): string {
    return '$' . number_format($amount, 2);
  }

  private function normalize_prepro_resources(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $url = esc_url_raw((string)($row['url'] ?? ''));
      if ($url === '') continue;
      $out[] = [
        'attachment_id' => max(0, (int)($row['attachment_id'] ?? 0)),
        'url' => $url,
        'label' => sanitize_text_field((string)($row['label'] ?? basename((string)parse_url($url, PHP_URL_PATH)))),
      ];
    }
    $seen = [];
    $clean = [];
    foreach ($out as $row) {
      $key = strtolower((string)$row['url']);
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $clean[] = $row;
    }
    return $clean;
  }

  private function normalize_source_links(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $url = esc_url_raw((string)($row['url'] ?? ''));
      if ($url === '') continue;
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($label === '') {
        $label = $this->link_label_from_url($url);
      }
      $out[] = [
        'url' => $url,
        'label' => $label,
      ];
    }

    $seen = [];
    $clean = [];
    foreach ($out as $row) {
      $key = strtolower((string)$row['url']);
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $clean[] = $row;
    }
    return $clean;
  }

  private function source_links_from_text(string $value): array {
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($value)) as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;

      $label = '';
      $url = $line;
      foreach (['|', ','] as $separator) {
        if (strpos($line, $separator) === false) continue;
        [$maybe_label, $maybe_url] = array_map('trim', explode($separator, $line, 2));
        if (filter_var($maybe_url, FILTER_VALIDATE_URL)) {
          $label = $maybe_label;
          $url = $maybe_url;
          break;
        }
      }

      $url = esc_url_raw($url);
      if ($url === '') continue;
      $rows[] = [
        'url' => $url,
        'label' => $label !== '' ? sanitize_text_field($label) : $this->link_label_from_url($url),
      ];
    }
    return $this->normalize_source_links($rows);
  }

  private function source_links_to_text(array $rows): string {
    $lines = [];
    foreach ($this->normalize_source_links($rows) as $row) {
      $url = (string)($row['url'] ?? '');
      if ($url === '') continue;
      $label = trim((string)($row['label'] ?? ''));
      $lines[] = ($label !== '' ? $label . ' | ' : '') . $url;
    }
    return implode("\n", $lines);
  }

  private function link_label_from_url(string $url): string {
    $host = parse_url($url, PHP_URL_HOST);
    $path = parse_url($url, PHP_URL_PATH);
    $label = '';
    if (is_string($path) && trim($path, '/') !== '') {
      $label = basename($path);
    }
    if ($label === '' && is_string($host) && $host !== '') {
      $label = $host;
    }
    return sanitize_text_field($label !== '' ? $label : 'Source Link');
  }

  private function resources_from_attachment_ids(array $attachment_ids): array {
    $rows = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $attachment_ids)))) as $attachment_id) {
      $url = (string)wp_get_attachment_url($attachment_id);
      if ($url === '') continue;
      $rows[] = [
        'attachment_id' => $attachment_id,
        'url' => $url,
        'label' => basename((string)parse_url($url, PHP_URL_PATH)),
      ];
    }
    return $rows;
  }

  private function resources_from_url_text(string $value): array {
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($value)) as $line) {
      $url = esc_url_raw(trim((string)$line));
      if ($url === '') continue;
      $rows[] = [
        'attachment_id' => 0,
        'url' => $url,
        'label' => basename((string)parse_url($url, PHP_URL_PATH)),
      ];
    }
    return $rows;
  }

  private function handle_multiple_media_uploads(string $field, int $parent_id = 0): array {
    if (empty($_FILES[$field]) || empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return [];
    if (!function_exists('media_handle_upload')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $attachment_ids = [];
    $tmp_field = $field . '_single';
    $names = (array)$_FILES[$field]['name'];
    foreach ($names as $index => $name) {
      if ((string)$name === '') continue;
      $_FILES[$tmp_field] = [
        'name' => (string)$name,
        'type' => (string)($_FILES[$field]['type'][$index] ?? ''),
        'tmp_name' => (string)($_FILES[$field]['tmp_name'][$index] ?? ''),
        'error' => (int)($_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int)($_FILES[$field]['size'][$index] ?? 0),
      ];
      $attachment_id = media_handle_upload($tmp_field, $parent_id);
      if (!is_wp_error($attachment_id) && (int)$attachment_id > 0) {
        $attachment_ids[] = (int)$attachment_id;
      }
      unset($_FILES[$tmp_field]);
    }
    return $attachment_ids;
  }

  public function handle_create_podcast(): void {
    $actor_user_id = (int)get_current_user_id();
    if (!$this->can_create_podcast($actor_user_id)) wp_die('Not allowed.');
    $this->verify_nonce();

    $title = sanitize_text_field((string)($_POST['podcast_title'] ?? ''));
    if ($title === '') $this->redirect_with_notice(0, 'Podcast name is required.', 'error');

    $manager_ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['manager_user_ids'] ?? [])))));
    $forced_manager_user_id = isset($_POST['force_manager_user_id']) ? (int)$_POST['force_manager_user_id'] : 0;
    if ($forced_manager_user_id > 0 && !in_array($forced_manager_user_id, $manager_ids, true)) $manager_ids[] = $forced_manager_user_id;
    if (empty($manager_ids) && (!class_exists('EUM_Access') || !EUM_Access::is_it_or_hr($actor_user_id))) $manager_ids[] = $actor_user_id;
    $manager_ids = array_values(array_unique(array_filter($manager_ids)));
    if (empty($manager_ids)) $this->redirect_with_notice(0, 'Every podcast needs at least one Podcast Manager.', 'error');

    $podcast_id = (int)wp_insert_post([
      'post_type' => self::CPT_PODCAST,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $actor_user_id,
    ]);
    if ($podcast_id <= 0) $this->redirect_with_notice(0, 'Could not create the podcast.', 'error');

    update_post_meta($podcast_id, self::META_CREATED_BY, $actor_user_id);
    $this->save_podcast_settings($podcast_id, $actor_user_id, false);
    $this->save_podcast_team($podcast_id, $manager_ids, array_map('intval', (array)($_POST['member_user_ids'] ?? [])));
    $this->push_activity($podcast_id, $actor_user_id, 'Created the podcast.');
    $this->sync_podcast_source_files($podcast_id, []);

    $this->redirect_with_notice($podcast_id, 'Podcast created.', 'success');
  }

  public function handle_save_podcast(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();

    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');

    $manager_ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['manager_user_ids'] ?? [])))));
    if (empty($manager_ids)) $this->redirect_with_notice($podcast_id, 'Every podcast needs at least one Podcast Manager.', 'error');

    $title = sanitize_text_field((string)($_POST['podcast_title'] ?? ''));
    if ($title === '') $this->redirect_with_notice($podcast_id, 'Podcast name is required.', 'error');

    wp_update_post([
      'ID' => $podcast_id,
      'post_title' => $title,
    ]);

    $this->save_podcast_settings($podcast_id, $actor_user_id, true);
    $this->save_podcast_team($podcast_id, $manager_ids, array_map('intval', (array)($_POST['member_user_ids'] ?? [])));
    $this->push_activity($podcast_id, $actor_user_id, 'Updated podcast settings.');
    $this->sync_podcast_source_files($podcast_id, []);

    $this->redirect_with_notice($podcast_id, 'Podcast saved.', 'success');
  }

  private function save_podcast_settings(int $podcast_id, int $actor_user_id, bool $allow_remove_cover): void {
    update_post_meta($podcast_id, self::META_STATUS, $this->sanitize_podcast_status((string)($_POST['status'] ?? 'active')));
    update_post_meta($podcast_id, self::META_SUBTITLE, sanitize_text_field((string)($_POST['subtitle'] ?? '')));
    update_post_meta($podcast_id, self::META_DESCRIPTION, wp_kses_post((string)($_POST['description'] ?? '')));
    update_post_meta($podcast_id, self::META_AUTHOR, sanitize_text_field((string)($_POST['author'] ?? '')));
    update_post_meta($podcast_id, self::META_OWNER_EMAIL, sanitize_email((string)($_POST['owner_email'] ?? '')));
    update_post_meta($podcast_id, self::META_LANGUAGE, sanitize_text_field((string)($_POST['language'] ?? 'en-us')));
    update_post_meta($podcast_id, self::META_CATEGORY, sanitize_text_field((string)($_POST['category'] ?? '')));
    update_post_meta($podcast_id, self::META_WEBSITE_URL, esc_url_raw((string)($_POST['website_url'] ?? '')));
    update_post_meta($podcast_id, self::META_COPYRIGHT, sanitize_text_field((string)($_POST['copyright'] ?? '')));
    update_post_meta($podcast_id, self::META_FINANCE_PATH_TERM_ID, max(0, (int)($_POST['finance_path_term_id'] ?? 0)));
    update_post_meta($podcast_id, self::META_SOURCE_LINKS, $this->source_links_from_text((string)($_POST['podcast_source_links'] ?? '')));

    if ($allow_remove_cover && !empty($_POST['remove_cover'])) {
      delete_post_meta($podcast_id, self::META_COVER_ATTACHMENT_ID);
    }

    $cover_attachment_id = $this->handle_media_upload('cover_upload', $podcast_id, false);
    if ($cover_attachment_id > 0) {
      update_post_meta($podcast_id, self::META_COVER_ATTACHMENT_ID, $cover_attachment_id);
      $this->push_activity($podcast_id, $actor_user_id, 'Updated the podcast cover art.');
    }
  }

  private function save_podcast_team(int $podcast_id, array $manager_ids, array $member_ids): void {
    $manager_ids = array_values(array_unique(array_filter(array_map('intval', $manager_ids))));
    $member_ids = array_values(array_unique(array_filter(array_map('intval', $member_ids), static fn($user_id) => $user_id > 0)));
    update_post_meta($podcast_id, self::META_MANAGER_USER_IDS, $manager_ids);
    update_post_meta($podcast_id, self::META_MEMBER_USER_IDS, $member_ids);
    self::$manager_anywhere_cache = [];
    self::$member_anywhere_cache = [];
  }

  public function handle_add_member(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();

    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $role = sanitize_key((string)($_POST['role'] ?? 'member'));
    if ($podcast_id <= 0 || $user_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');
    if (!in_array($role, ['manager', 'member'], true)) $role = 'member';

    $manager_ids = $this->explicit_manager_user_ids($podcast_id);
    $member_ids = $this->explicit_member_user_ids($podcast_id);

    if ($role === 'manager') {
      $manager_ids[] = $user_id;
    } else {
      $member_ids[] = $user_id;
    }

    $this->save_podcast_team($podcast_id, $manager_ids, $member_ids);
    $this->push_activity($podcast_id, $actor_user_id, 'Added ' . $this->user_label($user_id) . ' as ' . ($role === 'manager' ? 'Podcast Manager' : 'Podcast Member') . '.');
    $this->sync_podcast_source_files($podcast_id, []);
    $this->redirect_with_notice($podcast_id, 'Podcast team updated.', 'success');
  }

  public function handle_remove_member(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();

    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $role = sanitize_key((string)($_POST['role'] ?? 'member'));
    if ($podcast_id <= 0 || $user_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');

    $manager_ids = $this->explicit_manager_user_ids($podcast_id);
    $member_ids = $this->explicit_member_user_ids($podcast_id);

    if ($role === 'manager') {
      $manager_ids = array_values(array_filter($manager_ids, static fn($manager_id) => (int)$manager_id !== $user_id));
      if (empty($manager_ids)) $this->redirect_with_notice($podcast_id, 'A podcast must keep at least one Podcast Manager.', 'error');
    } else {
      $member_ids = array_values(array_filter($member_ids, static fn($member_id) => (int)$member_id !== $user_id));
    }

    $this->save_podcast_team($podcast_id, $manager_ids, $member_ids);
    $this->push_activity($podcast_id, $actor_user_id, 'Removed ' . $this->user_label($user_id) . ' from the podcast team.');
    $this->sync_podcast_source_files($podcast_id, []);
    $this->redirect_with_notice($podcast_id, 'Podcast team updated.', 'success');
  }

  public function handle_add_episode(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();

    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');

    $episode_title = sanitize_text_field((string)($_POST['episode_title'] ?? ''));
    if ($episode_title === '') $this->redirect_with_notice($podcast_id, 'Episode title is required.', 'error');

    $status = $this->sanitize_episode_status((string)($_POST['episode_status'] ?? 'draft'));
    $audio_attachment_id = $this->handle_media_upload('audio_upload', $podcast_id, false);
    $video_attachment_id = $this->handle_media_upload('video_upload', $podcast_id, false);
    $audio_url = esc_url_raw((string)($_POST['audio_url'] ?? ''));
    $video_url = esc_url_raw((string)($_POST['video_url'] ?? ''));
    if ($status === 'published' && $audio_attachment_id <= 0 && $video_attachment_id <= 0 && $audio_url === '' && $video_url === '') {
      $this->redirect_with_notice($podcast_id, 'Published episodes need audio or video media.', 'error');
    }

    $episode_id = (int)wp_insert_post([
      'post_type' => self::CPT_EPISODE,
      'post_status' => 'publish',
      'post_title' => $episode_title,
      'post_author' => $actor_user_id,
    ]);
    if ($episode_id <= 0) $this->redirect_with_notice($podcast_id, 'Could not create the episode.', 'error');

    $this->save_episode_meta($episode_id, $podcast_id, $audio_attachment_id, $video_attachment_id, false);
    $this->push_activity($podcast_id, $actor_user_id, 'Added episode "' . $episode_title . '".');
    $this->sync_podcast_source_files($podcast_id, [$episode_id]);
    $this->redirect_with_notice($podcast_id, 'Episode created.', 'success');
  }

  public function handle_save_episode(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();

    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    $episode_id = isset($_POST['episode_id']) ? (int)$_POST['episode_id'] : 0;
    if ($podcast_id <= 0 || $episode_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');

    $episode_title = sanitize_text_field((string)($_POST['episode_title'] ?? ''));
    if ($episode_title === '') $this->redirect_with_notice($podcast_id, 'Episode title is required.', 'error');

    wp_update_post([
      'ID' => $episode_id,
      'post_title' => $episode_title,
    ]);

    $audio_attachment_id = $this->handle_media_upload('audio_upload', $podcast_id, false);
    $video_attachment_id = $this->handle_media_upload('video_upload', $podcast_id, false);
    $this->save_episode_meta($episode_id, $podcast_id, $audio_attachment_id, $video_attachment_id, true);
    $this->push_activity($podcast_id, $actor_user_id, 'Updated episode "' . $episode_title . '".');
    $this->sync_podcast_source_files($podcast_id, [$episode_id]);
    $this->redirect_with_notice($podcast_id, 'Episode saved.', 'success');
  }

  public function handle_add_expense(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();

    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');

    $title = sanitize_text_field((string)($_POST['expense_title'] ?? ''));
    $amount = round((float)($_POST['expense_amount'] ?? 0), 2);
    if ($title === '' || $amount <= 0) $this->redirect_with_notice($podcast_id, 'Expense title and amount are required.', 'error');

    $rows = $this->podcast_expense_lines($podcast_id);
    $rows[] = [
      'title' => $title,
      'amount' => $amount,
      'occurred_on' => sanitize_text_field((string)($_POST['expense_occurred_on'] ?? current_time('Y-m-d'))),
      'finance_path_term_id' => max(0, (int)($_POST['expense_finance_path_term_id'] ?? 0)),
      'job_path_term_id' => max(0, (int)($_POST['expense_job_path_term_id'] ?? 0)),
      'notes' => sanitize_textarea_field((string)($_POST['expense_notes'] ?? '')),
      'submitted_by' => $actor_user_id,
      'created_at' => current_time('mysql'),
    ];
    update_post_meta($podcast_id, self::META_EXPENSE_LINES, $rows);
    $this->push_activity($podcast_id, $actor_user_id, 'Logged expense "' . $title . '".');
    $this->sync_podcast_source_files($podcast_id, []);
    $this->redirect_with_notice($podcast_id, 'Podcast expense added.', 'success');
  }

  public function handle_export_episode_xml(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();
    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    $episode_id = isset($_POST['episode_id']) ? (int)$_POST['episode_id'] : 0;
    if ($podcast_id <= 0 || $episode_id <= 0 || !$this->can_view_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');
    $episode = $this->episode_row($episode_id);
    if ((int)($episode['podcast_id'] ?? 0) !== $podcast_id) wp_die('Not allowed.');
    $xml = $this->build_episode_exchange_xml($episode);
    $this->send_download('podcast-episode-' . $episode_id . '.xml', 'application/xml; charset=' . get_option('blog_charset'), $xml);
  }

  public function handle_export_episodes_csv(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();
    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_view_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');
    $this->send_download('podcast-episodes-' . $podcast_id . '.csv', 'text/csv; charset=' . get_option('blog_charset'), $this->build_episode_exchange_csv($podcast_id));
  }

  public function handle_export_podcast_xml(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();
    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_view_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');
    $this->send_download('podcast-' . $podcast_id . '.xml', 'application/xml; charset=' . get_option('blog_charset'), $this->build_podcast_exchange_xml($podcast_id));
  }

  public function handle_import_episode_xml(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();
    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');
    $xml = $this->uploaded_file_contents('import_episode_xml');
    if ($xml === '') $this->redirect_with_notice($podcast_id, 'Episode XML upload is required.', 'error');
    $parsed = $this->parse_episode_exchange_xml($xml);
    if (empty($parsed['title'])) $this->redirect_with_notice($podcast_id, 'Episode XML could not be read.', 'error');
    $episode_id = $this->create_imported_episode($podcast_id, $actor_user_id, $parsed);
    if ($episode_id > 0) $this->sync_podcast_source_files($podcast_id, [$episode_id]);
    $this->redirect_with_notice($podcast_id, 'Episode XML imported.', 'success');
  }

  public function handle_import_episodes_csv(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();
    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');
    $csv = $this->uploaded_file_contents('import_episodes_csv');
    if ($csv === '') $this->redirect_with_notice($podcast_id, 'Episodes CSV upload is required.', 'error');
    $rows = $this->parse_episode_exchange_csv($csv);
    if (empty($rows)) $this->redirect_with_notice($podcast_id, 'No valid episodes were found in the CSV.', 'error');
    $episode_ids = [];
    foreach ($rows as $row) {
      $episode_id = $this->create_imported_episode($podcast_id, $actor_user_id, $row);
      if ($episode_id > 0) $episode_ids[] = $episode_id;
    }
    $this->sync_podcast_source_files($podcast_id, $episode_ids);
    $this->redirect_with_notice($podcast_id, 'Episodes CSV imported.', 'success');
  }

  public function handle_import_podcast_xml(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();
    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');
    $xml = $this->uploaded_file_contents('import_podcast_xml');
    if ($xml === '') $this->redirect_with_notice($podcast_id, 'Podcast XML upload is required.', 'error');
    $parsed = $this->parse_podcast_exchange_xml($xml);
    if (empty($parsed['podcast']) && empty($parsed['episodes'])) {
      $this->redirect_with_notice($podcast_id, 'Podcast XML could not be read.', 'error');
    }
    if (!empty($parsed['podcast'])) {
      $this->import_podcast_payload_into_existing_podcast($podcast_id, $parsed['podcast']);
    }
    foreach ((array)($parsed['episodes'] ?? []) as $episode) {
      $this->create_imported_episode($podcast_id, $actor_user_id, (array)$episode);
    }
    $this->push_activity($podcast_id, $actor_user_id, 'Imported podcast XML package.');
    $this->sync_podcast_source_files($podcast_id, null);
    $this->redirect_with_notice($podcast_id, 'Podcast XML imported.', 'success');
  }

  public function handle_import_rss(): void {
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();

    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');

    $rss_url = esc_url_raw((string)($_POST['rss_import_url'] ?? ''));
    $xml = '';
    if (!empty($_FILES['rss_import_file']) && !empty($_FILES['rss_import_file']['tmp_name'])) {
      $xml = $this->uploaded_file_contents('rss_import_file');
    } elseif ($rss_url !== '') {
      $response = wp_remote_get($rss_url, [
        'timeout' => 20,
        'redirection' => 5,
        'user-agent' => 'EUM Podcasts RSS Import/' . (defined('EUM_VERSION') ? EUM_VERSION : '1.0'),
      ]);
      if (is_wp_error($response)) {
        $this->redirect_with_notice($podcast_id, 'RSS import failed: ' . $response->get_error_message(), 'error');
      }
      $code = (int)wp_remote_retrieve_response_code($response);
      if ($code < 200 || $code >= 300) {
        $this->redirect_with_notice($podcast_id, 'RSS import failed with HTTP ' . $code . '.', 'error');
      }
      $xml = (string)wp_remote_retrieve_body($response);
    }

    if (trim($xml) === '') {
      $this->redirect_with_notice($podcast_id, 'Provide an RSS feed URL or upload an RSS/XML file.', 'error');
    }

    $default_status = $this->sanitize_episode_status((string)($_POST['rss_import_status'] ?? 'draft'));
    if ($default_status === 'archived') $default_status = 'draft';
    $parsed = $this->parse_rss_import_xml($xml, $default_status, $rss_url);
    if (empty($parsed['episodes'])) {
      $this->redirect_with_notice($podcast_id, 'No valid RSS episodes were found.', 'error');
    }

    if (!empty($_POST['rss_update_podcast_details']) && !empty($parsed['podcast'])) {
      $this->import_rss_channel_into_existing_podcast($podcast_id, (array)$parsed['podcast']);
    }

    $created = 0;
    $skipped = 0;
    $created_ids = [];
    foreach ((array)$parsed['episodes'] as $episode) {
      $guid = sanitize_text_field((string)($episode['guid'] ?? ''));
      if ($guid !== '' && $this->episode_guid_exists($podcast_id, $guid)) {
        $skipped++;
        continue;
      }
      $episode_id = $this->create_imported_episode($podcast_id, $actor_user_id, (array)$episode);
      if ($episode_id > 0) {
        $created++;
        $created_ids[] = $episode_id;
      }
    }

    $this->push_activity($podcast_id, $actor_user_id, 'Imported RSS feed: ' . $created . ' episode(s) created, ' . $skipped . ' skipped.');
    if ($created > 0 || !empty($_POST['rss_update_podcast_details'])) $this->sync_podcast_source_files($podcast_id, $created_ids);
    $this->redirect_with_notice($podcast_id, 'RSS import complete: ' . $created . ' episode(s) imported' . ($skipped > 0 ? ', ' . $skipped . ' skipped' : '') . '.', 'success');
  }

  public function handle_import_podcast_zip(): void {
    if (function_exists('set_time_limit')) @set_time_limit(600);
    $actor_user_id = (int)get_current_user_id();
    $this->verify_nonce();
    $podcast_id = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
    if ($podcast_id <= 0 || !$this->can_manage_podcast($podcast_id, $actor_user_id)) wp_die('Not allowed.');
    if (!class_exists('ZipArchive')) $this->redirect_with_notice($podcast_id, 'ZIP import is unavailable because PHP ZipArchive is not enabled on this server.', 'error');

    $upload = $_FILES['podcast_zip'] ?? [];
    if (empty($upload['tmp_name']) || !is_uploaded_file((string)$upload['tmp_name']) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $this->redirect_with_notice($podcast_id, 'Choose a valid podcast ZIP file. The server upload limit may also need to be increased for large audio archives.', 'error');
    }

    $zip = new ZipArchive();
    $opened = $zip->open((string)$upload['tmp_name'], ZipArchive::RDONLY);
    if ($opened !== true) $this->redirect_with_notice($podcast_id, 'The uploaded file could not be opened as a ZIP archive.', 'error');

    $audio_files = [];
    $episode_xml_entries = [];
    $feed_candidates = [];
    $uncompressed_bytes = 0;
    $xml_bytes = 0;
    $entry_count = (int)$zip->numFiles;
    if ($entry_count > 300) {
      $zip->close();
      $this->redirect_with_notice($podcast_id, 'The ZIP contains too many files. Keep the archive under 300 entries.', 'error');
    }

    for ($index = 0; $index < $entry_count; $index++) {
      $stat = $zip->statIndex($index);
      $entry_name = $zip->getNameIndex($index);
      if (!is_array($stat) || !is_string($entry_name) || $entry_name === '' || substr($entry_name, -1) === '/') continue;
      $size = max(0, (int)($stat['size'] ?? 0));
      $uncompressed_bytes += $size;
      if ($uncompressed_bytes > 2 * 1024 * 1024 * 1024) {
        $zip->close();
        $this->redirect_with_notice($podcast_id, 'The ZIP expands beyond the 2 GB import limit.', 'error');
      }

      $basename = basename(str_replace('\\', '/', $entry_name));
      $extension = strtolower((string)pathinfo($basename, PATHINFO_EXTENSION));
      if ($extension === 'mp3') {
        $key = $this->podcast_archive_reference_filename($basename);
        if (!isset($audio_files[$key])) $audio_files[$key] = ['entry' => $entry_name, 'size' => $size];
        else $audio_files[$key] = ['ambiguous' => true, 'entry' => '', 'size' => 0];
        continue;
      }
      if (!in_array($extension, ['xml', 'rss'], true) || $size <= 0) continue;
      if ($size > 5 * 1024 * 1024) continue;
      $xml_bytes += $size;
      if ($xml_bytes > 25 * 1024 * 1024) {
        $zip->close();
        $this->redirect_with_notice($podcast_id, 'The combined XML files in the ZIP exceed the 25 MB import limit.', 'error');
      }

      $xml = $zip->getFromIndex($index);
      if (!is_string($xml) || $xml === '') continue;
      $root = $this->load_podcast_xml($xml);
      if (!$root) continue;
      $root_name = strtolower((string)$root->getName());
      if ($root_name === 'rss' && isset($root->channel)) {
        $feed_candidates[] = ['name' => $basename, 'xml' => $xml];
      } elseif (in_array($root_name, ['podcastgenerator', 'episode'], true)) {
        $episode_xml_entries[] = ['name' => $basename, 'xml' => $xml];
      }
    }
    if (empty($feed_candidates)) {
      $zip->close();
      $this->redirect_with_notice($podcast_id, 'The ZIP must include an RSS feed XML file with a channel and episode entries.', 'error');
    }
    $preferred_feeds = array_values(array_filter($feed_candidates, static fn($candidate) => strtolower((string)$candidate['name']) === 'feed.xml'));
    if (count($preferred_feeds) > 1 || (empty($preferred_feeds) && count($feed_candidates) > 1)) {
      $zip->close();
      $this->redirect_with_notice($podcast_id, 'The ZIP has multiple feed XML files and none can be selected unambiguously. Include one feed.xml.', 'error');
    }
    $feed_xml = (string)(!empty($preferred_feeds) ? $preferred_feeds[0]['xml'] : $feed_candidates[0]['xml']);
    $import_status = $this->sanitize_episode_status((string)wp_unslash($_POST['zip_import_status'] ?? 'draft'));
    if ($import_status === 'archived') $import_status = 'draft';
    $feed_payload = $this->parse_rss_import_xml($feed_xml, $import_status);
    if (empty($feed_payload['podcast']) || empty($feed_payload['episodes'])) {
      $zip->close();
      $this->redirect_with_notice($podcast_id, 'The feed XML did not contain valid podcast details and episodes.', 'error');
    }

    $feed_by_audio = [];
    $feed_by_guid = [];
    $feed_by_title = [];
    foreach ((array)$feed_payload['episodes'] as $feed_episode) {
      $filename = $this->podcast_archive_reference_filename((string)($feed_episode['audio_url'] ?? ''));
      if ($filename === '') $filename = $this->podcast_archive_reference_filename((string)($feed_episode['guid'] ?? ''));
      if ($filename !== '') $feed_by_audio[$filename] = $feed_episode;
      $feed_guid = sanitize_text_field((string)($feed_episode['guid'] ?? ''));
      if ($feed_guid !== '') $feed_by_guid[$feed_guid] = $feed_episode;
      $feed_title = strtolower(trim((string)($feed_episode['title'] ?? '')));
      if ($feed_title !== '') $feed_by_title[$feed_title] = $feed_episode;
    }

    $import_rows = [];
    $import_errors = [];
    $matched_feed_guids = [];
    foreach ($episode_xml_entries as $xml_entry) {
      $episode = $this->parse_archive_episode_xml((string)$xml_entry['xml']);
      if (empty($episode['title'])) continue;
      $filename = (string)($episode['media_filename'] ?? '');
      if ($filename === '' || !isset($audio_files[$filename])) {
        $xml_stem = $this->podcast_archive_reference_filename(pathinfo((string)$xml_entry['name'], PATHINFO_FILENAME) . '.mp3');
        if (isset($audio_files[$xml_stem])) $filename = $xml_stem;
      }
      if (!isset($audio_files[$filename])) {
        $import_errors[] = 'Missing MP3 for ' . sanitize_file_name((string)$xml_entry['name']);
        continue;
      }
      if (!empty($audio_files[$filename]['ambiguous'])) {
        $import_errors[] = 'More than one MP3 has the name ' . sanitize_file_name($filename);
        continue;
      }
      if (isset($import_rows[$filename])) {
        $import_errors[] = 'More than one episode XML refers to ' . sanitize_file_name($filename);
        continue;
      }
      $episode_guid = sanitize_text_field((string)($episode['guid'] ?? ''));
      $episode_title = strtolower(trim((string)($episode['title'] ?? '')));
      $feed_episode = (array)($feed_by_audio[$filename] ?? ($feed_by_guid[$episode_guid] ?? ($feed_by_title[$episode_title] ?? [])));
      $matched_feed_guid = sanitize_text_field((string)($feed_episode['guid'] ?? ''));
      if ($matched_feed_guid !== '') $matched_feed_guids[$matched_feed_guid] = true;
      $merged_episode = array_merge($feed_episode, $episode);
      foreach (['publish_at', 'duration', 'summary', 'notes'] as $fallback_key) {
        if (trim((string)($merged_episode[$fallback_key] ?? '')) === '' && trim((string)($feed_episode[$fallback_key] ?? '')) !== '') {
          $merged_episode[$fallback_key] = $feed_episode[$fallback_key];
        }
      }
      if (!empty($feed_episode['guid'])) $merged_episode['guid'] = $feed_episode['guid'];
      if (empty($merged_episode['source_links']) && !empty($feed_episode['source_links'])) $merged_episode['source_links'] = $feed_episode['source_links'];
      $import_rows[$filename] = array_merge($merged_episode, [
        'status' => $import_status,
        'audio_url' => '',
        'archive_audio_entry' => (string)$audio_files[$filename]['entry'],
        'archive_audio_size' => (int)$audio_files[$filename]['size'],
      ]);
    }

    // Import feed-only episodes as well, so an archive remains complete if an episode XML is missing.
    foreach ($feed_by_audio as $filename => $feed_episode) {
      $feed_guid = sanitize_text_field((string)($feed_episode['guid'] ?? ''));
      if ($feed_guid !== '' && isset($matched_feed_guids[$feed_guid])) continue;
      if (isset($import_rows[$filename])) continue;
      if (!isset($audio_files[$filename])) {
        $import_errors[] = 'Missing MP3 for ' . sanitize_file_name($filename);
        continue;
      }
      if (!empty($audio_files[$filename]['ambiguous'])) {
        $import_errors[] = 'More than one MP3 has the name ' . sanitize_file_name($filename);
        continue;
      }
      $import_rows[$filename] = array_merge((array)$feed_episode, [
        'status' => $import_status,
        'audio_url' => '',
        'archive_audio_entry' => (string)$audio_files[$filename]['entry'],
        'archive_audio_size' => (int)$audio_files[$filename]['size'],
      ]);
    }

    if (empty($import_rows)) {
      $detail = !empty($import_errors) ? ' ' . implode('; ', array_slice($import_errors, 0, 3)) : '';
      $zip->close();
      $this->redirect_with_notice($podcast_id, 'No episode XML or feed entries matched MP3 files in the ZIP.' . $detail, 'error');
    }

    $created = 0;
    $updated = 0;
    $changed_episode_ids = [];
    foreach ($import_rows as $filename => $episode_payload) {
      $guid = sanitize_text_field((string)($episode_payload['guid'] ?? ''));
      $existing_episode_id = $guid !== '' ? $this->episode_id_for_guid($podcast_id, $guid) : 0;
      $audio_attachment_id = $existing_episode_id > 0
        ? $this->existing_episode_audio_attachment($existing_episode_id, (string)$filename)
        : 0;
      $new_audio_attachment = false;
      if ($audio_attachment_id <= 0) {
        $uploaded_audio = $this->sideload_podcast_zip_audio(
          $zip,
          (string)$episode_payload['archive_audio_entry'],
          $podcast_id,
          (int)$episode_payload['archive_audio_size']
        );
        if (is_wp_error($uploaded_audio)) {
          $import_errors[] = sanitize_file_name((string)$filename) . ': ' . $uploaded_audio->get_error_message();
          continue;
        }
        $audio_attachment_id = (int)$uploaded_audio;
        $new_audio_attachment = true;
      }

      $episode_payload['audio_attachment_id'] = $audio_attachment_id;
      $episode_payload['audio_url'] = '';
      $episode_payload['guid'] = $guid !== '' ? $guid : 'zip-import-' . md5((string)$filename);
      $episode_id = $this->create_imported_episode($podcast_id, $actor_user_id, $episode_payload, $existing_episode_id);
      if ($episode_id <= 0) {
        if ($new_audio_attachment) wp_delete_attachment($audio_attachment_id, true);
        $import_errors[] = sanitize_file_name((string)$filename) . ': episode could not be saved';
        continue;
      }
      $changed_episode_ids[] = $episode_id;
      if ($existing_episode_id > 0) $updated++;
      else $created++;
    }

    $zip->close();

    if ($created + $updated > 0) {
      $this->import_rss_channel_into_existing_podcast($podcast_id, (array)$feed_payload['podcast']);
      $this->push_activity($podcast_id, $actor_user_id, 'Imported podcast ZIP: ' . $created . ' new episode(s), ' . $updated . ' updated.');
      $this->sync_podcast_source_files($podcast_id, $changed_episode_ids);
    }
    if ($created + $updated === 0) {
      $detail = !empty($import_errors) ? ' ' . implode('; ', array_slice($import_errors, 0, 3)) : '';
      $this->redirect_with_notice($podcast_id, 'No episodes were imported.' . $detail, 'error');
    }
    $message = sprintf('Podcast ZIP import complete: %d new episode(s), %d updated.', $created, $updated);
    if (!empty($import_errors)) $message .= ' Some entries were skipped: ' . implode('; ', array_slice($import_errors, 0, 3));
    $this->redirect_with_notice($podcast_id, $message, 'success');
  }

  private function podcast_import_directory(int $podcast_id, bool $create = false): string {
    if ($podcast_id <= 0) return '';
    $uploads = wp_upload_dir();
    if (!empty($uploads['error']) || empty($uploads['basedir'])) return '';
    $root = trailingslashit((string)$uploads['basedir']) . 'eum-podcast-imports';
    $directory = trailingslashit($root) . $podcast_id;
    if ($create) {
      if (!is_dir($directory) && !wp_mkdir_p($directory)) return '';
      if (!file_exists($root . '/index.php')) @file_put_contents($root . '/index.php', "<?php\n// Silence is golden.\n");
      if (!file_exists($root . '/.htaccess')) {
        @file_put_contents($root . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
      }
      if (!file_exists($root . '/web.config')) {
        @file_put_contents($root . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><directoryBrowse enabled=\"false\"/><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>");
      }
    }
    return is_dir($directory) && is_writable($directory) ? $directory : '';
  }

  private function podcast_folder_import_transient_key(int $actor_user_id, int $podcast_id): string {
    return 'eum_podcast_folder_import_' . $actor_user_id . '_' . $podcast_id;
  }

  private function write_podcast_import_file(string $directory, string $filename, string $contents): bool {
    $filename = sanitize_file_name($filename);
    if ($directory === '' || $filename === '' || basename($filename) !== $filename) return false;
    $target = trailingslashit($directory) . $filename;
    if (is_link($target)) return false;
    $temp = $target . '.tmp-' . wp_generate_password(8, false, false);
    $written = @file_put_contents($temp, $contents, LOCK_EX);
    if (!is_int($written) || $written !== strlen($contents)) {
      if (file_exists($temp)) @unlink($temp);
      return false;
    }
    if (!@rename($temp, $target)) {
      @unlink($temp);
      return false;
    }
    return true;
  }

  private function copy_podcast_source_file(string $source, string $directory, string $filename): bool {
    $filename = sanitize_file_name($filename);
    if ($source === '' || !is_file($source) || $directory === '' || $filename === '') return false;
    $target = trailingslashit($directory) . $filename;
    if (is_link($target)) return false;
    if (realpath($source) === realpath($target)) return true;
    $temp = $target . '.tmp-' . wp_generate_password(8, false, false);
    if (!@copy($source, $temp)) {
      @unlink($temp);
      return false;
    }
    if (!@rename($temp, $target)) {
      @unlink($temp);
      return false;
    }
    return true;
  }

  private function sync_podcast_source_files(int $podcast_id, ?array $episode_ids = [], bool $sync_media = true): void {
    $directory = $this->podcast_import_directory($podcast_id, true);
    if ($directory === '') return;

    $settings = $this->podcast_settings($podcast_id);
    $cover_path = $settings['cover_attachment_id'] > 0 ? get_attached_file((int)$settings['cover_attachment_id']) : '';
    if ($sync_media && $cover_path && is_file($cover_path)) $this->copy_podcast_source_file((string)$cover_path, $directory, basename((string)$cover_path));

    $feed_xml = $this->build_feed_xml($podcast_id, 'audio');
    if ($feed_xml !== '') $this->write_podcast_import_file($directory, 'feed.xml', $feed_xml);
    $package_xml = $this->build_podcast_exchange_xml($podcast_id);
    if ($package_xml !== '') $this->write_podcast_import_file($directory, 'podcast.xml', $package_xml);

    if ($episode_ids === null) {
      $episode_ids = array_map(static fn($row) => (int)($row['ID'] ?? 0), $this->podcast_episode_rows($podcast_id, true));
    }
    foreach (array_values(array_unique(array_filter(array_map('intval', $episode_ids)))) as $episode_id) {
      $episode = $this->episode_row($episode_id);
      if ((int)($episode['podcast_id'] ?? 0) !== $podcast_id || empty($episode['post'])) continue;
      $audio_attachment_id = (int)($episode['audio_attachment_id'] ?? 0);
      if ($sync_media && $audio_attachment_id > 0) {
        $source = get_attached_file($audio_attachment_id);
        if ($source && is_file($source)) {
          $audio_name = sanitize_file_name(basename($source));
          if ($audio_name !== '' && $this->copy_podcast_source_file((string)$source, $directory, $audio_name)) $episode['audio_url'] = $audio_name;
        }
      }
      $episode_xml = $this->build_episode_exchange_xml($episode);
      if ($episode_xml !== '') $this->write_podcast_import_file($directory, 'episode-' . $episode_id . '.xml', $episode_xml);
    }
  }

  private function prepare_podcast_feed_publish_dates(string $directory, int $podcast_id) {
    $feed_path = $this->podcast_import_child_file($directory, 'feed.xml');
    if ($feed_path === '') return new WP_Error('eum_podcast_feed_dates_missing', 'Put the RSS source file in this podcast folder and name it feed.xml.');
    $feed_size = (int)filesize($feed_path);
    if ($feed_size <= 0 || $feed_size > 5 * 1024 * 1024) return new WP_Error('eum_podcast_feed_dates_size', 'The RSS feed must be between 1 byte and 5 MB.');
    $feed_xml = file_get_contents($feed_path);
    if (!is_string($feed_xml) || $feed_xml === '') return new WP_Error('eum_podcast_feed_dates_read', 'The RSS source file could not be read.');
    $feed = $this->parse_rss_import_xml($feed_xml, 'draft');
    if (empty($feed['episodes'])) return new WP_Error('eum_podcast_feed_dates_invalid', 'The RSS file has no readable episode entries or publish dates.');

    $episodes = [];
    $unmatched = 0;
    $without_date = 0;
    foreach ((array)$feed['episodes'] as $feed_episode) {
      $publish_at = sanitize_text_field((string)($feed_episode['publish_at'] ?? ''));
      if ($publish_at === '') {
        $without_date++;
        continue;
      }
      $guid = sanitize_text_field((string)($feed_episode['guid'] ?? ''));
      $episode_id = $guid !== '' ? $this->episode_id_for_guid($podcast_id, $guid) : 0;
      if ($episode_id <= 0) {
        $episode_id = $this->episode_id_for_audio_filename($podcast_id, (string)($feed_episode['audio_url'] ?? ''));
      }
      if ($episode_id <= 0) {
        $unmatched++;
        continue;
      }
      $episodes[] = [
        'episode_id' => $episode_id,
        'title' => sanitize_text_field((string)($feed_episode['title'] ?? 'Episode')),
        'publish_at' => $publish_at,
      ];
    }
    return [
      'episodes' => $episodes,
      'unmatched' => $unmatched,
      'without_date' => $without_date,
    ];
  }

  private function episode_id_for_audio_filename(int $podcast_id, string $audio_reference): int {
    $filename = $this->podcast_archive_reference_filename($audio_reference);
    if ($podcast_id <= 0 || $filename === '') return 0;
    $matches = [];
    foreach ($this->podcast_episode_rows($podcast_id, true) as $episode) {
      $source = '';
      $attachment_id = (int)($episode['audio_attachment_id'] ?? 0);
      if ($attachment_id > 0) {
        $path = get_attached_file($attachment_id);
        if ($path) $source = basename((string)$path);
      }
      if ($source === '') $source = (string)($episode['audio_url'] ?? '');
      if ($this->podcast_archive_reference_filename($source) === $filename) $matches[] = (int)($episode['ID'] ?? 0);
    }
    $matches = array_values(array_unique(array_filter($matches)));
    return count($matches) === 1 ? (int)$matches[0] : 0;
  }

  private function finish_podcast_publish_date_import(array $state): string {
    $podcast_id = (int)($state['podcast_id'] ?? 0);
    $actor_user_id = (int)($state['actor_user_id'] ?? 0);
    $updated = (int)($state['updated'] ?? 0);
    $changed_episode_ids = array_values(array_unique(array_filter(array_map('intval', (array)($state['changed_episode_ids'] ?? [])))));
    if ($podcast_id > 0 && $updated > 0) {
      $this->push_activity($podcast_id, $actor_user_id, 'Updated publish times from the RSS feed for ' . $updated . ' episode(s).');
      $this->sync_podcast_source_files($podcast_id, $changed_episode_ids, false);
    }
    return sprintf(
      'Feed publish-time update complete: %d episode(s) updated, %d feed item(s) unmatched, %d item(s) without a publish date. Audio sources were preserved.',
      $updated,
      (int)($state['unmatched'] ?? 0),
      (int)($state['without_date'] ?? 0)
    );
  }

  public function ajax_folder_import_start(): void {
    $actor_user_id = (int)get_current_user_id();
    $podcast_id = max(0, (int)($_POST['podcast_id'] ?? 0));
    if ($podcast_id <= 0 || !wp_verify_nonce((string)($_POST['nonce'] ?? ''), 'eum_podcast_folder_import_' . $podcast_id)) {
      wp_send_json_error(['message' => 'Security check failed. Reload the podcast page and try again.'], 403);
    }
    if (!$this->can_manage_podcast($podcast_id, $actor_user_id)) {
      wp_send_json_error(['message' => 'You cannot import files for this podcast.'], 403);
    }

    $directory = $this->podcast_import_directory($podcast_id, false);
    if ($directory === '') wp_send_json_error(['message' => 'The podcast import folder is missing or is not writable. Reload the page to create it, then upload the source files.']);
    $mode = sanitize_key((string)($_POST['mode'] ?? 'full'));
    if ($mode === 'publish_dates') {
      $prepared = $this->prepare_podcast_feed_publish_dates($directory, $podcast_id);
      if (is_wp_error($prepared)) wp_send_json_error(['message' => $prepared->get_error_message()]);
      $state = [
        'mode' => 'publish_dates',
        'actor_user_id' => $actor_user_id,
        'podcast_id' => $podcast_id,
        'directory' => $directory,
        'episodes' => (array)$prepared['episodes'],
        'index' => 0,
        'updated' => 0,
        'unmatched' => (int)($prepared['unmatched'] ?? 0),
        'without_date' => (int)($prepared['without_date'] ?? 0),
        'changed_episode_ids' => [],
      ];
      $date_transient_key = $this->podcast_folder_import_transient_key($actor_user_id, $podcast_id);
      set_transient($date_transient_key, $state, 4 * HOUR_IN_SECONDS);
      if (empty($state['episodes'])) {
        $message = $this->finish_podcast_publish_date_import($state);
        delete_transient($date_transient_key);
        wp_send_json_success(['complete' => true, 'total' => 0, 'message' => $message]);
      }
      wp_send_json_success(['total' => count($state['episodes']), 'message' => 'Found ' . count($state['episodes']) . ' existing episode(s) with feed publish dates. Updating dates only; audio sources will be preserved...']);
    }

    $prepared = $this->prepare_podcast_folder_import($directory, 'draft');
    if (is_wp_error($prepared)) wp_send_json_error(['message' => $prepared->get_error_message()]);

    $state = [
      'mode' => 'full',
      'actor_user_id' => $actor_user_id,
      'podcast_id' => $podcast_id,
      'directory' => $directory,
      'podcast' => (array)$prepared['podcast'],
      'podcast_package' => (array)($prepared['podcast_package'] ?? []),
      'episodes' => (array)$prepared['episodes'],
      'index' => 0,
      'created' => 0,
      'updated' => 0,
      'changed_episode_ids' => [],
      'errors' => (array)($prepared['errors'] ?? []),
    ];
    set_transient($this->podcast_folder_import_transient_key($actor_user_id, $podcast_id), $state, 4 * HOUR_IN_SECONDS);
    if (empty($state['episodes'])) {
      $message = $this->finish_podcast_folder_import($state);
      delete_transient($this->podcast_folder_import_transient_key($actor_user_id, $podcast_id));
      wp_send_json_success(['complete' => true, 'total' => 0, 'message' => $message]);
    }
    wp_send_json_success(['total' => count($state['episodes']), 'message' => 'Found ' . count($state['episodes']) . ' episode(s). Importing one at a time...']);
  }

  public function ajax_folder_import_next(): void {
    if (function_exists('set_time_limit')) @set_time_limit(180);
    $actor_user_id = (int)get_current_user_id();
    $podcast_id = max(0, (int)($_POST['podcast_id'] ?? 0));
    if ($podcast_id <= 0 || !wp_verify_nonce((string)($_POST['nonce'] ?? ''), 'eum_podcast_folder_import_' . $podcast_id)) {
      wp_send_json_error(['message' => 'Security check failed. Reload the podcast page and try again.'], 403);
    }
    if (!$this->can_manage_podcast($podcast_id, $actor_user_id)) {
      wp_send_json_error(['message' => 'You cannot import files for this podcast.'], 403);
    }

    $transient_key = $this->podcast_folder_import_transient_key($actor_user_id, $podcast_id);
    $state = get_transient($transient_key);
    if (!is_array($state) || (int)($state['actor_user_id'] ?? 0) !== $actor_user_id || (int)($state['podcast_id'] ?? 0) !== $podcast_id) {
      wp_send_json_error(['message' => 'The folder import session expired. Start the import again.']);
    }
    $episodes = (array)($state['episodes'] ?? []);
    $total = count($episodes);
    $index = max(0, (int)($state['index'] ?? 0));
    if ($index >= $total) {
      $message = (($state['mode'] ?? '') === 'publish_dates')
        ? $this->finish_podcast_publish_date_import($state)
        : $this->finish_podcast_folder_import($state);
      delete_transient($transient_key);
      wp_send_json_success(['complete' => true, 'processed' => $total, 'total' => $total, 'message' => $message]);
    }

    if (($state['mode'] ?? '') === 'publish_dates') {
      $date_episode = (array)$episodes[$index];
      $episode_id = (int)($date_episode['episode_id'] ?? 0);
      $current_episode = $episode_id > 0 ? $this->episode_row($episode_id) : [];
      $publish_at = sanitize_text_field((string)($date_episode['publish_at'] ?? ''));
      $last_result = '';
      if ((int)($current_episode['podcast_id'] ?? 0) !== $podcast_id || $publish_at === '') {
        $state['unmatched'] = (int)($state['unmatched'] ?? 0) + 1;
        $last_result = 'Skipped unmatched feed item ' . (string)($date_episode['title'] ?? '') . '.';
      } else {
        update_post_meta($episode_id, self::META_EP_PUBLISH_AT, $publish_at);
        $state['updated'] = (int)($state['updated'] ?? 0) + 1;
        $state['changed_episode_ids'][] = $episode_id;
        $last_result = 'Updated publish time for ' . (string)($date_episode['title'] ?? 'episode') . '.';
      }
      $state['index'] = $index + 1;
      $processed = (int)$state['index'];
      if ($processed >= $total) {
        $message = $this->finish_podcast_publish_date_import($state);
        delete_transient($transient_key);
        wp_send_json_success(['complete' => true, 'processed' => $processed, 'total' => $total, 'message' => $message]);
      }
      set_transient($transient_key, $state, 4 * HOUR_IN_SECONDS);
      wp_send_json_success([
        'complete' => false,
        'processed' => $processed,
        'total' => $total,
        'message' => $last_result . ' Processed ' . $processed . ' of ' . $total . ' feed entries... Audio sources are unchanged.',
      ]);
    }

    $episode = (array)$episodes[$index];
    $filename = sanitize_file_name((string)($episode['audio_source_name'] ?? ''));
    $directory = (string)($state['directory'] ?? '');
    $audio_path = $this->podcast_import_child_file($directory, $filename);
    $last_result = '';
    if ($audio_path === '') {
      $state['errors'][] = $filename . ': source MP3 is missing or no longer available';
      $last_result = 'Skipped ' . ($episode['title'] ?? $filename) . ': source MP3 is missing.';
    } else {
      $guid = sanitize_text_field((string)($episode['guid'] ?? ''));
      $existing_episode_id = $guid !== '' ? $this->episode_id_for_guid($podcast_id, $guid) : 0;
      $audio_attachment_id = $existing_episode_id > 0 ? $this->existing_episode_audio_attachment($existing_episode_id, $filename, $audio_path) : 0;
      $new_audio_attachment = false;
      if ($audio_attachment_id <= 0) {
        $uploaded_audio = $this->sideload_local_podcast_audio($audio_path, $filename, $podcast_id);
        if (is_wp_error($uploaded_audio)) {
          $state['errors'][] = $filename . ': episode XML matched, but its MP3 could not be added: ' . $uploaded_audio->get_error_message();
          $last_result = 'Matched episode XML for ' . ($episode['title'] ?? $filename) . ', but its MP3 could not be added; episode was not created.';
        } else {
          $audio_attachment_id = (int)$uploaded_audio;
          $new_audio_attachment = true;
        }
      }
      if ($audio_attachment_id > 0) {
        $episode['audio_attachment_id'] = $audio_attachment_id;
        $episode['audio_url'] = '';
        $episode['guid'] = $guid !== '' ? $guid : 'folder-import-' . md5($filename);
        $episode_id = $this->create_imported_episode($podcast_id, $actor_user_id, $episode, $existing_episode_id);
        if ($episode_id <= 0) {
          if ($new_audio_attachment) wp_delete_attachment($audio_attachment_id, true);
          $state['errors'][] = $filename . ': episode could not be saved';
          $last_result = 'Skipped ' . ($episode['title'] ?? $filename) . ': episode could not be saved.';
        } elseif ($existing_episode_id > 0) {
          $state['updated'] = (int)($state['updated'] ?? 0) + 1;
          $state['changed_episode_ids'][] = $episode_id;
          $last_result = 'Updated ' . ($episode['title'] ?? $filename) . '.';
        } else {
          $state['created'] = (int)($state['created'] ?? 0) + 1;
          $state['changed_episode_ids'][] = $episode_id;
          $last_result = 'Imported ' . ($episode['title'] ?? $filename) . '.';
        }
      }
    }

    $state['index'] = $index + 1;
    $processed = (int)$state['index'];
    if ($processed >= $total) {
      $message = $this->finish_podcast_folder_import($state);
      delete_transient($transient_key);
      wp_send_json_success(['complete' => true, 'processed' => $processed, 'total' => $total, 'message' => $message]);
    }
    set_transient($transient_key, $state, 4 * HOUR_IN_SECONDS);
    wp_send_json_success([
      'complete' => false,
      'processed' => $processed,
      'total' => $total,
      'message' => $last_result . ' Processed ' . $processed . ' of ' . $total . ' episodes...',
    ]);
  }

  private function prepare_podcast_folder_import(string $directory, string $status) {
    $real_directory = realpath($directory);
    if ($real_directory === false || !is_dir($real_directory)) return new WP_Error('eum_podcast_folder_missing', 'The podcast import folder could not be read.');
    $names = scandir($real_directory);
    if (!is_array($names) || count($names) > 300) return new WP_Error('eum_podcast_folder_entries', 'Keep the import folder under 300 files.');

    $audio_files = [];
    $episode_xml_entries = [];
    $feed_candidates = [];
    $podcast_package_candidates = [];
    $total_bytes = 0;
    $xml_bytes = 0;
    foreach ($names as $name) {
      if ($name === '.' || $name === '..') continue;
      $path = $this->podcast_import_child_file($real_directory, (string)$name);
      if ($path === '') continue;
      $size = (int)filesize($path);
      $total_bytes += $size;
      if ($total_bytes > 2 * 1024 * 1024 * 1024) return new WP_Error('eum_podcast_folder_size', 'The files in the import folder exceed the 2 GB limit.');
      $extension = strtolower((string)pathinfo((string)$name, PATHINFO_EXTENSION));
      if ($extension === 'mp3') {
        $key = $this->podcast_archive_reference_filename((string)$name);
        if (!isset($audio_files[$key])) $audio_files[$key] = ['name' => (string)$name, 'size' => $size];
        else $audio_files[$key] = ['ambiguous' => true, 'name' => '', 'size' => 0];
        continue;
      }
      if (!in_array($extension, ['xml', 'rss'], true) || $size <= 0) continue;
      if ($size > 5 * 1024 * 1024) return new WP_Error('eum_podcast_folder_xml_size', 'Each XML file must be 5 MB or smaller.');
      $xml_bytes += $size;
      if ($xml_bytes > 25 * 1024 * 1024) return new WP_Error('eum_podcast_folder_xml_total', 'The combined XML files exceed the 25 MB limit.');
      $xml = file_get_contents($path);
      if (!is_string($xml) || $xml === '') continue;
      $root = $this->load_podcast_xml($xml);
      if (!$root) continue;
      $root_name = strtolower((string)$root->getName());
      if (($root_name === 'rss' && isset($root->channel)) || $root_name === 'channel') {
        $feed_candidates[] = ['name' => basename((string)$name), 'xml' => $xml];
      } elseif ($root_name === 'podcast') {
        $podcast_package_candidates[] = ['name' => basename((string)$name), 'xml' => $xml];
      } elseif (in_array($root_name, ['podcastgenerator', 'episode'], true)) {
        $episode_xml_entries[] = ['name' => basename((string)$name), 'xml' => $xml];
      }
    }

    $feed_payload = ['podcast' => [], 'episodes' => []];
    if (!empty($feed_candidates)) {
      $preferred_feeds = array_values(array_filter($feed_candidates, static fn($candidate) => strtolower((string)$candidate['name']) === 'feed.xml'));
      if (count($preferred_feeds) > 1 || (empty($preferred_feeds) && count($feed_candidates) > 1)) {
        return new WP_Error('eum_podcast_folder_multiple_feeds', 'The folder has multiple RSS source files. Keep one feed.xml in the import folder.');
      }
      $feed_xml = (string)(!empty($preferred_feeds) ? $preferred_feeds[0]['xml'] : $feed_candidates[0]['xml']);
      $feed_payload = $this->parse_rss_import_xml($feed_xml, $status);
      if (empty($feed_payload['podcast'])) return new WP_Error('eum_podcast_folder_feed_invalid', 'The RSS feed XML does not contain podcast details.');
    }
    $podcast_package = [];
    if (!empty($podcast_package_candidates)) {
      $preferred_packages = array_values(array_filter($podcast_package_candidates, static fn($candidate) => strtolower((string)$candidate['name']) === 'podcast.xml'));
      if (count($preferred_packages) > 1 || (empty($preferred_packages) && count($podcast_package_candidates) > 1)) {
        return new WP_Error('eum_podcast_folder_multiple_packages', 'The folder has multiple podcast package XML files. Keep one podcast.xml.');
      }
      $package_xml = (string)(!empty($preferred_packages) ? $preferred_packages[0]['xml'] : $podcast_package_candidates[0]['xml']);
      $podcast_package = $this->parse_podcast_exchange_xml($package_xml);
      if (empty($podcast_package['podcast'])) return new WP_Error('eum_podcast_folder_package_invalid', 'The podcast.xml package could not be read.');
      foreach ((array)($podcast_package['episodes'] ?? []) as $package_episode) {
        if (!empty($package_episode['title'])) $episode_xml_entries[] = ['name' => 'podcast.xml', 'episode' => (array)$package_episode, 'package_entry' => true];
      }
    }
    if (empty($feed_candidates) && empty($episode_xml_entries) && empty($podcast_package_candidates)) {
      return new WP_Error('eum_podcast_folder_source_missing', 'No episode XML or podcast package XML was found. Add episode XML and matching MP3 files; feed.xml is optional.');
    }

    $feed_by_audio = [];
    $feed_by_guid = [];
    $feed_by_title = [];
    foreach ((array)$feed_payload['episodes'] as $feed_episode) {
      $filename = $this->podcast_archive_reference_filename((string)($feed_episode['audio_url'] ?? ''));
      if ($filename === '') $filename = $this->podcast_archive_reference_filename((string)($feed_episode['guid'] ?? ''));
      if ($filename !== '') $feed_by_audio[$filename] = $feed_episode;
      $guid = sanitize_text_field((string)($feed_episode['guid'] ?? ''));
      if ($guid !== '') $feed_by_guid[$guid] = $feed_episode;
      $title = strtolower(trim((string)($feed_episode['title'] ?? '')));
      if ($title !== '') $feed_by_title[$title] = $feed_episode;
    }

    $episodes = [];
    $errors = [];
    $matched_feed_guids = [];
    usort($episode_xml_entries, static function ($left, $right) {
      $left_package = !empty($left['package_entry']);
      $right_package = !empty($right['package_entry']);
      if ($left_package !== $right_package) return $left_package ? -1 : 1;
      $left_managed = preg_match('/^episode-\d+\.xml$/i', (string)($left['name'] ?? '')) === 1;
      $right_managed = preg_match('/^episode-\d+\.xml$/i', (string)($right['name'] ?? '')) === 1;
      return ((int)$left_managed) <=> ((int)$right_managed);
    });
    foreach ($episode_xml_entries as $xml_entry) {
      $episode = isset($xml_entry['episode']) && is_array($xml_entry['episode'])
        ? (array)$xml_entry['episode']
        : $this->parse_archive_episode_xml((string)($xml_entry['xml'] ?? ''));
      if (empty($episode['title'])) {
        $errors[] = sanitize_file_name((string)($xml_entry['name'] ?? 'episode XML')) . ': episode title could not be read from XML';
        continue;
      }
      $filename = (string)($episode['media_filename'] ?? '');
      if ($filename === '') $filename = $this->podcast_archive_reference_filename((string)($episode['audio_url'] ?? ''));
      if ($filename === '') $filename = $this->podcast_archive_reference_filename((string)($episode['guid'] ?? ''));
      if ($filename === '' || !isset($audio_files[$filename])) {
        $stem = $this->podcast_archive_reference_filename(pathinfo((string)$xml_entry['name'], PATHINFO_FILENAME) . '.mp3');
        if (isset($audio_files[$stem])) $filename = $stem;
      }
      if (!isset($audio_files[$filename])) {
        $errors[] = 'Missing MP3 for ' . sanitize_file_name((string)$xml_entry['name']);
        continue;
      }
      if (!empty($audio_files[$filename]['ambiguous'])) {
        $errors[] = 'More than one MP3 has the name ' . sanitize_file_name($filename);
        continue;
      }
      $episode_guid = sanitize_text_field((string)($episode['guid'] ?? ''));
      $episode_title = strtolower(trim((string)($episode['title'] ?? '')));
      $feed_episode = (array)($feed_by_audio[$filename] ?? ($feed_by_guid[$episode_guid] ?? ($feed_by_title[$episode_title] ?? [])));
      $matched_guid = sanitize_text_field((string)($feed_episode['guid'] ?? ''));
      if ($matched_guid !== '') $matched_feed_guids[$matched_guid] = true;
      $merged = array_merge($feed_episode, $episode);
      foreach (['publish_at', 'duration', 'summary', 'notes'] as $key) {
        if (trim((string)($merged[$key] ?? '')) === '' && trim((string)($feed_episode[$key] ?? '')) !== '') $merged[$key] = $feed_episode[$key];
      }
      if (!empty($feed_episode['guid'])) $merged['guid'] = $feed_episode['guid'];
      if (empty($merged['source_links']) && !empty($feed_episode['source_links'])) $merged['source_links'] = $feed_episode['source_links'];
      $merged_status = sanitize_key((string)($merged['status'] ?? ''));
      $merged['status'] = in_array($merged_status, ['draft', 'scheduled', 'published', 'archived'], true)
        ? $merged_status
        : $status;
      $merged['audio_url'] = '';
      $merged['audio_source_name'] = (string)$audio_files[$filename]['name'];
      $episodes[$filename] = $merged;
    }

    foreach ($feed_by_audio as $filename => $feed_episode) {
      $guid = sanitize_text_field((string)($feed_episode['guid'] ?? ''));
      if ($guid !== '' && isset($matched_feed_guids[$guid])) continue;
      if (isset($episodes[$filename])) continue;
      if (!isset($audio_files[$filename])) {
        $errors[] = 'Missing MP3 for ' . sanitize_file_name($filename);
        continue;
      }
      if (!empty($audio_files[$filename]['ambiguous'])) {
        $errors[] = 'More than one MP3 has the name ' . sanitize_file_name($filename);
        continue;
      }
      $feed_episode['status'] = $status;
      $feed_episode['audio_url'] = '';
      $feed_episode['audio_source_name'] = (string)$audio_files[$filename]['name'];
      $episodes[$filename] = $feed_episode;
    }
    return [
      'podcast' => (array)$feed_payload['podcast'],
      'podcast_package' => (array)($podcast_package['podcast'] ?? []),
      'episodes' => array_values($episodes),
      'errors' => $errors,
    ];
  }

  private function podcast_import_child_file(string $directory, string $filename): string {
    if ($directory === '' || $filename === '' || basename($filename) !== $filename) return '';
    $base = realpath($directory);
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if ($base === false || is_link($path) || !is_file($path)) return '';
    $real_path = realpath($path);
    if ($real_path === false || dirname($real_path) !== $base) return '';
    return $real_path;
  }

  private function sideload_local_podcast_audio(string $source_path, string $filename, int $podcast_id) {
    if (!function_exists('media_handle_sideload')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $filename = sanitize_file_name($filename);
    if ($filename === '' || strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)) !== 'mp3' || !is_file($source_path)) {
      return new WP_Error('eum_podcast_folder_audio_invalid', 'The source MP3 file is invalid.');
    }
    $temp_path = wp_tempnam($filename);
    if (!$temp_path) return new WP_Error('eum_podcast_folder_audio_temp', 'The server could not create a temporary file for this MP3.');
    if (!@copy($source_path, $temp_path)) {
      @unlink($temp_path);
      return new WP_Error('eum_podcast_folder_audio_copy', 'The MP3 could not be copied into the Media Library.');
    }
    $size = (int)filesize($temp_path);
    $attachment_id = $this->sideload_podcast_mp3_attachment([
      'name' => $filename,
      'type' => 'audio/mpeg',
      'tmp_name' => $temp_path,
      'error' => 0,
      'size' => $size,
    ], $podcast_id);
    if (file_exists($temp_path)) @unlink($temp_path);
    if (is_wp_error($attachment_id)) return $attachment_id;
    return (int)$attachment_id;
  }

  private function sideload_podcast_mp3_attachment(array $file, int $post_id) {
    $recognize_mp3 = static function ($data, $file_path, $filename, $mimes = null, $real_mime = '') {
      if (strtolower((string)pathinfo((string)$filename, PATHINFO_EXTENSION)) !== 'mp3') {
        return is_array($data) ? $data : [];
      }
      if (!is_array($data)) $data = [];
      $data['ext'] = 'mp3';
      $data['type'] = 'audio/mpeg';
      $data['proper_filename'] = false;
      return $data;
    };
    add_filter('wp_check_filetype_and_ext', $recognize_mp3, PHP_INT_MAX, 5);
    try {
      $uploaded = wp_handle_sideload($file, [
        'test_form' => false,
        'mimes' => ['mp3' => 'audio/mpeg'],
      ]);
    } finally {
      remove_filter('wp_check_filetype_and_ext', $recognize_mp3, PHP_INT_MAX);
    }
    if (!is_array($uploaded) || !empty($uploaded['error']) || empty($uploaded['file']) || empty($uploaded['url'])) {
      $message = is_array($uploaded) ? (string)($uploaded['error'] ?? '') : '';
      return new WP_Error('eum_podcast_mp3_upload_failed', $message !== '' ? $message : 'The MP3 could not be staged in the Media Library.');
    }

    $title = sanitize_text_field((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_FILENAME));
    $attachment_id = wp_insert_attachment([
      'post_mime_type' => 'audio/mpeg',
      'guid' => esc_url_raw((string)$uploaded['url']),
      'post_title' => $title,
      'post_content' => '',
      'post_status' => 'inherit',
    ], (string)$uploaded['file'], $post_id, true);
    if (is_wp_error($attachment_id)) {
      @unlink((string)$uploaded['file']);
      return $attachment_id;
    }

    if (!function_exists('wp_generate_attachment_metadata')) {
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $metadata = wp_generate_attachment_metadata((int)$attachment_id, (string)$uploaded['file']);
    if (is_array($metadata)) wp_update_attachment_metadata((int)$attachment_id, $metadata);
    return (int)$attachment_id;
  }

  private function finish_podcast_folder_import(array $state): string {
    $created = (int)($state['created'] ?? 0);
    $updated = (int)($state['updated'] ?? 0);
    $podcast_id = (int)($state['podcast_id'] ?? 0);
    $actor_user_id = (int)($state['actor_user_id'] ?? 0);
    if ($podcast_id > 0) {
      if (!empty($state['podcast_package'])) {
        $package = (array)$state['podcast_package'];
        $this->import_podcast_payload_into_existing_podcast($podcast_id, $package);
        $this->import_podcast_cover_from_folder($podcast_id, (string)($state['directory'] ?? ''), (string)($package['cover_file'] ?? ''));
      }
      $this->import_rss_channel_into_existing_podcast($podcast_id, (array)($state['podcast'] ?? []));
      if ($created + $updated > 0) $this->push_activity($podcast_id, $actor_user_id, 'Imported podcast source folder: ' . $created . ' new episode(s), ' . $updated . ' updated.');
      $this->sync_podcast_source_files($podcast_id, array_values(array_unique(array_map('intval', (array)($state['changed_episode_ids'] ?? [])))));
    }
    $message = sprintf('Folder import complete: %d new episode(s), %d updated.', $created, $updated);
    $errors = array_values(array_filter(array_map('sanitize_text_field', (array)($state['errors'] ?? []))));
    if (!empty($errors)) $message .= ' Skipped: ' . implode('; ', array_slice($errors, 0, 3));
    return $message;
  }

  private function import_podcast_cover_from_folder(int $podcast_id, string $directory, string $filename): void {
    if ($podcast_id <= 0 || $filename === '') return;
    $source = $this->podcast_import_child_file($directory, sanitize_file_name($filename));
    if ($source === '' || filesize($source) <= 0 || filesize($source) > 20 * 1024 * 1024) return;
    $filetype = wp_check_filetype($filename);
    if (empty($filetype['type']) || strpos((string)$filetype['type'], 'image/') !== 0) return;

    $current_id = (int)get_post_meta($podcast_id, self::META_COVER_ATTACHMENT_ID, true);
    $current_path = $current_id > 0 ? get_attached_file($current_id) : '';
    if ($current_path && is_file($current_path) && filesize($current_path) === filesize($source)) {
      $current_hash = md5_file($current_path);
      $source_hash = md5_file($source);
      if (is_string($current_hash) && is_string($source_hash) && hash_equals($current_hash, $source_hash)) return;
    }

    if (!function_exists('media_handle_sideload')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $temp_path = wp_tempnam($filename);
    if (!$temp_path || !@copy($source, $temp_path)) {
      if ($temp_path) @unlink($temp_path);
      return;
    }
    $attachment_id = media_handle_sideload([
      'name' => sanitize_file_name($filename),
      'type' => (string)$filetype['type'],
      'tmp_name' => $temp_path,
      'error' => 0,
      'size' => (int)filesize($temp_path),
    ], $podcast_id);
    if (file_exists($temp_path)) @unlink($temp_path);
    if (!is_wp_error($attachment_id) && (int)$attachment_id > 0) {
      update_post_meta($podcast_id, self::META_COVER_ATTACHMENT_ID, (int)$attachment_id);
    }
  }

  private function save_episode_meta(int $episode_id, int $podcast_id, int $new_audio_attachment_id, int $new_video_attachment_id, bool $preserve_existing_media): void {
    $status = $this->sanitize_episode_status((string)($_POST['episode_status'] ?? 'draft'));
    $publish_at = $this->normalize_publish_at((string)($_POST['publish_at'] ?? ''), $status);
    $audio_attachment_id = $new_audio_attachment_id > 0 ? $new_audio_attachment_id : ($preserve_existing_media ? (int)get_post_meta($episode_id, self::META_EP_AUDIO_ATTACHMENT_ID, true) : 0);
    $video_attachment_id = $new_video_attachment_id > 0 ? $new_video_attachment_id : ($preserve_existing_media ? (int)get_post_meta($episode_id, self::META_EP_VIDEO_ATTACHMENT_ID, true) : 0);
    $audio_url = $audio_attachment_id > 0 ? '' : esc_url_raw((string)($_POST['audio_url'] ?? ($preserve_existing_media ? get_post_meta($episode_id, self::META_EP_AUDIO_URL, true) : '')));
    $video_url = $video_attachment_id > 0 ? '' : esc_url_raw((string)($_POST['video_url'] ?? ($preserve_existing_media ? get_post_meta($episode_id, self::META_EP_VIDEO_URL, true) : '')));
    if ($status === 'published' && $audio_attachment_id <= 0 && $video_attachment_id <= 0 && $audio_url === '' && $video_url === '') {
      $this->redirect_with_notice($podcast_id, 'Published episodes need audio or video media.', 'error');
    }

    update_post_meta($episode_id, self::META_EP_PODCAST_ID, $podcast_id);
    update_post_meta($episode_id, self::META_EP_SUMMARY, sanitize_textarea_field((string)($_POST['summary'] ?? '')));
    update_post_meta($episode_id, self::META_EP_NOTES, wp_kses_post((string)($_POST['notes'] ?? '')));
    update_post_meta($episode_id, self::META_EP_STATUS, $status);
    update_post_meta($episode_id, self::META_EP_PUBLISH_AT, $publish_at);
    update_post_meta($episode_id, self::META_EP_DURATION, sanitize_text_field((string)($_POST['duration'] ?? '')));
    update_post_meta($episode_id, self::META_EP_EXPLICIT, !empty($_POST['explicit']) ? '1' : '0');
    update_post_meta($episode_id, self::META_EP_SEASON, max(0, (int)($_POST['season'] ?? 0)));
    update_post_meta($episode_id, self::META_EP_NUMBER, max(0, (int)($_POST['episode_number'] ?? 0)));
    update_post_meta($episode_id, self::META_EP_AUDIO_ATTACHMENT_ID, max(0, $audio_attachment_id));
    update_post_meta($episode_id, self::META_EP_AUDIO_URL, $audio_url);
    update_post_meta($episode_id, self::META_EP_VIDEO_ATTACHMENT_ID, max(0, $video_attachment_id));
    update_post_meta($episode_id, self::META_EP_VIDEO_URL, $video_url);
    $existing_prepro = $preserve_existing_media ? $this->normalize_prepro_resources((array)get_post_meta($episode_id, self::META_EP_PREPRO_RESOURCES, true)) : [];
    $uploaded_prepro = $this->resources_from_attachment_ids($this->handle_multiple_media_uploads('prepro_uploads', $episode_id));
    $manual_prepro_urls = $this->resources_from_url_text((string)($_POST['prepro_resource_urls'] ?? ''));
    update_post_meta($episode_id, self::META_EP_PREPRO_RESOURCES, $this->normalize_prepro_resources(array_merge($existing_prepro, $uploaded_prepro, $manual_prepro_urls)));
    $manual_source_links = $this->source_links_from_text((string)($_POST['episode_source_links'] ?? ''));
    update_post_meta($episode_id, self::META_EP_SOURCE_LINKS, $manual_source_links);
    if ((string)get_post_meta($episode_id, self::META_EP_GUID, true) === '') {
      update_post_meta($episode_id, self::META_EP_GUID, wp_generate_uuid4());
    }
  }

  private function uploaded_file_contents(string $field): string {
    if (empty($_FILES[$field]) || empty($_FILES[$field]['tmp_name'])) return '';
    $tmp = (string)$_FILES[$field]['tmp_name'];
    if ($tmp === '' || !file_exists($tmp)) return '';
    $contents = file_get_contents($tmp);
    return is_string($contents) ? $contents : '';
  }

  private function create_imported_episode(int $podcast_id, int $actor_user_id, array $payload, int $existing_episode_id = 0): int {
    $title = sanitize_text_field((string)($payload['title'] ?? ''));
    if ($title === '') return 0;
    $post_data = [
      'post_type' => self::CPT_EPISODE,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $actor_user_id,
    ];
    if ($existing_episode_id > 0 && get_post_type($existing_episode_id) === self::CPT_EPISODE
      && (int)get_post_meta($existing_episode_id, self::META_EP_PODCAST_ID, true) === $podcast_id) {
      $post_data['ID'] = $existing_episode_id;
      $episode_id = wp_update_post($post_data, true);
    } else {
      $episode_id = wp_insert_post($post_data, true);
    }
    if (is_wp_error($episode_id)) return 0;
    $episode_id = (int)$episode_id;
    if ($episode_id <= 0) return 0;

    $audio_attachment_id = max(0, (int)($payload['audio_attachment_id'] ?? 0));
    update_post_meta($episode_id, self::META_EP_PODCAST_ID, $podcast_id);
    update_post_meta($episode_id, self::META_EP_SUMMARY, sanitize_textarea_field((string)($payload['summary'] ?? '')));
    update_post_meta($episode_id, self::META_EP_NOTES, wp_kses_post((string)($payload['notes'] ?? '')));
    update_post_meta($episode_id, self::META_EP_STATUS, $this->sanitize_episode_status((string)($payload['status'] ?? 'draft')));
    update_post_meta($episode_id, self::META_EP_PUBLISH_AT, sanitize_text_field((string)($payload['publish_at'] ?? '')));
    update_post_meta($episode_id, self::META_EP_DURATION, sanitize_text_field((string)($payload['duration'] ?? '')));
    update_post_meta($episode_id, self::META_EP_EXPLICIT, !empty($payload['explicit']) ? '1' : '0');
    update_post_meta($episode_id, self::META_EP_SEASON, max(0, (int)($payload['season'] ?? 0)));
    update_post_meta($episode_id, self::META_EP_NUMBER, max(0, (int)($payload['number'] ?? 0)));
    update_post_meta($episode_id, self::META_EP_AUDIO_ATTACHMENT_ID, $audio_attachment_id);
    update_post_meta($episode_id, self::META_EP_AUDIO_URL, $audio_attachment_id > 0 ? '' : esc_url_raw((string)($payload['audio_url'] ?? '')));
    update_post_meta($episode_id, self::META_EP_VIDEO_ATTACHMENT_ID, 0);
    update_post_meta($episode_id, self::META_EP_VIDEO_URL, esc_url_raw((string)($payload['video_url'] ?? '')));
    update_post_meta($episode_id, self::META_EP_PREPRO_RESOURCES, $this->normalize_prepro_resources((array)($payload['prepro_resources'] ?? [])));
    update_post_meta($episode_id, self::META_EP_SOURCE_LINKS, $this->normalize_source_links((array)($payload['source_links'] ?? [])));
    update_post_meta($episode_id, self::META_EP_GUID, sanitize_text_field((string)($payload['guid'] ?? wp_generate_uuid4())));
    return $episode_id;
  }

  private function import_podcast_payload_into_existing_podcast(int $podcast_id, array $payload): void {
    if (!empty($payload['title'])) {
      wp_update_post(['ID' => $podcast_id, 'post_title' => $this->podcast_title_without_feed_variant((string)$payload['title'])]);
    }
    update_post_meta($podcast_id, self::META_STATUS, $this->sanitize_podcast_status((string)($payload['status'] ?? 'active')));
    update_post_meta($podcast_id, self::META_SUBTITLE, sanitize_text_field((string)($payload['subtitle'] ?? '')));
    update_post_meta($podcast_id, self::META_DESCRIPTION, wp_kses_post((string)($payload['description'] ?? '')));
    update_post_meta($podcast_id, self::META_AUTHOR, sanitize_text_field((string)($payload['author'] ?? '')));
    update_post_meta($podcast_id, self::META_OWNER_EMAIL, sanitize_email((string)($payload['owner_email'] ?? '')));
    update_post_meta($podcast_id, self::META_LANGUAGE, sanitize_text_field((string)($payload['language'] ?? 'en-us')));
    update_post_meta($podcast_id, self::META_CATEGORY, sanitize_text_field((string)($payload['category'] ?? '')));
    update_post_meta($podcast_id, self::META_WEBSITE_URL, esc_url_raw((string)($payload['website_url'] ?? '')));
    update_post_meta($podcast_id, self::META_COPYRIGHT, sanitize_text_field((string)($payload['copyright'] ?? '')));
    update_post_meta($podcast_id, self::META_FINANCE_PATH_TERM_ID, max(0, (int)($payload['finance_path_term_id'] ?? 0)));
    update_post_meta($podcast_id, self::META_SOURCE_LINKS, $this->normalize_source_links((array)($payload['source_links'] ?? [])));
    if (isset($payload['expenses']) && is_array($payload['expenses'])) {
      update_post_meta($podcast_id, self::META_EXPENSE_LINES, $payload['expenses']);
    }
    if (isset($payload['manager_user_ids']) && is_array($payload['manager_user_ids'])) {
      $manager_ids = array_values(array_filter(array_map('intval', $payload['manager_user_ids']), static fn($user_id) => $user_id > 0 && get_userdata($user_id)));
      $member_ids = array_values(array_filter(array_map('intval', (array)($payload['member_user_ids'] ?? [])), static fn($user_id) => $user_id > 0 && get_userdata($user_id)));
      if (!empty($manager_ids)) $this->save_podcast_team($podcast_id, $manager_ids, $member_ids);
    }
  }

  private function build_episode_exchange_xml(array $episode): string {
    $post = $episode['post'] ?? null;
    if (!$post instanceof WP_Post) return '';
    ob_start();
    echo '<?xml version="1.0" encoding="' . esc_attr(get_option('blog_charset')) . '"?>';
    echo $this->build_episode_exchange_fragment($episode);
    return (string)ob_get_clean();
  }

  private function build_episode_exchange_fragment(array $episode): string {
    $post = $episode['post'] ?? null;
    if (!$post instanceof WP_Post && !empty($episode['title'])) {
      $title = (string)$episode['title'];
    } else {
      $title = $post instanceof WP_Post ? (string)$post->post_title : '';
    }
    ob_start();
    ?>
<episode>
  <title><?php echo esc_xml($title); ?></title>
  <status><?php echo esc_xml((string)($episode['status'] ?? 'draft')); ?></status>
  <publish_at><?php echo esc_xml((string)($episode['publish_at'] ?? '')); ?></publish_at>
  <duration><?php echo esc_xml((string)($episode['duration'] ?? '')); ?></duration>
  <season><?php echo (int)($episode['season'] ?? 0); ?></season>
  <number><?php echo (int)($episode['number'] ?? 0); ?></number>
  <explicit><?php echo !empty($episode['explicit']) ? '1' : '0'; ?></explicit>
  <audio_url><?php echo esc_xml((string)($episode['audio_url'] ?? '')); ?></audio_url>
  <video_url><?php echo esc_xml((string)($episode['video_url'] ?? '')); ?></video_url>
  <guid><?php echo esc_xml((string)($episode['guid'] ?? '')); ?></guid>
  <summary><?php echo esc_xml((string)($episode['summary'] ?? '')); ?></summary>
  <notes><![CDATA[<?php echo (string)($episode['notes'] ?? ''); ?>]]></notes>
  <source_links>
    <?php foreach ((array)($episode['source_links'] ?? []) as $source_link) : ?>
    <source url="<?php echo esc_attr((string)($source_link['url'] ?? '')); ?>" label="<?php echo esc_attr((string)($source_link['label'] ?? '')); ?>" />
    <?php endforeach; ?>
  </source_links>
  <prepro_resources>
    <?php foreach ((array)($episode['prepro_resources'] ?? []) as $resource) : ?>
    <resource url="<?php echo esc_attr((string)($resource['url'] ?? '')); ?>" label="<?php echo esc_attr((string)($resource['label'] ?? '')); ?>" />
    <?php endforeach; ?>
  </prepro_resources>
</episode>
    <?php
    return (string)ob_get_clean();
  }

  private function build_episode_exchange_csv(int $podcast_id): string {
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, ['title', 'status', 'publish_at', 'duration', 'season', 'number', 'explicit', 'audio_url', 'video_url', 'summary', 'notes', 'source_links', 'prepro_resource_urls']);
    foreach ($this->podcast_episode_rows($podcast_id, true) as $episode) {
      $urls = array_map(static fn($resource) => (string)($resource['url'] ?? ''), (array)($episode['prepro_resources'] ?? []));
      fputcsv($stream, [
        !empty($episode['post']) ? (string)$episode['post']->post_title : '',
        (string)($episode['status'] ?? ''),
        (string)($episode['publish_at'] ?? ''),
        (string)($episode['duration'] ?? ''),
        (int)($episode['season'] ?? 0),
        (int)($episode['number'] ?? 0),
        !empty($episode['explicit']) ? '1' : '0',
        (string)($episode['audio_url'] ?? ''),
        (string)($episode['video_url'] ?? ''),
        (string)($episode['summary'] ?? ''),
        wp_strip_all_tags((string)($episode['notes'] ?? '')),
        $this->source_links_to_text((array)($episode['source_links'] ?? [])),
        implode("\n", array_filter($urls)),
      ]);
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    return is_string($csv) ? $csv : '';
  }

  private function build_podcast_exchange_xml(int $podcast_id): string {
    $podcast = get_post($podcast_id);
    $settings = $this->podcast_settings($podcast_id);
    $expenses = $this->podcast_expense_lines($podcast_id);
    $manager_ids = $this->explicit_manager_user_ids($podcast_id);
    $member_ids = $this->explicit_member_user_ids($podcast_id);
    $cover_path = $settings['cover_attachment_id'] > 0 ? get_attached_file((int)$settings['cover_attachment_id']) : '';
    $cover_file = $cover_path && is_file($cover_path) ? basename((string)$cover_path) : '';
    ob_start();
    echo '<?xml version="1.0" encoding="' . esc_attr(get_option('blog_charset')) . '"?>';
    ?>
<podcast>
  <details>
    <title><?php echo esc_xml((string)$podcast->post_title); ?></title>
    <status><?php echo esc_xml((string)$settings['status']); ?></status>
    <subtitle><?php echo esc_xml((string)$settings['subtitle']); ?></subtitle>
    <description><![CDATA[<?php echo (string)$settings['description']; ?>]]></description>
    <author><?php echo esc_xml((string)$settings['author']); ?></author>
    <owner_email><?php echo esc_xml((string)$settings['owner_email']); ?></owner_email>
    <language><?php echo esc_xml((string)$settings['language']); ?></language>
    <category><?php echo esc_xml((string)$settings['category']); ?></category>
    <website_url><?php echo esc_xml((string)$settings['website_url']); ?></website_url>
    <copyright><?php echo esc_xml((string)$settings['copyright']); ?></copyright>
    <cover_file><?php echo esc_xml($cover_file); ?></cover_file>
    <finance_path_term_id><?php echo (int)$settings['finance_path_term_id']; ?></finance_path_term_id>
    <source_links>
      <?php foreach ((array)($settings['source_links'] ?? []) as $source_link) : ?>
      <source url="<?php echo esc_attr((string)($source_link['url'] ?? '')); ?>" label="<?php echo esc_attr((string)($source_link['label'] ?? '')); ?>" />
      <?php endforeach; ?>
    </source_links>
  </details>
  <team>
    <manager_user_ids><?php echo esc_xml(implode(',', array_map('intval', $manager_ids))); ?></manager_user_ids>
    <member_user_ids><?php echo esc_xml(implode(',', array_map('intval', $member_ids))); ?></member_user_ids>
  </team>
  <expenses>
    <?php foreach ($expenses as $expense) : ?>
    <expense title="<?php echo esc_attr((string)($expense['title'] ?? '')); ?>" amount="<?php echo esc_attr((string)($expense['amount'] ?? 0)); ?>" occurred_on="<?php echo esc_attr((string)($expense['occurred_on'] ?? '')); ?>" finance_path_term_id="<?php echo (int)($expense['finance_path_term_id'] ?? 0); ?>" job_path_term_id="<?php echo (int)($expense['job_path_term_id'] ?? 0); ?>">
      <notes><?php echo esc_xml((string)($expense['notes'] ?? '')); ?></notes>
    </expense>
    <?php endforeach; ?>
  </expenses>
  <episodes>
    <?php foreach ($this->podcast_episode_rows($podcast_id, true) as $episode) : ?>
    <?php echo $this->build_episode_exchange_fragment($episode); ?>
    <?php endforeach; ?>
  </episodes>
</podcast>
    <?php
    return (string)ob_get_clean();
  }

  private function load_podcast_xml(string $xml): ?SimpleXMLElement {
    if (!function_exists('simplexml_load_string')) return null;
    $previous_errors = libxml_use_internal_errors(true);
    $root = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);
    return $root instanceof SimpleXMLElement ? $root : null;
  }

  private function parse_archive_episode_xml(string $xml): array {
    $root = $this->load_podcast_xml($xml);
    if (!$root) return [];
    $root_name = strtolower((string)$root->getName());
    if ($root_name === 'episode') {
      $episode = $this->parse_episode_exchange_xml($xml);
      if (empty($episode['title'])) return [];
      $reference = (string)($episode['audio_url'] ?? '');
      if ($reference === '') $reference = (string)($episode['guid'] ?? '');
      $episode['media_filename'] = $this->podcast_archive_reference_filename($reference);
      return $episode;
    }
    if ($root_name !== 'podcastgenerator' || !isset($root->episode)) return [];

    $episode = $root->episode;
    $guid = sanitize_text_field((string)($episode->guid ?? ''));
    return [
      'title' => sanitize_text_field((string)($episode->titlePG ?? '')),
      'summary' => sanitize_textarea_field((string)($episode->shortdescPG ?? '')),
      'notes' => (string)($episode->longdescPG ?? ''),
      'duration' => sanitize_text_field((string)($episode->fileInfoPG->duration ?? '')),
      'season' => max(0, (int)($episode->seasonNumPG ?? 0)),
      'number' => max(0, (int)($episode->episodeNumPG ?? 0)),
      'explicit' => in_array(strtolower(trim((string)($episode->explicitPG ?? ''))), ['1', 'true', 'yes'], true),
      'guid' => $guid,
      'media_filename' => $this->podcast_archive_reference_filename($guid),
      'source_links' => [],
      'prepro_resources' => [],
    ];
  }

  private function podcast_archive_reference_filename(string $reference): string {
    $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    if ($reference === '') return '';
    $parts = wp_parse_url($reference);
    $query = [];
    if (is_array($parts) && !empty($parts['query'])) parse_str((string)$parts['query'], $query);
    $filename_reference = '';
    foreach (['name', 'file', 'filename'] as $query_key) {
      if (!empty($query[$query_key]) && is_scalar($query[$query_key])) {
        $filename_reference = (string)$query[$query_key];
        break;
      }
    }
    if ($filename_reference === '') $filename_reference = (string)(is_array($parts) ? ($parts['path'] ?? $reference) : $reference);
    $basename = basename(str_replace('\\', '/', rawurldecode($filename_reference)));
    return strtolower(sanitize_file_name($basename));
  }

  private function parse_episode_exchange_xml(string $xml): array {
    $root = $this->load_podcast_xml($xml);
    if (!$root || strtolower($root->getName()) !== 'episode') return [];
    $resources = [];
    if (isset($root->prepro_resources)) {
      foreach ($root->prepro_resources->resource as $resource) {
        $resource_url = esc_url_raw((string)$resource['url']);
        if ($resource_url === '') continue;
        $resources[] = [
          'attachment_id' => 0,
          'url' => $resource_url,
          'label' => sanitize_text_field((string)$resource['label']),
        ];
      }
    }
    $source_links = [];
    if (isset($root->source_links)) {
      foreach ($root->source_links->source as $source) {
        $source_url = esc_url_raw((string)$source['url']);
        if ($source_url === '') continue;
        $source_links[] = [
          'url' => $source_url,
          'label' => sanitize_text_field((string)$source['label']),
        ];
      }
    }
    return [
      'title' => sanitize_text_field((string)$root->title),
      'status' => sanitize_key((string)$root->status),
      'publish_at' => sanitize_text_field((string)$root->publish_at),
      'duration' => sanitize_text_field((string)$root->duration),
      'season' => (int)$root->season,
      'number' => (int)$root->number,
      'explicit' => ((string)$root->explicit === '1' || strtolower((string)$root->explicit) === 'true'),
      'audio_url' => esc_url_raw((string)$root->audio_url),
      'video_url' => esc_url_raw((string)$root->video_url),
      'guid' => sanitize_text_field((string)$root->guid),
      'summary' => sanitize_textarea_field((string)$root->summary),
      'notes' => (string)$root->notes,
      'prepro_resources' => $resources,
      'source_links' => $this->normalize_source_links($source_links),
    ];
  }

  private function parse_episode_exchange_csv(string $csv): array {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream);
    if (!is_array($headers)) {
      fclose($stream);
      return [];
    }
    $headers = array_map(static fn($value) => sanitize_key((string)$value), $headers);
    $rows = [];
    while (($row = fgetcsv($stream)) !== false) {
      $assoc = [];
      foreach ($headers as $index => $header) {
        $assoc[$header] = (string)($row[$index] ?? '');
      }
      $rows[] = [
        'title' => sanitize_text_field((string)($assoc['title'] ?? '')),
        'status' => sanitize_key((string)($assoc['status'] ?? 'draft')),
        'publish_at' => sanitize_text_field((string)($assoc['publish_at'] ?? '')),
        'duration' => sanitize_text_field((string)($assoc['duration'] ?? '')),
        'season' => (int)($assoc['season'] ?? 0),
        'number' => (int)($assoc['number'] ?? 0),
        'explicit' => !empty($assoc['explicit']),
        'audio_url' => esc_url_raw((string)($assoc['audio_url'] ?? '')),
        'video_url' => esc_url_raw((string)($assoc['video_url'] ?? '')),
        'summary' => sanitize_textarea_field((string)($assoc['summary'] ?? '')),
        'notes' => sanitize_textarea_field((string)($assoc['notes'] ?? '')),
        'source_links' => $this->source_links_from_text((string)($assoc['source_links'] ?? '')),
        'prepro_resources' => $this->resources_from_url_text((string)($assoc['prepro_resource_urls'] ?? '')),
      ];
    }
    fclose($stream);
    return array_values(array_filter($rows, static fn($row) => (string)($row['title'] ?? '') !== ''));
  }

  private function parse_podcast_exchange_xml(string $xml): array {
    $root = $this->load_podcast_xml($xml);
    if (!$root || strtolower($root->getName()) !== 'podcast') return [];
    $details = $root->details;
    $payload = [
      'podcast' => [
        'title' => sanitize_text_field((string)($details->title ?? '')),
        'status' => sanitize_key((string)($details->status ?? 'active')),
        'subtitle' => sanitize_text_field((string)($details->subtitle ?? '')),
        'description' => (string)($details->description ?? ''),
        'author' => sanitize_text_field((string)($details->author ?? '')),
        'owner_email' => sanitize_email((string)($details->owner_email ?? '')),
        'language' => sanitize_text_field((string)($details->language ?? 'en-us')),
        'category' => sanitize_text_field((string)($details->category ?? '')),
        'website_url' => esc_url_raw((string)($details->website_url ?? '')),
        'copyright' => sanitize_text_field((string)($details->copyright ?? '')),
        'cover_file' => sanitize_file_name((string)($details->cover_file ?? '')),
        'finance_path_term_id' => (int)($details->finance_path_term_id ?? 0),
        'manager_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string)($root->team->manager_user_ids ?? ''))))),
        'member_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string)($root->team->member_user_ids ?? ''))))),
        'source_links' => [],
        'expenses' => [],
      ],
      'episodes' => [],
    ];
    if (isset($details->source_links)) {
      foreach ($details->source_links->source as $source) {
        $source_url = esc_url_raw((string)$source['url']);
        if ($source_url === '') continue;
        $payload['podcast']['source_links'][] = [
          'url' => $source_url,
          'label' => sanitize_text_field((string)$source['label']),
        ];
      }
      $payload['podcast']['source_links'] = $this->normalize_source_links((array)$payload['podcast']['source_links']);
    }
    if (isset($root->expenses)) {
      foreach ($root->expenses->expense as $expense) {
        $payload['podcast']['expenses'][] = [
          'title' => sanitize_text_field((string)$expense['title']),
          'amount' => round((float)$expense['amount'], 2),
          'occurred_on' => sanitize_text_field((string)$expense['occurred_on']),
          'finance_path_term_id' => (int)$expense['finance_path_term_id'],
          'job_path_term_id' => (int)$expense['job_path_term_id'],
          'notes' => sanitize_textarea_field((string)$expense->notes),
          'submitted_by' => 0,
          'created_at' => current_time('mysql'),
        ];
      }
    }
    if (isset($root->episodes)) {
      foreach ($root->episodes->episode as $episode) {
        $parsed_episode = $this->parse_episode_exchange_xml($episode->asXML() ?: '');
        if (!empty($parsed_episode)) $payload['episodes'][] = $parsed_episode;
      }
    }
    return $payload;
  }

  private function parse_rss_import_xml(string $xml, string $default_status = 'draft', string $source_feed_url = ''): array {
    $root = $this->load_podcast_xml($xml);
    if (!$root) return [];

    $channel = null;
    $root_name = strtolower((string)$root->getName());
    if ($root_name === 'rss' && isset($root->channel)) {
      $channel = $root->channel;
    } elseif ($root_name === 'channel') {
      $channel = $root;
    }
    if (!$channel) return [];

    $itunes_channel = $this->xml_children_by_namespace($channel, 'itunes');
    $owner = $itunes_channel && isset($itunes_channel->owner) ? $this->xml_children_by_namespace($itunes_channel->owner, 'itunes') : null;

    $podcast_source_links = [];
    $channel_link = esc_url_raw((string)($channel->link ?? ''));
    if ($channel_link !== '') $podcast_source_links[] = ['label' => 'Podcast Website', 'url' => $channel_link];
    if ($source_feed_url !== '') $podcast_source_links[] = ['label' => 'Imported RSS Feed', 'url' => $source_feed_url];

    $podcast = [
      'title' => $this->podcast_title_without_feed_variant((string)($channel->title ?? '')),
      'subtitle' => $itunes_channel ? sanitize_text_field((string)($itunes_channel->subtitle ?? '')) : '',
      'description' => wp_kses_post((string)($channel->description ?? '')),
      'author' => $itunes_channel ? sanitize_text_field((string)($itunes_channel->author ?? '')) : '',
      'owner_email' => $owner ? sanitize_email((string)($owner->email ?? '')) : '',
      'language' => sanitize_text_field((string)($channel->language ?? 'en-us')),
      'category' => '',
      'website_url' => $channel_link,
      'copyright' => sanitize_text_field((string)($channel->copyright ?? '')),
      'source_links' => $this->normalize_source_links($podcast_source_links),
    ];
    if ($podcast['subtitle'] === '' && $itunes_channel && isset($itunes_channel->summary)) {
      $podcast['subtitle'] = wp_trim_words(wp_strip_all_tags((string)$itunes_channel->summary), 18, '');
    }
    if ($podcast['description'] === '' && $itunes_channel && isset($itunes_channel->summary)) {
      $podcast['description'] = wp_kses_post((string)$itunes_channel->summary);
    }
    if ($itunes_channel && isset($itunes_channel->category)) {
      $category_paths = [];
      foreach ($itunes_channel->category as $category) {
        $category_paths = array_merge($category_paths, $this->itunes_category_paths($category));
      }
      $podcast['category'] = sanitize_text_field(implode('; ', array_values(array_unique(array_filter($category_paths)))));
    }

    $episodes = [];
    foreach ($channel->item as $item) {
      $title = sanitize_text_field((string)($item->title ?? ''));
      if ($title === '') continue;

      $itunes_item = $this->xml_children_by_namespace($item, 'itunes');
      $content_item = $this->xml_children_by_namespace($item, 'content');
      $guid = sanitize_text_field((string)($item->guid ?? ''));
      $item_link = esc_url_raw((string)($item->link ?? ''));
      if ($guid === '' && $item_link !== '') $guid = $item_link;
      if ($guid === '') $guid = 'rss-import-' . md5($title . '|' . (string)($item->pubDate ?? '') . '|' . $item_link);

      $audio_url = '';
      $video_url = '';
      foreach ($item->enclosure as $enclosure) {
        $url = esc_url_raw((string)$enclosure['url']);
        if ($url === '') continue;
        $type = strtolower((string)$enclosure['type']);
        if ($audio_url === '' && (strpos($type, 'audio/') === 0 || preg_match('/\.(mp3|m4a|aac|wav|ogg)(\?.*)?$/i', $url))) {
          $audio_url = $url;
        } elseif ($video_url === '' && (strpos($type, 'video/') === 0 || preg_match('/\.(mp4|m4v|mov|webm)(\?.*)?$/i', $url))) {
          $video_url = $url;
        } elseif ($audio_url === '') {
          $audio_url = $url;
        }
      }

      $publish_at = '';
      $pub_date = trim((string)($item->pubDate ?? ''));
      if ($pub_date !== '') {
        try {
          $pub_datetime = new DateTimeImmutable($pub_date, new DateTimeZone('UTC'));
          $site_timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
          $publish_at = $pub_datetime->setTimezone($site_timezone)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
          $publish_at = '';
        }
      }

      $summary = $itunes_item && isset($itunes_item->summary)
        ? sanitize_textarea_field((string)$itunes_item->summary)
        : sanitize_textarea_field(wp_strip_all_tags((string)($item->description ?? '')));
      $notes = $content_item && isset($content_item->encoded)
        ? wp_kses_post((string)$content_item->encoded)
        : wp_kses_post((string)($item->description ?? ''));

      $source_links = [];
      if ($item_link !== '') $source_links[] = ['label' => 'Original Episode Page', 'url' => $item_link];
      if ($source_feed_url !== '') $source_links[] = ['label' => 'Imported RSS Feed', 'url' => $source_feed_url];

      $episodes[] = [
        'title' => $title,
        'status' => $default_status,
        'publish_at' => $publish_at,
        'duration' => $itunes_item ? sanitize_text_field((string)($itunes_item->duration ?? '')) : '',
        'season' => $itunes_item ? max(0, (int)($itunes_item->season ?? 0)) : 0,
        'number' => $itunes_item ? max(0, (int)($itunes_item->episode ?? 0)) : 0,
        'explicit' => $itunes_item ? $this->truthy_xml_value((string)($itunes_item->explicit ?? '')) : false,
        'audio_url' => $audio_url,
        'video_url' => $video_url,
        'guid' => $guid,
        'summary' => $summary,
        'notes' => $notes,
        'source_links' => $this->normalize_source_links($source_links),
        'prepro_resources' => [],
      ];
    }

    return [
      'podcast' => $podcast,
      'episodes' => $episodes,
    ];
  }

  private function import_rss_channel_into_existing_podcast(int $podcast_id, array $payload): void {
    if (!empty($payload['title'])) {
      wp_update_post(['ID' => $podcast_id, 'post_title' => $this->podcast_title_without_feed_variant((string)$payload['title'])]);
    }
    foreach ([
      self::META_SUBTITLE => 'subtitle',
      self::META_DESCRIPTION => 'description',
      self::META_AUTHOR => 'author',
      self::META_OWNER_EMAIL => 'owner_email',
      self::META_LANGUAGE => 'language',
      self::META_CATEGORY => 'category',
      self::META_WEBSITE_URL => 'website_url',
      self::META_COPYRIGHT => 'copyright',
    ] as $meta_key => $payload_key) {
      if (!array_key_exists($payload_key, $payload)) continue;
      $value = (string)$payload[$payload_key];
      if ($payload_key === 'description') $value = wp_kses_post($value);
      elseif ($payload_key === 'owner_email') $value = sanitize_email($value);
      elseif ($payload_key === 'website_url') $value = esc_url_raw($value);
      else $value = sanitize_text_field($value);
      update_post_meta($podcast_id, $meta_key, $value);
    }
    if (isset($payload['source_links']) && is_array($payload['source_links'])) {
      $existing = $this->normalize_source_links((array)get_post_meta($podcast_id, self::META_SOURCE_LINKS, true));
      update_post_meta($podcast_id, self::META_SOURCE_LINKS, $this->normalize_source_links(array_merge($existing, (array)$payload['source_links'])));
    }
  }

  private function episode_guid_exists(int $podcast_id, string $guid): bool {
    return $this->episode_id_for_guid($podcast_id, $guid) > 0;
  }

  private function episode_id_for_guid(int $podcast_id, string $guid): int {
    $guid = sanitize_text_field($guid);
    if ($podcast_id <= 0 || $guid === '') return 0;
    $ids = get_posts([
      'post_type' => self::CPT_EPISODE,
      'post_status' => 'publish',
      'numberposts' => 1,
      'fields' => 'ids',
      'meta_query' => [
        'relation' => 'AND',
        [
          'key' => self::META_EP_PODCAST_ID,
          'value' => $podcast_id,
          'compare' => '=',
        ],
        [
          'key' => self::META_EP_GUID,
          'value' => $guid,
          'compare' => '=',
        ],
      ],
      'suppress_filters' => false,
    ]);
    return !empty($ids) ? (int)$ids[0] : 0;
  }

  private function existing_episode_audio_attachment(int $episode_id, string $filename, string $source_path = ''): int {
    $attachment_id = (int)get_post_meta($episode_id, self::META_EP_AUDIO_ATTACHMENT_ID, true);
    if ($attachment_id <= 0) return 0;
    $path = get_attached_file($attachment_id);
    if (!$path || !is_file($path)) return 0;
    if (strtolower(basename($path)) !== strtolower(basename($filename))) return 0;
    if ($source_path !== '' && is_file($source_path)) {
      $existing_size = filesize($path);
      $source_size = filesize($source_path);
      if (!is_int($existing_size) || !is_int($source_size) || $existing_size !== $source_size) return 0;
      $existing_hash = hash_file('sha256', $path);
      $source_hash = hash_file('sha256', $source_path);
      if (!is_string($existing_hash) || !is_string($source_hash) || !hash_equals($existing_hash, $source_hash)) return 0;
    }
    return $attachment_id;
  }

  private function xml_children_by_namespace(SimpleXMLElement $node, string $preferred_prefix): ?SimpleXMLElement {
    $namespaces = $node->getNamespaces(true);
    if (isset($namespaces[$preferred_prefix])) {
      return $node->children($namespaces[$preferred_prefix]);
    }
    foreach ($namespaces as $prefix => $namespace) {
      if (stripos((string)$namespace, $preferred_prefix) !== false) {
        return $node->children($namespace);
      }
    }
    return null;
  }

  private function itunes_category_paths(SimpleXMLElement $category, array $parents = []): array {
    $label = sanitize_text_field((string)$category['text']);
    if ($label !== '') $parents[] = $label;
    $nested_paths = [];
    $children = $this->xml_children_by_namespace($category, 'itunes');
    if ($children && isset($children->category)) {
      foreach ($children->category as $child) {
        $nested_paths = array_merge($nested_paths, $this->itunes_category_paths($child, $parents));
      }
    }
    return !empty($nested_paths) ? $nested_paths : (!empty($parents) ? [implode(' > ', $parents)] : []);
  }

  private function truthy_xml_value(string $value): bool {
    $value = strtolower(trim($value));
    return in_array($value, ['1', 'yes', 'true', 'explicit'], true);
  }

  private function send_download(string $filename, string $content_type, string $contents): void {
    nocache_headers();
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($contents));
    echo $contents;
    exit;
  }

  private function normalize_publish_at(string $value, string $status): string {
    $value = trim($value);
    if ($value !== '') {
      $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
      $datetime = DateTime::createFromFormat('Y-m-d\TH:i', $value, $timezone);
      if (!$datetime) {
        try {
          $datetime = new DateTime($value, $timezone);
        } catch (\Throwable $e) {
          $datetime = null;
        }
      }
      if ($datetime instanceof DateTime) return $datetime->format('Y-m-d H:i:s');
    }
    if ($status === 'published') return current_time('mysql');
    return '';
  }

  private function datetime_local_value(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $timestamp = $this->podcast_datetime_timestamp($value);
    if (!$timestamp) return '';
    return wp_date('Y-m-d\TH:i', $timestamp, function_exists('wp_timezone') ? wp_timezone() : null);
  }

  private function podcast_title_without_feed_variant(string $title): string {
    $title = trim(sanitize_text_field($title));
    while (preg_match('/\s+\((?:audio|video) feed\)\s*$/i', $title)) {
      $title = trim((string)preg_replace('/\s+\((?:audio|video) feed\)\s*$/i', '', $title));
    }
    return $title;
  }

  private function podcast_datetime_timestamp(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $datetime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($datetime instanceof DateTimeImmutable && (!$errors || (empty($errors['warning_count']) && empty($errors['error_count'])))) {
      return $datetime->getTimestamp();
    }
    try {
      return (new DateTimeImmutable($value, $timezone))->getTimestamp();
    } catch (\Throwable $e) {
      return 0;
    }
  }

  private function verify_nonce(): void {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string)$_POST['_wpnonce'], self::NONCE)) wp_die('Bad nonce.');
  }

  private function redirect_with_notice(int $podcast_id, string $message, string $type = 'success'): void {
    $referer = wp_get_referer();
    if (!$referer) {
      $referer = is_admin() && class_exists('EUM_Access') && EUM_Access::current_user_is_it()
        ? admin_url('admin.php?page=' . self::PAGE_SLUG)
        : home_url('/');
    }
    $args = [
      'eum_podcast_msg' => $message,
      'eum_podcast_type' => sanitize_key($type),
    ];
    if ($podcast_id > 0 && !isset($_GET['podcast_id'])) $args['podcast_id'] = $podcast_id;
    wp_safe_redirect(add_query_arg($args, remove_query_arg(['eum_podcast_msg', 'eum_podcast_type'], $referer)));
    exit;
  }

  private function render_notice(): string {
    if (empty($_GET['eum_podcast_msg'])) return '';
    $message = sanitize_text_field(wp_unslash((string)$_GET['eum_podcast_msg']));
    $type = sanitize_key((string)($_GET['eum_podcast_type'] ?? 'success'));
    $class = $type === 'error' ? 'eum-pod__notice is-error' : 'eum-pod__notice is-success';
    return '<div class="' . esc_attr($class) . '">' . esc_html($message) . '</div>';
  }

  private function handle_media_upload(string $field, int $parent_id = 0, bool $required = true): int {
    if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) return $required ? 0 : 0;
    if (!function_exists('media_handle_upload')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $attachment_id = media_handle_upload($field, $parent_id);
    if (is_wp_error($attachment_id)) return 0;
    return (int)$attachment_id;
  }

  private function sideload_podcast_zip_audio(ZipArchive $zip, string $entry_name, int $podcast_id, int $expected_size) {
    if ($expected_size <= 0) return new WP_Error('eum_podcast_zip_audio_empty', 'The MP3 file is empty.');
    if (!function_exists('media_handle_sideload')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $filename = sanitize_file_name(basename(str_replace('\\', '/', $entry_name)));
    if ($filename === '' || strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)) !== 'mp3') {
      return new WP_Error('eum_podcast_zip_audio_name', 'The archive audio file must have an .mp3 filename.');
    }
    $temp_path = wp_tempnam($filename);
    if (!$temp_path) return new WP_Error('eum_podcast_zip_audio_temp', 'The server could not create a temporary file for this MP3.');

    $input = $zip->getStream($entry_name);
    if (!is_resource($input)) {
      @unlink($temp_path);
      return new WP_Error('eum_podcast_zip_audio_read', 'The MP3 could not be read from the archive.');
    }
    $output = @fopen($temp_path, 'wb');
    if (!is_resource($output)) {
      fclose($input);
      @unlink($temp_path);
      return new WP_Error('eum_podcast_zip_audio_write', 'The server could not stage this MP3 for upload.');
    }
    $copied = stream_copy_to_stream($input, $output);
    fclose($input);
    fclose($output);
    if (!is_int($copied) || $copied !== $expected_size) {
      @unlink($temp_path);
      return new WP_Error('eum_podcast_zip_audio_incomplete', 'The MP3 could not be fully extracted from the archive.');
    }

    $attachment_id = $this->sideload_podcast_mp3_attachment([
      'name' => $filename,
      'type' => 'audio/mpeg',
      'tmp_name' => $temp_path,
      'error' => 0,
      'size' => $copied,
    ], $podcast_id);
    if (file_exists($temp_path)) @unlink($temp_path);
    if (is_wp_error($attachment_id)) return $attachment_id;
    return (int)$attachment_id;
  }

  private function build_itunes_category_xml(string $category): string {
    $category = trim($category);
    if ($category === '') return '';
    $paths = strpos($category, ';') !== false
      ? preg_split('/\s*;\s*/', $category)
      : preg_split('/\s*,\s*/', $category);
    $xml = '';
    foreach ((array)$paths as $path) {
      $parts = array_values(array_filter(array_map('sanitize_text_field', array_map('trim', explode('>', (string)$path)))));
      if (!empty($parts)) $xml .= $this->render_itunes_category_path($parts, 0);
    }
    return $xml;
  }

  private function render_itunes_category_path(array $parts, int $index): string {
    if (!isset($parts[$index])) return '';
    $tag = '<itunes:category text="' . esc_attr((string)$parts[$index]) . '"';
    if (!isset($parts[$index + 1])) return $tag . " />\n";
    return $tag . ">\n" . $this->render_itunes_category_path($parts, $index + 1) . "</itunes:category>\n";
  }

  private function build_feed_xml(int $podcast_id, string $variant = 'audio'): string {
    $podcast = get_post($podcast_id);
    if (!$podcast instanceof WP_Post) return '';
    $variant = in_array($variant, ['audio', 'video'], true) ? $variant : 'audio';
    $settings = $this->podcast_settings($podcast_id);
    $episodes = array_values(array_filter($this->podcast_episode_rows($podcast_id, false), fn($row) => $this->episode_is_public_for_variant($row, $variant)));
    $last_build = !empty($episodes) ? (string)($episodes[0]['publish_at'] ?? current_time('mysql')) : current_time('mysql');
    $site_timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $site_link = $settings['website_url'] !== '' ? (string)$settings['website_url'] : home_url('/');
    $cover_url = (string)($settings['cover_url'] ?? '');
    $category = trim((string)($settings['category'] ?? ''));
    $author = (string)($settings['author'] ?? get_bloginfo('name'));
    $owner_email = (string)($settings['owner_email'] ?? get_bloginfo('admin_email'));
    $description = (string)($settings['description'] ?? '');
    $feed_title = $this->podcast_title_without_feed_variant((string)$podcast->post_title);
    $feed_url = $this->feed_url($podcast_id, $variant);

    ob_start();
    echo '<?xml version="1.0" encoding="' . esc_attr(get_option('blog_charset')) . '"?>';
    ?>
<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?php echo esc_xml($feed_title); ?></title>
    <link><?php echo esc_xml($site_link); ?></link>
    <atom:link href="<?php echo esc_attr($feed_url); ?>" rel="self" type="application/rss+xml" />
    <language><?php echo esc_xml((string)$settings['language']); ?></language>
    <description><?php echo esc_xml(wp_strip_all_tags($description)); ?></description>
    <lastBuildDate><?php echo esc_xml(wp_date(DATE_RSS, $this->podcast_datetime_timestamp($last_build) ?: time(), $site_timezone)); ?></lastBuildDate>
    <?php if (!empty($settings['copyright'])) : ?>
    <copyright><?php echo esc_xml((string)$settings['copyright']); ?></copyright>
    <?php endif; ?>
    <managingEditor><?php echo esc_xml($owner_email); ?></managingEditor>
    <itunes:author><?php echo esc_xml($author); ?></itunes:author>
    <itunes:summary><?php echo esc_xml(wp_strip_all_tags($description)); ?></itunes:summary>
    <itunes:subtitle><?php echo esc_xml((string)$settings['subtitle']); ?></itunes:subtitle>
    <itunes:owner>
      <itunes:name><?php echo esc_xml($author); ?></itunes:name>
      <itunes:email><?php echo esc_xml($owner_email); ?></itunes:email>
    </itunes:owner>
    <itunes:explicit>no</itunes:explicit>
    <?php echo $this->build_itunes_category_xml($category); ?>
    <?php if ($cover_url !== '') : ?>
    <image>
      <url><?php echo esc_xml($cover_url); ?></url>
      <title><?php echo esc_xml($feed_title); ?></title>
      <link><?php echo esc_xml($site_link); ?></link>
    </image>
    <itunes:image href="<?php echo esc_attr($cover_url); ?>" />
    <?php endif; ?>
    <?php foreach ($episodes as $episode) : ?>
      <?php
      $post = $episode['post'];
      if (!$post instanceof WP_Post) continue;
      $media = $this->episode_media_for_variant($episode, $variant);
      if ($media['url'] === '') continue;
      $item_description = wp_strip_all_tags((string)($episode['summary'] ?: $episode['notes']));
      $episode_publish_timestamp = $this->podcast_datetime_timestamp((string)($episode['publish_at'] ?? ''));
      $episode_pub_date = wp_date(DATE_RSS, $episode_publish_timestamp ?: time(), $site_timezone);
      ?>
    <item>
      <title><?php echo esc_xml((string)$post->post_title); ?></title>
      <guid isPermaLink="false"><?php echo esc_xml((string)$episode['guid']); ?></guid>
      <pubDate><?php echo esc_xml($episode_pub_date); ?></pubDate>
      <description><?php echo esc_xml($item_description); ?></description>
      <itunes:summary><?php echo esc_xml($item_description); ?></itunes:summary>
      <?php if ($media['url'] !== '') : ?>
      <enclosure url="<?php echo esc_attr((string)$media['url']); ?>" length="<?php echo esc_attr((string)$media['length']); ?>" type="<?php echo esc_attr((string)$media['mime_type']); ?>" />
      <link><?php echo esc_xml((string)$media['url']); ?></link>
      <?php endif; ?>
      <?php if (!empty($episode['duration'])) : ?>
      <itunes:duration><?php echo esc_xml((string)$episode['duration']); ?></itunes:duration>
      <?php endif; ?>
      <?php if (!empty($episode['season'])) : ?>
      <itunes:season><?php echo (int)$episode['season']; ?></itunes:season>
      <?php endif; ?>
      <?php if (!empty($episode['number'])) : ?>
      <itunes:episode><?php echo (int)$episode['number']; ?></itunes:episode>
      <?php endif; ?>
      <itunes:explicit><?php echo !empty($episode['explicit']) ? 'yes' : 'no'; ?></itunes:explicit>
      <?php if (!empty($episode['notes'])) : ?>
      <content:encoded><![CDATA[<?php echo (string)$episode['notes']; ?>]]></content:encoded>
      <?php endif; ?>
    </item>
    <?php endforeach; ?>
  </channel>
</rss>
    <?php
    return (string)ob_get_clean();
  }

  private function episode_media_for_variant(array $episode, string $variant): array {
    $variant = in_array($variant, ['audio', 'video'], true) ? $variant : 'audio';
    $default_mime = $variant === 'video' ? 'video/mp4' : 'audio/mpeg';
    $attachment_id = (int)($episode[$variant . '_attachment_id'] ?? 0);
    $url = esc_url_raw((string)($episode[$variant . '_url'] ?? ''));
    return [
      'attachment_id' => $attachment_id,
      'url' => $url,
      'length' => $attachment_id > 0 ? $this->attachment_length_bytes($attachment_id) : 0,
      'mime_type' => $attachment_id > 0
        ? $this->attachment_mime_type($attachment_id, $default_mime)
        : $this->mime_type_from_url($url, $default_mime),
    ];
  }

  private function attachment_length_bytes(int $attachment_id): int {
    $attachment_id = (int)$attachment_id;
    if ($attachment_id <= 0) return 0;
    $path = get_attached_file($attachment_id);
    return ($path && file_exists($path)) ? (int)filesize($path) : 0;
  }

  private function attachment_mime_type(int $attachment_id, string $default = 'audio/mpeg'): string {
    $attachment_id = (int)$attachment_id;
    if ($attachment_id <= 0) return $default;
    $mime = (string)get_post_mime_type($attachment_id);
    return $mime !== '' ? $mime : $default;
  }

  private function mime_type_from_url(string $url, string $default): string {
    $path = (string)wp_parse_url($url, PHP_URL_PATH);
    if ($path === '') return $default;
    $filetype = wp_check_filetype($path);
    return !empty($filetype['type']) ? (string)$filetype['type'] : $default;
  }

  private function push_activity(int $podcast_id, int $user_id, string $message): void {
    $rows = (array)get_post_meta($podcast_id, self::META_ACTIVITY, true);
    if (!is_array($rows)) $rows = [];
    $rows[] = [
      'time' => current_time('mysql'),
      'user_id' => $user_id,
      'message' => sanitize_text_field($message),
    ];
    update_post_meta($podcast_id, self::META_ACTIVITY, array_slice($rows, -80));
  }

  private function print_assets(): void {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    ?>
    <style>
      .eum-pod{display:grid;gap:18px;max-width:1240px}
      .eum-pod__hero,.eum-pod__panel,.eum-pod__metric,.eum-pod__podcast-card,.eum-pod__episode-card{background:#fff;border:1px solid #d8dee8;border-radius:20px;box-shadow:0 18px 42px rgba(15,23,42,.08)}
      .eum-pod__hero{display:flex;justify-content:space-between;gap:18px;padding:24px;background:linear-gradient(135deg,#081d35 0%,#10345c 58%,#1f73b7 100%);color:#fff}
      .eum-pod__eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:11px;font-weight:700;opacity:.78}
      .eum-pod__hero h2{margin:6px 0 10px;font-size:32px;line-height:1.05}
      .eum-pod__sub{max-width:720px;color:rgba(255,255,255,.82)}
      .eum-pod__hero-badges{display:flex;flex-wrap:wrap;gap:10px;align-content:flex-start;justify-content:flex-end}
      .eum-pod__badge,.eum-pod__pill{display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:999px;background:rgba(255,255,255,.14);font-size:12px;font-weight:700}
      .eum-pod__cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}
      .eum-pod__metric{padding:18px}
      .eum-pod__metric-value{font-size:26px;font-weight:800;color:#0b2c4f}
      .eum-pod__metric-label{margin-top:6px;color:#576171}
      .eum-pod__panel{padding:20px}
      .eum-pod__panel-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}
      .eum-pod__panel-head h3{margin:0;font-size:20px}
      .eum-pod__panel-head span{color:#5f6978;max-width:520px}
      .eum-pod__grid,.eum-pod__cards,.eum-pod__two-up,.eum-pod__form-grid{display:grid;gap:14px}
      .eum-pod__grid{grid-template-columns:repeat(auto-fit,minmax(250px,1fr))}
      .eum-pod__two-up{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
      .eum-pod__podcast-card,.eum-pod__episode-card{padding:18px}
      .eum-pod__podcast-card-head,.eum-pod__episode-head,.eum-pod__row{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
      .eum-pod__podcast-card h3,.eum-pod__episode-card h4{margin:0}
      .eum-pod__podcast-card h3 a{text-decoration:none}
      .eum-pod__muted,.eum-pod .description{color:#617083}
      .eum-pod__meta,.eum-pod__actions,.eum-pod__tabs,.eum-pod__stack{display:flex;gap:10px;flex-wrap:wrap}
      .eum-pod__meta{margin:12px 0;color:#0c3259;font-size:13px;font-weight:600}
      .eum-pod__actions{margin-top:14px}
      .eum-pod__empty{padding:18px;border:1px dashed #c6cfdb;border-radius:16px;background:#f7f9fc;color:#5d6571}
      .eum-pod__overview{display:grid;grid-template-columns:minmax(220px,280px) 1fr;gap:18px}
      .eum-pod__cover{min-height:220px;border-radius:18px;overflow:hidden;background:#edf3fb;display:flex;align-items:center;justify-content:center}
      .eum-pod__cover img{display:block;width:100%;height:100%;object-fit:cover}
      .eum-pod__cover-fallback{font-weight:800;color:#5f6b79}
      .eum-pod__card-sm{padding:14px 16px;border:1px solid #e0e6ef;border-radius:16px;background:#fbfcfe;flex:1 1 220px}
      .eum-pod__section-title{font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#41516a}
      .eum-pod__tabs{gap:12px}
      .eum-pod__tabs button{appearance:none;border:1px solid #d3dbe8;background:#eef3f9;color:#16395f;border-radius:14px;padding:11px 16px;font-weight:800;cursor:pointer}
      .eum-pod__tabs button.is-active{background:#0d5aa2;color:#fff;border-color:#0d5aa2}
      .eum-pod__tab-panels{display:grid;gap:18px}
      .eum-pod__tab-panels .eum-pod__panel{display:none}
      .eum-pod__tab-panels .eum-pod__panel.is-active{display:block}
      .eum-pod__form{display:grid;gap:14px}
      .eum-pod__form-grid{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
      .eum-pod__form label{display:grid;gap:7px;font-weight:700;color:#19395a}
      .eum-pod__form input[type="text"],.eum-pod__form input[type="email"],.eum-pod__form input[type="url"],.eum-pod__form input[type="number"],.eum-pod__form input[type="datetime-local"],.eum-pod__form select,.eum-pod__form textarea{width:100%;max-width:100%;padding:11px 12px;border:1px solid #ccd5e2;border-radius:12px;background:#fff;color:#1d2530}
      .eum-pod__form select[multiple]{min-height:170px}
      .eum-pod__check{display:flex;align-items:center;gap:10px}
      .eum-pod__check input{margin:0}
      .eum-pod__notice{padding:14px 16px;border-radius:16px;font-weight:700}
      .eum-pod__notice.is-success{background:#ebfff3;border:1px solid #9ad3ad;color:#155b2f}
      .eum-pod__notice.is-error{background:#fff1f1;border:1px solid #efb4b4;color:#8b1e1e}
      .eum-pod__checklist{margin:10px 0 0;padding-left:18px}
      .eum-pod__checklist li{margin-bottom:6px}
      .eum-pod__checklist .is-ok{color:#155b2f}
      .eum-pod__checklist .is-missing{color:#8b1e1e}
      .eum-pod__resource-list{margin:10px 0 0;padding-left:18px;display:grid;gap:6px}
      .eum-pod__resource-list a{text-decoration:none;font-weight:600}
      .eum-pod audio{width:100%;max-width:420px}
      @media (max-width: 900px){
        .eum-pod__hero,.eum-pod__panel-head,.eum-pod__podcast-card-head,.eum-pod__episode-head,.eum-pod__row,.eum-pod__overview{grid-template-columns:1fr;display:grid}
        .eum-pod__hero-badges{justify-content:flex-start}
      }
    </style>
    <script>
      (function(){
        if (window.__eumPodcastTabsLoaded) return;
        window.__eumPodcastTabsLoaded = true;
        document.addEventListener('click', function(event) {
          var trigger = event.target.closest('[data-eum-pod-tab-group]');
          if (!trigger) return;
          var group = trigger.getAttribute('data-eum-pod-tab-group');
          var target = trigger.getAttribute('data-eum-pod-tab-target');
          if (!group || !target) return;
          document.querySelectorAll('[data-eum-pod-tab-group="' + group + '"]').forEach(function(button) {
            button.classList.toggle('is-active', button === trigger);
          });
          document.querySelectorAll('[data-eum-pod-tab-panel="' + group + '"]').forEach(function(panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-eum-pod-tab-key') === target);
          });
        });
      }());
    </script>
    <?php
  }
}
