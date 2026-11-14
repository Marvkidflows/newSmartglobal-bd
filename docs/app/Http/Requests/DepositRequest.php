<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepositRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'amount' => 'required|numeric|min:10',
            'payment_method' => 'required|string|max:100',
            'proof_image' => 'required|image|max:2048',
        ];
    }
}