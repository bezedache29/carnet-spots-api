<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An ISO 8601 date-time with a timezone, as the API returns them (`2026-07-14T10:00:00.000000Z`,
 * `2026-07-14T12:00:00+02:00`). Pair it with `date`, which checks the date actually exists.
 *
 * `date` alone also accepts "now" or "+10 years", and `date_format` can't be used: it re-formats the
 * value to compare it, and PHP writes a `Z` suffix back as `+00:00`.
 */
class Iso8601DateTime implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            $fail('The :attribute field must be an ISO 8601 date-time with a timezone, e.g. 2026-07-14T10:00:00Z.');
        }
    }
}
