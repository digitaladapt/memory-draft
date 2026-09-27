# Contributing

## Getting set up

```bash
composer install
composer test
```

## Before opening a pull request

All three of these must pass. They are the same three that run in CI, and they
are cheap enough to run on every save:

```bash
composer lint     # php-cs-fixer, dry run
composer stan     # PHPStan, level 6
composer test     # PHPUnit
```

`composer cs-fix` applies the style fixes rather than reporting them.

## What "done" means here

A feature is not done until it has a test that exercises it **the way a caller
would** — a service call for a service, not just "it did not throw". Several
tests in this repository exist because a real bug slipped past an earlier
version of them; where that happened, the test says so in a comment, and that
context is worth keeping.

## Design expectations

The semantics in this project are deliberate, and most of them are the opposite
of the obvious choice. Before changing behaviour, read `docs/design/SPEC.md`
— particularly the sections on trimming, revisions, and near-match resolution.
If you think a decision is wrong, the useful question is not "is this simpler?"
but "does this lose information silently?", because that is the failure mode
the whole design is organised around.

Concretely, changes that would be rejected:

- Deleting instead of demoting, when trimming.
- Rejecting a stale write instead of storing it as a backfill.
- Returning an empty result for an unknown key instead of an explicit miss.
- Making a read path mutate the store.

## Style

Symfony's coding standard via php-cs-fixer. Comments should explain *why* a
decision was made, especially where the reason is not visible from the code —
"this was a bug once" is more useful to the next reader than a restatement of
what the line does.
