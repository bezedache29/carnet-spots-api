<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTagRequest;
use App\Http\Requests\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class TagController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Tag::class, only: ['index']),
            new Middleware('can:create,'.Tag::class, only: ['store']),
            new Middleware('can:view,tag', only: ['show']),
            new Middleware('can:update,tag', only: ['update']),
            new Middleware('can:delete,tag', only: ['destroy']),
        ];
    }

    #[Endpoint(title: 'List tags', description: 'Returns every tag owned by the authenticated user, ordered by name.')]
    public function index(): AnonymousResourceCollection
    {
        return TagResource::collection(
            request()->user()->tags()->orderBy('name')->get()
        );
    }

    #[Endpoint(title: 'Create a tag', description: 'Creates a tag for the authenticated user. Names are unique per owner.')]
    public function store(StoreTagRequest $request): JsonResponse
    {
        $tag = $request->user()->tags()->create($request->validated());

        return (new TagResource($tag))->response()->setStatusCode(201);
    }

    #[Endpoint(title: 'Get a tag', description: 'Returns a single tag owned by the authenticated user.')]
    public function show(Tag $tag): TagResource
    {
        return new TagResource($tag);
    }

    #[Endpoint(title: 'Update a tag', description: 'Renames a tag owned by the authenticated user.')]
    public function update(UpdateTagRequest $request, Tag $tag): TagResource
    {
        $tag->update($request->validated());

        return new TagResource($tag);
    }

    #[Endpoint(title: 'Delete a tag', description: 'Deletes a tag owned by the authenticated user, detaching it from any points.')]
    public function destroy(Tag $tag): Response
    {
        $tag->delete();

        return response()->noContent();
    }
}
