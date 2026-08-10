<?php

declare(strict_types=1);
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
