<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GaransiExport;
use App\Models\Concerns\OwnedByEmployee;
use App\Models\Concerns\LatestFirst;

class Garansi extends Model
{
    use OwnedByEmployee, LatestFirst;

    protected $fillable = [
        'no_garansi',
        'company_id',
        'customer_categories_id',
        'employee_id',
        'customer_id',
        'department_id',
        'address',
        'phone',
        'products',
        'purchase_date',
        'claim_date',
        'reason',
        'note',
        'image',
        'status_pengajuan',
        'status_product',
        'status_garansi',
        'rejection_comment',
        'rejected_by',
        'sold_out_comment',
        'sold_out_by',
        'on_hold_comment',
        'on_hold_until',
        'on_hold_by',
        'cancelled_comment',
        'cancelled_by',
        'delivery_images',
        'delivered_at',
        'delivered_by',
        'garansi_file',
        'garansi_excel',
    ];

    protected $casts = [
        'company_id'             => 'integer',
        'customer_id'            => 'integer',
        'employee_id'            => 'integer',
        'department_id'          => 'integer',
        'customer_categories_id' => 'integer',
        'address'                => 'array',
        'products'               => 'array',
        'image'                  => 'array',
        'delivery_images'        => 'array',
        'purchase_date'          => 'date',
        'claim_date'             => 'date',
        'delivered_at'           => 'datetime',
        'on_hold_until'          => 'datetime',
    ];

    # >>>>>>>>>> TAMBAHKAN INI <<<<<<<<<<
    protected $appends = [
        'address_text', 
        'image_url',               // foto barang (1st)
        'delivery_image_url',      // bukti pengiriman (1st)
        'delivery_images_urls',    // semua bukti pengiriman (array url)
    ];

    protected static function booted()
    {
        static::creating(function (Garansi $garansi) {
            if (blank($garansi->no_garansi)) {
                $garansi->no_garansi = 'GAR-' . now()->format('Ymd') . strtoupper(Str::random(4));
            }
            $garansi->status_pengajuan ??= 'pending';
            $garansi->status_product   ??= 'pending';
            $garansi->status_garansi   ??= 'pending';

            self::normalizeProductColors($garansi);
        });

        static::saving(function (Garansi $garansi) {
            self::consumeImageArray($garansi, 'image', 'garansi-photos');
            self::consumeImageArray($garansi, 'delivery_images', 'garansi-delivery-photos');
            self::normalizeProductColors($garansi);

            if ($garansi->status_pengajuan === 'rejected') {
                $garansi->status_product = 'rejected';
                $garansi->status_garansi = 'rejected';
            }
        });

        static::saved(function (Garansi $garansi) {
            $html = view('invoices.garansi', compact('garansi'))->render();
            $pdf  = Pdf::loadHtml($html)->setPaper('a4', 'portrait');

            $pdfFileName = "Garansi-{$garansi->no_garansi}.pdf";
            Storage::disk('public')->put($pdfFileName, $pdf->output());
            $garansi->updateQuietly(['garansi_file' => $pdfFileName]);

            $excelFileName = "Garansi-{$garansi->no_garansi}.xlsx";
            Excel::store(new GaransiExport($garansi), $excelFileName, 'public');
            $garansi->updateQuietly(['garansi_excel' => $excelFileName]);
        });
    }

    // ================= ACCESSORS URL ABSOLUT =================
    protected function makeUrl(?string $path): ?string
    {
        if (!$path) return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return Storage::disk('public')->url($path);
    }

    public function getImageUrlAttribute(): ?string
    {
        $img = $this->image;
        if (is_array($img) && !empty($img)) return $this->makeUrl($img[0]);
        if (is_string($img) && $img !== '')  return $this->makeUrl($img);
        return null;
    }

    public function getDeliveryImageUrlAttribute(): ?string
    {
        $imgs = $this->delivery_images;
        if (is_array($imgs) && !empty($imgs)) return $this->makeUrl($imgs[0]);
        return null;
    }

    public function getDeliveryImagesUrlsAttribute(): array
    {
        $imgs = $this->delivery_images;
        if (!is_array($imgs) || empty($imgs)) return [];
        return array_values(array_filter(array_map(fn($p) => $this->makeUrl($p), $imgs)));
    }

    // ================= NORMALISASI PRODUK =================
    protected static function normalizeProductColors(Garansi $garansi): void
    {
        $items = $garansi->products;

        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }
        if (!is_array($items)) {
            $items = [];
        }

        foreach ($items as &$it) {
            $pid = $it['produk_id'] ?? null;
            if (!$pid) continue;

            $product = Product::find($pid);
            if (!$product) continue;

            if (array_key_exists('warna_id', $it) && is_numeric($it['warna_id'])) {
                $idx    = (int) $it['warna_id'];
                $colors = $product->colors ?? [];
                if (isset($colors[$idx])) {
                    $it['warna_id'] = $colors[$idx];
                }
            }
        }

        $garansi->products = $items;
    }

    // ================= IMAGE HELPERS =================
    protected static function consumeImageArray(Garansi $garansi, string $field, string $folder): void
    {
        $imgs = $garansi->$field ?? [];
        if (is_string($imgs)) {
            $imgs = json_decode($imgs, true) ?: [];
        }
        if (!is_array($imgs)) return;

        $saved = [];
        foreach ($imgs as $img) {
            if (is_string($img) && preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,/', $img, $m)) {
                $ext  = strtolower($m[1] ?? 'png');
                $data = substr($img, strpos($img, ',') + 1);
                $bin  = base64_decode($data, true);
                if ($bin === false) continue;

                $name = $folder . '/' . now()->format('Ymd_His') . '_' . Str::random(8) . '.' . $ext;
                Storage::disk('public')->put($name, $bin);
                $saved[] = $name;
            } elseif (is_string($img)) {
                $saved[] = $img;
            }
        }

        $garansi->$field = $saved;
    }

    // ================= RELASI & HELPERS LAIN =================
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function customerCategory(): BelongsTo { return $this->belongsTo(CustomerCategories::class, 'customer_categories_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class, 'department_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function deliveredBy(): BelongsTo { return $this->belongsTo(Employee::class, 'delivered_by'); }

    public function rejectedBy(): BelongsTo { return $this->belongsTo(Employee::class, 'rejected_by'); }
    public function onHoldBy(): BelongsTo { return $this->belongsTo(Employee::class, 'on_hold_by'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(Employee::class, 'cancelled_by'); }

    public function productsWithDetails(): array
    {
        $raw = $this->products;
        if (is_string($raw)) $raw = json_decode($raw, true) ?: [];
        elseif (!is_array($raw)) $raw = [];

        return array_map(function ($item) {
            $product = Product::find($item['produk_id'] ?? null);
            return [
                'brand_name'    => $product?->brand?->name ?? '(Brand hilang)',
                'category_name' => $product?->category?->name ?? '(Kategori hilang)',
                'product_name'  => $product?->name ?? '(Produk hilang)',
                'color'         => $item['warna_id'] ?? '-',
                'quantity'      => $item['quantity'] ?? 0,
            ];
        }, $raw);
    }

    public function getProductsDetailsAttribute(): string
    {
        $items = $this->productsWithDetails();
        if (empty($items)) return '';
        return collect($items)->map(fn ($i) =>
            "{$i['brand_name']} – {$i['category_name']} – {$i['product_name']} – {$i['color']} – Qty: {$i['quantity']}"
        )->implode('<br>');
    }
    
    public function getAddressTextAttribute(): ?string
    {
        // ambil nilai mentah dari DB (belum di-cast)
        $raw = $this->getAttributes()['address'] ?? null;
        $value = $this->address; // sudah di-cast (kalau bisa)

        // 1) Kalau raw string dan bukan JSON => langsung balikin sebagai teks lama
        if (is_string($raw)) {
            $trim = trim($raw);

            // coba decode JSON
            $decoded = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                // bukan JSON, berarti address versi lama (string polos)
                return $trim !== '' ? $trim : null;
            }
        }

        // 2) Di titik ini kita berharap $value = array dari JSON (frontend)
        if (!is_array($value) || empty($value)) {
            return null;
        }

        // dukung 2 bentuk:
        //  - [ { ... } ]
        //  - { ... }
        $first = $value[0] ?? $value;
        if (!is_array($first)) {
            return null;
        }

        // kalau dari Flutter: detail_alamat berisi string full alamat => pakai itu saja
        if (!empty($first['detail_alamat'])) {
            return $first['detail_alamat'];
        }

        // kalau suatu saat kamu kirim struktur lengkap, ini tetap jalan
        $kel  = $first['kelurahan']['name'] ?? $first['kelurahan_name'] ?? $first['kelurahan'] ?? null;
        $kec  = $first['kecamatan']['name'] ?? $first['kecamatan_name'] ?? $first['kecamatan'] ?? null;
        $kab  = $first['kota_kab']['name'] ?? $first['kota_kab_name'] ?? $first['kota_kab'] ?? null;
        $prov = $first['provinsi']['name'] ?? $first['provinsi_name'] ?? $first['provinsi'] ?? null;
        $kode = $first['kode_pos'] ?? null;

        $parts = array_filter([
            $kel,
            $kec,
            $kab,
            $prov,
            $kode,
        ], fn ($v) => filled($v) && $v !== '-');

        return empty($parts) ? null : implode(', ', $parts);
    }
}
