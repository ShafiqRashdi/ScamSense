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
     | The verified-domain allowlist lives in config/verified_institutions.php,
     | because each entry carries a citation and a verification date and the
     | file is long enough to deserve its own home. See docs/adr/0007.
     */

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

    /*
     | Data retention (security review finding 5, PDPA 2010).
     |
     | The submission form promises storage; this is the period behind that
     | promise, and `scamsense:purge` is what enforces it. Change this and the
     | privacy notice together — the two must not drift apart.
     |
     | 90 days is long enough to be useful for retraining and short enough to
     | defend as proportionate.
     */
    'retention' => [
        'submission_days' => (int) env('RETENTION_SUBMISSION_DAYS', 90),
    ],

    /*
     | Where the admin dashboard reads model performance from.
     |
     | It reads the committed metrics.json artefacts rather than storing its own
     | numbers, so a figure on the dashboard and a figure in Chapter 4 cannot
     | drift apart. Laravel never runs Python (AGENTS.md §2 rule 2) — this is a
     | file read, not a call into the ML service.
     */
    'models_path' => env('ML_MODELS_PATH', base_path('../ml-service/models')),

    /*
     | The artefacts actually being served, so the dashboard can mark everything
     | else as superseded or experimental. Must match ml-service/app/predictors.
     */
    'shipped_models' => [
        'url' => 'rf-url-v2.0.0-pathnorm',
        'message' => 'tfidf-msg-v3.0.0-collected-my',
    ],

];
