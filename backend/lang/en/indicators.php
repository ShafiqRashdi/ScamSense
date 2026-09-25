<?php

declare(strict_types=1);

/*
 | Indicator codes → plain English.
 |
 | Flask sends codes only; every user-facing word lives here (AGENTS.md §4).
 | A code missing from this file leaves a user staring at a raw identifier, so
 | a test asserts that every code the system can emit has an entry in both
 | languages.
 |
 | Written for a reader with no technical vocabulary. "Punycode homograph
 | attack" is accurate and useless; "uses letters that look like other letters"
 | is what a person can act on.
 */

return [
    'ip_literal_host' => 'The address is a string of numbers instead of a company name. Real companies do not do this.',
    'punycode_host' => 'The address uses letters that look like ordinary letters but are not.',
    'brand_lookalike' => 'The address uses a well-known company name, but it is not that company\'s real address.',
    'credential_trick_at_symbol' => 'The address hides where it really goes, using an @ symbol.',
    'url_shortener' => 'This is a shortened link, so the real destination is hidden until you open it.',
    'suspicious_tld' => 'The address ends in a type that scammers use often because it is cheap.',
    'excessive_subdomains' => 'The address has many parts stacked together to look official.',
    'non_standard_port' => 'The address uses an unusual connection setting.',
    'sensitive_keyword_path' => 'The address mentions logging in or verifying an account.',
    'long_random_path' => 'The address contains a long string of random characters.',
    'high_entropy_host' => 'The address looks randomly generated rather than like a real name.',
    'long_hostname' => 'The address is much longer than a real company address usually is.',
    'many_hyphens' => 'The address is joined together with several dashes, which real companies rarely do.',
    'no_https' => 'The connection is not encrypted.',
    'threat_intel_hit' => 'This link has been reported as dangerous by a security service.',
    'verified_institutional_domain' => 'This address belongs to a verified institution.',

    'urgency_language' => 'The message pressures you to act quickly.',
    'prize_claim' => 'The message says you have won something.',
    'credential_request' => 'The message asks for a password or personal details.',
    'payment_demand' => 'The message asks you to pay or transfer money.',
];
