<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimail;

/**
 * Reading and writing GLPI's notification templates.
 *
 * ### Nothing happens on install
 *
 * Notification templates are a thing administrators edit — often once, years
 * ago, with wording somebody in legal approved — and a plugin that rewrites
 * them the moment it is enabled has destroyed work it cannot see and cannot
 * describe. So applying is a button, it says how many rows it will change
 * before it changes them, and the previous body is kept.
 *
 * ### The backup is written once
 *
 * `glpi_plugin_glpimail_backups` has a unique key on the translation id and
 * the insert ignores a duplicate, so the row it holds is always the *original*
 * body — the one that was there before this plugin first touched it. Backing
 * up on every apply would mean the second apply backed up the first apply's
 * output, and Revert would restore this plugin's own markup, which is not what
 * anybody pressing Revert means.
 *
 * ### Two ways to style a template
 *
 *  - **Rewritten** — the template is in {@see Catalog}, and its body is built
 *    from that description. This is every notification GLPI ships.
 *  - **Wrapped** — it is not: a plugin's own template, or one somebody wrote.
 *    Its body is put inside the same card, untouched, so the estate's mail
 *    looks like one estate without this plugin having to know what a
 *    third-party template says. Wrapping always starts from the *original*
 *    body — the backup if there is one — so applying twice does not produce a
 *    card inside a card.
 *
 * ### Written with $DB, not with the itemtype
 *
 * {@see \NotificationTemplateTranslation::prepareInputForUpdate()} runs
 * `cleanContentHtml()`, which derives `content_text` from the HTML when the
 * text field is empty. That is the right thing for somebody typing into the
 * rich editor and the wrong thing here, where the text part is rendered from
 * the same block description as the HTML and is better than anything a tag
 * stripper would produce from it.
 */
final class Templates
{
    public const TABLE = 'glpi_plugin_glpimail_backups';

    /** A template this plugin has never touched. */
    public const STATE_STOCK = 'stock';
    /** Styled by this plugin, at the current markup version. */
    public const STATE_STYLED = 'styled';
    /** Styled by an older version of this plugin. */
    public const STATE_STALE = 'stale';
    /** Styled once, and edited since. */
    public const STATE_EDITED = 'edited';

    /**
     * Every notification template, and what state it is in.
     *
     * @return array<int,array{
     *     id:int, name:string, itemtype:string, state:string, known:bool,
     *     summary:string, plugin:string, translations:int, version:int
     * }>
     */
    public static function survey(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $catalog = Catalog::all();
        $out     = [];

        foreach (
            $DB->request([
                'FROM'  => 'glpi_notificationtemplates',
                'ORDER' => 'name',
            ]) as $row
        ) {
            $id      = (int) $row['id'];
            $name    = (string) $row['name'];
            $entry   = self::entryFor($name, (string) $row['itemtype'], $catalog);
            $bodies  = self::translations($id);
            $version = 0;
            $state   = self::STATE_STOCK;

            foreach ($bodies as $body) {
                $marker = self::markerOf((string) ($body['content_html'] ?? ''));

                if ($marker === null) {
                    // One unstyled translation is enough to call the whole
                    // template unstyled: the state drives a button that acts on
                    // all of them.
                    $state = self::hasBackup((int) $body['id']) ? self::STATE_EDITED : self::STATE_STOCK;
                    break;
                }

                $version = $marker;
                $state   = $marker === PLUGIN_GLPIMAIL_MARKUP_VERSION ? self::STATE_STYLED : self::STATE_STALE;
            }

            $out[] = [
                'id'           => $id,
                'name'         => $name,
                'itemtype'     => (string) $row['itemtype'],
                'state'        => $state,
                'known'        => $entry !== null,
                'summary'      => $entry['summary'] ?? '',
                // '' for GLPI's own, otherwise the plugin that described it
                // through the `glpimail_letters` hook. See Registry.
                'plugin'       => (string) ($entry['plugin'] ?? ''),
                'translations' => count($bodies),
                'version'      => $version,
            ];
        }

        return $out;
    }

    // ============================================================== applying

    /**
     * Style one template.
     *
     * @return int how many translation rows were written
     */
    public static function apply(int $templates_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $template = self::template($templates_id);

        if ($template === null) {
            return 0;
        }

        $brand = Brand::resolve();
        $theme = Theme::fromAccent($brand->accent);
        $shell = new Shell($theme, $brand);
        $entry = self::entryFor((string) $template['name'], (string) $template['itemtype']);

        $written = 0;

        foreach (self::translations($templates_id) as $row) {
            $id = (int) $row['id'];

            self::backup($id, $templates_id, $row, (string) ($template['css'] ?? ''));

            if ($entry !== null) {
                $html = $shell->html($entry['blocks']);
                $text = $shell->plain($entry['blocks']);
            } else {
                // Wrapped. Always from the original body, so a second apply
                // does not nest one card inside another.
                $original = self::originalHtml($id, (string) ($row['content_html'] ?? ''));

                if (trim($original) === '') {
                    // Nothing to wrap. A card containing an empty card is
                    // worse than leaving a text-only notification alone.
                    continue;
                }

                $html = $shell->html([['raw', $original]]);
                $text = (string) ($row['content_text'] ?? '');
            }

            $DB->update(
                'glpi_notificationtemplatetranslations',
                ['content_html' => $html, 'content_text' => $text],
                ['id' => $id]
            );

            $written++;
        }

        if ($written > 0) {
            $DB->update(
                'glpi_notificationtemplates',
                [
                    'css'      => Css::build($theme, Settings::flag('enhancements')),
                    'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ],
                ['id' => $templates_id]
            );
        }

        return $written;
    }

    /**
     * Style everything.
     *
     * @param bool $known_only stop at the templates this plugin has a
     *                         description for, rather than wrapping the rest
     * @return array{templates:int,rows:int}
     */
    public static function applyAll(bool $known_only = false): array
    {
        $catalog   = Catalog::all();
        $templates = 0;
        $rows      = 0;

        foreach (self::survey() as $entry) {
            if ($known_only && !isset($catalog[$entry['name']])) {
                continue;
            }

            $written = self::apply($entry['id']);

            if ($written > 0) {
                $templates++;
                $rows += $written;
            }
        }

        return ['templates' => $templates, 'rows' => $rows];
    }

    // ============================================================== reverting

    /**
     * Put one template's original bodies back.
     *
     * @return int how many translation rows were restored
     */
    public static function revert(int $templates_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $restored = 0;
        $css      = null;

        foreach (self::translations($templates_id) as $row) {
            $backup = self::backupOf((int) $row['id']);

            if ($backup === null) {
                continue;
            }

            $DB->update(
                'glpi_notificationtemplatetranslations',
                [
                    'subject'      => (string) $backup['subject'],
                    'content_text' => (string) $backup['content_text'],
                    'content_html' => (string) $backup['content_html'],
                ],
                ['id' => (int) $row['id']]
            );

            $css = $backup['css'];
            $restored++;

            $DB->delete(self::TABLE, ['id' => (int) $backup['id']]);
        }

        if ($restored > 0) {
            $DB->update('glpi_notificationtemplates', ['css' => $css], ['id' => $templates_id]);
        }

        return $restored;
    }

    /** @return array{templates:int,rows:int} */
    public static function revertAll(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return ['templates' => 0, 'rows' => 0];
        }

        $ids = [];

        foreach (
            $DB->request([
                'SELECT'   => ['notificationtemplates_id'],
                'DISTINCT' => true,
                'FROM'     => self::TABLE,
            ]) as $row
        ) {
            $ids[] = (int) $row['notificationtemplates_id'];
        }

        $templates = 0;
        $rows      = 0;

        foreach ($ids as $id) {
            $restored = self::revert($id);

            if ($restored > 0) {
                $templates++;
                $rows += $restored;
            }
        }

        return ['templates' => $templates, 'rows' => $rows];
    }

    // ================================================================ plumbing

    /**
     * The catalog description for a template, if there is one *for it*.
     *
     * Matched on the name **and** the itemtype, not on the name alone. The
     * catalog is keyed by GLPI's own seeded names, but nothing stops an
     * administrator cloning "Tickets" onto a Change — GLPI's template list has
     * a clone action — and a body full of `##ticket.*##` tags written into a
     * Change template is a mail with every field blank, which looks like this
     * plugin does not work rather than like a name collision. A template whose
     * itemtype does not match is not unknown, it is *not this one*: it falls
     * through to being wrapped, which is the right answer for it.
     *
     * @param array<string,array{itemtype:string,summary:string,blocks:array<int,array<mixed>>}>|null $catalog
     * @return array{itemtype:string,summary:string,blocks:array<int,array<mixed>>}|null
     */
    private static function entryFor(string $name, string $itemtype, ?array $catalog = null): ?array
    {
        $entry = ($catalog ?? Catalog::all())[$name] ?? null;

        return $entry !== null && $entry['itemtype'] === $itemtype ? $entry : null;
    }

    /** @return array<string,mixed>|null */
    private static function template(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => 'glpi_notificationtemplates',
                'WHERE' => ['id' => $id],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    private static function translations(int $templates_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];

        foreach (
            $DB->request([
                'FROM'  => 'glpi_notificationtemplatetranslations',
                'WHERE' => ['notificationtemplates_id' => $templates_id],
                'ORDER' => 'id',
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The markup version stamped in a body, or null if it is not ours.
     *
     * The stamp is an HTML comment at the very start, which survives being
     * stored and read back but not being edited in GLPI's rich editor — TinyMCE
     * keeps comments, so an administrator who tweaks a word keeps the stamp and
     * the settings page keeps saying "styled". That is the honest answer:
     * it *is* still our markup, with their wording in it, and re-applying would
     * throw the wording away. Which is why re-applying is never automatic.
     */
    public static function markerOf(string $html): ?int
    {
        return preg_match('/^\s*<!--glpimail:(\d+)-->/', $html, $m) === 1 ? (int) $m[1] : null;
    }

    /** @param array<string,mixed> $row */
    private static function backup(int $translations_id, int $templates_id, array $row, string $css): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::hasBackup($translations_id)) {
            return;
        }

        if (self::markerOf((string) ($row['content_html'] ?? '')) !== null) {
            // Ours already, and yet no backup — the table was dropped, or this
            // is a row somebody copied from a styled template. Keeping it as
            // "the original" would make Revert restore this plugin's markup
            // and call it the site's own.
            return;
        }

        $DB->insert(self::TABLE, [
            'notificationtemplatetranslations_id' => $translations_id,
            'notificationtemplates_id'            => $templates_id,
            'subject'                             => (string) ($row['subject'] ?? ''),
            'content_text'                        => (string) ($row['content_text'] ?? ''),
            'content_html'                        => (string) ($row['content_html'] ?? ''),
            'css'                                 => $css,
            'date_creation'                       => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    private static function backupOf(int $translations_id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return null;
        }

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['notificationtemplatetranslations_id' => $translations_id],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    private static function hasBackup(int $translations_id): bool
    {
        return self::backupOf($translations_id) !== null;
    }

    /** The body as it was before this plugin first saw it. */
    private static function originalHtml(int $translations_id, string $current): string
    {
        $backup = self::backupOf($translations_id);

        return $backup !== null ? (string) $backup['content_html'] : $current;
    }
}
