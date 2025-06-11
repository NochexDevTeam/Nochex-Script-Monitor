<?php
// Renders the Script Monitor configuration admin page and handles form submissions
function script_monitor_config_page() {
    // Handle alert email save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alert_emails'])) {
        $emails = sanitize_text_field($_POST['alert_emails']);
        update_option('script_monitor_alert_emails', $emails);
        echo '<div class="notice notice-success"><p>Alert email addresses updated successfully.</p></div>';
    }

    // Handle test email
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
        $alert_emails = get_option('script_monitor_alert_emails', '');
        $emails = array_filter(array_map('trim', explode(',', $alert_emails)));
        $sent = false;
        if (!empty($emails)) {
            $subject = 'Test Email - Nochex Script Monitor';
            $message = 'This is a successful test email from your Nochex Script Monitor plugin.';
            $headers = ['Content-Type: text/plain; charset=UTF-8;']; 
			
            foreach ($emails as $email) {
                if (is_email($email) && wp_mail($email, $subject, $message, $headers)) {
                    $sent = true;
                }
            }
            if ($sent) {
                echo '<div class="notice notice-success"><p>Test email sent successfully to: ' . esc_html(implode(', ', $emails)) . '.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to send test email. Please check your email configuration.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>No alert email addresses configured. Please save at least one email address first.</p></div>';
        }
    }

    $alert_emails = get_option('script_monitor_alert_emails', '');
    ?>
    <div class="wrap">
        <h1>Nochex Script Monitor - Email Configuration</h1>
         <p style="max-width:600px;">This plugin monitors all scripts loaded on your WordPress site including inline, internal, external, and dynamically created scripts. It helps you review, authorize, or decline scripts for improved security and visibility, and sends alerts when new or changed scripts are detected. This plugin is to assist you with monitoring scripts to help secure your website and maintain compliance with PCI DSS</p>
	<p style="max-width:600px;">If a script has a status of declined, the script will not load in your website!</span> But you can change the status of scripts at anytime without restriction</p>
	
<form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="alert_emails">Alert Email Addresses</label></th>
                    <td>
                        <input type="text" name="alert_emails" id="alert_emails" value="<?php echo esc_attr($alert_emails); ?>" class="regular-text">
                        <p class="description">Enter one or more email addresses, separated by commas, to receive immediate alerts for new script changes.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary">Save Changes</button>
            </p>
        </form>
        <form method="post" class="alignleft">
            <input type="hidden" name="send_test_email" value="1">
            <p class="submit">
                <button type="submit" class="button">Send Test Email</button>
            </p>
        </form>
        <form method="post" class="alignleft" style="margin-left:20px;">
            <input type="hidden" name="trigger_pending_alert" value="1">
            <p class="submit">
                <button type="submit" class="button">Send Pending Alert Now</button>
            </p>
        </form>
        <form method="post" class="alignleft" style="margin-left:20px;">
            <input type="hidden" name="force_trigger_pending_alert" value="1">
            <p class="submit">
                <button type="submit" class="button button-danger">Force Send Pending Alert (Ignore Rate Limit)</button>
            </p>
        </form>
    </div>
    <?php
    // Handle manual alert trigger
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trigger_pending_alert'])) {
        $last_sent = get_option('script_monitor_last_alert_time', 0);
        $now = time();
        $interval = 86400; // 24 hours
        if ($now - intval($last_sent) < $interval) {
            echo '<div class="notice notice-warning" style="width:30%; padding:5px;"><p>Alert was already sent within the last hour.</p></div>';
        } else {
            script_monitor_send_pending_alerts();
            echo '<div class="notice notice-success" style="width:30%; padding:5px;"><p>Pending alert sent successfully.</p></div>';
        }
    }
    // Handle force alert trigger (ignore rate limit)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['force_trigger_pending_alert'])) {
        // Reset last alert time to allow sending
        update_option('script_monitor_last_alert_time', 0);
        script_monitor_send_pending_alerts();
        echo '<div class="notice notice-success" style="width:30%; padding:5px;"><p>Pending alert sent (rate limit ignored).</p></div>';
    }
}

// Sends an immediate alert email when a new script is detected
function send_script_monitor_alert($script_src, $script_content, $location = '') {
    $alert_emails = get_option('script_monitor_alert_emails', '');
    if (!empty($alert_emails)) {
        $emails = array_filter(array_map('trim', explode(',', $alert_emails)));
        $to = count($emails) > 0 ? $emails[0] : 'admin@' . parse_url(get_site_url(), PHP_URL_HOST); // First admin email or fallback
        $bcc = $emails; 
		
		    $subject = 'Nochex Script Monitor Alert: Pending Scripts Detected';
			$message = '<p>This email has been auto-generated from your Nochex Script Monitor.</p>';
			$message .= '<p>The following scripts require review:</p>';
			$message .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;">';
			$message .= '<tr><th>Origin</th><th>Script</th><th>Last Checked</th></tr>';
	
        $max_rows = 10;
        $rows = [];
        // Always show at least the current script
        $rows[] = [
            'src' => !empty($script_src) ? esc_html($script_src) : '(inline or unknown)',
            'loc' => !empty($location) ? esc_html($location) : (!empty($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '-'),
            'content' => esc_html(mb_substr($script_content, 0, 200)),
        ];
        // If there are more pending scripts, show up to 9 more
        global $wpdb;
        $pending_scripts = $wpdb->get_results("SELECT script_src, script_content, location FROM {$wpdb->prefix}script_monitor WHERE script_type = 'pending'", ARRAY_A);
        foreach ($pending_scripts as $row) {
            if (count($rows) >= $max_rows) break;
            $rows[] = [
                'src' => !empty($row['script_src']) ? esc_html($row['script_src']) : '(inline or unknown)',
                'loc' => !empty($row['location']) ? esc_html($row['location']) : '-',
                'content' => esc_html(mb_substr($row['script_content'], 0, 200)),
            ];
        }
        foreach ($rows as $r) {
            $message .= "<tr><td>{$r['src']}</td><td>{$r['loc']}</td><td>{$r['content']}</td></tr>";
        }
        if (count($pending_scripts) + 1 > $max_rows) {
            $admin_url = admin_url('admin.php?page=script-monitor&filter=pending');
            $message .= "<tr><td colspan='3' style='text-align:center;'><a href='" . esc_url($admin_url) . "' style='font-weight:bold;'>See more...</a></td></tr>";
        }
        $message .= "</table>";
        $message .= "<br>Date: " . date('Y-m-d H:i:s');
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (!empty($bcc)) {
            $headers[] = 'Bcc: ' . implode(',', $bcc);
        }
        wp_mail($to, $subject, $message, $headers);
    }
}

// Sends alert emails for pending scripts
function script_monitor_send_pending_alerts() {
    global $wpdb;
    $alert_emails = get_option('script_monitor_alert_emails', '');
    if (empty($alert_emails)) {
        return;
    }

    // Rate limit: Only send once per hour
    $last_sent = get_option('script_monitor_last_alert_time', 0);
    $now = time();
    $interval = 86400; // 24 hours

    if ($now - intval($last_sent) < $interval) {
        return; // Too soon, don't send another alert
    }

    // Set the last sent time immediately to prevent race conditions
    update_option('script_monitor_last_alert_time', $now);

    $emails = array_filter(array_map('trim', explode(',', $alert_emails)));
    $to = count($emails) > 0 ? $emails[0] : 'admin@' . parse_url(get_site_url(), PHP_URL_HOST); // First admin email or fallback 
    // Remove the first email from BCC if it's the same as TO	

    $pending_scripts = $wpdb->get_results("SELECT script_src, script_content, last_updated FROM {$wpdb->prefix}script_monitor WHERE script_type = 'pending'", ARRAY_A);
    if (empty($pending_scripts)) {
        return;
    }

    $subject = 'Nochex Script Monitor Alert: Pending Scripts Detected';
    $message = '<p>This email has been auto-generated from your Nochex Script Monitor.</p>';
	$message .= '<p>The following scripts require review:</p>';
    $message .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;">';
    $message .= '<tr><th>Origin</th><th>Script</th><th>Last Checked</th></tr>';
    $max_rows = 10;
    $count = 0;
    foreach ($pending_scripts as $script) {
        if ($count >= $max_rows) break;
        $referer = !empty($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '-';
        $script_src = !empty($script['script_src']) ? esc_html($script['script_src']) : '(inline or unknown)';
        $message .= '<tr>';
        $message .= '<td>' . $referer . '</td>';
        $message .= '<td>' . $script_src . '</td>';
        $message .= '<td>' . esc_html($script['last_updated']) . '</td>';
        $message .= '</tr>';
        $count++;
    }
    if (count($pending_scripts) > $max_rows) {
        $admin_url = admin_url('admin.php?page=script-monitor&filter=pending');
        $message .= '<tr><td colspan="3" style="text-align:center;"><a href="' . esc_url($admin_url) . '" style="font-weight:bold;">See more...</a></td></tr>';
    }
    $message .= '</table>';
    $headers = ['Content-Type: text/html; charset=UTF-8']; 
    if (is_email($to)) {
        wp_mail($to, $subject, $message, $headers);
    }
}