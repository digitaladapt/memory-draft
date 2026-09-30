<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Tier;

/**
 * Applies {@see Schema} to a database and hands out configured connections.
 *
 * Kept separate from the repository so tests can point the same schema at
 * `:memory:` without touching a file.
 */
final class SqliteConnection
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
    ) {
    }

    /**
     * Build a connection for a file path, or for `:memory:`.
     *
     * PDO parses a DSN by treating everything before the first colon as the
     * driver name. The bare string `:memory:` therefore yields an *empty*
     * driver and fails with "could not find driver", so the `sqlite:` prefix is
     * required even for an in-memory database — `sqlite::memory:`.
     */
    public static function fromPath(string $path): self
    {
        return new self(str_starts_with($path, 'sqlite:') ? $path : 'sqlite:'.$path);
    }

    public function pdo(): \PDO
    {
        if (null !== $this->pdo) {
            return $this->pdo;
        }

        $pdo = new \PDO($this->dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            // Named parameters are used throughout; emulation would break the
            // same named parameter appearing twice in one statement.
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // WAL lets a reader and a writer proceed concurrently, which matters
        // because several agent sessions may share one store.
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        // Wait for a competing writer rather than failing the write outright.
        $pdo->exec('PRAGMA busy_timeout=10000');
        $pdo->exec('PRAGMA foreign_keys=ON');

        // Opening the store must not *write* when there is nothing to do.
        // Schema DDL and the meta upsert are writes, and a write taken here
        // would collide with any concurrent writer — so a read that merely
        // needed a connection could fail with "database is locked" for the
        // full busy_timeout. Establishing the schema is therefore a one-time
        // act, gated on the recorded version rather than repeated per open.
        if (Schema::VERSION !== self::recordedVersion($pdo)) {
            self::bootstrap($pdo);
        }

        $this->pdo = $pdo;

        return $this->pdo;
    }

    /**
     * The schema version recorded in the database, or null when it has never
     * been stamped (a brand-new file, or one written before versioning).
     *
     * A read, deliberately: it is what lets {@see pdo()} answer "is there
     * anything to do?" without taking a write lock to find out.
     */
    private static function recordedVersion(\PDO $pdo): ?int
    {
        try {
            $row = $pdo->query("SELECT value FROM meta WHERE key = 'schema_version'")->fetch();
        } catch (\PDOException) {
            // No meta table yet: nothing has ever been stamped.
            return null;
        }

        return false === $row ? null : (int) $row['value'];
    }

    /**
     * Create the tables, bring an older database forward, then index.
     *
     * The order is load-bearing and matches the spec: tables exist so the
     * migration can inspect them, the migration adds columns so the indexes
     * have something to name, and only then are indexes created. Creating an
     * index on a column that an older database does not have yet fails the
     * whole open.
     */
    private static function bootstrap(\PDO $pdo): void
    {
        $pdo->exec(Schema::TABLES);
        self::migrate($pdo);
        $pdo->exec(Schema::INDEXES);

        $pdo->prepare('INSERT OR IGNORE INTO meta (key, value) VALUES (:key, :value)')
            ->execute(['key' => 'schema_version', 'value' => (string) Schema::VERSION]);
    }

    /**
     * Bring an older database forward in place.
     *
     * Columns are added by `ALTER TABLE` rather than by recreating tables, so a
     * database written by an earlier build keeps its rows.
     */
    private static function migrate(\PDO $pdo): void
    {
        $columns = self::columns($pdo, 'sentences');
        if (!\in_array('backfilled', $columns, true)) {
            $pdo->exec('ALTER TABLE sentences ADD COLUMN backfilled INTEGER NOT NULL DEFAULT 0');
        }
        if (!\in_array('written_revision', $columns, true)) {
            $pdo->exec('ALTER TABLE sentences ADD COLUMN written_revision INTEGER NOT NULL DEFAULT 0');
        }

        $keyColumns = self::columns($pdo, 'memory_keys');
        if (!\in_array('revision', $keyColumns, true)) {
            $pdo->exec('ALTER TABLE memory_keys ADD COLUMN revision INTEGER NOT NULL DEFAULT 0');
        }
    }

    /**
     * @return list<string>
     */
    private static function columns(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query(\sprintf('PRAGMA table_info(%s)', $table))->fetchAll();

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function tierFromString(string $tier): Tier
    {
        return Tier::tryFrom($tier) ?? Tier::Hot;
    }
}
