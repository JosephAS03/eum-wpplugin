<?php
/**
 * Plugin Name: Enterprise User Management
 * Description: Enterprise-grade user meta, personnel files, user groups, job/location paths, projects, managers, and bulk import/export and auditing tools.
 * Version: 0.7.88-rebuild
 * Author: JAS Enterprises
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    return $needle === '' || strpos($haystack, $needle) !== false;
  }
}

if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

define('EUM_VERSION', '0.7.85-rebuild');
define('EUM_PLUGIN_FILE', __FILE__);
define('EUM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EUM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EUM_PLUGIN_DIR . 'includes/update-checker-bootstrap.php';

if (!function_exists('eum_log_message')) {
  function eum_log_message(string $message): void {
    $message = trim($message);
    if ($message === '') {
      return;
    }

    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
    $written = false;

    if (function_exists('wp_upload_dir')) {
      $uploads = wp_upload_dir(null, false);
      $base_dir = isset($uploads['basedir']) && is_string($uploads['basedir']) ? $uploads['basedir'] : '';
      $upload_error = isset($uploads['error']) ? (string)$uploads['error'] : '';
      if ($base_dir !== '' && $upload_error === '') {
        $dir = rtrim($base_dir, '/\\') . DIRECTORY_SEPARATOR . 'eum-logs';
        if ((is_dir($dir) || (function_exists('wp_mkdir_p') && wp_mkdir_p($dir))) && is_writable($dir)) {
          $path = $dir . DIRECTORY_SEPARATOR . 'eum-debug.log';
          $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false;
        }
      }
    }

    if (!$written && defined('WP_CONTENT_DIR') && is_string(WP_CONTENT_DIR) && WP_CONTENT_DIR !== '') {
      $path = rtrim(WP_CONTENT_DIR, '/\\') . DIRECTORY_SEPARATOR . 'eum-debug.log';
      $parent = dirname($path);
      if (is_dir($parent) && is_writable($parent)) {
        $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false;
      }
    }

    error_log($message);
  }
}

if (!function_exists('eum_run_smoke_test')) {
  function eum_run_smoke_test(): void {
    if (!isset($_GET['eum_smoke_test'])) {
      return;
    }

    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
      return;
    }

    $user_id = function_exists('get_current_user_id') ? (int)get_current_user_id() : 0;
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string)$_SERVER['REQUEST_URI'])) : '';
    eum_log_message('[EUM Smoke Test] Logger reached successfully for user #' . $user_id . ($uri !== '' ? ' at ' . $uri : ''));

    wp_die(
      esc_html__('EUM smoke test logged successfully. Check uploads/eum-logs/eum-debug.log or wp-content/eum-debug.log.', 'enterprise-user-management'),
      esc_html__('EUM Smoke Test', 'enterprise-user-management'),
      ['response' => 200]
    );
  }
}

add_action('init', 'eum_run_smoke_test', 1);

if (!function_exists('eum_request_context_summary')) {
  function eum_request_context_summary(): string {
    $bits = [];

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : '';
    if ($method !== '') {
      $bits[] = 'method=' . $method;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string)$_SERVER['REQUEST_URI'])) : '';
    if ($uri !== '') {
      $bits[] = 'uri=' . $uri;
    }

    $post_action = isset($_POST['action']) ? sanitize_key(wp_unslash((string)$_POST['action'])) : '';
    if ($post_action !== '') {
      $bits[] = 'post_action=' . $post_action;
    }

    $get_action = isset($_GET['action']) ? sanitize_key(wp_unslash((string)$_GET['action'])) : '';
    if ($get_action !== '') {
      $bits[] = 'get_action=' . $get_action;
    }

    if (function_exists('get_current_user_id')) {
      $user_id = (int)get_current_user_id();
      if ($user_id > 0) {
        $bits[] = 'user_id=' . $user_id;
      }
    }

    return implode(' | ', $bits);
  }
}

if (!function_exists('eum_capture_fatal_error')) {
  function eum_capture_fatal_error(): void {
    $error = error_get_last();
    if (!is_array($error)) {
      return;
    }

    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    $type = isset($error['type']) ? (int)$error['type'] : 0;
    if (!in_array($type, $fatal_types, true)) {
      return;
    }

    $message = isset($error['message']) ? sanitize_text_field((string)$error['message']) : 'Unknown fatal error';
    $file = isset($error['file']) ? (string)$error['file'] : '';
    $line = isset($error['line']) ? (int)$error['line'] : 0;
    $context = eum_request_context_summary();

    eum_log_message(
      '[EUM Fatal] type=' . $type .
      ($file !== '' ? ' | file=' . $file : '') .
      ($line > 0 ? ' | line=' . $line : '') .
      ' | message=' . $message .
      ($context !== '' ? ' | ' . $context : '')
    );
  }
}

register_shutdown_function('eum_capture_fatal_error');

if (version_compare(PHP_VERSION, '7.4', '<')) {
  if (!function_exists('eum_php_version_notice')) {
    function eum_php_version_notice() {
      echo '<div class="notice notice-error"><p>';
      echo esc_html(sprintf(
        'Enterprise User Management requires PHP 7.4 or newer. Current PHP version: %s.',
        PHP_VERSION
      ));
      echo '</p></div>';
    }
  }
  add_action('admin_notices', 'eum_php_version_notice');
  return;
}

require_once EUM_PLUGIN_DIR . 'includes/class-eum-plugin.php';

function eum_boot() {
  $plugin = new EUM_Plugin();
  $plugin->init();
}
add_action('plugins_loaded', 'eum_boot');


if (!function_exists('eum_user_data_value')) {
  function eum_user_data_value(array $atts = []): string {
    $pairs = [];
    foreach ($atts as $key => $value) {
      $pairs[] = sprintf('%s="%s"', sanitize_key((string)$key), esc_attr((string)$value));
    }
    return do_shortcode('[eum_user_data ' . implode(' ', $pairs) . ']');
  }
}
