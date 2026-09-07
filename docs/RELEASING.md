# Releasing Debloater Pro

Every step, in order. CI refuses a release-shaped state that lies about itself,
but it refuses — it does not fix. Which version comes next is a decision.

Pro is **not** on wordpress.org. It is deployed to Freemius, which is the last
step and the only one that reaches a customer.

---

## The version lives in four places

All four must agree, and `tools/version-discipline.mjs` fails the build when
they do not:

| Where | Line |
|---|---|
| `debloater-pro.php` | ` * Version:           X.Y.Z` |
| `readme.txt` | `Stable tag: X.Y.Z` |
| `package.json` | `"version": "X.Y.Z"` |
| `src/Pro.php` | `public const VERSION = 'X.Y.Z';` |

The fourth is easy to forget and the most consequential to get wrong:
`Pro::VERSION` is what the cloud client reports as the product version, so a
stale one misreports which Pro a site is running to the one service that
aggregates that across sites.

`readme.txt` here is **for Freemius only**. It carries no Contributors, Donate
or Tags lines and no wordpress.org links, because implying a listing that does
not exist would be a claim about where this plugin comes from.

---

## The steps

### 1. Decide the number

Pro's version moves independently of the free plugin's. They are separate
products with separate release cadences, and pinning them together would mean
releasing one to ship the other.

### 2. Move all four

Nothing generates them. A version that appears as a side effect of a build has
no author.

### 3. Write the changelog

`readme.txt`'s changelog section, newest first, written for somebody deciding
whether to update.

### 4. Run the gate

```bash
composer test          # Pro's units and the architecture invariants
composer lint          # PHPCS
vendor/bin/phpstan analyse --memory-limit=1G
```

And the integration suite, which needs the free plugin's wp-env — see
`README.md`:

```bash
cd ../debloater
npm run test:integration:pro
```

Everything green, with **nothing skipped**. A skipped test is a failed test
here; `docs/DECISIONS.md` D-0065 is the reason.

### 5. Build, and let the check look at it

```bash
composer install --no-dev --classmap-authoritative
npm run plugin-zip
node tools/version-discipline.mjs
```

The `--no-dev` install matters: the Freemius SDK is a *runtime* dependency and
ships inside the plugin, while everything else must stay out. Restore your
development install afterwards with `composer install`.

The check compares the archive against
`tests/Packaging/pro-plugin-content.json` and refuses if the content moved
while the version did not. Nothing is exempt from hashing — Pro has no build
step and no generated autoloader, so nothing it ships differs between machines.

### 6. Re-record what shipped

```bash
node tools/record-shipped-content.mjs --why "0.2.0: <what changed>"
```

Commit the regenerated record with the release.

### 7. Tag, in the same breath as the commit

```bash
git tag -a vX.Y.Z -m "Debloater Pro X.Y.Z"
git push --follow-tags
```

**This is a release step, not something to get round to.** A release with no tag
cannot be checked out later, `git describe` on `main` reports the wrong thing to
everybody who runs it, and "what was in 0.2.0" has to be reconstructed from
dates. All of those failures show up long after the release, to somebody who was
not there.

`--follow-tags` rather than a separate `git push --tags`: it pushes the
annotated tag with the commit it names, so the two cannot arrive separately or
one of them not at all.

This repository had no tags at all until `v0.2.0` — it was created by the Pro
split and nothing was ever tagged in it, which is why the version check reads
the content record rather than a tag. That reason has not changed; the tag is
for people, and the record is for the build.

`v0.2.0` is tagged at the commit where the four version locations moved
together, which is the first commit whose tree the content record describes.
The versions before it are deliberately untagged: there is no commit whose tree
is honestly 0.1.1, its content record having later been found misdated, and a
tag saying otherwise would be one no build could confirm.

### 8. Deploy to Freemius

The last step, and the only one a customer sees.

1. Sign in to the Freemius developer dashboard.
2. Product **38409**, Deployment → upload `dist/debloater-pro-X.Y.Z.zip`.
3. It is uploaded as a **pending** version. Nothing reaches a customer until it
   is released deliberately.
4. Check the version Freemius parsed matches the header before releasing it.

That is a person, with credentials, on purpose. Nothing in this repository has
Freemius credentials and nothing may acquire them: `WP_FS__debloater-pro_SECRET_KEY`
belongs in a test site's `wp-config.php` and nowhere else, and CI fails if a
secret key with a value appears anywhere here.

---

## What the check will not do

It will not bump a version, write a changelog, or deploy anything. It refuses,
names the files that moved, and stops.

The failure it prevents is quiet: shipping different code to customers under a
version they already have. Between 0.1.1 and 0.2.0 the shipped code changed
repeatedly — the Freemius SDK integration, the profiles panel, the licence
screen — with the version never moving. This file exists because of that.
