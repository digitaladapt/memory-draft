# PLAN — `recall latest`: recency as a first-class read

**Status: implemented.** Shipped in `feat/recall-latest`.

| | |
|---|---|
| Repos | `memory-draft` (service), `context-shuttle` (tool surface) |
| Goal | Let a caller that knows no key names open a session with one call: *show me where we left off* |
| Shape | a new kind of **entry** in `POST /api/recall`, not a new tool and not a new endpoint |

---

## 1. The problem

A keyword store can only be asked about keys the caller already knows. A model at
the start of a session knows no keys: it must call `memory_keys`, read a list it
cannot rank, and choose. That open-ended selection is exactly what a small model
does badly — and "what did we talk about last time?" is the one question the
store cannot answer, because *recency is not currently addressable*.

What already covers the neighbouring ground, so this must not duplicate it:

- `project:*` keys are written by task-loom tasks, deliberately, after the fact.
- `email-summary` is written by a mail summary task.

So the missing piece is not a status briefing — vitals, spending, calendar and
mail all have their own tasks. It is **the conversation trail**, which only the
live session writes.

## 2. The decision

A recall entry may ask for recency instead of naming a key:

```jsonc
{"queries": [{"key": "soul", "depth": 12}, {"latest": true}]}
```

`latest` is **tri-state**:

| Value | Meaning |
|---|---|
| absent / `false` | An ordinary keyword lookup; `key` is required |
| `true` | The most recently written keys, at the service's default count |
| `N` | The most recently written keys, N of them |

### 2.1 Why an entry rather than a new tool or a mode flag

- A new tool is a new name for a small model to choose between, and *choosing* is
  where small models fail. Omitting an argument is easier than selecting a verb.
- It adds capability at **zero** tool count — the anti-accumulation principle
  paying rent. The model does not learn a new tool; it stops filling in a field.
- It stays inside `recall`'s existing contract (`hits`, `misses`, revisions,
  pinned, tiers), so nothing new has to be learned about the response shape.
- Named keys and a recency read **compose in one round trip**, which is what the
  opening call actually wants: the durable facts by name, plus what is recent.
  A separate endpoint could not do this without forcing two calls.

### 2.2 Why `latest` accepts `true` as well as a number

The default count (8) must live in the **service**, not in each client. A caller
that has to name a number is being asked a question it has no basis to answer,
and every client that hardcodes one has to be released to change it. So `true`
means "you decide", and the default can be tuned without touching a client.

The tool surface exposes **only the boolean** — the model says "recent", never
"eight" — while the wire and console can override the count.

This also matters for a migration this repo may face later: if memory-draft ever
offers its own MCP server, server-side defaults are the only version of this
ergonomic that survives the move.

### 2.3 Why not a literal empty body

The tempting shape is `{"queries": []}` meaning "show me everything recent",
with no new field at all. Rejected: the store's existing rule is that **an empty
recall is refused** (`Count(min: 1)` → 422), on the principle that an empty
request is a caller bug rather than a request for nothing. Making the empty body
mean something would trade that loud error for a silent default.

Keeping the union means `queries` keeps `Count(min: 1)` **unchanged**, and "I
forgot to name a key" still 422s with the message it always did.

## 3. The awkward bits, decided deliberately

1. **`{"key": "x", "latest": 3}` in one entry is refused.** Both is a two-entry
   request; in one entry it has no defined meaning, and inventing one would make
   the answer depend on a rule the caller cannot see.
2. **At most one recency entry per batch.** Two would be two orderings of one
   thing; the answer could only be one of them, and which one would be an
   implementation detail rather than a stated rule.
3. **A key named *and* recent appears once**, as the named hit. The named
   occurrence is the stronger claim — the caller asked about that subject
   specifically — so the expansion does not append a second, shallower copy.
   De-duplication is by **canonical key**, so `SOUL` and `soul` do not both
   count.
4. **Named hits lead, then the recency expansion**, wherever the recency entry
   sat in the batch. The answer reads predictably, and the de-duplication rule
   above is then independent of ordering.
5. **`0` is not "none" — it is refused.** `false !== $latest` rather than a
   truthiness check, so a zero count is reported as a bad count rather than
   silently reclassified as a keyword lookup that then fails for having no key.

## 4. Server behaviour (memory-draft)

### 4.1 Ordering: most recently written key first

`allKeys()` orders `updated_at DESC` and `bumpRevision()` stamps it on every
effective write, so recency needs **no new query and no migration**.

**It did need a fix, and this was the sharpest finding of the work.** Timestamps
are second-resolution, so a burst of writes — a session writing several keys, or
any batch landing inside one second — *ties*, and the database is then free to
return the rows in any order. It returned insertion order, which for a recency
read is **exactly backwards**: ten keys written in one second came back
oldest-first. The order is now `updated_at DESC, id DESC`, with the monotonic
autoincrement `id` as the tiebreak — the same lesson the sentences table learned
as `batch` (§2.2 of the spec). `memory_keys` had never needed it, because until
now its order was a cosmetic detail of a listing rather than the answer itself.

### 4.2 Breadth, not depth

The unit of "what changed" is the **key**, not the sentence. One chatty key must
not monopolise the answer: `email-summary` is routinely past revision 40, and an
"N newest sentences overall" design would let it own every slot forever.

So: the **N most recently written keys**, each contributing its **newest D
sentences**. Defaults **N = 8, D = 3**, both overridable, both clamped.

- N = 8 covers two or three sessions on a lively day. N = 5 would push a quiet
  conversation under a single normal day of mail-summary writes, and the whole
  point is that yesterday's conversation must still be in the window.
- D = 3 is enough for a "where were we" read; the key can be recalled by name
  for the rest.
- `depth` is the *same field a named entry uses*, not a second dial, so the
  request shape stays one field wide. The count is what a recency question has an
  opinion about ("how much recent"); `depth` remains "how much of each".

Pinned sentences come along as they always do (exempt from `depth`), which here
is a feature: a key whose durable facts are pinned describes itself well.

### 4.3 The answer must say what it did

The failure mode of a default is a caller mistaking it for a deliberate recall.
Every recency response therefore carries a leading note, **authored by the
service** so the console and the wire cannot disagree:

> `No keys given — showing the 8 most recently written keys (the service default): 3 included of 3 available, newest just now, oldest just now.`

Payload — a `latest` meta block alongside `hits`:

```json
"latest": {
  "note": "No keys given — showing the 8 most recently written keys (the service default): ...",
  "count": 8, "depth": 3,
  "included": 3, "available": 3,
  "newest": "just now", "oldest": "just now",
  "scope": "hot"
}
```

`available` excludes the keys already answered **by name**, so
`included < available` keeps meaning one thing — the store has fewer keys than
were asked for — instead of being confounded by de-duplication.

An **empty store is a 200 with a note**, not a 422: "nothing has been written
yet" is a true answer to a legitimate question, which is the opposite of the
empty *named* recall that is refused as a bug.

### 4.4 Every hit carries `last_written`

A recency read is the one place a caller *needs* staleness at a glance, and the
store already computes the humanized age for `keys()`. Added to every hit
payload; useful for ordinary recall too.

### 4.5 Marking the hit honestly

`MatchKind::Recent = 'recent'` says *why* this key is in the answer. Reporting
`exact` would tell the caller it had asked for this key, which it had not.
`describe()` takes a nullable `$requested` and passes `null` for recency hits, so
no spelling is ever claimed to have been resolved.

## 5. API surface

Identical on console, wire and service — the store's standing rule.

**Wire**

```
POST /api/recall   {"queries": [{"latest": true}]}
POST /api/recall   {"queries": [{"latest": 3}]}
POST /api/recall   {"queries": [{"key": "soul"}, {"latest": true}]}
```

- `latest` accepts `true`, or a count in `1..50`.
- `8.0` is accepted as the number eight — JSON has no integer type, so a client
  that serializes numbers as decimals is not making an error. `8.5` is refused as
  a *fractional count*, which is what it is.
- `latest` with a `key` in the same entry, two `latest` entries, and a count
  outside the range are each a `422` before the service is entered.

**Console**

```
memory:recall --latest                  # the service default
memory:recall --latest=20               # an explicit count
memory:recall soul --latest             # both, composing
memory:recall --latest --latest-depth 5
```

The `key` argument is now optional, and naming neither a key nor `--latest` is
refused at the console, matching the wire. `--latest` is declared
`VALUE_OPTIONAL` with a **`false` sentinel** rather than a `null` default:
Symfony reports `null` for both "absent" and "present with no value", so a `null`
default made `memory:recall --latest` indistinguishable from no recency request
at all — the command refused its own documented invocation.

**Service**

```php
$memory->recall([['latest' => true]]);
$memory->recall([['latest' => 8, 'depth' => 5]]);
$memory->recall([['key' => 'soul'], ['latest' => true]]);
```

## 6. Tool surface (context-shuttle)

- `config/tools/memory_recall.yaml`: `keys` → `required: false`; add `latest`
  (boolean). The description gains one sentence: *with no keys, returns where you
  left off — the most recently written keys and their newest sentences, and the
  response says so in its first line.*
- `MemoryDraftTool::recall()`: `keys` defaults to `[]`; when it is empty and
  `latest` is set, send `{"latest": true}` — the **boolean**, never a number, so
  the model chooses no dials.
- Keep the client-side refusals honest: a call with **neither** keys nor `latest`
  is refused client-side, matching the server.
- `normalizeKeys()` throws on an empty list today; it must become "empty is fine
  when `latest` is set".

## 7. Implementation notes

1. `RecallQuery::queries` is unchanged at `Count(min: 1)`. The key/latest rule is
   a **class-level `#[Assert\Callback]`** on each entry, not a property
   constraint: as a property constraint, `NotBlank` would reject every
   latest-only query for having no key, which is the thing `latest` exists for.
   The callback reproduces the old `NotBlank` message verbatim for the
   blank-key case.
2. The per-entry callback only becomes reachable if `#[Assert\Valid]` cascades,
   which depends on docblock reflection — hence
   `phpdocumentor/reflection-docblock` remaining a direct dependency.
3. `latest` is typed `bool|int|float` deliberately. Declaring `bool|int` made
   the serializer coerce `8.5` to an int, which PHP deprecates when precision is
   lost, so a malformed count produced a deprecation *and* a 422.
4. Clamp both dials in the service as well as at the edge. The service is called
   directly and from the console, and an unclamped `0` count would have returned
   **every key in the store** — a validation gap that reads as a feature.
5. `MatchKind` gained a case, so the compiler-checked places it flows through
   were revisited. There is no exhaustive `match` over it.
6. Rollout order: **server first.** The tool sends `latest`, which an older
   memory-draft would not understand.

## 8. Known limitations (accepted, with the optional fix named)

- **Timestamps are not remembered as dates.** The store keeps `age`, not a
  calendar date, so the next session cannot say "last night, 3:40pm" — only
  "5h ago". The sentence row already has `created_at`, so rendering a real date
  on request is a small addition; it is simply not part of the minimum.
- **The story can be scattered.** "Where we left off" may really be three keys
  from one session. N > 1 covers the ordinary case; the stronger version is one
  pinned `session:*` key written at close. Deferred: try plain recency before
  adding a second mechanism.
- **Conversation-tail truncation.** Keeping only the newest D sentences per key
  drops a long session's early turns from the read (recoverable by recalling the
  key by name). If it bites, the fix is a highlights write at session close, not
  a bigger D.
- **Write-side trigger is unchanged.** This makes the read *easy*; the soul
  ritual is what makes it *happen*. Two separate problems.

## 9. Test plan

- **Service**: recency ordering (including a same-second burst, which is the
  regression that caught the `id DESC` tiebreak); breadth honoured; chatty keys
  do not monopolise; the note; an empty store; named-plus-recent de-duplication
  by canonical key; named-leads ordering; clamping; reads mutate nothing.
- **HTTP**: the `latest` block; an explicit count; a combined request; each of
  the four refusals; a whole float accepted and a fractional one refused.
- **Console**: bare `--latest` **executes** (option-shape assertions alone passed
  while the command was broken); an explicit count; composition; both refusals.
- **Regression**: `recall soul` still returns every pinned fact.

## 10. Open questions

1. ~~Wire naming: `latest` vs `recent` vs `latest: true`.~~ **`latest`**, settled.
2. ~~Tool ergonomics: a boolean, or a nested `latest: {count, depth}`?~~
   **A boolean.** Dials stay server-side; `depth` is the existing field.
3. Should the recency read reach into cold for a key whose hot tier is thin?
   Currently no.
4. While here: should `memory_keys` expose a per-key **pinned** count? It already
   does (added with the pinned-count work), so this is closed.

## 11. Alternatives rejected

| Alternative | Why not |
|---|---|
| A `session_briefing` tool | Status-shaped, not recency-shaped; a new verb to choose between. |
| A `latest` field alongside `queries` | The union is better: it keeps `Count(min: 1)` untouched, lets named and recency compose in one call, and avoids a second top-level concept. |
| A literal empty-body recall | Trades the loud error for a silent default. |
| "N newest sentences overall" | One chatty key owns the answer forever. |
| Ordering by `updated_at` alone | Second-resolution: same-second writes tie, and the tie broke *newest-last*. |

## 12. A separate pre-existing finding, not fixed here

`RecallQuery`'s docblock and `PostRecall`'s README example both advertise that a
**bare string** is accepted in the array — `"recall": ["context-shuttle"]`. It is
not: the serializer constructs a `RecallQuery` with the default empty `key`, and
validation then rejects it as blank. This is unrelated to recency and is left
alone rather than folded into this change, but the docblock claim is wrong and
should either be made true or deleted.
