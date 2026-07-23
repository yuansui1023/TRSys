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
        $timezone = 'America/Los_Angeles';
        try {
            $config = $helper->loadBookingConfig();
            if (!empty($config['timezone']) && is_string($config['timezone'])) {
                $timezone = $config['timezone'];
            }
        } catch (Throwable $e) {
            // Keep the default laboratory timezone when local config is unavailable.
        }
        $updated = $helper->pluginUpdatedMeta(__DIR__, $timezone);
        $updatedAttribute = '';
        if ($updated['timestamp'] !== null) {
            $updatedAttribute .= ' data-updated-timestamp="' . $this->escape((string)$updated['timestamp']) . '"';
        }
        if (is_string($updated['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $updated['date']) === 1) {
            $updatedAttribute .= ' data-updated-date="' . $this->escape($updated['date']) . '"';
        }

        $renderer->doc .= '<div id="instrument-booking-app" class="instrument-booking-app"'
            . ' data-ajax-url="' . $this->escape($ajaxUrl) . '"'
            . $updatedAttribute
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
