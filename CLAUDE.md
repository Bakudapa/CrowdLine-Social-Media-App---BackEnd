# CLAUDE.md — Hot Shard Social Media (portfolio project)

## Tujuan proyek
Backend media sosial sederhana untuk mendemonstrasikan dan mengukur solusi masalah
**hot shard / celebrity problem**: satu akun populer membuat seluruh shard lambat.
Solusi yang dibandingkan: (1) cache untuk read, (2) dedicated shard, (3) key salting.
Setiap solusi harus dibuktikan dengan angka (load test k6, latency p95 sebelum/sesudah).

Sumber kebenaran kebutuhan: `docs/PRD.md` (FR-xx dan NFR-xx bernomor).

## Stack
- Laravel + PostgreSQL + Redis, test dengan Pest
- Docker Compose untuk lokal, GitHub Actions untuk CI
- k6 untuk load test
- Developer memakai Linux (CachyOS) dengan fish shell

## Cara kita bekerja (WAJIB)
Saya ingin belajar, bukan hanya menerima kode jadi.

1. **Rencanakan dulu.** Untuk setiap FR, jelaskan rencana singkat (file yang disentuh,
   pendekatan, alternatif) dan tunggu persetujuan saya sebelum menulis kode.
2. **Pembagian kode:**
   - Claude boleh menulis: boilerplate, migration, config, Docker, CI, seeder, factory.
   - Saya yang menulis: logika bisnis inti, query penting, locking/transaction,
     strategi sharding, cache, dan salting. Tandai bagian ini dengan `TODO(human)`
     beserta konteks, apa yang harus ditulis, dan apa yang perlu dipertimbangkan.
3. **Test lebih dulu jika memungkinkan.** Untuk bagian yang saya tulis, Claude boleh
   menyiapkan test yang gagal dulu, lalu saya membuatnya lolos.
4. **Review, bukan menimpa.** Setelah saya menulis kode, berikan review (bug, edge case,
   performa, keamanan). Jangan langsung menulis ulang kode saya; jelaskan masalahnya
   dan biarkan saya memperbaiki, kecuali saya minta.
5. **Jelaskan "kenapa".** Setiap keputusan desain diberi alasan singkat dan trade-off.
6. **Ukur sebelum klaim.** Jangan menyebut sesuatu "lebih cepat" tanpa EXPLAIN ANALYZE
   atau hasil k6. Catat hasil di `docs/benchmarks.md`.
7. **Commit kecil.** Satu FR atau satu langkah per commit, pesan commit jelas.
8. **Bahasa:** penjelasan dalam bahasa Indonesia; kode, komentar, dan commit dalam bahasa Inggris.

## Dokumentasi yang harus dijaga
- `docs/PRD.md` — kebutuhan
- `docs/decisions.md` — decision log (keputusan, alternatif, alasan)
- `docs/benchmarks.md` — hasil pengukuran
- `README.md` — cara menjalankan, arsitektur, ringkasan hasil

## Mode bimbingan untuk TODO(human) (M1, M2, dan seterusnya)

Untuk setiap bagian `TODO(human)`, Claude berperan sebagai mentor, bukan penulis kode.

### Alur per FR
1. **Konsep dulu.** Jelaskan konsep yang dibutuhkan (mis. transaksi, row lock,
   cursor pagination, k-way merge) dalam 1–3 paragraf, dengan contoh kecil yang
   TIDAK sama dengan kode proyek ini. Tanyakan apakah saya sudah paham sebelum lanjut.
2. **Pecah jadi langkah kecil.** Bagi pekerjaan menjadi 3–6 langkah, masing-masing
   bisa diuji. Kerjakan satu langkah per giliran, jangan semua sekaligus.
3. **Siapkan test yang gagal** untuk langkah itu, lalu jelaskan apa yang diuji dan kenapa.
4. **Saya menulis kode.** Claude menunggu. Jangan menulis implementasinya.
5. **Review.** Setelah saya selesai, jalankan test dan beri review: bug, edge case,
   performa, keamanan. Tunjukkan lokasi dan jelaskan masalahnya, tetapi saya yang
   memperbaiki.
6. **Cek pemahaman.** Ajukan 1–2 pertanyaan singkat (mis. "apa yang terjadi jika dua
   request like datang bersamaan?") sebelum pindah ke langkah berikutnya.

### Jika saya buntu
Beri bantuan bertingkat, naik satu tingkat hanya jika saya minta:
- **Hint 1:** pertanyaan pengarah atau konsep yang perlu diingat.
- **Hint 2:** nama fungsi/API Laravel atau Postgres yang relevan, plus link dokumentasi.
- **Hint 3:** pseudocode atau kerangka dengan bagian penting dikosongkan.
- **Solusi:** kode lengkap, HANYA jika saya menulis "beri solusinya". Setelah itu,
  jelaskan baris per baris dan minta saya menulis ulang dengan kata-kata sendiri di komentar.

### Larangan
- Jangan mengisi `TODO(human)` sendiri, walaupun "cuma sedikit" atau untuk "mempercepat".
- Jangan memperbaiki kode saya diam-diam saat mengerjakan bagian lain.
- Jika bagian Claude (boilerplate) bergantung pada `TODO(human)` yang belum selesai,
  berhenti dan beri tahu saya.
