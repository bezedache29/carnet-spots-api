<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NearbyPointsRequest extends FormRequest
{
    public const DEFAULT_RADIUS_KM = 10;

    public const MAX_RADIUS_KM = 200;

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['numeric', 'gt:0', 'max:'.self::MAX_RADIUS_KM],
            'limit' => ['integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function radiusKm(): float
    {
        return (float) $this->validated('radius_km', self::DEFAULT_RADIUS_KM);
    }

    public function limit(): int
    {
        return (int) $this->validated('limit', self::DEFAULT_LIMIT);
    }
}
