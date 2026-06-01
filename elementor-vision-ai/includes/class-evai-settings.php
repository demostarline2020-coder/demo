<?php

namespace ElementorVisionAI;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {
    public const OPTION_KEY = 'evai_settings';

    public static function register(): void {
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function register_settings(): void {
        register_setting('evai_settings_group', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => [
                'gemini_api_key' => '',
                'gemini_model' => 'gemini-2.5-flash',
                'orchestrator_url' => '',
                'ai_backend' => 'gemini',
            ],
        ]);
    }

    public static function sanitize(array $input): array {
        return [
            'gemini_api_key' => sanitize_text_field($input['gemini_api_key'] ?? ''),
            'gemini_model' => self::sanitize_gemini_model(is_scalar($input['gemini_model'] ?? null) ? strval($input['gemini_model']) : 'gemini-2.5-flash'),
            'orchestrator_url' => esc_url_raw($input['orchestrator_url'] ?? ''),
            'ai_backend' => in_array(($input['ai_backend'] ?? 'gemini'), ['gemini','ollama'], true) ? $input['ai_backend'] : 'gemini',
        ];
    }

    public static function allowed_gemini_models(): array {
        return [
            'gemini-2.5-flash' => 'gemini-2.5-flash (recommended)',
            'gemini-2.5-pro' => 'gemini-2.5-pro',
            'gemini-1.5-flash' => 'gemini-1.5-flash',
        ];
    }

    public static function sanitize_gemini_model(string $model): string {
        return array_key_exists($model, self::allowed_gemini_models()) ? $model : 'gemini-2.5-flash';
    }

    public static function get(string $key, string $default = ''): string {
        $settings = get_option(self::OPTION_KEY, []);
        return strval($settings[$key] ?? $default);
    }
}
