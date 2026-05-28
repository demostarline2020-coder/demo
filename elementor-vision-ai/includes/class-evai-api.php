<?php

namespace ElementorVisionAI;

if (!defined('ABSPATH')) {
    exit;
}

class API {
    public static function register(): void {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void {
        register_rest_route('evai/v1', '/generate', [
            'methods' => 'POST',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => [self::class, 'generate'],
        ]);

        register_rest_route('evai/v1', '/orchestrator-health', [
            'methods' => 'GET',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => [self::class, 'orchestrator_health'],
        ]);
    }

    public static function orchestrator_health(): \WP_REST_Response {
        $baseUrl = untrailingslashit(Settings::get('orchestrator_url', 'http://127.0.0.1:4100'));
        $response = wp_remote_get($baseUrl . '/health', ['timeout' => 5]);

        if (is_wp_error($response)) {
            return new \WP_REST_Response([
                'ok' => false,
                'error' => self::build_connectivity_error($response, $baseUrl),
            ], 503);
        }

        return new \WP_REST_Response(['ok' => true], 200);
    }

    public static function generate(\WP_REST_Request $request): \WP_REST_Response {
        $file = $request->get_file_params()['image'] ?? null;
        if (!$file || empty($file['tmp_name'])) {
            return new \WP_REST_Response(['error' => 'Image is required.'], 400);
        }

        $baseUrl = untrailingslashit(Settings::get('orchestrator_url', 'http://127.0.0.1:4100'));
        $preflight = wp_remote_get($baseUrl . '/health', ['timeout' => 5]);
        if (is_wp_error($preflight)) {
            return new \WP_REST_Response(['error' => self::build_connectivity_error($preflight, $baseUrl)], 503);
        }

        $imgData = base64_encode(file_get_contents($file['tmp_name']));
        $payload = [
            'imageBase64' => $imgData,
            'mimeType' => $file['type'] ?? 'image/png',
            'filename' => $file['name'] ?? 'upload.png',
            'geminiApiKey' => Settings::get('gemini_api_key'),
        ];

        $response = wp_remote_post($baseUrl . '/generate-template', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            return new \WP_REST_Response(['error' => self::build_connectivity_error($response, $baseUrl)], 503);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return new \WP_REST_Response($body, wp_remote_retrieve_response_code($response));
    }

    private static function build_connectivity_error(\WP_Error $error, string $baseUrl): string {
        $message = $error->get_error_message();
        return sprintf(
            'Cannot reach Elementor Vision AI Orchestrator at %1$s. Details: %2$s. Start Node service in plugin/services/orchestrator (`npm install && npm start`) and ensure WordPress can access that URL from the server network.',
            $baseUrl,
            $message
        );
    }
}
