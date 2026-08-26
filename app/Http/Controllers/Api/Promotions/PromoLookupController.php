<?php

namespace App\Http\Controllers\Api\Promotions;

use App\Http\Controllers\Controller;
use App\Models\Mysuncash\Branch;
use App\Models\Mysuncash\Country;
use App\Models\Mysuncash\Island;
use App\Models\Mysuncash\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoLookupController extends Controller
{
    public function islands(Request $request): JsonResponse
    {
        return response()->json(
            Island::where('status', 'A')->orderBy('name')->get(['id', 'name'])
        );
    }

    public function countries(Request $request): JsonResponse
    {
        return response()->json(
            Country::where('status', 1)->orderBy('name')->get(['country_id', 'name'])
        );
    }

    public function merchants(Request $request): JsonResponse
    {
        return response()->json(
            Merchant::orderBy('merchant_name')
                ->get(['id', 'merchant_name', 'legal_name'])
                ->map(fn (Merchant $merchant) => [
                    'id' => $merchant->id,
                    'name' => $merchant->merchant_name ?: $merchant->legal_name,
                ])
        );
    }

    public function branches(Request $request): JsonResponse
    {
        $merchantId = (int) $request->query('merchant_id');

        return response()->json(
            Branch::where('client_record_id', $merchantId)
                ->where('status', Branch::STATUS_ACTIVE)
                ->orderBy('description')
                ->get(['id', 'description'])
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->description])
        );
    }
}
