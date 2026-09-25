<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests get RefreshDatabase by default (AGENTS.md §6). Unit tests do
| not touch the database at all — services such as Redactor and the DTO
| constructors must be testable without one, which is the point of the
| service layer in the first place.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
|
| These live here rather than in one test file because the admin area is now
| three pages across three files, and a helper defined in whichever file Pest
| happened to load first is a helper that breaks when a file is renamed.
|
*/

/** A user who can reach the admin area. */
function admin(): User
{
    return User::factory()->admin()->create();
}

/**
 * Writes a models directory holding one serving artefact and several retired
 * ones, which is the real shape: ten directories, two of them serving.
 */
function modelsDirectoryWithRetiredRuns(): string
{
    $dir = sys_get_temp_dir().'/scamsense-models-'.uniqid();

    foreach (['rf-url-v9.9.9-serving', 'rf-url-v0.1.0-old', 'rf-url-v0.2.0-randomsplit'] as $name) {
        mkdir($dir.'/'.$name, 0777, true);
        file_put_contents(
            $dir.'/'.$name.'/metrics.json',
            json_encode(['test' => ['f1_phishing' => 0.9, 'threshold' => 0.5]]),
        );
    }

    config()->set('scamsense.models_path', $dir);
    config()->set('scamsense.shipped_models.url', 'rf-url-v9.9.9-serving');
    config()->set('scamsense.shipped_models.message', 'not-present');

    return $dir;
}
