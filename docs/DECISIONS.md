# DECISIONS.md

Architectural decisions for WP Debloat. One entry per decision, newest last.
Decisions are recorded **before** the code that depends on them is written
(`BUILD-SPEC.md` §0). A decision is only revisited when the specification
changes or a later phase proves it wrong; the revision is a new entry that
supersedes the old one.

Format: `D-NNNN — title` · phase · date · status · context · options · decision · consequences.

---

## D-0001 — JSON Schema validation without a runtime dependency

- **Phase:** 0
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`BUILD-SPEC.md` §3 forbids Composer **runtime** dependencies ("dev deps only; no
runtime deps"). §17 Phase 0 requires `Registry\SchemaValidator` and offers the
choice: use `justinrainbow/json-schema`, vendor a minimal validator, or implement
the subset we use. Registry JSON (tweaks, compatibility, profiles, detectors,
facts, findings) must be validated at runtime inside WordPress, not only in CI,
because §13 rule 5 makes schema validation the barrier between user input and
generated executable code.

### Options considered

1. **`justinrainbow/json-schema` as a runtime dependency.** Complete draft-07
   support, well tested. Rejected: violates the zero-runtime-dependency rule,
   ships ~40 files into every install, and adds a supply-chain surface to a
   plugin whose whole promise is removing weight.
2. **Vendor (copy) `justinrainbow/json-schema` into the plugin.** Same code
   without the Composer edge. Rejected: we would own an unmaintained fork of
   ~4 000 lines to use perhaps 15 % of it, and GPL-compatibility/attribution
   bookkeeping for code we do not need.
3. **Hand-written validator for the draft-07 subset we actually use.**
   Accepted.

### Decision

Implement `WPDebloat\Registry\SchemaValidator` in-house, supporting exactly the
JSON Schema draft-07 keyword subset that our own schemas use:

`$schema`, `$id`, `title`, `description`, `type` (including union types),
`enum`, `const`, `required`, `properties`, `patternProperties`,
`additionalProperties` (boolean and schema), `propertyNames`, `items` (schema),
`minItems`, `maxItems`, `uniqueItems`, `minimum`, `maximum`,
`exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`, `minLength`, `maxLength`,
`pattern`, `format` (`date-time`, `uri`, `email` — advisory, non-failing except
where explicitly enabled), `anyOf`, `oneOf`, `allOf`, `not`, `$ref`
(local `#/definitions/...` and `#/$defs/...` only), `default` (ignored during
validation), `minProperties`, `maxProperties`.

Unsupported keywords are a **hard error** at schema-load time, not a silent
pass: an author who writes a keyword the validator does not implement gets
`UnsupportedSchemaKeyword` rather than an unvalidated document. Remote `$ref`
is refused (no network, §13 rule 9).

Validation returns a list of `SchemaViolation` value objects with JSON-pointer
paths, so registry authors get actionable messages and so REST parameter
rejection (§13 rule 3) can report which key failed.

### Consequences

- Zero runtime dependencies preserved.
- The validator itself must be tested to the same standard as the schemas: a
  dedicated unit suite covers each supported keyword, valid and invalid, plus
  the unsupported-keyword error.
- Adding a keyword to a registry schema requires adding support here first.
  That friction is intentional — it keeps the schemas within a subset we can
  guarantee.

---

## D-0002 — Contracts are final, readonly, and validate on construction

- **Phase:** 0
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`BUILD-SPEC.md` §17 Phase 0 requires `src/Contracts/*` as final immutable value
objects with `fromArray()`/`toArray()` and strict validation throwing
`ContractViolation`. The contracts cross every layer boundary (scan → analyze →
recommend → apply → verify → meter) and are serialized into `runs.payload`.

### Decision

- Every contract is `final readonly class` with constructor property promotion.
- **All** validation happens in the constructor, so an instance that exists is
  valid by construction. `fromArray()` only maps and delegates; it never
  validates separately, which removes the possibility of two divergent rules.
- `fromArray()` rejects unknown keys. A typo in persisted JSON is a
  `ContractViolation`, not a silently dropped field.
- `toArray()` is the inverse of `fromArray()` for every contract; a round-trip
  test asserts `fromArray( $x->toArray() ) == $x` for every contract.
- Enumerations that appear in contracts (`severity`, `risk`, `decision`,
  `category`, probe `status`, snapshot level/status, run type) are PHP backed
  enums, not strings, so an invalid value cannot be constructed at all.
- Floats bounded to a range (`confidence`) are validated inclusively and stored
  as `float`; integers are never accepted where a float is required and vice
  versa (`1` is accepted for a float field and cast, `1.5` is rejected for an
  integer field).

### Consequences

- Deserialization of an old `runs.payload` written by a future/incompatible
  version fails loudly. Repositories are responsible for catching
  `ContractViolation` and marking such a run unreadable rather than crashing a
  page load.
- No setters anywhere; "changing" a contract means constructing a new one via a
  `with*()` helper, which each contract provides only where a layer genuinely
  needs it.

---

## D-0003 — Local toolchain runs PHP and Composer through Docker

- **Phase:** 0
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

The build machine (Windows 11) has Node 24 and Docker Desktop but **no native
PHP and no native Composer**. `BUILD-SPEC.md` requires PHPUnit, PHPCS, PHPStan
and later `@wordpress/env`, all of which need PHP. `@wordpress/env` requires
Docker regardless, so Docker is on the critical path either way.

### Options considered

1. Install PHP + Composer natively on the build machine. Rejected as the default:
   it mutates the developer's machine outside the repository, and the resulting
   PHP version/extension set would not match CI.
2. **Run PHP and Composer in pinned Docker images.** Accepted.

### Decision

All PHP tooling runs in Docker against the repository mounted at `/app`:

- `php:8.2-cli` for PHP, PHPUnit, PHPCS and PHPStan (extensions verified
  present: `dom`, `mbstring`, `xml`, `tokenizer`, `json`, `zlib`, `sodium`).
- `composer:2` for dependency resolution.
- `@wordpress/env` (Docker) for integration tests from Phase 1 onwards.

`composer.json` pins `config.platform.php` to `8.1.0` so resolved dev
dependencies remain valid on the lowest supported PHP version even though the
container runs 8.2.

### Consequences

- The recorded PHP version for local runs is 8.2; the 8.1 and 8.3 legs of the
  matrix are exercised in CI (GitHub Actions), not locally. `docs/TEST-RESULTS.md`
  records which PHP produced each result.
- Helper wrapper scripts live outside the repository (in the build scratchpad) so
  that `BUILD-SPEC.md` §4's exact folder structure is not polluted with
  machine-specific tooling.
- `sodium` availability is confirmed now because Phase 17 needs Ed25519
  verification without a runtime dependency.

---

## D-0004 — Coding-standard exclusions and their justification

- **Phase:** 0
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`BUILD-SPEC.md` §3 requires PHPCS with WordPress-Extra and WordPress-VIP-Go
plus PHPStan level 6. Several WordPress sniffs conflict directly with
requirements the specification states elsewhere, and one tool simply predates
the PHP version the specification targets. Silently suppressing them per-line
would hide the conflict; excluding them in `phpcs.xml.dist` with the reason
written next to the exclusion keeps it visible.

### Decision

Five exclusions, each tied to a specific requirement:

1. `WordPress.Files.FileName` — `composer.json` uses PSR-4, which the
   specification fixes in §3. WordPress file naming cannot apply to `src/`.
2. `WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid` — §17
   Phase 0 mandates `fromArray()` / `toArray()` on every contract. Under
   §21.1 the specification outranks the convention. Local variables and
   properties remain `snake_case`, which the rest of the rule still enforces.
3. `WordPress.WP.CapitalPDangit.MisspelledInText` — `wordpress` is a
   lowercase category identifier in §6, not a misspelling of the product name.
4. `WordPress.PHP.DevelopmentFunctions.error_log_var_export` — §10 requires
   tweak parameters to be emitted into the generated runtime with
   `var_export`. It is a code-generation tool here, not stray debugging.
5. `WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown`
   — every `file_get_contents` call reads registry JSON from inside the plugin
   directory. §13 rule 9 forbids outbound HTTP entirely and a test asserts it,
   so the VIP caching advice is inapplicable.

Two further exclusions are tool limitations rather than judgements:

6. `PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext`
   and `PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.SelfOutsideClassScopeFound`
   — PHPCompatibility 9.x (the current release, from 2019) predates PHP 8.1
   enums and parses every enum method body as being outside class scope. Both
   `$this` and `self` are valid there. These exclusions are to be removed when
   PHPCompatibility ships enum support.

Test-only exclusions (`WordPress.WP.AlternativeFunctions`,
`WordPress.DB.DirectDatabaseQuery`, `WordPressVIPMinimum.Functions.RestrictedFunctions`,
`WordPressVIPMinimum.Performance.FetchingRemoteData`) apply because the unit
suite runs with no WordPress loaded, so the WordPress wrappers those rules
require do not exist in that context.

### Consequences

- The standard stays otherwise unmodified; nothing is suppressed inline.
- PHPStan runs at level 6 with `treatPhpDocTypesAsCertain: false`, so the
  narrowing checks do not fire on `mixed` boundaries where the PHPDoc is a
  claim about untrusted input rather than a fact.
- PHPStan was moved to 2.x during Phase 0 because 1.x is unmaintained and its
  configuration options are deprecated; `szepeviktor/phpstan-wordpress` moved
  to 2.x with it.

---

## D-0005 — The generated runtime carries no timestamp

- **Phase:** 1
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

Two statements in `BUILD-SPEC.md` cannot both hold literally:

- §10 sketches the generated file with a header reading
  `/* WP Debloat runtime — generated 2026-09-02T18:34:00Z — selection a1b2c3… */`.
- §17 Phase 1 requires a test proving that "regenerating twice yields
  byte-identical file".

A timestamp in the file makes every regeneration differ.

### Decision

`Compiler::compile()` produces deterministic, timestamp-free source. The header
keeps the parts that are both useful and stable — the selection hash and the
registry hash — and the generation time moves to `runtime.lock`, which already
exists to hold the runtime hash and is not itself hashed.

### Consequences

- Byte-identical regeneration is a real, testable property rather than one that
  holds only if you squint past the header.
- The runtime hash means "this file is exactly what this selection compiles
  to", which is what makes an unexpected diff evidence of tampering rather than
  of the clock having moved.
- `runtime.lock` gains `generated_at`, `selection_hash`, `registry_hash` and
  `plugin_version` alongside `runtime_hash`, so provenance is not lost — it is
  recorded somewhere it does not corrupt a hash.

This is a deviation from an illustrative example in the specification, not from
a requirement; the requirement (byte-identical regeneration) is honoured
exactly.

---

## D-0006 — What a runtime handler may and may not call

- **Phase:** 1
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`BUILD-SPEC.md` §10 says handlers "must not read options or touch the
database". `core.disable_self_pingbacks` needs to know the site's own address in
order to tell an internal link from an external one, and `home_url()` reads the
`home` option.

### Options considered

1. **Compile the home URL into the handler's parameters.** Satisfies the letter
   of the rule. Rejected: the URL is then frozen at generation time, and a site
   that changes domain silently starts self-pinging again, with no error and no
   sign that anything is wrong. A safety rule that produces silent wrong
   behaviour is worse than the thing it was protecting against.
2. **Allow the handler to call `home_url()` at hook time.** Accepted.

### Decision

The rule is stated precisely rather than loosened:

> A runtime handler may call core WordPress functions that resolve the site's
> own identity or configuration, where the tweak's semantics require it. It may
> not read WP Debloat's own state, the registry, or any option of its own, and
> it may not issue a database query.

`home_url()` qualifies: `home` is an autoloaded core option that WordPress has
already loaded by the time any hook fires, so the call adds no query. The hook
in question, `pre_ping`, fires only when a post is published, never on a
front-end read.

The invariant that actually matters is unchanged and still tested: an empty
selection registers no hooks and adds no queries to a front-end request, and no
handler touches WP Debloat's own storage. `LoaderTest` asserts statically that
no handler contains `get_option(`, `update_option(`, `get_transient(`, `$wpdb`,
a namespace declaration, or a reference to the autoloader.

---

## D-0007 — Loader strategy, fallback, and deferred bypass authorisation

- **Phase:** 1
- **Date:** 2026-09-02
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §16 ("Phase 1: loader strategy and fallback
  behavior when mu-plugins is not writable")

### Context

The generated runtime should load before other plugins, which means an
mu-plugin. `wp-content/mu-plugins` is not writable on every host, and §10
specifies a fallback. Separately, §10 describes a `?wpdebloat=off` bypass that
"requires a valid nonce for logged-in users with the capability" — but at
mu-plugin time WordPress has not yet loaded `pluggable.php`, so neither
`current_user_can()` nor `wp_verify_nonce()` exists.

### Decision

**Loader.** On activation, and whenever the runtime is rewritten,
`Apply\RuntimeLoader::install()` copies `mu-loader/wp-debloat-loader.php` into
`mu-plugins` through a temp file and an atomic rename. The three outcomes are
named and reported:

| Mode | Meaning |
|---|---|
| `mu-plugin` | The loader is installed and runs before plugins load. |
| `fallback` | `mu-plugins` was not writable. The plugin includes the runtime itself on `plugins_loaded` at priority −999. |
| `none` | Nothing is selected, so there is no runtime to load. |

The mode is stored in `wpdebloat_state.loader_mode` and reported by
`GET wpdebloat/v1/status`, so the dashboard and `RuntimeLoadedProbe` can warn
that the fallback is in use rather than behaving subtly differently in silence.
The fallback repeats the loader's hash check, so it is exactly as strict as the
mu-plugin it stands in for. Deactivation removes both the runtime and the
loader: hooks registered by a deactivated plugin would be indistinguishable
from a haunting.

**Kill switch.** `WPDEBLOAT_DISABLE` in `wp-config.php` is absolute and needs no
authentication — being able to set it already implies full access, and it has
to work when the site is too broken to reach the admin.

**Query bypass.** `?wpdebloat=off` is authenticated, so it cannot be honoured at
mu-plugin time. `WPDebloat_Runtime_Guard::bypass_allowed()` therefore:

1. returns false immediately if no bypass was requested;
2. if `current_user_can()` and `wp_verify_nonce()` exist (the fallback path, or
   an unusual load order), checks capability **and** nonce and returns the
   answer;
3. otherwise records the request as deferred and returns false — the runtime
   registers normally.

`RuntimeLoader::resolveDeferredBypass()` finishes the job at `plugins_loaded`:
if the request really is from a user with `wpdebloat_manage` and carries a
valid `wpdebloat_bypass` nonce, every handler the runtime registered is
unregistered.

### Consequences

- The bypass is not in effect for the handful of hooks that fire before
  `plugins_loaded`. Nothing user-visible does, and the alternative — trusting an
  unauthenticated query parameter that early — would be a hole large enough to
  drive a denial-of-service through.
- The capability is required as well as the nonce, so a link cannot lead an
  administrator into disabling the runtime without meaning to.
- `unregister()` on every handler stops being test-only scaffolding and becomes
  the mechanism the bypass depends on, which is why the integration suite
  asserts that unregistering restores the hook table exactly.

---

## D-0008 — Two PHPUnit versions: 10.5 for unit, 9.6 for integration

- **Phase:** 1
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`BUILD-SPEC.md` §3 specifies "PHPUnit 10 (unit, no WP), `@wordpress/env` + WP
PHPUnit for integration". Those turn out to be two different runners, because
the WordPress core test suite does not work on PHPUnit 10: `WP_UnitTestCase`
calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which PHPUnit 10
removed. Every integration test errors identically:

```
Error: Call to undefined method PHPUnit\Util\Test::parseTestMethodAnnotations()
/wordpress-phpunit/includes/abstract-testcase.php:592
```

This is not a version we chose: it is what WordPress 6.5's own test suite
supports. `wp-env`'s container ships PHPUnit 10.39 and hits the same wall.

### Options considered

1. **Downgrade everything to PHPUnit 9.6.** Rejected: §3 asks for PHPUnit 10 for
   the unit suite, and the unit suite has no reason to be held back by a
   limitation of the WordPress test harness.
2. **Write our own WordPress test case instead of `WP_UnitTestCase`.** Rejected:
   we would be reimplementing factories, transaction rollback between tests, and
   the hook reset, all to avoid a version number.
3. **Two runners.** Accepted.

### Decision

- **Unit suite**: PHPUnit 10.5, installed by Composer, configured by
  `phpunit.xml.dist`. Run with `composer test`.
- **Integration suite**: PHPUnit 9.6.22 as a phar, configured by
  `phpunit-wp.xml.dist` against the 9.6 schema. Run with
  `npm run test:integration`.

The phar cannot be a Composer dependency, because it would have to displace
PHPUnit 10 in the same dependency tree. `tools/download-phpunit.mjs` fetches it,
verifies it against a pinned SHA-256, and the phar itself is gitignored.
`yoast/phpunit-polyfills` is pinned to `^2.0` — the only line that supports both
9 and 10 — because the WordPress test bootstrap requires it.

### Consequences

- Two configuration files, on two schemas. Each says at the top why it exists.
- Tests must stay within what both versions support. In practice that means
  static data providers and no PHPUnit 10 attributes, neither of which is a
  loss.
- The pinned phar version needs bumping when WordPress moves to PHPUnit 10; at
  that point both suites collapse back into one runner and this entry can be
  superseded.
---

## D-0009 — Docker DNS configuration on the build machine

- **Phase:** 1
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`@wordpress/env` could not start on the build machine, for two separate
reasons, both DNS.

1. The machine's system resolver is `127.0.0.1`, a local proxy that refuses
   queries from Node. `dns.lookup()` works (it goes through the OS), but
   `dns.resolve()` fails. wp-env uses `dns.resolve('WordPress.org')` to decide
   whether it is offline, concluded that it was, and refused to start.
2. Containers and image builds inherited the same `127.0.0.1` resolver, so
   `curl https://getcomposer.org/installer` inside the `tests-wordpress` image
   build returned nothing and the build failed.

Both are properties of the machine, not of the project. Without them, no
integration test in any phase can run, and `BUILD-SPEC.md` §21.3 would have
required stopping at Phase 1.

### Decision

1. wp-env is invoked with `NODE_OPTIONS=--require <scratchpad>/dns-shim.cjs`, a
   two-line module calling `dns.setServers(['1.1.1.1','8.8.8.8'])`. It lives
   outside the repository, because it is a fact about this machine and would be
   noise in a checkout on any other.
2. `%USERPROFILE%\.docker\daemon.json` gained `"dns": ["1.1.1.1","8.8.8.8"]`.
   The original file was copied to `daemon.json.wpdebloat-backup` first.

### Consequences

- Integration and E2E tests can run locally.
- The Docker change is machine-wide and outlives this build. It is an additive
  DNS fallback, reversible by restoring the backup, and recorded here so it is
  not a mystery later.
- Neither change touches the plugin, the tests, or CI. On a machine with a
  working resolver, `npm run env:start` works with no shim.

---

## D-0010 — Confidence penalties and score weights (rubric v1)

- **Phase:** 3
- **Date:** 2026-09-02
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §16 ("Phase 3: confidence penalty values;
  score weights")

### Context

§6 says confidence is "base confidence × penalties (unknown host, detected
dependents, cache plugin present, custom code detected)" and §12 fixes the
severity penalties at 0/4/10/20 with "weights in rubric; v1 equal". The
multipliers themselves are left to this phase.

### Decision

Recorded in full in `docs/SCORING.md` v1.0. In summary:

| Penalty | Multiplier |
|---|---|
| Unknown host | × 0.95 |
| Cache plugin present | × 0.95 |
| Detected dependents | × 0.90 each, compounding, capped at three |
| Custom mu-plugins | × 0.90 |

Two bounds beyond the specification, both chosen so the figure keeps meaning
what it says:

- **The dependent penalty stops compounding at three.** Past that point the
  message is already "several things depend on this"; letting it run would drive
  confidence towards zero and turn a caution into a de-facto refusal, which is
  what `dont_touch` is for and what it should stay for.
- **A floor of 0.30.** Below that the honest answer is not a small number, it is
  that the change should not be recommended at all.

Sub-score weights are equal, as §12 specifies for v1. The alternative would be
inventing a claim about which category matters more, with nothing behind it.

### Consequences

- The multipliers are deliberately mild. Confidence is an honesty signal shown
  next to a recommendation, not a mechanism for quietly withdrawing one; a
  penalty large enough to hide a finding would be doing the refusal's job
  badly.
- Confidence is rounded to two decimals so the same site always prints the same
  figure, and the whole calculation is deterministic.
- Our own mu-plugin loader is excluded from the custom-code check. Penalising
  confidence for having installed WP Debloat would be absurd.

---

## D-0011 — Removing a capability and affecting one are different things

- **Phase:** 3
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`DontTouchRules` maps a finding to the capability its tweak would change, and
refuses the finding when a component present on the site declares a dependency
on that capability.

The first implementation used one map, and it produced a wrong answer
immediately. WooCommerce declares a dependency on `heartbeat` — correctly; the
checkout keep-alive needs it. `core.heartbeat_interval` was therefore refused on
every WooCommerce site. But that tweak does not remove Heartbeat. It slows it
from 15 seconds to 60. The dependency is still satisfied afterwards.

The unit tests caught it: a store with a single recent editor was being refused
by the dependency rule, when only the collaborative-editing rule should have
applied.

### Decision

Two maps, with different consequences:

- **`REMOVES_CAPABILITY`** — the tweak takes the capability away entirely. A
  present dependent is a **refusal**, naming the dependent.
- **`AFFECTS_CAPABILITY`** — the tweak changes how the capability behaves
  without removing it. A present dependent counts towards
  `dependencies_detected`, which **lowers confidence**, and does not refuse.

`wp.heartbeat.aggressive` is in the second map. It moves to the first the moment
a tweak exists that switches Heartbeat off rather than slowing it — which
`BUILD-SPEC.md` §7.1 already anticipates as `core.heartbeat_disable`.

### Consequences

- Refusing a change of degree is left to situational rules, which can weigh how
  the site is actually used. That is exactly what §17 Phase 3 specifies for
  Heartbeat: two or more recent editors *and* WooCommerce.
- A dependency that does not refuse still costs confidence, so nothing is
  ignored — the site with WooCommerce gets a less certain recommendation, not a
  silently identical one.
- The capability vocabulary stays coarse (present or absent), which is what
  makes it possible to reason about exhaustively. The nuance lives in which map
  a finding is in, where it is visible and testable, rather than in the
  vocabulary.

---

## D-0012 — Two findings report and do not act

- **Phase:** 3
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`BUILD-SPEC.md` §17 Phase 3 requires three info-only findings: inactive plugins
present, file editor enabled, XML-RPC enabled. Two of those are things a
hardening guide would tell you to change, and it would be easy to offer a
one-click fix for both. This entry records why we do not.

### Decision

**File editor.** Disabling it means adding `DISALLOW_FILE_EDIT` to
`wp-config.php`. WP Debloat does not edit `wp-config.php`, and will not. A
plugin that rewrites the file every request depends on is a plugin that can take
a site off the internet by getting one line wrong; no amount of care makes that
a good trade for a setting the user can change in ten seconds. The finding
explains the risk and gives the exact line.

**XML-RPC.** Disabling it is the most-recommended WordPress hardening step and
one of the easiest to get wrong. Jetpack uses it. The mobile apps use it. Some
backup plugins use it. On the sites where it is used, switching it off breaks
something the owner will not connect to a change made in a different plugin last
week. Deciding that safely needs the compatibility resolver and the intent
profile from Phase 4.

**Inactive plugins.** A deactivated plugin is not loaded, so there is no
performance case at all — claiming one would be exactly the kind of invented
benefit this product exists not to make. There is a maintenance case, and the
finding makes it. Deleting a plugin is not reversible from here and is not WP
Debloat's decision.

### Consequences

- Three findings that cost nothing in the score and propose nothing. That is the
  correct output, not a placeholder: an honest "here is what we found, and here
  is why we are not touching it" is worth more than a confident switch.
- XML-RPC may become actionable in a later phase once the resolver can tell
  whether anything is using it. The file editor will not.

---

## D-0013 — Which of two conflicting tweaks survives

- **Phase:** 4
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`BUILD-SPEC.md` §7.4 requires that two conflicting tweaks are never both in a
plan. It does not say which one to keep, and the first implementation kept
whichever came first by tweak id — which is deterministic and otherwise
arbitrary.

The property tests caught the consequence: under the safe profile a
medium-risk tweak was filtered out, so its lower-id conflicting partner got in;
under the maximum profile the medium-risk one was admitted first and won. A
*wider* profile therefore produced a plan missing something the narrower one
had offered, which is a surprising thing for "include more" to do.

### Decision

Candidates are ordered before anything is decided, and the first one considered
wins a conflict:

1. **Non-destructive before destructive.** Between two changes that cannot both
   be applied, the one that deletes nothing is the better default.
2. **Lower risk before higher.** Same reasoning, one step down.
3. **Tweak id**, so the result is deterministic when the first two tie.

### Consequences

- Widening a profile now only ever adds to a plan, which is what a user
  reasonably expects from "include more kinds of change".
- The rule is a property of the tweaks rather than of the caller's argument
  order, so a plan is reproducible from its inputs alone.
- A registry author who wants a specific tweak to win a conflict says so by
  giving it a lower risk, which is a claim they have to be able to justify —
  rather than by naming it earlier in the alphabet.

---

## D-0014 — A requirement is only satisfied by a tweak that is in the plan

- **Phase:** 4
- **Date:** 2026-09-02
- **Status:** Accepted

### Context

`BUILD-SPEC.md` §7.4: no tweak with unresolved `requires` may enter a plan. The
first implementation checked requirements against the **candidate** list, which
is what the planner was handed before any filtering.

The property tests found the hole immediately: a tweak whose requirement was
itself excluded — refused, filtered by profile, or dropped for its own unmet
requirement — still passed the check, because the requirement had been a
candidate. The plan then contained a change that depended on something that was
not going to happen.

### Decision

A requirement counts only when the required tweak is **in the plan**. Since
excluding one tweak can leave another's requirement unmet, and requirements can
point in either direction, the planner runs a fixed point: drop tweaks with
unmet requirements, and repeat until nothing more drops out.

Each dropped tweak's exclusion reason names the requirement *and* quotes why
that requirement is missing, so a chain of exclusions can be read back to its
cause rather than appearing as an unexplained absence.

### Consequences

- Planning is O(n²) in the worst case, over a set that is at most a few dozen
  tweaks. Correctness here is worth considerably more than the microseconds.
- A tweak with a requirement the profile excludes is now excluded too, which is
  right: applying it alone would do something other than what the registry
  author described.

---

## D-0015 — Level B spills to a gzipped file above eight megabytes

- **Phase:** 5
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §4, §8, §17 Phase 5

### Context

A Level B recovery point holds the exact rows a data operation is about to
delete. On a site that has never been cleaned, "delete expired transients" can
be tens of thousands of rows, and a future operation over post revisions can be
far larger. `BUILD-SPEC.md` §4 puts the overflow in
`wp-content/wpdebloat/backups/` as gzipped JSON "when > 8 MB" and leaves the
exact mechanism to this phase.

Keeping everything in `wpdebloat_snapshot_items` is simplest and is what happens
for the overwhelming majority of runs. It stops being reasonable at scale for
three reasons: a single snapshot of hundreds of thousands of rows makes every
query against that table slower for every other snapshot; the rows are read
exactly once, in bulk, and only if something goes wrong; and hosts with a small
`max_allowed_packet` start rejecting the inserts, at which point the operation is
correctly refused but the user simply cannot run it at all.

### Decision

**8 MB (8 × 1024 × 1024 bytes) of uncompressed item payload**, measured as the
sum of `strlen( Json::encode( $item->payload ) )` as the items are collected.

The threshold cannot be applied before collection begins, because an operation
declares how many rows it will touch, not how large they are. So items
accumulate in memory until the running total passes the threshold, at which
point everything held so far is flushed to a gzipped file and the remainder
streams straight to it. Below the threshold nothing touches the disk.

The file is newline-delimited canonical JSON, gzipped at level 9, named
`snapshot-<id>.ndjson.gz` and written mode 0600 into a directory carrying both an
`index.php` and an `.htaccess` that denies access. Reading streams line by line,
so restoring a large snapshot does not require holding it in memory either.

The snapshot row records `storage = 'file'` and the path; the checksum is
computed the same way in both cases — each item digested individually, the
digests sorted, and the concatenation hashed — so a snapshot verifies
identically whether it came from the table or the file.

### Consequences

- Two storage paths to keep correct, both exercised by integration tests that
  cross the real threshold rather than lowering it for the test.
- A spilled snapshot depends on the filesystem as well as the database. A
  missing or truncated file fails verification loudly and marks the snapshot
  corrupt; it never silently restores the rows that survived.
- `SnapshotManager::forget()` deletes the file alongside the row, so a removed
  snapshot leaves no orphan.
- The threshold is a constant rather than an option. A site that needs it lower
  needs a smaller `max_allowed_packet` accommodation, which is a support
  conversation, not a setting; adding a knob here would mean two thresholds to
  test and a way for a user to make their own recovery worse.

---

## D-0016 — Recovery points are never expired automatically

- **Phase:** 5
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §8, §16

### Context

The obvious retention policy is "keep snapshots for N days, then delete them",
and every backup product has one. The question for this phase is whether WP
Debloat should.

### Decision

**No automatic expiry.** Snapshots and their spill files are kept until the user
removes them, or until the plugin is uninstalled with cleanup enabled (Phase 15).
`SnapshotStatus::EXPIRED` exists in the enum because §8 defines it, and nothing
in the plugin sets it on a timer.

### Consequences

- A recovery point is the only route back from a change. Deleting one on a
  schedule means deciding, on the user's behalf, when their site stopped being
  worth recovering — and the decision would necessarily be made without knowing
  whether the change had been noticed yet. Somebody who applies a tweak in March
  and finds the broken page in June is exactly the person retention would have
  failed.
- The storage cost is bounded in practice: Level A snapshots are a few kilobytes,
  and Level B ones exist only for data operations, which are rare and which the
  user runs deliberately.
- Consequently the plugin must never *rely* on old snapshots disappearing. The
  restore path checks the site hash and the checksum on every restore, so an old
  snapshot restored onto a site it did not come from is refused rather than
  applied.
- If a site does accumulate large recovery points, the answer is a visible list
  with a delete action (Phase 8), not a silent sweep.

---

## D-0017 — The lock is what distinguishes a crashed run from a running one

- **Phase:** 5
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §9.2

### Context

`BUILD-SPEC.md` §9.2 requires that "a run found in APPLYING/VERIFYING on next
boot is auto-rolled-back and marked `INTERRUPTED`". Taken literally on every
admin request, that rolls back the apply that is running *right now*, in another
request, halfway through its work — which is a considerably worse outcome than
the crash it is meant to recover from.

### Decision

Crash recovery runs on `admin_init`, after the schema check, and refuses to act
while the apply lock is held by anyone. The lock has a 60-second TTL and is
refreshed by a live apply; a process that died stops refreshing it, so within a
minute the lock is gone and the run in `APPLYING` is unambiguously abandoned.
Recovery then takes the lock itself for the duration of the rollback.

### Consequences

- A crashed run is recovered on the first admin page load more than a minute
  later, rather than instantly. Nobody is waiting on it, and the site is in the
  state the crash left it in either way.
- No request ever rolls back another request's live work.
- A rollback that itself fails leaves the run `INTERRUPTED` with the reason
  recorded on it, rather than looping: the next boot finds it in `INTERRUPTED`,
  which is not a crash-recoverable state.

---

## D-0018 — Every journalled transition comes from the transition table

- **Phase:** 5
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §9.1

### Context

§9.1 ends with "every transition writes a journal row". The straightforward
implementation is for each site of the apply to call
`$journal->applied( $run_id, $id, FROM, TO )` with the states it believes are
right. Writing the first draft that way immediately produced rows the state
machine would have rejected — `APPLIED → ROLLED_BACK` and
`COMMITTED → ROLLED_BACK`, neither of which is an edge in §9.1 — because the
caller knew where the tweak should end up and not how it was supposed to get
there.

A journal that records transitions the machine forbids is worse than no journal:
it reads as authoritative and is not.

### Decision

`Apply\TweakLifecycle` is the only thing that writes tweak transitions. Callers
say where a tweak should end up; it asks `TweakStateMachine::pathTo()` for the
shortest route the table permits, walks it one edge at a time through a real
machine, journals each edge, and stores the final state. A target with no legal
route is journalled as a skip and the stored state is left alone.

### Consequences

- `SNAPSHOTTED → ROLLED_BACK` is journalled as
  `SNAPSHOTTED → APPLY_FAILED → ROLLED_BACK`, and undoing a committed tweak as
  `COMMITTED → REVERT_REQUESTED → ROLLED_BACK`, which is what §9.1 says happens.
- The journal is auditable against the table, and an integration test does
  exactly that for every row it writes.
- Adding a state to §9.1 changes the routes automatically; nothing has a
  hard-coded edge to update.

---

## D-0019 — Which markers prove a page rendered

- **Phase:** 6
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §11, §16

### Context

A probe has a status code and a blob of HTML. From those it has to answer "did
this page render", and its answer decides whether the user's change is kept or
undone. Both kinds of mistake are expensive: a marker that misses a broken page
leaves a site broken, and a marker that fires on a working page rolls back a
change that was fine.

### Decision

**Fatal markers** — any of these, matched case-insensitively as whole phrases,
makes the probe FAIL whatever the status code says:

- `Fatal error`
- `Parse error`
- `There has been a critical error`
- `Error establishing a database connection`
- `WP_Error Object`
- `object(WP_Error)`

**Render markers** — a page that is otherwise fine but lacks these is a WARN,
not a FAIL:

- `</html>` — the only genuinely universal proof that a whole document arrived.
  A response truncated by a fatal with `display_errors` off cannot have one.
- `<title` — present on essentially every real theme, but a theme is allowed to
  be strange, so its absence warns rather than fails.

**Dashboard markers** — `id="wpbody"` and `id="adminmenu"`, both required. They
have been in core's admin markup for over a decade and are not theme-dependent,
because the admin is not themed.

**Login-form markers** — `id="loginform"` or `name="log"`. Used two ways: their
presence on the login page is the proof it rendered, and their presence on
`/wp-admin/` means the credential did not work, which is UNKNOWN rather than a
verdict on the site.

### Deviation from §11, deliberate

§11 lists `WP_Error` among the fatal markers. Matched as a bare class name it
fires on any page that merely mentions the class: a tutorial, a changelog, a
release note, this plugin's own documentation pages. The consequence of that
false positive is rolling back a change that was working perfectly, on a site
whose only offence was writing about WordPress.

What actually indicates a broken page is a `WP_Error` that has been **printed**,
so the marker is the printed forms — `WP_Error Object` from `print_r()` and
`object(WP_Error)` from `var_dump()`. The intent of §11 is preserved; only the
false-positive surface is removed. A unit test asserts both that the printed
forms are caught and that prose about `WP_Error` is not.

### Consequences

- Marker lists live in one class, `Verify\Markers`, rather than being spread
  across six probes, so this decision is a diff of one file if it turns out to
  be wrong.
- The lists are conservative on the FAIL side and generous on the WARN side,
  which matches the asymmetry of the outcomes: a warning costs a sentence in a
  report, a failure costs the user their change.

---

## D-0020 — What happens when the site cannot reach itself

- **Phase:** 6
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §11, §9.2

### Context

Verification is loopback HTTP: the site asking itself for its own pages. Plenty
of hosts do not allow that — outbound requests blocked at the firewall, DNS that
does not resolve the site's own name from inside, a container that cannot route
back to itself. On those sites every probe fails identically, and for a reason
that has nothing to do with the change just applied.

Three responses were possible: treat it as a failure and roll back; treat it as
a pass and commit quietly; or report it as unknown.

### Decision

**Every HTTP probe reports UNKNOWN, the aggregate becomes WARN, and the run ends
`VERIFIED_WITH_WARNINGS` with the change kept and the user told plainly that the
checks could not run.**

Loopback is checked once, up front, by asking for the home page. If that fails,
the remaining probes are not attempted at all: they would each spend fifteen
seconds discovering the same thing, and a ninety-second verification that ends
in "we could not tell" is worse than a one-second one.

### Consequences

- A blocked-loopback site still gets snapshots, rollback and the journal. What
  it does not get is automatic verification, and it is told so rather than being
  left to assume the checks passed.
- Rolling back would have been the "safe-looking" choice and is in fact the
  wrong one: it would undo good work because of a firewall rule, on every apply,
  forever, on a site where nothing is wrong.
- Committing silently would be worse still — the user would believe the site had
  been checked.
- `UNKNOWN` counting as a warning in the aggregate is what makes this work, and
  it is why `UNKNOWN` and `NOT_TESTED` are separate statuses: "we could not
  check" warns, "there was nothing to check" does not.
- A probe that throws is also UNKNOWN, for the same reason: our bug is not
  evidence about their site.

**Amended in 19b-1b.** This decision covers a site that cannot reach itself. It
was also, wrongly, the outcome on sites whose loopback worked perfectly: the
admin probe sent the wrong cookie, was answered with the login form, and
reported UNKNOWN under this rule @@ so no apply on any site ever reached
`VERIFIED`. The rule is unchanged and was never the problem; what was missing
was the credential. See D-0058.

---

## D-0021 — `--format=json`, with `--json` as the spelling everyone uses

- **Phase:** 7
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §17 Phase 7

### Context

§17 Phase 7 specifies `--json` on the read commands. Implemented literally — a
`[--json]` entry in each command's synopsis — every invocation fails:

```
$ wp debloat scan --json
Error: Parameter errors:
 unknown --format parameter
```

WP-CLI reserves `--json` as shorthand for `--format=json` and rewrites it during
argument parsing, before a command is dispatched. A command that declares
`--json` itself therefore never sees it, and is handed a `--format` it did not
declare.

### Decision

Each command declares `[--format=<format>]` with the options `table` and `json`.
WP-CLI's rewriting then makes `--json` work exactly as the specification
describes, and `--format=json` works too, which is what a WP-CLI user would try
first.

The check is `wantsJson()`, which accepts either the resolved `format` or a
plain `json` boolean — the latter for callers that construct the command object
directly, which is how the integration suite drives it.

### Consequences

- The user-facing behaviour §17 asked for is unchanged: `--json` prints JSON.
- The synopsis in `wp help debloat scan` says `--format`, which is the
  convention every other WP-CLI command follows.
- Documented in `docs/CLI.md` so the two spellings are not a surprise.
- Found only by running the commands through a real `wp` binary. The unit-style
  tests drive the command objects directly and could never have caught it, which
  is the argument for `tools/cli-e2e.sh` existing at all.

---

## D-0022 — What a configuration document carries, and what it refuses to

- **Phase:** 7
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §17 Phase 7, §13 rule 5

### Context

`wp debloat export` produces "the config-as-code JSON (selection + intent profile
+ params)" and `import` "validates it before use". The question worth deciding is
what else, if anything, travels with it — a site's findings and score are right
there, and including them would make the export look more complete.

### Decision

**Choices travel; conclusions do not.** The document carries the selection with
its parameters, the stated intent, and provenance (plugin version, registry hash,
site hash, timestamp). It does not carry findings, facts, scores, snapshots or
run history.

Provenance is recorded and never enforced. A document from another site is the
entire use case, so `site_hash` is informational. A document from a different
registry version produces a warning rather than a refusal, because the individual
changes are validated anyway.

Import validates in three stages, in this order:

1. The whole document against `schemas/config.schema.json`, before a single value
   is read out of it (§13 rule 5).
2. Each named change against the registry: does this version have it, and are its
   parameters valid for it.
3. The resulting selection through the ordinary planner, so the §7.4 invariants
   apply exactly as they would to a plan built here.

A change the file names that this version does not have is reported and skipped;
the rest of the file still applies.

### Consequences

- A findings document from staging can never be imported and acted on in
  production. Findings describe one site at one moment; transplanting them would
  be transplanting conclusions drawn from facts that are not true here.
- Importing is not a way around the rules. A change that would be refused on this
  site is still refused, and the reason is printed.
- The schema lives in `schemas/`, not `registry/schemas/`, because it does not
  describe registry content — §4 names exactly six registry schemas and a
  repository invariant holds it to six.

---

## D-0023 — Plain React hooks, and no state library at all

- **Phase:** 8
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §16, §17 Phase 8

### Context

§16 requires this phase to record how the dashboard manages state, and §17
rules out external state libraries. The remaining choice is between
`@wordpress/data` — the store WordPress ships, which the block editor uses — and
plain component state.

### Options considered

1. **`@wordpress/data`.** Registered stores, selectors, resolvers, and a
   published API other plugins could read. Rejected: it is designed for state
   that many unrelated components need at once and that outlives a screen. This
   dashboard is one screen with four views, and every piece of state it holds is
   either a server response or which view is open. The store would be ceremony
   around a fetch.
2. **An external library — Redux, Zustand, Jotai.** Ruled out by §17 and by the
   bundle budget, and unnecessary for the same reason as above.
3. **Plain hooks, with one shared `useResource`.** Accepted.

### Decision

Component state via `useState`, and one hook — `useResource` — for everything
that comes from the server. It returns a named status (`loading`, `ready`,
`error`), the data, the error, and a `reload`.

The status is the point. A screen that tracks `data` and `isLoading` separately
renders "no findings" for the half second before the findings arrive, which is a
lie told by accident on the one screen whose entire purpose is not to overstate
what it knows.

There is no router either: this is a single admin page, and the browser's back
button belongs to WordPress's navigation rather than ours.

### Consequences

- The bundle is 6.7 KB gzipped of JavaScript, against a 250 KB budget, because
  React, the components and the i18n runtime all come from WordPress.
- Two screens showing the same data fetch it twice. On this screen that is two
  requests, and it removes an entire category of stale-cache bug.
- If a later phase needs state shared across genuinely unrelated components, this
  decision is worth revisiting rather than working around.

---

## D-0024 — A confirmation token, not just a nonce

- **Phase:** 8
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §13 rule 12, §17 Phase 8

### Context

§17 Phase 8 requires the restore action to take "an explicit confirmation
token", and that the UI perform no state change without a confirmation step. The
straightforward reading is a boolean — `confirm: true` — which makes the request
deliberate but says nothing about *what* was confirmed.

The gap that matters: a user previews a plan, reads it, and clicks apply. In
between, a plugin is activated in another tab, or a colleague runs a scan. The
plan is now different. A boolean confirmation applies the new plan under the old
agreement.

### Decision

Write endpoints take a token derived from the exact thing being acted on:

- **Apply** — an HMAC over the canonical JSON of the plan, issued by `preview`.
  The server rebuilds the plan, recomputes the token, and refuses on a mismatch
  with "this site has changed since that preview".
- **Rollback** — an HMAC over the recovery point's id, run and checksum, issued
  by `snapshots`. A token for one recovery point cannot restore another.

Both are keyed on `wp_salt()`, so a token cannot be constructed by anything that
has not already read the site's secrets.

This is in addition to the capability check and an explicit nonce check on every
state-changing route — the nonce proves the request came from this site's admin,
the token proves it was the thing the user was shown.

### Consequences

- The three questions are answered separately and can each be tested separately:
  may this user do it, did this request come from our screen, and is this the
  thing they agreed to.
- A stale preview fails loudly instead of applying something unseen. The user is
  told to preview again, which is the honest instruction.
- Requiring a nonce on write routes means they cannot be driven by an
  application password. That is deliberate: automation has WP-CLI, which is a
  better fit and leaves a clearer trail.
- `scan` counts as state-changing — it writes a run — so it needs the nonce too.
  A Phase 3 test that posted to it without one now sends one, and a new test
  asserts the refusal.

---

## D-0025 — What the Meter refuses to say

- **Phase:** 9
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §12

### Context

§12 fixes the metric list and ends with four words that shape the whole
implementation: "never reported as time saved". The interesting decisions are
therefore about what the reporting layer must *not* do, because each of them is
a way a plugin flatters itself and each is easy to write by accident.

### Decision

Four rules, each with a test that fails if it stops being true.

1. **A metric that could not be measured has no value.** `Measurement` carries
   `null` and a reason, never a zero. The naive implementation — start at 0, add
   what you find — reports a site whose loopback is blocked as having gone from
   forty requests to none, which would be the most flattering possible lie.
2. **A missing "after" produces no delta.** Not a fall to zero, not an omission:
   the row appears with "not measured" and the reason.
3. **A metric that did not move is still reported.** A report that lists only
   improvements is an advertisement.
4. **No percentage from zero.** There is no honest percentage change from
   nothing, and "infinite improvement" is not a number.

Direction is reported as `down` / `up` / `unchanged`, not `better` / `worse`.
Fewer requests is usually good; fewer scheduled events sometimes is not, and the
comparison has no business deciding which.

`frontend.*` metrics are summed across the pages §12 names — home, the newest
content page, and the dashboard — because the metric is "what this site asks
browsers to load", not "what one page does". Each reading records which pages it
covered, so a before and an after taken over different pages cannot silently
produce a delta.

`admin_ajax_requests_per_hour` is the one derived metric, and it reports the
interval and the number of administrators it used, so the arithmetic can be
checked rather than trusted.

### Consequences

- Reports on well-behaved sites are less impressive than they could be, and are
  true.
- The dashboard's report shows the score before and after by scanning again
  after the change. The score is derived from findings, so the only honest
  "after" is a fresh look at the site rather than arithmetic on the old one.
- No metric measures time, and none ever will: page-load time on somebody else's
  host depends on their hosting, their network and their visitors.

---

## D-0026 — What counts as an orphan, per meta type

- **Phase:** 10
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §16, §17 Phase 10

### Context

§17 Phase 10 requires this definition to be written down **before** the code
exists, and the reason is worth stating: "orphan metadata" sounds like a fact
about the database and is actually a judgement. Every wrong answer here deletes
somebody's data, and the plugin's own recovery point is the only thing between a
wrong definition and a lost row.

The dangerous case is not the row whose parent is gone. It is the row whose
parent is *not gone but is not where we looked* — a custom table, a soft delete,
an object type registered by a plugin that was inactive when the scan ran.

### Decision

An orphan is a metadata row whose parent object **does not exist in the table
that WordPress itself joins against**, checked with a `LEFT JOIN … IS NULL`
against that table and nothing cleverer.

| Meta table | Parent | Orphan when |
|---|---|---|
| `postmeta` | `posts.ID` | No row in `posts` with that `post_id`. Every post status counts as existing, including `auto-draft`, `trash` and `inherit` — a revision's meta is not an orphan just because the revision is a revision. |
| `termmeta` | `terms.term_id` | No row in `terms` with that `term_id`. Term *relationships* are not consulted: a term with no posts is an empty term, not a deleted one. |
| `usermeta` | `users.ID` | No row in `users` with that `user_id`. |
| `commentmeta` | `comments.comment_ID` | No row in `comments` with that `comment_id`. Spam and trashed comments still exist, so their meta is not orphaned. |

Three exclusions apply to every type, and each of them is a row that a naive
`LEFT JOIN` would happily delete:

1. **`user_id = 0` in `usermeta`, and any `*_id = 0`.** Zero is not a missing
   parent, it is a sentinel several plugins use deliberately.
2. **Multisite.** Orphan cleanup does not run on a multisite install in v1. User
   meta is shared across the network there, and "no row in this site's tables"
   is not the same question at all.
3. **Anything that arrived after the recovery point was written.** A row written
   moments ago is far more likely to be half of an operation still in progress —
   metadata inserted before the object it belongs to — than the leftover of one
   that finished years ago.

   Meta tables carry no timestamp, so this is enforced by a **collection
   ceiling** rather than a time window: `collect()` records the highest primary
   key it saw, and `execute()` will not delete anything above it. That turns out
   to be the stronger rule, and it applies to every operation in this phase
   rather than only to metadata.

   The hole it closes is real and was found while writing `OrphanMetaCleanup`:
   `collect()` writes the recovery point and `execute()` then asks the database
   again for what matches, so a row that came to match *in between* would have
   been deleted without ever having been backed up. On a busy site that is not
   hypothetical — a post is trashed, a comment is marked as spam, a plugin
   writes metadata a moment before creating its parent. A ceiling of zero means
   `collect()` found nothing, and nothing is what `execute()` may then delete:
   §13 rule 8 is about *this* run's recovery point, not one that exists in
   principle.

### Consequences

- The definition under-deletes on purpose. A row this misses costs disk space; a
  row it should not have deleted costs somebody their data.
- It is a plain `LEFT JOIN`, so it can be read, checked and disagreed with by
  anyone who knows SQL. A cleverer definition that nobody can audit would be
  worse even if it were more accurate.
- Every orphan removed is stored first, with its `meta_id`, and restored with
  that same `meta_id` — so a round trip is indistinguishable from never having
  run.
- On multisite the finding still reports what it sees and the operation refuses
  to run, rather than quietly reporting nothing.

---

## D-0027 — Level C is an attestation, never a substitute

- **Phase:** 10
- **Date:** 2026-09-03
- **Status:** Accepted
- **Spec:** §12 rule 8, §17 Phase 10

### Context

The destructive confirmation offers a checkbox: "I have an external backup".
§17 Phase 10 requires it, and requires that it never substitutes for the Level B
snapshot. The temptation is obvious — a user who has their own backup does not
need ours, and skipping it would make a large deletion much faster.

### Decision

The checkbox records a statement and changes nothing else. Level B is taken
whether or not it is ticked; a destructive operation with no complete Level B
snapshot is refused with the box ticked exactly as it is without it.

What the attestation is for: it is written into the run so that a later
conversation about a deletion has a record of what the user believed at the
time. That is worth having. It is not worth a single skipped backup.

### Consequences

- Ticking the box makes nothing faster, which some users will find pointless.
  The alternative is a plugin whose safety depends on a stranger's claim about
  infrastructure it cannot see.
- The attestation is recorded on the **run**, not as a snapshot row. A Level C
  "snapshot" would be a recovery point that contains nothing and can recover
  nothing — a row in the recovery-points table that lies about what it is. What
  the user stated belongs in the history of the change, next to what was done.
- The refusal test does not care about the attestation, which is the point: it
  passes a ticked box and still expects the refusal.

---

## D-0028 — "Already handled by host" is a claim the facts contradict

- **Phase:** 11
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 11
- **Deviates from:** the literal wording of that phase

### Context

Phase 11 asks for `plugins.host_optimizer_detected` to mark overlapping tweak
findings as `info` with the reason "already handled by host". The intent is
sound: a site whose host ships its own optimizer, or which runs a cache plugin
with the same switches built in, should not be nagged about ground somebody else
already covers.

Implementing it revealed that the reason is false at precisely the moment it
would be shown.

Every finding this could apply to is observation-backed. `wp.emojis.loaded`
fires because `wp.emojis_enabled` is true — because the emoji script is on the
page. If the other optimizer had handled it, the fact would be false and there
would be no finding to downgrade. So a finding that survives to the point of
being marked "already handled" is, by construction, one that nothing has
handled. Marking it `info` would also understate a cost the site is really
paying, and the score exists to be believed.

### Decision

Keep the phase's intent and drop its wording.

- A finding on ground another present tool also offers a setting for **gains a
  sentence** naming that tool and where its setting lives.
- It keeps its severity, its decision and its recommendation. The choice stays
  with the user, who now knows there are two places to make it.
- `plugins.host_optimizer_detected` still exists, still `info`, and reports which
  optimizers are present.
- The claim made is "there is another place to change this", which does not
  depend on how the other tool's switch is currently set — something WP Debloat
  cannot read and will not guess at.

The case the original wording was reaching for — an optimizer WP Debloat would
actively *fight* with, where leaving it alone is right regardless of what the
scan observed — is a refusal, and refusals already have a home: the compatibility
registry, where they carry their reason and produce `dont_touch`.

### Consequences

- `registry/host-optimizers.json` says what a layer *offers*, never what it has
  done. Its `covers` list is deliberately short: an entry belongs there only when
  the setting is visible in that product's own interface.
- `Finding::withAddedReasoning()` is added — the analyzer already amends a
  finding's confidence, decision and risk after the rule returns; this amends the
  one field a person reads.
- A registry test asserts every `covers` id is a finding id some rule actually
  produces. The first draft named `wp.emojis.enabled`, which is the *fact* key;
  the finding is `wp.emojis.loaded`. Nothing failed — the feature was simply a
  no-op that looked implemented.

---

## D-0029 — Network consent is given per scan, not stored

- **Phase:** 11
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §13 rule 9, §17 Phase 11

### Context

Looking up a plugin's release date needs wordpress.org, and §13 rule 9 allows no
outbound HTTP except loopback and opt-in. The obvious shape is a stored setting:
a checkbox that, once ticked, makes every future scan reach out.

### Decision

There is no stored setting. `Plugin::scan()` takes a boolean, `wp debloat scan`
takes `--check-plugin-updates`, and `POST /scan` takes `check_plugin_updates`,
default false. The flag is cleared after the scan that used it.

Two reasons.

1. **Consent is for an action.** A person ticking a box in a settings screen in
   March has not agreed to a request made in September by a cron job they forgot
   about. Asking at the moment of the action is a stronger promise and a simpler
   one to test: with the flag off, zero requests, asserted directly by counting
   `pre_http_request`.
2. **It must not travel.** The configuration document `wp debloat export` writes
   is meant to be committed and applied to other sites. Network consent given on
   one site is not consent on another, so it deliberately has no place in that
   document — which is why it is not a stored setting in the first place.

### Consequences

- Phase 17's registry updates will need their own consent, and should follow the
  same shape rather than inventing a global "allow network" flag.
- `plugins.update_source` records which reading produced the staleness figure,
  so a finding can be worded — and scored — for the reading it actually had. The
  local fallback is a different claim in different words at a third of the
  confidence.

---

## D-0030 — Registry tables, and where their schemas live

- **Phase:** 11
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §4, §17 Phase 11

### Context

The registry has been one document per object: one tweak, one detector, one
compatibility rule, one profile per file, each validated by one of the six
schemas §4 names. Phase 11 adds `registry/plugin-categories.json` — a lookup from
plugin slug to functional category. A file per plugin would be forty files each
holding a single word.

### Decision

Registry *tables* are a second shape: a single file holding a lookup, with its
own schema in `registry/schemas/`. Two exist — `plugin-categories.json` and
`host-optimizers.json`.

The invariant that used to count six schemas now asserts the exact set by name,
which is stricter: six object types plus two tables, and nothing else. The number
was only ever a proxy, and one that had to be edited every time the truth
changed.

`schemas/config.schema.json` stays where it is. It describes a document `wp
debloat export` writes, not registry content, and the distinction is worth
keeping.

### Consequences

- A table is optional. A registry without one loads and yields an empty table, so
  the rules that read it simply have nothing to say.
- Both tables are in the registry hash. A plan's determinism claim is "same
  facts, same profile, same registry", and a category map that could change
  without changing the hash would break that claim quietly.
- Tables hold identifiers and names only. What running two page caches costs is
  reasoning, and reasoning lives in the analyzer — the registry says what a
  plugin *is*.

---

## D-0031 — WP Debloat cannot tell promotion from warning, so it says so

- **Phase:** 12
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 12

### Context

§17 Phase 12 asks for `admin.suppress_promo_notices`, driven by an allowlist of
"third-party notice hooks" for WooCommerce, Elementor, Yoast, Rank Math and
Jetpack. The intent is clear and worth having: these plugins interrupt every
admin screen, and a lot of it is marketing.

The problem is that they do not separate the two. WooCommerce's `admin_notices`
callbacks carry both an upsell and "your database needs updating". Yoast routes
most of what it says through one notification centre. There is no hook, filter
or flag that reliably says "this one is an advert", and building the feature as
though there were would mean hiding a database-update warning and calling it
promotional.

Two ways to be dishonest were available: ship a filter list guessed from
plausible-looking names, or ship the feature under a title that implies a
precision it does not have.

### Decision

Ship the tweak with the id §17 gives it, and make everything a person reads
accurate.

- The **title** is "Hide admin notices from plugins you choose", not "Hide
  promotional notices".
- The **description** says it does not tell marketing from warnings and why.
- `breaks` names what will be missed: pending database updates, expiring
  licences.
- The **risk is medium**, which keeps it out of "Fix Safe Issues" entirely.
  Hiding another plugin's warnings is not something one click decides.
- Selection is **per vendor**, so nothing is hidden that a person did not
  choose after reading the above.
- The allowlist entry for each vendor carries its own `notes` saying what that
  particular plugin sends down the channel, and those notes are shown rather
  than filed.

The mechanism is source-based rather than hook-name-based: a notice callback is
removed only when the file it is defined in lives inside one of the plugin
directories the entry names. That needs no guesswork about internal APIs, cannot
be used to silence a plugin the user did not select, and fails safe — a callback
that cannot be attributed is left alone, because leaving a notice showing is the
better error.

### Consequences

- `registry/admin-notices.json` is an allowlist, and the tweak's parameter
  schema takes its `enum` from that file's source directories. A slug outside it
  is refused by schema validation before it can reach generated code (§13
  rule 5), which is asserted directly.
- The rule offers this only when three or more notices come from allowlisted
  plugins that are actually printing on this site, so it never proposes
  silencing something silent.
- If a vendor later publishes a real "no marketing" filter, that is a better
  mechanism and the entry should move to it. The registry shape allows for that
  without changing the tweak.

---

## D-0032 — Admin tweaks are not in any profile

- **Phase:** 12
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §7.4

### Context

Four of the five Phase 12 tweaks are `safe` and fully reversible, which would
normally make them candidates for the safe profile and therefore for "Fix Safe
Issues".

### Decision

None of them is in any profile. They are selected individually or not at all.

"Safe" in §7.4 means the change cannot break the site. These cannot. But "Fix
Safe Issues" is one click, and the person clicking it is not necessarily the
only person who uses the admin. Removing a widget from a shared dashboard, or
changing what an editor sees when they log in, is a change to somebody else's
working day, and it should be chosen rather than swept up.

`admin.remove_dashboard_widgets` could not be in a profile in any case: a
profile carries no parameters, and the whole question is which widgets.

### Consequences

- The tweaks are offered on the findings screen with their evidence, and picked
  one at a time.
- A future "Admin" profile, if one is ever wanted, would be a deliberate thing
  with its own name rather than these quietly joining `safe`.

---

## D-0033 — The asset scan reads a sample, and every fact says so

- **Phase:** 13
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 13

### Context

A site can have a hundred thousand URLs. §17 gives the asset scan ten seconds
and a ceiling of ten requests, so it reads the home page plus the most recent
entry of each public post type.

That is a sound sample — the differences between pages follow post type far more
than they follow individual posts — but it creates a way to be badly wrong. "No
Contact Form 7 form on any page" is true of four pages and says nothing about
the contact page, and a change made on that basis breaks exactly the page the
plugin was installed for.

### Decision

The sample size is a fact, it travels with every other fact in the namespace,
and the rules that read it say so in the words they show a person.

- `assets.pages_sampled` is what was actually fetched; `assets.pages_offered` is
  what the sample chose before fetching began. A gap between them means
  something did not answer, or the budget ran out.
- The Contact Form 7 finding is worded "Of N pages sampled…", never "on every
  page", and its confidence is capped at 0.75 — the ceiling is the sample, not
  the parsing, which is exact.
- Phase 13 adds **no unloading tweaks at all**, which §17 already requires and
  this makes a second reason for: acting on a sample is where the harm would be.

### Consequences

- A rule that wanted to say "this loads on every page" would need a different
  fact, and there is not one. That is deliberate.
- The asset scan checks loopback once and gives up if the site cannot reach
  itself, rather than making ten requests that will each time out. It reports
  `assets.available = false` with the reason, which a rule reads as "not
  observed" rather than "nothing found".
- There is a wall-clock budget across the whole asset scan, so a slow site
  produces a smaller sample rather than a slower scan. The smaller sample is
  visible in `pages_sampled`.

---

## D-0034 — "No outbound HTTP" was the wrong way to say it

- **Phase:** 13
- **Date:** 2026-09-03
- **Status:** Accepted
- **Supersedes:** the assertions written for Phase 11
- **Required by:** `BUILD-SPEC.md` §13 rule 9

### Context

Phase 11 added tests asserting that a scan makes **zero** HTTP requests. They
passed, and they were checking the wrong thing. §13 rule 9 has always allowed
loopback; what it forbids is traffic leaving the server. The zero-request
assertion happened to be equivalent only because nothing had needed loopback
yet.

Phase 13 needed it, and four tests failed for a change that was entirely within
the rule they were meant to be defending.

### Decision

The assertions now state the promise that is actually being made: **nothing
leaves this server** unless the user asked for it on that action. Every request
URL must start with the site's own home URL.

This is not a weakened test. It is a stricter one, because it keeps holding
however many loopback requests a later phase adds, and because it would still
fail on the thing that actually matters — a request to a host that is not this
site.

The one exception stays explicit: with `--check-plugin-updates`, requests to
`api.wordpress.org` are allowed, and a test asserts that every off-site request
goes there and nowhere else.

### Consequences

- A test that passes for an accidental reason is worth less than it looks, and
  the way to find out is to change something legitimate and see what breaks.
- Later phases that add loopback traffic — asset fetching, probes — will not
  need to renegotiate this.

---

## D-0035 — Licensing is provider-agnostic; Hakeemify Cloud is optional

- **Phase:** 14 (architecture; implemented in Phases 17 and 19)
- **Date:** 2026-09-03
- **Status:** Accepted
- **Supersedes:** the v0.4.2 amendment's requirement that
  `cloud.hakeemify.com` host a WP Debloat licensing service, and the earlier
  multi-subdomain topology it in turn superseded.

### Context

Two architecture instructions arrived in quick succession. The first replaced a
four-subdomain topology (`license.`, `api.`, `registry.`, `app.`) with a single
`cloud.hakeemify.com` carrying versioned paths, including a licensing service.
The second superseded the licensing half of that: Pro licensing goes to a
third-party platform (Freemius), and Hakeemify Cloud becomes an optional service
layer rather than the licensing authority.

Neither had been implemented when the second arrived. The repository contained
no host, no licensing code and no entitlement code of any kind — the only
`hakeemify.com` strings anywhere are JSON Schema `$id` identifiers, which are
names rather than addresses and are never fetched. So this is recorded as a
decision taken before the code, not as a migration.

### Decision

> WP Debloat Pro licensing is provider-agnostic and initially integrates with a
> third-party licensing platform such as Freemius. Hakeemify Cloud is an
> optional substantive service layer and is not the mandatory licensing
> authority.

> Where Hakeemify Cloud is used, it is one public hostname,
> `cloud.hakeemify.com`, with versioned, product-scoped path namespaces. DNS
> separation is intentionally minimized; security and data separation are
> enforced at the application and service boundary.

Concretely, and written into `BUILD-SPEC.md` §13 rules 13–15 and §17 Phases 17,
19 and 20:

- Entitlement is read through an `EntitlementProvider` interface, first
  implemented by a `FreemiusEntitlementProvider`. No Freemius symbol appears
  outside that adapter; no feature asks anything but the interface.
- Any server-backed feature goes through a `CloudServiceClient` interface whose
  `HakeemifyCloudClient` resolves every path from one base under
  `/v1/wp-debloat/`. There is no second host.
- A cloud endpoint whose real purpose is license validation is prohibited. The
  cloud earns its existence with work that cannot be done locally, or it does
  not exist.
- No private key, payment secret or global API secret is ever in a distributed
  package. Free WP Debloat works with no Pro, no licensing platform and no
  cloud; a cloud outage is never destructive; security fixes are never
  license-gated.

### Why the interfaces are not being written yet

Phase 14 is intelligence. Creating `EntitlementProvider` and
`CloudServiceClient` now would put five empty files in the tree that nothing
implements and nothing calls, which `CLAUDE.md` names specifically as something
not to do. The boundary is a real requirement, so it is recorded where
requirements live — the specification, as an exit criterion of Phase 19 — and it
will be built when there is a Pro plugin to build it into.

What *is* enforced from today is the part that can rot silently: a repository
invariant asserting that no distributed code depends on any of the superseded
hosts, that a cloud host never appears as a hard-coded string outside a resolver,
and that no private key or API secret is in the package. Those are the failures
that would be expensive to discover late, and they cost nothing to check now.

### Consequences

- Phase 17's registry fetch resolves from one pinned origin through one
  resolver, and keeps every existing protection: canonical serialization,
  SHA-256 per file, Ed25519 over the manifest, a pinned public key, schema
  validation, and no executable content ever.
- Phase 19 owns the entitlement and cloud adapters, their fixtures, and the
  tests for expiry, revocation, offline grace, malformed responses, backoff and
  fail-safe behaviour. Those tests are listed there rather than written here,
  because a test for code that does not exist is a test that passes for no
  reason — the thing Phase 13 had just finished removing (see D-0034).
- The four superseded hosts are named in one place, the invariant test, so that
  documentation describing the old topology stays readable as history without
  becoming a live dependency.

---

## D-0036 — Widget attribution by reflection, not by a list of addons

- **Phase:** 14
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 14

### Context

§17 Phase 14 asks for Elementor addon packs to be detected "from
registry/detectors" — the same mechanism the compatibility layer uses for
WooCommerce, Yoast and the rest.

There are hundreds of Elementor addon packs and new ones every month. A detector
list would cover the dozen anybody thought of, report the rest as absent, and
give a site running two unlisted packs an audit that quietly attributed their
widgets to nobody.

### Decision

Attribution is by asking where the widget's class is defined, through the
`Sources` helper Phase 12 already needed for admin callbacks and Phase 13 for
asset URLs. A widget belongs to whichever plugin directory its code lives in.

This covers every addon that exists, including ones nobody has heard of, and it
needs no maintenance. `elementor` and `elementor-pro` keep their detectors —
those are used for compatibility rules and for the fact predicate that gates the
Google Fonts tweak — but the pack list is derived rather than enumerated.

### Consequences

- A widget whose class cannot be reflected reports `unknown`, which is a real
  answer and shows up as its own pack in the audit.
- The three-phase pattern is now a shared helper rather than three
  implementations: `AdminSources` became `Sources` in Phase 13 for exactly this
  reason.

---

## D-0037 — The only Elementor tweak is one Elementor supports

- **Phase:** 14
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 14

### Context

The obvious tweak this phase could ship is "unregister the widgets you do not
use". It is what the audit's numbers make a person want, and it is the reason
§17 says **never disable widgets automatically**.

### Decision

One tweak: `elementor.disable_google_fonts`, which answers
`elementor/frontend/print_google_fonts` — a filter Elementor documents and
supports — with false. Medium risk, because the site's typeface visibly changes,
and the `breaks` list says so.

Nothing unregisters a widget. Elementor has no supported way to remove another
plugin's widget type, and doing it unsupported breaks the editor for every page
already built with one: the design loads, the widget is missing, and the content
is gone from the page the next time anybody saves it. That is unrecoverable from
inside WP Debloat, and no measured saving justifies it.

The general rule this sets, for Phase 15 and after: where a plugin exposes a
supported switch, use the switch; where it does not, report and stop.

### Consequences

- The audit is `info` with no recommendation, permanently.
- The tweak carries `fact:plugins.detected.elementor=true`, making it the first
  shipped tweak with a fact predicate — which changed what
  `DependencyResolverTest` could assert, since a fact-gated tweak is correctly
  held back until there is a scan (§7.4).

---

## D-0038 — One page fetch, several readers

- **Phase:** 15
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §13 rule 9, §17 Phases 13 and 15

### Context

Phase 13 gave the asset scan its own page fetching. Phase 15 needs the same
rendered pages to work out which of them are WooCommerce pages — and scanners
are deliberately isolated: each gets a fresh `FactSet` and cannot read another's
facts.

Left alone, the WooCommerce scanner would have fetched all ten pages again,
doubling a scan's loopback traffic to learn nothing new, and the two scanners
could have disagreed about the same site because they looked at it at slightly
different moments.

### Decision

`SampledPages` fetches once and lends the bodies to every scanner that needs
them. Both the asset scanner and the WooCommerce scanner take it.

Freshness is enforced rather than hoped for: `AbstractScanner::reset()` is a new
lifecycle hook, `ScanRunner` calls it before each scanner runs, and the scanners
that hold a sample forward it to `SampledPages::forget()`. A scan answered with
pages fetched during a previous one would not be an observation of this site now,
and the whole product rests on facts being observations.

### Consequences

- The wall-clock budget and the loopback check moved from `AssetScanner` to
  `SampledPages`, where they belong: they were always about the fetch rather
  than about assets.
- A third scanner that needs rendered pages costs nothing extra.
- `reset()` defaults to doing nothing, so a scanner that reads the site fresh
  each time — which is almost all of them — is unaffected.

---

## D-0039 — A mini-cart is a refusal, not a warning

- **Phase:** 15
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 15, §6 locked decision #6

### Context

`woo.cart_fragments_conditional` is the highest-value change in the registry and
the one most likely to be confidently wrong. WooCommerce's cart-fragments script
makes an uncached admin-ajax request on every page load; on a blog post that is
pure waste.

Unless the theme shows a cart total in its header, which most shop themes do. On
such a site the fragments are what keep that total correct, and making them
conditional leaves a number that never changes until the visitor reloads. It
looks like the shop is broken, and it is the sort of fault a shop owner
discovers from a customer.

### Decision

A mini-cart anywhere off the shop makes this finding `dont_touch`, in
`DontTouchRules`, alongside the Heartbeat refusal from Phase 3.

Not a confidence penalty and not a warning. There is no version of "apply it and
see" that is acceptable on a store, and a warning is something a person can
click past while thinking about something else.

The refusal names the pages it found a cart on, so it can be checked rather than
believed.

### Consequences

- The detection is deliberately generous: a shopping-cart widget class, a
  mini-cart block, a cart-contents element, a cart menu item. A false refusal
  costs a saved request; a false recommendation costs a broken shop.
- Two tests hold the balance: a mini-cart produces the refusal, and a site
  without one still gets the recommendation. A refusal that fired everywhere
  would be as useless as one that never fired.

---

## D-0040 — Every WooCommerce change is verified against cart, checkout and account

- **Phase:** 15
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §11, §17 Phase 15

### Context

Everything WP Debloat does to a store is worth less than one broken checkout.

### Decision

Three probes — `woo_cart`, `woo_checkout`, `woo_account` — fetch WooCommerce's
own pages **as a guest** and assert the markup that makes them work is present.
Both front-end WooCommerce tweaks list all three, so a change that breaks any of
them fails verification and is rolled back rather than committed. A test asserts
that every front-end Woo tweak names all three, so a future one cannot ship
without them.

As a guest, deliberately: an administrator sees a different page — caching
behaves differently, notices appear, some themes render a shop differently for
someone who can edit it. What matters is what a customer gets.

### Consequences

- A store with no cart, checkout or account page reports `NOT_TESTED` rather
  than a pass. There was nothing to check, and claiming a pass would be claiming
  to have checked.
- The blocked-loopback test had to stop asserting that *every* probe reports
  `UNKNOWN`, because a probe that does not apply was never going to run. It now
  asserts that no probe reaches a verdict and that every probe which does apply
  is `UNKNOWN` — which is the claim that was always meant.

---

## D-0041 — The REST root carries no namespace

- **Phase:** 16 (the bug it fixes was shipped in Phase 8)
- **Date:** 2026-09-03
- **Status:** Accepted
- **Severity:** the admin screen did not work at all on a default WordPress

### Context

`Screen::bootstrapData()` handed the admin bundle
`rest_url( 'wpdebloat/v1' )` as its API root, and `client.js` joined paths like
`/status` onto it.

On a site with **pretty permalinks** that produces
`…/wp-json/wpdebloat/v1//status` — a double slash WordPress tolerates. On a site
with **plain permalinks**, which is WordPress's default, `rest_url()` returns
`…/index.php?rest_route=/wpdebloat/v1`, and the same join produces a query
string that matches no route at all.

Every screen showed "No route was found matching the URL and request method."
The plugin was unusable on a default install, and had been since Phase 8.

**Nothing caught it.** 1 140 unit tests and 246 integration tests, including a
whole file of REST route tests, all build a `WP_REST_Request` by hand and
dispatch it. Not one of them ever composed a URL. The first thing to notice was
a browser opening the page in Phase 16 — which is the entire argument for this
phase existing.

### Decision

The bootstrap hands over a **bare** root and the namespace separately. The
client joins them, once, in one place.

`@wordpress/api-fetch`'s root middleware already knows how to join a path onto a
query-string root — it turns a `?` in the path into `&` and strips the leading
slash. It just has to be given a root it can do that with.

### Consequences

- `RestUrlTest` composes the URL exactly as the client does, parses it with
  `WP_REST_Request::from_url()` and dispatches it, **under both permalink
  structures**. It fails on the pre-fix code with three failures and passes on
  the fix; that was checked by reverting.
- The general lesson is recorded here because it will recur: a test that
  constructs the object under test by hand cannot find a bug in how that object
  is addressed. Dispatching a `WP_REST_Request` proves the route works. It says
  nothing about whether anything can reach it.

---

## D-0042 — The end-to-end suite drives the site it is testing

- **Phase:** 16
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §14, §17 Phase 16

### Context

The scenarios need a site with WooCommerce, Elementor and Contact Form 7 on it,
a product to buy and a form to submit. wp-env's *development* environment
already carries the stack; nothing carried the content.

They also need to drive changes: apply, roll back, force a probe failure. Some
of that is only reachable through WP-CLI.

### Decision

- `tools/seed-e2e.php` creates a purchasable product, a page carrying a Contact
  Form 7 form, and a page with a saved Elementor design. It refuses to run
  outside a local environment.
- The suite talks to the site through the browser for anything a person would
  do, and through WP-CLI for the things a browser cannot see — the stored
  runtime hash, whether the lock is held, what a rollback restored.
- WP-CLI exit codes are listed rather than ignored. `wp debloat apply` returns
  **3** when it applied but could not verify, which is what happens on any site
  that cannot reach itself over HTTP — and wp-env is exactly such a site
  (D-0009). Allowing 0 and 3 for an apply is accurate; allowing anything would
  have hidden the failures this suite exists to catch.

### Two things the fixtures taught us

**A fresh WooCommerce is not open.** WooCommerce ships with "coming soon" mode
on, which serves every visitor a launch page. The first version of the checkout
scenario asserted the cart page did *not* say "your cart is empty" — which was
true of the launch placeholder too. It passed while measuring nothing. The
assertion is now positive: the product must be named on the page.

**A block theme has no `form.cart`.** The fixture site runs Twenty Twenty-Five,
where add-to-cart is a block whose markup varies with the template. The scenario
uses WooCommerce's own `?add-to-cart=` URL — which is what the button does — so
it tests the cart and the checkout rather than the theme's choice of element.

### Consequences

- Nothing from this phase ships. `tests/E2E` is a development directory, and a
  repository invariant already asserts that no shipped file references it.
- `wp debloat verify --e2e` prints how to run the suite rather than trying to,
  because the suite is not in the package a person installs.

---

## D-0043 — A registry release is a signed manifest, and everything else is a refusal

- **Phase:** 17
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §13 rule 9, §17 Phase 17

### Context

The registry decides what WP Debloat offers to change about a site. Anything
that can replace it can change what this plugin does to somebody's shop, which
makes "where did these bytes come from" a security question rather than a
packaging one.

### Decision

A release is a git tag plus a manifest: every file with its SHA-256, signed once
with Ed25519 over the **canonical** form of the whole list.

Canonical rather than the bytes on disk, because a signature that broke when
somebody reformatted a file would be a signature nobody maintains — and because
signing a canonical form means key order cannot be used to forge one.

The update path is written as a sequence of refusals with one narrow way
through, and every one of them has a test:

| Check | On failure |
|---|---|
| Did the user ask? | No request is made at all |
| Is a key pinned, and is libsodium present? | Refuse — never "skip the check" |
| Is the signature ours, over the canonical manifest? | Refuse |
| Is the manifest for this product, in a format we know? | Refuse |
| Is every path a plain relative `.json`? | Refuse |
| Does every file's SHA-256 match? | Refuse **the whole release** |
| Does every file parse as JSON? | Refuse |

Three of those deserve their reasons stated.

**Whole-release rejection.** One bad hash rejects everything, not just that file.
A registry half from one version and half from another is a configuration nobody
tested, and it would be assembled silently.

**Paths are checked even though the manifest is signed.** A signing key that
leaks should cost the registry's integrity, not the filesystem. `..`, absolute
paths, drive letters and anything not ending in `.json` are refused before a
byte is fetched.

**JSON only, checked twice.** The path must end in `.json` and the contents must
parse as one. Handlers stay in the plugin; nothing from a remote is executed,
and this code cannot write a `.php` file even if asked to.

### Fail closed with no key

`SignatureVerifier::PUBLIC_KEY_HEX` is empty, because no signing key exists yet.
While it is empty **every update check refuses** — it does not skip verification
for want of something to verify against. A test asserts the constant is empty, so
pinning a real key later is a deliberate change to a test rather than a quiet
edit to a constant.

### Consequences

- The signing key never enters the repository. `tools/registry-manifest.php`
  refuses a key path inside the working tree, and `ServiceArchitectureTest`
  already refuses anything key-shaped in the package.
- `RegistryUpdater` stages a verified release and reports; it does not activate
  one. Fetching and installing in a single call would let a network failure
  halfway through leave a site with half a registry.
- Tests generate a keypair at runtime and never write it down. A test key in the
  repository would be a key in the package, which is the thing being avoided.

---

## D-0044 — Updating the registry is not part of the registry

- **Phase:** 17
- **Date:** 2026-09-03
- **Status:** Accepted

### Context

The update code was first written under `src/Registry/Update/`, which failed a
Phase 0 invariant: `src/Registry` and `src/Contracts` must not call WordPress,
because the unit suite loads them with no WordPress at all. `RegistryUpdater`
needs `wp_remote_get()`.

### Decision

The invariant was right and the placement was wrong. The whole concern moved to
`src/Update/`.

`src/Registry` is *what the registry is* — documents, schemas, a loader, value
objects, all pure. `src/Update` is *how a newer one arrives*, which is a network
operation and a WordPress one. They were never the same thing; putting them
together only looked tidy.

### Consequences

- `src/Registry` stays loadable without WordPress, which is what keeps the unit
  suite honest about the boundary.
- A test that would have been quietly deleted — "loosen the invariant, it is
  only one file" — instead produced a better arrangement. That is the invariant
  doing its job.

---

## D-0045 — The registry repository is prepared, not published

- **Phase:** 17
- **Date:** 2026-09-03
- **Status:** Accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 17, and the build's external-action
  boundary

### Context

§17 asks for `registry/` to become the public repository
`scornik/wp-debloat-registry`, with its own CI.

Creating a public repository is an external act. It needs a person's decision
and their credentials, and it publishes something that cannot be unpublished.

### Decision

The layout, the manifest, the release tooling and the CI workflow are all
written and tested. **The repository is not created and nothing is pushed to
it.**

`registry/` is already shaped to become that repository's root unchanged, and
`.github/workflows/registry.yml` is the CI it will need — validating every JSON
document, checking the manifest describes what is on disk, and running the
plugin's own suites against the registry, because a tweak that validates and
then does nothing is worse than one that fails to parse.

### Consequences

- Nothing in the plugin depends on the split having happened. The vendored
  snapshot is the source of truth until a signed release replaces it, and
  `wp debloat registry` reports the tag it is carrying.
- §17's exit criterion "remote publication is not required for local phase
  completion" is met exactly as written.
- The CI workflow currently lives in this repository and runs against
  `registry/**` here. When the split happens it moves with the directory and
  gains the plugin as a second checkout.

---

## D-0046 — the public name, the wordpress.org slug, and where they may appear

- **Phase:** 18
- **Date:** 2026-09-03
- **Status:** accepted
- **Required by:** `BUILD-SPEC.md` §16 ("Phase 18: public name + wp.org slug")
  and §17 Phase 18 ("apply them only through the Brand class and build config")

### Context

§16 lists this as a decision that has to be recorded rather than assumed, and
until now it has been assumed: the name and the slug have been in `Brand` since
Phase 0 and nothing has ever written them down as a decision.

The slug is the part that cannot be changed later. It is the directory name, the
text domain, the update-check key and the URL — and once a plugin is published,
changing it is not a rename but a new plugin with none of the installs.

### Decision

**Public name:** WP Debloat
**wordpress.org slug:** `wp-debloat`

Both live in `src/Brand.php` and nowhere else:

| Constant | Value | What it decides |
|---|---|---|
| `Brand::NAME` | `WP Debloat` | Plugin header, admin menu, readme title |
| `Brand::SLUG` | `wp-debloat` | Directory, zip, npm package name, `Manifest::PRODUCT` |
| `Brand::TEXT_DOMAIN` | `wp-debloat` | Every `__()` call, the POT filename |
| `Brand::MENU_SLUG` | `wp-debloat` | `?page=` |
| `Brand::PREFIX` | `wpdebloat` | Options, transients, tables, hooks |
| `Brand::REST_NAMESPACE` | `wpdebloat/v1` | REST routes |
| `Brand::CAPABILITY` | `wpdebloat_manage` | Permission checks |
| `Brand::CLI_COMMAND` | `debloat` | `wp debloat …` |

`ReleaseReadinessTest` holds this: the machine-readable identifiers appear
exactly once in the codebase, in `Brand`, and the slug agrees with the entry
filename, the npm package name and the POT filename.

### Two deliberate exceptions

**The text domain is a literal in every `__()` call.** It has to be. WordPress's
string extractor reads source text rather than running it, so
`__( 'x', Brand::TEXT_DOMAIN )` produces a POT entry with no domain and a
translation nobody ever sees. The test therefore exempts the last argument of a
gettext call, and asserts instead that every one of them is `wp-debloat` and
none is anything else — which is the property that actually matters.

**The product name appears inside sentences.** Roughly twenty findings say
things like "WP Debloat will not deactivate or delete anything". Those were left
as they are.

The alternative is `sprintf( __( '%s will not deactivate or delete anything' ),
Brand::NAME )`, and the argument for it is rebrandability. But no rebrand is
planned, the strings are translatable copy rather than identifiers, and turning
twenty sentences into placeholder templates to serve a hypothetical is the
speculative abstraction `CLAUDE.md` rules out. The identifiers are the part
where a stray literal is a silent bug — a mismatched option name looks exactly
like a setting that will not save. A product name inside a sentence is a
sentence somebody would have to rewrite anyway, in every locale, on the day a
rebrand actually happens.

`runtime-handlers/runtime-guard.php` carries one further literal: the capability
name. A runtime handler has no autoloader by design (§10), so it cannot see
`Brand` at all. `ReleaseReadinessTest` asserts its copy still equals
`Brand::CAPABILITY`, which is the closest thing to a single definition that a
file with no dependencies can have.

### Consequences

- The slug is now fixed. `wp-debloat` on wordpress.org, and nothing in the
  codebase repeats it.
- A rebrand means editing `Brand`, the plugin header, the readme title, and
  about twenty translatable sentences. That is written down here so nobody has
  to discover it.
- The name is **not** reserved on wordpress.org. Reserving it means submitting
  the plugin, which is an external act outside this build's boundary (see
  D-0045 for the same reasoning about the registry repository).

---

## D-0047 — public name and slug: Debloater

- **Phase:** 18a
- **Date:** 2026-09-03
- **Status:** accepted
- **Supersedes:** D-0046, which recorded "WP Debloat" / `wp-debloat`
- **Required by:** `BUILD-SPEC.md` §16 ("Phase 18: public name + wp.org slug")

### Context

D-0046 recorded the name the plugin had carried since Phase 0 and fixed the
slug as `wp-debloat`. Preparing the wordpress.org submission showed that slug
cannot be used.

**"wp" is a restricted term.** wordpress.org's Readme Validator and Plugin Check
reject a plugin name or slug that begins with — and, in the current wording,
that contains — "wp". `WP Debloat` and `wp-debloat` both fail that check, so
neither is submittable. This is not a warning to argue with: it is enforced at
the point of review.

**`debloat` is taken.** The bare slug belongs to an unrelated wordpress.org
plugin that removes unused CSS. It is unused, but a taken slug is a taken slug.

**The search demand is on the word "bloat".** That is the word people type, and
it is the word the subtitle has to carry even though it cannot be the slug.

**"WordPress" is permitted in a display name but not in a slug.** So the
subtitle can say it and the identifier cannot.

### Decision

| | |
|---|---|
| **Display name** | Debloater – Scan, Fix & Undo WordPress Bloat |
| **`Brand::NAME`** | `Debloater` |
| **`Brand::TAGLINE`** | `Scan, Fix & Undo WordPress Bloat` |
| **Slug** | `debloater` |
| **Text domain** | `debloater` |

`Brand::NAME` is the short name, because that is what belongs in a menu item, a
sentence and an error message. The full title — name, en dash, tagline — is
composed for the two places wordpress.org reads it: the plugin header and the
readme title. `Brand::TAGLINE` exists so that composition happens in one place
rather than being typed twice.

The rename is a **full identifier rename**, not a display-only one: namespace,
constants, prefixes, options, transients, database tables, capability, REST
namespace, WP-CLI command, generated paths, the kill-switch query variable, the
verification header, and the must-use loader filename.

### Why a full rename is safe here, and would not be later

There are zero production installs. Nothing anywhere has a
`wp_wpdebloat_snapshots` table, a `wpdebloat_state` option or a
`wp-content/wpdebloat/runtime.php` to migrate, so **no migration is written and
none is needed**. `Storage\Schema` is edited directly.

That window is closing. After the first release the same change would need a
`dbDelta` rename, an option migration, a runtime regeneration and a fallback for
sites that skip a version — which is precisely the reason to do it now rather
than to discover the restricted term after publishing.

### Consequences

- The old identifiers are gone rather than aliased. There is no
  `wp debloat` alias for `wp debloater`, and no back-compat constant. An alias
  would be a compatibility promise made to nobody.
- Tweak ids (`core.*`, `db.*`, `admin.*`, `woo.*`, `elementor.*`) are **not**
  renamed. They are registry data that identifies a change, not brand — and they
  are what a saved selection and every snapshot refer to.
- The "Debloat Score" (§1, locked decision 1) keeps its name. It is the name of
  a measure, it was not part of this brief, and renaming a locked architectural
  term is not a rename task.
- `docs/RENAME-MAP.md` records the mapping, one row per token, so the next
  person can tell a brand rename from a coincidence.
- The GitHub repository rename and the local working-directory rename are
  external acts, done by a person outside this session.

---

## D-0048 — what Plugin Check is allowed to be told, and what it is not

- **Phase:** 18
- **Date:** 2026-09-03
- **Status:** accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 18 ("make the Plugin Check report
  clean")

### Context

Run against what actually ships, Plugin Check reported **407 findings**. Taking
that from 407 to nothing could be done two ways, and only one of them is worth
doing.

The first run also reported 439 errors that were entirely an artefact of
pointing it at the working directory: `.git`, `node_modules`, a PHPUnit phar.
Plugin Check must be run against the staged tree `npm run plugin-zip` produces,
never against the repository. That is now how it is run, and it is worth
writing down because the wrong way looks like it is working.

### Decision

Findings were sorted into three piles, and each pile was handled differently.

**1. Real defects. Fixed.**

| Finding | What it actually was |
|---|---|
| `Generic.PHP.ForbiddenFunctions` (`proc_open`) | `RuntimeWriter::lint()` shelled out to `php -l` as a second syntax check. `token_get_all( $source, TOKEN_PARSE )` — already running one line above — runs the real parser and catches every case `php -l` does. Worse, `proc_open` is disabled on much shared hosting, so on exactly the hosts where a corrupt runtime is hardest to recover from, the "safety net" had been silently doing nothing. Removed. |
| `EscapeOutput.ExceptionNotEscaped` (326) | Chasing this found two genuine holes. `src/Rest/` had **no `try`/`catch` anywhere**, and WordPress does not catch exceptions from a route callback — so any engine throw was a PHP fatal on a REST request. And `recoverOnBoot()` ran on `admin_init` with nothing around it, so a throw during crash recovery would fatal every wp-admin page, locking somebody out of the only screen that could fix it. Both fixed. |
| `UnescapedDBParameter` (30 → 0 in the repositories) | Table names were interpolated into SQL. WordPress 6.2 added the `%i` identifier placeholder and this plugin requires 6.5, so the table name now goes through `prepare()` like everything else. Twenty call sites converted; the 278-test integration suite runs every one of them against real tables. |
| `hidden_files` (9) | `.gitkeep` markers were shipping inside `src/`. The zip builder now excludes dotfiles outright. |
| `missing_composer_json_file` | The zip carried `vendor/` with no `composer.json` beside it. Now ships. |
| `outdated_tested_upto_header` | `Tested up to: 6.8`, while the suite runs against 7.1. Corrected to what is actually tested. |

**2. False for this architecture. Annotated, with the reason and a test.**

Some sniffs assume a plugin shape this one deliberately does not have. Each is
suppressed with a named sniff code and a reason that says what makes it false —
never a blanket `phpcs:disable` and never a silent one.

- `ExceptionNotEscaped`, in 46 files. Escaping at the throw sites would put
  `esc_html()` inside `src/Contracts/` and `src/Registry/`, which a repository
  invariant forbids from calling WordPress at all. §13 rule 4 says escaping
  happens **at the edge**, and it now does:
  `Rest\Controller::guard()` escapes every `Throwable`, `Cli\Command` catches at
  the CLI edge, and `tests/Integration/ExceptionBoundaryTest.php` holds both.
  The annotation points at that test, so the claim is checkable rather than
  asserted.
- `AlternativeFunctions.*` — `WP_Filesystem` cannot do an atomic replace, and it
  prompts for FTP credentials in the middle of an apply. §10.
- `error_log_var_export` — `var_export()` here is the code generator, and §13
  rule 5 names it specifically.
- `slow_db_query_meta_key` — finding orphaned meta *is* querying by `meta_key`.
- `Offloading.OffloadedContent` — the URL is matched so the script that loads it
  can be removed. Nothing fetches it.

**3. Not ours to decide. Left, and reported.**

Two `trademarked_term` warnings on the display title
"Debloater – Scan, Fix & Undo WordPress Bloat": Plugin Check says "wordpress"
"cannot be used at all in your plugin name". `Debloater` and `debloater` are
both clean; only the tagline draws it. The title was chosen deliberately for
search, so it stands until somebody decides otherwise (see D-0047).

### Result

**407 findings → 2 warnings, 0 errors.** The two are the naming decision above.

### Consequences

- Plugin Check is run against `dist/debloater`, not the repository.
  `npm run plugin-check` does the former.
- One thing worth remembering: `wp_json_encode()` does **not** escape `<` or
  `>`. The REST boundary was written assuming it did, and a test caught the
  assumption. The boundary escapes explicitly now.
- A suppressed sniff that later becomes true in a file will stay suppressed.
  That is the cost of a file-level disable, and it is why each one names
  specific sniff codes rather than a whole standard.

---

## D-0049 — no PDF library in the white-label report

- **Phase:** 19
- **Date:** 2026-09-04
- **Status:** accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 19 — "print-CSS HTML first; server PDF
  only if bundled lib size is acceptable"

### Context

The spec left this open on purpose, with a size condition attached. The
condition decides it.

The smallest PHP library that renders usable PDF from HTML is several megabytes
of vendored code. The entire free plugin zip is 522 KB, and the plugin's whole
argument is that it removes weight from a site rather than adding it. Bundling
ten times the plugin's own size so that a browser does not have to be asked to
print is not a trade this product can make and still mean what it says.

There is a second cost that is not about bytes. A PDF library is a large
attacker surface that parses untrusted-ish input, and it is the kind of
dependency that needs watching for CVEs forever. This plugin ships **zero**
runtime Composer dependencies (§3), and the first one should not be one nobody
asked for.

### Decision

**HTML with print CSS. No PDF library, now or as a Pro-only extra.**

The report is a page: the browser's own print dialogue produces a PDF, on every
platform, with the client's own margins and paper size, and with no library to
keep patched. The CSS is inline and about ten lines, because the page is opened,
printed and closed.

### Consequences

- "Export as PDF" is Ctrl-P. If that turns out to be a real objection from real
  agencies rather than an assumed one, the cloud is the place for it — rendering
  server-side through `CloudServiceClient` costs the site nothing and keeps the
  library off it — and that is a Phase 20 conversation, not this one.
- The report contains only measured deltas (§12 invariant 14). White-label means
  the agency's name replaces Hakeemify's; it does not mean the numbers change,
  and where nothing was measured the report says so rather than estimating.

---

## D-0050 — how Pro attaches to the free plugin

- **Phase:** 19
- **Date:** 2026-09-04
- **Status:** accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 19, §13 rules 13, 14 and 15

### Context

Pro has to reach the engine to be useful and must not become part of it. Three
ways to arrange that, and only one of them survives contact with the exit
criterion "Pro adds no tweaks and no safety features".

**Bundle Pro inside the free plugin, gated on a licence check.** Rejected. It
puts commercial code in a GPL upload nobody reviewed, and it makes the free
plugin's behaviour depend on a licence answer — which is how a safety feature
ends up one refactor away from being paywalled.

**Give Pro direct access to the engine's classes.** Rejected. It works
immediately and rots immediately: every internal becomes a public API by
accident, and the first refactor of the resolver breaks a paying customer's
site.

**Documented hooks, and nothing else.** Chosen.

### Decision

Five extension points, all in `docs/HOOKS.md`, all tested by
`tests/Integration/ExtensionPointsTest.php`:

| Hook | Kind | For |
|---|---|---|
| `debloater_loaded` | action | The entry point. Hands over `Plugin`. |
| `debloater_scan_complete` | action | Drift detection |
| `debloater_apply_complete` | action | Reporting, including on rollbacks |
| `debloater_dashboard_panels` | filter | Text panels on our screen |
| `debloater_registry_origin` | filter | The priority channel |

The asymmetry is the design. `debloater_loaded` passes the whole plugin, and
every accessor on it is a getter — an extension can read the resolver, the risk
engine and the snapshot manager, and can replace none of them. There is no hook
to register a tweak, alter a plan, skip a recovery point or change a risk level,
and their absence is the point rather than an oversight.

`debloater_dashboard_panels` accepts **text**, not markup, and strips tags
before the payload is written. An extension that needs an interface of its own
needs a screen of its own, where it is responsible for its own escaping.

`debloater_registry_origin` can move the channel and cannot relax it: a base
`RegistryOrigin` refuses is a base nothing fetches from, an unusable value falls
back to the shipped origin rather than switching updates off, and the manifest
still faces the same signature check either way.

### Consequences

- The free plugin never names a Pro class. `ReleaseReadinessTest` asserts it, so
  the free plugin stays readable and releasable without reference to Pro.
- A new extension point needs, in one commit: the hook, its entry in
  `docs/HOOKS.md`, and a test. A hook without a test is a promise nobody is
  keeping.
- Pro lives in `pro/`, is never in the free zip's allow-list, and carries its own
  hand-written autoloader so it adds no dependency either.

---

## D-0051 — entitlement caching, and a bounded offline grace

- **Phase:** 19
- **Date:** 2026-09-04
- **Status:** accepted
- **Required by:** `BUILD-SPEC.md` §17 Phase 19 — "cached, offline-tolerant
  results"

### Context

Two failures look identical from inside the plugin: a site that is not entitled,
and a site whose licensing platform cannot be reached. Both arrive as "nothing
unlocked". Deciding what to do about that is deciding who absorbs a third
party's downtime.

### Decision

`CachedEntitlementProvider` wraps any provider. A good answer is cached until it
expires. When the wrapped provider then comes back empty, the last good answer
is honoured for a further **14 days** and no longer.

The grace applies **only to a previously good answer**. There is no path from
"never had an entitlement" to "has one", however many times a provider fails.

### Why that way round

Between "somebody who has paid briefly keeps what they bought" and "somebody who
has paid is locked out because a third party had an outage", the first is the
smaller wrong — and it is the one that does not generate a support ticket
blaming this plugin for somebody else's afternoon.

Fourteen days is long enough to cover an outage, a host blocking outbound HTTP,
and a card that needed reissuing. It is not long enough to be a way of not
paying: the window is finite, it does not renew on failure, and once it passes
the answer is empty like any other.

None of this is a security boundary, and it is not trying to be. Somebody
determined can edit PHP on their own server. Pretending otherwise is what leads
to the things the architecture brief rules out — encryption, ionCube,
anti-debugging traps, destructive anti-tamper. This exists to tell an honest
site what it has paid for.

### Consequences

- A licensing outage never stops a site scanning, applying, verifying or rolling
  back. None of those is what was paid for, and none is gated.
- The cached option is **not autoloaded**, matching the free plugin's rule: it
  is read on admin requests and by cron, never on a front-end page view.

---

## D-0052 – the tagline drops "WordPress"

- **Phase:** 18 (post-audit)
- **Date:** 2026-09-04
- **Status:** accepted
- **Amends:** D-0047, which fixed the display title
- **Required by:** `BUILD-SPEC.md` §13 rule 15 and the Phase 18 exit criterion
  "make the Plugin Check report clean"

### Context

D-0047 chose **"Debloater – Scan, Fix & Undo WordPress Bloat"** on the stated
basis that "WordPress" is permitted in a display name and forbidden only in a
slug. That is what the reference material says. It is not what the tool does.

Plugin Check, run against the tree that actually ships:

> The plugin name includes a restricted term. Your chosen plugin name –
> "Debloater – Scan, Fix & Undo WordPress Bloat" – contains the restricted
> term "wordpress" which cannot be used at all in your plugin name.

It was the only finding left after Phase 18 took the report from 407 to 2, and
it was recorded rather than fixed because it is a naming decision and naming
decisions belong to a person.

### Decision

**`Brand::TAGLINE` becomes `Scan, Fix & Undo Site Bloat`.**
The display title is **"Debloater – Scan, Fix & Undo Site Bloat"**.

Nothing else changes. The name is still `Debloater`, the slug is still
`debloater`, and both were already clean.

### What it costs

The word "WordPress" in the listing title. That was there for search: it is the
word people type, and a wordpress.org search result shows the title and little
else.

What is kept is "Bloat", which is the term this category is actually searched
on, and the description below the title still says WordPress in the first
sentence – where it is permitted, and where a search engine reads it.

### Why now rather than at submission

Because it is one line, and because leaving it would mean the final audit
shipping with a known warning attached to a decision nobody had made. A warning
that stays open long enough stops being read.

### Consequences

- **Plugin Check is now clean: 0 errors, 0 warnings.**
- `ReleaseReadinessTest` had an assertion encoding the wrong premise – it
  required the tagline to contain "WordPress". It now holds the tagline to the
  same restricted-term rule as the name and the slug, so the belief that caused
  this cannot be reintroduced quietly.
- D-0047 stands as the record of the rename; this amends only its tagline.

---

## D-0053 – the zip is written in-process, never by an OS tool

- **Phase:** 18b
- **Date:** 2026-09-04
- **Status:** accepted
- **Amends:** the packaging half of Phase 18

### Context

`debloater-0.1.0.zip` shipped and could not be installed. All 302 entries used
**backslash** separators, because the build shelled out to `Compress-Archive`
under Windows PowerShell 5.1. On a Linux host WordPress extracts
`debloater\debloater.php` as one flat file whose *name* contains a backslash,
the plugin directory ends up empty, and activation fails with "Plugin file does
not exist."

Two things about how it shipped are worth recording.

**The build was platform-dependent by design.** It branched on
`process.platform` and used a different tool on each side. Nobody ever built on
Linux, so the Windows branch was the only one that had ever run, and its output
was never compared with anything.

**The verification was blind to the defect.** Python's `zipfile.namelist()` –
and most zip readers – normalise backslashes to forward slashes on read. The
obvious check reported *zero* offending entries on an archive where every entry
was wrong. It was reported as verified in good faith and was not verified at
all.

### Decision

**`archiver`, in-process, on every platform. No PowerShell, no `zip`, no OS
tool, no branch on `process.platform`.**

Entry names are built by joining with a literal `/`, never `path.join`. Explicit
directory entries are written, parents first. There is one top-level folder,
named for the slug.

### How it is held

`tests/Packaging/zip.test.mjs` parses the **central directory bytes** rather
than asking a library for the names, because a test that reads through the same
normalisation as the bug cannot see the bug. It then extracts the archive inside
a Linux container and asserts WordPress activates the result.

CI runs it on **ubuntu-latest and windows-latest**, and a `package-parity` job
diffs the entry listings the two produce. Two platforms agreeing is a stronger
claim than each passing its own checks, and it is the specific thing that was
missing.

### Consequences

- `npm run plugin-zip` builds both plugins; `plugin-zip:free` and
  `plugin-zip:pro` build one.
- `archiver` is a dev dependency. The plugin still ships zero runtime
  dependencies.

---

## D-0054 – `Plugin Name` is the short name, and what that costs

- **Phase:** 18b
- **Date:** 2026-09-04
- **Status:** superseded by D-0056 (2026-09-05)
- **Amends:** D-0052

### Decision

The plugin header reads `Plugin Name: Debloater` and nothing else.
`readme.txt` keeps the full title in its `=== ... ===` line, sourced from the
new `Brand::FULL_TITLE` constant. `ReleaseReadinessTest` asserts both exactly.

The reasoning is that wordpress.org derives the slug from the plugin name, and a
header reading "Debloater – Scan, Fix & Undo Site Bloat" risks a slug like
`debloater-scan-fix-undo-site-bloat` – permanent, unfixable after publication,
and not the slug D-0047 chose.

### The open item

Plugin Check disagrees, and says so:

> Plugin name "Debloater – Scan, Fix & Undo Site Bloat" is different from the
> name declared in plugin header "Debloater".

`mismatched_plugin_name`, one warning, no errors. It wants the readme title and
the header to be identical, which is exactly what this decision makes them not
be.

Both cannot hold. The two ways out:

1. **Header and readme both `Debloater`.** Clears the warning and makes the slug
   unambiguous. The tagline moves to the short description under the header
   block, which is what wordpress.org actually displays as a plugin's one-line
   summary anyway – so little is lost.
2. **Header and readme both the full title.** Clears the warning and accepts
   whatever slug the review process derives.

Option 1 is the better trade and gives up almost nothing. It is not applied
here because the title is a naming decision and the brief for this phase was
explicit that the full title stays in the readme. Recorded rather than decided
unilaterally.

**Resolved:** option 1, on the naming decision being made. See D-0056.

---

## D-0055 – the apply lock carries its own expiry

- **Phase:** 18d
- **Date:** 2026-09-04
- **Status:** accepted
- **Amends:** the lock described in `BUILD-SPEC.md` section 8

### Context

Reported from a live site: every apply refused with "Another change is already
in progress on this site. Wait for it to finish and try again." The last
committed run had finished twenty-five minutes earlier. The lock TTL is sixty
seconds.

It was a deadlock with two halves, and neither is wrong on its own.

**`Lock` was a WordPress transient**, which is two options: `_transient_x`
holding the value and `_transient_timeout_x` holding the expiry. `acquire()`
wrote them with two separate `add_option()` calls. `get_transient()` treats a
value with **no timeout row as one that never expires** – so a request that
died between the two writes, or a second write that failed because a stale
timeout row was already present, left a lock that nothing would ever release.

**`ApplyManager::recoverInterruptedRuns()` steps aside while the lock is held**,
on the reasoning that a held lock means a live apply. That is right in general
and exactly wrong here: the one mechanism that could have cleared the lock was
the one thing the stuck lock prevented. The site could never apply anything
again, and the only thing it ever said was "wait for it to finish".

The class docblock claimed "the worst case is a wait rather than a permanently
stuck site". That claim was false in this code path, which is worth noting on
its own: the reasoning was written down and the code did not implement it.

### Decision

**The token and the expiry live in one value, written once:** `token|expiry`.

`heldBy()` parses it and decides expiry itself rather than inferring it from
another row's presence. There is no second write to lose and no window between
two writes.

A stored value that does **not** parse – a bare token with no expiry – is
treated as free. That is precisely the shape the old scheme left behind, so a
site carrying a stuck lock starts working again on its next request rather than
needing somebody to find an options row and delete it.

The option keeps its `_transient_` name so `delete_transient()` in
`uninstall.php` still removes it.

### Consequences

- The stuck-lock deadlock cannot recur: an expired lock reads as free, so
  recovery runs.
- `tests/Integration/ApplyLockTest.php` holds all of it, including the
  end-to-end deadlock. All eight tests were confirmed to fail against the old
  behaviour before the fix was restored.
- No "clear the lock" button was added. With a real expiry the lock self-heals
  in a minute, and a button that lets somebody break a genuinely running apply
  is a worse failure than the one it would fix.

---

## D-0056 – one name in both files, and the tagline where it is read

- **Phase:** 18e
- **Date:** 2026-09-05
- **Status:** accepted
- **Supersedes:** D-0054

### Context

D-0054 left one thing open: the plugin header said `Debloater` and the readme
title said `Debloater – Scan, Fix & Undo Site Bloat`, and Plugin Check reports
that disagreement as `mismatched_plugin_name`. It recorded two ways out and
took neither, because which name a product carries is not a decision to make
without being asked. Asked, and option 1 taken.

### Decision

**Both files carry `Debloater` and nothing else.**

The slug argument from D-0054 is unchanged and still the reason the header is
the short name: wordpress.org derives the slug from `Plugin Name`, and a
permanent `debloater-scan-fix-undo-site-bloat` is not recoverable after
publication. What D-0054 got wrong was treating the readme title as a display
string that could differ. Plugin Check reads it as a plugin name.

**The tagline becomes the short description**, which is the line wordpress.org
puts under a plugin's name in search results and on the plugin page – so it is
read more, not less, than the title line it left:

> Scan, fix and undo site bloat: audits your site against the facts, applies
> only what you approve, with a recovery point and automatic rollback.

143 characters, inside the 150 wordpress.org allows.

`Brand::FULL_TITLE` stays and still titles the admin screen. There it is a
heading on a page, not a name anything derives a slug or an identity from, and
nothing checks it against the header.

### Consequences

- Plugin Check: no errors, no warnings.
- `ReleaseReadinessTest` asserted the mismatch as though it were the
  requirement – the suite was green while wordpress.org's own checker was not.
  It now asserts the header and the readme title are identical, and that the
  short description exists, fits, and still carries the tagline.
- The name in the menu, the slug, the text domain and the REST namespace are
  all unchanged. Nothing a site has installed is affected.

---

## D-0057 – an allow-list decides what ships, `.distignore` can only remove

- **Phase:** 19b, part 1
- **Date:** 2026-09-05
- **Status:** accepted
- **Relates to:** D-0053 (portable packaging)

### Context

The brief for this phase asked for `.distignore` at each plugin root to be the
**only** include/exclude source. The builder instead keeps an explicit ship
list per plugin and applies `.distignore` on top of it. That is a deviation
from an instruction, so it is written down here rather than left in the code
for somebody to find.

### Decision

**`.distignore` moves to each plugin root and becomes the single exclusion
source**, which is the part of the brief that was missing: the builder read the
repository's `.distignore` and applied it to both plugins, so Pro was measured
against patterns written relative to a different directory. `pro/.distignore`
now exists and each plugin is read against its own root. A plugin root without
one is refused rather than silently packaged.

**The ship list stays.** What goes in is named; `.distignore` can only take
away.

### Why not a deny-list alone

A deny-list ships everything it does not name. This repository's root holds
`node_modules/`, a `vendor/` with forty development packages, `tests/`,
`admin-ui/`, `docs/` and `dist/`. Under a deny-list, one missing line is a
release containing them – and the failure is silent: the zip installs, the
plugin works, and only the file size or a reviewer ever says otherwise.

The free plugin ships nine named files out of `vendor/`. Writing "these nine
and nothing else" as exclusions means listing every package present today and
every one added later, and getting it wrong the first time somebody runs
`composer require`.

An allow-list fails the other way round. A file that should ship and was not
listed is *missing*, and the plugin breaks loudly on the first install – which
is the direction to fail in when the alternative is a quiet, shipped mistake.
This is also the arrangement that has held since D-0053; the zip that could not
be installed predates it.

### Consequences

- `.distignore` is honoured, per plugin root, and its absence is an error.
- Anything genuinely new that must ship has to be added to the ship list. That
  is the cost, and it is paid at build time with a message naming the file.
- If the single-source rule is wanted anyway, the change is confined to
  `collect()` in `scripts/plugin-zip.mjs`. It is not a redesign – it is a
  deliberate refusal, and reversible in one function.

---

## D-0058 – the admin probe carries the cookie the admin actually reads

- **Phase:** 19b, part 1b
- **Date:** 2026-09-05
- **Status:** accepted
- **Spec:** §11 (`admin` – GET `/wp-admin/` **with cookie of actor**)
- **Amends:** D-0019, D-0020

### Context

Reported from a live site: every apply ended `VERIFIED_WITH_WARNINGS`, with

> The dashboard answered with the login form, so this check could not confirm
> whether it renders.

The brief for this phase named the cause as "`Verify\HttpClient` sends no
authentication for the acting user". That is not what was wrong. `ActorSession`
already existed, already minted a credential against a real session token,
already forwarded the caller's own cookie when there was one, and already
destroyed what it created. The probe was authenticating.

It was authenticating with the wrong cookie, and the reason is not obvious.
`auth_redirect()` guards `/wp-admin/` and calls `wp_validate_auth_cookie( '', '' )`.
An **empty scheme** is resolved by `wp_parse_auth_cookie()` to `secure_auth`
under TLS and to `auth` otherwise – and never to `logged_in`. So the dashboard
reads `AUTH_COOKIE` or `SECURE_AUTH_COOKIE`, and a request carrying only
`LOGGED_IN_COOKIE` is anonymous to it however valid that cookie is.

### Decision

**Both cookies are sent, bound to the same session token.**

- `LOGGED_IN_COOKIE` stays: it is what `wp_get_current_user()` reads, so it is
  what makes the page render *as the actor* rather than merely letting the
  request through.
- The admin cookie is minted for the scheme **the target URL** needs, not the
  scheme of the request doing the verifying: `force_ssl_admin()` can put the
  admin behind https while the run that triggered it arrived over http.
- Only the matching one is sent. A `secure_auth` credential put on a plaintext
  request is a credential minted for TLS and then sent without it.
- Both name the same session token, because `wp_validate_auth_cookie()` checks
  the token against the user's live sessions and a cookie naming a session that
  does not exist is refused however well-formed it is.
- Nothing is persisted. The cookies are built per request, the minted session is
  destroyed when verification finishes, and no cookie value reaches a journal
  row, a run payload or a log – asserted, not assumed.

**A credential never leaves the cookie domain.** `COOKIE_DOMAIN`, or the site's
own host when that is unset, decides. A browser would not send this site's
credentials elsewhere and neither does this, however the URL came to point
off-site.

**Redirects are not followed, so the two failures stay distinguishable.**

| What comes back | What it means | Reported as |
|---|---|---|
| 302 to `wp-login.php` | core read the cookie and refused it | UNKNOWN, naming the scheme |
| 200 with a login form | something in front of core stripped the cookie | UNKNOWN, naming that |
| 200 with admin markers but no admin bar | rendered, but not for anybody | WARN |
| 200 with both | the dashboard, signed in | PASS |

Following the redirect turns the first into the second, which is how a specific
diagnosis becomes a vague one. A status code alone settles neither: the second
arrives as an ordinary 200.

### Consequences

- A site with working loopback now reaches `VERIFIED` rather than
  `VERIFIED_WITH_WARNINGS`, which is what §11 always described.
- D-0019's admin markers gain `Markers::ADMIN_BAR`. Six fixtures modelled a
  dashboard with `wpbody` and `adminmenu` and no admin bar – a page core does
  not serve to a signed-in user. They were corrected rather than the assertion
  weakened.
- A site that hides the admin bar inside `/wp-admin/` will WARN rather than
  PASS. That is the deliberate asymmetry from D-0019: the message names the
  missing marker, and a warning costs a sentence where a wrong PASS costs the
  truth.
- The brief asked for this to be recorded against D-0009. D-0009 is about Docker
  DNS on the build machine and has nothing to do with probes; the decisions this
  actually amends are D-0019 and D-0020, and they say so.
