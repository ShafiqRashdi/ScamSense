<?php

declare(strict_types=1);

use App\Models\Verdict;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A member of the public never lands in the admin area
|--------------------------------------------------------------------------
|
| Written after the feedback buttons were found sending an admin's browser to
| the dashboard (NavigationTest). That could only happen to a browser already
| signed in as the administrator, but the question it raises deserves a direct
| answer: can a visitor who is not signed in reach an admin page by any
| route, button or redirect? These tests say no, for every admin route that
| exists now or is added later.
*/

/** @return list<array{string, string}> every admin route as [method, uri] */
function everyAdminRoute(): array
{
    $routes = [];
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin')) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            // Placeholders get a harmless value; the gate runs before binding.
            $routes[] = [$method, '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri())];
        }
    }

    return $routes;
}

it('finds the admin routes it is meant to guard', function (): void {
    expect(count(everyAdminRoute()))->toBeGreaterThanOrEqual(10);
});

it('sends a visitor who is not signed in to the login page from every admin route', function (): void {
    foreach (everyAdminRoute() as [$method, $uri]) {
        $response = $this->call($method, $uri);

        expect($response->status())->toBe(302, "{$method} {$uri} answered {$response->status()}")
            ->and($response->headers->get('Location'))->toBe(route('login'), "{$method} {$uri}");
    }
});

it('offers no way to create an account', function (string $uri): void {
    $this->get($uri)->assertNotFound();
    $this->post($uri)->assertNotFound();
})->with(['/register', '/forgot-password', '/reset-password', '/verify-email', '/confirm-password']);

it('never links a public page to the admin area', function (string $locale): void {
    fakeNavigationMl();
    $this->post('/verify', ['type' => 'url', 'content' => 'http://lhdn-refund.site/claim']);
    $this->get(route('locale.switch', ['locale' => $locale]));

    foreach (['/', '/about', '/how-to-use', '/result'] as $page) {
        expect($this->get($page)->assertOk()->getContent())
            ->not->toContain(url('/admin'))
            ->not->toContain(route('login'));
    }
})->with(['en', 'ms']);

it('keeps a visitor on public pages through the whole journey', function (): void {
    fakeNavigationMl();

    $this->get('/')->assertOk();
    $this->post('/verify', ['type' => 'url', 'content' => 'http://lhdn-refund.site/claim'])
        ->assertRedirect(route('verify.result'));
    $this->get(route('locale.switch', ['locale' => 'ms', 'from' => 'verify.result']))
        ->assertRedirect(route('verify.result'));
    $this->post('/feedback', ['verdict_id' => Verdict::latest('id')->value('id'), 'agrees' => 0])
        ->assertRedirect(route('verify.result'));
    $this->get('/result')->assertOk()->assertSee(__('feedback.thanks', [], 'ms'));

    $this->assertGuest();
});
