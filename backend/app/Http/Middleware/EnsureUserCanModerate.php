<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Explicit role check for the admin area.
 *
 * AGENTS.md requires a role check on every admin route, separate from `auth`.
 * The two answer different questions — `auth` says somebody is signed in, this
 * says they are allowed to be here — and collapsing them is how a future
 * public-account feature would silently grant moderation rights to everyone.
 */
class EnsureUserCanModerate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canModerate()) {
            abort(403);
        }

        return $next($request);
    }
}
