<?php

namespace App\Http\Requests;

use App\Enums\CashRegisterStatus;
use App\Enums\CashRegisterType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $establishmentUuid = $this->header('X-Establishment-Uuid');

        return [
            'name' => ['required', 'string', 'max:150'],
            'initial_balance' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['required', Rule::enum(CashRegisterType::class)],
            'status' => ['sometimes', Rule::enum(CashRegisterStatus::class)],
            'user_id' => ['nullable', Rule::exists('users', 'id'),],
        ];
    }
}
