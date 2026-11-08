<?php
if (!defined('ABSPATH')) exit;

class EUM_Fonts {
  const OPTION_KEY = 'eum_font_settings';

  public function init(): void {
    add_action('admin_menu', [$this, 'admin_menu'], 30);
    add_action('admin_post_eum_font_save', [$this, 'handle_save']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_font_face'], 20);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_font_face'], 20);
    add_filter('upload_mimes', [$this, 'allow_font_mimes']);
    add_filter('wp_check_filetype_and_ext', [$this, 'fix_font_filetype_check'], 10, 5);
  }

  public static function default_settings(): array {
    return [
      'family' => '',
      'attachment_id' => 0,
      'url' => '',
      'filename' => '',
      'weight' => '400',
      'style' => 'normal',
      'display' => 'swap',
    ];
  }

  public static function get_settings(): array {
    $saved = get_option(self::OPTION_KEY, []);
    $saved = is_array($saved) ? $saved : [];
    $settings = array_merge(self::default_settings(), $saved);

    $settings['family'] = sanitize_text_field((string)($settings['family'] ?? ''));
    $settings['attachment_id'] = max(0, (int)($settings['attachment_id'] ?? 0));
    $settings['url'] = esc_url_raw((string)($settings['url'] ?? ''));
    $settings['filename'] = sanitize_file_name((string)($settings['filename'] ?? ''));

    $weight = trim((string)($settings['weight'] ?? '400'));
    if (!preg_match('/^(normal|bold|[1-9]00)$/', $weight)) $weight = '400';
    $settings['weight'] = $weight;

    $style = sanitize_key((string)($settings['style'] ?? 'normal'));
    if (!in_array($style, ['normal', 'italic', 'oblique'], true)) $style = 'normal';
    $settings['style'] = $style;

    $display = sanitize_key((string)($settings['display'] ?? 'swap'));
    if (!in_array($display, ['auto', 'block', 'swap', 'fallback', 'optional'], true)) $display = 'swap';
    $settings['display'] = $display;

    if ($settings['attachment_id'] > 0) {
      $attachment_url = wp_get_attachment_url($settings['attachment_id']);
      if (is_string($attachment_url) && $attachment_url !== '') {
        $settings['url'] = esc_url_raw($attachment_url);
      }
      $attachment_file = get_attached_file($settings['attachment_id']);
      if (is_string($attachment_file) && $attachment_file !== '') {
        $settings['filename'] = sanitize_file_name(basename($attachment_file));
      }
    }

    return $settings;
  }

  public function admin_menu(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) return;

    add_submenu_page(
      EUM_Admin::parent_slug('experience'),
      'Fonts',
      'Fonts',
      'read',
      'eum-fonts',
      [$this, 'render_page']
    );
  }

  public function render_page(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');

    $settings = self::get_settings();
    $msg = isset($_GET['eum_font_msg']) ? sanitize_text_field((string)$_GET['eum_font_msg']) : '';
    $type = isset($_GET['eum_font_type']) ? sanitize_key((string)$_GET['eum_font_type']) : 'success';
    $sample_family = $settings['family'] !== '' ? ('"' . esc_attr($settings['family']) . '", sans-serif') : 'sans-serif';

    echo '<div class="wrap"><h1>Enterprise Fonts</h1>';
    echo '<p>Upload a font file once and this plugin will load it locally on the front end and in wp-admin. That way any CSS using the same font-family name can render even on devices that do not have the font installed.</p>';

    if ($msg !== '') {
      $notice = $type === 'error' ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($notice) . '"><p>' . esc_html($msg) . '</p></div>';
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" style="max-width:840px;">';
    echo '<input type="hidden" name="action" value="eum_font_save">';
    wp_nonce_field('eum_font_save', 'eum_font_nonce');

    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="eum_font_family">Font Family Name</label></th><td>';
    echo '<input type="text" class="regular-text" id="eum_font_family" name="eum_font_family" value="' . esc_attr($settings['family']) . '" placeholder="Example: Outfit">';
    echo '<p class="description">Use the exact family name your theme or custom CSS expects.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="eum_font_upload">Font File</label></th><td>';
    echo '<input type="file" id="eum_font_upload" name="eum_font_upload" accept=".woff,.woff2,.ttf,.otf">';
    echo '<p class="description">Supported files: .woff, .woff2, .ttf, .otf</p>';
    if ($settings['url'] !== '') {
      echo '<p><strong>Current file:</strong> <a href="' . esc_url($settings['url']) . '" target="_blank" rel="noopener">' . esc_html($settings['filename'] ?: basename((string)parse_url($settings['url'], PHP_URL_PATH))) . '</a></p>';
    }
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="eum_font_weight">Font Weight</label></th><td>';
    echo '<input type="text" class="small-text" id="eum_font_weight" name="eum_font_weight" value="' . esc_attr($settings['weight']) . '" placeholder="400">';
    echo '<p class="description">Examples: 400, 500, 700, normal, bold</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="eum_font_style">Font Style</label></th><td>';
    echo '<select id="eum_font_style" name="eum_font_style">';
    foreach (['normal' => 'Normal', 'italic' => 'Italic', 'oblique' => 'Oblique'] as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($settings['style'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="eum_font_display">Font Display</label></th><td>';
    echo '<select id="eum_font_display" name="eum_font_display">';
    foreach (['swap' => 'swap', 'auto' => 'auto', 'block' => 'block', 'fallback' => 'fallback', 'optional' => 'optional'] as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($settings['display'], $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Current Status</th><td>';
    if ($settings['family'] !== '' && $settings['url'] !== '') {
      echo '<p><strong>Loaded family:</strong> ' . esc_html($settings['family']) . '</p>';
      echo '<div style="border:1px solid #dcdcde;border-radius:10px;padding:18px;background:#fff;font-family:' . $sample_family . ';font-size:28px;line-height:1.3;">The quick brown fox jumps over the lazy dog.</div>';
      echo '<p class="description">The plugin injects an @font-face rule site-wide. It does not force every element to use this font; it makes the font available locally anywhere that family name is referenced.</p>';
    } else {
      echo '<p>No local font is configured yet.</p>';
    }
    echo '<p><label><input type="checkbox" name="eum_font_remove" value="1"> Remove the current local font mapping</label></p>';
    echo '</td></tr>';

    echo '</tbody></table>';
    submit_button('Save Font Settings');
    echo '</form></div>';
  }

  public function handle_save(): void {
    if (!is_user_logged_in() || !class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    check_admin_referer('eum_font_save', 'eum_font_nonce');

    $settings = self::get_settings();
    $remove = !empty($_POST['eum_font_remove']);
    if ($remove) {
      update_option(self::OPTION_KEY, self::default_settings(), false);
      $this->redirect_with_message('Local font removed.');
    }

    $family = sanitize_text_field((string)($_POST['eum_font_family'] ?? $settings['family']));
    $weight = trim((string)($_POST['eum_font_weight'] ?? $settings['weight']));
    if (!preg_match('/^(normal|bold|[1-9]00)$/', $weight)) $weight = '400';
    $style = sanitize_key((string)($_POST['eum_font_style'] ?? $settings['style']));
    if (!in_array($style, ['normal', 'italic', 'oblique'], true)) $style = 'normal';
    $display = sanitize_key((string)($_POST['eum_font_display'] ?? $settings['display']));
    if (!in_array($display, ['auto', 'block', 'swap', 'fallback', 'optional'], true)) $display = 'swap';

    $payload = [
      'family' => $family,
      'attachment_id' => $settings['attachment_id'],
      'url' => $settings['url'],
      'filename' => $settings['filename'],
      'weight' => $weight,
      'style' => $style,
      'display' => $display,
    ];

    if (!empty($_FILES['eum_font_upload']['name'])) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
      require_once ABSPATH . 'wp-admin/includes/media.php';

      $attachment_id = media_handle_upload('eum_font_upload', 0);
      if (is_wp_error($attachment_id)) {
        $this->redirect_with_message($attachment_id->get_error_message(), 'error');
      }

      $attachment_url = wp_get_attachment_url($attachment_id);
      if (!is_string($attachment_url) || $attachment_url === '') {
        $this->redirect_with_message('The uploaded font could not be resolved.', 'error');
      }

      if ($family === '') {
        $family = sanitize_text_field((string)get_the_title($attachment_id));
      }

      $payload['family'] = $family;
      $payload['attachment_id'] = (int)$attachment_id;
      $payload['url'] = esc_url_raw($attachment_url);
      $payload['filename'] = sanitize_file_name(wp_basename((string)parse_url($attachment_url, PHP_URL_PATH)));
    }

    if ($payload['family'] === '') {
      $this->redirect_with_message('Please enter the font family name that should be loaded.', 'error');
    }
    if ($payload['url'] === '') {
      $this->redirect_with_message('Please upload a font file first.', 'error');
    }

    update_option(self::OPTION_KEY, $payload, false);
    $this->redirect_with_message('Local font settings saved.');
  }

  public function enqueue_font_face(): void {
    $settings = self::get_settings();
    if ($settings['family'] === '' || $settings['url'] === '') return;

    $format = $this->format_for_url($settings['url']);
    $src = 'url("' . esc_url_raw($settings['url']) . '")';
    if ($format !== '') $src .= ' format("' . esc_attr($format) . '")';

    $css = '@font-face{'
      . 'font-family:"' . $this->escape_font_family_for_css($settings['family']) . '";'
      . 'src:' . $src . ';'
      . 'font-weight:' . esc_attr($settings['weight']) . ';'
      . 'font-style:' . esc_attr($settings['style']) . ';'
      . 'font-display:' . esc_attr($settings['display']) . ';'
      . '}'
      . ':root{--eum-local-font-family:"' . $this->escape_font_family_for_css($settings['family']) . '";}'
      . '.eum-uses-local-font{font-family:var(--eum-local-font-family),sans-serif;}';

    if (!wp_style_is('eum-ui', 'enqueued')) {
      wp_register_style('eum-ui', false, [], defined('EUM_VERSION') ? EUM_VERSION : null);
      wp_enqueue_style('eum-ui');
    }
    wp_add_inline_style('eum-ui', $css);
  }

  public function allow_font_mimes(array $mimes): array {
    $mimes['woff'] = 'font/woff';
    $mimes['woff2'] = 'font/woff2';
    $mimes['ttf'] = 'font/ttf';
    $mimes['otf'] = 'font/otf';
    return $mimes;
  }

  public function fix_font_filetype_check($types, $file, $filename, $mimes = null, $real_mime = ''): array {
    $checked = is_array($types) ? $types : [];
    $filename = is_string($filename) ? $filename : '';
    if ($filename === '') {
      return $checked;
    }

    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = [
      'woff' => 'font/woff',
      'woff2' => 'font/woff2',
      'ttf' => 'font/ttf',
      'otf' => 'font/otf',
    ];
    if (isset($allowed[$ext])) {
      $checked['ext'] = $ext;
      $checked['type'] = $allowed[$ext];
      $checked['proper_filename'] = sanitize_file_name($filename);
    }
    return $checked;
  }

  private function redirect_with_message(string $message, string $type = 'success'): void {
    wp_safe_redirect(admin_url('admin.php?page=eum-fonts&eum_font_type=' . rawurlencode($type) . '&eum_font_msg=' . rawurlencode($message)));
    exit;
  }

  private function format_for_url(string $url): string {
    $path = (string)parse_url($url, PHP_URL_PATH);
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
      case 'woff2':
        return 'woff2';
      case 'woff':
        return 'woff';
      case 'ttf':
        return 'truetype';
      case 'otf':
        return 'opentype';
      default:
        return '';
    }
  }

  private function escape_font_family_for_css(string $family): string {
    return str_replace(['\\', '"'], ['\\\\', '\\"'], $family);
  }
}
