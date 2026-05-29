<?php

namespace ElementorVisionAI;

if (!defined('ABSPATH')) {
    exit;
}

class API {
    private const GEMINI_MODEL = 'gemini-2.0-flash';

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
        if (Settings::get('ai_backend', 'gemini') === 'gemini') {
            if (Settings::get('gemini_api_key') === '') {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => 'Add your Gemini API key in Elementor Vision AI → Settings before generating a template.',
                ], 400);
            }

            return new \WP_REST_Response([
                'ok' => true,
                'message' => 'Gemini is ready. No AI Server URL is required.',
                'mode' => 'gemini',
            ], 200);
        }

        $baseUrl = self::get_base_url();
        if (is_wp_error($baseUrl)) {
            return new \WP_REST_Response([
                'ok' => false,
                'error' => $baseUrl->get_error_message(),
            ], 400);
        }

        $response = wp_remote_get($baseUrl . '/health', ['timeout' => 5]);

        if (is_wp_error($response)) {
            return new \WP_REST_Response([
                'ok' => false,
                'error' => self::build_connectivity_error($response, $baseUrl),
            ], 503);
        }

        return new \WP_REST_Response(['ok' => true, 'mode' => 'ollama'], 200);
    }

    public static function generate(\WP_REST_Request $request): \WP_REST_Response {
        $file = $request->get_file_params()['image'] ?? null;
        if (!$file || empty($file['tmp_name'])) {
            return new \WP_REST_Response(['error' => 'Image is required.'], 400);
        }

        $imageBase64 = base64_encode(file_get_contents($file['tmp_name']));
        $mimeType = $file['type'] ?? 'image/png';
        $backend = Settings::get('ai_backend', 'gemini');

        if ($backend === 'gemini') {
            return self::generate_with_gemini($imageBase64, $mimeType);
        }

        return self::generate_with_worker($imageBase64, $mimeType, $file['name'] ?? 'upload.png');
    }

    private static function generate_with_gemini(string $imageBase64, string $mimeType): \WP_REST_Response {
        $apiKey = Settings::get('gemini_api_key');
        if ($apiKey === '') {
            return new \WP_REST_Response(['error' => 'Gemini API key is required. Add it in Elementor Vision AI → Settings.'], 400);
        }

        $template = self::call_gemini_for_template($apiKey, $imageBase64, $mimeType);
        if (is_wp_error($template)) {
            return new \WP_REST_Response(['error' => $template->get_error_message()], 502);
        }

        $validated = self::validate_elementor_template($template);
        if (is_wp_error($validated)) {
            return new \WP_REST_Response(['error' => $validated->get_error_message()], 422);
        }

        return new \WP_REST_Response([
            'similarityScore' => null,
            'elementorJson' => $validated,
            'previewImage' => $imageBase64,
            'mode' => 'gemini-direct',
            'message' => 'Template generated directly with Gemini. No AI Server URL was used.',
        ], 200);
    }

    private static function generate_with_worker(string $imageBase64, string $mimeType, string $filename): \WP_REST_Response {
        $baseUrl = self::get_base_url();
        if (is_wp_error($baseUrl)) {
            return new \WP_REST_Response(['error' => $baseUrl->get_error_message()], 400);
        }

        $preflight = wp_remote_get($baseUrl . '/health', ['timeout' => 5]);
        if (is_wp_error($preflight)) {
            return new \WP_REST_Response(['error' => self::build_connectivity_error($preflight, $baseUrl)], 503);
        }

        $payload = [
            'imageBase64' => $imageBase64,
            'mimeType' => $mimeType,
            'filename' => $filename,
            'aiBackend' => 'ollama',
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
        if (!is_array($body)) {
            return new \WP_REST_Response(['error' => 'The AI server returned an invalid response.'], 502);
        }

        return new \WP_REST_Response($body, wp_remote_retrieve_response_code($response));
    }

    private static function call_gemini_for_template(string $apiKey, string $imageBase64, string $mimeType) {
        $prompt = 'Analyze this website screenshot and return ONLY valid JSON for an importable Elementor page template. Use Elementor flexbox containers, not old sections/columns. Root object must have: version, title, type, content. content must be an array of Elementor container/widget elements. Use editable widgets: heading, text-editor, button, image, icon-box, spacer. Include responsive settings where helpful. Do not wrap response in markdown.';
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inlineData' => [
                        'mimeType' => $mimeType,
                        'data' => $imageBase64,
                    ]],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.2,
            ],
        ];

        $response = wp_remote_post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . self::GEMINI_MODEL . ':generateContent?key=' . rawurlencode($apiKey),
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($payload),
                'timeout' => 90,
            ]
        );

        if (is_wp_error($response)) {
            return new \WP_Error('evai_gemini_connection_failed', 'Could not contact Google Gemini. Details: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = $body['error']['message'] ?? 'Google Gemini returned an error.';
            return new \WP_Error('evai_gemini_error', $message);
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $decoded = self::decode_json_text($text);
        if (!is_array($decoded)) {
            return new \WP_Error('evai_gemini_invalid_json', 'Gemini did not return valid Elementor JSON. Please try again with a clearer screenshot.');
        }

        return $decoded;
    }

    private static function decode_json_text(string $text): ?array {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function validate_elementor_template(array $template) {
        $template['version'] = isset($template['version']) ? strval($template['version']) : '0.4';
        $template['title'] = sanitize_text_field($template['title'] ?? 'Elementor Vision AI Template');
        $template['type'] = sanitize_text_field($template['type'] ?? 'page');

        if (!isset($template['content']) || !is_array($template['content']) || $template['content'] === []) {
            return new \WP_Error('evai_invalid_template', 'Gemini returned a template without editable Elementor content. Please try another screenshot.');
        }

        $template['content'] = self::normalize_elements($template['content']);
        return $template;
    }

    private static function normalize_elements(array $elements): array {
        $normalized = [];
        foreach ($elements as $index => $element) {
            if (!is_array($element)) {
                continue;
            }

            $element['id'] = self::valid_element_id($element['id'] ?? ('evai_' . wp_generate_password(8, false, false)));
            $element['elType'] = in_array(($element['elType'] ?? ''), ['container', 'widget'], true) ? $element['elType'] : 'container';
            $element['settings'] = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];
            $element['elements'] = isset($element['elements']) && is_array($element['elements']) ? self::normalize_elements($element['elements']) : [];

            if ($element['elType'] === 'widget') {
                $element['widgetType'] = sanitize_key($element['widgetType'] ?? 'text-editor');
            } else {
                unset($element['widgetType']);
                $element['isInner'] = (bool)($element['isInner'] ?? false);
            }

            $normalized[] = $element;
        }

        return $normalized;
    }

    private static function valid_element_id(string $id): string {
        $id = preg_replace('/[^a-zA-Z0-9_]/', '', $id);
        return $id !== '' ? substr($id, 0, 32) : 'evai_' . wp_generate_password(8, false, false);
    }

    private static function get_base_url() {
        $raw = trim(Settings::get('orchestrator_url', ''));
        if ($raw === '') {
            return new \WP_Error(
                'evai_missing_worker_url',
                'AI Server URL is missing. Go to Elementor Vision AI → Settings and add your AI Server URL (example: https://ai.youragency.com). This is only required when using Ollama.'
            );
        }

        return untrailingslashit($raw);
    }

    private static function build_connectivity_error(\WP_Error $error, string $baseUrl): string {
        $message = $error->get_error_message();
        return sprintf(
            'Cannot reach the AI Server at %1$s. Details: %2$s. Check the AI Server URL in Elementor Vision AI → Settings or ask your developer/agency for help.',
            $baseUrl,
            $message
        );
    }
}
