<?php

return [
    'brand' => 'OptiNexus',
    'language' => 'Bahasa',

    'mail' => [
        'subject' => 'Anda diundang ke :application (:tenant) di OptiNexus',
        'greeting' => 'Halo :name,',
        'intro' => ':inviter mengundang Anda untuk menggunakan :application di :tenant.',
        'intro_unknown' => 'Anda diundang untuk menggunakan :application di :tenant.',
        'action' => 'Buka tautan di bawah untuk membuat kata sandi Anda sendiri dan memastikan alamat e-mail ini milik Anda:',
        'validity' => 'Tautan hanya berlaku sekali dan sampai :expires.',
        'ignore' => 'Jika Anda tidak merasa diundang, abaikan pesan ini. Tidak ada yang terjadi sebelum Anda membuka tautan dan menyetel kata sandi.',
        'footer' => 'Pesan otomatis dari OptiNexus. Mohon tidak membalas.',
    ],

    'form' => [
        'title' => 'Terima undangan Anda',
        'lead' => 'Anda diundang untuk menggunakan :application di :tenant.',
        'email' => 'Alamat e-mail',
        'name' => 'Nama Anda',
        'password' => 'Buat kata sandi',
        'password_hint' => 'Minimal :min karakter. Hanya Anda yang mengetahuinya.',
        'password_confirmation' => 'Ulangi kata sandi',
        'current_password' => 'Kata sandi OptiNexus Anda saat ini',
        'existing_notice' => 'Alamat e-mail ini sudah memiliki akun OptiNexus. Konfirmasi dengan kata sandi Anda saat ini untuk menambahkan akses ini ke akun tersebut. Kata sandi Anda tidak berubah.',
        'submit_new' => 'Simpan kata sandi dan lanjutkan',
        'submit_existing' => 'Konfirmasi dan tambahkan akses',
        'verify_note' => 'Membuka undangan ini dari kotak surat Anda memastikan bahwa alamat e-mail ini milik Anda.',
    ],

    'done' => [
        'title' => 'Akses Anda sudah siap',
        'lead' => 'Alamat e-mail Anda sudah terkonfirmasi dan :application siap digunakan di :tenant.',
        'next' => 'Lanjut ke :application dan masuk dengan :email.',
        'continue' => 'Lanjut ke :application',
        'no_link' => 'Buka :application dari alamat yang biasa Anda pakai, lalu pilih masuk dengan OptiNexus.',
    ],

    'states' => [
        'invalid' => [
            'title' => 'Tautan undangan ini tidak valid',
            'text' => 'Tautan tidak lengkap, sudah diganti oleh undangan yang lebih baru, atau tidak pernah ada.',
            'action' => 'Buka e-mail undangan yang paling baru, atau minta administrator yang mengundang Anda untuk mengirimkannya kembali.',
        ],
        'used' => [
            'title' => 'Undangan ini sudah digunakan',
            'text' => 'Akses untuk undangan ini sudah disiapkan.',
            'action' => 'Masuk ke aplikasi Anda dengan alamat e-mail dan kata sandi yang Anda buat.',
        ],
        'revoked' => [
            'title' => 'Undangan ini telah ditarik',
            'text' => 'Administrator membatalkan undangan ini.',
            'action' => 'Minta administrator organisasi Anda untuk mengundang Anda kembali.',
        ],
        'expired' => [
            'title' => 'Undangan ini sudah kedaluwarsa',
            'text' => 'Tautan undangan hanya berlaku untuk waktu terbatas.',
            'action' => 'Minta administrator yang mengundang Anda untuk mengirim ulang undangan. Tautan baru akan dikirim ke e-mail Anda.',
        ],
        'unavailable' => [
            'title' => 'Undangan ini tidak dapat digunakan lagi',
            'text' => 'Organisasi, aplikasi, atau kewenangan pengundang berubah sejak undangan dikirim.',
            'action' => 'Minta administrator organisasi Anda meninjau akses dan mengundang Anda kembali.',
        ],
        'roles_changed' => [
            'title' => 'Akses yang diundang berubah',
            'text' => 'Salah satu peran dalam undangan ini tidak dapat diberikan lagi. Tidak ada akses yang diberikan.',
            'action' => 'Minta administrator mengundang Anda kembali dengan peran yang berlaku saat ini.',
        ],
        'not_linkable' => [
            'title' => 'Undangan ini tidak dapat digunakan untuk alamat e-mail ini',
            'text' => 'Akun di balik alamat e-mail ini tidak dapat menerima akses ini.',
            'action' => 'Hubungi administrator yang mengundang Anda.',
        ],
        'locked' => [
            'title' => 'Terlalu banyak percobaan',
            'text' => 'Undangan ini dikunci sementara karena kata sandi dimasukkan salah beberapa kali.',
            'action' => 'Coba lagi dalam :minutes menit, atau minta administrator mengirim undangan baru.',
        ],
        'conflict' => [
            'title' => 'Alamat e-mail ini baru saja terdaftar',
            'text' => 'Akun untuk alamat e-mail ini baru saja dibuat.',
            'action' => 'Buka tautan undangan sekali lagi dan konfirmasi dengan kata sandi akun tersebut.',
        ],
        'no_seat' => [
            'title' => 'Tidak ada kursi kosong untuk akses ini',
            'text' => 'Organisasi Anda sedang tidak memiliki kursi kosong untuk aplikasi ini, sehingga tidak ada akses yang diberikan.',
            'action' => 'Minta administrator organisasi Anda mengosongkan kursi atau menaikkan batas pengguna, lalu buka undangan ini lagi.',
        ],
        'seat_retry' => [
            'title' => 'Kursi Anda belum dapat dipesan saat ini',
            'text' => 'Kursi tidak dapat diperiksa saat ini. Tidak ada akses yang diberikan dan undangan Anda masih berlaku.',
            'action' => 'Tunggu sebentar lalu buka tautan undangan lagi.',
        ],
        'wrong_password' => [
            'title' => 'Kata sandi tidak benar',
            'text' => 'Kata sandi saat ini tidak dapat dikonfirmasi.',
            'action' => 'Silakan coba lagi.',
        ],
    ],

    'errors' => [
        'name_required' => 'Masukkan nama Anda.',
        'password_required' => 'Buat kata sandi.',
        'password_min' => 'Kata sandi minimal :min karakter.',
        'password_confirmed' => 'Kedua kata sandi tidak sama.',
        'current_password_required' => 'Masukkan kata sandi Anda saat ini.',
        'wrong_password' => 'Kata sandi tidak benar. Sisa percobaan: :left.',
        'rate_limited' => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.',
    ],
];
