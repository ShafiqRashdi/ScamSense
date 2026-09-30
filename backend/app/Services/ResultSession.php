<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\IndicatorData;
use App\DataTransferObjects\RememberedResult;
use App\DataTransferObjects\VerdictResult;
use App\DataTransferObjects\VerifiedInstitution;
use App\Enums\IndicatorSource;
use App\Enums\RiskLevel;
use App\Enums\VerdictLabel;
use App\Models\Verdict;
use Illuminate\Contracts\Session\Session;
use Throwable;

/**
 * Keeps the visitor's latest verdict so the result page has a real address.
 *
 * Before this, the result was rendered straight from POST /verify. A page with
 * no GET address cannot be returned to, so the language switch and the
 * feedback buttons fell back to "the last GET page in this session", which
 * was the home page, or the admin dashboard when it was open in another tab.
 *
 * Scoped to the session, never to an id in the URL: /result shows only the
 * visitor's own latest check, so there is nothing to enumerate.
 *
 * Stored as plain values, not objects. config/session.php serialises sessions
 * as JSON, deliberately, so a leaked APP_KEY cannot be turned into a PHP
 * object-injection attack; an object put into the session comes back as an
 * array. The first version stored the DTO itself, passed every test (the test
 * session never leaves memory) and sent every real visitor back to the form.
 */
class ResultSession
{
    private const KEY = 'verify.latest_result';

    public function __construct(private Session $session) {}

    public function remember(VerdictResult $verdict, Verdict $record): void
    {
        $this->session->put(self::KEY, [
            'verdict_id' => (int) $record->id,
            'label' => $verdict->label->value,
            'risk_level' => $verdict->riskLevel->value,
            'confidence' => $verdict->confidence,
            'model_probability' => $verdict->modelProbability,
            'indicators' => array_map(
                static fn (IndicatorData $indicator): array => $indicator->toArray(),
                $verdict->indicators,
            ),
            'model_version' => $verdict->modelVersion,
            'is_partial' => $verdict->isPartial,
            'partial_reason' => $verdict->partialReason,
            'inference_ms' => $verdict->inferenceMs,
            'gated' => $verdict->gatedByVerifiedDomain,
            'institution' => $verdict->institution === null ? null : [
                'domain' => $verdict->institution->domain,
                'name' => $verdict->institution->name,
                'name_ms' => $verdict->institution->nameMs,
                'authority' => $verdict->institution->authority,
                'reference' => $verdict->institution->reference,
                'verified_on' => $verdict->institution->verifiedOn,
                'by_suffix' => $verdict->institution->bySuffix,
            ],
        ]);
    }

    /**
     * Null when there is nothing stored, or when what is stored cannot be
     * rebuilt (an older format, or tampering): the visitor simply goes back
     * to the form rather than seeing an error.
     */
    public function recall(): ?RememberedResult
    {
        $stored = $this->session->get(self::KEY);

        if (! is_array($stored)) {
            return null;
        }

        try {
            return new RememberedResult($this->rebuild($stored), (int) $stored['verdict_id']);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function rebuild(array $s): VerdictResult
    {
        $institution = is_array($s['institution'] ?? null) ? new VerifiedInstitution(
            domain: (string) $s['institution']['domain'],
            name: (string) $s['institution']['name'],
            nameMs: isset($s['institution']['name_ms']) ? (string) $s['institution']['name_ms'] : null,
            authority: (string) $s['institution']['authority'],
            reference: (string) $s['institution']['reference'],
            verifiedOn: (string) $s['institution']['verified_on'],
            bySuffix: (bool) $s['institution']['by_suffix'],
        ) : null;

        return new VerdictResult(
            label: VerdictLabel::from((string) $s['label']),
            riskLevel: RiskLevel::from((string) $s['risk_level']),
            confidence: (float) $s['confidence'],
            modelProbability: (float) $s['model_probability'],
            indicators: array_values(array_map(
                static fn (array $i): IndicatorData => new IndicatorData(
                    code: (string) $i['code'],
                    weight: (float) $i['weight'],
                    evidence: isset($i['evidence']) ? (string) $i['evidence'] : null,
                    source: IndicatorSource::from((string) $i['source']),
                ),
                (array) $s['indicators'],
            )),
            modelVersion: isset($s['model_version']) ? (string) $s['model_version'] : null,
            isPartial: (bool) $s['is_partial'],
            partialReason: isset($s['partial_reason']) ? (string) $s['partial_reason'] : null,
            inferenceMs: isset($s['inference_ms']) ? (int) $s['inference_ms'] : null,
            gatedByVerifiedDomain: (bool) $s['gated'],
            institution: $institution,
        );
    }
}
