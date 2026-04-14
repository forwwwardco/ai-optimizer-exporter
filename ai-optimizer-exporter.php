<?php

/**
 * Plugin Name: AI Optimizer Exporter
 * Description: Exports posts and pages in a token-efficient XML format for LLM parsing, and provides SEO suggestions.
 * Version: 5.2.0
 * Author: Forwwward
 * Author URI:  https://forwwward.co
 */

if (!defined('ABSPATH')) exit;

add_filter('auto_update_plugin', function ($update, $item) {
    if (isset($item->slug) && $item->slug === 'ai-optimizer-exporter-main') {
        return true;
    }
    return $update;
}, 10, 2);

require 'plugin-update-checker-master/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/forwwwardco/ai-optimizer-exporter',
    __FILE__,
    'ai-optimizer-exporter-main'
);

ob_start();

// ==========================================
// 1. Create the Admin Menu
// ==========================================
add_action('admin_menu', 'aie_create_menu');
function aie_create_menu()
{
    add_menu_page('AIOE', 'AIOE', 'manage_options', 'aie-suggestions', 'aie_suggestions_page', 'dashicons-media-code');
    add_submenu_page('aie-suggestions', 'Suggestions', 'Suggestions', 'manage_options', 'aie-suggestions', 'aie_suggestions_page');
    add_submenu_page('aie-suggestions', 'Export', 'Export', 'manage_options', 'aie-export', 'aie_export_page');
}

// ==========================================
// 2. Add AI Description Meta Box
// ==========================================
add_action('add_meta_boxes', 'aie_register_ai_description_meta_box');
function aie_register_ai_description_meta_box()
{
    $screens = ['post', 'page'];
    foreach ($screens as $screen) {
        add_meta_box(
            'aie_ai_description_box',
            'Describe this for AIs and LLMs',
            'aie_ai_description_meta_box_html',
            $screen,
            'normal',
            'high'
        );
    }
}

function aie_ai_description_meta_box_html($post)
{
    $value = get_post_meta($post->ID, '_aie_ai_description', true);
    wp_nonce_field('aie_ai_description_nonce_action', 'aie_ai_description_nonce');
?>
    <p>Provide a concise summary or specific context about this content, explicitly tailored for AI/LLM ingestion. This will be included in your XML export.</p>
    <textarea name="aie_ai_description_field" id="aie_ai_description_field" rows="4" style="width:100%;"><?php echo esc_textarea($value); ?></textarea>
<?php
}

add_action('save_post', 'aie_save_ai_description_meta_data');
function aie_save_ai_description_meta_data($post_id)
{
    if (!isset($_POST['aie_ai_description_nonce']) || !wp_verify_nonce($_POST['aie_ai_description_nonce'], 'aie_ai_description_nonce_action')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (isset($_POST['aie_ai_description_field'])) {
        $data = sanitize_textarea_field($_POST['aie_ai_description_field']);
        update_post_meta($post_id, '_aie_ai_description', $data);
    }
}

// ==========================================
// 3. Suggestions Page UI & Logic
// ==========================================
function aie_suggestions_page()
{
    if (!current_user_can('manage_options')) return;
    $post_type = isset($_POST['aie_suggestion_type']) ? sanitize_text_field($_POST['aie_suggestion_type']) : 'post';
?>
    <div class="wrap">
        <h1>SEO & AI Suggestions</h1>
        <p>Review missing Yoast metadata and AI Descriptions across your published content.</p>
        <hr>

        <form method="post" action="" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 650px;">
            <?php wp_nonce_field('aie_suggestions_nonce_action', 'aie_suggestions_nonce'); ?>
            <h3 style="margin-top:0;">Select Content Type</h3>
            <fieldset style="margin-bottom: 20px;">
                <label style="margin-right: 20px; font-size: 14px;">
                    <input type="radio" name="aie_suggestion_type" value="post" <?php checked($post_type, 'post'); ?>> Blog Articles (Posts)
                </label>
                <label style="font-size: 14px;">
                    <input type="radio" name="aie_suggestion_type" value="page" <?php checked($post_type, 'page'); ?>> Website Pages
                </label>
            </fieldset>
            <button type="submit" class="button button-primary">View Suggestions</button>
        </form>

        <?php
        $args = array('post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => -1);
        $query = new WP_Query($args);

        if (!$query->have_posts()) {
            echo '<div class="notice notice-warning"><p>No published content found for this post type.</p></div>';
        } else {
            echo '<div style="max-width: 1000px;">';
            echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 15px; border: 1px solid #ccd0d4;">';
            echo '<thead><tr><th style="width: 40%;">Post/Page Title</th><th style="width: 100px; text-align: center;">Status</th><th>Missing Fields</th><th style="width: 120px;">Action</th></tr></thead><tbody>';

            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $missing = [];

                if (empty(get_post_meta($post_id, '_yoast_wpseo_title', true))) $missing[] = 'Meta Title';
                if (empty(get_post_meta($post_id, '_yoast_wpseo_metadesc', true))) $missing[] = 'Meta Description';
                if (empty(get_post_meta($post_id, '_yoast_wpseo_focuskw', true))) $missing[] = 'Focus Keyword';
                if (empty(get_post_meta($post_id, '_aie_ai_description', true))) $missing[] = 'AI Description';

                $is_complete = empty($missing);

                echo '<tr>';
                echo '<td style="vertical-align: middle;"><strong>' . esc_html(get_the_title()) . '</strong></td>';
                if ($is_complete) {
                    echo '<td style="vertical-align: middle; text-align: center;"><span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 24px;"></span></td>';
                    echo '<td style="vertical-align: middle; color: #646970;">All good!</td>';
                } else {
                    echo '<td style="vertical-align: middle; text-align: center;"><span class="dashicons dashicons-warning" style="color: #d63638; font-size: 24px;"></span></td>';
                    echo '<td style="vertical-align: middle;"><span style="color: #d63638; font-weight: 500;">' . esc_html(implode(', ', $missing)) . '</span></td>';
                }
                echo '<td style="vertical-align: middle;"><a href="' . esc_url(get_edit_post_link($post_id)) . '" target="_blank" class="button button-small">Edit <span class="dashicons dashicons-external" style="font-size: 14px; line-height: 26px; margin-left: 2px;"></span></a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            wp_reset_postdata();
        }
        ?>
    </div>
<?php
}

// ==========================================
// 4. Export Page UI & Logic
// ==========================================
function aie_export_page()
{
    if (!current_user_can('manage_options')) return;

    $action = isset($_POST['aie_action']) ? sanitize_text_field($_POST['aie_action']) : '';
    $post_type = isset($_POST['aie_export_type']) ? sanitize_text_field($_POST['aie_export_type']) : 'post';
    $skip_content = isset($_POST['aie_export_nonce']) ? isset($_POST['aie_skip_content']) : true;

?>
    <div class="wrap">
        <h1>AI Optimizer Exporter</h1>
        <p>Export your published content into a token-optimized XML format for AI tools.</p>
        <hr>

        <form method="post" action="" id="aie_export_form" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px;">
            <?php wp_nonce_field('aie_export_nonce_action', 'aie_export_nonce'); ?>

            <h3 style="margin-top:0;">Select Content Type</h3>
            <fieldset style="margin-bottom: 20px;">
                <label style="margin-right: 20px; font-size: 14px;">
                    <input type="radio" name="aie_export_type" id="aie_type_post" value="post" <?php checked($post_type, 'post'); ?>> Blog Articles (Posts)
                </label>
                <label style="font-size: 14px;">
                    <input type="radio" name="aie_export_type" id="aie_type_page" value="page" <?php checked($post_type, 'page'); ?>> Website Pages
                </label>
            </fieldset>

            <h3 style="margin-top:0;">Filter Posts/Pages</h3>
            <fieldset style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
                <label style="display: block; margin-bottom: 15px; font-size: 14px;">
                    <strong>Number of posts to export:</strong>
                    <input type="number" name="aie_post_count" id="aie_post_count" value="0" min="0" style="width: 80px; margin-left: 10px;">
                    <span style="color: #666; margin-left: 10px;"><em>(Leave at 0 to export all. Higher numbers fetch the latest posts first.)</em></span>
                </label>

                <label style="display: block; font-size: 14px; font-weight: bold;">
                    <input type="checkbox" name="aie_select_posts_toggle" id="aie_select_posts_toggle" value="1">
                    Select specific posts/pages manually
                </label>

                <div id="aie-posts-wrapper" style="display: none; margin-top: 15px;">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column" style="width: 40px;"><input id="cb-select-all" type="checkbox"></td>
                                <th>Title</th>
                                <th style="width: 150px;">Date Published</th>
                            </tr>
                        </thead>
                        <tbody id="aie-posts-tbody">
                            <?php
                            // Pre-load all posts and pages as hidden rows for JavaScript to handle seamlessly.
                            $all_items = get_posts([
                                'post_type'   => ['post', 'page'],
                                'post_status' => 'publish',
                                'numberposts' => -1,
                                'orderby'     => 'date',
                                'order'       => 'DESC'
                            ]);

                            foreach ($all_items as $item) {
                                echo '<tr class="aie-selectable-row" data-type="' . esc_attr($item->post_type) . '" style="display:none;">';
                                echo '<th scope="row" class="check-column"><input type="checkbox" class="aie-row-cb" name="aie_selected_posts[]" value="' . esc_attr($item->ID) . '"></th>';
                                echo '<td>' . esc_html($item->post_title) . '</td>';
                                echo '<td>' . esc_html(get_the_date('Y-m-d', $item->ID)) . '</td>';
                                echo '</tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                    <div id="aie-pagination-controls" style="margin-top: 15px; display: flex; gap: 5px; flex-wrap: wrap;"></div>
                </div>
            </fieldset>

            <h3 style="margin-top:0;">Export Options</h3>
            <fieldset style="margin-bottom: 25px;">
                <label style="font-size: 14px;">
                    <input type="checkbox" name="aie_skip_content" value="1" <?php checked($skip_content); ?>> Skip page/post content (Speeds up export and saves tokens by substituting content with the AI description)
                </label>
            </fieldset>

            <button type="submit" name="aie_action" value="generate" class="button button-primary button-large">Generate Export File</button>
        </form>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const countInput = document.getElementById('aie_post_count');
                const selectToggle = document.getElementById('aie_select_posts_toggle');
                const tableWrapper = document.getElementById('aie-posts-wrapper');
                const radios = document.querySelectorAll('input[name="aie_export_type"]');
                const rows = document.querySelectorAll('.aie-selectable-row');
                const paginationControls = document.getElementById('aie-pagination-controls');
                const selectAllCb = document.getElementById('cb-select-all');

                let currentPage = 1;
                const itemsPerPage = 50;
                let filteredRows = [];

                // Logic to handle Input / Checkbox Toggling
                function handleInputs() {
                    if (parseInt(countInput.value) > 0) {
                        selectToggle.checked = false;
                    }
                    if (selectToggle.checked) {
                        countInput.value = 0;
                        tableWrapper.style.display = 'block';
                        updateTable();
                    } else {
                        tableWrapper.style.display = 'none';
                        // Uncheck all so they don't submit accidentally
                        rows.forEach(row => row.querySelector('.aie-row-cb').checked = false);
                        selectAllCb.checked = false;
                    }
                }

                // Filtering rows by selected post type
                function updateTable() {
                    if (!selectToggle.checked) return;

                    const selectedType = document.querySelector('input[name="aie_export_type"]:checked').value;
                    filteredRows = Array.from(rows).filter(row => row.getAttribute('data-type') === selectedType);

                    rows.forEach(row => row.style.display = 'none');
                    currentPage = 1;
                    selectAllCb.checked = false;

                    renderPage();
                    renderPaginationControls();
                }

                // Simple JS Pagination
                function renderPage() {
                    const start = (currentPage - 1) * itemsPerPage;
                    const end = start + itemsPerPage;

                    rows.forEach(row => row.style.display = 'none');

                    filteredRows.slice(start, end).forEach(row => {
                        row.style.display = 'table-row';
                    });
                }

                function renderPaginationControls() {
                    paginationControls.innerHTML = '';
                    const totalPages = Math.ceil(filteredRows.length / itemsPerPage);

                    if (totalPages <= 1) return;

                    for (let i = 1; i <= totalPages; i++) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = i === currentPage ? 'button button-primary' : 'button button-secondary';
                        btn.textContent = i;
                        btn.onclick = function(e) {
                            e.preventDefault();
                            currentPage = i;
                            selectAllCb.checked = false; // Reset select all on page change
                            renderPage();
                            renderPaginationControls();
                        };
                        paginationControls.appendChild(btn);
                    }
                }

                // Event Listeners
                countInput.addEventListener('input', function() {
                    if (parseInt(this.value) > 0) {
                        selectToggle.checked = false;
                        handleInputs();
                    }
                });
                selectToggle.addEventListener('change', handleInputs);
                radios.forEach(radio => radio.addEventListener('change', updateTable));

                // Select All logic (applies to visible rows only)
                selectAllCb.addEventListener('change', function() {
                    const checked = this.checked;
                    const start = (currentPage - 1) * itemsPerPage;
                    const end = start + itemsPerPage;

                    filteredRows.slice(start, end).forEach(row => {
                        row.querySelector('.aie-row-cb').checked = checked;
                    });
                });

                // Init
                handleInputs();
            });
        </script>

        <?php
        if ($action === 'generate' && isset($_POST['aie_export_nonce']) && wp_verify_nonce($_POST['aie_export_nonce'], 'aie_export_nonce_action')) {

            $args = array(
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC'
            );

            // Determine Query constraints
            $post_count = isset($_POST['aie_post_count']) ? intval($_POST['aie_post_count']) : 0;
            $select_posts_enabled = isset($_POST['aie_select_posts_toggle']);

            if ($select_posts_enabled) {
                if (!empty($_POST['aie_selected_posts'])) {
                    $selected_ids = array_map('intval', $_POST['aie_selected_posts']);
                    $args['post__in'] = $selected_ids;
                    $args['posts_per_page'] = -1; // Get all specific IDs
                } else {
                    echo '<div class="notice notice-error"><p>You enabled "Select specific posts" but did not check any items in the table. Export aborted.</p></div>';
                    return;
                }
            } else {
                $args['posts_per_page'] = ($post_count > 0) ? $post_count : -1;
            }

            $query = new WP_Query($args);

            if (!$query->have_posts()) {
                echo '<div class="notice notice-warning"><p>No published content found matching your criteria.</p></div>';
            } else {
                $site_name = get_bloginfo('name');

                $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                $xml .= "<root>\n";
                $xml .= "  <ai_context>\n";
                $xml .= "    <description>Token-optimized export from $site_name for: $post_type.</description>\n";
                $xml .= "    <schema>\n";
                $xml .= "      <f tag='t'>Title</f>\n";

                if (!$skip_content) {
                    $xml .= "      <f tag='b'>Body Content (Text only)</f>\n";
                } else {
                    $xml .= "      <f tag='aid'>AI Description</f>\n";
                }

                $xml .= "      <f tag='mt'>Meta Title (Yoast or Post Title fallback)</f>\n";
                $xml .= "      <f tag='md'>Meta Description (Yoast or Excerpt fallback)</f>\n";
                $xml .= "      <f tag='kw'>Yoast Focus Keyword</f>\n";
                $xml .= "      <f tag='cd'>Creation Date (YYYY-MM-DD)</f>\n";
                $xml .= "      <f tag='ud'>Last Updated Date (YYYY-MM-DD)</f>\n";
                $xml .= "      <f tag='u'>URL</f>\n";
                $xml .= "    </schema>\n";
                $xml .= "  </ai_context>\n";
                $xml .= '  <export type="' . esc_attr($post_type) . '">' . "\n";

                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();

                    $content = '';
                    if (!$skip_content) {
                        $content = get_the_content();
                        $content = strip_shortcodes($content);
                        $content = wp_strip_all_tags($content);
                        $content = preg_replace('/\s+/', ' ', $content);
                        $content = trim($content);
                    }

                    $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
                    $yoast_desc  = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
                    $yoast_kw    = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
                    $ai_desc     = get_post_meta($post_id, '_aie_ai_description', true);

                    $final_title = !empty($yoast_title) ? $yoast_title : get_the_title();
                    $final_desc  = !empty($yoast_desc) ? $yoast_desc : get_the_excerpt();
                    $final_desc  = wp_strip_all_tags($final_desc);

                    $xml .= "    <item>\n";
                    $xml .= "      <t>" . esc_html(get_the_title()) . "</t>\n";

                    if (!$skip_content) {
                        $xml .= "      <b>" . esc_html($content) . "</b>\n";
                    } else {
                        $xml .= "      <aid>" . esc_html($ai_desc) . "</aid>\n";
                    }

                    $xml .= "      <mt>" . esc_html($final_title) . "</mt>\n";
                    $xml .= "      <md>" . esc_html($final_desc) . "</md>\n";
                    $xml .= "      <kw>" . esc_html($yoast_kw) . "</kw>\n";
                    $xml .= "      <cd>" . get_the_date('Y-m-d') . "</cd>\n";
                    $xml .= "      <ud>" . get_the_modified_date('Y-m-d') . "</ud>\n";
                    $xml .= "      <u>" . esc_url(get_permalink()) . "</u>\n";
                    $xml .= "    </item>\n";
                }

                $xml .= "  </export>\n";
                $xml .= "</root>";

                wp_reset_postdata();

                $upload_dir = wp_upload_dir();
                $filename = 'ai-export-' . $post_type . 's-' . date('Y-m-d-His') . '.xml';
                $file_path = wp_normalize_path($upload_dir['path'] . '/' . $filename);
                $file_url = $upload_dir['url'] . '/' . $filename;

                if (file_put_contents($file_path, $xml)) {
                    // Calculate formatting
                    $file_size_bytes = filesize($file_path);
                    $units = array('B', 'KB', 'MB', 'GB');
                    $pow = floor(($file_size_bytes ? log($file_size_bytes) : 0) / log(1024));
                    $pow = min($pow, count($units) - 1);
                    $file_size_formatted = round($file_size_bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];

                    $char_count = number_format(mb_strlen($xml, 'UTF-8'));

                    echo '<div class="notice notice-success" style="padding: 20px; max-width: 610px; border-left-color: #00a32a;">';
                    echo '<h3 style="margin-top: 0;">✅ Export Generated Successfully!</h3>';
                    echo '<p>Your XML file containing <strong>' . $query->found_posts . ' ' . esc_html($post_type) . '(s)</strong> has been created.</p>';
                    echo '<div style="background: #f0f0f1; padding: 10px 15px; margin-bottom: 15px; border-radius: 4px;">';
                    echo '<span class="dashicons dashicons-analytics" style="margin-top: 2px;"></span> <strong>Stats:</strong> ' . $char_count . ' characters | ' . $file_size_formatted;
                    echo '</div>';
                    echo '<a href="' . esc_url($file_url) . '" download="' . esc_attr($filename) . '" class="button button-primary button-hero">⬇️ Download XML File</a>';
                    echo '</div>';
                } else {
                    echo '<div class="notice notice-error"><p>Error writing file to uploads directory. Please check your server permissions.</p></div>';
                }
            }
        }
        ?>
    </div>
<?php
}
