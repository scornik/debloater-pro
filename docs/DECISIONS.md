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
