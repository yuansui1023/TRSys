<?php

if (!defined('DOKU_INC')) {
    die();
}

require_once __DIR__ . '/helper.php';

class syntax_plugin_instrumentbooking extends DokuWiki_Syntax_Plugin
{
    public function getType()
    {
        return 'substition';
    }

    public function getPType()
    {
        return 'block';
    }

    public function getSort()
    {
        return 155;
    }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~INSTRUMENTBOOKING~~', $mode, 'plugin_instrumentbooking');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return [];
    }

    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml') {
            return false;
        }

        $renderer->info['cache'] = false;
        $base = defined('DOKU_BASE') ? DOKU_BASE : '';
        $ajaxUrl = $base . 'lib/exe/ajax.php?call=instrumentbooking';
        $vendorJs = $base . 'lib/plugins/instrumentbooking/vendor/fullcalendar/index.global.min.js';

        $helper = new helper_plugin_instrumentbooking();
        $config = null;
        try {
            $config = $helper->loadBookingConfig();
        } catch (Throwable $e) {
            // The repository link can still fall back to plugin.info.txt.
        }
        $build = $helper->pluginBuildMeta($config, __DIR__);
        $buildAttributes = '';
        if (is_string($build['commit']) && preg_match('/^[0-9a-f]{40}$/', $build['commit']) === 1) {
            $buildAttributes .= ' data-build-commit="' . $this->escape($build['commit']) . '"';
        }
        if (
            is_string($build['repositoryUrl'])
            && str_starts_with($build['repositoryUrl'], 'https://github.com/')
        ) {
            $buildAttributes .= ' data-repository-url="' . $this->escape($build['repositoryUrl']) . '"';
        }

        $renderer->doc .= '<div id="instrument-booking-app" class="instrument-booking-app"'
            . ' data-ajax-url="' . $this->escape($ajaxUrl) . '"'
            . $buildAttributes
            . ' data-fullcalendar-js="' . $this->escape($vendorJs) . '">'
            . '<p>' . $this->escape($this->getLang('loading') ?: 'Loading instrument bookings...') . '</p>'
            . '</div>' . "\n";
        return true;
    }

    private function escape(string $value): string
    {
        return function_exists('hsc') ? hsc($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
