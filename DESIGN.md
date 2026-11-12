---
name: TBloom Cake
description: Artisan cake shop and custom pastry studio in Indramayu
colors:
  primary: "#726251"
  primary-hover: "#562030"
  secondary: "#251306"
  accent: "#512323"
  accent-rose: "#6B283B"
  blush: "#F7E3DF"
  latte: "#C3B59C"
  cocoa: "#442818"
  canvas: "#FAF5EF"
  paper: "#FFFCF8"
  ink: "#38271F"
  muted: "#6F594C"
  line: "#D6C6B9"
  status-error: "#A33328"
typography:
  display:
    fontFamily: "Lufga, Rubik, ui-sans-serif, system-ui, sans-serif"
    fontSize: "3.5rem"
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "Lufga, Rubik, ui-sans-serif, system-ui, sans-serif"
    fontSize: "2.25rem"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.01em"
  title:
    fontFamily: "Lufga, Rubik, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: 1.35
  body:
    fontFamily: "Rubik, Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.6
  label:
    fontFamily: "Rubik, Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "0.2em"
rounded:
  sm: "4px"
  md: "8px"
  lg: "16px"
  full: "9999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
  2xl: "48px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.paper}"
    rounded: "{rounded.full}"
    padding: "14px 28px"
  button-primary-hover:
    backgroundColor: "{colors.secondary}"
    textColor: "{colors.paper}"
  button-secondary:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.secondary}"
    rounded: "{rounded.full}"
    padding: "14px 28px"
  card-product:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    rounded: "{rounded.lg}"
    padding: "20px"
---

# Design System: TBloom Cake

## Overview

**Creative North Star: "The Artisanal Patisserie Atelier"**

Sistem desain TBloom Cake memancarkan kehangatan, keanggunan artisanal, dan sentuhan playful dari sebuah studio kue modern di Indramayu. Antarmuka dirancang seperti etalase pastry berkelas: latar kanvas hangat bernuansa linen, kartu display bertekstur kertas lembut (*warm paper*), dan tipografi display ramah yang mengalir seperti piping cokelat pada kue tart.

Hierarki visual memprioritaskan fotografi cake autentik di atas elemen dekoratif buatan. Warna-warna brand mengakar kuat pada palet kuliner alami—espresso pekat, warm taupe, biskuit mentega, dan blush stroberi krim—menciptakan suasana hangat (*cozy*), terpercaya, dan mengundang selera tanpa terasa kaku seperti e-commerce konvensional.

**Key Characteristics:**
- **Warm Culinary Tones:** Menghindari warna abu-abu netral dingin; seluruh latar, border, dan bayangan menggunakan turunan pigmen cokelat, krem, dan susu hangat.
- **Tactile Soft Forms:** Menggunakan kombinasi rounded-full untuk tombol aksi dan radius lengkung lembut (8px–16px) pada kartu produk yang nyaman disentuh.
- **Authentic Cake-First Focus:** Antarmuka mundur menjadi kanvas elegan agar tekstur, warna, dan keindahan kue pelanggan menjadi pusat perhatian utama.
- **Clarity & Restraint:** Informasi harga, kuota harian, dan panduan serah-terima disajikan dengan keterbacaan tinggi tanpa clutter visual.

## Colors

Palet warna diturunkan langsung dari identitas resmi TBloom Cake (swatch HEX `#251306`, `#512323`, `#442818`, `#726251`, `#C3B59C`, `#F7E3DF`) yang dipadukan dengan permukaan semantik bernuansa hangat.

### Primary
- **Warm Taupe** (`#726251`): Warna tindakan utama untuk tombol primer, badge aksen, dan link navigasi aktif. Mewakili kehangatan dan stabilitas artisanal.

### Secondary
- **Dark Espresso** (`#251306`): Warna judul utama, header hero background, dan state hover tombol primer. Menegaskan identitas logo TBloom yang berkarakter kuat.

### Tertiary
- **Deep Wine / Burgundy** (`#512323`): Warna aksen untuk highlight khusus, badge promosi, dan status pemesanan mendalam.
- **Rose Accent** (`#6B283B`): Warna kategori kue dan highlight interaktif halus pada tautan teks.
- **Sweet Blush** (`#F7E3DF`): Warna kontainer pendukung, badge diskon, hover background baris menu, dan latar placeholder gambar.

### Neutral
- **Canvas** (`#FAF5EF`): Latar belakang halaman default yang hangat, tidak menyilaukan mata, dan memberikan kontras lembut terhadap kartu produk.
- **Paper Surface** (`#FFFCF8`): Permukaan putih gading untuk kartu produk, modal, container form, dan dropdown navigation.
- **Ink** (`#38271F`): Warna teks paragraf utama dengan rasio kontras tinggi di atas Canvas dan Paper.
- **Muted** (`#6F594C`): Warna teks sekunder, petunjuk formulir, dan metadata produk (seperti unit jual dan breadcrumb).
- **Line** (`#D6C6B9`): Garis batas halus untuk pemisah seksi, border kartu, dan outline form.
- **Latte Beige** (`#C3B59C`): Warna aksen garis sekunder dan border pill menu mobile.

### Named Rules
**The Warm Pigment Rule.** Tidak ada warna abu-abu monokromatik murni (`#000000`, `#808080`, atau `#E5E7EB` default Tailwind) yang digunakan pada antarmuka. Setiap teks, garis batas, dan bayangan wajib memiliki rona hangat berbasis turunan espresso (`#38271F`) atau latte (`#D6C6B9`).

**The 10% Accent Rule.** Warna merah anggur (`#512323` dan `#6B283B`) hanya digunakan pada maksimal 10% luas layar sebagai penunjuk kategori dan titik fokus, menjaga dominasi kehangatan taupe-espresso.

## Typography

Sistem tipografi memadukan font display berkarakter modern dan bersahabat (`Lufga`) dengan font teks berkejelasan tinggi (`Rubik` dan `Inter`).

**Display Font:** Lufga (fallback: Rubik, ui-sans-serif, system-ui, sans-serif)  
**Body Font:** Rubik / Inter (fallback: ui-sans-serif, system-ui, sans-serif)  
**Label/Badge Font:** Rubik (uppercase tracking luas)

**Character:** Tipografi display menghadirkan kesan artistik dan manis seperti lekukan krim pastry, sementara body font menjamin keterbacaan instruksi pemesanan dan syarat pickup.

### Hierarchy
- **Display** (Bold 700, 48px–64px / `3rem–4rem`, line-height `1.15`, tracking `-0.02em`): Digunakan untuk headline utama hero banner beranda.
- **Headline** (SemiBold 600, 32px–40px / `2rem–2.5rem`, line-height `1.2`, tracking `-0.01em`): Digunakan untuk judul seksi produk (`Simple Cake`, `Custom Cake`) dan judul halaman utama.
- **Title** (SemiBold 600, 20px–24px / `1.25rem–1.5rem`, line-height `1.35`): Digunakan untuk nama produk pada kartu katalog dan judul card checkout.
- **Body** (Regular 400 & Medium 500, 15px–16px / `0.9375rem–1rem`, line-height `1.6`): Digunakan untuk deskripsi produk, paragraf pengantar, dan teks formulir. Maksimal panjang baris 65–75ch.
- **Label** (SemiBold 600, 11px–12px / `0.6875rem–0.75rem`, letter-spacing `0.2em`, uppercase): Digunakan untuk kategori produk di atas judul (`BENTO CAKE`, `CUPCAKE`) dan status badge.

### Named Rules
**The Editorial Title Rule.** Nama produk dan judul seksi katalog wajib menggunakan sentence case berbobot semi-bold dengan font display, tidak boleh ditulis kapital semua (ALL CAPS) agar tetap terkesan ramah dan elegan.

## Layout

Model tata letak TBloom Cake dibangun di atas container terpusat dengan grid berbasis kelipatan 8px:
- **Max Container:** `1440px` untuk layar desktop penuh, dengan padding horizontal responsif: `px-5` (mobile, 20px), `sm:px-8` (tablet, 32px), dan `xl:px-16` / `xl:px-20` (desktop, 64px–80px).
- **Grid Katalog:** 1 kolom di mobile (<640px), 2 kolom di tablet (640px–1024px), dan 3–4 kolom di desktop (>1024px) dengan gap seragam `24px` hingga `32px`.
- **Feature Split (Z-pattern):** Layout selang-seling antara teks cerita produk dan gambar display kue (rasio aspek 16:10 atau 4:3) dengan batas pemisah garis lembut `#D6C6B9`.

## Elevation & Depth

Sistem antarmuka menerapkan **Tonal Layering with Warm Ambient Shadows**. Kedalaman tercipta dari perbedaan elevasi permukaan (Canvas `#FAF5EF` sebagai dasar, Paper `#FFFCF8` sebagai kartu terangkat) yang dipertegas oleh bayangan ber-tint pigmen espresso alami.

### Shadow Vocabulary
- **Warm SM** (`box-shadow: 0 1px 2px 0 rgba(56, 39, 31, 0.08)`): Digunakan untuk kartu produk pada posisi diam (*resting state*) dan container filter.
- **Warm MD** (`box-shadow: 0 4px 8px 0 rgba(56, 39, 31, 0.10)`): Digunakan saat kartu produk di-hover atau tombol ditekan.
- **Warm LG / Popover** (`box-shadow: 0 10px 15px -3px rgba(56, 39, 31, 0.10), 0 4px 6px -4px rgba(56, 39, 31, 0.10)`): Digunakan untuk dropdown menu mobile, modal dialog, dan popover konfirmasi.

### Named Rules
**The Ambient Tint Rule.** Tidak diperkenankan menggunakan bayangan hitam murni `rgba(0,0,0,x)`. Seluruh bayangan kartu dan tombol wajib memakai tint pigmen espresso hangat `rgba(56, 39, 31, x)` agar menyatu dengan warna kue dan kayu etalase.

## Shapes

Bahasa bentuk TBloom Cake mengadopsi **Soft Pill & Tactile Radii**:
- **Full Pill (`9999px`):** Khusus digunakan untuk seluruh tombol aksi utama (CTA), input pill, badge kategori, dan chip status ketersediaan kuota.
- **Card Radius (`16px` / `rounded-2xl`):** Digunakan untuk kontainer gambar produk, kartu varian harga, dan kotak detail pesanan.
- **Sub-element Radius (`8px` / `rounded-lg`):** Digunakan untuk thumbnail galeri foto kecil dan tombol sekunder kompak.
- **Border Treatment:** Selalu menggunakan border tipis `1px solid #D6C6B9` atau `#E5D8CC` untuk memberikan ketegasan struktural tanpa terlihat berat.

## Components

### Buttons
- **Shape:** Pill penuh (`border-radius: 9999px`) untuk tombol utama; radius sedang (`8px`) untuk tombol tindakan di dalam tabel/staf.
- **Primary:** Background Warm Taupe (`#726251`), teks Paper (`#FFFFFF` / `#FFFCF8`), padding `14px 28px` (desktop: min-height 52px).
- **Hover / Focus:** Transisi warna background ke Dark Espresso (`#251306`) dalam 200ms ease. Focus visible menampilkan outline 2px dengan offset 4px warna `#726251`.
- **Secondary / Outline:** Background transparan atau Paper, border `1px solid #D6C6B9`, teks Dark Espresso (`#251306`), hover ke `#FAF5EF`.

### Cards / Containers
- **Corner Style:** `rounded-2xl` (`16px`).
- **Background:** Paper Surface (`#FFFCF8`).
- **Border:** `1px solid #D6C6B9`.
- **Shadow:** Warm SM saat diam, bertransisi halus ke Warm MD saat hover.
- **Internal Padding:** `20px` (mobile: `16px`).

### Inputs / Form Fields
- **Style:** Pill penuh (`rounded-full`) dengan tinggi minimal `52px`–`56px`, background Canvas (`#FAF5EF`), border `1px solid #6F594C` atau `#D6C6B9`.
- **Floating Pill Label:** Label form bertengger di atas garis border dengan latar belakang Canvas untuk menjaga keterbacaan.
- **Focus:** Border beralih ke Ink (`#38271F`) dengan ring fokus `rgba(114, 98, 81, 0.3)`.
- **Error State:** Border merah pekat (`#A33328`), teks error merah dengan ikon peringatan terpadu.

### Navigation
- **Desktop:** Header sticky dengan latar Canvas semi-transparan (`#FAF5EF/95 backdrop-blur-sm`), garis border bawah `#D6C6B9`, logo TBloom Cake di sisi kiri, menu link di tengah dengan hover ke Rose Accent (`#7B3547`), dan tombol CTA pill di sisi kanan.
- **Mobile:** Tombol pill menu dengan border `#C3B59C`, membuka popover dropdown melengkung (`rounded-2xl`) berbayangan Warm LG.

### Signature Component: Quota Availability Badge
- **Pill Status Kuota:** Badge pill dengan status real-time ketersediaan tanggal pemesanan:
  - *Tersedia:* Background `#F7E3DF` dengan teks `#726251`, menampilkan sisa slot harian.
  - *Penuh:* Border `#C3B59C` dengan teks muted `#6F594C`, memberikan instruksi untuk memilih tanggal lain.

## Do's and Don'ts

### Do:
- **Do** gunakan foto produk cake asli beresolusi tinggi dengan pencahayaan hangat alami pada kartu katalog dan hero banner.
- **Do** selalu pasangkan warna latar Canvas (`#FAF5EF`) dengan Paper (`#FFFCF8`) untuk menciptakan hierarki kontras yang lembut.
- **Do** gunakan tombol berujung bulat penuh (`rounded-full`) untuk call-to-action pemesanan cake dan navigasi utama.
- **Do** berikan informasi kuota harian dan batas waktu pembayaran (30 menit) secara transparan pada setiap alur checkout.
- **Do** gunakan tint bayangan espresso hangat `rgba(56, 39, 31, 0.08–0.10)` alih-alih bayangan abu-abu gelap netral.

### Don't:
- **Don't** gunakan warna abu-abu netral dingin (`#F3F4F6`, `#9CA3AF`, `#111827`) pada latar, teks, atau garis batas antarmuka.
- **Don't** tampilkan produk non-cake seperti buket bunga, buket uang, florist, atau hantaran di etalase dan katalog website.
- **Don't** gunakan layout e-commerce generik yang padat tabel kaku; pertahankan kesan artisanal atelier yang lapang dan bernafas.
- **Don't** gunakan sudut kotak tajam (`rounded-none` atau radius 0px) pada tombol interaktif pelanggan.
- **Don't** gunakan teks promosi yang agresif atau pop-up banner mengganggu yang merusak pengalaman berbelanja santai.
