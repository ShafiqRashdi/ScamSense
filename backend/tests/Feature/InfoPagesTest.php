<?php

declare(strict_types=1);

/*
 | The About and How-to pages. Static reading pages, so what is worth pinning
 | is what they must never say, and that they teach the labels the site
 | actually shows.
 */

it('serves both pages in both languages', function (string $path, string $en, string $ms): void {
    $this->get($path)->assertOk()->assertSee($en);
    $this->withSession(['locale' => 'ms'])->get($path)->assertOk()->assertSee($ms);
})->with([
    'about' => ['/about', 'About ScamSense', 'Tentang ScamSense'],
    'how to' => ['/how-to-use', 'How to use ScamSense', 'Cara guna ScamSense'],
]);

it('links both pages from the band on every public page', function (): void {
    $this->get('/')
        ->assertSee('href="'.route('about').'"', escape: false)
        ->assertSee('href="'.route('howto').'"', escape: false);

    $this->withSession(['locale' => 'ms'])->get('/')
        ->assertSee('Tentang')
        ->assertSee('Cara guna');
});

it('marks the current page in the nav and no other', function (): void {
    $html = $this->get('/about')->getContent();

    expect(substr_count($html, 'aria-current="page"'))->toBe(1);
    expect($html)->toMatch('/href="'.preg_quote(route('about'), '/').'"[^>]*\s+aria-current="page"/');

    // The landing page is not one of the two, so nothing is current there.
    expect($this->get('/')->getContent())->not->toContain('aria-current="page"');
});

it('quotes no accuracy percentage on either page', function (string $path): void {
    // "98% accurate" is the misquote docs/user-manual.md warns against, and an
    // older reader takes it as a promise. The F1 figures belong in the report,
    // beside their caveats.
    foreach (['en', 'ms'] as $locale) {
        $text = strip_tags($this->withSession(['locale' => $locale])->get($path)->getContent());

        expect($text)->not->toMatch('/\d\s*%/');
    }
})->with(['/about', '/how-to-use']);

it('never calls the verified-address list AI', function (): void {
    // The registry is a safety mitigation, never model accuracy (AGENTS.md
    // Phase 5). The page says so in as many words.
    $this->get('/about')->assertSee('This part is not AI.');
    $this->withSession(['locale' => 'ms'])->get('/about')->assertSee('Bahagian ini bukan AI');
});

it('teaches the labels the site actually shows', function (): void {
    // Built from the live strings, so renaming a button or an answer cannot
    // leave this page describing one that no longer exists.
    $this->withSession(['locale' => 'ms'])->get('/how-to-use')
        ->assertSee('Pilih "Mesej" atau "Pautan Link".')
        ->assertSee('Tekan "Semak sekarang".')
        ->assertSee(__('verdict.high.label', [], 'ms'))
        ->assertSee(__('verdict.medium.label', [], 'ms'))
        ->assertSee(__('verdict.low.label', [], 'ms'))
        ->assertSee(__('verdict.unknown.label', [], 'ms'))
        ->assertSee(__('verdict.verified.label', [], 'ms'))
        ->assertSee(__('verdict.verified.scope_heading', [], 'ms'))
        ->assertSee(__('feedback.heading', [], 'ms'));
});

it('shows the example addresses as text, never as links', function (): void {
    $html = $this->get('/how-to-use')->getContent();

    expect($html)->toContain('secure-verify.xyz');
    expect($html)->not->toMatch('/<a\s[^>]*href="[^"]*(maybank2u|secure-verify|semakmule)/i');
});

it('uses no emoji on either page', function (string $path): void {
    foreach (['en', 'ms'] as $locale) {
        $html = $this->withSession(['locale' => $locale])->get($path)->getContent();

        expect($html)->not->toMatch('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u');
    }
})->with(['/about', '/how-to-use']);
