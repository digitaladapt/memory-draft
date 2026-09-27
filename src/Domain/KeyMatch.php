<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A key resolved to a record, plus how it was matched.
 *
 * Returning the match kind as part of a typed object — rather than as a string
 * inside an untyped array — is what lets static analysis follow the value
 * through the call graph. It also keeps the "how did I get here?" answer
 * attached to the thing it describes, instead of being reconstructed from an
 * array key at the call site.
 */
final class KeyMatch
{
    public function __construct(
        public readonly MemoryKey $key,
        public readonly MatchKind $kind,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, string $kind): self
    {
        return new self(
            key: MemoryKey::fromRow($row),
            kind: MatchKind::tryFrom($kind) ?? MatchKind::Miss,
        );
    }
}
