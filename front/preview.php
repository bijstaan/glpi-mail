<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * One notification, rendered as it will be sent.
 *
 * Served as a bare document rather than inside GLPI's chrome, because it goes
 * in an iframe on the settings page and the point of it is to be *exactly* the
 * mail — GLPI's own stylesheet leaking into the frame would make a card look
 * right that is not.
 *
 * Reading the templates is a configuration act, so it takes the same right the
 * settings page takes. Nothing here writes.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimail\Catalog;
use GlpiPlugin\Glpimail\Preview;

Session::checkRight('config', READ);

$name    = (string) ($_GET['name'] ?? '');
$catalog = Catalog::all();

if (!isset($catalog[$name])) {
    $name = (string) array_key_first($catalog);
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

echo Preview::render($name);
