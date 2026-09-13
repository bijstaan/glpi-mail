<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Settings for the notification templates.
 *
 * House style, as settled across the suite's config pages: one
 * `container-fluid` capped at 960px, cards as sections, status first, and READ
 * opens the page while UPDATE saves it — a profile with only the former sees
 * the values and no buttons rather than a form that answers Save with
 * access-denied.
 *
 * There is more than one form here because there is more than one kind of act:
 * saving settings, applying a template, reverting a template. Each posts a
 * hidden `section` and the handler writes only that section's keys. That is not
 * decoration — four settings cards posting into one handler that rebuilt every
 * setting from `$_POST` is a bug this estate has already had once, and the
 * symptom is that saving one card silently unticks every checkbox in the
 * others, because an unchecked box and an absent box look identical in a POST.
 *
 * The right is core `config`. This plugin owns no data and grants access to
 * none; it changes what GLPI's own notifications look like, which is a
 * configuration act, and a right of its own would be one more thing to grant
 * for no additional authority.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimail\Brand;
use GlpiPlugin\Glpimail\Catalog;
use GlpiPlugin\Glpimail\Settings;
use GlpiPlugin\Glpimail\Templates;

Session::checkRight('config', READ);

$can_edit = Session::haveRight('config', UPDATE);

$section = (string) ($_POST['section'] ?? '');

if ($section !== '') {
    Session::checkRight('config', UPDATE);

    switch ($section) {
        case 'settings':
            Settings::save($_POST);
            Session::addMessageAfterRedirect(__('Settings saved.', 'glpimail'), true, INFO);
            break;

        case 'apply':
            $id = (int) ($_POST['templates_id'] ?? 0);

            if ($id > 0) {
                $rows = Templates::apply($id);
                Session::addMessageAfterRedirect(
                    sprintf(_n('%d body rewritten.', '%d bodies rewritten.', $rows, 'glpimail'), $rows),
                    true,
                    $rows > 0 ? INFO : WARNING
                );
            } else {
                $result = Templates::applyAll(!empty($_POST['known_only']));
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('%1$d templates styled, %2$d bodies rewritten.', 'glpimail'),
                        $result['templates'],
                        $result['rows']
                    ),
                    true,
                    INFO
                );
            }
            break;

        case 'revert':
            $id = (int) ($_POST['templates_id'] ?? 0);

            if ($id > 0) {
                $rows = Templates::revert($id);
            } else {
                $rows = Templates::revertAll()['rows'];
            }

            Session::addMessageAfterRedirect(
                sprintf(_n('%d body restored.', '%d bodies restored.', $rows, 'glpimail'), $rows),
                true,
                $rows > 0 ? INFO : WARNING
            );
            break;
    }

    Html::back();
}

Html::header(
    __('Notification templates', 'glpimail'),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector('Config', 'config')
        : 'config',
    'Config'
);

$settings = Settings::all();
$brand    = Brand::resolve();
$survey   = Templates::survey();
$catalog  = Catalog::all();
$e        = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$styled = 0;
$stale  = 0;
foreach ($survey as $row) {
    if ($row['state'] === Templates::STATE_STYLED) {
        $styled++;
    } elseif ($row['state'] === Templates::STATE_STALE) {
        $stale++;
    }
}

// No `action` attribute on any form below, deliberately.
//
// `$_SERVER['PHP_SELF']` is `/index.php` under GLPI 11's front controller, not
// this script. A form pointed at it posts to the router, which answers 400 and
// routes the post nowhere — the page looks like it acted and has not. Verified
// in a browser here: the Apply button posted to `/index.php`, got a 400, and
// left every template stock while the page came back looking normal.
//
// Omitting the attribute posts to the current URL, which is the only thing
// that is reliably right. glpi-whitelabel's settings page reached the same
// conclusion; glpi-pdf's still carries the `PHP_SELF` version.
echo "<div class='container-fluid glpimail-config' style='max-width:960px'>";

// ------------------------------------------------------------ status first

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Status', 'glpimail') . '</h3></div>';
echo "<div class='card-body'>";

if ($styled === 0) {
    echo "<div class='alert alert-secondary mb-3'>"
       . '<i class="ti ti-mail-off me-1"></i>'
       . __s('Nothing has been styled yet. This plugin changes nothing about what the '
           . 'instance sends until you apply it below.', 'glpimail')
       . '</div>';
} else {
    echo "<div class='alert alert-success mb-3'>"
       . '<i class="ti ti-check me-1"></i>'
       . $e(sprintf(
           _n('%d template carries the house style.', '%d templates carry the house style.', $styled, 'glpimail'),
           $styled
       ))
       . '</div>';
}

if ($stale > 0) {
    echo "<div class='alert alert-warning mb-3'>"
       . $e(sprintf(
           __('%d template was styled by an older version of this plugin. Re-applying brings it up to date.', 'glpimail'),
           $stale
       ))
       . '</div>';
}

if ($brand->branded) {
    echo "<div class='alert alert-info mb-3'>"
       . '<i class="ti ti-tag me-1"></i>'
       . $e(sprintf(
           __('Notifications carry “%s”, from glpi-whitelabel.', 'glpimail'),
           $brand->name !== '' ? $brand->name : __('your logo', 'glpimail')
       ))
       . '</div>';
} else {
    echo "<div class='alert alert-info mb-3'>"
       . __s('glpi-whitelabel is not configured, so notifications go out unbranded — no name '
           . 'and no logo. They will never carry GLPI’s.', 'glpimail')
       . '</div>';
}

/**
 * The one place "GLPI" still gets through.
 *
 * glpi-whitelabel rebrands the notification *footer* by setting
 * `$CFG_GLPI['app_name']`, and this plugin rebrands the body. Neither reaches
 * the subject line: core prefixes every notification with the entity's
 * `notification_subject_tag`, and where no entity in the tree has set one it
 * falls back to the literal string `GLPI` — see
 * {@see \NotificationTarget::getSubjectPrefix()}.
 *
 * So an instance can be fully whitelabelled and still send an entity
 * “[GLPI] Your ticket has been solved”, which is the first thing they read and
 * the only place the product name survives. It is worth a line here because
 * there is nothing on the whitelabel page that would tell you, and because the
 * fix is one field on the entity rather than anything this plugin can do.
 */
$subject_tag = trim((string) Entity::getUsedConfig('notification_subject_tag', 0, '', ''));

if ($subject_tag === '') {
    echo "<div class='alert alert-warning mb-3'>"
       . '<i class="ti ti-mail-question me-1"></i>'
       . __s('Every notification subject starts with “[GLPI]”. That prefix is the entity’s '
           . 'notification tag, and no entity has set one — core falls back to the product '
           . 'name. Set it on the root entity under Administration → Entities → '
           . 'Notifications; whitelabelling does not reach it.', 'glpimail')
       . '</div>';
}

/**
 * Does GLPI know its own address?
 *
 * Worth a card of its own here, because a notification is the one place where
 * getting this wrong is invisible from inside the application. Every link in
 * every mail GLPI sends, and this plugin's masthead image, is built from
 * `url_base` at send time — so if it is wrong, the mail goes out with a broken
 * logo and links nobody can follow, and nothing in the interface ever says so.
 * The preview below is rendered exactly the way the mail is, which means the
 * preview shows it too. That is deliberate: better a broken image on this page
 * than a broken image in eight hundred inboxes.
 *
 * Compared against the address this administrator is browsing, which is a hint
 * and not proof — a reverse proxy, or an internal name and an external one, are
 * both legitimate reasons for the two to differ. So this is phrased as
 * something to check.
 */
// Declared, not assumed. GLPI 11 executes legacy front scripts through its
// router, and a global that happens to be in scope today is not a contract.
global $CFG_GLPI;

$url_base = (string) ($CFG_GLPI['url_base'] ?? '');
$browsing = ($_SERVER['HTTP_HOST'] ?? '');

if ($url_base !== '' && $browsing !== '' && parse_url($url_base, PHP_URL_HOST) !== null) {
    $configured = parse_url($url_base, PHP_URL_HOST)
        . (parse_url($url_base, PHP_URL_PORT) !== null ? ':' . parse_url($url_base, PHP_URL_PORT) : '');

    if (strcasecmp($configured, (string) $browsing) !== 0) {
        echo "<div class='alert alert-warning mb-3'>"
           . '<i class="ti ti-link-off me-1"></i>'
           . $e(sprintf(
               __('GLPI’s configured address is %1$s but you are reading this at %2$s. Every link '
                 . 'in every notification — and the logo above them — is built from the configured '
                 . 'one, so if it is not reachable from a recipient’s browser the mail arrives with '
                 . 'a broken image and links that go nowhere. Set it under Setup → General → GLPI '
                 . 'URL if it is wrong; ignore this if you are simply on a different name from your '
                 . 'users.', 'glpimail'),
               $url_base,
               $browsing
           ))
           . '</div>';
    }
}

// The one thing worth calling out: there is a logo, and it cannot be used.
if (Settings::flag('include_logo') && in_array($brand->issue, ['svg', 'webp', 'unreadable'], true)) {
    $why = match ($brand->issue) {
        'svg'  => __('The configured logo is an SVG. No mainstream mail client renders one, so '
                   . 'notifications use the name alone. Upload a PNG to glpi-whitelabel if you '
                   . 'want a mark in the masthead.', 'glpimail'),
        'webp' => __('The configured logo is a WebP. Outlook on Windows renders it as nothing at '
                   . 'all, so notifications use the name alone. A PNG works everywhere.', 'glpimail'),
        default => __('The configured logo could not be read as an image this server understands. '
                   . 'Notifications use the name alone.', 'glpimail'),
    };

    echo "<div class='alert alert-warning mb-0'>" . $e($why) . '</div>';
} else {
    echo "<p class='text-muted mb-0'>"
       . __s('The name and the logo come from glpi-whitelabel so that rebranding stays one page. '
           . 'Everything below belongs to this plugin.', 'glpimail')
       . '</p>';
}

echo '</div></div>';

// --------------------------------------------------------- the settings form

echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('section', ['value' => 'settings']);

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('How notifications look', 'glpimail') . '</h3></div>';
echo "<div class='card-body'>";

echo "<div class='row'>";

echo "<div class='col-md-4 mb-3'><label class='form-label'>" . __s('Accent colour', 'glpimail') . '</label>';
echo "<input type='color' class='form-control form-control-color' name='accent' value='"
   . $e($settings['accent']) . "'" . ($can_edit ? '' : ' disabled') . '>';
echo "<div class='form-text'>"
   . __s('The rule under the masthead, the buttons and the links. Everything else is derived '
       . 'from it.', 'glpimail')
   . '</div></div>';

echo "<div class='col-md-8 mb-3'><label class='form-label'>" . __s('Footer note', 'glpimail') . '</label>';
echo "<input type='text' class='form-control' name='footer_note' maxlength='255' value='"
   . $e($settings['footer_note']) . "'" . ($can_edit ? '' : ' disabled') . '>';
echo "<div class='form-text'>"
   . __s('Small print at the bottom of every notification — a support number, or “do not reply '
       . 'to this message”.', 'glpimail')
   . '</div></div>';

echo "<div class='col-md-8 mb-3'><label class='form-label'>" . __s('Footer link', 'glpimail') . '</label>';
echo "<input type='text' class='form-control' name='footer_link' maxlength='255' value='"
   . $e($settings['footer_link']) . "' placeholder='https://…'" . ($can_edit ? '' : ' disabled') . '>';
echo "<div class='form-text'>"
   . __s('A second line under it. The self-service portal, usually — the one place a requester '
       . 'can go that is not the ticket this mail was about.', 'glpimail')
   . '</div></div>';

echo "<div class='col-md-4 mb-3'><label class='form-label'>"
   . __s('Rows in a repeating section', 'glpimail') . '</label>';
echo "<input type='number' min='1' max='25' class='form-control' name='loop_limit' value='"
   . $e($settings['loop_limit']) . "'" . ($can_edit ? '' : ' disabled') . '>';
echo "<div class='form-text'>"
   . __s('The most recent followups, licences or tasks a notification prints before it stops. '
       . 'A mail with ninety followups in it is a mail nobody reads.', 'glpimail')
   . '</div></div>';

echo '</div>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='include_logo' value='1' "
   . ((int) $settings['include_logo'] === 1 ? "checked='checked' " : '')
   . ($can_edit ? '' : 'disabled ') . '>';
echo "<span class='form-check-label'>" . __s('Use the logo', 'glpimail') . ' — '
   . __s('unticking prints the name alone, which is the better choice for a mark drawn to sit '
       . 'on a dark sidebar', 'glpimail')
   . '</span></label>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='enhancements' value='1' "
   . ((int) $settings['enhancements'] === 1 ? "checked='checked' " : '')
   . ($can_edit ? '' : 'disabled ') . '>';
echo "<span class='form-check-label'>" . __s('Dark mode and phone stacking', 'glpimail') . ' — '
   . __s('a designed dark card for clients that ask for one, and labelled rows that stack above '
       . 'their values on a narrow screen', 'glpimail')
   . '</span></label>';

// The number, not an adjective. Both halves of this trade are real and a site
// cannot weigh them from a checkbox label — so the measurement goes next to the
// checkbox rather than into a README nobody reads at the moment of deciding.
echo "<div class='form-text ms-4 mb-3'>"
   . __s('Both need CSS that a lot of mail clients ignore. Measured against Mailpit’s '
       . 'client-support tables on a real ticket notification: 99.4% supported with this off, '
       . '89.0% with it on. Off, every notification is a fixed 600-pixel table that renders the '
       . 'same everywhere and that phones scale to fit; on, the readers whose client honours it '
       . 'get a better message and the rest get the same card as before.', 'glpimail')
   . '</div>';

echo "<p class='text-muted mt-3 mb-0'>"
   . __s('Saving these does not change any notification. Apply the templates below afterwards — '
       . 'the colours are written into each body, not read at send time.', 'glpimail')
   . '</p>';

echo '</div></div>';

if ($can_edit) {
    echo "<div class='d-flex mb-4'>";
    echo "<button type='submit' class='btn btn-primary ms-auto'>" . __s('Save') . '</button>';
    echo '</div>';
} else {
    echo "<div class='alert alert-secondary'>"
       . __s('Read only: you can see these settings but not change them.', 'glpimail')
       . '</div>';
}

echo '</form>';

// ------------------------------------------------------------------ preview

$preview = (string) ($_GET['preview'] ?? 'Tickets');

if (!isset($catalog[$preview])) {
    $preview = (string) array_key_first($catalog);
}

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Preview', 'glpimail') . '</h3></div>';
echo "<div class='card-body'>";

echo "<form method='get' class='row g-2 align-items-end mb-3'>";
echo "<div class='col-md-6'><label class='form-label'>" . __s('Notification', 'glpimail') . '</label>';
echo "<select class='form-select' name='preview' id='glpimail-preview-pick'>";
foreach ($catalog as $name => $entry) {
    echo "<option value='" . $e($name) . "'" . ($name === $preview ? " selected='selected'" : '') . '>'
       . $e($name) . '</option>';
}
echo '</select></div>';
echo "<div class='col-md-2'><button type='submit' class='btn btn-outline-secondary w-100'>"
   . __s('Show', 'glpimail') . '</button></div>';
echo '</form>';

echo "<iframe id='glpimail-preview' title='" . __s('Notification preview', 'glpimail') . "'"
   . " src='" . $e(Html::getPrefixedUrl('/plugins/glpimail') . '/front/preview.php?name=' . rawurlencode($preview)) . "'"
   . " style='width:100%;height:640px;border:1px solid var(--tblr-border-color);border-radius:6px;background:#fff'"
   . " sandbox='allow-same-origin'></iframe>";

echo "<p class='text-muted mt-2 mb-0'>"
   . __s('Rendered by GLPI’s own template processor with invented data, inside the same wrapper '
       . 'GLPI builds when it sends — including the “Automatically generated by …” line it adds '
       . 'after the body, which is why the card ends where it does.', 'glpimail')
   . '</p>';

echo '</div></div>';

// ---------------------------------------------------------------- templates

echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title'>"
   . __s('Templates', 'glpimail') . '</h3></div>';
echo "<div class='card-body'>";

echo "<p class='text-muted'>"
   . __s('Applying rewrites a template’s body and keeps the original. Reverting puts the '
       . 'original back — the one from before this plugin first touched it, not the last '
       . 'thing that was there. Uninstalling reverts everything.', 'glpimail')
   . '</p>';

echo "<p class='text-muted'>"
   . __s('“Described by” says where the wording came from. A plugin describes its own '
       . 'notifications by registering them, and gets the same card, the same button and the '
       . 'same dark mode as GLPI’s. Anything undescribed is wrapped instead: its own body, '
       . 'unchanged, inside the card.', 'glpimail')
   . '</p>';

if ($can_edit) {
    echo "<div class='d-flex flex-wrap gap-2 mb-3'>";

    foreach (
        [
            ['apply', 1, __('Apply to GLPI’s own notifications', 'glpimail'), 'btn-primary'],
            // Not `btn-outline-primary`. Tabler's primary follows the palette,
            // and on the warm ones it is a yellow that all but disappears as an
            // outline on a white card — verified in a browser, next to the
            // solid primary above it.
            ['apply', 0, __('Apply to everything, wrapping the rest', 'glpimail'), 'btn-outline-secondary'],
            ['revert', 0, __('Revert everything', 'glpimail'), 'btn-outline-danger'],
        ] as [$act, $known_only, $label, $class]
    ) {
        echo "<form method='post'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('section', ['value' => $act]);
        echo Html::hidden('templates_id', ['value' => 0]);
        if ($act === 'apply') {
            echo Html::hidden('known_only', ['value' => $known_only]);
        }
        echo "<button type='submit' class='btn $class'>" . $e($label) . '</button>';
        echo '</form>';
    }

    echo '</div>';
}

echo "<table class='table table-sm align-middle'><thead><tr>";
echo '<th>' . __s('Template', 'glpimail') . '</th>';
echo '<th>' . __s('Object', 'glpimail') . '</th>';
echo '<th>' . __s('Described by', 'glpimail') . '</th>';
echo '<th>' . __s('State', 'glpimail') . '</th>';
if ($can_edit) {
    echo "<th class='text-end'>" . __s('Actions', 'glpimail') . '</th>';
}
echo '</tr></thead><tbody>';

foreach ($survey as $row) {
    [$badge, $label] = match ($row['state']) {
        Templates::STATE_STYLED => ['bg-green-lt', __('Styled', 'glpimail')],
        Templates::STATE_STALE  => ['bg-orange-lt', __('Styled, out of date', 'glpimail')],
        Templates::STATE_EDITED => ['bg-azure-lt', __('Reverted or edited', 'glpimail')],
        default                 => ['bg-secondary-lt', __('Stock', 'glpimail')],
    };

    echo '<tr>';
    echo '<td><div>' . $e($row['name']) . '</div>';

    if ($row['summary'] !== '') {
        echo "<div class='text-muted small'>" . $e($row['summary']) . '</div>';
    } else {
        echo "<div class='text-muted small'>"
           . __s('Nobody has described this one. Applying wraps its body in the card without '
               . 'changing it — a plugin can do better by registering a Letter; see the README.', 'glpimail')
           . '</div>';
    }

    echo '</td>';
    echo "<td class='text-muted'>" . $e($row['itemtype']) . '</td>';

    echo '<td>';
    if ($row['plugin'] !== '') {
        echo "<span class='badge bg-purple-lt'>" . $e($row['plugin']) . '</span>';
    } elseif ($row['known']) {
        echo "<span class='text-muted'>" . __s('GLPI', 'glpimail') . '</span>';
    } else {
        echo "<span class='text-muted'>—</span>";
    }
    echo '</td>';

    echo "<td><span class='badge $badge'>" . $e($label) . '</span>';

    if (!$row['known']) {
        echo " <span class='badge bg-secondary-lt'>" . __s('wrapped', 'glpimail') . '</span>';
    }

    echo '</td>';

    if ($can_edit) {
        echo "<td class='text-end text-nowrap'>";

        echo "<form method='post' class='d-inline'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('section', ['value' => 'apply']);
        echo Html::hidden('templates_id', ['value' => $row['id']]);
        echo "<button type='submit' class='btn btn-sm btn-outline-primary'>" . __s('Apply', 'glpimail') . '</button>';
        echo '</form> ';

        if ($row['state'] !== Templates::STATE_STOCK) {
            echo "<form method='post' class='d-inline'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('section', ['value' => 'revert']);
            echo Html::hidden('templates_id', ['value' => $row['id']]);
            echo "<button type='submit' class='btn btn-sm btn-outline-secondary'>" . __s('Revert', 'glpimail') . '</button>';
            echo '</form>';
        }

        echo '</td>';
    }

    echo '</tr>';
}

echo '</tbody></table>';

echo '</div></div>';

echo '</div>';

Html::footer();
