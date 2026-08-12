<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ScamSense application settings
|--------------------------------------------------------------------------
|
| Project-specific configuration that is not credentials for a third party —
| those live in config/services.php. AGENTS.md §5 forbids env() outside config,
| so every value below is resolved once here.
|
*/

return [

    /*
     | The administrator provisioned by AdminUserSeeder. Leaving the password
     | empty is the intended default: the seeder then generates a random one and
     | prints it once. Never commit a value here.
     */
    'admin' => [
        'name' => env('ADMIN_NAME', 'ScamSense Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@scamsense.local'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
     | Submission limits enforced by the FormRequest before anything reaches a
     | service (AGENTS.md §3). Kept here so the validation rule, the UI counter
     | and the Chapter 4 write-up can never drift apart.
     */
    'limits' => [
        'message_max_chars' => 5000,
        'url_max_chars' => 2048,
    ],

    /*
     | Public rate limits, per IP (AGENTS.md §3).
     */
    'rate_limits' => [
        'verify_per_minute' => 10,
        'verify_per_hour' => 30,
        'feedback_per_hour' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verified institutional domains
    |--------------------------------------------------------------------------
    |
    | A SAFETY MITIGATION, NOT A MODEL IMPROVEMENT. See docs/adr/0007.
    |
    | The URL classifier flags genuine bank login pages because its training
    | corpus under-represents legitimate authenticated URLs (docs/adr/0006).
    | This list gates the verdict for domains whose legitimacy is an externally
    | verifiable registry fact — not a guess, and not the absence of a
    | blocklist entry.
    |
    | Every model metric in docs/RESULTS.md is measured with this gate DISABLED.
    | Reporting a figure that includes it would be measuring the allowlist.
    |
    | Matching is on the registrable domain, exactly. Adding an entry for a
    | domain the project cannot verify is the one dangerous mistake here — cite
    | the register that confirms it.
    |
    */
    'verified_domains' => [

        /*
         | Suffixes whose registration is restricted by policy, so membership
         | is structural. MYNIC permits .gov.my only to accredited government
         | bodies, so future services are covered without maintenance.
         */
        'suffixes' => [
            'gov.my',
            'edu.my',
            'mil.my',
        ],

        /*
         | Exact registrable domains. Banks and e-wallets are verifiable against
         | Bank Negara Malaysia's register of licensed financial institutions;
         | telcos and utilities against their MCMC/SPAN licences.
         */
        'domains' => [
            // Licensed banks (BNM register)
            'maybank2u.com.my', 'maybank.com', 'maybank2u.com',
            'cimb.com.my', 'cimbclicks.com.my', 'cimbbank.com.my',
            'publicbank.com.my', 'pbebank.com',
            'rhbgroup.com', 'rhbbank.com.my',
            'hlb.com.my', 'hongleongconnect.my',
            'ambank.com.my', 'amonline.com.my',
            'bankislam.com', 'bankislam.biz',
            'bsn.com.my', 'affinbank.com.my', 'affinalways.com',
            'ocbc.com.my', 'uob.com.my', 'hsbc.com.my', 'sc.com',
            'bnm.gov.my',

            // Payments and e-wallets (BNM licensed)
            'touchngo.com.my', 'tngdigital.com.my', 'myboost.com.my',
            'duitnow.my', 'paynet.my',

            // Utilities and telcos
            'tnb.com.my', 'mytnb.com.my', 'syabas.com.my',
            'tm.com.my', 'unifi.com.my', 'maxis.com.my', 'mymaxis.com.my',
            'celcom.com.my', 'digi.com.my', 'u.com.my', 'astro.com.my',

            // Logistics and major platforms
            'poslaju.com.my', 'pos.com.my',
            'shopee.com.my', 'lazada.com.my', 'grab.com',
            'myeg.com.my',
        ],
    ],

    /*
     | Two-part public suffixes, so `maybank2u.com.my` reduces to itself rather
     | than to `com.my`. Mirrors MULTI_PART_SUFFIXES in the ML service.
     */
    'multi_part_suffixes' => [
        'com.my', 'net.my', 'org.my', 'gov.my', 'edu.my', 'mil.my', 'name.my',
        'com.sg', 'com.au', 'co.uk', 'org.uk', 'gov.uk', 'ac.uk',
    ],

    /*
     | Risk bands, from the measured test-set distribution in docs/RESULTS.md:
     | low 2.0% actually phishing, medium 28.3%, high 95.2%.
     */
    'risk_bands' => [
        'medium_from' => 0.20,
        'high_from' => 0.60,
    ],

];
