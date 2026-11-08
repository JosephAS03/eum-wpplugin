<?php
if (!defined('ABSPATH')) exit;

class EUM_Email_Notifications {
  private $active_mail_transport = null;
  const OPTION_KEY = 'eum_email_notifications_settings';

  public function init(): void {
    add_action('admin_menu', [$this, 'menu'], 35);
    add_action('admin_post_eum_email_notifications_save', [$this, 'handle_save']);
    add_action('admin_post_eum_email_notifications_test', [$this, 'handle_test_send']);
    add_action('eum_candidate_application_created', [$this, 'handle_candidate_application_created'], 10, 4);
    add_action('eum_user_hire_applied', [$this, 'handle_user_hire_applied'], 10, 3);
    add_action('eum_profile_change_applied', [$this, 'handle_profile_change_applied'], 10, 2);
    add_action('admin_post_eum_send_personal_reset_email', [$this, 'handle_personal_reset_request']);
    add_shortcode('eum_personal_password_reset', [$this, 'shortcode_personal_password_reset']);
    add_filter('lostpassword_user_data', [$this, 'filter_lostpassword_user_data'], 20, 2);
    add_filter('send_retrieve_password_email', [$this, 'filter_send_retrieve_password_email'], 20, 3);
    add_filter('retrieve_password_notification_email', [$this, 'filter_retrieve_password_notification_email'], 20, 4);
    add_filter('wp_mail', [$this, 'filter_wp_mail_sender_cc'], 20, 1);
  }

  public function menu(): void {
    add_submenu_page(EUM_Admin::parent_slug('integrations'), 'Email Notifications', 'Email Notifications', 'read', 'eum-email-notifications', [$this, 'render_page']);
  }

  public static function settings(): array {
    $defaults = [
      'application_enabled' => 1,
      'activation_enabled' => 1,
      'change_enabled' => 1,
      'application_is_html' => 1,
      'activation_is_html' => 1,
      'change_is_html' => 1,
      'reset_is_html' => 1,
      'application_subject' => 'We received your application for {{job_title}}',
      'application_body' => '<p>Hello {{candidate_name}},</p><p>We received your application for <strong>{{job_title}}</strong>.</p><p><strong>Job Path:</strong> {{job_path}}<br><strong>Submitted:</strong> {{applied_at}}</p><p>Thank you,<br>{{site_name}}</p>',
      'activation_subject' => 'Your account is active',
      'activation_body' => '<p>Hello {{user_name}},</p><p>Your account is now active.</p><p><strong>Username:</strong> {{username}}<br><strong>Work Email:</strong> {{work_email}}<br><strong>Job Path:</strong> {{job_path}}</p><p><a href="{{reset_link}}">Set your password</a></p><p>Thank you,<br>{{site_name}}</p>',
      'change_subject' => 'Your employee data was updated',
      'change_body' => '<p>Hello {{user_name}},</p><p>Your employee data was updated.</p><p><strong>Reason:</strong> {{reason}}</p><p><strong>Changed data:</strong><br>{{changed_fields_html}}</p><p>If this looks incorrect, contact your manager or HR.</p><p>Thank you,<br>{{site_name}}</p>',
      'reset_subject' => 'Password reset request',
      'reset_body' => '<p>Hello {{user_name}},</p><p>A password reset was requested for your account.</p><p><a href="{{reset_link}}">Reset your password</a></p><p>If you did not expect this email, you can ignore it.</p><p>Thank you,<br>{{site_name}}</p>',
      'application_from_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
      'application_from_email' => get_option('admin_email'),
      'application_cc' => '',
      'activation_from_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
      'activation_from_email' => get_option('admin_email'),
      'activation_cc' => '',
      'change_from_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
      'change_from_email' => get_option('admin_email'),
      'change_cc' => '',
      'reset_from_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
      'reset_from_email' => get_option('admin_email'),
      'reset_cc' => '',
      'mail_transport' => 'wordpress',
      'smtp_host' => '',
      'smtp_port' => '587',
      'smtp_secure' => 'tls',
      'smtp_auth' => 1,
      'smtp_username' => '',
      'smtp_password' => '',
      'smtp_timeout' => '20',
      'smtp_from_envelope' => '',
    ];
    $settings = get_option(self::OPTION_KEY, []);
    return array_merge($defaults, is_array($settings) ? $settings : []);
  }

  public function render_page(): void {
    if (!EUM_Access::current_user_is_it() && !EUM_Access::current_user_is_hr()) wp_die('Not allowed.');
    $s = self::settings();
    $preview_samples = $this->preview_sample_context();
    $placeholder_html = implode(', ', array_map(static function(string $ph): string {
      return '<code>' . esc_html($ph) . '</code>';
    }, $this->available_placeholders()));

    echo '<div class="wrap"><h1>Email Notifications</h1>';
    if (!empty($_GET['updated'])) {
      echo '<div class="notice notice-success is-dismissible"><p>Email templates updated.</p></div>';
    }
    if (!empty($_GET['tested'])) {
      $status = sanitize_key((string) $_GET['tested']);
      if ($status === 'sent') {
        echo '<div class="notice notice-success is-dismissible"><p>Test email sent.</p></div>';
      } else {
        echo '<div class="notice notice-error is-dismissible"><p>Test email failed to send.</p></div>';
      }
    }

    echo '<p class="description">Available placeholders: ' . $placeholder_html . '.</p>';
    echo '<p class="description">Dynamic placeholders are also supported, such as <code>{{user_meta:department}}</code>, <code>{{manager_meta:title}}</code>, <code>{{job_owner_meta:company_div}}</code>, and <code>{{candidate_data:first_name}}</code>. From Email and CC fields also accept placeholders.</p>';
    echo '<p class="description">Email template HTML allows inline CSS, table-based layout markup, and <code>&lt;style&gt;</code> blocks for email-friendly designs.</p>';
    echo '<p class="description">Each template now includes a rendered preview pane. It uses your available account data by default, and any empty values fall back to the placeholder label, inside an email-width frame so the result looks much closer to an actual email client than the standard WordPress visual editor.</p>';


    echo '<hr style="margin:28px 0;">';
    echo '<h2>Mail Transport</h2>';
    echo '<p class="description">Use WordPress mail or point these notifications at your mail server directly. For cPanel-managed mailboxes, enter your SMTP host and mailbox credentials here.</p>';
    echo '<table class="form-table" style="max-width:900px;"><tbody>';
    echo '<tr><th scope="row">Transport</th><td><select name="settings[mail_transport]">';
    foreach (['wordpress' => 'WordPress Default', 'smtp' => 'SMTP Server'] as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected((string)($s['mail_transport'] ?? 'wordpress'), $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">SMTP Host</th><td><input type="text" class="regular-text code" name="settings[smtp_host]" value="' . esc_attr((string)($s['smtp_host'] ?? '')) . '" placeholder="mail.example.com"></td></tr>';
    echo '<tr><th scope="row">SMTP Port</th><td><input type="number" class="small-text" name="settings[smtp_port]" value="' . esc_attr((string)($s['smtp_port'] ?? '587')) . '"> <select name="settings[smtp_secure]"><option value="none" ' . selected((string)($s['smtp_secure'] ?? 'tls'), 'none', false) . '>None</option><option value="tls" ' . selected((string)($s['smtp_secure'] ?? 'tls'), 'tls', false) . '>TLS</option><option value="ssl" ' . selected((string)($s['smtp_secure'] ?? 'tls'), 'ssl', false) . '>SSL</option></select></td></tr>';
    echo '<tr><th scope="row">SMTP Authentication</th><td><label><input type="checkbox" name="settings[smtp_auth]" value="1" ' . checked(!empty($s['smtp_auth']), true, false) . '> Requires username and password</label></td></tr>';
    echo '<tr><th scope="row">SMTP Username</th><td><input type="text" class="regular-text code" name="settings[smtp_username]" value="' . esc_attr((string)($s['smtp_username'] ?? '')) . '"></td></tr>';
    echo '<tr><th scope="row">SMTP Password</th><td><input type="password" class="regular-text code" name="settings[smtp_password]" value="' . esc_attr((string)($s['smtp_password'] ?? '')) . '"></td></tr>';
    echo '<tr><th scope="row">Envelope Sender</th><td><input type="text" class="regular-text code" name="settings[smtp_from_envelope]" value="' . esc_attr((string)($s['smtp_from_envelope'] ?? '')) . '" placeholder="bounce@example.com"></td></tr>';
    echo '<tr><th scope="row">Timeout</th><td><input type="number" class="small-text" name="settings[smtp_timeout]" value="' . esc_attr((string)($s['smtp_timeout'] ?? '20')) . '"> seconds</td></tr>';
    echo '</tbody></table>';

    echo '<style>
      .eum-email-template-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:20px;align-items:start}
      .eum-email-template-editor{min-width:0}
      .eum-email-template-preview{min-width:0;position:sticky;top:32px}
      .eum-email-preview-shell{background:#f3f4f6;border:1px solid #d1d5db;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.06)}
      .eum-email-preview-topbar{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#fff;border-bottom:1px solid #e5e7eb;font-size:12px;color:#6b7280}
      .eum-email-preview-meta{padding:14px 16px;background:#fff;border-bottom:1px solid #e5e7eb;font-size:13px;line-height:1.6}
      .eum-email-preview-meta strong{display:inline-block;min-width:56px;color:#111827}
      .eum-email-preview-subject{font-size:15px;font-weight:600;color:#111827;margin-top:6px}
      .eum-email-preview-frame{display:block;width:100%;min-height:640px;border:0;background:#eef2f7}
      .eum-email-preview-help{margin-top:8px;color:#6b7280}
      @media (max-width: 1280px){
        .eum-email-template-grid{grid-template-columns:1fr}
        .eum-email-template-preview{position:static}
        .eum-email-preview-frame{min-height:520px}
      }
    </style>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_email_notifications_save">';
    wp_nonce_field('eum_email_notifications_save', 'eum_email_notifications_nonce');
    echo '<table class="form-table"><tbody>';

    foreach ([
      'application' => 'Candidate Application Email',
      'activation' => 'Account Active Email',
      'change' => 'Employee Data Update Email',
      'reset' => 'Password Reset Email',
    ] as $key => $label) {
      $editor_id = 'eum_email_tpl_' . $key;
      $preview_id = 'eum-email-preview-frame-' . $key;
      echo '<tr><th scope="row" style="vertical-align:top;">' . esc_html($label) . '</th><td>';
      if ($key !== 'reset') {
        echo '<p><label><input type="checkbox" name="settings[' . esc_attr($key) . '_enabled]" value="1" ' . checked(!empty($s[$key . '_enabled']), true, false) . '> Enabled</label></p>';
      }
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">From Name</label><input type="text" class="regular-text eum-email-preview-source" data-template="' . esc_attr($key) . '" data-preview-role="from_name" name="settings[' . esc_attr($key) . '_from_name]" value="' . esc_attr((string)$s[$key . '_from_name']) . '"></p>';
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">From Email</label><input type="text" class="regular-text eum-email-preview-source" data-template="' . esc_attr($key) . '" data-preview-role="from_email" name="settings[' . esc_attr($key) . '_from_email]" value="' . esc_attr((string)$s[$key . '_from_email']) . '" placeholder="{{job_owner_email}} or hr@yourcompany.com"></p>';
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">CC</label><textarea class="large-text" rows="2" name="settings[' . esc_attr($key) . '_cc]" placeholder="email@example.com, {{manager_email}}, {{job_owner_email}}">' . esc_textarea((string)$s[$key . '_cc']) . '</textarea></p>';
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Subject</label><input type="text" class="large-text eum-email-preview-source" data-template="' . esc_attr($key) . '" data-preview-role="subject" name="settings[' . esc_attr($key) . '_subject]" value="' . esc_attr((string)$s[$key . '_subject']) . '" placeholder="Subject"></p>';
      echo '<p><label><input type="checkbox" name="settings[' . esc_attr($key) . '_is_html]" value="1" ' . checked(!empty($s[$key . '_is_html']), true, false) . '> Send as HTML email</label></p>';

      echo '<div class="eum-email-template-grid">';
      echo '<div class="eum-email-template-editor">';
      echo '<p><label style="display:block;font-weight:600;margin-bottom:4px;">Body</label>';
      wp_editor((string)$s[$key . '_body'], $editor_id, [
        'textarea_name' => 'settings[' . $key . '_body]',
        'textarea_rows' => 12,
        'media_buttons' => false,
        'teeny' => false,
        'quicktags' => true,
        'tinymce' => [
          'wpautop' => true,
          'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo,removeformat',
          'toolbar2' => 'pastetext,code',
        ],
      ]);
      echo '</p>';
      echo '<p><button type="button" class="button eum-email-refresh-preview" data-template="' . esc_attr($key) . '">Refresh Preview</button> <span class="description">Rendered with your available account data. Empty values fall back to the label. Use Send Test Email below for record-specific previews.</span></p>';
      echo '</div>';

      echo '<div class="eum-email-template-preview">';
      echo '<div class="eum-email-preview-shell">';
      echo '<div class="eum-email-preview-topbar"><strong>Rendered Preview</strong><span>email-width canvas</span></div>';
      echo '<div class="eum-email-preview-meta">';
      echo '<div><strong>From:</strong> <span id="eum-email-preview-from-' . esc_attr($key) . '"></span></div>';
      echo '<div><strong>To:</strong> <span id="eum-email-preview-to-' . esc_attr($key) . '"></span></div>';
      echo '<div class="eum-email-preview-subject" id="eum-email-preview-subject-' . esc_attr($key) . '"></div>';
      echo '</div>';
      echo '<iframe id="' . esc_attr($preview_id) . '" class="eum-email-preview-frame" sandbox="allow-same-origin"></iframe>';
      echo '</div>';
      echo '<p class="description eum-email-preview-help">This preview is intentionally framed like an email client so tables, spacing, and CSS are easier to judge.</p>';
      echo '</div>';
      echo '</div>';

      echo '</td></tr>';
    }

    echo '</tbody></table>';
    submit_button('Save Email Templates');
    echo '</form>';

    echo '<hr style="margin:28px 0;">';
    echo '<h2>Send Test Email</h2>';
    echo '<p class="description">Send a preview email to any address using real employee or candidate data.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px;">';
    echo '<input type="hidden" name="action" value="eum_email_notifications_test">';
    wp_nonce_field('eum_email_notifications_test', 'eum_email_notifications_test_nonce');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row">Template</th><td><select name="test[type]">';
    foreach (['application' => 'Candidate Application', 'activation' => 'Account Active', 'change' => 'Employee Data Update', 'reset' => 'Password Reset'] as $k => $label) {
      echo '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Send To</th><td><input type="email" class="regular-text" name="test[to]" required placeholder="you@example.com"></td></tr>';
    echo '<tr><th scope="row">Employee User ID</th><td><input type="number" min="0" class="small-text" name="test[user_id]"> <span class="description">Optional. Use for employee/manager placeholders.</span></td></tr>';
    echo '<tr><th scope="row">Candidate Post ID</th><td><input type="number" min="0" class="small-text" name="test[candidate_id]"> <span class="description">Optional. Use for candidate placeholders.</span></td></tr>';
    echo '<tr><th scope="row">Job Posting ID</th><td><input type="number" min="0" class="small-text" name="test[job_id]"> <span class="description">Optional. Helps resolve job title, path, and job owner.</span></td></tr>';
    echo '<tr><th scope="row">Change Log ID</th><td><input type="number" min="0" class="small-text" name="test[change_id]"> <span class="description">Optional. Use for employee data update previews.</span></td></tr>';
    echo '<tr><th scope="row">Actor User ID</th><td><input type="number" min="0" class="small-text" name="test[actor_user_id]"> <span class="description">Optional. Use for actor/requester placeholders.</span></td></tr>';
    echo '</tbody></table>';
    submit_button('Send Test Email', 'secondary');
    echo '</form>';
    echo $this->render_editor_preview_script($preview_samples);
    echo '</div>';
  }

  private function preview_sample_context(): array {
    $current_user_id = get_current_user_id();
    $manager_user_id = 0;
    if ($current_user_id > 0 && class_exists('EUM_Personnel')) {
      $manager_user_id = (int)get_user_meta($current_user_id, EUM_Personnel::META_MANAGER_ID, true);
    }

    $candidate_data = [];
    if ($current_user_id > 0) {
      $user = get_user_by('id', $current_user_id);
      $custom = class_exists('EUM_Fields') ? (array)get_user_meta($current_user_id, EUM_Fields::USER_META_KEY, true) : [];
      $first_name = (string)get_user_meta($current_user_id, 'first_name', true);
      $last_name = (string)get_user_meta($current_user_id, 'last_name', true);
      if ($user) {
        $candidate_data = [
          'candidate_name' => $user->display_name ?: $user->user_login,
          'full_name' => $user->display_name ?: $user->user_login,
          'first_name' => $first_name,
          'last_name' => $last_name,
          'email' => sanitize_email((string)($custom['personal_email'] ?? $user->user_email)),
          'phone' => (string)($custom['phone'] ?? $custom['mobile_phone'] ?? ''),
          'job_title' => $this->email_job_title_label_for_user($current_user_id, $custom),
          'company_div' => $this->email_division_label_for_user($current_user_id),
          'company_division' => $this->email_division_label_for_user($current_user_id),
        ];
        foreach ($custom as $key => $value) {
          if (is_scalar($value)) $candidate_data[(string)$key] = (string)$value;
        }
      }
    }

    $context = $this->build_payload_context([
      'user_id' => $current_user_id,
      'actor_user_id' => $current_user_id,
      'job_owner_user_id' => $current_user_id,
      'job_term_id' => $this->current_job_term_id($current_user_id),
      'candidate_data' => $candidate_data,
      'context' => [
        'reset_link' => home_url('/wp-login.php?action=rp&preview=1'),
        'reason' => '',
        'changed_fields' => '',
        'changed_fields_html' => '',
        'applied_at' => current_time('mysql'),
      ],
    ]);

    $context['_preview_meta'] = [
      'user' => $this->preview_meta_values_for_user($current_user_id),
      'manager' => $this->preview_meta_values_for_user($manager_user_id > 0 ? $manager_user_id : $current_user_id),
      'job_owner' => $this->preview_meta_values_for_user($current_user_id),
      'actor' => $this->preview_meta_values_for_user($current_user_id),
    ];
    $context['_preview_candidate_data'] = $candidate_data;

    return $context;
  }

  private function preview_meta_values_for_user(int $user_id): array {
    if ($user_id <= 0) return [];
    $user = get_user_by('id', $user_id);
    if (!$user) return [];

    $values = [];
    $all_meta = get_user_meta($user_id);
    foreach ((array)$all_meta as $meta_key => $meta_values) {
      if (!is_string($meta_key) || $meta_key === '') continue;
      $first = is_array($meta_values) ? ($meta_values[0] ?? null) : $meta_values;
      if (is_scalar($first)) $values[$meta_key] = (string)$first;
    }

    $custom = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    foreach ($custom as $meta_key => $meta_value) {
      if (is_scalar($meta_value)) $values[(string)$meta_key] = (string)$meta_value;
    }

    $values['display_name'] = $user->display_name ?: $user->user_login;
    $values['user_login'] = $user->user_login;
    $values['username'] = $user->user_login;
    $values['email'] = $user->user_email;
    $values['work_email'] = $user->user_email;
    $values['personal_email'] = (string)($custom['personal_email'] ?? ($values['personal_email'] ?? ''));

    return $values;
  }

  private function render_editor_preview_script(array $samples): string {

    $json = wp_json_encode($samples);
    if (!is_string($json)) $json = '{}';
    $script = <<<'HTML'
<script>
(function(){
  const samples = __EUM_PREVIEW_SAMPLES__;
  const templates = ['application','activation','change','reset'];
  const previewMeta = samples._preview_meta || {};
  const previewCandidate = samples._preview_candidate_data || {};

  function escapeRegExp(str){
    return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function humanizeKey(str){
    return String(str || '').replace(/[_-]+/g, ' ').replace(/\b\w/g, function(ch){ return ch.toUpperCase(); });
  }

  function actualValueForBucket(bucket, key){
    key = String(key || '').trim();
    const normalized = key.toLowerCase();
    const bucketValues = previewMeta[bucket] && typeof previewMeta[bucket] === 'object' ? previewMeta[bucket] : {};
    const exact = Object.prototype.hasOwnProperty.call(bucketValues, key) ? bucketValues[key] : '';
    if (exact !== null && exact !== undefined && String(exact).trim() !== '') return String(exact);
    const normalizedValue = Object.prototype.hasOwnProperty.call(bucketValues, normalized) ? bucketValues[normalized] : '';
    if (normalizedValue !== null && normalizedValue !== undefined && String(normalizedValue).trim() !== '') return String(normalizedValue);
    return humanizeKey(key);
  }

  function actualCandidateValue(key){
    key = String(key || '').trim();
    const exact = Object.prototype.hasOwnProperty.call(previewCandidate, key) ? previewCandidate[key] : '';
    if (exact !== null && exact !== undefined && String(exact).trim() !== '') return String(exact);
    const normalized = key.toLowerCase();
    const normalizedValue = Object.prototype.hasOwnProperty.call(previewCandidate, normalized) ? previewCandidate[normalized] : '';
    if (normalizedValue !== null && normalizedValue !== undefined && String(normalizedValue).trim() !== '') return String(normalizedValue);
    return humanizeKey(key);
  }

  function replaceTokens(input){
    let output = String(input || '');
    Object.keys(samples).forEach(function(key){
      if (key === '_preview_meta' || key === '_preview_candidate_data') return;
      const regex = new RegExp('{{\s*' + escapeRegExp(key) + '\s*}}', 'g');
      const rawValue = samples[key];
      const replacement = rawValue !== null && rawValue !== undefined && String(rawValue).trim() !== '' ? String(rawValue) : humanizeKey(key);
      output = output.replace(regex, replacement);
    });
    output = output.replace(/{{\s*(user|manager|job_owner|actor)_meta:([^}]+)\s*}}/gi, function(_, bucket, key){
      return actualValueForBucket(bucket, key);
    });
    output = output.replace(/{{\s*candidate_data:([^}]+)\s*}}/gi, function(_, key){
      return actualCandidateValue(key);
    });
    output = output.replace(/{{\s*candidate_meta:([^}]+)\s*}}/gi, function(_, key){
      return actualCandidateValue(key);
    });
    output = output.replace(/{{\s*([a-z0-9_]+)\s*}}/gi, function(_, key){
      const rawValue = Object.prototype.hasOwnProperty.call(samples, key) ? samples[key] : '';
      if (rawValue !== null && rawValue !== undefined && String(rawValue).trim() !== '') return String(rawValue);
      return humanizeKey(key);
    });
    return output;
  }

  function getEditorContent(editorId){
    if (window.tinymce && tinymce.get(editorId)) {
      return tinymce.get(editorId).getContent();
    }
    const field = document.getElementById(editorId);
    return field ? field.value : '';
  }

  function buildPreviewDocument(html){
    const hasFullDocument = /<(?:!doctype|html|body)\b/i.test(html);
    if (hasFullDocument) return html;
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
      '<style>html,body{margin:0;padding:0;background:#eef2f7}body{padding:24px;box-sizing:border-box}a{color:#2563eb}.eum-preview-wrap{max-width:680px;margin:0 auto;background:#ffffff;border-radius:18px;box-shadow:0 18px 50px rgba(15,23,42,.12);overflow:hidden}.eum-preview-body{padding:24px;box-sizing:border-box;font-family:Arial,Helvetica,sans-serif;color:#111827}</style>' +
      '</head><body><div class="eum-preview-wrap"><div class="eum-preview-body">' + html + '</div></div></body></html>';
  }

  function updateMeta(key){
    const fromNameEl = document.querySelector('[name="settings[' + key + '_from_name]"]');
    const fromEmailEl = document.querySelector('[name="settings[' + key + '_from_email]"]');
    const subjectEl = document.querySelector('[name="settings[' + key + '_subject]"]');
    const fromName = replaceTokens(fromNameEl ? fromNameEl.value : '');
    const fromEmail = replaceTokens(fromEmailEl ? fromEmailEl.value : '');
    const subject = replaceTokens(subjectEl ? subjectEl.value : '');
    const toValue = key === 'application' ? ((samples.candidate_email && String(samples.candidate_email).trim() !== '') ? samples.candidate_email : 'Candidate Email') : ((samples.user_personal_email && String(samples.user_personal_email).trim() !== '') ? samples.user_personal_email : 'User Personal Email');

    const fromTarget = document.getElementById('eum-email-preview-from-' + key);
    const toTarget = document.getElementById('eum-email-preview-to-' + key);
    const subjectTarget = document.getElementById('eum-email-preview-subject-' + key);

    if (fromTarget) fromTarget.textContent = fromName && fromEmail ? fromName + ' <' + fromEmail + '>' : (fromName || fromEmail || '');
    if (toTarget) toTarget.textContent = toValue;
    if (subjectTarget) subjectTarget.textContent = subject;
  }

  function renderPreview(key){
    updateMeta(key);
    const frame = document.getElementById('eum-email-preview-frame-' + key);
    if (!frame) return;
    const editorId = 'eum_email_tpl_' + key;
    const content = replaceTokens(getEditorContent(editorId));
    frame.srcdoc = buildPreviewDocument(content);
  }

  function debounce(fn, wait){
    let t;
    return function(){
      const args = arguments;
      clearTimeout(t);
      t = setTimeout(function(){ fn.apply(null, args); }, wait);
    };
  }

  const debouncedRender = debounce(function(key){ renderPreview(key); }, 250);

  function bindTemplate(key){
    const refreshButton = document.querySelector('.eum-email-refresh-preview[data-template="' + key + '"]');
    if (refreshButton) {
      refreshButton.addEventListener('click', function(){ renderPreview(key); });
    }

    document.querySelectorAll('.eum-email-preview-source[data-template="' + key + '"]').forEach(function(el){
      ['input','change','keyup'].forEach(function(evt){
        el.addEventListener(evt, function(){ debouncedRender(key); });
      });
    });

    const textarea = document.getElementById('eum_email_tpl_' + key);
    if (textarea) {
      ['input','change','keyup'].forEach(function(evt){
        textarea.addEventListener(evt, function(){ debouncedRender(key); });
      });
    }

    const attachTiny = function(){
      if (!(window.tinymce && tinymce.get('eum_email_tpl_' + key))) return false;
      const editor = tinymce.get('eum_email_tpl_' + key);
      editor.on('keyup change SetContent Undo Redo', function(){ debouncedRender(key); });
      return true;
    };

    if (!attachTiny()) {
      let attempts = 0;
      const interval = setInterval(function(){
        attempts += 1;
        if (attachTiny() || attempts > 40) clearInterval(interval);
      }, 500);
    }

    renderPreview(key);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){
      templates.forEach(bindTemplate);
    });
  } else {
    templates.forEach(bindTemplate);
  }
})();
</script>
HTML;
    return str_replace('__EUM_PREVIEW_SAMPLES__', $json, $script);
  }

  public function handle_save(): void {
    if (!EUM_Access::current_user_is_it() && !EUM_Access::current_user_is_hr()) wp_die('Not allowed.');
    check_admin_referer('eum_email_notifications_save', 'eum_email_notifications_nonce');
    $posted = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
    $clean = self::settings();
    foreach (['application', 'activation', 'change', 'reset'] as $k) {
      if ($k !== 'reset') {
        $clean[$k . '_enabled'] = !empty($posted[$k . '_enabled']) ? 1 : 0;
      }
      $clean[$k . '_is_html'] = !empty($posted[$k . '_is_html']) ? 1 : 0;
      $clean[$k . '_subject'] = sanitize_text_field((string)($posted[$k . '_subject'] ?? $clean[$k . '_subject']));
      $clean[$k . '_body'] = $this->sanitize_email_template_html((string)($posted[$k . '_body'] ?? $clean[$k . '_body']));
      $clean[$k . '_from_name'] = sanitize_text_field((string)($posted[$k . '_from_name'] ?? $clean[$k . '_from_name']));
      $clean[$k . '_from_email'] = sanitize_text_field((string)($posted[$k . '_from_email'] ?? $clean[$k . '_from_email']));
      $clean[$k . '_cc'] = sanitize_textarea_field((string)($posted[$k . '_cc'] ?? $clean[$k . '_cc']));
    }
    $clean['mail_transport'] = in_array((string)($posted['mail_transport'] ?? 'wordpress'), ['wordpress', 'smtp'], true) ? (string)$posted['mail_transport'] : 'wordpress';
    $clean['smtp_host'] = sanitize_text_field((string)($posted['smtp_host'] ?? ''));
    $clean['smtp_port'] = preg_replace('/[^0-9]/', '', (string)($posted['smtp_port'] ?? '587'));
    $clean['smtp_secure'] = in_array((string)($posted['smtp_secure'] ?? 'tls'), ['none', 'tls', 'ssl'], true) ? (string)$posted['smtp_secure'] : 'tls';
    $clean['smtp_auth'] = !empty($posted['smtp_auth']) ? 1 : 0;
    $clean['smtp_username'] = sanitize_text_field((string)($posted['smtp_username'] ?? ''));
    $clean['smtp_password'] = (string)($posted['smtp_password'] ?? '');
    $clean['smtp_timeout'] = preg_replace('/[^0-9]/', '', (string)($posted['smtp_timeout'] ?? '20'));
    $clean['smtp_from_envelope'] = sanitize_text_field((string)($posted['smtp_from_envelope'] ?? ''));
    update_option(self::OPTION_KEY, $clean, false);
    wp_safe_redirect(add_query_arg(['page' => 'eum-email-notifications', 'updated' => '1'], admin_url('admin.php')));
    exit;
  }

  public function handle_test_send(): void {
    if (!EUM_Access::current_user_is_it() && !EUM_Access::current_user_is_hr()) wp_die('Not allowed.');
    check_admin_referer('eum_email_notifications_test', 'eum_email_notifications_test_nonce');
    $test = is_array($_POST['test'] ?? null) ? $_POST['test'] : [];
    $type = sanitize_key((string)($test['type'] ?? 'application'));
    if (!in_array($type, ['application', 'activation', 'change', 'reset'], true)) $type = 'application';
    $to = sanitize_email((string)($test['to'] ?? ''));
    if (!is_email($to)) wp_die('Valid test email address required.');

    $user_id = (int)($test['user_id'] ?? 0);
    $candidate_id = (int)($test['candidate_id'] ?? 0);
    $job_id = (int)($test['job_id'] ?? 0);
    $change_id = (int)($test['change_id'] ?? 0);
    $actor_user_id = (int)($test['actor_user_id'] ?? 0);
    $payload = $this->build_test_payload($type, $user_id, $candidate_id, $job_id, $change_id, $actor_user_id);
    $s = self::settings();
    $ok = $this->send_template($type, $to, (string)$s[$type . '_subject'], (string)$s[$type . '_body'], $payload, $s);
    wp_safe_redirect(add_query_arg(['page' => 'eum-email-notifications', 'tested' => ($ok ? 'sent' : 'failed')], admin_url('admin.php')));
    exit;
  }

  public function handle_candidate_application_created(int $candidate_id, int $job_id, string $source, array $context = []): void {
    try {
      $s = self::settings();
      if (empty($s['application_enabled'])) return;
      if (sanitize_key($source) === 'manual') return;
      $to = sanitize_email((string)($context['profile']['custom:personal_email'] ?? $context['email'] ?? ''));
      if (!is_email($to)) return;

      $job_title = (string)get_post_meta($job_id, EUM_Job_Boards::JOB_META_NEAT_TITLE, true);
      $job_term_id = (int)get_post_meta($job_id, EUM_Job_Boards::JOB_META_JOB_TERM, true);
      $internal_user_id = (int)($context['internal_user_id'] ?? 0);
      $owner_user_id = (int)get_post_meta($job_id, EUM_Job_Boards::JOB_META_OWNER_UID, true);
      if ($owner_user_id <= 0) $owner_user_id = (int)get_post_field('post_author', $job_id);

      $candidate_data = [
        'candidate_name' => (string)($context['full_name'] ?? get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_FULL_NAME, true)),
        'full_name' => (string)($context['full_name'] ?? get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_FULL_NAME, true)),
        'email' => (string)($context['email'] ?? get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_EMAIL, true)),
        'phone' => (string)($context['phone'] ?? get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_PHONE, true)),
        'applied_at' => (string)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_APPLIED_AT, true),
        'job_title' => $job_title,
        'job_path' => $this->term_path('eum_job_path', $job_term_id),
      ];
      $profile = isset($context['profile']) && is_array($context['profile']) ? $context['profile'] : [];
      if (empty($profile) && $candidate_id > 0 && class_exists('EUM_Job_Boards')) {
        $stored_profile = get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_PROFILE, true);
        $profile = is_array($stored_profile) ? $stored_profile : [];
      }
      $candidate_data = $this->candidate_data_with_profile($candidate_data, $profile);

      $payload = $this->build_payload_context([
        'user_id' => $internal_user_id,
        'candidate_id' => $candidate_id,
        'candidate_data' => $candidate_data,
        'job_id' => $job_id,
        'job_term_id' => $job_term_id,
        'job_owner_user_id' => $owner_user_id,
        'context' => [
          'candidate_name' => $candidate_data['candidate_name'],
          'job_title' => $job_title,
          'job_path' => $candidate_data['job_path'],
          'applied_at' => $candidate_data['applied_at'],
        ],
      ]);

      $this->send_template('application', $to, $s['application_subject'], $s['application_body'], $payload, $s);
    } catch (\Throwable $e) {
      $this->log_email_runtime_issue('handle_candidate_application_created', $e);
    }
  }

  public function handle_user_hire_applied(int $user_id, int $hire_id, array $context = []): void {
    try {
      $s = self::settings();
      if (empty($s['activation_enabled'])) return;
      if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) return;
      $to = $this->personal_email_for_user($user_id);
      if (!is_email($to)) return;
      $user = get_user_by('id', $user_id);
      if (!$user) return;

      $job_term_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_JOB_TERM, true);
      $candidate_id = (int)get_post_meta($hire_id, EUM_User_Creation::META_CANDIDATE_ID, true);
      $job_id = $candidate_id > 0 ? (int)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_JOB_ID, true) : 0;
      $owner_user_id = $job_id > 0 ? (int)get_post_meta($job_id, EUM_Job_Boards::JOB_META_OWNER_UID, true) : 0;
      if ($job_id > 0 && $owner_user_id <= 0) $owner_user_id = (int)get_post_field('post_author', $job_id);

      $payload = $this->build_payload_context([
        'user_id' => $user_id,
        'candidate_id' => $candidate_id,
        'job_id' => $job_id,
        'job_term_id' => $job_term_id,
        'job_owner_user_id' => $owner_user_id,
        'context' => [
          'user_name' => $user->display_name ?: $user->user_login,
          'username' => $user->user_login,
          'work_email' => $user->user_email,
          'job_path' => $this->term_path('eum_job_path', $job_term_id),
          'reset_link' => $this->build_reset_link($user),
        ],
      ]);

      $this->send_template('activation', $to, $s['activation_subject'], $s['activation_body'], $payload, $s);
    } catch (\Throwable $e) {
      $this->log_email_runtime_issue('handle_user_hire_applied', $e);
    }
  }

  public function handle_profile_change_applied(int $change_id, int $target_user_id): void {
    try {
      $s = self::settings();
      if (empty($s['change_enabled'])) return;
      if ((int)get_post_meta($change_id, EUM_Profiles::META_MANUAL, true) === 1) return;
      if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($target_user_id)) return;
      $to = $this->personal_email_for_user($target_user_id);
      if (!is_email($to)) return;
      $user = get_user_by('id', $target_user_id);
      if (!$user) return;

      $before = get_post_meta($change_id, EUM_Profiles::META_BEFORE, true); $before = is_array($before) ? $before : [];
      $after = get_post_meta($change_id, EUM_Profiles::META_AFTER, true); $after = is_array($after) ? $after : [];
      $changed = [];
      foreach ($after as $k => $v) {
        $old = $before[$k] ?? null;
        if (wp_json_encode($old) !== wp_json_encode($v)) {
          $changed[] = $k . ': ' . (is_scalar($v) ? (string)$v : wp_json_encode($v));
        }
      }
      if (empty($changed)) return;

      $job_term_id = (int)($after['tax:eum_job_path'] ?? 0);
      if ($job_term_id <= 0) $job_term_id = $this->current_job_term_id($target_user_id);

      $payload = $this->build_payload_context([
        'user_id' => $target_user_id,
        'change_id' => $change_id,
        'job_term_id' => $job_term_id,
        'context' => [
          'user_name' => $user->display_name ?: $user->user_login,
          'reason' => (string)get_post_meta($change_id, EUM_Profiles::META_NOTE, true),
          'changed_fields' => implode("\n", $changed),
          'changed_fields_html' => implode('<br>', array_map('esc_html', $changed)),
        ],
      ]);

      $this->send_template('change', $to, $s['change_subject'], $s['change_body'], $payload, $s);
    } catch (\Throwable $e) {
      $this->log_email_runtime_issue('handle_profile_change_applied', $e);
    }
  }

  public function shortcode_personal_password_reset($atts): string {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
    $atts = shortcode_atts([
      'user_id' => '',
      'target_user_id' => '',
      'return_url' => '',
      'button_label' => 'Send Password Reset Email',
    ], (array)$atts, 'eum_personal_password_reset');
    $actor_user_id = get_current_user_id();
    $target_user_id = (int)$atts['user_id'];
    if ($target_user_id <= 0) $target_user_id = (int)$atts['target_user_id'];
    if ($target_user_id <= 0 && isset($_GET['target_user_id'])) $target_user_id = (int)$_GET['target_user_id'];
    if ($target_user_id <= 0 && isset($_GET['user_id'])) $target_user_id = (int)$_GET['user_id'];
    if ($target_user_id <= 0) $target_user_id = $actor_user_id;
    if (!$this->can_request_reset_for($actor_user_id, $target_user_id)) return '<p>You do not have access to send this reset email.</p>';
    $user = get_user_by('id', $target_user_id);
    if (!$user) return '<p>User not found.</p>';
    $return_url = esc_url_raw((string)$atts['return_url']);
    if ($return_url === '') {
      $return_url = remove_query_arg(['eum_personal_reset_status', 'eum_personal_reset_user_id']);
    }
    $status = sanitize_key((string)($_GET['eum_personal_reset_status'] ?? ''));
    $status_target_user_id = (int)($_GET['eum_personal_reset_user_id'] ?? 0);
    ob_start();
    if ($status !== '' && $status_target_user_id === $target_user_id) {
      if ($status === 'sent') {
        echo '<div class="eum-personal-reset__notice eum-personal-reset__notice--success"><p>Password reset email sent.</p></div>';
      } elseif ($status === 'missing_email') {
        echo '<div class="eum-personal-reset__notice eum-personal-reset__notice--error"><p>This user does not have a valid personal email saved yet.</p></div>';
      } else {
        echo '<div class="eum-personal-reset__notice eum-personal-reset__notice--error"><p>Could not send the password reset email.</p></div>';
      }
    }
    echo '<div class="eum-personal-reset"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_send_personal_reset_email"><input type="hidden" name="target_user_id" value="' . (int)$target_user_id . '"><input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_send_personal_reset_email_' . $target_user_id, 'eum_send_personal_reset_email_nonce');
    echo '<p><strong>Send password reset to personal email</strong><br>' . esc_html($user->display_name ?: $user->user_login) . '</p>';
    echo '<p><button type="submit" class="button button-primary">' . esc_html((string)$atts['button_label']) . '</button></p>';
    echo '</form></div>';
    return ob_get_clean();
  }

  public function handle_personal_reset_request(): void {
    if (!is_user_logged_in()) wp_die('Not allowed.');
    $actor_user_id = get_current_user_id();
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    check_admin_referer('eum_send_personal_reset_email_' . $target_user_id, 'eum_send_personal_reset_email_nonce');
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? home_url('/')));
    if (!$this->can_request_reset_for($actor_user_id, $target_user_id)) wp_die('Not allowed.');
    $status = $this->send_personal_reset_email($target_user_id, $actor_user_id) ? 'sent' : ($this->personal_email_for_user($target_user_id) !== '' ? 'failed' : 'missing_email');
    $return_url = add_query_arg([
      'eum_personal_reset_status' => $status,
      'eum_personal_reset_user_id' => $target_user_id,
    ], $return_url);
    wp_safe_redirect($return_url);
    exit;
  }

  private function send_personal_reset_email(int $user_id, int $actor_user_id = 0): bool {
    $user = get_user_by('id', $user_id);
    if (!$user) return false;
    $to = $this->personal_email_for_user($user_id);
    if (!is_email($to)) return false;
    $s = self::settings();
    $payload = $this->reset_email_context($user, $this->build_reset_link($user), $actor_user_id);

    return $this->send_template('reset', $to, $s['reset_subject'], $s['reset_body'], $payload, $s);
  }

  private function can_request_reset_for(int $actor_user_id, int $target_user_id): bool {
    if ($actor_user_id <= 0 || $target_user_id <= 0) return false;
    if ($actor_user_id === $target_user_id) return true;
    if (EUM_Access::is_it_or_hr($actor_user_id)) return true;
    return class_exists('EUM_Profiles') && EUM_Profiles::can_edit_target($actor_user_id, $target_user_id);
  }

  public function filter_lostpassword_user_data($user_data, $errors) {
    try {
      if ($user_data instanceof WP_User) return $user_data;

      $lookup = '';
      if (!empty($_POST['user_login']) && is_string($_POST['user_login'])) {
        $lookup = sanitize_email(trim((string)wp_unslash($_POST['user_login'])));
      }
      if (!is_email($lookup)) return $user_data;

      $matched_user = $this->user_by_personal_email($lookup);
      if (!$matched_user) return $user_data;

      if ($errors instanceof WP_Error && method_exists($errors, 'remove')) {
        $errors->remove('invalid_email');
        $errors->remove('invalidcombo');
      }

      return $matched_user;
    } catch (\Throwable $e) {
      $this->log_email_runtime_issue('filter_lostpassword_user_data', $e);
      return $user_data;
    }
  }

  public function filter_send_retrieve_password_email(bool $send, string $user_login, $user_data): bool {
    try {
      if (!$send || !($user_data instanceof WP_User)) return $send;

      $to = $this->personal_email_for_user((int)$user_data->ID);
      if (!is_email($to)) return $send;

      return !$this->send_personal_reset_email((int)$user_data->ID, 0);
    } catch (\Throwable $e) {
      $this->log_email_runtime_issue('filter_send_retrieve_password_email', $e);
      return $send;
    }
  }

  public function filter_retrieve_password_notification_email(array $defaults, string $key, string $user_login, $user_data): array {
    try {
      if (!($user_data instanceof WP_User)) return $defaults;

      $to = $this->personal_email_for_user((int)$user_data->ID);
      if (!is_email($to)) return $defaults;

      $settings = self::settings();
      $reset_link = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user_login), 'login');
      $context = $this->reset_email_context($user_data, $reset_link, 0);
      $is_html = !empty($settings['reset_is_html']);
      $body_resolved = $this->replace_placeholders((string)$settings['reset_body'], $context);

      $defaults['to'] = $to;
      $defaults['subject'] = $this->replace_placeholders((string)$settings['reset_subject'], $context);
      $defaults['message'] = $is_html ? $this->sanitize_email_template_html($body_resolved) : wp_strip_all_tags($body_resolved);
      $defaults['headers'] = $this->template_headers('reset', $to, $context, $settings, $is_html);

      return $defaults;
    } catch (\Throwable $e) {
      $this->log_email_runtime_issue('filter_retrieve_password_notification_email', $e);
      return $defaults;
    }
  }

  private function personal_email_for_user(int $user_id): string {
    $custom = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    return sanitize_email((string)($custom['personal_email'] ?? ''));
  }

  private function user_by_personal_email(string $email): ?WP_User {
    if (!class_exists('EUM_Fields') || !is_email($email)) return null;

    $candidates = get_users([
      'number' => 5,
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
        return $candidate;
      }
    }

    return null;
  }

  private function build_reset_link($user): string {
    $key = get_password_reset_key($user);
    if (is_wp_error($key)) return wp_lostpassword_url();
    return network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login), 'login');
  }

  private function reset_email_context(WP_User $user, string $reset_link, int $actor_user_id = 0): array {
    return $this->build_payload_context([
      'user_id' => (int)$user->ID,
      'actor_user_id' => $actor_user_id,
      'context' => [
        'user_name' => $user->display_name ?: $user->user_login,
        'reset_link' => $reset_link,
        'username' => $user->user_login,
        'work_email' => $user->user_email,
      ],
    ]);
  }

  private function template_headers(string $type, string $to, array $context, array $settings, bool $is_html): array {
    $headers = [];
    if ($is_html) {
      $headers[] = 'Content-Type: text/html; charset=UTF-8';
    }

    $from_email_raw = $this->replace_placeholders((string)($settings[$type . '_from_email'] ?? ''), $context);
    $from_email = sanitize_email($from_email_raw);
    $from_name = sanitize_text_field($this->replace_placeholders((string)($settings[$type . '_from_name'] ?? ''), $context));
    if ($from_email !== '') {
      $from_header = ($from_name !== '' ? wp_specialchars_decode($from_name, ENT_QUOTES) . ' <' . $from_email . '>' : $from_email);
      $headers[] = 'From: ' . $from_header;
      $headers[] = 'Reply-To: ' . $from_header;
      $headers[] = 'Sender: ' . $from_email;
    }

    $cc_line = $this->replace_placeholders((string)($settings[$type . '_cc'] ?? ''), $context);
    $cc_seen = [];
    foreach ($this->extract_email_list($cc_line) as $cc_email) {
      $cc_key = strtolower($cc_email);
      if ($cc_email !== $to && !isset($cc_seen[$cc_key])) {
        $headers[] = 'Cc: ' . $cc_email;
        $cc_seen[$cc_key] = true;
      }
    }

    foreach ($this->manager_cc_emails_for_context($type, $context, $to) as $cc_email) {
      $cc_key = strtolower($cc_email);
      if (!isset($cc_seen[$cc_key])) {
        $headers[] = 'Cc: ' . $cc_email;
        $cc_seen[$cc_key] = true;
      }
    }

    return $headers;
  }

  private function manager_cc_emails_for_context(string $type, array $context, string $to): array {
    if ($type === 'reset') return [];
    $to = strtolower(sanitize_email($to));
    $ids = [];
    $meta_context = isset($context['_meta_context']) && is_array($context['_meta_context']) ? $context['_meta_context'] : [];

    foreach (['user', 'manager', 'job_owner'] as $bucket) {
      $user_id = (int)($meta_context[$bucket] ?? 0);
      if ($bucket === 'user' && $user_id > 0) {
        $ids = array_merge($ids, $this->manager_chain_user_ids_for_user($user_id));
      } elseif ($user_id > 0) {
        $ids[] = $user_id;
        $ids = array_merge($ids, $this->manager_chain_user_ids_for_user($user_id));
      }
    }

    $emails = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0))) as $user_id) {
      $email = $this->work_email_for_user($user_id);
      if ($email !== '' && strtolower($email) !== $to) $emails[strtolower($email)] = $email;
    }
    return array_values($emails);
  }

  private function manager_chain_user_ids_for_user(int $user_id): array {
    if ($user_id <= 0) return [];
    $ids = [];
    if (class_exists('EUM_Manager_IDs') && method_exists('EUM_Manager_IDs', 'manager_user_ids_for_subject_user')) {
      $ids = array_merge($ids, (array)EUM_Manager_IDs::manager_user_ids_for_subject_user($user_id));
    }
    if (class_exists('EUM_Personnel')) {
      $effective = EUM_Personnel::effective_manager_user_id($user_id);
      if ($effective > 0) $ids[] = $effective;
    }
    return array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0 && $id !== $user_id)));
  }

  private function work_email_for_user(int $user_id): string {
    $user = $user_id > 0 ? get_user_by('id', $user_id) : false;
    return $user ? sanitize_email((string)$user->user_email) : '';
  }

  private function send_template(string $type, string $to, string $subject_tpl, string $body_tpl, array $context = [], array $settings = []): bool {
    if (!is_email($to)) return false;
    $settings = $settings ?: self::settings();
    $subject = $this->replace_placeholders($subject_tpl, $context);
    $is_html = !empty($settings[$type . '_is_html']);
    $body_resolved = $this->replace_placeholders((string)$body_tpl, $context);
    $body = $is_html ? $this->sanitize_email_template_html($body_resolved) : wp_strip_all_tags($body_resolved);
    $headers = $this->template_headers($type, $to, $context, $settings, $is_html);

    $this->active_mail_transport = $settings;
    if (!empty($settings['mail_transport']) && (string)$settings['mail_transport'] === 'smtp') {
      add_action('phpmailer_init', [$this, 'configure_phpmailer_transport']);
    }
    try {
      try {
        return wp_mail($to, $subject, $body, $headers);
      } catch (\Throwable $e) {
        /**
         * Candidate applications and other core workflows should keep working
         * even if the configured mail transport throws during delivery.
         */
        do_action('wp_mail_failed', new WP_Error('eum_mail_send_failed', $e->getMessage(), [
          'to' => $to,
          'subject' => $subject,
          'headers' => $headers,
        ]));
        if (defined('WP_DEBUG') && WP_DEBUG) eum_log_message('[EUM Email Notifications] ' . $e->getMessage());
        return false;
      }
    } finally {
      if (!empty($settings['mail_transport']) && (string)$settings['mail_transport'] === 'smtp') {
        remove_action('phpmailer_init', [$this, 'configure_phpmailer_transport']);
      }
      $this->active_mail_transport = null;
    }
  }

  public function filter_wp_mail_sender_cc(array $args): array {
    try {
      $sender_cc = $this->sender_cc_email_for_wp_mail($args);
      if ($sender_cc === '') return $args;

      $existing = [];
      foreach ($this->extract_mail_addresses($args['to'] ?? []) as $email) {
        $existing[strtolower($email)] = true;
      }

      $original_headers = $args['headers'] ?? [];
      $header_lines = $this->normalize_mail_headers($original_headers);
      foreach ($this->header_recipient_emails($header_lines) as $email) {
        $existing[strtolower($email)] = true;
      }

      if (isset($existing[strtolower($sender_cc)])) return $args;

      $header_lines[] = 'Cc: ' . $sender_cc;
      $args['headers'] = is_array($original_headers) ? $header_lines : implode("\r\n", $header_lines);
      return $args;
    } catch (\Throwable $e) {
      $this->log_email_runtime_issue('filter_wp_mail_sender_cc', $e);
      return $args;
    }
  }

  public function configure_phpmailer_transport($phpmailer): void {
    try {
      $settings = is_array($this->active_mail_transport) ? $this->active_mail_transport : [];
      if (($settings['mail_transport'] ?? 'wordpress') !== 'smtp') return;
      $host = trim((string)($settings['smtp_host'] ?? ''));
      if ($host === '') return;
      $phpmailer->isSMTP();
      $phpmailer->Host = $host;
      $phpmailer->Port = max(1, (int)($settings['smtp_port'] ?? 587));
      $secure = (string)($settings['smtp_secure'] ?? 'tls');
      $phpmailer->SMTPSecure = ($secure === 'none') ? '' : $secure;
      $phpmailer->SMTPAuth = !empty($settings['smtp_auth']);
      if ($phpmailer->SMTPAuth) {
        $phpmailer->Username = (string)($settings['smtp_username'] ?? '');
        $phpmailer->Password = (string)($settings['smtp_password'] ?? '');
      }
      $phpmailer->Timeout = max(5, (int)($settings['smtp_timeout'] ?? 20));
      $envelope = sanitize_email((string)($settings['smtp_from_envelope'] ?? ''));
      if ($envelope !== '') {
        $phpmailer->Sender = $envelope;
      }
    } catch (\Throwable $e) {
      $this->log_email_runtime_issue('configure_phpmailer_transport', $e);
    }
  }

  private function build_test_payload(string $type, int $user_id, int $candidate_id, int $job_id, int $change_id, int $actor_user_id): array {
    $candidate_data = [];
    if ($candidate_id > 0 && class_exists('EUM_Job_Boards')) {
      $job_id = $job_id > 0 ? $job_id : (int)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_JOB_ID, true);
      $candidate_data = [
        'candidate_name' => (string)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_FULL_NAME, true),
        'full_name' => (string)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_FULL_NAME, true),
        'email' => (string)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_EMAIL, true),
        'phone' => (string)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_PHONE, true),
        'applied_at' => (string)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_APPLIED_AT, true),
      ];
      $candidate_profile = get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_PROFILE, true);
      $candidate_data = $this->candidate_data_with_profile($candidate_data, is_array($candidate_profile) ? $candidate_profile : []);
      $linked_user = (int)get_post_meta($candidate_id, EUM_Job_Boards::CAND_META_USER_ID, true);
      if ($user_id <= 0 && $linked_user > 0) $user_id = $linked_user;
    }

    $job_term_id = 0;
    $job_owner_user_id = 0;
    if ($job_id > 0 && class_exists('EUM_Job_Boards')) {
      $job_term_id = (int)get_post_meta($job_id, EUM_Job_Boards::JOB_META_JOB_TERM, true);
      $job_owner_user_id = (int)get_post_meta($job_id, EUM_Job_Boards::JOB_META_OWNER_UID, true);
      if ($job_owner_user_id <= 0) $job_owner_user_id = (int)get_post_field('post_author', $job_id);
      $candidate_data['job_title'] = (string)get_post_meta($job_id, EUM_Job_Boards::JOB_META_NEAT_TITLE, true);
      $candidate_data['job_path'] = $this->term_path('eum_job_path', $job_term_id);
    }

    $context = [
      'reason' => $change_id > 0 && class_exists('EUM_Profiles') ? (string)get_post_meta($change_id, EUM_Profiles::META_NOTE, true) : 'Test email',
      'applied_at' => $candidate_data['applied_at'] ?? current_time('mysql'),
      'changed_fields' => "title: Example Title\ncompany_div: Example Division",
      'changed_fields_html' => 'title: Example Title<br>company_div: Example Division',
    ];

    if ($user_id > 0) {
      $user = get_user_by('id', $user_id);
      if ($user) {
        $context['user_name'] = $user->display_name ?: $user->user_login;
        $context['username'] = $user->user_login;
        $context['work_email'] = $user->user_email;
        $context['reset_link'] = $this->build_reset_link($user);
      }
      if ($job_term_id <= 0) $job_term_id = $this->current_job_term_id($user_id);
    }

    if ($type === 'application' && $candidate_id <= 0 && $user_id > 0) {
      $user = get_user_by('id', $user_id);
      if ($user) {
        $candidate_data['candidate_name'] = $user->display_name ?: $user->user_login;
        $candidate_data['email'] = $this->personal_email_for_user($user_id);
      }
    }

    return $this->build_payload_context([
      'user_id' => $user_id,
      'candidate_id' => $candidate_id,
      'candidate_data' => $candidate_data,
      'job_id' => $job_id,
      'job_term_id' => $job_term_id,
      'change_id' => $change_id,
      'actor_user_id' => $actor_user_id,
      'job_owner_user_id' => $job_owner_user_id,
      'context' => $context,
    ]);
  }

  private function candidate_data_with_profile(array $candidate_data, array $profile): array {
    foreach ($profile as $key => $value) {
      if (!is_scalar($value)) continue;
      $string_value = (string)$value;
      $candidate_data[(string)$key] = $string_value;

      if (str_contains((string)$key, ':')) {
        [, $alias] = explode(':', (string)$key, 2);
        if ($alias !== '' && !array_key_exists($alias, $candidate_data)) {
          $candidate_data[$alias] = $string_value;
        }
      }
    }

    $division_key = (string)($profile['core:job_company'] ?? '');
    if ($division_key !== '') {
      $division_label = class_exists('EUM_Company_Divisions') ? EUM_Company_Divisions::division_label($division_key) : $division_key;
      $candidate_data['job_company'] = $division_key;
      $candidate_data['company_div_key'] = $division_key;
      $candidate_data['company_division_key'] = $division_key;
      $candidate_data['company_div'] = $division_label !== '' ? $division_label : $division_key;
      $candidate_data['company_division'] = $division_label !== '' ? $division_label : $division_key;
    }

    return $candidate_data;
  }

  private function build_payload_context(array $args): array {
    $user_id = (int)($args['user_id'] ?? 0);
    $candidate_id = (int)($args['candidate_id'] ?? 0);
    $job_id = (int)($args['job_id'] ?? 0);
    $job_term_id = (int)($args['job_term_id'] ?? 0);
    $change_id = (int)($args['change_id'] ?? 0);
    $actor_user_id = (int)($args['actor_user_id'] ?? 0);
    $job_owner_user_id = (int)($args['job_owner_user_id'] ?? 0);
    $candidate_data = isset($args['candidate_data']) && is_array($args['candidate_data']) ? $args['candidate_data'] : [];
    $context = isset($args['context']) && is_array($args['context']) ? $args['context'] : [];

    $manager_user_id = 0;
    if ($user_id > 0 && class_exists('EUM_Personnel')) {
      $manager_user_id = EUM_Personnel::effective_manager_user_id($user_id);
    }
    if ($job_owner_user_id <= 0 && $job_id > 0 && class_exists('EUM_Job_Boards')) {
      $job_owner_user_id = (int)get_post_meta($job_id, EUM_Job_Boards::JOB_META_OWNER_UID, true);
      if ($job_owner_user_id <= 0) $job_owner_user_id = (int)get_post_field('post_author', $job_id);
    }

    $base = [
      'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
      'job_title' => $job_id > 0 && class_exists('EUM_Job_Boards') ? (string)get_post_meta($job_id, EUM_Job_Boards::JOB_META_NEAT_TITLE, true) : '',
      'job_path' => $job_term_id > 0 ? $this->term_path('eum_job_path', $job_term_id) : '',
      'reason' => $change_id > 0 && class_exists('EUM_Profiles') ? (string)get_post_meta($change_id, EUM_Profiles::META_NOTE, true) : '',
    ];

    $base = array_merge($base, $context);
    $base = array_merge($base, $this->user_context('user', $user_id));
    $base = array_merge($base, $this->user_context('manager', $manager_user_id));
    $base = array_merge($base, $this->user_context('job_owner', $job_owner_user_id));
    $base = array_merge($base, $this->user_context('actor', $actor_user_id));

    if (!empty($candidate_data['candidate_name']) && empty($base['candidate_name'])) {
      $base['candidate_name'] = (string)$candidate_data['candidate_name'];
    }
    if (!empty($candidate_data['email']) && empty($base['candidate_email'])) {
      $base['candidate_email'] = (string)$candidate_data['email'];
    }

    $base['_meta_context'] = [
      'user' => $user_id,
      'manager' => $manager_user_id,
      'job_owner' => $job_owner_user_id,
      'actor' => $actor_user_id,
      'candidate' => $candidate_id,
    ];
    $base['_candidate_data'] = $candidate_data;

    return $base;
  }

  private function user_context(string $prefix, int $user_id): array {
    if ($user_id <= 0) return [];
    $user = get_user_by('id', $user_id);
    if (!$user) return [];

    $custom = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    $name = $user->display_name ?: $user->user_login;
    $first_name = (string)get_user_meta($user_id, 'first_name', true);
    $last_name = (string)get_user_meta($user_id, 'last_name', true);
    if ($prefix === 'user') {
      return [
        'user_name' => $name,
        'username' => $user->user_login,
        'user_login' => $user->user_login,
        'work_email' => $user->user_email,
        'user_email' => $user->user_email,
        'user_personal_email' => sanitize_email((string)($custom['personal_email'] ?? '')),
        'user_first_name' => $first_name,
        'user_last_name' => $last_name,
      ];
    }

    return [
      $prefix . '_name' => $name,
      $prefix . '_username' => $user->user_login,
      $prefix . '_email' => $user->user_email,
      $prefix . '_work_email' => $user->user_email,
      $prefix . '_personal_email' => sanitize_email((string)($custom['personal_email'] ?? '')),
      $prefix . '_first_name' => $first_name,
      $prefix . '_last_name' => $last_name,
      $prefix . '_id' => (string)$user_id,
    ];
  }

  private function replace_placeholders(string $template, array $context): string {
    if ($template === '') return '';

    $replace = [];
    foreach ($context as $k => $v) {
      if ($k === '_meta_context' || $k === '_candidate_data') continue;
      $replace['{{' . $k . '}}'] = is_scalar($v) ? (string)$v : wp_json_encode($v);
    }
    $output = strtr($template, $replace);

    $meta_context = isset($context['_meta_context']) && is_array($context['_meta_context']) ? $context['_meta_context'] : [];
    $candidate_data = isset($context['_candidate_data']) && is_array($context['_candidate_data']) ? $context['_candidate_data'] : [];

    $output = preg_replace_callback('/\\{\\{\s*(user|manager|job_owner|actor)_meta:([^}]+)\s*\\}\\}/', function($m) use ($meta_context) {
      $bucket = sanitize_key((string)$m[1]);
      $meta_key = sanitize_key((string)$m[2]);
      $user_id = (int)($meta_context[$bucket] ?? 0);
      if ($user_id <= 0 || $meta_key === '') return '';
      return $this->lookup_user_meta_value($user_id, $meta_key);
    }, $output);

    $output = preg_replace_callback('/\{\{\s*candidate_data:([^}]+)\s*\}\}/', function($m) use ($candidate_data) {
      $key = trim((string)$m[1]);
      if ($key === '') return '';
      return is_scalar($candidate_data[$key] ?? null) ? (string)$candidate_data[$key] : '';
    }, $output);

    $output = preg_replace_callback('/\{\{\s*candidate_meta:([^}]+)\s*\}\}/', function($m) use ($meta_context) {
      $candidate_id = (int)($meta_context['candidate'] ?? 0);
      $meta_key = sanitize_key((string)$m[1]);
      if ($candidate_id <= 0 || $meta_key === '') return '';
      $value = get_post_meta($candidate_id, $meta_key, true);
      if (is_scalar($value)) return (string)$value;
      return is_array($value) ? wp_json_encode($value) : '';
    }, $output);

    return $output;
  }



  private function sanitize_email_template_html(string $html): string {
    if ($html === '') return '';

    $allowed = [
      'html' => ['lang' => true, 'dir' => true],
      'head' => [],
      'meta' => ['charset' => true, 'http-equiv' => true, 'content' => true, 'name' => true],
      'title' => [],
      'style' => ['type' => true, 'media' => true],
      'body' => ['class' => true, 'id' => true, 'style' => true],
      'div' => ['class' => true, 'id' => true, 'style' => true, 'align' => true, 'width' => true],
      'span' => ['class' => true, 'id' => true, 'style' => true, 'align' => true],
      'p' => ['class' => true, 'id' => true, 'style' => true, 'align' => true],
      'br' => [],
      'hr' => ['class' => true, 'id' => true, 'style' => true],
      'strong' => ['class' => true, 'id' => true, 'style' => true],
      'b' => ['class' => true, 'id' => true, 'style' => true],
      'em' => ['class' => true, 'id' => true, 'style' => true],
      'i' => ['class' => true, 'id' => true, 'style' => true],
      'u' => ['class' => true, 'id' => true, 'style' => true],
      'small' => ['class' => true, 'id' => true, 'style' => true],
      'sup' => ['class' => true, 'id' => true, 'style' => true],
      'sub' => ['class' => true, 'id' => true, 'style' => true],
      'blockquote' => ['class' => true, 'id' => true, 'style' => true],
      'h1' => ['class' => true, 'id' => true, 'style' => true, 'align' => true],
      'h2' => ['class' => true, 'id' => true, 'style' => true, 'align' => true],
      'h3' => ['class' => true, 'id' => true, 'style' => true, 'align' => true],
      'h4' => ['class' => true, 'id' => true, 'style' => true, 'align' => true],
      'h5' => ['class' => true, 'id' => true, 'style' => true, 'align' => true],
      'h6' => ['class' => true, 'id' => true, 'style' => true, 'align' => true],
      'ul' => ['class' => true, 'id' => true, 'style' => true],
      'ol' => ['class' => true, 'id' => true, 'style' => true],
      'li' => ['class' => true, 'id' => true, 'style' => true],
      'a' => ['class' => true, 'id' => true, 'style' => true, 'href' => true, 'target' => true, 'rel' => true, 'title' => true, 'name' => true],
      'img' => ['class' => true, 'id' => true, 'style' => true, 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'border' => true],
      'table' => ['class' => true, 'id' => true, 'style' => true, 'width' => true, 'height' => true, 'border' => true, 'cellpadding' => true, 'cellspacing' => true, 'align' => true, 'bgcolor' => true, 'role' => true],
      'thead' => ['class' => true, 'id' => true, 'style' => true],
      'tbody' => ['class' => true, 'id' => true, 'style' => true],
      'tfoot' => ['class' => true, 'id' => true, 'style' => true],
      'tr' => ['class' => true, 'id' => true, 'style' => true, 'align' => true, 'valign' => true, 'bgcolor' => true],
      'td' => ['class' => true, 'id' => true, 'style' => true, 'width' => true, 'height' => true, 'colspan' => true, 'rowspan' => true, 'align' => true, 'valign' => true, 'bgcolor' => true],
      'th' => ['class' => true, 'id' => true, 'style' => true, 'width' => true, 'height' => true, 'colspan' => true, 'rowspan' => true, 'align' => true, 'valign' => true, 'bgcolor' => true, 'scope' => true],
    ];

    $style_props = [
      'background', 'background-color', 'background-image', 'background-position', 'background-repeat', 'background-size',
      'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-radius', 'border-collapse', 'border-spacing',
      'color', 'display', 'float', 'clear', 'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing',
      'line-height', 'list-style-type', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
      'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
      'text-align', 'text-decoration', 'text-transform', 'vertical-align', 'white-space', 'width', 'min-width', 'max-width',
      'height', 'min-height', 'max-height', 'overflow', 'word-break', 'word-wrap', 'mso-line-height-rule', 'mso-table-lspace',
      'mso-table-rspace', 'opacity', 'direction'
    ];

    add_filter('safe_style_css', [$this, 'filter_safe_style_css_for_email'], 100);
    $GLOBALS['eum_email_safe_style_css'] = $style_props;
    try {
      $clean = wp_kses($html, $allowed, ['http', 'https', 'mailto', 'tel', 'data']);
    } finally {
      remove_filter('safe_style_css', [$this, 'filter_safe_style_css_for_email'], 100);
      unset($GLOBALS['eum_email_safe_style_css']);
    }

    return $clean;
  }

  public function filter_safe_style_css_for_email(array $props): array {
    $extra = isset($GLOBALS['eum_email_safe_style_css']) && is_array($GLOBALS['eum_email_safe_style_css']) ? $GLOBALS['eum_email_safe_style_css'] : [];
    return array_values(array_unique(array_merge($props, $extra)));
  }

  private function lookup_user_meta_value(int $user_id, string $meta_key): string {
    $meta_key = sanitize_key($meta_key);
    $custom = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    if (in_array($meta_key, ['job_company', 'company_div', 'company_division'], true)) {
      $label = $this->email_division_label_for_user($user_id);
      if ($label !== '') return $label;
    }
    if ($meta_key === 'job_title') {
      $label = $this->email_job_title_label_for_user($user_id, $custom);
      if ($label !== '') return $label;
    }
    if ($meta_key === 'title') {
      if (array_key_exists('title', $custom) && is_scalar($custom['title']) && (string)$custom['title'] !== '') {
        return (string)$custom['title'];
      }
      $label = $this->email_job_title_label_for_user($user_id, $custom);
      if ($label !== '') return $label;
    }
    if (array_key_exists($meta_key, $custom) && is_scalar($custom[$meta_key])) return (string)$custom[$meta_key];
    $value = get_user_meta($user_id, $meta_key, true);
    if (is_scalar($value)) return (string)$value;
    if ($meta_key === 'personal_email' && isset($custom['personal_email'])) return (string)$custom['personal_email'];
    return '';
  }

  private function email_division_label_for_user(int $user_id): string {
    if ($user_id <= 0) return '';
    if (class_exists('EUM_Profiles')) {
      $label = trim((string)EUM_Profiles::get_field_display_value($user_id, 'core:job_company'));
      if ($label !== '') return $label;
    }
    if (class_exists('EUM_Company_Divisions')) {
      $division_key = EUM_Company_Divisions::effective_division_for_user($user_id);
      $label = trim((string)EUM_Company_Divisions::division_label($division_key));
      if ($label !== '') return $label;
    }
    $raw = get_user_meta($user_id, 'job_company', true);
    return is_scalar($raw) ? sanitize_text_field((string)$raw) : '';
  }

  private function email_job_title_label_for_user(int $user_id, array $custom = []): string {
    if ($user_id <= 0) return '';
    if (empty($custom) && class_exists('EUM_Fields')) {
      $custom = (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true);
    }
    $raw = isset($custom['job_title']) && is_scalar($custom['job_title']) ? (string)$custom['job_title'] : '';
    if (class_exists('EUM_Profiles')) {
      $label = trim((string)EUM_Profiles::job_title_label_from_value($raw));
      if ($label !== '') return $label;
    }
    return $raw !== '' ? sanitize_text_field($raw) : '';
  }

  private function sender_cc_email_for_wp_mail(array $args): string {
    $actor_user_id = get_current_user_id();
    if ($actor_user_id > 0) {
      $email = $this->work_email_for_user($actor_user_id);
      if (!is_email($email)) $email = $this->personal_email_for_user($actor_user_id);
      if (is_email($email)) return $email;
    }

    $header_lines = $this->normalize_mail_headers($args['headers'] ?? []);
    foreach (['reply-to', 'from', 'sender'] as $prefix) {
      $email = $this->first_header_email($header_lines, $prefix);
      if (is_email($email)) return $email;
    }

    return '';
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

  private function first_header_email(array $headers, string $prefix): string {
    $prefix = strtolower(trim($prefix));
    foreach ($headers as $line) {
      if (!is_string($line) || stripos($line, $prefix . ':') !== 0) continue;
      $addresses = $this->extract_mail_addresses(substr($line, strlen($prefix) + 1));
      if (!empty($addresses)) return $addresses[0];
    }
    return '';
  }

  private function header_recipient_emails(array $headers): array {
    $emails = [];
    foreach ($headers as $line) {
      if (!is_string($line) || !preg_match('/^(to|cc|bcc)\s*:/i', $line)) continue;
      foreach ($this->extract_mail_addresses(preg_replace('/^[^:]+:/', '', $line, 1)) as $email) {
        $emails[strtolower($email)] = $email;
      }
    }
    return array_values($emails);
  }

  private function extract_mail_addresses($raw): array {
    if (is_array($raw)) {
      $emails = [];
      foreach ($raw as $value) {
        foreach ($this->extract_mail_addresses($value) as $email) {
          $emails[strtolower($email)] = $email;
        }
      }
      return array_values($emails);
    }

    if (!is_scalar($raw) || (string)$raw === '') return [];

    $parts = preg_split('/[\r\n,;]+/', (string)$raw);
    $emails = [];
    foreach ((array)$parts as $part) {
      $email = $this->normalize_mail_address((string)$part);
      if ($email !== '' && is_email($email)) $emails[strtolower($email)] = $email;
    }
    return array_values($emails);
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
    $out = [];
    foreach ((array)$parts as $part) {
      $email = sanitize_email(trim((string)$part));
      if ($email !== '' && is_email($email)) $out[$email] = $email;
    }
    return array_values($out);
  }

  private function current_job_term_id(int $user_id): int {
    if ($user_id <= 0) return 0;
    $terms = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($terms) || empty($terms)) return 0;
    return (int)$terms[0];
  }

  private function available_placeholders(): array {
    return [
      '{{site_name}}',
      '{{user_name}}', '{{user_first_name}}', '{{user_last_name}}', '{{username}}', '{{user_login}}', '{{work_email}}', '{{user_email}}', '{{user_personal_email}}',
      '{{candidate_name}}', '{{candidate_email}}', '{{candidate_data:first_name}}',
      '{{job_title}}', '{{job_path}}', '{{job_owner_name}}', '{{job_owner_email}}', '{{job_owner_personal_email}}',
      '{{manager_name}}', '{{manager_email}}', '{{manager_personal_email}}',
      '{{actor_name}}', '{{actor_email}}',
      '{{reset_link}}', '{{reason}}', '{{changed_fields}}', '{{changed_fields_html}}', '{{applied_at}}',
      '{{user_meta:key}}', '{{manager_meta:key}}', '{{job_owner_meta:key}}', '{{actor_meta:key}}', '{{candidate_meta:key}}',
    ];
  }

  private function term_path(string $tax, int $term_id): string {
    if ($term_id <= 0 || !term_exists($term_id, $tax)) return '';
    $parts = [];
    $chain = array_reverse(array_merge(get_ancestors($term_id, $tax), [$term_id]));
    foreach ($chain as $tid) {
      $term = get_term((int)$tid, $tax);
      if ($term && !is_wp_error($term)) $parts[] = (string)$term->name;
    }
    return implode(' / ', $parts);
  }

  private function log_email_runtime_issue(string $context, \Throwable $e): void {
    eum_log_message('[EUM Email Notifications] ' . $context . ': ' . $e->getMessage());
  }
}
