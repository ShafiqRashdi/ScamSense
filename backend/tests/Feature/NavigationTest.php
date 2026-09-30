<?php

declare(strict_types=1);

use App\Models\Verdict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| Every button goes where it says
|--------------------------------------------------------------------------
|
| Found on the live site on 2026-09-30: the result page existed only as the
| response to POST /verify, and the pages send no Referer. So back() — used by
| the language switch and by feedback — fell through to "the last GET this
| session made": BM on the result page went home, and the feedback buttons
| went to the admin dashboard when it was open in another tab. The admin
| header's brand also went to the public home page.
*/

function fakeNavigationMl(): void
{
    Http::fake([
        '*/predict/*' => Http::response([
            'request_id' => 't',
            'label' => 'scam',
            'confidence' => 0.93,
            'indicators' => [],
            'model_version' => 'rf-url-v2.0.0-pathnorm',
            'inference_ms' => 10,
        ]),
    ]);
}

function checkOneLink(): void
{
    fakeNavigationMl();
    test()->post('/verify', ['type' => 'url', 'content' => 'http://lhdn-refund.site/claim'])
        ->assertRedirect(route('verify.result'));
}

it('shows the verdict at its own address after a check', function (): void {
    checkOneLink();

    $this->get('/result')->assertOk()->assertSee('lhdn-refund[.]site');
});

it('sends a visitor with no check back to the form', function (): void {
    $this->get('/result')->assertRedirect(route('verify.show'));
});

it('keeps the visitor on the result page when switching language there', function (): void {
    checkOneLink();

    $html = $this->get('/result')->getContent();
    expect($html)->toContain(e(route('locale.switch', ['locale' => 'ms', 'from' => 'verify.result'])));

    $this->get(route('locale.switch', ['locale' => 'ms', 'from' => 'verify.result']))
        ->assertRedirect(route('verify.result'));

    $this->get('/result')->assertOk()->assertSee('Berkemungkinan scam');
});

it('returns every public page to itself when switching language', function (string $route): void {
    $this->get(route($route))->assertOk();

    $this->get(route('locale.switch', ['locale' => 'ms', 'from' => $route]))
        ->assertRedirect(route($route));
})->with(['verify.show', 'about', 'howto']);

it('never redirects the language switch anywhere off the list', function (string $from): void {
    $this->get('/locale/ms?from='.urlencode($from))->assertRedirect(route('verify.show'));
})->with(['admin.dashboard', 'https://evil.example', '//evil.example', 'logout', '']);

it('returns feedback to the result, even with the admin area open in another tab', function (): void {
    checkOneLink();
    $verdictId = Verdict::latest('id')->value('id');

    // The same session visits the dashboard, as a second tab would.
    $this->actingAs(admin())->get('/admin')->assertOk();

    $this->post('/feedback', ['verdict_id' => $verdictId, 'agrees' => 1])
        ->assertRedirect(route('verify.result'));

    $this->get('/result')->assertOk()->assertSee(__('feedback.thanks'));
});

it('returns a failed check to the form, not to the last page opened', function (): void {
    $this->get('/about')->assertOk();

    $this->post('/verify', ['type' => 'url', 'content' => 'javascript:alert(1)'])
        ->assertRedirect(route('verify.show'));
});

it('takes the admin brand to the dashboard', function (): void {
    $html = $this->actingAs(admin())->get('/admin')->getContent();

    expect($html)->toMatch('/<a class="sc-brand navbar-brand" href="'.preg_quote(route('admin.dashboard'), '/').'">/');
});

/*
 | The audit: every link and form on every page, in both languages, must point
 | at a route that exists for that method. A 404 or 405 behind a button is the
 | class of bug above, caught before a visitor finds it.
 */
function assertEveryTargetResolves(string $html, string $page): void
{
    $base = rtrim(config('app.url'), '/');

    preg_match_all('/<a\b[^>]*href="([^"]+)"/i', $html, $links);
    preg_match_all('/<form\b[^>]*method="(GET|POST)"[^>]*action="([^"]+)"/i', $html, $forms);

    $targets = [];
    foreach ($links[1] as $href) {
        $targets[] = ['GET', html_entity_decode($href)];
    }
    foreach ($forms[2] as $i => $action) {
        $targets[] = [strtoupper($forms[1][$i]), html_entity_decode($action)];
    }

    foreach ($targets as [$method, $url]) {
        if (! str_starts_with($url, $base) && ! str_starts_with($url, '/')) {
            continue; // in-page anchors (#main) and external references
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        try {
            Route::getRoutes()->match(Request::create($path, $method));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            throw new RuntimeException("{$page}: {$method} {$path} has no route");
        }
    }
}

it('has no dead link or form on any public page', function (string $locale): void {
    checkOneLink();
    $this->get(route('locale.switch', ['locale' => $locale]));

    foreach (['/', '/about', '/how-to-use', '/result', '/login'] as $page) {
        $response = $this->get($page);
        expect($response->status())->toBe(200, "{$page} answered {$response->status()}");
        assertEveryTargetResolves($response->getContent(), $page);
    }
})->with(['en', 'ms']);

it('has no dead link or form on any admin page', function (): void {
    $this->actingAs(admin());

    foreach (['/admin', '/admin/moderation', '/admin/reports'] as $page) {
        assertEveryTargetResolves($this->get($page)->assertOk()->getContent(), $page);
    }
});
