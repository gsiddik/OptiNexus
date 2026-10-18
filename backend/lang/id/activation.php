<?php

return [
    'activate' => [
        'title' => 'Pilih kata sandi Anda',
        'lead' => 'Ini adalah masuk pertama Anda. Ganti kata sandi sementara dengan kata sandi yang hanya Anda ketahui.',
        'email' => 'Email',
        'temporary_password' => 'Kata sandi sementara',
        'temporary_hint' => 'Yang diberikan administrator Anda. Hanya berlaku sekali dan diganti saat Anda menyimpan.',
        'password' => 'Kata sandi baru',
        'password_hint' => 'Minimal :min karakter. Jangan memakai kembali kata sandi sementara.',
        'password_confirmation' => 'Ulangi kata sandi baru',
        'submit' => 'Simpan kata sandi dan lanjut',
        'note' => 'Sebelum langkah ini selesai, akun Anda belum dapat membuka aplikasi apa pun.',
    ],

    'errors' => [
        'temporary_required' => 'Masukkan kata sandi sementara.',
        'wrong_password' => 'Kata sandi sementara tidak benar. Sisa :left percobaan sebelum akun ini dikunci sementara.',
        'password_required' => 'Pilih kata sandi baru.',
        'password_min' => 'Kata sandi minimal :min karakter.',
        'password_confirmed' => 'Kedua kata sandi tidak sama.',
        'same_password' => 'Pilih kata sandi yang berbeda dari kata sandi sementara.',
        'locked' => 'Terlalu banyak percobaan. Coba lagi dalam :minutes menit, atau hubungi administrator Anda.',
        'expired' => 'Kata sandi sementara ini sudah kedaluwarsa. Minta administrator Anda menerbitkan yang baru.',
        'session' => 'Permintaan masuk Anda sudah kedaluwarsa. Buka kembali aplikasinya lalu masuk.',
    ],

    'states' => [
        'locked' => [
            'title' => 'Terlalu banyak percobaan',
            'text' => 'Akun ini dikunci sementara karena kata sandi sementara salah dimasukkan beberapa kali.',
            'action' => 'Coba lagi dalam :minutes menit, atau hubungi administrator Anda.',
        ],
        'expired' => [
            'title' => 'Kata sandi sementara sudah kedaluwarsa',
            'text' => 'Kata sandi sementara hanya berlaku untuk waktu yang terbatas.',
            'action' => 'Minta administrator Anda menerbitkan kata sandi sementara yang baru.',
        ],
        'used' => [
            'title' => 'Kata sandi ini sudah diganti',
            'text' => 'Kata sandi sementara sudah dipakai dan tidak berlaku lagi.',
            'action' => 'Masuk ke aplikasi Anda dengan alamat email dan kata sandi yang Anda pilih.',
        ],
        'invalid' => [
            'title' => 'Langkah ini tidak dapat dilanjutkan',
            'text' => 'Permintaan masuk Anda sudah tidak berlaku. Mungkin sudah diterbitkan kata sandi sementara yang lebih baru.',
            'action' => 'Buka kembali aplikasinya lalu masuk dengan kata sandi sementara yang terbaru.',
        ],
    ],

    'pending' => [
        'title' => 'Menunggu verifikasi email',
        'text' => 'Kata sandi Anda sudah diatur. Administrator Anda masih perlu mencatat bahwa alamat email Anda sudah diverifikasi sebelum Anda dapat membuka aplikasi apa pun.',
        'action' => 'Hubungi administrator Anda. Setelah mereka mengonfirmasi, masuk kembali dari aplikasi Anda dengan kata sandi yang Anda pilih.',
    ],
];
