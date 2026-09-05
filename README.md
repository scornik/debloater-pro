# Debloater Pro

Workflow features for people who run Debloater on more than one site: scans on a
schedule, drift detection between sites, a saved profile, a printable
before/after report, and a name on that report.

**Private.** The free plugin is [scornik/debloater](https://github.com/scornik/debloater).

## What Pro is not

Pro adds nothing to what Debloater does to a site. No tweaks, no runtime
handlers, no registry, no recovery, no verification, no rollback. All of that
lives in the free plugin and stays there, and
`tests/Pro/ProArchitectureTest.php` fails if any of it appears here.

That is not a style preference. Safety behind a paywall is the thing this
product refuses to be (`BUILD-SPEC.md` §13 rule 15), and an invariant nobody
checks is a sentence in a document.

## How it extends the free plugin

Through the hooks Debloater documents in `docs/HOOKS.md`, and through nothing
else. Pro does not call into the free plugin's classes, which is why:

- it is a separate plugin with `Requires Plugins: debloater`, not a bundle;
- it is not a Composer dependency of the free plugin, nor the free plugin of it;
- the free plugin can be read, audited and released without reference to this
  repository.

## Working on it

Clone both repositories side by side:

```
parent/
  debloater/      # the free plugin
  debloater-pro/  # this
```

```
composer install
composer test
```

The tests that assert Pro adds nothing to the free plugin need the free plugin
to compare against. They find it as a sibling checkout, or wherever
`DEBLOATER_FREE_PATH` points:

```
DEBLOATER_FREE_PATH=/path/to/debloater composer test
```

Without it, those six tests **skip and say so**. They do not quietly pass — a
test that checks half the evidence and reports success is worse than one that
does not run.

## The suites

| Suite | Needs | What it covers |
|---|---|---|
| `tests/Pro/` | nothing but PHP | Pro's own units, and the architecture invariants |
| `tests/Integration/` | WordPress, and the free plugin active | Pro against a real site |

The integration tests run from the free plugin's wp-env, which maps this
directory as a second plugin. They are not run by this repository's CI, which
has no WordPress: see `.github/workflows/ci.yml`.

## Licensing

Entitlement comes through `EntitlementProvider`, and the first implementation
targets Freemius. Debloater operates no licence server of its own, and no
Hakeemify host is a prerequisite for Pro. The provider is an interface precisely
so that the platform is replaceable and the plugin is not built around one.

Nothing secret is in this repository. Store identifiers that must not be
published belong in `wp-config.php` constants, and `.distignore` excludes
`config/freemius.php` before it exists.
