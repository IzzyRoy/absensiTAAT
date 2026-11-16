# Absensi Sekolah SMAN 1 Tajurhalang

Aplikasi absensi sekolah berbasis web untuk mendukung pengelolaan kehadiran dan administrasi sekolah SMAN 1 Tajurhalang.

Repository ini digunakan sebagai tempat kolaborasi pengembangan aplikasi. Setiap anggota tim mengerjakan fitur pada branch masing-masing, kemudian mengajukan Merge Request untuk ditinjau sebelum digabungkan ke branch `main`.

**Repository GitLab:**  
https://gitlab.com/abisuryoN/absensi-sekolah

---

## Daftar Isi

1. [Teknologi yang Digunakan](#1-teknologi-yang-digunakan)
2. [Persyaratan Sistem](#2-persyaratan-sistem)
3. [Instalasi Proyek](#3-instalasi-proyek)
4. [Konfigurasi Database](#4-konfigurasi-database)
5. [Migration dan Seeder](#5-migration-dan-seeder)
6. [Akun Login Seeder](#6-akun-login-seeder)
7. [Menjalankan Aplikasi](#7-menjalankan-aplikasi)
8. [Alur Kolaborasi GitLab](#8-alur-kolaborasi-gitlab)
9. [Panduan untuk Anggota Tim](#9-panduan-untuk-anggota-tim)
10. [Panduan untuk Pemilik Repository](#10-panduan-untuk-pemilik-repository)
11. [Mengatasi Masalah Umum](#11-mengatasi-masalah-umum)
12. [Aturan Keamanan](#12-aturan-keamanan)

---

## 1. Teknologi yang Digunakan

- PHP 8.3
- Laravel 12 atau Laravel 13, mengikuti versi yang digunakan proyek
- MySQL
- Composer
- Node.js dan npm
- Git
- GitLab

### Pembaruan otomatis dashboard absensi

Dashboard admin, siswa, guru piket, dan orang tua memeriksa endpoint `GET /attendance/updates` setiap 30 detik. Jika revisi absensi yang berada dalam cakupan akun berubah, browser memuat ulang dashboard secara otomatis; pengguna tidak perlu menekan refresh. Tab yang sedang tidak terlihat melewati polling, dan permintaan yang masih berjalan tidak ditumpuk.

Polling dipilih sebagai opsi hosting yang paling kompatibel. Repository ini tidak memasang Laravel Reverb, konfigurasi contoh menetapkan `BROADCAST_CONNECTION=log`, dan berkas konfigurasi/deployment yang tersedia tidak membuktikan server produksi dapat menjalankan proses Reverb/WebSocket secara stabil. Kemampuan hosting aktual tetap perlu dikonfirmasi oleh administrator; polling tidak membutuhkan daemon atau koneksi WebSocket yang persisten. Interval 30 detik membatasi beban dan berarti pembaruan dapat tertunda hingga sekitar 30 detik.

Kontrak respons untuk integrasi ini bernama `attendance.gate.updated`; `data` berisi `attendance_id`, `student_id`, `date` (`Y-m-d`), `session_id`, dan `status`, disertai `revision`. Contoh:

```json
{
  "event": "attendance.gate.updated",
  "data": {
    "attendance_id": 42,
    "student_id": 7,
    "date": "2026-11-16",
    "session_id": null,
    "status": "hadir"
  },
  "revision": "sha256..."
}
```

Ini adalah kontrak snapshot HTTP polling, bukan Laravel broadcast event. Data dibatasi menurut peran: admin melihat tanggal sekolah berjalan, siswa melihat datanya sendiri, piket melihat scan yang dikaitkan ke sesi piketnya, dan orang tua melihat anak yang sedang dipilih serta diizinkan oleh policy.

Catatan absensi gerbang saat ini memiliki batas unik `student_id + date`, bukan `session_id`; proses penyimpanan juga tidak menyimpan sesi sekolah pada record gerbang. Karena itu kontrak mengirim `session_id: null` dan identitas yang dapat dipastikan adalah siswa, tanggal, serta record. Polling hanya membaca data dan tidak membuat atau mengubah absensi, sehingga tidak menambah risiko catatan ganda. Batas unik database yang sudah ada tetap menjadi perlindungan pencatatan harian.

### Kalender akademik dan libur semester

Admin mengelola kalender melalui menu Tahun Ajaran dan Semester. Nomor semester `1` berarti Ganjil dan `2` berarti Genap. Formulir semester menyediakan tanggal mulai dan akhir libur; tanggal libur disimpan pada record semester bersama `academic_year_id`, sehingga periode tidak tercampur saat berganti tahun ajaran. Jalankan `php artisan migrate` setelah pembaruan kode untuk menambahkan `holiday_start_date` dan `holiday_end_date`.

Status kalender ditampilkan pada dashboard admin, siswa, dan orang tua, serta status periode per semester pada daftar semester. `AcademicCalendarService::periodOn($date)` memilih semester yang tanggalnya sesuai dan memverifikasi bahwa tanggal itu juga berada di rentang tahun ajaran pemilik semester; service tidak menggunakan semester aktif dari tahun ajaran lain sebagai fallback. `statusOn($date)` mengutamakan semester yang sedang berjalan, lalu memeriksa periode libur yang telah ditetapkan.

Transisi kenaikan kelas hanya tersedia setelah tanggal akhir Semester Genap pada tahun ajaran aktif, dan memerlukan Semester Ganjil tahun ajaran tujuan. Tanggal/sumber kalender untuk integrasi proses kenaikan kelas tersedia melalui `AcademicCalendarService::promotionContext($currentAcademicYear, $nextAcademicYear)`. Mengganti semester tidak mengubah kelas siswa; perubahan kelas tetap dilakukan secara eksplisit melalui alur transisi tahun ajaran. Aktivasi tahun ajaran baru juga mengaktifkan Semester Ganjil milik tahun tersebut agar fitur yang masih membaca semester aktif tidak memakai semester dari tahun sebelumnya.

Periode libur hanya ditampilkan sebagai informasi kalender. Perubahan ini tidak menonaktifkan atau mengubah kebijakan pencatatan absensi saat libur, karena kebijakan tersebut belum ditentukan.

## 2. Persyaratan Sistem

Sebelum menjalankan aplikasi, pastikan perangkat sudah memiliki:

- PHP 8.3 dengan ekstensi yang dibutuhkan Laravel dan aplikasi.
- Composer.
- MySQL atau MariaDB yang kompatibel.
- Node.js dan npm.
- Git.
- Browser modern.

Pengguna Windows dapat menggunakan Laragon untuk membantu menyiapkan PHP dan MySQL. Pengguna Linux dapat memasang PHP, Composer, Node.js, dan MySQL secara terpisah.

**Penting:** Versi Laravel yang benar-benar digunakan mengikuti `composer.json` dan `composer.lock`. PHP 8.3 menjadi target lingkungan pengembangan yang disarankan untuk proyek ini, tetapi kecocokan akhirnya tetap harus mengikuti persyaratan versi Laravel dan dependensi yang terpasang.

## 3. Instalasi Proyek

### Langkah 1. Clone repository

Buka terminal dan jalankan:

```bash
git clone https://gitlab.com/abisuryoN/absensi-sekolah.git
cd absensi-sekolah
```

Perintah tersebut mengunduh proyek ke komputer lokal.

### Langkah 2. Install dependency PHP

```bash
composer install
```

Tunggu hingga Composer menyelesaikan pemasangan seluruh dependensi.

### Langkah 3. Buat file environment

Untuk Linux:

```bash
cp .env.example .env
```

Untuk Windows:

```bat
copy .env.example .env
```

File `.env` menyimpan konfigurasi lokal, termasuk koneksi database.

Jangan mengunggah file `.env` ke repository.

### Langkah 4. Generate application key

```bash
php artisan key:generate
```

Perintah tersebut membuat application key untuk konfigurasi Laravel lokal.

### Langkah 5. Siapkan database MySQL

Buat database baru melalui phpMyAdmin, MySQL client, atau perangkat pengelola database yang tersedia.

Contoh:

```sql
CREATE DATABASE absensi_sekolah;
```

Nama tersebut hanya contoh. Nama database yang digunakan aplikasi ditentukan melalui konfigurasi `DB_DATABASE` pada file `.env`.

Lanjutkan ke bagian konfigurasi database sebelum menjalankan migration.

## 4. Konfigurasi Database

Buka file `.env` pada folder utama proyek.

Sesuaikan konfigurasi berikut dengan MySQL di komputer masing-masing:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=absensi_sekolah
DB_USERNAME=root
DB_PASSWORD=
```

Keterangan:

| Konfigurasi | Fungsi |
|---|---|
| `DB_CONNECTION` | Jenis database yang digunakan |
| `DB_HOST` | Alamat server database |
| `DB_PORT` | Port MySQL |
| `DB_DATABASE` | Nama database aplikasi |
| `DB_USERNAME` | Username MySQL |
| `DB_PASSWORD` | Password MySQL |

Jika nama database lokal berbeda, ubah nilai `DB_DATABASE` sesuai database yang sudah dibuat.

Jika MySQL menggunakan password, isi `DB_PASSWORD` dengan password lokal yang benar.

Setelah menyimpan perubahan `.env`, jalankan:

```bash
php artisan config:clear
```

Perintah ini membersihkan cache konfigurasi agar Laravel membaca konfigurasi terbaru.

## 5. Migration dan Seeder

Migration membuat struktur tabel database. Seeder memasukkan data awal, termasuk akun pengujian yang didaftarkan oleh seeder proyek.

### A. Menjalankan migration dan seeder sekaligus

Untuk database development baru yang masih kosong, jalankan:

```bash
php artisan migrate --seed
```

Perintah tersebut menjalankan migration yang belum diterapkan, lalu menjalankan seeder utama yang terdaftar pada `DatabaseSeeder`.

Akun login di bagian berikutnya hanya tersedia jika seeder yang membuat akun tersebut memang berhasil dijalankan.

### B. Menjalankan seeder saja

Jika struktur tabel sudah tersedia dan hanya ingin memasukkan data seeder:

```bash
php artisan db:seed
```

### C. Menjalankan seeder tertentu

```bash
php artisan db:seed --class=NamaSeeder
```

Ganti `NamaSeeder` dengan nama class seeder yang benar-benar tersedia di folder `database/seeders`.

### D. Memeriksa konfigurasi database

Untuk memastikan konfigurasi environment terbaca:

```bash
php artisan config:clear
php artisan migrate:status
```

Perintah `migrate:status` menampilkan status migration yang sudah dan belum dijalankan.

**Peringatan:** Jangan menjalankan `php artisan migrate:fresh --seed` pada database yang berisi data penting. Perintah tersebut menghapus seluruh tabel sebelum menjalankan migration kembali.

## 6. Akun Login Seeder

Akun berikut ditujukan untuk pengembangan dan pengujian lokal.

**Password seluruh akun demo:**

```text
password123
```

### A. Super Admin (`super_admin`)

| Email | Nama |
|---|---|
| `superadmin1@sman1tajurhalang.sch.id` | Kepala Sekolah SMAN 1 Tajurhalang |
| `superadmin2@sman1tajurhalang.sch.id` | Operator TU SMAN 1 Tajurhalang |
| `admin@sman1tajurhalang.sch.id` | Admin SMAN 1 Tajurhalang |

Jumlah akun: 3.

### B. Guru (`guru`)

Seeder menyediakan 28 akun guru.

Format email:

```text
guru.[nama.slug]@sman1tajurhalang.sch.id
```

Contoh akun:

| Email | Nama |
|---|---|
| `guru.budi.santoso@sman1tajurhalang.sch.id` | Budi Santoso, S.Pd. |
| `guru.siti.rahayu@sman1tajurhalang.sch.id` | Siti Rahayu, S.Pd. |
| `guru.ahmad.fauzi@sman1tajurhalang.sch.id` | Ahmad Fauzi, M.Pd. |
| `guru.dewi.kusuma@sman1tajurhalang.sch.id` | Dewi Kusuma, S.Pd. |

Terdapat 24 akun guru lainnya dengan pola email yang sama.

Jumlah akun: 28.

### C. Guru Piket (`guru_piket`)

| Email | Nama |
|---|---|
| `piket@sman1tajurhalang.sch.id` | Guru Piket SMAN 1 Tajurhalang |

Akun ini digunakan bersama untuk keperluan pengujian guru piket.

Jumlah akun: 1.

### D. Orang Tua (`parent`)

Seeder menyediakan 494 akun orang tua.

Format email:

```text
ortu.[nama.slug]@gmail.com
```

Nama dan email dibuat secara otomatis menggunakan Faker. Nilai aktual mengikuti hasil seeder.

Jumlah akun: 494.

### E. Siswa (`siswa`)

Seeder menyediakan 548 akun siswa.

Format email:

```text
siswa.[NIS]@sman1tajurhalang.sch.id
```

NIS dibuat secara otomatis berdasarkan data kelas yang digunakan oleh seeder.

Jumlah akun: 548.

### Quick Login

Untuk pengujian awal, gunakan akun berikut:

| Keterangan | Nilai |
|---|---|
| Role | Super Admin |
| Email | `superadmin1@sman1tajurhalang.sch.id` |
| Password | `password123` |

Jika login gagal, pastikan migration dan seeder sudah dijalankan, lalu periksa apakah akun tersebut benar-benar terdapat di database lokal.

**Catatan keamanan:** Password di atas hanya untuk pengembangan dan pengujian lokal. Jangan menggunakan password tersebut untuk akun produksi atau akun yang dapat diakses publik.

## 7. Menjalankan Aplikasi

### Langkah 1. Build frontend

```bash
npm install
npm run build
```

### Langkah 2. Jalankan server Laravel

```bash
php artisan serve
```

### Langkah 3. Buka aplikasi

Akses alamat berikut di browser:

http://127.0.0.1:8000

Biarkan terminal server tetap berjalan selama aplikasi digunakan.

## 8. Alur Kolaborasi GitLab

Repository utama:

https://gitlab.com/abisuryoN/absensi-sekolah

### Aturan utama

1. Branch `main` adalah branch utama.
2. Jangan mengerjakan fitur secara langsung pada `main`.
3. Setiap anggota membuat branch sendiri.
4. Perubahan dikirim melalui `git push` ke branch masing-masing.
5. Anggota mengajukan Merge Request menuju `main`.
6. Pemilik repository meninjau dan menggabungkan perubahan setelah dinyatakan aman.
7. Jangan melakukan force push ke `main`.
8. Jangan mengunggah file `.env`, token, password, atau data rahasia.

### Alur perubahan

```text
main
  |
  +-- feature/absensi
  |
  +-- feature/laporan
  |
  +-- fix/perbaikan-login
```

Setiap branch fitur diajukan melalui Merge Request menuju `main`.

**Push bukan berarti perubahan otomatis masuk ke `main`.** Perubahan baru masuk ke branch utama setelah Merge Request berhasil di-merge.

## 9. Panduan untuk Anggota Tim

### A. Clone repository

Untuk anggota yang belum mengunduh proyek:

```bash
git clone https://gitlab.com/abisuryoN/absensi-sekolah.git
cd absensi-sekolah
```

Selesaikan instalasi lokal sesuai bagian 3 sampai 7.

### B. Perbarui branch utama

Sebelum memulai tugas baru:

```bash
git switch main
git pull origin main
```

Pada repository hasil clone, `origin` biasanya menunjuk ke repository GitLab tersebut.

### C. Buat branch sendiri

Contoh untuk fitur laporan:

```bash
git switch -c feature/laporan
```

Contoh nama branch:

- `feature/absensi`
- `feature/kelola-siswa`
- `feature/laporan`
- `fix/perbaikan-login`

Gunakan nama branch yang menjelaskan pekerjaan.

### D. Kerjakan perubahan dan buat commit

Periksa file yang berubah:

```bash
git status
```

Tambahkan perubahan dan buat commit:

```bash
git add .
git commit -m "Menambahkan fitur laporan"
```

Gunakan pesan commit yang menjelaskan perubahan sebenarnya.

### E. Push branch ke GitLab

```bash
git push -u origin feature/laporan
```

Ganti nama branch sesuai pekerjaan yang sedang dibuat.

### F. Ajukan Merge Request

1. Buka repository GitLab.
2. Masuk ke menu Merge Requests.
3. Pilih New merge request.
4. Pilih source branch milik sendiri, misalnya `feature/laporan`.
5. Pilih target branch `main`.
6. Jelaskan perubahan dan pengujian yang sudah dilakukan.
7. Kirim Merge Request untuk ditinjau pemilik repository.

Tunggu hingga perubahan diperiksa dan di-merge sebelum menganggap pekerjaan sudah masuk ke `main`.

### G. Jika ada perubahan baru pada `main`

Sebelum memulai tugas baru, kembali ke `main` dan tarik perubahan terbaru:

```bash
git switch main
git pull origin main
```

Setelah itu, buat branch baru untuk pekerjaan berikutnya.

Jika sedang mengerjakan branch fitur yang sudah berjalan, koordinasikan cara mengambil perubahan terbaru agar tidak menimpa pekerjaan yang belum di-commit.

## 10. Panduan untuk Pemilik Repository

Pemilik repository bertanggung jawab memeriksa Merge Request dan menjaga branch `main` tetap stabil.

### A. Tinjau Merge Request

Periksa apakah:

- Perubahan sesuai dengan tugas.
- Fitur terkait sudah diuji.
- Tidak ada kredensial rahasia yang ikut terkirim.
- Tidak ada konflik yang belum diselesaikan.
- Perubahan tidak merusak fitur yang sudah ada.

### B. Gabungkan perubahan

Jika perubahan sudah sesuai, lakukan merge melalui GitLab.

### C. Ambil perubahan terbaru ke komputer pemilik

```bash
git switch main
git pull origin main
```

Jika remote yang digunakan bernama `gitlab`, gunakan:

```bash
git switch main
git pull gitlab main
```

Gunakan nama remote yang benar sesuai hasil `git remote -v`.

### D. Menguji branch anggota sebelum merge

Untuk mengambil branch anggota yang belum digabungkan:

```bash
git fetch origin
git switch --track origin/feature/laporan
```

Sesuaikan nama branch dengan branch yang hendak diperiksa.

Setelah selesai melakukan pengujian, kembali ke branch utama:

```bash
git switch main
git pull origin main
```

Pastikan pekerjaan yang belum di-commit sudah diamankan sebelum berpindah branch.

## 11. Mengatasi Masalah Umum

### A. Composer gagal

Jalankan:

```bash
composer install
```

Pastikan versi PHP dan ekstensi yang dibutuhkan tersedia:

```bash
php -v
composer --version
```

### B. Database tidak dapat terhubung

Periksa konfigurasi berikut di `.env`:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=absensi_sekolah
DB_USERNAME=root
DB_PASSWORD=
```

Pastikan MySQL aktif, database sudah dibuat, dan kredensial sesuai.

Setelah perubahan konfigurasi:

```bash
php artisan config:clear
```

### C. Tabel belum tersedia

Jalankan:

```bash
php artisan migrate --seed
```

Gunakan hanya pada database development yang sudah dikonfigurasi dengan benar.

### D. Akun login tidak ditemukan

Jalankan seeder:

```bash
php artisan db:seed
```

Jika masih gagal, periksa `DatabaseSeeder.php`, seeder akun, dan isi tabel pengguna.

### E. Konflik saat pull atau merge

Periksa status:

```bash
git status
```

Baca pesan konflik, selesaikan konflik pada file terkait, lalu tandai file yang sudah diselesaikan:

```bash
git add nama-file
```

Selesaikan merge sesuai kondisi repository. Jangan menggunakan `git reset --hard` atau `git push --force` secara sembarangan.

## 12. Aturan Keamanan

- Jangan mengunggah file `.env`.
- Jangan membagikan Personal Access Token GitLab.
- Gunakan akun GitLab masing-masing.
- Gunakan password demo hanya pada lingkungan lokal.
- Jangan memasukkan data pribadi siswa atau orang tua ke commit pengujian.
- Jangan menjalankan migration destruktif pada database penting.
- Gunakan Merge Request untuk pemeriksaan perubahan sebelum masuk ke `main`.

---

## Ringkasan Alur Kerja

**Anggota tim:**

Clone → instalasi → buat branch → coding → commit → push → Merge Request.

**Pemilik repository:**

Review → pengujian → merge ke `main` → pull perubahan terbaru ke komputer lokal.

**Repository:**  
https://gitlab.com/abisuryoN/absensi-sekolah
