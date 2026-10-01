# MemoryDraft — Specification

- **Status:** pre-1.0. Semantics settled; packaging is newer.
- **Repo:** `memory-draft` (private/ci tier — LAN-only, `private: false` for
  cross-repo `uses:`).
- **Profile:** `api-gateway` (no `templates/`, no Twig — skips frontend checks).
- **Stack:** PHP 8.5, Symfony 8.1, SQLite via PDO. No Doctrine: the store is one
  file with one table pair, and an ORM would add a mapping layer for no gain.
- **Relationship to the prototype:** the behaviour was first prototyped in Python
  (`memory-prototype/mem.py`, 41 tests) to find the sharp edges cheaply. Three
  of the decisions below exist because that prototype had a bug. Code did not
  carry over; the lessons did.

---

## 1. What this is

A keyword-addressed memory service. A caller asks *"what do I know about X?"* by
name and gets a sentence or two back. Writing is an upsert with an optional
revision.

The bet is that this covers most of what a model actually needs from "memory",
at a fraction of the complexity of embeddings and vector search — and that the
failure modes of a keyword store are tractable if they are designed against
deliberately rather than discovered later.

### 1.1 The two failure modes

A keyword store fails in two ways, and both are worse than they look because
they are **silent**.

**The key miss.** The caller asks for `ContextShuttle`. It was stored as
`context-shuttle`. The store returns nothing, the caller concludes it knows
nothing, and proceeds confidently without the fact. Nothing errors; the answer
is just wrong. §4 is entirely about this.

**Unannounced forgetting.** The store trims to stay bounded, and the caller has
no idea which of its beliefs just stopped being true. §5 is about this.

Everything below follows from taking those two seriously.

### 1.2 Non-goals

- **No semantic search.** Deliberate, not an omission. Embeddings would turn a
  predictable, inspectable store into a ranked one, and ranking that is *usually*
  right is harder to reason about than matching that is *definitely* right. If
  key misses turn out to be common in practice, the fix is a better miss path
  (§4.4), not a different retrieval model.
- **No user accounts.** One deployment, one store, one trust boundary. The
  network is the access control.
- **No summarisation or inference.** Sentences are stored and returned verbatim.
  The service does not decide what a memory *means*.

---

## 2. Model

```
memory_keys   id, key (canonical slug, UNIQUE), match_key (identity), revision,
              created_at, updated_at
sentences     id, key_id, text, text_hash, created_at, tier, pinned, batch,
              written_revision, backfilled
aliases       id, key_id, alias (UNIQUE), created_at
meta          key, value
```

Two tables rather than one because a key is a real entity independent of its
content: it has a revision that must survive an empty write, and it may exist
with every sentence in cold storage.

### 2.1 Why sentence granularity

The unit of storage is the **sentence**, not the entry. Consequences:

- Trimming is `UPDATE sentences SET tier = 'cold'` on specific rows. With a
  prose blob it would be a re-parse and a rewrite, and the boundary would drift
  slightly each time.
- "A sentence exists exactly once per key" is enforceable. With blobs, the same
  fact asserted twice produces a growing paragraph.
- The caller can retrieve *k* sentences (`--depth`) with predictable cost. **`depth` bounds unpinned sentences only.** Pinned sentences are returned in full however shallow the request, because `depth` exists to bound chatter and protecting the caller from the facts it explicitly refused to let age out is the opposite of the point (§5.2). `depth` defaults to 12 — a whole typical hot window — so the answer to a question that did not specify one is the key's current knowledge rather than a two-sentence prefix of it.

Prose is split heuristically, deliberately conservatively: abbreviations,
initials and decimals survive (`Dr. Smith`, `J. Adams`, `3.50`). An explicit
sentence list is always accepted, because the splitter is a convenience and
heuristics on prose are lossy.

### 2.2 Ordering: `batch`, not the clock

Every write takes a monotonic counter from `meta.next_batch`. Ordering is
`pinned DESC, backfilled ASC, batch DESC, id ASC`.

**`batch` exists because the clock is not good enough.** Timestamps are
second-resolution, and two writes inside the same second are ordinary — so
ordering by timestamp made same-second writes come back in arbitrary order.
That was a real bug in the prototype, and it showed up as recalled prose reading
*backwards*.

The `id ASC` tiebreak within a batch is the second half of the same lesson: the
newest *write* leads, but sentence order is preserved **within** a write, so a
paragraph reads forwards. Without it, a shallow `depth` truncated the **lead**
sentence — the one carrying the topic — and kept the trailing detail. Exactly
backwards from what is useful.

---

## 3. Keys

Two derived forms per key, because matching and display want different things.

| Form | Derived by | Used for |
|---|---|---|
| `slug` | camel-case split, lowercase, runs of non-alphanumerics → `-`, namespace `:` preserved | Storage and display |
| `match_key` | lowercase, **all** non-alphanumerics removed | Identity |

So `ContextShuttle` → slug `context-shuttle`, match key `contextshuttle`. All of
`context-shuttle`, `context_shuttle`, `Context Shuttle`, `CONTEXTSHUTTLE` share
that identity.

**Why strip everything rather than split on case transitions?** Stripping is
total and idempotent; case-splitting has to guess where words end and guesses
wrong on `iOS`, `McDonalds`, `contextSHUTTLE`. The identity function never guesses.

**Accepted consequence: `match_key` is many-to-one.** `project:foo` and
`project-foo` collide. This is deliberate. A collision is *visible* — both
spellings surface as aliases of one key in `memory:keys` — whereas a miss is
*silent*. Between a visible wrong-ish grouping and an invisible absence, take
the visible one.

**Slug idempotency is required, not cosmetic.** The slug is written to the
database, so re-slugging a stored key must be a no-op or the key drifts on every
read-back. This is why the camel-case split happens *before* lowercasing: doing
it after turns each capital into a separator and mangles the key
(`ContextShuttle` → `ontext-huttle`). That was a real bug, caught by a test
asserting readability rather than just identity.

---

## 4. Resolution

### 4.1 Escalation

1. **Exact** — the canonical slug matches a key.
2. **Alias** — the slug matches a recorded alias.
3. **Slug** — the `match_key` matches an existing key's identity.
4. **Miss** — nothing matched; report it and suggest.

Tiers 1–3 all return **content in the same round trip**. If the caller asked
with a variant spelling, the hit reports `resolved_from` so it can adopt the
canonical key — but it never has to make a second call to get its answer. A
"did you mean…?" that costs a round trip is a worse outcome than just answering.

### 4.2 Aliases are learned on write, never on read

Writing through a variant records it in `aliases`, so subsequent reads resolve
at tier 2 without the identity scan.

A **read never writes.** That is why a read of a variant resolves (tier 3) but
does not record anything: making the read path mutate would turn every lookup
into a write, and a store where reads write is a store where readers contend.
There is a test asserting exactly this, because it is the kind of property that
erodes quietly as matching logic grows.

### 4.3 Writes unify

A write through a variant resolves to the *existing* key rather than creating a
second one. This was a bug in the prototype — `ensureKey` accepted only exact
matches, so writing `ContextShuttle` when `context-shuttle` existed silently
created a duplicate, and the two spellings then diverged into separate memories
with no indication. Silent divergence is the worst outcome in the whole design.

### 4.4 The miss path

An unknown key returns an explicit miss — never an empty hit — with near-miss
suggestions scored on substring of the identity and shared tokens.

Suggestions are only offered when something actually overlaps. A suggestion
that shares nothing is worse than no suggestion: it invites the caller to trust
a key that is not what it asked for.

### 4.5 `memory:keys`

Enumerating the keyspace with hot/cold counts, revision, age and aliases is the
highest-value read in the service. The caller guessing key names is the failure
mode; this replaces guessing with looking. It is the answer to "what can I even
ask about?", which the caller otherwise cannot answer at all.

---

## 5. Aging

### 5.1 Demote, do not delete

Above `MEMORY_HOT_CAP` unpinned sentences per key, the oldest move to **cold**.
Cold sentences are excluded from default recall, retrievable with `--cold`, and
counted in `memory:keys`. Above `MEMORY_COLD_CAP` the oldest cold sentences are
deleted — the only place data is destroyed, and it is **reported on the write
that caused it**.

The asymmetry is the point. Trimming must produce a bounded default view; it
must not produce an unbounded *loss*. Demotion gives both, and the cost is a
little disk.

### 5.2 Caps are per key

The number that protects a context window is how many sentences **one key**
returns, so that is what the hot cap bounds. A global cap would let one chatty
key consume the budget or a thousand quiet keys force trimming of active ones.

The tradeoff accepted: a burst of chatter against one key crowds out its real
facts, without any signal that it happened. Two mitigations, both needed:

- **`--pin`** exempts durable facts (constraints, identity, preferences).
  Pinned sentences are excluded from the cap calculation entirely, so pinning
  cannot itself push unpinned facts out — and they are exempt from `depth`
  too, so a shallow recall returns every pinned fact rather than a prefix of
  them. A key whose durable half is nine sentences must not answer a
  two-sentence question by hiding seven of them.
- **Demotions are reported on the write**, so the caller sees what it just
  stopped guaranteeing at the moment it stops guaranteeing it.

`memory:stats` reports backfilled and pinned counts, so a store that is mostly
pinned is visible rather than inferred.

### 5.3 `replace` retires

`--mode replace` moves the key's current hot sentences to cold, then adds the
new ones. It never deletes, so a stale caller's `replace` cannot destroy
anything — the worst case is a recoverable retirement. `append` (renewing on
repeat) is the default; the destructive path is opt-in.

---

## 6. Revisions

Every key carries a monotonic `revision`, returned on every recall.

| Caller passes | Intent | Effect |
|---|---|---|
| nothing | `current` | Applies at the current revision |
| the revision it read | `confirmed` | A normal, fully-authoritative write |
| an older revision | `backfill` | Stored, flagged, ranked below current knowledge |

### 6.1 A stale write is kept, not rejected

This is the central decision, and the alternative is tempting: detect the
conflict, reject the write, tell the caller to re-read. That is textbook
optimistic concurrency and it is *wrong here*, for a reason specific to who the
caller is.

The caller is a language model that may have spent a long reasoning pass on what
it read. Its observation may be something the newer writer never knew. Rejecting
the write discards that **and** gives the caller nothing to do with it. So the
write is kept, flagged, and ranked below current knowledge.

Concretely, a backfilled sentence:

- is stored in the **hot** tier, not exiled to cold — it is not old, it is just
  written against an older picture;
- sorts **after** current sentences, so a shallow `depth` shows current
  knowledge first;
- is labelled `[backfilled]` on recall, so the caller can see the provenance;
- does **not** trigger a `replace` retirement, so a slow writer cannot wipe what
  a fast one just established.

The revision still bumps for a backfill, because the key *was* touched.

### 6.2 Revision is optional by design

A caller that did not read first passes nothing and is treated as `current`.
Requiring a revision would force a read-before-every-write, which doubles the
cost of the common case to protect the rare one.

---

## 7. What was wrong in the prototype

Recorded because each is a trap the PHP implementation would have hit too.

| Bug | Symptom | Fix |
|---|---|---|
| Ordered by second-resolution timestamp | Same-second writes came back arbitrarily; recalled prose read backwards | Monotonic `batch` counter, persisted |
| Newest-first ordering applied to sentences within a write | Shallow `depth` truncated the **lead** sentence, keeping trailing detail | `batch DESC, id ASC` |
| Upsert only checked the hot tier | A renewed fact was cloned into hot while the cold copy lingered — one fact, two rows | Check both tiers; promote rather than duplicate |
| `ensureKey` accepted only exact matches | Writing a variant created a **duplicate key**; the two spellings diverged silently | Accept any resolution |
| Camel-split applied after lowercasing | Capitals became separators: `ContextShuttle` → `ontext-huttle` | Split before lowercasing; test for readability, not just identity |
| Empty write created a key | No-op calls left empty keys as noise | Resolve emptiness before touching the store |
| Index created before migration added its column | Opening a pre-existing database failed outright | Tables → migrate → indexes |

---

## 8. API surface

### HTTP

A thin transport over the service; every rule below holds identically for the
console and the wire, because neither re-decides anything.

```
POST   /api/recall        {"queries": [{"key": "x", "depth": 12, "includeCold": false}]}
POST   /api/remember      {"items":   [{"key": "x", "sentences": "...", "mode": "append",
                                        "pin": false, "revision": 8}]}
GET    /api/keys          ?pattern=&limit=
GET    /api/stats
DELETE /api/keys/{key}

GET    /about             name + build-stamped version
GET    /health            liveness  — never touches the store
GET    /ready             readiness — opens and reads the store; 503 if it cannot
```

`/health` and `/ready` sit at the **root**, per §8.4, and because the Docker
`HEALTHCHECK` and the example compose file already probe `/health` there.

**A recall miss is `200`, not `404`.** The miss is the answer — it comes with
near-miss suggestions, and a `404` would discard them and make a lookup that
missed indistinguishable from one that could not be served. `DELETE` of an
unknown key *is* `404`, because there the absence is a caller mistake.

**`DELETE` resolves spelling variants** through the same escalating match as a
read. That is deliberate: the store's identity model says `ContextShuttle` and
`context-shuttle` are one key, and a delete that silently found nothing over a
hyphen would be the worst outcome in the whole design.

Bodies are bound with `#[MapRequestPayload]` / `#[MapQueryString]`, so the DTO
constraints are the contract. A blank key, an unknown `mode`, an out-of-range
`limit` or an empty batch is a `422` before the service is entered.

### Console

```
memory:recall   <key...> [--depth N] [--cold] [--json]
memory:remember <key> [text] [-s|--sentence ...] [--mode append|replace]
                            [--pin] [--revision N] [--json]
memory:keys     [pattern] [--limit N] [--json]
memory:stats    [--json]
memory:forget   <key> [--force]
```

`memory:forget` is a separate command rather than a flag, so the only
destructive operation cannot be reached by a typo in a write.

### Service

```php
$memory->recall([['key' => 'x', 'depth' => 12, 'includeCold' => false]]);
$memory->remember([new RememberItem(key: 'x', sentences: '...', revision: 8)]);
$memory->keys(pattern: '', limit: 200);
$memory->stats();
$memory->forget('x');
```

---

## 9. Configuration

| Variable | Default | Meaning |
|---|---|---|
| `MEMORY_DB_PATH` | `var/memory.db` | SQLite file; `:memory:` for tests |
| `MEMORY_HOT_CAP` | `20` | Unpinned hot sentences per key |
| `MEMORY_COLD_CAP` | `200` | Unpinned cold sentences per key |

Caps are guesses pending real use. The right way to tune them is to watch
`memory:stats` for backfilled and pinned growth, not to raise both.

---

## 10. Open questions

1. **Caps are unvalidated by real use.** 20/200 is reasoning, not measurement.
2. **No age warning on recall.** If everything under a key is months old, the
   caller cannot tell stale from current except by reading the age on each
   sentence. A key-level "nothing here has been touched in N months" line is
   cheap and probably worth adding.
3. **`match_key` collisions have no resolution path.** `project:foo` and
   `project-foo` unify and there is no way to split them again other than
   `forget`. Rare, but the escape hatch is missing.
4. **The miss path is the first thing to upgrade** if it proves insufficient —
   semantic search as a fallback for misses only, not as a replacement for
   matching.
