# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- HTTP API over the same service the console commands use, so the two transports
  cannot diverge:
  - `POST /api/recall`, `POST /api/remember`, `GET /api/keys`, `GET /api/stats`,
    `DELETE /api/keys/{key}`.
  - `GET /about`, `GET /health` (liveness, no store) and `GET /ready`
    (readiness, opens and reads the store, `503` when it cannot) — split per
    GUIDING-LIGHT §8.4, with the Docker `HEALTHCHECK` on `/health`.
  - Request bodies and query strings bound to validated DTOs
    (`#[MapRequestPayload]` / `#[MapQueryString]`): a blank key, an unknown
    `mode`, an out-of-range `limit` or an empty batch is a `422` before the
    service is reached.
- Application version stamped into a `VERSION` file at image build time from the
  `APP_VERSION` build arg and reported by `/about` (§8.13); an unstamped build
  reports `unknown` rather than guessing.
- Functional (`WebTestCase`) coverage for every endpoint, including the store
  failure path where `/ready` must return `503` while `/health` stays up.
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
- `phpdocumentor/reflection-docblock` is a direct dependency, not an incidental
  one. The typed collections on the request DTOs (`list<RememberItem>`) only
  denormalize to objects when docblock reflection is available; without it the
  collection stays raw arrays, `#[Assert\Valid]` silently stops cascading, and
  invalid input would reach the service instead of being rejected.
