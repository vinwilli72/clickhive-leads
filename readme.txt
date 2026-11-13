=== Click Hive Leads ===
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 0.1.1

Lead inbox for sites managed by Click Hive Marketing.

== What it does ==

Gravity Forms submissions on the forms you switch on become leads. Staff work
every lead from one list: set the stage, tick the site's own checkboxes, add
notes, and export.

* One lead per person. A returning person (same email, or same phone when
  there is no email) updates their lead: it moves to the top of the list,
  shows "Returning (n)", checkboxes marked "Clear" are unchecked, and a lead
  in a Lost stage goes back to the first Open stage. Won leads stay won.
* Stages are set per site, each one Open, Won or Lost. Every stage change is
  recorded with who made it and when, so reports can be added later.
* Notes are a dated log with each author's name. Nothing is overwritten.
* The site records where each visitor came from (landing page, referrer, UTM
  tags, Google click ID) and attaches it to the lead. No hidden form fields.
* Two-step forms: the first form can send people to a follow-up page with a
  private link, and the follow-up form adds to the same lead.
* Merge tags for Gravity Forms notifications: {ch_lead_link} links to the lead
  and {ch_leads_url} links to the list. Links ask staff to sign in first.
* The plugin sends no email. Notifications stay in Gravity Forms.

== Updates ==

New versions are published as GitHub releases. WordPress checks about twice a day; to check right away, go to
Dashboard > Updates and click "Check again".

== Setup ==

1. Upload and activate the plugin. Gravity Forms must be active.
2. Settings > Click Hive Leads: set the stages and checkboxes for this business.
3. For each form that should create leads: Forms > (the form) > Settings >
   Click Hive Leads. Turn it on, pick the name, email and phone fields, and
   check any other fields to copy onto the lead. For healthcare clients, leave
   free-text fields unchecked.
4. Optional: add {ch_lead_link} to the form's notification so the email links
   straight to the lead.
5. Users > Add New: give front-desk staff the "Lead Manager" role. They can see
   and work leads and nothing else in the admin. The Client role from Click
   Hive Utility can also export.

== Two-step forms ==

On the first form, choose a Follow-up page. After submitting, visitors go
there with a private link. On the follow-up form, set the role to "Follow-up
step" and choose the First step page. Visitors who reach the follow-up page
without the link see a short message pointing to the first step. Signed-in
editors still see the form.

== Who can do what ==

* Administrators: everything, including settings and deleting leads.
* Client (Click Hive Utility): view and work leads, add notes, export.
* Lead Manager: view and work leads, add notes.

== Notes ==

* Leads are kept after deactivation. Settings > Click Hive Leads can delete
  leads with no new submission after a number of days (default: keep forever).
* Sites using SiteGround Security's custom login URL: test the {ch_lead_link}
  link while logged out.

== Changelog ==

= 0.1.1 =
* Form fields shown as list columns use the field's Admin Field Label from Gravity Forms as the heading when it has one. Hovering the heading shows the full question.
* Lead list columns no longer break words in half. When there are more columns than fit, the list scrolls sideways.

= 0.1.0 =
* First version: leads from Gravity Forms, lead list with inline stage, checkboxes and notes, filters, search, monthly summary, CSV export, two-step forms, source capture, merge tags, Lead Manager role.
