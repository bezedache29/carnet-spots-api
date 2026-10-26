<?php

namespace App\Http\Requests;

use App\Rules\Iso8601DateTime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is enforced by scoping the queries to the authenticated
     * user's own points and tags in the controller, not here.
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
            // The `server_time` returned by the previous sync. Omitted on the first sync.
            'since' => ['sometimes', 'date', new Iso8601DateTime],
        ];
    }
}
