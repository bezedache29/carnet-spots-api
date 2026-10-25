<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\NearbyPointsRequest;
use App\Http\Resources\PointResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NearbyPointController extends Controller
{
    /**
     * The current user's points around a position, nearest first, each with its `distance_km`.
     */
    public function __invoke(NearbyPointsRequest $request): AnonymousResourceCollection
    {
        $points = $request->user()->points()
            ->withinRadius(
                (float) $request->validated('latitude'),
                (float) $request->validated('longitude'),
                $request->radiusKm(),
            )
            ->with('tags')
            ->limit($request->limit())
            ->get();

        return PointResource::collection($points);
    }
}
