<?php
if (!defined('ABSPATH')) exit;

class EUM_Finance {
  const CPT_RECORD = 'eum_finance_record';
  const CPT_RECURRING = 'eum_finance_recurring';
  const TAX_FINANCE_PATH = 'eum_finance_path';

  const META_KIND = '_eum_finance_kind';
  const META_AMOUNT = '_eum_finance_amount';
  const META_CURRENCY = '_eum_finance_currency';
  const META_OCCURRED_ON = '_eum_finance_occurred_on';
  const META_JOB_PATH_TERM_ID = '_eum_finance_job_path_term_id';
  const META_FINANCE_PATH_TERM_ID = '_eum_finance_finance_path_term_id';
  const META_RECEIPT_ID = '_eum_finance_receipt_id';
  const META_STATUS = '_eum_finance_status';
  const META_APPROVER_IDS = '_eum_finance_approver_ids';
  const META_APPROVED_BY = '_eum_finance_approved_by';
  const META_APPROVED_AT = '_eum_finance_approved_at';
  const META_SUBMITTED_BY = '_eum_finance_submitted_by';
  const META_NOTES = '_eum_finance_notes';
  const META_VENDOR = '_eum_finance_vendor';
  const META_RECURRING_ID = '_eum_finance_recurring_id';
  const META_EXTERNAL_ID = '_eum_finance_external_id';
  const META_NEEDS_RECEIPT = '_eum_finance_needs_receipt';

  const META_REC_KIND = '_eum_finance_recurring_kind';
  const META_REC_AMOUNT = '_eum_finance_recurring_amount';
  const META_REC_CURRENCY = '_eum_finance_recurring_currency';
  const META_REC_START = '_eum_finance_recurring_start';
  const META_REC_END = '_eum_finance_recurring_end';
  const META_REC_FREQ = '_eum_finance_recurring_frequency';
  const META_REC_JOB_PATH_TERM_ID = '_eum_finance_recurring_job_path_term_id';
  const META_REC_FINANCE_PATH_TERM_ID = '_eum_finance_recurring_finance_path_term_id';
  const META_REC_VENDOR = '_eum_finance_recurring_vendor';
  const META_REC_NOTES = '_eum_finance_recurring_notes';
  const META_REC_STATUS = '_eum_finance_recurring_status';
  const META_REC_APPROVER_IDS = '_eum_finance_recurring_approver_ids';
  const META_REC_APPROVED_BY = '_eum_finance_recurring_approved_by';
  const META_REC_APPROVED_AT = '_eum_finance_recurring_approved_at';
  const META_REC_SUBMITTED_BY = '_eum_finance_recurring_submitted_by';
  const META_REC_EXTERNAL_ID = '_eum_finance_recurring_external_id';
  const META_REC_NEEDS_RECEIPT = '_eum_finance_recurring_needs_receipt';
  const META_REC_LAST_MATERIALIZED_ON = '_eum_finance_recurring_last_materialized_on';

  const TERM_META_LINKED_JOB_PATH = '_eum_finance_linked_job_path';
  const TERM_META_EXTERNAL_CODE = '_eum_finance_external_code';
  const TERM_META_LABOR_PAYCODE = '_eum_finance_labor_paycode_enabled';
  const TERM_META_BUDGET = '_eum_finance_budget';
  const JOB_PATH_TERM_META_BUDGET = '_eum_job_path_budget';

  const OPTION_API_SETTINGS = 'eum_finance_api_settings';

  public function init(): void {
    add_action('init', [$this, 'register_post_types']);
    add_action('init', [$this, 'register_taxonomy']);
    add_action('admin_menu', [$this, 'admin_menu'], 30);
    add_shortcode('eum_finance_suite', [$this, 'shortcode_finance_suite']);
    add_shortcode('eum_finance_receipt_queue', [$this, 'shortcode_receipt_queue']);

    add_action('admin_post_eum_finance_save_record', [$this, 'handle_save_record']);
    add_action('admin_post_eum_finance_approve_record', [$this, 'handle_approve_record']);
    add_action('admin_post_eum_finance_save_recurring', [$this, 'handle_save_recurring']);
    add_action('admin_post_eum_finance_approve_recurring', [$this, 'handle_approve_recurring']);
    add_action('admin_post_eum_finance_save_api_settings', [$this, 'handle_save_api_settings']);

    add_action(self::TAX_FINANCE_PATH . '_add_form_fields', [$this, 'render_finance_path_add_fields']);
    add_action(self::TAX_FINANCE_PATH . '_edit_form_fields', [$this, 'render_finance_path_edit_fields']);
    add_action('created_' . self::TAX_FINANCE_PATH, [$this, 'save_finance_path_fields']);
    add_action('edited_' . self::TAX_FINANCE_PATH, [$this, 'save_finance_path_fields']);
    add_action('eum_job_path_add_form_fields', [$this, 'render_job_path_budget_add_fields']);
    add_action('eum_job_path_edit_form_fields', [$this, 'render_job_path_budget_edit_fields']);
    add_action('created_eum_job_path', [$this, 'save_job_path_budget_fields']);
    add_action('edited_eum_job_path', [$this, 'save_job_path_budget_fields']);

    add_action('rest_api_init', [$this, 'register_rest_routes']);
  }


  public static function labor_paycode_options(): array {
    $terms = get_terms([
      'taxonomy' => self::TAX_FINANCE_PATH,
      'hide_empty' => false,
      'orderby' => 'name',
      'order' => 'ASC',
      'number' => 0,
      'meta_query' => [
        [
          'key' => self::TERM_META_LABOR_PAYCODE,
          'value' => '1',
        ],
      ],
    ]);
    if (is_wp_error($terms) || empty($terms)) return [];
    $out = [];
    foreach ($terms as $term) {
      $term_id = (int)$term->term_id;
      $out[$term_id] = self::labor_paycode_option_label($term_id);
    }
    natcasesort($out);
    return $out;
  }

  public static function is_valid_labor_paycode(int $term_id): bool {
    if ($term_id <= 0 || !taxonomy_exists(self::TAX_FINANCE_PATH)) return false;
    $term = get_term($term_id, self::TAX_FINANCE_PATH);
    if (!$term || is_wp_error($term)) return false;
    return !empty(get_term_meta($term_id, self::TERM_META_LABOR_PAYCODE, true));
  }

  public static function finance_path_option_label(int $term_id): string {
    if ($term_id <= 0) return '';
    $term = get_term($term_id, self::TAX_FINANCE_PATH);
    if (!$term || is_wp_error($term)) return 'Finance Path #' . $term_id;
    $label = class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path(self::TAX_FINANCE_PATH, $term_id, ' / ') : ((string)$term->name);
    $external = (string)get_term_meta($term_id, self::TERM_META_EXTERNAL_CODE, true);
    if ($external !== '') $label .= ' [' . $external . ']';
    return $label;
  }

  public static function labor_paycode_option_label(int $term_id): string {
    if ($term_id <= 0) return '';
    $term = get_term($term_id, self::TAX_FINANCE_PATH);
    if (!$term || is_wp_error($term)) return 'Cost Center #' . $term_id;
    $external = trim((string)get_term_meta($term_id, self::TERM_META_EXTERNAL_CODE, true));
    $name = trim((string)$term->name);
    if ($external !== '' && $name !== '') return $external . ' - ' . $name;
    if ($external !== '') return $external;
    if ($name !== '') return $name;
    return 'Cost Center #' . $term_id;
  }

  public static function finance_path_budget(int $term_id): float {
    return round((float)get_term_meta($term_id, self::TERM_META_BUDGET, true), 2);
  }

  public static function job_path_budget(int $term_id): float {
    return round((float)get_term_meta($term_id, self::JOB_PATH_TERM_META_BUDGET, true), 2);
  }

  public function register_post_types(): void {
    register_post_type(self::CPT_RECORD, [
      'labels' => [
        'name' => 'Finance Records',
        'singular_name' => 'Finance Record',
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_menu' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
      'rewrite' => false,
    ]);

    register_post_type(self::CPT_RECURRING, [
      'labels' => [
        'name' => 'Recurring Finance Items',
        'singular_name' => 'Recurring Finance Item',
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

  public function register_taxonomy(): void {
    register_taxonomy(self::TAX_FINANCE_PATH, [self::CPT_RECORD, self::CPT_RECURRING], [
      'labels' => [
        'name' => 'Finance Paths',
        'singular_name' => 'Finance Path',
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => false,
      'show_admin_column' => false,
      'hierarchical' => true,
      'rewrite' => false,
      'show_in_rest' => false,
    ]);
  }

  public function admin_menu(): void {
    if (!$this->current_user_can_admin_finance()) return;

    add_submenu_page(EUM_Admin::parent_slug('finance'), 'Finance Suite', 'Finance Suite', 'read', 'eum-finance', [$this, 'render_admin_finance_page']);
    add_submenu_page(EUM_Admin::parent_slug('finance'), 'Recurring Receipt Queue', 'Finance Receipt Queue', 'read', 'eum-finance-receipts', [$this, 'render_admin_receipt_queue_page']);
    add_submenu_page(EUM_Admin::parent_slug('finance'), 'Finance Paths', 'Finance Paths', 'read', 'edit-tags.php?taxonomy=' . self::TAX_FINANCE_PATH . '&post_type=' . self::CPT_RECORD);
  }

  public function render_admin_finance_page(): void {
    if (!$this->current_user_can_admin_finance()) wp_die('Not allowed.');
    echo '<div class="wrap"><h1>Finance Suite</h1>';
    echo $this->render_suite(get_current_user_id(), true, false);
    echo '</div>';
  }

  public function render_admin_receipt_queue_page(): void {
    if (!$this->current_user_can_admin_finance()) wp_die('Not allowed.');
    echo '<div class="wrap"><h1>Recurring Receipt Queue</h1>';
    echo $this->render_receipt_queue_only(get_current_user_id(), true);
    echo '</div>';
  }

  public function shortcode_finance_suite($atts = []): string {
    try {
      $user_id = (int)get_current_user_id();
      if (!$this->current_user_can_use_suite($user_id)) {
        return '<div class="eum-finance eum-finance--denied">You do not have access to the finance suite.</div>';
      }
      return $this->render_suite($user_id, false, false);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Finance shortcode] '.$e->getMessage());
      return $this->render_runtime_error('Finance Suite', $e);
    }
  }

  public function shortcode_receipt_queue($atts = []): string {
    try {
      $user_id = (int)get_current_user_id();
      if (!$this->current_user_can_use_suite($user_id)) {
        return '<div class="eum-finance eum-finance--denied">You do not have access to the finance receipt queue.</div>';
      }
      return $this->render_receipt_queue_only($user_id, false);
    } catch (\Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Finance receipt queue shortcode] '.$e->getMessage());
      return $this->render_runtime_error('Finance Receipt Queue', $e);
    }
  }

  private function render_runtime_error(string $area, \Throwable $e): string {
    $detail = (defined('WP_DEBUG') && WP_DEBUG) ? '<div class="description" style="margin-top:8px">'.esc_html($e->getMessage()).'</div>' : '';
    return '<div class="eum-finance eum-finance--denied"><strong>'.esc_html($area).' temporarily unavailable.</strong><div class="description" style="margin-top:8px">A runtime error was caught so the rest of the site can continue loading.</div>'.$detail.'</div>';
  }

  private function render_suite(int $viewer_user_id, bool $is_admin, bool $queue_only): string {
    $viewer_is_finance = EUM_Access::is_finance_user($viewer_user_id);
    $prefill_recurring_id = isset($_GET['eum_finance_prefill_recurring']) ? (int)$_GET['eum_finance_prefill_recurring'] : 0;
    $prefill_recurring = $prefill_recurring_id > 0 ? $this->get_recurring_template($prefill_recurring_id) : [];

    ob_start();
    $this->print_inline_styles();
    echo '<div class="eum-finance">';
    echo $this->render_notice();

    $stats = $this->finance_stats_for_user($viewer_user_id);
    echo '<section class="eum-finance__hero">';
    echo '<div><h2>Finance Suite</h2><p>Track expenses, income, receipts, recurring items, and roll-up job-path cost visibility.</p></div>';
    echo '<div class="eum-finance__hero-badges">';
    echo '<span class="eum-finance__badge">Role: ' . esc_html($viewer_is_finance ? 'Finance' : 'Manager') . '</span>';
    echo '<span class="eum-finance__badge">Pending approvals: ' . (int)$stats['pending_approvals'] . '</span>';
    echo '<span class="eum-finance__badge">Open receipt gaps: ' . (int)$stats['missing_receipts'] . '</span>';
    echo '</div>';
    echo '</section>';

    echo '<section class="eum-finance__grid eum-finance__grid--cards">';
    echo $this->metric_card('Approved Expenses', $this->format_money($stats['approved_expenses']));
    echo $this->metric_card('Approved Income', $this->format_money($stats['approved_income']));
    echo $this->metric_card('Net', $this->format_money($stats['approved_income'] - $stats['approved_expenses']));
    echo $this->metric_card('Recurring Templates', (string)$stats['recurring_count']);
    echo '</section>';

    echo '<section class="eum-finance__panel">';
    echo '<div class="eum-finance__panel-head"><h3>Path Cost Dashboard</h3><span>Current and upcoming costs for your selected job path, including labor from timecards and schedules</span></div>';
    echo $this->render_path_cost_dashboard($viewer_user_id);
    echo '</section>';

    if (!$queue_only) {
      echo '<div class="eum-finance__two-up">';
      echo '<section class="eum-finance__panel">';
      echo '<div class="eum-finance__panel-head"><h3>Add Finance Record</h3><span>Expenses, income, retro adjustments, and receipts</span></div>';
      echo $this->render_record_form($viewer_user_id, $prefill_recurring);
      echo '</section>';

      echo '<section class="eum-finance__panel">';
      echo '<div class="eum-finance__panel-head"><h3>Add Recurring Item</h3><span>Use recurring templates for rent, subscriptions, payroll journals, and more</span></div>';
      echo $this->render_recurring_form($viewer_user_id);
      echo '</section>';
      echo '</div>';
    }

    if (!$queue_only) {
      echo '<div class="eum-finance__two-up">';
      echo '<section class="eum-finance__panel">';
      echo '<div class="eum-finance__panel-head"><h3>Pending Approvals</h3><span>Managers need second-level or finance approval</span></div>';
      echo $this->render_pending_approvals($viewer_user_id);
      echo '</section>';

      echo '<section class="eum-finance__panel">';
      echo '<div class="eum-finance__panel-head"><h3>Recent Records</h3><span>Roll-up amounts bill to the selected job path and all ancestors</span></div>';
      echo $this->render_records_table($viewer_user_id);
      echo '</section>';
      echo '</div>';
    }

    echo '<div class="eum-finance__two-up">';
    echo '<section class="eum-finance__panel">';
    echo '<div class="eum-finance__panel-head"><h3>Receipt Gaps</h3><span>Recurring items missing the current cycle receipt</span></div>';
    echo $this->render_receipt_queue_only($viewer_user_id, $is_admin);
    echo '</section>';

    echo '<section class="eum-finance__panel">';
    echo '<div class="eum-finance__panel-head"><h3>Job Path Rollup</h3><span>Top-level paths inherit costs from every child path below</span></div>';
    echo $this->render_rollup_report($viewer_user_id);
    echo '</section>';
    echo '</div>';

    if ($viewer_is_finance) {
      echo '<section class="eum-finance__panel">';
      echo '<div class="eum-finance__panel-head"><h3>API Bridge Settings</h3><span>Leave room for external systems and later automation</span></div>';
      echo $this->render_api_settings_form();
      echo '</section>';
    }

    echo '</div>';
    return ob_get_clean();
  }

  private function render_receipt_queue_only(int $viewer_user_id, bool $is_admin): string {
    $items = $this->recurring_templates_missing_current_receipt($viewer_user_id);
    ob_start();
    if (empty($items)) {
      echo '<div class="eum-finance__empty">All recurring items that require a receipt already have a current-cycle receipt uploaded.</div>';
      return ob_get_clean();
    }

    echo '<div class="eum-finance__table-wrap"><table class="widefat striped"><thead><tr>';
    echo '<th>Recurring Item</th><th>Cycle</th><th>Amount</th><th>Path</th><th>Finance Path</th><th>Actions</th>';
    echo '</tr></thead><tbody>';
    foreach ($items as $item) {
      $prefill_url = add_query_arg('eum_finance_prefill_recurring', (int)$item['ID'], $this->current_url());
      echo '<tr>';
      echo '<td><strong>' . esc_html($item['title']) . '</strong><div class="description">' . esc_html($item['frequency_label']) . '</div></td>';
      echo '<td>' . esc_html($item['cycle_label']) . '</td>';
      echo '<td>' . esc_html($this->format_money((float)$item['amount'], (string)$item['currency'])) . '</td>';
      echo '<td>' . esc_html($this->job_path_label((int)$item['job_path_term_id'])) . '</td>';
      echo '<td>' . esc_html($this->finance_path_label((int)$item['finance_path_term_id'])) . '</td>';
      echo '<td><a class="button button-primary" href="' . esc_url($prefill_url) . '">Create current record</a></td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
  }

  private function render_record_form(int $viewer_user_id, array $prefill_recurring = []): string {
    $is_finance = EUM_Access::is_finance_user($viewer_user_id);
    $job_term_id = (int)($prefill_recurring['job_path_term_id'] ?? 0);
    $finance_term_id = (int)($prefill_recurring['finance_path_term_id'] ?? 0);
    $kind = (string)($prefill_recurring['kind'] ?? 'expense');
    $amount = isset($prefill_recurring['amount']) ? (float)$prefill_recurring['amount'] : 0;
    $currency = (string)($prefill_recurring['currency'] ?? 'USD');
    $vendor = (string)($prefill_recurring['vendor'] ?? '');
    $notes = (string)($prefill_recurring['notes'] ?? '');
    $needs_receipt = !empty($prefill_recurring['needs_receipt']);
    $title = (string)($prefill_recurring['title'] ?? '');
    $recurring_id = (int)($prefill_recurring['ID'] ?? 0);

    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" class="eum-finance__form">';
    wp_nonce_field('eum_finance_save_record', 'eum_finance_nonce');
    echo '<input type="hidden" name="action" value="eum_finance_save_record">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    if ($recurring_id > 0) echo '<input type="hidden" name="recurring_id" value="' . (int)$recurring_id . '">';

    echo '<div class="eum-finance__field-grid">';
    echo $this->text_field('title', 'Title', $title, true, 'Vendor invoice, expense name, revenue line, or journal label');
    echo $this->select_field('kind', 'Type', [
      'expense' => 'Expense',
      'income' => 'Income',
      'other' => 'Other / Adjustment',
    ], $kind);
    echo $this->number_field('amount', 'Amount', $amount > 0 ? (string)$amount : '', true, '0.01');
    echo $this->text_field('currency', 'Currency', $currency, true, 'USD');
    echo $this->date_field('occurred_on', 'Occurred On', current_time('Y-m-d'));
    echo $this->text_field('vendor', 'Vendor / Source', $vendor, false, 'Payee, platform, client, or source');
    echo '</div>';

    echo '<div class="eum-finance__picker-grid">';
    echo '<div><label class="eum-finance__label">Bill to Job Path</label>';
    echo EUM_Profiles::render_job_path_directory_picker('job_path_term_id', $job_term_id, $viewer_user_id, [
      'required' => true,
      'placeholder' => '— Select a billed job path —',
      'enforce_manageable_job_paths' => !$is_finance,
    ]);
    echo '</div>';

    echo '<div><label class="eum-finance__label">Finance Path / Code</label>';
    echo EUM_Profiles::render_taxonomy_directory_picker(self::TAX_FINANCE_PATH, 'finance_path_term_id', $finance_term_id, $viewer_user_id, [
      'required' => false,
      'placeholder' => '— Select a finance path —',
      'none_label' => 'No finance path',
    ]);
    echo '<p class="description">Finance path term IDs and optional external code IDs are stored for later paycode and API integrations.</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="eum-finance__field-grid">';
    echo '<div><label class="eum-finance__label">Receipt / Backup</label><input type="file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,image/*,application/pdf"><p class="description">Receipt uploads are strongly recommended and are checked on recurring items.</p></div>';
    echo '<div><label class="eum-finance__label"><input type="checkbox" name="needs_receipt" value="1" ' . checked($needs_receipt, true, false) . '> Receipt required for this record</label>';
    if (!$is_finance) {
      echo '<p class="description">Managers can create records for their managed paths. Finance or second-level approval is required before records count toward approved totals.</p>';
      echo '<p class="description">Managers cannot submit retroactive records. Finance users can.</p>';
    } else {
      echo '<p class="description">Finance users auto-approve records and can backfill retroactive expenses or income.</p>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div><label class="eum-finance__label">Notes</label><textarea name="notes" rows="4" class="large-text" placeholder="Optional details, invoice numbers, GL clues, or audit notes">' . esc_textarea($notes) . '</textarea></div>';

    echo '<div class="eum-finance__actions"><button type="submit" class="button button-primary">' . esc_html($is_finance ? 'Add Approved Record' : 'Submit for Approval') . '</button></div>';
    echo '</form>';
    return ob_get_clean();
  }

  private function render_recurring_form(int $viewer_user_id): string {
    $is_finance = EUM_Access::is_finance_user($viewer_user_id);
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-finance__form">';
    wp_nonce_field('eum_finance_save_recurring', 'eum_finance_recurring_nonce');
    echo '<input type="hidden" name="action" value="eum_finance_save_recurring">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';

    echo '<div class="eum-finance__field-grid">';
    echo $this->text_field('title', 'Template Name', '', true, 'Rent, payroll clearing, software subscription, insurance, etc.');
    echo $this->select_field('kind', 'Type', [
      'expense' => 'Expense',
      'income' => 'Income',
      'other' => 'Other / Adjustment',
    ], 'expense');
    echo $this->number_field('amount', 'Amount', '', true, '0.01');
    echo $this->text_field('currency', 'Currency', 'USD', true, 'USD');
    echo $this->select_field('frequency', 'Frequency', $this->frequency_options(), 'monthly');
    echo $this->text_field('vendor', 'Vendor / Source', '', false, 'Recurring payee or source');
    echo '</div>';

    echo '<div class="eum-finance__field-grid">';
    echo $this->date_field('start_on', 'Start On', current_time('Y-m-d'));
    echo $this->date_field('end_on', 'End On (optional)', '');
    echo '<div><label class="eum-finance__label"><input type="checkbox" name="needs_receipt" value="1" checked> Current cycle receipt required</label><p class="description">Use the receipt queue to see which recurring items are missing the current cycle receipt.</p></div>';
    echo '<div></div>';
    echo '</div>';

    echo '<div class="eum-finance__picker-grid">';
    echo '<div><label class="eum-finance__label">Bill to Job Path</label>';
    echo EUM_Profiles::render_job_path_directory_picker('job_path_term_id', 0, $viewer_user_id, [
      'required' => true,
      'placeholder' => '— Select a billed job path —',
      'enforce_manageable_job_paths' => !$is_finance,
    ]);
    echo '</div>';

    echo '<div><label class="eum-finance__label">Finance Path / Code</label>';
    echo EUM_Profiles::render_taxonomy_directory_picker(self::TAX_FINANCE_PATH, 'finance_path_term_id', 0, $viewer_user_id, [
      'placeholder' => '— Select a finance path —',
      'none_label' => 'No finance path',
    ]);
    echo '</div>';
    echo '</div>';

    echo '<div><label class="eum-finance__label">Notes</label><textarea name="notes" rows="4" class="large-text" placeholder="Optional details or hand-off notes for the finance team"></textarea></div>';
    echo '<div class="eum-finance__actions"><button type="submit" class="button button-primary">' . esc_html($is_finance ? 'Add Active Recurring Item' : 'Submit Recurring Item') . '</button></div>';
    echo '</form>';
    return ob_get_clean();
  }

  private function render_pending_approvals(int $viewer_user_id): string {
    $records = $this->pending_records_for_user($viewer_user_id);
    $recurring = $this->pending_recurring_for_user($viewer_user_id);
    ob_start();
    if (empty($records) && empty($recurring)) {
      echo '<div class="eum-finance__empty">No finance approvals are waiting on you right now.</div>';
      return ob_get_clean();
    }

    if (!empty($records)) {
      echo '<h4 class="eum-finance__subheading">Pending Records</h4>';
      echo '<div class="eum-finance__table-wrap"><table class="widefat striped"><thead><tr><th>Title</th><th>Amount</th><th>Path</th><th>Submitted By</th><th>Approvers</th><th></th></tr></thead><tbody>';
      foreach ($records as $row) {
        echo '<tr>';
        echo '<td><strong>' . esc_html($row['title']) . '</strong><div class="description">' . esc_html(ucfirst($row['kind'])) . ' · ' . esc_html($row['occurred_on']) . '</div></td>';
        echo '<td>' . esc_html($this->format_money((float)$row['amount'], (string)$row['currency'])) . '</td>';
        echo '<td>' . esc_html($this->job_path_label((int)$row['job_path_term_id'])) . '</td>';
        echo '<td>' . esc_html($this->user_label((int)$row['submitted_by'])) . '</td>';
        echo '<td>' . esc_html(implode(', ', array_filter(array_map([$this, 'user_label'], (array)$row['approver_ids'])))) . '</td>';
        echo '<td>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('eum_finance_approve_record_' . (int)$row['ID'], 'eum_finance_approve_nonce');
        echo '<input type="hidden" name="action" value="eum_finance_approve_record">';
        echo '<input type="hidden" name="record_id" value="' . (int)$row['ID'] . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
        echo '<button type="submit" class="button button-primary">Approve</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
      }
      echo '</tbody></table></div>';
    }

    if (!empty($recurring)) {
      echo '<h4 class="eum-finance__subheading">Pending Recurring Items</h4>';
      echo '<div class="eum-finance__table-wrap"><table class="widefat striped"><thead><tr><th>Template</th><th>Amount</th><th>Frequency</th><th>Path</th><th></th></tr></thead><tbody>';
      foreach ($recurring as $row) {
        echo '<tr>';
        echo '<td><strong>' . esc_html($row['title']) . '</strong></td>';
        echo '<td>' . esc_html($this->format_money((float)$row['amount'], (string)$row['currency'])) . '</td>';
        echo '<td>' . esc_html($this->frequency_label((string)$row['frequency'])) . '</td>';
        echo '<td>' . esc_html($this->job_path_label((int)$row['job_path_term_id'])) . '</td>';
        echo '<td>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('eum_finance_approve_recurring_' . (int)$row['ID'], 'eum_finance_recurring_approve_nonce');
        echo '<input type="hidden" name="action" value="eum_finance_approve_recurring">';
        echo '<input type="hidden" name="recurring_id" value="' . (int)$row['ID'] . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
        echo '<button type="submit" class="button button-primary">Approve</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
      }
      echo '</tbody></table></div>';
    }

    return ob_get_clean();
  }

  private function render_records_table(int $viewer_user_id): string {
    $records = $this->visible_records_for_user($viewer_user_id);
    $records = array_slice($records, 0, 60);
    ob_start();
    if (empty($records)) {
      echo '<div class="eum-finance__empty">No finance records are visible yet.</div>';
      return ob_get_clean();
    }

    echo '<div class="eum-finance__table-wrap"><table class="widefat striped"><thead><tr>';
    echo '<th>Title</th><th>Amount</th><th>Job Path</th><th>Finance Path</th><th>Receipt</th><th>Status</th><th>Submitted By</th>';
    echo '</tr></thead><tbody>';
    foreach ($records as $row) {
      $receipt_url = $row['receipt_id'] > 0 ? wp_get_attachment_url((int)$row['receipt_id']) : '';
      echo '<tr>';
      echo '<td><strong>' . esc_html($row['title']) . '</strong><div class="description">' . esc_html(ucfirst($row['kind'])) . ' · ' . esc_html($row['occurred_on']) . '</div></td>';
      echo '<td>' . esc_html($this->format_money((float)$row['amount'], (string)$row['currency'])) . '</td>';
      echo '<td>' . esc_html($this->job_path_label((int)$row['job_path_term_id'])) . '</td>';
      echo '<td>' . esc_html($this->finance_path_label((int)$row['finance_path_term_id'])) . '</td>';
      echo '<td>' . ($receipt_url ? '<a href="' . esc_url($receipt_url) . '" target="_blank" rel="noopener">View receipt</a>' : '<span class="description">Missing</span>') . '</td>';
      echo '<td><span class="eum-finance__status eum-finance__status--' . esc_attr($row['status']) . '">' . esc_html(ucfirst($row['status'])) . '</span></td>';
      echo '<td>' . esc_html($this->user_label((int)$row['submitted_by'])) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
  }

  private function render_rollup_report(int $viewer_user_id): string {
    $report = $this->job_path_rollup_for_user($viewer_user_id);
    ob_start();
    if (empty($report)) {
      echo '<div class="eum-finance__empty">No approved finance activity is available for rollup yet.</div>';
      return ob_get_clean();
    }
    echo '<div class="eum-finance__table-wrap"><table class="widefat striped"><thead><tr><th>Job Path</th><th>Direct Expense</th><th>Direct Income</th><th>Inherited / Rollup Expense</th><th>Inherited / Rollup Income</th><th>Net Rollup</th></tr></thead><tbody>';
    foreach ($report as $row) {
      echo '<tr>';
      echo '<td>' . esc_html($row['label']) . '</td>';
      echo '<td>' . esc_html($this->format_money((float)$row['direct_expense'])) . '</td>';
      echo '<td>' . esc_html($this->format_money((float)$row['direct_income'])) . '</td>';
      echo '<td>' . esc_html($this->format_money((float)$row['rollup_expense'])) . '</td>';
      echo '<td>' . esc_html($this->format_money((float)$row['rollup_income'])) . '</td>';
      echo '<td>' . esc_html($this->format_money((float)($row['rollup_income'] - $row['rollup_expense']))) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
  }

  private function render_path_cost_dashboard(int $viewer_user_id): string {
    [$selected_term_id, $include_descendants, $options] = $this->dashboard_scope_for_user($viewer_user_id);
    $current_url = $this->current_url();
    $scope_label = $selected_term_id > 0 ? $this->job_path_label($selected_term_id) : 'All visible job paths';

    ob_start();
    echo '<form method="get" class="eum-finance__form" style="margin-bottom:16px;">';
    foreach ($_GET as $key => $value) {
      if (!is_scalar($value)) continue;
      $key = sanitize_key((string)$key);
      if ($key === '' || in_array($key, ['eum_finance_scope_path', 'eum_finance_scope_desc'], true)) continue;
      echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)$value) . '">';
    }
    echo '<div class="eum-finance__picker-grid">';
    echo '<div><label class="eum-finance__label">Path Scope</label>';
    echo EUM_Profiles::render_job_path_directory_picker('eum_finance_scope_path', $selected_term_id, $viewer_user_id, [
      'required' => false,
      'placeholder' => EUM_Access::is_finance_user($viewer_user_id) ? '— All job paths —' : '— Managed job path —',
      'none_label' => EUM_Access::is_finance_user($viewer_user_id) ? 'All visible job paths' : 'All managed job paths',
      'enforce_manageable_job_paths' => !EUM_Access::is_finance_user($viewer_user_id),
    ]);
    echo '</div>';
    echo '<div><label class="eum-finance__label"><input type="checkbox" name="eum_finance_scope_desc" value="1" ' . checked($include_descendants, true, false) . '> Include child job paths</label><p class="description">Managers can narrow to one managed path or include everything beneath it. Finance users can choose any path.</p></div>';
    echo '</div>';
    echo '<div class="eum-finance__actions"><button type="submit" class="button">Apply Path Filter</button><a class="button" href="' . esc_url(remove_query_arg(['eum_finance_scope_path', 'eum_finance_scope_desc'], $current_url)) . '">Reset</a></div>';
    echo '</form>';

    try {
      $summary = $this->dashboard_cost_summary($viewer_user_id, $selected_term_id, $include_descendants);
      echo '<p class="description" style="margin:0 0 12px;">Showing totals for <strong>' . esc_html($scope_label) . '</strong>' . ($include_descendants && $selected_term_id > 0 ? ' and child job paths.' : '.') . '</p>';
      echo '<div class="eum-finance__grid eum-finance__grid--cards">';
      echo $this->metric_card('Current Week Total', $this->format_money($summary['current_week_total']));
      echo $this->metric_card('Current Week Timecard Labor', $this->format_money($summary['current_week_timecard_labor']));
      echo $this->metric_card('Current Month Total', $this->format_money($summary['current_month_total']));
      echo $this->metric_card('Upcoming 30-Day Planned', $this->format_money($summary['upcoming_total']));
      echo $this->metric_card('Upcoming Scheduled Labor', $this->format_money($summary['upcoming_scheduled_labor']));
      echo $this->metric_card('Upcoming Recurring / Future', $this->format_money($summary['upcoming_other']));
      if ($selected_term_id > 0 && $summary['configured_budget'] > 0) {
        echo $this->metric_card('Configured Budget', $this->format_money($summary['configured_budget']));
        echo $this->metric_card('Budget Remaining', $this->format_money($summary['configured_budget_remaining']));
      }
      echo '</div>';

      echo '<div class="eum-finance__table-wrap" style="margin-top:14px;"><table class="widefat striped"><thead><tr><th>Bucket</th><th>Amount</th><th>Notes</th></tr></thead><tbody>';
      foreach ($summary['rows'] as $row) {
        echo '<tr><td><strong>' . esc_html((string)$row['label']) . '</strong></td><td>' . esc_html($this->format_money((float)$row['amount'])) . '</td><td>' . esc_html((string)$row['notes']) . '</td></tr>';
      }
      echo '</tbody></table></div>';

      if (!empty($summary['paycode_rows'])) {
        echo '<h4 style="margin:16px 0 8px;">Labor Paycodes in Scope</h4>';
        echo '<div class="eum-finance__table-wrap"><table class="widefat striped"><thead><tr><th>Finance Path / Paycode</th><th>Hours</th><th>Labor Cost</th></tr></thead><tbody>';
        foreach ($summary['paycode_rows'] as $row) {
          echo '<tr><td>' . esc_html((string)$row['label']) . '</td><td>' . esc_html(number_format((float)$row['hours'], 2)) . '</td><td>' . esc_html($this->format_money((float)$row['cost'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
      }
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[EUM Finance] Path cost dashboard error: ' . $e->getMessage());
      }
      echo '<div class="eum-finance__empty">The path cost dashboard hit an error while calculating labor and projected costs. The rest of the finance suite is still available.</div>';
      echo '<p class="description">Try narrowing the path filter. This build also includes a safer scoped calculation so future loads should be more reliable.</p>';
    }

    return ob_get_clean();
  }

  private function dashboard_scope_for_user(int $viewer_user_id): array {
    $all_paths = $this->available_dashboard_job_paths($viewer_user_id);
    $requested_term_id = isset($_GET['eum_finance_scope_path']) ? (int)$_GET['eum_finance_scope_path'] : 0;
    $include_descendants = !isset($_GET['eum_finance_scope_desc']) || !empty($_GET['eum_finance_scope_desc']);

    if ($requested_term_id > 0 && !isset($all_paths[$requested_term_id])) {
      $requested_term_id = 0;
    }
    if ($requested_term_id <= 0 && !EUM_Access::is_finance_user($viewer_user_id) && !empty($all_paths)) {
      $requested_term_id = (int)array_key_first($all_paths);
    }
    return [$requested_term_id, $include_descendants, $all_paths];
  }

  private function available_dashboard_job_paths(int $viewer_user_id): array {
    $all = $this->all_job_path_options();
    if (EUM_Access::is_finance_user($viewer_user_id) || EUM_Access::is_it_or_hr($viewer_user_id)) return $all;
    $visible = [];
    foreach ($this->manager_root_job_terms($viewer_user_id) as $root_term_id) {
      $root_term_id = (int)$root_term_id;
      if ($root_term_id <= 0) continue;
      foreach ($all as $term_id => $label) {
        $term_id = (int)$term_id;
        if ($term_id === $root_term_id || term_is_ancestor_of($root_term_id, $term_id, 'eum_job_path')) $visible[$term_id] = $label;
      }
    }
    natcasesort($visible);
    return $visible;
  }


  private function dashboard_scope_user_ids(int $viewer_user_id, int $selected_term_id, bool $include_descendants): array {
    $term_ids = [];
    if ($selected_term_id > 0) {
      $term_ids[] = $selected_term_id;
      if ($include_descendants && taxonomy_exists('eum_job_path')) {
        $descendants = get_term_children($selected_term_id, 'eum_job_path');
        if (!is_wp_error($descendants) && !empty($descendants)) {
          $term_ids = array_merge($term_ids, array_map('intval', $descendants));
        }
      }
    } else {
      $term_ids = array_map('intval', array_keys($this->available_dashboard_job_paths($viewer_user_id)));
      if (empty($term_ids) && EUM_Access::is_finance_user($viewer_user_id)) {
        $term_ids = $this->all_job_path_term_ids();
      }
    }

    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
    if (empty($term_ids)) return [];

    $user_ids = get_objects_in_term($term_ids, 'eum_job_path');
    if (is_wp_error($user_ids) || empty($user_ids)) return [];
    return array_values(array_unique(array_filter(array_map('intval', (array)$user_ids))));
  }

  private function dashboard_cost_summary(int $viewer_user_id, int $selected_term_id, bool $include_descendants): array {
    $cache_key = 'eum_finance_dash_' . md5(implode('|', [$viewer_user_id, $selected_term_id, $include_descendants ? '1' : '0', current_time('Y-m-d-H')]));
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['rows'])) return $cached;

    $now_ts = current_time('timestamp');
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone((string)get_option('timezone_string') ?: 'UTC');
    $now = (new DateTimeImmutable('@' . $now_ts))->setTimezone($tz);
    $week_start = $now->modify('Sunday this week')->setTime(0, 0, 0);
    $week_end = $week_start->modify('+7 days');
    $month_start = $now->modify('first day of this month')->setTime(0, 0, 0);
    $month_end = $month_start->modify('first day of next month');
    $upcoming_start = $now->setTime(0, 0, 0);
    $upcoming_end = $upcoming_start->modify('+30 days');
    $scope_user_ids = $this->dashboard_scope_user_ids($viewer_user_id, $selected_term_id, $include_descendants);

    $current_week_records = $this->approved_record_total_for_scope($viewer_user_id, $selected_term_id, $include_descendants, $week_start->format('Y-m-d'), $week_end->modify('-1 day')->format('Y-m-d'));
    $current_month_records = $this->approved_record_total_for_scope($viewer_user_id, $selected_term_id, $include_descendants, $month_start->format('Y-m-d'), $month_end->modify('-1 day')->format('Y-m-d'));
    $future_records = $this->approved_record_total_for_scope($viewer_user_id, $selected_term_id, $include_descendants, $upcoming_start->format('Y-m-d'), $upcoming_end->modify('-1 day')->format('Y-m-d'), true);

    $timecard_rows = [];
    $timecard_month_rows = [];
    try {
      $timeclock = class_exists('EUM_Timeclock') ? new EUM_Timeclock() : null;
      if ($timeclock && method_exists($timeclock, 'labor_rows_for_range')) {
        $args = [
          'job_path_term_id' => $selected_term_id,
          'include_descendants' => $include_descendants,
        ];
        if (!empty($scope_user_ids)) $args['user_ids'] = $scope_user_ids;
        $timecard_rows = $timeclock->labor_rows_for_range($week_start->getTimestamp(), $week_end->getTimestamp(), $args);
        $timecard_month_rows = $timeclock->labor_rows_for_range($month_start->getTimestamp(), $month_end->getTimestamp(), $args);
      }
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Finance] Timecard labor summary failed: ' . $e->getMessage());
    }

    $scheduled_rows = [];
    try {
      if (class_exists('EUM_Scheduling')) {
        $args = [
          'job_path_term_id' => $selected_term_id,
          'include_descendants' => $include_descendants,
          'statuses' => ['publish', 'draft'],
        ];
        if (!empty($scope_user_ids)) $args['user_ids'] = $scope_user_ids;
        $scheduled_rows = (new EUM_Scheduling())->labor_rows_for_range($upcoming_start->getTimestamp(), $upcoming_end->getTimestamp(), $args);
      }
    } catch (Throwable $e) {
      if (defined('WP_DEBUG') && WP_DEBUG) error_log('[EUM Finance] Scheduled labor summary failed: ' . $e->getMessage());
    }

    $current_week_timecard_labor = array_sum(array_map(static fn($row) => (float)($row['cost'] ?? 0), $timecard_rows));
    $current_month_timecard_labor = array_sum(array_map(static fn($row) => (float)($row['cost'] ?? 0), $timecard_month_rows));
    $upcoming_scheduled_labor = array_sum(array_map(static fn($row) => (float)($row['cost'] ?? 0), $scheduled_rows));
    $upcoming_recurring = $this->upcoming_recurring_total_for_scope($viewer_user_id, $selected_term_id, $include_descendants, $upcoming_start->format('Y-m-d'), $upcoming_end->modify('-1 day')->format('Y-m-d'));
    $approved_expense_total = $this->approved_expense_total_for_scope($viewer_user_id, $selected_term_id, $include_descendants);
    $configured_budget = $selected_term_id > 0 ? self::job_path_budget($selected_term_id) : 0.0;
    $configured_budget_remaining = $configured_budget - $approved_expense_total;

    $current_week_total = $current_week_records + $current_week_timecard_labor;
    $current_month_total = $current_month_records + $current_month_timecard_labor;
    $upcoming_other = $future_records + $upcoming_recurring;
    $upcoming_total = $upcoming_scheduled_labor + $upcoming_other;

    $paycodes = [];
    foreach (array_merge($timecard_rows, $scheduled_rows) as $row) {
      $paycode_id = (int)($row['finance_path_term_id'] ?? 0);
      if ($paycode_id <= 0) continue;
      if (!isset($paycodes[$paycode_id])) {
        $paycodes[$paycode_id] = ['label' => self::finance_path_option_label($paycode_id), 'hours' => 0.0, 'cost' => 0.0];
      }
      $paycodes[$paycode_id]['hours'] += (float)($row['hours'] ?? 0);
      $paycodes[$paycode_id]['cost'] += (float)($row['cost'] ?? 0);
    }
    uasort($paycodes, static fn($a, $b) => strcasecmp((string)$a['label'], (string)$b['label']));

    $summary = [
      'current_week_total' => $current_week_total,
      'current_week_timecard_labor' => $current_week_timecard_labor,
      'current_month_total' => $current_month_total,
      'upcoming_total' => $upcoming_total,
      'upcoming_scheduled_labor' => $upcoming_scheduled_labor,
      'upcoming_other' => $upcoming_other,
      'approved_expense_total' => $approved_expense_total,
      'configured_budget' => $configured_budget,
      'configured_budget_remaining' => $configured_budget_remaining,
      'rows' => [
        ['label' => 'Approved non-labor this week', 'amount' => $current_week_records, 'notes' => 'Approved finance records in the current Sunday–Saturday week for the selected path scope.'],
        ['label' => 'Timecard labor this week', 'amount' => $current_week_timecard_labor, 'notes' => 'Actual worked labor from timecards in the same path scope.'],
        ['label' => 'Approved non-labor this month', 'amount' => $current_month_records, 'notes' => 'Approved finance records in the current calendar month.'],
        ['label' => 'Timecard labor this month', 'amount' => $current_month_timecard_labor, 'notes' => 'Actual worked labor from timecards in the current calendar month.'],
        ['label' => 'Upcoming scheduled labor (30 days)', 'amount' => $upcoming_scheduled_labor, 'notes' => 'Published and draft scheduled work in the next 30 days.'],
        ['label' => 'Upcoming recurring templates (30 days)', 'amount' => $upcoming_recurring, 'notes' => 'Active recurring items expected to hit in the next 30 days.'],
        ['label' => 'Future-dated approved records (30 days)', 'amount' => $future_records, 'notes' => 'Approved records already dated into the next 30 days.'],
        ['label' => 'Approved expense spend to date', 'amount' => $approved_expense_total, 'notes' => 'Approved expense records already booked to the selected path scope.'],
      ],
      'paycode_rows' => array_values($paycodes),
    ];

    set_transient($cache_key, $summary, 5 * MINUTE_IN_SECONDS);
    return $summary;
  }

  private function approved_record_total_for_scope(int $viewer_user_id, int $selected_term_id, bool $include_descendants, string $start_date, string $end_date, bool $future_only = false): float {
    $total = 0.0;
    foreach ($this->visible_records_for_user($viewer_user_id) as $row) {
      if ((string)$row['status'] !== 'approved') continue;
      if (!$this->job_path_matches_scope((int)$row['job_path_term_id'], $selected_term_id, $include_descendants)) continue;
      $date = (string)($row['occurred_on'] ?? '');
      if ($date === '' || $date < $start_date || $date > $end_date) continue;
      if ($future_only && $date < current_time('Y-m-d')) continue;
      $kind = (string)($row['kind'] ?? 'expense');
      $amount = (float)($row['amount'] ?? 0);
      $total += ($kind === 'income') ? (-1 * $amount) : $amount;
    }
    return $total;
  }

  private function approved_expense_total_for_scope(int $viewer_user_id, int $selected_term_id, bool $include_descendants): float {
    $total = 0.0;
    foreach ($this->visible_records_for_user($viewer_user_id) as $row) {
      if ((string)$row['status'] !== 'approved') continue;
      if ((string)($row['kind'] ?? 'expense') === 'income') continue;
      if (!$this->job_path_matches_scope((int)$row['job_path_term_id'], $selected_term_id, $include_descendants)) continue;
      $total += (float)($row['amount'] ?? 0);
    }
    return $total;
  }

  private function upcoming_recurring_total_for_scope(int $viewer_user_id, int $selected_term_id, bool $include_descendants, string $start_date, string $end_date): float {
    $total = 0.0;
    foreach ($this->visible_recurring_templates_for_user($viewer_user_id) as $row) {
      if ((string)$row['status'] !== 'active') continue;
      if (!$this->job_path_matches_scope((int)$row['job_path_term_id'], $selected_term_id, $include_descendants)) continue;
      $occurrences = $this->template_occurrence_count_between($row, $start_date, $end_date);
      if ($occurrences <= 0) continue;
      $amount = (float)($row['amount'] ?? 0) * $occurrences;
      $kind = (string)($row['kind'] ?? 'expense');
      $total += ($kind === 'income') ? (-1 * $amount) : $amount;
    }
    return $total;
  }

  private function template_occurrence_count_between(array $row, string $start_date, string $end_date): int {
    $cursor = (string)($row['start_on'] ?? '');
    $frequency = (string)($row['frequency'] ?? 'monthly');
    $hard_end = (string)($row['end_on'] ?? '');
    if ($cursor === '' || $end_date < $cursor) return 0;
    $count = 0;
    $safe = 0;
    while ($safe < 1000 && $cursor !== '' && $cursor <= $end_date) {
      $safe++;
      if ($cursor >= $start_date && ($hard_end === '' || $cursor <= $hard_end)) $count++;
      $next = $this->next_cycle_start($cursor, $frequency);
      if ($next === '' || $next === $cursor) break;
      $cursor = $next;
      if ($hard_end !== '' && $cursor > $hard_end) break;
    }
    return $count;
  }

  private function job_path_matches_scope(int $job_path_term_id, int $selected_term_id, bool $include_descendants): bool {
    if ($job_path_term_id <= 0) return false;
    if ($selected_term_id <= 0) return true;
    if ($job_path_term_id === $selected_term_id) return true;
    return $include_descendants ? term_is_ancestor_of($selected_term_id, $job_path_term_id, 'eum_job_path') : false;
  }

  private function render_api_settings_form(): string {
    $settings = $this->api_settings();
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-finance__form">';
    wp_nonce_field('eum_finance_save_api_settings', 'eum_finance_api_nonce');
    echo '<input type="hidden" name="action" value="eum_finance_save_api_settings">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($this->current_url()) . '">';
    echo '<div class="eum-finance__field-grid">';
    echo $this->text_field('endpoint_base', 'External Endpoint Base', (string)($settings['endpoint_base'] ?? ''), false, 'Optional URL for future finance syncs');
    echo $this->text_field('api_key_label', 'Credential Label', (string)($settings['api_key_label'] ?? ''), false, 'Human-readable note only');
    echo $this->text_field('external_source_name', 'Source Name', (string)($settings['external_source_name'] ?? ''), false, 'Examples: QuickBooks, Stripe, Payroll, ERP');
    echo '<div><label class="eum-finance__label"><input type="checkbox" name="enable_hooks" value="1" ' . checked(!empty($settings['enable_hooks']), true, false) . '> Enable outbound hook payloads</label><p class="description">The finance suite already emits <code>eum_finance_record_saved</code>, <code>eum_finance_record_approved</code>, <code>eum_finance_recurring_saved</code>, and related filters for future bridge plugins or API integrations.</p></div>';
    echo '</div>';
    echo '<div class="eum-finance__actions"><button type="submit" class="button button-primary">Save API Settings</button></div>';
    echo '</form>';
    return ob_get_clean();
  }

  private function metric_card(string $label, string $value): string {
    return '<div class="eum-finance__metric"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
  }

  public function handle_save_record(): void {
    $actor_user_id = (int)get_current_user_id();
    if ($actor_user_id <= 0 || !$this->current_user_can_use_suite($actor_user_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'You are not allowed to create finance records.', 'error');
    }
    if (!isset($_POST['eum_finance_nonce']) || !wp_verify_nonce((string)$_POST['eum_finance_nonce'], 'eum_finance_save_record')) {
      $this->redirect_with_notice($this->posted_return_url(), 'Finance record security check failed.', 'error');
    }

    $is_finance = EUM_Access::is_finance_user($actor_user_id);
    $title = sanitize_text_field((string)($_POST['title'] ?? ''));
    $kind = sanitize_key((string)($_POST['kind'] ?? 'expense'));
    if (!in_array($kind, ['expense', 'income', 'other'], true)) $kind = 'expense';
    $amount = round((float)wp_unslash((string)($_POST['amount'] ?? '0')), 2);
    $currency = strtoupper(substr(sanitize_text_field((string)($_POST['currency'] ?? 'USD')), 0, 8));
    $occurred_on = sanitize_text_field((string)($_POST['occurred_on'] ?? current_time('Y-m-d')));
    $vendor = sanitize_text_field((string)($_POST['vendor'] ?? ''));
    $notes = sanitize_textarea_field((string)($_POST['notes'] ?? ''));
    $job_path_term_id = (int)($_POST['job_path_term_id'] ?? 0);
    $finance_path_term_id = (int)($_POST['finance_path_term_id'] ?? 0);
    $recurring_id = (int)($_POST['recurring_id'] ?? 0);
    $needs_receipt = !empty($_POST['needs_receipt']);

    if ($title === '' || $amount <= 0 || $job_path_term_id <= 0) {
      $this->redirect_with_notice($this->posted_return_url(), 'Title, positive amount, and billed job path are required.', 'error');
    }
    if (!$this->user_can_bill_job_path($actor_user_id, $job_path_term_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'You can only bill finance records to job paths that you manage.', 'error');
    }
    if (!$is_finance && strtotime($occurred_on . ' 00:00:00') < strtotime(current_time('Y-m-d') . ' 00:00:00')) {
      $this->redirect_with_notice($this->posted_return_url(), 'Only finance users can add retroactive expenses or income.', 'error');
    }
    if ($finance_path_term_id > 0 && !term_exists($finance_path_term_id, self::TAX_FINANCE_PATH)) {
      $this->redirect_with_notice($this->posted_return_url(), 'Please choose a valid finance path.', 'error');
    }

    $post_id = wp_insert_post([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $actor_user_id,
    ], true);
    if (is_wp_error($post_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'Could not create the finance record.', 'error');
    }
    $post_id = (int)$post_id;

    $status = $is_finance ? 'approved' : 'pending';
    $approver_ids = $is_finance ? [] : $this->finance_approver_ids_for_actor($actor_user_id);

    update_post_meta($post_id, self::META_KIND, $kind);
    update_post_meta($post_id, self::META_AMOUNT, $amount);
    update_post_meta($post_id, self::META_CURRENCY, $currency ?: 'USD');
    update_post_meta($post_id, self::META_OCCURRED_ON, $occurred_on);
    update_post_meta($post_id, self::META_JOB_PATH_TERM_ID, $job_path_term_id);
    update_post_meta($post_id, self::META_FINANCE_PATH_TERM_ID, $finance_path_term_id);
    update_post_meta($post_id, self::META_STATUS, $status);
    update_post_meta($post_id, self::META_APPROVER_IDS, $approver_ids);
    update_post_meta($post_id, self::META_SUBMITTED_BY, $actor_user_id);
    update_post_meta($post_id, self::META_VENDOR, $vendor);
    update_post_meta($post_id, self::META_NOTES, $notes);
    update_post_meta($post_id, self::META_RECURRING_ID, $recurring_id);
    update_post_meta($post_id, self::META_NEEDS_RECEIPT, $needs_receipt ? '1' : '0');
    update_post_meta($post_id, self::META_EXTERNAL_ID, sanitize_text_field((string)($_POST['external_id'] ?? '')));
    if ($is_finance) {
      update_post_meta($post_id, self::META_APPROVED_BY, $actor_user_id);
      update_post_meta($post_id, self::META_APPROVED_AT, current_time('mysql'));
    }

    $receipt_id = $this->handle_receipt_upload('receipt_file', $post_id, $actor_user_id);
    if ($receipt_id > 0) {
      update_post_meta($post_id, self::META_RECEIPT_ID, $receipt_id);
    }

    do_action('eum_finance_record_saved', $post_id, $this->record_payload($post_id));
    if ($status === 'approved') {
      do_action('eum_finance_record_approved', $post_id, $this->record_payload($post_id));
    }

    $message = $is_finance ? 'Finance record added and approved.' : 'Finance record submitted for approval.';
    $this->redirect_with_notice($this->posted_return_url(), $message, 'success');
  }

  public function handle_approve_record(): void {
    $viewer_user_id = (int)get_current_user_id();
    $record_id = (int)($_POST['record_id'] ?? 0);
    if ($record_id <= 0 || get_post_type($record_id) !== self::CPT_RECORD) {
      $this->redirect_with_notice($this->posted_return_url(), 'Finance record not found.', 'error');
    }
    if (!isset($_POST['eum_finance_approve_nonce']) || !wp_verify_nonce((string)$_POST['eum_finance_approve_nonce'], 'eum_finance_approve_record_' . $record_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'Finance approval security check failed.', 'error');
    }
    if (!$this->user_can_approve_record($viewer_user_id, $record_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'You are not allowed to approve this finance record.', 'error');
    }

    update_post_meta($record_id, self::META_STATUS, 'approved');
    update_post_meta($record_id, self::META_APPROVED_BY, $viewer_user_id);
    update_post_meta($record_id, self::META_APPROVED_AT, current_time('mysql'));
    do_action('eum_finance_record_approved', $record_id, $this->record_payload($record_id));

    $this->redirect_with_notice($this->posted_return_url(), 'Finance record approved.', 'success');
  }

  public function handle_save_recurring(): void {
    $actor_user_id = (int)get_current_user_id();
    if ($actor_user_id <= 0 || !$this->current_user_can_use_suite($actor_user_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'You are not allowed to create recurring finance items.', 'error');
    }
    if (!isset($_POST['eum_finance_recurring_nonce']) || !wp_verify_nonce((string)$_POST['eum_finance_recurring_nonce'], 'eum_finance_save_recurring')) {
      $this->redirect_with_notice($this->posted_return_url(), 'Recurring finance security check failed.', 'error');
    }

    $is_finance = EUM_Access::is_finance_user($actor_user_id);
    $title = sanitize_text_field((string)($_POST['title'] ?? ''));
    $kind = sanitize_key((string)($_POST['kind'] ?? 'expense'));
    if (!in_array($kind, ['expense', 'income', 'other'], true)) $kind = 'expense';
    $amount = round((float)wp_unslash((string)($_POST['amount'] ?? '0')), 2);
    $currency = strtoupper(substr(sanitize_text_field((string)($_POST['currency'] ?? 'USD')), 0, 8));
    $frequency = sanitize_key((string)($_POST['frequency'] ?? 'monthly'));
    if (!isset($this->frequency_options()[$frequency])) $frequency = 'monthly';
    $vendor = sanitize_text_field((string)($_POST['vendor'] ?? ''));
    $notes = sanitize_textarea_field((string)($_POST['notes'] ?? ''));
    $start_on = sanitize_text_field((string)($_POST['start_on'] ?? current_time('Y-m-d')));
    $end_on = sanitize_text_field((string)($_POST['end_on'] ?? ''));
    $job_path_term_id = (int)($_POST['job_path_term_id'] ?? 0);
    $finance_path_term_id = (int)($_POST['finance_path_term_id'] ?? 0);
    $needs_receipt = !empty($_POST['needs_receipt']);

    if ($title === '' || $amount <= 0 || $job_path_term_id <= 0) {
      $this->redirect_with_notice($this->posted_return_url(), 'Template name, positive amount, and billed job path are required.', 'error');
    }
    if (!$this->user_can_bill_job_path($actor_user_id, $job_path_term_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'You can only bill recurring items to job paths that you manage.', 'error');
    }

    $post_id = wp_insert_post([
      'post_type' => self::CPT_RECURRING,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $actor_user_id,
    ], true);
    if (is_wp_error($post_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'Could not create the recurring finance item.', 'error');
    }
    $post_id = (int)$post_id;

    $status = $is_finance ? 'active' : 'pending';
    $approver_ids = $is_finance ? [] : $this->finance_approver_ids_for_actor($actor_user_id);

    update_post_meta($post_id, self::META_REC_KIND, $kind);
    update_post_meta($post_id, self::META_REC_AMOUNT, $amount);
    update_post_meta($post_id, self::META_REC_CURRENCY, $currency ?: 'USD');
    update_post_meta($post_id, self::META_REC_FREQ, $frequency);
    update_post_meta($post_id, self::META_REC_VENDOR, $vendor);
    update_post_meta($post_id, self::META_REC_NOTES, $notes);
    update_post_meta($post_id, self::META_REC_START, $start_on);
    update_post_meta($post_id, self::META_REC_END, $end_on);
    update_post_meta($post_id, self::META_REC_JOB_PATH_TERM_ID, $job_path_term_id);
    update_post_meta($post_id, self::META_REC_FINANCE_PATH_TERM_ID, $finance_path_term_id);
    update_post_meta($post_id, self::META_REC_STATUS, $status);
    update_post_meta($post_id, self::META_REC_APPROVER_IDS, $approver_ids);
    update_post_meta($post_id, self::META_REC_SUBMITTED_BY, $actor_user_id);
    update_post_meta($post_id, self::META_REC_EXTERNAL_ID, sanitize_text_field((string)($_POST['external_id'] ?? '')));
    update_post_meta($post_id, self::META_REC_NEEDS_RECEIPT, $needs_receipt ? '1' : '0');
    if ($is_finance) {
      update_post_meta($post_id, self::META_REC_APPROVED_BY, $actor_user_id);
      update_post_meta($post_id, self::META_REC_APPROVED_AT, current_time('mysql'));
    }

    do_action('eum_finance_recurring_saved', $post_id, $this->recurring_payload($post_id));
    if ($status === 'active') {
      do_action('eum_finance_recurring_approved', $post_id, $this->recurring_payload($post_id));
    }

    $this->redirect_with_notice($this->posted_return_url(), $is_finance ? 'Recurring item added and activated.' : 'Recurring item submitted for approval.', 'success');
  }

  public function handle_approve_recurring(): void {
    $viewer_user_id = (int)get_current_user_id();
    $recurring_id = (int)($_POST['recurring_id'] ?? 0);
    if ($recurring_id <= 0 || get_post_type($recurring_id) !== self::CPT_RECURRING) {
      $this->redirect_with_notice($this->posted_return_url(), 'Recurring finance item not found.', 'error');
    }
    if (!isset($_POST['eum_finance_recurring_approve_nonce']) || !wp_verify_nonce((string)$_POST['eum_finance_recurring_approve_nonce'], 'eum_finance_approve_recurring_' . $recurring_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'Recurring finance approval security check failed.', 'error');
    }
    if (!$this->user_can_approve_recurring($viewer_user_id, $recurring_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'You are not allowed to approve this recurring item.', 'error');
    }

    update_post_meta($recurring_id, self::META_REC_STATUS, 'active');
    update_post_meta($recurring_id, self::META_REC_APPROVED_BY, $viewer_user_id);
    update_post_meta($recurring_id, self::META_REC_APPROVED_AT, current_time('mysql'));
    do_action('eum_finance_recurring_approved', $recurring_id, $this->recurring_payload($recurring_id));

    $this->redirect_with_notice($this->posted_return_url(), 'Recurring finance item approved.', 'success');
  }

  public function handle_save_api_settings(): void {
    $viewer_user_id = (int)get_current_user_id();
    if (!EUM_Access::is_finance_user($viewer_user_id)) {
      $this->redirect_with_notice($this->posted_return_url(), 'Only finance users can change finance API settings.', 'error');
    }
    if (!isset($_POST['eum_finance_api_nonce']) || !wp_verify_nonce((string)$_POST['eum_finance_api_nonce'], 'eum_finance_save_api_settings')) {
      $this->redirect_with_notice($this->posted_return_url(), 'Finance API settings security check failed.', 'error');
    }
    $settings = [
      'endpoint_base' => esc_url_raw((string)($_POST['endpoint_base'] ?? '')),
      'api_key_label' => sanitize_text_field((string)($_POST['api_key_label'] ?? '')),
      'external_source_name' => sanitize_text_field((string)($_POST['external_source_name'] ?? '')),
      'enable_hooks' => !empty($_POST['enable_hooks']) ? 1 : 0,
    ];
    update_option(self::OPTION_API_SETTINGS, $settings, false);
    $this->redirect_with_notice($this->posted_return_url(), 'Finance API settings saved.', 'success');
  }

  public function render_finance_path_add_fields(): void {
    if (!$this->current_user_can_admin_finance()) return;
    $job_paths = $this->all_job_path_options();
    echo '<div class="form-field"><label for="eum_finance_linked_job_path">Linked Job Path</label>';
    echo '<select name="eum_finance_linked_job_path" id="eum_finance_linked_job_path"><option value="0">— None —</option>';
    foreach ($job_paths as $term_id => $label) {
      echo '<option value="' . (int)$term_id . '">' . esc_html($label) . '</option>';
    }
    echo '</select><p>Optional. Use this when the finance path starts under a job path and then continues with finance-only children.</p></div>';

    echo '<div class="form-field"><label for="eum_finance_external_code">External Code ID</label><input type="text" name="eum_finance_external_code" id="eum_finance_external_code" value=""><p>Optional ID for later API/paycode sync work.</p></div>';
    echo '<div class="form-field"><label for="eum_finance_budget">Budget</label><input type="number" step="0.01" min="0" name="eum_finance_budget" id="eum_finance_budget" value=""><p>Optional budget for this cost center / finance path.</p></div>';
    echo '<div class="form-field"><label><input type="checkbox" name="eum_finance_labor_paycode_enabled" value="1"> Labor paycode option</label><p>Leaves room for future timecard and scheduling billing codes.</p></div>';
  }

  public function render_finance_path_edit_fields($term): void {
    if (!$this->current_user_can_admin_finance()) return;
    $linked_job_path = (int)get_term_meta((int)$term->term_id, self::TERM_META_LINKED_JOB_PATH, true);
    $external_code = (string)get_term_meta((int)$term->term_id, self::TERM_META_EXTERNAL_CODE, true);
    $labor_paycode = !empty(get_term_meta((int)$term->term_id, self::TERM_META_LABOR_PAYCODE, true));
    $budget = self::finance_path_budget((int)$term->term_id);
    $job_paths = $this->all_job_path_options();
    echo '<tr class="form-field"><th scope="row"><label for="eum_finance_linked_job_path">Linked Job Path</label></th><td>';
    echo '<select name="eum_finance_linked_job_path" id="eum_finance_linked_job_path"><option value="0">— None —</option>';
    foreach ($job_paths as $term_id => $label) {
      echo '<option value="' . (int)$term_id . '" ' . selected($linked_job_path, (int)$term_id, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select><p class="description">Optional root job path for this finance branch.</p></td></tr>';

    echo '<tr class="form-field"><th scope="row"><label for="eum_finance_external_code">External Code ID</label></th><td><input type="text" name="eum_finance_external_code" id="eum_finance_external_code" value="' . esc_attr($external_code) . '" class="regular-text"><p class="description">Optional code used for future API or paycode mappings.</p></td></tr>';
    echo '<tr class="form-field"><th scope="row"><label for="eum_finance_budget">Budget</label></th><td><input type="number" step="0.01" min="0" name="eum_finance_budget" id="eum_finance_budget" value="' . esc_attr($budget > 0 ? number_format($budget, 2, '.', '') : '') . '" class="regular-text"><p class="description">Optional budget for this cost center / finance path.</p></td></tr>';
    echo '<tr class="form-field"><th scope="row">Labor paycode option</th><td><label><input type="checkbox" name="eum_finance_labor_paycode_enabled" value="1" ' . checked($labor_paycode, true, false) . '> Treat this finance path as available for future labor/paycode billing</label></td></tr>';
  }

  public function save_finance_path_fields(int $term_id): void {
    if (!$this->current_user_can_admin_finance()) return;
    update_term_meta($term_id, self::TERM_META_LINKED_JOB_PATH, (int)($_POST['eum_finance_linked_job_path'] ?? 0));
    update_term_meta($term_id, self::TERM_META_EXTERNAL_CODE, sanitize_text_field((string)($_POST['eum_finance_external_code'] ?? '')));
    update_term_meta($term_id, self::TERM_META_LABOR_PAYCODE, !empty($_POST['eum_finance_labor_paycode_enabled']) ? '1' : '0');
    update_term_meta($term_id, self::TERM_META_BUDGET, round((float)($_POST['eum_finance_budget'] ?? 0), 2));
  }

  public function render_job_path_budget_add_fields(): void {
    if (!$this->current_user_can_admin_finance()) return;
    echo '<div class="form-field"><label for="eum_job_path_budget">Budget</label><input type="number" step="0.01" min="0" name="eum_job_path_budget" id="eum_job_path_budget" value=""><p>Optional operating budget for this Job Path.</p></div>';
  }

  public function render_job_path_budget_edit_fields($term): void {
    if (!$this->current_user_can_admin_finance()) return;
    $budget = self::job_path_budget((int)$term->term_id);
    echo '<tr class="form-field"><th scope="row"><label for="eum_job_path_budget">Budget</label></th><td><input type="number" step="0.01" min="0" name="eum_job_path_budget" id="eum_job_path_budget" value="' . esc_attr($budget > 0 ? number_format($budget, 2, '.', '') : '') . '" class="regular-text"><p class="description">Optional operating budget for this Job Path.</p></td></tr>';
  }

  public function save_job_path_budget_fields(int $term_id): void {
    if (!$this->current_user_can_admin_finance()) return;
    update_term_meta($term_id, self::JOB_PATH_TERM_META_BUDGET, round((float)($_POST['eum_job_path_budget'] ?? 0), 2));
  }

  public function register_rest_routes(): void {
    register_rest_route('eum-finance/v1', '/records', [
      [
        'methods' => WP_REST_Server::READABLE,
        'callback' => [$this, 'rest_list_records'],
        'permission_callback' => [$this, 'rest_can_access'],
      ],
      [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'rest_create_record'],
        'permission_callback' => [$this, 'rest_can_access'],
      ],
    ]);

    register_rest_route('eum-finance/v1', '/paths', [
      'methods' => WP_REST_Server::READABLE,
      'callback' => [$this, 'rest_list_paths'],
      'permission_callback' => [$this, 'rest_can_access'],
    ]);
  }

  public function rest_can_access(WP_REST_Request $request): bool {
    $user_id = (int)get_current_user_id();
    return $user_id > 0 && $this->current_user_can_use_suite($user_id);
  }

  public function rest_list_records(WP_REST_Request $request): WP_REST_Response {
    $records = array_slice($this->visible_records_for_user((int)get_current_user_id()), 0, 100);
    return new WP_REST_Response(array_map(function($row) {
      return apply_filters('eum_finance_rest_record_payload', $row, $row['ID']);
    }, $records), 200);
  }

  public function rest_create_record(WP_REST_Request $request): WP_REST_Response {
    $data = $request->get_json_params();
    if (!is_array($data)) $data = [];
    $actor_user_id = (int)get_current_user_id();
    $job_path_term_id = (int)($data['job_path_term_id'] ?? 0);
    if (!$this->user_can_bill_job_path($actor_user_id, $job_path_term_id)) {
      return new WP_REST_Response(['ok' => false, 'message' => 'You cannot bill records to that job path.'], 403);
    }
    $post_id = wp_insert_post([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'post_title' => sanitize_text_field((string)($data['title'] ?? 'API Finance Record')),
      'post_author' => $actor_user_id,
    ], true);
    if (is_wp_error($post_id)) {
      return new WP_REST_Response(['ok' => false, 'message' => 'Could not create finance record.'], 500);
    }
    $post_id = (int)$post_id;
    $status = EUM_Access::is_finance_user($actor_user_id) ? 'approved' : 'pending';
    update_post_meta($post_id, self::META_KIND, sanitize_key((string)($data['kind'] ?? 'expense')));
    update_post_meta($post_id, self::META_AMOUNT, round((float)($data['amount'] ?? 0), 2));
    update_post_meta($post_id, self::META_CURRENCY, strtoupper(substr(sanitize_text_field((string)($data['currency'] ?? 'USD')), 0, 8)));
    update_post_meta($post_id, self::META_OCCURRED_ON, sanitize_text_field((string)($data['occurred_on'] ?? current_time('Y-m-d'))));
    update_post_meta($post_id, self::META_JOB_PATH_TERM_ID, $job_path_term_id);
    update_post_meta($post_id, self::META_FINANCE_PATH_TERM_ID, (int)($data['finance_path_term_id'] ?? 0));
    update_post_meta($post_id, self::META_VENDOR, sanitize_text_field((string)($data['vendor'] ?? '')));
    update_post_meta($post_id, self::META_NOTES, sanitize_textarea_field((string)($data['notes'] ?? '')));
    update_post_meta($post_id, self::META_STATUS, $status);
    update_post_meta($post_id, self::META_SUBMITTED_BY, $actor_user_id);
    update_post_meta($post_id, self::META_APPROVER_IDS, $status === 'approved' ? [] : $this->finance_approver_ids_for_actor($actor_user_id));
    update_post_meta($post_id, self::META_NEEDS_RECEIPT, !empty($data['needs_receipt']) ? '1' : '0');
    update_post_meta($post_id, self::META_EXTERNAL_ID, sanitize_text_field((string)($data['external_id'] ?? '')));
    if ($status === 'approved') {
      update_post_meta($post_id, self::META_APPROVED_BY, $actor_user_id);
      update_post_meta($post_id, self::META_APPROVED_AT, current_time('mysql'));
    }

    do_action('eum_finance_record_saved', $post_id, $this->record_payload($post_id));
    return new WP_REST_Response(['ok' => true, 'record_id' => $post_id, 'status' => $status], 200);
  }

  public function rest_list_paths(WP_REST_Request $request): WP_REST_Response {
    $terms = get_terms(['taxonomy' => self::TAX_FINANCE_PATH, 'hide_empty' => false, 'number' => 0]);
    if (is_wp_error($terms)) $terms = [];
    $payload = [];
    foreach ($terms as $term) {
      $payload[] = [
        'term_id' => (int)$term->term_id,
        'label' => $this->finance_path_label((int)$term->term_id),
        'linked_job_path_term_id' => (int)get_term_meta((int)$term->term_id, self::TERM_META_LINKED_JOB_PATH, true),
        'external_code' => (string)get_term_meta((int)$term->term_id, self::TERM_META_EXTERNAL_CODE, true),
        'budget' => self::finance_path_budget((int)$term->term_id),
        'labor_paycode_enabled' => !empty(get_term_meta((int)$term->term_id, self::TERM_META_LABOR_PAYCODE, true)),
      ];
    }
    return new WP_REST_Response($payload, 200);
  }

  private function finance_stats_for_user(int $user_id): array {
    $records = $this->visible_records_for_user($user_id);
    $approved_expenses = 0.0;
    $approved_income = 0.0;
    foreach ($records as $row) {
      if ($row['status'] !== 'approved') continue;
      if ($row['kind'] === 'income') $approved_income += (float)$row['amount'];
      else $approved_expenses += (float)$row['amount'];
    }
    return [
      'pending_approvals' => count($this->pending_records_for_user($user_id)) + count($this->pending_recurring_for_user($user_id)),
      'missing_receipts' => count($this->recurring_templates_missing_current_receipt($user_id)),
      'approved_expenses' => $approved_expenses,
      'approved_income' => $approved_income,
      'recurring_count' => count($this->visible_recurring_templates_for_user($user_id)),
    ];
  }

  private function visible_records_for_user(int $user_id): array {
    $ids = get_posts([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'numberposts' => 500,
      'fields' => 'ids',
      'orderby' => 'date',
      'order' => 'DESC',
    ]);
    $rows = [];
    foreach ((array)$ids as $post_id) {
      $row = $this->record_payload((int)$post_id);
      if (!$this->user_can_view_record($user_id, $row)) continue;
      $rows[] = $row;
    }
    usort($rows, static function($a, $b) {
      return strcmp((string)$b['occurred_on'], (string)$a['occurred_on']);
    });
    return $rows;
  }

  private function visible_recurring_templates_for_user(int $user_id): array {
    $ids = get_posts([
      'post_type' => self::CPT_RECURRING,
      'post_status' => 'publish',
      'numberposts' => 500,
      'fields' => 'ids',
      'orderby' => 'title',
      'order' => 'ASC',
    ]);
    $rows = [];
    foreach ((array)$ids as $post_id) {
      $row = $this->recurring_payload((int)$post_id);
      if (!$this->user_can_view_recurring($user_id, $row)) continue;
      $rows[] = $row;
    }
    return $rows;
  }

  private function pending_records_for_user(int $user_id): array {
    $rows = [];
    foreach ($this->visible_records_for_user($user_id) as $row) {
      if ($row['status'] !== 'pending') continue;
      if (!$this->user_can_approve_record($user_id, (int)$row['ID'])) continue;
      $rows[] = $row;
    }
    return $rows;
  }

  private function pending_recurring_for_user(int $user_id): array {
    $rows = [];
    foreach ($this->visible_recurring_templates_for_user($user_id) as $row) {
      if ($row['status'] !== 'pending') continue;
      if (!$this->user_can_approve_recurring($user_id, (int)$row['ID'])) continue;
      $rows[] = $row;
    }
    return $rows;
  }

  private function recurring_templates_missing_current_receipt(int $user_id): array {
    $rows = [];
    foreach ($this->visible_recurring_templates_for_user($user_id) as $row) {
      if ($row['status'] !== 'active') continue;
      if (empty($row['needs_receipt'])) continue;
      $cycle = $this->current_cycle_for_template((int)$row['ID']);
      if (empty($cycle)) continue;
      if ($this->template_has_receipt_for_cycle((int)$row['ID'], $cycle['start'], $cycle['end'])) continue;
      $row['cycle_label'] = $this->display_date($cycle['start']) . ' → ' . $this->display_date($cycle['end']);
      $row['frequency_label'] = $this->frequency_label((string)$row['frequency']);
      $rows[] = $row;
    }
    return $rows;
  }

  private function current_cycle_for_template(int $template_id): array {
    $template = $this->get_recurring_template($template_id);
    if (empty($template)) return [];
    $start = $template['start_on'];
    if ($start === '') return [];
    $today = current_time('Y-m-d');
    if ($today < $start) return [];
    if ($template['end_on'] !== '' && $today > $template['end_on']) return [];

    $cursor = $start;
    $safe = 0;
    while ($safe < 500) {
      $safe++;
      $next = $this->next_cycle_start($cursor, (string)$template['frequency']);
      if ($next === '' || $next > $today) {
        $end = date('Y-m-d', strtotime($next !== '' ? ($next . ' -1 day') : $today));
        if ($template['end_on'] !== '' && $end > $template['end_on']) {
          $end = $template['end_on'];
        }
        return ['start' => $cursor, 'end' => $end];
      }
      $cursor = $next;
    }
    return [];
  }

  private function template_has_receipt_for_cycle(int $template_id, string $cycle_start, string $cycle_end): bool {
    foreach ($this->records_for_recurring_template($template_id) as $row) {
      if ((int)$row['receipt_id'] <= 0) continue;
      if ($row['status'] === 'rejected') continue;
      $date = (string)$row['occurred_on'];
      if ($date >= $cycle_start && $date <= $cycle_end) return true;
    }
    return false;
  }

  private function records_for_recurring_template(int $template_id): array {
    $ids = get_posts([
      'post_type' => self::CPT_RECORD,
      'post_status' => 'publish',
      'numberposts' => 100,
      'fields' => 'ids',
      'meta_key' => self::META_RECURRING_ID,
      'meta_value' => $template_id,
    ]);
    $rows = [];
    foreach ((array)$ids as $post_id) {
      $rows[] = $this->record_payload((int)$post_id);
    }
    return $rows;
  }

  private function job_path_rollup_for_user(int $user_id): array {
    $records = $this->visible_records_for_user($user_id);
    $managed_root_terms = EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_or_hr($user_id)
      ? $this->all_job_path_term_ids()
      : $this->manager_root_job_terms($user_id);

    $direct = [];
    $rollup = [];
    foreach ($records as $row) {
      if ($row['status'] !== 'approved') continue;
      $term_id = (int)$row['job_path_term_id'];
      if ($term_id <= 0) continue;
      $bucket = ($row['kind'] === 'income') ? 'income' : 'expense';
      $amount = (float)$row['amount'];
      if (!isset($direct[$term_id])) $direct[$term_id] = ['expense' => 0.0, 'income' => 0.0];
      $direct[$term_id][$bucket] += $amount;

      $chain = array_reverse((array)get_ancestors($term_id, 'eum_job_path'));
      $chain[] = $term_id;
      foreach ($chain as $chain_term_id) {
        $chain_term_id = (int)$chain_term_id;
        if ($chain_term_id <= 0) continue;
        if (!isset($rollup[$chain_term_id])) $rollup[$chain_term_id] = ['expense' => 0.0, 'income' => 0.0];
        $rollup[$chain_term_id][$bucket] += $amount;
      }
    }

    $visible_term_ids = array_unique(array_merge(array_keys($direct), array_keys($rollup)));
    $out = [];
    foreach ($visible_term_ids as $term_id) {
      $term_id = (int)$term_id;
      if ($term_id <= 0) continue;
      if (!$this->job_term_is_visible_in_report($term_id, $managed_root_terms, $user_id)) continue;
      $out[] = [
        'term_id' => $term_id,
        'label' => $this->job_path_label($term_id),
        'direct_expense' => (float)($direct[$term_id]['expense'] ?? 0),
        'direct_income' => (float)($direct[$term_id]['income'] ?? 0),
        'rollup_expense' => (float)($rollup[$term_id]['expense'] ?? 0),
        'rollup_income' => (float)($rollup[$term_id]['income'] ?? 0),
      ];
    }

    usort($out, static function($a, $b) {
      return strcasecmp((string)$a['label'], (string)$b['label']);
    });
    return $out;
  }

  private function job_term_is_visible_in_report(int $term_id, array $managed_root_terms, int $user_id): bool {
    if (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_or_hr($user_id)) return true;
    foreach ($managed_root_terms as $root_term_id) {
      if ($term_id === $root_term_id || term_is_ancestor_of($root_term_id, $term_id, 'eum_job_path')) return true;
    }
    return false;
  }

  private function user_can_view_record(int $user_id, array $row): bool {
    if (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_or_hr($user_id)) return true;
    return $this->user_can_bill_job_path($user_id, (int)$row['job_path_term_id']);
  }

  private function user_can_view_recurring(int $user_id, array $row): bool {
    if (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_or_hr($user_id)) return true;
    return $this->user_can_bill_job_path($user_id, (int)$row['job_path_term_id']);
  }

  private function user_can_approve_record(int $user_id, int $record_id): bool {
    if ($user_id <= 0) return false;
    if (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_or_hr($user_id)) return true;
    $approver_ids = array_values(array_filter(array_map('intval', (array)get_post_meta($record_id, self::META_APPROVER_IDS, true))));
    return in_array($user_id, $approver_ids, true);
  }

  private function user_can_approve_recurring(int $user_id, int $recurring_id): bool {
    if ($user_id <= 0) return false;
    if (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_or_hr($user_id)) return true;
    $approver_ids = array_values(array_filter(array_map('intval', (array)get_post_meta($recurring_id, self::META_REC_APPROVER_IDS, true))));
    return in_array($user_id, $approver_ids, true);
  }

  private function finance_approver_ids_for_actor(int $actor_user_id): array {
    $approver_ids = [];
    if (class_exists('EUM_Manager_IDs')) {
      $approver_ids = array_values(array_filter(array_map('intval', EUM_Manager_IDs::manager_user_ids_for_subject_user($actor_user_id)), static fn($uid) => $uid > 0 && $uid !== $actor_user_id));
    }
    $finance_users = get_users([
      'fields' => 'ids',
      'number' => 5000,
      'meta_key' => EUM_Access::META_FINANCE,
      'meta_value' => '1',
    ]);
    foreach ((array)$finance_users as $uid) {
      $uid = (int)$uid;
      if ($uid > 0 && $uid !== $actor_user_id) $approver_ids[] = $uid;
    }
    $forced_root = EUM_Access::forced_root_user_id();
    if ($forced_root > 0 && $forced_root !== $actor_user_id) $approver_ids[] = $forced_root;
    return array_values(array_unique(array_filter(array_map('intval', $approver_ids))));
  }

  private function user_can_bill_job_path(int $user_id, int $job_path_term_id): bool {
    if ($job_path_term_id <= 0) return false;
    if (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_or_hr($user_id)) return true;
    foreach ($this->manager_root_job_terms($user_id) as $root_term_id) {
      if ($job_path_term_id === $root_term_id || term_is_ancestor_of($root_term_id, $job_path_term_id, 'eum_job_path')) return true;
    }
    return false;
  }

  private function manager_root_job_terms(int $actor_user_id): array {
    if (!class_exists('EUM_Manager_IDs')) return [];
    $posts = get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'fields' => 'ids',
    ]);
    $term_ids = [];
    foreach ((array)$posts as $post_id) {
      $manager_users = EUM_Manager_IDs::manager_users_for_id((int)$post_id);
      if (!in_array($actor_user_id, $manager_users, true)) continue;
      $job_term_id = (int)get_post_meta((int)$post_id, EUM_Manager_IDs::META_JOB_PATH_TERM, true);
      if ($job_term_id > 0) $term_ids[] = $job_term_id;
    }
    return array_values(array_unique($term_ids));
  }

  private function current_user_can_admin_finance(): bool {
    $user_id = (int)get_current_user_id();
    return $user_id > 0 && (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_user($user_id));
  }

  private function current_user_can_use_suite(int $user_id): bool {
    if ($user_id <= 0) return false;
    if (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_or_hr($user_id)) return true;
    if (class_exists('EUM_Access') && method_exists('EUM_Access', 'is_manager_user')) {
      return EUM_Access::is_manager_user($user_id);
    }
    return false;
  }

  private function record_payload(int $post_id): array {
    return [
      'ID' => $post_id,
      'title' => get_the_title($post_id),
      'kind' => (string)get_post_meta($post_id, self::META_KIND, true),
      'amount' => (float)get_post_meta($post_id, self::META_AMOUNT, true),
      'currency' => (string)get_post_meta($post_id, self::META_CURRENCY, true),
      'occurred_on' => (string)get_post_meta($post_id, self::META_OCCURRED_ON, true),
      'job_path_term_id' => (int)get_post_meta($post_id, self::META_JOB_PATH_TERM_ID, true),
      'finance_path_term_id' => (int)get_post_meta($post_id, self::META_FINANCE_PATH_TERM_ID, true),
      'receipt_id' => (int)get_post_meta($post_id, self::META_RECEIPT_ID, true),
      'status' => (string)get_post_meta($post_id, self::META_STATUS, true),
      'approver_ids' => array_values(array_filter(array_map('intval', (array)get_post_meta($post_id, self::META_APPROVER_IDS, true)))),
      'submitted_by' => (int)get_post_meta($post_id, self::META_SUBMITTED_BY, true),
      'approved_by' => (int)get_post_meta($post_id, self::META_APPROVED_BY, true),
      'notes' => (string)get_post_meta($post_id, self::META_NOTES, true),
      'vendor' => (string)get_post_meta($post_id, self::META_VENDOR, true),
      'recurring_id' => (int)get_post_meta($post_id, self::META_RECURRING_ID, true),
      'external_id' => (string)get_post_meta($post_id, self::META_EXTERNAL_ID, true),
      'needs_receipt' => !empty(get_post_meta($post_id, self::META_NEEDS_RECEIPT, true)),
    ];
  }

  private function recurring_payload(int $post_id): array {
    return [
      'ID' => $post_id,
      'title' => get_the_title($post_id),
      'kind' => (string)get_post_meta($post_id, self::META_REC_KIND, true),
      'amount' => (float)get_post_meta($post_id, self::META_REC_AMOUNT, true),
      'currency' => (string)get_post_meta($post_id, self::META_REC_CURRENCY, true),
      'frequency' => (string)get_post_meta($post_id, self::META_REC_FREQ, true),
      'start_on' => (string)get_post_meta($post_id, self::META_REC_START, true),
      'end_on' => (string)get_post_meta($post_id, self::META_REC_END, true),
      'job_path_term_id' => (int)get_post_meta($post_id, self::META_REC_JOB_PATH_TERM_ID, true),
      'finance_path_term_id' => (int)get_post_meta($post_id, self::META_REC_FINANCE_PATH_TERM_ID, true),
      'vendor' => (string)get_post_meta($post_id, self::META_REC_VENDOR, true),
      'notes' => (string)get_post_meta($post_id, self::META_REC_NOTES, true),
      'status' => (string)get_post_meta($post_id, self::META_REC_STATUS, true),
      'approver_ids' => array_values(array_filter(array_map('intval', (array)get_post_meta($post_id, self::META_REC_APPROVER_IDS, true)))),
      'submitted_by' => (int)get_post_meta($post_id, self::META_REC_SUBMITTED_BY, true),
      'approved_by' => (int)get_post_meta($post_id, self::META_REC_APPROVED_BY, true),
      'external_id' => (string)get_post_meta($post_id, self::META_REC_EXTERNAL_ID, true),
      'needs_receipt' => !empty(get_post_meta($post_id, self::META_REC_NEEDS_RECEIPT, true)),
    ];
  }

  private function get_recurring_template(int $post_id): array {
    if ($post_id <= 0 || get_post_type($post_id) !== self::CPT_RECURRING) return [];
    return $this->recurring_payload($post_id);
  }

  private function job_path_label(int $term_id): string {
    if ($term_id <= 0 || !term_exists($term_id, 'eum_job_path')) return '—';
    return class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path('eum_job_path', $term_id, ' / ') : ((string)get_term_field('name', $term_id, 'eum_job_path'));
  }

  private function finance_path_label(int $term_id): string {
    if ($term_id <= 0 || !term_exists($term_id, self::TAX_FINANCE_PATH)) return '—';
    $label = class_exists('EUM_Profiles') ? EUM_Profiles::term_full_path(self::TAX_FINANCE_PATH, $term_id, ' / ') : ((string)get_term_field('name', $term_id, self::TAX_FINANCE_PATH));
    $external_code = (string)get_term_meta($term_id, self::TERM_META_EXTERNAL_CODE, true);
    if ($external_code !== '') $label .= ' [' . $external_code . ']';
    return $label;
  }

  private function user_label(int $user_id): string {
    if ($user_id <= 0) return '—';
    $user = get_user_by('id', $user_id);
    if (!$user) return 'User #' . $user_id;
    return $user->display_name . ' (#' . $user_id . ')';
  }

  private function frequency_options(): array {
    return [
      'daily' => 'Daily',
      'weekly' => 'Weekly',
      'monthly' => 'Monthly',
      'quarterly' => 'Quarterly',
      'biquarterly' => 'Bi-Quarterly',
      'yearly' => 'Yearly',
      'biyearly' => 'Bi-Yearly',
    ];
  }

  private function frequency_label(string $frequency): string {
    $options = $this->frequency_options();
    return $options[$frequency] ?? ucfirst($frequency ?: 'Custom');
  }

  private function next_cycle_start(string $date, string $frequency): string {
    $timestamp = strtotime($date . ' 00:00:00');
    if (!$timestamp) return '';
    switch ($frequency) {
      case 'daily': return date('Y-m-d', strtotime('+1 day', $timestamp));
      case 'weekly': return date('Y-m-d', strtotime('+1 week', $timestamp));
      case 'monthly': return date('Y-m-d', strtotime('+1 month', $timestamp));
      case 'quarterly': return date('Y-m-d', strtotime('+3 months', $timestamp));
      case 'biquarterly': return date('Y-m-d', strtotime('+6 months', $timestamp));
      case 'yearly': return date('Y-m-d', strtotime('+1 year', $timestamp));
      case 'biyearly': return date('Y-m-d', strtotime('+2 years', $timestamp));
      default: return '';
    }
  }

  private function format_money(float $amount, string $currency = 'USD'): string {
    $currency = strtoupper($currency ?: 'USD');
    return $currency . ' ' . number_format($amount, 2);
  }

  private function display_date(string $date): string {
    $timestamp = strtotime($date . ' 00:00:00');
    if (!$timestamp) return $date;
    return wp_date('M j, Y', $timestamp);
  }

  private function text_field(string $name, string $label, string $value, bool $required = false, string $placeholder = ''): string {
    return '<div><label class="eum-finance__label" for="eum_finance_' . esc_attr($name) . '">' . esc_html($label) . '</label><input class="regular-text" type="text" name="' . esc_attr($name) . '" id="eum_finance_' . esc_attr($name) . '" value="' . esc_attr($value) . '" ' . ($required ? 'required' : '') . ' placeholder="' . esc_attr($placeholder) . '"></div>';
  }

  private function number_field(string $name, string $label, string $value, bool $required = false, string $step = '0.01'): string {
    return '<div><label class="eum-finance__label" for="eum_finance_' . esc_attr($name) . '">' . esc_html($label) . '</label><input class="small-text" type="number" name="' . esc_attr($name) . '" id="eum_finance_' . esc_attr($name) . '" value="' . esc_attr($value) . '" ' . ($required ? 'required' : '') . ' min="0" step="' . esc_attr($step) . '"></div>';
  }

  private function date_field(string $name, string $label, string $value): string {
    return '<div><label class="eum-finance__label" for="eum_finance_' . esc_attr($name) . '">' . esc_html($label) . '</label><input type="date" name="' . esc_attr($name) . '" id="eum_finance_' . esc_attr($name) . '" value="' . esc_attr($value) . '"></div>';
  }

  private function select_field(string $name, string $label, array $options, string $selected): string {
    $html = '<div><label class="eum-finance__label" for="eum_finance_' . esc_attr($name) . '">' . esc_html($label) . '</label><select name="' . esc_attr($name) . '" id="eum_finance_' . esc_attr($name) . '">';
    foreach ($options as $value => $option_label) {
      $html .= '<option value="' . esc_attr((string)$value) . '" ' . selected($selected, (string)$value, false) . '>' . esc_html((string)$option_label) . '</option>';
    }
    $html .= '</select></div>';
    return $html;
  }

  private function handle_receipt_upload(string $field_name, int $post_id, int $user_id): int {
    if (empty($_FILES[$field_name]['name'])) return 0;
    if (!function_exists('wp_handle_upload')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!function_exists('wp_generate_attachment_metadata')) {
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $file = $_FILES[$field_name];
    if (empty($file['tmp_name'])) return 0;
    $uploaded = wp_handle_upload($file, ['test_form' => false]);
    if (!empty($uploaded['error']) || empty($uploaded['file'])) return 0;

    $filetype = wp_check_filetype(basename($uploaded['file']), null);
    $attachment_id = wp_insert_attachment([
      'post_mime_type' => $filetype['type'] ?? 'application/octet-stream',
      'post_title' => sanitize_file_name(wp_basename($uploaded['file'])),
      'post_content' => '',
      'post_status' => 'inherit',
      'post_parent' => $post_id,
      'post_author' => $user_id,
    ], $uploaded['file'], $post_id);
    if (is_wp_error($attachment_id)) return 0;

    $metadata = wp_generate_attachment_metadata((int)$attachment_id, $uploaded['file']);
    if (!is_wp_error($metadata) && !empty($metadata)) {
      wp_update_attachment_metadata((int)$attachment_id, $metadata);
    }

    return (int)$attachment_id;
  }

  private function render_notice(): string {
    $msg = isset($_GET['eum_finance_msg']) ? sanitize_text_field((string)wp_unslash($_GET['eum_finance_msg'])) : '';
    if ($msg === '') return '';
    $type = isset($_GET['eum_finance_type']) ? sanitize_key((string)wp_unslash($_GET['eum_finance_type'])) : 'success';
    $class = $type === 'error' ? 'notice notice-error' : 'notice notice-success';
    return '<div class="' . esc_attr($class) . '"><p>' . esc_html($msg) . '</p></div>';
  }

  private function redirect_with_notice(string $url, string $msg, string $type = 'success'): void {
    $url = $url ?: home_url('/');
    wp_safe_redirect(add_query_arg([
      'eum_finance_msg' => $msg,
      'eum_finance_type' => $type,
    ], $url));
    exit;
  }

  private function posted_return_url(): string {
    return isset($_POST['return_url']) ? esc_url_raw((string)wp_unslash($_POST['return_url'])) : $this->current_url();
  }

  private function current_url(): string {
    $scheme = is_ssl() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field((string)$_SERVER['HTTP_HOST']) : parse_url(home_url('/'), PHP_URL_HOST);
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '/';
    return esc_url_raw($scheme . '://' . $host . $request_uri);
  }

  private function api_settings(): array {
    $settings = get_option(self::OPTION_API_SETTINGS, []);
    return is_array($settings) ? $settings : [];
  }

  private function all_job_path_options(): array {
    $terms = get_terms(['taxonomy' => 'eum_job_path', 'hide_empty' => false, 'number' => 0]);
    if (is_wp_error($terms)) return [];
    $options = [];
    foreach ($terms as $term) {
      $options[(int)$term->term_id] = $this->job_path_label((int)$term->term_id);
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  private function all_job_path_term_ids(): array {
    return array_map('intval', array_keys($this->all_job_path_options()));
  }

  private function print_inline_styles(): void {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    echo '<style>
      .eum-finance{display:block;max-width:1400px}.eum-finance__hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;background:linear-gradient(135deg,#111827,#1f2937);color:#fff;border-radius:18px;padding:22px 24px;margin-bottom:18px}.eum-finance__hero h2{margin:0 0 4px;font-size:28px}.eum-finance__hero p{margin:0;color:#d1d5db}.eum-finance__hero-badges{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.eum-finance__badge{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);padding:8px 12px;border-radius:999px;font-size:12px;font-weight:600}.eum-finance__grid--cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.eum-finance__metric{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;box-shadow:0 8px 22px rgba(15,23,42,.06)}.eum-finance__metric span{display:block;color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.05em}.eum-finance__metric strong{display:block;font-size:24px;margin-top:8px;color:#111827}.eum-finance__two-up{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-bottom:18px}.eum-finance__panel{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}.eum-finance__panel-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:14px}.eum-finance__panel-head h3{margin:0;font-size:18px}.eum-finance__panel-head span{color:#6b7280;font-size:13px}.eum-finance__field-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:14px}.eum-finance__picker-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:14px}.eum-finance__label{display:block;font-weight:600;margin-bottom:6px;color:#111827}.eum-finance__actions{margin-top:14px;display:flex;justify-content:flex-end}.eum-finance__table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:12px}.eum-finance__table-wrap table{margin:0}.eum-finance__status{display:inline-flex;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700}.eum-finance__status--approved,.eum-finance__status--active{background:#dcfce7;color:#166534}.eum-finance__status--pending{background:#fef3c7;color:#92400e}.eum-finance__status--rejected{background:#fee2e2;color:#991b1b}.eum-finance__empty{padding:18px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#475569}.eum-finance__subheading{margin:14px 0 10px}.eum-finance--denied{padding:16px;border:1px solid #fecaca;background:#fef2f2;border-radius:12px}.eum-finance code{background:#f3f4f6;padding:2px 6px;border-radius:6px}@media (max-width:1100px){.eum-finance__grid--cards,.eum-finance__field-grid,.eum-finance__two-up,.eum-finance__picker-grid{grid-template-columns:1fr}} 
    </style>';
  }
}
