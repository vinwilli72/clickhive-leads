// End-to-end tests: the real plugin in a real WordPress with Gravity Forms,
// driven through a real browser (Playwright). Nothing is simulated except
// outgoing email, which a test-only must-use plugin catches (tests/setup.php).
//
// Needs: a throwaway WordPress at WP_URL with Gravity Forms and this plugin
// active, and wp-cli. WP_CLI is the command that runs wp-cli for that site
// (default "wp"). The test runs tests/setup.php first, which wipes forms,
// pages and leads.
//
// Run:  WP_URL=http://localhost:8099 WP_CLI="wp --path=/path/to/wp" node tests/e2e.js

const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const WP_URL = process.env.WP_URL || 'http://localhost:8099';
const WP_CLI = (process.env.WP_CLI || 'wp').split(' ');
const PASS = 'testpass123';

function wp(...args) {
  return execFileSync(WP_CLI[0], [...WP_CLI.slice(1), ...args], { encoding: 'utf8' }).trim();
}
function php(code) {
  const out = wp('eval', code);
  try {
    return JSON.parse(out);
  } catch (e) {
    throw new Error(`wp eval did not return JSON: ${out}`);
  }
}

// Everything the tests check about one lead, read straight from the database.
function lead(by, value) {
  return php(`
    $id = chl_find_by(${JSON.stringify(by)}, ${JSON.stringify(value)});
    if (!$id) { echo 'null'; return; }
    $boxes = array();
    foreach (array_keys(chl_checkboxes()) as $k) { $boxes[$k] = chl_is_checked($id, $k); }
    echo wp_json_encode(array(
      'id' => $id,
      'title' => get_the_title($id),
      'date_gmt' => get_post_field('post_date_gmt', $id),
      'first_name' => chl_get($id, 'first_name'),
      'last_name' => chl_get($id, 'last_name'),
      'email' => chl_get($id, 'email'),
      'phone' => chl_get($id, 'phone'),
      'stage' => chl_get($id, 'stage'),
      'submissions' => (int) chl_get($id, 'submissions'),
      'first_received' => (int) chl_get($id, 'first_received'),
      'boxes' => $boxes,
      'fields' => array_map(function ($f) { return $f['value']; }, chl_fields($id)),
      'forms' => get_post_meta($id, '_chl_form'),
      'source' => chl_get($id, 'source'),
      'source_data' => chl_get($id, 'source_data'),
      'notes' => array_map(function ($n) { return array($n['name'], $n['text']); }, chl_notes($id)),
      'history' => array_map(function ($h) { return array($h['event'], $h['value'], $h['category'], (int) $h['user_id'], $h['detail']); }, array_reverse(chl_history($id))),
    ));
  `);
}
const leadCount = () => Number(wp('post', 'list', '--post_type=ch_lead', '--post_status=any', '--format=count'));
const mails = () => php('echo wp_json_encode(get_option("chl_test_mails", array()));');
const userId = (login) => Number(wp('user', 'get', login, '--field=ID'));

let passed = 0;
async function check(name, fn) {
  await fn();
  passed++;
  console.log(`ok ${passed} - ${name}`);
}

async function login(browser, user) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(`${WP_URL}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', PASS);
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  return { ctx, page };
}

// Fills and sends a Gravity Forms form on the current page. The Quote form
// is embedded with ajax="true" in tests/setup.php; the others are not.
async function submit(page, formId, values, ajax = false) {
  for (const [input, value] of Object.entries(values)) {
    const sel = `#gform_${formId} [name="input_${input}"]`;
    const tag = await page.$eval(sel, (el) => el.tagName);
    if (tag === 'SELECT') await page.selectOption(sel, value);
    else await page.fill(sel, value);
  }
  if (ajax) {
    // AJAX forms swap in the confirmation or the errors without a page load.
    await page.click(`#gform_submit_button_${formId}`);
    await page.waitForSelector('.gform_confirmation_message, .gform_validation_errors');
    return;
  }
  await Promise.all([page.waitForNavigation(), page.click(`#gform_submit_button_${formId}`)]);
}

(async () => {
  const fixture = JSON.parse(wp('eval-file', path.join(__dirname, 'setup.php')));
  const F = fixture.forms;
  const browser = await chromium.launch();

  try {
    // ------------------------------------------------------------------
    // Intake
    // ------------------------------------------------------------------
    const visitor = await browser.newContext();
    const vpage = await visitor.newPage();
    let token;

    await check('first visit records the source in a first-party cookie', async () => {
      await vpage.goto(`${WP_URL}/contact/?utm_source=google&utm_medium=cpc&utm_campaign=spring&gclid=TeSt-123`, {
        referer: 'https://www.google.com/',
      });
      const cookie = (await visitor.cookies()).find((c) => c.name === 'chl_src');
      assert.ok(cookie, 'chl_src cookie set');
      const data = JSON.parse(decodeURIComponent(cookie.value));
      assert.equal(data.gclid, 'TeSt-123');
      assert.equal(data.utm_source, 'google');
      assert.match(data.landing, /\/contact\/\?utm_source=google/);
      assert.equal(data.referrer, 'https://www.google.com/');
    });

    await check('later pages keep the first-visit source', async () => {
      await vpage.goto(`${WP_URL}/quote/`);
      const cookie = (await visitor.cookies()).find((c) => c.name === 'chl_src');
      assert.match(JSON.parse(decodeURIComponent(cookie.value)).landing, /\/contact\//);
    });

    await check('connected form creates a lead and redirects to the follow-up page with a 32-character token', async () => {
      await vpage.goto(`${WP_URL}/contact/`);
      await submit(vpage, F.contact, {
        '1.3': 'Ana',
        '1.6': 'Lopez',
        2: 'Ana@mailtest.dev',
        3: '(360) 555-1234',
        4: 'Repair',
        5: 'My knee hurts, private details here',
      });
      const url = new URL(vpage.url());
      assert.equal(url.pathname, '/details/');
      token = url.searchParams.get('lead');
      assert.match(token || '', /^[A-Za-z0-9]{32}$/);

      const l = lead('email', 'ana@mailtest.dev');
      assert.ok(l, 'lead exists');
      assert.equal(l.title, 'Ana Lopez');
      assert.equal(l.first_name, 'Ana');
      assert.equal(l.last_name, 'Lopez');
      assert.equal(l.phone, '+13605551234', 'phone stored as +1XXXXXXXXXX');
      assert.equal(l.stage, 'new', 'first Open stage');
      assert.equal(l.submissions, 1);
      assert.deepEqual(l.fields, { service: 'Repair' }, 'only the checked field is copied; the message is not');
      assert.deepEqual(l.forms, [String(F.contact)]);
      assert.equal(l.source, 'Google Ads');
      assert.equal(l.source_data.gclid, 'TeSt-123');
      assert.equal(l.source_data.utm_campaign, 'spring');
      assert.deepEqual(l.history.map((h) => h[0]), ['created', 'stage', 'submission']);
      const sub = l.history[2][4];
      assert.equal(sub.form_title, 'Contact');
      assert.match(sub.entry_url, new RegExp(`page=gf_entries&view=entry&id=${F.contact}&lid=\\d+`));
    });

    await check('notification merge tags link to the lead and the list', async () => {
      const m = mails().find((x) => x.subject === 'New lead');
      assert.ok(m, 'notification sent');
      const id = lead('email', 'ana@mailtest.dev').id;
      assert.ok(m.message.includes(`${WP_URL}/wp-admin/post.php?post=${id}&action=edit`), m.message);
      assert.ok(m.message.includes(`${WP_URL}/wp-admin/edit.php?post_type=ch_lead`), m.message);
    });

    await check('follow-up step with the token adds its fields to the same lead', async () => {
      assert.equal(await vpage.locator(`#gform_${F.details}`).count(), 1, 'form shown with a valid token');
      await submit(vpage, F.details, { 1: '$5,000', 2: 'This month' });
      assert.match(await vpage.content(), /Thanks, we got it/);
      const l = lead('email', 'ana@mailtest.dev');
      assert.deepEqual(l.fields, { service: 'Repair', budget: '$5,000', timeline: 'This month' });
      assert.equal(l.submissions, 1, 'a follow-up step is not a new submission');
      assert.deepEqual(l.forms, [String(F.contact), String(F.details)]);
      assert.equal(l.history.at(-1)[0], 'followup');
      assert.equal(leadCount(), 1);
      const m = mails().find((x) => x.subject === 'New lead' && x.message.includes('Follow-up for'));
      assert.ok(m && m.message.includes(`post=${l.id}`), '{ch_lead_link} works on the follow-up form too');
    });

    await check('follow-up page without a valid token shows a link back to step one', async () => {
      const ctx = await browser.newContext();
      const p = await ctx.newPage();
      for (const q of ['', '?lead=abc', `?lead=${'x'.repeat(32)}`]) {
        await p.goto(`${WP_URL}/details/${q}`);
        assert.equal(await p.locator(`#gform_${F.details}`).count(), 0, `no form for "${q}"`);
        const link = p.locator('.chl-followup-missing a');
        assert.equal(new URL(await link.getAttribute('href')).pathname, '/contact/');
      }
      await ctx.close();
    });

    await check('unconnected forms and spam create no lead', async () => {
      const before = leadCount();
      await vpage.goto(`${WP_URL}/newsletter/`);
      await submit(vpage, F.news, { 1: 'reader@mailtest.dev' });
      await vpage.goto(`${WP_URL}/contact/`);
      await submit(vpage, F.contact, { '1.3': 'Spam', '1.6': 'Bot', 2: 'spammer@mailtest.dev', 3: '5035550199' });
      assert.equal(leadCount(), before);
      const spam = php(`echo wp_json_encode(GFAPI::get_entries(${F.contact}, array('status' => 'spam')));`);
      assert.equal(spam.length, 1, 'Gravity Forms kept the entry as spam');
      assert.equal(lead('email', 'spammer@mailtest.dev'), null);
      assert.equal(lead('email', 'reader@mailtest.dev'), null);
    });

    // ------------------------------------------------------------------
    // Returning leads
    // ------------------------------------------------------------------
    await check('returning person (same email, any case) updates the one lead: count, top of list, Lost reopens, Clear boxes uncheck', async () => {
      const before = lead('email', 'ana@mailtest.dev');
      php(`
        $id = ${before.id};
        chl_set_stage($id, 'not-a-fit');
        chl_set_checkbox($id, 'followed-up', true);
        chl_set_checkbox($id, 'added-to-crm', true);
        wp_update_post(array('ID' => $id, 'post_date' => '2020-01-01 10:00:00', 'post_date_gmt' => '2020-01-01 18:00:00', 'edit_date' => true));
        echo '1';
      `);
      await vpage.goto(`${WP_URL}/quote/`);
      await submit(vpage, F.quote, { 1: 'Ana Lopez', 2: 'ANA@mailtest.dev', 3: '', 4: 'Install' }, true);
      const l = lead('email', 'ana@mailtest.dev');
      assert.equal(l.id, before.id);
      assert.equal(leadCount(), 1);
      assert.equal(l.submissions, 2);
      assert.equal(l.stage, 'new', 'Lost stage reopened to the first Open stage');
      assert.equal(l.boxes['followed-up'], false, '"Followed up | Clear" unchecked');
      assert.equal(l.boxes['added-to-crm'], true, 'other checkboxes kept');
      assert.ok(l.date_gmt > '2026-01-01', `moved to the top (date ${l.date_gmt})`);
      assert.equal(l.first_received, before.first_received);
      assert.equal(l.phone, '+13605551234', 'a blank phone does not erase the stored one');
      assert.equal(l.fields.service, 'Install', 'newest submission wins');
      assert.equal(l.fields.budget, '$5,000');
      const reopen = l.history.find((h) => h[0] === 'stage' && h[4].reason === 'returned');
      assert.ok(reopen && reopen[1] === 'new' && reopen[4].from === 'not-a-fit');
    });

    await check('matches on phone when there is no email; Won leads stay won', async () => {
      await vpage.goto(`${WP_URL}/quote/`);
      await submit(vpage, F.quote, { 1: 'Cara Diaz', 2: 'cara@mailtest.dev', 3: '(503) 555-0000', 4: 'Repair' }, true);
      const cara = lead('email', 'cara@mailtest.dev');
      assert.equal(cara.first_name, 'Cara');
      assert.equal(cara.last_name, 'Diaz', 'full name split into first and last');
      php(`chl_set_stage(${cara.id}, 'customer'); echo 1;`);

      await vpage.goto(`${WP_URL}/quote/`);
      await submit(vpage, F.quote, { 1: 'Cara D', 2: '', 3: '503.555.0000', 4: 'Install' }, true);
      const again = lead('email', 'cara@mailtest.dev');
      assert.equal(again.id, cara.id, 'same lead found by phone');
      assert.equal(again.submissions, 2);
      assert.equal(again.stage, 'customer', 'Won stays won');
      assert.equal(leadCount(), 2);
    });

    // ------------------------------------------------------------------
    // Roles
    // ------------------------------------------------------------------
    const admin = await login(browser, 'admin');

    await check('client role gets lead abilities on admin load, and again after Click Hive Utility rebuilds it', async () => {
      const caps = () => php(`$r = get_role('client'); echo wp_json_encode(array($r->has_cap('chl_work_leads'), $r->has_cap('chl_export_leads'), $r->has_cap('chl_delete_leads')));`);
      await admin.page.goto(`${WP_URL}/wp-admin/`);
      assert.deepEqual(caps(), [true, true, false]);
      php(`remove_role('client'); add_role('client', 'Client', array('read' => true)); echo 1;`);
      assert.deepEqual(caps(), [false, false, false]);
      await admin.page.goto(`${WP_URL}/wp-admin/`);
      assert.deepEqual(caps(), [true, true, false]);
    });

    const lisa = await login(browser, 'lisa');
    const carl = await login(browser, 'carl');

    await check('Lead Manager lands on the lead list after signing in', async () => {
      assert.match(lisa.page.url(), /edit\.php\?post_type=ch_lead/);
    });

    await check('who can open what', async () => {
      const status = async (who, url) => (await who.page.goto(`${WP_URL}${url}`)).status();
      const sam = await login(browser, 'sam');
      const list = '/wp-admin/edit.php?post_type=ch_lead';
      const settings = '/wp-admin/options-general.php?page=chl-settings';
      assert.equal(await status(admin, list), 200);
      assert.equal(await status(lisa, list), 200);
      assert.equal(await status(carl, list), 200);
      assert.equal(await status(sam, list), 403, 'subscriber has no access');
      assert.equal(await status(admin, settings), 200);
      assert.equal(await status(lisa, settings), 403);
      assert.equal(await status(carl, settings), 403);
      const id = lead('email', 'ana@mailtest.dev').id;
      assert.equal(await status(lisa, `/wp-admin/post.php?post=${id}&action=edit`), 200);
      // Core refuses with its own wp_die page, which it sends as status 500.
      await sam.page.goto(`${WP_URL}/wp-admin/post.php?post=${id}&action=edit`);
      assert.match(await sam.page.content(), /not allowed to edit this item/);
      assert.equal(await status(lisa, '/wp-admin/post-new.php?post_type=ch_lead'), 403, 'leads only come from forms');
      // Delete is for administrators only.
      await lisa.page.goto(`${WP_URL}${list}`);
      assert.equal(await lisa.page.locator('.row-actions .trash').count(), 0);
      await admin.page.goto(`${WP_URL}${list}`);
      assert.ok(await admin.page.locator('.row-actions .trash').count() > 0);
      // Export: administrators and clients, not Lead Managers.
      assert.equal(await lisa.page.goto(`${WP_URL}${list}`).then(() => lisa.page.locator('a.chl-export').count()), 0);
      assert.equal(await carl.page.goto(`${WP_URL}${list}`).then(() => carl.page.locator('a.chl-export').count()), 1);
      const url = await carl.page.locator('a.chl-export').getAttribute('href');
      const r = await lisa.page.request.get(url);
      assert.equal(r.status(), 403, 'Lead Manager cannot export even with the link');
      await sam.ctx.close();
    });

    await check('a logged-out lead link goes to the login page and back to the lead', async () => {
      const id = lead('email', 'ana@mailtest.dev').id;
      const ctx = await browser.newContext();
      const p = await ctx.newPage();
      await p.goto(`${WP_URL}/wp-admin/post.php?post=${id}&action=edit`);
      assert.match(p.url(), /wp-login\.php\?redirect_to=/);
      await p.fill('#user_login', 'lisa');
      await p.fill('#user_pass', PASS);
      await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
      assert.equal(new URL(p.url()).search, `?post=${id}&action=edit`);
      assert.match(await p.locator('.chl-lead-name').innerText(), /Ana Lopez/);
      await ctx.close();
    });

    // ------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------
    const lp = lisa.page;
    const listUrl = (q = '') => `${WP_URL}/wp-admin/edit.php?post_type=ch_lead${q}`;

    await check('list shows the default columns, the returning marker and the summary', async () => {
      await lp.goto(listUrl());
      const headers = await lp.locator('.wp-list-table thead th').allInnerTexts();
      for (const h of ['Received', 'Name', 'Email', 'Phone', 'Stage', 'Followed up', 'Added to CRM', 'Latest note']) {
        assert.ok(headers.some((x) => x.trim().startsWith(h)), `column ${h} in ${headers}`);
      }
      const ana = lp.locator('tr', { hasText: 'ana@mailtest.dev' });
      assert.match(await ana.innerText(), /Returning \(2\)/);
      assert.match(await ana.innerText(), /\(360\) 555-1234/);
      assert.equal(await lp.locator('.chl-sum-this').innerText(), '2');
      assert.equal(await lp.locator('.chl-sum-won').innerText(), '1', 'Cara moved to Customer this month');
      assert.equal(await lp.locator('.chl-sum-last').innerText(), '0');
    });

    await check('stage, checkbox and note save inline from the list, with history and author', async () => {
      const id = lead('email', 'ana@mailtest.dev').id;
      const row = lp.locator(`#post-${id}`);
      await row.locator('select.chl-stage').selectOption('contacted');
      await row.locator('select.chl-stage.is-saved').waitFor();
      await row.locator('input[data-field="cb:followed-up"]').check();
      await row.locator('input[data-field="cb:followed-up"].is-saved').waitFor();
      await row.locator('.chl-note-input').fill('Called, left a message');
      await row.locator('.chl-note-save').click();
      await row.locator('.chl-note-input.is-saved').waitFor();
      assert.match(await row.locator('.chl-latest-note').innerText(), /Called, left a message\s*Lisa,/);

      const l = lead('email', 'ana@mailtest.dev');
      const uid = userId('lisa');
      assert.equal(l.stage, 'contacted');
      assert.equal(l.boxes['followed-up'], true);
      assert.deepEqual(l.notes, [['Lisa', 'Called, left a message']]);
      const last3 = l.history.slice(-3).map((h) => [h[0], h[1], h[2], h[3]]);
      assert.deepEqual(last3, [
        ['stage', 'contacted', 'open', uid],
        ['checkbox', 'followed-up', 'on', uid],
        ['note', '', '', uid],
      ]);

      // Reload: the list shows what was saved.
      await lp.reload();
      assert.equal(await row.locator('select.chl-stage').inputValue(), 'contacted');
      assert.ok(await row.locator('input[data-field="cb:followed-up"]').isChecked());
    });

    await check('inline save refuses a bad nonce and an unknown stage', async () => {
      const id = lead('email', 'ana@mailtest.dev').id;
      const nonce = await lp.evaluate(() => window.CHL.nonce);
      const post = (form) => lp.request.post(`${WP_URL}/wp-admin/admin-ajax.php`, { form: { action: 'chl_inline', lead: String(id), ...form } });
      assert.equal((await post({ nonce: 'bad', field: 'stage', value: 'quoted' })).status(), 403);
      assert.equal((await post({ nonce, field: 'stage', value: 'nope' })).status(), 400);
      const sam = await login(browser, 'sam');
      const r = await sam.page.request.post(`${WP_URL}/wp-admin/admin-ajax.php`, { form: { action: 'chl_inline', nonce, lead: String(id), field: 'stage', value: 'quoted' } });
      assert.equal(r.status(), 403, 'subscriber refused');
      await sam.ctx.close();
      assert.equal(lead('email', 'ana@mailtest.dev').stage, 'contacted');
    });

    await check('notes from the lead screen are added to the log, never overwritten', async () => {
      const id = lead('email', 'ana@mailtest.dev').id;
      await lp.goto(`${WP_URL}/wp-admin/post.php?post=${id}&action=edit`);
      assert.match(await lp.locator('#chl_fields').innerText(), /Budget\s*\$5,000/);
      assert.match(await lp.locator('#chl_source').innerText(), /Google Ads/);
      assert.match(await lp.locator('#chl_history').innerText(), /Stage changed from Not a fit to New because they sent a form again/);
      await lp.fill('#chl-note', 'Booked a visit for Tuesday');
      await lp.selectOption('#chl_lead select.chl-stage', 'quoted');
      await Promise.all([lp.waitForNavigation(), lp.click('#chl_save input[type=submit]')]);
      assert.match(await lp.locator('.notice').first().innerText(), /Lead saved/);
      const l = lead('email', 'ana@mailtest.dev');
      assert.deepEqual(l.notes.map((n) => n[1]), ['Called, left a message', 'Booked a visit for Tuesday']);
      assert.equal(l.stage, 'quoted');
      assert.equal(l.boxes['followed-up'], true, 'checkbox kept');
      assert.equal(l.boxes['added-to-crm'], true, 'checkbox kept');
      assert.equal(l.phone, '+13605551234', 'phone kept in +1 format after editing screen save');
      assert.equal(l.submissions, 2);
      const notesText = await lp.locator('#chl_notes').innerText();
      assert.ok(notesText.indexOf('Booked a visit') < notesText.indexOf('Called, left'), 'newest note first');
    });

    await check('search: name, email, notes and 4+ phone digits', async () => {
      const names = async (q) => {
        await lp.goto(listUrl(`&s=${encodeURIComponent(q)}`));
        return (await lp.locator('.wp-list-table tbody .column-title strong').allInnerTexts()).map((s) => s.trim());
      };
      assert.deepEqual(await names('Lopez'), ['Ana Lopez']);
      assert.deepEqual(await names('cara@'), ['Cara D']);
      assert.deepEqual(await names('visit for tuesday'), ['Ana Lopez']);
      assert.deepEqual(await names('555-1234'), ['Ana Lopez']);
      assert.deepEqual(await names('(503) 555'), ['Cara D']);
      assert.deepEqual(await names('55-51-234'), ['Ana Lopez'], 'digits match whatever punctuation is typed');
      assert.deepEqual(await names('nobody'), []);
    });

    await check('filters: stage, category, not checked, form, date range', async () => {
      const names = async (q) => {
        await lp.goto(listUrl(q));
        return (await lp.locator('.wp-list-table tbody .column-title strong').allInnerTexts()).map((s) => s.trim()).sort();
      };
      assert.deepEqual(await names('&chl_stage=quoted'), ['Ana Lopez']);
      assert.deepEqual(await names('&chl_cat=won'), ['Cara D']);
      assert.deepEqual(await names('&chl_cat=open'), ['Ana Lopez']);
      assert.deepEqual(await names('&chl_cat=lost'), []);
      assert.deepEqual(await names('&chl_unchecked=followed-up'), ['Cara D']);
      assert.deepEqual(await names(`&chl_form=${F.details}`), ['Ana Lopez']);
      assert.deepEqual(await names(`&chl_form=${F.quote}`), ['Ana Lopez', 'Cara D']);
      const today = php('echo wp_json_encode(wp_date("Y-m-d"));');
      assert.deepEqual(await names(`&chl_from=${today}&chl_to=${today}`), ['Ana Lopez', 'Cara D']);
      assert.deepEqual(await names('&chl_from=2020-01-01&chl_to=2020-12-31'), []);
      // The filter dropdowns are on the page.
      await lp.goto(listUrl());
      for (const name of ['chl_stage', 'chl_cat', 'chl_unchecked', 'chl_form', 'chl_from', 'chl_to']) {
        assert.equal(await lp.locator(`[name="${name}"]`).count(), 1, name);
      }
    });

    await check('export: CSV of the current filter with copied fields and source', async () => {
      const parse = (text) => {
        // Small CSV reader: quoted cells may hold commas and new lines.
        const rows = [];
        let row = [], cell = '', q = false;
        for (let i = 0; i < text.length; i++) {
          const c = text[i];
          if (q) {
            if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; } else if (c === '"') q = false; else cell += c;
          } else if (c === '"') q = true;
          else if (c === ',') { row.push(cell); cell = ''; }
          else if (c === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; }
          else cell += c;
        }
        return rows;
      };
      const get = async (q) => {
        await carl.page.goto(listUrl(q));
        const url = await carl.page.locator('a.chl-export').getAttribute('href');
        const r = await carl.page.request.get(url);
        assert.equal(r.status(), 200);
        assert.match(r.headers()['content-type'], /text\/csv/);
        const rows = parse((await r.text()).replace(/^﻿/, ''));
        const head = rows.shift();
        return rows.map((r) => Object.fromEntries(head.map((h, i) => [h, r[i]])));
      };
      const all = await get('');
      assert.equal(all.length, 2);
      const ana = all.find((r) => r.Email === 'ana@mailtest.dev');
      assert.equal(ana['First name'], 'Ana');
      assert.equal(ana.Phone, '+13605551234');
      assert.equal(ana.Stage, 'Quoted');
      assert.equal(ana['Stage category'], 'Open');
      assert.equal(ana['Followed up'], 'Yes');
      assert.equal(ana.Submissions, '2');
      assert.equal(ana.Service, 'Install');
      assert.equal(ana.Budget, '$5,000');
      assert.equal(ana.Timeline, 'This month');
      assert.equal(ana.Source, 'Google Ads');
      assert.equal(ana.gclid, 'TeSt-123');
      assert.equal(ana.utm_campaign, 'spring');
      assert.match(ana['Landing page'], /\/contact\/\?utm_source=google/);
      assert.equal(ana.Forms, 'Contact, Details, Quote');
      assert.match(ana.Notes, /Lisa: Called, left a message\n.*Lisa: Booked a visit for Tuesday/);
      assert.ok(!('Message' in ana), 'unchecked free-text field is not exported');

      const won = await get('&chl_cat=won');
      assert.deepEqual(won.map((r) => r.Email), ['cara@mailtest.dev']);
      const searched = await get('&s=555-1234');
      assert.deepEqual(searched.map((r) => r.Email), ['ana@mailtest.dev']);
    });

    // ------------------------------------------------------------------
    // Admin screens
    // ------------------------------------------------------------------
    const ap = admin.page;

    await check('settings page lists connected forms only', async () => {
      await ap.goto(`${WP_URL}/wp-admin/options-general.php?page=chl-settings`);
      const text = await ap.locator('table.chl-forms').innerText();
      assert.match(text, /Contact\s+Creates or updates a lead\s+Service/);
      assert.match(text, /Details\s+Follow-up step\s+Budget, Timeline/);
      assert.match(text, /Quote/);
      assert.doesNotMatch(text, /Newsletter/);
    });

    await check('renaming a checkbox keeps values, removing one retires it, stages carry categories', async () => {
      await ap.goto(`${WP_URL}/wp-admin/options-general.php?page=chl-settings`);
      await ap.fill('#chl_checkboxes', 'FOLLOWED UP | clear\nCalled back');
      await ap.fill('#chl_stages', 'New\nContacted\nQuoted\nCustomer | Won\nHired | won\nNot a fit | Lost');
      await ap.check('input[name="chl_settings[list_fields][]"][value="budget"]');
      await Promise.all([ap.waitForNavigation(), ap.click('#submit')]);
      await lp.goto(listUrl());
      const headers = (await lp.locator('.wp-list-table thead th').allInnerTexts()).map((s) => s.trim());
      assert.ok(headers.includes('FOLLOWED UP') && headers.includes('Called back') && headers.includes('Budget'));
      assert.ok(!headers.includes('Added to CRM'));
      const id = lead('email', 'ana@mailtest.dev').id;
      assert.ok(await lp.locator(`#post-${id} input[data-field="cb:followed-up"]`).isChecked(), 'renamed checkbox kept its value');
      assert.match(await lp.locator(`#post-${id} .column-chl_f_budget`).innerText(), /\$5,000/);
      assert.equal(lead('email', 'ana@mailtest.dev').boxes['added-to-crm'], undefined, 'retired from the list');
      assert.equal(php(`echo wp_json_encode(get_post_meta(${id}, '_chl_cb_added-to-crm', true));`), '1', 'but the stored value is kept');
      assert.equal(php('echo wp_json_encode(chl_stage_category("hired"));'), 'won');
      await lp.goto(listUrl('&chl_cat=won'));
      assert.equal(await lp.locator('.wp-list-table tbody .column-title strong').count(), 1);
    });

    await check('a field with an Admin Field Label uses it as the column heading, full question on hover', async () => {
      await ap.goto(`${WP_URL}/wp-admin/options-general.php?page=chl-settings`);
      for (const key of ['do-you-have-previous-musical-experience', 'will-you-be-the-student-or-is-this-for-someone-else']) {
        await ap.check(`input[name="chl_settings[list_fields][]"][value="${key}"]`);
      }
      await Promise.all([ap.waitForNavigation(), ap.click('#submit')]);
      await lp.goto(listUrl());
      const long = await lp.locator('thead .column-chl_f_do-you-have-previous-musical-experience').innerText();
      assert.equal(long.trim(), 'Do you have previous musical experience?', 'no Admin Field Label: the full question');
      const admin = lp.locator('thead .column-chl_f_will-you-be-the-student-or-is-this-for-someone-else .chl-col-label');
      assert.equal(await admin.innerText(), 'Student', 'Admin Field Label used as the heading');
      assert.equal(await admin.getAttribute('title'), 'Will you be the student, or is this for someone else?');
      const budget = await lp.locator('thead .column-chl_f_budget').innerText();
      assert.equal(budget.trim(), 'Budget', 'short labels are left alone');
      // The lead screen keeps the full question.
      await vpage.goto(`${WP_URL}/contact/`);
      await submit(vpage, F.contact, { '1.3': 'Dee', '1.6': 'Ng', 2: 'dee@mailtest.dev', 3: '3605550123' });
      await submit(vpage, F.details, { 1: '$200', 2: 'Later', 3: 'Two years of piano', 4: 'My son' });
      const id = lead('email', 'dee@mailtest.dev').id;
      await lp.goto(`${WP_URL}/wp-admin/post.php?post=${id}&action=edit`);
      const answers = await lp.locator('#chl_fields').innerText();
      assert.match(answers, /Do you have previous musical experience\?\s*Two years of piano/);
      assert.match(answers, /Will you be the student, or is this for someone else\?\s*My son/);
    });

    await check('Gravity Forms has a Click Hive Leads tab that saves per-form settings', async () => {
      await ap.goto(`${WP_URL}/wp-admin/admin.php?page=gf_edit_forms&view=settings&subview=clickhive-leads&id=${F.news}`);
      assert.match(await ap.content(), /Save submissions from this form as leads/);
      await ap.click('label[for="_gform_setting_enabled"]');
      await ap.locator('select[name="_gform_setting_map_email"]').selectOption('1');
      await Promise.all([ap.waitForNavigation(), ap.click('#gform-settings-save')]);
      const s = php(`echo wp_json_encode(GFAPI::get_form(${F.news})['clickhive-leads']);`);
      assert.equal(s.enabled, '1');
      assert.equal(s.role, 'create');
      assert.equal(s.map_email, '1');
      // Now the newsletter form creates leads.
      await vpage.goto(`${WP_URL}/newsletter/`);
      await submit(vpage, F.news, { 1: 'reader2@mailtest.dev' });
      assert.ok(lead('email', 'reader2@mailtest.dev'));
    });

    await check('merge tags appear in the notification editor list', async () => {
      const tags = (id) => php(`echo wp_json_encode(wp_list_pluck(apply_filters('gform_custom_merge_tags', array(), ${id}, array(), ''), 'tag'));`);
      assert.deepEqual(tags(F.contact), ['{ch_lead_link}', '{ch_leads_url}']);
      php(`$f = GFAPI::get_form(${F.news}); $f['clickhive-leads']['enabled'] = '0'; GFAPI::update_form($f); echo 1;`);
      assert.deepEqual(tags(F.news), ['{ch_leads_url}'], 'no lead link on forms that are not connected');
    });

    await check('editors see the follow-up form without a token; submitting it without one is refused', async () => {
      await ap.goto(`${WP_URL}/details/`);
      assert.match(await ap.locator('.chl-followup-preview').innerText(), /Editor preview/);
      const before = php(`echo wp_json_encode(GFAPI::count_entries(${F.details}));`);
      await submit(ap, F.details, { 1: '$1', 2: 'Later' });
      assert.match(await ap.content(), /couldn(&#039;|'|’)t match this to your earlier details/);
      assert.equal(php(`echo wp_json_encode(GFAPI::count_entries(${F.details}));`), before);
    });

    await check('admins can delete; history goes with the lead', async () => {
      const id = lead('email', 'reader2@mailtest.dev').id;
      php(`wp_delete_post(${id}, true); echo 1;`);
      assert.equal(php(`global $wpdb; echo wp_json_encode((int) $wpdb->get_var("SELECT COUNT(*) FROM " . chl_history_table() . " WHERE lead_id = ${id}"));`), 0);
    });

    console.log(`\n${passed} passed`);
  } catch (e) {
    console.error(`\nnot ok ${passed + 1}\n`, e);
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
