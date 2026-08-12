<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the visitor's chosen language.
 *
 * The value is validated against a fixed list here as well as at the point it
 * is stored. Session data is not user input in the usual sense, but it is
 * attacker-influenced, and `App::setLocale()` with an arbitrary string would
 * let a crafted value reach the filesystem through the translation loader.
 */
class SetLocale
{
    private const SUPPORTED = ['en', 'ms'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->session()->get('locale', config('app.locale'));

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
