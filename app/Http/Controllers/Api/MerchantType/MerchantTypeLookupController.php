<?php

namespace App\Http\Controllers\Api\MerchantType;

use App\Http\Controllers\Controller;
use App\Models\Mysuncash\BillpayCountry;
use App\Models\Mysuncash\ClientBusinessCategory;
use App\Models\Mysuncash\ClientSoleProprietorship;
use App\Models\Mysuncash\EmploymentPositionLevel;
use App\Models\Mysuncash\IdType;
use App\Models\Mysuncash\Island;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only reference data for the Business/Charity Management Initial Info forms. */
class MerchantTypeLookupController extends Controller
{
    public function soleProprietorships(Request $request): JsonResponse
    {
        $type = $request->query('type') === 'charity'
            ? ClientSoleProprietorship::CHARITY_IDS
            : ClientSoleProprietorship::BUSINESS_IDS;

        return response()->json(
            ClientSoleProprietorship::whereIn('id', $type)->orderBy('name')->get(['id', 'name'])
        );
    }

    public function businessCategories(): JsonResponse
    {
        return response()->json(
            ClientBusinessCategory::where('status', 'A')->orderBy('name')->get(['id', 'name'])
        );
    }

    public function idTypes(): JsonResponse
    {
        return response()->json(
            IdType::where('to_display', 1)->orderBy('description')->get(['code', 'description'])
        );
    }

    public function positionLevels(): JsonResponse
    {
        return response()->json(
            EmploymentPositionLevel::orderBy('id')->get(['id', 'description'])
        );
    }

    public function islands(): JsonResponse
    {
        return response()->json(
            Island::where('status', 'A')->orderBy('name')->get(['id', 'name'])
        );
    }

    public function countries(): JsonResponse
    {
        return response()->json(
            BillpayCountry::orderBy('name')->get(['id', 'name'])
        );
    }
}
