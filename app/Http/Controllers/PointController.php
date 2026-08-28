<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePointRequest;
use App\Http\Requests\UpdatePointRequest;
use App\Http\Resources\PointResource;
use App\Models\Point;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PointController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Point::class, only: ['index']),
            new Middleware('can:create,'.Point::class, only: ['store']),
            new Middleware('can:view,point', only: ['show']),
            new Middleware('can:update,point', only: ['update']),
            new Middleware('can:delete,point', only: ['destroy']),
        ];
    }

    #[Endpoint(title: 'List points', description: 'Returns every point owned by the authenticated user, most recent first, with tags eager-loaded.')]
    public function index(): AnonymousResourceCollection
    {
        return PointResource::collection(
            request()->user()->points()->with('tags')->latest()->get()
        );
    }

    #[Endpoint(title: 'Create a point', description: 'Creates a point for the authenticated user. `tag_ids` is optional and must reference tags owned by the same user.')]
    public function store(StorePointRequest $request): JsonResponse
    {
        $point = $request->user()->points()->create($request->safe()->except('tag_ids'));

        if ($request->has('tag_ids')) {
            $point->tags()->sync($request->validated('tag_ids'));
        }

        return (new PointResource($point->load('tags')))->response()->setStatusCode(201);
    }

    #[Endpoint(title: 'Get a point', description: 'Returns a single point owned by the authenticated user.')]
    public function show(Point $point): PointResource
    {
        return new PointResource($point->load('tags'));
    }

    #[Endpoint(title: 'Update a point', description: "Updates a point owned by the authenticated user. Every field is optional (PATCH-style), e.g. send only `status`/`visited_at`/`note` to mark a point as visited. `tag_ids`, when present, replaces the point's tags and must reference tags owned by the same user.")]
    public function update(UpdatePointRequest $request, Point $point): PointResource
    {
        $point->update($request->safe()->except('tag_ids'));

        if ($request->has('tag_ids')) {
            $point->tags()->sync($request->validated('tag_ids'));
        }

        return new PointResource($point->load('tags'));
    }

    #[Endpoint(title: 'Delete a point', description: 'Deletes a point owned by the authenticated user.')]
    public function destroy(Point $point): Response
    {
        $point->delete();

        return response()->noContent();
    }
}
