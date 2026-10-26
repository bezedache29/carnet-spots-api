<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

abstract class Controller
{
    /**
     * Whether the model changed on the server after the version the client edited (`base_updated_at`).
     *
     * Only server timestamps are compared, so a wrong clock on the phone can't make an old offline
     * change overwrite a newer one. Without `base_updated_at` (e.g. the web client), there is no check.
     */
    protected function changedSince(Model $model, ?string $baseUpdatedAt): bool
    {
        return $baseUpdatedAt !== null && $model->updated_at->greaterThan(Date::parse($baseUpdatedAt));
    }
}
