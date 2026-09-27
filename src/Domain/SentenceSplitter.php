<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Splits prose into sentence-ish units.
 *
 * Deliberately conservative, because the split is a heuristic and heuristics on
 * prose are lossy. Abbreviations, initials and decimals are all protected so
 * that "Dr. Smith", "J. Adams" and "costs 3.50" survive intact. A boundary
 * requires trailing whitespace after the terminator, which is what keeps
 * decimals together without special-casing them.
 *
 * An explicit sentence list is always accepted as an alternative — see
 * {@see \App\Service\MemoryService::remember()}. The splitter exists for
 * convenience, not as the only way in.
 */
final class SentenceSplitter
{
    /**
     * Abbreviations that must not be treated as sentence boundaries.
     *
     * @var list<string>
     */
    private const ABBREVIATIONS = [
        'mr', 'mrs', 'ms', 'dr', 'prof', 'sr', 'jr', 'st', 'vs', 'etc',
        'inc', 'ltd', 'co', 'no', 'fig', 'approx', 'dept', 'est', 'al',
    ];

    /** Sentinel that cannot occur in real text, standing in for a masked period. */
    private const SENTINEL = "\x00";

    /**
     * @return list<string>
     */
    public static function split(string $text): array
    {
        if ('' === trim($text)) {
            return [];
        }

        $protected = $text;

        foreach (self::ABBREVIATIONS as $abbreviation) {
            // Preserve the original casing ("Dr." stays "Dr."); only the
            // boundary is masked.
            $protected = (string) preg_replace_callback(
                '/\b'.preg_quote($abbreviation, '/').'\./i',
                static fn (array $m): string => substr($m[0], 0, -1).self::SENTINEL,
                $protected,
            );
        }

        // "J. Smith" — a single capital followed by a period is an initial.
        $protected = (string) preg_replace('/\b([A-Z])\./', '\\1'.self::SENTINEL, $protected);

        // Newlines end sentences too.
        $protected = (string) preg_replace('/\n+/', '. ', $protected);

        $parts = preg_split('/(?<=[.!?])\s+/', $protected) ?: [];

        $sentences = [];
        foreach ($parts as $part) {
            $sentence = trim(str_replace(self::SENTINEL, '.', $part));
            if ('' !== $sentence) {
                $sentences[] = $sentence;
            }
        }

        return $sentences;
    }

    /**
     * Accept either a prose blob or an explicit list of sentences.
     *
     * @param string|list<string> $value
     *
     * @return list<string>
     */
    public static function normalize(string|array $value): array
    {
        if (\is_string($value)) {
            return self::split($value);
        }

        $sentences = [];
        foreach ($value as $item) {
            foreach (self::split((string) $item) as $sentence) {
                $sentences[] = $sentence;
            }
        }

        return $sentences;
    }

    /**
     * Render an ISO-8601 timestamp as a coarse age, e.g. "3mo ago".
     *
     * Age is shown on every recalled sentence because recency is the only
     * ranking a keyword store has — and the cheapest way to spot a stale fact is
     * to see that nothing has touched the key in months.
     */
    public static function humanizeAge(string $timestamp, ?int $now = null): string
    {
        $then = strtotime($timestamp);
        if (false === $then) {
            return '?';
        }

        $delta = ($now ?? time()) - $then;

        return match (true) {
            $delta < 60 => 'just now',
            $delta < 3600 => intdiv($delta, 60).'m ago',
            $delta < 86400 => intdiv($delta, 3600).'h ago',
            $delta < 86400 * 30 => intdiv($delta, 86400).'d ago',
            $delta < 86400 * 365 => intdiv($delta, 86400 * 30).'mo ago',
            default => intdiv($delta, 86400 * 365).'y ago',
        };
    }
}
