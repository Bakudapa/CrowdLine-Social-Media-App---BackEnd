# Decision Log

Format setiap keputusan: **Konteks → Keputusan → Alternatif → Alasan → Trade-off**.
Status: `Accepted`, `Superseded by D-xxx`, atau `Proposed`. Keputusan lama tidak dihapus. Jika berubah, tambahkan keputusan baru yang menggantikannya.

---

## D-001 — Sharding di level aplikasi, beberapa instance Postgres

- **Status:** Accepted (2026-10-13)
- **Konteks:** Tujuan proyek adalah *belajar dan mengukur* masalah hot shard. Sharding bisa dilakukan oleh database (mis. Citus) atau oleh aplikasi.
- **Keputusan:** Aplikasi Laravel sendiri yang memilih shard (komponen `ShardRouter`). Setiap shard adalah container Postgres terpisah. Data non-shard (users, token, `shard_map`) berada di `pg_global`.
- **Alternatif:**
  - *Citus / Postgres partitioning:* routing otomatis, tetapi menyembunyikan mekanisme yang justru ingin dipelajari.
  - *Satu Postgres, beberapa schema:* ringan, tetapi semua shard berbagi CPU/IO yang sama sehingga hot shard tidak benar-benar terisolasi.
- **Alasan:** Routing manual membuat setiap konsep (lokasi data, query lintas shard, perpindahan data) terlihat di kode.
- **Trade-off:** Lebih banyak kode, dan query lintas shard (timeline, follow) harus ditangani sendiri. Tidak ada JOIN atau transaksi lintas shard.

## D-002 — Directory-based routing dengan penempatan awal `user_id mod N`

- **Status:** Accepted (2026-10-13)
- **Konteks:** Router perlu tahu "data user X ada di shard mana".
- **Keputusan:** Tabel `shard_map(user_id → shard)` di `pg_global` adalah sumber kebenaran. Saat register, shard dipilih dengan `user_id mod N` (N = jumlah shard reguler), lalu hasilnya **disimpan** di `shard_map`.
- **Alternatif:**
  - *Pure hash (tanpa tabel):* tanpa lookup, tetapi satu user tidak bisa dipindah. Dedicated shard (FR-18) jadi mustahil, dan menambah N akan memindahkan hampir semua user.
  - *Consistent hashing:* mengurangi perpindahan saat N berubah, tetapi tetap tidak bisa memindahkan satu user tertentu, dan lebih rumit.
  - *Hash kriptografis/crc32 sebelum mod:* berguna jika ID tidak berurutan. ID kita bigint berurutan, jadi `mod N` sudah tersebar rata.
- **Alasan:** Perpaduan paling sederhana: rumus mudah untuk penempatan awal, dan fleksibilitas tabel untuk memindahkan selebriti.
- **Trade-off:** Setiap request butuh lookup `shard_map`. Hal ini dimitigasi dengan cache in-memory + Redis (FR-06), dan cache itu wajib dibersihkan saat user dipindah. `pg_global` menjadi dependensi tunggal untuk lookup.

## D-003 — ID post: UUIDv7, ID publik `{author_id}_{uuid}`

- **Status:** Accepted (2026-10-13)
- **Konteks:** Dengan banyak shard, *auto-increment* per shard menghasilkan ID kembar (shard_0 dan shard_1 sama-sama punya post id=1). Selain itu, `GET /posts/{id}` harus tahu shard tujuan **tanpa** bertanya ke semua shard.
- **Keputusan:**
  - Kolom `posts.id` = **UUIDv7**, di-generate di aplikasi. UUIDv7 unik global dan terurut waktu, sehingga tetap ramah indeks B-tree.
  - ID yang dikirim ke klien = `{author_id}_{uuid}`. Router mem-parse `author_id`, lalu memakai `shard_map` → shard.
- **Alternatif:**
  - *Snowflake 64-bit dengan shard ID di dalamnya (gaya Instagram):* ringkas, tetapi **rusak saat dedicated shard**: post selebriti dipindah ke shard lain, sementara ID-nya masih menunjuk ke shard lama. Juga perlu koordinasi worker ID.
  - *Tabel lookup global `post_id → shard`:* setiap post baru menulis ke `pg_global`, sehingga `pg_global` justru menjadi hotspot baru.
  - *UUIDv4:* acak, sehingga insert menyebar ke seluruh indeks (fragmentasi). UUIDv7 lebih baik untuk itu.
- **Alasan:** Lokasi post mengikuti lokasi author. Karena `shard_map` tetap benar setelah perpindahan, ID post juga tetap valid.
- **Trade-off:** ID panjang (±40 karakter) dan `author_id` terlihat publik (bukan masalah, karena author memang ditampilkan). Indeks UUID (16 byte) lebih besar dari bigint (8 byte).

## D-004 — Timeline: fan-out on read saja di MVP

- **Status:** Accepted (2026-10-13)
- **Konteks:** Home timeline menggabungkan post dari semua user yang di-follow, dan mereka tersebar di banyak shard.
- **Keputusan:** Saat timeline diminta: ambil daftar followee (dari shard pembaca), kelompokkan per shard, ambil `limit` post terbaru dari setiap shard, lalu gabungkan (merge) dan potong ke `limit`.
- **Alternatif:** *Fan-out on write:* saat post dibuat, ID-nya disalin ke timeline setiap follower di Redis. Baca jadi sangat cepat, tetapi selebriti dengan 6.000+ follower memicu ribuan tulisan per post, dan ini perlu queue/worker. Ini juga masalah hotspot tersendiri.
- **Alasan:** Fokus proyek adalah tiga solusi hot shard. Fan-out on read lebih sederhana dan justru **memperlihatkan** read hotspot: setiap follower selebriti membaca shard selebriti.
- **Trade-off:** Timeline lebih lambat jika followee tersebar di semua shard. Jumlah query maksimal = jumlah shard. Boleh menjadi eksperimen lanjutan setelah M7.

## D-005 — Konsistensi follow lintas shard: urutan tulis + rekonsiliasi

- **Status:** Accepted (2026-10-13)
- **Konteks:** Follow menulis ke dua tempat yang bisa berbeda shard: baris `follows` di shard follower dan `follower_counts` di shard followee. Transaksi database **tidak bisa** mencakup dua instance Postgres.
- **Keputusan:**
  1. Tulis baris `follows` dulu. Ini sumber kebenaran, dan PK mencegah follow ganda.
  2. Jika insert benar-benar terjadi (bukan duplikat), update `follower_counts` di shard followee.
  3. Jika langkah 2 gagal: log error dan kembalikan sukses. `php artisan follows:reconcile` menghitung ulang counter dari data `follows`.
- **Alternatif:**
  - *Two-phase commit (2PC):* Postgres mendukung `PREPARE TRANSACTION`, tetapi rumit dan bisa meninggalkan transaksi menggantung.
  - *Transactional outbox + worker:* andal (*eventually consistent*), tetapi butuh queue, worker, dan retry. Terlalu banyak untuk proyek pertama.
- **Alasan:** Counter boleh sedikit meleset sementara, sedangkan data follow tidak boleh. Urutan tulis menjamin yang hilang hanya counter, dan counter bisa dihitung ulang.
- **Trade-off:** `follower_count` bisa sementara tidak akurat jika terjadi kegagalan. Ini diterima dan didokumentasikan. Outbox bisa dicatat sebagai peningkatan.

## D-006 — Key salting: 16 slot default, counter acak per slot

- **Status:** Accepted (2026-10-13)
- **Konteks:** Satu post selebriti di-like ribuan kali per detik. Tanpa salting, semua `UPDATE posts SET like_count = like_count + 1` mengantre pada **lock satu baris yang sama**.
- **Keputusan:** Counter dipecah menjadi S baris `post_like_counters(post_id, slot)`. Setiap like memilih slot acak `0..S-1`, sehingga lock tersebar ke S baris. Jumlah like = `SUM(count)` dari semua slot. Default **S = 16**. Di M5 dibandingkan S = 4, 16, dan 64.
- **Alternatif:**
  - *Counter di Redis (`INCR`):* sangat cepat, tetapi bukan "salting di database" yang ingin dipelajari, dan butuh sinkronisasi ke DB.
  - *Batching (kumpulkan like, tulis per detik):* efektif, tetapi menambah delay dan kompleksitas worker.
- **Alasan:** Salting adalah teknik umum untuk *hot key*, dan efeknya mudah diukur lewat lock wait.
- **Trade-off:** Write lebih cepat, tetapi **read lebih mahal** (menjumlahkan S baris), sehingga perlu cache pendek (NFR-09). S terlalu besar membuat read berat, sedangkan S terlalu kecil membuat contention tetap tinggi. Inilah yang diukur di M5.

## D-007 — Lingkungan target: satu mesin (laptop) dengan isolasi resource

- **Status:** Accepted (2026-10-13)
- **Konteks:** Belum ada server cloud. Kemungkinan besar semuanya berjalan di laptop.
- **Keputusan:** Docker Compose dengan batas `cpus`/`mem_limit` yang sama untuk setiap shard (NFR-04). Semua konfigurasi lewat env, sehingga pindah ke cloud tidak mengubah kode.
- **Alternatif:** *Tanpa batas resource:* shard yang panas memakai CPU laptop yang sama dengan shard lain, sehingga noisy neighbor muncul di **semua** shard dan dedicated shard tampak tidak berguna.
- **Alasan:** Batas resource meniru "satu shard = satu mesin" secara cukup realistis untuk perbandingan relatif.
- **Trade-off:** Angka absolut tidak mewakili produksi. Disk tetap berbagi (batas IO lebih sulit di Docker), dan hal ini dicatat di setiap laporan benchmark.
