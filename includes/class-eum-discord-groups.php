<?php

if (!defined('ABSPATH')) exit;

class EUM_Discord_Groups {
  const TAXONOMY = 'eum_user_group';
  const META_DISCORD_USER_ID = 'edb_discord_user_id';
  const META_DISCORD_LINKED = 'edb_discord_linked';
  const OPTION_SYNC_VERSION = 'eum_discord_group_sync_version';
  const SYNC_VERSION = '4';
  const GROUP_CONNECTED_NAME = 'Discord Connected';
  const GROUP_CONNECTED_SLUG = 'discord-connected';
  const GROUP_NOT_CONNECTED_NAME = 'Discord Not Connected';
  const GROUP_NOT_CONNECTED_SLUG = 'discord-not-connected';
  const GROUP_ACTIVE_NAME = 'Active Users';
  const GROUP_ACTIVE_SLUG = 'active-users';
  const GROUP_TERMINATED_NAME = 'Terminated Users';
  const GROUP_TERMINATED_SLUG = 'terminated-users';

  /** @var array<int, bool> */
  private static $syncing_users = [];
  private static $backfilling_users = false;

  public function init(): void {
    add_action('init', [$this, 'ensure_terms'], 25);
    add_action('admin_init', [$this, 'maybe_backfill_existing_users']);
    add_action('user_register', [$this, 'sync_user_group_status'], 20, 1);
    add_action('profile_update', [$this, 'sync_user_group_status'], 20, 1);
    add_action('wp_login', [$this, 'handle_user_login'], 20, 2);
    add_action('added_user_meta', [$this, 'handle_user_meta_change'], 20, 4);
    add_action('updated_user_meta', [$this, 'handle_user_meta_change'], 20, 4);
    add_action('deleted_user_meta', [$this, 'handle_user_meta_change'], 20, 4);
    add_action('set_object_terms', [$this, 'handle_set_object_terms'], 20, 6);
  }

  public function ensure_terms(): void {
    try {
      if (!taxonomy_exists(self::TAXONOMY)) return;
      $this->ensure_term(self::GROUP_CONNECTED_NAME, self::GROUP_CONNECTED_SLUG);
      $this->ensure_term(self::GROUP_NOT_CONNECTED_NAME, self::GROUP_NOT_CONNECTED_SLUG);
      $this->ensure_term(self::GROUP_ACTIVE_NAME, self::GROUP_ACTIVE_SLUG);
      $this->ensure_term(self::GROUP_TERMINATED_NAME, self::GROUP_TERMINATED_SLUG);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('ensure_terms', $e);
    }
  }

  public function maybe_backfill_existing_users(): void {
    if (get_option(self::OPTION_SYNC_VERSION) === self::SYNC_VERSION) return;
    if (!current_user_can('manage_options')) return;
    $this->backfill_existing_users();
  }

  public function backfill_existing_users(): void {
    try {
      $this->ensure_terms();
      if (!taxonomy_exists(self::TAXONOMY)) return;

      $user_ids = get_users([
        'fields' => 'ids',
        'number' => 0,
        'orderby' => 'ID',
        'order' => 'ASC',
      ]);

      self::$backfilling_users = true;
      try {
        foreach (array_map('intval', (array)$user_ids) as $user_id) {
          $this->sync_user_group_status($user_id);
        }
      } finally {
        self::$backfilling_users = false;
      }

      update_option(self::OPTION_SYNC_VERSION, self::SYNC_VERSION, false);
    } catch (\Throwable $e) {
      $this->log_runtime_issue('backfill_existing_users', $e);
    }
  }

  public static function is_backfilling_users(): bool {
    return self::$backfilling_users;
  }

  public function sync_user_group_status(int $user_id): void {
    try {
      $user_id = (int)$user_id;
      if ($user_id <= 0) return;
      if (!taxonomy_exists(self::TAXONOMY)) return;
      if (!empty(self::$syncing_users[$user_id])) return;

      $term_ids = $this->managed_term_ids();
      $managed_ids = array_values(array_filter(array_map('intval', $term_ids), static fn($id) => $id > 0));
      if (count($managed_ids) < 4) return;

      $current = wp_get_object_terms($user_id, self::TAXONOMY, ['fields' => 'ids']);
      $current = is_wp_error($current) ? [] : array_values(array_unique(array_map('intval', (array)$current)));

      $is_connected = $this->user_has_discord_connection($user_id);
      $is_terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
      $desired_ids = [
        $is_connected ? (int)$term_ids['connected'] : (int)$term_ids['not_connected'],
        $is_terminated ? (int)$term_ids['terminated'] : (int)$term_ids['active'],
      ];

      $updated = array_values(array_unique(array_merge(
        array_diff($current, $managed_ids),
        $desired_ids
      )));

      sort($current);
      sort($updated);

      if ($current === $updated) return;

      self::$syncing_users[$user_id] = true;
      wp_set_object_terms($user_id, $updated, self::TAXONOMY, false);
      unset(self::$syncing_users[$user_id]);
    } catch (\Throwable $e) {
      unset(self::$syncing_users[(int)$user_id]);
      $this->log_runtime_issue('sync_user_group_status', $e);
    }
  }

  public function handle_user_login($user_login, $user): void {
    if ($user instanceof \WP_User) $this->sync_user_group_status((int)$user->ID);
  }

  public function handle_user_meta_change($meta_id, $user_id, $meta_key, $meta_value): void {
    $sync_keys = [self::META_DISCORD_USER_ID, self::META_DISCORD_LINKED];
    if (class_exists('EUM_Personnel')) $sync_keys[] = EUM_Personnel::META_TERMINATED;
    if (!in_array((string)$meta_key, $sync_keys, true)) return;
    $this->sync_user_group_status((int)$user_id);
  }

  public function handle_set_object_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void {
    if ((string)$taxonomy !== self::TAXONOMY) return;
    $this->sync_user_group_status((int)$object_id);
  }

  private function managed_term_ids(): array {
    return [
      'connected' => $this->ensure_term(self::GROUP_CONNECTED_NAME, self::GROUP_CONNECTED_SLUG),
      'not_connected' => $this->ensure_term(self::GROUP_NOT_CONNECTED_NAME, self::GROUP_NOT_CONNECTED_SLUG),
      'active' => $this->ensure_term(self::GROUP_ACTIVE_NAME, self::GROUP_ACTIVE_SLUG),
      'terminated' => $this->ensure_term(self::GROUP_TERMINATED_NAME, self::GROUP_TERMINATED_SLUG),
    ];
  }

  private function ensure_term(string $name, string $slug): int {
    $existing = get_term_by('slug', $slug, self::TAXONOMY);
    if ($existing instanceof \WP_Term) {
      return (int)$existing->term_id;
    }

    $created = wp_insert_term($name, self::TAXONOMY, ['slug' => $slug]);
    if (is_wp_error($created)) {
      $term_id = (int)$created->get_error_data('term_exists');
      if ($term_id > 0) return $term_id;
      return 0;
    }

    return (int)($created['term_id'] ?? 0);
  }

  private function user_has_discord_connection(int $user_id): bool {
    $discord_user_id = trim((string)get_user_meta($user_id, self::META_DISCORD_USER_ID, true));
    if ($discord_user_id === '') return false;
    return (bool) get_user_meta($user_id, self::META_DISCORD_LINKED, true);
  }

  private function log_runtime_issue(string $context, \Throwable $e): void {
    eum_log_message('[EUM Discord Groups] ' . $context . ': ' . $e->getMessage());
  }
}
