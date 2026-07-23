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
    /** @var array<string, bool|string>|null */
    public ?array $knownUsers = null;

    /** @var bool|null */
    public ?bool $userEnumerationSupported = null;

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

    public function dokuWikiUserExists(string $username): bool
    {
        if ($this->knownUsers !== null) {
            return $this->lookupKnownUser($username) !== null;
        }
        return parent::dokuWikiUserExists($username);
    }

    public function dokuWikiCanListUsers(): bool
    {
        if ($this->userEnumerationSupported !== null) {
            return $this->userEnumerationSupported;
        }
        if ($this->knownUsers !== null) {
            return true;
        }
        return parent::dokuWikiCanListUsers();
    }

    public function retrieveDokuWikiUsers(): array
    {
        if ($this->knownUsers !== null) {
            $users = [];
            foreach ($this->knownUsers as $username => $value) {
                if ($value === false) {
                    continue;
                }
                $users[(string)$username] = is_string($value) ? $value : '';
            }
            return $users;
        }
        return parent::retrieveDokuWikiUsers();
    }

    public function dokuWikiDisplayName(string $username): string
    {
        if ($this->knownUsers !== null) {
            $value = $this->lookupKnownUser($username);
            return is_string($value) ? $value : '';
        }
        return parent::dokuWikiDisplayName($username);
    }

    private function lookupKnownUser(string $username): bool|string|null
    {
        $key = strtolower($username);
        foreach ($this->knownUsers ?? [] as $name => $value) {
            if (strtolower((string)$name) === $key) {
                return $value === false ? null : $value;
            }
        }
        return null;
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

function grant_plugin_admin(PDO $pdo, string $username, int $now = 1): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO plugin_admins (username, added_at, added_by)
         VALUES (:username, :added_at, :added_by)'
    );
    $stmt->execute([
        ':username' => $username,
        ':added_at' => $now,
        ':added_by' => 'test',
    ]);
}

function plugin_admin(string $name = 'manager'): array
{
    return user($name, []);
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
    grant_plugin_admin($pdo, 'manager');
    $event = $h->createEvent($c, $pdo, plugin_admin('manager'), booking([
        'eventType' => 'block',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T09:30:00-08:00',
    ]))['event'];
    assert_true($event['eventType'] === 'block');
    assert_true($event['title'] === 'Outage');
});

test('manager can create outage longer than booking maximum across days', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $event = $h->createEvent($c, $pdo, plugin_admin('manager'), booking([
        'eventType' => 'block',
        'start' => '2030-01-02T09:00:00-08:00',
        'end' => '2030-01-04T17:00:00-08:00',
    ]))['event'];
    assert_true($event['eventType'] === 'block');
});

test('manager can update outage beyond booking duration limits', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $manager = plugin_admin('manager');
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
    grant_plugin_admin($pdo, 'manager');
    $h->createEvent($c, $pdo, user('alice'), booking());
    assert_error('BOOKING_CONFLICT', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, plugin_admin('manager'), booking([
            'eventType' => 'block',
            'start' => '2030-01-01T09:30:00-08:00',
            'end' => '2030-01-01T10:00:00-08:00',
        ]));
    });
});

test('outage still conflicts with existing outage', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $manager = plugin_admin('manager');
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
    grant_plugin_admin($pdo, 'manager');
    $manager = plugin_admin('manager');
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
    grant_plugin_admin($pdo, 'manager');
    $manager = plugin_admin('manager');
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
    grant_plugin_admin($pdo, 'manager');
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T01:00:00-08:00',
        'end' => '2030-01-01T02:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, plugin_admin('manager'), booking([
        'eventType' => 'block',
        'start' => '2030-01-01T02:00:00-08:00',
        'end' => '2030-01-01T03:00:00-08:00',
    ]));
});

test('existing event type cannot be changed', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $manager = plugin_admin('manager');
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
    grant_plugin_admin($pdo, 'manager');
    $manager = plugin_admin('manager');
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
    try {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T09:00:00-08:00',
            'end' => '2030-01-01T14:00:00-08:00',
        ]));
        throw new RuntimeException('Expected INVALID_INPUT');
    } catch (InstrumentBookingException $e) {
        assert_true($e->errorCode() === 'INVALID_INPUT');
        assert_true(str_contains($e->getMessage(), 'limit: 4 hours'));
        assert_true(str_contains($e->getMessage(), 'Requested: 5 hours'));
    }
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
            'maxBookingHours' => 2,
            'weeklyQuotaHours' => 0,
        ]);
    });
});

test('admin can create and update instruments', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $admin = plugin_admin('manager');
    $created = $h->createInstrument($c, $pdo, $admin, [
        'name' => 'TEM-01',
        'description' => 'Transmission Electron Microscope',
        'maxBookingHours' => 3,
        'weeklyQuotaHours' => 6,
    ])['instrument'];
    assert_true(str_starts_with($created['code'], 'tool-'));
    assert_true($created['maxBookingHours'] === 3);
    assert_true($created['weeklyQuotaHours'] === 6);
    assert_true($created['maxMinutes'] === 180);
    assert_true($created['weeklyQuotaMinutes'] === 360);
    $stored = $pdo->query(
        "SELECT max_booking_minutes, weekly_quota_minutes FROM instruments WHERE code = " . $pdo->quote($created['code'])
    )->fetch();
    assert_true((int)$stored['max_booking_minutes'] === 180);
    assert_true((int)$stored['weekly_quota_minutes'] === 360);

    $updated = $h->updateInstrument($c, $pdo, $admin, [
        'instrumentCode' => $created['code'],
        'name' => 'TEM-01 Updated',
        'description' => 'Updated description',
        'maxBookingHours' => 4,
        'weeklyQuotaHours' => 8,
    ])['instrument'];
    assert_true($updated['name'] === 'TEM-01 Updated');
    assert_true($updated['maxBookingHours'] === 4);
    assert_true($updated['weeklyQuotaHours'] === 8);
    assert_true($updated['maxMinutes'] === 240);
    assert_true($updated['weeklyQuotaMinutes'] === 480);
});

test('admin cannot modify another users booking', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $event = $h->createEvent($c, $pdo, user('alice'), booking())['event'];
    assert_error('EVENT_NOT_EDITABLE', function () use ($h, $c, $pdo, $event) {
        $h->updateEvent($c, $pdo, plugin_admin('manager'), [
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
    grant_plugin_admin($pdo, 'manager-a');
    grant_plugin_admin($pdo, 'manager-b');
    $event = $h->createEvent($c, $pdo, plugin_admin('manager-a'), booking([
        'eventType' => 'block',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]))['event'];
    assert_error('EVENT_NOT_EDITABLE', function () use ($h, $c, $pdo, $event) {
        $h->updateEvent($c, $pdo, plugin_admin('manager-b'), [
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
    grant_plugin_admin($pdo, 'manager');
    set_instrument_rules($pdo, 'sem-01', 120, 120);
    $admin = plugin_admin('manager');
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
    grant_plugin_admin($pdo, 'manager');
    set_instrument_rules($pdo, 'sem-01', 60, 60);
    $event = $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]))['event'];
    $h->cancelEvent($c, $pdo, user(), ['eventId' => $event['id']]);
    $h->createEvent($c, $pdo, plugin_admin('manager'), booking([
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
    grant_plugin_admin($pdo, 'manager');
    $h->knownUsers = ['ops-admin' => 'Ops Admin'];
    $admin = plugin_admin('manager');
    $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'ops-admin']);
    $admins = $h->listPluginAdmins($pdo);
    assert_true(count($admins) === 2);
    $names = array_map(static fn(array $row): string => $row['username'], $admins);
    sort($names);
    assert_true($names === ['manager', 'ops-admin']);
    assert_true(!array_key_exists('canRemove', $admins[0]));
});

test('instruments API marks only plugin_admins as isAdmin', function () {
    [$h, $c, $pdo] = fixture();
    $guest = $h->listInstruments($c, $pdo, user('test'));
    assert_true($guest['isAdmin'] === false);
    $superuser = $h->listInstruments($c, $pdo, user('wiki-admin', ['admin'], true));
    assert_true($superuser['isAdmin'] === false);
    grant_plugin_admin($pdo, 'trsys-admin');
    $admin = $h->listInstruments($c, $pdo, plugin_admin('trsys-admin'));
    assert_true($admin['isAdmin'] === true);
});

test('dokuwiki superuser without plugin_admins is not trsys admin', function () {
    [$h, $c, $pdo] = fixture();
    $context = user('wiki-admin', ['admin'], true);
    assert_true($h->isPluginAdmin($pdo, $context) === false);
    assert_true($h->isManager($c, $context, $pdo) === false);
    assert_error('ADMIN_REQUIRED', function () use ($h, $c, $pdo, $context) {
        $h->listAdminInstruments($c, $pdo, $context);
    });
    assert_error('PERMISSION_DENIED', function () use ($h, $c, $pdo, $context) {
        $h->createEvent($c, $pdo, $context, booking(['eventType' => 'block']));
    });
});

test('manager_groups member without plugin_admins is not trsys admin', function () {
    [$h, $c, $pdo] = fixture();
    assert_true($c['manager_groups'] === ['instrument-admin']);
    $context = user('legacy-manager', ['instrument-admin']);
    assert_true($h->isPluginAdmin($pdo, $context) === false);
    assert_true($h->isManager($c, $context, $pdo) === false);
    $listed = $h->listInstruments($c, $pdo, $context);
    assert_true($listed['isAdmin'] === false);
    assert_error('ADMIN_REQUIRED', function () use ($h, $c, $pdo, $context) {
        $h->listAdminInstruments($c, $pdo, $context);
    });
});

test('ordinary user cannot see settings or create outage', function () {
    [$h, $c, $pdo] = fixture();
    $listed = $h->listInstruments($c, $pdo, user('test'));
    assert_true($listed['isAdmin'] === false, 'Frontend hides Settings unless isAdmin === true');
    assert_error('ADMIN_REQUIRED', function () use ($h, $c, $pdo) {
        $h->listAdminInstruments($c, $pdo, user('test'));
    });
    assert_error('ADMIN_REQUIRED', function () use ($h, $c, $pdo) {
        $h->createInstrument($c, $pdo, user('test'), [
            'name' => 'TEM-01',
            'description' => '',
            'maxBookingHours' => 2,
            'weeklyQuotaHours' => 0,
        ]);
    });
    assert_error('PERMISSION_DENIED', function () use ($h, $c, $pdo) {
        $h->createEvent($c, $pdo, user('test'), booking(['eventType' => 'block']));
    });
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
    grant_plugin_admin($pdo, 'manager');
    $admin = plugin_admin('manager');
    $result = $h->deleteInstrument($c, $pdo, $admin, [
        'instrumentCode' => 'sem-01',
        'confirmName' => 'SEM-01',
    ]);
    assert_true($result['deleted'] === true);
    assert_true($h->listInstruments($c, $pdo, $admin)['instruments'] === []);
});

test('wrong confirmation name does not delete instrument', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    assert_error('DELETE_CONFIRMATION_MISMATCH', function () use ($h, $c, $pdo) {
        $h->deleteInstrument($c, $pdo, plugin_admin('manager'), [
            'instrumentCode' => 'sem-01',
            'confirmName' => 'Wrong Name',
        ]);
    });
    assert_true(count($h->listInstruments($c, $pdo, plugin_admin('manager'))['instruments']) === 1);
});

test('deleting a missing instrument returns not found', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    assert_error('INSTRUMENT_NOT_FOUND', function () use ($h, $c, $pdo) {
        $h->deleteInstrument($c, $pdo, plugin_admin('manager'), [
            'instrumentCode' => 'missing-tool',
            'confirmName' => 'Missing',
        ]);
    });
});

test('deleting an instrument removes bookings outages segments and request records', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    set_instrument_rules($pdo, 'sem-01', 240, 0);
    $admin = plugin_admin('manager');
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
    grant_plugin_admin($pdo, 'manager');
    $h->createEvent($c, $pdo, user(), booking([
        'instrumentCode' => 'tem-01',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]));
    $h->deleteInstrument($c, $pdo, plugin_admin('manager'), [
        'instrumentCode' => 'sem-01',
        'confirmName' => 'SEM-01',
    ]);
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM instruments WHERE code = 'tem-01'")->fetchColumn() === 1);
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM events WHERE instrument_code = 'tem-01'")->fetchColumn() === 1);
});

test('cli revoke removes non final admin and blocks last admin', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $h->knownUsers = ['alpha' => true, 'beta' => true];
    $admin = plugin_admin('manager');
    $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'alpha']);
    $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'beta']);
    $result = $h->revokePluginAdminCli($pdo, 'alpha');
    assert_true($result['revoked'] === true);
    assert_true($result['remainingAdmins'] === 2);
    $h->revokePluginAdminCli($pdo, 'manager');
    assert_error('LAST_ADMIN_CANNOT_BE_REVOKED', function () use ($h, $pdo) {
        $h->revokePluginAdminCli($pdo, 'beta');
    });
    assert_error('ADMIN_NOT_FOUND', function () use ($h, $pdo) {
        $h->revokePluginAdminCli($pdo, 'missing-user');
    });
});

test('cli bootstrap creates the first plugin admin only', function () {
    [$h, $c, $pdo] = fixture();
    $h->knownUsers = ['bootstrap-admin' => true, 'second-admin' => true];
    $result = $h->bootstrapPluginAdminCli($pdo, 'bootstrap-admin', 100);
    assert_true($result['bootstrapped'] === true);
    assert_true($result['username'] === 'bootstrap-admin');
    assert_true($h->isPluginAdmin($pdo, plugin_admin('bootstrap-admin')) === true);
    assert_true((int)$pdo->query('SELECT COUNT(*) FROM plugin_admins')->fetchColumn() === 1);
    assert_error('INVALID_INPUT', function () use ($h, $pdo) {
        $h->bootstrapPluginAdminCli($pdo, 'second-admin');
    });
});

test('cli bootstrap rejects missing dokuwiki users', function () {
    [$h, $c, $pdo] = fixture();
    $h->knownUsers = ['real-user' => true];
    assert_error('USER_NOT_FOUND', function () use ($h, $pdo) {
        $h->bootstrapPluginAdminCli($pdo, 'missing-user');
    });
    assert_true((int)$pdo->query('SELECT COUNT(*) FROM plugin_admins')->fetchColumn() === 0);
    $result = $h->bootstrapPluginAdminCli($pdo, 'real-user', 200);
    assert_true($result['bootstrapped'] === true);
    assert_true($result['username'] === 'real-user');
});

test('cli bootstrap rejects when plugin_admins is not empty', function () {
    [$h, $c, $pdo] = fixture();
    $h->knownUsers = ['late-admin' => true];
    grant_plugin_admin($pdo, 'existing-admin');
    assert_error('INVALID_INPUT', function () use ($h, $pdo) {
        $h->bootstrapPluginAdminCli($pdo, 'late-admin');
    });
});

test('settings hours map to database minutes', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    set_instrument_rules($pdo, 'sem-01', 360, 1800);
    $listed = $h->listAdminInstruments($c, $pdo, plugin_admin('manager'))['instruments'][0];
    assert_true($listed['maxBookingHours'] === 6);
    assert_true($listed['weeklyQuotaHours'] === 30);
    $saved = $h->updateInstrument($c, $pdo, plugin_admin('manager'), [
        'instrumentCode' => 'sem-01',
        'name' => 'SEM-01',
        'description' => 'Scanning Electron Microscope',
        'maxBookingHours' => 6,
        'weeklyQuotaHours' => 30,
    ])['instrument'];
    assert_true($saved['maxBookingHours'] === 6);
    assert_true($saved['weeklyQuotaHours'] === 30);
    $row = $pdo->query("SELECT max_booking_minutes, weekly_quota_minutes FROM instruments WHERE code = 'sem-01'")->fetch();
    assert_true((int)$row['max_booking_minutes'] === 360);
    assert_true((int)$row['weekly_quota_minutes'] === 1800);
});

test('weekly used time counts exact booking minutes', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 360, 0);
    $weekTs = la_timestamp('2030-01-01 12:00:00');

    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T09:30:00-08:00',
    ]));
    assert_true($h->weeklyBookingMinutesUsed($pdo, 'sem-01', 'alice', $weekTs, $c['timezone']) === 30);

    $pdo->exec('DELETE FROM events');
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T10:00:00-08:00',
        'end' => '2030-01-01T11:00:00-08:00',
    ]));
    assert_true($h->weeklyBookingMinutesUsed($pdo, 'sem-01', 'alice', $weekTs, $c['timezone']) === 60);

    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T12:00:00-08:00',
        'end' => '2030-01-01T13:00:00-08:00',
    ]));
    assert_true($h->weeklyBookingMinutesUsed($pdo, 'sem-01', 'alice', $weekTs, $c['timezone']) === 120);

    $pdo->exec('DELETE FROM events');
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-02T09:00:00-08:00',
        'end' => '2030-01-02T15:00:00-08:00',
    ]));
    assert_true($h->weeklyBookingMinutesUsed($pdo, 'sem-01', 'alice', $weekTs, $c['timezone']) === 360);
});

test('weekly used time ignores cancelled outages other users and instruments', function () {
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
    grant_plugin_admin($pdo, 'manager');
    set_instrument_rules($pdo, 'sem-01', 360, 0);
    set_instrument_rules($pdo, 'tem-01', 360, 0);
    $weekTs = la_timestamp('2030-01-01 12:00:00');

    $cancelled = $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:00:00-08:00',
    ]))['event'];
    $h->cancelEvent($c, $pdo, user(), ['eventId' => $cancelled['id']]);

    $h->createEvent($c, $pdo, plugin_admin('manager'), booking([
        'eventType' => 'block',
        'start' => '2030-01-01T11:00:00-08:00',
        'end' => '2030-01-01T14:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user('bob'), booking([
        'start' => '2030-01-01T15:00:00-08:00',
        'end' => '2030-01-01T16:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user(), booking([
        'instrumentCode' => 'tem-01',
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T12:00:00-08:00',
    ]));
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T16:30:00-08:00',
        'end' => '2030-01-01T17:00:00-08:00',
    ]));

    assert_true($h->weeklyBookingMinutesUsed($pdo, 'sem-01', 'alice', $weekTs, $c['timezone']) === 30);
});

test('weekly used time excludes original booking group on update', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 120, 120);
    $weekTs = la_timestamp('2030-01-01 12:00:00');
    $now = la_timestamp('2030-01-01 08:00:00');
    $event = $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T11:00:00-08:00',
    ]), null, $now)['event'];
    assert_true(
        $h->weeklyBookingMinutesUsed(
            $pdo,
            'sem-01',
            'alice',
            $weekTs,
            $c['timezone'],
            $event['bookingGroupId']
        ) === 0
    );
    $h->updateEvent($c, $pdo, user(), [
        'eventId' => $event['id'],
        'instrumentCode' => 'sem-01',
        'eventType' => 'booking',
        'note' => '',
        'start' => '2030-01-01T12:00:00-08:00',
        'end' => '2030-01-01T14:00:00-08:00',
    ], null, $now);
    assert_true($h->weeklyBookingMinutesUsed($pdo, 'sem-01', 'alice', $weekTs, $c['timezone']) === 120);
});

test('cross week segment usage is counted once per week', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 240, 0);
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-05T23:00:00-08:00',
        'end' => '2030-01-06T01:00:00-08:00',
    ]));
    $firstWeek = la_timestamp('2030-01-05 12:00:00');
    $secondWeek = la_timestamp('2030-01-06 12:00:00');
    assert_true($h->weeklyBookingMinutesUsed($pdo, 'sem-01', 'alice', $firstWeek, $c['timezone']) === 60);
    assert_true($h->weeklyBookingMinutesUsed($pdo, 'sem-01', 'alice', $secondWeek, $c['timezone']) === 60);
});

test('weekly limit error reports readable hours and minutes', function () {
    [$h, $c, $pdo] = fixture();
    set_instrument_rules($pdo, 'sem-01', 90, 90);
    $h->createEvent($c, $pdo, user(), booking([
        'start' => '2030-01-01T09:00:00-08:00',
        'end' => '2030-01-01T10:30:00-08:00',
    ]));
    try {
        $h->createEvent($c, $pdo, user(), booking([
            'start' => '2030-01-01T12:00:00-08:00',
            'end' => '2030-01-01T12:30:00-08:00',
        ]));
        throw new RuntimeException('Expected WEEKLY_LIMIT_EXCEEDED');
    } catch (InstrumentBookingException $e) {
        assert_true($e->errorCode() === 'WEEKLY_LIMIT_EXCEEDED');
        assert_true(str_contains($e->getMessage(), 'Used: 1 hour 30 minutes'));
        assert_true(str_contains($e->getMessage(), 'requested: 30 minutes'));
        assert_true(str_contains($e->getMessage(), 'limit: 1 hour 30 minutes'));
    }
});

test('admin can create and update a single tool row with positive hour limits', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $admin = plugin_admin('manager');
    $created = $h->createInstrument($c, $pdo, $admin, [
        'name' => 'AFM-01',
        'description' => 'Atomic Force Microscope',
        'maxBookingHours' => 2,
        'weeklyQuotaHours' => 8,
    ])['instrument'];
    assert_true($created['maxBookingHours'] === 2);
    assert_true($created['weeklyQuotaHours'] === 8);
    $updated = $h->updateInstrument($c, $pdo, $admin, [
        'instrumentCode' => $created['code'],
        'name' => 'AFM-01',
        'description' => 'Updated',
        'maxBookingHours' => 3,
        'weeklyQuotaHours' => 9,
    ])['instrument'];
    assert_true($updated['maxBookingHours'] === 3);
    assert_true($updated['weeklyQuotaHours'] === 9);
});

test('tool settings reject non positive hour values', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $admin = plugin_admin('manager');
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo, $admin) {
        $h->createInstrument($c, $pdo, $admin, [
            'name' => 'Bad Max',
            'description' => '',
            'maxBookingHours' => 0,
            'weeklyQuotaHours' => 4,
        ]);
    });
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo, $admin) {
        $h->createInstrument($c, $pdo, $admin, [
            'name' => 'Bad Weekly',
            'description' => '',
            'maxBookingHours' => 2,
            'weeklyQuotaHours' => 0,
        ]);
    });
});

test('trsys admin can list dokuwiki candidate users', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $h->knownUsers = [
        'manager' => 'Manager Name',
        'test' => 'SY',
        'user02' => 'John Smith',
        'user03' => 'Jane Doe',
    ];
    $result = $h->listCandidateDokuWikiUsers($c, $pdo, plugin_admin('manager'));
    assert_true($result['supported'] === true);
    $names = array_map(static fn(array $row): string => $row['username'], $result['users']);
    assert_true($names === ['test', 'user02', 'user03']);
    assert_true($result['users'][0] === ['username' => 'test', 'displayName' => 'SY']);
    foreach ($result['users'] as $user) {
        assert_true(array_keys($user) === ['username', 'displayName']);
    }
});

test('ordinary user cannot list dokuwiki candidate users', function () {
    [$h, $c, $pdo] = fixture();
    $h->knownUsers = ['test' => 'SY'];
    assert_error('PERMISSION_DENIED', function () use ($h, $c, $pdo) {
        $h->listCandidateDokuWikiUsers($c, $pdo, user('test'));
    });
});

test('existing plugin admins are excluded from candidate users', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    grant_plugin_admin($pdo, 'ops-admin');
    $h->knownUsers = [
        'manager' => 'Manager',
        'ops-admin' => 'Ops',
        'test' => 'SY',
    ];
    $result = $h->listCandidateDokuWikiUsers($c, $pdo, plugin_admin('manager'));
    $names = array_map(static fn(array $row): string => $row['username'], $result['users']);
    assert_true($names === ['test']);
});

test('add administrator accepts one existing dokuwiki user', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $h->knownUsers = [
        'manager' => 'Manager',
        'test' => 'SY',
        'twin-a' => 'Same Name',
        'twin-b' => 'Same Name',
    ];
    $admin = plugin_admin('manager');
    $added = $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'test'], 50)['admin'];
    assert_true($added['username'] === 'test');
    assert_true($added['displayName'] === 'SY');
    assert_true($added['addedAt'] === 50);
    $candidates = $h->listCandidateDokuWikiUsers($c, $pdo, $admin);
    $names = array_map(static fn(array $row): string => $row['username'], $candidates['users']);
    assert_true(!in_array('test', $names, true));
    $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'twin-a']);
    $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'twin-b']);
    $admins = $h->listPluginAdmins($pdo);
    $adminNames = array_map(static fn(array $row): string => $row['username'], $admins);
    sort($adminNames);
    assert_true($adminNames === ['manager', 'test', 'twin-a', 'twin-b']);
});

test('add administrator rejects arrays missing users and duplicates', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $h->knownUsers = ['manager' => true, 'test' => 'SY'];
    $admin = plugin_admin('manager');
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo, $admin) {
        $h->addPluginAdmin($c, $pdo, $admin, ['username' => ['test', 'other']]);
    });
    assert_error('DOKUWIKI_USER_NOT_FOUND', function () use ($h, $c, $pdo, $admin) {
        $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'ghost']);
    });
    assert_error('ADMIN_REQUIRED', function () use ($h, $c, $pdo) {
        $h->addPluginAdmin($c, $pdo, user('alice'), ['username' => 'test']);
    });
    $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'test']);
    assert_error('INVALID_INPUT', function () use ($h, $c, $pdo, $admin) {
        $h->addPluginAdmin($c, $pdo, $admin, ['username' => 'test']);
    });
});

test('add administrator fails when user disappears after listing', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $h->knownUsers = ['manager' => true, 'temp-user' => 'Temp'];
    $listed = $h->listCandidateDokuWikiUsers($c, $pdo, plugin_admin('manager'));
    assert_true($listed['users'][0]['username'] === 'temp-user');
    unset($h->knownUsers['temp-user']);
    assert_error('DOKUWIKI_USER_NOT_FOUND', function () use ($h, $c, $pdo) {
        $h->addPluginAdmin($c, $pdo, plugin_admin('manager'), ['username' => 'temp-user']);
    });
});

test('unsupported dokuwiki user enumeration returns explicit payload', function () {
    [$h, $c, $pdo] = fixture();
    grant_plugin_admin($pdo, 'manager');
    $h->userEnumerationSupported = false;
    $result = $h->listCandidateDokuWikiUsers($c, $pdo, plugin_admin('manager'));
    assert_true($result['supported'] === false);
    assert_true($result['users'] === []);
    assert_true($result['message'] === 'The current DokuWiki authentication backend does not support listing users.');
});

test('plugin code updated timestamp uses newest included file', function () {
    $h = new TestInstrumentBookingHelper();
    $root = sys_get_temp_dir() . '/ib-updated-' . bin2hex(random_bytes(4));
    mkdir($root);
    mkdir($root . '/db');
    mkdir($root . '/tests');
    file_put_contents($root . '/helper.php', "<?php\n");
    file_put_contents($root . '/script.js', "console.log(1);\n");
    file_put_contents($root . '/db/schema.sql', "SELECT 1;\n");
    file_put_contents($root . '/README.md', "docs\n");
    file_put_contents($root . '/tests/run.php', "<?php\n");
    file_put_contents($root . '/.DS_Store', "ignore\n");
    file_put_contents($root . '/plugin.info.txt', "date 2026-01-01\n");

    touch($root . '/helper.php', 1700000000);
    touch($root . '/script.js', 1700001000);
    touch($root . '/db/schema.sql', 1700005000);
    touch($root . '/README.md', 1800000000);
    touch($root . '/tests/run.php', 1800000000);
    touch($root . '/.DS_Store', 1800000000);
    touch($root . '/plugin.info.txt', 1700002000);

    $latest = $h->pluginCodeUpdatedTimestamp($root);
    assert_true($latest === 1700005000, 'Expected subdirectory sql file mtime');
    $meta = $h->pluginUpdatedMeta($root, 'America/Los_Angeles');
    assert_true($meta['timestamp'] === 1700005000);
    assert_true($meta['date'] === $h->formatPluginUpdatedDate(1700005000, 'America/Los_Angeles'));
    assert_true(!str_contains(json_encode($meta), $root));

    // Cleanup
    foreach ([$root . '/helper.php', $root . '/script.js', $root . '/db/schema.sql', $root . '/README.md', $root . '/tests/run.php', $root . '/.DS_Store', $root . '/plugin.info.txt'] as $file) {
        @unlink($file);
    }
    @rmdir($root . '/db');
    @rmdir($root . '/tests');
    @rmdir($root);
});

test('plugin updated date uses America/Los_Angeles across UTC day boundary', function () {
    $h = new TestInstrumentBookingHelper();
    // 2026-07-23 00:30:00 UTC => 2026-07-22 17:30:00 America/Los_Angeles
    $utcMorning = (new DateTimeImmutable('2026-07-23T00:30:00+00:00'))->getTimestamp();
    assert_true($h->formatPluginUpdatedDate($utcMorning, 'America/Los_Angeles') === '2026-07-22');
    // 2026-07-23 08:00:00 UTC => 2026-07-23 01:00:00 America/Los_Angeles
    $utcLater = (new DateTimeImmutable('2026-07-23T08:00:00+00:00'))->getTimestamp();
    assert_true($h->formatPluginUpdatedDate($utcLater, 'America/Los_Angeles') === '2026-07-23');
});

test('plugin updated meta falls back to plugin.info.txt when no code files exist', function () {
    $h = new class extends TestInstrumentBookingHelper {
        public function pluginCodeUpdatedTimestamp(?string $pluginRoot = null): ?int
        {
            return null;
        }
    };
    $root = sys_get_temp_dir() . '/ib-fallback-' . bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents($root . '/plugin.info.txt', "base x\ndate 2026-03-15\n");

    $withInfo = $h->pluginUpdatedMeta($root, 'America/Los_Angeles');
    assert_true($withInfo['timestamp'] === null);
    assert_true($withInfo['date'] === '2026-03-15');
    assert_true(!str_contains(json_encode($withInfo), $root));

    unlink($root . '/plugin.info.txt');
    $empty = $h->pluginUpdatedMeta($root, 'America/Los_Angeles');
    assert_true($empty['timestamp'] === null);
    assert_true($empty['date'] === null);
    assert_true(!str_contains(json_encode($empty), $root));

    @rmdir($root);
});

test('plugin info fallback date parses valid date field', function () {
    $h = new TestInstrumentBookingHelper();
    $root = sys_get_temp_dir() . '/ib-info-only-' . bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents($root . '/plugin.info.txt', "base instrumentbooking\ndate 2025-12-31\n");
    assert_true($h->pluginInfoFallbackDate($root) === '2025-12-31');
    @unlink($root . '/plugin.info.txt');
    assert_true($h->pluginInfoFallbackDate($root) === null);
    @rmdir($root);
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
