<?php

declare(strict_types=1);

/*
 | ADR 0012 records a contrast table, and the whole point of computing those
 | numbers rather than eyeballing them is that a later palette change becomes a
 | regression rather than a restyle. A table in a document cannot enforce that.
 | These tests read the real Sass variables and do the arithmetic.
 |
 | This exists because one pair was already failing when the table was first
 | computed: $sc-line-strong is the input border, its comment read "input
 | borders, which must be findable", and it measured 1.57:1 against a required
 | 3.0:1 — a hairline that looked tasteful and disappeared for exactly the
 | reduced-contrast-sensitivity audience this project names.
 */

/** The stylesheet, read once. */
function stylesheet(): string
{
    return file_get_contents(resource_path('css/app.scss'));
}

/**
 * A palette variable's hex value, straight out of the Sass source.
 *
 * Reading the source rather than a duplicated list here is the point: a test
 * that carries its own copy of the colours passes happily after someone edits
 * the stylesheet.
 */
function palette(string $name): string
{
    preg_match('/\$'.preg_quote($name, '/').':\s*(#[0-9a-fA-F]{6})\s*;/', stylesheet(), $match);

    expect($match)->not->toBeEmpty("\${$name} not found in app.scss");

    return $match[1];
}

/** Relative luminance, WCAG 2.1 definition. */
function luminance(string $hex): float
{
    $channels = array_map(
        static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        },
        [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ]
    );

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrast(string $foreground, string $background): float
{
    $a = luminance($foreground);
    $b = luminance($background);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

it('meets AA on every text pairing in the palette', function (string $ink, string $ground): void {
    // 4.5:1 is WCAG 2.1 SC 1.4.3 for body text. $min-contrast-ratio in the
    // stylesheet is left at Bootstrap's 4.5 for the same reason — lowering it
    // would let Bootstrap silently pick an unreadable foreground for a filled
    // badge.
    expect(contrast(palette($ink), palette($ground)))
        ->toBeGreaterThanOrEqual(4.5, "\${$ink} on \${$ground}");
})->with([
    ['sc-ink', 'sc-surface'],
    ['sc-ink', 'sc-paper'],
    ['sc-ink', 'sc-paper-tint'],
    ['sc-ink-muted', 'sc-surface'],
    ['sc-ink-muted', 'sc-paper'],
    ['sc-ink-muted', 'sc-paper-tint'],
    ['sc-brand', 'sc-surface'],
    ['sc-danger', 'sc-surface'],
    ['sc-warning', 'sc-surface'],
]);

it('keeps the "What to do" box readable in every band tint', function (string $ink, string $wash): void {
    /*
     | The action box inside a verdict is tinted in the band's own colour and
     | carries two kinds of text: body ink, and its heading and arrows in the
     | risk colour itself. The risk colour on its own tint is the pair that
     | could quietly fail -- a darker tint makes the box look more urgent and
     | makes its heading harder to read at the same time.
     */
    expect(contrast(palette($ink), palette($wash)))
        ->toBeGreaterThanOrEqual(4.5, "\${$ink} on \${$wash}");
})->with([
    ['sc-ink', 'sc-wash-danger'],
    ['sc-danger', 'sc-wash-danger'],
    ['sc-ink', 'sc-wash-warning'],
    ['sc-warning', 'sc-wash-warning'],
    ['sc-ink', 'sc-wash-success'],
    ['sc-success', 'sc-wash-success'],
    // The privacy panel's icons sit on the success tint too.
    ['sc-brand', 'sc-wash-success'],
]);

it('keeps white legible on every filled risk badge', function (string $background): void {
    // The risk colours are used as solid badge backgrounds, not only as
    // borders, so each has to carry white text. A badge nobody can read is a
    // verdict nobody can read.
    expect(contrast('#ffffff', palette($background)))
        ->toBeGreaterThanOrEqual(4.5, "white on \${$background}");
})->with(['sc-danger', 'sc-warning', 'sc-success', 'sc-brand']);

it('gives the input border a findable boundary', function (string $ground): void {
    // SC 1.4.11 Non-text Contrast: 3:1 for the visual boundary that identifies
    // a user interface component. The 2px border helps a sighted user with good
    // vision and does not satisfy the criterion on its own.
    //
    // Checked against every surface an input could sit on rather than only the
    // one it sits on today, so moving a form into a tinted panel cannot quietly
    // reintroduce the defect.
    expect(contrast(palette('sc-line-strong'), palette($ground)))
        ->toBeGreaterThanOrEqual(3.0, "\$sc-line-strong on \${$ground}");
})->with(['sc-surface', 'sc-paper', 'sc-paper-tint']);

it('meets AA on the masthead, where the palette is inverted', function (
    string $ink,
    string $ground,
): void {
    /*
     | The dark band was the one region the original table never covered. Every
     | pair above puts ink on paper; these put light text on deep green, and a
     | tone that looks fine on cream can fail badly when the relationship is
     | reversed.
     |
     | The mockup for this design used #a9c4b9 as the muted tone. It measures
     | 5.35:1 -- passing, but with little room, and it would have been adopted
     | on the strength of looking right rather than measuring right. #b7cfc5
     | reaches 6.05:1 and was chosen by computing both.
     */
    expect(contrast(palette($ink), palette($ground)))
        ->toBeGreaterThanOrEqual(4.5, "\${$ink} on \${$ground}");
})->with([
    ['sc-on-deep', 'sc-deep-2'],
    ['sc-on-deep-muted', 'sc-deep-2'],
    ['sc-on-deep', 'sc-deep'],
    ['sc-on-deep-muted', 'sc-deep'],
]);

it('keeps white legible on both dark surfaces', function (string $ground): void {
    // The hero, the brand mark and the footer's lead sentence are all white on
    // one of these two. They carry the strongest claims on the page.
    expect(contrast('#ffffff', palette($ground)))
        ->toBeGreaterThanOrEqual(4.5, "white on \${$ground}");
})->with(['sc-deep', 'sc-deep-2']);

it('keeps the language pill readable when it inverts', function (): void {
    // The selected language sits as deep green on a near-white pill, which is
    // the palette used the other way round from everywhere else on the band.
    expect(contrast(palette('sc-deep-2'), '#ffffff'))->toBeGreaterThanOrEqual(4.5);
});

it('keeps the focus outline visible against the page', function (): void {
    // :focus-visible draws a 3px outline in $sc-brand. For a keyboard or
    // switch-access user this is the only thing telling them where they are.
    expect(contrast(palette('sc-brand'), palette('sc-surface')))
        ->toBeGreaterThanOrEqual(3.0);
});
