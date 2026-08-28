<?php

namespace App\Http\Requests;

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePointRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is enforced by PointPolicy (via HasMiddleware in the
     * controller), not here — this only validates the payload shape.
     */
    public function authorize(): bool
    {
        return true;
    }

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
            'note' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::enum(PointStatus::class)],
            'visited_at' => ['nullable', 'date'],
            'visibility' => ['sometimes', Rule::enum(PointVisibility::class)],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('owner_id', $this->user()->id),
            ],
        ];
    }
}
