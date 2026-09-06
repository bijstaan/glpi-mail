<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimail;

/**
 * Whose name is at the top of the email.
 *
 * Same three states as glpi-pdf, on purpose — a document and the mail
 * announcing it should not disagree about who sent them:
 *
 *  - **glpi-whitelabel configured** — its name and its logo, read from that
 *    plugin rather than copied here, so rebranding stays one page.
 *  - **absent or unconfigured** — neutral. No name, no logo, no product name.
 *    An unbranded mail beats one wearing a stranger's brand, and "GLPI" means
 *    nothing to the requester who is about to read it.
 *  - **half-configured** — whatever it has, which is what glpi-whitelabel's own
 *    Brand class does with a half-filled form.
 *
 * ### Why a logo has to earn its place here and not in the browser
 *
 * A mail client is not a browser and its image support is twenty years behind
 * one. Two formats glpi-whitelabel is right to accept are wrong in an email:
 *
 *  - **SVG** — no mainstream mail client renders it. Gmail strips it, Outlook
 *    ignores it, Apple Mail is the exception rather than the rule. It is the
 *    correct format for a logo on a web page and it is a broken image icon in
 *    an inbox.
 *  - **WebP** — Outlook on Windows renders it as nothing at all.
 *
 * So this class asks what the file *is* before deciding there is a logo, and
 * when the answer is one of those it reports a wordmark instead and the
 * settings page says why. That is the same call glpi-pdf makes about SVG and
 * TCPDF, made for a different renderer.
 *
 * ### And why the wordmark is never really absent
 *
 * The masthead is emitted as `<img alt="Acme">`, always. Remote images are
 * blocked by default in Outlook and in Gmail for unknown senders, and an
 * `alt` is what the reader gets instead — so the fallback for "images off" and
 * the fallback for "the logo could not be used" are the same fallback, and it
 * is the one that was going to be tested in the field anyway.
 */
final class Brand
{
    /** Formats a mail client can be relied on to draw. */
    private const MAIL_SAFE = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF];

    private function __construct(
        /** The name at the top, and the logo's alt text. Never "GLPI". */
        public readonly string $name,
        /** Whether a mail-safe logo file exists and is wanted. */
        public readonly bool $logo,
        /** Why there is no logo, when there is a file but it cannot be used. */
        public readonly string $issue,
        public readonly string $accent,
        /** Whether any of this came from glpi-whitelabel. */
        public readonly bool $branded,
    ) {
    }

    public static function resolve(): self
    {
        $accent = Settings::accent();

        if (!self::available()) {
            return new self('', false, '', $accent, false);
        }

        /** @var class-string $settings */
        $settings = \GlpiPlugin\Whitelabel\Settings::class;

        $name  = trim((string) $settings::get('name'));
        $issue = '';
        $logo  = false;

        if (Settings::flag('include_logo')) {
            $issue = self::logoIssue();
            $logo  = $issue === '';
        }

        if ($name === '' && !$logo) {
            // Nothing usable. Neutral rather than half-branded: a masthead
            // holding only an accent rule is not a brand, it is a mistake that
            // looks deliberate.
            return new self('', false, $issue, $accent, false);
        }

        return new self($name, $logo, $issue, $accent, true);
    }

    /** The neutral masthead, for previews and for tests. */
    public static function neutral(): self
    {
        return new self('', false, '', Settings::accent(), false);
    }

    /**
     * The masthead URL, as a tag rather than a baked address.
     *
     * `##glpi.url##` is expanded by GLPI at send time from the *entity's* URL
     * base, which is a setting a multi-entity instance genuinely varies. Baking
     * `$CFG_GLPI['url_base']` in at apply time would be one URL for everybody,
     * and would go stale the day somebody puts the instance behind a different
     * name — with thirty templates to re-apply and no reason to suspect them.
     *
     * Note this only works for `src`. GLPI rewrites relative `href`s to
     * absolute ones when it renders a template ({@see
     * \NotificationTemplate::convertRelativeGlpiLinksToAbsolute()}) but the
     * regex covers `href` alone, so an image has to say where it lives.
     */
    public static function logoUrl(): string
    {
        return '##glpi.url##/plugins/glpimail/front/logo.php';
    }

    // ------------------------------------------------------------- the file

    /** The stored whitelabel logo, or null. */
    public static function logoPath(): ?string
    {
        if (!self::available()) {
            return null;
        }

        /** @var class-string $assets */
        $assets = \GlpiPlugin\Whitelabel\Assets::class;

        $path = $assets::path('logo');

        return $path !== null && is_readable($path) ? $path : null;
    }

    /**
     * '' when the logo can be used, otherwise a word naming the problem.
     *
     * Deliberately not a sentence: the settings page phrases it, this decides
     * it. Values are 'none', 'svg', 'webp' and 'unreadable'.
     */
    public static function logoIssue(): string
    {
        $path = self::logoPath();

        if ($path === null) {
            return 'none';
        }

        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return 'svg';
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return 'unreadable';
        }

        if ((int) $info[2] === IMAGETYPE_WEBP) {
            return 'webp';
        }

        return in_array((int) $info[2], self::MAIL_SAFE, true) ? '' : 'unreadable';
    }

    private static function available(): bool
    {
        return \Plugin::isPluginActive('whitelabel')
            && class_exists(\GlpiPlugin\Whitelabel\Settings::class)
            && class_exists(\GlpiPlugin\Whitelabel\Assets::class);
    }
}
