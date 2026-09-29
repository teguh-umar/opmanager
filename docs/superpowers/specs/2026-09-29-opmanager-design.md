---
date: 2026-09-29
tags: [glpi, opmanager, pgn-glpi-suite, design]
status: active
---

# Plugin `opmanager` — Spesifikasi Desain v1

Integrasi ManageEngine OpManager ↔ GLPI 11 (VM-HQ-GLPI, core 11.0.5) sebagai plugin ke-7
PGN GLPI Suite. Hub koordinasi: `…/Documents/PGN GLPI Suite/_pgn-glpi-suite.md`.

Dokumen ini hasil sesi brainstorming 2026-09-23 s.d. 2026-09-29 dengan user. Semua
keputusan di §2 berasal dari user; hal yang merupakan **usulan** (belum diputuskan
eksplisit) ditandai *(usulan)* dan boleh dikoreksi saat review.

## 1. Tujuan & ruang lingkup

**Masuk v1:**

- Mencocokkan perangkat OpManager ke aset GLPI yang sudah ada (tanpa menulis ke aset).
- Sub-tab **Monitoring** (status + alarm terbaru) di tab bersama **PGN Suite** pada
  Computer, NetworkEquipment, Security, Storage.
- Tiket GLPI dari alarm OpManager, dua arah: buat / gabung / buka kembali / Solved dari
  OpManager, dan notes + clear alarm ke OpManager dari aksi teknisi di GLPI.
- Webhook OpManager → plugin sebagai jalur utama, polling API OpManager sebagai jaring
  pengaman.

**Tidak masuk v1:**

- Membuat atau mengubah aset GLPI dari data OpManager (sinkron opsi B/C). Catatan : Data GLPI menjadi prioritas.
  Alasan: aset di VM diisi GLPI Native Inventory (dua sumber akan saling menimpa), IP
  ganda di GLPI membuat pembuatan aset rawan duplikat, dan OpManager adalah sistem
  monitoring, bukan CMDB. **Prinsip tetap (D15):** kalau opsi B/C dibangun kelak, data
  GLPI selalu menang, dan OpManager paling jauh hanya mengisi field yang kosong.
- Feed Finding ke riskregister (user: tidak perlu).
- Webhook bawaan GLPI untuk arah keluar (diganti hook plugin, lihat §2 D16).
- Pemanggilan API GLPI (v1 atau v2) oleh OpManager (lihat §2 D17).
- Mendorong data GLPI ke OpManager selain notes dan clear alarm.

## 2. Log keputusan

| # | Keputusan | Tanggal |
|---|---|---|
| D1 | Key plugin `opmanager`; namespace di hub §2 | 2026-09-23 |
| D2 | Sinkron = **hanya mencocokkan**, tidak pernah menulis ke aset GLPI | 2026-09-23 |
| D3 | 1 ID alarm OpManager = maksimal 1 tiket | 2026-09-23 |
| D4 | Dropdown mode pencocokan seperti integrasipaman + mode `manual` | 2026-09-23 |
| D5 | Monitoring = sub-tab di tab bersama PGN Suite, bukan tab sendiri | 2026-09-24 |
| D6 | Alarm clear → tiket **Solved**; ID alarm sama aktif lagi → **buka kembali + followup** | 2026-09-29 |
| D7 | Plugin sebagai jembatan di dalam GLPI: webhook OpManager masuk, API OpManager keluar, cron pengaman. Webhook bawaan GLPI tidak dipakai | 2026-09-29 |
| D8 | Teknisi Solve/Close di GLPI → notes + **clear** alarm di OpManager | 2026-09-29 |
| D9 | Setelah clear manual, OpManager memberi **ID alarm baru** kalau kondisi masih ada (fakta dari user) | 2026-09-29 |
| D10 | Alarm baru dengan perangkat + jenis sama dalam jendela waktu (default 24 jam) setelah tiket sebelumnya Solved/Closed → **Linked tickets** + followup | 2026-09-29 |
| D11 | Severity yang dibuatkan tiket diatur di Setup; default **Critical saja** | 2026-09-29 |
| D12 | Badai di satu perangkat → 1 tiket per perangkat; badai lintas perangkat → dependency OpManager + rem darurat plugin | 2026-09-29 |
| D13 | Batas coba ulang kiriman keluar diatur di Setup, default 10 | 2026-09-29 |
| D14 | Entity tiket: entity aset → entity IP network GLPI yang mencakup IP perangkat → entity default | 2026-09-29 |
| D15 | Data GLPI menjadi prioritas; OpManager tidak pernah menimpa data aset GLPI | 2026-09-29 |
| D16 | Arah keluar memakai **hook plugin**, bukan webhook bawaan GLPI. Alasan user: awalnya lebih rumit, tapi operasionalnya lebih mudah. Alasan teknis: tiket gabungan (D12) butuh satu clear per alarm, dan Solved oleh OpManager harus dibedakan dari Solved oleh teknisi | 2026-09-29 |
| D17 | OpManager tidak memanggil API GLPI. API v2 memakai token OAuth 1 jam; API v1 butuh `initSession` lalu Session-Token yang juga kedaluwarsa (`APIRest.php:174` → `checkSessionToken()`). Selain itu, D3/D6/D10/D12 butuh logika "cek dulu, baru tulis" yang tidak bisa dilakukan oleh webhook | 2026-09-29 |
| D18 | Tiket memakai **template tiket GLPI** (default GLPI atau kustom) yang dipilih di Setup; semua isian mengikuti field GLPI (§7) | 2026-09-29 |

## 3. Arsitektur

```
OpManager ──webhook──▶ Penerima (stateless) ──┐
OpManager ◀──API── Cron AlarmSync/DeviceSync ─┤
                                               ▼
                                  Mesin alarm → tiket ◀── hook Ticket / Followup / Solution
                                               │                     │
                                               ▼                     ▼
                              tabel plugin + Ticket GLPI     Antrean → Klien API OpManager
```

| Komponen | Kelas *(usulan)* | Tugas |
|---|---|---|
| Penerima webhook | `front/webhook.php` → `Webhook\Receiver` | Validasi token, parse isi, serahkan ke mesin. Path didaftarkan stateless via `Glpi\Http\SessionManager::registerPluginStatelessPath()` (tanpa sesi, tanpa CSRF — diverifikasi di `src/Glpi/Http/SessionManager.php:72` dan `CheckCsrfListener`) |
| Klien API OpManager | `OpManager\Client` | Semua panggilan HTTP ke OpManager; API key terdekripsi saat dipakai |
| Pencocok aset | `Matching\Matcher` | 5 mode, pin manual, IP ganda → perlu dicek (§5) |
| Mesin alarm → tiket | `Alarm\Engine` | Satu-satunya pengambil keputusan tiket (§7). Webhook dan cron sama-sama lewat sini |
| Hook tiket | `Hooks\TicketHooks` | Deteksi aksi teknisi → isi antrean (§8) |
| Antrean keluar | `Outbound\Queue` | Kirim, coba ulang dengan jeda bertambah, tandai gagal |
| Cron | `Cron` | `AlarmSync` (default 10 mnt), `DeviceSync` (default 60 mnt), `QueueRetry` (default 5 mnt). Semua **mode CLI** (lihat hub §4 soal run mode per task) |
| Sub-tab Monitoring | `AssetSections` | Provider `sections()` untuk `AssetTab` PGN Suite (§9) |
| Setup | `SuiteConfig` | Section di tab PGN Suite config (§10) |

## 4. Namespace & aturan suite yang wajib

- Tabel `glpi_plugin_opmanager_*`, config context `plugin:opmanager`, PSR-4
  `GlpiPlugin\Opmanager\` → `src/`, cron `GlpiPlugin\Opmanager\Cron::*`.
- Hak akses: `plugin_opmanager_config` (READ/UPDATE) dan
  `plugin_opmanager_monitoring` (READ). *Nama kedua belum diklaim di hub — klaim saat
  implementasi dimulai.* Tab hak akses di Administration → Profiles memakai matriks
  standar (`displayRightsChoiceMatrix`). Seeding hanya untuk hak yang baru dibuat;
  profil dengan core `config` UPDATE selalu mendapat hak penuh.
- `lib/pgnsuite/ConfigTab.php` **r3** (SHA-256 `008C4C81…F39D0`) dan
  `lib/pgnsuite/AssetTab.php` **r1** (SHA-256 `C7F6C88B…A143A9`), byte-identik dengan
  kanonik, `.gitattributes` `-text`, SHA dicek **di dalam zip**.
- Kolom kunci `INT UNSIGNED`; kolom `*_id` hanya untuk kunci GLPI. ID eksternal
  OpManager disimpan sebagai `VARCHAR` bernama `*_key`. Kolom tanggal `TIMESTAMP`.
- Tidak ada badge hitungan; tabel bisa diurutkan hanya pada kolom bernilai tunggal.
- `echo Html::submit(...)` / `echo Html::hidden(...)`; form Setup membawa
  `_from_pgnsuite`.
- Kelas custom asset Security/Storage (`Glpi\CustomAsset\<system_name>Asset`) **belum
  termuat saat `plugin_init`** → daftar diambil dari baris aktif
  `glpi_assets_assetdefinitions`, diteruskan sebagai string ke `AssetTab::register()`,
  **tanpa** `class_exists()`; itemtype divalidasi di `sections()` saat render.
- Semua pembatasan entity hanya untuk tampilan, tidak untuk sinkron/cron.

## 5. Pencocokan aset

Target itemtype: `Computer`, `NetworkEquipment`, dan custom asset aktif (Security,
Storage). Plugin **tidak pernah** membuat atau mengubah aset GLPI.

Mode (satu konstanta `Config::MATCH_MODES`, dibaca dropdown, pencocok, dan controller):

| Mode | Cara |
|---|---|
| `ip_only` | IP perangkat → aset pemilik IP |
| `name_only` | Nama perangkat → nama aset (persis, tanpa beda huruf besar/kecil) |
| `hybrid` | IP dulu, lalu nama |
| `hybrid_name` | Nama dulu, lalu IP — **default** |
| `manual` | Tidak ada pencocokan otomatis |

Aturan di semua mode:

- **Pin manual selalu menang** dan tidak pernah ditimpa sinkron.
- IP yang dimiliki **lebih dari satu** aset tidak dipakai → `match_state = review`.
- Nama yang cocok ke lebih dari satu aset → `review`.
- Tidak ketemu → `unmatched`.
- Pin manual dilakukan di halaman **Devices** (§9) oleh pemegang `plugin_opmanager_config`.

## 6. Model data

Semua tabel memiliki `id INT UNSIGNED AUTO_INCREMENT`, `date_creation` dan `date_mod`
`TIMESTAMP`.

**`glpi_plugin_opmanager_devices`**

| Kolom | Tipe | Catatan |
|---|---|---|
| `device_key` | VARCHAR(255) UNIQUE | Identitas perangkat di OpManager |
| `name`, `display_name` | VARCHAR(255) | |
| `ip_address` | VARCHAR(45) | |
| `device_type`, `device_category` | VARCHAR(255) | |
| `availability` | VARCHAR(32) | Status terakhir dari OpManager |
| `itemtype` | VARCHAR(255) | Aset GLPI pasangan |
| `items_id` | INT UNSIGNED | 0 = belum ada |
| `match_state` | VARCHAR(16) | `matched` / `review` / `unmatched` |
| `match_source` | VARCHAR(16) | `name` / `ip` / `manual` |
| `is_manual` | TINYINT | Pin manual |
| `last_seen` | TIMESTAMP NULL | |

**`glpi_plugin_opmanager_alarms`**

| Kolom | Tipe | Catatan |
|---|---|---|
| `alarm_key` | VARCHAR(255) **UNIQUE** | Penjamin D3 |
| `plugin_opmanager_devices_id` | INT UNSIGNED | |
| `alarm_type` | VARCHAR(255) | Kategori alarm OpManager; kunci "jenis sama" untuk D10 |
| `severity` | VARCHAR(16) | `critical` / `major` / `minor` / `warning` / `clear` |
| `message` | TEXT | |
| `state` | VARCHAR(16) | `active` / `cleared` |
| `tickets_id` | INT UNSIGNED | 0 = tanpa tiket |
| `ticket_pending` | TINYINT | Pembuatan tiket gagal, dicoba ulang cron |
| `source` | VARCHAR(16) | `webhook` / `poll` |
| `first_seen`, `last_change`, `cleared_at` | TIMESTAMP NULL | |

**`glpi_plugin_opmanager_storms`** — `tickets_id`, `started_at`, `last_hit_at`,
`ended_at`, `alarm_count`.

**`glpi_plugin_opmanager_queue`** — `plugin_opmanager_alarms_id`, `action`
(`note`/`clear`), `payload` (TEXT), `status` (`pending`/`sent`/`failed`), `attempts`,
`next_try_at`, `last_error`, `sent_at`.

## 7. Mesin alarm → tiket

Alarm masuk (webhook atau poll) di-upsert berdasarkan `alarm_key`. Kalau webhook dan poll
datang bersamaan, INSERT kedua gagal karena UNIQUE, lalu baris dibaca ulang; tidak ada
duplikat. Keputusan dievaluasi berurutan, aturan pertama yang cocok menang:

| # | Kondisi | Aksi |
|---|---|---|
| 1 | Severity tidak dicentang di Setup, dan alarm belum punya tiket | Simpan saja |
| 2 | Alarm sudah punya tiket, tiket Solved/Closed, alarm aktif lagi | Buka kembali tiket (reopen standar GLPI) + followup |
| 3 | Alarm sudah punya tiket, alarm clear | Followup. Kalau **semua** alarm yang menunjuk tiket itu `cleared` → tiket **Solved** dengan solusi *"Alarm cleared di OpManager pada <waktu>"* |
| 4 | Alarm sudah punya tiket, perubahan lain (severity/pesan) | Followup |
| 5 | Alarm baru, perangkat punya tiket terbuka dari plugin | **Gabung**: `tickets_id` = tiket itu + followup |
| 6 | Alarm baru, rem darurat aktif | Gabung ke tiket induk badai + followup |
| 7 | Alarm baru, lainnya | **Tiket baru** (lihat di bawah) |

**Tiket baru:**

- **Template tiket (D18):** Setup memilih template tiket GLPI. Pilihannya "default GLPI"
  (template yang dipakai GLPI untuk entity/kategori tiket itu, lewat
  `getITILTemplateToUse()`) atau template kustom tertentu. Tiket yang dibuat lewat kode
  **tidak** otomatis memakai isi *predefined* template; GLPI hanya menerapkannya di form
  layar (`CommonITILObject.php:3717`). Karena itu plugin menerapkan sendiri isi
  predefined template (kategori, tipe, urgensi, dampak, grup, pemohon, lokasi, dan field
  GLPI lain yang ditentukan template), lalu mengirim field template (`_tickettemplate`)
  supaya GLPI tetap mengecek field wajib (`:2980`). Semua isian adalah field GLPI; plugin
  tidak menambah field buatan sendiri.
- **Judul dan deskripsi** memakai pola di Setup dengan variabel alarm: `{alarm_key}`,
  `{severity}`, `{message}`, `{device}`, `{ip}`, `{alarm_type}`, `{time}`, `{opm_link}`.
  Default judul: `[OPM-{alarm_key}] {severity}: {message} - {device}`. Judul hanya untuk
  manusia; logika tidak pernah membaca judul.
- Kalau field wajib template kosong, GLPI menolak tiket → `ticket_pending = 1`, dicatat
  di log dan tampil di Setup; alarm tidak hilang.
- Urgensi dari severity (Critical → Very high, Major → High, Minor → Medium, Warning →
  Low) dipakai **hanya** kalau template tidak menentukan urgensi.
- Pembuat: user GLPI layanan yang diatur di Setup (webhook dan cron berjalan tanpa login).
- Aset ditautkan (`Item_Ticket`) kalau `match_state = matched`.
- **Entity (D14):** entity aset pasangan → entity IP network GLPI paling spesifik yang
  mencakup IP perangkat (langkah ini bisa dimatikan di Setup) → entity default.
- **Tautan berulang (D10):** kalau ada tiket plugin untuk perangkat + `alarm_type` yang
  sama yang menjadi Solved/Closed dalam jendela waktu, buat `Ticket_Ticket` (link) +
  followup *"Alarm serupa sudah pernah ditangani di tiket #N"*.

**Rem darurat (D12):** kalau aturan 7 terpenuhi lebih dari N kali dalam M menit (default
10 / 5), buat satu tiket induk *"Badai alarm OpManager"* dan arahkan aturan 7 berikutnya ke
aturan 6. Badai berakhir setelah M menit tanpa alarm baru yang memenuhi aturan 7. Tiket
yang terbuat sebelum ambang terlewati tetap berdiri sendiri. Tiket induk mengikuti
aturan 3 (Solved saat semua alarmnya clear).

**Pengaman loop:** selama mesin mengubah tiket, penanda statis `Engine::$applying` aktif
dan hook tiket (§8) tidak mengantrekan apa pun.

## 8. Aliran keluar (GLPI → OpManager)

| Aksi teknisi di GLPI | Isi antrean |
|---|---|
| Menambah solusi / status tiket jadi Solved atau Closed | Untuk **setiap** alarm aktif di tiket: `note` (*"Tiket #N di-Solve/Close oleh <user>: <solusi>"*) lalu `clear` |
| Menambah followup **publik** *(usulan: followup privat tidak dikirim)* | `note` ke setiap alarm aktif di tiket |

Antrean dikirim langsung setelah aksi; yang gagal dicoba ulang oleh `QueueRetry` dengan
jeda bertambah (1, 2, 4, 8 … menit, maks 60) sampai batas percobaan (Setup, default 10),
lalu `failed` dan tampil di Setup. Endpoint OpManager (§13) dibungkus di `Client` saja.

## 9. Tampilan

**Sub-tab Monitoring** (key `monitoring`, order 60) di tab bersama PGN Suite untuk aset
yang punya pasangan perangkat OpManager, hanya untuk pemegang
`plugin_opmanager_monitoring`:

- status ketersediaan + waktu sinkron terakhir (peringatan kalau lebih dari 30 menit);
- cara dicocokkan (nama/IP/manual);
- 20 alarm terbaru dengan tautan tiket, keterangan *"Menampilkan 20 dari M"*, tanpa pager;
- tautan ke halaman penuh.

**Halaman penuh** *(usulan: sektor menu sendiri `opmanager`, seperti icofr/riskregister)*:

- **Devices** — daftar perangkat, filter `match_state`, pin/lepas manual.
- **Alarms** — daftar alarm dengan filter dan paging server.

## 10. Setup (section di tab PGN Suite config)

| Kunci | Default |
|---|---|
| `opm_url` | — |
| `opm_api_key` (terenkripsi GLPIKey) | — |
| `opm_verify_ssl` *(usulan)* | ya |
| `webhook_token` (terenkripsi, bisa dibuat ulang) | acak saat instalasi |
| `match_mode` | `hybrid_name` |
| `ticket_severities` | `critical` |
| `ticket_entity_default`, `ticket_use_ipnetwork_entity` | root, ya |
| `ticket_template` | `0` = default GLPI |
| `ticket_title_pattern`, `ticket_content_pattern` | pola default di §7 |
| `service_user` (user GLPI pembuat tiket) | — |
| `relink_window_hours` | 24 |
| `storm_threshold`, `storm_window_minutes` | 10, 5 |
| `outbound_max_attempts` | 10 |
| `stale_warning_minutes` | 30 |

Ditampilkan juga: status sinkron terakhir, daftar kiriman `failed` (dengan tombol kirim
ulang), dan URL webhook lengkap untuk disalin ke OpManager.

## 11. Penanganan error

| Kejadian | Perilaku |
|---|---|
| Token webhook salah/tidak ada | `401`, dicatat tanpa isi request |
| Isi webhook rusak / field wajib kosong | `400`, dicatat; poll akan menangkap alarmnya |
| OpManager tidak terjangkau | Dicatat, dicoba di jadwal berikut; status di Setup dan peringatan basi di sub-tab |
| API key kosong | Webhook tetap jalan; poll dan kiriman keluar nonaktif dengan peringatan di Setup |
| Pembuatan tiket gagal | `ticket_pending = 1`, dicoba ulang `AlarmSync` |
| Kiriman keluar gagal | Coba ulang lalu `failed` (§8) |
| Perangkat belum cocok | Tiket tetap dibuat tanpa tautan aset, entity per D14 |

Log: `files/_log/opmanager.log`.

**Keamanan:** token dibandingkan dengan `hash_equals`; token diterima dari header
`X-OPM-Token` atau parameter `?token=` (cadangan kalau OpManager tidak mendukung header
kustom — token di URL tercatat di access log nginx, disebut di panduan). API key dan
token tidak pernah ditampilkan ulang utuh.

## 12. Pengujian & rilis

- **Uji offline** (tanpa GLPI): pencocok (5 mode, IP ganda, nama ganda, pin manual),
  mesin keputusan (setiap baris tabel §7 + rem darurat + tautan berulang + Solved hanya
  saat semua clear), parser webhook, jeda coba ulang. Logika keputusan ditulis sebagai
  fungsi murni supaya bisa diuji tanpa `$DB`. Uji regresi harus **terlihat merah** dulu.
- **Uji satu sumber:** dropdown, pencocok, dan controller membaca `MATCH_MODES` dan
  daftar severity yang sama.
- **`tools/selfcheck.php` di VM:** tabel, hak akses, pendaftaran section, path stateless,
  enkripsi config.
- **Runbook VM:** webhook uji → tiket → clear → Solved → alarm aktif lagi → reopen →
  Solve dari GLPI → notes + clear di OpManager. Kasus yang bergantung data produksi (badai,
  banyak alarm satu perangkat, IP network entity) punya pilihan *"belum teruji di
  produksi"*.
- **Rilis:** tag lokal setiap versi, push GitHub hanya versi mayor; SHA `lib/pgnsuite/*`
  dicek di dalam zip; zip dipasang ke `Downloads/glpi/plugins/opmanager/` lalu versi,
  jumlah file, dan SHA dicek. Versi pertama *(usulan)*: `0.1.0`.

## 13. Harus diverifikasi sebelum/saat implementasi

1. **API OpManager di server PGN:** path dan parameter untuk daftar perangkat, daftar
   alarm aktif, tambah notes, dan clear alarm (dugaan: `/api/json/device/listDevices`,
   `/api/json/alarm/listAlarms`, `/api/json/alarm/addNotes`, `/api/json/alarm/clearAlarm`
   dengan `apiKey` + `entity`). Path `/api/v2/alarms/...` di `review_integrasi.md` belum
   terbukti ada.
2. **Webhook OpManager:** variabel yang tersedia (ID alarm, nama/IP perangkat, severity,
   kategori, pesan, status) dan apakah header kustom didukung. Template JSON webhook untuk
   OpManager ditulis setelah ini diketahui.
3. **API key OpManager** diterbitkan.
4. **IP networks di GLPI VM** terisi dengan entity yang benar (untuk D14).
5. **User layanan GLPI** untuk pembuat tiket dibuat, dengan hak buat tiket di entity
   target.
6. Nama klaim hak `plugin_opmanager_monitoring` di hub.
