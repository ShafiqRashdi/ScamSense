<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SubmissionType;
use App\Http\Requests\VerifySubmissionRequest;
use App\Models\Submission;
use App\Models\Verdict;
use App\Services\Detection\DetectionService;
use App\Services\RedactorService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public verification flow (objective O2).
 *
 * Thin by design: validate, redact, detect, persist, render. Every decision
 * lives in a service.
 */
class VerifyController extends Controller
{
    public function __construct(
        private DetectionService $detection,
        private RedactorService $redactor,
    ) {}

    public function show(): View
    {
        return view('verify.index');
    }

    public function store(VerifySubmissionRequest $request): View
    {
        $type = $request->submissionType();
        $raw = $request->content();

        // Redact BEFORE anything is stored (AGENTS.md §3). The model still
        // sees the redacted text, which is deliberate: PII is not signal, and
        // RedactorService leaves URLs intact so the phishing link survives.
        $redaction = $this->redactor->redact($raw);

        $verdict = $this->detection->analyse($type, $redaction->text);

        $submission = Submission::query()->create([
            'type' => $type,
            'content_redacted' => $redaction->text,
            'content_hash' => Submission::hashContent($redaction->text),
            'ip_hash' => $this->hashIp($request),
        ]);

        $record = new Verdict($verdict->toVerdictAttributes());
        $record->submission_id = $submission->id;
        $record->save();

        foreach ($verdict->indicators as $indicator) {
            $record->indicators()->create([
                'code' => $indicator->code,
                'weight' => $indicator->weight,
                // Evidence quotes user content, so it is redacted like the rest.
                'evidence' => $indicator->evidence === null
                    ? null
                    : $this->redactor->redactToString($indicator->evidence),
                'source' => $indicator->source,
            ]);
        }

        return view('verify.result', [
            'verdict' => $verdict,
            'submission' => $submission,
            'isUrl' => $type === SubmissionType::Url,
        ]);
    }

    /**
     * Hash the IP rather than storing it.
     *
     * Rate limiting and abuse detection need to tell addresses apart, never to
     * identify the people behind them.
     */
    private function hashIp(Request $request): string
    {
        return hash('sha256', (string) $request->ip());
    }
}
