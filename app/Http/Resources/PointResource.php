<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'note' => $this->note,
            'status' => $this->status->value,
            'visited_at' => $this->visited_at,
            'visibility' => $this->visibility->value,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'distance_km' => $this->whenHas('distance_km', fn ($distanceKm) => $distanceKm === null ? null : round((float) $distanceKm, 2)),
        ];
    }
}
