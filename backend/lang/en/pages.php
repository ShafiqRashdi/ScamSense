<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The About and How-to pages — English
|--------------------------------------------------------------------------
|
| Two rules the copy keeps, both decided with Shafiq:
|
|   - NO ACCURACY PERCENTAGES. "98% accurate" is the misquote docs/user-manual.md
|     warns against, and an older reader takes it as a promise. The F1 figures
|     belong in the report, beside their caveats. The corpus size is stated
|     instead: it is true and it promises nothing.
|   - The verified-address list is named as NOT AI. It is a register lookup,
|     and describing it as model accuracy breaks AGENTS.md (Phase 5).
|
| Button and answer names are not repeated here. The views pass the live
| strings in as placeholders, so renaming a button cannot leave this page
| teaching a label that no longer exists.
|
*/

return [

    'nav' => [
        'label' => 'Main',
        'about' => 'About',
        'howto' => 'How to use',
    ],

    'about' => [
        'title' => 'About ScamSense',
        'lead' => 'ScamSense helps you check a suspicious message or link before you click or reply. It gives you a second opinion, not a guarantee.',

        'ai_heading' => 'How AI helps check it',
        'ai' => [
            'messages' => [
                'title' => 'Messages',
                'body' => 'We taught the computer using over 1,100 real messages received by people in Malaysia, both scams and ordinary ones. It learned how scammers write: rushing you, promising prizes, asking for account numbers.',
            ],
            'links' => [
                'title' => 'Links',
                'body' => 'We look at how the address is written, for example a bank\'s name placed where it does not belong. We never open the link.',
            ],
            'reported' => [
                'title' => 'Reported links',
                'body' => 'We also ask two security services, Google Safe Browsing and VirusTotal, whether the link has been reported before.',
            ],
            'official' => [
                'title' => 'Official addresses',
                'body' => 'Real bank and government addresses are checked against official lists. This part is not AI. It is a direct check.',
            ],
        ],

        'safe_heading' => 'How we keep you safe',
        'safe' => [
            'never_open' => 'We never open the link you paste.',
            'reviewed' => 'Every report is read by a person first. Nobody can teach ScamSense that a scam is safe just by reporting it many times.',
        ],

        'limits_heading' => 'What ScamSense cannot do',
        'limits' => [
            'wrong' => 'It can be wrong. Sometimes it is extra careful, and even a real website can get a warning.',
            'page' => 'It never sees the page itself, only the address.',
        ],
    ],

    'howto' => [
        'title' => 'How to use ScamSense',
        'lead' => 'Four steps, and what each answer means.',

        'steps_heading' => 'Checking something',
        'steps' => [
            'choose' => 'Choose ":message" or ":link".',
            'paste' => 'Paste what you received into the box.',
            'press' => 'Press ":button".',
            'read' => 'Read the answer and the reasons under it.',
        ],
        'language' => 'Switch language with EN / BM at the top of the page.',

        'answers_heading' => 'What each answer means',
        'answers' => [
            'high' => 'Do not open it, do not reply, and do not send money.',
            'medium' => 'Contact the company yourself, using a phone number or app you already have, not one from the message.',
            'low' => 'This is not a guarantee. If you were not expecting it, stay careful.',
            'verified' => 'The address really belongs to that company. :scope.',
            'unknown' => 'We could not finish checking. Try again in a moment, and be careful until then.',
        ],

        'address_heading' => 'A habit worth learning: read the address',
        'address' => 'Look at the part just before the first single slash (/). That is where the link really goes. Scam links copy the name, but never the real address.',
        'address_real' => 'the real Maybank address',
        'address_fake' => 'NOT Maybank. It goes to secure-verify.xyz',

        'feedback_heading' => 'Tell us if we got it wrong',
        'feedback' => 'Under every answer there is a ":heading" section. Every report is read by a person before anything changes.',

        'lost_heading' => 'If you have already lost money',
        'lost' => [
            'bank' => 'Call your bank now. Use the number on the back of your card, not one from any message.',
            'nsrc' => 'Call the National Scam Response Centre (NSRC) at :number. Open daily, 8am to 8pm.',
            'mule' => 'Check and report the account or phone number at Semak Mule: :site',
        ],
        'lost_hurry' => 'Act quickly. Money can sometimes be stopped in the first few hours.',
    ],

];
