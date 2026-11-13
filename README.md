# Click Hive Leads

WordPress plugin for Click Hive client sites. Gravity Forms submissions on the
forms the team switches on become leads that staff work from one list. See
`readme.txt` for what it does and how to set it up, and `docs/SPEC.md` for the
build brief.

## Layout

- `clickhive-leads.php`: header, updates, roles.
- `includes/intake.php`: turns one submission into a lead. Knows nothing about
  any form plugin.
- `includes/gravity-forms.php` and `includes/class-chl-gf-addon.php`: the
  Gravity Forms adapter and its per-form settings tab. A Cornerstone Forms
  adapter would sit beside these and call `chl_receive()` the same way.
- `includes/lead.php`: the `ch_lead` post type, history table, stage,
  checkboxes and notes.
- `includes/list.php`, `includes/export.php`, `includes/edit.php`: the admin
  screens.
- `includes/source.php`: the first-visit source cookie.
- `includes/settings.php`: Settings > Click Hive Leads.

## Tests

`tests/lint.sh` runs `php -l` on every PHP file.

`tests/e2e.js` drives a real browser (Playwright) against a throwaway
WordPress with Gravity Forms and this plugin active. It wipes forms, pages and
leads on that site first, through `tests/setup.php`.

    WP_URL=http://localhost:8099 WP_CLI="wp --path=/path/to/wordpress" node tests/e2e.js

## Releasing

Update the version in the plugin header, the `CHL_VERSION` constant,
`readme.txt` (Stable tag and changelog), then publish a GitHub release tagged
with that version. The release workflow builds `clickhive-leads.zip` and
attaches it; sites pick it up as an update.
