<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdatePointRequest extends FormRequest
{
    /**
     * Checked before validation, so another user's point returns 404 whatever the payload.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('point'));
    }

    /**
     * Partial update: only the fields sent are validated and changed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'required', Rule::enum(PointStatus::class)],
            'visited_at' => ['nullable', 'date'],
            'visibility' => ['sometimes', 'required', Rule::enum(PointVisibility::class)],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('owner_id', $this->user()->id)],
        ];
    }
}
