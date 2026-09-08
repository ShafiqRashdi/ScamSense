<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\SampleSubmissionSeeder;

/*
 | The reports page.
 |
 | It exists because the dashboard had grown to nine panels, five of which were
 | reference rather than anything an administrator would act on today. The split
 | is by the question each page answers: the dashboard asks "is anything wrong,
 | and is there anything for me to do?"; this page asks "what does the data say,
 | and can I take it away?"
 |
 | The model-metrics tests moved here with the panel they cover. They were
 | pointed at /admin, and leaving them there would have left four tests passing
 | against a page that no longer renders the thing they describe.
 */

it('keeps reports behind authentication', function (): void {
    $this->get('/admin/reports')->assertRedirect('/login');
});

it('refuses a signed-in user who cannot moderate reports', function (): void {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($viewer)->get('/admin/reports')->assertForbidden();
});

it('carries every panel the dashboard gave up', function (): void {
    $this->seed(SampleSubmissionSeeder::class);

    $this->actingAs(admin())->get('/admin/reports')
        ->assertOk()
        ->assertSee('Model performance')
        ->assertSee('Most frequent indicators')
        ->assertSee('Threat-intel cache')
        ->assertSee('Retraining set')
        ->assertSee('Verdicts by model version');
});

it('offers a download beside the data', function (): void {
    // A table of five hundred indicator codes is more useful as a file than as
    // something to scroll, which is the whole argument for this page.
    $this->actingAs(admin())->get('/admin/reports')
        ->assertOk()
        ->assertSee(route('admin.exports.submissions'), escape: false)
        ->assertSee(route('admin.exports.feedback'), escape: false)
        ->assertSee(route('admin.exports.training'), escape: false)
        ->assertSee(route('admin.exports.indicators'), escape: false)
        ->assertSee(route('admin.exports.model-versions'), escape: false)
        ->assertSee(route('admin.exports.model-metrics'), escape: false);
});

it('warns that a downloaded file has left the retention policy', function (): void {
    // The submissions export carries text a third party wrote. It is redacted,
    // but a copy on a laptop is no longer covered by the scheduled purge, and
    // saying so is cheaper than discovering it in a PDPA question.
    $this->actingAs(admin())->get('/admin/reports')
        ->assertSee('Downloaded files leave the retention policy behind');
});

/*
|--------------------------------------------------------------------------
| Model metrics — moved here with the panel
|--------------------------------------------------------------------------
*/

it('shows only serving models, with retired runs behind a disclosure', function (): void {
    /*
     | ModelMetricsReader returns every directory with a metrics.json, which is
     | ten today and grows with each retrain. Rendering all of them made this
     | panel more than half the page while only two were serving traffic.
     */
    modelsDirectoryWithRetiredRuns();

    $html = $this->actingAs(admin())->get('/admin/reports')->assertOk()->getContent();

    $serving = strpos($html, 'rf-url-v9.9.9-serving');
    $disclosure = strpos($html, '<details');

    expect($serving)->not->toBeFalse('the serving model must be visible')
        ->and($disclosure)->not->toBeFalse('retired runs need a disclosure')
        ->and($serving)->toBeLessThan($disclosure, 'serving models come before the disclosure')
        ->and($html)->toContain('Show superseded and experimental runs (2)');
});

it('keeps retired runs reachable rather than dropping them', function (): void {
    // They are real evidence for the report; the point is that they are not
    // what anyone opens this page to see.
    modelsDirectoryWithRetiredRuns();

    $this->actingAs(admin())->get('/admin/reports')
        ->assertSee('rf-url-v0.1.0-old')
        ->assertSee('rf-url-v0.2.0-randomsplit');
});

it('warns when no model is serving rather than showing nothing', function (): void {
    $dir = sys_get_temp_dir().'/scamsense-models-'.uniqid();
    mkdir($dir.'/rf-url-v0.1.0-old', 0777, true);
    file_put_contents($dir.'/rf-url-v0.1.0-old/metrics.json', json_encode(['test' => ['f1_phishing' => 0.9]]));
    config()->set('scamsense.models_path', $dir);
    config()->set('scamsense.shipped_models.url', 'nothing-matches-this');
    config()->set('scamsense.shipped_models.message', 'nor-this');

    $this->actingAs(admin())->get('/admin/reports')->assertSee('No serving model found');
});

it('escapes a model name rather than rendering it', function (): void {
    $dir = sys_get_temp_dir().'/scamsense-metrics-'.uniqid();
    mkdir($dir.'/rf-url-<script>alert(1)</script>', 0777, true);
    file_put_contents(
        $dir.'/rf-url-<script>alert(1)</script>/metrics.json',
        json_encode(['test' => ['f1_phishing' => 0.5]]),
    );
    config()->set('scamsense.models_path', $dir);

    $this->actingAs(admin())
        ->get('/admin/reports')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false);
})->skipOnWindows();
