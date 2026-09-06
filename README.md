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

The integration tests run from the free plugin's wp-env, with this directory
mapped in as a second plugin:

```
wp-env run tests-cli --env-cwd=wp-content/plugins/debloater \
    php tools/phpunit-9.phar -c ../debloater-pro/phpunit-wp.xml.dist
```

The mapping goes in the free plugin's `.wp-env.override.json`, which is
untracked there:

```json
{
  "mappings": { "wp-content/plugins/debloater-pro": "../debloater-pro" },
  "env": {
    "tests": {
      "mappings": { "wp-content/plugins/debloater-pro": "../debloater-pro" }
    }
  }
}
```

Not in its `.wp-env.json`, because that repository is public and its environment
has to start on a machine with no Pro checkout beside it.

They are not run by this repository's CI, which has no WordPress: see
`.github/workflows/ci.yml` and docs/DECISIONS.md D-0065.

## Developing against a real licence

Three constants make the SDK usable on a test site. **All three belong in that
site's `wp-config.php` and nowhere else** — not in this repository, not in CI,
not in a `.env` that someone might commit:

```php
define( 'WP_FS__DEV_MODE', true );
define( 'WP_FS__SKIP_EMAIL_ACTIVATION', true );
define( 'WP_FS__debloater-pro_SECRET_KEY', '…' );
```

The first two only change how the SDK behaves locally. The third is the
product's **secret** key, and it is a different value from the public key in
`config/freemius.php.dist` — that one is handed to every browser that loads the
licensing UI and is not a secret at all.

CI fails if a secret key with a value assigned to it appears anywhere in this
repository, including in a file somebody added in a hurry. That check is
fail-probed, so it is known to work rather than assumed to.

If you need a local override of the product id or public key, copy
`config/freemius.php.dist` to `config/freemius.php`. That file is gitignored
and excluded from packages, so a local experiment cannot become a release.

## Licensing

Entitlement comes through `EntitlementProvider`, and the first implementation
targets Freemius. Debloater operates no licence server of its own, and no
Hakeemify host is a prerequisite for Pro. The provider is an interface precisely
so that the platform is replaceable and the plugin is not built around one.

Nothing secret is in this repository. Store identifiers that must not be
published belong in `wp-config.php` constants, and `.distignore` excludes
`config/freemius.php` before it exists.

### Plans

One plan, annual, auto-renewing. Features stop when a licence expires; the free
plugin does not – Debloater keeps scanning, applying, verifying and rolling
back, and everything already applied stays applied.

| Row | Price | White-label available |
|---|---|---|
| Single site | $29 | no |
| 5 sites | $49 | yes |
| 20 sites | $79 | yes |
| Unlimited | $149 | yes |

### What white-label does, exactly

It is a flag on an individual licence, set from the Freemius dashboard, on
licences bought through a row that offers it.

On a site with a white-labelled licence, the agency's client **does not see**
the licence owner's email, the licence key, prices, the billing address or
invoices.

They **do still see** an Account item in the menu, and it works – the SDK keeps
it because licence activation and deactivation happen there. Anyone telling a
client "you will see no licensing screens" is promising something this does not
do.

Because that page is stripped of the useful parts, Pro's own screen carries the
plan, the licence state and a way to release the site. That is where to look,
and it is the same on every licence.


## CI

| Job | Needs | What it proves |
|---|---|---|
| `Tests` | PHP, and the free plugin | Pro's units and the architecture invariants, on 8.1, 8.2 and 8.3 |
| `Static analysis` | PHP, and the free plugin | PHPCS and PHPStan level 6 |
| `Integration (Pro + Debloater)` | Docker, wp-env, both plugins | Pro against a real WordPress |
| `Nothing secret ships` | nothing | no key, token or store secret is committed |

The free plugin is checked out unconditionally. It is public, so there is no
token, no `continue-on-error`, and no job that can report success without
having looked — which is what the first three used to do, every run, because
the `FREE_PLUGIN_TOKEN` they asked for was never configured. See
`docs/DECISIONS.md` D-0065.

### Push the free repository first

Every job above checks out **`scornik/debloater` at `main`**, not at a pinned
commit. So when a change here depends on something new in the free plugin — a
class, a hook, a query argument — Pro's CI cannot pass until that change is on
the free plugin's `main`.

Push the free repository first. Always, even when the Pro change looks
self-contained and the free change looks trivial.

This is not hypothetical. `cd5bf84` added the profiles panel, which uses
`Debloater\Config\Profile` and `ProfileStore`. Those classes were committed in
the free repository but not yet pushed, so Pro's CI checked out a `main` that
did not have them and Static analysis reported about forty unknown-class errors
— every one of them meaning "the other repository has not caught up", and none
of them saying so.

Tracking `main` is the deliberate choice: pinning a commit would let the two
drift while both reported green, which is the failure this workflow exists to
prevent. The cost is this ordering rule, and the rule is cheaper than the drift.
