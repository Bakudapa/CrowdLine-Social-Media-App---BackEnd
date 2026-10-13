# PRD — CrowdLine (Hot Shard Social Media)

| | |
|---|---|
| Status | Draft v0.2 |
| Tanggal | 2026-10-13 |
| Pemilik | Developer (portfolio project) |
| Stack | Laravel, PostgreSQL (multi-instance), Redis, Pest, Docker Compose, GitHub Actions, k6 |

Dokumen ini adalah **sumber kebenaran kebutuhan**. Setiap pekerjaan merujuk ke ID `FR-xx` (functional) atau `NFR-xx` (non-functional). Perubahan kebutuhan dicatat di bagian [Riwayat perubahan](#16-riwayat-perubahan), sedangkan keputusan desain dicatat di `docs/decisions.md`.

---

## 1. Ringkasan

CrowdLine adalah backend media sosial sederhana (post, follow, timeline, like) yang datanya di-shard ke beberapa instance PostgreSQL. Tujuannya bukan membangun produk lengkap, melainkan **mendemonstrasikan dan mengukur** masalah *hot shard / celebrity problem*, lalu membandingkan tiga solusi:

1. **Cache untuk read** (Redis, cache-aside)
2. **Dedicated shard** (akun selebriti dipindah ke shard sendiri)
3. **Key salting** (counter/kunci panas dipecah ke beberapa slot)

Setiap klaim perbaikan harus didukung angka dari k6 (latency p95, throughput, error rate) dan `EXPLAIN ANALYZE` jika relevan.

## 2. Latar belakang & masalah

Pada sistem yang di-shard berdasarkan `user_id`, semua data milik satu user berada di satu shard. Distribusi popularitas di media sosial sangat timpang (mendekati distribusi Zipf). Akibatnya, satu akun selebriti dapat:

- **Read hotspot:** menerima sebagian besar trafik baca (profil, post, timeline follower), sehingga CPU dan koneksi pada shard-nya habis.
- **Write hotspot:** menerima ribuan like per detik ke satu post. Semua tulisan menuju **satu baris counter**, sehingga terjadi lock contention.
- **Noisy neighbor:** user biasa yang kebetulan satu shard dengan selebriti ikut lambat, padahal trafik mereka sendiri normal.

## 3. Tujuan & non-tujuan

### 3.1 Tujuan
- G1. Mereproduksi ketiga gejala hot shard secara konsisten dan terukur.
- G2. Mengimplementasikan ketiga solusi sebagai **strategi yang bisa dinyalakan dan dimatikan**, agar perbandingan dilakukan pada codebase dan dataset yang sama.
- G3. Menghasilkan laporan benchmark sebelum/sesudah yang dapat direproduksi (`docs/benchmarks.md`).
- G4. Menjadi sarana belajar: logika inti ditulis oleh developer (lihat CLAUDE.md, kolom "Penulis inti" di bawah).

### 3.2 Non-tujuan
- Frontend/UI (hanya REST API JSON).
- Media upload, komentar, notifikasi, DM, pencarian, moderasi.
- Resharding otomatis atau rebalancing online tanpa downtime. Perpindahan ke dedicated shard dilakukan lewat command manual.
- Fan-out on write ke timeline (lihat [Pertanyaan terbuka](#15-pertanyaan-terbuka)). Timeline MVP memakai fan-out on read.
- Multi-region dan high availability (replica, failover).
- Skala produksi nyata. Angka benchmark bersifat **relatif** (perbandingan antar strategi di mesin yang sama), bukan absolut.

## 4. Aktor

| Aktor | Deskripsi |
|---|---|
| Regular user | User biasa dengan sedikit follower dan trafik rendah. |
| Celebrity user | User dengan sangat banyak follower (mis. ≥ 50% dari seluruh user). Sumber hotspot. |
| Operator/developer | Menjalankan seeder, memindahkan shard, menyalakan strategi, menjalankan benchmark. |
| Load generator (k6) | Mensimulasikan banyak user secara bersamaan. |

## 5. Asumsi & batasan lingkungan

- A1. Lingkungan target utama adalah **Docker Compose di satu mesin**. Mesin itu bisa laptop developer (Linux CachyOS) jika tidak tersedia server cloud.
- A2. Karena semua shard bisa berjalan di satu host, **isolasi resource per shard wajib** (NFR-04). Tanpa isolasi, shard berbagi CPU/IO host dan efek hot shard maupun dedicated shard tidak akan terlihat jelas.
- A3. k6 sebaiknya berjalan di mesin lain atau di container dengan CPU yang dibatasi, agar tidak berebut CPU dengan aplikasi. Jika terpaksa berjalan di host yang sama, hal ini dicatat di setiap hasil benchmark.
- A4. Ukuran dataset disesuaikan agar muat di laptop (lihat FR-22).
- A5. Jika nanti tersedia cloud, konfigurasi yang sama harus bisa dijalankan tanpa perubahan kode, cukup lewat env/config.

## 6. Arsitektur tingkat tinggi

```
                ┌─────────┐
   k6 ────────▶ │  Nginx  │
                └────┬────┘
                     ▼
              ┌──────────────┐        ┌─────────┐
              │ Laravel app  │◀──────▶│  Redis  │  cache, rate limit
              │ (php-fpm)    │        └─────────┘
              └──┬───────┬───┘
     ShardRouter │       │ global connection
     ┌───────────┼───────┼──────────────┐
     ▼           ▼       ▼              ▼
 ┌────────┐ ┌────────┐ ┌────────┐  ┌────────────┐   ┌───────────┐
 │shard_0 │ │shard_1 │ │shard_2 │  │shard_ded_0 │   │ pg_global │
 └────────┘ └────────┘ └────────┘  └────────────┘   └───────────┘
   (setiap container dibatasi cpus/memory)           users, tokens,
                                                      shard_map
```

- **pg_global:** data yang tidak di-shard, yaitu `users`, `personal_access_tokens` (Sanctum), dan `shard_map` (direktori user → shard).
- **shard_0..shard_N-1:** shard reguler. Penempatan awal: `hash(user_id) mod N`.
- **shard_ded_\*:** shard khusus untuk akun selebriti (FR-18). Saat strategi dedicated tidak aktif, shard ini kosong dan tidak dipakai.
- **ShardRouter:** komponen aplikasi yang menerjemahkan `user_id` menjadi nama koneksi database, berdasarkan `shard_map` (di-cache di Redis/memori).
- Default jumlah shard: **3 reguler + 1 dedicated**. Angka ini dapat diubah lewat config.

> Keputusan arsitektur (directory-based routing vs. pure hash, jumlah shard, pilihan driver) dicatat di `docs/decisions.md`.

## 7. Model data

### 7.1 pg_global

| Tabel | Kolom utama | Catatan |
|---|---|---|
| `users` | `id` (bigint), `username` (unique), `email` (unique), `password`, `is_celebrity` (bool), `created_at` | `id` dibuat di global agar unik lintas shard. |
| `personal_access_tokens` | standar Sanctum | |
| `shard_map` | `user_id` (PK), `shard` (string), `updated_at` | Sumber kebenaran lokasi data user. |

### 7.2 Setiap shard (skema identik)

Data di-shard berdasarkan **pemilik** baris:

| Tabel | Kunci shard | Kolom utama | Catatan |
|---|---|---|---|
| `posts` | `author_id` | `id` (UUIDv7), `author_id`, `body` (≤ 280 char), `like_count`, `created_at` | `id` dibuat di aplikasi agar unik lintas shard. ID publik = `{author_id}_{uuid}` (D-003). |
| `post_likes` | pemilik post | `post_id`, `user_id`, `created_at`, PK (`post_id`, `user_id`) | Menjamin idempoten: satu user hanya bisa like satu kali. |
| `post_like_counters` | pemilik post | `post_id`, `slot` (0..S-1), `count`, PK (`post_id`, `slot`) | Hanya dipakai saat strategi salting aktif (FR-19). |
| `follows` | `follower_id` | `follower_id`, `followee_id`, `created_at`, PK (`follower_id`, `followee_id`) | Disimpan di shard **follower**, sehingga "siapa yang saya follow" cukup dibaca dari satu shard. |
| `follower_counts` | `followee_id` | `followee_id`, `count` | Denormalisasi jumlah follower. Ikut menjadi hot row untuk selebriti. |

Indeks minimum: `posts(author_id, created_at DESC, id DESC)` untuk timeline dan daftar post.

## 8. Strategi hotspot (mode yang dapat dinyalakan)

Strategi dikendalikan lewat config/env, misalnya:

```
HOTSPOT_CACHE=true|false
HOTSPOT_DEDICATED=true|false
HOTSPOT_SALTING=true|false
HOTSPOT_SALT_SLOTS=16
```

Kombinasi yang wajib diuji: `baseline` (semua off), `cache`, `dedicated`, `salting`, dan `all` (semua on).

## 9. Kebutuhan fungsional (FR)

Kolom **Penulis inti**: `Claude` = boleh ditulis Claude (boilerplate, migration, config, seeder, dsb.). `Human` = logika inti ditulis developer, Claude hanya menyiapkan kerangka + `TODO(human)` + test yang gagal (lihat CLAUDE.md).

### 9.1 Auth

| ID | Kebutuhan | Kriteria penerimaan | Penulis inti |
|---|---|---|---|
| FR-01 | Register user | `POST /api/v1/auth/register` dengan `username`, `email`, `password` → 201 + data user. Username dan email unik (422 jika duplikat). Password di-hash. Baris `shard_map` dibuat dalam transaksi yang sama. | Claude (validasi), Human (penempatan shard) |
| FR-02 | Login, terbit token | `POST /api/v1/auth/login` → 200 + Sanctum token. Kredensial salah → 401 tanpa membocorkan apakah email terdaftar. | Claude |
| FR-03 | Logout | `POST /api/v1/auth/logout` mencabut token saat ini → 204. | Claude |
| FR-04 | Proteksi endpoint | Semua endpoint selain register/login/health butuh `Authorization: Bearer`. Tanpa token → 401. | Claude |

### 9.2 Sharding

| ID | Kebutuhan | Kriteria penerimaan | Penulis inti |
|---|---|---|---|
| FR-05 | Konfigurasi multi-koneksi | Koneksi `pg_global`, `shard_0..N-1`, `shard_ded_0` didefinisikan di config dari env. Migration shard bisa dijalankan ke semua shard dengan satu command (`php artisan shards:migrate`). | Claude |
| FR-06 | ShardRouter | Diberi `user_id`, mengembalikan nama koneksi berdasarkan `shard_map`. Hasil lookup di-cache (in-memory per request + Redis) dan dibersihkan saat mapping berubah. User yang tidak dikenal → exception yang jelas. | **Human** |
| FR-07 | Penempatan awal | User baru ditempatkan dengan `user_id mod N` pada shard reguler (D-002). Hasilnya disimpan di `shard_map`, sehingga perubahan N kelak hanya memengaruhi user baru. | **Human** |
| FR-08 | Penandaan selebriti | `php artisan users:mark-celebrity {user}` mengubah `is_celebrity`. Seeder juga dapat menandai selebriti. | Claude |

### 9.3 Post

| ID | Kebutuhan | Kriteria penerimaan | Penulis inti |
|---|---|---|---|
| FR-09 | Buat post | `POST /api/v1/posts` dengan `body` 1–280 karakter → 201. Disimpan di shard milik author. | **Human** (query ke shard) |
| FR-10 | Baca post | `GET /api/v1/posts/{id}` → 200 dengan `like_count` dan `liked_by_me`. Post tidak ada atau format ID salah → 404. Shard ditemukan dari `author_id` di ID publik → `shard_map` (D-003). | **Human** |
| FR-11 | Daftar post user | `GET /api/v1/users/{id}/posts?cursor=&limit=` → cursor pagination berdasarkan (`created_at`, `id`), `limit` default 20 dan maks 50. | **Human** |
| FR-12 | Profil user | `GET /api/v1/users/{id}` → username, `follower_count`, `following_count`, `post_count`. | **Human** |

### 9.4 Follow & timeline

| ID | Kebutuhan | Kriteria penerimaan | Penulis inti |
|---|---|---|---|
| FR-13 | Follow / unfollow | `POST` / `DELETE /api/v1/users/{id}/follow` idempoten (follow dua kali tidak menambah count). Tidak bisa follow diri sendiri (422). `follower_counts` di shard followee ikut berubah. Urutan tulis: baris `follows` dulu (sumber kebenaran), lalu counter. Jika langkah kedua gagal, error di-log dan `php artisan follows:reconcile` menghitung ulang counter (D-005). | **Human** |
| FR-14 | Home timeline | `GET /api/v1/timeline?cursor=&limit=` mengembalikan post terbaru dari user yang di-follow, terurut `created_at DESC`. Strategi: fan-out on read, yaitu mengelompokkan followee per shard, query paralel/berurutan per shard, lalu merge k-way dan potong ke `limit`. | **Human** |

### 9.5 Like

| ID | Kebutuhan | Kriteria penerimaan | Penulis inti |
|---|---|---|---|
| FR-15 | Like / unlike | `POST` / `DELETE /api/v1/posts/{id}/like` idempoten. Insert `post_likes` dan update counter dalam satu transaksi di shard post. Tidak ada lost update di bawah konkurensi (diuji dengan test paralel). | **Human** (transaksi/locking) |
| FR-16 | Baca jumlah like | `like_count` pada FR-10/FR-11. Saat salting aktif, nilainya adalah jumlah semua slot. Selisih maksimum terhadap nilai sebenarnya ditentukan di NFR-09. | **Human** |

### 9.6 Solusi hotspot

| ID | Kebutuhan | Kriteria penerimaan | Penulis inti |
|---|---|---|---|
| FR-17 | Read cache | Saat `HOTSPOT_CACHE=true`: profil, post, dan halaman pertama daftar post user di-cache di Redis (cache-aside, TTL dapat dikonfigurasi). Ada invalidasi saat post dibuat, like berubah (atau TTL pendek untuk counter), dan follow berubah. Ada **proteksi cache stampede** (mis. lock atau early refresh) pada key selebriti. Header debug `X-Cache: HIT|MISS` di non-production. | **Human** |
| FR-18 | Dedicated shard | `php artisan shards:move {user} {shard}` memindahkan seluruh data user (posts, post_likes, counters, follower_counts, dan follows miliknya) ke shard tujuan: salin, verifikasi jumlah baris, update `shard_map`, invalidasi cache router, lalu hapus data lama. Selama perpindahan, tulisan user tersebut ditolak (503 + `Retry-After`) atau di-queue. Prosedur dan batasannya didokumentasikan. | **Human** |
| FR-19 | Key salting counter | Saat `HOTSPOT_SALTING=true`: like menambah `post_like_counters` pada slot acak `0..S-1` (bukan `posts.like_count`). Read menjumlahkan semua slot (opsional di-cache). Hal yang sama diterapkan pada `follower_counts` untuk selebriti. Perpindahan mode off → on tidak merusak count (ada command rekonsiliasi). | **Human** |
| FR-20 | Toggle strategi | Strategi pada §8 dibaca dari config tanpa mengubah kode. Endpoint `GET /api/v1/_meta/strategy` (non-production) menampilkan strategi yang aktif agar hasil k6 bisa diberi label. | Claude |

### 9.7 Dataset, benchmark, observability

| ID | Kebutuhan | Kriteria penerimaan | Penulis inti |
|---|---|---|---|
| FR-21 | Health check | `GET /api/v1/health` → status app, Redis, `pg_global`, dan setiap shard (200 / 503). Detail error hanya ke log. Shard mati terdeteksi dalam beberapa detik (connect timeout 2 detik). | Claude |
| FR-22 | Seeder dataset | Seeder deterministik (seed tetap) dengan ukuran yang dapat dikonfigurasi. Default laptop: **10.000 user, 1–3 selebriti** (selebriti utama di-follow ≥ 60% user), **~200.000 post**, distribusi follow mendekati Zipf. Selebriti utama sengaja ditempatkan di `shard_0` bersama banyak user biasa (untuk skenario noisy neighbor). Juga mengekspor daftar token/ID untuk k6. | Claude |
| FR-23 | Skrip k6 | Skrip untuk skenario S1–S3 (§11) dengan tag `scenario`, `strategy`, `user_type` (celebrity/regular), dan `shard`. Output ringkasan JSON disimpan ke `benchmarks/results/`. | Claude (skrip), Human (review skenario) |
| FR-24 | Metrik per shard | Aktifkan `pg_stat_statements` di setiap shard. Ada command/skrip untuk mengambil top query, jumlah koneksi aktif, dan lock wait per shard sebelum/sesudah run. Response menyertakan header `X-Shard` (non-production). | Claude |

## 10. Kebutuhan non-fungsional (NFR)

| ID | Kategori | Kebutuhan |
|---|---|---|
| NFR-01 | Pengukuran | Setiap klaim performa didukung hasil k6 (p50, p95, p99, RPS, error rate) dan, untuk query, `EXPLAIN (ANALYZE, BUFFERS)`. Hasil dicatat di `docs/benchmarks.md` beserta commit hash, strategi, spesifikasi mesin, dan ukuran dataset. |
| NFR-02 | Reproduksibilitas | Satu command (mis. `make bench SCENARIO=s1 STRATEGY=cache`) menjalankan: reset data → seed → warm-up → run → simpan hasil. Setiap kombinasi dijalankan **≥ 3 kali**, dan yang dilaporkan adalah median. |
| NFR-03 | Metodologi | Warm-up ≥ 30 detik (tidak dihitung). Durasi run ≥ 2 menit. Load profile sama antar strategi. Cache Redis di-flush di awal setiap run, kecuali skenario yang memang menguji cache hangat (dinyatakan eksplisit). |
| NFR-04 | Isolasi resource | Setiap container Postgres shard dibatasi resource yang sama (default: `cpus: 1.0`, `mem_limit: 1g`, `shared_buffers` disesuaikan). App dan Redis juga dibatasi. Batas dicatat di benchmarks.md. Ini wajib karena deploy bisa di satu laptop (A1–A2). |
| NFR-05 | Target performa (hipotesis) | Target berikut **hipotesis awal** dan divalidasi setelah baseline terukur: (a) S1 + cache: p95 baca profil/post selebriti turun ≥ 50% dari baseline. (b) S2 + salting: throughput like ke post selebriti naik ≥ 2× dan lock wait turun signifikan. (c) S3 + dedicated: p95 user biasa di shard asal selebriti kembali ke ≤ 1,2× p95 user di shard yang tidak panas. Jika target tidak tercapai, hasil tetap dilaporkan apa adanya beserta analisisnya. |
| NFR-06 | Baseline sehat | Pada beban rendah (tanpa hotspot), p95 endpoint baca ≤ 100 ms dan endpoint tulis ≤ 200 ms di lingkungan laptop. Jika tidak tercapai, perbaiki indeks/query dulu sebelum mengukur solusi hotspot, agar yang terukur memang efek hotspot. |
| NFR-07 | Error rate | Error rate (5xx + timeout) < 1% pada semua skenario. Jika lebih tinggi, hasil ditandai tidak valid atau dianalisis sebagai titik jenuh. |
| NFR-08 | Konsistensi like | Like idempoten. Tidak ada lost update: setelah N like konkuren dari N user berbeda, total count = N (diuji otomatis). |
| NFR-09 | Konsistensi cache | Data basi dari cache dibatasi TTL: post/profil ≤ 60 detik, counter ≤ 5 detik (dapat dikonfigurasi). Post baru milik user sendiri harus langsung terlihat oleh user itu (read-your-writes) di daftar post-nya. |
| NFR-10 | Keamanan | Password di-hash (bcrypt/argon2). Validasi semua input. Rate limit per token pada endpoint tulis (dapat dinonaktifkan saat benchmark, dan hal ini dicatat). Tidak ada SQL mentah dari input user tanpa binding. Endpoint `_meta` dan header debug mati di `APP_ENV=production`. Secrets hanya dari env, tidak di-commit. |
| NFR-11 | Testing | Pest: feature test untuk setiap endpoint FR. Unit test untuk ShardRouter, fungsi hash, merge timeline, dan salting. Test konkurensi untuk FR-15. Test berjalan terhadap multi-shard (≥ 2 shard) di Docker/CI, bukan hanya SQLite. |
| NFR-12 | CI | GitHub Actions: lint (Pint), static analysis (Larastan level yang disepakati), dan Pest dengan service Postgres multi-shard + Redis. CI tidak menjalankan benchmark penuh. Smoke k6 singkat (opsional) boleh dijalankan. |
| NFR-13 | Portabilitas | `docker compose up` + satu command setup cukup untuk menjalankan semuanya di Linux (fish shell: hindari skrip yang bergantung pada sintaks bash interaktif, gunakan `Makefile` atau skrip `#!/usr/bin/env bash`). Dapat dipindah ke cloud hanya dengan mengubah env. |
| NFR-14 | Observability | Log terstruktur (JSON) dengan `request_id`, `shard`, `duration_ms`. Slow query log (≥ 100 ms) aktif di setiap shard saat benchmark. |
| NFR-15 | Dokumentasi | `docs/decisions.md` diperbarui untuk setiap keputusan desain (alternatif + alasan). `README.md` memuat cara menjalankan, diagram arsitektur, dan ringkasan hasil benchmark. |
| NFR-16 | Kualitas proses | Commit kecil: satu FR atau satu langkah per commit, dengan pesan berbahasa Inggris yang mereferensikan ID (mis. `feat(FR-15): add idempotent like endpoint`). |

## 11. Skenario benchmark

Semua skenario dijalankan pada matriks strategi: `baseline`, `cache`, `dedicated`, `salting`, dan `all`. Strategi yang tidak relevan untuk suatu skenario tetap diukur sebagai kontrol.

### S1 — Read hotspot
- **Beban:** 90% request membaca profil, post, dan daftar post **selebriti utama**, sedangkan 10% membaca user acak.
- **Profil:** ramping VU 0 → target (mis. 200 VU) dalam 1 menit, tahan 2 menit.
- **Metrik utama:** p95 `GET /users/{celeb}`, `GET /users/{celeb}/posts`, CPU shard_0, dan cache hit ratio.
- **Solusi yang diharapkan berdampak:** cache (FR-17).

### S2 — Write hotspot
- **Beban:** banyak VU (user berbeda) melakukan like/unlike ke **satu post selebriti** secara bersamaan, ditambah follow ke selebriti.
- **Metrik utama:** RPS like sukses, p95 `POST /posts/{id}/like`, lock wait (`pg_stat_activity` / `pg_locks`), dan kebenaran count akhir (NFR-08).
- **Solusi yang diharapkan berdampak:** key salting (FR-19).

### S3 — Noisy neighbor
- **Beban:** campuran S1 + S2 pada selebriti di `shard_0`, sementara grup VU terpisah dengan **beban konstan rendah** mengakses user biasa di `shard_0` dan user biasa di `shard_1`/`shard_2`.
- **Metrik utama:** p95 user biasa di `shard_0` dibandingkan p95 user biasa di shard lain.
- **Solusi yang diharapkan berdampak:** dedicated shard (FR-18). Selebriti dipindah ke `shard_ded_0` sebelum run.

### Format laporan (di `docs/benchmarks.md`)
Untuk setiap skenario: tabel strategi × (p50, p95, p99, RPS, error %), grafik opsional, kondisi lingkungan (mesin, batas resource, ukuran dataset, commit), dan analisis singkat: kenapa angkanya seperti itu, serta trade-off yang dibayar (staleness, kompleksitas, biaya read pada salting, dsb.).

## 12. Kontrak API

Base path: `/api/v1`. Semua response JSON. Format error:

```json
{ "error": { "code": "validation_failed", "message": "...", "details": { "body": ["..."] } } }
```

| Method | Path | Auth | FR | Response sukses |
|---|---|---|---|---|
| POST | `/auth/register` | – | FR-01 | 201 `{ user }` |
| POST | `/auth/login` | – | FR-02 | 200 `{ token, user }` |
| POST | `/auth/logout` | ✓ | FR-03 | 204 |
| GET | `/users/{id}` | ✓ | FR-12 | 200 `{ id, username, follower_count, following_count, post_count, is_celebrity }` |
| GET | `/users/{id}/posts` | ✓ | FR-11 | 200 `{ data: [post], next_cursor }` |
| POST | `/users/{id}/follow` | ✓ | FR-13 | 204 |
| DELETE | `/users/{id}/follow` | ✓ | FR-13 | 204 |
| GET | `/timeline` | ✓ | FR-14 | 200 `{ data: [post], next_cursor }` |
| POST | `/posts` | ✓ | FR-09 | 201 `{ post }` |
| GET | `/posts/{id}` | ✓ | FR-10 | 200 `{ post }` |
| POST | `/posts/{id}/like` | ✓ | FR-15 | 204 |
| DELETE | `/posts/{id}/like` | ✓ | FR-15 | 204 |
| GET | `/health` | – | FR-21 | 200 / 503 `{ app, redis, global, shards: { shard_0: "ok" \| "down", ... } }` |
| GET | `/_meta/strategy` | – (non-prod) | FR-20 | 200 `{ cache, dedicated, salting, salt_slots }` |

Objek `post`:

```json
{
  "id": "42_01929a3e-7c1b-7d2e-9f00-3b6a1c2d4e5f",
  "author": { "id": 1, "username": "celeb" },
  "body": "hello",
  "like_count": 1234,
  "liked_by_me": false,
  "created_at": "2026-10-13T10:00:00Z"
}
```

Kode status umum: `401` tanpa/invalid token, `404` tidak ditemukan, `422` validasi, `429` rate limit, `503` shard tidak tersedia atau user sedang dipindah (dengan `Retry-After`).

## 13. Milestone

| # | Milestone | FR/NFR | Definisi selesai |
|---|---|---|---|
| M0 | Fondasi | FR-05, FR-21, NFR-04, NFR-12, NFR-13 | Docker Compose multi-shard dengan batas resource berjalan, CI hijau, health check OK. |
| M1 | Auth & routing | FR-01–FR-04, FR-06–FR-08 | User tersebar ke shard sesuai hash. Test router lolos. |
| M2 | Domain inti | FR-09–FR-16, NFR-08, NFR-11 | Semua endpoint berfungsi lintas shard. Test konkurensi like lolos. |
| M3 | Baseline terukur | FR-20, FR-22–FR-24, NFR-01–NFR-03, NFR-06 | Seeder + k6 berjalan. Ketiga gejala hotspot terbukti dengan angka di benchmarks.md. |
| M4 | Solusi: cache | FR-17, NFR-09 | S1 diukur ulang dan dilaporkan. |
| M5 | Solusi: salting | FR-19 | S2 diukur ulang dan dilaporkan. |
| M6 | Solusi: dedicated shard | FR-18 | S3 diukur ulang dan dilaporkan. |
| M7 | Finalisasi | NFR-05, NFR-15 | Matriks penuh (termasuk `all`), README dengan ringkasan hasil dan trade-off. |

## 14. Risiko & mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Semua shard di satu laptop berbagi CPU/disk | Efek hot shard dan dedicated shard tidak terlihat atau bias | NFR-04 (batas resource per container). Catat spesifikasi host. Pertimbangkan pinning CPU (`cpuset`). |
| k6 berebut CPU dengan app | Angka latency tidak akurat | Batasi CPU k6 atau jalankan dari mesin lain. Catat di laporan (A3). |
| Thermal throttling / proses lain di laptop | Variasi antar run tinggi | ≥ 3 run + median (NFR-02). Mode power "performance". Tutup aplikasi lain. |
| Bottleneck di PHP-FPM, bukan di DB | Yang terukur adalah app, bukan shard | Pantau CPU app vs shard. Tambah worker FPM atau gunakan Octane bila perlu (dicatat di decisions.md). |
| Konsistensi penulisan lintas shard (follow, move) | Count tidak sinkron | Strategi eksplisit (outbox / kompensasi) dan command rekonsiliasi. |
| Scope creep fitur sosial | Fokus hilang | Patuhi non-tujuan §3.2. |

## 15. Keputusan atas pertanyaan terbuka

Semua pertanyaan dari v0.1 sudah diputuskan. Detail dan alasannya ada di `docs/decisions.md`.

| # | Pertanyaan | Keputusan | Ref |
|---|---|---|---|
| 1 | Format ID post global | UUIDv7 di-generate aplikasi. ID publik `{author_id}_{uuid}`. | D-003 |
| 2 | Timeline fan-out on write | Tidak masuk MVP. Boleh menjadi eksperimen lanjutan setelah M7. | D-004 |
| 3 | Konsistensi follow lintas shard | Urutan tulis + command rekonsiliasi. Outbox tidak dipakai. | D-005 |
| 4 | Jumlah slot salting | Default 16. Di M5 dibandingkan 4 / 16 / 64. | D-006 |
| 5 | Lokasi deploy | Dirancang untuk laptop dulu (NFR-04). Cloud opsional lewat env. | D-007 |

Pertanyaan terbuka baru ditambahkan di sini saat muncul.

## 16. Glosarium

| Istilah | Arti |
|---|---|
| Shard | Satu instance database yang menyimpan sebagian data. |
| Hot shard | Shard yang menerima beban jauh di atas rata-rata. |
| Celebrity problem | Hot shard yang disebabkan oleh satu akun sangat populer. |
| Noisy neighbor | Tenant/user yang terdampak oleh beban user lain di resource yang sama. |
| Cache-aside | Aplikasi membaca cache dulu. Jika miss, ambil dari DB lalu isi cache. |
| Cache stampede | Banyak request bersamaan meng-miss key yang sama dan semuanya menghantam DB. |
| Key salting | Memecah satu kunci panas menjadi beberapa sub-kunci (slot) agar beban tersebar. |
| Fan-out on read / write | Timeline disusun saat dibaca / disebarkan ke follower saat post ditulis. |
| p95 | Persentil ke-95 latency: 95% request lebih cepat dari nilai ini. |

## 17. Riwayat perubahan

| Versi | Tanggal | Perubahan |
|---|---|---|
| 0.1 | 2026-10-13 | Draft awal. |
| 0.2 | 2026-10-13 | Pertanyaan terbuka #1–#5 diputuskan (D-001–D-007). FR-07, FR-10, FR-13, §7.2 disesuaikan. |
