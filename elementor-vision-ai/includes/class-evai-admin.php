<?php

namespace ElementorVisionAI;

if (!defined('ABSPATH')) {
    exit;
}

class Admin {
    public static function register(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function menu(): void {
        add_menu_page('Elementor Vision AI', 'Elementor Vision AI', 'manage_options', 'elementor-vision-ai', [self::class, 'render_app'], 'dashicons-format-image');
        add_submenu_page('elementor-vision-ai', 'Settings', 'Settings', 'manage_options', 'elementor-vision-ai-settings', [self::class, 'render_settings']);
    }

    public static function assets(string $hook): void {
        if (!str_contains($hook, 'elementor-vision-ai')) return;

        wp_enqueue_script('wp-element');
        wp_enqueue_script('evai-admin', EVAI_PLUGIN_URL . 'admin/assets/app.js', ['wp-element'], EVAI_VERSION, true);
        wp_enqueue_style('evai-admin', EVAI_PLUGIN_URL . 'admin/assets/app.css', [], EVAI_VERSION);

        wp_localize_script('evai-admin', 'evaiConfig', [
            'restUrl' => esc_url_raw(rest_url('evai/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public static function render_app(): void {
        echo '<div class="wrap"><h1>Elementor Vision AI</h1><div id="evai-root"></div></div>';
    }

    public static function render_settings(): void {
        ?>
        <div class="wrap">
            <h1>Elementor Vision AI Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('evai_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Gemini API Key</th>
                        <td><input type="password" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[gemini_api_key]" value="<?php echo esc_attr(Settings::get('gemini_api_key')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Orchestrator URL</th>
                        <td><input type="url" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[orchestrator_url]" value="<?php echo esc_attr(Settings::get('orchestrator_url', 'http://127.0.0.1:4100')); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }
}
