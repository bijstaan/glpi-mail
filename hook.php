<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Glpimail\Settings;
use GlpiPlugin\Glpimail\Templates;

/**
 * Install: one table, the settings defaults, and nothing else.
 *
 * In particular, no templates are written. Enabling a plugin is not consent to
 * rewrite thirty notifications an administrator may have spent a day on — see
 * the note in {@see Templates}. Installing this plugin changes nothing about
 * what the instance sends until somebody presses Apply.
 *
 * The one table is the backup of what was there first. It is not a cache and
 * it cannot be regenerated: once the original body has been overwritten, this
 * table is the only copy of it in the instance.
 */
function plugin_glpimail_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $table = Templates::TABLE;

    if (!$DB->tableExists($table)) {
        $sql = "CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `notificationtemplatetranslations_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `notificationtemplates_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `subject` VARCHAR(255) NOT NULL DEFAULT '',
            `content_text` LONGTEXT DEFAULT NULL,
            `content_html` LONGTEXT DEFAULT NULL,
            `css` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `translation` (`notificationtemplatetranslations_id`),
            KEY `notificationtemplates_id` (`notificationtemplates_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";

        // The unique key is the design, not a tidiness measure: it is what
        // makes the stored row the *original* body rather than the last one.
        // See Templates::backup().
        $DB->doQuery($sql);
    }

    Config::setConfigurationValues(PLUGIN_GLPIMAIL_CONFIG_CONTEXT, Settings::DEFAULTS);

    // Settings this plugin used to have. GLPI runs the install hook on upgrade
    // as well as on first install, which is the only hook there is to migrate
    // from — and a retired key left in `glpi_configs` is a row that reads like
    // a live setting to anybody looking at the table by hand.
    //
    // `dark_mode` became `enhancements` when the markup was rebuilt for client
    // support: the switch now governs the whole `<style>` layer — the dark card
    // *and* the phone stacking — rather than the dark card alone, and it
    // defaults the other way, so carrying the old value across would have
    // silently turned the new, larger thing on.
    Config::deleteConfigurationValues(PLUGIN_GLPIMAIL_CONFIG_CONTEXT, ['dark_mode']);

    return true;
}

/**
 * Uninstall: put the original notifications back first.
 *
 * This is not politeness. The styled bodies reference this plugin's own logo
 * endpoint, and every notification the instance sends after the plugin is gone
 * would carry an `<img>` pointing at a 404 — a broken image at the top of every
 * mail, appearing weeks later with nothing to connect it to a plugin somebody
 * removed. Reverting is the only uninstall that leaves the instance working.
 *
 * Anything this plugin merely wrapped goes back to its own body for the same
 * reason. Anything an administrator edited after it was styled is restored to
 * the version from *before* it was ever styled, which is the only version this
 * plugin ever had a right to; the settings page says so next to the button.
 */
function plugin_glpimail_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    Templates::revertAll();

    if ($DB->tableExists(Templates::TABLE)) {
        $DB->doQuery('DROP TABLE `' . Templates::TABLE . '`');
    }

    Config::deleteConfigurationValues(
        PLUGIN_GLPIMAIL_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );

    return true;
}
