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

        $pdo->exec(Schema::SQL);
        self::migrate($pdo);

        $statement = $pdo->prepare(
            'INSERT OR IGNORE INTO meta (key, value) VALUES (:key, :value)'
        );
        $statement->execute(['key' => 'schema_version', 'value' => (string) Schema::VERSION]);

        $this->pdo = $pdo;

        return $this->pdo;
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
