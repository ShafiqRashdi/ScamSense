<?php

declare(strict_types=1);

namespace App\Services\Detection;

use App\DataTransferObjects\IndicatorData;
use App\DataTransferObjects\MlPrediction;
use App\DataTransferObjects\ThreatIntelResult;
use App\DataTransferObjects\VerdictResult;
use App\Enums\IndicatorSource;
use App\Enums\RiskLevel;
use App\Enums\SubmissionType;
use App\Enums\VerdictLabel;
use App\Services\ThreatIntel\ThreatIntelAggregator;

/**
 * Fuses model output, threat intelligence and registry facts into one verdict.
 *
 * The order below is decided in ADR 0007, not by convenience:
 *
 *   1. No model?                   -> partial verdict, label unknown
 *   2. Threat-intel HIT?           -> High, escalation indicator
 *   3. Verified institution?       -> capped at Low, registry indicator
 *   4. Otherwise                   -> band the model probability
 *   5. A provider unavailable?     -> mark partial, independent of the outcome
 *
 * Two properties of that order are load-bearing:
 *
 * **Clean threat intelligence never lowers a verdict.** A clean lookup is
 * absence of evidence — the default state of every phishing domain registered
 * in the last few hours. Allowing it to de-escalate would be most effective
 * exactly where the lexical model is the only defence.
 *
 * **Escalation is evaluated before the verified-domain gate**, so a compromised
 * bank domain still reaches the user as high risk. Registry membership proves
 * who operates a domain, not that today's page is safe.
 */
class DetectionService
{
    public function __construct(
        private MlServiceClient $ml,
        private ThreatIntelAggregator $threatIntel,
        private VerifiedDomainRegistry $verifiedDomains,
    ) {}

    public function analyse(SubmissionType $type, string $content, ?string $requestId = null): VerdictResult
    {
        $prediction = $this->ml->predictFor($type, $content, $requestId);

        $intel = $type === SubmissionType::Url
            ? $this->threatIntel->lookup($content)
            : [];

        return $this->fuse($type, $content, $prediction, $intel);
    }

    /**
     * @param  list<ThreatIntelResult>  $intel
     */
    public function fuse(
        SubmissionType $type,
        string $content,
        MlPrediction $prediction,
        array $intel = [],
    ): VerdictResult {
        $indicators = $prediction->indicators;
        $hits = array_values(array_filter($intel, static fn (ThreatIntelResult $r): bool => $r->hit));

        // A provider that could not be reached contributes nothing, but the
        // user is entitled to know a signal was missing.
        $unreachable = array_values(array_filter(
            $intel,
            static fn (ThreatIntelResult $r): bool => ! $r->isInformative(),
        ));

        foreach ($hits as $hit) {
            $indicators[] = new IndicatorData(
                code: 'threat_intel_hit',
                weight: 0.0,
                evidence: $hit->provider,
                source: IndicatorSource::ThreatIntel,
            );
        }

        // ── 1. No model ────────────────────────────────────────────────────
        if (! $prediction->isAvailable()) {
            // A threat-intel hit is still actionable without a model, and is
            // strictly better than telling the user nothing.
            if ($hits !== []) {
                return new VerdictResult(
                    label: VerdictLabel::Scam,
                    riskLevel: RiskLevel::High,
                    confidence: 1.0,
                    modelProbability: 0.0,
                    indicators: $indicators,
                    modelVersion: null,
                    isPartial: true,
                    partialReason: $prediction->unavailableReason,
                    inferenceMs: null,
                );
            }

            return new VerdictResult(
                label: VerdictLabel::Unknown,
                riskLevel: RiskLevel::Unknown,
                confidence: 0.0,
                modelProbability: 0.0,
                indicators: $indicators,
                modelVersion: null,
                isPartial: true,
                partialReason: $prediction->unavailableReason,
                inferenceMs: null,
            );
        }

        $probability = $prediction->probability;
        $isPartial = $unreachable !== [];
        $partialReason = $isPartial
            ? 'threat_intel_unavailable:'.implode(',', array_map(
                static fn (ThreatIntelResult $r): string => $r->provider,
                $unreachable,
            ))
            : null;

        // ── 2. Escalate on a positive threat-intel hit ─────────────────────
        if ($hits !== []) {
            return new VerdictResult(
                label: VerdictLabel::Scam,
                riskLevel: RiskLevel::High,
                confidence: max($probability, 0.99),
                modelProbability: $probability,
                indicators: $indicators,
                modelVersion: $prediction->modelVersion,
                isPartial: $isPartial,
                partialReason: $partialReason,
                inferenceMs: $prediction->inferenceMs,
            );
        }

        // ── 3. The verified-domain gate (URLs only) ────────────────────────
        if ($type === SubmissionType::Url) {
            $verified = $this->verifiedDomains->match($content);

            if ($verified !== null) {
                $indicators[] = new IndicatorData(
                    code: 'verified_institutional_domain',
                    weight: 0.0,
                    evidence: $verified,
                    source: IndicatorSource::Registry,
                );

                return new VerdictResult(
                    label: VerdictLabel::Legitimate,
                    riskLevel: RiskLevel::Low,
                    // Confidence describes the gate's basis, not the model's
                    // score — which is preserved separately in modelProbability
                    // so nothing downstream measures a doctored number.
                    confidence: 1.0 - $probability,
                    modelProbability: $probability,
                    indicators: $indicators,
                    modelVersion: $prediction->modelVersion,
                    isPartial: $isPartial,
                    partialReason: $partialReason,
                    inferenceMs: $prediction->inferenceMs,
                    gatedByVerifiedDomain: true,
                );
            }
        }

        // ── 4. Band the model probability ──────────────────────────────────
        $risk = $this->band($probability);
        $label = $risk === RiskLevel::Low ? VerdictLabel::Legitimate : VerdictLabel::Scam;

        return new VerdictResult(
            label: $label,
            riskLevel: $risk,
            confidence: $label === VerdictLabel::Scam ? $probability : 1.0 - $probability,
            modelProbability: $probability,
            indicators: $indicators,
            modelVersion: $prediction->modelVersion,
            isPartial: $isPartial,
            partialReason: $partialReason,
            inferenceMs: $prediction->inferenceMs,
        );
    }

    /**
     * Map a probability to a risk band.
     *
     * Thresholds come from the measured test-set distribution in
     * docs/RESULTS.md: low is 2.0% actually phishing, medium 28.3%, high 95.2%.
     * The middle band exists because forcing an uncertain result to one side
     * would misrepresent it — 28% is genuinely "be careful", not "safe" and not
     * "dangerous".
     */
    private function band(float $probability): RiskLevel
    {
        $mediumFrom = (float) config('scamsense.risk_bands.medium_from', 0.20);
        $highFrom = (float) config('scamsense.risk_bands.high_from', 0.60);

        return match (true) {
            $probability >= $highFrom => RiskLevel::High,
            $probability >= $mediumFrom => RiskLevel::Medium,
            default => RiskLevel::Low,
        };
    }
}
