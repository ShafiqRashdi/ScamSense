<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use Illuminate\Support\Str;

/**
 * Issues and checks the anonymous session token that links a visitor to the
 * verdicts they created.
 *
 * ScamSense has no public accounts, so there is no user id to attribute
 * feedback to. Without any link, feedback would be postable against any verdict
 * id — which is precisely the bulk-labelling primitive the moderation queue
 * exists to deny. This is the cheapest control that closes it without asking a
 * frightened user to register an account first.
 *
 * ## What it is and is not
 *
 * It is a per-session random value, stored server-side in the session and
 * compared by hash against the submission row. It stops casual enumeration and
 * cross-session posting.
 *
 * It is **not** an identity or an authorisation control. Someone can clear
 * their session and get a new token, and someone determined can submit a
 * verdict and then leave feedback on it. That is fine: the design assumes
 * feedback is adversarial and answers that with moderation, not with
 * authentication. This control stops the easy, high-volume attack; the gate in
 * TrainingSample stops the consequential one.
 */
class SessionTokenService
{
    private const SESSION_KEY = 'scamsense_token';

    /**
     * The current session's token, creating one if needed.
     */
    public function token(): string
    {
        $existing = session()->get(self::SESSION_KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = (string) Str::uuid().Str::random(32);
        session()->put(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * The value stored on a submission.
     *
     * Hashed rather than stored raw: a database leak should not hand someone
     * the tokens needed to forge feedback against historical verdicts.
     */
    public function hash(): string
    {
        return hash('sha256', $this->token());
    }

    /**
     * Whether a stored hash belongs to the current session.
     *
     * Compared with `hash_equals` rather than `===`. The comparison is not
     * secret-dependent in an obvious way, but a timing oracle on a session
     * token is the sort of thing a security-minded panel will ask about, and
     * the constant-time version costs nothing.
     */
    public function matches(?string $storedHash): bool
    {
        if ($storedHash === null || $storedHash === '') {
            return false;
        }

        return hash_equals($storedHash, $this->hash());
    }
}
