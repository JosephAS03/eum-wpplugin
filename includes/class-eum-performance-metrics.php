<?php
if (!defined('ABSPATH')) exit;

class EUM_Performance_Metrics {
  public const CPT = 'eum_perf_metric';
  public const PAGE_SLUG = 'eum-performance-metrics';

  private const META_KEY = '_eum_perf_metric_key';
  private const META_UNIT = '_eum_perf_metric_unit';
  private const META_SOURCE = '_eum_perf_metric_source';
  private const META_AGGREGATION = '_eum_perf_metric_aggregation';
  private const META_SORT_ORDER = '_eum_perf_metric_sort_order';
  private const META_ACTIVE = '_eum_perf_metric_active';
  private const META_ASSIGNMENTS = '_eum_perf_metric_assignments';
  private const META_API_CONNECTOR = '_eum_perf_metric_api_connector';
  private const META_API_ENDPOINT = '_eum_perf_metric_api_endpoint';
  private const META_API_AUTH_HEADER = '_eum_perf_metric_api_auth_header';
  private const META_API_AUTH_TOKEN = '_eum_perf_metric_api_auth_token';
  private const META_API_VALUE_PATH = '_eum_perf_metric_api_value_path';

  public function init(): void {
    add_action('init', [$this, 'register_post_type']);
    add_action('admin_menu', [$this, 'admin_menu'], 35);
    add_action('admin_post_eum_perf_metric_save', [$this, 'handle_save_metric']);
    add_action('admin_post_eum_perf_metric_delete', [$this, 'handle_delete_metric']);
  }

  public static function can_view(int $user_id = 0): bool {
    $user_id = $user_id > 0 ? $user_id : (int)get_current_user_id();
    if ($user_id <= 0 || !current_user_can('read')) return false;
    if (class_exists('EUM_Access') && (EUM_Access::is_it_or_hr($user_id) || EUM_Access::is_finance_user($user_id))) return true;
    return class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_anything($user_id);
  }

  public static function can_manage(int $user_id = 0): bool {
    $user_id = $user_id > 0 ? $user_id : (int)get_current_user_id();
    if ($user_id <= 0) return false;
    return class_exists('EUM_Access') && (EUM_Access::is_it_or_hr($user_id) || EUM_Access::is_finance_user($user_id));
  }

  public function register_post_type(): void {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Performance Metrics',
        'singular_name' => 'Performance Metric',
      ],
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
    add_dashboard_page('Performance Metrics', 'Performance Metrics', 'read', self::PAGE_SLUG, [$this, 'render_page']);
    if (self::can_manage()) {
      add_submenu_page(EUM_Admin::parent_slug('finance'), 'Performance Metrics', 'Performance Metrics', 'read', self::PAGE_SLUG, [$this, 'render_page']);
    }
  }

  public function render_page(): void {
    if (!self::can_view()) wp_die('Not allowed.');
    $viewer_user_id = (int)get_current_user_id();
    $filters = $this->current_filters();
    $rows = $this->metric_rows_for_viewer($viewer_user_id, $filters);
    $editing_metric_id = self::can_manage($viewer_user_id) ? max(0, (int)($_GET['edit_metric'] ?? 0)) : 0;

    echo '<div class="wrap"><h1>Performance Metrics</h1>';
    echo '<p>Track path-scoped operating metrics, keep manual fallback values, and leave hooks in place for API-backed feeds.</p>';
    echo $this->render_notice();
    echo $this->render_filters($filters, $viewer_user_id);
    echo $this->render_metrics_table($rows);
    if (self::can_manage($viewer_user_id)) {
      echo $this->render_metric_manager($editing_metric_id);
    }
    echo '</div>';
  }

  public static function metric_options_for_user(int $user_id = 0): array {
    $viewer_user_id = $user_id > 0 ? $user_id : (int)get_current_user_id();
    if (!self::can_view($viewer_user_id)) return [];
    $self = new self();
    $out = [];
    foreach ($self->all_metric_payloads() as $payload) {
      if (empty($payload['active'])) continue;
      if (!$self->payload_has_visible_assignment($payload, $viewer_user_id)) continue;
      $out[(int)$payload['ID']] = (string)$payload['title'];
    }
    natcasesort($out);
    return $out;
  }

  public static function metric_payload(int $metric_id): array {
    return (new self())->metric_payload_from_post($metric_id);
  }

  public static function resolve_metric_value(int $metric_id, int $job_path_term_id = 0, array $args = []): float {
    $self = new self();
    $payload = $self->metric_payload_from_post($metric_id);
    if (empty($payload)) return 0.0;
    foreach ((array)($payload['assignments'] ?? []) as $assignment) {
      if ((int)($assignment['job_path_term_id'] ?? 0) === $job_path_term_id) {
        return $self->resolve_metric_value_from_payload($payload, $assignment, $args);
      }
    }
    if (!empty($payload['assignments'][0])) {
      return $self->resolve_metric_value_from_payload($payload, (array)$payload['assignments'][0], $args);
    }
    return 0.0;
  }

  public function handle_save_metric(): void {
    if (!is_user_logged_in() || !self::can_manage()) wp_die('Not allowed.');
    $metric_id = max(0, (int)($_POST['metric_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? admin_url('index.php?page=' . self::PAGE_SLUG)));
    if (!isset($_POST['eum_perf_metric_nonce']) || !wp_verify_nonce((string)$_POST['eum_perf_metric_nonce'], 'eum_perf_metric_save')) wp_die('Bad nonce.');

    $title = sanitize_text_field((string)($_POST['metric_title'] ?? ''));
    if ($title === '') {
      wp_safe_redirect(add_query_arg(['eum_msg' => 'Metric name is required.', 'eum_type' => 'error'], $return_url));
      exit;
    }

    $post_data = [
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'post_title' => $title,
    ];
    if ($metric_id > 0) $post_data['ID'] = $metric_id;
    else $post_data['post_author'] = (int)get_current_user_id();

    $saved = $metric_id > 0 ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);
    if (is_wp_error($saved) || (int)$saved <= 0) {
      wp_safe_redirect(add_query_arg(['eum_msg' => 'Could not save the metric.', 'eum_type' => 'error'], $return_url));
      exit;
    }
    $metric_id = (int)$saved;

    update_post_meta($metric_id, self::META_KEY, sanitize_title((string)($_POST['metric_key'] ?? $title)));
    update_post_meta($metric_id, self::META_UNIT, sanitize_text_field((string)($_POST['metric_unit'] ?? '')));
    update_post_meta($metric_id, self::META_SOURCE, $this->normalize_source((string)($_POST['metric_source'] ?? 'manual')));
    update_post_meta($metric_id, self::META_AGGREGATION, $this->normalize_aggregation((string)($_POST['metric_aggregation'] ?? 'value')));
    update_post_meta($metric_id, self::META_SORT_ORDER, (int)($_POST['metric_sort_order'] ?? 0));
    update_post_meta($metric_id, self::META_ACTIVE, !empty($_POST['metric_active']) ? '1' : '0');
    update_post_meta($metric_id, self::META_API_CONNECTOR, sanitize_text_field((string)($_POST['metric_api_connector'] ?? '')));
    update_post_meta($metric_id, self::META_API_ENDPOINT, esc_url_raw((string)($_POST['metric_api_endpoint'] ?? '')));
    update_post_meta($metric_id, self::META_API_AUTH_HEADER, sanitize_text_field((string)($_POST['metric_api_auth_header'] ?? '')));
    update_post_meta($metric_id, self::META_API_AUTH_TOKEN, sanitize_text_field((string)($_POST['metric_api_auth_token'] ?? '')));
    update_post_meta($metric_id, self::META_API_VALUE_PATH, sanitize_text_field((string)($_POST['metric_api_value_path'] ?? '')));
    update_post_meta($metric_id, self::META_ASSIGNMENTS, $this->assignments_from_request($_POST));

    wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'edit_metric' => $metric_id, 'eum_msg' => 'Metric saved.', 'eum_type' => 'success'], admin_url('index.php')));
    exit;
  }

  public function handle_delete_metric(): void {
    if (!is_user_logged_in() || !self::can_manage()) wp_die('Not allowed.');
    $metric_id = max(0, (int)($_POST['metric_id'] ?? 0));
    $return_url = esc_url_raw((string)($_POST['return_url'] ?? admin_url('index.php?page=' . self::PAGE_SLUG)));
    if (!isset($_POST['eum_perf_metric_delete_nonce']) || !wp_verify_nonce((string)$_POST['eum_perf_metric_delete_nonce'], 'eum_perf_metric_delete_' . $metric_id)) wp_die('Bad nonce.');
    if ($metric_id > 0) wp_delete_post($metric_id, true);
    wp_safe_redirect(add_query_arg(['eum_msg' => 'Metric deleted.', 'eum_type' => 'success'], $return_url));
    exit;
  }

  private function current_filters(): array {
    return [
      'scope_path' => max(0, (int)($_GET['scope_path'] ?? 0)),
      'include_descendants' => !array_key_exists('include_descendants', $_GET) || !empty($_GET['include_descendants']),
      'start_date' => sanitize_text_field((string)($_GET['start_date'] ?? current_time('Y-m-d'))),
      'end_date' => sanitize_text_field((string)($_GET['end_date'] ?? current_time('Y-m-d'))),
      'sort_by' => $this->normalize_sort_by((string)($_GET['sort_by'] ?? 'path')),
      'sort_dir' => $this->normalize_sort_dir((string)($_GET['sort_dir'] ?? 'asc')),
    ];
  }

  private function normalize_sort_by(string $value): string {
    $value = sanitize_key($value);
    return in_array($value, ['metric', 'path', 'value', 'source'], true) ? $value : 'path';
  }

  private function normalize_sort_dir(string $value): string {
    $value = sanitize_key($value);
    return $value === 'desc' ? 'desc' : 'asc';
  }

  private function normalize_source(string $value): string {
    $value = sanitize_key($value);
    return in_array($value, ['manual', 'api', 'computed'], true) ? $value : 'manual';
  }

  private function normalize_aggregation(string $value): string {
    $value = sanitize_key($value);
    return in_array($value, ['value', 'sum', 'average', 'count', 'ratio'], true) ? $value : 'value';
  }

  private function metric_rows_for_viewer(int $viewer_user_id, array $filters): array {
    $rows = [];
    foreach ($this->all_metric_payloads() as $payload) {
      foreach ((array)($payload['assignments'] ?? []) as $assignment) {
        $job_path_term_id = (int)($assignment['job_path_term_id'] ?? 0);
        if ($job_path_term_id <= 0) continue;
        if (!$this->user_can_view_job_path($viewer_user_id, $job_path_term_id)) continue;
        if (!$this->path_matches_scope($job_path_term_id, (int)$filters['scope_path'], !empty($filters['include_descendants']))) continue;
        $rows[] = [
          'metric_id' => (int)$payload['ID'],
          'metric_name' => (string)$payload['title'],
          'metric_key' => (string)$payload['metric_key'],
          'path_term_id' => $job_path_term_id,
          'path_label' => $this->job_path_label($job_path_term_id),
          'value' => $this->resolve_metric_value_from_payload($payload, $assignment, $filters),
          'unit' => (string)$payload['unit'],
          'source' => (string)$payload['source'],
          'source_label' => ucfirst((string)$payload['source']),
          'assignment_note' => sanitize_text_field((string)($assignment['note'] ?? '')),
          'active' => !empty($payload['active']),
          'sort_order' => (int)($payload['sort_order'] ?? 0),
        ];
      }
    }

    $sort_by = (string)$filters['sort_by'];
    $sort_dir = (string)$filters['sort_dir'];
    usort($rows, function(array $a, array $b) use ($sort_by, $sort_dir): int {
      $left = $a[$sort_by === 'metric' ? 'metric_name' : ($sort_by === 'source' ? 'source' : ($sort_by === 'value' ? 'value' : 'path_label'))] ?? '';
      $right = $b[$sort_by === 'metric' ? 'metric_name' : ($sort_by === 'source' ? 'source' : ($sort_by === 'value' ? 'value' : 'path_label'))] ?? '';
      $cmp = ($sort_by === 'value') ? ((float)$left <=> (float)$right) : strcasecmp((string)$left, (string)$right);
      if ($cmp === 0) $cmp = ((int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0));
      return $sort_dir === 'desc' ? (-1 * $cmp) : $cmp;
    });

    return $rows;
  }

  private function resolve_metric_value_from_payload(array $payload, array $assignment, array $args = []): float {
    $manual_value = round((float)($assignment['manual_value'] ?? 0), 4);
    $resolved = null;
    $source = (string)($payload['source'] ?? 'manual');
    if ($source === 'api') {
      $resolved = $this->resolve_api_metric_value($payload, $assignment, $args);
    } elseif ($source === 'computed') {
      $resolved = apply_filters('eum_performance_metric_computed_value', null, $payload, $assignment, $args);
    }
    $resolved = apply_filters('eum_performance_metric_value', $resolved, $payload, $assignment, $args);
    return round(is_numeric($resolved) ? (float)$resolved : $manual_value, 4);
  }

  private function resolve_api_metric_value(array $payload, array $assignment, array $args = []): ?float {
    $metric_id = (int)($payload['ID'] ?? 0);
    $job_path_term_id = (int)($assignment['job_path_term_id'] ?? 0);
    $cache_key = 'eum_perf_metric_' . md5(implode('|', [$metric_id, $job_path_term_id, (string)($args['start_date'] ?? ''), (string)($args['end_date'] ?? '')]));
    $cached = get_transient($cache_key);
    if ($cached !== false) return round((float)$cached, 4);

    $pre = apply_filters('eum_performance_metric_api_value', null, $payload, $assignment, $args);
    if (is_numeric($pre)) {
      set_transient($cache_key, (float)$pre, 10 * MINUTE_IN_SECONDS);
      return round((float)$pre, 4);
    }

    $endpoint = trim((string)($payload['api_endpoint'] ?? ''));
    if ($endpoint === '') return null;

    $replacements = [
      '{metric_id}' => (string)$metric_id,
      '{metric_key}' => rawurlencode((string)($payload['metric_key'] ?? '')),
      '{path_term_id}' => (string)$job_path_term_id,
      '{start_date}' => rawurlencode((string)($args['start_date'] ?? '')),
      '{end_date}' => rawurlencode((string)($args['end_date'] ?? '')),
    ];
    $url = strtr($endpoint, $replacements);
    $request_args = ['timeout' => 12, 'headers' => []];
    $header_name = trim((string)($payload['api_auth_header'] ?? ''));
    $header_token = trim((string)($payload['api_auth_token'] ?? ''));
    if ($header_name !== '' && $header_token !== '') {
      $request_args['headers'][$header_name] = $header_token;
    }
    $request_args = apply_filters('eum_performance_metric_api_request_args', $request_args, $payload, $assignment, $args);
    $response = wp_remote_get($url, $request_args);
    if (is_wp_error($response)) return null;

    $body = wp_remote_retrieve_body($response);
    if ($body === '') return null;
    if (is_numeric(trim($body))) {
      $numeric = (float)trim($body);
      set_transient($cache_key, $numeric, 10 * MINUTE_IN_SECONDS);
      return round($numeric, 4);
    }

    $decoded = json_decode($body, true);
    $value = $this->extract_json_value($decoded, (string)($payload['api_value_path'] ?? ''));
    if (!is_numeric($value)) return null;
    $numeric = (float)$value;
    set_transient($cache_key, $numeric, 10 * MINUTE_IN_SECONDS);
    return round($numeric, 4);
  }

  private function extract_json_value($decoded, string $path) {
    if ($path === '' || $path === '.') return $decoded;
    $cursor = $decoded;
    foreach (array_values(array_filter(explode('.', $path), static fn($part) => $part !== '')) as $segment) {
      if (is_array($cursor) && array_key_exists($segment, $cursor)) {
        $cursor = $cursor[$segment];
        continue;
      }
      return null;
    }
    return $cursor;
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
    echo '<p style="margin:0;"><label><strong>Start Date</strong></label><br><input type="date" name="start_date" value="' . esc_attr((string)$filters['start_date']) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>End Date</strong></label><br><input type="date" name="end_date" value="' . esc_attr((string)$filters['end_date']) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>Sort By</strong></label><br><select name="sort_by" style="width:100%;"><option value="path"' . selected((string)$filters['sort_by'], 'path', false) . '>Path</option><option value="metric"' . selected((string)$filters['sort_by'], 'metric', false) . '>Metric</option><option value="value"' . selected((string)$filters['sort_by'], 'value', false) . '>Value</option><option value="source"' . selected((string)$filters['sort_by'], 'source', false) . '>Source</option></select></p>';
    echo '<p style="margin:0;"><label><strong>Direction</strong></label><br><select name="sort_dir" style="width:100%;"><option value="asc"' . selected((string)$filters['sort_dir'], 'asc', false) . '>Ascending</option><option value="desc"' . selected((string)$filters['sort_dir'], 'desc', false) . '>Descending</option></select></p>';
    echo '<p style="margin:0;"><label><input type="checkbox" name="include_descendants" value="1" ' . checked(!empty($filters['include_descendants']), true, false) . '> Include descendants</label></p>';
    echo '</div>';
    echo '<p style="margin:12px 0 0;"><button type="submit" class="button button-primary">Apply Filters</button></p>';
    echo '</form>';
    return (string)ob_get_clean();
  }

  private function render_metrics_table(array $rows): string {
    ob_start();
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;max-width:1200px;">';
    echo '<h2 style="margin-top:0;">Metric Coverage</h2>';
    if (empty($rows)) {
      echo '<p style="margin-bottom:0;">No metrics matched the current scope.</p>';
      echo '</div>';
      return (string)ob_get_clean();
    }
    echo '<table class="widefat striped"><thead><tr><th>Metric</th><th>Path</th><th style="width:120px;">Value</th><th style="width:110px;">Unit</th><th style="width:120px;">Source</th><th>Notes</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      echo '<tr>';
      echo '<td><strong>' . esc_html((string)$row['metric_name']) . '</strong><div style="color:#646970;font-size:12px;">' . esc_html((string)$row['metric_key']) . '</div></td>';
      echo '<td>' . esc_html((string)$row['path_label']) . '</td>';
      echo '<td>' . esc_html(number_format((float)$row['value'], 2, '.', ',')) . '</td>';
      echo '<td>' . esc_html((string)$row['unit']) . '</td>';
      echo '<td>' . esc_html((string)$row['source_label']) . '</td>';
      echo '<td>' . esc_html((string)$row['assignment_note']) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    return (string)ob_get_clean();
  }

  private function render_metric_manager(int $editing_metric_id): string {
    $payloads = $this->all_metric_payloads();
    $editing = $editing_metric_id > 0 ? $this->metric_payload_from_post($editing_metric_id) : [];
    $path_options = $this->all_job_path_options();
    ob_start();
    echo '<div style="display:grid;grid-template-columns:minmax(320px,1fr) minmax(420px,1.2fr);gap:16px;max-width:1200px;margin-top:20px;">';
    echo '<section style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;">';
    echo '<h2 style="margin-top:0;">Existing Metrics</h2>';
    if (empty($payloads)) {
      echo '<p style="margin-bottom:0;">No performance metrics created yet.</p>';
    } else {
      foreach ($payloads as $payload) {
        $metric_id = (int)$payload['ID'];
        $assignments = (array)($payload['assignments'] ?? []);
        echo '<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;margin-bottom:12px;">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">';
        echo '<div><strong>' . esc_html((string)$payload['title']) . '</strong><div style="color:#646970;font-size:12px;margin-top:4px;">' . esc_html((string)$payload['source']) . ' / ' . esc_html((string)$payload['unit']) . '</div></div>';
        echo '<a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'edit_metric' => $metric_id], admin_url('index.php'))) . '">Edit</a>';
        echo '</div>';
        echo '<div style="margin-top:8px;color:#50575e;font-size:13px;">' . count($assignments) . ' path assignment(s)</div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
        echo '<input type="hidden" name="action" value="eum_perf_metric_delete">';
        echo '<input type="hidden" name="metric_id" value="' . $metric_id . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_attr(admin_url('index.php?page=' . self::PAGE_SLUG)) . '">';
        wp_nonce_field('eum_perf_metric_delete_' . $metric_id, 'eum_perf_metric_delete_nonce');
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Delete this metric?\');">Delete</button>';
        echo '</form>';
        echo '</div>';
      }
    }
    echo '</section>';

    echo '<section style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;">';
    echo '<h2 style="margin-top:0;">' . ($editing_metric_id > 0 ? 'Edit Metric' : 'New Metric') . '</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="eum_perf_metric_save">';
    echo '<input type="hidden" name="metric_id" value="' . (int)$editing_metric_id . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr(admin_url('index.php?page=' . self::PAGE_SLUG)) . '">';
    wp_nonce_field('eum_perf_metric_save', 'eum_perf_metric_nonce');
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">';
    echo '<p style="margin:0;"><label><strong>Name</strong></label><br><input type="text" name="metric_title" value="' . esc_attr((string)($editing['title'] ?? '')) . '" style="width:100%;" required></p>';
    echo '<p style="margin:0;"><label><strong>Metric Key</strong></label><br><input type="text" name="metric_key" value="' . esc_attr((string)($editing['metric_key'] ?? '')) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>Unit</strong></label><br><input type="text" name="metric_unit" value="' . esc_attr((string)($editing['unit'] ?? '')) . '" style="width:100%;" placeholder="Tickets, Calls, Streams"></p>';
    echo '<p style="margin:0;"><label><strong>Source</strong></label><br><select name="metric_source" style="width:100%;"><option value="manual"' . selected((string)($editing['source'] ?? 'manual'), 'manual', false) . '>Manual</option><option value="api"' . selected((string)($editing['source'] ?? ''), 'api', false) . '>API</option><option value="computed"' . selected((string)($editing['source'] ?? ''), 'computed', false) . '>Computed Hook</option></select></p>';
    echo '<p style="margin:0;"><label><strong>Aggregation</strong></label><br><select name="metric_aggregation" style="width:100%;"><option value="value"' . selected((string)($editing['aggregation'] ?? 'value'), 'value', false) . '>Single Value</option><option value="sum"' . selected((string)($editing['aggregation'] ?? ''), 'sum', false) . '>Sum</option><option value="average"' . selected((string)($editing['aggregation'] ?? ''), 'average', false) . '>Average</option><option value="count"' . selected((string)($editing['aggregation'] ?? ''), 'count', false) . '>Count</option><option value="ratio"' . selected((string)($editing['aggregation'] ?? ''), 'ratio', false) . '>Ratio</option></select></p>';
    echo '<p style="margin:0;"><label><strong>Sort Order</strong></label><br><input type="number" name="metric_sort_order" value="' . (int)($editing['sort_order'] ?? 0) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><input type="checkbox" name="metric_active" value="1" ' . checked(!empty($editing['active']), true, false) . '> Active</label></p>';
    echo '</div>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:14px;">';
    echo '<p style="margin:0;"><label><strong>API Connector Label</strong></label><br><input type="text" name="metric_api_connector" value="' . esc_attr((string)($editing['api_connector'] ?? '')) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;grid-column:1/-1;"><label><strong>API Endpoint</strong></label><br><input type="url" name="metric_api_endpoint" value="' . esc_attr((string)($editing['api_endpoint'] ?? '')) . '" style="width:100%;" placeholder="https://api.example.com/metrics/{metric_key}?path={path_term_id}&start={start_date}&end={end_date}"></p>';
    echo '<p style="margin:0;"><label><strong>API Auth Header</strong></label><br><input type="text" name="metric_api_auth_header" value="' . esc_attr((string)($editing['api_auth_header'] ?? '')) . '" style="width:100%;" placeholder="Authorization"></p>';
    echo '<p style="margin:0;"><label><strong>API Auth Token</strong></label><br><input type="text" name="metric_api_auth_token" value="' . esc_attr((string)($editing['api_auth_token'] ?? '')) . '" style="width:100%;"></p>';
    echo '<p style="margin:0;"><label><strong>JSON Value Path</strong></label><br><input type="text" name="metric_api_value_path" value="' . esc_attr((string)($editing['api_value_path'] ?? '')) . '" style="width:100%;" placeholder="data.value"></p>';
    echo '</div>';
    echo '<h3 style="margin:18px 0 10px;">Path Assignments</h3>';
    echo '<table class="widefat striped"><thead><tr><th>Job Path</th><th style="width:120px;">Manual Value</th><th>Notes</th></tr></thead><tbody>';
    $assignment_rows = array_values((array)($editing['assignments'] ?? []));
    $row_total = max(8, count($assignment_rows) + 2);
    for ($i = 0; $i < $row_total; $i++) {
      $row = $assignment_rows[$i] ?? [];
      echo '<tr>';
      echo '<td><select name="assignment_job_path_term_id[]" style="width:100%;"><option value="0">Select Job Path</option>';
      foreach ($path_options as $term_id => $label) {
        echo '<option value="' . (int)$term_id . '" ' . selected((int)($row['job_path_term_id'] ?? 0), (int)$term_id, false) . '>' . esc_html($label) . '</option>';
      }
      echo '</select></td>';
      echo '<td><input type="number" step="0.01" name="assignment_manual_value[]" value="' . esc_attr(isset($row['manual_value']) ? number_format((float)$row['manual_value'], 2, '.', '') : '') . '" style="width:100%;"></td>';
      echo '<td><input type="text" name="assignment_note[]" value="' . esc_attr((string)($row['note'] ?? '')) . '" style="width:100%;"></td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p style="margin:14px 0 0;"><button type="submit" class="button button-primary">Save Metric</button></p>';
    echo '</form></section></div>';
    return (string)ob_get_clean();
  }

  private function assignments_from_request(array $source): array {
    $path_ids = isset($source['assignment_job_path_term_id']) ? (array)$source['assignment_job_path_term_id'] : [];
    $manual_values = isset($source['assignment_manual_value']) ? (array)$source['assignment_manual_value'] : [];
    $notes = isset($source['assignment_note']) ? (array)$source['assignment_note'] : [];
    $rows = [];
    $count = max(count($path_ids), count($manual_values), count($notes));
    for ($i = 0; $i < $count; $i++) {
      $path_id = max(0, (int)($path_ids[$i] ?? 0));
      if ($path_id <= 0) continue;
      $rows[$path_id] = [
        'job_path_term_id' => $path_id,
        'manual_value' => round((float)($manual_values[$i] ?? 0), 4),
        'note' => sanitize_text_field((string)($notes[$i] ?? '')),
      ];
    }
    return array_values($rows);
  }

  private function all_metric_payloads(): array {
    $ids = get_posts([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'numberposts' => -1,
      'fields' => 'ids',
      'orderby' => 'title',
      'order' => 'ASC',
    ]);
    $out = [];
    foreach ((array)$ids as $post_id) {
      $payload = $this->metric_payload_from_post((int)$post_id);
      if (!empty($payload)) $out[] = $payload;
    }
    usort($out, static fn($a, $b) => strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    return $out;
  }

  private function metric_payload_from_post(int $post_id): array {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== self::CPT) return [];
    $assignments = get_post_meta($post_id, self::META_ASSIGNMENTS, true);
    if (!is_array($assignments)) $assignments = [];
    $normalized_assignments = [];
    foreach ($assignments as $assignment) {
      if (!is_array($assignment)) continue;
      $path_id = max(0, (int)($assignment['job_path_term_id'] ?? 0));
      if ($path_id <= 0) continue;
      $normalized_assignments[] = [
        'job_path_term_id' => $path_id,
        'manual_value' => round((float)($assignment['manual_value'] ?? 0), 4),
        'note' => sanitize_text_field((string)($assignment['note'] ?? '')),
      ];
    }
    return [
      'ID' => $post_id,
      'title' => (string)$post->post_title,
      'metric_key' => sanitize_title((string)get_post_meta($post_id, self::META_KEY, true)),
      'unit' => sanitize_text_field((string)get_post_meta($post_id, self::META_UNIT, true)),
      'source' => $this->normalize_source((string)get_post_meta($post_id, self::META_SOURCE, true)),
      'aggregation' => $this->normalize_aggregation((string)get_post_meta($post_id, self::META_AGGREGATION, true)),
      'sort_order' => (int)get_post_meta($post_id, self::META_SORT_ORDER, true),
      'active' => !empty(get_post_meta($post_id, self::META_ACTIVE, true)),
      'api_connector' => sanitize_text_field((string)get_post_meta($post_id, self::META_API_CONNECTOR, true)),
      'api_endpoint' => esc_url_raw((string)get_post_meta($post_id, self::META_API_ENDPOINT, true)),
      'api_auth_header' => sanitize_text_field((string)get_post_meta($post_id, self::META_API_AUTH_HEADER, true)),
      'api_auth_token' => sanitize_text_field((string)get_post_meta($post_id, self::META_API_AUTH_TOKEN, true)),
      'api_value_path' => sanitize_text_field((string)get_post_meta($post_id, self::META_API_VALUE_PATH, true)),
      'assignments' => $normalized_assignments,
    ];
  }

  private function payload_has_visible_assignment(array $payload, int $viewer_user_id): bool {
    foreach ((array)($payload['assignments'] ?? []) as $assignment) {
      if ($this->user_can_view_job_path($viewer_user_id, (int)($assignment['job_path_term_id'] ?? 0))) return true;
    }
    return false;
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
    $posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'fields' => 'ids',
    ]);
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
