<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Verdict;
use App\Services\Feedback\SessionTokenService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a community feedback submission.
 *
 * This is an unauthenticated write path, so it gets the same treatment as the
 * verification endpoint: every field validated here before anything reaches a
 * service, length capped, and the target verdict checked for ownership.
 */
class SubmitFeedbackRequest extends FormRequest
{
    /**
     * Feedback may only be left on a verdict from the visitor's own session.
     *
     * Authorisation rather than validation because the failure is "not yours",
     * not "malformed" — and because returning 403 rather than a validation
     * error keeps the two cases distinguishable in the logs when someone starts
     * walking verdict ids.
     */
    public function authorize(): bool
    {
        $verdict = Verdict::query()->with('submission')->find($this->input('verdict_id'));

        if ($verdict === null) {
            return false;
        }

        return app(SessionTokenService::class)
            ->matches($verdict->submission?->session_token_hash);
    }

    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'verdict_id' => ['required', 'integer', 'exists:verdicts,id'],
            'agrees' => ['required', 'boolean'],
            // Free text from an anonymous stranger. Capped, and redacted before
            // storage like every other submitted string.
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function verdict(): Verdict
    {
        return Verdict::query()->findOrFail($this->validated('verdict_id'));
    }

    public function agrees(): bool
    {
        return $this->boolean('agrees');
    }

    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) && trim($comment) !== '' ? trim($comment) : null;
    }
}
