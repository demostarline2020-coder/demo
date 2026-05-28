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
        if (!str_contains($hook, 'elementor-vision-ai')) {
            return;
        }

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
            <p>Quick setup takes about 2 minutes. Follow the steps below and click <strong>Save Settings</strong>.</p>

            <div style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin:12px 0 18px;max-width:980px;">
                <h2 style="margin-top:0;">Choose Your AI Option</h2>
                <p><strong>Gemini</strong> is best for most users. It is the easiest option.</p>
                <p><strong>Ollama</strong> is for advanced users or agencies who already run their own server.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div style="border:1px solid #dcdcde;padding:10px;border-radius:6px;">
                        <h3 style="margin-top:0;">Gemini</h3>
                        <ul>
                            <li>Easiest setup</li>
                            <li>No server needed</li>
                            <li>Recommended for most users</li>
                        </ul>
                    </div>
                    <div style="border:1px solid #dcdcde;padding:10px;border-radius:6px;">
                        <h3 style="margin-top:0;">Ollama</h3>
                        <ul>
                            <li>Free per generation</li>
                            <li>Requires your own server</li>
                            <li>Best for agencies/advanced users</li>
                        </ul>
                    </div>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('evai_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">AI Backend</th>
                        <td>
                            <select name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[ai_backend]">
                                <option value="gemini" <?php selected(Settings::get('ai_backend', 'gemini'), 'gemini'); ?>>Gemini Vision (recommended)</option>
                                <option value="ollama" <?php selected(Settings::get('ai_backend', 'gemini'), 'ollama'); ?>>Ollama (advanced/local)</option>
                            </select>
                            <p class="description">Select Gemini for easiest onboarding. Select Ollama only if your team manages a server.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Gemini API Key</th>
                        <td><input type="password" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[gemini_api_key]" value="<?php echo esc_attr(Settings::get('gemini_api_key')); ?>" class="regular-text" />
                        <p class="description">Paste your Gemini key here if you selected Gemini above.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Worker URL</th>
                        <td><input type="url" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[orchestrator_url]" value="<?php echo esc_attr(Settings::get('orchestrator_url', 'https://your-managed-worker.example.com')); ?>" class="regular-text" />
                        <p class="description">Worker URL = the web address of your AI server. Example: <code>https://ai.youragency.com</code>. Your developer/agency should provide this URL.</p></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <div style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin:18px 0;max-width:980px;">
                <h2 style="margin-top:0;">Recommended Easy Setup (Google Gemini)</h2>
                <ol>
                    <li>Click here to open Google AI Studio:<br /><a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">https://aistudio.google.com/app/apikey</a></li>
                    <li>Sign in with Google account.</li>
                    <li>Click "Create API Key".</li>
                    <li>Copy the API key and paste it below.</li>
                    <li>Save settings.</li>
                </ol>
                <p><strong>No coding or server setup required.</strong></p>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin:18px 0;max-width:980px;">
                <h2 style="margin-top:0;">Advanced Local Setup (Free AI on Your Own Server)</h2>
                <p><strong>This option is for advanced users or agencies.</strong></p>
                <ol>
                    <li>Install Ollama on your VPS/server:<br /><a href="https://ollama.com/download" target="_blank" rel="noopener noreferrer">https://ollama.com/download</a></li>
                    <li>Run this command on server:<br /><code>ollama pull llama3</code></li>
                    <li>Start your AI worker service.</li>
                    <li>Paste your Worker URL below.</li>
                </ol>
                <p>The server must support template generation requests.</p>
            </div>
        </div>
        <?php
    }
}
