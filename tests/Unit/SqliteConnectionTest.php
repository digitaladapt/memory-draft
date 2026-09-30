<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Storage\Schema;
use App\Infrastructure\Storage\SqliteConnection;
use PHPUnit\Framework\TestCase;

/**
 * Opening the store, which is where two silent failures lived.
 *
 * Both are about *when* the store is allowed to write. Opening it must not
 * write when the schema is already current — otherwise every read request takes
 * a write lock and collides with any concurrent writer — and when it does
 * bootstrap, the order (tables → migrate → indexes) has to be one an older
 * database can survive.
 *
 * @internal
 *
 * @covers \App\Infrastructure\Storage\SqliteConnection
 */
final class SqliteConnectionTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }

        $this->paths = [];
    }

    private function tempDatabase(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'memopen').'.db';
        $this->paths[] = $path;

        return $path;
    }

    /**
     * A read request opens a connection; a concurrent write must not make that
     * fail.
     *
     * Before this was fixed, `pdo()` ran `CREATE TABLE`/`CREATE INDEX` and an
     * `INSERT OR IGNORE INTO meta` on *every* open, so a new connection — i.e.
     * any new request, and the readiness probe — blocked for the whole
     * `busy_timeout` and then threw `database is locked` whenever another
     * writer held its transaction. A store where a read cannot be served while
     * a write is in flight has a much smaller safe concurrency than its
     * `busy_timeout` suggests.
     */
    public function testOpeningTheStoreDuringAConcurrentWriteDoesNotFail(): void
    {
        $path = $this->tempDatabase();

        // First open establishes the schema, so subsequent opens are read-only.
        SqliteConnection::fromPath($path)->pdo();

        // A writer takes the write lock and holds it.
        $writer = SqliteConnection::fromPath($path)->pdo();
        $writer->exec('BEGIN IMMEDIATE');

        try {
            // A *fresh* connection is what a new request gets. Opening it and
            // reading must succeed without waiting on the busy_timeout.
            $reader = SqliteConnection::fromPath($path)->pdo();
            $keys = $reader->query('SELECT COUNT(*) FROM memory_keys')->fetchColumn();
        } finally {
            $writer->exec('ROLLBACK');
        }

        self::assertSame(0, (int) $keys, 'the read went through while the write lock was held');
    }

    /**
     * An already-open reader is also unaffected, which is the property the
     * multi-process design depends on.
     */
    public function testAnOpenConnectionReadsWhileAnotherWriterHoldsTheLock(): void
    {
        $path = $this->tempDatabase();

        $readerConn = SqliteConnection::fromPath($path);
        $readerConn->pdo()->query('SELECT COUNT(*) FROM memory_keys')->fetchColumn();

        $writer = SqliteConnection::fromPath($path)->pdo();
        $writer->exec('BEGIN IMMEDIATE');

        try {
            $keys = $readerConn->pdo()->query('SELECT COUNT(*) FROM memory_keys')->fetchColumn();
        } finally {
            $writer->exec('ROLLBACK');
        }

        self::assertSame(0, (int) $keys);
    }

    /**
     * A database written by an earlier build must still open.
     *
     * The migration adds `backfilled` and `written_revision`; the indexes name
     * `backfilled`. Creating the indexes before the migration therefore fails
     * on exactly the databases the migration exists for, and the store becomes
     * unopenable rather than upgradeable. Order is tables → migrate → indexes.
     */
    public function testALegacyDatabaseWithoutTheNewerColumnsStillOpens(): void
    {
        $path = $this->tempDatabase();

        // A pre-migration sentences table: no `backfilled`, no `written_revision`.
        $legacy = new \PDO('sqlite:'.$path);
        $legacy->exec(
            'CREATE TABLE memory_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL UNIQUE,
                match_key TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )'
        );
        $legacy->exec(
            'CREATE TABLE sentences (
                id INTEGER PRIMARY KEY AUTOINCREMENT, key_id INTEGER NOT NULL,
                text TEXT NOT NULL, text_hash TEXT NOT NULL, created_at TEXT NOT NULL,
                tier TEXT NOT NULL DEFAULT \'hot\', pinned INTEGER NOT NULL DEFAULT 0,
                batch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $legacy->exec("INSERT INTO memory_keys (key, match_key, created_at, updated_at) VALUES ('legacy', 'legacy', '2020-01-01T00:00:00Z', '2020-01-01T00:00:00Z')");
        $legacy->exec("INSERT INTO sentences (key_id, text, text_hash, created_at) VALUES (1, 'Old row.', 'hash', '2020-01-01T00:00:00Z')");
        $legacy = null;

        $pdo = SqliteConnection::fromPath($path)->pdo();

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM sentences')->fetchColumn(), 'the existing row survived');

        $columns = array_map(
            static fn (array $row): string => (string) $row['name'],
            $pdo->query('PRAGMA table_info(sentences)')->fetchAll(),
        );
        self::assertContains('backfilled', $columns, 'the migration ran');
        self::assertContains('written_revision', $columns, 'the migration ran');
    }

    /**
     * The schema version is stamped exactly once, and re-opening a current
     * store does not touch the file.
     */
    public function testReopeningACurrentStoreDoesNotWrite(): void
    {
        $path = $this->tempDatabase();

        SqliteConnection::fromPath($path)->pdo();
        $before = filemtime($path);
        clearstatcache(true, $path);

        SqliteConnection::fromPath($path)->pdo();
        clearstatcache(true, $path);

        // The version is recorded, so the second open had nothing to bootstrap.
        self::assertSame(
            (string) Schema::VERSION,
            (string) SqliteConnection::fromPath($path)->pdo()->query("SELECT value FROM meta WHERE key = 'schema_version'")->fetchColumn(),
        );
        self::assertSame($before, filemtime($path), 'no write happened on a store that was already current');
    }
}
