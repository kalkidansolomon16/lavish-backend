<?php

namespace Database\Seeders;

use App\Models\EarnRule;
use App\Models\LoyaltySetting;
use App\Models\LoyaltyTier;
use App\Models\Product;
use App\Models\Reward;
use App\Models\Service;
use Illuminate\Database\Seeder;

/**
 * Starting values for Lavish Rewards and gift cards. Runs in every environment (production too) and only
 * fills what is empty, so values a manager changed in Web → Rewards are never overwritten.
 *
 * Every number here is a PLACEHOLDER from the functional spec (X, Y, N, A, B) until Lavish decides —
 * see docs/API.md §12 "Open decisions". With these values a client gets roughly 5% back:
 *   1 point per ETB 10 spent + 50 per visit, and 1 point = ETB 0.50 off.
 */
class LoyaltySeeder extends Seeder
{
    public function run(): void
    {
        if (! LoyaltySetting::query()->exists()) {
            LoyaltySetting::create([
                'point_value_etb' => 0.5,
                'points_expiry_months' => 12,
                'expiry_reminder_days' => 14,
                'voucher_valid_days' => 90,
                'max_points_per_visit' => 5000,
                'max_points_per_day' => 8000,
                'max_referrals_per_year' => 10,
                'gift_card_expiry_months' => 24,
                'promo_card_expiry_months' => 3,
                'gift_card_low_balance_percent' => 20,
                'gift_card_amounts' => [1000, 2500, 5000, 10000],
            ]);
        }

        if (! LoyaltyTier::query()->exists()) {
            foreach ([
                ['glow', 'Glow', 0, 1, 0, false, ['Birthday reward', 'Full reward catalog']],
                ['radiance', 'Radiance', 3000, 1.25, 0, false, ['Earn 1.25× points', 'Priority booking', 'Free add-on each quarter']],
                ['elite', 'Lavish Elite', 10000, 1.5, 5, true, ['Earn 1.5× points', 'Exclusive prices (5% off treatments)', 'Early access to new services', 'Free signature treatment every year', 'Points never expire']],
            ] as $i => [$key, $name, $min, $mult, $discount, $never, $benefits]) {
                LoyaltyTier::create(['key' => $key, 'name' => $name, 'min_points_12m' => $min, 'earn_multiplier' => $mult,
                    'service_discount_percent' => $discount, 'points_never_expire' => $never, 'benefits' => $benefits, 'sort' => $i]);
            }
        }

        if (! EarnRule::query()->exists()) {
            $service = fn (string $n) => Service::query()->where('name', $n)->value('id');
            $rules = [
                ['spend', null, 10, 100],          // 10 points per ETB 100 on treatments
                ['visit', null, 50, null],         // + 50 for every completed visit
                ['product_spend', null, 5, 100],   // 5 points per ETB 100 in the shop
                ['referral', null, 500, null],
                ['review', null, 50, null],
                ['birthday', null, 300, null],
                ['signup', null, 200, null],
                ['app_booking', null, 25, null],
            ];
            foreach ($rules as [$type, $serviceId, $points, $perEtb]) {
                EarnRule::create(['type' => $type, 'service_id' => $serviceId, 'points' => $points, 'per_etb' => $perEtb]);
            }
            // Bonus points on selected high-value treatments (skipped if the treatment doesn't exist yet).
            foreach (['Hair Restoration Solutions' => 500, 'Body Contouring & Skin Tightening' => 200, 'HIFU Face & Neck Lift' => 150] as $name => $points) {
                if ($id = $service($name)) {
                    EarnRule::create(['type' => 'visit', 'service_id' => $id, 'points' => $points]);
                }
            }
        }

        if (! Reward::query()->exists()) {
            $service = fn (string $n) => Service::query()->where('name', $n)->value('id');
            $product = fn (string $n) => Product::query()->where('name', $n)->value('id');
            $elite = LoyaltyTier::query()->where('key', 'elite')->value('id');
            $ladder = [
                // [name, cost, type, value, service, product, tier, icon, description]
                ['ETB 300 off any treatment', 600, 'discount', 300, null, null, null, 'sell', 'Your first reward — taken off your next treatment.'],
                ['Free SPF 50 Mineral Sunscreen', 3000, 'product', 0, null, $product('SPF 50 Mineral Sunscreen'), null, 'sunny', 'Collect it at the front desk on your next visit.'],
                ['ETB 1,000 off any treatment', 2000, 'discount', 1000, null, null, null, 'sell', 'Use it on any treatment at any branch.'],
                ['Free Signature HydraFacial', 8000, 'free_service', 0, $service('Signature HydraFacial'), null, null, 'spa', 'A full Signature HydraFacial on us.'],
                ['Free PRP Skin Rejuvenation', 16000, 'free_service', 0, $service('PRP Skin Rejuvenation'), null, null, 'water_drop', 'One PRP Skin Rejuvenation session.'],
                ['Free HIFU Face & Neck Lift', 25000, 'free_service', 0, $service('HIFU Face & Neck Lift'), null, $elite, 'diamond', 'Reserved for Lavish Elite members.'],
            ];
            foreach ($ladder as $i => [$name, $cost, $type, $value, $serviceId, $productId, $tier, $icon, $desc]) {
                if (($type === 'free_service' && ! $serviceId) || ($type === 'product' && ! $productId)) {
                    continue;
                }
                Reward::create(['name' => $name, 'description' => $desc, 'points_cost' => $cost, 'reward_type' => $type, 'value' => $value,
                    'service_id' => $serviceId, 'product_id' => $productId, 'tier_required_id' => $tier, 'icon' => $icon, 'sort' => $i]);
            }
        }
    }
}
