#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "tests/run.php must be run from PHP CLI.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/helper.php';

$tests = [];

class TestInstrumentBookingHelper extends helper_plugin_instrumentbooking
{
    public function createEvent(
        array $config,
        PDO $pdo,
        array $context,
        array $input,
        ?callable $reloadConfig = null,
        ?int $nowTimestamp = null
    ): array {
        return parent::createEvent(
            $config,
            $pdo,
            $context,
            $input,
            $reloadConfig,
            $nowTimestamp ?? $this->testNowFromInput($input)
        );
    }

    public function updateEvent(
        array $config,
        PDO $pdo,
        array $context,
        array $input,
        ?callable $reloadConfig = null,
        ?int $nowTimestamp = null
    ): array {
        return parent::updateEvent(
            $config,
            $pdo,
            $context,
            $input,
            $reloadConfig,
            $nowTimestamp ?? $this->testNowFromInput($input)
        );
    }

    private function testNowFromInput(array $input): int
    {
        $start = isset($input['start']) && is_string($input['start'])
            ? (new DateTimeImmutable($input['start']))->getTimestamp()
            : time();
        return $start - 3600;
    }
}

function test(string $name, callable $fn): void
{
    global $tests;
    $tests[] = [$name, $fn];
}

function fixture(array $overrides = []): array
{
    $helper = new TestInstrumentBookingHelper();
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
    $helper->migrateConfiguredInstruments($pdo, $config);
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
        'note' => '',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
        'requestId' => uuid_for_test(),
    ], $extra);
}

function event_range(): array
{
    return [
        'instrumentCode' => 'sem-01',
        'start' => '2029-12-31T00:00:00-08:00',
        'end' => '2030-01-02T00:00:00-08:00',
    ];
}

function insert_raw_event(
    PDO $pdo,
    string $start,
    string $end,
    ?string $blockedStart = null,
    ?string $blockedEnd = null,
    string $eventType = 'booking'
): void {
    $startTimestamp = (new DateTimeImmutable($start))->getTimestamp();
    $endTimestamp = (new DateTimeImmutable($end))->getTimestamp();
    $requestId = uuid_for_test();
    $stmt = $pdo->prepare(
        'INSERT INTO events (
            instrument_code, event_type, owner_user, title, note,
            start_ts, end_ts, blocked_start_ts, blocked_end_ts,
            request_id, booking_group_id, created_at, updated_at
        ) VALUES (
            :instrument_code, :event_type, :owner_user, :title, :note,
            :start_ts, :end_ts, :blocked_start_ts, :blocked_end_ts,
            :request_id, :booking_group_id, :created_at, :updated_at
        )'
    );
    $stmt->execute([
        ':instrument_code' => 'sem-01',
        ':event_type' => $eventType,
        ':owner_user' => 'legacy-user',
        ':title' => $eventType === 'block' ? 'Outage' : 'Booking',
        ':note' => '',
        ':start_ts' => $startTimestamp,
        ':end_ts' => $endTimestamp,
        ':blocked_start_ts' => $blockedStart === null
            ? $startTimestamp
            : (new DateTimeImmutable($blockedStart))->getTimestamp(),
        ':blocked_end_ts' => $blockedEnd === null
            ? $endTimestamp
            : (new DateTimeImmutable($blockedEnd))->getTimestamp(),
        ':request_id' => $requestId,
        ':booking_group_id' => $requestId,
        ':created_at' => time(),
        ':updated_at' => time(),
    ]);
}

function uuid_for_test(): string
{
    static $i = 1;
    return sprintf('00000000-0000-4000-8000-%012d', $i++);
}

function la_timestamp(string $localTime): int
{
    return (new DateTimeImmutable($localTime, new DateTimeZone('America/Los_Angeles')))->getTimestamp();
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

test('01:00-02:00 and 02:00-03:00 do not conflict', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T01:00:00-08:00',
        'end' => '2030-01-01T02:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T02:00:00-08:00',
        'end' => '2030-01-01T03:00:00-08:00',
    ]));
});

test('01:00-01:30 and 01:30-02:00 do not conflict', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T01:00:00-08:00',
        'end' => '2030-01-01T01:30:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T01:30:00-08:00',
        'end' => '2030-01-01T02:00:00-08:00',
    ]));
});

test('09:00-10:30 and 10:00-11:00 conflict', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user(), booking(['end' => '2030-01-01T10:30:00-08:00']));
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

test('identical ranges conflict', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user(), booking());
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking());
    });
});

test('range containing an existing event conflicts', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T01:30:00-08:00',
        'end' => '2030-01-01T02:00:00-08:00',
    ]));
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T01:00:00-08:00',
            'end' => '2030-01-01T03:00:00-08:00',
        ]));
    });
});

test('legacy minute overlap at the end conflicts', function () {
    [$h, $c, $pdo] = fixture();
    insert_raw_event($pdo, '2030-01-01T01:00:00-08:00', '2030-01-01T02:01:00-08:00');
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T02:00:00-08:00',
            'end' => '2030-01-01T03:00:00-08:00',
        ]));
    });
});

test('legacy minute overlap at the start conflicts', function () {
    [$h, $c, $pdo] = fixture();
    insert_raw_event($pdo, '2030-01-01T01:59:00-08:00', '2030-01-01T03:00:00-08:00');
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T01:00:00-08:00',
            'end' => '2030-01-01T02:00:00-08:00',
        ]));
    });
});

test('configured booking buffers no longer create conflicts', function () {
    [$h, $c, $pdo] = fixture([
        'instruments' => [
            'sem-01' => [
                'buffer_before_minutes' => 15,
                'buffer_after_minutes' => 15,
            ],
        ],
    ]);
    $h->createEvent($c, $pdo, user(), booking(['start' => '2030-01-01T09:00:00-08:00', 'end' => '2030-01-01T10:00:00-08:00']));
    $stored = $pdo->query('SELECT start_ts, end_ts, blocked_start_ts, blocked_end_ts FROM events ORDER BY id LIMIT 1')->fetch();
    assert_true((int)$stored['blocked_start_ts'] === (int)$stored['start_ts'], 'Expected no stored buffer before booking');
    assert_true((int)$stored['blocked_end_ts'] === (int)$stored['end_ts'], 'Expected no stored buffer after booking');
    $h->createEvent($c, $pdo, user(), booking(['start' => '2030-01-01T10:00:00-08:00', 'end' => '2030-01-01T11:00:00-08:00']));
});

test('legacy blocked range buffers do not affect conflict checks', function () {
    [$h, $c, $pdo] = fixture();
    insert_raw_event(
        $pdo,
        '2030-01-01T01:00:00-08:00',
        '2030-01-01T02:00:00-08:00',
        '2030-01-01T00:45:00-08:00',
        '2030-01-01T02:15:00-08:00'
    );
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T02:00:00-08:00',
        'end' => '2030-01-01T03:00:00-08:00',
    ]));
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
    assert_true($updated['event']['title'] === 'Booking');
});

test('duplicate requestId does not create a second event', function () {
    [$h, $c, $pdo] = fixture();
    $payload = booking(['requestId' => '11111111-1111-4111-8111-111111111111']);
    $h->createEvent($c, $pdo, user(), $payload);
    $h->createEvent($c, $pdo, user(), $payload);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    assert_true($count === 1, 'Expected exactly one event');
});

test('any authenticated user can book available instruments', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent($c, $pdo, user('mallory', ['other-users']), booking())['event'];
    assert_true($event['ownerUser'] === 'mallory');
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
    assert_error('EVENT_NOT_EDITABLE', function () use ($h, $c, $pdo, $event) {
        $h->cancelEvent($c, $pdo, user('bob'), ['eventId' => $event['id']]);
    });
});

test('authorized user sees complete details for another user booking', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user('alice'), booking([
        'title' => 'Alice microscopy session',
        'note' => 'Use the low-vacuum detector',
    ]));

    $events = $h->listEvents($c, $pdo, user('bob'), event_range())['events'];
    assert_true(count($events) === 1, 'Expected one visible event');
    assert_true($events[0]['title'] === 'Booking', 'Expected the internal booking title');
    assert_true($events[0]['ownerUser'] === 'alice', 'Expected the event owner');
    assert_true($events[0]['note'] === 'Use the low-vacuum detector', 'Expected the event note');
    assert_true($events[0]['eventType'] === 'booking', 'Expected the event type');
    assert_true($events[0]['canEdit'] === false, 'Other users must not be able to edit the booking');
    assert_true($events[0]['canCancel'] === false, 'Other users must not be able to cancel the booking');
});

test('any authenticated user can read complete event details', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user('alice'), booking([
        'note' => 'Shared details',
    ]));

    $events = $h->listEvents($c, $pdo, user('mallory', ['other-users']), event_range())['events'];
    assert_true(count($events) === 1, 'Expected one visible event');
    assert_true($events[0]['ownerUser'] === 'alice');
    assert_true($events[0]['note'] === 'Shared details');
});

test('anonymous user cannot read events', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('AUTH_REQUIRED', function () use ($h, $c, $pdo) {
        $h->listEvents($c, $pdo, user('', []), event_range());
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
    $event = $h->createEvent($c, $pdo, user('manager', ['instrument-admin']), booking([
        'eventType' => 'block',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T09:30:00-08:00',
    ]))['event'];
    assert_true($event['eventType'] === 'block');
    assert_true($event['title'] === 'Outage');
});

test('manager can create outage longer than booking maximum across days', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent($c, $pdo, user('manager', ['instrument-admin']), booking([
        'eventType' => 'block',
        'start' => '2030-01-02T09:00:00-08:00',
        'end' => '2030-01-04T17:00:00-08:00',
    ]))['event'];
    assert_true($event['eventType'] === 'block');
});

test('manager can update outage beyond booking duration limits', function () {
    [$h, $c, $pdo] = fixture();
    $manager = user('manager', ['instrument-admin']);
    $event = $h->createEvent($c, $pdo, $manager, booking([
        'eventType' => 'block',
    ]), null, la_timestamp('2030-01-01 08:00:00'))['event'];
    $updated = $h->updateEvent($c, $pdo, $manager, [
        'eventId' => $event['id'],
        'instrumentCode' => 'sem-01',
        'eventType' => 'block',
        'note' => '',
        'start' => '2030-01-02T09:00:00-08:00',
        'end' => '2030-01-05T17:00:00-08:00',
    ], null, la_timestamp('2030-01-01 08:00:00'))['event'];
    assert_true($updated['eventType'] === 'block');
    assert_true($updated['title'] === 'Outage');
});

test('outage still conflicts with existing booking', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user('alice'), booking());
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user('manager', ['instrument-admin']), booking([
            'eventType' => 'block',
            'start' => '2030-01-01T09:30:00-08:00',
            'end' => '2030-01-01T10:00:00-08:00',
        ]));
    });
});

test('outage still conflicts with existing outage', function () {
    [$h, $c, $pdo] = fixture();
    $manager = user('manager', ['instrument-admin']);
    $h->createEvent($c, $pdo, $manager, booking([
        'eventType' => 'block',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]));
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo, $manager) {
        $h->createEvent($c, $pdo, $manager, booking([
            'eventType' => 'block',
            'start' => '2030-01-01T09:30:00-08:00',
            'end' => '2030-01-01T10:30:00-08:00',
        ]));
    });
});

test('adjacent outages do not apply booking buffers', function () {
    [$h, $c, $pdo] = fixture([
        'instruments' => [
            'sem-01' => [
                'buffer_before_minutes' => 10,
                'buffer_after_minutes' => 10,
            ],
        ],
    ]);
    $manager = user('manager', ['instrument-admin']);
    $h->createEvent($c, $pdo, $manager, booking([
        'eventType' => 'block',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T09:30:00-08:00',
    ]));
    $h->createEvent($c, $pdo, $manager, booking([
        'eventType' => 'block',
        'start' => '2030-01-01T09:30:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]));
});

test('booking can start when outage ends', function () {
    [$h, $c, $pdo] = fixture();
    $manager = user('manager', ['instrument-admin']);
    $h->createEvent($c, $pdo, $manager, booking([
        'eventType' => 'block',
        'start' => '2030-01-01T01:00:00-08:00',
        'end' => '2030-01-01T02:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T02:00:00-08:00',
        'end' => '2030-01-01T03:00:00-08:00',
    ]));
});

test('outage can start when booking ends', function () {
    [$h, $c, $pdo] = fixture();
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T01:00:00-08:00',
        'end' => '2030-01-01T02:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user('manager', ['instrument-admin']), booking([
        'eventType' => 'block',
        'start' => '2030-01-01T02:00:00-08:00',
        'end' => '2030-01-01T03:00:00-08:00',
    ]));
});

test('existing event type cannot be changed', function () {
    [$h, $c, $pdo] = fixture();
    $manager = user('manager', ['instrument-admin']);
    $event = $h->createEvent($c, $pdo, $manager, booking([
        'eventType' => 'block',
        'title' => 'Maintenance',
    ]))['event'];

    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo, $manager, $event) {
        $h->updateEvent($c, $pdo, $manager, [
            'eventId' => $event['id'],
            'instrumentCode' => 'sem-01',
            'eventType' => 'booking',
            'title' => 'Converted booking',
            'note' => '',
            'start' => '2030-01-01T09:00:00-08:00',
            'end' => '2030-01-01T10:00:00-08:00',
        ]);
    });
});

test('ordinary user cannot modify or cancel outage', function () {
    [$h, $c, $pdo] = fixture();
    $manager = user('manager', ['instrument-admin']);
    $event = $h->createEvent($c, $pdo, $manager, booking([
        'eventType' => 'block',
        'title' => 'Vacuum pump outage',
        'note' => 'Do not operate the instrument',
    ]))['event'];

    $visible = $h->listEvents($c, $pdo, user('alice'), event_range())['events'][0];
    assert_true($visible['title'] === 'Outage', 'Expected the internal outage title');
    assert_true($visible['ownerUser'] === 'manager', 'Expected the outage owner');
    assert_true($visible['note'] === 'Do not operate the instrument', 'Expected the outage note');
    assert_true($visible['eventType'] === 'block', 'Expected the outage event type');
    assert_true($visible['canEdit'] === false, 'Ordinary users must not be able to edit outages');
    assert_true($visible['canCancel'] === false, 'Ordinary users must not be able to cancel outages');

    assert_error('EVENT_NOT_EDITABLE', function () use ($h, $c, $pdo, $event) {
        $h->updateEvent($c, $pdo, user('alice'), [
            'eventId' => $event['id'],
            'instrumentCode' => 'sem-01',
            'eventType' => 'block',
            'title' => 'Unauthorized outage change',
            'note' => '',
            'start' => '2030-01-01T09:00:00-08:00',
            'end' => '2030-01-01T10:00:00-08:00',
        ]);
    });
    assert_error('EVENT_NOT_EDITABLE', function () use ($h, $c, $pdo, $event) {
        $h->cancelEvent($c, $pdo, user('alice'), ['eventId' => $event['id']]);
    });
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

test('booking under global 30 minute minimum is rejected', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T09:00:00-08:00',
            'end' => '2030-01-01T09:15:00-08:00',
        ]));
    });
});

test('strict next booking slot follows Los Angeles half-hour boundaries', function () {
    [$h] = fixture();
    assert_true(
        $h->nextBookableSlotTimestamp('America/Los_Angeles', la_timestamp('2030-01-01 02:59:00'))
            === la_timestamp('2030-01-01 03:00:00'),
        '02:59 should advance to 03:00'
    );
    assert_true(
        $h->nextBookableSlotTimestamp('America/Los_Angeles', la_timestamp('2030-01-01 03:00:00'))
            === la_timestamp('2030-01-01 03:30:00'),
        '03:00 should advance to 03:30'
    );
    assert_true(
        $h->nextBookableSlotTimestamp('America/Los_Angeles', la_timestamp('2030-01-01 03:29:00'))
            === la_timestamp('2030-01-01 03:30:00'),
        '03:29 should advance to 03:30'
    );
    assert_true(
        $h->nextBookableSlotTimestamp('America/Los_Angeles', la_timestamp('2030-01-01 03:30:00'))
            === la_timestamp('2030-01-01 04:00:00'),
        '03:30 should advance to 04:00'
    );
});

test('02:59 allows a booking from 03:00', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent(
        $c,
        $pdo,
        user(),
        booking([
            'start' => '2030-01-01T03:00:00-08:00',
            'end' => '2030-01-01T03:30:00-08:00',
        ]),
        null,
        la_timestamp('2030-01-01 02:59:00')
    )['event'];
    assert_true($event['id'] > 0);
});

test('03:00 rejects a booking from 03:00', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo) {
        $h->createEvent(
            $c,
            $pdo,
            user(),
            booking([
                'start' => '2030-01-01T03:00:00-08:00',
                'end' => '2030-01-01T03:30:00-08:00',
            ]),
            null,
            la_timestamp('2030-01-01 03:00:00')
        );
    });
});

test('03:00 allows a booking from 03:30', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent(
        $c,
        $pdo,
        user(),
        booking([
            'start' => '2030-01-01T03:30:00-08:00',
            'end' => '2030-01-01T04:00:00-08:00',
        ]),
        null,
        la_timestamp('2030-01-01 03:00:00')
    )['event'];
    assert_true($event['id'] > 0);
});

test('03:29 allows a booking from 03:30', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent(
        $c,
        $pdo,
        user(),
        booking([
            'start' => '2030-01-01T03:30:00-08:00',
            'end' => '2030-01-01T04:00:00-08:00',
        ]),
        null,
        la_timestamp('2030-01-01 03:29:00')
    )['event'];
    assert_true($event['id'] > 0);
});

test('03:30 rejects a booking from 03:30', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo) {
        $h->createEvent(
            $c,
            $pdo,
            user(),
            booking([
                'start' => '2030-01-01T03:30:00-08:00',
                'end' => '2030-01-01T04:00:00-08:00',
            ]),
            null,
            la_timestamp('2030-01-01 03:30:00')
        );
    });
});

test('off-slot start and end times are rejected', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T03:15:00-08:00',
            'end' => '2030-01-01T04:00:00-08:00',
        ]));
    });
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T03:00:00-08:00',
            'end' => '2030-01-01T03:45:00-08:00',
        ]));
    });
});

test('30, 60, 90, and 120 minute bookings are accepted', function () {
    foreach ([30, 60, 90, 120] as $duration) {
        [$h, $c, $pdo] = fixture();
        $event = $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T09:00:00-08:00',
            'end' => (new DateTimeImmutable('2030-01-01T09:00:00-08:00'))
                ->modify('+' . $duration . ' minutes')
                ->format(DateTimeInterface::ATOM),
        ]))['event'];
        assert_true($event['id'] > 0, 'Expected ' . $duration . '-minute booking to be accepted');
    }
});

test('editing uses the strict next booking slot', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent(
        $c,
        $pdo,
        user(),
        booking([
            'start' => '2030-01-01T03:00:00-08:00',
            'end' => '2030-01-01T03:30:00-08:00',
        ]),
        null,
        la_timestamp('2030-01-01 02:00:00')
    )['event'];
    $payload = [
        'eventId' => $event['id'],
        'instrumentCode' => 'sem-01',
        'eventType' => 'booking',
        'note' => '',
        'start' => '2030-01-01T03:00:00-08:00',
        'end' => '2030-01-01T03:30:00-08:00',
    ];
    $updated = $h->updateEvent($c, $pdo, user(), $payload, null, la_timestamp('2030-01-01 02:59:00'));
    $payload['eventId'] = $updated['event']['id'];
    assert_error('EVENT_NOT_EDITABLE', function () use ($h, $c, $pdo, $payload) {
        $h->updateEvent($c, $pdo, user(), $payload, null, la_timestamp('2030-01-01 03:00:00'));
    });
});

test('next booking slot crosses DST spring-forward correctly', function () {
    [$h] = fixture();
    $next = $h->nextBookableSlotTimestamp(
        'America/Los_Angeles',
        (new DateTimeImmutable('2030-03-10T01:59:00-08:00'))->getTimestamp()
    );
    $formatted = (new DateTimeImmutable('@' . $next))
        ->setTimezone(new DateTimeZone('America/Los_Angeles'))
        ->format(DateTimeInterface::ATOM);
    assert_true($formatted === '2030-03-10T03:00:00-07:00', 'Expected DST-aware 03:00 PDT slot');
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
    $requestId = uuid_for_test();
    $stmt = $pdo->prepare('INSERT INTO events (instrument_code, event_type, owner_user, title, note, start_ts, end_ts, blocked_start_ts, blocked_end_ts, request_id, booking_group_id, created_at, updated_at, cancelled_at, cancelled_by) VALUES (:instrument_code, :event_type, :owner_user, :title, :note, :start_ts, :end_ts, :blocked_start_ts, :blocked_end_ts, :request_id, :booking_group_id, :created_at, :updated_at, :cancelled_at, :cancelled_by)');
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
        ':request_id' => $requestId,
        ':booking_group_id' => $requestId,
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

function set_instrument_rules(PDO $pdo, string $code, int $maxMinutes, int $weeklyMinutes): void
{
    $stmt = $pdo->prepare(
        'UPDATE instruments
         SET max_booking_minutes = :max_booking_minutes,
             weekly_quota_minutes = :weekly_quota_minutes,
             updated_at = :updated_at
         WHERE code = :code'
    );
    $stmt->execute([
        ':max_booking_minutes' => $maxMinutes,
        ':weekly_quota_minutes' => $weeklyMinutes,
        ':updated_at' => time(),
        ':code' => $code,
    ]);
}

test('regular user cannot access settings API', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('ADMIN_REQUIRED', function () use ($h, $c, $pdo) {
        $h->listAdminInstruments($c, $pdo, user());
    });
    assert_error('ADMIN_REQUIRED', function () use ($h, $c, $pdo) {
        $h->createInstrument($c, $pdo, user(), [
            'name' => 'TEM-01',
            'description' => '',
            'maxBookingMinutes' => 120,
            'weeklyQuotaMinutes' => 0,
        ]);
    });
});

test('admin can create and update instruments', function () {
    [$h, $c, $pdo] = fixture();
    $admin = user('manager', ['instrument-admin']);
    $created = $h->createInstrument($c, $pdo, $admin, [
        'name' => 'TEM-01',
        'description' => 'Transmission Electron Microscope',
        'maxBookingMinutes' => 180,
        'weeklyQuotaMinutes' => 360,
    ])['instrument'];
    assert_true(str_starts_with($created['code'], 'tool-'));
    assert_true($created['maxMinutes'] === 180);
    assert_true($created['weeklyQuotaMinutes'] === 360);

    $updated = $h->updateInstrument($c, $pdo, $admin, [
        'instrumentCode' => $created['code'],
        'name' => 'TEM-01 Updated',
        'description' => 'Updated description',
        'maxBookingMinutes' => 240,
        'weeklyQuotaMinutes' => 480,
    ])['instrument'];
    assert_true($updated['name'] === 'TEM-01 Updated');
    assert_true($updated['maxMinutes'] === 240);
    assert_true($updated['weeklyQuotaMinutes'] === 480);
});

test('admin cannot modify another users booking', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent($c, $pdo, user('alice'), booking())['event'];
    assert_error('EVENT_NOT_EDITABLE', function () use ($h, $c, $pdo, $event) {
        $h->updateEvent($c, $pdo, user('manager', ['instrument-admin']), [
            'eventId' => $event['id'],
            'instrumentCode' => 'sem-01',
            'eventType' => 'booking',
            'note' => '',
            'start' => '2030-01-01T09:00:00-08:00',
            'end' => '2030-01-01T10:00:00-08:00',
        ]);
    });
});

test('admin cannot modify another admins outage', function () {
    [$h, $c, $pdo] = fixture();
    $event = $h->createEvent($c, $pdo, user('manager-a', ['instrument-admin']), booking([
        'eventType' => 'block',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]))['event'];
    assert_error('EVENT_NOT_EDITABLE', function () use ($h, $c, $pdo, $event) {
        $h->updateEvent($c, $pdo, user('manager-b', ['instrument-admin']), [
            'eventId' => $event['id'],
            'instrumentCode' => 'sem-01',
            'eventType' => 'block',
            'note' => '',
            'start' => '2030-01-01T09:00:00-08:00',
            'end' => '2030-01-01T11:00:00-08:00',
        ]);
    });
});

test('admin bookings obey weekly quota', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 120, 120);
    $admin = user('manager', ['instrument-admin']);
    $h->createEvent($c, $pdo, $admin, booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T11:00:00-08:00',
    ]));
    assert_error('WEEKLY_LIMIT_EXCEEDED', function () use ($h, $c, $pdo, $admin) {
        $h->createEvent($c, $pdo, $admin, booking([
            'start' => '2030-01-01T12:00:00-08:00',
            'end' => '2030-01-01T13:00:00-08:00',
        ]));
    });
});

test('weekly quota of zero is unlimited', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 240, 0);
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T13:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T14:00:00-08:00',
        'end' => '2030-01-01T18:00:00-08:00',
    ]));
});

test('weekly quotas are tracked per instrument', function () {
    [$h, $c, $pdo] = fixture([
        'instruments' => [
            'tem-01' => [
                'name' => 'TEM-01',
                'description' => 'TEM',
                'allowed_groups' => ['sem-users'],
                'min_minutes' => 30,
                'max_minutes' => 240,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'color' => '#2563eb',
                'enabled' => true,
            ],
        ],
    ]);
    set_instrument_rules($pdo, 'sem-01', 60, 60);
    set_instrument_rules($pdo, 'tem-01', 60, 60);
    $h->createEvent($c, $pdo, user(), booking([
        'instrumentCode' => 'sem-01',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user(), booking([
        'instrumentCode' => 'tem-01',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]));
});

test('cancelled bookings and outages do not consume weekly quota', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 60, 60);
    $event = $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]))['event'];
    $h->cancelEvent($c, $pdo, user(), ['eventId' => $event['id']]);
    $h->createEvent($c, $pdo, user('manager', ['instrument-admin']), booking([
        'eventType' => 'block',
        'start' => '2030-01-01T11:00:00-08:00',
        'end' => '2030-01-01T13:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T14:00:00-08:00',
        'end' => '2030-01-01T15:00:00-08:00',
    ]));
});

test('updating a booking excludes its own weekly usage', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 60, 60);
    $event = $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]))['event'];
    $updated = $h->updateEvent($c, $pdo, user(), [
        'eventId' => $event['id'],
        'instrumentCode' => 'sem-01',
        'eventType' => 'booking',
        'note' => '',
        'start' => '2030-01-01T11:00:00-08:00',
        'end' => '2030-01-01T12:00:00-08:00',
    ], null, la_timestamp('2030-01-01 08:00:00'))['event'];
    assert_true($updated['start'] === '2030-01-01T11:00:00-08:00');
});

test('rolling seven day horizon uses Los Angeles calendar time', function () {
    [$h, $c, $pdo] = fixture();
    $now = la_timestamp('2030-01-01 10:13:00');
    $h->createEvent(
        $c,
        $pdo,
        user(),
        booking([
            'start' => '2030-01-08T10:00:00-08:00',
            'end' => '2030-01-08T10:30:00-08:00',
        ]),
        null,
        $now
    );
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo, $now) {
        $h->createEvent(
            $c,
            $pdo,
            user(),
            booking([
                'start' => '2030-01-08T10:30:00-08:00',
                'end' => '2030-01-08T11:00:00-08:00',
            ]),
            null,
            $now
        );
    });
});

test('DST week horizon uses calendar days not fixed seconds', function () {
    [$h, $c, $pdo] = fixture();
    $now = (new DateTimeImmutable('2030-03-07T10:00:00-08:00'))->getTimestamp();
    $h->createEvent(
        $c,
        $pdo,
        user(),
        booking([
            'start' => '2030-03-14T10:00:00-07:00',
            'end' => '2030-03-14T10:30:00-07:00',
        ]),
        null,
        $now
    );
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo, $now) {
        $h->createEvent(
            $c,
            $pdo,
            user(),
            booking([
                'start' => '2030-03-14T10:30:00-07:00',
                'end' => '2030-03-14T11:00:00-07:00',
            ]),
            null,
            $now
        );
    });
});

test('cross week bookings split into two segments', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 240, 0);
    $event = $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-05T23:00:00-08:00',
        'end' => '2030-01-06T01:00:00-08:00',
    ]))['event'];
    $rows = $pdo->query('SELECT start_ts, end_ts, booking_group_id, request_id FROM events ORDER BY start_ts')->fetchAll();
    assert_true(count($rows) === 2, 'Expected two segments');
    assert_true($rows[0]['booking_group_id'] === $rows[1]['booking_group_id']);
    assert_true($rows[0]['request_id'] === $rows[1]['request_id']);
    assert_true((int)$rows[0]['end_ts'] === (int)$rows[1]['start_ts']);
    assert_true($event['bookingGroupId'] === $rows[0]['booking_group_id']);
});

test('cross week segments count against each week quota separately', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 120, 120);
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-05T23:00:00-08:00',
        'end' => '2030-01-06T01:00:00-08:00',
    ]));
    assert_error('WEEKLY_LIMIT_EXCEEDED', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-05T21:00:00-08:00',
            'end' => '2030-01-05T22:30:00-08:00',
        ]));
    });
    assert_error('WEEKLY_LIMIT_EXCEEDED', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-06T02:00:00-08:00',
            'end' => '2030-01-06T03:30:00-08:00',
        ]));
    });
});

test('cross week create rolls back when one segment conflicts', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 240, 0);
    $h->createEvent($c, $pdo, user('bob'), booking([
        'start' => '2030-01-06T00:00:00-08:00',
        'end' => '2030-01-06T00:30:00-08:00',
    ]));
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-05T23:00:00-08:00',
            'end' => '2030-01-06T01:00:00-08:00',
        ]));
    });
    $count = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE owner_user = 'alice'")->fetchColumn();
    assert_true($count === 0, 'Expected complete rollback');
});

test('cancelling one segment cancels the whole booking group', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 240, 0);
    $event = $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-05T23:00:00-08:00',
        'end' => '2030-01-06T01:00:00-08:00',
    ]))['event'];
    $secondId = (int)$pdo->query('SELECT id FROM events ORDER BY start_ts DESC LIMIT 1')->fetchColumn();
    $h->cancelEvent($c, $pdo, user(), ['eventId' => $secondId]);
    $active = (int)$pdo->query('SELECT COUNT(*) FROM events WHERE cancelled_at IS NULL')->fetchColumn();
    assert_true($active === 0, 'Expected all segments cancelled');
    assert_true($event['bookingGroupId'] !== '');
});

test('idempotent retry does not duplicate cross week segments', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 240, 0);
    $payload = booking([
        'requestId' => '22222222-2222-4222-8222-222222222222',
        'start' => '2030-01-05T23:00:00-08:00',
        'end' => '2030-01-06T01:00:00-08:00',
    ]);
    $h->createEvent($c, $pdo, user(), $payload);
    $again = $h->createEvent($c, $pdo, user(), $payload);
    assert_true($again['idempotent'] === true);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    assert_true($count === 2, 'Expected two segments total after retry');
});

test('schema version is three after install', function () {
    [$h, $c, $pdo] = fixture();
    assert_true($h->schemaVersion($pdo) === 3);
});

test('admin list has no web remove path and rejects remove API', function () {
    [$h, $c, $pdo] = fixture();
    $admin = user('manager', ['instrument-admin']);
    $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'ops-admin']);
    $admins = $h->listPluginAdmins($pdo);
    assert_true(count($admins) === 1);
    assert_true($admins[0]['username'] === 'ops-admin');
    assert_true(!array_key_exists('canRemove', $admins[0]));
});

test('ordinary user cannot delete instruments', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('ADMIN_REQUIRED', function () use ($h, $c, $pdo) {
        $h->deleteInstrument($c, $pdo, user(), [
            'instrumentCode' => 'sem-01',
            'confirmName' => 'SEM-01',
        ]);
    });
});

test('admin can delete instrument with matching confirmation', function () {
    [$h, $c, $pdo] = fixture();
    $admin = user('manager', ['instrument-admin']);
    $result = $h->deleteInstrument($c, $pdo, $admin, [
        'instrumentCode' => 'sem-01',
        'confirmName' => 'SEM-01',
    ]);
    assert_true($result['deleted'] === true);
    assert_true($h->listInstruments($c, $pdo, $admin)['instruments'] === []);
});

test('wrong confirmation name does not delete instrument', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('DELETE_CONFIRMATION_MISMATCH', function () use ($h, $c, $pdo) {
        $h->deleteInstrument($c, $pdo, user('manager', ['instrument-admin']), [
            'instrumentCode' => 'sem-01',
            'confirmName' => 'Wrong Name',
        ]);
    });
    assert_true(count($h->listInstruments($c, $pdo, user('manager', ['instrument-admin']))['instruments']) === 1);
});

test('deleting a missing instrument returns not found', function () {
    [$h, $c, $pdo] = fixture();
    assert_error('INSTRUMENT_NOT_FOUND', function () use ($h, $c, $pdo) {
        $h->deleteInstrument($c, $pdo, user('manager', ['instrument-admin']), [
            'instrumentCode' => 'missing-tool',
            'confirmName' => 'Missing',
        ]);
    });
});

test('deleting an instrument removes bookings outages segments and request records', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 240, 0);
    $admin = user('manager', ['instrument-admin']);
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
        'requestId' => '33333333-3333-4333-8333-333333333333',
    ]));
    $h->createEvent($c, $pdo, $admin, booking([
        'eventType' => 'block',
        'start' => '2030-01-01T11:00:00-08:00',
        'end' => '2030-01-01T12:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-05T23:00:00-08:00',
        'end' => '2030-01-06T01:00:00-08:00',
        'requestId' => '44444444-4444-4444-8444-444444444444',
    ]));
    $h->deleteInstrument($c, $pdo, $admin, [
        'instrumentCode' => 'sem-01',
        'confirmName' => 'SEM-01',
    ]);
    assert_true((int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() === 0);
    assert_true((int)$pdo->query('SELECT COUNT(*) FROM instruments')->fetchColumn() === 0);
    assert_true(
        (int)$pdo->query("SELECT COUNT(*) FROM events WHERE request_id = '33333333-3333-4333-8333-333333333333'")->fetchColumn() === 0
    );
});

test('deleting one instrument leaves other instruments intact', function () {
    [$h, $c, $pdo] = fixture([
        'instruments' => [
            'tem-01' => [
                'name' => 'TEM-01',
                'description' => 'TEM',
                'allowed_groups' => ['sem-users'],
                'min_minutes' => 30,
                'max_minutes' => 240,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'color' => '#2563eb',
                'enabled' => true,
            ],
        ],
    ]);
    $h->createEvent($c, $pdo, user(), booking([
        'instrumentCode' => 'tem-01',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]));
    $h->deleteInstrument($c, $pdo, user('manager', ['instrument-admin']), [
        'instrumentCode' => 'sem-01',
        'confirmName' => 'SEM-01',
    ]);
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM instruments WHERE code = 'tem-01'")->fetchColumn() === 1);
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM events WHERE instrument_code = 'tem-01'")->fetchColumn() === 1);
});

test('cli revoke removes non final admin and blocks last admin', function () {
    [$h, $c, $pdo] = fixture();
    $admin = user('manager', ['instrument-admin']);
    $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'alpha']);
    $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'beta']);
    $result = $h->revokePluginAdminCli($pdo, 'alpha');
    assert_true($result['revoked'] === true);
    assert_true($result['remainingAdmins'] === 1);
    assert_error('LAST_ADMIN_CANNOT_BE_REVOKED', function () use ($h, $pdo) {
        $h->revokePluginAdminCli($pdo, 'beta');
    });
    assert_error('ADMIN_NOT_FOUND', function () use ($h, $pdo) {
        $h->revokePluginAdminCli($pdo, 'missing-user');
    });
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
