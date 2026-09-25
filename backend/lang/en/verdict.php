<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Verdict copy — English
|--------------------------------------------------------------------------
|
| The wording here is the product, not decoration. Three rules govern it:
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
| 3. Say "link" only about links. `body` and `action` are nested by submission
|    type because a person who pasted an SMS and is told "this link has several
|    features we see in scam links" learns that the system did not read what
|    they sent. A test asserts both types exist for every band, so adding a band
|    cannot half-cover them.
|
*/

return [

    // The masthead line on the result page. A label, not the answer.
    'checked_heading' => 'We checked it',

    'high' => [
        'label' => 'Likely a scam',
        'heading' => 'This looks like a scam',
        'body' => [
            'url' => 'This link has several features we see in scam links. We strongly suggest you do not open it.',
            'message' => 'This message has several features we see in scam messages. We strongly suggest you do not reply or follow any instructions in it.',
        ],
        'action_heading' => 'What to do',
        'action' => [
            'url' => 'Do not enter any password, banking details or personal information. If someone sent you this, do not reply to them.',
            'message' => 'Do not send any money, password or personal information. Do not open any link in it. If you know the person it claims to be from, contact them another way to check.',
        ],
    ],

    /*
     | The band most users will see on a legitimate link — seven of seventeen
     | genuine Malaysian sites landed here in testing. It has to read as
     | "we are not certain" without alarming or reassuring.
     |
     | `action_heading` deliberately does not presuppose the reader is mid-login.
     | Most people paste something *because* they were unsure, not while
     | entering a password.
     */
    'medium' => [
        'label' => 'Not certain',
        'heading' => 'We are not certain about this one',
        'body' => [
            'url' => 'Parts of this link look odd — but plenty of genuine websites look this way too. We cannot tell you for sure.',
            'message' => 'Parts of this message look odd — but plenty of genuine messages look this way too. We cannot tell you for sure.',
        ],
        'action_heading' => 'The safest thing to do',
        'action' => [
            'url' => 'Contact the company yourself, using a phone number or app you already have. Do not use a number or link from the message that sent you this.',
            'message' => 'Contact the company or person yourself, using a phone number or app you already have. Do not use a number or link from the message itself.',
        ],
        'closing' => 'If you were not expecting this message, that is the strongest warning sign of all.',
    ],

    'low' => [
        'label' => 'No warning signs',
        'heading' => 'We did not find warning signs',
        'body' => [
            'url' => 'This link does not have the features we usually see in scam links.',
            'message' => 'This message does not have the features we usually see in scam messages.',
        ],
        'action_heading' => 'Still worth remembering',
        'action' => [
            'url' => 'This does not guarantee the page is safe. If the message was unexpected, be careful anyway.',
            'message' => 'This does not guarantee the message is genuine. If it arrived unexpectedly, be careful anyway.',
        ],
    ],

    'unknown' => [
        'label' => 'Could not check',
        'heading' => 'We could not check this properly',
        'body' => [
            'url' => 'Part of our checking system is unavailable right now, so this result is incomplete.',
            'message' => 'Part of our checking system is unavailable right now, so this result is incomplete.',
        ],
        'action_heading' => 'What to do',
        'action' => [
            'url' => 'Please try again in a few minutes. In the meantime, treat this link as unchecked and do not enter any personal details.',
            'message' => 'Please try again in a few minutes. In the meantime, treat this message as unchecked. Do not send money or personal details, and do not open any link in it.',
        ],
    ],

    /*
     | The verified-address panel. The most reassuring output the platform can
     | produce, and the one most at risk of being read as "this is safe".
     |
     | It renders ABOVE the verdict card, because it is the answer the user came
     | for and a generic hedge read first is a hedge read instead. The scoping
     | paragraph is not optional and must never be collapsed behind a toggle or
     | pushed below a fold.
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

    /*
     | Shown defanged inside a <pre>. The note has to explain the defanging, not
     | just its purpose: a reader who is not told that the brackets and the "xx"
     | were added by us can reasonably conclude the address itself is malformed,
     | and take that as the suspicious finding.
     */
    'submitted' => [
        'heading' => 'What you checked',
        'note' => 'We changed https to hxxps and put brackets around the dots, so this cannot be opened by accident. That was us — the real address does not have them.',
        'note_message' => 'If there was a link in your message, we changed https to hxxps and put brackets around the dots so it cannot be opened by accident. That was us — the real address does not have them.',
    ],

    'confidence' => 'Confidence',
    'indicators_heading' => 'What we noticed',

    /*
     | Shown when the model reached a verdict but no indicator crossed its
     | threshold. Common in the medium band, and the band where a reader most
     | needs to know that "we are not certain" is the whole finding rather than
     | a list that failed to load. See app/indicators.py for why several
     | high-importance features are deliberately never surfaced.
     */
    'indicators_none' => [
        'url' => 'Nothing specific stood out. Our checks look at how the address is written, and this one did not clearly match either a scam or a well-known genuine site.',
        'message' => 'Nothing specific stood out. Our checks look at the wording, and this one did not clearly match either a scam or an ordinary message.',
    ],

    'check_another' => 'Check something else',
];
