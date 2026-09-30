<?php

declare(strict_types=1);

/*
 | AGENTS.md §6: "A test suite that burns VirusTotal quota is broken." It did,
 | on 2026-09-29, because phpunit.xml let the real keys through from .env. The
 | keys are now blanked there; this fails if that line is ever removed.
 */

it('runs the suite with no real threat-intel keys', function (): void {
    expect(config('services.virustotal.key'))->toBeEmpty();
    expect(config('services.safe_browsing.key'))->toBeEmpty();
});
