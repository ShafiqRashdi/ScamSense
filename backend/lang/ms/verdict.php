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
*/

return [

    'high' => [
        'label' => 'Berkemungkinan penipuan',
        'heading' => 'Ini kelihatan seperti penipuan',
        'body' => 'Pautan ini mempunyai beberapa ciri yang sering kami lihat pada pautan penipuan. Kami sangat menasihatkan anda supaya tidak membukanya.',
        'action_heading' => 'Apa yang perlu anda buat',
        'action' => 'Jangan masukkan sebarang kata laluan, maklumat bank atau maklumat peribadi. Jika ada orang menghantar pautan ini kepada anda, jangan balas mesej mereka.',
    ],

    /*
     | Keadaan yang paling kerap dilihat pengguna pada pautan yang sah.
     */
    'medium' => [
        'label' => 'Tidak pasti',
        'heading' => 'Kami tidak pasti tentang pautan ini',
        'body' => 'Ada bahagian pautan ini yang kelihatan pelik — tetapi banyak laman web yang sah juga begitu. Kami tidak dapat memastikannya.',
        'action_heading' => 'Sebelum anda masukkan kata laluan atau maklumat bank',
        'action' => 'Hubungi pihak syarikat secara terus, guna nombor telefon atau aplikasi yang anda memang sudah ada. Jangan guna nombor atau pautan daripada mesej yang menghantar ini.',
        'closing' => 'Jika anda tidak menjangkakan mesej ini, itulah tanda amaran yang paling kuat.',
    ],

    'low' => [
        'label' => 'Tiada tanda amaran',
        'heading' => 'Kami tidak menemui tanda amaran',
        'body' => 'Pautan ini tidak mempunyai ciri yang biasa kami lihat pada pautan penipuan.',
        'action_heading' => 'Tetap perlu diingat',
        'action' => 'Ini tidak menjamin halaman tersebut selamat. Jika mesej itu datang secara tiba-tiba, tetap berhati-hati.',
    ],

    'unknown' => [
        'label' => 'Tidak dapat disemak',
        'heading' => 'Kami tidak dapat menyemaknya sepenuhnya',
        'body' => 'Sebahagian daripada sistem semakan kami tidak dapat digunakan sekarang, jadi keputusan ini tidak lengkap.',
        'action_heading' => 'Apa yang perlu anda buat',
        'action' => 'Sila cuba lagi dalam beberapa minit. Buat masa ini, anggap pautan ini belum disahkan dan jangan masukkan sebarang maklumat peribadi.',
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

    'submitted' => [
        'heading' => 'Apa yang anda semak',
        'note' => 'Kami paparkannya dengan cara yang tidak boleh dibuka secara tidak sengaja.',
    ],

    'confidence' => 'Keyakinan',
    'indicators_heading' => 'Apa yang kami perasan',
    'check_another' => 'Semak perkara lain',
];
