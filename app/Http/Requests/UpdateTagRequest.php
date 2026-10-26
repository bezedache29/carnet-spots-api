<?php

namespace App\Http\Requests;

use App\Rules\Iso8601DateTime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is enforced by TagPolicy (via HasMiddleware in the
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
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('tags', 'name')
                    ->where('owner_id', $this->user()->id)
                    ->withoutTrashed()
                    ->ignore($this->route('tag')),
            ],
            // updated_at of the version the client edited: a newer one on the server answers 409.
            'base_updated_at' => ['sometimes', 'date', new Iso8601DateTime],
        ];
    }
}
