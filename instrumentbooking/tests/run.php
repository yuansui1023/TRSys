#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "tests/run.php must be run from PHP CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/helper.php';

$tests = [];

function test(string $name, callable $fn): void
{
    global $tests;
    $tests[] = [$name, $fn];
}

function fixture(array $overrides = []): array
{
    $helper = new helper_plugin_instrumentbooking();
    $db = tempnam(sys_get_temp_dir(), 'ib-test-');
    if ($db === false) {
        throw new RuntimeException('Unable to create temporary database path.');
    }
    unlink($db);
    $config = array_replace_recursive([
        'database_path' => $db,
        'timezone' => 'America/Los_Angeles',
        'manager_groups' => ['instrument-admin'],
        'cancelled_retention_days' => 180,
        'history_retention_days' => 730,
        'instruments' => [
            'sem-01' => [
                'name' => 'SEM-01',
                'description' => 'Scanning Electron Microscope',
                'allowed_groups' => ['sem-users', 'instrument-admin'],
                'min_minutes' => 30,
                'max_minutes' => 240,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'color' => '#2563eb',
                'enabled' => true,
            ],
        ],
    ], $overrides);
    $config = $helper->validateConfig($config);
    $pdo = $helper->connect($config, true);
    $helper->applySchema($pdo, dirname(__DIR__) . '/db/schema.sql');
    return [$helper, $config, $pdo, $db];
}

function user(string $name = 'alice', array $groups = ['sem-users'], bool $admin = false): array
{
    return ['user' => $name, 'groups' => $groups, 'isSuperuser' => $admin];
}

function booking(array $extra = []): array
{
    return array_replace([
        'instrumentCode' => 'sem-01',
        'eventType' => 'booking',
        'title' => 'Test booking',
        'note' => '',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
        'requestId' => uuid_for_test(),
    ], $extra);
}

function uuid_for_test(): string
{
    static $i = 1;
    return sprintf('00000000-0000-4000-8000-%012d', $i++);
}

function assert_true(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_error(string $code, callable $fn): void
{
    try {
        $fn();
    } catch (InstrumentBookingException $e) {
        assert_true($e->errorCode() === $code, 'Expected ' . $code . ', got ' . $e->errorCode());
        return;
    }
    throw new RuntimeException('Expected error ' . $code);
}

test('09:00-10:00 and 10:00-11:00 do not conflict', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user(), booking());
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T10:00:00-08:00',
        'end' => '2030-01-01T11:00:00-08:00',
    ]));
});

test('09:00-10:01 and 10:00-11:00 conflict', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user(), booking(['end' => '2030-01-01T10:01:00-08:00']));
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T10:00:00-08:00',
            'end' => '2030-01-01T11:00:00-08:00',
        ]));
    });
});

test('contained range conflicts', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user(), booking(['start' => '2030-01-01T09:00:00-08:00', 'end' => '2030-01-01T12:00:00-08:00']));
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking(['start' => '2030-01-01T10:00:00-08:00', 'end' => '2030-01-01T11:00:00-08:00']));
    });
});

test('buffer overlap conflicts', function () {
    [$h, $c, $pdo] = fixture(['instruments' => ['sem-01' => ['buffer_after_minutes' => 10]]]);
    $h->createEvent($c, $pdo, user(), booking(['start' => '2030-01-01T09:00:00-08:00', 'end' => '2030-01-01T10:00:00-08:00']));
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking(['start' => '2030-01-01T10:05:00-08:00', 'end' => '2030-01-01T11:05:00-08:00']));
    });
});

test('cancelled events no longer block booking', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent($c, $pdo, user(), booking())['event'];
    $h->cancelEvent($c, $pdo, user(), ['eventId' => $event['id']]);
    $h->createEvent($c, $pdo, user(), booking());
});

test('update excludes current event from conflict check', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent($c, $pdo, user(), booking())['event'];
    $updated = $h->updateEvent($c, $pdo, user(), [
        'eventId' => $event['id'],
        'instrumentCode' => 'sem-01',
        'eventType' => 'booking',
        'title' => 'Updated',
        'note' => '',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]);
    assert_true($updated['event']['title'] === 'Updated');
});

test('duplicate requestId does not create a second event', function () {
    [$h, $c, $pdo] = fixture();
    $payload = booking(['requestId' => '11111111-1111-4111-8111-111111111111']);
    $h->createEvent($c, $pdo, user(), $payload);
    $h->createEvent($c, $pdo, user(), $payload);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    assert_true($count === 1, 'Expected exactly one event');
});

test('unauthorized user cannot book', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('PERMISSION_DENIED', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user('mallory', ['other-users']), booking());
    });
});

test('ordinary user cannot modify another user booking', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent($c, $pdo, user('alice'), booking())['event'];
    assert_error('EVENT_NOT_EDITABLE', function () use ($h, $c, $pdo, $event) {
        $h->updateEvent($c, $pdo, user('bob'), [
            'eventId' => $event['id'],
            'instrumentCode' => 'sem-01',
            'eventType' => 'booking',
            'title' => 'Bad update',
            'note' => '',
            'start' => '2030-01-01T09:00:00-08:00',
            'end' => '2030-01-01T10:00:00-08:00',
        ]);
    });
});

test('ordinary user cannot create block', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('PERMISSION_DENIED', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking(['eventType' => 'block']));
    });
});

test('manager can create block', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent($c, $pdo, user('manager', ['instrument-admin']), booking(['eventType' => 'block', 'title' => 'Maintenance']))['event'];
    assert_true($event['eventType'] === 'block');
});

test('end before start is rejected', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking(['start' => '2030-01-01T10:00:00-08:00', 'end' => '2030-01-01T09:00:00-08:00']));
    });
});

test('booking over max duration is rejected', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking(['start' => '2030-01-01T09:00:00-08:00', 'end' => '2030-01-01T14:00:00-08:00']));
    });
});

test('booking under min duration is rejected', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking(['start' => '2030-01-01T09:00:00-08:00', 'end' => '2030-01-01T09:15:00-08:00']));
    });
});

test('DST transition with explicit offsets is accepted', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-03-10T01:30:00-08:00',
        'end' => '2030-03-10T03:30:00-07:00',
    ]))['event'];
    assert_true($event['id'] > 0);
});

test('cleanup dry-run does not delete rows', function () {
    [$h, $c, $pdo] = fixture();
    $old = time() - 900 * 86400;
    $stmt = $pdo->prepare('INSERT INTO events (instrument_code, event_type, owner_user, title, note, start_ts, end_ts, blocked_start_ts, blocked_end_ts, request_id, created_at, updated_at, cancelled_at, cancelled_by) VALUES (:instrument_code, :event_type, :owner_user, :title, :note, :start_ts, :end_ts, :blocked_start_ts, :blocked_end_ts, :request_id, :created_at, :updated_at, :cancelled_at, :cancelled_by)');
    $stmt->execute([
        ':instrument_code' => 'sem-01',
        ':event_type' => 'booking',
        ':owner_user' => 'alice',
        ':title' => 'Old cancelled',
        ':note' => '',
        ':start_ts' => $old - 3600,
        ':end_ts' => $old,
        ':blocked_start_ts' => $old - 3600,
        ':blocked_end_ts' => $old,
        ':request_id' => uuid_for_test(),
        ':created_at' => $old,
        ':updated_at' => $old,
        ':cancelled_at' => $old,
        ':cancelled_by' => 'alice',
    ]);
    $before = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $result = $h->cleanup($pdo, $c, true);
    $after = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    assert_true($result['cancelledChecked'] === 1);
    assert_true($before === $after, 'Dry run changed row count');
});

test('second SQLite writer gets a handled busy condition', function () {
    [$h, $c, $pdo1, $db] = fixture();
    $pdo2 = $h->connect($c);
    $pdo2->exec('PRAGMA busy_timeout = 100');
    $pdo1->exec('BEGIN IMMEDIATE');
    try {
        assert_error('DATABASE_BUSY', function () use ($h, $c, $pdo2) {
            $h->createEvent($c, $pdo2, user(), booking());
        });
    } finally {
        $pdo1->rollBack();
    }
});

$failures = 0;
foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo "[PASS] " . $name . "\n";
    } catch (Throwable $e) {
        $failures++;
        echo "[FAIL] " . $name . ": " . $e->getMessage() . "\n";
    }
}

echo count($tests) . " tests, " . $failures . " failures\n";
exit($failures === 0 ? 0 : 1);
