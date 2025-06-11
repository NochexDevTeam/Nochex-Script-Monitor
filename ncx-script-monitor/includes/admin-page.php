<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

global $wpdb;
$table_name = $wpdb->prefix . 'ncx_script_monitor';

// Get unique domains from script_src
$domains = $wpdb->get_col("SELECT DISTINCT script_src FROM $table_name WHERE script_src IS NOT NULL AND script_src != ''");
$domain_options = [];
foreach ($domains as $src) {
    $host = parse_url($src, PHP_URL_HOST);
    if ($host && !in_array($host, $domain_options) && $host) {
        $domain_options[] = $host;
    }
}
sort($domain_options);

// Get main/internal domain
$main_domain = parse_url(get_site_url(), PHP_URL_HOST);

// Handle bulk actions (authorize/decline)
if (isset($_POST['bulk_action']) && isset($_POST['script_ids'])) {
    $action = sanitize_text_field($_POST['bulk_action']);
    $script_ids = array_map('intval', $_POST['script_ids']);
    $updated_hashes = [];
    $updated_count = 0;

    foreach ($script_ids as $id) {
        // Get both hash and size for precise updates
        $row = $wpdb->get_row($wpdb->prepare("SELECT script_hash, script_size FROM $table_name WHERE id = %d", $id), ARRAY_A);
        if ($row && !in_array($row['script_hash'], $updated_hashes, true)) {
            if ($action === 'authorize') {
                // Authorize all rows with this hash
                $result = $wpdb->update(
                    $table_name,
                    ['script_type' => 'authorized'],
                    ['script_hash' => $row['script_hash']],
                    ['%s'],
                    ['%s']
                );
            } elseif ($action === 'decline') {
                // Decline all rows with this hash
                $result = $wpdb->update(
                    $table_name,
                    ['script_type' => 'declined'],
                    ['script_hash' => $row['script_hash']],
                    ['%s'],
                    ['%s']
                );
            } else {
                $result = false;
            }
            if ($result !== false && $result > 0) {
                $updated_count += $result;
            }
            $updated_hashes[] = $row['script_hash'];
        }
    }

    echo '<div class="notice notice-success"><p>' . esc_html($updated_count) . ' scripts updated successfully.</p></div>';
}

// Pagination and filtering
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
$page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
$offset = ($page - 1) * $per_page;

$filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : '';
$domain_filter = isset($_GET['domain_filter']) ? sanitize_text_field($_GET['domain_filter']) : '';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$search_card_pattern = !empty($_GET['search_card_pattern']);
$where_clause = "WHERE 1=1";

if ($filter) {
    if ($filter === 'authorized') {
        $where_clause .= " AND script_type = 'authorized'";
    } elseif ($filter === 'declined') {
        $where_clause .= " AND script_type = 'declined'";
    } elseif ($filter === 'pending') {
        $where_clause .= " AND script_type = 'pending'";
    } // else, no filter, show all
}

if ($domain_filter) {
    if ($domain_filter === '__no_domain__') {
        $where_clause .= " AND (script_src IS NULL OR script_src = '')";
    } elseif ($domain_filter === $main_domain) {
        $where_clause .= $wpdb->prepare(" AND script_src LIKE %s", '%' . $main_domain . '%');
    } else {
        $where_clause .= $wpdb->prepare(" AND script_src LIKE %s", '%' . $domain_filter . '%');
    }
}

if ($search) {
    $where_clause .= $wpdb->prepare(" AND script_content LIKE %s", '%' . $search . '%');
}
if ($search_card_pattern) {
    $where_clause .= " AND script_content REGEXP '[0-9]{13,19}'";
}

// Exclude scripts from wp-admin, wp-includes, and the WordPress domain
$main_domain = preg_replace('/^www\./', '', parse_url(get_site_url(), PHP_URL_HOST));
$root_domain = implode('.', array_slice(explode('.', $main_domain), -2)); // e.g., mywebsite.com

/*$where_clause .= " AND (script_src NOT LIKE '%/wp-admin/%' 
    AND script_src NOT LIKE '%/wp-includes/%' 
    AND script_src NOT LIKE '%$root_domain%')";*/

$total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where_clause");
$total_pages = ceil($total_items / $per_page);

$scripts = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, script_type, script_src, script_content FROM $table_name $where_clause ORDER BY last_updated DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ),
    ARRAY_A
);

// Render the admin page
?>
<div class="wrap">
    <!-- Filter Form -->
    <form method="get" class="alignright">
	<div class="alignright">        
        <input type="hidden" name="page" value="ncx-script-monitor">
		<table class="alignright fsts">
		<tr><td></td><td><h3>Filter Scripts</h3></td></tr>
		<tr><td class="fAlignR">
        Status:</td><td><select name="filter">
             <option value="">All</option>
            <option value="pending" <?php selected($filter, 'pending'); ?>>Pending</option>
            <option value="authorized" <?php selected($filter, 'authorized'); ?>>Authorized</option>
            <option value="declined" <?php selected($filter, 'declined'); ?>>Declined</option>
        </select> </td></tr><tr><td class="fAlignR">
        Domains:</td><td><select name="domain_filter">
            <option value="">All</option>
            <option value="__no_domain__" <?php selected($domain_filter, '__no_domain__'); ?>>No Domain (Inline/Empty)</option>
            <option value="<?php echo esc_attr($main_domain); ?>" <?php selected($domain_filter, $main_domain); ?>>Internal (<?php echo esc_html($main_domain); ?>)</option>
            <?php foreach ($domain_options as $domain): ?>
                <?php if ($domain !== $main_domain): ?>
                    <option value="<?php echo esc_attr($domain); ?>" <?php selected($domain_filter, $domain); ?>>
                        <?php echo esc_html($domain); ?>
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select> </td></tr><tr><td class="fAlignR">
        Results per page:</td><td> <select name="per_page">
            <option value="10" <?php selected($per_page, 10); ?>>10</option>
            <option value="25" <?php selected($per_page, 25); ?>>25</option>
            <option value="50" <?php selected($per_page, 50); ?>>50</option>
            <option value="100" <?php selected($per_page, 100); ?>>100</option>
        </select></td></tr><tr><td></td><td>
        <button type="submit" class="button">Filter</button>
		</td></tr>
		</table>
	</div>	
    </form> 
    <h1>Nochex Script Monitor</h1>
    <p style="max-width:600px;">This plugin monitors all scripts loaded on your WordPress site including inline, internal, external, and dynamically created scripts. It helps you review, authorize, or decline scripts for improved security and visibility, and sends alerts when new or changed scripts are detected. This plugin is to assist you with monitoring scripts to help secure your website and maintain compliance with PCI DSS</p>
	<p style="max-width:600px;">If a script has a status of declined, the script will not load in your website!</span> But you can change the status of scripts at anytime without restriction</p>
    <!-- Tabs for Filtering -->
    <div class="nav-tab-wrapper">
        <a href="?page=ncx-script-monitor&filter=pending" class="nav-tab <?php echo $filter === 'pending' ? 'nav-tab-active' : ''; ?>">Pending</a>
        <a href="?page=ncx-script-monitor&filter=authorized" class="nav-tab <?php echo $filter === 'authorized' ? 'nav-tab-active' : ''; ?>">Authorized</a>
        <a href="?page=ncx-script-monitor&filter=declined" class="nav-tab <?php echo $filter === 'declined' ? 'nav-tab-active' : ''; ?>">Declined</a>
        <a href="?page=ncx-script-monitor" class="nav-tab <?php echo empty($filter) ? 'nav-tab-active' : ''; ?>">All</a>
    </div>

    <!-- Table for Displaying Scripts -->
    <form method="post">
        <div class="tablenav alignleft">
                <select name="bulk_action">
                    <option value="">Bulk Actions</option>
                    <option value="authorize">Authorize</option>
                    <option value="decline">Decline</option>
                </select>
                <button type="submit" class="button action">Apply</button>
        </div>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th class="smallth"><input type="checkbox" id="select-all"></th>
                    <th class="smallth">ID</th>
                    <th class="smallth">Status</th>
                    <th>Script Location</th>
                    <th>Inline Script Content</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scripts as $script): ?>
                    <tr>
                        <td><input type="checkbox" name="script_ids[]" value="<?php echo esc_attr($script['id']); ?>"></td>
                        <td><?php echo esc_html($script['id']); ?></td>
                        <td><?php echo esc_html($script['script_type']); ?></td>
                        <td><?php echo esc_html($script['script_src']); ?></td>
                        <td><?php echo esc_html(wp_trim_words($script['script_content'], 5, '...')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>

    <?php
    if ($total_pages > 1) {
        echo '<div class="tablenav-pages">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $active = $i == $page ? ' class="button button-primary"' : ' class="button"';
            echo '<a' . $active . ' href="?page=script-monitor&paged=' . $i . '&per_page=' . $per_page . '">' . $i . '</a> ';
        }
        echo '</div>';
    }
    ?>

</div>

<script>
    document.getElementById('select-all').addEventListener('click', function () {
        const checkboxes = document.querySelectorAll('input[name="script_ids[]"]');
        checkboxes.forEach(checkbox => checkbox.checked = this.checked);
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.view-more-button').forEach(function (button) {
            button.addEventListener('click', function () {
                const content = this.getAttribute('data-content');
                const previewDiv = this.previousElementSibling;

                if (previewDiv.classList.contains('expanded')) {
                    previewDiv.textContent = content.substring(0, 100) + '...'; // Collapse
                    previewDiv.classList.remove('expanded');
                    this.textContent = 'View More';
                } else {
                    previewDiv.textContent = content; // Expand
                    previewDiv.classList.add('expanded');
                    this.textContent = 'View Less';
                }
            });
        });
    });
</script>

<style>
table.fsts{
border:1px solid #c3c4c7;
background-color:#f6f7f7;
padding:5px;
color:2c3338;
}
.fAlignR{
text-align:right;
}
.smallth{
width:100px;
}
.notice{
max-width:25%;
}    .script-content-preview {
        max-height: 50px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .script-content-preview.expanded {
        max-height: none;
        white-space: normal;
    }

    .view-more-button {
        margin-top: 5px;
        display: block;
        cursor: pointer;
        background: #007cba;
        color: #fff;
        border: none;
        padding: 5px 10px;
        border-radius: 3px;
        font-size: 12px;
    }

    .view-more-button:hover {
        background: #005a9c;
    }
</style>