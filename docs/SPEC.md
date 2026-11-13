# Click Hive Leads: build brief

A WordPress plugin for Click Hive client sites. Gravity Forms submissions on forms the team switches on become Lead records (post type `ch_lead`) that staff work from one list: stage, their own checkboxes, notes, export. It is a basic lead inbox, not a CRM.

Repo: `vinwilli72/clickhive-leads` (public, like `vinwilli72/clickhive-calls`, so sites can self-update without a token). Vince reviews and merges every pull request himself. Work on a branch per version and open a PR; never push to main.

Reference code: `clickhive-calls` (same author, same patterns) and the VEMMA leads code (`nwc-leads.php`), which this generalizes.

## Decisions already made

- Separate plugin from Click Hive Calls. Only about 20 percent of clients use both.
- Gravity Forms only for now. Keep intake behind a small adapter so a Cornerstone Forms adapter can be added later without touching the lead record, list or reports.
- No ACF dependency. Build the lead edit screen with native meta boxes, because checkboxes and mapped fields differ per site and come from settings.
- No link to the phone system's call records yet. Store phones as `+1XXXXXXXXXX` (same format as the phone system) so that link can be added later without data cleanup.
- No "PHI mode." The plugin sends no email. Healthcare control is simply not mapping free-text fields; notifications stay in Gravity Forms.
- Returning leads follow the VEMMA policy: one lead per person (match on email, then phone). A new submission moves the lead to the top, counts the submission, clears "followed up"-type state, and reopens leads in a lost stage. Won leads stay won.

## Attaching a form

A "Click Hive Leads" tab in each Gravity Forms form's settings, built on the Gravity Forms add-on framework (feed or settings add-on), where the team:

- Turns leads on for that form.
- Maps core fields with dropdowns: first name, last name (or a single Name field), email, phone.
- Picks any other fields to copy onto the lead. They show on the lead under the form's own field labels.
- Chooses the form's role: "Creates or updates a lead" or "Follow-up step."

Skip entries Gravity Forms marks as spam.

Create the lead on `gform_entry_created` (before notifications send), so merge tags can link to it.

## Two-step (follow-up) forms

Generalizes VEMMA's flow so a partial lead exists after step one:

- A form set to "creates a lead" can name a follow-up page. After submit, it redirects there with a private 32-character token in the URL.
- A form set to "Follow-up step" reads the token (hidden field or URL), adds its mapped fields to the same lead, and logs the step.
- Without a valid token, visitors see a short message linking back to step one. Logged-in editors still see the form.
- Booking-specific behavior (VEMMA's class picker, reminders, calendar file, texts) is out of scope. It stays VEMMA-only.

## The lead record

Core fields: first name, last name, email, phone, received date (post date), first received, submission count, source.

Stage: one at a time, from a list the client edits in settings (one per line, the way Calls handles status choices). Each stage also belongs to a fixed category, Open, Won or Lost, so reports work across clients with different stage names. New leads get the first Open stage.

Checkboxes: client-named, one per line in settings, any combination per lead. Keys come from the label so renaming keeps existing values, and removing a line retires it without deleting data.

Mapped fields: stored per lead, shown read-only on the edit screen with their form labels, newest submission wins on conflict.

Notes: a dated log, each entry with the author's name. Never a single field that gets overwritten.

History, written from day one even before any report uses it: every stage change with a timestamp and who made it, checkbox changes, each submission (form name, entry ID with a link to the Gravity Forms entry), and follow-up steps. Stage history is what makes later reports possible ("how many became customers last quarter," "time from new to won").

Source capture: a small first-party cookie records the visitor's landing page, referrer, UTM tags and Google click ID (`gclid`) on first visit. On submission, attach it to the lead. No hidden fields needed on forms.

## The list

Default columns: received date and time, name, email, phone, stage, the client's checkboxes, latest note.

- Stage, checkboxes and notes edit inline from the list without opening the lead (VEMMA pattern, admin-ajax with a nonce, with a saved/error flash).
- The notes column shows the most recent note with a small box to add a new one.
- Filters: stage, stage category (Open, Won, Lost), "not checked" per checkbox, date range, form.
- Search: name, email, notes, and any run of 4+ phone digits (VEMMA's search).
- Summary above the list: leads this month, last month, and won this month.
- Export: CSV of whatever the list is currently filtered to, including mapped fields and source.
- Returning leads show a "Returning (n)" marker.

## Gravity Forms merge tags

Add to the notification editor's merge tag dropdown:

- `{ch_lead_link}`: the admin edit URL of the lead this submission created or updated.
- `{ch_leads_url}`: the leads list URL.

No login tokens in links. WordPress already sends logged-out visitors to the login page and back to the admin URL after sign-in. (Per-site check: sites using SiteGround Security's custom login URL should be tested logged out.)

## Roles

- Administrators: everything, including settings and delete.
- The `client` role from Click Hive Utility's Client Access: view and work leads, add notes, export. Grant on every admin load, the way `chc_grant_client_role()` does in Calls, because Click Hive Utility rebuilds that role.
- A "Lead Manager" role for front-desk staff, matching Calls' Call Manager: view and work leads only.
- Settings page: administrators only.

## Settings page

Stages (with category per stage), checkboxes, which mapped fields appear as list columns (optional, later), retention (keep leads N days, default forever), and a short status panel showing which forms are connected.

## Packaging and updates

Mirror `clickhive-calls` exactly:

- `plugin-update-checker` pointed at the GitHub repo with release assets.
- `.github/workflows/release.yml` that checks the version in the header and constant match the tag, builds `clickhive-leads.zip` without tests or repo files, and attaches it to the release.
- `readme.txt` with changelog. Start at 0.1.0.

## Testing

- PHP lint on every file.
- End-to-end tests against a real local WordPress (SQLite works: WordPress from GitHub, `sqlite-database-integration`, wp-cli) with Gravity Forms installed, covering: lead creation from a connected form, ignoring unconnected forms and spam, the follow-up step with and without a token, returning-lead rules, inline edits, notes, export contents, merge tags, and role access.
- A Gravity Forms license is needed for the test site; Vince can supply the plugin zip.

## Out of scope for 0.1

Phone system link, Cornerstone Forms adapter, reports screen (history is recorded so it can be built later), booking module, migrating VEMMA's existing `nwc_lead` records.

## Writing rules for anything a client reads

No em dashes, no exclamation points, plain wording. Admin labels short and literal.
