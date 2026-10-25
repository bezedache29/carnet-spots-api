<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePointRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:10000'],
            'status' => [Rule::enum(PointStatus::class)],
            'visited_at' => ['nullable', 'date'],
            'visibility' => [Rule::enum(PointVisibility::class)],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('owner_id', $this->user()->id)],
        ];
    }
}
