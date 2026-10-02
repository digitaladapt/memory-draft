<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use App\Domain\WriteIntent;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One entry in a recall batch: either a keyword to look up, or a request for
 * the most recently written keys.
 *
 * The object form exists so a caller can ask for more (or less) depth per
 * keyword. `key` and `latest` are mutually exclusive — naming a key *and*
 * asking for the latest in one entry has no meaning, and inventing one would
 * make the answer depend on a rule the caller cannot see. Wanting both is a
 * two-entry batch, which reads plainly.
 *
 * `latest` is tri-state, and the distinction is the point:
 *
 *   false (default) — an ordinary keyword lookup; `key` is required.
 *   true            — the latest keys, at the service's own default count.
 *   int             — the latest keys, that many of them.
 *
 * `true` exists so the *default lives in the service* rather than in each
 * client. A caller — a small model especially — that has to name a number is
 * being asked a question it has no basis to answer, and every client that
 * hardcodes one is a client that has to be released to change it.
 *
 * The type is `bool|int|float` rather than `bool|int` because JSON has no
 * integer type: a payload saying `8.0` is the number eight, and it arrives as a
 * float. Declaring `bool|int` made the serializer coerce that float to an int,
 * which PHP deprecates when the conversion loses precision — so `8.5` produced a
 * deprecation *and* a 422. Accepting the number and judging it here is both
 * quieter and more honest: `8.0` is eight keys, `8.5` is a fractional count and
 * is refused as one.
 *
 * The "exactly one of key/latest" rule is enforced by a class-level callback
 * rather than a `#[Assert\NotBlank]` on `key`, because the constraint has to
 * see both fields: as a property constraint, `NotBlank` would reject every
 * latest-only query for having no key, which is exactly what `latest` is for.
 */
#[Assert\Callback('validateShape')]
final class RecallQuery
{
    /**
     * The largest number of recent keys a caller may ask for.
     *
     * Bounded for the same reason `GET /api/keys` bounds its limit: a recency
     * read is a probe for "where did we leave off", and an unbounded one is a
     * way to pull the whole store into a single context window.
     */
    public const LATEST_MAX = 50;

    public function __construct(
        public string $key = '',
        public ?int $depth = null,
        public bool $includeCold = false,
        public bool|int|float $latest = false,
    ) {
    }

    /**
     * True when this entry asks for recency rather than naming a key.
     *
     * `false !== $latest` rather than a truthiness check, deliberately: `0` is a
     * latest request with an invalid count (rejected below) and must not be
     * silently reclassified as a keyword lookup that then fails for having no
     * key. The error should name the actual mistake.
     */
    public function isLatest(): bool
    {
        return false !== $this->latest;
    }

    /**
     * How many recent keys to return, or `true` for the service's own default.
     *
     * Only meaningful when {@see isLatest()}; a keyword entry yields `false`.
     * One of the two things the service accepts — a count, or "you decide" —
     * with whole floats folded to integers here, in the one place that knows
     * JSON had no integer type.
     */
    public function latestCount(): bool|int
    {
        return \is_bool($this->latest) ? $this->latest : (int) $this->latest;
    }

    /**
     * Intent describing what the caller's optional revision means.
     */
    public function intent(?int $revision): WriteIntent
    {
        return null === $revision ? WriteIntent::Current : WriteIntent::Confirmed;
    }

    public static function validateShape(mixed $value, ExecutionContextInterface $context): void
    {
        if (!$value instanceof self) {
            return;
        }

        $named = '' !== trim($value->key);

        if ($value->isLatest() && $named) {
            $context
                ->buildViolation('An entry names a key or asks for the latest — not both. Ask for both with two entries.')
                ->atPath('key')
                ->addViolation();

            return;
        }

        if (!$value->isLatest() && !$named) {
            // The message the old `#[Assert\NotBlank]` produced, kept verbatim:
            // it is the one an existing caller may already be matching on, and
            // it is still the accurate description of this case.
            $violation = $context->buildViolation('This value should not be blank.');

            if ('' !== $value->key) {
                // Only a *named* path when something was actually typed. On a
                // latest-only entry there is no `key` field in play at all, and
                // pointing at one would send the caller looking for a spelling
                // mistake that does not exist.
                $violation->atPath('key');
            }

            $violation->addViolation();

            return;
        }

        // `true` means "the service's default", so only an explicit number is
        // range-checked.
        if (!$value->isLatest() || \is_bool($value->latest)) {
            return;
        }

        if (\is_float($value->latest) && $value->latest !== (float) (int) $value->latest) {
            $context
                ->buildViolation('A latest count must be a whole number of keys, or true for the default.')
                ->atPath('latest')
                ->addViolation();

            return;
        }

        if ($value->latestCount() < 1 || $value->latestCount() > self::LATEST_MAX) {
            $context
                ->buildViolation(\sprintf('A latest count must be between 1 and %d, or true for the default.', self::LATEST_MAX))
                ->atPath('latest')
                ->addViolation();
        }
    }
}
