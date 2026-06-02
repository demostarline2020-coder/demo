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
        wp_enqueue_script('evai-settings', EVAI_PLUGIN_URL . 'admin/assets/settings.js', [], EVAI_VERSION, true);
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
        $backend = Settings::get('ai_backend', 'gemini');
        ?>
        <div class="wrap evai-settings-wrap">
            <h1>Elementor Vision AI Settings</h1>
            <p class="evai-intro">Quick setup takes about 2 minutes. Most users only need a Gemini API key.</p>

            <form method="post" action="options.php" id="evai-settings-form">
                <?php settings_fields('evai_settings_group'); ?>

                <div class="evai-card">
                    <h2>Choose Your AI Option</h2>
                    <div class="evai-field-group">
                        <label for="evai-ai-backend"><strong>AI Backend</strong></label>
                        <select id="evai-ai-backend" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[ai_backend]">
                            <option value="gemini" <?php selected($backend, 'gemini'); ?>>Gemini Vision (Recommended) — Powered by Gemini 2.5 Flash</option>
                            <option value="ollama" <?php selected($backend, 'ollama'); ?>>Ollama (Advanced / Local)</option>
                        </select>
                        <p class="description">Gemini is easiest and does not need an AI Server URL. Ollama is for advanced users with their own server.</p>
                    </div>
                </div>

                <div class="evai-card evai-conditional" data-backend="gemini" id="evai-gemini-group">
                    <h2>Recommended Easy Setup (Google Gemini)</h2>
                    <ol>
                        <li><a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">Open Google AI Studio</a></li>
                        <li>Sign in with your Google account.</li>
                        <li>Click "Create API Key".</li>
                        <li>Copy the key and paste it below.</li>
                        <li>Save settings.</li>
                    </ol>
                    <p><strong>No coding or server setup required.</strong></p>
                    <p class="evai-powered">Powered by Gemini 2.5 Flash.</p>
                    <div class="evai-field-group">
                        <label for="evai-gemini-key"><strong>Gemini API Key</strong></label>
                        <input id="evai-gemini-key" type="password" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[gemini_api_key]" value="<?php echo esc_attr(Settings::get('gemini_api_key')); ?>" class="regular-text" />
                    </div>
                </div>

                <div class="evai-card evai-conditional" data-backend="ollama" id="evai-ollama-group">
                    <details>
                        <summary><strong>Advanced Setup</strong></summary>
                        <h2>Advanced Local Setup (Free AI on Your Own Server)</h2>
                        <p><strong>This option is for advanced users or agencies.</strong></p>
                        <ol>
                            <li>Install Ollama: <a href="https://ollama.com/download" target="_blank" rel="noopener noreferrer">https://ollama.com/download</a></li>
                            <li>Run on server: <code>ollama pull llama3</code></li>
                            <li>Start your AI worker service.</li>
                            <li>Paste your AI Server URL below.</li>
                        </ol>
                        <p>The server must support template generation requests.</p>
                    </details>
                    <div class="evai-field-group">
                        <label for="evai-worker-url"><strong>AI Server URL</strong></label>
                        <input id="evai-worker-url" type="url" placeholder="https://ai.youragency.com" name="<?php echo esc_attr(Settings::OPTION_KEY); ?>[orchestrator_url]" value="<?php echo esc_attr(Settings::get('orchestrator_url', '')); ?>" class="regular-text" />
                        <p class="description">The web address of your AI server. Your developer or agency should provide this.</p>
                    </div>
                </div>

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }
}
