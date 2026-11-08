<?php
if (!defined('ABSPATH')) exit;

class EUM_Company_Divisions {
  const OPTION_DIVISIONS = 'eum_company_divisions';
  const META_USER_DIVISION = 'job_company';
  const META_USER_DIVISION_OVERRIDE_FLAG = '_eum_job_company_override';
  const META_TERM_DIVISION = '_eum_job_company_division';

  public function init(): void {
    add_action('admin_menu', [$this, 'menu'], 35);
  }

  public function menu(): void {
    add_submenu_page(
      EUM_Admin::parent_slug('workforce'),
      'Company Divisions',
      'Company Divisions',
      'read',
      'eum-company-divisions',
      [$this, 'render']
    );
  }

  public function render(): void {
    if (!self::current_user_can_manage_divisions()) wp_die('Not allowed.');
    if (function_exists('wp_enqueue_media')) wp_enqueue_media();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['eum_save_company_divisions'])) {
      check_admin_referer('eum_save_company_divisions', 'eum_company_divisions_nonce');
      $labels = isset($_POST['division_label']) ? (array)$_POST['division_label'] : [];
      $domains = isset($_POST['division_domain']) ? (array)$_POST['division_domain'] : [];
      $image_ids = isset($_POST['division_image_id']) ? (array)$_POST['division_image_id'] : [];
      $rows = [];
      $count = max(count($labels), count($domains), count($image_ids));
      for ($i = 0; $i < $count; $i++) {
        $rows[] = [
          'label' => (string)($labels[$i] ?? ''),
          'domain' => (string)($domains[$i] ?? ''),
          'image_id' => (int)($image_ids[$i] ?? 0),
        ];
      }
      self::set_divisions($rows);
      echo '<div class="notice notice-success"><p>Company divisions saved.</p></div>';
    }

    $divisions = self::get_divisions();

    echo '<div class="wrap"><h1>Company Divisions</h1>';
    echo '<p>Configure company divisions, their email domains, and the default social preview image used for job listings in that division. Division keys are generated automatically from the label.</p>';
    echo '<form method="post">';
    wp_nonce_field('eum_save_company_divisions', 'eum_company_divisions_nonce');
    echo '<table class="widefat striped" style="max-width:1120px;">';
    echo '<thead><tr><th>Division Label</th><th>Email Domain</th><th>Default Social Image</th></tr></thead><tbody>';
    $rows = array_values($divisions);
    if (empty($rows)) $rows[] = ['label' => '', 'domain' => '', 'image_id' => 0];
    foreach ($rows as $row) {
      echo '<tr>';
      echo '<td><input name="division_label[]" class="regular-text" value="' . esc_attr((string)($row['label'] ?? '')) . '" placeholder="North America"></td>';
      echo '<td><input name="division_domain[]" class="regular-text" value="' . esc_attr((string)($row['domain'] ?? '')) . '" placeholder="north.example.com"></td>';
      echo '<td>' . self::render_image_picker_html('division_image_id[]', (int)($row['image_id'] ?? 0), 'No default image selected.') . '</td>';
      echo '</tr>';
    }
    for ($i = 0; $i < 3; $i++) {
      echo '<tr>';
      echo '<td><input name="division_label[]" class="regular-text" value="" placeholder="New Division"></td>';
      echo '<td><input name="division_domain[]" class="regular-text" value="" placeholder="division.example.com"></td>';
      echo '<td>' . self::render_image_picker_html('division_image_id[]', 0, 'No default image selected.') . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p><button type="submit" class="button button-primary" name="eum_save_company_divisions" value="1">Save Company Divisions</button></p>';
    self::render_image_picker_script();
    echo '</form></div>';
  }

  public static function get_divisions(): array {
    $saved = get_option(self::OPTION_DIVISIONS, []);
    $saved = is_array($saved) ? $saved : [];
    $out = [];
    foreach ($saved as $row) {
      if (!is_array($row)) continue;
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      $key = sanitize_title((string)($row['key'] ?? ($label !== '' ? $label : '')));
      $domain = self::sanitize_domain((string)($row['domain'] ?? ''));
      $image_id = self::sanitize_image_attachment_id($row['image_id'] ?? 0);
      if ($label === '' && $key !== '') $label = ucwords(str_replace(['-', '_'], ' ', $key));
      if ($label === '') continue;
      if ($key === '') $key = sanitize_title($label);
      $out[$key] = [
        'key' => $key,
        'label' => $label,
        'domain' => $domain,
        'image_id' => $image_id,
      ];
    }
    return $out;
  }

  public static function set_divisions(array $rows): void {
    $clean = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      $domain = self::sanitize_domain((string)($row['domain'] ?? ''));
      $image_id = self::sanitize_image_attachment_id($row['image_id'] ?? 0);
      if ($label === '') continue;
      $key = sanitize_title($label);
      $clean[$key] = ['key' => $key, 'label' => $label, 'domain' => $domain, 'image_id' => $image_id];
    }
    update_option(self::OPTION_DIVISIONS, array_values($clean), false);
  }

  public static function sanitize_domain(string $domain): string {
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) ? $domain : '';
  }

  public static function normalize_division_key($value): string {
    $value = is_scalar($value) ? trim((string)$value) : '';
    if ($value === '') return '';
    $divisions = self::get_divisions();
    foreach ($divisions as $key => $row) {
      $label = sanitize_text_field((string)($row['label'] ?? ''));
      if ($label !== '' && strcasecmp($label, $value) === 0) return $key;
    }
    $slug = sanitize_title($value);
    if ($slug !== '' && isset($divisions[$slug])) return $slug;
    return $slug;
  }

  public static function division_label(string $key): string {
    $key = self::normalize_division_key($key);
    if ($key === '') return '';
    $divisions = self::get_divisions();
    return isset($divisions[$key]) ? (string)$divisions[$key]['label'] : ucwords(str_replace(['-', '_'], ' ', $key));
  }

  public static function division_domain(string $key): string {
    $key = self::normalize_division_key($key);
    if ($key === '') return '';
    $divisions = self::get_divisions();
    return isset($divisions[$key]) ? (string)$divisions[$key]['domain'] : '';
  }

  public static function division_image_id(string $key): int {
    $key = self::normalize_division_key($key);
    if ($key === '') return 0;
    $divisions = self::get_divisions();
    return isset($divisions[$key]) ? (int)($divisions[$key]['image_id'] ?? 0) : 0;
  }

  public static function division_image_url(string $key, string $size = 'full'): string {
    $image_id = self::division_image_id($key);
    if ($image_id <= 0) return '';
    $image_url = wp_get_attachment_image_url($image_id, $size);
    return is_string($image_url) ? $image_url : '';
  }

  public static function division_exists(string $key): bool {
    $key = self::normalize_division_key($key);
    if ($key === '') return false;
    $divisions = self::get_divisions();
    return isset($divisions[$key]);
  }

  public static function resolve_division_for_job_path(int $job_term_id, $override_division = ''): string {
    $override = self::normalize_division_key((string)$override_division);
    if ($override !== '' && self::division_exists($override)) return $override;
    return self::effective_division_for_job_path($job_term_id);
  }

  public static function options_for_select(): array {
    $out = [];
    foreach (self::get_divisions() as $key => $row) {
      $label = (string)($row['label'] ?? $key);
      $domain = (string)($row['domain'] ?? '');
      $out[$key] = $domain !== '' ? ($label . ' (' . $domain . ')') : $label;
    }
    return $out;
  }

  public static function label_options_for_select(): array {
    $out = [];
    foreach (self::get_divisions() as $row) {
      $label = (string)($row['label'] ?? '');
      if ($label === '') continue;
      $domain = (string)($row['domain'] ?? '');
      $out[$label] = $domain !== '' ? ($label . ' (' . $domain . ')') : $label;
    }
    return $out;
  }

  public static function direct_job_path_division(int $term_id): string {
    if ($term_id <= 0) return '';
    return self::normalize_division_key((string)get_term_meta($term_id, self::META_TERM_DIVISION, true));
  }

  public static function effective_division_for_job_path(int $term_id): string {
    $term_id = (int)$term_id;
    while ($term_id > 0) {
      $direct = self::direct_job_path_division($term_id);
      if ($direct !== '') return $direct;
      $term = get_term($term_id, 'eum_job_path');
      if (!$term || is_wp_error($term) || empty($term->parent)) break;
      $term_id = (int)$term->parent;
    }
    return '';
  }

  public static function stored_user_division(int $user_id): string {
    if ($user_id <= 0) return '';
    return self::normalize_division_key((string)get_user_meta($user_id, self::META_USER_DIVISION, true));
  }

  public static function user_division_override_enabled(int $user_id): bool {
    if ($user_id <= 0) return false;
    return !empty(get_user_meta($user_id, self::META_USER_DIVISION_OVERRIDE_FLAG, true));
  }

  public static function derived_division_for_user_job_path(int $user_id): string {
    if ($user_id <= 0) return '';
    $terms = wp_get_object_terms($user_id, 'eum_job_path', ['fields' => 'ids']);
    if (is_wp_error($terms) || empty($terms)) return '';
    return self::effective_division_for_job_path((int)$terms[0]);
  }

  public static function user_division_override(int $user_id): string {
    if (!self::user_division_override_enabled($user_id)) return '';
    return self::stored_user_division($user_id);
  }

  public static function effective_division_for_user(int $user_id): string {
    $override = self::user_division_override($user_id);
    if ($override !== '') return $override;
    $derived = self::derived_division_for_user_job_path($user_id);
    if ($derived !== '') return $derived;
    return self::stored_user_division($user_id);
  }

  public static function save_user_division_input(int $user_id, string $division_key, bool $manual_context = false, int $job_term_id = 0): string {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return '';

    $division = self::normalize_division_key($division_key);
    $derived = $job_term_id > 0 ? self::effective_division_for_job_path($job_term_id) : self::derived_division_for_user_job_path($user_id);

    if (!$manual_context) {
      if ($division === '') $division = $derived;
      if ($division !== '') update_user_meta($user_id, self::META_USER_DIVISION, $division);
      else delete_user_meta($user_id, self::META_USER_DIVISION);
      delete_user_meta($user_id, self::META_USER_DIVISION_OVERRIDE_FLAG);
      return $division;
    }

    if ($division === '' || ($derived !== '' && $division === $derived)) {
      $store = $division !== '' ? $division : $derived;
      if ($store !== '') update_user_meta($user_id, self::META_USER_DIVISION, $store);
      else delete_user_meta($user_id, self::META_USER_DIVISION);
      delete_user_meta($user_id, self::META_USER_DIVISION_OVERRIDE_FLAG);
      return $store;
    }

    update_user_meta($user_id, self::META_USER_DIVISION, $division);
    update_user_meta($user_id, self::META_USER_DIVISION_OVERRIDE_FLAG, 1);
    return $division;
  }

  public static function sync_user_division_from_classification(int $user_id, array $mapping = []): string {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return '';
    if (self::user_division_override_enabled($user_id)) return self::effective_division_for_user($user_id);

    $mapped_division = '';
    foreach ($mapping as $raw_key => $value) {
      $normalized = class_exists('EUM_Taxonomies') ? EUM_Taxonomies::normalize_mapping_key((string)$raw_key) : sanitize_key((string)$raw_key);
      if ($normalized !== 'job_company') continue;
      $mapped_division = self::normalize_division_key((string)$value);
      break;
    }

    $division = $mapped_division !== '' ? $mapped_division : self::derived_division_for_user_job_path($user_id);
    return self::save_user_division_input($user_id, $division, false);
  }

  public static function build_email_for_username_and_division(string $username, string $division_key): string {
    $username = sanitize_user($username, true);
    $domain = self::division_domain($division_key);
    if ($username === '' || $domain === '') return '';
    return strtolower($username . '@' . $domain);
  }

  public static function derive_transfer_values(string $username, int $job_term_id, string $override_division = ''): array {
    $division = self::resolve_division_for_job_path($job_term_id, $override_division);
    $email = self::build_email_for_username_and_division($username, $division);
    return [
      'division' => $division,
      'label' => self::division_label($division),
      'domain' => self::division_domain($division),
      'email' => $email,
    ];
  }

  public static function domain_map_for_job_terms(array $term_ids): array {
    $out = [];
    foreach (array_values(array_unique(array_map('intval', $term_ids))) as $term_id) {
      if ($term_id <= 0) continue;
      $division = self::effective_division_for_job_path($term_id);
      $out[$term_id] = [
        'division' => $division,
        'label' => self::division_label($division),
        'domain' => self::division_domain($division),
        'image_url' => self::division_image_url($division),
      ];
    }
    return $out;
  }

  private static function current_user_can_manage_divisions(): bool {
    return current_user_can('manage_options') || EUM_Access::current_user_is_it() || EUM_Access::current_user_is_hr();
  }

  private static function sanitize_image_attachment_id($value): int {
    $image_id = max(0, (int)$value);
    return $image_id > 0 && wp_attachment_is_image($image_id) ? $image_id : 0;
  }

  private static function render_image_picker_html(string $field_name, int $image_id = 0, string $empty_label = 'No image selected.'): string {
    $image_url = $image_id > 0 ? wp_get_attachment_image_url($image_id, 'medium') : '';
    ob_start();
    echo '<div class="eum-image-picker" data-eum-image-picker="1">';
    echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="' . (int)$image_id . '" class="eum-image-picker__input">';
    echo '<div class="eum-image-picker__preview" style="margin-bottom:8px;">';
    if (is_string($image_url) && $image_url !== '') {
      echo '<img src="' . esc_url($image_url) . '" alt="" style="display:block;max-width:220px;width:100%;height:auto;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">';
    } else {
      echo '<div class="description eum-image-picker__empty">' . esc_html($empty_label) . '</div>';
    }
    echo '</div>';
    echo '<p style="margin:0;display:flex;gap:8px;flex-wrap:wrap;">';
    echo '<button type="button" class="button button-secondary eum-image-picker__select">Choose Image</button>';
    echo '<button type="button" class="button eum-image-picker__remove"' . ($image_id > 0 ? '' : ' style="display:none;"') . '>Remove Image</button>';
    echo '</p>';
    echo '</div>';
    return ob_get_clean();
  }

  private static function render_image_picker_script(): void {
    echo '<script>(function(){if(window.EUMImagePickerInit)return;window.EUMImagePickerInit=function(root){if(!root||typeof wp==="undefined"||!wp.media)return;root.querySelectorAll("[data-eum-image-picker]").forEach(function(picker){if(picker.dataset.eumImagePickerBound==="1")return;picker.dataset.eumImagePickerBound="1";var input=picker.querySelector(".eum-image-picker__input");var preview=picker.querySelector(".eum-image-picker__preview");var selectButton=picker.querySelector(".eum-image-picker__select");var removeButton=picker.querySelector(".eum-image-picker__remove");var emptyLabel=(preview&&preview.textContent?preview.textContent:"No image selected.").trim()||"No image selected.";var renderPreview=function(url){if(!preview)return;if(url){preview.innerHTML=\'<img src="\'+url+\'" alt="" style="display:block;max-width:220px;width:100%;height:auto;border:1px solid #ccd0d4;border-radius:8px;background:#fff;">\';if(removeButton)removeButton.style.display="";}else{preview.innerHTML=\'<div class="description eum-image-picker__empty">\'+emptyLabel+\'</div>\';if(removeButton)removeButton.style.display="none";}};selectButton&&selectButton.addEventListener("click",function(event){event.preventDefault();var frame=wp.media({title:"Select social image",button:{text:"Use this image"},multiple:false,library:{type:"image"}});frame.on("select",function(){var selection=frame.state().get("selection").first();if(!selection)return;var attachment=selection.toJSON();if(input)input.value=attachment.id||"";renderPreview(attachment.url||"");});frame.open();});removeButton&&removeButton.addEventListener("click",function(event){event.preventDefault();if(input)input.value="";renderPreview("");});});};document.addEventListener("DOMContentLoaded",function(){window.EUMImagePickerInit(document);});}());</script>';
  }
}
