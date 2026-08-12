<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The risk band shown to the user.
 *
 * This is stored on the verdict rather than derived from the confidence at read
 * time. Deriving it would mean that changing a threshold silently rewrites the
 * risk level of every historical verdict, including the ones already
 * screenshotted for Chapter 4 — the panel is entitled to ask which model and
 * which thresholds produced a given screenshot, and a stored band answers that.
 */
enum RiskLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Unknown = 'unknown';

    /**
     * A Bootstrap contextual colour.
     *
     * Never use this alone to convey risk. WCAG 1.4.1 forbids colour as the only
     * carrier of meaning, and the users this project exists for are frequently
     * older adults on cheap screens. Always pair it with {@see self::icon()} and
     * the translated text label (AGENTS.md O2).
     */
    public function colour(): string
    {
        return match ($this) {
            self::High => 'danger',
            self::Medium => 'warning',
            self::Low => 'success',
            self::Unknown => 'secondary',
        };
    }

    /**
     * A Bootstrap Icons name, paired with the colour so risk is never
     * conveyed by hue alone.
     */
    public function icon(): string
    {
        return match ($this) {
            self::High => 'exclamation-octagon-fill',
            self::Medium => 'exclamation-triangle-fill',
            self::Low => 'check-circle-fill',
            self::Unknown => 'question-circle-fill',
        };
    }

    /**
     * An inline SVG for the icon.
     *
     * Inlined rather than pulled from an icon font because the icon is the
     * non-colour carrier of the risk level. If a webfont fails to load — slow
     * connection, blocked CDN, a strict CSP — an icon-font glyph degrades to
     * nothing and the verdict would be signalled by colour alone, which is the
     * exact WCAG 1.4.1 failure this pairing exists to prevent.
     *
     * Marked aria-hidden at the call site: the adjacent text label already
     * announces the meaning, so a screen reader would otherwise say it twice.
     */
    public function iconSvg(): string
    {
        $path = match ($this) {
            // Octagon with an exclamation — "stop".
            self::High => 'M11.46.146A.5.5 0 0 0 11.107 0H4.893a.5.5 0 0 0-.353.146L.146 4.54A.5.5 0 0 0 0 4.893v6.214a.5.5 0 0 0 .146.353l4.394 4.394a.5.5 0 0 0 .353.146h6.214a.5.5 0 0 0 .353-.146l4.394-4.394a.5.5 0 0 0 .146-.353V4.893a.5.5 0 0 0-.146-.353zM8 4a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 4m.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2',
            // Triangle with an exclamation — "caution".
            self::Medium => 'M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2',
            // Circle with a tick — "no warning signs".
            self::Low => 'M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z',
            // Circle with a question mark — "could not check".
            self::Unknown => 'M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286m1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94',
        };

        return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="'.$path.'"/></svg>';
    }
}
