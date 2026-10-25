<?php

return [
    'brand' => 'OptiNexus',
    'greeting' => 'Halo :name,',
    'action' => 'Buka di OptiNexus',
    'footer' => 'Pesan otomatis dari OptiNexus. Anda menerimanya sebagai Admin tenant terdaftar atau operator OptiNexus yang berwenang. Mohon tidak membalas e-mail ini.',

    'statuses' => [
        'ACTIVE' => 'Aktif', 'SUSPENDED' => 'Ditangguhkan', 'TERMINATED' => 'Diakhiri', 'DISABLED' => 'Dinonaktifkan',
        'DRAFT' => 'Draf', 'ISSUED' => 'Diterbitkan', 'PARTIALLY_PAID' => 'Dibayar sebagian', 'PAID' => 'Lunas',
        'OVERDUE' => 'Lewat jatuh tempo', 'VOID' => 'Dibatalkan (void)', 'CANCELLED' => 'Dibatalkan',
    ],

    'facts' => [
        'customer' => 'Customer', 'customer_code' => 'Kode customer', 'tenant' => 'Tenant', 'user' => 'Pengguna', 'email' => 'E-mail',
        'previous_status' => 'Status sebelumnya', 'new_status' => 'Status baru', 'invoice_number' => 'Nomor invoice',
        'total' => 'Total', 'balance_due' => 'Sisa tagihan', 'due_date' => 'Jatuh tempo', 'issue_date' => 'Tanggal terbit',
        'item' => 'Plan/Add-on', 'price_type' => 'Jenis harga', 'currency' => 'Mata uang', 'unit_amount' => 'Harga satuan',
        'tiered' => 'Lihat tingkatan harga', 'effective_from' => 'Berlaku mulai', 'effective_until' => 'Berlaku hingga',
    ],

    'customer' => [
        'subject' => 'Customer :name kini :status',
        'intro' => 'Customer :name (:code) kini berstatus :status.',
        'follow_up' => 'Selama status ini berlaku, tenant milik customer ini tidak dapat menggunakan aplikasinya. Hubungi administrator OptiNexus untuk tindak lanjut.',
    ],
    'tenant' => [
        'subject' => 'Tenant :name kini :status',
        'intro' => 'Tenant :name kini berstatus :status.',
        'follow_up' => 'Selama status ini berlaku, pengguna tenant ini tidak dapat mengakses aplikasinya. Hubungi administrator OptiNexus untuk tindak lanjut.',
    ],
    'user' => [
        'subject' => 'Pengguna :name kini :status',
        'intro' => 'Akun :name (:email) kini berstatus :status.',
        'follow_up' => 'Pengguna ini tidak dapat masuk ke aplikasi sampai akunnya diaktifkan kembali. Tinjau akses akun ini di OptiNexus.',
    ],
    'invoice' => [
        'issued_subject' => 'Invoice baru :number perlu ditindaklanjuti',
        'issued_intro' => 'Invoice :number untuk tenant :tenant telah diterbitkan.',
        'issued_follow_up' => 'Mohon tinjau invoice ini dan selesaikan pembayaran sebelum :due.',
        'status_subject' => 'Status invoice :number: :status',
        'status_intro' => 'Status invoice :number untuk tenant :tenant berubah dari :old menjadi :new.',
        'follow_up' => [
            'OVERDUE' => 'Invoice ini telah melewati jatuh tempo. Mohon segera selesaikan pembayaran sisa tagihan agar layanan tidak terganggu.',
            'PARTIALLY_PAID' => 'Pembayaran sebagian sudah diterima. Sisa tagihan masih harus dibayar sebelum jatuh tempo.',
            'PAID' => 'Pembayaran sudah diterima lengkap. Tidak ada tindakan lanjutan yang diperlukan.',
            'VOID' => 'Invoice ini tidak berlaku lagi. Bila diterbitkan invoice pengganti, Anda akan menerima pemberitahuan terpisah.',
            'CANCELLED' => 'Invoice ini tidak berlaku lagi. Bila diterbitkan invoice pengganti, Anda akan menerima pemberitahuan terpisah.',
            'default' => 'Mohon tinjau invoice ini di OptiNexus atau di aplikasi tenant Anda.',
        ],
        'reminder_subject' => 'Pengingat: invoice :number jatuh tempo :due',
        'reminder_intro' => 'Invoice :number untuk tenant :tenant akan jatuh tempo pada :due.',
        'reminder_follow_up' => 'Mohon selesaikan pembayaran sisa tagihan sebelum tanggal tersebut. Abaikan pengingat ini bila pembayaran sudah dikirim.',
    ],
    'price' => [
        'subject' => 'Perubahan harga :item',
        'intro' => 'Harga :item yang digunakan tenant :tenant telah berubah.',
        'follow_up' => 'Invoice yang sudah diterbitkan tidak berubah. Tinjau harga baru ini dan tindak lanjuti dengan administrator Anda.',
    ],
];
