<?php

declare(strict_types=1);

use App\DataTransferObjects\IndicatorData;
use App\DataTransferObjects\MarkedSegment;
use App\DataTransferObjects\SubmissionExplanation;
use App\Enums\IndicatorSource;
use App\Enums\SubmissionType;
use App\Services\DefangService;
use App\Services\Detection\VerifiedDomainRegistry;
use App\Services\RedactorService;
use App\Services\SubmissionExplainer;

beforeEach(function (): void {
    $this->explainer = new SubmissionExplainer(
        new DefangService,
        new RedactorService,
        VerifiedDomainRegistry::fromConfig(),
    );
});

function indicator(string $code, ?string $evidence): IndicatorData
{
    return new IndicatorData($code, 0.0, $evidence, IndicatorSource::Pattern);
}

/** The page text as a reader would see it, with each mark shown as [n:...]. */
function rendered(SubmissionExplanation $explanation): string
{
    return implode('', array_map(
        static fn (MarkedSegment $s): string => $s->number !== null ? "[{$s->number}:{$s->text}]" : $s->text,
        $explanation->segments,
    ));
}

/*
|--------------------------------------------------------------------------
| Marks land on the evidence, in the defanged text
|--------------------------------------------------------------------------
*/

it('marks url evidence in its defanged form', function (): void {
    $explanation = $this->explainer->explain(
        SubmissionType::Url,
        'http://maybank2u.secure-verify.xyz/login/update-account',
        [
            indicator('brand_lookalike', 'secure-verify.xyz'),
            indicator('sensitive_keyword_path', '/login/update-account'),
        ],
    );

    expect(rendered($explanation))
        ->toBe('hxxp://maybank2u[.][1:secure-verify[.]xyz][2:/login/update-account]')
        ->and($explanation->numbers)->toBe([1, 2]);
});

it('strips the ellipsis the ML service puts around message evidence', function (): void {
    $explanation = $this->explainer->explain(
        SubmissionType::Message,
        'Akaun anda akan disekat segera jika tidak dikemaskini hari ini.',
        [indicator('urgency_language', '…akaun anda akan disekat segera…')],
    );

    expect(rendered($explanation))
        ->toBe('[1:Akaun anda akan disekat segera] jika tidak dikemaskini hari ini.');
});

it('leaves a reason unnumbered when its evidence is not in the text', function (): void {
    // no_https arrives as "http", which the defanged text no longer contains.
    // A number pointing at nothing would break the promise in the hint line.
    $explanation = $this->explainer->explain(
        SubmissionType::Url,
        'http://pos-semakan.xyz/update',
        [indicator('no_https', 'http'), indicator('suspicious_tld', '.xyz')],
    );

    expect($explanation->numbers)->toBe([null, 1])
        ->and(rendered($explanation))->toBe('hxxp://pos-semakan[1:[.]xyz]/update');
});

it('never lets two reasons claim the same words', function (): void {
    $explanation = $this->explainer->explain(
        SubmissionType::Url,
        'https://secure-verify.xyz/',
        [indicator('brand_lookalike', 'secure-verify.xyz'), indicator('suspicious_tld', '.xyz')],
    );

    expect($explanation->numbers)->toBe([1, null]);
});

it('reports whether anything was marked', function (): void {
    $none = $this->explainer->explain(SubmissionType::Message, 'Bil elektrik anda RM128.40.', []);

    expect($none->hasMarks())->toBeFalse()
        ->and(rendered($none))->toBe('Bil elektrik anda RM128.40.');
});

/*
|--------------------------------------------------------------------------
| Redaction stays visible as redaction
|--------------------------------------------------------------------------
*/

it('splits redaction masks out so the page can show them as ours', function (): void {
    $explanation = $this->explainer->explain(
        SubmissionType::Message,
        'Hubungi '.RedactorService::MASK_PHONE.' sekarang',
        [],
    );

    $masks = array_values(array_filter($explanation->segments, static fn (MarkedSegment $s): bool => $s->isMask));

    expect($masks)->toHaveCount(1)
        ->and($masks[0]->text)->toBe(RedactorService::MASK_PHONE);
});

it('redacts evidence before looking for it, as the stored text was', function (): void {
    $explanation = $this->explainer->explain(
        SubmissionType::Message,
        'Sila hubungi '.RedactorService::MASK_PHONE.' untuk tuntut hadiah',
        [indicator('prize_claim', 'hubungi 012-345 6789 untuk tuntut hadiah')],
    );

    expect($explanation->numbers)->toBe([1]);
});

/*
|--------------------------------------------------------------------------
| How to read this address
|--------------------------------------------------------------------------
*/

it('splits a lookalike address around the domain that really owns it', function (): void {
    $anatomy = $this->explainer->explain(
        SubmissionType::Url,
        'http://maybank2u.secure-verify.xyz/login/update-account',
        [indicator('brand_lookalike', 'secure-verify.xyz')],
    )->anatomy;

    expect($anatomy)->not->toBeNull()
        ->and($anatomy->lead)->toBe('hxxp://')
        ->and($anatomy->prefix)->toBe('maybank2u[.]')
        ->and($anatomy->owner)->toBe('secure-verify[.]xyz')
        ->and($anatomy->tail)->toBe('/login/update-account')
        ->and($anatomy->prefixIsCopiedName)->toBeTrue();
});

it('keeps Malaysian two-part suffixes with their owner', function (): void {
    $anatomy = $this->explainer->explain(
        SubmissionType::Url,
        'https://www.maybank2u.com.my/home/m2u/common/login.do',
        [],
    )->anatomy;

    expect($anatomy->owner)->toBe('maybank2u[.]com[.]my')
        ->and($anatomy->prefix)->toBe('www[.]')
        ->and($anatomy->prefixIsCopiedName)->toBeFalse();
});

it('gives no breakdown for an IP address or a message', function (): void {
    expect($this->explainer->explain(SubmissionType::Url, 'http://192.168.10.5/login', [])->anatomy)->toBeNull()
        ->and($this->explainer->explain(SubmissionType::Message, 'Pautan: https://x.xyz', [])->anatomy)->toBeNull();
});
