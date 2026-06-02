<?php

namespace ElementorVisionAI;

if (!defined('ABSPATH')) {
    exit;
}

class Capabilities {
    public const OPTION_KEY = 'evai_capability_map';

    public static function refresh(): array {
        $map = self::map();
        update_option(self::OPTION_KEY, $map, false);
        return $map;
    }

    public static function map(): array {
        $elementorActive = did_action('elementor/loaded') || class_exists('\\Elementor\\Plugin');
        $elementorProActive = defined('ELEMENTOR_PRO_VERSION') || class_exists('\\ElementorPro\\Plugin');

        $freeWidgets = [
            'heading',
            'text-editor',
            'image',
            'icon-box',
            'button',
            'html',
            'container',
            'spacer',
            'divider',
            'social-icons',
        ];

        $proWidgets = [
            'form',
            'nav-menu',
            'loop-grid',
            'slides',
            'popup',
            'price-table',
            'posts',
            'theme-site-logo',
            'theme-site-title',
            'theme-page-title',
            'theme-post-title',
            'theme-post-content',
            'theme-post-featured-image',
        ];

        return [
            'elementor_active' => $elementorActive,
            'elementor_pro_active' => $elementorProActive,
            'available_widgets' => $elementorProActive ? array_values(array_unique(array_merge($freeWidgets, $proWidgets))) : $freeWidgets,
            'fallbacks' => [
                'nav-menu' => 'html',
                'form' => 'html',
                'loop-grid' => 'container_cards',
                'slides' => 'image',
                'popup' => 'button',
                'price-table' => 'container_cards',
                'posts' => 'container_cards',
            ],
        ];
    }

    public static function widget_available(string $widgetType): bool {
        return in_array($widgetType, self::map()['available_widgets'], true);
    }
}
