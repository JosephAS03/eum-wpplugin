<?php
if (!defined('ABSPATH')) exit;

class EUM_Expected_Labor {
  public const CPT_RULE = 'eum_expected_labor_rule';
  public const CPT_ADJUSTMENT = 'eum_expected_labor_adj';
  public const PAGE_SLUG = 'eum-expected-labor';

  private const META_JOB_PATH_TERM_ID = '_eum_expected_labor_job_path_term_id';
  private const META_INCLUDE_DESCENDANTS = '_eum_expected_labor_include_descendants';
  private const META_METRIC_ID = '_eum_expected_labor_metric_id';
  private const META_BASE_HOURS = '_eum_expected_labor_base_hours';
  private const META_HOURS_PER_UNIT = '_eum_expected_labor_hours_per_unit';
  private const META_FLAT_HOURS = '_eum_expected_labor_flat_hours';
  private const META_MIN_HOURS = '_eum_expected_labor_min_hours';
  private const META_MAX_HOURS = '_eum_expected_labor_max_hours';
  private const META_SORT_ORDER = '_eum_expected_labor_sort_order';
  private const META_ACTIVE = '_eum_expected_labor_active';
  private const META_NOTE = '_eum_expected_labor_note';
  private const META_WEEK_START = '_eum_expected_labor_week_start';
  private const META_HOURS = '_eum_expected_labor_hours';

  public function init(): void {
    add_action('init', [$this, 'register_post_types']);
    add_action('admin_menu', [$this, 'admin_menu'], 36);
    add_action('admin_post_eum_expected_labor_save_rule', [$this, 'handle_save_rule']);
    add_action('admin_post_eum_expected_labor_delete_rule', [$this, 'handle_delete_rule']);
    add_action('admin_post_eum_expected_labor_save_adjustments', [$this, 'handle_save_adjustments']);
    add_action('admin_post_eum_expected_labor_delete_adjustment', [$this, 'handle_delete_adjustment']);
  }

  public static function can_view(int $user_id = 0): bool {
    return class_exists('EUM_Performance_Metrics') && EUM_Performance_Metrics::can_view($user_id);
  }

  public static function can_manage_rules(int $user_id = 0): bool {
    return class_exists('EUM_Performance_Metrics') && EUM_Performance_Metrics::can_manage($user_id);
  }

  public static function can_manage_adjustments(int $user_id = 0): bool {
    return self::can_view($user_id);
  }

  public function register_post_types(): void {
    register_post_type(self::CPT_RULE, [
      'labels' => ['name' => 'Expected Labor Rules', 'singular_name' => 'Expected Labor Rule'],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
      'rewrite' => false,
    ]);
    register_post_type(self::CPT_ADJUSTMENT, [
      'labels' => ['name' => 'Expected Labor Adjustments', 'singular_name' => 'Expected Labor Adjustment'],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
      'rewrite' => false,
    ]);
  }

  public function admin_menu(): void {
    if (!self::can_view()) return;
    add_dashboard_page('Expected Labor', 'Expected Labor', 'read', self::PAGE_SLUG, [$this, 'render_page']);
    if (self::can_manage_rules()) {
      add_submenu_page(EUM_Admin::parent_slug('finance'), 'Expected Labor', 'Expected Labor', 'read', self::PAGE_SLUG, [$this, 'render_page']);
    }
  }

  public function render_page(): void {
    if (!self::can_view()) wp_die('Not allowed.');
    $viewer_user_id = (int)get_current_user_id();
    $filters = $this->current_filters();
    $summary = $this->expected_labor_summary($viewer_user_id, $filters);
    $editing_rule_id = self::can_manage_rules($viewer_user_id) ? max(0, (int)($_GET['edit_rule'] ?? 0)) : 0;

    echo '<div class="wrap"><h1>Expected Labor</h1>';
    echo '<p>Project labor demand from performance metrics and rules, then compare the expectation against scheduled and worked hours.</p>';
    echo $this->render_notice();
    echo $this->render_filters($filters, $viewer_user_id);
    echo $this->render_summary_cards($summary);
    echo $this->render_summary_table($summary['rows']);
    if (self::can_manage_adjustments($viewer_user_id)) {
      echo $this->render_adjustment_manager($filters);
    }
    if (self::can_manage_rules($viewer_user_id)) {
      echo $this->render_rule_manager($editing_rule_id);
    }
    echo '</div>';
  }

  public function handle_save_rule(): void {
    if (!is_user_logged_in() || !self::can_manage_rules()) wp_die('Not allowed.');
    $rule_id = max(0, (int)($_POST['rule_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? admin_url('index.php?page=' . self::PAGE_SLUG)));
    if (!isset($_POST['eum_expected_labor_rule_nonce']) || !wp_verify_nonce((string)$_POST['eum_expected_labor_rule_nonce'], 'eum_expected_labor_rule_save')) wp_die('Bad nonce.');

    $title = sanitize_text_field((string)($_POST['rule_title'] ?? ''));
    $job_path_term_id = max(0, (int)($_POST['rule_job_path_term_id'] ?? 0));
    if ($title === '' || $job_path_term_id <= 0) {
      wp_safe_redirect(add_query_arg(['eum_msg' => 'Rule name and Job Path are required.', 'eum_type' => 'error'], $return_url));
      exit;
    }

    $post_data = ['post_type' => self::CPT_RULE, 'post_status' => 'publish', 'post_title' => $title];
    if ($rule_id > 0) $post_data['ID'] = $rule_id;
    else $post_data['post_author'] = (int)get_current_user_id();
    $saved = $rule_id > 0 ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);
    if (is_wp_error($saved) || (int)$saved <= 0) {
      wp_safe_redirect(add_query_arg(['eum_msg' => 'Could not save the labor rule.', 'eum_type' => 'error'], $return_url));
      exit;
    }
    $rule_id = (int)$saved;

    update_post_meta($rule_id, self::META_JOB_PATH_TERM_ID, $job_path_term_id);
    update_post_meta($rule_id, self::META_INCLUDE_DESCENDANTS, !empty($_POST['rule_include_descendants']) ? '1' : '0');
    update_post_meta($rule_id, self::META_METRIC_ID, max(0, (int)($_POST['rule_metric_id'] ?? 0)));
    update_post_meta($rule_id, self::META_BASE_HOURS, round((float)($_POST['rule_base_hours'] ?? 0), 4));
    update_post_meta($rule_id, self::META_HOURS_PER_UNIT, round((float)($_POST['rule_hours_per_unit'] ?? 0), 4));
    update_post_meta($rule_id, self::META_FLAT_HOURS, round((float)($_POST['rule_flat_hours'] ?? 0), 4));
    update_post_meta($rule_id, self::META_MIN_HOURS, round((float)($_POST['rule_min_hours'] ?? 0), 4));
    update_post_meta($rule_id, self::META_MAX_HOURS, round((float)($_POST['rule_max_hours'] ?? 0), 4));
    update_post_meta($rule_id, self::META_SORT_ORDER, (int)($_POST['rule_sort_order'] ?? 0));
    update_post_meta($rule_id, self::META_ACTIVE, !empty($_POST['rule_active']) ? '1' : '0');
    update_post_meta($rule_id, self::META_NOTE, sanitize_text_field((string)($_POST['rule_note'] ?? '')));

    wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'edit_rule' => $rule_id, 'eum_msg' => 'Labor rule saved.', 'eum_type' => 'success'], admin_url('index.php')));
    exit;
  }

  public function handle_delete_rule(): void {
    if (!is_user_logged_in() || !self::can_manage_rules()) wp_die('Not allowed.');
    $rule_id = max(0, (int)($_POST['rule_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? admin_url('index.php?page=' . self::PAGE_SLUG)));
    if (!isset($_POST['eum_expected_labor_delete_rule_nonce']) || !wp_verify_nonce((string)$_POST['eum_expected_labor_delete_rule_nonce'], 'eum_expected_labor_delete_rule_' . $rule_id)) wp_die('Bad nonce.');
    if ($rule_id > 0) wp_delete_post($rule_id, true);
    wp_safe_redirect(add_query_arg(['eum_msg' => 'Labor rule deleted.', 'eum_type' => 'success'], $return_url));
    exit;
  }

  public function handle_save_adjustments(): void {
    if (!is_user_logged_in() || !self::can_manage_adjustments()) wp_die('Not allowed.');
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? admin_url('index.php?page=' . self::PAGE_SLUG)));
    if (!isset($_POST['eum_expected_labor_adjustment_nonce']) || !wp_verify_nonce((string)$_POST['eum_expected_labor_adjustment_nonce'], 'eum_expected_labor_adjustments_save')) wp_die('Bad nonce.');
    $week_start = $this->normalize_week_start((string)($_POST['adjustment_week_start'] ?? ''));
    $path_ids = isset($_POST['adjustment_job_path_term_id']) ? (array)$_POST['adjustment_job_path_term_id'] : [];
    $hours = isset($_POST['adjustment_hours']) ? (array)$_POST['adjustment_hours'] : [];
    $notes = isset($_POST['adjustment_note']) ? (array)$_POST['adjustment_note'] : [];
    $created = 0;
    $count = max(count($path_ids), count($hours), count($notes));
    for ($i = 0; $i < $count; $i++) {
      $path_id = max(0, (int)($path_ids[$i] ?? 0));
      $hours_value = round((float)($hours[$i] ?? 0), 4);
      $note = sanitize_text_field((string)($notes[$i] ?? ''));
      if ($path_id <= 0 || $hours_value == 0.0) continue;
      if (!$this->user_can_view_job_path((int)get_current_user_id(), $path_id)) continue;
      $post_id = wp_insert_post([
        'post_type' => self::CPT_ADJUSTMENT,
        'post_status' => 'publish',
        'post_title' => 'Adjustment - ' . $this->job_path_label($path_id) . ' - ' . $week_start,
        'post_author' => (int)get_current_user_id(),
      ], true);
      if (is_wp_error($post_id) || (int)$post_id <= 0) continue;
      $post_id = (int)$post_id;
      update_post_meta($post_id, self::META_JOB_PATH_TERM_ID, $path_id);
      update_post_meta($post_id, self::META_WEEK_START, $week_start);
      update_post_meta($post_id, self::META_HOURS, $hours_value);
      update_post_meta($post_id, self::META_NOTE, $note);
      $created++;
    }
    $message = $created > 0 ? 'Manual labor adjustments saved.' : 'No adjustments were added.';
    $type = $created > 0 ? 'success' : 'warning';
    wp_safe_redirect(add_query_arg(['eum_msg' => $message, 'eum_type' => $type], $return_url));
    exit;
  }

  public function handle_delete_adjustment(): void {
    if (!is_user_logged_in() || !self::can_manage_adjustments()) wp_die('Not allowed.');
    $adjustment_id = max(0, (int)($_POST['adjustment_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? admin_url('index.php?page=' . self::PAGE_SLUG)));
    if (!isset($_POST['eum_expected_labor_delete_adjustment_nonce']) || !wp_verify_nonce((string)$_POST['eum_expected_labor_delete_adjustment_nonce'], 'eum_expected_labor_delete_adjustment_' . $adjustment_id)) wp_die('Bad nonce.');
    if ($adjustment_id > 0) wp_delete_post($adjustment_id, true);
    wp_safe_redirect(add_query_arg(['eum_msg' => 'Adjustment deleted.', 'eum_type' => 'success'], $return_url));
    exit;
  }

  private function current_filters(): array {
    return [
      'scope_path' => max(0, (int)($_GET['scope_path'] ?? 0)),
      'include_descendants' => !array_key_exists('include_descendants', $_GET) || !empty($_GET['include_descendants']),
      'week_start' => $this->normalize_week_start((string)($_GET['week_start'] ?? '')),
    ];
  }

  private function normalize_week_start(string $raw): string {
    $raw = sanitize_text_field($raw);
    if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone((string)get_option('timezone_string') ?: 'UTC');
    $now = new DateTimeImmutable('now', $tz);
    return $now->modify('Sunday this week')->format('Y-m-d');
  }

  private function expected_labor_summary(int $viewer_user_id, array $filters): array {
    $week_start = $this->normalize_week_start((string)($filters['week_start'] ?? ''));
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone((string)get_option('timezone_string') ?: 'UTC');
    $range_start = (new DateTimeImmutable($week_start, $tz))->setTime(0, 0, 0);
    $range_end = $range_start->modify('+7 days');

    $schedule_args = ['statuses' => ['publish', 'draft']];
    $timeclock_args = [];
    if ((int)($filters['scope_path'] ?? 0) > 0) {
      $schedule_args['job_path_term_id'] = (int)$filters['scope_path'];
      $schedule_args['include_descendants'] = !empty($filters['include_descendants']);
      $timeclock_args['job_path_term_id'] = (int)$filters['scope_path'];
      $timeclock_args['include_descendants'] = !empty($filters['include_descendants']);
    }

    $scheduled_rows = class_exists('EUM_Scheduling') ? (new EUM_Scheduling())->labor_rows_for_range($range_start->getTimestamp(), $range_end->getTimestamp(), $schedule_args) : [];
    $worked_rows = class_exists('EUM_Timeclock') ? (new EUM_Timeclock())->labor_rows_for_range($range_start->getTimestamp(), $range_end->getTimestamp(), $timeclock_args) : [];
    $rules = $this->rule_payloads();
    $adjustments = $this->adjustment_payloads_for_week($week_start);

    $path_ids = [];
    foreach ($rules as $rule) {
      $path_id = (int)($rule['job_path_term_id'] ?? 0);
      if ($path_id > 0 && $this->user_can_view_job_path($viewer_user_id, $path_id)) $path_ids[] = $path_id;
    }
    foreach ($adjustments as $adjustment) {
      $path_id = (int)($adjustment['job_path_term_id'] ?? 0);
      if ($path_id > 0 && $this->user_can_view_job_path($viewer_user_id, $path_id)) $path_ids[] = $path_id;
    }
    foreach (array_merge($scheduled_rows, $worked_rows) as $row) {
      $path_id = (int)($row['job_path_term_id'] ?? 0);
      if ($path_id > 0 && $this->user_can_view_job_path($viewer_user_id, $path_id)) $path_ids[] = $path_id;
    }
    if ((int)($filters['scope_path'] ?? 0) > 0) $path_ids[] = (int)$filters['scope_path'];

    $path_ids = array_values(array_unique(array_filter(array_map('intval', $path_ids))));
    $path_ids = array_values(array_filter($path_ids, fn($path_id) => $this->path_matches_scope($path_id, (int)($filters['scope_path'] ?? 0), !empty($filters['include_descendants']))));
    sort($path_ids, SORT_NUMERIC);

    $scheduled_hours = [];
    $worked_hours = [];
    foreach ($scheduled_rows as $row) {
      $path_id = (int)($row['job_path_term_id'] ?? 0);
      if ($path_id <= 0) continue;
      $scheduled_hours[$path_id] = (float)($scheduled_hours[$path_id] ?? 0) + (float)($row['hours'] ?? 0);
    }
    foreach ($worked_rows as $row) {
      $path_id = (int)($row['job_path_term_id'] ?? 0);
      if ($path_id <= 0) continue;
      $worked_hours[$path_id] = (float)($worked_hours[$path_id] ?? 0) + (float)($row['hours'] ?? 0);
    }

    $rows = [];
    $total_expected = 0.0;
    $total_scheduled = 0.0;
    $total_worked = 0.0;
    foreach ($path_ids as $path_id) {
      $rule_hours = 0.0;
      $manual_hours = 0.0;
      $rule_count = 0;
      foreach ($rules as $rule) {
        if (!$this->rule_applies_to_path($rule, $path_id)) continue;
        if (!$this->user_can_view_job_path($viewer_user_id, (int)($rule['job_path_term_id'] ?? 0))) continue;
        if (empty($rule['active'])) continue;
        $metric_id = (int)($rule['metric_id'] ?? 0);
        $metric_value = $metric_id > 0 && class_exists('EUM_Performance_Metrics')
          ? EUM_Performance_Metrics::resolve_metric_value($metric_id, $path_id, ['start_date' => $week_start, 'end_date' => $range_end->modify('-1 day')->format('Y-m-d')])
          : 0.0;
        $hours = (float)($rule['base_hours'] ?? 0) + ((float)($rule['hours_per_unit'] ?? 0) * $metric_value) + (float)($rule['flat_hours'] ?? 0);
        $min = (float)($rule['min_hours'] ?? 0);
        $max = (float)($rule['max_hours'] ?? 0);
        if ($min > 0 && $hours < $min) $hours = $min;
        if ($max > 0 && $hours > $max) $hours = $max;
        $rule_hours += $hours;
        $rule_count++;
      }
      foreach ($adjustments as $adjustment) {
        if ((int)($adjustment['job_path_term_id'] ?? 0) !== $path_id) continue;
        $manual_hours += (float)($adjustment['hours'] ?? 0);
      }
      $expected_hours = $rule_hours + $manual_hours;
      $scheduled = (float)($scheduled_hours[$path_id] ?? 0);
      $worked = (float)($worked_hours[$path_id] ?? 0);
      $rows[] = [
        'path_term_id' => $path_id,
        'path_label' => $this->job_path_label($path_id),
        'expected_hours' => $expected_hours,
        'rule_hours' => $rule_hours,
        'manual_hours' => $manual_hours,
        'scheduled_hours' => $scheduled,
        'worked_hours' => $worked,
        'scheduled_variance' => $scheduled - $expected_hours,
        'worked_variance' => $worked - $expected_hours,
        'rule_count' => $rule_count,
      ];
      $total_expected += $expected_hours;
      $total_scheduled += $scheduled;
      $total_worked += $worked;
    }

    usort($rows, static fn($a, $b) => strcasecmp((string)$a['path_label'], (string)$b['path_label']));
    return [
      'week_start' => $week_start,
      'week_end' => $range_end->modify('-1 day')->format('Y-m-d'),
      'rows' => $rows,
      'total_expected' => $total_expected,
      'total_scheduled' => $total_scheduled,
      'total_worked' => $total_worked,
    ];
  }

  private function render_notice(): string {
    $msg = sanitize_text_field((string)($_GET['eum_msg'] ?? ''));
    if ($msg === '') return '';
    $type = sanitize_key((string)($_GET['eum_type'] ?? 'success'));
    $class = 'notice notice-success';
    if ($type === 'error') $class = 'notice notice-error';
    elseif ($type === 'warning') $class = 'notice notice-warning';
    return '<div class="' . esc_attr($class) . '"><p>' . esc_html($msg) . '</p></div>';
  }

  private function render_filters(array $filters, int $viewer_user_id): string {
    $path_options = $this->visible_job_path_options($viewer_user_id);
    ob_start();
    echo '<form method="get" action="' . esc_url(admin_url('index.php')) . '" style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px 16px;margin:16px 0 20px;max-width:1200px;">';
    echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end;">';
    echo '<p style="margin:0;"><label><strong>Scope Path</strong></label><br>' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_picker('scope_path', (int)$filters['scope_path'], $viewer_user_id, ['placeholder' => 'All visible paths', 'none_label' => 'All visible paths']) : '<select name="scope_path" style="width:100%;"><option value="0">All visible paths</option></select>') . '</p>';
    echo '<p style="margin:0;"><label><strong>Week Starting</strong></label><br><input type="date" name="week_start" value="' . esc_attr((string)$filters['week_start']) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><input type="checkbox" name="include_descendants" value="1" ' . checked(!empty($filters['include_descendants']), true, false) . '> Include descendants</label></p>';
    echo '</div><p style="margin:12px 0 0;"><button type="submit" class="button button-primary">Apply Filters</button></p></form>';
    return (string)ob_get_clean();
  }

  private function render_summary_cards(array $summary): string {
    $cards = [
      'Expected Hours' => (float)($summary['total_expected'] ?? 0),
      'Scheduled Hours' => (float)($summary['total_scheduled'] ?? 0),
      'Worked Hours' => (float)($summary['total_worked'] ?? 0),
    ];
    ob_start();
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:0 0 18px;max-width:980px;">';
    foreach ($cards as $label => $value) {
      echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px 16px;">';
      echo '<div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;">' . esc_html($label) . '</div>';
      echo '<div style="font-size:26px;font-weight:700;line-height:1.1;margin-top:6px;">' . esc_html(number_format($value, 2, '.', ',')) . '</div>';
      echo '</div>';
    }
    echo '</div>';
    return (string)ob_get_clean();
  }

  private function render_summary_table(array $rows): string {
    ob_start();
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;max-width:1200px;">';
    echo '<h2 style="margin-top:0;">Projected vs Actual</h2>';
    if (empty($rows)) {
      echo '<p style="margin-bottom:0;">No expected labor rows matched the current scope.</p>';
      echo '</div>';
      return (string)ob_get_clean();
    }
    echo '<table class="widefat striped"><thead><tr><th>Path</th><th style="width:110px;">Expected</th><th style="width:110px;">Rules</th><th style="width:110px;">Manual</th><th style="width:110px;">Scheduled</th><th style="width:110px;">Worked</th><th style="width:110px;">Sched Var</th><th style="width:110px;">Worked Var</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      echo '<tr>';
      echo '<td><strong>' . esc_html((string)$row['path_label']) . '</strong><div style="color:#646970;font-size:12px;">' . (int)($row['rule_count'] ?? 0) . ' rule(s)</div></td>';
      echo '<td>' . esc_html(number_format((float)$row['expected_hours'], 2, '.', ',')) . '</td>';
      echo '<td>' . esc_html(number_format((float)$row['rule_hours'], 2, '.', ',')) . '</td>';
      echo '<td>' . esc_html(number_format((float)$row['manual_hours'], 2, '.', ',')) . '</td>';
      echo '<td>' . esc_html(number_format((float)$row['scheduled_hours'], 2, '.', ',')) . '</td>';
      echo '<td>' . esc_html(number_format((float)$row['worked_hours'], 2, '.', ',')) . '</td>';
      echo '<td>' . esc_html(number_format((float)$row['scheduled_variance'], 2, '.', ',')) . '</td>';
      echo '<td>' . esc_html(number_format((float)$row['worked_variance'], 2, '.', ',')) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    return (string)ob_get_clean();
  }

  private function render_adjustment_manager(array $filters): string {
    $path_options = $this->visible_job_path_options((int)get_current_user_id());
    $recent = $this->adjustment_payloads_for_week((string)$filters['week_start']);
    ob_start();
    echo '<div style="display:grid;grid-template-columns:minmax(320px,1fr) minmax(420px,1.2fr);gap:16px;max-width:1200px;margin-top:20px;">';
    echo '<section style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;">';
    echo '<h2 style="margin-top:0;">Recent Manual Adjustments</h2>';
    if (empty($recent)) {
      echo '<p style="margin-bottom:0;">No manual adjustments saved for this week.</p>';
    } else {
      foreach ($recent as $row) {
        $adjustment_id = (int)$row['ID'];
        echo '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;margin-bottom:12px;">';
        echo '<strong>' . esc_html($this->job_path_label((int)$row['job_path_term_id'])) . '</strong>';
        echo '<div style="color:#50575e;margin-top:6px;">' . esc_html(number_format((float)$row['hours'], 2, '.', ',')) . ' hours</div>';
        if ((string)$row['note'] !== '') echo '<div style="color:#646970;font-size:12px;margin-top:4px;">' . esc_html((string)$row['note']) . '</div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
        echo '<input type="hidden" name="action" value="eum_expected_labor_delete_adjustment">';
        echo '<input type="hidden" name="adjustment_id" value="' . $adjustment_id . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_attr(admin_url('index.php?page=' . self::PAGE_SLUG . '&week_start=' . $filters['week_start'])) . '">';
        wp_nonce_field('eum_expected_labor_delete_adjustment_' . $adjustment_id, 'eum_expected_labor_delete_adjustment_nonce');
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Delete this adjustment?\');">Delete</button>';
        echo '</form></div>';
      }
    }
    echo '</section>';

    echo '<section style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;">';
    echo '<h2 style="margin-top:0;">Manual Bulk Additions</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_expected_labor_save_adjustments">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr(admin_url('index.php?page=' . self::PAGE_SLUG . '&week_start=' . $filters['week_start'])) . '">';
    wp_nonce_field('eum_expected_labor_adjustments_save', 'eum_expected_labor_adjustment_nonce');
    echo '<p><label><strong>Week Starting</strong></label><br><input type="date" name="adjustment_week_start" value="' . esc_attr((string)$filters['week_start']) . '"></p>';
    echo '<table class="widefat striped"><thead><tr><th>Job Path</th><th style="width:120px;">Hours</th><th>Notes</th></tr></thead><tbody>';
    for ($i = 0; $i < 6; $i++) {
      echo '<tr><td><select name="adjustment_job_path_term_id[]" style="width:100%;"><option value="0">Select Job Path</option>';
      foreach ($path_options as $term_id => $label) {
        echo '<option value="' . (int)$term_id . '">' . esc_html($label) . '</option>';
      }
      echo '</select></td><td><input type="number" step="0.01" name="adjustment_hours[]" style="width:100%;"></td><td><input type="text" name="adjustment_note[]" style="width:100%;"></td></tr>';
    }
    echo '</tbody></table><p style="margin:14px 0 0;"><button type="submit" class="button button-primary">Save Adjustments</button></p></form></section></div>';
    return (string)ob_get_clean();
  }

  private function render_rule_manager(int $editing_rule_id): string {
    $rules = $this->rule_payloads();
    $editing = $editing_rule_id > 0 ? $this->rule_payload($editing_rule_id) : [];
    $path_options = $this->all_job_path_options();
    $metric_options = class_exists('EUM_Performance_Metrics') ? EUM_Performance_Metrics::metric_options_for_user((int)get_current_user_id()) : [];
    ob_start();
    echo '<div style="display:grid;grid-template-columns:minmax(320px,1fr) minmax(420px,1.2fr);gap:16px;max-width:1200px;margin-top:20px;">';
    echo '<section style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;">';
    echo '<h2 style="margin-top:0;">Labor Rules</h2>';
    if (empty($rules)) {
      echo '<p style="margin-bottom:0;">No expected labor rules created yet.</p>';
    } else {
      foreach ($rules as $rule) {
        $rule_id = (int)$rule['ID'];
        echo '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;margin-bottom:12px;">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;"><div><strong>' . esc_html((string)$rule['title']) . '</strong><div style="color:#646970;font-size:12px;margin-top:4px;">' . esc_html($this->job_path_label((int)$rule['job_path_term_id'])) . '</div></div><a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'edit_rule' => $rule_id], admin_url('index.php'))) . '">Edit</a></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
        echo '<input type="hidden" name="action" value="eum_expected_labor_delete_rule">';
        echo '<input type="hidden" name="rule_id" value="' . $rule_id . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_attr(admin_url('index.php?page=' . self::PAGE_SLUG)) . '">';
        wp_nonce_field('eum_expected_labor_delete_rule_' . $rule_id, 'eum_expected_labor_delete_rule_nonce');
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Delete this rule?\');">Delete</button></form></div>';
      }
    }
    echo '</section>';

    echo '<section style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;">';
    echo '<h2 style="margin-top:0;">' . ($editing_rule_id > 0 ? 'Edit Rule' : 'New Rule') . '</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_expected_labor_save_rule">';
    echo '<input type="hidden" name="rule_id" value="' . (int)$editing_rule_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr(admin_url('index.php?page=' . self::PAGE_SLUG)) . '">';
    wp_nonce_field('eum_expected_labor_rule_save', 'eum_expected_labor_rule_nonce');
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">';
    echo '<p style="margin:0;"><label><strong>Name</strong></label><br><input type="text" name="rule_title" value="' . esc_attr((string)($editing['title'] ?? '')) . '" style="width:100%;" required></p>';
    echo '<p style="margin:0;"><label><strong>Job Path</strong></label><br>' . (class_exists('EUM_Profiles') ? EUM_Profiles::render_job_path_directory_picker('rule_job_path_term_id', (int)($editing['job_path_term_id'] ?? 0), get_current_user_id(), ['placeholder' => 'Select Job Path']) : '<select name="rule_job_path_term_id" style="width:100%;"><option value="0">Select Job Path</option></select>') . '</p>';
    echo '<p style="margin:0;"><label><strong>Performance Metric</strong></label><br><select name="rule_metric_id" style="width:100%;"><option value="0">None / Manual Rule</option>';
    foreach ($metric_options as $metric_id => $label) {
      echo '<option value="' . (int)$metric_id . '" ' . selected((int)($editing['metric_id'] ?? 0), (int)$metric_id, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></p>';
    echo '<p style="margin:0;"><label><input type="checkbox" name="rule_include_descendants" value="1" ' . checked(!empty($editing['include_descendants']), true, false) . '> Applies to descendants</label></p>';
    echo '<p style="margin:0;"><label><input type="checkbox" name="rule_active" value="1" ' . checked(!array_key_exists('active', $editing) || !empty($editing['active']), true, false) . '> Active</label></p>';
    echo '<p style="margin:0;"><label><strong>Base Hours</strong></label><br><input type="number" step="0.01" name="rule_base_hours" value="' . esc_attr(isset($editing['base_hours']) ? number_format((float)$editing['base_hours'], 2, '.', '') : '0.00') . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>Hours per Metric Unit</strong></label><br><input type="number" step="0.01" name="rule_hours_per_unit" value="' . esc_attr(isset($editing['hours_per_unit']) ? number_format((float)$editing['hours_per_unit'], 2, '.', '') : '0.00') . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>Flat Hours</strong></label><br><input type="number" step="0.01" name="rule_flat_hours" value="' . esc_attr(isset($editing['flat_hours']) ? number_format((float)$editing['flat_hours'], 2, '.', '') : '0.00') . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>Min Hours</strong></label><br><input type="number" step="0.01" name="rule_min_hours" value="' . esc_attr(isset($editing['min_hours']) ? number_format((float)$editing['min_hours'], 2, '.', '') : '0.00') . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>Max Hours</strong></label><br><input type="number" step="0.01" name="rule_max_hours" value="' . esc_attr(isset($editing['max_hours']) ? number_format((float)$editing['max_hours'], 2, '.', '') : '0.00') . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>Sort Order</strong></label><br><input type="number" name="rule_sort_order" value="' . (int)($editing['sort_order'] ?? 0) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;grid-column:1/-1;"><label><strong>Notes</strong></label><br><input type="text" name="rule_note" value="' . esc_attr((string)($editing['note'] ?? '')) . '" style="width:100%;"></p>';
    echo '</div><p style="margin:14px 0 0;"><button type="submit" class="button button-primary">Save Rule</button></p></form></section></div>';
    return (string)ob_get_clean();
  }

  private function rule_payloads(): array {
    $ids = get_posts(['post_type' => self::CPT_RULE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC']);
    $rows = [];
    foreach ((array)$ids as $post_id) {
      $payload = $this->rule_payload((int)$post_id);
      if (!empty($payload)) $rows[] = $payload;
    }
    usort($rows, static function($a, $b) {
      $cmp = ((int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0));
      return $cmp !== 0 ? $cmp : strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });
    return $rows;
  }

  private function rule_payload(int $post_id): array {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== self::CPT_RULE) return [];
    return [
      'ID' => $post_id,
      'title' => (string)$post->post_title,
      'job_path_term_id' => (int)get_post_meta($post_id, self::META_JOB_PATH_TERM_ID, true),
      'include_descendants' => !empty(get_post_meta($post_id, self::META_INCLUDE_DESCENDANTS, true)),
      'metric_id' => (int)get_post_meta($post_id, self::META_METRIC_ID, true),
      'base_hours' => round((float)get_post_meta($post_id, self::META_BASE_HOURS, true), 4),
      'hours_per_unit' => round((float)get_post_meta($post_id, self::META_HOURS_PER_UNIT, true), 4),
      'flat_hours' => round((float)get_post_meta($post_id, self::META_FLAT_HOURS, true), 4),
      'min_hours' => round((float)get_post_meta($post_id, self::META_MIN_HOURS, true), 4),
      'max_hours' => round((float)get_post_meta($post_id, self::META_MAX_HOURS, true), 4),
      'sort_order' => (int)get_post_meta($post_id, self::META_SORT_ORDER, true),
      'active' => !empty(get_post_meta($post_id, self::META_ACTIVE, true)),
      'note' => sanitize_text_field((string)get_post_meta($post_id, self::META_NOTE, true)),
    ];
  }

  private function adjustment_payloads_for_week(string $week_start): array {
    $ids = get_posts([
      'post_type' => self::CPT_ADJUSTMENT,
      'post_status' => 'publish',
      'numberposts' => -1,
      'fields' => 'ids',
      'meta_query' => [['key' => self::META_WEEK_START, 'value' => $week_start]],
      'orderby' => 'date',
      'order' => 'DESC',
    ]);
    $rows = [];
    foreach ((array)$ids as $post_id) {
      $path_id = (int)get_post_meta((int)$post_id, self::META_JOB_PATH_TERM_ID, true);
      if ($path_id <= 0) continue;
      $rows[] = [
        'ID' => (int)$post_id,
        'job_path_term_id' => $path_id,
        'week_start' => $week_start,
        'hours' => round((float)get_post_meta((int)$post_id, self::META_HOURS, true), 4),
        'note' => sanitize_text_field((string)get_post_meta((int)$post_id, self::META_NOTE, true)),
      ];
    }
    return $rows;
  }

  private function rule_applies_to_path(array $rule, int $path_id): bool {
    $rule_path_id = (int)($rule['job_path_term_id'] ?? 0);
    if ($rule_path_id <= 0 || $path_id <= 0) return false;
    if ($rule_path_id === $path_id) return true;
    return !empty($rule['include_descendants']) && term_is_ancestor_of($rule_path_id, $path_id, 'eum_job_path');
  }

  private function all_job_path_options(): array {
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'number' => 0]);
    if (is_wp_error($terms) || empty($terms)) return [];
    $out = [];
    foreach ($terms as $term) {
      $out[(int)$term->term_id] = $this->job_path_label((int)$term->term_id);
    }
    natcasesort($out);
    return $out;
  }

  private function visible_job_path_options(int $viewer_user_id): array {
    $out = [];
    foreach ($this->all_job_path_options() as $term_id => $label) {
      if ($this->user_can_view_job_path($viewer_user_id, (int)$term_id)) $out[(int)$term_id] = $label;
    }
    return $out;
  }

  private function job_path_label(int $term_id): string {
    if ($term_id <= 0) return '';
    if (class_exists('EUM_Profiles')) return EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ');
    $term = get_term($term_id, 'eum_job_path');
    return ($term && !is_wp_error($term)) ? (string)$term->name : ('Job Path #' . $term_id);
  }

  private function user_can_view_job_path(int $viewer_user_id, int $term_id): bool {
    if ($viewer_user_id <= 0 || $term_id <= 0) return false;
    if (class_exists('EUM_Access') && (EUM_Access::is_it_or_hr($viewer_user_id) || EUM_Access::is_finance_user($viewer_user_id))) return true;
    foreach ($this->manager_root_job_terms($viewer_user_id) as $root_term_id) {
      if ($term_id === $root_term_id || term_is_ancestor_of($root_term_id, $term_id, 'eum_job_path')) return true;
    }
    return false;
  }

  private function manager_root_job_terms(int $viewer_user_id): array {
    if ($viewer_user_id <= 0 || !class_exists('EUM_Manager_IDs')) return [];
    $posts = get_posts(['post_type' => EUM_Manager_IDs::CPT, 'post_status' => 'publish', 'numberposts' => 2000, 'fields' => 'ids']);
    $term_ids = [];
    foreach ((array)$posts as $post_id) {
      $manager_users = EUM_Manager_IDs::manager_users_for_id((int)$post_id);
      if (!in_array($viewer_user_id, $manager_users, true)) continue;
      $term_id = (int)get_post_meta((int)$post_id, EUM_Manager_IDs::META_JOB_PATH_TERM, true);
      if ($term_id > 0) $term_ids[] = $term_id;
    }
    return array_values(array_unique($term_ids));
  }

  private function path_matches_scope(int $candidate_term_id, int $scope_term_id, bool $include_descendants): bool {
    if ($scope_term_id <= 0) return true;
    if ($candidate_term_id <= 0) return false;
    if ($candidate_term_id === $scope_term_id) return true;
    return $include_descendants && term_is_ancestor_of($scope_term_id, $candidate_term_id, 'eum_job_path');
  }
}
