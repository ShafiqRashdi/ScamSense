<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response security headers (security review finding 3).
 *
 * ScamSense deliberately renders attacker-authored strings — that is the
 * product. Escaping is correct today and the payload tests prove it, but these
 * headers are what limits the damage when an escaping bug eventually appears,
 * and this codebase will keep growing.
 *
 * The CSP can be genuinely strict rather than aspirational because the
 * application loads nothing third-party: no CDN scripts, no web fonts, no
 * analytics. Vite output is same-origin, and the only inline content is the
 * risk icons, which are inline SVG rather than inline script.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headers($request) as $name => $value) {
            // Never overwrite a header a controller set deliberately.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [
            /*
             * `default-src 'self'` is achievable here because nothing external
             * is loaded. `object-src 'none'` and `base-uri 'none'` close the
             * plugin and base-tag injection routes; `frame-ancestors 'none'`
             * is the modern X-Frame-Options and matters because a verdict page
             * framed inside an attacker's site is a convincing way to fake a
             * "safe" result to a user.
             *
             * `form-action 'self'` stops an injected form posting a user's
             * input somewhere else.
             */
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                // Bootstrap sets inline styles on progress bars and the chart.
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                // No submitted host may ever be contacted from the page.
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]),

            // Submitted content is echoed back, so a browser must never be
            // allowed to guess a type for it.
            'X-Content-Type-Options' => 'nosniff',

            // Legacy equivalent of frame-ancestors, for older browsers.
            'X-Frame-Options' => 'DENY',

            // A submitted URL must never appear in a Referer header sent
            // onward. The public layout also sets this as a meta tag; the
            // header is authoritative and applies before the document parses.
            'Referrer-Policy' => 'no-referrer',

            // Nothing here needs a camera, microphone or location.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
        ];

        /*
         * HSTS only over HTTPS. Sending it over plain HTTP is meaningless, and
         * setting it in local development would pin a browser to https on
         * localhost for six months — a genuinely annoying way to break your own
         * machine.
         */
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }
}
