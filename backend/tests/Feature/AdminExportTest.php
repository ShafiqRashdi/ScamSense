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

    $this->actingAs(admin())->get(route('admin.exports.model-metrics'))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="scamsense-model-metrics-'.now()->format('Y-m-d').'.json"');
});

it('never lets an export be cached for whoever asks next', function (): void {
    $response = $this->actingAs(admin())->get(route('admin.exports.submissions'))->assertOk();

    expect($response->headers->get('cache-control'))->toContain('no-store');
});
