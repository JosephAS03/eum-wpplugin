<?php
if (!defined('ABSPATH')) exit;

require_once EUM_PLUGIN_DIR . 'includes/class-eum-module-registry.php';

foreach (EUM_Module_Registry::files() as $file_config) {
  $path = (string)($file_config['path'] ?? '');
  if ($path === '') continue;
  if (!empty($file_config['optional']) && !file_exists($path)) continue;
  require_once $path;
}

class EUM_Plugin {

  public function init(): void {
    register_activation_hook(EUM_PLUGIN_FILE, [$this, 'activate']);
    register_deactivation_hook(EUM_PLUGIN_FILE, [$this, 'deactivate']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

    $this->boot_modules(EUM_Module_Registry::runtime_classes());

    if (is_admin()) {
      $this->boot_modules(EUM_Module_Registry::admin_classes());
    }
  }

  public function enqueue_assets(): void {
    $css_version = file_exists(EUM_PLUGIN_DIR . 'assets/admin.css') ? (string)filemtime(EUM_PLUGIN_DIR . 'assets/admin.css') : EUM_VERSION;
    $js_version = file_exists(EUM_PLUGIN_DIR . 'assets/admin.js') ? (string)filemtime(EUM_PLUGIN_DIR . 'assets/admin.js') : EUM_VERSION;
    wp_enqueue_style('eum-ui', EUM_PLUGIN_URL . 'assets/admin.css', [], $css_version);
    wp_enqueue_script('eum-ui', EUM_PLUGIN_URL . 'assets/admin.js', [], $js_version, true);
  }

  public function activate(): void {
    $this->run_activation_map();
    EUM_Access::migrate_legacy_access(EUM_Access::forced_root_user_id());

    flush_rewrite_rules();
  }

  private function boot_modules(array $classes): void {
    foreach ($classes as $class_name) {
      if (!is_string($class_name) || $class_name === '' || !class_exists($class_name)) continue;
      try {
        $module = new $class_name();
        if (method_exists($module, 'init')) {
          $module->init();
        }
      } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log(sprintf('[EUM module init] %s failed: %s', $class_name, $e->getMessage()));
        }
      }
    }
  }

  private function run_activation_map(): void {
    foreach (EUM_Module_Registry::activation_map() as $class_name => $methods) {
      if (!class_exists($class_name)) continue;
      try {
        $module = null;
        foreach ((array)$methods as $method) {
          if (!method_exists($class_name, (string)$method)) continue;
          if ($class_name === 'EUM_Events' && $method === 'activate') {
            EUM_Events::activate();
            continue;
          }
          if ($module === null) {
            $module = new $class_name();
          }
          $module->{$method}();
        }
      } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log(sprintf('[EUM module activate] %s failed: %s', $class_name, $e->getMessage()));
        }
      }
    }
  }

  public function deactivate(): void {
    if (class_exists('EUM_Nextcloud_Bridge') && method_exists('EUM_Nextcloud_Bridge', 'unschedule_cron')) {
      EUM_Nextcloud_Bridge::unschedule_cron();
    }
    if (class_exists('EUM_Tickets') && method_exists('EUM_Tickets', 'unschedule_cron')) {
      EUM_Tickets::unschedule_cron();
    }
    flush_rewrite_rules();
  }
}
