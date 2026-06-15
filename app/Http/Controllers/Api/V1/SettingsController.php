<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private const DEFAULTS = [
        'notifications' => [
            'master'           => true,
            'sound'            => true,
            'route_changes'    => true,
            'disruptions'      => true,
            'stop_updates'     => false,
            'bus_arriving'     => true,
            'journey_reminder' => true,
            'turn_by_turn'     => false,
            'nearby_contrib'   => true,
            'points_earned'    => true,
            'tips'             => false,
            'app_news'         => false,
        ],
        'privacy' => [
            'two_fa'             => false,
            'analytics'          => true,
            'anonymous_reports'  => false,
        ],
    ];

    public function show(Request $request): JsonResponse
    {
        $settings = array_replace_recursive(self::DEFAULTS, $request->user()->settings ?? []);
        return response()->json($settings);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notifications'                  => 'sometimes|array',
            'notifications.master'           => 'sometimes|boolean',
            'notifications.sound'            => 'sometimes|boolean',
            'notifications.route_changes'    => 'sometimes|boolean',
            'notifications.disruptions'      => 'sometimes|boolean',
            'notifications.stop_updates'     => 'sometimes|boolean',
            'notifications.bus_arriving'     => 'sometimes|boolean',
            'notifications.journey_reminder' => 'sometimes|boolean',
            'notifications.turn_by_turn'     => 'sometimes|boolean',
            'notifications.nearby_contrib'   => 'sometimes|boolean',
            'notifications.points_earned'    => 'sometimes|boolean',
            'notifications.tips'             => 'sometimes|boolean',
            'notifications.app_news'         => 'sometimes|boolean',
            'privacy'                        => 'sometimes|array',
            'privacy.two_fa'                 => 'sometimes|boolean',
            'privacy.analytics'              => 'sometimes|boolean',
            'privacy.anonymous_reports'      => 'sometimes|boolean',
        ]);

        $user    = $request->user();
        $current = array_replace_recursive(self::DEFAULTS, $user->settings ?? []);
        $merged  = array_replace_recursive($current, $data);

        $user->update(['settings' => $merged]);

        return response()->json($merged);
    }
}
