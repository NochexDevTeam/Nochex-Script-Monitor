<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Adds a custom weekly interval to WordPress cron schedules
add_filter('cron_schedules', function ($schedules) {
    $schedules['weekly'] = [
        'interval' => 604800, // 1 week in seconds
        'display' => __('Once Weekly'),
    ];
    return $schedules;
});

// Hooks the weekly report email function to the custom cron event
add_action('send_weekly_report_email_event', 'send_weekly_report_email');

// Sends the weekly script monitor report email to the configured address
function send_weekly_report_email() {
    $email = get_option('weekly_report_email', '');

    if (empty($email)) {
        return; // No email configured
    }

    $subject = 'Weekly Script Monitor Report';
    $message = generate_weekly_report();
    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    wp_mail($email, $subject, $message, $headers);
}

// Generates the weekly report of new scripts found in the last week
function generate_weekly_report() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'script_monitor';

    // Get all scripts logged in the past week that are not authorized or declined
    $one_week_ago = date('Y-m-d H:i:s', strtotime('-1 week'));
    $new_scripts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT script_type, script_src, script_content, last_updated 
             FROM $table_name 
             WHERE last_updated >= %s 
             AND script_type NOT IN ('authorized', 'declined', 'internal')",
            $one_week_ago
        ),
        ARRAY_A
    );

    if (empty($new_scripts)) {
        return 'No new findings in the past week.';
    }

    // Format the report
    $report = "Weekly Script Monitor Report:\n\n";
    foreach ($new_scripts as $script) {
        $report .= "Type: " . esc_html($script['script_type']) . "\n";
        $report .= "Source: " . esc_html($script['script_src']) . "\n";
        $report .= "Content: " . esc_html(wp_trim_words($script['script_content'], 20, '...')) . "\n";
        $report .= "Last Updated: " . esc_html($script['last_updated']) . "\n";
        $report .= "----------------------------------------\n";
    }

    return $report;
}