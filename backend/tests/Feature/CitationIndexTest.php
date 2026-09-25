<?php

declare(strict_types=1);

/*
 | docs/citation-index.md maps every claim in Chapters 4 and 5 to the file that
 | supports it. Its whole value is that a reader can follow a reference and find
 | something there, so an index carrying a dead path is worse than no index: it
 | asserts evidence exists where none does.
 |
 | Two of its references were wrong when it was first written — a figure cited
 | to ADR 0006 that lives in RESULTS.md, and a corpus figure cited to
 | DATASETS.md that lives in the artefact's metrics.json. Both were caught by
 | checking rather than by reading. These tests keep that checking automatic.
 */

/** Repository root — the docs and ml-service trees sit beside `backend/`. */
function repoPath(string $relative = ''): string
{
    return dirname(base_path()).($relative === '' ? '' : DIRECTORY_SEPARATOR.$relative);
}

/**
 * Every path-looking reference in the index.
 *
 * Matches backticked tokens that contain a slash or end in a known extension,
 * which is what distinguishes a file reference from a column name or a code
 * identifier also written in backticks.
 *
 * @return list<string>
 */
function citedPaths(): array
{
    $doc = file_get_contents(repoPath('docs/citation-index.md'));

    preg_match_all('/`([A-Za-z0-9_\-.\/]+\.(?:md|json|php|py))`/', $doc, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * Resolve a reference written the way a reader would read it.
 *
 * The index cites files by the shortest unambiguous name — `RESULTS.md` rather
 * than `docs/RESULTS.md`, `models/…/metrics.json` rather than the full
 * ml-service path — because that is how they are referred to in conversation
 * and in the report. This tries the roots those shorthands imply.
 */
function resolveCited(string $path): ?string
{
    $roots = [
        '',
        'docs/',
        'docs/adr/',
        'ml-service/',
        'ml-service/training/',
        'ml-service/tests/',
        'backend/',
        'backend/tests/',
    ];

    foreach ($roots as $root) {
        $candidate = repoPath($root.$path);
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

it('has a citation index', function (): void {
    expect(file_exists(repoPath('docs/citation-index.md')))->toBeTrue();
});

it('cites only files that exist', function (): void {
    $missing = [];

    foreach (citedPaths() as $path) {
        if (resolveCited($path) === null) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
});

it('cites a decision record for both dataset rejections', function (): void {
    /*
     | The two rejections are the corpus-audit story and are weaker apart: one
     | corpus could produce no metric at all, the other would have produced a
     | flattering meaningless one. ADR 0005 covered the first; the second lived
     | only in DATASETS.md until ADR 0011.
     */
    expect(file_exists(repoPath('docs/adr/0005-message-corpus-cannot-support-evaluation.md')))->toBeTrue()
        ->and(file_exists(repoPath('docs/adr/0011-reject-the-malay-email-corpus.md')))->toBeTrue();
});

it('records the deployment-boundary defects somewhere citable', function (): void {
    // Four defects that made the running system wrong while the suite was
    // fully green. The point is the class, not the instances.
    $doc = repoPath('docs/deployment-boundary-defects.md');

    expect(file_exists($doc))->toBeTrue();

    $text = file_get_contents($doc);

    foreach (['trailing space', 'held the port', 'Vite', 'ADMIN_PASSWORD'] as $defect) {
        expect($text)->toContain($defect);
    }
});

it('names its own gaps rather than leaving them implied', function (): void {
    /*
     | The last section lists claims the report might want to make and cannot
     | yet support. It is the part most likely to be quietly dropped once it
     | becomes inconvenient, which is exactly when it matters.
     */
    $text = file_get_contents(repoPath('docs/citation-index.md'));

    expect($text)->toContain('Claims with no source yet')
        ->and($text)->toContain('No participants recruited');
});
