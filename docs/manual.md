# ManageEngine OpManager integration (`opmanager`) — Manual Penggunaan

> Versi didokumentasikan: **1.3.1** · Status rilis: **1.3.1 FINAL** (tag `1.3.1`, commit `b2a0ee6`) · Terakhir diperbarui: 2026-11-10

## 1. Ringkasan

Plugin **ManageEngine OpManager integration** menghubungkan inventori dan alarm OpManager dengan aset serta tiket GLPI. Plugin mencocokkan perangkat OpManager dengan aset GLPI, menampilkan alarm pada aset, menyediakan laporan Coverage, dan dapat membuat tiket dari alarm. Teknisi juga dapat mengirim catatan dan tindakan penanganan dari GLPI kembali ke OpManager.

Mulai versi 1.3.1, manual ini juga tersedia dari dalam plugin melalui menu **Manual**. Jika versi major.minor manual lebih lama daripada plugin terpasang, halaman Manual menampilkan peringatan bahwa beberapa layar mungkin berbeda.

Fitur **NMS hygiene** mengidentifikasi perangkat production yang dipantau tetapi belum tercatat di GLPI, serta perangkat yang tidak lagi dipantau namun perlu diperiksa. Fitur ini membantu tim GLPI dan NMS menindaklanjuti daftar tersebut melalui tiket Request berkala.

Prinsip penting:

- GLPI adalah sumber kebenaran untuk pencatatan aset; OpManager adalah sistem monitoring.
- OpManager hanya memantau perangkat production. Aset dev/staging di GLPI memang tidak ada di OpManager dan bukan celah Coverage atau temuan NMS hygiene.
- Plugin tidak membuat aset GLPI dari perangkat OpManager.
- Plugin tidak mengubah, menghapus, atau melakukan manage/unmanage perangkat di OpManager. Tindakan tersebut dilakukan oleh tim NMS.
- Aset GLPI yang tidak dipantau OpManager tidak otomatis dianggap bermasalah.
- Pembuatan tiket NMS hygiene **nonaktif secara default**. Tinjau klasifikasi terlebih dahulu sebelum mengaktifkannya.

## 2. Navigasi & lokasi menu

Plugin tersedia di menu bersama **ICT Tools** (nama menu dapat dikonfigurasi oleh administrator). Buka salah satu halaman OpManager untuk menampilkan navigasi tab:

| Tab | Fungsi |
|---|---|
| **Devices** | Daftar perangkat, pencocokan aset GLPI, kondisi NMS hygiene, dan halaman detail perangkat |
| **Alarms** | Daftar alarm OpManager yang tersimpan di GLPI |
| **Coverage** | Ringkasan pencocokan dan daftar hal yang perlu ditindaklanjuti |
| **Manual** | Manual penggunaan yang disertakan bersama paket plugin |

Tab perangkat pada item aset GLPI menampilkan bagian **Monitoring** berisi perangkat OpManager yang cocok dan alarm terkait. Kartu dashboard GLPI tersedia melalui **Home → Dashboard → Edit → Add widget**, dalam grup **OpManager**.

Pengaturan plugin ada di tab **OpManager** pada halaman konfigurasi **PGN Suite**. Bergantung pada plugin suite yang pertama kali dimuat, PGN Suite dapat muncul sebagai entri tersendiri di sidebar **Setup** atau sebagai tab di **Setup → General**. Pada instalasi yang masih menggunakan halaman konfigurasi plugin langsung, buka **Setup → Plugins**, lalu pilih **Setup** pada baris plugin.

## 3. Hak akses

Atur di **Administration → Profiles → (pilih profile) → tab OpManager**, lalu simpan perubahan.

| Right name | Label | Hak yang diberikan |
|---|---|---|
| `plugin_opmanager_config` | Configuration | **Read** untuk melihat pengaturan; **Update** untuk mengubah dan menyimpan pengaturan |
| `plugin_opmanager_monitoring` | Monitoring | **Read** untuk menu dan data monitoring, Devices, Alarms, Coverage, bagian Monitoring pada aset, serta kartu dashboard |
| `plugin_opmanager_hygiene` | NMS hygiene | **Read** untuk melihat informasi dan filter hygiene; **Update** untuk membuat atau membatalkan pengecualian perangkat |

Berikan hak minimum sesuai tugas. Hak **Update** NMS hygiene perlu diberikan kepada profil petugas yang berwenang mengelola pengecualian. Hak plugin tidak menggantikan hak GLPI lain yang diperlukan untuk melihat atau mengubah item GLPI.

## 4. Pengaturan

Lokasi: **Setup → PGN Suite → OpManager** bila PGN Suite tampil sebagai entri sidebar, atau **Setup → General → PGN Suite → OpManager** bila PGN Suite tampil sebagai tab di General. Jika halaman konfigurasi plugin masih terpisah, buka **Setup → Plugins → Setup** pada baris OpManager. Hanya pengguna dengan hak **Configuration** yang sesuai dapat membuka atau menyimpan pengaturan.

| Tab | Pengaturan dan tujuan |
|---|---|
| **Connection** | Alamat layanan dan kredensial API OpManager serta koneksi yang dipakai plugin |
| **Matching** | Aturan pencocokan perangkat ke aset GLPI, cakupan tipe aset, dan status GLPI yang dihitung sebagai aset dalam penggunaan untuk Coverage |
| **Tickets** | Pengaturan tiket alarm, termasuk sumber tiket, template dan pola konten, entitas, serta cakupan IP produksi |
| **Alarm filter** | Filter tipe alarm dan kata kunci untuk menentukan alarm yang boleh memicu tiket baru |
| **Webhook** | Mode webhook, token, dan status penerimaan webhook dari profil **Invoke a Webhook** di OpManager |
| **To OpManager** | Antrean tindakan keluar dari GLPI, status pengiriman, dan pengaturan percobaan ulang |
| **NMS hygiene** | Status hygiene, kelompok dan teknisi penerima tiket, template dan kategori ITIL, availability yang dianggap tidak dimonitor, serta pengaturan bukti liveness dan probe jaringan |

Klik **Save** setelah mengubah nilai. Status koneksi, sinkronisasi perangkat dan alarm, webhook, antrean ke OpManager, serta hygiene dapat dilihat pada status di halaman Setup.

### Persiapan NMS hygiene

1. Pastikan **Create hygiene tickets** tetap nonaktif saat awal konfigurasi.
2. Tentukan **Asset technician(s)** yang bertanggung jawab memeriksa perangkat yang belum terdaftar di GLPI.
3. Pilih **NMS team group** untuk tiket perangkat unmanaged. Grup GLPI/aset bersifat opsional dan akan menjadi observer pada tiket perangkat yang belum terdaftar.
4. Atur kategori ITIL bila proses GLPI Anda mewajibkannya sebelum tiket dapat diselesaikan atau ditutup.
5. Tinjau daftar nilai **Unmanaged availabilities**. Default-nya adalah `UnManaged` dan `Device Not Monitored.`.
6. Bila menggunakan probe TCP, masukkan hanya CIDR yang memang dapat dijangkau dari server GLPI. CIDR kosong berarti probe tidak digunakan. Sesuaikan port jika perlu.
7. Pilih template Request yang tidak meminta field wajib yang tidak dapat diisi otomatis. Nilai template kosong menggunakan template Request default.
8. Simpan konfigurasi, lalu tinjau kondisi dan bukti liveness pada Devices selama masa evaluasi. Setelah tim menyetujui hasilnya, aktifkan **Create hygiene tickets**.

Pembuatan tiket hygiene memerlukan minimal satu **Asset technician** dan **NMS team group**. Jika pengaturannya tidak lengkap, plugin menampilkan galat Setup dan tiket tidak dibuat.

## 5. Panduan penggunaan

### Sinkronisasi perangkat dan pencocokan aset

Plugin mengambil daftar perangkat dari OpManager, lalu mencocokkannya dengan aset GLPI yang termasuk dalam cakupan konfigurasi. Buka **Devices** untuk meninjau hasil pencocokan. Perangkat yang tidak ditemukan atau memiliki lebih dari satu kemungkinan pasangan perlu diperiksa; selesaikan pencocokan manual bila tindakan tersebut tersedia pada halaman perangkat.

Gunakan **Coverage** untuk melihat persentase cakupan, jumlah aset GLPI dan perangkat OpManager, perangkat yang belum ditemukan di GLPI, serta hasil pencocokan yang ambigu atau perlu ditindaklanjuti. Daftar Coverage dapat difilter, dibuka ke item terkait, dan diekspor sebagai CSV.

Pada halaman aset GLPI, buka tab **Monitoring** untuk melihat perangkat OpManager yang cocok dan alarm terkait. Tab hanya tersedia untuk tipe aset yang didukung dan diaktifkan dalam konfigurasi.

### Alarm dan tiket GLPI

Sumber tiket bawaan adalah kriteria yang dikirim oleh profil webhook OpManager. Dalam mode ini, alarm yang memenuhi cakupan IP produksi dan diterima dari webhook dapat membuat tiket; severity dan **Alarm filter** tidak dipakai untuk menentukan pembuatan tiket. Periksa kriteria alarm pada profil **Invoke a Webhook** di OpManager. Jangan mengaktifkan repeat notifications: setiap pengiriman ulang diperlakukan sebagai webhook baru dan dapat membuka kembali tiket yang berstatus Solved.

Plugin menyimpan alarm dan menyinkronkan perubahan berikutnya melalui polling berkala. Polling tetap memperbarui tiket yang sudah ada, menambahkan followup, serta memproses Clear/Solve; polling tidak membuat tiket baru dalam mode webhook tersebut. Jika webhook tidak dapat diproses karena sinkronisasi sedang sibuk atau API tidak tersedia, permintaan akan ditangani oleh polling berikutnya. Alarm webhook yang gagal membuat tiket akan dicoba lagi oleh polling.

Perilaku siklus tiket:

- Alarm dari perangkat yang sama dapat bergabung ke tiket alarm yang masih terbuka.
- Tiket berstatus **Solved** dapat dibuka kembali jika alarm yang sama dikirim lagi melalui webhook dan masih memenuhi kriteria.
- **Closed** bersifat final untuk alarm tersebut. Jika alarm berulang setelah tiket Closed, plugin memperlakukannya sebagai alarm baru; tiket baru dapat ditautkan ke tiket terdahulu dan kembali mengikuti kriteria pembuatan.
- Tiket yang masuk trash saat menjadi child dari tiket lain dapat diperlakukan seperti tiket yang digabung; alarm dapat mengikuti tiket parent.
- Pastikan field wajib pada template tiket tersedia. Field wajib yang tidak dapat diisi dapat menggagalkan pembuatan tiket; periksa status dan log plugin bila ini terjadi.

### Tindakan GLPI ke OpManager

| Tindakan manusia di GLPI | Tindakan ke alarm aktif di OpManager |
|---|---|
| Menambahkan followup publik pada tiket alarm | Mengirim catatan, lalu acknowledge alarm |
| Menyelesaikan (**Solve**) atau menutup (**Close**) tiket | Mengirim catatan dan clear alarm aktif yang terkait |

Followup privat, tindakan otomatis/cron, followup yang dibuat plugin sendiri, dan salinan tiket hasil merge tidak dikirim sebagai tindakan teknisi. Pengiriman yang gagal masuk antrean **To OpManager** untuk dicoba ulang.

### NMS hygiene

Kondisi perangkat mempertimbangkan status monitoring OpManager, pencocokan GLPI, bukti liveness, dan pengecualian aktif.

| Kondisi | Arti dan tindak lanjut |
|---|---|
| **Monitored, not in GLPI** | Perangkat production dipantau tetapi belum ditemukan sebagai aset GLPI; tinjau dan daftarkan/pasangkan melalui proses GLPI |
| **Monitored, needs manual pairing** | Perlu peninjauan pasangan aset |
| **Unmanaged, dead** | Tidak dimonitor dan bukti yang tersedia menunjukkan perangkat tidak hidup; tim NMS memeriksa apakah perlu dihapus dari OpManager |
| **Unmanaged, liveness unknown** | Tidak dimonitor dan bukti hidup tidak tersedia; perlu pemeriksaan manual tim NMS |
| **Unmanaged, still alive** | Tidak dimonitor tetapi terbukti hidup; ditampilkan sebagai informasi dan tidak masuk tiket |
| **Monitored and in GLPI** | Perangkat production dipantau dan sudah cocok dengan aset GLPI; tidak memerlukan tindak lanjut hygiene |
| **Excluded from hygiene tickets** | Dikecualikan dari kedua alur tiket |

Bukti liveness dapat berasal dari GLPI agent, plugin Juniper Mist yang aktif, dan probe TCP pada CIDR yang dikonfigurasi. Satu bukti **alive** mengalahkan bukti **dead**; status **dead** digunakan bila ada bukti dead tanpa bukti alive; bila tidak ada sumber bukti yang berlaku, hasilnya **unknown**. Perangkat yang tidak dimonitor hanya diperiksa liveness-nya bila tidak dikecualikan.

Ketika tiket hygiene diaktifkan, plugin membuat paling banyak dua tiket Request berkala:

- **Monitored in OpManager, not in GLPI** — dibagi menurut **Server, VM and storage**, **Network**, dan **Unknown category**. Ditugaskan kepada Asset technician(s); grup GLPI/aset dan NMS menjadi observer.
- **Unmanaged in OpManager: remove or check** — untuk perangkat yang dead atau liveness-nya unknown, dengan bagian **Dead** dan **Liveness unknown**, masing-masing dibagi menurut domain. Ditugaskan kepada NMS team group.

Perangkat tanpa temuan untuk suatu alur tidak menghasilkan tiket kosong. Selama tiket alur masih terbuka, run berikutnya tidak membuat tiket baru; followup hanya dibuat jika ada perubahan yang perlu dilaporkan. Jika tiket lama sudah Solved, Closed, atau dihapus, run berikutnya dapat membuat tiket baru berdasarkan kondisi terkini.

Untuk mengecualikan perangkat, buka halaman perangkat, pilih **Exclude from hygiene tickets**, dan isi alasan. Pengguna dengan hak NMS hygiene **Update** dapat membatalkan pengecualian. Riwayat mencatat siapa dan kapan pengecualian dibuat/dibatalkan; perangkat yang dikecualikan tidak masuk kedua alur tiket.

### Dashboard

Tambahkan kartu dari **Home → Dashboard → Edit → Add widget → OpManager**. Kartu yang tersedia mencakup Coverage, jumlah perangkat, aset GLPI yang tidak dimonitor, perangkat yang tidak ditemukan di GLPI, pencocokan, perangkat per kategori, alarm aktif per severity, dan tiket alarm terbuka. Hak **Monitoring: Read** diperlukan untuk melihat data kartu. Angka dapat memerlukan waktu hingga lima menit untuk diperbarui karena cache.

## 6. Automatic actions

Periksa status dan jadwal di **Setup → Automatic actions**. Nama tugas dan interval dapat bergantung pada konfigurasi instalasi.

| Tugas / pemicu | Fungsi |
|---|---|
| **DeviceSync** | Memperbarui inventori perangkat OpManager dan hasil pencocokan |
| **AlarmSync** | Polling alarm OpManager sebagai pembaruan dan safety net bagi webhook; interval bawaan 10 menit |
| Webhook **Invoke a Webhook** | Meminta plugin mengambil dan memproses alarm yang dikirim OpManager |
| Antrean **To OpManager** | Mengirim catatan, acknowledge, dan clear; percobaan ulang menggunakan backoff bertahap hingga batas konfigurasi |
| **HygieneCheck** | Tugas harian mode CLI; memperbarui klasifikasi dan liveness. Pembuatan tiket hanya berjalan bila diaktifkan dan jarak run tiket sudah terpenuhi (default 7 hari) |

Pastikan mode CLI berjalan untuk **HygieneCheck**. Ketika tiket hygiene nonaktif, klasifikasi tetap diperbarui agar dapat ditinjau sebelum pengiriman tiket dimulai.

## 7. Integrasi dan batasan operasional

- Integrasi memerlukan layanan API OpManager yang dapat dijangkau GLPI dan kredensial API yang valid.
- Perangkat alarm hanya masuk cakupan tiket sesuai sumber tiket, kriteria webhook, cakupan IP produksi, serta filter yang berlaku pada mode sumber tersebut.
- Webhook menggunakan token yang diatur pada tab **Webhook**. Uji dari profil **Invoke a Webhook** setelah konfigurasi dan pastikan badge webhook menunjukkan status baik.
- Probe jaringan hanya berjalan pada CIDR yang dicantumkan. Segmen yang tidak tercantum tidak diprobe dan tidak boleh dianggap dead berdasarkan probe.
- Probe menggunakan koneksi TCP, bukan ICMP ping. Kegagalan koneksi tidak membuktikan perangkat mati jika segmen atau port tidak dapat dijangkau dari server GLPI.
- Tiket hygiene melewati business rules tiket GLPI. Atur kelompok penerima di konfigurasi plugin, bukan dengan mengandalkan business rules.
- Instalasi produksi dapat menjalankan Escalade yang mempertahankan hanya satu grup assignee. Karena itu tiket GLPI hygiene mengassign Asset technician(s), sementara grup GLPI/aset dan grup NMS adalah observer; tiket NMS ditugaskan hanya ke NMS team group.
- Plugin Behaviors dapat mensyaratkan kategori ITIL sebelum **Solve** atau **Close**. Isi **ITIL category of the hygiene tickets** bila proses tersebut mewajibkannya.
- Template Request bawaan dapat menyembunyikan observer pada tampilan template. Periksa aktor tiket dan tab **Historical** bila observer tidak terlihat pada ringkasan.

## 8. Instalasi dan upgrade

Deploy paket plugin yang disetujui oleh administrator GLPI. Instalasi dan upgrade dilakukan melalui CLI GLPI sesuai prosedur operasional lingkungan; jangan memakai tombol upgrade plugin di web. Setelah upgrade:

1. Jalankan instalasi paksa plugin dan aktivasi bila diperlukan.
2. Jalankan pemeriksaan mandiri plugin (`tools/selfcheck.php`) sesuai prosedur admin.
3. Verifikasi **Setup → Automatic actions**, terutama **HygieneCheck** dalam mode CLI.
4. Periksa hak akses profil untuk **Configuration**, **Monitoring**, dan **NMS hygiene**. Berikan hak NMS hygiene secara manual pada profil tim yang perlu mengelola pengecualian.
5. Pastikan dokumentasi `docs/manual.md` disertakan dalam paket. Halaman **Manual** membaca file tersebut; jika belum disertakan, halaman akan menampilkan pemberitahuan bahwa manual belum terpasang.
6. Setelah perubahan menu atau aktivasi, pengguna mungkin perlu logout lalu login kembali agar menu GLPI diperbarui.

## 9. Troubleshooting

| Gejala | Pemeriksaan |
|---|---|
| Menu atau tab OpManager tidak terlihat | Pastikan plugin aktif dan profil memiliki hak **Monitoring: Read**; logout lalu login kembali |
| Status API key/koneksi bermasalah | Periksa alamat API, kredensial, keterjangkauan layanan, dan status terakhir di tab **Connection** |
| Perangkat atau alarm tidak berubah | Periksa status serta jadwal **DeviceSync**/**AlarmSync**, lalu periksa log plugin untuk galat API |
| Webhook diterima tetapi tiket tidak dibuat | Periksa token dan status webhook, kriteria **Invoke a Webhook**, IP produksi, sumber tiket, serta field wajib template |
| Tidak ada tiket dari alarm yang hanya terlihat saat polling | Dalam mode webhook, polling tidak membuat tiket baru; pastikan alarm dikirim oleh profil webhook yang dikonfigurasi |
| Tiket alarm tidak mengikuti filter yang diharapkan | Pastikan mode sumber tiket. Dalam mode default **OpManager webhook criteria**, severity dan **Alarm filter** tidak menentukan tiket baru |
| Catatan atau clear belum sampai ke OpManager | Periksa status antrean pada **To OpManager**, koneksi API, dan galat terakhir; antrean akan mencoba ulang |
| Perangkat unmanaged tampak dead | Periksa bukti liveness, status agent/Mist, CIDR yang dijangkau, port probe, firewall, dan waktu pemeriksaan terakhir |
| Hygiene tidak membuat tiket | Pastikan **Create hygiene tickets** aktif, minimal satu Asset technician dan NMS team group dipilih, frekuensi tiket sudah terpenuhi, dan ada perangkat yang memenuhi kondisi |
| Tiket hygiene tidak dapat diselesaikan/ditutup | Periksa kewajiban kategori dari Behaviors dan isi kategori ITIL hygiene pada Setup bila diperlukan |
| Halaman Manual menyatakan belum terpasang | Pastikan `docs/manual.md` ikut dalam paket rilis plugin, lalu deploy paket yang memuat file tersebut |

## 10. Status verifikasi dan batas cakupan

Versi **1.3.1 FINAL** menambahkan manual di dalam plugin; tidak ada perubahan fungsi lain dari 1.3.0. Versi 1.3.0-rc7 lulus pemeriksaan produksi pada **2026-11-10**. Catatan rilis mengonfirmasi fitur NMS hygiene dan alur tiketnya. Runbook 1.3.0 juga mencatat pemeriksaan instalasi, hak akses, konfigurasi, dan alur tiket; beberapa butir penerimaan manual masih berupa langkah yang perlu diverifikasi oleh administrator pada lingkungan masing-masing.

Karena itu, status ini bukan jaminan bahwa setiap koneksi, pemetaan aset, kriteria alarm, CIDR, kategori ITIL, template, hak profil, atau penerima tiket di semua instalasi sudah benar. Validasi nilai tersebut di GLPI dan OpManager sebelum mengaktifkan pembuatan tiket hygiene.
