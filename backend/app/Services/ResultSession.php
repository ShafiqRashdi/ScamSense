<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\RememberedResult;
use App\DataTransferObjects\VerdictResult;
use App\Models\Verdict;
use Illuminate\Contracts\Session\Session;

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
 */
class ResultSession
{
    private const KEY = 'verify.latest_result';

    public function __construct(private Session $session) {}

    public function remember(VerdictResult $verdict, Verdict $record): void
    {
        $this->session->put(self::KEY, new RememberedResult($verdict, (int) $record->id));
    }

    public function recall(): ?RememberedResult
    {
        $stored = $this->session->get(self::KEY);

        return $stored instanceof RememberedResult ? $stored : null;
    }
}
