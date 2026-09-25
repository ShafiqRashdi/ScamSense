<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubmissionType;
use App\Services\Detection\MlServiceClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * End-to-end readiness check for the detection chain.
 *
 * ## Why this exists
 *
 * The failure this catches has now happened three times during development and
 * is invisible from every surface that looks like it should reveal it.
 *
 * When the Flask process is started with a secret that does not match
 * `ML_SERVICE_SECRET` in the backend's environment, the service is genuinely
 * healthy: it is listening, it has loaded both artefacts, and `/health` returns
 * `models_loaded: true`. Every *signed* request, however, fails the HMAC check
 * and comes back 401. Laravel does the right thing and degrades to a partial
 * verdict, so the only symptom a person sees is that every single check answers
 * "We could not check this properly" — which reads like a broken model rather
 * than a mismatched secret.
 *
 * `/health` cannot detect this on its own. It is unauthenticated by design, so
 * it never exercises the signature path, and it could not verify a secret
 * *matches* even if it wanted to — only that one is present. The sole way to
 * know the chain works is to send a real signed request and look at the answer,
 * which is exactly what this command does.
 *
 * Run it before a demo. A green result means a submission will produce a real
 * verdict; anything else names the broken link.
 */
class Doctor extends Command
{
    protected $signature = 'scamsense:doctor';

    protected $description = 'Check that the detection chain answers end to end';

    public function handle(MlServiceClient $ml): int
    {
        $this->newLine();
        $this->line('  ScamSense readiness check');
        $this->line('  '.str_repeat('=', 44));
        $this->newLine();

        $failures = 0;

        $failures += $this->reportConfiguration();
        $failures += $this->reportReachability($ml);
        $failures += $this->reportSignedCall($ml, SubmissionType::Url, 'https://www.utm.my/about/');
        $failures += $this->reportSignedCall(
            $ml,
            SubmissionType::Message,
            'Tahniah! Anda memenangi RM5,000. Sila klik pautan untuk tuntut.',
        );

        $this->newLine();

        if ($failures > 0) {
            $this->error("  {$failures} check(s) failed. Submissions will return partial verdicts.");
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  All checks passed. A submission will produce a real verdict.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function reportConfiguration(): int
    {
        $secret = (string) config('services.ml_service.secret');
        $url = (string) config('services.ml_service.url');

        if ($secret === '') {
            $this->fail_('shared secret', 'ML_SERVICE_SECRET is not set in the backend environment');

            return 1;
        }

        // Length and a short fingerprint only. The value itself never reaches
        // the terminal, because a demo runs this on a shared screen.
        $this->pass('shared secret', strlen($secret).' chars, fingerprint '.substr(hash('sha256', $secret), 0, 8));
        $this->pass('service url', $url !== '' ? $url : '(empty)');

        return $url === '' ? 1 : 0;
    }

    private function reportReachability(MlServiceClient $ml): int
    {
        if (! $ml->isHealthy()) {
            $this->fail_('service reachable', 'no response from '.config('services.ml_service.url'));
            $this->hint('Start it: run start.bat, or from ml-service/ set ML_SERVICE_SECRET and run wsgi.py');

            return 1;
        }

        $this->pass('service reachable', 'health endpoint answered');

        return 0;
    }

    /**
     * The check that matters. Everything above can pass while this fails.
     */
    private function reportSignedCall(MlServiceClient $ml, SubmissionType $type, string $content): int
    {
        $label = "signed {$type->value} prediction";

        try {
            $prediction = $ml->predictFor($type, $content, 'doctor');
        } catch (Throwable $exception) {
            $this->fail_($label, $exception->getMessage());

            return 1;
        }

        if (! $prediction->isAvailable()) {
            $this->fail_($label, $prediction->unavailableReason ?? 'unavailable');
            $this->hintFor((string) $prediction->unavailableReason);

            return 1;
        }

        $this->pass($label, sprintf(
            '%s at %.4f via %s',
            $prediction->label?->value ?? 'unknown',
            $prediction->probability,
            $prediction->modelVersion ?? 'unknown model',
        ));

        return 0;
    }

    private function hintFor(string $reason): void
    {
        match ($reason) {
            'ml_service_error' => $this->hint(
                'A 401 here means the Flask process holds a DIFFERENT secret than this '
                .'application. Restart it with ML_SERVICE_SECRET taken from backend/.env — '
                .'note that /health will keep reporting healthy either way.'
            ),
            'model_unavailable' => $this->hint(
                'The service is up but could not load an artefact. Check its startup log '
                .'for a scikit-learn version mismatch between training and serving.'
            ),
            'ml_service_unreachable' => $this->hint('Nothing is listening. Start the ML service.'),
            default => null,
        };
    }

    private function pass(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=green>PASS</> %-28s %s', $label, $detail));
    }

    /**
     * Named with a trailing underscore because `fail()` is a reserved method on
     * Illuminate\Console\Command and overriding it changes exception behaviour.
     */
    private function fail_(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=red>FAIL</> %-28s %s', $label, $detail));
    }

    private function hint(string $text): void
    {
        $this->line('       <fg=yellow>-></> '.$text);
    }
}
