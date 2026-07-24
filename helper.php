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
    public const SCHEMA_VERSION = 3;

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

    public function loadBookingConfig(?string $path = null): array
    {
        $path = $path ?: $this->defaultConfigPath();
        if (!is_file($path)) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The booking configuration file does not exist.', 500);
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The booking configuration file is invalid.', 500);
        }

        return $this->validateConfig($config);
    }

    public function validateConfig(array $config): array
    {
        $config += [
            'cancelled_retention_days' => 180,
            'history_retention_days' => 730,
        ];

        if (empty($config['database_path']) || !is_string($config['database_path'])) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The booking database path is not configured.', 500);
        }
        if (empty($config['timezone']) || !is_string($config['timezone'])) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The lab timezone is not configured.', 500);
        }
        try {
            new DateTimeZone($config['timezone']);
        } catch (Throwable $e) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The lab timezone is invalid.', 500);
        }

        $config['cancelled_retention_days'] = max(1, (int)$config['cancelled_retention_days']);
        $config['history_retention_days'] = max(1, (int)$config['history_retention_days']);
        return $config;
    }

    public function connect(array $config, bool $allowCreate = false): PDO
    {
        $path = $config['database_path'];
        if (!is_file($path) && !$allowCreate) {
            throw new InstrumentBookingException('INTERNAL_ERROR', 'The booking database has not been initialized. Contact an administrator.', 500);
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

    public function listInstruments(array $config, PDO $pdo, array $context): array
    {
        $this->requireAuthenticated($context);
        $this->assertSchemaCurrent($pdo);
        $rows = $pdo->query('SELECT * FROM instruments ORDER BY name COLLATE NOCASE, code')->fetchAll();
        $result = array_map(fn(array $row): array => $this->instrumentForResponse($row), $rows);

        return [
            'timezone' => $config['timezone'],
            'isAdmin' => $this->isPluginAdmin($pdo, $context),
            'instruments' => $result,
        ];
    }

    public function listAdminInstruments(array $config, PDO $pdo, array $context): array
    {
        $this->requireAdmin($config, $pdo, $context);
        return [
            'instruments' => $this->listInstruments($config, $pdo, $context)['instruments'],
            'admins' => $this->listPluginAdmins($pdo),
        ];
    }

    public function listPluginAdmins(PDO $pdo): array
    {
        $this->assertSchemaCurrent($pdo);
        $rows = $pdo->query(
            'SELECT username, added_at
             FROM plugin_admins
             ORDER BY username COLLATE NOCASE'
        )->fetchAll();
        return array_map(function (array $row): array {
            $username = (string)$row['username'];
            return [
                'username' => $username,
                'displayName' => $this->dokuWikiDisplayName($username),
                'addedAt' => (int)$row['added_at'],
            ];
        }, $rows);
    }

    public function listCandidateDokuWikiUsers(array $config, PDO $pdo, array $context): array
    {
        $this->requireAuthenticated($context);
        if (!$this->isPluginAdmin($pdo, $context)) {
            throw new InstrumentBookingException('PERMISSION_DENIED', 'Administrator access is required.', 403);
        }
        $this->assertSchemaCurrent($pdo);

        if (!$this->dokuWikiCanListUsers()) {
            return [
                'supported' => false,
                'users' => [],
                'message' => 'The current DokuWiki authentication backend does not support listing users.',
            ];
        }

        $admins = [];
        foreach ($this->listPluginAdmins($pdo) as $admin) {
            $admins[strtolower($admin['username'])] = true;
        }

        $users = [];
        foreach ($this->retrieveDokuWikiUsers() as $username => $displayName) {
            $username = (string)$username;
            if ($username === '' || isset($admins[strtolower($username)])) {
                continue;
            }
            $users[] = [
                'username' => $username,
                'displayName' => (string)$displayName,
            ];
        }

        usort($users, static function (array $a, array $b): int {
            return strcasecmp($a['username'], $b['username']);
        });

        return [
            'supported' => true,
            'users' => $users,
        ];
    }

    public function addPluginAdmin(array $config, PDO $pdo, array $context, array $input, ?int $nowTimestamp = null): array
    {
        $this->requireAdmin($config, $pdo, $context);
        $this->assertSchemaCurrent($pdo);

        if (array_key_exists('username', $input) && is_array($input['username'])) {
            throw new InstrumentBookingException(
                'INVALID_INPUT',
                'Only one administrator username can be added at a time.',
                400
            );
        }

        $username = $this->cleanText($this->requireString($input, 'username', 255), 255);
        if ($username === '') {
            throw new InstrumentBookingException('INVALID_INPUT', 'Username is required.', 400);
        }
        if (!$this->dokuWikiUserExists($username)) {
            throw new InstrumentBookingException(
                'DOKUWIKI_USER_NOT_FOUND',
                'The DokuWiki user does not exist.',
                404
            );
        }
        $now = $nowTimestamp ?? time();

        $this->beginImmediate($pdo);
        try {
            if (!$this->isPluginAdmin($pdo, $context)) {
                throw new InstrumentBookingException('ADMIN_REQUIRED', 'Administrator access is required.', 403);
            }
            if ($this->findPluginAdmin($pdo, $username) !== null) {
                throw new InstrumentBookingException('INVALID_INPUT', 'That administrator already exists.', 409);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO plugin_admins (username, added_at, added_by)
                 VALUES (:username, :added_at, :added_by)'
            );
            $stmt->execute([
                ':username' => $username,
                ':added_at' => $now,
                ':added_by' => (string)$context['user'],
            ]);
            $this->commitImmediate($pdo);
            return [
                'admin' => [
                    'username' => $username,
                    'displayName' => $this->dokuWikiDisplayName($username),
                    'addedAt' => $now,
                ],
            ];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($e instanceof InstrumentBookingException) {
                throw $e;
            }
            if ($this->isUniqueConstraintException($e)) {
                throw new InstrumentBookingException('INVALID_INPUT', 'That administrator already exists.', 409);
            }
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
            }
            throw $e;
        }
    }

    public function revokePluginAdminCli(PDO $pdo, string $username): array
    {
        $this->assertSchemaCurrent($pdo);
        $username = $this->cleanText($username, 255);
        $this->beginImmediate($pdo);
        try {
            $existing = $this->findPluginAdmin($pdo, $username);
            if ($existing === null) {
                throw new InstrumentBookingException('ADMIN_NOT_FOUND', 'That username is not a TRCal administrator.', 404);
            }
            $count = (int)$pdo->query('SELECT COUNT(*) FROM plugin_admins')->fetchColumn();
            if ($count <= 1) {
                throw new InstrumentBookingException(
                    'LAST_ADMIN_CANNOT_BE_REVOKED',
                    'The last TRCal administrator cannot be revoked.',
                    409
                );
            }
            $stmt = $pdo->prepare('DELETE FROM plugin_admins WHERE username = :username COLLATE NOCASE');
            $stmt->execute([':username' => $username]);
            $this->commitImmediate($pdo);
            return [
                'revoked' => true,
                'username' => (string)$existing['username'],
                'remainingAdmins' => $count - 1,
            ];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
            }
            throw $e;
        }
    }

    public function bootstrapPluginAdminCli(PDO $pdo, string $username, ?int $nowTimestamp = null): array
    {
        $this->assertSchemaCurrent($pdo);
        $username = $this->cleanText($username, 255);
        if ($username === '') {
            throw new InstrumentBookingException('INVALID_INPUT', 'Username is required.', 400);
        }
        if (!$this->dokuWikiUserExists($username)) {
            throw new InstrumentBookingException(
                'USER_NOT_FOUND',
                'That DokuWiki username does not exist.',
                404
            );
        }
        $now = $nowTimestamp ?? time();
        $this->beginImmediate($pdo);
        try {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM plugin_admins')->fetchColumn();
            if ($count > 0) {
                throw new InstrumentBookingException(
                    'INVALID_INPUT',
                    'TRCal administrators already exist. Use Settings or revoke before bootstrapping.',
                    409
                );
            }
            $stmt = $pdo->prepare(
                'INSERT INTO plugin_admins (username, added_at, added_by)
                 VALUES (:username, :added_at, :added_by)'
            );
            $stmt->execute([
                ':username' => $username,
                ':added_at' => $now,
                ':added_by' => 'cli-bootstrap',
            ]);
            $this->commitImmediate($pdo);
            return [
                'bootstrapped' => true,
                'username' => $username,
                'addedAt' => $now,
            ];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
            }
            throw $e;
        }
    }

    public function dokuWikiUserExists(string $username): bool
    {
        $username = trim($username);
        if ($username === '') {
            return false;
        }

        global $auth;
        if (isset($auth) && is_object($auth) && method_exists($auth, 'getUserData')) {
            $data = $auth->getUserData($username);
            return is_array($data);
        }

        if (function_exists('auth_getUserData')) {
            $data = auth_getUserData($username);
            return is_array($data);
        }

        return false;
    }

    public function dokuWikiCanListUsers(): bool
    {
        global $auth;
        if (!isset($auth) || !is_object($auth)) {
            return false;
        }
        if (method_exists($auth, 'canDo')) {
            return (bool)$auth->canDo('getUsers');
        }
        return method_exists($auth, 'retrieveUsers');
    }

    public function dokuWikiDisplayName(string $username): string
    {
        $info = $this->dokuWikiUserInfo($username);
        if ($info === null) {
            return '';
        }
        $name = trim((string)($info['name'] ?? ''));
        return $name;
    }

    /**
     * @return array<string, string> username => displayName
     */
    public function retrieveDokuWikiUsers(): array
    {
        global $auth;
        if (!$this->dokuWikiCanListUsers()) {
            return [];
        }

        $raw = $auth->retrieveUsers(0, 0, []);
        if (!is_array($raw)) {
            return [];
        }

        $users = [];
        foreach ($raw as $username => $info) {
            $username = is_string($username) ? $username : '';
            if ($username === '') {
                continue;
            }
            $displayName = '';
            if (is_array($info) && isset($info['name'])) {
                $displayName = trim((string)$info['name']);
            }
            $users[$username] = $displayName;
        }
        return $users;
    }

    public function dokuWikiUserInfo(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        global $auth;
        if (isset($auth) && is_object($auth) && method_exists($auth, 'getUserData')) {
            $data = $auth->getUserData($username);
            return is_array($data) ? $data : null;
        }

        if (function_exists('auth_getUserData')) {
            $data = auth_getUserData($username);
            return is_array($data) ? $data : null;
        }

        return null;
    }

    public function pluginCodeUpdatedTimestamp(?string $pluginRoot = null): ?int
    {
        $root = $pluginRoot ?? __DIR__;
        $root = realpath($root);
        if ($root === false || !is_dir($root)) {
            return null;
        }

        $excludeDirs = ['.git' => true, 'tests' => true];
        $excludeFiles = [
            'readme.md' => true,
            'install.md' => true,
            'security.md' => true,
            'license' => true,
            'third_party_licenses.md' => true,
            '.ds_store' => true,
        ];
        $includeExtensions = [
            'php' => true,
            'js' => true,
            'css' => true,
            'sql' => true,
        ];

        $latest = null;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $relative = substr($fileInfo->getPathname(), strlen($root) + 1);
            $relative = str_replace('\\', '/', $relative);
            $parts = explode('/', $relative);
            if (isset($excludeDirs[$parts[0]])) {
                continue;
            }
            $basename = strtolower($fileInfo->getFilename());
            if (isset($excludeFiles[$basename])) {
                continue;
            }
            $extension = strtolower($fileInfo->getExtension());
            $included = isset($includeExtensions[$extension]) || $basename === 'plugin.info.txt';
            if (!$included) {
                continue;
            }
            $mtime = $fileInfo->getMTime();
            if (!is_int($mtime) || $mtime <= 0) {
                continue;
            }
            if ($latest === null || $mtime > $latest) {
                $latest = $mtime;
            }
        }

        return $latest;
    }

    public function pluginInfoFallbackDate(?string $pluginRoot = null): ?string
    {
        $root = $pluginRoot ?? __DIR__;
        $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'plugin.info.txt';
        if (!is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return null;
        }
        if (preg_match('/^date\s+(\d{4}-\d{2}-\d{2})\s*$/mi', $contents, $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    public function formatPluginUpdatedDate(int $timestamp, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d');
    }

    /**
     * @return array{timestamp:?int, date:?string}
     */
    public function pluginUpdatedMeta(?string $pluginRoot = null, ?string $timezone = null): array
    {
        $timezone = $timezone ?: 'America/Los_Angeles';
        $timestamp = $this->pluginCodeUpdatedTimestamp($pluginRoot);
        if ($timestamp !== null) {
            return [
                'timestamp' => $timestamp,
                'date' => $this->formatPluginUpdatedDate($timestamp, $timezone),
            ];
        }
        $fallback = $this->pluginInfoFallbackDate($pluginRoot);
        return [
            'timestamp' => null,
            'date' => $fallback,
        ];
    }

    public function createInstrument(array $config, PDO $pdo, array $context, array $input, ?int $nowTimestamp = null): array
    {
        $this->requireAdmin($config, $pdo, $context);
        $this->assertSchemaCurrent($pdo);
        $values = $this->validatedInstrumentInput($input);
        $now = $nowTimestamp ?? time();
        $code = 'tool-' . $this->newUuid();

        $this->beginImmediate($pdo);
        try {
            if (!$this->isPluginAdmin($pdo, $context)) {
                throw new InstrumentBookingException('ADMIN_REQUIRED', 'Administrator access is required.', 403);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO instruments (
                    code, name, description, max_booking_minutes,
                    weekly_quota_minutes, created_at, updated_at
                 ) VALUES (
                    :code, :name, :description, :max_booking_minutes,
                    :weekly_quota_minutes, :created_at, :updated_at
                 )'
            );
            $stmt->execute([
                ':code' => $code,
                ':name' => $values['name'],
                ':description' => $values['description'],
                ':max_booking_minutes' => $values['max_booking_minutes'],
                ':weekly_quota_minutes' => $values['weekly_quota_minutes'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $instrument = $this->findInstrument($pdo, $code);
            $this->commitImmediate($pdo);
            return ['instrument' => $this->instrumentForResponse($instrument)];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isUniqueConstraintException($e)) {
                throw new InstrumentBookingException('INVALID_INPUT', 'An instrument with this name already exists.', 409);
            }
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
            }
            throw $e;
        }
    }

    public function updateInstrument(array $config, PDO $pdo, array $context, array $input, ?int $nowTimestamp = null): array
    {
        $this->requireAdmin($config, $pdo, $context);
        $this->assertSchemaCurrent($pdo);
        $code = $this->requireInstrumentCode($input);
        $values = $this->validatedInstrumentInput($input);
        $now = $nowTimestamp ?? time();

        $this->beginImmediate($pdo);
        try {
            if (!$this->isPluginAdmin($pdo, $context)) {
                throw new InstrumentBookingException('ADMIN_REQUIRED', 'Administrator access is required.', 403);
            }
            if ($this->findInstrument($pdo, $code) === null) {
                throw new InstrumentBookingException('INSTRUMENT_NOT_FOUND', 'The instrument was not found.', 404);
            }
            $stmt = $pdo->prepare(
                'UPDATE instruments
                 SET name = :name,
                     description = :description,
                     max_booking_minutes = :max_booking_minutes,
                     weekly_quota_minutes = :weekly_quota_minutes,
                     updated_at = :updated_at
                 WHERE code = :code'
            );
            $stmt->execute([
                ':name' => $values['name'],
                ':description' => $values['description'],
                ':max_booking_minutes' => $values['max_booking_minutes'],
                ':weekly_quota_minutes' => $values['weekly_quota_minutes'],
                ':updated_at' => $now,
                ':code' => $code,
            ]);
            $instrument = $this->findInstrument($pdo, $code);
            $this->commitImmediate($pdo);
            return ['instrument' => $this->instrumentForResponse($instrument)];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isUniqueConstraintException($e)) {
                throw new InstrumentBookingException('INVALID_INPUT', 'An instrument with this name already exists.', 409);
            }
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
            }
            throw $e;
        }
    }

    public function instrumentDeletionPreview(array $config, PDO $pdo, array $context, array $input): array
    {
        $this->requireAdmin($config, $pdo, $context);
        $this->assertSchemaCurrent($pdo);
        $code = $this->requireInstrumentCode($input);
        $instrument = $this->requireInstrument($pdo, $code);
        $now = time();
        $total = $pdo->prepare('SELECT COUNT(*) FROM events WHERE instrument_code = :code');
        $total->execute([':code' => $code]);
        $future = $pdo->prepare(
            'SELECT COUNT(*) FROM events
             WHERE instrument_code = :code
               AND cancelled_at IS NULL
               AND end_ts > :now'
        );
        $future->execute([':code' => $code, ':now' => $now]);
        return [
            'instrument' => $this->instrumentForResponse($instrument),
            'totalEvents' => (int)$total->fetchColumn(),
            'futureEvents' => (int)$future->fetchColumn(),
        ];
    }

    public function deleteInstrument(array $config, PDO $pdo, array $context, array $input): array
    {
        $this->requireAdmin($config, $pdo, $context);
        $this->assertSchemaCurrent($pdo);
        $code = $this->requireInstrumentCode($input);
        $confirmName = $this->requireString($input, 'confirmName', 120);

        $this->beginImmediate($pdo);
        try {
            if (!$this->isPluginAdmin($pdo, $context)) {
                throw new InstrumentBookingException('ADMIN_REQUIRED', 'Administrator access is required.', 403);
            }
            $instrument = $this->findInstrument($pdo, $code);
            if ($instrument === null) {
                throw new InstrumentBookingException('INSTRUMENT_NOT_FOUND', 'The instrument was not found.', 404);
            }
            if ((string)$instrument['name'] !== $confirmName) {
                throw new InstrumentBookingException(
                    'DELETE_CONFIRMATION_MISMATCH',
                    'The confirmation name does not match the instrument name.',
                    400
                );
            }

            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM events WHERE instrument_code = :code');
            $countStmt->execute([':code' => $code]);
            $deletedEvents = (int)$countStmt->fetchColumn();

            $deleteEvents = $pdo->prepare('DELETE FROM events WHERE instrument_code = :code');
            $deleteEvents->execute([':code' => $code]);
            $remainingEvents = $pdo->prepare('SELECT COUNT(*) FROM events WHERE instrument_code = :code');
            $remainingEvents->execute([':code' => $code]);
            if ((int)$remainingEvents->fetchColumn() !== 0) {
                throw new InstrumentBookingException('DELETE_FAILED', 'Associated events could not be deleted.', 500);
            }

            $deleteInstrument = $pdo->prepare('DELETE FROM instruments WHERE code = :code');
            $deleteInstrument->execute([':code' => $code]);
            if ($deleteInstrument->rowCount() !== 1) {
                throw new InstrumentBookingException('DELETE_FAILED', 'The instrument could not be deleted.', 500);
            }
            $this->commitImmediate($pdo);
            return [
                'deleted' => true,
                'instrumentCode' => $code,
                'deletedEvents' => $deletedEvents,
            ];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($e instanceof InstrumentBookingException) {
                throw $e;
            }
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
            }
            throw new InstrumentBookingException('DELETE_FAILED', 'The instrument could not be deleted.', 500);
        }
    }

    public function listEvents(array $config, PDO $pdo, array $context, array $input): array
    {
        $this->requireAuthenticated($context);
        $this->assertSchemaCurrent($pdo);
        $instrumentCode = $this->requireInstrumentCode($input);
        $this->requireInstrument($pdo, $instrumentCode);

        $start = $this->parseIsoToTimestamp($this->requireString($input, 'start', 64));
        $end = $this->parseIsoToTimestamp($this->requireString($input, 'end', 64));
        if ($start >= $end) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The end time must be later than the start time.', 400);
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
        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $groupId = (string)$row['booking_group_id'];
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [];
            }
            $groups[$groupId][] = $row;
        }
        foreach ($groups as $segments) {
            $fullSegments = $this->findByBookingGroupId($pdo, (string)$segments[0]['booking_group_id']);
            $events[] = $this->eventForResponse(
                $config,
                $pdo,
                $this->logicalEventFromSegments($fullSegments),
                $context
            );
        }
        usort($events, static fn(array $a, array $b): int => strcmp($a['start'], $b['start']));
        return ['events' => $events];
    }

    public function createEvent(
        array $config,
        PDO $pdo,
        array $context,
        array $input,
        ?callable $reloadConfig = null,
        ?int $nowTimestamp = null
    ): array
    {
        $this->requireAuthenticated($context);
        $this->assertSchemaCurrent($pdo);
        $this->beginImmediate($pdo);
        try {
            if ($reloadConfig !== null) {
                $config = $reloadConfig();
            }

            $requestId = strtolower($this->requireString($input, 'requestId', 64));
            if (!preg_match(self::UUID_PATTERN, $requestId)) {
                throw new InstrumentBookingException('INVALID_INPUT', 'The request ID is invalid.', 400);
            }

            $existing = $this->findByRequestId($pdo, $requestId);
            if ($existing !== []) {
                $this->commitImmediate($pdo);
                return [
                    'event' => $this->eventForResponse(
                        $config,
                        $pdo,
                        $this->logicalEventFromSegments($existing),
                        $context
                    ),
                    'idempotent' => true,
                ];
            }

            $instrumentCode = $this->requireInstrumentCode($input);
            $instrument = $this->requireInstrument($pdo, $instrumentCode);
            $eventType = $this->optionalEventType($input);
            $this->assertCreateAllowed($pdo, $context, $eventType);

            $now = $nowTimestamp ?? time();
            [$start, $end] = $this->validatedTimesForInstrument(
                $config,
                $instrument,
                $input,
                $eventType,
                $now
            );
            $internalTitle = $eventType === 'block' ? 'Outage' : 'Booking';
            $note = $this->cleanText($this->optionalString($input, 'note', 1000), 1000, true);
            $bookingGroupId = $requestId;
            $segments = $eventType === 'booking'
                ? $this->splitBookingAtWeekBoundary($start, $end, $config['timezone'])
                : [[$start, $end]];
            foreach ($segments as [$segmentStart, $segmentEnd]) {
                $this->assertNoConflict($pdo, $instrumentCode, $segmentStart, $segmentEnd, null);
            }
            if ($eventType === 'booking') {
                $this->assertWeeklyQuota(
                    $pdo,
                    $instrument,
                    (string)$context['user'],
                    $segments,
                    $config['timezone'],
                    null
                );
            }
            foreach ($segments as [$segmentStart, $segmentEnd]) {
                $this->insertEventSegment(
                    $pdo,
                    $instrumentCode,
                    $eventType,
                    (string)$context['user'],
                    $internalTitle,
                    $note,
                    $segmentStart,
                    $segmentEnd,
                    $requestId,
                    $bookingGroupId,
                    $now,
                    $now
                );
            }
            $event = $this->logicalEventFromSegments($this->findByBookingGroupId($pdo, $bookingGroupId));
            $this->commitImmediate($pdo);
            return ['event' => $this->eventForResponse($config, $pdo, $event, $context), 'idempotent' => false];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
            }
            throw $e;
        }
    }

    public function updateEvent(
        array $config,
        PDO $pdo,
        array $context,
        array $input,
        ?callable $reloadConfig = null,
        ?int $nowTimestamp = null
    ): array
    {
        $this->requireAuthenticated($context);
        $this->assertSchemaCurrent($pdo);
        $this->beginImmediate($pdo);
        try {
            if ($reloadConfig !== null) {
                $config = $reloadConfig();
            }

            $eventId = $this->requirePositiveInt($input, 'eventId');
            $selectedSegment = $this->findById($pdo, $eventId);
            if ($selectedSegment === null || $selectedSegment['cancelled_at'] !== null) {
                throw new InstrumentBookingException('EVENT_NOT_FOUND', 'The booking event was not found.', 404);
            }
            $existingSegments = $this->findByBookingGroupId($pdo, (string)$selectedSegment['booking_group_id']);
            $existing = $this->logicalEventFromSegments($existingSegments);
            $now = $nowTimestamp ?? time();
            if (!$this->canEditEvent($config, $pdo, $existing, $context, $now)) {
                throw new InstrumentBookingException('EVENT_NOT_EDITABLE', 'This booking cannot be edited.', 403);
            }

            $instrumentCode = $this->requireInstrumentCode($input + ['instrumentCode' => $existing['instrument_code']]);
            $instrument = $this->requireInstrument($pdo, $instrumentCode);
            if (array_key_exists('eventType', $input) && $this->optionalEventType($input) !== $existing['event_type']) {
                throw new InstrumentBookingException('INVALID_INPUT', 'The event type cannot be changed after creation.', 400);
            }
            $eventType = $existing['event_type'];
            if ($eventType === 'block' && !$this->isPluginAdmin($pdo, $context)) {
                throw new InstrumentBookingException('PERMISSION_DENIED', 'Only administrators can create outages.', 403);
            }

            [$start, $end] = $this->validatedTimesForInstrument(
                $config,
                $instrument,
                $input,
                $eventType,
                $now
            );
            $internalTitle = $eventType === 'block' ? 'Outage' : 'Booking';
            $note = $this->cleanText($this->optionalString($input, 'note', 1000), 1000, true);
            $segments = $eventType === 'booking'
                ? $this->splitBookingAtWeekBoundary($start, $end, $config['timezone'])
                : [[$start, $end]];
            $groupId = (string)$existing['booking_group_id'];
            foreach ($segments as [$segmentStart, $segmentEnd]) {
                $this->assertNoConflict($pdo, $instrumentCode, $segmentStart, $segmentEnd, $groupId);
            }
            if ($eventType === 'booking') {
                $this->assertWeeklyQuota(
                    $pdo,
                    $instrument,
                    (string)$existing['owner_user'],
                    $segments,
                    $config['timezone'],
                    $groupId
                );
            }
            $delete = $pdo->prepare('DELETE FROM events WHERE booking_group_id = :booking_group_id');
            $delete->execute([':booking_group_id' => $groupId]);
            foreach ($segments as [$segmentStart, $segmentEnd]) {
                $this->insertEventSegment(
                    $pdo,
                    $instrumentCode,
                    $eventType,
                    (string)$existing['owner_user'],
                    $internalTitle,
                    $note,
                    $segmentStart,
                    $segmentEnd,
                    (string)$existing['request_id'],
                    $groupId,
                    (int)$existing['created_at'],
                    $now
                );
            }
            $event = $this->logicalEventFromSegments($this->findByBookingGroupId($pdo, $groupId));
            $this->commitImmediate($pdo);
            return ['event' => $this->eventForResponse($config, $pdo, $event, $context)];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
            }
            throw $e;
        }
    }

    public function cancelEvent(array $config, PDO $pdo, array $context, array $input, ?callable $reloadConfig = null): array
    {
        $this->requireAuthenticated($context);
        $this->assertSchemaCurrent($pdo);
        $this->beginImmediate($pdo);
        try {
            if ($reloadConfig !== null) {
                $config = $reloadConfig();
            }

            $eventId = $this->requirePositiveInt($input, 'eventId');
            $selectedSegment = $this->findById($pdo, $eventId);
            if ($selectedSegment === null || $selectedSegment['cancelled_at'] !== null) {
                throw new InstrumentBookingException('EVENT_NOT_FOUND', 'The booking event was not found.', 404);
            }
            $groupId = (string)$selectedSegment['booking_group_id'];
            $event = $this->logicalEventFromSegments($this->findByBookingGroupId($pdo, $groupId));
            if (!$this->canCancelEvent($pdo, $event, $context)) {
                throw new InstrumentBookingException('EVENT_NOT_EDITABLE', 'This booking cannot be cancelled.', 403);
            }

            $stmt = $pdo->prepare(
                'UPDATE events
                 SET cancelled_at = :cancelled_at,
                     cancelled_by = :cancelled_by,
                     updated_at = :updated_at
                 WHERE booking_group_id = :booking_group_id
                   AND cancelled_at IS NULL'
            );
            $now = time();
            $stmt->execute([
                ':cancelled_at' => $now,
                ':cancelled_by' => $context['user'],
                ':updated_at' => $now,
                ':booking_group_id' => $groupId,
            ]);
            $this->commitImmediate($pdo);
            return ['cancelled' => true, 'eventId' => $eventId];
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            if ($this->isBusyException($e)) {
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
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

            $this->commitImmediate($pdo);
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
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
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
            throw new InstrumentBookingException('INTERNAL_ERROR', 'The database schema file does not exist.', 500);
        }
        $sql = file_get_contents($schemaPath);
        if ($sql === false || trim($sql) === '') {
            throw new InstrumentBookingException('INTERNAL_ERROR', 'The database schema file cannot be read.', 500);
        }
        $version = $this->schemaVersion($pdo);
        if ($version === 0) {
            $pdo->exec($sql);
            return;
        }
        if ($version === 1) {
            $this->migrateSchemaV1ToV2($pdo);
            $version = 2;
        }
        if ($version === 2) {
            $this->migrateSchemaV2ToV3($pdo);
            return;
        }
        if ($version !== self::SCHEMA_VERSION) {
            throw new InstrumentBookingException('INTERNAL_ERROR', 'The booking database schema version is not supported.', 500);
        }
    }

    public function assertSchemaCurrent(PDO $pdo): void
    {
        if ($this->schemaVersion($pdo) !== self::SCHEMA_VERSION) {
            throw new InstrumentBookingException(
                'SCHEMA_MIGRATION_REQUIRED',
                'The booking database must be migrated. Run: php lib/plugins/instrumentbooking/bin/install.php',
                500
            );
        }
    }

    private function migrateSchemaV1ToV2(PDO $pdo): void
    {
        $this->beginImmediate($pdo);
        try {
            $pdo->exec(
                'CREATE TABLE instruments (
                    code TEXT PRIMARY KEY,
                    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
                    description TEXT NOT NULL DEFAULT \'\',
                    max_booking_minutes INTEGER NOT NULL,
                    weekly_quota_minutes INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    CHECK (length(name) BETWEEN 1 AND 120),
                    CHECK (length(description) <= 1000),
                    CHECK (max_booking_minutes BETWEEN 30 AND 10080),
                    CHECK (max_booking_minutes % 30 = 0),
                    CHECK (
                        weekly_quota_minutes = 0
                        OR (
                            weekly_quota_minutes BETWEEN 30 AND 10080
                            AND weekly_quota_minutes % 30 = 0
                            AND weekly_quota_minutes >= max_booking_minutes
                        )
                    )
                )'
            );
            $pdo->exec(
                'CREATE TABLE events_v2 (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    instrument_code TEXT NOT NULL,
                    event_type TEXT NOT NULL CHECK (event_type IN (\'booking\', \'block\')),
                    owner_user TEXT NOT NULL,
                    title TEXT NOT NULL,
                    note TEXT NOT NULL DEFAULT \'\',
                    start_ts INTEGER NOT NULL,
                    end_ts INTEGER NOT NULL,
                    blocked_start_ts INTEGER NOT NULL,
                    blocked_end_ts INTEGER NOT NULL,
                    request_id TEXT NOT NULL,
                    booking_group_id TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    cancelled_at INTEGER,
                    cancelled_by TEXT,
                    CHECK (start_ts < end_ts),
                    CHECK (blocked_start_ts <= start_ts),
                    CHECK (blocked_end_ts >= end_ts)
                )'
            );
            $pdo->exec(
                'INSERT INTO events_v2 (
                    id, instrument_code, event_type, owner_user, title, note,
                    start_ts, end_ts, blocked_start_ts, blocked_end_ts,
                    request_id, booking_group_id, created_at, updated_at,
                    cancelled_at, cancelled_by
                 )
                 SELECT
                    id, instrument_code, event_type, owner_user, title, note,
                    start_ts, end_ts, blocked_start_ts, blocked_end_ts,
                    request_id, request_id, created_at, updated_at,
                    cancelled_at, cancelled_by
                 FROM events'
            );
            $pdo->exec('DROP TABLE events');
            $pdo->exec('ALTER TABLE events_v2 RENAME TO events');
            $this->createEventIndexes($pdo);
            $pdo->exec('PRAGMA user_version = 2');
            $this->commitImmediate($pdo);
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            throw $e;
        }
    }

    private function createEventIndexes(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX IF NOT EXISTS events_conflict_idx ON events (instrument_code, blocked_start_ts, blocked_end_ts)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS events_owner_idx ON events (owner_user, start_ts)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS events_range_idx ON events (start_ts, end_ts)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS events_request_idx ON events (request_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS events_group_idx ON events (booking_group_id)');
    }

    private function migrateSchemaV2ToV3(PDO $pdo): void
    {
        $this->beginImmediate($pdo);
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS plugin_admins (
                    username TEXT PRIMARY KEY COLLATE NOCASE,
                    added_at INTEGER NOT NULL,
                    added_by TEXT NOT NULL DEFAULT \'\',
                    CHECK (length(username) BETWEEN 1 AND 255)
                )'
            );
            $pdo->exec('PRAGMA user_version = 3');
            $this->commitImmediate($pdo);
        } catch (Throwable $e) {
            $this->rollBackQuietly($pdo);
            throw $e;
        }
    }

    private function findPluginAdmin(PDO $pdo, string $username): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM plugin_admins WHERE username = :username COLLATE NOCASE');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function requireAuthenticated(array $context): void
    {
        if (empty($context['user'])) {
            throw new InstrumentBookingException('AUTH_REQUIRED', 'Please log in to DokuWiki first.', 401);
        }
    }

    public function isPluginAdmin(PDO $pdo, array $context): bool
    {
        if (empty($context['user'])) {
            return false;
        }
        if ($this->schemaVersion($pdo) < 3) {
            return false;
        }
        return $this->findPluginAdmin($pdo, (string)$context['user']) !== null;
    }

    public function requireAdmin(array $config, PDO $pdo, array $context): void
    {
        $this->requireAuthenticated($context);
        if (!$this->isPluginAdmin($pdo, $context)) {
            throw new InstrumentBookingException('ADMIN_REQUIRED', 'Administrator access is required.', 403);
        }
    }

    private function assertCreateAllowed(PDO $pdo, array $context, string $eventType): void
    {
        if ($eventType === 'block' && !$this->isPluginAdmin($pdo, $context)) {
            throw new InstrumentBookingException('PERMISSION_DENIED', 'Only administrators can create outages.', 403);
        }
    }

    private function canEditEvent(array $config, PDO $pdo, array $event, array $context, ?int $nowTimestamp = null): bool
    {
        if ((int)$event['start_ts'] < $this->nextBookableSlotTimestamp($config['timezone'], $nowTimestamp)) {
            return false;
        }
        return $event['owner_user'] === $context['user']
            && ($event['event_type'] === 'booking' || $this->isPluginAdmin($pdo, $context));
    }

    private function canCancelEvent(PDO $pdo, array $event, array $context): bool
    {
        $now = time();
        if ($event['owner_user'] !== $context['user']) {
            return false;
        }
        if ($event['event_type'] === 'block') {
            return $this->isPluginAdmin($pdo, $context) && (int)$event['end_ts'] > $now;
        }
        return (int)$event['start_ts'] > $now;
    }

    private function eventForResponse(array $config, PDO $pdo, array $event, array $context): array
    {
        return [
            'id' => (int)$event['id'],
            'bookingGroupId' => $event['booking_group_id'],
            'instrumentCode' => $event['instrument_code'],
            'start' => $this->formatIso((int)$event['start_ts'], $config['timezone']),
            'end' => $this->formatIso((int)$event['end_ts'], $config['timezone']),
            'title' => $event['title'],
            'eventType' => $event['event_type'],
            'note' => $event['note'],
            'ownerUser' => $event['owner_user'],
            'createdAt' => $this->formatIso((int)$event['created_at'], $config['timezone']),
            'updatedAt' => $this->formatIso((int)$event['updated_at'], $config['timezone']),
            'canEdit' => $this->canEditEvent($config, $pdo, $event, $context),
            'canCancel' => $this->canCancelEvent($pdo, $event, $context),
        ];
    }

    public function nextBookableSlotTimestamp(string $timezone, ?int $nowTimestamp = null): int
    {
        new DateTimeZone($timezone);
        $now = $nowTimestamp ?? time();
        return (intdiv($now, 1800) + 1) * 1800;
    }

    private function validatedTimesForInstrument(
        array $config,
        array $instrument,
        array $input,
        string $eventType,
        int $nowTimestamp
    ): array
    {
        $startValue = $this->requireString($input, 'start', 64);
        $endValue = $this->requireString($input, 'end', 64);
        $start = $this->parseIsoToTimestamp($startValue);
        $end = $this->parseIsoToTimestamp($endValue);
        if (
            !$this->isThirtyMinuteTimestamp($startValue)
            || !$this->isThirtyMinuteTimestamp($endValue)
            || ($end - $start) % 1800 !== 0
        ) {
            throw new InstrumentBookingException(
                'INVALID_INPUT',
                'Start and end times must use 30-minute intervals.',
                400
            );
        }
        if ($start >= $end) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The end time must be later than the start time.', 400);
        }

        $earliestStart = $this->nextBookableSlotTimestamp($config['timezone'], $nowTimestamp);
        if ($start < $earliestStart) {
            throw new InstrumentBookingException(
                'INVALID_INPUT',
                'The earliest available start time is ' . $this->formatSlotTime($earliestStart, $config['timezone']) . '.',
                400
            );
        }

        $bookingHorizon = (new DateTimeImmutable('@' . $nowTimestamp))
            ->setTimezone(new DateTimeZone($config['timezone']))
            ->modify('+7 days')
            ->getTimestamp();
        if ($start > $bookingHorizon) {
            throw new InstrumentBookingException(
                'INVALID_INPUT',
                'The event must start within the next seven calendar days.',
                400
            );
        }

        if ($eventType === 'block') {
            return [$start, $end];
        }

        $durationSeconds = $end - $start;
        $minSeconds = 30 * 60;
        $maxMinutes = (int)$instrument['max_booking_minutes'];
        $maxSeconds = $maxMinutes * 60;
        if ($durationSeconds < $minSeconds) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The booking is shorter than the minimum duration for this instrument.', 400);
        }
        if ($durationSeconds > $maxSeconds) {
            $requestedMinutes = intdiv($durationSeconds, 60);
            throw new InstrumentBookingException(
                'INVALID_INPUT',
                'The booking exceeds the maximum duration for this instrument. Requested: '
                    . $this->formatMinutes($requestedMinutes)
                    . ', limit: '
                    . $this->formatMinutes($maxMinutes) . '.',
                400
            );
        }

        return [$start, $end];
    }

    private function isThirtyMinuteTimestamp(string $value): bool
    {
        return preg_match('/T\d{2}:(?:00|30):00(?:Z|[+-]\d{2}:\d{2})$/i', $value) === 1;
    }

    private function formatSlotTime(int $timestamp, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('H:i');
    }

    private function parseIsoToTimestamp(string $value): int
    {
        if (!preg_match('/(Z|[+-]\d{2}:\d{2})$/i', $value)) {
            throw new InstrumentBookingException('INVALID_INPUT', 'Times must include an explicit timezone offset.', 400);
        }
        try {
            $dt = new DateTimeImmutable($value);
        } catch (Throwable $e) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The time format is invalid.', 400);
        }
        return $dt->getTimestamp();
    }

    private function formatIso(int $timestamp, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone($timezone))
            ->format(DateTimeInterface::ATOM);
    }

    private function assertNoConflict(PDO $pdo, string $instrumentCode, int $newStart, int $newEnd, ?string $excludeGroupId): void
    {
        $sql = 'SELECT id
                FROM events
                WHERE instrument_code = :instrument_code
                  AND cancelled_at IS NULL
                  AND start_ts < :new_end
                  AND end_ts > :new_start';
        $params = [
            ':instrument_code' => $instrumentCode,
            ':new_start' => $newStart,
            ':new_end' => $newEnd,
        ];
        if ($excludeGroupId !== null) {
            $sql .= ' AND booking_group_id <> :booking_group_id';
            $params[':booking_group_id'] = $excludeGroupId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch() !== false) {
            throw new InstrumentBookingException('BOOKING_CONFLICT', 'This time slot is already reserved. Please choose another time.', 409);
        }
    }

    private function splitBookingAtWeekBoundary(int $start, int $end, string $timezone): array
    {
        [$weekStart, $weekEnd] = $this->weekBoundsForTimestamp($start, $timezone);
        if ($end <= $weekEnd) {
            return [[$start, $end]];
        }
        return [[$start, $weekEnd], [$weekEnd, $end]];
    }

    private function weekBoundsForTimestamp(int $timestamp, string $timezone): array
    {
        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone($timezone));
        $daysSinceSunday = (int)$date->format('w');
        $weekStart = $date->setTime(0, 0, 0)->modify('-' . $daysSinceSunday . ' days');
        return [$weekStart->getTimestamp(), $weekStart->modify('+7 days')->getTimestamp()];
    }

    private function assertWeeklyQuota(
        PDO $pdo,
        array $instrument,
        string $ownerUser,
        array $segments,
        string $timezone,
        ?string $excludeGroupId
    ): void {
        $limitMinutes = (int)$instrument['weekly_quota_minutes'];
        if ($limitMinutes === 0) {
            return;
        }
        foreach ($segments as [$segmentStart, $segmentEnd]) {
            $usedSeconds = $this->sumWeeklyBookingSeconds(
                $pdo,
                (string)$instrument['code'],
                $ownerUser,
                $segmentStart,
                $timezone,
                $excludeGroupId
            );
            $usedMinutes = intdiv($usedSeconds, 60);
            $requestedSeconds = $segmentEnd - $segmentStart;
            $requestedMinutes = intdiv($requestedSeconds, 60);
            if ($usedMinutes + $requestedMinutes > $limitMinutes) {
                throw new InstrumentBookingException(
                    'WEEKLY_LIMIT_EXCEEDED',
                    'Weekly booking limit exceeded. Used: ' . $this->formatMinutes($usedMinutes)
                        . ', requested: ' . $this->formatMinutes($requestedMinutes)
                        . ', limit: ' . $this->formatMinutes($limitMinutes) . '.',
                    409
                );
            }
        }
    }

    public function weeklyBookingMinutesUsed(
        PDO $pdo,
        string $instrumentCode,
        string $ownerUser,
        int $timestampInWeek,
        string $timezone,
        ?string $excludeGroupId = null
    ): int {
        $usedSeconds = $this->sumWeeklyBookingSeconds(
            $pdo,
            $instrumentCode,
            $ownerUser,
            $timestampInWeek,
            $timezone,
            $excludeGroupId
        );
        return intdiv($usedSeconds, 60);
    }

    private function sumWeeklyBookingSeconds(
        PDO $pdo,
        string $instrumentCode,
        string $ownerUser,
        int $timestampInWeek,
        string $timezone,
        ?string $excludeGroupId
    ): int {
        [$weekStart, $weekEnd] = $this->weekBoundsForTimestamp($timestampInWeek, $timezone);
        // Use CASE instead of min()/max() — PDO+SQLite treats bound args in min/max as aggregates.
        $sql = 'SELECT COALESCE(SUM(
                    (CASE WHEN end_ts < :clip_end THEN end_ts ELSE :clip_end_b END)
                    - (CASE WHEN start_ts > :clip_start THEN start_ts ELSE :clip_start_b END)
                ), 0)
                FROM events
                WHERE instrument_code = :instrument_code
                  AND owner_user = :owner_user
                  AND event_type = :event_type
                  AND cancelled_at IS NULL
                  AND start_ts < :range_end
                  AND end_ts > :range_start';
        $params = [
            ':clip_start' => $weekStart,
            ':clip_start_b' => $weekStart,
            ':clip_end' => $weekEnd,
            ':clip_end_b' => $weekEnd,
            ':range_start' => $weekStart,
            ':range_end' => $weekEnd,
            ':instrument_code' => $instrumentCode,
            ':owner_user' => $ownerUser,
            ':event_type' => 'booking',
        ];
        if ($excludeGroupId !== null) {
            $sql .= ' AND booking_group_id <> :booking_group_id';
            $params[':booking_group_id'] = $excludeGroupId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return max(0, (int)$stmt->fetchColumn());
    }

    private function formatMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ($hours === 1 ? ' hour' : ' hours');
        }
        if ($remainingMinutes > 0 || $parts === []) {
            $parts[] = $remainingMinutes . ($remainingMinutes === 1 ? ' minute' : ' minutes');
        }
        return implode(' ', $parts);
    }

    private function insertEventSegment(
        PDO $pdo,
        string $instrumentCode,
        string $eventType,
        string $ownerUser,
        string $title,
        string $note,
        int $start,
        int $end,
        string $requestId,
        string $bookingGroupId,
        int $createdAt,
        int $updatedAt
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO events (
                instrument_code, event_type, owner_user, title, note,
                start_ts, end_ts, blocked_start_ts, blocked_end_ts,
                request_id, booking_group_id, created_at, updated_at
             ) VALUES (
                :instrument_code, :event_type, :owner_user, :title, :note,
                :start_ts, :end_ts, :start_ts, :end_ts,
                :request_id, :booking_group_id, :created_at, :updated_at
             )'
        );
        $stmt->execute([
            ':instrument_code' => $instrumentCode,
            ':event_type' => $eventType,
            ':owner_user' => $ownerUser,
            ':title' => $title,
            ':note' => $note,
            ':start_ts' => $start,
            ':end_ts' => $end,
            ':request_id' => $requestId,
            ':booking_group_id' => $bookingGroupId,
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
        ]);
    }

    private function logicalEventFromSegments(array $segments): array
    {
        if ($segments === []) {
            throw new InstrumentBookingException('EVENT_NOT_FOUND', 'The booking event was not found.', 404);
        }
        usort($segments, static fn(array $a, array $b): int => (int)$a['start_ts'] <=> (int)$b['start_ts']);
        $event = $segments[0];
        $event['start_ts'] = min(array_map(static fn(array $row): int => (int)$row['start_ts'], $segments));
        $event['end_ts'] = max(array_map(static fn(array $row): int => (int)$row['end_ts'], $segments));
        return $event;
    }

    private function requireInstrumentCode(array $input): string
    {
        $code = $this->requireString($input, 'instrumentCode', 64);
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $code)) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The instrument code is invalid.', 400);
        }
        return $code;
    }

    private function requireInstrument(PDO $pdo, string $code): array
    {
        $instrument = $this->findInstrument($pdo, $code);
        if ($instrument === null) {
            throw new InstrumentBookingException('INSTRUMENT_NOT_FOUND', 'The instrument was not found.', 404);
        }
        return $instrument;
    }

    private function findInstrument(PDO $pdo, string $code): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM instruments WHERE code = :code');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function instrumentForResponse(array $instrument): array
    {
        $maxMinutes = (int)$instrument['max_booking_minutes'];
        $weeklyMinutes = (int)$instrument['weekly_quota_minutes'];
        return [
            'code' => (string)$instrument['code'],
            'name' => (string)$instrument['name'],
            'description' => (string)$instrument['description'],
            'minMinutes' => 30,
            'maxBookingHours' => intdiv($maxMinutes, 60),
            'weeklyQuotaHours' => intdiv($weeklyMinutes, 60),
            'maxMinutes' => $maxMinutes,
            'weeklyQuotaMinutes' => $weeklyMinutes,
        ];
    }

    private function validatedInstrumentInput(array $input): array
    {
        $name = $this->cleanText($this->requireString($input, 'name', 120), 120);
        $description = $this->cleanText($this->optionalString($input, 'description', 1000), 1000, true);
        $maxHours = $this->requireHourRule($input, 'maxBookingHours', false);
        $weeklyHours = $this->requireHourRule($input, 'weeklyQuotaHours', false);
        if ($weeklyHours < $maxHours) {
            throw new InstrumentBookingException(
                'INVALID_INPUT',
                'The weekly limit must be at least the maximum booking duration.',
                400
            );
        }
        return [
            'name' => $name,
            'description' => $description,
            'max_booking_minutes' => $maxHours * 60,
            'weekly_quota_minutes' => $weeklyHours * 60,
        ];
    }

    private function requireHourRule(array $input, string $key, bool $allowZero): int
    {
        if (!array_key_exists($key, $input) || filter_var($input[$key], FILTER_VALIDATE_INT) === false) {
            throw new InstrumentBookingException('INVALID_INPUT', 'Time limits must be whole hours.', 400);
        }
        $value = (int)$input[$key];
        if ($allowZero && $value === 0) {
            return 0;
        }
        if ($value < 1 || $value > 168) {
            throw new InstrumentBookingException(
                'INVALID_INPUT',
                'Time limits must be whole hours between 1 and 168.',
                400
            );
        }
        return $value;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }

    private function optionalEventType(array $input): string
    {
        $eventType = isset($input['eventType']) ? (string)$input['eventType'] : 'booking';
        if (!in_array($eventType, ['booking', 'block'], true)) {
            throw new InstrumentBookingException('INVALID_INPUT', 'The event type is invalid.', 400);
        }
        return $eventType;
    }

    private function requireString(array $input, string $key, int $maxLength): string
    {
        if (!array_key_exists($key, $input) || !is_string($input[$key])) {
            throw new InstrumentBookingException('INVALID_INPUT', 'A request field has an invalid format.', 400);
        }
        $value = trim($input[$key]);
        if ($value === '' || $this->textLength($value) > $maxLength) {
            throw new InstrumentBookingException('INVALID_INPUT', 'A request field has an invalid length.', 400);
        }
        return $value;
    }

    private function optionalString(array $input, string $key, int $maxLength): string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return '';
        }
        if (!is_string($input[$key])) {
            throw new InstrumentBookingException('INVALID_INPUT', 'A request field has an invalid format.', 400);
        }
        $value = trim($input[$key]);
        if ($this->textLength($value) > $maxLength) {
            throw new InstrumentBookingException('INVALID_INPUT', 'A request field has an invalid length.', 400);
        }
        return $value;
    }

    private function requirePositiveInt(array $input, string $key): int
    {
        if (!array_key_exists($key, $input) || filter_var($input[$key], FILTER_VALIDATE_INT) === false) {
            throw new InstrumentBookingException('INVALID_INPUT', 'A request field has an invalid format.', 400);
        }
        $value = (int)$input[$key];
        if ($value < 1) {
            throw new InstrumentBookingException('INVALID_INPUT', 'A request field has an invalid format.', 400);
        }
        return $value;
    }

    private function cleanText(string $value, int $maxLength, bool $allowEmpty = false): string
    {
        $value = trim(strip_tags($value));
        if ((!$allowEmpty && $value === '') || $this->textLength($value) > $maxLength) {
            throw new InstrumentBookingException('INVALID_INPUT', 'A request field has an invalid length.', 400);
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
                throw new InstrumentBookingException('DATABASE_BUSY', 'The booking database is busy. Please try again later.', 503);
            }
            throw $e;
        }
    }

    private function commitImmediate(PDO $pdo): void
    {
        $pdo->exec('COMMIT');
    }

    private function rollBackQuietly(PDO $pdo): void
    {
        try {
            $pdo->exec('ROLLBACK');
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

    private function findByRequestId(PDO $pdo, string $requestId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM events WHERE request_id = :request_id ORDER BY start_ts, id');
        $stmt->execute([':request_id' => $requestId]);
        return $stmt->fetchAll();
    }

    private function findByBookingGroupId(PDO $pdo, string $bookingGroupId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM events WHERE booking_group_id = :booking_group_id ORDER BY start_ts, id'
        );
        $stmt->execute([':booking_group_id' => $bookingGroupId]);
        return $stmt->fetchAll();
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

    private function isUniqueConstraintException(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'unique constraint failed');
    }
}
