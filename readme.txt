=== Debloater Pro ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Workflow for people who run Debloater on more than one site: scheduled scans, drift detection, portable profiles and a printable before/after report.

== Description ==

Debloater Pro adds workflow to Debloater. It does not add anything Debloater
does to a site.

Scans on a schedule, so a site is checked without somebody remembering to.
Drift detection, so you are told what changed since the last scan rather than
having to compare two reports yourself. Portable profiles, so you save a setup
once and take it to every site you manage. A printable before and after report,
with your own name on it.

Each site still shows you the preview and asks before anything is applied.
Nothing is pushed to a site from somewhere else.

Everything to do with safety stays in Debloater itself: the recovery point
taken before a change, the verification afterwards, the automatic rollback when
verification fails, the refusal to delete anything without a backup. None of it
is here, and none of it is behind a licence.

Requires the free Debloater plugin, which does the actual work.

== Changelog ==

= 0.2.0 =
* Profiles panel, replacing the "Saved profile" dropdown: apply, export,
  duplicate, rename and delete, with Debloater's own profiles always listed.
  Applying opens Debloater's preview with the changes ticked -- the recovery
  point, the checks afterwards and the way back are unchanged.
* Licence state, plan and the site quota are shown on Pro's own screen rather
  than only on the Account page, which a white-labelled licence empties.
* Licensing now runs through the Freemius SDK, behind the EntitlementProvider
  interface. Debloater itself is unaffected by any of it: with no licence, an
  expired one, or the platform absent, the free plugin keeps scanning,
  applying, verifying and rolling back exactly as before.
* Fixed: the before/after report showed "nothing was measured" for changes that
  had measured plenty, because it read the stored measurements in a shape
  nothing writes.
* Fixed: the report opened inside the admin page instead of as a document of
  its own.

= 0.1.1 =
First release.
