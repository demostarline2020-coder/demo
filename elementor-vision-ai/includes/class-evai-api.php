<?php

namespace ElementorVisionAI;

if (!defined('ABSPATH')) {
    exit;
}

class API {
    private const GEMINI_MODEL = 'gemini-2.5-flash';

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
                'message' => 'Powered by Gemini 2.5 Flash. No AI Server URL is required.',
                'mode' => 'gemini',
                'model' => self::GEMINI_MODEL,
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

        $model = self::GEMINI_MODEL;
        $prompt = 'Describe this screenshot in 5 bullet points.';
        $result = self::call_gemini_with_retries($apiKey, $model, $prompt, $upload['image_base64'], $upload['mime_type'], $upload['image_size'], false, 'plain_test');
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

        $model = self::GEMINI_MODEL;
        $prompt = $minimal ? self::minimal_elementor_template_prompt() : self::elementor_template_prompt();
        $result = self::call_gemini_with_retries($apiKey, $model, $prompt, $imageBase64, $mimeType, $imageSize, true, 'design_specification');
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
                'mode' => 'design-spec-truncated',
                'model' => $model,
                'debug' => $result['debug'],
                'rawGeminiResponse' => $result['raw_body'],
                'geminiText' => $result['text'],
                'extractedJsonText' => $extractedJson,
                'rawResponseFile' => $savedRaw,
                'finishReason' => $finishReason,
                'isTruncated' => true,
                'message' => 'Gemini response was truncated. The design specification is incomplete, so no Elementor JSON was built.',
            ], 422);
        }

        $layout = json_decode($extractedJson, true);
        if (!is_array($layout)) {
            return new \WP_REST_Response([
                'error' => 'Gemini returned a design specification, but it was not valid JSON.',
                'debug' => array_merge($result['debug'], ['failure_stage' => 'design_spec_parse']),
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
                'designSpecification' => $layout,
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
                'designSpecification' => $layout,
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
            'designSpecification' => $layout,
            'rawGeminiResponse' => $result['raw_body'],
            'geminiText' => $result['text'],
            'extractedJsonText' => $extractedJson,
            'rawResponseFile' => $savedRaw,
            'finishReason' => $finishReason,
            'isTruncated' => false,
            'message' => 'Valid Elementor JSON was built by WordPress from Gemini design specification. Gemini did not generate Elementor internals.',
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

    private static function call_gemini_with_retries(string $apiKey, string $model, string $prompt, string $imageBase64, string $mimeType, int $imageSize, bool $expectJson, string $mode) {
        $backoffs = [2, 5, 10];
        $lastError = null;

        for ($attempt = 0; $attempt <= count($backoffs); $attempt++) {
            self::log_gemini('attempt_start', $model, [
                'mode' => $mode,
                'attempt' => $attempt + 1,
                'retry_count' => $attempt,
                'fixed_model' => self::GEMINI_MODEL,
            ]);

            $result = self::call_gemini($apiKey, $model, $prompt, $imageBase64, $mimeType, $imageSize, $expectJson, $mode, $attempt);
            if (!is_wp_error($result)) {
                $result['model'] = $model;
                $result['debug']['final_model'] = $model;
                $result['debug']['retry_count'] = $attempt;
                self::log_gemini('final_model_selected', $model, [
                    'mode' => $mode,
                    'final_model' => $model,
                    'retry_count' => $attempt,
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

        if ($lastError instanceof \WP_Error) {
            $data = $lastError->get_error_data();
            self::log_gemini('final_model_failed', $model, [
                'mode' => $mode,
                'final_model' => $model,
                'error' => $lastError->get_error_message(),
            ]);
            if (is_array($data)) {
                $data['final_model'] = $model;
                $data['all_retries_failed'] = 'yes';
                return new \WP_Error($lastError->get_error_code(), $lastError->get_error_message(), $data);
            }
            return $lastError;
        }

        return new \WP_Error('evai_gemini_retry_failed', 'Gemini 2.5 Flash is temporarily unavailable. Please try again in a few minutes.');
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
                    'Google Gemini is temporarily overloaded or unavailable for model %s. The plugin retried automatically. Please try again in a few minutes if this continues.',
                    $model
                );
            }
            if ($statusName === 'RESOURCE_EXHAUSTED') {
                $message = 'Google Gemini says Gemini 2.5 Flash has reached its usage limit. Please wait and try again shortly.';
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
        return 'Analyze this screenshot and return compact DESIGN SPEC JSON only. Do not output Elementor JSON. Schema: {"sections":[{"type":"hero|stats|features|services|cta|footer|content","layout":"1-column|2-column|3-column|grid","heading":"","subheading":"","text":"","buttons":[""],"background_color":"#ffffff","text_color":"#111111","accent_color":"#2563eb","padding_top":80,"padding_bottom":80,"heading_size":"48px","heading_weight":700,"alignment":"left|center","column_widths":[60,40],"items":[{"title":"","text":"","value":""}]}]}. Max 3 sections. Essential visual details only. No markdown.';
    }

    private static function elementor_template_prompt(): string {
        return 'Analyze this webpage screenshot and return DESIGN SPECIFICATION JSON only. Do not output Elementor JSON or Elementor settings. WordPress will build Elementor JSON. Capture layout structure, spacing, typography hierarchy, colors, section backgrounds, image positions, button styles, column widths, alignment, visual hierarchy. Schema: {"sections":[{"type":"hero|stats|features|services|cta|footer|content","layout":"1-column|2-column|3-column|grid","heading":"","subheading":"","text":"","buttons":[""],"background_color":"#ffffff","text_color":"#111111","accent_color":"#2563eb","padding_top":100,"padding_bottom":100,"heading_size":"64px","heading_weight":700,"body_size":"18px","alignment":"left|center|right","column_widths":[60,40],"image_position":"left|right|background|none","button_style":"filled|outline|text","items":[{"title":"","text":"","value":""}]}]}. Max 8 sections, max 8 items per section. No markdown.';
    }

    private static function build_elementor_template_from_layout(array $designSpec) {
        $sections = self::normalize_design_sections($designSpec);
        if ($sections === []) {
            return new \WP_Error('evai_design_missing_sections', 'Gemini design specification did not include usable sections.');
        }

        $content = [];
        foreach (array_slice($sections, 0, 10) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $content[] = self::build_section_from_design_spec($section);
        }

        if ($content === []) {
            return new \WP_Error('evai_design_empty_sections', 'Gemini design specification did not include usable section content.');
        }

        return [
            'version' => '0.4',
            'title' => sanitize_text_field($designSpec['title'] ?? 'Elementor Vision AI Template'),
            'type' => 'page',
            'content' => $content,
        ];
    }

    private static function normalize_design_sections(array $designSpec): array {
        if (!empty($designSpec['sections']) && is_array($designSpec['sections'])) {
            return $designSpec['sections'];
        }

        $sections = [];
        foreach (['hero', 'stats', 'features', 'services', 'cta', 'footer'] as $type) {
            if (!empty($designSpec[$type]) && is_array($designSpec[$type])) {
                $section = $designSpec[$type];
                $section['type'] = $section['type'] ?? $type;
                $sections[] = $section;
            }
        }
        return $sections;
    }

    private static function build_section_from_design_spec(array $section): array {
        $type = sanitize_key($section['type'] ?? 'content');
        return match ($type) {
            'hero' => self::hero_section($section),
            'stats' => self::stats_section($section),
            'features' => self::features_section($section),
            'services' => self::services_section($section),
            'cta' => self::cta_section($section),
            'footer' => self::footer_section($section),
            default => self::content_section($section),
        };
    }

    private static function hero_section(array $section): array {
        $content = self::text_stack($section, 'hero');
        $layout = self::layout_value($section['layout'] ?? '1-column');
        if ($layout === '2-column') {
            $widths = self::column_widths($section['column_widths'] ?? [60, 40]);
            $visual = self::visual_placeholder_container($section);
            $columns = [
                self::container($content, true, self::column_settings($widths[0], 'column')),
                self::container([$visual], true, self::column_settings($widths[1], 'column')),
            ];
            return self::section_container($section, $columns, 'row');
        }
        return self::section_container($section, $content, 'column');
    }

    private static function stats_section(array $section): array {
        $items = self::items($section, 6);
        $cards = [];
        foreach ($items as $item) {
            $cards[] = self::container([
                self::heading_widget($item['value'] ?: ($item['title'] ?? ''), 'h3', $section, 'stat'),
                self::text_widget($item['text'] ?: ($item['title'] ?? ''), $section),
            ], true, self::card_settings($section, 25));
        }
        return self::section_container($section, $cards ?: self::text_stack($section, 'stats'), 'row');
    }

    private static function features_section(array $section): array {
        return self::grid_like_section($section, 'features');
    }

    private static function services_section(array $section): array {
        return self::grid_like_section($section, 'services');
    }

    private static function grid_like_section(array $section, string $type): array {
        $elements = self::text_stack($section, $type);
        $cards = [];
        foreach (self::items($section, 8) as $item) {
            $cardElements = [];
            if (!empty($item['title'])) {
                $cardElements[] = self::heading_widget($item['title'], 'h3', $section, 'card');
            }
            if (!empty($item['text'])) {
                $cardElements[] = self::text_widget($item['text'], $section);
            }
            if ($cardElements !== []) {
                $cards[] = self::container($cardElements, true, self::card_settings($section, 33));
            }
        }
        if ($cards !== []) {
            $elements[] = self::container($cards, true, [
                '_column_size' => 100,
                'flex_direction' => 'row',
                'flex_wrap' => 'wrap',
                'gap' => ['unit' => 'px', 'size' => self::int_value($section['column_gap'] ?? 24, 24, 0, 80)],
            ]);
        }
        return self::section_container($section, $elements, 'column');
    }

    private static function cta_section(array $section): array {
        return self::section_container($section, self::text_stack($section, 'cta'), 'column', [
            'align_items' => self::alignment($section),
        ]);
    }

    private static function footer_section(array $section): array {
        $elements = self::text_stack($section, 'footer');
        foreach (self::items($section, 6) as $item) {
            if (!empty($item['title']) || !empty($item['text'])) {
                $elements[] = self::text_widget(trim(($item['title'] ?? '') . ' ' . ($item['text'] ?? '')), $section);
            }
        }
        return self::section_container($section, $elements, 'column');
    }

    private static function content_section(array $section): array {
        $layout = self::layout_value($section['layout'] ?? '1-column');
        if ($layout === '2-column') {
            $widths = self::column_widths($section['column_widths'] ?? [50, 50]);
            $items = self::items($section, 4);
            $right = [];
            foreach ($items as $item) {
                $right[] = self::heading_widget($item['title'] ?? '', 'h3', $section, 'card');
                $right[] = self::text_widget($item['text'] ?? '', $section);
            }
            return self::section_container($section, [
                self::container(self::text_stack($section, 'content'), true, self::column_settings($widths[0], 'column')),
                self::container($right ?: [self::visual_placeholder_widget($section)], true, self::column_settings($widths[1], 'column')),
            ], 'row');
        }
        return self::section_container($section, self::text_stack($section, 'content'), 'column');
    }

    private static function text_stack(array $section, string $context): array {
        $elements = [];
        if (!empty($section['heading'])) {
            $elements[] = self::heading_widget($section['heading'], $context === 'hero' ? 'h1' : 'h2', $section, $context);
        }
        if (!empty($section['subheading'])) {
            $elements[] = self::text_widget($section['subheading'], $section, true);
        }
        if (!empty($section['text'])) {
            $elements[] = self::text_widget($section['text'], $section);
        }
        if (!empty($section['buttons']) && is_array($section['buttons'])) {
            $buttonRow = [];
            foreach (array_slice($section['buttons'], 0, 3) as $button) {
                $buttonRow[] = self::button_widget(is_scalar($button) ? strval($button) : 'Learn More', $section);
            }
            if ($buttonRow !== []) {
                $elements[] = self::container($buttonRow, true, [
                    '_column_size' => 100,
                    'flex_direction' => 'row',
                    'justify_content' => self::alignment($section),
                    'gap' => ['unit' => 'px', 'size' => 12],
                ]);
            }
        }
        return $elements ?: [self::text_widget('Generated section', $section)];
    }

    private static function section_container(array $section, array $elements, string $direction = 'column', array $extraSettings = []): array {
        $settings = array_merge([
            '_column_size' => 100,
            'content_width' => 'boxed',
            'width' => ['unit' => '%', 'size' => 100],
            'flex_direction' => $direction,
            'flex_gap' => ['unit' => 'px', 'size' => self::int_value($section['gap'] ?? 24, 24, 0, 100)],
            'justify_content' => self::alignment($section),
            'align_items' => $direction === 'row' ? 'center' : self::alignment($section),
            'background_background' => 'classic',
            'background_color' => self::color($section['background_color'] ?? '#ffffff', '#ffffff'),
            'padding' => self::box_values(
                self::int_value($section['padding_top'] ?? null, self::default_padding($section), 0, 240),
                self::int_value($section['padding_right'] ?? null, 24, 0, 160),
                self::int_value($section['padding_bottom'] ?? null, self::default_padding($section), 0, 240),
                self::int_value($section['padding_left'] ?? null, 24, 0, 160)
            ),
        ], $extraSettings);
        return self::container($elements, false, $settings);
    }

    private static function visual_placeholder_container(array $section): array {
        return self::container([self::visual_placeholder_widget($section)], true, [
            '_column_size' => 100,
            'min_height' => ['unit' => 'px', 'size' => self::int_value($section['image_height'] ?? 420, 420, 120, 900)],
            'background_background' => 'classic',
            'background_color' => self::color($section['image_background_color'] ?? $section['accent_color'] ?? '#dbeafe', '#dbeafe'),
            'border_radius' => self::box_values(24, 24, 24, 24),
        ]);
    }

    private static function visual_placeholder_widget(array $section): array {
        return self::widget('spacer', [
            '_column_size' => 100,
            'space' => ['unit' => 'px', 'size' => self::int_value($section['image_height'] ?? 360, 360, 80, 900)],
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

    private static function heading_widget($text, string $tag, array $section = [], string $context = 'content'): array {
        $fallbackSize = match ($context) {
            'hero' => 64,
            'stat' => 42,
            'card' => 24,
            default => 40,
        };
        return self::widget('heading', [
            '_column_size' => 100,
            'title' => sanitize_text_field(is_scalar($text) ? strval($text) : ''),
            'header_size' => $tag,
            'align' => self::alignment($section),
            'title_color' => self::color($section['text_color'] ?? '#111827', '#111827'),
            'typography_typography' => 'custom',
            'typography_font_size' => ['unit' => 'px', 'size' => self::px_value($section['heading_size'] ?? null, $fallbackSize, 12, 120)],
            'typography_font_weight' => (string)self::int_value($section['heading_weight'] ?? null, $context === 'hero' ? 700 : 600, 100, 900),
        ]);
    }

    private static function text_widget($text, array $section = [], bool $lead = false): array {
        return self::widget('text-editor', [
            '_column_size' => 100,
            'editor' => wp_kses_post(is_scalar($text) ? strval($text) : ''),
            'align' => self::alignment($section),
            'text_color' => self::color($section['text_color'] ?? '#374151', '#374151'),
            'typography_typography' => 'custom',
            'typography_font_size' => ['unit' => 'px', 'size' => self::px_value($section['body_size'] ?? null, $lead ? 20 : 16, 10, 48)],
        ]);
    }

    private static function button_widget(string $text, array $section = []): array {
        $style = sanitize_key($section['button_style'] ?? 'filled');
        $isOutline = $style === 'outline';
        return self::widget('button', [
            '_column_size' => 100,
            'text' => sanitize_text_field($text),
            'link' => ['url' => '#'],
            'button_text_color' => $isOutline ? self::color($section['accent_color'] ?? '#2563eb', '#2563eb') : '#ffffff',
            'background_color' => $isOutline ? 'rgba(0,0,0,0)' : self::color($section['accent_color'] ?? '#2563eb', '#2563eb'),
            'border_border' => $isOutline ? 'solid' : '',
            'border_width' => $isOutline ? self::box_values(1, 1, 1, 1) : [],
            'border_color' => self::color($section['accent_color'] ?? '#2563eb', '#2563eb'),
            'border_radius' => self::box_values(self::int_value($section['button_radius'] ?? 8, 8, 0, 80), self::int_value($section['button_radius'] ?? 8, 8, 0, 80), self::int_value($section['button_radius'] ?? 8, 8, 0, 80), self::int_value($section['button_radius'] ?? 8, 8, 0, 80)),
            'button_typography_typography' => 'custom',
            'button_typography_font_size' => ['unit' => 'px', 'size' => 16],
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
    private static function items(array $section, int $limit): array {
        if (empty($section['items']) || !is_array($section['items'])) {
            return [];
        }
        return array_values(array_filter(array_slice($section['items'], 0, $limit), 'is_array'));
    }

    private static function column_settings(int $size, string $direction): array {
        return [
            '_column_size' => max(1, min(100, $size)),
            'width' => ['unit' => '%', 'size' => max(1, min(100, $size))],
            'flex_direction' => $direction,
            'gap' => ['unit' => 'px', 'size' => 20],
        ];
    }

    private static function card_settings(array $section, int $size): array {
        return [
            '_column_size' => $size,
            'width' => ['unit' => '%', 'size' => $size],
            'flex_direction' => 'column',
            'background_background' => 'classic',
            'background_color' => self::color($section['card_background_color'] ?? '#ffffff', '#ffffff'),
            'padding' => self::box_values(24, 24, 24, 24),
            'border_radius' => self::box_values(16, 16, 16, 16),
            'gap' => ['unit' => 'px', 'size' => 12],
        ];
    }

    private static function layout_value($value): string {
        $value = is_scalar($value) ? sanitize_key(strval($value)) : '1-column';
        return in_array($value, ['1-column', '2-column', '3-column', 'grid'], true) ? $value : '1-column';
    }

    private static function column_widths($value): array {
        if (!is_array($value) || count($value) < 2) {
            return [50, 50];
        }
        $first = self::int_value($value[0], 50, 10, 90);
        $second = self::int_value($value[1], 100 - $first, 10, 90);
        $total = max(1, $first + $second);
        return [(int)round($first / $total * 100), (int)round($second / $total * 100)];
    }

    private static function alignment(array $section): string {
        $alignment = is_scalar($section['alignment'] ?? null) ? sanitize_key(strval($section['alignment'])) : 'left';
        return in_array($alignment, ['left', 'center', 'right'], true) ? $alignment : 'left';
    }

    private static function default_padding(array $section): int {
        $type = sanitize_key($section['type'] ?? 'content');
        return match ($type) {
            'hero' => 120,
            'cta' => 90,
            'footer' => 56,
            default => 80,
        };
    }

    private static function box_values(int $top, int $right, int $bottom, int $left): array {
        return [
            'unit' => 'px',
            'top' => $top,
            'right' => $right,
            'bottom' => $bottom,
            'left' => $left,
            'isLinked' => false,
        ];
    }

    private static function color($value, string $fallback): string {
        $value = is_scalar($value) ? trim(strval($value)) : '';
        if (preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value) || preg_match('/^rgba?\([^)]+\)$/', $value)) {
            return $value;
        }
        return $fallback;
    }

    private static function px_value($value, int $default, int $min, int $max): int {
        if (is_string($value)) {
            $value = str_replace('px', '', $value);
        }
        return self::int_value($value, $default, $min, $max);
    }

    private static function int_value($value, int $default, int $min, int $max): int {
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int)round((float)$value)));
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
