<?php
if (!defined('ABSPATH')) exit;

class EUM_Job_Tasks {
  const CPT = 'eum_job_task';
  const META_ALLOWED_JOB_PATHS = 'eum_allowed_job_paths';

  private static $task_cache = null;
  private static $labels_cache = [];

  public function init(): void {
    add_action('init', [$this, 'register_cpt']);
    if (is_admin()) {
      add_action('admin_menu', [$this, 'menu'], 30);
    }
  }

  public function register_cpt(): void {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Job Tasks',
        'singular_name' => 'Job Task',
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
    add_submenu_page(EUM_Admin::parent_slug('workforce'), 'Job Tasks', 'Job Tasks', 'read', 'eum-job-tasks', [$this, 'render_page']);
  }

  public static function task_label(int $post_id): string {
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

  public static function task_posts(): array {
    if (self::$task_cache !== null) return self::$task_cache;
    $posts = get_posts([
      'post_type' => self::CPT,
      'post_status' => ['publish', 'draft'],
      'numberposts' => 5000,
      'orderby' => 'title',
      'order' => 'ASC',
      'suppress_filters' => false,
    ]);
    self::$task_cache = is_array($posts) ? $posts : [];
    return self::$task_cache;
  }

  public static function invalidate_cache(): void {
    self::$task_cache = null;
    self::$labels_cache = [];
  }

  public static function task_records_for_job_term(int $job_term_id): array {
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
    foreach (self::task_posts() as $post) {
      $post_id = (int)$post->ID;
      $allowed = self::allowed_job_paths($post_id);
      if (empty($allowed) || !array_intersect($match_ids, $allowed)) continue;
      $label = sanitize_text_field((string)$post->post_title);
      if ($label === '') continue;
      $out[] = [
        'id' => $post_id,
        'label' => $label,
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

  public static function task_labels_for_job_term(int $job_term_id): array {
    $labels = [];
    foreach (self::task_records_for_job_term($job_term_id) as $row) {
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($label !== '') $labels[] = $label;
    }
    return array_values(array_unique($labels));
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

  private function render_checkboxes(string $name, array $options, array $selected, string $empty = 'No options yet.'): string {
    if (empty($options)) return '<p class="description">' . esc_html($empty) . '</p>';
    $html = '<div style="max-height:280px;overflow:auto;border:1px solid #ccd0d4;padding:8px 10px;background:#fff;">';
    foreach ($options as $value => $label) {
      $html .= '<label style="display:block;margin:0 0 6px 0;"><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . (int)$value . '" ' . checked(in_array((int)$value, $selected, true), true, false) . '> ' . esc_html($label) . '</label>';
    }
    $html .= '</div>';
    return $html;
  }

  private function handle_actions(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) return;
    if (empty($_POST['eum_job_task_action'])) return;
    check_admin_referer('eum_job_tasks_save');

    $action = sanitize_key((string)$_POST['eum_job_task_action']);
    $post_id = isset($_POST['job_task_id']) ? (int)$_POST['job_task_id'] : 0;
    $title = sanitize_text_field((string)($_POST['job_task_name'] ?? ''));
    $allowed_paths = array_values(array_filter(array_map('intval', (array)($_POST['allowed_job_paths'] ?? [])), fn($v) => $v > 0));

    $redirect_args = ['page' => 'eum-job-tasks'];

    if ($action === 'delete' && $post_id > 0) {
      wp_delete_post($post_id, true);
      self::invalidate_cache();
      wp_safe_redirect(add_query_arg(array_merge($redirect_args, ['updated' => 'deleted']), admin_url('admin.php')));
      exit;
    }

    if ($title === '') {
      wp_safe_redirect(add_query_arg(array_merge($redirect_args, ['updated' => 'missing_title', 'edit' => $post_id]), admin_url('admin.php')));
      exit;
    }
    if (empty($allowed_paths)) {
      wp_safe_redirect(add_query_arg(array_merge($redirect_args, ['updated' => 'missing_paths', 'edit' => $post_id]), admin_url('admin.php')));
      exit;
    }

    $postarr = [
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'post_title' => $title,
    ];
    if ($action === 'update' && $post_id > 0) {
      $postarr['ID'] = $post_id;
      $post_id = (int)wp_update_post($postarr, true);
    } else {
      $post_id = (int)wp_insert_post($postarr, true);
    }

    if ($post_id > 0 && !is_wp_error($post_id)) {
      update_post_meta($post_id, self::META_ALLOWED_JOB_PATHS, $allowed_paths);
      self::invalidate_cache();
      wp_safe_redirect(add_query_arg(array_merge($redirect_args, ['updated' => ($action === 'update' ? 'updated' : 'created')]), admin_url('admin.php')));
      exit;
    }

    wp_safe_redirect(add_query_arg(array_merge($redirect_args, ['updated' => 'error']), admin_url('admin.php')));
    exit;
  }

  public function render_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $this->handle_actions();

    $edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
    $edit_post = $edit_id > 0 ? get_post($edit_id) : null;
    if (!$edit_post || $edit_post->post_type !== self::CPT) {
      $edit_id = 0;
      $edit_post = null;
    }

    $updated = sanitize_key((string)($_GET['updated'] ?? ''));
    $messages = [
      'created' => 'Job task created.',
      'updated' => 'Job task updated.',
      'deleted' => 'Job task deleted.',
      'missing_title' => 'Enter a job task name.',
      'missing_paths' => 'Select at least one allowed job path.',
      'error' => 'Could not save the job task.',
    ];

    $name = $edit_post ? (string)$edit_post->post_title : '';
    $allowed_paths = $edit_post ? self::allowed_job_paths($edit_id) : [];

    echo '<div class="wrap"><h1>Job Tasks</h1>';
    if ($updated !== '' && isset($messages[$updated])) {
      $class = in_array($updated, ['missing_title', 'missing_paths', 'error'], true) ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($messages[$updated]) . '</p></div>';
    }

    echo '<p>Create reusable schedule tasks here and attach them to one or more Job Paths. These task names become available in the schedule builder and timecard path/task display for those paths.</p>';
    echo '<p class="description">Job Titles are still automatically valid schedule tasks, and each Job Path can still have its own custom one-off task list from the Enterprise Terms screen.</p>';
    echo '<div style="display:grid;grid-template-columns:minmax(360px,460px) minmax(520px,1fr);gap:24px;align-items:start;">';

    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">' . ($edit_post ? 'Edit Job Task' : 'Add Job Task') . '</h2>';
    echo '<form method="post">';
    wp_nonce_field('eum_job_tasks_save');
    echo '<input type="hidden" name="job_task_id" value="' . (int)$edit_id . '">';
    echo '<p><label><strong>Task Name</strong></label><br><input type="text" name="job_task_name" value="' . esc_attr($name) . '" class="regular-text" style="width:100%;max-width:420px;" required></p>';
    echo '<p><label><strong>Allowed Job Paths</strong></label><br>';
    echo class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_checkboxes('allowed_job_paths', $allowed_paths, get_current_user_id(), ['enforce_manageable_job_paths' => false]) : $this->render_checkboxes('allowed_job_paths', $this->all_job_paths_for_form(), $allowed_paths, 'No Job Paths exist yet.');
    echo '<span class="description">These paths can use this task inside the scheduler and related timecard displays.</span></p>';
    echo '<p>';
    echo '<button type="submit" name="eum_job_task_action" value="' . ($edit_post ? 'update' : 'create') . '" class="button button-primary">' . ($edit_post ? 'Update Job Task' : 'Create Job Task') . '</button> ';
    if ($edit_post) echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=eum-job-tasks')) . '">Cancel</a> ';
    if ($edit_post) echo '<button type="submit" name="eum_job_task_action" value="delete" class="button button-link-delete" onclick="return confirm(\'Delete this job task?\');">Delete</button>';
    echo '</p></form></div>';

    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">Current Job Tasks</h2>';
    $rows = self::task_posts();
    if (empty($rows)) {
      echo '<p class="description">No job tasks created yet.</p>';
    } else {
      echo '<table class="widefat striped"><thead><tr><th>Task</th><th>Allowed Job Paths</th><th></th></tr></thead><tbody>';
      foreach ($rows as $post) {
        $post_id = (int)$post->ID;
        $paths = array_map(function($term_id) {
          return class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', (int)$term_id, ' / ') : (string)$term_id;
        }, self::allowed_job_paths($post_id));
        echo '<tr>';
        echo '<td><strong>' . esc_html((string)$post->post_title) . '</strong><br><span class="description">ID #' . $post_id . '</span></td>';
        echo '<td>' . (!empty($paths) ? esc_html(implode(' · ', $paths)) : '<span class="description">—</span>') . '</td>';
        echo '<td><a class="button button-small" href="' . esc_url(add_query_arg(['page' => 'eum-job-tasks', 'edit' => $post_id], admin_url('admin.php'))) . '">Edit</a></td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
    echo '</div></div></div>';
  }
}
