<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Answers one question for the readiness probe: can this instance serve
 * memory traffic right now?
 *
 * Readiness means the store can be **opened and read** — not that a file
 * happens to exist. Opening the connection applies the schema idempotently,
 * so a freshly provisioned volume is ready on first boot (the first real
 * request would create the file anyway), whereas a store that cannot be
 * created, opened or queried fails here loudly and reports 503.
 *
 * A write probe is deliberately absent: exercising the write path would
 * mutate the live store on every health check, and a read that succeeds
 * already proves the connection, the file and the schema are in place.
 */
final class StoreProbe
{
    public function __construct(
        private readonly SqliteConnection $connection,
    ) {
    }

    /**
     * @return array{keys: int} the key count, as proof a real read went through
     *
     * @throws \PDOException if the store cannot be opened or queried
     */
    public function check(): array
    {
        $statement = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM memory_keys');
        $statement->execute();

        return ['keys' => (int) $statement->fetchColumn()];
    }
}
