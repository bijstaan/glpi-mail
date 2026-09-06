<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimail;

/**
 * The palette, light and dark, derived from one configured accent.
 *
 * One colour is configurable and the rest is arithmetic, which is the whole
 * argument for this plugin existing: an administrator who is handed eight
 * colour pickers produces eight colours, and thirty notifications that each
 * look like a different company. What they actually want is "our blue", and
 * everything a card needs around that blue — a tint for the masthead, a legible
 * ink for the button, a lighter cousin that survives a dark ground — is
 * derived rather than asked for.
 *
 * ### The dark side is not the light side inverted
 *
 * Mail clients that support `prefers-color-scheme` are given a designed dark
 * card. The ones that do not — Outlook on Windows most of all — will invert the
 * message themselves, badly, and there is nothing to be done about that from
 * here except to keep the light card's contrast high enough that a naive
 * inversion of it is still readable.
 *
 * Two things are worth knowing about the dark palette:
 *
 *  - The **accent is lightened**. A brand blue chosen to be legible as white
 *    text on white paper is, on a near-black card, a hole. It is mixed toward
 *    white until it reads as a colour rather than as an absence.
 *  - The **button keeps its light-mode ink**. Its background is the accent in
 *    both modes, so the text on it is decided by the accent's own luminance and
 *    not by the page around it. This is the mistake that produces white-on-
 *    yellow buttons.
 */
final class Theme
{
    private function __construct(
        /** @var array<string,string> light tokens */
        public readonly array $light,
        /** @var array<string,string> dark tokens */
        public readonly array $dark,
    ) {
    }

    public static function fromAccent(string $accent): self
    {
        $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) === 1
            ? strtolower($accent)
            : (string) Settings::DEFAULTS['accent'];

        // White text unless the accent is bright enough that white would be
        // the unreadable choice — a brand yellow or lime, which is rarer than
        // a brand blue but not rare enough to ignore.
        $on_accent = self::luminance($accent) > 0.55 ? '#10151c' : '#ffffff';

        $light = [
            // The ground the card floats on, and the one colour a mail client
            // is most likely to override.
            'ground'      => '#f1f3f6',
            'card'        => '#ffffff',
            'border'      => '#e2e6ec',
            'hairline'    => '#eef1f5',
            // A faint band for the masthead and the quoted-content panel.
            // Note the direction: this is *near-white, carrying a trace of the
            // accent*, not the accent moved a little toward white. Written the
            // other way round it is a 96%-saturated brand colour, which is a
            // banner rather than a tint — and looks entirely deliberate right
            // up until somebody tries to read black text on it.
            'faint'       => self::mix('#f7f9fb', $accent, 0.05),
            'ink'         => '#1b2430',
            'muted'       => '#66738a',
            'accent'      => $accent,
            'accent_ink'  => $on_accent,
            'accent_deep' => self::mix($accent, '#000000', 0.18),
        ];

        $dark_accent = self::mix($accent, '#ffffff', 0.28);

        $dark = [
            'ground'      => '#0e1116',
            'card'        => '#171b22',
            'border'      => '#2a313c',
            'hairline'    => '#232932',
            'faint'       => '#1e242d',
            'ink'         => '#e7ecf3',
            'muted'       => '#97a2b3',
            'accent'      => $dark_accent,
            // Unchanged from light: the button's ground is the accent in both
            // modes, so its ink follows the accent and not the page.
            'accent_ink'  => $on_accent,
            'accent_deep' => $dark_accent,
        ];

        return new self($light, $dark);
    }

    public function light(string $token): string
    {
        return $this->light[$token] ?? '#000000';
    }

    public function dark(string $token): string
    {
        return $this->dark[$token] ?? '#ffffff';
    }

    /**
     * The tones a status pill can take.
     *
     * Fixed rather than derived. These say "resolved", "overdue", "waiting for
     * you" — meanings a reader already has colours for — and running them
     * through the brand accent would turn a red into a brand-red that no longer
     * reads as a warning.
     *
     * @return array<string,array{0:string,1:string,2:string}> tone => [background, ink, border]
     */
    public static function tones(): array
    {
        return [
            'neutral' => ['#eef1f5', '#445063', '#dfe4ea'],
            'info'    => ['#e7f1fb', '#155189', '#cfe1f5'],
            'good'    => ['#e6f6ed', '#136c3b', '#c9ead8'],
            'warn'    => ['#fdf2e0', '#8a5a11', '#f5e2bf'],
            'bad'     => ['#fdeceb', '#8f2320', '#f6cfcd'],
        ];
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function darkTones(): array
    {
        return [
            'neutral' => ['#262d38', '#b3bdcc', '#333c48'],
            'info'    => ['#152b41', '#8bc0ef', '#1f3b57'],
            'good'    => ['#12301f', '#79d3a2', '#1d442e'],
            'warn'    => ['#3a2c12', '#e3b467', '#4d3c1c'],
            'bad'     => ['#3a1c1b', '#ef9f9c', '#4d2725'],
        ];
    }

    // --------------------------------------------------------- the arithmetic

    /** Mix `$colour` toward `$towards` by `$amount` (0..1). */
    public static function mix(string $colour, string $towards, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($colour);
        [$r2, $g2, $b2] = self::rgb($towards);

        $amount = max(0.0, min(1.0, $amount));

        return sprintf(
            '#%02x%02x%02x',
            (int) round($r1 + ($r2 - $r1) * $amount),
            (int) round($g1 + ($g2 - $g1) * $amount),
            (int) round($b1 + ($b2 - $b1) * $amount)
        );
    }

    /**
     * Relative luminance, 0..1.
     *
     * The sRGB gamma-corrected form from WCAG rather than a plain average.
     * The cheap version calls a saturated blue and a saturated yellow equally
     * bright, which is precisely the case this is here to decide.
     */
    public static function luminance(string $colour): float
    {
        $channels = [];

        foreach (self::rgb($colour) as $value) {
            $value      = $value / 255;
            $channels[] = $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $colour): array
    {
        $hex = ltrim(trim($colour), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (preg_match('/^[0-9A-Fa-f]{6}$/', $hex) !== 1) {
            return [0, 0, 0];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
