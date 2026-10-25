<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTagRequest;
use App\Http\Requests\Api\V1\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class TagController extends Controller
{
    /**
     * All of the user's tags, alphabetically — a personal tag list stays small, so no pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TagResource::collection($request->user()->tags()->orderBy('name')->get());
    }

    public function store(StoreTagRequest $request): TagResource
    {
        return new TagResource($request->user()->tags()->create($request->validated()));
    }

    public function show(Tag $tag): TagResource
    {
        Gate::authorize('view', $tag);

        return new TagResource($tag);
    }

    public function update(UpdateTagRequest $request, Tag $tag): TagResource
    {
        $tag->update($request->validated());

        return new TagResource($tag);
    }

    /**
     * Detaches the tag from every point (cascade on the pivot); the points themselves are kept.
     */
    public function destroy(Tag $tag): Response
    {
        Gate::authorize('delete', $tag);

        $tag->delete();

        return response()->noContent();
    }
}
