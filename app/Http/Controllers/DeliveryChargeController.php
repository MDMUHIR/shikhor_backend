<?php

namespace App\Http\Controllers;

use App\Models\DeliveryCharge;
use App\Services\DeliveryService;
use Illuminate\Http\Request;

class DeliveryChargeController extends Controller
{
    public function getDeliveryCharges(Request $request)
    {
        $charges = DeliveryCharge::all();
        return $this->success('Delivery charges retrieved successfully', $charges);
    }

    /** Delivery quote for a district: the zone is picked automatically (Dhaka = inside, everywhere else = outside). */
    public function quote(Request $request, DeliveryService $delivery)
    {
        $request->validate(['district' => 'required|string|max:100', 'subtotal' => 'nullable|numeric|min:0']);

        return $this->success('Delivery quote', $delivery->quote($request->district, (float) $request->input('subtotal', 0)));
    }

    public function addDeliveryCharge(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'zone' => 'nullable|in:inside_dhaka,outside_dhaka,free_over',
            'charge' => 'required|numeric|min:0',
            'minimum_order' => 'nullable|numeric|min:0',
            'status' => 'nullable|boolean',
        ]);

        $charge = DeliveryCharge::create([
            'name' => $request->name,
            'zone' => $request->zone,
            'charge' => $request->charge,
            'minimum_order' => $request->minimum_order,
            'status' => $request->boolean('status', true),
        ]);

        return $this->success('Delivery charge added successfully', $charge);
    }

    public function updateDeliveryCharge(Request $request)
    {
        $this->validate($request, [
            'id' => 'required|exists:delivery_charges,id',
            'name' => 'required|string|max:255',
            'zone' => 'nullable|in:inside_dhaka,outside_dhaka,free_over',
            'charge' => 'required|numeric|min:0',
            'minimum_order' => 'nullable|numeric|min:0',
            'status' => 'nullable|boolean',
        ]);

        $charge = DeliveryCharge::find($request->id);
        if (!$charge) {
            return $this->error('Delivery charge not found', 404);
        }

        $charge->update([
            'name' => $request->name,
            'zone' => $request->has('zone') ? $request->zone : $charge->zone,
            'charge' => $request->charge,
            'minimum_order' => $request->minimum_order,
            'status' => $request->boolean('status', $charge->status),
        ]);

        return $this->success('Delivery charge updated successfully', $charge);
    }

    public function deleteDeliveryCharge(Request $request, $id)
    {
        $charge = DeliveryCharge::find($id);
        if (!$charge) {
            return $this->error('Delivery charge not found', 404);
        }

        $charge->delete();

        return $this->success('Delivery charge deleted successfully');
    }
}
