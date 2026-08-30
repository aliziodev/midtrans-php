# Berkontribusi

Terima kasih sudah melihat-lihat. Dokumen ini untuk orang yang mengerjakan
package-nya. Kalau Anda hanya memakainya, [README](README.md) sudah cukup.

## Menjalankan test

```bash
composer test:unit         # cepat, tanpa jaringan
composer test:integration  # memanggil sandbox Midtrans sungguhan
composer analyse           # PHPStan
composer qa                # validate + analyse + unit + audit
```

## Suite integrasi

Salin `.env.example` ke `.env` dan isi kredensial sandbox. Tanpa `.env` suite ini
di-skip, bukan gagal, jadi kontributor tanpa akun Midtrans tetap bisa bekerja.

Suite ini sengaja memanggil API yang sungguhan. Unit test membuktikan SDK
menyusun request seperti yang kita maksudkan; ia tidak membuktikan Midtrans
setuju. Tiga bug lolos ke rilis justru karena request-nya dibangun persis seperti
dokumentasi, dan dokumentasi bukan yang API lakukan: host Snap-BI yang salah, 404
berbadan kosong yang dilaporkan sebagai gagal parse, dan `convertInvoice` yang
memakai PATCH. Ketiganya hanya ketahuan lewat panggilan hidup.

### Guard sandbox

Guard-nya menegaskan **host** tujuan mengandung `sandbox`, bukan menebak dari
bentuk key — dan itu bukan kerewelan. Akun sandbox lama memakai prefiks
`SB-Mid-server-`, yang baru memakai `Mid-server-`, bentuk yang selama ini dipakai
production. Prefiks tidak lagi membedakan lingkungan; host membedakannya, dan
host yang menentukan apakah sebuah test bisa memindahkan uang sungguhan.

Jangan pernah melonggarkan guard ini agar sebuah test lewat.

### Menambah test integrasi

Utamakan alur utuh daripada satu endpoint. Membuat sesuatu lalu membacanya
kembali, membatalkannya, atau mengonversinya adalah yang membuktikan SDK
memodelkan fiturnya. Menembak endpoint satu per satu hanya membuktikan ia
menjawab — dan itulah cara `convertInvoice` lolos dengan HTTP method yang salah.

Jangan menjalankan endpoint yang mengubah pengaturan merchant. `updateSnapPreferences`
sengaja tidak punya test integrasi karena `PATCH`-nya mengubah konfigurasi Snap
akun sungguhan.

## Test transport

`CurlTransportServerTest` menjalankan `CurlTransport` terhadap server bawaan PHP
di `127.0.0.1`, karena loop retry hanya benar-benar terbukti lewat socket asli:
jumlah percobaan, `Retry-After` yang ditunggu, capture header, dan redirect yang
tidak diikuti. Konsekuensinya suite jadi ~6 detik, bukan ~0,06 detik. Kalau
sedang iterasi cepat:

```bash
vendor/bin/phpunit --testsuite unit --exclude-group transport
```

## Coverage

Coverage butuh pcov atau xdebug terpasang:

```bash
composer test:coverage
```

CI mengukurnya tiap push dan mengirim ke Codecov — butuh secret `CODECOV_TOKEN`
di repository settings. Perlu diketahui: tidak ada ambang minimum di mana pun,
jadi coverage yang turun **tidak** menggagalkan CI. Angkanya laporan, bukan
gerbang.

Coverage tinggi juga bukan jaminan. Ketiga bug di atas lolos dengan coverage
100%. Kalau Anda menyentuh jalur yang memanggil Midtrans, tambahkan test
integrasinya.

## Commit dan rilis

Rilis berjalan otomatis lewat semantic-release dari pesan commit, jadi format
Conventional Commits menentukan versi berikutnya:

| Prefiks | Efek |
| --- | --- |
| `fix:` | patch |
| `feat:` | minor |
| `feat!:` atau `BREAKING CHANGE:` di body | major |
| `docs:`, `test:`, `refactor:`, `chore:`, `ci:` | tidak merilis |

Tulis body yang menjelaskan **kenapa**, bukan mengulang diff.

## Endpoint yang belum bisa diuji

Enam belas method belum pernah dijawab sandbox karena butuh pengaktifan dari
Midtrans atau tidak punya cara pemicu — rinciannya di
[README](README.md#seberapa-jauh-package-ini-terbukti). Kalau akun Anda punya
akses yang belum kami punya, test integrasi untuk jalur itu sangat kami hargai.
