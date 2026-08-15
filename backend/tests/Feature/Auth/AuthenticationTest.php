<?php

declare(strict_types=1);

use App\Models\User;

it('renders the login screen', function (): void {
    $this->get('/login')->assertOk();
});

it('authenticates an administrator with valid credentials', function (): void {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('admin.dashboard', absolute: false));
});

it('rejects an invalid password', function (): void {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

it('logs an administrator out', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

/*
 * ScamSense has no public accounts (AGENTS.md §3). These assert that the Breeze
 * self-service routes stay deleted — a later `composer require laravel/breeze`
 * or a stray scaffold command would silently put them back.
 */
it('exposes no self-service account routes', function (string $uri): void {
    $this->get($uri)->assertNotFound();
})->with([
    'register' => '/register',
    'forgot password' => '/forgot-password',
    'confirm password' => '/confirm-password',
    'verify email' => '/verify-email',
]);

it('keeps the admin area behind authentication', function (): void {
    $this->get('/admin')->assertRedirect('/login');
});
