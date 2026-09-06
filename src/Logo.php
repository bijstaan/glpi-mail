<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimail;

/**
 * The masthead image, as a mail client will fetch it.
 *
 * A logo in an email is not a logo on a page, in three ways that all have to be
 * dealt with here rather than in the markup:
 *
 *  - **It is fetched by a stranger.** Gmail's image proxy, Outlook's renderer
 *    on somebody's phone, a corporate gateway prefetching links. None of them
 *    has a session, which is why this endpoint is exempted from GLPI's firewall
 *    in setup.php. It exposes nothing that glpi-whitelabel does not already
 *    serve unauthenticated to the login page.
 *  - **It is fetched once per reader, forever.** glpi-whitelabel's slot suggests
 *    200×110 for a 100×55 box, and nothing stops an administrator uploading the
 *    print original — this instance's own logo assets run to hundreds of
 *    kilobytes. On a page that is one download; on a notification sent to
 *    eight hundred requesters it is eight hundred, and it is at the top of
 *    every mail the instance has ever sent. So it is downscaled once and kept.
 *  - **It is fetched by renderers from 2007.** {@see Brand} decides whether the
 *    format is one a mail client will draw at all; by the time a request gets
 *    here that has already been settled, and this refuses anything else with a
 *    404 so the `alt` text — the brand name — takes its place.
 *
 * The cache key is glpi-whitelabel's `revision`, which that plugin bumps on
 * every save of its settings for exactly this purpose. Reusing it means a
 * re-uploaded logo invalidates this cache with nothing here having to watch for
 * it — the same trick glpi-pdf uses for its print raster.
 */
final class Logo
{
    /** Twice the 30px the masthead draws it at, for a 2× display. */
    private const MAX_HEIGHT = 60;

    /** A wordmark can be wide. This is where "wide" becomes "a banner". */
    private const MAX_WIDTH = 420;

    /** Serve it, or answer false and let the caller 404. */
    public static function send(): bool
    {
        if (!Settings::flag('include_logo') || Brand::logoIssue() !== '') {
            return false;
        }

        $file = self::file();

        if ($file === null) {
            return false;
        }

        [$path, $type] = $file;

        $bytes = @file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            return false;
        }

        header('Content-Type: ' . $type);
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        // A day. Long enough that a mail sent to a large audience is one
        // origin fetch per proxy, short enough that a rebrand reaches people
        // who have the old one — and the cache key changes on re-upload
        // anyway, so this is only about intermediaries that ignore it.
        header('Cache-Control: public, max-age=86400');

        echo $bytes;

        return true;
    }

    /**
     * The file to serve and its content type.
     *
     * @return array{0:string,1:string}|null
     */
    private static function file(): ?array
    {
        $source = Brand::logoPath();

        if ($source === null) {
            return null;
        }

        $info = @getimagesize($source);

        if ($info === false) {
            return null;
        }

        $type = match ((int) $info[2]) {
            IMAGETYPE_PNG  => 'image/png',
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_GIF  => 'image/gif',
            default        => null,
        };

        if ($type === null) {
            return null;
        }

        // Already small enough, or no GD to shrink it with. Serving the
        // original is a worse outcome than serving a resized one and a much
        // better outcome than serving nothing.
        if (
            ((int) $info[1] <= self::MAX_HEIGHT && (int) $info[0] <= self::MAX_WIDTH)
            || !function_exists('imagecreatetruecolor')
        ) {
            return [$source, $type];
        }

        $cached = self::cached();

        if ($cached !== null) {
            return [$cached, 'image/png'];
        }

        $bytes = self::resample($source, (int) $info[2], (int) $info[0], (int) $info[1]);

        if ($bytes === null) {
            return [$source, $type];
        }

        $target = self::cachePath();

        return self::publish($target, $bytes) ? [$target, 'image/png'] : [$source, $type];
    }

    /**
     * Downscale, keeping the alpha channel.
     *
     * PNG whatever went in. A logo is very often transparent and the masthead
     * band is tinted, so losing the alpha turns a transparent mark into a
     * white or black rectangle — which is both the most visible way this could
     * go wrong and the one nobody would think to check, because it looks fine
     * on the settings page where the ground happens to be white.
     */
    private static function resample(string $source, int $type, int $width, int $height): ?string
    {
        $image = match ($type) {
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_GIF  => @imagecreatefromgif($source),
            default        => false,
        };

        if (!$image) {
            return null;
        }

        $scale = min(
            self::MAX_HEIGHT / max(1, $height),
            self::MAX_WIDTH / max(1, $width),
            1.0
        );

        $target_w = max(1, (int) round($width * $scale));
        $target_h = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($target_w, $target_h);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $target_w, $target_h, $width, $height);

        ob_start();
        imagepng($canvas, null, 9);
        $bytes = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($image);

        return $bytes !== '' ? $bytes : null;
    }

    // ------------------------------------------------------------- the cache

    private static function cachePath(): string
    {
        $revision = '0';

        if (class_exists(\GlpiPlugin\Whitelabel\Settings::class)) {
            /** @var class-string $settings */
            $settings = \GlpiPlugin\Whitelabel\Settings::class;
            $revision = (string) $settings::get('revision');
        }

        return self::dir() . '/logo-' . preg_replace('/\W+/', '', $revision) . '.png';
    }

    private static function cached(): ?string
    {
        $path = self::cachePath();

        return is_file($path) && filesize($path) > 0 ? $path : null;
    }

    /**
     * Write the cache entry, then drop the ones it replaces.
     *
     * In that order, and through a rename, because two mail clients fetching
     * the logo at the same moment is the normal case rather than the unlucky
     * one — a notification goes to everybody at once. Writing in place lets a
     * concurrent request read a half-written PNG; sweeping before writing lets
     * one delete the file another is about to read, and the reader answers 404,
     * which the recipient sees as a broken masthead with nothing in any log to
     * explain it.
     *
     * `rename()` within one directory is atomic on every filesystem GLPI runs
     * on, and the sweep afterwards skips the file just published.
     *
     * The sweep exists because glpi-whitelabel bumps its revision — which is
     * this cache's key — on *every* save of its settings page, not only when an
     * image changes. An administrator tuning the dark palette would otherwise
     * leave a trail of identical PNGs behind.
     */
    private static function publish(string $target, string $bytes): bool
    {
        $temporary = $target . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temporary, $bytes) === false || !@rename($temporary, $target)) {
            @unlink($temporary);

            return false;
        }

        foreach ((array) @glob(self::dir() . '/logo-*.png') as $stale) {
            if ((string) $stale !== $target) {
                @unlink((string) $stale);
            }
        }

        return true;
    }

    private static function dir(): string
    {
        $dir = GLPI_PLUGIN_DOC_DIR . '/glpimail';

        if (!is_dir($dir)) {
            @mkdir($dir, 0o770, true);
        }

        return $dir;
    }
}
