# CLOUD-DESIGN.md

A design for the agency multi-site dashboard. **Nothing here is built.** No
infrastructure exists, no accounts have been created, no code has been written,
and `cloud.hakeemify.com` does not resolve.

This is deliberately a document rather than a service. The dashboard is worth
building when there are agencies asking for it, and the cheapest version of that
conversation is one where the design already exists and the bill does not.

---

## 1. What it is for

An agency runs Debloater on thirty client sites. Today they learn what changed
on each one by opening thirty dashboards. The cloud exists to answer, in one
place:

- Which sites have findings nobody has looked at?
- Which sites drifted since last week, and how?
- Which sites are on an old plugin or registry version?
- Which sites have an apply that failed verification and rolled back?

That is the whole product. It is a **reporting** surface, and §2 explains why it
stops there.

### Requirements

| | |
|---|---|
| **R1** | One list of sites, each with: last scan, finding counts by severity, drift since the previous scan, plugin and registry version, and the outcome of the last apply. |
| **R2** | One site's history: runs over time, what was applied, what was rolled back and why. |
| **R3** | Reuse the JSON the plugin already produces. No second schema. |
| **R4** | A site that never enrols is unaffected in every respect. |
| **R5** | The cloud being down is invisible to every site. |
| **R6** | An agency can export everything it has sent and delete everything it has sent. |

---

## 2. Push-only, and why that is not a limitation

**v1 accepts reports from sites. It sends no commands to them. There is no
inbound control path, and this is the single most important decision in the
document.**

Debloater edits people's sites. It takes a recovery point first, verifies
afterwards, and rolls back automatically when verification fails — and the whole
argument that this is safe rests on a person having asked for the change and
being there when it happens. A control channel would mean a change originating
somewhere the site owner is not, verified by nobody watching, on thirty sites at
once. That is a materially different product with a materially different risk,
and it is not this one.

The concrete consequence: there is no endpoint that makes a site do anything.
Not apply, not roll back, not scan, not "just" update the registry. A compromised
Hakeemify Cloud can serve wrong numbers on a dashboard. It cannot touch a site.

That property is worth more than the convenience of remote apply, and it is why
`CloudServiceClient` has exactly one method — `get()` — returning decoded data,
with no path from a response to anything executable
(`pro/src/Cloud/CloudServiceClient.php`).

### What agencies will ask for anyway

Remote apply, and probably within a week of launch. The answer is not "never",
it is: **the site initiates, always.** A future version can let an agency stage
an *intent* in the dashboard, which the site collects on its own schedule, plans
locally, snapshots locally, verifies locally, and rolls back locally — with the
site free to refuse. That inverts the trust direction and keeps every safety
property where it already works. It is deferred (§12), and it must not be
retrofitted by adding a command endpoint to v1.

---

## 3. What a site sends

**Facts are already site-agnostic.** This is checkable rather than aspirational:
a full fact set from the Phase 9 fixture (`tests/Fixtures/facts/full-stack.json`,
50 facts across a WooCommerce, Contact Form 7 and LiteSpeed install) contains
**zero absolute URLs**. `Scan\Sources` reduces every asset URL to a source label
before it becomes a fact, so what is stored is `"litespeed"` and
`"woocommerce/woocommerce.php"`, never `https://client-site.example/wp-content/...`.

That was not done for the cloud. It is a consequence of invariant 1 — the
scanner produces facts, not opinions — and it means the payload needs
subtraction rather than redesign.

### The envelope

```json
{
  "schema_version": 1,
  "site_id":        "<opaque id issued at enrolment>",
  "site_hash":      "<sha256 of home_url|abspath, as the plugin already computes>",
  "sent_at":        "2026-09-04T10:15:00Z",
  "plugin_version": "0.1.0",
  "registry_tag":   "v0.1.0",
  "run": { "...": "the existing Run::toArray(), minus the fields in §3.2" }
}
```

`run` is literally what `Contracts\Run::toArray()` produces — id, type, status,
actor, timestamps, plugin version, registry hash, payload, error — because R3
says no second schema. A schema that drifts from the plugin's own is a schema
that starts lying the first time a field is added.

### 3.1 Sent

Facts, findings (with evidence), the plan, apply results, measured deltas, run
status and timing, plugin and registry versions. Plugin **slugs** and theme
slugs, because "which of my sites still runs that abandoned plugin" is a
question the dashboard exists to answer.

### 3.2 Never sent

Stripped before transmission, and asserted by a test when this is built:

| Field | Why |
|---|---|
| `home_url`, `abspath` | The site is identified by `site_id` and `site_hash`. The hash is one-way and already in the codebase; the cloud never needs the input. |
| `actor` | It is `user:14` in the journal. A user id is a person. The dashboard needs "a human did this" or "cron did this", so the field is reduced to `human` or `automatic` on the way out. |
| Journal rows | §13 rule 12 already forbids PII beyond the actor id. Rather than rely on that holding forever, journals do not leave the site at all. |
| Snapshot contents | Level B snapshots are **the rows a site is about to delete** — post content, options, comments, user meta. This is the single largest category of "must never leave", and there is no feature worth relaxing it for. |
| `db.autoload.top` | Option **names**, which on a real site include plugin-specific keys that leak business detail. Sent as a count and a byte total; the names stay home. |
| Anything from `wp_users` | Never scanned into facts in the first place. `users.admin_count` is a number. |

### 3.3 Minimisation is enforced by an allow-list

The transmitted payload is built by naming what goes, not by deleting what does
not. A deny-list fails open: the next field somebody adds to a fact ships by
default, and nobody notices for a year. This is the same reasoning as the zip
builder's ship list (`scripts/plugin-zip.mjs`), and for the same reason.

---

## 4. Auth: signed site keys

### Enrolment

1. The agency creates a site in the dashboard and gets a one-time enrolment code.
2. They paste it into the site's Pro settings.
3. The site generates an **Ed25519 keypair locally**, sends the public half with
   the enrolment code, and receives a `site_id`.
4. The private key never leaves the site. Hakeemify never has it and cannot
   obtain it.
5. The enrolment code is single-use and expires in 24 hours.

Ed25519 because the plugin depended on `ext-sodium` for registry signature
verification (`src/Update/SignatureVerifier.php`), so this added no new
cryptographic surface. *Since free 0.4.0 that verifier is gone with the fetch
(free D-0073, D-0078), so that reason no longer holds.*

### Every report

Signed with the site key over a canonical serialisation — reusing
`Contracts\Json::canonical()`, which already exists because registry manifests
needed exactly this property. The signature covers the body **and** a timestamp
**and** a nonce; the server refuses a timestamp outside ±5 minutes and a nonce
it has seen. Replay is otherwise trivial, and a replayed report is a dashboard
that shows a stale site as current — which is the failure mode most likely to
get somebody to ignore a real problem.

### Why not an API key

A shared bearer token is a password: readable in `wp_options`, readable in a
database backup, readable by every other plugin on the site, and identical
across every request. A site key is asymmetric, so what the server stores cannot
be used to impersonate the site — which matters most in the case this design
should assume, namely that the server is the thing that gets breached.

### Human auth

Dashboard sign-in is email plus a magic link, TOTP available. No password to
reset, store, or leak. Sessions are 30 days, revocable per device.

**No SSO in v1.** It is three weeks of work for a customer who does not exist
yet.

---

## 5. One host, versioned and product-scoped

Everything is under `https://cloud.hakeemify.com`, per §13 rule 14 and already
enforced in code by `Pro\Cloud\EndpointResolver`, which is the only place in
either plugin permitted to name the host — asserted by
`tests/Pro/ProArchitectureTest.php`.

```
https://cloud.hakeemify.com/v1/debloater/<service>/<path>
```

| Service | Method | For |
|---|---|---|
| `enrol` | POST | Exchange a code and a public key for a `site_id` |
| `reports` | POST | Push a run |
| `sites` | GET | Dashboard reads |
| `status` | GET | Liveness, unauthenticated, no data |

`v1` is in the path rather than a header so a URL in a log says which contract
produced it. `debloater` scopes the product, because one host will serve others
and a shared namespace is how two products end up sharing an outage.

**Why one host rather than `api.`, `license.`, `registry.`, `app.`** — an
administrator who wants to know what this plugin talks to should be able to
answer with one line in a firewall rule. Four subdomains make that take four
answers, three of which somebody will miss. This supersedes the earlier
multi-subdomain sketch (D-0047 records the naming lineage).

### Routing

One TLS terminator, path-routed to per-service handlers. The write path
(`enrol`, `reports`) and the read path (`sites`) are separate deployables from
the start — not for scale, which is irrelevant at this size, but because they
have different blast radii: the write path accepts data from the internet and
the read path holds the session cookies.

---

## 6. Isolation: licensing is not the cloud

**Licensing is a third-party platform (Freemius). Hakeemify Cloud is an optional
service. Neither is implemented in terms of the other, and the boundary is
load-bearing.**

| | Licensing | Cloud |
|---|---|---|
| Who runs it | A third party | Hakeemify |
| Answers | "What may this site use?" | "What did these sites report?" |
| Reached through | `EntitlementProvider` | `CloudServiceClient` |
| Required for Pro | Yes | No |
| Required for the free plugin | No | No |
| If it is down | Pro keeps working (14-day grace, D-0051) | Pro keeps working, locally |

Three rules, and the first is the one to be suspicious of a future self about:

1. **No cloud endpoint validates a licence.** §13 rule 14 prohibits a cloud
   endpoint whose real purpose is licence validation. The temptation is
   specific and predictable: `/v1/debloater/sites` already authenticates the
   site, so checking entitlement there feels free. It is not free — it makes
   the cloud a licensing dependency, which is the exact coupling rule 13 exists
   to prevent, and it does it in a way that reads as an optimisation.
2. **The cloud never receives a licence key.** It knows a `site_id`. Whether
   that site pays is a question for the storefront, answered on the site.
3. **Debloater runs no licence server.** If Freemius is replaced, a new
   `EntitlementProvider` implementation is written and nothing else changes —
   which is only true because no feature asks anything but
   `allows( 'some_feature' )`, asserted by `ProArchitectureTest`.

---

## 7. Secrets and keys

| Secret | Where it lives | Who holds it |
|---|---|---|
| Site private key | The site, in `wp_options`, generated locally | The site. Hakeemify never has it. |
| Site public key | Cloud database | Hakeemify |
| Registry **signing** key | Offline, on hardware, never in CI | A person |
| Registry **verification** key | Published with the registry. Compiled into the plugin until free 0.4.0, which removed the fetch and the verifier with it (free D-0073, D-0078) | Everyone. It is public. |
| Cloud TLS | The platform's managed certificate | The platform |
| Session signing | Cloud secret manager | Hakeemify |
| Freemius credentials | The storefront | The third party |

Two things follow, and both are already true in the shipped code:

**No secret is in the plugin.** §13 rule 15, asserted by `SecurityRulesTest` and
`ProArchitectureTest`. Since free 0.4.0 there is no verification key in the
plugin either, because there is nothing left to verify (free D-0073).

**The registry signing key never touches infrastructure.** `tools/registry-manifest.php`
refuses a key path inside the repository. Signing is a manual act by a person on
a machine. It is inconvenient by design: a signing key in CI is a signing key
that leaks with CI, and a leaked registry signing key means arbitrary handler
code on every site that opted into updates.

---

## 8. Rotation

**Site keys.** Rotated by the site, unprompted, every 180 days: generate, sign
the new public key with the old private key, submit, switch on acknowledgement.
A key never used for 12 months is disabled server-side. Rotation needs no human
and no downtime, which is the only kind of rotation that actually happens.

**Registry signing key.** The plugin verifies against **one** compiled key, so
rotation is a plugin release. Sequence: generate the new key offline, ship a
release that accepts both, wait for adoption, sign the next registry with the
new key, remove the old one in the release after. Two releases, deliberately —
a one-release rotation strands every site that has not updated, on the exact
mechanism that would deliver their next security fix.

**Compromise.** A compromised signing key is the worst case in this system and
gets its own procedure, written before it is needed: stop publishing, revoke
server-side, ship a release with the new key and a hard floor on the manifest
tag, and say so publicly on the same day. A registry update cannot execute
arbitrary code by itself — handler paths are allow-listed against declared
tweaks and realpath-checked inside the plugin directory (§13 rule 5) — but it
can change what a site is offered, and that is enough to warrant treating it as
a full compromise.

---

## 9. Retention, backups, deletion

| Data | Kept | Then |
|---|---|---|
| Run reports | 13 months | Deleted |
| Aggregates for a site's history | 13 months | Deleted with the reports |
| Enrolment codes | 24 hours | Deleted |
| Nonces (replay window) | 15 minutes | Deleted |
| Access logs | 30 days | Deleted |
| Session records | 30 days after expiry | Deleted |

Thirteen months so an agency can compare a year to the same month last year, and
not a day longer. There is no "we might want it later" tier: data nobody has a
use for is a liability with a storage bill attached.

**Deletion is real.** Un-enrolling a site deletes its reports within 24 hours,
including from backups older than the retention window as those age out. There
is no soft-delete flag that keeps everything forever behind a boolean.

**Backups** are the platform's daily snapshots with a 7-day window, encrypted at
rest, restore rehearsed quarterly. A backup nobody has restored is a hypothesis.

**Monitoring**: uptime on `/v1/debloater/status`, error rate and p95 latency per
service, a queue-depth alert on report ingestion, and a weekly report of
enrolled sites that have stopped reporting — the last being the one that
actually matters, because a site that silently stops is indistinguishable from a
site with nothing to say, and that is exactly the failure this dashboard exists
to prevent.

---

## 10. Cost at zero and low scale

Sizing from what the plugin actually produces, measured rather than estimated.
A scan of the full-stack fixture site produces a run payload of **30.1 KB** —
12.1 KB of facts, 17.4 KB of analysis across 16 findings. A site on a weekly
schedule sends about four a month.

The analysis is the larger half, and it is prose: each finding carries its
evidence and its "why" in full sentences. That is worth knowing before anybody
proposes compressing the wrong thing.

| Scale | Sites | Reports/month | Data/month | Realistic monthly cost |
|---|---|---|---|---|
| Zero | 0 | 0 | 0 | **£0** — nothing deployed |
| Pilot | 50 | 200 | 6 MB | £0–5 — free tiers |
| Small | 500 | 2 000 | 60 MB | £15–30 |
| Real | 5 000 | 20 000 | 600 MB | £60–120 |

The shape worth noticing: at 5 000 sites this is **under 1 GB a month and well
under one request per second**. It is a small database and two small services. The
temptation this design exists to resist is building for a scale that will not
arrive for years and paying for it every month until it does.

So: managed Postgres, container hosting that scales to zero, object storage for
raw report bodies past 90 days. **No Kubernetes, no queue broker, no data
warehouse, no analytics pipeline.** Ingestion writes to Postgres synchronously,
because at one request per second a queue is a second thing to operate for a
problem nobody has.

**Zero scale costs zero.** Nothing is provisioned until an agency asks.

---

## 11. Migration to a standalone service

Hakeemify Cloud would start on WordPress — a WordPress developer's fastest path
to a working dashboard is a WordPress site. The design has to survive leaving
it, so the plugin must not be able to tell the difference.

### What would not change

**Nothing in the plugin's contract.** Deliberately, and it is the reason
`EndpointResolver` exists as a class rather than a constant:

- The host, `cloud.hakeemify.com`. A migration is a DNS change.
- The paths, `/v1/debloater/<service>/...`.
- The auth model, the payload schema, the signature scheme.
- The plugin code. Not one line.

The `v1` prefix is what buys this. A standalone service implements the same `v1`
and the migration is invisible; when the contract genuinely needs to change,
that is `v2` served alongside, not `v1` quietly behaving differently.

### What would change

Server-side only: WordPress custom post types become real Postgres tables, the
REST controller becomes an HTTP service, WordPress sessions become the service's
own.

### What would be lost

WordPress's admin as a free back office. That is worth listing rather than
discovering: the first version gets user management, roles and a data browser
for nothing, and the standalone version has to build all three. It is the main
argument for starting on WordPress and the main cost of leaving.

### Trigger

Migrate when ingestion write latency exceeds 200 ms p95, or when WordPress's own
update cycle starts dictating the service's deployment schedule. Not before, and
not because standalone sounds more serious.

---

## 12. Deferred until there is revenue

Listed rather than omitted, because an unwritten deferral gets rebuilt as a
surprise.

| Deferred | Why |
|---|---|
| **Any inbound control path** | §2. Not a scheduling decision — a v1 that can command a site is a different product. The site-initiated intent model is the shape a future version should take. |
| Remote apply, in any form | As above. |
| SSO / SAML | Weeks of work for a customer who does not exist. |
| Team roles beyond owner and member | Two roles cover an agency of ten. |
| Webhooks and outbound integrations | Every one is an ongoing maintenance commitment. |
| A public API | Committing to a contract before knowing what anybody wants from it. |
| White-label dashboard domains | Certificate management for a cosmetic win. |
| Server-side PDF | D-0049 — and the cloud is the right place for it if it ever matters, precisely because it keeps the library off the site. |
| Anomaly detection, "AI insights" | `CLAUDE.md` forbids AI in the product. The dashboard reports what sites measured. |
| Multi-region | One region, until somebody's data residency requirement makes it a sale. |
| Real-time updates | Sites report weekly. A page refresh is real-time enough. |

---

## 13. Open questions for a person

Not decidable from the code, and each needs an answer before anything is built.

1. **Is the dashboard Pro, or a separate subscription?** It has a running cost
   per site; Pro currently does not. That is a pricing decision.
2. **Which jurisdiction stores the data?** Reports carry no personal data by
   design (§3.2), but "which plugins are on my client's site" is commercially
   sensitive, and agencies will ask.
3. **Does an agency's client get access to their own site's page?** Attractive,
   and it doubles the auth model's complexity.
4. **What is the free tier?** Three sites is a good demo; it is also a support
   burden with no revenue attached.

---

## 14. Status

**Design only. Nothing deployed, no accounts, no infrastructure, no code.**

What exists in the repository today is the client side, written and tested
against the cloud's absence: `CloudServiceClient`, `HakeemifyCloudClient`,
`EndpointResolver`, `CloudResponse`. The cloud is off unless a wp-config
constant enables it, a disabled client makes no request at all, and a test
asserts an outage changes nothing about a site.

That is the right amount to have built. The interface is proven against the case
that matters most — the service not being there — and the service itself waits
for somebody to ask for it.
