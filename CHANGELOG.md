# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Keys are canonicalised through ICU, so accented spellings resolve to one key
  and emoji survive.** `café` written composed or decomposed is now one key
  (`cafe`), and `Latin-ASCII` folding runs through `Normalizer`/`Transliterator`
  rather than a hand-written table — which matters because stripping combining
  marks is right for Latin and *wrong* for Devanagari, where they are vowel
  signs. Emoji and non-Latin scripts are kept verbatim: the primary reader of a
  key is a language model, and `😬` is information where `?` is not. Requires
  `ext-intl`.

- **A recall entry can ask for the most recently written keys instead of naming
  one.** A caller opening a session knows no key names, so every recall it could
  make is a guess, and `memory:keys` hands it an unranked list to choose from —
  the selection decision small models make worst. Recency is the one question
  that needs no prior knowledge:

  ```jsonc
  {"queries": [{"latest": true}]}                  // the service's default count
  {"queries": [{"latest": 3}]}                     // an explicit count
  {"queries": [{"key": "soul"}, {"latest": true}]}  // both, in one round trip
  ```

  - `latest` accepts `true` for the **service's own default** (8), so no client
    hardcodes a number and the default can be retuned without a client release.
    `memory:recall --latest` and `--latest=N` are the console equivalents.
  - Breadth is counted in **keys, not sentences**: N recent keys, each
    contributing its newest D sentences (defaults 8 and 3, both capped), so a key
    written every few hours cannot take every slot.
  - Recency hits report `"match": "recent"` rather than claiming a match kind,
    and the response carries a `latest` block whose `note` states what was shown
    and whether the count was the caller's or the service's — authored by the
    service, so console and wire cannot describe it differently.
  - A key named *and* recent appears once, as the named hit; de-duplication is by
    canonical key, and `latest.available` excludes keys answered by name.
  - An **empty store** answers a recency read with a `200` and a note. Only an
    empty *named* recall stays a `422`.
  - Four shapes are refused: a `key` and `latest` in one entry, two `latest`
    entries, a count outside `1..50`, and a fractional count. A count written as
    a whole float (`8.0`) is accepted, because JSON has no integer type.

- Every recall hit reports `last_written`, the humanized age of the key itself,
  so a key nothing has touched in months is visible without recalling it. This is
  the key-level age line the spec's open questions asked for.

### Fixed

- **A key with only punctuation, emoji, or non-Latin characters was stored as
  the empty string, silently merging unrelated memories into one row.**
  `KeyNormalizer::slug()` kept nothing outside `a-z0-9`, so `..`, `😬` and `记忆`
  all normalised to `''`. Because the identity is *stored* rather than recomputed,
  the first such key created a row whose key was `''` and every later one joined
  it — no error, no trace, and a prefix match on `''` made it a candidate for
  every lookup. Non-empty input now guarantees non-empty output: when folding has
  nothing left to keep, the codepoints are spelled out (`---` → `u2d-u2d-u2d`).
- **`slug()` and `matchKey()` disagreed about non-Latin keys.** `matchKey()` had a
  fallback to the trimmed input for keys with no ASCII alphanumerics; `slug()` had
  none, so the two derived conflicting values for the same key.
- **The identity form was not idempotent.** ICU's `Latin-ASCII` is
  context-sensitive — `ṏ` folds only after a following combining mark is
  stripped, and stripping a mark can leave a jamo that NFKC then composes into a
  different syllable — so a stored `match_key` could change on read-back. Folding
  now iterates to a fixed point.
- **Non-Latin keys produced no suggestion tokens.** `tokens()` split on
  `[^A-Za-z0-9]+`, so a Cyrillic or CJK key yielded an empty token list and the
  miss path offered nothing for it, telling the caller no similar key exists.

- **`memory:keys` and the recency read were ordered by a second-resolution
  timestamp, so same-second writes came back oldest-first.** Ordering is now
  `updated_at DESC, id DESC`. It was harmless as a listing — the order of a
  directory was cosmetic — but recency made that order *the answer*, and the
  answer was inverted for any burst of writes landing in one second, which a
  session writing several keys does routinely. The monotonic `id` is the
  tiebreak, by the same argument as `batch` for sentences: ordering is not a
  question the clock can be trusted to answer.
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

- Key normalisation now requires **`ext-intl`** and **`ext-mbstring`**; both are
  declared in `composer.json`, installed in the image, and added to CI.

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

- **Keys are canonicalised through ICU, so accented spellings resolve to one key
  and emoji survive.** `café` written composed or decomposed is now one key
  (`cafe`), and `Latin-ASCII` folding runs through `Normalizer`/`Transliterator`
  rather than a hand-written table — which matters because stripping combining
  marks is right for Latin and *wrong* for Devanagari, where they are vowel
  signs. Emoji and non-Latin scripts are kept verbatim: the primary reader of a
  key is a language model, and `😬` is information where `?` is not. Requires
  `ext-intl`.

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
