<?php
if (!defined('ABSPATH')) exit;

class EUM_Job_Titles {
  const CPT = 'eum_job_title';
  const META_ALLOWED_JOB_PATHS = 'eum_allowed_job_paths';
  const META_DEFAULT_GROUPS = 'eum_default_user_groups';
  const META_DEFAULT_PROJECTS = 'eum_default_projects';
  const USER_SYNC_HOOK = 'eum_job_title_user_sync_batch';
  const USER_SYNC_BATCH_SIZE = 50;

  private static $title_cache = null;
  private static $labels_cache = [];

  public function init(): void {
    add_action('init', [$this, 'register_cpt']);
    add_action(self::USER_SYNC_HOOK, [$this, 'process_user_sync_batch'], 10, 3);
    if (is_admin()) {
      add_action('admin_menu', [$this, 'menu'], 30);
      // Process form submissions before wp-admin renders its header, so
      // redirects and validation feedback are sent before output begins.
      add_action('admin_init', [$this, 'handle_actions']);
    }
  }

  public function process_user_sync_batch($user_ids, $actor_user_id = 0, $reason = 'Job Title Update'): void {
    if (!class_exists('EUM_Taxonomies')) return;
    $user_ids = array_values(array_unique(array_filter(array_map('intval', (array)$user_ids), static function($user_id) {
      return $user_id > 0 && get_user_by('id', $user_id);
    })));
    $actor_user_id = max(0, (int)$actor_user_id);
    $reason = sanitize_text_field((string)$reason) ?: 'Job Title Update';
    foreach ($user_ids as $user_id) {
      EUM_Taxonomies::sync_user_classification_effects($user_id, $actor_user_id, $reason);
    }
  }

  private function schedule_path_user_sync(array $path_ids, int $actor_user_id, string $reason): int {
    if (!taxonomy_exists('eum_job_path')) return 0;
    $term_ids = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $path_ids)))) as $path_id) {
      if ($path_id <= 0 || !term_exists($path_id, 'eum_job_path')) continue;
      $term_ids[] = $path_id;
      $descendants = get_term_children($path_id, 'eum_job_path');
      if (!is_wp_error($descendants) && is_array($descendants)) {
        $term_ids = array_merge($term_ids, array_map('intval', $descendants));
      }
    }
    $term_ids = array_values(array_unique(array_filter($term_ids)));
    if (empty($term_ids)) return 0;

    $user_ids = get_objects_in_term($term_ids, 'eum_job_path');
    if (is_wp_error($user_ids)) {
      if (function_exists('eum_log_message')) eum_log_message('[EUM Job Titles] Could not collect users for background sync: ' . $user_ids->get_error_message());
      return 0;
    }
    $user_ids = array_values(array_unique(array_filter(array_map('intval', (array)$user_ids))));
    if (empty($user_ids)) return 0;

    $batches = array_chunk($user_ids, self::USER_SYNC_BATCH_SIZE);
    $start_at = time() + 10;
    foreach ($batches as $index => $batch) {
      $args = [$batch, max(0, $actor_user_id), $reason];
      if (!wp_next_scheduled(self::USER_SYNC_HOOK, $args)) {
        wp_schedule_single_event($start_at + ($index * 5), self::USER_SYNC_HOOK, $args);
      }
    }
    return count($user_ids);
  }

  private function redirect_with_save_error(string $details): void {
    $transient_key = 'eum_job_title_save_error_' . (int)get_current_user_id();
    set_transient($transient_key, sanitize_text_field($details), 60);
    wp_safe_redirect(add_query_arg(['page' => 'eum-job-titles', 'updated' => 'error'], admin_url('admin.php')));
    exit;
  }

  public function register_cpt(): void {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Job Titles',
        'singular_name' => 'Job Title',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title'],
      'map_meta_cap' => true,
      'rewrite' => false,
      'query_var' => false,
    ]);
  }

  public function menu(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) return;
    add_submenu_page(EUM_Admin::parent_slug('workforce'), 'Job Titles', 'Job Titles', 'read', 'eum-job-titles', [$this, 'render_page']);
  }

  public static function token_from_id(int $post_id): string {
    return $post_id > 0 ? 'eum_job_title:' . $post_id : '';
  }

  public static function id_from_token($value): int {
    if (is_numeric($value)) return max(0, (int)$value);
    $value = trim((string)$value);
    if (preg_match('/^eum_job_title:(\d+)$/', $value, $m)) return (int)$m[1];
    return 0;
  }

  public static function title_label(int $post_id): string {
    $post_id = (int)$post_id;
    if ($post_id <= 0) return '';
    if (isset(self::$labels_cache[$post_id])) return self::$labels_cache[$post_id];
    $post = get_post($post_id);
    if (!$post || $post->post_type !== self::CPT || $post->post_status === 'trash') return '';
    return self::$labels_cache[$post_id] = sanitize_text_field((string)$post->post_title);
  }

  public static function allowed_job_paths(int $post_id): array {
    $ids = array_map('intval', (array)get_post_meta($post_id, self::META_ALLOWED_JOB_PATHS, true));
    return array_values(array_filter($ids, fn($v) => $v > 0));
  }

  public static function default_groups(int $post_id): array {
    $ids = array_map('intval', (array)get_post_meta($post_id, self::META_DEFAULT_GROUPS, true));
    return array_values(array_filter($ids, fn($v) => $v > 0));
  }

  public static function default_projects(int $post_id): array {
    $ids = array_map('intval', (array)get_post_meta($post_id, self::META_DEFAULT_PROJECTS, true));
    return array_values(array_filter($ids, fn($v) => $v > 0));
  }

  public static function title_posts(): array {
    if (self::$title_cache !== null) return self::$title_cache;
    $posts = get_posts([
      'post_type' => self::CPT,
      'post_status' => ['publish', 'draft'],
      'numberposts' => 5000,
      'orderby' => 'title',
      'order' => 'ASC',
      'suppress_filters' => false,
    ]);
    self::$title_cache = is_array($posts) ? $posts : [];
    return self::$title_cache;
  }

  public static function invalidate_cache(): void {
    self::$title_cache = null;
    self::$labels_cache = [];
  }

  public static function title_records_for_job_term(int $job_term_id): array {
    $job_term_id = (int)$job_term_id;
    if ($job_term_id <= 0) return [];
    $match_ids = [$job_term_id];
    if (taxonomy_exists('eum_job_path')) {
      $anc = get_ancestors($job_term_id, 'eum_job_path');
      if (!is_wp_error($anc) && is_array($anc)) {
        $match_ids = array_values(array_unique(array_merge($match_ids, array_map('intval', $anc))));
      }
    }

    $out = [];
    foreach (self::title_posts() as $post) {
      $post_id = (int)$post->ID;
      $allowed = self::allowed_job_paths($post_id);
      if (empty($allowed) || !array_intersect($match_ids, $allowed)) continue;
      $label = sanitize_text_field((string)$post->post_title);
      if ($label === '') continue;
      $out[] = [
        'id' => $post_id,
        'label' => $label,
        'token' => self::token_from_id($post_id),
        'allowed_paths' => $allowed,
      ];
    }
    usort($out, static function($a, $b) {
      $cmp = strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
      if ($cmp !== 0) return $cmp;
      return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });
    return $out;
  }

  public static function title_labels_for_job_term(int $job_term_id): array {
    $labels = [];
    foreach (self::title_records_for_job_term($job_term_id) as $row) {
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($label !== '') $labels[] = $label;
    }
    return array_values(array_unique($labels));
  }

  public static function is_title_allowed_for_job_term(int $title_id, int $job_term_id): bool {
    $title_id = (int)$title_id;
    $job_term_id = (int)$job_term_id;
    if ($title_id <= 0 || $job_term_id <= 0) return false;
    foreach (self::title_records_for_job_term($job_term_id) as $row) {
      if ((int)($row['id'] ?? 0) === $title_id) return true;
    }
    return false;
  }

  public static function effective_group_ids_for_user_title(int $user_id): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !class_exists('EUM_Fields')) return [];
    $all = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    $title_id = self::id_from_token((string)($all['job_title'] ?? ''));
    return $title_id > 0 ? self::default_groups($title_id) : [];
  }

  public static function effective_project_ids_for_user_title(int $user_id): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !class_exists('EUM_Fields')) return [];
    $all = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    $title_id = self::id_from_token((string)($all['job_title'] ?? ''));
    return $title_id > 0 ? self::default_projects($title_id) : [];
  }

  private function all_job_paths_for_form(): array {
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'number' => 0]);
    if (is_wp_error($terms) || empty($terms)) return [];
    $out = [];
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      $out[$term_id] = class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ') : (string)$term->name;
    }
    natcasesort($out);
    return $out;
  }

  private function all_terms_for_select(string $taxonomy): array {
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
    if (is_wp_error($terms) || empty($terms)) return [];
    $out = [];
    foreach ($terms as $term) {
      $out[(int)$term->term_id] = sanitize_text_field((string)$term->name);
    }
    natcasesort($out);
    return $out;
  }

  private function render_checkboxes(string $name, array $options, array $selected, string $empty = 'No options yet.'): string {
    if (empty($options)) return '<p class="description">' . esc_html($empty) . '</p>';
    $html = '<div style="max-height:240px;overflow:auto;border:1px solid #ccd0d4;padding:8px 10px;background:#fff;">';
    foreach ($options as $value => $label) {
      $html .= '<label style="display:block;margin:0 0 6px 0;"><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . (int)$value . '" ' . checked(in_array((int)$value, $selected, true), true, false) . '> ' . esc_html($label) . '</label>';
    }
    $html .= '</div>';
    return $html;
  }

  public function handle_actions(): void {
    if (sanitize_key((string)($_GET['page'] ?? '')) !== 'eum-job-titles') return;
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') return;
    if (empty($_POST['eum_job_title_action'])) return;
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) return;
    check_admin_referer('eum_job_titles_save');

    $action = sanitize_key((string)$_POST['eum_job_title_action']);
    $post_id = isset($_POST['job_title_id']) ? (int)$_POST['job_title_id'] : 0;
    $title = sanitize_text_field((string)($_POST['job_title_name'] ?? ''));
    $allowed_paths = array_values(array_filter(array_map('intval', (array)($_POST['allowed_job_paths'] ?? [])), fn($v) => $v > 0));
    $groups = array_values(array_filter(array_map('intval', (array)($_POST['default_groups'] ?? [])), fn($v) => $v > 0));
    $projects = array_values(array_filter(array_map('intval', (array)($_POST['default_projects'] ?? [])), fn($v) => $v > 0));

    $redirect_args = ['page' => 'eum-job-titles'];

    if ($action === 'delete' && $post_id > 0) {
      $affected_paths = self::allowed_job_paths($post_id);
      wp_delete_post($post_id, true);
      self::invalidate_cache();
      $sync_count = $this->schedule_path_user_sync($affected_paths, get_current_user_id(), 'Job Title Deleted');
      wp_safe_redirect(add_query_arg(array_merge($redirect_args, ['updated' => 'deleted', 'sync_queued' => $sync_count]), admin_url('admin.php')));
      exit;
    }

    if ($title === '') {
      wp_safe_redirect(add_query_arg(array_merge($redirect_args, ['updated' => 'missing_title']), admin_url('admin.php')));
      exit;
    }
    if (empty($allowed_paths)) {
      wp_safe_redirect(add_query_arg(array_merge($redirect_args, ['updated' => 'missing_paths', 'edit' => $post_id]), admin_url('admin.php')));
      exit;
    }

    $affected_paths = $action === 'update' && $post_id > 0 ? self::allowed_job_paths($post_id) : [];
    $postarr = [
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'post_title' => $title,
    ];
    if ($action === 'update' && $post_id > 0) {
      $postarr['ID'] = $post_id;
      $post_result = wp_update_post($postarr, true);
    } else {
      $post_result = wp_insert_post($postarr, true);
    }

    if (is_wp_error($post_result) || (int)$post_result <= 0) {
      $detail = is_wp_error($post_result) ? $post_result->get_error_message() : 'WordPress returned an invalid Job Title ID.';
      if (function_exists('eum_log_message')) eum_log_message('[EUM Job Titles] Could not save title: ' . sanitize_text_field($detail));
      $this->redirect_with_save_error($detail);
    }

    $post_id = (int)$post_result;
    $affected_paths = array_values(array_unique(array_merge($affected_paths, $allowed_paths)));
    try {
      update_post_meta($post_id, self::META_ALLOWED_JOB_PATHS, $allowed_paths);
      update_post_meta($post_id, self::META_DEFAULT_GROUPS, $groups);
      update_post_meta($post_id, self::META_DEFAULT_PROJECTS, $projects);
      if (class_exists('EUM_Public_Meta')) EUM_Public_Meta::save_job_title_fields($post_id, $_POST);
      self::invalidate_cache();
    } catch (\Throwable $e) {
      if ($action !== 'update') wp_delete_post($post_id, true);
      if (function_exists('eum_log_message')) eum_log_message('[EUM Job Titles] Save failed after post creation: ' . $e->getMessage());
      $this->redirect_with_save_error($e->getMessage());
    }

    // New titles cannot have assigned users yet. On edits, defer path-wide
    // recalculation so the save request stays fast and always redirects.
    $sync_count = $action === 'update'
      ? $this->schedule_path_user_sync($affected_paths, get_current_user_id(), 'Job Title Update')
      : 0;
    wp_safe_redirect(add_query_arg(array_merge($redirect_args, [
      'updated' => ($action === 'update' ? 'updated' : 'created'),
      'sync_queued' => $sync_count,
    ]), admin_url('admin.php')));
    exit;
  }

  public function render_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');

    $edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
    $edit_post = $edit_id > 0 ? get_post($edit_id) : null;
    if (!$edit_post || $edit_post->post_type !== self::CPT) {
      $edit_id = 0;
      $edit_post = null;
    }

    $updated = sanitize_key((string)($_GET['updated'] ?? ''));
    $save_error_detail = '';
    if ($updated === 'error') {
      $transient_key = 'eum_job_title_save_error_' . (int)get_current_user_id();
      $save_error_detail = (string)get_transient($transient_key);
      delete_transient($transient_key);
    }
    $messages = [
      'created' => 'Job title created.',
      'updated' => 'Job title updated.',
      'deleted' => 'Job title deleted.',
      'missing_title' => 'Enter a job title name.',
      'missing_paths' => 'Select at least one allowed job path.',
      'error' => 'Could not save the job title.',
    ];

    $name = $edit_post ? (string)$edit_post->post_title : '';
    $allowed_paths = $edit_post ? self::allowed_job_paths($edit_id) : [];
    $groups = $edit_post ? self::default_groups($edit_id) : [];
    $projects = $edit_post ? self::default_projects($edit_id) : [];

    echo '<div class="wrap"><h1>Job Titles</h1>';
    if ($updated !== '' && isset($messages[$updated])) {
      $class = in_array($updated, ['missing_title', 'missing_paths', 'error'], true) ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($messages[$updated]);
      if ($updated === 'error' && $save_error_detail !== '') echo ' Details: ' . esc_html($save_error_detail);
      echo '</p></div>';
    }
    if (isset($_GET['sync_queued']) && (int)$_GET['sync_queued'] > 0) {
      echo '<div class="notice notice-info"><p>Background classification sync queued for ' . (int)$_GET['sync_queued'] . ' user(s).</p></div>';
    }

    echo '<p>Create reusable job titles here. Titles can be attached to multiple Job Paths, can reuse the same visible name, and can carry default Groups and Projects.</p>';
    echo '<div style="display:grid;grid-template-columns:minmax(360px,460px) minmax(520px,1fr);gap:24px;align-items:start;">';

    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">' . ($edit_post ? 'Edit Job Title' : 'Add Job Title') . '</h2>';
    echo '<form method="post">';
    wp_nonce_field('eum_job_titles_save');
    echo '<input type="hidden" name="eum_job_title_action" value="' . ($edit_post ? 'update' : 'create') . '">';
    echo '<input type="hidden" name="job_title_id" value="' . (int)$edit_id . '">';
    echo '<p><label><strong>Job Title Name</strong></label><br><input type="text" name="job_title_name" value="' . esc_attr($name) . '" class="regular-text" style="width:100%;max-width:420px;" required></p>';
    if (class_exists('EUM_Public_Meta')) EUM_Public_Meta::render_job_title_fields($edit_id);
    echo '<p><label><strong>Allowed Job Paths</strong></label><br>';
    echo class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_checkboxes('allowed_job_paths', $allowed_paths, get_current_user_id(), ['enforce_manageable_job_paths' => false]) : $this->render_checkboxes('allowed_job_paths', $this->all_job_paths_for_form(), $allowed_paths, 'No Job Paths exist yet.');
    echo '<span class="description">These paths can use this title inside the Job Path selector.</span></p>';
    echo '<p><label><strong>Default User Groups</strong></label><br>';
    echo $this->render_checkboxes('default_groups', $this->all_terms_for_select('eum_user_group'), $groups, 'No groups exist yet.');
    echo '<span class="description">Users who receive this title inherit these groups.</span></p>';
    echo '<p><label><strong>Default Projects</strong></label><br>';
    echo $this->render_checkboxes('default_projects', $this->all_terms_for_select('eum_project'), $projects, 'No projects exist yet.');
    echo '<span class="description">Users who receive this title inherit these projects.</span></p>';
    echo '<p>';
    echo '<button type="submit" name="eum_job_title_action" value="' . ($edit_post ? 'update' : 'create') . '" class="button button-primary">' . ($edit_post ? 'Update Job Title' : 'Create Job Title') . '</button> ';
    if ($edit_post) echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=eum-job-titles')) . '">Cancel</a> ';
    if ($edit_post) echo '<button type="submit" name="eum_job_title_action" value="delete" class="button button-link-delete" onclick="return confirm(\'Delete this job title?\');">Delete</button>';
    echo '</p></form></div>';

    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">Current Job Titles</h2>';
    $rows = self::title_posts();
    if (empty($rows)) {
      echo '<p class="description">No job titles created yet.</p>';
    } else {
      echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Allowed Job Paths</th><th>Defaults</th><th></th></tr></thead><tbody>';
      foreach ($rows as $post) {
        $post_id = (int)$post->ID;
        $paths = array_map(function($term_id) {
          return class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', (int)$term_id, ' / ') : (string)$term_id;
        }, self::allowed_job_paths($post_id));
        $group_terms = [];
        foreach (self::default_groups($post_id) as $gid) {
          $term = get_term($gid, 'eum_user_group');
          if ($term && !is_wp_error($term)) $group_terms[] = (string)$term->name;
        }
        $project_terms = [];
        foreach (self::default_projects($post_id) as $pid) {
          $term = get_term($pid, 'eum_project');
          if ($term && !is_wp_error($term)) $project_terms[] = (string)$term->name;
        }
        echo '<tr>';
        echo '<td><strong>' . esc_html((string)$post->post_title) . '</strong><br><span class="description">ID #' . $post_id . '</span></td>';
        echo '<td>' . (!empty($paths) ? esc_html(implode(' · ', $paths)) : '<span class="description">—</span>') . '</td>';
        echo '<td>';
        echo !empty($group_terms) ? '<div><strong>Groups:</strong> ' . esc_html(implode(', ', $group_terms)) . '</div>' : '<div class="description">No default groups</div>';
        echo !empty($project_terms) ? '<div><strong>Projects:</strong> ' . esc_html(implode(', ', $project_terms)) . '</div>' : '<div class="description">No default projects</div>';
        echo '</td>';
        echo '<td><a class="button button-small" href="' . esc_url(add_query_arg(['page' => 'eum-job-titles', 'edit' => $post_id], admin_url('admin.php'))) . '">Edit</a></td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div></div></div>';
  }
}
