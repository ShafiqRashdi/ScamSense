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

];
