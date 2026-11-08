<?php
if (!defined('ABSPATH')) exit;

class EUM_Tickets {
  const PAGE_SLUG = 'eum-tickets';
  const OPTION_KEY = 'eum_ticket_settings';
  const OPTION_SYNC_STATE = 'eum_ticket_sync_state';
  const CPT_TICKET = 'eum_ticket';
  const NONCE = 'eum_ticket_action';
  const CRON_HOOK = 'eum_ticket_mail_sync';
  const CRON_SCHEDULE = 'eum_ticket_every_five_minutes';
  const SYNC_INITIAL_BACKFILL_DAYS = 30;
  const SYNC_RECENT_BACKFILL_DAYS = 3;
  const SYNC_INITIAL_BACKFILL_LIMIT = 200;
  const SYNC_RECENT_BACKFILL_LIMIT = 60;
  const SYNC_FINGERPRINT_LIMIT = 500;

  const META_TYPE = '_eum_ticket_type';
  const META_STATUS = '_eum_ticket_status';
  const META_PRIORITY = '_eum_ticket_priority';
  const META_REQUESTER_NAME = '_eum_ticket_requester_name';
  const META_REQUESTER_EMAIL = '_eum_ticket_requester_email';
  const META_ASSIGNEE_USER_ID = '_eum_ticket_assignee_user_id';
  const META_MESSAGES = '_eum_ticket_messages';
  const META_ACTIVITY = '_eum_ticket_activity';
  const META_LAST_ACTIVITY_AT = '_eum_ticket_last_activity_at';
  const META_LAST_INBOUND_AT = '_eum_ticket_last_inbound_at';
  const META_LAST_OUTBOUND_AT = '_eum_ticket_last_outbound_at';
  const META_LAST_SYNC_ERROR = '_eum_ticket_last_sync_error';
  const META_SOURCE = '_eum_ticket_source';

  private ?array $active_mail_transport = null;

  public function init(): void {
    add_action('init', [$this, 'register_post_type']);
    add_filter('cron_schedules', [$this, 'cron_schedules']);
    add_filter('wp_mail', [$this, 'filter_ticket_mail_headers'], 40, 1);
    add_action('init', [$this, 'ensure_cron_scheduled'], 20);
    add_action(self::CRON_HOOK, [$this, 'handle_periodic_sync']);

    add_action('admin_menu', [$this, 'admin_menu'], 48);
    add_action('admin_post_eum_tickets_save_settings', [$this, 'handle_save_settings']);
    add_action('admin_post_eum_tickets_manual_sync', [$this, 'handle_manual_sync']);
    add_action('admin_post_eum_tickets_test_connection', [$this, 'handle_test_connection']);
    add_action('admin_post_eum_ticket_update', [$this, 'handle_ticket_update']);
    add_action('admin_post_eum_ticket_reply', [$this, 'handle_ticket_reply']);
    add_action('admin_post_eum_ticket_note', [$this, 'handle_ticket_note']);
    add_action('admin_post_eum_ticket_bulk', [$this, 'handle_ticket_bulk']);

    add_shortcode('eum_tickets_portal', [$this, 'shortcode_portal']);
  }

  public function register_post_type(): void {
    register_post_type(self::CPT_TICKET, [
      'labels' => [
        'name' => 'Tickets',
        'singular_name' => 'Ticket',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'rewrite' => false,
      'map_meta_cap' => true,
      'capability_type' => 'post',
      'supports' => ['title', 'editor', 'author'],
    ]);
  }

  public static function default_settings(): array {
    return [
      'portal_title' => 'Support Tickets',
      'portal_intro' => 'Tickets are created from the configured HR / IT mailboxes. Use this portal to review, reply, resolve, and reopen requests.',
      'sync_enabled' => 1,
      'sync_interval_minutes' => 5,
      'ticket_types' => self::default_ticket_types(),
    ];
  }

  private static function default_ticket_types(): array {
    return [
      [
        'enabled' => 1,
        'key' => 'hr_requests',
        'label' => 'HR Requests',
        'team' => 'hr',
        'description' => 'Inbox for HR questions, leave questions, account follow-up, and personnel requests.',
        'default_priority' => 'normal',
        'inbound_enabled' => 0,
        'inbound_address' => '',
        'imap_host' => '',
        'imap_port' => '993',
        'imap_secure' => 'ssl',
        'imap_validate_cert' => 0,
        'imap_username' => '',
        'imap_password' => '',
        'imap_mailbox' => 'INBOX',
        'outbound_from_name' => 'HR Support',
        'outbound_from_email' => '',
        'outbound_reply_to' => '',
        'mail_transport' => 'inherit',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_secure' => 'tls',
        'smtp_auth' => 1,
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_timeout' => '20',
        'smtp_from_envelope' => '',
        'auto_ack_enabled' => 1,
        'auto_ack_subject' => 'We received your HR request [Ticket {{ticket_ref}}]',
        'auto_ack_body' => "Hello {{requester_name}},\n\nWe received your message and created ticket {{ticket_ref}} for {{ticket_type_label}}.\n\nSubject: {{ticket_subject}}\nStatus: {{ticket_status}}\n\nReply to this email and keep {{ticket_ref}} in the subject line to continue the thread.\n\nThank you,\n{{from_name}}",
      ],
      [
        'enabled' => 1,
        'key' => 'it_support',
        'label' => 'IT Support',
        'team' => 'it',
        'description' => 'Inbox for IT support, account access, workstation, and technical issues.',
        'default_priority' => 'normal',
        'inbound_enabled' => 0,
        'inbound_address' => '',
        'imap_host' => '',
        'imap_port' => '993',
        'imap_secure' => 'ssl',
        'imap_validate_cert' => 0,
        'imap_username' => '',
        'imap_password' => '',
        'imap_mailbox' => 'INBOX',
        'outbound_from_name' => 'IT Support',
        'outbound_from_email' => '',
        'outbound_reply_to' => '',
        'mail_transport' => 'inherit',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_secure' => 'tls',
        'smtp_auth' => 1,
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_timeout' => '20',
        'smtp_from_envelope' => '',
        'auto_ack_enabled' => 1,
        'auto_ack_subject' => 'We received your IT request [Ticket {{ticket_ref}}]',
        'auto_ack_body' => "Hello {{requester_name}},\n\nWe received your message and created ticket {{ticket_ref}} for {{ticket_type_label}}.\n\nSubject: {{ticket_subject}}\nStatus: {{ticket_status}}\n\nReply to this email and keep {{ticket_ref}} in the subject line to continue the thread.\n\nThank you,\n{{from_name}}",
      ],
    ];
  }

  public static function get_settings(): array {
    $saved = get_option(self::OPTION_KEY, []);
    $saved = is_array($saved) ? $saved : [];
    $settings = array_merge(self::default_settings(), $saved);
    $settings['portal_title'] = sanitize_text_field((string)($settings['portal_title'] ?? 'Support Tickets'));
    $settings['portal_intro'] = wp_kses_post((string)($settings['portal_intro'] ?? ''));
    $settings['sync_enabled'] = empty($settings['sync_enabled']) ? 0 : 1;
    $settings['sync_interval_minutes'] = max(5, (int)($settings['sync_interval_minutes'] ?? 5));
    $settings['ticket_types'] = self::normalize_ticket_types((array)($settings['ticket_types'] ?? []));
    if (empty($settings['ticket_types'])) $settings['ticket_types'] = self::default_ticket_types();
    return $settings;
  }

  private static function normalize_ticket_types(array $rows): array {
    if (empty($rows)) $rows = self::default_ticket_types();
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $key = sanitize_key((string)($row['key'] ?? ''));
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($key === '' || $label === '' || isset($seen[$key])) continue;
      $team = in_array((string)($row['team'] ?? 'both'), ['hr', 'it', 'both'], true) ? (string)$row['team'] : 'both';
      $mail_transport = in_array((string)($row['mail_transport'] ?? 'inherit'), ['inherit', 'wordpress', 'smtp'], true) ? (string)$row['mail_transport'] : 'inherit';
      $out[] = [
        'enabled' => empty($row['enabled']) ? 0 : 1,
        'key' => $key,
        'label' => $label,
        'team' => $team,
        'description' => sanitize_textarea_field((string)($row['description'] ?? '')),
        'default_priority' => in_array((string)($row['default_priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], true) ? (string)$row['default_priority'] : 'normal',
        'inbound_enabled' => empty($row['inbound_enabled']) ? 0 : 1,
        'inbound_address' => sanitize_email((string)($row['inbound_address'] ?? '')),
        'imap_host' => sanitize_text_field((string)($row['imap_host'] ?? '')),
        'imap_port' => preg_replace('/[^0-9]/', '', (string)($row['imap_port'] ?? '993')),
        'imap_secure' => in_array((string)($row['imap_secure'] ?? 'ssl'), ['ssl', 'tls', 'none'], true) ? (string)$row['imap_secure'] : 'ssl',
        'imap_validate_cert' => empty($row['imap_validate_cert']) ? 0 : 1,
        'imap_username' => sanitize_text_field((string)($row['imap_username'] ?? '')),
        'imap_password' => (string)($row['imap_password'] ?? ''),
        'imap_mailbox' => 'INBOX',
        'outbound_from_name' => sanitize_text_field((string)($row['outbound_from_name'] ?? '')),
        'outbound_from_email' => sanitize_email((string)($row['outbound_from_email'] ?? '')),
        'outbound_reply_to' => sanitize_email((string)($row['outbound_reply_to'] ?? '')),
        'mail_transport' => $mail_transport,
        'smtp_host' => sanitize_text_field((string)($row['smtp_host'] ?? '')),
        'smtp_port' => preg_replace('/[^0-9]/', '', (string)($row['smtp_port'] ?? '587')),
        'smtp_secure' => in_array((string)($row['smtp_secure'] ?? 'tls'), ['ssl', 'tls', 'none'], true) ? (string)$row['smtp_secure'] : 'tls',
        'smtp_auth' => empty($row['smtp_auth']) ? 0 : 1,
        'smtp_username' => sanitize_text_field((string)($row['smtp_username'] ?? '')),
        'smtp_password' => (string)($row['smtp_password'] ?? ''),
        'smtp_timeout' => preg_replace('/[^0-9]/', '', (string)($row['smtp_timeout'] ?? '20')),
        'smtp_from_envelope' => sanitize_email((string)($row['smtp_from_envelope'] ?? '')),
        'auto_ack_enabled' => empty($row['auto_ack_enabled']) ? 0 : 1,
        'auto_ack_subject' => sanitize_text_field((string)($row['auto_ack_subject'] ?? '')),
        'auto_ack_body' => sanitize_textarea_field((string)($row['auto_ack_body'] ?? '')),
      ];
      $seen[$key] = true;
    }
    return array_values($out);
  }

  public function cron_schedules(array $schedules): array {
    $settings = self::get_settings();
    $minutes = max(5, (int)($settings['sync_interval_minutes'] ?? 5));
    $schedules[self::CRON_SCHEDULE] = [
      'interval' => $minutes * MINUTE_IN_SECONDS,
      'display' => sprintf('Every %d minutes (EUM Tickets)', $minutes),
    ];
    return $schedules;
  }

  public function ensure_cron_scheduled(): void {
    $settings = self::get_settings();
    if (empty($settings['sync_enabled'])) {
      wp_clear_scheduled_hook(self::CRON_HOOK);
      return;
    }

    $existing = wp_next_scheduled(self::CRON_HOOK);
    if ($existing) {
      $schedule = wp_get_schedule(self::CRON_HOOK);
      if ($schedule !== self::CRON_SCHEDULE) {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $existing = false;
      }
    }

    if (!$existing) {
      wp_schedule_event(time() + 120, self::CRON_SCHEDULE, self::CRON_HOOK);
    }
  }

  public static function unschedule_cron(): void {
    wp_clear_scheduled_hook(self::CRON_HOOK);
  }

  public function admin_menu(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) return;
    add_submenu_page(
      EUM_Admin::parent_slug('integrations'),
      'Tickets',
      'Tickets',
      'read',
      self::PAGE_SLUG,
      [$this, 'render_admin_page']
    );
  }

  public function render_admin_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    $settings = self::get_settings();
    $sync_state = $this->mail_sync_capability_summary();
    $next_run = wp_next_scheduled(self::CRON_HOOK);
    $notice = $this->admin_notice_html();

    echo '<div class="wrap"><h1>Tickets</h1>';
    echo $notice;
    echo '<p><code>[eum_tickets_portal]</code> renders the HR / IT tickets workspace on the front end. HR users should use the shortcode page because they are intentionally blocked from <code>/wp-admin/</code>.</p>';
    echo '<p class="description">Each ticket type can point at its own mailbox. New inbound messages create tickets, replies on existing ticket references reopen/update the thread, and HR / IT can reply back out from the portal.</p>';

    echo '<div style="max-width:1100px;background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;margin:18px 0;">';
    echo '<h2 style="margin-top:0;">Mail Sync Status</h2>';
    echo '<p><strong>IMAP Extension:</strong> ' . esc_html($sync_state['imap']) . '</p>';
    echo '<p><strong>Auto Sync:</strong> ' . (!empty($settings['sync_enabled']) ? 'Enabled' : 'Disabled') . '</p>';
    echo '<p><strong>Interval:</strong> Every ' . (int)$settings['sync_interval_minutes'] . ' minutes</p>';
    echo '<p><strong>Next Scheduled Run:</strong> ' . ($next_run ? esc_html(date_i18n('Y-m-d H:i:s', $next_run)) : 'Not scheduled') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
    echo '<input type="hidden" name="action" value="eum_tickets_manual_sync">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_tickets_manual_sync', 'eum_tickets_manual_sync_nonce');
    echo '<button class="button button-secondary" type="submit">Run Mail Sync Now</button>';
    echo '</form>';
    echo '</div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:1200px;">';
    echo '<input type="hidden" name="action" value="eum_tickets_save_settings">';
    wp_nonce_field('eum_tickets_save_settings', 'eum_tickets_nonce');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Portal Title</th><td><input type="text" class="regular-text" name="portal_title" value="' . esc_attr((string)$settings['portal_title']) . '"></td></tr>';
    echo '<tr><th scope="row">Portal Intro</th><td><textarea class="large-text" rows="4" name="portal_intro">' . esc_textarea((string)$settings['portal_intro']) . '</textarea></td></tr>';
    echo '<tr><th scope="row">Enable Mail Sync</th><td><label><input type="checkbox" name="sync_enabled" value="1" ' . checked(!empty($settings['sync_enabled']), true, false) . '> Poll configured ticket mailboxes through WordPress cron</label></td></tr>';
    echo '<tr><th scope="row">Sync Interval</th><td><input type="number" min="5" class="small-text" name="sync_interval_minutes" value="' . (int)$settings['sync_interval_minutes'] . '"> minutes</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Ticket Types & Mailboxes</h2>';
    echo '<p class="description">Each row can point at a different inbound email account and outbound sending identity. Inbound ticket sync only reads <code>INBOX</code>; mail filed into any other folder is ignored. Set Mail Transport to <code>inherit</code> to reuse the Email Notifications SMTP settings.</p>';
    echo '<div class="eum-ticket-types" data-next-index="' . (int)count((array)$settings['ticket_types']) . '">';
    echo '<div class="eum-ticket-types__rows">';
    foreach (array_values((array)$settings['ticket_types']) as $index => $type) {
      echo $this->render_ticket_type_settings_row(is_array($type) ? $type : [], (int)$index);
    }
    echo '</div>';
    echo '<template class="eum-ticket-types__template">' . $this->render_ticket_type_settings_row([], '__index__') . '</template>';
    echo '<p><button type="button" class="button" data-eum-add-ticket-type>Add Ticket Type</button></p>';
    echo '</div>';
    submit_button('Save Ticket Settings');
    echo '</form>';
    echo $this->render_ticket_type_settings_script();
    echo '</div>';
  }

  private function admin_notice_html(): string {
    if (empty($_GET['eum_ticket_msg'])) return '';
    $message = sanitize_text_field((string)wp_unslash($_GET['eum_ticket_msg']));
    if ($message === '') return '';
    $type = sanitize_key((string)($_GET['eum_ticket_type'] ?? 'success'));
    $class = $type === 'error' ? 'notice notice-error' : 'notice notice-success';
    $html = '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    if (!empty($_GET['eum_ticket_details'])) {
      $details = sanitize_textarea_field((string)wp_unslash($_GET['eum_ticket_details']));
      if ($details !== '') {
        $html .= '<div class="notice notice-info"><div style="padding:10px 12px;"><strong>Ticket connection details</strong><pre style="margin:10px 0 0;white-space:pre-wrap;font-family:Consolas,Monaco,monospace;">' . esc_html($details) . '</pre></div></div>';
      }
    }
    return $html;
  }

  private function render_ticket_type_settings_row(array $type, $index): string {
    $type = array_merge([
      'enabled' => 1,
      'key' => '',
      'label' => '',
      'team' => 'both',
      'description' => '',
      'default_priority' => 'normal',
      'inbound_enabled' => 0,
      'inbound_address' => '',
      'imap_host' => '',
      'imap_port' => '993',
      'imap_secure' => 'ssl',
      'imap_validate_cert' => 0,
      'imap_username' => '',
      'imap_password' => '',
      'imap_mailbox' => 'INBOX',
      'outbound_from_name' => '',
      'outbound_from_email' => '',
      'outbound_reply_to' => '',
      'mail_transport' => 'inherit',
      'smtp_host' => '',
      'smtp_port' => '587',
      'smtp_secure' => 'tls',
      'smtp_auth' => 1,
      'smtp_username' => '',
      'smtp_password' => '',
      'smtp_timeout' => '20',
      'smtp_from_envelope' => '',
      'auto_ack_enabled' => 1,
      'auto_ack_subject' => '',
      'auto_ack_body' => '',
    ], $type);
    $type_key = sanitize_key((string)($type['key'] ?? ''));
    $sync_state = $this->mailbox_sync_state($type_key);
    $last_sync_at = sanitize_text_field((string)($sync_state['last_run_at'] ?? ''));
    $last_sync_error = sanitize_text_field((string)($sync_state['last_error'] ?? ''));

    ob_start();
    echo '<div class="eum-ticket-type" data-eum-ticket-type-row style="margin:0 0 18px;padding:18px;border:1px solid #dcdcde;border-radius:16px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.04);">';
    echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">';
    echo '<div><h3 style="margin:0 0 4px;">' . esc_html($type['label'] !== '' ? (string)$type['label'] : 'New Ticket Type') . '</h3><p class="description" style="margin:0;">Mailbox, routing, and sending settings for one ticket queue.';
    if ($last_sync_at !== '') echo ' Last sync: ' . esc_html($this->human_datetime($last_sync_at)) . '.';
    if ($last_sync_error !== '') echo '<br><span style="color:#b91c1c;">Last sync error: ' . esc_html($last_sync_error) . '</span>';
    echo '</p></div>';
    echo '<button type="button" class="button-link-delete" data-eum-remove-ticket-type>Remove</button>';
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:16px;">';
    echo '<p style="margin:0;"><label><input type="checkbox" name="ticket_types[' . esc_attr((string)$index) . '][enabled]" value="1" ' . checked(!empty($type['enabled']), true, false) . '> Enabled</label></p>';
    echo '<p style="margin:0;"><label><input type="checkbox" name="ticket_types[' . esc_attr((string)$index) . '][inbound_enabled]" value="1" ' . checked(!empty($type['inbound_enabled']), true, false) . '> Poll mailbox for new mail</label></p>';
    echo '<p style="margin:0;"><label><input type="checkbox" name="ticket_types[' . esc_attr((string)$index) . '][auto_ack_enabled]" value="1" ' . checked(!empty($type['auto_ack_enabled']), true, false) . '> Send auto acknowledgement</label></p>';
    echo '<p style="margin:0;"><label><input type="checkbox" name="ticket_types[' . esc_attr((string)$index) . '][imap_validate_cert]" value="1" ' . checked(!empty($type['imap_validate_cert']), true, false) . '> Validate IMAP certificate</label></p>';
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:16px;">';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Type Key</label><input type="text" name="ticket_types[' . esc_attr((string)$index) . '][key]" value="' . esc_attr((string)$type['key']) . '" class="regular-text" placeholder="hr_requests"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Label</label><input type="text" name="ticket_types[' . esc_attr((string)$index) . '][label]" value="' . esc_attr((string)$type['label']) . '" class="regular-text" placeholder="HR Requests"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Team</label><select name="ticket_types[' . esc_attr((string)$index) . '][team]">' . $this->select_options(['hr' => 'HR', 'it' => 'IT', 'both' => 'Both'], (string)$type['team']) . '</select></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Default Priority</label><select name="ticket_types[' . esc_attr((string)$index) . '][default_priority]">' . $this->select_options($this->priority_options(), (string)$type['default_priority']) . '</select></p>';
    echo '</div>';
    echo '<p style="margin:14px 0 0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Description</label><textarea name="ticket_types[' . esc_attr((string)$index) . '][description]" rows="3" class="large-text">' . esc_textarea((string)$type['description']) . '</textarea></p>';

    echo '<h4 style="margin:18px 0 8px;">Inbound Mailbox</h4>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Mailbox Email</label><input type="email" name="ticket_types[' . esc_attr((string)$index) . '][inbound_address]" value="' . esc_attr((string)$type['inbound_address']) . '" class="regular-text" placeholder="hr@company.com"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">IMAP Host</label><input type="text" name="ticket_types[' . esc_attr((string)$index) . '][imap_host]" value="' . esc_attr((string)$type['imap_host']) . '" class="regular-text" placeholder="mail.example.com"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">IMAP Port</label><input type="number" min="1" name="ticket_types[' . esc_attr((string)$index) . '][imap_port]" value="' . esc_attr((string)$type['imap_port']) . '" class="small-text"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Security</label><select name="ticket_types[' . esc_attr((string)$index) . '][imap_secure]">' . $this->select_options(['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None'], (string)$type['imap_secure']) . '</select></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Username</label><input type="text" name="ticket_types[' . esc_attr((string)$index) . '][imap_username]" value="' . esc_attr((string)$type['imap_username']) . '" class="regular-text"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Password</label><input type="password" name="ticket_types[' . esc_attr((string)$index) . '][imap_password]" value="' . esc_attr((string)$type['imap_password']) . '" class="regular-text"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Mailbox / Folder</label><input type="text" name="ticket_types[' . esc_attr((string)$index) . '][imap_mailbox]" value="INBOX" class="regular-text" readonly><span class="description" style="display:block;margin-top:4px;">Only <code>INBOX</code> is monitored. Messages moved into custom folders will not create or update tickets.</span></p>';
    echo '</div>';

    echo '<h4 style="margin:18px 0 8px;">Outbound Mail</h4>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">From Name</label><input type="text" name="ticket_types[' . esc_attr((string)$index) . '][outbound_from_name]" value="' . esc_attr((string)$type['outbound_from_name']) . '" class="regular-text"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">From Email</label><input type="email" name="ticket_types[' . esc_attr((string)$index) . '][outbound_from_email]" value="' . esc_attr((string)$type['outbound_from_email']) . '" class="regular-text" placeholder="support@company.com"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Reply-To</label><input type="email" name="ticket_types[' . esc_attr((string)$index) . '][outbound_reply_to]" value="' . esc_attr((string)$type['outbound_reply_to']) . '" class="regular-text" placeholder="support@company.com"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Mail Transport</label><select name="ticket_types[' . esc_attr((string)$index) . '][mail_transport]">' . $this->select_options(['inherit' => 'Inherit Email Notifications', 'wordpress' => 'WordPress Default', 'smtp' => 'Custom SMTP'], (string)$type['mail_transport']) . '</select></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">SMTP Host</label><input type="text" name="ticket_types[' . esc_attr((string)$index) . '][smtp_host]" value="' . esc_attr((string)$type['smtp_host']) . '" class="regular-text"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">SMTP Port</label><input type="number" min="1" name="ticket_types[' . esc_attr((string)$index) . '][smtp_port]" value="' . esc_attr((string)$type['smtp_port']) . '" class="small-text"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">SMTP Security</label><select name="ticket_types[' . esc_attr((string)$index) . '][smtp_secure]">' . $this->select_options(['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None'], (string)$type['smtp_secure']) . '</select></p>';
    echo '<p style="margin:0;"><label><input type="checkbox" name="ticket_types[' . esc_attr((string)$index) . '][smtp_auth]" value="1" ' . checked(!empty($type['smtp_auth']), true, false) . '> SMTP authentication required</label></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">SMTP Username</label><input type="text" name="ticket_types[' . esc_attr((string)$index) . '][smtp_username]" value="' . esc_attr((string)$type['smtp_username']) . '" class="regular-text"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">SMTP Password</label><input type="password" name="ticket_types[' . esc_attr((string)$index) . '][smtp_password]" value="' . esc_attr((string)$type['smtp_password']) . '"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">SMTP Timeout</label><input type="number" min="5" name="ticket_types[' . esc_attr((string)$index) . '][smtp_timeout]" value="' . esc_attr((string)$type['smtp_timeout']) . '" class="small-text"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Envelope Sender</label><input type="email" name="ticket_types[' . esc_attr((string)$index) . '][smtp_from_envelope]" value="' . esc_attr((string)$type['smtp_from_envelope']) . '" class="regular-text"></p>';
    echo '</div>';
    echo '<div style="margin-top:16px;padding:14px 16px;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;">';
    echo '<p style="margin:0 0 10px;" class="description">Run a live connection check for this queue using the values currently entered on this form. The test checks IMAP when inbound polling is enabled, probes SMTP directly when this queue uses SMTP, and reports whether the mailbox currently contains messages. Save afterward if you want to keep any edits you made before the test.</p>';
    echo '<button type="submit" class="button button-secondary" name="eum_ticket_run_test" value="' . esc_attr((string)$index) . '">Run Connection Test</button>';
    echo '</div>';

    echo '<h4 style="margin:18px 0 8px;">Auto Acknowledgement</h4>';
    echo '<p style="margin:0 0 10px;" class="description">Placeholders: <code>{{ticket_ref}}</code>, <code>{{ticket_subject}}</code>, <code>{{ticket_type_label}}</code>, <code>{{ticket_status}}</code>, <code>{{requester_name}}</code>, <code>{{from_name}}</code>.</p>';
    echo '<p style="margin:0 0 10px;"><label style="display:block;font-weight:600;margin-bottom:4px;">Subject</label><input type="text" class="large-text" name="ticket_types[' . esc_attr((string)$index) . '][auto_ack_subject]" value="' . esc_attr((string)$type['auto_ack_subject']) . '"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Body</label><textarea class="large-text" rows="6" name="ticket_types[' . esc_attr((string)$index) . '][auto_ack_body]">' . esc_textarea((string)$type['auto_ack_body']) . '</textarea></p>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_ticket_type_settings_script(): string {
    return '<script>(function(){var root=document.querySelector(".eum-ticket-types");if(!root||root.dataset.eumInit==="1")return;root.dataset.eumInit="1";var rows=root.querySelector(".eum-ticket-types__rows");var tpl=root.querySelector(".eum-ticket-types__template");var add=root.querySelector("[data-eum-add-ticket-type]");if(!rows||!tpl||!add)return;var nextIndex=parseInt(root.getAttribute("data-next-index")||String(rows.children.length),10);var bindRow=function(node){if(!node)return;var remove=node.querySelector("[data-eum-remove-ticket-type]");if(remove){remove.addEventListener("click",function(){node.remove();});}};Array.prototype.forEach.call(rows.children,bindRow);add.addEventListener("click",function(){var html=tpl.innerHTML.replace(/__index__/g,String(nextIndex++));rows.insertAdjacentHTML("beforeend",html);bindRow(rows.lastElementChild);});})();</script>';
  }

  public function handle_save_settings(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    check_admin_referer('eum_tickets_save_settings', 'eum_tickets_nonce');
    if (isset($_POST['eum_ticket_run_test']) && (string)wp_unslash($_POST['eum_ticket_run_test']) !== '') {
      $_POST['eum_ticket_test_index'] = (string)wp_unslash($_POST['eum_ticket_run_test']);
      $this->handle_test_connection();
    }

    $settings = $this->settings_from_post();
    update_option(self::OPTION_KEY, $settings, false);
    $this->ensure_cron_scheduled();
    $this->redirect_admin($this->current_url(), 'Ticket settings saved.');
  }

  public function handle_manual_sync(): void {
    if (!is_user_logged_in() || !class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    check_admin_referer('eum_tickets_manual_sync', 'eum_tickets_manual_sync_nonce');
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    $summary = $this->sync_all_mailboxes();
    $message = sprintf(
      'Mail sync complete. Scanned %d messages, created %d tickets, updated %d tickets, and skipped %d already-imported emails.',
      (int)$summary['processed'],
      (int)$summary['created'],
      (int)$summary['updated'],
      (int)($summary['skipped'] ?? 0)
    );
    $is_admin_settings_return = strpos($return_url, 'page=' . self::PAGE_SLUG) !== false;
    if (!empty($summary['errors'])) {
      $message .= ' ' . implode(' | ', array_map('sanitize_text_field', (array)$summary['errors']));
      if ($is_admin_settings_return) $this->redirect_admin($return_url, $message, 'error');
      $this->redirect_portal($return_url, $message, 'error');
    }
    if ($is_admin_settings_return) $this->redirect_admin($return_url, $message, 'success');
    $this->redirect_portal($return_url, $message, 'success');
  }

  public function handle_test_connection(): void {
    if (!is_user_logged_in() || !class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    check_admin_referer('eum_tickets_save_settings', 'eum_tickets_nonce');

    $test_index = isset($_POST['eum_ticket_test_index']) ? (string)wp_unslash($_POST['eum_ticket_test_index']) : '';
    $settings = $this->settings_from_post();
    update_option(self::OPTION_KEY, $settings, false);
    $this->ensure_cron_scheduled();

    $ticket_types = array_values((array)($settings['ticket_types'] ?? []));
    $index = max(0, (int)$test_index);
    $type = isset($ticket_types[$index]) && is_array($ticket_types[$index]) ? $ticket_types[$index] : [];
    $page_url = admin_url('admin.php?page=' . self::PAGE_SLUG);
    if (empty($type)) {
      $this->redirect_admin($page_url, 'Ticket queue test could not read the submitted row.', 'error');
    }

    $result = $this->test_ticket_type_connections($type);
    $queue_label = $this->ticket_queue_test_label($type);
    $message = !empty($result['success'])
      ? $queue_label . ' connection test succeeded.'
      : ($queue_label . ' connection test failed: ' . sanitize_text_field((string)($result['message'] ?? 'Unknown error.')));
    $notice_type = !empty($result['success']) ? 'success' : 'error';
    $details = $this->format_ticket_connection_test_details($result, $type);

    if (!empty($result['success'])) {
      eum_log_message('[EUM Tickets] ' . $queue_label . ' connection test passed.');
    } else {
      eum_log_message('[EUM Tickets] ' . $queue_label . ' connection test failed: ' . sanitize_text_field((string)($result['message'] ?? 'Unknown error.')));
    }

    $this->redirect_admin($page_url, $message, $notice_type, $details);
  }

  private function settings_from_post(): array {
    return [
      'portal_title' => sanitize_text_field((string)($_POST['portal_title'] ?? 'Support Tickets')),
      'portal_intro' => wp_kses_post((string)($_POST['portal_intro'] ?? '')),
      'sync_enabled' => !empty($_POST['sync_enabled']) ? 1 : 0,
      'sync_interval_minutes' => max(5, (int)($_POST['sync_interval_minutes'] ?? 5)),
      'ticket_types' => self::normalize_ticket_types((array)($_POST['ticket_types'] ?? [])),
    ];
  }

  public function shortcode_portal($atts = []): string {
    if (!is_user_logged_in()) return '<div class="eum-ticket-portal"><p>Please sign in to access tickets.</p></div>';
    $user_id = (int)get_current_user_id();
    if (!$this->user_can_access_portal($user_id)) return '<div class="eum-ticket-portal"><p>You do not have access to tickets.</p></div>';

    $settings = self::get_settings();
    $selected_ticket_id = isset($_GET['eum_ticket_id']) ? max(0, (int)$_GET['eum_ticket_id']) : 0;
    $assignment_filters_submitted = !empty($_GET['eum_ticket_assignment_filters']);
    $filters = [
      'status' => sanitize_key((string)($_GET['eum_ticket_status'] ?? 'open')),
      'type' => sanitize_key((string)($_GET['eum_ticket_type'] ?? '')),
      'q' => sanitize_text_field((string)($_GET['eum_ticket_q'] ?? '')),
      'person' => sanitize_text_field((string)($_GET['eum_ticket_person'] ?? '')),
      'assigned_to_me' => $assignment_filters_submitted ? !empty($_GET['eum_ticket_assigned_to_me']) : true,
      'unassigned' => $assignment_filters_submitted ? !empty($_GET['eum_ticket_unassigned']) : true,
      'assignee' => sanitize_text_field((string)($_GET['eum_ticket_assignee'] ?? '')),
    ];
    $filters['assignee_user_id'] = $this->resolve_assignee_filter_user_id((string)$filters['assignee']);
    $assignee_options = $this->assignee_options();
    $selected_assignee_label = $filters['assignee_user_id'] > 0
      ? (string)($assignee_options[(string)$filters['assignee_user_id']] ?? $this->user_label($filters['assignee_user_id']))
      : (string)$filters['assignee'];
    if (!in_array($filters['status'], ['all', 'open', 'pending', 'resolved', 'closed'], true)) $filters['status'] = 'open';

    $tickets = $this->visible_tickets_for_user($user_id, $filters);
    $selected_ticket = $selected_ticket_id > 0 ? get_post($selected_ticket_id) : null;
    if ($selected_ticket && $selected_ticket->post_type !== self::CPT_TICKET) $selected_ticket = null;
    if ($selected_ticket && !$this->user_can_view_ticket($selected_ticket_id, $user_id)) $selected_ticket = null;

    ob_start();
    echo '<div class="eum-ticket-portal" style="max-width:1280px;margin:0 auto;color:#111827;">';
    echo $this->render_portal_notice();
    echo '<div style="background:#fff;border:1px solid #dbe3ea;border-radius:18px;padding:24px;box-shadow:0 14px 34px rgba(15,23,42,.05);margin:0 0 18px;background:linear-gradient(180deg,#f7fbff 0%,#ffffff 100%);">';
    echo '<p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0f6cbd;">HR / IT Workspace</p>';
    echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
    echo '<div><h2 style="margin:0;font-size:32px;line-height:1.1;">' . esc_html((string)$settings['portal_title']) . '</h2>';
    echo '<div style="margin:12px 0 0;max-width:760px;font-size:16px;line-height:1.65;color:#475569;">' . wp_kses_post(wpautop((string)$settings['portal_intro'])) . '</div></div>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
    echo '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:13px;font-weight:600;">Open: ' . (int)$this->count_tickets_by_status($user_id, 'open') . '</span>';
    echo '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:13px;font-weight:600;">Pending: ' . (int)$this->count_tickets_by_status($user_id, 'pending') . '</span>';
    echo '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#dcfce7;color:#166534;font-size:13px;font-weight:600;">Resolved: ' . (int)$this->count_tickets_by_status($user_id, 'resolved') . '</span>';
    echo '</div></div>';
    if (class_exists('EUM_Access') && EUM_Access::current_user_is_it()) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
      echo '<input type="hidden" name="action" value="eum_tickets_manual_sync">';
      echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
      wp_nonce_field('eum_tickets_manual_sync', 'eum_tickets_manual_sync_nonce');
      echo '<button class="button button-secondary" type="submit">Sync Mailboxes Now</button>';
      echo '</form>';
    }
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:minmax(320px,420px) minmax(0,1fr);gap:18px;align-items:start;">';
    echo '<aside style="background:#fff;border:1px solid #dbe3ea;border-radius:18px;padding:18px;box-shadow:0 14px 34px rgba(15,23,42,.05);">';
    echo '<form method="get" style="display:grid;grid-template-columns:1fr;gap:10px;">';
    if (is_page()) {
      echo '<input type="hidden" name="page_id" value="' . (int)get_queried_object_id() . '">';
    }
    echo '<input type="hidden" name="eum_ticket_assignment_filters" value="1">';
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">Search</span><input type="search" name="eum_ticket_q" value="' . esc_attr((string)$filters['q']) . '" style="width:100%;"></label>';
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">People</span><input type="search" name="eum_ticket_person" value="' . esc_attr((string)$filters['person']) . '" placeholder="Requester, manager, assignee, or email" style="width:100%;"></label>';
    echo '<fieldset style="margin:0;padding:12px;border:1px solid #e2e8f0;border-radius:12px;"><legend style="padding:0 6px;font-weight:600;">Assignment</legend>';
    echo '<label style="display:flex;gap:8px;align-items:center;margin-bottom:8px;"><input type="checkbox" name="eum_ticket_assigned_to_me" value="1" ' . checked(!empty($filters['assigned_to_me']), true, false) . '> Assigned to me</label>';
    echo '<label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="eum_ticket_unassigned" value="1" ' . checked(!empty($filters['unassigned']), true, false) . '> Unassigned</label>';
    echo '<label style="display:block;font-weight:600;margin:10px 0 4px;">Assigned to</label>';
    echo '<input type="search" name="eum_ticket_assignee" list="eum-ticket-assignee-list" value="' . esc_attr($selected_assignee_label) . '" placeholder="Search assigned employee" style="width:100%;">';
    echo '<datalist id="eum-ticket-assignee-list">';
    foreach ($assignee_options as $assignee_label) {
      echo '<option value="' . esc_attr((string)$assignee_label) . '"></option>';
    }
    echo '</datalist>';
    echo '</fieldset>';
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">Status</span><select name="eum_ticket_status" style="width:100%;">' . $this->select_options(['open' => 'Open', 'pending' => 'Pending', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'], (string)$filters['status']) . '</select></label>';
    echo '<label><span style="display:block;font-weight:600;margin-bottom:4px;">Type</span><select name="eum_ticket_type" style="width:100%;"><option value="">All Types</option>' . $this->select_options($this->ticket_type_options(), (string)$filters['type']) . '</select></label>';
    echo '<button class="button button-secondary" type="submit">Filter</button>';
    echo '</form>';

    echo '<div style="margin-top:16px;display:grid;gap:12px;">';
    if (empty($tickets)) {
      echo '<div style="padding:16px;border:1px dashed #cbd5e1;border-radius:14px;color:#64748b;">No tickets matched the current filters.</div>';
    } else {
      echo '<form id="eum-ticket-bulk-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:grid;gap:12px;">';
      echo '<input type="hidden" name="action" value="eum_ticket_bulk">';
      echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
      wp_nonce_field('eum_ticket_bulk', 'eum_ticket_bulk_nonce');
      echo '<div style="padding:14px 16px;border:1px solid #e2e8f0;border-radius:14px;background:#f8fafc;display:grid;gap:12px;">';
      echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">';
      echo '<div><strong style="display:block;">Bulk Actions</strong><span style="display:block;margin-top:4px;color:#64748b;font-size:13px;">Select tickets below, then update their status or send one reply to every selected requester.</span></div>';
      echo '<label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;color:#0f172a;"><input type="checkbox" class="eum-ticket-select-all" value="1"> Select all listed</label>';
      echo '</div>';
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">';
      echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Bulk Action</label><select name="bulk_action_mode" style="width:100%;"><option value="status">Update Status</option><option value="reply">Send Reply</option></select></p>';
      echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Status Update</label><select name="bulk_status" style="width:100%;">' . $this->select_options($this->status_options(), 'pending') . '</select></p>';
      echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Status After Reply</label><select name="bulk_reply_status" style="width:100%;"><option value="">Keep current status</option>' . $this->select_options($this->status_options(), '') . '</select></p>';
      echo '</div>';
      echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Bulk Reply Message</label><textarea name="bulk_reply_body" rows="5" style="width:100%;"></textarea></p>';
      echo $this->render_cc_fields(0, 'bulk_');
      echo '<button class="button button-secondary" type="submit">Apply to Selected Tickets</button>';
      echo '</div>';

      foreach ($tickets as $ticket) {
        $ticket_id = (int)$ticket->ID;
        $status = $this->ticket_status($ticket_id);
        $priority = $this->ticket_priority($ticket_id);
        $ticket_type = $this->ticket_type_label($this->ticket_type_key($ticket_id));
        $meta_line = trim(implode(' - ', array_filter([
          $ticket_type,
          ucfirst($status),
          ucfirst($priority),
          $this->requester_email($ticket_id),
        ])));
        $excerpt = $this->ticket_conversation_excerpt($ticket_id);
        $is_active = $selected_ticket && (int)$selected_ticket->ID === $ticket_id;
        $link = add_query_arg(['eum_ticket_id' => $ticket_id], $this->current_url());
        echo '<div style="display:grid;grid-template-columns:auto minmax(0,1fr);gap:12px;align-items:flex-start;">';
        echo '<label style="padding-top:18px;"><input type="checkbox" class="eum-ticket-select-item" name="ticket_ids[]" value="' . $ticket_id . '"></label>';
        echo '<a href="' . esc_url($link) . '" style="display:block;padding:16px;border:1px solid ' . ($is_active ? '#93c5fd' : '#e2e8f0') . ';border-radius:16px;text-decoration:none;color:#111827;background:' . ($is_active ? '#f8fbff' : '#fff') . ';">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">';
        echo '<div><strong style="display:block;font-size:15px;line-height:1.4;">' . esc_html((string)$ticket->post_title) . '</strong>';
        echo '<span style="display:block;margin-top:6px;color:#64748b;font-size:13px;">' . esc_html($this->ticket_reference($ticket_id)) . ' - ' . esc_html($meta_line) . '</span></div>';
        echo '<span style="' . esc_attr($this->status_badge_style($status)) . '">' . esc_html(ucfirst($status)) . '</span>';
        echo '</div>';
        if ($excerpt !== '') echo '<p style="margin:10px 0 0;color:#475569;line-height:1.55;">' . esc_html($excerpt) . '</p>';
        echo '<p style="margin:10px 0 0;color:#94a3b8;font-size:12px;">Updated ' . esc_html($this->human_datetime($this->ticket_last_activity_at($ticket_id))) . '</p>';
        echo '</a>';
        echo '</div>';
      }
      echo '</form>';
      echo '<script>(function(){var form=document.getElementById("eum-ticket-bulk-form");if(!form)return;var selectAll=form.querySelector(".eum-ticket-select-all");if(!selectAll)return;var items=form.querySelectorAll(".eum-ticket-select-item");var sync=function(){var allChecked=items.length>0;items.forEach(function(item){if(!item.checked)allChecked=false;});selectAll.checked=allChecked;};selectAll.addEventListener("change",function(){items.forEach(function(item){item.checked=selectAll.checked;});});items.forEach(function(item){item.addEventListener("change",sync);});})();</script>';
    }
    echo '</div>';
    echo '</aside>';

    echo '<section style="background:#fff;border:1px solid #dbe3ea;border-radius:18px;padding:22px;box-shadow:0 14px 34px rgba(15,23,42,.05);min-height:640px;">';
    if (!$selected_ticket) {
      echo '<div style="padding:18px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#475569;">Select a ticket from the list to review the request, update status, and send replies.</div>';
    } else {
      echo $this->render_ticket_workspace($selected_ticket, $user_id);
    }
    echo '</section>';
    echo '</div></div>';
    return (string)ob_get_clean();
  }

  private function render_portal_notice(): string {
    if (empty($_GET['eum_ticket_notice'])) return '';
    $message = sanitize_text_field((string)wp_unslash($_GET['eum_ticket_notice']));
    if ($message === '') return '';
    $type = sanitize_key((string)($_GET['eum_ticket_notice_type'] ?? 'success'));
    $styles = $type === 'error'
      ? 'margin:0 0 18px;padding:14px 16px;border-radius:10px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;'
      : 'margin:0 0 18px;padding:14px 16px;border-radius:10px;border:1px solid #86efac;background:#f0fdf4;color:#166534;';
    return '<div style="' . esc_attr($styles) . '">' . esc_html($message) . '</div>';
  }

  private function render_ticket_workspace(WP_Post $ticket, int $user_id): string {
    $ticket_id = (int)$ticket->ID;
    $status = $this->ticket_status($ticket_id);
    $priority = $this->ticket_priority($ticket_id);
    $type_key = $this->ticket_type_key($ticket_id);
    $requester_name = $this->requester_name($ticket_id);
    $requester_email = $this->requester_email($ticket_id);
    $assignee_user_id = (int)get_post_meta($ticket_id, self::META_ASSIGNEE_USER_ID, true);
    $messages = $this->ticket_messages($ticket_id);
    $activity = array_reverse($this->ticket_activity($ticket_id));
    $sync_state = $this->mailbox_sync_state($type_key);
    $last_sync_at = sanitize_text_field((string)($sync_state['last_run_at'] ?? ''));
    $last_sync_error = sanitize_text_field((string)($sync_state['last_error'] ?? ''));
    $back_url = remove_query_arg(['eum_ticket_id', 'eum_ticket_notice', 'eum_ticket_notice_type'], $this->current_url());

    ob_start();
    echo '<p style="margin:0 0 14px;"><a href="' . esc_url($back_url) . '" style="color:#0f6cbd;text-decoration:none;font-weight:600;"><- Back to ticket list</a></p>';
    echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
    echo '<div><p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0f6cbd;">' . esc_html($this->ticket_type_label($type_key)) . '</p>';
    echo '<h3 style="margin:0;font-size:30px;line-height:1.15;">' . esc_html((string)$ticket->post_title) . '</h3>';
    echo '<p style="margin:10px 0 0;color:#64748b;">' . esc_html($this->ticket_reference($ticket_id)) . ' - ' . esc_html($requester_name !== '' ? $requester_name : 'Unknown requester') . ($requester_email !== '' ? ' (' . $requester_email . ')' : '') . '</p></div>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
    echo '<span style="' . esc_attr($this->status_badge_style($status)) . '">' . esc_html(ucfirst($status)) . '</span>';
    echo '<span style="display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#f8fafc;color:#334155;font-size:13px;font-weight:600;">Priority: ' . esc_html(ucfirst($priority)) . '</span>';
    echo '</div></div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:18px;">';
    echo $this->summary_card('Requester', $requester_name !== '' ? $requester_name : 'Unknown');
    echo $this->summary_card('Requester Email', $requester_email !== '' ? $requester_email : 'Not captured');
    echo $this->summary_card('Assignee', $assignee_user_id > 0 ? $this->user_label($assignee_user_id) : 'Unassigned');
    echo $this->summary_card('Last Activity', $this->human_datetime($this->ticket_last_activity_at($ticket_id)));
    echo '</div>';
    if ($last_sync_error !== '') {
      echo '<div style="margin-top:18px;padding:14px 16px;border-radius:14px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;">Mailbox sync issue for this queue: ' . esc_html($last_sync_error) . '</div>';
    } elseif ($last_sync_at !== '') {
      echo '<div style="margin-top:18px;padding:14px 16px;border-radius:14px;border:1px solid #dbe3ea;background:#f8fafc;color:#475569;">Mailbox last checked ' . esc_html($this->human_datetime($last_sync_at)) . '.</div>';
    }

    echo '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;">';
    echo '<h4 style="margin:0 0 12px;">Ticket Settings</h4>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">';
    echo '<input type="hidden" name="action" value="eum_ticket_update">';
    echo '<input type="hidden" name="ticket_id" value="' . $ticket_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_ticket_update_' . $ticket_id, 'eum_ticket_nonce');
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Subject</label><input type="text" name="subject" value="' . esc_attr((string)$ticket->post_title) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Ticket Type</label><select name="ticket_type" style="width:100%;">' . $this->select_options($this->ticket_type_options(), $type_key) . '</select></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Status</label><select name="status" style="width:100%;">' . $this->select_options($this->status_options(), $status) . '</select></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Priority</label><select name="priority" style="width:100%;">' . $this->select_options($this->priority_options(), $priority) . '</select></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Assignee</label><select name="assignee_user_id" style="width:100%;"><option value="0">Unassigned</option>' . $this->select_options($this->assignee_options(), (string)$assignee_user_id) . '</select></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Requester Name</label><input type="text" name="requester_name" value="' . esc_attr($requester_name) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Requester Email</label><input type="email" name="requester_email" value="' . esc_attr($requester_email) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;display:flex;align-items:flex-end;"><button class="button button-secondary" type="submit">Save Ticket</button></p>';
    echo '</form>';
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;margin-top:18px;">';
    echo '<div style="padding:18px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;">';
    echo '<h4 style="margin:0 0 12px;">Send Reply</h4>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_ticket_reply">';
    echo '<input type="hidden" name="ticket_id" value="' . $ticket_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_ticket_reply_' . $ticket_id, 'eum_ticket_nonce');
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Reply Message</label><textarea name="reply_body" rows="8" style="width:100%;"></textarea></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">New Status After Reply</label><select name="status" style="width:100%;">' . $this->select_options($this->status_options(), $status) . '</select></p>';
    echo $this->render_cc_fields($ticket_id);
    echo '<p class="description">The email subject will include ' . esc_html($this->ticket_reference($ticket_id)) . ' so future replies reattach to this ticket.</p>';
    echo '<button class="button button-primary" type="submit">Send Reply</button>';
    echo '</form>';
    echo '</div>';

    echo '<div style="padding:18px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;">';
    echo '<h4 style="margin:0 0 12px;">Add Internal Note</h4>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_ticket_note">';
    echo '<input type="hidden" name="ticket_id" value="' . $ticket_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    wp_nonce_field('eum_ticket_note_' . $ticket_id, 'eum_ticket_nonce');
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Note</label><textarea name="note_body" rows="8" style="width:100%;"></textarea></p>';
    echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Optional Status Change</label><select name="status" style="width:100%;"><option value="">Keep current status</option>' . $this->select_options($this->status_options(), $status) . '</select></p>';
    echo '<button class="button button-secondary" type="submit">Save Note</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;">';
    echo '<h4 style="margin:0 0 14px;">Conversation</h4>';
    if (empty($messages)) {
      echo '<p style="margin:0;color:#64748b;">No conversation history yet.</p>';
    } else {
      echo '<div style="display:grid;gap:14px;">';
      foreach ($messages as $message) {
        echo $this->render_message_row($message);
      }
      echo '</div>';
    }
    echo '</div>';

    echo '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:16px;background:#fff;">';
    echo '<h4 style="margin:0 0 14px;">Activity</h4>';
    if (empty($activity)) {
      echo '<p style="margin:0;color:#64748b;">No activity logged yet.</p>';
    } else {
      echo '<div style="display:grid;gap:10px;">';
      foreach ($activity as $entry) {
        $actor = !empty($entry['user_id']) ? $this->user_label((int)$entry['user_id']) : 'System';
        echo '<div style="padding:12px 14px;border:1px solid #eef2f7;border-radius:12px;background:#f8fafc;">';
        echo '<strong style="display:block;">' . esc_html((string)($entry['message'] ?? 'Activity')) . '</strong>';
        echo '<span style="display:block;margin-top:6px;color:#64748b;font-size:13px;">' . esc_html($actor) . ' - ' . esc_html($this->human_datetime((string)($entry['at'] ?? ''))) . '</span>';
        echo '</div>';
      }
      echo '</div>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function summary_card(string $label, string $value): string {
    return '<div style="padding:14px 16px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;"><span style="display:block;margin-bottom:6px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;">' . esc_html($label) . '</span>' . esc_html($value) . '</div>';
  }

  private function render_cc_fields(int $ticket_id = 0, string $prefix = ''): string {
    $resolved = $ticket_id > 0 ? $this->ticket_cc_option_addresses($ticket_id) : [];
    $options = [
      'requester_personal' => 'Requester Personal Email',
      'requester_work' => 'Requester Work Email',
      'manager_work' => 'Requester Manager Work Email',
      'manager_personal' => 'Requester Manager Personal Email',
    ];
    $note = $ticket_id > 0
      ? 'Only valid addresses will be used. Unavailable options are disabled for this ticket.'
      : 'Preset recipients are resolved separately for each selected ticket. Only valid addresses will be used.';

    ob_start();
    echo '<div style="display:grid;gap:10px;margin:14px 0;">';
    echo '<div><strong style="display:block;margin-bottom:4px;">CC Recipients</strong><span style="display:block;color:#64748b;font-size:13px;">' . esc_html($note) . '</span></div>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">';
    foreach ($options as $key => $label) {
      $name = $prefix . 'cc_' . $key;
      $email = (string)($resolved[$key] ?? '');
      $disabled = ($ticket_id > 0 && $email === '');
      $full_label = $label;
      if ($ticket_id > 0) {
        $full_label .= $email !== '' ? ' (' . $email . ')' : ' (not available)';
      }
      echo '<label style="display:flex;gap:8px;align-items:flex-start;padding:10px 12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;' . ($disabled ? 'opacity:.65;' : '') . '">';
      echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1"' . disabled($disabled, true, false) . '>';
      echo '<span>' . esc_html($full_label) . '</span>';
      echo '</label>';
    }
    echo '</div>';
    echo '<p style="margin:0;"><label style="display:block;font-weight:600;margin-bottom:4px;">Other CC Email Addresses</label><input type="text" name="' . esc_attr($prefix . 'cc_other') . '" value="" placeholder="name@example.com, another@example.com" style="width:100%;"></label></p>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_message_row(array $message): string {
    $direction = sanitize_key((string)($message['direction'] ?? 'internal'));
    $subject = sanitize_text_field((string)($message['subject'] ?? ''));
    $body = (string)($message['body'] ?? '');
    $author_name = sanitize_text_field((string)($message['author_name'] ?? ''));
    $author_email = sanitize_email((string)($message['author_email'] ?? ''));
    $cc = $this->sanitize_email_list($message['cc'] ?? []);
    $attachments = $this->normalize_ticket_attachments($message['attachments'] ?? []);
    $meta = trim(implode(' - ', array_filter([
      $author_name !== '' ? $author_name : '',
      $author_email !== '' ? $author_email : '',
      $this->human_datetime((string)($message['at'] ?? '')),
    ])));
    $palette = [
      'incoming' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'label' => 'Incoming Email'],
      'outgoing' => ['bg' => '#ecfdf5', 'border' => '#bbf7d0', 'label' => 'Outgoing Reply'],
      'internal' => ['bg' => '#f8fafc', 'border' => '#e2e8f0', 'label' => 'Internal Note'],
    ];
    $colors = $palette[$direction] ?? $palette['internal'];
    ob_start();
    echo '<article style="padding:16px;border:1px solid ' . esc_attr($colors['border']) . ';border-radius:16px;background:' . esc_attr($colors['bg']) . ';">';
    echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">';
    echo '<div><strong style="display:block;">' . esc_html($colors['label']) . '</strong>';
    if ($subject !== '') echo '<span style="display:block;margin-top:4px;color:#334155;">' . esc_html($subject) . '</span>';
    echo '</div>';
    echo '<span style="color:#64748b;font-size:13px;">' . esc_html($meta) . '</span>';
    echo '</div>';
    if (!empty($cc)) echo '<div style="margin-top:10px;color:#475569;font-size:13px;"><strong>Cc:</strong> ' . esc_html(implode(', ', $cc)) . '</div>';
    echo '<div style="margin-top:12px;color:#1f2937;line-height:1.65;">' . nl2br(esc_html($body)) . '</div>';
    if (!empty($attachments)) echo $this->render_message_attachments($attachments);
    echo '</article>';
    return (string)ob_get_clean();
  }

  private function render_message_attachments(array $attachments): string {
    if (empty($attachments)) return '';
    ob_start();
    echo '<div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(148,163,184,.25);">';
    echo '<strong style="display:block;margin-bottom:10px;color:#334155;">Attachments</strong>';
    echo '<div style="display:grid;gap:10px;">';
    foreach ($attachments as $attachment) {
      if (!is_array($attachment)) continue;
      $url = esc_url((string)($attachment['url'] ?? ''));
      if ($url === '') continue;
      $name = sanitize_file_name((string)($attachment['name'] ?? 'attachment'));
      if ($name === '') $name = 'attachment';
      $type = sanitize_text_field((string)($attachment['type'] ?? 'application/octet-stream'));
      $size = max(0, (int)($attachment['size'] ?? 0));
      $is_image = strpos($type, 'image/') === 0;
      echo '<div style="padding:12px;border:1px solid #dbe4f0;border-radius:14px;background:#fff;">';
      if ($is_image) {
        echo '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" style="display:block;margin-bottom:10px;">';
        echo '<img src="' . $url . '" alt="' . esc_attr($name) . '" style="display:block;max-width:100%;max-height:260px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;">';
        echo '</a>';
      }
      echo '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" style="font-weight:600;color:#0f172a;text-decoration:none;">' . esc_html($name) . '</a>';
      $meta = array_filter([
        $type !== '' ? strtoupper($type) : '',
        $size > 0 ? size_format($size) : '',
      ]);
      if (!empty($meta)) {
        echo '<div style="margin-top:4px;color:#64748b;font-size:13px;">' . esc_html(implode(' - ', $meta)) . '</div>';
      }
      echo '</div>';
    }
    echo '</div>';
    echo '</div>';
    return (string)ob_get_clean();
  }

  public function handle_ticket_update(): void {
    $ticket_id = max(0, (int)($_POST['ticket_id'] ?? 0));
    $ticket = $ticket_id > 0 ? get_post($ticket_id) : null;
    if (!$ticket || $ticket->post_type !== self::CPT_TICKET) wp_die('Ticket not found.');
    $this->assert_ticket_access($ticket_id, 'eum_ticket_update_' . $ticket_id, 'eum_ticket_nonce');

    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    $before = [
      'title' => (string)$ticket->post_title,
      'status' => $this->ticket_status($ticket_id),
      'priority' => $this->ticket_priority($ticket_id),
      'type' => $this->ticket_type_key($ticket_id),
      'assignee' => (int)get_post_meta($ticket_id, self::META_ASSIGNEE_USER_ID, true),
      'requester_name' => $this->requester_name($ticket_id),
      'requester_email' => $this->requester_email($ticket_id),
    ];

    $subject = sanitize_text_field((string)($_POST['subject'] ?? $before['title']));
    $status = sanitize_key((string)($_POST['status'] ?? $before['status']));
    $priority = sanitize_key((string)($_POST['priority'] ?? $before['priority']));
    $type_key = sanitize_key((string)($_POST['ticket_type'] ?? $before['type']));
    $assignee_user_id = max(0, (int)($_POST['assignee_user_id'] ?? $before['assignee']));
    $requester_name = sanitize_text_field((string)($_POST['requester_name'] ?? $before['requester_name']));
    $requester_email = sanitize_email((string)($_POST['requester_email'] ?? $before['requester_email']));

    if ($subject === '') $subject = $before['title'] !== '' ? $before['title'] : 'Support Ticket';
    if (!array_key_exists($status, $this->status_options())) $status = $before['status'];
    if (!array_key_exists($priority, $this->priority_options())) $priority = $before['priority'];
    if (!array_key_exists($type_key, $this->ticket_type_options())) $type_key = $before['type'];
    if ($assignee_user_id > 0 && !$this->user_can_access_portal($assignee_user_id)) $assignee_user_id = 0;

    wp_update_post([
      'ID' => $ticket_id,
      'post_title' => $subject,
    ]);
    update_post_meta($ticket_id, self::META_STATUS, $status);
    update_post_meta($ticket_id, self::META_PRIORITY, $priority);
    update_post_meta($ticket_id, self::META_TYPE, $type_key);
    update_post_meta($ticket_id, self::META_ASSIGNEE_USER_ID, $assignee_user_id);
    update_post_meta($ticket_id, self::META_REQUESTER_NAME, $requester_name);
    update_post_meta($ticket_id, self::META_REQUESTER_EMAIL, $requester_email);
    $this->touch_ticket($ticket_id);

    $changes = [];
    if ($before['title'] !== $subject) $changes[] = 'subject updated';
    if ($before['status'] !== $status) $changes[] = 'status set to ' . ucfirst($status);
    if ($before['priority'] !== $priority) $changes[] = 'priority set to ' . ucfirst($priority);
    if ($before['type'] !== $type_key) $changes[] = 'type changed to ' . $this->ticket_type_label($type_key);
    if ($before['assignee'] !== $assignee_user_id) $changes[] = 'assignee updated';
    if ($before['requester_name'] !== $requester_name || $before['requester_email'] !== $requester_email) $changes[] = 'requester details updated';
    if (!empty($changes)) {
      $this->push_activity($ticket_id, get_current_user_id(), 'Ticket updated: ' . implode(', ', $changes) . '.');
    }

    $this->redirect_portal($return_url, 'Ticket updated.', 'success');
  }

  public function handle_ticket_reply(): void {
    $ticket_id = max(0, (int)($_POST['ticket_id'] ?? 0));
    $ticket = $ticket_id > 0 ? get_post($ticket_id) : null;
    if (!$ticket || $ticket->post_type !== self::CPT_TICKET) wp_die('Ticket not found.');
    $this->assert_ticket_access($ticket_id, 'eum_ticket_reply_' . $ticket_id, 'eum_ticket_nonce');

    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    $reply_body = trim((string)wp_unslash($_POST['reply_body'] ?? ''));
    $new_status = sanitize_key((string)($_POST['status'] ?? $this->ticket_status($ticket_id)));
    if ($reply_body === '') $this->redirect_portal($return_url, 'Reply message is required.', 'error');

    $result = $this->send_ticket_reply($ticket, $reply_body, $new_status, $this->cc_request_payload(), (int)get_current_user_id(), false);
    if (empty($result['success'])) {
      $this->redirect_portal($return_url, (string)($result['message'] ?? 'Reply could not be sent. Check ticket mailbox settings.'), 'error');
    }
    $this->redirect_portal($return_url, 'Reply sent.', 'success');
  }

  public function handle_ticket_note(): void {
    $ticket_id = max(0, (int)($_POST['ticket_id'] ?? 0));
    $ticket = $ticket_id > 0 ? get_post($ticket_id) : null;
    if (!$ticket || $ticket->post_type !== self::CPT_TICKET) wp_die('Ticket not found.');
    $this->assert_ticket_access($ticket_id, 'eum_ticket_note_' . $ticket_id, 'eum_ticket_nonce');

    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    $note_body = trim((string)wp_unslash($_POST['note_body'] ?? ''));
    $new_status = sanitize_key((string)($_POST['status'] ?? ''));
    if ($note_body === '') $this->redirect_portal($return_url, 'Internal note is required.', 'error');

    $this->append_message($ticket_id, [
      'direction' => 'internal',
      'subject' => 'Internal note',
      'body' => $note_body,
      'author_name' => $this->user_label(get_current_user_id()),
      'author_email' => $this->actor_email(get_current_user_id(), $this->ticket_type_config($this->ticket_type_key($ticket_id))),
      'at' => current_time('mysql'),
    ]);
    $this->push_activity($ticket_id, get_current_user_id(), 'Internal note added.');
    if ($new_status !== '' && array_key_exists($new_status, $this->status_options()) && $new_status !== $this->ticket_status($ticket_id)) {
      update_post_meta($ticket_id, self::META_STATUS, $new_status);
      $this->push_activity($ticket_id, get_current_user_id(), 'Status updated to ' . ucfirst($new_status) . '.');
    }
    $this->touch_ticket($ticket_id);
    $this->redirect_portal($return_url, 'Internal note saved.', 'success');
  }

  public function handle_ticket_bulk(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    check_admin_referer('eum_ticket_bulk', 'eum_ticket_bulk_nonce');

    $user_id = (int)get_current_user_id();
    if (!$this->user_can_access_portal($user_id)) wp_die('Not allowed.');

    $return_url = esc_url_raw((string)($_POST['return_url'] ?? $this->current_url()));
    $raw_ticket_ids = isset($_POST['ticket_ids']) ? (array)wp_unslash($_POST['ticket_ids']) : [];
    $selected_ids = array_values(array_unique(array_filter(array_map('intval', $raw_ticket_ids), static fn($id) => $id > 0)));
    if (empty($selected_ids)) $this->redirect_portal($return_url, 'Select at least one ticket first.', 'error');

    $ticket_ids = [];
    foreach ($selected_ids as $ticket_id) {
      if ($this->user_can_view_ticket($ticket_id, $user_id) && get_post_type($ticket_id) === self::CPT_TICKET) {
        $ticket_ids[] = $ticket_id;
      }
    }
    if (empty($ticket_ids)) $this->redirect_portal($return_url, 'None of the selected tickets are available to update.', 'error');

    $mode = sanitize_key((string)($_POST['bulk_action_mode'] ?? 'status'));
    if ($mode === 'reply') {
      $reply_body = trim((string)wp_unslash($_POST['bulk_reply_body'] ?? ''));
      $reply_status = sanitize_key((string)($_POST['bulk_reply_status'] ?? ''));
      if ($reply_body === '') $this->redirect_portal($return_url, 'Bulk reply message is required.', 'error');

      $sent = 0;
      $missing_email = 0;
      $send_failed = 0;
      $cc_request = $this->cc_request_payload('bulk_');

      foreach ($ticket_ids as $ticket_id) {
        $ticket = get_post($ticket_id);
        if (!$ticket || $ticket->post_type !== self::CPT_TICKET) {
          $send_failed++;
          continue;
        }

        $result = $this->send_ticket_reply($ticket, $reply_body, $reply_status, $cc_request, $user_id, true);
        if (!empty($result['success'])) {
          $sent++;
          continue;
        }

        if (($result['reason'] ?? '') === 'missing_requester') {
          $missing_email++;
        } else {
          $send_failed++;
        }
      }

      if ($sent > 0 && $missing_email === 0 && $send_failed === 0) {
        $this->redirect_portal($return_url, 'Sent bulk reply to ' . $sent . ' ticket' . ($sent === 1 ? '' : 's') . '.', 'success');
      }

      $parts = [];
      if ($sent > 0) $parts[] = 'Sent ' . $sent . ' bulk repl' . ($sent === 1 ? 'y' : 'ies') . '.';
      if ($missing_email > 0) $parts[] = $missing_email . ' ticket' . ($missing_email === 1 ? ' was' : 's were') . ' skipped because the requester email was missing or invalid.';
      if ($send_failed > 0) $parts[] = $send_failed . ' ticket' . ($send_failed === 1 ? '' : 's') . ' failed to send.';
      if (empty($parts)) $parts[] = 'Bulk reply did not complete.';
      $this->redirect_portal($return_url, implode(' ', $parts), 'error');
    }

    if ($mode !== 'status') $this->redirect_portal($return_url, 'Choose a valid bulk action.', 'error');

    $new_status = sanitize_key((string)($_POST['bulk_status'] ?? ''));
    if (!array_key_exists($new_status, $this->status_options())) {
      $this->redirect_portal($return_url, 'Choose a valid status for the bulk update.', 'error');
    }

    $updated = 0;
    foreach ($ticket_ids as $ticket_id) {
      if ($this->ticket_status($ticket_id) === $new_status) continue;
      update_post_meta($ticket_id, self::META_STATUS, $new_status);
      $this->touch_ticket($ticket_id);
      $this->push_activity($ticket_id, $user_id, 'Bulk status updated to ' . ucfirst($new_status) . '.');
      $updated++;
    }

    if ($updated > 0) {
      $this->redirect_portal($return_url, 'Updated ' . $updated . ' ticket' . ($updated === 1 ? '' : 's') . ' to ' . ucfirst($new_status) . '.', 'success');
    }

    $this->redirect_portal($return_url, 'Selected tickets were already ' . ucfirst($new_status) . '.', 'success');
  }

  private function send_ticket_reply(WP_Post $ticket, string $reply_body, string $new_status, array $cc_request, int $actor_user_id, bool $is_bulk): array {
    $ticket_id = (int)$ticket->ID;
    $requester_email = $this->requester_email($ticket_id);
    if (!is_email($requester_email)) {
      return [
        'success' => false,
        'reason' => 'missing_requester',
        'message' => 'Requester email is missing or invalid.',
      ];
    }

    $type = $this->ticket_type_config($this->ticket_type_key($ticket_id));
    $subject = $this->build_reply_subject($ticket_id, (string)$ticket->post_title);
    $cc = $this->resolve_cc_emails_for_ticket($ticket_id, $cc_request, $requester_email);
    $sent = $this->send_ticket_mail($type, $requester_email, $subject, $reply_body, $ticket_id, $cc);
    if (!$sent) {
      $this->push_activity($ticket_id, $actor_user_id, ($is_bulk ? 'Bulk reply' : 'Outgoing reply') . ' failed to send to ' . $requester_email . '.');
      return [
        'success' => false,
        'reason' => 'send_failed',
        'message' => 'Reply could not be sent. Check ticket mailbox settings.',
      ];
    }

    $this->append_message($ticket_id, [
      'direction' => 'outgoing',
      'subject' => $subject,
      'body' => $reply_body,
      'author_name' => $this->user_label($actor_user_id),
      'author_email' => $this->actor_email($actor_user_id, $type),
      'cc' => $cc,
      'at' => current_time('mysql'),
    ]);
    update_post_meta($ticket_id, self::META_LAST_OUTBOUND_AT, current_time('mysql'));

    $status_changed = false;
    if ($new_status !== '' && array_key_exists($new_status, $this->status_options()) && $new_status !== $this->ticket_status($ticket_id)) {
      update_post_meta($ticket_id, self::META_STATUS, $new_status);
      $status_changed = true;
    }

    $activity = ($is_bulk ? 'Bulk reply sent to ' : 'Reply sent to ') . $requester_email;
    if (!empty($cc)) $activity .= ' (cc: ' . implode(', ', $cc) . ')';
    $activity .= '.';
    if ($status_changed) $activity .= ' Status updated to ' . ucfirst($new_status) . '.';
    $this->push_activity($ticket_id, $actor_user_id, $activity);
    $this->touch_ticket($ticket_id);

    return [
      'success' => true,
      'reason' => 'sent',
      'message' => 'Reply sent.',
      'to' => $requester_email,
      'cc' => $cc,
    ];
  }

  private function assert_ticket_access(int $ticket_id, string $nonce_action, string $nonce_field): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $user_id = (int)get_current_user_id();
    if (!$this->user_can_view_ticket($ticket_id, $user_id)) wp_die('Not allowed.');
    check_admin_referer($nonce_action, $nonce_field);
  }

  public function handle_periodic_sync(): void {
    $this->sync_all_mailboxes();
  }

  private function sync_all_mailboxes(): array {
    $settings = self::get_settings();
    $summary = [
      'processed' => 0,
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
      'errors' => [],
    ];

    foreach ((array)$settings['ticket_types'] as $type) {
      if (!is_array($type) || empty($type['enabled']) || empty($type['inbound_enabled'])) continue;
      $result = $this->sync_type_mailbox($type);
      $summary['processed'] += (int)($result['processed'] ?? 0);
      $summary['created'] += (int)($result['created'] ?? 0);
      $summary['updated'] += (int)($result['updated'] ?? 0);
      $summary['skipped'] += (int)($result['skipped'] ?? 0);
      if (!empty($result['error'])) $summary['errors'][] = (string)$result['error'];
    }

    return $summary;
  }

  private function sync_type_mailbox(array $type): array {
    $result = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => ''];
    $type_key = sanitize_key((string)($type['key'] ?? ''));
    $type_label = sanitize_text_field((string)($type['label'] ?? ($type_key !== '' ? $type_key : 'Ticket mailbox')));
    $sync_state = $this->mailbox_sync_state($type_key);
    if (!function_exists('imap_open')) {
      $result['error'] = 'IMAP PHP extension is not available, so ticket mailbox sync cannot run.';
      $this->finalize_mailbox_sync_state($type_key, $type, $sync_state, $result['error']);
      return $result;
    }

    $host = trim((string)($type['imap_host'] ?? ''));
    $username = trim((string)($type['imap_username'] ?? ''));
    $password = (string)($type['imap_password'] ?? '');
    if ($host === '' || $username === '' || $password === '') {
      $result['error'] = $type_label . ' mailbox sync is enabled but IMAP host, username, or password is incomplete.';
      $this->finalize_mailbox_sync_state($type_key, $type, $sync_state, $result['error']);
      return $result;
    }

    $mailbox = $this->imap_mailbox_string($type);
    $stream = @imap_open($mailbox, $username, $password, OP_SILENT, 1);
    if (!$stream) {
      $error = function_exists('imap_last_error') ? (string)imap_last_error() : 'Unable to open mailbox.';
      $result['error'] = sprintf('%s mailbox sync failed: %s', (string)($type['label'] ?? 'Ticket type'), $error);
      $this->finalize_mailbox_sync_state($type_key, $type, $sync_state, $result['error']);
      return $result;
    }

    $message_numbers = $this->mailbox_candidate_message_numbers($stream, $sync_state);
    $fingerprints = $this->normalize_sync_fingerprint_map($sync_state['fingerprints'] ?? []);

    foreach ($message_numbers as $message_number) {
      $context = $this->read_inbound_message_context($stream, (int)$message_number);
      $fingerprint = sanitize_text_field((string)($context['fingerprint'] ?? ''));
      $uid = max(0, (int)($context['uid'] ?? 0));
      $result['processed']++;
      if ($uid > (int)($sync_state['last_uid'] ?? 0)) $sync_state['last_uid'] = $uid;
      if ($fingerprint !== '' && isset($fingerprints[$fingerprint])) {
        $result['skipped']++;
        @imap_setflag_full($stream, (string)$message_number, '\\Seen');
        continue;
      }

      $processed = $this->process_inbound_message($stream, (int)$message_number, $type, $context);
      if (!empty($processed['created'])) $result['created']++;
      if (!empty($processed['updated'])) $result['updated']++;
      if (!empty($processed['duplicate'])) $result['skipped']++;
      if (!empty($processed['error'])) {
        if ($result['error'] === '') $result['error'] = (string)$processed['error'];
        continue;
      }

      if ($fingerprint !== '') $fingerprints[$fingerprint] = time();
      @imap_setflag_full($stream, (string)$message_number, '\\Seen');
    }

    imap_close($stream);
    $sync_state['fingerprints'] = $this->trim_sync_fingerprint_map($fingerprints);
    $this->finalize_mailbox_sync_state($type_key, $type, $sync_state, $result['error']);
    return $result;
  }

  private function process_inbound_message($stream, int $message_number, array $type, array $context = []): array {
    if (empty($context)) $context = $this->read_inbound_message_context($stream, $message_number);
    $subject = sanitize_text_field((string)($context['subject'] ?? ''));
    if ($subject === '') $subject = 'Support Request';
    $parts = $this->read_message_parts($stream, $message_number);
    $body = $this->extract_message_body_from_parts($parts, $stream, $message_number);
    $from_email = sanitize_email((string)($context['from_email'] ?? ''));
    $from_name = sanitize_text_field((string)($context['from_name'] ?? ''));
    if ($from_name === '' && $from_email !== '') $from_name = $from_email;
    $message_at = sanitize_text_field((string)($context['received_at'] ?? current_time('mysql')));
    $fingerprint = sanitize_text_field((string)($context['fingerprint'] ?? ''));
    $body_for_storage = $body !== '' ? $body : '(No readable message body captured.)';
    $message_payload = [
      'direction' => 'incoming',
      'subject' => $subject,
      'body' => $body_for_storage,
      'author_name' => $from_name,
      'author_email' => $from_email,
      'fingerprint' => $fingerprint,
      'at' => $message_at,
    ];

    $ticket_id = $this->ticket_id_from_subject($subject);
    $created = false;
    $updated = false;
    if ($ticket_id > 0 && get_post_type($ticket_id) === self::CPT_TICKET) {
      if ($this->ticket_has_matching_message($ticket_id, $message_payload)) {
        update_post_meta($ticket_id, self::META_LAST_SYNC_ERROR, '');
        return ['created' => false, 'updated' => false, 'duplicate' => true];
      }
      $updated = true;
      $clean_subject = $this->strip_ticket_reference($subject);
      if ($clean_subject !== '' && $clean_subject !== get_the_title($ticket_id)) {
        wp_update_post(['ID' => $ticket_id, 'post_title' => $clean_subject]);
      }
      if (in_array($this->ticket_status($ticket_id), ['resolved', 'closed'], true)) {
        update_post_meta($ticket_id, self::META_STATUS, 'open');
        $this->push_activity($ticket_id, 0, 'Ticket reopened by inbound email.');
      }
    } else {
      $existing_ticket_id = $this->find_ticket_by_existing_message($message_payload, $from_email);
      if ($existing_ticket_id > 0) {
        $ticket_id = $existing_ticket_id;
        $updated = true;
      }
    }

    if ($ticket_id <= 0) {
      $ticket_id = $this->create_ticket_from_mail($type, $subject, $body, $from_name, $from_email);
      $created = $ticket_id > 0;
    }

    if ($ticket_id <= 0) {
      return ['created' => false, 'updated' => false, 'error' => 'Inbound mail could not be converted into a ticket.'];
    }

    if ($this->ticket_has_matching_message($ticket_id, $message_payload)) {
      update_post_meta($ticket_id, self::META_LAST_SYNC_ERROR, '');
      return ['created' => false, 'updated' => false, 'duplicate' => true];
    }

    $attachments = $this->extract_message_attachments($parts, $ticket_id, $fingerprint);
    if (!empty($attachments)) $message_payload['attachments'] = $attachments;
    $this->append_message($ticket_id, $message_payload);
    update_post_meta($ticket_id, self::META_LAST_INBOUND_AT, $message_at);
    update_post_meta($ticket_id, self::META_LAST_SYNC_ERROR, '');
    $this->touch_ticket($ticket_id);
    $this->push_activity($ticket_id, 0, $created ? 'Ticket created from inbound email.' : 'Inbound email added to ticket.');

    if ($created && !empty($type['auto_ack_enabled']) && is_email($from_email)) {
      $this->send_auto_acknowledgement($ticket_id, $type, $from_email);
    }

    return ['created' => $created, 'updated' => !$created || $updated];
  }

  private function create_ticket_from_mail(array $type, string $subject, string $body, string $requester_name, string $requester_email): int {
    $title = $this->strip_ticket_reference($subject);
    if ($title === '') $title = $subject !== '' ? $subject : 'Support Request';
    $author_id = class_exists('EUM_Access') ? EUM_Access::forced_root_user_id() : 1;
    $ticket_id = wp_insert_post([
      'post_type' => self::CPT_TICKET,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field($title),
      'post_content' => sanitize_textarea_field($body),
      'post_author' => $author_id,
    ], true);
    if (is_wp_error($ticket_id) || (int)$ticket_id <= 0) return 0;
    $ticket_id = (int)$ticket_id;

    update_post_meta($ticket_id, self::META_TYPE, sanitize_key((string)($type['key'] ?? '')));
    update_post_meta($ticket_id, self::META_STATUS, 'open');
    update_post_meta($ticket_id, self::META_PRIORITY, sanitize_key((string)($type['default_priority'] ?? 'normal')));
    update_post_meta($ticket_id, self::META_REQUESTER_NAME, $requester_name);
    update_post_meta($ticket_id, self::META_REQUESTER_EMAIL, $requester_email);
    update_post_meta($ticket_id, self::META_SOURCE, 'email');
    update_post_meta($ticket_id, self::META_LAST_ACTIVITY_AT, current_time('mysql'));
    return $ticket_id;
  }

  private function send_auto_acknowledgement(int $ticket_id, array $type, string $to): void {
    $subject_tpl = (string)($type['auto_ack_subject'] ?? '');
    $body_tpl = (string)($type['auto_ack_body'] ?? '');
    if ($subject_tpl === '' || $body_tpl === '') return;
    $subject = $this->replace_ticket_placeholders($subject_tpl, $ticket_id, $type);
    $body = $this->replace_ticket_placeholders($body_tpl, $ticket_id, $type);
    if ($this->send_ticket_mail($type, $to, $subject, $body, $ticket_id)) {
      update_post_meta($ticket_id, self::META_LAST_OUTBOUND_AT, current_time('mysql'));
      $this->append_message($ticket_id, [
        'direction' => 'outgoing',
        'subject' => $subject,
        'body' => $body,
        'author_name' => sanitize_text_field((string)($type['outbound_from_name'] ?? $type['label'] ?? 'Ticket Desk')),
        'author_email' => sanitize_email((string)($type['outbound_from_email'] ?? '')),
        'at' => current_time('mysql'),
      ]);
      $this->push_activity($ticket_id, 0, 'Automatic acknowledgement sent.');
      $this->touch_ticket($ticket_id);
    }
  }

  private function send_ticket_mail(array $type, string $to, string $subject, string $body, int $ticket_id, array $cc = []): bool {
    if (!is_email($to)) return false;
    $transport = $this->mail_transport_settings_for_type($type);
    $from_name = sanitize_text_field((string)($type['outbound_from_name'] ?? $type['label'] ?? get_bloginfo('name')));
    $from_email = sanitize_email((string)($type['outbound_from_email'] ?? ''));
    $reply_to = sanitize_email((string)($type['outbound_reply_to'] ?? ($type['inbound_address'] ?? $from_email)));
    $headers = ['Content-Type: text/plain; charset=UTF-8', 'X-EUM-Ticket-Mail: 1'];
    $cc = $this->sanitize_email_list($cc, [$to]);
    if (!empty($cc)) $headers[] = 'X-EUM-Ticket-Cc: ' . implode(', ', $cc);
    if ($from_email !== '') $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    if ($reply_to !== '') $headers[] = 'Reply-To: ' . $reply_to;
    $this->active_mail_transport = $transport;
    if (($transport['mail_transport'] ?? 'wordpress') === 'smtp') {
      add_action('phpmailer_init', [$this, 'configure_phpmailer_transport']);
    }
    try {
      return wp_mail($to, $subject, $body, $headers);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) eum_log_message('[EUM Tickets] ' . $e->getMessage());
      return false;
    } finally {
      if (($transport['mail_transport'] ?? 'wordpress') === 'smtp') {
        remove_action('phpmailer_init', [$this, 'configure_phpmailer_transport']);
      }
      $this->active_mail_transport = null;
    }
  }

  public function configure_phpmailer_transport($phpmailer): void {
    $settings = is_array($this->active_mail_transport) ? $this->active_mail_transport : [];
    if (($settings['mail_transport'] ?? 'wordpress') !== 'smtp') return;
    $host = trim((string)($settings['smtp_host'] ?? ''));
    if ($host === '') return;
    $phpmailer->isSMTP();
    $phpmailer->Host = $host;
    $phpmailer->Port = max(1, (int)($settings['smtp_port'] ?? 587));
    $secure = (string)($settings['smtp_secure'] ?? 'tls');
    $phpmailer->SMTPSecure = $secure === 'none' ? '' : $secure;
    $phpmailer->SMTPAuth = !empty($settings['smtp_auth']);
    if ($phpmailer->SMTPAuth) {
      $phpmailer->Username = (string)($settings['smtp_username'] ?? '');
      $phpmailer->Password = (string)($settings['smtp_password'] ?? '');
    }
    $phpmailer->Timeout = max(5, (int)($settings['smtp_timeout'] ?? 20));
    $envelope = sanitize_email((string)($settings['smtp_from_envelope'] ?? ''));
    if ($envelope !== '') $phpmailer->Sender = $envelope;
  }

  public function filter_ticket_mail_headers(array $args): array {
    $headers = $this->normalize_mail_headers($args['headers'] ?? []);
    $is_ticket_mail = false;
    $ticket_cc = [];
    foreach ($headers as $line) {
      $line = (string)$line;
      if (stripos($line, 'X-EUM-Ticket-Mail:') === 0) {
        $is_ticket_mail = true;
        continue;
      }
      if (stripos($line, 'X-EUM-Ticket-Cc:') === 0) {
        $ticket_cc = array_merge($ticket_cc, $this->extract_email_list(trim(substr($line, strlen('X-EUM-Ticket-Cc:')))));
      }
    }
    if (!$is_ticket_mail) return $args;

    $filtered = [];
    foreach ($headers as $line) {
      $line = (string)$line;
      if (stripos($line, 'X-EUM-Ticket-Mail:') === 0) continue;
      if (stripos($line, 'X-EUM-Ticket-Cc:') === 0) continue;
      if (stripos($line, 'Cc:') === 0) continue;
      if (stripos($line, 'Bcc:') === 0) continue;
      $filtered[] = $line;
    }

    $to_addresses = $this->extract_mail_addresses($args['to'] ?? []);
    foreach ($this->sanitize_email_list($ticket_cc, $to_addresses) as $cc_email) {
      $filtered[] = 'Cc: ' . $cc_email;
    }

    $args['headers'] = is_array($args['headers'] ?? null) ? $filtered : implode("\r\n", $filtered);
    return $args;
  }

  private function mail_transport_settings_for_type(array $type): array {
    $mode = (string)($type['mail_transport'] ?? 'inherit');
    if ($mode === 'inherit' && class_exists('EUM_Email_Notifications')) {
      return EUM_Email_Notifications::settings();
    }
    if ($mode === 'smtp') {
      return [
        'mail_transport' => 'smtp',
        'smtp_host' => sanitize_text_field((string)($type['smtp_host'] ?? '')),
        'smtp_port' => preg_replace('/[^0-9]/', '', (string)($type['smtp_port'] ?? '587')),
        'smtp_secure' => in_array((string)($type['smtp_secure'] ?? 'tls'), ['none', 'tls', 'ssl'], true) ? (string)$type['smtp_secure'] : 'tls',
        'smtp_auth' => !empty($type['smtp_auth']) ? 1 : 0,
        'smtp_username' => sanitize_text_field((string)($type['smtp_username'] ?? '')),
        'smtp_password' => (string)($type['smtp_password'] ?? ''),
        'smtp_timeout' => preg_replace('/[^0-9]/', '', (string)($type['smtp_timeout'] ?? '20')),
        'smtp_from_envelope' => sanitize_email((string)($type['smtp_from_envelope'] ?? '')),
      ];
    }
    return ['mail_transport' => 'wordpress'];
  }

  private function replace_ticket_placeholders(string $text, int $ticket_id, array $type): string {
    $replacements = [
      '{{ticket_ref}}' => $this->ticket_reference($ticket_id),
      '{{ticket_subject}}' => (string)get_the_title($ticket_id),
      '{{ticket_type_label}}' => sanitize_text_field((string)($type['label'] ?? 'Ticket')),
      '{{ticket_status}}' => ucfirst($this->ticket_status($ticket_id)),
      '{{requester_name}}' => $this->requester_name($ticket_id) !== '' ? $this->requester_name($ticket_id) : 'there',
      '{{from_name}}' => sanitize_text_field((string)($type['outbound_from_name'] ?? $type['label'] ?? get_bloginfo('name'))),
    ];
    return str_replace(array_keys($replacements), array_values($replacements), $text);
  }

  private function append_message(int $ticket_id, array $message): void {
    $messages = $this->ticket_messages($ticket_id);
    $messages[] = $this->normalize_ticket_message($message);
    update_post_meta($ticket_id, self::META_MESSAGES, array_slice($messages, -200));
  }

  private function normalize_ticket_message(array $message): array {
    return [
      'direction' => sanitize_key((string)($message['direction'] ?? 'internal')),
      'subject' => sanitize_text_field((string)($message['subject'] ?? '')),
      'body' => sanitize_textarea_field((string)($message['body'] ?? '')),
      'author_name' => sanitize_text_field((string)($message['author_name'] ?? '')),
      'author_email' => sanitize_email((string)($message['author_email'] ?? '')),
      'cc' => $this->sanitize_email_list($message['cc'] ?? []),
      'fingerprint' => sanitize_text_field((string)($message['fingerprint'] ?? '')),
      'at' => sanitize_text_field((string)($message['at'] ?? current_time('mysql'))),
      'attachments' => $this->normalize_ticket_attachments($message['attachments'] ?? []),
    ];
  }

  private function normalize_ticket_attachments($attachments): array {
    if (!is_array($attachments)) return [];
    $clean = [];
    foreach ($attachments as $attachment) {
      if (!is_array($attachment)) continue;
      $name = sanitize_file_name((string)($attachment['name'] ?? ''));
      $url = esc_url_raw((string)($attachment['url'] ?? ''));
      if ($name === '' && $url !== '') {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $name = sanitize_file_name(basename($path));
      }
      if ($name === '' || $url === '') continue;
      $clean[] = [
        'name' => $name,
        'path' => wp_normalize_path((string)($attachment['path'] ?? '')),
        'url' => $url,
        'type' => sanitize_text_field((string)($attachment['type'] ?? 'application/octet-stream')),
        'size' => max(0, (int)($attachment['size'] ?? 0)),
        'uploaded_at' => sanitize_text_field((string)($attachment['uploaded_at'] ?? '')),
      ];
    }
    return array_values($clean);
  }

  private function push_activity(int $ticket_id, int $user_id, string $message): void {
    $activity = $this->ticket_activity($ticket_id);
    $activity[] = [
      'user_id' => max(0, $user_id),
      'message' => sanitize_text_field($message),
      'at' => current_time('mysql'),
    ];
    update_post_meta($ticket_id, self::META_ACTIVITY, array_slice($activity, -120));
  }

  private function ticket_messages(int $ticket_id): array {
    $messages = get_post_meta($ticket_id, self::META_MESSAGES, true);
    if (!is_array($messages)) return [];
    $clean = [];
    foreach ($messages as $message) {
      if (!is_array($message)) continue;
      $clean[] = $this->normalize_ticket_message($message);
    }
    return array_values($clean);
  }

  private function ticket_has_matching_message(int $ticket_id, array $candidate): bool {
    $direction = sanitize_key((string)($candidate['direction'] ?? 'incoming'));
    $subject = sanitize_text_field((string)($candidate['subject'] ?? ''));
    $body = sanitize_textarea_field((string)($candidate['body'] ?? ''));
    $author_email = sanitize_email((string)($candidate['author_email'] ?? ''));
    $fingerprint = sanitize_text_field((string)($candidate['fingerprint'] ?? ''));
    foreach ($this->ticket_messages($ticket_id) as $message) {
      if (!is_array($message)) continue;
      if ($fingerprint !== '' && sanitize_text_field((string)($message['fingerprint'] ?? '')) === $fingerprint) return true;
      if (sanitize_key((string)($message['direction'] ?? '')) !== $direction) continue;
      if (sanitize_text_field((string)($message['subject'] ?? '')) !== $subject) continue;
      if (sanitize_email((string)($message['author_email'] ?? '')) !== $author_email) continue;
      if (sanitize_textarea_field((string)($message['body'] ?? '')) !== $body) continue;
      return true;
    }
    return false;
  }

  private function find_ticket_by_existing_message(array $candidate, string $requester_email = ''): int {
    $ticket_ids = get_posts([
      'post_type' => self::CPT_TICKET,
      'post_status' => 'publish',
      'numberposts' => 300,
      'orderby' => 'modified',
      'order' => 'DESC',
      'fields' => 'ids',
    ]);

    foreach ($ticket_ids as $ticket_id) {
      $ticket_id = (int)$ticket_id;
      if ($ticket_id <= 0) continue;
      if ($requester_email !== '' && $this->requester_email($ticket_id) !== $requester_email) continue;
      if ($this->ticket_has_matching_message($ticket_id, $candidate)) return $ticket_id;
    }

    return 0;
  }

  private function ticket_activity(int $ticket_id): array {
    $activity = get_post_meta($ticket_id, self::META_ACTIVITY, true);
    return is_array($activity) ? $activity : [];
  }

  private function ticket_conversation_excerpt(int $ticket_id): string {
    $messages = $this->ticket_messages($ticket_id);
    if (empty($messages)) return '';
    $last = end($messages);
    if (!is_array($last)) return '';
    return wp_trim_words(trim((string)($last['body'] ?? '')), 24, '...');
  }

  private function ticket_last_activity_at(int $ticket_id): string {
    $stored = sanitize_text_field((string)get_post_meta($ticket_id, self::META_LAST_ACTIVITY_AT, true));
    if ($stored !== '') return $stored;
    $post = get_post($ticket_id);
    return $post ? (string)$post->post_modified : '';
  }

  private function touch_ticket(int $ticket_id): void {
    update_post_meta($ticket_id, self::META_LAST_ACTIVITY_AT, current_time('mysql'));
  }

  private function count_tickets_by_status(int $user_id, string $status): int {
    $tickets = $this->visible_tickets_for_user($user_id, ['status' => $status, 'type' => '', 'q' => '']);
    return count($tickets);
  }

  private function visible_tickets_for_user(int $user_id, array $filters = []): array {
    if (!$this->user_can_access_portal($user_id)) return [];
    $tickets = get_posts([
      'post_type' => self::CPT_TICKET,
      'post_status' => 'publish',
      'numberposts' => 250,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    $status_filter = sanitize_key((string)($filters['status'] ?? 'open'));
    $type_filter = sanitize_key((string)($filters['type'] ?? ''));
    $search = strtolower(trim((string)($filters['q'] ?? '')));
    $person_search = strtolower(trim((string)($filters['person'] ?? '')));
    $assignee_filter_user_id = max(0, (int)($filters['assignee_user_id'] ?? 0));
    $include_assigned_to_me = !empty($filters['assigned_to_me']);
    $include_unassigned = !empty($filters['unassigned']);
    $has_assignment_filter = $include_assigned_to_me || $include_unassigned || $assignee_filter_user_id > 0;

    $tickets = array_values(array_filter($tickets, function($ticket) use ($status_filter, $type_filter, $search, $person_search, $assignee_filter_user_id, $include_assigned_to_me, $include_unassigned, $has_assignment_filter, $user_id) {
      if (!$ticket instanceof WP_Post) return false;
      $ticket_id = (int)$ticket->ID;
      $status = $this->ticket_status($ticket_id);
      $type_key = $this->ticket_type_key($ticket_id);
      $assigned_user_id = (int)get_post_meta($ticket_id, self::META_ASSIGNEE_USER_ID, true);
      $people_haystack = $this->ticket_people_haystack($ticket_id);
      $haystack = strtolower(trim(implode(' ', array_filter([
        (string)$ticket->post_title,
        $this->ticket_reference($ticket_id),
        $this->requester_name($ticket_id),
        $this->requester_email($ticket_id),
        $this->ticket_conversation_excerpt($ticket_id),
        $people_haystack,
      ]))));
      if ($status_filter !== '' && $status_filter !== 'all' && $status !== $status_filter) return false;
      if ($type_filter !== '' && $type_key !== $type_filter) return false;
      if ($has_assignment_filter) {
        $matches_assignment = false;
        if ($include_assigned_to_me && $assigned_user_id === $user_id) $matches_assignment = true;
        if ($include_unassigned && $assigned_user_id <= 0) $matches_assignment = true;
        if ($assignee_filter_user_id > 0 && $assigned_user_id === $assignee_filter_user_id) $matches_assignment = true;
        if (!$matches_assignment) return false;
      }
      if ($person_search !== '' && strpos($people_haystack, $person_search) === false) return false;
      if ($search !== '' && strpos($haystack, $search) === false) return false;
      return true;
    }));

    usort($tickets, function($a, $b) {
      return strcmp($this->ticket_last_activity_at((int)$b->ID), $this->ticket_last_activity_at((int)$a->ID));
    });

    return $tickets;
  }

  private function user_can_access_portal(int $user_id): bool {
    return class_exists('EUM_Access') && EUM_Access::is_it_or_hr($user_id);
  }

  private function user_can_view_ticket(int $ticket_id, int $user_id): bool {
    if ($ticket_id <= 0 || $user_id <= 0) return false;
    if (!$this->user_can_access_portal($user_id)) return false;
    return get_post_type($ticket_id) === self::CPT_TICKET;
  }

  private function ticket_type_key(int $ticket_id): string {
    return sanitize_key((string)get_post_meta($ticket_id, self::META_TYPE, true));
  }

  private function ticket_status(int $ticket_id): string {
    $status = sanitize_key((string)get_post_meta($ticket_id, self::META_STATUS, true));
    return array_key_exists($status, $this->status_options()) ? $status : 'open';
  }

  private function ticket_priority(int $ticket_id): string {
    $priority = sanitize_key((string)get_post_meta($ticket_id, self::META_PRIORITY, true));
    return array_key_exists($priority, $this->priority_options()) ? $priority : 'normal';
  }

  private function requester_name(int $ticket_id): string {
    return sanitize_text_field((string)get_post_meta($ticket_id, self::META_REQUESTER_NAME, true));
  }

  private function requester_email(int $ticket_id): string {
    return sanitize_email((string)get_post_meta($ticket_id, self::META_REQUESTER_EMAIL, true));
  }

  private function cc_request_payload(string $prefix = ''): array {
    return [
      'requester_personal' => !empty($_POST[$prefix . 'cc_requester_personal']),
      'requester_work' => !empty($_POST[$prefix . 'cc_requester_work']),
      'manager_work' => !empty($_POST[$prefix . 'cc_manager_work']),
      'manager_personal' => !empty($_POST[$prefix . 'cc_manager_personal']),
      'other' => (string)wp_unslash($_POST[$prefix . 'cc_other'] ?? ''),
    ];
  }

  private function resolve_cc_emails_for_ticket(int $ticket_id, array $cc_request, string $to = ''): array {
    $resolved = $this->ticket_cc_option_addresses($ticket_id);
    $emails = [];
    if (!empty($cc_request['requester_personal'])) $emails[] = (string)($resolved['requester_personal'] ?? '');
    if (!empty($cc_request['requester_work'])) $emails[] = (string)($resolved['requester_work'] ?? '');
    if (!empty($cc_request['manager_work'])) $emails[] = (string)($resolved['manager_work'] ?? '');
    if (!empty($cc_request['manager_personal'])) $emails[] = (string)($resolved['manager_personal'] ?? '');
    $emails = array_merge($emails, $this->extract_email_list((string)($cc_request['other'] ?? '')));
    return $this->sanitize_email_list($emails, $to !== '' ? [$to] : []);
  }

  private function ticket_cc_option_addresses(int $ticket_id): array {
    $requester_user_id = $this->ticket_requester_user_id($ticket_id);
    $manager_user_id = $requester_user_id > 0 ? $this->manager_user_id_for_user($requester_user_id) : 0;
    return [
      'requester_personal' => $this->personal_email_for_user($requester_user_id),
      'requester_work' => $this->work_email_for_user($requester_user_id),
      'manager_work' => $this->work_email_for_user($manager_user_id),
      'manager_personal' => $this->personal_email_for_user($manager_user_id),
    ];
  }

  private function ticket_requester_user_id(int $ticket_id): int {
    return $this->resolve_ticket_person_user_id($this->requester_email($ticket_id), $this->requester_name($ticket_id));
  }

  private function resolve_ticket_person_user_id(string $email, string $name = ''): int {
    static $cache = [];
    $key = strtolower(trim($email)) . '|' . $this->normalize_search_text($name);
    if (array_key_exists($key, $cache)) return (int)$cache[$key];

    $user_id = 0;
    if (is_email($email)) {
      $user = get_user_by('email', $email);
      if ($user instanceof WP_User) {
        $user_id = (int)$user->ID;
      }
      if ($user_id <= 0) $user_id = $this->user_id_by_personal_email($email);
    }
    if ($user_id <= 0 && $name !== '') {
      $user_id = $this->user_id_by_name($name);
    }

    $cache[$key] = $user_id;
    return $user_id;
  }

  private function user_id_by_personal_email(string $email): int {
    if (!class_exists('EUM_Fields') || !is_email($email)) return 0;

    $candidates = get_users([
      'number' => 10,
      'meta_query' => [
        [
          'key' => EUM_Fields::USER_META_KEY,
          'value' => sanitize_email($email),
          'compare' => 'LIKE',
        ],
      ],
    ]);

    foreach ((array)$candidates as $candidate) {
      if (!$candidate instanceof WP_User) continue;
      if (strcasecmp($this->personal_email_for_user((int)$candidate->ID), $email) === 0) {
        return (int)$candidate->ID;
      }
    }

    return 0;
  }

  private function user_id_by_name(string $name): int {
    $name = $this->normalize_search_text($name);
    if ($name === '') return 0;

    $candidates = get_users([
      'number' => 20,
      'search' => '*' . $name . '*',
      'search_columns' => ['display_name', 'user_login', 'user_nicename'],
    ]);

    $matches = [];
    foreach ((array)$candidates as $candidate) {
      if (!$candidate instanceof WP_User) continue;
      $candidate_terms = $this->user_people_terms((int)$candidate->ID);
      if (in_array($name, $candidate_terms, true)) $matches[] = (int)$candidate->ID;
    }

    return count($matches) === 1 ? (int)$matches[0] : 0;
  }

  private function resolve_assignee_filter_user_id(string $raw): int {
    $raw = trim($raw);
    if ($raw === '') return 0;
    if (ctype_digit($raw)) {
      $user_id = (int)$raw;
      return $this->user_can_access_portal($user_id) ? $user_id : 0;
    }

    $normalized = $this->normalize_search_text($raw);
    foreach ($this->assignee_options() as $user_id => $label) {
      if ($this->normalize_search_text((string)$label) === $normalized) return (int)$user_id;
    }

    return 0;
  }

  private function ticket_people_haystack(int $ticket_id): string {
    static $cache = [];
    if (isset($cache[$ticket_id])) return (string)$cache[$ticket_id];

    $terms = [
      $this->requester_name($ticket_id),
      $this->requester_email($ticket_id),
    ];

    $requester_user_id = $this->ticket_requester_user_id($ticket_id);
    if ($requester_user_id > 0) {
      $terms = array_merge($terms, $this->user_people_terms($requester_user_id));
      $manager_user_id = $this->manager_user_id_for_user($requester_user_id);
      if ($manager_user_id > 0) {
        $terms = array_merge($terms, $this->user_people_terms($manager_user_id));
      }
    }

    $assignee_user_id = (int)get_post_meta($ticket_id, self::META_ASSIGNEE_USER_ID, true);
    if ($assignee_user_id > 0) {
      $terms = array_merge($terms, $this->user_people_terms($assignee_user_id));
    }

    $cache[$ticket_id] = strtolower(trim(implode(' ', array_values(array_unique(array_filter(array_map('strval', $terms)))))));
    return (string)$cache[$ticket_id];
  }

  private function user_people_terms(int $user_id): array {
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];

    $user = $user_id > 0 ? get_user_by('id', $user_id) : false;
    if (!$user instanceof WP_User) return $cache[$user_id] = [];

    $first_name = sanitize_text_field((string)get_user_meta($user_id, 'first_name', true));
    $last_name = sanitize_text_field((string)get_user_meta($user_id, 'last_name', true));
    $full_name = trim($first_name . ' ' . $last_name);
    $terms = [
      $user->display_name,
      $user->user_login,
      $user->user_nicename,
      $user->user_email,
      $this->personal_email_for_user($user_id),
      $first_name,
      $last_name,
      $full_name,
    ];

    $normalized = [];
    foreach ($terms as $term) {
      $clean = $this->normalize_search_text((string)$term);
      if ($clean !== '') $normalized[$clean] = $clean;
    }
    return $cache[$user_id] = array_values($normalized);
  }

  private function normalize_search_text(string $value): string {
    $value = strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    return sanitize_text_field($value);
  }

  private function personal_email_for_user(int $user_id): string {
    if ($user_id <= 0 || !class_exists('EUM_Fields')) return '';
    $custom = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    return sanitize_email((string)($custom['personal_email'] ?? ''));
  }

  private function work_email_for_user(int $user_id): string {
    $user = $user_id > 0 ? get_user_by('id', $user_id) : false;
    return $user instanceof WP_User ? sanitize_email((string)$user->user_email) : '';
  }

  private function manager_user_id_for_user(int $user_id): int {
    if ($user_id <= 0 || !class_exists('EUM_Personnel')) return 0;
    return max(0, (int)EUM_Personnel::effective_manager_user_id($user_id));
  }

  private function ticket_type_options(): array {
    $options = [];
    foreach ((array)self::get_settings()['ticket_types'] as $type) {
      if (!is_array($type)) continue;
      $key = sanitize_key((string)($type['key'] ?? ''));
      $label = sanitize_text_field((string)($type['label'] ?? ''));
      if ($key !== '' && $label !== '') $options[$key] = $label;
    }
    return $options;
  }

  private function ticket_type_label(string $type_key): string {
    $options = $this->ticket_type_options();
    return (string)($options[$type_key] ?? ($type_key !== '' ? ucfirst(str_replace('_', ' ', $type_key)) : 'Ticket'));
  }

  private function ticket_type_config(string $type_key): array {
    foreach ((array)self::get_settings()['ticket_types'] as $type) {
      if (!is_array($type)) continue;
      if (sanitize_key((string)($type['key'] ?? '')) === $type_key) return $type;
    }
    return [];
  }

  private function assignee_options(): array {
    $options = [];
    foreach (get_users(['number' => 500, 'orderby' => 'display_name', 'order' => 'ASC']) as $user) {
      if (!($user instanceof WP_User)) continue;
      if (!$this->user_can_access_portal((int)$user->ID)) continue;
      $name = $user->display_name !== '' ? $user->display_name : $user->user_login;
      $email = sanitize_email((string)$user->user_email);
      $options[(string)$user->ID] = $email !== '' ? $name . ' (' . $email . ')' : $name;
    }
    asort($options);
    return $options;
  }

  private function user_label(int $user_id): string {
    $user = $user_id > 0 ? get_user_by('id', $user_id) : false;
    if (!$user) return 'Unknown user';
    return $user->display_name !== '' ? $user->display_name : $user->user_login;
  }

  private function actor_email(int $user_id, array $type): string {
    $user = $user_id > 0 ? get_user_by('id', $user_id) : false;
    if ($user && is_email((string)$user->user_email)) return sanitize_email((string)$user->user_email);
    $fallback = sanitize_email((string)($type['outbound_from_email'] ?? ''));
    return $fallback;
  }

  private function status_options(): array {
    return [
      'open' => 'Open',
      'pending' => 'Pending',
      'resolved' => 'Resolved',
      'closed' => 'Closed',
    ];
  }

  private function priority_options(): array {
    return [
      'low' => 'Low',
      'normal' => 'Normal',
      'high' => 'High',
      'urgent' => 'Urgent',
    ];
  }

  private function select_options(array $options, string $selected_value): string {
    $html = '';
    foreach ($options as $value => $label) {
      $html .= '<option value="' . esc_attr((string)$value) . '" ' . selected((string)$selected_value, (string)$value, false) . '>' . esc_html((string)$label) . '</option>';
    }
    return $html;
  }

  private function status_badge_style(string $status): string {
    $map = [
      'open' => 'display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:13px;font-weight:600;',
      'pending' => 'display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:13px;font-weight:600;',
      'resolved' => 'display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#dcfce7;color:#166534;font-size:13px;font-weight:600;',
      'closed' => 'display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#e5e7eb;color:#374151;font-size:13px;font-weight:600;',
    ];
    return (string)($map[$status] ?? $map['open']);
  }

  private function human_datetime(string $datetime): string {
    $ts = $datetime !== '' ? strtotime($datetime) : false;
    return $ts ? date_i18n('Y-m-d g:i A', $ts) : 'Unknown';
  }

  private function ticket_reference(int $ticket_id): string {
    return 'TCK-' . $ticket_id;
  }

  private function ticket_id_from_subject(string $subject): int {
    if (preg_match('/\bTCK-(\d+)\b/i', $subject, $matches)) return max(0, (int)$matches[1]);
    return 0;
  }

  private function strip_ticket_reference(string $subject): string {
    $subject = preg_replace('/\[\s*TCK-\d+\s*\]/i', '', $subject);
    $subject = preg_replace('/\bTCK-\d+\b/i', '', (string)$subject);
    $subject = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', (string)$subject);
    return sanitize_text_field(trim((string)$subject, " \t\n\r\0\x0B-[]"));
  }

  private function build_reply_subject(int $ticket_id, string $title): string {
    $clean = $this->strip_ticket_reference($title);
    if ($clean === '') $clean = $title !== '' ? $title : 'Support Ticket';
    return 'Re: ' . $clean . ' [' . $this->ticket_reference($ticket_id) . ']';
  }

  private function current_url(): string {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : parse_url(home_url('/'), PHP_URL_HOST);
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
    return $scheme . $host . $request_uri;
  }

  private function redirect_admin(string $url, string $message, string $type = 'success', string $details = ''): void {
    $args = [
      'eum_ticket_msg' => $message,
      'eum_ticket_type' => $type,
    ];
    if ($details !== '') $args['eum_ticket_details'] = $details;
    $target = add_query_arg($args, $url);
    wp_safe_redirect($target);
    exit;
  }

  private function redirect_portal(string $url, string $message, string $type = 'success'): void {
    $base = remove_query_arg(['eum_ticket_notice', 'eum_ticket_notice_type'], $url);
    $target = add_query_arg([
      'eum_ticket_notice' => $message,
      'eum_ticket_notice_type' => $type,
    ], $base);
    wp_safe_redirect($target);
    exit;
  }

  private function test_ticket_type_connections(array $type): array {
    $imap = $this->test_ticket_imap_connection($type);
    $outbound = $this->test_ticket_outbound_connection($type);
    $tested = !empty($imap['tested']) || !empty($outbound['tested']);
    $success = empty($imap['failed']) && empty($outbound['failed']) && $tested;
    $message = '';
    if (!$tested) {
      $message = 'No direct ticket server handshake could be run for this queue. Enable inbound polling or use SMTP transport for outbound mail.';
    } elseif (!empty($imap['failed'])) {
      $message = (string)($imap['message'] ?? 'Inbound mailbox connection failed.');
    } elseif (!empty($outbound['failed'])) {
      $message = (string)($outbound['message'] ?? 'Outbound mail connection failed.');
    }

    return [
      'success' => $success,
      'message' => $message,
      'imap' => $imap,
      'outbound' => $outbound,
    ];
  }

  private function test_ticket_imap_connection(array $type): array {
    if (empty($type['inbound_enabled'])) {
      return [
        'tested' => false,
        'failed' => false,
        'status' => 'skipped',
        'message' => 'Inbound polling is disabled for this queue.',
      ];
    }
    if (!function_exists('imap_open')) {
      return [
        'tested' => true,
        'failed' => true,
        'status' => 'failed',
        'message' => 'IMAP PHP extension is not available on this server.',
      ];
    }

    $host = trim((string)($type['imap_host'] ?? ''));
    $username = trim((string)($type['imap_username'] ?? ''));
    $password = (string)($type['imap_password'] ?? '');
    if ($host === '' || $username === '' || $password === '') {
      return [
        'tested' => true,
        'failed' => true,
        'status' => 'failed',
        'message' => 'IMAP host, username, and password are required for the inbound mailbox test.',
      ];
    }

    if (function_exists('imap_errors')) @imap_errors();
    if (function_exists('imap_alerts')) @imap_alerts();
    $mailbox = $this->imap_mailbox_string($type);
    $stream = @imap_open($mailbox, $username, $password, OP_SILENT, 1);
    if (!$stream) {
      $error = function_exists('imap_last_error') ? trim((string)imap_last_error()) : '';
      if ($error === '' && function_exists('imap_errors')) {
        $errors = @imap_errors();
        $error = is_array($errors) && !empty($errors) ? trim((string)end($errors)) : '';
      }
      return [
        'tested' => true,
        'failed' => true,
        'status' => 'failed',
        'message' => $error !== '' ? $error : 'Unable to open the IMAP mailbox.',
        'target' => $this->imap_target_label($type),
      ];
    }

    $message_count = function_exists('imap_num_msg') ? max(0, (int)@imap_num_msg($stream)) : 0;
    $unseen = @imap_search($stream, 'UNSEEN');
    $unseen_count = is_array($unseen) ? count($unseen) : 0;
    $recent_subjects = $this->imap_recent_subjects($stream, $message_count, 5);
    @imap_close($stream);
    return [
      'tested' => true,
      'failed' => false,
      'status' => 'passed',
      'message' => $message_count > 0
        ? sprintf('Connected successfully. Mailbox contains %d messages (%d unread).', $message_count, $unseen_count)
        : 'Connected successfully. Mailbox currently contains 0 messages.',
      'target' => $this->imap_target_label($type),
      'message_count' => $message_count,
      'unseen_count' => $unseen_count,
      'recent_subjects' => $recent_subjects,
    ];
  }

  private function test_ticket_outbound_connection(array $type): array {
    $transport = $this->mail_transport_settings_for_type($type);
    $transport_mode = (string)($transport['mail_transport'] ?? 'wordpress');
    $source = (string)($type['mail_transport'] ?? 'inherit');

    if ($transport_mode !== 'smtp') {
      return [
        'tested' => false,
        'failed' => false,
        'status' => 'skipped',
        'message' => $source === 'inherit'
          ? 'Outbound mail inherits the shared WordPress transport, so there is no dedicated SMTP server to probe from this queue.'
          : 'Outbound mail uses the WordPress default transport, so there is no dedicated SMTP server to probe from this queue.',
      ];
    }

    $host = trim((string)($transport['smtp_host'] ?? ''));
    if ($host === '') {
      return [
        'tested' => true,
        'failed' => true,
        'status' => 'failed',
        'message' => 'SMTP host is required for the outbound mail test.',
      ];
    }

    try {
      $this->ensure_phpmailer_classes_loaded();
      $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
      $mailer->isSMTP();
      $mailer->Host = $host;
      $mailer->Port = max(1, (int)($transport['smtp_port'] ?? 587));
      $secure = (string)($transport['smtp_secure'] ?? 'tls');
      $mailer->SMTPSecure = $secure === 'none' ? '' : $secure;
      $mailer->SMTPAutoTLS = $secure !== 'none';
      $mailer->SMTPAuth = !empty($transport['smtp_auth']);
      if ($mailer->SMTPAuth) {
        $mailer->Username = (string)($transport['smtp_username'] ?? '');
        $mailer->Password = (string)($transport['smtp_password'] ?? '');
      }
      $mailer->Timeout = max(5, (int)($transport['smtp_timeout'] ?? 20));
      $connected = $mailer->smtpConnect();
      if (!$connected) {
        $error = trim((string)$mailer->ErrorInfo);
        $mailer->smtpClose();
        return [
          'tested' => true,
          'failed' => true,
          'status' => 'failed',
          'message' => $error !== '' ? $error : 'SMTP connection failed.',
          'target' => $this->smtp_target_label($transport, $source),
        ];
      }

      $mailer->smtpClose();
      return [
        'tested' => true,
        'failed' => false,
        'status' => 'passed',
        'message' => 'Connected successfully.',
        'target' => $this->smtp_target_label($transport, $source),
      ];
    } catch (\Throwable $e) {
      return [
        'tested' => true,
        'failed' => true,
        'status' => 'failed',
        'message' => sanitize_text_field($e->getMessage()),
        'target' => $this->smtp_target_label($transport, $source),
      ];
    }
  }

  private function ensure_phpmailer_classes_loaded(): void {
    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    if (!class_exists('\PHPMailer\PHPMailer\SMTP')) require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    if (!class_exists('\PHPMailer\PHPMailer\Exception')) require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
  }

  private function imap_recent_subjects($stream, int $message_count, int $limit = 5): array {
    if ($message_count <= 0 || $limit <= 0) return [];
    $subjects = [];
    $start = max(1, $message_count - $limit + 1);
    for ($message_number = $message_count; $message_number >= $start; $message_number--) {
      $overview_rows = @imap_fetch_overview($stream, (string)$message_number, 0);
      $overview = is_array($overview_rows) && !empty($overview_rows[0]) ? $overview_rows[0] : null;
      $raw_subject = is_object($overview) && isset($overview->subject) ? (string)$overview->subject : '';
      $subject = $this->decode_mail_header($raw_subject);
      if ($subject === '') $subject = '(No subject)';
      $subjects[] = sanitize_text_field($subject);
    }
    return $subjects;
  }

  private function imap_target_label(array $type): string {
    $host = trim((string)($type['imap_host'] ?? ''));
    $port = max(1, (int)($type['imap_port'] ?? 993));
    $mailbox = $this->supported_imap_mailbox();
    return $host !== '' ? ($host . ':' . $port . '/' . $mailbox) : $mailbox;
  }

  private function smtp_target_label(array $transport, string $source): string {
    $host = trim((string)($transport['smtp_host'] ?? ''));
    $port = max(1, (int)($transport['smtp_port'] ?? 587));
    $secure = (string)($transport['smtp_secure'] ?? 'tls');
    $prefix = $source === 'inherit' ? 'Inherited SMTP' : 'Queue SMTP';
    return $prefix . ': ' . $host . ':' . $port . ($secure !== 'none' ? ' (' . strtoupper($secure) . ')' : '');
  }

  private function ticket_queue_test_label(array $type): string {
    $label = sanitize_text_field((string)($type['label'] ?? ''));
    if ($label !== '') return $label;
    $key = sanitize_key((string)($type['key'] ?? ''));
    if ($key !== '') return ucfirst(str_replace('_', ' ', $key));
    return 'Selected ticket queue';
  }

  private function format_ticket_connection_test_details(array $result, array $type): string {
    $lines = [];
    $lines[] = 'Queue: ' . $this->ticket_queue_test_label($type);

    $imap = is_array($result['imap'] ?? null) ? $result['imap'] : [];
    $outbound = is_array($result['outbound'] ?? null) ? $result['outbound'] : [];

    $lines[] = 'Inbound IMAP: ' . ucfirst((string)($imap['status'] ?? 'skipped'));
    if (!empty($imap['target'])) $lines[] = 'Inbound Target: ' . sanitize_text_field((string)$imap['target']);
    if (!empty($imap['message'])) $lines[] = 'Inbound Detail: ' . sanitize_text_field((string)$imap['message']);
    if (isset($imap['message_count'])) $lines[] = 'Inbound Message Count: ' . (int)$imap['message_count'];
    if (isset($imap['unseen_count'])) $lines[] = 'Inbound Unread Count: ' . (int)$imap['unseen_count'];
    if (!empty($imap['recent_subjects']) && is_array($imap['recent_subjects'])) {
      $lines[] = 'Recent Subjects:';
      foreach ((array)$imap['recent_subjects'] as $subject) {
        $lines[] = '- ' . sanitize_text_field((string)$subject);
      }
    }

    $lines[] = 'Outbound Mail: ' . ucfirst((string)($outbound['status'] ?? 'skipped'));
    if (!empty($outbound['target'])) $lines[] = 'Outbound Target: ' . sanitize_text_field((string)$outbound['target']);
    if (!empty($outbound['message'])) $lines[] = 'Outbound Detail: ' . sanitize_text_field((string)$outbound['message']);

    if (!empty($result['message'])) $lines[] = 'Summary: ' . sanitize_text_field((string)$result['message']);
    return implode("\n", array_filter($lines, static fn($line) => trim((string)$line) !== ''));
  }

  private function mail_sync_capability_summary(): array {
    return [
      'imap' => function_exists('imap_open') ? 'Available' : 'Missing (PHP IMAP extension not loaded)',
    ];
  }

  private function mailbox_sync_state(string $type_key): array {
    if ($type_key === '') return [];
    $states = get_option(self::OPTION_SYNC_STATE, []);
    $states = is_array($states) ? $states : [];
    $state = $states[$type_key] ?? [];
    return is_array($state) ? $state : [];
  }

  private function save_mailbox_sync_state(string $type_key, array $state): void {
    if ($type_key === '') return;
    $states = get_option(self::OPTION_SYNC_STATE, []);
    $states = is_array($states) ? $states : [];
    $states[$type_key] = [
      'last_run_at' => sanitize_text_field((string)($state['last_run_at'] ?? '')),
      'last_error' => sanitize_text_field((string)($state['last_error'] ?? '')),
      'last_uid' => max(0, (int)($state['last_uid'] ?? 0)),
      'fingerprints' => $this->trim_sync_fingerprint_map($this->normalize_sync_fingerprint_map($state['fingerprints'] ?? [])),
    ];
    update_option(self::OPTION_SYNC_STATE, $states, false);
  }

  private function finalize_mailbox_sync_state(string $type_key, array $type, array $state, string $error): void {
    if ($type_key === '') return;
    $previous_error = sanitize_text_field((string)($state['last_error'] ?? ''));
    $state['last_run_at'] = current_time('mysql');
    $state['last_error'] = $error;
    $this->save_mailbox_sync_state($type_key, $state);

    if ($error !== '' && $error !== $previous_error) {
      eum_log_message('[EUM Tickets] ' . sanitize_text_field($error));
      return;
    }

    if ($error === '' && $previous_error !== '') {
      $label = sanitize_text_field((string)($type['label'] ?? ($type_key !== '' ? $type_key : 'Ticket mailbox')));
      eum_log_message('[EUM Tickets] ' . $label . ' mailbox sync recovered.');
    }
  }

  private function normalize_sync_fingerprint_map($fingerprints): array {
    if (!is_array($fingerprints)) return [];
    $normalized = [];
    foreach ($fingerprints as $fingerprint => $seen_at) {
      $key = sanitize_text_field((string)$fingerprint);
      if ($key === '') continue;
      $normalized[$key] = max(1, (int)$seen_at);
    }
    arsort($normalized, SORT_NUMERIC);
    return $normalized;
  }

  private function trim_sync_fingerprint_map(array $fingerprints): array {
    if (empty($fingerprints)) return [];
    arsort($fingerprints, SORT_NUMERIC);
    return array_slice($fingerprints, 0, self::SYNC_FINGERPRINT_LIMIT, true);
  }

  private function mailbox_search_queries(array $sync_state): array {
    return ['UNSEEN'];
  }

  private function mailbox_candidate_message_numbers($stream, array $sync_state): array {
    $message_numbers = [];
    foreach ($this->mailbox_search_queries($sync_state) as $query) {
      $query = trim((string)$query);
      if ($query === '') continue;
      $found = @imap_search($stream, $query);
      if (!is_array($found)) continue;
      foreach ($found as $message_number) {
        $message_number = (int)$message_number;
        if ($message_number > 0) $message_numbers[$message_number] = $message_number;
      }
    }

    $message_count = function_exists('imap_num_msg') ? max(0, (int)@imap_num_msg($stream)) : 0;
    if ($message_count > 0) {
      $last_uid = max(0, (int)($sync_state['last_uid'] ?? 0));
      if ($last_uid > 0 && function_exists('imap_uid')) {
        for ($message_number = $message_count; $message_number >= 1; $message_number--) {
          $uid = max(0, (int)@imap_uid($stream, $message_number));
          if ($uid <= 0) continue;
          if ($uid > $last_uid) {
            $message_numbers[$message_number] = $message_number;
            continue;
          }
          break;
        }
      }
    }

    ksort($message_numbers, SORT_NUMERIC);
    return array_values($message_numbers);
  }

  private function read_inbound_message_context($stream, int $message_number): array {
    $overview_rows = @imap_fetch_overview($stream, (string)$message_number, 0);
    $overview = is_array($overview_rows) && !empty($overview_rows[0]) ? $overview_rows[0] : null;
    $header = @imap_headerinfo($stream, $message_number);
    $raw_subject = is_object($overview) && isset($overview->subject) ? (string)$overview->subject : '';
    $subject = $this->decode_mail_header($raw_subject);
    $from_email = '';
    $from_name = '';

    if (is_object($header) && !empty($header->from[0])) {
      $from_part = $header->from[0];
      $mailbox = isset($from_part->mailbox) ? (string)$from_part->mailbox : '';
      $host = isset($from_part->host) ? (string)$from_part->host : '';
      $personal = isset($from_part->personal) ? $this->decode_mail_header((string)$from_part->personal) : '';
      $from_email = sanitize_email(trim($mailbox . '@' . $host, '@'));
      $from_name = sanitize_text_field($personal);
    }
    if ($from_name === '' && $from_email !== '') $from_name = $from_email;

    $uid = function_exists('imap_uid') ? max(0, (int)@imap_uid($stream, $message_number)) : 0;
    $message_id = $this->mail_message_id($overview, $header);
    $received_at = $this->mail_received_at($overview, $header);

    return [
      'overview' => $overview,
      'header' => $header,
      'subject' => $subject !== '' ? $subject : 'Support Request',
      'from_email' => $from_email,
      'from_name' => $from_name,
      'received_at' => $received_at,
      'uid' => $uid,
      'fingerprint' => $this->build_mail_fingerprint($uid, $message_id, $subject, $from_email, $received_at),
    ];
  }

  private function mail_message_id($overview, $header): string {
    $raw = '';
    if (is_object($overview) && !empty($overview->message_id)) $raw = (string)$overview->message_id;
    if ($raw === '' && is_object($header) && !empty($header->message_id)) $raw = (string)$header->message_id;
    $raw = trim($raw);
    $raw = trim($raw, "<> \t\n\r\0\x0B");
    return sanitize_text_field($raw);
  }

  private function mail_received_at($overview, $header): string {
    $timestamp = 0;
    if (is_object($overview) && !empty($overview->udate)) $timestamp = (int)$overview->udate;
    if ($timestamp <= 0) {
      $raw = '';
      if (is_object($overview) && !empty($overview->date)) $raw = (string)$overview->date;
      if ($raw === '' && is_object($header) && !empty($header->date)) $raw = (string)$header->date;
      $parsed = $raw !== '' ? strtotime($raw) : false;
      $timestamp = $parsed ? (int)$parsed : 0;
    }
    if ($timestamp <= 0) return current_time('mysql');
    return date_i18n('Y-m-d H:i:s', $timestamp);
  }

  private function build_mail_fingerprint(int $uid, string $message_id, string $subject, string $from_email, string $received_at): string {
    if ($message_id !== '') return 'mid:' . md5(strtolower($message_id));
    if ($uid > 0) return 'uid:' . $uid;
    $seed = strtolower(trim($subject . '|' . $from_email . '|' . $received_at));
    return $seed !== '' ? 'sig:' . md5($seed) : '';
  }

  private function supported_imap_mailbox(): string {
    return 'INBOX';
  }

  private function imap_mailbox_string(array $type): string {
    $host = trim((string)($type['imap_host'] ?? ''));
    $port = max(1, (int)($type['imap_port'] ?? 993));
    $secure = (string)($type['imap_secure'] ?? 'ssl');
    $mailbox = $this->supported_imap_mailbox();
    $flags = '/imap';
    if ($secure === 'ssl') $flags .= '/ssl';
    elseif ($secure === 'tls') $flags .= '/tls';
    else $flags .= '/notls';
    if (empty($type['imap_validate_cert'])) $flags .= '/novalidate-cert';
    return '{' . $host . ':' . $port . $flags . '}' . $mailbox;
  }

  private function decode_mail_header(string $value): string {
    if ($value === '') return '';
    if (function_exists('iconv_mime_decode')) {
      $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
      if (is_string($decoded) && $decoded !== '') return sanitize_text_field($decoded);
    }
    if (function_exists('mb_decode_mimeheader')) {
      $decoded = @mb_decode_mimeheader($value);
      if (is_string($decoded) && $decoded !== '') return sanitize_text_field($decoded);
    }
    if (function_exists('imap_utf8')) {
      $decoded = @imap_utf8($value);
      if (is_string($decoded) && $decoded !== '') return sanitize_text_field($decoded);
    }
    return sanitize_text_field($value);
  }

  private function extract_message_body($stream, int $message_number): string {
    return $this->extract_message_body_from_parts($this->read_message_parts($stream, $message_number), $stream, $message_number);
  }

  private function read_message_parts($stream, int $message_number): array {
    $structure = @imap_fetchstructure($stream, $message_number);
    if (!is_object($structure)) return [];
    return $this->collect_message_parts($stream, $message_number, $structure);
  }

  private function extract_message_body_from_parts(array $parts, $stream, int $message_number): string {
    foreach ($parts as $part) {
      if (($part['mime'] ?? '') === 'text/plain' && empty($part['is_attachment'])) {
        return $this->normalize_message_body((string)($part['body'] ?? ''));
      }
    }
    foreach ($parts as $part) {
      if (($part['mime'] ?? '') === 'text/html' && empty($part['is_attachment'])) {
        return $this->normalize_message_body(wp_strip_all_tags((string)($part['body'] ?? '')));
      }
    }
    $fallback = @imap_body($stream, $message_number, FT_PEEK);
    return $this->normalize_message_body(is_string($fallback) ? $fallback : '');
  }

  private function extract_message_attachments(array $parts, int $ticket_id, string $fingerprint = ''): array {
    if ($ticket_id <= 0 || empty($parts)) return [];
    [$dir, $url] = $this->ticket_upload_dir($ticket_id);
    if ($dir === '' || $url === '') return [];
    wp_mkdir_p($dir);
    if (!is_dir($dir)) return [];

    $attachments = [];
    foreach ($parts as $part) {
      if (!is_array($part) || empty($part['is_attachment'])) continue;
      $body = (string)($part['body'] ?? '');
      if ($body === '') continue;

      $filename = sanitize_file_name((string)($part['filename'] ?? ''));
      if ($filename === '') $filename = $this->default_ticket_attachment_name($part);
      if ($filename === '') $filename = 'attachment.bin';

      $stored_name = wp_unique_filename($dir, $filename);
      $target = trailingslashit($dir) . $stored_name;
      $written = @file_put_contents($target, $body, LOCK_EX);
      if ($written === false) {
        if (function_exists('eum_log_message')) {
          eum_log_message('[EUM Tickets] Failed to store attachment "' . $filename . '" for ticket #' . $ticket_id . '.');
        }
        continue;
      }

      $mime = sanitize_text_field((string)($part['mime'] ?? 'application/octet-stream'));
      if (($mime === '' || $mime === 'application/octet-stream') && function_exists('mime_content_type')) {
        $detected = @mime_content_type($target);
        if (is_string($detected) && $detected !== '') $mime = sanitize_text_field($detected);
      }

      $attachments[] = [
        'name' => $filename,
        'path' => $target,
        'url' => trailingslashit($url) . rawurlencode($stored_name),
        'type' => $mime !== '' ? $mime : 'application/octet-stream',
        'size' => max(0, (int)($part['size'] ?? 0), (int)@filesize($target)),
        'uploaded_at' => current_time('mysql'),
        'fingerprint' => $fingerprint,
      ];
    }

    return $this->normalize_ticket_attachments($attachments);
  }

  private function ticket_upload_dir(int $ticket_id): array {
    $upload = wp_upload_dir();
    $base_dir = isset($upload['basedir']) ? trailingslashit((string)$upload['basedir']) . 'eum/tickets/' . $ticket_id . '/' : '';
    $base_url = isset($upload['baseurl']) ? trailingslashit((string)$upload['baseurl']) . 'eum/tickets/' . $ticket_id . '/' : '';
    return [$base_dir, $base_url];
  }

  private function collect_message_parts($stream, int $message_number, $structure, string $part_number = ''): array {
    $parts = [];
    if (!is_object($structure)) return $parts;
    $mime = $this->structure_mime_type($structure);
    $is_attachment = $this->structure_is_attachment($structure);
    $has_children = !empty($structure->parts) && is_array($structure->parts);
    if ($is_attachment || !$has_children) {
      $fetch_part = $part_number !== '' ? $part_number : '1';
      $raw = @imap_fetchbody($stream, $message_number, $fetch_part, FT_PEEK);
      if ($raw === '' && $part_number === '') $raw = @imap_body($stream, $message_number, FT_PEEK);
      $parts[] = [
        'part_number' => $fetch_part,
        'mime' => $mime,
        'body' => $this->decode_body_by_encoding(is_string($raw) ? $raw : '', (int)($structure->encoding ?? 0)),
        'is_attachment' => $is_attachment,
        'filename' => $this->structure_attachment_filename($structure),
        'size' => max(0, (int)($structure->bytes ?? 0)),
      ];
    }

    if (!$is_attachment && $has_children) {
      foreach ($structure->parts as $index => $subpart) {
        $next_number = $part_number === '' ? (string)($index + 1) : $part_number . '.' . ($index + 1);
        $parts = array_merge($parts, $this->collect_message_parts($stream, $message_number, $subpart, $next_number));
      }
    }
    return $parts;
  }

  private function structure_mime_type($structure): string {
    $primary = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application', 4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'];
    $type = isset($primary[(int)($structure->type ?? 7)]) ? $primary[(int)($structure->type ?? 7)] : 'other';
    $subtype = strtolower((string)($structure->subtype ?? 'plain'));
    return strtolower($type . '/' . $subtype);
  }

  private function structure_is_attachment($structure): bool {
    $disposition = strtolower((string)($structure->disposition ?? ''));
    if (in_array($disposition, ['attachment', 'inline'], true) && $this->structure_attachment_filename($structure) !== '') {
      return true;
    }
    return $this->structure_attachment_filename($structure) !== '';
  }

  private function structure_attachment_filename($structure): string {
    $filename = $this->structure_parameter_value($structure, ['filename']);
    if ($filename === '') $filename = $this->structure_parameter_value($structure, ['name']);
    return $filename !== '' ? sanitize_file_name($this->decode_mail_header($filename)) : '';
  }

  private function structure_parameter_value($structure, array $attributes): string {
    $attributes = array_map(static fn($attribute) => strtolower((string)$attribute), $attributes);
    foreach (['parameters', 'dparameters'] as $property) {
      if (empty($structure->{$property}) || !is_array($structure->{$property})) continue;
      foreach ((array)$structure->{$property} as $parameter) {
        $attribute = strtolower((string)($parameter->attribute ?? ''));
        if ($attribute === '' || !in_array($attribute, $attributes, true)) continue;
        $value = (string)($parameter->value ?? '');
        if ($value !== '') return $value;
      }
    }
    return '';
  }

  private function default_ticket_attachment_name(array $part): string {
    $ext = strtolower((string)($part['mime'] ?? ''));
    $ext = strpos($ext, '/') !== false ? (string)substr($ext, strpos($ext, '/') + 1) : 'bin';
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
    if ($ext === '') $ext = 'bin';
    if ($ext === 'jpeg') $ext = 'jpg';
    if ($ext === 'plain') $ext = 'txt';
    $suffix = str_replace('.', '-', (string)($part['part_number'] ?? 'file'));
    if ($suffix === '') $suffix = 'file';
    return 'attachment-' . $suffix . '.' . $ext;
  }

  private function decode_body_by_encoding(string $body, int $encoding): string {
    switch ($encoding) {
      case 3:
        $decoded = base64_decode($body, true);
        return is_string($decoded) ? $decoded : $body;
      case 4:
        return quoted_printable_decode($body);
      default:
        return $body;
    }
  }

  private function normalize_message_body(string $body): string {
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/\n{3,}/', "\n\n", $body);
    $body = trim((string)$body);
    return sanitize_textarea_field($body);
  }

  private function normalize_mail_address(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    if (preg_match('/<([^>]+)>/', $raw, $matches)) {
      $raw = (string)$matches[1];
    }
    return sanitize_email(trim($raw, " \t\n\r\0\x0B<>"));
  }

  private function extract_email_list(string $raw): array {
    if ($raw === '') return [];
    $parts = preg_split('/[\r\n,;]+/', $raw);
    $emails = [];
    foreach ((array)$parts as $part) {
      $email = $this->normalize_mail_address((string)$part);
      if ($email !== '' && is_email($email)) $emails[strtolower($email)] = $email;
    }
    return array_values($emails);
  }

  private function extract_mail_addresses($value): array {
    $items = is_array($value) ? $value : [$value];
    $emails = [];
    foreach ($items as $item) {
      if (!is_scalar($item)) continue;
      foreach ($this->extract_email_list((string)$item) as $email) {
        $emails[strtolower($email)] = $email;
      }
    }
    return array_values($emails);
  }

  private function sanitize_email_list($value, array $exclude = []): array {
    $emails = $this->extract_mail_addresses($value);
    $blocked = [];
    foreach ($exclude as $email) {
      $normalized = $this->normalize_mail_address((string)$email);
      if ($normalized !== '') $blocked[strtolower($normalized)] = true;
    }

    $clean = [];
    foreach ($emails as $email) {
      $key = strtolower($email);
      if (isset($blocked[$key])) continue;
      $clean[$key] = $email;
    }

    return array_values($clean);
  }

  private function normalize_mail_headers($headers): array {
    if (is_array($headers)) {
      $lines = [];
      foreach ($headers as $header) {
        if (!is_scalar($header)) continue;
        foreach (preg_split('/\r\n|\r|\n/', (string)$header) as $line) {
          $line = trim((string)$line);
          if ($line !== '') $lines[] = $line;
        }
      }
      return $lines;
    }
    if (!is_scalar($headers) || (string)$headers === '') return [];
    $lines = preg_split('/\r\n|\r|\n/', (string)$headers);
    $lines = array_map(static fn($line) => trim((string)$line), (array)$lines);
    return array_values(array_filter($lines, static fn($line) => $line !== ''));
  }
}
