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

        register_rest_route('evai/v1', '/gemini-test', [
            'methods' => 'POST',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => [self::class, 'gemini_test'],
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
                'model' => self::selected_gemini_model(),
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
        $upload = self::extract_upload($request);
        if (is_wp_error($upload)) {
            return new \WP_REST_Response(['error' => $upload->get_error_message()], 400);
        }

        $backend = Settings::get('ai_backend', 'gemini');
        if ($backend === 'gemini') {
            return self::generate_with_gemini($upload['image_base64'], $upload['mime_type'], $upload['image_size']);
        }

        return self::generate_with_worker($upload['image_base64'], $upload['mime_type'], $upload['filename']);
    }

    public static function gemini_test(\WP_REST_Request $request): \WP_REST_Response {
        $upload = self::extract_upload($request);
        if (is_wp_error($upload)) {
            return new \WP_REST_Response(['error' => $upload->get_error_message()], 400);
        }

        $apiKey = Settings::get('gemini_api_key');
        if ($apiKey === '') {
            return new \WP_REST_Response(['error' => 'Gemini API key is required. Add it in Elementor Vision AI → Settings.'], 400);
        }

        $model = self::selected_gemini_model();
        $prompt = 'Describe this screenshot in 5 bullet points.';
        $result = self::call_gemini($apiKey, $model, $prompt, $upload['image_base64'], $upload['mime_type'], $upload['image_size'], false, 'plain_test');
        if (is_wp_error($result)) {
            return new \WP_REST_Response(['error' => $result->get_error_message()], 502);
        }

        return new \WP_REST_Response([
            'mode' => 'gemini-test',
            'model' => $model,
            'text' => $result['text'],
            'debug' => $result['debug'],
            'message' => 'Gemini test completed. If this works but Generate Template times out, the issue is likely the Elementor JSON prompt or processing pipeline.',
        ], 200);
    }

    private static function generate_with_gemini(string $imageBase64, string $mimeType, int $imageSize): \WP_REST_Response {
        $apiKey = Settings::get('gemini_api_key');
        if ($apiKey === '') {
            return new \WP_REST_Response(['error' => 'Gemini API key is required. Add it in Elementor Vision AI → Settings.'], 400);
        }

        $model = self::selected_gemini_model();
        $prompt = self::elementor_template_prompt();
        $result = self::call_gemini($apiKey, $model, $prompt, $imageBase64, $mimeType, $imageSize, true, 'template_generation');
        if (is_wp_error($result)) {
            return new \WP_REST_Response(['error' => $result->get_error_message()], 502);
        }

        self::log_gemini('parse_start', $model, [
            'timestamp' => self::timestamp(),
            'raw_response_bytes' => strlen($result['raw_body']),
            'stage' => 'response_parsing',
        ]);
        $parseStart = microtime(true);
        $decoded = self::decode_json_text($result['text']);
        self::log_gemini('parse_end', $model, [
            'timestamp' => self::timestamp(),
            'duration_ms' => self::elapsed_ms($parseStart),
            'success' => is_array($decoded) ? 'yes' : 'no',
            'stage' => 'response_parsing',
        ]);

        if (!is_array($decoded)) {
            return new \WP_REST_Response(['error' => 'Gemini responded, but the plugin could not parse valid Elementor JSON. Run “Test Gemini Only” to confirm basic Gemini speed, then simplify the screenshot or switch models.'], 422);
        }

        self::log_gemini('normalization_start', $model, [
            'timestamp' => self::timestamp(),
            'stage' => 'elementor_json_normalization',
        ]);
        $normalizationStart = microtime(true);
        $validated = self::validate_elementor_template($decoded);
        self::log_gemini('normalization_end', $model, [
            'timestamp' => self::timestamp(),
            'duration_ms' => self::elapsed_ms($normalizationStart),
            'success' => is_wp_error($validated) ? 'no' : 'yes',
            'stage' => 'elementor_json_normalization',
        ]);

        if (is_wp_error($validated)) {
            return new \WP_REST_Response(['error' => $validated->get_error_message()], 422);
        }

        return new \WP_REST_Response([
            'similarityScore' => null,
            'elementorJson' => $validated,
            'previewImage' => $imageBase64,
            'mode' => 'gemini-direct',
            'model' => $model,
            'debug' => $result['debug'],
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

    private static function call_gemini(string $apiKey, string $model, string $prompt, string $imageBase64, string $mimeType, int $imageSize, bool $expectJson, string $mode) {
        $promptLength = strlen($prompt);
        $start = microtime(true);
        $debug = [
            'mode' => $mode,
            'model' => $model,
            'request_start' => self::timestamp(),
            'image_size_bytes' => $imageSize,
            'prompt_length' => $promptLength,
            'timeout_seconds' => 180,
        ];

        self::log_gemini('request_start', $model, $debug);
        self::log_gemini('prompt_exact', $model, ['prompt' => $prompt]);

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
                'temperature' => $expectJson ? 0.2 : 0.1,
            ],
        ];

        if ($expectJson) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        $response = wp_remote_post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey),
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($payload),
                'timeout' => 180,
            ]
        );

        $debug['request_end'] = self::timestamp();
        $debug['duration_ms'] = self::elapsed_ms($start);

        if (is_wp_error($response)) {
            $debug['stage'] = 'before_gemini_response';
            $debug['error'] = $response->get_error_message();
            self::log_gemini('request_failure', $model, $debug);
            return new \WP_Error('evai_gemini_connection_failed', 'Could not contact Google Gemini before a response was received. Details: ' . $response->get_error_message());
        }

        $rawBody = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        $debug['http_status'] = $status;
        $debug['raw_response_bytes'] = strlen($rawBody);
        $debug['stage'] = 'gemini_response_received';

        self::log_gemini('raw_response', $model, ['raw_response' => $rawBody]);
        self::log_gemini('request_response_received', $model, $debug);

        $body = json_decode($rawBody, true);
        if ($status < 200 || $status >= 300) {
            $statusName = is_array($body) ? ($body['error']['status'] ?? '') : '';
            $message = is_array($body) ? ($body['error']['message'] ?? 'Google Gemini returned an error.') : 'Google Gemini returned an error.';
            if ($statusName === 'RESOURCE_EXHAUSTED') {
                $message = sprintf(
                    'Google Gemini says this model has reached its usage limit. Current model: %s. Please wait and try again, or switch to another Gemini model in Elementor Vision AI → Settings.',
                    $model
                );
            }
            self::log_gemini('request_failure', $model, array_merge($debug, ['error' => $message]));
            return new \WP_Error('evai_gemini_error', $message);
        }

        if (!is_array($body)) {
            self::log_gemini('request_failure', $model, array_merge($debug, ['stage' => 'gemini_response_json_decode', 'error' => 'Raw Gemini response was not valid JSON.']));
            return new \WP_Error('evai_gemini_invalid_response', 'Gemini responded, but the plugin could not read the response envelope.');
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            self::log_gemini('request_failure', $model, array_merge($debug, ['stage' => 'gemini_text_extract', 'error' => 'No text part found in Gemini response.']));
            return new \WP_Error('evai_gemini_empty_response', 'Gemini responded, but no usable text was returned.');
        }

        self::log_gemini('request_success', $model, $debug);
        return [
            'text' => $text,
            'raw_body' => $rawBody,
            'debug' => $debug,
        ];
    }

    private static function elementor_template_prompt(): string {
        return 'Analyze this website screenshot and return ONLY valid JSON for an importable Elementor page template. Use Elementor flexbox containers, not old sections/columns. Root object must have: version, title, type, content. content must be an array of Elementor container/widget elements. Use editable widgets: heading, text-editor, button, image, icon-box, spacer. Include responsive settings where helpful. Do not wrap response in markdown.';
    }

    private static function extract_upload(\WP_REST_Request $request) {
        $file = $request->get_file_params()['image'] ?? null;
        if (!$file || empty($file['tmp_name'])) {
            return new \WP_Error('evai_missing_image', 'Image is required.');
        }

        $contents = file_get_contents($file['tmp_name']);
        if ($contents === false || $contents === '') {
            return new \WP_Error('evai_invalid_image', 'The uploaded image could not be read.');
        }

        return [
            'image_base64' => base64_encode($contents),
            'image_size' => strlen($contents),
            'mime_type' => $file['type'] ?? 'image/png',
            'filename' => $file['name'] ?? 'upload.png',
        ];
    }

    private static function selected_gemini_model(): string {
        return Settings::sanitize_gemini_model(Settings::get('gemini_model', 'gemini-2.5-flash'));
    }

    private static function log_gemini(string $event, string $model, array $context = []): void {
        $context['event'] = $event;
        $context['model'] = $model;
        error_log('[Elementor Vision AI] Gemini debug ' . wp_json_encode($context));
    }

    private static function timestamp(): string {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function elapsed_ms(float $start): int {
        return (int)round((microtime(true) - $start) * 1000);
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
        foreach ($elements as $element) {
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
