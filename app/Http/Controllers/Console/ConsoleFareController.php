<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\FareAttribute;
use App\Models\FareModifier;
use App\Models\FareRule;
use App\Models\FareZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsoleFareController extends Controller
{
    // ── Zones ─────────────────────────────────────────────────────────────────

    public function zones(): JsonResponse
    {
        $zones = DB::select("
            SELECT id, zone_id, name, agency_id, color,
                   ST_AsGeoJSON(zone_polygon) as geojson
            FROM fare_zones
            ORDER BY name
        ");

        return response()->json(array_map(fn ($z) => [
            'id'        => $z->id,
            'zone_id'   => $z->zone_id,
            'name'      => $z->name,
            'agency_id' => $z->agency_id,
            'color'     => $z->color,
            'geojson'   => $z->geojson ? json_decode($z->geojson, true) : null,
        ], $zones));
    }

    public function storeZone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'agency_id' => 'required|string|exists:agencies,agency_id',
            'color'     => 'required|string|size:6',
            'geojson'   => 'required|array',
        ]);

        $zoneId = 'ZONE_' . Str::upper(Str::random(6));

        DB::statement("
            INSERT INTO fare_zones (zone_id, name, agency_id, color, zone_polygon, created_at, updated_at)
            VALUES (?, ?, ?, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), NOW(), NOW())
        ", [$zoneId, $data['name'], $data['agency_id'], $data['color'], json_encode($data['geojson'])]);

        $zone = DB::selectOne("SELECT id, zone_id, name, agency_id, color, ST_AsGeoJSON(zone_polygon) as geojson FROM fare_zones WHERE zone_id = ?", [$zoneId]);

        return response()->json([
            'id'        => $zone->id,
            'zone_id'   => $zone->zone_id,
            'name'      => $zone->name,
            'agency_id' => $zone->agency_id,
            'color'     => $zone->color,
            'geojson'   => json_decode($zone->geojson, true),
        ], 201);
    }

    public function updateZone(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'color'   => 'sometimes|string|size:6',
            'geojson' => 'sometimes|array',
        ]);

        if (!empty($data['geojson'])) {
            DB::statement("
                UPDATE fare_zones SET name = COALESCE(?, name), color = COALESCE(?, color),
                zone_polygon = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), updated_at = NOW()
                WHERE id = ?
            ", [$data['name'] ?? null, $data['color'] ?? null, json_encode($data['geojson']), $id]);
        } else {
            DB::statement("
                UPDATE fare_zones SET name = COALESCE(?, name), color = COALESCE(?, color), updated_at = NOW()
                WHERE id = ?
            ", [$data['name'] ?? null, $data['color'] ?? null, $id]);
        }

        $zone = DB::selectOne("SELECT id, zone_id, name, agency_id, color, ST_AsGeoJSON(zone_polygon) as geojson FROM fare_zones WHERE id = ?", [$id]);

        return response()->json([
            'id'        => $zone->id,
            'zone_id'   => $zone->zone_id,
            'name'      => $zone->name,
            'agency_id' => $zone->agency_id,
            'color'     => $zone->color,
            'geojson'   => json_decode($zone->geojson, true),
        ]);
    }

    public function destroyZone(int $id): JsonResponse
    {
        FareZone::findOrFail($id)->delete();
        return response()->json(['message' => 'Zone deleted.']);
    }

    // ── Fare Attributes ───────────────────────────────────────────────────────

    public function fareAttributes(): JsonResponse
    {
        return response()->json(
            FareAttribute::with('fareRules')->orderBy('fare_id')->get()
        );
    }

    public function saveFareAttribute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fare_id'           => 'nullable|string|max:255',
            'price'             => 'required|numeric|min:0',
            'currency_type'     => 'sometimes|string|max:10',
            'payment_method'    => 'sometimes|integer|in:0,1',
            'transfers'         => 'sometimes|nullable|integer|in:0,1,2',
            'agency_id'         => 'required|string|exists:agencies,agency_id',
            'transfer_duration' => 'sometimes|nullable|integer|min:0',
        ]);

        $fareId = $data['fare_id'] ?? 'FARE_' . Str::upper(Str::random(8));

        $attr = FareAttribute::updateOrCreate(
            ['fare_id' => $fareId],
            [
                'price'             => $data['price'],
                'currency_type'     => $data['currency_type'] ?? 'KES',
                'payment_method'    => $data['payment_method'] ?? 0,
                'transfers'         => $data['transfers'] ?? null,
                'agency_id'         => $data['agency_id'],
                'transfer_duration' => $data['transfer_duration'] ?? null,
            ]
        );

        return response()->json($attr, 201);
    }

    public function deleteFareAttribute(int $id): JsonResponse
    {
        FareAttribute::findOrFail($id)->delete();
        return response()->json(['message' => 'Fare attribute deleted.']);
    }

    // ── Fare Rules ────────────────────────────────────────────────────────────

    public function fareRules(): JsonResponse
    {
        return response()->json(FareRule::orderBy('fare_id')->get());
    }

    public function saveFareRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fare_id'        => 'required|string|exists:fare_attributes,fare_id',
            'route_id'       => 'nullable|string|exists:routes,route_id',
            'origin_id'      => 'nullable|string',
            'destination_id' => 'nullable|string',
            'contains_id'    => 'nullable|string',
        ]);

        $rule = FareRule::create($data);
        return response()->json($rule, 201);
    }

    public function deleteFareRule(int $id): JsonResponse
    {
        FareRule::findOrFail($id)->delete();
        return response()->json(['message' => 'Fare rule deleted.']);
    }

    // ── Route-Based Fares ─────────────────────────────────────────────────────

    public function routeBasedFares(): JsonResponse
    {
        $rules = FareRule::whereNotNull('route_id')
            ->whereNull('origin_id')
            ->whereNull('destination_id')
            ->with('fareAttribute', 'route')
            ->orderBy('fare_id')
            ->get();

        return response()->json($rules->map(fn ($r) => [
            'id'            => $r->id,
            'fare_id'       => $r->fare_id,
            'route_id'      => $r->route_id,
            'route_name'    => $r->route?->route_short_name,
            'price'         => $r->fareAttribute?->price,
            'currency_type' => $r->fareAttribute?->currency_type,
            'payment_method'=> $r->fareAttribute?->payment_method,
            'agency_id'     => $r->fareAttribute?->agency_id,
        ]));
    }

    public function saveRouteFare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_id'       => 'required|string|exists:routes,route_id',
            'price'          => 'required|numeric|min:0',
            'currency_type'  => 'sometimes|string|max:10',
            'payment_method' => 'sometimes|integer|in:0,1',
            'agency_id'      => 'required|string|exists:agencies,agency_id',
        ]);

        $fareId = 'FARE_ROUTE_' . Str::upper($data['route_id']);

        $attr = FareAttribute::updateOrCreate(
            ['fare_id' => $fareId],
            [
                'price'          => $data['price'],
                'currency_type'  => $data['currency_type'] ?? 'KES',
                'payment_method' => $data['payment_method'] ?? 0,
                'agency_id'      => $data['agency_id'],
            ]
        );

        // Replace any existing route-level rule for this route
        FareRule::where('route_id', $data['route_id'])
            ->whereNull('origin_id')
            ->whereNull('destination_id')
            ->delete();

        $rule = FareRule::create([
            'fare_id'  => $attr->fare_id,
            'route_id' => $data['route_id'],
        ]);

        return response()->json([
            'id'            => $rule->id,
            'fare_id'       => $attr->fare_id,
            'route_id'      => $rule->route_id,
            'price'         => $attr->price,
            'currency_type' => $attr->currency_type,
            'payment_method'=> $attr->payment_method,
            'agency_id'     => $attr->agency_id,
        ], 201);
    }

    public function deleteRouteFare(int $id): JsonResponse
    {
        $rule = FareRule::findOrFail($id);
        // Also delete the attribute if it has no other rules
        $attr = FareAttribute::where('fare_id', $rule->fare_id)->first();
        $rule->delete();
        if ($attr && FareRule::where('fare_id', $attr->fare_id)->doesntExist()) {
            $attr->delete();
        }
        return response()->json(['message' => 'Route fare deleted.']);
    }

    // ── Preview ───────────────────────────────────────────────────────────────

    public function previewFare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin_zone_id'      => 'nullable|string',
            'destination_zone_id' => 'nullable|string',
            'route_id'            => 'nullable|string',
        ]);

        $originId  = $data['origin_zone_id'] ?? null;
        $destId    = $data['destination_zone_id'] ?? null;
        $routeId   = $data['route_id'] ?? null;

        $rule = null;
        $resolvedVia = null;

        // 1. Zone-to-zone (bidirectional)
        if ($originId && $destId) {
            $rule = FareRule::where('origin_id', $originId)->where('destination_id', $destId)->first()
                 ?? FareRule::where('origin_id', $destId)->where('destination_id', $originId)->first();
            if ($rule) $resolvedVia = 'zone';
        }

        // 2. Route-based
        if (!$rule && $routeId) {
            $rule = FareRule::where('route_id', $routeId)
                ->whereNull('origin_id')
                ->whereNull('destination_id')
                ->first();
            if ($rule) $resolvedVia = 'route';
        }

        // 3. Catch-all (FareAttribute with no rules at all)
        if (!$rule) {
            $attr = FareAttribute::whereDoesntHave('fareRules')->orderBy('price')->first();
            if ($attr) {
                $resolvedVia = 'flat';
                return response()->json(array_merge(
                    $this->buildPreviewResponse($attr, $resolvedVia),
                    ['found' => true]
                ));
            }
        }

        if (!$rule) {
            return response()->json(['found' => false]);
        }

        $attr = FareAttribute::where('fare_id', $rule->fare_id)->firstOrFail();

        return response()->json(array_merge(
            $this->buildPreviewResponse($attr, $resolvedVia),
            ['found' => true, 'fare_rule_id' => $rule->id]
        ));
    }

    private function buildPreviewResponse(FareAttribute $attr, ?string $resolvedVia): array
    {
        $basePrice = (float) $attr->price;

        // Fetch effective modifiers
        $now       = now();
        $modifiers = FareModifier::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now))
            ->get()
            ->filter(fn (FareModifier $m) => $this->modifierApplies($m, $attr));

        $effectivePrice = $basePrice;
        $appliedModifiers = [];

        // Apply multipliers first, then fixed surcharges
        foreach ($modifiers as $mod) {
            if ($mod->multiplier !== null) {
                $effectivePrice *= $mod->multiplier;
            }
        }
        foreach ($modifiers as $mod) {
            if ($mod->fixed_surcharge !== null) {
                $effectivePrice += $mod->fixed_surcharge;
            }
            $appliedModifiers[] = [
                'id'             => $mod->id,
                'name'           => $mod->name,
                'type'           => $mod->type,
                'multiplier'     => $mod->multiplier,
                'fixed_surcharge'=> $mod->fixed_surcharge,
            ];
        }

        return [
            'fare_id'          => $attr->fare_id,
            'base_price'       => $basePrice,
            'effective_price'  => round($effectivePrice, 2),
            'currency_type'    => $attr->currency_type,
            'payment_method'   => $attr->payment_method,
            'transfers'        => $attr->transfers,
            'resolved_via'     => $resolvedVia,
            'modifiers_applied'=> $appliedModifiers,
        ];
    }

    private function modifierApplies(FareModifier $mod, FareAttribute $attr): bool
    {
        if ($mod->applies_to === 'all') return true;
        if ($mod->applies_to === 'agency' && $mod->applies_to_id === $attr->agency_id) return true;
        // Route/zone checks require the caller to pass context — for now treat as applicable
        return false;
    }

    // ── Fare Modifiers ────────────────────────────────────────────────────────

    public function fareModifiers(): JsonResponse
    {
        return response()->json(FareModifier::orderByDesc('is_active')->orderBy('name')->get());
    }

    public function saveFareModifier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'type'            => 'required|string|in:weather,event,peak_hours,day_of_week',
            'applies_to'      => 'sometimes|string|in:all,agency,route,zone',
            'applies_to_id'   => 'nullable|string|max:255',
            'multiplier'      => 'nullable|numeric|min:0',
            'fixed_surcharge' => 'nullable|numeric',
            'condition_data'  => 'nullable|array',
            'is_active'       => 'sometimes|boolean',
            'start_at'        => 'nullable|date',
            'end_at'          => 'nullable|date|after_or_equal:start_at',
        ]);

        $data['created_by'] = Auth::id();
        $data['applies_to'] ??= 'all';

        $mod = FareModifier::create($data);
        return response()->json($mod, 201);
    }

    public function updateFareModifier(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'type'            => 'sometimes|string|in:weather,event,peak_hours,day_of_week',
            'applies_to'      => 'sometimes|string|in:all,agency,route,zone',
            'applies_to_id'   => 'nullable|string|max:255',
            'multiplier'      => 'nullable|numeric|min:0',
            'fixed_surcharge' => 'nullable|numeric',
            'condition_data'  => 'nullable|array',
            'is_active'       => 'sometimes|boolean',
            'start_at'        => 'nullable|date',
            'end_at'          => 'nullable|date',
        ]);

        $mod = FareModifier::findOrFail($id);
        $mod->update($data);

        return response()->json($mod);
    }

    public function deleteFareModifier(int $id): JsonResponse
    {
        FareModifier::findOrFail($id)->delete();
        return response()->json(['message' => 'Modifier deleted.']);
    }

    public function toggleModifier(int $id): JsonResponse
    {
        $mod = FareModifier::findOrFail($id);
        $mod->update(['is_active' => !$mod->is_active]);
        return response()->json(['is_active' => $mod->is_active]);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public function exportFareFiles(): Response
    {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fare_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $this->writeFareAttributesCsv($tmpDir);
        $this->writeFareRulesCsv($tmpDir);

        $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'fare_gtfs.zip';
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($tmpDir . DIRECTORY_SEPARATOR . 'fare_attributes.txt', 'fare_attributes.txt');
        $zip->addFile($tmpDir . DIRECTORY_SEPARATOR . 'fare_rules.txt', 'fare_rules.txt');
        $zip->close();

        $content = file_get_contents($zipPath);
        array_map('unlink', glob($tmpDir . DIRECTORY_SEPARATOR . '*'));
        rmdir($tmpDir);

        return response($content, 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="fare_gtfs.zip"',
        ]);
    }

    private function writeFareAttributesCsv(string $dir): void
    {
        $handle = fopen($dir . DIRECTORY_SEPARATOR . 'fare_attributes.txt', 'w');
        fputcsv($handle, ['fare_id', 'price', 'currency_type', 'payment_method', 'transfers', 'agency_id', 'transfer_duration']);
        FareAttribute::all()->each(function ($a) use ($handle) {
            fputcsv($handle, [
                $a->fare_id, $a->price, $a->currency_type,
                $a->payment_method, $a->transfers ?? '',
                $a->agency_id, $a->transfer_duration ?? '',
            ]);
        });
        fclose($handle);
    }

    private function writeFareRulesCsv(string $dir): void
    {
        $handle = fopen($dir . DIRECTORY_SEPARATOR . 'fare_rules.txt', 'w');
        fputcsv($handle, ['fare_id', 'route_id', 'origin_id', 'destination_id', 'contains_id']);
        FareRule::all()->each(function ($r) use ($handle) {
            fputcsv($handle, [
                $r->fare_id, $r->route_id ?? '', $r->origin_id ?? '',
                $r->destination_id ?? '', $r->contains_id ?? '',
            ]);
        });
        fclose($handle);
    }
}
