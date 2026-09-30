<?php

declare(strict_types=1);

use App\DataTransferObjects\IndicatorData;
use App\DataTransferObjects\RememberedResult;
use App\DataTransferObjects\VerdictResult;
use App\DataTransferObjects\VerifiedInstitution;
use App\Enums\IndicatorSource;
use App\Enums\RiskLevel;
use App\Enums\VerdictLabel;
use App\Models\Verdict;
use App\Services\ResultSession;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

/*
 | The feature tests keep one session object in memory for a whole test, so
 | whatever is put into it comes back unchanged. Production writes the session
 | out as JSON and reads it back on the next request, which turns objects into
 | arrays. The first ResultSession stored an object, passed every feature test
 | and sent every real visitor back to the form. These tests go through a real
 | save and a fresh load, with the serialisation config/session.php uses.
 */

function roundTrip(VerdictResult $verdict, int $verdictId): ?RememberedResult
{
    $handler = new ArraySessionHandler(120);
    $serialization = config('session.serialization');

    $first = new Store('scamsense_session', $handler, null, $serialization);
    $first->start();
    $record = new Verdict;
    $record->id = $verdictId;
    (new ResultSession($first))->remember($verdict, $record);
    $first->save();

    $second = new Store('scamsense_session', $handler, $first->getId(), $serialization);
    $second->start();

    return (new ResultSession($second))->recall();
}

it('uses JSON session serialisation, which is what makes this test necessary', function (): void {
    expect(config('session.serialization'))->toBe('json');
});

it('survives a real save and load with indicators intact', function (): void {
    $verdict = new VerdictResult(
        label: VerdictLabel::Scam,
        riskLevel: RiskLevel::High,
        confidence: 0.93,
        modelProbability: 0.9312,
        indicators: [
            new IndicatorData('urgency_language', 0.31, 'segera dalam 24 jam', IndicatorSource::Pattern),
            new IndicatorData('suspicious_tld', 0.2, null, IndicatorSource::Model),
        ],
        modelVersion: 'tfidf-msg-v3.0.0-collected-my',
        isPartial: true,
        partialReason: 'threat_intel_unavailable',
        inferenceMs: 42,
    );

    $recalled = roundTrip($verdict, 17);

    expect($recalled)->not->toBeNull()
        ->and($recalled->verdictId)->toBe(17)
        ->and($recalled->verdict)->toEqual($verdict);
});

it('survives a real save and load for a verified institution', function (): void {
    $verdict = new VerdictResult(
        label: VerdictLabel::Legitimate,
        riskLevel: RiskLevel::Low,
        confidence: 0.1,
        modelProbability: 0.93,
        indicators: [new IndicatorData('verified_domain', 1.0, 'maybank2u.com.my', IndicatorSource::Registry)],
        modelVersion: 'rf-url-v2.0.0-pathnorm',
        isPartial: false,
        partialReason: null,
        inferenceMs: 30,
        gatedByVerifiedDomain: true,
        institution: new VerifiedInstitution(
            domain: 'maybank2u.com.my',
            name: 'Maybank',
            nameMs: null,
            authority: 'Bank Negara Malaysia',
            reference: 'https://example.test/licensed-banks',
            verifiedOn: '2026-08-20',
        ),
    );

    expect(roundTrip($verdict, 3)?->verdict)->toEqual($verdict);
});

it('returns nothing rather than failing on something it cannot rebuild', function (): void {
    $session = new Store('s', new ArraySessionHandler(120), null, 'json');
    $session->put('verify.latest_result', ['verdict_id' => 1, 'label' => 'not-a-label']);

    expect((new ResultSession($session))->recall())->toBeNull();
});
