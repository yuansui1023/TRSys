<?php

if (!defined('DOKU_INC') && PHP_SAPI !== 'cli') {
    die();
}

if (!class_exists('DokuWiki_Plugin')) {
    class DokuWiki_Plugin
    {
        public function getConf($key)
        {
            return null;
        }
    }
}

class InstrumentBookingException extends RuntimeException
{
    private string $errorCode;
    private int $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

class helper_plugin_instrumentbooking extends DokuWiki_Plugin
{
    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function defaultConfigPath(): string
    {
        $configured = method_exists($this, 'getConf') ? (string)$this->getConf('config_path') : '';
        if ($configured !== '') {
            return $configured;
        }
        if (defined('DOKU_CONF')) {
            return DOKU_CONF . 'instrumentbooking.local.php';
        }
        $dokuRoot = getenv('DOKUWIKI_ROOT');
        if (is_string($dokuRoot) && $dokuRoot !== '') {
            return rtrim($dokuRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'instrumentbooking.local.php';
        }
        $cwd = getcwd();
        if (is_string($cwd) && is_file($cwd . DIRECTORY_SEPARATOR . 'doku.php')) {
            return $cwd . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'instrumentbooking.local.php';
        }
        $derivedRoot = dirname(__DIR__, 3);
        if (is_file($derivedRoot . DIRECTORY_SEPARATOR . 'doku.php')) {
            return $derivedRoot . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'instrumentbooking.local.php';
        }
        $env = getenv('PLUGIN_CONFIG');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return __DIR__ . '/instrumentbooking.local.php';
    }

    public function loadConfig(?string $path = null): array
    {
        $path = $path ?: $this->defaultConfigPath();
        if (!is_file($path)) {
            throw new InstrumentBookingException('INVALID_INPUT', '预约系统配置文件不存在。', 500);
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new InstrumentBookingException('INVALID_INPUT', '预约系统配置文件格式无效。', 500);
        }

        return $this->validateConfig($config);
    }

    public function validateConfig(array $config): array
    {
        $config += [
            'manager_groups' => [],
            'cancelled_retention_days' => 180,
            'history_retention_days' => 730,
            'instruments' => [],
        ];

        if (empty($config['database_path']) || !is_string($config['database_path'])) {
            throw new InstrumentBookingException('INVALID_INPUT', '预约数据库路径未配置。', 500);
        }
        if (empty($config['timezone']) || !is_string($config['timezone'])) {
            throw new InstrumentBookingException('INVALID_INPUT', '实验室时区未配置。', 500);
        }
        try {
            new DateTimeZone($config['timezone']);
        } catch (Throwable $e) {
            throw new InstrumentBookingException('INVALID_INPUT', '实验室时区配置无效。', 500);
        }

        if (!is_array($config['manager_groups']) || !is_array($config['instruments'])) {
            throw new InstrumentBookingException('INVALID_INPUT', '预约系统配置格式无效。', 500);
        }

        foreach ($config['instruments'] as $code => $instrument) {
            if (!is_string($code) || !preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $code)) {
                throw new InstrumentBookingException('INVALID_INPUT', '仪器代码配置无效。', 500);
            }
            if (!is_array($instrument)) {
                throw new InstrumentBookingException('INVALID_INPUT', '仪器配置格式无效。', 500);
            }
            $required = ['name', 'description', 'allowed_groups', 'min_minutes', 'max_minutes', 'buffer_before_minutes', 'buffer_after_minutes', 'color', 'enabled'];
            foreach ($required as $key) {
                if (!array_key_exists($key, $instrument)) {
                    throw new InstrumentBookingException('INVALID_INPUT', '仪器配置缺少必要字段。', 500);
                }
            }
            if (!is_array($instrument['allowed_groups']) || (int)$instrument['min_minutes'] < 1 || (int)$instrument['max_minutes'] < (int)$instrument['min_minutes']) {
                throw new InstrumentBookingException('INVALID_INPUT', '仪器权限或时间限制配置无效。', 500);
            }
            if ((int)$instrument['buffer_before_minutes'] < 0 || (int)$instrument['buffer_after_minutes'] < 0) {
                throw new InstrumentBookingException('INVALID_INPUT', '仪器缓冲时间配置无效。', 500);
            }
        }

        $config['cancelled_retention_days'] = max(1, (int)$config['cancelled_retention_days']);
        $config['history_retention_days'] = max(1, (int)$config['history_retention_days']);
        return $config;
    }

    public function connect(array $config, bool $allowCreate = false): PDO
    {
        $path = $config['database_path'];
        if (!is_file($path) && !$allowCreate) {
            throw new InstrumentBookingException('INTERNAL_ERROR', '预约数据库尚未初始化，请联系管理员。', 500);
        }

        $pdo = new PDO(
            'sqlite:' . $path,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    public function listInstruments(array $config, array $context): array
    {
        $result = [];
        foreach ($config['instruments'] as $code => $instrument) {
            $isManager = $this->isManager($config, $context);
            if (!$isManager && (!$instrument['enabled'] || !$this->userHasInstrumentAccess($instrument, $context))) {
                continue;
            }
            $result[] = [
                'code' => $code,
                'name' => (string)$instrument['name'],
                'description' => (string)$instrument['description'],
                'minMinutes' => (int)$instrument['min_minutes'],
                'maxMinutes' => (int)$instrument['max_minutes'],
                'bufferBeforeMinutes' => (int)$instrument['buffer_before_minutes'],
                'bufferAfterMinutes' => (int)$instrument['buffer_after_minutes'],
                'color' => (string)$instrument['color'],
                'enabled' => (bool)$instrument['enabled'],
            ];
        }

        return [
            'timezone' => $config['timezone'],
            'isManager' => $this->isManager($config, $context),
            'instruments' => $result,
        ];
    }

    public function listEvents(array $config, PDO $pdo, array $context, array $input): array
    {
        $instrumentCode = $this->requireInstrumentCode($input);
        $instrument = $this->requireInstrument($config, $instrumentCode, false);
        if (!$this->canViewInstrument($config, $instrument, $context)) {
            throw new InstrumentBookingException('PERMISSION_DENIED', '你没有查看该仪器预约的权限。', 403);
        }

        $start = $this->parseIsoToTimestamp($this->requireString($input, 'start', 64));
        $end = $this->parseIsoToTimestamp($this->requireString($input, 'end', 64));
        if ($start >= $end) {
            throw new InstrumentBookingException('INVALID_INPUT', '结束时间必须晚于开始时间。', 400);
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM events
             WHERE instrument_code = :instrument_code
               AND cancelled_at IS NULL
               AND start_ts < :range_end
               AND end_ts > :range_start
             ORDER BY start_ts ASC, id ASC'
        );
        $stmt->execute([
            ':instrument_code' => $instrumentCode,
            ':range_start' => $start,
            ':range_end' => $end,
        ]);

        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $events[] = $this->eventForResponse($config, $row, $context);
        }
        return ['events' => $events];
    }

    public function createEvent(array $config, PDO $pdo, array $context, array $input, ?callable $reloadConfig = null): array
    {
        $this->requireAuthenticated($context);
        $this->beginImmediate($pdo);
        try {
            if ($reloadConfig !== null) {
                $config = $reloadConfig();
            }

            $requestId = strtolower($this->requireString($input, 'requestId', 64));
            if (!preg_match(self::UUID_PATTERN, $requestId)) {
                throw new InstrumentBookingException('INVALID_INPUT', '请求 ID 格式无效。', 400);
            }

            $existing = $this->findByRequestId($pdo, $requestId);
            if ($existing !== null) {
                $pdo->commit();
                return [
                    'event' => $this->eventForResponse($config, $existing, $context),
                    'idempotent' => true,
                ];
            }

            $instrumentCode = $this->requireInstrumentCode($input);
            $instrument = $this->requireInstrument($config, $instrumentCode, true);
            $eventType = $this->optionalEventType($input);
            $this->assertCreateAllowed($config, $instrument, $context, $eventType);

            [$start, $end, $blockedStart, $blockedEnd] = $this->validatedTimesForInstrument($instrument, $input);
            $this->assertNoConflict($pdo, $instrumentCode, $blockedStart, $blockedEnd, null);

            $now = time();
            $stmt = $pdo->prepare(
                'INSERT INTO events (
                    instrument_code, event_type, owner_user, title, note,
                    start_ts, end_ts, blocked_start_ts, blocked_end_ts,
                    request_id, created_at, updated_at
                ) VALUES (
                    :instrument_code, :event_type, :owner_user, :title, :note,
                    :start_ts, :end_ts, :blocked_start_ts, :blocked_end_ts,
                    :request_id, :created_at, :updated_at
                )'
            );
            $stmt->execute([
                ':instrument_code' => $instrumentCode,
                ':event_type' => $eventType,
                ':owner_user' => $context['user'],
                ':title' => $this->cleanText($this->requireString($input, 'title', 120), 120),
                ':note' => $this->cleanText($this->optionalString($input, 'note', 1000), 1000),
                ':start_ts' => $start,
                ':end_ts' => $end,
                ':blocked_start_ts' => $blockedStart,
                ':blocked_end_ts' => $blockedEnd,
                ':request_id' => $requestId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $event = $this->findById($pdo, (int)$pdo->lastInsertId());
            $pdo->commit();
            return ['event' => $this->eventForResponse($config, $event, $context), 'idempotent' => false];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', '预约数据库正忙，请稍后重试。', 503);
            }
            throw $e;
        }
    }

    public function updateEvent(array $config, PDO $pdo, array $context, array $input, ?callable $reloadConfig = null): array
    {
        $this->requireAuthenticated($context);
        $this->beginImmediate($pdo);
        try {
            if ($reloadConfig !== null) {
                $config = $reloadConfig();
            }

            $eventId = $this->requirePositiveInt($input, 'eventId');
            $existing = $this->findById($pdo, $eventId);
            if ($existing === null || $existing['cancelled_at'] !== null) {
                throw new InstrumentBookingException('EVENT_NOT_FOUND', '预约事件不存在。', 404);
            }
            if (!$this->canEditEvent($config, $existing, $context)) {
                throw new InstrumentBookingException('EVENT_NOT_EDITABLE', '该预约不能修改。', 403);
            }

            $instrumentCode = $this->requireInstrumentCode($input + ['instrumentCode' => $existing['instrument_code']]);
            $instrument = $this->requireInstrument($config, $instrumentCode, true);
            $eventType = array_key_exists('eventType', $input) ? $this->optionalEventType($input) : $existing['event_type'];
            if ($eventType === 'block' && !$this->isManager($config, $context)) {
                throw new InstrumentBookingException('PERMISSION_DENIED', '只有管理员可以创建维护或停机占用。', 403);
            }
            if ($eventType === 'booking' && !$this->isManager($config, $context)) {
                $this->assertInstrumentBookingAccess($instrument, $context);
            }

            [$start, $end, $blockedStart, $blockedEnd] = $this->validatedTimesForInstrument($instrument, $input);
            $this->assertNoConflict($pdo, $instrumentCode, $blockedStart, $blockedEnd, $eventId);

            $stmt = $pdo->prepare(
                'UPDATE events
                 SET instrument_code = :instrument_code,
                     event_type = :event_type,
                     title = :title,
                     note = :note,
                     start_ts = :start_ts,
                     end_ts = :end_ts,
                     blocked_start_ts = :blocked_start_ts,
                     blocked_end_ts = :blocked_end_ts,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':instrument_code' => $instrumentCode,
                ':event_type' => $eventType,
                ':title' => $this->cleanText($this->requireString($input, 'title', 120), 120),
                ':note' => $this->cleanText($this->optionalString($input, 'note', 1000), 1000),
                ':start_ts' => $start,
                ':end_ts' => $end,
                ':blocked_start_ts' => $blockedStart,
                ':blocked_end_ts' => $blockedEnd,
                ':updated_at' => time(),
                ':id' => $eventId,
            ]);

            $event = $this->findById($pdo, $eventId);
            $pdo->commit();
            return ['event' => $this->eventForResponse($config, $event, $context)];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', '预约数据库正忙，请稍后重试。', 503);
            }
            throw $e;
        }
    }

    public function cancelEvent(array $config, PDO $pdo, array $context, array $input, ?callable $reloadConfig = null): array
    {
        $this->requireAuthenticated($context);
        $this->beginImmediate($pdo);
        try {
            if ($reloadConfig !== null) {
                $config = $reloadConfig();
            }

            $eventId = $this->requirePositiveInt($input, 'eventId');
            $event = $this->findById($pdo, $eventId);
            if ($event === null || $event['cancelled_at'] !== null) {
                throw new InstrumentBookingException('EVENT_NOT_FOUND', '预约事件不存在。', 404);
            }
            if (!$this->canCancelEvent($config, $event, $context)) {
                throw new InstrumentBookingException('EVENT_NOT_EDITABLE', '该预约不能取消。', 403);
            }

            $stmt = $pdo->prepare(
                'UPDATE events
                 SET cancelled_at = :cancelled_at,
                     cancelled_by = :cancelled_by,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $now = time();
            $stmt->execute([
                ':cancelled_at' => $now,
                ':cancelled_by' => $context['user'],
                ':updated_at' => $now,
                ':id' => $eventId,
            ]);
            $pdo->commit();
            return ['cancelled' => true, 'eventId' => $eventId];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', '预约数据库正忙，请稍后重试。', 503);
            }
            throw $e;
        }
    }

    public function cleanup(PDO $pdo, array $config, bool $dryRun = false): array
    {
        $now = time();
        $cancelledCutoff = $now - ((int)$config['cancelled_retention_days'] * 86400);
        $historyCutoff = $now - ((int)$config['history_retention_days'] * 86400);

        $this->beginImmediate($pdo);
        try {
            $cancelledCount = $this->countCleanupCancelled($pdo, $cancelledCutoff, $now);
            $historyCount = $this->countCleanupHistory($pdo, $historyCutoff, $now);

            if (!$dryRun) {
                $stmt = $pdo->prepare(
                    'DELETE FROM events
                     WHERE cancelled_at IS NOT NULL
                       AND cancelled_at < :cancelled_cutoff
                       AND end_ts < :now'
                );
                $stmt->execute([':cancelled_cutoff' => $cancelledCutoff, ':now' => $now]);
                $cancelledDeleted = $stmt->rowCount();

                $stmt = $pdo->prepare(
                    'DELETE FROM events
                     WHERE cancelled_at IS NULL
                       AND event_type = :event_type
                       AND end_ts < :history_cutoff
                       AND end_ts < :now'
                );
                $stmt->execute([':event_type' => 'booking', ':history_cutoff' => $historyCutoff, ':now' => $now]);
                $historyDeleted = $stmt->rowCount();
            } else {
                $cancelledDeleted = 0;
                $historyDeleted = 0;
            }

            $pdo->commit();
            return [
                'cancelledChecked' => $cancelledCount,
                'historyChecked' => $historyCount,
                'cancelledDeleted' => $cancelledDeleted,
                'historyDeleted' => $historyDeleted,
                'dryRun' => $dryRun,
            ];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', '预约数据库正忙，请稍后重试。', 503);
            }
            throw $e;
        }
    }

    public function detectFilesystemType(string $path): string
    {
        $target = is_dir($path) ? $path : dirname($path);
        if (PHP_OS_FAMILY === 'Linux') {
            $cmd = 'df -PT ' . escapeshellarg($target) . ' 2>/dev/null';
            $output = shell_exec($cmd);
            if (is_string($output)) {
                $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
                if (isset($lines[1])) {
                    $parts = preg_split('/\s+/', $lines[1]);
                    if (isset($parts[1])) {
                        return strtolower($parts[1]);
                    }
                }
            }
        }
        return 'unknown';
    }

    public function isNetworkFilesystem(string $type): bool
    {
        return in_array(strtolower($type), [
            'nfs', 'nfs4', 'smbfs', 'cifs', 'fuse.sshfs', 'sshfs',
            'glusterfs', 'ceph', 'lustre', 'davfs', 'fuseblk',
        ], true);
    }

    public function schemaVersion(PDO $pdo): int
    {
        return (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    }

    public function applySchema(PDO $pdo, string $schemaPath): void
    {
        if (!is_file($schemaPath)) {
            throw new InstrumentBookingException('INTERNAL_ERROR', '数据库 schema 文件不存在。', 500);
        }
        $sql = file_get_contents($schemaPath);
        if ($sql === false || trim($sql) === '') {
            throw new InstrumentBookingException('INTERNAL_ERROR', '数据库 schema 文件无法读取。', 500);
        }
        $pdo->exec($sql);
    }

    public function requireAuthenticated(array $context): void
    {
        if (empty($context['user'])) {
            throw new InstrumentBookingException('AUTH_REQUIRED', '请先登录 DokuWiki。', 401);
        }
    }

    public function isManager(array $config, array $context): bool
    {
        if (!empty($context['isSuperuser'])) {
            return true;
        }
        $groups = $context['groups'] ?? [];
        return count(array_intersect($groups, $config['manager_groups'])) > 0;
    }

    public function userHasInstrumentAccess(array $instrument, array $context): bool
    {
        $groups = $context['groups'] ?? [];
        return count(array_intersect($groups, $instrument['allowed_groups'])) > 0;
    }

    private function assertCreateAllowed(array $config, array $instrument, array $context, string $eventType): void
    {
        if ($eventType === 'block') {
            if (!$this->isManager($config, $context)) {
                throw new InstrumentBookingException('PERMISSION_DENIED', '只有管理员可以创建维护或停机占用。', 403);
            }
            return;
        }
        if ($this->isManager($config, $context)) {
            return;
        }
        $this->assertInstrumentBookingAccess($instrument, $context);
    }

    private function assertInstrumentBookingAccess(array $instrument, array $context): void
    {
        if (!$this->userHasInstrumentAccess($instrument, $context)) {
            throw new InstrumentBookingException('PERMISSION_DENIED', '你没有预约该仪器的权限。', 403);
        }
    }

    private function canViewInstrument(array $config, array $instrument, array $context): bool
    {
        if ($this->isManager($config, $context)) {
            return true;
        }
        return (bool)$instrument['enabled'] && $this->userHasInstrumentAccess($instrument, $context);
    }

    private function canEditEvent(array $config, array $event, array $context): bool
    {
        if ((int)$event['start_ts'] <= time()) {
            return false;
        }
        if ($this->isManager($config, $context)) {
            return true;
        }
        return $event['event_type'] === 'booking' && $event['owner_user'] === $context['user'];
    }

    private function canCancelEvent(array $config, array $event, array $context): bool
    {
        $now = time();
        if ($this->isManager($config, $context)) {
            if ((int)$event['start_ts'] > $now) {
                return true;
            }
            return $event['event_type'] === 'block' && (int)$event['start_ts'] <= $now && (int)$event['end_ts'] > $now;
        }
        return $event['event_type'] === 'booking'
            && $event['owner_user'] === $context['user']
            && (int)$event['start_ts'] > $now;
    }

    private function eventForResponse(array $config, array $event, array $context): array
    {
        $isManager = $this->isManager($config, $context);
        $isOwner = $event['owner_user'] === ($context['user'] ?? '');
        $visible = $isManager || $isOwner;

        $response = [
            'id' => (int)$event['id'],
            'instrumentCode' => $event['instrument_code'],
            'start' => $this->formatIso((int)$event['start_ts'], $config['timezone']),
            'end' => $this->formatIso((int)$event['end_ts'], $config['timezone']),
            'title' => $visible ? $event['title'] : '已占用',
            'canEdit' => $this->canEditEvent($config, $event, $context),
            'canCancel' => $this->canCancelEvent($config, $event, $context),
        ];

        if ($visible) {
            $response['eventType'] = $event['event_type'];
            $response['note'] = $event['note'];
            $response['ownerUser'] = $event['owner_user'];
            $response['createdAt'] = $this->formatIso((int)$event['created_at'], $config['timezone']);
            $response['updatedAt'] = $this->formatIso((int)$event['updated_at'], $config['timezone']);
        }
        return $response;
    }

    private function validatedTimesForInstrument(array $instrument, array $input): array
    {
        $start = $this->parseIsoToTimestamp($this->requireString($input, 'start', 64));
        $end = $this->parseIsoToTimestamp($this->requireString($input, 'end', 64));
        if ($start >= $end) {
            throw new InstrumentBookingException('INVALID_INPUT', '结束时间必须晚于开始时间。', 400);
        }

        $durationSeconds = $end - $start;
        $minSeconds = (int)$instrument['min_minutes'] * 60;
        $maxSeconds = (int)$instrument['max_minutes'] * 60;
        if ($durationSeconds < $minSeconds) {
            throw new InstrumentBookingException('INVALID_INPUT', '预约时间短于该仪器允许的最短时长。', 400);
        }
        if ($durationSeconds > $maxSeconds) {
            throw new InstrumentBookingException('INVALID_INPUT', '预约时间超过该仪器允许的最长时长。', 400);
        }

        return [
            $start,
            $end,
            $start - ((int)$instrument['buffer_before_minutes'] * 60),
            $end + ((int)$instrument['buffer_after_minutes'] * 60),
        ];
    }

    private function parseIsoToTimestamp(string $value): int
    {
        if (!preg_match('/(Z|[+-]\d{2}:\d{2})$/i', $value)) {
            throw new InstrumentBookingException('INVALID_INPUT', '时间必须包含明确的时区偏移。', 400);
        }
        try {
            $dt = new DateTimeImmutable($value);
        } catch (Throwable $e) {
            throw new InstrumentBookingException('INVALID_INPUT', '时间格式无效。', 400);
        }
        return $dt->getTimestamp();
    }

    private function formatIso(int $timestamp, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone($timezone))
            ->format(DateTimeInterface::ATOM);
    }

    private function assertNoConflict(PDO $pdo, string $instrumentCode, int $blockedStart, int $blockedEnd, ?int $excludeId): void
    {
        $sql = 'SELECT id
                FROM events
                WHERE instrument_code = :instrument_code
                  AND cancelled_at IS NULL
                  AND blocked_start_ts < :new_blocked_end
                  AND blocked_end_ts > :new_blocked_start';
        $params = [
            ':instrument_code' => $instrumentCode,
            ':new_blocked_start' => $blockedStart,
            ':new_blocked_end' => $blockedEnd,
        ];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :current_event_id';
            $params[':current_event_id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch() !== false) {
            throw new InstrumentBookingException('BOOKING_CONFLICT', '该时间段已被占用，请重新选择。', 409);
        }
    }

    private function requireInstrumentCode(array $input): string
    {
        $code = $this->requireString($input, 'instrumentCode', 64);
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $code)) {
            throw new InstrumentBookingException('INVALID_INPUT', '仪器代码格式无效。', 400);
        }
        return $code;
    }

    private function requireInstrument(array $config, string $code, bool $mustBeEnabled): array
    {
        if (!isset($config['instruments'][$code])) {
            throw new InstrumentBookingException('INSTRUMENT_NOT_FOUND', '仪器不存在。', 404);
        }
        $instrument = $config['instruments'][$code];
        if ($mustBeEnabled && !$instrument['enabled']) {
            throw new InstrumentBookingException('INSTRUMENT_DISABLED', '该仪器当前不可预约。', 403);
        }
        return $instrument;
    }

    private function optionalEventType(array $input): string
    {
        $eventType = isset($input['eventType']) ? (string)$input['eventType'] : 'booking';
        if (!in_array($eventType, ['booking', 'block'], true)) {
            throw new InstrumentBookingException('INVALID_INPUT', '事件类型无效。', 400);
        }
        return $eventType;
    }

    private function requireString(array $input, string $key, int $maxLength): string
    {
        if (!array_key_exists($key, $input) || !is_string($input[$key])) {
            throw new InstrumentBookingException('INVALID_INPUT', '请求字段格式无效。', 400);
        }
        $value = trim($input[$key]);
        if ($value === '' || $this->textLength($value) > $maxLength) {
            throw new InstrumentBookingException('INVALID_INPUT', '请求字段长度无效。', 400);
        }
        return $value;
    }

    private function optionalString(array $input, string $key, int $maxLength): string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return '';
        }
        if (!is_string($input[$key])) {
            throw new InstrumentBookingException('INVALID_INPUT', '请求字段格式无效。', 400);
        }
        $value = trim($input[$key]);
        if ($this->textLength($value) > $maxLength) {
            throw new InstrumentBookingException('INVALID_INPUT', '请求字段长度无效。', 400);
        }
        return $value;
    }

    private function requirePositiveInt(array $input, string $key): int
    {
        if (!array_key_exists($key, $input) || filter_var($input[$key], FILTER_VALIDATE_INT) === false) {
            throw new InstrumentBookingException('INVALID_INPUT', '请求字段格式无效。', 400);
        }
        $value = (int)$input[$key];
        if ($value < 1) {
            throw new InstrumentBookingException('INVALID_INPUT', '请求字段格式无效。', 400);
        }
        return $value;
    }

    private function cleanText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        if ($value === '' || $this->textLength($value) > $maxLength) {
            throw new InstrumentBookingException('INVALID_INPUT', '请求字段长度无效。', 400);
        }
        return $value;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function beginImmediate(PDO $pdo): void
    {
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (Throwable $e) {
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', '预约数据库正忙，请稍后重试。', 503);
            }
            throw $e;
        }
    }

    private function rollBackQuietly(PDO $pdo): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $ignored) {
        }
    }

    private function findById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function findByRequestId(PDO $pdo, string $requestId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM events WHERE request_id = :request_id');
        $stmt->execute([':request_id' => $requestId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function countCleanupCancelled(PDO $pdo, int $cutoff, int $now): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM events
             WHERE cancelled_at IS NOT NULL
               AND cancelled_at < :cutoff
               AND end_ts < :now'
        );
        $stmt->execute([':cutoff' => $cutoff, ':now' => $now]);
        return (int)$stmt->fetchColumn();
    }

    private function countCleanupHistory(PDO $pdo, int $cutoff, int $now): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM events
             WHERE cancelled_at IS NULL
               AND event_type = :event_type
               AND end_ts < :cutoff
               AND end_ts < :now'
        );
        $stmt->execute([':event_type' => 'booking', ':cutoff' => $cutoff, ':now' => $now]);
        return (int)$stmt->fetchColumn();
    }

    private function isBusyException(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'database is locked')
            || str_contains($message, 'database is busy')
            || str_contains($message, 'sqlstate[hy000]: general error: 5');
    }
}
