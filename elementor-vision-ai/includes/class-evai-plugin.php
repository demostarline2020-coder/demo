<?php

namespace ElementorVisionAI;

if (!defined('ABSPATH')) {
    exit;
}

require_once EVAI_PLUGIN_DIR . 'includes/class-evai-settings.php';
require_once EVAI_PLUGIN_DIR . 'includes/class-evai-capabilities.php';
require_once EVAI_PLUGIN_DIR . 'includes/class-evai-admin.php';
require_once EVAI_PLUGIN_DIR . 'includes/class-evai-api.php';

class Plugin {
    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init(): void {
        Settings::register();
        Capabilities::refresh();
        Admin::register();
        API::register();
    }
}
