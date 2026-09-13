<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimail;

/**
 * The card, and everything that goes in it.
 *
 * A notification is described in {@see Catalog} as a list of blocks — an
 * eyebrow, a title, a button, some labelled rows, a quoted description — and
 * this class turns that list into the two bodies GLPI stores: `content_html`
 * and `content_text`. One description, two renderings, so the plain-text
 * alternative cannot drift out of step with the HTML the way a hand-maintained
 * pair always does.
 *
 * ### The vocabulary, and why it is this small
 *
 * Nested tables, `cellpadding`, `bgcolor`, `width` and `height` as attributes,
 * spacer cells instead of margins, `<strong>` instead of `font-weight`,
 * `align=` instead of `text-align`. Not nostalgia — arithmetic.
 *
 * Mailpit scores a message against caniemail's client-support tables, and the
 * scoring is **per node, weighted by client share**: a property costs what the
 * clients that fail it are worth, multiplied by how much of the message uses
 * it. Measured on a real ticket notification, the properties this file used to
 * lean on were the whole difference between 89% and 99.5% support:
 *
 * | dropped        | replaced by                      |
 * | -------------- | -------------------------------- |
 * | `padding`      | `cellpadding`, gutter cells      |
 * | `margin`       | spacer rows                      |
 * | `text-align`   | `align=`                         |
 * | `font-weight`  | `<strong>`                       |
 * | `background-color`, `width`, `height`, `border` | `bgcolor`, `width`, `height`, and a 1px coloured table |
 * | `display`, `max-width`, `max-height`, `white-space`, `word-break`, `outline`, `text-transform` | nothing; they were polish |
 *
 * What is left in `style=` is `color`, `font-family` and `font-size`, which
 * cost nothing at all, plus four properties kept deliberately on **a handful of
 * nodes each**, because that is where the per-node scoring makes them free or
 * nearly so: `text-decoration:none` on the button's anchor alone,
 * `line-height` on the four blocks that carry prose, `letter-spacing` on the
 * small-caps labels, and `border-radius` on the card, the button and the pills.
 * Applied to every element those four cost 8 points; applied to these they cost
 * a twentieth of one.
 *
 * The result renders the same in Word, in Gmail's stripped `<head>`, and in
 * whatever webmail an entity's finance department is still running.
 *
 * ### The enhancement layer is now opt-in
 *
 * The stacking on a phone and the dark card live in {@see Css}, and they are
 * **off by default**, because `@media` and `!important` together cost about ten
 * points of the same measurement. A client too old to understand them was
 * always going to fall back to this card; what changed is the recognition that
 * asking for them at all is a trade, and which side of it is the default.
 *
 * ### The tags
 *
 * Literal text is escaped; anything that looks like a GLPI tag is not, because
 * `##ticket.content##` is substituted with rich HTML and `##lang.ticket.title##`
 * with a translated label. Blocks come from this plugin's own catalog rather
 * than from a form, so the escaping here is a belt on a pair of braces —
 * except for the footer note, which really is administrator input, and which
 * is escaped at the point it enters.
 */
final class Shell
{
    public const CARD_WIDTH = 600;

    public function __construct(
        private readonly Theme $theme,
        private readonly Brand $brand,
    ) {
    }

    // ==================================================================== HTML

    /**
     * @param array<int,array<mixed>> $blocks
     */
    public function html(array $blocks): string
    {
        $t = $this->theme;

        $out = '<!--glpimail:' . PLUGIN_GLPIMAIL_MARKUP_VERSION . '-->';

        // The ground. `bgcolor`, not a style: see the note on the vocabulary in
        // the class comment.
        $out .= '<table class="gm-ground" width="100%" bgcolor="' . $t->light('ground')
              . '" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" valign="top">';

        $out .= $this->spacerTable(24);

        // A one-pixel table around the card, coloured like a border. `border`
        // as a CSS property on the card would cost more than every other
        // decision in this file put together; `cellpadding="1"` on a coloured
        // table is the same line, drawn by a primitive from 1997 that every
        // client agrees on.
        $out .= '<table class="gm-edge" width="' . self::CARD_WIDTH . '" bgcolor="' . $t->light('border')
              . '" cellpadding="1" cellspacing="0" border="0" align="center"'
              . $this->style(['border-radius' => '10px']) . '><tr><td>';

        $out .= '<table class="gm-card" width="100%" bgcolor="' . $t->light('card')
              . '" cellpadding="0" cellspacing="0" border="0">';

        $out .= $this->masthead();
        $out .= $this->accentRule();

        $out .= '<tr><td>' . $this->band(
            $this->stack(
                $this->spacerRow(24)
                . $this->blocks($blocks)
                . $this->spacerRow(8)
            )
        ) . '</td></tr>';

        $out .= $this->footer();

        $out .= '</table></td></tr></table>';
        $out .= $this->spacerTable(16);
        $out .= '</td></tr></table>';

        // A `<font>` that is deliberately never closed.
        //
        // GLPI appends its own "Automatically generated by …" after this body
        // and inside `<body>`, as a bare text node with no element around it:
        //
        //     … $template_datas['content_html']
        //       . "<br>" . htmlescape($footer_string)
        //
        // No selector reaches it and no inline style can be put on it. The
        // stylesheet used to catch it by styling `body`, and the stylesheet is
        // now off by default — so without this it arrives as a line of the
        // client's default 16px serif under a finished card, which is the last
        // thing the reader sees.
        //
        // An unclosed element is not a mistake here: HTML parsing closes it at
        // `</body>`, so everything GLPI adds afterwards — its signature, its
        // footer, its trailing breaks — inherits from it. `<font>` rather than
        // a `<span style>` because it is presentational markup that predates
        // CSS, which is exactly why every mail client honours it; measured, it
        // costs nothing at all against the client-support tables.
        //
        // If a client's sanitiser closes or drops it, the line is unstyled —
        // which is where it started.
        $out .= '<font face="' . $this->escape($this->font()) . '" size="1" color="'
              . $t->light('muted') . '">';

        return $out;
    }

    /**
     * A full-width band with the card's side gutters.
     *
     * The gutters are cells rather than padding. That is the single largest
     * decision in this file: `padding` is one of the properties that costs the
     * most compatibility, and a 32-pixel cell with a non-breaking space in it
     * is understood by every renderer ever written.
     */
    private function band(string $inner, string $bgcolor = ''): string
    {
        return '<table width="100%"' . ($bgcolor !== '' ? ' bgcolor="' . $bgcolor . '"' : '')
             . ' cellpadding="0" cellspacing="0" border="0"><tr>'
             . $this->gutter()
             . '<td align="left">' . $inner . '</td>'
             . $this->gutter()
             . '</tr></table>';
    }

    private function gutter(): string
    {
        return '<td width="32"' . $this->style(['font-size' => '1px']) . '>&nbsp;</td>';
    }

    /** The vertical stack every block writes its rows into. */
    private function stack(string $rows): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>';
    }

    /**
     * Vertical space, as a row.
     *
     * `font-size:1px` on a cell holding `&nbsp;` — without it the cell is at
     * least a line of text tall whatever `height` says, because the space is
     * text and text has a line box. `line-height:0` would be the modern way
     * and is one of the properties that had to go; `font-size` is free.
     */
    private function spacerRow(int $height, int $colspan = 1): string
    {
        return '<tr><td height="' . $height . '"' . ($colspan > 1 ? ' colspan="' . $colspan . '"' : '')
             . $this->style(['font-size' => '1px']) . '>&nbsp;</td></tr>';
    }

    /** The same, as its own table, for use between tables rather than within one. */
    private function spacerTable(int $height): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
             . $this->spacerRow($height) . '</table>';
    }

    /** A horizontal hairline, as a row. */
    private function rule(int $colspan = 1): string
    {
        return '<tr><td class="gm-hr" height="1" bgcolor="' . $this->theme->light('hairline') . '"'
             . ($colspan > 1 ? ' colspan="' . $colspan . '"' : '')
             . $this->style(['font-size' => '1px']) . '>&nbsp;</td></tr>';
    }

    /**
     * One line of type.
     *
     * `color`, `font-family` and `font-size` are the three declarations that
     * cost nothing at all. Everything else a line of type might want —
     * weight, alignment, spacing above and below — is an element or an
     * attribute here: `<strong>`, `align`, and a spacer row.
     */
    private function type(int $size, string $colour, string $content, string $class = '', array $extra = []): string
    {
        return '<td align="left"' . ($class !== '' ? ' class="' . $class . '"' : '')
             . $this->style($extra + [
                 'font-family' => $this->font(),
                 'font-size'   => $size . 'px',
                 'color'       => $colour,
             ]) . '>' . $content . '</td>';
    }

    /**
     * The masthead.
     *
     * Three states, from {@see Brand}, and the empty one is a real answer: a
     * strip of accent and nothing else, rather than the word "GLPI" in front
     * of somebody who has never heard of it.
     */
    private function masthead(): string
    {
        $t = $this->theme;

        if ($this->brand->logo) {
            // Height as an attribute, not a style. `alt` is the brand name and
            // not "Logo": Outlook and Gmail block remote images by default, and
            // the alt text is what most first-time readers will actually see.
            $inner = '<img src="' . Brand::logoUrl() . '"'
                   . ' alt="' . $this->escape($this->brand->name !== '' ? $this->brand->name : 'Home') . '"'
                   . ' height="30" border="0"'
                   . $this->style([
                       'font-family' => $this->font(),
                       'font-size'   => '17px',
                       'color'       => $t->light('ink'),
                   ]) . '>';
        } elseif ($this->brand->name !== '') {
            $inner = '<span class="gm-ink"' . $this->style([
                'font-family' => $this->font(),
                'font-size'   => '17px',
                'color'       => $t->light('ink'),
            ]) . '><strong>' . $this->escape($this->brand->name) . '</strong></span>';
        } else {
            // Neutral. Something has to occupy the row or the band collapses to
            // nothing and the accent rule sits on the card's top edge.
            $inner = '<span' . $this->style(['font-size' => '1px', 'color' => $t->light('faint')]) . '>&nbsp;</span>';
        }

        return '<tr><td class="gm-head" bgcolor="' . $t->light('faint') . '">'
             . $this->band(
                 $this->stack(
                     $this->spacerRow(17)
                     . '<tr>' . $this->type(17, $t->light('ink'), $inner) . '</tr>'
                     . $this->spacerRow(17)
                 )
             )
             . '</td></tr>';
    }

    private function accentRule(): string
    {
        return '<tr><td class="gm-rule" height="3" bgcolor="' . $this->theme->light('accent') . '"'
             . $this->style(['font-size' => '1px']) . '>&nbsp;</td></tr>';
    }

    private function footer(): string
    {
        $t    = $this->theme;
        $note = trim(Settings::get('footer_note'));
        $link = trim(Settings::get('footer_link'));

        $lines = [];

        if ($note !== '') {
            $lines[] = $this->escape($note);
        }

        if ($link !== '') {
            $lines[] = preg_match('#^https?://#i', $link) === 1
                ? '<a class="gm-link" href="' . $this->escape($link) . '"'
                    . $this->style(['color' => $t->light('accent')]) . '>'
                    . $this->escape($link) . '</a>'
                : $this->escape($link);
        }

        if ($lines === []) {
            // No footer of our own. GLPI's own "Automatically generated by …"
            // line is about to land under the card — see Css::build(), which
            // styles it through `body` when the enhancement sheet is on and
            // cannot reach it at all when it is off.
            return '';
        }

        return '<tr><td>' . $this->band(
            $this->stack(
                $this->rule()
                . $this->spacerRow(14)
                . '<tr>' . $this->type(12, $t->light('muted'), implode('<br>', $lines), 'gm-foot') . '</tr>'
                . $this->spacerRow(18)
            )
        ) . '</td></tr>';
    }

    // ------------------------------------------------------------- the blocks

    /** @param array<int,array<mixed>> $blocks */
    private function blocks(array $blocks): string
    {
        $out = '';

        foreach ($blocks as $block) {
            $out .= $this->block($block);
        }

        return $out;
    }

    /** @param array<mixed> $block */
    private function block(array $block): string
    {
        $t    = $this->theme;
        $kind = (string) ($block[0] ?? '');

        return match ($kind) {
            // Small caps by way of `<strong>` and tracking. `text-transform`
            // would be the obvious way to shout a tag whose value is not known
            // until send time; it is one of the properties that had to go, so
            // the eyebrow is emphatic rather than upper-case.
            'eyebrow' => '<tr>' . $this->type(11, $t->light('muted'),
                '<strong>' . $this->text((string) $block[1]) . '</strong>', 'gm-eyebrow',
                ['letter-spacing' => '0.8px']) . '</tr>' . $this->spacerRow(7),

            'title' => '<tr>' . $this->type(23, $t->light('ink'),
                '<strong>' . $this->text((string) $block[1]) . '</strong>', 'gm-title',
                ['line-height' => '30px']) . '</tr>' . $this->spacerRow(13),

            'lede' => '<tr>' . $this->type(15, $t->light('ink'),
                $this->text((string) $block[1]), 'gm-body',
                ['line-height' => '23px']) . '</tr>' . $this->spacerRow(16),

            // No `line-height` here, and the reason is arithmetic rather than
            // taste: a note is one or two lines of small print, so it gains
            // least from a set leading — and on the shortest templates, where
            // there are thirty styled nodes rather than a hundred and twenty,
            // the fixed cost of GLPI's own `<body>` and `<style>` is diluted
            // least. Password Forget measured 98.96% with it and 99.15%
            // without. It is kept where prose actually wraps: the title, the
            // lede, and the quoted panel.
            'note' => '<tr>' . $this->type(13, $t->light('muted'),
                $this->text((string) $block[1]), 'gm-muted') . '</tr>' . $this->spacerRow(16),

            'pills'   => $this->pills((array) $block[1]),
            'button'  => $this->button((string) $block[1], (string) $block[2]),
            'meta'    => $this->meta((array) $block[1]),
            'panel'   => $this->panel((string) $block[1], (string) $block[2]),
            'section' => $this->section((string) $block[1]),
            'divider' => $this->divider(),
            'link'    => $this->link((string) $block[1], (string) $block[2]),
            'raw'     => '<tr><td>' . (string) $block[1] . '</td></tr>',

            'loop' => $this->loop((string) $block[1], $block[2] === null ? null : (int) $block[2], (array) $block[3]),
            'if'   => $this->conditional((string) $block[1], null, (array) $block[2], (array) ($block[3] ?? [])),
            'ifeq' => $this->conditional((string) $block[1], (string) $block[2], (array) $block[3], (array) ($block[4] ?? [])),

            default => '',
        };
    }

    /**
     * A row of status pills.
     *
     * An entry is `[label, tone]`, or `[label, tone, tag, else_label,
     * else_tone]` for one that changes with the record — the status pill goes
     * green once there is a solve date and stays blue before it. The
     * conditional form wraps the whole cell, spacer included, so whichever
     * branch survives leaves a well-formed row.
     *
     * @param array<int,array<int,string>> $pills
     */
    private function pills(array $pills): string
    {
        $cells = '';

        foreach ($pills as $pill) {
            $label = (string) ($pill[0] ?? '');
            $tone  = (string) ($pill[1] ?? 'neutral');
            $tag   = (string) ($pill[2] ?? '');

            if ($tag === '') {
                $cells .= $this->pill($label, $tone);
                continue;
            }

            $cells .= '##IF' . $tag . '##' . $this->pill($label, $tone) . '##ENDIF' . $tag . '##'
                    . '##ELSE' . $tag . '##'
                    . $this->pill((string) ($pill[3] ?? $label), (string) ($pill[4] ?? 'neutral'))
                    . '##ENDELSE' . $tag . '##';
        }

        if ($cells === '') {
            return '';
        }

        return '<tr><td><table cellpadding="0" cellspacing="0" border="0"><tr>' . $cells
             . '</tr></table></td></tr>' . $this->spacerRow(18);
    }

    /** One pill cell, plus the spacer that follows it. */
    private function pill(string $label, string $tone): string
    {
        $tones               = Theme::tones();
        [$bg, $ink, $border] = $tones[$tone] ?? $tones['neutral'];

        // A pill per cell of a single-row table rather than an inline-block
        // span: `display` is one of the properties that had to go, and Word
        // gave `inline-block` a line of its own about half the time anyway.
        // The border is a one-pixel coloured table around a filled one, the
        // same trick as the card's edge.
        // Square, and measured rather than chosen. A pill needs `border-radius`
        // on two cells — the coloured border and the fill inside it — and on a
        // short template that is a tenth of every styled node in the message.
        // Saved searches alerts measured 98.88% with rounded pills and 99.36%
        // without. The radius is kept where it is one node: the card's edge and
        // the button. A small bordered rectangle still reads as a tag.
        return '<td class="gm-pill-' . $this->escape($tone) . '" bgcolor="' . $border . '">'
             . '<table cellpadding="0" cellspacing="0" border="0"><tr><td class="gm-pill-fill-'
             . $this->escape($tone) . '" bgcolor="' . $bg . '">'
             . '<table cellpadding="4" cellspacing="0" border="0"><tr>'
             . $this->type(12, $ink, '<strong>' . $this->text($label) . '</strong>')
             . '</tr></table></td></tr></table></td>'
             . '<td width="6"' . $this->style(['font-size' => '1px']) . '>&nbsp;</td>';
    }

    private function button(string $label, string $url): string
    {
        $t = $this->theme;

        // The button: a coloured cell with the padding supplied by an inner
        // table's `cellpadding`, and the anchor filling it. `display:inline-block`
        // and `padding` were how this was done before, and both are among the
        // properties that cost the most; `cellpadding` is the primitive every
        // renderer implements.
        //
        // `text-decoration:none` is kept, on this one anchor. It is the only
        // node in the whole message that carries it, and measured against
        // Mailpit's client tables one node costs nothing — an underlined
        // button label is the one thing here that would read as a mistake.
        return '<tr><td><table cellpadding="0" cellspacing="0" border="0"><tr>'
             . '<td class="gm-btn" bgcolor="' . $t->light('accent') . '"'
             . $this->style(['border-radius' => '7px']) . '>'
             . '<table cellpadding="12" cellspacing="0" border="0"><tr><td align="center">'
             . '<a class="gm-btn-a" href="' . $url . '" target="_blank"'
             . $this->style([
                 'text-decoration' => 'none',
                 'font-family'     => $this->font(),
                 'font-size'       => '15px',
                 'color'           => $t->light('accent_ink'),
             ]) . '><strong>' . $this->text($label) . '</strong></a>'
             . '</td></tr></table></td></tr></table></td></tr>'
             . $this->spacerRow(20);
    }

    /**
     * The labelled rows.
     *
     * An entry is `[label, value]`, or `[label, value, tag]` for a row that is
     * only printed when the tag has a value — a category on a ticket that has
     * none is a row saying "Category:" and nothing, which reads as a fault
     * rather than as an absence.
     *
     * Each row is a hairline table followed by a cell table, and a conditional
     * row wraps *both* — otherwise a row that is dropped leaves its rule
     * behind and the card grows a double line where a field used to be.
     *
     * @param array<int,array<int,string>> $rows
     */
    private function meta(array $rows): string
    {
        $t   = $this->theme;
        $out = '';

        foreach ($rows as $row) {
            $label = (string) ($row[0] ?? '');
            $value = (string) ($row[1] ?? '');
            $tag   = (string) ($row[2] ?? '');

            // A fixed 33% label column, not a proportional one: the labels are
            // translated at send time and a German "Bearbeitungszeitraum" next
            // to an English "Due" would otherwise resize the grid per language.
            $line = '<table class="gm-stack" width="100%" cellpadding="0" cellspacing="0" border="0">'
                  . $this->rule(3)
                  . '<tr>'
                  . '<td class="gm-label" width="33%" valign="top">'
                  . '<table cellpadding="9" cellspacing="0" border="0"><tr>'
                  . $this->type(13, $t->light('muted'), $this->text($label))
                  . '</tr></table></td>'
                  . '<td width="12"' . $this->style(['font-size' => '1px']) . '>&nbsp;</td>'
                  . '<td class="gm-value" valign="top">'
                  . '<table cellpadding="9" cellspacing="0" border="0"><tr>'
                  . $this->type(14, $t->light('ink'), $this->text($value))
                  . '</tr></table></td>'
                  . '</tr></table>';

            $out .= $tag === ''
                ? $line
                : '##IF' . $tag . '##' . $line . '##ENDIF' . $tag . '##';
        }

        return $out === ''
            ? ''
            : '<tr><td>' . $out . '</td></tr>' . $this->spacerRow(18);
    }

    private function panel(string $title, string $content): string
    {
        $t   = $this->theme;
        $out = '';

        if ($title !== '') {
            $out .= '<tr>' . $this->type(11, $t->light('muted'),
                '<strong>' . $this->text($title) . '</strong>', 'gm-label',
                ['letter-spacing' => '0.6px']) . '</tr>' . $this->spacerRow(7);
        }

        // The accent as a left rule rather than as a fill: the panel holds
        // whatever rich text somebody typed into the ticket, in whatever
        // colours their editor left on it, and a tinted ground behind
        // arbitrary inline colours is how you get grey-on-grey.
        return $out . '<tr><td><table class="gm-panel" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
             . '<td class="gm-panel-bar" width="3" bgcolor="' . $t->light('accent') . '"'
             . $this->style(['font-size' => '1px']) . '>&nbsp;</td>'
             . '<td class="gm-panel-body" bgcolor="' . $t->light('faint') . '">'
             . '<table width="100%" cellpadding="13" cellspacing="0" border="0"><tr>'
             . $this->type(14, $t->light('ink'), $content, 'gm-body', ['line-height' => '22px'])
             . '</tr></table></td>'
             . '</tr></table></td></tr>' . $this->spacerRow(18);
    }

    private function section(string $title): string
    {
        return $this->spacerRow(6)
             . '<tr>' . $this->type(11, $this->theme->light('muted'),
                 '<strong>' . $this->text($title) . '</strong>', 'gm-label',
                 ['letter-spacing' => '0.8px']) . '</tr>'
             . $this->spacerRow(10);
    }

    private function divider(): string
    {
        return $this->spacerRow(18) . $this->rule() . $this->spacerRow(18);
    }

    private function link(string $label, string $url): string
    {
        return '<tr>' . $this->type(14, $this->theme->light('accent'),
            '<a class="gm-link" href="' . $url . '" target="_blank"'
            . $this->style([
                'font-family' => $this->font(),
                'font-size'   => '14px',
                'color'       => $this->theme->light('accent'),
            ]) . '>' . $this->text($label) . '</a>') . '</tr>'
            . $this->spacerRow(14);
    }

    /** @param array<int,array<mixed>> $blocks */
    private function loop(string $name, ?int $limit, array $blocks): string
    {
        // GLPI's own syntax. `FOREACH LAST n` keeps the most recent n, which is
        // the right end of a followup list to keep; a plain FOREACH prints all
        // of them, which on a two-year-old ticket is a mail nobody opens.
        $open = $limit === null
            ? '##FOREACH' . $name . '##'
            : '##FOREACH LAST ' . $limit . ' ' . $name . '##';

        return $open . $this->blocks($blocks) . '##ENDFOREACH' . $name . '##';
    }

    /**
     * @param array<int,array<mixed>> $then
     * @param array<int,array<mixed>> $else
     */
    private function conditional(string $tag, ?string $equals, array $then, array $else): string
    {
        $head = $equals === null ? '##IF' . $tag . '##' : '##IF' . $tag . '=' . $equals . '##';

        $out = $head . $this->blocks($then) . '##ENDIF' . $tag . '##';

        if ($else !== []) {
            // GLPI's ELSE is a separate construct with its own opening tag
            // rather than a branch of the IF, and it is matched by the *same*
            // field name — see NotificationTemplate::processIf(). Writing it
            // as `##ELSEtag## … ##ENDELSEtag##` is not a stylistic choice.
            $out .= '##ELSE' . $tag . '##' . $this->blocks($else) . '##ENDELSE' . $tag . '##';
        }

        return $out;
    }

    // ==================================================================== text

    /**
     * The plain-text alternative, from the same blocks.
     *
     * Not an afterthought. It is what a screen reader gets from a client
     * configured to prefer text, what lands in a ticketing system that strips
     * HTML on ingest, and what several corporate gateways deliver instead of
     * the HTML part. GLPI will happily send an HTML-only notification; it
     * should not.
     *
     * @param array<int,array<mixed>> $blocks
     */
    public function plain(array $blocks): string
    {
        $lines = $this->plainBlocks($blocks);

        // Collapse runs of blank lines rather than trying to be exact about
        // them per block — the blocks do not know what follows them.
        $out = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));

        return trim((string) $out) . "\n";
    }

    /**
     * @param array<int,array<mixed>> $blocks
     * @return array<int,string>
     */
    private function plainBlocks(array $blocks): array
    {
        $lines = [];

        foreach ($blocks as $block) {
            $kind = (string) ($block[0] ?? '');

            switch ($kind) {
                case 'eyebrow':
                    $lines[] = self::shout((string) $block[1]);
                    break;

                case 'title':
                    $lines[] = '';
                    $lines[] = (string) $block[1];
                    $lines[] = str_repeat('=', 60);
                    break;

                case 'lede':
                case 'note':
                    $lines[] = '';
                    $lines[] = (string) $block[1];
                    break;

                case 'pills':
                    $labels = [];
                    foreach ((array) $block[1] as $pill) {
                        // The plain-text part takes the unconditional branch of
                        // a conditional pill. Both branches carry the same tag
                        // — only the colour differs — so there is nothing to
                        // lose by not asking, and an ##IF## around three
                        // characters of a status line is noise.
                        $labels[] = '[' . ($pill[0] ?? '') . ']';
                    }
                    if ($labels !== []) {
                        $lines[] = implode(' ', $labels);
                    }
                    break;

                case 'button':
                case 'link':
                    $lines[] = '';
                    $lines[] = $block[1] . ': ' . $block[2];
                    break;

                case 'meta':
                    $lines[] = '';
                    foreach ((array) $block[1] as $row) {
                        $line = ($row[0] ?? '') . ': ' . ($row[1] ?? '');
                        $tag  = (string) ($row[2] ?? '');

                        $lines[] = $tag === ''
                            ? $line
                            : '##IF' . $tag . '##' . $line . '##ENDIF' . $tag . '##';
                    }
                    break;

                case 'panel':
                    $lines[] = '';
                    if ((string) $block[1] !== '') {
                        $lines[] = self::shout((string) $block[1]);
                    }
                    $lines[] = (string) $block[2];
                    break;

                case 'section':
                    $lines[] = '';
                    $lines[] = '-- ' . (string) $block[1] . ' ' . str_repeat('-', max(0, 56 - strlen((string) $block[1])));
                    break;

                case 'divider':
                    $lines[] = '';
                    $lines[] = str_repeat('-', 60);
                    break;

                case 'raw':
                    // HTML by definition. There is nothing sensible to put in
                    // the text part for it, and a tag soup would be worse than
                    // the gap.
                    break;

                case 'loop':
                    $limit   = $block[2] === null ? null : (int) $block[2];
                    $lines[] = $limit === null
                        ? '##FOREACH' . $block[1] . '##'
                        : '##FOREACH LAST ' . $limit . ' ' . $block[1] . '##';
                    $lines   = array_merge($lines, $this->plainBlocks((array) $block[3]));
                    $lines[] = '##ENDFOREACH' . $block[1] . '##';
                    break;

                case 'if':
                case 'ifeq':
                    $tag     = (string) $block[1];
                    $equals  = $kind === 'ifeq' ? (string) $block[2] : null;
                    $then    = (array) ($kind === 'ifeq' ? $block[3] : $block[2]);
                    $else    = (array) ($kind === 'ifeq' ? ($block[4] ?? []) : ($block[3] ?? []));

                    $lines[] = $equals === null ? '##IF' . $tag . '##' : '##IF' . $tag . '=' . $equals . '##';
                    $lines   = array_merge($lines, $this->plainBlocks($then));
                    $lines[] = '##ENDIF' . $tag . '##';

                    if ($else !== []) {
                        $lines[] = '##ELSE' . $tag . '##';
                        $lines   = array_merge($lines, $this->plainBlocks($else));
                        $lines[] = '##ENDELSE' . $tag . '##';
                    }
                    break;
            }
        }

        return $lines;
    }

    /**
     * Upper-case a heading in the text part — unless it is a tag.
     *
     * `strtoupper('##ticket.action##')` is `##TICKET.ACTION##`, and GLPI
     * substitutes tags with `strtr()`, which is case-sensitive. So the
     * shouted version is not a shouted heading: it is a tag that will never
     * be replaced, printed literally in the mail. Most headings in the
     * catalog *are* tags, which is how this was one line away from shipping.
     */
    private static function shout(string $value): string
    {
        return str_contains($value, '##') ? $value : strtoupper($value);
    }

    // ================================================================ plumbing

    private function font(): string
    {
        return '-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif';
    }

    /** @param array<string,string> $declarations */
    private function style(array $declarations): string
    {
        $parts = [];

        foreach ($declarations as $property => $value) {
            $parts[] = $property . ':' . $value;
        }

        return ' style="' . implode(';', $parts) . '"';
    }

    /**
     * Escape literal text without touching GLPI's tags.
     *
     * Tags are `##word.word##` and contain nothing `htmlspecialchars()` would
     * alter, so this is a plain escape — the method exists to name the
     * intention, and to be the one place to change if a tag ever grows a
     * character that needs protecting.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /** Body text: same escape, kept separate because its callers pass tags. */
    private function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
