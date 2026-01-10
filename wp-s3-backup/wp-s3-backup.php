<?php
/**
 * Plugin Name: ZATA S3 Backup
 * Description: Backup WordPress DB + themes + plugins and upload to ZATA / S3-compatible storage (ZATA default). Includes test connection. No shell_exec.
 * Version: 1.0.7
 * Author: Radhe D
 * License: MIT
 */

if (!defined('ABSPATH')) exit;

define('ZATA_WPS3B_OPT', 'zata_wps3b_settings');
define('ZATA_WPS3B_LOG', 'zata_wps3b_last_log');
define('ZATA_WPS3B_TEST', 'zata_wps3b_test_status');
define('ZATA_WPS3B_CRON_HOOK', 'zata_wps3b_cron_backup');

/**
 * Ensure default settings exist
 */
function zata_wps3b_default_settings() {
    $defaults = [
        // Provider
        'provider'     => 'zata',          // zata | s3_generic

        // Destination
        'endpoint'     => 'idr01.zata.ai',  // host only
        'protocol'     => 'https',         // https | http
        'region'       => '',              // optional
        'bucket'       => '',
        'access_key'   => '',
        'secret_key'   => '',
        'prefix'       => 'wp-backups',
        'path_style'   => 1,               // 1 => path-style

        // Content selection
        'include_db'      => 1,
        'include_themes'  => 1,
        'include_plugins' => 1,

        // Schedule & retention
        'schedule'        => '',           // '' | daily | weekly
        'backup_time'     => '02:00',      // HH:MM format for daily backups
        'keep_local'      => 3,            // per type (db/themes/plugins). 0 = keep all
        'last_backup'     => 0,            // timestamp of last backup
        
        // Email notifications
        'notify_enabled'  => 0,            // 1 = enabled, 0 = disabled
        'notify_on'       => 'both',       // 'success' | 'failure' | 'both'
        'notify_email'    => get_option('admin_email'), // recipient email
    ];

    $cur = get_option(ZATA_WPS3B_OPT);
    if (!is_array($cur)) {
        update_option(ZATA_WPS3B_OPT, $defaults, false);
        return $defaults;
    }

    $merged = array_merge($defaults, $cur);
    if ($merged !== $cur) update_option(ZATA_WPS3B_OPT, $merged, false);

    return $merged;
}

register_activation_hook(__FILE__, function () {
    zata_wps3b_default_settings();
    zata_wps3b_apply_schedule();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(ZATA_WPS3B_CRON_HOOK);
});

/**
 * Apply WP-Cron schedule with time control
 */
function zata_wps3b_apply_schedule() {
    $s = zata_wps3b_default_settings();

    wp_clear_scheduled_hook(ZATA_WPS3B_CRON_HOOK);

    if ($s['schedule'] === 'daily') {
        // Calculate next run time based on backup_time setting
        $backup_time = isset($s['backup_time']) ? $s['backup_time'] : '02:00';
        $next_run = zata_wps3b_calculate_next_daily_run($backup_time);
        
        if (!wp_next_scheduled(ZATA_WPS3B_CRON_HOOK)) {
            wp_schedule_event($next_run, 'daily', ZATA_WPS3B_CRON_HOOK);
        }
    } elseif ($s['schedule'] === 'weekly') {
        if (!wp_next_scheduled(ZATA_WPS3B_CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'weekly', ZATA_WPS3B_CRON_HOOK);
        }
    }
}

/**
 * Calculate next daily run time based on user preference
 */
function zata_wps3b_calculate_next_daily_run($time_string) {
    // Parse HH:MM format
    list($hour, $minute) = explode(':', $time_string);
    $hour = (int)$hour;
    $minute = (int)$minute;
    
    // Get current time in site timezone
    $now = current_time('timestamp');
    $today = strtotime(gmdate('Y-m-d', $now) . " {$hour}:{$minute}:00");
    
    // If today's time has passed, schedule for tomorrow
    if ($today <= $now) {
        $today = strtotime('+1 day', $today);
    }
    
    return $today;
}

/**
 * Get next scheduled backup time
 */
function zata_wps3b_get_next_scheduled() {
    $timestamp = wp_next_scheduled(ZATA_WPS3B_CRON_HOOK);
    if (!$timestamp) {
        return null;
    }
    return $timestamp;
}

add_action(ZATA_WPS3B_CRON_HOOK, function () {
    zata_wps3b_run_backup('cron');
});

/**
 * Send email notification for backup result
 */
function zata_wps3b_send_notification($success, $log_text, $mode = 'manual') {
    $s = zata_wps3b_default_settings();
    
    // Check if notifications are enabled
    if (empty($s['notify_enabled'])) {
        return;
    }
    
    // Check if we should notify for this result
    $notify_on = $s['notify_on'] ?? 'both';
    if ($notify_on === 'success' && !$success) return;
    if ($notify_on === 'failure' && $success) return;
    
    // Get recipient
    $to = !empty($s['notify_email']) ? $s['notify_email'] : get_option('admin_email');
    
    // Prepare subject
    $site_name = get_bloginfo('name');
    $status = $success ? '✓ SUCCESS' : '✖ FAILED';
    $subject = "[{$site_name}] Backup {$status} - " . gmdate('Y-m-d H:i:s');
    
    // Prepare message
    $message = "WordPress Backup Report\n";
    $message .= "========================\n\n";
    $message .= "Site: " . home_url() . "\n";
    $message .= "Status: " . ($success ? 'SUCCESS' : 'FAILED') . "\n";
    $message .= "Mode: " . strtoupper($mode) . "\n";
    $message .= "Time: " . current_time('Y-m-d H:i:s') . "\n\n";
    
    $message .= "Backup Configuration:\n";
    $message .= "-------------------\n";
    $message .= "Database: " . (!empty($s['include_db']) ? 'Yes' : 'No') . "\n";
    $message .= "Themes: " . (!empty($s['include_themes']) ? 'Yes' : 'No') . "\n";
    $message .= "Plugins: " . (!empty($s['include_plugins']) ? 'Yes' : 'No') . "\n";
    $message .= "Remote Storage: " . (zata_wps3b_is_remote_configured($s) ? 'Enabled (' . $s['provider'] . ')' : 'Disabled') . "\n\n";
    
    $message .= "Detailed Log:\n";
    $message .= "============\n";
    $message .= $log_text . "\n\n";
    
    $message .= "---\n";
    $message .= "This is an automated message from ZATA S3 Backup plugin.\n";
    $message .= "Plugin URL: " . admin_url('admin.php?page=zata-wps3b') . "\n";
    
    // Set headers
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    
    // Send email
    wp_mail($to, $subject, $message, $headers);
}

/**
 * Load admin UI + logic
 */
require_once __DIR__ . '/admin.php';