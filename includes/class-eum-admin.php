<?php
if (!defined('ABSPATH')) exit;

class EUM_Admin {
  const MENU_CORE = 'eum';
  const MENU_WORKFORCE = 'eum-workforce';
  const MENU_EXPERIENCE = 'eum-experience';
  const MENU_FINANCE = 'eum-finance-hub';
  const MENU_INTEGRATIONS = 'eum-integrations';

  public function init(): void {
    add_action('admin_menu', [$this, 'menu'], 20);
  }

  public static function parent_slug(string $section = 'core'): string {
    $sections = self::sections();
    return (string)($sections[$section]['slug'] ?? self::MENU_CORE);
  }

  public static function can_access_section(string $section): bool {
    $user_id = (int)get_current_user_id();
    if ($user_id <= 0 || !current_user_can('read')) return false;

    switch ($section) {
      case 'finance':
        return class_exists('EUM_Access') && (EUM_Access::is_finance_user($user_id) || EUM_Access::is_it_user($user_id));
      case 'core':
      case 'workforce':
      case 'experience':
      case 'integrations':
      default:
        return class_exists('EUM_Access') && EUM_Access::current_user_is_it();
    }
  }

  public static function sections(): array {
    return [
      'core' => [
        'slug' => self::MENU_CORE,
        'menu_title' => 'Enterprise',
        'page_title' => 'Enterprise Workspace',
        'icon' => 'dashicons-id',
        'position' => 58,
        'description' => 'Platform overview, status checks, security tools, and shared admin shortcuts.',
      ],
      'workforce' => [
        'slug' => self::MENU_WORKFORCE,
        'menu_title' => 'Workforce',
        'page_title' => 'Enterprise Workforce',
        'icon' => 'dashicons-groups',
        'position' => 59,
        'description' => 'People structure, job architecture, manager routing, terms, profile policy, and hiring configuration.',
      ],
      'experience' => [
        'slug' => self::MENU_EXPERIENCE,
        'menu_title' => 'Experience',
        'page_title' => 'Enterprise Experience',
        'icon' => 'dashicons-screenoptions',
        'position' => 60,
        'description' => 'Homepage/dashboard presentation, dynamic links, and other front-end experience configuration.',
      ],
      'finance' => [
        'slug' => self::MENU_FINANCE,
        'menu_title' => 'Finance',
        'page_title' => 'Enterprise Finance',
        'icon' => 'dashicons-chart-line',
        'position' => 61,
        'description' => 'Cost centers, budgets, finance records, recurring items, and finance-facing operational tools.',
      ],
      'integrations' => [
        'slug' => self::MENU_INTEGRATIONS,
        'menu_title' => 'Integrations',
        'page_title' => 'Enterprise Integrations',
        'icon' => 'dashicons-admin-links',
        'position' => 62,
        'description' => 'Email, service bridges, cloud platforms, SSO, and other external system configuration.',
      ],
    ];
  }

  public function menu(): void {
    foreach (self::sections() as $section_key => $section) {
      if (!self::can_access_section($section_key)) continue;
      add_menu_page(
        (string)$section['page_title'],
        (string)$section['menu_title'],
        'read',
        (string)$section['slug'],
        [$this, 'page_section_' . $section_key],
        (string)$section['icon'],
        (int)$section['position']
      );
    }

    if (self::can_access_section('core')) {
      add_submenu_page(self::MENU_CORE, 'Status', 'Status', 'read', 'eum-status', [$this, 'page_status']);
    }

    if (self::can_access_section('workforce')) {
      add_submenu_page(self::MENU_WORKFORCE, 'Custom Fields', 'Custom Fields', 'read', 'eum-fields', [$this, 'page_fields_proxy']);
      add_submenu_page(self::MENU_WORKFORCE, 'Bulk Import / Export', 'Bulk Import / Export', 'read', 'eum-bulk', [$this, 'page_bulk_proxy']);
    }
  }

  public function page_section_core(): void {
    $this->render_section_page('core');
  }

  public function page_section_workforce(): void {
    $this->render_section_page('workforce');
  }

  public function page_section_experience(): void {
    $this->render_section_page('experience');
  }

  public function page_section_finance(): void {
    $this->render_section_page('finance');
  }

  public function page_section_integrations(): void {
    $this->render_section_page('integrations');
  }

  private function render_section_page(string $section_key): void {
    if (!self::can_access_section($section_key)) wp_die('Not allowed.');
    $sections = self::sections();
    $section = $sections[$section_key] ?? $sections['core'];

    echo '<div class="wrap">';
    echo '<h1>' . esc_html((string)$section['page_title']) . '</h1>';
    echo '<p>' . esc_html((string)$section['description']) . '</p>';

    $cards = $this->section_cards($section_key);
    if (!empty($cards)) {
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;max-width:1200px;margin-top:18px;">';
      foreach ($cards as $card) {
        $href = (string)($card['href'] ?? '');
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.05);">';
        echo '<h2 style="margin:0 0 8px;font-size:18px;">' . esc_html((string)($card['title'] ?? 'Module')) . '</h2>';
        if (!empty($card['summary'])) {
          echo '<p style="margin:0 0 14px;color:#50575e;">' . esc_html((string)$card['summary']) . '</p>';
        }
        if ($href !== '') {
          echo '<a class="button button-primary" href="' . esc_url($href) . '">Open</a>';
        }
        if (!empty($card['secondary'])) {
          echo '<div style="margin-top:10px;color:#646970;">' . esc_html((string)$card['secondary']) . '</div>';
        }
        echo '</div>';
      }
      echo '</div>';
    }

    if ($section_key === 'core') {
      echo '<div style="margin-top:20px;max-width:1200px;background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px;">';
      echo '<h2 style="margin-top:0;">Developer Docs</h2>';
      echo '<p style="margin:0 0 8px;">This rebuild now keeps handoff notes inside the plugin so future developers or models can pick it up faster.</p>';
      echo '<ul style="margin:0;padding-left:18px;">';
      echo '<li><code>' . esc_html(EUM_PLUGIN_DIR . 'docs\\ARCHITECTURE.md') . '</code></li>';
      echo '<li><code>' . esc_html(EUM_PLUGIN_DIR . 'docs\\ADMIN-MENU-MAP.md') . '</code></li>';
      echo '<li><code>' . esc_html(EUM_PLUGIN_DIR . 'docs\\DTC.md') . '</code></li>';
      echo '<li><code>' . esc_html(EUM_PLUGIN_DIR . 'docs\\PODCASTS.md') . '</code></li>';
      echo '</ul>';
      echo '</div>';
    }

    echo '</div>';
  }

  private function section_cards(string $section_key): array {
    $cards = [
      'core' => [
        [
          'title' => 'Status',
          'summary' => 'Version, access, and taxonomy health checks.',
          'href' => admin_url('admin.php?page=eum-status'),
        ],
        [
          'title' => 'Change Work Queue',
          'summary' => 'Approval-driven staffing and profile changes that surface in the dashboard work queue.',
          'href' => admin_url('index.php?page=' . EUM_Change_Dashboard::PAGE_SLUG),
        ],
        [
          'title' => 'Login As User',
          'summary' => 'Controlled support and troubleshooting impersonation for IT.',
          'href' => admin_url('admin.php?page=eum-login-as'),
        ],
      ],
      'workforce' => [
        [
          'title' => 'Profile Policy',
          'summary' => 'Profile access rules and change tracking.',
          'href' => admin_url('admin.php?page=eum-profile-access'),
        ],
        [
          'title' => 'Profile Changes',
          'summary' => 'Pending and historical profile changes.',
          'href' => admin_url('admin.php?page=eum-profile-changes'),
        ],
        [
          'title' => 'Manager IDs',
          'summary' => 'Branch and title-aware manager routing, mailbox access, and interim coverage.',
          'href' => admin_url('admin.php?page=eum-manager-ids'),
        ],
        [
          'title' => 'Enterprise Terms',
          'summary' => 'Job paths, groups, locations, projects, and related taxonomies.',
          'href' => admin_url('admin.php?page=eum-terms'),
        ],
        [
          'title' => 'Job Architecture',
          'summary' => 'Job titles, job tasks, company divisions, and job boards.',
          'href' => admin_url('admin.php?page=eum-job-titles'),
          'secondary' => 'Also includes Job Tasks, Company Divisions, and Job Boards in this section menu.',
        ],
        [
          'title' => 'LMS',
          'summary' => 'Courses, lessons, required training paths, optional learning, reassignments, and certificates.',
          'href' => admin_url('admin.php?page=' . EUM_LMS::PAGE_SLUG),
          'secondary' => 'Assignments can target groups, job paths, job titles, projects, locations, and direct users.',
        ],
        [
          'title' => 'Bulk Tools',
          'summary' => 'Bulk import/export, visual bulk editing, and custom field definitions.',
          'href' => admin_url('admin.php?page=eum-bulk'),
          'secondary' => 'Visual Bulk Editor also remains available under Users.',
        ],
      ],
      'experience' => [
        [
          'title' => 'Homepage Dashboard',
          'summary' => 'Configure homepage panels, visibility rules, headers, and ordering.',
          'href' => admin_url('admin.php?page=' . EUM_Home_Dashboard::PAGE_SLUG),
        ],
        [
          'title' => 'Dynamic Links',
          'summary' => 'Manage dashboard links, headers, and visibility by role/group/path.',
          'href' => admin_url('admin.php?page=eum-dynamic-links'),
        ],
        [
          'title' => 'Podcasts',
          'summary' => 'Manage podcast ownership, members, episodes, feeds, and finance-path assignment.',
          'href' => admin_url('admin.php?page=' . EUM_Podcasts::PAGE_SLUG),
        ],
        [
          'title' => 'DTC Video Hub',
          'summary' => 'Channels, video projects, versioned assets, snapshots, staffing events, and connector publishing.',
          'href' => admin_url('admin.php?page=' . EUM_DTC::PAGE_SLUG),
        ],
        [
          'title' => 'Fonts',
          'summary' => 'Load brand fonts locally for both the site and admin surfaces.',
          'href' => admin_url('admin.php?page=eum-fonts'),
        ],
      ],
      'finance' => [
        [
          'title' => 'Finance Suite',
          'summary' => 'Records, recurring items, path rollups, budgets, and approval queues.',
          'href' => admin_url('admin.php?page=eum-finance'),
        ],
        [
          'title' => 'Performance Metrics',
          'summary' => 'Path-scoped KPIs with manual fallbacks and API connector settings.',
          'href' => admin_url('index.php?page=' . EUM_Performance_Metrics::PAGE_SLUG),
        ],
        [
          'title' => 'Expected Labor',
          'summary' => 'Projected labor rules, manual additions, and expected-vs-actual weekly modeling.',
          'href' => admin_url('index.php?page=' . EUM_Expected_Labor::PAGE_SLUG),
        ],
        [
          'title' => 'Receipt Queue',
          'summary' => 'Recurring items still waiting on a current-cycle receipt.',
          'href' => admin_url('admin.php?page=eum-finance-receipts'),
        ],
        [
          'title' => 'Cost Centers',
          'summary' => 'Finance paths, paycodes, and cost center budget settings.',
          'href' => admin_url('edit-tags.php?taxonomy=' . EUM_Finance::TAX_FINANCE_PATH . '&post_type=' . EUM_Finance::CPT_RECORD),
        ],
      ],
      'integrations' => [
        [
          'title' => 'Email Notifications',
          'summary' => 'Notifications, reset-email routing, and message templates.',
          'href' => admin_url('admin.php?page=eum-email-notifications'),
        ],
        [
          'title' => 'Tickets',
          'summary' => 'Per-queue mailboxes, inbound ticket creation, and the HR / IT support portal.',
          'href' => admin_url('admin.php?page=' . EUM_Tickets::PAGE_SLUG),
        ],
        [
          'title' => 'Mail & Services',
          'summary' => 'Mail provisioning, webmail launch, and service bridge settings.',
          'href' => admin_url('admin.php?page=eum-mail-services'),
        ],
        [
          'title' => 'Nextcloud',
          'summary' => 'Account provisioning, sync, and login bridge settings.',
          'href' => admin_url('admin.php?page=eum-nextcloud'),
        ],
        [
          'title' => 'Click-to-Login Apps',
          'summary' => 'Configure browser-assisted app logins, manage encrypted credentials, and review app assignments.',
          'href' => admin_url('admin.php?page=eum-click-login'),
        ],
        [
          'title' => 'SSO / OIDC',
          'summary' => 'Shared sign-in credentials and redirect URLs for Site Manager connected sites and Nextcloud.',
          'href' => admin_url('admin.php?page=eum-oidc'),
        ],
      ],
    ];

    return $cards[$section_key] ?? [];
  }

  public function page_status(): void {
    if (!self::can_access_section('core')) wp_die('Not allowed.');

    $user = wp_get_current_user();

    echo '<div class="wrap"><h1>EUM Status</h1>';
    echo '<table class="widefat striped" style="max-width:900px;"><tbody>';
    echo '<tr><td><strong>Plugin Version</strong></td><td>' . esc_html(defined('EUM_VERSION') ? EUM_VERSION : 'unknown') . '</td></tr>';
    echo '<tr><td><strong>Current User</strong></td><td>' . esc_html($user->user_login) . ' (#' . (int)$user->ID . ')</td></tr>';
    echo '<tr><td><strong>IT Access?</strong></td><td>' . (EUM_Access::current_user_is_it() ? 'YES' : 'NO') . '</td></tr>';
    echo '<tr><td><strong>HR Access?</strong></td><td>' . (EUM_Access::current_user_is_hr() ? 'YES' : 'NO') . '</td></tr>';
    echo '<tr><td><strong>Finance Access?</strong></td><td>' . (EUM_Access::is_finance_user((int)$user->ID) ? 'YES' : 'NO') . '</td></tr>';
    echo '<tr><td><strong>Taxonomy exists (eum_job_path)</strong></td><td>' . (taxonomy_exists('eum_job_path') ? 'YES' : 'NO') . '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';
  }

  public function page_bulk_proxy(): void {
    if (!self::can_access_section('workforce')) wp_die('Not allowed.');
    if (class_exists('EUM_Bulk')) (new EUM_Bulk())->render_page();
    else echo '<div class="wrap"><h1>Bulk Import / Export</h1><p>EUM_Bulk class not found.</p></div>';
  }

  public function page_fields_proxy(): void {
    if (!self::can_access_section('workforce')) wp_die('Not allowed.');
    if (class_exists('EUM_Fields_Admin')) (new EUM_Fields_Admin())->render();
    else echo '<div class="wrap"><h1>Custom Fields</h1><p>EUM_Fields_Admin class not found.</p></div>';
  }
}
