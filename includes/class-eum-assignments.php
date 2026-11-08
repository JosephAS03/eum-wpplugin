<?php

if (!defined('ABSPATH')) exit;



class EUM_Assignments {

  // Job Path term meta

  const TM_JOB_DEFAULT_GROUPS   = 'eum_default_user_groups';

  const TM_JOB_DEFAULT_PROJECTS = 'eum_default_projects';



  // Location term meta

  const TM_LOC_DEFAULT_GROUPS   = 'eum_default_user_groups';

  const TM_LOC_DEFAULT_PROJECTS = 'eum_default_projects';



  // User Group term meta

  const TM_GROUP_INCLUDED_GROUPS  = 'eum_included_user_groups';

  const TM_GROUP_DEFAULT_PROJECTS = 'eum_default_projects';



  // Project term meta

  const TM_PROJECT_INCLUDED_PROJECTS = 'eum_included_projects';

  const TM_PROJECT_DEFAULT_GROUPS    = 'eum_default_user_groups';



  public function init(): void {

    // Sync after profile taxonomy saves

    add_action('personal_options_update', [$this, 'sync_user_from_profile'], 60);

    add_action('edit_user_profile_update', [$this, 'sync_user_from_profile'], 60);

  }



  public function sync_user_from_profile(int $user_id): void {
    try {
      if (!$user_id) return;

      // Ensure taxonomies exist
      if (!taxonomy_exists('eum_job_path')) return;
      if (!taxonomy_exists('eum_location')) return;
      if (!taxonomy_exists('eum_user_group')) return;
      if (!taxonomy_exists('eum_project')) return;

      $this->sync_user($user_id);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('sync_user_from_profile', $e);
    }

  }



  /**

   * Applies defaults based on:

   * - Job path term + ancestors (inheritance)

   * - Location term + ancestors (inheritance)

   * - User groups: included groups + default projects (recursive)

   * - Projects: included projects + default groups (recursive)

   *

   * Default behavior: MERGE onto existing assignments (doesn't remove anything).

   */

  public function sync_user(int $user_id): void {
    try {
      $effective_group_ids = [];

      $effective_project_ids = [];



    // ===== Job Path inheritance =====

    $job_terms = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);

    if (!is_wp_error($job_terms) && !empty($job_terms)) {

      $leaf_id = (int)$job_terms[0];

      $path_ids = array_reverse(get_ancestors($leaf_id, 'eum_job_path'));

      $path_ids[] = $leaf_id;



      foreach ($path_ids as $tid) {

        $effective_group_ids = array_merge($effective_group_ids, $this->term_meta_ids((int)$tid, self::TM_JOB_DEFAULT_GROUPS));

        $effective_project_ids = array_merge($effective_project_ids, $this->term_meta_ids((int)$tid, self::TM_JOB_DEFAULT_PROJECTS));

      }

    }



    // ===== Location inheritance =====

    $loc_terms = wp_get_object_terms($user_id, 'eum_location', ['fields' => 'ids']);

    if (!is_wp_error($loc_terms) && !empty($loc_terms)) {

      $leaf_id = (int)$loc_terms[0];

      $path_ids = array_reverse(get_ancestors($leaf_id, 'eum_location'));

      $path_ids[] = $leaf_id;



      foreach ($path_ids as $tid) {

        $effective_group_ids = array_merge($effective_group_ids, $this->term_meta_ids((int)$tid, self::TM_LOC_DEFAULT_GROUPS));

        $effective_project_ids = array_merge($effective_project_ids, $this->term_meta_ids((int)$tid, self::TM_LOC_DEFAULT_PROJECTS));

      }

    }



    // ===== User Groups: included groups + default projects (recursive) =====

    $direct_groups = wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']);

    if (!is_wp_error($direct_groups) && !empty($direct_groups)) {

      $direct_groups = array_map('intval', $direct_groups);



      $visited = [];

      $queue = $direct_groups;



      while (!empty($queue)) {

        $gid = (int)array_shift($queue);

        if (!$gid || isset($visited[$gid])) continue;

        $visited[$gid] = true;



        // include this group itself

        $effective_group_ids[] = $gid;



        // group default projects

        $effective_project_ids = array_merge(

          $effective_project_ids,

          $this->term_meta_ids($gid, self::TM_GROUP_DEFAULT_PROJECTS)

        );



        // included groups (recursive)

        $includes = $this->term_meta_ids($gid, self::TM_GROUP_INCLUDED_GROUPS);

        foreach ($includes as $inc) {

          if (!isset($visited[$inc])) $queue[] = (int)$inc;

        }

      }

    }



    // ===== Projects: included projects + default groups (recursive) =====

    $direct_projects = wp_get_object_terms($user_id, 'eum_project', ['fields' => 'ids']);

    if (!is_wp_error($direct_projects) && !empty($direct_projects)) {

      $direct_projects = array_map('intval', $direct_projects);



      $visited = [];

      $queue = $direct_projects;



      while (!empty($queue)) {

        $pid = (int)array_shift($queue);

        if (!$pid || isset($visited[$pid])) continue;

        $visited[$pid] = true;



        // include this project itself

        $effective_project_ids[] = $pid;



        // project default groups

        $effective_group_ids = array_merge(

          $effective_group_ids,

          $this->term_meta_ids($pid, self::TM_PROJECT_DEFAULT_GROUPS)

        );



        // included projects (recursive)

        $includes = $this->term_meta_ids($pid, self::TM_PROJECT_INCLUDED_PROJECTS);

        foreach ($includes as $inc) {

          if (!isset($visited[$inc])) $queue[] = (int)$inc;

        }

      }

    }



    // De-dupe

    $effective_group_ids = array_values(array_unique(array_map('intval', $effective_group_ids)));

    $effective_project_ids = array_values(array_unique(array_map('intval', $effective_project_ids)));



    // Merge onto existing assignments (append-only default)

    $existing_groups = wp_get_object_terms($user_id, 'eum_user_group', ['fields' => 'ids']);

    if (is_wp_error($existing_groups)) $existing_groups = [];

    $existing_groups = array_map('intval', (array)$existing_groups);



    $existing_projects = wp_get_object_terms($user_id, 'eum_project', ['fields' => 'ids']);

    if (is_wp_error($existing_projects)) $existing_projects = [];

    $existing_projects = array_map('intval', (array)$existing_projects);



      $final_groups = array_values(array_unique(array_merge($existing_groups, $effective_group_ids)));

      $final_projects = array_values(array_unique(array_merge($existing_projects, $effective_project_ids)));

      sort($existing_groups);
      sort($existing_projects);
      sort($final_groups);
      sort($final_projects);

      if ($existing_groups !== $final_groups) {
        wp_set_object_terms($user_id, $final_groups, 'eum_user_group', false);
      }

      if ($existing_projects !== $final_projects) {
        wp_set_object_terms($user_id, $final_projects, 'eum_project', false);
      }
    } catch (\Throwable $e) {
      $this->log_runtime_issue('sync_user', $e);
    }

  }



  private function term_meta_ids(int $term_id, string $key): array {

    $raw = get_term_meta($term_id, $key, true);

    if (!is_array($raw)) return [];

    $ids = array_map('intval', $raw);

    $ids = array_values(array_filter($ids, fn($x) => $x > 0));

    return $ids;

  }

  private function log_runtime_issue(string $context, \Throwable $e): void {
    eum_log_message('[EUM Assignments] ' . $context . ': ' . $e->getMessage());
  }

}
