<?php

namespace App\Models;

use App\Enums\PointStatus;
use App\Enums\PointVisibility;
use Database\Factories\PointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['title', 'latitude', 'longitude', 'note', 'status', 'visited_at', 'visibility'])]
class Point extends Model
{
    /** @use HasFactory<PointFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'visited_at' => 'datetime',
            'status' => PointStatus::class,
            'visibility' => PointVisibility::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
