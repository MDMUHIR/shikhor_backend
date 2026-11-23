<?php

namespace App\Services;

use App\Models\DeliveryCharge;

/**
 * Delivery pricing decided on the server from the shipping district.
 * Inside Dhaka (district "Dhaka") and outside Dhaka use the admin-defined zone charges;
 * a "free_over" row waives the charge once the products subtotal reaches its minimum.
 */
class DeliveryService
{
    public const INSIDE = 'inside_dhaka';

    public const OUTSIDE = 'outside_dhaka';

    public function zoneFor(?string $district): string
    {
        $normalized = strtolower(trim((string) $district));

        return in_array($normalized, ['dhaka', 'dhaka district', 'dhaka city'], true) ? self::INSIDE : self::OUTSIDE;
    }

    /**
     * @return array{zone: string, label: string, charge: float, free_delivery: bool, free_over: ?float}
     */
    public function quote(?string $district, float $productsSubtotal): array
    {
        $zone = $this->zoneFor($district);

        $active = DeliveryCharge::query()->where('status', true)->get();
        $row = $active->where('zone', $zone)->sortBy('charge')->first();

        $freeRow = $active
            ->where('zone', 'free_over')
            ->filter(fn ($r) => (float) $r->minimum_order > 0)
            ->sortBy('minimum_order')
            ->first();

        $free = $freeRow && $productsSubtotal >= (float) $freeRow->minimum_order;

        // A zone row may also carry its own free-delivery threshold.
        if ($row && (float) $row->minimum_order > 0 && $productsSubtotal >= (float) $row->minimum_order) {
            $free = true;
        }

        return [
            'zone' => $zone,
            'label' => $row?->name ?? ($zone === self::INSIDE ? 'Inside Dhaka' : 'Outside Dhaka'),
            'charge' => $free || ! $row ? 0.0 : round((float) $row->charge, 2),
            'free_delivery' => $free,
            'free_over' => $freeRow ? (float) $freeRow->minimum_order : null,
        ];
    }
}
