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
                'orchestrator_url' => 'http://127.0.0.1:4100',
            ],
        ]);
    }

    public static function sanitize(array $input): array {
        return [
            'gemini_api_key' => sanitize_text_field($input['gemini_api_key'] ?? ''),
            'orchestrator_url' => esc_url_raw($input['orchestrator_url'] ?? ''),
        ];
    }

    public static function get(string $key, string $default = ''): string {
        $settings = get_option(self::OPTION_KEY, []);
        return strval($settings[$key] ?? $default);
    }
}
