<?php

namespace App\Http\Controllers;

use App\Enums\EmployeeType;
use App\Enums\LoyaltyRewardType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnumController extends Controller
{

    public function LoyaltyRewardType(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => LoyaltyRewardType::toArray(),
        ]);
    }
}
