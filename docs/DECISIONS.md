# Decisions — Debloater Pro

**Shared decisions live in the free plugin's repository**, at
<https://github.com/scornik/debloater/blob/main/docs/DECISIONS.md>. That file is
authoritative for every decision about Debloater itself and for every decision
that concerns both plugins. This file holds only what is specific to Pro.

Until now both repositories carried a full copy, sixty-three decisions each,
fifty-six of them identical. Amending either made them disagree without anything
noticing, and `P1` cited "D-0057" without saying which copy it meant. A test in
each repository now fails if a decision number appears in both.

The numbering is one sequence across all three repositories, so `D-0059` means
one thing wherever it is cited. Registry decisions are in
`scornik/debloater-registry`.

---

## Principles

The seven statements that generalise beyond their own subject, **by reference**.
The reasoning, the failure each came from and the decision each links to are in
the free plugin's file, under the same heading — one copy, so there is nothing
to drift.

- **P1.** Allow-list what ships. Never deny-list.
- **P2.** A skipped test is a failed test in CI.
- **P3.** A check that has never passed in the runner is not coverage.
- **P4.** Pin the literal that forms a contract, never the constant both sides read.
- **P5.** When code encodes a vocabulary that lives somewhere else, test it against the real artifact.
- **P6.** When both defaults are wrong, refuse.
- **P7.** Code that decides something is importable; code that runs does not decide.

`tests/Pro/DecisionRecordTest.php` checks this list against the free plugin's,
so a principle added or reworded there is not silently missing here.

---

## What moved, and where it went

These numbers were in this file and are now only in
`scornik/debloater`. An old citation still resolves — it is one repository over,
in the same numbering.

`D-0001`, `D-0002`, `D-0003`, `D-0004`, `D-0005`, `D-0006`, `D-0007`, `D-0008`, `D-0009`, `D-0010`, `D-0011`, `D-0012`, `D-0013`, `D-0014`, `D-0015`, `D-0016`, `D-0017`, `D-0018`, `D-0019`, `D-0020`, `D-0021`, `D-0022`, `D-0023`, `D-0024`, `D-0025`, `D-0026`, `D-0027`, `D-0028`, `D-0029`, `D-0030`, `D-0031`, `D-0032`, `D-0033`, `D-0034`, `D-0036`, `D-0037`, `D-0038`, `D-0039`, `D-0040`, `D-0041`, `D-0042`, `D-0043`, `D-0044`, `D-0045`, `D-0046`, `D-0047`, `D-0048`, `D-0049`, `D-0051`, `D-0052`, `D-0053`, `D-0054`, `D-0055`, `D-0056`, `D-0057`, `D-0058`

---

## Pro's own decisions

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

---

## D-0060 – the Freemius product, and what it is allowed to decide

- **Phase:** 19b, part 2
- **Date:** 2026-09-06
- **Status:** accepted
- **Spec:** §13 rule 13, §13 rule 15
- **Implements:** D-0035 (licensing is provider-agnostic)

### The product

| | |
|---|---|
| Platform | Freemius |
| Product id | `38409` |
| Slug / premium slug | `debloater-pro` |
| Type | plugin, premium-only (`is_premium_only`) |
| wordpress.org compliant | **no** – Pro is not distributed there |
| Add-ons | none |
| Plan | one, `pro` |
| Pricing rows | 1 site, 20 sites, unlimited |
| White-label | on the 20-site and unlimited rows |

The id and public key live in `config/freemius.php.dist`, which is committed and
ships. Neither is secret: both are handed to every browser that loads the
licensing UI, and they identify the product rather than authorising anything.
`config/freemius.php` overrides them locally, is gitignored and is excluded from
packages, so a local experiment cannot become a release.

The **secret** key is a different value, belongs in the test site's
`wp-config.php`, and is never in this repository. CI fails on an assigned
`WP_FS__*_SECRET_KEY` anywhere, and that check is fail-probed.

### What the SDK is allowed to decide

**Whether premium code may run, and nothing else.** The gate is
`can_use_premium_code__premium_only()` rather than `is_paying()`: they answer
different questions, and the second would switch features off for somebody who
had cancelled a renewal with three months still paid for.

**The site quota is read for display only.** It is shown on Pro's own screen and
decides nothing. A plugin that enforced a site limit itself would be enforcing a
rule it cannot see the whole of – other installs, a site released an hour ago, a
quota the customer has since raised – and would get it wrong in the direction
that costs the customer.

**Nothing it says can block the free plugin.** Entitlement is cached, so a
platform that is unreachable leaves Pro on its last known answer rather than
switching features off. `CachedEntitlementProvider` now also catches a provider
that throws: the interface says implementations must not, and the adapter does
not, but a guarantee that holds only while everyone keeps their promise is not
the guarantee it claims to be.

### Two files may name Freemius, not one

`ProArchitectureTest` allowed exactly one – the adapter. The SDK has to be
initialised before `plugins_loaded`, because it hooks activation, deactivation
and the admin menu, and WordPress offers one place that early: the plugin's
entry point. So `debloater-pro.php` joins the adapter on the allowed list.

The rule it protects is unchanged. The entry point *starts* the SDK; it asks it
nothing. Everything Freemius knows – plans, licences, trials, quotas – is read
in the adapter and leaves as an `Entitlement`. A third file naming Freemius is
still a failure, and the test now reads the entry points, which it did not
before: the bootstrap was added and the suite stayed green, because `sources()`
walked directories and never opened `debloater-pro.php`.

### White-label

**Corrected by D-0061, which was written after testing this against the live
product.** What follows was wrong when it was written: the SDK does *not* hide
its Account menu on a white-labelled licence.

The conclusion it reached happens to be right, and for a better reason. Both
licence status and a way to release the site are rendered on Pro's own screen,
and a test asserts the screen names no Account URL at all.

### Wording

Only the licence notices are overridden, through `override_i18n()`, so they
describe feature updates, drift alerts and priority support rather than the
SDK's default wording about security updates and support. The placeholders are
preserved in number and order – these strings reach `sprintf`, so an override
that drops a `%s` does not read differently, it throws.

The opt-in screen and every word of its data-collection copy are left exactly as
the SDK wrote them. That text is a disclosure of what gets sent to a third
party, and rewording somebody else's privacy disclosure to suit your own tone is
not a thing to do.

---

---

## D-0061 – what white-label actually does, and what we may promise

- **Phase:** 19b, part 2, after launch testing
- **Date:** 2026-09-06
- **Status:** accepted
- **Corrects:** D-0060

### What was believed

That enabling white-label on a licence removes the Freemius Account submenu, so
an agency's client would see no licensing UI at all. Three files said so: the
adapter, the Pro screen, and the test named for it.

### What is true

White-label is a flag **on a licence**, not on the product – the Licenses table
in the Freemius dashboard, column "Is White-labeled". It can be set on licences
sold through a pricing row that has White Labeled enabled, which for this
product is the 5-site, 20-site and Unlimited rows.

**It does not remove the Account submenu.** The SDK forces that submenu on,
because licence activation and deactivation happen there and a plugin whose
licence cannot be deactivated is a plugin that cannot be moved between sites.

What it hides is the sensitive content of that page:

| Hidden on a white-labelled licence | Still visible |
|---|---|
| The licence owner's email address | The Account menu item itself |
| The licence key | Activation and deactivation |
| Prices | The plugin's own version and update state |
| Billing address | |
| Invoices | |

### What this changes

**The requirement on our own screen is unchanged, and its reason is stronger.**
It was "the Account page may be absent". It is now "the client-facing Account
page is uninformative" – deliberately emptied of exactly what somebody looking
at it wants to know. So plan, licence status and a deactivate path are rendered
on Pro's own screen, and the test asserting that keeps its assertion and gets a
new name and message.

**What we may say to an agency is narrower than what was implied.** We may say a
client does not see the licence key, the prices or the invoices. We may not say
the client sees no Freemius UI: they see an Account item, and it works. Saying
otherwise would be a promise the SDK contradicts on the first screenshot
somebody sends back.

---

---

## D-0062 – the commerce path is verified end to end

- **Phase:** 19b, part 2
- **Date:** 2026-09-06
- **Status:** accepted
- **Product:** Freemius `38409`, `debloater-pro`

### The plan and its rows

One plan, `pro`. Annual only, auto-renew.

| Row | Price | Pricing ID | White-label available |
|---|---|---|---|
| Single site | $29 | 85724 | no |
| 5 sites | $49 | 85727 | yes |
| 20 sites | $79 | 85726 | yes |
| Unlimited | $149 | 85725 | yes |

**Expiry blocks features** (Is Blocking on). When a licence expires Pro's
features stop.

**The free plugin is unaffected by Pro expiry.** Debloater keeps scanning,
applying, verifying and rolling back, and every change already applied stays
applied. That is not a courtesy: BUILD-SPEC §13 rule 15 says safety is never
paywalled, and an expiry that took recovery or rollback with it would break that
rule rather than bend it.

### Verified, not assumed

A sandbox purchase of the 20-site row, on 2026-09-06:

1. Checkout completed.
2. A licence was issued.
3. The licence activated on a second site.
4. Freemius deployment served version 0.1.1 to it.

Checkout, licence, activation and the update channel therefore work as a chain
rather than as four things believed to work separately. This is worth recording
with a date because it is a claim about somebody else's live service, and the
next person to read it should know how old the evidence is.

---

---

## D-0064 – Pro chooses a profile; Debloater applies it

- **Phase:** 19c
- **Date:** 2026-09-06
- **Status:** accepted
- **Spec:** §13 rules 8 and 15, §17 Phase 19c
- **See also:** D-0063 (in `scornik/debloater`), which is the free half.

### What replaced what

Pro's screen carried a "Saved profile" dropdown. It listed the three profiles
the registry defines, stored which one you had picked, and said so in its own
description: *"This only remembers which one you meant."* The phase that
introduced profiles named it as the placeholder it was.

`Admin\ProfilesPanel` replaces it. It lists every profile the site has, built-in
and saved, and offers Apply, Export, Duplicate, Rename and Delete.

### Apply does not apply

This is the decision, and everything else here follows from it.

Apply builds a URL — `?page=debloater&debloater_profile=<id>` — and redirects to
it. Debloater's screen looks the id up in its own store and opens its ordinary
preview with those changes ticked. The plan, the confirmation token issued for
that exact plan, the recovery point, the verification and the automatic rollback
are all the free plugin's, unchanged, and Pro is not involved in any of them.

The alternative was to plan and apply in Pro, reusing `BulkApply`, and it was
rejected on the grounds that make it tempting. It would have been convenient,
one click shorter, and entirely within Pro's reach. It would also have meant a
paid extension holding a second route into applying — and a second route is a
route that can be got at without the first one's checks, whatever the intention
of whoever adds to it next year. §13 rule 15 says safety is never paywalled;
the sharper form of it is that the paid half must not be able to *route around*
the free half's safety either.

So `ProfilesPanel` contains no plan, no token and no call into the engine, and a
test asks it to apply and requires it to answer that it does not know how.

### The URL is an id, never a selection

`debloater_profile` carries a profile id. The free screen resolves it against
its own store, so a link somebody edited by hand can name a profile that does
not exist — and gets nothing — but cannot name a set of changes of its own
choosing. The worst a crafted link achieves is showing somebody a preview of
changes the site was already offering them, behind the same confirmation as
always.

It is documented in the free plugin's `docs/HOOKS.md` as a URL contract, and
pinned on both sides by tests that assert the literal string rather than each
side's own constant.

### Built-ins can be copied but not edited

Rename and Delete are rendered only for a site's own profiles. That is a
courtesy; the enforcement is `ProfileStore`, which refuses either for a
registry profile no matter what is posted, and the test posts them anyway.

Duplicate *is* offered for a built-in, because copying `Safe` and adjusting the
copy is how somebody starts. The copy is an ordinary saved profile from the
moment it exists.

### `BulkApply` keeps its option, and Apply writes it

`Features\BulkApply` is unchanged: same option, same registry check, same
`apply()` that takes a confirmation token for the exact plan. What changed is
who writes the option. The dropdown wrote it when you pressed Save; the panel
writes it when you press Apply, which is a plainer statement of which profile
you meant than picking one from a list and saving a form.

Saved profiles are not storable there, because `BulkApply` plans by profile id
and a site's own profile is a selection rather than a name the planner knows.
`save()` says so by returning false, and the panel does not need to care.

---

---

## D-0065 – Pro's integration suite runs again, from the free plugin's wp-env

- **Phase:** 19c
- **Date:** 2026-09-06
- **Status:** accepted
- **Spec:** §21.2

### What was wrong

The split removed the wp-env mapping and the PHPUnit suite that ran Pro's
integration tests, and the commit said so plainly: "the build no longer knows
Pro exists." What it did not say, because nobody noticed, is that
`tests/Integration/` came with Pro and there was then nothing anywhere that
could run it. `ProScreenTest` and `ProIntegrationTest` were present, correct,
and unreachable for four commits.

They did not fail and they did not skip. They simply were not collected, which
is the failure mode this project treats as worse than a red build: a suite that
is not run reports nothing at all, and nothing at all reads as fine.

### Where the pieces live now

Three things are needed and they are in three repositories' worth of places:

| Piece | Where | Why there |
|---|---|---|
| `phpunit-wp.xml.dist` | here | It configures Pro's tests. PHPUnit resolves paths from the config file, so `tests/Integration` means Pro's. |
| `tests/bootstrap-integration.php` | here | Loads Pro's autoloader, then requires the free plugin's bootstrap verbatim rather than reimplementing it. |
| the wp-env mapping | the free plugin's **untracked** `.wp-env.override.json` | wp-env runs there. |

The mapping is deliberately not in the free plugin's `.wp-env.json`. That
repository is public and its environment must start on a machine that has no
private sibling checkout; a tracked mapping to `../debloater-pro` would make
`wp-env start` fail for everyone else. `.wp-env.override.json` is gitignored
there and `.distignore` already excludes it from the package.

### It runs in CI

Added after the fact, as the `Integration (Pro + Debloater)` job. It checks out
both repositories, installs both, downloads the PHPUnit 9 phar, generates the
mapping by copying the free plugin's committed `.wp-env.override.json.dist`,
starts wp-env and runs `npm run test:integration:pro` — the same command a
person runs here, rather than a CI-shaped imitation of it that can drift.

Pro is mapped but deliberately **not activated**. Its entry point initialises
the licensing SDK, which on activation reaches for a network these tests do not
need and CI should not depend on. Every test in the suite constructs Pro
directly — `new Pro( $plugin, FixtureEntitlementProvider::everything() )` — so
the mapping only has to make the files reachable.

### The token that was never there

The two jobs that need the free plugin used to check it out with
`token: ${{ secrets.FREE_PLUGIN_TOKEN }}` and `continue-on-error: true`, because
`scornik/debloater` was private. The secret was never configured. So the
architecture invariants — the ones asserting Pro adds no tweaks, no runtime
handlers and no safety features to Debloater — skipped on every run the workflow
ever made, while the job reported success.

The free plugin is public now, so both checkouts are unconditional, the token is
gone, and a step asserts the tree is really there before the suite runs. A
second step fails the build if anything skipped.

---
