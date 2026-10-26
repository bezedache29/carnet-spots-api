<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncRequest;
use App\Http\Resources\PointResource;
use App\Http\Resources\TagResource;
use App\Models\Point;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonInterface;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

class SyncController extends Controller
{
    /**
     * How far back `server_time` is set. `updated_at` is computed in PHP before the write is committed,
     * so a row can become visible a little after the time it carries: without this margin, a sync
     * reading just before that commit would hand out a `server_time` past the row, and it would never
     * be sent. The cost is resending the last few seconds of changes, which the client upserts anyway.
     */
    public const SERVER_TIME_MARGIN_SECONDS = 5;

    #[Endpoint(title: 'Sync points and tags', description: 'Returns every point and tag of the authenticated user created, updated or deleted since `since` (the `server_time` of the previous sync), plus the `server_time` to send next time (a few seconds in the past, as a safety margin). Without `since`, returns everything that is not deleted. Deleted items are listed in `deleted` as `{id, uuid}`. An item can appear twice across two syncs: apply them as upserts.')]
    public function __invoke(SyncRequest $request): JsonResponse
    {
        // Captured before reading, moved back by a safety margin and rounded down to the second like the
        // timestamps in the database: a change still being written is returned by the next sync instead of being lost.
        $serverTime = Date::now()->subSeconds(self::SERVER_TIME_MARGIN_SECONDS)->startOfSecond();
        $since = $request->has('since')
            ? Date::parse($request->validated('since'))->setTimezone(config('app.timezone'))
            : null;

        $points = $this->rowsChangedSince($request->user()->points()->with('tags'), $since);
        $tags = $this->rowsChangedSince($request->user()->tags(), $since);

        return response()->json([
            'server_time' => $serverTime,
            'points' => PointResource::collection($points->reject->trashed()->values()),
            'tags' => TagResource::collection($tags->reject->trashed()->values()),
            'deleted' => [
                'points' => $points->filter->trashed()->map->only(['id', 'uuid'])->values(),
                'tags' => $tags->filter->trashed()->map->only(['id', 'uuid'])->values(),
            ],
        ]);
    }

    /**
     * Rows changed since `$since` (deleted ones included, since a soft delete also bumps `updated_at`),
     * or every live row on the first sync.
     *
     * Uses `>=` because timestamps only have second precision: a row changed in the same second as the
     * previous `server_time` must not be missed, at the cost of sometimes sending it twice.
     *
     * @param  HasMany<Point|Tag, User>  $relation
     * @return Collection<int, Point|Tag>
     */
    private function rowsChangedSince(HasMany $relation, ?CarbonInterface $since): Collection
    {
        return $relation
            ->when($since !== null, fn (Builder $query) => $query->withTrashed()->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->get();
    }
}
