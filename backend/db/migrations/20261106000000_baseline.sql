-- The baseline's schema (20261106000000_baseline.php): the tables, indexes and
-- seed rows production already has. Frozen: never edit it. A schema change is a
-- new migration.
--
-- Each CREATE is the live database's own text (SQLite keeps it as written, with
-- every ALTER TABLE ... ADD COLUMN spliced in), hence the uneven layout. The
-- baseline compares a database it finds against these statements.

CREATE TABLE contacts (
        contact_id   INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id      INTEGER DEFAULT NULL,
        name         TEXT    NOT NULL,
        email        TEXT    NOT NULL,
        message      TEXT    NOT NULL,
        is_read      INTEGER NOT NULL DEFAULT 0,
        submitted_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
    );

CREATE TABLE deck_system_cards (
            deck_system_id INTEGER NOT NULL,
            card_id        INTEGER NOT NULL,
            name           TEXT NOT NULL DEFAULT '',
            keywords       TEXT DEFAULT NULL,
            meaning        TEXT DEFAULT NULL,
            advice         TEXT DEFAULT NULL,
            reversed_keywords TEXT DEFAULT NULL,
            reversed_meaning  TEXT DEFAULT NULL,
            reversed_advice   TEXT DEFAULT NULL,
            PRIMARY KEY (deck_system_id, card_id),
            FOREIGN KEY (deck_system_id) REFERENCES deck_systems(deck_system_id) ON DELETE CASCADE
        );

CREATE TABLE deck_systems (
        deck_system_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name           TEXT NOT NULL UNIQUE,
        short_name     TEXT NOT NULL,
        total_cards    INTEGER NOT NULL DEFAULT 78,
        approved       INTEGER NOT NULL DEFAULT 1,
        submitted_by   INTEGER DEFAULT NULL
    );

CREATE TABLE "decks" (
	"deck_id"	INTEGER NOT NULL UNIQUE,
	"name"	TEXT NOT NULL UNIQUE,
	"artist"	TEXT,
	"purchase_url"	TEXT,
	"additional_cards"	INTEGER NOT NULL DEFAULT 0, card_aspect_w REAL NOT NULL DEFAULT 5, card_aspect_h REAL NOT NULL DEFAULT 8.6, approved INTEGER NOT NULL DEFAULT 1, usable INTEGER NOT NULL DEFAULT 1, submitted_by INTEGER DEFAULT NULL, deck_system_id INTEGER NOT NULL DEFAULT 1,
	PRIMARY KEY("deck_id" AUTOINCREMENT)
);

CREATE TABLE pending_spreads (
        pending_id   INTEGER PRIMARY KEY AUTOINCREMENT,
        name         TEXT    NOT NULL DEFAULT '',
        description  TEXT    NOT NULL DEFAULT '',
        card_count   INTEGER NOT NULL DEFAULT 1,
        positions    TEXT    NOT NULL DEFAULT '[]',
        submitter    TEXT    NOT NULL DEFAULT '',
        submitted_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
    , user_id INTEGER DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL);

CREATE TABLE "readings" (
	"reading_id"	TEXT NOT NULL UNIQUE,
	"reading_info"	TEXT NOT NULL,
	"reading_time"	TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, user_id INTEGER DEFAULT NULL REFERENCES users(user_id) ON DELETE CASCADE, hide_user INTEGER NOT NULL DEFAULT 0, reading_name TEXT DEFAULT NULL, password_hash TEXT DEFAULT NULL, reading_notes TEXT DEFAULT NULL, is_final INTEGER NOT NULL DEFAULT 0, question TEXT DEFAULT NULL, querent TEXT DEFAULT NULL, is_private INTEGER NOT NULL DEFAULT 0, expires_at TEXT DEFAULT NULL,
	PRIMARY KEY("reading_id")
);

CREATE TABLE reading_tags (
        reading_id TEXT NOT NULL REFERENCES readings(reading_id) ON DELETE CASCADE,
        tag        TEXT NOT NULL,
        PRIMARY KEY (reading_id, tag)
    );

CREATE TABLE sessions (
        id      TEXT    PRIMARY KEY,
        data    TEXT    NOT NULL DEFAULT '',
        expires INTEGER NOT NULL
    , user_id INTEGER DEFAULT NULL);

CREATE TABLE "special_cards" (
            deck_id           INTEGER NOT NULL,
            card_id           INTEGER NOT NULL,
            name              TEXT    NOT NULL,
            keywords          TEXT,
            meaning           TEXT,
            advice            TEXT,
            keywords_reversed TEXT,
            meaning_reversed  TEXT,
            advice_reversed   TEXT,
            PRIMARY KEY (deck_id, card_id),
            CONSTRAINT fk_special_cards_decks FOREIGN KEY (deck_id) REFERENCES decks (deck_id) ON DELETE CASCADE
        );

CREATE TABLE spread_categories (
        category_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL COLLATE NOCASE UNIQUE,
        sort_order  INTEGER NOT NULL DEFAULT 0
    );

-- The starting categories: every public spread is in one, and
-- spreads.category_id defaults to General's id 1.
INSERT INTO spread_categories (name, sort_order) VALUES
    ('General', 1),
    ('Love & relationships', 2),
    ('Career', 3),
    ('Decisions', 4),
    ('Self-reflection', 5),
    ('Seasonal', 6);

CREATE TABLE spreads (
        spread_id   INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL DEFAULT '',
        description TEXT    NOT NULL DEFAULT '',
        card_count  INTEGER NOT NULL DEFAULT 1,
        positions   TEXT    NOT NULL DEFAULT '[]'
    , category_id INTEGER NOT NULL DEFAULT 1 REFERENCES spread_categories(category_id));

CREATE TABLE user_favorite_decks (
        user_id    INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        deck_id    INTEGER NOT NULL REFERENCES decks(deck_id) ON DELETE CASCADE,
        created_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, deck_id)
    );

CREATE TABLE user_favorite_spreads (
        user_id        INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        spread_type    TEXT    NOT NULL CHECK(spread_type IN ('public', 'personal')),
        spread_id      INTEGER NOT NULL,
        created_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, spread_type, spread_id)
    );

CREATE TABLE user_passkeys (
        passkey_id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id             INTEGER NOT NULL,
        credential_id       TEXT    NOT NULL,
        public_key_pem      TEXT    NOT NULL,
        name                TEXT    NOT NULL DEFAULT 'My Passkey',
        sign_count          INTEGER NOT NULL DEFAULT 0,
        created_at          TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at        TEXT    DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
    );

CREATE TABLE user_spreads (
        user_spread_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id        INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        name           TEXT    NOT NULL DEFAULT '',
        description    TEXT    NOT NULL DEFAULT '',
        card_count     INTEGER NOT NULL DEFAULT 1,
        positions      TEXT    NOT NULL DEFAULT '[]',
        created_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE user_tokens (
        token_id    INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER NOT NULL,
        type        TEXT    NOT NULL,
        token_hash  TEXT    NOT NULL,
        expires_at  TEXT    NOT NULL,
        created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        used_at     TEXT    DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
    );

CREATE TABLE plugin_tokens (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id      INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        token_hash   TEXT    NOT NULL,
        label        TEXT    NOT NULL DEFAULT 'TarotGen plugin',
        scope        TEXT    NOT NULL DEFAULT 'account',
        created_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at TEXT    DEFAULT NULL,
        expires_at   TEXT    DEFAULT NULL,
        revoked_at   TEXT    DEFAULT NULL
    );

CREATE TABLE plugin_auth_codes (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id        INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        code_hash      TEXT    NOT NULL,
        code_challenge TEXT    NOT NULL,
        scope          TEXT    NOT NULL DEFAULT 'account',
        created_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at     TEXT    NOT NULL,
        used_at        TEXT    DEFAULT NULL
    );

CREATE TABLE plugin_draw_keys (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        code_hash       TEXT    NOT NULL,
        code_challenge  TEXT    NOT NULL,
        code_expires_at TEXT    NOT NULL,
        key_hash        TEXT    DEFAULT NULL,
        created_at      TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        issued_at       TEXT    DEFAULT NULL,
        last_used_at    TEXT    DEFAULT NULL,
        revoked_at      TEXT    DEFAULT NULL
    );

CREATE TABLE card_reports (
        report_id         INTEGER PRIMARY KEY AUTOINCREMENT,
        deck_id           INTEGER NOT NULL REFERENCES "decks"(deck_id) ON DELETE CASCADE,
        card_id           INTEGER NOT NULL,
        card_name         TEXT    NOT NULL DEFAULT '',
        report_count      INTEGER NOT NULL DEFAULT 1,
        resolved_at       TEXT    DEFAULT NULL,
        first_reported_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_reported_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
    , round INTEGER NOT NULL DEFAULT 1);

CREATE TABLE card_report_submissions (
        submission_id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_id     INTEGER NOT NULL REFERENCES card_reports(report_id) ON DELETE CASCADE,
        round         INTEGER NOT NULL,
        reason        TEXT    NOT NULL,
        details       TEXT    NOT NULL DEFAULT '',
        submitted_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE daily_cards (
        user_id    INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        day        TEXT    NOT NULL,
        reading_id TEXT    DEFAULT NULL REFERENCES readings(reading_id) ON DELETE SET NULL,
        PRIMARY KEY (user_id, day)
    );

CREATE TABLE users (
        user_id        INTEGER PRIMARY KEY AUTOINCREMENT,
        email          TEXT    NOT NULL COLLATE NOCASE,
        password_hash  TEXT    NOT NULL,
        display_name   TEXT    NOT NULL COLLATE NOCASE,
        is_active      INTEGER NOT NULL DEFAULT 0,
        is_admin       INTEGER NOT NULL DEFAULT 0,
        registered_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at  TEXT    DEFAULT NULL
    , google_id TEXT DEFAULT NULL, password_login_disabled INTEGER NOT NULL DEFAULT 0);

CREATE INDEX idx_contacts_is_read ON contacts (is_read);

CREATE INDEX idx_contacts_user ON contacts (user_id);

CREATE INDEX idx_favorite_decks_user ON user_favorite_decks (user_id);

CREATE INDEX idx_favorite_spreads_user ON user_favorite_spreads (user_id);

CREATE UNIQUE INDEX idx_passkeys_credential ON user_passkeys (credential_id);

CREATE INDEX idx_passkeys_user ON user_passkeys (user_id);

CREATE INDEX idx_pending_spreads_user ON pending_spreads (user_id);

CREATE INDEX idx_readings_time ON readings (reading_time, reading_id);

CREATE INDEX idx_readings_user_time ON readings (user_id, reading_time, reading_id);

CREATE INDEX idx_readings_expires ON readings (expires_at) WHERE expires_at IS NOT NULL;

CREATE INDEX idx_reading_tags_tag ON reading_tags (tag, reading_id);

CREATE INDEX idx_sessions_expires ON sessions(expires);

CREATE INDEX idx_sessions_user ON sessions (user_id);

CREATE INDEX idx_spreads_category ON spreads (category_id);

CREATE INDEX idx_user_spreads_user ON user_spreads (user_id);

CREATE UNIQUE INDEX idx_user_tokens_hash ON user_tokens (token_hash);

CREATE INDEX idx_user_tokens_user ON user_tokens (user_id, type);

CREATE UNIQUE INDEX idx_plugin_tokens_hash ON plugin_tokens (token_hash);

CREATE INDEX idx_plugin_tokens_user ON plugin_tokens (user_id);

CREATE UNIQUE INDEX idx_plugin_auth_codes_hash ON plugin_auth_codes (code_hash);

CREATE UNIQUE INDEX idx_plugin_draw_keys_code ON plugin_draw_keys (code_hash);

CREATE UNIQUE INDEX idx_plugin_draw_keys_key ON plugin_draw_keys (key_hash);

CREATE UNIQUE INDEX idx_card_reports_card ON card_reports (deck_id, card_id);

CREATE INDEX idx_card_report_submissions_report
        ON card_report_submissions (report_id);

CREATE UNIQUE INDEX idx_daily_cards_reading ON daily_cards (reading_id);

CREATE UNIQUE INDEX idx_users_display_name ON users (display_name COLLATE NOCASE);

CREATE UNIQUE INDEX idx_users_email ON users (email COLLATE NOCASE);

CREATE UNIQUE INDEX idx_users_google_id ON users (google_id);
