<?php
/*
Plugin Name: Elementor Widget Usage Table
Description: Displays a table of Elementor (including Pro) widgets used on each page with counts, page title, and URL.
Version: 1.0
Author: HMShingala
Author URI: www.hmshingala.com
*/

if (!defined('ABSPATH')) exit;

// Admin menu for full table view
add_action('admin_menu', function () {
    add_menu_page(
        'Elementor Widget Usage',
        'Elementor Widget Usage',
        'manage_options',
        'elementor-widget-usage',
        'ewu_display_admin_page',
        'dashicons-table',
        70
    );
});

// Dashboard widget (summary)
add_action('wp_dashboard_setup', function () {
    wp_add_dashboard_widget(
        'ewu_dashboard_widget',
        'Elementor Widget Usage (Summary)',
        'ewu_display_dashboard_widget'
    );
});

// Extract widget names recursively
function ewu_extract_widgets_from_elementor($elements) {
    $widgets = [];
    foreach ($elements as $element) {
        if (isset($element['widgetType'])) {
            $widgets[] = $element['widgetType'];
        }
        if (!empty($element['elements']) && is_array($element['elements'])) {
            $widgets = array_merge($widgets, ewu_extract_widgets_from_elementor($element['elements']));
        }
    }
    return $widgets;
}

// Get widget usage [widget][post_id] = count
function ewu_get_widget_usage_data() {
    $args = [
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'     => '_elementor_data',
                'compare' => 'EXISTS'
            ]
        ]
    ];
    $query = new WP_Query($args);

    $widget_data = [];
    $meta_data   = [];

    foreach ($query->posts as $post) {
        $author_id = $post->post_author;
        $author_data = get_userdata($author_id);
        $author_name = $author_data ? $author_data->display_name : 'Unknown';

        $page_info = [
            'title' => get_the_title($post->ID),
            'url'   => get_permalink($post->ID),
            'date'  => get_the_date('Y-m-d', $post->ID),
            'author'=> $author_name,
        ];

        $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
        if (!$elementor_data) continue;
        $content = json_decode($elementor_data, true);
        if (!$content) continue;

        $widgets = ewu_extract_widgets_from_elementor($content);
        foreach ($widgets as $widget_name) {
            if (!isset($widget_data[$widget_name])) {
                $widget_data[$widget_name] = [];
            }
            if (!isset($widget_data[$widget_name][$post->ID])) {
                $widget_data[$widget_name][$post->ID] = 0;
                $meta_data[$post->ID] = $page_info;
            }
            $widget_data[$widget_name][$post->ID]++;
        }
    }
    return [$widget_data, $meta_data];
}

function ewu_display_admin_page() {
    list($widget_data, $meta_data) = ewu_get_widget_usage_data();

    // Get search query
    $query = '';
    if (isset($_GET['ewu_search'])) {
        $query = sanitize_text_field($_GET['ewu_search']);
    }

    echo '<div class="wrap"><h1>Elementor Widget Usage</h1>';
    echo ewu_render_search_form($query);
    echo ewu_render_table($widget_data, $meta_data, false, $query); // full columns on admin page
    echo '</div>';
}

function ewu_display_dashboard_widget() {
    list($widget_data, $meta_data) = ewu_get_widget_usage_data();
    echo ewu_render_dashboard_table($widget_data, $meta_data, 6); // dashboard: only 6 rows
}

function ewu_render_search_form($value = '') {
    $value = esc_attr($value);
    return <<<HTML
    <form method="get" style="margin-bottom:10px;">
        <input type="hidden" name="page" value="elementor-widget-usage">
        <input type="search" name="ewu_search" value="{$value}" placeholder="Search widget, page, or creator..." style="padding:3px;min-width:300px;">
        <input class="button button-secondary" type="submit" value="Search">
        &nbsp; <a href="admin.php?page=elementor-widget-usage" class="button">Reset</a>
    </form>
HTML;
}

// Full featured table for admin page
function ewu_render_table($widget_data, $meta_data, $compact = false, $search = '') {
    $count = 0;
    $max_rows = $compact ? 6 : -1;
    $search = is_string($search) ? trim(mb_strtolower($search)) : '';
    ob_start();
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" style="width: 210px;">Widget Name (Count)</th>
                <th scope="col">Page Title</th>
                <th scope="col">Page URL</th>
                <th scope="col" style="width:120px;">Widget Date Created</th>
                <th scope="col" style="width:130px;">Created By</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rows_output = false;
        foreach ($widget_data as $widget => $pages) {
            foreach ($pages as $page_id => $usage_count) {
                if (!isset($meta_data[$page_id])) continue;
                $title = $meta_data[$page_id]['title'];
                $url   = $meta_data[$page_id]['url'];
                $date  = $meta_data[$page_id]['date'];
                $author= $meta_data[$page_id]['author'];

                // Apply search (if any): match on widget, title, or author name
                if ($search) {
                    if (
                        strpos(mb_strtolower($widget), $search) === false &&
                        strpos(mb_strtolower($title), $search) === false &&
                        strpos(mb_strtolower($author), $search) === false
                    ) {
                        continue;
                    }
                }
                ?>
                <tr>
                    <td><?php echo esc_html($widget) . ' (' . esc_html($usage_count) . ')'; ?></td>
                    <td><?php echo esc_html($title); ?></td>
                    <td><a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html($title); ?></a></td>
                    <td><?php echo esc_html($date); ?></td>
                    <td><?php echo esc_html($author); ?></td>
                </tr>
                <?php
                $rows_output = true;
                $count++;
                if ($compact && $count >= $max_rows) break 2;
            }
        }
        if (!$rows_output) {
            ?>
            <tr>
                <td colspan="5"><em>No Elementor widgets found on published pages.</em></td>
            </tr>
            <tr>
                <td colspan="5" style="text-align:center; color:#888;">
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=elementor-widget-usage')); ?>">Check Full Report</a>
                </td>
            </tr>
            <tr>
                <td colspan="5" style="text-align:center; color:#888;">
                    <span>Pluign Developed by <strong><a href="https://www.hmshingala.com" target="_blank">HMSHINGALA</a></strong></span>
                </td>
            </tr>
            <?php
        } elseif ($compact && $count >= $max_rows) {
            ?>
            <tr>
                <td colspan="5" style="text-align:center; color:#888;">
                    ...and more. <a href="<?php echo esc_url(admin_url('admin.php?page=elementor-widget-usage')); ?>">Full Report</a>
                </td>
            </tr>
            <?php
        }?>
            <tr>
                <td colspan="5" style="text-align:center; color:#888;">
                    <span>Pluign Developed by <strong><a href="https://www.hmshingala.com" target="_blank">HMSHINGALA</a></strong></span>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

// Dashboard summary table: ONLY Widget Name (Count) + Page Title
function ewu_render_dashboard_table($widget_data, $meta_data, $max_rows = 6) {
    $count = 0;
    ob_start();
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" style="width: 220px;">Widget Name (Count)</th>
                <th scope="col">Page Title</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rows_output = false;
        foreach ($widget_data as $widget => $pages) {
            foreach ($pages as $page_id => $usage_count) {
                if (!isset($meta_data[$page_id])) continue;
                $title = $meta_data[$page_id]['title'];
                $url   = $meta_data[$page_id]['url'];
                ?>
                <tr>
                    <td><?php echo esc_html($widget) . ' (' . esc_html($usage_count) . ')'; ?></td>
                    <td><a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html($title); ?></a></td>
                </tr>
                <?php
                $rows_output = true;
                $count++;
                if ($max_rows > 0 && $count >= $max_rows) break 2;
            }
        }
        if (!$rows_output) {
            ?>
            <tr>
                <td colspan="2"><em>No Elementor widgets found on published pages.</em></td>
            </tr>
            <?php
        } elseif ($count >= $max_rows) {
            ?>
            <tr>
                <td colspan="2" style="text-align:center; color:#888;">
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=elementor-widget-usage')); ?>">Check Full Report</a>
                </td>
            </tr>
            <tr>
                <td colspan="2" style="text-align:center; color:#888;">
                    <span>Pluign Developed by <strong><a href="https://www.hmshingala.com" target="_blank">HMSHINGALA</a></strong></span>
                </td>
            </tr>
            <?php
        }
        ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}
