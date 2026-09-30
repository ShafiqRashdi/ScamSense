<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SubmissionType;
use App\Rules\UrlSafetyRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a public verification request.
 *
 * Every rule here runs before anything reaches a service (AGENTS.md §3). The
 * length caps and the scheme restriction are the boundary between hostile input
 * and the rest of the application.
 */
class VerifySubmissionRequest extends FormRequest
{
    /**
     * Where a failed validation returns. Named rather than "previous page":
     * the site sends no Referer, so "previous" means whatever this session
     * opened last, possibly the admin area in another tab.
     */
    protected $redirectRoute = 'verify.show';

    public function authorize(): bool
    {
        // Public endpoint. Rate limiting is middleware, not authorisation.
        return true;
    }

    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        $isUrl = $this->input('type') === SubmissionType::Url->value;

        return [
            'type' => ['required', Rule::enum(SubmissionType::class)],
            'content' => array_filter([
                'required',
                'string',
                'max:'.($isUrl
                    ? config('scamsense.limits.url_max_chars')
                    : config('scamsense.limits.message_max_chars')),
                $isUrl ? new UrlSafetyRule : null,
            ]),
        ];
    }

    public function submissionType(): SubmissionType
    {
        return SubmissionType::from((string) $this->validated('type'));
    }

    public function content(): string
    {
        return trim((string) $this->validated('content'));
    }
}
