# Panduan deploy lokal OptiEcosystem dengan Podman Desktop

Panduan ini menyiapkan seluruh platform Opti di satu komputer Windows dengan Podman Desktop, dengan nama **OptiEcosystem**, lalu
menghubungkan OptiNexus, OptiDeli dan OptiRadar supaya peta OptiRadar tampil sebagai iframe di halaman `/radar` OptiDeli.
Referensi bahasa Inggris (semua perintah harian, backup/restore, upgrade): [README.md](README.md). Urutan aktivasi integrasi di
luar container: `docs/ecosystem/ACTIVATION_RUNBOOK.md`.

> **Status pengujian (25-10-2026).** Belum ada yang dijalankan di Podman atau Windows. Yang sudah diuji di sandbox: sintaks
> `docker compose config` untuk kelima produk beserta overlay-nya, dan langkah bootstrap OptiDeli (migrasi, sinkron izin, Superadmin,
> data demo, dua kali berturut-turut) serta cache konfigurasi/route terhadap PostgreSQL. **Belum diuji:** image OptiDeli dan OptiRadar,
> skrip `optiecosystem.ps1` (tidak ada PowerShell di sandbox), Podman Desktop, layanan Java OptiRadar sungguhan dan iframe di browser.
> Bagian yang belum diuji ditandai **(belum diuji)**.

## 1. Apa yang dijalankan

| Produk | URL di browser | Port (127.0.0.1) | Project Compose (instalasi baru) |
| --- | --- | --- | --- |
| OptiNexus (API, issuer OIDC) | `http://nexus.localtest.me:8100` | 8100 | `optiecosystem-nexus` |
| OptiNexus (console) | `http://nexus-console.localtest.me:5180` | 5180 | `optiecosystem-nexus` |
| OptiDeli (console + `/api`) | `http://deli.localtest.me:5200` | 5200, API 8300 | `optiecosystem-deli` |
| OptiRadar (server + OptiRadar Web) | `http://radar.localtest.me:8082` | 8082 | `optiecosystem-radar` |
| OptiFleet (opsional) | `http://fleet.localtest.me:5173` | 5173, API 8000 | `optiecosystem-fleet` |
| OptiEntry (opsional) | `http://entry.localtest.me:5190` | 5190, API 8200 | `optiecosystem-entry` |

Semua container bergabung ke jaringan `optiecosystem` dengan nama publik di atas sebagai alias, sehingga satu URL berlaku untuk
browser maupun antar-container. Nama `*.localtest.me` menunjuk ke 127.0.0.1 lewat DNS publik; bila offline, tambahkan ke
`C:\Windows\System32\drivers\etc\hosts` (sebagai administrator), misalnya `127.0.0.1 deli.localtest.me`.
Setiap produk punya database PostgreSQL sendiri (OptiFleet juga Redis dan MongoDB, OptiEntry Redis). Tidak ada database bersama.

Untuk uji iframe cukup **OptiNexus + OptiDeli + OptiRadar** (sekitar 4 GB RAM). Seluruh platform butuh sekitar 8 GB.

## 2. Prasyarat

1. **Podman Desktop** (versi terbaru, membawa Podman 5.x). Saat pertama dibuka, buat Podman machine:
   *Settings → Resources → Podman → Create new*: CPU 4, Memory 8192 MB (minimal 6144 untuk Nexus+Deli+Radar), Disk 60 GB. Rootful
   maupun rootless sama-sama bisa. Lewat terminal: `podman machine init --cpus 4 --memory 8192 --disk-size 60` lalu `podman machine start`.
2. **Compose**: *Settings → Resources → Compose → Setup* (Podman Desktop memasang `docker-compose` dan `podman compose` memakainya).
   Cek: `podman compose version`. `podman-compose` (Python) hanya cadangan, karena bisa mengabaikan `service_completed_successfully`.
3. **PowerShell 7**: `winget install Microsoft.PowerShell`, lalu buka *PowerShell 7* (bukan Windows PowerShell 5.1, walau itu juga jalan).
4. **Git**: `winget install Git.Git`.
5. Ruang disk minimal 10 GB untuk image dan volume. Build pertama OptiRadar (Gradle + npm) memakan 10 sampai 20 menit dan butuh internet
   ke Maven Central dan npm dari dalam build.

## 3. Ambil kode

Folder yang dipakai di sini: `D:\Project\OptiEcosystem` (sama dengan instalasi yang sudah ada; ganti bila perlu). Semua repository harus
bersebelahan di folder itu.

```powershell
mkdir D:\Project\OptiEcosystem -Force; cd D:\Project\OptiEcosystem
# core.autocrlf=false: skrip shell berjalan di dalam container Linux dan butuh akhir baris LF.
foreach ($r in 'OptiNexus', 'OptiDeli', 'OptiRadar', 'OptiRadar-web', 'OptiFleet-v2', 'OptiAccounting') {
    if (-not (Test-Path $r)) { git clone -c core.autocrlf=false "https://github.com/gsiddik/$r.git" }
}
```

Sampai pull request OptiEcosystem di-merge, tiga repository dipakai dari cabang `claude/project-thread-dnmy18`; sisanya dari `main`:

```powershell
foreach ($r in 'OptiNexus', 'OptiDeli', 'OptiRadar') { git -C $r fetch origin; git -C $r checkout claude/project-thread-dnmy18; git -C $r pull }
foreach ($r in 'OptiRadar-web', 'OptiFleet-v2', 'OptiAccounting') { git -C $r checkout main; git -C $r pull }
```

**Memperbarui kode nanti: `sync`.** `up` dan `build` tidak pernah menyentuh Git: keduanya memakai isi folder repository apa adanya.
Untuk mengambil kode terbaru dipakai perintah terpisah (dari `OptiNexus\deploy\optiecosystem`, setelah OptiNexus sendiri ada):

```powershell
.\optiecosystem.ps1 sync -Products all                 # clone repo yang belum ada (LF), fetch, lalu pull --ff-only di cabang saat ini
.\optiecosystem.ps1 sync -Products all -Branch main    # sekaligus pindah ke main di setiap repo yang punya cabang itu
.\optiecosystem.ps1 upgrade -Products all              # backup, build ulang, buat ulang container (migrasi berjalan di bootstrap)
```

`sync` tidak pernah membuang pekerjaan: repo dengan perubahan yang belum di-commit dilewati (WARN), dan cabang yang sudah menyimpang
dilaporkan (FAIL) tanpa merge atau reset. Ini bukan clone ulang: folder yang sudah ada dipakai lagi, sehingga `.state`, backup dan
volume tetap. Untuk OptiRadar, OptiRadar-web ikut diambil.

Clone lama yang dibuat dengan `core.autocrlf=true`: jalankan `git -C <repo> config core.autocrlf false` lalu
`git -C <repo> checkout -- .` (hanya bila tidak ada perubahan lokal). OptiNexus, OptiDeli, OptiRadar dan OptiFleet-v2 juga memaksa LF
lewat `.gitattributes`, dan Dockerfile OptiDeli/OptiRadar membuang CR, jadi langkah ini terutama untuk OptiAccounting.

## 4. Inisialisasi, build, start

```powershell
cd D:\Project\OptiEcosystem\OptiNexus\deploy\optiecosystem
.\optiecosystem.ps1 init                                 # menulis .state\*.env dengan secret acak (tidak dicetak, tidak di-commit)
.\optiecosystem.ps1 preflight -Products deli,radar        # mesin Podman, compose, memori, port, nama host
.\optiecosystem.ps1 build -Products deli,radar            # pertama kali, atau setelah git pull
.\optiecosystem.ps1 up -Products deli,radar               # OptiNexus selalu ikut dan dinyalakan lebih dulu; menunggu health check
.\optiecosystem.ps1 smoke -Products deli,radar
```

`up` menyalakan OptiNexus dulu, lalu OptiDeli dan OptiRadar; tiap produk punya job `bootstrap` sendiri (migrasi + seed aman produksi).
Di Podman Desktop, menu *Containers* menampilkan grup `optiecosystem-nexus`, `optiecosystem-deli`, `optiecosystem-radar`.

Yang dibuat `init` untuk uji lokal:

| File | Isi penting |
| --- | --- |
| `.state\nexus.env` | `CGO_SUPERADMIN_EMAIL` / `CGO_SUPERADMIN_PASSWORD` (login console Nexus), `OPTIRADAR_BASE_URL=http://radar.localtest.me:8082`, `OPTIRADAR_EMBED_TOKEN` (kosong, diisi di langkah 5.3) |
| `.state\deli.env` | `APP_ENV=local` dan `OPTIDELI_SEED=demo` (tenant `demo` dengan akun demo), `OPTIDELI_DEMO_PASSWORD`, Superadmin `OPTIDELI_PLATFORM_ADMIN_EMAIL` / `_PASSWORD`, `OPTINEXUS_SERVICE_CLIENT_ID/SECRET` (kosong, diisi di langkah 5.4) |
| `.state\radar.env` | `WEB_FRAME_ANCESTORS=http://deli.localtest.me:5200` (origin yang boleh memasang OptiRadar dalam iframe) |

Membaca nilai tanpa membuka file: `(Get-Content .state\deli.env | Select-String OPTIDELI_DEMO_PASSWORD)`. Jangan bagikan isi `.state`.
Setelah mengubah file `.state\*.env`, jalankan lagi `.\optiecosystem.ps1 up -Products deli,radar`: container dibuat ulang, image tidak dibangun ulang.

## 5. Menghubungkan peta OptiRadar ke OptiDeli

Alurnya: browser OptiDeli → `POST /api/v1/telematics/radar-embed` → OptiDeli (service account, scope `embed.create`) →
OptiNexus `POST /api/v1/embed/sessions` → OptiNexus memakai token administrator OptiRadar → `POST /api/optinexus/embed` → token
read-only 10 menit → iframe `http://radar.localtest.me:8082/?embed=1&token=...`. OptiDeli tidak pernah memegang kredensial OptiRadar.

Fungsi bantu untuk sesi PowerShell ini (perintah `artisan` berjalan sebagai `www-data`, pemilik `storage/`):

```powershell
podman ps --format "{{.Names}}"          # pastikan nama container; instalasi lama memakai awalan optinexus-, optiradar-
function nexus { podman exec -i -u www-data optiecosystem-nexus-api-1 @args }
function deli  { podman exec -i -u www-data optiecosystem-deli-api-1 @args }
$radar = 'http://radar.localtest.me:8082'
```

### 5.1 OptiRadar: administrator, grup tenant, perangkat (belum diuji)

1. Buka `http://radar.localtest.me:8082` dan **daftar** akun pertama (akun pertama di database kosong otomatis administrator).
2. Masuk lewat PowerShell dan buat token API administrator (dipakai OptiNexus sebagai `OPTIRADAR_EMBED_TOKEN`):
   ```powershell
   $rs = New-Object Microsoft.PowerShell.Commands.WebRequestSession
   Invoke-RestMethod -Method Post "$radar/api/session" -WebSession $rs -Body @{ email = '<email admin>'; password = '<password admin>' } | Out-Null
   $radarToken = Invoke-RestMethod -Method Post "$radar/api/session/token" -WebSession $rs -Body @{ expiration = '2027-12-31T00:00:00Z' }
   ```
   Simpan token ini terpisah dari token sinkron read-only (`OPTIRADAR_API_TOKEN`), jangan ditempel ke chat.

Grup tenant dibuat di 5.2 setelah UUID tenant OptiNexus diketahui.

### 5.2 OptiNexus: katalog, tenant, aplikasi

1. Masuk ke console `http://nexus-console.localtest.me:5180` dengan `CGO_SUPERADMIN_EMAIL` / `CGO_SUPERADMIN_PASSWORD` dari `.state\nexus.env`.
2. Impor manifest OptiDeli dan OptiRadar (aditif, aman diulang), lalu ulangi seeder supaya role sistem mendapat izin baru:
   ```powershell
   deli php artisan optideli:nexus:manifest --output=/tmp/optideli.manifest.json
   podman cp optiecosystem-deli-api-1:/tmp/optideli.manifest.json "$env:TEMP\optideli.manifest.json"
   podman cp "$env:TEMP\optideli.manifest.json" optiecosystem-nexus-api-1:/tmp/optideli.manifest.json
   podman cp ..\..\docs\ecosystem\manifests\optiradar.manifest.json optiecosystem-nexus-api-1:/tmp/optiradar.manifest.json
   nexus php artisan ecosystem:import-manifest optideli /tmp/optideli.manifest.json --create-application
   nexus php artisan ecosystem:import-manifest optiradar /tmp/optiradar.manifest.json --create-application
   nexus php artisan db:seed --class=ProductionSeeder --force --no-interaction
   ```
3. Console → *Applications* → **OptiRadar** → ubah *Frontend URL* menjadi `http://radar.localtest.me:8082` (URL iframe dibentuk dari sini).
4. Console → *Customers* → buat customer; *Tenants* → buat tenant (misalnya kode `ACME-ID`) dan pastikan statusnya aktif.
   Catat **UUID tenant** (bagian akhir URL `/tenants/<uuid>`).
5. *Tenants* → tenant itu → *Applications* → tambahkan **OptiDeli** dan **OptiRadar** dengan status aktif.
   Catatan langganan: selama aplikasi tidak dijual lewat produk/plan, OptiNexus tidak menuntut langganan. Bila Anda sudah membuat plan
   yang berisi capability OptiDeli atau OptiRadar, tenant butuh langganan aktif yang mencakup keduanya, lalu
   `nexus php artisan ecosystem:refresh-entitlements ACME-ID --apply`.
6. Kembali ke OptiRadar, buat grup tenant dengan atribut `optinexusTenantId` = UUID tenant, dan satu perangkat di grup itu:
   ```powershell
   $tenantId = '<uuid tenant OptiNexus>'
   $g = Invoke-RestMethod -Method Post "$radar/api/groups" -WebSession $rs -ContentType 'application/json' `
        -Body (@{ name = 'ACME-ID'; attributes = @{ optinexusTenantId = $tenantId } } | ConvertTo-Json)
   Invoke-RestMethod -Method Post "$radar/api/devices" -WebSession $rs -ContentType 'application/json' `
        -Body (@{ name = 'Truk 01'; uniqueId = 'truk01'; groupId = $g.id } | ConvertTo-Json) | Out-Null
   ```
   Lewat UI juga bisa: *Settings → Groups → +*, lalu *Attributes* tambahkan kunci `optinexusTenantId`.
   Posisi contoh tanpa membuka port perangkat (protokol OsmAnd di dalam container, **belum diuji**):
   `podman exec optiecosystem-radar-radar-1 curl -fsS "http://127.0.0.1:5055/?id=truk01&lat=-6.2&lon=106.82&speed=0"`

#### Grup tenant otomatis (webhook OptiNexus ke OptiRadar)

Langkah 6 di atas bisa dilewati: bila OptiNexus berlangganan event ke OptiRadar, grup tenant dibuat OptiRadar sendiri segera setelah
OptiRadar ditetapkan ke tenant (yaitu saat langganan tenant mencakup OptiRadar), sebelum ada user yang login. Operator langsung bisa
mendaftarkan perangkat ke grup itu. Sekali saja per instalasi:

```powershell
nexus php artisan ecosystem:register-event-subscription optiradar cgo.tenant.application_assigned --url=http://radar.localtest.me:8082/api/optinexus/events --secret-file=/tmp/radar.whsec
podman cp optiecosystem-nexus-api-1:/tmp/radar.whsec .state\radar.whsec
nexus rm /tmp/radar.whsec
```

Salin nilai `EVENT_WEBHOOK_SECRET` dari `.state\radar.whsec` ke `OPTINEXUS_WEBHOOK_SECRET` di `.state\radar.env`, hapus file `radar.whsec`, lalu
`.\optiecosystem.ps1 up -Products radar`. `EVENT_WEBHOOK_ALLOW_HTTP=true` di `.state\nexus.env` (diisi `init` untuk instalasi baru; tambahkan sendiri
pada instalasi lama lalu `up -Products nexus`) mengizinkan alamat http lokal; di luar uji lokal pakai https. Grup yang sudah ada tidak diubah,
dan grup tidak pernah dihapus saat aplikasi dicabut dari tenant.

### 5.3 OptiNexus: token embed

Isi `OPTIRADAR_EMBED_TOKEN=<nilai $radarToken>` di `.state\nexus.env`, lalu `.\optiecosystem.ps1 up -Products deli,radar`.

### 5.4 OptiDeli: service account dan tautan tenant

1. Buat service account OptiDeli di OptiNexus dengan scope yang dibutuhkan peta (dan feed ke OptiEntry nanti):
   ```powershell
   nexus php artisan ecosystem:register-service-account optideli --scopes=authorization.check,commercial.read,embed.create --secret-file=/tmp/deli.sa
   podman cp optiecosystem-nexus-api-1:/tmp/deli.sa .state\deli.sa
   nexus rm /tmp/deli.sa
   ```
   Salin `M2M_CLIENT_ID` dan `M2M_CLIENT_SECRET` dari `.state\deli.sa` ke `OPTINEXUS_SERVICE_CLIENT_ID` dan
   `OPTINEXUS_SERVICE_CLIENT_SECRET` di `.state\deli.env`, lalu `.\optiecosystem.ps1 up -Products deli,radar`.
   Service account yang sudah ada: perintah yang sama memperbarui scope-nya tanpa secret baru (tidak ada file yang ditulis); tambahkan
   `--rotate` hanya bila secret-nya hilang.
2. Buka `http://deli.localtest.me:5200/platform`, masuk dengan `OPTIDELI_PLATFORM_ADMIN_EMAIL` / `_PASSWORD` dari `.state\deli.env`.
   Di daftar tenant, tenant `demo` → *hubungkan OptiNexus* → tempel UUID tenant dari 5.2.

### 5.5 Uji iframe di browser

1. Keluar dari Superadmin, masuk ke `http://deli.localtest.me:5200` sebagai `dispatcher@optideli.test` dengan `OPTIDELI_DEMO_PASSWORD`.
2. Buka menu **Peta OptiRadar** (`/radar`). Yang diharapkan: peta OptiRadar tanpa menu, hanya perangkat grup tenant (Truk 01).
3. Periksa di DevTools (F12) → *Network*:
   * `POST /api/v1/telematics/radar-embed` → 200 dengan `data.url` = `http://radar.localtest.me:8082/?embed=1&token=...`;
   * dokumen iframe membawa header `Content-Security-Policy: frame-ancestors 'self' http://deli.localtest.me:5200`;
   * tidak ada galat *Refused to frame* di *Console*.
4. `.\optiecosystem.ps1 smoke -Products deli,radar` juga memeriksa header frame-ancestors OptiRadar terhadap origin OptiDeli.

| Gejala | Arti dan perbaikan |
| --- | --- |
| 409 `RADAR_EMBED_NOT_AVAILABLE` | tenant `demo` belum ditautkan (5.4.2) atau `OPTINEXUS_SERVICE_CLIENT_ID/SECRET` kosong di `deli.env` |
| 403 `RADAR_EMBED_NOT_ENTITLED` | tenant Nexus tidak aktif, OptiDeli/OptiRadar tidak terpasang aktif di tenant, atau langganan dituntut tetapi tidak ada (5.2.5) |
| 503 `RADAR_EMBED_UNAVAILABLE` | OptiNexus tak terjangkau, atau OptiRadar menolak: `OPTIRADAR_EMBED_TOKEN` kosong/salah, *Frontend URL* OptiRadar kosong, grup dengan `optinexusTenantId` tidak ada. Lihat `podman logs optiecosystem-nexus-api-1` (`embed.optiradar_failed`) |
| 401/403 dari OptiNexus di log OptiDeli | service account tanpa scope `embed.create` (ulangi perintah 5.4.1; scope diperbarui) |
| iframe kosong, *Refused to frame* | `WEB_FRAME_ANCESTORS` di `radar.env` tidak sama persis dengan origin OptiDeli (skema, host, port) |

## 6. Operasi harian

| Tugas | Perintah |
| --- | --- |
| Status | `.\optiecosystem.ps1 status` |
| Stop / start | `.\optiecosystem.ps1 stop -Products deli,radar` / `start -Products deli,radar` (OptiNexus hanya bila disebut, atau `-Products all`) |
| Hapus container | `.\optiecosystem.ps1 down -Products all` (volume dan `.state` tidak pernah dihapus) |
| Backup / restore | `backup -Products deli,radar`; `restore -Products deli,radar -Backup <folder> -ConfirmReplace` |
| Upgrade | `.\optiecosystem.ps1 sync -Products deli,radar` (atau `git pull` sendiri), lalu `upgrade -Products deli,radar` |
| Log | Podman Desktop → *Containers* → container → *Logs*, atau `podman logs -f optiecosystem-deli-api-1` |

## 7. Instalasi yang sudah ada (`deploy\ecosystem`)

Folder lama `deploy\ecosystem` sekarang bernama `deploy\optiecosystem` dan skripnya `optiecosystem.ps1`. Perintah pertama dari folder baru
menyalin `.state` lama ke sini (yang lama tetap ada). File env yang sudah ada tidak diubah, jadi jaringan `opti-ecosystem` dan project
`optinexus`, `optifleet-v2`, `optiaccounting`, `optiradar` tetap dipakai beserta datanya; produk baru (OptiDeli) langsung memakai nama
`optiecosystem-deli` dan bergabung ke jaringan yang tertulis di `.state\ecosystem.env`. Fungsi bantu di bagian 5 lalu memakai nama
container lama (`optinexus-api-1`). Pindah ke nama OptiEcosystem untuk data yang sudah ada: README, bagian *Names* (lewat backup dan
restore, tidak ada yang dihapus).

## 8. Masalah umum di Podman Desktop

* `preflight` FAIL *Engine reachable*: Podman machine belum jalan (`podman machine start`, atau tombol *Run* di Podman Desktop).
* *Compose provider* tidak ditemukan: pasang Compose dari *Settings → Resources → Compose*.
* Build OptiRadar gagal di `--build-context`: butuh Podman 4.5 atau lebih baru (`podman version`).
* Build gagal karena memori (`Killed`, `cc1`): naikkan memori machine (`podman machine stop; podman machine set --memory 8192; podman machine start`).
* `exec /usr/local/bin/entrypoint.sh: no such file or directory` atau `/bin/sh^M`: akhir baris CRLF, lihat bagian 3.
* Port terpakai: ubah port di `.state\ecosystem.env` dan nilai yang sama di `.state\<produk>.env` (termasuk `*_PUBLIC_URL`), lalu `up`.
* Bootstrap gagal: pesan alasannya tercetak; perbaiki setelan lalu `up` lagi. Waktu tunggu dibatasi (90 dtk database, 300 dtk skema).
