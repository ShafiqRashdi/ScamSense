<?php

declare(strict_types=1);

use App\Services\RedactorService;

beforeEach(function (): void {
    $this->redactor = new RedactorService;
});

/*
|--------------------------------------------------------------------------
| Malaysian phone numbers
|--------------------------------------------------------------------------
*/

it('masks Malaysian phone numbers in every common format', function (string $input): void {
    $result = $this->redactor->redact($input);

    expect($result->text)->toContain(RedactorService::MASK_PHONE)
        ->and($result->counts['phone_numbers'])->toBeGreaterThan(0);
})->with([
    'mobile dashed' => 'Hubungi saya di 012-345 6789 segera',
    'mobile bare' => 'Call 0123456789 now',
    'mobile hyphen only' => 'WhatsApp 012-3456789 ya',
    'international +60' => 'Sila hubungi +60123456789',
    'international 60' => 'Contact 60123456789',
    'international spaced' => 'Call +60 12 345 6789',
    'landline KL' => 'Pejabat kami 03-1234 5678',
    'landline Penang' => 'Telefon 04-123 4567',
]);

it('does not leave any digits of a masked phone number behind', function (): void {
    $result = $this->redactor->redact('Hubungi 012-345 6789 sekarang');

    expect($result->text)->toBe('Hubungi '.RedactorService::MASK_PHONE.' sekarang');
});

/*
|--------------------------------------------------------------------------
| IC (MyKad) numbers
|--------------------------------------------------------------------------
*/

it('masks a dashed IC number', function (): void {
    $result = $this->redactor->redact('No. KP saya 020304-14-5678 untuk pengesahan');

    expect($result->text)->toContain(RedactorService::MASK_IC)
        ->and($result->text)->not->toContain('020304')
        ->and($result->counts['ic_numbers'])->toBe(1);
});

it('masks an undashed IC number', function (): void {
    $result = $this->redactor->redact('IC 020304145678 sila sahkan');

    expect($result->text)->toContain(RedactorService::MASK_IC)
        ->and($result->counts['ic_numbers'])->toBe(1);
});

it('treats a twelve-digit number with an impossible birth month as an account, not an IC', function (): void {
    // Month 99 cannot be a birth date, so this is not a MyKad.
    $result = $this->redactor->redact('Akaun 019945678901 sila bayar');

    expect($result->counts['ic_numbers'])->toBe(0)
        ->and($result->counts['bank_accounts'])->toBe(1)
        ->and($result->text)->toContain(RedactorService::MASK_ACCOUNT);
});

it('treats a twelve-digit number with an unissued state code as an account', function (): void {
    // 99 is not an issued place-of-birth code.
    $result = $this->redactor->redact('Akaun 020304995678 sila bayar');

    expect($result->counts['ic_numbers'])->toBe(0)
        ->and($result->counts['bank_accounts'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Email addresses
|--------------------------------------------------------------------------
*/

it('masks email addresses', function (string $input): void {
    $result = $this->redactor->redact($input);

    expect($result->text)->toContain(RedactorService::MASK_EMAIL)
        ->and($result->counts['emails'])->toBe(1);
})->with([
    'simple' => 'Emel saya ahmad@gmail.com terima kasih',
    'with dots' => 'Contact siti.nurhaliza@example.com.my please',
    'with plus' => 'Send to user+tag@domain.org now',
    'subdomain' => 'Reply to admin@mail.maybank2u.com.my',
]);

/*
|--------------------------------------------------------------------------
| Bank account numbers
|--------------------------------------------------------------------------
*/

it('masks bank account numbers of varying length', function (string $input): void {
    $result = $this->redactor->redact($input);

    expect($result->text)->toContain(RedactorService::MASK_ACCOUNT);
})->with([
    'ten digits' => 'Bank in to 1234567890 today',
    'fourteen digits' => 'Akaun CIMB 12345678901234 sila bayar',
    'sixteen digits' => 'Transfer 1234567890123456 sekarang',
    // People write account numbers in groups far more often than as one run.
    // Missing these was a real false negative found by probing, not by the
    // original tests — PII would have reached the database.
    'grouped by four' => 'Maybank 5140 1234 5678 sila bayar',
    'grouped by dashes' => 'Akaun 5140-1234-5678 sila bayar',
    'grouped uneven' => 'CIMB 8009 123456 sila bayar',
]);

it('masks a landline written with a bracketed area code', function (): void {
    // Also a false negative found by probing: this is how Malaysian businesses
    // print a hotline, so it appears verbatim in impersonation scams.
    $result = $this->redactor->redact('Hotline (03) 2145 6789 untuk bantuan');

    expect($result->text)->toContain(RedactorService::MASK_PHONE)
        ->and($result->counts['phone_numbers'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The critical one: legitimate text must survive intact
|--------------------------------------------------------------------------
*/

it('does not mangle legitimate text', function (string $input): void {
    expect($this->redactor->redact($input)->text)->toBe($input);
})->with([
    'money' => 'Anda memenangi RM5,000 dalam cabutan bertuah',
    'money with decimals' => 'Jumlah bayaran ialah RM1,250.50 sahaja',
    'time window' => 'Sila jawab dalam tempoh 24 jam',
    'year' => 'Promosi ini sah sehingga 31 Disember 2026',
    'date' => 'Tarikh luput 12/08/2026',
    'percentage' => 'Diskaun sehingga 70% untuk semua barangan',
    'short digits' => 'Kod pengesahan anda ialah 4821',
    'plain sentence' => 'Terima kasih kerana menggunakan perkhidmatan kami',
    'english sentence' => 'Your parcel has been delivered successfully',
    'order reference' => 'Nombor pesanan anda ialah AB-2291',
    'price list' => 'Harga: 15, 30, 45 ringgit',
]);

it('preserves a URL rather than eating its digits', function (): void {
    $input = 'Klik https://maybank2u-verify.xyz/login/012345678 sekarang';

    expect($this->redactor->redact($input)->text)->toBe($input);
});

it('preserves a shortened URL', function (): void {
    $input = 'Sila klik http://bit.ly/3xK9mZ2 untuk tuntutan';

    expect($this->redactor->redact($input)->text)->toBe($input);
});

it('redacts PII around a URL while leaving the URL intact', function (): void {
    $result = $this->redactor->redact(
        'Klik https://scam.example.com/pay?ref=1234567890 atau hubungi 012-345 6789'
    );

    expect($result->text)
        ->toContain('https://scam.example.com/pay?ref=1234567890')
        ->and($result->text)->toContain(RedactorService::MASK_PHONE)
        ->and($result->counts['phone_numbers'])->toBe(1)
        ->and($result->counts['bank_accounts'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A realistic mixed-language scam, which is what this project actually sees
|--------------------------------------------------------------------------
*/

it('redacts every category in one code-switched message', function (): void {
    $result = $this->redactor->redact(
        'TAHNIAH! Anda memenangi RM5,000. Sila hubungi 012-345 6789 atau emel '
        .'claims@prize-my.com. Sertakan no KP 020304-14-5678 dan akaun bank '
        .'1234567890 dalam 24 jam. Klik https://prize-my.xyz/claim'
    );

    expect($result->counts)->toBe([
        'emails' => 1,
        'ic_numbers' => 1,
        'phone_numbers' => 1,
        'bank_accounts' => 1,
    ])
        ->and($result->text)->toContain('RM5,000')
        ->and($result->text)->toContain('24 jam')
        ->and($result->text)->toContain('https://prize-my.xyz/claim')
        ->and($result->text)->not->toContain('012-345 6789')
        ->and($result->text)->not->toContain('claims@prize-my.com')
        ->and($result->text)->not->toContain('020304-14-5678')
        ->and($result->text)->not->toContain('1234567890');
});

/*
|--------------------------------------------------------------------------
| Result object behaviour
|--------------------------------------------------------------------------
*/

it('reports nothing redacted for clean text', function (): void {
    $result = $this->redactor->redact('Selamat pagi, apa khabar?');

    expect($result->redactedAnything())->toBeFalse()
        ->and($result->total())->toBe(0)
        ->and($result->matchedPatterns())->toBe([]);
});

it('reports which patterns matched', function (): void {
    $result = $this->redactor->redact('Emel ali@example.com atau call 012-345 6789');

    expect($result->matchedPatterns())->toEqualCanonicalizing(['emails', 'phone_numbers'])
        ->and($result->total())->toBe(2);
});

it('cannot be tricked into injecting text through a forged URL placeholder', function (): void {
    // A user typing something resembling the internal placeholder must not be
    // able to have arbitrary text substituted back in during URL restoration.
    $result = $this->redactor->redact('Nothing here U0 or [URL] or __URL_0__');

    expect($result->text)->toBe('Nothing here U0 or [URL] or __URL_0__');
});

it('handles empty and whitespace input without error', function (string $input): void {
    expect($this->redactor->redact($input)->text)->toBe($input);
})->with([
    'empty' => '',
    'spaces' => '   ',
    'newlines' => "\n\n",
]);
