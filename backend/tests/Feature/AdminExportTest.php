<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Enums\RiskLevel;
use App\Enums\SubmissionType;
use App\Enums\UserRole;
use App\Enums\VerdictLabel;
use App\Models\Feedback;
use App\Models\Submission;
use App\Models\User;
use App\Models\Verdict;
use App\Services\Admin\CsvExporter;
use Illuminate\Testing\TestResponse;

/*
 | Downloads from the reports page.
 |
 | The interesting test in this file is the formula-injection one. ScamSense
 | exports attacker-written text by design -- that is the product -- and a cell
 | beginning `=`, `+`, `-` or `@` is executed as a formula by Excel, LibreOffice
 | and Google Sheets. `=cmd|'/c calc'!A1` arriving as a WhatsApp message becomes
 | code execution the moment an administrator opens the export.
 |
 | It is the same class of problem as the URL defanging on the result page: a
 | second rendering context with its own escaping rule. The browser rule is
 | "never put it in an href"; the spreadsheet rule is "prefix it with a quote".
 | fputcsv does neither -- it quotes for CSV parsing, which is unrelated.
 */

/** Read a streamed download into a string. */
function download(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

function submissionSaying(string $text): Submission
{
    $submission = Submission::factory()->create([
        'type' => SubmissionType::Message,
        'content_redacted' => $text,
    ]);

    Verdict::factory()->for($submission)->create([
        'label' => VerdictLabel::Scam,
        'risk_level' => RiskLevel::High,
    ]);

    return $submission;
}

/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
*/

it('keeps every export behind authentication', function (string $route): void {
    $this->get(route($route))->assertRedirect('/login');
})->with([
    'admin.exports.all',
    'admin.exports.submissions',
    'admin.exports.feedback',
    'admin.exports.training',
    'admin.exports.indicators',
    'admin.exports.model-versions',
    'admin.exports.model-metrics',
]);

it('refuses an export to a signed-in user who cannot moderate', function (): void {
    // Exports carry more than the pages do -- every row rather than a top ten.
    // A viewer who cannot open the reports page must not be able to download
    // the data behind it by guessing the URL.
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($viewer)->get(route('admin.exports.submissions'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Formula injection — the reason this exporter is not a fputcsv wrapper
|--------------------------------------------------------------------------
*/

it('neutralises a formula so a spreadsheet reads it as text', function (string $payload): void {
    $csv = app(CsvExporter::class)->neutralise($payload);

    expect($csv)->toBe("'".$payload)
        ->and($csv[0])->toBe("'");
})->with([
    '=cmd|\'/c calc\'!A1',
    '=1+1',
    '+1234567890',
    '-2+3+cmd|\' /c calc\'!A1',
    '@SUM(1+1)*cmd|\'/c calc\'!A1',
    "\tSome text after a tab",
    "\rSome text after a carriage return",
]);

it('leaves an ordinary scam message untouched', function (): void {
    // The defence must not mangle the corpus. A quote prefixed onto every cell
    // would corrupt the exported text for the retraining work this file exists
    // to support.
    $message = 'TAHNIAH! Anda memenangi RM5,000. Klik pautan untuk menuntut.';

    expect(app(CsvExporter::class)->neutralise($message))->toBe($message);
});

it('carries a formula payload through a real export as text', function (): void {
    /*
     | The unit assertions above cover the rule; this covers the wiring. A
     | neutralise() nobody calls is a defence that exists only in a test.
     */
    submissionSaying('=cmd|\'/c calc\'!A1 sila sahkan akaun anda');

    $csv = download($this->actingAs(admin())->get(route('admin.exports.submissions'))->assertOk());

    expect($csv)->toContain("'=cmd")
        ->and($csv)->not->toContain(',=cmd');
});

/*
|--------------------------------------------------------------------------
| Content
|--------------------------------------------------------------------------
*/

it('exports the redacted column, because there is no other one', function (): void {
    // Redaction runs before the row is written rather than on the way out, so
    // an export cannot leak what was never stored. This asserts the export
    // reads that column and not some reconstructed original.
    submissionSaying('Hubungi [NO. TEL DILINDUNGI] sekarang');

    $csv = download($this->actingAs(admin())->get(route('admin.exports.submissions'))->assertOk());

    expect($csv)->toContain('[NO. TEL DILINDUNGI]')
        ->and($csv)->toContain('content_redacted');
});

it('joins each submission to the verdict it produced', function (): void {
    submissionSaying('Akaun anda akan disekat.');

    $csv = download($this->actingAs(admin())->get(route('admin.exports.submissions'))->assertOk());

    expect($csv)->toContain('model_version')
        ->and($csv)->toContain('high');
});

it('exports the moderation trail with the approver named', function (): void {
    // The export that answers "prove no user feedback reached a model without a
    // human approving it", which a panel is entitled to ask.
    $moderator = User::factory()->admin()->create(['name' => 'Siti Moderator']);
    $submission = submissionSaying('Bil elektrik anda.');

    Feedback::factory()->create([
        'verdict_id' => $submission->latestVerdict->id,
        'status' => FeedbackStatus::Approved,
        'moderated_by' => $moderator->id,
        'moderated_at' => now(),
    ]);

    $csv = download($this->actingAs(admin())->get(route('admin.exports.feedback'))->assertOk());

    expect($csv)->toContain('Siti Moderator')
        ->and($csv)->toContain('moderated_at');
});

it('starts every csv with a byte-order mark so Excel reads Malay correctly', function (): void {
    // Without it Excel on Windows falls back to the system code page and every
    // accented character and redaction marker becomes mojibake. The corpus is
    // Malaysian; this is not an edge case.
    submissionSaying('Bungkusan anda tertahan di kastam.');

    $csv = download($this->actingAs(admin())->get(route('admin.exports.submissions'))->assertOk());

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
});

it('serves model metrics as json so a figure keeps its qualifier', function (): void {
    // Flattening these into CSV columns would be the first step towards quoting
    // an F1 without its caveat, which is what ModelMetricsReader exists to stop.
    modelsDirectoryWithRetiredRuns();

    $response = $this->actingAs(admin())->get(route('admin.exports.model-metrics'))->assertOk();

    expect($response->headers->get('content-disposition'))
        ->toContain('scamsense-model-metrics-'.now()->format('Y-m-d').'.json')
        ->and($response->headers->get('content-type'))->toContain('application/json');
});

it('never lets an export be cached for whoever asks next', function (string $route): void {
    // The zip is included because it once went out as `Cache-Control: public`:
    // Laravel's file download defaults to public, and only the single-file
    // routes set no-store. It carries every export at once.
    $response = $this->actingAs(admin())->get(route($route))->assertOk();

    expect($response->headers->get('cache-control'))->toContain('no-store')
        ->and($response->headers->get('cache-control'))->not->toContain('public');
})->with(['admin.exports.submissions', 'admin.exports.all']);

/*
|--------------------------------------------------------------------------
| One button
|--------------------------------------------------------------------------
*/

it('offers everything as one download', function (): void {
    /*
     | Six buttons in a row was six decisions to make before getting any data.
     | The files are kilobytes and the answer is almost always "all of them".
     */
    submissionSaying('Bungkusan anda tertahan di kastam.');

    $response = $this->actingAs(admin())->get(route('admin.exports.all'))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = basename((string) $zip->getNameIndex($i));
    }
    $zip->close();

    expect($names)->toHaveCount(6);

    foreach (['submissions', 'feedback', 'training-set', 'indicators', 'model-versions'] as $expected) {
        expect(collect($names)->contains(fn (string $n): bool => str_contains($n, $expected)))
            ->toBeTrue("the archive should contain the {$expected} export");
    }

    expect(collect($names)->contains(fn (string $n): bool => str_ends_with($n, '.json')))
        ->toBeTrue('model metrics travel as JSON so a figure keeps its qualifier');
});

it('writes the same bytes into the zip as it streams on its own', function (): void {
    /*
     | The reason ExportController describes each dataset once instead of twice.
     | Two code paths writing "the submissions export" is how the zip quietly
     | starts carrying a different set of columns from the CSV beside it -- and
     | the formula neutralisation is one of those columns' properties.
     */
    submissionSaying("=cmd|'/c calc'!A1 sila sahkan akaun anda");

    $loose = download($this->actingAs(admin())->get(route('admin.exports.submissions'))->assertOk());

    $response = $this->actingAs(admin())->get(route('admin.exports.all'))->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $inside = (string) $zip->getFromName('scamsense-submissions-'.now()->format('Y-m-d').'.csv');
    $zip->close();

    expect($inside)->toBe($loose)
        ->and($inside)->toContain("'=cmd");
});

it('does not leave exported personal data lying in storage', function (): void {
    // The archive writes each CSV to disk before zipping it. Leaving those
    // behind would be a folder of redacted-but-still-personal text sitting in
    // storage with no retention policy over it at all.
    submissionSaying('Bil elektrik anda.');

    // Earlier runs of this suite left files here before the bug below was
    // found, and a stale directory would make this pass or fail for the wrong
    // reason.
    foreach (glob(storage_path('app/exports/*')) ?: [] as $stale) {
        is_dir($stale) ? array_map('unlink', glob($stale.'/*') ?: []) : @unlink($stale);
        is_dir($stale) && @rmdir($stale);
    }

    $response = $this->actingAs(admin())->get(route('admin.exports.all'))->assertOk();
    ob_start();
    $response->baseResponse->sendContent();
    ob_end_clean();

    /*
     | This caught a real defect. The first version globbed the workspace to
     | delete the loose CSVs and matched nothing, so every download left a
     | folder of redacted-but-still-personal text in storage with no retention
     | policy over it. Deleting by the paths just written fixed it.
     */
    $csvs = glob(storage_path('app/exports/*/*.csv')) ?: [];
    $dirs = array_filter(glob(storage_path('app/exports/*')) ?: [], 'is_dir');

    expect($csvs)->toBe([])
        ->and($dirs)->toBe([], 'the workspace directory should be removed too');
});

it('flushes the response only when it is writing to the response', function (): void {
    /*
     | "Download everything" once returned a blank page while every single
     | download worked. CsvExporter called PHP's flush() after each chunk;
     | flush() sends the RESPONSE and its headers, not the file being written,
     | so the zip's own headers could no longer be sent ("headers already
     | sent"). No other test here could see it: a test run sends no real
     | headers, so the archive test passed throughout.
     |
     | The rule is therefore pinned directly: only php://output is the
     | response. A file on its way into the zip is not.
     */
    $file = fopen('php://temp', 'w+b');
    $disk = tmpfile();
    $output = fopen('php://output', 'wb');

    expect(CsvExporter::streamsToResponse($file))->toBeFalse()
        ->and(CsvExporter::streamsToResponse($disk))->toBeFalse()
        ->and(CsvExporter::streamsToResponse($output))->toBeTrue();

    fclose($file);
    fclose($disk);
    fclose($output);
});
