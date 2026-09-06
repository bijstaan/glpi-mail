<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimail;

/**
 * Which plugins describe their own notifications, and how.
 *
 * A plugin says so by registering a callback under `glpimail_letters` — the
 * same shape glpi-pdf uses for `glpipdf_documents`, so the pattern is one an
 * author of these plugins has already met:
 *
 * ```php
 * $PLUGIN_HOOKS['glpimail_letters']['glpisignal'] = [Letters::class, 'offers'];
 * ```
 *
 * and that callback returns a flat list of offers, one per notification
 * template the plugin owns:
 *
 * ```php
 * [
 *     [
 *         'name'     => 'On-call page',        // the NotificationTemplate name
 *         'itemtype' => Alert::class,
 *         'summary'  => __('The page sent to whoever is on call.', 'glpisignal'),
 *         'build'    => static fn(): Letter => Letter::make()->title('##alert.name##')…,
 *     ],
 * ]
 * ```
 *
 * ### Why this exists, when wrapping already worked
 *
 * Without it a plugin's template is *wrapped*: its own body, whatever that
 * body is, put inside the house card untouched. That is the right fallback and
 * a poor destination. A wrapped body keeps whatever markup its author wrote —
 * usually a `<ul>` of bolded labels, because that is what one writes into
 * GLPI's template editor — so the estate's mail ends up as one card containing
 * six different ideas of what a labelled field looks like. Wrapping makes them
 * share an envelope; describing makes them share a design.
 *
 * It is also the difference between a plugin's notification having a *button*
 * and having a link in a paragraph, because a button is not something you can
 * write in a template editor and have work in Outlook.
 *
 * ### A contributor cannot take over one of GLPI's own
 *
 * An offer whose name and itemtype match an entry in {@see Catalog} is dropped
 * and logged. Otherwise a plugin could quietly redefine what a ticket
 * notification says by shipping a name collision, and the administrator's only
 * clue would be that the wording changed after an unrelated install.
 *
 * ### Failure is per-offer
 *
 * A contributor that throws is dropped and logged; every other template still
 * applies. The alternative is that one plugin's typo takes the settings page
 * down and with it the ability to revert anything.
 */
final class Registry
{
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    /**
     * Every contributed description, validated, keyed by template name.
     *
     * @return array<string,array{itemtype:string,summary:string,plugin:string,blocks:array<int,array<mixed>>}>
     */
    public static function letters(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        /** @var array<string,mixed> $PLUGIN_HOOKS */
        global $PLUGIN_HOOKS;

        $core = Catalog::core();
        $out  = [];

        foreach ((array) ($PLUGIN_HOOKS['glpimail_letters'] ?? []) as $plugin => $callback) {
            if (!is_callable($callback)) {
                continue;
            }

            try {
                $offered = (array) $callback();
            } catch (\Throwable $e) {
                self::complain($plugin, 'could not list its notifications', $e->getMessage());
                continue;
            }

            foreach ($offered as $offer) {
                $entry = self::normalise((array) $offer, (string) $plugin, $core, $out);

                if ($entry !== null) {
                    $out[$entry['name']] = $entry['entry'];
                }
            }
        }

        return self::$cache = $out;
    }

    /** Forget what the hooks said. For tests, and for a plugin enabled mid-request. */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * @param array<string,mixed>                 $offer
     * @param array<string,array<string,mixed>>   $core
     * @param array<string,array<string,mixed>>   $taken
     * @return array{name:string,entry:array<string,mixed>}|null
     */
    private static function normalise(array $offer, string $plugin, array $core, array $taken): ?array
    {
        $name     = trim((string) ($offer['name'] ?? ''));
        $itemtype = (string) ($offer['itemtype'] ?? '');

        if ($name === '' || $itemtype === '') {
            self::complain($plugin, 'offered a notification with no name or itemtype', $name);

            return null;
        }

        // An offer for a class that is not loaded is not an error — it is a
        // plugin naming an itemtype from a plugin that is not installed here,
        // which is the normal state of a suite where each part ships alone.
        if (!class_exists($itemtype)) {
            return null;
        }

        if (isset($core[$name]) && $core[$name]['itemtype'] === $itemtype) {
            self::complain($plugin, 'tried to redefine one of GLPI’s own notifications', $name);

            return null;
        }

        if (isset($taken[$name])) {
            self::complain($plugin, 'offered a name another plugin has already described', $name);

            return null;
        }

        if (!isset($offer['build']) || !is_callable($offer['build'])) {
            self::complain($plugin, 'offered a notification with no build callback', $name);

            return null;
        }

        // Built now rather than lazily. The settings page lists every template
        // and the apply loop renders every one, so laziness would save nothing
        // and would move a contributor's exception from here — where it is
        // caught, named and survivable — into the middle of a write.
        try {
            $letter = ($offer['build'])();
        } catch (\Throwable $e) {
            self::complain($plugin, 'could not build ' . $name, $e->getMessage());

            return null;
        }

        if (!$letter instanceof Letter) {
            self::complain($plugin, 'returned something other than a Letter for', $name);

            return null;
        }

        if ($letter->isEmpty()) {
            self::complain($plugin, 'described ' . $name . ' as an empty letter', '');

            return null;
        }

        return [
            'name'  => $name,
            'entry' => [
                'itemtype' => $itemtype,
                'summary'  => trim((string) ($offer['summary'] ?? '')),
                'plugin'   => $plugin,
                'blocks'   => $letter->blocks(),
            ],
        ];
    }

    private static function complain(string $plugin, string $what, string $detail): void
    {
        trigger_error(
            rtrim(sprintf('glpimail: %s %s. %s', $plugin, $what, $detail)),
            E_USER_WARNING
        );
    }
}
