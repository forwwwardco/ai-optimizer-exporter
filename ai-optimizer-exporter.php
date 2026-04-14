<?php

/**
 * Plugin Name: AI Optimizer Exporter
 * Description: Exports posts and pages in a token-efficient XML format for LLM parsing, and provides SEO suggestions.
 * Version: 5.1.0
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
    // Main Menu (Defaults to Suggestions)
    add_menu_page(
        'AIOE',
        'AIOE',
        'manage_options',
        'aie-suggestions',
        'aie_suggestions_page',
        'dashicons-media-code'
    );

    // Submenu: Suggestions
    add_submenu_page(
        'aie-suggestions',
        'Suggestions',
        'Suggestions',
        'manage_options',
        'aie-suggestions',
        'aie_suggestions_page'
    );

    // Submenu: Export
    add_submenu_page(
        'aie-suggestions',
        'Export',
        'Export',
        'manage_options',
        'aie-export',
        'aie_export_page'
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
        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        );
        $query = new WP_Query($args);

        if (!$query->have_posts()) {
            echo '<div class="notice notice-warning"><p>No published content found for this post type.</p></div>';
        } else {
            echo '<div style="max-width: 1000px;">';
            echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 15px; border: 1px solid #ccd0d4;">';
            echo '<thead><tr>';
            echo '<th style="width: 40%;">Post/Page Title</th>';
            echo '<th style="width: 100px; text-align: center;">Status</th>';
            echo '<th>Missing Fields</th>';
            echo '<th style="width: 120px;">Action</th>';
            echo '</tr></thead>';
            echo '<tbody>';

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

            echo '</tbody></table>';
            echo '</div>';
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
    $skip_content = isset($_POST['aie_skip_content']);

?>
    <div class="wrap">
        <h1>AI Optimizer Exporter</h1>
        <p>Export your published content into a token-optimized XML format for AI tools.</p>
        <hr>

        <form method="post" action="" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 650px;">
            <?php wp_nonce_field('aie_export_nonce_action', 'aie_export_nonce'); ?>

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

            <button type="submit" name="aie_action" value="generate" class="button button-primary">Generate Export File</button>
        </form>

        <?php
        if ($action === 'generate' && isset($_POST['aie_export_nonce']) && wp_verify_nonce($_POST['aie_export_nonce'], 'aie_export_nonce_action')) {

            $args = array(
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            );
            $query = new WP_Query($args);

            if (!$query->have_posts()) {
                echo '<div class="notice notice-warning"><p>No published content found for this post type.</p></div>';
            } else {
                $site_name = get_bloginfo('name');

                $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                $xml .= "<root>\n";

                // AI Context Header
                $xml .= "  <ai_context>\n";
                $xml .= "    <description>Token-optimized export from $site_name for: $post_type.</description>\n";
                $xml .= "    <schema>\n";
                $xml .= "      <f tag='t'>Title</f>\n";

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
                    }

                    $xml .= "      <aid>" . esc_html($ai_desc) . "</aid>\n";
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
                    echo '<div class="notice notice-success" style="padding: 20px; max-width: 610px; border-left-color: #00a32a;">';
                    echo '<h3 style="margin-top: 0;">✅ Export Generated Successfully!</h3>';
                    echo '<p>Your XML file containing <strong>' . $query->found_posts . ' published ' . esc_html($post_type) . '(s)</strong> has been created and is ready for AI analysis.</p>';
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
