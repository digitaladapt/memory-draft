# Security Policy

## Reporting a vulnerability

Report security issues privately to the maintainer rather than opening a public
issue. If you are unsure whether something qualifies, report it anyway — a false
report costs a few minutes, a missed one costs a disclosure.

Please include: what the issue is, how to reproduce it, and what you would
expect to happen instead.

## Scope

MemoryDraft is an **internal service**. It is designed to sit on a private
network, reached by other containers, and behind whatever authentication the
deployment puts in front of it. It does not implement user accounts and is not
designed to face the public internet.

Treat the following as in scope:

- Reading or writing a keyword the caller should not have access to.
- Any way a request can cause data loss beyond the documented trimming and
  `memory:forget` behaviour.
- Injection through a key name, a sentence, or a revision value.
- Leaking the store's contents through an error message or a log line.

## Design notes relevant to security

- **The store is a plain SQLite file.** Anyone who can read the file can read
  every memory, and anyone who can write it can alter or destroy them. File
  permissions and volume access are the real access control, so deployments
  must treat the database path with the same care as any other data file.
- **Sentences are stored verbatim.** They are never evaluated, so a stored
  sentence cannot execute anything — but it will be replayed into a language
  model's context later. Prompt-injection content therefore persists across
  sessions, and the store should not be writable by anything that accepts
  untrusted input.
- **Trimming is not deletion, and deletion is reported.** The only place data is
  destroyed is past the cold cap, or via an explicit `memory:forget`. If you
  find a path that loses data silently, that is a security-relevant bug.
- **`.env` is never committed and never copied into an image** (see
  `.dockerignore`). Configuration comes from the environment at runtime.

## Supported versions

The project is pre-1.0. Only the tip of `main` is supported; there are no
maintenance branches and no backported fixes.
