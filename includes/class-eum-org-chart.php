<?php
if (!defined('ABSPATH')) exit;

class EUM_Org_Chart {
  public function init(): void {
    add_shortcode('eum_org_chart', [$this, 'shortcode']);
  }

  public function shortcode($atts): string {
    if (!is_user_logged_in()) {
      return '<div class="eum-org-chart"><p>You must be logged in to view the org chart.</p></div>';
    }

    $atts = shortcode_atts([
      'user_id' => '',
      'user' => '',
      'query_var' => 'eum_org_user',
      'slot_query_var' => 'eum_org_manager_slot',
      'title' => 'Org Chart',
      'show_title' => '1',
      'manager_edit_url' => class_exists('EUM_Portal_Shell') ? EUM_Portal_Shell::page_url('work_profile') : home_url('/work-profile/'),
      'self_edit_url' => class_exists('EUM_Portal_Shell') ? EUM_Portal_Shell::page_url('work_profile') : home_url('/work-profile/'),
    ], (array)$atts, 'eum_org_chart');

    $current_user_id = (int)get_current_user_id();
    $user_query_var = sanitize_key((string)$atts['query_var']);
    $slot_query_var = sanitize_key((string)$atts['slot_query_var']);
    $focus_slot_id = isset($_GET[$slot_query_var]) ? max(0, intval($_GET[$slot_query_var])) : 0;
    $panel_id = 'eum-org-sidepanel-' . wp_rand(1000, 999999);

    $boss_nodes = [];
    $team_items = [];
    $team_user_ids = [];
    $visible_ids = [];
    $visible_slots = [];
    $focus_html = '';
    $initial_panel_html = '';

    if ($focus_slot_id > 0) {
      $focus_node = $this->resolve_slot_node_for_manager_id($focus_slot_id);
      if (($focus_node['type'] ?? 'none') === 'none') {
        return '<div class="eum-org-chart"><p>Manager slot not found.</p></div>';
      }

      $boss_nodes = $this->resolve_manager_chain_nodes_for_manager_id($focus_slot_id);
      $team_items = $this->get_direct_report_items_for_manager_slot($focus_slot_id);
      $team_user_ids = $this->extract_report_item_user_ids($team_items);
      $focus_html = $this->render_node_card($focus_node, $current_user_id, 'Selected Role', $user_query_var, $slot_query_var, $panel_id, (string)$atts['manager_edit_url'], (string)$atts['self_edit_url'], true);
      $initial_panel_html = $this->slot_panel_html($focus_node, $team_user_ids, $current_user_id, [
        'manager_edit_url' => (string)$atts['manager_edit_url'],
        'self_edit_url' => (string)$atts['self_edit_url'],
        'query_var' => $user_query_var,
      ]);
      if (!empty($focus_node['manager_id'])) $visible_slots[] = (int)$focus_node['manager_id'];
      if (!empty($focus_node['user_id'])) $visible_ids[] = (int)$focus_node['user_id'];
    } else {
      $focus_user_id = class_exists('EUM_Profiles')
        ? EUM_Profiles::resolve_target_user_from_request($atts, true)
        : $current_user_id;
      if ($focus_user_id <= 0) $focus_user_id = $current_user_id;

      $focus_user = get_user_by('id', $focus_user_id);
      if (!$focus_user) {
        return '<div class="eum-org-chart"><p>User not found.</p></div>';
      }

      $boss_nodes = $this->resolve_manager_chain_nodes_for_user($focus_user_id);
      $team_items = $this->get_direct_report_items_for_user($focus_user_id);
      $team_user_ids = $this->extract_report_item_user_ids($team_items);
      $focus_html = $this->render_person_card($focus_user_id, $current_user_id, 'Selected User', $user_query_var, $slot_query_var, $panel_id, (string)$atts['manager_edit_url'], (string)$atts['self_edit_url'], true);
      $initial_panel_html = class_exists('EUM_Profiles')
        ? EUM_Profiles::public_profile_snapshot_html($focus_user_id, $current_user_id, [
            'manager_edit_url' => (string)$atts['manager_edit_url'],
            'self_edit_url' => (string)$atts['self_edit_url'],
            'query_var' => $user_query_var,
          ])
        : '<div class="eum-org-sidepanel__empty">Profile preview unavailable.</div>';
      $visible_ids[] = $focus_user_id;
    }

    foreach ($boss_nodes as $boss_node) {
      if (($boss_node['type'] ?? '') === 'user' && !empty($boss_node['user_id'])) {
        $visible_ids[] = (int)$boss_node['user_id'];
      } elseif (in_array(($boss_node['type'] ?? ''), ['vacancy', 'interim'], true) && !empty($boss_node['manager_id'])) {
        $visible_slots[] = (int)$boss_node['manager_id'];
        if (!empty($boss_node['user_id'])) $visible_ids[] = (int)$boss_node['user_id'];
      }
    }
    foreach ($team_items as $team_item) {
      if (($team_item['kind'] ?? '') === 'user' && !empty($team_item['user_id'])) {
        $visible_ids[] = (int)$team_item['user_id'];
      } elseif (($team_item['kind'] ?? '') === 'slot' && !empty($team_item['manager_id'])) {
        $visible_slots[] = (int)$team_item['manager_id'];
        if (!empty($team_item['user_id'])) $visible_ids[] = (int)$team_item['user_id'];
      }
    }

    $visible_ids = array_values(array_unique(array_filter(array_map('intval', $visible_ids))));
    $visible_slots = array_values(array_unique(array_filter(array_map('intval', $visible_slots))));

    ob_start();
    echo '<div class="eum-org-chart eum-org-chart--modern">';
    if ($atts['show_title'] === '1' || $atts['show_title'] === 'true') {
      echo '<div class="eum-org-chart__header">';
      echo '<div><h3 style="margin:0;">' . esc_html((string)$atts['title']) . '</h3>';
      echo '<p class="eum-org-chart__subhead">Click a card to move through teams and managers. Use the icon button to preview details on the right.</p></div>';
      echo '</div>';
    }

    echo '<div class="eum-org-chart__shell">';
    echo '<div class="eum-org-chart__main">';

    echo '<div class="eum-org-chart__lane eum-org-chart__lane--bosses">';
    if (empty($boss_nodes)) {
      echo '<div class="eum-org-chart__empty">No higher manager found.</div>';
    } else {
      foreach ($boss_nodes as $index => $boss_node) {
        $badge = (($boss_node['type'] ?? '') === 'vacancy') ? 'Manager Vacancy' : 'Manager';
        if ($index > 0) $badge = 'Manager +' . ($index + 1);
        echo $this->render_node_card($boss_node, $current_user_id, $badge, $user_query_var, $slot_query_var, $panel_id, (string)$atts['manager_edit_url'], (string)$atts['self_edit_url']);
      }
    }
    echo '</div>';

    echo '<div class="eum-org-chart__focus-wrap">';
    echo $focus_html;
    echo '</div>';

    echo '<div class="eum-org-chart__lane eum-org-chart__lane--reports">';
    if (empty($team_items)) {
      echo '<div class="eum-org-chart__empty">No team members found.</div>';
    } else {
      foreach ($team_items as $team_item) {
        if (($team_item['kind'] ?? '') === 'user' && !empty($team_item['user_id'])) {
          echo $this->render_person_card((int)$team_item['user_id'], $current_user_id, 'Team', $user_query_var, $slot_query_var, $panel_id, (string)$atts['manager_edit_url'], (string)$atts['self_edit_url']);
        } elseif (($team_item['kind'] ?? '') === 'slot' && !empty($team_item['manager_id'])) {
          $slot_node = $team_item;
          unset($slot_node['kind']);
          echo $this->render_node_card($slot_node, $current_user_id, 'Team', $user_query_var, $slot_query_var, $panel_id, (string)$atts['manager_edit_url'], (string)$atts['self_edit_url']);
        }
      }
    }
    echo '</div>';

    echo '</div>';
    echo '<aside class="eum-org-chart__sidepanel" id="' . esc_attr($panel_id) . '">';
    echo $initial_panel_html;
    echo '</aside>';
    echo '</div>';

    foreach ($visible_ids as $uid) {
      echo '<div id="eum-org-profile-' . (int)$uid . '" hidden>';
      if (class_exists('EUM_Profiles')) {
        echo EUM_Profiles::public_profile_snapshot_html($uid, $current_user_id, [
          'manager_edit_url' => (string)$atts['manager_edit_url'],
          'self_edit_url' => (string)$atts['self_edit_url'],
          'query_var' => $user_query_var,
        ]);
      }
      echo '</div>';
    }

    foreach ($visible_slots as $manager_id) {
      $slot_node = $this->resolve_slot_node_for_manager_id((int)$manager_id);
      if (($slot_node['type'] ?? 'none') === 'none') continue;
      echo '<div id="eum-org-slot-' . (int)$manager_id . '" hidden>';
      echo $this->slot_panel_html($slot_node, $this->get_direct_reports_for_manager_slot((int)$manager_id), $current_user_id, [
        'manager_edit_url' => (string)$atts['manager_edit_url'],
        'self_edit_url' => (string)$atts['self_edit_url'],
        'query_var' => $user_query_var,
      ]);
      echo '</div>';
    }

    echo '</div>';
    return ob_get_clean();
  }

  private function render_node_card(array $node, int $viewer_user_id, string $label, string $user_query_var, string $slot_query_var, string $panel_id, string $manager_edit_url, string $self_edit_url, bool $is_focus = false): string {
    $type = (string)($node['type'] ?? 'none');
    if ($type === 'user' && !empty($node['user_id'])) {
      return $this->render_person_card((int)$node['user_id'], $viewer_user_id, $label, $user_query_var, $slot_query_var, $panel_id, $manager_edit_url, $self_edit_url, $is_focus);
    }
    if (in_array($type, ['vacancy', 'interim'], true)) {
      return $this->render_slot_card($node, $viewer_user_id, $label, $user_query_var, $slot_query_var, $panel_id, $is_focus);
    }
    return '';
  }

  private function render_person_card(int $user_id, int $viewer_user_id, string $label, string $user_query_var, string $slot_query_var, string $panel_id, string $manager_edit_url, string $self_edit_url, bool $is_focus = false): string {
    $user = get_user_by('id', $user_id);
    if (!$user) return '';

    $name = $user->display_name ?: $user->user_login;
    $job = $this->user_job_title_label($user_id);
    $company_division = class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'core:job_company') : '';
    $classes = 'eum-org-chart__person-card' . ($is_focus ? ' is-focus' : '');
    $badges = [$label];
    if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id)) {
      $badges[] = class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::terminated_label_for_viewer($viewer_user_id) : 'Alumni/Former Staff';
    }
    if (class_exists('EUM_Profiles')) {
      $worker_badge = EUM_Profiles::worker_type_badge_label($user_id);
      if ($worker_badge !== '') $badges[] = $worker_badge;
    }
    if (class_exists('EUM_Workforce_Lifecycle')) {
      $ooo_badge = EUM_Workforce_Lifecycle::out_of_office_label($user_id);
      if ($ooo_badge !== '') $badges[] = $ooo_badge;
    }
    if ($user_id === $viewer_user_id) $badges[] = 'You';
    $url = $this->build_user_focus_url($user_id, $user_query_var, $slot_query_var);
    $report_counts = $this->report_counts_for_user($user_id);

    ob_start();
    echo '<article class="' . esc_attr($classes) . '">';
    echo '<a class="eum-org-chart__person-main" href="' . esc_url($url) . '">';
    if (class_exists('EUM_Profiles')) {
      echo EUM_Profiles::profile_avatar_html($user_id, 92, 'eum-org-chart__avatar');
    }
    echo '<span class="eum-org-chart__person-copy">';
    echo '<span class="eum-org-chart__badges">';
    foreach ($badges as $badge) {
      echo '<span class="eum-org-chart__badge">' . esc_html($badge) . '</span>';
    }
    echo '</span>';
    echo '<strong class="eum-org-chart__name">' . esc_html($name) . '</strong>';
    if ($job !== '') echo '<span class="eum-org-chart__meta eum-org-chart__meta--title">' . esc_html($job) . '</span>';
    if ($company_division !== '') echo '<span class="eum-org-chart__meta eum-org-chart__meta--division">' . esc_html($company_division) . '</span>';
    echo '<span class="eum-org-chart__meta eum-org-chart__meta--counts">Reports • ' . esc_html($this->format_report_counts((int)$report_counts['direct'], (int)$report_counts['total'])) . '</span>';
    echo '</span>';
    echo '</a>';
    echo '<button type="button" class="eum-org-chart__info" data-eum-org-profile-target="eum-org-profile-' . (int)$user_id . '" data-eum-org-panel="' . esc_attr($panel_id) . '" aria-label="Open profile preview for ' . esc_attr($name) . '">';
    echo '<span aria-hidden="true">👥</span>';
    echo '</button>';
    echo '</article>';
    return ob_get_clean();
  }

  private function render_slot_card(array $node, int $viewer_user_id, string $label, string $user_query_var, string $slot_query_var, string $panel_id, bool $is_focus = false): string {
    $manager_id = (int)($node['manager_id'] ?? 0);
    if ($manager_id <= 0) return '';

    $type = (string)($node['type'] ?? 'vacancy');
    $classes = 'eum-org-chart__person-card eum-org-chart__person-card--vacancy' . ($is_focus ? ' is-focus' : '');
    $name = trim((string)($node['name'] ?? 'Vacancy'));
    if ($name === '') $name = 'Vacancy';
    $job_title = trim((string)($node['job_title'] ?? ''));
    if ($job_title === '') $job_title = 'Vacancy';
    $company_division = trim((string)($node['company_division'] ?? ''));
    $badges = [$label];
    if ($type === 'interim') $badges[] = 'Interim';
    if (!empty($node['user_id']) && (int)$node['user_id'] === $viewer_user_id) $badges[] = 'You';
    $report_counts = $this->report_counts_for_manager_slot($manager_id);
    $url = $this->build_slot_focus_url($manager_id, $user_query_var, $slot_query_var);
    $profile_target = 'eum-org-slot-' . $manager_id;

    ob_start();
    echo '<article class="' . esc_attr($classes) . '">';
    echo '<a class="eum-org-chart__person-main" href="' . esc_url($url) . '">';
    echo $this->render_slot_avatar_html($node, 'eum-org-chart__avatar', 92);
    echo '<span class="eum-org-chart__person-copy">';
    echo '<span class="eum-org-chart__badges">';
    foreach ($badges as $badge) {
      echo '<span class="eum-org-chart__badge">' . esc_html($badge) . '</span>';
    }
    echo '</span>';
    echo '<strong class="eum-org-chart__name">' . esc_html($name) . '</strong>';
    echo '<span class="eum-org-chart__meta eum-org-chart__meta--title">' . esc_html($job_title) . '</span>';
    if ($company_division !== '') echo '<span class="eum-org-chart__meta eum-org-chart__meta--division">' . esc_html($company_division) . '</span>';
    echo '<span class="eum-org-chart__meta eum-org-chart__meta--counts">Reports • ' . esc_html($this->format_report_counts((int)$report_counts['direct'], (int)$report_counts['total'])) . '</span>';
    echo '</span>';
    echo '</a>';
    echo '<button type="button" class="eum-org-chart__info" data-eum-org-profile-target="' . esc_attr($profile_target) . '" data-eum-org-panel="' . esc_attr($panel_id) . '" aria-label="Open manager slot preview for ' . esc_attr($name) . '">';
    echo '<span aria-hidden="true">👥</span>';
    echo '</button>';
    echo '</article>';
    return ob_get_clean();
  }

  private function render_slot_avatar_html(array $node, string $classes, int $size = 92): string {
    $image_url = trim((string)($node['image_url'] ?? ''));
    $user_id = (int)($node['user_id'] ?? 0);
    if ($image_url !== '') {
      return '<img class="' . esc_attr($classes) . '" src="' . esc_url($image_url) . '" alt="" width="' . (int)$size . '" height="' . (int)$size . '">';
    }
    if ($user_id > 0 && class_exists('EUM_Profiles')) {
      $existing_image = EUM_Profiles::profile_image_url($user_id);
      if ($existing_image !== '') {
        return '<img class="' . esc_attr($classes) . '" src="' . esc_url($existing_image) . '" alt="" width="' . (int)$size . '" height="' . (int)$size . '">';
      }
    }

    $source = trim((string)($node['job_title'] ?? ''));
    if ($source === '') $source = trim((string)($node['name'] ?? 'Vacancy'));
    return $this->render_generated_avatar_html($source, $classes, $size);
  }

  private function render_generated_avatar_html(string $source, string $classes, int $size = 92): string {
    $initials = $this->initials_from_label($source);
    $hash = md5($source !== '' ? $source : 'vacancy');
    $bg = '#' . substr($hash, 0, 6);
    $style = 'display:inline-flex;align-items:center;justify-content:center;width:' . (int)$size . 'px;height:' . (int)$size . 'px;border-radius:50%;font-weight:700;font-size:' . max(18, (int)round($size / 2.9)) . 'px;color:#fff;background:' . esc_attr($bg) . ';';
    return '<span class="' . esc_attr(trim($classes . ' is-generated')) . '" style="' . esc_attr($style) . '"><span>' . esc_html($initials) . '</span></span>';
  }

  private function initials_from_label(string $label): string {
    $label = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($label)));
    if ($label === '') return 'V';
    $words = preg_split('/\s+/', $label);
    $letters = '';
    foreach ((array)$words as $word) {
      if ($word === '') continue;
      $letters .= strtoupper(substr($word, 0, 1));
      if (strlen($letters) >= 2) break;
    }
    if ($letters === '') $letters = strtoupper(substr($label, 0, 1));
    return substr($letters, 0, 2);
  }

  private function slot_panel_html(array $node, array $team_ids, int $viewer_user_id, array $profile_opts = []): string {
    $type = (string)($node['type'] ?? 'vacancy');
    $name = trim((string)($node['name'] ?? 'Vacancy'));
    if ($name === '') $name = 'Vacancy';
    $job_title = trim((string)($node['job_title'] ?? ''));
    if ($job_title === '') $job_title = 'Vacancy';
    $division = trim((string)($node['company_division'] ?? ''));
    $manager_id = (int)($node['manager_id'] ?? 0);
    $user_id = (int)($node['user_id'] ?? 0);

    ob_start();
    echo '<div class="eum-public-profile eum-public-profile--snapshot">';
    echo '<div class="eum-public-profile__hero">';
    echo '<div class="eum-public-profile__hero-media">' . $this->render_slot_avatar_html($node, 'eum-public-profile__avatar', 112) . '</div>';
    echo '<div class="eum-public-profile__hero-copy">';
    echo '<div class="eum-public-profile__eyebrow">' . esc_html($type === 'interim' ? 'Interim Manager Slot' : 'Manager Vacancy') . '</div>';
    echo '<h3>' . esc_html($name) . '</h3>';
    echo '<div class="eum-public-profile__title-line">';
    echo '<span>' . esc_html($job_title) . '</span>';
    if ($division !== '') echo '<span>' . esc_html($division) . '</span>';
    echo '</div>';
    $report_counts = $this->report_counts_for_manager_slot($manager_id);
    echo '<p class="description" style="margin-top:8px;">Direct reports in this role: ' . esc_html($this->format_report_counts((int)$report_counts['direct'], (int)$report_counts['total'])) . '</p>';
    echo '</div>';
    echo '</div>';

    if ($manager_id > 0) {
      echo '<div class="eum-public-profile__section"><h4>Role</h4><p>' . esc_html(get_the_title($manager_id)) . '</p></div>';
    }

    if ($user_id > 0 && class_exists('EUM_Profiles')) {
      echo '<div class="eum-public-profile__section"><h4>' . esc_html($type === 'interim' ? 'Interim Profile' : 'Displayed Profile') . '</h4>';
      echo EUM_Profiles::public_profile_snapshot_html($user_id, $viewer_user_id, $profile_opts);
      echo '</div>';
    } elseif (!empty($team_ids)) {
      echo '<div class="eum-public-profile__section"><h4>Direct Reports</h4><p>' . esc_html(implode(', ', array_map([$this, 'user_label'], array_slice($team_ids, 0, 8)))) . (count($team_ids) > 8 ? '…' : '') . '</p></div>';
    }

    echo '</div>';
    return ob_get_clean();
  }

  private function resolve_slot_node_for_manager_id(int $manager_id_post): array {
    $manager_id_post = (int)$manager_id_post;
    if ($manager_id_post <= 0 || !class_exists('EUM_Manager_IDs') || get_post_type($manager_id_post) !== EUM_Manager_IDs::CPT) {
      return ['type' => 'none'];
    }

    $cfg = EUM_Manager_IDs::vacancy_config_for_id($manager_id_post);
    foreach (EUM_Manager_IDs::manager_users_for_id($manager_id_post) as $manager_user_id) {
      $manager_user_id = (int)$manager_user_id;
      if ($manager_user_id > 0 && get_user_by('id', $manager_user_id)) {
        return [
          'type' => 'user',
          'user_id' => $manager_user_id,
          'manager_id' => $manager_id_post,
        ];
      }
    }

    $interim_user_id = (int)($cfg['interim_user_id'] ?? 0);
    if ($interim_user_id > 0) {
      $interim_user = get_user_by('id', $interim_user_id);
      if ($interim_user) {
        $division = trim((string)($cfg['division_label'] ?? ''));
        if ($division === '' && class_exists('EUM_Profiles')) {
          $division = EUM_Profiles::get_field_display_value($interim_user_id, 'core:job_company');
        }
        return [
          'type' => 'interim',
          'manager_id' => $manager_id_post,
          'user_id' => $interim_user_id,
          'name' => (string)($interim_user->display_name ?: $interim_user->user_login),
          'job_title' => trim((string)($cfg['job_title'] ?? '')),
          'company_division' => $division,
          'image_url' => trim((string)($cfg['image_url'] ?? '')),
        ];
      }
    }

    if (!empty($cfg['hide_if_vacant'])) {
      return ['type' => 'none'];
    }

    return [
      'type' => 'vacancy',
      'manager_id' => $manager_id_post,
      'name' => 'Vacancy',
      'job_title' => trim((string)($cfg['job_title'] ?? '')),
      'company_division' => trim((string)($cfg['division_label'] ?? '')),
      'image_url' => trim((string)($cfg['image_url'] ?? '')),
    ];
  }


  private function resolve_manager_chain_nodes_for_user(int $user_id): array {
    $chain = [];
    $seen = [];
    $current_type = 'user';
    $current_user_id = (int)$user_id;
    $current_slot_id = 0;

    while (true) {
      $node = ($current_type === 'slot')
        ? $this->resolve_manager_node_for_manager_id($current_slot_id)
        : $this->resolve_manager_node_for_user($current_user_id);
      if (($node['type'] ?? 'none') === 'none') break;

      $key = $this->manager_chain_node_key($node);
      if ($key === '' || isset($seen[$key])) break;
      $seen[$key] = true;
      $chain[] = $node;

      if (($node['type'] ?? '') === 'user') {
        $next_user_id = (int)($node['user_id'] ?? 0);
        if ($next_user_id <= 0 || ($current_type === 'user' && $next_user_id === $current_user_id)) break;
        $current_type = 'user';
        $current_user_id = $next_user_id;
        continue;
      }

      $next_slot_id = (int)($node['manager_id'] ?? 0);
      if ($next_slot_id <= 0) break;
      $current_type = 'slot';
      $current_slot_id = $next_slot_id;
    }

    return $chain;
  }

  private function resolve_manager_chain_nodes_for_manager_id(int $manager_id_post): array {
    $chain = [];
    $seen = [];
    $current_slot_id = (int)$manager_id_post;

    while ($current_slot_id > 0) {
      $node = $this->resolve_manager_node_for_manager_id($current_slot_id);
      if (($node['type'] ?? 'none') === 'none') break;

      $key = $this->manager_chain_node_key($node);
      if ($key === '' || isset($seen[$key])) break;
      $seen[$key] = true;
      $chain[] = $node;

      if (($node['type'] ?? '') === 'user') {
        $next_user_id = (int)($node['user_id'] ?? 0);
        if ($next_user_id <= 0) break;
        foreach ($this->resolve_manager_chain_nodes_for_user($next_user_id) as $parent_node) {
          $parent_key = $this->manager_chain_node_key($parent_node);
          if ($parent_key === '' || isset($seen[$parent_key])) continue;
          $seen[$parent_key] = true;
          $chain[] = $parent_node;
        }
        break;
      }

      $next_slot_id = (int)($node['manager_id'] ?? 0);
      if ($next_slot_id <= 0 || $next_slot_id === $current_slot_id) break;
      $current_slot_id = $next_slot_id;
    }

    return $chain;
  }

  private function manager_chain_node_key(array $node): string {
    $type = (string)($node['type'] ?? 'none');
    if ($type === 'user' && !empty($node['user_id'])) return 'u:' . (int)$node['user_id'];
    if (in_array($type, ['vacancy', 'interim'], true) && !empty($node['manager_id'])) return 's:' . (int)$node['manager_id'];
    return '';
  }

  private function resolve_manager_node_for_user(int $user_id): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return ['type' => 'none'];

    if (class_exists('EUM_Personnel')) {
      $direct_manager_id = EUM_Personnel::effective_manager_user_id($user_id);
      if ($direct_manager_id > 0 && get_user_by('id', $direct_manager_id)) {
        return [
          'type' => 'user',
          'user_id' => $direct_manager_id,
        ];
      }
    }

    if (!class_exists('EUM_Manager_IDs')) return ['type' => 'none'];
    $mgr = new EUM_Manager_IDs();
    foreach ((array)$mgr->manager_id_chain_for_user($user_id) as $manager_id_post) {
      $node = $this->resolve_slot_node_for_manager_id((int)$manager_id_post);
      if (($node['type'] ?? 'none') !== 'none') {
        return $node;
      }
    }

    return ['type' => 'none'];
  }

  private function resolve_manager_node_for_manager_id(int $manager_id_post): array {
    $manager_id_post = (int)$manager_id_post;
    if ($manager_id_post <= 0 || !class_exists('EUM_Manager_IDs')) return ['type' => 'none'];

    $mgr = new EUM_Manager_IDs();
    $chain = (array)$mgr->manager_id_ancestry_chain_from_manager_id($manager_id_post);
    $index = array_search($manager_id_post, $chain, true);
    if ($index === false) return ['type' => 'none'];
    for ($i = $index + 1; $i < count($chain); $i++) {
      $node = $this->resolve_slot_node_for_manager_id((int)$chain[$i]);
      if (($node['type'] ?? 'none') !== 'none') return $node;
    }
    return ['type' => 'none'];
  }

  private function format_report_counts(int $direct, int $total): string {
    $direct = max(0, (int)$direct);
    $total = max($direct, (int)$total);
    return $direct . '/' . $total;
  }

  private function report_counts_for_user(int $user_id): array {
    $direct = 0;
    foreach ($this->get_direct_report_items_for_user($user_id) as $item) {
      if (($item['kind'] ?? '') === 'user' && !empty($item['user_id'])) $direct++;
    }
    $total = count($this->collect_descendant_user_ids_for_user($user_id, 4));
    return ['direct' => $direct, 'total' => $total];
  }

  private function report_counts_for_manager_slot(int $manager_id_post): array {
    $direct = 0;
    foreach ($this->get_direct_report_items_for_manager_slot($manager_id_post) as $item) {
      if (($item['kind'] ?? '') === 'user' && !empty($item['user_id'])) $direct++;
    }
    $total = count($this->collect_descendant_user_ids_for_manager_slot($manager_id_post, 4));
    return ['direct' => $direct, 'total' => $total];
  }

  private function extract_report_item_user_ids(array $items): array {
    $ids = [];
    foreach ($items as $item) {
      if (($item['kind'] ?? '') === 'user' && !empty($item['user_id'])) {
        $ids[(int)$item['user_id']] = (int)$item['user_id'];
      }
    }
    return array_values($ids);
  }

  private function report_item_sort_label(array $item): string {
    if (($item['kind'] ?? '') === 'user' && !empty($item['user_id'])) {
      return $this->user_label((int)$item['user_id']);
    }
    if (($item['kind'] ?? '') === 'slot') {
      $job_title = trim((string)($item['job_title'] ?? ''));
      $name = trim((string)($item['name'] ?? ''));
      return $job_title !== '' ? $job_title : ($name !== '' ? $name : 'Vacancy');
    }
    return '';
  }

  private function get_direct_report_items_for_user(int $user_id): array {
    $items = [];
    $seen_users = [];
    $seen_slots = [];

    foreach ($this->get_direct_reports($user_id) as $uid) {
      $uid = (int)$uid;
      if ($uid <= 0 || isset($seen_users[$uid])) continue;
      $seen_users[$uid] = true;
      $items[] = ['kind' => 'user', 'user_id' => $uid];
    }

    foreach ($this->find_direct_child_slot_nodes_for_user($user_id) as $node) {
      $type = (string)($node['type'] ?? 'none');
      if ($type === 'user' && !empty($node['user_id'])) {
        $uid = (int)$node['user_id'];
        if ($uid > 0 && !isset($seen_users[$uid])) {
          $seen_users[$uid] = true;
          $items[] = ['kind' => 'user', 'user_id' => $uid];
        }
      } elseif (in_array($type, ['vacancy', 'interim'], true) && !empty($node['manager_id'])) {
        $slot_id = (int)$node['manager_id'];
        if ($slot_id > 0 && !isset($seen_slots[$slot_id])) {
          $seen_slots[$slot_id] = true;
          $items[] = ['kind' => 'slot'] + $node;
        }
      }
    }

    usort($items, function ($a, $b) {
      return strcasecmp($this->report_item_sort_label($a), $this->report_item_sort_label($b));
    });

    return $items;
  }

  private function get_direct_report_items_for_manager_slot(int $manager_id_post): array {
    $items = [];
    $seen_users = [];
    $seen_slots = [];

    foreach ($this->get_direct_reports_for_manager_slot($manager_id_post) as $uid) {
      $uid = (int)$uid;
      if ($uid <= 0 || isset($seen_users[$uid])) continue;
      $seen_users[$uid] = true;
      $items[] = ['kind' => 'user', 'user_id' => $uid];
    }

    foreach ($this->find_direct_child_slot_nodes_for_manager_slot($manager_id_post) as $node) {
      $type = (string)($node['type'] ?? 'none');
      if ($type === 'user' && !empty($node['user_id'])) {
        $uid = (int)$node['user_id'];
        if ($uid > 0 && !isset($seen_users[$uid])) {
          $seen_users[$uid] = true;
          $items[] = ['kind' => 'user', 'user_id' => $uid];
        }
      } elseif (in_array($type, ['vacancy', 'interim'], true) && !empty($node['manager_id'])) {
        $slot_id = (int)$node['manager_id'];
        if ($slot_id > 0 && !isset($seen_slots[$slot_id])) {
          $seen_slots[$slot_id] = true;
          $items[] = ['kind' => 'slot'] + $node;
        }
      }
    }

    usort($items, function ($a, $b) {
      return strcasecmp($this->report_item_sort_label($a), $this->report_item_sort_label($b));
    });

    return $items;
  }

  private function collect_descendant_user_ids_for_user(int $user_id, int $max_depth = 4, array $visited_users = [], array $visited_slots = []): array {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || $max_depth <= 0 || isset($visited_users[$user_id])) return [];
    $visited_users[$user_id] = true;
    $out = [];

    foreach ($this->get_direct_report_items_for_user($user_id) as $item) {
      if (($item['kind'] ?? '') === 'user' && !empty($item['user_id'])) {
        $child_user_id = (int)$item['user_id'];
        if ($child_user_id > 0 && $child_user_id !== $user_id) {
          $out[$child_user_id] = $child_user_id;
          foreach ($this->collect_descendant_user_ids_for_user($child_user_id, $max_depth - 1, $visited_users, $visited_slots) as $desc_user_id) {
            $out[(int)$desc_user_id] = (int)$desc_user_id;
          }
        }
      } elseif (($item['kind'] ?? '') === 'slot' && !empty($item['manager_id'])) {
        foreach ($this->collect_descendant_user_ids_for_manager_slot((int)$item['manager_id'], $max_depth - 1, $visited_users, $visited_slots) as $desc_user_id) {
          $out[(int)$desc_user_id] = (int)$desc_user_id;
        }
      }
    }

    return array_values($out);
  }

  private function collect_descendant_user_ids_for_manager_slot(int $manager_id_post, int $max_depth = 4, array $visited_users = [], array $visited_slots = []): array {
    $manager_id_post = (int)$manager_id_post;
    if ($manager_id_post <= 0 || $max_depth <= 0 || isset($visited_slots[$manager_id_post])) return [];
    $visited_slots[$manager_id_post] = true;
    $out = [];

    foreach ($this->get_direct_report_items_for_manager_slot($manager_id_post) as $item) {
      if (($item['kind'] ?? '') === 'user' && !empty($item['user_id'])) {
        $child_user_id = (int)$item['user_id'];
        if ($child_user_id > 0) {
          $out[$child_user_id] = $child_user_id;
          foreach ($this->collect_descendant_user_ids_for_user($child_user_id, $max_depth - 1, $visited_users, $visited_slots) as $desc_user_id) {
            $out[(int)$desc_user_id] = (int)$desc_user_id;
          }
        }
      } elseif (($item['kind'] ?? '') === 'slot' && !empty($item['manager_id'])) {
        $child_slot_id = (int)$item['manager_id'];
        foreach ($this->collect_descendant_user_ids_for_manager_slot($child_slot_id, $max_depth - 1, $visited_users, $visited_slots) as $desc_user_id) {
          $out[(int)$desc_user_id] = (int)$desc_user_id;
        }
      }
    }

    return array_values($out);
  }

  private function all_manager_id_posts(): array {
    static $manager_ids = null;
    if ($manager_ids !== null) return $manager_ids;
    if (!class_exists('EUM_Manager_IDs')) {
      $manager_ids = [];
      return $manager_ids;
    }

    $manager_ids = array_values(array_filter(array_map('intval', get_posts([
      'post_type' => EUM_Manager_IDs::CPT,
      'post_status' => 'publish',
      'numberposts' => 2000,
      'orderby' => 'title',
      'order' => 'ASC',
      'fields' => 'ids',
    ]))));

    return $manager_ids;
  }

  private function find_direct_child_slot_nodes_for_user(int $user_id): array {
    $children = [];
    foreach ($this->all_manager_id_posts() as $manager_id_post) {
      $manager_id_post = (int)$manager_id_post;
      if ($manager_id_post <= 0) continue;
      $parent_node = $this->resolve_manager_node_for_manager_id($manager_id_post);
      if (($parent_node['type'] ?? 'none') !== 'user' || (int)($parent_node['user_id'] ?? 0) !== $user_id) continue;
      $node = $this->resolve_slot_node_for_manager_id($manager_id_post);
      if (($node['type'] ?? 'none') === 'none') continue;
      $children[$manager_id_post] = $node;
    }
    return array_values($children);
  }

  private function find_direct_child_slot_nodes_for_manager_slot(int $manager_id_post): array {
    $manager_id_post = (int)$manager_id_post;
    if ($manager_id_post <= 0) return [];

    $children = [];
    foreach ($this->all_manager_id_posts() as $candidate_slot_id) {
      $candidate_slot_id = (int)$candidate_slot_id;
      if ($candidate_slot_id <= 0 || $candidate_slot_id === $manager_id_post) continue;
      $parent_node = $this->resolve_manager_node_for_manager_id($candidate_slot_id);
      if ((int)($parent_node['manager_id'] ?? 0) !== $manager_id_post) continue;
      $node = $this->resolve_slot_node_for_manager_id($candidate_slot_id);
      if (($node['type'] ?? 'none') === 'none') continue;
      $children[$candidate_slot_id] = $node;
    }
    return array_values($children);
  }

  private function get_direct_reports(int $user_id): array {
    $reports = [];

    if (class_exists('EUM_Manager_IDs')) {
      foreach (EUM_Manager_IDs::managed_user_ids_for_manager_user($user_id, false) as $uid) {
        if ($uid > 0 && $uid !== $user_id) $reports[$uid] = $uid;
      }
    }

    if (class_exists('EUM_Personnel')) {
      $direct = get_users([
        'meta_key' => EUM_Personnel::META_MANAGER_ID,
        'meta_value' => $user_id,
        'fields' => ['ID'],
        'number' => 5000,
      ]);
      foreach ((array)$direct as $user) {
        $uid = is_object($user) ? (int)$user->ID : (int)$user;
        if ($uid > 0 && $uid !== $user_id && !EUM_Personnel::is_terminated($uid)) $reports[$uid] = $uid;
      }
    }

    $report_ids = array_values($reports);
    usort($report_ids, function ($a, $b) {
      return strcasecmp($this->user_label($a), $this->user_label($b));
    });

    return $report_ids;
  }

  private function get_direct_reports_for_manager_slot(int $manager_id_post): array {
    $manager_id_post = (int)$manager_id_post;
    if ($manager_id_post <= 0) return [];

    $reports = [];
    foreach ((array)get_users(['fields' => ['ID'], 'number' => 5000]) as $user) {
      $uid = is_object($user) ? (int)$user->ID : (int)$user;
      if ($uid <= 0) continue;
      if (class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($uid)) continue;

      $node = $this->resolve_manager_node_for_user($uid);
      if ((int)($node['manager_id'] ?? 0) === $manager_id_post) {
        $reports[$uid] = $uid;
      }
    }

    $report_ids = array_values($reports);
    usort($report_ids, function ($a, $b) {
      return strcasecmp($this->user_label($a), $this->user_label($b));
    });
    return $report_ids;
  }

  private function user_label(int $user_id): string {
    $user = get_user_by('id', $user_id);
    if (!$user) return 'User #' . $user_id;
    return (string)($user->display_name ?: $user->user_login);
  }

  private function build_user_focus_url(int $user_id, string $user_query_var, string $slot_query_var): string {
    $url = remove_query_arg([$user_query_var, $slot_query_var], $this->current_url());
    $ref = class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_id_for_user($user_id) : '';
    if ($ref === '') $ref = 'eum-id:' . $user_id;
    return add_query_arg([$user_query_var => $ref], $url);
  }

  private function build_slot_focus_url(int $manager_id_post, string $user_query_var, string $slot_query_var): string {
    $url = remove_query_arg([$user_query_var, $slot_query_var], $this->current_url());
    return add_query_arg([$slot_query_var => $manager_id_post], $url);
  }

  private function user_job_title_label(int $user_id): string {
    if (class_exists('EUM_Profiles')) {
      $label = EUM_Profiles::preferred_job_title_label($user_id);
      if ($label !== '') return $label;
    }
    if (!taxonomy_exists('eum_job_path')) return '';
    $terms = wp_get_object_terms($user_id, 'eum_job_path');
    if (is_wp_error($terms) || empty($terms)) return '';
    $term = $terms[0];
    return isset($term->name) ? (string)$term->name : '';
  }

  private function current_url(): string {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? wp_unslash((string)$_SERVER['HTTP_HOST']) : parse_url(home_url('/'), PHP_URL_HOST);
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '/';
    return esc_url_raw($scheme . $host . $uri);
  }
}
