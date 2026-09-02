<?php

declare(strict_types=1);

use App\Models\Indicator;
use App\Models\Submission;
use App\Models\Verdict;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 | The ML service is faked throughout. These tests are about what the user
 | sees, and a real prediction would make them depend on the model.
 */
function fakeMl(float $probability, string $label = 'scam'): void
{
    Http::fake([
        '*/predict/*' => Http::response([
            'request_id' => 'test',
            'label' => $label,
            'confidence' => $label === 'scam' ? $probability : 1.0 - $probability,
            'indicators' => [
                ['code' => 'suspicious_tld', 'weight' => 0.06, 'evidence' => '.xyz', 'source' => 'model'],
            ],
            'model_version' => 'rf-url-v1.2.0-no-https',
            'inference_ms' => 12,
        ]),
    ]);
}

function submit(string $content, string $type = 'url')
{
    return test()->post('/verify', ['type' => $type, 'content' => $content]);
}

/*
|--------------------------------------------------------------------------
| The form
|--------------------------------------------------------------------------
*/

it('renders the verification form', function (): void {
    $this->get('/')->assertOk()->assertSee('Is this a scam?');
});

it('shows the privacy notice on the form itself', function (): void {
    // Required by the Phase 6 brief: the user must know what is stored
    // before they paste, not afterwards.
    $this->get('/')
        ->assertSee('What happens to what you paste')
        ->assertSee('We never open the link you paste', escape: false);
});

/*
|--------------------------------------------------------------------------
| Escaping — nothing user-supplied is ever rendered as HTML
|--------------------------------------------------------------------------
*/

it('escapes HTML in a submitted message', function (): void {
    fakeMl(0.10, 'legitimate');

    $response = submit('<script>alert("xss")</script> Tahniah anda menang', 'message');

    $response->assertOk()
        ->assertDontSee('<script>alert', escape: false)
        ->assertSee('&lt;script&gt;', escape: false);
});

it('escapes an event-handler payload', function (): void {
    /*
     * The assertion is about the tag, not the attribute text. `onerror=alert(1)`
     * appearing inside an escaped `&lt;img ...&gt;` is inert — it is displayed
     * to the user as characters. What must never appear is a real `<img` tag
     * that the browser would parse and fire the handler from.
     */
    fakeMl(0.10, 'legitimate');

    $html = submit('<img src=x onerror=alert(1)>', 'message')
        ->assertOk()
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', escape: false)
        ->getContent();

    expect($html)->not->toContain('<img src=x');
});

it('rejects a javascript scheme url', function (): void {
    submit('javascript:alert(1)')->assertSessionHasErrors('content');
});

it('rejects content over the length limit', function (): void {
    submit(str_repeat('a', 6000), 'message')->assertSessionHasErrors('content');
});

/*
|--------------------------------------------------------------------------
| Defanging — the link must not be openable by accident
|--------------------------------------------------------------------------
*/

it('defangs the submitted url so it cannot be clicked', function (): void {
    fakeMl(0.93);

    $response = submit('http://lhdn-refund.site/semak/akaun');

    $response->assertOk()
        ->assertSee('hxxp://lhdn-refund[.]site/semak/akaun')
        ->assertDontSee('href="http://lhdn-refund.site', escape: false);
});

it('defangs urls embedded inside a message', function (): void {
    fakeMl(0.93, 'legitimate');

    submit('Klik https://prize-my.xyz/claim sekarang', 'message')
        ->assertOk()
        ->assertSee('hxxps://prize-my[.]xyz/claim');
});

it('never emits the submitted url inside an href', function (): void {
    fakeMl(0.93);

    $html = submit('http://evil-domain-xyz.top/login')->getContent();

    expect($html)->not->toContain('href="http://evil-domain-xyz.top')
        ->and($html)->not->toContain("href='http://evil-domain-xyz.top");
});

/*
|--------------------------------------------------------------------------
| Bands — risk is never conveyed by colour alone
|--------------------------------------------------------------------------
*/

it('shows a text label and an icon alongside the colour', function (float $probability, string $label): void {
    fakeMl($probability, $probability < 0.20 ? 'legitimate' : 'scam');

    $response = submit('https://unlisted-example-site.test/path');

    $response->assertOk()
        ->assertSee($label)
        // The icon is inline SVG, not an icon font: if a webfont fails to load
        // the glyph vanishes and the verdict would be carried by colour alone.
        ->assertSee('<svg', escape: false);
})->with([
    'high' => [0.93, 'Likely a scam'],
    'medium' => [0.45, 'Not certain'],
    'low' => [0.05, 'No warning signs'],
]);

it('shows the medium band as uncertainty, not as an accusation', function (): void {
    // Seven of seventeen genuine Malaysian sites landed in this band, so it is
    // the state most users will meet on a legitimate link.
    fakeMl(0.45);

    submit('https://unlisted-example-site.test/billing')
        ->assertOk()
        ->assertSee('We are not certain about this one')
        ->assertSee('plenty of genuine websites look this way too')
        ->assertSee('Contact the company yourself');
});

/*
|--------------------------------------------------------------------------
| The verified-address panel
|--------------------------------------------------------------------------
*/

it('names the institution rather than saying only that the address is verified', function (): void {
    fakeMl(0.93);

    submit('https://www.maybank2u.com.my/home/m2u/common/login.do')
        ->assertOk()
        ->assertSee('This is the real Maybank website')
        ->assertSee('bank licensed by Bank Negara Malaysia');
});

it('always shows the scoping paragraph, never collapsed', function (): void {
    /*
     * The panel is the most reassuring output the platform produces and the
     * one most at risk of being read as "this page is safe". The scoping text
     * is what prevents that, so it must not sit behind a <details> toggle.
     */
    fakeMl(0.93);

    $html = submit('https://www.maybank2u.com.my/home/login.do')->getContent();

    expect($html)->toContain('We checked the address, not the page');

    // The teaching section may be disclosed progressively; the scope may not.
    $afterDetails = substr($html, strpos($html, '<details'));
    $detailsEnd = strpos($afterDetails, '</details>');
    $insideDetails = substr($afterDetails, 0, $detailsEnd);

    expect($insideDetails)->not->toContain('We checked the address, not the page');
});

it('discloses the teaching section progressively', function (): void {
    fakeMl(0.93);

    submit('https://www.maybank2u.com.my/home/login.do')
        ->assertOk()
        ->assertSee('<details', escape: false)
        ->assertSee('How to check this yourself');
});

it('explains the suffix rule for a government address instead of naming a company', function (): void {
    fakeMl(0.55);

    submit('https://www.hasil.gov.my/en/')
        ->assertOk()
        ->assertSee('Malaysian government agencies')
        ->assertSee('Scammers cannot');
});

it('does not show the verified panel for a lookalike', function (): void {
    fakeMl(0.93);

    submit('https://maybank2u.com.my.secure-verify.xyz/login')
        ->assertOk()
        ->assertDontSee('This is the real')
        ->assertSee('Likely a scam');
});

/*
|--------------------------------------------------------------------------
| Degradation
|--------------------------------------------------------------------------
*/

it('shows a clearly labelled partial verdict when the ML service is down', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    submit('https://unlisted-example-site.test/x')
        ->assertOk()
        ->assertSee('Could not check')
        ->assertSee('this result is incomplete');
});

it('never returns a 500 when the ML service fails', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    submit('https://unlisted-example-site.test/x')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Persistence
|--------------------------------------------------------------------------
*/

it('redacts a Malaysian phone number before persisting', function (): void {
    fakeMl(0.10, 'legitimate');

    submit('Hubungi saya di 012-345 6789 sekarang', 'message')->assertOk();

    expect(Submission::first()->content_redacted)
        ->not->toContain('012-345 6789')
        ->toContain('DILINDUNGI');
});

it('stores the verdict with its model version and indicators', function (): void {
    fakeMl(0.93);

    submit('http://lhdn-refund.site/claim')->assertOk();

    $verdict = Verdict::first();

    expect($verdict->model_version)->toBe('rf-url-v1.2.0-no-https')
        ->and(Indicator::count())->toBeGreaterThan(0);
});

it('does not mark a verdict partial merely because no API keys are configured', function (): void {
    /*
     * This project runs without registered threat-intel keys by default. If
     * "not configured" counted as a missing signal, EVERY verdict would carry
     * the partial notice — and a warning shown on every result carries no
     * information and trains users to ignore it, which is worse than not
     * having one at all.
     */
    config()->set('services.safe_browsing.key', '');
    config()->set('services.virustotal.key', '');
    fakeMl(0.93);

    submit('http://lhdn-refund.site/claim')->assertOk()->assertDontSee('this result is incomplete');

    expect(Verdict::first()->is_partial)->toBeFalse();
});

it('records the gate as a registry indicator when it fires', function (): void {
    fakeMl(0.93);

    submit('https://www.maybank2u.com.my/home/login.do')->assertOk();

    expect(Indicator::where('code', 'verified_institutional_domain')->first()->source->value)
        ->toBe('registry');
});

it('hashes the submitter IP rather than storing it', function (): void {
    fakeMl(0.10, 'legitimate');

    submit('https://unlisted-example-site.test/x')->assertOk();

    $stored = Submission::first()->ip_hash;

    expect($stored)->toHaveLength(64)
        ->and($stored)->not->toContain('127.0.0.1');
});

/*
|--------------------------------------------------------------------------
| Bilingual
|--------------------------------------------------------------------------
*/

it('renders the verdict in Bahasa Malaysia when selected', function (): void {
    fakeMl(0.45);

    $this->withSession(['locale' => 'ms']);

    submit('https://unlisted-example-site.test/billing')
        ->assertOk()
        ->assertSee('Kami tidak pasti tentang perkara ini')
        ->assertSee('kelihatan pelik');
});

it('uses alamat rather than domain in Malay copy', function (): void {
    // The audience includes older adults; `domain` is a technical English term.
    fakeMl(0.93);

    $this->withSession(['locale' => 'ms']);

    $html = submit('https://www.maybank2u.com.my/home/login.do')->getContent();

    expect($html)->toContain('alamat rasmi')
        ->and($html)->toContain('Kami menyemak alamat, bukan halaman');
});

it('has a translation for every indicator code in both languages', function (): void {
    // A code missing from a language file leaves a user staring at a raw
    // identifier like "sensitive_keyword_path".
    $en = require lang_path('en/indicators.php');
    $ms = require lang_path('ms/indicators.php');

    expect(array_keys($ms))->toEqualCanonicalizing(array_keys($en));

    foreach ($en as $key => $value) {
        expect($value)->not->toBe('')
            ->and($ms[$key])->not->toBe('');
    }
});

it('translates every public-facing string into Malay', function (): void {
    /*
     | The regression this exists for: lang/ms/*.php covered the result page, so
     | the Malay verdict read fluently, but there was no lang/ms.json at all.
     | Every __('An English sentence') call fell through untranslated, which
     | meant a Malay-preferring user met an entirely English submission form and
     | an English footer — the footer being the line that tells them to call
     | their bank.
     */
    $json = json_decode(file_get_contents(lang_path('ms.json')), true, flags: JSON_THROW_ON_ERROR);

    $views = [
        resource_path('views/verify/index.blade.php'),
        resource_path('views/verify/result.blade.php'),
        resource_path('views/layouts/public.blade.php'),
    ];

    $missing = [];

    foreach ($views as $view) {
        // String-key calls only. Dotted keys resolve against lang/ms/*.php.
        preg_match_all("/__\('([A-Z][^']*)'/", file_get_contents($view), $matches);

        foreach ($matches[1] as $string) {
            if (! str_contains($string, ' ') && str_contains($string, '.')) {
                continue;
            }
            if (! array_key_exists($string, $json)) {
                $missing[] = $string;
            }
        }
    }

    expect(array_unique($missing))->toBe([]);
});

it('renders the submission form in Malay', function (): void {
    $this->withSession(['locale' => 'ms']);

    $this->get('/')
        ->assertOk()
        ->assertSee('Adakah ini penipuan?')
        ->assertSee('Semak sekarang')
        ->assertDontSee('Is this a scam?');
});

it('translates the footer safety line, which is the most consequential on the site', function (): void {
    $this->withSession(['locale' => 'ms']);

    $this->get('/')
        ->assertSee('segera hubungi bank anda dan buat laporan polis')
        ->assertDontSee('contact your bank immediately');
});

/*
|--------------------------------------------------------------------------
| Copy matches what was actually submitted
|--------------------------------------------------------------------------
*/

it('says message rather than link when a message was submitted', function (): void {
    // Telling someone who pasted an SMS that "this link has several features we
    // see in scam links" announces that the system did not read what they sent.
    fakeMl(0.93);

    $html = submit('Tahniah! Anda memenangi RM5,000. Klik sekarang.', 'message')->getContent();

    expect($html)->toContain('This message has several features')
        ->and($html)->not->toContain('This link has several features');
});

it('has both submission types for every verdict band in both languages', function (): void {
    // A band with only a `url` variant renders the raw key when a message
    // lands in it, so this must fail loudly rather than at a demo.
    foreach (['en', 'ms'] as $locale) {
        $verdict = require lang_path("{$locale}/verdict.php");

        foreach (['high', 'medium', 'low', 'unknown'] as $band) {
            foreach (['body', 'action'] as $key) {
                expect($verdict[$band][$key])->toHaveKeys(['url', 'message'], "{$locale}.{$band}.{$key}");
            }
        }

        expect($verdict['indicators_none'])->toHaveKeys(['url', 'message'], $locale);
    }
});

/*
|--------------------------------------------------------------------------
| Result page structure
|--------------------------------------------------------------------------
*/

it('puts the verified panel above the verdict card', function (): void {
    /*
     | The verified panel is the strongest reassurance the platform produces and
     | the answer the user came for. Rendered second it sat behind a generic
     | "this does not guarantee the page is safe" hedge, and at 375px with
     | enlarged text its heading fell below the fold.
     */
    fakeMl(0.05, 'legitimate');

    $html = submit('https://www.maybank2u.com.my/home/m2u/common/login.do')->getContent();

    $verified = strpos($html, 'This is the real');
    $verdict = strpos($html, 'We did not find warning signs');

    expect($verified)->not->toBeFalse()
        ->and($verdict)->not->toBeFalse()
        ->and($verified)->toBeLessThan($verdict);
});

it('keeps exactly one h1 when the verified panel is shown', function (): void {
    // Two h1s on one page leaves a screen-reader user with no single answer to
    // "what does this page say".
    fakeMl(0.05, 'legitimate');

    $html = submit('https://www.maybank2u.com.my/home/m2u/common/login.do')->getContent();

    expect(substr_count($html, '<h1'))->toBe(1);
});

it('keeps the verified scope paragraph visible rather than inside the toggle', function (): void {
    // Collapsing it would let the panel be read as "this page is safe", which
    // is the one claim ScamSense must never make.
    fakeMl(0.05, 'legitimate');

    $html = submit('https://www.maybank2u.com.my/home/m2u/common/login.do')->getContent();

    $scope = strpos($html, 'We checked the address, not the page');
    $details = strpos($html, '<details');

    expect($scope)->toBeLessThan($details);
});

it('explains that the defanging was ours, not part of the address', function (): void {
    // Without this a reader can conclude the address itself is malformed and
    // take the brackets as the suspicious finding.
    fakeMl(0.45);

    submit('https://unlisted-example-site.test/billing')
        ->assertSee('That was us — the real address does not have them.', escape: false);
});

it('says so plainly when no indicator fired', function (): void {
    /*
     | The indicators card used to be hidden entirely when the list was empty,
     | which is common in the medium band — the model reaches "not certain" on
     | continuous features that are deliberately never surfaced to a reader
     | (see ml-service/app/indicators.py). That left "parts of this look odd"
     | with nothing named, reading as a list that failed to load.
     */
    Http::fake([
        '*/predict/*' => Http::response([
            'request_id' => 'test',
            'label' => 'scam',
            'confidence' => 0.45,
            'indicators' => [],
            'model_version' => 'rf-url-v2.0.0-pathnorm',
            'inference_ms' => 9,
        ]),
    ]);

    submit('https://unlisted-example-site.test/billing')
        ->assertOk()
        ->assertSee('What we noticed')
        ->assertSee('Nothing specific stood out');
});
