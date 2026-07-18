PRAGMA foreign_keys = ON;
PRAGMA user_version = 1;

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

    request_id          TEXT NOT NULL UNIQUE,

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
