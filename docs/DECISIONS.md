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
