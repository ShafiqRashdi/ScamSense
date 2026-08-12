<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Verified institutional domains
|--------------------------------------------------------------------------
|
| A SAFETY MITIGATION, NOT A MODEL IMPROVEMENT. See docs/adr/0007.
|
| The URL classifier flags genuine bank login pages because its training corpus
| under-represents legitimate authenticated URLs (docs/adr/0006). This registry
| gates the verdict for domains whose legitimacy is an externally verifiable
| fact — not a guess, and not merely the absence of a blocklist entry.
|
| Every model metric in docs/RESULTS.md is measured with this gate DISABLED.
|
| ## Every entry must carry a reference
|
| ADR 0007 claims these are "third-party checkable". That claim is only true if
| each entry records where it was verified and when, so `reference` and
| `verified_on` are required, not decorative. An entry without a citation is an
| assertion, and an assertion in an allowlist is the one genuinely dangerous
| mistake available here.
|
| ## Structure
|
| Institutions are keyed by a stable slug and may hold several domains — many
| banks run separate marketing and online-banking addresses — so the display
| name is stated once and cannot drift between them.
|
*/

return [

    /*
     | Suffixes whose registration is restricted by policy. Membership is
     | structural rather than listed, so future government services are covered
     | with no maintenance and no per-domain citation.
     */
    'suffixes' => [
        'gov.my' => [
            'name' => 'Malaysian Government',
            'name_ms' => 'Kerajaan Malaysia',
            'authority' => 'mynic_restricted',
            'reference' => 'MYNIC .my domain registration policy: gov.my is restricted to Malaysian government agencies.',
            'verified_on' => '2026-08-11',
        ],
        'edu.my' => [
            'name' => 'Malaysian educational institution',
            'name_ms' => 'Institusi pendidikan Malaysia',
            'authority' => 'mynic_restricted',
            'reference' => 'MYNIC .my domain registration policy: edu.my is restricted to accredited institutions.',
            'verified_on' => '2026-08-11',
        ],
        'mil.my' => [
            'name' => 'Malaysian Armed Forces',
            'name_ms' => 'Angkatan Tentera Malaysia',
            'authority' => 'mynic_restricted',
            'reference' => 'MYNIC .my domain registration policy: mil.my is restricted to the armed forces.',
            'verified_on' => '2026-08-11',
        ],
    ],

    /*
     | Named institutions. `domains` are exact registrable domains.
     |
     | `reference` should name the register or official source consulted, so a
     | reviewer can re-check it without trusting this file.
     */
    'institutions' => [

        // ── Licensed banks ────────────────────────────────────────────────
        'maybank' => [
            'name' => 'Maybank',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks; domains published on the bank corporate site.',
            'verified_on' => '2026-08-11',
            'domains' => ['maybank2u.com.my', 'maybank.com.my', 'maybank.com'],
        ],
        'cimb' => [
            'name' => 'CIMB Bank',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['cimb.com.my', 'cimbclicks.com.my', 'cimbbank.com.my'],
        ],
        'public-bank' => [
            'name' => 'Public Bank',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['publicbank.com.my', 'pbebank.com'],
        ],
        'rhb' => [
            'name' => 'RHB Bank',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['rhbgroup.com', 'rhbbank.com.my'],
        ],
        'hong-leong' => [
            'name' => 'Hong Leong Bank',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['hlb.com.my', 'hongleongconnect.my'],
        ],
        'ambank' => [
            'name' => 'AmBank',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['ambank.com.my', 'amonline.com.my'],
        ],
        'bank-islam' => [
            'name' => 'Bank Islam',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed Islamic banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['bankislam.com', 'bankislam.biz'],
        ],
        'bsn' => [
            'name' => 'Bank Simpanan Nasional',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia; BSN is a development financial institution under BNM oversight.',
            'verified_on' => '2026-08-11',
            'domains' => ['bsn.com.my'],
        ],
        'affin' => [
            'name' => 'Affin Bank',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['affinbank.com.my', 'affinalways.com'],
        ],
        'ocbc-my' => [
            'name' => 'OCBC Bank Malaysia',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['ocbc.com.my'],
        ],
        'uob-my' => [
            'name' => 'UOB Malaysia',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['uob.com.my'],
        ],
        'hsbc-my' => [
            'name' => 'HSBC Malaysia',
            'authority' => 'bnm_licensed_bank',
            'reference' => 'Bank Negara Malaysia list of licensed commercial banks.',
            'verified_on' => '2026-08-11',
            'domains' => ['hsbc.com.my'],
        ],

        // ── Payments and e-wallets ────────────────────────────────────────
        'touch-n-go' => [
            'name' => "Touch 'n Go",
            'authority' => 'bnm_licensed_emoney',
            'reference' => 'Bank Negara Malaysia list of approved e-money issuers.',
            'verified_on' => '2026-08-11',
            'domains' => ['touchngo.com.my', 'tngdigital.com.my'],
        ],
        'boost' => [
            'name' => 'Boost',
            'authority' => 'bnm_licensed_emoney',
            'reference' => 'Bank Negara Malaysia list of approved e-money issuers.',
            'verified_on' => '2026-08-11',
            'domains' => ['myboost.com.my'],
        ],
        'paynet' => [
            'name' => 'PayNet (DuitNow)',
            'authority' => 'bnm_operator',
            'reference' => 'Payments Network Malaysia, operator of DuitNow under BNM oversight.',
            'verified_on' => '2026-08-11',
            'domains' => ['paynet.my', 'duitnow.my'],
        ],

        // ── Utilities and telcos ──────────────────────────────────────────
        'tnb' => [
            'name' => 'Tenaga Nasional (TNB)',
            'authority' => 'licensed_utility',
            'reference' => 'Energy Commission of Malaysia licensee; domains published on the TNB corporate site.',
            'verified_on' => '2026-08-11',
            'domains' => ['tnb.com.my', 'mytnb.com.my'],
        ],
        'syabas' => [
            'name' => 'Air Selangor (SYABAS)',
            'authority' => 'licensed_utility',
            'reference' => 'SPAN water services licensee.',
            'verified_on' => '2026-08-11',
            'domains' => ['syabas.com.my'],
        ],
        'tm' => [
            'name' => 'Telekom Malaysia (unifi)',
            'authority' => 'mcmc_licensee',
            'reference' => 'MCMC network service provider licensee.',
            'verified_on' => '2026-08-11',
            'domains' => ['tm.com.my', 'unifi.com.my'],
        ],
        'maxis' => [
            'name' => 'Maxis',
            'authority' => 'mcmc_licensee',
            'reference' => 'MCMC network service provider licensee.',
            'verified_on' => '2026-08-11',
            'domains' => ['maxis.com.my', 'mymaxis.com.my'],
        ],
        'celcomdigi' => [
            'name' => 'CelcomDigi',
            'authority' => 'mcmc_licensee',
            'reference' => 'MCMC network service provider licensee.',
            'verified_on' => '2026-08-11',
            'domains' => ['celcom.com.my', 'digi.com.my', 'celcomdigi.com'],
        ],
        'u-mobile' => [
            'name' => 'U Mobile',
            'authority' => 'mcmc_licensee',
            'reference' => 'MCMC network service provider licensee.',
            'verified_on' => '2026-08-11',
            'domains' => ['u.com.my'],
        ],
        'astro' => [
            'name' => 'Astro',
            'authority' => 'mcmc_licensee',
            'reference' => 'MCMC broadcasting licensee.',
            'verified_on' => '2026-08-11',
            'domains' => ['astro.com.my'],
        ],

        // ── Logistics and major platforms ─────────────────────────────────
        'pos-malaysia' => [
            'name' => 'Pos Malaysia (Pos Laju)',
            'authority' => 'mcmc_postal_licensee',
            'reference' => 'MCMC postal services licensee.',
            'verified_on' => '2026-08-11',
            'domains' => ['pos.com.my', 'poslaju.com.my'],
        ],
        'shopee-my' => [
            'name' => 'Shopee Malaysia',
            'authority' => 'ssm_registered_platform',
            'reference' => 'Domain published on the Shopee Malaysia corporate site; SSM-registered entity.',
            'verified_on' => '2026-08-11',
            'domains' => ['shopee.com.my'],
        ],
        'lazada-my' => [
            'name' => 'Lazada Malaysia',
            'authority' => 'ssm_registered_platform',
            'reference' => 'Domain published on the Lazada Malaysia corporate site; SSM-registered entity.',
            'verified_on' => '2026-08-11',
            'domains' => ['lazada.com.my'],
        ],
        'grab-my' => [
            'name' => 'Grab',
            'authority' => 'ssm_registered_platform',
            'reference' => 'Domain published on the Grab corporate site; APAD e-hailing licensee.',
            'verified_on' => '2026-08-11',
            'domains' => ['grab.com'],
        ],
        'myeg' => [
            'name' => 'MyEG',
            'authority' => 'gov_concessionaire',
            'reference' => 'Government e-services concessionaire; domain published on the MyEG corporate site.',
            'verified_on' => '2026-08-11',
            'domains' => ['myeg.com.my'],
        ],
    ],
];
