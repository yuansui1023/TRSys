PRAGMA foreign_keys = ON;
BEGIN IMMEDIATE;

CREATE TABLE IF NOT EXISTS instruments (
    code                    TEXT PRIMARY KEY,
    name                    TEXT NOT NULL COLLATE NOCASE UNIQUE,
    description             TEXT NOT NULL DEFAULT '',
    max_booking_minutes     INTEGER NOT NULL,
    weekly_quota_minutes    INTEGER NOT NULL DEFAULT 0,
    created_at              INTEGER NOT NULL,
    updated_at              INTEGER NOT NULL,

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
);

CREATE TABLE IF NOT EXISTS events (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    instrument_code     TEXT NOT NULL,

    event_type          TEXT NOT NULL
                        CHECK (event_type IN ('booking', 'block')),

    owner_user          TEXT NOT NULL,
    title               TEXT NOT NULL,
    note                TEXT NOT NULL DEFAULT '',

    start_ts            INTEGER NOT NULL,
    end_ts              INTEGER NOT NULL,

    blocked_start_ts    INTEGER NOT NULL,
    blocked_end_ts      INTEGER NOT NULL,

    request_id          TEXT NOT NULL,
    booking_group_id    TEXT NOT NULL,

    created_at          INTEGER NOT NULL,
    updated_at          INTEGER NOT NULL,

    cancelled_at        INTEGER,
    cancelled_by        TEXT,

    CHECK (start_ts < end_ts),
    CHECK (blocked_start_ts <= start_ts),
    CHECK (blocked_end_ts >= end_ts)
);

CREATE INDEX IF NOT EXISTS events_conflict_idx
ON events (
    instrument_code,
    blocked_start_ts,
    blocked_end_ts
);

CREATE INDEX IF NOT EXISTS events_owner_idx
ON events (
    owner_user,
    start_ts
);

CREATE INDEX IF NOT EXISTS events_range_idx
ON events (
    start_ts,
    end_ts
);

CREATE INDEX IF NOT EXISTS events_request_idx
ON events (
    request_id
);

CREATE INDEX IF NOT EXISTS events_group_idx
ON events (
    booking_group_id
);

PRAGMA user_version = 2;
COMMIT;
