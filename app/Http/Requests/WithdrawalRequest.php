<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'amount' => 'required|numeric|min:10',
            'method' => 'required|string|max:100',
            'account_details' => 'required|string|max:500',
        ];
    }
}
