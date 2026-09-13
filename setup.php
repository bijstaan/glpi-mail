<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI Mail — one house style for every notification the instance sends.
 *
 * A notification is the only part of an ITSM system most people ever see. A
 * requester opens two tickets a year; they never log in, they never see the
 * dashboard, and their entire impression of the service is four emails. GLPI's
 * own are — and this is not an opinion, it is verifiable on a stock 11.0.8
 * install — a wall of underlined grey labels wrapped in `<div>`s, sent at
 * whatever width the client feels like, with GLPI's name at the bottom.
 *
 * ### The bug this plugin was written on top of
 *
 * Worse than ugly. GLPI 11.0.8 seeds its stock templates from
 * `install/empty_data.php`, and the HTML bodies in that file are stored
 * *HTML-escaped* — `&lt;div&gt;` rather than `<div>`. Nothing in the send path
 * decodes them: {@see \NotificationTemplate::getTemplateByLanguage()} runs the
 * body through `process()`, which substitutes tags and rewrites relative
 * `href`s, and then concatenates the result straight into `<body>`. So every
 * stock HTML notification a fresh GLPI 11.0.8 sends is a screenful of visible
 * markup.
 *
 * That is worth knowing because it changes what this plugin is. It is not a
 * skin over something that works; for the core templates it is the thing that
 * makes HTML notifications work at all.
 *
 * ### How it works
 *
 * There is no new send path and no new transport. GLPI already owns those, and
 * a second mailer is a second thing to configure and a second thing to be
 * quietly broken. What this plugin does is *write the templates GLPI already
 * sends*: {@see GlpiPlugin\Glpimail\Catalog} describes each notification as
 * blocks, {@see GlpiPlugin\Glpimail\Shell} renders those blocks into the
 * table-and-inline-style markup that mail clients actually honour, and
 * {@see GlpiPlugin\Glpimail\Templates} writes the result into
 * `glpi_notificationtemplatetranslations`.
 *
 * Nothing is applied on install. Templates are a thing administrators edit, and
 * a plugin that overwrites them the moment it is enabled has destroyed work it
 * cannot see. Applying is an explicit button, the previous body is kept, and
 * Revert — and uninstalling — puts it back.
 *
 * ### The name at the top
 *
 * From glpi-whitelabel, never from GLPI, on exactly the terms glpi-pdf uses:
 * configured means its name and its logo; absent means neutral, no product name
 * at all; half-configured means whatever it has. An entity has not heard of
 * GLPI and an email that announces it reads as somebody else's system.
 */

use Glpi\Http\Firewall;
use GlpiPlugin\Glpimail\Settings;

define('PLUGIN_GLPIMAIL_VERSION', '0.1.0');
define('PLUGIN_GLPIMAIL_MIN_GLPI', '11.0');

define('PLUGIN_GLPIMAIL_CONFIG_CONTEXT', 'plugin:glpimail');

/**
 * Bumped when the rendered markup changes in a way that matters.
 *
 * Written into every body as `<!--glpimail:N-->`, which is how the settings
 * page tells "styled by this plugin" from "somebody has edited it since" and
 * from "still the stock body". A body carrying an older N is ours but stale,
 * and the page offers to re-apply it.
 */
define('PLUGIN_GLPIMAIL_MARKUP_VERSION', 1);

function plugin_init_glpimail()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpimail'] = true;
    $PLUGIN_HOOKS['config_page']['glpimail']    = 'front/config.php';

    /**
     * The logo endpoint answers to mail clients, which have no session.
     *
     * GLPI 11 routes plugin scripts through its firewall and defaults a legacy
     * script to "must be authenticated" — correct nearly everywhere and wrong
     * here, because the request comes from Gmail's image proxy or from Outlook
     * on somebody's phone. Left at the default it answers an <img> with an
     * access-denied *page*, which the client renders as a broken image.
     *
     * It exposes nothing new: it serves the same file glpi-whitelabel already
     * serves without a rights check, for the same reason — a brand mark is
     * public by construction, it is on the login page.
     */
    Firewall::addPluginStrategyForLegacyScripts(
        'glpimail',
        '#^/front/logo\.php#',
        Firewall::STRATEGY_NO_CHECK
    );

    $PLUGIN_HOOKS['add_css']['glpimail']        = 'css/glpimail.css';
    $PLUGIN_HOOKS['add_javascript']['glpimail'] = 'js/glpimail.js';
}

function plugin_version_glpimail()
{
    return [
        'name'         => 'GLPI Mail',
        'version'      => PLUGIN_GLPIMAIL_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-mail',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPIMAIL_MIN_GLPI]],
    ];
}

function plugin_glpimail_check_prerequisites()
{
    return true;
}

function plugin_glpimail_check_config($verbose = false)
{
    return true;
}
