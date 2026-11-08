<?php
if (!defined('ABSPATH')) exit;

class EUM_Capabilities {
  const CAP_MANAGE = 'eum_manage';
  const CAP_EDIT_USERS = 'eum_edit_users';

  public function init(): void {
    // Ensure admins always get caps (covers cases where activation hook didn't fire)
    add_action('admin_init', [$this, 'ensure_admin_caps']);

    // Repair action handler
    add_action('admin_post_eum_repair_caps', [$this, 'handle_repair_caps']);
  }

  public function add_caps(): void {
    $role = get_role('administrator');
    if ($role) {
      $role->add_cap(self::CAP_MANAGE, true);
      $role->add_cap(self::CAP_EDIT_USERS, true);
    }
  }

  public function ensure_admin_caps(): void {
    if (!is_admin()) return;

    // Use a real admin capability (role names like "administrator" are not reliable in current_user_can)
    if (!current_user_can('manage_options')) return;

    if (!current_user_can(self::CAP_MANAGE) || !current_user_can(self::CAP_EDIT_USERS)) {
      $this->add_caps();
    }
  }

  public function handle_repair_caps(): void {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');

    check_admin_referer('eum_repair_caps', 'eum_repair_nonce');

    $this->add_caps();

    wp_safe_redirect(admin_url('admin.php?page=eum-status&repaired=1'));
    exit;
  }
}
