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
                throw new InstrumentBookingException('INVALID_INPUT', '缺少操作类型。', 400);
            }

            $helper = new helper_plugin_instrumentbooking();
            $context = $this->currentContext();
            $helper->requireAuthenticated($context);

            if (in_array($operation, ['create', 'update', 'cancel'], true)) {
                $this->requireMethod('POST');
                $this->requireCsrfToken();
                $input = $this->readJsonBody();
            } else {
                $this->requireMethod('GET');
                $input = $_GET;
            }

            $config = $helper->loadConfig();
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
                    'message' => '服务器处理预约请求时出错，请稍后重试或联系管理员。',
                ],
            ], 500);
        }
    }

    private function dispatch(string $operation, helper_plugin_instrumentbooking $helper, array $config, array $context, array $input): array
    {
        if ($operation === 'instruments') {
            return $helper->listInstruments($config, $context);
        }

        $pdo = $helper->connect($config);
        $reloadConfig = function () use ($helper): array {
            return $helper->loadConfig();
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

        throw new InstrumentBookingException('INVALID_INPUT', '未知操作类型。', 400);
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
            throw new InstrumentBookingException('INVALID_INPUT', 'HTTP 方法无效。', 405);
        }
    }

    private function requireCsrfToken(): void
    {
        if (!function_exists('checkSecurityToken') || !checkSecurityToken()) {
            throw new InstrumentBookingException('CSRF_FAILED', '安全令牌无效，请刷新页面后重试。', 403);
        }
    }

    private function readJsonBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            throw new InstrumentBookingException('INVALID_INPUT', '请求内容类型必须是 application/json。', 400);
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '' || strlen($raw) > 32768) {
            throw new InstrumentBookingException('INVALID_INPUT', '请求体无效。', 400);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InstrumentBookingException('INVALID_INPUT', 'JSON 请求体格式无效。', 400);
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
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
