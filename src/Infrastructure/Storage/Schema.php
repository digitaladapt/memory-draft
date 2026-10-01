<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * The SQLite schema, kept in one place so it can be applied idempotently at
 * boot ({@see SqliteConnection}) and asserted by tests.
 *
 * Design notes, all of which exist to serve one property — *nothing is ever
 * silently lost*:
 *
 *  - **Two tables, not one.** `memory_keys` holds identity and the revision
 *    counter; `sentences` holds content. A key with no hot sentences is still a
 *    real key (it may be entirely in cold storage), and a revision must survive
 *    even if a caller only writes an empty batch.
 *
 *  - **`match_key` is stored, not computed.** Near-match resolution ("ContextShuttle"
 *    -> "context-shuttle") needs to find an existing row by its aggressive
 *    canonical form. Computing that in SQL is impossible, so it is written
 *    once at insert time and indexed.
 *
 *  - **`batch` is a monotonic write counter.** Ordering must not depend on clock
 *    resolution: two writes landing in the same second are common, and getting
 *    their order wrong makes recall show stale or backward prose.
 *
 *  - **`backfilled` ranks rather than rejects.** A write from a stale revision is
 *    stored with this flag set, so it sorts below current knowledge but is not
 *    discarded or pushed to cold.
 *
 *  - **`UNIQUE(key_id, text_hash, COALESCE(...))`** would be ideal but SQLite
 *    cannot index on an expression involving a nullable-free unique identity in
 *    a way that also varies by tier intent, so uniqueness is enforced in the
 *    repository inside a transaction. See {@see MemoryRepository::upsertSentence}.
 */
final class Schema
{
    public const VERSION = 1;

    /**
     * Table definitions only. Applied before {@see INDEXES} and before
     * {@see SqliteConnection::migrate()}, so an
     * older database gains its new columns before any index names them.
     *
     * Keeping DDL ordered (tables → rows migrated → indexes) is not tidiness:
     * an index on a column that does not exist yet fails, and the database then
     * cannot be opened at all. That ordering was a bug the prototype hit and
     * the reason it is called out in the spec.
     */
    public const TABLES = <<<'SQL'
        CREATE TABLE IF NOT EXISTS memory_keys (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            key         TEXT    NOT NULL UNIQUE,
            match_key   TEXT    NOT NULL,
            revision    INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        );

        CREATE TABLE IF NOT EXISTS sentences (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            key_id            INTEGER NOT NULL REFERENCES memory_keys(id) ON DELETE CASCADE,
            text              TEXT    NOT NULL,
            text_hash         TEXT    NOT NULL,
            created_at        TEXT    NOT NULL,
            tier              TEXT    NOT NULL DEFAULT 'hot',
            pinned            INTEGER NOT NULL DEFAULT 0,
            batch             INTEGER NOT NULL DEFAULT 0,
            written_revision  INTEGER NOT NULL DEFAULT 0,
            backfilled        INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS aliases (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            key_id    INTEGER NOT NULL REFERENCES memory_keys(id) ON DELETE CASCADE,
            alias     TEXT    NOT NULL UNIQUE,
            created_at TEXT   NOT NULL
        );

        CREATE TABLE IF NOT EXISTS meta (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        SQL;

    /**
     * Index definitions, applied last. Every column named here must already
     * exist — which is only true once the tables are created and any pending
     * migration has run.
     */
    public const INDEXES = <<<'SQL'
        CREATE INDEX IF NOT EXISTS idx_keys_match ON memory_keys(match_key);

        CREATE INDEX IF NOT EXISTS idx_sentences_key_tier
            ON sentences(key_id, tier);
        CREATE INDEX IF NOT EXISTS idx_sentences_order
            ON sentences(key_id, tier, backfilled, batch);
        CREATE INDEX IF NOT EXISTS idx_sentences_hash
            ON sentences(key_id, text_hash);
        SQL;
}
