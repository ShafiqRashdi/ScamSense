<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The About and How-to pages — Bahasa Malaysia
|--------------------------------------------------------------------------
|
| Same rules as lang/ms/verdict.php: `alamat` never `domain`, `tidak pasti`
| never `belum pasti`, plain everyday words. `AI` rather than `kecerdasan
| buatan`, Shafiq's choice: it is how Malaysian news and adverts say it.
|
*/

return [

    'nav' => [
        'label' => 'Menu utama',
        'about' => 'Tentang',
        'howto' => 'Cara guna',
    ],

    'about' => [
        'title' => 'Tentang ScamSense',
        'lead' => 'ScamSense membantu anda menyemak mesej atau pautan yang mencurigakan sebelum anda tekan atau balas. Ia memberi pendapat kedua, bukan jaminan.',

        'ai_heading' => 'Bagaimana AI membantu menyemak',
        'ai' => [
            'messages' => [
                'title' => 'Mesej',
                'body' => 'Kami ajar komputer dengan lebih 1,100 mesej sebenar yang diterima orang di Malaysia, mesej scam dan mesej biasa. Ia belajar cara penipu menulis: mendesak, menjanjikan hadiah, meminta nombor akaun.',
            ],
            'links' => [
                'title' => 'Pautan',
                'body' => 'Kami lihat cara alamat itu ditulis, contohnya nama bank yang diletak di tempat yang salah. Kami tidak pernah membuka pautan itu.',
            ],
            'reported' => [
                'title' => 'Pautan yang pernah dilaporkan',
                'body' => 'Kami juga bertanya kepada dua perkhidmatan keselamatan, Google Safe Browsing dan VirusTotal, sama ada pautan itu pernah dilaporkan.',
            ],
            'official' => [
                'title' => 'Alamat rasmi',
                'body' => 'Alamat bank dan kerajaan yang sebenar disemak dengan senarai rasmi. Bahagian ini bukan AI, ia semakan terus.',
            ],
        ],

        'safe_heading' => 'Bagaimana kami menjaga keselamatan anda',
        'safe' => [
            'never_open' => 'Kami tidak pernah membuka pautan yang anda letakkan.',
            'reviewed' => 'Setiap laporan dibaca oleh manusia dahulu. Tiada sesiapa boleh "mengajar" ScamSense bahawa scam itu selamat hanya dengan melapor berkali-kali.',
        ],

        'limits_heading' => 'Apa yang ScamSense tidak boleh buat',
        'limits' => [
            'wrong' => 'Ia boleh silap. Kadang-kadang ia terlebih berhati-hati, dan laman web sebenar pun boleh dapat amaran.',
            'page' => 'Ia tidak pernah melihat laman itu, hanya alamatnya.',
        ],
    ],

    'howto' => [
        'title' => 'Cara guna ScamSense',
        'lead' => 'Empat langkah, dan maksud setiap jawapan.',

        'steps_heading' => 'Cara menyemak',
        'steps' => [
            'choose' => 'Pilih ":message" atau ":link".',
            'paste' => 'Letakkan apa yang anda terima ke dalam kotak.',
            'press' => 'Tekan ":button".',
            'read' => 'Baca jawapan dan sebab-sebabnya di bawah.',
        ],
        'language' => 'Tukar bahasa dengan butang EN / BM di bahagian atas halaman.',

        'answers_heading' => 'Maksud setiap jawapan',
        'answers' => [
            'high' => 'Jangan buka, jangan balas, dan jangan hantar wang.',
            'medium' => 'Hubungi pihak syarikat secara terus, guna nombor telefon atau aplikasi yang anda sudah ada, bukan nombor dalam mesej itu.',
            'low' => 'Ini bukan jaminan. Jika anda tidak menjangkakan mesej ini, tetap berhati-hati.',
            'verified' => 'Alamat itu memang milik syarikat tersebut. :scope.',
            'unknown' => 'Kami tidak dapat menyemaknya sepenuhnya. Cuba lagi sebentar lagi. Sementara itu, berhati-hati.',
        ],

        'address_heading' => 'Tabiat yang berguna: baca alamatnya',
        'address' => 'Lihat bahagian sebelum tanda / yang pertama (yang berdiri sendiri). Itulah tempat sebenar pautan itu pergi. Pautan scam meniru nama, tetapi tidak pernah alamat yang sebenar.',
        'address_real' => 'alamat Maybank yang sebenar',
        'address_fake' => 'BUKAN Maybank. Ia pergi ke secure-verify.xyz',

        'feedback_heading' => 'Beritahu kami jika kami silap',
        'feedback' => 'Di bawah setiap jawapan ada bahagian ":heading". Setiap laporan dibaca oleh manusia sebelum apa-apa berubah.',

        'lost_heading' => 'Jika anda sudah kehilangan wang',
        'lost' => [
            'bank' => 'Hubungi bank anda sekarang. Guna nombor di belakang kad anda, bukan nombor daripada mana-mana mesej.',
            'nsrc' => 'Hubungi Pusat Respons Scam Kebangsaan (NSRC) di talian :number. Dibuka setiap hari, 8 pagi hingga 8 malam.',
            'mule' => 'Semak dan laporkan akaun atau nombor telefon di Semak Mule: :site',
        ],
        'lost_hurry' => 'Bertindak cepat. Wang kadang-kadang boleh disekat dalam beberapa jam pertama.',
    ],

];
