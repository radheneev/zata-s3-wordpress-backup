<?php
/**
 * Plugin Name: ZATA S3 WordPress Backup
 * Plugin URI: https://github.com/radheneev/zata-s3-wordpress-backup
 * Description: Backup WordPress database, themes, and plugins as separate ZIP files and upload them to ZATA S3 or any S3-compatible object storage. Includes scheduling, logs, and email notifications.
 * Version: 1.0.6
 * Author: Radhe D
 * Author URI: https://zata.ai
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: zata-s3-backup
 */

if (!defined('ABSPATH')) exit;

define('WPS3B_VERSION', '1.0.6');
define('WPS3B_SLUG', 'wp-s3-backup');
define('WPS3B_OPT', 'wps3b_settings');
define('WPS3B_HISTORY_OPT', 'wps3b_history');

require_once __DIR__ . '/admin.php';

register_activation_hook(__FILE__, function () {
  $s = get_option(WPS3B_OPT, []);
  $s = is_array($s) ? $s : [];
  if (($s['schedule'] ?? 'daily') === 'disabled') return;

  if (function_exists('wps3b_reschedule_cron')) {
    wps3b_reschedule_cron($s);
  }
});

register_deactivation_hook(__FILE__, function () {
  $hook = 'wps3b_run_backup_cron';
  $timestamp = wp_next_scheduled($hook);
  while ($timestamp) {
    wp_unschedule_event($timestamp, $hook);
    $timestamp = wp_next_scheduled($hook);
  }
});

add_action('wps3b_run_backup_cron', 'wps3b_run_backup');

add_action('admin_post_wps3b_run_now', function () {
  if (!current_user_can('manage_options')) wp_die('Unauthorized');
  check_admin_referer('wps3b_run_now');

  $result = wps3b_run_backup();

  $redirect = add_query_arg([
    'page' => WPS3B_SLUG,
    'tab'  => 'logs',
    'wps3b_msg' => $result['ok'] ? 'success' : 'error',
    'wps3b_detail' => rawurlencode($result['message']),
  ], admin_url('admin.php'));

  wp_safe_redirect($redirect);
  exit;
});

/**
 * Paths + logs
 */
function wps3b_backup_dir(): string {
  $dir = WP_CONTENT_DIR . '/uploads/wps3b-backups';
  if (!is_dir($dir)) {
    @wp_mkdir_p($dir);

    // Block direct access (Apache) + prevent directory browsing
    if (!file_exists($dir . '/index.php')) {
      @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
    }
    if (!file_exists($dir . '/.htaccess')) {
      @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
  }
  return $dir;
}

function wps3b_log_file(): string {
  return rtrim(wps3b_backup_dir(), '/') . '/wps3b.log';
}

function wps3b_log(string $level, string $message, array $context = []): void {
  // Never log secrets
  unset($context['access_key'], $context['secret_key']);

  $line = sprintf(
    "[%s] [%s] %s",
    gmdate('Y-m-d H:i:s'),
    strtoupper($level),
    $message
  );

  if (!empty($context)) {
    $line .= ' | ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES);
  }
  $line .= "\n";

  @file_put_contents(wps3b_log_file(), $line, FILE_APPEND);
}

function wps3b_history_add(array $entry): void {
  $hist = get_option(WPS3B_HISTORY_OPT, []);
  $hist = is_array($hist) ? $hist : [];
  array_unshift($hist, $entry);
  $hist = array_slice($hist, 0, 50);
  update_option(WPS3B_HISTORY_OPT, $hist);
}

/**
 * Main backup
 */
function wps3b_run_backup(): array {
  $s = get_option(WPS3B_OPT, []);
  $s = is_array($s) ? $s : [];

  $run_id = wp_generate_uuid4();
  $started = time();

  wps3b_log('info', 'Backup run started', [
    'run_id' => $run_id,
    'site' => home_url(),
  ]);

  $required = ['endpoint', 'region', 'bucket', 'access_key', 'secret_key'];
  foreach ($required as $k) {
    if (empty($s[$k])) {
      $final = ['ok' => false, 'message' => "Missing setting: {$k}"];
      wps3b_log('error', 'Backup aborted due to missing setting', [
        'run_id' => $run_id,
        'missing' => $k,
      ]);
      wps3b_history_add([
        'run_id' => $run_id,
        'time' => time(),
        'status' => 'FAILED',
        'message' => $final['message'],
        'duration_sec' => time() - $started,
      ]);
      wps3b_maybe_notify($final);
      return $final;
    }
  }

  $backup_dir = wps3b_backup_dir();
  if (!is_dir($backup_dir)) {
    $final = ['ok' => false, 'message' => "Cannot create backup dir: {$backup_dir}"];
    wps3b_log('error', 'Backup dir create failed', ['run_id' => $run_id, 'dir' => $backup_dir]);
    wps3b_history_add([
      'run_id' => $run_id,
      'time' => time(),
      'status' => 'FAILED',
      'message' => $final['message'],
      'duration_sec' => time() - $started,
    ]);
    wps3b_maybe_notify($final);
    return $final;
  }

  $site = preg_replace('/[^a-zA-Z0-9\-]/', '-', (parse_url(home_url(), PHP_URL_HOST) ?: 'site'));
  $ts = gmdate('Ymd-His');
  $prefix = trim($s['key_prefix'] ?? 'wp-backups', '/');

  $results = [];
  $errors = [];
  $created_files = [];
  $uploaded = [];

  // DB zip
  if (!empty($s['include_db'])) {
    $db = wps3b_backup_db_zip($backup_dir, $site, $ts);
    if (!$db['ok']) {
      $errors[] = "DB: " . $db['message'];
      wps3b_log('error', 'DB zip failed', ['run_id' => $run_id, 'error' => $db['message']]);
    } else {
      $created_files[] = $db['file'];
      $remote = $prefix . '/db/' . basename($db['file']);
      $up = wps3b_s3_put_object($db['file'], $remote, $s);
      if (!$up['ok']) {
        $errors[] = "DB upload: " . $up['message'];
        wps3b_log('error', 'DB upload failed', ['run_id' => $run_id, 'key' => $remote, 'error' => $up['message']]);
      } else {
        $uploaded[] = $remote;
        $results[] = "DB uploaded: s3://{$s['bucket']}/{$remote}";
        wps3b_log('info', 'DB uploaded', ['run_id' => $run_id, 'key' => $remote, 'size_bytes' => (int)(filesize($db['file']) ?: 0)]);
      }
    }
  }

  // Themes zip
  if (!empty($s['include_themes'])) {
    $themes = wps3b_backup_folder_zip(WP_CONTENT_DIR . '/themes', $backup_dir, "{$site}-themes-{$ts}.zip", 'wp-content/themes');
    if (!$themes['ok']) {
      $errors[] = "Themes: " . $themes['message'];
      wps3b_log('error', 'Themes zip failed', ['run_id' => $run_id, 'error' => $themes['message']]);
    } else {
      $created_files[] = $themes['file'];
      $remote = $prefix . '/themes/' . basename($themes['file']);
      $up = wps3b_s3_put_object($themes['file'], $remote, $s);
      if (!$up['ok']) {
        $errors[] = "Themes upload: " . $up['message'];
        wps3b_log('error', 'Themes upload failed', ['run_id' => $run_id, 'key' => $remote, 'error' => $up['message']]);
      } else {
        $uploaded[] = $remote;
        $results[] = "Themes uploaded: s3://{$s['bucket']}/{$remote}";
        wps3b_log('info', 'Themes uploaded', ['run_id' => $run_id, 'key' => $remote, 'size_bytes' => (int)(filesize($themes['file']) ?: 0)]);
      }
    }
  }

  // Plugins zip
  if (!empty($s['include_plugins'])) {
    $plugins = wps3b_backup_folder_zip(WP_CONTENT_DIR . '/plugins', $backup_dir, "{$site}-plugins-{$ts}.zip", 'wp-content/plugins');
    if (!$plugins['ok']) {
      $errors[] = "Plugins: " . $plugins['message'];
      wps3b_log('error', 'Plugins zip failed', ['run_id' => $run_id, 'error' => $plugins['message']]);
    } else {
      $created_files[] = $plugins['file'];
      $remote = $prefix . '/plugins/' . basename($plugins['file']);
      $up = wps3b_s3_put_object($plugins['file'], $remote, $s);
      if (!$up['ok']) {
        $errors[] = "Plugins upload: " . $up['message'];
        wps3b_log('error', 'Plugins upload failed', ['run_id' => $run_id, 'key' => $remote, 'error' => $up['message']]);
      } else {
        $uploaded[] = $remote;
        $results[] = "Plugins uploaded: s3://{$s['bucket']}/{$remote}";
        wps3b_log('info', 'Plugins uploaded', ['run_id' => $run_id, 'key' => $remote, 'size_bytes' => (int)(filesize($plugins['file']) ?: 0)]);
      }
    }
  }

  // Local retention / cleanup
  $keep_local = max(0, (int)($s['keep_local'] ?? 3));
  if ($keep_local > 0) {
    wps3b_apply_local_retention($backup_dir, $keep_local);
  } else {
    foreach ($created_files as $f) @unlink($f);
  }

  $final = empty($errors)
    ? ['ok' => true, 'message' => implode(' | ', $results)]
    : ['ok' => false, 'message' => implode(' | ', $errors) . (empty($results) ? '' : ' | ' . implode(' | ', $results))];

  $duration = time() - $started;

  wps3b_history_add([
    'run_id' => $run_id,
    'time' => time(),
    'status' => $final['ok'] ? 'SUCCESS' : 'FAILED',
    'duration_sec' => $duration,
    'uploaded_keys' => $uploaded,
    'message' => $final['message'],
  ]);

  wps3b_log($final['ok'] ? 'info' : 'error', 'Backup run finished', [
    'run_id' => $run_id,
    'status' => $final['ok'] ? 'SUCCESS' : 'FAILED',
    'duration_sec' => $duration,
    'uploaded_count' => count($uploaded),
  ]);

  wps3b_maybe_notify($final);
  return $final;
}

/** DB -> database.sql -> db zip */
function wps3b_backup_db_zip(string $backup_dir, string $site, string $ts): array {
  $sql_file = "{$backup_dir}/{$site}-db-{$ts}.sql";
  $zip_file = "{$backup_dir}/{$site}-db-{$ts}.zip";

  $dump = wps3b_dump_db_to_file($sql_file);
  if (!$dump['ok']) return $dump;

  $zip = new ZipArchive();
  if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($sql_file);
    return ['ok' => false, 'message' => 'Unable to create DB ZIP'];
  }

  $zip->addFile($sql_file, 'database.sql');
  $zip->close();
  @unlink($sql_file);

  return ['ok' => true, 'file' => $zip_file, 'message' => 'DB zipped'];
}

/** Folder -> zip */
function wps3b_backup_folder_zip(string $src_dir, string $backup_dir, string $zip_name, string $zip_root): array {
  if (!is_dir($src_dir)) return ['ok' => false, 'message' => "Missing directory: {$src_dir}"];
  $zip_file = rtrim($backup_dir, '/') . '/' . $zip_name;

  $zip = new ZipArchive();
  if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    return ['ok' => false, 'message' => "Unable to create ZIP: {$zip_name}"];
  }

  wps3b_zip_folder($zip, $src_dir, $zip_root);
  $zip->close();

  return ['ok' => true, 'file' => $zip_file, 'message' => "{$zip_name} created"];
}

/** Add a folder recursively to ZipArchive */
function wps3b_zip_folder(ZipArchive $zip, string $folder, string $zipPathBase): void {
  $folder = rtrim($folder, '/');
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($it as $file) {
    $filePath = (string)$file;
    $relative = ltrim(str_replace($folder, '', $filePath), '/');
    $zipPath = rtrim($zipPathBase, '/') . '/' . $relative;

    if (is_dir($filePath)) $zip->addEmptyDir($zipPath);
    else $zip->addFile($filePath, $zipPath);
  }
}

/**
 * DB dump: tries mysqldump first, else PHP fallback.
 */
function wps3b_dump_db_to_file(string $outFile): array {
  global $wpdb;

  $mysqldump = trim((string)@shell_exec('command -v mysqldump 2>/dev/null'));
  if ($mysqldump) {
    $host = DB_HOST; $port = '';
    if (strpos(DB_HOST, ':') !== false) {
      [$host, $port] = explode(':', DB_HOST, 2);
    }

    $cmd = escapeshellcmd($mysqldump)
      . " --single-transaction --quick --skip-lock-tables"
      . " -h " . escapeshellarg($host)
      . ($port ? " -P " . escapeshellarg($port) : "")
      . " -u " . escapeshellarg(DB_USER)
      . " --password=" . escapeshellarg(DB_PASSWORD)
      . " " . escapeshellarg(DB_NAME)
      . " > " . escapeshellarg($outFile) . " 2>&1";

    $out = []; $rc = 0;
    @exec($cmd, $out, $rc);

    if ($rc === 0 && file_exists($outFile) && filesize($outFile) > 0) {
      return ['ok' => true, 'message' => 'DB dumped via mysqldump'];
    }
  }

  // PHP fallback
  $fh = @fopen($outFile, 'w');
  if (!$fh) return ['ok' => false, 'message' => "Cannot write DB dump to {$outFile}"];

  fwrite($fh, "-- ZATA S3 WordPress Backup DB dump\n-- Generated: " . gmdate('c') . "\n\n");

  $tables = $wpdb->get_col('SHOW TABLES');
  if (!$tables) { fclose($fh); return ['ok' => false, 'message' => 'No tables found']; }

  foreach ($tables as $table) {
    $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
    if (!$create || empty($create[1])) continue;

    fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
    fwrite($fh, $create[1] . ";\n\n");

    $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
    if (!$rows) { fwrite($fh, "\n"); continue; }

    foreach ($rows as $row) {
      $cols = array_map(fn($c) => "`" . str_replace("`", "``", $c) . "`", array_keys($row));
      $vals = array_map(function ($v) {
        if (is_null($v)) return "NULL";
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
      }, array_values($row));

      fwrite($fh, "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n");
    }
    fwrite($fh, "\n");
  }

  fclose($fh);
  return ['ok' => true, 'message' => 'DB dumped via PHP fallback'];
}

/** Keep latest N local zip files */
function wps3b_apply_local_retention(string $dir, int $keep): void {
  $files = glob(rtrim($dir, '/') . '/*.zip');
  if (!$files) return;

  usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
  $remove = array_slice($files, $keep);
  foreach ($remove as $f) @unlink($f);
}

/** Email notifications */
function wps3b_maybe_notify(array $result): void {
  $s = get_option(WPS3B_OPT, []);
  $s = is_array($s) ? $s : [];

  if (empty($s['notify_enabled'])) return;

  $to = $s['notify_email'] ?? get_option('admin_email');
  if (!$to) return;

  $is_success = !empty($result['ok']);
  if ($is_success && empty($s['notify_on_success'])) return;

  $subject = $is_success ? '[ZATA S3 Backup] SUCCESS' : '[ZATA S3 Backup] FAILED';
  $body =
    ($is_success ? "Backup completed successfully.\n\n" : "Backup failed.\n\n") .
    "Details:\n" . ($result['message'] ?? '(no message)') . "\n\n" .
    "Site: " . home_url() . "\n" .
    "Time: " . date_i18n('c') . "\n";

  @wp_mail($to, $subject, $body);
}

/**
 * Upload file to S3-compatible storage using AWS Signature V4 (no AWS SDK dependency)
 * Note: For very large sites, next improvement is "streaming upload" (memory friendly).
 */
function wps3b_s3_put_object(string $file_path, string $object_key, array $s): array {
  if (!file_exists($file_path)) return ['ok' => false, 'message' => "File not found: {$file_path}"];
  if (!function_exists('curl_init')) return ['ok' => false, 'message' => 'cURL extension is required'];

  $endpoint_raw = isset($s['endpoint']) ? rtrim($s['endpoint'], '/') : '';
  $scheme_pref = !empty($s['scheme']) ? strtolower($s['scheme']) : 'https';
  if (!in_array($scheme_pref, ['http','https'], true)) $scheme_pref = 'https';

  // Accept endpoint in two forms:
  // 1) host[:port] (recommended in UI)
  // 2) full URL with scheme
  $endpoint = (strpos($endpoint_raw, '://') === false) ? ($scheme_pref . '://' . $endpoint_raw) : $endpoint_raw;

  $bucket   = $s['bucket'];
  $region   = isset($s['region']) ? trim($s['region']) : '';
  if ($region === '') $region = 'us-east-1';
  $ak       = $s['access_key'];
  $sk       = $s['secret_key'];
  $use_path_style = !empty($s['path_style']);
  $already_redirected = !empty($s['_redirected']);

  $host_base = parse_url($endpoint, PHP_URL_HOST);
  $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
  if (!$host_base) return ['ok' => false, 'message' => 'Invalid endpoint'];

  $payload = file_get_contents($file_path);
  if ($payload === false) return ['ok' => false, 'message' => 'Cannot read file for upload'];

  $service = 's3';
  $amzdate = gmdate('Ymd\THis\Z');
  $datestamp = gmdate('Ymd');
  $content_type = 'application/zip';

  if ($use_path_style) {
    $host = $host_base;
    $uri  = '/' . $bucket . '/' . ltrim($object_key, '/');
  } else {
    $host = $bucket . '.' . $host_base;
    $uri  = '/' . ltrim($object_key, '/');
  }

  $url = $scheme . '://' . $host . $uri;

  $payload_hash = hash('sha256', $payload);

  $canonical_headers =
    'content-type:' . $content_type . "\n" .
    'host:' . $host . "\n" .
    'x-amz-content-sha256:' . $payload_hash . "\n" .
    'x-amz-date:' . $amzdate . "\n";

  $signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';

  $canonical_request =
    "PUT\n{$uri}\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

  $algorithm = 'AWS4-HMAC-SHA256';
  $credential_scope = "{$datestamp}/{$region}/{$service}/aws4_request";
  $string_to_sign =
    $algorithm . "\n" .
    $amzdate . "\n" .
    $credential_scope . "\n" .
    hash('sha256', $canonical_request);

  $kDate = hash_hmac('sha256', $datestamp, 'AWS4' . $sk, true);
  $kRegion = hash_hmac('sha256', $region, $kDate, true);
  $kService = hash_hmac('sha256', $service, $kRegion, true);
  $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
  $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

  $authorization_header =
    $algorithm . ' ' .
    'Credential=' . $ak . '/' . $credential_scope . ', ' .
    'SignedHeaders=' . $signed_headers . ', ' .
    'Signature=' . $signature;

  $headers = [
    'Content-Type: ' . $content_type,
    'Host: ' . $host,
    'X-Amz-Date: ' . $amzdate,
    'X-Amz-Content-Sha256: ' . $payload_hash,
    'Authorization: ' . $authorization_header,
  ];

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, true);

  if (!empty($s['insecure_tls'])) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
  }

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($resp === false) return ['ok' => false, 'message' => "cURL error: {$err}"];

  // Handle common redirects (e.g., http -> https) by retrying once using the Location header
  if (!$already_redirected && in_array($code, [301,302,307,308], true)) {
    $header_size = 0;
    $headers_out = [];
    // Try to parse response headers from the raw response (CURLOPT_HEADER = true)
    // Header size is not available after curl_close, so we parse manually.
    $parts = preg_split("/\r\n\r\n/", $resp);
    $raw_headers = $parts[0] ?? '';
    foreach (explode("\r\n", $raw_headers) as $line) {
      if (strpos($line, ':') !== false) {
        [$k,$v] = array_map('trim', explode(':', $line, 2));
        $headers_out[strtolower($k)] = $v;
      }
    }
    $location = $headers_out['location'] ?? '';
    if ($location) {
      $loc_scheme = parse_url($location, PHP_URL_SCHEME);
      $loc_host   = parse_url($location, PHP_URL_HOST);
      if ($loc_scheme && $loc_host) {
        // Retry with redirected base (keep same object key & path style)
        $s2 = $s;
        $s2['_redirected'] = 1;
        $s2['scheme'] = $loc_scheme;
        $s2['endpoint'] = $loc_host;
        return wps3b_s3_put_object($file_path, $object_key, $s2);
      }
    }
  }

  if ($code < 200 || $code >= 300) return ['ok' => false, 'message' => "Upload failed HTTP {$code}"];

  return ['ok' => true, 'message' => 'Uploaded'];
}