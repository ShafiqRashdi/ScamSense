<?php

declare(strict_types=1);

use App\Models\Submission;
use Illuminate\Support\Facades\Http;

/**
 * The verification payloads from the security-review skill, as tests.
 *
 * The skill requires each of these to be exercised and the results tabulated
 * for Chapter 4. Having them here rather than in a one-off script means a
 * regression is caught by the suite rather than by the next manual pass.
 */
function fakePrediction(float $probability = 0.9): void
{
    Http::fake([
        '*/predict/*' => Http::response([
            'request_id' => 't',
            'label' => 'scam',
            'confidence' => $probability,
            'indicators' => [],
            'model_version' => 'rf-url-v2.0.0-pathnorm',
            'inference_ms' => 5,
        ]),
    ]);
}

it('renders a script payload escaped, never executed', function (): void {
    fakePrediction();

    $html = $this->followingRedirects()->post('/verify', ['type' => 'message', 'content' => '<script>alert(1)</script>'])
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('renders an image onerror payload escaped', function (): void {
    fakePrediction();

    $html = $this->followingRedirects()->post('/verify', ['type' => 'message', 'content' => '<img src=x onerror=alert(1)>'])
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<img src=x');
});

it('rejects a javascript scheme at validation', function (): void {
    $this->post('/verify', ['type' => 'url', 'content' => 'javascript:alert(document.domain)'])
        ->assertSessionHasErrors('content');
});

it('accepts an internal address as text and never fetches it', function (string $url): void {
    /*
     * The SSRF cases. These must be analysed as strings — a request to either
     * would be a server-side request forgery, and the metadata endpoint in
     * particular is the classic cloud credential theft primitive. This matters
     * more now the deployment target is a public cloud host.
     */
    fakePrediction();

    $this->followingRedirects()->post('/verify', ['type' => 'url', 'content' => $url])->assertOk();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '169.254.169.254')
        || str_contains($request->url(), '127.0.0.1:5001/api/v1/predict/url?')
    );
})->with([
    'ml service' => 'http://127.0.0.1:5001/api/v1/predict',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'link local' => 'http://169.254.169.254/',
]);

it('stores a SQL injection string as literal text', function (): void {
    fakePrediction();

    $this->followingRedirects()->post('/verify', ['type' => 'message', 'content' => "' OR '1'='1"])->assertOk();

    expect(Submission::first()->content_redacted)->toBe("' OR '1'='1");
});

it('rejects a body over the length cap', function (): void {
    $this->post('/verify', ['type' => 'message', 'content' => str_repeat('A', 6000)])
        ->assertSessionHasErrors('content');
});

it('throttles the eleventh verification request in a minute', function (): void {
    fakePrediction();

    $throttled = false;
    for ($i = 0; $i < 15; $i++) {
        if ($this->post('/verify', ['type' => 'message', 'content' => "probe {$i}"])->status() === 429) {
            $throttled = true;
            break;
        }
    }

    expect($throttled)->toBeTrue();
});

it('never renders a submitted url as a clickable link', function (): void {
    fakePrediction();

    $html = $this->followingRedirects()->post('/verify', ['type' => 'url', 'content' => 'http://evil-probe.xyz/pay'])
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('href="http://evil-probe.xyz')
        ->and($html)->toContain('hxxp://evil-probe[.]xyz/pay');
});
