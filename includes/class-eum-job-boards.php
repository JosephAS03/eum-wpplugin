<?php
if (!defined('ABSPATH')) exit;

class EUM_Job_Boards {
  const CPT_JOB = 'eum_job_posting';
  const CPT_CANDIDATE = 'eum_job_candidate';
  const OPTION_SETTINGS = 'eum_job_board_settings';
  const USER_META_CANDIDATE_IDS = '_eum_candidate_ids';

  const JOB_META_JOB_TERM = '_eum_job_term_id';
  const JOB_META_JOB_TITLE = '_eum_job_title';
  const JOB_META_NEAT_TITLE = '_eum_job_neat_title';
  const JOB_META_DESCRIPTION = '_eum_job_description';
  const JOB_META_REQUIREMENTS = '_eum_job_requirements';
  const JOB_META_SHORT_DESCRIPTION = '_eum_job_short_description';
  const JOB_META_LOCATION = '_eum_job_location';
  const JOB_META_SOCIAL_IMAGE_ID = '_eum_job_social_image_id';
  const JOB_META_APPLICATION_FIELDS = '_eum_job_application_fields';
  const JOB_META_VISIBILITY = '_eum_job_visibility';
  const JOB_META_STATUS = '_eum_job_status';
  const JOB_META_OWNER_UID = '_eum_job_owner_user_id';
  const JOB_META_OWNER_MIDS = '_eum_job_owner_manager_ids';
  const JOB_META_PRESET_USER_META = '_eum_job_preset_user_meta';

  const CAND_META_JOB_ID = '_eum_candidate_job_id';
  const CAND_META_FULL_NAME = '_eum_candidate_full_name';
  const CAND_META_EMAIL = '_eum_candidate_email';
  const CAND_META_PHONE = '_eum_candidate_phone';
  const CAND_META_SOURCE = '_eum_candidate_source';
  const CAND_META_SOURCE_LABEL = '_eum_candidate_source_label';
  const CAND_META_STATUS = '_eum_candidate_status';
  const CAND_META_PROFILE = '_eum_candidate_profile';
  const CAND_META_APPLICATION_RESPONSES = '_eum_candidate_application_responses';
  const CAND_META_HIRE_ID = '_eum_candidate_hire_id';
  const CAND_META_USER_ID = '_eum_candidate_user_id';
  const CAND_META_TRANSFER_CHANGE_ID = '_eum_candidate_transfer_change_id';
  const CAND_META_HIRING_DATE = '_eum_candidate_hiring_manager_date';
  const CAND_META_CURRENT_DATE = '_eum_candidate_current_manager_date';
  const CAND_META_REHIRE_CHANGE_ID = '_eum_candidate_rehire_change_id';
  const CAND_META_APPLIED_AT = '_eum_candidate_applied_at';
  const CAND_META_APPLICATION_CHANGE_ID = '_eum_candidate_application_change_id';
  const CAND_META_APPLICATION_HISTORY_IDS = '_eum_candidate_application_change_ids';
  const CAND_META_PRE_HIRE_STATUS = '_eum_candidate_pre_hire_status';
  const CAND_META_INVITE_CODE = '_eum_candidate_invite_code';
  const CAND_META_INVITE_CODE_LOOKUP = '_eum_candidate_invite_code_lookup';
  const CAND_META_INVITE_CREATED_AT = '_eum_candidate_invite_created_at';
  const CAND_META_INVITE_SENT_AT = '_eum_candidate_invite_sent_at';
  const CAND_META_INVITE_COMPLETED_AT = '_eum_candidate_invite_completed_at';
  const CAND_META_REVIEW_INVITE_CODE = '_eum_candidate_review_invite_code';
  const CAND_META_REVIEW_INVITE_LOOKUP = '_eum_candidate_review_invite_lookup';
  const CAND_META_REVIEW_INVITE_CREATED_AT = '_eum_candidate_review_invite_created_at';
  const CAND_META_REVIEW_INVITE_COMPLETED_AT = '_eum_candidate_review_invite_completed_at';
  const CAND_META_SIGNUP_REDIRECT_URL = '_eum_candidate_signup_redirect_url';
  const CAND_META_SIGNUP_REDIRECT_HISTORY = '_eum_candidate_signup_redirect_history';
  const CAND_META_PRESET_USER_META = '_eum_candidate_preset_user_meta';

  const JOB_PATH_META_DEFAULT_TITLE = 'eum_job_default_title';
  const JOB_PATH_META_TEMPLATE_DESC = 'eum_job_template_desc';
  const JOB_PATH_META_TEMPLATE_REQS = 'eum_job_template_requirements';

  public function init(): void {
    add_action('init', [$this, 'register_cpts']);
    add_action('rest_api_init', [$this, 'register_rest_routes']);
    add_action('admin_menu', [$this, 'register_admin_menu'], 30);

    add_action('eum_job_path_add_form_fields', [$this, 'render_job_path_term_fields_add']);
    add_action('eum_job_path_edit_form_fields', [$this, 'render_job_path_term_fields_edit']);
    add_action('created_eum_job_path', [$this, 'save_job_path_term_fields']);
    add_action('edited_eum_job_path', [$this, 'save_job_path_term_fields']);

    add_action('admin_post_eum_job_board_settings_save', [$this, 'handle_settings_save']);
    add_action('admin_post_eum_job_board_save', [$this, 'handle_job_save']);
    add_action('admin_post_eum_job_board_clone', [$this, 'handle_job_clone']);
    add_action('admin_post_eum_job_board_delete', [$this, 'handle_job_delete']);
    add_action('admin_post_eum_job_board_apply', [$this, 'handle_candidate_apply']);
    add_action('admin_post_nopriv_eum_job_board_apply', [$this, 'handle_candidate_apply']);
    add_action('admin_post_eum_job_board_feed', [$this, 'handle_public_feed']);
    add_action('admin_post_nopriv_eum_job_board_feed', [$this, 'handle_public_feed']);
    add_action('admin_post_eum_job_board_apply_api', [$this, 'handle_public_apply_api']);
    add_action('admin_post_nopriv_eum_job_board_apply_api', [$this, 'handle_public_apply_api']);
    add_action('admin_post_eum_job_board_candidate_update', [$this, 'handle_candidate_update']);
    add_action('admin_post_eum_job_board_candidate_hire', [$this, 'handle_candidate_hire']);
    add_action('admin_post_eum_job_board_candidate_delete', [$this, 'handle_candidate_delete']);
    add_action('admin_post_eum_job_board_candidate_add_manual', [$this, 'handle_candidate_add_manual']);
    add_action('admin_post_eum_job_board_candidate_create_invite', [$this, 'handle_candidate_create_invite']);
    add_action('admin_post_eum_job_board_candidate_create_review_invite', [$this, 'handle_candidate_create_review_invite']);
    add_action('admin_post_eum_job_board_candidate_bulk_signup_redirect', [$this, 'handle_candidate_bulk_signup_redirect']);
    add_action('admin_post_eum_job_board_candidate_clone_invite', [$this, 'handle_candidate_clone_invite']);
    add_action('admin_post_eum_job_board_candidate_signup', [$this, 'handle_preaccepted_candidate_signup']);
    add_action('admin_post_nopriv_eum_job_board_candidate_signup', [$this, 'handle_preaccepted_candidate_signup']);
    add_action('admin_post_eum_job_board_candidate_review', [$this, 'handle_candidate_review']);
    add_action('admin_post_nopriv_eum_job_board_candidate_review', [$this, 'handle_candidate_review']);
    add_action('admin_post_eum_job_board_candidate_pending_hire_update', [$this, 'handle_candidate_pending_hire_update']);
    add_action('template_redirect', [$this, 'render_candidate_signup_landing'], 1);
    add_action('eum_profile_change_applied', [$this, 'handle_profile_change_applied'], 10, 2);
    add_action('user_register', [$this, 'handle_user_email_candidate_matches'], 20, 1);
    add_action('profile_update', [$this, 'handle_user_email_candidate_matches'], 20, 1);
    add_filter('eum_allow_public_request', [$this, 'allow_preaccepted_candidate_public_request']);

    add_shortcode('eum_job_board', [$this, 'shortcode_manage']);
    add_shortcode('eum_internal_job_board', [$this, 'shortcode_internal']);
    add_shortcode('eum_external_job_board', [$this, 'shortcode_external']);
    add_shortcode('eum_candidate_signup', [$this, 'shortcode_candidate_signup']);
    add_filter('do_parse_request', [$this, 'intercept_candidate_join_request'], 1, 3);

    // Register this after the job-board hooks and shortcodes so a rewrite
    // issue cannot prevent the rest of the module from booting.
    add_filter('query_vars', [$this, 'register_candidate_join_query_var']);
    if (function_exists('add_rewrite_rule')) {
      add_rewrite_rule('^join/([^/]+)/?$', 'index.php?eum_candidate_join=$matches[1]', 'top');
    } else {
      add_action('init', [$this, 'register_candidate_join_rewrite_rule'], 1);
    }
    add_action('init', [$this, 'maybe_flush_candidate_join_rewrite'], 20);
  }

  public function register_cpts(): void {
    register_post_type(self::CPT_JOB, [
      'labels' => ['name' => 'Job Postings', 'singular_name' => 'Job Posting'],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
    ]);
    register_post_type(self::CPT_CANDIDATE, [
      'labels' => ['name' => 'Job Candidates', 'singular_name' => 'Job Candidate'],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
    ]);
  }

  public function register_candidate_join_query_var(array $vars): array {
    $vars[] = 'eum_candidate_join';
    return array_values(array_unique($vars));
  }

  public function register_candidate_join_rewrite_rule(): void {
    if (function_exists('add_rewrite_rule')) {
      add_rewrite_rule('^join/([^/]+)/?$', 'index.php?eum_candidate_join=$matches[1]', 'top');
    }
  }

  public function intercept_candidate_join_request($do_parse, $wp, $extra_query_vars = []) {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    if (!preg_match('#/join/([^/?]+)/?(?:\?|$)#i', $request_uri, $matches)) return $do_parse;
    $code = sanitize_text_field(rawurldecode((string)($matches[1] ?? '')));
    if ($code === '') return $do_parse;
    return false;
  }

  public function maybe_flush_candidate_join_rewrite(): void {
    if (!function_exists('flush_rewrite_rules') || !function_exists('get_option') || !function_exists('update_option')) return;
    if ((string)get_option('eum_candidate_join_rewrite_version', '') === '2') return;
    flush_rewrite_rules(false);
    update_option('eum_candidate_join_rewrite_version', '2', false);
  }

  public function register_rest_routes(): void {
    register_rest_route('eum-job-board/v1', '/feed', [
      'methods' => 'GET',
      'callback' => [$this, 'rest_feed'],
      'permission_callback' => '__return_true',
    ]);
    register_rest_route('eum-job-board/v1', '/apply', [
      'methods' => 'POST',
      'callback' => [$this, 'rest_apply'],
      'permission_callback' => '__return_true',
    ]);
  }

  public function register_admin_menu(): void {
    add_submenu_page(EUM_Admin::parent_slug('workforce'), 'Job Boards', 'Job Boards', 'read', 'eum-job-boards', [$this, 'render_settings_page']);
  }

  public static function settings(): array {
    $defaults = [
      'external_source_url' => '',
      'external_feed_token' => wp_generate_password(24, false, false),
      'external_source_token' => '',
      'external_apply_url' => '',
      'candidate_source_options' => '',
      'candidate_signup_page_url' => '',
    ];
    $stored_settings = get_option(self::OPTION_SETTINGS, []);
    $settings = array_merge($defaults, is_array($stored_settings) ? $stored_settings : []);
    // Preserve tokens already embedded in legacy source URLs. If there is no
    // source token in the URL, retain the previous shared-token behavior.
    if (!is_array($stored_settings) || !array_key_exists('external_source_token', $stored_settings)) {
      $source_token = '';
      $source_parts = wp_parse_url((string)$settings['external_source_url']);
      if (is_array($source_parts) && !empty($source_parts['query'])) {
        $source_query = [];
        parse_str((string)$source_parts['query'], $source_query);
        $source_token = sanitize_text_field((string)($source_query['token'] ?? ''));
      }
      $settings['external_source_token'] = $source_token !== '' ? $source_token : (string)$settings['external_feed_token'];
    }
    return $settings;
  }

  public static function can_manage_jobs(int $user_id): bool {
    return $user_id > 0 && (EUM_Access::is_it_or_hr($user_id) || EUM_Access::is_manager_user($user_id));
  }

  public static function can_direct_create_users(int $user_id): bool {
    return EUM_Access::is_it_or_hr($user_id);
  }

  public function render_settings_page(): void {
    if (!current_user_can('read') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $settings = self::settings();
    $feed = add_query_arg(['action' => 'eum_job_board_feed', 'token' => $settings['external_feed_token']], admin_url('admin-post.php'));
    $apply = add_query_arg(['action' => 'eum_job_board_apply_api'], admin_url('admin-post.php'));
    $rest_feed = add_query_arg(['token' => $settings['external_feed_token']], rest_url('eum-job-board/v1/feed'));
    $rest_apply = rest_url('eum-job-board/v1/apply');
    echo '<div class="wrap"><h1>Job Boards</h1><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_job_board_settings_save">';
    wp_nonce_field('eum_job_board_settings_save', 'eum_job_board_settings_nonce');
    echo '<table class="form-table"><tr><th>External Source URL</th><td><input type="url" class="regular-text" name="settings[external_source_url]" value="' . esc_attr($settings['external_source_url']) . '"></td></tr>';
    echo '<tr><th>External Feed Token</th><td><input type="text" class="regular-text" name="settings[external_feed_token]" value="' . esc_attr($settings['external_feed_token']) . '"><p class="description">Protects this site’s own feed and application endpoints. It is separate from the External Source Token below.</p></td></tr>';
    echo '<tr><th>External Source Token</th><td><input type="text" class="regular-text" name="settings[external_source_token]" value="' . esc_attr($settings['external_source_token']) . '"><p class="description">Token used to read jobs from the External Source URL. Copy the feed token from the source career site here. This may differ from this site’s External Feed Token. Leave blank if the source URL already contains its token.</p></td></tr>';
    echo '<tr><th>External Apply URL</th><td><input type="url" class="regular-text" name="settings[external_apply_url]" value="' . esc_attr($settings['external_apply_url']) . '"></td></tr>';
    echo '<tr><th>Candidate Signup Page URL</th><td><input type="url" class="regular-text" name="settings[candidate_signup_page_url]" value="' . esc_attr($settings['candidate_signup_page_url']) . '" placeholder="' . esc_attr(home_url('/candidate-signup/')) . '"><p class="description">Page that contains the <code>[eum_candidate_signup]</code> shortcode.</p></td></tr>';
    echo '<tr><th>Candidate Source Options</th><td><textarea class="large-text code" rows="6" name="settings[candidate_source_options]">' . esc_textarea((string)$settings['candidate_source_options']) . '</textarea><p class="description">Optional HR/IT-managed source choices. Use one per line, either <code>label</code> or <code>key | Label</code>. The candidate form always includes Other for a manual label.</p></td></tr>';
    echo '<tr><th>Internal Feed URL</th><td><code>' . esc_html($feed) . '</code><p class="description">Use this on the external site. It already includes the token in the query string.</p></td></tr>';
    echo '<tr><th>Internal Apply API</th><td><code>' . esc_html($apply) . '</code><p class="description">Post candidate applications here. Include the same token as a <code>token</code> field or a Bearer token.</p></td></tr>';
    echo '<tr><th>REST Feed URL (alternate)</th><td><code>' . esc_html($rest_feed) . '</code></td></tr>';
    echo '<tr><th>REST Apply API (alternate)</th><td><code>' . esc_html($rest_apply) . '</code></td></tr></table>';
    submit_button('Save Job Board Settings');
    echo '</form></div>';
  }

  public function handle_settings_save(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    check_admin_referer('eum_job_board_settings_save', 'eum_job_board_settings_nonce');
    $settings = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
    update_option(self::OPTION_SETTINGS, [
      'external_source_url' => esc_url_raw((string)($settings['external_source_url'] ?? '')),
      'external_feed_token' => sanitize_text_field((string)($settings['external_feed_token'] ?? wp_generate_password(24, false, false))),
      'external_source_token' => sanitize_text_field((string)($settings['external_source_token'] ?? '')),
      'external_apply_url' => esc_url_raw((string)($settings['external_apply_url'] ?? '')),
      'candidate_source_options' => $this->sanitize_candidate_source_options_text((string)($settings['candidate_source_options'] ?? '')),
      'candidate_signup_page_url' => esc_url_raw((string)($settings['candidate_signup_page_url'] ?? '')),
    ]);
    wp_safe_redirect(add_query_arg(['page' => 'eum-job-boards', 'updated' => '1'], admin_url('admin.php')));
    exit;
  }

  public function allow_preaccepted_candidate_public_request($allowed): bool {
    if ($allowed) return true;
    $action = sanitize_key((string)($_REQUEST['action'] ?? ''));
    if (in_array($action, ['eum_job_board_candidate_signup', 'eum_job_board_candidate_review'], true)) return true;
    $has_invite_parameter = array_key_exists('eum_candidate_invite', $_GET);
    $code = sanitize_text_field((string)($_GET['eum_candidate_invite'] ?? ($_GET['code'] ?? (get_query_var('eum_candidate_join') ?: ''))));
    // Let the signup page render its own invalid/expired-link message instead
    // of sending candidates to the site-wide login screen first.
    return $has_invite_parameter || ($code !== '' && (
      $this->candidate_by_invite_code($code) > 0
      || $this->candidate_by_review_invite_code($code, true) > 0
    ));
  }

  private function sanitize_candidate_source_options_text(string $raw): string {
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $clean = [];
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      $parts = array_map('trim', explode('|', $line, 2));
      $label = count($parts) === 2 ? $parts[1] : $parts[0];
      $key = count($parts) === 2 ? sanitize_key($parts[0]) : sanitize_key($label);
      $label = sanitize_text_field($label);
      if ($key === '' || $label === '' || in_array($key, ['external', 'internal', 'manual', 'other'], true)) continue;
      $clean[$key] = $label;
    }
    $out = [];
    foreach ($clean as $key => $label) $out[] = $key . ' | ' . $label;
    return implode("\n", $out);
  }

  private function candidate_source_options(): array {
    $settings = self::settings();
    $options = [
      'external' => 'External',
      'internal' => 'Internal',
      'manual' => 'Manual',
    ];
    $lines = preg_split('/\r\n|\r|\n/', (string)($settings['candidate_source_options'] ?? '')) ?: [];
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      $parts = array_map('trim', explode('|', $line, 2));
      $key = count($parts) === 2 ? sanitize_key($parts[0]) : sanitize_key($parts[0]);
      $label = count($parts) === 2 ? sanitize_text_field($parts[1]) : sanitize_text_field($parts[0]);
      if ($key !== '' && $label !== '' && !isset($options[$key])) $options[$key] = $label;
    }
    $options['other'] = 'Other';
    return $options;
  }

  private function sanitize_candidate_source_from_request(array $payload, string $default = 'external'): array {
    $source = sanitize_key((string)($payload['candidate_source'] ?? ($payload['source'] ?? $default)));
    $options = $this->candidate_source_options();
    if ($source === '' || !isset($options[$source])) $source = $default;
    if (!isset($options[$source])) $source = 'external';
    $label = $source === 'other' ? sanitize_text_field((string)($payload['candidate_source_label'] ?? '')) : '';
    if ($source === 'other' && $label === '') $label = 'Other';
    return [$source, $label];
  }

  private function candidate_source_label(int $candidate_id): string {
    $source = sanitize_key((string)get_post_meta($candidate_id, self::CAND_META_SOURCE, true));
    $manual_label = sanitize_text_field((string)get_post_meta($candidate_id, self::CAND_META_SOURCE_LABEL, true));
    if ($source === 'other' && $manual_label !== '') return $manual_label;
    $options = $this->candidate_source_options();
    return (string)($options[$source] ?? ucfirst(str_replace('_', ' ', $source ?: 'External')));
  }

  private function render_candidate_source_select(string $name, string $selected, string $label_name = 'candidate_source_label', string $label_value = '', bool $include_internal = true): string {
    $selected = sanitize_key($selected ?: 'external');
    $out = '<select name="' . esc_attr($name) . '" data-eum-candidate-source-select>';
    foreach ($this->candidate_source_options() as $key => $label) {
      if (!$include_internal && $key === 'internal') continue;
      $out .= '<option value="' . esc_attr($key) . '" ' . selected($selected, $key, false) . '>' . esc_html($label) . '</option>';
    }
    $out .= '</select>';
    $out .= '<input type="text" name="' . esc_attr($label_name) . '" data-eum-candidate-source-label style="margin-left:8px;max-width:240px;' . ($selected === 'other' ? '' : 'display:none;') . '" value="' . esc_attr($label_value) . '" placeholder="Source label">';
    $out .= '<script>(function(){if(window.EUMCandidateSourceToggleInit)return;window.EUMCandidateSourceToggleInit=function(root){root.querySelectorAll("[data-eum-candidate-source-select]").forEach(function(sel){var label=sel.parentNode.querySelector("[data-eum-candidate-source-label]");if(!label)return;var sync=function(){label.style.display=sel.value==="other"?"inline-block":"none";};sel.addEventListener("change",sync);sync();});};document.addEventListener("DOMContentLoaded",function(){window.EUMCandidateSourceToggleInit(document);});}());</script>';
    return $out;
  }

  private function invite_code_lookup(string $code): string {
    return hash_hmac('sha256', trim($code), wp_salt('auth'));
  }

  private function candidate_account_lock_name(int $candidate_id): string {
    return '_eum_candidate_account_create_lock_' . max(0, $candidate_id);
  }

  private function claim_candidate_account_lock(int $candidate_id): string {
    if ($candidate_id <= 0) return '';
    $option_name = $this->candidate_account_lock_name($candidate_id);
    $existing = get_option($option_name, false);
    $created_at = is_array($existing) ? (int)($existing['created_at'] ?? 0) : 0;
    if ($created_at > 0 && $created_at < time() - (30 * MINUTE_IN_SECONDS)) {
      delete_option($option_name);
    }

    $token = wp_generate_password(32, false, false);
    return add_option($option_name, ['token' => $token, 'created_at' => time()], '', false) ? $token : '';
  }

  private function release_candidate_account_lock(int $candidate_id, string $token): void {
    if ($candidate_id <= 0 || $token === '') return;
    $option_name = $this->candidate_account_lock_name($candidate_id);
    $lock = get_option($option_name, false);
    if (is_array($lock) && !empty($lock['token']) && hash_equals((string)$lock['token'], $token)) {
      delete_option($option_name);
    }
  }

  private function candidate_hire_created_user(int $candidate_id, int $user_id): bool {
    if ($candidate_id <= 0 || $user_id <= 0 || !class_exists('EUM_User_Creation')) return false;
    $hire_ids = get_posts([
      'post_type' => EUM_User_Creation::CPT,
      'post_status' => 'any',
      'numberposts' => -1,
      'fields' => 'ids',
      'meta_query' => [[
        'key' => EUM_User_Creation::META_CANDIDATE_ID,
        'value' => $candidate_id,
        'compare' => '=',
        'type' => 'NUMERIC',
      ]],
    ]);
    foreach ((array)$hire_ids as $hire_id) {
      if ((int)get_post_meta((int)$hire_id, EUM_User_Creation::META_CREATED_USER, true) === $user_id) return true;
    }
    return false;
  }

  private function candidate_has_scheduled_hire(int $candidate_id): bool {
    if ($candidate_id <= 0 || !class_exists('EUM_User_Creation')) return false;
    $hire_ids = get_posts([
      'post_type' => EUM_User_Creation::CPT,
      'post_status' => 'any',
      'numberposts' => 1,
      'fields' => 'ids',
      'meta_query' => [
        'relation' => 'AND',
        ['key' => EUM_User_Creation::META_CANDIDATE_ID, 'value' => $candidate_id, 'compare' => '=', 'type' => 'NUMERIC'],
        ['key' => EUM_User_Creation::META_STATUS, 'value' => 'scheduled'],
      ],
    ]);
    return !empty($hire_ids);
  }

  private function sanitize_signup_redirect_url(string $url): string {
    $url = esc_url_raw(trim($url));
    if ($url === '') return '';
    $parts = wp_parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
  }

  private function split_candidate_name(string $full_name): array {
    $full_name = trim(preg_replace('/\s+/', ' ', $full_name) ?: '');
    if ($full_name === '') return ['', ''];
    $parts = explode(' ', $full_name);
    $first = array_shift($parts);
    $last = trim(implode(' ', $parts));
    return [sanitize_text_field((string)$first), sanitize_text_field($last)];
  }

  private function next_available_signup_username(string $base): string {
    $base = sanitize_user($base, true) ?: 'user';
    $candidate = $base;
    $i = 2;
    while (username_exists($candidate)) {
      $candidate = $base . $i;
      $i++;
    }
    return $candidate;
  }

  private function send_preaccepted_candidate_email(int $candidate_id, string $invite_url): bool {
    $email = sanitize_email((string)get_post_meta($candidate_id, self::CAND_META_EMAIL, true));
    if (!is_email($email)) return false;
    $full_name = sanitize_text_field((string)get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true));
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_title = sanitize_text_field((string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true));
    if ($job_title === '') $job_title = sanitize_text_field((string)get_the_title($job_id));
    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $subject = 'Your acceptance for ' . ($job_title !== '' ? $job_title : $site_name);
    $message = "Hi " . ($full_name !== '' ? $full_name : 'there') . ",\n\n";
    $message .= "You have been accepted" . ($job_title !== '' ? " for {$job_title}" : '') . ". Please complete your account setup and personal information using this secure link:\n\n";
    $message .= $invite_url . "\n\n";
    $message .= "You will create your password during this process. This link is intended only for you.\n\n";
    $message .= "Thank you,\n" . $site_name;
    return (bool)wp_mail($email, $subject, $message);
  }

  private function candidate_signup_url(string $code): string {
    $settings = self::settings();
    $base = esc_url_raw((string)($settings['candidate_signup_page_url'] ?? ''));
    if ($base !== '' && function_exists('url_to_postid') && url_to_postid($base) <= 0) {
      $base = '';
    }
    if ($base === '') {
      $page_id = (int)get_option('eum_candidate_signup_page_id', 0);
      $base = $page_id > 0 ? get_permalink($page_id) : home_url('/');
    }
    $base_url = $base !== '' ? $base : home_url('/');
    $parts = wp_parse_url($base_url);
    $site_parts = wp_parse_url(home_url('/'));
    $base_host = is_array($parts) ? (string)($parts['host'] ?? '') : '';
    $site_host = is_array($site_parts) ? (string)($site_parts['host'] ?? '') : '';
    if ($base_host === '' || $base_host === $site_host) {
      return home_url('/join/' . rawurlencode($code) . '/');
    }
    return add_query_arg(['eum_candidate_invite' => $code], $base_url);
  }

  private function candidate_by_invite_code(string $code): int {
    $lookup = $this->invite_code_lookup($code);
    $items = get_posts([
      'post_type' => self::CPT_CANDIDATE,
      'post_status' => 'publish',
      'numberposts' => 1,
      'fields' => 'ids',
      'meta_query' => [
        'relation' => 'AND',
        ['key' => self::CAND_META_INVITE_CODE_LOOKUP, 'value' => $lookup],
        ['key' => self::CAND_META_INVITE_COMPLETED_AT, 'compare' => 'NOT EXISTS'],
      ],
    ]);
    return !empty($items) ? (int)$items[0] : 0;
  }

  private function candidate_by_review_invite_code(string $code, bool $include_completed = false): int {
    if (trim($code) === '') return 0;
    $meta_query = [
      ['key' => self::CAND_META_REVIEW_INVITE_LOOKUP, 'value' => $this->invite_code_lookup($code)],
    ];
    if (!$include_completed) $meta_query[] = ['key' => self::CAND_META_REVIEW_INVITE_COMPLETED_AT, 'compare' => 'NOT EXISTS'];
    $items = get_posts([
      'post_type' => self::CPT_CANDIDATE,
      'post_status' => 'publish',
      'numberposts' => 1,
      'fields' => 'ids',
      'meta_query' => $meta_query,
    ]);
    return !empty($items) ? (int)$items[0] : 0;
  }

  private function candidate_review_invite_url(string $code): string {
    return home_url('/join/' . rawurlencode($code) . '/');
  }

  private function candidate_review_invite_eligible(int $candidate_id): bool {
    if ($candidate_id <= 0) return false;
    $source = sanitize_key((string)get_post_meta($candidate_id, self::CAND_META_SOURCE, true));
    $status = sanitize_key((string)get_post_meta($candidate_id, self::CAND_META_STATUS, true));
    return $source !== '' && $source !== 'internal' && !in_array($status, ['pre_accepted', 'hired', 'rejected'], true);
  }

  private function ensure_candidate_review_invite(int $candidate_id, bool $regenerate = false): string {
    if (!$this->candidate_review_invite_eligible($candidate_id)) return '';
    $code = (string)get_post_meta($candidate_id, self::CAND_META_REVIEW_INVITE_CODE, true);
    $completed = (string)get_post_meta($candidate_id, self::CAND_META_REVIEW_INVITE_COMPLETED_AT, true);
    if (!$regenerate && $code !== '') return $code;
    if (!$regenerate && $completed !== '') return $code;
    $code = wp_generate_password(40, false, false);
    update_post_meta($candidate_id, self::CAND_META_REVIEW_INVITE_CODE, $code);
    update_post_meta($candidate_id, self::CAND_META_REVIEW_INVITE_LOOKUP, $this->invite_code_lookup($code));
    update_post_meta($candidate_id, self::CAND_META_REVIEW_INVITE_CREATED_AT, current_time('mysql'));
    delete_post_meta($candidate_id, self::CAND_META_REVIEW_INVITE_COMPLETED_AT);
    return $code;
  }

  public static function record_user_candidate_id(int $user_id, int $candidate_id): void {
    if ($user_id <= 0 || $candidate_id <= 0) return;
    $ids = get_user_meta($user_id, self::USER_META_CANDIDATE_IDS, true);
    $ids = is_array($ids) ? $ids : [];
    $ids = array_values(array_unique(array_filter(array_map('intval', array_merge($ids, [$candidate_id])), fn($id) => $id > 0)));
    update_user_meta($user_id, self::USER_META_CANDIDATE_IDS, $ids);
  }

  public static function apply_candidate_preset_user_meta(int $user_id, int $candidate_id): void {
    if ($user_id <= 0 || $candidate_id <= 0) return;
    $meta = get_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, true);
    $meta = is_array($meta) ? $meta : [];
    foreach ($meta as $key => $value) {
      $key = sanitize_key((string)$key);
      if ($key === '') continue;
      if (is_array($value)) {
        $existing = get_user_meta($user_id, $key, true);
        if (is_array($existing)) {
          update_user_meta($user_id, $key, array_merge($existing, $value));
          continue;
        }
      }
      update_user_meta($user_id, $key, $value);
    }
  }

  private function sanitize_preset_user_meta($raw): array {
    if (is_array($raw)) {
      $out = [];
      foreach ($raw as $key => $value) {
        $key = sanitize_key((string)$key);
        if ($key === '') continue;
        $out[$key] = is_scalar($value) ? sanitize_text_field((string)$value) : $value;
      }
      return $out;
    }
    $lines = preg_split('/\r\n|\r|\n/', (string)$raw) ?: [];
    $out = [];
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '' || str_starts_with($line, '#')) continue;
      $parts = preg_split('/\s*=\s*/', $line, 2);
      if (!$parts || count($parts) < 2) $parts = preg_split('/\s*:\s*/', $line, 2);
      if (!$parts || count($parts) < 2) continue;
      $key = sanitize_key((string)$parts[0]);
      if ($key === '') continue;
      $value_raw = trim((string)$parts[1]);
      $decoded = json_decode($value_raw, true);
      $out[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : sanitize_textarea_field($value_raw);
    }
    return $out;
  }

  private function preset_user_meta_text(array $meta): string {
    $lines = [];
    foreach ($meta as $key => $value) {
      $key = sanitize_key((string)$key);
      if ($key === '') continue;
      $lines[] = $key . ' = ' . (is_scalar($value) ? (string)$value : wp_json_encode($value));
    }
    return implode("\n", $lines);
  }

  private function merged_candidate_preset_user_meta(int $job_id, $candidate_raw): array {
    $job_meta = $job_id > 0 ? get_post_meta($job_id, self::JOB_META_PRESET_USER_META, true) : [];
    $job_meta = is_array($job_meta) ? $job_meta : [];
    return array_merge($job_meta, $this->sanitize_preset_user_meta($candidate_raw));
  }

  public function render_job_path_term_fields_add(): void {
    if (!EUM_Access::current_user_is_it() && !EUM_Access::current_user_is_hr()) return;
    echo '<div class="form-field"><label for="eum_job_default_title">Default Job Posting Title</label><input type="text" name="eum_job_default_title" id="eum_job_default_title"></div>';
    echo '<div class="form-field"><label for="eum_job_template_desc">Default Description Template</label><textarea name="eum_job_template_desc" id="eum_job_template_desc" rows="6" style="width:100%;"></textarea></div>';
    echo '<div class="form-field"><label for="eum_job_template_requirements">Default Requirements Template</label><textarea name="eum_job_template_requirements" id="eum_job_template_requirements" rows="6" style="width:100%;"></textarea></div>';
  }

  public function render_job_path_term_fields_edit($term): void {
    if (!EUM_Access::current_user_is_it() && !EUM_Access::current_user_is_hr()) return;
    $term_id = (int)$term->term_id;
    echo '<tr class="form-field"><th><label for="eum_job_default_title">Default Job Posting Title</label></th><td><input type="text" name="eum_job_default_title" id="eum_job_default_title" class="regular-text" value="' . esc_attr((string)get_term_meta($term_id, self::JOB_PATH_META_DEFAULT_TITLE, true)) . '"></td></tr>';
    echo '<tr class="form-field"><th><label for="eum_job_template_desc">Default Description Template</label></th><td><textarea name="eum_job_template_desc" id="eum_job_template_desc" rows="8" class="large-text">' . esc_textarea((string)get_term_meta($term_id, self::JOB_PATH_META_TEMPLATE_DESC, true)) . '</textarea></td></tr>';
    echo '<tr class="form-field"><th><label for="eum_job_template_requirements">Default Requirements Template</label></th><td><textarea name="eum_job_template_requirements" id="eum_job_template_requirements" rows="8" class="large-text">' . esc_textarea((string)get_term_meta($term_id, self::JOB_PATH_META_TEMPLATE_REQS, true)) . '</textarea></td></tr>';
  }

  public function save_job_path_term_fields(int $term_id): void {
    if (!EUM_Access::current_user_is_it() && !EUM_Access::current_user_is_hr()) return;
    update_term_meta($term_id, self::JOB_PATH_META_DEFAULT_TITLE, sanitize_text_field((string)($_POST['eum_job_default_title'] ?? '')));
    update_term_meta($term_id, self::JOB_PATH_META_TEMPLATE_DESC, wp_kses_post((string)($_POST['eum_job_template_desc'] ?? '')));
    update_term_meta($term_id, self::JOB_PATH_META_TEMPLATE_REQS, wp_kses_post((string)($_POST['eum_job_template_requirements'] ?? '')));
  }

  public function shortcode_manage($atts): string {
    if (!is_user_logged_in()) return '<div class="eum-job-board"><p>You must be logged in.</p></div>';
    $user_id = get_current_user_id();
    if (!self::can_manage_jobs($user_id)) return '<div class="eum-job-board"><p>You do not have access to job boards.</p></div>';
    if ($this->actor_can_manage_job_social_images($user_id) && function_exists('wp_enqueue_media')) wp_enqueue_media();
    $buffer_level = ob_get_level();
    try {
    $jobs = $this->visible_jobs_for_actor($user_id);
    $show_historical_candidates = !empty($_GET['eum_candidate_history']);
    $all_candidates = $this->visible_candidates_for_actor($user_id, true);
    $historical_candidates = array_values(array_filter($all_candidates, fn($item) => $this->candidate_is_historical((int)$item->ID)));
    $active_candidates = array_values(array_filter($all_candidates, fn($item) => !$this->candidate_is_historical((int)$item->ID)));
    $candidates = $show_historical_candidates ? $all_candidates : $active_candidates;
    $job_terms = $this->job_terms_for_actor($user_id);
    $selected_job = (int)($_GET['eum_job_edit'] ?? 0);
    $editing = $selected_job > 0 ? get_post($selected_job) : null;
    $selected_candidate = (int)($_GET['eum_candidate'] ?? 0);
    $owner_user_id = $editing ? (int)get_post_meta((int)$editing->ID, self::JOB_META_OWNER_UID, true) : $user_id;
    if ($owner_user_id <= 0) $owner_user_id = $user_id;
    $creator_user_id = $editing ? (int)get_post_field('post_author', (int)$editing->ID) : $user_id;
    $owner_options = $this->job_owner_user_options($owner_user_id, $creator_user_id);
    $msg = isset($_GET['eum_jobs_msg']) ? sanitize_text_field((string)$_GET['eum_jobs_msg']) : '';
    $type = isset($_GET['eum_jobs_type']) ? sanitize_key((string)$_GET['eum_jobs_type']) : 'success';

    ob_start();
    echo '<div class="eum-job-board">';
    if ($msg !== '') {
      $notice_style = ($type === 'error')
        ? 'margin-bottom:16px;padding:12px 14px;border:1px solid #d63638;background:#fcf0f1;color:#8a2424;border-radius:6px;'
        : 'margin-bottom:16px;padding:12px 14px;border:1px solid #00a32a;background:#ecf7ed;color:#166534;border-radius:6px;';
      echo '<div style="' . esc_attr($notice_style) . '">' . esc_html($msg) . '</div>';
    }
    echo '<div class="eum-job-board__grid" style="display:grid;grid-template-columns:minmax(320px,620px) minmax(320px,1fr);gap:24px;align-items:start;">';
    echo '<div class="eum-job-board-card" style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">';
    echo '<h3 style="margin-top:0;">' . ($editing ? 'Edit Job Posting' : 'Create Job Posting') . '</h3>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_job_board_save"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    if ($editing) echo '<input type="hidden" name="job_id" value="' . (int)$editing->ID . '">';
    wp_nonce_field('eum_job_board_save', 'eum_job_board_nonce');
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Job Path</label>';
    $current_term = $editing ? (int)get_post_meta((int)$editing->ID, self::JOB_META_JOB_TERM, true) : 0;
    $current_job_title_token = $editing ? (string)get_post_meta((int)$editing->ID, self::JOB_META_JOB_TITLE, true) : '';
    if (class_exists('EUM_Profiles')) {
      echo EUM_Profiles::render_job_path_directory_picker('job_term_id', $current_term, $user_id, [
        'placeholder' => '— Select Job Path —',
        'required' => true,
        'job_title_input_name' => 'job_title_token',
        'job_title_current' => $current_job_title_token,
      ]);
      echo '<div style="margin-top:10px;">';
      echo '<label style="display:block;font-weight:600;margin-bottom:4px;">Job Title</label>';
      echo EUM_Profiles::render_job_title_select_html('job_title_token', $current_job_title_token, $current_term, 'Selected in Job Path', false);
      echo '<span class="description" style="display:block;margin-top:6px;">Choose the title from the Job Path selector above. It differentiates Title vs Further Path.</span>';
      echo '</div>';
    } else {
      echo '<select name="job_term_id" style="width:100%;max-width:520px;" required><option value="">— Select Job Path —</option>';
      foreach ($job_terms as $term) {
        echo '<option value="' . (int)$term->term_id . '" ' . selected($current_term, (int)$term->term_id, false) . '>' . esc_html($this->term_full_path('eum_job_path', (int)$term->term_id, ' / ')) . '</option>';
      }
      echo '</select>';
    }
    echo '</p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Public Job Title</label><input type="text" name="neat_title" style="width:100%;max-width:520px;" value="' . esc_attr($editing ? (string)get_post_meta((int)$editing->ID, self::JOB_META_NEAT_TITLE, true) : '') . '" required></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Short Description</label><textarea name="short_description" style="width:100%;min-height:90px;">' . esc_textarea($editing ? (string)get_post_meta((int)$editing->ID, self::JOB_META_SHORT_DESCRIPTION, true) : '') . '</textarea><span class="description" style="display:block;margin-top:6px;">This is the preview text shown before someone clicks into the posting.</span></p>';
    if ($this->actor_can_manage_job_social_images($user_id)) {
      $job_social_image_id = $editing ? $this->job_social_image_id((int)$editing->ID) : 0;
      echo '<div style="margin:0 0 16px;"><label style="display:block;font-weight:600;margin-bottom:4px;">Social Share Image Override</label>';
      echo $this->render_job_image_picker_html('social_image_id', $job_social_image_id, 'No job-specific image selected.');
      echo '<span class="description" style="display:block;margin-top:8px;">' . esc_html($this->job_social_image_help_text($current_term)) . '</span></div>';
    }
    echo '<div style="margin:0 0 16px;"><label style="display:block;font-weight:600;margin-bottom:4px;">Job Posting Content</label>';
    $job_content_value = $editing ? $this->job_full_content_html((int)$editing->ID) : '';
    if (function_exists('wp_editor')) {
      wp_editor($job_content_value, 'eum_job_posting_content', [
        'textarea_name' => 'description',
        'textarea_rows' => 16,
        'media_buttons' => false,
        'teeny' => false,
        'quicktags' => true,
      ]);
    } else {
      echo '<textarea name="description" style="width:100%;min-height:240px;">' . esc_textarea($job_content_value) . '</textarea>';
    }
    echo '<span class="description" style="display:block;margin-top:6px;">Use this one rich-text field for the full posting details, requirements, and any additional sections.</span></div>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Location</label><input type="text" name="location" style="width:100%;max-width:520px;" value="' . esc_attr($editing ? (string)get_post_meta((int)$editing->ID, self::JOB_META_LOCATION, true) : '') . '"></p>';
    $vis = $editing ? (string)get_post_meta((int)$editing->ID, self::JOB_META_VISIBILITY, true) : 'both';
    $status = $editing ? $this->normalize_job_status((string)get_post_meta((int)$editing->ID, self::JOB_META_STATUS, true)) : 'published';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Visibility</label><select name="visibility"><option value="internal" ' . selected($vis, 'internal', false) . '>Internal only</option><option value="external" ' . selected($vis, 'external', false) . '>External only</option><option value="both" ' . selected($vis, 'both', false) . '>Internal + External</option><option value="private" ' . selected($vis, 'private', false) . '>Private</option></select><br><span class="description">Private listings are visible to managers/HR/IT here but are not listed on internal or external job boards.</span></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Status</label><select name="status"><option value="published" ' . selected($status, 'published', false) . '>Published</option><option value="unpublished" ' . selected($status, 'unpublished', false) . '>Unpublished</option><option value="draft" ' . selected($status, 'draft', false) . '>Draft</option></select></p>';
    $job_preset_meta = $editing ? get_post_meta((int)$editing->ID, self::JOB_META_PRESET_USER_META, true) : [];
    $job_preset_meta = is_array($job_preset_meta) ? $job_preset_meta : [];
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Default User Meta on Hire</label><textarea name="preset_user_meta" style="width:100%;min-height:90px;" placeholder="meta_key = value">' . esc_textarea($this->preset_user_meta_text($job_preset_meta)) . '</textarea><span class="description" style="display:block;margin-top:6px;">Optional defaults applied to users hired/created from this listing. One per line: <code>meta_key = value</code>. Candidate/invite presets override these defaults.</span></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Owner</label><select name="owner_user_id" style="width:100%;max-width:520px;">';
    foreach ($owner_options as $owner_option) {
      echo '<option value="' . (int)$owner_option->ID . '" ' . selected($owner_user_id, (int)$owner_option->ID, false) . '>' . esc_html($this->display_user_label((int)$owner_option->ID)) . '</option>';
    }
    echo '</select><span class="description">The owner, the owner&#8217;s manager chain, the creator, and HR / IT can access this posting.</span></p>';
    echo $this->render_job_application_fields_editor($editing ? $this->job_application_fields((int)$editing->ID) : []);
    if (!$editing) {
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Number of Listings</label><input type="number" name="listing_count" min="1" max="25" step="1" style="width:100%;max-width:120px;" value="1"><br><span class="description">Create multiple copies of this listing in one save when you need more than one opening.</span></p>';
    }
    if ($editing) {
      echo '<p class="description"><strong>Creator:</strong> ' . esc_html($this->display_user_label($creator_user_id)) . '</p>';
    }
    echo '<p><button type="submit" class="button button-primary">' . ($editing ? 'Save Job Posting' : 'Create Job Posting') . '</button></p></form>';
    if ($this->actor_can_manage_job_social_images($user_id)) {
      echo $this->render_job_image_picker_script();
    }
    echo '</div>';

    echo '<div class="eum-job-board-card" style="padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;"><h3 style="margin-top:0;">My Job Postings</h3>';
    if (empty($jobs)) echo '<p>No job postings yet.</p>';
    else {
      echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr><th>Title</th><th>Job Title</th><th>Job Path</th><th>Owner</th><th>Creator</th><th>Visibility</th><th>Status</th><th>Active Candidates</th><th>Actions</th></tr></thead><tbody>';
      foreach ($jobs as $job) {
        $job_id = (int)$job->ID;
        $job_term_id = (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true);
        $delete_url = wp_nonce_url(add_query_arg(['action' => 'eum_job_board_delete', 'job_id' => $job_id, 'return_url' => $this->current_url()], admin_url('admin-post.php')), 'eum_job_board_delete_' . $job_id);
        $clone_url = wp_nonce_url(add_query_arg(['action' => 'eum_job_board_clone', 'job_id' => $job_id, 'return_url' => $this->current_url()], admin_url('admin-post.php')), 'eum_job_board_clone_' . $job_id);
        $job_owner_user_id = (int)get_post_meta($job_id, self::JOB_META_OWNER_UID, true);
        if ($job_owner_user_id <= 0) $job_owner_user_id = (int)get_post_field('post_author', $job_id);
        $job_creator_user_id = (int)get_post_field('post_author', $job_id);
        echo '<tr><td>' . esc_html((string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true)) . '</td><td>' . esc_html(EUM_Profiles::job_title_label_from_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true))) . '</td><td>' . esc_html($this->term_full_path('eum_job_path', $job_term_id, ' / ')) . '</td><td>' . esc_html($this->display_user_label($job_owner_user_id)) . '</td><td>' . esc_html($this->display_user_label($job_creator_user_id)) . '</td><td>' . esc_html($this->job_visibility_label((string)get_post_meta($job_id, self::JOB_META_VISIBILITY, true))) . '</td><td>' . esc_html($this->job_status_label((string)get_post_meta($job_id, self::JOB_META_STATUS, true))) . '</td><td>' . (int)$this->candidate_count_for_job($job_id) . '</td><td><a class="button button-small" href="' . esc_url(add_query_arg(['eum_tab' => 'jobs', 'eum_job_edit' => $job_id], $this->current_url())) . '">Edit</a> <a class="button button-small" href="' . esc_url(add_query_arg(['eum_tab' => 'jobs', 'eum_job' => $job_id], $this->current_url())) . '">View Candidates</a> <a class="button button-small" href="' . esc_url($clone_url) . '">Clone</a> <a class="button button-small" href="' . esc_url($delete_url) . '" onclick="return confirm(&quot;Delete this job posting and all of its candidates? Pending candidate records will also be removed.&quot;);">Delete</a></td></tr>';
      }
      echo '</tbody></table></div>';
    }
    echo '</div></div>';

    $history_toggle_url = $show_historical_candidates
      ? remove_query_arg(['eum_candidate_history'], $this->current_url())
      : add_query_arg(['eum_candidate_history' => '1'], $this->current_url());
    echo '<div class="eum-job-board-card" style="margin-top:24px;padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">';
    echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">';
    echo '<div><h3 style="margin:0;">Candidates</h3><p class="description" style="margin:6px 0 0;">Active candidates stay in the main queue. Completed candidates are hidden by default but preserved for history.</p></div>';
    echo '<a class="button" href="' . esc_url($history_toggle_url) . '">' . ($show_historical_candidates ? 'Hide Historical Candidates' : 'Show Historical Candidates') . '</a>';
    echo '</div>';
    if (!$show_historical_candidates && !empty($historical_candidates)) {
      echo '<p class="description" style="margin-top:10px;">' . count($historical_candidates) . ' historical candidates are hidden from the default queue.</p>';
    }
    $filter_job_id = (int)($_GET['eum_job'] ?? 0);
    $display_candidates = array_values(array_filter($candidates, function ($candidate) use ($filter_job_id): bool {
      return $filter_job_id <= 0 || (int)get_post_meta((int)$candidate->ID, self::CAND_META_JOB_ID, true) === $filter_job_id;
    }));
    foreach ($display_candidates as $candidate) $this->ensure_candidate_review_invite((int)$candidate->ID);
    $pending_invite_count = count(array_filter($display_candidates, fn($candidate) => $this->candidate_has_pending_signup_invite((int)$candidate->ID)));
    if (!empty($display_candidates)) {
      if ($pending_invite_count > 0) {
        echo '<form id="eum-candidate-bulk-redirect-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0;padding:14px;border:1px solid #d0d7de;border-radius:8px;background:#f8f9fa;">';
        echo '<input type="hidden" name="action" value="eum_job_board_candidate_bulk_signup_redirect"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
        wp_nonce_field('eum_job_board_candidate_bulk_signup_redirect', 'eum_job_board_candidate_bulk_signup_redirect_nonce');
        echo '<h4 style="margin:0 0 8px;">Bulk Apply Redirect After Signup</h4>';
        echo '<p class="description" style="margin:0 0 12px;">Select pending pre-approved invites in the table below. Leave the URL blank to clear any custom redirect and send candidates to the site homepage.</p>';
        echo '<div style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">';
        echo '<label style="display:grid;gap:4px;"><strong>Redirect URL</strong><input type="url" name="signup_redirect_url" style="min-width:280px;max-width:100%;" placeholder="' . esc_attr(home_url('/')) . '"></label>';
        echo '<label style="display:grid;gap:4px;"><strong>Reason for change (required)</strong><input type="text" name="reason" required maxlength="500" style="min-width:280px;max-width:100%;"></label>';
        echo '<button type="submit" class="button button-primary">Apply to Selected Invites</button>';
        echo '</div></form>';
      }
      echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr>' . ($pending_invite_count > 0 ? '<th scope="col"><label><input id="eum-candidate-select-all-pending" type="checkbox" form="eum-candidate-bulk-redirect-form" data-eum-select-all-pending-invites="1"> Select all</label></th>' : '') . '<th>Name</th><th>Job</th><th>Type</th><th>Status</th><th>Email</th><th>Invite Code</th><th>Actions</th></tr></thead><tbody>';
      foreach ($display_candidates as $cand) {
        $cid = (int)$cand->ID;
        $job_id = (int)get_post_meta($cid, self::CAND_META_JOB_ID, true);
        $candidate_delete_url = wp_nonce_url(add_query_arg(['action' => 'eum_job_board_candidate_delete', 'candidate_id' => $cid, 'return_url' => $this->current_url()], admin_url('admin-post.php')), 'eum_job_board_candidate_delete_' . $cid);
        $candidate_clone_invite_url = wp_nonce_url(add_query_arg(['action' => 'eum_job_board_candidate_clone_invite', 'candidate_id' => $cid, 'return_url' => $this->current_url()], admin_url('admin-post.php')), 'eum_job_board_candidate_clone_invite_' . $cid);
        $candidate_review_invite_url = wp_nonce_url(add_query_arg(['action' => 'eum_job_board_candidate_create_review_invite', 'candidate_id' => $cid, 'return_url' => $this->current_url()], admin_url('admin-post.php')), 'eum_job_board_candidate_create_review_invite_' . $cid);
        $can_clone_invite = (string)get_post_meta($cid, self::CAND_META_INVITE_CODE, true) !== '';
        $review_invite_code = (string)get_post_meta($cid, self::CAND_META_REVIEW_INVITE_CODE, true);
        $invite_code = $can_clone_invite ? (string)get_post_meta($cid, self::CAND_META_INVITE_CODE, true) : $review_invite_code;
        $invite_url = $can_clone_invite ? $this->candidate_signup_url($invite_code) : ($review_invite_code !== '' ? $this->candidate_review_invite_url($review_invite_code) : '');
        $review_invite_completed = (string)get_post_meta($cid, self::CAND_META_REVIEW_INVITE_COMPLETED_AT, true) !== '';
        $can_bulk_update_redirect = $this->candidate_has_pending_signup_invite($cid);
        $invite_cell = $invite_code !== ''
          ? '<code>' . esc_html($invite_code) . '</code><br><input type="text" readonly onclick="this.select();" aria-label="Copy candidate invite link" style="width:190px;max-width:100%;margin-top:4px;" value="' . esc_attr($invite_url) . '">' . ($review_invite_completed ? '<br><span class="description">Submitted</span>' : '')
          : '—';
        $review_invite_action = $this->candidate_review_invite_eligible($cid)
          ? '<a class="button button-small" href="' . esc_url($candidate_review_invite_url) . '">' . ($review_invite_completed ? 'New Review Link' : 'Refresh Review Link') . '</a> '
          : '';
        echo '<tr>' . ($pending_invite_count > 0 ? '<td>' . ($can_bulk_update_redirect ? '<input type="checkbox" form="eum-candidate-bulk-redirect-form" name="candidate_ids[]" data-eum-candidate-bulk-checkbox="1" value="' . $cid . '" aria-label="Select ' . esc_attr((string)get_post_meta($cid, self::CAND_META_FULL_NAME, true)) . ' for bulk redirect update">' : '—') . '</td>' : '') . '<td>' . esc_html((string)get_post_meta($cid, self::CAND_META_FULL_NAME, true)) . '</td><td>' . esc_html((string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true)) . '</td><td>' . esc_html($this->candidate_source_label($cid)) . '</td><td>' . esc_html($this->candidate_status_label((string)get_post_meta($cid, self::CAND_META_STATUS, true))) . '</td><td>' . esc_html((string)get_post_meta($cid, self::CAND_META_EMAIL, true)) . '</td><td>' . $invite_cell . '</td><td><a class="button button-small" href="' . esc_url(add_query_arg(['eum_tab' => 'jobs', 'eum_candidate' => $cid], $this->current_url())) . '">Open</a> ' . $review_invite_action . ($can_clone_invite ? '<a class="button button-small" href="' . esc_url($candidate_clone_invite_url) . '">Clone Invite</a> ' : '') . '<a class="button button-small" href="' . esc_url($candidate_delete_url) . '" onclick="return confirm(&quot;Delete this candidate record? Pending hire workflow items tied to this candidate will also be removed.&quot;);">Delete</a></td></tr>';
      }
      echo '</tbody></table></div>';
    } else echo '<p>' . ($show_historical_candidates ? 'No candidates found.' : 'No active candidates right now.') . '</p>';
    echo '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #e2e4e7;">';
    echo '<h4 style="margin:0 0 8px;">Add Candidate Manually</h4>';
    $manual_job_previews = [];
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">';
    echo '<input type="hidden" name="action" value="eum_job_board_candidate_add_manual"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_job_board_candidate_add_manual', 'eum_job_board_candidate_add_manual_nonce');
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Job Posting</label><select name="job_id" required><option value="">— Select —</option>';
    foreach ($jobs as $manual_job) {
      $manual_job_id = (int)$manual_job->ID;
      $manual_job_term_id = (int)get_post_meta($manual_job_id, self::JOB_META_JOB_TERM, true);
      $manual_job_title = (string)get_post_meta($manual_job_id, self::JOB_META_NEAT_TITLE, true);
      if ($manual_job_title === '' && class_exists('EUM_Profiles')) $manual_job_title = EUM_Profiles::job_title_label_from_value((string)get_post_meta($manual_job_id, self::JOB_META_JOB_TITLE, true));
      if ($manual_job_title === '') $manual_job_title = get_the_title($manual_job_id);
      $manual_job_title_token = class_exists('EUM_Profiles')
        ? EUM_Profiles::normalize_job_title_value((string)get_post_meta($manual_job_id, self::JOB_META_JOB_TITLE, true), $manual_job_term_id)
        : sanitize_text_field((string)get_post_meta($manual_job_id, self::JOB_META_JOB_TITLE, true));
      $manual_projection = $this->candidate_projection_for_profile([
        'tax:eum_job_path' => $manual_job_term_id,
        'custom:job_title' => $manual_job_title_token,
      ], $manual_job_term_id);
      $manual_job_previews[$manual_job_id] = [
        'job_path' => (string)($manual_projection['job_path_label'] ?? ''),
        'job_title' => (string)($manual_projection['job_title_label'] ?? $manual_job_title),
        'division_label' => (string)($manual_projection['division_label'] ?? ''),
        'division_domain' => (string)($manual_projection['division_domain'] ?? ''),
      ];
      echo '<option value="' . $manual_job_id . '">' . esc_html($manual_job_title) . '</option>';
    }
    echo '</select></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">First Name</label><input type="text" name="first_name" required></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Last Name</label><input type="text" name="last_name" required></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Personal Email</label><input type="email" name="personal_email" required></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Phone <span style="font-weight:400;color:#646970;">optional</span></label><input type="text" name="phone_number"></p>';
    echo '<div class="eum-manual-candidate-preview" data-jobs="' . esc_attr(wp_json_encode($manual_job_previews)) . '" style="grid-column:1/-1;padding:14px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;">';
    echo '<strong style="display:block;margin-bottom:10px;">Posting Preview</strong>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">';
    echo '<div><strong>Job Path</strong><br><span data-preview="job_path">Select a posting</span></div>';
    echo '<div><strong>Job Title</strong><br><span data-preview="job_title">Select a posting</span></div>';
    echo '<div><strong>Company Division</strong><br><span data-preview="division">Select a posting</span></div>';
    echo '<div><strong>Division Email Domain</strong><br><span data-preview="division_domain">Select a posting</span></div>';
    echo '<div><strong>Projected Company Email</strong><br><span data-preview="company_email">Enter a name and select a posting</span></div>';
    echo '</div></div>';
    echo '<p style="grid-column:1/-1;"><label style="display:block;font-weight:600;margin-bottom:4px;">Note</label><textarea name="notes" style="width:100%;min-height:80px;"></textarea></p>';
    echo '<p><button type="submit" class="button">Add Candidate</button></p>';
    echo '</form><p class="description">Manual candidates now inherit the selected posting job path and title immediately, then send the same application email as other candidate intake paths.</p>';
    echo '<script>(function(){if(window.EUMManualCandidatePreviewInit)return;window.EUMManualCandidatePreviewInit=function(container){var form=container.closest("form");if(!form)return;var job=form.querySelector(\'[name="job_id"]\');var first=form.querySelector(\'[name="first_name"]\');var last=form.querySelector(\'[name="last_name"]\');if(!job||!first||!last)return;var jobs=JSON.parse(container.getAttribute("data-jobs")||"{}");var setText=function(key,value){var target=container.querySelector(\'[data-preview="\'+key+\'"]\');if(target)target.textContent=value;};var slugify=function(value){return (value||"").toLowerCase().replace(/[^a-z0-9._-]/g,"");};var update=function(){var selected=jobs[job.value]||null;var firstName=(first.value||"").trim();var lastName=(last.value||"").trim();var firstPart=firstName?firstName.split(/\\s+/)[0]:"";var lastParts=lastName?lastName.split(/\\s+/):[];var lastPart=lastParts.length?lastParts[lastParts.length-1]:"";var username=slugify(firstPart + lastPart);var divisionDomain=selected&&selected.division_domain?selected.division_domain:"";setText("job_path",selected&&selected.job_path?selected.job_path:"Select a posting");setText("job_title",selected&&selected.job_title?selected.job_title:"Select a posting");setText("division",selected&&selected.division_label?selected.division_label:"Select a posting");setText("division_domain",divisionDomain?divisionDomain:"Select a posting");if(divisionDomain&&username){setText("company_email",username+"@"+divisionDomain);}else if(divisionDomain){setText("company_email","Enter first and last name to preview");}else{setText("company_email","Enter a name and select a posting");}};["change","input"].forEach(function(eventName){job.addEventListener(eventName,update);first.addEventListener(eventName,update);last.addEventListener(eventName,update);});update();};document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".eum-manual-candidate-preview").forEach(function(container){window.EUMManualCandidatePreviewInit(container);});});}());</script></div>';
    echo $this->render_preaccepted_candidate_form($jobs);
    if ($selected_candidate > 0 && $this->actor_can_view_candidate($user_id, $selected_candidate)) echo $this->render_candidate_editor($selected_candidate, $user_id);
    echo '</div></div>';
    return ob_get_clean();
    } catch (\Throwable $e) {
      while (ob_get_level() > $buffer_level) ob_end_clean();
      if (defined('WP_DEBUG') && WP_DEBUG) eum_log_message('[EUM Job Boards] Manage view failed: ' . $e->getMessage());
      return '<div class="eum-job-board"><p>The Job Boards workspace is temporarily unavailable.</p></div>';
    }
  }

  public function shortcode_internal($atts): string {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
    return $this->render_public_board($this->public_jobs('internal'), false, false);
  }

  public function shortcode_external($atts): string {
    $settings = self::settings();
    $remote = trim((string)$settings['external_source_url']);
    if ($remote !== '') {
      $source_token = $this->external_source_token($settings, $remote);
      $remote_url = $source_token !== '' ? add_query_arg(['token' => $source_token], $remote) : $remote;
      $request_args = ['timeout' => 20, 'headers' => ['Accept' => 'application/json']];
      if ($source_token !== '') $request_args['headers']['Authorization'] = 'Bearer ' . $source_token;
      $resp = wp_remote_get($remote_url, $request_args);
      if (!is_wp_error($resp) && (int)wp_remote_retrieve_response_code($resp) === 200) {
        $data = json_decode((string)wp_remote_retrieve_body($resp), true);
        if (is_array($data)) {
          $remote_jobs = [];
          if (!empty($data['jobs']) && is_array($data['jobs'])) $remote_jobs = $data['jobs'];
          elseif (isset($data[0]) || empty($data)) $remote_jobs = $data;
          if (!empty($remote_jobs)) return $this->render_public_board($remote_jobs, false, true, true, $source_token);
        }
      }
    }
    return $this->render_public_board($this->public_jobs('external'), false, true);
  }

  public function shortcode_candidate_signup($atts = []): string {
    if (!class_exists('EUM_Profiles')) return '<div class="eum-candidate-signup"><p>Candidate signup is temporarily unavailable.</p></div>';
    $code = $this->candidate_invite_code_from_request();
    $candidate_id = $this->candidate_by_invite_code($code);
    if ($candidate_id <= 0) return '<div class="eum-candidate-signup"><p>This candidate signup link is invalid.</p></div>';
    if ((string)get_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, true) !== '') return '<div class="eum-candidate-signup"><p>This candidate signup link has already been used.</p></div>';

    $profile = is_array(get_post_meta($candidate_id, self::CAND_META_PROFILE, true)) ? get_post_meta($candidate_id, self::CAND_META_PROFILE, true) : [];
    $full_name = (string)get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true);
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_label = (string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true);
    if ($job_label === '') $job_label = get_the_title($job_id);

    ob_start();
    echo '<div class="eum-candidate-signup">';
    echo '<style>.eum-candidate-signup{max-width:760px;margin:0 auto;color:#111827}.eum-candidate-signup__card{background:#fff;border:1px solid #dbe3ea;border-radius:18px;box-shadow:0 14px 34px rgba(15,23,42,.05);padding:28px}.eum-candidate-signup h2{margin:0 0 8px;font-size:30px;line-height:1.15}.eum-candidate-signup p{line-height:1.6}.eum-candidate-signup__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.eum-candidate-signup label{display:block;font-weight:700;margin:0 0 5px}.eum-candidate-signup input,.eum-candidate-signup select,.eum-candidate-signup textarea{width:100%;max-width:100%;box-sizing:border-box}.eum-candidate-signup__section{margin-top:18px;padding-top:18px;border-top:1px solid #e5e7eb}.eum-candidate-signup__button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:12px 20px;background:#0f6cbd;color:#fff;font-weight:700;cursor:pointer}@media(max-width:640px){.eum-candidate-signup__card{padding:20px;border-radius:14px}.eum-candidate-signup h2{font-size:24px}}</style>';
    echo '<div class="eum-candidate-signup__card">';
    echo '<h2>Complete Your Account</h2>';
    echo '<p>You have been pre-accepted for <strong>' . esc_html($job_label ?: 'this role') . '</strong>. Confirm your personal details and create your password to continue.</p>';
    if (is_user_logged_in()) {
      $user = wp_get_current_user();
      echo '<p>You are already signed in as <strong>' . esc_html($user->display_name ?: $user->user_login) . '</strong>. Accepting this invite will convert it into an internal candidate record and fill it from your existing profile.</p>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      echo '<input type="hidden" name="action" value="eum_job_board_candidate_signup">';
      echo '<input type="hidden" name="invite_code" value="' . esc_attr($code) . '">';
      wp_nonce_field('eum_preaccepted_candidate_signup_' . $candidate_id, 'eum_preaccepted_candidate_signup_nonce');
      echo '<p style="margin-top:20px;"><button type="submit" class="eum-candidate-signup__button">Accept as Internal Candidate</button></p>';
      echo '</form></div></div>';
      return ob_get_clean();
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_job_board_candidate_signup">';
    echo '<input type="hidden" name="invite_code" value="' . esc_attr($code) . '">';
    wp_nonce_field('eum_preaccepted_candidate_signup_' . $candidate_id, 'eum_preaccepted_candidate_signup_nonce');
    echo '<div class="eum-candidate-signup__grid">';
    foreach ($this->preaccepted_signup_fields() as $field_id) echo '<div>' . EUM_Profiles::render_field_input($field_id, $profile[$field_id] ?? '', 0, 0) . '</div>';
    echo '</div>';
    echo '<script>(function(){var root=document.currentScript&&document.currentScript.closest(".eum-candidate-signup");if(!root)return;var requiredFields=' . wp_json_encode($this->preaccepted_required_fields()) . ';requiredFields.forEach(function(id){var input=root.querySelector(\'[name="eum_pf[\'+id+\']"]\');if(!input)return;input.required=true;var label=input.closest("p")&&input.closest("p").querySelector("label");if(label&&label.textContent.indexOf("*")===-1){label.appendChild(document.createTextNode(" *"));}});}());</script>';
    echo '<div class="eum-candidate-signup__section"><h3 style="margin:0 0 12px;">Account</h3><div class="eum-candidate-signup__grid">';
    echo '<p><label>Password</label><input type="password" name="user_pass" required minlength="10" autocomplete="new-password"></p>';
    echo '<p><label>Confirm Password</label><input type="password" name="user_pass_confirm" required minlength="10" autocomplete="new-password"></p>';
    echo '</div></div>';
    echo '<p style="margin-top:20px;"><button type="submit" class="eum-candidate-signup__button">Create Account and Continue</button></p>';
    echo '</form></div></div>';
    return ob_get_clean();
  }

  private function render_candidate_review_form(int $candidate_id, string $code): string {
    if (!class_exists('EUM_Profiles')) return '<div class="eum-candidate-review"><p>Application review is temporarily unavailable.</p></div>';
    $profile = get_post_meta($candidate_id, self::CAND_META_PROFILE, true);
    $profile = is_array($profile) ? $profile : [];
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_label = (string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true);
    if ($job_label === '') $job_label = get_the_title($job_id);
    $application_fields = $job_id > 0 ? $this->job_application_fields($job_id) : [];
    $responses = $this->candidate_application_responses($candidate_id);
    $resume_id = (int)($profile['custom:resume_attachment_id'] ?? 0);
    $cover_id = (int)($profile['custom:cover_letter_attachment_id'] ?? 0);

    ob_start();
    echo '<div class="eum-candidate-review" style="max-width:820px;margin:0 auto;color:#111827;">';
    echo '<style>.eum-candidate-review__card{background:#fff;border:1px solid #dbe3ea;border-radius:18px;box-shadow:0 14px 34px rgba(15,23,42,.05);padding:28px}.eum-candidate-review h2{margin:0 0 8px;font-size:30px;line-height:1.15}.eum-candidate-review p{line-height:1.6}.eum-candidate-review__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.eum-candidate-review input,.eum-candidate-review select,.eum-candidate-review textarea{max-width:100%;box-sizing:border-box}.eum-candidate-review__button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:12px 20px;background:#0f6cbd;color:#fff;font-weight:700;cursor:pointer}@media(max-width:640px){.eum-candidate-review__card{padding:20px;border-radius:14px}.eum-candidate-review h2{font-size:24px}}</style>';
    echo '<div class="eum-candidate-review__card"><h2>Review Your Application</h2>';
    echo '<p>Review or update the information you submitted for <strong>' . esc_html($job_label ?: 'this role') . '</strong>. When you continue, your updated application will be returned to the hiring team. This link does not create an account or approve the application.</p>';
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_job_board_candidate_review"><input type="hidden" name="invite_code" value="' . esc_attr($code) . '">';
    wp_nonce_field('eum_candidate_review_' . $candidate_id, 'eum_candidate_review_nonce');
    echo '<h3>Personal Information</h3><div class="eum-candidate-review__grid">';
    foreach ($this->candidate_application_fields() as $field_id) echo '<div>' . EUM_Profiles::render_field_input($field_id, $profile[$field_id] ?? '', 0, 0) . '</div>';
    echo '</div>';
    if (!empty($application_fields)) {
      echo '<h3 style="margin-top:24px;padding-top:18px;border-top:1px solid #e5e7eb;">Application Questions</h3>';
      echo $this->render_application_question_inputs($application_fields, $responses, 'eum_application_responses', false);
    }
    echo '<h3 style="margin-top:24px;padding-top:18px;border-top:1px solid #e5e7eb;">Experience and Additional Information</h3>';
    echo $this->render_history_repeater('work_history', 'Work History', is_array($profile['custom:work_history'] ?? null) ? $profile['custom:work_history'] : [], [
      'job_title' => 'Job Title', 'company' => 'Company', 'start_date' => 'Start Date', 'end_date' => 'End Date', 'responsibilities' => 'Responsibilities',
    ]);
    echo $this->render_history_repeater('education_history', 'Education History', is_array($profile['custom:education_history'] ?? null) ? $profile['custom:education_history'] : [], [
      'school' => 'School', 'degree' => 'Degree / Program', 'field' => 'Field of Study', 'start_date' => 'Start Date', 'end_date' => 'End Date', 'details' => 'Details',
    ]);
    if ($resume_id > 0) echo '<p>Current resume: <a href="' . esc_url(wp_get_attachment_url($resume_id)) . '" target="_blank" rel="noopener">View resume</a></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Replace Resume (optional)</label><input type="file" name="resume_file" accept=".pdf,.doc,.docx,.rtf,.txt"></p>';
    if ($cover_id > 0) echo '<p>Current cover letter: <a href="' . esc_url(wp_get_attachment_url($cover_id)) . '" target="_blank" rel="noopener">View cover letter</a></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Replace Cover Letter (optional)</label><input type="file" name="cover_letter_file" accept=".pdf,.doc,.docx,.rtf,.txt"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Additional Information / Cover Letter</label><textarea name="notes" style="width:100%;min-height:100px;">' . esc_textarea((string)($profile['custom:bio'] ?? '')) . '</textarea></p>';
    echo '<p style="margin-top:22px;"><button type="submit" class="eum-candidate-review__button">Save and Continue</button></p>';
    echo '</form>' . $this->render_history_repeater_script() . '</div></div>';
    return ob_get_clean();
  }

  public function handle_candidate_review(): void {
    if (!class_exists('EUM_Profiles')) wp_die('Application review is temporarily unavailable.');
    $code = sanitize_text_field((string)wp_unslash($_POST['invite_code'] ?? ''));
    $candidate_id = $this->candidate_by_review_invite_code($code);
    if ($candidate_id <= 0 || !$this->candidate_review_invite_eligible($candidate_id)) wp_die('This application review link is invalid or has already been used.');
    check_admin_referer('eum_candidate_review_' . $candidate_id, 'eum_candidate_review_nonce');

    $profile = get_post_meta($candidate_id, self::CAND_META_PROFILE, true);
    $profile = is_array($profile) ? $profile : [];
    foreach ($this->candidate_application_fields() as $field_id) {
      $normalized = EUM_Profiles::normalize_field_input($field_id, $_POST['eum_pf'][$field_id] ?? null);
      if ($normalized !== null) $profile[$field_id] = $normalized;
    }
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, $candidate_id, 0, $job_term_id);
    $profile['custom:work_history'] = $this->sanitize_repeater_items($_POST['eum_hist']['work_history'] ?? ($profile['custom:work_history'] ?? []));
    $profile['custom:education_history'] = $this->sanitize_repeater_items($_POST['eum_hist']['education_history'] ?? ($profile['custom:education_history'] ?? []));
    $profile['custom:bio'] = sanitize_textarea_field((string)wp_unslash($_POST['notes'] ?? ''));

    $first_name = sanitize_text_field((string)($profile['core:first_name'] ?? ''));
    $last_name = sanitize_text_field((string)($profile['core:last_name'] ?? ''));
    [$full_name, $email, $phone] = $this->candidate_summary_from_profile($profile, []);
    if ($first_name === '' || $last_name === '' || $full_name === '') wp_die('Please enter your first and last name.');
    if (!is_email($email)) wp_die('Please enter a valid personal email address.');

    $resume_id = $this->handle_candidate_upload($_FILES, 'resume_file');
    if ($resume_id > 0) $profile['custom:resume_attachment_id'] = $resume_id;
    $cover_id = $this->handle_candidate_upload($_FILES, 'cover_letter_file');
    if ($cover_id > 0) $profile['custom:cover_letter_attachment_id'] = $cover_id;

    $existing_responses = $this->candidate_application_responses($candidate_id);
    $job_fields = $job_id > 0 ? $this->job_application_fields($job_id) : [];
    $legacy_responses = array_diff_key($existing_responses, array_fill_keys($this->job_application_field_keys($job_fields), true));
    $responses = array_merge($legacy_responses, $this->sanitize_candidate_application_responses($job_id, $_POST, false));
    update_post_meta($candidate_id, self::CAND_META_FULL_NAME, $full_name);
    update_post_meta($candidate_id, self::CAND_META_EMAIL, $email);
    update_post_meta($candidate_id, self::CAND_META_PHONE, $phone);
    update_post_meta($candidate_id, self::CAND_META_PROFILE, $profile);
    $this->persist_candidate_application_responses($candidate_id, $responses);
    wp_update_post(['ID' => $candidate_id, 'post_title' => $full_name]);
    update_post_meta($candidate_id, self::CAND_META_REVIEW_INVITE_COMPLETED_AT, current_time('mysql'));

    wp_safe_redirect($this->candidate_review_invite_url($code));
    exit;
  }

  public function render_candidate_signup_landing(): void {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
    $legacy_code = sanitize_text_field((string)($_GET['eum_candidate_invite'] ?? ''));
    if ($legacy_code !== '') {
      $pretty_url = $this->candidate_signup_url($legacy_code);
      if (str_contains($pretty_url, '/join/')) {
        wp_safe_redirect($pretty_url, 302);
        exit;
      }
    }
    $code = $this->candidate_invite_code_from_request();
    if ($code === '') return;

    $review_candidate_id = $this->candidate_by_review_invite_code($code, true);
    $review_link_completed = $review_candidate_id > 0
      && (string)get_post_meta($review_candidate_id, self::CAND_META_REVIEW_INVITE_COMPLETED_AT, true) !== '';

    status_header(200);
    nocache_headers();
    echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . esc_html($review_candidate_id > 0 ? 'Review Your Application' : 'Complete Your Account') . '</title>';
    wp_head();
    echo '</head><body>';
    echo '<main style="min-height:100vh;padding:32px 16px;background:#f8fafc;">';
    if ($review_candidate_id > 0) {
      if ($review_link_completed) echo '<div style="max-width:760px;margin:0 auto;padding:28px;background:#fff;border:1px solid #dbe3ea;border-radius:18px;"><h2>Application Updated</h2><p>Thank you. Your updated application has been sent to the hiring team.</p></div>';
      elseif (!$this->candidate_review_invite_eligible($review_candidate_id)) echo '<div style="max-width:760px;margin:0 auto;padding:28px;background:#fff;border:1px solid #dbe3ea;border-radius:18px;"><h2>Link No Longer Available</h2><p>This application can no longer be edited through this link. Please contact the hiring team if you need help.</p></div>';
      else echo $this->render_candidate_review_form($review_candidate_id, $code);
    } else {
      echo $this->shortcode_candidate_signup([]);
    }
    echo '</main>';
    wp_footer();
    echo '</body></html>';
    exit;
  }

  private function candidate_invite_code_from_request(): string {
    $code = sanitize_text_field((string)($_GET['eum_candidate_invite'] ?? ($_GET['code'] ?? '')));
    if ($code !== '') return $code;

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    if (preg_match('#/join/([^/?]+)/?(?:\?|$)#i', $request_uri, $matches)) {
      return sanitize_text_field(rawurldecode((string)($matches[1] ?? '')));
    }
    return '';
  }

  private function render_preaccepted_candidate_form(array $jobs): string {
    ob_start();
    echo '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #e2e4e7;">';
    echo '<h4 style="margin:0 0 8px;">Create Pre-Accepted Candidate Link</h4>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">';
    echo '<input type="hidden" name="action" value="eum_job_board_candidate_create_invite"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_job_board_candidate_create_invite', 'eum_job_board_candidate_create_invite_nonce');
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Job Posting</label><select name="job_id" required><option value="">— Select —</option>';
    foreach ($jobs as $job) {
      $job_id = (int)$job->ID;
      $label = (string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true);
      if ($label === '') $label = get_the_title($job_id);
      echo '<option value="' . $job_id . '">' . esc_html($label) . '</option>';
    }
    echo '</select></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Candidate Type / Source</label>' . $this->render_candidate_source_select('candidate_source', 'external', 'candidate_source_label', '', false) . '</p>';
    echo '<div style="grid-column:1/-1;padding:14px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;">';
    echo '<strong style="display:block;margin-bottom:8px;">Prefill Candidate Info <span style="font-weight:400;color:#646970;">optional</span></strong>';
    echo '<p class="description" style="margin-top:0;">Use this when importing an already accepted candidate. They will see these values and can verify or change them before creating the account.</p>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">First Name</label><input type="text" name="prefill_first_name"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Middle Name</label><input type="text" name="prefill_middle_name"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Last Name</label><input type="text" name="prefill_last_name"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Preferred Name</label><input type="text" name="prefill_preferred_name"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Pronouns</label><input type="text" name="prefill_pronouns"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Date of Birth</label><input type="date" name="prefill_date_of_birth"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Personal Email</label><input type="email" name="prefill_personal_email"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Phone Number</label><input type="text" name="prefill_mobile_phone"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">City</label><input type="text" name="prefill_residence_city"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">State</label><input type="text" name="prefill_residence_state"></p>';
    echo '</div></div>';
    echo '<p style="grid-column:1/-1;"><label style="display:block;font-weight:600;margin-bottom:4px;">After Signup Redirect <span style="font-weight:400;color:#646970;">optional</span></label><input type="url" name="signup_redirect_url" placeholder="' . esc_attr(home_url('/')) . '"><span class="description" style="display:block;margin-top:6px;">Send them to a site page or a custom http/https link after the account is created and they are logged in.</span></p>';
    echo '<p style="grid-column:1/-1;"><label style="display:block;font-weight:600;margin-bottom:4px;">Preset User Meta <span style="font-weight:400;color:#646970;">optional</span></label><textarea name="preset_user_meta" style="width:100%;min-height:90px;" placeholder="meta_key = value"></textarea><span class="description" style="display:block;margin-top:6px;">Applied to the user when this invite is accepted. Overrides defaults from the selected job listing.</span></p>';
    echo '<p style="grid-column:1/-1;"><label style="display:block;font-weight:600;margin-bottom:4px;">Internal Note <span style="font-weight:400;color:#646970;">optional</span></label><textarea name="notes" style="width:100%;min-height:70px;" placeholder="Example: Accepted at recruiting event / imported from partner system"></textarea></p>';
    echo '<p><button type="submit" class="button button-primary">Create Random Signup Link</button></p>';
    echo '</form><p class="description">This creates an anonymous pre-acceptance link identified only by its random code and this internal note. The candidate enters personal details and account password after opening the link.</p></div>';
    return ob_get_clean();
  }


  private function render_public_board($jobs, bool $internal, bool $external, bool $remote = false, string $remote_api_token = ''): string {
    if (empty($jobs)) return '<div class="eum-public-job-board"><p>No job postings are currently available.</p></div>';
    $buffer_level = ob_get_level();
    try {
    $job_views = [];
    foreach ((array)$jobs as $job) {
      $view = $this->normalize_public_job($job, $remote, $external);
      if (!empty($view['id'])) $job_views[(int)$view['id']] = $view;
    }
    if (empty($job_views)) return '<div class="eum-public-job-board"><p>No job postings are currently available.</p></div>';
    $selected_job_id = (int)($_GET['eum_public_job'] ?? ($_GET['eum_apply_job'] ?? 0));
    $selected_view = sanitize_key((string)($_GET['eum_public_view'] ?? ((isset($_GET['eum_apply_job']) && $selected_job_id > 0) ? 'apply' : 'detail')));
    if (!in_array($selected_view, ['detail', 'apply'], true)) $selected_view = 'detail';
    if (!isset($job_views[$selected_job_id])) $selected_job_id = 0;
    $board_title = 'Job Opportunities';
    $board_subtitle = 'Choose a role to review the full posting before continuing to the application.';
    $board_mode = $external ? 'external' : 'internal';
    $base_url = $this->public_board_base_url();
    ob_start();
    echo '<div class="eum-public-job-board">';
    echo '<style>.eum-public-job-board{max-width:1040px;margin:0 auto;color:#111827}.eum-public-job-board__notice{margin:0 0 18px;padding:14px 16px;border-radius:10px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8}.eum-public-job-board__notice--success{border-color:#86efac;background:#f0fdf4;color:#166534}.eum-public-job-board__shell{display:block}.eum-public-job-board__hero,.eum-public-job-board__page,.eum-public-job-board__card{background:#fff;border:1px solid #dbe3ea;border-radius:18px;box-shadow:0 14px 34px rgba(15,23,42,.05)}.eum-public-job-board__hero{padding:28px 28px 22px;margin:0 0 18px;background:linear-gradient(180deg,#f7fbff 0%,#ffffff 100%)}.eum-public-job-board__eyebrow{margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0f6cbd}.eum-public-job-board__heading{margin:0;font-size:32px;line-height:1.1}.eum-public-job-board__subheading{margin:12px 0 0;max-width:720px;font-size:16px;line-height:1.65;color:#475569}.eum-public-job-board__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}.eum-public-job-board__card{padding:22px}.eum-public-job-board__title{margin:0 0 10px;font-size:22px;line-height:1.2}.eum-public-job-board__title a{color:#0f172a;text-decoration:none}.eum-public-job-board__title a:hover{color:#0f6cbd}.eum-public-job-board__meta{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;padding:0;list-style:none}.eum-public-job-board__meta-item{padding:6px 10px;border-radius:999px;background:#f1f5f9;font-size:13px;line-height:1.3;color:#334155}.eum-public-job-board__excerpt{margin:0;color:#334155;line-height:1.7}.eum-public-job-board__actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}.eum-public-job-board__button,.eum-public-job-board__link{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:999px;text-decoration:none;font-weight:600}.eum-public-job-board__button{padding:11px 18px;background:#0f6cbd;color:#fff}.eum-public-job-board__button:hover{background:#0b5a9e;color:#fff}.eum-public-job-board__button--secondary{background:#fff;color:#0f172a;border:1px solid #cbd5e1}.eum-public-job-board__button--secondary:hover{background:#f8fafc;color:#0f172a}.eum-public-job-board__link{color:#0f6cbd}.eum-public-job-board__link:hover{text-decoration:underline}.eum-public-job-board__page{padding:28px}.eum-public-job-board__back{margin:0 0 16px}.eum-public-job-board__back a{color:#0f6cbd;text-decoration:none;font-weight:600}.eum-public-job-board__back a:hover{text-decoration:underline}.eum-public-job-board__section{margin-top:18px;padding:20px;border:1px solid #e2e8f0;border-radius:14px;background:#fff}.eum-public-job-board__section h4{margin:0 0 12px;font-size:18px}.eum-public-job-board__copy{color:#334155;line-height:1.7}.eum-public-job-board__copy p:first-child{margin-top:0}.eum-public-job-board__copy p:last-child{margin-bottom:0}.eum-public-job-board__summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:18px}.eum-public-job-board__summary-card{padding:14px 16px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0}.eum-public-job-board__summary-label{display:block;margin-bottom:6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b}.eum-public-job-board__form-wrap{padding:18px;border:1px solid #dbe3ea;border-radius:16px;background:#f8f9fa}@media (max-width:720px){.eum-public-job-board__hero,.eum-public-job-board__page,.eum-public-job-board__card{padding:20px}.eum-public-job-board__heading{font-size:26px}}</style>';
    echo '<style>
.eum-public-job-board { max-width: 960px; margin: 0 auto; color: #222; font-family: Arial, Helvetica, sans-serif; }
.eum-public-job-board .eum-public-job-board__hero,
.eum-public-job-board .eum-public-job-board__page,
.eum-public-job-board .eum-public-job-board__card { background: #fff; border: 0; border-radius: 0; box-shadow: none; }
.eum-public-job-board .eum-public-job-board__hero { padding: 0 0 18px; margin: 0 0 4px; border-bottom: 1px solid #ccc; }
.eum-public-job-board .eum-public-job-board__eyebrow { margin: 0 0 7px; color: #555; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.eum-public-job-board .eum-public-job-board__heading { margin: 0; color: #222; font-size: 28px; font-weight: 600; line-height: 1.25; }
.eum-public-job-board .eum-public-job-board__subheading { max-width: 700px; margin: 8px 0 0; color: #555; font-size: 16px; line-height: 1.55; }
.eum-public-job-board .eum-public-job-board__grid { display: grid; grid-template-columns: 1fr; gap: 0; }
.eum-public-job-board .eum-public-job-board__card { padding: 20px 0; border-bottom: 1px solid #ddd; }
.eum-public-job-board .eum-public-job-board__title { margin: 0 0 6px; font-size: 20px !important; font-weight: 600; line-height: 1.3; }
.eum-public-job-board .eum-public-job-board__title a { color: #8b0000; text-decoration: none; }
.eum-public-job-board .eum-public-job-board__title a:hover { color: #650000; text-decoration: underline; }
.eum-public-job-board .eum-public-job-board__meta { display: flex; flex-wrap: wrap; gap: 0; margin: 0 0 10px; padding: 0; list-style: none; }
.eum-public-job-board .eum-public-job-board__meta-item { padding: 0; border: 0; border-radius: 0; background: transparent; color: #555; font-size: 14px; line-height: 1.5; }
.eum-public-job-board .eum-public-job-board__meta-item + .eum-public-job-board__meta-item::before { content: "·"; margin: 0 8px; color: #888; }
.eum-public-job-board .eum-public-job-board__excerpt { margin: 0; color: #333; font-size: 15px; line-height: 1.6; }
.eum-public-job-board .eum-public-job-board__actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
.eum-public-job-board .eum-public-job-board__button { min-height: 0; padding: 9px 14px; border: 1px solid #8b0000; border-radius: 4px !important; background: #8b0000; box-shadow: none !important; color: #fff; font-weight: 600; text-decoration: none; }
.eum-public-job-board .eum-public-job-board__button:hover { border-color: #650000; background: #650000; color: #fff; }
.eum-public-job-board .eum-public-job-board__button--secondary { border-color: #aaa; background: #fff; color: #333; }
.eum-public-job-board .eum-public-job-board__button--secondary:hover { border-color: #666; background: #f5f5f5; color: #222; }
.eum-public-job-board .eum-public-job-board__link,
.eum-public-job-board .eum-public-job-board__back a { color: #8b0000; font-weight: 600; text-decoration: none; }
.eum-public-job-board .eum-public-job-board__link:hover,
.eum-public-job-board .eum-public-job-board__back a:hover { text-decoration: underline; }
.eum-public-job-board .eum-public-job-board__page { padding: 20px 0; border-top: 1px solid #ddd; }
.eum-public-job-board .eum-public-job-board__back { margin: 0 0 16px; }
.eum-public-job-board .eum-public-job-board__section { margin-top: 18px; padding: 18px 0; border: 0; border-top: 1px solid #ddd; border-radius: 0; background: transparent; }
.eum-public-job-board .eum-public-job-board__section h4 { margin: 0 0 10px; color: #222; font-size: 18px; }
.eum-public-job-board .eum-public-job-board__copy { color: #333; line-height: 1.65; }
.eum-public-job-board .eum-public-job-board__summary { display: flex; flex-wrap: wrap; gap: 24px; margin-top: 18px; padding: 14px 0; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; }
.eum-public-job-board .eum-public-job-board__summary-card { padding: 0; border: 0; border-radius: 0; background: transparent; color: #333; }
.eum-public-job-board .eum-public-job-board__summary-label { margin-bottom: 3px; color: #666; font-size: 11px; font-weight: 600; letter-spacing: .04em; }
.eum-public-job-board .eum-public-job-board__form-wrap { padding: 18px; border: 1px solid #ddd; border-radius: 4px; background: #f7f7f7; }
.eum-public-job-board .eum-public-job-board__notice { border-radius: 4px; box-shadow: none; }
@media (max-width: 720px) {
  .eum-public-job-board .eum-public-job-board__heading { font-size: 25px; }
  .eum-public-job-board .eum-public-job-board__summary { gap: 14px; }
}
</style>';
    echo $this->public_board_notice_markup();
    echo '<div class="eum-public-job-board__shell">';
    if ($selected_job_id > 0) {
      $job_view = $job_views[$selected_job_id];
      $detail_url = $this->public_board_job_url($selected_job_id, 'detail');
      $apply_url = $this->public_board_job_url($selected_job_id, 'apply');
      $meta = array_values(array_filter([(string)($job_view['location'] ?? ''), (string)($job_view['job_title'] ?? '')]));
      echo '<p class="eum-public-job-board__back"><a href="' . esc_url($base_url) . '">&larr; Back to ' . esc_html(strtolower($board_title)) . '</a></p>';
      echo '<section class="eum-public-job-board__page">';
      echo '<p class="eum-public-job-board__eyebrow">Job Opening</p>';
      echo '<h3 class="eum-public-job-board__heading">' . esc_html((string)$job_view['title']) . '</h3>';
      if (!empty($meta)) {
        echo '<ul class="eum-public-job-board__meta">';
        foreach ($meta as $meta_item) echo '<li class="eum-public-job-board__meta-item">' . esc_html($meta_item) . '</li>';
        echo '</ul>';
      }
      if ($selected_view === 'apply') {
        echo '<p class="eum-public-job-board__subheading">Complete the application below. You can return to the job details at any time before submitting.</p>';
        echo '<div class="eum-public-job-board__section"><h4>Application</h4><div class="eum-public-job-board__form-wrap">' . $this->render_apply_form($job_view, $board_mode, $remote, $remote_api_token) . '</div></div>';
        echo '<div class="eum-public-job-board__actions"><a class="eum-public-job-board__button eum-public-job-board__button--secondary" href="' . esc_url($detail_url) . '">View Job Details</a><a class="eum-public-job-board__link" href="' . esc_url($base_url) . '">Return to All Openings</a></div>';
      } else {
        echo '<p class="eum-public-job-board__subheading">Review the role details below, then continue to the application when you are ready.</p>';
        echo '<div class="eum-public-job-board__summary">';
        if (!empty($job_view['location'])) echo '<div class="eum-public-job-board__summary-card"><span class="eum-public-job-board__summary-label">Location</span>' . esc_html((string)$job_view['location']) . '</div>';
        if (!empty($job_view['job_title'])) echo '<div class="eum-public-job-board__summary-card"><span class="eum-public-job-board__summary-label">Job Title</span>' . esc_html((string)$job_view['job_title']) . '</div>';
        echo '</div>';
        if (!empty($job_view['description'])) echo '<div class="eum-public-job-board__section"><h4>Job Details</h4><div class="eum-public-job-board__copy">' . wp_kses_post(wpautop((string)$job_view['description'])) . '</div></div>';
        echo '<div class="eum-public-job-board__actions"><a class="eum-public-job-board__button" href="' . esc_url($apply_url) . '">Continue to Application</a><a class="eum-public-job-board__button eum-public-job-board__button--secondary" href="' . esc_url($base_url) . '">Browse Other Roles</a></div>';
      }
      echo '</section>';
    } else {
      echo '<section class="eum-public-job-board__hero">';
      echo '<p class="eum-public-job-board__eyebrow">Career Opportunities</p>';
      echo '<h3 class="eum-public-job-board__heading">' . esc_html($board_title) . '</h3>';
      echo '<p class="eum-public-job-board__subheading">' . esc_html($board_subtitle) . '</p>';
      echo '</section>';
      echo '<div class="eum-public-job-board__grid">';
      foreach ($job_views as $job_view) {
        $excerpt = $this->public_job_excerpt($job_view, 42);
        $detail_url = $this->public_board_job_url((int)$job_view['id'], 'detail');
        $meta = array_values(array_filter([(string)($job_view['location'] ?? ''), (string)($job_view['job_title'] ?? '')]));
        echo '<article class="eum-public-job-board__card">';
        echo '<h4 class="eum-public-job-board__title"><a href="' . esc_url($detail_url) . '">' . esc_html((string)$job_view['title']) . '</a></h4>';
        if (!empty($meta)) {
          echo '<ul class="eum-public-job-board__meta">';
          foreach ($meta as $meta_item) echo '<li class="eum-public-job-board__meta-item">' . esc_html($meta_item) . '</li>';
          echo '</ul>';
        }
        echo '<p class="eum-public-job-board__excerpt">' . esc_html($excerpt) . '</p>';
        echo '<div class="eum-public-job-board__actions"><a class="eum-public-job-board__button" href="' . esc_url($detail_url) . '">View Details</a></div>';
        echo '</article>';
      }
      echo '</div>';
    }
    echo '</div></div>';
    return ob_get_clean();
    } catch (\Throwable $e) {
      while (ob_get_level() > $buffer_level) ob_end_clean();
      if (defined('WP_DEBUG') && WP_DEBUG) eum_log_message('[EUM Job Boards] Public board render failed: ' . $e->getMessage());
      return '<div class="eum-public-job-board"><p>The job board is temporarily unavailable.</p></div>';
    }
  }

  private function render_apply_form($job_or_view, string $source, bool $remote = false, string $remote_api_token = ''): string {
    $job_view = is_array($job_or_view) ? $job_or_view : ['id' => (int)$job_or_view];
    $job_id = (int)($job_view['id'] ?? 0);
    $application_fields = is_array($job_view['application_fields'] ?? null) ? $job_view['application_fields'] : $this->job_application_fields($job_id);
    $prefill = [];
    $user_id = 0;
    if ($source === 'internal' && is_user_logged_in()) {
      $user_id = get_current_user_id();
      $prefill = $this->candidate_profile_from_user($user_id);
    }
    $settings = self::settings();
    $remote_apply_api = $remote ? esc_url_raw((string)($job_view['apply_url'] ?? '')) : '';
    if ($remote && $remote_apply_api === '') $remote_apply_api = esc_url_raw((string)$settings['external_apply_url']);
    if ($remote && $remote_api_token === '') $remote_api_token = $this->external_source_token($settings, (string)($settings['external_source_url'] ?? ''));
    $action_url = $remote_apply_api !== '' ? $remote_apply_api : admin_url('admin-post.php');
    $return_url = $this->public_board_success_url($this->current_url());
    ob_start();
    echo '<div style="padding:14px;border:1px solid #ccd0d4;border-radius:8px;background:#f8f9fa;"><form method="post" enctype="multipart/form-data" action="' . esc_url($action_url) . '">';
    if ($remote_apply_api === '') {
      echo '<input type="hidden" name="action" value="eum_job_board_apply">';
      wp_nonce_field('eum_job_board_apply_' . $job_id, 'eum_job_board_apply_nonce');
    } else {
      echo '<input type="hidden" name="token" value="' . esc_attr($remote_api_token) . '">';
      echo '<input type="hidden" name="return_url_sig" value="' . esc_attr(hash_hmac('sha256', $return_url, $remote_api_token)) . '">';
    }
    echo '<input type="hidden" name="job_id" value="' . $job_id . '"><input type="hidden" name="source" value="' . esc_attr($source === 'remote' ? 'external' : $source) . '"><input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    if ($source === 'internal' && $user_id > 0) {
      $u = get_user_by('id', $user_id);
      echo '<input type="hidden" name="internal_user_id" value="' . $user_id . '"><p><strong>Applying as internal candidate:</strong> ' . esc_html($u ? ($u->display_name ?: $u->user_login) : ('User #' . $user_id)) . '</p>';
    }
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">First Name</label><input type="text" name="eum_pf[core:first_name]" value="' . esc_attr((string)($prefill['core:first_name'] ?? '')) . '" style="width:100%;max-width:420px;" required></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Middle Name</label><input type="text" name="eum_pf[custom:middle_name]" value="' . esc_attr((string)($prefill['custom:middle_name'] ?? '')) . '" style="width:100%;max-width:420px;"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Last Name</label><input type="text" name="eum_pf[core:last_name]" value="' . esc_attr((string)($prefill['core:last_name'] ?? '')) . '" style="width:100%;max-width:420px;" required></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Preferred Name</label><input type="text" name="eum_pf[custom:preferred_name]" value="' . esc_attr((string)($prefill['custom:preferred_name'] ?? '')) . '" style="width:100%;max-width:420px;"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Pronouns</label><input type="text" name="eum_pf[custom:pronouns]" value="' . esc_attr((string)($prefill['custom:pronouns'] ?? '')) . '" style="width:100%;max-width:240px;"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Personal Email</label><input type="email" name="eum_pf[custom:personal_email]" value="' . esc_attr((string)($prefill['custom:personal_email'] ?? '')) . '" style="width:100%;max-width:420px;" required></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Phone Number</label><input type="text" name="eum_pf[custom:mobile_phone]" value="' . esc_attr((string)($prefill['custom:mobile_phone'] ?? '')) . '" style="width:100%;max-width:260px;" required></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">City of Residence</label><input type="text" name="eum_pf[custom:residence_city]" value="' . esc_attr((string)($prefill['custom:residence_city'] ?? '')) . '" style="width:100%;max-width:320px;"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">State / Province</label><input type="text" name="eum_pf[custom:residence_state]" value="' . esc_attr((string)($prefill['custom:residence_state'] ?? '')) . '" style="width:100%;max-width:220px;"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">LinkedIn</label><input type="url" name="eum_pf[custom:linkedin]" value="' . esc_attr((string)($prefill['custom:linkedin'] ?? '')) . '" style="width:100%;max-width:520px;"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Skills / Expertise</label><textarea name="eum_pf[custom:skills]" style="width:100%;min-height:90px;">' . esc_textarea((string)($prefill['custom:skills'] ?? '')) . '</textarea></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Languages</label><input type="text" name="eum_pf[custom:languages]" value="' . esc_attr((string)($prefill['custom:languages'] ?? '')) . '" style="width:100%;max-width:520px;"></p>';
    echo $this->render_history_repeater('work_history', 'Work History', is_array($prefill['custom:work_history'] ?? null) ? $prefill['custom:work_history'] : [], [
      'job_title' => 'Job Title',
      'company' => 'Company',
      'start_date' => 'Start Date',
      'end_date' => 'End Date',
      'responsibilities' => 'Responsibilities',
    ]);
    echo $this->render_history_repeater('education_history', 'Education History', is_array($prefill['custom:education_history'] ?? null) ? $prefill['custom:education_history'] : [], [
      'school' => 'School',
      'degree' => 'Degree / Program',
      'field' => 'Field of Study',
      'start_date' => 'Start Date',
      'end_date' => 'End Date',
      'details' => 'Details',
    ]);
    if (!empty($application_fields)) {
      echo '<div style="margin:18px 0 8px;padding-top:14px;border-top:1px solid #e2e4e7;"><h4 style="margin:0 0 8px;">Additional Job Questions</h4>';
      echo $this->render_application_question_inputs($application_fields, [], 'eum_application_responses', true);
      echo '</div>';
    }
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Resume</label><input type="file" name="resume_file" accept=".pdf,.doc,.docx,.rtf,.txt"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Cover Letter</label><input type="file" name="cover_letter_file" accept=".pdf,.doc,.docx,.rtf,.txt"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Additional Information</label><textarea name="notes" style="width:100%;min-height:90px;"></textarea></p><p><button type="submit" class="button button-primary">Submit Application</button></p></form></div>';
    echo $this->render_history_repeater_script();
    return ob_get_clean();
  }

  public function handle_job_delete(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    if (!self::can_manage_jobs($actor_user_id)) wp_die('Not allowed.');
    $job_id = (int)($_REQUEST['job_id'] ?? 0);
    check_admin_referer('eum_job_board_delete_' . $job_id);
    $return_url = esc_url_raw((string)($_REQUEST['return_url'] ?? home_url('/')));
    if ($job_id <= 0) { wp_safe_redirect($return_url); exit; }
    $job = get_post($job_id);
    if (!$job || $job->post_type !== self::CPT_JOB || !$this->actor_can_manage_job($actor_user_id, $job_id)) {
      wp_safe_redirect($return_url);
      exit;
    }
    $candidate_ids = get_posts([
      'post_type' => self::CPT_CANDIDATE,
      'post_status' => 'any',
      'fields' => 'ids',
      'posts_per_page' => -1,
      'meta_key' => self::CAND_META_JOB_ID,
      'meta_value' => $job_id,
    ]);
    foreach ((array)$candidate_ids as $candidate_id) {
      wp_delete_post((int)$candidate_id, true);
    }
    wp_delete_post($job_id, true);
    wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs'], $return_url));
    exit;
  }

  public function handle_job_clone(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    if (!self::can_manage_jobs($actor_user_id)) wp_die('Not allowed.');
    $job_id = (int)($_REQUEST['job_id'] ?? 0);
    check_admin_referer('eum_job_board_clone_' . $job_id);
    $return_url = esc_url_raw((string)($_REQUEST['return_url'] ?? home_url('/')));
    $job = get_post($job_id);
    if (!$job || $job->post_type !== self::CPT_JOB || !$this->actor_can_manage_job($actor_user_id, $job_id)) {
      wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs'], $return_url));
      exit;
    }
    $title = (string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true);
    if ($title === '') $title = get_the_title($job_id);
    $new_job_id = (int)wp_insert_post([
      'post_type' => self::CPT_JOB,
      'post_status' => 'publish',
      'post_title' => $title . ' Copy',
      'post_author' => $actor_user_id,
    ]);
    if ($new_job_id <= 0) {
      wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs', 'eum_jobs_msg' => 'Could not clone job listing.', 'eum_jobs_type' => 'error'], $return_url));
      exit;
    }
    foreach ([self::JOB_META_JOB_TERM, self::JOB_META_JOB_TITLE, self::JOB_META_DESCRIPTION, self::JOB_META_REQUIREMENTS, self::JOB_META_SHORT_DESCRIPTION, self::JOB_META_LOCATION, self::JOB_META_SOCIAL_IMAGE_ID, self::JOB_META_APPLICATION_FIELDS, self::JOB_META_VISIBILITY, self::JOB_META_PRESET_USER_META] as $meta_key) {
      $value = get_post_meta($job_id, $meta_key, true);
      if ($value !== '' && $value !== []) update_post_meta($new_job_id, $meta_key, $value);
    }
    update_post_meta($new_job_id, self::JOB_META_NEAT_TITLE, $title . ' Copy');
    update_post_meta($new_job_id, self::JOB_META_STATUS, 'draft');
    update_post_meta($new_job_id, self::JOB_META_OWNER_UID, $actor_user_id);
    $job_term_id = (int)get_post_meta($new_job_id, self::JOB_META_JOB_TERM, true);
    update_post_meta($new_job_id, self::JOB_META_OWNER_MIDS, $this->owner_related_manager_ids($actor_user_id, $job_term_id));
    wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs', 'eum_job_edit' => $new_job_id, 'eum_jobs_msg' => 'Job listing cloned as a draft.'], $return_url));
    exit;
  }

  public function handle_job_save(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    if (!self::can_manage_jobs($actor_user_id)) wp_die('Not allowed.');
    check_admin_referer('eum_job_board_save', 'eum_job_board_nonce');

    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    $job_id = (int)($_POST['job_id'] ?? 0);
    $editing = $job_id > 0;
    $existing_job = $editing ? get_post($job_id) : null;
    if ($editing && (!$existing_job || $existing_job->post_type !== self::CPT_JOB || !$this->actor_can_manage_job($actor_user_id, $job_id))) {
      wp_safe_redirect($return_url);
      exit;
    }

    $job_term_id = (int)($_POST['job_term_id'] ?? 0);
    $current_term_id = $editing ? (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true) : 0;
    if ($job_term_id <= 0 || !term_exists($job_term_id, 'eum_job_path')) {
      wp_safe_redirect($return_url);
      exit;
    }
    if (!$editing) {
      if (!$this->actor_can_manage_job_term($actor_user_id, $job_term_id)) {
        wp_safe_redirect($return_url);
        exit;
      }
    } elseif ($job_term_id !== $current_term_id && !EUM_Access::is_it_or_hr($actor_user_id) && !$this->actor_can_manage_job_term($actor_user_id, $job_term_id)) {
      wp_safe_redirect($return_url);
      exit;
    }

    $posted_owner_user_id = (int)($_POST['owner_user_id'] ?? 0);
    $existing_owner_user_id = $editing ? (int)get_post_meta($job_id, self::JOB_META_OWNER_UID, true) : 0;
    $owner_user_id = $posted_owner_user_id > 0 && get_user_by('id', $posted_owner_user_id)
      ? $posted_owner_user_id
      : ($existing_owner_user_id > 0 ? $existing_owner_user_id : $actor_user_id);

    $job_title = sanitize_text_field((string)($_POST['neat_title'] ?? 'Job Posting'));
    $job_title_token = class_exists('EUM_Profiles') ? EUM_Profiles::normalize_job_title_value((string)($_POST['job_title_token'] ?? ''), $job_term_id) : sanitize_text_field((string)($_POST['job_title_token'] ?? ''));
    $job_description = wp_kses_post((string)($_POST['description'] ?? ''));
    $job_short_description = sanitize_textarea_field((string)($_POST['short_description'] ?? ''));
    $job_requirements = '';
    $job_location = sanitize_text_field((string)($_POST['location'] ?? ''));
    $job_social_image_id = $this->actor_can_manage_job_social_images($actor_user_id)
      ? $this->sanitize_image_attachment_id($_POST['social_image_id'] ?? 0)
      : ($editing ? $this->job_social_image_id($job_id) : 0);
    $job_visibility = sanitize_key((string)($_POST['visibility'] ?? 'both'));
    if (!in_array($job_visibility, ['internal', 'external', 'both', 'private'], true)) $job_visibility = 'both';
    $job_status = $this->normalize_job_status((string)($_POST['status'] ?? 'published'));
    $job_application_fields = $this->sanitize_job_application_fields($_POST['application_fields'] ?? []);
    $preset_user_meta = $this->sanitize_preset_user_meta((string)($_POST['preset_user_meta'] ?? ''));

    $postarr = [
      'post_type' => self::CPT_JOB,
      'post_status' => 'publish',
      'post_title' => $job_title,
    ];

    $saved_job_ids = [];
    if ($editing) {
      $postarr['ID'] = $job_id;
      wp_update_post($postarr);
      $saved_job_ids[] = $job_id;
    } else {
      $listing_count = max(1, min(25, (int)($_POST['listing_count'] ?? 1)));
      for ($i = 0; $i < $listing_count; $i++) {
        $new_job_id = (int)wp_insert_post(array_merge($postarr, ['post_author' => $actor_user_id]));
        if ($new_job_id > 0) $saved_job_ids[] = $new_job_id;
      }
      $job_id = !empty($saved_job_ids) ? (int)$saved_job_ids[0] : 0;
    }

    if ($job_id <= 0 || empty($saved_job_ids)) {
      wp_safe_redirect($return_url);
      exit;
    }

    foreach ($saved_job_ids as $saved_job_id) {
      update_post_meta($saved_job_id, self::JOB_META_JOB_TERM, $job_term_id);
      update_post_meta($saved_job_id, self::JOB_META_JOB_TITLE, $job_title_token);
      update_post_meta($saved_job_id, self::JOB_META_NEAT_TITLE, $job_title);
      update_post_meta($saved_job_id, self::JOB_META_DESCRIPTION, $job_description);
      update_post_meta($saved_job_id, self::JOB_META_SHORT_DESCRIPTION, $job_short_description);
      update_post_meta($saved_job_id, self::JOB_META_REQUIREMENTS, $job_requirements);
      update_post_meta($saved_job_id, self::JOB_META_LOCATION, $job_location);
      if ($this->actor_can_manage_job_social_images($actor_user_id)) {
        if ($job_social_image_id > 0) update_post_meta($saved_job_id, self::JOB_META_SOCIAL_IMAGE_ID, $job_social_image_id);
        else delete_post_meta($saved_job_id, self::JOB_META_SOCIAL_IMAGE_ID);
      }
      update_post_meta($saved_job_id, self::JOB_META_APPLICATION_FIELDS, $job_application_fields);
      update_post_meta($saved_job_id, self::JOB_META_VISIBILITY, $job_visibility);
      update_post_meta($saved_job_id, self::JOB_META_STATUS, $job_status);
      update_post_meta($saved_job_id, self::JOB_META_OWNER_UID, $owner_user_id);
      update_post_meta($saved_job_id, self::JOB_META_OWNER_MIDS, $this->owner_related_manager_ids($owner_user_id, $job_term_id));
      update_post_meta($saved_job_id, self::JOB_META_PRESET_USER_META, $preset_user_meta);
    }

    wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs'], $return_url));
    exit;
  }

  public function handle_candidate_apply(): void {
    $job_id = (int)($_POST['job_id'] ?? 0);
    check_admin_referer('eum_job_board_apply_' . $job_id, 'eum_job_board_apply_nonce');
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? ''));
    $this->create_candidate_from_request($job_id, sanitize_key((string)($_POST['source'] ?? 'external')), $_POST, $_FILES);
    wp_safe_redirect($return_url !== '' ? $return_url : $this->public_board_success_url(home_url('/')));
    exit;
  }

  public function handle_candidate_add_manual(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    if (!self::can_manage_jobs($actor_user_id)) wp_die('Not allowed.');
    check_admin_referer('eum_job_board_candidate_add_manual', 'eum_job_board_candidate_add_manual_nonce');
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    $job_id = (int)($_POST['job_id'] ?? 0);
    if ($job_id <= 0 || !$this->actor_can_manage_job($actor_user_id, $job_id)) { wp_safe_redirect($return_url); exit; }

    $first_name = sanitize_text_field((string)($_POST['first_name'] ?? ''));
    $last_name = sanitize_text_field((string)($_POST['last_name'] ?? ''));
    $personal_email = sanitize_email((string)($_POST['personal_email'] ?? ($_POST['email'] ?? '')));
    $phone = sanitize_text_field((string)($_POST['phone_number'] ?? ($_POST['phone'] ?? '')));
    $full_name = trim($first_name . ' ' . $last_name);
    if ($full_name === '') {
      wp_safe_redirect($return_url);
      exit;
    }
    if (!is_email($personal_email)) {
      wp_safe_redirect(add_query_arg(['eum_jobs_msg' => rawurlencode('A valid personal email is required.'), 'eum_jobs_type' => 'error'], $return_url));
      exit;
    }

    $payload = [
      'first_name' => $first_name,
      'last_name' => $last_name,
      'full_name' => $full_name,
      'personal_email' => $personal_email,
      'email' => $personal_email,
      'phone_number' => $phone,
      'phone' => $phone,
      'notes' => (string)($_POST['notes'] ?? ''),
    ];
    $candidate_id = $this->create_candidate_from_request($job_id, 'manual', $payload, []);
    $redirect_url = add_query_arg([
      'eum_tab' => 'jobs',
      'eum_jobs_msg' => $candidate_id > 0
        ? 'Manual candidate added. Open it from the Candidates list if you want to keep editing.'
        : 'Manual candidate submission could not be saved.',
      'eum_jobs_type' => $candidate_id > 0 ? 'success' : 'error',
    ], remove_query_arg(['eum_candidate'], $return_url));
    wp_safe_redirect($redirect_url);
    exit;
  }

  public function handle_candidate_bulk_signup_redirect(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    if (!self::can_manage_jobs($actor_user_id)) wp_die('Not allowed.');
    check_admin_referer('eum_job_board_candidate_bulk_signup_redirect', 'eum_job_board_candidate_bulk_signup_redirect_nonce');

    $return_url = esc_url_raw((string)wp_unslash($_POST['return_url'] ?? home_url('/')));
    if ($return_url === '') $return_url = home_url('/');
    $candidate_ids = array_values(array_unique(array_filter(array_map('intval', (array)wp_unslash($_POST['candidate_ids'] ?? [])), static fn($id) => $id > 0)));
    $redirect_raw = trim((string)wp_unslash($_POST['signup_redirect_url'] ?? ''));
    $redirect_url = $this->sanitize_signup_redirect_url($redirect_raw);
    $reason = sanitize_text_field((string)wp_unslash($_POST['reason'] ?? ''));

    if (empty($candidate_ids)) {
      $this->redirect_to_jobs_notice($return_url, 'Select at least one pending pre-approved invite.', 'error');
    }
    if ($reason === '') {
      $this->redirect_to_jobs_notice($return_url, 'Enter a reason for the bulk redirect change.', 'error');
    }
    if ($redirect_raw !== '' && $redirect_url === '') {
      $this->redirect_to_jobs_notice($return_url, 'Enter a valid http or https redirect URL, or leave it blank to use the homepage.', 'error');
    }

    foreach ($candidate_ids as $candidate_id) {
      $candidate = get_post($candidate_id);
      if (!$candidate || $candidate->post_type !== self::CPT_CANDIDATE || !$this->actor_can_view_candidate($actor_user_id, $candidate_id) || !$this->candidate_has_pending_signup_invite($candidate_id)) {
        $this->redirect_to_jobs_notice($return_url, 'One or more selected records are not accessible pending pre-approved invites. No redirects were changed.', 'error');
      }
    }

    $updated = 0;
    $unchanged = 0;
    foreach ($candidate_ids as $candidate_id) {
      $before = (string)get_post_meta($candidate_id, self::CAND_META_SIGNUP_REDIRECT_URL, true);
      if ($before === $redirect_url) {
        $unchanged++;
        continue;
      }
      if ($redirect_url !== '') update_post_meta($candidate_id, self::CAND_META_SIGNUP_REDIRECT_URL, $redirect_url);
      else delete_post_meta($candidate_id, self::CAND_META_SIGNUP_REDIRECT_URL);

      $history = get_post_meta($candidate_id, self::CAND_META_SIGNUP_REDIRECT_HISTORY, true);
      $history = is_array($history) ? $history : [];
      $history[] = [
        'before' => $before,
        'after' => $redirect_url,
        'reason' => $reason,
        'actor_user_id' => $actor_user_id,
        'occurred_at' => current_time('mysql'),
      ];
      update_post_meta($candidate_id, self::CAND_META_SIGNUP_REDIRECT_HISTORY, array_slice($history, -50));
      $updated++;
    }

    $message = sprintf('Updated the signup redirect for %d invite(s).', $updated);
    if ($unchanged > 0) $message .= sprintf(' %d already had that redirect.', $unchanged);
    $this->redirect_to_jobs_notice($return_url, $message);
  }

  public function handle_candidate_create_invite(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    if (!self::can_manage_jobs($actor_user_id)) wp_die('Not allowed.');
    check_admin_referer('eum_job_board_candidate_create_invite', 'eum_job_board_candidate_create_invite_nonce');
    $return_url = esc_url_raw((string)wp_unslash($_POST['return_url'] ?? home_url('/')));
    if ($return_url === '') $return_url = home_url('/');
    $redirect_raw = trim((string)wp_unslash($_POST['signup_redirect_url'] ?? ''));
    $redirect_after = $this->sanitize_signup_redirect_url($redirect_raw);
    if ($redirect_raw !== '' && $redirect_after === '') {
      $this->redirect_to_jobs_notice($return_url, 'The signup redirect must be a valid http or https URL.', 'error');
    }
    $job_id = (int)($_POST['job_id'] ?? 0);
    if ($job_id <= 0 || !$this->actor_can_manage_job($actor_user_id, $job_id)) { wp_safe_redirect($return_url); exit; }

    [$source, $source_label] = $this->sanitize_candidate_source_from_request($_POST, 'external');
    if ($source === 'internal') $source = 'external';
    $code = wp_generate_password(32, false, false);
    $note = sanitize_textarea_field((string)($_POST['notes'] ?? ''));
    $job_term_id = (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true);
    $job_title_token = class_exists('EUM_Profiles') ? EUM_Profiles::normalize_job_title_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true), $job_term_id) : sanitize_text_field((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true));
    $profile = [];
    if ($job_term_id > 0) $profile['tax:eum_job_path'] = $job_term_id;
    if ($job_title_token !== '') $profile['custom:job_title'] = $job_title_token;
    if ($note !== '') $profile['custom:bio'] = $note;
    $prefill_map = [
      'prefill_first_name' => 'core:first_name',
      'prefill_middle_name' => 'custom:middle_name',
      'prefill_last_name' => 'core:last_name',
      'prefill_preferred_name' => 'custom:preferred_name',
      'prefill_pronouns' => 'custom:pronouns',
      'prefill_date_of_birth' => 'custom:date_of_birth',
      'prefill_personal_email' => 'custom:personal_email',
      'prefill_mobile_phone' => 'custom:mobile_phone',
      'prefill_residence_city' => 'custom:residence_city',
      'prefill_residence_state' => 'custom:residence_state',
    ];
    foreach ($prefill_map as $post_key => $field_id) {
      $raw_value = (string)($_POST[$post_key] ?? '');
      if (class_exists('EUM_Profiles')) {
        $normalized = EUM_Profiles::normalize_field_input($field_id, $raw_value);
        if ($normalized !== null && $normalized !== '' && $normalized !== []) $profile[$field_id] = $normalized;
      } else {
        $value = sanitize_text_field($raw_value);
        if ($value !== '') $profile[$field_id] = $value;
      }
    }
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, 0, 0, $job_term_id);
    [$prefill_full_name, $prefill_email, $prefill_phone] = $this->candidate_summary_from_profile($profile, [
      'full_name' => '',
      'email' => '',
      'phone' => '',
    ]);

    $candidate_label = 'Pre-accepted Invite ' . substr($code, 0, 8);
    $candidate_id = (int)wp_insert_post([
      'post_type' => self::CPT_CANDIDATE,
      'post_status' => 'publish',
      'post_title' => $candidate_label,
      'post_author' => $actor_user_id,
    ]);
    if ($candidate_id <= 0) {
      wp_safe_redirect(add_query_arg(['eum_jobs_msg' => 'Pre-accepted invite could not be created.', 'eum_jobs_type' => 'error'], $return_url));
      exit;
    }

    update_post_meta($candidate_id, self::CAND_META_JOB_ID, $job_id);
    update_post_meta($candidate_id, self::CAND_META_FULL_NAME, $prefill_full_name !== '' ? $prefill_full_name : $candidate_label);
    update_post_meta($candidate_id, self::CAND_META_EMAIL, $prefill_email);
    update_post_meta($candidate_id, self::CAND_META_PHONE, $prefill_phone);
    update_post_meta($candidate_id, self::CAND_META_SOURCE, $source);
    update_post_meta($candidate_id, self::CAND_META_SOURCE_LABEL, $source_label);
    update_post_meta($candidate_id, self::CAND_META_USER_ID, 0);
    update_post_meta($candidate_id, self::CAND_META_STATUS, 'pre_accepted');
    update_post_meta($candidate_id, self::CAND_META_PROFILE, $profile);
    update_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, $this->merged_candidate_preset_user_meta($job_id, (string)($_POST['preset_user_meta'] ?? '')));
    update_post_meta($candidate_id, self::CAND_META_APPLIED_AT, current_time('mysql'));
    update_post_meta($candidate_id, self::CAND_META_INVITE_CODE, $code);
    update_post_meta($candidate_id, self::CAND_META_INVITE_CODE_LOOKUP, $this->invite_code_lookup($code));
    update_post_meta($candidate_id, self::CAND_META_INVITE_CREATED_AT, current_time('mysql'));
    if ($redirect_after !== '') update_post_meta($candidate_id, self::CAND_META_SIGNUP_REDIRECT_URL, $redirect_after);

    wp_safe_redirect(add_query_arg([
      'eum_tab' => 'jobs',
      'eum_candidate' => $candidate_id,
      'eum_jobs_msg' => 'Pre-accepted invite created. Copy the signup link from the candidate detail.',
      'eum_jobs_type' => 'success',
    ], $return_url));
    exit;
  }

  public function handle_candidate_create_review_invite(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $candidate_id = (int)($_GET['candidate_id'] ?? 0);
    check_admin_referer('eum_job_board_candidate_create_review_invite_' . $candidate_id);
    if (!$this->actor_can_view_candidate($actor_user_id, $candidate_id) || !$this->candidate_review_invite_eligible($candidate_id)) wp_die('Not allowed.');
    $return_url = esc_url_raw((string)wp_unslash($_GET['return_url'] ?? home_url('/')));
    if ($return_url === '') $return_url = home_url('/');
    $this->ensure_candidate_review_invite($candidate_id, true);
    wp_safe_redirect(add_query_arg([
      'eum_tab' => 'jobs',
      'eum_candidate' => $candidate_id,
      'eum_jobs_msg' => 'Applicant review link refreshed. Copy it from the candidate details or table.',
      'eum_jobs_type' => 'success',
    ], $return_url));
    exit;
  }

  public function handle_preaccepted_candidate_signup(): void {
    if (!class_exists('EUM_Profiles')) wp_die('Candidate signup is temporarily unavailable.');
    $code = sanitize_text_field((string)($_POST['invite_code'] ?? ($_GET['eum_candidate_invite'] ?? ($_GET['code'] ?? ''))));
    $candidate_id = $this->candidate_by_invite_code($code);
    if ($candidate_id <= 0 || (string)get_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, true) !== '') wp_die('This candidate signup link is invalid or has already been used.');
    check_admin_referer('eum_preaccepted_candidate_signup_' . $candidate_id, 'eum_preaccepted_candidate_signup_nonce');

    if (is_user_logged_in()) {
      $linked_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
      if ($linked_user_id > 0 && $linked_user_id !== get_current_user_id() && get_userdata($linked_user_id)) {
        if ($this->candidate_hire_created_user($candidate_id, $linked_user_id)) {
          wp_die('This candidate already has an account. Sign in to that account before accepting this invite.');
        }
        wp_die('This candidate is already linked to another account. Contact HR before accepting this invite.');
      }
      $lock_token = $this->claim_candidate_account_lock($candidate_id);
      if ($lock_token === '') wp_die('This candidate signup is already being processed. Please wait a moment and try again.');
      if ((string)get_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, true) !== '') {
        $this->release_candidate_account_lock($candidate_id, $lock_token);
        wp_die('This candidate signup link has already been used.');
      }
      $this->accept_preaccepted_invite_as_internal_candidate($candidate_id, get_current_user_id(), $lock_token);
    }

    $password = (string)($_POST['user_pass'] ?? '');
    $confirm = (string)($_POST['user_pass_confirm'] ?? '');
    if (strlen($password) < 10 || $password !== $confirm) wp_die('Password must be at least 10 characters and match the confirmation.');

    $profile = is_array(get_post_meta($candidate_id, self::CAND_META_PROFILE, true)) ? get_post_meta($candidate_id, self::CAND_META_PROFILE, true) : [];
    foreach ($this->preaccepted_signup_fields() as $field_id) {
      $normalized = EUM_Profiles::normalize_field_input($field_id, $_POST['eum_pf'][$field_id] ?? null);
      if ($normalized !== null) $profile[$field_id] = $normalized;
    }
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, $candidate_id, 0, $job_term_id);
    foreach ($this->preaccepted_required_fields() as $required_field_id) {
      $required_value = $profile[$required_field_id] ?? '';
      if (is_array($required_value)) {
        $is_missing = empty($required_value);
      } else {
        $is_missing = trim((string)$required_value) === '';
      }
      if ($is_missing) wp_die('Please enter ' . esc_html($this->preaccepted_field_label($required_field_id)) . '.');
    }
    $dob_required = sanitize_text_field((string)($profile['custom:date_of_birth'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob_required)) wp_die('Please enter a valid date of birth.');
    [$full_name, $personal_email, $phone] = $this->candidate_summary_from_profile($profile, [
      'full_name' => '',
      'email' => (string)get_post_meta($candidate_id, self::CAND_META_EMAIL, true),
      'phone' => (string)get_post_meta($candidate_id, self::CAND_META_PHONE, true),
    ]);
    if ($full_name === '' || $personal_email === '' || !is_email($personal_email)) wp_die('Please enter your name and a valid personal email.');

    $lock_token = $this->claim_candidate_account_lock($candidate_id);
    if ($lock_token === '') wp_die('This candidate signup is already being processed. Please wait a moment and try again.');
    if ((string)get_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, true) !== '') {
      $this->release_candidate_account_lock($candidate_id, $lock_token);
      wp_die('This candidate signup link has already been used.');
    }

    $linked_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
    if ($linked_user_id > 0 && get_userdata($linked_user_id)) {
      if (!$this->candidate_hire_created_user($candidate_id, $linked_user_id)) {
        $this->release_candidate_account_lock($candidate_id, $lock_token);
        wp_die('This candidate is already linked to an account. Sign in to accept the invite or contact HR; another account was not created.');
      }
      $this->accept_preaccepted_invite_for_hire_user($candidate_id, $linked_user_id, $profile, $full_name, $personal_email, $phone, $password, $lock_token);
    }

    $username = $this->generate_username_from_name($full_name);
    $candidate_hire_id = (int)get_post_meta($candidate_id, self::CAND_META_HIRE_ID, true);
    $candidate_hire = $candidate_hire_id > 0 ? get_post($candidate_hire_id) : null;
    $is_own_scheduled_hire = class_exists('EUM_User_Creation') && $candidate_hire
      && $candidate_hire->post_type === EUM_User_Creation::CPT
      && (string)get_post_meta($candidate_hire_id, EUM_User_Creation::META_STATUS, true) === 'scheduled'
      && (int)get_post_meta($candidate_hire_id, EUM_User_Creation::META_CANDIDATE_ID, true) === $candidate_id;
    $username = class_exists('EUM_User_Creation')
      ? $this->next_available_username_for_pending_hire($username, $is_own_scheduled_hire ? $candidate_hire_id : 0, $candidate_id)
      : $this->next_available_signup_username($username);
    $projection = $this->candidate_projection_for_profile($profile, $job_term_id, 0, $full_name);
    $company_email = sanitize_email((string)($projection['company_email'] ?? ''));
    if ($company_email === '') $company_email = $personal_email;
    if (username_exists($username) || email_exists($company_email)) {
      $this->release_candidate_account_lock($candidate_id, $lock_token);
      wp_die('That account username or email is already in use. Please contact HR.');
    }

    [$first_name, $last_name] = $this->split_candidate_name($full_name);
    $previous_invite_registration_user = $GLOBALS['eum_preaccepted_invite_registering_user'] ?? null;
    $GLOBALS['eum_preaccepted_invite_registering_user'] = [
      'candidate_id' => $candidate_id,
      'username' => $username,
      'email' => $company_email,
    ];
    try {
      $user_id = wp_insert_user([
        'user_login' => $username,
        'user_pass' => $password,
        'user_email' => $company_email,
        'display_name' => $full_name,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'role' => get_option('default_role', 'subscriber'),
      ]);
    } catch (Throwable $e) {
      $this->release_candidate_account_lock($candidate_id, $lock_token);
      throw $e;
    } finally {
      if ($previous_invite_registration_user === null) unset($GLOBALS['eum_preaccepted_invite_registering_user']);
      else $GLOBALS['eum_preaccepted_invite_registering_user'] = $previous_invite_registration_user;
    }
    if (is_wp_error($user_id)) {
      $this->release_candidate_account_lock($candidate_id, $lock_token);
      wp_die('Could not create your account: ' . esc_html($user_id->get_error_message()));
    }

    // Record the one-time claim immediately after user creation, before profile hooks or sync work can run.
    update_post_meta($candidate_id, self::CAND_META_USER_ID, (int)$user_id);
    update_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, current_time('mysql'));

    if ($job_term_id > 0) wp_set_object_terms((int)$user_id, [$job_term_id], 'eum_job_path', false);
    if (class_exists('EUM_Company_Divisions')) {
      $division_key = EUM_Company_Divisions::normalize_division_key((string)($projection['division_key'] ?? ''));
      if ($division_key !== '') EUM_Company_Divisions::save_user_division_input((int)$user_id, $division_key, false, $job_term_id);
    }
    if (class_exists('EUM_User_Creation')) (new EUM_User_Creation())->apply_profile_fields_to_user((int)$user_id, $profile);
    self::apply_candidate_preset_user_meta((int)$user_id, $candidate_id);

    update_post_meta($candidate_id, self::CAND_META_FULL_NAME, $full_name);
    update_post_meta($candidate_id, self::CAND_META_EMAIL, $personal_email);
    update_post_meta($candidate_id, self::CAND_META_PHONE, $phone);
    update_post_meta($candidate_id, self::CAND_META_PROFILE, $profile);
    update_post_meta($candidate_id, self::CAND_META_STATUS, 'hired');
    update_post_meta($candidate_id, self::CAND_META_USER_ID, (int)$user_id);
    update_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, current_time('mysql'));
    self::record_user_candidate_id((int)$user_id, $candidate_id);
    self::ensure_candidate_application_log($candidate_id, (int)$user_id, 0, 'Pre-Accepted Candidate Signup');

    if (class_exists('EUM_Access')) {
      update_user_meta((int)$user_id, EUM_Access::META_FIRST_PASSWORD_SETUP_AT, current_time('mysql'));
      delete_user_meta((int)$user_id, EUM_Access::META_PASSWORD_SETUP_REQUIRED);
      delete_user_meta((int)$user_id, EUM_Access::META_PASSWORD_SETUP_REQUIRED_AT);
    }
    $this->release_candidate_account_lock($candidate_id, $lock_token);
    $user = get_user_by('id', (int)$user_id);
    wp_set_current_user((int)$user_id);
    nocache_headers();
    wp_set_auth_cookie((int)$user_id, true);
    if ($user) do_action('wp_login', $user->user_login, $user);
    $this->finish_preaccepted_candidate_signup((int)$user_id, $candidate_id, $password);
  }

  private function accept_preaccepted_invite_for_hire_user(int $candidate_id, int $user_id, array $profile, string $full_name, string $email, string $phone, string $password, string $lock_token): void {
    if (!$this->candidate_hire_created_user($candidate_id, $user_id) || (string)get_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, true) !== '') {
      $this->release_candidate_account_lock($candidate_id, $lock_token);
      wp_die('This signup link is no longer available. Contact HR if you still need help accessing your account.');
    }

    $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, $candidate_id, $user_id, $job_term_id);
    [$first_name, $last_name] = $this->split_candidate_name($full_name);
    $updated_user_id = wp_update_user([
      'ID' => $user_id,
      'user_pass' => $password,
      'display_name' => $full_name,
      'first_name' => $first_name,
      'last_name' => $last_name,
    ]);
    if (is_wp_error($updated_user_id)) {
      $this->release_candidate_account_lock($candidate_id, $lock_token);
      wp_die('Could not finish setting up your account: ' . esc_html($updated_user_id->get_error_message()));
    }

    update_post_meta($candidate_id, self::CAND_META_FULL_NAME, $full_name);
    update_post_meta($candidate_id, self::CAND_META_EMAIL, $email);
    update_post_meta($candidate_id, self::CAND_META_PHONE, $phone);
    update_post_meta($candidate_id, self::CAND_META_PROFILE, $profile);
    update_post_meta($candidate_id, self::CAND_META_STATUS, 'hired');
    update_post_meta($candidate_id, self::CAND_META_USER_ID, $user_id);
    update_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, current_time('mysql'));

    if ($job_term_id > 0) wp_set_object_terms($user_id, [$job_term_id], 'eum_job_path', false);
    if (class_exists('EUM_Company_Divisions')) {
      $division_key = EUM_Company_Divisions::normalize_division_key((string)($this->candidate_projection_for_profile($profile, $job_term_id, $user_id, $full_name)['division_key'] ?? ''));
      if ($division_key !== '') EUM_Company_Divisions::save_user_division_input($user_id, $division_key, false, $job_term_id);
    }
    if (class_exists('EUM_User_Creation')) (new EUM_User_Creation())->apply_profile_fields_to_user($user_id, $profile);
    self::apply_candidate_preset_user_meta($user_id, $candidate_id);
    self::record_user_candidate_id($user_id, $candidate_id);
    self::ensure_candidate_application_log($candidate_id, $user_id, 0, 'Pre-Accepted Candidate Signup');

    if (class_exists('EUM_Access')) {
      update_user_meta($user_id, EUM_Access::META_FIRST_PASSWORD_SETUP_AT, current_time('mysql'));
      delete_user_meta($user_id, EUM_Access::META_PASSWORD_SETUP_REQUIRED);
      delete_user_meta($user_id, EUM_Access::META_PASSWORD_SETUP_REQUIRED_AT);
    }
    $this->release_candidate_account_lock($candidate_id, $lock_token);
    $user = get_user_by('id', $user_id);
    wp_set_current_user($user_id);
    nocache_headers();
    wp_set_auth_cookie($user_id, true);
    if ($user) do_action('wp_login', $user->user_login, $user);
    $this->finish_preaccepted_candidate_signup($user_id, $candidate_id, $password, true);
  }

  private function accept_preaccepted_invite_as_internal_candidate(int $candidate_id, int $user_id, string $lock_token): void {
    if ($candidate_id <= 0 || $user_id <= 0 || !class_exists('EUM_Profiles')) wp_die('This invite cannot be accepted.');
    $invite_profile = is_array(get_post_meta($candidate_id, self::CAND_META_PROFILE, true)) ? get_post_meta($candidate_id, self::CAND_META_PROFILE, true) : [];
    $profile = array_merge($this->candidate_profile_from_user($user_id), $invite_profile);
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    if ($job_term_id > 0) $profile['tax:eum_job_path'] = $job_term_id;
    $job_title_token = class_exists('EUM_Profiles') ? EUM_Profiles::normalize_job_title_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true), $job_term_id) : sanitize_text_field((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true));
    if ($job_title_token !== '') $profile['custom:job_title'] = $job_title_token;
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, $candidate_id, $user_id, $job_term_id);

    $user = get_user_by('id', $user_id);
    [$full_name, $email, $phone] = $this->candidate_summary_from_profile($profile, [
      'full_name' => $user ? ($user->display_name ?: $user->user_login) : '',
      'email' => $user ? $user->user_email : '',
      'phone' => '',
    ]);

    update_post_meta($candidate_id, self::CAND_META_FULL_NAME, $full_name);
    update_post_meta($candidate_id, self::CAND_META_EMAIL, $email);
    update_post_meta($candidate_id, self::CAND_META_PHONE, $phone);
    update_post_meta($candidate_id, self::CAND_META_SOURCE, 'internal');
    delete_post_meta($candidate_id, self::CAND_META_SOURCE_LABEL);
    update_post_meta($candidate_id, self::CAND_META_STATUS, 'selected');
    update_post_meta($candidate_id, self::CAND_META_USER_ID, $user_id);
    update_post_meta($candidate_id, self::CAND_META_PROFILE, $profile);
    update_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, current_time('mysql'));
    self::record_user_candidate_id($user_id, $candidate_id);
    self::apply_candidate_preset_user_meta($user_id, $candidate_id);
    self::ensure_candidate_application_log($candidate_id, $user_id, $user_id, 'Pre-Accepted Internal Candidate Signup');
    $this->release_candidate_account_lock($candidate_id, $lock_token);
    $this->finish_preaccepted_candidate_signup($user_id, $candidate_id, '');
  }

  private function finish_preaccepted_candidate_signup(int $user_id, int $candidate_id, string $password, bool $existing_user = false): void {
    $redirect = $this->sanitize_signup_redirect_url((string)get_post_meta($candidate_id, self::CAND_META_SIGNUP_REDIRECT_URL, true));
    if ($redirect === '') $redirect = home_url('/');

    wp_redirect($redirect);
    if (function_exists('fastcgi_finish_request')) {
      // Let PHP-FPM return the login cookie and redirect before external provisioning can block.
      fastcgi_finish_request();
      do_action($existing_user ? 'eum_preaccepted_candidate_existing_user_ready' : 'eum_preaccepted_candidate_user_ready', $user_id, $candidate_id, $password);
      exit;
    }

    do_action($existing_user ? 'eum_preaccepted_candidate_existing_user_ready' : 'eum_preaccepted_candidate_user_ready', $user_id, $candidate_id, $password);
    exit;
  }

  public function handle_candidate_update(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $candidate_id = (int)($_POST['candidate_id'] ?? 0);
    check_admin_referer('eum_job_board_candidate_' . $candidate_id, 'eum_job_board_candidate_nonce');
    if (!$this->actor_can_view_candidate($actor_user_id, $candidate_id)) wp_die('Not allowed.');
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    $existing_profile = is_array(get_post_meta($candidate_id, self::CAND_META_PROFILE, true)) ? get_post_meta($candidate_id, self::CAND_META_PROFILE, true) : [];
    $existing_application_responses = $this->candidate_application_responses($candidate_id);

    [$source, $source_label] = $this->sanitize_candidate_source_from_request($_POST, 'external');
    update_post_meta($candidate_id, self::CAND_META_SOURCE, $source);
    update_post_meta($candidate_id, self::CAND_META_SOURCE_LABEL, $source_label);
    update_post_meta($candidate_id, self::CAND_META_USER_ID, (int)($_POST['internal_user_id'] ?? 0));
    update_post_meta($candidate_id, self::CAND_META_FULL_NAME, sanitize_text_field((string)($_POST['full_name'] ?? '')));
    update_post_meta($candidate_id, self::CAND_META_EMAIL, sanitize_email((string)($_POST['email'] ?? '')));
    update_post_meta($candidate_id, self::CAND_META_PHONE, sanitize_text_field((string)($_POST['phone'] ?? '')));
    update_post_meta($candidate_id, self::CAND_META_STATUS, sanitize_key((string)($_POST['candidate_status'] ?? 'review')));
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    update_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, $this->merged_candidate_preset_user_meta($job_id, (string)($_POST['preset_user_meta'] ?? '')));
    if (EUM_Access::is_it_or_hr($actor_user_id) || $this->actor_is_hiring_manager_for_candidate($actor_user_id, $candidate_id)) update_post_meta($candidate_id, self::CAND_META_HIRING_DATE, $this->parse_datetime_local((string)($_POST['hiring_manager_date'] ?? '')));
    if (EUM_Access::is_it_or_hr($actor_user_id) || $this->actor_is_current_manager_for_candidate($actor_user_id, $candidate_id)) update_post_meta($candidate_id, self::CAND_META_CURRENT_DATE, $this->parse_datetime_local((string)($_POST['current_manager_date'] ?? '')));

    $profile = $existing_profile;
    $job_application_fields = $job_id > 0 ? $this->job_application_fields($job_id) : [];
    $legacy_application_responses = array_diff_key($existing_application_responses, array_fill_keys($this->job_application_field_keys($job_application_fields), true));
    $application_responses = array_merge($legacy_application_responses, $this->sanitize_candidate_application_responses($job_id, $_POST, false));
    $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    if (empty($profile['tax:eum_job_path'])) $profile['tax:eum_job_path'] = $job_term_id;
    foreach ($this->candidate_editable_fields($actor_user_id, $candidate_id) as $field_id) {
      $normalized = EUM_Profiles::normalize_field_input($field_id, $_POST['eum_pf'][$field_id] ?? null);
      if ($normalized !== null) $profile[$field_id] = $normalized;
    }
    if (empty($profile['custom:job_title']) && $job_id > 0) {
      $profile['custom:job_title'] = class_exists('EUM_Profiles')
        ? EUM_Profiles::normalize_job_title_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true), $job_term_id)
        : sanitize_text_field((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true));
    }
    $profile['custom:work_history'] = $this->sanitize_repeater_items($_POST['eum_hist']['work_history'] ?? ($existing_profile['custom:work_history'] ?? []));
    $profile['custom:education_history'] = $this->sanitize_repeater_items($_POST['eum_hist']['education_history'] ?? ($existing_profile['custom:education_history'] ?? []));
    $profile['custom:bio'] = sanitize_textarea_field((string)($_POST['notes'] ?? ($existing_profile['custom:bio'] ?? '')));

    $resume_id = $this->handle_candidate_upload($_FILES, 'resume_file');
    if ($resume_id > 0) $profile['custom:resume_attachment_id'] = $resume_id;
    elseif (!empty($existing_profile['custom:resume_attachment_id'])) $profile['custom:resume_attachment_id'] = (int)$existing_profile['custom:resume_attachment_id'];

    $cover_id = $this->handle_candidate_upload($_FILES, 'cover_letter_file');
    if ($cover_id > 0) $profile['custom:cover_letter_attachment_id'] = $cover_id;
    elseif (!empty($existing_profile['custom:cover_letter_attachment_id'])) $profile['custom:cover_letter_attachment_id'] = (int)$existing_profile['custom:cover_letter_attachment_id'];

    $linked_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, $candidate_id, $linked_user_id);
    [$candidate_full_name, $candidate_email, $candidate_phone] = $this->candidate_summary_from_profile($profile, [
      'full_name' => (string)($_POST['full_name'] ?? ''),
      'email' => (string)($_POST['email'] ?? ''),
      'phone' => (string)($_POST['phone'] ?? ''),
    ]);
    update_post_meta($candidate_id, self::CAND_META_FULL_NAME, $candidate_full_name);
    update_post_meta($candidate_id, self::CAND_META_EMAIL, $candidate_email);
    update_post_meta($candidate_id, self::CAND_META_PHONE, $candidate_phone);
    update_post_meta($candidate_id, self::CAND_META_PROFILE, $profile);
    $this->persist_candidate_application_responses($candidate_id, $application_responses);
    if ($linked_user_id > 0) self::ensure_candidate_application_log($candidate_id, $linked_user_id, $actor_user_id);
    elseif ($this->candidate_source_supports_email_match_history($source)) $this->sync_candidate_email_match_history($candidate_id, $actor_user_id);
    $this->maybe_sync_internal_transfer_request($candidate_id, $actor_user_id);
    $this->sync_pending_hire_from_candidate($candidate_id);
    wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs', 'eum_candidate' => $candidate_id], $return_url));
    exit;
  }

  public function handle_candidate_pending_hire_update(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $candidate_id = (int)($_POST['candidate_id'] ?? 0);
    $hire_id = (int)($_POST['hire_id'] ?? 0);
    check_admin_referer('eum_job_board_candidate_pending_hire_' . $candidate_id, 'eum_job_board_candidate_pending_hire_nonce');
    if (!$this->actor_can_view_candidate($actor_user_id, $candidate_id) || !$this->can_manage_pending_hire($hire_id, $actor_user_id)) wp_die('Not allowed.');
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    $effective_at = $this->parse_datetime_local((string)($_POST['effective_at'] ?? ''));
    if ($effective_at === '') {
      $this->redirect_to_candidate($return_url, $candidate_id);
    }
    if (!EUM_Access::is_it_or_hr($actor_user_id) && strtotime(get_gmt_from_date($effective_at)) < time() + (72 * HOUR_IN_SECONDS)) wp_die('Managers must set the effective hire date at least 72 hours in the future.');
    update_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, $this->merged_candidate_preset_user_meta((int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true), (string)($_POST['preset_user_meta'] ?? $this->preset_user_meta_text(is_array(get_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, true)) ? get_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, true) : []))));
    $username = sanitize_user((string)($_POST['username'] ?? ''), true);
    if ($username === '') $username = sanitize_user((string)get_post_meta($hire_id, EUM_User_Creation::META_USERNAME, true), true);
    if ($username === '') $username = $this->generate_username_from_name((string)get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true));
    $username = $this->next_available_username_for_pending_hire($username, $hire_id, $candidate_id);
    $pending_hire_overrides = [
      'hire_id' => $hire_id,
      'username' => $username,
      'effective_at' => $effective_at,
    ];
    if (class_exists('EUM_Timeclock')) {
      $pending_hire_overrides['profile_after'] = EUM_Timeclock::compensation_payload_from_request($_POST, 'eum_comp');
    }
    $this->sync_pending_hire_from_candidate($candidate_id, $pending_hire_overrides);
    $hire_email = sanitize_email((string)get_post_meta($hire_id, EUM_User_Creation::META_EMAIL, true));
    if ($hire_email === '') {
      $this->restore_candidate_status_after_failed_hire($candidate_id);
      $this->redirect_to_candidate($return_url, $candidate_id, $this->company_email_generation_error_for_hire($hire_id), 'error');
    }

    $hire_service = class_exists('EUM_User_Creation') ? new EUM_User_Creation() : null;
    if ($hire_service && $hire_service->hire_is_due($hire_id)) {
      $result = $hire_service->apply_hire($hire_id);
      if (is_wp_error($result)) {
        $this->restore_candidate_status_after_failed_hire($candidate_id);
        $this->redirect_to_candidate($return_url, $candidate_id, $result->get_error_message(), 'error');
      }
      $this->redirect_to_candidate($return_url, $candidate_id, 'Candidate hired.', 'success');
    }

    $this->redirect_to_candidate($return_url, $candidate_id, 'Pending hire updated.', 'success');
  }

  public function handle_candidate_delete(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $candidate_id = (int)($_REQUEST['candidate_id'] ?? 0);
    check_admin_referer('eum_job_board_candidate_delete_' . $candidate_id);
    if (!$this->actor_can_view_candidate($actor_user_id, $candidate_id)) wp_die('Not allowed.');
    $return_url = esc_url_raw((string)($_REQUEST['return_url'] ?? home_url('/')));
    if ($candidate_id <= 0) {
      wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs'], $return_url));
      exit;
    }
    $this->delete_candidate_related_records($candidate_id);
    wp_delete_post($candidate_id, true);
    wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs'], remove_query_arg(['eum_candidate'], $return_url)));
    exit;
  }

  public function handle_candidate_clone_invite(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $candidate_id = (int)($_REQUEST['candidate_id'] ?? 0);
    check_admin_referer('eum_job_board_candidate_clone_invite_' . $candidate_id);
    $return_url = esc_url_raw((string)($_REQUEST['return_url'] ?? home_url('/')));
    if ($candidate_id <= 0 || !$this->actor_can_view_candidate($actor_user_id, $candidate_id)) wp_die('Not allowed.');

    $original = get_post($candidate_id);
    if (!$original || $original->post_type !== self::CPT_CANDIDATE) {
      wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs', 'eum_jobs_msg' => 'Invite could not be cloned.', 'eum_jobs_type' => 'error'], $return_url));
      exit;
    }

    $old_code = (string)get_post_meta($candidate_id, self::CAND_META_INVITE_CODE, true);
    if ($old_code === '') {
      wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs', 'eum_candidate' => $candidate_id, 'eum_jobs_msg' => 'Only pre-acceptance invites can be cloned.', 'eum_jobs_type' => 'error'], $return_url));
      exit;
    }

    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    if ($job_id <= 0 || !$this->actor_can_manage_job($actor_user_id, $job_id)) wp_die('Not allowed.');

    $code = wp_generate_password(32, false, false);
    $candidate_label = 'Pre-accepted Invite ' . substr($code, 0, 8);
    $new_candidate_id = (int)wp_insert_post([
      'post_type' => self::CPT_CANDIDATE,
      'post_status' => 'publish',
      'post_title' => $candidate_label,
      'post_author' => $actor_user_id,
    ]);
    if ($new_candidate_id <= 0) {
      wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs', 'eum_jobs_msg' => 'Invite could not be cloned.', 'eum_jobs_type' => 'error'], $return_url));
      exit;
    }

    foreach ([
      self::CAND_META_JOB_ID,
      self::CAND_META_FULL_NAME,
      self::CAND_META_EMAIL,
      self::CAND_META_PHONE,
      self::CAND_META_SOURCE,
      self::CAND_META_SOURCE_LABEL,
      self::CAND_META_PROFILE,
      self::CAND_META_PRESET_USER_META,
      self::CAND_META_SIGNUP_REDIRECT_URL,
    ] as $meta_key) {
      $value = get_post_meta($candidate_id, $meta_key, true);
      if ($value !== '' && $value !== []) update_post_meta($new_candidate_id, $meta_key, $value);
    }
    $copied_full_name = (string)get_post_meta($new_candidate_id, self::CAND_META_FULL_NAME, true);
    if ($copied_full_name === '' || str_starts_with($copied_full_name, 'Pre-accepted Invite ')) {
      update_post_meta($new_candidate_id, self::CAND_META_FULL_NAME, $candidate_label);
    }

    update_post_meta($new_candidate_id, self::CAND_META_STATUS, 'pre_accepted');
    update_post_meta($new_candidate_id, self::CAND_META_USER_ID, 0);
    update_post_meta($new_candidate_id, self::CAND_META_HIRE_ID, 0);
    delete_post_meta($new_candidate_id, self::CAND_META_TRANSFER_CHANGE_ID);
    delete_post_meta($new_candidate_id, self::CAND_META_REHIRE_CHANGE_ID);
    delete_post_meta($new_candidate_id, self::CAND_META_APPLICATION_CHANGE_ID);
    delete_post_meta($new_candidate_id, self::CAND_META_APPLICATION_HISTORY_IDS);
    update_post_meta($new_candidate_id, self::CAND_META_APPLIED_AT, current_time('mysql'));
    update_post_meta($new_candidate_id, self::CAND_META_INVITE_CODE, $code);
    update_post_meta($new_candidate_id, self::CAND_META_INVITE_CODE_LOOKUP, $this->invite_code_lookup($code));
    update_post_meta($new_candidate_id, self::CAND_META_INVITE_CREATED_AT, current_time('mysql'));
    delete_post_meta($new_candidate_id, self::CAND_META_INVITE_SENT_AT);
    delete_post_meta($new_candidate_id, self::CAND_META_INVITE_COMPLETED_AT);

    wp_safe_redirect(add_query_arg([
      'eum_tab' => 'jobs',
      'eum_candidate' => $new_candidate_id,
      'eum_jobs_msg' => 'Pre-accepted invite cloned. Copy the new one-time signup link from the candidate detail.',
      'eum_jobs_type' => 'success',
    ], remove_query_arg(['eum_candidate'], $return_url)));
    exit;
  }


  public function handle_candidate_hire(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $candidate_id = (int)($_POST['candidate_id'] ?? 0);
    check_admin_referer('eum_job_board_candidate_hire_' . $candidate_id, 'eum_job_board_candidate_hire_nonce');
    if (!$this->actor_can_view_candidate($actor_user_id, $candidate_id)) wp_die('Not allowed.');
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    $source = (string)get_post_meta($candidate_id, self::CAND_META_SOURCE, true);
    $internal_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
    $profile = is_array(get_post_meta($candidate_id, self::CAND_META_PROFILE, true)) ? get_post_meta($candidate_id, self::CAND_META_PROFILE, true) : [];
    if (empty($profile['tax:eum_job_path'])) $profile['tax:eum_job_path'] = $job_term_id;
    if (empty($profile['custom:job_title']) && $job_id > 0) {
      $profile['custom:job_title'] = class_exists('EUM_Profiles')
        ? EUM_Profiles::normalize_job_title_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true), $job_term_id)
        : sanitize_text_field((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true));
    }
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, $candidate_id, $internal_user_id, $job_term_id);
    [$candidate_full_name, $candidate_email, $candidate_phone] = $this->candidate_summary_from_profile($profile, [
      'full_name' => (string)get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true),
      'email' => (string)get_post_meta($candidate_id, self::CAND_META_EMAIL, true),
      'phone' => (string)get_post_meta($candidate_id, self::CAND_META_PHONE, true),
    ]);
    update_post_meta($candidate_id, self::CAND_META_PROFILE, $profile);
    update_post_meta($candidate_id, self::CAND_META_FULL_NAME, $candidate_full_name);
    update_post_meta($candidate_id, self::CAND_META_EMAIL, $candidate_email);
    update_post_meta($candidate_id, self::CAND_META_PHONE, $candidate_phone);

    if ($source === 'internal' && $internal_user_id > 0) {
      $terminated = !empty(get_user_meta($internal_user_id, 'eum_terminated', true));
      $rehirable = !empty(get_user_meta($internal_user_id, 'eum_rehirable', true));
      if ($terminated) {
        self::ensure_candidate_application_log($candidate_id, $internal_user_id, $actor_user_id);
        if (!$rehirable && !EUM_Access::is_it_or_hr($actor_user_id)) {
          update_post_meta($candidate_id, self::CAND_META_REHIRE_CHANGE_ID, $this->ensure_internal_rehire_change($candidate_id, $actor_user_id, $internal_user_id, $job_term_id, $profile));
          update_post_meta($candidate_id, self::CAND_META_STATUS, 'review');
        } else {
          $this->apply_internal_candidate_change($internal_user_id, $job_term_id, $profile, true);
          self::apply_candidate_preset_user_meta($internal_user_id, $candidate_id);
          update_post_meta($candidate_id, self::CAND_META_STATUS, 'hired');
        }
        wp_safe_redirect(add_query_arg(['eum_tab' => 'jobs', 'eum_candidate' => $candidate_id], $return_url)); exit;
      }
      self::ensure_candidate_application_log($candidate_id, $internal_user_id, $actor_user_id);
      update_post_meta($candidate_id, self::CAND_META_STATUS, 'selected');
      $this->maybe_sync_internal_transfer_request($candidate_id, $actor_user_id, true);
      $message = 'Internal transfer staged. The incoming manager can keep editing the request, and the current manager controls the final effective date.';
      $this->redirect_to_candidate($return_url, $candidate_id, $message, 'success');
    }

    if ($this->candidate_has_scheduled_hire($candidate_id)) {
      $this->redirect_to_candidate($return_url, $candidate_id, 'This candidate already has a pending hire. Update the existing pending hire instead of creating another account.', 'error');
    }
    if ($internal_user_id > 0 && get_userdata($internal_user_id)) {
      $this->redirect_to_candidate($return_url, $candidate_id, 'This candidate is already linked to an account. No additional account was created.', 'error');
    }

    $full_name = sanitize_text_field((string)($_POST['full_name'] ?? get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true)));
    $username_base = sanitize_user((string)($_POST['username'] ?? $this->generate_username_from_name($full_name)), true);
    $effective_at = $this->parse_datetime_local((string)($_POST['effective_at'] ?? ''));
    if ($effective_at === '') wp_die('Effective hire date required.');
    if (!EUM_Access::is_it_or_hr($actor_user_id) && strtotime(get_gmt_from_date($effective_at)) < time() + (72 * HOUR_IN_SECONDS)) wp_die('Managers must set the effective hire date at least 72 hours in the future.');
    $division_key = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::effective_division_for_job_path($job_term_id) : '';
    if ($division_key === '' && class_exists('EUM_Company_Divisions')) $division_key = $this->candidate_division_key_for_profile($profile, $job_term_id, $internal_user_id, $full_name);
    if (class_exists('EUM_Company_Divisions')) $division_key = EUM_Company_Divisions::normalize_division_key($division_key);
    if ($division_key !== '') $profile['core:job_company'] = $division_key;
    $username = $this->next_available_username_for_pending_hire($username_base, 0, $candidate_id);
    $email = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::build_email_for_username_and_division($username, $division_key) : sanitize_email((string)get_post_meta($candidate_id, self::CAND_META_EMAIL, true));
    if ($email === '') {
      $this->redirect_to_candidate($return_url, $candidate_id, $this->company_email_generation_error_message($username, $job_term_id, $division_key), 'error');
    }

    $account_lock_token = $this->claim_candidate_account_lock($candidate_id);
    if ($account_lock_token === '') {
      $this->redirect_to_candidate($return_url, $candidate_id, 'A signup or hire for this candidate is already being processed. Please wait and try again.', 'error');
    }
    if ($this->candidate_has_scheduled_hire($candidate_id)) {
      $this->release_candidate_account_lock($candidate_id, $account_lock_token);
      $this->redirect_to_candidate($return_url, $candidate_id, 'This candidate already has a pending hire. Update the existing pending hire instead of creating another account.', 'error');
    }
    $internal_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
    if ($internal_user_id > 0 && get_userdata($internal_user_id)) {
      $this->release_candidate_account_lock($candidate_id, $account_lock_token);
      $this->redirect_to_candidate($return_url, $candidate_id, 'This candidate is already linked to an account. No additional account was created.', 'error');
    }

    $hire_id = (int)wp_insert_post(['post_type' => EUM_User_Creation::CPT, 'post_status' => 'publish', 'post_title' => 'Candidate Hire - ' . $full_name . ' (' . $username . ')', 'post_author' => $actor_user_id]);
    if ($hire_id <= 0) {
      $this->release_candidate_account_lock($candidate_id, $account_lock_token);
      $this->redirect_to_candidate($return_url, $candidate_id, 'Could not save the candidate hire request.', 'error');
    }
    update_post_meta($hire_id, EUM_User_Creation::META_STATUS, 'scheduled');
    update_post_meta($hire_id, EUM_User_Creation::META_ACTOR_USER, $actor_user_id);
    update_post_meta($hire_id, EUM_User_Creation::META_FULL_NAME, $full_name);
    update_post_meta($hire_id, EUM_User_Creation::META_EMAIL, $email);
    update_post_meta($hire_id, EUM_User_Creation::META_USERNAME, $username);
    update_post_meta($hire_id, EUM_User_Creation::META_JOB_TERM, $job_term_id);
    update_post_meta($hire_id, EUM_User_Creation::META_EFFECTIVE_AT, $effective_at);
    if (class_exists('EUM_Timeclock')) {
      $profile = array_merge($profile, EUM_Timeclock::compensation_payload_from_request($_POST, 'eum_comp'));
    }
    update_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, $this->merged_candidate_preset_user_meta($job_id, (string)($_POST['preset_user_meta'] ?? $this->preset_user_meta_text(is_array(get_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, true)) ? get_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, true) : []))));
    update_post_meta($hire_id, EUM_User_Creation::META_PROFILE_AFTER, $profile);
    if (class_exists('EUM_User_Creation')) update_post_meta($hire_id, EUM_User_Creation::META_CANDIDATE_ID, $candidate_id);
    update_post_meta($hire_id, EUM_User_Creation::META_ERROR, '');
    update_post_meta($candidate_id, self::CAND_META_HIRE_ID, $hire_id);
    update_post_meta($candidate_id, self::CAND_META_PRE_HIRE_STATUS, (string)get_post_meta($candidate_id, self::CAND_META_STATUS, true));
    update_post_meta($candidate_id, self::CAND_META_STATUS, 'pending_hire');
    $this->release_candidate_account_lock($candidate_id, $account_lock_token);

    $hire_service = class_exists('EUM_User_Creation') ? new EUM_User_Creation() : null;
    if ($hire_service && $hire_service->hire_is_due($hire_id)) {
      $result = $hire_service->apply_hire($hire_id);
      if (is_wp_error($result)) {
        $this->restore_candidate_status_after_failed_hire($candidate_id);
        $this->redirect_to_candidate($return_url, $candidate_id, $result->get_error_message(), 'error');
      }
      $this->redirect_to_candidate($return_url, $candidate_id, 'Candidate hired.', 'success');
    }

    $message = 'Candidate hire scheduled for ' . $effective_at . '.';
    if ($username !== $username_base) $message .= ' Username reserved as ' . $username . '.';
    $this->redirect_to_candidate($return_url, $candidate_id, $message, 'success');
  }

  public function rest_feed(WP_REST_Request $request) {
    $token = $this->extract_api_token($request->get_params(), function_exists('getallheaders') ? (array)getallheaders() : []);
    $settings = self::settings();
    if ($token === '' || !hash_equals((string)$settings['external_feed_token'], $token)) return new WP_REST_Response(['error' => 'forbidden'], 403);
    return new WP_REST_Response($this->build_public_jobs_payload(), 200);
  }

  public function rest_apply(WP_REST_Request $request) {
    $data = $request->get_json_params();
    if (!is_array($data) || empty($data)) $data = $request->get_params();
    $data = is_array($data) ? $data : [];
    $token = $this->extract_api_token($data, function_exists('getallheaders') ? (array)getallheaders() : []);
    $settings = self::settings();
    if ($token === '' || !hash_equals((string)$settings['external_feed_token'], $token)) return new WP_REST_Response(['error' => 'forbidden'], 403);
    $candidate_id = $this->create_candidate_from_request((int)($data['job_id'] ?? 0), sanitize_key((string)($data['source'] ?? 'external')), $data, $_FILES);
    return new WP_REST_Response(['ok' => true, 'candidate_id' => $candidate_id], 200);
  }

  public function handle_public_feed(): void {
    $token = $this->extract_api_token($_REQUEST, function_exists('getallheaders') ? (array)getallheaders() : []);
    $settings = self::settings();
    if ($token === '' || !hash_equals((string)$settings['external_feed_token'], $token)) {
      status_header(403); wp_send_json(['error' => 'forbidden'], 403);
    }
    wp_send_json($this->build_public_jobs_payload());
  }

  public function handle_public_apply_api(): void {
    $headers = function_exists('getallheaders') ? (array)getallheaders() : [];
    $raw = file_get_contents('php://input');
    $data = [];
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false && $raw !== '') {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = array_merge($_GET, $_POST);
    $token = $this->extract_api_token($data, $headers);
    $settings = self::settings();
    if ($token === '' || !hash_equals((string)$settings['external_feed_token'], $token)) {
      status_header(403); wp_send_json(['error' => 'forbidden'], 403);
    }
    $candidate_id = $this->create_candidate_from_request((int)($data['job_id'] ?? 0), sanitize_key((string)($data['source'] ?? 'external')), $data, $_FILES);
    $return_url = esc_url_raw((string)($data['return_url'] ?? ''));
    $return_url_sig = sanitize_text_field((string)($data['return_url_sig'] ?? ''));
    $validated_return_url = '';
    if ($return_url !== '' && $return_url_sig !== '' && hash_equals(hash_hmac('sha256', $return_url, (string)$settings['external_feed_token']), $return_url_sig)) {
      $validated_return_url = $return_url;
    }
    if ($validated_return_url !== '' && !$this->request_expects_json_response()) {
      wp_redirect($validated_return_url, 303, 'EUM Job Boards');
      exit;
    }
    wp_send_json(['ok' => true, 'candidate_id' => $candidate_id, 'redirect_url' => $validated_return_url]);
  }


  private function build_public_jobs_payload(): array {
    $settings = self::settings();
    $jobs = [];
    foreach ($this->public_jobs('external') as $job) {
      $job_id = (int)$job->ID;
      $job_term_id = (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true);
      $division_key = '';
      $division = '';
      $division_image_url = '';
      if ($job_term_id > 0 && class_exists('EUM_Company_Divisions')) {
        $division_key = EUM_Company_Divisions::effective_division_for_job_path($job_term_id);
        $division = EUM_Company_Divisions::division_label($division_key);
        $division_image_url = EUM_Company_Divisions::division_image_url($division_key);
      }
      $job_image_url = $this->job_social_image_url($job_id);
      $jobs[] = [
        'id' => $job_id,
        'title' => (string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true),
        'job_title' => class_exists('EUM_Profiles') ? EUM_Profiles::job_title_label_from_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true)) : '',
        'job_path' => $this->term_full_path('eum_job_path', $job_term_id, ' / '),
        'division' => $division,
        'short_description' => $this->job_short_description($job_id),
        'description' => $this->job_full_content_html($job_id),
        'requirements' => '',
        'location' => (string)get_post_meta($job_id, self::JOB_META_LOCATION, true),
        'job_image_url' => $job_image_url,
        'division_image_url' => $division_image_url,
        'social_image_url' => $job_image_url !== '' ? $job_image_url : $division_image_url,
        'application_fields' => $this->job_application_fields($job_id),
        'apply_url' => (string)($settings['external_apply_url'] ?: add_query_arg(['action' => 'eum_job_board_apply_api'], admin_url('admin-post.php'))),
      ];
    }
    return $jobs;
  }

  private function extract_api_token(array $data = [], array $headers = []): string {
    $token = sanitize_text_field((string)($data['token'] ?? ''));
    if ($token !== '') return $token;
    $header_sources = [];
    foreach ($headers as $key => $value) $header_sources[strtolower((string)$key)] = is_array($value) ? implode(',', $value) : (string)$value;
    foreach (['authorization', 'Authorization', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
      if (!empty($_SERVER[$key])) $header_sources[strtolower($key)] = (string)$_SERVER[$key];
    }
    $auth = trim((string)($header_sources['authorization'] ?? $header_sources['http_authorization'] ?? $header_sources['redirect_http_authorization'] ?? ''));
    if ($auth !== '' && preg_match('/Bearer\s+(.+)/i', $auth, $m)) return sanitize_text_field(trim((string)$m[1]));
    return '';
  }

  private function external_source_token(array $settings, string $source_url): string {
    $token = trim((string)($settings['external_source_token'] ?? ''));
    if ($token !== '') return $token;
    $parts = wp_parse_url($source_url);
    if (!is_array($parts) || empty($parts['query'])) return '';
    $query = [];
    parse_str((string)$parts['query'], $query);
    return sanitize_text_field((string)($query['token'] ?? ''));
  }

  private function public_jobs(string $mode): array {
    $items = get_posts(['post_type' => self::CPT_JOB, 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => [['key' => self::JOB_META_STATUS, 'value' => ['published', 'open'], 'compare' => 'IN']]]);
    $out = [];
    foreach ($items as $item) {
      $vis = (string)get_post_meta((int)$item->ID, self::JOB_META_VISIBILITY, true);
      if ($mode === 'internal' && !in_array($vis, ['internal', 'both'], true)) continue;
      if ($mode === 'external' && !in_array($vis, ['external', 'both'], true)) continue;
      $out[] = $item;
    }
    return $out;
  }

  private function visible_jobs_for_actor(int $actor_user_id): array {
    $items = get_posts(['post_type' => self::CPT_JOB, 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'date', 'order' => 'DESC']);
    if (EUM_Access::is_it_or_hr($actor_user_id)) return $items;
    return array_values(array_filter($items, fn($item) => $this->actor_can_manage_job($actor_user_id, (int)$item->ID)));
  }

  private function visible_candidates_for_actor(int $actor_user_id, bool $include_historical = false): array {
    $items = get_posts(['post_type' => self::CPT_CANDIDATE, 'post_status' => 'publish', 'numberposts' => 1000, 'orderby' => 'date', 'order' => 'DESC']);
    if (!$include_historical) {
      $items = array_values(array_filter($items, fn($item) => !$this->candidate_is_historical((int)$item->ID)));
    }
    if (EUM_Access::is_it_or_hr($actor_user_id)) return $items;
    return array_values(array_filter($items, fn($item) => $this->actor_can_view_candidate($actor_user_id, (int)$item->ID)));
  }

  private function active_candidate_statuses(): array {
    return ['new', 'review', 'pre_accepted', 'selected', 'pending_hire'];
  }

  private function historical_candidate_statuses(): array {
    return ['hired', 'rejected'];
  }

  private function candidate_is_historical(int $candidate_id): bool {
    $status = sanitize_key((string)get_post_meta($candidate_id, self::CAND_META_STATUS, true));
    if ($status === '') $status = 'new';
    return !in_array($status, $this->active_candidate_statuses(), true);
  }

  private function candidate_has_pending_signup_invite(int $candidate_id): bool {
    return $candidate_id > 0
      && (string)get_post_meta($candidate_id, self::CAND_META_STATUS, true) === 'pre_accepted'
      && (string)get_post_meta($candidate_id, self::CAND_META_INVITE_CODE, true) !== ''
      && (string)get_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, true) === '';
  }

  private function actor_can_view_candidate(int $actor_user_id, int $candidate_id): bool {
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    if ($job_id > 0 && $this->actor_can_manage_job($actor_user_id, $job_id)) return true;
    $internal_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
    if ($internal_user_id > 0) {
      $current_job = (int)EUM_Profiles::get_field_raw_value($internal_user_id, 'tax:eum_job_path');
      if ($current_job > 0 && $this->actor_can_manage_job_term($actor_user_id, $current_job)) return true;
    }
    return false;
  }

  private function render_candidate_editor(int $candidate_id, int $actor_user_id): string {
    $buffer_level = ob_get_level();
    try {
    $full_name = (string)get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true);
    $email = (string)get_post_meta($candidate_id, self::CAND_META_EMAIL, true);
    $phone = (string)get_post_meta($candidate_id, self::CAND_META_PHONE, true);
    $status = (string)get_post_meta($candidate_id, self::CAND_META_STATUS, true);
    $source = (string)get_post_meta($candidate_id, self::CAND_META_SOURCE, true);
    $internal_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $profile = is_array(get_post_meta($candidate_id, self::CAND_META_PROFILE, true)) ? get_post_meta($candidate_id, self::CAND_META_PROFILE, true) : [];
    $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    if (empty($profile['tax:eum_job_path'])) $profile['tax:eum_job_path'] = $job_term_id;
    if (empty($profile['custom:job_title']) && $job_id > 0) {
      $profile['custom:job_title'] = class_exists('EUM_Profiles')
        ? EUM_Profiles::normalize_job_title_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true), $job_term_id)
        : sanitize_text_field((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true));
    }
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, $candidate_id, $internal_user_id, $job_term_id);
    $effective_default = EUM_Access::is_it_or_hr($actor_user_id) ? date('Y-m-d\TH:i', current_time('timestamp')) : date('Y-m-d\TH:i', current_time('timestamp') + (72 * HOUR_IN_SECONDS));
    $hiring_date = (string)get_post_meta($candidate_id, self::CAND_META_HIRING_DATE, true);
    $current_date = (string)get_post_meta($candidate_id, self::CAND_META_CURRENT_DATE, true);
    $internal_user = $internal_user_id > 0 ? get_user_by('id', $internal_user_id) : null;
    $editable_fields = $this->candidate_editable_fields($actor_user_id, $candidate_id);
    $show_internal_user_picker = $this->should_show_candidate_internal_user_picker($source, $internal_user_id);
    $hire_id = (int)get_post_meta($candidate_id, self::CAND_META_HIRE_ID, true);
    $hire_status = ($hire_id > 0 && class_exists('EUM_User_Creation')) ? (string)get_post_meta($hire_id, EUM_User_Creation::META_STATUS, true) : '';
    $hire_username = ($hire_id > 0 && class_exists('EUM_User_Creation')) ? (string)get_post_meta($hire_id, EUM_User_Creation::META_USERNAME, true) : '';
    $hire_effective_at = ($hire_id > 0 && class_exists('EUM_User_Creation')) ? (string)get_post_meta($hire_id, EUM_User_Creation::META_EFFECTIVE_AT, true) : '';
    $hire_email = ($hire_id > 0 && class_exists('EUM_User_Creation')) ? (string)get_post_meta($hire_id, EUM_User_Creation::META_EMAIL, true) : '';
    $hire_profile_after = ($hire_id > 0 && class_exists('EUM_User_Creation')) ? get_post_meta($hire_id, EUM_User_Creation::META_PROFILE_AFTER, true) : [];
    $hire_profile_after = is_array($hire_profile_after) ? $hire_profile_after : [];
    $candidate_projection = $this->candidate_projection_for_profile($profile, $job_term_id, $internal_user_id, $full_name);
    $job_label = (string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true);
    if ($job_label === '') $job_label = get_the_title($job_id);
    $candidate_company_email = $hire_email !== '' ? $hire_email : (string)($candidate_projection['company_email'] ?? '');
    $job_application_fields = $job_id > 0 ? $this->job_application_fields($job_id) : [];
    $application_responses = $this->candidate_application_responses($candidate_id);
    $legacy_application_responses = array_diff_key($application_responses, array_fill_keys($this->job_application_field_keys($job_application_fields), true));

    ob_start();
    echo '<div class="eum-candidate-editor" style="margin-top:18px;padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#f8f9fa;">';
    echo '<h4 style="margin-top:0;">Candidate Detail</h4><p><strong>Candidate:</strong> ' . esc_html($full_name) . ' · <strong>Job:</strong> ' . esc_html((string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true)) . '</p>';
    echo '<p><strong>Type:</strong> ' . esc_html($this->candidate_source_label($candidate_id)) . ($internal_user ? ' · <strong>User:</strong> ' . esc_html($internal_user->display_name ?: $internal_user->user_login) : '') . ' · <strong>Status:</strong> ' . esc_html($this->candidate_status_label($status)) . '</p>';

    echo '<div style="margin:0 0 18px;padding:14px;border:1px solid #d0d7de;border-radius:8px;background:#fff;">';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">';
    echo '<div><strong>Job Path</strong><br>' . esc_html((string)($candidate_projection['job_path_label'] ?: 'Not assigned')) . '</div>';
    echo '<div><strong>Job Title</strong><br>' . esc_html((string)($candidate_projection['job_title_label'] ?: 'Not assigned')) . '</div>';
    echo '<div><strong>Company Division</strong><br>' . esc_html((string)($candidate_projection['division_label'] ?: 'Not assigned')) . '</div>';
    echo '<div><strong>Division Email Domain</strong><br>' . esc_html((string)($candidate_projection['division_domain'] ?: 'Not assigned')) . '</div>';
    echo '<div><strong>' . esc_html($hire_email !== '' ? 'Reserved Company Email' : 'Projected Company Email') . '</strong><br>' . esc_html($candidate_company_email !== '' ? $candidate_company_email : 'Not available yet') . '</div>';
    echo '</div></div>';
    $invite_code = (string)get_post_meta($candidate_id, self::CAND_META_INVITE_CODE, true);
    $invite_completed = (string)get_post_meta($candidate_id, self::CAND_META_INVITE_COMPLETED_AT, true);
    if ($invite_code !== '' && $invite_completed === '') {
      echo '<div style="margin:0 0 18px;padding:14px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;">';
      echo '<p style="margin:0 0 8px;"><strong>Pre-Accepted Candidate Signup Link</strong></p>';
      echo '<p style="margin:0;"><input type="text" readonly onclick="this.select();" style="width:100%;max-width:720px;" value="' . esc_attr($this->candidate_signup_url($invite_code)) . '"></p>';
      $signup_redirect_url = (string)get_post_meta($candidate_id, self::CAND_META_SIGNUP_REDIRECT_URL, true);
      echo '<p style="margin:8px 0 0;"><strong>After signup:</strong> ' . esc_html($signup_redirect_url !== '' ? $signup_redirect_url : 'Site homepage (default)') . '</p>';
      echo '</div>';
    }
    if ($this->candidate_review_invite_eligible($candidate_id)) {
      $review_invite_code = $this->ensure_candidate_review_invite($candidate_id);
      $review_invite_completed = (string)get_post_meta($candidate_id, self::CAND_META_REVIEW_INVITE_COMPLETED_AT, true) !== '';
      $refresh_review_url = wp_nonce_url(add_query_arg(['action' => 'eum_job_board_candidate_create_review_invite', 'candidate_id' => $candidate_id, 'return_url' => $this->current_url()], admin_url('admin-post.php')), 'eum_job_board_candidate_create_review_invite_' . $candidate_id);
      echo '<div style="margin:0 0 18px;padding:14px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;">';
      echo '<p style="margin:0 0 8px;"><strong>Applicant Review Link</strong></p>';
      if ($review_invite_completed) {
        echo '<p style="margin:0 0 10px;">This review link has already been submitted.</p>';
      } else {
        echo '<p style="margin:0 0 8px;">Share this if you want the applicant to review and update their application. It does not approve them or create an account.</p>';
        echo '<p style="margin:0;"><input type="text" readonly onclick="this.select();" style="width:100%;max-width:720px;" value="' . esc_attr($this->candidate_review_invite_url($review_invite_code)) . '"></p>';
        echo '<p style="margin:6px 0 0;"><strong>Invite code:</strong> <code>' . esc_html($review_invite_code) . '</code></p>';
      }
      echo '<p style="margin:10px 0 0;"><a class="button" href="' . esc_url($refresh_review_url) . '">' . ($review_invite_completed ? 'Create New Review Link' : 'Refresh Review Link') . '</a></p>';
      echo '</div>';
    }
    if ($invite_code !== '') {
      $candidate_clone_invite_url = wp_nonce_url(add_query_arg(['action' => 'eum_job_board_candidate_clone_invite', 'candidate_id' => $candidate_id, 'return_url' => $this->current_url()], admin_url('admin-post.php')), 'eum_job_board_candidate_clone_invite_' . $candidate_id);
      echo '<p style="margin:0 0 18px;"><a class="button" href="' . esc_url($candidate_clone_invite_url) . '">Clone Invite</a></p>';
    }
    echo $this->render_candidate_application_history($candidate_id);
    echo $this->render_candidate_signup_redirect_history($candidate_id);

    if ($hire_status === 'scheduled') {
      echo '<div style="margin:0 0 18px;padding:14px;border:1px solid #c3dafe;border-radius:8px;background:#eff6ff;">';
      echo '<p style="margin:0 0 8px;"><strong>Pending Hire Scheduled</strong></p>';
      echo '<p style="margin:0;">This candidate stays in Candidates until the effective hire date. Any saved candidate changes will sync into the scheduled hire record automatically.</p>';
      echo '</div>';
    }

    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="eum_job_board_candidate_update"><input type="hidden" name="candidate_id" value="' . $candidate_id . '"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_job_board_candidate_' . $candidate_id, 'eum_job_board_candidate_nonce');
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Candidate Type</label>' . $this->render_candidate_source_select('candidate_source', $source, 'candidate_source_label', (string)get_post_meta($candidate_id, self::CAND_META_SOURCE_LABEL, true)) . '</p>';
    if ($show_internal_user_picker) {
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Internal User</label><select name="internal_user_id" style="width:100%;max-width:520px;"><option value="0">— None —</option>';
    foreach ($this->candidate_internal_user_options($internal_user_id) as $u) {
      $label = $u->display_name ?: $u->user_login;
      if (!empty(get_user_meta((int)$u->ID, 'eum_terminated', true))) $label .= !empty(get_user_meta((int)$u->ID, 'eum_rehirable', true)) ? ' (terminated · rehirable)' : ' (terminated · non-rehireable)';
      echo '<option value="' . (int)$u->ID . '" ' . selected($internal_user_id, (int)$u->ID, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select><br><span class="description">Use this to convert an external application into an internal candidate, including rehirable terminated users.</span></p>';
    } else {
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Internal User</label><input type="hidden" name="internal_user_id" value="0"><span class="description">Switch Candidate Type to Internal and save to load the internal-user picker only when you need it.</span></p>';
    }
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Full Name</label><input type="text" name="full_name" style="width:100%;max-width:520px;" value="' . esc_attr($full_name) . '"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Email</label><input type="email" name="email" style="width:100%;max-width:520px;" value="' . esc_attr($email) . '"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Phone</label><input type="text" name="phone" style="width:100%;max-width:320px;" value="' . esc_attr($phone) . '"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Candidate Status</label><select name="candidate_status">';
    foreach (['new', 'review', 'pre_accepted', 'selected', 'pending_hire', 'hired', 'rejected'] as $candidate_status_option) {
      echo '<option value="' . esc_attr($candidate_status_option) . '" ' . selected($status, $candidate_status_option, false) . '>' . esc_html($this->candidate_status_label($candidate_status_option)) . '</option>';
    }
    echo '</select></p>';
    $candidate_preset_meta = get_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, true);
    $candidate_preset_meta = is_array($candidate_preset_meta) ? $candidate_preset_meta : [];
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Preset User Meta on Hire / Acceptance</label><textarea name="preset_user_meta" style="width:100%;min-height:90px;" placeholder="meta_key = value">' . esc_textarea($this->preset_user_meta_text($candidate_preset_meta)) . '</textarea><span class="description" style="display:block;margin-top:6px;">Applied to the linked/created user when this candidate is hired or accepts a pre-acceptance invite.</span></p>';
    if (!empty($job_application_fields) || !empty($legacy_application_responses)) {
      echo '<div style="margin:18px 0 8px;padding-top:14px;border-top:1px solid #e2e4e7;"><h4 style="margin:0 0 8px;">Job Application Questions</h4>';
      if (!empty($job_application_fields)) echo $this->render_application_question_inputs($job_application_fields, $application_responses, 'eum_application_responses', false);
      if (!empty($legacy_application_responses)) echo $this->render_candidate_application_response_summary($legacy_application_responses);
      echo '</div>';
    }
    echo '<div style="margin:18px 0 8px;padding-top:14px;border-top:1px solid #e2e4e7;"><h4 style="margin:0 0 8px;">Candidate Profile</h4>';
    foreach ($editable_fields as $field_id) echo EUM_Profiles::render_field_input($field_id, $profile[$field_id] ?? '', 0, $actor_user_id);
    echo $this->render_history_repeater('work_history', 'Work History', is_array($profile['custom:work_history'] ?? null) ? $profile['custom:work_history'] : [], [
      'job_title' => 'Job Title', 'company' => 'Company', 'start_date' => 'Start Date', 'end_date' => 'End Date', 'responsibilities' => 'Responsibilities',
    ]);
    echo $this->render_history_repeater('education_history', 'Education History', is_array($profile['custom:education_history'] ?? null) ? $profile['custom:education_history'] : [], [
      'school' => 'School', 'degree' => 'Degree / Program', 'field' => 'Field of Study', 'start_date' => 'Start Date', 'end_date' => 'End Date', 'details' => 'Details',
    ]);
    $resume_id = (int)($profile['custom:resume_attachment_id'] ?? 0);
    $cover_id = (int)($profile['custom:cover_letter_attachment_id'] ?? 0);
    if ($resume_id > 0) echo '<p><strong>Resume:</strong> <a href="' . esc_url(wp_get_attachment_url($resume_id)) . '" target="_blank" rel="noopener">View Resume</a></p>';
    if ($cover_id > 0) echo '<p><strong>Cover Letter:</strong> <a href="' . esc_url(wp_get_attachment_url($cover_id)) . '" target="_blank" rel="noopener">View Cover Letter</a></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Replace Resume</label><input type="file" name="resume_file" accept=".pdf,.doc,.docx,.rtf,.txt"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Replace Cover Letter</label><input type="file" name="cover_letter_file" accept=".pdf,.doc,.docx,.rtf,.txt"></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Additional Information / Cover Letter</label><textarea name="notes" style="width:100%;min-height:90px;">' . esc_textarea((string)($profile['custom:bio'] ?? '')) . '</textarea></p></div>';
    if ($source === 'internal' || $internal_user_id > 0) {
      echo '<div style="margin:18px 0 8px;padding-top:14px;border-top:1px solid #e2e4e7;"><h4 style="margin:0 0 8px;">Internal Transfer Scheduling</h4><p class="description">The incoming manager can propose a date. The current manager keeps final control of the effective date, with the normal transfer timing limits.</p>';
      if (EUM_Access::is_it_or_hr($actor_user_id) || $this->actor_is_hiring_manager_for_candidate($actor_user_id, $candidate_id)) echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Incoming Manager Proposed Date</label><input type="datetime-local" name="hiring_manager_date" value="' . esc_attr($this->datetime_local_value($hiring_date)) . '"></p>';
      if (EUM_Access::is_it_or_hr($actor_user_id) || $this->actor_is_current_manager_for_candidate($actor_user_id, $candidate_id)) echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Current Manager Effective Date</label><input type="datetime-local" name="current_manager_date" value="' . esc_attr($this->datetime_local_value($current_date)) . '"></p>';
      echo '</div>';
    }
    echo '<p><button type="submit" class="button button-secondary">Save Candidate</button></p></form>';

    if ($hire_status === 'scheduled' && $this->can_manage_pending_hire($hire_id, $actor_user_id)) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:18px;padding-top:14px;border-top:1px solid #dcdcde;"><input type="hidden" name="action" value="eum_job_board_candidate_pending_hire_update"><input type="hidden" name="candidate_id" value="' . $candidate_id . '"><input type="hidden" name="hire_id" value="' . $hire_id . '"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
      wp_nonce_field('eum_job_board_candidate_pending_hire_' . $candidate_id, 'eum_job_board_candidate_pending_hire_nonce');
      echo '<h4 style="margin:0 0 8px;">Pending Hire Settings</h4>';
      if (class_exists('EUM_Timeclock')) echo EUM_Timeclock::render_compensation_inputs('eum_comp', $hire_profile_after, ['title' => 'Compensation', 'description' => 'Compensation saved here will apply when the hire is created.']);
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Username</label><input type="text" name="username" style="width:100%;max-width:320px;" value="' . esc_attr($hire_username) . '"></p>';
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Company Email</label><input type="text" disabled style="width:100%;max-width:420px;" value="' . esc_attr($hire_email) . '"><br><span class="description">This updates automatically from the reserved username and company division.</span></p>';
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Effective Hire Date</label><input type="datetime-local" name="effective_at" style="width:100%;max-width:320px;" value="' . esc_attr($this->datetime_local_value($hire_effective_at)) . '" required></p>';
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Preset User Meta on Hire</label><textarea name="preset_user_meta" style="width:100%;min-height:90px;" placeholder="meta_key = value">' . esc_textarea($this->preset_user_meta_text($candidate_preset_meta)) . '</textarea></p>';
      echo '<p><button type="submit" class="button button-primary">Update Pending Hire</button></p></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
      echo '<input type="hidden" name="action" value="eum_user_creation_cancel">';
      echo '<input type="hidden" name="hire_id" value="' . $hire_id . '">';
      echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
      wp_nonce_field('eum_user_creation_cancel_' . $hire_id, 'eum_user_creation_cancel_nonce');
      echo '<button type="submit" class="button" onclick="return confirm(&quot;Cancel this pending hire? The candidate will stay in Candidates and can be edited or rescheduled.&quot;)">Cancel Pending Hire</button>';
      echo '</form>';
    } elseif ($source === 'internal' || $internal_user_id > 0) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:18px;padding-top:14px;border-top:1px solid #dcdcde;"><input type="hidden" name="action" value="eum_job_board_candidate_hire"><input type="hidden" name="candidate_id" value="' . $candidate_id . '"><input type="hidden" name="full_name" value="' . esc_attr($full_name) . '"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
      wp_nonce_field('eum_job_board_candidate_hire_' . $candidate_id, 'eum_job_board_candidate_hire_nonce');
      echo '<h4 style="margin:0 0 8px;">Start Internal Transfer</h4><p class="description">Active internal candidates start a staged transfer request. Rehirable terminated users are rehired and transferred immediately. Non-rehireable users require HR/IT approval.</p><p><button type="submit" class="button button-primary">Select Internal Candidate</button></p>';
      echo '</form>';
    } else {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:18px;padding-top:14px;border-top:1px solid #dcdcde;"><input type="hidden" name="action" value="eum_job_board_candidate_hire"><input type="hidden" name="candidate_id" value="' . $candidate_id . '"><input type="hidden" name="full_name" value="' . esc_attr($full_name) . '"><input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
      wp_nonce_field('eum_job_board_candidate_hire_' . $candidate_id, 'eum_job_board_candidate_hire_nonce');
      echo '<h4 style="margin:0 0 8px;">Schedule Hire</h4>';
      if (class_exists('EUM_Timeclock')) echo EUM_Timeclock::render_compensation_inputs('eum_comp', [], ['title' => 'Compensation', 'description' => 'Compensation saved here will be applied on the hire date.']);
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Username</label><input type="text" name="username" style="width:100%;max-width:320px;" value="' . esc_attr($this->generate_username_from_name($full_name)) . '"></p><p><label style="display:block;font-weight:600;margin-bottom:4px;">Effective Hire Date</label><input type="datetime-local" name="effective_at" style="width:100%;max-width:320px;" value="' . esc_attr($effective_default) . '" required></p><p><label style="display:block;font-weight:600;margin-bottom:4px;">Preset User Meta on Hire</label><textarea name="preset_user_meta" style="width:100%;min-height:90px;" placeholder="meta_key = value">' . esc_textarea($this->preset_user_meta_text($candidate_preset_meta)) . '</textarea></p><p><button type="submit" class="button button-primary">Schedule Hire</button></p>';
      echo '</form>';
    }
    $candidate_delete_url = wp_nonce_url(add_query_arg(['action' => 'eum_job_board_candidate_delete', 'candidate_id' => $candidate_id, 'return_url' => $this->current_url()], admin_url('admin-post.php')), 'eum_job_board_candidate_delete_' . $candidate_id);
    echo '<p style="margin-top:12px;"><a class="button" href="' . esc_url($candidate_delete_url) . '" onclick="return confirm(&quot;Delete this candidate record? Pending hire workflow items tied to this candidate will also be removed.&quot;);">Delete Candidate</a></p>';
    echo '</div>' . $this->render_history_repeater_script();
    return ob_get_clean();
    } catch (\Throwable $e) {
      while (ob_get_level() > $buffer_level) ob_end_clean();
      if (defined('WP_DEBUG') && WP_DEBUG) eum_log_message('[EUM Job Boards] Candidate detail render failed for #' . $candidate_id . ': ' . $e->getMessage());
      return '<div class="eum-candidate-editor" style="margin-top:18px;padding:18px;border:1px solid #ccd0d4;border-radius:8px;background:#f8f9fa;"><p>Candidate details are temporarily unavailable.</p></div>';
    }
  }

  private function candidate_count_for_job(int $job_id, bool $include_historical = false): int {
    $items = get_posts(['post_type' => self::CPT_CANDIDATE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => self::CAND_META_JOB_ID, 'meta_value' => $job_id]);
    if (!$include_historical) {
      $items = array_values(array_filter((array)$items, fn($candidate_id) => !$this->candidate_is_historical((int)$candidate_id)));
    }
    return is_array($items) ? count($items) : 0;
  }

  private function actor_can_manage_job(int $actor_user_id, int $job_id): bool {
    if ($actor_user_id <= 0 || $job_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;

    $job = get_post($job_id);
    if (!$job || $job->post_type !== self::CPT_JOB) return false;

    $creator_user_id = (int)$job->post_author;
    if ($creator_user_id > 0 && $creator_user_id === $actor_user_id) return true;

    $owner_user_id = $this->job_owner_user_id($job_id);
    if ($owner_user_id > 0 && ($owner_user_id === $actor_user_id || $this->user_is_in_owner_chain($actor_user_id, $owner_user_id))) {
      return true;
    }

    $job_term_id = (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true);
    return $job_term_id > 0 && $this->actor_can_manage_job_term($actor_user_id, $job_term_id);
  }

  private function actor_can_manage_job_term(int $actor_user_id, int $term_id): bool {
    if ($actor_user_id <= 0 || $term_id <= 0) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    $my_mids = class_exists('EUM_Manager_IDs') ? EUM_Manager_IDs::manager_ids_for_user($actor_user_id) : [];
    $chain = array_merge([$term_id], get_ancestors($term_id, 'eum_job_path', 'taxonomy'));
    foreach ($chain as $tid) if (!empty(array_intersect($my_mids, $this->manager_ids_for_term((int)$tid)))) return true;
    return false;
  }

  private function manager_ids_for_term(int $term_id): array {
    $mids = class_exists('EUM_Manager_Messages') ? (array)get_term_meta($term_id, EUM_Manager_Messages::TERM_META_MANAGER_IDS, true) : [];
    return array_values(array_filter(array_map('intval', $mids)));
  }

  private function redirect_to_candidate(string $return_url, int $candidate_id, string $message = '', string $type = 'success'): void {
    $args = ['eum_tab' => 'jobs', 'eum_candidate' => $candidate_id];
    if ($message !== '') {
      $args['eum_jobs_msg'] = $message;
      $args['eum_jobs_type'] = $type === 'error' ? 'error' : 'success';
    }
    wp_safe_redirect(add_query_arg($args, $return_url));
    exit;
  }

  private function redirect_to_jobs_notice(string $return_url, string $message, string $type = 'success'): void {
    wp_safe_redirect(add_query_arg([
      'eum_tab' => 'jobs',
      'eum_jobs_msg' => $message,
      'eum_jobs_type' => $type === 'error' ? 'error' : 'success',
    ], $return_url));
    exit;
  }

  private function restore_candidate_status_after_failed_hire(int $candidate_id): void {
    if ($candidate_id <= 0) return;
    $restore_status = (string)get_post_meta($candidate_id, self::CAND_META_PRE_HIRE_STATUS, true);
    if ($restore_status === '') $restore_status = 'selected';
    update_post_meta($candidate_id, self::CAND_META_STATUS, $restore_status);
  }

  private function company_email_generation_error_message(string $username, int $job_term_id, string $division_key): string {
    $username = sanitize_user($username, true);
    $job_path = $job_term_id > 0 ? $this->term_full_path('eum_job_path', $job_term_id, ' / ') : '';
    if ($username === '') return 'Company email could not be generated because the reserved username is blank after sanitization.';
    if (!class_exists('EUM_Company_Divisions')) return 'Company email could not be generated because Company Divisions is unavailable.';
    $division_key = EUM_Company_Divisions::normalize_division_key($division_key);
    if ($division_key === '') {
      return 'Company email could not be generated because the selected Job Path' . ($job_path !== '' ? ' (' . $job_path . ')' : '') . ' does not resolve to a Company Division.';
    }
    $label = EUM_Company_Divisions::division_label($division_key);
    $domain = EUM_Company_Divisions::division_domain($division_key);
    if ($domain === '') {
      return 'Company email could not be generated because the Company Division "' . $label . '" does not have a valid email domain configured.';
    }
    return 'Company email could not be generated for reserved username "' . $username . '" in Company Division "' . $label . '".';
  }

  private function company_email_generation_error_for_hire(int $hire_id): string {
    $username = sanitize_user((string)get_post_meta($hire_id, EUM_User_Creation::META_USERNAME, true), true);
    $job_term_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_JOB_TERM, true);
    $profile_after = get_post_meta($hire_id, EUM_User_Creation::META_PROFILE_AFTER, true);
    $profile_after = is_array($profile_after) ? $profile_after : [];
    $candidate_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_CANDIDATE_ID, true);
    $linked_user_id = $candidate_id > 0 ? (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true) : 0;
    $full_name = sanitize_text_field((string)get_post_meta($hire_id, EUM_User_Creation::META_FULL_NAME, true));
    if ($full_name === '' && $candidate_id > 0) $full_name = (string)get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true);
    $division_key = class_exists('EUM_Company_Divisions') ? $this->candidate_division_key_for_profile($profile_after, $job_term_id, $linked_user_id, $full_name) : '';
    return $this->company_email_generation_error_message($username, $job_term_id, $division_key);
  }

  private function sync_pending_hire_from_candidate(int $candidate_id, array $overrides = []): void {
    if ($candidate_id <= 0 || !class_exists('EUM_User_Creation')) return;
    $hire_id = (int)($overrides['hire_id'] ?? get_post_meta($candidate_id, self::CAND_META_HIRE_ID, true));
    if ($hire_id <= 0) return;
    $hire = get_post($hire_id);
    if (!$hire || $hire->post_type !== EUM_User_Creation::CPT) return;
    if ((string)get_post_meta($hire_id, EUM_User_Creation::META_STATUS, true) !== 'scheduled') return;

    $full_name = sanitize_text_field((string)get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true));
    $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    $candidate_profile = get_post_meta($candidate_id, self::CAND_META_PROFILE, true);
    $candidate_profile = is_array($candidate_profile) ? $candidate_profile : [];
    $profile_after = get_post_meta($hire_id, EUM_User_Creation::META_PROFILE_AFTER, true);
    $profile_after = array_merge(is_array($profile_after) ? $profile_after : [], $candidate_profile);
    if (!empty($overrides['profile_after']) && is_array($overrides['profile_after'])) {
      $profile_after = array_merge($profile_after, $overrides['profile_after']);
    }
    $linked_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
    $profile_after = $this->apply_candidate_conditional_job_path_logic($profile_after, $candidate_id, $linked_user_id, $job_term_id);

    $username = sanitize_user((string)($overrides['username'] ?? get_post_meta($hire_id, EUM_User_Creation::META_USERNAME, true)), true);
    if ($username === '') $username = $this->generate_username_from_name($full_name);
    $effective_at = $this->parse_datetime_local((string)($overrides['effective_at'] ?? get_post_meta($hire_id, EUM_User_Creation::META_EFFECTIVE_AT, true)));
    if ($effective_at === '') $effective_at = (string)get_post_meta($hire_id, EUM_User_Creation::META_EFFECTIVE_AT, true);

    $division_key = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::effective_division_for_job_path($job_term_id) : '';
    if ($division_key === '' && class_exists('EUM_Company_Divisions')) $division_key = $this->candidate_division_key_for_profile($profile_after, $job_term_id);
    if (class_exists('EUM_Company_Divisions')) $division_key = EUM_Company_Divisions::normalize_division_key($division_key);
    if ($division_key !== '') $profile_after['core:job_company'] = $division_key;
    $email = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::build_email_for_username_and_division($username, $division_key) : sanitize_email((string)get_post_meta($candidate_id, self::CAND_META_EMAIL, true));
    if ($email !== '') $profile_after['core:user_email'] = $email;

    wp_update_post(['ID' => $hire_id, 'post_title' => 'Candidate Hire - ' . $full_name . ' (' . $username . ')']);
    update_post_meta($hire_id, EUM_User_Creation::META_FULL_NAME, $full_name);
    update_post_meta($hire_id, EUM_User_Creation::META_USERNAME, $username);
    update_post_meta($hire_id, EUM_User_Creation::META_EMAIL, $email);
    if ($job_term_id > 0) update_post_meta($hire_id, EUM_User_Creation::META_JOB_TERM, $job_term_id);
    if ($effective_at !== '') update_post_meta($hire_id, EUM_User_Creation::META_EFFECTIVE_AT, $effective_at);
    update_post_meta($hire_id, EUM_User_Creation::META_PROFILE_AFTER, $profile_after);
    update_post_meta($hire_id, EUM_User_Creation::META_ERROR, '');
    update_post_meta($candidate_id, self::CAND_META_STATUS, 'pending_hire');
  }

  private function can_manage_pending_hire(int $hire_id, int $actor_user_id): bool {
    if ($hire_id <= 0 || $actor_user_id <= 0 || !class_exists('EUM_User_Creation')) return false;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    $hire = get_post($hire_id);
    if (!$hire || $hire->post_type !== EUM_User_Creation::CPT) return false;
    if ((string)get_post_meta($hire_id, EUM_User_Creation::META_STATUS, true) !== 'scheduled') return false;
    $creator = (int)get_post_meta($hire_id, EUM_User_Creation::META_ACTOR_USER, true);
    if ($creator === $actor_user_id) return true;
    return $this->actor_can_manage_job_term($actor_user_id, (int)get_post_meta($hire_id, EUM_User_Creation::META_JOB_TERM, true));
  }

  private function next_available_username_for_pending_hire(string $base, int $hire_id, int $candidate_id = 0): string {
    $base = sanitize_user($base, true) ?: 'user';
    $job_term_id = $candidate_id > 0 ? $this->candidate_effective_job_term_id($candidate_id) : 0;
    $profile = $candidate_id > 0 ? get_post_meta($candidate_id, self::CAND_META_PROFILE, true) : [];
    $profile = is_array($profile) ? $profile : [];
    if ($candidate_id > 0) {
      $profile = $this->apply_candidate_conditional_job_path_logic($profile, $candidate_id, (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true), $job_term_id);
    }
    $division_key = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::effective_division_for_job_path($job_term_id) : '';
    if ($division_key === '' && class_exists('EUM_Company_Divisions')) $division_key = $this->candidate_division_key_for_profile($profile, $job_term_id, (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true));
    if (class_exists('EUM_Company_Divisions')) $division_key = EUM_Company_Divisions::normalize_division_key($division_key);

    $candidate = $base;
    $i = 2;
    while (
      username_exists($candidate)
      || $this->pending_hire_conflict(EUM_User_Creation::META_USERNAME, $candidate, $hire_id)
      || (class_exists('EUM_Company_Divisions') && ($candidate_email = EUM_Company_Divisions::build_email_for_username_and_division($candidate, $division_key)) && (email_exists($candidate_email) || $this->pending_hire_conflict(EUM_User_Creation::META_EMAIL, $candidate_email, $hire_id)))
    ) {
      $candidate = $base . $i;
      $i++;
    }
    return $candidate;
  }

  private function pending_hire_conflict(string $meta_key, string $value, int $exclude_hire_id = 0): bool {
    if (!class_exists('EUM_User_Creation') || $value === '') return false;
    $items = get_posts([
      'post_type' => EUM_User_Creation::CPT,
      'post_status' => 'publish',
      'numberposts' => 50,
      'fields' => 'ids',
      'meta_query' => [
        'relation' => 'AND',
        ['key' => EUM_User_Creation::META_STATUS, 'value' => 'scheduled'],
        ['key' => $meta_key, 'value' => $value],
      ],
    ]);
    foreach ((array)$items as $item_id) {
      if ((int)$item_id !== $exclude_hire_id) return true;
    }
    return false;
  }

  private function job_terms_for_actor(int $actor_user_id): array {
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'number' => 0]);
    if (is_wp_error($terms)) return [];
    if (EUM_Access::is_it_or_hr($actor_user_id)) return $terms;
    return array_values(array_filter($terms, fn($term) => $this->actor_can_manage_job_term($actor_user_id, (int)$term->term_id)));
  }

  private function candidate_application_fields(): array {
    return [
      'core:first_name',
      'custom:middle_name',
      'core:last_name',
      'custom:preferred_name',
      'custom:pronouns',
      'custom:date_of_birth',
      'custom:personal_email',
      'custom:mobile_phone',
      'custom:residence_city',
      'custom:residence_state',
      'custom:linkedin',
      'custom:skills',
      'custom:languages',
    ];
  }

  private function preaccepted_signup_fields(): array {
    return [
      'core:first_name',
      'custom:middle_name',
      'core:last_name',
      'custom:preferred_name',
      'custom:pronouns',
      'custom:date_of_birth',
      'custom:personal_email',
      'custom:mobile_phone',
      'custom:residence_city',
      'custom:residence_state',
    ];
  }

  private function preaccepted_required_fields(): array {
    return array_values(array_diff($this->preaccepted_signup_fields(), ['custom:preferred_name']));
  }

  private function preaccepted_field_label(string $field_id): string {
    if (class_exists('EUM_Profiles')) {
      $catalog = EUM_Profiles::field_catalog();
      if (isset($catalog[$field_id]['label'])) return strtolower((string)$catalog[$field_id]['label']);
    }
    $field_id = preg_replace('/^(core|custom|tax):/', '', $field_id);
    return strtolower(str_replace('_', ' ', (string)$field_id));
  }

  private function candidate_editable_fields(int $actor_user_id, int $candidate_id = 0): array {
    $internal_user_id = $candidate_id > 0 ? (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true) : 0;
    $source = $candidate_id > 0 ? sanitize_key((string)get_post_meta($candidate_id, self::CAND_META_SOURCE, true)) : '';
    $fields = $this->candidate_application_fields();
    if ($internal_user_id > 0) $fields = array_merge($fields, EUM_Profiles::editable_fields_for_actor($actor_user_id, $internal_user_id));
    else $fields = array_merge($fields, EUM_Profiles::editable_fields_for_new_hire_actor($actor_user_id));
    if ($candidate_id > 0) $fields[] = 'tax:eum_job_path';
    if ($internal_user_id <= 0 && $source !== 'internal') {
      $fields = array_values(array_diff($fields, ['core:eum_manager_user_id']));
    }

    $catalog = EUM_Profiles::field_catalog();
    $ordered = [];
    foreach (array_keys($catalog) as $field_id) {
      if (in_array($field_id, $fields, true) && !in_array($field_id, ['core:user_pass'], true)) $ordered[] = $field_id;
    }
    return array_values(array_unique($ordered));
  }

  private function candidate_source_supports_email_match_history(string $source): bool {
    return sanitize_key($source) !== 'manual';
  }

  private function should_show_candidate_internal_user_picker(string $source, int $internal_user_id): bool {
    return $internal_user_id > 0 || sanitize_key($source) === 'internal';
  }

  private function candidate_status_label(string $status): string {
    $status = sanitize_key($status);
    switch ($status) {
      case 'review':
        return 'In review';
      case 'pre_accepted':
        return 'Pre-accepted';
      case 'pending_hire':
        return 'Pending Hire';
      default:
        return ucfirst(str_replace('_', ' ', $status ?: 'new'));
    }
  }

  private function job_visibility_label(string $visibility): string {
    switch (sanitize_key($visibility)) {
      case 'internal':
        return 'Internal only';
      case 'external':
        return 'External only';
      case 'private':
        return 'Private';
      case 'both':
      default:
        return 'Internal + External';
    }
  }

  private function normalize_job_status(string $status): string {
    $status = sanitize_key($status);
    if ($status === 'open') return 'published';
    if ($status === 'closed') return 'unpublished';
    if (!in_array($status, ['published', 'unpublished', 'draft'], true)) return 'published';
    return $status;
  }

  private function job_status_label(string $status): string {
    switch ($this->normalize_job_status($status)) {
      case 'draft':
        return 'Draft';
      case 'unpublished':
        return 'Unpublished';
      case 'published':
      default:
        return 'Published';
    }
  }

  private function candidate_profile_from_user(int $user_id): array {
    $out = [];
    foreach ($this->candidate_application_fields() as $field_id) $out[$field_id] = EUM_Profiles::get_field_raw_value($user_id, $field_id);
    if (class_exists('EUM_Profiles')) {
      $out = array_merge($out, EUM_Profiles::candidate_extended_profile_for_user($user_id));
    }
    return $out;
  }

  private function create_candidate_from_request(int $job_id, string $source, array $payload, array $files = []): int {
    $job = get_post($job_id);
    if (!$job || $job->post_type !== self::CPT_JOB) wp_die('Invalid job.');
    $internal_user_id = (int)($payload['internal_user_id'] ?? 0);
    if ($source === 'internal' && $internal_user_id <= 0 && is_user_logged_in()) $internal_user_id = get_current_user_id();
    $first_name_input = sanitize_text_field((string)($payload['first_name'] ?? ''));
    $last_name_input = sanitize_text_field((string)($payload['last_name'] ?? ''));
    $personal_email_input = sanitize_email((string)($payload['personal_email'] ?? ($payload['email'] ?? '')));
    $phone_input = sanitize_text_field((string)($payload['phone_number'] ?? ($payload['phone'] ?? '')));
    $flat_map = [
      'core:first_name' => $first_name_input !== '' ? $first_name_input : null,
      'custom:middle_name' => $payload['middle_name'] ?? null,
      'core:last_name' => $last_name_input !== '' ? $last_name_input : null,
      'custom:preferred_name' => $payload['preferred_name'] ?? null,
      'custom:pronouns' => $payload['pronouns'] ?? null,
      'custom:personal_email' => $personal_email_input !== '' ? $personal_email_input : null,
      'custom:mobile_phone' => $phone_input !== '' ? $phone_input : null,
      'custom:residence_city' => $payload['city'] ?? null,
      'custom:residence_state' => $payload['state'] ?? null,
      'custom:linkedin' => $payload['linkedin'] ?? null,
      'custom:skills' => $payload['skills'] ?? null,
      'custom:languages' => $payload['languages'] ?? null,
    ];
    $application_responses = $this->sanitize_candidate_application_responses($job_id, $payload, $source !== 'manual');
    $stored_candidate_profile = ($source === 'internal' && $internal_user_id > 0 && class_exists('EUM_Profiles'))
      ? EUM_Profiles::candidate_extended_profile_for_user($internal_user_id)
      : [];
    $profile = [];
    foreach ($this->candidate_application_fields() as $field_id) {
      $posted = $payload['eum_pf'][$field_id] ?? ($flat_map[$field_id] ?? null);
      if ($posted === null && $source === 'internal' && $internal_user_id > 0) $profile[$field_id] = EUM_Profiles::get_field_raw_value($internal_user_id, $field_id);
      else {
        $normalized = EUM_Profiles::normalize_field_input($field_id, $posted);
        if ($normalized !== null) $profile[$field_id] = $normalized;
      }
    }
    $profile['custom:work_history'] = $this->sanitize_repeater_items($payload['eum_hist']['work_history'] ?? ($payload['work_history'] ?? ($stored_candidate_profile['custom:work_history'] ?? [])));
    $profile['custom:education_history'] = $this->sanitize_repeater_items($payload['eum_hist']['education_history'] ?? ($payload['education_history'] ?? ($stored_candidate_profile['custom:education_history'] ?? [])));
    $profile['custom:bio'] = sanitize_textarea_field((string)($payload['notes'] ?? ($payload['cover_note'] ?? '')));
    $resume_id = $this->handle_candidate_upload($files, 'resume_file');
    if ($resume_id <= 0) $resume_id = $this->handle_candidate_upload($files, 'resume');
    if ($resume_id <= 0) $resume_id = $this->handle_candidate_payload_upload($payload, 'resume_upload');
    if ($resume_id > 0) $profile['custom:resume_attachment_id'] = $resume_id;
    elseif (!empty($stored_candidate_profile['custom:resume_attachment_id'])) $profile['custom:resume_attachment_id'] = (int)$stored_candidate_profile['custom:resume_attachment_id'];
    $cover_id = $this->handle_candidate_upload($files, 'cover_letter_file');
    if ($cover_id <= 0) $cover_id = $this->handle_candidate_upload($files, 'cover_letter');
    if ($cover_id <= 0) $cover_id = $this->handle_candidate_payload_upload($payload, 'cover_letter_upload');
    if ($cover_id > 0) $profile['custom:cover_letter_attachment_id'] = $cover_id;
    elseif (!empty($stored_candidate_profile['custom:cover_letter_attachment_id'])) $profile['custom:cover_letter_attachment_id'] = (int)$stored_candidate_profile['custom:cover_letter_attachment_id'];

    $job_term_id = (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true);
    $job_title_token = class_exists('EUM_Profiles') ? EUM_Profiles::normalize_job_title_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true), $job_term_id) : sanitize_text_field((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true));
    if ($job_term_id > 0 && empty($profile['tax:eum_job_path'])) $profile['tax:eum_job_path'] = $job_term_id;
    if ($job_title_token !== '' && empty($profile['custom:job_title'])) $profile['custom:job_title'] = $job_title_token;
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, 0, $internal_user_id, $job_term_id);
    $fallback_full_name = trim($first_name_input . ' ' . $last_name_input);
    if ($fallback_full_name === '') $fallback_full_name = sanitize_text_field((string)($payload['full_name'] ?? 'Candidate'));
    [$full_name, $email, $phone] = $this->candidate_summary_from_profile($profile, [
      'full_name' => $fallback_full_name,
      'email' => $personal_email_input,
      'phone' => $phone_input,
    ]);
    $first_name = sanitize_text_field((string)($profile['core:first_name'] ?? ''));
    $last_name = sanitize_text_field((string)($profile['core:last_name'] ?? ''));
    if ($first_name === '' || $last_name === '') wp_die('First name and last name are required.');
    if ($email === '') wp_die('Personal email is required.');
    if ($source !== 'manual' && empty($payload['allow_missing_phone']) && $phone === '') wp_die('Phone number is required.');
    if (!is_email($email)) wp_die('A valid personal email is required.');

    $candidate_post_author = $internal_user_id > 0 ? $internal_user_id : (get_current_user_id() ?: 0);
    $candidate_id = (int)wp_insert_post(['post_type' => self::CPT_CANDIDATE, 'post_status' => 'publish', 'post_title' => $full_name ?: 'Candidate', 'post_author' => $candidate_post_author]);
    update_post_meta($candidate_id, self::CAND_META_JOB_ID, $job_id);
    update_post_meta($candidate_id, self::CAND_META_FULL_NAME, $full_name);
    update_post_meta($candidate_id, self::CAND_META_EMAIL, $email);
    update_post_meta($candidate_id, self::CAND_META_PHONE, $phone);
    update_post_meta($candidate_id, self::CAND_META_SOURCE, $source);
    update_post_meta($candidate_id, self::CAND_META_USER_ID, $internal_user_id);
    update_post_meta($candidate_id, self::CAND_META_STATUS, 'new');
    update_post_meta($candidate_id, self::CAND_META_PROFILE, $profile);
    update_post_meta($candidate_id, self::CAND_META_PRESET_USER_META, $this->merged_candidate_preset_user_meta($job_id, $payload['preset_user_meta'] ?? ''));
    update_post_meta($candidate_id, self::CAND_META_APPLIED_AT, current_time('mysql'));
    $this->ensure_candidate_review_invite($candidate_id);
    $this->persist_candidate_application_responses($candidate_id, $application_responses);
    if ($internal_user_id > 0) self::ensure_candidate_application_log($candidate_id, $internal_user_id, $internal_user_id);
    elseif ($this->candidate_source_supports_email_match_history($source)) $this->sync_candidate_email_match_history($candidate_id, get_current_user_id() ?: $candidate_post_author);
    try {
      do_action('eum_candidate_application_created', $candidate_id, $job_id, $source, [
        'candidate_id' => $candidate_id,
        'internal_user_id' => $internal_user_id,
        'full_name' => $full_name,
        'email' => $email,
        'phone' => $phone,
        'profile' => $profile,
        'application_fields' => $this->job_application_fields($job_id),
        'application_responses' => $application_responses,
      ]);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) eum_log_message('[EUM Job Boards] Candidate application hook failed: ' . $e->getMessage());
    }
    return $candidate_id;
  }

  private function sanitize_repeater_items($items): array {
    if (is_string($items) && $items !== '') { $decoded = json_decode($items, true); if (is_array($decoded)) $items = $decoded; }
    $items = is_array($items) ? $items : [];
    $out = [];
    foreach ($items as $row) {
      if (!is_array($row)) continue;
      $clean = [];
      foreach ($row as $k => $v) $clean[sanitize_key((string)$k)] = sanitize_textarea_field((string)$v);
      $joined = trim(implode('', array_map('strval', $clean)));
      if ($joined !== '') $out[] = $clean;
    }
    return $out;
  }

  private function handle_candidate_upload(array $files, string $key): int {
    if (empty($files[$key]['name'])) return 0;
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attachment_id = media_handle_upload($key, 0);
    return is_wp_error($attachment_id) ? 0 : (int)$attachment_id;
  }

  private function handle_candidate_payload_upload(array $payload, string $key): int {
    $raw = $payload[$key] ?? null;
    if (is_string($raw) && $raw !== '') {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $raw = $decoded;
    }
    if (!is_array($raw)) return 0;

    $filename = sanitize_file_name((string)($raw['name'] ?? 'upload'));
    $contents_b64 = (string)($raw['contents_base64'] ?? '');
    if ($filename === '' || $contents_b64 === '') return 0;

    $binary = base64_decode($contents_b64, true);
    if ($binary === false || $binary === '') return 0;

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = wp_tempnam($filename);
    if (!$tmp) return 0;
    $bytes = file_put_contents($tmp, $binary);
    if ($bytes === false || $bytes <= 0) {
      @unlink($tmp);
      return 0;
    }

    $file_array = [
      'name' => $filename,
      'type' => sanitize_mime_type((string)($raw['type'] ?? '')) ?: 'application/octet-stream',
      'tmp_name' => $tmp,
      'error' => 0,
      'size' => (int)filesize($tmp),
    ];
    $sideload = wp_handle_sideload($file_array, ['test_form' => false], current_time('mysql'));
    @unlink($tmp);
    if (!is_array($sideload) || !empty($sideload['error']) || empty($sideload['file'])) return 0;

    $filetype = wp_check_filetype((string)$sideload['file'], null);
    $attachment = [
      'guid' => (string)($sideload['url'] ?? ''),
      'post_mime_type' => (string)($filetype['type'] ?? ($sideload['type'] ?? 'application/octet-stream')),
      'post_title' => preg_replace('/\.[^.]+$/', '', wp_basename($filename)),
      'post_content' => '',
      'post_status' => 'inherit',
    ];
    $attachment_id = wp_insert_attachment($attachment, (string)$sideload['file'], 0);
    if (is_wp_error($attachment_id) || (int)$attachment_id <= 0) {
      return 0;
    }

    $mime = (string)$attachment['post_mime_type'];
    if (strpos($mime, 'image/') === 0) {
      $metadata = wp_generate_attachment_metadata((int)$attachment_id, (string)$sideload['file']);
      if (!is_wp_error($metadata) && !empty($metadata)) wp_update_attachment_metadata((int)$attachment_id, $metadata);
    }
    return (int)$attachment_id;
  }

  private function render_history_repeater(string $key, string $label, array $items, array $fields): string {
    if (empty($items)) $items = [[]];
    ob_start();
    echo '<div class="eum-history-repeater" data-key="' . esc_attr($key) . '" style="margin:18px 0;padding:14px;border:1px solid #dcdcde;border-radius:8px;background:#fff;">';
    echo '<h4 style="margin:0 0 10px;">' . esc_html($label) . '</h4><div class="eum-history-items">';
    foreach (array_values($items) as $idx => $row) echo $this->render_history_repeater_item($key, (int)$idx, is_array($row) ? $row : [], $fields);
    echo '</div><p><button type="button" class="button eum-add-history-item">Add ' . esc_html($label) . ' Item</button></p>';
    echo '<template class="eum-history-template">' . $this->render_history_repeater_item($key, '__INDEX__', [], $fields) . '</template></div>';
    return ob_get_clean();
  }

  private function render_history_repeater_item(string $key, $idx, array $row, array $fields): string {
    ob_start();
    echo '<div class="eum-history-item" style="margin:0 0 12px;padding:12px;border:1px solid #e2e4e7;border-radius:6px;background:#f8f9fa;">';
    foreach ($fields as $field_key => $field_label) {
      $is_long = in_array($field_key, ['responsibilities', 'details'], true);
      $name = 'eum_hist[' . $key . '][' . $idx . '][' . $field_key . ']';
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html($field_label) . '</label>';
      if ($is_long) echo '<textarea name="' . esc_attr($name) . '" style="width:100%;min-height:80px;">' . esc_textarea((string)($row[$field_key] ?? '')) . '</textarea>';
      else echo '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr((string)($row[$field_key] ?? '')) . '" style="width:100%;max-width:520px;">';
      echo '</p>';
    }
    echo '<p><button type="button" class="button-link-delete eum-remove-history-item">Remove Item</button></p></div>';
    return ob_get_clean();
  }

  private function render_history_repeater_script(): string {
    return '<script>(function(){document.querySelectorAll(".eum-history-repeater").forEach(function(box){if(box.dataset.bound){return;}box.dataset.bound="1";var btn=box.querySelector(".eum-add-history-item"),list=box.querySelector(".eum-history-items"),tpl=box.querySelector(".eum-history-template");if(btn&&list&&tpl){btn.addEventListener("click",function(){var idx=list.querySelectorAll(".eum-history-item").length;list.insertAdjacentHTML("beforeend", tpl.innerHTML.replace(/__INDEX__/g, idx));});}box.addEventListener("click",function(e){if(e.target&&e.target.classList.contains("eum-remove-history-item")){e.preventDefault();var item=e.target.closest(".eum-history-item");if(item){item.remove();}}});});})();</script>';
  }

  private function actor_is_hiring_manager_for_candidate(int $actor_user_id, int $candidate_id): bool {
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    return $this->actor_can_manage_job_term($actor_user_id, (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true));
  }

  private function actor_is_current_manager_for_candidate(int $actor_user_id, int $candidate_id): bool {
    $internal_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
    if ($internal_user_id <= 0) return false;
    return $this->actor_can_manage_job_term($actor_user_id, (int)EUM_Profiles::get_field_raw_value($internal_user_id, 'tax:eum_job_path'));
  }

  private function candidate_effective_job_term_id(int $candidate_id): int {
    if ($candidate_id <= 0) return 0;
    $profile = get_post_meta($candidate_id, self::CAND_META_PROFILE, true);
    $profile = is_array($profile) ? $profile : [];
    $profile_term_id = (int)($profile['tax:eum_job_path'] ?? 0);
    if ($profile_term_id > 0 && term_exists($profile_term_id, 'eum_job_path')) return $profile_term_id;
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_term_id = $job_id > 0 ? (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true) : 0;
    return ($job_term_id > 0 && term_exists($job_term_id, 'eum_job_path')) ? $job_term_id : 0;
  }

  private function candidate_projected_username_from_profile(array $profile, int $job_term_id = 0, int $linked_user_id = 0, string $fallback_full_name = ''): string {
    $username_basis = '';
    if ($linked_user_id > 0) {
      $linked_user = get_user_by('id', $linked_user_id);
      if ($linked_user) $username_basis = (string)$linked_user->user_login;
    }

    if ($username_basis === '') {
      $first_name = sanitize_text_field((string)($profile['core:first_name'] ?? ''));
      $last_name = sanitize_text_field((string)($profile['core:last_name'] ?? ''));
      $full_name = trim($first_name . ' ' . $last_name);
      if ($full_name === '') $full_name = sanitize_text_field($fallback_full_name);
      $username_basis = $this->generate_username_from_name($full_name);
    }

    $username_basis = sanitize_user($username_basis, true);
    if ($username_basis === '' || $linked_user_id > 0 || $job_term_id <= 0 || !class_exists('EUM_Company_Divisions')) return $username_basis;

    $path_division = $this->candidate_job_path_division_details($job_term_id);
    $division_key = (string)($path_division['division_key'] ?? '');
    if ($division_key === '') $division_key = $this->resolved_company_division_for_profile($profile, $job_term_id);
    if ($division_key === '') return $username_basis;

    return $this->next_available_username_for_division($username_basis, $division_key);
  }

  private function candidate_job_path_division_details(int $job_term_id): array {
    $job_term_id = max(0, $job_term_id);
    if ($job_term_id <= 0 || !class_exists('EUM_Company_Divisions')) {
      return [
        'division_key' => '',
        'division_label' => '',
        'division_domain' => '',
      ];
    }

    $division_key = EUM_Company_Divisions::normalize_division_key(EUM_Company_Divisions::effective_division_for_job_path($job_term_id));
    return [
      'division_key' => $division_key,
      'division_label' => EUM_Company_Divisions::division_label($division_key),
      'division_domain' => EUM_Company_Divisions::division_domain($division_key),
    ];
  }

  private function candidate_division_key_for_profile(array $profile, int $job_term_id = 0, int $linked_user_id = 0, string $fallback_full_name = ''): string {
    $projection = $this->candidate_projection_for_profile($profile, $job_term_id, $linked_user_id, $fallback_full_name);
    if (!class_exists('EUM_Company_Divisions')) return sanitize_title((string)($projection['division_key'] ?? ''));
    return EUM_Company_Divisions::normalize_division_key((string)($projection['division_key'] ?? ''));
  }

  private function candidate_projection_for_profile(array $profile, int $job_term_id = 0, int $linked_user_id = 0, string $fallback_full_name = ''): array {
    $job_term_id = $job_term_id > 0 ? $job_term_id : (int)($profile['tax:eum_job_path'] ?? 0);
    $path_division = $this->candidate_job_path_division_details($job_term_id);
    $division_key = (string)($path_division['division_key'] ?? '');
    if ($division_key === '') {
      $division_key = class_exists('EUM_Company_Divisions')
        ? $this->resolved_company_division_for_profile($profile, $job_term_id)
        : sanitize_title((string)($profile['core:job_company'] ?? ''));
    }
    $job_path_label = $job_term_id > 0 ? $this->term_full_path('eum_job_path', $job_term_id, ' / ') : '';
    $job_title_label = class_exists('EUM_Profiles')
      ? EUM_Profiles::job_title_label_from_value((string)($profile['custom:job_title'] ?? ''))
      : sanitize_text_field((string)($profile['custom:job_title'] ?? ''));
    $division_label = (string)($path_division['division_label'] ?? '');
    if ($division_label === '') {
      $division_label = class_exists('EUM_Company_Divisions')
        ? EUM_Company_Divisions::division_label($division_key)
        : ($division_key !== '' ? ucwords(str_replace(['-', '_'], ' ', $division_key)) : '');
    }
    $division_domain = (string)($path_division['division_domain'] ?? '');
    if ($division_domain === '' && class_exists('EUM_Company_Divisions')) {
      $division_domain = EUM_Company_Divisions::division_domain($division_key);
    }
    $company_email = sanitize_email((string)($profile['core:user_email'] ?? ''));
    $username_basis = $this->candidate_projected_username_from_profile($profile, $job_term_id, $linked_user_id, $fallback_full_name);

    if ($username_basis !== '' && $job_term_id > 0 && class_exists('EUM_Company_Divisions')) {
      $derived = EUM_Company_Divisions::derive_transfer_values($username_basis, $job_term_id, $division_key);
      $division_key = (string)($derived['division'] ?? $division_key);
      $division_label = EUM_Company_Divisions::division_label($division_key);
      $division_domain = (string)($derived['domain'] ?? $division_domain);
      $company_email = sanitize_email((string)($derived['email'] ?? $company_email));
    }

    return [
      'job_term_id' => $job_term_id,
      'job_path_label' => $job_path_label,
      'job_title_label' => $job_title_label,
      'division_key' => $division_key,
      'division_label' => $division_label,
      'division_domain' => $division_domain,
      'company_email' => $company_email,
      'username_basis' => $username_basis,
    ];
  }

  private function apply_candidate_conditional_job_path_logic(array $profile, int $candidate_id = 0, int $linked_user_id = 0, int $fallback_job_term_id = 0): array {
    if (!class_exists('EUM_Profiles')) return $profile;

    $job_term_id = (int)($profile['tax:eum_job_path'] ?? 0);
    if ($job_term_id <= 0) $job_term_id = $fallback_job_term_id;
    if ($job_term_id <= 0 && $candidate_id > 0) $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    if ($job_term_id > 0) $profile['tax:eum_job_path'] = $job_term_id;
    $manual_division = class_exists('EUM_Company_Divisions')
      ? EUM_Company_Divisions::normalize_division_key((string)($profile['core:job_company'] ?? ''))
      : sanitize_title((string)($profile['core:job_company'] ?? ''));

    if (class_exists('EUM_Taxonomies')) {
      $mapping = [];
      $location_term_id = (int)($profile['tax:eum_location'] ?? 0);
      if ($location_term_id > 0) $mapping = array_merge($mapping, EUM_Taxonomies::effective_mapping_for_term('eum_location', $location_term_id));
      if ($job_term_id > 0) $mapping = array_merge($mapping, EUM_Taxonomies::effective_mapping_for_term('eum_job_path', $job_term_id));
      if (!empty($mapping)) {
        $catalog = EUM_Profiles::field_catalog();
        foreach ($mapping as $raw_key => $value) {
          $field_id = EUM_Taxonomies::mapping_key_to_log_field_id((string)$raw_key);
          if (!isset($catalog[$field_id])) continue;
          $normalized = EUM_Profiles::normalize_field_input($field_id, $value);
          if ($normalized !== null) $profile[$field_id] = $normalized;
        }
      }
    }

    if ($job_term_id > 0 && class_exists('EUM_Company_Divisions')) {
      $fallback_full_name = $candidate_id > 0 ? (string)get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true) : '';
      $projection = $this->candidate_projection_for_profile($profile, $job_term_id, $linked_user_id, $fallback_full_name);
      $resolved_division = (string)($projection['division_key'] ?? $this->resolved_company_division_for_profile($profile, $job_term_id, $manual_division));
      if ($resolved_division !== '') $profile['core:job_company'] = $resolved_division;
      else unset($profile['core:job_company']);
      if (!empty($projection['company_email'])) $profile['core:user_email'] = (string)$projection['company_email'];
      else unset($profile['core:user_email']);
    }

    return $profile;
  }

  private function mapped_company_division_for_profile(array $profile, int $job_term_id): string {
    if (!class_exists('EUM_Taxonomies')) return '';

    $mapping = [];
    $location_term_id = (int)($profile['tax:eum_location'] ?? 0);
    if ($location_term_id > 0) $mapping = array_merge($mapping, EUM_Taxonomies::effective_mapping_for_term('eum_location', $location_term_id));
    if ($job_term_id > 0) $mapping = array_merge($mapping, EUM_Taxonomies::effective_mapping_for_term('eum_job_path', $job_term_id));

    foreach ($mapping as $raw_key => $value) {
      if (EUM_Taxonomies::normalize_mapping_key((string)$raw_key) !== 'job_company') continue;
      return class_exists('EUM_Company_Divisions')
        ? EUM_Company_Divisions::normalize_division_key((string)$value)
        : sanitize_title((string)$value);
    }

    return '';
  }

  private function resolved_company_division_for_profile(array $profile, int $job_term_id, string $preferred_division = ''): string {
    if (!class_exists('EUM_Company_Divisions')) return sanitize_title($preferred_division !== '' ? $preferred_division : (string)($profile['core:job_company'] ?? ''));

    $division = EUM_Company_Divisions::normalize_division_key($preferred_division !== '' ? $preferred_division : (string)($profile['core:job_company'] ?? ''));
    if ($division === '') $division = $this->mapped_company_division_for_profile($profile, $job_term_id);
    return EUM_Company_Divisions::normalize_division_key(EUM_Company_Divisions::resolve_division_for_job_path($job_term_id, $division));
  }

  private function candidate_summary_from_profile(array $profile, array $fallback = []): array {
    $first_name = sanitize_text_field((string)($profile['core:first_name'] ?? ''));
    $last_name = sanitize_text_field((string)($profile['core:last_name'] ?? ''));
    $full_name = trim($first_name . ' ' . $last_name);
    if ($full_name === '') $full_name = sanitize_text_field((string)($fallback['full_name'] ?? 'Candidate'));
    $email = sanitize_email((string)($profile['custom:personal_email'] ?? ($fallback['email'] ?? '')));
    $phone = sanitize_text_field((string)($profile['custom:mobile_phone'] ?? ($fallback['phone'] ?? '')));
    return [$full_name, $email, $phone];
  }

  private function maybe_sync_internal_transfer_request(int $candidate_id, int $actor_user_id, bool $force_select = false): void {
    $source = (string)get_post_meta($candidate_id, self::CAND_META_SOURCE, true);
    $internal_user_id = (int)get_post_meta($candidate_id, self::CAND_META_USER_ID, true);
    if ($source !== 'internal' || $internal_user_id <= 0 || !class_exists('EUM_Profiles')) return;
    if (!empty(get_user_meta($internal_user_id, 'eum_terminated', true))) return;

    if ($force_select) update_post_meta($candidate_id, self::CAND_META_STATUS, 'selected');

    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_term_id = $this->candidate_effective_job_term_id($candidate_id);
    $profile = (array)get_post_meta($candidate_id, self::CAND_META_PROFILE, true);
    $profile = $this->apply_candidate_conditional_job_path_logic($profile, $candidate_id, $internal_user_id, $job_term_id);
    $new_manager_user_id = $this->job_owner_user_id($job_id);
    $after = array_merge($profile, ['tax:eum_job_path' => $job_term_id]);
    if ($new_manager_user_id > 0) $after['core:eum_manager_user_id'] = $new_manager_user_id;

    $change_id = (int)get_post_meta($candidate_id, self::CAND_META_TRANSFER_CHANGE_ID, true);
    if ($change_id > 0) {
      $existing = get_post($change_id);
      $existing_status = $existing ? (string)get_post_meta($change_id, EUM_Profiles::META_STATUS, true) : '';
      if (!$existing || $existing->post_type !== EUM_Profiles::CPT_CHANGE || !in_array($existing_status, ['pending', 'scheduled', 'applied'], true)) {
        $change_id = 0;
      }
    }

    $incoming_manager_date = $this->parse_datetime_local((string)get_post_meta($candidate_id, self::CAND_META_HIRING_DATE, true));
    $current_manager_date = $this->parse_datetime_local((string)get_post_meta($candidate_id, self::CAND_META_CURRENT_DATE, true));
    $requested_effective_at = $current_manager_date !== '' ? $current_manager_date : $incoming_manager_date;
    if ($requested_effective_at === '' && $change_id > 0) $requested_effective_at = (string)get_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, true);

    $transfer_effective = EUM_Profiles::normalize_transfer_effective_at_for_save($actor_user_id, $internal_user_id, $requested_effective_at, $after, $change_id);
    if (!empty($transfer_effective['error'])) return;
    $effective_at = (string)$transfer_effective['effective_at'];
    $transfer_context = EUM_Profiles::transfer_protocol_context($internal_user_id, $after, $change_id);
    $requires_approval = EUM_Profiles::manager_change_requires_approval($actor_user_id, $internal_user_id, array_keys($after));
    $approver_ids = $requires_approval ? EUM_Profiles::approver_ids_for_actor($actor_user_id, $internal_user_id, $after, $change_id) : [];
    $status = $requires_approval ? 'pending' : 'scheduled';

    if ($change_id > 0 && get_post($change_id)) {
      update_post_meta($change_id, EUM_Profiles::META_AFTER, $after);
      update_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, $effective_at);
      update_post_meta($change_id, EUM_Profiles::META_STATUS, $status);
      update_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, $approver_ids);
      update_post_meta($change_id, EUM_Profiles::META_NOTE, 'Job Candidate Transfer');
    } else {
      $change_id = (int)wp_insert_post(['post_type' => EUM_Profiles::CPT_CHANGE, 'post_status' => 'publish', 'post_title' => 'Internal Transfer - User #' . $internal_user_id, 'post_author' => $actor_user_id]);
      update_post_meta($change_id, EUM_Profiles::META_TARGET_USER, $internal_user_id);
      update_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, $actor_user_id);
      update_post_meta($change_id, EUM_Profiles::META_BEFORE, []);
      update_post_meta($change_id, EUM_Profiles::META_AFTER, $after);
      update_post_meta($change_id, EUM_Profiles::META_NOTE, 'Job Candidate Transfer');
      update_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, $effective_at);
      update_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, current_time('mysql'));
      update_post_meta($change_id, EUM_Profiles::META_STATUS, $status);
      update_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, $approver_ids);
      update_post_meta($change_id, EUM_Profiles::META_MANUAL, 0);
      update_post_meta($candidate_id, self::CAND_META_TRANSFER_CHANGE_ID, $change_id);
    }

    update_post_meta($change_id, EUM_Profiles::META_TRANSFER_PROTOCOL, '1');
    update_post_meta($change_id, EUM_Profiles::META_TRANSFER_OLD_MANAGER, (int)($transfer_context['old_manager_user_id'] ?? 0));
    update_post_meta($change_id, EUM_Profiles::META_TRANSFER_NEW_MANAGER, (int)($transfer_context['new_manager_user_id'] ?? 0));
    if (!empty($transfer_effective['proposed_max_at'])) update_post_meta($change_id, EUM_Profiles::META_TRANSFER_PROPOSED_MAX_AT, (string)$transfer_effective['proposed_max_at']);

    $change_status = (string)get_post_meta($change_id, EUM_Profiles::META_STATUS, true);
    update_post_meta($candidate_id, self::CAND_META_STATUS, $change_status === 'applied' ? 'hired' : 'selected');
  }

  public function handle_profile_change_applied(int $change_id, int $target_user_id): void {
    $change_id = (int)$change_id;
    if ($change_id <= 0) return;
    $candidate_ids = get_posts([
      'post_type' => self::CPT_CANDIDATE,
      'post_status' => 'publish',
      'numberposts' => 20,
      'fields' => 'ids',
      'meta_key' => self::CAND_META_TRANSFER_CHANGE_ID,
      'meta_value' => $change_id,
    ]);
    foreach ((array)$candidate_ids as $candidate_id) {
      update_post_meta((int)$candidate_id, self::CAND_META_STATUS, 'hired');
    }
  }

  private function ensure_internal_rehire_change(int $candidate_id, int $actor_user_id, int $internal_user_id, int $job_term_id, array $profile): int {
    $existing = (int)get_post_meta($candidate_id, self::CAND_META_REHIRE_CHANGE_ID, true);
    if ($existing > 0 && get_post($existing)) return $existing;
    $after = array_merge($profile, ['tax:eum_job_path' => $job_term_id, 'system:eum_terminated' => 0]);
    $change_id = (int)wp_insert_post(['post_type' => EUM_Profiles::CPT_CHANGE, 'post_status' => 'publish', 'post_title' => 'Rehire Candidate - User #' . $internal_user_id, 'post_author' => $actor_user_id]);
    update_post_meta($change_id, EUM_Profiles::META_TARGET_USER, $internal_user_id);
    update_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, $actor_user_id);
    update_post_meta($change_id, EUM_Profiles::META_BEFORE, []);
    update_post_meta($change_id, EUM_Profiles::META_AFTER, $after);
    update_post_meta($change_id, EUM_Profiles::META_NOTE, 'Job Candidate Rehire');
    update_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, current_time('mysql'));
    update_post_meta($change_id, EUM_Profiles::META_STATUS, 'pending');
    update_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, $this->it_hr_user_ids());
    update_post_meta($change_id, EUM_Profiles::META_MANUAL, 0);
    return $change_id;
  }

  private function it_hr_user_ids(): array {
    $out = [];
    foreach (get_users(['number' => 500]) as $u) if (EUM_Access::is_it_or_hr((int)$u->ID)) $out[] = (int)$u->ID;
    return array_values(array_unique($out));
  }

  private function apply_internal_candidate_change(int $user_id, int $job_term_id, array $profile, bool $rehire = false): void {
    $after = array_merge($profile, ['tax:eum_job_path' => $job_term_id]);
    if ($rehire) $after['system:eum_terminated'] = 0;
    if (class_exists('EUM_Company_Divisions')) {
      $u = get_user_by('id', $user_id);
      $derived = EUM_Company_Divisions::derive_transfer_values($u ? $u->user_login : '', $job_term_id, (string)($after['core:job_company'] ?? ''));
      if ($derived['division'] !== '') $after['core:job_company'] = $derived['division'];
      if ($derived['email'] !== '') $after['core:user_email'] = $derived['email'];
    }
    $catalog = EUM_Profiles::field_catalog();
    $custom_values = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    foreach ($after as $field_id => $value) {
      if ($field_id === 'system:eum_terminated') { update_user_meta($user_id, 'eum_terminated', !empty($value) ? 1 : 0); continue; }
      if (!isset($catalog[$field_id])) continue;
      $def = $catalog[$field_id];
      if (str_starts_with($field_id, 'core:')) {
        $key = substr($field_id, 5);
        if (in_array($key, ['first_name','last_name'], true)) update_user_meta($user_id, $key, sanitize_text_field((string)$value));
        elseif ($key === 'display_name') wp_update_user(['ID' => $user_id, 'display_name' => sanitize_text_field((string)$value)]);
        elseif ($key === 'user_email' && (string)$value !== '') wp_update_user(['ID' => $user_id, 'user_email' => sanitize_email((string)$value)]);
        elseif ($key === 'job_company') {
          if (class_exists('EUM_Company_Divisions')) EUM_Company_Divisions::save_user_division_input($user_id, (string)$value, true, $job_term_id);
          else update_user_meta($user_id, 'job_company', sanitize_text_field((string)$value));
        }
      } elseif (($def['type'] ?? '') === 'taxonomy_single') wp_set_object_terms($user_id, [(int)$value], (string)$def['taxonomy'], false);
      elseif (($def['type'] ?? '') === 'taxonomy_multi') wp_set_object_terms($user_id, array_map('intval', (array)$value), (string)$def['taxonomy'], false);
      elseif (($def['type'] ?? '') === 'user_select') update_user_meta($user_id, substr($field_id, 5), (int)$value);
      elseif (str_starts_with($field_id, 'custom:')) $custom_values[substr($field_id, 7)] = $value;
    }
    if (class_exists('EUM_Profiles')) EUM_Profiles::store_candidate_extended_profile_for_user($user_id, $after);
    if (class_exists('EUM_Fields')) update_user_meta($user_id, EUM_Fields::USER_META_KEY, $custom_values);

    if (class_exists('EUM_Taxonomies')) {
      EUM_Taxonomies::sync_user_classification_effects($user_id, 0, $rehire ? 'Internal Rehire Classification Sync' : 'Internal Transfer Classification Sync');
    } elseif (class_exists('EUM_Assignments')) {
      $syncer = new EUM_Assignments();
      if (method_exists($syncer, 'sync_user')) $syncer->sync_user($user_id);
    }
  }


  private function delete_candidate_related_records(int $candidate_id): void {
    $keep_application_log = false;
    $hire_id = (int)get_post_meta($candidate_id, self::CAND_META_HIRE_ID, true);
    if ($hire_id > 0 && class_exists('EUM_User_Creation')) {
      $hire_status = (string)get_post_meta($hire_id, EUM_User_Creation::META_STATUS, true);
      $created_user_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_CREATED_USER, true);
      $keep_application_log = $hire_status === 'applied' || $created_user_id > 0;
      if (!in_array($hire_status, ['applied', 'cancelled'], true)) wp_delete_post($hire_id, true);
    }

    foreach ([self::CAND_META_TRANSFER_CHANGE_ID, self::CAND_META_REHIRE_CHANGE_ID] as $meta_key) {
      $change_id = (int)get_post_meta($candidate_id, $meta_key, true);
      if ($change_id <= 0) continue;
      $change_status = class_exists('EUM_Profiles') ? (string)get_post_meta($change_id, EUM_Profiles::META_STATUS, true) : '';
      if (in_array($change_status, ['pending', 'scheduled'], true)) wp_delete_post($change_id, true);
    }

    if (class_exists('EUM_Profiles') && !$keep_application_log) {
      foreach (self::candidate_application_history_ids($candidate_id) as $application_change_id) {
        $application_status = (string)get_post_meta($application_change_id, EUM_Profiles::META_STATUS, true);
        if ($application_status === 'candidate') wp_delete_post($application_change_id, true);
      }
    }
  }

  public static function ensure_candidate_application_log(int $candidate_id, int $target_user_id, int $actor_user_id = 0, string $note = 'Candidate Application'): int {
    if ($candidate_id <= 0 || $target_user_id <= 0 || !class_exists('EUM_Profiles')) return 0;

    $settings = EUM_Profiles::get_settings();
    if (empty($settings['logging_enabled'])) return 0;

    $note = sanitize_text_field($note);
    if ($note === '') $note = 'Candidate Application';
    $existing = self::candidate_application_change_id_for_user($candidate_id, $target_user_id, $note);
    $job_id = (int)get_post_meta($candidate_id, self::CAND_META_JOB_ID, true);
    $job_term_id = $job_id > 0 ? (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true) : 0;
    $full_name = (string)get_post_meta($candidate_id, self::CAND_META_FULL_NAME, true);
    $profile = get_post_meta($candidate_id, self::CAND_META_PROFILE, true);
    $profile = is_array($profile) ? $profile : [];
    if ($job_term_id > 0 && empty($profile['tax:eum_job_path'])) $profile['tax:eum_job_path'] = $job_term_id;

    $occurred_at = (string)get_post_meta($candidate_id, self::CAND_META_APPLIED_AT, true);
    if ($occurred_at === '') {
      $candidate = get_post($candidate_id);
      if ($candidate && !empty($candidate->post_date) && $candidate->post_date !== '0000-00-00 00:00:00') $occurred_at = (string)$candidate->post_date;
    }
    if ($occurred_at === '') $occurred_at = current_time('mysql');

    $log_actor_user_id = $actor_user_id > 0 ? $actor_user_id : $target_user_id;
    $post_title = $note . ' - ' . ($full_name !== '' ? $full_name : ('User #' . $target_user_id));

    if ($existing > 0 && get_post($existing)) {
      wp_update_post([
        'ID' => $existing,
        'post_title' => $post_title,
        'post_author' => $log_actor_user_id,
      ]);
      update_post_meta($existing, EUM_Profiles::META_TARGET_USER, $target_user_id);
      update_post_meta($existing, EUM_Profiles::META_ACTOR_USER, $log_actor_user_id);
      update_post_meta($existing, EUM_Profiles::META_AFTER, $profile);
      update_post_meta($existing, EUM_Profiles::META_NOTE, $note);
      update_post_meta($existing, EUM_Profiles::META_EFFECTIVE_AT, $occurred_at);
      update_post_meta($existing, EUM_Profiles::META_OCCURRED_AT, $occurred_at);
      update_post_meta($existing, EUM_Profiles::META_STATUS, 'candidate');
      self::append_candidate_application_history_id($candidate_id, $existing);
      return $existing;
    }

    $change_id = (int)wp_insert_post([
      'post_type' => EUM_Profiles::CPT_CHANGE,
      'post_status' => 'publish',
      'post_title' => $post_title,
      'post_author' => $log_actor_user_id,
    ]);
    if ($change_id <= 0) return 0;

    update_post_meta($change_id, EUM_Profiles::META_TARGET_USER, $target_user_id);
    update_post_meta($change_id, EUM_Profiles::META_ACTOR_USER, $log_actor_user_id);
    update_post_meta($change_id, EUM_Profiles::META_APPROVER_IDS, []);
    update_post_meta($change_id, EUM_Profiles::META_BEFORE, []);
    update_post_meta($change_id, EUM_Profiles::META_AFTER, $profile);
    update_post_meta($change_id, EUM_Profiles::META_NOTE, $note);
    update_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, $occurred_at);
    update_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, $occurred_at);
    update_post_meta($change_id, EUM_Profiles::META_STATUS, 'candidate');
    update_post_meta($change_id, EUM_Profiles::META_MANUAL, 0);
    self::append_candidate_application_history_id($candidate_id, $change_id);

    return $change_id;
  }

  private static function candidate_application_history_ids(int $candidate_id): array {
    $ids = get_post_meta($candidate_id, self::CAND_META_APPLICATION_HISTORY_IDS, true);
    $ids = is_array($ids) ? $ids : [];
    $legacy_id = (int)get_post_meta($candidate_id, self::CAND_META_APPLICATION_CHANGE_ID, true);
    if ($legacy_id > 0) $ids[] = $legacy_id;
    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn($change_id) => $change_id > 0)));
  }

  private static function append_candidate_application_history_id(int $candidate_id, int $change_id): void {
    if ($candidate_id <= 0 || $change_id <= 0) return;
    $ids = self::candidate_application_history_ids($candidate_id);
    if (!in_array($change_id, $ids, true)) $ids[] = $change_id;
    update_post_meta($candidate_id, self::CAND_META_APPLICATION_HISTORY_IDS, $ids);
    $primary_id = (int)get_post_meta($candidate_id, self::CAND_META_APPLICATION_CHANGE_ID, true);
    if ($primary_id <= 0) update_post_meta($candidate_id, self::CAND_META_APPLICATION_CHANGE_ID, $change_id);
  }

  private static function candidate_application_change_id_for_user(int $candidate_id, int $target_user_id, string $note): int {
    if ($candidate_id <= 0 || $target_user_id <= 0 || !class_exists('EUM_Profiles')) return 0;
    $normalized_note = sanitize_text_field($note);
    foreach (self::candidate_application_history_ids($candidate_id) as $change_id) {
      $change = get_post($change_id);
      if (!$change || $change->post_type !== EUM_Profiles::CPT_CHANGE) continue;
      if ((int)get_post_meta($change_id, EUM_Profiles::META_TARGET_USER, true) !== $target_user_id) continue;
      if (sanitize_text_field((string)get_post_meta($change_id, EUM_Profiles::META_NOTE, true)) !== $normalized_note) continue;
      return $change_id;
    }
    return 0;
  }

  private function render_candidate_application_history(int $candidate_id): string {
    if ($candidate_id <= 0 || !class_exists('EUM_Profiles')) return '';
    $rows = [];
    foreach (self::candidate_application_history_ids($candidate_id) as $change_id) {
      $change = get_post($change_id);
      if (!$change || $change->post_type !== EUM_Profiles::CPT_CHANGE) continue;
      $rows[] = [
        'note' => sanitize_text_field((string)get_post_meta($change_id, EUM_Profiles::META_NOTE, true)),
        'status' => sanitize_key((string)get_post_meta($change_id, EUM_Profiles::META_STATUS, true)),
        'target_user_id' => (int)get_post_meta($change_id, EUM_Profiles::META_TARGET_USER, true),
        'occurred_at' => (string)get_post_meta($change_id, EUM_Profiles::META_OCCURRED_AT, true),
      ];
    }
    if (empty($rows)) return '';
    usort($rows, static function($left, $right): int {
      return strcmp((string)$right['occurred_at'], (string)$left['occurred_at']);
    });
    ob_start();
    echo '<div style="margin:0 0 18px;padding:14px;border:1px solid #d9e2ec;border-radius:8px;background:#fff;">';
    echo '<h4 style="margin:0 0 8px;">Application History</h4>';
    foreach ($rows as $index => $row) {
      $status = $row['status'] !== '' ? ucfirst(str_replace('_', ' ', (string)$row['status'])) : 'Candidate';
      $occurred_at = (string)$row['occurred_at'] !== '' ? (string)$row['occurred_at'] : current_time('mysql');
      $meta_parts = array_filter([
        $this->display_user_label((int)$row['target_user_id']),
        $occurred_at,
        $status,
      ], static fn($value) => trim((string)$value) !== '');
      echo '<div style="padding:8px 0;' . ($index > 0 ? 'border-top:1px solid #edf2f7;' : '') . '">';
      echo '<strong>' . esc_html((string)$row['note']) . '</strong>';
      echo '<div class="description">' . esc_html(implode(' | ', $meta_parts)) . '</div>';
      echo '</div>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_candidate_signup_redirect_history(int $candidate_id): string {
    $history = get_post_meta($candidate_id, self::CAND_META_SIGNUP_REDIRECT_HISTORY, true);
    if (!is_array($history) || empty($history)) return '';
    $history = array_reverse($history);
    ob_start();
    echo '<div style="margin:0 0 18px;padding:14px;border:1px solid #d9e2ec;border-radius:8px;background:#fff;">';
    echo '<h4 style="margin:0 0 8px;">Signup Redirect Change History</h4>';
    foreach ($history as $index => $entry) {
      if (!is_array($entry)) continue;
      $before = (string)($entry['before'] ?? '');
      $after = (string)($entry['after'] ?? '');
      $actor_user_id = (int)($entry['actor_user_id'] ?? 0);
      $occurred_at = sanitize_text_field((string)($entry['occurred_at'] ?? ''));
      $reason = sanitize_text_field((string)($entry['reason'] ?? ''));
      echo '<div style="padding:8px 0;' . ($index > 0 ? 'border-top:1px solid #edf2f7;' : '') . '">';
      echo '<strong>' . esc_html($reason !== '' ? $reason : 'Redirect changed') . '</strong>';
      echo '<div class="description">' . esc_html($this->display_user_label($actor_user_id) . ' | ' . ($occurred_at !== '' ? $occurred_at : 'Time not recorded')) . '</div>';
      echo '<div class="description">From: ' . esc_html($before !== '' ? $before : 'Site homepage (default)') . '<br>To: ' . esc_html($after !== '' ? $after : 'Site homepage (default)') . '</div>';
      echo '</div>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  public function handle_user_email_candidate_matches(int $user_id): void {
    try {
      $user_id = (int)$user_id;
      if ($user_id <= 0) return;
      $this->sync_candidate_application_logs_for_user($user_id, $user_id);
    } catch (\Throwable $e) {
      $this->log_job_board_runtime_issue('handle_user_email_candidate_matches', $e);
    }
  }

  private function sync_candidate_application_logs_for_user(int $user_id, int $actor_user_id = 0): int {
    $matched = 0;
    foreach ($this->candidate_ids_matching_user_email($user_id) as $candidate_id) {
      if (self::ensure_candidate_application_log($candidate_id, $user_id, $actor_user_id, 'Candidate Application Email Match') > 0) {
        $matched++;
      }
    }
    return $matched;
  }

  private function sync_candidate_email_match_history(int $candidate_id, int $actor_user_id = 0): int {
    $matched = 0;
    foreach ($this->user_ids_matching_candidate_email($candidate_id) as $user_id) {
      if (self::ensure_candidate_application_log($candidate_id, $user_id, $actor_user_id, 'Candidate Application Email Match') > 0) {
        $matched++;
      }
    }
    return $matched;
  }

  private function candidate_ids_matching_user_email(int $user_id): array {
    $emails = $this->user_emails_for_candidate_matching($user_id);
    if (empty($emails)) return [];
    $candidate_ids = get_posts([
      'post_type' => self::CPT_CANDIDATE,
      'post_status' => 'publish',
      'numberposts' => 1000,
      'fields' => 'ids',
    ]);
    $out = [];
    foreach ((array)$candidate_ids as $candidate_id) {
      $candidate_email = strtolower(sanitize_email((string)get_post_meta((int)$candidate_id, self::CAND_META_EMAIL, true)));
      if ($candidate_email !== '' && in_array($candidate_email, $emails, true)) $out[] = (int)$candidate_id;
    }
    return array_values(array_unique($out));
  }

  private function user_ids_matching_candidate_email(int $candidate_id): array {
    $candidate_email = strtolower(sanitize_email((string)get_post_meta($candidate_id, self::CAND_META_EMAIL, true)));
    if ($candidate_email === '') return [];
    $out = [];

    $work_email_user = get_user_by('email', $candidate_email);
    if ($work_email_user instanceof WP_User) {
      $out[] = (int)$work_email_user->ID;
    }

    if (class_exists('EUM_Fields')) {
      $meta_candidates = get_users([
        'number' => 25,
        'meta_query' => [
          [
            'key' => EUM_Fields::USER_META_KEY,
            'value' => $candidate_email,
            'compare' => 'LIKE',
          ],
        ],
      ]);
      foreach ((array)$meta_candidates as $user) {
        if (!$user instanceof WP_User) continue;
        $user_id = (int)$user->ID;
        if ($user_id <= 0) continue;
        if (in_array($candidate_email, $this->user_emails_for_candidate_matching($user_id, $user), true)) {
          $out[] = $user_id;
        }
      }
    }

    return array_values(array_unique($out));
  }

  private function user_emails_for_candidate_matching(int $user_id, $user = null): array {
    // `get_users( [ 'fields' => [...] ] )` returns stdClass rows, not WP_User objects.
    if (!$user || !is_object($user)) {
      $user = get_user_by('id', $user_id);
    }
    if (!$user || !is_object($user)) return [];
    $emails = [];
    $work_email = strtolower(sanitize_email((string)($user->user_email ?? '')));
    if ($work_email !== '') $emails[] = $work_email;
    if (class_exists('EUM_Profiles')) {
      $personal_email = strtolower(sanitize_email((string)EUM_Profiles::get_field_raw_value($user_id, 'custom:personal_email')));
      if ($personal_email !== '') $emails[] = $personal_email;
    }
    return array_values(array_unique($emails));
  }

  private function job_owner_user_id(int $job_id): int {
    $owner_user_id = (int)get_post_meta($job_id, self::JOB_META_OWNER_UID, true);
    if ($owner_user_id > 0 && get_user_by('id', $owner_user_id)) return $owner_user_id;

    $job = get_post($job_id);
    return $job ? (int)$job->post_author : 0;
  }

  private function job_owner_user_options(int $selected_user_id = 0, int $include_user_id = 0): array {
    $users = get_users([
      'fields' => ['ID', 'display_name', 'user_login', 'user_email'],
      'number' => 2000,
      'orderby' => 'display_name',
      'order' => 'ASC',
    ]);
    if (!is_array($users)) $users = [];

    $indexed = [];
    foreach ($users as $user) {
      $indexed[(int)$user->ID] = $user;
    }
    foreach ([$selected_user_id, $include_user_id] as $user_id) {
      $user_id = (int)$user_id;
      if ($user_id > 0 && !isset($indexed[$user_id])) {
        $user = get_user_by('id', $user_id);
        if ($user) $indexed[$user_id] = $user;
      }
    }

    uasort($indexed, function($a, $b) {
      $label_a = strtolower((string)($a->display_name ?: $a->user_login));
      $label_b = strtolower((string)($b->display_name ?: $b->user_login));
      return $label_a <=> $label_b;
    });

    return array_values($indexed);
  }

  private function candidate_internal_user_options(int $selected_user_id = 0): array {
    $users = get_users([
      'fields' => ['ID', 'display_name', 'user_login'],
      'number' => 500,
      'orderby' => 'display_name',
      'order' => 'ASC',
    ]);
    if (!is_array($users)) $users = [];

    $indexed = [];
    foreach ($users as $user) {
      $indexed[(int)$user->ID] = $user;
    }
    if ($selected_user_id > 0 && !isset($indexed[$selected_user_id])) {
      $user = get_user_by('id', $selected_user_id);
      if ($user) $indexed[$selected_user_id] = $user;
    }
    if (!empty($indexed)) {
      update_meta_cache('user', array_keys($indexed));
      uasort($indexed, function($a, $b) {
        $label_a = strtolower((string)($a->display_name ?: $a->user_login));
        $label_b = strtolower((string)($b->display_name ?: $b->user_login));
        return $label_a <=> $label_b;
      });
    }

    return array_values($indexed);
  }

  private function display_user_label(int $user_id): string {
    if ($user_id <= 0) return '—';
    $user = get_user_by('id', $user_id);
    if (!$user) return 'User #' . $user_id;
    $label = $user->display_name ?: $user->user_login;
    if ($user->user_email) $label .= ' (' . $user->user_email . ')';
    return $label;
  }

  private function owner_related_manager_ids(int $owner_user_id, int $fallback_term_id = 0): array {
    $out = [];

    if ($owner_user_id > 0) {
      $out = array_merge($out, $this->get_manager_ids_for_user($owner_user_id));
      if (class_exists('EUM_Manager_IDs')) {
        $mgr = new EUM_Manager_IDs();
        if (method_exists($mgr, 'resolve_effective_manager_id_for_user')) {
          $owner_mid = (int)$mgr->resolve_effective_manager_id_for_user($owner_user_id);
          if ($owner_mid > 0) $out[] = $owner_mid;
        }
      }
    }

    if ($fallback_term_id > 0) {
      $out = array_merge($out, $this->manager_ids_for_term($fallback_term_id));
    }

    return array_values(array_unique(array_filter(array_map('intval', $out))));
  }

  private function user_is_in_owner_chain(int $actor_user_id, int $owner_user_id): bool {
    if ($actor_user_id <= 0 || $owner_user_id <= 0) return false;
    if ($actor_user_id === $owner_user_id) return true;

    $direct_manager_user_id = (int)get_user_meta($owner_user_id, EUM_Personnel::META_MANAGER_ID, true);
    if ($direct_manager_user_id > 0 && $direct_manager_user_id === $actor_user_id) return true;

    if (!class_exists('EUM_Manager_IDs')) return false;

    $mgr = new EUM_Manager_IDs();
    if (!method_exists($mgr, 'resolve_effective_manager_id_for_user')) return false;

    $owner_mid = (int)$mgr->resolve_effective_manager_id_for_user($owner_user_id);
    if ($owner_mid <= 0) return false;

    if (method_exists($mgr, 'user_is_manager_for_id') && $mgr->user_is_manager_for_id($actor_user_id, $owner_mid)) return true;

    $owner_term = $this->get_manager_job_term_id($owner_mid);
    if ($owner_term <= 0) return false;

    $ancestor_terms = array_map('intval', (array)get_ancestors($owner_term, 'eum_job_path', 'taxonomy'));
    foreach ($this->get_manager_ids_for_user($actor_user_id) as $my_mid) {
      $my_term = $this->get_manager_job_term_id($my_mid);
      if ($my_term <= 0) continue;
      if ($my_term === $owner_term || in_array($my_term, $ancestor_terms, true)) return true;
    }

    return false;
  }

  private function get_manager_ids_for_user(int $user_id): array {
    if ($user_id <= 0 || !class_exists('EUM_Manager_IDs') || !method_exists('EUM_Manager_IDs', 'manager_ids_for_user')) return [];
    return array_values(array_filter(array_map('intval', (array)EUM_Manager_IDs::manager_ids_for_user($user_id))));
  }

  private function get_manager_job_term_id(int $manager_post_id): int {
    if ($manager_post_id <= 0 || !class_exists('EUM_Manager_IDs')) return 0;
    return (int)get_post_meta($manager_post_id, EUM_Manager_IDs::META_JOB_PATH_TERM, true);
  }

  private function application_question_allowed_types(): array {
    return class_exists('EUM_Fields') ? EUM_Fields::allowed_types() : ['text', 'textarea', 'email', 'url', 'number', 'date'];
  }

  private function job_application_fields(int $job_id): array {
    if ($job_id <= 0) return [];
    $stored = get_post_meta($job_id, self::JOB_META_APPLICATION_FIELDS, true);
    return $this->sanitize_job_application_fields(is_array($stored) ? $stored : []);
  }

  private function job_application_field_keys(array $fields): array {
    $keys = [];
    foreach ($fields as $field) {
      $key = sanitize_key((string)($field['key'] ?? ''));
      if ($key !== '') $keys[] = $key;
    }
    return $keys;
  }

  private function sanitize_job_application_fields($rows): array {
    $rows = is_array($rows) ? $rows : [];
    $allowed_types = $this->application_question_allowed_types();
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($label === '') continue;
      $key = sanitize_key((string)($row['key'] ?? ''));
      if ($key === '') $key = sanitize_key(str_replace('-', '_', sanitize_title($label)));
      if ($key === '') $key = 'question';
      $base_key = $key;
      $suffix = 2;
      while (isset($seen[$key])) $key = $base_key . '_' . $suffix++;
      $type = sanitize_key((string)($row['type'] ?? 'text'));
      if (!in_array($type, $allowed_types, true)) $type = 'text';
      $out[] = [
        'key' => $key,
        'label' => $label,
        'type' => $type,
        'required' => !empty($row['required']) ? 1 : 0,
        'placeholder' => sanitize_text_field((string)($row['placeholder'] ?? '')),
        'help_text' => sanitize_textarea_field((string)($row['help_text'] ?? '')),
      ];
      $seen[$key] = true;
    }
    return $out;
  }

  private function render_job_application_fields_editor(array $fields): string {
    if (empty($fields)) $fields = [['key' => '', 'label' => '', 'type' => 'text', 'required' => 0, 'placeholder' => '', 'help_text' => '']];
    ob_start();
    echo '<div class="eum-job-application-fields" style="margin:18px 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;background:#f8f9fa;">';
    echo '<h4 style="margin:0 0 8px;">Application Questions</h4><p class="description" style="margin-top:0;">Add extra questions for this specific job. They will appear on both the internal and external application flow.</p>';
    echo '<div class="eum-job-application-fields__rows">';
    foreach (array_values($fields) as $index => $field) echo $this->render_job_application_field_editor_row($index, $field);
    echo '</div>';
    echo '<p style="margin:12px 0 0;"><button type="button" class="button" data-eum-add-application-field>Add Question</button></p>';
    echo '<script type="text/template" class="eum-job-application-fields__template">' . $this->render_job_application_field_editor_row('__index__', ['key' => '', 'label' => '', 'type' => 'text', 'required' => 0, 'placeholder' => '', 'help_text' => '']) . '</script>';
    echo '</div>';
    echo '<script>(function(){if(window.EUMJobApplicationFieldsInit)return;window.EUMJobApplicationFieldsInit=function(root){if(!root||root.dataset.eumInit==="1")return;root.dataset.eumInit="1";var list=root.querySelector(".eum-job-application-fields__rows");var addButton=root.querySelector("[data-eum-add-application-field]");var template=root.querySelector(".eum-job-application-fields__template");if(!list||!addButton||!template)return;var nextIndex=list.querySelectorAll("[data-eum-application-field-row]").length;var bindRemove=function(node){if(!node)return;var button=node.querySelector("[data-eum-remove-application-field]");if(!button)return;button.addEventListener("click",function(){node.remove();});};list.querySelectorAll("[data-eum-application-field-row]").forEach(bindRemove);addButton.addEventListener("click",function(){var html=template.innerHTML.replace(/__index__/g,String(nextIndex++));list.insertAdjacentHTML("beforeend",html);bindRemove(list.lastElementChild);});};document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".eum-job-application-fields").forEach(function(root){window.EUMJobApplicationFieldsInit(root);});});}());</script>';
    return ob_get_clean();
  }

  private function render_job_application_field_editor_row($index, array $field): string {
    $key = sanitize_key((string)($field['key'] ?? ''));
    $label = sanitize_text_field((string)($field['label'] ?? ''));
    $type = sanitize_key((string)($field['type'] ?? 'text'));
    $placeholder = sanitize_text_field((string)($field['placeholder'] ?? ''));
    $help_text = sanitize_textarea_field((string)($field['help_text'] ?? ''));
    $required = !empty($field['required']);
    $allowed_types = $this->application_question_allowed_types();
    ob_start();
    echo '<div data-eum-application-field-row style="margin:0 0 12px;padding:14px;border:1px solid #dcdcde;border-radius:8px;background:#fff;">';
    echo '<input type="hidden" name="application_fields[' . esc_attr((string)$index) . '][key]" value="' . esc_attr($key) . '">';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Question Label</label><input type="text" name="application_fields[' . esc_attr((string)$index) . '][label]" value="' . esc_attr($label) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Field Type</label><select name="application_fields[' . esc_attr((string)$index) . '][type]" style="width:100%;">';
    foreach ($allowed_types as $allowed_type) echo '<option value="' . esc_attr($allowed_type) . '" ' . selected($type, $allowed_type, false) . '>' . esc_html(ucfirst($allowed_type)) . '</option>';
    echo '</select></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Placeholder</label><input type="text" name="application_fields[' . esc_attr((string)$index) . '][placeholder]" value="' . esc_attr($placeholder) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;display:flex;align-items:flex-end;"><label style="font-weight:600;"><input type="checkbox" name="application_fields[' . esc_attr((string)$index) . '][required]" value="1" ' . checked($required, true, false) . '> Required</label></p>';
    echo '</div>';
    echo '<p style="margin:12px 0 0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Help Text</label><textarea name="application_fields[' . esc_attr((string)$index) . '][help_text]" style="width:100%;min-height:70px;">' . esc_textarea($help_text) . '</textarea></p>';
    echo '<p class="description" style="margin:8px 0 0;">' . ($key !== '' ? 'Field ID: <code>' . esc_html($key) . '</code>' : 'Field ID will be generated from the label when you save.') . '</p>';
    echo '<p style="margin:10px 0 0;"><button type="button" class="button-link-delete" data-eum-remove-application-field>Remove Question</button></p>';
    echo '</div>';
    return ob_get_clean();
  }

  private function render_application_question_inputs(array $fields, array $values = [], string $input_root = 'eum_application_responses', bool $enforce_required = true): string {
    ob_start();
    foreach ($fields as $field) {
      $key = sanitize_key((string)($field['key'] ?? ''));
      if ($key === '') continue;
      $label = sanitize_text_field((string)($field['label'] ?? $key));
      $type = sanitize_key((string)($field['type'] ?? 'text'));
      if (!in_array($type, $this->application_question_allowed_types(), true)) $type = 'text';
      $value = isset($values[$key]) && is_scalar($values[$key]) ? (string)$values[$key] : '';
      $placeholder = sanitize_text_field((string)($field['placeholder'] ?? ''));
      $help_text = sanitize_textarea_field((string)($field['help_text'] ?? ''));
      $required = $enforce_required && !empty($field['required']);
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html($label) . ($required ? ' <span style="color:#b91c1c;">*</span>' : '') . '</label>';
      if ($type === 'textarea') {
        echo '<textarea name="' . esc_attr($input_root) . '[' . esc_attr($key) . ']" style="width:100%;min-height:90px;"' . ($required ? ' required' : '') . ' placeholder="' . esc_attr($placeholder) . '">' . esc_textarea($value) . '</textarea>';
      } else {
        $html_type = in_array($type, ['email', 'url', 'number', 'date'], true) ? $type : 'text';
        echo '<input type="' . esc_attr($html_type) . '" name="' . esc_attr($input_root) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" style="width:100%;max-width:520px;"' . ($required ? ' required' : '') . ' placeholder="' . esc_attr($placeholder) . '">';
      }
      if ($help_text !== '') echo '<span class="description" style="display:block;margin-top:6px;">' . esc_html($help_text) . '</span>';
      echo '</p>';
    }
    return ob_get_clean();
  }

  private function sanitize_candidate_application_responses(int $job_id, array $payload, bool $enforce_required = true): array {
    if ($job_id <= 0) return [];
    $fields = $this->job_application_fields($job_id);
    if (empty($fields)) return [];
    $submitted = is_array($payload['eum_application_responses'] ?? null) ? $payload['eum_application_responses'] : [];
    $responses = [];
    foreach ($fields as $field) {
      $key = sanitize_key((string)($field['key'] ?? ''));
      if ($key === '') continue;
      $raw_value = $submitted[$key] ?? '';
      $value = $this->sanitize_application_question_value((string)($field['type'] ?? 'text'), $raw_value);
      if ($enforce_required && !empty($field['required']) && $value === '') wp_die(sanitize_text_field((string)($field['label'] ?? 'This field')) . ' is required.');
      if ($value !== '') $responses[$key] = $value;
    }
    return $responses;
  }

  private function sanitize_application_question_value(string $type, $value): string {
    $type = sanitize_key($type);
    if (class_exists('EUM_Fields')) return EUM_Fields::sanitize_value($type, $value);
    $value = is_scalar($value) ? (string)$value : '';
    switch ($type) {
      case 'email':
        return sanitize_email($value);
      case 'url':
        return esc_url_raw($value);
      case 'number':
        return (string)(is_numeric($value) ? $value : '');
      case 'date':
        return preg_replace('/[^0-9\-]/', '', $value);
      case 'textarea':
        return sanitize_textarea_field($value);
      default:
        return sanitize_text_field($value);
    }
  }

  private function candidate_application_responses(int $candidate_id): array {
    $stored = get_post_meta($candidate_id, self::CAND_META_APPLICATION_RESPONSES, true);
    $stored = is_array($stored) ? $stored : [];
    $out = [];
    foreach ($stored as $key => $value) {
      $clean_key = sanitize_key((string)$key);
      if ($clean_key === '') continue;
      $out[$clean_key] = is_scalar($value) ? sanitize_textarea_field((string)$value) : '';
    }
    return $out;
  }

  private function persist_candidate_application_responses(int $candidate_id, array $responses): void {
    if ($candidate_id <= 0) return;
    if (empty($responses)) {
      delete_post_meta($candidate_id, self::CAND_META_APPLICATION_RESPONSES);
      return;
    }
    update_post_meta($candidate_id, self::CAND_META_APPLICATION_RESPONSES, $responses);
  }

  private function render_candidate_application_response_summary(array $responses): string {
    if (empty($responses)) return '';
    ob_start();
    echo '<div style="margin-top:12px;padding:14px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;"><strong>Saved Responses From Removed Questions</strong>';
    foreach ($responses as $key => $value) {
      echo '<p style="margin:10px 0 0;"><strong>' . esc_html(ucwords(str_replace('_', ' ', (string)$key))) . ':</strong><br>' . nl2br(esc_html((string)$value)) . '</p>';
    }
    echo '</div>';
    return ob_get_clean();
  }

  private function job_full_content_html(int $job_id): string {
    if ($job_id <= 0) return '';
    $description = (string)get_post_meta($job_id, self::JOB_META_DESCRIPTION, true);
    $requirements = (string)get_post_meta($job_id, self::JOB_META_REQUIREMENTS, true);
    if ($description !== '' && $requirements !== '') {
      $description_plain = trim((string)wp_strip_all_tags($description));
      $requirements_plain = trim((string)wp_strip_all_tags($requirements));
      if ($requirements_plain !== '' && strpos($description_plain, $requirements_plain) !== false) return $description;
      return $description . '<h4>Requirements</h4>' . $requirements;
    }
    return $description !== '' ? $description : $requirements;
  }

  private function job_short_description(int $job_id): string {
    if ($job_id <= 0) return '';
    $short = sanitize_textarea_field((string)get_post_meta($job_id, self::JOB_META_SHORT_DESCRIPTION, true));
    if ($short !== '') return $short;
    return wp_trim_words(trim((string)wp_strip_all_tags($this->job_full_content_html($job_id))), 42, '...');
  }

  private function normalize_public_job($job, bool $remote, bool $external): array {
    if ($remote) {
      $job_id = (int)($job['id'] ?? 0);
      $title = sanitize_text_field((string)($job['title'] ?? ''));
      return [
        'id' => $job_id,
        'title' => $title !== '' ? $title : ('Job #' . $job_id),
        'job_title' => sanitize_text_field((string)($job['job_title'] ?? '')),
        'job_path' => sanitize_text_field((string)($job['job_path'] ?? '')),
        'division' => sanitize_text_field((string)($job['division'] ?? '')),
        'short_description' => sanitize_textarea_field((string)($job['short_description'] ?? '')),
        'description' => (string)($job['description'] ?? ''),
        'requirements' => (string)($job['requirements'] ?? ''),
        'location' => sanitize_text_field((string)($job['location'] ?? '')),
        'job_image_url' => esc_url_raw((string)($job['job_image_url'] ?? '')),
        'division_image_url' => esc_url_raw((string)($job['division_image_url'] ?? '')),
        'social_image_url' => esc_url_raw((string)($job['social_image_url'] ?? '')),
        'apply_url' => esc_url_raw((string)($job['apply_url'] ?? '')),
        'application_fields' => $this->sanitize_job_application_fields($job['application_fields'] ?? []),
      ];
    }

    $job_id = (int)$job->ID;
    $title = (string)get_post_meta($job_id, self::JOB_META_NEAT_TITLE, true);
    if ($title === '' && class_exists('EUM_Profiles')) $title = EUM_Profiles::job_title_label_from_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true));
    if ($title === '') $title = get_the_title($job_id);
    $job_term_id = (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true);

    return [
      'id' => $job_id,
      'title' => $title,
      'job_title' => class_exists('EUM_Profiles') ? EUM_Profiles::job_title_label_from_value((string)get_post_meta($job_id, self::JOB_META_JOB_TITLE, true)) : '',
      'job_path' => $this->term_full_path('eum_job_path', $job_term_id, ' / '),
      'division' => ($job_term_id > 0 && class_exists('EUM_Company_Divisions'))
        ? EUM_Company_Divisions::division_label(EUM_Company_Divisions::effective_division_for_job_path($job_term_id))
        : '',
      'short_description' => $this->job_short_description($job_id),
      'description' => $this->job_full_content_html($job_id),
      'requirements' => '',
      'location' => (string)get_post_meta($job_id, self::JOB_META_LOCATION, true),
      'job_image_url' => $this->job_social_image_url($job_id),
      'division_image_url' => $this->job_division_image_url($job_term_id),
      'social_image_url' => $this->resolved_job_social_image_url($job_id, $job_term_id),
      'apply_url' => $external ? add_query_arg(['action' => 'eum_job_board_apply_api'], admin_url('admin-post.php')) : '',
      'application_fields' => $this->job_application_fields($job_id),
    ];
  }

  private function public_job_excerpt(array $job_view, int $words = 40): string {
    $excerpt = trim((string)($job_view['short_description'] ?? ''));
    if ($excerpt === '') $excerpt = trim((string)wp_strip_all_tags((string)($job_view['description'] ?? '')));
    if ($excerpt === '') $excerpt = trim((string)wp_strip_all_tags((string)($job_view['requirements'] ?? '')));
    if ($excerpt === '') $excerpt = 'No description provided.';
    return wp_trim_words($excerpt, $words, '...');
  }

  private function public_board_base_url(): string {
    return remove_query_arg(['eum_public_job', 'eum_public_view', 'eum_apply_job', 'eum_public_notice', 'eum_public_notice_type'], $this->current_url());
  }

  private function public_board_job_url(int $job_id, string $view = 'detail'): string {
    $args = ['eum_public_job' => $job_id, 'eum_public_view' => $view === 'apply' ? 'apply' : 'detail'];
    return add_query_arg($args, $this->public_board_base_url());
  }

  private function public_board_success_url(string $url): string {
    $base = $url !== '' ? $url : $this->current_url();
    $base = remove_query_arg(['eum_public_job', 'eum_public_view', 'eum_apply_job', 'eum_public_notice', 'eum_public_notice_type'], $base);
    return add_query_arg(['eum_public_notice' => 'applied', 'eum_public_notice_type' => 'success'], $base);
  }

  private function actor_can_manage_job_social_images(int $actor_user_id): bool {
    return $actor_user_id > 0 && (EUM_Access::is_it_or_hr($actor_user_id) || user_can($actor_user_id, 'manage_options'));
  }

  private function sanitize_image_attachment_id($value): int {
    $image_id = max(0, (int)$value);
    return $image_id > 0 && wp_attachment_is_image($image_id) ? $image_id : 0;
  }

  private function job_social_image_id(int $job_id): int {
    if ($job_id <= 0) return 0;
    return $this->sanitize_image_attachment_id(get_post_meta($job_id, self::JOB_META_SOCIAL_IMAGE_ID, true));
  }

  private function job_social_image_url(int $job_id, string $size = 'full'): string {
    $image_id = $this->job_social_image_id($job_id);
    if ($image_id <= 0) return '';
    $image_url = wp_get_attachment_image_url($image_id, $size);
    return is_string($image_url) ? $image_url : '';
  }

  private function job_division_image_url(int $job_term_id, string $size = 'full'): string {
    if ($job_term_id <= 0 || !class_exists('EUM_Company_Divisions')) return '';
    $division_key = EUM_Company_Divisions::effective_division_for_job_path($job_term_id);
    return EUM_Company_Divisions::division_image_url($division_key, $size);
  }

  private function resolved_job_social_image_url(int $job_id, int $job_term_id = 0, string $size = 'full'): string {
    $job_image_url = $this->job_social_image_url($job_id, $size);
    if ($job_image_url !== '') return $job_image_url;
    if ($job_term_id <= 0) $job_term_id = (int)get_post_meta($job_id, self::JOB_META_JOB_TERM, true);
    return $this->job_division_image_url($job_term_id, $size);
  }

  private function job_social_image_help_text(int $job_term_id): string {
    $message = 'Used for social previews on public job links. Leave blank to fall back to the division image.';
    if ($job_term_id > 0 && class_exists('EUM_Company_Divisions')) {
      $division_key = EUM_Company_Divisions::effective_division_for_job_path($job_term_id);
      $division_label = EUM_Company_Divisions::division_label($division_key);
      if ($division_label !== '') {
        $message = 'Used for social previews on public job links. Leave blank to use the division image for ' . $division_label . '.';
      }
    }
    return $message;
  }

  private function render_job_image_picker_html(string $field_name, int $image_id = 0, string $empty_label = 'No image selected.'): string {
    $image_url = $image_id > 0 ? wp_get_attachment_image_url($image_id, 'medium') : '';
    ob_start();
    echo '<div class="eum-job-image-picker" data-eum-job-image-picker="1">';
    echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="' . (int)$image_id . '" class="eum-job-image-picker__input">';
    echo '<div class="eum-job-image-picker__preview" style="margin-bottom:8px;">';
    if (is_string($image_url) && $image_url !== '') {
      echo '<img src="' . esc_url($image_url) . '" alt="" style="display:block;max-width:260px;width:100%;height:auto;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">';
    } else {
      echo '<div class="description eum-job-image-picker__empty">' . esc_html($empty_label) . '</div>';
    }
    echo '</div>';
    echo '<p style="margin:0;display:flex;gap:8px;flex-wrap:wrap;">';
    echo '<button type="button" class="button button-secondary eum-job-image-picker__select">Choose Image</button>';
    echo '<button type="button" class="button eum-job-image-picker__remove"' . ($image_id > 0 ? '' : ' style="display:none;"') . '>Remove Image</button>';
    echo '</p>';
    echo '</div>';
    return ob_get_clean();
  }

  private function render_job_image_picker_script(): string {
    return '<script>(function(){if(window.EUMJobImagePickerInit)return;window.EUMJobImagePickerInit=function(root){if(!root||typeof wp==="undefined"||!wp.media)return;root.querySelectorAll("[data-eum-job-image-picker]").forEach(function(picker){if(picker.dataset.eumJobImagePickerBound==="1")return;picker.dataset.eumJobImagePickerBound="1";var input=picker.querySelector(".eum-job-image-picker__input");var preview=picker.querySelector(".eum-job-image-picker__preview");var selectButton=picker.querySelector(".eum-job-image-picker__select");var removeButton=picker.querySelector(".eum-job-image-picker__remove");var emptyLabel=(preview&&preview.textContent?preview.textContent:"No image selected.").trim()||"No image selected.";var renderPreview=function(url){if(!preview)return;if(url){preview.innerHTML=\'<img src="\'+url+\'" alt="" style="display:block;max-width:260px;width:100%;height:auto;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">\';if(removeButton)removeButton.style.display="";}else{preview.innerHTML=\'<div class="description eum-job-image-picker__empty">\'+emptyLabel+\'</div>\';if(removeButton)removeButton.style.display="none";}};selectButton&&selectButton.addEventListener("click",function(event){event.preventDefault();var frame=wp.media({title:"Select job social image",button:{text:"Use this image"},multiple:false,library:{type:"image"}});frame.on("select",function(){var selection=frame.state().get("selection").first();if(!selection)return;var attachment=selection.toJSON();if(input)input.value=attachment.id||"";renderPreview(attachment.url||"");});frame.open();});removeButton&&removeButton.addEventListener("click",function(event){event.preventDefault();if(input)input.value="";renderPreview("");});});};document.addEventListener("DOMContentLoaded",function(){window.EUMJobImagePickerInit(document);});}());</script>';
  }

  private function public_board_notice_markup(): string {
    $notice = sanitize_key((string)($_GET['eum_public_notice'] ?? ''));
    if ($notice === '') return '';
    $type = sanitize_key((string)($_GET['eum_public_notice_type'] ?? 'success'));
    $message = $notice === 'applied' ? 'Application submitted successfully. Thanks for your interest.' : ucfirst(str_replace('_', ' ', $notice));
    return '<div class="eum-public-job-board__notice eum-public-job-board__notice--' . esc_attr($type ?: 'success') . '">' . esc_html($message) . '</div>';
  }

  private function request_expects_json_response(): bool {
    $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return strpos($content_type, 'application/json') !== false
      || strpos($accept, 'application/json') !== false
      || $xhr === 'xmlhttprequest';
  }

  private function current_url(): string {
    return (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? parse_url(home_url('/'), PHP_URL_HOST)) . ($_SERVER['REQUEST_URI'] ?? '/');
  }

  private function term_full_path(string $tax, int $term_id, string $sep): string {
    $t = get_term($term_id, $tax);
    if (!$t || is_wp_error($t)) return '';
    $anc = array_reverse(get_ancestors($term_id, $tax));
    $names = [];
    foreach ($anc as $aid) { $a = get_term($aid, $tax); if ($a && !is_wp_error($a)) $names[] = $a->name; }
    $names[] = $t->name;
    return implode($sep, $names);
  }

  private function generate_username_from_name(string $full_name): string {
    $parts = preg_split('/\s+/', strtolower(trim($full_name)));
    return preg_replace('/[^a-z0-9._-]/', '', ($parts[0] ?? '') . (count($parts) > 1 ? end($parts) : ''));
  }

  private function parse_datetime_local(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $dt = date_create($value, wp_timezone());
    return $dt ? $dt->format('Y-m-d H:i:s') : '';
  }

  private function datetime_local_value(string $mysql): string {
    if ($mysql === '') return '';
    $ts = strtotime($mysql);
    return $ts ? wp_date('Y-m-d\TH:i', $ts, wp_timezone()) : '';
  }

  private function next_available_username(string $base): string {
    $base = sanitize_user($base, true) ?: 'user';
    $candidate = $base; $i = 2;
    while (username_exists($candidate)) { $candidate = $base . $i; $i++; }
    return $candidate;
  }

  private function next_available_username_for_division(string $base, string $division_key): string {
    $base = sanitize_user($base, true) ?: 'user';
    $candidate = $base; $i = 2;
    while (username_exists($candidate) || (class_exists('EUM_Company_Divisions') && email_exists(EUM_Company_Divisions::build_email_for_username_and_division($candidate, $division_key)))) { $candidate = $base . $i; $i++; }
    return $candidate;
  }

  private function log_job_board_runtime_issue(string $context, \Throwable $e): void {
    eum_log_message('[EUM Job Boards] ' . $context . ': ' . $e->getMessage());
  }
}

