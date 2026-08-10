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
}
