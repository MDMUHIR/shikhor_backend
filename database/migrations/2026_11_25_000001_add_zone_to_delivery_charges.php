<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_charges', function (Blueprint $table) {
            // inside_dhaka | outside_dhaka | free_over (free once the products subtotal reaches minimum_order)
            $table->string('zone')->nullable()->after('name');
        });

        foreach (DB::table('delivery_charges')->get() as $row) {
            $name = strtolower($row->name);
            $zone = str_contains($name, 'inside') ? 'inside_dhaka'
                : (str_contains($name, 'outside') ? 'outside_dhaka'
                : (((float) $row->charge === 0.0 && (float) $row->minimum_order > 0) ? 'free_over' : null));

            if ($zone) {
                DB::table('delivery_charges')->where('id', $row->id)->update(['zone' => $zone]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('delivery_charges', function (Blueprint $table) {
            $table->dropColumn('zone');
        });
    }
};
