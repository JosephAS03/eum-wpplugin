<?php
if (!defined('ABSPATH')) exit;

class EUM_Artist_Releases {
  const CPT_ARTIST = 'eum_artist';
  const CPT_RELEASE = 'eum_release';
  const CPT_TRACK = 'eum_track';
  const CPT_APPROVAL = 'eum_artist_approval';

  const META_ARTIST_PROFILE = '_eum_artist_profile';
  const META_TEAM_USERS = '_eum_artist_team_users';
  const META_TEAM_RULES = '_eum_artist_team_rules';
  const META_ROADMAP = '_eum_artist_roadmap';
  const META_ACTIVITY = '_eum_artist_activity';
  const META_ASSETS = '_eum_artist_assets';
  const META_CREATED_BY = '_eum_artist_created_by';

  const META_RELEASE_ARTIST_ID = '_eum_release_artist_id';
  const META_RELEASE_TYPE = '_eum_release_type';
  const META_RELEASE_DATE = '_eum_release_date';
  const META_RELEASE_STATUS = '_eum_release_status';
  const META_RELEASE_BUDGET = '_eum_release_budget';
  const META_RELEASE_COST_CENTER_TERM_ID = '_eum_release_cost_center_term_id';
  const META_RELEASE_INFO = '_eum_release_info';
  const META_RELEASE_PROMO_STRATEGY = '_eum_release_promo_strategy';
  const META_RELEASE_TRACKS = '_eum_release_tracks';
  const META_RELEASE_ACTIVITY = '_eum_release_activity';
  const META_RELEASE_ASSETS = '_eum_release_assets';
  const META_RELEASE_SYNC_SOURCE = '_eum_release_sync_source_track_id';

  const META_TRACK_ARTIST_ID = '_eum_track_artist_id';
  const META_TRACK_INFO = '_eum_track_info';
  const META_TRACK_PROMO_STRATEGY = '_eum_track_promo_strategy';
  const META_TRACK_LINKED_RELEASES = '_eum_track_linked_release_ids';
  const META_TRACK_ASSETS = '_eum_track_assets';
  const META_TRACK_ACTIVITY = '_eum_track_activity';

  const ASSET_GROUP_GENERAL = 'general';
  const ASSET_GROUP_COVER = 'cover';
  const ASSET_GROUP_PROMO = 'promo_material';
  const ASSET_GROUP_AUDIO = 'audio';
  const ASSET_GROUP_PROJECT = 'project_file';

  const META_APPROVAL_CONTEXT = '_eum_ar_context';
  const META_APPROVAL_STATUS = '_eum_ar_status';
  const META_APPROVAL_PAYLOAD = '_eum_ar_payload';
  const META_APPROVAL_OBJECT_TYPE = '_eum_ar_object_type';
  const META_APPROVAL_OBJECT_ID = '_eum_ar_object_id';
  const META_APPROVAL_ARTIST_ID = '_eum_ar_artist_id';
  const META_APPROVAL_PROPOSED_BY = '_eum_ar_proposed_by';
  const META_APPROVAL_APPROVER_IDS = '_eum_ar_approver_ids';
  const META_APPROVAL_APPROVER_ID = '_eum_ar_approver_id';
  const META_APPROVAL_COMMENTS = '_eum_ar_comments';

  const META_EXPENSE_LINES = '_eum_ar_expense_lines';

  const NONCE = 'eum_artist_release_action';

  public function init(): void {
    add_action('init', [$this, 'register_post_types']);
    add_shortcode('eum_artists_portal', [$this, 'shortcode_portal']);
    add_shortcode('eum_artist_workspace', [$this, 'shortcode_artist_workspace']);
    add_shortcode('eum_release_workspace', [$this, 'shortcode_release_workspace']);

    add_action('admin_post_eum_ar_create_artist', [$this, 'handle_create_artist']);
    add_action('admin_post_eum_ar_save_artist', [$this, 'handle_save_artist']);
    add_action('admin_post_eum_ar_add_release', [$this, 'handle_add_release']);
    add_action('admin_post_eum_ar_save_release', [$this, 'handle_save_release']);
    add_action('admin_post_eum_ar_add_track', [$this, 'handle_add_track']);
    add_action('admin_post_eum_ar_save_track', [$this, 'handle_save_track']);
    add_action('admin_post_eum_ar_add_roadmap', [$this, 'handle_add_roadmap']);
    add_action('admin_post_eum_ar_upload_asset', [$this, 'handle_upload_asset']);
    add_action('admin_post_eum_ar_restore_asset', [$this, 'handle_restore_asset']);
    add_action('admin_post_eum_ar_submit_expense', [$this, 'handle_submit_expense']);
    add_action('admin_post_eum_ar_approval', [$this, 'handle_approval']);
    add_action('admin_post_eum_ar_set_rep_rule', [$this, 'handle_set_rep_rule']);
    add_action('admin_post_eum_ar_add_team_user', [$this, 'handle_add_team_user']);
    add_action('admin_post_eum_ar_remove_team_user', [$this, 'handle_remove_team_user']);
    add_action('admin_post_eum_ar_add_team_rule', [$this, 'handle_add_team_rule']);
    add_action('admin_post_eum_ar_remove_team_rule', [$this, 'handle_remove_team_rule']);

    add_action('transition_post_status', [$this, 'sync_track_release_links'], 10, 3);
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

    register_post_type(self::CPT_ARTIST, array_merge($common, [
      'labels' => ['name' => 'Artists', 'singular_name' => 'Artist'],
    ]));
    register_post_type(self::CPT_RELEASE, array_merge($common, [
      'labels' => ['name' => 'Releases', 'singular_name' => 'Release'],
    ]));
    register_post_type(self::CPT_TRACK, array_merge($common, [
      'labels' => ['name' => 'Tracks', 'singular_name' => 'Track'],
    ]));
    register_post_type(self::CPT_APPROVAL, array_merge($common, [
      'labels' => ['name' => 'A&R Approvals', 'singular_name' => 'A&R Approval'],
      'supports' => ['title', 'author'],
    ]));
  }

  public function shortcode_portal($atts = []): string {
    try {
      $user_id = (int)get_current_user_id();
      if ($user_id <= 0) return '';
      $artist_id = isset($_GET['artist_id']) ? (int)$_GET['artist_id'] : 0;
      $release_id = isset($_GET['release_id']) ? (int)$_GET['release_id'] : 0;

      if ($release_id > 0) {
        return $this->render_release_workspace($release_id, $user_id);
      }
      if ($artist_id > 0) {
        return $this->render_artist_workspace_v2($artist_id, $user_id);
      }
      return $this->render_directory_v2($user_id);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Artists portal] '.$e->getMessage());
      return $this->render_runtime_error('Artists Portal', $e);
    }
  }

  public function shortcode_artist_workspace($atts = []): string {
    try {
      $atts = shortcode_atts(['id' => 0], $atts, 'eum_artist_workspace');
      $artist_id = (int)$atts['id'];
      if ($artist_id <= 0 && isset($_GET['artist_id'])) $artist_id = (int)$_GET['artist_id'];
      return $artist_id > 0 ? $this->render_artist_workspace_v2($artist_id, (int)get_current_user_id()) : $this->render_directory_v2((int)get_current_user_id());
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Artist workspace] '.$e->getMessage());
      return $this->render_runtime_error('Artist Workspace', $e);
    }
  }

  public function shortcode_release_workspace($atts = []): string {
    try {
      $atts = shortcode_atts(['id' => 0], $atts, 'eum_release_workspace');
      $release_id = (int)$atts['id'];
      if ($release_id <= 0 && isset($_GET['release_id'])) $release_id = (int)$_GET['release_id'];
      return $release_id > 0 ? $this->render_release_workspace($release_id, (int)get_current_user_id()) : '<div class="eum-ar-empty">Select a release.</div>';
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Release workspace] '.$e->getMessage());
      return $this->render_runtime_error('Release Workspace', $e);
    }
  }

  private function render_runtime_error(string $area, \Throwable $e): string {
    $detail = (defined('WP_DEBUG') && WP_DEBUG) ? '<div class="description" style="margin-top:8px">'.esc_html($e->getMessage()).'</div>' : '';
    return '<div class="eum-ar"><div class="eum-ar__panel"><h3>'.esc_html($area).' temporarily unavailable.</h3><p>A runtime error was caught so the rest of the site can continue loading.</p>'.$detail.'</div></div>';
  }

  private function can_create_artist(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($user_id) || EUM_Access::is_artist_creator($user_id)) return true;
    return $this->user_is_representative_anywhere($user_id);
  }

  private function user_is_representative_anywhere(int $user_id): bool {
    if ($user_id <= 0) return false;
    $artist_ids = get_posts([
      'post_type' => self::CPT_ARTIST,
      'post_status' => 'publish',
      'numberposts' => 300,
      'fields' => 'ids',
    ]);
    foreach ((array)$artist_ids as $artist_id) {
      if ($this->user_has_artist_role((int)$artist_id, $user_id, 'representative')) {
        return true;
      }
    }
    return false;
  }

  private function can_view_artist(int $artist_id, int $user_id): bool {
    if ($artist_id <= 0 || $user_id <= 0) return false;
    if (EUM_Access::is_it_user($user_id)) return true;
    if ($this->user_role_for_artist($artist_id, $user_id) !== 'none') return true;
    $created_by = (int)get_post_meta($artist_id, self::META_CREATED_BY, true);
    return $created_by > 0 && $created_by === $user_id;
  }

  private function user_roles_for_artist(int $artist_id, int $user_id): array {
    if ($user_id <= 0 || $artist_id <= 0) return [];

    $roles = [];
    if (EUM_Access::is_it_user($user_id)) $roles['manager'] = 'manager';
    if (EUM_Access::is_finance_user($user_id)) $roles['finance'] = 'finance';

    foreach ($this->artist_team_users($artist_id) as $row) {
      if ((int)($row['user_id'] ?? 0) !== $user_id) continue;
      $role = $this->normalize_team_role((string)($row['role'] ?? 'member'));
      $roles[$role] = $role;
    }

    foreach ($this->artist_team_rules($artist_id) as $rule) {
      if (!$this->user_matches_artist_rule($user_id, $rule)) continue;
      $role = $this->normalize_team_role((string)($rule['role'] ?? 'member'));
      $roles[$role] = $role;
    }

    if (in_array($user_id, $this->artist_manager_user_ids($artist_id), true)) {
      $roles['manager'] = 'manager';
    }

    return array_values($roles);
  }

  private function user_has_artist_role(int $artist_id, int $user_id, string $role): bool {
    return in_array($this->normalize_team_role($role), $this->user_roles_for_artist($artist_id, $user_id), true);
  }

  private function user_role_for_artist(int $artist_id, int $user_id): string {
    return $this->highest_artist_role($this->user_roles_for_artist($artist_id, $user_id));
  }

  private function normalize_team_role(string $role): string {
    $role = sanitize_key($role);
    if (!in_array($role, ['member','representative','manager','finance'], true)) $role = 'member';
    return $role;
  }

  private function max_role(string $left, string $right): string {
    $weight = ['none' => 0, 'member' => 1, 'representative' => 2, 'manager' => 3, 'finance' => 4];
    return ($weight[$right] ?? 0) > ($weight[$left] ?? 0) ? $right : $left;
  }

  private function highest_artist_role(array $roles): string {
    $best = 'none';
    foreach ($roles as $role) {
      $best = $this->max_role($best, $this->normalize_team_role((string)$role));
    }
    return $best;
  }

  private function user_matches_artist_rule(int $user_id, array $rule): bool {
    $job_term_id = (int)($rule['job_path_term_id'] ?? 0);
    $title = $this->normalize_artist_job_title((string)($rule['job_title'] ?? ''));
    if ($job_term_id <= 0) return false;

    if ($title !== '') {
      if ($this->current_user_job_path_term_id($user_id) !== $job_term_id) return false;
    } elseif (!in_array($job_term_id, $this->user_job_path_ids($user_id), true)) {
      return false;
    }

    if ($title === '') return true;

    $user_title_value = $this->user_job_title_value($user_id);
    if ($user_title_value !== '' && strcasecmp($user_title_value, $title) === 0) return true;

    $user_title_label = $this->user_job_title_label($user_id);
    $rule_title_label = $this->job_title_label_from_value($title);
    return $user_title_label !== '' && $rule_title_label !== '' && strcasecmp($user_title_label, $rule_title_label) === 0;
  }

  private function user_job_path_ids(int $user_id): array {
    $assigned = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($assigned) || empty($assigned)) return [];
    $leaf = (int)$assigned[0];
    return array_values(array_unique(array_filter(array_merge([$leaf], array_map('intval', get_ancestors($leaf, 'eum_job_path'))))));
  }

  private function current_user_job_path_term_id(int $user_id): int {
    $assigned = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($assigned) || empty($assigned)) return 0;
    return (int)$assigned[0];
  }

  private function user_job_title_value(int $user_id): string {
    if (!class_exists('EUM_Fields')) return '';
    $all = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    return $this->normalize_artist_job_title((string)($all['job_title'] ?? ''));
  }

  private function normalize_artist_job_title(string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (class_exists('EUM_Job_Titles') && method_exists('EUM_Job_Titles', 'id_from_token')) {
      $title_id = (int)EUM_Job_Titles::id_from_token($value);
      if ($title_id > 0 && method_exists('EUM_Job_Titles', 'token_from_id')) {
        return (string)EUM_Job_Titles::token_from_id($title_id);
      }
    }
    return sanitize_text_field($value);
  }

  private function job_title_label_from_value(string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (class_exists('EUM_Job_Titles') && method_exists('EUM_Job_Titles', 'id_from_token') && method_exists('EUM_Job_Titles', 'title_label')) {
      $title_id = (int)EUM_Job_Titles::id_from_token($value);
      if ($title_id > 0) {
        return sanitize_text_field((string)EUM_Job_Titles::title_label($title_id));
      }
    }
    return sanitize_text_field($value);
  }

  private function is_valid_job_title_for_job_term(int $job_path_term_id, string $job_title, bool $allow_blank = false): bool {
    $job_path_term_id = (int)$job_path_term_id;
    $job_title = $this->normalize_artist_job_title($job_title);
    if ($job_title === '') return $allow_blank;
    if ($job_path_term_id <= 0) return false;
    if (class_exists('EUM_Job_Titles') && method_exists('EUM_Job_Titles', 'id_from_token') && method_exists('EUM_Job_Titles', 'is_title_allowed_for_job_term')) {
      $title_id = (int)EUM_Job_Titles::id_from_token($job_title);
      if ($title_id > 0) return EUM_Job_Titles::is_title_allowed_for_job_term($title_id, $job_path_term_id);
    }
    return true;
  }

  private function user_job_title_label(int $user_id): string {
    return $this->job_title_label_from_value($this->user_job_title_value($user_id));
  }

  private function representative_rules(int $artist_id): array {
    return array_values(array_filter($this->artist_team_rules($artist_id), fn($rule) => ($rule['role'] ?? '') === 'representative'));
  }

  private function primary_representative_rule(int $artist_id): array {
    $rules = $this->representative_rules($artist_id);
    return !empty($rules) ? (array)$rules[0] : [];
  }

  private function resolved_representatives(int $artist_id): array {
    $user_ids = [];
    foreach ($this->artist_team_users($artist_id) as $row) {
      if ($this->normalize_team_role((string)($row['role'] ?? '')) === 'representative') {
        $user_ids[] = (int)$row['user_id'];
      }
    }
    $all_users = $this->users_matching_rules($artist_id);
    foreach ($all_users as $user_id => $role) {
      if ($role === 'representative') $user_ids[] = (int)$user_id;
    }
    return array_values(array_unique(array_filter(array_map('intval', $user_ids))));
  }

  private function artist_manager_user_ids(int $artist_id): array {
    $manager_user_ids = [];
    $representatives = $this->resolved_representatives($artist_id);

    if (!empty($representatives) && class_exists('EUM_Manager_IDs')) {
      foreach ($representatives as $rep_user_id) {
        $manager_user_ids = array_merge($manager_user_ids, EUM_Manager_IDs::manager_user_ids_for_subject_user((int)$rep_user_id));
      }
    } elseif (!empty($representatives) && class_exists('EUM_Personnel')) {
      foreach ($representatives as $rep_user_id) {
        $manager_id = (int)get_user_meta((int)$rep_user_id, EUM_Personnel::META_MANAGER_ID, true);
        if ($manager_id > 0) $manager_user_ids[] = $manager_id;
      }
    }

    if (empty($manager_user_ids)) {
      $rep_rule = $this->primary_representative_rule($artist_id);
      $job_path_term_id = (int)($rep_rule['job_path_term_id'] ?? 0);
      if ($job_path_term_id <= 0) {
        $job_path_term_id = $this->artist_job_path_term_id($artist_id);
      }
      if ($job_path_term_id > 0) {
        $manager_user_ids = array_merge($manager_user_ids, $this->manager_users_for_job_path($job_path_term_id));
      }
    }

    return array_values(array_unique(array_filter(array_map('intval', $manager_user_ids))));
  }

  private function manager_users_for_job_path(int $job_path_term_id): array {
    if ($job_path_term_id <= 0 || !class_exists('EUM_Manager_IDs')) return [];
    $manager_ids = [];
    $check_term_ids = array_values(array_unique(array_filter(array_merge([$job_path_term_id], array_map('intval', get_ancestors($job_path_term_id, 'eum_job_path'))))));
    foreach ($check_term_ids as $term_id) {
      $manager_ids = array_merge($manager_ids, EUM_Manager_IDs::manager_ids_for_term('eum_job_path', (int)$term_id));
    }

    $user_ids = [];
    foreach (array_values(array_unique(array_map('intval', $manager_ids))) as $manager_id_post) {
      $user_ids = array_merge($user_ids, EUM_Manager_IDs::manager_users_for_id((int)$manager_id_post));
    }
    return array_values(array_unique(array_filter(array_map('intval', $user_ids))));
  }

  private function users_matching_rules(int $artist_id): array {
    $out = [];
    $users = get_users(['fields' => ['ID']]);
    foreach ($users as $user) {
      $uid = (int)$user->ID;
      foreach ($this->artist_team_rules($artist_id) as $rule) {
        if ($this->user_matches_artist_rule($uid, $rule)) {
          $out[$uid] = $this->max_role($out[$uid] ?? 'none', $this->normalize_team_role((string)($rule['role'] ?? 'member')));
        }
      }
    }
    return $out;
  }

  private function can_manage_artist(int $artist_id, int $user_id): bool {
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    return !empty(array_intersect($roles, ['representative','manager','finance'])) || (int)get_post_meta($artist_id, self::META_CREATED_BY, true) === $user_id || EUM_Access::is_it_user($user_id);
  }

  private function can_manage_money(int $artist_id, int $user_id): bool {
    return $this->user_has_artist_role($artist_id, $user_id, 'manager') || $this->user_has_artist_role($artist_id, $user_id, 'finance') || EUM_Access::is_finance_user($user_id);
  }

  private function can_submit_money(int $artist_id, int $user_id): bool {
    return !empty(array_intersect($this->user_roles_for_artist($artist_id, $user_id), ['representative','manager','finance']));
  }

  private function render_artist_card(array $row): string {
    $url = esc_url(add_query_arg(['artist_id' => (int)($row['ID'] ?? 0)], remove_query_arg(['release_id'])));
    ob_start();
    echo '<article class="eum-ar__artist-card">';
    echo '<div class="eum-ar__artist-card-head"><div><h4><a href="' . $url . '">' . esc_html((string)($row['title'] ?? 'Artist')) . '</a></h4><div class="description">' . esc_html((string)($row['division'] ?? 'Unassigned')) . '</div></div><span class="eum-ar__pill eum-ar__pill--status">' . esc_html(ucfirst((string)($row['status'] ?? 'active'))) . '</span></div>';
    echo '<dl class="eum-ar__kv">';
    echo '<div><dt>Owner</dt><dd>' . esc_html((string)($row['lead_rep'] ?? 'Unassigned')) . '</dd></div>';
    echo '<div><dt>Next Release</dt><dd>' . esc_html((string)($row['next_release'] ?? 'TBD')) . '</dd></div>';
    echo '<div><dt>Pending</dt><dd>' . (int)($row['pending_approvals'] ?? 0) . '</dd></div>';
    echo '</dl>';
    echo '<div class="eum-ar__artist-card-actions"><a class="button button-primary" href="' . $url . '">Open Workspace</a></div>';
    echo '</article>';
    return ob_get_clean();
  }

  private function render_identity_summary(array $identity, string $title): string {
    $path_label = (string)($identity['job_path_label'] ?? '');
    $title_label = (string)($identity['job_title_label'] ?? '');
    $valid = !empty($identity['is_valid']);
    ob_start();
    echo '<div class="eum-ar__identity ' . ($valid ? '' : 'is-incomplete') . '"><strong>' . esc_html($title) . '</strong><div>' . esc_html($path_label !== '' ? $path_label : 'No Job Path selected') . '</div><div class="description">' . esc_html($title_label !== '' ? $title_label : 'No Job Title selected') . '</div></div>';
    return ob_get_clean();
  }

  private function render_role_badges(array $roles): string {
    if (empty($roles)) return '<span class="eum-ar__badge">Role: Viewer</span>';
    $html = '';
    foreach ($roles as $role) {
      $html .= '<span class="eum-ar__badge">Role: ' . esc_html(ucfirst((string)$role)) . '</span>';
    }
    return $html;
  }

  private function render_media_thumb(?array $asset, string $empty_text, string $accent_text = ''): string {
    ob_start();
    echo '<div class="eum-ar__media-thumb">';
    if ($asset && !empty($asset['url']) && $this->asset_is_image($asset)) {
      echo '<img src="' . esc_url((string)$asset['url']) . '" alt="">';
    } elseif ($asset && !empty($asset['filename'])) {
      echo '<div class="eum-ar__media-placeholder"><strong>' . esc_html((string)$asset['filename']) . '</strong><span>' . esc_html((string)$asset['asset_kind']) . '</span></div>';
    } else {
      echo '<div class="eum-ar__media-placeholder"><strong>' . esc_html($empty_text) . '</strong>';
      if ($accent_text !== '') echo '<span>' . esc_html($accent_text) . '</span>';
      echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_attachment_list(array $assets, string $empty_text): string {
    ob_start();
    if (empty($assets)) {
      echo '<div class="eum-ar__empty eum-ar__empty--tight">' . esc_html($empty_text) . '</div>';
      return ob_get_clean();
    }

    echo '<div class="eum-ar__file-list">';
    foreach ($assets as $asset) {
      echo '<div class="eum-ar__file-chip">';
      echo '<div><strong>' . esc_html((string)($asset['asset_kind'] ?: $asset['filename'])) . '</strong>';
      if (!empty($asset['notes'])) echo '<div class="description">' . esc_html((string)$asset['notes']) . '</div>';
      echo '</div>';
      if (!empty($asset['url'])) {
        echo '<a class="button button-small" href="' . esc_url((string)$asset['url']) . '" target="_blank" rel="noopener">Download</a>';
      }
      echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_audio_player(?array $asset): string {
    ob_start();
    if (!$asset || empty($asset['url'])) {
      echo '<div class="eum-ar__empty eum-ar__empty--tight">No track upload yet.</div>';
      return ob_get_clean();
    }

    echo '<div class="eum-ar__audio">';
    echo '<audio controls preload="none" src="' . esc_url((string)$asset['url']) . '"></audio>';
    echo '<div class="eum-ar__audio-meta"><strong>' . esc_html((string)($asset['filename'] ?: 'Track Upload')) . '</strong>';
    echo '<a href="' . esc_url((string)$asset['url']) . '" target="_blank" rel="noopener">Open file</a></div>';
    echo '</div>';
    return ob_get_clean();
  }

  private function normalize_strategy_input($value): array {
    $lines = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string)$value);
    if (!is_array($lines)) $lines = [];
    $out = [];
    foreach ($lines as $line) {
      $line = sanitize_textarea_field((string)$line);
      if ($line === '') continue;
      $out[] = $line;
    }
    return array_values(array_unique($out));
  }

  private function strategy_textarea_value($value): string {
    return implode("\n", $this->normalize_strategy_input($value));
  }

  private function render_strategy_list($value, string $empty_text): string {
    $strategies = $this->normalize_strategy_input($value);
    ob_start();
    if (empty($strategies)) {
      echo '<div class="eum-ar__empty eum-ar__empty--tight">' . esc_html($empty_text) . '</div>';
      return ob_get_clean();
    }

    echo '<div class="eum-ar__strategy-list">';
    foreach ($strategies as $strategy) {
      echo '<div class="eum-ar__strategy-chip">' . esc_html($strategy) . '</div>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_release_management_card(WP_Post $release, int $artist_id, int $user_id, bool $expanded = false): string {
    $release_id = (int)$release->ID;
    $tab_group = 'release-card-' . $release_id;
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $highest_role = $this->highest_artist_role($roles);
    $can_edit = !empty($roles) || $this->can_manage_artist($artist_id, $user_id);
    $type = (string)get_post_meta($release_id, self::META_RELEASE_TYPE, true);
    $date = (string)get_post_meta($release_id, self::META_RELEASE_DATE, true);
    $status = (string)get_post_meta($release_id, self::META_RELEASE_STATUS, true);
    $budget = (float)get_post_meta($release_id, self::META_RELEASE_BUDGET, true);
    $cost_center_term_id = $this->release_cost_center_term_id($release_id);
    $cost_center_label = $this->cost_center_label($cost_center_term_id);
    $cost_center_budget = $this->cost_center_budget($cost_center_term_id);
    $info = (string)get_post_meta($release_id, self::META_RELEASE_INFO, true);
    $promo_strategy = get_post_meta($release_id, self::META_RELEASE_PROMO_STRATEGY, true);
    $tracks = $this->release_tracks($release_id);
    $cover = $this->release_cover_asset($release_id);
    $promo_assets = $this->object_assets_by_group($release_id, self::META_RELEASE_ASSETS, self::ASSET_GROUP_PROMO, true);
    $open_url = esc_url(add_query_arg(['artist_id' => $artist_id, 'release_id' => $release_id], remove_query_arg(['track_id'])));

    ob_start();
    echo '<article class="eum-ar__manage-card eum-ar__manage-card--release' . ($expanded ? ' is-expanded' : '') . '">';
    echo '<div class="eum-ar__manage-media">';
    echo $this->render_media_thumb($cover, 'No cover uploaded yet.', 'Upload album artwork to brand this release.');
    echo '</div>';
    echo '<div class="eum-ar__manage-main">';
    echo '<div class="eum-ar__manage-head">';
    echo '<div><h3>' . esc_html($release->post_title) . '</h3><div class="description">' . esc_html(strtoupper($type ?: 'single')) . ' &middot; ' . esc_html($date !== '' ? $date : 'No date scheduled') . ' &middot; ' . esc_html($status !== '' ? ucfirst($status) : 'Planning') . '</div></div>';
    echo '<div class="eum-ar__actions"><span class="eum-ar__pill eum-ar__pill--status">' . esc_html(ucfirst($status ?: 'planning')) . '</span><a class="button button-small" href="' . $open_url . '">Open Release</a></div>';
    echo '</div>';
    echo '<nav class="eum-ar__subtabs">';
    echo '<button type="button" class="is-active" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="overview">Overview</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="strategies">Strategies</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="files">Files</button>';
    echo '</nav>';

    if ($can_edit) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact eum-ar__manage-form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_save_release"><input type="hidden" name="release_id" value="' . $release_id . '">';
    }

    echo '<div class="eum-ar__option-panel is-active" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="overview">';
    echo '<div class="eum-ar__meta-grid">';
    echo '<div class="eum-ar__meta-cell"><span>Budget</span><strong>' . esc_html($this->format_money($budget)) . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Cost Center</span><strong>' . esc_html($cost_center_label !== '' ? $cost_center_label : 'Not assigned') . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Tracks</span><strong>' . count($tracks) . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Singles Linked</span><strong>' . (int)$this->count_release_single_links($release_id) . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Promo Files</span><strong>' . count($promo_assets) . '</strong></div>';
    if ($cost_center_budget > 0) {
      echo '<div class="eum-ar__meta-cell"><span>Cost Center Budget</span><strong>' . esc_html($this->format_money($cost_center_budget)) . '</strong></div>';
    }
    echo '</div>';
    echo '<div class="eum-ar__detail-block"><strong>Release Notes</strong><p>' . esc_html($info !== '' ? wp_trim_words(wp_strip_all_tags($info), 28, '...') : 'No release notes yet.') . '</p></div>';
    if ($can_edit) {
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Release Title<input type="text" name="release_title" value="' . esc_attr($release->post_title) . '" required></label>';
      echo '<label>Type<select name="release_type">' . $this->select_options(['single' => 'Single', 'ep' => 'EP', 'lp' => 'LP / Album'], $type) . '</select></label>';
      echo '<label>Release Date<input type="date" name="release_date" value="' . esc_attr($date) . '"></label>';
      echo '<label>Status<select name="release_status">' . $this->select_options(['planning' => 'Planning', 'recording' => 'Recording', 'awaiting_art' => 'Awaiting Art', 'scheduled' => 'Scheduled', 'released' => 'Released'], $status) . '</select></label>';
      echo '<label>Budget<input type="number" step="0.01" name="release_budget" value="' . esc_attr((string)$budget) . '"></label>';
      echo '<label>Cost Center<select name="release_cost_center_term_id">' . $this->cost_center_options_html($cost_center_term_id, true, 'No release cost center') . '</select></label>';
      echo '<label>Add Existing Track<select name="append_track_id"><option value="0">None</option>' . $this->track_options_html($artist_id) . '</select></label>';
      echo '<label>Mark added track as single<select name="append_track_single"><option value="0">No</option><option value="1">Yes</option></select></label>';
      echo '<label>Album Cover<input type="file" name="release_cover_file" accept="image/*"></label>';
      echo '</div>';
      echo '<label>Release Notes<textarea name="release_info" rows="4">' . esc_textarea($info) . '</textarea></label>';
    }
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="strategies">';
    echo '<div class="eum-ar__detail-block"><strong>Promotional Strategies</strong>' . $this->render_strategy_list($promo_strategy, 'No promotional strategies saved yet.') . '</div>';
    if ($can_edit) {
      echo '<label>Strategies<textarea name="release_promo_strategies" rows="6" placeholder="One strategy per line">' . esc_textarea($this->strategy_textarea_value($promo_strategy)) . '</textarea></label>';
    }
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="files">';
    echo '<div class="eum-ar__detail-grid">';
    echo '<div class="eum-ar__detail-block"><strong>Album Cover</strong>' . $this->render_media_thumb($cover, 'No cover uploaded yet.', 'Upload album artwork for this release.') . '</div>';
    echo '<div class="eum-ar__detail-block"><strong>Promotional Material</strong>' . $this->render_attachment_list($promo_assets, 'No promotional material uploaded yet.') . '</div>';
    echo '</div>';
    if ($can_edit) {
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Promo Material Files<input type="file" name="release_promo_material_files[]" multiple></label>';
      echo '<label>Promo Material Label<input type="text" name="release_promo_material_label" placeholder="Press kit, ad set, social pack"></label>';
      echo '<label>Promo Material Notes<input type="text" name="release_promo_material_notes" placeholder="Optional notes"></label>';
      echo '</div>';
    }
    echo '</div>';

    if ($can_edit) {
      echo '<button class="button">' . esc_html(in_array($highest_role, ['representative', 'manager', 'finance'], true) || $this->can_manage_artist($artist_id, $user_id) ? 'Save Release' : 'Propose Release Update') . '</button>';
      echo '</form>';
    }

    echo '</div>';
    echo '</article>';
    return ob_get_clean();
  }

  private function render_track_management_card(WP_Post $track, int $artist_id, int $user_id, bool $expanded = false): string {
    $track_id = (int)$track->ID;
    $tab_group = 'track-card-' . $track_id;
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $highest_role = $this->highest_artist_role($roles);
    $can_edit = !empty($roles) || $this->can_manage_artist($artist_id, $user_id);
    $info = (array)get_post_meta($track_id, self::META_TRACK_INFO, true);
    $promo_strategy = get_post_meta($track_id, self::META_TRACK_PROMO_STRATEGY, true);
    $linked_release_ids = $this->track_linked_release_ids($track_id);
    $cover = $this->track_cover_asset($track_id);
    $direct_cover = $this->latest_asset_by_group($track_id, self::META_TRACK_ASSETS, self::ASSET_GROUP_COVER, true);
    $audio = $this->track_primary_audio_asset($track_id);
    $project = $this->track_primary_project_asset($track_id);
    $promo_assets = $this->object_assets_by_group($track_id, self::META_TRACK_ASSETS, self::ASSET_GROUP_PROMO, true);
    $linked_release_labels = [];
    foreach ($linked_release_ids as $release_id) {
      $linked_release_labels[] = get_the_title((int)$release_id);
    }

    ob_start();
    echo '<article class="eum-ar__manage-card eum-ar__manage-card--track' . ($expanded ? ' is-expanded' : '') . '">';
    echo '<div class="eum-ar__manage-media">';
    echo $this->render_media_thumb($cover, 'No artwork yet.', $direct_cover ? 'Custom track artwork' : 'Inheriting the first linked release image.');
    echo '</div>';
    echo '<div class="eum-ar__manage-main">';
    echo '<div class="eum-ar__manage-head">';
    echo '<div><h3>' . esc_html($track->post_title) . '</h3><div class="description">' . esc_html(ucfirst((string)($info['type'] ?? 'master'))) . ' &middot; ' . esc_html(ucfirst((string)($info['status'] ?? 'draft'))) . '</div></div>';
    echo '<div class="eum-ar__actions"><span class="eum-ar__pill">' . ($direct_cover ? 'Custom Cover' : 'Release Cover Inherited') . '</span></div>';
    echo '</div>';
    echo '<nav class="eum-ar__subtabs">';
    echo '<button type="button" class="is-active" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="overview">Overview</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="strategies">Strategies</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="files">Files</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="audio">Audio</button>';
    echo '</nav>';

    if ($can_edit) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact eum-ar__manage-form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_save_track"><input type="hidden" name="track_id" value="' . $track_id . '">';
      echo '<input type="hidden" name="linked_release_ids[]" value="">';
    }

    echo '<div class="eum-ar__option-panel is-active" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="overview">';
    echo '<div class="eum-ar__meta-grid">';
    echo '<div class="eum-ar__meta-cell"><span>Linked Releases</span><strong>' . count($linked_release_ids) . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Promo Files</span><strong>' . count($promo_assets) . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Audio</span><strong>' . ($audio ? 'Uploaded' : 'Pending') . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Project</span><strong>' . ($project ? 'Uploaded' : 'Pending') . '</strong></div>';
    echo '</div>';
    echo '<div class="eum-ar__detail-grid">';
    echo '<div class="eum-ar__detail-block"><strong>Linked Releases</strong><p>' . esc_html(!empty($linked_release_labels) ? implode(', ', array_filter($linked_release_labels)) : 'Not linked to a release yet.') . '</p></div>';
    echo '<div class="eum-ar__detail-block"><strong>Track Notes</strong><p>' . esc_html(!empty($info['notes']) ? wp_trim_words(wp_strip_all_tags((string)$info['notes']), 28, '...') : 'No track notes yet.') . '</p></div>';
    echo '</div>';
    if ($can_edit) {
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Track Title<input type="text" name="track_title" value="' . esc_attr($track->post_title) . '" required></label>';
      echo '<label>Type<select name="track_type">' . $this->select_options(['master' => 'Master', 'acoustic' => 'Acoustic', 'demo' => 'Demo'], (string)($info['type'] ?? 'master')) . '</select></label>';
      echo '<label>Status<select name="track_status">' . $this->select_options(['draft' => 'Draft', 'mix_review' => 'Mix review', 'approved' => 'Approved'], (string)($info['status'] ?? 'draft')) . '</select></label>';
      echo '<label>Linked Releases<select name="linked_release_ids[]" multiple size="5">' . $this->release_options_html($artist_id, $linked_release_ids) . '</select></label>';
      echo '<label>Album Cover<input type="file" name="track_cover_file" accept="image/*"></label>';
      echo '</div>';
      echo '<label>Track Notes<textarea name="track_info" rows="4">' . esc_textarea((string)($info['notes'] ?? '')) . '</textarea></label>';
    }
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="strategies">';
    echo '<div class="eum-ar__detail-block"><strong>Promotional Strategies</strong>' . $this->render_strategy_list($promo_strategy, 'No promotional strategies saved yet.') . '</div>';
    if ($can_edit) {
      echo '<label>Strategies<textarea name="track_promo_strategies" rows="6" placeholder="One strategy per line">' . esc_textarea($this->strategy_textarea_value($promo_strategy)) . '</textarea></label>';
    }
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="files">';
    echo '<div class="eum-ar__detail-grid">';
    echo '<div class="eum-ar__detail-block"><strong>Album Cover</strong>' . $this->render_media_thumb($cover, 'No artwork yet.', $direct_cover ? 'Custom track artwork' : 'Inheriting the first linked release image.') . '</div>';
    echo '<div class="eum-ar__detail-block"><strong>Promotional Material</strong>' . $this->render_attachment_list($promo_assets, 'No promotional material uploaded yet.') . '</div>';
    echo '</div>';
    if ($can_edit) {
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Promo Material Files<input type="file" name="track_promo_material_files[]" multiple></label>';
      echo '<label>Promo Material Label<input type="text" name="track_promo_material_label" placeholder="Social cut, teaser, lyric card"></label>';
      echo '<label>Promo Material Notes<input type="text" name="track_promo_material_notes" placeholder="Optional notes"></label>';
      echo '</div>';
    }
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="audio">';
    echo '<div class="eum-ar__detail-grid eum-ar__detail-grid--media">';
    echo '<div class="eum-ar__detail-block"><strong>Track Upload</strong>' . $this->render_audio_player($audio) . '</div>';
    echo '<div class="eum-ar__detail-block"><strong>Project File</strong>' . $this->render_attachment_list($project ? [$project] : [], 'No project file uploaded yet.') . '</div>';
    echo '</div>';
    if ($can_edit) {
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Track Upload<input type="file" name="track_audio_file" accept="audio/*"></label>';
      echo '<label>Project File<input type="file" name="track_project_file"></label>';
      echo '</div>';
    }
    echo '</div>';

    if ($can_edit) {
      echo '<button class="button">' . esc_html(in_array($highest_role, ['representative', 'manager', 'finance'], true) || $this->can_manage_artist($artist_id, $user_id) ? 'Save Track' : 'Propose Track Update') . '</button>';
      echo '</form>';
    }

    echo '</div>';
    echo '</article>';
    return ob_get_clean();
  }

  private function render_release_create_card(int $artist_id, int $user_id): string {
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $highest_role = $this->highest_artist_role($roles);
    $can_publish = in_array($highest_role, ['representative', 'manager', 'finance'], true) || $this->can_manage_artist($artist_id, $user_id);
    $tab_group = 'release-create-' . $artist_id;

    ob_start();
    echo '<article class="eum-ar__manage-card eum-ar__manage-card--release eum-ar__manage-card--create is-expanded">';
    echo '<div class="eum-ar__manage-media">';
    echo $this->render_media_thumb(null, 'New Release', 'Use the tabs to split core details, promotional strategy, and release files.');
    echo '</div>';
    echo '<div class="eum-ar__manage-main">';
    echo '<div class="eum-ar__manage-head">';
    echo '<div><h3>Create Release</h3><div class="description">Build the release in steps instead of one long form.</div></div>';
    echo '<div class="eum-ar__actions"><span class="eum-ar__pill">' . esc_html($can_publish ? 'Direct create' : 'Manager approval') . '</span></div>';
    echo '</div>';
    echo '<nav class="eum-ar__subtabs">';
    echo '<button type="button" class="is-active" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="overview">Overview</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="strategies">Strategies</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="files">Files</button>';
    echo '</nav>';
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact eum-ar__manage-form">';
    wp_nonce_field(self::NONCE, '_wpnonce');
    echo '<input type="hidden" name="action" value="eum_ar_add_release"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';

    echo '<div class="eum-ar__option-panel is-active" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="overview">';
    echo '<div class="eum-ar__meta-grid">';
    echo '<div class="eum-ar__meta-cell"><span>Workflow</span><strong>' . esc_html($can_publish ? 'Publish on save' : 'Submit for approval') . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Cost Center</span><strong>Optional</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Artwork</span><strong>Optional</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Promo Files</span><strong>Multiple</strong></div>';
    echo '</div>';
    echo '<div class="eum-ar__form-grid">';
    echo '<label>Release Title<input type="text" name="release_title" required></label>';
    echo '<label>Type<select name="release_type"><option value="single">Single</option><option value="ep">EP</option><option value="lp">LP / Album</option></select></label>';
    echo '<label>Release Date<input type="date" name="release_date"></label>';
    echo '<label>Status<select name="release_status"><option value="planning">Planning</option><option value="recording">Recording</option><option value="awaiting_art">Awaiting Art</option><option value="scheduled">Scheduled</option><option value="released">Released</option></select></label>';
    echo '<label>Budget<input type="number" step="0.01" name="release_budget"></label>';
    echo '<label>Cost Center<select name="release_cost_center_term_id">' . $this->cost_center_options_html(0, true, 'No release cost center') . '</select></label>';
    echo '<label>Album Cover<input type="file" name="release_cover_file" accept="image/*"></label>';
    echo '</div>';
    echo '<label>Release Info<textarea name="release_info" rows="4" placeholder="Notes, rollout context, creative direction, dependencies..."></textarea></label>';
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="strategies">';
    echo '<div class="eum-ar__detail-block"><strong>Promotional Strategies</strong><p>Use one line per strategy so the release plan stays easy to scan and edit later.</p></div>';
    echo '<label>Strategies<textarea name="release_promo_strategies" rows="6" placeholder="One strategy per line"></textarea></label>';
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="files">';
    echo '<div class="eum-ar__detail-block"><strong>Promotional Material</strong><p>Upload multiple files at once for social packs, press kits, ad creative, or rollout docs.</p></div>';
    echo '<div class="eum-ar__form-grid">';
    echo '<label>Promo Material Files<input type="file" name="release_promo_material_files[]" multiple></label>';
    echo '<label>Promo Material Label<input type="text" name="release_promo_material_label" placeholder="Press kit, ad set, social pack"></label>';
    echo '<label>Promo Material Notes<input type="text" name="release_promo_material_notes" placeholder="Optional notes"></label>';
    echo '</div>';
    echo '</div>';

    echo '<button class="button">' . esc_html($can_publish ? 'Create Release' : 'Submit Release For Approval') . '</button>';
    echo '</form>';
    echo '</div>';
    echo '</article>';
    return ob_get_clean();
  }

  private function render_track_create_card(int $artist_id, int $user_id, array $selected_release_ids = [], string $group_suffix = 'new'): string {
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $highest_role = $this->highest_artist_role($roles);
    $can_publish = in_array($highest_role, ['representative', 'manager', 'finance'], true) || $this->can_manage_artist($artist_id, $user_id);
    $selected_release_ids = array_values(array_unique(array_filter(array_map('intval', $selected_release_ids))));
    $tab_group = 'track-create-' . $artist_id . '-' . sanitize_key($group_suffix ?: 'new');

    ob_start();
    echo '<article class="eum-ar__manage-card eum-ar__manage-card--track eum-ar__manage-card--create is-expanded">';
    echo '<div class="eum-ar__manage-media">';
    echo $this->render_media_thumb(null, 'New Track', 'If you skip custom artwork, the first linked release cover will be inherited.');
    echo '</div>';
    echo '<div class="eum-ar__manage-main">';
    echo '<div class="eum-ar__manage-head">';
    echo '<div><h3>Create Track</h3><div class="description">Split metadata, strategy, files, and audio into their own tabs.</div></div>';
    echo '<div class="eum-ar__actions"><span class="eum-ar__pill">' . esc_html($can_publish ? 'Direct create' : 'Manager approval') . '</span></div>';
    echo '</div>';
    echo '<nav class="eum-ar__subtabs">';
    echo '<button type="button" class="is-active" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="overview">Overview</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="strategies">Strategies</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="files">Files</button>';
    echo '<button type="button" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="audio">Audio</button>';
    echo '</nav>';
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact eum-ar__manage-form">';
    wp_nonce_field(self::NONCE, '_wpnonce');
    echo '<input type="hidden" name="action" value="eum_ar_add_track"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
    echo '<input type="hidden" name="linked_release_ids[]" value="">';

    echo '<div class="eum-ar__option-panel is-active" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="overview">';
    echo '<div class="eum-ar__meta-grid">';
    echo '<div class="eum-ar__meta-cell"><span>Workflow</span><strong>' . esc_html($can_publish ? 'Publish on save' : 'Submit for approval') . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Cover</span><strong>' . esc_html(empty($selected_release_ids) ? 'Optional' : 'Can inherit') . '</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Promo Files</span><strong>Multiple</strong></div>';
    echo '<div class="eum-ar__meta-cell"><span>Audio</span><strong>Player ready</strong></div>';
    echo '</div>';
    echo '<div class="eum-ar__form-grid">';
    echo '<label>Track Title<input type="text" name="track_title" required></label>';
    echo '<label>Type<select name="track_type"><option value="master">Master</option><option value="acoustic">Acoustic</option><option value="demo">Demo</option></select></label>';
    echo '<label>Status<select name="track_status"><option value="draft">Draft</option><option value="mix_review">Mix review</option><option value="approved">Approved</option></select></label>';
    echo '<label>Linked Releases<select name="linked_release_ids[]" multiple size="5">' . $this->release_options_html($artist_id, $selected_release_ids) . '</select></label>';
    echo '<label>Album Cover<input type="file" name="track_cover_file" accept="image/*"></label>';
    echo '</div>';
    echo '<label>Track Info<textarea name="track_info" rows="4" placeholder="Notes, version details, mix requests, or rollout context..."></textarea></label>';
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="strategies">';
    echo '<div class="eum-ar__detail-block"><strong>Promotional Strategies</strong><p>Use one line per strategy so campaigns, deliverables, and concepts stay editable later.</p></div>';
    echo '<label>Strategies<textarea name="track_promo_strategies" rows="6" placeholder="One strategy per line"></textarea></label>';
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="files">';
    echo '<div class="eum-ar__detail-block"><strong>Promotional Material</strong><p>Upload multiple assets for teasers, lyric cards, canvases, or other campaign material.</p></div>';
    echo '<div class="eum-ar__form-grid">';
    echo '<label>Promo Material Files<input type="file" name="track_promo_material_files[]" multiple></label>';
    echo '<label>Promo Material Label<input type="text" name="track_promo_material_label" placeholder="Social cut, teaser, lyric card"></label>';
    echo '<label>Promo Material Notes<input type="text" name="track_promo_material_notes" placeholder="Optional notes"></label>';
    echo '</div>';
    echo '</div>';

    echo '<div class="eum-ar__option-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="audio">';
    echo '<div class="eum-ar__detail-block"><strong>Audio & Project Files</strong><p>Upload the audio file for playback plus the related session or project file.</p></div>';
    echo '<div class="eum-ar__form-grid">';
    echo '<label>Track Upload<input type="file" name="track_audio_file" accept="audio/*"></label>';
    echo '<label>Project File<input type="file" name="track_project_file"></label>';
    echo '</div>';
    echo '</div>';

    echo '<button class="button">' . esc_html($can_publish ? 'Create Track' : 'Submit Track For Approval') . '</button>';
    echo '</form>';
    echo '</div>';
    echo '</article>';
    return ob_get_clean();
  }

  private function approval_posts_for_user(int $user_id, int $artist_id = 0, int $limit = 20): array {
    $meta_query = [
      [
        'key' => self::META_APPROVAL_STATUS,
        'value' => 'pending',
      ],
    ];
    if ($artist_id > 0) {
      $meta_query[] = [
        'key' => self::META_APPROVAL_ARTIST_ID,
        'value' => $artist_id,
      ];
    }

    $posts = get_posts([
      'post_type' => self::CPT_APPROVAL,
      'post_status' => 'publish',
      'numberposts' => max(1, $limit * 4),
      'meta_query' => $meta_query,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    $visible = [];
    foreach ((array)$posts as $post) {
      if (!$this->approval_is_visible_to_user((int)$post->ID, $user_id)) continue;
      $visible[] = $post;
      if (count($visible) >= $limit) break;
    }
    return $visible;
  }

  private function approval_is_visible_to_user(int $approval_id, int $user_id): bool {
    if ($approval_id <= 0 || $user_id <= 0) return false;
    if (EUM_Access::is_it_user($user_id) || EUM_Access::is_finance_user($user_id)) return true;

    $approver_ids = $this->approval_approver_ids($approval_id);
    if (!empty($approver_ids) && in_array($user_id, $approver_ids, true)) return true;

    $artist_id = (int)get_post_meta($approval_id, self::META_APPROVAL_ARTIST_ID, true);
    return $artist_id > 0 && $this->can_view_artist($artist_id, $user_id);
  }

  private function approval_approver_ids(int $approval_id): array {
    return array_values(array_unique(array_filter(array_map('intval', (array)get_post_meta($approval_id, self::META_APPROVAL_APPROVER_IDS, true)))));
  }

  private function approval_target_label(int $approval_id): string {
    $artist_id = (int)get_post_meta($approval_id, self::META_APPROVAL_ARTIST_ID, true);
    $object_type = (string)get_post_meta($approval_id, self::META_APPROVAL_OBJECT_TYPE, true);
    $object_id = (int)get_post_meta($approval_id, self::META_APPROVAL_OBJECT_ID, true);
    $payload = (array)get_post_meta($approval_id, self::META_APPROVAL_PAYLOAD, true);

    if ($object_type === 'release' && $object_id > 0) {
      return 'Release · ' . get_the_title($object_id);
    }
    if ($artist_id > 0) {
      return 'Artist · ' . get_the_title($artist_id);
    }
    if (!empty($payload['release_title'])) {
      return 'Release Proposal · ' . sanitize_text_field((string)$payload['release_title']);
    }
    if (!empty($payload['track_title'])) {
      return 'Track Proposal · ' . sanitize_text_field((string)$payload['track_title']);
    }
    if (!empty($payload['artist_name'])) {
      return 'New Artist · ' . sanitize_text_field((string)$payload['artist_name']);
    }
    return 'A&R Approval';
  }

  private function render_approval_queue(int $user_id, int $artist_id = 0, int $limit = 12): string {
    $posts = $this->approval_posts_for_user($user_id, $artist_id, $limit);
    ob_start();
    if (empty($posts)) {
      echo '<div class="eum-ar__empty">No pending approvals right now.</div>';
      return ob_get_clean();
    }

    echo '<div class="eum-ar__stack">';
    foreach ($posts as $approval) {
      $approval_id = (int)$approval->ID;
      $payload = (array)get_post_meta($approval_id, self::META_APPROVAL_PAYLOAD, true);
      $proposed_by = (int)get_post_meta($approval_id, self::META_APPROVAL_PROPOSED_BY, true);
      $can_act = $this->can_approve((int)get_post_meta($approval_id, self::META_APPROVAL_ARTIST_ID, true), $user_id, $approval_id);

      echo '<div class="eum-ar__card-sm"><div class="eum-ar__row"><div><strong>' . esc_html($approval->post_title) . '</strong><div class="description">' . esc_html($this->approval_target_label($approval_id)) . ' · proposed by ' . esc_html($this->user_label($proposed_by)) . '</div></div><span class="eum-ar__pill">Pending</span></div>';
      if (!empty($payload['summary'])) {
        echo '<div>' . esc_html((string)$payload['summary']) . '</div>';
      } elseif (!empty($payload['artist_name'])) {
        echo '<div>' . esc_html((string)$payload['artist_name']) . '</div>';
      }
      if ($can_act) {
        echo '<div class="eum-ar__actions">';
        foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $verb => $label) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
          wp_nonce_field(self::NONCE, '_wpnonce');
          echo '<input type="hidden" name="action" value="eum_ar_approval"><input type="hidden" name="approval_id" value="' . $approval_id . '"><input type="hidden" name="decision" value="' . esc_attr($verb) . '"><button class="button button-small">' . esc_html($label) . '</button></form>';
        }
        echo '</div>';
      }
      echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_directory_v2(int $user_id): string {
    $artist_ids = $this->visible_artist_ids_for_user($user_id);
    $artists = [];
    foreach ($artist_ids as $artist_id) {
      $artists[] = $this->artist_summary($artist_id, $user_id);
    }

    $can_create = $this->can_create_artist($user_id);
    $rep_identity = $this->current_user_rep_identity($user_id);
    $active_count = 0;
    $single_count = 0;
    $album_count = 0;
    $pending_count = 0;
    foreach ($artists as $row) {
      if (($row['status'] ?? '') !== 'archived') $active_count++;
      if (($row['next_release_type'] ?? '') === 'single') $single_count++;
      if (in_array(($row['next_release_type'] ?? ''), ['ep', 'lp'], true)) $album_count++;
      $pending_count += (int)($row['pending_approvals'] ?? 0);
    }

    ob_start();
    $this->print_styles();
    echo '<div class="eum-ar">';
    echo $this->render_shell_start('Artists Directory');

    echo '<section class="eum-ar__hero">';
    echo '<div><div class="eum-ar__eyebrow">Artist & Release Dashboard</div><h2>Artists Directory</h2><div class="eum-ar__sub">Artists, approvals, and rep ownership are split into cleaner panels so the workflow is easier to manage.</div></div>';
    echo '<div class="eum-ar__hero-badges"><span class="eum-ar__badge">Visible artists: ' . count($artists) . '</span><span class="eum-ar__badge">Pending approvals: ' . $pending_count . '</span></div>';
    echo '</section>';

    echo '<div class="eum-ar__toolbar"><form method="get" class="eum-ar__toolbar-form">';
    foreach ($_GET as $key => $value) {
      if ($key === 'ar_search' || is_array($value)) continue;
      echo '<input type="hidden" name="' . esc_attr((string)$key) . '" value="' . esc_attr((string)$value) . '">';
    }
    echo '<input type="search" name="ar_search" value="' . esc_attr((string)($_GET['ar_search'] ?? '')) . '" placeholder="Search artist, release, or owner">';
    echo '<button class="button">Filter</button>';
    echo '</form>';
    if ($can_create) echo '<a href="#eum-ar-create-artist" class="eum-ar__new">+ New Artist</a>';
    echo '</div>';

    echo '<section class="eum-ar__cards">';
    echo $this->metric('Active artists', (string)$active_count);
    echo $this->metric('Singles in flight', (string)$single_count);
    echo $this->metric('Albums / EPs', (string)$album_count);
    echo $this->metric('Pending approvals', (string)$pending_count);
    echo '</section>';

    echo '<div class="eum-ar__split">';
    echo '<section class="eum-ar__panel"><div class="eum-ar__panel-head"><h3>Artists</h3><span>Open an artist to manage people, rules, releases, approvals, files, and finance in dedicated sections.</span></div>';
    if (empty($artists)) {
      echo '<div class="eum-ar__empty">No artists are visible for your current role.</div>';
    } else {
      echo '<div class="eum-ar__artist-grid">';
      foreach ($artists as $row) {
        echo $this->render_artist_card($row);
      }
      echo '</div>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel"><div class="eum-ar__panel-head"><h3>Approval Queue</h3><span>Managers and fallback managers can approve from one place.</span></div>';
    echo $this->render_approval_queue($user_id, 0, 8);
    echo '</section>';
    echo '</div>';

    if ($can_create) {
      echo '<section class="eum-ar__panel" id="eum-ar-create-artist"><div class="eum-ar__panel-head"><h3>Create Artist</h3><span>Rep ownership is tied to a job path + title so it follows the position automatically.</span></div>';
      if (!EUM_Access::is_it_or_hr($user_id)) {
        echo $this->render_identity_summary($rep_identity, 'Your current rep assignment');
        if (!$rep_identity['is_valid']) {
          echo '<div class="eum-ar__empty">Your profile needs both a Job Path and Job Title before you can submit a new artist for manager approval.</div>';
        }
      }

      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_create_artist">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Artist Name<input type="text" name="artist_name" required></label>';
      echo '<label>Division<input type="text" name="division"></label>';
      echo '<label>Status<select name="status"><option value="active">Active</option><option value="development">Development</option><option value="archived">Archived</option></select></label>';
      echo '</div>';
      if (EUM_Access::is_it_or_hr($user_id)) {
        echo '<label>Representative Job Path + Title';
        echo EUM_Profiles::render_job_path_directory_picker('job_path_term_id', 0, $user_id, [
          'placeholder' => 'Select the rep job path',
          'job_title_input_name' => 'job_title',
          'required' => true,
        ]);
        echo '</label>';
      } else {
        echo '<input type="hidden" name="job_path_term_id" value="' . (int)$rep_identity['job_path_term_id'] . '">';
        echo '<input type="hidden" name="job_title" value="' . esc_attr((string)$rep_identity['job_title']) . '">';
      }
      echo '<label>Artist Summary<textarea name="artist_summary" rows="4"></textarea></label>';
      echo '<button class="button button-primary">' . (EUM_Access::is_it_or_hr($user_id) ? 'Create Artist' : 'Submit For Manager Approval') . '</button></form></section>';
    }

    echo $this->render_shell_end();
    echo '</div>';
    return ob_get_clean();
  }

  private function render_artist_workspace_v2(int $artist_id, int $user_id): string {
    return $this->render_artist_workspace_v3($artist_id, $user_id);
    if (!$this->can_view_artist($artist_id, $user_id)) {
      return '<div class="eum-ar eum-ar--denied">You do not have access to this artist.</div>';
    }

    $artist = get_post($artist_id);
    if (!$artist || $artist->post_type !== self::CPT_ARTIST) {
      return '<div class="eum-ar eum-ar--denied">Artist not found.</div>';
    }

    $profile = $this->artist_profile($artist_id);
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $highest_role = $this->highest_artist_role($roles);
    $rep_state = $this->representative_state($artist_id);
    $explicit_members = $this->explicit_member_rows($artist_id);
    $member_rules = $this->member_rules($artist_id);
    $releases = $this->artist_releases($artist_id);
    $tracks = $this->artist_tracks($artist_id);
    $roadmap = $this->artist_roadmap($artist_id);
    $activity = $this->activity_log($artist_id, self::META_ACTIVITY);
    $money = $this->money_summary($artist_id);
    $artist_tab_group = 'artist-main-' . $artist_id;

    ob_start();
    $this->print_styles();
    echo '<div class="eum-ar">';
    echo $this->render_shell_start('Artist Workspace');

    echo '<section class="eum-ar__hero">';
    echo '<div><div class="eum-ar__eyebrow">Artist Workspace</div><h2>' . esc_html($artist->post_title) . '</h2><div class="eum-ar__sub">' . esc_html($profile['status'] ?: 'active') . ' · ' . esc_html($profile['division'] ?: 'Unassigned') . ' · Owner ' . esc_html($this->representative_display_label($artist_id)) . '</div></div>';
    echo '<div class="eum-ar__hero-badges">' . $this->render_role_badges($roles) . '<span class="eum-ar__badge">Budget: ' . esc_html($this->format_money($money['budget'])) . '</span><span class="eum-ar__badge">Costs: ' . esc_html($this->format_money($money['costs'])) . '</span></div>';
    echo '</section>';

    echo '<nav class="eum-ar__tabs">';
    foreach ([
      'overview' => 'Overview',
      'ownership' => 'Ownership',
      'team' => 'Team',
      'rules' => 'Rules',
      'approvals' => 'Approvals',
      'releases' => 'Releases',
      'tracks' => 'Tracks',
      'roadmap' => 'Roadmap',
      'assets' => 'Assets',
      'activity' => 'Activity',
    ] as $tab_key => $tab_label) {
      echo '<button type="button" class="' . ($tab_key === 'overview' ? 'is-active' : '') . '" data-eum-tab-group="' . esc_attr($artist_tab_group) . '" data-eum-tab-target="' . esc_attr($tab_key) . '">' . esc_html($tab_label) . '</button>';
    }
    echo '</nav>';

    echo '<div class="eum-ar__split">';
    echo '<section class="eum-ar__panel" id="artist-overview"><div class="eum-ar__panel-head"><h3>Artist Overview</h3><span>Status, division, summary, and general updates.</span></div>';
    echo '<div class="eum-ar__stack">';
    echo '<div class="eum-ar__card-sm"><strong>Status</strong><div>' . esc_html($profile['status'] ?: 'active') . '</div></div>';
    echo '<div class="eum-ar__card-sm"><strong>Division</strong><div>' . esc_html($profile['division'] ?: 'Unassigned') . '</div></div>';
    echo '<div class="eum-ar__card-sm"><strong>Summary</strong><div>' . wp_kses_post(wpautop($profile['summary'] ?: 'No artist summary yet.')) . '</div></div>';
    echo '</div>';
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_save_artist"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Status<select name="status">' . $this->select_options(['active' => 'Active', 'development' => 'Development', 'planning' => 'Planning', 'archived' => 'Archived'], $profile['status']) . '</select></label>';
      echo '<label>Division<input type="text" name="division" value="' . esc_attr($profile['division']) . '"></label>';
      echo '</div><label>Artist Summary<textarea name="summary" rows="4">' . esc_textarea($profile['summary']) . '</textarea></label>';
      echo '<button class="button">' . (in_array($highest_role, ['representative', 'manager', 'finance'], true) ? 'Save Artist' : 'Propose Artist Changes') . '</button></form>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel" id="artist-ownership"><div class="eum-ar__panel-head"><h3>Representative Ownership</h3><span>The rep is resolved by job path + title. If that seat is vacant, the job path manager becomes the fallback owner/approver.</span></div>';
    echo $this->render_identity_summary([
      'job_path_term_id' => (int)($rep_state['job_path_term_id'] ?? 0),
      'job_path_label' => (string)($rep_state['job_path_label'] ?? ''),
      'job_title' => (string)($rep_state['job_title'] ?? ''),
      'job_title_label' => (string)($rep_state['job_title_label'] ?? ''),
      'is_valid' => (int)($rep_state['job_path_term_id'] ?? 0) > 0 && (string)($rep_state['job_title'] ?? '') !== '',
    ], 'Current representative rule');
    echo '<div class="eum-ar__stack">';
    if (!empty($rep_state['representative_user_ids'])) {
      foreach ((array)$rep_state['representative_user_ids'] as $rep_user_id) {
        echo '<div class="eum-ar__card-sm"><strong>' . esc_html($this->user_label((int)$rep_user_id)) . '</strong><span class="eum-ar__pill">Representative</span><div class="description">Resolved from the assigned job path + title.</div></div>';
      }
    } else {
      echo '<div class="eum-ar__card-sm"><strong>No active rep currently matches this role.</strong><div class="description">Fallback managers keep approval and oversight access until the role is filled.</div></div>';
    }
    foreach ((array)$rep_state['manager_user_ids'] as $manager_user_id) {
      echo '<div class="eum-ar__card-sm"><strong>' . esc_html($this->user_label((int)$manager_user_id)) . '</strong><span class="eum-ar__pill">Manager</span><div class="description">Manager fallback / approval coverage for this rep path.</div></div>';
    }
    echo '</div>';
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_set_rep_rule"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<label>Rep Job Path + Title';
      echo EUM_Profiles::render_job_path_directory_picker('job_path_term_id', (int)($rep_state['job_path_term_id'] ?? 0), $user_id, [
        'placeholder' => 'Select the rep job path',
        'job_title_input_name' => 'job_title',
        'job_title_current' => (string)($rep_state['job_title'] ?? ''),
        'required' => true,
      ]);
      echo '</label><button class="button">Save Rep Rule</button></form>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__split">';
    echo '<section class="eum-ar__panel" id="artist-team"><div class="eum-ar__panel-head"><h3>Explicit Team Members</h3><span>Direct members live here. A person can also separately resolve as the artist rep through the ownership rule above.</span></div>';
    if (empty($explicit_members)) {
      echo '<div class="eum-ar__empty">No explicit team members yet.</div>';
    } else {
      echo '<div class="eum-ar__stack">';
      foreach ($explicit_members as $row) {
        $member_roles = $this->user_roles_for_artist($artist_id, (int)$row['user_id']);
        echo '<div class="eum-ar__card-sm"><div class="eum-ar__row"><div><strong>' . esc_html($this->user_label((int)$row['user_id'])) . '</strong><div class="description">Explicit member assignment</div></div><div class="eum-ar__actions">' . $this->render_role_badges($member_roles);
        if ($this->can_manage_artist($artist_id, $user_id)) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
          wp_nonce_field(self::NONCE, '_wpnonce');
          echo '<input type="hidden" name="action" value="eum_ar_remove_team_user"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '"><input type="hidden" name="row_index" value="' . (int)($row['_index'] ?? 0) . '"><button class="button button-small">Remove</button></form>';
        }
        echo '</div></div></div>';
      }
      echo '</div>';
    }
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_team_user"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<div class="eum-ar__form-grid"><label>Add Team Member<select name="user_id"><option value="0">Choose a user…</option>' . $this->user_options_html() . '</select></label></div><button class="button">Add Team Member</button></form>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel" id="artist-rules"><div class="eum-ar__panel-head"><h3>Member Rules</h3><span>Rules grant member access by job path and optionally by title.</span></div>';
    if (empty($member_rules)) {
      echo '<div class="eum-ar__empty">No member rules yet.</div>';
    } else {
      echo '<div class="eum-ar__stack">';
      foreach ($member_rules as $rule) {
        $title_label = $this->job_title_label_from_value((string)($rule['job_title'] ?? ''));
        echo '<div class="eum-ar__card-sm"><div class="eum-ar__row"><div><strong>' . esc_html($this->job_path_label((int)($rule['job_path_term_id'] ?? 0))) . '</strong><div class="description">' . esc_html($title_label !== '' ? $title_label : 'Any title on this path') . '</div></div><div class="eum-ar__actions"><span class="eum-ar__pill">Member Rule</span>';
        if ($this->can_manage_artist($artist_id, $user_id)) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
          wp_nonce_field(self::NONCE, '_wpnonce');
          echo '<input type="hidden" name="action" value="eum_ar_remove_team_rule"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '"><input type="hidden" name="row_index" value="' . (int)($rule['_index'] ?? 0) . '"><button class="button button-small">Remove</button></form>';
        }
        echo '</div></div></div>';
      }
      echo '</div>';
    }
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_team_rule"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<label>Member Rule Path + Title';
      echo EUM_Profiles::render_job_path_directory_picker('job_path_term_id', 0, $user_id, [
        'placeholder' => 'Select a member path',
        'job_title_input_name' => 'job_title',
        'required' => true,
        'none_label' => 'Any title on this path',
      ]);
      echo '</label><button class="button">Add Member Rule</button></form>';
    }
    echo '</section>';
    echo '</div>';

    echo '<section class="eum-ar__panel" id="artist-approvals"><div class="eum-ar__panel-head"><h3>Approvals</h3><span>Artist proposals, release changes, track proposals, and expenses route through this queue.</span></div>';
    echo $this->render_approval_queue($user_id, $artist_id, 20);
    echo '</section>';

    echo '<div class="eum-ar__split" id="releases">';
    echo '<section class="eum-ar__panel"><div class="eum-ar__panel-head"><h3>Release Management</h3><span>Manage covers, promotional strategy, promotional material, release metadata, and track additions in one place.</span></div>';
    if (empty($releases)) {
      echo '<div class="eum-ar__empty">No releases yet.</div>';
    } else {
      echo '<div class="eum-ar__manage-grid">';
      foreach ($releases as $release) {
        echo $this->render_release_management_card($release, $artist_id, $user_id);
      }
      echo '</div>';
    }
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact eum-ar__manage-form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_release"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<div class="eum-ar__form-grid"><label>Release Title<input type="text" name="release_title" required></label><label>Type<select name="release_type"><option value="single">Single</option><option value="ep">EP</option><option value="lp">LP / Album</option></select></label><label>Release Date<input type="date" name="release_date"></label><label>Status<select name="release_status"><option value="planning">Planning</option><option value="recording">Recording</option><option value="awaiting_art">Awaiting Art</option><option value="scheduled">Scheduled</option><option value="released">Released</option></select></label><label>Budget<input type="number" step="0.01" name="release_budget"></label><label>Album Cover<input type="file" name="release_cover_file" accept="image/*"></label><label>Promo Material File<input type="file" name="release_promo_material_file"></label><label>Promo Material Label<input type="text" name="release_promo_material_label" placeholder="Press kit, social pack"></label><label>Promo Material Notes<input type="text" name="release_promo_material_notes" placeholder="Optional notes"></label></div><label>Release Info<textarea name="release_info" rows="3"></textarea></label><label>Promotional Strategy<textarea name="release_promo_strategy" rows="3"></textarea></label><button class="button">Create Release</button></form>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel" id="tracks"><div class="eum-ar__panel-head"><h3>Track Management</h3><span>Tracks now have artwork inheritance, promo planning, promotional materials, audio playback, and project file uploads.</span></div>';
    if (empty($tracks)) {
      echo '<div class="eum-ar__empty">No tracks yet.</div>';
    } else {
      echo '<div class="eum-ar__manage-grid">';
      foreach ($tracks as $track) {
        echo $this->render_track_management_card($track, $artist_id, $user_id);
      }
      echo '</div>';
    }
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact eum-ar__manage-form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_track"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<input type="hidden" name="linked_release_ids[]" value="">';
      echo '<div class="eum-ar__form-grid"><label>Track Title<input type="text" name="track_title" required></label><label>Type<select name="track_type"><option value="master">Master</option><option value="acoustic">Acoustic</option><option value="demo">Demo</option></select></label><label>Status<select name="track_status"><option value="draft">Draft</option><option value="mix_review">Mix review</option><option value="approved">Approved</option></select></label><label>Linked Releases<select name="linked_release_ids[]" multiple size="5">' . $this->release_options_html($artist_id) . '</select></label><label>Album Cover<input type="file" name="track_cover_file" accept="image/*"></label><label>Track Upload<input type="file" name="track_audio_file" accept="audio/*"></label><label>Project File<input type="file" name="track_project_file"></label><label>Promo Material File<input type="file" name="track_promo_material_file"></label><label>Promo Material Label<input type="text" name="track_promo_material_label" placeholder="Teaser, lyric card, visualizer"></label><label>Promo Material Notes<input type="text" name="track_promo_material_notes" placeholder="Optional notes"></label></div><label>Track Info<textarea name="track_info" rows="3"></textarea></label><label>Promotional Strategy<textarea name="track_promo_strategy" rows="3"></textarea></label><button class="button">Create Track</button></form>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__split">';
    echo '<section class="eum-ar__panel" id="roadmap"><div class="eum-ar__panel-head"><h3>Roadmap</h3><span>Milestones stay separate from releases and files.</span></div>';
    echo '<div class="eum-ar__stack">';
    foreach ($roadmap as $item) {
      $internal = !empty($item['visibility']) && $item['visibility'] === 'internal';
      if ($internal && !in_array($highest_role, ['representative', 'manager', 'finance'], true) && !EUM_Access::is_it_user($user_id)) continue;
      echo '<div class="eum-ar__card-sm"><strong>' . esc_html($item['title']) . '</strong><span class="eum-ar__pill eum-ar__pill--status">' . esc_html(ucfirst($item['status'])) . '</span><div>' . esc_html($item['due_date'] ?: 'No due date') . '</div><div class="description">Owner: ' . esc_html($this->user_label((int)($item['owner_user_id'] ?? 0))) . '</div></div>';
    }
    if (empty($roadmap)) echo '<div class="eum-ar__empty">No roadmap items yet.</div>';
    echo '</div>';
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_roadmap"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<div class="eum-ar__form-grid"><label>Milestone<input type="text" name="title" required></label><label>Owner<select name="owner_user_id"><option value="0">— None —</option>' . $this->user_options_html() . '</select></label><label>Due Date<input type="date" name="due_date"></label><label>Status<select name="status"><option value="planned">Planned</option><option value="in_progress">In Progress</option><option value="blocked">Blocked</option><option value="done">Done</option></select></label><label>Visibility<select name="visibility"><option value="team">Team</option><option value="internal">Internal only</option></select></label></div><button class="button">Add Milestone</button></form>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel"><div class="eum-ar__panel-head"><h3>Budget / Costs</h3><span>Money lines and finance sync stay in their own panel.</span></div>' . $this->render_money_panel($artist_id, $user_id, 'artist') . '</section>';
    echo '</div>';

    echo '<div class="eum-ar__split" id="assets">';
    echo '<section class="eum-ar__panel"><div class="eum-ar__panel-head"><h3>Files & Version History</h3><span>Upload and restore versions without mixing them into team management.</span></div>' . $this->render_asset_list($artist_id, self::META_ASSETS);
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo $this->render_asset_upload_form('artist', $artist_id);
    }
    echo '</section>';

    echo '<section class="eum-ar__panel" id="activity"><div class="eum-ar__panel-head"><h3>Activity</h3><span>Recent changes across approvals, releases, files, and team management.</span></div>' . $this->render_activity($activity) . '</section>';
    echo '</div>';

    echo $this->render_shell_end();
    echo '</div>';
    return ob_get_clean();
  }

  private function render_artist_workspace_v3(int $artist_id, int $user_id): string {
    if (!$this->can_view_artist($artist_id, $user_id)) {
      return '<div class="eum-ar eum-ar--denied">You do not have access to this artist.</div>';
    }

    $artist = get_post($artist_id);
    if (!$artist || $artist->post_type !== self::CPT_ARTIST) {
      return '<div class="eum-ar eum-ar--denied">Artist not found.</div>';
    }

    $profile = $this->artist_profile($artist_id);
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $highest_role = $this->highest_artist_role($roles);
    $rep_state = $this->representative_state($artist_id);
    $explicit_members = $this->explicit_member_rows($artist_id);
    $member_rules = $this->member_rules($artist_id);
    $releases = $this->artist_releases($artist_id);
    $tracks = $this->artist_tracks($artist_id);
    $roadmap = $this->artist_roadmap($artist_id);
    $activity = $this->activity_log($artist_id, self::META_ACTIVITY);
    $money = $this->money_summary($artist_id);
    $tab_group = 'artist-main-' . $artist_id;

    ob_start();
    $this->print_styles();
    echo '<div class="eum-ar">';
    echo $this->render_shell_start('Artist Workspace');

    echo '<section class="eum-ar__hero">';
    echo '<div><div class="eum-ar__eyebrow">Artist Workspace</div><h2>' . esc_html($artist->post_title) . '</h2><div class="eum-ar__sub">' . esc_html($profile['status'] ?: 'active') . ' &middot; ' . esc_html($profile['division'] ?: 'Unassigned') . ' &middot; Owner ' . esc_html($this->representative_display_label($artist_id)) . '</div></div>';
    echo '<div class="eum-ar__hero-badges">' . $this->render_role_badges($roles) . '<span class="eum-ar__badge">Budget: ' . esc_html($this->format_money($money['budget'])) . '</span><span class="eum-ar__badge">Costs: ' . esc_html($this->format_money($money['costs'])) . '</span></div>';
    echo '</section>';

    echo '<nav class="eum-ar__tabs">';
    foreach ([
      'overview' => 'Overview',
      'ownership' => 'Ownership',
      'team' => 'Team',
      'rules' => 'Rules',
      'approvals' => 'Approvals',
      'releases' => 'Releases',
      'tracks' => 'Tracks',
      'roadmap' => 'Roadmap',
      'assets' => 'Assets',
      'activity' => 'Activity',
    ] as $tab_key => $tab_label) {
      echo '<button type="button" class="' . ($tab_key === 'overview' ? 'is-active' : '') . '" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="' . esc_attr($tab_key) . '">' . esc_html($tab_label) . '</button>';
    }
    echo '</nav>';

    echo '<div class="eum-ar__tab-panel is-active" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="overview">';
    echo '<div class="eum-ar__split">';
    echo '<section class="eum-ar__panel" id="artist-overview"><div class="eum-ar__panel-head"><h3>Artist Overview</h3><span>Status, division, summary, and general updates.</span></div>';
    echo '<div class="eum-ar__stack">';
    echo '<div class="eum-ar__card-sm"><strong>Status</strong><div>' . esc_html($profile['status'] ?: 'active') . '</div></div>';
    echo '<div class="eum-ar__card-sm"><strong>Division</strong><div>' . esc_html($profile['division'] ?: 'Unassigned') . '</div></div>';
    echo '<div class="eum-ar__card-sm"><strong>Summary</strong><div>' . wp_kses_post(wpautop($profile['summary'] ?: 'No artist summary yet.')) . '</div></div>';
    echo '</div>';
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_save_artist"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Status<select name="status">' . $this->select_options(['active' => 'Active', 'development' => 'Development', 'planning' => 'Planning', 'archived' => 'Archived'], $profile['status']) . '</select></label>';
      echo '<label>Division<input type="text" name="division" value="' . esc_attr($profile['division']) . '"></label>';
      echo '</div><label>Artist Summary<textarea name="summary" rows="4">' . esc_textarea($profile['summary']) . '</textarea></label>';
      echo '<button class="button">' . (in_array($highest_role, ['representative', 'manager', 'finance'], true) ? 'Save Artist' : 'Propose Artist Changes') . '</button></form>';
    }
    echo '</section>';
    echo '<section class="eum-ar__panel"><div class="eum-ar__panel-head"><h3>Budget / Costs</h3><span>Money lines and finance sync stay separate from artist metadata.</span></div>' . $this->render_money_panel($artist_id, $user_id, 'artist') . '</section>';
    echo '</div>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="ownership">';
    echo '<section class="eum-ar__panel" id="artist-ownership"><div class="eum-ar__panel-head"><h3>Representative Ownership</h3><span>The rep is resolved by job path + title. If that seat is vacant, the job path manager becomes the fallback owner and approver.</span></div>';
    echo $this->render_identity_summary([
      'job_path_term_id' => (int)($rep_state['job_path_term_id'] ?? 0),
      'job_path_label' => (string)($rep_state['job_path_label'] ?? ''),
      'job_title' => (string)($rep_state['job_title'] ?? ''),
      'job_title_label' => (string)($rep_state['job_title_label'] ?? ''),
      'is_valid' => (int)($rep_state['job_path_term_id'] ?? 0) > 0 && (string)($rep_state['job_title'] ?? '') !== '',
    ], 'Current representative rule');
    echo '<div class="eum-ar__stack">';
    if (!empty($rep_state['representative_user_ids'])) {
      foreach ((array)$rep_state['representative_user_ids'] as $rep_user_id) {
        echo '<div class="eum-ar__card-sm"><strong>' . esc_html($this->user_label((int)$rep_user_id)) . '</strong><span class="eum-ar__pill">Representative</span><div class="description">Resolved from the assigned job path + title.</div></div>';
      }
    } else {
      echo '<div class="eum-ar__card-sm"><strong>No active rep currently matches this role.</strong><div class="description">Fallback managers keep approval and oversight access until the role is filled.</div></div>';
    }
    foreach ((array)$rep_state['manager_user_ids'] as $manager_user_id) {
      echo '<div class="eum-ar__card-sm"><strong>' . esc_html($this->user_label((int)$manager_user_id)) . '</strong><span class="eum-ar__pill">Manager</span><div class="description">Manager fallback and approval coverage for this rep path.</div></div>';
    }
    echo '</div>';
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_set_rep_rule"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<label>Rep Job Path + Title';
      echo EUM_Profiles::render_job_path_directory_picker('job_path_term_id', (int)($rep_state['job_path_term_id'] ?? 0), $user_id, [
        'placeholder' => 'Select the rep job path',
        'job_title_input_name' => 'job_title',
        'job_title_current' => (string)($rep_state['job_title'] ?? ''),
        'required' => true,
      ]);
      echo '</label><button class="button">Save Rep Rule</button></form>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="team">';
    echo '<section class="eum-ar__panel" id="artist-team"><div class="eum-ar__panel-head"><h3>Explicit Team Members</h3><span>Direct members live here. A person can also resolve as the artist rep through the ownership rule above.</span></div>';
    if (empty($explicit_members)) {
      echo '<div class="eum-ar__empty">No explicit team members yet.</div>';
    } else {
      echo '<div class="eum-ar__stack">';
      foreach ($explicit_members as $row) {
        $member_roles = $this->user_roles_for_artist($artist_id, (int)$row['user_id']);
        echo '<div class="eum-ar__card-sm"><div class="eum-ar__row"><div><strong>' . esc_html($this->user_label((int)$row['user_id'])) . '</strong><div class="description">Explicit member assignment</div></div><div class="eum-ar__actions">' . $this->render_role_badges($member_roles);
        if ($this->can_manage_artist($artist_id, $user_id)) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
          wp_nonce_field(self::NONCE, '_wpnonce');
          echo '<input type="hidden" name="action" value="eum_ar_remove_team_user"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '"><input type="hidden" name="row_index" value="' . (int)($row['_index'] ?? 0) . '"><button class="button button-small">Remove</button></form>';
        }
        echo '</div></div></div>';
      }
      echo '</div>';
    }
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_team_user"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<div class="eum-ar__form-grid"><label>Add Team Member<select name="user_id"><option value="0">Choose a user...</option>' . $this->user_options_html() . '</select></label></div><button class="button">Add Team Member</button></form>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="rules">';
    echo '<section class="eum-ar__panel" id="artist-rules"><div class="eum-ar__panel-head"><h3>Member Rules</h3><span>Rules grant member access by job path and optionally by title.</span></div>';
    if (empty($member_rules)) {
      echo '<div class="eum-ar__empty">No member rules yet.</div>';
    } else {
      echo '<div class="eum-ar__stack">';
      foreach ($member_rules as $rule) {
        $title_label = $this->job_title_label_from_value((string)($rule['job_title'] ?? ''));
        echo '<div class="eum-ar__card-sm"><div class="eum-ar__row"><div><strong>' . esc_html($this->job_path_label((int)($rule['job_path_term_id'] ?? 0))) . '</strong><div class="description">' . esc_html($title_label !== '' ? $title_label : 'Any title on this path') . '</div></div><div class="eum-ar__actions"><span class="eum-ar__pill">Member Rule</span>';
        if ($this->can_manage_artist($artist_id, $user_id)) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
          wp_nonce_field(self::NONCE, '_wpnonce');
          echo '<input type="hidden" name="action" value="eum_ar_remove_team_rule"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '"><input type="hidden" name="row_index" value="' . (int)($rule['_index'] ?? 0) . '"><button class="button button-small">Remove</button></form>';
        }
        echo '</div></div></div>';
      }
      echo '</div>';
    }
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_team_rule"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<label>Member Rule Path + Title';
      echo EUM_Profiles::render_job_path_directory_picker('job_path_term_id', 0, $user_id, [
        'placeholder' => 'Select a member path',
        'job_title_input_name' => 'job_title',
        'required' => true,
        'none_label' => 'Any title on this path',
      ]);
      echo '</label><button class="button">Add Member Rule</button></form>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="approvals">';
    echo '<section class="eum-ar__panel" id="artist-approvals"><div class="eum-ar__panel-head"><h3>Approvals</h3><span>Artist proposals, release changes, track proposals, and expenses route through this queue.</span></div>';
    echo $this->render_approval_queue($user_id, $artist_id, 20);
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="releases">';
    echo '<section class="eum-ar__panel" id="artist-releases"><div class="eum-ar__panel-head"><h3>Release Management</h3><span>Each release now has separate tabs for overview, strategies, and files.</span></div>';
    if (empty($releases)) {
      echo '<div class="eum-ar__empty">No releases yet.</div>';
    } else {
      echo '<div class="eum-ar__manage-grid">';
      foreach ($releases as $release) {
        echo $this->render_release_management_card($release, $artist_id, $user_id);
      }
      echo '</div>';
    }
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<div class="eum-ar__manage-grid eum-ar__manage-grid--create">';
      echo $this->render_release_create_card($artist_id, $user_id);
      echo '</div>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="tracks">';
    echo '<section class="eum-ar__panel" id="artist-tracks"><div class="eum-ar__panel-head"><h3>Track Management</h3><span>Tracks now have dedicated tabs for overview, strategies, files, and audio.</span></div>';
    if (empty($tracks)) {
      echo '<div class="eum-ar__empty">No tracks yet.</div>';
    } else {
      echo '<div class="eum-ar__manage-grid">';
      foreach ($tracks as $track) {
        echo $this->render_track_management_card($track, $artist_id, $user_id);
      }
      echo '</div>';
    }
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<div class="eum-ar__manage-grid eum-ar__manage-grid--create">';
      echo $this->render_track_create_card($artist_id, $user_id);
      echo '</div>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="roadmap">';
    echo '<section class="eum-ar__panel" id="roadmap"><div class="eum-ar__panel-head"><h3>Roadmap</h3><span>Milestones stay separate from releases and files.</span></div>';
    echo '<div class="eum-ar__stack">';
    foreach ($roadmap as $item) {
      $internal = !empty($item['visibility']) && $item['visibility'] === 'internal';
      if ($internal && !in_array($highest_role, ['representative', 'manager', 'finance'], true) && !EUM_Access::is_it_user($user_id)) continue;
      echo '<div class="eum-ar__card-sm"><strong>' . esc_html($item['title']) . '</strong><span class="eum-ar__pill eum-ar__pill--status">' . esc_html(ucfirst($item['status'])) . '</span><div>' . esc_html($item['due_date'] ?: 'No due date') . '</div><div class="description">Owner: ' . esc_html($this->user_label((int)($item['owner_user_id'] ?? 0))) . '</div></div>';
    }
    if (empty($roadmap)) echo '<div class="eum-ar__empty">No roadmap items yet.</div>';
    echo '</div>';
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_roadmap"><input type="hidden" name="artist_id" value="' . (int)$artist_id . '">';
      echo '<div class="eum-ar__form-grid"><label>Milestone<input type="text" name="title" required></label><label>Owner<select name="owner_user_id"><option value="0">-- None --</option>' . $this->user_options_html() . '</select></label><label>Due Date<input type="date" name="due_date"></label><label>Status<select name="status"><option value="planned">Planned</option><option value="in_progress">In Progress</option><option value="blocked">Blocked</option><option value="done">Done</option></select></label><label>Visibility<select name="visibility"><option value="team">Team</option><option value="internal">Internal only</option></select></label></div><button class="button">Add Milestone</button></form>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="assets">';
    echo '<section class="eum-ar__panel" id="assets"><div class="eum-ar__panel-head"><h3>Files & Version History</h3><span>Upload and restore versions without mixing them into team management.</span></div>' . $this->render_asset_list($artist_id, self::META_ASSETS);
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo $this->render_asset_upload_form('artist', $artist_id);
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="activity">';
    echo '<section class="eum-ar__panel" id="activity"><div class="eum-ar__panel-head"><h3>Activity</h3><span>Recent changes across approvals, releases, files, and team management.</span></div>' . $this->render_activity($activity) . '</section>';
    echo '</div>';

    echo $this->render_shell_end();
    echo '</div>';
    return ob_get_clean();
  }

  private function render_directory(int $user_id): string {
    return $this->render_directory_v2($user_id);
    $artist_ids = $this->visible_artist_ids_for_user($user_id);
    $artists = [];
    foreach ($artist_ids as $artist_id) {
      $artists[] = $this->artist_summary($artist_id, $user_id);
    }
    $can_create = $this->can_create_artist($user_id);
    $rep_identity = $this->current_user_rep_identity($user_id);

    $active_count = 0; $single_count = 0; $album_count = 0; $pending_count = 0;
    foreach ($artists as $row) {
      if (($row['status'] ?? '') !== 'archived') $active_count++;
      if (($row['next_release_type'] ?? '') === 'single') $single_count++;
      if (in_array(($row['next_release_type'] ?? ''), ['ep','lp'], true)) $album_count++;
      $pending_count += (int)($row['pending_approvals'] ?? 0);
    }

    ob_start();
    $this->print_styles();
    echo '<div class="eum-ar">';
    echo $this->render_shell_start('Artists Directory');
    echo '<section class="eum-ar__hero">';
    echo '<div><div class="eum-ar__eyebrow">Artist & Release Dashboard</div><h2>Artists Directory</h2><div class="eum-ar__sub">Artists, approvals, and rep ownership are split into separate panels so the workflow is easier to navigate.</div></div>';
    echo '<div class="eum-ar__hero-badges"><span class="eum-ar__badge">Visible artists: '.count($artists).'</span><span class="eum-ar__badge">Pending approvals: '.$pending_count.'</span></div>';
    echo '</section>';

    echo '<div class="eum-ar__toolbar"><form method="get" class="eum-ar__toolbar-form">';
    foreach ($_GET as $key => $value) {
      if ($key === 'ar_search' || is_array($value)) continue;
      echo '<input type="hidden" name="'.esc_attr((string)$key).'" value="'.esc_attr((string)$value).'">';
    }
    echo '<input type="search" name="ar_search" value="'.esc_attr((string)($_GET['ar_search'] ?? '')).'" placeholder="Search artist, release, or owner">';
    echo '<button class="button">Filter</button>';
    echo '</form>';
    if ($can_create) echo '<a href="#eum-ar-create-artist" class="eum-ar__new">+ New Artist</a>';
    echo '</div>';

    echo '<section class="eum-ar__cards">';
    echo $this->metric('Active artists', (string)$active_count);
    echo $this->metric('Singles in flight', (string)$single_count);
    echo $this->metric('Albums / EPs', (string)$album_count);
    echo $this->metric('Pending approvals', (string)$pending_count);
    echo '</section>';

    echo '<div class="eum-ar__split">';
    echo '<section class="eum-ar__panel"><div class="eum-ar__panel-head"><h3>Artists</h3><span>Open an artist to manage people, rules, releases, approvals, files, and finance in dedicated panels.</span></div>';
    if (empty($artists)) {
      echo '<div class="eum-ar__empty">No artists are visible for your current role.</div>';
    } else {
      echo '<div class="eum-ar__artist-grid">';
      foreach ($artists as $row) {
        echo $this->render_artist_card($row);
      }
      echo '</div>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel"><div class="eum-ar__panel-head"><h3>Approval Queue</h3><span>Managers and fallback managers can approve from one place.</span></div>';
    echo $this->render_approval_queue($user_id, 0, 8);
    echo '</section>';
    echo '</div>';

    if ($can_create) {
      echo '<section class="eum-ar__panel" id="eum-ar-create-artist"><div class="eum-ar__panel-head"><h3>Create Artist</h3><span>Rep ownership is attached to a job path + title so it follows the role automatically.</span></div>';
      if (!EUM_Access::is_it_or_hr($user_id)) {
        echo $this->render_identity_summary($rep_identity, 'Your current rep assignment');
        if (!$rep_identity['is_valid']) {
          echo '<div class="eum-ar__empty">Your profile needs both a Job Path and Job Title before you can submit a new artist for manager approval.</div>';
        }
      }

      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_create_artist">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Artist Name<input type="text" name="artist_name" required></label>';
      echo '<label>Division<input type="text" name="division"></label>';
      echo '<label>Status<select name="status"><option value="active">Active</option><option value="development">Development</option><option value="archived">Archived</option></select></label>';
      echo '<label>Lead representative<select name="lead_representative_user_id"><option value="0">— None —</option>'.$this->user_options_html((int)$profile['lead_representative_user_id']).'</select></label>';
      echo '</div>';
      echo '<label>Artist Summary<textarea name="artist_summary" rows="4"></textarea></label>';
      echo '<button class="button button-primary">Create Artist</button></form></div>';
    }

    echo $this->render_shell_end();
    echo '</div>';
    return ob_get_clean();
  }

  private function render_artist_workspace(int $artist_id, int $user_id): string {
    return $this->render_artist_workspace_v2($artist_id, $user_id);
    if (!$this->can_view_artist($artist_id, $user_id)) {
      return '<div class="eum-ar eum-ar--denied">You do not have access to this artist.</div>';
    }

    $artist = get_post($artist_id);
    if (!$artist || $artist->post_type !== self::CPT_ARTIST) {
      return '<div class="eum-ar eum-ar--denied">Artist not found.</div>';
    }

    $profile = $this->artist_profile($artist_id);
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $role = $this->highest_artist_role($roles);
    $team_users = $this->resolved_team_rows($artist_id);
    $releases = $this->artist_releases($artist_id);
    $tracks = $this->artist_tracks($artist_id);
    $roadmap = $this->artist_roadmap($artist_id);
    $assets = $this->object_assets($artist_id, self::META_ASSETS);
    $activity = $this->activity_log($artist_id, self::META_ACTIVITY);
    $money = $this->money_summary($artist_id);

    ob_start();
    $this->print_styles();
    echo '<div class="eum-ar">';
    echo $this->render_shell_start('Artist Workspace');
    echo '<div class="eum-ar__workspace-head">';
    echo '<div><div class="eum-ar__eyebrow">Artist Workspace</div><h2>'.esc_html($artist->post_title).'</h2><div class="eum-ar__sub">'.esc_html(($profile['status'] ?? 'active')).' · '.esc_html(($profile['division'] ?? 'Unassigned')).' · '.count($team_users).' team members · '.count($releases).' releases</div></div>';
    echo '<div class="eum-ar__hero-badges"><span class="eum-ar__badge">Role: '.esc_html(ucfirst($role)).'</span><span class="eum-ar__badge">Budget: '.esc_html($this->format_money($money['budget'])).'</span><span class="eum-ar__badge">Costs: '.esc_html($this->format_money($money['costs'])).'</span></div>';
    echo '</div>';

    echo '<div class="eum-ar__tabs"><a class="is-active">Overview</a><a href="#team">Team</a><a href="#releases">Releases</a><a href="#tracks">Tracks</a><a href="#roadmap">Roadmap</a><a href="#assets">Assets</a><a href="#activity">Activity</a></div>';

    echo '<div class="eum-ar__two">';
    echo '<section class="eum-ar__panel"><h3>Artist Overview</h3>';
    echo '<div class="eum-ar__stack">';
    echo '<div class="eum-ar__card-sm"><strong>Status</strong><div>'.esc_html($profile['status']).'</div></div>';
    echo '<div class="eum-ar__card-sm"><strong>Division</strong><div>'.esc_html($profile['division'] ?: 'Unassigned').'</div></div>';
    echo '<div class="eum-ar__card-sm"><strong>Summary</strong><div>'.wp_kses_post(wpautop($profile['summary'] ?: 'No artist summary yet.')).'</div></div>';
    echo '</div>';
    if (in_array($role, ['member','representative','manager','finance'], true) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_save_artist"><input type="hidden" name="artist_id" value="'.(int)$artist_id.'">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Status<select name="status">'.$this->select_options(['active'=>'Active','development'=>'Development','planning'=>'Planning','archived'=>'Archived'],$profile['status']).'</select></label>';
      echo '<label>Division<input type="text" name="division" value="'.esc_attr($profile['division']).'"></label>';
      echo '<label>Finance / Billing Job Path'.(class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_picker('job_path_term_id', (int)($profile['job_path_term_id'] ?? 0), $user_id, ['placeholder' => '— None —', 'none_label' => '— None —', 'enforce_manageable_job_paths' => false]) : '<select name="job_path_term_id"><option value="0">— None —</option>'.$this->job_path_options_html((int)($profile['job_path_term_id'] ?? 0)).'</select>').'</label>';
      echo '<label>Lead representative<select name="lead_representative_user_id"><option value="0">— None —</option>'.$this->user_options_html().'</select></label>';
      echo '<label>Finance / Billing Job Path'.(class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_picker('job_path_term_id', 0, $user_id, ['placeholder' => '— None —', 'none_label' => '— None —', 'enforce_manageable_job_paths' => false]) : '<select name="job_path_term_id"><option value="0">— None —</option>'.$this->job_path_options_html().'</select>').'</label>';
      echo '</div><label>Artist Summary<textarea name="summary" rows="4">'.esc_textarea($profile['summary']).'</textarea></label><button class="button">'.(in_array($role, ['representative','manager','finance'], true) ? 'Save Artist' : 'Propose Artist Changes').'</button></form>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel"><h3>Artist Team</h3>';
    echo '<div class="eum-ar__stack">';
    foreach ($team_users as $row) {
      echo '<div class="eum-ar__card-sm"><strong>'.esc_html($row['name']).'</strong><span class="eum-ar__pill">'.esc_html(ucfirst($row['role'])).'</span><div class="description">'.esc_html($row['source']).'</div></div>';
    }
    if (empty($team_users)) echo '<div class="eum-ar__empty">No team assignments yet.</div>';
    echo '</div>';
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<div class="eum-ar__inline-forms">';
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__mini-form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_team_user"><input type="hidden" name="artist_id" value="'.(int)$artist_id.'">';
      echo '<select name="user_id"><option value="0">Add user…</option>'.$this->user_options_html().'</select>';
      echo '<select name="role"><option value="member">Member</option><option value="representative">Artist Representative</option></select>';
      echo '<button class="button">Add Team User</button></form>';

      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__mini-form">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_team_rule"><input type="hidden" name="artist_id" value="'.(int)$artist_id.'">';
      echo class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_picker('job_path_term_id', 0, $user_id, ['placeholder' => 'Rule path…', 'none_label' => '— None —', 'enforce_manageable_job_paths' => false]) : '<select name="job_path_term_id"><option value="0">Rule path…</option>'.$this->job_path_options_html().'</select>';
      echo '<input type="text" name="job_title" placeholder="Optional job title">';
      echo '<select name="role"><option value="member">Member</option><option value="representative">Artist Representative</option></select>';
      echo '<button class="button">Add Path Rule</button></form>';
      echo '</div>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel" id="roadmap"><h3>Roadmap & Milestones</h3>';
    echo '<div class="eum-ar__stack">';
    foreach ($roadmap as $item) {
      $internal = !empty($item['visibility']) && $item['visibility'] === 'internal';
      if ($internal && !in_array($role, ['representative','manager','finance'], true) && !EUM_Access::is_it_user($user_id)) continue;
      echo '<div class="eum-ar__card-sm"><strong>'.esc_html($item['title']).'</strong><span class="eum-ar__pill eum-ar__pill--status">'.esc_html(ucfirst($item['status'])).'</span><div>'.esc_html($item['due_date'] ?: 'No due date').'</div><div class="description">Owner: '.esc_html($this->user_label((int)($item['owner_user_id'] ?? 0))).'</div></div>';
    }
    if (empty($roadmap)) echo '<div class="eum-ar__empty">No roadmap items yet.</div>';
    echo '</div>';
    if ($this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_roadmap"><input type="hidden" name="artist_id" value="'.(int)$artist_id.'">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Milestone<input type="text" name="title" required></label>';
      echo '<label>Owner<select name="owner_user_id"><option value="0">— None —</option>'.$this->user_options_html().'</select></label>';
      echo '<label>Due Date<input type="date" name="due_date"></label>';
      echo '<label>Status<select name="status"><option value="planned">Planned</option><option value="in_progress">In Progress</option><option value="blocked">Blocked</option><option value="done">Done</option></select></label>';
      echo '<label>Visibility<select name="visibility"><option value="team">Team</option><option value="internal">Internal only</option></select></label>';
      echo '</div><button class="button">Add Milestone</button></form>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__two" id="releases">';
    echo '<section class="eum-ar__panel"><h3>Releases</h3>';
    echo '<table class="eum-ar__table"><thead><tr><th>Release</th><th>Type</th><th>Date</th><th>Status</th><th>Budget</th></tr></thead><tbody>';
    if (empty($releases)) {
      echo '<tr><td colspan="5">No releases yet.</td></tr>';
    }
    foreach ($releases as $release) {
      $url = esc_url(add_query_arg(['artist_id' => $artist_id, 'release_id' => $release->ID]));
      echo '<tr><td><a href="'.$url.'"><strong>'.esc_html($release->post_title).'</strong></a></td><td>'.esc_html(strtoupper((string)get_post_meta($release->ID, self::META_RELEASE_TYPE, true))).'</td><td>'.esc_html((string)get_post_meta($release->ID, self::META_RELEASE_DATE, true)).'</td><td>'.esc_html((string)get_post_meta($release->ID, self::META_RELEASE_STATUS, true)).'</td><td>'.esc_html($this->format_money((float)get_post_meta($release->ID, self::META_RELEASE_BUDGET, true))).'</td></tr>';
    }
    echo '</tbody></table>';
    if (in_array($role, ['member','representative','manager','finance'], true) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_release"><input type="hidden" name="artist_id" value="'.(int)$artist_id.'">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Release Title<input type="text" name="release_title" required></label>';
      echo '<label>Type<select name="release_type"><option value="single">Single</option><option value="ep">EP</option><option value="lp">LP / Album</option></select></label>';
      echo '<label>Release Date<input type="date" name="release_date"></label>';
      echo '<label>Status<select name="release_status"><option value="planning">Planning</option><option value="recording">Recording</option><option value="awaiting_art">Awaiting Art</option><option value="scheduled">Scheduled</option><option value="released">Released</option></select></label>';
      echo '<label>Budget<input type="number" step="0.01" name="release_budget"></label>';
      echo '</div><label>Release Info<textarea name="release_info" rows="3"></textarea></label><button class="button">Create Release</button></form>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel" id="tracks"><h3>Tracks</h3>';
    echo '<table class="eum-ar__table"><thead><tr><th>Track</th><th>Linked Releases</th><th>Latest Status</th></tr></thead><tbody>';
    if (empty($tracks)) echo '<tr><td colspan="3">No tracks yet.</td></tr>';
    foreach ($tracks as $track) {
      $info = (array)get_post_meta($track->ID, self::META_TRACK_INFO, true);
      $release_ids = array_values(array_filter(array_map('intval', (array)get_post_meta($track->ID, self::META_TRACK_LINKED_RELEASES, true))));
      $labels = [];
      foreach ($release_ids as $rid) { $labels[] = get_the_title($rid); }
      echo '<tr><td><strong>'.esc_html($track->post_title).'</strong></td><td>'.esc_html(implode(', ', array_filter($labels))).'</td><td>'.esc_html((string)($info['status'] ?? 'draft')).'</td></tr>';
    }
    echo '</tbody></table>';
    if (in_array($role, ['member','representative','manager','finance'], true) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_add_track"><input type="hidden" name="artist_id" value="'.(int)$artist_id.'">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Track Title<input type="text" name="track_title" required></label>';
      echo '<label>Type<select name="track_type"><option value="master">Master</option><option value="acoustic">Acoustic</option><option value="demo">Demo</option></select></label>';
      echo '<label>Status<select name="track_status"><option value="draft">Draft</option><option value="mix_review">Mix review</option><option value="approved">Approved</option></select></label>';
      echo '</div><label>Track Info<textarea name="track_info" rows="3"></textarea></label><button class="button">Create Track</button></form>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__two" id="assets">';
    echo '<section class="eum-ar__panel"><h3>Files & Version History</h3>';
    echo $this->render_asset_list($artist_id, self::META_ASSETS);
    if (in_array($role, ['member','representative','manager','finance'], true) || $this->can_manage_artist($artist_id, $user_id)) {
      echo $this->render_asset_upload_form('artist', $artist_id);
    }
    echo '</section>';

    echo '<section class="eum-ar__panel"><h3>Budget / Costs</h3>';
    echo $this->render_money_panel($artist_id, $user_id, 'artist');
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__panel" id="activity"><h3>Activity</h3>';
    echo $this->render_activity($activity);
    echo '</div>';

    echo $this->render_shell_end();
    echo '</div>';
    return ob_get_clean();
  }

  private function render_release_workspace_v2(int $release_id, int $user_id): string {
    return $this->render_release_workspace_v3($release_id, $user_id);
    $release = get_post($release_id);
    if (!$release || $release->post_type !== self::CPT_RELEASE) return '<div class="eum-ar--denied">Release not found.</div>';
    $artist_id = (int)get_post_meta($release_id, self::META_RELEASE_ARTIST_ID, true);
    if (!$this->can_view_artist($artist_id, $user_id)) return '<div class="eum-ar--denied">You do not have access to this release.</div>';

    $artist = get_post($artist_id);
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $type = (string)get_post_meta($release_id, self::META_RELEASE_TYPE, true);
    $date = (string)get_post_meta($release_id, self::META_RELEASE_DATE, true);
    $status = (string)get_post_meta($release_id, self::META_RELEASE_STATUS, true);
    $budget = (float)get_post_meta($release_id, self::META_RELEASE_BUDGET, true);
    $tracks = $this->release_tracks($release_id);
    $cover = $this->release_cover_asset($release_id);
    $activity = $this->activity_log($release_id, self::META_RELEASE_ACTIVITY);
    $artist_url = esc_url(add_query_arg(['artist_id' => $artist_id], remove_query_arg(['release_id'])));

    ob_start();
    $this->print_styles();
    echo '<div class="eum-ar">';
    echo $this->render_shell_start('Release Workspace');
    echo '<section class="eum-ar__hero eum-ar__hero--workspace">';
    echo '<div class="eum-ar__hero-copy"><div class="eum-ar__eyebrow">Release Workspace</div><h2>' . esc_html($release->post_title) . '</h2><div class="eum-ar__sub">Artist: <a href="' . $artist_url . '">' . esc_html($artist ? $artist->post_title : 'Unknown') . '</a> &middot; ' . esc_html($date !== '' ? $date : 'No date scheduled') . ' &middot; Budget ' . esc_html($this->format_money($budget)) . ' &middot; ' . esc_html($status !== '' ? ucfirst($status) : 'Planning') . '</div><div class="eum-ar__hero-badges">' . $this->render_role_badges($roles) . '<span class="eum-ar__badge">Type: ' . esc_html(strtoupper($type ?: 'single')) . '</span><span class="eum-ar__badge">Tracks: ' . count($tracks) . '</span><span class="eum-ar__badge">Singles linked: ' . (int)$this->count_release_single_links($release_id) . '</span></div></div>';
    echo '<div class="eum-ar__hero-media">' . $this->render_media_thumb($cover, 'No cover uploaded yet.', 'Add cover art to style the release workspace.') . '</div>';
    echo '</section>';

    echo '<nav class="eum-ar__tabs"><a class="is-active" href="#release-details">Details</a><a href="#release-tracks">Tracks</a><a href="#release-budget">Budget</a><a href="#release-files">Files</a><a href="#release-activity">Activity</a></nav>';

    echo '<section class="eum-ar__panel" id="release-details"><div class="eum-ar__panel-head"><h3>Release Details</h3><span>Everything for this release, including cover art and promotional material, is managed here.</span></div>';
    echo $this->render_release_management_card($release, $artist_id, $user_id, true);
    echo '</section>';

    echo '<div class="eum-ar__split">';
    echo '<section class="eum-ar__panel" id="release-tracks"><div class="eum-ar__panel-head"><h3>Track Management</h3><span>Edit linked tracks without leaving the release workspace.</span></div>';
    if (empty($tracks)) {
      echo '<div class="eum-ar__empty">No tracks linked to this release yet.</div>';
    } else {
      echo '<div class="eum-ar__manage-grid">';
      foreach ($tracks as $row) {
        $track_post = get_post((int)($row['track_id'] ?? 0));
        if (!$track_post || $track_post->post_type !== self::CPT_TRACK) continue;
        echo $this->render_track_management_card($track_post, $artist_id, $user_id, true);
      }
      echo '</div>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel" id="release-budget"><div class="eum-ar__panel-head"><h3>Budget & Approvals</h3><span>Release-specific approvals and money stay visible next to the work.</span></div>';
    echo '<div class="eum-ar__stack">';
    echo '<div class="eum-ar__card-sm"><strong>Approval Timeline</strong>' . $this->render_release_timeline($release_id, $artist_id, $user_id) . '</div>';
    echo '<div class="eum-ar__card-sm"><strong>Budget / Costs</strong>' . $this->render_money_panel($release_id, $user_id, 'release') . '</div>';
    echo '</div>';
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__split">';
    echo '<section class="eum-ar__panel" id="release-files"><div class="eum-ar__panel-head"><h3>Files & Version History</h3><span>Use the generic file stack for anything that does not belong in the dedicated cover, promo, audio, or project sections.</span></div>';
    echo $this->render_asset_list($release_id, self::META_RELEASE_ASSETS);
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo $this->render_asset_upload_form('release', $release_id);
    }
    echo '</section>';

    echo '<section class="eum-ar__panel" id="release-activity"><div class="eum-ar__panel-head"><h3>Activity</h3><span>Recent updates for this release and its files.</span></div>' . $this->render_activity($activity) . '</section>';
    echo '</div>';

    echo $this->render_shell_end();
    echo '</div>';
    return ob_get_clean();
  }

  private function render_release_workspace_v3(int $release_id, int $user_id): string {
    $release = get_post($release_id);
    if (!$release || $release->post_type !== self::CPT_RELEASE) return '<div class="eum-ar--denied">Release not found.</div>';
    $artist_id = (int)get_post_meta($release_id, self::META_RELEASE_ARTIST_ID, true);
    if (!$this->can_view_artist($artist_id, $user_id)) return '<div class="eum-ar--denied">You do not have access to this release.</div>';

    $artist = get_post($artist_id);
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $type = (string)get_post_meta($release_id, self::META_RELEASE_TYPE, true);
    $date = (string)get_post_meta($release_id, self::META_RELEASE_DATE, true);
    $status = (string)get_post_meta($release_id, self::META_RELEASE_STATUS, true);
    $budget = (float)get_post_meta($release_id, self::META_RELEASE_BUDGET, true);
    $tracks = $this->release_tracks($release_id);
    $cover = $this->release_cover_asset($release_id);
    $activity = $this->activity_log($release_id, self::META_RELEASE_ACTIVITY);
    $artist_url = esc_url(add_query_arg(['artist_id' => $artist_id], remove_query_arg(['release_id'])));
    $tab_group = 'release-main-' . $release_id;

    ob_start();
    $this->print_styles();
    echo '<div class="eum-ar">';
    echo $this->render_shell_start('Release Workspace');
    echo '<section class="eum-ar__hero eum-ar__hero--workspace">';
    echo '<div class="eum-ar__hero-copy"><div class="eum-ar__eyebrow">Release Workspace</div><h2>' . esc_html($release->post_title) . '</h2><div class="eum-ar__sub">Artist: <a href="' . $artist_url . '">' . esc_html($artist ? $artist->post_title : 'Unknown') . '</a> &middot; ' . esc_html($date !== '' ? $date : 'No date scheduled') . ' &middot; Budget ' . esc_html($this->format_money($budget)) . ' &middot; ' . esc_html($status !== '' ? ucfirst($status) : 'Planning') . '</div><div class="eum-ar__hero-badges">' . $this->render_role_badges($roles) . '<span class="eum-ar__badge">Type: ' . esc_html(strtoupper($type ?: 'single')) . '</span><span class="eum-ar__badge">Tracks: ' . count($tracks) . '</span><span class="eum-ar__badge">Singles linked: ' . (int)$this->count_release_single_links($release_id) . '</span></div></div>';
    echo '<div class="eum-ar__hero-media">' . $this->render_media_thumb($cover, 'No cover uploaded yet.', 'Add cover art to style the release workspace.') . '</div>';
    echo '</section>';

    echo '<nav class="eum-ar__tabs">';
    foreach ([
      'details' => 'Details',
      'tracks' => 'Tracks',
      'budget' => 'Budget',
      'files' => 'Files',
      'activity' => 'Activity',
    ] as $tab_key => $tab_label) {
      echo '<button type="button" class="' . ($tab_key === 'details' ? 'is-active' : '') . '" data-eum-tab-group="' . esc_attr($tab_group) . '" data-eum-tab-target="' . esc_attr($tab_key) . '">' . esc_html($tab_label) . '</button>';
    }
    echo '</nav>';

    echo '<div class="eum-ar__tab-panel is-active" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="details">';
    echo '<section class="eum-ar__panel" id="release-details"><div class="eum-ar__panel-head"><h3>Release Details</h3><span>Everything for this release, including cover art and promotional material, is managed here.</span></div>';
    echo $this->render_release_management_card($release, $artist_id, $user_id, true);
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="tracks">';
    echo '<section class="eum-ar__panel" id="release-tracks"><div class="eum-ar__panel-head"><h3>Track Management</h3><span>Edit linked tracks or create a new one already attached to this release.</span></div>';
    if (empty($tracks)) {
      echo '<div class="eum-ar__empty">No tracks linked to this release yet.</div>';
    } else {
      echo '<div class="eum-ar__manage-grid">';
      foreach ($tracks as $row) {
        $track_post = get_post((int)($row['track_id'] ?? 0));
        if (!$track_post || $track_post->post_type !== self::CPT_TRACK) continue;
        echo $this->render_track_management_card($track_post, $artist_id, $user_id, true);
      }
      echo '</div>';
    }
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo '<div class="eum-ar__manage-grid eum-ar__manage-grid--create">';
      echo $this->render_track_create_card($artist_id, $user_id, [$release_id], 'release-' . $release_id);
      echo '</div>';
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="budget">';
    echo '<section class="eum-ar__panel" id="release-budget"><div class="eum-ar__panel-head"><h3>Budget & Approvals</h3><span>Release-specific approvals and money stay visible next to the work.</span></div>';
    echo '<div class="eum-ar__stack">';
    echo '<div class="eum-ar__card-sm"><strong>Approval Timeline</strong>' . $this->render_release_timeline($release_id, $artist_id, $user_id) . '</div>';
    echo '<div class="eum-ar__card-sm"><strong>Budget / Costs</strong>' . $this->render_money_panel($release_id, $user_id, 'release') . '</div>';
    echo '</div>';
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="files">';
    echo '<section class="eum-ar__panel" id="release-files"><div class="eum-ar__panel-head"><h3>Files & Version History</h3><span>Use the generic file stack for anything that does not belong in the dedicated cover, promo, audio, or project sections.</span></div>';
    echo $this->render_asset_list($release_id, self::META_RELEASE_ASSETS);
    if (!empty($roles) || $this->can_manage_artist($artist_id, $user_id)) {
      echo $this->render_asset_upload_form('release', $release_id);
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__tab-panel" data-eum-tab-panel-group="' . esc_attr($tab_group) . '" data-eum-tab-panel="activity">';
    echo '<section class="eum-ar__panel" id="release-activity"><div class="eum-ar__panel-head"><h3>Activity</h3><span>Recent updates for this release and its files.</span></div>' . $this->render_activity($activity) . '</section>';
    echo '</div>';

    echo $this->render_shell_end();
    echo '</div>';
    return ob_get_clean();
  }

  private function render_release_workspace(int $release_id, int $user_id): string {
    return $this->render_release_workspace_v2($release_id, $user_id);
    $release = get_post($release_id);
    if (!$release || $release->post_type !== self::CPT_RELEASE) return '<div class="eum-ar--denied">Release not found.</div>';
    $artist_id = (int)get_post_meta($release_id, self::META_RELEASE_ARTIST_ID, true);
    if (!$this->can_view_artist($artist_id, $user_id)) return '<div class="eum-ar--denied">You do not have access to this release.</div>';

    $artist = get_post($artist_id);
    $roles = $this->user_roles_for_artist($artist_id, $user_id);
    $type = (string)get_post_meta($release_id, self::META_RELEASE_TYPE, true);
    $date = (string)get_post_meta($release_id, self::META_RELEASE_DATE, true);
    $status = (string)get_post_meta($release_id, self::META_RELEASE_STATUS, true);
    $budget = (float)get_post_meta($release_id, self::META_RELEASE_BUDGET, true);
    $tracks = $this->release_tracks($release_id);
    $cover = $this->release_cover_asset($release_id);
    $activity = $this->activity_log($release_id, self::META_RELEASE_ACTIVITY);
    $artist_url = esc_url(add_query_arg(['artist_id' => $artist_id], remove_query_arg(['release_id'])));

    ob_start();
    $this->print_styles();
    echo '<div class="eum-ar">';
    echo $this->render_shell_start('Release Workspace');
    echo '<div class="eum-ar__workspace-head">';
    echo '<div><div class="eum-ar__eyebrow">Release Workspace</div><h2>'.esc_html($release->post_title).' <span class="eum-ar__pill">'.esc_html(strtoupper($type)).'</span></h2><div class="eum-ar__sub">Artist: <a href="'.$artist_url.'">'.esc_html($artist ? $artist->post_title : 'Unknown').'</a> · '.esc_html($date ?: 'TBD').' · Budget '.esc_html($this->format_money($budget)).' · Status '.esc_html($status ?: 'draft').'</div></div>';
    echo '<div class="eum-ar__hero-badges">'.$this->render_role_badges($roles).'<span class="eum-ar__badge">Tracks: '.count($tracks).'</span><span class="eum-ar__badge">Singles linked: '.(int)$this->count_release_single_links($release_id).'</span></div>';
    echo '</div>';

    echo '<div class="eum-ar__two">';
    echo '<section class="eum-ar__panel"><h3>Tracklist</h3>';
    echo '<table class="eum-ar__table"><thead><tr><th>#</th><th>Track</th><th>Type</th><th>Single</th><th>Status</th></tr></thead><tbody>';
    if (empty($tracks)) echo '<tr><td colspan="5">No tracks linked yet.</td></tr>';
    foreach ($tracks as $index => $row) {
      echo '<tr><td>'.(int)($index + 1).'</td><td>'.esc_html($row['title']).'</td><td>'.esc_html($row['type']).'</td><td>'.(!empty($row['is_single']) ? 'Yes' : 'No').'</td><td>'.esc_html($row['status']).'</td></tr>';
    }
    echo '</tbody></table>';
    if (in_array($role, ['member','representative','manager','finance'], true)) {
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_save_release"><input type="hidden" name="release_id" value="'.(int)$release_id.'">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Release Date<input type="date" name="release_date" value="'.esc_attr($date).'"></label>';
      echo '<label>Status<select name="release_status">'.$this->select_options(['planning'=>'Planning','recording'=>'Recording','awaiting_art'=>'Awaiting Art','scheduled'=>'Scheduled','released'=>'Released'],$status).'</select></label>';
      echo '<label>Budget<input type="number" step="0.01" name="release_budget" value="'.esc_attr((string)$budget).'"></label>';
      echo '<label>Type<select name="release_type">'.$this->select_options(['single'=>'Single','ep'=>'EP','lp'=>'LP / Album'],$type).'</select></label>';
      echo '<label>Add Existing Track<select name="append_track_id"><option value="0">— None —</option>'.$this->track_options_html($artist_id).'</select></label>';
      echo '<label>New Track marked single?<select name="append_track_single"><option value="0">No</option><option value="1">Yes</option></select></label>';
      echo '</div><label>Release Notes<textarea name="release_info" rows="3">'.esc_textarea((string)get_post_meta($release_id, self::META_RELEASE_INFO, true)).'</textarea></label><button class="button">Save Release</button></form>';
    }
    echo '</section>';

    echo '<section class="eum-ar__panel"><h3>Files & Version History</h3>';
    echo $this->render_asset_list($release_id, self::META_RELEASE_ASSETS);
    if (in_array($role, ['member','representative','manager','finance'], true)) {
      echo $this->render_asset_upload_form('release', $release_id);
    }
    echo '</section>';
    echo '</div>';

    echo '<div class="eum-ar__two">';
    echo '<section class="eum-ar__panel"><h3>Approval Timeline</h3>'.$this->render_release_timeline($release_id, $artist_id, $user_id).'</section>';
    echo '<section class="eum-ar__panel"><h3>Budget / Costs</h3>'.$this->render_money_panel($release_id, $user_id, 'release').'</section>';
    echo '</div>';

    echo '<div class="eum-ar__panel"><h3>Activity</h3>'.$this->render_activity($activity).'</div>';
    echo $this->render_shell_end();
    echo '</div>';
    return ob_get_clean();
  }

  private function render_shell_start(string $title): string {
    ob_start();
    $directory_url = esc_url(remove_query_arg(['artist_id', 'release_id']));
    $artist_id = isset($_GET['artist_id']) ? (int)$_GET['artist_id'] : 0;
    $release_id = isset($_GET['release_id']) ? (int)$_GET['release_id'] : 0;
    echo '<div class="eum-ar__layout">';
    echo '<aside class="eum-ar__side"><div class="eum-ar__brand">A&R Portal</div><nav>';
    echo '<a class="' . ($artist_id <= 0 && $release_id <= 0 ? 'is-active' : '') . '" href="' . $directory_url . '">Artists Directory</a>';
    if ($artist_id > 0) {
      echo '<a class="' . ($release_id <= 0 ? 'is-active' : '') . '" href="' . esc_url(add_query_arg(['artist_id' => $artist_id], remove_query_arg(['release_id']))) . '">Current Artist</a>';
    }
    if ($release_id > 0) {
      echo '<a class="is-active" href="' . esc_url(add_query_arg(['artist_id' => $artist_id, 'release_id' => $release_id])) . '">Current Release</a>';
    }
    echo '</nav><div class="description" style="margin-top:16px;color:#d8e0f2">' . esc_html($title) . '</div></aside>';
    echo '<main class="eum-ar__main">';
    echo $this->render_notice();
    return ob_get_clean();
  }

  private function render_shell_end(): string {
    return '</main></div>';
  }

  private function render_notice(): string {
    if (empty($_GET['eum_ar_notice'])) return '';
    return '<div class="eum-ar__notice">'.esc_html((string)$_GET['eum_ar_notice']).'</div>';
  }

  private function metric(string $label, string $value): string {
    return '<div class="eum-ar__metric"><div class="eum-ar__metric-value">'.esc_html($value).'</div><div class="eum-ar__metric-label">'.esc_html($label).'</div></div>';
  }

  private function artist_profile(int $artist_id): array {
    $profile = (array)get_post_meta($artist_id, self::META_ARTIST_PROFILE, true);
    $summary = wp_kses_post((string)($profile['summary'] ?? ''));
    if ($summary === '') {
      $summary = wp_kses_post((string)get_post_field('post_content', $artist_id));
    }
    return [
      'status' => sanitize_text_field((string)($profile['status'] ?? 'active')),
      'division' => sanitize_text_field((string)($profile['division'] ?? '')),
      'summary' => $summary,
      'lead_representative_user_id' => (int)($profile['lead_representative_user_id'] ?? 0),
      'job_path_term_id' => (int)($profile['job_path_term_id'] ?? 0),
    ];
  }

  private function artist_team_users(int $artist_id): array {
    $rows = (array)get_post_meta($artist_id, self::META_TEAM_USERS, true);
    $out = [];
    foreach ($rows as $index => $row) {
      if (!is_array($row)) continue;
      $uid = (int)($row['user_id'] ?? 0);
      if ($uid <= 0) continue;
      $out[] = [
        '_index' => (int)$index,
        'user_id' => $uid,
        'role' => $this->normalize_team_role((string)($row['role'] ?? 'member')),
      ];
    }
    return $out;
  }

  private function artist_team_rules(int $artist_id): array {
    $rows = (array)get_post_meta($artist_id, self::META_TEAM_RULES, true);
    $out = [];
    foreach ($rows as $index => $row) {
      if (!is_array($row)) continue;
      $term_id = (int)($row['job_path_term_id'] ?? 0);
      if ($term_id <= 0) continue;
      $out[] = [
        '_index' => (int)$index,
        'job_path_term_id' => $term_id,
        'job_title' => $this->normalize_artist_job_title((string)($row['job_title'] ?? '')),
        'role' => $this->normalize_team_role((string)($row['role'] ?? 'member')),
      ];
    }
    return $out;
  }

  private function explicit_member_rows(int $artist_id): array {
    return array_values(array_filter($this->artist_team_users($artist_id), fn($row) => ($row['role'] ?? '') === 'member'));
  }

  private function member_rules(int $artist_id): array {
    return array_values(array_filter($this->artist_team_rules($artist_id), fn($row) => ($row['role'] ?? '') === 'member'));
  }

  private function current_user_rep_identity(int $user_id): array {
    $job_path_term_id = $this->current_user_job_path_term_id($user_id);
    $job_title = $this->user_job_title_value($user_id);
    return [
      'job_path_term_id' => $job_path_term_id,
      'job_path_label' => $this->job_path_label($job_path_term_id),
      'job_title' => $job_title,
      'job_title_label' => $this->job_title_label_from_value($job_title),
      'is_valid' => $job_path_term_id > 0 && $job_title !== '',
    ];
  }

  private function representative_state(int $artist_id): array {
    $rule = $this->primary_representative_rule($artist_id);
    $rep_user_ids = $this->resolved_representatives($artist_id);
    $manager_user_ids = $this->artist_manager_user_ids($artist_id);
    $job_path_term_id = (int)($rule['job_path_term_id'] ?? $this->artist_job_path_term_id($artist_id));
    $title_value = (string)($rule['job_title'] ?? '');

    return [
      'rule' => $rule,
      'job_path_term_id' => $job_path_term_id,
      'job_path_label' => $this->job_path_label($job_path_term_id),
      'job_title' => $title_value,
      'job_title_label' => $this->job_title_label_from_value($title_value),
      'representative_user_ids' => $rep_user_ids,
      'manager_user_ids' => $manager_user_ids,
    ];
  }

  private function representative_display_label(int $artist_id): string {
    $state = $this->representative_state($artist_id);
    if (!empty($state['representative_user_ids'])) {
      $labels = array_map(fn($user_id) => $this->user_label((int)$user_id), $state['representative_user_ids']);
      return implode(', ', array_filter($labels));
    }
    if (!empty($state['manager_user_ids'])) {
      $labels = array_map(fn($user_id) => $this->user_label((int)$user_id), $state['manager_user_ids']);
      return implode(', ', array_filter($labels)) . ' (manager fallback)';
    }
    $profile = $this->artist_profile($artist_id);
    if (!empty($profile['lead_representative_user_id'])) {
      return $this->user_label((int)$profile['lead_representative_user_id']);
    }
    return 'Unassigned';
  }

  private function artist_roadmap(int $artist_id): array {
    $rows = (array)get_post_meta($artist_id, self::META_ROADMAP, true);
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $out[] = [
        'id' => sanitize_text_field((string)($row['id'] ?? wp_generate_uuid4())),
        'title' => sanitize_text_field((string)($row['title'] ?? '')),
        'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
        'due_date' => sanitize_text_field((string)($row['due_date'] ?? '')),
        'status' => sanitize_key((string)($row['status'] ?? 'planned')),
        'visibility' => sanitize_key((string)($row['visibility'] ?? 'team')),
      ];
    }
    usort($out, static function($a, $b) { return strcmp((string)($a['due_date'] ?? ''), (string)($b['due_date'] ?? '')); });
    return $out;
  }

  private function object_assets(int $object_id, string $meta_key): array {
    $rows = (array)get_post_meta($object_id, $meta_key, true);
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $attachment_id = (int)($row['attachment_id'] ?? 0);
      $url = $attachment_id > 0 ? wp_get_attachment_url($attachment_id) : '';
      $out[] = [
        'id' => sanitize_text_field((string)($row['id'] ?? wp_generate_uuid4())),
        'attachment_id' => $attachment_id,
        'asset_kind' => sanitize_text_field((string)($row['asset_kind'] ?? 'File')),
        'notes' => sanitize_text_field((string)($row['notes'] ?? '')),
        'uploaded_by' => (int)($row['uploaded_by'] ?? 0),
        'uploaded_at' => sanitize_text_field((string)($row['uploaded_at'] ?? '')),
        'approval_state' => sanitize_key((string)($row['approval_state'] ?? 'approved')),
        'is_live' => !empty($row['is_live']),
        'asset_group' => sanitize_key((string)($row['asset_group'] ?? self::ASSET_GROUP_GENERAL)),
        'url' => esc_url_raw((string)$url),
        'filename' => $attachment_id > 0 ? basename((string)get_attached_file($attachment_id)) : '',
      ];
    }
    return $out;
  }

  private function object_assets_by_group(int $object_id, string $meta_key, string $asset_group, bool $approved_only = true): array {
    $asset_group = sanitize_key($asset_group);
    $rows = [];
    foreach ($this->object_assets($object_id, $meta_key) as $asset) {
      if ((string)($asset['asset_group'] ?? self::ASSET_GROUP_GENERAL) !== $asset_group) continue;
      if ($approved_only && (string)($asset['approval_state'] ?? 'approved') !== 'approved') continue;
      $rows[] = $asset;
    }
    return $rows;
  }

  private function latest_asset_by_group(int $object_id, string $meta_key, string $asset_group, bool $approved_only = true): ?array {
    $rows = $this->object_assets_by_group($object_id, $meta_key, $asset_group, $approved_only);
    if (empty($rows)) return null;

    for ($i = count($rows) - 1; $i >= 0; $i--) {
      if (!empty($rows[$i]['is_live'])) return $rows[$i];
    }
    return $rows[count($rows) - 1] ?? null;
  }

  private function asset_is_image(array $asset): bool {
    $attachment_id = (int)($asset['attachment_id'] ?? 0);
    return $attachment_id > 0 && function_exists('wp_attachment_is_image') && wp_attachment_is_image($attachment_id);
  }

  private function first_image_asset(array $assets, array $preferred_groups = []): ?array {
    foreach ($preferred_groups as $group) {
      foreach ($assets as $asset) {
        if ((string)($asset['asset_group'] ?? self::ASSET_GROUP_GENERAL) !== (string)$group) continue;
        if ((string)($asset['approval_state'] ?? 'approved') !== 'approved') continue;
        if ($this->asset_is_image($asset)) return $asset;
      }
    }
    foreach ($assets as $asset) {
      if ((string)($asset['approval_state'] ?? 'approved') !== 'approved') continue;
      if ($this->asset_is_image($asset)) return $asset;
    }
    return null;
  }

  private function release_cover_asset(int $release_id): ?array {
    $cover = $this->latest_asset_by_group($release_id, self::META_RELEASE_ASSETS, self::ASSET_GROUP_COVER, true);
    if ($cover) return $cover;
    return $this->first_image_asset($this->object_assets($release_id, self::META_RELEASE_ASSETS), [self::ASSET_GROUP_COVER, self::ASSET_GROUP_PROMO]);
  }

  private function track_linked_release_ids(int $track_id): array {
    return array_values(array_filter(array_map('intval', (array)get_post_meta($track_id, self::META_TRACK_LINKED_RELEASES, true))));
  }

  private function track_cover_asset(int $track_id): ?array {
    $cover = $this->latest_asset_by_group($track_id, self::META_TRACK_ASSETS, self::ASSET_GROUP_COVER, true);
    if ($cover) return $cover;
    foreach ($this->track_linked_release_ids($track_id) as $release_id) {
      $release_cover = $this->release_cover_asset((int)$release_id);
      if ($release_cover) return $release_cover;
    }
    return null;
  }

  private function track_primary_audio_asset(int $track_id): ?array {
    return $this->latest_asset_by_group($track_id, self::META_TRACK_ASSETS, self::ASSET_GROUP_AUDIO, true);
  }

  private function track_primary_project_asset(int $track_id): ?array {
    return $this->latest_asset_by_group($track_id, self::META_TRACK_ASSETS, self::ASSET_GROUP_PROJECT, true);
  }

  private function activity_log(int $object_id, string $meta_key): array {
    $rows = (array)get_post_meta($object_id, $meta_key, true);
    if (!is_array($rows)) return [];
    return array_slice(array_values($rows), -50);
  }

  private function artist_releases(int $artist_id): array {
    $posts = get_posts([
      'post_type' => self::CPT_RELEASE,
      'post_status' => 'publish',
      'numberposts' => 200,
      'meta_key' => self::META_RELEASE_ARTIST_ID,
      'meta_value' => $artist_id,
    ]);
    usort($posts, function($left, $right) {
      $left_date = (string)get_post_meta((int)$left->ID, self::META_RELEASE_DATE, true);
      $right_date = (string)get_post_meta((int)$right->ID, self::META_RELEASE_DATE, true);
      if ($left_date === '' && $right_date === '') return strcasecmp((string)$left->post_title, (string)$right->post_title);
      if ($left_date === '') return 1;
      if ($right_date === '') return -1;
      $cmp = strcmp($left_date, $right_date);
      if ($cmp !== 0) return $cmp;
      return strcasecmp((string)$left->post_title, (string)$right->post_title);
    });
    return $posts;
  }

  private function artist_tracks(int $artist_id): array {
    return get_posts([
      'post_type' => self::CPT_TRACK,
      'post_status' => 'publish',
      'numberposts' => 300,
      'meta_key' => self::META_TRACK_ARTIST_ID,
      'meta_value' => $artist_id,
    ]);
  }

  private function release_tracks(int $release_id): array {
    $rows = (array)get_post_meta($release_id, self::META_RELEASE_TRACKS, true);
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $track_id = (int)($row['track_id'] ?? 0);
      if ($track_id <= 0) continue;
      $info = (array)get_post_meta($track_id, self::META_TRACK_INFO, true);
      $out[] = [
        'track_id' => $track_id,
        'title' => get_the_title($track_id),
        'type' => sanitize_text_field((string)($row['type'] ?? ($info['type'] ?? 'master'))),
        'is_single' => !empty($row['is_single']),
        'status' => sanitize_text_field((string)($info['status'] ?? 'draft')),
      ];
    }
    return $out;
  }

  private function count_release_single_links(int $release_id): int {
    $count = 0;
    foreach ($this->release_tracks($release_id) as $row) {
      if (!empty($row['is_single'])) $count++;
    }
    return $count;
  }

  private function visible_artist_ids_for_user(int $user_id): array {
    $posts = get_posts([
      'post_type' => self::CPT_ARTIST,
      'post_status' => 'publish',
      'numberposts' => 300,
      'fields' => 'ids',
    ]);
    $ids = [];
    $search = sanitize_text_field((string)($_GET['ar_search'] ?? ''));
    foreach ((array)$posts as $artist_id) {
      $artist_id = (int)$artist_id;
      if (!$this->can_view_artist($artist_id, $user_id)) continue;
      if ($search !== '') {
        $hay = strtolower(get_the_title($artist_id).' '.wp_strip_all_tags((string)get_post_field('post_content', $artist_id)));
        if (strpos($hay, strtolower($search)) === false) {
          $match_release = false;
          foreach ($this->artist_releases($artist_id) as $release) {
            if (strpos(strtolower($release->post_title), strtolower($search)) !== false) { $match_release = true; break; }
          }
          if (!$match_release) continue;
        }
      }
      $ids[] = $artist_id;
    }
    return $ids;
  }

  private function artist_summary(int $artist_id, int $user_id): array {
    $profile = $this->artist_profile($artist_id);
    $lead_rep = $this->representative_display_label($artist_id);
    $releases = $this->artist_releases($artist_id);
    $next_release = 'TBD';
    $next_type = '';
    foreach ($releases as $release) {
      $date = (string)get_post_meta($release->ID, self::META_RELEASE_DATE, true);
      if ($date !== '') { $next_release = $date.' · '.$release->post_title; $next_type = (string)get_post_meta($release->ID, self::META_RELEASE_TYPE, true); break; }
    }
    $pending = $this->pending_approval_count($artist_id, $user_id);
    return [
      'ID' => $artist_id,
      'title' => get_the_title($artist_id),
      'division' => $profile['division'] ?: 'Unassigned',
      'lead_rep' => $lead_rep,
      'next_release' => $next_release,
      'next_release_type' => $next_type,
      'status' => $profile['status'] ?: 'active',
      'pending_approvals' => $pending,
    ];
  }

  private function pending_approval_count(int $artist_id, int $user_id): int {
    $posts = get_posts([
      'post_type' => self::CPT_APPROVAL,
      'post_status' => 'publish',
      'numberposts' => 200,
      'meta_query' => [
        ['key' => self::META_APPROVAL_ARTIST_ID, 'value' => $artist_id],
        ['key' => self::META_APPROVAL_STATUS, 'value' => 'pending'],
      ],
      'fields' => 'ids',
    ]);
    return count((array)$posts);
  }

  private function resolved_team_rows(int $artist_id): array {
    $rows = [];
    foreach ($this->artist_team_users($artist_id) as $row) {
      $rows[] = ['name' => $this->user_label((int)$row['user_id']), 'role' => $row['role'], 'source' => 'Explicit user'];
    }
    foreach ($this->artist_team_rules($artist_id) as $rule) {
      $label = $this->job_path_label((int)$rule['job_path_term_id']);
      $title = trim((string)$rule['job_title']);
      $rows[] = ['name' => $label . ($title !== '' ? ' · '.$title : ''), 'role' => $rule['role'], 'source' => 'Path + title rule'];
    }
    $seen_mgr = [];
    foreach ($this->resolved_representatives($artist_id) as $rep_user_id) {
      $mgr_id = class_exists('EUM_Personnel') ? (int)get_user_meta($rep_user_id, EUM_Personnel::META_MANAGER_ID, true) : 0;
      if ($mgr_id > 0 && empty($seen_mgr[$mgr_id])) {
        $rows[] = ['name' => $this->user_label($mgr_id), 'role' => 'manager', 'source' => 'Representative real manager'];
        $seen_mgr[$mgr_id] = true;
      }
    }
    return $rows;
  }

  private function render_asset_list(int $object_id, string $meta_key): string {
    $assets = $this->object_assets($object_id, $meta_key);
    ob_start();
    if (empty($assets)) {
      echo '<div class="eum-ar__empty">No file versions uploaded yet.</div>';
      return ob_get_clean();
    }
    echo '<div class="eum-ar__stack">';
    foreach ($assets as $asset) {
      echo '<div class="eum-ar__card-sm"><strong>'.esc_html($asset['filename'] ?: $asset['asset_kind']).'</strong><span class="eum-ar__pill">'.esc_html($asset['asset_kind']).'</span>';
      echo '<div class="description">'.esc_html($asset['uploaded_at']).' · '.esc_html($this->user_label((int)$asset['uploaded_by'])).' · '.esc_html($asset['approval_state']).($asset['is_live'] ? ' · live' : '').'</div>';
      if (!empty($asset['notes'])) echo '<div>'.esc_html($asset['notes']).'</div>';
      echo '<div class="eum-ar__actions">';
      if (!empty($asset['url'])) echo '<a class="button button-small" href="'.esc_url($asset['url']).'" target="_blank" rel="noopener">Download</a>';
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_restore_asset"><input type="hidden" name="object_id" value="'.(int)$object_id.'"><input type="hidden" name="meta_key" value="'.esc_attr($meta_key).'"><input type="hidden" name="asset_id" value="'.esc_attr($asset['id']).'"><button class="button button-small">Make live</button></form>';
      echo '</div></div>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function render_asset_upload_form(string $object_type, int $object_id): string {
    ob_start();
    echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__form eum-ar__form--compact">';
    wp_nonce_field(self::NONCE, '_wpnonce');
    echo '<input type="hidden" name="action" value="eum_ar_upload_asset"><input type="hidden" name="object_type" value="'.esc_attr($object_type).'"><input type="hidden" name="object_id" value="'.(int)$object_id.'">';
    echo '<div class="eum-ar__form-grid"><label>Asset Type<input type="text" name="asset_kind" placeholder="Audio master, artwork, stems"></label><label>File<input type="file" name="asset_file" required></label></div>';
    echo '<label>Version Notes<textarea name="notes" rows="2"></textarea></label><button class="button">Upload New Version</button></form>';
    return ob_get_clean();
  }

  private function render_money_panel(int $object_id, int $user_id, string $context): string {
    $artist_id = $context === 'artist' ? $object_id : (int)get_post_meta($object_id, self::META_RELEASE_ARTIST_ID, true);
    $lines = $this->expense_lines($object_id, $context);
    $can_manage_money = $this->can_manage_money($artist_id, $user_id);
    $can_submit_money = $this->can_submit_money($artist_id, $user_id);
    $default_cost_center_term_id = $this->default_money_cost_center_term_id($object_id, $context);
    $default_cost_center_label = $this->cost_center_label($default_cost_center_term_id);
    $default_cost_center_budget = $this->cost_center_budget($default_cost_center_term_id);
    $cost_center_options = $this->cost_center_options();
    ob_start();
    $total = 0.0;
    if ($default_cost_center_label !== '') {
      echo '<div class="eum-ar__detail-block"><strong>Default Cost Center</strong><p>' . esc_html($default_cost_center_label) . '</p>';
      if ($default_cost_center_budget > 0) {
        echo '<p>Configured budget: ' . esc_html($this->format_money($default_cost_center_budget)) . '</p>';
      }
      echo '</div>';
    }
    echo '<div class="eum-ar__stack">';
    foreach ($lines as $line) {
      $total += (float)$line['amount'];
      $cost_center_label = $this->cost_center_label((int)($line['cost_center_term_id'] ?? 0));
      $detail_bits = [(string)($line['occurred_on'] ?? ''), (string)($line['kind'] ?? '')];
      if ($cost_center_label !== '') $detail_bits[] = $cost_center_label;
      $detail_bits[] = 'finance sync ' . (!empty($line['finance_record_id']) ? '#' . (int)$line['finance_record_id'] : 'pending');
      echo '<div class="eum-ar__card-sm"><strong>'.esc_html($line['label']).'</strong><span class="eum-ar__pill">'.esc_html($line['status']).'</span><div>'.esc_html($this->format_money((float)$line['amount'])).'</div><div class="description">'.esc_html(implode(' · ', array_filter($detail_bits, static fn($value) => $value !== ''))).'</div></div>';
      continue;
      echo '<div class="eum-ar__card-sm"><strong>'.esc_html($line['label']).'</strong><span class="eum-ar__pill">'.esc_html($line['status']).'</span><div>'.esc_html($this->format_money((float)$line['amount'])).'</div><div class="description">'.esc_html($line['occurred_on']).' · '.esc_html($line['kind']).' · finance sync '.(!empty($line['finance_record_id']) ? '#'.$line['finance_record_id'] : 'pending').'</div></div>';
    }
    if (empty($lines)) echo '<div class="eum-ar__empty">No money lines yet.</div>';
    echo '</div>';
    echo '<div class="eum-ar__money-total">Current total: <strong>'.esc_html($this->format_money($total)).'</strong></div>';
    if ($can_submit_money) {
      echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'" class="eum-ar__form eum-ar__form--compact">';
      wp_nonce_field(self::NONCE, '_wpnonce');
      echo '<input type="hidden" name="action" value="eum_ar_submit_expense"><input type="hidden" name="context" value="'.esc_attr($context).'"><input type="hidden" name="object_id" value="'.(int)$object_id.'">';
      echo '<div class="eum-ar__form-grid">';
      echo '<label>Label<input type="text" name="label" required></label>';
      echo '<label>Kind<select name="kind"><option value="expense">Expense</option><option value="income">Income</option></select></label>';
      echo '<label>Amount<input type="number" step="0.01" name="amount" required></label>';
      echo '<label>Date<input type="date" name="occurred_on" value="'.esc_attr(current_time('Y-m-d')).'"></label>';
      if (!empty($cost_center_options)) {
        echo '<label>Cost Center<select name="cost_center_term_id">' . $this->cost_center_options_html($default_cost_center_term_id, true, $default_cost_center_label !== '' ? 'Use release cost center' : 'No cost center') . '</select></label>';
      }
      echo '<label>Receipt<input type="file" name="receipt"></label>';
      echo '</div><label>Notes<textarea name="notes" rows="2"></textarea></label><button class="button">'.($can_manage_money ? 'Add Money Line' : 'Submit Expense for Approval').'</button></form>';
    } else {
      echo '<div class="description">Members can view money but only representatives, managers, and finance users can submit costs.</div>';
    }
    return ob_get_clean();
  }

  private function render_activity(array $activity): string {
    ob_start();
    if (empty($activity)) {
      echo '<div class="eum-ar__empty">No activity yet.</div>';
      return ob_get_clean();
    }
    echo '<ul class="eum-ar__timeline">';
    foreach (array_reverse($activity) as $row) {
      if (!is_array($row)) continue;
      echo '<li><strong>'.esc_html((string)($row['message'] ?? 'Update')).'</strong><div class="description">'.esc_html((string)($row['time'] ?? '')).' · '.esc_html($this->user_label((int)($row['user_id'] ?? 0))).'</div></li>';
    }
    echo '</ul>';
    return ob_get_clean();
  }

  private function render_release_timeline(int $release_id, int $artist_id, int $viewer_user_id): string {
    $posts = get_posts([
      'post_type' => self::CPT_APPROVAL,
      'post_status' => 'publish',
      'numberposts' => 100,
      'meta_query' => [
        ['key' => self::META_APPROVAL_OBJECT_TYPE, 'value' => 'release'],
        ['key' => self::META_APPROVAL_OBJECT_ID, 'value' => $release_id],
      ],
    ]);
    ob_start();
    if (empty($posts)) {
      echo '<div class="eum-ar__empty">No approval requests yet.</div>';
      return ob_get_clean();
    }
    echo '<ul class="eum-ar__timeline">';
    foreach ($posts as $approval) {
      $status = (string)get_post_meta($approval->ID, self::META_APPROVAL_STATUS, true);
      $payload = (array)get_post_meta($approval->ID, self::META_APPROVAL_PAYLOAD, true);
      $can_act = $status === 'pending' && $this->can_approve($artist_id, $viewer_user_id, $approval->ID);
      echo '<li><strong>'.esc_html($approval->post_title).'</strong><div class="description">'.esc_html($status).' · proposed by '.esc_html($this->user_label((int)get_post_meta($approval->ID, self::META_APPROVAL_PROPOSED_BY, true))).'</div>';
      if (!empty($payload['summary'])) echo '<div>'.esc_html((string)$payload['summary']).'</div>';
      if ($can_act) {
        echo '<div class="eum-ar__actions">';
        foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $verb => $label) {
          echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
          wp_nonce_field(self::NONCE, '_wpnonce');
          echo '<input type="hidden" name="action" value="eum_ar_approval"><input type="hidden" name="approval_id" value="'.(int)$approval->ID.'"><input type="hidden" name="decision" value="'.esc_attr($verb).'"><button class="button button-small">'.esc_html($label).'</button></form>';
        }
        echo '</div>';
      }
      echo '</li>';
    }
    echo '</ul>';
    return ob_get_clean();
  }

  private function expense_lines(int $object_id, string $context): array {
    $key = self::META_EXPENSE_LINES . '_' . $context;
    $rows = (array)get_post_meta($object_id, $key, true);
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $out[] = [
        'id' => sanitize_text_field((string)($row['id'] ?? wp_generate_uuid4())),
        'label' => sanitize_text_field((string)($row['label'] ?? 'Line item')),
        'kind' => sanitize_key((string)($row['kind'] ?? 'expense')),
        'amount' => (float)($row['amount'] ?? 0),
        'occurred_on' => sanitize_text_field((string)($row['occurred_on'] ?? '')),
        'notes' => sanitize_textarea_field((string)($row['notes'] ?? '')),
        'status' => sanitize_key((string)($row['status'] ?? 'approved')),
        'cost_center_term_id' => $this->valid_cost_center_term_id((int)($row['cost_center_term_id'] ?? 0)),
        'finance_record_id' => (int)($row['finance_record_id'] ?? 0),
        'receipt_id' => (int)($row['receipt_id'] ?? 0),
        'submitted_by' => (int)($row['submitted_by'] ?? 0),
      ];
    }
    return $out;
  }

  private function money_summary(int $artist_id): array {
    $budget = 0.0;
    $costs = 0.0;
    foreach ($this->artist_releases($artist_id) as $release) {
      $budget += (float)get_post_meta($release->ID, self::META_RELEASE_BUDGET, true);
      foreach ($this->expense_lines($release->ID, 'release') as $line) {
        if ($line['kind'] === 'expense' && $line['status'] === 'approved') $costs += (float)$line['amount'];
      }
    }
    foreach ($this->expense_lines($artist_id, 'artist') as $line) {
      if ($line['kind'] === 'expense' && $line['status'] === 'approved') $costs += (float)$line['amount'];
    }
    return ['budget' => $budget, 'costs' => $costs];
  }

  private function artist_payload_from_request(array $source, int $actor_user_id, bool $force_actor_identity = false): array {
    $rep_identity = $force_actor_identity ? $this->current_user_rep_identity($actor_user_id) : [];
    $job_path_term_id = $force_actor_identity ? (int)($rep_identity['job_path_term_id'] ?? 0) : (int)($source['job_path_term_id'] ?? 0);
    $job_title = $force_actor_identity ? (string)($rep_identity['job_title'] ?? '') : $this->normalize_artist_job_title((string)($source['job_title'] ?? ''));

    return [
      'artist_name' => sanitize_text_field((string)($source['artist_name'] ?? 'Untitled Artist')),
      'status' => sanitize_key((string)($source['status'] ?? 'active')),
      'division' => sanitize_text_field((string)($source['division'] ?? '')),
      'summary' => wp_kses_post((string)($source['artist_summary'] ?? $source['summary'] ?? '')),
      'job_path_term_id' => max(0, $job_path_term_id),
      'job_title' => $job_title,
      'job_title_label' => $this->job_title_label_from_value($job_title),
      'summary_text' => 'Artist information update',
    ];
  }

  private function apply_artist_profile_payload(int $artist_id, array $payload): void {
    $current = $this->artist_profile($artist_id);
    $next = [
      'status' => sanitize_key((string)($payload['status'] ?? $current['status'] ?? 'active')),
      'division' => sanitize_text_field((string)($payload['division'] ?? $current['division'] ?? '')),
      'summary' => wp_kses_post((string)($payload['summary'] ?? $current['summary'] ?? '')),
      'lead_representative_user_id' => (int)($current['lead_representative_user_id'] ?? 0),
      'job_path_term_id' => (int)($current['job_path_term_id'] ?? 0),
    ];
    if (!empty($payload['job_path_term_id'])) {
      $next['job_path_term_id'] = (int)$payload['job_path_term_id'];
    }
    update_post_meta($artist_id, self::META_ARTIST_PROFILE, $next);
    wp_update_post([
      'ID' => $artist_id,
      'post_content' => wp_kses_post((string)$next['summary']),
    ]);
  }

  private function save_primary_representative_rule(int $artist_id, int $job_path_term_id, string $job_title): void {
    $job_path_term_id = max(0, (int)$job_path_term_id);
    $job_title = $this->normalize_artist_job_title($job_title);
    if ($artist_id <= 0 || $job_path_term_id <= 0 || $job_title === '') return;

    $raw_rules = (array)get_post_meta($artist_id, self::META_TEAM_RULES, true);
    $kept_rules = [];
    foreach ($raw_rules as $row) {
      if (!is_array($row)) continue;
      $role = $this->normalize_team_role((string)($row['role'] ?? 'member'));
      if ($role === 'representative') continue;
      $kept_rules[] = [
        'job_path_term_id' => (int)($row['job_path_term_id'] ?? 0),
        'job_title' => $this->normalize_artist_job_title((string)($row['job_title'] ?? '')),
        'role' => $role,
      ];
    }
    array_unshift($kept_rules, [
      'job_path_term_id' => $job_path_term_id,
      'job_title' => $job_title,
      'role' => 'representative',
    ]);
    update_post_meta($artist_id, self::META_TEAM_RULES, array_values($kept_rules));

    $profile = $this->artist_profile($artist_id);
    $profile['lead_representative_user_id'] = 0;
    $profile['job_path_term_id'] = $job_path_term_id;
    update_post_meta($artist_id, self::META_ARTIST_PROFILE, $profile);
  }

  private function create_artist_from_payload(array $payload, int $user_id): int {
    $artist_id = wp_insert_post([
      'post_type' => self::CPT_ARTIST,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field((string)($payload['artist_name'] ?? 'Untitled Artist')),
      'post_content' => wp_kses_post((string)($payload['summary'] ?? '')),
      'post_author' => $user_id,
    ]);
    if (is_wp_error($artist_id) || $artist_id <= 0) return 0;

    update_post_meta($artist_id, self::META_ARTIST_PROFILE, [
      'status' => sanitize_key((string)($payload['status'] ?? 'active')),
      'division' => sanitize_text_field((string)($payload['division'] ?? '')),
      'summary' => wp_kses_post((string)($payload['summary'] ?? '')),
      'lead_representative_user_id' => 0,
      'job_path_term_id' => (int)($payload['job_path_term_id'] ?? 0),
    ]);
    update_post_meta($artist_id, self::META_CREATED_BY, $user_id);
    $this->save_primary_representative_rule($artist_id, (int)($payload['job_path_term_id'] ?? 0), (string)($payload['job_title'] ?? ''));
    $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Artist created');
    return (int)$artist_id;
  }

  private function approver_ids_for_new_artist(int $actor_user_id, int $job_path_term_id): array {
    $approver_ids = [];
    if (class_exists('EUM_Manager_IDs')) {
      $approver_ids = array_merge($approver_ids, EUM_Manager_IDs::manager_user_ids_for_subject_user($actor_user_id));
    } elseif (class_exists('EUM_Personnel')) {
      $manager_id = (int)get_user_meta($actor_user_id, EUM_Personnel::META_MANAGER_ID, true);
      if ($manager_id > 0) $approver_ids[] = $manager_id;
    }
    if (empty($approver_ids) && $job_path_term_id > 0) {
      $approver_ids = array_merge($approver_ids, $this->manager_users_for_job_path($job_path_term_id));
    }
    return array_values(array_unique(array_filter(array_map('intval', $approver_ids), fn($uid) => $uid > 0 && $uid !== $actor_user_id)));
  }

  private function can_approve(int $artist_id, int $user_id, int $approval_id): bool {
    if ($user_id <= 0) return false;
    if (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_user($user_id)) return true;
    $approver_ids = $this->approval_approver_ids($approval_id);
    if (!empty($approver_ids)) return in_array($user_id, $approver_ids, true);
    $context = (string)get_post_meta($approval_id, self::META_APPROVAL_CONTEXT, true);
    $role = $this->user_role_for_artist($artist_id, $user_id);
    if ($context === 'money' || $context === 'money_line') {
      return in_array($role, ['manager','finance'], true);
    }
    return in_array($role, ['representative','manager','finance'], true);
  }

  public function handle_create_artist(): void {
    $this->verify_post();
    $user_id = (int)get_current_user_id();
    if (!$this->can_create_artist($user_id)) wp_die('Not allowed');

    $force_actor_identity = !EUM_Access::is_it_or_hr($user_id);
    $payload = $this->artist_payload_from_request($_POST, $user_id, $force_actor_identity);
    if ((int)($payload['job_path_term_id'] ?? 0) <= 0 || (string)($payload['job_title'] ?? '') === '' || !$this->is_valid_job_title_for_job_term((int)$payload['job_path_term_id'], (string)$payload['job_title'])) {
      $this->redirect_notice([], 'A representative job path and title are required.');
    }

    if (EUM_Access::is_it_or_hr($user_id)) {
      $artist_id = $this->create_artist_from_payload($payload, $user_id);
      if ($artist_id <= 0) wp_die('Could not create artist');
      $this->redirect_notice(['artist_id' => $artist_id], 'Artist created.');
    }

    $approver_ids = $this->approver_ids_for_new_artist($user_id, (int)$payload['job_path_term_id']);
    if (empty($approver_ids)) {
      $this->redirect_notice([], 'No manager is available to approve this artist yet.');
    }

    $this->create_approval(0, 'artist', 0, 'create_artist', $payload, $user_id, 'New artist approval', $approver_ids);
    $this->redirect_notice([], 'Artist submitted for manager approval.');
  }

  public function handle_save_artist(): void {
    $this->verify_post();
    $artist_id = (int)($_POST['artist_id'] ?? 0);
    $user_id = (int)get_current_user_id();
    if ($artist_id <= 0 || !$this->can_view_artist($artist_id, $user_id)) wp_die('Not allowed');

    $payload = [
      'status' => sanitize_key((string)($_POST['status'] ?? 'active')),
      'division' => sanitize_text_field((string)($_POST['division'] ?? '')),
      'summary' => wp_kses_post((string)($_POST['summary'] ?? '')),
      'summary_text' => 'Artist information update',
    ];

    if ($this->can_manage_artist($artist_id, $user_id) && $this->user_role_for_artist($artist_id, $user_id) !== 'member') {
      $this->apply_artist_profile_payload($artist_id, $payload);
      $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Artist information updated');
      $this->redirect_notice(['artist_id' => $artist_id], 'Artist updated.');
    }

    $this->create_approval($artist_id, 'artist', $artist_id, 'artist_update', $payload, $user_id, 'Artist information update');
    $this->redirect_notice(['artist_id' => $artist_id], 'Artist changes submitted for approval.');
  }

  public function handle_add_release(): void {
    $this->verify_post();
    $artist_id = (int)($_POST['artist_id'] ?? 0);
    $user_id = (int)get_current_user_id();
    $role = $this->user_role_for_artist($artist_id, $user_id);
    if ($artist_id <= 0 || !in_array($role, ['member','representative','manager','finance'], true) && !$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');

    $payload = $this->release_payload_from_request($_POST, $artist_id);
    $title = sanitize_text_field((string)($payload['release_title'] ?? 'Untitled Release'));

    if (in_array($role, ['representative','manager','finance'], true) || $this->can_manage_artist($artist_id, $user_id)) {
      $release_id = $this->create_release_from_payload($payload, $user_id);
      $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Release created: '.$title);
      $this->redirect_notice(['artist_id' => $artist_id, 'release_id' => $release_id], 'Release created.');
    }

    $this->create_approval($artist_id, 'artist', $artist_id, 'create_release', $payload, $user_id, 'New release proposal');
    $this->redirect_notice(['artist_id' => $artist_id], 'Release submitted for approval.');
  }

  private function create_release_from_payload(array $payload, int $user_id): int {
    $release_id = wp_insert_post([
      'post_type' => self::CPT_RELEASE,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field((string)($payload['release_title'] ?? 'Untitled Release')),
      'post_author' => $user_id,
    ]);
    if (is_wp_error($release_id) || $release_id <= 0) return 0;
    update_post_meta($release_id, self::META_RELEASE_ARTIST_ID, (int)($payload['artist_id'] ?? 0));
    update_post_meta($release_id, self::META_RELEASE_TYPE, sanitize_key((string)($payload['release_type'] ?? 'single')));
    update_post_meta($release_id, self::META_RELEASE_DATE, sanitize_text_field((string)($payload['release_date'] ?? '')));
    update_post_meta($release_id, self::META_RELEASE_STATUS, sanitize_key((string)($payload['release_status'] ?? 'planning')));
    update_post_meta($release_id, self::META_RELEASE_BUDGET, (float)($payload['release_budget'] ?? 0));
    $cost_center_term_id = $this->valid_cost_center_term_id((int)($payload['release_cost_center_term_id'] ?? 0));
    if ($cost_center_term_id > 0) update_post_meta($release_id, self::META_RELEASE_COST_CENTER_TERM_ID, $cost_center_term_id);
    else delete_post_meta($release_id, self::META_RELEASE_COST_CENTER_TERM_ID);
    update_post_meta($release_id, self::META_RELEASE_INFO, wp_kses_post((string)($payload['release_info'] ?? '')));
    update_post_meta($release_id, self::META_RELEASE_PROMO_STRATEGY, $this->normalize_strategy_input($payload['release_promo_strategy'] ?? []));
    $this->apply_release_asset_payload($release_id, $payload, $user_id);
    $artist_id = (int)($payload['artist_id'] ?? 0);
    $this->push_activity($release_id, self::META_RELEASE_ACTIVITY, $user_id, 'Release created');
    if ($artist_id > 0) {
      $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Release created: '.get_the_title($release_id));
    }
    return (int)$release_id;
  }

  public function handle_save_release(): void {
    $this->verify_post();
    $release_id = (int)($_POST['release_id'] ?? 0);
    $release = get_post($release_id);
    if (!$release || $release->post_type !== self::CPT_RELEASE) wp_die('Release not found');
    $artist_id = (int)get_post_meta($release_id, self::META_RELEASE_ARTIST_ID, true);
    $user_id = (int)get_current_user_id();
    $role = $this->user_role_for_artist($artist_id, $user_id);
    if (!in_array($role, ['member','representative','manager','finance'], true) && !$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');

    $payload = $this->release_payload_from_request($_POST, $release_id);
    $payload['release_id'] = $release_id;
    $payload['summary'] = 'Release update';

    if (in_array($role, ['representative','manager','finance'], true) || $this->can_manage_artist($artist_id, $user_id)) {
      $this->apply_release_payload($release_id, $payload, $user_id);
      $this->redirect_notice(['artist_id' => $artist_id, 'release_id' => $release_id], 'Release updated.');
    }

    $this->create_approval($artist_id, 'release', $release_id, 'release_update', $payload, $user_id, 'Release update');
    $this->redirect_notice(['artist_id' => $artist_id, 'release_id' => $release_id], 'Release changes submitted for approval.');
  }

  private function apply_release_payload(int $release_id, array $payload, int $user_id): void {
    if (!empty($payload['release_title'])) {
      wp_update_post([
        'ID' => $release_id,
        'post_title' => sanitize_text_field((string)$payload['release_title']),
      ]);
    }
    update_post_meta($release_id, self::META_RELEASE_TYPE, sanitize_key((string)($payload['release_type'] ?? 'single')));
    update_post_meta($release_id, self::META_RELEASE_DATE, sanitize_text_field((string)($payload['release_date'] ?? '')));
    update_post_meta($release_id, self::META_RELEASE_STATUS, sanitize_key((string)($payload['release_status'] ?? 'planning')));
    update_post_meta($release_id, self::META_RELEASE_BUDGET, (float)($payload['release_budget'] ?? 0));
    $cost_center_term_id = $this->valid_cost_center_term_id((int)($payload['release_cost_center_term_id'] ?? 0));
    if ($cost_center_term_id > 0) update_post_meta($release_id, self::META_RELEASE_COST_CENTER_TERM_ID, $cost_center_term_id);
    else delete_post_meta($release_id, self::META_RELEASE_COST_CENTER_TERM_ID);
    update_post_meta($release_id, self::META_RELEASE_INFO, wp_kses_post((string)($payload['release_info'] ?? '')));
    update_post_meta($release_id, self::META_RELEASE_PROMO_STRATEGY, $this->normalize_strategy_input($payload['release_promo_strategy'] ?? []));
    $this->apply_release_asset_payload($release_id, $payload, $user_id);

    $tracks = (array)get_post_meta($release_id, self::META_RELEASE_TRACKS, true);
    $append_track_id = (int)($payload['append_track_id'] ?? 0);
    if ($append_track_id > 0) {
      $already = false;
      foreach ($tracks as $row) {
        if ((int)($row['track_id'] ?? 0) === $append_track_id) { $already = true; break; }
      }
      if (!$already) {
        $info = (array)get_post_meta($append_track_id, self::META_TRACK_INFO, true);
        $tracks[] = [
          'track_id' => $append_track_id,
          'type' => sanitize_text_field((string)($info['type'] ?? 'master')),
          'is_single' => !empty($payload['append_track_single']),
        ];
        update_post_meta($release_id, self::META_RELEASE_TRACKS, array_values($tracks));
        $this->link_track_to_release($append_track_id, $release_id);
        if (!empty($payload['append_track_single'])) {
          $artist_id = (int)get_post_meta($release_id, self::META_RELEASE_ARTIST_ID, true);
          $this->ensure_single_release_for_track($artist_id, $append_track_id, $release_id, $user_id);
        }
      }
    }

    $this->validate_release_track_count($release_id);
    $this->push_activity($release_id, self::META_RELEASE_ACTIVITY, $user_id, 'Release updated');
    $artist_id = (int)get_post_meta($release_id, self::META_RELEASE_ARTIST_ID, true);
    if ($artist_id > 0) {
      $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Release updated: '.get_the_title($release_id));
    }
  }

  private function validate_release_track_count(int $release_id): void {
    $type = (string)get_post_meta($release_id, self::META_RELEASE_TYPE, true);
    $count = count($this->release_tracks($release_id));
    if ($type === 'single' && $count > 1) {
      update_post_meta($release_id, self::META_RELEASE_STATUS, 'needs_fix');
      $this->push_activity($release_id, self::META_RELEASE_ACTIVITY, 0, 'Validation notice: singles should contain exactly one track.');
    } elseif ($type === 'ep' && $count > 7) {
      update_post_meta($release_id, self::META_RELEASE_STATUS, 'needs_fix');
      $this->push_activity($release_id, self::META_RELEASE_ACTIVITY, 0, 'Validation notice: EPs should contain 7 or fewer tracks.');
    } elseif ($type === 'lp' && $count > 0 && $count < 7) {
      update_post_meta($release_id, self::META_RELEASE_STATUS, 'needs_fix');
      $this->push_activity($release_id, self::META_RELEASE_ACTIVITY, 0, 'Validation notice: LPs / Albums should contain 7 or more tracks.');
    }
  }

  private function release_payload_from_request(array $source, int $parent_id): array {
    return [
      'artist_id' => (int)($source['artist_id'] ?? 0),
      'release_title' => sanitize_text_field((string)($source['release_title'] ?? 'Untitled Release')),
      'release_type' => sanitize_key((string)($source['release_type'] ?? 'single')),
      'release_date' => sanitize_text_field((string)($source['release_date'] ?? '')),
      'release_status' => sanitize_key((string)($source['release_status'] ?? 'planning')),
      'release_budget' => (float)($source['release_budget'] ?? 0),
      'release_cost_center_term_id' => $this->valid_cost_center_term_id((int)($source['release_cost_center_term_id'] ?? 0)),
      'release_info' => wp_kses_post((string)($source['release_info'] ?? '')),
      'release_promo_strategy' => $this->normalize_strategy_input($source['release_promo_strategies'] ?? $source['release_promo_strategy'] ?? ''),
      'append_track_id' => (int)($source['append_track_id'] ?? 0),
      'append_track_single' => !empty($source['append_track_single']) ? 1 : 0,
      'release_cover_attachment_id' => $this->handle_media_upload('release_cover_file', $parent_id, false),
      'release_promo_material_attachment_ids' => $this->handle_multiple_media_uploads('release_promo_material_files', $parent_id),
      'release_promo_material_attachment_id' => $this->handle_media_upload('release_promo_material_file', $parent_id, false),
      'release_promo_material_label' => sanitize_text_field((string)($source['release_promo_material_label'] ?? 'Promo Material')),
      'release_promo_material_notes' => sanitize_text_field((string)($source['release_promo_material_notes'] ?? '')),
    ];
  }

  private function apply_release_asset_payload(int $release_id, array $payload, int $user_id): void {
    $cover_attachment_id = (int)($payload['release_cover_attachment_id'] ?? 0);
    if ($cover_attachment_id > 0) {
      $this->append_object_asset(
        $release_id,
        self::META_RELEASE_ASSETS,
        $cover_attachment_id,
        'Album Cover',
        self::ASSET_GROUP_COVER,
        $user_id,
        'Release cover artwork',
        true,
        true
      );
    }

    $promo_attachment_ids = array_values(array_unique(array_filter(array_map('intval', array_merge(
      (array)($payload['release_promo_material_attachment_ids'] ?? []),
      [(int)($payload['release_promo_material_attachment_id'] ?? 0)]
    )))));
    foreach ($promo_attachment_ids as $promo_attachment_id) {
      if ($promo_attachment_id <= 0) continue;
      $this->append_object_asset(
        $release_id,
        self::META_RELEASE_ASSETS,
        $promo_attachment_id,
        sanitize_text_field((string)($payload['release_promo_material_label'] ?? 'Promo Material')),
        self::ASSET_GROUP_PROMO,
        $user_id,
        sanitize_text_field((string)($payload['release_promo_material_notes'] ?? '')),
        false,
        false
      );
    }
  }

  public function handle_add_track(): void {
    $this->verify_post();
    $artist_id = (int)($_POST['artist_id'] ?? 0);
    $user_id = (int)get_current_user_id();
    $role = $this->user_role_for_artist($artist_id, $user_id);
    if ($artist_id <= 0 || !in_array($role, ['member','representative','manager','finance'], true) && !$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');

    $payload = $this->track_payload_from_request($_POST, $artist_id, $artist_id);

    if (in_array($role, ['representative','manager','finance'], true) || $this->can_manage_artist($artist_id, $user_id)) {
      $track_id = $this->create_track_from_payload($payload, $user_id);
      $this->redirect_notice(['artist_id' => $artist_id], 'Track created.');
    }

    $this->create_approval($artist_id, 'artist', $artist_id, 'create_track', $payload, $user_id, 'New track proposal');
    $this->redirect_notice(['artist_id' => $artist_id], 'Track submitted for approval.');
  }

  private function create_track_from_payload(array $payload, int $user_id): int {
    $track_id = wp_insert_post([
      'post_type' => self::CPT_TRACK,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field((string)($payload['track_title'] ?? 'Untitled Track')),
      'post_author' => $user_id,
    ]);
    if (is_wp_error($track_id) || $track_id <= 0) return 0;
    $artist_id = (int)($payload['artist_id'] ?? 0);
    update_post_meta($track_id, self::META_TRACK_ARTIST_ID, $artist_id);
    update_post_meta($track_id, self::META_TRACK_INFO, [
      'notes' => sanitize_textarea_field((string)($payload['track_info'] ?? '')),
      'type' => sanitize_key((string)($payload['track_type'] ?? 'master')),
      'status' => sanitize_key((string)($payload['track_status'] ?? 'draft')),
    ]);
    update_post_meta($track_id, self::META_TRACK_PROMO_STRATEGY, $this->normalize_strategy_input($payload['track_promo_strategy'] ?? []));
    if (array_key_exists('linked_release_ids', $payload)) {
      $this->sync_track_release_membership($track_id, $artist_id, (array)($payload['linked_release_ids'] ?? []));
    }
    $this->apply_track_asset_payload($track_id, $payload, $user_id);
    $this->push_activity($track_id, self::META_TRACK_ACTIVITY, $user_id, 'Track created');
    if ($artist_id > 0) $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Track created: '.get_the_title($track_id));
    return (int)$track_id;
  }

  public function handle_save_track(): void {
    $this->verify_post();
    $track_id = (int)($_POST['track_id'] ?? 0);
    $track = get_post($track_id);
    if (!$track || $track->post_type !== self::CPT_TRACK) wp_die('Track not found');
    $artist_id = (int)get_post_meta($track_id, self::META_TRACK_ARTIST_ID, true);
    $user_id = (int)get_current_user_id();
    $role = $this->user_role_for_artist($artist_id, $user_id);
    if (!in_array($role, ['member','representative','manager','finance'], true) && !$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');

    $payload = $this->track_payload_from_request($_POST, $artist_id, $track_id);
    $payload['track_id'] = $track_id;
    $payload['summary'] = 'Track update';

    if (in_array($role, ['representative','manager','finance'], true) || $this->can_manage_artist($artist_id, $user_id)) {
      $this->apply_track_payload($track_id, $payload, $user_id);
      $this->redirect_notice(['artist_id' => $artist_id], 'Track updated.');
    }

    $this->create_approval($artist_id, 'track', $track_id, 'track_update', $payload, $user_id, 'Track update');
    $this->redirect_notice(['artist_id' => $artist_id], 'Track changes submitted for approval.');
  }

  private function track_payload_from_request(array $source, int $artist_id, int $parent_id): array {
    $payload = [
      'artist_id' => $artist_id,
      'track_title' => sanitize_text_field((string)($source['track_title'] ?? 'Untitled Track')),
      'track_info' => sanitize_textarea_field((string)($source['track_info'] ?? '')),
      'track_type' => sanitize_key((string)($source['track_type'] ?? 'master')),
      'track_status' => sanitize_key((string)($source['track_status'] ?? 'draft')),
      'track_promo_strategy' => $this->normalize_strategy_input($source['track_promo_strategies'] ?? $source['track_promo_strategy'] ?? ''),
      'track_cover_attachment_id' => $this->handle_media_upload('track_cover_file', $parent_id, false),
      'track_audio_attachment_id' => $this->handle_media_upload('track_audio_file', $parent_id, false),
      'track_project_attachment_id' => $this->handle_media_upload('track_project_file', $parent_id, false),
      'track_promo_material_attachment_ids' => $this->handle_multiple_media_uploads('track_promo_material_files', $parent_id),
      'track_promo_material_attachment_id' => $this->handle_media_upload('track_promo_material_file', $parent_id, false),
      'track_promo_material_label' => sanitize_text_field((string)($source['track_promo_material_label'] ?? 'Promo Material')),
      'track_promo_material_notes' => sanitize_text_field((string)($source['track_promo_material_notes'] ?? '')),
    ];
    if (array_key_exists('linked_release_ids', $source)) {
      $payload['linked_release_ids'] = array_values(array_unique(array_filter(array_map('intval', (array)$source['linked_release_ids']))));
    }
    return $payload;
  }

  private function apply_track_payload(int $track_id, array $payload, int $user_id): void {
    if (!empty($payload['track_title'])) {
      wp_update_post([
        'ID' => $track_id,
        'post_title' => sanitize_text_field((string)$payload['track_title']),
      ]);
    }

    update_post_meta($track_id, self::META_TRACK_INFO, [
      'notes' => sanitize_textarea_field((string)($payload['track_info'] ?? '')),
      'type' => sanitize_key((string)($payload['track_type'] ?? 'master')),
      'status' => sanitize_key((string)($payload['track_status'] ?? 'draft')),
    ]);
    update_post_meta($track_id, self::META_TRACK_PROMO_STRATEGY, $this->normalize_strategy_input($payload['track_promo_strategy'] ?? []));

    if (array_key_exists('linked_release_ids', $payload)) {
      $artist_id = (int)get_post_meta($track_id, self::META_TRACK_ARTIST_ID, true);
      $this->sync_track_release_membership($track_id, $artist_id, (array)$payload['linked_release_ids']);
    }

    $this->apply_track_asset_payload($track_id, $payload, $user_id);
    $this->push_activity($track_id, self::META_TRACK_ACTIVITY, $user_id, 'Track updated');
    $artist_id = (int)get_post_meta($track_id, self::META_TRACK_ARTIST_ID, true);
    if ($artist_id > 0) {
      $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Track updated: '.get_the_title($track_id));
    }
  }

  private function apply_track_asset_payload(int $track_id, array $payload, int $user_id): void {
    $cover_attachment_id = (int)($payload['track_cover_attachment_id'] ?? 0);
    if ($cover_attachment_id > 0) {
      $this->append_object_asset(
        $track_id,
        self::META_TRACK_ASSETS,
        $cover_attachment_id,
        'Track Cover',
        self::ASSET_GROUP_COVER,
        $user_id,
        'Custom track artwork',
        true,
        true
      );
    }

    $audio_attachment_id = (int)($payload['track_audio_attachment_id'] ?? 0);
    if ($audio_attachment_id > 0) {
      $this->append_object_asset(
        $track_id,
        self::META_TRACK_ASSETS,
        $audio_attachment_id,
        'Track Upload',
        self::ASSET_GROUP_AUDIO,
        $user_id,
        'Primary track audio',
        true,
        true
      );
    }

    $project_attachment_id = (int)($payload['track_project_attachment_id'] ?? 0);
    if ($project_attachment_id > 0) {
      $this->append_object_asset(
        $track_id,
        self::META_TRACK_ASSETS,
        $project_attachment_id,
        'Project File',
        self::ASSET_GROUP_PROJECT,
        $user_id,
        'Session or project source file',
        true,
        true
      );
    }

    $promo_attachment_ids = array_values(array_unique(array_filter(array_map('intval', array_merge(
      (array)($payload['track_promo_material_attachment_ids'] ?? []),
      [(int)($payload['track_promo_material_attachment_id'] ?? 0)]
    )))));
    foreach ($promo_attachment_ids as $promo_attachment_id) {
      if ($promo_attachment_id <= 0) continue;
      $this->append_object_asset(
        $track_id,
        self::META_TRACK_ASSETS,
        $promo_attachment_id,
        sanitize_text_field((string)($payload['track_promo_material_label'] ?? 'Promo Material')),
        self::ASSET_GROUP_PROMO,
        $user_id,
        sanitize_text_field((string)($payload['track_promo_material_notes'] ?? '')),
        false,
        false
      );
    }
  }

  public function handle_add_roadmap(): void {
    $this->verify_post();
    $artist_id = (int)($_POST['artist_id'] ?? 0);
    $user_id = (int)get_current_user_id();
    if (!$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');
    $rows = $this->artist_roadmap($artist_id);
    $rows[] = [
      'id' => wp_generate_uuid4(),
      'title' => sanitize_text_field((string)($_POST['title'] ?? 'Milestone')),
      'owner_user_id' => (int)($_POST['owner_user_id'] ?? 0),
      'due_date' => sanitize_text_field((string)($_POST['due_date'] ?? '')),
      'status' => sanitize_key((string)($_POST['status'] ?? 'planned')),
      'visibility' => sanitize_key((string)($_POST['visibility'] ?? 'team')),
    ];
    update_post_meta($artist_id, self::META_ROADMAP, $rows);
    $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Roadmap milestone added');
    $this->redirect_notice(['artist_id' => $artist_id], 'Roadmap milestone added.');
  }

  public function handle_upload_asset(): void {
    $this->verify_post();
    $object_type = sanitize_key((string)($_POST['object_type'] ?? 'artist'));
    $object_id = (int)($_POST['object_id'] ?? 0);
    $user_id = (int)get_current_user_id();
    [$artist_id, $meta_key] = $this->asset_context_meta($object_type, $object_id);
    if ($artist_id <= 0 || !$this->can_view_artist($artist_id, $user_id)) wp_die('Not allowed');

    $role = $this->user_role_for_artist($artist_id, $user_id);
    if (!in_array($role, ['member','representative','manager','finance'], true) && !$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');
    $attachment_id = $this->handle_media_upload('asset_file', $object_id);
    if ($attachment_id <= 0) {
      $this->redirect_notice(['artist_id' => $artist_id], 'Upload failed.');
    }

    $asset_group = sanitize_key((string)($_POST['asset_group'] ?? self::ASSET_GROUP_GENERAL));
    $approval_state = in_array($role, ['representative','manager','finance'], true) ? 'approved' : 'proposed';
    $asset_id = $this->append_object_asset(
      $object_id,
      $meta_key,
      $attachment_id,
      sanitize_text_field((string)($_POST['asset_kind'] ?? 'File')),
      $asset_group,
      $user_id,
      sanitize_text_field((string)($_POST['notes'] ?? '')),
      false,
      empty($this->object_assets($object_id, $meta_key)),
      $approval_state
    );
    $this->push_activity($object_id, $this->activity_key_for_object_type($object_type), $user_id, 'New asset version uploaded');
    if (!in_array($role, ['representative','manager','finance'], true)) {
      $this->create_approval($artist_id, $object_type, $object_id, 'asset_upload', [
        'summary' => 'New asset upload pending approval',
        'asset_id' => $asset_id,
        'asset_group' => $asset_group,
        'meta_key' => $meta_key,
      ], $user_id, 'Asset upload approval');
      $msg = 'Asset uploaded and submitted for approval.';
    } else {
      $msg = 'Asset version uploaded.';
    }
    $this->redirect_notice(['artist_id' => $artist_id, 'release_id' => ($object_type === 'release' ? $object_id : null)], $msg);
  }

  public function handle_restore_asset(): void {
    $this->verify_post();
    $object_id = (int)($_POST['object_id'] ?? 0);
    $meta_key = sanitize_text_field((string)($_POST['meta_key'] ?? ''));
    $asset_id = sanitize_text_field((string)($_POST['asset_id'] ?? ''));
    $user_id = (int)get_current_user_id();
    if ($meta_key === self::META_ASSETS) {
      $artist_id = $object_id;
      $object_type = 'artist';
    } elseif ($meta_key === self::META_TRACK_ASSETS) {
      $artist_id = (int)get_post_meta($object_id, self::META_TRACK_ARTIST_ID, true);
      $object_type = 'track';
    } else {
      $artist_id = (int)get_post_meta($object_id, self::META_RELEASE_ARTIST_ID, true);
      $object_type = 'release';
    }
    if (!$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');

    $rows = $this->object_assets($object_id, $meta_key);
    foreach ($rows as &$row) {
      $row['is_live'] = ((string)$row['id'] === $asset_id);
    }
    unset($row);
    update_post_meta($object_id, $meta_key, $rows);
    $this->push_activity($object_id, $this->activity_key_for_object_type($object_type), $user_id, 'Asset version restored / made live');
    $this->redirect_notice(['artist_id' => $artist_id, 'release_id' => ($meta_key === self::META_RELEASE_ASSETS ? $object_id : null)], 'Asset version set as live.');
  }

  public function handle_submit_expense(): void {
    $this->verify_post();
    $context = sanitize_key((string)($_POST['context'] ?? 'artist'));
    $object_id = (int)($_POST['object_id'] ?? 0);
    $artist_id = $context === 'artist' ? $object_id : (int)get_post_meta($object_id, self::META_RELEASE_ARTIST_ID, true);
    $user_id = (int)get_current_user_id();
    if (!$this->can_submit_money($artist_id, $user_id)) wp_die('Not allowed');
    $line = [
      'id' => wp_generate_uuid4(),
      'label' => sanitize_text_field((string)($_POST['label'] ?? 'Line item')),
      'kind' => sanitize_key((string)($_POST['kind'] ?? 'expense')),
      'amount' => (float)($_POST['amount'] ?? 0),
      'occurred_on' => sanitize_text_field((string)($_POST['occurred_on'] ?? current_time('Y-m-d'))),
      'notes' => sanitize_textarea_field((string)($_POST['notes'] ?? '')),
      'status' => $this->can_manage_money($artist_id, $user_id) ? 'approved' : 'pending',
      'cost_center_term_id' => $this->resolved_money_cost_center_term_id($object_id, $context, (int)($_POST['cost_center_term_id'] ?? 0)),
      'finance_record_id' => 0,
      'receipt_id' => $this->handle_media_upload('receipt', $object_id, false),
      'submitted_by' => $user_id,
    ];
    $lines = $this->expense_lines($object_id, $context);
    $lines[] = $line;
    update_post_meta($object_id, self::META_EXPENSE_LINES.'_'.$context, $lines);
    $this->push_activity($object_id, $context === 'artist' ? self::META_ACTIVITY : self::META_RELEASE_ACTIVITY, $user_id, ($line['status'] === 'approved' ? 'Money line added' : 'Money line submitted for approval'));

    if ($line['status'] === 'approved') {
      $finance_id = $this->sync_money_line_to_finance_suite($artist_id, $object_id, $context, $line, $user_id);
      if ($finance_id > 0) {
        $lines[count($lines)-1]['finance_record_id'] = $finance_id;
        update_post_meta($object_id, self::META_EXPENSE_LINES.'_'.$context, $lines);
      }
      $msg = 'Money line added.';
    } else {
      $this->create_approval($artist_id, $context, $object_id, 'money_line', $line, $user_id, 'Expense / income approval');
      $msg = 'Expense / income submitted for approval.';
    }
    $this->redirect_notice(['artist_id' => $artist_id, 'release_id' => ($context === 'release' ? $object_id : null)], $msg);
  }

  private function sync_money_line_to_finance_suite(int $artist_id, int $object_id, string $context, array $line, int $user_id): int {
    if (!class_exists('EUM_Finance')) return 0;
    $job_path_term_id = $this->artist_job_path_term_id($artist_id);
    $record_id = wp_insert_post([
      'post_type' => EUM_Finance::CPT_RECORD,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field((string)$line['label']).' · '.($context === 'release' ? get_the_title($object_id) : get_the_title($artist_id)),
      'post_author' => $user_id,
    ]);
    if (is_wp_error($record_id) || $record_id <= 0) return 0;
    update_post_meta($record_id, EUM_Finance::META_KIND, sanitize_key((string)$line['kind']));
    update_post_meta($record_id, EUM_Finance::META_AMOUNT, (float)$line['amount']);
    update_post_meta($record_id, EUM_Finance::META_CURRENCY, 'USD');
    update_post_meta($record_id, EUM_Finance::META_OCCURRED_ON, sanitize_text_field((string)$line['occurred_on']));
    update_post_meta($record_id, EUM_Finance::META_JOB_PATH_TERM_ID, $job_path_term_id);
    $cost_center_term_id = $this->resolved_money_cost_center_term_id($object_id, $context, (int)($line['cost_center_term_id'] ?? 0));
    if ($cost_center_term_id > 0) {
      update_post_meta($record_id, EUM_Finance::META_FINANCE_PATH_TERM_ID, $cost_center_term_id);
    }
    update_post_meta($record_id, EUM_Finance::META_STATUS, 'approved');
    update_post_meta($record_id, EUM_Finance::META_SUBMITTED_BY, $user_id);
    update_post_meta($record_id, EUM_Finance::META_APPROVED_BY, $user_id);
    update_post_meta($record_id, EUM_Finance::META_APPROVED_AT, current_time('mysql'));
    update_post_meta($record_id, EUM_Finance::META_NOTES, sanitize_textarea_field((string)$line['notes']).' [Synced from A&R '.($context === 'release' ? 'release' : 'artist').' #'.$object_id.']');
    if (!empty($line['receipt_id'])) update_post_meta($record_id, EUM_Finance::META_RECEIPT_ID, (int)$line['receipt_id']);
    return (int)$record_id;
  }

  public function handle_approval(): void {
    $this->verify_post();
    $approval_id = (int)($_POST['approval_id'] ?? 0);
    $decision = sanitize_key((string)($_POST['decision'] ?? 'reject'));
    $user_id = (int)get_current_user_id();
    $artist_id = (int)get_post_meta($approval_id, self::META_APPROVAL_ARTIST_ID, true);
    if ($approval_id <= 0 || !$this->can_approve($artist_id, $user_id, $approval_id)) wp_die('Not allowed');

    update_post_meta($approval_id, self::META_APPROVAL_STATUS, $decision === 'approve' ? 'approved' : 'rejected');
    update_post_meta($approval_id, self::META_APPROVAL_APPROVER_ID, $user_id);
    $payload = (array)get_post_meta($approval_id, self::META_APPROVAL_PAYLOAD, true);
    $action = (string)get_post_meta($approval_id, self::META_APPROVAL_CONTEXT, true);
    $object_type = (string)get_post_meta($approval_id, self::META_APPROVAL_OBJECT_TYPE, true);
    $object_id = (int)get_post_meta($approval_id, self::META_APPROVAL_OBJECT_ID, true);

    if ($decision === 'approve') {
      switch ($action) {
        case 'create_artist':
          $artist_id = $this->create_artist_from_payload($payload, $user_id);
          if ($artist_id <= 0) wp_die('Could not create artist');
          $object_id = $artist_id;
          update_post_meta($approval_id, self::META_APPROVAL_ARTIST_ID, $artist_id);
          update_post_meta($approval_id, self::META_APPROVAL_OBJECT_ID, $artist_id);
          break;
        case 'create_release':
          $this->create_release_from_payload($payload, $user_id);
          break;
        case 'create_track':
          $this->create_track_from_payload($payload, $user_id);
          break;
        case 'release_update':
          $this->apply_release_payload($object_id, $payload, $user_id);
          break;
        case 'track_update':
          $this->apply_track_payload($object_id, $payload, $user_id);
          break;
        case 'artist_update':
          $this->apply_artist_profile_payload($object_id, $payload);
          break;
        case 'asset_upload':
          $meta_key = sanitize_text_field((string)($payload['meta_key'] ?? ''));
          $asset_id = sanitize_text_field((string)($payload['asset_id'] ?? ''));
          if ($meta_key !== '' && $asset_id !== '') {
            $rows = (array)get_post_meta($object_id, $meta_key, true);
            foreach ($rows as &$row) {
              if (!is_array($row)) continue;
              if ((string)($row['id'] ?? '') !== $asset_id) continue;
              $row['approval_state'] = 'approved';
            }
            unset($row);
            update_post_meta($object_id, $meta_key, $rows);
          }
          break;
        case 'money_line':
          $context = $object_type === 'release' ? 'release' : 'artist';
          $lines = $this->expense_lines($object_id, $context);
          foreach ($lines as &$line) {
            if ((string)($line['id'] ?? '') === (string)($payload['id'] ?? '')) {
              $line['status'] = 'approved';
              $line['finance_record_id'] = $this->sync_money_line_to_finance_suite($artist_id, $object_id, $context, $line, $user_id);
            }
          }
          unset($line);
          update_post_meta($object_id, self::META_EXPENSE_LINES.'_'.$context, $lines);
          break;
      }
      if (($object_id ?: $artist_id) > 0) {
        $activity_key = $object_id > 0 ? $this->activity_key_for_object_type($object_type) : self::META_ACTIVITY;
        $this->push_activity($object_id ?: $artist_id, $activity_key, $user_id, 'Approval granted: '.get_the_title($approval_id));
      }
      $msg = 'Approval applied.';
    } else {
      if ($action === 'asset_upload') {
        $meta_key = sanitize_text_field((string)($payload['meta_key'] ?? ''));
        $asset_id = sanitize_text_field((string)($payload['asset_id'] ?? ''));
        if ($meta_key !== '' && $asset_id !== '') {
          $rows = (array)get_post_meta($object_id, $meta_key, true);
          foreach ($rows as &$row) {
            if (!is_array($row)) continue;
            if ((string)($row['id'] ?? '') !== $asset_id) continue;
            $row['approval_state'] = 'rejected';
          }
          unset($row);
          update_post_meta($object_id, $meta_key, $rows);
        }
      }
      if (($object_id ?: $artist_id) > 0) {
        $activity_key = $object_id > 0 ? $this->activity_key_for_object_type($object_type) : self::META_ACTIVITY;
        $this->push_activity($object_id ?: $artist_id, $activity_key, $user_id, 'Approval rejected: '.get_the_title($approval_id));
      }
      $msg = 'Approval rejected.';
    }

    $args = ['artist_id' => $artist_id];
    if ($object_type === 'release') $args['release_id'] = $object_id;
    $this->redirect_notice($args, $msg);
  }

  private function create_approval(int $artist_id, string $object_type, int $object_id, string $context, array $payload, int $proposed_by, string $title, array $approver_ids = []): int {
    $approval_id = wp_insert_post([
      'post_type' => self::CPT_APPROVAL,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $proposed_by,
    ]);
    if (is_wp_error($approval_id) || $approval_id <= 0) return 0;
    update_post_meta($approval_id, self::META_APPROVAL_ARTIST_ID, $artist_id);
    update_post_meta($approval_id, self::META_APPROVAL_OBJECT_TYPE, $object_type);
    update_post_meta($approval_id, self::META_APPROVAL_OBJECT_ID, $object_id);
    update_post_meta($approval_id, self::META_APPROVAL_CONTEXT, $context);
    update_post_meta($approval_id, self::META_APPROVAL_PAYLOAD, $payload);
    update_post_meta($approval_id, self::META_APPROVAL_STATUS, 'pending');
    update_post_meta($approval_id, self::META_APPROVAL_PROPOSED_BY, $proposed_by);
    update_post_meta($approval_id, self::META_APPROVAL_APPROVER_IDS, array_values(array_unique(array_filter(array_map('intval', $approver_ids)))));
    return (int)$approval_id;
  }

  public function handle_set_rep_rule(): void {
    $this->verify_post();
    $artist_id = (int)($_POST['artist_id'] ?? 0);
    $user_id = (int)get_current_user_id();
    if (!$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');

    $job_path_term_id = (int)($_POST['job_path_term_id'] ?? 0);
    $job_title = $this->normalize_artist_job_title((string)($_POST['job_title'] ?? ''));
    if ($job_path_term_id <= 0 || $job_title === '' || !$this->is_valid_job_title_for_job_term($job_path_term_id, $job_title)) {
      $this->redirect_notice(['artist_id' => $artist_id], 'A representative job path and title are required.');
    }

    $this->save_primary_representative_rule($artist_id, $job_path_term_id, $job_title);
    $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Representative rule updated');
    $this->redirect_notice(['artist_id' => $artist_id], 'Representative rule saved.');
  }

  public function handle_add_team_user(): void {
    $this->verify_post();
    $artist_id = (int)($_POST['artist_id'] ?? 0);
    $user_id = (int)get_current_user_id();
    if (!$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');
    $team = array_map(static function($row) {
      return [
        'user_id' => (int)($row['user_id'] ?? 0),
        'role' => sanitize_key((string)($row['role'] ?? 'member')),
      ];
    }, $this->artist_team_users($artist_id));
    $new_user_id = (int)($_POST['user_id'] ?? 0);
    if ($new_user_id > 0) {
      $exists = false;
      foreach ($team as $row) {
        if ((int)$row['user_id'] === $new_user_id && sanitize_key((string)($row['role'] ?? 'member')) === 'member') {
          $exists = true;
          break;
        }
      }
      if (!$exists) {
        $team[] = ['user_id' => $new_user_id, 'role' => 'member'];
        update_post_meta($artist_id, self::META_TEAM_USERS, $team);
        $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Artist team user added');
      }
    }
    $this->redirect_notice(['artist_id' => $artist_id], 'Team assignment saved.');
  }

  public function handle_remove_team_user(): void {
    $this->verify_post();
    $artist_id = (int)($_POST['artist_id'] ?? 0);
    $row_index = (int)($_POST['row_index'] ?? -1);
    $user_id = (int)get_current_user_id();
    if (!$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');

    $rows = (array)get_post_meta($artist_id, self::META_TEAM_USERS, true);
    if (isset($rows[$row_index]) && $this->normalize_team_role((string)($rows[$row_index]['role'] ?? 'member')) === 'member') {
      unset($rows[$row_index]);
      update_post_meta($artist_id, self::META_TEAM_USERS, array_values($rows));
      $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Artist team user removed');
    }
    $this->redirect_notice(['artist_id' => $artist_id], 'Team member removed.');
  }

  public function handle_add_team_rule(): void {
    $this->verify_post();
    $artist_id = (int)($_POST['artist_id'] ?? 0);
    $user_id = (int)get_current_user_id();
    if (!$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');
    $term_id = (int)($_POST['job_path_term_id'] ?? 0);
    $job_title = (string)($_POST['job_title'] ?? '');
    if ($term_id > 0 && !$this->is_valid_job_title_for_job_term($term_id, $job_title, true)) {
      $this->redirect_notice(['artist_id' => $artist_id], 'That title is not valid for the selected job path.');
    }
    if ($term_id > 0) {
      $rules = array_map(function($row) {
        return [
          'job_path_term_id' => (int)($row['job_path_term_id'] ?? 0),
          'job_title' => $this->normalize_artist_job_title((string)($row['job_title'] ?? '')),
          'role' => $this->normalize_team_role((string)($row['role'] ?? 'member')),
        ];
      }, $this->artist_team_rules($artist_id));
      $rules[] = [
        'job_path_term_id' => $term_id,
        'job_title' => $this->normalize_artist_job_title($job_title),
        'role' => 'member',
      ];
      update_post_meta($artist_id, self::META_TEAM_RULES, $rules);
      $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Path + title team rule added');
    }
    $this->redirect_notice(['artist_id' => $artist_id], 'Team rule saved.');
  }

  public function handle_remove_team_rule(): void {
    $this->verify_post();
    $artist_id = (int)($_POST['artist_id'] ?? 0);
    $row_index = (int)($_POST['row_index'] ?? -1);
    $user_id = (int)get_current_user_id();
    if (!$this->can_manage_artist($artist_id, $user_id)) wp_die('Not allowed');

    $rows = (array)get_post_meta($artist_id, self::META_TEAM_RULES, true);
    if (isset($rows[$row_index]) && $this->normalize_team_role((string)($rows[$row_index]['role'] ?? 'member')) !== 'representative') {
      unset($rows[$row_index]);
      update_post_meta($artist_id, self::META_TEAM_RULES, array_values($rows));
      $this->push_activity($artist_id, self::META_ACTIVITY, $user_id, 'Path + title team rule removed');
    }
    $this->redirect_notice(['artist_id' => $artist_id], 'Team rule removed.');
  }

  private function push_activity(int $object_id, string $meta_key, int $user_id, string $message): void {
    $rows = (array)get_post_meta($object_id, $meta_key, true);
    if (!is_array($rows)) $rows = [];
    $rows[] = ['time' => current_time('mysql'), 'user_id' => $user_id, 'message' => sanitize_text_field($message)];
    update_post_meta($object_id, $meta_key, array_slice($rows, -100));
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
      if ($name === '') continue;
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

    return array_values(array_unique(array_filter(array_map('intval', $attachment_ids))));
  }

  private function append_object_asset(
    int $object_id,
    string $meta_key,
    int $attachment_id,
    string $asset_kind,
    string $asset_group,
    int $user_id,
    string $notes = '',
    bool $replace_group = false,
    bool $make_live = false,
    string $approval_state = 'approved'
  ): string {
    if ($object_id <= 0 || $attachment_id <= 0) return '';

    $rows = (array)get_post_meta($object_id, $meta_key, true);
    if (!is_array($rows)) $rows = [];
    $asset_group = sanitize_key($asset_group);

    if ($replace_group || $make_live) {
      foreach ($rows as &$row) {
        if (!is_array($row)) continue;
        if (sanitize_key((string)($row['asset_group'] ?? self::ASSET_GROUP_GENERAL)) !== $asset_group) continue;
        $row['is_live'] = false;
      }
      unset($row);
    }

    $asset_id = wp_generate_uuid4();
    $rows[] = [
      'id' => $asset_id,
      'attachment_id' => $attachment_id,
      'asset_kind' => sanitize_text_field($asset_kind),
      'notes' => sanitize_text_field($notes),
      'uploaded_by' => $user_id,
      'uploaded_at' => current_time('mysql'),
      'approval_state' => sanitize_key($approval_state),
      'is_live' => $make_live,
      'asset_group' => $asset_group,
    ];
    update_post_meta($object_id, $meta_key, array_values($rows));
    return $asset_id;
  }

  private function asset_context_meta(string $object_type, int $object_id): array {
    if ($object_type === 'release') {
      return [(int)get_post_meta($object_id, self::META_RELEASE_ARTIST_ID, true), self::META_RELEASE_ASSETS];
    }
    if ($object_type === 'track') {
      return [(int)get_post_meta($object_id, self::META_TRACK_ARTIST_ID, true), self::META_TRACK_ASSETS];
    }
    return [$object_id, self::META_ASSETS];
  }

  private function activity_key_for_object_type(string $object_type): string {
    if ($object_type === 'release') return self::META_RELEASE_ACTIVITY;
    if ($object_type === 'track') return self::META_TRACK_ACTIVITY;
    return self::META_ACTIVITY;
  }

  private function ensure_single_release_for_track(int $artist_id, int $track_id, int $source_release_id, int $user_id): int {
    $existing = get_posts([
      'post_type' => self::CPT_RELEASE,
      'post_status' => 'publish',
      'numberposts' => 1,
      'meta_query' => [
        ['key' => self::META_RELEASE_SYNC_SOURCE, 'value' => $track_id],
        ['key' => self::META_RELEASE_TYPE, 'value' => 'single'],
      ],
    ]);
    if (!empty($existing)) {
      $single_id = (int)$existing[0]->ID;
    } else {
      $single_id = wp_insert_post([
        'post_type' => self::CPT_RELEASE,
        'post_status' => 'publish',
        'post_title' => get_the_title($track_id),
        'post_author' => $user_id,
      ]);
      if (is_wp_error($single_id) || $single_id <= 0) return 0;
      update_post_meta($single_id, self::META_RELEASE_ARTIST_ID, $artist_id);
      update_post_meta($single_id, self::META_RELEASE_TYPE, 'single');
      update_post_meta($single_id, self::META_RELEASE_SYNC_SOURCE, $track_id);
    }
    update_post_meta($single_id, self::META_RELEASE_TRACKS, [[
      'track_id' => $track_id,
      'type' => ((array)get_post_meta($track_id, self::META_TRACK_INFO, true))['type'] ?? 'master',
      'is_single' => true,
    ]]);
    $source_date = (string)get_post_meta($source_release_id, self::META_RELEASE_DATE, true);
    if ($source_date !== '') update_post_meta($single_id, self::META_RELEASE_DATE, $source_date);
    $source_status = (string)get_post_meta($source_release_id, self::META_RELEASE_STATUS, true);
    if ($source_status !== '') update_post_meta($single_id, self::META_RELEASE_STATUS, $source_status);
    $this->link_track_to_release($track_id, $single_id);
    return (int)$single_id;
  }

  private function sync_track_release_membership(int $track_id, int $artist_id, array $release_ids): void {
    $valid_release_ids = [];
    foreach ($this->artist_releases($artist_id) as $release) {
      $valid_release_ids[(int)$release->ID] = true;
    }
    $release_ids = array_values(array_unique(array_filter(array_map('intval', $release_ids), static function($release_id) use ($valid_release_ids) {
      return $release_id > 0 && !empty($valid_release_ids[$release_id]);
    })));

    update_post_meta($track_id, self::META_TRACK_LINKED_RELEASES, $release_ids);

    $track_info = (array)get_post_meta($track_id, self::META_TRACK_INFO, true);
    $track_type = sanitize_text_field((string)($track_info['type'] ?? 'master'));

    foreach (array_keys($valid_release_ids) as $release_id) {
      $rows = (array)get_post_meta((int)$release_id, self::META_RELEASE_TRACKS, true);
      if (!is_array($rows)) $rows = [];
      $next_rows = [];
      $present = false;

      foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if ((int)($row['track_id'] ?? 0) === $track_id) {
          if (!in_array((int)$release_id, $release_ids, true)) continue;
          $row['type'] = $track_type;
          $row['is_single'] = !empty($row['is_single']);
          $present = true;
        }
        $next_rows[] = $row;
      }

      if (in_array((int)$release_id, $release_ids, true) && !$present) {
        $next_rows[] = [
          'track_id' => $track_id,
          'type' => $track_type,
          'is_single' => false,
        ];
      }

      update_post_meta((int)$release_id, self::META_RELEASE_TRACKS, array_values($next_rows));
      $this->validate_release_track_count((int)$release_id);
    }
  }

  private function link_track_to_release(int $track_id, int $release_id): void {
    $artist_id = (int)get_post_meta($track_id, self::META_TRACK_ARTIST_ID, true);
    $release_ids = $this->track_linked_release_ids($track_id);
    if (!in_array($release_id, $release_ids, true)) $release_ids[] = $release_id;
    $this->sync_track_release_membership($track_id, $artist_id, $release_ids);
  }

  public function sync_track_release_links(string $new_status, string $old_status, WP_Post $post): void {
    if ($post->post_type !== self::CPT_RELEASE || $new_status !== 'publish') return;
    foreach ($this->release_tracks((int)$post->ID) as $row) {
      $this->link_track_to_release((int)$row['track_id'], (int)$post->ID);
    }
  }

  private function verify_post(): void {
    check_admin_referer(self::NONCE);
    if (!is_user_logged_in()) wp_die('Please log in.');
  }

  private function redirect_notice(array $args, string $message): void {
    $args = array_filter($args, static fn($v) => $v !== null && $v !== '' && $v !== 0);
    $args['eum_ar_notice'] = rawurlencode($message);
    $url = add_query_arg($args, wp_get_referer() ?: home_url('/'));
    wp_safe_redirect($url);
    exit;
  }

  private function user_label(int $user_id): string {
    $u = $user_id > 0 ? get_userdata($user_id) : false;
    if (!$u) return 'Unknown';
    $name = trim((string)$u->display_name);
    return $name !== '' ? $name : (string)$u->user_login;
  }

  private function user_options_html(int $selected = 0): string {
    $users = get_users(['number' => 500, 'orderby' => 'display_name', 'order' => 'ASC']);
    $html = '';
    foreach ($users as $user) {
      $html .= '<option value="'.(int)$user->ID.'" '.selected($selected, (int)$user->ID, false).'>'.esc_html($this->user_label((int)$user->ID)).'</option>';
    }
    return $html;
  }

  private function track_options_html(int $artist_id): string {
    $html = '';
    foreach ($this->artist_tracks($artist_id) as $track) {
      $html .= '<option value="'.(int)$track->ID.'">'.esc_html($track->post_title).'</option>';
    }
    return $html;
  }

  private function release_options_html(int $artist_id, array $selected_ids = []): string {
    $selected_ids = array_values(array_unique(array_filter(array_map('intval', $selected_ids))));
    $html = '';
    foreach ($this->artist_releases($artist_id) as $release) {
      $html .= '<option value="' . (int)$release->ID . '" ' . selected(in_array((int)$release->ID, $selected_ids, true), true, false) . '>' . esc_html($release->post_title) . '</option>';
    }
    return $html;
  }

  private function job_path_options_html(int $selected = 0): string {
    if (!taxonomy_exists('eum_job_path')) return '';
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'number' => 0]);
    if (is_wp_error($terms)) return '';
    $html = '';
    foreach ($terms as $term) {
      $html .= '<option value="'.(int)$term->term_id.'" '.selected($selected, (int)$term->term_id, false).'>'.esc_html($this->job_path_label((int)$term->term_id)).'</option>';
    }
    return $html;
  }

  private function cost_center_options(): array {
    if (!class_exists('EUM_Finance')) return [];
    return EUM_Finance::labor_paycode_options();
  }

  private function cost_center_options_html(int $selected = 0, bool $include_none = true, string $none_label = 'No cost center'): string {
    $html = '';
    if ($include_none) {
      $html .= '<option value="0">' . esc_html($none_label) . '</option>';
    }
    foreach ($this->cost_center_options() as $term_id => $label) {
      $html .= '<option value="' . (int)$term_id . '" ' . selected($selected, (int)$term_id, false) . '>' . esc_html((string)$label) . '</option>';
    }
    return $html;
  }

  private function select_options(array $options, string $selected): string {
    $html = '';
    foreach ($options as $value => $label) {
      $html .= '<option value="'.esc_attr((string)$value).'" '.selected((string)$selected, (string)$value, false).'>'.esc_html((string)$label).'</option>';
    }
    return $html;
  }

  private function job_path_label(int $term_id): string {
    if ($term_id <= 0) return 'Unassigned';
    if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'term_full_path')) {
      return (string)EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ');
    }
    $term = get_term($term_id, 'eum_job_path');
    return $term && !is_wp_error($term) ? (string)$term->name : 'Unassigned';
  }

  private function artist_job_path_term_id(int $artist_id): int {
    $profile = $this->artist_profile($artist_id);
    if (!empty($profile['job_path_term_id'])) return (int)$profile['job_path_term_id'];
    $team_rules = $this->artist_team_rules($artist_id);
    if (!empty($team_rules[0]['job_path_term_id'])) return (int)$team_rules[0]['job_path_term_id'];
    return 0;
  }

  private function valid_cost_center_term_id(int $term_id): int {
    $term_id = max(0, (int)$term_id);
    if ($term_id <= 0) return 0;
    if (!class_exists('EUM_Finance') || !EUM_Finance::is_valid_labor_paycode($term_id)) return 0;
    return $term_id;
  }

  private function release_cost_center_term_id(int $release_id): int {
    return $this->valid_cost_center_term_id((int)get_post_meta($release_id, self::META_RELEASE_COST_CENTER_TERM_ID, true));
  }

  private function default_money_cost_center_term_id(int $object_id, string $context): int {
    return $context === 'release' ? $this->release_cost_center_term_id($object_id) : 0;
  }

  private function resolved_money_cost_center_term_id(int $object_id, string $context, int $submitted_term_id): int {
    $submitted_term_id = $this->valid_cost_center_term_id($submitted_term_id);
    if ($submitted_term_id > 0) return $submitted_term_id;
    return $this->default_money_cost_center_term_id($object_id, $context);
  }

  private function cost_center_label(int $term_id): string {
    $term_id = $this->valid_cost_center_term_id($term_id);
    if ($term_id <= 0 || !class_exists('EUM_Finance')) return '';
    return (string)EUM_Finance::labor_paycode_option_label($term_id);
  }

  private function cost_center_budget(int $term_id): float {
    $term_id = $this->valid_cost_center_term_id($term_id);
    if ($term_id <= 0 || !class_exists('EUM_Finance')) return 0.0;
    return (float)EUM_Finance::finance_path_budget($term_id);
  }

  private function format_money(float $amount, string $currency = 'USD'): string {
    return strtoupper($currency).' '.number_format($amount, 2);
  }

  private function print_styles(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    echo <<<'HTML'
<style>
.eum-ar{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#12233d}
.eum-ar__layout{display:grid;grid-template-columns:220px minmax(0,1fr);gap:24px;align-items:start}
.eum-ar__side{background:#0f1b33;color:#fff;border-radius:24px;padding:24px;position:sticky;top:24px}
.eum-ar__brand{font-size:28px;font-weight:800;margin-bottom:18px}
.eum-ar__side nav a{display:block;color:#d8e0f2;text-decoration:none;padding:12px 14px;border-radius:14px;margin:6px 0}
.eum-ar__side nav a.is-active,.eum-ar__side nav a:hover{background:#233964;color:#fff}
.eum-ar__main{min-width:0}
.eum-ar__notice{background:#edf5ff;border:1px solid #c2d8ff;color:#123;padding:12px 14px;border-radius:14px;margin-bottom:18px}
.eum-ar__hero,.eum-ar__toolbar,.eum-ar__workspace-head,.eum-ar__row{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
.eum-ar__hero,.eum-ar__toolbar,.eum-ar__workspace-head{margin-bottom:20px}
.eum-ar__hero{padding:24px;border-radius:24px;border:1px solid #dbe4f1;background:radial-gradient(circle at top right,#f9d1a7 0,#f6efe4 20%,#f4f8ff 55%,#fff 100%)}
.eum-ar__hero--workspace{align-items:stretch}
.eum-ar__hero-copy{display:grid;gap:12px;min-width:0}
.eum-ar__hero-media{width:min(240px,100%);flex:0 0 min(240px,100%)}
.eum-ar__toolbar-form input[type=search]{min-width:320px;padding:12px 14px;border-radius:14px;border:1px solid #d2dceb}
.eum-ar__new{display:inline-flex;align-items:center;background:#2e6bff;color:#fff;padding:12px 16px;border-radius:14px;text-decoration:none;font-weight:700}
.eum-ar__cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px}
.eum-ar__metric,.eum-ar__panel,.eum-ar__card-sm{background:#fff;border:1px solid #dde4ef;border-radius:20px;box-shadow:0 8px 20px rgba(13,34,71,.04)}
.eum-ar__metric{padding:18px 20px}
.eum-ar__metric-value{font-size:34px;font-weight:800}
.eum-ar__metric-label{font-size:14px;color:#62708a}
.eum-ar__panel{padding:22px;margin-bottom:20px}
.eum-ar__panel h3{margin:0;font-size:26px}
.eum-ar__panel-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}
.eum-ar__panel-head span{color:#66758e;font-size:13px;max-width:420px}
.eum-ar__table{width:100%;border-collapse:collapse}
.eum-ar__table th,.eum-ar__table td{padding:12px 10px;border-bottom:1px solid #edf1f6;text-align:left;vertical-align:top}
.eum-ar__table th{font-size:13px;letter-spacing:.03em;color:#5a6986;text-transform:uppercase}
.eum-ar__eyebrow{font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#647691;font-weight:700}
.eum-ar__sub{color:#66758e}
.eum-ar__hero-badges,.eum-ar__actions,.eum-ar__inline-forms,.eum-ar__tabs,.eum-ar__subtabs{display:flex;flex-wrap:wrap;gap:10px}
.eum-ar__badge,.eum-ar__pill{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;background:#eef3fb;font-weight:700;font-size:13px}
.eum-ar__pill--status{background:#f7efe2;color:#996b14}
.eum-ar__tabs{margin:0 0 20px;padding:12px;border-radius:24px;background:linear-gradient(180deg,#f8fbff 0,#eef4fd 100%);border:1px solid #dde6f2}
.eum-ar__subtabs{margin:0;padding:10px;border-radius:18px;background:#f6f8fc;border:1px solid #e4ebf6}
.eum-ar__tabs button,.eum-ar__subtabs button{appearance:none;border:0;padding:11px 15px;border-radius:15px;background:#eef3fb;color:#2b3e5f;font-weight:700;cursor:pointer;box-shadow:inset 0 0 0 1px #dee7f2;transition:background .15s ease,color .15s ease,box-shadow .15s ease,transform .15s ease}
.eum-ar__tabs button:hover,.eum-ar__subtabs button:hover{background:#e5eefc}
.eum-ar__tabs button.is-active,.eum-ar__subtabs button.is-active{background:#dce8ff;color:#173b72;box-shadow:inset 0 0 0 1px #bfd2fb,0 8px 18px rgba(29,67,142,.08)}
.eum-ar__tabs button:focus-visible,.eum-ar__subtabs button:focus-visible{outline:2px solid #2e6bff;outline-offset:2px}
.eum-ar__tab-panel{display:none;gap:20px;margin-bottom:20px}
.eum-ar__tab-panel.is-active{display:grid}
.eum-ar__tab-panel[hidden],.eum-ar__option-panel[hidden]{display:none!important}
.eum-ar__two,.eum-ar__split{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}
.eum-ar__stack{display:grid;gap:12px}
.eum-ar__card-sm{padding:16px}
.eum-ar__form{display:grid;gap:12px;margin-top:12px}
.eum-ar__form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.eum-ar__form label{display:grid;gap:6px;font-weight:600}
.eum-ar__form input,.eum-ar__form textarea,.eum-ar__form select{border:1px solid #d3dceb;border-radius:12px;padding:10px 12px;background:#fff}
.eum-ar__mini-form{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.eum-ar__mini-form input,.eum-ar__mini-form select{border:1px solid #d3dceb;border-radius:12px;padding:9px 10px;background:#fff}
.eum-ar__manage-grid{display:grid;gap:16px}
.eum-ar__manage-grid--create{margin-top:16px}
.eum-ar__manage-card{display:grid;grid-template-columns:minmax(180px,220px) minmax(0,1fr);gap:18px;padding:18px;border:1px solid #dde4ef;border-radius:22px;background:linear-gradient(180deg,#ffffff 0,#fbfcff 100%)}
.eum-ar__manage-card.is-expanded{padding:22px;background:linear-gradient(180deg,#fffdf8 0,#fbfcff 100%)}
.eum-ar__manage-card--create{border-style:dashed;background:linear-gradient(180deg,#fff 0,#f6f9ff 100%)}
.eum-ar__manage-media{min-width:0}
.eum-ar__manage-main{display:grid;gap:16px;min-width:0}
.eum-ar__manage-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.eum-ar__manage-head h3{margin:0;font-size:24px}
.eum-ar__meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px}
.eum-ar__meta-cell{padding:12px 14px;border-radius:16px;background:#f5f8fd;border:1px solid #e3ebf6}
.eum-ar__meta-cell span{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#6a7891;font-weight:700}
.eum-ar__meta-cell strong{display:block;margin-top:6px;color:#17304f}
.eum-ar__detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.eum-ar__detail-grid--media{align-items:start}
.eum-ar__detail-block{padding:14px 16px;border-radius:18px;background:#f8fbff;border:1px solid #e4ecf6}
.eum-ar__detail-block strong{display:block;margin-bottom:8px}
.eum-ar__detail-block p{margin:0;color:#314868}
.eum-ar__option-panel{display:none;gap:14px}
.eum-ar__option-panel.is-active{display:grid}
.eum-ar__strategy-list{display:grid;gap:8px}
.eum-ar__strategy-chip{padding:10px 12px;border-radius:14px;background:#fff;border:1px solid #e0e8f3;color:#213554}
.eum-ar__media-thumb{aspect-ratio:1/1;border-radius:22px;overflow:hidden;border:1px solid #d8e2ef;background:linear-gradient(180deg,#f7f9fd 0,#edf3fb 100%);display:flex;align-items:center;justify-content:center}
.eum-ar__media-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.eum-ar__media-placeholder{display:grid;gap:8px;padding:18px;text-align:center;color:#51627f}
.eum-ar__media-placeholder strong{font-size:15px}
.eum-ar__media-placeholder span{font-size:13px}
.eum-ar__file-list{display:grid;gap:10px}
.eum-ar__file-chip{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:10px 12px;border-radius:14px;background:#fff;border:1px solid #e0e8f3}
.eum-ar__audio{display:grid;gap:10px}
.eum-ar__audio audio{width:100%}
.eum-ar__audio-meta{display:flex;justify-content:space-between;gap:12px;align-items:center}
.eum-ar__audio-meta a{text-decoration:none;font-weight:700}
.eum-ar__manage-form{padding-top:16px;border-top:1px solid #e5ebf4}
.eum-ar__empty--tight{padding:12px 14px}
.eum-ar__artist-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
.eum-ar__artist-card{display:grid;gap:14px;padding:18px;border:1px solid #dde4ef;border-radius:20px;background:linear-gradient(180deg,#fff 0,#f9fbff 100%)}
.eum-ar__artist-card h4{margin:0 0 4px;font-size:20px}
.eum-ar__artist-card h4 a{text-decoration:none;color:#12233d}
.eum-ar__artist-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.eum-ar__artist-card-actions{display:flex;justify-content:flex-end}
.eum-ar__kv{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0}
.eum-ar__kv div{padding:10px 12px;border-radius:14px;background:#f5f8fd}
.eum-ar__kv dt{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#647691}
.eum-ar__kv dd{margin:4px 0 0;font-weight:600;color:#223655}
.eum-ar__identity{padding:14px 16px;border-radius:16px;background:#f4f7fc;border:1px solid #d7e0ee;margin-bottom:14px}
.eum-ar__identity.is-incomplete{background:#fff8eb;border-color:#f0d59b}
.eum-ar__actions form{margin:0}
.eum-ar__money-total{margin-top:12px;color:#2a3f60}
.eum-ar__empty{padding:18px;background:#f8fbff;border:1px dashed #ced8e7;border-radius:16px;color:#66778f}
.eum-ar__timeline{margin:0;padding-left:22px}
.eum-ar__timeline li{margin:0 0 12px;color:#243754}
.description{color:#6a7891;font-size:13px}
@media (max-width: 1000px){
  .eum-ar__layout{grid-template-columns:1fr}
  .eum-ar__side{position:static}
  .eum-ar__two,.eum-ar__split{grid-template-columns:1fr}
  .eum-ar__toolbar,.eum-ar__workspace-head,.eum-ar__hero,.eum-ar__row,.eum-ar__panel-head,.eum-ar__manage-head,.eum-ar__audio-meta{flex-direction:column}
  .eum-ar__kv{grid-template-columns:1fr}
  .eum-ar__manage-card{grid-template-columns:1fr}
  .eum-ar__hero-media{width:100%;flex-basis:auto}
}
</style>
<script>
(function () {
  if (window.eumArTabsInit) return;
  window.eumArTabsInit = true;

  function each(nodes, callback) {
    Array.prototype.forEach.call(nodes, callback);
  }

  function byGroup(attribute, group) {
    var matches = [];
    each(document.querySelectorAll("[" + attribute + "]"), function (node) {
      if (node.getAttribute(attribute) === group) matches.push(node);
    });
    return matches;
  }

  function firstActiveButton(group) {
    var buttons = byGroup("data-eum-tab-group", group);
    for (var i = 0; i < buttons.length; i += 1) {
      if (buttons[i].classList.contains("is-active")) return buttons[i];
    }
    return buttons.length ? buttons[0] : null;
  }

  function activate(group, target) {
    if (!group || !target) return;

    each(byGroup("data-eum-tab-group", group), function (button) {
      var active = button.getAttribute("data-eum-tab-target") === target;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-selected", active ? "true" : "false");
    });

    each(byGroup("data-eum-tab-panel-group", group), function (panel) {
      var active = panel.getAttribute("data-eum-tab-panel") === target;
      panel.classList.toggle("is-active", active);
      panel.hidden = !active;
    });
  }

  function init() {
    var seen = {};
    each(document.querySelectorAll("[data-eum-tab-group]"), function (button) {
      var group = button.getAttribute("data-eum-tab-group");
      var activeButton;
      if (!group || seen[group]) return;
      seen[group] = true;
      activeButton = firstActiveButton(group);
      if (activeButton) {
        activate(group, activeButton.getAttribute("data-eum-tab-target"));
      }
    });
  }

  document.addEventListener("click", function (event) {
    var target = event.target && event.target.nodeType === 1 ? event.target : event.target.parentElement;
    var button = target && target.closest ? target.closest("[data-eum-tab-target]") : null;
    if (!button) return;
    event.preventDefault();
    activate(button.getAttribute("data-eum-tab-group"), button.getAttribute("data-eum-tab-target"));
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
</script>
HTML;
  }
}
