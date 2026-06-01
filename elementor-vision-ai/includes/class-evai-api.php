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

        register_rest_route('evai/v1', '/generate-minimal', [
            'methods' => 'POST',
            'permission_callback' => fn() => current_user_can('manage_options'),
            'callback' => [self::class, 'generate_minimal'],
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

    public static function generate_minimal(\WP_REST_Request $request): \WP_REST_Response {
        $upload = self::extract_upload($request);
        if (is_wp_error($upload)) {
            return new \WP_REST_Response(['error' => $upload->get_error_message()], 400);
        }

        return self::generate_with_gemini($upload['image_base64'], $upload['mime_type'], $upload['image_size'], true);
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

        $primaryModel = self::selected_gemini_model();
        $prompt = 'Describe this screenshot in 5 bullet points.';
        $result = self::call_gemini_with_retries($apiKey, $primaryModel, $prompt, $upload['image_base64'], $upload['mime_type'], $upload['image_size'], false, 'plain_test');
        if (is_wp_error($result)) {
            return self::gemini_error_response($result, 502);
        }
        $model = $result['model'];

        return new \WP_REST_Response([
            'mode' => 'gemini-test',
            'model' => $model,
            'text' => $result['text'],
            'debug' => $result['debug'],
            'message' => 'Gemini test completed. If this works but Generate Template times out, the issue is likely the Elementor JSON prompt or processing pipeline.',
        ], 200);
    }

    private static function generate_with_gemini(string $imageBase64, string $mimeType, int $imageSize, bool $minimal = false): \WP_REST_Response {
        $apiKey = Settings::get('gemini_api_key');
        if ($apiKey === '') {
            return new \WP_REST_Response(['error' => 'Gemini API key is required. Add it in Elementor Vision AI → Settings.'], 400);
        }

        $primaryModel = self::selected_gemini_model();
        $prompt = $minimal ? self::minimal_elementor_template_prompt() : self::elementor_template_prompt();
        $result = self::call_gemini_with_retries($apiKey, $primaryModel, $prompt, $imageBase64, $mimeType, $imageSize, true, 'layout_description');
        if (is_wp_error($result)) {
            return self::gemini_error_response($result, 502);
        }

        $model = $result['model'];
        $extractedJson = self::extract_json_text($result['text']);
        $savedRaw = self::save_raw_gemini_response($result['raw_body'], $model);
        if (is_wp_error($savedRaw)) {
            self::log_gemini('raw_response_save_failed', $model, ['error' => $savedRaw->get_error_message()]);
            $savedRaw = ['path' => '', 'url' => '', 'error' => $savedRaw->get_error_message()];
        }

        $finishReason = $result['debug']['finish_reason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            return new \WP_REST_Response([
                'error' => 'Gemini response was truncated.',
                'similarityScore' => null,
                'elementorJson' => null,
                'previewImage' => $imageBase64,
                'mode' => 'layout-description-truncated',
                'model' => $model,
                'debug' => $result['debug'],
                'rawGeminiResponse' => $result['raw_body'],
                'geminiText' => $result['text'],
                'extractedJsonText' => $extractedJson,
                'rawResponseFile' => $savedRaw,
                'finishReason' => $finishReason,
                'isTruncated' => true,
                'message' => 'Gemini response was truncated. The layout description is incomplete, so no Elementor JSON was built.',
            ], 422);
        }

        $layout = json_decode($extractedJson, true);
        if (!is_array($layout)) {
            return new \WP_REST_Response([
                'error' => 'Gemini returned a layout description, but it was not valid JSON.',
                'debug' => array_merge($result['debug'], ['failure_stage' => 'layout_description_parse']),
                'rawGeminiResponse' => $result['raw_body'],
                'geminiText' => $result['text'],
                'extractedJsonText' => $extractedJson,
                'rawResponseFile' => $savedRaw,
            ], 422);
        }

        $template = self::build_elementor_template_from_layout($layout);
        if (is_wp_error($template)) {
            return new \WP_REST_Response([
                'error' => $template->get_error_message(),
                'debug' => array_merge($result['debug'], ['failure_stage' => 'strict_elementor_builder']),
                'layoutDescription' => $layout,
                'rawGeminiResponse' => $result['raw_body'],
                'extractedJsonText' => $extractedJson,
                'rawResponseFile' => $savedRaw,
            ], 422);
        }

        $validation = self::validate_strict_elementor_template($template);
        if (is_wp_error($validation)) {
            return new \WP_REST_Response([
                'error' => $validation->get_error_message(),
                'debug' => array_merge($result['debug'], ['failure_stage' => 'strict_elementor_validation']),
                'layoutDescription' => $layout,
                'elementorJson' => $template,
            ], 422);
        }

        return new \WP_REST_Response([
            'similarityScore' => null,
            'elementorJson' => $template,
            'previewImage' => $imageBase64,
            'mode' => $minimal ? 'strict-elementor-minimal' : 'strict-elementor',
            'model' => $model,
            'debug' => $result['debug'],
            'layoutDescription' => $layout,
            'rawGeminiResponse' => $result['raw_body'],
            'geminiText' => $result['text'],
            'extractedJsonText' => $extractedJson,
            'rawResponseFile' => $savedRaw,
            'finishReason' => $finishReason,
            'isTruncated' => false,
            'message' => 'Valid Elementor JSON was built by WordPress from Gemini layout description. Gemini did not generate Elementor internals.',
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

    private static function call_gemini_with_retries(string $apiKey, string $primaryModel, string $prompt, string $imageBase64, string $mimeType, int $imageSize, bool $expectJson, string $mode) {
        $models = array_values(array_unique([$primaryModel, 'gemini-1.5-flash']));
        $backoffs = [2, 5, 10];
        $lastError = null;

        foreach ($models as $modelIndex => $model) {
            for ($attempt = 0; $attempt <= count($backoffs); $attempt++) {
                self::log_gemini('attempt_start', $model, [
                    'mode' => $mode,
                    'model_index' => $modelIndex,
                    'attempt' => $attempt + 1,
                    'retry_count' => $attempt,
                    'primary_model' => $primaryModel,
                ]);

                $result = self::call_gemini($apiKey, $model, $prompt, $imageBase64, $mimeType, $imageSize, $expectJson, $mode, $attempt);
                if (!is_wp_error($result)) {
                    $result['model'] = $model;
                    $result['debug']['final_model'] = $model;
                    $result['debug']['retry_count'] = $attempt;
                    $result['debug']['fallback_used'] = $model !== $primaryModel ? 'yes' : 'no';
                    self::log_gemini('final_model_selected', $model, [
                        'mode' => $mode,
                        'final_model' => $model,
                        'retry_count' => $attempt,
                        'fallback_used' => $model !== $primaryModel ? 'yes' : 'no',
                    ]);
                    return $result;
                }

                $lastError = $result;
                if (!self::is_retryable_gemini_error($result)) {
                    self::log_gemini('non_retryable_failure', $model, [
                        'mode' => $mode,
                        'final_model' => $model,
                        'retry_count' => $attempt,
                        'error' => $result->get_error_message(),
                    ]);
                    return $result;
                }

                if ($attempt < count($backoffs)) {
                    $delay = $backoffs[$attempt];
                    self::log_gemini('retry_scheduled', $model, [
                        'mode' => $mode,
                        'retry_count' => $attempt + 1,
                        'delay_seconds' => $delay,
                        'error' => $result->get_error_message(),
                    ]);
                    sleep($delay);
                }
            }

            if ($model !== 'gemini-1.5-flash') {
                self::log_gemini('model_fallback', 'gemini-1.5-flash', [
                    'mode' => $mode,
                    'from_model' => $model,
                    'to_model' => 'gemini-1.5-flash',
                    'reason' => $lastError ? $lastError->get_error_message() : 'retryable Gemini failure',
                ]);
            }
        }

        if ($lastError instanceof \WP_Error) {
            $data = $lastError->get_error_data();
            self::log_gemini('final_model_failed', 'gemini-1.5-flash', [
                'mode' => $mode,
                'final_model' => 'gemini-1.5-flash',
                'error' => $lastError->get_error_message(),
            ]);
            if (is_array($data)) {
                $data['final_model'] = $data['model'] ?? 'gemini-1.5-flash';
                $data['all_retries_failed'] = 'yes';
                return new \WP_Error($lastError->get_error_code(), $lastError->get_error_message(), $data);
            }
            return $lastError;
        }

        return new \WP_Error('evai_gemini_retry_failed', 'Gemini is temporarily unavailable. Please try again in a few minutes or switch Gemini models in Elementor Vision AI → Settings.');
    }

    private static function is_retryable_gemini_error(\WP_Error $error): bool {
        $data = $error->get_error_data();
        if (!is_array($data)) {
            return false;
        }

        $status = (int)($data['http_status'] ?? 0);
        return in_array($status, [429, 500, 502, 503, 504], true) || ($data['stage'] ?? '') === 'before_gemini_response';
    }

    private static function call_gemini(string $apiKey, string $model, string $prompt, string $imageBase64, string $mimeType, int $imageSize, bool $expectJson, string $mode, int $retryCount = 0) {
        $promptLength = strlen($prompt);
        $start = microtime(true);
        $debug = [
            'mode' => $mode,
            'model' => $model,
            'request_start' => self::timestamp(),
            'image_size_bytes' => $imageSize,
            'prompt_length' => $promptLength,
            'timeout_seconds' => 180,
            'max_output_tokens' => self::gemini_max_output_tokens($expectJson),
            'retry_count' => $retryCount,
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
                'maxOutputTokens' => self::gemini_max_output_tokens($expectJson),
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
            return new \WP_Error('evai_gemini_connection_failed', 'Could not contact Google Gemini before a response was received. Details: ' . $response->get_error_message(), $debug);
        }

        $rawBody = wp_remote_retrieve_body($response);
        $status = wp_remote_retrieve_response_code($response);
        $debug['http_status'] = $status;
        $debug['raw_response_bytes'] = strlen($rawBody);
        $debug['stage'] = 'gemini_response_received';

        self::log_gemini('raw_response', $model, ['raw_response' => $rawBody]);
        self::log_gemini('request_response_received', $model, $debug);

        $body = json_decode($rawBody, true);
        if (is_array($body)) {
            $debug['finish_reason'] = $body['candidates'][0]['finishReason'] ?? '';
            $debug['output_tokens'] = $body['usageMetadata']['candidatesTokenCount'] ?? null;
            $debug['total_tokens'] = $body['usageMetadata']['totalTokenCount'] ?? null;
            self::log_gemini('response_finish_metadata', $model, [
                'finish_reason' => $debug['finish_reason'],
                'total_output_tokens' => $debug['output_tokens'],
                'total_tokens' => $debug['total_tokens'],
            ]);
        }
        if ($status < 200 || $status >= 300) {
            $statusName = is_array($body) ? ($body['error']['status'] ?? '') : '';
            $message = is_array($body) ? ($body['error']['message'] ?? 'Google Gemini returned an error.') : 'Google Gemini returned an error.';
            if ($status === 503) {
                $message = sprintf(
                    'Google Gemini is temporarily overloaded or unavailable for model %s. The plugin retried automatically and may switch to gemini-1.5-flash. Please try again in a few minutes if this continues.',
                    $model
                );
            }
            if ($statusName === 'RESOURCE_EXHAUSTED') {
                $message = sprintf(
                    'Google Gemini says this model has reached its usage limit. Current model: %s. Please wait and try again, or switch to another Gemini model in Elementor Vision AI → Settings.',
                    $model
                );
            }
            self::log_gemini('request_failure', $model, array_merge($debug, ['error' => $message]));
            return new \WP_Error('evai_gemini_error', $message, array_merge($debug, ['failure_stage' => 'gemini_http_error']));
        }

        if (!is_array($body)) {
            self::log_gemini('request_failure', $model, array_merge($debug, ['stage' => 'gemini_response_json_decode', 'error' => 'Raw Gemini response was not valid JSON.']));
            return new \WP_Error('evai_gemini_invalid_response', 'Gemini responded, but the plugin could not read the response envelope.', array_merge($debug, ['failure_stage' => 'gemini_response_json_decode']));
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            self::log_gemini('request_failure', $model, array_merge($debug, ['stage' => 'gemini_text_extract', 'error' => 'No text part found in Gemini response.']));
            return new \WP_Error('evai_gemini_empty_response', 'Gemini responded, but no usable text was returned.', array_merge($debug, ['failure_stage' => 'gemini_text_extract']));
        }

        $debug['text_response_bytes'] = strlen($text);
        self::log_gemini('request_success', $model, $debug);
        return [
            'model' => $model,
            'text' => $text,
            'raw_body' => $rawBody,
            'debug' => $debug,
        ];
    }

    private static function gemini_error_response(\WP_Error $error, int $status): \WP_REST_Response {
        $payload = ['error' => $error->get_error_message()];
        $debug = $error->get_error_data();
        if (is_array($debug)) {
            $payload['debug'] = $debug;
        }
        return new \WP_REST_Response($payload, $status);
    }

    private static function gemini_max_output_tokens(bool $expectJson): int {
        return $expectJson ? 32768 : 1024;
    }

    private static function minimal_elementor_template_prompt(): string {
        return 'Describe this webpage screenshot as compact JSON only. Do not output Elementor JSON. Schema: {"sections":[{"type":"hero|services|stats|cta|footer|content","heading":"","subheading":"","text":"","buttons":[""],"items":[{"title":"","text":""}]}]}. Max 3 sections, max 4 items per section. Essential visible content only. No markdown.';
    }

    private static function elementor_template_prompt(): string {
        return 'Describe this webpage screenshot as structured layout JSON only. Do not output Elementor JSON or Elementor settings. WordPress will build Elementor JSON. Schema: {"sections":[{"type":"hero|services|stats|cta|footer|content","heading":"","subheading":"","text":"","buttons":[""],"items":[{"title":"","text":""}]}]}. Max 6 sections, max 6 items per section. Use visible content only. No styling internals. No markdown.';
    }

    private static function build_elementor_template_from_layout(array $layout) {
        $sections = $layout['sections'] ?? null;
        if (!is_array($sections) || $sections === []) {
            return new \WP_Error('evai_layout_missing_sections', 'Gemini layout description did not include sections.');
        }

        $content = [];
        foreach (array_slice($sections, 0, 8) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $content[] = self::build_section_container($section);
        }

        if ($content === []) {
            return new \WP_Error('evai_layout_empty_sections', 'Gemini layout description did not include usable sections.');
        }

        return [
            'version' => '0.4',
            'title' => sanitize_text_field($layout['title'] ?? 'Elementor Vision AI Template'),
            'type' => 'page',
            'content' => $content,
        ];
    }

    private static function build_section_container(array $section): array {
        $type = sanitize_key($section['type'] ?? 'content');
        $elements = [];

        if (!empty($section['heading'])) {
            $elements[] = self::heading_widget($section['heading'], $type === 'hero' ? 'h1' : 'h2');
        }
        if (!empty($section['subheading'])) {
            $elements[] = self::text_widget($section['subheading']);
        }
        if (!empty($section['text'])) {
            $elements[] = self::text_widget($section['text']);
        }

        if (!empty($section['buttons']) && is_array($section['buttons'])) {
            foreach (array_slice($section['buttons'], 0, 2) as $button) {
                $elements[] = self::button_widget(is_scalar($button) ? strval($button) : 'Learn More');
            }
        }

        if (!empty($section['items']) && is_array($section['items'])) {
            $cards = [];
            foreach (array_slice($section['items'], 0, 6) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $cardElements = [];
                if (!empty($item['title'])) {
                    $cardElements[] = self::heading_widget($item['title'], 'h3');
                }
                if (!empty($item['text'])) {
                    $cardElements[] = self::text_widget($item['text']);
                }
                if ($cardElements !== []) {
                    $cards[] = self::container($cardElements, true, [
                        '_column_size' => 33,
                        'flex_direction' => 'column',
                    ]);
                }
            }
            if ($cards !== []) {
                $elements[] = self::container($cards, true, [
                    '_column_size' => 100,
                    'flex_direction' => 'row',
                ]);
            }
        }

        if ($elements === []) {
            $elements[] = self::text_widget('Generated section');
        }

        return self::container($elements, false, [
            '_column_size' => 100,
            'content_width' => 'boxed',
            'flex_direction' => 'column',
            'padding' => ['unit' => 'px', 'top' => 60, 'right' => 24, 'bottom' => 60, 'left' => 24],
        ]);
    }

    private static function container(array $elements, bool $isInner, array $settings = []): array {
        return [
            'id' => self::element_id(),
            'elType' => 'container',
            'elementType' => 'container',
            '_column_size' => (int)($settings['_column_size'] ?? 100),
            'isInner' => $isInner,
            'settings' => array_merge(['_column_size' => 100], $settings),
            'elements' => $elements,
        ];
    }

    private static function heading_widget($text, string $tag): array {
        return self::widget('heading', [
            '_column_size' => 100,
            'title' => sanitize_text_field(is_scalar($text) ? strval($text) : ''),
            'header_size' => $tag,
        ]);
    }

    private static function text_widget($text): array {
        return self::widget('text-editor', [
            '_column_size' => 100,
            'editor' => wp_kses_post(is_scalar($text) ? strval($text) : ''),
        ]);
    }

    private static function button_widget(string $text): array {
        return self::widget('button', [
            '_column_size' => 100,
            'text' => sanitize_text_field($text),
            'link' => ['url' => '#'],
        ]);
    }

    private static function widget(string $widgetType, array $settings): array {
        return [
            'id' => self::element_id(),
            'elType' => 'widget',
            'elementType' => 'widget',
            '_column_size' => (int)($settings['_column_size'] ?? 100),
            'widgetType' => $widgetType,
            'settings' => array_merge(['_column_size' => 100], $settings),
            'elements' => [],
        ];
    }

    private static function validate_strict_elementor_template(array $template) {
        if (($template['type'] ?? '') !== 'page' || empty($template['content']) || !is_array($template['content'])) {
            return new \WP_Error('evai_invalid_elementor_root', 'Generated Elementor template root is invalid.');
        }

        foreach ($template['content'] as $element) {
            $error = self::validate_elementor_element($element);
            if (is_wp_error($error)) {
                return $error;
            }
        }

        return true;
    }

    private static function validate_elementor_element($element) {
        if (!is_array($element)) {
            return new \WP_Error('evai_invalid_element', 'Elementor element must be an object.');
        }
        foreach (['id', 'elType', 'elementType', 'settings', 'elements'] as $key) {
            if (!array_key_exists($key, $element)) {
                return new \WP_Error('evai_missing_element_key', sprintf('Elementor element is missing required key: %s', $key));
            }
        }
        if (!array_key_exists('_column_size', $element) || !array_key_exists('_column_size', $element['settings'])) {
            return new \WP_Error('evai_missing_column_size', 'Elementor element is missing required _column_size key.');
        }
        if (($element['elType'] ?? '') === 'widget' && empty($element['widgetType'])) {
            return new \WP_Error('evai_missing_widget_type', 'Elementor widget is missing widgetType.');
        }
        foreach ($element['elements'] as $child) {
            $error = self::validate_elementor_element($child);
            if (is_wp_error($error)) {
                return $error;
            }
        }
        return true;
    }

    private static function element_id(): string {
        return substr(strtolower(wp_generate_password(8, false, false)), 0, 8);
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

    private static function extract_json_text(string $text): string {
        $clean = trim($text);

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $clean, $matches)) {
            $clean = trim($matches[1]);
        } else {
            $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
            $clean = preg_replace('/\s*```$/', '', $clean);
            $firstBrace = strpos($clean, '{');
            $lastBrace = strrpos($clean, '}');
            if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
                $clean = substr($clean, $firstBrace, $lastBrace - $firstBrace + 1);
            }
        }

        return trim($clean);
    }

    private static function save_raw_gemini_response(string $rawBody, string $model) {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new \WP_Error('evai_upload_dir_error', $uploads['error']);
        }

        $dir = trailingslashit($uploads['basedir']) . 'elementor-vision-ai/raw-responses';
        if (!wp_mkdir_p($dir)) {
            return new \WP_Error('evai_raw_response_dir_error', 'Could not create raw response directory.');
        }

        $safeModel = sanitize_file_name($model);
        $filename = sprintf('gemini-response-%s-%s.json', gmdate('Ymd-His'), $safeModel);
        $path = trailingslashit($dir) . $filename;
        $bytes = file_put_contents($path, $rawBody);
        if ($bytes === false) {
            return new \WP_Error('evai_raw_response_write_error', 'Could not write raw Gemini response file.');
        }

        return [
            'path' => $path,
            'url' => trailingslashit($uploads['baseurl']) . 'elementor-vision-ai/raw-responses/' . $filename,
            'bytes' => $bytes,
        ];
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
