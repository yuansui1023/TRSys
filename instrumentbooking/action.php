<?php

if (!defined('DOKU_INC')) {
    die();
}

require_once __DIR__ . '/helper.php';

class action_plugin_instrumentbooking extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
    }

    public function handleAjax(Doku_Event $event, $param): void
    {
        if ($event->data !== 'instrumentbooking') {
            return;
        }

        $event->preventDefault();
        $event->stopPropagation();

        try {
            $operation = $this->queryParam('operation');
            if ($operation === '') {
                throw new InstrumentBookingException('INVALID_INPUT', 'The operation is missing.', 400);
            }

            $helper = new helper_plugin_instrumentbooking();
            $context = $this->currentContext();
            $helper->requireAuthenticated($context);

            if (in_array($operation, [
                'create',
                'update',
                'cancel',
                'admin/instrument/create',
                'admin/instrument/update',
            ], true)) {
                $this->requireMethod('POST');
                $this->requireCsrfToken();
                $input = $this->readJsonBody();
            } else {
                $this->requireMethod('GET');
                $input = $_GET;
            }

            $config = $helper->loadBookingConfig();
            $data = $this->dispatch($operation, $helper, $config, $context, $input);
            $this->json(['ok' => true, 'data' => $data], 200);
        } catch (InstrumentBookingException $e) {
            $this->json([
                'ok' => false,
                'error' => [
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                ],
            ], $e->httpStatus());
        } catch (Throwable $e) {
            $this->json([
                'ok' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'The server could not process the booking request. Please try again later or contact an administrator.',
                ],
            ], 500);
        }
    }

    private function dispatch(string $operation, helper_plugin_instrumentbooking $helper, array $config, array $context, array $input): array
    {
        $pdo = $helper->connect($config);
        if ($operation === 'instruments') {
            if ($helper->schemaVersion($pdo) !== helper_plugin_instrumentbooking::SCHEMA_VERSION) {
                $data = [
                    'timezone' => $config['timezone'],
                    'isManager' => $helper->isManager($config, $context),
                    'instruments' => [],
                    'migrationRequired' => true,
                    'migrationMessage' => 'Run: php lib/plugins/instrumentbooking/bin/install.php',
                ];
            } else {
                $data = $helper->listInstruments($config, $pdo, $context);
            }
            $data['sectok'] = function_exists('getSecurityToken')
                ? getSecurityToken()
                : '';
            return $data;
        }

        $reloadConfig = function () use ($helper): array {
            return $helper->loadBookingConfig();
        };

        if ($operation === 'events') {
            return $helper->listEvents($config, $pdo, $context, $input);
        }
        if ($operation === 'create') {
            return $helper->createEvent($config, $pdo, $context, $input, $reloadConfig);
        }
        if ($operation === 'update') {
            return $helper->updateEvent($config, $pdo, $context, $input, $reloadConfig);
        }
        if ($operation === 'cancel') {
            return $helper->cancelEvent($config, $pdo, $context, $input, $reloadConfig);
        }
        if ($operation === 'admin/instruments') {
            return $helper->listAdminInstruments($config, $pdo, $context);
        }
        if ($operation === 'admin/instrument/create') {
            return $helper->createInstrument($config, $pdo, $context, $input);
        }
        if ($operation === 'admin/instrument/update') {
            return $helper->updateInstrument($config, $pdo, $context, $input);
        }

        throw new InstrumentBookingException('INVALID_INPUT', 'Unknown operation.', 400);
    }

    private function currentContext(): array
    {
        global $USERINFO, $INFO;

        $user = $_SERVER['REMOTE_USER'] ?? '';
        $groups = [];
        if (isset($USERINFO['grps']) && is_array($USERINFO['grps'])) {
            $groups = array_values(array_filter(array_map('strval', $USERINFO['grps'])));
        }

        $isAdmin = false;
        if (function_exists('auth_isadmin')) {
            $isAdmin = (bool)auth_isadmin();
        } elseif (isset($INFO['isadmin'])) {
            $isAdmin = (bool)$INFO['isadmin'];
        }

        return [
            'user' => (string)$user,
            'groups' => $groups,
            'isSuperuser' => $isAdmin,
        ];
    }

    private function requireMethod(string $method): void
    {
        $actual = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($actual !== $method) {
            throw new InstrumentBookingException('INVALID_INPUT', 'Invalid HTTP method.', 405);
        }
    }

    private function requireCsrfToken(): void
    {
        $token = $_SERVER['HTTP_X_DOKUWIKI_SECTOK'] ?? '';
        if (!is_string($token) || $token === '' || !function_exists('checkSecurityToken') || !checkSecurityToken($token)) {
            throw new InstrumentBookingException('CSRF_FAILED', 'The security token is invalid. Please refresh the page and try again.', 403);
        }
    }

    private function readJsonBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The request content type must be application/json.', 400);
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '' || strlen($raw) > 32768) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The request body is invalid.', 400);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The JSON request body is invalid.', 400);
        }
        return $decoded;
    }

    private function queryParam(string $name): string
    {
        return isset($_GET[$name]) && is_string($_GET[$name]) ? trim($_GET[$name]) : '';
    }

    private function json(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
