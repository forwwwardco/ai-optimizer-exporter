<?php

/**
 * Plugin Name: AI Optimizer Exporter
 * Description: Exports posts and pages in a token-efficient XML format for LLM parsing, and provides SEO suggestions.
 * Version: 5.0.0
 * Author: Forwwward
 * Author URI:  https://forwwward.co
 */
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

if (! defined('ABSPATH')) exit;

ob_start();

// ==========================================
// 1. Create the Admin Menu
// ==========================================
add_action('admin_menu', 'aie_create_menu');
function aie_create_menu()
{
    add_menu_page(
        'AIOE',
        'AIOE',
        'manage_options',
        'ai-content-export',
        'aie_settings_page',
        'dashicons-media-code'
    );
}

// ==========================================
// 2. Add AI Description Meta Box to Posts/Pages
// ==========================================
add_action('add_meta_boxes', 'aie_register_ai_description_meta_box');
function aie_register_ai_description_meta_box()
{
    $screens = ['post', 'page'];
    foreach ($screens as $screen) {
        add_meta_box(
            'aie_ai_description_box',                 // Unique ID
            'Describe this for AIs and LLMs',         // Box title
            'aie_ai_description_meta_box_html',       // Content callback
            $screen,                                  // Post type
            'normal',                                 // Context
            'high'                                    // Priority
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
    // Check nonce for security
    if (!isset($_POST['aie_ai_description_nonce']) || !wp_verify_nonce($_POST['aie_ai_description_nonce'], 'aie_ai_description_nonce_action')) {
        return;
    }
    // Ignore autosaves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    // Check user permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    // Save the data
    if (isset($_POST['aie_ai_description_field'])) {
        $data = sanitize_textarea_field($_POST['aie_ai_description_field']);
        update_post_meta($post_id, '_aie_ai_description', $data);
    }
}

// ==========================================
// 3. The Settings Page UI & Logic
// ==========================================
function aie_settings_page()
{
    // Security check
    if (!current_user_can('manage_options')) return;

    // Grab current form state
    $action = isset($_POST['aie_action']) ? sanitize_text_field($_POST['aie_action']) : '';
    $post_type = isset($_POST['aie_export_type']) ? sanitize_text_field($_POST['aie_export_type']) : 'post';
    $skip_content = isset($_POST['aie_skip_content']);

?>
    <div class="wrap">
        <h1>AI Optimizer Exporter</h1>
        <p>Export your published content into a token-optimized XML format or scan for missing SEO metadata.</p>
        <hr>

        <form method="post" action="" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 650px;">
            <?php wp_nonce_field('aie_action_nonce', 'aie_nonce'); ?>

            <h3 style="margin-top:0;">Select Content Type</h3>
            <fieldset style="margin-bottom: 25px;">
                <label style="margin-right: 20px; font-size: 14px;">
                    <input type="radio" name="aie_export_type" value="post" <?php checked($post_type, 'post'); ?>> Blog Articles (Posts)
                </label>
                <label style="font-size: 14px;">
                    <input type="radio" name="aie_export_type" value="page" <?php checked($post_type, 'page'); ?>> Website Pages
                </label>
            </fieldset>

            <h3 style="margin-top:0;">Export Options</h3>
            <fieldset style="margin-bottom: 25px;">
                <label style="font-size: 14px;">
                    <input type="checkbox" name="aie_skip_content" value="1" <?php checked($skip_content); ?>> Skip page/post content (Speeds up export and saves tokens by only exporting metadata and AI descriptions)
                </label>
            </fieldset>

            <h3 style="margin-top:0;">Choose Action</h3>
            <div style="display: flex; gap: 10px;">
                <button type="submit" name="aie_action" value="generate" class="button button-primary">Generate Export File</button>
                <button type="submit" name="aie_action" value="suggestions" class="button button-secondary">Get SEO Suggestions</button>
            </div>
        </form>

        <?php
        // Process Actions if the form was submitted
        if ($action && isset($_POST['aie_nonce']) && wp_verify_nonce($_POST['aie_nonce'], 'aie_action_nonce')) {

            // STRICTLY 'publish' status only
            $args = array(
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            );
            $query = new WP_Query($args);

            if (!$query->have_posts()) {
                echo '<div class="notice notice-warning"><p>No published content found for this post type.</p></div>';
            } else {

                // ==========================================
                // ACTION 1: GENERATE EXPORT
                // ==========================================
                if ($action === 'generate') {
                    $site_name = get_bloginfo('name');

                    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                    $xml .= "<root>\n";

                    // AI Context Header
                    $xml .= "  <ai_context>\n";
                    $xml .= "    <description>Token-optimized export from $site_name for: $post_type.</description>\n";
                    $xml .= "    <schema>\n";
                    $xml .= "      <f tag='t'>Title</f>\n";

                    // Conditionally add Body Content to Schema
                    if (!$skip_content) {
                        $xml .= "      <f tag='b'>Body Content (Text only)</f>\n";
                    }

                    $xml .= "      <f tag='aid'>AI Description</f>\n";
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

                        // Fetch Content only if we aren't skipping it
                        $content = '';
                        if (!$skip_content) {
                            $content = get_the_content();
                            $content = strip_shortcodes($content);
                            $content = wp_strip_all_tags($content);
                            $content = preg_replace('/\s+/', ' ', $content);
                            $content = trim($content);
                        }

                        // Yoast & Custom Metadata
                        $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
                        $yoast_desc  = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
                        $yoast_kw    = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
                        $ai_desc     = get_post_meta($post_id, '_aie_ai_description', true);

                        $final_title = !empty($yoast_title) ? $yoast_title : get_the_title();
                        $final_desc  = !empty($yoast_desc) ? $yoast_desc : get_the_excerpt();
                        $final_desc  = wp_strip_all_tags($final_desc);

                        $xml .= "    <item>\n";
                        $xml .= "      <t>" . esc_xml(get_the_title()) . "</t>\n";

                        if (!$skip_content) {
                            $xml .= "      <b>" . esc_xml($content) . "</b>\n";
                        }

                        $xml .= "      <aid>" . esc_xml($ai_desc) . "</aid>\n";
                        $xml .= "      <mt>" . esc_xml($final_title) . "</mt>\n";
                        $xml .= "      <md>" . esc_xml($final_desc) . "</md>\n";
                        $xml .= "      <kw>" . esc_xml($yoast_kw) . "</kw>\n";
                        $xml .= "      <cd>" . get_the_date('Y-m-d') . "</cd>\n";
                        $xml .= "      <ud>" . get_the_modified_date('Y-m-d') . "</ud>\n";
                        $xml .= "      <u>" . esc_url(get_permalink()) . "</u>\n";
                        $xml .= "    </item>\n";
                    }

                    $xml .= "  </export>\n";
                    $xml .= "</root>";

                    wp_reset_postdata();

                    // Write XML to the WordPress uploads directory
                    $upload_dir = wp_upload_dir();
                    $filename = 'ai-export-' . $post_type . 's-' . date('Y-m-d-His') . '.xml';
                    $file_path = wp_normalize_path($upload_dir['path'] . '/' . $filename);
                    $file_url = $upload_dir['url'] . '/' . $filename;

                    if (file_put_contents($file_path, $xml)) {
                        echo '<div class="notice notice-success" style="padding: 20px; max-width: 610px; border-left-color: #00a32a;">';
                        echo '<h3 style="margin-top: 0;">✅ Export Generated Successfully!</h3>';
                        echo '<p>Your XML file containing <strong>' . $query->found_posts . ' published ' . esc_html($post_type) . '(s)</strong> has been created and is ready for AI analysis.</p>';
                        echo '<a href="' . esc_url($file_url) . '" download="' . esc_attr($filename) . '" class="button button-primary button-hero">⬇️ Download XML File</a>';
                        echo '</div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Error writing file to uploads directory. Please check your server permissions.</p></div>';
                    }
                }

                // ==========================================
                // ACTION 2: SEO SUGGESTIONS
                // ==========================================
                elseif ($action === 'suggestions') {
                    $suggestions = [];

                    while ($query->have_posts()) {
                        $query->the_post();
                        $post_id = get_the_ID();
                        $missing = [];

                        // Check required Yoast & AI fields
                        if (empty(get_post_meta($post_id, '_yoast_wpseo_title', true))) {
                            $missing[] = 'Meta Title';
                        }
                        if (empty(get_post_meta($post_id, '_yoast_wpseo_metadesc', true))) {
                            $missing[] = 'Meta Description';
                        }
                        if (empty(get_post_meta($post_id, '_yoast_wpseo_focuskw', true))) {
                            $missing[] = 'Focus Keyword';
                        }
                        if (empty(get_post_meta($post_id, '_aie_ai_description', true))) {
                            $missing[] = 'AI Description';
                        }

                        // If anything is missing, push to suggestions array
                        if (!empty($missing)) {
                            $suggestions[] = [
                                'id'      => $post_id,
                                'title'   => get_the_title(),
                                'edit'    => get_edit_post_link($post_id),
                                'missing' => implode(', ', $missing)
                            ];
                        }
                    }
                    wp_reset_postdata();

                    echo '<div style="max-width: 900px;">';

                    if (empty($suggestions)) {
                        echo '<div class="notice notice-success"><p>🎉 Great job! All ' . $query->found_posts . ' published ' . esc_html($post_type) . 's have their Yoast Data and AI Description filled out.</p></div>';
                    } else {
                        echo '<h2 style="margin-top: 30px;">SEO & AI Suggestions for Published ' . esc_html(ucfirst($post_type)) . 's</h2>';
                        echo '<p>Found <strong>' . count($suggestions) . '</strong> published ' . esc_html($post_type) . 's missing crucial metadata.</p>';
                        echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 15px; border: 1px solid #ccd0d4;">';
                        echo '<thead><tr>';
                        echo '<th>Post/Page Title</th>';
                        echo '<th style="width: 40%;">Missing Fields</th>';
                        echo '<th style="width: 150px;">Action</th>';
                        echo '</tr></thead>';
                        echo '<tbody>';
                        foreach ($suggestions as $item) {
                            echo '<tr>';
                            echo '<td style="vertical-align: middle;"><strong>' . esc_html($item['title']) . '</strong></td>';
                            echo '<td style="vertical-align: middle;"><span style="color: #d63638; font-weight: 500;">' . esc_html($item['missing']) . '</span></td>';
                            echo '<td style="vertical-align: middle;"><a href="' . esc_url($item['edit']) . '" target="_blank" class="button button-small">Edit ' . esc_html(ucfirst($post_type)) . ' <span class="dashicons dashicons-external" style="font-size: 14px; line-height: 26px; margin-left: 2px;"></span></a></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }
                    echo '</div>';
                }
            }
        }
        ?>
    </div>
<?php
}
