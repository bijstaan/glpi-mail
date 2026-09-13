<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Put every rendered template through GLPI's own template processor.
 *
 * tests/render.php checks the tag constructs with regular expressions of its
 * own. This checks them against the regular expressions that will actually read
 * them, which is not the same thing:
 * {@see \NotificationTemplate::processIf()} matches `##ENDIF<field>##` on the
 * field name alone and rewrites one block per occurrence in document order, and
 * {@see \NotificationTemplate::process()} unrolls `##FOREACH…##` before either.
 * A body can balance perfectly and still come out of that with half a card
 * missing.
 *
 * So: render each template, hand it invented data, and require that what comes
 * back has no `##` left in it and still parses. A surviving tag is a tag GLPI
 * would have printed to an entity.
 *
 * Needs a booted GLPI, so it runs in the container rather than in CI:
 *
 *     docker exec glpi-glpi-1 php /var/www/glpi/plugins/glpimail/tests/process.php
 */

$root = '/var/www/glpi';

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "This has to run inside the GLPI container.\n");
    exit(2);
}

chdir($root);
require $root . '/vendor/autoload.php';

(new Glpi\Kernel\Kernel('production'))->boot();

if (!defined('PLUGIN_GLPIMAIL_MARKUP_VERSION')) {
    // The plugin's setup.php is only loaded for an *active* plugin, and this
    // test is worth running before anybody enables it.
    require dirname(__DIR__) . '/setup.php';
}

// Required rather than autoloaded: GLPI registers a plugin's namespace only
// for an *active* plugin, and this is worth running before anybody enables it.
foreach (['Settings', 'Theme', 'Brand', 'Css', 'Shell', 'Catalog', 'Preview'] as $class) {
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}

use GlpiPlugin\Glpimail\Brand;
use GlpiPlugin\Glpimail\Catalog;
use GlpiPlugin\Glpimail\Preview;
use GlpiPlugin\Glpimail\Shell;
use GlpiPlugin\Glpimail\Theme;

$failures = 0;
$shell    = new Shell(Theme::fromAccent('#1c6fbb'), Brand::neutral());

foreach (Catalog::all() as $name => $entry) {
    $rendered = Preview::fill($shell->html($entry['blocks']));

    if (preg_match_all('/##[^#]{0,60}##/', $rendered, $left) > 0) {
        $failures++;
        printf("  FAIL %s left %d tags: %s\n", $name, count($left[0]), implode(' ', array_slice($left[0], 0, 4)));
    }

    if (trim(strip_tags($rendered)) === '') {
        $failures++;
        printf("  FAIL %s rendered to nothing\n", $name);
    }

    // A FOREACH that did not unroll leaves one copy of its contents rather
    // than three, and the tag check above would not notice — the tags inside
    // it are substituted either way. Every loop in the catalog opens with a
    // divider, so three dividers is three rows.
    foreach ($entry['blocks'] as $block) {
        if (($block[0] ?? '') !== 'loop') {
            continue;
        }

        $opens_with_divider = (($block[3][0][0] ?? '') === 'divider');

        if ($opens_with_divider && substr_count($rendered, 'class="gm-hr"') < 3) {
            $failures++;
            printf("  FAIL %s did not unroll its %s loop\n", $name, $block[1]);
        }
    }

    $text = Preview::fill($shell->plain($entry['blocks']));

    if (preg_match_all('/##[^#]{0,60}##/', $text, $left_text) > 0) {
        $failures++;
        printf("  FAIL %s (text) left %d tags: %s\n", $name, count($left_text[0]), implode(' ', array_slice($left_text[0], 0, 4)));
    }
}

printf("%s: %d templates, %d failures\n", basename(__FILE__), count(Catalog::all()), $failures);

exit($failures === 0 ? 0 : 1);
