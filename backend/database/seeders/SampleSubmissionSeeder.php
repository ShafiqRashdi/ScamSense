<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\IndicatorSource;
use App\Enums\RiskLevel;
use App\Enums\SubmissionType;
use App\Enums\VerdictLabel;
use App\Models\Indicator;
use App\Models\Submission;
use App\Models\Verdict;
use App\Services\RedactorService;
use Illuminate\Database\Seeder;

/**
 * Realistic Malaysian sample data for local development and demonstrations.
 *
 * Three things about this seeder are deliberate:
 *
 * 1. **The samples carry PII and it is redacted here, not pre-masked in the
 *    source array.** Every message goes through RedactorService on its way in,
 *    exactly as a real submission would. That makes the seeder a live
 *    demonstration that redaction runs before `->save()` rather than an
 *    assertion that it does.
 *
 * 2. **The scam text is drawn from patterns already circulating publicly in
 *    Malaysia** — prize claims, bank account suspension, parcel customs fees,
 *    LHDN refunds, the Macau scam. Detection needs the real shape of the
 *    problem. The phone numbers, account numbers and IC numbers in them are
 *    invented, and the domains use TLDs that do not resolve.
 *
 * 3. **The verdicts here are illustrative, not model output.** No model has run
 *    over this data; the labels and confidences are hand-set so the Phase 6 UI
 *    and Phase 8 dashboard have something to render. `model_version` says so
 *    explicitly rather than naming a real model, so a screenshot taken from
 *    seeded data can never be mistaken for a measured result.
 */
class SampleSubmissionSeeder extends Seeder
{
    /**
     * Marks a verdict as illustrative seed data rather than a model output.
     */
    private const SEED_MODEL_VERSION = 'seed-data-not-a-model';

    public function run(RedactorService $redactor): void
    {
        foreach ($this->samples() as $sample) {
            $redaction = $redactor->redact($sample['content']);

            $submission = Submission::query()->create([
                'type' => $sample['type'],
                'content_redacted' => $redaction->text,
                'content_hash' => Submission::hashContent($redaction->text),
                'ip_hash' => hash('sha256', fake()->ipv4()),
            ]);

            $verdict = Verdict::query()->create([
                'submission_id' => $submission->id,
                'label' => $sample['label'],
                'risk_level' => $sample['risk'],
                'confidence' => $sample['confidence'],
                'model_version' => self::SEED_MODEL_VERSION,
                'is_partial' => false,
                'inference_ms' => random_int(90, 420),
            ]);

            foreach ($sample['indicators'] as [$code, $weight, $evidence, $source]) {
                Indicator::query()->create([
                    'verdict_id' => $verdict->id,
                    'code' => $code,
                    'weight' => $weight,
                    // Evidence quotes user content, so it is redacted too.
                    'evidence' => $evidence === null ? null : $redactor->redactToString($evidence),
                    'source' => $source,
                ]);
            }
        }

        $this->seedDegradedVerdict();
    }

    /**
     * One verdict produced while the ML service was unreachable.
     *
     * Present so that the Phase 6 UI has a partial verdict to render during
     * development, and so nobody builds the result panel having only ever seen
     * the happy path (AGENTS.md §2, rule 4).
     */
    private function seedDegradedVerdict(): void
    {
        $content = 'Akaun anda akan disekat. Sila sahkan di https://bank-verify.top/login';

        $submission = Submission::query()->create([
            'type' => SubmissionType::Message,
            'content_redacted' => $content,
            'content_hash' => Submission::hashContent($content),
            'ip_hash' => hash('sha256', fake()->ipv4()),
        ]);

        Verdict::query()->create([
            'submission_id' => $submission->id,
            'label' => VerdictLabel::Unknown,
            'risk_level' => RiskLevel::Unknown,
            'confidence' => 0,
            'model_version' => null,
            'is_partial' => true,
            'partial_reason' => 'ml_service_unavailable',
            'inference_ms' => null,
        ]);
    }

    /**
     * @return list<array{
     *     type: SubmissionType,
     *     content: string,
     *     label: VerdictLabel,
     *     risk: RiskLevel,
     *     confidence: float,
     *     indicators: list<array{0: string, 1: float, 2: string|null, 3: IndicatorSource}>
     * }>
     */
    private function samples(): array
    {
        $pattern = IndicatorSource::Pattern;
        $model = IndicatorSource::Model;
        $intel = IndicatorSource::ThreatIntel;

        return [
            // ── Scam messages, Bahasa/English code-switched ────────────────
            [
                'type' => SubmissionType::Message,
                'content' => 'TAHNIAH! Anda telah memenangi RM5,000 dalam cabutan bertuah Syarikat kami. '
                    .'Sila hubungi 012-345 6789 dan berikan no. akaun bank 1234567890 dalam tempoh 24 jam '
                    .'sebelum hadiah dilupuskan.',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::High,
                'confidence' => 0.9600,
                'indicators' => [
                    ['prize_claim', 0.3400, 'Anda telah memenangi RM5,000', $pattern],
                    ['urgency_language', 0.2900, 'dalam tempoh 24 jam', $pattern],
                    ['payment_demand', 0.2100, 'no. akaun bank', $pattern],
                ],
            ],
            [
                'type' => SubmissionType::Message,
                'content' => 'MAYBANK: Akaun anda telah digantung kerana aktiviti mencurigakan. '
                    .'Sahkan identiti anda sekarang di https://maybank2u-verify.xyz/login atau akaun '
                    .'akan ditutup dalam 12 jam.',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::High,
                'confidence' => 0.9800,
                'indicators' => [
                    ['brand_lookalike', 0.3600, 'maybank2u-verify.xyz', $model],
                    ['credential_request', 0.3100, 'Sahkan identiti anda', $pattern],
                    ['urgency_language', 0.2000, 'dalam 12 jam', $pattern],
                    ['suspicious_tld', 0.1300, '.xyz', $model],
                ],
            ],
            [
                'type' => SubmissionType::Message,
                'content' => 'Pos Laju: Bungkusan anda tertahan di kastam. Cukai RM3.50 belum dijelaskan. '
                    .'Bayar di https://poslaju-cukai.top/bayar untuk elak bungkusan dipulangkan.',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::High,
                'confidence' => 0.9400,
                'indicators' => [
                    ['brand_lookalike', 0.3300, 'poslaju-cukai.top', $model],
                    ['payment_demand', 0.2800, 'Cukai RM3.50 belum dijelaskan', $pattern],
                    ['suspicious_tld', 0.2400, '.top', $model],
                ],
            ],
            [
                'type' => SubmissionType::Message,
                'content' => 'LHDN: Anda layak menerima pulangan cukai sebanyak RM1,280.00. '
                    .'Sila isi maklumat perbankan anda di https://lhdn-pulangan.site untuk pembayaran.',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::High,
                'confidence' => 0.9500,
                'indicators' => [
                    ['brand_lookalike', 0.3500, 'lhdn-pulangan.site', $model],
                    ['credential_request', 0.3000, 'maklumat perbankan anda', $pattern],
                    ['suspicious_tld', 0.2200, '.site', $model],
                ],
            ],
            [
                'type' => SubmissionType::Message,
                'content' => 'Encik, ini Sarjan Rahim dari PDRM Bukit Aman. Akaun anda terlibat dalam '
                    .'kes pengubahan wang haram. Sila hubungi 03-2145 6789 segera dan jangan beritahu sesiapa.',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::High,
                'confidence' => 0.9700,
                'indicators' => [
                    ['credential_request', 0.3800, 'Akaun anda terlibat', $pattern],
                    ['payment_demand', 0.2700, 'kes pengubahan wang haram', $pattern],
                    ['urgency_language', 0.2000, 'segera', $pattern],
                ],
            ],
            [
                'type' => SubmissionType::Message,
                'content' => 'Kerja part time online! Income RM300-RM500 sehari, kerja dari rumah, '
                    .'tiada pengalaman diperlukan. WhatsApp 019-876 5432 sekarang. Terhad 10 kekosongan sahaja!',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::Medium,
                'confidence' => 0.7300,
                'indicators' => [
                    ['prize_claim', 0.3200, 'Income RM300-RM500 sehari', $pattern],
                    ['urgency_language', 0.2300, 'Terhad 10 kekosongan sahaja', $pattern],
                ],
            ],

            // ── Legitimate messages ───────────────────────────────────────
            [
                'type' => SubmissionType::Message,
                'content' => 'TAC anda ialah 483920. Sah selama 5 minit. Jangan kongsi dengan sesiapa '
                    .'termasuk kakitangan bank.',
                'label' => VerdictLabel::Legitimate,
                'risk' => RiskLevel::Low,
                'confidence' => 0.9100,
                'indicators' => [
                ],
            ],
            [
                'type' => SubmissionType::Message,
                'content' => 'Peringatan: Temujanji klinik anda pada 15 Ogos 2026, jam 10:30 pagi. '
                    .'Sila hadir 15 minit awal.',
                'label' => VerdictLabel::Legitimate,
                'risk' => RiskLevel::Low,
                'confidence' => 0.9300,
                'indicators' => [
                ],
            ],
            [
                'type' => SubmissionType::Message,
                'content' => 'Terima kasih. Tambah nilai RM30.00 telah berjaya. Baki anda kini RM42.50.',
                'label' => VerdictLabel::Legitimate,
                'risk' => RiskLevel::Low,
                'confidence' => 0.8900,
                'indicators' => [
                ],
            ],

            // ── Phishing URLs imitating Malaysian banks and couriers ──────
            [
                'type' => SubmissionType::Url,
                'content' => 'https://cimb-clicks.secure-login.top/verify?id=8821',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::High,
                'confidence' => 0.9700,
                'indicators' => [
                    ['brand_lookalike', 0.3300, 'cimb-clicks', $model],
                    ['excessive_subdomains', 0.2600, 'cimb-clicks.secure-login.top', $model],
                    ['suspicious_tld', 0.2400, '.top', $model],
                ],
            ],
            [
                'type' => SubmissionType::Url,
                'content' => 'http://192.168.44.21/maybank2u/login.php',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::High,
                'confidence' => 0.9900,
                'indicators' => [
                    ['ip_literal_host', 0.4100, '192.168.44.21', $model],
                    ['no_https', 0.2700, 'http', $model],
                    ['brand_lookalike', 0.2200, 'maybank2u', $model],
                ],
            ],
            [
                'type' => SubmissionType::Url,
                'content' => 'https://bit.ly/3xK9mZq',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::Medium,
                'confidence' => 0.6400,
                'indicators' => [
                    ['url_shortener', 0.4800, 'bit.ly', $model],
                ],
            ],
            [
                'type' => SubmissionType::Url,
                'content' => 'https://xn--myabank-6va.com/login',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::High,
                'confidence' => 0.9300,
                'indicators' => [
                    ['punycode_host', 0.4400, 'xn--myabank-6va', $model],
                    ['brand_lookalike', 0.3000, 'myabank', $model],
                    ['threat_intel_hit', 0.1800, 'google_safe_browsing', $intel],
                ],
            ],
            [
                'type' => SubmissionType::Url,
                'content' => 'https://ninjavan-my.delivery-update.xyz/track/MY8837162',
                'label' => VerdictLabel::Scam,
                'risk' => RiskLevel::High,
                'confidence' => 0.9200,
                'indicators' => [
                    ['brand_lookalike', 0.3400, 'ninjavan-my', $model],
                    ['excessive_subdomains', 0.2800, 'ninjavan-my.delivery-update.xyz', $model],
                    ['suspicious_tld', 0.2100, '.xyz', $model],
                ],
            ],

            // ── Legitimate URLs ───────────────────────────────────────────
            [
                'type' => SubmissionType::Url,
                'content' => 'https://www.maybank2u.com.my',
                'label' => VerdictLabel::Legitimate,
                'risk' => RiskLevel::Low,
                'confidence' => 0.9600,
                'indicators' => [
                    ['verified_institutional_domain', 0.4200, 'maybank2u.com.my', $model],
                ],
            ],
            [
                'type' => SubmissionType::Url,
                'content' => 'https://www.hasil.gov.my/en/',
                'label' => VerdictLabel::Legitimate,
                'risk' => RiskLevel::Low,
                'confidence' => 0.9500,
                'indicators' => [
                    ['verified_institutional_domain', 0.4500, 'hasil.gov.my', $model],
                ],
            ],
            [
                'type' => SubmissionType::Url,
                'content' => 'https://www.poslaju.com.my/track-trace/',
                'label' => VerdictLabel::Legitimate,
                'risk' => RiskLevel::Low,
                'confidence' => 0.9400,
                'indicators' => [
                    ['verified_institutional_domain', 0.4300, 'poslaju.com.my', $model],
                ],
            ],
        ];
    }
}
