# ADR-017: The daily demo reset runs as a job on Horizon, not inside the `scheduler` container

- **Status**: Accepted
- **Date**: 2026-09-13
- **Deciders**: project owner
- **Related**: [[ADR-001]], [[ADR-014]]

## Context and problem statement

FR-DEMO-03 (specs.md §22.1) requires the public demo to be restored daily, at 03:00 UTC, to the seeded data set, starting from publication (end of Phase 2). The plan (§8) described it as "one line in `routes/console.php`" over the `demo:reset` command, which has existed since Phase 1: `migrate:fresh` on the migration connection, then the full seed (3 tenants, 8,000 accounts, 50,000 orders).

`Schedule::command()` does not send the command anywhere. It runs it as a subprocess **inside the container that runs the scheduler**, that is, the `scheduler` service in `docker-compose.coolify.yml`: `mem_limit: 128m`, described in plan §3.1 as "it only dispatches — it does not itself execute heavy jobs". The image keeps PHP's default `memory_limit`, 128M.

The command's duration was known from Phase 1 (~57 s). Its memory peak had never been measured.

## Decision drivers

- **Measured, not estimated.** A reset that dies halfway is worse than no reset at all: `migrate:fresh` has already dropped the schema, and the public demo stays empty until the next run.
- **The memory budget of the shared VPS** (`.ai/rules/project.md`: 250–400 MB at peak, 11 measured OOM kills). Preferably without new caps.
- **The scheduler stays a dispatcher**, as the plan describes it.
- **Testable in CI**, not manual configuration outside the repo.

## The measurement

Environment: local PHP 8.4 CLI, PostgreSQL 16.14, a dedicated `throughput_reset_probe` database (so the measurement would not disturb the dev database), fully seeded. The real runtime is PHP 8.3 inside a Linux container: the absolute figures may differ, the order of magnitude does not.

| Process | Peak PHP heap | Max RSS | Duration |
|---|---|---|---|
| `demo:reset`, before the seed fix | 206.5 MB | 208 MB | 59 s |
| `demo:reset`, after the seed fix (`0772c63`), run with `memory_limit=128M` | 80.5 MB | 90.2 MB | 67.7 s¹ |
| `schedule:work`, idle | — | 63.2 MB | — |
| `schedule:run`, one pass with nothing due | — | 63.2 MB | — |

¹ On a machine loaded in parallel by other processes; unloaded, ~57 s (Phase 1).

**The seed fix.** The summaries of tenant Marlin's 30,000 orders held two Carbon objects per row until invoicing: 134.6 MB, against 16.1 MB with integer timestamps (measured in isolation). After the fix, the data stayed equivalent: 0 invoices with an `issue_date` different from the order date, 0 orders or deals older than their account, the same volumes.

**Even after the fix**, at 03:00 the `scheduler` container would hold, simultaneously, `schedule:work` (63 MB), the `schedule:run` started that minute (63 MB) and the reset (90 MB): ~216 MB against a 128m cap.

## Considered options

1. **`Schedule::command('demo:reset')` directly in `scheduler`, cap unchanged.** The variant from the plan. The reset does not fit even on its own next to `schedule:work`. Rejected.
2. **The same entry, with `scheduler` raised to 256m.** The simplest. But it raises the cap of a service on a VPS that has already had OOM kills, it contradicts the pure-dispatcher role, and at the ~216 MB measured it would have stayed at the limit anyway. Rejected.
3. **A Scheduled Task in Coolify, inside the `app` container (256m).** No code. But the configuration lives outside the repo, it is not testable in CI, and `DEMO_RESET_CRON` would go unused. Rejected.
4. **The scheduler dispatches `ResetDemoDataJob`, and the reset runs in `horizon` (384m, already budgeted).** **Chosen.**

## Decision

- `routes/console.php`: `Schedule::job(new ResetDemoDataJob, 'default')->cron(config('throughput.demo.reset_cron'))->when(DemoMode::enabled)`.
- `ResetDemoDataJob` is a system job, with no tenant ([[ADR-014]]):
  - `tries = 1`, `timeout = 600`, `failOnTimeout`;
  - `ShouldBeUnique` for 15 minutes;
  - it re-checks `DEMO_MODE` at execution time;
  - a non-zero exit code throws an exception, with the command's output in the message.
- `queue.connections.redis.retry_after` goes up from 90 to **900** s, above the job's timeout. Otherwise Redis would put the reset back on the queue while it is still running.
- The seed memory fix (`0772c63`) is a precondition: without it, the reset does not fit inside the Horizon worker's 128M `memory_limit` either.

## Consequences

### Positive

- No memory cap changes. The reset uses ~90 MB out of `horizon`'s 384m, at 03:00, when the queue is quiet.
- The scheduler stays a dispatcher. The schedule entry and the job's contract are covered by `DemoResetScheduleTest`: the cron expression, the DEMO_MODE filter, loud failure, `retry_after` above the timeout.
- The initial seed via `APP_RUN_SEEDERS` benefits from the same memory fix.

### Negative / trade-offs

- **The single worker is busy for ~70 s** while the reset runs (`maxProcesses = 1`). Jobs dispatched in that window wait. Accepted, at 03:00 UTC.
- **A `retry_after` of 15 minutes for every job on that connection.** A dead worker's job is retried after up to 15 minutes, not after 90 s.
- **The application returns errors for ~70 s** while `migrate:fresh` and the seed run. The same in every variant. A maintenance page for the duration of the reset remains a possible improvement, not made.
- The figures come from local PHP 8.4. They get re-verified on the production stack at publication (plan §15: "run once on the production stack, duration measured").

## History

- 2026-09-13 — created. The owner chose variant 4 out of variants 2–4, on the measurements above.
