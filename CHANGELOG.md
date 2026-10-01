# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Re-stating a pinned fact no longer silently un-pins it.** `pin` is now
  tri-state: omitted leaves an existing pin exactly as it is, `true` pins, and
  `false` un-pins. It was a plain boolean, so an omitted `pin` and an explicit
  `false` were one and the same instruction — and because writing an existing
  sentence *renews* it, the canonical way to say "this is still true" also
  discarded the pin, quietly turning a durable fact into a disposable one. The
  loss only surfaced later, when trimming demoted the fact. There was also no
  way to take a pin back except `forget`, which destroys the history the store
  keeps; `pin: false` now does it without destroying anything.
- **Pinned sentences are no longer truncated by `depth`.** A shallow recall now
  returns *every* pinned sentence plus `depth` unpinned ones, because `depth`
  exists to bound chatter and hiding the facts a caller explicitly pinned is
  the failure it is meant to prevent. Previously `recall soul --depth 2`
  returned two of nine pinned facts with no indication the rest existed.
- **Opening the store no longer writes when the schema is current.** The DDL and
  the `schema_version` upsert were run on every connection, so any new
  connection — i.e. any read request, and the readiness probe — took a write and
  could fail with `database is locked` while another writer held its
  transaction. Bootstrap is now gated on the recorded version and is a one-time
  act.
- **A legacy database opens again.** Indexes were created before the migration
  added the columns they names, so a store written by an earlier build failed on
  open with `no such column: backfilled`. Order is now tables → migrate →
  indexes, as the spec always said it should be.
- `MEMORY_HOT_CAP` / `MEMORY_COLD_CAP` of zero or less no longer invert the
  trimming arithmetic; a negative cap reads as no budget rather than as an
  accidental setting of every hot sentence to demote.

### Changed

- `memory:remember`'s `--pin` is now negatable: `--pin` pins, `--no-pin`
  un-pins, and omitting it leaves an existing pin alone. `--pin` was a plain
  flag, so it could only ever assert "pin" and an un-pin was unreachable from
  the console.
- `memory:keys` and every recall hit now report a `pinned` count, so a key that
  holds more than the hot cap (because its sentences are pinned) is
  self-explaining rather than looking like a cap violation.
- The default recall depth is 12, not 2. A caller that did not ask for a
  specific depth now gets the whole current hot window instead of a two-line
  prefix of it — the default answer to "what do I know about X?" should be the
  answer, not a sample of it. Pinned sentences are returned on top of this.

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
