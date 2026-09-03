# FINAL-AUDIT.md

The completion gate for Phases 0–20 (`BUILD-SPEC.md` §21.7).

**Date:** 2026-09-04 · **Head:** see §2 · **Version:** 0.1.0

---

## 1. Phases

All twenty-one gates passed, each with its own commit and no squashing.

| Phase | What it built | Commit |
|---|---|---|
| 0 | Contracts, schemas, state machines | `60160e5` |
| 1 | Registry, compiler, runtime, mu-plugin loader | `7f5eed7` |
| 2 | Scanners and facts | `9f47be9` |
| 3 | Analyzer, findings, score | `3d7944c` |
| 4 | Recommendation engine, preview planning | `2910859` |
| 5 | Snapshots, apply, rollback | `6a9c204` |
| 6 | Verification engine | `f25cfae` |
| 7 | WP-CLI | `26f566e` |
| 8 | Admin dashboard | `94d50fb` |
| 9 | Preview, Fix Safe Issues, before/after | `91a66d2` |
| 10 | Database intelligence (+ `0cb418f`, decisions first) | `72d557e` |
| 11 | Plugin intelligence | `fab10c9` |
| 12 | Admin intelligence | `0f33bca` |
| 13 | Asset detection | `a9f65e0` |
| 14 | Elementor intelligence | `a429994` |
| 15 | WooCommerce intelligence | `145ea81` |
| 16 | End-to-end verification | `a280ca6` |
| 17 | Registry ecosystem | `aeb57b9` |
| 18a | Rename to Debloater | `b08d8e3` |
| 18 | Release hardening | `7d55676` |
| 19 | Pro workflow features | `ff80992` |
| 20 | Cloud design document | `da0c53d` |

Phase 18a was inserted mid-phase on instruction: wordpress.org treats "wp" as a
restricted term, so "WP Debloat" / `wp-debloat` was never submittable
(`docs/DECISIONS.md` D-0047).

---

## 2. Test matrix

Every gate in §21.7, run at this commit.

| Check | Result |
|---|---|
| Unit — free plugin | **1 185 tests, 12 188 assertions** — pass |
| Unit — Pro | **14 tests, 110 assertions** — pass |
| Integration (wp-env + WP PHPUnit) | **302 tests, 4 572 assertions** — pass |
| Fail-probe (forced rollback) | **9 tests, 105 assertions** — pass |
| WP-CLI end to end | pass — the whole loop on the fixture site |
| E2E (Playwright, full stack) | **13 of 13** in one clean run, 20.2 min; see §5 |
| Registry JSON validity | pass — every document in `registry/` and `schemas/` |
| Registry manifest | pass — matches all 58 files |
| PHPCS (WordPress-Extra + VIP-Go) | **clean** — `src/`, `pro/`, `tests/`, handlers, loader |
| PHPStan level 6 | **no errors** — `src/` and `pro/` |
| ESLint | clean |
| Jest | 12 tests — pass |
| Bundle budget | 10 790 B gzipped — **4%** of the 256 KB budget |
| Plugin Check, against the shipped tree | **0 errors, 2 warnings** — see §4 |
| Security invariants (§13, one test per rule) | 15 of 15 — pass |
| Runtime zero-overhead (§14) | pass |
| Rollback / restore round trip | pass |
| Clean build / package | `debloater-0.1.0.zip`, 301 files, 522 KB; autoloads the plugin, carries no Pro code |

Totals: **1 523 automated tests** across five suites, plus 13 browser scenarios.

---

## 3. What the audit itself found

The gate is not a formality if it can still find something, and it did.

**`ExpiredTransientsCleanup` deleted rows outside its own recovery point.**
Carried as a known warning since Phase 10 and closed here.

The operation collected a batch of expired transients into the snapshot, then
`execute()` re-queried the database in a loop and deleted **every** expired
transient it found — including ones that expired in the seconds between the
recovery point being written and the deletion running. Those had no backup.
Restoring the snapshot would not have brought them back, because they were never
in it.

The practical harm is small: an expired transient is a cache entry the site had
already stopped honouring. That is not what made it worth fixing. **Invariant 8**
says a recovery point exists before a destructive operation runs, and an
operation that deletes outside its own recovery point does not satisfy that,
whatever the rows are worth. Every sibling operation already bounded `execute()`
by the highest primary key `collect()` saw; a transient has no id worth ordering
by, so the bound is now the set of names instead.

Two tests were added, and both were **confirmed to fail on the pre-fix code**
before the fix was restored — a transient that expires after collection now
survives, and an operation that never collected deletes nothing.

---

## 4. Known warnings

Five, all recorded rather than resolved, and none of them a red gate.

**1. The display title draws a `trademarked_term` warning.**

> The plugin name includes a restricted term. Your chosen plugin name —
> "Debloater – Scan, Fix & Undo WordPress Bloat" — contains the restricted term
> "wordpress" which cannot be used at all in your plugin name.

`Debloater` and `debloater` are both clean; only the tagline draws it. It is a
warning rather than an error, and the title was chosen deliberately for search.
Dropping the word from `Brand::TAGLINE` clears it and is a one-line change. This
is a naming decision and belongs to a person.

**2. No release has ever been signed.** `SignatureVerifier::PUBLIC_KEY_HEX` is
empty, which means the verifier fails **closed** — the safe direction. The
verification path is fully implemented and tested against runtime keypairs.
Pinning a real public key is a release-time step needing a key that does not yet
exist.

**3. The registry repository is not published.** Layout, manifest tooling and CI
are complete and tested. Creating a public repository is an external act needing
a person's credentials and publishing something that cannot be unpublished
(D-0045). §17 explicitly permits local completion without it.

**4. `RegistryUpdater` stages a verified release; it does not activate one.**
Swapping the live registry is a separate act and belongs with the apply
machinery, not the download.

**5. Applies in wp-env return exit 3, "applied but not verified."** wp-env runs
the site and the runner in separate containers, so the site cannot reach itself
over loopback (D-0009). The verifier is correct; the environment cannot exercise
it. Verification is covered instead by the fail-probe suite, which forces a
failure and asserts the rollback.

---

## 5. Environment-dependent checks

**The E2E suite is sensitive to concurrent load on this machine, and that is a
property of the machine rather than of the code.**

Run alongside the rest of this gate — the integration suite, PHPCS, PHPStan,
Plugin Check and two zip builds, all against the same Docker daemon — the suite
took 25.3 minutes and two scenarios timed out. Run alone at the same commit, all
thirteen pass.

The evidence that this is contention and not a regression:

- The two failures were a timeout and an element not appearing within one, never
  an assertion about behaviour.
- Both re-ran clean individually on a quiet machine.
- The contended run took 22% longer overall than the clean one.
- CI runs E2E as its own job in `e2e.yml` and has been green twice at
  13 of 13.
- The full suite was then re-run alone at this commit and passed **13 of 13 in
  20.2 minutes**, which is the figure recorded in §2. Splitting a suite into
  pieces and reporting the pieces green is not the same claim as the suite being
  green, so the whole thing was run again rather than the two failures alone.

Recorded rather than dismissed, because "it passes when I run it again" is the
sentence that hides real flakiness, and the next person should know the
distinction was checked rather than assumed.

Everything else in §21.7 ran to completion on this machine. Nothing was skipped.

---

## 6. Deferred, with reasons

| Deferred | Where |
|---|---|
| Server-side PDF in the white-label report | D-0049 — the smallest usable library is ten times the plugin's size |
| Any inbound cloud control path, including remote apply | `CLOUD-DESIGN.md` §2, §12 — a v1 that can command a site is a different product |
| SSO, team roles, webhooks, public API, white-label domains, multi-region, real-time | `CLOUD-DESIGN.md` §12 — eleven items, each with a reason |
| Multisite beyond network defaults | §1 locked decision 8; groundwork exists behind `DEBLOATER_PRO_MULTISITE` |
| Registry activation after staging | Warning 4 above |

---

## 7. External actions not taken

§21.7's boundary, observed throughout. None of these was performed, and each is
implemented behind a tested adapter with fixtures where an integration was
required:

- The repository was not published publicly.
- The plugin was not submitted to wordpress.org.
- No production infrastructure was created. `cloud.hakeemify.com` does not
  resolve.
- No Freemius account exists or was modified. The adapter is tested against the
  platform's **absence**, which is also what a free install looks like.
- No registry release was pushed to a public remote.
- No destructive operation was run against a real site.

---

## 8. Release readiness

**The free plugin is ready to submit.** Plugin Check is clean of errors against
the tree that actually ships, the zip builds reproducibly from an allow-list at
301 files and 522 KB, the readme validates, the POT covers 517 strings, and
`uninstall.php` honours §13 rule 10 — the runtime and loader always go, the
recovery points stay unless the site opted in.

Three things need a person before submission:

1. **Decide the display title** (warning 1). One line.
2. **Generate a signing key and pin its public half.** Until then the registry
   verifier fails closed, which is safe but means updates cannot be adopted.
3. **Reserve `debloater` on wordpress.org** by submitting. The slug is not yet
   claimed, and it is the one thing here that cannot be changed afterwards.

**Pro is feature-complete and commercially unwired.** It needs a Freemius
account and a plan named `pro` or `agency` to unlock anything; without one it
runs and unlocks nothing, which is the correct behaviour and the tested one.

---

## 9. Verdict

**Phases 0–20 complete. No gate is red. No gate went unexecuted.**

The single environment-dependent observation is in §5, and it was diagnosed
rather than waved through.

What is not claimed: that this has run on a real production site, that a release
has been signed, or that anybody has bought it. Those are the next steps, and
each needs a person rather than another phase.
