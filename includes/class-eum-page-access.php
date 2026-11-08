<?php
if (!defined('ABSPATH')) exit;

class EUM_Page_Access {
  const META_IT_ONLY = '_eum_page_it_only';
  const META_HR_ONLY = '_eum_page_hr_only';
  const META_FINANCE_ONLY = '_eum_page_finance_only';
  const META_MANAGERS = '_eum_page_managers';
  const META_JOB_PATHS = '_eum_page_job_paths';
  const META_GROUPS = '_eum_page_groups';
  const META_PROJECTS = '_eum_page_projects';

  public function init(): void {
    add_action('add_meta_boxes', [$this, 'add_meta_box']);
    add_action('save_post_page', [$this, 'save_meta_box']);
    add_action('template_redirect', [$this, 'enforce_page_access']);
    add_filter('wp_nav_menu_objects', [$this, 'filter_nav_menu_items'], 20, 2);
  }

  public function add_meta_box(): void {
    if (!EUM_Access::current_user_is_it()) return;

    add_meta_box(
      'eum-page-access',
      'Enterprise Access Rules',
      [$this, 'render_meta_box'],
      'page',
      'side',
      'default'
    );
  }

  public function render_meta_box(WP_Post $post): void {
    if (!EUM_Access::current_user_is_it()) {
      echo '<p>Only IT can configure page restrictions.</p>';
      return;
    }

    wp_nonce_field('eum_page_access_save', 'eum_page_access_nonce');

    $it_only  = ((string)get_post_meta($post->ID, self::META_IT_ONLY, true) === '1');
    $hr_only  = ((string)get_post_meta($post->ID, self::META_HR_ONLY, true) === '1');
    $finance_only = ((string)get_post_meta($post->ID, self::META_FINANCE_ONLY, true) === '1');
    $managers = ((string)get_post_meta($post->ID, self::META_MANAGERS, true) === '1');
    $job_paths = array_values(array_filter(array_map('intval', (array)get_post_meta($post->ID, self::META_JOB_PATHS, true))));
    $groups = array_values(array_filter(array_map('intval', (array)get_post_meta($post->ID, self::META_GROUPS, true))));
    $projects = array_values(array_filter(array_map('intval', (array)get_post_meta($post->ID, self::META_PROJECTS, true))));

    $job_terms = taxonomy_exists('eum_job_path') ? get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'number' => 0]) : [];
    if (is_wp_error($job_terms)) $job_terms = [];

    $group_terms = taxonomy_exists('eum_user_group') ? get_terms(['taxonomy' => 'eum_user_group', 'hide_empty' => false, 'number' => 0]) : [];
    if (is_wp_error($group_terms)) $group_terms = [];

    $project_terms = taxonomy_exists('eum_project') ? get_terms(['taxonomy' => 'eum_project', 'hide_empty' => false, 'number' => 0]) : [];
    if (is_wp_error($project_terms)) $project_terms = [];

    echo '<p><label><input type="checkbox" name="eum_page_it_only" value="1" '.checked($it_only, true, false).'> IT only</label></p>';
    echo '<p><label><input type="checkbox" name="eum_page_hr_only" value="1" '.checked($hr_only, true, false).'> HR only</label></p>';
    echo '<p><label><input type="checkbox" name="eum_page_finance_only" value="1" '.checked($finance_only, true, false).'> Finance only</label></p>';
    echo '<p><label><input type="checkbox" name="eum_page_managers" value="1" '.checked($managers, true, false).'> Managers (IT/HR always allowed too)</label></p>';

    echo '<p><strong>Job Path</strong><br>' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_checkboxes('eum_page_job_paths', $job_paths, get_current_user_id(), ['enforce_manageable_job_paths' => false]) : '<select name="eum_page_job_paths[]" multiple size="6" style="width:100%;">' . implode('', array_map(function($term) use ($job_paths) { $term_id = (int)$term->term_id; return '<option value="' . $term_id . '" ' . selected(in_array($term_id, $job_paths, true), true, false) . '>' . esc_html((string)$term->name) . '</option>'; }, $job_terms)) . '</select>') . '<span class="description">Selected root terms allow descendants too.</span></p>';

    echo '<p><strong>Groups</strong><br><select name="eum_page_groups[]" multiple size="5" style="width:100%;">';
    foreach ($group_terms as $term) {
      $term_id = (int)$term->term_id;
      echo '<option value="'.$term_id.'" '.selected(in_array($term_id, $groups, true), true, false).'>'.esc_html($term->name).'</option>';
    }
    echo '</select></p>';

    echo '<p><strong>Projects</strong><br><select name="eum_page_projects[]" multiple size="5" style="width:100%;">';
    foreach ($project_terms as $term) {
      $term_id = (int)$term->term_id;
      echo '<option value="'.$term_id.'" '.selected(in_array($term_id, $projects, true), true, false).'>'.esc_html($term->name).'</option>';
    }
    echo '</select></p>';
  }

  public function save_meta_box(int $post_id): void {
    if (!isset($_POST['eum_page_access_nonce']) || !wp_verify_nonce($_POST['eum_page_access_nonce'], 'eum_page_access_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!EUM_Access::current_user_is_it()) return;

    update_post_meta($post_id, self::META_IT_ONLY, isset($_POST['eum_page_it_only']) ? '1' : '0');
    update_post_meta($post_id, self::META_HR_ONLY, isset($_POST['eum_page_hr_only']) ? '1' : '0');
    update_post_meta($post_id, self::META_FINANCE_ONLY, isset($_POST['eum_page_finance_only']) ? '1' : '0');
    update_post_meta($post_id, self::META_MANAGERS, isset($_POST['eum_page_managers']) ? '1' : '0');

    $job_paths = isset($_POST['eum_page_job_paths']) ? array_map('intval', (array)$_POST['eum_page_job_paths']) : [];
    $groups = isset($_POST['eum_page_groups']) ? array_map('intval', (array)$_POST['eum_page_groups']) : [];
    $projects = isset($_POST['eum_page_projects']) ? array_map('intval', (array)$_POST['eum_page_projects']) : [];

    update_post_meta($post_id, self::META_JOB_PATHS, array_values(array_filter($job_paths, fn($x) => $x > 0)));
    update_post_meta($post_id, self::META_GROUPS, array_values(array_filter($groups, fn($x) => $x > 0)));
    update_post_meta($post_id, self::META_PROJECTS, array_values(array_filter($projects, fn($x) => $x > 0)));
  }

  public function enforce_page_access(): void {
    if (!is_page()) return;

    $post_id = get_queried_object_id();
    if ($post_id <= 0) return;

    $user_id = (int)get_current_user_id();
    if (!$this->user_can_access_page($post_id, $user_id)) {
      wp_safe_redirect(home_url('/'));
      exit;
    }
  }

  public function filter_nav_menu_items(array $items, $args): array {
    $user_id = (int)get_current_user_id();
    $filtered = [];

    foreach ($items as $item) {
      $linked_post_id = (int)($item->object_id ?? 0);
      $is_page_link = (($item->object ?? '') === 'page' || get_post_type($linked_post_id) === 'page');

      if ($is_page_link && $linked_post_id > 0 && !$this->user_can_access_page($linked_post_id, $user_id)) {
        continue;
      }

      $filtered[] = $item;
    }

    return $filtered;
  }

  public function user_can_access_page(int $post_id, int $user_id): bool {
    $it_only  = ((string)get_post_meta($post_id, self::META_IT_ONLY, true) === '1');
    $hr_only  = ((string)get_post_meta($post_id, self::META_HR_ONLY, true) === '1');
    $finance_only = ((string)get_post_meta($post_id, self::META_FINANCE_ONLY, true) === '1');
    $managers = ((string)get_post_meta($post_id, self::META_MANAGERS, true) === '1');
    $job_paths = array_values(array_filter(array_map('intval', (array)get_post_meta($post_id, self::META_JOB_PATHS, true))));
    $groups = array_values(array_filter(array_map('intval', (array)get_post_meta($post_id, self::META_GROUPS, true))));
    $projects = array_values(array_filter(array_map('intval', (array)get_post_meta($post_id, self::META_PROJECTS, true))));

    $has_rules = $it_only || $hr_only || $finance_only || $managers || !empty($job_paths) || !empty($groups) || !empty($projects);
    if (!$has_rules) return true;

    if ($it_only && EUM_Access::is_it_user($user_id)) return true;
    if ($hr_only && EUM_Access::is_hr_user($user_id)) return true;
    if ($finance_only && method_exists('EUM_Access', 'is_finance_user') && EUM_Access::is_finance_user($user_id)) return true;
    if ($managers && (EUM_Access::is_it_or_hr($user_id) || EUM_Access::is_manager_user($user_id))) return true;

    if (!empty($job_paths) && $this->user_matches_job_paths($user_id, $job_paths)) return true;
    if (!empty($groups) && $this->user_matches_terms($user_id, 'eum_user_group', $groups)) return true;
    if (!empty($projects) && $this->user_matches_terms($user_id, 'eum_project', $projects)) return true;

    return false;
  }

  private function user_matches_job_paths(int $user_id, array $allowed_root_ids): bool {
    if ($user_id <= 0 || !taxonomy_exists('eum_job_path')) return false;

    $assigned = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($assigned) || empty($assigned)) return false;

    $leaf = (int)$assigned[0];
    $path = array_merge([$leaf], array_map('intval', get_ancestors($leaf, 'eum_job_path')));
    foreach ($allowed_root_ids as $allowed_id) {
      if (in_array((int)$allowed_id, $path, true)) return true;
    }
    return false;
  }

  private function user_matches_terms(int $user_id, string $taxonomy, array $allowed_term_ids): bool {
    if ($user_id <= 0 || !taxonomy_exists($taxonomy)) return false;
    $assigned = wp_get_object_terms($user_id, $taxonomy, ['fields' => 'ids']);
    if (is_wp_error($assigned) || empty($assigned)) return false;
    return count(array_intersect(array_map('intval', $assigned), array_map('intval', $allowed_term_ids))) > 0;
  }
}
