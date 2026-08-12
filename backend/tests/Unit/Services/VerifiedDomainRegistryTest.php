<?php

declare(strict_types=1);

use App\Services\Detection\VerifiedDomainRegistry;

beforeEach(function (): void {
    $this->registry = VerifiedDomainRegistry::fromConfig();
});

/*
|--------------------------------------------------------------------------
| The gate must fire for genuine institutions
|--------------------------------------------------------------------------
*/

it('recognises a licensed bank domain', function (string $url): void {
    expect($this->registry->isVerified($url))->toBeTrue();
})->with([
    'maybank root' => 'https://www.maybank2u.com.my',
    'maybank login path' => 'https://www.maybank2u.com.my/home/m2u/common/login.do',
    'cimb' => 'https://www.cimb.com.my/en/personal/index.html',
    'cimbclicks' => 'https://www.cimbclicks.com.my',
    'public bank' => 'https://www.publicbank.com.my',
]);

it('recognises an institutional suffix without needing a list entry', function (string $url): void {
    // MYNIC restricts .gov.my registration, so membership is structural and
    // future government services are covered automatically.
    expect($this->registry->isVerified($url))->toBeTrue();
})->with([
    'lhdn' => 'https://www.hasil.gov.my/en/',
    'kwsp' => 'https://www.kwsp.gov.my/en/member/overview',
    'jpj' => 'https://www.jpj.gov.my/en/web/main-site/utama',
    'bnm' => 'https://www.bnm.gov.my/',
    'a university' => 'https://www.uptm.edu.my',
]);

it('returns the matched domain so it can be recorded as evidence', function (): void {
    expect($this->registry->match('https://www.maybank2u.com.my/home/login.do'))
        ->toBe('maybank2u.com.my');
});

/*
|--------------------------------------------------------------------------
| The gate must NOT fire for impersonators
|--------------------------------------------------------------------------
|
| These are the tests that matter. A loose match here would turn the
| mitigation into a bypass — an attacker would simply append a verified name
| to a domain they control.
|
*/

it('does not match a verified name used as a subdomain of an attacker domain', function (string $url): void {
    expect($this->registry->isVerified($url))->toBeFalse();
})->with([
    'maybank as subdomain' => 'https://maybank2u.com.my.secure-verify.xyz/login',
    'gov.my as subdomain' => 'https://hasil.gov.my.phishing.top/semak',
    'bank in a path' => 'https://secure-login.xyz/maybank2u.com.my/login',
    'hyphenated lookalike' => 'https://maybank2u-com-my.xyz/login',
    'lhdn lookalike' => 'http://lhdn-refund.site/claim',
    'kwsp lookalike' => 'http://kwsp-semakan.top/login',
    'cimb lookalike' => 'https://cimb-clicks.secure-login.top/verify',
]);

it('does not match a domain that merely ends with the suffix text', function (): void {
    // Without the leading dot when comparing suffixes, "notgov.my" would match
    // "gov.my". That is precisely the bypass this guards against.
    expect($this->registry->isVerified('https://notgov.my/x'))->toBeFalse()
        ->and($this->registry->isVerified('https://fakegov.my/x'))->toBeFalse();
});

it('does not match an unrelated domain', function (): void {
    expect($this->registry->isVerified('https://example.com'))->toBeFalse()
        ->and($this->registry->isVerified('http://192.168.1.1/maybank2u/login'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Robustness
|--------------------------------------------------------------------------
*/

it('is case insensitive on the host', function (): void {
    expect($this->registry->isVerified('https://WWW.MayBank2u.COM.MY/login'))->toBeTrue();
});

it('handles a missing scheme', function (): void {
    expect($this->registry->isVerified('maybank2u.com.my/login'))->toBeTrue();
});

it('is resolved from the container with the configured lists', function (): void {
    /*
     * Regression guard. Without an explicit binding the container builds this
     * through its default constructor, which takes empty arrays — so the gate
     * would silently never fire while config/scamsense.php sat there full of
     * correct domains looking configured. That failure mode is invisible
     * except through a test like this one.
     */
    $resolved = app(VerifiedDomainRegistry::class);

    expect($resolved->isVerified('https://www.maybank2u.com.my/home/login.do'))->toBeTrue()
        ->and($resolved->isVerified('https://www.hasil.gov.my/en/'))->toBeTrue();
});

it('never throws on hostile input', function (string $url): void {
    expect($this->registry->isVerified($url))->toBeFalse();
})->with([
    'empty' => '',
    'spaces' => '   ',
    'not a url' => 'not a url at all',
    'scheme only' => 'http://',
    'single label' => 'https://localhost',
    'very long' => 'https://'.str_repeat('a', 2000).'.com',
]);
