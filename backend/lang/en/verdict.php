<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Verdict copy — English
|--------------------------------------------------------------------------
|
| The wording here is the product, not decoration. Two rules govern it:
|
| 1. Never claim a page is safe. ScamSense never fetches a submitted URL, so it
|    knows who registered an address and nothing about what the page is doing.
|    Every reassuring string is scoped to the address.
|
| 2. Never quote a probability to the user. The measured band figures come from
|    a corpus we know is unrepresentative of deployment (docs/adr/0006), so
|    "about 1 in 4 links like this are scams" would assert a calibration this
|    system cannot defend.
|
*/

return [

    'high' => [
        'label' => 'Likely a scam',
        'heading' => 'This looks like a scam',
        'body' => 'This link has several features we see in scam links. We strongly suggest you do not open it.',
        'action_heading' => 'What to do',
        'action' => 'Do not enter any password, banking details or personal information. If someone sent you this, do not reply to them.',
    ],

    /*
     | The band most users will see on a legitimate link — seven of seventeen
     | genuine Malaysian sites landed here in testing. It has to read as
     | "we are not certain" without alarming or reassuring.
     */
    'medium' => [
        'label' => 'Not certain',
        'heading' => 'We are not certain about this one',
        'body' => 'Parts of this link look odd — but plenty of genuine websites look this way too. We cannot tell you for sure.',
        'action_heading' => 'Before you enter any password or banking details',
        'action' => 'Contact the company yourself, using a phone number or app you already have. Do not use a number or link from the message that sent you this.',
        'closing' => 'If you were not expecting this message, that is the strongest warning sign of all.',
    ],

    'low' => [
        'label' => 'No warning signs',
        'heading' => 'We did not find warning signs',
        'body' => 'This link does not have the features we usually see in scam links.',
        'action_heading' => 'Still worth remembering',
        'action' => 'This does not guarantee the page is safe. If the message was unexpected, be careful anyway.',
    ],

    'unknown' => [
        'label' => 'Could not check',
        'heading' => 'We could not check this properly',
        'body' => 'Part of our checking system is unavailable right now, so this result is incomplete.',
        'action_heading' => 'What to do',
        'action' => 'Please try again in a few minutes. In the meantime, treat the link as unverified and do not enter any personal details.',
    ],

    /*
     | The verified-address panel. The most reassuring output the platform can
     | produce, and the one most at risk of being read as "this is safe".
     |
     | The scoping paragraph is not optional and must never be collapsed behind
     | a toggle or pushed below a fold.
     */
    'verified' => [
        'label' => 'Verified address',
        'heading' => 'This is the real :institution website',
        'body_institution' => ':domain is the official address of :institution, a :authority.',
        'body_suffix' => 'Only :authority can register an address ending in :domain_suffix. Scammers cannot.',

        'teach_heading' => 'How to check this yourself',
        'teach' => 'Look at the part just before the first single slash. That is the real address. Scam links copy the name but not the address — :example is not :institution.',

        'scope_heading' => 'We checked the address, not the page',
        'scope' => 'If this link arrived in a message you were not expecting, be careful anyway. The safest habit is to type your bank\'s address yourself, or use their official app, instead of opening links.',
    ],

    'authority' => [
        'bnm_licensed_bank' => 'bank licensed by Bank Negara Malaysia',
        'bnm_licensed_emoney' => 'e-wallet approved by Bank Negara Malaysia',
        'bnm_operator' => 'payment operator overseen by Bank Negara Malaysia',
        'licensed_utility' => 'licensed utility company',
        'mcmc_licensee' => 'telecommunications company licensed by MCMC',
        'mcmc_postal_licensee' => 'postal company licensed by MCMC',
        'ssm_registered_platform' => 'company registered in Malaysia',
        'gov_concessionaire' => 'appointed government service provider',
        'mynic_restricted' => 'Malaysian government agencies',
    ],

    'partial' => [
        'notice' => 'Some checks could not be completed, so this result is incomplete.',
    ],

    'submitted' => [
        'heading' => 'What you checked',
        'note' => 'We have shown this in a way that cannot be opened by accident.',
    ],

    'confidence' => 'Confidence',
    'indicators_heading' => 'What we noticed',
    'check_another' => 'Check something else',
];
