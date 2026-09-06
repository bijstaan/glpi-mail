<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The masthead image.
 *
 * Same reasoning as glpi-whitelabel's asset.php, one step further out: this is
 * requested by a mail client rather than by a browser with a session, so there
 * is no rights check and cannot be one. There is also nothing to check — the
 * request carries no parameters at all. It asks for "the logo", and which file
 * that is has already been decided by configuration.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimail\Logo;

if (!Logo::send()) {
    // 404 rather than a placeholder image. The masthead is an <img> whose alt
    // text is the brand name, so a failed fetch degrades to the wordmark —
    // which is the same fallback a reader with images turned off already gets.
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'not found';
}
