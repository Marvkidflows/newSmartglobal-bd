<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvestmentPlanRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->user()->role === 'admin';
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255|unique:investment_plans,name',
            'description' => 'nullable|string',
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'required|numeric|gt:min_amount',
            'profit_percentage' => 'required|numeric|min:0|max:100',
            'duration_months' => 'required|integer|min:1',
            'status' => 'required|in:active,inactive',
        ];
    }
}