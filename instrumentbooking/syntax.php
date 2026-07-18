<?php

if (!defined('DOKU_INC')) {
    die();
}

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

        $base = defined('DOKU_BASE') ? DOKU_BASE : '';
        $ajaxUrl = $base . 'lib/exe/ajax.php?call=instrumentbooking';
        $vendorJs = $base . 'lib/plugins/instrumentbooking/vendor/fullcalendar/index.global.min.js';
        $vendorCss = $base . 'lib/plugins/instrumentbooking/vendor/fullcalendar/index.global.min.css';
        $sectok = function_exists('getSecurityToken') ? getSecurityToken() : '';

        $renderer->doc .= '<link rel="stylesheet" href="' . $this->escape($vendorCss) . '">' . "\n";
        $renderer->doc .= '<div id="instrument-booking-app" class="instrument-booking-app"'
            . ' data-ajax-url="' . $this->escape($ajaxUrl) . '"'
            . ' data-sectok="' . $this->escape($sectok) . '"'
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
