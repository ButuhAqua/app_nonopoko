<?php

namespace App\Filament\Admin\Resources\GaransiResource\Api\Handlers;

use App\Filament\Admin\Resources\GaransiResource;
use App\Models\Employee;
use App\Models\Garansi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Rupadana\ApiService\Http\Handlers;

class UpdateHandler extends Handlers
{
    public static ?string $uri = '/{id}';
    public static ?string $resource = GaransiResource::class;

    public static function getMethod()
    {
        return Handlers::PUT;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Update Garansi + upload bukti pengiriman (opsional).
     *
     * Body (opsional):
     * - delivery_image (file tunggal)
     * - delivery_images[] (multi file)
     * - field lain sesuai fillable model.
     */
    public function handler(Request $request)
    {
        $id = $request->route('id');

        /** @var Garansi|null $garansi */
        $garansi = static::getModel()::find($id);
        if (! $garansi) {
            return static::sendNotFoundResponse();
        }

        // validasi gambar
        $request->validate([
            'delivery_image'    => 'sometimes|image|max:4096',
            'delivery_images.*' => 'sometimes|image|max:4096',
            // kalau mau izinkan kirim delivered_by eksplisit dari client:
            // 'delivered_by' => 'sometimes|nullable|exists:employees,id',
        ]);

        // upload file bila ada
        $newPaths = [];

        if ($request->hasFile('delivery_images')) {
            foreach ($request->file('delivery_images') as $file) {
                $newPaths[] = $file->store('garansi-delivery-photos', 'public');
            }
        }

        if ($request->hasFile('delivery_image')) {
            $newPaths[] = $request->file('delivery_image')->store('garansi-delivery-photos', 'public');
        }

        if (!empty($newPaths)) {
            $existing = (array) ($garansi->delivery_images ?? []);
            $garansi->delivery_images = array_values(array_unique(array_merge($existing, $newPaths)));

            // status & meta delivered
            if (empty($garansi->status_garansi) || $garansi->status_garansi === 'pending') {
                $garansi->status_garansi = 'delivered';
            }
            $garansi->delivered_at = now();

            // 🔑 cari employee id dari user login (berdasarkan email)
            $deliveredById = null;
            $user = $request->user();
            if ($user && !empty($user->email)) {
                $deliveredById = Employee::where('email', $user->email)->value('id');
            }
            // fallback: jika client kirim delivered_by eksplisit dan valid
            if (!$deliveredById && $request->filled('delivered_by')) {
                $maybe = (int) $request->input('delivered_by');
                if (Employee::whereKey($maybe)->exists()) {
                    $deliveredById = $maybe;
                }
            }
            // set hanya jika ada employee yang valid (hindari error FK 1452)
            if ($deliveredById) {
                $garansi->delivered_by = $deliveredById;
            }
        }

        // update field lain (kecuali file)
        $input = $request->except(['delivery_image', 'delivery_images']);

        // normalisasi JSON string -> array
        foreach (['address', 'products', 'image', 'delivery_images'] as $k) {
            if ($request->has($k) && is_string($request->input($k))) {
                $decoded = json_decode($request->input($k), true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $input[$k] = $decoded;
                }
            }
        }

        if (!empty($input)) {
            $garansi->fill($input);
        }

        $garansi->save();

        // response dengan URL absolut
        $data = $garansi->toArray();
        $imgs = (array) ($garansi->delivery_images ?? []);
        $data['delivery_images']    = array_map(fn ($p) => Storage::disk('public')->url($p), $imgs);
        $data['delivery_image_url'] = $imgs[0] ?? null ? Storage::disk('public')->url($imgs[0]) : null;

        return response()->json([
            'message' => 'Successfully Update Resource',
            'data'    => $data,
        ]);
    }
}
