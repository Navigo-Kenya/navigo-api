<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [

            // ── Starter ───────────────────────────────────────────────────────────
            [
                'slug'              => 'first_step',
                'name'              => 'First Step',
                'description'       => 'Made your very first contribution to the Navigo community.',
                'icon'              => 'footsteps-outline',
                'color'             => '#FF6F00',
                'requirement_type'  => 'total_count',
                'requirement_value' => 1,
                'requirement_meta'  => null,
                'points_bonus'      => 5,
            ],
            [
                'slug'              => 'first_alert',
                'name'              => 'First Alert',
                'description'       => 'Spotted and reported your first real-time incident on Nairobi roads.',
                'icon'              => 'megaphone-outline',
                'color'             => '#FF6F00',
                'requirement_type'  => 'report_count',
                'requirement_value' => 1,
                'requirement_meta'  => null,
                'points_bonus'      => 5,
            ],

            // ── Stop Photos ────────────────────────────────────────────────────────
            [
                'slug'              => 'photo_taker',
                'name'              => 'Photo Taker',
                'description'       => 'Added 5 stop photos to help commuters find their stages.',
                'icon'              => 'camera-outline',
                'color'             => '#8B5CF6',
                'requirement_type'  => 'type_count',
                'requirement_value' => 5,
                'requirement_meta'  => ['type' => 'stop_photo'],
                'points_bonus'      => 15,
            ],
            [
                'slug'              => 'photo_pro',
                'name'              => 'Photo Pro',
                'description'       => 'Added 20 stop photos — a visual guide for every commuter in Nairobi.',
                'icon'              => 'camera',
                'color'             => '#7C3AED',
                'requirement_type'  => 'type_count',
                'requirement_value' => 20,
                'requirement_meta'  => ['type' => 'stop_photo'],
                'points_bonus'      => 50,
            ],
            [
                'slug'              => 'visual_legend',
                'name'              => 'Visual Legend',
                'description'       => 'Documented 60 matatu stages with photos. The network sees through your lens.',
                'icon'              => 'images-outline',
                'color'             => '#6D28D9',
                'requirement_type'  => 'type_count',
                'requirement_value' => 60,
                'requirement_meta'  => ['type' => 'stop_photo'],
                'points_bonus'      => 100,
            ],

            // ── Delay Reports (Contributions) ──────────────────────────────────────
            [
                'slug'              => 'delay_spotter',
                'name'              => 'Delay Spotter',
                'description'       => 'Filed 10 delay alerts, keeping fellow commuters one step ahead.',
                'icon'              => 'time-outline',
                'color'             => '#EF4444',
                'requirement_type'  => 'type_count',
                'requirement_value' => 10,
                'requirement_meta'  => ['type' => 'delay_report'],
                'points_bonus'      => 20,
            ],
            [
                'slug'              => 'traffic_oracle',
                'name'              => 'Traffic Oracle',
                'description'       => 'Filed 40+ delay alerts. Nairobi commuters plan their day around your watch.',
                'icon'              => 'speedometer-outline',
                'color'             => '#DC2626',
                'requirement_type'  => 'type_count',
                'requirement_value' => 40,
                'requirement_meta'  => ['type' => 'delay_report'],
                'points_bonus'      => 75,
            ],

            // ── Incident Reports ───────────────────────────────────────────────────
            [
                'slug'              => 'road_reporter',
                'name'              => 'Road Reporter',
                'description'       => 'Submitted 10 real-time incident reports from the field.',
                'icon'              => 'megaphone-outline',
                'color'             => '#F97316',
                'requirement_type'  => 'report_count',
                'requirement_value' => 10,
                'requirement_meta'  => null,
                'points_bonus'      => 15,
            ],
            [
                'slug'              => 'city_watchman',
                'name'              => 'City Watchman',
                'description'       => 'Filed 50 incident reports. You\'re one of Nairobi\'s most active street monitors.',
                'icon'              => 'eye-outline',
                'color'             => '#F59E0B',
                'requirement_type'  => 'report_count',
                'requirement_value' => 50,
                'requirement_meta'  => null,
                'points_bonus'      => 50,
            ],
            [
                'slug'              => 'guardian_of_nairobi',
                'name'              => 'Guardian of Nairobi',
                'description'       => 'Over 150 incident reports submitted. The city watches with your eyes.',
                'icon'              => 'shield-checkmark-outline',
                'color'             => '#D97706',
                'requirement_type'  => 'report_count',
                'requirement_value' => 150,
                'requirement_meta'  => null,
                'points_bonus'      => 125,
            ],

            // ── Report type specialisations ────────────────────────────────────────
            [
                'slug'              => 'traffic_hawk',
                'name'              => 'Traffic Hawk',
                'description'       => 'Reported 15 traffic jams. Matatus re-route around your warnings.',
                'icon'              => 'car-sport-outline',
                'color'             => '#FF6F00',
                'requirement_type'  => 'report_type_count',
                'requirement_value' => 15,
                'requirement_meta'  => ['type' => 'traffic_jam'],
                'points_bonus'      => 25,
            ],
            [
                'slug'              => 'safety_spotter',
                'name'              => 'Safety Spotter',
                'description'       => 'Flagged 10 security incidents, keeping commuters safe after dark.',
                'icon'              => 'warning-outline',
                'color'             => '#D32F2F',
                'requirement_type'  => 'report_type_count',
                'requirement_value' => 10,
                'requirement_meta'  => ['type' => 'security'],
                'points_bonus'      => 30,
            ],
            [
                'slug'              => 'flood_finder',
                'name'              => 'Flood Finder',
                'description'       => 'Reported 8 flooded routes during Nairobi\'s rainy seasons.',
                'icon'              => 'water-outline',
                'color'             => '#5856D6',
                'requirement_type'  => 'report_type_count',
                'requirement_value' => 8,
                'requirement_meta'  => ['type' => 'flooded_route'],
                'points_bonus'      => 20,
            ],
            [
                'slug'              => 'fare_watchdog',
                'name'              => 'Fare Watchdog',
                'description'       => 'Called out 12 fare hikes. Commuters thank you for the heads-up.',
                'icon'              => 'trending-up-outline',
                'color'             => '#30B050',
                'requirement_type'  => 'report_type_count',
                'requirement_value' => 12,
                'requirement_meta'  => ['type' => 'fare_hike'],
                'points_bonus'      => 20,
            ],

            // ── Data quality ───────────────────────────────────────────────────────
            [
                'slug'              => 'fact_finder',
                'name'              => 'Fact Finder',
                'description'       => 'Had 10 stop edits approved, making the transit map more accurate.',
                'icon'              => 'create-outline',
                'color'             => '#3B82F6',
                'requirement_type'  => 'approved_type_count',
                'requirement_value' => 10,
                'requirement_meta'  => ['type' => 'stop_edit'],
                'points_bonus'      => 40,
            ],
            [
                'slug'              => 'route_pioneer',
                'name'              => 'Route Pioneer',
                'description'       => 'Got 3 new stops approved, expanding Nairobi\'s official transit network.',
                'icon'              => 'navigate-outline',
                'color'             => '#10B981',
                'requirement_type'  => 'approved_type_count',
                'requirement_value' => 3,
                'requirement_meta'  => ['type' => 'new_stop'],
                'points_bonus'      => 75,
            ],
            [
                'slug'              => 'community_voice',
                'name'              => 'Community Voice',
                'description'       => 'Wrote 20 stop reviews, guiding thousands of daily commuters.',
                'icon'              => 'chatbubbles-outline',
                'color'             => '#F59E0B',
                'requirement_type'  => 'type_count',
                'requirement_value' => 20,
                'requirement_meta'  => ['type' => 'stop_review'],
                'points_bonus'      => 30,
            ],

            // ── Points milestones ──────────────────────────────────────────────────
            [
                'slug'              => 'points_100',
                'name'              => 'Commuter Pro',
                'description'       => 'Reached 100 Safiri Points. You\'re making a real difference.',
                'icon'              => 'ribbon-outline',
                'color'             => '#0EA5E9',
                'requirement_type'  => 'points',
                'requirement_value' => 100,
                'requirement_meta'  => null,
                'points_bonus'      => 0,
            ],
            [
                'slug'              => 'points_500',
                'name'              => 'Transit Expert',
                'description'       => 'Reached 500 Safiri Points. An indispensable voice in the community.',
                'icon'              => 'medal-outline',
                'color'             => '#F97316',
                'requirement_type'  => 'points',
                'requirement_value' => 500,
                'requirement_meta'  => null,
                'points_bonus'      => 0,
            ],
            [
                'slug'              => 'points_1000',
                'name'              => 'Matatu Master',
                'description'       => 'Reached 1000 Safiri Points. A legend of the Nairobi network.',
                'icon'              => 'trophy-outline',
                'color'             => '#EAB308',
                'requirement_type'  => 'points',
                'requirement_value' => 1000,
                'requirement_meta'  => null,
                'points_bonus'      => 0,
            ],
            [
                'slug'              => 'points_2500',
                'name'              => 'Safiri Gold',
                'description'       => 'Reached 2500 Safiri Points. Among the top contributors in all of Nairobi.',
                'icon'              => 'star-outline',
                'color'             => '#D97706',
                'requirement_type'  => 'points',
                'requirement_value' => 2500,
                'requirement_meta'  => null,
                'points_bonus'      => 0,
            ],
            [
                'slug'              => 'points_5000',
                'name'              => 'Nairobi Legend',
                'description'       => 'Reached 5000 Safiri Points. Your contributions shape how this city moves.',
                'icon'              => 'diamond-outline',
                'color'             => '#B45309',
                'requirement_type'  => 'points',
                'requirement_value' => 5000,
                'requirement_meta'  => null,
                'points_bonus'      => 0,
            ],
        ];

        foreach ($badges as $badge) {
            Badge::updateOrCreate(['slug' => $badge['slug']], $badge);
        }
    }
}
