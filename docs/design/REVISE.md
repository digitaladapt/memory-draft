# PLAN — `revise`: retire or destroy one sentence, named by its text

Status: **implemented**. Extends SPEC §5 (Aging) and §8 (API). This is the plan
as agreed, updated where the implementation settled a detail differently — those
places are marked "as built".

---

## 1. The problem

Two ways exist to remove a *wrong* sentence, and neither fits:

- `remember --mode replace` retires **every** hot sentence on the key. One bad
  fact costs the caller every good fact beside it.
- `forget` destroys **the entire key**.

So a caller that recalls `soul` and finds eight good facts and one stale one has
no verb for the one. That gap is the whole of this plan.

## 2. The decision

One verb, two dispositions, addressed by text:

| `disposition` | Effect | Reversible |
|---|---|---|
| `demote` (default) | Move the matching sentence from hot to cold | Yes — re-stating it with `remember` promotes it back (`upsertSentence()` already does this) |
| `purge` | Delete the matching sentence outright | No |

`demote` is the default because it is the safe half of "get this out of view": the
per-sentence form of what `replace` does to a key and the hot cap does to the
oldest sentences. It introduces no new storage semantics — only a new *selection*
of which sentences are affected.

Both dispositions live in one verb because "this is no longer true" and "this must
not be recoverable" are one decision made with different confidence. Splitting
them across two tools is how the wrong one gets picked.

Three things this deliberately is not:

1. **Not a `forget` flag.** `forget` destroys a key; `revise` never removes a key
   or its revision, aliases or identity. Same target, different kind of act.
2. **Not ids.** See §3.1 — they are not needed, which is the point.
3. **Not fuzzy.** `contains` and `glob` are deterministic and reviewable. A
   similarity threshold would reintroduce the "ranking that is usually right"
   that SPEC §1.2 rejects in favour of "matching that is definitely right".

### 2.1 Why separate tools rather than one polymorphic `memory` verb switch

Tool descriptions are the model's only documentation. A single description
covering five verbs degrades all five, and `forget`'s is already the longest in
the family precisely because "this destroys things" needs the room to say so.

## 3. Matching: text, not identity

A target is `(key, text)`. That pair is **already unique**: `upsertSentence()`
finds an existing row by `hash('sha256', $text)` scoped to `key_id`, preferring
hot. Text addresses one sentence exactly, so an id would add nothing except the
obligation to disclose a handle nobody asked for.

### 3.1 Why no ids — and why that is smaller, not larger

An id would be a second name for a thing that already has one. That costs: a
field in every recall response, a column in every tool schema, a new class of
stale handle (the id a caller holds may point at a different row after a
`replace`), and an explanation for each. Text costs none of that and is the
handle a model can actually produce, because it is what recall just showed it.

The cost we accept: matching is by content, so a caller must quote or describe
the sentence rather than point at it. `dry_run` (§6) is what makes that safe.

### 3.2 Scope is one key, and stays one key

Prose matching with no key is `DELETE FROM sentences WHERE text LIKE …` across
the store — a footgun a model would eventually find. `keys` (a list) is a named
later step (§9), not v1.

### 3.3 Three match modes

| `match` | Meaning |
|---|---|
| `exact` (default) | Stored sentence equals the given text after folding |
| `contains` | Stored sentence contains the pattern after folding |
| `glob` | `*` and `?`, anchored to the whole folded sentence |

`exact` is the default because **a miss is recoverable and a wrong match is
not**. A caller quoting what recall showed it gets an exact hit or a clear miss,
never "I matched something similar and demoted that". Widening is the caller's
explicit act.

`glob` is anchored because an unanchored glob is `contains` with extra syntax, and
two ways to express one thing is how the wrong one gets used.

### 3.4 Folding is its own small function, not `KeyNormalizer`

`KeyNormalizer::fold()` is private and key-shaped: it collapses punctuation runs
to `-`, which is right for `context-shuttle` and wrong for prose (`"it's 3 p.m."`
→ `it-s-3-p-m`). Prose wants the opposite — punctuation is information; only case,
surrounding whitespace and Unicode composition should normalise.

`SentenceMatcher` gets its own ~10-line fold: NFKC, collapse whitespace runs to
one space, `mb_strtolower`, trim. Deliberate duplication, not a widened contract:
sharing a private method is how a key rule becomes a text rule by accident.

Consequence, stated because it will be asked: matching is
**diacritic-sensitive** (`café` ≠ `cafe`), unlike key resolution. That is the
conservative direction — it yields a miss, not a wrong match — and `contains` on a
shared stem is the escape hatch.

## 4. The awkward bits, decided deliberately

1. **Ambiguous `purge` is refused, not resolved.** If `glob`/`contains` selects
   more than one sentence and the disposition is `purge`, the call fails and lists
   the matches. Destroying several things is a decision to make having seen them.
   `demote` over several is **allowed** and reported, because it loses nothing and
   "push everything mentioning X out of view" is a legitimate request.
2. **`tier` defaults to `hot` for both dispositions.** Hot is the view the caller
   just read, so it is the set of sentences it can actually name. Reaching into
   cold is an act to intend.
3. **A `tier: hot` request that matches only cold is not a silent no-op.** It
   reports what it saw elsewhere and names the fix — the same rule as the
   key-miss path.
4. **Pins are skipped by default, both dispositions, always reported**
   (`skipped.pinned`). Pinning is the caller saying "this survives trimming", so
   trimming must not quietly reach it (SPEC §5.2). `include_pinned` is the
   explicit override, and with `purge` it is the most destructive thing this API
   can do — hence a named parameter, never a side effect.
5. **Ordering reuses the existing definitions.** Demotion candidates order
   `batch ASC, id ASC`, exactly as `trimHot()`. No second notion of "old".
6. **`purge` of nothing matched is a 200**, unlike `forget`'s 404. There is no
   single resource whose absence is the point here; a miss is a normal answer,
   consistent with recall.

## 5. Server behaviour

### 5.1 The report is the feature

Trimming already reports demotions and purges on the write that caused them
(SPEC §5.1). `revise` is an explicit trim, so it must be at least as loud:

```jsonc
{
  "key": "soul", "disposition": "demote", "match": "exact", "tier": "hot",
  "dry_run": false,
  "matched": 1, "changed": 1,
  "skipped": {"pinned": 0, "other_tier": 0},
  "revision": 9,
  "remaining": {"hot": 7, "cold": 3, "pinned": 2},
  "entries": [{"text": "the old plan", "action": "demoted", "age": "3d ago"}]
}
```

- `entries` echoes at most 20 and then `"more": N`. A `contains` over a chatty key
  must not fill a context window from a *write* result.
- **As built:** a third `skipped` key, `already_cold`, was in the draft and was
  dropped. Demoting a sentence that is already cold changes nothing, so counting
  it separately only produced a number nobody acts on; `tier: hot` already keeps
  cold rows out of `matched`, and `other_tier` is what explains a miss.
- `remaining` is included because "did I just empty the key?" is the immediate
  next question, and a second `recall` round trip to answer it is waste.

### 5.2 Revision

Bumped once per batch, because a revise changes what recall returns and a caller
holding an older revision should be flagged backfilled on its next `remember`.
**As built:** the bump happens only when the batch actually changes something — a
miss and a `dry_run` leave the revision where it was, so a rehearsal cannot
invalidate a revision the caller is holding.

**Pre-existing looseness, not changed here:** `pruneCold()` destroys sentences
without bumping the revision itself. That is invisible today because its only
caller is `remember`, whose single batch bump runs first and covers it — but it
would matter the moment anything else calls it. Out of scope; worth its own fix.

### 5.3 One transaction

Match and the writes it implies are one unit, so a report describing `changed: 1`
cannot be produced by a call that half-applied.

## 6. `dry_run` is not a convenience

With text matching there is no id to check before acting, so `dry_run: true` is
the only way to see what a pattern selects *before* it selects it. It runs the
identical match and returns the identical report with `changed: 0` — no writes, no
transaction.

It is what makes `contains`/`glob` usable against `purge` at all, and why refusing
ambiguous purges (§4.1) is tolerable rather than obstructive.

## 7. API surface

### HTTP

```
POST /api/revise
{"items": [{"key": "soul", "text": "the old plan", "disposition": "purge",
            "match": "exact", "tier": "hot", "dryRun": false,
            "includePinned": false}]}
```

`POST`, not `DELETE`: `demote` is not a deletion, and the request carries options
a path segment cannot. Bound with `#[MapRequestPayload]`, so the DTO constraints
are the contract — a blank `key`/`text`, an unknown `disposition` or `match` is a
422 before the service is entered.

### Console

```
memory:revise <key> <text...> [--disposition demote|purge] [--match exact|contains|glob]
                              [--tier hot|cold|any] [--dry-run]
                              [--include-pinned] [--force] [--json]
```

`--force` skips the prompt **only for `purge`**; `demote` never prompts because it
cannot lose anything.

### Service

```php
$memory->revise([['key' => 'soul', 'text' => 'the old plan',
                  'disposition' => 'demote']]);
```

Clamping and enum checks live here as well as at the edge: the service is called
directly and from the console (as with `latest`/`depth`).

### Tool (context-shuttle)

`config/tools/memory_revise.yaml` → `MemoryDraftTool::revise()`.

```
key             string  required
text            string  required
disposition     enum    demote | purge              (default: demote)
match           enum    exact | contains | glob     (default: exact)
tier            enum    hot | cold | any            (default: hot)
dry_run         bool    false
include_pinned  bool    false
```

The description should lead with `demote` being reversible and name `purge` as the
only irreversible memory operation once `forget` is gone.

## 8. Retiring `forget`

Sequence, not a flag day:

1. **Ship `revise`.** `forget` keeps working, gains one sentence: superseded by
   `memory_revise` with `disposition: purge`, which names what it destroys.
2. **Once `revise` is live in context-shuttle**, remove `memory_forget` from
   `config/tools/`, delete `MemoryDraftTool::forget()`, `ForgetCommand` and
   `DELETE /api/keys/{key}`, and move the tests across.
3. **`MemoryService::forget()` and `MemoryRepository::deleteKey()` last**, after
   the command and route that reach them are gone.

Why retire rather than alias it to `revise`: `forget` destroys a key *including*
its revision counter, aliases and identity. That is a real difference, and the
useful part of it — "this fact is wrong" — is now expressible while the
indiscriminate part never was.

**Compatibility note:** the tool name disappears, so
`MemoryDraftGatingTest` / `MemoryDraftGateTest` expectations
(`['memory_recall','memory_remember','memory_keys','memory_stats','memory_forget']`)
must change in the same commit as step 2. `/tools/memory_forget` likewise drops
out of the route assertions.

## 9. What we deliberately did not build

- **Cross-key revise.** `keys` as an alternative to `key` multiplies the blast
  radius of `purge` by the size of the store. One key at a time until there is a
  real request.
- **A "protect" verb.** `include_pinned` is an override, not protection; a caller
  that wants a fact immovable uses `pin: true` on `remember`.
- **Soft delete / `deleted_at`.** It would turn every read path into a filtered
  read and make `purge` stop meaning what it says. `demote` is why a caller that
  is unsure has a reversible option.
- **Restoring a purged sentence.** No undo, by design (§2).

## 10. Test plan

- **Matcher**: fold is idempotent; punctuation survives (`"it's 3 p.m."`); accent
  is *not* folded; `exact` on a quoted recall line; `contains` on a phrase; `glob`
  anchored (`plan*` matches `planned the move` but not `the plan`; `*plan`
  matches `the plan` but not `planet`); metacharacters in *text* are literal under
  `exact` and under `contains`, and special only under `glob`.
- **Service**: demote moves hot→cold and cold recall finds it; re-stating promotes
  it back; purge deletes and a second purge reports `changed: 0`; `tier: hot`
  matching only cold reports `other_tier`; pinned skipped and reported, then
  honoured with `include_pinned`; ambiguous purge refused; ambiguous demote
  allowed; revision bumped once; `dry_run` writes nothing (assert counts and
  revision unchanged); empty store; unknown key with suggestions.
- **HTTP**: each DTO refusal as a 422; the report shape; `dryRun` camelCase.
- **Console**: `--dry-run` **executes** (option-shape assertions alone pass while
  a command is broken); `--force` only affects `purge`.
- **Regression**: `recall soul` still returns every pinned fact after a revise
  that skipped them; `remember` still promotes a demoted sentence.

## 11. Open questions

1. ~~Separate tools or one verb switch?~~ **Separate.** Settled.
2. ~~Ids or text?~~ **Text, with `exact`/`contains`/`glob`.** Settled.
3. ~~Should `exact` over a pinned sentence refuse rather than skip?~~ **Silent
   skip + report**, consistent with the caps. Settled.
4. ~~Should `purge` of a **cold** sentence bump the revision?~~ **Yes, uniformly**
   — any change to what recall would return bumps it, whichever tier it touched.
   Settled as built; `pruneCold` is the loose end noted in §5.2.
5. ~~`entries` cap of 20 — a parameter like `depth`, or fixed?~~ **Fixed.**
   Settled; `"more": N` says the rest are there.

## 12. Alternatives rejected

- **`DELETE /api/sentences/{hash}`.** Small, and the hash is already a column — but
  it exposes a digest no caller can compute from what recall showed it, so it is
  an id by another name (§3.1).
- **`remember --mode retire --text …`.** Folds a destructive act into the write
  path, where a typo reaches it. The reason `forget` is a separate command is the
  reason this is a separate verb.
- **A `pattern` field on `forget`.** Changes what `forget` means for every
  existing caller, and leaves the name describing a superset of what it did.
