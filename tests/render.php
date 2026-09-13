<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Render every notification in the catalog and check what came out.
 *
 * Pure PHP: no GLPI bootstrap and no database, so it runs in CI's bare
 * `php:8.3` image. The two GLPI classes the renderer touches — `Config` for
 * settings and `Plugin` for "is glpi-whitelabel here" — are stubbed below,
 * which is enough because neither has any bearing on the markup.
 *
 * What it is actually guarding:
 *
 *  - **Tag syntax.** GLPI's template processor is a set of regular expressions
 *    over a string ({@see \NotificationTemplate::processIf()}). An unbalanced
 *    `##IF…##` does not raise anything; it silently leaves half a card in the
 *    mail, or eats the rest of the body. Nothing in PHP catches that, so it is
 *    caught here.
 *  - **Well-formed markup.** The body is concatenated into GLPI's `<body>`
 *    without being parsed, so an unclosed `<td>` is discovered by a reader, in
 *    an inbox, a week later.
 *  - **No `<style>` and no `<script>` in the body.** The stylesheet belongs in
 *    the template's `css` field, which GLPI puts in the `<head>`; a `<style>`
 *    in the body is dropped by several clients and, worse, shown as text by
 *    one or two. A `<script>` would just be a mistake.
 *  - **A text part that is text.** It is easy to write a `plain()` branch that
 *    quietly emits a tag.
 */

declare(strict_types=1);

define('PLUGIN_GLPIMAIL_CONFIG_CONTEXT', 'plugin:glpimail');
define('PLUGIN_GLPIMAIL_MARKUP_VERSION', 1);

/** The two GLPI classes the renderer reaches for, and nothing else. */
class Config
{
    public static function getConfigurationValues($context, $keys = []): array
    {
        return [];
    }
}

class Plugin
{
    public static function isPluginActive($name): bool
    {
        return false;
    }
}

require __DIR__ . '/../src/Settings.php';
require __DIR__ . '/../src/Theme.php';
require __DIR__ . '/../src/Brand.php';
require __DIR__ . '/../src/Css.php';
require __DIR__ . '/../src/Shell.php';
require __DIR__ . '/../src/Letter.php';
require __DIR__ . '/../src/Registry.php';
require __DIR__ . '/../src/Catalog.php';

use GlpiPlugin\Glpimail\Brand;
use GlpiPlugin\Glpimail\Catalog;
use GlpiPlugin\Glpimail\Letter;
use GlpiPlugin\Glpimail\Registry;
use GlpiPlugin\Glpimail\Css;
use GlpiPlugin\Glpimail\Settings;
use GlpiPlugin\Glpimail\Shell;
use GlpiPlugin\Glpimail\Theme;

$failures = 0;
$checks   = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures, $checks;

    $checks++;

    if ($ok) {
        return;
    }

    $failures++;
    printf("  FAIL %s%s\n", $what, $detail !== '' ? ' — ' . $detail : '');
}

// ------------------------------------------------------------ the arithmetic

check('mix() reaches its endpoints', Theme::mix('#000000', '#ffffff', 1.0) === '#ffffff');
check('mix() stays put at zero', Theme::mix('#1c6fbb', '#ffffff', 0.0) === '#1c6fbb');
check('mix() clamps above one', Theme::mix('#000000', '#ffffff', 4.0) === '#ffffff');
check('white is bright', Theme::luminance('#ffffff') > 0.99);
check('black is not', Theme::luminance('#000000') < 0.01);

// The case the button ink exists for: a brand yellow is bright enough that
// white on it is unreadable, and a naive average would call it darker than a
// mid blue.
check('yellow reads as bright', Theme::luminance('#ffd400') > 0.55);
check('a brand blue does not', Theme::luminance('#1c6fbb') < 0.55);

$theme = Theme::fromAccent('#ffd400');
check('a bright accent gets dark ink', $theme->light('accent_ink') === '#10151c');
$theme = Theme::fromAccent('#1c6fbb');
check('a dark accent gets white ink', $theme->light('accent_ink') === '#ffffff');
check('a nonsense accent falls back', Theme::fromAccent('rgb(1,2,3)')->light('accent') === Settings::DEFAULTS['accent']);

// Every pill tone must be a real colour in both palettes, or a mail client
// drops the declaration and the pill becomes unstyled text.
foreach (array_keys(Theme::tones()) as $tone) {
    foreach ([Theme::tones()[$tone], Theme::darkTones()[$tone] ?? []] as $set) {
        foreach ($set as $colour) {
            check("tone $tone is six-digit hex", preg_match('/^#[0-9a-f]{6}$/i', (string) $colour) === 1, (string) $colour);
        }
    }
}

// --------------------------------------------------------------- the stylesheet

$css = Css::build(Theme::fromAccent('#1c6fbb'), true);
check('the enhancement sheet answers dark mode', str_contains($css, 'prefers-color-scheme: dark'));
check('the enhancement sheet stacks on a phone', str_contains($css, 'max-width: 600px'));
check('it styles the body GLPI appends to', str_contains($css, 'body {'));
// `text` is 65535 bytes, and it is written to every template.
check('it fits the column', strlen($css) < 60000, strlen($css) . ' bytes');

// The default. An empty string rather than a comment: GLPI emits the <style>
// element whatever this returns, and anything inside it is scored against the
// message. This is the assertion that keeps the 99% claim true.
check('enhancements off emits nothing at all', Css::build(Theme::fromAccent('#1c6fbb'), false) === '');

// ------------------------------------------------- the contributor contract

// Registry complains through trigger_error, which is the right channel in
// production and noise here. Counted instead, so the tests can assert that a
// bad offer is *rejected loudly* rather than merely rejected.
$warnings = 0;
set_error_handler(static function (int $level) use (&$warnings): bool {
    $warnings++;

    return true;
}, E_USER_WARNING);

$letter = Letter::make()
    ->eyebrow('##alert.action##')
    ->title('##alert.name##')
    ->pill('##alert.severity##', Letter::BAD)
    ->pill('step ##alert.step##')
    ->button('Acknowledge it', '##alert.url##')
    ->row('##lang.alert.host##', '##alert.host##', when: '##alert.host##')
    ->row('##lang.alert.seen##', '##alert.first_seen##')
    ->panel('##lang.alert.summary##', '##alert.summary##')
    ->when('alert.runbook', static fn(Letter $l) => $l->link('Runbook', '##alert.runbook##'))
    ->loop('events', 3, static fn(Letter $l) => $l->row('##event.at##', '##event.what##'));

$tree = $letter->blocks();
$kinds = array_map(static fn(array $b): string => (string) $b[0], $tree);

check('a letter keeps its order', $kinds === [
    'eyebrow', 'title', 'pills', 'button', 'meta', 'panel', 'if', 'loop',
], implode(',', $kinds));

// Two pill() calls make one row, not two — otherwise a contributor writing the
// obvious thing gets two tables stacked on top of each other.
check('consecutive pills group', count($tree[2][1]) === 2);
check('consecutive rows group', count($tree[4][1]) === 2);

// The trap this normalisation exists for: `when: '##alert.host##'` is what
// everybody writes, and GLPI's syntax needs the bare field. Left alone it
// renders `##IF##alert.host####`, which matches nothing and silently drops the
// block.
check('a conditional row takes the hashes off its tag', ($tree[4][1][0][2] ?? '') === 'alert.host',
    (string) ($tree[4][1][0][2] ?? '(none)'));
check('so does when()', ($tree[6][1] ?? '') === 'alert.runbook', (string) ($tree[6][1] ?? '(none)'));

check('a loop keeps its limit', ($tree[7][1] ?? '') === 'events' && ($tree[7][2] ?? null) === 3);

// An invented tone is a grey pill, not a lost notification.
check('an unknown tone falls back', Letter::make()->pill('x', 'critical')->blocks()[0][1][0][1] === 'neutral');
check('an empty letter says so', Letter::make()->isEmpty());
check('a letter with only an empty loop is still empty',
    Letter::make()->loop('x', 1, static fn(Letter $l) => null)->isEmpty());

// --- what the hook accepts, and what it refuses --------------------------

$GLOBALS['PLUGIN_HOOKS'] = ['glpimail_letters' => [
    'goodplugin' => static fn(): array => [[
        'name'     => 'Test notification',
        'itemtype' => 'Config',
        'summary'  => 'A test.',
        'build'    => static fn(): Letter => Letter::make()->title('##x.name##'),
    ]],
    'collider' => static fn(): array => [[
        // GLPI's own. Must be refused, or a plugin could silently redefine
        // what a ticket notification says by shipping a name collision.
        'name'     => 'Tickets',
        'itemtype' => 'Ticket',
        'build'    => static fn(): Letter => Letter::make()->title('mine now'),
    ]],
    'thrower' => static fn(): array => [[
        'name'     => 'Broken',
        'itemtype' => 'Config',
        'build'    => static function (): Letter { throw new RuntimeException('nope'); },
    ]],
    'liar' => static fn(): array => [[
        'name'     => 'Not a letter',
        'itemtype' => 'Config',
        'build'    => static fn(): string => '<p>html</p>',
    ]],
    'exploder' => static function (): array { throw new RuntimeException('listing failed'); },
]];

// `Ticket` is not loaded in this bare harness, so the collision test needs the
// itemtype it collides on to exist. Catalog keys on name *and* itemtype.
class_alias('Config', 'Ticket');

Registry::reset();
$contributed = Registry::letters();

check('a good offer is accepted', isset($contributed['Test notification']));
check('and is attributed to its plugin',
    ($contributed['Test notification']['plugin'] ?? '') === 'goodplugin');
check('a plugin cannot redefine one of GLPI’s own', !isset($contributed['Tickets']));
check('a builder that throws is dropped', !isset($contributed['Broken']));
check('so is one that does not return a Letter', !isset($contributed['Not a letter']));
check('a hook that throws does not take the rest with it', count($contributed) === 1,
    implode(',', array_keys($contributed)));
check('and every rejection was reported', $warnings >= 4, "warnings=$warnings");

check('contributed letters join the catalog', isset(Catalog::all()['Test notification']));
check('without displacing GLPI’s', Catalog::all()['Tickets']['plugin'] === '');
check('core() is core only', !isset(Catalog::core()['Test notification']));

restore_error_handler();
$GLOBALS['PLUGIN_HOOKS'] = [];
Registry::reset();

// ------------------------------------------------------------- every template

$shell   = new Shell(Theme::fromAccent('#1c6fbb'), Brand::neutral());
$catalog = Catalog::all();

check('the catalog is not empty', $catalog !== []);

foreach ($catalog as $name => $entry) {
    $html = $shell->html($entry['blocks']);
    $text = $shell->plain($entry['blocks']);

    check("$name is stamped", str_starts_with($html, '<!--glpimail:1-->'));
    check("$name carries no stylesheet", !str_contains($html, '<style'));
    check("$name carries no script", stripos($html, '<script') === false);
    check("$name has a text part", trim($text) !== '');
    check("$name's text part is text", !preg_match('/<[a-z\/][^>]*>/i', $text));

    // -------- the tag constructs must balance, in the HTML and in the text

    foreach (['html' => $html, 'text' => $text] as $part => $body) {
        preg_match_all('/##FOREACH(?:\s+(?:FIRST|LAST)\s+\d+)?\s*([a-z0-9_]+)##/i', $body, $open);
        preg_match_all('/##ENDFOREACH([a-z0-9_]+)##/i', $body, $close);

        sort($open[1]);
        sort($close[1]);
        check("$name ($part) closes every FOREACH", $open[1] === $close[1], implode(',', array_diff($open[1], $close[1])));

        foreach (['IF' => 'ENDIF', 'ELSE' => 'ENDELSE'] as $head => $tail) {
            preg_match_all('/##' . $head . '([a-z0-9._-]+?)(?:=[^#]*)?##/i', $body, $o);
            preg_match_all('/##' . $tail . '([a-z0-9._-]+)##/i', $body, $c);

            sort($o[1]);
            sort($c[1]);
            check(
                "$name ($part) closes every $head",
                $o[1] === $c[1],
                implode(',', array_merge(array_diff($o[1], $c[1]), array_diff($c[1], $o[1])))
            );
        }

        // An ##ELSE## with no ##IF## on the same field is dead markup: GLPI
        // only reaches the ELSE branch while processing that field's IF.
        preg_match_all('/##ELSE([a-z0-9._-]+)##/i', $body, $e);
        foreach (array_unique($e[1]) as $field) {
            check(
                "$name ($part) has an IF for ELSE$field",
                preg_match('/##IF' . preg_quote($field, '/') . '(?:=[^#]*)?##/i', $body) === 1
            );
        }
    }

    // -------- the vocabulary is the whole compatibility claim

    // Measured against Mailpit's client-support tables, these are the
    // properties that cost the difference between 89% and 99.5% support. They
    // are replaced by attributes and spacer cells throughout — see the table in
    // Shell's class comment — and a regression here is silent, because the
    // markup still renders perfectly well in a browser.
    foreach ([
        'padding', 'margin', 'text-align', 'font-weight', 'background-color',
        'display', 'max-width', 'max-height', 'white-space', 'word-break',
        'outline', 'text-transform', 'border-collapse', 'border-left', 'border-top',
    ] as $banned) {
        check(
            "$name uses no $banned",
            preg_match('/(?<![-\w])' . preg_quote($banned, '/') . '\s*:/', $html) !== 1,
            $banned
        );
    }

    // The four that are kept, and only because they are confined to a small
    // share of the nodes. The cost is per node weighted by client share, so
    // what matters is not how many times a property appears but what fraction
    // of the message carries it — on the largest template today the worst of
    // these is on 7% of styled nodes. A property that spreads across the card
    // takes the score with it: `text-decoration` on every element measured 2.2
    // points, and on the button's anchor alone it measured nothing.
    $styled = max(1, preg_match_all('/style="/', $html));

    foreach (['line-height', 'letter-spacing', 'border-radius', 'text-decoration'] as $prop) {
        $uses  = preg_match_all('/(?<![-\w])' . preg_quote($prop, '/') . '\s*:/', $html);
        $share = 100 * $uses / $styled;

        check(
            "$name confines $prop to a small share of nodes",
            $share <= 15.0,
            sprintf('%d of %d styled nodes (%.1f%%)', $uses, $styled, $share)
        );
    }

    // -------- no tag may have been case-mangled on its way through

    // GLPI substitutes with strtr(), which is case-sensitive, so `##TICKET.URL##`
    // is not a tag — it is four words that will be printed to an entity. The
    // text renderer upper-cases headings, and most headings in the catalog are
    // tags; this is the check that keeps those two facts apart.
    foreach (['html' => $html, 'text' => $text] as $part => $body) {
        preg_match_all('/##([A-Za-z][A-Za-z0-9._-]*)##/', $body, $tags);

        foreach (array_unique($tags[1]) as $tag) {
            if (preg_match('/^(IF|ELSE|ENDIF|ENDELSE|FOREACH|ENDFOREACH)/', $tag) === 1) {
                continue;
            }

            check("$name ($part) keeps $tag lower-case", $tag === strtolower($tag), $tag);
        }
    }

    // -------- the deliberately unclosed <font>

    // The body ends with an open `<font>` so that the line GLPI appends after
    // it — outside anything this plugin emits — inherits type from something.
    // See Shell::html(). It is the one unclosed element in the message and it
    // is load-bearing, so it is asserted rather than tolerated.
    check(
        "$name ends with the unclosed <font> that catches GLPI's footer",
        preg_match('/<font [^>]*>$/', trim($html)) === 1,
        substr(rtrim($html), -60)
    );

    check(
        "$name closes every other font",
        substr_count($html, '<font') - substr_count($html, '</font>') === 1,
        sprintf('%d open, %d closed', substr_count($html, '<font'), substr_count($html, '</font>'))
    );

    // -------- the markup must parse

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    // Wrapped, because a fragment has no root and the tags are not XML. The
    // trailing `<font>` is closed here rather than excused: what is being
    // checked is that nothing *else* is unbalanced, and leaving it open would
    // mask a second unclosed element behind the same error.
    $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</font></body></html>');

    $errors = array_filter(
        libxml_get_errors(),
        // Only structural complaints. libxml objects to HTML5 elements and to
        // unknown entities, neither of which is a mail problem.
        static fn($error): bool => str_contains($error->message, 'Unexpected end tag')
            || str_contains($error->message, 'Opening and ending tag mismatch')
            || str_contains($error->message, 'end tag does not match')
    );
    libxml_clear_errors();

    check("$name parses", $errors === [], $errors === [] ? '' : trim(reset($errors)->message));
}

printf(
    "%s: %d templates, %d checks, %d failures\n",
    basename(__FILE__),
    count($catalog),
    $checks,
    $failures
);

exit($failures === 0 ? 0 : 1);
