---
date: 2026-09-29
tags: [glpi, opmanager, pgn-glpi-suite, design]
status: active
---

# Plugin `opmanager` — Spesifikasi Desain v1

Integrasi ManageEngine OpManager ↔ GLPI 11 (VM-GLPI-01, core 11.0.5) sebagai plugin ke-7
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
| D19 | Severity OpManager asli (terverifikasi di produksi 2026-09-30): `numericSeverity` 1 Critical, 2 Trouble, 3 Attention, 4 Service Down, 5 Clear. Menggantikan nama Critical/Major/Minor/Warning di D11 dan §7/§10. Default tiket tetap **Critical saja** (= 1). Field `severity` berisi HTML, tidak boleh dirender; pakai `severityString` | 2026-09-30 |
| D21 | Tiket hanya untuk severity yang dicentang (default Critical) **pada aset produksi**. Aset produksi = IP alarm atau salah satu IP aset GLPI pasangannya berada di rentang **IP Addressing** (plugin addressing) yang dicentang di Setup. Tanpa rentang yang dicentang, tidak ada tiket. Middleware webhook lama dimatikan saat plugin aktif | 2026-09-30 |
| D22 | Followup: perubahan severity langsung; perubahan pesan saja paling banyak satu followup per jam per alarm (final review, tidak dibantah user) | 2026-09-30 |
| D23 | Filter alarm untuk tiket **baru** (mis. High CPU Utilization tidak jadi tiket): alarm cocok bila Event Type OpManager dicentang **atau** pesannya mengandung kata kunci (per baris, tidak peka huruf). Mode **Kecualikan** (default) atau **Hanya yang dipilih**. Daftar kosong = filter mati. Berlaku di atas D21; alarm terfilter tetap tersimpan dan tampil; alarm yang sudah punya tiket tidak terpengaruh (followup + solve tetap jalan) | 2026-10-01 |
| D24 | Arah keluar (Plan 3) hanya untuk tindakan **yang diketik pengguna GLPI** (teknisi, admin, dst.): solusi atau followup dari sesi login GLPI. Yang dibuat plugin sendiri (service user, cron AlarmSync, webhook, Solve otomatis saat alarm Clear) dan aksi otomatis GLPI (auto-close, rules) **tidak** dikirim ke OpManager. Ini juga mencegah putaran balik OpManager → GLPI → OpManager | 2026-10-01 |
| D25 | Hanya followup **publik** yang dikirim sebagai catatan ke OpManager; followup privat tetap internal GLPI | 2026-10-01 |
| D26 | Tiket di-Close oleh pengguna tanpa solusi yang diketik → alarm tetap di-**clear**, dengan catatan singkat otomatis *"Tiket #N ditutup oleh <user> di GLPI"* (tanpa isi solusi) sebagai jejak | 2026-10-01 |
| D27 | Followup **publik** dari teknisi → selain `note`, alarm aktif tiket di-**acknowledge** di OpManager (tanda sudah ditangani). Ack tidak diantrekan lagi bila masih ada ack menunggu untuk alarm itu; ack ulang aman (bukan toggle, lihat §14). Aturan D24/D25 berlaku | 2026-10-02 |
| D28 | Halaman **Coverage** (aset OpManager vs aset GLPI) di menu OpManager, dikerjakan sebelum kartu dashboard GLPI. Aset GLPI yang dihitung: tipe aset dari Setup, status GLPI yang dipilih di Setup (default semua), punya IP; bisa disaring ke rentang produksi. Tren harian ditunda. Lihat §15 | 2026-10-02 |
| D29 | Setup OpManager ditata ulang: **strip status** (sync perangkat, sync alarm, webhook, antrean) selalu tampil + **tab** per bagian, satu tombol Save. Nama field form tidak berubah, jadi `front/config.php` tetap. Lihat §15 | 2026-10-02 |

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
| `severity` | TINYINT UNSIGNED | `numericSeverity` OpManager: 1 Critical, 2 Trouble, 3 Attention, 4 Service Down, 5 Clear (D19) |
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
- Urgensi dari severity (Critical → Very high, Trouble → High, Attention → Medium,
  Service Down → High) dipakai **hanya** kalau template tidak menentukan urgensi (D19).
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
| Menambah solusi (tiket jadi Solved) | Untuk **setiap** alarm aktif di tiket: `note` (*"Tiket #N di-Solve oleh <user>: <solusi>"*) lalu `clear` |
| Status jadi Closed **tanpa** solusi yang diketik (D26) | Untuk setiap alarm aktif: `note` singkat (*"Tiket #N ditutup oleh <user> di GLPI"*) lalu `clear` |
| Menambah followup **publik** (D25) | `note` ke setiap alarm aktif di tiket; followup privat tidak dikirim |

**Hanya tindakan yang diketik pengguna GLPI (D24).** Sebuah solusi atau followup dikirim
hanya jika semua syarat ini terpenuhi:
- dibuat dalam sesi login GLPI: `Session::getLoginUserID()` > 0, dan bukan `Session::isCron()`;
- penulisnya bukan service user plugin;
- tidak dibuat saat plugin sendiri sedang menulis. Gateway memasang penanda selama
  `create/followup/solve/reopen`, dan hook mengabaikan item yang dibuat selama penanda aktif.

Dengan aturan ini, Solve otomatis saat alarm Clear (D6), followup dari AlarmSync atau webhook,
dan auto-close GLPI tidak pernah dikirim balik ke OpManager.

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
| `ticket_severities` | `1` (Critical, D19) |
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

## 14. Fakta produksi yang sudah terverifikasi (2026-09-30)

- **Error OpManager selalu HTTP 200** dengan `{"error":{"code","message"}}` (contoh: code
  5000, apiKey tidak valid). Status HTTP tidak boleh dipercaya; plugin memeriksa objek
  `error` (v0.1.1).
- **`/api/json/device/listDevices`** mengembalikan list sesuai asumsi (933 perangkat).
  Banyak perangkat dinamai IP. §13 butir 1 terjawab untuk perangkat.
- **`/api/json/alarm/listAlarms`** mengembalikan list datar berisi 6.231 alarm (3,7 MB),
  termasuk yang sudah Clear. Field per alarm: `alarmId` (kunci alarm), `alarmcode` (jenis
  alarm untuk D10), `entity`, `deviceName`, `displayName`, `ipAddress`, `category`,
  `numericSeverity`/`severityString` (D19), `statusStr`/`statusNum`, `eventType`,
  `message`, `modTime`/`modTimeLong` (epoch ms), `who` (status acknowledge).
- **`deviceName` pada alarm tidak selalu ada di daftar perangkat** (contoh VM di host VMware:
  `10.20.3.139.10000000001`, `displayName` VM-APP-DB01, `ipAddress` 10.20.14.139).
  Tautan alarm ke aset: `deviceName` → `devices.device_key`; kalau tidak ada, pakai
  `displayName`/`ipAddress` dengan aturan pencocokan §5.
- **Dokumentasi resmi OpManager** (manageengine.com, 2026-09-30):
  - **API key dikirim lewat header `apiKey`.** Parameter URL sudah deprecated sejak
    12.8.100, dan `listAlarms` termasuk endpoint yang wajib memakai header. Mulai v0.2.0
    semua panggilan memakai header, sehingga key juga tidak muncul di URL atau log.
  - `GET /api/json/alarm/listAlarms`: `deviceName`, `severity` (1-5), `category`,
    `entity`, `alertType` (`ActiveAlarms` = hanya yang belum clear), `fromTime`/`toTime`
    (`yyyy-mm-dd hh:mm:ss`).
  - `/api/json/alarm/addNotes` (`entity`, `notes`) dan `/api/json/alarm/clearAlarm`
    (`entity`): alarm dikenali lewat **`entity`**, bukan `alarmId`. Tabel `alarms` wajib
    menyimpan `entity_key` di samping `alarm_key`.
- **Terverifikasi di produksi (probe read-only, key lewat header):**
  `alertType=ActiveAlarms` mengembalikan 40 alarm aktif (15 Critical, 6 Trouble,
  19 Attention; 24 KB, bukan 3,7 MB). Tanpa filter: 6.231 alarm.
- **`entity` 1:1 dengan alarm** (6.231 alarm = 6.231 entity), jadi hipotesis "entity tetap
  sama untuk alarm baru" **tidak terbukti**. D10 tetap memakai **perangkat + `alarmcode`**
  sebagai "jenis alarm yang sama"; `entity_key` disimpan hanya untuk notes/clear (Plan 3).
- **D20 (desain, dilaporkan ke user 2026-09-30) — deteksi clear saat polling:** polling
  memakai `ActiveAlarms`. Alarm lokal yang masih `active` tapi tidak ada di daftar dicek
  ulang satu per satu lewat `listAlarms?entity=<entity_key>`, dan hanya ditandai `cleared`
  kalau jawabannya benar-benar severity 5 (Clear). Respons error atau bukan-list
  **tidak pernah** meng-clear apa pun. Ini mencegah satu respons kosong men-Solve
  puluhan tiket sekaligus.
- **Webhook OpManager (terlihat di produksi 2026-09-30, action "GLPI_Create_Ticket"):**
  Notification Profile → "Invoke a Webhook" bisa melakukan POST dengan body JSON mentah dan
  header kustom. Variabel yang tersedia: `$alarmid`, `$entity`, `$displayName`, `$deviceIP`,
  `$strEMSAlarmSeverity`, `$message`, `$alarmTime`, `$category`. Ini menjawab §13 butir 2
  untuk v0.3.0: `$alarmid` untuk anti-duplikat, `$entity` untuk notes/clear, dan token
  rahasia plugin lewat header.
- **Action itu memanggil API v1 GLPI langsung** (`apirest.php` + header `App-Token` /
  `User-Token`) dan tidak bisa berfungsi. API v1 hanya membaca user token dari
  `Authorization: user_token …`, dan hanya untuk `initSession`. Endpoint lain wajib memakai
  `Session-Token` (`APIRest.php:600-620`, `API.php:2393`). Selain itu URL-nya tanpa
  `/Ticket`, dan `users_id_assign` bukan field tiket (seharusnya `_users_id_assign`).
  Action ini harus **dinonaktifkan saat v0.2.0 aktif**, supaya satu alarm tidak menjadi dua
  tiket. Pada v0.3.0 action ini diarahkan ulang ke penerima webhook plugin.
- **`addNotes` terverifikasi di produksi (probe 2026-10-01): wajib POST** (form params `entity`,
  `notes`, key di header). GET dijawab `{"error":{"message":"Invalid request method.",
  "errorcode":"INVALID_METHOD"}}`. Dengan key yang dipakai sekarang, POST dijawab
  `AUTHORIZATION_FAILED` ("You are not authorized to perform this operation").
  **Diperbarui 2026-10-01 (probe 2, curl): POST yang sama berhasil** —
  `{"result":{"message":"Successfully Added"}}` — dengan key yang terpasang di GLPI (user
  menyatakan milik admin dengan akses penuh). Cara kirim yang dipakai Plan 3: **POST, key di
  header `apiKey`, `entity` + `notes` sebagai form body**. Penolakan pertama kemungkinan karena
  key/hak saat itu berbeda; jika muncul lagi, cek dulu pemilik key di OpManager.
  Entity contoh: `IF-192.0.2.245.10000000001-10000014510_Poll` (alarm interface), jadi
  entity bisa berisi titik dan garis bawah.
- Objek error OpManager punya dua bentuk: `{"code","message"}` (key salah) dan
  `{"message","errorcode"}` (hak/method). Plugin membaca keduanya.
- **`clearAlarm` terverifikasi di produksi (probe 2026-10-01):** POST, key di header `apiKey`,
  form body `entity`. Jawaban sukses **berbeda bentuk** dari `addNotes`:
  `{"ackMessage":"Alarm has been successfully Cleared!","AlarmProperty":{...}}`. Karena itu plugin
  menganggap gagal hanya jika ada objek `error`, bukan dengan mencari `result`.
- **`acknowledgeAlarm` terverifikasi di produksi (probe 2026-10-02):** POST, key di header,
  form body `entity`. Sukses: `{"ackMessage":"Alarm has been successfully Acknowledged",...}`.
  Panggilan kedua pada alarm yang sudah di-ack: `{"ackMessage":"The selected Alarm is not
  Acknowledged",...}` tanpa `error`, dan alarm **tetap** acknowledged (bukan toggle). Ack
  tercatat atas nama pemilik API key; `listAlarms` memberi field `who` (siapa yang meng-ack).

## 15. Coverage dan Setup baru (v0.6.0, D28–D29)

### 15.1 Halaman Coverage (menu OpManager → Coverage, hak `plugin_opmanager_monitoring` READ)

**Aset GLPI dalam lingkup:**
- itemtype termasuk `asset_itemtypes` dari Setup;
- tidak terhapus dan bukan template; untuk custom asset, `getSystemSQLCriteria()` ikut dipakai;
- `states_id` termasuk `coverage_states` dari Setup (daftar kosong = semua status);
- punya minimal satu IP aktif di `glpi_ipaddresses` (`mainitemtype`/`mainitems_id`);
- dibatasi oleh entity aktif pengguna (`getEntitiesRestrictCriteria`).

**Perangkat OpManager:** `glpi_plugin_opmanager_devices`. Data perangkat tidak terikat entity, jadi daftar yang hanya berisi perangkat ditampilkan ke semua pemegang hak monitoring.

**Angka ringkas:**
- jumlah perangkat OpManager;
- terpasang ke aset (`matched`, termasuk pin manual);
- tidak ada di GLPI (`unmatched`);
- ambigu (`review`);
- aset GLPI dalam lingkup;
- yang dimonitor (ada perangkat yang `matched` ke aset itu);
- yang tidak dimonitor;
- cakupan % = dimonitor ÷ dalam lingkup.

**Lima daftar tindak lanjut** (masing-masing bisa diekspor CSV):

| Daftar | Isi |
|---|---|
| Tidak ada di GLPI | perangkat `unmatched` |
| Tidak dimonitor | aset dalam lingkup tanpa perangkat `matched` |
| Ambigu | perangkat `review` (link ke form perangkat untuk pin manual) |
| Nama berbeda | perangkat `matched` lewat IP yang namanya (`display_name`, else `name`), setelah dinormalisasi (huruf kecil, tanpa domain, tanpa spasi tepi), tidak sama dengan nama aset |
| Basi | perangkat yang `last_seen` lebih tua dari `last_device_sync` dikurangi `stale_warning_minutes` |

**Penyaringan:**
- Pilihan "Hanya rentang produksi" memakai rentang `production_addressings` (IP Addressing). Penyaringan berlaku untuk IP perangkat dan IP aset.
- Halaman bisa juga disaring per kategori perangkat OpManager.

**Daftar dan ekspor:**
- Daftar dipaginasi (50 per halaman).
- CSV berisi seluruh daftar, dipisah `;`, UTF-8 dengan BOM.

### 15.2 Setup

**Strip status**, selalu tampil di atas tab. Badge `ok`/`warn`/`error`:
- *Sync perangkat:* error bila ada `last_device_sync_error`; warn bila kosong atau lebih tua dari `stale_warning_minutes`.
- *Sync alarm:* error bila ada error; warn bila lebih tua dari 3× interval cron alarm.
- *Webhook:* ok bila token tersimpan, warn bila belum.
- *Ke OpManager:* error bila ada antrean `failed`, warn bila ada `pending`.
- *API key:* error bila belum diisi.

**Tab:**

| Tab | Isi |
|---|---|
| Koneksi | URL, API key, SSL |
| Pencocokan | mode, tipe aset, ambang data basi, status GLPI untuk Coverage |
| Tiket | severity, rentang produksi, template, service user, entity, pola, relink, storm |
| Filter | filter alarm |
| Webhook | URL, header, token |
| Ke OpManager | batas retry, status antrean |

**Perilaku form:**
- Tab yang terakhir dibuka diingat per browser (`sessionStorage`); kalau gagal, kembali ke tab pertama.
- Satu form, satu tombol Save, nama field tidak berubah.
