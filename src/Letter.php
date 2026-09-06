<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimail;

/**
 * A notification, described as data.
 *
 * This is the whole contract a contributing plugin sees. It says *what* the
 * mail contains — an eyebrow, a title, a status pill, some labelled rows, a
 * quoted panel — and says nothing about how any of it is drawn. {@see Shell}
 * owns that, and owns it alone.
 *
 * The alternative was letting each plugin hand over a string of HTML for its
 * template body, which is faster to write exactly once. It was rejected for the
 * same reason glpi-pdf rejected it for TCPDF, and the reason is sharper here:
 * mail clients support a narrow, undocumented and *mutually inconsistent*
 * subset of HTML and CSS, so every plugin would separately discover that Word
 * has no `max-width`, that Gmail strips `<head>` on some clients, and that
 * `display:inline-block` is a coin toss. Six plugins would arrive at six
 * dialects of those limits, and the suite's mail would look like six products —
 * which is the thing this plugin exists to prevent.
 *
 * The blocks are deliberately few and none of them is a layout primitive.
 * There is no column, no box, no width, no colour. A contributor chooses what
 * to say; {@see Shell} decides what it looks like, which is the only
 * arrangement where changing the house style is one edit rather than six.
 *
 * ### Everything here is plain text, except the tags
 *
 * Nothing is escaped on the way in and nothing may contain markup — Shell
 * escapes at the point it builds markup, so a contributor that pre-escaped
 * would produce a visible `&amp;` in a customer's inbox.
 *
 * The exception, and it is the whole point: `##tag##` is GLPI's notification
 * template syntax, and it passes through untouched. `##ticket.title##` is
 * substituted with the value at send time and `##lang.ticket.title##` with a
 * label translated into the *recipient's* language. Use the `lang` form for
 * every field label you can; a literal is a literal in every language.
 *
 * ### Example
 *
 * ```php
 * Letter::make()
 *     ->eyebrow('##alert.action##')
 *     ->title('##alert.name##')
 *     ->pill('##alert.severity##', Letter::BAD)
 *     ->button('Acknowledge it', '##alert.url##')
 *     ->row('##lang.alert.host##', '##alert.host##', when: 'alert.host')
 *     ->row('##lang.alert.first_seen##', '##alert.first_seen##')
 *     ->panel('##lang.alert.summary##', '##alert.summary##');
 * ```
 */
final class Letter
{
    /** Pill tones. Meanings, not colours — {@see Theme} owns the colours. */
    public const NEUTRAL = 'neutral';
    public const INFO    = 'info';
    public const GOOD    = 'good';
    public const WARN    = 'warn';
    public const BAD     = 'bad';

    private const TONES = [self::NEUTRAL, self::INFO, self::GOOD, self::WARN, self::BAD];

    /** @var array<int,array<mixed>> */
    private array $blocks = [];

    /**
     * Pills and rows group into one element each, so they are collected here
     * until something else is added. A contributor writing three `row()` calls
     * in a line means one table, not three.
     *
     * @var array<int,array<int,string>>
     */
    private array $pills = [];

    /** @var array<int,array<int,string>> */
    private array $rows = [];

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    // --------------------------------------------------------------- prose

    /** The small line above the title. What happened, usually `##x.action##`. */
    public function eyebrow(string $text): self
    {
        return $this->add('eyebrow', $text);
    }

    /** The one line a reader will read if they read nothing else. */
    public function title(string $text): self
    {
        return $this->add('title', $text);
    }

    /** A sentence of body copy under the title. */
    public function lede(string $text): self
    {
        return $this->add('lede', $text);
    }

    /** Smaller, quieter body copy. For caveats and instructions. */
    public function note(string $text): self
    {
        return $this->add('note', $text);
    }

    /** A small-caps heading with the rows or panel that follow it. */
    public function section(string $title): self
    {
        return $this->add('section', $title);
    }

    public function divider(): self
    {
        return $this->add('divider');
    }

    // ------------------------------------------------------------- signals

    /**
     * A status pill.
     *
     * Consecutive calls make one row of pills. Two is usually the right number
     * and four is too many — a row of pills is a glance, and a glance does not
     * survive being asked to compare five things.
     *
     * `$when` makes the pill conditional on a tag having a value, with
     * `$otherwise` drawn when it does not. That is how a status pill goes green
     * once there is a solve date and stays blue before it. Use one tag per
     * pill: {@see \NotificationTemplate::processIf()} matches the closing tag on
     * the field name alone, so the same field used twice in one letter is
     * resolved by document order — which works, and stops working the day
     * somebody reorders the calls.
     */
    public function pill(
        string $label,
        string $tone = self::NEUTRAL,
        ?string $when = null,
        ?string $otherwise = null,
        string $otherwise_tone = self::NEUTRAL
    ): self {
        if (trim($label) === '') {
            return $this;
        }

        $pill = [$label, self::tone($tone)];

        if ($when !== null && trim($when) !== '') {
            $pill[] = self::tag($when);
            $pill[] = $otherwise ?? $label;
            $pill[] = self::tone($otherwise_tone);
        }

        $this->pills[] = $pill;

        return $this;
    }

    /**
     * The one thing you want the reader to do.
     *
     * One per notification. A mail with three buttons has no primary action,
     * and the reader picks by position rather than by meaning.
     */
    public function button(string $label, string $url): self
    {
        return $this->add('button', $label, $url);
    }

    /** A plain link on its own line, for when a button would overstate it. */
    public function link(string $label, string $url): self
    {
        return $this->add('link', $label, $url);
    }

    // ---------------------------------------------------------------- data

    /**
     * A labelled row.
     *
     * Consecutive calls make one table, which on a phone stacks each label
     * above its value.
     *
     * `$when` drops the row when the tag has no value. Use it: a row reading
     * "Category:" with nothing after it looks like a fault rather than like an
     * absence, and there is no way for the reader to tell which it was.
     */
    public function row(string $label, string $value, ?string $when = null): self
    {
        if (trim($label) === '' && trim($value) === '') {
            return $this;
        }

        $row = [$label, $value];

        if ($when !== null && trim($when) !== '') {
            $row[] = self::tag($when);
        }

        $this->rows[] = $row;

        return $this;
    }

    /**
     * @param array<int,array{0:string,1:string,2?:string}> $rows
     */
    public function rows(array $rows): self
    {
        foreach ($rows as $row) {
            $this->row(
                (string) ($row[0] ?? ''),
                (string) ($row[1] ?? ''),
                isset($row[2]) ? (string) $row[2] : null
            );
        }

        return $this;
    }

    /**
     * A quoted block, with an accent rule down its left edge.
     *
     * The one place rich content belongs: `##ticket.content##` and its like are
     * substituted with HTML that somebody typed into an editor. Pass the tag,
     * not your own markup — see the note on escaping in the class comment.
     *
     * `$title` may be empty, for a panel that follows a `section()`.
     */
    public function panel(string $title, string $content): self
    {
        return $this->add('panel', $title, $content);
    }

    // ------------------------------------------------------------ structure

    /**
     * Repeat what the callback describes, once per row of a `##FOREACH##` list.
     *
     * `$list` is the key the notification target fills — `$this->data['tasks']`
     * is `'tasks'`. Read it out of the target's `addDataForTemplate()`, not out
     * of the tag list it registers for the help panel: GLPI's own targets
     * disagree with themselves about this in at least four places, and the
     * assignment is the one that decides what a mail contains.
     *
     * `$limit` keeps only the most recent N. Pass one. A notification is a
     * summary with a link on it, and nobody has ever read the ninetieth entry.
     *
     * @param callable(self):void $body
     */
    public function loop(string $list, ?int $limit, callable $body): self
    {
        $list = preg_replace('/[^A-Za-z0-9_]/', '', $list) ?? '';

        if ($list === '') {
            return $this;
        }

        $inner = self::make();
        $body($inner);

        $blocks = $inner->blocks();

        if ($blocks === []) {
            return $this;
        }

        return $this->add('loop', $list, $limit === null ? null : max(1, $limit), $blocks);
    }

    /**
     * Include what the callback describes only when `$tag` has a value.
     *
     * "Has a value" is GLPI's definition, not PHP's: empty, `'0'` and `&nbsp;`
     * all count as absent. See {@see \NotificationTemplate::processIf()}.
     *
     * @param callable(self):void      $then
     * @param callable(self):void|null $otherwise
     */
    public function when(string $tag, callable $then, ?callable $otherwise = null): self
    {
        return $this->conditional(self::tag($tag), null, $then, $otherwise);
    }

    /**
     * The same, but on an exact match.
     *
     * For the numeric status fields — `##IFticket.storestatus=5##`. Note that a
     * ladder of these against one field is resolved by document order rather
     * than by value; two branches is the shape that stays correct.
     *
     * @param callable(self):void      $then
     * @param callable(self):void|null $otherwise
     */
    public function whenEquals(string $tag, string $value, callable $then, ?callable $otherwise = null): self
    {
        return $this->conditional(self::tag($tag), $value, $then, $otherwise);
    }

    // ------------------------------------------------------------- the tree

    /**
     * What {@see Shell} renders.
     *
     * @return array<int,array<mixed>>
     */
    public function blocks(): array
    {
        $this->flush();

        return $this->blocks;
    }

    public function isEmpty(): bool
    {
        return $this->blocks() === [];
    }

    // ================================================================ inside

    /**
     * @param callable(self):void      $then
     * @param callable(self):void|null $otherwise
     */
    private function conditional(string $tag, ?string $value, callable $then, ?callable $otherwise): self
    {
        if ($tag === '') {
            return $this;
        }

        $inner = self::make();
        $then($inner);

        $else = [];

        if ($otherwise !== null) {
            $branch = self::make();
            $otherwise($branch);
            $else = $branch->blocks();
        }

        $blocks = $inner->blocks();

        if ($blocks === [] && $else === []) {
            return $this;
        }

        return $value === null
            ? $this->add('if', $tag, $blocks, $else)
            : $this->add('ifeq', $tag, $value, $blocks, $else);
    }

    private function add(string $kind, mixed ...$arguments): self
    {
        $this->flush();

        $this->blocks[] = [$kind, ...$arguments];

        return $this;
    }

    /** Turn the collected pills and rows into their blocks. */
    private function flush(): void
    {
        if ($this->pills !== []) {
            $this->blocks[] = ['pills', $this->pills];
            $this->pills    = [];
        }

        if ($this->rows !== []) {
            $this->blocks[] = ['meta', $this->rows];
            $this->rows     = [];
        }
    }

    private static function tone(string $tone): string
    {
        // An unknown tone is neutral rather than an error. A contributor who
        // invents `'critical'` gets a grey pill and a mail that goes out;
        // throwing would lose the notification over a colour.
        return in_array($tone, self::TONES, true) ? $tone : self::NEUTRAL;
    }

    /**
     * A tag name, with the hashes taken off if they were left on.
     *
     * `when('##ticket.category##')` is what everybody writes the first time,
     * and GLPI's syntax needs the bare field — `##IFticket.category##`. Left
     * alone it produces `##IF##ticket.category####`, which matches nothing,
     * silently drops the block, and is invisible until somebody notices a
     * section that never appears.
     */
    private static function tag(string $tag): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '', trim($tag, '#')) ?? '';
    }
}
