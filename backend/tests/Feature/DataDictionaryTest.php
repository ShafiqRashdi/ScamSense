<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 | docs/data-dictionary.md is pasted into Chapter 4 as Tables 4.1–4.7. A data
 | dictionary that has drifted from the schema is worse than none: an examiner
 | checks it by running DESCRIBE, and one wrong row makes the rest suspect.
 |
 | The Chapter 4 draft had seven such errors, including a whole table
 | (`model_versions`) and two arguments resting on columns that were never
 | built. These tests pin the shape the document describes, so a future
 | migration that contradicts it fails here rather than in a viva.
 */

/**
 * Every column name the dictionary lists, across all its tables.
 *
 * Flattened rather than per-table because column names are distinctive enough
 * here that a flat set answers the only question these tests ask — is this
 * column described anywhere — without parsing the document's headings.
 *
 * @return list<string>
 */
function documentedColumns(): array
{
    $doc = file_get_contents(base_path('../docs/data-dictionary.md'));

    // Rows look like: | `column_name` | TYPE | CONSTRAINT | Description |
    preg_match_all('/^\| `([a-z_]+)` \|/m', $doc, $matches);

    return array_values(array_unique($matches[1]));
}

it('documents every application table that exists', function (): void {
    $tables = [
        'submissions', 'verdicts', 'indicators', 'threat_intel_cache',
        'feedback', 'training_samples', 'users',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should exist");
    }
});

it('has no model_versions table, whatever the draft claimed', function (): void {
    /*
     | The draft carried a Table 4.7 for it and an ERD sentence reading "a model
     | version has many verdicts". Traceability is by denormalisation instead:
     | verdicts.model_version is a plain string with no foreign key, written
     | once so a verdict stays attributable even to an artefact later deleted
     | from the models directory.
     */
    expect(Schema::hasTable('model_versions'))->toBeFalse();
    expect(Schema::hasColumn('verdicts', 'model_version'))->toBeTrue();
});

it('stores one fused confidence, not separate model and threat-intel scores', function (): void {
    // The draft argued at length for keeping them apart. The contribution of
    // each is recoverable from `indicators.source` instead.
    expect(Schema::hasColumn('verdicts', 'confidence'))->toBeTrue()
        ->and(Schema::hasColumn('verdicts', 'ml_score'))->toBeFalse()
        ->and(Schema::hasColumn('verdicts', 'threat_intel_score'))->toBeFalse()
        ->and(Schema::hasColumn('indicators', 'source'))->toBeTrue();
});

it('keeps split assignment out of the database', function (): void {
    // It lives in split_indices.json beside each artefact, so a held-out
    // partition belongs to one trained model rather than being shared globally
    // across model versions.
    expect(Schema::hasColumn('training_samples', 'split'))->toBeFalse();
});

it('matches the column names the dictionary uses', function (): void {
    $expected = [
        'submissions' => ['type', 'content_redacted', 'content_hash', 'ip_hash', 'session_token_hash'],
        'verdicts' => ['submission_id', 'label', 'risk_level', 'confidence', 'model_version', 'is_partial', 'partial_reason', 'inference_ms'],
        'indicators' => ['verdict_id', 'code', 'weight', 'evidence', 'source'],
        'threat_intel_cache' => ['provider', 'url_hash', 'response', 'is_malicious', 'fetched_at', 'expires_at'],
        'feedback' => ['verdict_id', 'agrees', 'comment', 'status', 'ip_hash', 'moderated_by', 'moderated_at', 'moderation_note'],
        'training_samples' => ['feedback_id', 'submission_id', 'origin', 'type', 'content', 'content_hash', 'label', 'approved_by', 'approved_at', 'dataset_version', 'exported_at'],
        'users' => ['name', 'email', 'role', 'password', 'remember_token'],
    ];

    foreach ($expected as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeTrue("{$table}.{$column}");
        }
    }
});

it('has no column the dictionary invented', function (): void {
    // The draft's phantom fields. Each would have been checked with DESCRIBE.
    expect(Schema::hasColumn('submissions', 'language'))->toBeFalse()
        ->and(Schema::hasColumn('feedback', 'reviewed_by'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'email_verified_at'))->toBeFalse();
});

it('names the tables the dictionary names', function (): void {
    // The draft used the design names rather than the built ones.
    expect(Schema::hasTable('verdict_indicators'))->toBeFalse()
        ->and(Schema::hasTable('indicators'))->toBeTrue()
        ->and(Schema::hasTable('threat_intel_lookups'))->toBeFalse()
        ->and(Schema::hasTable('threat_intel_cache'))->toBeTrue();
});

it('documents every column that exists, with none left out', function (): void {
    /*
     | The direction the other tests do not cover: a migration adding a column
     | that never reaches the document. That is how a dictionary rots — not by
     | describing something absent, but by quietly omitting something present.
     */
    $documented = documentedColumns();

    $tables = [
        'submissions', 'verdicts', 'indicators', 'threat_intel_cache',
        'feedback', 'training_samples', 'users',
    ];

    $missing = [];

    foreach ($tables as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            if (! in_array($column, $documented, true)) {
                $missing[] = "{$table}.{$column}";
            }
        }
    }

    expect($missing)->toBe([]);
});
