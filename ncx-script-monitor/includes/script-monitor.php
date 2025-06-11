<?php
/**
 * Nochex Script Monitor Class
 *
 * This class is responsible for monitoring scripts in WordPress.
 * It detects inline, internal, external, and dynamically created scripts,
 * ensuring that only unique and changed scripts are tracked.
 */
global $script_monitor_new_scripts;
$script_monitor_new_scripts = [];

class ScriptMonitor {
    private $tracked_scripts = [];
    private $table_name;
    private static $new_scripts_alert = [];

    // Constructor: Sets up the database table and AJAX hooks
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ncx_script_monitor';

        $this->create_table();

        // Only AJAX hooks here
        add_action('wp_ajax_log_dynamic_script', [$this, 'log_dynamic_script']);
        add_action('wp_ajax_nopriv_log_dynamic_script', [$this, 'log_dynamic_script']);
    }

    // Initializes ScriptMonitor hooks for Nochex Script Monitoring and blocking
    public static function init() {
        $instance = new self();
        add_action('wp_enqueue_scripts', [$instance, 'enqueue_dynamic_script_monitor']);
        add_action('wp_enqueue_scripts', [$instance, 'monitor_scripts'], 10);
        add_action('wp_footer', [$instance, 'monitor_inline_scripts'], 10);
        add_action('wp_enqueue_scripts', [$instance, 'block_declined_scripts'], 1); // Run early to block scripts
    }

    // Enqueues the JS monitor and passes authorized/declined scripts to JS
    public function enqueue_dynamic_script_monitor() {
        global $wpdb;

        $main_domain = preg_replace('/^www\./', '', parse_url(get_site_url(), PHP_URL_HOST));
        $root_domain = implode('.', array_slice(explode('.', $main_domain), -2)); // mywebsite.com

        // When fetching authorized scripts, match any script_src containing $root_domain
        $authorized_scripts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT script_src, script_content, script_hash, script_size FROM {$this->table_name} WHERE script_type = 'authorized' AND script_src LIKE %s",
                '%' . $root_domain . '%'
            ),
            ARRAY_A
        );

        $authorized_list = [];
        foreach ($authorized_scripts as $script) {
            if (!empty($script['script_src'])) {
                $authorized_list[] = [
                    'hash' => $script['script_hash'],
                    'size' => $script['script_size'],
                    'src'  => $script['script_src']
                ];
            }
            if (!empty($script['script_content'])) {
                $authorized_list[] = [
                    'hash' => base64_encode($script['script_content']),
                    'size' => strlen($script['script_content'])
                ];
            }
        }

        // Fetch declined external script sources and inline contents from the DB
        $declined_scripts = $wpdb->get_results(
            "SELECT script_src, script_content FROM {$this->table_name} WHERE script_type = 'declined'",
            ARRAY_A
        );

        $declined_srcs = [];
        $declined_inline = [];
        foreach ($declined_scripts as $script) {
            if (!empty($script['script_src'])) {
                $declined_srcs[] = $script['script_src'];
            }
            if (!empty($script['script_content'])) {
                $declined_inline[] = $script['script_content'];
            }
        }

        // For external scripts
        $declined_srcs = array_map(function($src) {
            $url = parse_url($src);
            $normalized = (isset($url['scheme']) ? $url['scheme'] . '://' : '') . $url['host'] . (isset($url['path']) ? $url['path'] : '');
            return $normalized;
        }, $declined_srcs);

        // For inline scripts
        $declined_inline = array_filter($declined_inline, function($content) {
            return $content !== null && $content !== '';
        });
        $declined_inline = array_map('base64_encode', $declined_inline);

        wp_enqueue_script(
            'js-md5',
            'https://cdnjs.cloudflare.com/ajax/libs/blueimp-md5/2.19.0/js/md5.min.js',
            [],
            '2.19.0',
            false
        );
        wp_enqueue_script(
            'dynamic-script-monitor',
            plugin_dir_url(__FILE__) . '../assets/dynamic-script-monitor.js',
            ['js-md5'],
            '1.0',
            true
        );

        wp_enqueue_script(
            'dynamic-script-monitors',
            'https://wp.purelydemos.com/test.js',
            [],
            '1.0',
            true
        );
        wp_localize_script('dynamic-script-monitor', 'dynamicScriptMonitor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('log_dynamic_scripts_batch'),
            'authorizedScripts' => $authorized_list,
            'declinedScripts' => array_merge($declined_srcs, $declined_inline),
            // ...
        ]);
    }

    // Creates the database table for Nochex Script Monitoring if it doesn't exist
    private function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            script_hash VARCHAR(255) NOT NULL,
            script_size INT(11),
            script_type VARCHAR(50) NOT NULL,
            script_src TEXT,
            script_version VARCHAR(50),
            script_content LONGTEXT,
            line_number INT(11),
            location TEXT,
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY script_hash (script_hash),
            INDEX script_type (script_type),
            INDEX last_updated (last_updated)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // Monitors enqueued scripts and logs new or changed ones to the database
    public function monitor_scripts() {
        global $wp_scripts;

        $site_url = get_site_url(); // Get the WordPress site's URL
        $site_host = parse_url($site_url, PHP_URL_HOST); // Extract the host (domain) from the site URL

        foreach ($wp_scripts->registered as $handle => $script) {
            $src = $script->src;

            // Skip if the script has no source
            if (empty($src)) {
                continue;
            }

            // Determine if the script is internal or external
            $script_host = parse_url($src, PHP_URL_HOST); // Extract the host from the script's URL
            $type = ($script_host === $site_host || empty($script_host)) ? 'internal' : 'external';

            if ($type === 'external') {
                // Always hash by URL for consistency
                $hash = md5($src);
                $size = script_monitor_get_remote_file_size($src);
            } else {
                $hash = md5($src);
                $size = strlen($src);
            }

            global $wpdb;
            $existing_script = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table_name} WHERE script_hash = %s AND script_size = %d",
                    $hash,
                    $size
                ),
                ARRAY_A
            );

            if (!$existing_script) {
                $wpdb->replace(
                    $this->table_name,
                    [
                        'script_hash' => $hash,
                        'script_size' => $size,
                        'script_type' => 'pending',
                        'script_src' => $src,
                        'script_content' => null,
                        'last_updated' => current_time('mysql'),
                    ]
                );
                // Send alert for all pending scripts
                if (function_exists('script_monitor_send_pending_alerts')) {
                    script_monitor_send_pending_alerts();
                }
            }
        }
    }

    // Removes declined inline scripts from the page output buffer
    public function monitor_inline_scripts() {
        ob_start(function ($buffer) {
            global $wpdb;

            // Get all declined inline scripts from the database
            $declined_scripts = $wpdb->get_results(
                "SELECT script_hash, script_content FROM {$this->table_name} WHERE script_type = 'declined'",
                ARRAY_A
            );

            if ($declined_scripts) {
                foreach ($declined_scripts as $declined_script) {
                    $hash = $declined_script['script_hash'];
                    $content = $declined_script['script_content'];

                    // Remove declined inline scripts from the buffer
                    $buffer = preg_replace_callback(
                        '/<script\b[^>]*>(.*?)<\/script>/is',
                        function ($matches) use ($hash, $content) {
                            $inline_hash = md5($matches[1]);
                            return ($inline_hash === $hash || $matches[1] === $content) ? '' : $matches[0];
                        },
                        $buffer
                    );
                }
            }

            return $buffer;
        });
    }

    // Records a script in the database and queues it for alert if new
    private function record_script($hash, $type, $src = null, $version = null, $content = null, $line_number = null, $location = null) {
        global $wpdb;

        $size = $type === 'external' ? script_monitor_get_remote_file_size($src) : strlen($content);

        $existing_script = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE script_hash = %s",
                $hash
            ),
            ARRAY_A
        );

        if ($existing_script) {
            // If declined, do nothing regardless of size
            if ($existing_script['script_type'] === 'declined') {
                return;
            }
            // If authorized but size is different, update previous authorized record to pending and log new as pending
            if ($existing_script['script_type'] === 'authorized' && intval($existing_script['script_size']) !== intval($size)) {
                // Update all authorized records for this hash to pending
                $wpdb->update(
                    $this->table_name,
                    [ 'script_type' => 'pending' ],
                    [ 'script_hash' => $hash, 'script_type' => 'authorized' ]
                );
                // Insert new pending record for this hash+size combo
                $wpdb->replace(
                    $this->table_name,
                    [
                        'script_hash' => $hash,
                        'script_size' => $size,
                        'script_type' => 'pending',
                        'script_src' => $src,
                        'script_content' => $content,
                        'last_updated' => current_time('mysql'),
                    ]
                );
                return;
            }
            // If already authorized and size matches, do nothing
            if ($existing_script['script_type'] === 'authorized' && intval($existing_script['script_size']) === intval($size)) {
                return;
            }
            // Existing logic for other types, if any
        }

        // If not found, insert or update the script
        $wpdb->replace(
            $this->table_name,
            [
                'script_hash' => $hash,
                'script_size' => $size,
                'script_type' => $type,
                'script_src' => $src,
                'script_content' => $content,
                'line_number' => $line_number,
                'location' => $location,
                'last_updated' => current_time('mysql'),
            ]
        );
        //send_script_monitor_alert($src, $content, $location);
    }

    // Returns all tracked scripts from the database
    public function get_tracked_scripts() {
        global $wpdb;

        $results = $wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
        return $results;
    }

    // Blocks declined scripts (external and inline) from loading on the page
    public function block_declined_scripts() {
        global $wpdb, $wp_scripts;

        // Get all declined scripts from the database
        $declined_scripts = $wpdb->get_results(
            "SELECT script_hash, script_src, script_content FROM {$this->table_name} WHERE script_type = 'declined'",
            ARRAY_A
        );

        if ($declined_scripts) {
            $declined_hashes = array_column($declined_scripts, 'script_hash');
            $declined_sources = array_column($declined_scripts, 'script_src');
            $declined_contents = array_column($declined_scripts, 'script_content');

            // For external scripts
            $declined_sources = array_map(function($src) {
                // Use the same normalization as JS (remove query, etc.)
                $url = parse_url($src);
                $normalized = $url['scheme'] . '://' . $url['host'] . (isset($url['path']) ? $url['path'] : '');
                return $normalized;
            }, $declined_sources);

            // For inline scripts
            $declined_contents = array_filter($declined_contents, function($content) {
                return $content !== null && $content !== '';
            });
            $declined_contents = array_map('base64_encode', $declined_contents);

            // Block declined external and internal scripts
            foreach ($wp_scripts->registered as $handle => $script) {
                $src = $script->src;
                $hash = md5($src);

                if (in_array($hash, $declined_hashes) || in_array($src, $declined_sources)) {
                    wp_deregister_script($handle);
                    wp_dequeue_script($handle);
                }
            }

            // Block declined inline scripts using output buffering
            ob_start(function ($buffer) use ($declined_hashes, $declined_contents) {
                return preg_replace_callback(
                    '/<script\b[^>]*>(.*?)<\/script>/is',
                    function ($matches) use ($declined_hashes, $declined_contents) {
                        $inline_hash = md5($matches[1]);
                        if (in_array($inline_hash, $declined_hashes) || in_array($matches[1], $declined_contents)) {
                            return ''; // Remove the declined inline script
                        }
                        return $matches[0];
                    },
                    $buffer
                );
            });
        }
    }

    // Handles AJAX logging of a single dynamic script
    public function log_dynamic_script() {
        global $wpdb;

        $script_src = isset($_POST['script_src']) ? sanitize_text_field($_POST['script_src']) : null;
        $script_content = isset($_POST['script_content']) ? sanitize_textarea_field($_POST['script_content']) : null;
        $hash = $script_src ? md5($script_src) : md5($script_content);
        $size = $script_src ? script_monitor_get_remote_file_size($script_src) : strlen($script_content);

        // Check if the script is already authorized
        $existing_script = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE script_hash = %s AND script_size = %d", $hash, $size),
            ARRAY_A
        );

        if ($existing_script) {
            // If declined, do nothing regardless of size
            if ($existing_script['script_type'] === 'declined') {
                wp_send_json_success(['message' => 'Script is declined and will not be logged.']);
                return;
            }
            // If authorized but size is different, update previous authorized record to pending and log new as pending
            if ($existing_script['script_type'] === 'authorized' && intval($existing_script['script_size']) !== intval($size)) {
                // Update all authorized records for this hash to pending
                $wpdb->update(
                    $this->table_name,
                    [ 'script_type' => 'pending' ],
                    [ 'script_hash' => $hash, 'script_type' => 'authorized' ]
                );
                // Insert new pending record for this hash+size combo
                $wpdb->replace(
                    $this->table_name,
                    [
                        'script_hash' => $hash,
                        'script_size' => $size,
                        'script_type' => 'pending',
                        'script_src' => $script_src,
                        'script_content' => $script_content,
                        'last_updated' => current_time('mysql'),
                    ]
                );
                wp_send_json_success(['message' => 'Script logged successfully as pending due to size mismatch.']);
                return;
            }
            // If already authorized and size matches, do nothing
            if ($existing_script['script_type'] === 'authorized' && intval($existing_script['script_size']) === intval($size)) {
                wp_send_json_success(['message' => 'Script is already authorized and will not be logged.']);
                return;
            }
        }

        // Insert or update the script in the database
        $wpdb->replace(
            $this->table_name,
            [
                'script_hash' => $hash,
                'script_size' => $size,
                'script_type' => 'pending',
                'script_src' => $script_src,
                'script_content' => $script_content,
                'last_updated' => current_time('mysql'),
            ]
        );
        if (function_exists('script_monitor_send_pending_alerts')) {
            script_monitor_send_pending_alerts();
        }

        wp_send_json_success(['message' => 'Script logged successfully.']);
    }
}

// Gets the remote file size for a script URL
function script_monitor_get_remote_file_size($url) {
    // Try HEAD first
    $headers = @get_headers($url, 1);
    if (isset($headers['Content-Length']) && is_numeric($headers['Content-Length'])) {
        return (int) $headers['Content-Length'];
    }
    // Fallback: GET and measure
    $content = @file_get_contents($url);
    return $content !== false ? strlen($content) : 0;
}

// Gets the remote file hash for a script URL
function script_monitor_get_remote_file_hash($url) {
    $content = @file_get_contents($url);
    return $content !== false ? md5($content) : '';
}

// Handles AJAX logging of a batch of dynamic scripts
add_action('wp_ajax_log_dynamic_scripts_batch', 'log_dynamic_scripts_batch_handler');
add_action('wp_ajax_nopriv_log_dynamic_scripts_batch', 'log_dynamic_scripts_batch_handler');
function log_dynamic_scripts_batch_handler() {
    wp_send_json_success();
}


ScriptMonitor::init();

?>