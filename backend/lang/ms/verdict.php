<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Verdict copy — Bahasa Malaysia
|--------------------------------------------------------------------------
|
| Reviewed and corrected by Shafiq. Three corrections worth preserving, since
| the same mistakes are easy to repeat:
|
|   - `pelik`, never `luar biasa`, for "odd" in the suspicious sense.
|     `luar biasa` means extraordinary and often reads positively.
|   - `tidak pasti`, never `belum pasti`. "Not yet certain" implies the verdict
|     will later resolve into something definite. It will not.
|   - `alamat` throughout, never `domain`. The audience includes older adults
|     and `domain` is a technical English term.
|
| `body` and `action` are nested by submission type. Telling someone who pasted
| an SMS that "pautan ini mempunyai beberapa ciri penipuan" announces that the
| system did not read what they sent.
|
*/

return [

    // The masthead line on the result page. A label, not the answer.
    'checked_heading' => 'Kami sudah semak',

    'high' => [
        'label' => 'Berkemungkinan penipuan',
        'heading' => 'Ini kelihatan seperti penipuan',
        'body' => [
            'url' => 'Pautan ini mempunyai beberapa ciri yang sering kami lihat pada pautan penipuan. Kami sangat menasihatkan anda supaya tidak membukanya.',
            'message' => 'Mesej ini mempunyai beberapa ciri yang sering kami lihat pada mesej penipuan. Kami sangat menasihatkan anda supaya jangan balas dan jangan ikut apa-apa arahan di dalamnya.',
        ],
        'action_heading' => 'Apa yang perlu anda buat',
        'action' => [
            'url' => 'Jangan masukkan sebarang kata laluan, maklumat bank atau maklumat peribadi. Jika ada orang menghantar pautan ini kepada anda, jangan balas mesej mereka.',
            'message' => 'Jangan hantar wang, kata laluan atau maklumat peribadi. Jangan buka apa-apa pautan di dalamnya. Jika anda kenal orang yang kononnya menghantar mesej ini, hubungi dia dengan cara lain untuk pastikan.',
        ],
    ],

    /*
     | Keadaan yang paling kerap dilihat pengguna pada pautan yang sah.
     |
     | `action_heading` sengaja tidak menganggap pengguna sedang log masuk.
     | Kebanyakan orang menampal sesuatu kerana mereka ragu-ragu, bukan semasa
     | sedang memasukkan kata laluan.
     */
    'medium' => [
        'label' => 'Tidak pasti',
        'heading' => 'Kami tidak pasti tentang perkara ini',
        'body' => [
            'url' => 'Ada bahagian pautan ini yang kelihatan pelik — tetapi banyak laman web yang sah juga begitu. Kami tidak dapat memastikannya.',
            'message' => 'Ada bahagian mesej ini yang kelihatan pelik — tetapi banyak mesej yang sah juga begitu. Kami tidak dapat memastikannya.',
        ],
        'action_heading' => 'Cara yang paling selamat',
        'action' => [
            'url' => 'Hubungi pihak syarikat secara terus, guna nombor telefon atau aplikasi yang anda memang sudah ada. Jangan guna nombor atau pautan daripada mesej yang menghantar ini.',
            'message' => 'Hubungi pihak syarikat atau orang itu secara terus, guna nombor telefon atau aplikasi yang anda memang sudah ada. Jangan guna nombor atau pautan daripada mesej itu sendiri.',
        ],
        'closing' => 'Jika anda tidak menjangkakan mesej ini, itulah tanda amaran yang paling kuat.',
    ],

    'low' => [
        'label' => 'Tiada tanda amaran',
        'heading' => 'Kami tidak menemui tanda amaran',
        'body' => [
            'url' => 'Pautan ini tidak mempunyai ciri yang biasa kami lihat pada pautan penipuan.',
            'message' => 'Mesej ini tidak mempunyai ciri yang biasa kami lihat pada mesej penipuan.',
        ],
        'action_heading' => 'Tetap perlu diingat',
        'action' => [
            'url' => 'Ini tidak menjamin halaman tersebut selamat. Jika mesej itu datang secara tiba-tiba, tetap berhati-hati.',
            'message' => 'Ini tidak menjamin mesej itu betul-betul daripada orang yang disebut. Jika ia datang secara tiba-tiba, tetap berhati-hati.',
        ],
    ],

    'unknown' => [
        'label' => 'Tidak dapat disemak',
        'heading' => 'Kami tidak dapat menyemaknya sepenuhnya',
        'body' => [
            'url' => 'Sebahagian daripada sistem semakan kami tidak dapat digunakan sekarang, jadi keputusan ini tidak lengkap.',
            'message' => 'Sebahagian daripada sistem semakan kami tidak dapat digunakan sekarang, jadi keputusan ini tidak lengkap.',
        ],
        'action_heading' => 'Apa yang perlu anda buat',
        'action' => [
            'url' => 'Sila cuba lagi dalam beberapa minit. Buat masa ini, anggap pautan ini belum disemak dan jangan masukkan sebarang maklumat peribadi.',
            'message' => 'Sila cuba lagi dalam beberapa minit. Buat masa ini, anggap mesej ini belum disemak. Jangan hantar wang atau maklumat peribadi, dan jangan buka apa-apa pautan di dalamnya.',
        ],
    ],

    'verified' => [
        'label' => 'Alamat disahkan',
        'heading' => 'Ini laman web :institution yang sebenar',
        'body_institution' => ':domain ialah alamat rasmi :institution, sebuah :authority.',
        'body_suffix' => 'Hanya :authority boleh mendaftarkan alamat yang berakhir dengan :domain_suffix. Penipu tidak boleh.',

        'teach_heading' => 'Cara anda boleh semak sendiri',
        'teach' => 'Lihat bahagian sebelum tanda condong (/) yang pertama. Itulah alamat sebenar. Pautan penipuan meniru nama tetapi bukan alamat — :example bukan :institution.',

        'scope_heading' => 'Kami menyemak alamat, bukan halaman',
        'scope' => 'Jika pautan ini datang daripada mesej yang anda tidak jangkakan, tetap berhati-hati. Cara paling selamat ialah taip sendiri alamat bank anda, atau guna aplikasi rasmi mereka, bukannya klik pautan.',
    ],

    'authority' => [
        'bnm_licensed_bank' => 'bank yang dilesenkan oleh Bank Negara Malaysia',
        'bnm_licensed_emoney' => 'e-dompet yang diluluskan oleh Bank Negara Malaysia',
        'bnm_operator' => 'pengendali pembayaran di bawah penyeliaan Bank Negara Malaysia',
        'licensed_utility' => 'syarikat utiliti berlesen',
        'mcmc_licensee' => 'syarikat telekomunikasi yang dilesenkan oleh MCMC',
        'mcmc_postal_licensee' => 'syarikat pos yang dilesenkan oleh MCMC',
        'ssm_registered_platform' => 'syarikat yang berdaftar di Malaysia',
        'gov_concessionaire' => 'penyedia perkhidmatan yang dilantik oleh kerajaan',
        'mynic_restricted' => 'agensi kerajaan Malaysia',
    ],

    'partial' => [
        'notice' => 'Sebahagian semakan tidak dapat dilengkapkan, jadi keputusan ini tidak lengkap.',
    ],

    /*
     | Nota ini mesti menerangkan apa yang kami ubah, bukan sekadar tujuannya.
     | Pembaca yang tidak diberitahu bahawa tanda kurung dan "xx" itu kami yang
     | tambah boleh menyangka alamat itu sendiri yang rosak.
     */
    'submitted' => [
        'heading' => 'Apa yang anda semak',
        'note' => 'Kami tukar https kepada hxxps dan letak tanda kurung pada titik, supaya ia tidak boleh dibuka secara tidak sengaja. Itu kami yang buat — alamat sebenar tidak begitu.',
        'note_message' => 'Jika ada pautan dalam mesej anda, kami tukar https kepada hxxps dan letak tanda kurung pada titik supaya ia tidak boleh dibuka secara tidak sengaja. Itu kami yang buat — alamat sebenar tidak begitu.',
    ],

    'confidence' => 'Keyakinan',
    'indicators_heading' => 'Apa yang kami perasan',

    /*
     | Dipaparkan apabila tiada penunjuk yang cukup kuat untuk ditunjukkan.
     | Pembaca perlu tahu bahawa "kami tidak pasti" itulah keputusannya, bukan
     | senarai yang gagal dimuatkan.
     */
    'indicators_none' => [
        'url' => 'Tiada apa-apa yang jelas menonjol. Semakan kami melihat cara alamat itu ditulis, dan alamat ini tidak jelas menyerupai penipuan mahupun laman web terkenal yang sah.',
        'message' => 'Tiada apa-apa yang jelas menonjol. Semakan kami melihat cara ayat itu ditulis, dan mesej ini tidak jelas menyerupai penipuan mahupun mesej biasa.',
    ],

    // Di bawah "Apa yang kami perasan", hanya jika sekurang-kurangnya satu
    // sebab dapat dijumpai dalam teks dan diberi nombor.
    'indicators_hint' => 'Nombor ini sama dengan bahagian yang ditanda dalam apa yang anda semak.',

    'anatomy' => [
        'heading' => 'Cara membaca alamat ini',
        'owner' => 'Siapa pemilik alamat ini. Bahagian inilah yang perlu anda semak.',
        'copied' => 'Nama yang ditiru. Sesiapa pun boleh letak nama ini di sini.',
        'tail' => 'Apa-apa selepas tanda condong (/) yang pertama tidak mengubah pemiliknya.',
    ],

    // "Beri amaran kepada keluarga", untuk keputusan berisiko tinggi sahaja.
    // Kekalkan "berkemungkinan": kami tidak mendakwa lebih daripada keputusan.
    'share' => [
        'heading' => 'Beri amaran kepada keluarga',
        'explain' => 'Mesej penipuan selalu dikongsi dalam kumpulan keluarga. Salin ini dan hantar kepada mereka.',
        'button' => 'Salin mesej',
        'copied' => 'Sudah disalin',
        'note' => 'Pautan kekal dalam bentuk selamat, jadi tiada siapa boleh membukanya secara tidak sengaja.',
        'message' => [
            'url' => 'ScamSense mendapati pautan ini berkemungkinan penipuan. Jangan buka:',
            'message' => 'ScamSense mendapati mesej ini berkemungkinan penipuan. Jangan balas, jangan buka apa-apa pautan di dalamnya, dan jangan hantar wang.',
        ],
    ],

    'check_another' => 'Semak perkara lain',
];
