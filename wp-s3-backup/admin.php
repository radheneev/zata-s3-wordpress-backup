<?php
if (!defined('ABSPATH')) exit;

function wps3b_default_settings(): array {
  return [
    'provider' => 'zata',
    'endpoint' => 'https://idr01.zata.ai',
    'region' => 'CentralIndia',
    'bucket' => '',
    'access_key' => '',
    'secret_key' => '',
    'key_prefix' => 'wp-backups',
    'path_style' => 1,
    'insecure_tls' => 0,

    'include_db' => 1,
    'include_themes' => 1,
    'include_plugins' => 1,
    'keep_local' => 3,

    'schedule' => 'daily',
    'custom_minutes' => 60,

    'notify_enabled' => 0,
    'notify_on_success' => 0,
    'notify_email' => get_option('admin_email'),
  ];
}

/**
 * Admin menu (Dashicon for sidebar)
 */
add_action('admin_menu', function () {
  add_menu_page(
    'ZATA S3 WordPress Backup',
    'ZATA S3 Backup',
    'manage_options',
    WPS3B_SLUG,
    'wps3b_render_settings_page',
    'dashicons-cloud-upload',
    58
  );

  add_submenu_page(
    WPS3B_SLUG,
    'Settings',
    'Settings',
    'manage_options',
    WPS3B_SLUG,
    'wps3b_render_settings_page'
  );
});

/**
 * Load CSS/JS only on this plugin admin page
 */
add_action('admin_enqueue_scripts', function ($hook) {
  if ($hook !== 'toplevel_page_' . WPS3B_SLUG) return;

  wp_enqueue_style('wps3b-admin', plugins_url('assets/admin.css', __FILE__), [], WPS3B_VERSION);
  wp_enqueue_script('wps3b-admin', plugins_url('assets/admin.js', __FILE__), ['jquery'], WPS3B_VERSION, true);

  wp_localize_script('wps3b-admin', 'WPS3B_PRESETS', [
    'zata' => [
      'endpoint' => 'https://idr01.zata.ai',
      'region' => 'CentralIndia',
      'prefix' => 'wp-backups',
      'path_style' => true,
    ],
    'aws' => [
      'endpoint' => 'https://s3.amazonaws.com',
      'region' => 'ap-south-1',
      'prefix' => 'wp-backups',
      'path_style' => false,
    ],
    'minio' => [
      'endpoint' => 'https://rgw.example.com',
      'region' => 'us-east-1',
      'prefix' => 'wp-backups',
      'path_style' => true,
    ],
  ]);
});

/**
 * Register settings + defaults
 */
add_action('admin_init', function () {
  $existing = get_option(WPS3B_OPT, null);
  if ($existing === null) {
    add_option(WPS3B_OPT, wps3b_default_settings());
  } else {
    $merged = array_merge(wps3b_default_settings(), is_array($existing) ? $existing : []);
    if ($merged !== $existing) update_option(WPS3B_OPT, $merged);
  }

  register_setting('wps3b_group', WPS3B_OPT, [
    'sanitize_callback' => 'wps3b_sanitize_settings',
  ]);

  add_action('update_option_' . WPS3B_OPT, function ($old, $new) {
    wps3b_reschedule_cron(is_array($new) ? $new : []);
  }, 10, 2);
});

/**
 * Custom schedule interval
 */
add_filter('cron_schedules', function ($schedules) {
  $opt = get_option(WPS3B_OPT, []);
  $mins = max(5, (int)($opt['custom_minutes'] ?? 60));
  $schedules['wps3b_custom'] = [
    'interval' => $mins * 60,
    'display' => "ZATA S3 Backup (every {$mins} minutes)",
  ];
  return $schedules;
});

function wps3b_sanitize_settings($in) {
  $defaults = wps3b_default_settings();
  $out = [];

  $out['provider']     = isset($in['provider']) ? sanitize_text_field($in['provider']) : $defaults['provider'];

  $out['endpoint']     = isset($in['endpoint']) ? esc_url_raw(trim($in['endpoint'])) : $defaults['endpoint'];
  $out['region']       = isset($in['region']) ? sanitize_text_field(trim($in['region'])) : $defaults['region'];
  $out['bucket']       = isset($in['bucket']) ? sanitize_text_field(trim($in['bucket'])) : '';
  $out['access_key']   = isset($in['access_key']) ? sanitize_text_field(trim($in['access_key'])) : '';
  $out['secret_key']   = isset($in['secret_key']) ? sanitize_text_field(trim($in['secret_key'])) : '';

  $out['key_prefix']   = isset($in['key_prefix']) ? sanitize_text_field(trim($in['key_prefix'])) : $defaults['key_prefix'];

  $out['path_style']   = !empty($in['path_style']) ? 1 : 0;
  $out['insecure_tls'] = !empty($in['insecure_tls']) ? 1 : 0;

  $out['include_db']      = !empty($in['include_db']) ? 1 : 0;
  $out['include_themes']  = !empty($in['include_themes']) ? 1 : 0;
  $out['include_plugins'] = !empty($in['include_plugins']) ? 1 : 0;

  $out['keep_local'] = isset($in['keep_local']) ? max(0, (int)$in['keep_local']) : $defaults['keep_local'];

  $allowed = ['disabled', 'hourly', 'twicedaily', 'daily', 'weekly', 'wps3b_custom'];
  $out['schedule'] = in_array(($in['schedule'] ?? $defaults['schedule']), $allowed, true) ? $in['schedule'] : $defaults['schedule'];
  $out['custom_minutes'] = isset($in['custom_minutes']) ? max(5, (int)$in['custom_minutes']) : $defaults['custom_minutes'];

  $out['notify_enabled'] = !empty($in['notify_enabled']) ? 1 : 0;
  $out['notify_on_success'] = !empty($in['notify_on_success']) ? 1 : 0;
  $out['notify_email']   = isset($in['notify_email']) ? sanitize_email(trim($in['notify_email'])) : get_option('admin_email');

  return $out;
}

/**
 * Cron schedule handler
 */
function wps3b_reschedule_cron(array $s) {
  $hook = 'wps3b_run_backup_cron';

  $timestamp = wp_next_scheduled($hook);
  while ($timestamp) {
    wp_unschedule_event($timestamp, $hook);
    $timestamp = wp_next_scheduled($hook);
  }

  if (($s['schedule'] ?? 'daily') === 'disabled') return;

  $recurrence = $s['schedule'] ?? 'daily';
  if (!in_array($recurrence, ['hourly', 'twicedaily', 'daily', 'weekly', 'wps3b_custom'], true)) {
    $recurrence = 'daily';
  }

  wp_schedule_event(time() + 300, $recurrence, $hook);
}

/**
 * Actions: download/clear logs
 */
add_action('admin_post_wps3b_download_log', function () {
  if (!current_user_can('manage_options')) wp_die('Unauthorized');
  check_admin_referer('wps3b_download_log');

  $file = function_exists('wps3b_log_file') ? wps3b_log_file() : '';
  if (!$file || !file_exists($file)) wp_die('Log file not found');

  header('Content-Type: text/plain');
  header('Content-Disposition: attachment; filename="wps3b.log"');
  readfile($file);
  exit;
});

add_action('admin_post_wps3b_clear_log', function () {
  if (!current_user_can('manage_options')) wp_die('Unauthorized');
  check_admin_referer('wps3b_clear_log');

  $file = function_exists('wps3b_log_file') ? wps3b_log_file() : '';
  if ($file && file_exists($file)) @unlink($file);

  update_option(WPS3B_HISTORY_OPT, []);

  $redirect = add_query_arg([
    'page' => WPS3B_SLUG,
    'tab' => 'logs',
    'wps3b_msg' => 'success',
    'wps3b_detail' => rawurlencode('Logs cleared.'),
  ], admin_url('admin.php'));

  wp_safe_redirect($redirect);
  exit;
});

/**
 * (i) tooltip icon helper
 */
function wps3b_info_icon(string $text): string {
  return '<span class="wps3b-info dashicons dashicons-info-outline" title="' . esc_attr($text) . '"></span>';
}

/**
 * Settings + Logs page (tabs)
 */
function wps3b_render_settings_page() {
  if (!current_user_can('manage_options')) return;

  $opt = get_option(WPS3B_OPT, []);
  $opt = is_array($opt) ? $opt : [];
  $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';

  $msg = isset($_GET['wps3b_msg']) ? sanitize_text_field($_GET['wps3b_msg']) : '';
  $detail = isset($_GET['wps3b_detail']) ? sanitize_text_field($_GET['wps3b_detail']) : '';

  $zata_link = 'https://zata.ai';
  $logo_url = plugins_url('assets/zata_icon.png', __FILE__);
  ?>
  <div class="wrap wps3b-wrap">
    <div class="wps3b-header">
      <div class="wps3b-brand">
        <img src="<?php echo esc_url($logo_url); ?>" alt="ZATA" />
        <div>
          <div class="wps3b-title">ZATA S3 WordPress Backup</div>
          <div class="wps3b-subtitle">Separate ZIPs → Upload to ZATA / S3-compatible storage</div>
        </div>
      </div>
      <div class="wps3b-cta">
        Don’t have a ZATA account?
        <a class="button button-secondary" href="<?php echo esc_url($zata_link); ?>" target="_blank" rel="noopener noreferrer">Go to zata.ai</a>
      </div>
    </div>

    <h2 class="nav-tab-wrapper">
      <a class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page'=>WPS3B_SLUG,'tab'=>'settings'], admin_url('admin.php'))); ?>">Settings</a>
      <a class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page'=>WPS3B_SLUG,'tab'=>'logs'], admin_url('admin.php'))); ?>">Logs</a>
    </h2>

    <?php if ($msg): ?>
      <div class="notice <?php echo $msg === 'success' ? 'notice-success' : 'notice-error'; ?> is-dismissible">
        <p><strong><?php echo esc_html($detail); ?></strong></p>
      </div>
    <?php endif; ?>

    <?php if ($tab === 'logs'): ?>
      <?php wps3b_render_logs_tab(); ?>
      <?php return; ?>
    <?php endif; ?>

    <form method="post" action="options.php">
      <?php settings_fields('wps3b_group'); ?>

      <div class="wps3b-card">
        <h2>Provider Preset</h2>
        <table class="form-table">
          <tr>
            <th>Preset <?php echo wps3b_info_icon('Quick-fill endpoint/region/path-style for ZATA/AWS/MinIO. You can still edit manually.'); ?></th>
            <td>
              <?php $provider = $opt['provider'] ?? 'zata'; ?>
              <select id="wps3b_provider" name="<?php echo esc_attr(WPS3B_OPT); ?>[provider]">
                <option value="zata" <?php selected($provider, 'zata'); ?>>ZATA (Central India)</option>
                <option value="aws" <?php selected($provider, 'aws'); ?>>AWS S3</option>
                <option value="minio" <?php selected($provider, 'minio'); ?>>MinIO / Ceph RGW</option>
                <option value="custom" <?php selected($provider, 'custom'); ?>>Custom</option>
              </select>
              <p class="description">Recommended preset: ZATA (Central India).</p>
            </td>
          </tr>
        </table>
      </div>

      <div class="wps3b-grid">
        <div class="wps3b-card">
          <h2>Backup Content</h2>
          <table class="form-table">
            <tr>
              <th>Include <?php echo wps3b_info_icon('Backups are created as separate ZIPs (DB, Themes, Plugins) for easy restore.'); ?></th>
              <td>
                <label><input type="checkbox" name="<?php echo esc_attr(WPS3B_OPT); ?>[include_db]" value="1" <?php checked(!empty($opt['include_db'])); ?> /> Database</label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(WPS3B_OPT); ?>[include_themes]" value="1" <?php checked(!empty($opt['include_themes'])); ?> /> Themes</label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(WPS3B_OPT); ?>[include_plugins]" value="1" <?php checked(!empty($opt['include_plugins'])); ?> /> Plugins</label>
              </td>
            </tr>
            <tr>
              <th>Keep local ZIP copies <?php echo wps3b_info_icon('Keeps the latest N ZIPs in wp-content/uploads/wps3b-backups. Set 0 to keep none locally.'); ?></th>
              <td>
                <input type="number" min="0" name="<?php echo esc_attr(WPS3B_OPT); ?>[keep_local]" value="<?php echo esc_attr((int)($opt['keep_local'] ?? 3)); ?>" />
              </td>
            </tr>
          </table>
        </div>

        <div class="wps3b-card">
          <h2>S3 / Object Storage</h2>
          <table class="form-table">
            <tr>
              <th>Endpoint <?php echo wps3b_info_icon('ZATA Central India endpoint: https://idr01.zata.ai. For MinIO/Ceph RGW use your RGW base URL.'); ?></th>
              <td>
                <input type="text" class="regular-text" id="wps3b_endpoint"
                  name="<?php echo esc_attr(WPS3B_OPT); ?>[endpoint]"
                  value="<?php echo esc_attr($opt['endpoint'] ?? ''); ?>" />
              </td>
            </tr>
            <tr>
              <th>Region <?php echo wps3b_info_icon('For ZATA use CentralIndia. For AWS use region like ap-south-1.'); ?></th>
              <td>
                <input type="text" class="regular-text" id="wps3b_region"
                  name="<?php echo esc_attr(WPS3B_OPT); ?>[region]"
                  value="<?php echo esc_attr($opt['region'] ?? 'CentralIndia'); ?>" />
              </td>
            </tr>
            <tr>
              <th>Bucket <?php echo wps3b_info_icon('Bucket must already exist in your S3 storage. Example: wp-backups.'); ?></th>
              <td><input type="text" class="regular-text" name="<?php echo esc_attr(WPS3B_OPT); ?>[bucket]" value="<?php echo esc_attr($opt['bucket'] ?? ''); ?>" /></td>
            </tr>
            <tr>
              <th>Access Key <?php echo wps3b_info_icon('Create/view credentials in your ZATA dashboard under S3 credentials (Access Key / Secret Key).'); ?></th>
              <td><input type="text" class="regular-text" name="<?php echo esc_attr(WPS3B_OPT); ?>[access_key]" value="<?php echo esc_attr($opt['access_key'] ?? ''); ?>" /></td>
            </tr>
            <tr>
              <th>Secret Key <?php echo wps3b_info_icon('Keep this secret. It is stored in WordPress options. Do not share it.'); ?></th>
              <td><input type="password" class="regular-text" name="<?php echo esc_attr(WPS3B_OPT); ?>[secret_key]" value="<?php echo esc_attr($opt['secret_key'] ?? ''); ?>" autocomplete="new-password" /></td>
            </tr>
            <tr>
              <th>Key prefix <?php echo wps3b_info_icon('Remote path prefix in bucket. Uploads to prefix/db, prefix/themes, prefix/plugins.'); ?></th>
              <td>
                <input type="text" class="regular-text" id="wps3b_prefix" name="<?php echo esc_attr(WPS3B_OPT); ?>[key_prefix]" value="<?php echo esc_attr($opt['key_prefix'] ?? 'wp-backups'); ?>" />
              </td>
            </tr>
            <tr>
              <th>Compatibility</th>
              <td>
                <label>
                  <input type="checkbox" id="wps3b_path_style" name="<?php echo esc_attr(WPS3B_OPT); ?>[path_style]" value="1" <?php checked(!empty($opt['path_style'])); ?> />
                  Path-style addressing
                  <?php echo wps3b_info_icon('Enable for ZATA/MinIO/Ceph RGW. Disable for AWS virtual-host style.'); ?>
                </label><br>
                <label>
                  <input type="checkbox" name="<?php echo esc_attr(WPS3B_OPT); ?>[insecure_tls]" value="1" <?php checked(!empty($opt['insecure_tls'])); ?> />
                  Allow insecure TLS (self-signed)
                  <?php echo wps3b_info_icon('Only enable for self-signed object storage endpoints.'); ?>
                </label>
              </td>
            </tr>
          </table>
        </div>
      </div>

      <div class="wps3b-grid">
        <div class="wps3b-card">
          <h2>Schedule</h2>
          <table class="form-table">
            <tr>
              <th>Backup schedule <?php echo wps3b_info_icon('WordPress wp-cron runs on site visits. For exact timing, trigger wp-cron.php via server cron.'); ?></th>
              <td>
                <?php $schedule = $opt['schedule'] ?? 'daily'; ?>
                <select name="<?php echo esc_attr(WPS3B_OPT); ?>[schedule]">
                  <option value="disabled" <?php selected($schedule, 'disabled'); ?>>Disabled</option>
                  <option value="hourly" <?php selected($schedule, 'hourly'); ?>>Hourly</option>
                  <option value="twicedaily" <?php selected($schedule, 'twicedaily'); ?>>Twice Daily</option>
                  <option value="daily" <?php selected($schedule, 'daily'); ?>>Daily</option>
                  <option value="weekly" <?php selected($schedule, 'weekly'); ?>>Weekly</option>
                  <option value="wps3b_custom" <?php selected($schedule, 'wps3b_custom'); ?>>Custom (minutes)</option>
                </select>

                <div style="margin-top:8px;">
                  <label>Custom minutes:
                    <input type="number" min="5" name="<?php echo esc_attr(WPS3B_OPT); ?>[custom_minutes]" value="<?php echo esc_attr((int)($opt['custom_minutes'] ?? 60)); ?>" />
                    <?php echo wps3b_info_icon('Used only when schedule = Custom. Minimum 5 minutes.'); ?>
                  </label>
                </div>

                <?php $next = wp_next_scheduled('wps3b_run_backup_cron'); ?>
                <p class="description"><strong>Next scheduled run:</strong> <?php echo esc_html($next ? date_i18n('Y-m-d H:i:s', $next) : '(not scheduled)'); ?></p>
              </td>
            </tr>
          </table>
        </div>

        <div class="wps3b-card">
          <h2>Email Notifications</h2>
          <table class="form-table">
            <tr>
              <th>Enable <?php echo wps3b_info_icon('Send email on failure. Optional: send on success too.'); ?></th>
              <td>
                <label><input type="checkbox" name="<?php echo esc_attr(WPS3B_OPT); ?>[notify_enabled]" value="1" <?php checked(!empty($opt['notify_enabled'])); ?> />
                  Send email on backup result</label><br>
                <label><input type="checkbox" name="<?php echo esc_attr(WPS3B_OPT); ?>[notify_on_success]" value="1" <?php checked(!empty($opt['notify_on_success'])); ?> />
                  Also notify on success</label>
              </td>
            </tr>
            <tr>
              <th>Notify email <?php echo wps3b_info_icon('Default is WordPress admin email.'); ?></th>
              <td><input type="email" class="regular-text" name="<?php echo esc_attr(WPS3B_OPT); ?>[notify_email]" value="<?php echo esc_attr($opt['notify_email'] ?? get_option('admin_email')); ?>" /></td>
            </tr>
          </table>
        </div>
      </div>

      <?php submit_button('Save Settings'); ?>
    </form>

    <div class="wps3b-actions">
      <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wps3b_run_now'), 'wps3b_run_now')); ?>">
        Run Backup Now
      </a>
      <a class="button" href="<?php echo esc_url(add_query_arg(['page'=>WPS3B_SLUG,'tab'=>'logs'], admin_url('admin.php'))); ?>">
        View Logs
      </a>
    </div>
  </div>
  <?php
}

function wps3b_render_logs_tab() {
  if (!current_user_can('manage_options')) return;

  $hist = get_option(WPS3B_HISTORY_OPT, []);
  $hist = is_array($hist) ? $hist : [];

  $log_path = function_exists('wps3b_log_file') ? wps3b_log_file() : '';
  $log_exists = $log_path && file_exists($log_path);
  ?>
  <div class="wps3b-card">
    <h2>Backup History</h2>

    <div class="wps3b-log-actions">
      <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wps3b_run_now'), 'wps3b_run_now')); ?>">Run Backup Now</a>
      <?php if ($log_exists): ?>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wps3b_download_log'), 'wps3b_download_log')); ?>">Download Log</a>
      <?php endif; ?>
      <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wps3b_clear_log'), 'wps3b_clear_log')); ?>"
         onclick="return confirm('Clear history and delete log file?');">Clear Logs</a>
    </div>

    <?php if (empty($hist)): ?>
      <p class="description">No runs yet. Click “Run Backup Now”.</p>
    <?php else: ?>
      <table class="widefat striped">
        <thead>
          <tr>
            <th>Time</th>
            <th>Status</th>
            <th>Duration</th>
            <th>Uploaded</th>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($hist as $row): ?>
            <tr>
              <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)($row['time'] ?? time()))); ?></td>
              <td>
                <span class="wps3b-badge <?php echo ($row['status'] ?? '') === 'SUCCESS' ? 'ok' : 'bad'; ?>">
                  <?php echo esc_html($row['status'] ?? ''); ?>
                </span>
              </td>
              <td><?php echo esc_html((int)($row['duration_sec'] ?? 0) . 's'); ?></td>
              <td>
                <?php
                  $keys = $row['uploaded_keys'] ?? [];
                  if (is_array($keys) && count($keys)) {
                    echo '<code>' . esc_html(implode(", ", $keys)) . '</code>';
                  } else {
                    echo '-';
                  }
                ?>
              </td>
              <td><?php echo esc_html($row['message'] ?? ''); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="wps3b-card">
    <h2>Latest Log Lines</h2>
    <?php
      if (!$log_exists) {
        echo '<p class="description">Log file not created yet. Run a backup to generate logs.</p>';
        return;
      }

      $lines = @file($log_path, FILE_IGNORE_NEW_LINES);
      $lines = is_array($lines) ? $lines : [];
      $tail = array_slice($lines, -120);
    ?>
    <pre class="wps3b-logbox"><?php echo esc_html(implode("\n", $tail)); ?></pre>
  </div>
  <?php
}