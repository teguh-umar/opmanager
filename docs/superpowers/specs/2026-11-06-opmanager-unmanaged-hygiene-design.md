---
date: 2026-11-06
tags: [glpi, opmanager, pgn-glpi-suite, design, nms-reference]
status: draft
---

# OpManager — Kebersihan perangkat NMS (unmanaged & tidak terdaftar di GLPI)

Keputusan D34. Ini satu-satunya fitur yang membuka freeze opmanager (keputusan user 2026-11-05,
dibuka kembali 2026-11-06 dan dicatat orkestrator di hub §7, commit f8398b7). Plugin penerus
`nms` akan meniru fitur ini, jadi §8 mendaftar setiap tabel, kolom, right, dan config key baru.

Semua yang tidak bertanda *(usulan)* adalah keputusan user dari sesi brainstorming 2026-11-06.

## 1. Masalah dan prinsip

Daftar Devices sudah menyimpan availability OpManager. Isinya "Clear", "UnManaged",
"Device Not Monitored.", dan seterusnya. Plugin belum memakai nilai itu. Perangkat yang
unmanaged hanya tampil "Not found in GLPI" atau "Matched", tanpa tindakan yang bisa diambil.

Prinsip (user):

- **GLPI adalah single source of truth untuk aset. NMS hanya alat monitoring.**
- Aset GLPI yang tidak ada di NMS **bukan masalah**, karena tidak semua aset dimonitor.
  OpManager hanya memonitor perangkat **production**; aset dev/staging di GLPI memang tidak ada
  di OpManager dan tidak pernah dicek (user, 2026-11-10).
  Fitur ini tidak pernah melaporkan arah itu.
- Perangkat yang **dimonitor di NMS tetapi tidak ada di GLPI adalah masalah**: ada perangkat
  aktif yang tidak tercatat sebagai aset.
- **Konsensus:** perangkat yang unmanaged atau tidak dimonitor dan fisiknya sudah tidak ada
  harus **dihapus dari NMS**. Unmanaged bukan tempat parkir permanen. Perangkat yang sengaja
  di-unmanage, misalnya karena keterbatasan lisensi, tetap dibiarkan.
- **Plugin tidak pernah menghapus atau mengubah apa pun di OpManager.** Penghapusan dikerjakan
  tim NMS. Plugin hanya mengklasifikasi perangkat dan membuat tiket Request.

## 2. Klasifikasi perangkat

Status monitoring diambil dari kolom `availability` yang sudah ada:

- **Tidak dimonitor:** availability termasuk daftar `hygiene_unmanaged_availabilities`.
  Default-nya `["UnManaged", "Device Not Monitored."]`. Pembandingan dilakukan tanpa
  membedakan huruf besar-kecil dan setelah spasi di tepi dipangkas.
- **Dimonitor:** nilai lain apa pun yang tidak kosong (Clear, Critical, Trouble, Attention,
  Service Down, ...).
- Perangkat dengan availability kosong, atau yang `last_seen`-nya lebih tua dari dua siklus
  DeviceSync (sudah hilang dari OpManager), tidak diklasifikasi. *(usulan)*

| # | Kondisi | Hasil |
|---|---|---|
| A | Dimonitor, `match_state = unmatched` (Not found in GLPI) | Masuk **tiket GLPI**: ke tim GLPI + tim aset, tim NMS sebagai observer |
| A' | Dimonitor, `match_state = review` | Masuk tiket GLPI pada bagian terpisah, "perlu dipasangkan manual" *(usulan)* |
| B | Tidak dimonitor, cek hidup = **mati** | Masuk **tiket NMS**: "kandidat hapus dari NMS" |
| C | Tidak dimonitor, cek hidup = **tidak diketahui** | Masuk **tiket NMS**: "perlu dicek manual" |
| D | Tidak dimonitor, cek hidup = **hidup** | Tanpa tiket. Tampil sebagai info "Unmanaged – masih hidup" (dianggap sengaja di-unmanage) |
| E | Dimonitor dan Matched | Tidak ditindak |
| X | Perangkat dengan **pengecualian aktif** | Tidak masuk tiket mana pun, apa pun kondisinya |

Kondisi B, C, dan D berlaku sama untuk perangkat yang Matched maupun yang tidak ada di GLPI.

## 3. Cek hidup (liveness)

Tidak semua segmen bisa dijangkau dari server GLPI, dan hanya server yang punya GLPI agent.
AP wireless datanya ada di plugin suite **Juniper MIST** (`mist`). Karena itu cek hidup
menggabungkan beberapa sumber bukti. Setiap sumber menghasilkan `alive`, `dead`, atau tidak
memberi bukti.

| Sumber | Kapan berlaku | alive | dead |
|---|---|---|---|
| **GLPI agent** | Perangkat Matched dan asetnya punya baris di `glpi_agents` (`itemtype`, `items_id`) | `last_contact` ≤ `hygiene_agent_stale_days` hari | `last_contact` lebih tua dari itu |
| **Mist** | Plugin `mist` aktif (`Plugin::isPluginActive('mist')`) dan ada baris `glpi_plugin_mist_devices` yang cocok: aset GLPI yang sama (`itemtype`, `items_id`), atau kalau tidak ada, `ip_address` yang sama | `status = 'connected'`, atau `mist_last_seen` ≤ `hygiene_mist_stale_days` hari | `status = 'disconnected'` dan `mist_last_seen` lebih tua dari batas |
| **Probe jaringan** | IP perangkat ada di salah satu CIDR `hygiene_probe_cidrs` (segmen yang diketahui terjangkau) | Port TCP dari `hygiene_probe_tcp_ports` menerima koneksi | Semua port gagal atau timeout |

**Sumber bukti bersifat terbuka (user, 2026-11-06).** Mist bukan satu-satunya sumber
eksternal: kelak bisa ada FortiCloud manager atau controller lain. Karena itu setiap sumber
adalah **provider** dengan kontrak yang sama:

```
fn(array $device, string $now): ?array{verdict: 'alive'|'dead', detail: string}
```

`$device` berisi `device_key`, `name`, `ip_address`, `device_category`, `availability`,
`itemtype`, `items_id`, dan `match_state`. Provider mengembalikan `null` bila tidak berlaku.

- Provider bawaan: `agent`, `mist`, `probe` (tabel di atas).
- Plugin lain bisa menambah provider tanpa mengubah kode opmanager, lewat hook
  `$PLUGIN_HOOKS['opmanager_liveness'][<plugin>] = callable` atau `[nama => callable]`.
  Nama provider adalah key array, atau nama plugin bila hanya satu callable, maksimal 32
  karakter.
- Kontraknya hanya array biasa, tanpa interface PHP yang harus di-import, jadi plugin
  lain tidak terikat ke kelas opmanager.
- Provider yang melempar exception dicatat di `last_hygiene_error` dan dilewati. Satu
  provider yang rusak tidak menghentikan run.
- Provider eksternal dipanggil hanya bila pluginnya aktif, karena GLPI hanya memuat hook dari
  plugin aktif.
- `nms` mewarisi kontrak yang sama, dengan nama hook disesuaikan (`nms_liveness`).

Gabungan:

1. **hidup** bila ada satu sumber yang bilang `alive`;
2. **mati** bila ada sumber yang bilang `dead` dan tidak ada yang bilang `alive`;
3. **tidak diketahui** bila tidak ada sumber yang berlaku.

Hasil disimpan per perangkat (§8: `liveness_state`, `liveness_source`, `liveness_detail`,
`liveness_checked_at`). `liveness_detail` berisi bukti yang bisa dibaca manusia, misalnya
"agent last contact 2026-10-02" atau "TCP 22,443 timeout". Detail ini ikut ditulis di tiket.

Batasan:

- Probe hanya ke IP di dalam CIDR yang dikonfigurasi. Ping ke segmen yang tidak terjangkau
  akan salah dibaca sebagai "mati", padahal segmen itu memang tidak bisa dicapai.
- Probe memakai TCP connect dengan timeout pendek (`hygiene_probe_timeout_ms`, default
  1000). Probe tidak memakai `exec('ping')`, karena exec sering dimatikan dan tidak aman.
  ICMP tidak termasuk v1. *(usulan)*
- Waktu probe total per run dibatasi, supaya cron tidak macet.
- Bacaan tabel `mist` lewat string nama tabel dijaga dengan `isPluginActive('mist')` dan
  `tableExists`. Aturan hub §4 untuk referensi lintas-plugin berlaku. Fitur tetap berjalan
  tanpa `mist`.
- Cek hidup hanya dijalankan untuk perangkat yang tidak dimonitor dan tidak dikecualikan.

## 4. Tiket

Dua alur tiket, keduanya bertipe **Request** (`type = 2`), dibuat atas nama service user yang
sudah ada di Setup, lewat objek core (`Ticket->add`, `ITILFollowup->add`; hub §5).

| Alur | Isi | Assigned group | Observer group |
|---|---|---|---|
| **GLPI** | Kondisi A (dan A') | rc7: tidak ada grup; user `hygiene_glpi_users` (teknisi penanggung jawab aset, wajib) | `hygiene_glpi_groups` (multi) + `hygiene_nms_group` |
| **NMS** | Kondisi B dan C, dua bagian terpisah: "kandidat hapus" dan "perlu dicek manual" | `hygiene_nms_group` | – |

Aturan anti-banjir (usulan Claude, disetujui user):

- Satu tiket per alur, bukan satu tiket per perangkat.
- Run berkala setiap `hygiene_frequency_days` hari (default 7).
- Bila tiket alur itu masih **terbuka** (status selain Solved/Closed, tidak di trash), run
  berikutnya **tidak membuat tiket baru**. Run itu menambah satu followup yang hanya berisi
  perangkat **baru** sejak laporan terakhir, plus daftar perangkat yang sudah tidak memenuhi
  kondisi (sudah dihapus dari NMS, sudah didaftarkan, atau sudah dikecualikan). Bila tidak ada
  perubahan, tidak ada followup.
- Bila tiket sebelumnya sudah Solved/Closed/terhapus, run berikutnya membuat tiket baru dengan
  daftar lengkap kondisi saat itu.
- Tidak ada perangkat yang memenuhi kondisi berarti tidak ada tiket.
- Isi tiket berupa tabel per perangkat: nama, IP, kategori OpManager, availability, aset GLPI
  (link bila ada), hasil cek hidup + detail, `last_seen`. Tiket juga berisi tautan ke daftar
  Devices yang sudah difilter.
- **Tiket kebersihan tidak melewati business rules tiket (rc5, keputusan user 2026-11-09, opsi A).**
  Tiket ini dibuat dengan flag core `_skip_rules`. Alasannya: di production ada aturan alarm,
  misalnya "Description contains 10.20.10." dengan aksi Assign group SysAdmin dan category Server.
  Aturan itu cocok dengan isi laporan yang memuat banyak IP, lalu mengganti grup kebersihan; tiket
  NMS jadi ter-assign ke SysAdmin. Karena aturan dilewati, ITIL category datang dari Setup
  (`hygiene_itil_category`, opsional). Plugin Behaviors mewajibkan category sebelum Solve/Close.
  Untuk `nms`: tiket laporan apa pun yang berisi banyak IP atau perangkat sebaiknya juga melewati
  aturan tiket.
- **Tiket GLPI tanpa grup teknisi (rc7, keputusan user 2026-11-09, opsi A).** Di production,
  plugin Escalade disetel "Remove old assign group on new group assign", sehingga satu tiket
  hanya bisa punya satu grup teknisi: setiap grup baru menghapus grup lama. Karena itu tiket GLPI
  di-assign ke teknisi penanggung jawab aset (`hygiene_glpi_users`, wajib untuk menyalakan tiket),
  dan `hygiene_glpi_groups` (mis. SysAdmin, ITOC, SOC L1) menjadi observer bersama
  `hygiene_nms_group`. Tiket NMS tetap satu grup teknisi. Untuk `nms`: jangan pernah meng-assign
  lebih dari satu grup teknisi ke satu tiket.
- **Teknisi di-assign sesudah tiket dibuat (rc6).** Di production, plugin Behaviors disetel
  "Use the technician's group" = First untuk tiket baru. Kalau input pembuatan tiket berisi
  teknisi, Behaviors mengganti grup kebersihan dengan grup pertama teknisi itu. Karena itu tiket
  dibuat hanya dengan grup, lalu `hygiene_glpi_users` ditambahkan lewat `Ticket_User` (ASSIGN).
  Setting update di Behaviors ("No" di production) tidak menyentuhnya. Untuk `nms`: lakukan hal
  yang sama.
- **Tiket GLPI juga dibagi per domain dan punya teknisi penanggung jawab (rc4, keputusan user
  2026-11-09).** Pendaftar aset: server oleh SysAdmin, network oleh ITOC, plus satu atau lebih
  teknisi yang ditunjuk sebagai penanggung jawab input dan cek aset. Tiket GLPI di-assign ke
  `hygiene_glpi_groups` (SysAdmin, ITOC) dan `hygiene_glpi_users`, observer `hygiene_nms_group`.
  Isinya dibagi per domain, sama seperti tiket NMS di bawah.
- **Tiket NMS dibagi per domain (rc3, keputusan user 2026-11-09, "Opsi A").** Tim NMS adalah
  grup bersama tim server (ICT: server, VM, storage) dan tim network (ITOC). Tiketnya tetap
  satu dan di-assign ke `hygiene_nms_group`. Di setiap bagian (dead / unknown) ada sub-bagian
  "Server, VM and storage", "Network" dan "Unknown category". Domain ditentukan dari kategori
  OpManager (`Report::domain()`). Bila kategori tidak dikenal, domain diambil dari itemtype aset
  GLPI yang dipasangkan (Computer → server, NetworkEquipment → network). Tanpa itemtype,
  perangkat masuk "Unknown category". Followup tidak dibagi; kolom Category tetap ada. Tiket
  per tim (Opsi B) ditunda sampai terbukti perlu.
- Entity tiket: `ticket_entity_default` yang sudah ada. *(usulan)*
- Template tiket memakai config key sendiri, `hygiene_ticket_template`. Template alarm
  (`ticket_template`) tidak dipakai, karena template itu dibuat untuk Incident alarm dan field
  wajibnya bisa memblokir tiket laporan. Default 0 berarti template Request default di entity
  itu. `type = 2` selalu dipaksa, termasuk bila template menetapkan tipe lain. Bila field wajib
  template tidak terisi, tiket tidak dibuat, galatnya tampil di Setup (`last_hygiene_error`), dan
  run tiket dicoba lagi pada run harian berikutnya, bukan menunggu satu periode penuh.
  *(keputusan Claude)*
- Perubahan kondisi perangkat yang sudah dilaporkan *(keputusan Claude)*:
  - **Masih di alur yang sama** (misalnya B → C di tiket NMS): followup mencantumkannya di
    bagian "berubah kondisi" (lama → baru). Baris `hygieneitems` diperbarui
    (`hygiene_state`, `changed_at`).
  - **Pindah alur** (misalnya A → B): dihitung "sudah tidak memenuhi" di tiket lama dan
    "baru" di tiket alur lain.
  - **Selesai lalu muncul lagi** di tiket yang masih terbuka: dihitung baru lagi.
    `resolved_at` dikosongkan dan `reported_at` diperbarui.

## 5. Pengecualian

- Pengecualian berlaku **per perangkat OpManager** (dikunci dengan `device_key`) dan
  mengeluarkan perangkat dari **kedua** alur tiket.
- **Alasan wajib diisi.** Tercatat siapa yang mengecualikan dan kapan. Pengecualian bisa
  **dibatalkan**, dan siapa serta kapan pembatalannya juga tercatat. Riwayat tidak dihapus.
- **Siapa:** tim NMS dan tim GLPI (user). Pembagiannya lewat profil GLPI, dengan right baru
  `plugin_opmanager_hygiene` UPDATE (§8) yang diberikan ke profil kedua tim. Plugin tidak
  membedakan tim di dalam kode. *(usulan)*
- Contoh: perangkat milik ISP, 127.0.0.1, perangkat sengaja di-unmanage yang probe-nya
  diblokir firewall, atau perangkat tidak diketahui yang sudah dicek manual oleh tim NMS.
- UI: tombol "Kecualikan dari tiket" dengan field alasan di form Device, dan
  "Batalkan pengecualian" bila aktif. Riwayat pengecualian tampil di form Device.

## 6. Tampilan

- **Daftar Devices** (search options baru): *Monitoring di OpManager* (Dimonitor / Tidak
  dimonitor), *Cek hidup* (hidup / mati / tidak diketahui / belum dicek), *Kondisi kebersihan*
  (A, A', B, C, D, E, X dalam label manusia), *Dikecualikan* (ya/tidak). Kolom ini bisa
  difilter dan disimpan sebagai saved search.
- **Form Device:** blok "Kebersihan NMS" berisi kondisi, cek hidup + detail + waktu,
  pengecualian dan riwayatnya, serta tiket kebersihan terbuka yang memuat perangkat ini.
- **Setup** (section di tab ICT Tools config, aturan suite): subsection "Kebersihan NMS" untuk
  semua config key §8, plus status run terakhir (`last_hygiene_run`, `last_hygiene_error`)
  dan ringkasan jumlah per kondisi.
- Tidak ada menu atau halaman baru. *(usulan)*

## 7. Cron

- Tugas baru `HygieneCheck`, mode CLI (`MODE_EXTERNAL`, hub §4). Jalan harian. Setiap kali
  jalan, tugas ini memperbarui cek hidup dan klasifikasi.
- Tiket dibuat atau di-followup hanya bila `hygiene_frequency_days` sudah lewat sejak
  `last_hygiene_ticket_run`. Klasifikasi di UI tetap segar setiap hari. *(usulan)*
- `hygiene_enabled = 0` (default saat upgrade) mematikan pembuatan tiket. Klasifikasi tetap
  dihitung, supaya tim bisa meninjau daftar dulu sebelum tiket pertama keluar. *(usulan)*
- Run yang gagal sebagian harus terlihat di Setup (hub §4): galat probe, tabel `mist` yang
  tidak terbaca, dan tiket yang gagal dibuat.

## 8. Model data (daftar lengkap untuk migrasi `nms`)

### 8.1 Kolom baru di `glpi_plugin_opmanager_devices`

| Kolom | Tipe | Arti |
|---|---|---|
| `liveness_state` | VARCHAR(16) NOT NULL DEFAULT '' | `alive` / `dead` / `unknown` / '' (belum dicek) |
| `liveness_source` | VARCHAR(32) NOT NULL DEFAULT '' | nama provider: `agent` / `mist` / `probe` / provider dari plugin lain / '' |
| `liveness_detail` | VARCHAR(255) NOT NULL DEFAULT '' | bukti untuk manusia |
| `liveness_checked_at` | TIMESTAMP NULL | |
| `hygiene_state` | VARCHAR(16) NOT NULL DEFAULT '' | `glpi_missing` (A), `glpi_review` (A'), `nms_dead` (B), `nms_unknown` (C), `unmanaged_alive` (D), `ok` (E), `excepted` (X), '' |

### 8.2 Tabel baru

`glpi_plugin_opmanager_deviceexceptions`: pengecualian dan riwayatnya.

| Kolom | Tipe |
|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK |
| `device_key` | VARCHAR(255) NOT NULL DEFAULT '' (KEY) |
| `reason` | TEXT NULL |
| `users_id` | INT UNSIGNED NOT NULL DEFAULT 0 (pembuat) |
| `is_active` | TINYINT NOT NULL DEFAULT 1 |
| `revoked_by` | INT UNSIGNED NOT NULL DEFAULT 0 |
| `revoked_at` | TIMESTAMP NULL |
| `date_creation`, `date_mod` | TIMESTAMP NULL |

Paling banyak satu baris aktif per `device_key`. Aturan ini dijaga di kode, karena MySQL tidak
punya partial unique index.

`glpi_plugin_opmanager_hygienetickets`: satu baris per tiket kebersihan.

| Kolom | Tipe |
|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK |
| `flow` | VARCHAR(8) NOT NULL DEFAULT '' (`glpi` / `nms`) |
| `tickets_id` | INT UNSIGNED NOT NULL DEFAULT 0 (KEY) |
| `last_report_at` | TIMESTAMP NULL |
| `date_creation`, `date_mod` | TIMESTAMP NULL |

`glpi_plugin_opmanager_hygieneitems`: perangkat yang sudah dilaporkan di tiket kebersihan.
Dipakai untuk menghitung selisih "baru / sudah selesai" pada followup.

| Kolom | Tipe |
|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK |
| `plugin_opmanager_hygienetickets_id` | INT UNSIGNED NOT NULL DEFAULT 0 |
| `device_key` | VARCHAR(255) NOT NULL DEFAULT '' |
| `hygiene_state` | VARCHAR(16) NOT NULL DEFAULT '' (kondisi saat dilaporkan) |
| `reported_at` | TIMESTAMP NULL |
| `changed_at` | TIMESTAMP NULL (kondisi berubah di alur yang sama) |
| `resolved_at` | TIMESTAMP NULL (dilaporkan "sudah tidak memenuhi") |

UNIQUE (`plugin_opmanager_hygienetickets_id`, `device_key`).

### 8.3 Right baru

| Right | Mask | Gerbang |
|---|---|---|
| `plugin_opmanager_hygiene` | READ \| UPDATE | READ: blok Kebersihan NMS + filter di Devices. UPDATE: buat/batalkan pengecualian |

Seeding saat install/upgrade memakai `RightSeeder` yang sudah ada. Right baru ini mengikuti
pola `plugin_opmanager_monitoring`. Hanya profil yang punya `config` UPDATE yang otomatis
mendapat right penuh. Admin memberikan UPDATE ke profil tim NMS dan tim GLPI secara manual.
Perangkat yang di-pin manual dihitung Matched.

### 8.4 Config key baru (context `plugin:opmanager`)

| Key | Default | Arti |
|---|---|---|
| `hygiene_enabled` | `0` | buat tiket kebersihan (klasifikasi selalu jalan) |
| `hygiene_frequency_days` | `7` | jarak antar run tiket (1–90) |
| `hygiene_unmanaged_availabilities` | `["UnManaged","Device Not Monitored."]` | nilai availability yang dianggap tidak dimonitor |
| `hygiene_agent_stale_days` | `7` | batas `last_contact` agent |
| `hygiene_mist_stale_days` | `7` | batas `mist_last_seen` |
| `hygiene_probe_cidrs` | `[]` | segmen yang boleh di-probe; kosong = probe mati |
| `hygiene_probe_tcp_ports` | `[22,443,80,3389,161]` *(usulan)* | port yang dicoba (161 hanya bermakna untuk TCP; builder boleh mengganti) |
| `hygiene_probe_timeout_ms` | `1000` | timeout per koneksi |
| `hygiene_ticket_template` | `0` | template tiket kebersihan; 0 = template Request default |
| `hygiene_glpi_groups` | `[]` | grup tiket GLPI (tim GLPI + tim aset); rc7: observer, opsional |
| `hygiene_glpi_users` | `[]` | rc4: user (teknisi penanggung jawab input dan cek aset) yang ikut di-assign ke tiket GLPI; opsional |
| `hygiene_itil_category` | `0` | rc5: ITIL category kedua tiket kebersihan; 0 = tanpa category |
| `hygiene_nms_group` | `0` | grup tim NMS: assigned tiket NMS dan observer tiket GLPI |
| `last_hygiene_run` | '' | |
| `last_hygiene_ticket_run` | '' | |
| `last_hygiene_error` | '' | |

`hygiene_enabled = 1` tanpa grup yang terisi adalah galat Setup yang terlihat. Tiket tidak
dibuat dalam keadaan itu.

### 8.5 Cron baru

`HygieneCheck` (§7).

### 8.6 Hook baru

`$PLUGIN_HOOKS['opmanager_liveness']`: provider bukti hidup dari plugin lain (§3).

## 9. Pengujian & rilis

- Unit test murni untuk: klasifikasi (tabel §2, termasuk normalisasi availability), penggabungan
  bukti cek hidup (§3), pemilihan "tiket baru vs followup vs tidak ada", dan selisih
  baru/selesai, validasi pengecualian, serta sanitasi config.
- Static test pola suite yang sudah ada (gateway lewat objek core, tidak ada raw SQL ke tabel
  core, guard `isPluginActive('mist')`).
- Rilis **1.3.0-rc1** dulu (hub aturan 8), uji di production, lalu 1.3.0. Spec dan rilis
  dikirim ke orkestrator untuk dicatat sebagai referensi `nms`.

## 10. Bukan cakupan

- Menghapus, me-manage, atau mengubah perangkat di OpManager dari GLPI.
- Membuat aset GLPI dari data OpManager (D15 tetap berlaku).
- Melaporkan aset GLPI yang tidak ada di NMS.
- ICMP ping.
- Pekerjaan multi-brand (itu milik `nms`).

## 11. Diserahkan ke builder

- Tata letak persis blok UI dan teks tiket (format HTML tabel di followup).
- Cara efisien mencocokkan baris `mist` berdasarkan IP (indeks / satu query per run).
- Batas waktu total probe per run, dan urutan prioritas probe bila batas tercapai.
