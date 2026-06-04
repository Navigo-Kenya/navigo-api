<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'   => 'required|string|exists:agencies,agency_id',
            'period_from' => 'required|date',
            'period_to'   => 'required|date|after_or_equal:period_from',
        ]);

        $this->assertAgencyAllowed($request, $data['agency_id']);

        $shifts = Shift::query()
            ->where('agency_id', $data['agency_id'])
            ->whereBetween('shift_date', [$data['period_from'], $data['period_to']])
            ->whereNotNull('driver_id')
            ->with(['driver:id,name', 'vehicle:id,plate', 'conductor:id,name'])
            ->get();

        $byDriver = $shifts->groupBy('driver_id');

        $rows = [];

        foreach ($byDriver as $driverId => $driverShifts) {
            $driver     = $driverShifts->first()->driver;
            $vehicleIds = $driverShifts->pluck('vehicle_id')->filter()->unique();
            $shiftDates = $driverShifts->pluck('shift_date')->unique()->toArray();

            $daysWorked = $driverShifts
                ->where('status', 'completed')
                ->pluck('shift_date')
                ->unique()
                ->count();

            $totalBanked   = 0;
            $totalExpected = 0;
            $fuelAdvances  = 0;

            if ($vehicleIds->isNotEmpty() && count($shiftDates) > 0) {
                $bankRow = DB::table('daily_banking')
                    ->whereIn('vehicle_id', $vehicleIds)
                    ->whereIn('banking_date', $shiftDates)
                    ->selectRaw('SUM(banked_amount) as banked, SUM(COALESCE(expected_amount, 0)) as expected')
                    ->first();

                $totalBanked   = (float) ($bankRow?->banked   ?? 0);
                $totalExpected = (float) ($bankRow?->expected ?? 0);

                $fuelAdvances = (float) DB::table('vehicle_expenses')
                    ->whereIn('vehicle_id', $vehicleIds)
                    ->where('expense_type', 'fuel')
                    ->whereIn('expense_date', $shiftDates)
                    ->sum('amount');
            }

            // Nairobi lengo model: (banked − target − fuel) ÷ 2 → driver share
            $grossAfterTarget = max(0, $totalBanked - $totalExpected);
            $netBeforeSplit   = max(0, $grossAfterTarget - $fuelAdvances);
            $driverNet        = round($netBeforeSplit / 2, 2);

            $rows[] = [
                'driver_id'        => $driverId,
                'driver_name'      => $driver?->name ?? 'Unknown',
                'days_worked'      => $daysWorked,
                'total_banked'     => round($totalBanked, 2),
                'total_expected'   => round($totalExpected, 2),
                'fuel_advances'    => round($fuelAdvances, 2),
                'net_before_split' => round($netBeforeSplit, 2),
                'driver_net_pay'   => $driverNet,
                'vehicles'         => $driverShifts->pluck('vehicle.plate')->filter()->unique()->values(),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['driver_name'], $b['driver_name']));

        return response()->json([
            'agency_id'    => $data['agency_id'],
            'period_from'  => $data['period_from'],
            'period_to'    => $data['period_to'],
            'generated_at' => now()->toISOString(),
            'rows'         => $rows,
            'totals'       => [
                'total_banked'   => round(collect($rows)->sum('total_banked'), 2),
                'total_expected' => round(collect($rows)->sum('total_expected'), 2),
                'total_net_pay'  => round(collect($rows)->sum('driver_net_pay'), 2),
            ],
        ]);
    }
}
