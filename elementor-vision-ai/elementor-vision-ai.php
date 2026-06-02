<?php
/**
 * Plugin Name: Elementor Vision AI
 * Description: Generate Elementor templates from screenshots using Gemini Vision with iterative visual reconstruction.
 * Version: 0.1.0
 * Author: Elementor Vision AI
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EVAI_VERSION', '0.1.0');
define('EVAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EVAI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EVAI_PLUGIN_DIR . 'includes/class-evai-plugin.php';

\ElementorVisionAI\Plugin::instance();
