# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Keyword-addressed memory store at sentence granularity, backed by SQLite.
- Two-tier storage: sentences past the per-key hot cap are **demoted** to a cold
  tier rather than deleted, and remain retrievable with `--cold`.
- Per-key monotonic **revisions**, returned by every recall and accepted
  optionally on write. A write from a stale revision is kept and flagged as
  *backfill*, ranked below current knowledge rather than rejected.
- Near-match resolution: `ContextShuttle`, `context-shuttle`, `context_shuttle`
  and `Context Shuttle` resolve to one key in a single round trip, reporting
  `resolved_from`. Writing through a variant records it as an alias.
- Explicit misses with near-miss suggestions, plus a `keys` command so the
  keyspace can be discovered rather than guessed.
- `--pin` to exempt durable facts from trimming; demotions are reported on the
  write that causes them.
- Console commands: `memory:recall`, `memory:remember`, `memory:keys`,
  `memory:stats`, `memory:forget`.

### Notes

- Trimming demotes rather than deletes; only exceeding the cold cap destroys
  anything, and that is reported. This is the core design commitment.
