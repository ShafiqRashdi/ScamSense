<?php

declare(strict_types=1);

use App\DataTransferObjects\MlPrediction;
use App\DataTransferObjects\ThreatIntelResult;
use App\Enums\IndicatorSource;
use App\Enums\RiskLevel;
use App\Enums\SubmissionType;
use App\Enums\VerdictLabel;
use App\Services\Detection\DetectionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function prediction(float $probability, string $label = 'scam'): MlPrediction
{
    // The service reports confidence FOR the label it returned.
    $confidence = $label === 'scam' ? $probability : 1.0 - $probability;

    return MlPrediction::fromResponse([
        'label' => $label,
        'confidence' => $confidence,
        'indicators' => [
            ['code' => 'suspicious_tld', 'weight' => 0.06, 'evidence' => '.xyz', 'source' => 'model'],
        ],
        'model_version' => 'rf-url-v1.2.0-no-https',
        'inference_ms' => 12,
    ]);
}

function detector(): DetectionService
{
    return app(DetectionService::class);
}

/*
|--------------------------------------------------------------------------
| Banding — the measured distribution in docs/RESULTS.md
|--------------------------------------------------------------------------
*/

it('bands the model probability', function (float $probability, RiskLevel $expected): void {
    $verdict = detector()->fuse(SubmissionType::Url, 'https://unlisted.example/x', prediction($probability));

    expect($verdict->riskLevel)->toBe($expected);
})->with([
    'clearly safe' => [0.05, RiskLevel::Low],
    'just below medium' => [0.19, RiskLevel::Low],
    'lower medium' => [0.29, RiskLevel::Medium],
    'upper medium' => [0.57, RiskLevel::Medium],
    'high' => [0.93, RiskLevel::High],
]);

it('labels a low band as legitimate and anything above as scam', function (): void {
    expect(detector()->fuse(SubmissionType::Url, 'https://a.example', prediction(0.05))->label)
        ->toBe(VerdictLabel::Legitimate)
        ->and(detector()->fuse(SubmissionType::Url, 'https://a.example', prediction(0.40))->label)
        ->toBe(VerdictLabel::Scam);
});

/*
|--------------------------------------------------------------------------
| ADR 0007 decision 1 — threat intel is asymmetric
|--------------------------------------------------------------------------
*/

it('escalates to high on a threat-intel hit', function (): void {
    $verdict = detector()->fuse(
        SubmissionType::Url,
        'https://unlisted.example/x',
        prediction(0.10),
        [ThreatIntelResult::hit('google_safe_browsing', [], 'SOCIAL_ENGINEERING')],
    );

    expect($verdict->riskLevel)->toBe(RiskLevel::High)
        ->and($verdict->label)->toBe(VerdictLabel::Scam);
});

it('does NOT let a clean threat-intel result lower a high model score', function (): void {
    /*
     * The core decision in ADR 0007. A clean lookup is absence of evidence —
     * the default state of every phishing domain registered in the last few
     * hours. If it could de-escalate, it would do so most reliably on exactly
     * the fresh domains where the lexical model is the only defence.
     */
    $verdict = detector()->fuse(
        SubmissionType::Url,
        'https://brand-new-phishing.xyz/login',
        prediction(0.93),
        [
            ThreatIntelResult::clean('google_safe_browsing'),
            ThreatIntelResult::clean('virustotal'),
        ],
    );

    expect($verdict->riskLevel)->toBe(RiskLevel::High)
        ->and($verdict->label)->toBe(VerdictLabel::Scam);
});

it('does not treat an unreachable provider as a clean result', function (): void {
    $verdict = detector()->fuse(
        SubmissionType::Url,
        'https://unlisted.example/login',
        prediction(0.93),
        [ThreatIntelResult::unknown('virustotal', 'rate_limited')],
    );

    expect($verdict->riskLevel)->toBe(RiskLevel::High)
        ->and($verdict->isPartial)->toBeTrue()
        ->and($verdict->partialReason)->toContain('virustotal');
});

/*
|--------------------------------------------------------------------------
| ADR 0007 decisions 2 and 4 — the verified-domain gate
|--------------------------------------------------------------------------
*/

it('caps a verified institutional domain at low risk', function (): void {
    // The Maybank login case: the model scores 0.93 and the gate must prevent
    // ScamSense telling a user their own bank is dangerous.
    $verdict = detector()->fuse(
        SubmissionType::Url,
        'https://www.maybank2u.com.my/home/m2u/common/login.do',
        prediction(0.93),
    );

    expect($verdict->riskLevel)->toBe(RiskLevel::Low)
        ->and($verdict->label)->toBe(VerdictLabel::Legitimate)
        ->and($verdict->gatedByVerifiedDomain)->toBeTrue();
});

it('records the gate as a registry indicator, never as a model attribution', function (): void {
    // ADR 0007 decision 3: the gate is a safety mitigation and must stay
    // distinguishable from model output in any later analysis.
    $verdict = detector()->fuse(
        SubmissionType::Url,
        'https://www.hasil.gov.my/en/',
        prediction(0.55),
    );

    $gate = collect($verdict->indicators)
        ->firstWhere('code', 'verified_institutional_domain');

    expect($gate)->not->toBeNull()
        ->and($gate->source)->toBe(IndicatorSource::Registry)
        ->and($gate->source->isModelAttribution())->toBeFalse()
        ->and($gate->evidence)->toBe('hasil.gov.my');
});

it('preserves the raw model probability when the gate fires', function (): void {
    // The gate changes the band, never the score. Nothing downstream may end
    // up measuring a doctored number.
    $verdict = detector()->fuse(
        SubmissionType::Url,
        'https://www.maybank2u.com.my/home/login.do',
        prediction(0.93),
    );

    expect($verdict->modelProbability)->toBe(0.93)
        ->and($verdict->riskLevel)->toBe(RiskLevel::Low);
});

it('does not gate a lookalike domain', function (string $url): void {
    $verdict = detector()->fuse(SubmissionType::Url, $url, prediction(0.93));

    expect($verdict->riskLevel)->toBe(RiskLevel::High)
        ->and($verdict->gatedByVerifiedDomain)->toBeFalse();
})->with([
    'brand as subdomain' => 'https://maybank2u.com.my.secure-verify.xyz/login',
    'hyphenated' => 'https://maybank2u-verify.xyz/login',
    'gov lookalike' => 'http://lhdn-refund.site/claim',
]);

it('lets a threat-intel hit override the verified-domain gate', function (): void {
    /*
     * Verified domains do get compromised — open redirects, subdomain
     * takeovers, hosted content. Positive evidence of active malice outranks a
     * registry fact about who owns the domain.
     */
    $verdict = detector()->fuse(
        SubmissionType::Url,
        'https://www.maybank2u.com.my/compromised/page',
        prediction(0.40),
        [ThreatIntelResult::hit('google_safe_browsing', [], 'MALWARE')],
    );

    expect($verdict->riskLevel)->toBe(RiskLevel::High)
        ->and($verdict->gatedByVerifiedDomain)->toBeFalse();
});

it('does not apply the gate to messages', function (): void {
    $verdict = detector()->fuse(
        SubmissionType::Message,
        'Sila log masuk di maybank2u.com.my sekarang',
        prediction(0.93),
    );

    expect($verdict->riskLevel)->toBe(RiskLevel::High);
});

/*
|--------------------------------------------------------------------------
| ADR 0007 decision 4 step 1 — degrade, never fail
|--------------------------------------------------------------------------
*/

it('returns a partial verdict when the model is unavailable', function (): void {
    $verdict = detector()->fuse(
        SubmissionType::Url,
        'https://a.example',
        MlPrediction::unavailable('ml_service_unreachable'),
    );

    expect($verdict->isPartial)->toBeTrue()
        ->and($verdict->label)->toBe(VerdictLabel::Unknown)
        ->and($verdict->riskLevel)->toBe(RiskLevel::Unknown)
        ->and($verdict->modelVersion)->toBeNull()
        ->and($verdict->partialReason)->toBe('ml_service_unreachable');
});

it('still warns from threat intel when the model is unavailable', function (): void {
    // A hit is actionable without a model, and is strictly better than
    // telling a user nothing at all.
    $verdict = detector()->fuse(
        SubmissionType::Url,
        'https://known-bad.example',
        MlPrediction::unavailable('ml_service_unreachable'),
        [ThreatIntelResult::hit('virustotal', [], '7 engines')],
    );

    expect($verdict->riskLevel)->toBe(RiskLevel::High)
        ->and($verdict->label)->toBe(VerdictLabel::Scam)
        ->and($verdict->isPartial)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| End to end with a faked ML service
|--------------------------------------------------------------------------
*/

it('produces a partial verdict when the ML service times out', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $verdict = detector()->analyse(SubmissionType::Url, 'https://unlisted.example/x');

    expect($verdict->isPartial)->toBeTrue()
        ->and($verdict->partialReason)->toBe('ml_service_unreachable');
});

it('produces a partial verdict when the ML service returns 503', function (): void {
    Http::fake(['*' => Http::response(['error' => 'model_unavailable'], 503)]);

    $verdict = detector()->analyse(SubmissionType::Message, 'Tahniah anda menang');

    expect($verdict->isPartial)->toBeTrue()
        ->and($verdict->partialReason)->toBe('model_unavailable')
        ->and($verdict->label)->toBe(VerdictLabel::Unknown);
});

it('produces a partial verdict when the ML service returns a malformed body', function (): void {
    Http::fake(['*' => Http::response(['unexpected' => true], 200)]);

    $verdict = detector()->analyse(SubmissionType::Url, 'https://unlisted.example/x');

    expect($verdict->isPartial)->toBeTrue()
        ->and($verdict->partialReason)->toBe('ml_service_malformed_response');
});

it('signs the request to the ML service', function (): void {
    Http::fake(['*' => Http::response(['error' => 'x'], 503)]);

    detector()->analyse(SubmissionType::Url, 'https://unlisted.example/x');

    Http::assertSent(function (Request $request): bool {
        $timestamp = $request->header('X-ScamSense-Timestamp')[0] ?? '';
        $signature = $request->header('X-ScamSense-Signature')[0] ?? '';

        $expected = hash_hmac(
            'sha256',
            $timestamp.'.'.$request->body(),
            (string) config('services.ml_service.secret'),
        );

        return hash_equals($expected, $signature);
    });
});

it('never fetches the submitted URL itself', function (): void {
    /*
     * AGENTS.md §2 rule 3. The only outbound requests may be to the ML service
     * and the reputation providers — never to the URL a user pasted.
     */
    Http::fake(['*' => Http::response(['error' => 'x'], 503)]);

    detector()->analyse(SubmissionType::Url, 'https://attacker-controlled.example/payload');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'attacker-controlled.example'));
});
