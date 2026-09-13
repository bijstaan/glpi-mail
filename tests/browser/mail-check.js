// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// glpi-mail, in the only place most of it can be judged: a browser, and the
// real send path.
//
// The plugin's claims are of two kinds and this checks both, because neither
// half proves the other:
//
//  - **Does the page work?** The settings screen, the live preview iframe, the
//    Apply and Revert buttons, and the logo endpoint answering a client that
//    has no session — which is the case that matters, since the client is
//    Gmail's image proxy.
//  - **Does the mail work?** After Apply, a real ticket is rendered through
//    `NotificationTemplate::getTemplateByLanguage()` — GLPI's own code, the
//    same call `NotificationEventMailing` makes — and the result is examined.
//    That is the only assertion that can distinguish "the plugin wrote a
//    template" from "GLPI will send this".
//
// It leaves the instance as it found it: everything applied is reverted before
// it exits, unless MAIL_KEEP=1 says to leave the house style in place.
//
//   cd glpi-mail/tests/browser && SHOT_DIR=../../docs/screenshots node mail-check.js
//   MAIL_KEEP=1 …                       leave the templates styled afterwards
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const { fullPage } = require('./shot');
const fs = require('fs');
const path = require('path');

const BASE  = 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';
const KEEP  = process.env.MAIL_KEEP === '1';

// A ticket with a timeline on it. A one-line ticket renders a card that proves
// nothing about the parts of the design that only appear when there is
// something to show.
const TICKET = Number(process.env.MAIL_TICKET || 1);

const fail = [];
const check = (name, cond, detail) => {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + String(detail).slice(0, 180) : ''}`);
  if (!cond) fail.push(name);
};

/** Run PHP inside the container against a booted, logged-in GLPI. */
const php = (code) =>
  execSync('docker exec -i glpi-glpi-1 php', {
    encoding: 'utf8',
    maxBuffer: 32 * 1024 * 1024,
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + '(new Auth())->login("glpi","glpi",true);(new Plugin())->init(true);'
      + code,
  });

/**
 * The body GLPI would actually send for one ticket notification.
 *
 * Not the plugin's renderer and not the settings page's preview — this is
 * `NotificationTemplate::getTemplateByLanguage()`, which is what
 * `NotificationEventMailing::send()` queues. Everything else in this file is
 * about the administrator's experience; this is about the entity's.
 */
const sent = (templateName, event) =>
  php(`
    global $DB;
    $ticket = new Ticket();
    $ticket->getFromDB(${TICKET});
    $target = new NotificationTargetTicket($ticket->fields["entities_id"], "${event}", $ticket);
    $id = 0;
    foreach ($DB->request(["SELECT" => ["id"], "FROM" => "glpi_notificationtemplates",
                           "WHERE" => ["name" => "${templateName}"], "LIMIT" => 1]) as $r) {
        $id = (int) $r["id"];
    }
    $t = new NotificationTemplate();
    $t->getFromDB($id);
    // usertype matters. NotificationTarget::formatURL() returns an empty
    // string for anything but a GLPI or external user, so a target built
    // without one renders every link as href="" — which is what a real send
    // never does, because NotificationEvent::raiseEvent() sets it per
    // recipient. Without this the harness would be testing a case that does
    // not occur and would report a broken link that is not broken.
    $tid = $t->getTemplateByLanguage(
        $target,
        ["language" => "en_GB", "additionnaloption" => ["usertype" => NotificationTarget::GLPI_USER]],
        "${event}",
        ["item" => $ticket, "entities_id" => $ticket->fields["entities_id"]]
    );
    echo $t->templates_by_languages[$tid]["content_html"] ?? "(nothing)";
  `);

/**
 * Screenshot a mail body at three widths and both schemes.
 *
 * The image `src` is rewritten to this host first, and that deserves an
 * explanation rather than a quiet `replace`: a notification carries an
 * *absolute* URL for its logo, built at send time from `$CFG_GLPI['url_base']`,
 * and this dev instance has that set to `http://localhost` with no port. So the
 * mail is correct and the instance is misconfigured, and a browser on the host
 * cannot fetch the image either way. Rewriting the origin for the capture shows
 * what the mail looks like where url_base is right; the assertion that the
 * endpoint *works* is made separately, over real HTTP, further down.
 */
async function shootMail(browser, html, stem) {
  const file = path.join('/tmp', `glpimail-${stem}.html`);
  fs.writeFileSync(file, html.replace(/src="https?:\/\/[^/"]+\//g, `src="${BASE}/`));

  let blank = [];

  // Light only. The dark and narrow captures were dropped when the enhancement
  // layer became opt-in: with it off — which is the default and what these
  // screenshots document — a dark capture is identical to the light one and a
  // 390px capture is the same 600px card that a phone would scale, so both were
  // pictures of nothing in particular sitting in the repository.
  for (const [tag, opts] of [
    ['light', { viewport: { width: 760, height: 900 }, colorScheme: 'light' }],
  ]) {
    const ctx  = await browser.newContext(opts);
    const page = await ctx.newPage();
    await page.goto('file://' + file, { waitUntil: 'networkidle' });

    if (tag === 'light') {
      // Every label and heading in the card is a `##lang.*##` tag, and a tag
      // the target never assigns a value to substitutes to nothing rather than
      // erroring — an empty heading over a populated section, which reads as
      // this plugin having lost the label. `##lang.ticket.items##` is
      // registered by NotificationTargetTicket and never assigned; it got here
      // that way. Read after rendering, because whether a section is present
      // at all depends on ##IF## branches that only GLPI can resolve.
      blank = await page.evaluate(() =>
        [...document.querySelectorAll('.gm-label, .gm-eyebrow, .gm-title')]
          .filter((el) => el.textContent.trim() === '')
          .map((el) => el.className));
    }

    await page.screenshot({ path: `${SHOTS}/glpimail-mail-${stem}-${tag}.png`, fullPage: true });
    await ctx.close();
  }

  return blank;
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1500, height: 1100 } });
  const page = await ctx.newPage();

  const errs = [];
  const bad  = [];
  page.on('pageerror', (e) => errs.push(e.message));
  page.on('response', (r) => {
    if (r.status() >= 400) bad.push(`${r.status()} ${r.url()}`);
  });

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', 'glpi');
  await page.fill('input[type=password]', 'glpi');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');

  const config = `${BASE}/plugins/glpimail/front/config.php`;

  // ------------------------------------------------------------ the page ---

  await page.goto(config, { waitUntil: 'networkidle' });

  check('the settings page renders', (await page.locator('.glpimail-config').count()) === 1);
  check('it leads with status', /Nothing has been styled|carries the house style|carry the house style/
    .test(await page.locator('.glpimail-config .card').first().innerText()));
  check('it names the brand it will use',
    /glpi-whitelabel/.test(await page.locator('.glpimail-config').innerText()));

  // The warning about url_base must appear when, and only when, there is
  // something to warn about. Asserting it unconditionally is how this check
  // started failing the day somebody fixed the setting — which is exactly
  // backwards, and was the first thing it did.
  const urlBase = php('global $CFG_GLPI; echo $CFG_GLPI["url_base"];').trim();
  const expected = urlBase.replace(/^https?:\/\//, '') !== 'localhost:8081';
  const shown = await page.locator('.glpimail-config .alert:has-text("configured address")').count();

  check(
    expected
      ? 'a url_base that does not match is called out'
      : 'a url_base that matches is not complained about',
    (shown === 1) === expected,
    `url_base=${urlBase} alerts=${shown}`
  );

  const rows = await page.locator('.glpimail-config table tbody tr').count();
  check('every notification template is listed', rows >= 32, `rows=${rows}`);

  // ---------------------------------------------------------- the preview ---
  //
  // Read through the frame rather than by fetching the URL: the point is that
  // the settings page renders it, in an iframe, without the surrounding GLPI
  // stylesheet leaking in.
  const frame = page.frameLocator('#glpimail-preview');
  const card  = frame.locator('table.gm-card');

  await card.first().waitFor({ timeout: 10000 }).catch(() => {});
  check('the preview renders a card', (await card.count()) > 0);
  check('the preview left no tags unsubstituted',
    !/##[^#]{0,60}##/.test(await frame.locator('body').innerText()));
  check('the preview shows GLPI’s appended footer line',
    /Automatically generated by/.test(await frame.locator('body').innerText()));

  await fullPage(page, `${SHOTS}/glpimail-01-settings.png`);

  // Switching the picker is the one piece of JavaScript this plugin ships.
  await page.selectOption('#glpimail-preview-pick', 'Password Forget');
  await page.waitForTimeout(1500);
  check('choosing another notification reloads the preview',
    /Reset your password/.test(await page.frameLocator('#glpimail-preview').locator('body').innerText()));

  // ------------------------------------------------------- the logo, cold ---
  //
  // A fresh context with no cookies: this is Gmail's image proxy, not a
  // logged-in administrator. GLPI 11 routes plugin scripts through its firewall
  // and defaults them to "must be authenticated"; without the exemption in
  // setup.php this answers an <img> with an access-denied page.
  const cold = await browser.newContext();
  const logo = await cold.request.get(`${BASE}/plugins/glpimail/front/logo.php`);

  check('the logo answers a client with no session', logo.status() === 200, `status=${logo.status()}`);
  check('as an image', /^image\//.test(logo.headers()['content-type'] || ''), logo.headers()['content-type']);
  check('cacheable', /max-age=\d+/.test(logo.headers()['cache-control'] || ''), logo.headers()['cache-control']);

  const bytes = (await logo.body()).length;
  // Downscaled to twice the 30px the masthead draws it at. The whole point is
  // that a print original is not sent to every recipient of every mail.
  check('and small enough to be in every mail', bytes < 60000, `${bytes} bytes`);
  await cold.close();

  // ------------------------------------------------------------ apply ------

  // The instance may already be styled — somebody pressed Apply, which is what
  // the button is for. Everything below has to work from either state, and the
  // run has to put back the one it found. An earlier version assumed stock and
  // reported two failures that were its own assumption.
  const startedStyled = Number(
    php('echo countElementsInTable("glpi_notificationtemplatetranslations",'
      + ' ["content_html" => ["LIKE", "<!--glpimail%"]]);').trim()
  ) > 0;

  if (startedStyled) {
    console.log('  (the instance was already styled — reverting first, and re-applying at the end)');
    await page.click('button:has-text("Revert everything")');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
  }

  const before = sent('Tickets', 'new');
  check('before applying, GLPI sends escaped markup',
    before.includes('&lt;div') || before.includes('&lt;p'),
    'this is the GLPI 11.0.8 bug the plugin exists for');

  await page.click('button:has-text("Apply to GLPI’s own notifications")');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(500);

  const styled = await page.locator('.glpimail-config table .badge:has-text("Styled")').count();
  const described = await page.locator('.glpimail-config table tbody tr').evaluateAll(
    (trs) => trs.filter((tr) => (tr.querySelectorAll('td')[2]?.textContent.trim() || '') !== '—').length
  );

  // Exactly the described ones, and not one row more. `>=` would have hidden
  // the case this is really guarding: "apply to GLPI's own" quietly wrapping
  // everything else as well, which is the other button.
  check('applying styles exactly what is described', styled === described,
    `styled=${styled} described=${described}`);
  check('and that is at least GLPI’s own thirty-two', described >= 32, `described=${described}`);

  await fullPage(page, `${SHOTS}/glpimail-02-applied.png`);

  // ------------------------------------------- what GLPI will actually send --

  const after = sent('Tickets', 'new');

  check('the sent body is no longer escaped', !after.includes('&lt;div'));
  check('it is this plugin’s markup', after.includes('<!--glpimail:1-->'));
  check('built as a card', after.includes('class="gm-card"'));
  // The enhancement layer is off by default, and that is the compatibility
  // claim rather than an omission: `@media` and `!important` are what took the
  // score from 99.4% to 89.0%. GLPI emits the `<style>` element whatever the
  // template says, so what is asserted is that nothing is inside it.
  check('the stylesheet GLPI emits is left empty',
    /<style[^>]*>\s*<\/style>/.test(after),
    (after.match(/<style[^>]*>[\s\S]{0,60}/) || [''])[0].replace(/\s+/g, ' '));

  check('so no @media or !important reaches the client',
    !after.includes('@media') && !after.includes('!important'));

  // And the properties the rebuild removed stay removed. A regression here
  // renders perfectly well in a browser and is invisible until somebody opens
  // the mail in Outlook.
  for (const banned of ['padding:', 'margin:', 'text-align:', 'font-weight:', 'display:', 'word-break:']) {
    check(`the sent body uses no ${banned.slice(0, -1)}`, !after.includes(banned));
  }
  check('the masthead points at the logo endpoint',
    after.includes('/plugins/glpimail/front/logo.php'));
  check('the link back to the ticket survived tag substitution',
    /href="https?:\/\/[^"]*\/index\.php\?redirect=ticket[_%]/.test(after),
    (after.match(/href="[^"]*ticket[^"]*"/) || ['(none)'])[0]);
  check('no tag was left unsubstituted in the sent body',
    !/##[^#\s]{1,60}##/.test(after),
    (after.match(/##[^#\s]{1,60}##/) || ['-'])[0]);

  const blankTicket = await shootMail(browser, after, 'ticket');
  check('no label or heading rendered empty', blankTicket.length === 0, blankTicket.join(' | '));

  const survey = sent('Ticket Satisfaction', 'satisfaction');
  check('a second notification renders too', survey.includes('class="gm-card"'));
  const blankSurvey = await shootMail(browser, survey, 'satisfaction');
  check('nor in the survey', blankSurvey.length === 0, blankSurvey.join(' | '));

  // ------------------------------- what a mail client will make of it -------
  //
  // The claim this plugin makes about compatibility is a number, so it is
  // asserted as one. A real notification is queued, drained through GLPI's own
  // cron into the mailpit sandbox, and scored against caniemail's client
  // support tables — the same check a person would run by opening the message
  // and pressing HTML Check.
  //
  // Skipped rather than failed where mailpit is not running: it is a local
  // service, and a check that cannot run is not a check that failed.
  let mailpit = true;
  try {
    await ctx.request.get('http://localhost:8025/api/v1/info', { timeout: 2000 });
  } catch {
    mailpit = false;
    console.log('  (mailpit is not running — skipping the client-support score)');
  }

  if (mailpit) {
    php(`
      global $DB;
      $ticket = new Ticket();
      $ticket->getFromDB(${TICKET});
      NotificationEvent::raiseEvent('new', $ticket, ['entities_id' => $ticket->fields['entities_id']]);
      $task = new CronTask();
      $task->getFromDBbyName('QueuedNotification', 'queuednotification');
      QueuedNotification::cronQueuedNotification($task);
    `);

    const list = await (await ctx.request.get('http://localhost:8025/api/v1/messages?limit=20')).json();
    const sent = list.messages.find((m) => /New ticket/i.test(m.Subject));

    check('the notification reached the mail sandbox', sent !== undefined,
      list.messages.map((m) => m.Subject).slice(0, 3).join(' | '));

    if (sent) {
      const score = await (await ctx.request.get(
        `http://localhost:8025/api/v1/message/${sent.ID}/html-check`)).json();

      // 99% is the bar this design was rebuilt to clear: padding became
      // cellpadding, margins became spacer rows, colours and sizes became
      // attributes. A regression here means somebody reached for a CSS
      // property that a third of mail clients do not implement.
      check('the delivered mail scores at least 99% client support',
        score.Total.Supported >= 99,
        `supported ${score.Total.Supported.toFixed(2)}%  partial ${score.Total.Partial.toFixed(2)}%  `
        + `unsupported ${score.Total.Unsupported.toFixed(2)}%`);

      // The two warnings that cannot be removed are GLPI's own wrapper. Any
      // third one with real unsupported weight is this plugin's doing.
      const heavy = score.Warnings
        .filter((w) => !['<body> element', '<style> element'].includes(w.Title))
        .filter((w) => w.Score.Unsupported > 25);

      check('no property is unsupported by a quarter of clients',
        heavy.length === 0,
        heavy.map((w) => `${w.Title} ${w.Score.Unsupported.toFixed(0)}%`).join(', '));
    }
  }

  // ------------------------------------------- what a plugin contributed ----
  //
  // A plugin describes its own notification through the `glpimail_letters`
  // hook; without one its template is merely wrapped. There is nothing to check
  // on an instance where no such plugin is active, and "nothing registered" is
  // a legitimate state rather than a failure — so this reports and moves on.
  const contributed = await page.evaluate(() =>
    [...document.querySelectorAll('.glpimail-config table tbody tr')]
      .map((tr) => ({
        // The first cell holds the template name in a bare <div> and its
        // summary in a second, styled one. `textContent` on the cell runs the
        // two together, which made the failure message longer than the page.
        name: tr.querySelector('td > div')?.textContent.trim() || '',
        from: tr.querySelectorAll('td')[2]?.textContent.trim() || '',
      }))
      .filter((r) => r.from !== '' && r.from !== 'GLPI' && r.from !== '—'));

  if (contributed.length === 0) {
    console.log('  (no plugin has registered a letter on this instance — skipping)');
  } else {
    check('a contributed letter is attributed to its plugin',
      contributed.every((r) => /^[a-z0-9]+$/.test(r.from)),
      contributed.map((r) => `${r.name}=${r.from}`).join(' | '));

    // Rendered through the send path like the others, so the screenshot in the
    // README is the mail rather than the preview.
    const first = contributed[0];
    const body  = php(`
      global $DB;
      $id = 0; $itemtype = '';
      foreach ($DB->request(["FROM" => "glpi_notificationtemplates",
                             "WHERE" => ["name" => ${JSON.stringify(first.name)}], "LIMIT" => 1]) as $r) {
          $id = (int) $r["id"]; $itemtype = (string) $r["itemtype"];
      }
      echo $id > 0 ? $itemtype : "";
    `).trim();

    check(`${first.name} belongs to a real itemtype`, body !== '', body);
  }

  // ------------------------------------------------------------ revert -----

  if (KEEP || startedStyled) {
    console.log(KEEP
      ? '  (MAIL_KEEP=1 — leaving the templates styled)'
      : '  (the instance was styled when this started — leaving it that way)');
  } else {
    await page.click('button:has-text("Revert everything")');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    const left = await page.locator('.glpimail-config table .badge:has-text("Styled")').count();
    check('reverting takes the house style back off', left === 0, `styled=${left}`);

    const restored = sent('Tickets', 'new');
    check('and restores the body byte for byte', restored === before);
  }

  // ------------------------------------------------------------------------

  check('no javascript errors', errs.length === 0, errs.join(' | '));
  check('nothing answered 4xx or 5xx', bad.length === 0, bad.slice(0, 5).join(' | '));

  console.log(fail.length ? 'FAILURES: ' + fail.join(', ') : 'all mail checks passed');
  await browser.close();
  process.exit(fail.length ? 1 : 0);
})();
