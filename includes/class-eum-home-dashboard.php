<?php
if (!defined('ABSPATH')) exit;

class EUM_Home_Dashboard {
  const OPTION_KEY = 'eum_home_dashboard_settings';
  const PAGE_SLUG = 'eum-home-dashboard';

  private static $front_assets_printed = false;

  public function init(): void {
    add_shortcode('eum_home_dashboard', [$this, 'shortcode']);
    add_action('admin_menu', [$this, 'menu'], 46);
    add_action('admin_post_eum_home_dashboard_save', [$this, 'handle_save']);
  }

  public function menu(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) return;
    add_submenu_page(EUM_Admin::parent_slug('experience'), 'Homepage Dashboard', 'Homepage Dashboard', 'read', self::PAGE_SLUG, [$this, 'render_admin']);
  }

  public function handle_save(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');
    if (!isset($_POST['eum_home_dashboard_nonce']) || !wp_verify_nonce($_POST['eum_home_dashboard_nonce'], 'eum_home_dashboard_save')) wp_die('Bad nonce.');

    $settings = $this->normalize_settings(isset($_POST['settings']) ? (array)$_POST['settings'] : [], false);
    update_option(self::OPTION_KEY, $settings, false);
    $this->redirect_admin('Homepage dashboard saved.');
  }

  private function get_settings(): array {
    $saved = get_option(self::OPTION_KEY, null);
    return is_array($saved) ? $this->normalize_settings($saved, true) : $this->normalize_settings($this->default_settings(), true);
  }

  private function normalize_settings($raw, bool $with_defaults): array {
    $defaults = $this->default_settings();
    $raw = is_array($raw) ? $raw : [];
    $panels = [];
    if (isset($raw['panels']) && is_array($raw['panels'])) {
      foreach ($raw['panels'] as $panel_raw) {
        $panel = $this->sanitize_panel($panel_raw);
        if (!empty($panel)) $panels[] = $panel;
      }
    } elseif ($with_defaults) {
      $panels = $defaults['panels'];
    }
    return [
      'welcome_eyebrow' => isset($raw['welcome_eyebrow']) ? sanitize_text_field((string)$raw['welcome_eyebrow']) : ($with_defaults ? $defaults['welcome_eyebrow'] : ''),
      'welcome_title' => isset($raw['welcome_title']) ? sanitize_text_field((string)$raw['welcome_title']) : ($with_defaults ? $defaults['welcome_title'] : ''),
      'welcome_subtitle' => isset($raw['welcome_subtitle']) ? sanitize_textarea_field((string)$raw['welcome_subtitle']) : ($with_defaults ? $defaults['welcome_subtitle'] : ''),
      'panels' => $panels,
    ];
  }

  private function sanitize_panel($raw): array {
    if (!is_array($raw)) return [];
    $panel = array_merge($this->empty_panel(), $raw);
    $roles = $this->role_options();
    $groups = $this->taxonomy_option_map('eum_user_group');
    $job_paths = $this->taxonomy_option_map('eum_job_path');

    $type = sanitize_key((string)$panel['type']);
    if (in_array($type, ['dynamic_links', 'learning', 'notifications'], true)) return [];
    if (!in_array($type, ['profile', 'needs_attention'], true)) $type = 'profile';
    $size = sanitize_key((string)$panel['size']);
    if (!in_array($size, ['standard', 'wide', 'full'], true)) $size = 'standard';
    $theme = sanitize_key((string)$panel['theme']);
    if (!in_array($theme, ['ocean', 'emerald', 'sunrise', 'slate'], true)) $theme = 'ocean';
    $title = sanitize_text_field((string)$panel['title']);
    if ($title === '') {
      if ($type === 'profile') {
        $title = 'Your Information';
      } elseif ($type === 'needs_attention') {
        $title = 'Action Items';
      }
    }

    return [
      'title' => $title,
      'subtitle' => sanitize_text_field((string)$panel['subtitle']),
      'type' => $type,
      'size' => $size,
      'theme' => $theme,
      'visibility_roles' => array_values(array_filter(array_map('sanitize_text_field', (array)$panel['visibility_roles']), static function ($value) use ($roles) { return isset($roles[$value]); })),
      'visibility_groups' => array_values(array_filter(array_map('intval', (array)$panel['visibility_groups']), static function ($value) use ($groups) { return $value > 0 && isset($groups[$value]); })),
      'visibility_job_paths' => array_values(array_filter(array_map('intval', (array)$panel['visibility_job_paths']), static function ($value) use ($job_paths) { return $value > 0 && isset($job_paths[$value]); })),
      'visibility_mode' => in_array((string)$panel['visibility_mode'], ['any', 'all'], true) ? (string)$panel['visibility_mode'] : 'any',
      'require_rules' => !empty($panel['require_rules']) ? '1' : '0',
    ];
  }

  private function empty_panel(): array {
    return ['title' => '', 'subtitle' => '', 'type' => 'profile', 'size' => 'standard', 'theme' => 'ocean', 'visibility_roles' => [], 'visibility_groups' => [], 'visibility_job_paths' => [], 'visibility_mode' => 'any', 'require_rules' => '0'];
  }

  private function default_settings(): array {
    return ['welcome_eyebrow' => 'Enterprise Homepage', 'welcome_title' => 'Hello {{first_name}}', 'welcome_subtitle' => 'A cleaner workspace with the tools, forms, and links that matter to your role.', 'panels' => $this->default_panels()];
  }

  private function default_panels(): array {
    $panels = [[
      'title' => 'Your Information', 'subtitle' => 'Quick profile, reporting, and workspace details.', 'type' => 'profile', 'size' => 'wide', 'theme' => 'ocean', 'visibility_roles' => ['special:logged_in', 'special:terminated'], 'visibility_groups' => [], 'visibility_job_paths' => [], 'visibility_mode' => 'any', 'require_rules' => '0'
    ]];

    $panels[] = [
      'title' => 'Action Items',
      'subtitle' => 'Approvals and requests waiting on you.',
      'type' => 'needs_attention',
      'size' => 'full',
      'theme' => 'slate',
      'visibility_roles' => ['special:manager', 'special:it', 'special:hr'],
      'visibility_groups' => [],
      'visibility_job_paths' => [],
      'visibility_mode' => 'any',
      'require_rules' => '0',
    ];

    return $panels;
  }

  private function role_options(): array {
    $options = ['special:logged_in' => 'All Active Users', 'special:manager' => 'Managers', 'special:it' => 'IT', 'special:hr' => 'HR', 'special:finance' => 'Finance', 'special:terminated' => 'Terminated Users', 'special:external_user' => 'External Users', 'special:artist_creator' => 'Artist Creators', 'special:podcast_manager' => 'Podcast Managers', 'special:podcast_member' => 'Podcast Members'];
    if (class_exists('EUM_Workforce_Lifecycle')) {
      foreach ((array)EUM_Workforce_Lifecycle::get_settings()['external_orgs'] as $org) {
        if (empty($org['key']) || empty($org['label'])) continue;
        $options['external_org:' . sanitize_key((string)$org['key'])] = 'External Org: ' . sanitize_text_field((string)$org['label']);
      }
    }
    if (function_exists('wp_roles') && wp_roles()) {
      foreach ((array)wp_roles()->roles as $key => $row) {
        $options['wp:' . sanitize_key((string)$key)] = 'WP Role: ' . sanitize_text_field((string)($row['name'] ?? $key));
      }
    }
    return $options;
  }

  private function taxonomy_option_map(string $taxonomy): array {
    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) return [];
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'number' => 0]);
    if (is_wp_error($terms) || empty($terms)) return [];
    $map = [];
    foreach ($terms as $term) $map[(int)$term->term_id] = $this->term_full_path($taxonomy, (int)$term->term_id);
    return $map;
  }

  private function term_full_path(string $taxonomy, int $term_id): string {
    if (class_exists('EUM_Profiles') && method_exists('EUM_Profiles', 'term_full_path')) return (string)EUM_Profiles::term_full_path($taxonomy, $term_id, ' / ');
    $term = get_term($term_id, $taxonomy);
    return ($term && !is_wp_error($term)) ? (string)$term->name : '';
  }

  private function current_user_context(int $user_id): array {
    $user = get_user_by('id', $user_id);
    $access = class_exists('EUM_Dynamic_Links') && method_exists('EUM_Dynamic_Links', 'access_context') ? EUM_Dynamic_Links::access_context($user_id) : ['job_path_ids' => [], 'group_ids' => []];
    $is_terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
    $tokens = [$is_terminated ? 'special:terminated' : 'special:logged_in'];
    if ($user instanceof WP_User) foreach ((array)$user->roles as $role) $tokens[] = 'wp:' . sanitize_key((string)$role);
    if (class_exists('EUM_Access')) {
      if (EUM_Access::is_manager_user($user_id)) $tokens[] = 'special:manager';
      if (EUM_Access::is_it_user($user_id)) $tokens[] = 'special:it';
      if (EUM_Access::is_hr_user($user_id)) $tokens[] = 'special:hr';
      if (EUM_Access::is_finance_user($user_id)) $tokens[] = 'special:finance';
      if (EUM_Access::is_artist_creator($user_id)) $tokens[] = 'special:artist_creator';
      if (EUM_Access::is_podcast_manager($user_id)) $tokens[] = 'special:podcast_manager';
      if (EUM_Access::is_podcast_member($user_id)) $tokens[] = 'special:podcast_member';
    }
    if (class_exists('EUM_Profiles') && in_array(EUM_Profiles::worker_type_for_user($user_id), ['external_user', 'partner', 'vendor'], true)) $tokens[] = 'special:external_user';
    $external_org_key = class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::external_org_key_for_user($user_id) : '';
    if ($external_org_key !== '') $tokens[] = 'external_org:' . $external_org_key;
    return ['user' => $user, 'role_tokens' => array_values(array_unique($tokens)), 'external_org_key' => $external_org_key, 'job_path_ids' => array_values(array_filter(array_map('intval', (array)($access['job_path_ids'] ?? [])))), 'group_ids' => array_values(array_filter(array_map('intval', (array)($access['group_ids'] ?? []))))];
  }

  private function panel_is_visible_to_context(array $panel, array $context): bool {
    $roles = (array)($panel['visibility_roles'] ?? []);
    $groups = array_map('intval', (array)($panel['visibility_groups'] ?? []));
    $job_paths = array_map('intval', (array)($panel['visibility_job_paths'] ?? []));
    $mode = in_array((string)($panel['visibility_mode'] ?? 'any'), ['any', 'all'], true) ? (string)$panel['visibility_mode'] : 'any';
    $has_rules = !empty($roles) || !empty($groups) || !empty($job_paths);
    $role_match = !empty($roles) && !empty(array_intersect($roles, (array)($context['role_tokens'] ?? [])));
    $group_match = !empty($groups) && !empty(array_intersect($groups, (array)($context['group_ids'] ?? [])));
    $job_match = !empty($job_paths) && !empty(array_intersect($job_paths, (array)($context['job_path_ids'] ?? [])));
    if ($mode === 'all') {
      $allowed = true;
      if (!empty($roles)) $allowed = $allowed && $role_match;
      if (!empty($groups)) $allowed = $allowed && $group_match;
      if (!empty($job_paths)) $allowed = $allowed && $job_match;
      return $has_rules ? $allowed : ((string)($panel['require_rules'] ?? '0') !== '1');
    }
    $allowed = $role_match || $group_match || $job_match;
    if (!$has_rules) $allowed = ((string)($panel['require_rules'] ?? '0') !== '1');
    return $allowed;
  }

  public function render_admin(): void {
    if (!class_exists('EUM_Access') || !EUM_Access::current_user_is_it()) wp_die('Not allowed.');

    $settings = $this->get_settings();
    $catalogs = [
      'groups' => $this->taxonomy_option_map('eum_user_group'),
      'job_paths' => $this->taxonomy_option_map('eum_job_path'),
      'roles' => $this->role_options(),
    ];

    if (!empty($_GET['eum_msg'])) {
      $class = sanitize_key((string)($_GET['eum_type'] ?? 'success')) === 'error' ? 'notice notice-error' : 'notice notice-success';
      echo '<div class="' . esc_attr($class) . '"><p>' . esc_html(sanitize_text_field((string)$_GET['eum_msg'])) . '</p></div>';
    }

    $this->render_admin_assets();
    ?>
    <div class="wrap eum-home-admin">
      <h1>Homepage Dashboard</h1>
      <p class="description">Manage the homepage’s profile and action panels. Notifications and Messages are available from the portal top bar. Dynamic Links are selected in Appearance &rarr; Menus instead; each link’s access rules are applied when the menu is shown.</p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eum_home_dashboard_save">
        <?php wp_nonce_field('eum_home_dashboard_save', 'eum_home_dashboard_nonce'); ?>

        <div class="eum-home-admin__layout">
          <div class="eum-home-admin__main">
            <section class="eum-home-admin__card">
              <h2>Hero</h2>
              <p>Use <code>{{display_name}}</code>, <code>{{first_name}}</code>, <code>{{job_title}}</code>, <code>{{job_company}}</code>, and <code>{{job_path}}</code>.</p>
              <div class="eum-home-admin__grid">
                <label><span>Eyebrow</span><input type="text" name="settings[welcome_eyebrow]" value="<?php echo esc_attr((string)$settings['welcome_eyebrow']); ?>"></label>
                <label><span>Title</span><input type="text" name="settings[welcome_title]" value="<?php echo esc_attr((string)$settings['welcome_title']); ?>"></label>
                <label class="eum-home-admin__full"><span>Subtitle</span><textarea name="settings[welcome_subtitle]" rows="3"><?php echo esc_textarea((string)$settings['welcome_subtitle']); ?></textarea></label>
              </div>
            </section>

            <section class="eum-home-admin__card">
              <div class="eum-home-admin__head">
                <div>
                  <h2>Panels</h2>
                  <p>Add and reorder the information and action panels shown on the homepage.</p>
                </div>
                <button type="button" class="button button-secondary" data-eum-home-add-panel="1">Add Panel</button>
              </div>
              <div class="eum-home-admin__panels" data-eum-home-panels="1">
                <?php foreach ((array)$settings['panels'] as $index => $panel) echo $this->render_panel_editor($panel, (string)$index, $catalogs); ?>
              </div>
            </section>
          </div>

          <aside class="eum-home-admin__side">
            <section class="eum-home-admin__card">
              <h2>Notes</h2>
              <ul class="eum-home-admin__notes">
                <li>Panel visibility is controlled by the selected roles, groups, and job paths.</li>
                <li>Dynamic Links are available in the <strong>Dynamic Links</strong> box under Appearance &rarr; Menus.</li>
                <li>Dynamic Link access rules are checked for each user when the navigation menu is displayed.</li>
              </ul>
              <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=eum-dynamic-links')); ?>">Manage Dynamic Links</a></p>
            </section>
          </aside>
        </div>

        <p><button type="submit" class="button button-primary button-large">Save Homepage Dashboard</button></p>
      </form>

      <template id="eum-home-panel-template"><?php echo $this->render_panel_editor($this->empty_panel(), '__INDEX__', $catalogs); ?></template>
    </div>
    <?php
  }

  public function shortcode($atts = []): string {
    if (!is_user_logged_in()) return '<div class="eum-home-dashboard eum-home-dashboard--locked"><p>Please log in to view this dashboard.</p></div>';

    $user_id = (int)get_current_user_id();
    $settings = $this->get_settings();
    $context = $this->current_user_context($user_id);
    $user = $context['user'];
    if (!$user instanceof WP_User) return '';

    $panels = [];
    foreach ((array)$settings['panels'] as $panel) {
      if (!$this->panel_is_visible_to_context($panel, $context)) continue;
      $html = $this->render_panel($panel, $user_id);
      if ($html !== '') $panels[] = $html;
    }
    $external_panel = $this->render_external_org_panel_for_context($context);
    if ($external_panel !== '') $panels[] = $external_panel;

    $job_title = class_exists('EUM_Profiles') ? EUM_Profiles::preferred_job_title_label($user_id) : '';
    $job_company = class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'core:job_company') : '';
    $job_path = class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'tax:eum_job_path') : '';

    $hero_title = $this->apply_tokens((string)$settings['welcome_title'], $user_id, $user);
    if ($hero_title === '') $hero_title = (string)$user->display_name;

    ob_start();
    echo $this->front_assets();
    ?>
    <section class="eum-home-dashboard">
      <div class="eum-home-dashboard__shell">
        <div class="eum-home-dashboard__hero">
          <div>
            <?php if ((string)$settings['welcome_eyebrow'] !== '') : ?><div class="eum-home-dashboard__eyebrow"><?php echo esc_html($this->apply_tokens((string)$settings['welcome_eyebrow'], $user_id, $user)); ?></div><?php endif; ?>
            <h1><?php echo esc_html($hero_title); ?></h1>
            <?php if ((string)$settings['welcome_subtitle'] !== '') : ?><p><?php echo esc_html($this->apply_tokens((string)$settings['welcome_subtitle'], $user_id, $user)); ?></p><?php endif; ?>
            <div class="eum-home-dashboard__chips">
              <?php if ($job_title !== '') : ?><span><?php echo esc_html($job_title); ?></span><?php endif; ?>
              <?php if ($job_company !== '') : ?><span><?php echo esc_html($job_company); ?></span><?php endif; ?>
              <?php if ($job_path !== '') : ?><span><?php echo esc_html($job_path); ?></span><?php endif; ?>
            </div>
          </div>
          <div class="eum-home-dashboard__hero-card">
            <strong><?php echo (int)count($panels); ?></strong>
            <span>visible panels</span>
          </div>
        </div>
        <div class="eum-home-dashboard__grid">
          <?php echo empty($panels) ? '<section class="eum-home-card eum-home-card--full is-slate"><div class="eum-home-card__body"><p class="eum-home-card__empty">No panels are available for your access.</p></div></section>' : implode('', $panels); ?>
        </div>
      </div>
    </section>
    <?php
    return (string)ob_get_clean();
  }
  private function render_panel(array $panel, int $user_id): string {
    $type = (string)$panel['type'];
    if ($type === 'profile') {
      $body = $this->render_profile_panel($user_id);
    } elseif ($type === 'needs_attention') {
      $body = $this->render_needs_attention_panel($user_id);
    } else {
      return '';
    }
    if ($body === '') return '';
    ob_start(); ?>
    <section class="eum-home-card eum-home-card--<?php echo esc_attr((string)$panel['size']); ?> is-<?php echo esc_attr((string)$panel['theme']); ?>">
      <div class="eum-home-card__header"><h2><?php echo esc_html((string)$panel['title']); ?></h2><?php if ((string)$panel['subtitle'] !== '') : ?><p><?php echo esc_html((string)$panel['subtitle']); ?></p><?php endif; ?></div>
      <div class="eum-home-card__body"><?php echo $body; ?></div>
    </section>
    <?php return (string)ob_get_clean();
  }

  private function render_profile_panel(int $user_id): string {
    $user = get_user_by('id', $user_id);
    if (!$user) return '';
    $is_terminated = class_exists('EUM_Personnel') && EUM_Personnel::is_terminated($user_id);
    if ($is_terminated) return $this->render_alumni_profile_panel($user_id, $user);
    $job_title = class_exists('EUM_Profiles') ? EUM_Profiles::preferred_job_title_label($user_id) : '';
    $job_company = class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'core:job_company') : '';
    $job_path = class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'tax:eum_job_path') : '';
    $manager_id = class_exists('EUM_Personnel') ? EUM_Personnel::effective_manager_user_id($user_id) : 0;
    $manager = $manager_id > 0 ? get_user_by('id', $manager_id) : false;
    $rows = [
      ['Name', (string)$user->display_name],
      ['Employee ID', class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_id_for_user($user_id) : ''],
      ['Job', trim($job_title . ($job_company !== '' ? ', ' . $job_company : ''), ', ')],
      ['Work Email', (string)$user->user_email],
      ['Job Path', $job_path],
      ['Direct Manager', $manager ? (string)$manager->display_name : 'Not assigned'],
      ['Manager Email', $manager ? (string)$manager->user_email : ''],
    ];
    ob_start(); ?>
    <div class="eum-home-profile">
      <?php foreach ($rows as $row) : if ((string)$row[1] === '') continue; ?>
        <div class="eum-home-profile__row"><span><?php echo esc_html((string)$row[0]); ?></span><strong><?php echo esc_html((string)$row[1]); ?></strong></div>
      <?php endforeach; ?>
    </div>
    <?php return (string)ob_get_clean();
  }

  private function render_alumni_profile_panel(int $user_id, WP_User $user): string {
    $fields = class_exists('EUM_Fields') ? (array)get_user_meta($user_id, EUM_Fields::USER_META_KEY, true) : [];
    $rows = [
      ['Name', (string)$user->display_name],
      ['Employee ID', class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employee_id_for_user($user_id) : ''],
      ['Status', class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employment_status_label_for_user($user_id, $user_id) : 'Alumni/Former Staff'],
      ['Personal Email', sanitize_email((string)($fields['personal_email'] ?? ''))],
      ['Employment Time', class_exists('EUM_Workforce_Lifecycle') ? EUM_Workforce_Lifecycle::employment_duration_label($user_id) : ''],
    ];
    ob_start(); ?>
    <div class="eum-home-profile eum-home-profile--alumni">
      <?php foreach ($rows as $row) : if ((string)$row[1] === '') continue; ?>
        <div class="eum-home-profile__row"><span><?php echo esc_html((string)$row[0]); ?></span><strong><?php echo esc_html((string)$row[1]); ?></strong></div>
      <?php endforeach; ?>
    </div>
    <?php return (string)ob_get_clean();
  }

  private function render_external_org_panel_for_context(array $context): string {
    $key = sanitize_key((string)($context['external_org_key'] ?? ''));
    if ($key === '' || !class_exists('EUM_Workforce_Lifecycle')) return '';
    $org = EUM_Workforce_Lifecycle::external_org_by_key($key);
    if (empty($org)) return '';
    $title = (string)($org['panel_title'] ?? $org['label']);
    $body = (string)($org['panel_body'] ?? '');
    if ($title === '' && $body === '') return '';
    ob_start(); ?>
    <section class="eum-home-card eum-home-card--wide is-slate">
      <div class="eum-home-card__header"><h2><?php echo esc_html($title); ?></h2></div>
      <div class="eum-home-card__body"><?php echo $body !== '' ? wp_kses_post(wpautop($body)) : '<p class="eum-home-card__empty">No organization information has been added yet.</p>'; ?></div>
    </section>
    <?php return (string)ob_get_clean();
  }

  private function render_needs_attention_panel(int $user_id): string {
    $profile_changes = $this->pending_profile_change_items_for_user($user_id, 6);
    $leave_requests = $this->pending_leave_items_for_user($user_id, 6);
    $availability_requests = $this->pending_availability_items_for_user($user_id, 6);
    $total = count($profile_changes) + count($leave_requests) + count($availability_requests);
    $return_url = $this->current_front_url();

    if ($total <= 0) {
      return '<p class="eum-home-card__empty">No action items are waiting on you right now.</p>';
    }

    ob_start(); ?>
    <div class="eum-home-attention">
      <div class="eum-home-attention__summary">
        <strong><?php echo (int)$total; ?></strong>
        <span>items waiting on you</span>
      </div>

      <?php if (!empty($profile_changes)) : ?>
        <section class="eum-home-attention__section">
          <div class="eum-home-attention__section-head">
            <h3>Profile Changes</h3>
            <?php if (class_exists('EUM_Change_Dashboard')) : ?>
              <a href="<?php echo esc_url(admin_url('index.php?page=' . EUM_Change_Dashboard::PAGE_SLUG . '&queue=approval')); ?>">Open Queue</a>
            <?php endif; ?>
          </div>
          <div class="eum-home-attention__list">
            <?php foreach ($profile_changes as $item) : ?>
              <article class="eum-home-attention__item">
                <div class="eum-home-attention__copy">
                  <strong><?php echo esc_html((string)$item['title']); ?></strong>
                  <span><?php echo esc_html((string)$item['meta']); ?></span>
                </div>
                <div class="eum-home-attention__actions">
                  <?php echo $this->profile_change_action_form((int)$item['id'], 'approve', 'Approve', $return_url); ?>
                  <?php echo $this->profile_change_action_form((int)$item['id'], 'reject', 'Reject', $return_url, true); ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if (!empty($leave_requests)) : ?>
        <section class="eum-home-attention__section">
          <div class="eum-home-attention__section-head">
            <h3>Leave Requests</h3>
          </div>
          <div class="eum-home-attention__list">
            <?php foreach ($leave_requests as $item) : ?>
              <article class="eum-home-attention__item">
                <div class="eum-home-attention__copy">
                  <strong><?php echo esc_html((string)$item['title']); ?></strong>
                  <span><?php echo esc_html((string)$item['meta']); ?></span>
                </div>
                <div class="eum-home-attention__actions">
                  <?php echo $this->leave_request_action_form((int)$item['id'], 'approve', 'Approve', $return_url); ?>
                  <?php echo $this->leave_request_action_form((int)$item['id'], 'reject', 'Reject', $return_url, true); ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if (!empty($availability_requests)) : ?>
        <section class="eum-home-attention__section">
          <div class="eum-home-attention__section-head">
            <h3>Availability Requests</h3>
          </div>
          <div class="eum-home-attention__list">
            <?php foreach ($availability_requests as $item) : ?>
              <article class="eum-home-attention__item">
                <div class="eum-home-attention__copy">
                  <strong><?php echo esc_html((string)$item['title']); ?></strong>
                  <span><?php echo esc_html((string)$item['meta']); ?></span>
                </div>
                <div class="eum-home-attention__actions">
                  <?php echo $this->availability_request_action_form((int)$item['id'], 'approve', 'Approve', $return_url); ?>
                  <?php echo $this->availability_request_action_form((int)$item['id'], 'reject', 'Reject', $return_url, true); ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
  }

  private function pending_profile_change_items_for_user(int $user_id, int $limit = 6): array {
    if ($user_id <= 0 || !class_exists('EUM_Profiles')) return [];
    $profile = new EUM_Profiles();
    $items = get_posts([
      'post_type' => EUM_Profiles::CPT_CHANGE,
      'post_status' => 'publish',
      'numberposts' => max(1, $limit * 4),
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        ['key' => EUM_Profiles::META_STATUS, 'value' => 'pending'],
      ],
    ]);
    $out = [];
    foreach ((array)$items as $item) {
      $change_id = (int)$item->ID;
      if (!$profile->can_approve_change($change_id, $user_id)) continue;
      $target_user_id = (int)get_post_meta($change_id, EUM_Profiles::META_TARGET_USER, true);
      $target_user = $target_user_id > 0 ? get_user_by('id', $target_user_id) : false;
      $reason = sanitize_text_field((string)get_post_meta($change_id, EUM_Profiles::META_NOTE, true));
      $effective_at = sanitize_text_field((string)get_post_meta($change_id, EUM_Profiles::META_EFFECTIVE_AT, true));
      $meta = [];
      if ($target_user) $meta[] = (string)($target_user->display_name ?: $target_user->user_login);
      if ($reason !== '') $meta[] = $reason;
      if ($effective_at !== '') $meta[] = 'Effective ' . $effective_at;
      $out[] = [
        'id' => $change_id,
        'title' => (string)$item->post_title,
        'meta' => implode(' | ', array_filter($meta)),
      ];
      if (count($out) >= $limit) break;
    }
    return $out;
  }

  private function pending_leave_items_for_user(int $user_id, int $limit = 6): array {
    if ($user_id <= 0 || !class_exists('EUM_Workforce_Lifecycle')) return [];
    $items = get_posts([
      'post_type' => EUM_Workforce_Lifecycle::CPT_LEAVE,
      'post_status' => 'publish',
      'numberposts' => max(1, $limit * 4),
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        ['key' => EUM_Workforce_Lifecycle::META_STATUS, 'value' => 'pending'],
      ],
    ]);
    $out = [];
    foreach ((array)$items as $item) {
      $leave_id = (int)$item->ID;
      if (!$this->can_dashboard_approve_leave($user_id, $leave_id)) continue;
      $target_user_id = (int)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_TARGET_USER, true);
      $type = EUM_Workforce_Lifecycle::leave_type((string)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_TYPE, true));
      $start = sanitize_text_field((string)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_START_DATE, true));
      $return = sanitize_text_field((string)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_RETURN_DATE, true));
      $out[] = [
        'id' => $leave_id,
        'title' => ($type['label'] ?? 'Leave Request') . ' - ' . $this->employee_label($target_user_id),
        'meta' => trim($start . ($return !== '' ? ' to ' . $return : '')),
      ];
      if (count($out) >= $limit) break;
    }
    return $out;
  }

  private function pending_availability_items_for_user(int $user_id, int $limit = 6): array {
    if ($user_id <= 0 || !class_exists('EUM_Workforce_Lifecycle')) return [];
    $items = get_posts([
      'post_type' => EUM_Workforce_Lifecycle::CPT_AVAILABILITY,
      'post_status' => 'publish',
      'numberposts' => max(1, $limit * 4),
      'orderby' => 'date',
      'order' => 'DESC',
      'meta_query' => [
        ['key' => EUM_Workforce_Lifecycle::AV_META_STATUS, 'value' => 'pending'],
      ],
    ]);
    $out = [];
    foreach ((array)$items as $item) {
      $request_id = (int)$item->ID;
      if (!$this->can_dashboard_approve_availability($user_id, $request_id)) continue;
      $target_user_id = (int)get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_TARGET_USER, true);
      $scope = sanitize_key((string)get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_SCOPE, true));
      $date_label = $scope === 'default'
        ? ('Default as of ' . sanitize_text_field((string)get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_AS_OF_DATE, true)))
        : ('Week of ' . sanitize_text_field((string)get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_WEEK_START, true)));
      $out[] = [
        'id' => $request_id,
        'title' => 'Availability - ' . $this->employee_label($target_user_id),
        'meta' => $date_label,
      ];
      if (count($out) >= $limit) break;
    }
    return $out;
  }

  private function can_dashboard_approve_leave(int $actor_user_id, int $leave_id): bool {
    if ($actor_user_id <= 0 || $leave_id <= 0 || !class_exists('EUM_Workforce_Lifecycle')) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) return true;
    $target_user_id = (int)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_TARGET_USER, true);
    $requestor_id = (int)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_ACTOR_USER, true);
    if ($actor_user_id === $target_user_id) return false;
    $type = EUM_Workforce_Lifecycle::leave_type((string)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_TYPE, true));
    $roles = (array)($type['approver_roles'] ?? []);
    $approver_ids = array_map('intval', (array)get_post_meta($leave_id, EUM_Workforce_Lifecycle::META_APPROVER_IDS, true));
    if (in_array($actor_user_id, $approver_ids, true)) return true;
    if (in_array('next_level_manager', $roles, true) && class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $target_user_id)) return true;
    if (in_array('request_manager', $roles, true) && class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $requestor_id)) return true;
    return false;
  }

  private function can_dashboard_approve_availability(int $actor_user_id, int $request_id): bool {
    if ($actor_user_id <= 0 || $request_id <= 0 || !class_exists('EUM_Workforce_Lifecycle')) return false;
    if (class_exists('EUM_Access') && EUM_Access::is_it_or_hr($actor_user_id)) return true;
    $target_user_id = (int)get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_TARGET_USER, true);
    $requestor_id = (int)get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_ACTOR_USER, true);
    if ($target_user_id <= 0 || $actor_user_id === $target_user_id || $actor_user_id === $requestor_id) return false;
    $approver_ids = array_map('intval', (array)get_post_meta($request_id, EUM_Workforce_Lifecycle::AV_META_APPROVER_IDS, true));
    if (in_array($actor_user_id, $approver_ids, true)) return true;
    return class_exists('EUM_Profiles') && EUM_Profiles::is_manager_of_target($actor_user_id, $target_user_id);
  }

  private function profile_change_action_form(int $change_id, string $action, string $label, string $return_url, bool $danger = false): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-home-attention__form">';
    echo '<input type="hidden" name="action" value="eum_profile_change_action">';
    echo '<input type="hidden" name="change_id" value="' . (int)$change_id . '">';
    echo '<input type="hidden" name="change_action" value="' . esc_attr($action) . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_profile_change_action_' . $change_id, 'eum_profile_action_nonce');
    echo '<button type="submit" class="button button-small' . ($danger ? '' : ' button-primary') . '">' . esc_html($label) . '</button>';
    echo '</form>';
    return (string)ob_get_clean();
  }

  private function leave_request_action_form(int $leave_id, string $action, string $label, string $return_url, bool $danger = false): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-home-attention__form">';
    echo '<input type="hidden" name="action" value="eum_leave_action">';
    echo '<input type="hidden" name="leave_id" value="' . (int)$leave_id . '">';
    echo '<input type="hidden" name="leave_action" value="' . esc_attr($action) . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_leave_action_' . $leave_id, 'eum_leave_action_nonce');
    echo '<button type="submit" class="button button-small' . ($danger ? '' : ' button-primary') . '">' . esc_html($label) . '</button>';
    echo '</form>';
    return (string)ob_get_clean();
  }

  private function availability_request_action_form(int $request_id, string $action, string $label, string $return_url, bool $danger = false): string {
    ob_start();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="eum-home-attention__form">';
    echo '<input type="hidden" name="action" value="eum_availability_action">';
    echo '<input type="hidden" name="availability_id" value="' . (int)$request_id . '">';
    echo '<input type="hidden" name="availability_action" value="' . esc_attr($action) . '">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr($return_url) . '">';
    wp_nonce_field('eum_availability_action_' . $request_id, 'eum_availability_action_nonce');
    echo '<button type="submit" class="button button-small' . ($danger ? '' : ' button-primary') . '">' . esc_html($label) . '</button>';
    echo '</form>';
    return (string)ob_get_clean();
  }

  private function employee_label(int $user_id): string {
    if ($user_id <= 0) return 'User #' . $user_id;
    if (class_exists('EUM_Workforce_Lifecycle') && method_exists('EUM_Workforce_Lifecycle', 'employee_list_label')) {
      return (string)EUM_Workforce_Lifecycle::employee_list_label($user_id);
    }
    $user = get_user_by('id', $user_id);
    return $user ? (string)($user->display_name ?: $user->user_login) : ('User #' . $user_id);
  }

  private function apply_tokens(string $text, int $user_id, ?WP_User $user = null): string {
    $user = $user instanceof WP_User ? $user : get_user_by('id', $user_id);
    if (!$user) return trim($text);
    return trim(strtr($text, [
      '{{display_name}}' => (string)$user->display_name,
      '{{first_name}}' => (string)get_user_meta($user_id, 'first_name', true),
      '{{job_title}}' => class_exists('EUM_Profiles') ? EUM_Profiles::preferred_job_title_label($user_id) : '',
      '{{job_company}}' => class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'core:job_company') : '',
      '{{job_path}}' => class_exists('EUM_Profiles') ? EUM_Profiles::get_field_display_value($user_id, 'tax:eum_job_path') : '',
    ]));
  }
  private function render_panel_editor(array $panel, string $index, array $catalogs): string {
    $panel = array_merge($this->empty_panel(), $panel);
    ob_start(); ?>
    <section class="eum-home-admin__panel" data-eum-home-panel="1">
      <div class="eum-home-admin__panel-head"><strong data-eum-panel-number="1">Panel</strong><div><button type="button" class="button button-small" data-eum-home-move="up">Up</button> <button type="button" class="button button-small" data-eum-home-move="down">Down</button> <button type="button" class="button button-small button-link-delete" data-eum-home-remove="1">Remove</button></div></div>
      <div class="eum-home-admin__grid">
        <label><span>Title</span><input type="text" name="settings[panels][<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr((string)$panel['title']); ?>"></label>
        <label><span>Subtitle</span><input type="text" name="settings[panels][<?php echo esc_attr($index); ?>][subtitle]" value="<?php echo esc_attr((string)$panel['subtitle']); ?>"></label>
        <label><span>Type</span><select name="settings[panels][<?php echo esc_attr($index); ?>][type]"><?php echo $this->select_options(['profile' => 'Profile Information', 'needs_attention' => 'Action Items Queue'], (string)$panel['type']); ?></select></label>
        <label><span>Size</span><select name="settings[panels][<?php echo esc_attr($index); ?>][size]"><?php echo $this->select_options(['standard' => 'Standard', 'wide' => 'Wide', 'full' => 'Full Width'], (string)$panel['size']); ?></select></label>
        <label><span>Theme</span><select name="settings[panels][<?php echo esc_attr($index); ?>][theme]"><?php echo $this->select_options(['ocean' => 'Ocean', 'emerald' => 'Emerald', 'sunrise' => 'Sunrise', 'slate' => 'Slate'], (string)$panel['theme']); ?></select></label>
      </div>
      <div class="eum-home-admin__rules">
        <h3>Visibility</h3>
        <div class="eum-home-admin__grid">
          <label><span>Roles</span><?php echo $this->multi_select($catalogs['roles'], (array)$panel['visibility_roles'], 'settings[panels][' . $index . '][visibility_roles][]'); ?></label>
          <label><span>User Groups</span><?php echo $this->multi_select($catalogs['groups'], array_map('strval', (array)$panel['visibility_groups']), 'settings[panels][' . $index . '][visibility_groups][]'); ?></label>
          <label><span>Job Paths</span><?php echo $this->multi_select($catalogs['job_paths'], array_map('strval', (array)$panel['visibility_job_paths']), 'settings[panels][' . $index . '][visibility_job_paths][]', 7); ?></label>
          <label><span>Match Mode</span><select name="settings[panels][<?php echo esc_attr($index); ?>][visibility_mode]"><?php echo $this->select_options(['any' => 'Match Any Rule', 'all' => 'Match Every Used Rule'], (string)$panel['visibility_mode']); ?></select></label>
          <label class="eum-home-admin__check"><input type="checkbox" name="settings[panels][<?php echo esc_attr($index); ?>][require_rules]" value="1" <?php checked((string)$panel['require_rules'], '1'); ?>><span>Hide when no rules are set</span></label>
        </div>
      </div>
    </section>
    <?php return (string)ob_get_clean();
  }

  private function select_options(array $options, string $selected): string {
    $html = '';
    foreach ($options as $value => $label) $html .= '<option value="' . esc_attr((string)$value) . '" ' . selected((string)$selected, (string)$value, false) . '>' . esc_html((string)$label) . '</option>';
    return $html;
  }

  private function multi_select(array $options, array $selected, string $name, int $size = 6): string {
    $selected = array_map('strval', $selected);
    $html = '<select name="' . esc_attr($name) . '" multiple size="' . (int)$size . '">';
    foreach ($options as $value => $label) $html .= '<option value="' . esc_attr((string)$value) . '" ' . selected(in_array((string)$value, $selected, true), true, false) . '>' . esc_html((string)$label) . '</option>';
    return $html . '</select>';
  }

  private function redirect_admin(string $message, string $type = 'success'): void {
    wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'eum_msg' => $message, 'eum_type' => $type], admin_url('admin.php')));
    exit;
  }

  private function current_front_url(): string {
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash((string)$_SERVER['HTTP_HOST'])) : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '/';
    return esc_url_raw($scheme . $host . $uri);
  }

  private function front_assets(): string {
    if (self::$front_assets_printed) return '';
    self::$front_assets_printed = true;
    return '<style>
      .eum-home-dashboard{max-width:1420px;margin:0 auto;padding:28px 18px 44px;font-family:"Aptos","Segoe UI",sans-serif}
      .eum-home-dashboard__shell{border-radius:32px;padding:26px;background:radial-gradient(circle at top right,rgba(56,189,248,.22),transparent 28%),linear-gradient(135deg,#061a35 0%,#0c2f55 44%,#0d6b8f 100%);box-shadow:0 28px 70px rgba(2,12,27,.26)}
      .eum-home-dashboard__hero{display:grid;grid-template-columns:minmax(0,1.7fr) 240px;gap:18px;margin-bottom:22px;color:#fff}
      .eum-home-dashboard__hero>div,.eum-home-dashboard__hero-card{border:1px solid rgba(255,255,255,.14);background:rgba(7,18,36,.34);border-radius:24px;padding:24px}
      .eum-home-dashboard__eyebrow{display:inline-flex;padding:7px 12px;border-radius:999px;background:rgba(255,255,255,.12);font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase}
      .eum-home-dashboard h1{margin:14px 0 10px;font-size:clamp(2rem,3.8vw,3.3rem);line-height:1;letter-spacing:-.04em;color:#fff}
      .eum-home-dashboard__hero p{margin:0;color:rgba(241,245,249,.84);line-height:1.6}
      .eum-home-dashboard__chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
      .eum-home-dashboard__chips span{display:inline-flex;padding:8px 13px;border-radius:999px;background:rgba(255,255,255,.12);font-size:13px;font-weight:600;color:#f8fafc}
      .eum-home-dashboard__hero-card{display:grid;place-items:center;text-align:center}
      .eum-home-dashboard__hero-card strong{font-size:2rem;color:#fff}
      .eum-home-dashboard__hero-card span{color:#bae6fd;text-transform:uppercase;letter-spacing:.08em;font-size:12px}
      .eum-home-dashboard__grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:18px}
      .eum-home-card{--accent:#38bdf8;grid-column:span 4;border-radius:24px;background:linear-gradient(180deg,rgba(255,255,255,.97),rgba(248,250,252,.93));box-shadow:0 18px 40px rgba(15,23,42,.12);overflow:hidden}
      .eum-home-card--wide{grid-column:span 6}.eum-home-card--full{grid-column:1 / -1}
      .eum-home-card.is-ocean{--accent:#38bdf8}.eum-home-card.is-emerald{--accent:#10b981}.eum-home-card.is-sunrise{--accent:#f59e0b}.eum-home-card.is-slate{--accent:#64748b}
      .eum-home-card__header,.eum-home-card__body{padding:20px 22px}.eum-home-card__header{position:relative}.eum-home-card__header:before{content:"";position:absolute;left:22px;right:22px;top:0;height:5px;border-radius:999px;background:linear-gradient(90deg,var(--accent),transparent)}
      .eum-home-card__header h2{margin:8px 0 4px;color:#0f172a}.eum-home-card__header p,.eum-home-card__empty{margin:0;color:#64748b;line-height:1.55}
      .eum-home-profile{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.eum-home-profile__row{padding:14px 15px;border-radius:18px;background:#f8fafc;border:1px solid #e2e8f0}.eum-home-profile__row span{display:block;margin-bottom:6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#64748b}.eum-home-profile__row strong{display:block;color:#0f172a;line-height:1.5}
      .eum-home-attention{display:grid;gap:18px}.eum-home-attention__summary{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:18px;background:#0f172a;color:#fff}.eum-home-attention__summary strong{font-size:1.7rem;line-height:1}.eum-home-attention__summary span{color:#cbd5e1;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
      .eum-home-attention__section{display:grid;gap:10px}.eum-home-attention__section-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.eum-home-attention__section-head h3{margin:0;color:#0f172a;font-size:1rem}.eum-home-attention__section-head a{font-size:13px;font-weight:700;text-decoration:none}
      .eum-home-attention__list{display:grid;gap:10px}.eum-home-attention__item{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;padding:14px 16px;border-radius:18px;background:#fff8e8;border:1px solid #fde68a}.eum-home-attention__copy{display:grid;gap:4px}.eum-home-attention__copy strong{color:#78350f}.eum-home-attention__copy span{color:#92400e;line-height:1.5}
      .eum-home-attention__actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.eum-home-attention__form{margin:0}
      .eum-home-dashboard--locked{padding:16px;border:1px solid #dbe2ea;border-radius:16px;background:#fff}
      @media (max-width:1180px){.eum-home-dashboard__hero{grid-template-columns:1fr}.eum-home-card{grid-column:span 6}.eum-home-card--wide{grid-column:span 12}}
      @media (max-width:860px){.eum-home-card,.eum-home-card--wide{grid-column:1 / -1}.eum-home-profile{grid-template-columns:1fr}.eum-home-attention__item{flex-direction:column}.eum-home-attention__actions{justify-content:flex-start}}
    </style>';
  }

  private function render_admin_assets(): void {
    ?>
    <style>
      .eum-home-admin__layout{display:grid;grid-template-columns:minmax(0,1.6fr) 320px;gap:20px;align-items:start;margin-top:18px}
      .eum-home-admin__main{display:grid;gap:20px}.eum-home-admin__side{display:grid;gap:20px}
      .eum-home-admin__card{background:#fff;border:1px solid #dcdcde;border-radius:18px;padding:20px;box-shadow:0 12px 28px rgba(15,23,42,.05)}
      .eum-home-admin__head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}
      .eum-home-admin__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 16px}.eum-home-admin__full{grid-column:1 / -1}
      .eum-home-admin label{display:grid;gap:7px;font-weight:600}.eum-home-admin label span{font-size:13px}.eum-home-admin input[type="text"],.eum-home-admin textarea,.eum-home-admin select{width:100%;max-width:none}.eum-home-admin textarea{min-height:84px}.eum-home-admin select[multiple]{min-height:120px}
      .eum-home-admin__notes{margin:0;padding-left:18px;display:grid;gap:10px;color:#475569;line-height:1.6}.eum-home-admin__panels{display:grid;gap:16px}
      .eum-home-admin__panel{border:1px solid #dbe2ea;border-radius:18px;padding:16px;background:linear-gradient(180deg,#fff,#f8fafc)}.eum-home-admin__panel-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid #e5e7eb}
      .eum-home-admin__rules{margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb}.eum-home-admin__check{display:flex;align-items:center;gap:10px;min-height:42px}
      @media (max-width:1180px){.eum-home-admin__layout{grid-template-columns:1fr}}@media (max-width:900px){.eum-home-admin__grid{grid-template-columns:1fr}}
    </style>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var wrap = document.querySelector('[data-eum-home-panels]');
        var add = document.querySelector('[data-eum-home-add-panel]');
        var template = document.getElementById('eum-home-panel-template');
        if (!wrap || !add || !template) return;
        function renumber() {
          wrap.querySelectorAll('[data-eum-home-panel]').forEach(function (panel, index) {
            var label = panel.querySelector('[data-eum-panel-number]');
            if (label) label.textContent = 'Panel ' + (index + 1);
            panel.querySelectorAll('[name]').forEach(function (field) {
              field.name = field.name.replace(/settings\\[panels\\]\\[(?:__INDEX__|\\d+)\\]/g, 'settings[panels][' + index + ']');
            });
          });
        }
        add.addEventListener('click', function () {
          wrap.insertAdjacentHTML('beforeend', template.innerHTML.trim().replace(/__INDEX__/g, String(wrap.children.length)));
          renumber();
        });
        wrap.addEventListener('click', function (event) {
          var panel = event.target.closest('[data-eum-home-panel]');
          if (!panel) return;
          if (event.target.closest('[data-eum-home-remove]')) { panel.remove(); renumber(); return; }
          var move = event.target.closest('[data-eum-home-move]');
          if (!move) return;
          if (move.getAttribute('data-eum-home-move') === 'up' && panel.previousElementSibling) wrap.insertBefore(panel, panel.previousElementSibling);
          if (move.getAttribute('data-eum-home-move') === 'down' && panel.nextElementSibling) wrap.insertBefore(panel.nextElementSibling, panel);
          renumber();
        });
        renumber();
      });
    </script>
    <?php
  }
}

