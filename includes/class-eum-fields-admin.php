<?php
if (!defined('ABSPATH')) exit;

class EUM_Fields_Admin {
  public function init(): void {
    add_action('admin_menu', [$this, 'menu']);
  }

  public function menu(): void {
    add_submenu_page(
      EUM_Admin::parent_slug('workforce'),
      'Custom Fields',
      'Custom Fields',
      'read',
      'eum-fields',
      [$this, 'render']
    );
  }

  public function render(): void {
    if (!EUM_Access::current_user_is_it()) wp_die('Not allowed.');

    // Save
    if (!empty($_POST['eum_save_fields'])) {
      check_admin_referer('eum_save_fields', 'eum_fields_nonce');

      $keys = isset($_POST['field_key']) ? (array)$_POST['field_key'] : [];
      $labels = isset($_POST['field_label']) ? (array)$_POST['field_label'] : [];
      $types = isset($_POST['field_type']) ? (array)$_POST['field_type'] : [];
      $reqs = isset($_POST['field_required']) ? (array)$_POST['field_required'] : [];

      $defs = [];
      $n = max(count($keys), count($labels), count($types));
      for ($i=0; $i<$n; $i++) {
        $defs[] = [
          'key' => $keys[$i] ?? '',
          'label' => $labels[$i] ?? '',
          'type' => $types[$i] ?? 'text',
          'required' => !empty($reqs[$i]) ? 1 : 0,
        ];
      }

      EUM_Fields::set_definitions($defs);
      echo '<div class="notice notice-success"><p>Fields saved.</p></div>';
    }

    $defs = EUM_Fields::get_definitions();
    $types = EUM_Fields::allowed_types();

    echo '<div class="wrap"><h1>Custom Fields</h1>';
    echo '<p>These fields appear on user profiles under Enterprise Data and can be displayed via shortcode.</p>';

    echo '<form method="post">';
    wp_nonce_field('eum_save_fields', 'eum_fields_nonce');

    echo '<table class="widefat striped" style="max-width: 980px;">';
    echo '<thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th></tr></thead><tbody>';

    // existing rows
    foreach ($defs as $i => $d) {
      $key = esc_attr($d['key'] ?? '');
      $label = esc_attr($d['label'] ?? '');
      $type = esc_attr($d['type'] ?? 'text');
      $required = !empty($d['required']) ? 'checked' : '';

      echo '<tr>';
      echo '<td><input name="field_key[]" value="'.$key.'" class="regular-text" placeholder="employee_id" /></td>';
      echo '<td><input name="field_label[]" value="'.$label.'" class="regular-text" placeholder="Employee ID" /></td>';
      echo '<td><select name="field_type[]">';
      foreach ($types as $t) {
        $sel = ($t === $type) ? 'selected' : '';
        echo '<option value="'.esc_attr($t).'" '.$sel.'>'.esc_html($t).'</option>';
      }
      echo '</select></td>';
      echo '<td><input type="checkbox" name="field_required['.(int)$i.']" value="1" '.$required.' /></td>';
      echo '</tr>';
    }

    // blank row
    echo '<tr>';
    echo '<td><input name="field_key[]" value="" class="regular-text" placeholder="new_field_key" /></td>';
    echo '<td><input name="field_label[]" value="" class="regular-text" placeholder="New Field Label" /></td>';
    echo '<td><select name="field_type[]">';
    foreach ($types as $t) {
      echo '<option value="'.esc_attr($t).'">'.esc_html($t).'</option>';
    }
    echo '</select></td>';
    echo '<td><input type="checkbox" name="field_required['.(int)count($defs).']" value="1" /></td>';
    echo '</tr>';

    echo '</tbody></table>';

    echo '<p><button class="button button-primary" type="submit" name="eum_save_fields" value="1">Save Fields</button></p>';
    echo '</form>';

    echo '<h2>Display Shortcode</h2>';
    echo '<pre>[eum_user_meta key="employee_id"]</pre>';
    echo '</div>';
  }
}
