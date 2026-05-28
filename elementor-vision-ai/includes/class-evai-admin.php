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
            <p><strong>For non-technical users:</strong> ask your agency/technical team for a <strong>Managed Worker URL</strong>. You do not need Node.js on your website host.</p>

            <div style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin:12px 0 18px;max-width:980px;">
                <h2 style="margin-top:0;">What is Managed Worker URL?</h2>
                <p>It is the public HTTPS URL of the AI worker server that processes screenshots. Example: <code>https://worker.youragency.com</code>.</p>
                <p>The worker must support these endpoints:</p>
                <ul style="margin-top:0;">
                    <li><code>GET /health</code></li>
                    <li><code>POST /generate-template</code></li>
                </ul>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('evai_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Gemini API Key (only for Gemini backend)</th>
                        <td><input type="password" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[gemini_api_key]" value="<?php echo esc_attr(Settings::get('gemini_api_key')); ?>" class="regular-text" />
                        <p class="description">Required only when AI Backend = Gemini Vision.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Managed Worker URL</th>
                        <td><input type="url" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[orchestrator_url]" value="<?php echo esc_attr(Settings::get('orchestrator_url', 'https://your-managed-worker.example.com')); ?>" class="regular-text" />
                        <p class="description">Paste URL from agency/tech team. Example: https://worker.youragency.com</p></td>
                    </tr>
                    <tr>
                        <th scope="row">AI Backend</th>
                        <td>
                            <select name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[ai_backend]">
                                <option value="gemini" <?php selected(Settings::get('ai_backend', 'gemini'), 'gemini'); ?>>Gemini Vision (API key)</option>
                                <option value="ollama" <?php selected(Settings::get('ai_backend', 'gemini'), 'ollama'); ?>>Ollama LLaVA (free/local)</option>
                            </select>
                            <p class="description">Gemini = easiest cloud setup. Ollama = no per-request AI cost, hosted by your agency on worker infrastructure.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <div style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin:18px 0;max-width:980px;">
                <h2 style="margin-top:0;">How to get Gemini API Key (Google AI Studio)</h2>
                <ol>
                    <li>Open <a href="https://aistudio.google.com" target="_blank" rel="noopener noreferrer">https://aistudio.google.com</a>.</li>
                    <li>Sign in with your Google account.</li>
                    <li>Open <strong>API Keys</strong> / <strong>Get API key</strong>.</li>
                    <li>Create a new key, copy it, and paste into <strong>Gemini API Key</strong> above.</li>
                </ol>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin:18px 0;max-width:980px;">
                <h2 style="margin-top:0;">How to use Ollama (free/local)</h2>
                <ol>
                    <li>Your agency/technical team installs Ollama on the worker server.</li>
                    <li>They run <code>ollama pull llava:7b</code>.</li>
                    <li>They keep Ollama running and connect worker to it (default: <code>http://127.0.0.1:11434</code>).</li>
                    <li>In this settings page, select <strong>AI Backend = Ollama LLaVA</strong>.</li>
                    <li>Gemini key is not needed in Ollama mode.</li>
                </ol>
            </div>
        </div>
        <?php
    }
}
