<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentOriginEnum;
use App\Enums\PrestationCategory;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class EnumController extends Controller
{
    public function PrestationCategory()
    {
        return response()->json([
            'status' => 'success',
            'data'   => PrestationCategory::toArray(),
        ]);
    }

    public function AppointmentOriginEnum()
    {
        return response()->json([
            'status' => 'success',
            'data'   => AppointmentOriginEnum::toArray(),
        ]);
    }




}
