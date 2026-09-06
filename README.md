# GLPI Mail

Responsive, branded notification templates for the whole instance — for GLPI 11.

A notification is the only part of an ITSM system most people ever see. A
requester opens two tickets a year. They never log in, they never see the
dashboard, and their entire impression of the service is four emails.

## The state of the art it replaces

GLPI 11.0.8 seeds its notification templates from `install/empty_data.php`, and
the HTML bodies in that file are stored **HTML-escaped** — `&lt;div&gt;` where
`<div>` was meant. Nothing in the send path decodes them:
`NotificationTemplate::getTemplateByLanguage()` runs the body through
`process()`, which substitutes tags and rewrites relative `href`s, and then
concatenates the result straight into `<body>`.

So a stock GLPI 11.0.8 sends this, to a customer:

```
&lt;!-- description{ color: inherit; background: #ebebeb; … } --&gt;
&lt;div&gt;&lt;/div&gt;
&lt;div&gt; URL : &lt;a href="…"&gt;&lt;/a&gt; &lt;/div&gt;
&lt;p class="description b"&gt;&lt;strong&gt;Ticket: Description&lt;/strong&gt;&lt;/p&gt;
```

Reproduce it with `tests/process.php`'s harness.

Two more, found while reading the targets rather than the tag lists:

- The stock **certificate** and **domain** expiry templates use flat tags with
  no `##FOREACH##` around them, but `NotificationTargetCertificate` and
  `NotificationTargetDomain` only ever fill those tags *inside* the loop. Every
  field but the entity name arrives blank.
- The stock **reservation** template asks for `##reservation.tech##`, which is
  not a tag. It is printed literally.

## What this produces instead

One 600-pixel card, in a table with inline styles. This is not a mock-up: it is
the body GLPI itself produced for ticket #1 through
`NotificationTemplate::getTemplateByLanguage()` — the same call
`NotificationEventMailing` makes when it queues a message.

![a ticket notification as GLPI renders it, with the whitelabel logo in the masthead, a status pill, labelled rows and the recent activity](docs/screenshots/glpimail-mail-ticket-light.png)

It is a fixed 600-pixel table built from `cellpadding`, `bgcolor` and spacer
cells, which is the subset of HTML that every mail client agrees on — see
[Client support](#client-support-994) for the measurements, and for the
dark-mode and phone-stacking layer that is available and switched off.

Thirty-two templates, covering every notification GLPI ships: tickets, changes,
problems, approvals, satisfaction surveys, reservations, stock and expiry
alerts, passwords, projects, the knowledge base, and the housekeeping ones
nobody looks at until they matter. Most are much shorter than the one above —
the survey invitation is a sentence and a button:

![the satisfaction survey invitation: a heading, one line, one button and two rows](docs/screenshots/glpimail-mail-satisfaction-light.png)

## Branding

The name and the logo come from **glpi-whitelabel**, on the same terms glpi-pdf
uses:

| glpi-whitelabel        | what a notification carries          |
| ---------------------- | ------------------------------------ |
| configured             | its name and its logo                |
| absent or unconfigured | nothing — no name, no logo, no "GLPI" |
| half-configured        | whatever it has                       |

A customer has never heard of GLPI. An unbranded mail beats one wearing a
stranger's product name.

Two formats glpi-whitelabel is right to accept are wrong in an inbox, and the
settings page says so rather than shipping a broken image:

- **SVG** — no mainstream mail client renders it.
- **WebP** — Outlook on Windows renders it as nothing at all.

In both cases the masthead falls back to the wordmark. So does a blocked image,
because the logo is an `<img>` whose `alt` is the brand name — which is what
most first-time readers see anyway, Outlook and Gmail both blocking remote
images by default.

## Applying it

Nothing happens on install. Templates are a thing administrators edit, often
once, years ago, with wording somebody approved — and a plugin that rewrites
them the moment it is enabled has destroyed work it cannot see.

**Setup → Notifications → Notification templates** is GLPI's page.
**Setup → General → GLPI Mail** is this one, and it has three buttons:

- **Apply to GLPI's own notifications** — rewrites the thirty-two described in
  `src/Catalog.php`.
- **Apply to everything, wrapping the rest** — plus anything else in the table,
  including other plugins' templates, whose bodies are put inside the same card
  **unchanged**. That is how the estate's mail looks like one estate without
  this plugin having to know what a third-party template says.
- **Revert everything** — puts back the body from *before* this plugin first
  touched it. So does uninstalling, which matters: a styled body references this
  plugin's own logo endpoint, and leaving it behind would put a broken image at
  the top of every mail the instance sends, weeks later, with nothing to connect
  it to a plugin somebody removed.

![the settings page: status, the appearance form, the live preview and the template table](docs/screenshots/glpimail-01-settings.png)

The original bodies live in `glpi_plugin_glpimail_backups`, written **once** per
translation — the second apply does not back up the first apply's output.

Applied, with this suite's own three plugin templates wrapped rather than
rewritten:

![the template table after applying, thirty-two rows marked Styled and three marked Stock wrapped](docs/screenshots/glpimail-02-applied.png)

Settings are baked into the bodies rather than read at send time, so changing
the accent means applying again. The page says so.

## A plugin can describe its own notifications

Without doing anything, a plugin's template is **wrapped**: its own body,
whatever that body is, put inside the house card untouched. That is the right
fallback and a poor destination — a wrapped body keeps whatever markup its
author wrote, usually a `<ul>` of bolded labels, because that is what one can
write in GLPI's template editor. Wrapping makes the estate's mail share an
envelope; describing makes it share a design. It is also the difference between
a notification having a *button* and having a link in a paragraph, because a
button is not something you can write in a template editor and have work in
Outlook.

A plugin describes its own by registering under `glpimail_letters`, the same
shape glpi-pdf uses for `glpipdf_documents`:

```php
$PLUGIN_HOOKS['glpimail_letters']['glpisignal'] = [MailLetters::class, 'offers'];
```

```php
public static function offers(): array
{
    // glpi-mail is optional. Returning an offer that references one of its
    // classes when it is not installed turns a missing optional dependency
    // into a fatal.
    if (!class_exists(Letter::class)) {
        return [];
    }

    return [[
        'name'     => 'On-call page',        // the NotificationTemplate's name
        'itemtype' => Alert::class,
        'summary'  => __('The page sent to whoever is on call.', 'glpisignal'),
        'build'    => [self::class, 'page'],
    ]];
}

public static function page(): Letter
{
    return Letter::make()
        ->eyebrow('##alert.action## — ##lang.alert.step## ##alert.step##')
        ->title('##alert.name##')
        ->pill('##alert.severity##', Letter::BAD)
        ->button('Acknowledge it', '##alert.url##')
        ->row('##lang.alert.host##', '##alert.host##', when: 'alert.host')
        ->row('##lang.alert.first_seen##', '##alert.first_seen##')
        ->when('alert.comment', static fn(Letter $l) =>
            $l->panel('##lang.alert.comment##', '##alert.comment##'));
}
```

![an on-call page from glpi-signal, described through the hook and rendered in the house style](docs/screenshots/glpimail-mail-page-light.png)

`Letter` is the whole contract. It says *what* the mail contains — an eyebrow, a
title, a status pill, labelled rows, a quoted panel — and nothing about how any
of it is drawn, for the same reason glpi-pdf's `Doc` says nothing about TCPDF:
mail clients support a narrow, undocumented and mutually inconsistent subset of
HTML, and six plugins left to discover that separately produce six dialects of
it. There is no column, no box, no width and no colour in the vocabulary.

Everything is plain text except `##tags##`, which pass through untouched —
`##x.y##` is substituted at send time and `##lang.x.y##` with a label translated
into the **recipient's** language. Use the `lang` form for every field label you
can.

A few things the contract does for you:

| you write | why it matters |
| --- | --- |
| `->pill(…)` twice | one row of pills, not two stacked tables |
| `when: '##alert.host##'` | the hashes come off — GLPI's `##IF##` needs the bare field, and left on it renders `##IF##alert.host####`, which matches nothing and silently drops the block |
| `->pill('x', 'critical')` | an unknown tone is a grey pill, not a lost notification |
| `->loop('events', 5, …)` | `##FOREACH LAST 5 events##`, capped so a mail is a summary |

An offer whose name and itemtype match one of GLPI's own is refused and logged —
otherwise a plugin could quietly redefine what a ticket notification says by
shipping a name collision. A builder that throws is dropped and logged; every
other template still applies.

The settings page's **Described by** column says where each body's wording came
from: GLPI, a plugin, or nobody (in which case it is wrapped).

Five plugins in this suite describe their notifications through it. Three —
**glpi-major**, **glpi-improve** and **glpi-entitle** — already used GLPI's
template system properly and only needed describing. Two did not: they bypassed
it entirely and wrote finished bodies straight into `glpi_queuednotifications`,
and were converted at the same time as this contract landed.

- **glpi-signal** composed its on-call pages in PHP. Its wording now lives in a
  seeded template and is described here, so a page arrives as the house card
  with the severity as a pill and a full-width **Acknowledge it** button.
- **glpi-report** sent the whole report as the message body — which it had to,
  because the report is a complete `<!DOCTYPE html>` document and a template
  would nest it inside another one. The mail is now a covering note with the
  headline figures on it, described here, and the report travels as an
  attachment and at its own URL.

In both, **composition moved and delivery did not**: who gets paged is the
on-call rota, and who receives a review is the contacts on that report. Neither
is a `Notification` target list, so both plugins still queue their own rows —
what changed is that they no longer write the words.

Nothing in any of the five is required for its plugin to work. glpi-mail is
optional in all of them, each guards its offer on `class_exists(Letter::class)`,
and with glpi-mail absent every one of those notifications still sends.

## Does GLPI know its own address?

Every link in every mail, and this plugin's masthead image, is built from
`url_base` at send time. If it is wrong the mail goes out with a broken logo and
links nobody can follow, and nothing anywhere in the interface says so — the
application works perfectly on the address you are already using.

So the settings page compares `url_base` against the address you are browsing
and says something when they differ. It is a hint, not proof: a reverse proxy,
or an internal name and an external one, are both good reasons for the two to
disagree.

It also says something about the **subject line**, which is the one place
"GLPI" still gets through a fully whitelabelled instance: core prefixes every
notification with the entity's `notification_subject_tag` and falls back to the
literal product name when no entity has set one. glpi-whitelabel's `app_name`
override does not reach it, and neither can this plugin — it is one field on the
entity, and the page says which.

And the preview is rendered exactly the way the mail is, which means a wrong
`url_base` shows up there as a broken image too. That is deliberate. Better a
broken image on the settings page than in eight hundred inboxes.

## The preview

The settings page renders any template in an iframe, using GLPI's own
`NotificationTemplate::process()` on invented data, inside the wrapper GLPI
builds when it sends — including the `Automatically generated by …` line GLPI
appends *after* the body.

That last line is not cosmetic detail. It is a bare text node with no element
around it, so no selector reaches it and no inline style can be put on it; it is
the reason `src/Css.php` styles `body` at all. A preview that hid it would be
hiding the one piece of the layout this plugin does not control.

## How it is built

| file                | what it decides                                            |
| ------------------- | ---------------------------------------------------------- |
| `src/Catalog.php`   | what each notification says, as blocks                      |
| `src/Shell.php`     | what a block looks like, in HTML and in plain text          |
| `src/Theme.php`     | the palette, light and dark, derived from one accent        |
| `src/Css.php`       | the `<style>` block: media queries and `prefers-color-scheme` |
| `src/Brand.php`     | whose name is on it                                         |
| `src/Logo.php`      | the masthead image, downscaled and cached                   |
| `src/Templates.php` | reading and writing GLPI's templates, and the backup        |
| `src/Preview.php`   | the send path, minus the recipient                          |

One description, two renderings — the plain-text alternative comes from the same
blocks as the HTML, so it cannot drift the way a hand-maintained pair always
does. GLPI will happily send an HTML-only notification; it should not.

## Client support: 99.4%

Mailpit scores a message against caniemail's client-support tables. On a real
ticket notification, every template in the catalog now scores between **99.15%
and 99.77% supported**, with unsupported under 0.4%. It started at 89.0%.

The gap was not a few unlucky properties — it was the whole approach. The
scoring is **per node, weighted by client share**, so what a property costs is
what the clients that fail it are worth times how much of the message uses it.
Measured, one at a time:

| dropped | replaced by |
| --- | --- |
| `padding` | `cellpadding` and gutter cells |
| `margin` | spacer rows |
| `text-align` | `align=` |
| `font-weight` | `<strong>` |
| `background-color`, `width`, `height`, `border` | `bgcolor`, `width`, `height`, and a 1px coloured table for the card's edge |
| `display`, `max-width`, `max-height`, `white-space`, `word-break`, `outline`, `text-transform` | nothing — they were polish |

What survives in `style=` is `color`, `font-family` and `font-size`, which cost
nothing at all, plus four properties kept **on a handful of nodes each**, which
is where the per-node scoring makes them nearly free:

| kept | where | cost everywhere → cost here |
| --- | --- | --- |
| `text-decoration:none` | the button's anchor | 2.23 → 0.00 |
| `line-height` | the title, the lede, the quoted panel | 1.25 → 0.04 |
| `letter-spacing` | the small-caps labels | 0.84 → 0.00 |
| `border-radius` | the card's edge and the button | 2.62 → 0.00 |

Two warnings can never be removed, because they are GLPI's own wrapper rather
than this plugin's markup: `<body>` (38.8% supported) and `<style>` (61.1%),
both emitted by `NotificationTemplate::getTemplateByLanguage()` whatever the
template says. A message containing nothing but the word "hello" inside that
wrapper scores 89.8%.

The visible cost is small: the eyebrow and section labels are sentence case
rather than upper (`text-transform` had to go, and the text is a `##tag##` so it
cannot be upper-cased at build time), and the status pills are square.

### `<font>`, unclosed, on purpose

The body ends with an open `<font>` tag. GLPI appends its "Automatically
generated by …" line *after* the template body and inside `<body>`, as a bare
text node — no selector reaches it and no inline style can be put on it. HTML
parsing closes the `<font>` at `</body>`, so that line inherits from it and
arrives as small muted type instead of the client's default 16px serif. It costs
nothing on the score, and a sanitiser that closes or drops it leaves the line
where it started. `tests/render.php` asserts it is there, and that it is the
only unclosed element in the message.

### The enhancement layer is opt-in

Dark mode and phone stacking both need `@media` and `!important`, which together
cost about ten points — 99.4% becomes 89.0%. They are a setting, **off by
default**, and the settings page states both numbers next to the checkbox rather
than describing the choice in adjectives. Off, every notification is a fixed
600-pixel table that renders the same everywhere and that phones scale to fit.
On, the readers whose client honours it get a better message and the rest get
exactly the card they got before.

## Tests

```sh
sh tests/run.sh                                             # pure PHP, runs in CI
docker exec glpi-glpi-1 php \
    /var/www/glpi/plugins/glpimail/tests/process.php        # against a real GLPI
cd glpi-mail/tests/browser && SHOT_DIR=../../docs/screenshots \
    node mail-check.js                                      # the page and the send path
```

`render.php` renders all thirty-two and checks the things a mail template is
silently wrong about: unbalanced `##IF##` and `##FOREACH##` constructs, markup
that does not close, a tag whose case got mangled on the way through.

`process.php` puts every body through GLPI's *own* processor and requires that
nothing beginning `##` survives. A surviving tag is a tag GLPI would have
printed to a customer.

`mail-check.js` drives the settings page the way an administrator would, then
asserts against the thing that matters: it renders a real ticket through GLPI's
send path before and after applying, requires that the "before" is escaped (the
bug at the top of this file) and the "after" is a card, fetches the logo
endpoint from a context with **no session** — Gmail's image proxy has none —
and finishes by reverting and checking the original body came back byte for
byte. It writes the screenshots in this README on the way through.

It caught four things worth naming:

- **The form action.** `$_SERVER['PHP_SELF']` is `/index.php` under GLPI 11's
  front controller, not the plugin script, so Apply posted to the router, got a
  400, and left the page looking exactly as though it had worked.
  glpi-whitelabel already documents this; the forms here now carry no `action`
  at all. **glpi-pdf's settings page still has the `PHP_SELF` version.**
- **Two headings that rendered empty or wrong.**
  `##lang.ticket.items##` is registered as a tag by `NotificationTargetTicket`
  and never assigned a value, and `##lang.ticket.timelineitems##` is
  "Processing ticket" — a status, not a heading. The check now fails if any
  label, eyebrow or title in a *sent* body renders blank.
- **A race in the logo cache.** It swept the directory before writing, so one
  request could delete the file another was about to read and answer 404 — a
  broken masthead with nothing in any log. It now writes through a `rename()`
  and sweeps afterwards.
- **A low-contrast button.** Tabler's `primary` follows the palette, and as an
  outline on a warm palette it all but disappeared.

## Requirements

GLPI 11.0 or later. No composer dependencies — there is deliberately no
`composer.json` anywhere in this monorepo. glpi-whitelabel is optional; without
it, notifications are styled but unbranded.

## Install

```bash
# from the GLPI root — the directory has to be named for the plugin
# key, which is not the repository name
git clone https://github.com/bijstaan/glpi-mail.git plugins/glpimail
php bin/console plugin:install -u glpi glpimail
php bin/console plugin:activate glpimail
```

## Licence

GNU General Public License, version 3 or later — the same licence as GLPI.
This plugin is loaded into GLPI's process and extends its classes, so it is a
derivative work of GLPI and carries GLPI's licence. See [LICENSE](LICENSE).
