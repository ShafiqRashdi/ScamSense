<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Enums\RiskLevel;
use App\Enums\UserRole;
use App\Models\Feedback;
use App\Models\Indicator;
use App\Models\Submission;
use App\Models\ThreatIntelCache;
use App\Models\TrainingSample;
use App\Models\User;
use App\Models\Verdict;
use App\Services\Admin\DashboardService;
use App\Services\Admin\ModelMetricsReader;
use Database\Seeders\FeedbackQueueSeeder;
use Database\Seeders\SampleSubmissionSeeder;

/*
|--------------------------------------------------------------------------
| Access control — AGENTS.md requires a role check on every admin route
|--------------------------------------------------------------------------
*/

it('keeps the dashboard behind authentication', function (): void {
    $this->get('/admin')->assertRedirect('/login');
});

it('refuses a signed-in user who cannot moderate', function (): void {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($viewer)->get('/admin')->assertForbidden();
});

it('lets an administrator in', function (): void {
    $this->actingAs(admin())->get('/admin')->assertOk()->assertSee('Dashboard');
});

it('sends a user to the dashboard after signing in', function (): void {
    $user = admin();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard', absolute: false));
});

/*
|--------------------------------------------------------------------------
| Submission volume
|--------------------------------------------------------------------------
*/

it('zero-fills days with no traffic', function (): void {
    /*
     * A chart that silently skips empty days compresses a quiet week into a
     * busy-looking line, which would misrepresent adoption in a report
     * screenshot.
     */
    Submission::factory()->create(['created_at' => now()->subDays(3)]);

    $volume = app(DashboardService::class)->submissionVolume(7);

    expect($volume['series'])->toHaveCount(7)
        ->and($volume['total'])->toBe(1)
        ->and(collect($volume['series'])->where('total', 0)->count())->toBe(6);
});

it('counts submissions separately by type', function (): void {
    Submission::factory()->count(3)->create(['created_at' => now()]);
    Submission::factory()->url()->count(2)->create(['created_at' => now()]);

    $today = collect(app(DashboardService::class)->submissionVolume(7)['series'])->last();

    expect($today['message'])->toBe(3)
        ->and($today['url'])->toBe(2)
        ->and($today['total'])->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Verdict distribution and degradation
|--------------------------------------------------------------------------
*/

it('reports the verdict distribution by risk band', function (): void {
    Verdict::factory()->count(2)->create(['risk_level' => RiskLevel::High]);
    Verdict::factory()->create(['risk_level' => RiskLevel::Medium]);

    $risk = collect(app(DashboardService::class)->verdictDistribution()['risk'])
        ->keyBy('level');

    expect($risk['high']['count'])->toBe(2)
        ->and($risk['medium']['count'])->toBe(1)
        ->and($risk['low']['count'])->toBe(0);
});

it('reports the degraded-verdict rate and its reasons', function (): void {
    Verdict::factory()->count(3)->create();
    Verdict::factory()->partial()->create();

    $partial = app(DashboardService::class)->partialRate();

    expect($partial['partial'])->toBe(1)
        ->and($partial['rate'])->toBe(0.25)
        ->and($partial['reasons'])->toHaveKey('ml_service_unavailable');
});

it('counts how many verdicts the verified-address gate capped', function (): void {
    $verdict = Verdict::factory()->create();
    Indicator::factory()->for($verdict)->create(['code' => 'verified_institutional_domain']);

    expect(app(DashboardService::class)->verdictDistribution()['gated'])->toBe(1);
});

it('separates indicator counts by source', function (): void {
    // ADR 0007: the gate must stay distinguishable from model output. An
    // aggregate that mixed them would be exactly the conflation it forbids.
    $verdict = Verdict::factory()->create();
    Indicator::factory()->for($verdict)->fromModel()->create(['code' => 'suspicious_tld']);
    Indicator::factory()->for($verdict)->create([
        'code' => 'verified_institutional_domain',
        'source' => 'registry',
    ]);

    $indicators = collect(app(DashboardService::class)->topIndicators())->keyBy('code');

    expect($indicators['suspicious_tld']['is_model'])->toBeTrue()
        ->and($indicators['verified_institutional_domain']['is_model'])->toBeFalse()
        ->and($indicators['verified_institutional_domain']['source'])->toBe('registry');
});

/*
|--------------------------------------------------------------------------
| Moderation, retraining set, threat intel
|--------------------------------------------------------------------------
*/

it('summarises the moderation queue by status', function (): void {
    Feedback::factory()->count(2)->create(['status' => FeedbackStatus::Pending]);
    Feedback::factory()->approved(admin())->create();

    $queue = app(DashboardService::class)->moderationQueue();

    expect($queue['pending'])->toBe(2)->and($queue['approved'])->toBe(1);
});

it('splits the retraining set by provenance', function (): void {
    // The v1/v2 story in Chapter 4: how much came from users.
    TrainingSample::factory()->count(2)->create();

    $training = app(DashboardService::class)->trainingSet();

    expect($training['corpus'])->toBe(2)
        ->and($training['community'])->toBe(0)
        ->and($training['awaiting_export'])->toBe(2);
});

it('reports the threat-intel cache hit rate', function (): void {
    ThreatIntelCache::factory()->count(3)->create();
    ThreatIntelCache::factory()->expired()->create([
        'url_hash' => ThreatIntelCache::hashUrl('https://expired.example/x'),
    ]);

    $cache = app(DashboardService::class)->threatIntelCache();

    expect($cache['total'])->toBe(4)
        ->and($cache['fresh'])->toBe(3)
        ->and($cache['hit_rate'])->toBe(0.75);
});

it('groups verdicts by the model version that produced them', function (): void {
    // Traceability: a verdict is only reproducible if the model is known.
    Verdict::factory()->count(2)->create(['model_version' => 'rf-url-v2.0.0-pathnorm']);
    Verdict::factory()->partial()->create();

    $byVersion = app(DashboardService::class)->verdictsByModelVersion();

    expect($byVersion['rf-url-v2.0.0-pathnorm'])->toBe(2)
        ->and($byVersion)->toHaveKey('(no model — partial)');
});

/*
|--------------------------------------------------------------------------
| Model metrics come from the committed artefacts, never from the database
|--------------------------------------------------------------------------
*/

it('reads model performance from metrics.json', function (): void {
    $dir = sys_get_temp_dir().'/scamsense-metrics-'.uniqid();
    mkdir($dir.'/rf-url-vtest', 0777, true);
    file_put_contents($dir.'/rf-url-vtest/metrics.json', json_encode([
        'trained_at' => '2026-08-12T00:00:00+00:00',
        'split_strategy' => 'domain',
        'REPORTING_QUALIFIER' => 'F1 on a test corpus.',
        'test' => ['f1_phishing' => 0.8979, 'precision_phishing' => 0.8368, 'roc_auc' => 0.9576],
    ]));

    $models = (new ModelMetricsReader($dir))->all();

    expect($models)->toHaveCount(1)
        ->and($models[0]['name'])->toBe('rf-url-vtest')
        ->and($models[0]['f1'])->toBe(0.8979)
        ->and($models[0]['kind'])->toBe('url');
});

it('carries the reporting qualifier through to the UI', function (): void {
    /*
     * A metric shown without its caveat is how "F1 on a 528-message collected
     * corpus" quietly becomes "ScamSense is 97% accurate" in someone's slides.
     */
    $dir = sys_get_temp_dir().'/scamsense-metrics-'.uniqid();
    mkdir($dir.'/tfidf-msg-vtest', 0777, true);
    file_put_contents($dir.'/tfidf-msg-vtest/metrics.json', json_encode([
        'REPORTING_QUALIFIER' => 'F1 on a 528-message collected Malaysian corpus.',
        'test' => ['f1_scam' => 0.9735],
    ]));

    $models = (new ModelMetricsReader($dir))->all();

    expect($models[0]['qualifier'])->toContain('528-message collected Malaysian corpus')
        ->and($models[0]['kind'])->toBe('message');
});

it('survives a missing models directory', function (): void {
    // The dashboard must render on a machine that has never trained anything.
    expect((new ModelMetricsReader('/no/such/path'))->all())->toBe([]);
});

it('renders the dashboard with real seeded data', function (): void {
    $this->seed(SampleSubmissionSeeder::class);
    $this->seed(FeedbackQueueSeeder::class);

    $this->actingAs(admin())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Submissions over time')
        ->assertSee('Verdict distribution')
        ->assertSee('Why verdicts were incomplete');
});

it('answers one question and sends the rest to reports', function (): void {
    /*
     | The page had grown to nine panels, five of which were reference rather
     | than anything to act on today. Together they made a page to be searched
     | rather than read, and they buried the degraded-verdict rate -- the one
     | figure the user manual says is worth checking daily.
     |
     | Asserted from both directions. Checking only that the reference panels
     | are gone would pass on a page that had lost the operational ones too.
     */
    $this->seed(SampleSubmissionSeeder::class);

    $page = $this->actingAs(admin())->get('/admin')->assertOk();

    foreach (['Submissions over time', 'Verdict distribution', 'Why verdicts were incomplete'] as $stays) {
        $page->assertSee($stays);
    }

    /*
     | Asserted against distinctive panel CONTENT, not the headings. The
     | dashboard still names the moved panels in one signpost sentence so the
     | reader is not left wondering whether the page is broken, and an
     | assertDontSee on "Model performance" would fail on that sentence while
     | telling us nothing about whether the panel is gone.
     */
    foreach ([
        'Read from the committed metrics.json files',
        'Show superseded and experimental runs',
        'Most frequent indicators',
        'entries still within their 24-hour life',
        'Awaiting export',
        'Verdicts by model version',
    ] as $moved) {
        $page->assertDontSee($moved);
    }

    // And the reader is told where they went, rather than being left to
    // wonder whether the page is broken.
    $page->assertSee('Reports and downloads');
});

it('clamps the day range to something sensible', function (): void {
    $this->actingAs(admin())->get('/admin?days=99999')->assertOk();
    $this->actingAs(admin())->get('/admin?days=-5')->assertOk();
    $this->actingAs(admin())->get('/admin?days=abc')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Density — the dashboard shows what is acted on, not everything it knows
|--------------------------------------------------------------------------
*/

it('does not repeat a figure that a panel already breaks down', function (): void {
    /*
     | "In retraining set" and "Partial verdicts" were headline counters as well
     | as panels, and "Pending review" appears again on the moderation page. A
     | number repeated across one screen is not emphasis — it costs the reader a
     | moment working out whether the two are the same thing.
     */
    $html = $this->actingAs(admin())->get('/admin')->assertOk()->getContent();

    expect(substr_count($html, 'In retraining set'))->toBe(0)
        ->and(substr_count($html, 'Partial verdicts'))->toBe(0);
});

it('puts the range control on the only chart it changes', function (): void {
    /*
     | DashboardService::summary() passes $days to submissionVolume() and to
     | nothing else, so every other figure is all-time. In the page header the
     | control read as a page-wide filter and implied the counters beside it
     | followed the same window.
     */
    $html = $this->actingAs(admin())->get('/admin')->assertOk()->getContent();

    $heading = strpos($html, 'Submissions over time');
    $control = strpos($html, 'Change the range of this chart');

    expect($control)->not->toBeFalse()
        ->and($control)->toBeGreaterThan($heading - 400)
        ->and($html)->toContain('every other figure on this page is all time');
});

it('seeds only indicator codes the system can actually emit', function (): void {
    /*
     | The seeders invented fifteen codes that exist nowhere else —
     | `no_link_present`, `contact_off_platform`, `authority_impersonation`,
     | `uses_https` and others. They rendered in the dashboard's indicator panel
     | and would have gone into Figure 4.14 of the report, where a reader
     | cross-referencing the indicator table in docs/api-contract.md would find
     | codes that do not exist. Any that reached a verdict page would render the
     | raw translation key to a user.
     |
     | The existing translation test compares en against ms, so it stays green
     | while the seeders invent codes neither file has. This compares seeded
     | data against the language file, which is the real contract.
     */
    $this->seed(SampleSubmissionSeeder::class);
    $this->seed(FeedbackQueueSeeder::class);

    $known = array_keys(require lang_path('en/indicators.php'));
    $seeded = Indicator::query()->distinct()->pluck('code')->all();

    expect($seeded)->not->toBeEmpty()
        ->and(array_values(array_diff($seeded, $known)))->toBe([]);
});
