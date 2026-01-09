<?php
if (!defined('ABSPATH')) exit;

/* ---------------------------
 * Admin menu
 * --------------------------- */
add_action('admin_menu', function () {
    add_menu_page(
        'ZATA S3 Backup',
        'ZATA S3 Backup',
        'manage_options',
        'zata-wps3b',
        'zata_wps3b_render_page',
        'dashicons-cloud',
        80
    );
});

/* ---------------------------
 * Helpers: settings + logs + test status
 * --------------------------- */
function zata_wps3b_get_settings() {
    return zata_wps3b_default_settings();
}
function zata_wps3b_set_log($text) {
    update_option(ZATA_WPS3B_LOG, $text, false);
}
function zata_wps3b_get_log() {
    return (string) get_option(ZATA_WPS3B_LOG, 'No logs yet.');
}
function zata_wps3b_append_log_line(&$lines, $line) {
    $lines[] = '[' . current_time('Y-m-d H:i:s') . '] ' . $line;
}
function zata_wps3b_set_test_status($ok, $message) {
    update_option(ZATA_WPS3B_TEST, [
        'ok' => (bool)$ok,
        'message' => (string)$message,
        'time' => time(),
    ], false);
}
function zata_wps3b_get_test_status() {
    $t = get_option(ZATA_WPS3B_TEST, null);
    if (!is_array($t)) return ['ok'=>false,'message'=>'Not tested yet.','time'=>0];
    return array_merge(['ok'=>false,'message'=>'Not tested yet.','time'=>0], $t);
}

/* ---------------------------
 * Backup runner: local + optional remote upload
 * --------------------------- */
function zata_wps3b_run_backup($mode = 'manual') {
    $s = zata_wps3b_get_settings();
    $lines = [];
    $success = false;
    zata_wps3b_append_log_line($lines, "Backup run started ({$mode}).");

    $include_db      = !empty($s['include_db']);
    $include_themes  = !empty($s['include_themes']);
    $include_plugins = !empty($s['include_plugins']);

    if (!$include_db && !$include_themes && !$include_plugins) {
        zata_wps3b_append_log_line($lines, "✖ ERROR: Nothing selected to backup. Enable DB/Themes/Plugins.");
        zata_wps3b_set_log(implode("\n", $lines));
        
        // Send failure notification
        zata_wps3b_send_notification(false, implode("\n", $lines), $mode);
        return;
    }

    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit($upload_dir['basedir']) . 'zata-backups';
    if (!file_exists($base_dir)) wp_mkdir_p($base_dir);

    $site = parse_url(home_url(), PHP_URL_HOST);
    $ts = date('Ymd-His');

    $files = []; // type => path

    try {
        if ($include_db) {
            $db_file = $base_dir . "/{$site}-db-{$ts}.sql";
            zata_wps3b_db_dump($db_file);
            $files['db'] = $db_file;
            zata_wps3b_append_log_line($lines, "✓ Database backup created: {$db_file}");
        } else {
            zata_wps3b_append_log_line($lines, "• Database backup skipped (not selected).");
        }

        if ($include_themes) {
            $themes_zip = $base_dir . "/{$site}-themes-{$ts}.zip";
            zata_wps3b_zip_dir(WP_CONTENT_DIR . '/themes', $themes_zip);
            $files['themes'] = $themes_zip;
            zata_wps3b_append_log_line($lines, "✓ Themes ZIP created: {$themes_zip}");
        } else {
            zata_wps3b_append_log_line($lines, "• Themes backup skipped (not selected).");
        }

        if ($include_plugins) {
            $plugins_zip = $base_dir . "/{$site}-plugins-{$ts}.zip";
            zata_wps3b_zip_dir(WP_CONTENT_DIR . '/plugins', $plugins_zip);
            $files['plugins'] = $plugins_zip;
            zata_wps3b_append_log_line($lines, "✓ Plugins ZIP created: {$plugins_zip}");
        } else {
            zata_wps3b_append_log_line($lines, "• Plugins backup skipped (not selected).");
        }

        // Local retention
        zata_wps3b_local_retention($base_dir, $site, (int)$s['keep_local']);

        // Upload if configured
        if (zata_wps3b_is_remote_configured($s)) {
            zata_wps3b_append_log_line($lines, "Remote upload enabled: {$s['provider']}");

            $prefix = trim((string)$s['prefix']);
            $prefix = $prefix === '' ? 'wp-backups' : trim($prefix, '/');

            if (!empty($files['db'])) {
                zata_wps3b_upload_file($s, $files['db'], "{$prefix}/db/" . basename($files['db']), $lines);
            }
            if (!empty($files['themes'])) {
                zata_wps3b_upload_file($s, $files['themes'], "{$prefix}/themes/" . basename($files['themes']), $lines);
            }
            if (!empty($files['plugins'])) {
                zata_wps3b_upload_file($s, $files['plugins'], "{$prefix}/plugins/" . basename($files['plugins']), $lines);
            }
        } else {
            zata_wps3b_append_log_line($lines, "Remote upload skipped: destination not configured.");
        }

        zata_wps3b_append_log_line($lines, "Backup completed successfully.");
        $success = true;
        
        // Record last backup time
        $s['last_backup'] = time();
        update_option(ZATA_WPS3B_OPT, $s, false);

    } catch (Exception $e) {
        zata_wps3b_append_log_line($lines, "✖ ERROR: " . $e->getMessage());
        $success = false;
    }

    $log_text = implode("\n", $lines);
    zata_wps3b_set_log($log_text);
    
    // Send email notification
    zata_wps3b_send_notification($success, $log_text, $mode);
}

/* ---------------------------
 * Local backup helpers
 * --------------------------- */
function zata_wps3b_db_dump($output_file) {
    global $wpdb;

    $tables = $wpdb->get_col('SHOW TABLES');
    if (!$tables) throw new Exception('No DB tables found.');

    $fh = fopen($output_file, 'w');
    if (!$fh) throw new Exception('Unable to write DB file.');

    fwrite($fh, "-- WordPress Database Backup\n");
    fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");

    foreach ($tables as $table) {
        $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        if (!$create || empty($create[1])) continue;

        fwrite($fh, "\n-- Table: {$table}\n");
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fh, $create[1] . ";\n\n");

        $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        if (!$rows) continue;

        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $v) {
                if ($v === null) $vals[] = 'NULL';
                else $vals[] = "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$v) . "'";
            }
            fwrite($fh, "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n");
        }
    }

    fclose($fh);
}

function zata_wps3b_zip_dir($source, $destination) {
    if (!extension_loaded('zip')) throw new Exception('ZIP extension missing.');
    $zip = new ZipArchive();
    if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Cannot create ZIP: ' . basename($destination));
    }

    $source = realpath($source);
    if (!$source) throw new Exception('Source path does not exist.');

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iter as $item) {
        if ($item->isFile()) {
            $real = $item->getRealPath();
            $rel = substr($real, strlen($source) + 1);
            $zip->addFile($real, $rel);
        }
    }

    $zip->close();
}

function zata_wps3b_local_retention($base_dir, $site, $keep_local) {
    if ($keep_local < 1) return;

    foreach (['db', 'themes', 'plugins'] as $type) {
        $pattern = "{$base_dir}/{$site}-{$type}-*.";
        $files = glob($pattern . '*');
        if (!$files) continue;

        usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
        $to_delete = array_slice($files, $keep_local);
        foreach ($to_delete as $old) {
            @unlink($old);
        }
    }
}

/* ---------------------------
 * S3 helpers
 * --------------------------- */
function zata_wps3b_is_remote_configured($s) {
    return !empty($s['endpoint']) && !empty($s['bucket']) && !empty($s['access_key']) && !empty($s['secret_key']);
}

function zata_wps3b_s3_url($s, $key) {
    $host = trim((string)$s['endpoint']);
    $bucket = trim((string)$s['bucket']);
    $protocol = ($s['protocol'] === 'http') ? 'http' : 'https';
    $path_style = !empty($s['path_style']);

    if ($path_style) {
        return "{$protocol}://{$host}/{$bucket}/{$key}";
    } else {
        return "{$protocol}://{$bucket}.{$host}/{$key}";
    }
}

function zata_wps3b_s3_sign($s, $method, $key, $body = '') {
    $ak = (string)$s['access_key'];
    $sk = (string)$s['secret_key'];
    $bucket = (string)$s['bucket'];
    $region = trim((string)$s['region']) ?: 'us-east-1';

    $host = trim((string)$s['endpoint']);
    $path_style = !empty($s['path_style']);

    $canonical_uri = $path_style ? "/{$bucket}/{$key}" : "/{$key}";
    $canonical_uri = '/' . implode('/', array_map('rawurlencode', explode('/', trim($canonical_uri, '/'))));

    $dt = gmdate('Ymd\THis\Z');
    $date = substr($dt, 0, 8);

    $payload_hash = hash('sha256', $body);

    $canonical_headers = "host:{$host}\nx-amz-content-sha256:{$payload_hash}\nx-amz-date:{$dt}\n";
    $signed_headers = 'host;x-amz-content-sha256;x-amz-date';

    $canonical_request = "{$method}\n{$canonical_uri}\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
    $canonical_hash = hash('sha256', $canonical_request);

    $scope = "{$date}/{$region}/s3/aws4_request";
    $string_to_sign = "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n{$canonical_hash}";

    $k_secret = "AWS4{$sk}";
    $k_date = hash_hmac('sha256', $date, $k_secret, true);
    $k_region = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', 's3', $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);

    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

    $authorization = "AWS4-HMAC-SHA256 Credential={$ak}/{$scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

    return [
        'Authorization' => $authorization,
        'x-amz-content-sha256' => $payload_hash,
        'x-amz-date' => $dt,
    ];
}

function zata_wps3b_s3_request($s, $method, $key, $body = '', $extra_headers = []) {
    $url = zata_wps3b_s3_url($s, $key);
    $auth_headers = zata_wps3b_s3_sign($s, $method, $key, $body);

    $headers = array_merge($auth_headers, $extra_headers);
    $header_strings = [];
    foreach ($headers as $k => $v) {
        $header_strings[] = "{$k}: {$v}";
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header_strings);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($method === 'PUT' && $body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception("cURL error: {$error}");
    if ($http_code < 200 || $http_code >= 300) {
        throw new Exception("S3 HTTP {$http_code}: " . substr($response, 0, 200));
    }

    return ['code' => $http_code, 'body' => $response];
}

function zata_wps3b_upload_file($s, $local_path, $remote_key, &$lines) {
    if (!file_exists($local_path)) {
        zata_wps3b_append_log_line($lines, "✖ Local file not found: {$local_path}");
        return;
    }

    $size = filesize($local_path);
    $body = file_get_contents($local_path);

    zata_wps3b_append_log_line($lines, "Uploading " . basename($local_path) . " → {$remote_key}");

    try {
        $r = zata_wps3b_s3_request($s, 'PUT', $remote_key, $body, ['Content-Type' => 'application/octet-stream']);
        zata_wps3b_append_log_line($lines, "✓ Upload OK: {$remote_key}");
    } catch (Exception $e) {
        zata_wps3b_append_log_line($lines, "✖ Upload failed: " . $e->getMessage());
    }
}

/* ---------------------------
 * Admin page UI
 * --------------------------- */
function zata_wps3b_render_page() {
    // Handle form submissions
    $notice = null;
    $notice_type = 'success';

    // 1) Save settings
    if (isset($_POST['zata_wps3b_save']) && check_admin_referer('zata_wps3b_save', 'zata_wps3b_nonce')) {
        $new_settings = [
            'provider'        => sanitize_text_field($_POST['provider'] ?? 'zata'),
            'endpoint'        => sanitize_text_field($_POST['endpoint'] ?? ''),
            'protocol'        => sanitize_text_field($_POST['protocol'] ?? 'https'),
            'region'          => sanitize_text_field($_POST['region'] ?? ''),
            'bucket'          => sanitize_text_field($_POST['bucket'] ?? ''),
            'access_key'      => sanitize_text_field($_POST['access_key'] ?? ''),
            'secret_key'      => sanitize_text_field($_POST['secret_key'] ?? ''),
            'prefix'          => sanitize_text_field($_POST['prefix'] ?? 'wp-backups'),
            'path_style'      => isset($_POST['path_style']) ? 1 : 0,
            'include_db'      => isset($_POST['include_db']) ? 1 : 0,
            'include_themes'  => isset($_POST['include_themes']) ? 1 : 0,
            'include_plugins' => isset($_POST['include_plugins']) ? 1 : 0,
            'schedule'        => sanitize_text_field($_POST['schedule'] ?? ''),
            'backup_time'     => sanitize_text_field($_POST['backup_time'] ?? '02:00'),
            'keep_local'      => max(0, (int) ($_POST['keep_local'] ?? 3)),
            'last_backup'     => $s['last_backup'] ?? 0, // Preserve last backup time
            'notify_enabled'  => isset($_POST['notify_enabled']) ? 1 : 0,
            'notify_on'       => sanitize_text_field($_POST['notify_on'] ?? 'both'),
            'notify_email'    => sanitize_email($_POST['notify_email'] ?? get_option('admin_email')),
        ];

        update_option(ZATA_WPS3B_OPT, $new_settings, false);
        zata_wps3b_apply_schedule();

        $notice = 'Settings saved successfully!';
        if ($new_settings['schedule'] === 'daily') {
            $next = zata_wps3b_get_next_scheduled();
            if ($next) {
                $notice .= ' Next backup: ' . date('Y-m-d H:i:s', $next);
            }
        }
        $notice_type = 'success';
    }

    // 2) Test connection
    if (isset($_POST['zata_wps3b_test']) && check_admin_referer('zata_wps3b_test', 'zata_wps3b_test_nonce')) {
        $s = zata_wps3b_get_settings();
        if (!zata_wps3b_is_remote_configured($s)) {
            zata_wps3b_set_test_status(false, 'Remote not configured.');
            $notice = 'Remote storage not configured.';
            $notice_type = 'error';
        } else {
            $test_key = trim((string)$s['prefix'], '/') . '/.test-' . uniqid();
            $test_body = 'ZATA test: ' . time();

            try {
                zata_wps3b_s3_request($s, 'PUT', $test_key, $test_body);
                zata_wps3b_s3_request($s, 'GET', $test_key);
                zata_wps3b_s3_request($s, 'DELETE', $test_key);
                zata_wps3b_set_test_status(true, 'All operations OK (write/read/delete).');
                $notice = 'Connection test successful! All operations (write/read/delete) passed.';
                $notice_type = 'success';
            } catch (Exception $e) {
                zata_wps3b_set_test_status(false, 'Test failed: ' . $e->getMessage());
                $notice = 'Connection test failed: ' . $e->getMessage();
                $notice_type = 'error';
            }
        }
    }

    // 3) Run backup
    if (isset($_POST['zata_wps3b_run']) && check_admin_referer('zata_wps3b_run', 'zata_wps3b_run_nonce')) {
        zata_wps3b_run_backup('manual');
        $notice = 'Backup completed! Check the logs below for details.';
        $notice_type = 'success';
    }

    // 4) Send test email
    if (isset($_POST['zata_wps3b_test_email']) && check_admin_referer('zata_wps3b_test_email', 'zata_wps3b_test_email_nonce')) {
        $test_email = !empty($s['notify_email']) ? $s['notify_email'] : get_option('admin_email');
        $subject = '[' . get_bloginfo('name') . '] Test Email - ZATA S3 Backup';
        $message = "This is a test email from ZATA S3 Backup plugin.\n\n";
        $message .= "Site: " . home_url() . "\n";
        $message .= "Time: " . current_time('Y-m-d H:i:s') . "\n\n";
        $message .= "If you receive this email, your notification settings are working correctly!\n\n";
        $message .= "---\n";
        $message .= "Plugin URL: " . admin_url('admin.php?page=zata-wps3b') . "\n";
        
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        
        if (wp_mail($test_email, $subject, $message, $headers)) {
            $notice = 'Test email sent successfully to ' . $test_email . '! Check your inbox.';
            $notice_type = 'success';
        } else {
            $notice = 'Failed to send test email. Please check your WordPress email configuration.';
            $notice_type = 'error';
        }
    }

    // Get current settings
    $s = zata_wps3b_get_settings();
    $test = zata_wps3b_get_test_status();
    $log = zata_wps3b_get_log();

    $remote_configured = zata_wps3b_is_remote_configured($s);
    $run_disabled = $remote_configured && !$test['ok'];
    ?>
    <div class="wrap" style="max-width:1200px;">
        <h1>ZATA S3 Backup</h1>
        <p style="color:#666;margin-bottom:24px;">Backup WordPress DB, themes, and plugins to ZATA / S3-compatible storage.</p>

        <?php if ($notice): ?>
            <div class="notice notice-<?php echo $notice_type === 'error' ? 'error' : 'success'; ?> is-dismissible">
                <p><?php echo esc_html($notice); ?></p>
            </div>
        <?php endif; ?>

        <style>
            .zata-provider {display:none;}
            .zata-tile {
                display:flex;align-items:center;gap:12px;
                padding:16px 20px;border:2px solid #ddd;border-radius:6px;
                cursor:pointer;transition:all .2s;
            }
            .zata-tile:hover { border-color:#0073aa; }
            .zata-provider:checked + .zata-tile {
                border-color:#0073aa;background:#f0f8ff;
            }
            .zata-ico {font-size:28px;color:#0073aa;}
            .zata-title {font-weight:600;font-size:15px;}
            .zata-sub {font-size:13px;color:#666;}
            .zata-layout {display:flex;gap:20px;margin-top:20px;flex-wrap:wrap;}
            .zata-card {flex:1;min-width:460px;background:#fff;border:1px solid #ccd0d4;padding:20px;box-shadow:0 1px 1px rgba(0,0,0,.04);}
            .zata-card h2 {margin-top:16px;margin-bottom:10px;font-size:16px;}
            .zata-actions {display:flex;gap:10px;margin-top:14px;}
            .zata-checks {display:flex;flex-direction:column;gap:8px;margin-bottom:16px;}
            .zata-checks label {display:flex;align-items:center;gap:8px;}
            .zata-hint {font-size:13px;color:#666;margin-top:4px;}
            .zata-mono {
                font-family:Consolas,Monaco,monospace;font-size:12px;
                background:#f7f7f7;padding:12px;border:1px solid #ddd;
                border-radius:4px;white-space:pre-wrap;max-height:400px;overflow-y:auto;
            }
            .zata-badge {
                display:inline-block;padding:4px 10px;border-radius:4px;
                font-size:13px;font-weight:600;background:#f0f0f0;color:#666;
            }
            .zata-badge.ok {background:#d4edda;color:#155724;}
            .zata-badge.bad {background:#f8d7da;color:#721c24;}
        </style>

        <form method="post" action="">
            <?php wp_nonce_field('zata_wps3b_save', 'zata_wps3b_nonce'); ?>

            <h2 style="margin-bottom:12px;">Storage Provider</h2>
            <div style="display:flex;gap:16px;margin-bottom:24px;">
                <input class="zata-provider" type="radio" id="prov_zata" name="provider" value="zata" <?php checked($s['provider'], 'zata'); ?>>
                <label class="zata-tile" for="prov_zata">
                    <span class="dashicons dashicons-cloud zata-ico"></span>
                    <div>
                        <div class="zata-title">ZATA (Default)</div>
                        <div class="zata-sub">Pre-filled preset, S3-compatible</div>
                    </div>
                </label>

                <input class="zata-provider" type="radio" id="prov_s3" name="provider" value="s3_generic" <?php checked($s['provider'], 's3_generic'); ?>>
                <label class="zata-tile" for="prov_s3">
                    <span class="dashicons dashicons-database zata-ico"></span>
                    <div>
                        <div class="zata-title">S3-Compatible (Generic)</div>
                        <div class="zata-sub">AWS / MinIO / Wasabi / Ceph RGW</div>
                    </div>
                </label>
            </div>

            <div class="zata-layout">
                <div class="zata-card">
                    <h2 style="margin-top:0;">Destination Settings</h2>
                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th>Endpoint (host only)</th>
                            <td>
                                <input type="text" name="endpoint" id="zata_endpoint" class="regular-text" value="<?php echo esc_attr($s['endpoint']); ?>" placeholder="idr01.zata.ai">
                                <div class="zata-hint">Use host only (no http/https). Example: <code>idr01.zata.ai</code></div>
                            </td>
                        </tr>
                        <tr>
                            <th>Protocol</th>
                            <td>
                                <select name="protocol" id="zata_protocol">
                                    <option value="https" <?php selected($s['protocol'], 'https'); ?>>https</option>
                                    <option value="http" <?php selected($s['protocol'], 'http'); ?>>http</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Region (optional)</th>
                            <td>
                                <input type="text" name="region" class="regular-text" value="<?php echo esc_attr($s['region']); ?>" placeholder="(optional)">
                                <div class="zata-hint">For ZATA, keep blank. For some S3 providers, set required region.</div>
                            </td>
                        </tr>
                        <tr>
                            <th>Bucket</th>
                            <td><input type="text" name="bucket" class="regular-text" value="<?php echo esc_attr($s['bucket']); ?>"></td>
                        </tr>
                        <tr>
                            <th>Access Key</th>
                            <td><input type="text" name="access_key" class="regular-text" value="<?php echo esc_attr($s['access_key']); ?>"></td>
                        </tr>
                        <tr>
                            <th>Secret Key</th>
                            <td><input type="password" name="secret_key" class="regular-text" value="<?php echo esc_attr($s['secret_key']); ?>"></td>
                        </tr>
                        <tr>
                            <th>Key prefix</th>
                            <td><input type="text" name="prefix" class="regular-text" value="<?php echo esc_attr($s['prefix']); ?>"></td>
                        </tr>
                        <tr>
                            <th>Compatibility</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="path_style" <?php checked((int)$s['path_style'], 1); ?>>
                                    Path-style addressing (recommended)
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div class="zata-actions">
                        <button type="submit" class="button button-primary" name="zata_wps3b_save" value="1">Save Settings</button>
                    </div>
                </div>

                <div class="zata-card">
                    <h2 style="margin-top:0;">Backup Content</h2>
                    <div class="zata-checks">
                        <label><input type="checkbox" name="include_db" <?php checked(!empty($s['include_db'])); ?>> Database</label>
                        <label><input type="checkbox" name="include_themes" <?php checked(!empty($s['include_themes'])); ?>> Themes</label>
                        <label><input type="checkbox" name="include_plugins" <?php checked(!empty($s['include_plugins'])); ?>> Plugins</label>
                        <div class="zata-hint">Select what to include in backups.</div>
                    </div>

                    <hr>

                    <h2>Schedule & Retention</h2>
                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th>Backup schedule</th>
                            <td>
                                <select name="schedule" id="backup_schedule">
                                    <option value="" <?php selected($s['schedule'], ''); ?>>Manual only</option>
                                    <option value="daily" <?php selected($s['schedule'], 'daily'); ?>>Daily</option>
                                    <option value="weekly" <?php selected($s['schedule'], 'weekly'); ?>>Weekly</option>
                                </select>
                                <div class="zata-hint">Uses WP-Cron (no server cron required).</div>
                            </td>
                        </tr>
                        <tr id="backup_time_row" style="<?php echo $s['schedule'] !== 'daily' ? 'display:none;' : ''; ?>">
                            <th>Backup time (daily)</th>
                            <td>
                                <input type="time" name="backup_time" id="backup_time" value="<?php echo esc_attr($s['backup_time'] ?? '02:00'); ?>">
                                <div class="zata-hint">Time when daily backup should run (site timezone: <?php echo wp_timezone_string(); ?>).</div>
                            </td>
                        </tr>
                        <tr>
                            <th>Keep local copies</th>
                            <td>
                                <input type="number" name="keep_local" min="0" max="50" value="<?php echo esc_attr((int)$s['keep_local']); ?>">
                                <div class="zata-hint">0 = keep all local backups.</div>
                            </td>
                        </tr>
                    </table>

                    <?php 
                    $next_scheduled = zata_wps3b_get_next_scheduled();
                    $last_backup = !empty($s['last_backup']) ? $s['last_backup'] : 0;
                    ?>
                    
                    <?php if ($s['schedule'] && $next_scheduled): ?>
                        <div style="background:#e7f5ff;border-left:4px solid #0073aa;padding:12px;margin-top:15px;">
                            <strong>📅 Schedule Status</strong><br>
                            <div style="margin-top:6px;">
                                <?php if ($last_backup > 0): ?>
                                    <div style="margin-bottom:4px;">
                                        ⏱️ Last backup: <strong><?php echo date('Y-m-d H:i:s', $last_backup); ?></strong>
                                        <span style="color:#666;">(<?php echo human_time_diff($last_backup, current_time('timestamp')); ?> ago)</span>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    🔜 Next backup: <strong><?php echo date('Y-m-d H:i:s', $next_scheduled); ?></strong>
                                    <span style="color:#666;">(in <?php echo human_time_diff(current_time('timestamp'), $next_scheduled); ?>)</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <hr>

                    <h2>Email Notifications</h2>
                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th>Enable Notifications</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="notify_enabled" id="notify_enabled" <?php checked(!empty($s['notify_enabled'])); ?>>
                                    Send email notifications about backups
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div id="notification_settings" style="<?php echo empty($s['notify_enabled']) ? 'display:none;' : ''; ?>">
                        <table class="form-table" style="margin-top:0;">
                            <tr>
                                <th>Email Address</th>
                                <td>
                                    <input type="email" name="notify_email" class="regular-text" value="<?php echo esc_attr($s['notify_email'] ?? ''); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                                    <div class="zata-hint">Leave blank to use admin email: <strong><?php echo esc_html(get_option('admin_email')); ?></strong></div>
                                </td>
                            </tr>
                            <tr>
                                <th>Notify When</th>
                                <td>
                                    <div style="display:flex;flex-direction:column;gap:8px;">
                                        <label>
                                            <input type="checkbox" name="notify_on_success" <?php checked(!empty($s['notify_on_success'])); ?>>
                                            Backup succeeds ✓
                                        </label>
                                        <label>
                                            <input type="checkbox" name="notify_on_failure" <?php checked(!empty($s['notify_on_failure'])); ?>>
                                            Backup fails ✖
                                        </label>
                                    </div>
                                    <div class="zata-hint">Choose when to receive email notifications.</div>
                                </td>
                            </tr>
                        </table>

                        <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:12px;margin-top:10px;">
                            <strong>📧 Notifications Active</strong><br>
                            <div style="margin-top:6px;font-size:13px;">
                                Recipient: <strong><?php echo esc_html(!empty($s['notify_email']) ? $s['notify_email'] : get_option('admin_email')); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- Separate forms for Test and Backup -->
        <div class="zata-layout" style="margin-top:20px;">
            <div class="zata-card">
                <h2 style="margin-top:0;">Connection</h2>
                <div style="margin:6px 0 10px;">
                    <?php if ($remote_configured): ?>
                        <?php if ($test['ok']): ?>
                            <span class="zata-badge ok">Test: OK</span>
                        <?php else: ?>
                            <span class="zata-badge bad">Test: Required</span>
                        <?php endif; ?>
                        <span class="zata-hint" style="display:block;margin-top:6px;">
                            Test verifies <strong>read/write/delete</strong> on the bucket.
                        </span>
                    <?php else: ?>
                        <span class="zata-badge">Remote: Not configured</span>
                        <span class="zata-hint" style="display:block;margin-top:6px;">
                            Configure endpoint/bucket/keys to enable remote upload and testing.
                        </span>
                    <?php endif; ?>
                </div>

                <div class="zata-actions">
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('zata_wps3b_test', 'zata_wps3b_test_nonce'); ?>
                        <button type="submit" class="button button-secondary" name="zata_wps3b_test" value="1" <?php disabled(!$remote_configured); ?>>
                            Test Connection
                        </button>
                    </form>

                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('zata_wps3b_run', 'zata_wps3b_run_nonce'); ?>
                        <button type="submit" class="button button-primary" name="zata_wps3b_run" value="1" <?php disabled($run_disabled); ?>>
                            Start Backup
                        </button>
                    </form>
                </div>

                <?php if ($run_disabled): ?>
                    <div class="zata-hint" style="margin-top:8px;">
                        Please run <strong>Test Connection</strong> before starting backup (remote is configured).
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <hr>
        <h2>Logs</h2>
        <div class="zata-mono"><?php echo esc_html($log); ?></div>

        <script>
            (function(){
                const zataRadio = document.getElementById('prov_zata');
                const endpoint  = document.getElementById('zata_endpoint');
                const protocol  = document.getElementById('zata_protocol');

                function applyPreset(){
                    if (zataRadio && zataRadio.checked){
                        if (!endpoint.value) endpoint.value = 'idr01.zata.ai';
                        if (!protocol.value) protocol.value = 'https';
                    }
                }
                if (zataRadio) zataRadio.addEventListener('change', applyPreset);
                applyPreset();

                // Show/hide backup time based on schedule selection
                const scheduleSelect = document.getElementById('backup_schedule');
                const timeRow = document.getElementById('backup_time_row');
                
                if (scheduleSelect && timeRow) {
                    scheduleSelect.addEventListener('change', function() {
                        if (this.value === 'daily') {
                            timeRow.style.display = '';
                        } else {
                            timeRow.style.display = 'none';
                        }
                    });
                }

                // Show/hide notification settings based on checkbox
                const notifyCheckbox = document.getElementById('notify_enabled');
                const notifySettings = document.getElementById('notification_settings');
                
                if (notifyCheckbox && notifySettings) {
                    notifyCheckbox.addEventListener('change', function() {
                        if (this.checked) {
                            notifySettings.style.display = '';
                        } else {
                            notifySettings.style.display = 'none';
                        }
                    });
                }
            })();
        </script>
    </div>
    <?php
}