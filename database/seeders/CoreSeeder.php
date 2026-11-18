<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Real starting data every installation needs (production too): the three branches, the treatment
 * catalogue (from lavishmedicalspa.com — confirm prices and durations) and the Lavish Rewards defaults.
 * Safe to run again: existing rows are kept and only missing ones are added.
 */
class CoreSeeder extends Seeder
{
    public const BRANCHES = [
        ['Addis Ababa', 'Bole, Adorsam Apartments, 5th floor', true],
        ['Adama', 'Kanab Mall, 3rd floor, near the Post Office', false],
        ['Dire Dawa', 'Sabian Ozie Plaza, 4th floor', false],
    ];

    // [name, price ETB, minutes, Material Symbols icon]
    public const SERVICES = [
        ['Signature HydraFacial', 4500, 60, 'spa'],
        ['PRP Skin Rejuvenation', 9000, 75, 'water_drop'],
        ['Morpheus8 & Microneedling', 12000, 90, 'auto_awesome'],
        ['Advanced Dermatology Treatments', 3500, 45, 'healing'],
        ['Botox & Dermal Fillers', 18500, 45, 'vaccines'],
        ['HIFU Face & Neck Lift', 15000, 90, 'face_retouching_natural'],
        ['Hair Restoration Solutions', 45000, 240, 'face'],
        ['Body Contouring & Skin Tightening', 20000, 120, 'accessibility_new'],
    ];

    public function run(): void
    {
        foreach (self::BRANCHES as $i => [$name, $address, $main]) {
            Branch::query()->firstOrCreate(['name' => $name], ['address' => $address, 'is_main' => $main, 'sort' => $i]);
        }

        foreach (self::SERVICES as $i => [$name, $price, $mins, $icon]) {
            Service::query()->firstOrCreate(['name' => $name], [
                'slug' => Str::slug($name), 'price' => $price, 'duration_minutes' => $mins, 'icon' => $icon, 'is_online' => false, 'sort' => $i,
            ]);
        }
        Service::query()->firstOrCreate(['name' => 'Online Consultation'], [
            'slug' => 'online-consultation', 'price' => 4000, 'duration_minutes' => 45, 'icon' => 'video_call', 'is_online' => true, 'sort' => 99,
            'description' => 'A 45-minute video call with a Lavish doctor. Reception confirms the time; the video link appears in the app once paid.',
        ]);

        $this->call(LoyaltySeeder::class);
    }
}
