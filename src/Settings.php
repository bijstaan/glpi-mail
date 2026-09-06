<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimail;

use Config;

/**
 * Plugin settings, with defaults.
 *
 * Short on purpose, and for the same reason glpi-pdf's is short: the point of
 * the plugin is that thirty-odd notifications look like one service rather than
 * thirty. Every knob here is a way for that to stop being true, so what is
 * configurable is what genuinely differs between sites — the accent, the
 * footer, whether the logo is used at all — and never what differs between one
 * notification and the next.
 */
final class Settings
{
    public const DEFAULTS = [
        // The colour of the rule under the masthead, the buttons and the
        // links. Not read from glpi-whitelabel: that plugin themes GLPI's
        // chrome through a palette, and a palette that reads as an application
        // sidebar is not a button colour on a white card. The default matches
        // glpi-pdf's, so a printed document and the email announcing it are
        // recognisably the same house.
        'accent'        => '#1c6fbb',

        // Put the whitelabel logo in the masthead. Off prints the name alone,
        // which is the better choice for a mark drawn for a dark sidebar —
        // and the only choice for one supplied as SVG, which mail clients do
        // not render. See Brand.
        'include_logo'  => 1,

        // Rendered small under the footer rule of every notification.
        // Somewhere for a support telephone number, an address a jurisdiction
        // requires, or "do not reply to this message".
        'footer_note'   => '',

        // A second line under that, rendered as a link when it looks like a
        // URL. The portal, most often — the one place a requester can go that
        // is not the ticket the mail was about.
        'footer_link'   => '',

        // The enhancement layer: a `<style>` block carrying a dark card for
        // clients that ask for one, and the rules that stack the labelled rows
        // above their values on a narrow screen.
        //
        // **Off by default, and that is a measured decision rather than a
        // cautious one.** Both need `@media` and `!important`, and against
        // Mailpit's client-support scoring those two cost about ten points —
        // the difference between 99.5% and 89% support on a real notification.
        // The card underneath is built to need neither: it is a fixed
        // 600-pixel table that every client renders the same way and that
        // phones scale to fit.
        //
        // Turning it on buys a designed dark card instead of whatever a
        // client's own inverter makes of a white one, and true stacking
        // instead of scaling, on the clients modern enough to honour it. That
        // is a real gain for the readers who get it, paid for by the readers
        // who do not. The settings page states the score on both sides.
        'enhancements'  => 0,

        // How many rows a repeating section prints before it stops — the
        // followups on a ticket, the licences in an expiry alert. A cap
        // rather than a scroll: a notification is a summary with a link on it,
        // and a mail with ninety followups in it is a mail nobody reads. GLPI
        // spells this `##FOREACH LAST 5 followups##`.
        'loop_limit'    => 5,
    ];

    /**
     * The settings, read once.
     *
     * The cache is not premature: rendering one notification asks for the
     * accent, the loop limit, the logo flag and both footer lines, and
     * applying to the whole instance renders thirty-two of them — several
     * hundred round trips to `glpi_configs` for six values that cannot change
     * during the request that is writing them.
     *
     * @var array<string,int|string>|null
     */
    private static ?array $cache = null;

    /** @return array<string,int|string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = Config::getConfigurationValues(
            PLUGIN_GLPIMAIL_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = array_key_exists($key, $stored) ? $stored[$key] : $default;
        }

        return self::$cache = $out;
    }

    public static function get(string $key): string
    {
        return (string) (self::all()[$key] ?? (self::DEFAULTS[$key] ?? ''));
    }

    public static function flag(string $key): bool
    {
        return (int) (self::all()[$key] ?? 0) === 1;
    }

    /**
     * The accent, validated.
     *
     * An unparseable colour would otherwise be interpolated straight into a
     * `style` attribute, where a mail client's answer to nonsense is to drop
     * the whole declaration — so a typo months ago would show up as an
     * unstyled button rather than as anything anybody could trace back.
     */
    public static function accent(): string
    {
        $accent = trim(self::get('accent'));

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) === 1
            ? strtolower($accent)
            : (string) self::DEFAULTS['accent'];
    }

    public static function loopLimit(): int
    {
        $limit = (int) self::get('loop_limit');

        return $limit > 0 ? min($limit, 25) : (int) self::DEFAULTS['loop_limit'];
    }

    public static function save(array $input): void
    {
        $values = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = match ($key) {
                'include_logo', 'enhancements' => !empty($input[$key]) ? 1 : 0,
                'loop_limit'                => max(1, min(25, (int) ($input[$key] ?? 5))),
                'accent'                    => preg_match('/^#[0-9A-Fa-f]{6}$/', trim((string) ($input[$key] ?? ''))) === 1
                    ? strtolower(trim((string) $input[$key]))
                    : (string) self::DEFAULTS['accent'],
                default                     => trim((string) ($input[$key] ?? '')),
            };
        }

        Config::setConfigurationValues(PLUGIN_GLPIMAIL_CONFIG_CONTEXT, $values);

        // The save and an apply can happen in the same request — the settings
        // page offers both — and a stale cache would bake the previous accent
        // into every body that was just rewritten to carry the new one.
        self::$cache = null;
    }
}
