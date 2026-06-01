<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            [
                'slug'               => 'first_step',
                'name'               => 'First Step',
                'description'        => 'Made your very first contribution to the Hopln community.',
                'icon'               => 'footsteps-outline',
                'color'              => '#FF6F00',
                'requirement_type'   => 'total_count',
                'requirement_value'  => 1,
                'requirement_meta'   => null,
                'points_bonus'       => 5,
            ],
            [
                'slug'               => 'photo_taker',
                'name'               => 'Photo Taker',
                'description'        => 'Added 3 or more stop photos to help the community.',
                'icon'               => 'camera-outline',
                'color'              => '#8B5CF6',
                'requirement_type'   => 'type_count',
                'requirement_value'  => 3,
                'requirement_meta'   => ['type' => 'stop_photo'],
                'points_bonus'       => 10,
            ],
            [
                'slug'               => 'photo_pro',
                'name'               => 'Photo Pro',
                'description'        => 'Added 10 or more stop photos, a true visual contributor.',
                'icon'               => 'camera',
                'color'              => '#7C3AED',
                'requirement_type'   => 'type_count',
                'requirement_value'  => 10,
                'requirement_meta'   => ['type' => 'stop_photo'],
                'points_bonus'       => 30,
            ],
            [
                'slug'               => 'delay_spotter',
                'name'               => 'Delay Spotter',
                'description'        => 'Reported 5 or more delay alerts, keeping commuters informed.',
                'icon'               => 'time-outline',
                'color'              => '#EF4444',
                'requirement_type'   => 'type_count',
                'requirement_value'  => 5,
                'requirement_meta'   => ['type' => 'delay_report'],
                'points_bonus'       => 15,
            ],
            [
                'slug'               => 'traffic_oracle',
                'name'               => 'Traffic Oracle',
                'description'        => 'Reported 20+ delay alerts. The community relies on you.',
                'icon'               => 'speedometer-outline',
                'color'              => '#DC2626',
                'requirement_type'   => 'type_count',
                'requirement_value'  => 20,
                'requirement_meta'   => ['type' => 'delay_report'],
                'points_bonus'       => 50,
            ],
            [
                'slug'               => 'fact_finder',
                'name'               => 'Fact Finder',
                'description'        => 'Had 5 stop edits approved, improving data accuracy.',
                'icon'               => 'create-outline',
                'color'              => '#3B82F6',
                'requirement_type'   => 'approved_type_count',
                'requirement_value'  => 5,
                'requirement_meta'   => ['type' => 'stop_edit'],
                'points_bonus'       => 25,
            ],
            [
                'slug'               => 'route_pioneer',
                'name'               => 'Route Pioneer',
                'description'        => 'Had a new stop suggestion approved, expanding the network.',
                'icon'               => 'navigate-outline',
                'color'              => '#10B981',
                'requirement_type'   => 'approved_type_count',
                'requirement_value'  => 1,
                'requirement_meta'   => ['type' => 'new_stop'],
                'points_bonus'       => 50,
            ],
            [
                'slug'               => 'community_voice',
                'name'               => 'Community Voice',
                'description'        => 'Wrote 10 or more stop reviews, helping fellow commuters.',
                'icon'               => 'chatbubbles-outline',
                'color'              => '#F59E0B',
                'requirement_type'   => 'type_count',
                'requirement_value'  => 10,
                'requirement_meta'   => ['type' => 'stop_review'],
                'points_bonus'       => 20,
            ],
            [
                'slug'               => 'points_100',
                'name'               => 'Commuter Pro',
                'description'        => 'Reached 100 Safiri Points. You\'re making a real difference.',
                'icon'               => 'ribbon-outline',
                'color'              => '#0EA5E9',
                'requirement_type'   => 'points',
                'requirement_value'  => 100,
                'requirement_meta'   => null,
                'points_bonus'       => 0,
            ],
            [
                'slug'               => 'points_500',
                'name'               => 'Transit Expert',
                'description'        => 'Reached 500 Safiri Points. An indispensable community pillar.',
                'icon'               => 'medal-outline',
                'color'              => '#F97316',
                'requirement_type'   => 'points',
                'requirement_value'  => 500,
                'requirement_meta'   => null,
                'points_bonus'       => 0,
            ],
            [
                'slug'               => 'points_1000',
                'name'               => 'Matatu Master',
                'description'        => 'Reached 1000 Safiri Points. A legend of the Nairobi network.',
                'icon'               => 'trophy-outline',
                'color'              => '#EAB308',
                'requirement_type'   => 'points',
                'requirement_value'  => 1000,
                'requirement_meta'   => null,
                'points_bonus'       => 0,
            ],
        ];

        foreach ($badges as $badge) {
            Badge::updateOrCreate(['slug' => $badge['slug']], $badge);
        }
    }
}
