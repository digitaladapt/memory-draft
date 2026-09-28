# MemoryDraft

A small, self-hosted **memory service** for language models: keyword-addressed,
sentence-granular, revision-checked, and designed so that nothing is lost
silently.

The premise is that a model repeatedly needs to answer one question —
*"what do I know about X?"* — and that the cheapest useful answer is a sentence
or two per keyword, retrieved by name. No embeddings, no ranking model, no
vector store. Just a well-behaved key/value store with a few opinions about how
memory should age.

## Why it exists

A keyword store has two failure modes, and both are designed against here:

1. **The key miss.** You ask for `ContextShuttle`, it was stored as
   `context-shuttle`, and you get silence — then confidently proceed without the
   fact. Silent failure is the dangerous kind. So recall escalates match
   strength, resolves spelling variants **in the same round trip**, and always
   reports a miss explicitly.
2. **Memory that disappears without saying so.** Trimming *demotes* to a cold
   tier rather than deleting, a stale write is *kept and flagged* rather than
   rejected, and `replace` retires history rather than destroying it.

## Quick start

```bash
composer install

bin/console memory:remember deploy 'Uses blue-green deploys. Rollback is one command.'
bin/console memory:recall deploy
bin/console memory:keys
```

```bash
deploy  (2 hot, revision 1)
  - [just now] Uses blue-green deploys.
  - [just now] Rollback is one command.
```

### Docker

```bash
docker build -t memory-draft .
docker run -p 8080:80 -v memory-data:/data memory-draft
```

The store lives on a volume, so memory outlives the container.

## Commands

| Command | Purpose |
|---|---|
| `memory:recall <key...>` | Look up one or more keywords |
| `memory:remember <key> [text]` | Store sentences (prose or explicit `--sentence`) |
| `memory:keys [pattern]` | Enumerate the keyspace, with aliases |
| `memory:stats` | Counts and active trimming budgets |
| `memory:forget <key>` | Permanently delete a key |

Useful flags: `--depth N` (sentences per key), `--cold` (include the cold tier),
`--pin` (exempt from trimming), `--mode replace` (retire current sentences),
`--revision N` (write against a revision you read), `--json`.

## Concepts

### Sentence granularity

Each **sentence** is its own row. Trimming is then an exact tier change on a row
rather than a re-parse of a prose blob, and "a sentence exists exactly once per
key" is enforceable. Prose is split heuristically (`Dr. Smith`, `J. Adams` and
`3.50` all survive intact), but an explicit list is always accepted — the
splitter is a convenience, not the only way in.

### Two tiers: hot and cold

Above `MEMORY_HOT_CAP` unpinned sentences per key, the oldest are **demoted to
cold**, not deleted. Cold sentences are retrievable with `--cold` and are listed
by `memory:keys`. Only past `MEMORY_COLD_CAP` — a much larger budget — is
anything destroyed, and that deletion is reported on the write that caused it.

Caps are **per key**, because what actually threatens a context window is how
many sentences one key returns. Keys are namespaced by convention
(`project:`, `person:`, `pref:`) — free text, but it makes suggestions work.

### Revisions

Every key carries a monotonic **revision**, returned by every recall. Passing it
back on write is the concurrency control:

| You pass | Meaning |
|---|---|
| nothing | "I did not read first." Applies at the current revision. |
| the current revision | "Nothing changed since I read." A confirmed write. |
| an older revision | "Something changed since I read." Kept and **flagged as backfilled**. |

A stale write is *not* rejected. The caller is a language model reasoning from
what it read, and dropping its write discards something the newer writer may
never have known. So it is stored, flagged, and ranked below current knowledge
(`is backfilled` sorts after current, but stays in the hot tier — it does not
fall into cold). Bumping the revision even for a backfill records that the key
was touched.

### Near-match resolution

`ContextShuttle`, `context-shuttle`, `context_shuttle`, `Context Shuttle` and
`CONTEXTSHUTTLE` are all the same key. Resolution escalates: exact key → recorded
alias → aggressive canonical form (lowercase, all non-alphanumerics removed).
A resolved variant returns content in the same round trip and reports
`resolved_from`, so the caller can adopt the canonical spelling. A write through
a variant records it as an alias.

Collisions are accepted and visible: stripping is many-to-one, so
`project:foo` and `project-foo` unify. That is deliberate — a collision is
visible (both spellings list as aliases) whereas a miss would be silent.

When nothing matches, recall returns an explicit miss with near-miss suggestions
rather than an empty result.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `MEMORY_DB_PATH` | `var/memory.db` | SQLite file. One file, so a backup is a copy. |
| `MEMORY_HOT_CAP` | `20` | Unpinned sentences per key before demotion to cold. |
| `MEMORY_COLD_CAP` | `200` | Unpinned cold sentences per key before deletion. |

See `docs/examples/.env.example` for the annotated list.

## Development

```bash
composer test     # PHPUnit
composer stan     # PHPStan (level 6)
composer lint     # php-cs-fixer, dry run
composer cs-fix   # apply style fixes
```

## Layout

```
src/
  Command/          console commands (remember, recall, keys, stats, forget)
  Domain/           pure models: keys, sentences, tiers, splitting, matching
  Service/          application logic; the only thing a caller needs to know
  Infrastructure/   SQLite storage
tests/Unit/         the behaviour, exercised the way a caller would use it
docs/design/        SPEC.md and design notes
docs/examples/      annotated compose.yaml and .env.example
```

## Status

Pre-1.0. The semantics above are the point of the project and are covered by
tests; the surrounding packaging is newer. See `docs/design/SPEC.md` for the
reasoning behind each decision, including the ones that were reversed after
using the thing.
