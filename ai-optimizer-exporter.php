<?php

/**
 * Plugin Name: AI Optimizer & Exporter
 * Description: Exports posts and pages in a token-efficient XML format for LLM parsing, and provides SEO suggestions.
 * Version: 3.0
 * Author: Forwwward
 */

require 'plugin-update-checker-master/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/forwwwardco/ai-optimizer-exporter',
    __FILE__,
    'ai-optimizer-exporter'
);

if (! defined('ABSPATH')) exit;

ob_start();

// 1. Create the Admin Menu
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

// 2. The Settings Page UI & Logic
function aie_settings_page()
{
    // Security check
    if (!current_user_can('manage_options')) return;

    // Grab current form state
    $action = isset($_POST['aie_action']) ? sanitize_text_field($_POST['aie_action']) : '';
    $post_type = isset($_POST['aie_export_type']) ? sanitize_text_field($_POST['aie_export_type']) : 'post';

?>
    <div class="wrap">
        <h1>AI Optimizer & Exporter</h1>
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
                    $xml .= "      <f tag='b'>Body Content (Text only)</f>\n";
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

                        $content = get_the_content();
                        $content = strip_shortcodes($content);
                        $content = wp_strip_all_tags($content);
                        $content = preg_replace('/\s+/', ' ', $content);
                        $content = trim($content);

                        $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
                        $yoast_desc  = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
                        $yoast_kw    = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);

                        $final_title = !empty($yoast_title) ? $yoast_title : get_the_title();
                        $final_desc  = !empty($yoast_desc) ? $yoast_desc : get_the_excerpt();
                        $final_desc  = wp_strip_all_tags($final_desc);

                        $xml .= "    <item>\n";
                        $xml .= "      <t>" . esc_xml(get_the_title()) . "</t>\n";
                        $xml .= "      <b>" . esc_xml($content) . "</b>\n";
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

                        // Check required Yoast fields
                        if (empty(get_post_meta($post_id, '_yoast_wpseo_title', true))) {
                            $missing[] = 'Meta Title';
                        }
                        if (empty(get_post_meta($post_id, '_yoast_wpseo_metadesc', true))) {
                            $missing[] = 'Meta Description';
                        }
                        if (empty(get_post_meta($post_id, '_yoast_wpseo_focuskw', true))) {
                            $missing[] = 'Focus Keyword';
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
                    echo '<h2 style="margin-top: 30px;">SEO Suggestions for Published ' . esc_html(ucfirst($post_type)) . 's</h2>';

                    if (empty($suggestions)) {
                        echo '<div class="notice notice-success"><p>🎉 Great job! All ' . $query->found_posts . ' published ' . esc_html($post_type) . 's have their Yoast Meta Title, Description, and Focus Keyword filled out.</p></div>';
                    } else {
                        echo '<p>Found <strong>' . count($suggestions) . '</strong> published ' . esc_html($post_type) . 's missing crucial SEO metadata.</p>';
                        echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 15px; border: 1px solid #ccd0d4;">';
                        echo '<thead><tr>';
                        echo '<th>Post/Page Title</th>';
                        echo '<th style="width: 30%;">Missing Fields</th>';
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
