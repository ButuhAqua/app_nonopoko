<?php

namespace App\Filament\Admin\Resources\GaransiResource\Api\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use App\Models\Product;
use Laravolt\Indonesia\Models\Provinsi;
use Laravolt\Indonesia\Models\Kabupaten;
use Laravolt\Indonesia\Models\Kecamatan;
use Laravolt\Indonesia\Models\Kelurahan;
use App\Models\PostalCode;

class GaransiTransformer extends JsonResource
{
    public function toArray($request): array
    {
        $this->resource->loadMissing([
            'department:id,name',
            'employee:id,name',
            'customer:id,name',
            'customerCategory:id,name',
        ]);

        $statusLabel = match ($this->status) {
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'pending'  => 'Pending',
            default    => ucfirst((string) $this->status),
        };

        $alamatReadable   = $this->mapAddressesReadable($this->address);
        $productsReadable = $this->mapProductsReadable($this->products);

        // 🔧 Ambil URL gambar pertama secara aman
        $imageUrl = null;
        if (is_array($this->image) && isset($this->image[0]) && is_string($this->image[0])) {
            $imageUrl = Storage::url($this->image[0]);
        } elseif (is_string($this->image) && $this->image !== '') {
            $imageUrl = Storage::url($this->image);
        }

        return [
            'id'                => $this->id,
            'no_garansi'        => $this->no_garansi,
            'department'        => $this->department?->name ?? '-',
            'employee'          => $this->employee?->name ?? '-',
            'customer'          => $this->customer?->name ?? '-',
            // 👇 kalau di Flutter kamu baca "category", lebih aman kirim key "category"
            'category'          => $this->customerCategory?->name ?? '-',
            'customer_category' => $this->customerCategory?->name ?? '-',

            'phone'             => $this->phone,
            'address_text'      => $this->addressText($alamatReadable),
            'address_detail'    => $alamatReadable,

            'purchase_date'     => optional($this->purchase_date)->format('d/m/Y'),
            'claim_date'        => optional($this->claim_date)->format('d/m/Y'),

            'reason'            => $this->reason,
            'note'              => $this->note ?: null,

            // 🔧 sebelumnya: 'image' => Storage::url($this->image)
            'image'             => $imageUrl,
            'delivery_image_url'   => $this->delivery_image_url,    // URL bukti pertama (absolute)
            'delivery_images_urls' => $this->delivery_images_urls,  // semua URL bukti (absolute array)

            // Jika tabel di Flutter butuh string “products_details”, sediakan versi join-an juga
            'products'          => $productsReadable,
            'products_details'  => collect($productsReadable)->map(function ($p) {
                $brand = $p['brand'] ?? '-';
                $cat   = $p['category'] ?? '-';
                $prod  = $p['product'] ?? '-';
                $color = $p['color'] ?? '-';
                $qty   = $p['quantity'] ?? 0;
                return "{$brand} – {$cat} – {$prod} – {$color} – Qty: {$qty}";
            })->implode("\n"),

            'status_pengajuan_raw' => $this->status_pengajuan,
            'status_product_raw'   => $this->status_product,
            'status_garansi_raw'   => $this->status_garansi,
            'status'            => $statusLabel,

            // 👇 Samakan dengan yang dibaca Flutter (g.pdfUrl)
            'pdf_url'           => $this->garansi_file ? Storage::url($this->garansi_file) : null,

            'created_at'        => optional($this->created_at)->format('d/m/Y'),
            'updated_at'        => optional($this->updated_at)->format('d/m/Y'),
        ];
    }

    /* ---------- Helpers ---------- */

    private function addressText(array $items): ?string
    {
        if (empty($items)) return null;
        return collect($items)->map(function ($a) {
            $name = fn($objOrStr) => is_array($objOrStr) ? ($objOrStr['name'] ?? null) : (is_string($objOrStr) ? $objOrStr : null);
            $parts = [
                $a['detail_alamat'] ?? null,
                $name($a['kelurahan'] ?? null),
                $name($a['kecamatan'] ?? null),
                $name($a['kota_kab'] ?? null),
                $name($a['provinsi'] ?? null),
                $a['kode_pos'] ?? null,
            ];
            // buang null, '', '-', 'null'
            $parts = array_values(array_filter($parts, fn($v) => ($t = trim((string)$v)) !== '' && $t !== '-' && strtolower($t) !== 'null'));
            return implode(', ', $parts);
        })->join(' | ');
    }

    private function mapAddressesReadable($address): array
    {
        $items = is_array($address) ? $address : json_decode($address ?? '[]', true);
        if (!is_array($items)) $items = [];

        $getCode = function(array $a, string $key) {
            // dukung: key_code, nested[key][code], legacy key (bisa code atau name)
            if (!empty($a["{$key}_code"])) return (string)$a["{$key}_code"];
            if (!empty($a[$key]['code']))  return (string)$a[$key]['code'];
            if (!empty($a[$key]) && is_string($a[$key])) {
                // heuristik: kalau string 2–10 char alfanumerik semua → anggap code
                $v = (string)$a[$key];
                return preg_match('/^[A-Za-z0-9._-]{2,10}$/', $v) ? $v : null;
            }
            return null;
        };

        $getName = function(array $a, string $key, ?string $code, string $model) {
            // urutan prioritas: flat *_name → nested[name] → lookup by code → legacy string
            if (!empty($a["{$key}_name"])) return (string)$a["{$key}_name"];
            if (!empty($a[$key]['name']))  return (string)$a[$key]['name'];
            if ($code) return $this->nameFromCode($model, $code);
            if (!empty($a[$key]) && is_string($a[$key])) {
                // kalau legacy string bukan code (heuristik gagal), pakai sebagai name
                $v = (string)$a[$key];
                if (!preg_match('/^[A-Za-z0-9._-]{2,10}$/', $v)) return $v;
            }
            return null;
        };

        return array_map(function ($a) use ($getCode, $getName) {
            $provCode = $getCode($a, 'provinsi');
            $kabCode  = $getCode($a, 'kota_kab');
            $kecCode  = $getCode($a, 'kecamatan');
            $kelCode  = $getCode($a, 'kelurahan');

            return [
                'detail_alamat' => $a['detail_alamat'] ?? null,
                'provinsi'      => ['code' => $provCode, 'name' => $getName($a, 'provinsi',  $provCode,  \Laravolt\Indonesia\Models\Provinsi::class)],
                'kota_kab'      => ['code' => $kabCode,  'name' => $getName($a, 'kota_kab',  $kabCode,   \Laravolt\Indonesia\Models\Kabupaten::class)],
                'kecamatan'     => ['code' => $kecCode,  'name' => $getName($a, 'kecamatan', $kecCode,   \Laravolt\Indonesia\Models\Kecamatan::class)],
                'kelurahan'     => ['code' => $kelCode,  'name' => $getName($a, 'kelurahan', $kelCode,   \Laravolt\Indonesia\Models\Kelurahan::class)],
                'kode_pos'      => $a['kode_pos'] ?? $this->postalByVillage($kelCode),
            ];
        }, $items);
    }

    private function nameFromCode(string $model, ?string $code): ?string
    {
        if (!$code) return null;
        return optional($model::where('code', $code)->first())->name;
    }

    private function postalByVillage(?string $villageCode): ?string
    {
        if (!$villageCode) return null;
        return optional(PostalCode::where('village_code', $villageCode)->first())->postal_code;
    }

    private function mapProductsReadable($products): array
    {
        $items = is_array($products) ? $products : json_decode($products ?? '[]', true);
        if (!is_array($items)) $items = [];

        return array_map(function ($p) {
            $product = isset($p['produk_id'])
                ? Product::with(['brand:id,name', 'category:id,name'])->find($p['produk_id'])
                : null;

            return [
                'brand'    => $product?->brand?->name ?? null,
                'category' => $product?->category?->name ?? null,
                'product'  => $product?->name ?? null,
                'color'    => $p['warna_id'] ?? null,
                'quantity' => (int)($p['quantity'] ?? 0),
            ];
        }, $items);
    }
}
