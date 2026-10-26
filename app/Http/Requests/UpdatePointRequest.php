<?php

namespace App\Http\Requests;

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePointRequest extends FormRequest
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
     * All fields are optional so that partial updates (e.g. marking a
     * point as visited with only status/visited_at/note) validate cleanly.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::enum(PointStatus::class)],
            'visited_at' => ['sometimes', 'nullable', 'date'],
            'visibility' => ['sometimes', Rule::enum(PointVisibility::class)],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('owner_id', $this->user()->id)->whereNull('deleted_at'),
            ],
            // updated_at of the version the client edited: a newer one on the server answers 409.
            'base_updated_at' => ['sometimes', 'date'],
        ];
    }
}
