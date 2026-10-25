<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePointRequest;
use App\Http\Requests\Api\V1\UpdatePointRequest;
use App\Http\Resources\PointResource;
use App\Models\Point;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PointController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $points = $request->user()->points()
            ->with('tags')
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return PointResource::collection($points);
    }

    public function store(StorePointRequest $request): PointResource
    {
        $point = DB::transaction(function () use ($request): Point {
            $point = $request->user()->points()->create($request->safe()->except('tag_ids'));
            $point->tags()->sync($request->validated('tag_ids', []));

            return $point;
        });

        return new PointResource($point->load('tags'));
    }

    public function show(Point $point): PointResource
    {
        Gate::authorize('view', $point);

        return new PointResource($point->load('tags'));
    }

    /**
     * Tags are only replaced when `tag_ids` is sent; sync() keeps a replayed request idempotent.
     */
    public function update(UpdatePointRequest $request, Point $point): PointResource
    {
        DB::transaction(function () use ($request, $point): void {
            $point->update($request->safe()->except('tag_ids'));

            if ($request->has('tag_ids')) {
                $point->tags()->sync($request->validated('tag_ids', []));
            }
        });

        return new PointResource($point->load('tags'));
    }

    public function destroy(Point $point): Response
    {
        Gate::authorize('delete', $point);

        $point->delete();

        return response()->noContent();
    }
}
